<?php

$today = mktime(0, 0, 0, 10, 14, 2026);   // Mittwoch, 14.10.2026

// ---- Zeiträume ----
$p = EVPeriod::Build('monat', 0, $today);
check('Monat: Bezeichnung', $p['label'], 'Oktober 2026');
check('Monat: 31 Balken', count($p['buckets']), 31);
check('Monat: läuft noch', $p['partial'], true);
check('Monat: Einheit', $p['unit'], 'Tag');
$q = EVPeriod::Previous($p, $today);
check('Monat: Vergleich beginnt am 1.9.', $q['start'], mktime(0, 0, 0, 9, 1, 2026));
check('Monat: Vergleich endet nach 14 Tagen', $q['end'], mktime(0, 0, 0, 9, 15, 2026));
check('Monat: Vergleichstext', EVPeriod::CompareText($p), 'zum gleichen Zeitraum im Vormonat');
check('Woche -1: Vergleichstext', EVPeriod::CompareText($w2 = EVPeriod::Build('woche', -1, $today)), 'zur Vorwoche');

$w = EVPeriod::Build('woche', 0, $today);
check('Woche: Bezeichnung', $w['label'], 'KW 42 · 12.10.–18.10.2026');
check('Woche: Montag als Start', $w['start'], mktime(0, 0, 0, 10, 12, 2026));
$w2 = EVPeriod::Build('woche', -1, $today);
check('Woche -1: Bezeichnung', $w2['label'], 'KW 41 · 5.10.–11.10.2026');
check('Woche -1: abgeschlossen', $w2['partial'], false);
$wj = EVPeriod::Build('woche', 11, mktime(0, 0, 0, 12, 30, 2026));
check('Woche über Jahreswechsel: Bezeichnung mit neuem Jahr', $wj['label'], 'KW 11 · 15.3.–21.3.2027');

$y = EVPeriod::Build('12m', 0, $today);
check('12 Monate: Bezeichnung', $y['label'], 'Nov 2025 – Okt 2026');
check('12 Monate: 12 Balken', count($y['buckets']), 12);
$yq = EVPeriod::Previous($y, $today);
check('12 Monate: Vergleich beginnt Nov 2024', $yq['start'], mktime(0, 0, 0, 11, 1, 2024));

$j = EVPeriod::Build('jahr', -1, $today);
check('Jahr -1: Bezeichnung', $j['label'], '2025');
check('Jahr -1: Dezember endet am 1.1.2026', $j['buckets'][11]['e'], mktime(0, 0, 0, 1, 1, 2026));

$jan = EVPeriod::Build('monat', -9, $today);
check('Monat -9: Januar 2026', $jan['label'], 'Januar 2026');
$feb = EVPeriod::Build('monat', -20, $today);
check('Monat -20: Februar 2025', $feb['label'], 'Februar 2025');
check('Monat -20: 28 Tage', count($feb['buckets']), 28);

$okt = EVPeriod::Build('monat', 0, $today);
$umst = $okt['buckets'][24];   // 25.10.2026 = Umstellung auf Winterzeit
check('Zeitumstellung: Tag hat 25 Stunden', $umst['e'] - $umst['s'], 25 * 3600);

// ---- Belegung ----
$from = mktime(0, 0, 0, 10, 12, 2026);   // Montag
$to = mktime(0, 0, 0, 10, 13, 2026);
$log = [
    ['TimeStamp' => mktime(23, 0, 0, 10, 11, 2026), 'Value' => true],    // seit Sonntagabend belegt
    ['TimeStamp' => mktime(7, 30, 0, 10, 12, 2026), 'Value' => false],
    ['TimeStamp' => mktime(8, 15, 0, 10, 12, 2026), 'Value' => true],
    ['TimeStamp' => mktime(8, 20, 0, 10, 12, 2026), 'Value' => true],    // Wiederholung, kein neuer Vorgang
    ['TimeStamp' => mktime(10, 45, 0, 10, 12, 2026), 'Value' => false],
    ['TimeStamp' => mktime(16, 0, 0, 10, 12, 2026), 'Value' => true],
];
$iv = EVStats::Intervals($log, false, $from, $to);
check('Intervalle: Anzahl', count($iv), 3);
check('Intervalle: erstes beginnt am Zeitraumstart', $iv[0][0], $from);
check('Intervalle: erstes begann vor dem Zeitraum', $iv[0][2], false);
check('Intervalle: letztes läuft bis Zeitraumende', $iv[2][1], $to);
check('Ladevorgänge: nur die zwei, die im Zeitraum begannen', count(EVStats::Starts($iv)), 2);
check('Belegte Sekunden', EVStats::Seconds($iv), (int) ((7.5 + 2.5 + 8) * 3600));

$hs = EVStats::HourSeconds($iv);
check('Heatmap: Mo 7–8 Uhr halb belegt', $hs[0][7], 1800);
check('Heatmap: Mo 8–9 Uhr ab 8.15 Uhr belegt', $hs[0][8], 2700);
check('Heatmap: Mo 10–11 Uhr bis 10.45 Uhr', $hs[0][10], 2700);
check('Heatmap: Mo 12–13 Uhr frei', $hs[0][12], 0);
check('Heatmap: Mo 23–24 Uhr belegt', $hs[0][23], 3600);
check('Geschäftszeit belegt (7–18 Uhr)', EVStats::BizSeconds($hs, 7, 18), (int) ((0.5 + 2.5 + 2) * 3600));
check('Geschäftszeit eines Tages', EVStats::BizWindowSeconds($from, $to, 7, 18), 11 * 3600);
check('Geschäftszeit einer Woche', EVStats::BizWindowSeconds($from, EVPeriod::AddDays($from, 7), 7, 18), 5 * 11 * 3600);
check('Wochentage in KW 42', EVStats::WeekdayCounts($from, EVPeriod::AddDays($from, 7)), [1, 1, 1, 1, 1, 1, 1]);
check('Intervalle: Anfangszustand belegt ohne Werte', EVStats::Intervals([], true, $from, $to), [[$from, $to, false]]);
