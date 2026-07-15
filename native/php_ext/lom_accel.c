#ifdef HAVE_CONFIG_H
#include "config.h"
#endif

#include "php.h"
#include "ext/standard/info.h"
#include "lom.h"

#include <stdbool.h>
#include <string.h>

typedef struct {
	lom_doc *doc;
} lom_doc_res;

static int le_lom_doc;

static void lom_doc_res_dtor(zend_resource *rsrc)
{
	lom_doc_res *r = (lom_doc_res *)rsrc->ptr;
	if(r) {
		lom_doc_free(r->doc);
		efree(r);
	}
}

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_lom_accel_available, 0, 0, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_lom_accel_version, 0, 0, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_MASK_EX(arginfo_lom_accel_scan, 0, 1, MAY_BE_ARRAY|MAY_BE_FALSE)
	ZEND_ARG_TYPE_INFO(0, code, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO(0, capture_attributes, _IS_BOOL, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_lom_doc_create, 0, 0, 1)
	ZEND_ARG_TYPE_INFO(0, code, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_lom_doc_op, 0, 0, 1)
	ZEND_ARG_INFO(0, doc)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_lom_doc_get, 0, 0, 2)
	ZEND_ARG_INFO(0, doc)
	ZEND_ARG_TYPE_INFO(0, selector, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_lom_doc_set, 0, 0, 3)
	ZEND_ARG_INFO(0, doc)
	ZEND_ARG_TYPE_INFO(0, selector, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO(0, text, IS_STRING, 0)
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_INFO_EX(arginfo_lom_doc_new, 0, 0, 3)
	ZEND_ARG_INFO(0, doc)
	ZEND_ARG_TYPE_INFO(0, selector, IS_STRING, 0)
	ZEND_ARG_TYPE_INFO(0, fragment, IS_STRING, 0)
ZEND_END_ARG_INFO()

static lom_doc *fetch_doc(zval *zid)
{
	lom_doc_res *r = (lom_doc_res *)zend_fetch_resource(Z_RES_P(zid), "lom_doc", le_lom_doc);
	return r ? r->doc : NULL;
}

static void matches_to_php(lom_doc *doc, lom_match_list *m, zval *return_value)
{
	array_init(return_value);
	for(size_t i = 0; i < m->count; i++) {
		const char *ptr = NULL;
		size_t len = 0;
		if(lom_doc_node_slice(doc, m->items[i].offset, &ptr, &len) != LOM_OK) continue;
		zval row;
		array_init(&row);
		add_next_index_stringl(&row, ptr, len);
		add_next_index_long(&row, (zend_long)m->items[i].offset);
		add_next_index_zval(return_value, &row);
	}
}

PHP_FUNCTION(lom_accel_available)
{
	RETURN_TRUE;
}

PHP_FUNCTION(lom_accel_version)
{
	RETURN_STRING(lom_version());
}

PHP_FUNCTION(lom_accel_scan)
{
	zend_string *code;
	zend_bool capture_attributes = 1;

	ZEND_PARSE_PARAMETERS_START(1, 2)
		Z_PARAM_STR(code)
		Z_PARAM_OPTIONAL
		Z_PARAM_BOOL(capture_attributes)
	ZEND_PARSE_PARAMETERS_END();

	lom_scan_result r;
	lom_scan_result_init(&r);
	lom_status st = lom_scan_indexes(ZSTR_VAL(code), ZSTR_LEN(code), &r, capture_attributes ? true : false);
	if(st != LOM_OK) {
		lom_scan_result_free(&r);
		RETURN_FALSE;
	}

	array_init(return_value);
	zval opens, tag_ends, parents, node_ends, names, tag_index;
	array_init(&opens);
	array_init(&tag_ends);
	array_init(&parents);
	array_init(&node_ends);
	array_init(&names);
	array_init(&tag_index);

	for(size_t i = 0; i < r.open_count; i++) {
		const lom_open_row *row = &r.opens[i];
		zend_ulong okey = (zend_ulong)row->open_off;
		add_next_index_long(&opens, (zend_long)row->open_off);
		add_index_long(&tag_ends, okey, (zend_long)row->tag_end_off);
		if(row->parent_off < 0) add_index_bool(&parents, okey, 0);
		else add_index_long(&parents, okey, (zend_long)row->parent_off);
		add_index_long(&node_ends, okey, (zend_long)row->node_end_off);
		const char *tname = lom_scan_string(&r, row->name_id);
		add_index_string(&names, okey, tname);
		zval *bucket = zend_hash_str_find(Z_ARRVAL(tag_index), tname, strlen(tname));
		if(!bucket) {
			zval list;
			array_init(&list);
			add_next_index_long(&list, (zend_long)row->open_off);
			add_assoc_zval(&tag_index, tname, &list);
		} else {
			add_next_index_long(bucket, (zend_long)row->open_off);
		}
	}

	zval attr_index, attr_value_index;
	array_init(&attr_index);
	array_init(&attr_value_index);
	for(size_t i = 0; i < r.attr_count; i++) {
		const lom_attr_row *a = &r.attrs[i];
		const char *an = lom_scan_string(&r, a->name_id);
		const char *av = lom_scan_string(&r, a->value_id);
		zend_ulong okey = (zend_ulong)a->open_off;
		zval *amap = zend_hash_str_find(Z_ARRVAL(attr_index), an, strlen(an));
		if(!amap) {
			zval m;
			array_init(&m);
			add_index_bool(&m, okey, 1);
			add_assoc_zval(&attr_index, an, &m);
		} else {
			add_index_bool(amap, okey, 1);
		}
		zval *vmap_root = zend_hash_str_find(Z_ARRVAL(attr_value_index), an, strlen(an));
		if(!vmap_root) {
			zval vroot;
			array_init(&vroot);
			zval vlist;
			array_init(&vlist);
			add_index_bool(&vlist, okey, 1);
			add_assoc_zval(&vroot, av, &vlist);
			add_assoc_zval(&attr_value_index, an, &vroot);
		} else {
			zval *vlist = zend_hash_str_find(Z_ARRVAL_P(vmap_root), av, strlen(av));
			if(!vlist) {
				zval vlist_new;
				array_init(&vlist_new);
				add_index_bool(&vlist_new, okey, 1);
				add_assoc_zval(vmap_root, av, &vlist_new);
			} else {
				add_index_bool(vlist, okey, 1);
			}
		}
	}

	add_assoc_zval(return_value, "opening_tag_offsets", &opens);
	add_assoc_zval(return_value, "tag_end_offsets", &tag_ends);
	add_assoc_zval(return_value, "parent_offsets", &parents);
	add_assoc_zval(return_value, "node_end_offsets", &node_ends);
	add_assoc_zval(return_value, "opening_tag_names", &names);
	add_assoc_zval(return_value, "tag_index", &tag_index);
	add_assoc_long(return_value, "self_closing_open_count", (zend_long)r.self_closing_count);
	add_assoc_zval(return_value, "attribute_index", &attr_index);
	add_assoc_zval(return_value, "attribute_value_index", &attr_value_index);
	add_assoc_bool(return_value, "attribute_full_index_ready", capture_attributes ? 1 : 0);
	lom_scan_result_free(&r);
}

PHP_FUNCTION(lom_doc_create)
{
	zend_string *code;
	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_STR(code)
	ZEND_PARSE_PARAMETERS_END();

	lom_doc *doc = lom_doc_create(ZSTR_VAL(code), ZSTR_LEN(code));
	if(!doc || lom_doc_status(doc) != LOM_OK) {
		if(doc) lom_doc_free(doc);
		RETURN_FALSE;
	}
	lom_doc_res *r = emalloc(sizeof(lom_doc_res));
	r->doc = doc;
	RETURN_RES(zend_register_resource(r, le_lom_doc));
}

PHP_FUNCTION(lom_doc_code)
{
	zval *zid;
	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_RESOURCE(zid)
	ZEND_PARSE_PARAMETERS_END();
	lom_doc *doc = fetch_doc(zid);
	if(!doc) RETURN_FALSE;
	size_t len = 0;
	const char *code = lom_doc_code(doc, &len);
	RETURN_STRINGL(code, len);
}

PHP_FUNCTION(lom_doc_get_tagged)
{
	zval *zid;
	zend_string *sel;
	ZEND_PARSE_PARAMETERS_START(2, 2)
		Z_PARAM_RESOURCE(zid)
		Z_PARAM_STR(sel)
	ZEND_PARSE_PARAMETERS_END();
	lom_doc *doc = fetch_doc(zid);
	if(!doc) RETURN_FALSE;
	lom_match_list m;
	lom_match_list_init(&m);
	if(lom_doc_get(doc, ZSTR_VAL(sel), &m) != LOM_OK) {
		lom_match_list_free(&m);
		RETURN_FALSE;
	}
	matches_to_php(doc, &m, return_value);
	lom_match_list_free(&m);
}

PHP_FUNCTION(lom_doc_get_tagged_parent)
{
	zval *zid;
	zend_string *sel;
	ZEND_PARSE_PARAMETERS_START(2, 2)
		Z_PARAM_RESOURCE(zid)
		Z_PARAM_STR(sel)
	ZEND_PARSE_PARAMETERS_END();
	lom_doc *doc = fetch_doc(zid);
	if(!doc) RETURN_FALSE;
	lom_match_list m;
	lom_match_list_init(&m);
	if(lom_doc_get_parent(doc, ZSTR_VAL(sel), &m) != LOM_OK) {
		lom_match_list_free(&m);
		RETURN_FALSE;
	}
	matches_to_php(doc, &m, return_value);
	lom_match_list_free(&m);
}

PHP_FUNCTION(lom_doc_set_inner_text)
{
	zval *zid;
	zend_string *sel, *text;
	ZEND_PARSE_PARAMETERS_START(3, 3)
		Z_PARAM_RESOURCE(zid)
		Z_PARAM_STR(sel)
		Z_PARAM_STR(text)
	ZEND_PARSE_PARAMETERS_END();
	lom_doc *doc = fetch_doc(zid);
	if(!doc) RETURN_FALSE;
	RETURN_BOOL(lom_doc_set_inner_text(doc, ZSTR_VAL(sel), ZSTR_VAL(text)) == LOM_OK);
}

PHP_FUNCTION(lom_doc_new_before_close)
{
	zval *zid;
	zend_string *sel, *frag;
	ZEND_PARSE_PARAMETERS_START(3, 3)
		Z_PARAM_RESOURCE(zid)
		Z_PARAM_STR(sel)
		Z_PARAM_STR(frag)
	ZEND_PARSE_PARAMETERS_END();
	lom_doc *doc = fetch_doc(zid);
	if(!doc) RETURN_FALSE;
	RETURN_BOOL(lom_doc_new_before_close(doc, ZSTR_VAL(sel), ZSTR_VAL(frag)) == LOM_OK);
}

PHP_FUNCTION(lom_doc_sync_code)
{
	zval *zid;
	ZEND_PARSE_PARAMETERS_START(1, 1)
		Z_PARAM_RESOURCE(zid)
	ZEND_PARSE_PARAMETERS_END();
	lom_doc *doc = fetch_doc(zid);
	if(!doc) RETURN_FALSE;
	size_t len = 0;
	const char *code = lom_doc_code(doc, &len);
	RETURN_STRINGL(code, len);
}

static const zend_function_entry lom_accel_functions[] = {
	PHP_FE(lom_accel_available, arginfo_lom_accel_available)
	PHP_FE(lom_accel_version, arginfo_lom_accel_version)
	PHP_FE(lom_accel_scan, arginfo_lom_accel_scan)
	PHP_FE(lom_doc_create, arginfo_lom_doc_create)
	PHP_FE(lom_doc_code, arginfo_lom_doc_op)
	PHP_FE(lom_doc_get_tagged, arginfo_lom_doc_get)
	PHP_FE(lom_doc_get_tagged_parent, arginfo_lom_doc_get)
	PHP_FE(lom_doc_set_inner_text, arginfo_lom_doc_set)
	PHP_FE(lom_doc_new_before_close, arginfo_lom_doc_new)
	PHP_FE(lom_doc_sync_code, arginfo_lom_doc_op)
	PHP_FE_END
};

PHP_MINIT_FUNCTION(lom_accel)
{
	le_lom_doc = zend_register_list_destructors_ex(lom_doc_res_dtor, NULL, "lom_doc", module_number);
	return SUCCESS;
}

PHP_MINFO_FUNCTION(lom_accel)
{
	php_info_print_table_start();
	php_info_print_table_row(2, "LOM accelerator", "enabled");
	php_info_print_table_row(2, "liblom version", lom_version());
	php_info_print_table_end();
}

zend_module_entry lom_accel_module_entry = {
	STANDARD_MODULE_HEADER,
	"lom_accel",
	lom_accel_functions,
	PHP_MINIT(lom_accel),
	NULL,
	NULL,
	NULL,
	PHP_MINFO(lom_accel),
	"0.2.0",
	STANDARD_MODULE_PROPERTIES
};

#ifdef COMPILE_DL_LOM_ACCEL
#ifdef ZTS
ZEND_TSRMLS_CACHE_DEFINE()
#endif
ZEND_GET_MODULE(lom_accel)
#endif
