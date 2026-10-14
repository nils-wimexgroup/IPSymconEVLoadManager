<?php

/**
 * Simulation: EV Datensammler (EVLM Arensdorf + ChargePilot Regenstauf) und EV Auswertung
 * laufen zwei Tage lang im Symcon-Nachbau. Prüft, was in Archiv, Kachel und CSV ankommt.
 * Aufruf: php tests/sim/run_sim.php
 */

namespace Sim;

date_default_timezone_set('Europe/Berlin');
require __DIR__ . '/stub.php';

set_error_handler(function ($no, $str, $file, $line) {
    if (!(error_reporting() & $no)) {
        return false;   // mit @ unterdrückt
    }
    throw new \ErrorException($str, 0, $no, $file, $line);
});

$root = dirname(__DIR__, 2);
function loadSim(string $file)
{
    $code = file_get_contents($file);
    $code = preg_replace('/^<\?php/', '', $code);
    $code = preg_replace('/^require_once .*;\s*$/m', '', $code);
    $code = str_replace('__DIR__', var_export(dirname($file), true), $code);
    eval('namespace Sim; ' . $code);
}
foreach (['EVDataCollector/lib/EVCounter.php', 'EVDataCollector/lib/EVSource.php', 'EVDataCollector/lib/EVMinute.php',
    'EVAnalytics/lib/EVPeriod.php', 'EVAnalytics/lib/EVStats.php', 'EVAnalytics/lib/EVReport.php', 'EVAnalytics/lib/EVArchiveReader.php',
    'EVAnalytics/module.php', 'EVDataCollector/module.php'] as $f) {
    loadSim($root . '/' . $f);
}

$fail = 0;
$count = 0;
function check(string $name, $got, $want, float $tol = 1e-6)
{
    global $fail, $count;
    $count++;
    $ok = is_float($want) || is_int($want) && is_float($got) ? abs((float) $got - (float) $want) <= $tol : $got === $want;
    if (!$ok) {
        $fail++;
        echo "FEHLER  $name\n        erwartet: " . var_export($want, true) . "\n        erhalten: " . var_export($got, true) . "\n";
    }
}
function out(callable $f): string
{
    ob_start();
    $f();
    return ob_get_clean();
}
function newVar(int $type, $value, int $parent, string $name, string $ident = ''): int
{
    $id = IPS_CreateVariable($type);
    IPS_SetParent($id, $parent);
    IPS_SetName($id, $name);
    if ($ident !== '') {
        IPS_SetIdent($id, $ident);
    }
    SetValue($id, $value);
    return $id;
}
function cat(string $name, int $parent): int
{
    $id = IPS_CreateCategory();
    IPS_SetParent($id, $parent);
    IPS_SetName($id, $name);
    return $id;
}

$S = mktime(0, 0, 0, 10, 12, 2026);   // Montag
S()['now'] = $S - 3600;

// ---------------- Grundinstanzen
$ac = createInstance('{43192F0B-135B-4CE7-A0A7-1475603F3060}', null, 'Archiv');
$wh = createInstance('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}', null, 'WebHook Control', 0, ['Hooks' => '[]']);

// ---------------- Arensdorf: EVLM-Instanz mit drei Ladepunkten
$evlm = createInstance('{8C1F2A3B-4D5E-6071-8293-A4B5C6D7E8F9}', null, 'EV Lastmanagement', 0, ['Active' => true, 'BudgetA' => 20.0]);
$v = [];
foreach (['L1', 'L2', 'L3'] as $k => $n) {
    $v[$n] = [
        'state' => newVar(3, 'A', $evlm, "$n Status"), 'c1' => newVar(2, 0.0, $evlm, "$n Strom L1"), 'c2' => newVar(2, 0.0, $evlm, "$n Strom L2"),
        'c3' => newVar(2, 0.0, $evlm, "$n Strom L3"), 'set' => newVar(2, 6.0, $evlm, "$n Soll"), 'meter' => newVar(2, 1000.0 * ($k + 1), $evlm, "$n Zähler")
    ];
}
newVar(2, 0.0, $evlm, 'Zugeteilt', 'Used');
IPS_SetProperty($evlm, 'Groups', json_encode([['Name' => 'Saeule 1', 'Vendor' => 'alfen', 'MaxA' => 32], ['Name' => 'Saeule 2', 'Vendor' => 'alfen', 'MaxA' => 32]]));
$cpCfg = [];
foreach ([['L1', 'links', 'Saeule 1'], ['L2', 'rechts', 'Saeule 1'], ['L3', 'links', 'Saeule 2']] as [$n, $name, $g]) {
    $cpCfg[] = ['Name' => $name, 'Group' => $g, 'MaxA' => 32, 'StateVar' => $v[$n]['state'], 'CurL1' => $v[$n]['c1'], 'CurL2' => $v[$n]['c2'],
        'CurL3' => $v[$n]['c3'], 'SetVar' => $v[$n]['set'], 'EnergyVar' => $v[$n]['meter'], 'Prio' => false];
}
IPS_SetProperty($evlm, 'ChargePoints', json_encode($cpCfg));

// alte Zählerhistorie für L1: 30 Tage, 20 kWh je Tag, ein Ausreißer (0)
AC_SetLoggingStatus($ac, $v['L1']['meter'], true);
$hist = [];
for ($i = 1; $i <= 30; $i++) {
    $hist[] = ['TimeStamp' => $S - $i * 86400 + 12 * 3600, 'Value' => 1000.0 - ($i - 1) * 20];
}
$hist[] = ['TimeStamp' => $S - 15 * 86400 + 13 * 3600, 'Value' => 0.0];
AC_AddLoggedValues($ac, $v['L1']['meter'], $hist);

// ---------------- Regenstauf: ChargePilot-Ordner
$cpRoot = cat('Ladesäulen Regenstauf', 0);
$siteCat = cat('Standort', $cpRoot);
$sa1 = cat('Säule 1', $cpRoot);
$lpL = cat('links', $sa1);
$lpR = cat('rechts', $sa1);
$mbAddr = function (int $parent, int $addr, int $dt, $value) {
    $inst = createInstance('{CB197E50-273D-4535-8C91-BB35273E3CA5}', null, "Adresse $addr", $parent, ['ReadAddress' => $addr, 'ReadFunctionCode' => 4, 'DataType' => $dt]);
    return newVar($dt === 6 ? 1 : 1, $value, $inst, 'Wert', 'Value');
};
$cp = [
    'count' => $mbAddr($siteCat, 4108, 3, 2), 'fuse' => $mbAddr($siteCat, 4112, 3, 32),
    'Lstate' => $mbAddr($lpL, 12328, 3, 1), 'Lpower' => $mbAddr($lpL, 12304, 3, 0), 'Lenergy' => $mbAddr($lpL, 12324, 3, 4294967295),
    'Lcur' => $mbAddr($lpL, 12306, 6, 0),
];
// rechts als ModBus Device (ab Symcon 7.0) mit mehreren Adressen in einer Instanz
$dev = createInstance('{90BF9234-B44C-7EB3-280B-FBD6075ADDAA}', null, 'ChargePilot rechts', $lpR, ['Addresses' => json_encode([
    ['Active' => true, 'Ident' => '', 'DataType' => 3, 'ReadFunctionCode' => 4, 'ReadAddress' => 12584],
    ['Active' => true, 'Ident' => '', 'DataType' => 3, 'ReadFunctionCode' => 4, 'ReadAddress' => 12560],
    ['Active' => true, 'Ident' => 'Energie', 'DataType' => 3, 'ReadFunctionCode' => 4, 'ReadAddress' => 12580],
])]);
$cp['Rstate'] = newVar(1, 1, $dev, 'Status', 'A_3_4_12584');
$cp['Rpower'] = newVar(1, 0, $dev, 'Leistung', 'A_3_4_12560');
$cp['Renergy'] = newVar(1, 4294967295, $dev, 'Energie', 'Energie');

// ---------------- Module
$evaw = createInstance('{FD2A745A-12F8-1A48-40AD-D84F70819EFF}', 'Sim\EVAnalytics', 'EV Auswertung');
$dsA = createInstance('{2BA54478-7D6D-155B-129D-20033B7D3C81}', 'Sim\EVDataCollector', 'EV Datensammler ADF', 0,
    ['SiteCode' => 'ADF', 'SiteName' => 'Arensdorf', 'Source' => 'evlm', 'EVLMInstance' => $evlm, 'Target' => $evaw, 'SampleInterval' => 30]);
$dsR = createInstance('{2BA54478-7D6D-155B-129D-20033B7D3C81}', 'Sim\EVDataCollector', 'EV Datensammler RGST', 0,
    ['SiteCode' => 'RGST', 'SiteName' => 'Regenstauf', 'Source' => 'chargepilot', 'CPFolder' => $cpRoot, 'Target' => $evaw, 'SampleInterval' => 30]);
$mA = S()['mods'][$dsA];
$mR = S()['mods'][$dsR];
$mW = S()['mods'][$evaw];

check('Datensammler ADF aktiv', S()['status'][$dsA], 102);
check('Datensammler RGST wartet auf Zuordnung', S()['status'][$dsR], 204);
check('CSV-Hook in der WebHook Control', json_decode(IPS_GetProperty($wh, 'Hooks'), true), [['Hook' => '/hook/evauswertung', 'TargetID' => $evaw]]);

$scanText = out(function () use ($mR) {
    $mR->ScanFolder();
});
check('Ordner einlesen: zwei Ladepunkte', strpos($scanText, '2 vollständige Ladepunkte') !== false, true);
check('Ordner einlesen: Anzahl laut ChargePilot', strpos($scanText, 'meldet 2 Ladepunkte') !== false, true);
out(function () use ($mR) {
    $mR->ApplyScan();
});
check('Datensammler RGST nach Übernahme aktiv', S()['status'][$dsR], 102);

// ---------------- zwei Tage Betrieb
$meter = [1000.0, 2000.0, 3000.0];
$sessWh = 0.0;
$end = $S + 2 * 86400 + 3600;
$imported = '';
$reimport = '';
$rowsAfterImport = 0;
$rowsAfterReimport = -1;
for ($t = $S; $t < $end; $t += 30) {
    S()['now'] = $t;
    $h = ($t - mktime(0, 0, 0, (int) date('n', $t), (int) date('j', $t), (int) date('Y', $t))) / 3600;
    $day = intdiv($t - $S, 86400);
    // Arensdorf
    $l1 = $h >= 8 && $h < 12;
    if ($l1) {
        $meter[0] += 16 * 3 * 230 / 1000 * 30 / 3600;
    }
    SetValue($v['L1']['state'], $l1 ? 'C2' : 'A');
    foreach (['c1', 'c2', 'c3'] as $c) {
        SetValue($v['L1'][$c], $l1 ? 16.0 : 0.0);
    }
    SetValue($v['L1']['meter'], $t === $S + 10 * 3600 ? 0.0 : round($meter[0], 3));   // ein Ausreißer
    SetValue($v['L2']['state'], $h >= 13 && $h < 14 ? 'B2' : 'A');
    SetValue($v['L3']['state'], 'A');
    foreach (['L2', 'L3'] as $n) {
        foreach (['c1', 'c2', 'c3', 'meter'] as $c) {
            SetValue($v[$n][$c], GetValue($v[$n][$c]));
        }
    }
    // Regenstauf, am zweiten Tag 9.30–10.00 Uhr VPN weg (keine Aktualisierung)
    $charging = $h >= 9 && $h < 10.5;
    $plugged = $h >= 10.5 && $h < 11;
    if ($h >= 9 && $h < 9 + 30 / 3600) {
        $sessWh = 0.0;
    }
    if ($charging) {
        $sessWh += 7400 * 30 / 3600;
    }
    $vpnDown = $day === 1 && $h >= 9.5 && $h < 10;
    if (!$vpnDown) {
        SetValue($cp['Lstate'], $charging ? 3 : ($plugged ? 2 : 1));
        SetValue($cp['Lpower'], $charging ? 7400 : 0);
        SetValue($cp['Lenergy'], $charging || $plugged ? (int) round($sessWh) : 4294967295);
        SetValue($cp['Lcur'], $charging ? 11 : 0);
        foreach (['Rstate', 'Rpower', 'Renergy', 'count', 'fuse'] as $k) {
            SetValue($cp[$k], GetValue($cp[$k]));
        }
    }
    $mA->Sample();
    $mR->Sample();
    if ($t % 60 === 0) {
        $mW->Housekeeping();
    }
    if ($t === $S + 300) {
        $imported = out(function () use ($mA) {
            $mA->ImportHistory();
        });
        $eVar = json_decode(S()['attr'][$evaw]['Sites'], true)['ADF']['saeulen'][0]['points'][0]['varE'];
        $rowsAfterImport = count(S()['logged'][$eVar]);
        $reimport = out(function () use ($mA) {
            $mA->ImportHistory();
        });
        $rowsAfterReimport = count(S()['logged'][$eVar]);
    }
}

// ---------------- Prüfen
$sites = json_decode(S()['attr'][$evaw]['Sites'], true);
check('Standorte in der Auswertung', array_keys($sites), ['ADF', 'RGST']);
check('Kennungen Arensdorf', array_column($sites['ADF']['saeulen'][0]['points'], 'id'), ['ADF-LS01-1', 'ADF-LS01-2']);
check('Budget Regenstauf aus Sicherung 32 A', $sites['RGST']['budgetKw'], 22.1);
check('Säulen-Limit Arensdorf durch Budget 20 A', $sites['ADF']['saeulen'][0]['limitKw'], 13.8);
$eVar = $sites['ADF']['saeulen'][0]['points'][0]['varE'];
check('Zählervariable wird archiviert', S()['logging'][$eVar] ?? false, true);
check('Zählervariable hat Aggregation Zähler', S()['aggtype'][$eVar] ?? null, 1);
check('Historie: Ausreißer verworfen', strpos($imported, '1 Ausreißer verworfen') !== false, true);
check('Historie: zweiter Lauf verdoppelt nichts', $rowsAfterReimport, $rowsAfterImport);
check('Historie: Ausreißer nicht übernommen', count(array_filter(S()['logged'][$eVar], function ($r) use ($S) {
    return $r['TimeStamp'] < $S && $r['Value'] == 0;
})), 0);
check('Historie beginnt 30 Tage vorher', date('d.m.Y', $sites['ADF']['histSince']), date('d.m.Y', $S - 30 * 86400));
check('Puffer leer', count(json_decode((string) @file_get_contents(IPS_GetKernelDir() . 'EVDataCollector' . DIRECTORY_SEPARATOR . $dsA . '_outbox.json'), true) ?: []), 0);
check('Live-Stand Regenstauf vorhanden', isset(json_decode(S()['attr'][$evaw]['Live'], true)['RGST']['points']), true);

// bestehende Installation ohne „Nullwerte ignorieren“: Übernehmen repariert und aggregiert neu
S()['ignoreZeros'][$eVar] = false;
$mW->ApplyChanges();
check('Reparatur: Nullwerte werden wieder ignoriert', S()['ignoreZeros'][$eVar], true);
check('Reparatur: Neuaggregation eingeplant', array_key_exists((string) $eVar, json_decode(S()['attr'][$evaw]['Reagg'], true)), true);

$ask = function (string $ident, array $view) use ($mW, $evaw) {
    S()['viz'][$evaw] = [];
    $mW->RequestAction($ident, json_encode($view + ['cid' => 'test1']));
    return json_decode(end(S()['viz'][$evaw]), true);
};
$w = $ask('View', ['type' => 'woche', 'off' => 0, 'scope' => 'all'])['data'];
$l1kwh = 2 * 4 * 16 * 3 * 230 / 1000;   // 88,32 kWh
$rgkwh = 2 * 1.5 * 7.4;                  // 22,2 kWh
check('Woche: Energie gesamt', $w['kpi']['kwh'], $l1kwh + $rgkwh, 0.6);
check('Woche: Ladevorgänge (Ausfall zählt nicht doppelt)', $w['kpi']['ses'], 6);
check('Woche: Regenstauf trotz VPN-Ausfall vollständig', $w['rows'][1]['kwh'], $rgkwh, 0.3);
check('Woche: Regenstauf 2 Ladevorgänge', $w['rows'][1]['ses'], 2);
check('Woche: Höchste Leistung Arensdorf', $w['sitePeaks'][0]['peak'], 11.04, 0.01);
check('Woche: nie am Budget', $w['kpi']['limitDays'], 0);
check('Antwort trägt die Kennung der Kachel', $ask('View', ['type' => 'woche', 'off' => 0, 'scope' => 'all'])['cid'], 'test1');

$m = $ask('View', ['type' => 'monat', 'off' => 0, 'scope' => 'site:ADF'])['data'];
check('Oktober Arensdorf: Historie 1.–11.10. plus Betrieb', $m['kpi']['kwh'], 11 * 20 + $l1kwh, 0.6);
check('Oktober Arensdorf: Säule 1 · links vorn', $m['rows'][0]['label'], 'Säule 1 · links');
check('Oktober Arensdorf: Belegung Mo 8–9 Uhr bei L1', $ask('View', ['type' => 'woche', 'off' => 0, 'scope' => 'saeule:ADF:ADF-LS01'])['data']['heat']['grid'][0][8], 0.5, 0.01);

$csv = $ask('Csv', ['type' => 'woche', 'off' => 0, 'scope' => 'all', 'mode' => 'summen']);
check('CSV: Link erzeugt', strpos($csv['url'] ?? '', '/hook/evauswertung/csv?t=') === 0, true);
parse_str(parse_url($csv['url'], PHP_URL_QUERY), $q);
$_GET = $q;
$body = out(function () use ($mW) {
    $m = new \ReflectionMethod($mW, 'ProcessHookData');
    $m->setAccessible(true);
    $m->invoke($mW);
});
check('CSV: Download liefert Kopfzeile', strpos($body, "\xEF\xBB\xBFStandort;Säule;Ladepunkt") === 0, true);
check('CSV: 5 Ladepunkte', $csv['lines'], 5);
$_GET = ['t' => 'falsch'];
check('CSV: falscher Link abgelehnt', strpos(out(function () use ($mW) {
    $m = new \ReflectionMethod($mW, 'ProcessHookData');
    $m->setAccessible(true);
    $m->invoke($mW);
}), 'abgelaufen') !== false, true);

check('Kachel startet mit Bericht', strpos($mW->GetVisualizationTile(), '"ev":"report"') !== false, true);
check('Formular Auswertung', count(json_decode($mW->GetConfigurationForm(), true)['actions'][0]['values']), 2);
check('Formular Datensammler', count(json_decode($mA->GetConfigurationForm(), true)['actions'][0]['values']), 3);

// Anzahl Ladepunkte ändert sich: Aufzeichnung anhalten
SetValue($cp['count'], 3);
$seqBefore = (int) S()['attr'][$dsR]['Seq'];
for ($i = 0; $i < 4; $i++) {
    S()['now'] += 30;
    $mR->Sample();
}
check('Neue Säule beim ChargePilot: Status 205', S()['status'][$dsR], 205);
check('Neue Säule beim ChargePilot: keine Datensätze mehr', (int) S()['attr'][$dsR]['Seq'], $seqBefore);

$warn = array_values(array_filter(S()['log'], function ($l) {
    return strpos($l, 'DEBUG') !== 0;
}));
echo "Meldungen im Log:\n  " . implode("\n  ", $warn) . "\n";
echo ($fail ? "$fail von $count Prüfungen fehlgeschlagen\n" : "Alle $count Prüfungen der Simulation bestanden\n");
exit($fail ? 1 : 0);
