<?php

/**
 * Tests fuer die symcon-unabhaengigen Teile der EV-Module.
 * Aufruf: php tests/run.php
 */
declare(strict_types=1);
date_default_timezone_set('Europe/Berlin');

require __DIR__ . '/../EVDataCollector/lib/EVCounter.php';
require __DIR__ . '/../EVAnalytics/lib/EVPeriod.php';
require __DIR__ . '/../EVAnalytics/lib/EVStats.php';

$fail = 0;
$count = 0;
function check(string $name, $got, $want): void
{
    global $fail, $count;
    $count++;
    $ok = is_float($want) ? abs((float) $got - $want) < 1e-6 : $got === $want;
    if (!$ok) {
        $fail++;
        echo "FEHLER  $name\n        erwartet: " . var_export($want, true) . "\n        erhalten: " . var_export($got, true) . "\n";
    }
}
foreach (glob(__DIR__ . '/test_*.php') as $f) {
    require $f;
}
echo ($fail ? "$fail von $count Prüfungen fehlgeschlagen\n" : "Alle $count Prüfungen bestanden\n");
exit($fail ? 1 : 0);
