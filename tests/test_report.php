<?php

require_once __DIR__ . '/../EVAnalytics/lib/EVReport.php';

require_once __DIR__ . '/fixtures.php';

$biz = ['from' => 7, 'to' => 18];

$v = EVReport::Build($meta, $r, 'monat', 0, 'all', $now, $biz);
check('Bericht: Energie gesamt Oktober', $v['kpi']['kwh'], 410.0);
check('Bericht: Vergleich 1.–14. September', $v['kpi']['prevKwh'], 210.0);
check('Bericht: Ladevorgänge', $v['kpi']['ses'], 24);
check('Bericht: Ø Dauer 2,75 h', $v['kpi']['avgDur'], 2.75);
check('Bericht: Ø kWh je Vorgang', $v['kpi']['avgKwh'], round(410 / 24, 3));
check('Bericht: Auslastung', $v['kpi']['occ'], round(66 / 876, 4));
check('Bericht: Auslastung Geschäftszeit', $v['kpi']['bizOcc'], round(48 / 290, 4));
check('Bericht: höchste Leistung kommt von Arensdorf', $v['kpi']['peakSite'], 'Arensdorf');
check('Bericht: ein Standort-Tag am Budget', $v['kpi']['limitDays'], 1);
check('Bericht: Zeilen sind Standorte', array_column($v['rows'], 'label'), ['Arensdorf', 'Regenstauf']);
check('Bericht: Regenstauf 200 kWh', $v['rows'][1]['kwh'], 200.0);
check('Bericht: Drill-down auf Standort', $v['rows'][0]['drill'], 'site:ADF');
check('Bericht: Balken 2.10. hat 15 kWh', $v['energy'][1]['kwh'], 15.0);
check('Bericht: Balken 5.10. aufgeteilt nach Standort', $v['energy'][4]['by'], ['Arensdorf' => 15.0, 'Regenstauf' => 20.0]);
check('Bericht: Balken 20.10. liegt in der Zukunft', $v['energy'][19]['future'], true);
check('Bericht: Heatmap Montag 8–9 Uhr', $v['heat']['grid'][0][8], round(3600 * 2 / (3 * 2 * 3600), 4));
check('Bericht: Vorher-Knopf aktiv', $v['canPrev'], true);
check('Bericht: Weiter-Knopf aus', $v['canNext'], false);
check('Bericht: lässt sich als JSON ausgeben', json_encode($v) !== false, true);

$s = EVReport::Build($meta, $r, 'monat', 0, 'site:ADF', $now, $biz);
check('Standort: Budget als Grenze', $s['scope']['limitKw'], 13.8);
check('Standort: 2.10. am Budget', $s['peaks'][1]['limitDays'], 1);
check('Standort: Zeilen sind Ladepunkte mit Säule', array_column($s['rows'], 'label'), ['Säule 1 · links', 'Säule 1 · rechts']);
check('Standort: Ladepunkt ohne Belegung hat 0 Vorgänge', $s['rows'][1]['ses'], 0);
check('Standort: Brotkrumen', array_column($s['scope']['crumbs'], 'l'), ['Alle Standorte', 'Arensdorf']);

$g = EVReport::Build($meta, $r, 'monat', 0, 'saeule:RGST:RGST-LS01', $now, $biz);
check('Säule: ohne Limit keine Grenze', $g['scope']['limitKw'], null);
check('Säule: Hinweis zum Standort', $g['scope']['note'], 'Regenstauf: ChargePilot per Modbus über VPN.');
check('Säule: Bezeichnung des Limits', $g['scope']['limitLabel'], 'Säulen-Limit');

$old = EVReport::Build($meta, $r, 'jahr', -1, 'all', $now, $biz);
check('2025: Energie aus Historie', $old['kpi']['kwh'], 365 * 15.0);
check('2025: keine Ladevorgänge', $old['kpi']['ses'], null);
check('2025: keine Spitzen', $old['kpi']['peak'], null);
check('Unbekannte Auswahl wird zu Alle', EVReport::Build($meta, $r, 'monat', 0, 'saeule:XX:YY', $now, $biz)['scope']['value'], 'all');

$csv = EVReport::Csv($meta, $r, 'monat', 0, 'site:ADF', 'summen', $now);
$lines = explode("\r\n", trim($csv['text']));
check('CSV Summen: Kopfzeile', substr($lines[0], 3), 'Standort;Säule;Ladepunkt;Von;Bis;Energie kWh;Ladevorgänge;Ø kWh je Vorgang;Ø Dauer h;Belegt h;Auslastung %');
check('CSV Summen: Zeile links', $lines[1], 'Arensdorf;Säule 1;links;01.10.2026;14.10.2026;140,00;14;10,00;4,00;56,00;17,28');
check('CSV Summen: Dateiname', $csv['filename'], 'ev-auswertung_ADF_Oktober-2026_summen.csv');
$cv = EVReport::Csv($meta, $r, 'monat', 0, 'saeule:RGST:RGST-LS01', 'verlauf', $now);
$cl = explode("\r\n", trim($cv['text']));
check('CSV Verlauf: 10 Tage Regenstauf', $cv['count'], 10);
check('CSV Verlauf: erste Zeile', $cl[1], '05.10.2026;Regenstauf;Säule 1;links;20,00;1;1,00');
