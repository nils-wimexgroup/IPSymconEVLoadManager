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
        $this->RegisterPropertyInteger('Interval', 20);
        $this->RegisterPropertyInteger('ValidTime', 0);
        $this->RegisterPropertyString('Groups', '[]');
        $this->RegisterPropertyString('ChargePoints', '[]');

        $this->RegisterProfiles();

        $this->RegisterVariableFloat('Budget', 'Budget', 'EVLM.Ampere', 10);
        $this->RegisterVariableFloat('Used', 'Zugeteilt gesamt', 'EVLM.Ampere', 20);
        $this->RegisterVariableFloat('Free', 'Frei', 'EVLM.Ampere', 30);
        $this->RegisterVariableInteger('ActiveCount', 'Aktive Ladepunkte', '', 40);
        $this->RegisterVariableFloat('PowerTotal', 'Leistung gesamt', 'EVLM.kW', 50);

        $this->RegisterTimer('Balance', 0, 'EVLM_Balance($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->MaintainChargePointVariables();

        $active = $this->ReadPropertyBoolean('Active');
        $interval = $active ? max(5, $this->ReadPropertyInteger('Interval')) * 1000 : 0;
        $this->SetTimerInterval('Balance', $interval);
        $this->SetStatus($active ? 102 : 104);

        if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->Balance();
        }
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
                if (abs($target - $s['set']) >= $minstep || ($target == 0.0 && $s['set'] > 0.0)) {
                    @RequestAction($s['setVar'], $target);
                }
                if ($validT > 0 && $s['validVar'] > 0 && IPS_VariableExists($s['validVar'])) {
                    @RequestAction($s['validVar'], $validT);
                }
                $data[$i]['alloc'] = $target;
            } else {
                $data[$i]['alloc'] = $s['set'];
            }

            $this->SetValueSafe("CP{$i}_State", $s['state']);
            $this->SetValueSafe("CP{$i}_Alloc", (float) $data[$i]['alloc']);
            $this->SetValueSafe("CP{$i}_Power", (float) $s['power']);

            $powerTotal += $s['power'];
            if ($s['active']) {
                $activeCount++;
                $used += $data[$i]['alloc'];
            }
        }

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
                'state'   => $s['state'] !== '' ? $s['state'] : $mode,
                'act'     => round($s['act'], 1),
                'set'     => (int) round($s['alloc']),
                'max'     => round($s['max'], 1),
                'power'   => round($s['power'], 1),
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

    private function ReadChargePoints()
    {
        $cps = json_decode($this->ReadPropertyString('ChargePoints'), true);
        if (!is_array($cps)) {
            $cps = [];
        }

        $out = [];
        $idx = 0;
        foreach ($cps as $cp) {
            if ($idx >= self::MAXCP) {
                break;
            }
            $stateVar = (int) ($cp['StateVar'] ?? 0);
            $setVar   = (int) ($cp['SetVar'] ?? 0);
            $validVar = (int) ($cp['ValidVar'] ?? 0);
            $maxA     = (float) ($cp['MaxA'] ?? 32);
            $curVars  = [(int) ($cp['CurL1'] ?? 0), (int) ($cp['CurL2'] ?? 0), (int) ($cp['CurL3'] ?? 0)];

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
            $stateStr = ($stateVar > 0 && IPS_VariableExists($stateVar)) ? (string) @GetValue($stateVar) : '';
            $letter   = strlen($stateStr) > 0 ? strtoupper(substr($stateStr, 0, 1)) : '';

            $isError = ($letter === 'E' || $letter === 'F');
            if ($isError) {
                $isActive = false;
                $isReady = false;
            } elseif ($letter === 'C') {
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
                'group'       => trim((string) ($cp['Group'] ?? '')),
                'max'         => $maxA,
                'setVar'      => $setVar,
                'validVar'    => $validVar,
                'act'         => $act,
                'sum'         => $sum,
                'power'       => round($sum * 230 / 1000, 1),
                'set'         => $set,
                'state'       => $stateStr,
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
    }
}
