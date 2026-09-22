<?php

/**
 * EV Lastmanagement - IP-Symcon Modul
 * -----------------------------------
 * Dynamisches Lastmanagement (Water-Filling) fuer mehrere Ladepunkte, mit
 *  - Gruppen/Saeulen (gemeinsames Limit, z. B. Dual-Socket: beide zus. max 32 A)
 *  - Priorisierung: Session-Vorrang (bis Ladeende) und Dauer-Vorrang (bis aus)
 * Liest Status/Strom aus Symcon-Variablen und schreibt die Sollwerte per
 * RequestAction zurueck (herstellerneutral, z. B. Alfen Modbus).
 * HTML-SDK-Kachel (module.html) mit Ladesaeulen-Grafik, Status und Leistung.
 */
class EVLoadManager extends IPSModule
{
    private const MAXCP = 8;
    private const INF = 1000000.0;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyBoolean('Active', true);
        $this->RegisterPropertyFloat('BudgetA', 32.0);
        $this->RegisterPropertyFloat('MinA', 6.0);
        $this->RegisterPropertyFloat('Hyst', 1.0);
        $this->RegisterPropertyFloat('Headroom', 2.0);
        $this->RegisterPropertyFloat('MinStep', 1.0);
        $this->RegisterPropertyInteger('Interval', 15);
        $this->RegisterPropertyInteger('ValidTime', 0);
        $this->RegisterPropertyString('Groups', '[]');
        $this->RegisterPropertyString('ChargePoints', '[]');

        $this->RegisterAttributeString('SessionState', '{}');

        $this->RegisterProfiles();

        $this->RegisterVariableFloat('Budget', 'Budget', 'EVLM.Ampere', 10);
        $this->RegisterVariableFloat('Used', 'Zugeteilt gesamt', 'EVLM.Ampere', 20);
        $this->RegisterVariableFloat('Free', 'Frei', 'EVLM.Ampere', 30);
        $this->RegisterVariableInteger('ActiveCount', 'Aktive Ladepunkte', '', 40);
        $this->RegisterVariableFloat('PowerTotal', 'Leistung gesamt', 'EVLM.kW', 50);

        $this->RegisterTimer('Balance', 0, 'EVLM_Balance($_IPS[\'TARGET\']);');

        // HTML-SDK-Kachel aktivieren (ohne dies zeigt Symcon nur die Variablenliste als Text)
        $this->SetVisualizationType(1);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // HTML-Kachel aktivieren - greift auch bei bestehenden Instanzen, sobald "Uebernehmen" gedrueckt wird
        $this->SetVisualizationType(1);

        $this->MaintainChargePointVariables();

        $active = $this->ReadPropertyBoolean('Active');
        // Timer laeuft immer (liest Ist-Werte + aktualisiert die Kachel); Sollwerte werden nur bei aktivem Management geschrieben.
        // Watchdog-Sicherheit: nie langsamer schreiben als die halbe Gueltigkeitszeit (ValidTime>0 = per Reg 1208
        // gesetzt, 0 = Box-Default 60 s angenommen) und hoechstens alle 30 s, damit der Alfen-Sollwert nie verfaellt.
        $interval = max(5, $this->ReadPropertyInteger('Interval'));
        $valid = (int) $this->ReadPropertyInteger('ValidTime');
        $wdMax = ($valid > 0) ? max(5, (int) floor($valid / 2)) : 30;
        $this->SetTimerInterval('Balance', min($interval, $wdMax) * 1000);
        $this->SetStatus($active ? 102 : 104);

        if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->Balance();
        }
    }

    /**
     * Formular dynamisch aufbauen: die Wallbox-Auswahl je Ladepunkt wird aus den
     * definierten Saeulen/Gruppen als Dropdown befuellt (kein manuelles Eintippen).
     */
    public function GetConfigurationForm()
    {
        $form = json_decode(@file_get_contents(__DIR__ . '/form.json'), true);
        if (!is_array($form)) {
            return '{"elements":[]}';
        }
        $options = [['caption' => '(keine)', 'value' => '']];
        $groups = json_decode($this->ReadPropertyString('Groups'), true);
        if (is_array($groups)) {
            foreach ($groups as $g) {
                $name = trim((string) ($g['Name'] ?? ''));
                if ($name !== '') {
                    $options[] = ['caption' => $name, 'value' => $name];
                }
            }
        }
        if (isset($form['elements']) && is_array($form['elements'])) {
            foreach ($form['elements'] as &$el) {
                if (($el['type'] ?? '') === 'List' && ($el['name'] ?? '') === 'ChargePoints' && isset($el['columns'])) {
                    foreach ($el['columns'] as &$col) {
                        if (($col['name'] ?? '') === 'Group') {
                            $col['add'] = '';
                            $col['edit'] = ['type' => 'Select', 'options' => $options];
                        }
                    }
                    unset($col);
                }
            }
            unset($el);
        }
        return json_encode($form);
    }

    /**
     * Eine Regelrunde. Oeffentlich: EVLM_Balance($InstanceID);
     */
    public function Balance()
    {
        $active   = $this->ReadPropertyBoolean('Active');
        $budget   = $this->ReadPropertyFloat('BudgetA');
        $min      = $this->ReadPropertyFloat('MinA');
        $hyst     = $this->ReadPropertyFloat('Hyst');
        $headroom = $this->ReadPropertyFloat('Headroom');
        $minstep  = $this->ReadPropertyFloat('MinStep');
        $validT   = $this->ReadPropertyInteger('ValidTime');

        $data = $this->ReadChargePoints();
        $caps = $this->GetGroupCaps();

        // Session-Energie: Startwerte je Socket (persistent)
        $sess = json_decode($this->ReadAttributeString('SessionState'), true);
        if (!is_array($sess)) {
            $sess = [];
        }

        // ---- Bedarf bestimmen ----
        foreach ($data as $i => &$s) {
            $s['alloc'] = $s['set'];
            $s['need']  = $min;
            if ($s['active']) {
                $wantsMore = ($s['act'] >= $s['set'] - $hyst) && ($s['set'] < $s['max'] - 0.1);
                $s['need'] = $wantsMore ? $s['max'] : min(max($s['act'] + $headroom, $min), $s['max']);
            }
        }
        unset($s);

        // ---- Session-Vorrang aufheben, wenn Ladevorgang beendet (kein Auto) ----
        if ($active) {
            foreach ($data as $i => &$s) {
                if ($s['sessionPrio'] && !$s['active'] && !$s['ready'] && !$s['error']) {
                    $this->SetValueSafe("CP{$i}_PrioSession", false);
                    $s['sessionPrio'] = false;
                    $s['prio'] = $s['permPrio'];
                }
            }
            unset($s);
        }

        // ---- Water-Filling: Budget + Gruppenlimit + Priorisierung ----
        if ($active) {
            $partPrio = [];
            $partNorm = [];
            foreach ($data as $i => $s) {
                if ($s['active'] || $s['ready']) {
                    if ($s['prio']) {
                        $partPrio[] = $i;
                    } else {
                        $partNorm[] = $i;
                    }
                }
            }
            $partAll = array_merge($partPrio, $partNorm); // Vorrang zuerst
            foreach ($data as $i => &$s) {
                $s['alloc'] = 0.0;
            }
            unset($s);

            $globalRem = $budget;
            $groupRem = [];
            foreach ($caps as $name => $cap) {
                $groupRem[$name] = $cap;
            }

            // 1) Mindeststrom - priorisierte zuerst (bei knappem Budget)
            foreach ($partAll as $i) {
                $g = $data[$i]['group'];
                $gr = ($g !== '' && isset($groupRem[$g])) ? $groupRem[$g] : self::INF;
                if ($globalRem >= $min && $gr >= $min) {
                    $data[$i]['alloc'] = $min;
                    $globalRem -= $min;
                    if ($g !== '' && isset($groupRem[$g])) {
                        $groupRem[$g] -= $min;
                    }
                }
            }

            // 2) Rest verteilen - erst die Vorrang-Gruppe voll, dann der Rest
            foreach ([$partPrio, $partNorm] as $tier) {
                while ($globalRem > 0.01) {
                    $hungry = [];
                    foreach ($tier as $i) {
                        if (!$data[$i]['active']) {
                            continue;
                        }
                        if ($data[$i]['alloc'] >= $data[$i]['need'] - 0.01) {
                            continue;
                        }
                        $g = $data[$i]['group'];
                        $gr = ($g !== '' && isset($groupRem[$g])) ? $groupRem[$g] : self::INF;
                        if ($gr > 0.01) {
                            $hungry[] = $i;
                        }
                    }
                    if (count($hungry) == 0) {
                        break;
                    }
                    $share = $globalRem / count($hungry);
                    $progress = false;
                    foreach ($hungry as $i) {
                        $g = $data[$i]['group'];
                        $gr = ($g !== '' && isset($groupRem[$g])) ? $groupRem[$g] : self::INF;
                        $give = min($share, $data[$i]['need'] - $data[$i]['alloc'], $gr);
                        if ($give > 0.01) {
                            $data[$i]['alloc'] += $give;
                            $globalRem -= $give;
                            if ($g !== '' && isset($groupRem[$g])) {
                                $groupRem[$g] -= $give;
                            }
                            $progress = true;
                        }
                    }
                    if (!$progress) {
                        break;
                    }
                }
            }
        }

        // ---- Anwenden + Variablen aktualisieren ----
        $used = 0.0;
        $activeCount = 0;
        $powerTotal = 0.0;

        foreach ($data as $i => $s) {
            $target = floor($s['alloc']);
            if ($target < $min) {
                $target = 0.0;
            }

            if ($active && $s['setVar'] > 0 && IPS_VariableExists($s['setVar'])) {
                // Anti-Pendeln: kleine Aenderungen (< MinStep) nicht uebernehmen, sondern alten Sollwert halten.
                if (abs($target - $s['set']) < $minstep && !($target == 0.0 && $s['set'] > 0.0)) {
                    $target = floor($s['set']);
                }
                // WICHTIG: Sollwert JEDEN Zyklus schreiben (auch unveraendert), um den Alfen-Watchdog
                // (Reg 1210, Gueltigkeit per Reg 1208, Default 60 s) aufzufrischen. Sonst verfaellt der
                // Sollwert und die Box faellt auf ihren (evtl. hoeheren) Fallback zurueck -> Oszillation.
                @RequestAction($s['setVar'], $target);
                if ($validT > 0 && $s['validVar'] > 0 && IPS_VariableExists($s['validVar'])) {
                    @RequestAction($s['validVar'], $validT);
                }
                $data[$i]['alloc'] = $target;
            } else {
                $data[$i]['alloc'] = $s['set'];
            }

            $this->SetValueSafe("CP{$i}_State", $s['stateLabel'] !== '' ? $s['stateLabel'] : $s['state']);
            $this->SetValueSafe("CP{$i}_Alloc", (float) $data[$i]['alloc']);
            $this->SetValueSafe("CP{$i}_Power", (float) $s['power']);

            // Session-Energie = aktueller Zaehlerstand - Zaehlerstand bei Ladestart
            $connected = ($s['active'] || $s['ready']);
            $stKey = (string) $i;
            $stState = (isset($sess[$stKey]) && is_array($sess[$stKey])) ? $sess[$stKey] : ['start' => null, 'conn' => false];
            if ($connected && $s['energyVar'] > 0) {
                if (empty($stState['conn']) || $stState['start'] === null) {
                    $stState['start'] = $s['meter']; // neuer Ladevorgang -> Startwert merken
                }
                $stState['conn'] = true;
                $data[$i]['energy'] = max(0.0, $s['meter'] - (float) $stState['start']);
            } else {
                $stState['conn'] = false;
                $data[$i]['energy'] = 0.0;
            }
            $sess[$stKey] = $stState;
            $this->SetValueSafe("CP{$i}_Energy", (float) $data[$i]['energy']);

            $powerTotal += $s['power'];
            if ($s['active']) {
                $activeCount++;
                $used += $data[$i]['alloc'];
            }
        }

        $this->WriteAttributeString('SessionState', json_encode($sess));

        $this->SetValueSafe('Budget', $budget);
        $this->SetValueSafe('Used', $used);
        $this->SetValueSafe('Free', max(0.0, $budget - $used));
        $this->SetValueSafe('ActiveCount', $activeCount);
        $this->SetValueSafe('PowerTotal', round($powerTotal, 1));

        $this->UpdateVisualizationValue(json_encode($this->BuildViz($data, $active, $budget, $used)));
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident === 'Active') {
            IPS_SetProperty($this->InstanceID, 'Active', (bool) $Value);
            IPS_ApplyChanges($this->InstanceID);
            return;
        }
        if (preg_match('/^CP\d+_PrioSession$/', $Ident)) {
            $this->SetValueSafe($Ident, (bool) $Value);
            $this->Balance();
            return;
        }
        throw new Exception('Invalid ident: ' . $Ident);
    }

    public function GetVisualizationTile()
    {
        $active = $this->ReadPropertyBoolean('Active');
        $budget = $this->ReadPropertyFloat('BudgetA');

        $data = $this->ReadChargePoints();
        $used = 0.0;
        foreach ($data as $i => &$s) {
            $s['alloc'] = $s['set'];
            if ($s['active']) {
                $used += $s['set'];
            }
        }
        unset($s);

        $init = json_encode($this->BuildViz($data, $active, $budget, $used));
        $html = @file_get_contents(__DIR__ . '/module.html');
        if ($html === false) {
            return '<div style="padding:8px">module.html fehlt.</div>';
        }
        return str_replace('%%INITIAL%%', $init === false ? '{}' : $init, $html);
    }

    // ---------------------------------------------------------------- privat

    private function BuildViz($data, $active, $budget, $used)
    {
        $caps = $this->GetGroupCaps();
        $stations = [];
        $order = [];
        $totalPower = 0.0;

        foreach ($data as $i => $s) {
            $totalPower += $s['power'];
            $g = $s['group'];
            if ($g !== '') {
                $key = 'g:' . $g;
                $sname = $g;
                $smax = isset($caps[$g]) ? $caps[$g] : null;
            } else {
                $key = 'i:' . $i;
                $sname = $s['name'];
                $smax = null;
            }
            if (!isset($stations[$key])) {
                $stations[$key] = ['name' => $sname, 'max' => $smax, 'power' => 0.0, 'sockets' => []];
                $order[] = $key;
            }
            $mode = $s['error'] ? 'fehler' : ($s['active'] ? 'laden' : ($s['ready'] ? 'bereit' : 'frei'));
            $perm = (bool) ($s['permPrio'] ?? false);
            $sess = (bool) ($s['sessionPrio'] ?? false);
            $stations[$key]['power'] += $s['power'];
            $stations[$key]['sockets'][] = [
                'idx'     => $i,
                'name'    => $s['name'],
                'mode'    => $mode,
                'state'   => $s['stateLabel'] !== '' ? $s['stateLabel'] : $mode,
                'act'     => round($s['act'], 1),
                'set'     => (int) round($s['alloc']),
                'max'     => round($s['max'], 1),
                'power'   => round($s['power'], 1),
                'energy'  => round($s['energy'] ?? 0, 2),
                'perm'    => $perm,
                'session' => $sess,
                'prio'    => ($perm || $sess)
            ];
        }

        $list = [];
        foreach ($order as $k) {
            $st = $stations[$k];
            $st['power'] = round($st['power'], 1);
            if ($st['max'] !== null) {
                $st['max'] = round($st['max'], 1);
            }
            $list[] = $st;
        }

        return [
            'active'   => $active,
            'budget'   => round($budget, 1),
            'used'     => round($used, 1),
            'free'     => round(max(0.0, $budget - $used), 1),
            'power'    => round($totalPower, 1),
            'stations' => $list
        ];
    }

    private function GetGroupCaps()
    {
        $groups = json_decode($this->ReadPropertyString('Groups'), true);
        $caps = [];
        if (is_array($groups)) {
            foreach ($groups as $g) {
                $name = trim((string) ($g['Name'] ?? ''));
                if ($name !== '') {
                    $caps[$name] = (float) ($g['MaxA'] ?? 0);
                }
            }
        }
        return $caps;
    }

    // Hersteller je Gruppe/Wallbox: 'alfen' (geraetespezifische Statustabelle)
    // oder 'standard' (IEC 61851). Fehlt/leer -> 'alfen' (Bestandsverhalten).
    private function GetGroupVendors()
    {
        $groups = json_decode($this->ReadPropertyString('Groups'), true);
        $vendors = [];
        if (is_array($groups)) {
            foreach ($groups as $g) {
                $name = trim((string) ($g['Name'] ?? ''));
                if ($name !== '') {
                    $v = strtolower(trim((string) ($g['Vendor'] ?? '')));
                    $vendors[$name] = ($v === 'standard') ? 'standard' : 'alfen';
                }
            }
        }
        return $vendors;
    }

    private function ReadChargePoints()
    {
        $cps = json_decode($this->ReadPropertyString('ChargePoints'), true);
        if (!is_array($cps)) {
            $cps = [];
        }

        $vendors = $this->GetGroupVendors();
        $out = [];
        $idx = 0;
        foreach ($cps as $cp) {
            if ($idx >= self::MAXCP) {
                break;
            }
            $stateVar = (int) ($cp['StateVar'] ?? 0);
            $setVar   = (int) ($cp['SetVar'] ?? 0);
            $validVar = (int) ($cp['ValidVar'] ?? 0);
            $energyVar = (int) ($cp['EnergyVar'] ?? 0);
            $maxA     = (float) ($cp['MaxA'] ?? 32);
            $curVars  = [(int) ($cp['CurL1'] ?? 0), (int) ($cp['CurL2'] ?? 0), (int) ($cp['CurL3'] ?? 0)];
            $group    = trim((string) ($cp['Group'] ?? ''));
            $vendor   = $vendors[$group] ?? 'alfen';

            $act = 0.0;
            $sum = 0.0;
            foreach ($curVars as $v) {
                if ($v > 0 && IPS_VariableExists($v)) {
                    $c = (float) @GetValue($v);
                    $act = max($act, $c);
                    $sum += $c;
                }
            }
            $set      = ($setVar > 0 && IPS_VariableExists($setVar)) ? (float) @GetValue($setVar) : 0.0;
            $meter    = ($energyVar > 0 && IPS_VariableExists($energyVar)) ? (float) @GetValue($energyVar) : 0.0;
            $stateStr = ($stateVar > 0 && IPS_VariableExists($stateVar)) ? (string) @GetValue($stateVar) : '';
            $letter   = strlen($stateStr) > 0 ? strtoupper(substr($stateStr, 0, 1)) : '';

            // Fehler-Erkennung je Hersteller:
            //  Alfen (geraetespezifisch): A und F = Fehler, E = Leerlauf/ohne Kabel (kein Fehler)
            //  Standard (IEC 61851):      E und F = Fehler, A = kein Fahrzeug (frei)
            if ($vendor === 'alfen') {
                $isError = ($letter === 'A' || $letter === 'F');
            } else {
                $isError = ($letter === 'E' || $letter === 'F');
            }
            if ($isError) {
                $isActive = false;
                $isReady = false;
            } elseif ($letter === 'C' || $letter === 'D') {
                $isActive = true;
                $isReady = false;
            } elseif ($letter === 'B') {
                $isActive = false;
                $isReady = true;
            } elseif ($letter === '') {
                $isActive = ($act > 0.5);
                $isReady = false;
            } else {
                $isActive = false;
                $isReady = false;
            }

            $permPrio = (bool) ($cp['Prio'] ?? false);
            $sessionPrio = false;
            try {
                $spid = $this->GetIDForIdent("CP{$idx}_PrioSession");
                if ($spid && IPS_VariableExists($spid)) {
                    $sessionPrio = (bool) GetValue($spid);
                }
            } catch (Exception $e) {
            }

            $out[$idx] = [
                'name'        => (string) ($cp['Name'] ?? ('Ladepunkt ' . ($idx + 1))),
                'group'       => $group,
                'vendor'      => $vendor,
                'max'         => $maxA,
                'setVar'      => $setVar,
                'validVar'    => $validVar,
                'energyVar'   => $energyVar,
                'meter'       => $meter,
                'energy'      => 0.0,
                'act'         => $act,
                'sum'         => $sum,
                'power'       => round($sum * 230 / 1000, 1),
                'set'         => $set,
                'state'       => $stateStr,
                'stateLabel'  => $this->Mode3Label($stateStr, $vendor),
                'active'      => $isActive,
                'ready'       => $isReady,
                'error'       => $isError,
                'permPrio'    => $permPrio,
                'sessionPrio' => $sessionPrio,
                'prio'        => ($permPrio || $sessionPrio),
                'alloc'       => 0.0,
                'need'        => 0.0
            ];
            $idx++;
        }
        return $out;
    }

    private function SetValueSafe($ident, $value)
    {
        try {
            $id = $this->GetIDForIdent($ident);
        } catch (Exception $e) {
            return;
        }
        if ($id && IPS_VariableExists($id)) {
            SetValue($id, $value);
        }
    }

    // Mode-3-Status (IEC 61851, z. B. "C2") in deutschen Klartext uebersetzen.
    // A und E werden je Hersteller unterschiedlich gedeutet:
    //   Alfen:    A = Fehler, E = Frei (Leerlauf/ohne Kabel)
    //   Standard: A = Frei (kein Fahrzeug), E = Fehler (IEC 61851)
    private function Mode3Label($state, $vendor = 'alfen')
    {
        $s = strtoupper(trim((string) $state));
        if ($s === '') {
            return '';
        }
        $labelA = ($vendor === 'alfen') ? 'Fehler (A)' : 'Frei';
        $labelE = ($vendor === 'alfen') ? 'Frei' : 'Fehler (E)';
        switch ($s) {
            case 'A':  return $labelA;
            case 'B1': return 'Verbunden';
            case 'B2': return 'Bereit';
            case 'C1': return 'Bereit';
            case 'C2': return 'Lädt';
            case 'D1':
            case 'D2': return 'Lädt (belüftet)';
            case 'E':  return $labelE;
            case 'F':  return 'Fehler (F)';
        }
        switch (substr($s, 0, 1)) {
            case 'A': return $labelA;
            case 'B': return 'Bereit';
            case 'C': return 'Lädt';
            case 'D': return 'Lädt';
            case 'E': return $labelE;
            case 'F': return 'Fehler';
        }
        return $s;
    }

    private function MaintainChargePointVariables()
    {
        $cps = json_decode($this->ReadPropertyString('ChargePoints'), true);
        if (!is_array($cps)) {
            $cps = [];
        }
        $count = count($cps);
        for ($i = 0; $i < self::MAXCP; $i++) {
            $keep = $i < $count;
            $name = $keep ? (string) ($cps[$i]['Name'] ?? ('Ladepunkt ' . ($i + 1))) : ('Ladepunkt ' . ($i + 1));
            $base = 100 + $i * 10;
            $this->MaintainVariable("CP{$i}_State", $name . ' - Status',            3, '',            $base + 1, $keep);
            $this->MaintainVariable("CP{$i}_Alloc", $name . ' - Sollwert',          2, 'EVLM.Ampere', $base + 2, $keep);
            $this->MaintainVariable("CP{$i}_Power", $name . ' - Leistung',          2, 'EVLM.kW',     $base + 3, $keep);
            $this->MaintainVariable("CP{$i}_Energy", $name . ' - Session-Energie',  2, 'EVLM.kWh',    $base + 5, $keep);
            $this->MaintainVariable("CP{$i}_PrioSession", $name . ' - Vorrang (Sitzung)', 0, '~Switch', $base + 4, $keep);
            if ($keep) {
                $this->EnableAction("CP{$i}_PrioSession");
            }
        }
    }

    private function RegisterProfiles()
    {
        if (!IPS_VariableProfileExists('EVLM.Ampere')) {
            IPS_CreateVariableProfile('EVLM.Ampere', 2);
            IPS_SetVariableProfileValues('EVLM.Ampere', 0, 63, 1);
            IPS_SetVariableProfileDigits('EVLM.Ampere', 1);
            IPS_SetVariableProfileText('EVLM.Ampere', '', ' A');
            IPS_SetVariableProfileIcon('EVLM.Ampere', 'Electricity');
        }
        if (!IPS_VariableProfileExists('EVLM.kW')) {
            IPS_CreateVariableProfile('EVLM.kW', 2);
            IPS_SetVariableProfileValues('EVLM.kW', 0, 50, 0.1);
            IPS_SetVariableProfileDigits('EVLM.kW', 2);
            IPS_SetVariableProfileText('EVLM.kW', '', ' kW');
            IPS_SetVariableProfileIcon('EVLM.kW', 'EnergyProduction');
        }
        if (!IPS_VariableProfileExists('EVLM.kWh')) {
            IPS_CreateVariableProfile('EVLM.kWh', 2);
            IPS_SetVariableProfileValues('EVLM.kWh', 0, 100000, 0);
            IPS_SetVariableProfileDigits('EVLM.kWh', 2);
            IPS_SetVariableProfileText('EVLM.kWh', '', ' kWh');
            IPS_SetVariableProfileIcon('EVLM.kWh', 'Battery');
        }
    }
}
