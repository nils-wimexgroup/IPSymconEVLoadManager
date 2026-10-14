<?php

require_once __DIR__ . '/../EVDataCollector/lib/EVSource.php';
require_once __DIR__ . '/../EVDataCollector/lib/EVMinute.php';

// ---- Status ----
check('Mode3: A frei', EVSource::ModeFromMode3('A', 'alfen', 0), 'frei');
check('Mode3: E bei Alfen frei', EVSource::ModeFromMode3('E', 'alfen', 0), 'frei');
check('Mode3: E bei Standard Fehler', EVSource::ModeFromMode3('E', 'standard', 0), 'fehler');
check('Mode3: F Fehler', EVSource::ModeFromMode3('F', 'alfen', 0), 'fehler');
check('Mode3: B2 bereit', EVSource::ModeFromMode3('B2', 'alfen', 0), 'bereit');
check('Mode3: C1 bereit (Säule gibt nichts frei)', EVSource::ModeFromMode3('C1', 'alfen', 0), 'bereit');
check('Mode3: C2 lädt', EVSource::ModeFromMode3('C2', 'alfen', 10), 'laden');
check('Mode3: ohne Status, Strom fließt', EVSource::ModeFromMode3('', 'alfen', 6), 'laden');
check('ChargePilot: 2 = angesteckt', EVSource::ModeFromChargePilot(2), 'bereit');
check('ChargePilot: 4 = Fehler', EVSource::ModeFromChargePilot('4'), 'fehler');
check('ChargePilot: unbekannt = offline', EVSource::ModeFromChargePilot(9), 'offline');
check('belegt bei bereit', EVSource::IsBelegt('bereit'), true);
check('nicht belegt bei Fehler', EVSource::IsBelegt('fehler'), false);

// ---- EVLM ----
$groups = [['Name' => 'Saeule 1', 'Vendor' => 'alfen', 'MaxA' => 32], ['Name' => 'Saeule 2', 'Vendor' => 'alfen', 'MaxA' => 20]];
$cps = [
    ['Name' => 'links', 'Group' => 'Saeule 1', 'MaxA' => 32, 'StateVar' => 11, 'CurL1' => 12, 'CurL2' => 13, 'CurL3' => 14, 'SetVar' => 15, 'EnergyVar' => 16],
    ['Name' => 'rechts', 'Group' => 'Saeule 1', 'MaxA' => 32, 'StateVar' => 21, 'CurL1' => 22, 'CurL2' => 23, 'CurL3' => 24, 'SetVar' => 25, 'EnergyVar' => 26],
    ['Name' => 'links', 'Group' => 'Saeule 2', 'MaxA' => 32, 'StateVar' => 31, 'CurL1' => 32, 'CurL2' => 33, 'CurL3' => 34, 'SetVar' => 35, 'EnergyVar' => 36],
];
[$pts, $sa, $budget, $map] = EVSource::FromEVLM('ADF', $groups, $cps, 20.0, []);
check('EVLM: Kennungen', array_column($pts, 'id'), ['ADF-LS01-1', 'ADF-LS01-2', 'ADF-LS02-1']);
check('EVLM: Säulenname lesbar', $pts[0]['saeuleName'], 'Säule 1');
check('EVLM: Budget 20 A = 13,8 kW', $budget, 13.8);
check('EVLM: Säule 1 begrenzt durch Budget', $sa['ADF-LS01']['limitKw'], 13.8);
check('EVLM: Säule 2 begrenzt durch 20 A', $sa['ADF-LS02']['limitKw'], 13.8);
check('EVLM: Kennung an Statusvariable gemerkt', $map['21'], 'ADF-LS01-2');
// Liste umsortiert: Kennungen bleiben an den Statusvariablen
[$pts2] = EVSource::FromEVLM('ADF', $groups, [$cps[1], $cps[0], $cps[2]], 20.0, $map);
check('EVLM: Umsortieren ändert keine Kennung', array_column($pts2, 'id'), ['ADF-LS01-2', 'ADF-LS01-1', 'ADF-LS02-1']);
// neuer Ladepunkt bekommt eine freie Kennung
$cps[] = ['Name' => 'rechts', 'Group' => 'Saeule 2', 'MaxA' => 32, 'StateVar' => 41];
[$pts3, , , $map3] = EVSource::FromEVLM('ADF', $groups, $cps, 20.0, $map);
check('EVLM: neuer Ladepunkt', $pts3[3]['id'], 'ADF-LS02-2');

// ---- ChargePilot ----
check('Adresse 12328 = Status Ladepunkt 1', EVSource::ClassifyAddress(12328), [0, 'state']);
check('Adresse 12560 = Leistung Ladepunkt 2', EVSource::ClassifyAddress(12560), [1, 'power']);
check('Adresse 4108 = Anzahl', EVSource::ClassifyAddress(4108), ['site', 'count']);
check('Adresse 12352 (RFID) wird nicht erkannt', EVSource::ClassifyAddress(12352), null);
$dev = function ($var, $addr, $lp, $sa) {
    return ['var' => $var, 'addr' => $addr, 'lp' => $lp, 'saeule' => $sa, 'path' => "$sa/$lp/$addr"];
};
$devices = [
    $dev(101, 12328, 'links', 'Säule 1'), $dev(102, 12304, 'links', 'Säule 1'), $dev(103, 12324, 'links', 'Säule 1'), $dev(104, 12374, 'links', 'Säule 1'),
    $dev(201, 12584, 'rechts', 'Säule 1'), $dev(202, 12560, 'rechts', 'Säule 1'), $dev(203, 12580, 'rechts', 'Säule 1'),
    $dev(301, 12840, 'links', 'Säule 2'), $dev(302, 12816, 'links', 'Säule 2'),
    $dev(901, 4108, 'Standort', 'Ladesäulen Regenstauf'), $dev(902, 4112, 'Standort', 'Ladesäulen Regenstauf'),
    $dev(999, 12352, 'links', 'Säule 1'),
];
$scan = EVSource::ScanChargePilot('RGST', $devices, []);
check('ChargePilot: zwei vollständige Ladepunkte', array_column($scan['points'], 'id'), ['RGST-LS01-1', 'RGST-LS01-2']);
check('ChargePilot: Soll-Strom erkannt', $scan['points'][0]['vars']['set'], 104);
check('ChargePilot: Standort-Register', $scan['site'], ['count' => 901, 'fuseMain' => 902]);
check('ChargePilot: Hinweise (RFID ignoriert, Energie fehlt bei Säule 2)', count($scan['issues']), 2);
check('ChargePilot: Watt werden zu kW', EVSource::PowerKw(7400), 7.4);
check('ChargePilot: kW bleiben kW', EVSource::PowerKw(7.4), 7.4);

// ---- Minuten-Datensätze ----
$t0 = mktime(10, 0, 5, 10, 14, 2026);
$smp = function ($kw1, $b1, $e1, $kw2) {
    return [
        ['id' => 'A-LS01-1', 'saeule' => 'A-LS01', 'kw' => $kw1, 'belegt' => $b1, 'counter' => $e1],
        ['id' => 'A-LS01-2', 'saeule' => 'A-LS01', 'kw' => $kw2, 'belegt' => $kw2 > 0, 'counter' => 50.0],
    ];
};
[$acc, $rec] = EVMinute::Add([], $t0, $smp(7.0, true, 100.0, 0.0), 1);
check('Minute: erster Wert liefert noch keinen Datensatz', $rec, null);
[$acc, $rec] = EVMinute::Add($acc, $t0 + 20, $smp(11.0, true, 100.1, 4.0), 1);
[$acc, $rec] = EVMinute::Add($acc, $t0 + 60, $smp(11.0, true, 100.3, 4.0), 1);
check('Minute: Datensatz beim Minutenwechsel', $rec['t'], mktime(10, 0, 0, 10, 14, 2026));
check('Minute: Ø Leistung', $rec['p']['A-LS01-1'][2], 9.0);
check('Minute: letzter Zählerstand', $rec['p']['A-LS01-1'][0], 100.1);
check('Minute: Säule max (gleichzeitig)', $rec['s']['A-LS01'], 15.0);
check('Minute: Standort max', $rec['site'], 15.0);
check('Minute: belegt', $rec['p']['A-LS01-2'][1], 1);
