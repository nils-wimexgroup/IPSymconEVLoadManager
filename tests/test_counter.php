<?php

// ---- MeterStep: echter Zählerstand (Alfen) ----
$t = 1_790_000_000;
[$s, $n] = EVCounter::MeterStep([], 1000.0, $t, 22.0);
check('Meter: erster Wert setzt geprüften Zähler', $s['out'], 1000.0);
[$s, $n] = EVCounter::MeterStep($s, 1001.5, $t + 300, 22.0);
check('Meter: normaler Zuwachs', $s['out'], 1001.5);
[$s, $n] = EVCounter::MeterStep($s, 0.0, $t + 360, 22.0);
check('Meter: Rücksprung auf 0 verworfen', $s['out'], 1001.5);
check('Meter: Rücksprung Hinweis', $n, 'Rücksprung verworfen');
[$s, $n] = EVCounter::MeterStep($s, 1002.0, $t + 420, 22.0);
check('Meter: nach Ausreißer geht es normal weiter', $s['out'], 1002.0);
[$s, $n] = EVCounter::MeterStep($s, 13345.0, $t + 480, 22.0);
check('Meter: unmöglicher Sprung verworfen', $s['out'], 1002.0);
check('Meter: Sprung Hinweis', $n, 'Sprung verworfen');

// Zählertausch: neuer Zähler beginnt bei 5 kWh und steigt plausibel
$s2 = $s;
$tt = $t + 600;
foreach ([5.0, 5.1, 5.2, 5.3, 5.4] as $i => $v) {
    [$s2, $n] = EVCounter::MeterStep($s2, $v, $tt + 60 * $i, 22.0);
}
check('Meter: Zählertausch nach 5 Werten erkannt', $n, 'Zählerwechsel erkannt, neu angesetzt');
check('Meter: geprüfter Zähler springt beim Tausch nicht', $s2['out'], 1002.0);
[$s2, $n] = EVCounter::MeterStep($s2, 5.6, $tt + 60 * 5, 22.0);
check('Meter: nach Tausch zählt der Zuwachs weiter', $s2['out'], 1002.2);

[$s3, $n] = EVCounter::MeterStep(['out' => 50.0, 'raw' => 50.0, 't' => $t], null, $t + 60, 22.0);
check('Meter: keine Daten ändert nichts', $s3['out'], 50.0);

// ---- SessionStep: Sitzungsenergie (ChargePilot) ----
$c = [];
[$c, $n] = EVCounter::SessionStep($c, EVCounter::NO_SESSION, $t, 22.0);
check('Sitzung: ohne Sitzung bleibt 0', $c['out'], 0.0);
[$c, $n] = EVCounter::SessionStep($c, 0, $t + 60, 22.0);
[$c, $n] = EVCounter::SessionStep($c, 5000, $t + 1800, 22.0);
[$c, $n] = EVCounter::SessionStep($c, 12000, $t + 3600, 22.0);
check('Sitzung: Zuwächse addiert', $c['out'], 12.0);
[$c, $n] = EVCounter::SessionStep($c, EVCounter::NO_SESSION, $t + 3700, 22.0);
check('Sitzung: Ende ändert den Zähler nicht', $c['out'], 12.0);
[$c, $n] = EVCounter::SessionStep($c, 3000, $t + 7200, 22.0);
check('Sitzung: neue Sitzung zählt ab Beginn', $c['out'], 15.0);
[$c, $n] = EVCounter::SessionStep($c, null, $t + 7260, 22.0);
check('Sitzung: Ausfall ändert nichts', $c['out'], 15.0);
[$c, $n] = EVCounter::SessionStep($c, 9000, $t + 10800, 22.0);
check('Sitzung: nach Ausfall läuft dieselbe Sitzung weiter', $c['out'], 21.0);
[$c, $n] = EVCounter::SessionStep($c, 2000, $t + 14400, 22.0);
check('Sitzung: kleinerer Wert = neue Sitzung', $c['out'], 23.0);
[$c, $n] = EVCounter::SessionStep($c, -1, $t + 14460, 22.0);
check('Sitzung: -1 (vorzeichenbehaftet gelesen) = keine Sitzung', $c['last'], null);

// Falls das Register doch ein Gesamtzähler ist: nur Zuwächse
$g = [];
foreach ([[100000, 0], [101000, 600], [101000, 1200], [104500, 3000]] as [$wh, $dt]) {
    [$g, $n] = EVCounter::SessionStep($g, $wh, $t + $dt, 22.0);
}
check('Gesamtzähler: erster Wert zählt als Sitzung, dann nur Zuwächse', $g['out'], 104.5);

// unmöglicher Zuwachs in laufender Sitzung wird begrenzt
$h = ['out' => 0.0, 'last' => 1.0, 't' => $t];
[$h, $n] = EVCounter::SessionStep($h, 90000, $t + 60, 22.0);
check('Sitzung: unmöglicher Zuwachs begrenzt', round($h['out'], 4), round(22.0 * 60 / 3600 + 0.05, 4));
check('Sitzung: Hinweis bei Begrenzung', $n, 'Zuwachs auf Höchstleistung begrenzt');
