<?php

require_once __DIR__ . '/lib/EVCounter.php';
require_once __DIR__ . '/lib/EVSource.php';
require_once __DIR__ . '/lib/EVMinute.php';

/**
 * EV Datensammler - IP-Symcon Modul
 * ---------------------------------
 * Liest die Ladepunkte eines Standorts (EVLM-Instanz oder ChargePilot-Ordner mit ModBus-Geraeten),
 * fuehrt je Ladepunkt einen geprueften Zaehler, bildet Minuten-Datensaetze und schickt sie zusammen
 * mit dem Live-Stand an die EV Auswertung. Etappe 1: Auswertung auf demselben Symcon (EVAW_Ingest).
 */
class EVDataCollector extends IPSModule
{
    private const EVLM_GUID = '{8C1F2A3B-4D5E-6071-8293-A4B5C6D7E8F9}';
    private const EVAW_GUID = '{FD2A745A-12F8-1A48-40AD-D84F70819EFF}';
    private const MODBUS_ADDRESS_GUID = '{CB197E50-273D-4535-8C91-BB35273E3CA5}';   // ModBus Address: ein Register je Instanz
    private const MODBUS_DEVICE_GUID = '{90BF9234-B44C-7EB3-280B-FBD6075ADDAA}';    // ModBus Device (ab 7.0): mehrere Adressen je Instanz
    private const MAX_REC = 120;       // Minuten-Datensätze je Lieferung
    private const MAX_OUTBOX = 43200;  // 30 Tage

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('SiteCode', '');
        $this->RegisterPropertyString('SiteName', '');
        $this->RegisterPropertyString('Source', 'evlm');
        $this->RegisterPropertyInteger('EVLMInstance', 0);
        $this->RegisterPropertyInteger('CPFolder', 0);
        $this->RegisterPropertyInteger('CPAddressOffset', 0);
        $this->RegisterPropertyFloat('BudgetKw', 0.0);
        $this->RegisterPropertyFloat('MaxKw', 22.0);
        $this->RegisterPropertyInteger('Target', 0);
        $this->RegisterPropertyInteger('SampleInterval', 5);
        $this->RegisterPropertyInteger('LiveInterval', 20);
        $this->RegisterPropertyInteger('OfflineAfter', 120);

        $this->RegisterAttributeString('IdMap', '{}');      // Schlüsselvariable → Kennung, bleibt fest
        $this->RegisterAttributeString('CPMap', '{}');      // übernommene ChargePilot-Zuordnung
        $this->RegisterAttributeString('CPScan', '{}');     // zuletzt eingelesene, noch nicht übernommene Zuordnung
        $this->RegisterAttributeString('Counters', '{}');
        $this->RegisterAttributeString('Belegt', '{}');
        $this->RegisterAttributeString('Minute', '{}');
        $this->RegisterAttributeInteger('Seq', 0);
        $this->RegisterAttributeString('LastModes', '{}');
        $this->RegisterAttributeBoolean('SendMeta', true);
        $this->RegisterAttributeString('LastResult', '');

        $this->RegisterAttributeInteger('LastLive', 0);
        $this->RegisterTimer('Sample', 0, 'EVDS_Sample($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->WriteAttributeBoolean('SendMeta', true);
        $status = $this->CheckConfig();
        $ok = $status === 102 || $status === 204;
        $this->SetTimerInterval('Sample', $ok ? max(2, $this->ReadPropertyInteger('SampleInterval')) * 1000 : 0);
        $this->SetStatus($status);
    }

    private function CheckConfig(): int
    {
        if (!preg_match('/^[A-Z0-9]{2,8}$/', $this->ReadPropertyString('SiteCode'))) {
            return 201;
        }
        $target = $this->ReadPropertyInteger('Target');
        if (!$target || !IPS_InstanceExists($target) || IPS_GetInstance($target)['ModuleInfo']['ModuleID'] !== self::EVAW_GUID) {
            return 203;
        }
        if ($this->ReadPropertyString('Source') === 'chargepilot') {
            $map = $this->ReadJson('CPMap');
            return empty($map['points']) ? 204 : 102;
        }
        $evlm = $this->ReadPropertyInteger('EVLMInstance');
        if (!$evlm || !IPS_InstanceExists($evlm) || IPS_GetInstance($evlm)['ModuleInfo']['ModuleID'] !== self::EVLM_GUID) {
            return 202;
        }
        return 102;
    }

    // ================================================================ Messen

    /** Timer: Quelle lesen, Zähler führen, Minuten-Datensätze bilden. Öffentlich: EVDS_Sample($id) */
    public function Sample()
    {
        $this->Locked(function () {
            $this->DoSample();
        });
    }

    private function DoSample()
    {
        $now = time();
        $data = $this->ReadSource($now);
        if ($data === null) {
            return;
        }
        $counters = $this->ReadJson('Counters');
        $belegt = $this->ReadJson('Belegt');
        $maxKw = $this->ReadPropertyFloat('MaxKw');
        $samples = [];
        $modes = [];
        foreach ($data['points'] as $p) {
            $c = $counters[$p['id']] ?? [];
            if ($data['source'] === 'chargepilot') {
                [$c, $note] = EVCounter::SessionStep($c, $p['offline'] ? null : $p['energyRaw'], $now, $maxKw);
            } else {
                [$c, $note] = EVCounter::MeterStep($c, $p['offline'] ? null : $p['energyRaw'], $now, max($maxKw, $p['maxKw']));
            }
            if ($note !== '' && $note !== 'keine Daten') {
                $this->SendDebug('Zähler ' . $p['id'], $note, 0);
                if (strpos($note, 'Zählerwechsel') !== false) {
                    IPS_LogMessage('EV Datensammler', $p['id'] . ': ' . $note);
                }
            }
            $counters[$p['id']] = $c;
            // offline = Zustand unbekannt: letzten bekannten Belegt-Zustand halten, sonst zählte das Ende
            // eines kurzen Ausfalls als neuer Ladevorgang
            if (!$p['offline']) {
                $belegt[$p['id']] = EVSource::IsBelegt($p['mode']);
            }
            $samples[] = ['id' => $p['id'], 'saeule' => $p['saeule'], 'kw' => $p['kw'], 'belegt' => (bool) ($belegt[$p['id']] ?? false),
                'counter' => $c['out'] ?? null];
            $modes[$p['id']] = $p['mode'];
        }
        $this->WriteJson('Counters', $counters);
        $this->WriteJson('Belegt', $belegt);

        if (!$data['hold']) {
            $seq = $this->ReadAttributeInteger('Seq');
            [$acc, $rec] = EVMinute::Add($this->ReadJson('Minute'), $now, $samples, $seq + 1);
            $this->WriteJson('Minute', $acc);
            if ($rec !== null) {
                $this->WriteAttributeInteger('Seq', $seq + 1);
                $this->Enqueue($rec);
            }
        }

        // Statuswechsel sofort melden, sonst mit dem Live-Takt
        $last = $this->ReadJson('LastModes');
        $liveDue = $now - $this->ReadAttributeInteger('LastLive') >= max(5, $this->ReadPropertyInteger('LiveInterval'));
        if ($last !== $modes || $liveDue) {
            $this->WriteJson('LastModes', $modes);
            $this->WriteAttributeInteger('LastLive', $now);
            $this->Deliver($this->LivePayload($data, $now));
        } elseif (!empty($this->ReadOutbox())) {
            $this->Deliver(null);
        }
    }

    /** Live-Stand sofort schicken. Öffentlich: EVDS_SendLive($id) */
    public function SendLive()
    {
        $this->Locked(function () {
            $now = time();
            $data = $this->ReadSource($now);
            if ($data !== null) {
                $this->Deliver($this->LivePayload($data, $now));
            }
        });
    }

    /**
     * Liest die Quelle. Rückgabe ['source','points'=>[…],'meta'=>[…],'live'=>[…],'hold'=>bool] oder null.
     * Je Ladepunkt: id, saeule, mode, mode3, kw, a, set, kwh (Sitzung), energyRaw, offline, maxKw, prio
     */
    private function ReadSource(int $now): ?array
    {
        $site = $this->ReadPropertyString('SiteCode');
        $name = $this->ReadPropertyString('SiteName') ?: $site;
        $stale = max(20, $this->ReadPropertyInteger('OfflineAfter'));

        if ($this->ReadPropertyString('Source') === 'chargepilot') {
            $map = $this->ReadJson('CPMap');
            if (empty($map['points'])) {
                return null;
            }
            $hold = false;
            $note = $name . ': ChargePilot, Werte per Modbus.';
            $count = isset($map['site']['count']) ? $this->Val($map['site']['count']) : null;
            if ($count !== null && isset($map['expected']) && (int) $count['v'] !== (int) $map['expected']) {
                // Blöcke könnten sich verschoben haben: nichts mehr aufzeichnen, bis neu eingelesen wurde
                $hold = true;
                $note = $name . ': Der ChargePilot meldet ' . (int) $count['v'] . ' statt ' . (int) $map['expected'] . ' Ladepunkte. Aufzeichnung angehalten, bitte im Datensammler neu einlesen.';
                if ($this->GetStatus() !== 205) {
                    $this->SetStatus(205);
                    IPS_LogMessage('EV Datensammler', $note);
                }
            }
            $budgetKw = $this->ReadPropertyFloat('BudgetKw') ?: null;
            foreach (['fuseMain', 'fuseSub'] as $f) {
                if (!$budgetKw && isset($map['site'][$f]) && ($v = $this->Val($map['site'][$f])) && $v['v'] > 0) {
                    $budgetKw = EVSource::AmpToKw((float) $v['v']);
                }
            }
            $pts = [];
            $saeulen = [];
            foreach ($map['points'] as $p) {
                $st = $this->Val($p['vars']['state']);
                $offline = $st === null || $now - $st['u'] > $stale;
                $pw = $this->Val($p['vars']['power']);
                $en = $this->Val($p['vars']['energy']);
                $amps = 0.0;
                foreach ($p['vars']['cur'] as $cv) {
                    $c = $cv ? $this->Val($cv) : null;
                    $amps = max($amps, $c ? (float) $c['v'] : 0.0);
                }
                $set = $p['vars']['set'] ? $this->Val($p['vars']['set']) : null;
                $mode = $offline ? 'offline' : EVSource::ModeFromChargePilot($st['v']);
                $sess = $en ? (float) $en['v'] : null;
                $pts[] = [
                    'id' => $p['id'], 'saeule' => $p['saeule'], 'saeuleName' => $p['saeuleName'], 'name' => $p['name'],
                    'mode' => $mode, 'mode3' => $st === null ? '' : (string) $st['v'], 'kw' => $offline || !$pw ? 0.0 : EVSource::PowerKw($pw['v']),
                    'a' => round($amps, 1), 'set' => $set ? round((float) $set['v'], 1) : null,
                    'kwh' => $sess === null || $sess < 0 || $sess >= EVCounter::NO_SESSION ? 0.0 : round($sess / 1000, 2),
                    'energyRaw' => $sess, 'offline' => $offline, 'maxKw' => (float) $p['maxKw'], 'prio' => null
                ];
                $saeulen[$p['saeule']] = $saeulen[$p['saeule']] ?? ['name' => $p['saeuleName'], 'limitKw' => null];
            }
            return ['source' => 'chargepilot', 'points' => $pts, 'hold' => $hold,
                'meta' => $this->Meta($name, 'ChargePilot', $budgetKw, $note, $saeulen, $pts),
                'live' => ['active' => true, 'budgetKw' => $budgetKw, 'usedA' => null]];
        }

        $evlm = $this->ReadPropertyInteger('EVLMInstance');
        if (!$evlm || !IPS_InstanceExists($evlm)) {
            return null;
        }
        $groups = json_decode((string) IPS_GetProperty($evlm, 'Groups'), true) ?: [];
        $cps = json_decode((string) IPS_GetProperty($evlm, 'ChargePoints'), true) ?: [];
        $idMap = $this->ReadJson('IdMap');
        [$points, $saeulen, $budgetKw, $newMap] = EVSource::FromEVLM($site, $groups, $cps, (float) IPS_GetProperty($evlm, 'BudgetA'), $idMap);
        if ($newMap !== $idMap) {
            $this->WriteJson('IdMap', $newMap);
        }
        $pts = [];
        foreach ($points as $p) {
            $st = $p['vars']['state'] ? $this->Val($p['vars']['state']) : null;
            $amps = 0.0;
            $sum = 0.0;
            $upd = $st['u'] ?? 0;
            foreach ($p['vars']['cur'] as $cv) {
                $c = $cv ? $this->Val($cv) : null;
                if ($c) {
                    $amps = max($amps, (float) $c['v']);
                    $sum += (float) $c['v'];
                    $upd = max($upd, $c['u']);
                }
            }
            $offline = $upd === 0 || $now - $upd > $stale;
            $mode3 = $st === null ? '' : (string) $st['v'];
            $en = $p['vars']['energy'] ? $this->Val($p['vars']['energy']) : null;
            $set = $p['vars']['set'] ? $this->Val($p['vars']['set']) : null;
            $kwhVar = @IPS_GetObjectIDByIdent('CP' . $p['pos'] . '_Energy', $evlm);
            $prioVar = @IPS_GetObjectIDByIdent('CP' . $p['pos'] . '_PrioSession', $evlm);
            $perm = (bool) ($cps[$p['pos']]['Prio'] ?? false);
            $pts[] = [
                'id' => $p['id'], 'saeule' => $p['saeule'], 'saeuleName' => $p['saeuleName'], 'name' => $p['name'],
                'mode' => $offline ? 'offline' : EVSource::ModeFromMode3($mode3, $p['vendor'], $amps), 'mode3' => $mode3,
                'kw' => $offline ? 0.0 : round($sum * EVSource::VOLT / 1000, 2), 'a' => round($amps, 1),
                'set' => $set ? round((float) $set['v'], 1) : null,
                'kwh' => $kwhVar ? round((float) GetValue($kwhVar), 2) : null,
                'energyRaw' => $en ? (float) $en['v'] : null, 'offline' => $offline, 'maxKw' => $p['maxKw'],
                'prio' => $perm ? 'dauer' : ($prioVar && GetValue($prioVar) ? 'sitzung' : null)
            ];
        }
        $usedVar = @IPS_GetObjectIDByIdent('Used', $evlm);
        return ['source' => 'evlm', 'points' => $pts, 'hold' => false,
            'meta' => $this->Meta($name, 'EVLM', $budgetKw, null, $saeulen, $pts),
            'live' => ['active' => (bool) IPS_GetProperty($evlm, 'Active'), 'budgetKw' => $budgetKw,
                'usedA' => $usedVar ? round((float) GetValue($usedVar), 1) : null, 'budgetA' => (float) IPS_GetProperty($evlm, 'BudgetA')]];
    }

    /** Wert und letzte Aktualisierung einer Variable, null wenn es sie nicht gibt */
    private function Val(int $var): ?array
    {
        if ($var <= 0 || !IPS_VariableExists($var)) {
            return null;
        }
        return ['v' => GetValue($var), 'u' => (int) IPS_GetVariable($var)['VariableUpdated']];
    }

    private function Meta(string $name, string $label, $budgetKw, $note, array $saeulen, array $pts): array
    {
        $out = ['name' => $name, 'sourceLabel' => $label, 'budgetKw' => $budgetKw, 'note' => $note, 'saeulen' => []];
        foreach ($saeulen as $sid => $sa) {
            $o = ['id' => $sid, 'name' => $sa['name'], 'limitKw' => $sa['limitKw'], 'points' => []];
            foreach ($pts as $p) {
                if ($p['saeule'] === $sid) {
                    $o['points'][] = ['id' => $p['id'], 'name' => $p['name']];
                }
            }
            $out['saeulen'][] = $o;
        }
        return $out;
    }

    private function LivePayload(array $data, int $now): array
    {
        $site = 0.0;
        $pts = [];
        foreach ($data['points'] as $p) {
            $site += $p['kw'];
            $pts[] = ['id' => $p['id'], 'mode' => $p['mode'], 'mode3' => $p['mode3'], 'kw' => round($p['kw'], 2), 'a' => $p['a'],
                'set' => $p['set'], 'kwh' => $p['kwh'], 'prio' => $p['prio']];
        }
        return ['data' => $data, 'live' => $data['live'] + ['t' => $now, 'power' => round($site, 2), 'points' => $pts]];
    }

    // ================================================================ Liefern

    private function Enqueue(array $rec)
    {
        $box = $this->ReadOutbox();
        $box[] = $rec;
        if (count($box) > self::MAX_OUTBOX) {
            $drop = count($box) - self::MAX_OUTBOX;
            $box = array_slice($box, $drop);
            IPS_LogMessage('EV Datensammler', $drop . ' alte Minuten-Datensätze verworfen, der Puffer ist voll (30 Tage).');
        }
        $this->WriteOutbox($box);
    }

    /** Schickt Live-Stand und unbestätigte Datensätze an die Auswertung; bestätigte werden aus dem Puffer gelöscht. */
    private function Deliver(?array $live): bool
    {
        $data = $live['data'] ?? null;
        if ($data === null) {
            $data = $this->ReadSource(time());
            if ($data === null) {
                return false;
            }
        }
        $box = $this->ReadOutbox();
        $meta = $data['meta'];
        $hash = md5(json_encode($meta));
        $payload = [
            'v' => 1, 'site' => $this->ReadPropertyString('SiteCode'), 'metaHash' => $hash,
            'meta' => $this->ReadAttributeBoolean('SendMeta') ? $meta : null,
            'live' => $live['live'] ?? null, 'rec' => array_slice($box, 0, self::MAX_REC)
        ];
        $ans = $this->Send($payload);
        if ($ans !== null && !empty($ans['needMeta']) && $payload['meta'] === null) {
            $payload['meta'] = $meta;
            $ans = $this->Send($payload);
        }
        if ($ans === null) {
            return false;
        }
        $this->WriteAttributeBoolean('SendMeta', false);
        $ack = (int) ($ans['ack'] ?? 0);
        if ($box) {
            $keep = array_values(array_filter($box, function ($r) use ($ack) {
                return (int) $r['seq'] > $ack;
            }));
            if (count($keep) !== count($box)) {
                $this->WriteOutbox($keep);
            }
        }
        return true;
    }

    /** Etappe 1: Auswertung auf demselben Symcon */
    private function Send(array $payload): ?array
    {
        $target = $this->ReadPropertyInteger('Target');
        if (!$target || !IPS_InstanceExists($target) || !function_exists('EVAW_Ingest')) {
            $this->WriteAttributeString('LastResult', date('d.m. H:i:s') . ': Auswertung nicht gefunden');
            return null;
        }
        $res = json_decode((string) EVAW_Ingest($target, json_encode($payload)), true);
        if (!is_array($res) || isset($res['error'])) {
            $this->WriteAttributeString('LastResult', date('d.m. H:i:s') . ': ' . ($res['error'] ?? 'keine Antwort'));
            return null;
        }
        $this->WriteAttributeString('LastResult', date('d.m. H:i:s') . ': geliefert, bestätigt bis ' . (int) $res['ack']);
        return $res;
    }

    private function OutboxFile(): string
    {
        $dir = IPS_GetKernelDir() . 'EVDataCollector' . DIRECTORY_SEPARATOR;
        if (!is_dir($dir)) {
            @mkdir($dir);
        }
        return $dir . $this->InstanceID . '_outbox.json';
    }

    private function ReadOutbox(): array
    {
        $f = $this->OutboxFile();
        $v = is_file($f) ? json_decode((string) @file_get_contents($f), true) : [];
        return is_array($v) ? $v : [];
    }

    private function WriteOutbox(array $box)
    {
        @file_put_contents($this->OutboxFile(), json_encode($box), LOCK_EX);
    }

    // ================================================================ Knöpfe im Formular

    /** ChargePilot: Ordner nach ModBus-Geräten durchsuchen und Vorschau zeigen. Öffentlich: EVDS_ScanFolder($id) */
    public function ScanFolder()
    {
        $folder = $this->ReadPropertyInteger('CPFolder');
        if (!$folder || !IPS_ObjectExists($folder)) {
            echo 'Bitte zuerst den Ordner mit den ModBus-Geräten auswählen und übernehmen.';
            return;
        }
        $devices = [];
        $this->CollectDevices($folder, $devices);
        $scan = EVSource::ScanChargePilot($this->ReadPropertyString('SiteCode'), $devices, $this->ReadJson('IdMap'));
        $count = isset($scan['site']['count']) ? $this->Val($scan['site']['count']) : null;
        $scan['expected'] = $count ? (int) $count['v'] : null;
        $this->WriteJson('CPScan', $scan);

        $lines = ['Gefunden: ' . count($devices) . ' ModBus-Geräte, ' . count($scan['points']) . ' vollständige Ladepunkte.'];
        foreach ($scan['points'] as $p) {
            $have = array_keys(array_filter(['Strom' => $p['vars']['cur'][0], 'Soll' => $p['vars']['set']]));
            $lines[] = '  ' . $p['id'] . '  ' . $p['saeuleName'] . ' / ' . $p['name'] . '  (Status, Leistung, Energie' . ($have ? ', ' . implode(', ', $have) : '') . ')';
        }
        if ($scan['expected'] !== null) {
            $lines[] = 'Der ChargePilot meldet ' . $scan['expected'] . ' Ladepunkte.' . ($scan['expected'] !== $scan['blocks'] ? ' Im Ordner sind ' . $scan['blocks'] . ' angelegt.' : '');
        }
        foreach ($scan['issues'] as $i) {
            $lines[] = 'Hinweis: ' . $i;
        }
        $lines[] = 'Mit „Zuordnung übernehmen“ gilt diese Liste.';
        echo implode("\n", $lines);
    }

    /** Öffentlich: EVDS_ApplyScan($id) */
    public function ApplyScan()
    {
        $this->Locked(function () {
            $this->DoApplyScan();
        });
    }

    private function DoApplyScan()
    {
        $scan = $this->ReadJson('CPScan');
        if (empty($scan['points'])) {
            echo 'Es gibt keine eingelesene Zuordnung. Bitte zuerst „Ordner einlesen“.';
            return;
        }
        $this->WriteJson('IdMap', $scan['idMap']);
        $this->WriteJson('CPMap', ['points' => $scan['points'], 'site' => $scan['site'], 'expected' => $scan['expected']]);
        $this->WriteAttributeBoolean('SendMeta', true);
        IPS_ApplyChanges($this->InstanceID);
        echo count($scan['points']) . ' Ladepunkte übernommen.';
    }

    private function CollectDevices(int $parent, array &$out, int $depth = 0)
    {
        if ($depth > 6) {
            return;
        }
        $offset = $this->ReadPropertyInteger('CPAddressOffset');
        foreach (IPS_GetChildrenIDs($parent) as $id) {
            $obj = IPS_GetObject($id);
            $guid = $obj['ObjectType'] === 1 ? IPS_GetInstance($id)['ModuleInfo']['ModuleID'] : '';
            if ($guid === self::MODBUS_ADDRESS_GUID || $guid === self::MODBUS_DEVICE_GUID) {
                // Säule und Ladepunkt aus den beiden Ordnern über der Instanz
                $lpId = $obj['ParentID'];
                $lp = IPS_GetName($lpId);
                $sa = IPS_ObjectExists(IPS_GetParent($lpId)) ? IPS_GetName(IPS_GetParent($lpId)) : '';
                foreach ($this->ModbusVariables($id, $guid) as [$addr, $var]) {
                    $out[] = ['var' => $var, 'addr' => $addr + $offset, 'lp' => $lp, 'saeule' => $sa, 'path' => IPS_GetLocation($id) . ' @' . $addr];
                }
            } elseif ($obj['HasChildren']) {
                $this->CollectDevices($id, $out, $depth + 1);
            }
        }
    }

    /**
     * Leseadressen und Variablen einer ModBus-Instanz.
     * ModBus Address: Eigenschaft ReadAddress, eine Variable darunter.
     * ModBus Device: Liste "Addresses"; je Eintrag eine Variable mit dem Ident des Eintrags
     * bzw. dem Standard-Ident A_<DataType>_<ReadFunctionCode>_<ReadAddress>.
     * @return array [[Adresse, Variable], …]
     */
    private function ModbusVariables(int $id, string $guid): array
    {
        $cfg = json_decode(IPS_GetConfiguration($id), true);
        $out = [];
        if ($guid === self::MODBUS_ADDRESS_GUID) {
            if (!isset($cfg['ReadAddress']) || (int) ($cfg['ReadFunctionCode'] ?? 1) === 0) {
                return [];
            }
            foreach (IPS_GetChildrenIDs($id) as $c) {
                if (IPS_VariableExists($c)) {
                    $out[] = [(int) $cfg['ReadAddress'], $c];
                    break;
                }
            }
            return $out;
        }
        $list = json_decode((string) ($cfg['Addresses'] ?? '[]'), true);
        foreach (is_array($list) ? $list : [] as $a) {
            if (isset($a['Active']) && !$a['Active']) {
                continue;
            }
            $ident = (string) ($a['Ident'] ?? '');
            if ($ident === '') {
                $ident = 'A_' . (int) ($a['DataType'] ?? 0) . '_' . (int) ($a['ReadFunctionCode'] ?? 0) . '_' . (int) ($a['ReadAddress'] ?? 0);
            }
            $var = @IPS_GetObjectIDByIdent($ident, $id);
            if ($var !== false && isset($a['ReadAddress'])) {
                $out[] = [(int) $a['ReadAddress'], (int) $var];
            }
        }
        return $out;
    }

    /** Alte Zählerhistorie aus dem Archiv an die Auswertung übergeben (nur EVLM). Öffentlich: EVDS_ImportHistory($id) */
    public function ImportHistory()
    {
        $this->Locked(function () {
            $this->DoImportHistory();
        });
    }

    private function DoImportHistory()
    {
        if ($this->ReadPropertyString('Source') !== 'evlm') {
            echo 'Der ChargePilot liefert keinen Zählerstand, deshalb gibt es keine alte Historie zum Übernehmen.';
            return;
        }
        $ac = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0] ?? 0;
        $data = $this->ReadSource(time());
        if (!$ac || $data === null) {
            echo 'Archiv oder EVLM-Instanz nicht gefunden.';
            return;
        }
        $evlm = $this->ReadPropertyInteger('EVLMInstance');
        $cps = json_decode((string) IPS_GetProperty($evlm, 'ChargePoints'), true) ?: [];
        [$points] = EVSource::FromEVLM($this->ReadPropertyString('SiteCode'), json_decode((string) IPS_GetProperty($evlm, 'Groups'), true) ?: [], $cps, (float) IPS_GetProperty($evlm, 'BudgetA'), $this->ReadJson('IdMap'));
        $hist = ['points' => []];
        $lines = [];
        foreach ($points as $p) {
            $var = $p['vars']['energy'];
            if (!$var || !AC_GetLoggingStatus($ac, $var)) {
                $lines[] = $p['id'] . ': Zählervariable wird nicht archiviert, nichts zu übernehmen.';
                continue;
            }
            $rows = [];
            $end = time();
            for ($guard = 0; $guard < 500; $guard++) {
                $chunk = @AC_GetLoggedValues($ac, $var, 0, $end, 10000);
                if (!is_array($chunk) || !$chunk) {
                    break;
                }
                $min = PHP_INT_MAX;
                foreach ($chunk as $r) {
                    $rows[] = [(int) $r['TimeStamp'], (float) $r['Value']];
                    $min = min($min, (int) $r['TimeStamp']);
                }
                if (count($chunk) < 10000) {
                    break;
                }
                $end = $min - 1;
            }
            usort($rows, function ($a, $b) {
                return $a[0] <=> $b[0];
            });
            // gleicher Filter wie im Betrieb; höchstens ein Wert je 15 Minuten
            $st = [];
            $out = [];
            $rejected = 0;
            $swaps = 0;
            $lastT = 0;
            foreach ($rows as [$t, $v]) {
                [$st, $note] = EVCounter::MeterStep($st, $v, $t, max($this->ReadPropertyFloat('MaxKw'), $p['maxKw']));
                if ($note !== '') {
                    strpos($note, 'Zählerwechsel') !== false ? $swaps++ : $rejected++;
                    continue;
                }
                if ($t - $lastT >= 900) {
                    $out[] = [$t, round($st['out'], 3)];
                    $lastT = $t;
                }
            }
            $hist['points'][$p['id']] = $out;
            $lines[] = $p['id'] . ': ' . count($out) . ' Werte' . ($out ? ' ab ' . date('d.m.Y', $out[0][0]) : '') . ($rejected ? ', ' . $rejected . ' Ausreißer verworfen' : '') . '.';
            if ($swaps) {
                $lines[] = '  Achtung: In der Historie wurde ein Zählertausch erkannt. Der Übergang zur laufenden Zählung kann einen Sprung haben, bitte in der Auswertung prüfen.';
            }
        }
        $payload = ['v' => 1, 'site' => $this->ReadPropertyString('SiteCode'), 'metaHash' => md5(json_encode($data['meta'])),
            'meta' => $data['meta'], 'live' => null, 'rec' => [], 'hist' => $hist];
        $ans = $this->Send($payload);
        $lines[] = $ans === null ? 'Die Auswertung hat die Historie nicht angenommen.' : 'Übergeben. Die Auswertung aggregiert die Werte in den nächsten Minuten neu.';
        echo implode("\n", $lines);
    }

    /** Öffentlich: EVDS_TestDelivery($id) */
    public function TestDelivery()
    {
        $this->Locked(function () {
            $this->DoTestDelivery();
        });
    }

    private function DoTestDelivery()
    {
        $data = $this->ReadSource(time());
        if ($data === null) {
            echo 'Die Quelle liefert keine Ladepunkte. Bitte Einstellungen prüfen.';
            return;
        }
        $this->WriteAttributeBoolean('SendMeta', true);
        $ok = $this->Deliver($this->LivePayload($data, time()));
        echo ($ok ? 'Lieferung angenommen. ' : 'Lieferung fehlgeschlagen. ') . $this->ReadAttributeString('LastResult') . "\n"
            . count($data['points']) . ' Ladepunkte, Puffer: ' . count($this->ReadOutbox()) . ' Datensätze.';
    }

    // ================================================================ Formular

    public function GetConfigurationForm()
    {
        $form = json_decode(@file_get_contents(__DIR__ . '/form.json'), true);
        if (!is_array($form)) {
            return '{"elements":[]}';
        }
        $isCp = $this->ReadPropertyString('Source') === 'chargepilot';
        $rows = [];
        if ($isCp) {
            $map = $this->ReadJson('CPMap');
            foreach ($map['points'] ?? [] as $p) {
                $rows[] = ['Id' => $p['id'], 'Saeule' => $p['saeuleName'], 'Name' => $p['name']];
            }
        } else {
            $data = $this->ReadSource(time());
            foreach ($data['points'] ?? [] as $p) {
                $rows[] = ['Id' => $p['id'], 'Saeule' => $p['saeuleName'], 'Name' => $p['name']];
            }
        }
        $walk = function (array &$items) use (&$walk, $isCp, $rows) {
            foreach ($items as &$el) {
                $n = $el['name'] ?? '';
                if (in_array($n, ['EVLMInstance'], true)) {
                    $el['visible'] = !$isCp;
                }
                if (in_array($n, ['CPFolder', 'CPAddressOffset', 'BudgetKw', 'BtnScan', 'BtnApply'], true)) {
                    $el['visible'] = $isCp;
                }
                if ($n === 'BtnHistory') {
                    $el['visible'] = !$isCp;
                }
                if ($n === 'PointList') {
                    $el['values'] = $rows;
                }
                if ($n === 'LastResult') {
                    $el['caption'] = 'Letzte Lieferung: ' . ($this->ReadAttributeString('LastResult') ?: '–');
                }
                if (isset($el['items']) && is_array($el['items'])) {
                    $walk($el['items']);
                }
            }
            unset($el);
        };
        $walk($form['elements']);
        $walk($form['actions']);
        return json_encode($form);
    }

    // ================================================================ Hilfen

    /** Timer und Knöpfe laufen in Symcon parallel; Puffer und Zähler darf immer nur ein Lauf schreiben. */
    private function Locked(callable $fn)
    {
        $name = 'EVDS_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($name, 10000)) {
            $this->SendDebug('Sperre', 'noch belegt, Lauf übersprungen', 0);
            return null;
        }
        try {
            return $fn();
        } finally {
            IPS_SemaphoreLeave($name);
        }
    }

    private function ReadJson(string $name): array
    {
        $v = json_decode($this->ReadAttributeString($name), true);
        return is_array($v) ? $v : [];
    }

    private function WriteJson(string $name, array $v)
    {
        $this->WriteAttributeString($name, json_encode($v));
    }
}
