<?php
require_once __DIR__ . '/O.php';

function median($values) {
    if(!is_array($values) || count($values) === 0) {
        return null;
    }
    sort($values, SORT_NUMERIC);
    $n = count($values);
    $mid = (int)floor($n / 2);
    if(($n % 2) === 1) {
        return $values[$mid];
    }
    return ($values[$mid - 1] + $values[$mid]) / 2.0;
}

function p90($values) {
    if(!is_array($values) || count($values) === 0) {
        return null;
    }
    sort($values, SORT_NUMERIC);
    $idx = (int)ceil((count($values) * 0.90) - 1);
    if($idx < 0) { $idx = 0; }
    if($idx >= count($values)) { $idx = count($values) - 1; }
    return $values[$idx];
}

function now_ms() {
    return microtime(true) * 1000.0;
}

$runs = 11;
if(isset($argv[1]) && is_numeric($argv[1])) {
    $runs = max(1, (int)$argv[1]);
}

$fixture = __DIR__ . '/perf_fixture.xml';
if(!file_exists($fixture)) {
    fwrite(STDERR, "Missing perf_fixture.xml\n");
    exit(1);
}

$selector = 'region[1]_zone[3]_entity[4]_meta_note';
$set_times_ms = array();
$select_warm_times_ms = array();

for($i = 0; $i < $runs; $i++) {
    $O = new O($fixture);
    $O->debug(false);

    // Warm selector path similarly to perf_test.php.
    $t0 = now_ms();
    $targets = $O->get_tagged($selector);
    $t1 = now_ms();

    if(!is_array($targets) || count($targets) < 1) {
        fwrite(STDERR, "Selector warmup produced no matches on run " . ($i + 1) . "\n");
        continue;
    }

    $new_value = 'set-perf-' . $i;
    $O->__($selector, $new_value);
    $t2 = now_ms();

    $select_warm_times_ms[] = ($t1 - $t0);
    $set_times_ms[] = ($t2 - $t1);
}

echo "set_perf_repeat summary (" . $runs . " run" . ($runs === 1 ? "" : "s") . ")\n";
echo str_repeat('-', 48) . "\n";

foreach(array(
    'select_warm_ms' => $select_warm_times_ms,
    'set_ms' => $set_times_ms,
) as $name => $values) {
    if(count($values) === 0) {
        echo str_pad($name, 16) . " n=0\n";
        continue;
    }
    $min = min($values);
    $med = median($values);
    $pv = p90($values);
    $max = max($values);
    echo str_pad($name, 16)
        . " n=" . str_pad((string)count($values), 2, ' ', STR_PAD_LEFT)
        . "  min=" . str_pad((string)round($min, 3), 8, ' ', STR_PAD_LEFT)
        . "  med=" . str_pad((string)round($med, 3), 8, ' ', STR_PAD_LEFT)
        . "  p90=" . str_pad((string)round($pv, 3), 8, ' ', STR_PAD_LEFT)
        . "  max=" . str_pad((string)round($max, 3), 8, ' ', STR_PAD_LEFT)
        . "\n";
}

