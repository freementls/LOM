/*
 * lomd — persistent LOM OData/API daemon for Power Apps / Power BI.
 *
 * Avoids Power Apps 500/2000 client row limits by pushing filter+paging
 * server-side ($filter/$top/$skip + @odata.nextLink) and exposing List*
 * actions that return only one page of rows.
 */
#include "lom.h"

#include <arpa/inet.h>
#include <ctype.h>
#include <errno.h>
#include <netinet/in.h>
#include <pthread.h>
#include <signal.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <sys/socket.h>
#include <time.h>
#include <unistd.h>

#define MAX_ENTITIES 32
#define MAX_COLS 32
#define MAX_PAGE 5000
#define DEFAULT_PAGE 50

typedef enum { COL_ATTR = 1, COL_CHILD = 2 } col_kind;

typedef struct {
	char name[64];
	col_kind kind;
	char source[64]; /* attr or child tag name */
} col_def;

typedef struct {
	char name[64];           /* OData entity set name, e.g. People */
	char row_selector[128];  /* LOM selector for rows */
	char parent_selector[128]; /* where POST inserts (optional) */
	char id_attr[64];        /* stable key attribute, default lom_id */
	col_def cols[MAX_COLS];
	size_t col_count;
} entity_def;

typedef struct {
	lom_doc *doc;
	char data_path[1024];
	char api_key[256];
	char base_url[512];
	int port;
	entity_def entities[MAX_ENTITIES];
	size_t entity_count;
	pthread_mutex_t lock;
	volatile int running;
} lom_service;

static lom_service g_svc;

static void json_escape(const char *in, char *out, size_t out_sz) {
	size_t o = 0;
	for(size_t i = 0; in && in[i] && o + 2 < out_sz; i++) {
		char c = in[i];
		if(c == '"' || c == '\\') {
			if(o + 3 >= out_sz) break;
			out[o++] = '\\';
			out[o++] = c;
		} else if((unsigned char)c < 0x20) {
			continue;
		} else {
			out[o++] = c;
		}
	}
	out[o] = 0;
}

static int parse_entities_file(const char *path) {
	FILE *f = fopen(path, "r");
	if(!f) return -1;
	char line[1024];
	g_svc.entity_count = 0;
	while(fgets(line, sizeof(line), f)) {
		char *p = line;
		while(*p && isspace((unsigned char)*p)) p++;
		if(*p == 0 || *p == '#') continue;
		char *nl = strchr(p, '\n');
		if(nl) *nl = 0;
		if(g_svc.entity_count >= MAX_ENTITIES) break;
		entity_def *e = &g_svc.entities[g_svc.entity_count];
		memset(e, 0, sizeof(*e));
		snprintf(e->id_attr, sizeof(e->id_attr), "lom_id");
		/* format: Name|row_selector|parent_selector|id_attr|col:src,col:src
		   cols use @age for attr, name for child tag */
		char *tok[5];
		int n = 0;
		for(char *s = p; n < 5; ) {
			tok[n++] = s;
			char *bar = strchr(s, '|');
			if(!bar) break;
			*bar = 0;
			s = bar + 1;
		}
		if(n < 2) continue;
		snprintf(e->name, sizeof(e->name), "%s", tok[0]);
		snprintf(e->row_selector, sizeof(e->row_selector), "%s", tok[1]);
		if(n >= 3 && tok[2][0]) snprintf(e->parent_selector, sizeof(e->parent_selector), "%s", tok[2]);
		if(n >= 4 && tok[3][0]) snprintf(e->id_attr, sizeof(e->id_attr), "%s", tok[3]);
		if(n >= 5 && tok[4][0]) {
			char *c = tok[4];
			while(*c && e->col_count < MAX_COLS) {
				while(*c == ',') c++;
				if(!*c) break;
				char *comma = strchr(c, ',');
				if(comma) *comma = 0;
				col_def *cd = &e->cols[e->col_count++];
				if(c[0] == '@') {
					cd->kind = COL_ATTR;
					snprintf(cd->source, sizeof(cd->source), "%s", c + 1);
					snprintf(cd->name, sizeof(cd->name), "%s", c + 1);
				} else {
					cd->kind = COL_CHILD;
					snprintf(cd->source, sizeof(cd->source), "%s", c);
					snprintf(cd->name, sizeof(cd->name), "%s", c);
				}
				if(!comma) break;
				c = comma + 1;
			}
		}
		g_svc.entity_count++;
	}
	fclose(f);
	return (int)g_svc.entity_count;
}

static entity_def *find_entity(const char *name) {
	for(size_t i = 0; i < g_svc.entity_count; i++) {
		if(strcasecmp(g_svc.entities[i].name, name) == 0) return &g_svc.entities[i];
	}
	return NULL;
}

static int url_decode(char *s) {
	char *o = s;
	for(char *p = s; *p; p++) {
		if(*p == '+') *o++ = ' ';
		else if(*p == '%' && isxdigit((unsigned char)p[1]) && isxdigit((unsigned char)p[2])) {
			char hex[3] = { p[1], p[2], 0 };
			*o++ = (char)strtol(hex, NULL, 16);
			p += 2;
		} else *o++ = *p;
	}
	*o = 0;
	return 0;
}

static void parse_query(char *q, char **keys, char **vals, int *n, int maxn) {
	*n = 0;
	if(!q || !*q) return;
	for(char *p = q; *p && *n < maxn; ) {
		keys[*n] = p;
		char *eq = strchr(p, '=');
		char *amp = strchr(p, '&');
		if(eq && (!amp || eq < amp)) {
			*eq = 0;
			vals[*n] = eq + 1;
			if(amp) { *amp = 0; p = amp + 1; }
			else p += strlen(p);
		} else {
			vals[*n] = "";
			if(amp) { *amp = 0; p = amp + 1; }
			else p += strlen(p);
		}
		url_decode(keys[*n]);
		url_decode(vals[*n]);
		(*n)++;
	}
}

static const char *qget(char **keys, char **vals, int n, const char *k) {
	for(int i = 0; i < n; i++) if(strcmp(keys[i], k) == 0) return vals[i];
	return NULL;
}

typedef struct {
	char field[64];
	char op[16]; /* eq ne gt ge lt le startswith contains */
	char value[256];
} filter_clause;

static int parse_filter(const char *filter, filter_clause *out, int maxn) {
	/* supports: field eq 'x' | field eq 12 | startswith(field,'x') | contains(field,'x')
	   and only a single clause or clause and clause (all AND) */
	if(!filter || !*filter) return 0;
	int count = 0;
	char buf[1024];
	snprintf(buf, sizeof(buf), "%s", filter);
	char *parts[8];
	int pn = 0;
	char *save = NULL;
	for(char *tok = strtok_r(buf, " ", &save); tok && pn < 64; ) {
		/* re-split on " and " already by scanning */
		(void)tok;
		break;
	}
	/* simple scanner */
	const char *p = filter;
	while(*p && count < maxn) {
		while(*p && isspace((unsigned char)*p)) p++;
		if(!*p) break;
		if(strncasecmp(p, "and ", 4) == 0) { p += 4; continue; }
		if(strncasecmp(p, "or ", 3) == 0) { p += 3; continue; } /* treat as and for v1 */
		filter_clause *c = &out[count];
		memset(c, 0, sizeof(*c));
		if(strncasecmp(p, "startswith(", 11) == 0 || strncasecmp(p, "contains(", 9) == 0) {
			int is_start = strncasecmp(p, "startswith(", 11) == 0;
			p += is_start ? 11 : 9;
			snprintf(c->op, sizeof(c->op), "%s", is_start ? "startswith" : "contains");
			size_t i = 0;
			while(*p && *p != ',' && i + 1 < sizeof(c->field)) c->field[i++] = *p++;
			c->field[i] = 0;
			while(*p && (*p == ',' || isspace((unsigned char)*p) || *p == '\'')) p++;
			i = 0;
			while(*p && *p != '\'' && *p != ')' && i + 1 < sizeof(c->value)) c->value[i++] = *p++;
			c->value[i] = 0;
			while(*p && *p != ')') p++;
			if(*p == ')') p++;
			count++;
			continue;
		}
		size_t i = 0;
		while(*p && !isspace((unsigned char)*p) && i + 1 < sizeof(c->field)) c->field[i++] = *p++;
		c->field[i] = 0;
		while(*p && isspace((unsigned char)*p)) p++;
		i = 0;
		while(*p && !isspace((unsigned char)*p) && i + 1 < sizeof(c->op)) c->op[i++] = *p++;
		c->op[i] = 0;
		while(*p && isspace((unsigned char)*p)) p++;
		if(*p == '\'') {
			p++;
			i = 0;
			while(*p && *p != '\'' && i + 1 < sizeof(c->value)) c->value[i++] = *p++;
			c->value[i] = 0;
			if(*p == '\'') p++;
		} else {
			i = 0;
			while(*p && !isspace((unsigned char)*p) && i + 1 < sizeof(c->value)) c->value[i++] = *p++;
			c->value[i] = 0;
		}
		count++;
	}
	return count;
}

static int row_get_field(entity_def *e, int64_t off, const char *field, char *buf, size_t buflen) {
	if(strcmp(field, e->id_attr) == 0 || strcmp(field, "id") == 0) {
		return lom_doc_get_attr(g_svc.doc, off, e->id_attr, buf, buflen) == LOM_OK;
	}
	for(size_t i = 0; i < e->col_count; i++) {
		if(strcasecmp(e->cols[i].name, field) != 0) continue;
		if(e->cols[i].kind == COL_ATTR) {
			return lom_doc_get_attr(g_svc.doc, off, e->cols[i].source, buf, buflen) == LOM_OK;
		}
		return lom_doc_child_text(g_svc.doc, off, e->cols[i].source, buf, buflen) == LOM_OK;
	}
	/* try attr then child by field name */
	if(lom_doc_get_attr(g_svc.doc, off, field, buf, buflen) == LOM_OK) return 1;
	if(lom_doc_child_text(g_svc.doc, off, field, buf, buflen) == LOM_OK) return 1;
	buf[0] = 0;
	return 0;
}

static int clause_match(entity_def *e, int64_t off, filter_clause *c) {
	char buf[512];
	row_get_field(e, off, c->field, buf, sizeof(buf));
	if(strcmp(c->op, "eq") == 0) return strcmp(buf, c->value) == 0;
	if(strcmp(c->op, "ne") == 0) return strcmp(buf, c->value) != 0;
	if(strcmp(c->op, "gt") == 0) return atof(buf) > atof(c->value);
	if(strcmp(c->op, "ge") == 0) return atof(buf) >= atof(c->value);
	if(strcmp(c->op, "lt") == 0) return atof(buf) < atof(c->value);
	if(strcmp(c->op, "le") == 0) return atof(buf) <= atof(c->value);
	if(strcmp(c->op, "startswith") == 0) return strncmp(buf, c->value, strlen(c->value)) == 0;
	if(strcmp(c->op, "contains") == 0) return strstr(buf, c->value) != NULL;
	return 1;
}

static int row_matches_filter(entity_def *e, int64_t off, filter_clause *clauses, int n) {
	for(int i = 0; i < n; i++) if(!clause_match(e, off, &clauses[i])) return 0;
	return 1;
}

static void append_row_json(entity_def *e, int64_t off, char **buf, size_t *len, size_t *cap) {
	char id[128], field[512], esc[1024];
	row_get_field(e, off, e->id_attr, id, sizeof(id));
	char piece[2048];
	int n = snprintf(piece, sizeof(piece), "{\"%s\":\"", e->id_attr);
	json_escape(id, esc, sizeof(esc));
	n += snprintf(piece + n, sizeof(piece) - (size_t)n, "%s\"", esc);
	for(size_t i = 0; i < e->col_count; i++) {
		row_get_field(e, off, e->cols[i].name, field, sizeof(field));
		json_escape(field, esc, sizeof(esc));
		n += snprintf(piece + n, sizeof(piece) - (size_t)n, ",\"%s\":\"%s\"", e->cols[i].name, esc);
	}
	n += snprintf(piece + n, sizeof(piece) - (size_t)n, "}");
	if(*len + (size_t)n + 2 > *cap) {
		size_t ncap = *cap ? *cap * 2 : 4096;
		while(ncap < *len + (size_t)n + 2) ncap *= 2;
		char *nb = realloc(*buf, ncap);
		if(!nb) return;
		*buf = nb;
		*cap = ncap;
	}
	memcpy(*buf + *len, piece, (size_t)n);
	*len += (size_t)n;
	(*buf)[*len] = 0;
}

static char *build_list_response(entity_def *e, const char *filter, int top, int skip, int want_count, const char *path_base) {
	filter_clause clauses[8];
	int nc = parse_filter(filter, clauses, 8);
	lom_match_list m;
	lom_match_list_init(&m);
	if(lom_doc_get(g_svc.doc, e->row_selector, &m) != LOM_OK) {
		lom_match_list_free(&m);
		return strdup("{\"error\":\"query failed\"}");
	}
	int64_t *matched = malloc(sizeof(int64_t) * (m.count ? m.count : 1));
	size_t matched_n = 0;
	for(size_t i = 0; i < m.count; i++) {
		if(row_matches_filter(e, m.items[i].offset, clauses, nc)) {
			matched[matched_n++] = m.items[i].offset;
		}
	}
	size_t total = matched_n;
	if(skip < 0) skip = 0;
	if(top < 1) top = DEFAULT_PAGE;
	if(top > MAX_PAGE) top = MAX_PAGE;
	size_t from = (size_t)skip;
	if(from > matched_n) from = matched_n;
	size_t to = from + (size_t)top;
	if(to > matched_n) to = matched_n;

	char *body = NULL; size_t len = 0, cap = 0;
	const char *prefix = "{\"@odata.context\":\"$metadata#";
	/* build manually */
	cap = 4096;
	body = malloc(cap);
	len = 0;
	len += (size_t)snprintf(body + len, cap - len, "{");
	if(want_count) {
		len += (size_t)snprintf(body + len, cap - len, "\"@odata.count\":%zu,", total);
	}
	len += (size_t)snprintf(body + len, cap - len, "\"value\":[");
	for(size_t i = from; i < to; i++) {
		if(i > from) {
			if(len + 2 > cap) { cap *= 2; body = realloc(body, cap); }
			body[len++] = ',';
		}
		append_row_json(e, matched[i], &body, &len, &cap);
	}
	if(len + 2 > cap) { cap *= 2; body = realloc(body, cap); }
	body[len++] = ']';
	if(to < total) {
		char next[1024];
		snprintf(next, sizeof(next),
			"%s%s%s?$skip=%zu&$top=%d%s%s",
			g_svc.base_url[0] ? g_svc.base_url : path_base,
			(strstr(path_base, "/api/List") || (g_svc.base_url[0] && strstr(g_svc.base_url, "/api/"))) ? "" : "/",
			e->name,
			to,
			top,
			filter && *filter ? "&$filter=" : "",
			filter && *filter ? filter : "");
		/* Prefer /api/List{Entity} style when path_base contains /api/List */
		if(strstr(path_base, "/api/List")) {
			snprintf(next, sizeof(next),
				"http://127.0.0.1:%d/api/List%s?$skip=%zu&$top=%d%s%s",
				g_svc.port, e->name, to, top,
				filter && *filter ? "&$filter=" : "",
				filter && *filter ? filter : "");
		} else if(g_svc.base_url[0]) {
			snprintf(next, sizeof(next),
				"%s/%s?$skip=%zu&$top=%d%s%s",
				g_svc.base_url, e->name, to, top,
				filter && *filter ? "&$filter=" : "",
				filter && *filter ? filter : "");
		} else {
			snprintf(next, sizeof(next),
				"%s/%s?$skip=%zu&$top=%d%s%s",
				path_base, e->name, to, top,
				filter && *filter ? "&$filter=" : "",
				filter && *filter ? filter : "");
		}
		char esc[1200];
		json_escape(next, esc, sizeof(esc));
		if(len + strlen(esc) + 40 > cap) { cap = len + strlen(esc) + 64; body = realloc(body, cap); }
		len += (size_t)snprintf(body + len, cap - len, ",\"@odata.nextLink\":\"%s\"", esc);
	}
	if(len + 2 > cap) { cap *= 2; body = realloc(body, cap); }
	body[len++] = '}';
	body[len] = 0;
	free(matched);
	lom_match_list_free(&m);
	(void)prefix;
	return body;
}

static int find_row_by_id(entity_def *e, const char *id, int64_t *out_off) {
	lom_match_list m;
	lom_match_list_init(&m);
	if(lom_doc_get(g_svc.doc, e->row_selector, &m) != LOM_OK) {
		lom_match_list_free(&m);
		return 0;
	}
	char buf[128];
	for(size_t i = 0; i < m.count; i++) {
		if(lom_doc_get_attr(g_svc.doc, m.items[i].offset, e->id_attr, buf, sizeof(buf)) == LOM_OK && strcmp(buf, id) == 0) {
			*out_off = m.items[i].offset;
			lom_match_list_free(&m);
			return 1;
		}
	}
	lom_match_list_free(&m);
	return 0;
}

static void http_send_len(int fd, int code, const char *ctype, const char *body, size_t blen) {
	char hdr[512];
	const char *msg = code == 200 ? "OK" : code == 201 ? "Created" : code == 204 ? "No Content" :
		code == 400 ? "Bad Request" : code == 401 ? "Unauthorized" : code == 404 ? "Not Found" : "Error";
	int n = snprintf(hdr, sizeof(hdr),
		"HTTP/1.1 %d %s\r\nContent-Type: %s\r\nContent-Length: %zu\r\n"
		"Access-Control-Allow-Origin: *\r\nAccess-Control-Allow-Headers: Authorization, Content-Type, X-Api-Key\r\n"
		"Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS\r\nConnection: close\r\n\r\n",
		code, msg, ctype, blen);
	send(fd, hdr, (size_t)n, 0);
	if(blen && body) send(fd, body, blen, 0);
}

static void http_send(int fd, int code, const char *ctype, const char *body) {
	http_send_len(fd, code, ctype, body, body ? strlen(body) : 0);
}

static int check_auth(const char *req) {
	if(!g_svc.api_key[0]) return 1;
	const char *h = strcasestr(req, "\nX-Api-Key:");
	if(!h) h = strcasestr(req, "\nAuthorization:");
	if(!h) return 0;
	h = strchr(h, ':');
	if(!h) return 0;
	h++;
	while(*h == ' ') h++;
	char got[256];
	size_t i = 0;
	while(h[i] && h[i] != '\r' && h[i] != '\n' && i + 1 < sizeof(got)) {
		got[i] = h[i];
		i++;
	}
	got[i] = 0;
	if(strncasecmp(got, "ApiKey ", 7) == 0) memmove(got, got + 7, strlen(got + 7) + 1);
	if(strncasecmp(got, "Bearer ", 7) == 0) memmove(got, got + 7, strlen(got + 7) + 1);
	return strcmp(got, g_svc.api_key) == 0;
}

static char *json_get_string(const char *json, const char *key, char *out, size_t out_sz) {
	out[0] = 0;
	char pat[96];
	snprintf(pat, sizeof(pat), "\"%s\"", key);
	const char *p = strstr(json, pat);
	if(!p) return NULL;
	p = strchr(p + strlen(pat), ':');
	if(!p) return NULL;
	p++;
	while(*p && isspace((unsigned char)*p)) p++;
	if(*p != '"') return NULL;
	p++;
	size_t i = 0;
	while(*p && *p != '"' && i + 1 < out_sz) {
		if(*p == '\\' && p[1]) p++;
		out[i++] = *p++;
	}
	out[i] = 0;
	return out;
}

static void handle_request(int fd, char *req, size_t req_len) {
	(void)req_len;
	if(strncmp(req, "OPTIONS ", 8) == 0) {
		http_send(fd, 204, "text/plain", "");
		return;
	}
	char method[16] = {0}, path[1024] = {0};
	sscanf(req, "%15s %1023s", method, path);
	char *qmark = strchr(path, '?');
	char *query = NULL;
	if(qmark) { *qmark = 0; query = qmark + 1; }

	if(strcmp(path, "/health") == 0) {
		http_send(fd, 200, "application/json", "{\"ok\":true,\"engine\":\"liblom\"}");
		return;
	}
	if(!check_auth(req) && strcmp(path, "/openapi.json") != 0) {
		http_send(fd, 401, "application/json", "{\"error\":\"unauthorized\"}");
		return;
	}

	char *keys[32]; char *vals[32]; int qn = 0;
	parse_query(query, keys, vals, &qn, 32);

	/* OpenAPI served for custom connector import */
	if(strcmp(path, "/openapi.json") == 0) {
		FILE *f = fopen("powerapps/openapi.json", "rb");
		if(!f) f = fopen("/srv/http/LOM/powerapps/openapi.json", "rb");
		if(!f) { http_send(fd, 404, "application/json", "{\"error\":\"openapi missing\"}"); return; }
		fseek(f, 0, SEEK_END); long sz = ftell(f); rewind(f);
		char *b = malloc((size_t)sz + 1);
		fread(b, 1, (size_t)sz, f); b[sz] = 0; fclose(f);
		http_send(fd, 200, "application/json", b);
		free(b);
		return;
	}

	pthread_mutex_lock(&g_svc.lock);

	if(strcmp(path, "/odata") == 0 || strcmp(path, "/odata/") == 0) {
		char body[4096];
		size_t n = (size_t)snprintf(body, sizeof(body), "{\"@odata.context\":\"$metadata\",\"value\":[");
		for(size_t i = 0; i < g_svc.entity_count; i++) {
			n += (size_t)snprintf(body + n, sizeof(body) - n, "%s{\"name\":\"%s\",\"kind\":\"EntitySet\",\"url\":\"%s\"}",
				i ? "," : "", g_svc.entities[i].name, g_svc.entities[i].name);
		}
		snprintf(body + n, sizeof(body) - n, "]}");
		pthread_mutex_unlock(&g_svc.lock);
		http_send(fd, 200, "application/json", body);
		return;
	}

	if(strcmp(path, "/odata/$metadata") == 0) {
		char body[8192];
		size_t n = (size_t)snprintf(body, sizeof(body),
			"<?xml version=\"1.0\"?><edmx:Edmx Version=\"4.0\" xmlns:edmx=\"http://docs.oasis-open.org/odata/ns/edmx\">"
			"<edmx:DataServices><Schema Namespace=\"LOM\" xmlns=\"http://docs.oasis-open.org/odata/ns/edm\">");
		for(size_t i = 0; i < g_svc.entity_count; i++) {
			entity_def *e = &g_svc.entities[i];
			n += (size_t)snprintf(body + n, sizeof(body) - n, "<EntityType Name=\"%s\"><Key><PropertyRef Name=\"%s\"/></Key>", e->name, e->id_attr);
			n += (size_t)snprintf(body + n, sizeof(body) - n, "<Property Name=\"%s\" Type=\"Edm.String\"/>", e->id_attr);
			for(size_t c = 0; c < e->col_count; c++) {
				n += (size_t)snprintf(body + n, sizeof(body) - n, "<Property Name=\"%s\" Type=\"Edm.String\"/>", e->cols[c].name);
			}
			n += (size_t)snprintf(body + n, sizeof(body) - n, "</EntityType>");
		}
		for(size_t i = 0; i < g_svc.entity_count; i++) {
			n += (size_t)snprintf(body + n, sizeof(body) - n, "<EntityContainer Name=\"Container\"><EntitySet Name=\"%s\" EntityType=\"LOM.%s\"/></EntityContainer>",
				g_svc.entities[i].name, g_svc.entities[i].name);
		}
		snprintf(body + n, sizeof(body) - n, "</Schema></edmx:DataServices></edmx:Edmx>");
		pthread_mutex_unlock(&g_svc.lock);
		http_send(fd, 200, "application/xml", body);
		return;
	}

	/* /odata/Entity or /api/ListEntity or /odata/Entity('id') */
	const char *ent_name = NULL;
	int is_list_action = 0;
	char id_buf[128] = {0};
	if(strncmp(path, "/odata/", 7) == 0) {
		ent_name = path + 7;
	} else if(strncmp(path, "/api/List", 9) == 0) {
		ent_name = path + 9;
		is_list_action = 1;
	} else if(strncmp(path, "/api/Get", 8) == 0) {
		ent_name = path + 8;
	} else if(strncmp(path, "/api/Create", 11) == 0) {
		ent_name = path + 11;
	} else if(strncmp(path, "/api/Update", 11) == 0) {
		ent_name = path + 11;
	} else if(strncmp(path, "/api/Delete", 11) == 0) {
		ent_name = path + 11;
	}

	char ent_only[64] = {0};
	if(ent_name) {
		snprintf(ent_only, sizeof(ent_only), "%s", ent_name);
		char *paren = strchr(ent_only, '(');
		if(paren) {
			*paren = 0;
			char *q = paren + 1;
			if(*q == '\'' || *q == '"') q++;
			size_t i = 0;
			while(*q && *q != '\'' && *q != '"' && *q != ')' && i + 1 < sizeof(id_buf)) id_buf[i++] = *q++;
			id_buf[i] = 0;
		}
	}

	entity_def *e = ent_only[0] ? find_entity(ent_only) : NULL;
	if(!e) {
		pthread_mutex_unlock(&g_svc.lock);
		http_send(fd, 404, "application/json", "{\"error\":\"entity not found\"}");
		return;
	}

	const char *filter = qget(keys, vals, qn, "$filter");
	if(!filter) filter = qget(keys, vals, qn, "filter");
	const char *top_s = qget(keys, vals, qn, "$top");
	if(!top_s) top_s = qget(keys, vals, qn, "top");
	const char *skip_s = qget(keys, vals, qn, "$skip");
	if(!skip_s) skip_s = qget(keys, vals, qn, "skip");
	const char *count_s = qget(keys, vals, qn, "$count");
	int top = top_s ? atoi(top_s) : DEFAULT_PAGE;
	int skip = skip_s ? atoi(skip_s) : 0;
	int want_count = count_s && (strcmp(count_s, "true") == 0 || strcmp(count_s, "1") == 0);
	if(is_list_action) want_count = 1;

	char *body_pos = strstr(req, "\r\n\r\n");
	const char *body_in = body_pos ? body_pos + 4 : "";

	if(strcmp(method, "GET") == 0) {
		if(id_buf[0] || (!is_list_action && qget(keys, vals, qn, "id"))) {
			if(!id_buf[0]) snprintf(id_buf, sizeof(id_buf), "%s", qget(keys, vals, qn, "id"));
			int64_t off;
			if(!find_row_by_id(e, id_buf, &off)) {
				pthread_mutex_unlock(&g_svc.lock);
				http_send(fd, 404, "application/json", "{\"error\":\"not found\"}");
				return;
			}
			char *buf = NULL; size_t len = 0, cap = 0;
			append_row_json(e, off, &buf, &len, &cap);
			pthread_mutex_unlock(&g_svc.lock);
			http_send_len(fd, 200, "application/json", buf ? buf : "{}", buf ? len : 2);
			free(buf);
			return;
		}
		char path_base[256];
		snprintf(path_base, sizeof(path_base), "http://127.0.0.1:%d/odata", g_svc.port);
		char *resp = build_list_response(e, filter ? filter : "", top, skip, want_count,
			is_list_action ? "http://127.0.0.1/api/List" : path_base);
		/* fix nextLink for List actions */
		if(is_list_action && resp) {
			/* rebuild next with /api/ListName — already uses path from base_url if set */
		}
		pthread_mutex_unlock(&g_svc.lock);
		http_send(fd, 200, "application/json", resp ? resp : "{}");
		free(resp);
		return;
	}

	if(strcmp(method, "POST") == 0) {
		/* create row under parent_selector */
		char frag[2048];
		size_t n = (size_t)snprintf(frag, sizeof(frag), "<%s", e->row_selector);
		/* row_selector may be chain — use last tag */
		const char *tag = strrchr(e->row_selector, '_');
		tag = tag ? tag + 1 : e->row_selector;
		char tagname[64];
		snprintf(tagname, sizeof(tagname), "%s", tag);
		char *br = strchr(tagname, '[');
		if(br) *br = 0;
		char idv[64];
		snprintf(idv, sizeof(idv), "lom-%ld", (long)time(NULL));
		n = (size_t)snprintf(frag, sizeof(frag), "<%s %s=\"%s\"", tagname, e->id_attr, idv);
		for(size_t i = 0; i < e->col_count; i++) {
			if(e->cols[i].kind != COL_ATTR) continue;
			char val[256];
			if(!json_get_string(body_in, e->cols[i].name, val, sizeof(val))) continue;
			n += (size_t)snprintf(frag + n, sizeof(frag) - n, " %s=\"%s\"", e->cols[i].source, val);
		}
		n += (size_t)snprintf(frag + n, sizeof(frag) - n, ">");
		for(size_t i = 0; i < e->col_count; i++) {
			if(e->cols[i].kind != COL_CHILD) continue;
			char val[256];
			if(!json_get_string(body_in, e->cols[i].name, val, sizeof(val))) continue;
			n += (size_t)snprintf(frag + n, sizeof(frag) - n, "<%s>%s</%s>", e->cols[i].source, val, e->cols[i].source);
		}
		snprintf(frag + n, sizeof(frag) - n, "</%s>", tagname);
		const char *parent = e->parent_selector[0] ? e->parent_selector : NULL;
		lom_status st = LOM_ERR_ARG;
		if(parent) st = lom_doc_new_before_close(g_svc.doc, parent, frag);
		else st = LOM_ERR_ARG;
		if(st == LOM_OK) {
			lom_doc_save_file(g_svc.doc, g_svc.data_path);
		}
		pthread_mutex_unlock(&g_svc.lock);
		if(st != LOM_OK) {
			char err[128];
			snprintf(err, sizeof(err), "{\"error\":\"create failed\",\"status\":%d,\"parent\":\"%s\"}", (int)st, parent ? parent : "");
			http_send(fd, 400, "application/json", err);
			return;
		}
		char out[256];
		snprintf(out, sizeof(out), "{\"%s\":\"%s\"}", e->id_attr, idv);
		http_send(fd, 201, "application/json", out);
		return;
	}

	if(strcmp(method, "PATCH") == 0 || strcmp(method, "PUT") == 0) {
		if(!id_buf[0]) {
			const char *idq = qget(keys, vals, qn, "id");
			if(idq) snprintf(id_buf, sizeof(id_buf), "%s", idq);
		}
		if(!id_buf[0]) json_get_string(body_in, e->id_attr, id_buf, sizeof(id_buf));
		int64_t off;
		if(!id_buf[0] || !find_row_by_id(e, id_buf, &off)) {
			pthread_mutex_unlock(&g_svc.lock);
			http_send(fd, 404, "application/json", "{\"error\":\"not found\"}");
			return;
		}
		for(size_t i = 0; i < e->col_count; i++) {
			char val[256];
			if(!json_get_string(body_in, e->cols[i].name, val, sizeof(val))) continue;
			if(e->cols[i].kind == COL_ATTR) lom_doc_set_attr(g_svc.doc, off, e->cols[i].source, val);
			else lom_doc_set_child_text_offset(g_svc.doc, off, e->cols[i].source, val);
			/* refresh offset after mutations */
			if(!find_row_by_id(e, id_buf, &off)) break;
		}
		lom_doc_save_file(g_svc.doc, g_svc.data_path);
		pthread_mutex_unlock(&g_svc.lock);
		http_send(fd, 200, "application/json", "{\"ok\":true}");
		return;
	}

	if(strcmp(method, "DELETE") == 0) {
		if(!id_buf[0]) {
			const char *idq = qget(keys, vals, qn, "id");
			if(idq) snprintf(id_buf, sizeof(id_buf), "%s", idq);
		}
		int64_t off;
		if(!id_buf[0] || !find_row_by_id(e, id_buf, &off)) {
			pthread_mutex_unlock(&g_svc.lock);
			http_send(fd, 404, "application/json", "{\"error\":\"not found\"}");
			return;
		}
		lom_doc_delete_offset(g_svc.doc, off);
		lom_doc_save_file(g_svc.doc, g_svc.data_path);
		pthread_mutex_unlock(&g_svc.lock);
		http_send(fd, 204, "text/plain", "");
		return;
	}

	pthread_mutex_unlock(&g_svc.lock);
	http_send(fd, 400, "application/json", "{\"error\":\"unsupported\"}");
}

static void *client_thread(void *arg) {
	int fd = (int)(intptr_t)arg;
	char *req = malloc(1024 * 1024);
	if(!req) { close(fd); return NULL; }
	size_t n = 0;
	while(n + 1 < 1024 * 1024) {
		ssize_t r = recv(fd, req + n, 1024 * 1024 - 1 - n, 0);
		if(r <= 0) break;
		n += (size_t)r;
		req[n] = 0;
		if(strstr(req, "\r\n\r\n")) {
			/* if Content-Length, wait for body */
			const char *cl = strcasestr(req, "\nContent-Length:");
			if(cl) {
				size_t need = (size_t)atoi(cl + 15);
				char *body = strstr(req, "\r\n\r\n");
				size_t have = body ? n - (size_t)(body + 4 - req) : 0;
				if(have >= need) break;
			} else break;
		}
	}
	req[n] = 0;
	handle_request(fd, req, n);
	free(req);
	close(fd);
	return NULL;
}

static void usage(const char *a) {
	fprintf(stderr,
		"Usage: %s --file data.xml --entities entities.conf --port 8080 [--api-key SECRET] [--base-url URL]\n", a);
}

int main(int argc, char **argv) {
	const char *file = NULL, *ents = NULL;
	memset(&g_svc, 0, sizeof(g_svc));
	g_svc.port = 8080;
	pthread_mutex_init(&g_svc.lock, NULL);
	for(int i = 1; i < argc; i++) {
		if(!strcmp(argv[i], "--file") && i + 1 < argc) file = argv[++i];
		else if(!strcmp(argv[i], "--entities") && i + 1 < argc) ents = argv[++i];
		else if(!strcmp(argv[i], "--port") && i + 1 < argc) g_svc.port = atoi(argv[++i]);
		else if(!strcmp(argv[i], "--api-key") && i + 1 < argc) snprintf(g_svc.api_key, sizeof(g_svc.api_key), "%s", argv[++i]);
		else if(!strcmp(argv[i], "--base-url") && i + 1 < argc) snprintf(g_svc.base_url, sizeof(g_svc.base_url), "%s", argv[++i]);
		else { usage(argv[0]); return 2; }
	}
	if(!file || !ents) { usage(argv[0]); return 2; }
	snprintf(g_svc.data_path, sizeof(g_svc.data_path), "%s", file);
	if(parse_entities_file(ents) < 1) {
		fprintf(stderr, "No entities loaded from %s\n", ents);
		return 1;
	}
	g_svc.doc = lom_doc_create_file(file);
	if(!g_svc.doc || lom_doc_status(g_svc.doc) != LOM_OK) {
		fprintf(stderr, "Failed to load %s\n", file);
		return 1;
	}
	for(size_t i = 0; i < g_svc.entity_count; i++) {
		lom_doc_ensure_ids(g_svc.doc, g_svc.entities[i].row_selector, g_svc.entities[i].id_attr);
	}
	lom_doc_save_file(g_svc.doc, g_svc.data_path);

	int srv = socket(AF_INET, SOCK_STREAM, 0);
	int yes = 1;
	setsockopt(srv, SOL_SOCKET, SO_REUSEADDR, &yes, sizeof(yes));
	struct sockaddr_in addr;
	memset(&addr, 0, sizeof(addr));
	addr.sin_family = AF_INET;
	addr.sin_addr.s_addr = htonl(INADDR_ANY);
	addr.sin_port = htons((uint16_t)g_svc.port);
	if(bind(srv, (struct sockaddr *)&addr, sizeof(addr)) < 0 || listen(srv, 64) < 0) {
		perror("bind/listen");
		return 1;
	}
	fprintf(stderr, "lomd %s listening on :%d  entities=%zu  auth=%s\n",
		lom_version(), g_svc.port, g_svc.entity_count, g_svc.api_key[0] ? "api-key" : "off");
	g_svc.running = 1;
	signal(SIGPIPE, SIG_IGN);
	while(g_svc.running) {
		int cfd = accept(srv, NULL, NULL);
		if(cfd < 0) continue;
		pthread_t th;
		pthread_create(&th, NULL, client_thread, (void *)(intptr_t)cfd);
		pthread_detach(th);
	}
	close(srv);
	lom_doc_free(g_svc.doc);
	return 0;
}
