<?php
$lom_root = __DIR__;
include $lom_root . '/O.php';

function ok($cond, $label) {
    print($label . ': ' . ($cond ? "OK" : "FAIL") . PHP_EOL);
    if(!$cond) {
        exit(1);
    }
}

$xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE root [
  <!ELEMENT root ANY>
  <!ENTITY customEntity "EntityFromDoctype">
]>
<!-- top-level comment with < and > characters -->
<?xml-stylesheet type="text/xsl" href="style.xsl"?>
<?php echo "embedded php"; ?>
<% Response.Write("embedded asp") %>
<root>
  <meta>
    <raw><![CDATA[
      if (a < b && c > d) { return "&not-tag;"; }
    ]]></raw>
  </meta>
  <item id="i1">
    <name>Alpha</name>
    <value>&customEntity;</value>
  </item>
  <item id="i2">
    <name>Beta</name>
    <value>2</value>
  </item>
</root>
XML;

$tmp = tempnam(sys_get_temp_dir(), 'lom_header_sections_');
if($tmp === false) {
    print("temp file creation failed\n");
    exit(1);
}
file_put_contents($tmp, $xml);

$O = new O($tmp);
$O->debug(false);

// Verify parser feature detection toggles for syntactically challenging sections.
ok($O->must_check_for_doctype === true, 'detect doctype');
ok($O->must_check_for_non_parsed_character_data === true, 'detect cdata');
ok($O->must_check_for_comment === true, 'detect comment');
ok($O->must_check_for_programming_instruction === true, 'detect processing instruction');
ok($O->must_check_for_ASP === true, 'detect asp/php block');

// Ensure core selection still works through mixed header/markup sections.
$items = $O->get_tagged('root_item');
ok(is_array($items) && count($items) === 2, 'select root_item count');
$raw = $O->get_tagged('root_meta_raw');
ok(is_array($raw) && count($raw) === 1, 'select cdata container');

// Mutate content and append a new node, then re-query.
$O->__('root_item[1]_name', 'AlphaUpdated');
ok($O->_('root_item[1]_name') === 'AlphaUpdated', 'set text under mixed headers');
$O->new_('<item id="i3"><name>Gamma</name><value>3</value></item>', 'root');
$items_after = $O->get_tagged('root_item');
ok(is_array($items_after) && count($items_after) === 3, 'append item after mixed headers');

// Lightweight structural sanity check after writes.
ok(substr_count($O->code(), '<') === substr_count($O->code(), '>'), 'balanced angle brackets after writes');

// Ensure special sections remain present in serialized code.
$code = $O->code();
ok(strpos($code, '<?xml version="1.0" encoding="UTF-8"?>') !== false, 'keep xml declaration');
ok(strpos($code, '<!DOCTYPE root [') !== false, 'keep doctype');
ok(strpos($code, '<!-- top-level comment with < and > characters -->') !== false, 'keep comment');
ok(strpos($code, '<?xml-stylesheet type="text/xsl" href="style.xsl"?>') !== false, 'keep processing instruction');
ok(strpos($code, '<?php echo "embedded php"; ?>') !== false, 'keep php block');
ok(strpos($code, '<% Response.Write("embedded asp") %>') !== false, 'keep asp block');
ok(strpos($code, '<![CDATA[') !== false, 'keep cdata');

@unlink($tmp);
print("Header sections regression sequence completed.\n");
?>
