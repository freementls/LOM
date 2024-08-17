<?php

$array = array(43 => 'um', 22 => 'huh', 456 => 'yes', '99' => 'no', '97' => 'maybe so');
print('$array: ');var_dump($array);
$next_result = true;
while($next_result !== false) {
    print(key($array) . '<br />' . PHP_EOL);
    $next_result = next($array);
}
$next_result = true;
while($next_result !== false) {
    print(key($array) . '<br />' . PHP_EOL);
    $next_result = next($array);
}
ksort($array);
print('$array: ');var_dump($array);

?>
