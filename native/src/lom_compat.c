/* SPDX-License-Identifier: Apache-2.0
 * XPath 1.0 / CSS subset → LOM selector. Unsupported constructs return LOM_ERR_PARSE.
 */
#include "lom.h"

#include <ctype.h>
#include <stdio.h>
#include <string.h>

static int append_str(char *out, size_t cap, size_t *n, const char *s) {
	size_t sl = strlen(s);
	if(*n + sl >= cap) return 0;
	memcpy(out + *n, s, sl);
	*n += sl;
	out[*n] = 0;
	return 1;
}

static int append_ident(char *out, size_t cap, size_t *n, const char *s, size_t sl) {
	if(!sl || sl >= 128) return 0;
	if(*n + sl >= cap) return 0;
	memcpy(out + *n, s, sl);
	*n += sl;
	out[*n] = 0;
	return 1;
}

static int call_inner(const char *p, const char *name, char *inner, size_t inner_cap) {
	size_t nl = strlen(name);
	if(strncmp(p, name, nl) != 0 || p[nl] != '(') return 0;
	const char *s = p + nl + 1;
	int depth = 1;
	size_t n = 0;
	while(*s && depth) {
		if(*s == '(') depth++;
		else if(*s == ')') {
			depth--;
			if(!depth) break;
		}
		if(n + 1 >= inner_cap) return 0;
		inner[n++] = *s++;
	}
	if(depth || *s != ')') return 0;
	s++;
	while(*s && isspace((unsigned char)*s)) s++;
	if(*s) return 0; /* nested or trailing — not this pass */
	inner[n] = 0;
	return 1;
}

static lom_status xpath_body(const char *xpath, char *out, size_t out_cap) {
	out[0] = 0;
	size_t n = 0;
	const char *p = xpath;
	while(*p && isspace((unsigned char)*p)) p++;
	if(!*p) return LOM_ERR_PARSE;

	int first = 1;
	int pending = 0; /* 0 child, 1 desc, 2 parent, 3 ancestor */
	if(p[0] == '/' && p[1] == '/') { pending = 1; p += 2; }
	else if(p[0] == '/') p++;

	while(*p) {
		while(*p && isspace((unsigned char)*p)) p++;
		if(!*p) break;

		if(p[0] == '/' && p[1] == '/') { pending = 1; p += 2; continue; }
		if(p[0] == '/') { pending = 0; p++; continue; }
		if(p[0] == '|') {
			if(!append_str(out, out_cap, &n, "|")) return LOM_ERR_PARSE;
			first = 1;
			pending = 0;
			p++;
			if(p[0] == '/' && p[1] == '/') { pending = 1; p += 2; }
			else if(p[0] == '/') p++;
			continue;
		}
		if(strncmp(p, "parent::", 8) == 0) { pending = 2; p += 8; continue; }
		if(strncmp(p, "ancestor::", 10) == 0) { pending = 3; p += 10; continue; }
		if(p[0] == '.' && p[1] == '.' && (p[2] == 0 || p[2] == '/' || p[2] == '|' || isspace((unsigned char)p[2]))) {
			pending = 2;
			p += 2;
			if(*p == 0 || *p == '|' || *p == '/') {
				if(!append_str(out, out_cap, &n, "'")) return LOM_ERR_PARSE;
				first = 0;
				pending = 0;
			}
			continue;
		}

		if(!first) {
			const char *sep = "_";
			if(pending == 1) sep = "__";
			else if(pending == 2) sep = "'";
			else if(pending == 3) sep = "\"";
			if(!append_str(out, out_cap, &n, sep)) return LOM_ERR_PARSE;
		} else if(pending == 2) {
			if(!append_str(out, out_cap, &n, "'")) return LOM_ERR_PARSE;
		} else if(pending == 3) {
			if(!append_str(out, out_cap, &n, "\"")) return LOM_ERR_PARSE;
		}
		pending = 0;
		first = 0;

		if(*p == '*') {
			if(!append_str(out, out_cap, &n, "*")) return LOM_ERR_PARSE;
			p++;
		} else if(isalpha((unsigned char)*p) || *p == '_' || *p == ':') {
			const char *id = p;
			while(*p && (isalnum((unsigned char)*p) || *p == '_' || *p == '-' || *p == ':')) p++;
			if(!append_ident(out, out_cap, &n, id, (size_t)(p - id))) return LOM_ERR_PARSE;
		} else {
			return LOM_ERR_PARSE;
		}

		while(*p == '[') {
			p++;
			while(*p && isspace((unsigned char)*p)) p++;
			if(strncmp(p, "last()", 6) == 0) {
				p += 6;
				if(!append_str(out, out_cap, &n, "[$]")) return LOM_ERR_PARSE;
			} else if(isdigit((unsigned char)*p)) {
				int idx = 0;
				while(isdigit((unsigned char)*p)) idx = idx * 10 + (*p++ - '0');
				if(idx < 1) return LOM_ERR_PARSE;
				char buf[32];
				snprintf(buf, sizeof(buf), "[%d]", idx - 1);
				if(!append_str(out, out_cap, &n, buf)) return LOM_ERR_PARSE;
			} else if(*p == '@') {
				p++;
				const char *an = p;
				while(*p && (isalnum((unsigned char)*p) || *p == '_' || *p == '-' || *p == ':')) p++;
				if(!append_str(out, out_cap, &n, "@")) return LOM_ERR_PARSE;
				if(!append_ident(out, out_cap, &n, an, (size_t)(p - an))) return LOM_ERR_PARSE;
				while(*p && isspace((unsigned char)*p)) p++;
				if(*p == '=' || (*p == '!' && p[1] == '=')) {
					int ne = (*p == '!');
					p += ne ? 2 : 1;
					while(*p && isspace((unsigned char)*p)) p++;
					char q = *p;
					if(q != '\'' && q != '"') return LOM_ERR_PARSE;
					p++;
					const char *vs = p;
					while(*p && *p != q) p++;
					if(*p != q) return LOM_ERR_PARSE;
					size_t vl = (size_t)(p - vs);
					p++;
					if(!append_str(out, out_cap, &n, ne ? "!=" : "=")) return LOM_ERR_PARSE;
					if(n + vl >= out_cap) return LOM_ERR_PARSE;
					memcpy(out + n, vs, vl);
					n += vl;
					out[n] = 0;
				}
			} else if(strncmp(p, "text()", 6) == 0) {
				p += 6;
				while(*p && isspace((unsigned char)*p)) p++;
				if(*p != '=' && !(*p == '!' && p[1] == '=')) return LOM_ERR_PARSE;
				int ne = (*p == '!');
				p += ne ? 2 : 1;
				while(*p && isspace((unsigned char)*p)) p++;
				char q = *p;
				if(q != '\'' && q != '"') return LOM_ERR_PARSE;
				p++;
				const char *vs = p;
				while(*p && *p != q) p++;
				if(*p != q) return LOM_ERR_PARSE;
				size_t vl = (size_t)(p - vs);
				p++;
				if(!append_str(out, out_cap, &n, ne ? "!=" : "=")) return LOM_ERR_PARSE;
				if(n + vl >= out_cap) return LOM_ERR_PARSE;
				memcpy(out + n, vs, vl);
				n += vl;
				out[n] = 0;
			} else {
				return LOM_ERR_PARSE;
			}
			while(*p && isspace((unsigned char)*p)) p++;
			if(*p != ']') return LOM_ERR_PARSE;
			p++;
		}
	}
	return n ? LOM_OK : LOM_ERR_PARSE;
}

lom_status lom_xpath_to_lom(const char *xpath, char *out, size_t out_cap) {
	if(!xpath || !out || out_cap < 2) return LOM_ERR_ARG;
	const char *p = xpath;
	while(*p && isspace((unsigned char)*p)) p++;
	char inner[1024];
	const char *prefix = NULL;
	if(call_inner(p, "count", inner, sizeof(inner))) prefix = "#";
	else if(call_inner(p, "sum", inner, sizeof(inner))) prefix = "sum:";
	else if(call_inner(p, "avg", inner, sizeof(inner))) prefix = "avg:";
	else if(call_inner(p, "average", inner, sizeof(inner))) prefix = "avg:";
	if(prefix) {
		char body[1024];
		lom_status st = xpath_body(inner, body, sizeof(body));
		if(st != LOM_OK) return st;
		if(strlen(prefix) + strlen(body) + 1 > out_cap) return LOM_ERR_PARSE;
		snprintf(out, out_cap, "%s%s", prefix, body);
		return out[0] ? LOM_OK : LOM_ERR_PARSE;
	}
	return xpath_body(p, out, out_cap);
}

lom_status lom_css_to_lom(const char *css, char *out, size_t out_cap) {
	if(!css || !out || out_cap < 2) return LOM_ERR_ARG;
	out[0] = 0;
	size_t n = 0;
	const char *p = css;
	int first = 1;
	while(*p) {
		while(*p && isspace((unsigned char)*p)) p++;
		if(!*p) break;
		if(*p == '>') {
			/* child combinator — same as space for LOM _ */
			p++;
			continue;
		}
		if(!first) {
			if(!append_str(out, out_cap, &n, "_")) return LOM_ERR_PARSE;
		}
		first = 0;
		if(*p == '*') {
			if(!append_str(out, out_cap, &n, "*")) return LOM_ERR_PARSE;
			p++;
		} else if(isalpha((unsigned char)*p) || *p == '_' || *p == '-') {
			const char *id = p;
			/* CSS namespaces use '|'; ':' starts a pseudo-class. */
			while(*p && (isalnum((unsigned char)*p) || *p == '_' || *p == '-')) p++;
			size_t il = (size_t)(p - id);
			if(!append_ident(out, out_cap, &n, id, il)) return LOM_ERR_PARSE;
		} else {
			return LOM_ERR_PARSE;
		}
		while(*p == '[') {
			p++;
			const char *an = p;
			while(*p && *p != '=' && *p != ']' && !isspace((unsigned char)*p)) p++;
			size_t al = (size_t)(p - an);
			if(!al) return LOM_ERR_PARSE;
			if(!append_str(out, out_cap, &n, "@")) return LOM_ERR_PARSE;
			if(!append_ident(out, out_cap, &n, an, al)) return LOM_ERR_PARSE;
			while(*p && isspace((unsigned char)*p)) p++;
			if(*p == '=') {
				p++;
				while(*p && isspace((unsigned char)*p)) p++;
				char q = (*p == '\'' || *p == '"') ? *p++ : 0;
				const char *vs = p;
				if(q) {
					while(*p && *p != q) p++;
				} else {
					while(*p && *p != ']') p++;
				}
				size_t vl = (size_t)(p - vs);
				if(q && *p == q) p++;
				if(!append_str(out, out_cap, &n, "=")) return LOM_ERR_PARSE;
				if(n + vl >= out_cap) return LOM_ERR_PARSE;
				memcpy(out + n, vs, vl);
				n += vl;
				out[n] = 0;
			}
			if(*p != ']') return LOM_ERR_PARSE;
			p++;
		}
		if(strncmp(p, ":nth-child(", 11) == 0) {
			p += 11;
			int idx = 0;
			while(isdigit((unsigned char)*p)) idx = idx * 10 + (*p++ - '0');
			if(*p != ')') return LOM_ERR_PARSE;
			p++;
			if(idx < 1) return LOM_ERR_PARSE;
			char buf[32];
			snprintf(buf, sizeof(buf), "[%d]", idx - 1);
			if(!append_str(out, out_cap, &n, buf)) return LOM_ERR_PARSE;
		} else if(strncmp(p, ":last-of-type", 13) == 0) {
			p += 13;
			if(!append_str(out, out_cap, &n, "[$]")) return LOM_ERR_PARSE;
		}
	}
	return n ? LOM_OK : LOM_ERR_PARSE;
}

lom_status lom_doc_get_xpath(lom_doc *doc, const char *xpath, lom_match_list *out) {
	char sel[1024];
	lom_status st = lom_xpath_to_lom(xpath, sel, sizeof(sel));
	if(st != LOM_OK) return st;
	return lom_doc_get(doc, sel, out);
}

lom_status lom_doc_get_css(lom_doc *doc, const char *css, lom_match_list *out) {
	char sel[1024];
	lom_status st = lom_css_to_lom(css, sel, sizeof(sel));
	if(st != LOM_OK) return st;
	return lom_doc_get(doc, sel, out);
}
