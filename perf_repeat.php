<?php
// Repeated benchmark runner to reduce noise from single perf_test.php runs.
// Usage:
//   php perf_repeat.php           # default 7 runs
//   php perf_repeat.php 11        # custom run count

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
    if($idx < 0) {
        $idx = 0;
    }
    if($idx >= count($values)) {
        $idx = count($values) - 1;
    }
    return $values[$idx];
}

function parse_metric_ms($output, $label_prefix) {
    $pattern = '/^' . preg_quote($label_prefix, '/') . '\s*:\s*([0-9]+(?:\.[0-9]+)?)\s*ms/m';
    if(preg_match($pattern, $output, $m)) {
        return (float)$m[1];
    }
    return null;
}

function parse_total_seconds($output) {
    if(preg_match('/Total time spent querying XML:\s*([0-9]+(?:\.[0-9]+)?)\s*seconds/i', $output, $m)) {
        return (float)$m[1];
    }
    return null;
}

$runs = 7;
if(isset($argv[1]) && is_numeric($argv[1])) {
    $runs = max(1, (int)$argv[1]);
}

$metrics = array(
    'total_seconds' => array(),
    'set_after_warmup_ms' => array(),
    'new_nested_insert_ms' => array(),
    'top_level_cold_ms' => array(),
    'descendant_warm_ms' => array(),
);

$perfTest = __DIR__ . DIRECTORY_SEPARATOR . 'perf_test.php';
for($i = 0; $i < $runs; $i++) {
    $output = shell_exec('php ' . escapeshellarg($perfTest) . ' 2>&1');
    if(!is_string($output) || $output === '') {
        fwrite(STDERR, "Run " . ($i + 1) . " produced no output.\n");
        continue;
    }
    $t = parse_total_seconds($output);
    if($t !== null) {
        $metrics['total_seconds'][] = $t;
    }
    $set_ms = parse_metric_ms($output, 'set() after context warmup');
    if($set_ms !== null) {
        $metrics['set_after_warmup_ms'][] = $set_ms;
    }
    $new_ms = parse_metric_ms($output, 'new_() nested insert');
    if($new_ms !== null) {
        $metrics['new_nested_insert_ms'][] = $new_ms;
    }
    $top_cold_ms = parse_metric_ms($output, 'top-level repeated tag (cold)');
    if($top_cold_ms !== null) {
        $metrics['top_level_cold_ms'][] = $top_cold_ms;
    }
    $desc_warm_ms = parse_metric_ms($output, 'descendant chain (warm)');
    if($desc_warm_ms !== null) {
        $metrics['descendant_warm_ms'][] = $desc_warm_ms;
    }
}

echo "perf_repeat summary (" . $runs . " run" . ($runs === 1 ? "" : "s") . ")\n";
echo str_repeat('-', 48) . "\n";
foreach($metrics as $name => $values) {
    if(count($values) === 0) {
        echo str_pad($name, 24) . " n=0\n";
        continue;
    }
    $min = min($values);
    $max = max($values);
    $med = median($values);
    $p90v = p90($values);
    echo str_pad($name, 24)
        . " n=" . str_pad((string)count($values), 2, ' ', STR_PAD_LEFT)
        . "  min=" . str_pad((string)round($min, 3), 8, ' ', STR_PAD_LEFT)
        . "  med=" . str_pad((string)round($med, 3), 8, ' ', STR_PAD_LEFT)
        . "  p90=" . str_pad((string)round($p90v, 3), 8, ' ', STR_PAD_LEFT)
        . "  max=" . str_pad((string)round($max, 3), 8, ' ', STR_PAD_LEFT)
        . "\n";
}

