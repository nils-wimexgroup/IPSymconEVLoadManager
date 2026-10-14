<?php

require_once __DIR__ . '/lib/EVPeriod.php';
require_once __DIR__ . '/lib/EVStats.php';
require_once __DIR__ . '/lib/EVReport.php';
require_once __DIR__ . '/lib/EVArchiveReader.php';

/**
 * EV Auswertung - IP-Symcon Modul
 * -------------------------------
 * Nimmt Minuten-Datensaetze und Live-Staende der EV Datensammler an (lokal per EVAW_Ingest,
 * ab Etappe 2 auch per WebHook), schreibt sie in eigene Archiv-Variablen je Standort und
 * Ladepunkt und zeigt sie in einer HTML-SDK-Kachel aus: Energie, Ladevorgaenge, Auslastung
 * (belegt und davon aktiv geladen), Heatmap, Leistungsspitzen und CSV-Export.
 */
class EVAnalytics extends IPSModule
{
    private const ARCHIVE_GUID = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';
    private const HOOK = '/hook/evauswertung';
    private const LATE = 180;          // aelter als 3 min = nachgereicht, mit echtem Zeitstempel ins Archiv
    private const CSV_TTL = 300;       // Gueltigkeit eines CSV-Links in Sekunden

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('BizFrom', 7);
        $this->RegisterPropertyInteger('BizTo', 18);
        $this->RegisterPropertyInteger('OfflineAfter', 60);

        $this->RegisterAttributeString('Sites', '{}');       // Struktur je Standort inkl. Variablen-IDs
        $this->RegisterAttributeString('SiteState', '{}');   // je Standort: lastSeq, metaHash, Zeitpunkt der letzten Lieferung
        $this->RegisterAttributeString('Live', '{}');        // letzter Live-Stand je Standort
        $this->RegisterAttributeString('Reagg', '[]');       // Variablen, die neu aggregiert werden muessen
        $this->RegisterAttributeString('CsvTokens', '{}');

        $this->RegisterProfiles();
        $this->RegisterMessage(0, IPS_KERNELSTARTED);
        $this->RegisterTimer('Housekeeping', 60 * 1000, 'EVAW_Housekeeping($_IPS[\'TARGET\']);');

        $this->SetVisualizationType(self::VisuType());
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetVisualizationType(self::VisuType());
        if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->RegisterCsvHook();
            $this->UpgradeSites();
            $this->CheckCounters(false);
        }
        $this->SetStatus(102);
    }

    /**
     * Zählervariablen: Nullwerte und negative Werte aus der Aggregation nehmen und die Variable neu aggregieren.
     * Ohne $force nur Variablen, denen die Einstellung noch fehlt. Öffentlich: EVAW_RepairCounters($id)
     */
    public function RepairCounters()
    {
        $n = $this->CheckCounters(true);
        echo $n . ' Zählervariablen werden neu aggregiert, höchstens zwei je Minute. Danach stimmen die Tageswerte wieder.';
    }

    private function CheckCounters(bool $force): int
    {
        $ac = $this->ArchiveID();
        if (!$ac) {
            return 0;
        }
        $todo = $this->ReadJson('Reagg');
        $n = 0;
        foreach ($this->ReadJson('Sites') as $site) {
            foreach ($site['saeulen'] ?? [] as $sa) {
                foreach ($sa['points'] ?? [] as $p) {
                    $var = (int) ($p['varE'] ?? 0);
                    if (!$var || !IPS_VariableExists($var)) {
                        continue;
                    }
                    $missing = !AC_GetCounterIgnoreZeros($ac, $var);
                    if ($missing) {
                        AC_SetCounterIgnoreZeros($ac, $var, true);
                    }
                    if ($missing || $force) {
                        $todo[(string) $var] = 0;   // sofort beim nächsten Aufräumen
                        $n++;
                    }
                }
            }
        }
        if ($n) {
            IPS_ApplyChanges($ac);
            $this->WriteJson('Reagg', $todo);
        }
        return $n;
    }

    /** Formular mit der Liste der angeschlossenen Standorte */
    public function GetConfigurationForm()
    {
        $form = json_decode(@file_get_contents(__DIR__ . '/form.json'), true);
        if (!is_array($form)) {
            return '{"elements":[]}';
        }
        $sites = $this->ReadJson('Sites');
        $state = $this->ReadJson('SiteState');
        $values = [];
        foreach ($sites as $code => $s) {
            $n = 0;
            foreach ($s['saeulen'] ?? [] as $sa) {
                $n += count($sa['points'] ?? []);
            }
            $seen = (int) ($state[$code]['seen'] ?? 0);
            $values[] = [
                'Code' => (string) $code, 'Name' => (string) ($s['name'] ?? $code), 'Source' => (string) ($s['sourceLabel'] ?? ''),
                'Points' => $n, 'Seen' => $seen ? date('d.m.Y H:i:s', $seen) : '–', 'Seq' => (int) ($state[$code]['lastSeq'] ?? 0)
            ];
        }
        foreach ($form['actions'] as &$a) {
            if (($a['name'] ?? '') === 'SiteList') {
                $a['values'] = $values;
            }
        }
        unset($a);
        return json_encode($form);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message == IPS_KERNELSTARTED) {
            $this->RegisterCsvHook();
            $this->UpgradeSites();
        }
    }

    // ================================================================ Annahme der Daten

    /**
     * Nimmt eine Lieferung eines Datensammlers an. Oeffentlich: EVAW_Ingest($id, $json)
     * $json = {"v":1,"site":"ADF","meta":{…}|null,"metaHash":"…","live":{…}|null,"rec":[…],"hist":{…}|null}
     * Rueckgabe JSON {"ack": hoechste gespeicherte Folgenummer, "needMeta": bool, "error"?: Text}
     */
    public function Ingest(string $json)
    {
        $r = $this->Locked(function () use ($json) {
            return $this->DoIngest($json);
        });
        return $r ?? json_encode(['ack' => 0, 'needMeta' => false, 'error' => 'Auswertung beschäftigt, nächster Versuch folgt']);
    }

    private function DoIngest(string $json)
    {
        $in = json_decode($json, true);
        if (!is_array($in) || !preg_match('/^[A-Z0-9]{2,8}$/', (string) ($in['site'] ?? ''))) {
            return json_encode(['ack' => 0, 'needMeta' => false, 'error' => 'Ungültige Lieferung']);
        }
        $site = $in['site'];
        $state = $this->ReadJson('SiteState');
        $st = $state[$site] ?? ['lastSeq' => 0, 'metaHash' => '', 'seen' => 0];
        $sites = $this->ReadJson('Sites');

        if (!empty($in['meta']) && is_array($in['meta'])) {
            $sites[$site] = $this->EnsureSite($site, $in['meta'], $sites[$site] ?? []);
            $st['metaHash'] = (string) ($in['metaHash'] ?? '');
            $this->WriteJson('Sites', $sites);
        }
        if (!isset($sites[$site]) || ($in['metaHash'] ?? '') !== $st['metaHash']) {
            // Struktur unbekannt oder geändert: nichts schreiben, Struktur anfordern
            $state[$site] = $st;
            $this->WriteJson('SiteState', $state);
            return json_encode(['ack' => (int) $st['lastSeq'], 'needMeta' => true]);
        }

        $now = time();
        $late = [];
        foreach ($in['rec'] ?? [] as $r) {
            $seq = (int) ($r['seq'] ?? 0);
            if ($seq <= (int) $st['lastSeq']) {
                continue;   // schon gespeichert
            }
            $this->WriteRecord($sites[$site], $r, $now, $late);
            $st['lastSeq'] = $seq;
        }
        if (!empty($in['hist']) && is_array($in['hist'])) {
            $this->WriteHistory($site, $sites, $in['hist'], $late);
        }
        if ($late) {
            $this->AddLate($late);
        }
        if (!empty($in['live']) && is_array($in['live'])) {
            $live = $this->ReadJson('Live');
            $live[$site] = $in['live'] + ['received' => $now];
            $this->WriteJson('Live', $live);
        }
        $st['seen'] = $now;
        $state[$site] = $st;
        $this->WriteJson('SiteState', $state);
        return json_encode(['ack' => (int) $st['lastSeq'], 'needMeta' => false]);
    }

    /** Legt Kategorie und Archiv-Variablen eines Standorts an bzw. ergänzt sie */
    private function EnsureSite(string $site, array $meta, array $old): array
    {
        $cat = $this->EnsureCategory($this->InstanceID, 'S_' . $site, (string) ($meta['name'] ?? $site), 10);
        $name = (string) ($meta['name'] ?? $site);
        $out = [
            'name' => $name, 'sourceLabel' => (string) ($meta['sourceLabel'] ?? ''), 'note' => $meta['note'] ?? null,
            'budgetKw' => isset($meta['budgetKw']) ? (float) $meta['budgetKw'] : null,
            'histSince' => (int) ($old['histSince'] ?? time()), 'detailSince' => (int) ($old['detailSince'] ?? time()),
            'varP' => $this->EnsureVar($cat, $this->Ident($site) . '_P', 2, 'Leistung Standort', 'EVAW.kW', 0, 1),
            'saeulen' => []
        ];
        // Angaben, die nur die Auswertung kennt (z. B. Beginn der übernommenen Historie), bleiben erhalten
        $oldPoints = [];
        foreach ($old['saeulen'] ?? [] as $os) {
            foreach ($os['points'] ?? [] as $op) {
                $oldPoints[$op['id']] = $op;
            }
        }
        $pos = 10;
        foreach ($meta['saeulen'] ?? [] as $sa) {
            $sid = (string) $sa['id'];
            $o = ['id' => $sid, 'name' => (string) ($sa['name'] ?? $sid), 'limitKw' => isset($sa['limitKw']) ? (float) $sa['limitKw'] : null,
                'varP' => $this->EnsureVar($cat, $this->Ident($sid) . '_P', 2, $sa['name'] . ' · Leistung Säule', 'EVAW.kW', 0, $pos++), 'points' => []];
            foreach ($sa['points'] ?? [] as $p) {
                $pid = (string) $p['id'];
                $label = $sa['name'] . ' · ' . $p['name'];
                $identL = $this->Ident($pid) . '_L';
                $newL = @IPS_GetObjectIDByIdent($identL, $cat) === false;
                $pt = [
                    'id' => $pid, 'name' => (string) $p['name'],
                    'varE' => $this->EnsureVar($cat, $this->Ident($pid) . '_E', 2, $label . ' · Zähler (geprüft)', 'EVAW.kWh', 1, $pos++),
                    'varB' => $this->EnsureVar($cat, $this->Ident($pid) . '_B', 0, $label . ' · Belegt', '~Switch', 0, $pos++),
                    'varP' => $this->EnsureVar($cat, $this->Ident($pid) . '_P', 2, $label . ' · Leistung', 'EVAW.kW', 0, $pos++),
                    'varL' => $this->EnsureVar($cat, $identL, 0, $label . ' · Lädt aktiv', '~Switch', 0, $pos++)
                ] + (isset($oldPoints[$pid]['histFrom']) ? ['histFrom' => (int) $oldPoints[$pid]['histFrom']] : []);
                // Ladepunkt mit Daten aus der Zeit vor "Lädt aktiv": beim Aufräumen einmalig nachrechnen (FillCharging)
                if ($newL && isset($oldPoints[$pid])) {
                    $pt['lFill'] = time();
                } elseif (isset($oldPoints[$pid]['lFill'])) {
                    $pt['lFill'] = (int) $oldPoints[$pid]['lFill'];
                }
                $o['points'][] = $pt;
            }
            $out['saeulen'][] = $o;
        }
        return $out;
    }

    /** Schreibt einen Minuten-Datensatz. Frisch: SetValue. Nachgereicht: wird für AC_AddLoggedValues gesammelt. */
    private function WriteRecord(array $site, array $r, int $now, array &$late)
    {
        $t = (int) ($r['t'] ?? $now);
        $fresh = $now - $t <= self::LATE;
        $put = function (int $var, $value) use ($fresh, $t, &$late) {
            if ($var <= 0 || $value === null) {
                return;
            }
            if ($fresh) {
                SetValue($var, $value);
            } else {
                $late[$var][] = ['TimeStamp' => $t, 'Value' => $value];
            }
        };
        foreach ($site['saeulen'] as $sa) {
            foreach ($sa['points'] as $p) {
                $v = $r['p'][$p['id']] ?? null;
                if (!is_array($v)) {
                    continue;
                }
                $put($p['varE'], $v[0] === null ? null : (float) $v[0]);
                $put($p['varB'], (bool) $v[1]);
                $put($p['varP'], (float) $v[2]);
                // ältere Datensammler liefern kein "lädt aktiv": dann aus Belegt und Leistung
                $put($p['varL'] ?? 0, isset($v[3]) ? (bool) $v[3] : ((bool) $v[1] && (float) $v[2] >= EVStats::LADEN_KW));
            }
            if (isset($r['s'][$sa['id']])) {
                $put($sa['varP'], (float) $r['s'][$sa['id']]);
            }
        }
        if (isset($r['site'])) {
            $put($site['varP'], (float) $r['site']);
        }
    }

    /**
     * Alte Zählerhistorie: {"points": {Kennung: [[t, kWh], …]}}.
     * AC_AddLoggedValues prüft nicht auf Dubletten. Übernommen wird deshalb nur, was vor dem Beginn der
     * eigenen Aufzeichnung und vor einer früheren Übernahme liegt. Ein zweiter Lauf verdoppelt nichts.
     */
    private function WriteHistory(string $site, array &$sites, array $hist, array &$late)
    {
        $first = PHP_INT_MAX;
        foreach ($sites[$site]['saeulen'] as &$sa) {
            foreach ($sa['points'] as &$p) {
                $limit = min((int) $sites[$site]['detailSince'], (int) ($p['histFrom'] ?? PHP_INT_MAX));
                $min = PHP_INT_MAX;
                foreach ($hist['points'][$p['id']] ?? [] as $row) {
                    $t = (int) $row[0];
                    if ($t >= $limit) {
                        continue;
                    }
                    $late[$p['varE']][] = ['TimeStamp' => $t, 'Value' => (float) $row[1]];
                    $min = min($min, $t);
                }
                if ($min < PHP_INT_MAX) {
                    $p['histFrom'] = $min;
                    $first = min($first, $min);
                }
            }
            unset($p);
        }
        unset($sa);
        if ($first < (int) $sites[$site]['histSince']) {
            $sites[$site]['histSince'] = $first;
        }
        $this->WriteJson('Sites', $sites);
    }

    private function AddLate(array $late)
    {
        $ac = $this->ArchiveID();
        $todo = $this->ReadJson('Reagg');
        foreach ($late as $var => $rows) {
            usort($rows, function ($a, $b) {
                return $a['TimeStamp'] <=> $b['TimeStamp'];
            });
            foreach (array_chunk($rows, 1000) as $chunk) {
                AC_AddLoggedValues($ac, (int) $var, $chunk);
            }
            $todo[(string) $var] = time();
        }
        $this->WriteJson('Reagg', $todo);
    }

    /** Minütlich: nachgereichte Variablen neu aggregieren, abgelaufene CSV-Links löschen. Öffentlich: EVAW_Housekeeping($id) */
    public function Housekeeping()
    {
        $this->Locked(function () {
            $this->DoHousekeeping();
        });
    }

    private function DoHousekeeping()
    {
        $this->FillCharging();
        $todo = $this->ReadJson('Reagg');
        $ac = $this->ArchiveID();
        $keep = [];
        $started = 0;
        foreach ($todo as $var => $since) {
            if (!IPS_VariableExists((int) $var)) {
                continue;
            }
            // erst aggregieren, wenn 2 Minuten nichts mehr nachkam (Nachholen läuft in Paketen),
            // und höchstens zwei Variablen je Minute, weil die Neuaggregation im Hintergrund läuft
            if (time() - (int) $since >= 120 && $started < 2) {
                AC_ReAggregateVariable($ac, (int) $var);
                $started++;
            } else {
                $keep[$var] = $since;
            }
        }
        $this->WriteJson('Reagg', $keep);

        $tokens = $this->ReadJson('CsvTokens');
        foreach ($tokens as $tk => $info) {
            if (($info['exp'] ?? 0) < time()) {
                @unlink($this->CsvFile($tk));
                unset($tokens[$tk]);
            }
        }
        $this->WriteJson('CsvTokens', $tokens);
    }

    /** Bestand auf den aktuellen Aufbau bringen, z. B. Variable "Lädt aktiv" für Standorte von vor Version 1.2 */
    private function UpgradeSites()
    {
        $this->Locked(function () {
            $sites = $this->ReadJson('Sites');
            $changed = false;
            foreach ($sites as $code => $site) {
                foreach ($site['saeulen'] ?? [] as $sa) {
                    foreach ($sa['points'] ?? [] as $p) {
                        if (empty($p['varL'])) {
                            // die gespeicherte Struktur hat alle Felder, die der Datensammler sonst liefert
                            $sites[$code] = $this->EnsureSite((string) $code, $site, $site);
                            $changed = true;
                            continue 3;
                        }
                    }
                }
            }
            if ($changed) {
                $this->WriteJson('Sites', $sites);
            }
        });
    }

    /**
     * "Lädt aktiv" für die Zeit vor der Variable einmalig aus Belegt und Leistung nachrechnen,
     * höchstens drei Ladepunkte je Lauf. Den Status des Lastmanagements gibt es rückwirkend nicht,
     * auch nicht den beim Verbindungsausfall gehaltenen Zustand; solche Minuten zählen hier nicht als geladen.
     */
    private function FillCharging()
    {
        $sites = $this->ReadJson('Sites');
        $reader = new EVArchiveReader($this->ArchiveID());
        $late = [];
        $done = 0;
        foreach ($sites as &$site) {
            foreach ($site['saeulen'] as &$sa) {
                foreach ($sa['points'] as &$p) {
                    if (empty($p['lFill']) || $done >= 3) {
                        continue;
                    }
                    $from = (int) $site['detailSince'];
                    $to = (int) $p['lFill'];
                    if ($to > $from) {
                        $rows = EVStats::ChargingFrom(
                            $reader->Logged((int) $p['varB'], $from, $to), $reader->StateBefore((int) $p['varB'], $from),
                            $reader->Values((int) $p['varP'], $from, $to), (float) ($reader->ValueBefore((int) $p['varP'], $from) ?? 0.0)
                        );
                        if ($rows) {
                            $late[(int) $p['varL']] = $rows;
                        }
                    }
                    unset($p['lFill']);
                    $done++;
                }
                unset($p);
            }
            unset($sa);
        }
        unset($site);
        if ($done) {
            if ($late) {
                $this->AddLate($late);
            }
            $this->WriteJson('Sites', $sites);
        }
    }

    // ================================================================ Kachel

    public function GetVisualizationTile()
    {
        $html = @file_get_contents(__DIR__ . '/module.html');
        if ($html === false) {
            return '<div style="padding:8px">module.html fehlt.</div>';
        }
        $init = ['ev' => 'report', 'data' => $this->Report('monat', 0, 'all')];
        $json = json_encode($init);
        return str_replace('%%INITIAL%%', $json === false ? '{}' : $json, $html);
    }

    public function RequestAction($Ident, $Value)
    {
        $v = json_decode((string) $Value, true);
        if (!is_array($v)) {
            $v = [];
        }
        $type = in_array($v['type'] ?? '', EVPeriod::TYPES, true) ? $v['type'] : 'monat';
        $off = min(0, max(-240, (int) ($v['off'] ?? 0)));
        $scope = (string) ($v['scope'] ?? 'all');
        // Die Antwort geht an alle offenen Kacheln; jede Kachel nimmt nur Antworten mit ihrer eigenen Kennung an.
        $cid = preg_replace('/[^A-Za-z0-9]/', '', (string) ($v['cid'] ?? ''));
        switch ($Ident) {
            case 'View':
                $this->UpdateVisualizationValue(json_encode(['ev' => 'report', 'cid' => $cid, 'data' => $this->Report($type, $off, $scope)]));
                return;
            case 'Csv':
                $mode = ($v['mode'] ?? '') === 'verlauf' ? 'verlauf' : 'summen';
                $csv = $this->Locked(function () use ($type, $off, $scope, $mode) {
                    return $this->MakeCsv($type, $off, $scope, $mode);
                }) ?? ['ev' => 'csv', 'error' => 'Die Auswertung ist gerade beschäftigt, bitte gleich noch einmal.'];
                $this->UpdateVisualizationValue(json_encode($csv + ['cid' => $cid]));
                return;
        }
        throw new Exception('Invalid ident: ' . $Ident);
    }

    private function Report(string $type, int $off, string $scope): array
    {
        return EVReport::Build($this->ReadJson('Sites'), new EVArchiveReader($this->ArchiveID()), $type, $off, $scope, time(), $this->Biz());
    }

    private function Biz(): array
    {
        $from = max(0, min(23, $this->ReadPropertyInteger('BizFrom')));
        $to = max($from + 1, min(24, $this->ReadPropertyInteger('BizTo')));
        return ['from' => $from, 'to' => $to];
    }

    // ================================================================ CSV

    private function MakeCsv(string $type, int $off, string $scope, string $mode): array
    {
        $csv = EVReport::Csv($this->ReadJson('Sites'), new EVArchiveReader($this->ArchiveID()), $type, $off, $scope, $mode, time());
        $tk = bin2hex(random_bytes(16));
        if (@file_put_contents($this->CsvFile($tk), $csv['text']) === false) {
            return ['ev' => 'csv', 'error' => 'Die Datei konnte nicht gespeichert werden.'];
        }
        $tokens = $this->ReadJson('CsvTokens');
        $tokens[$tk] = ['exp' => time() + self::CSV_TTL, 'name' => $csv['filename']];
        $this->WriteJson('CsvTokens', $tokens);
        return ['ev' => 'csv', 'url' => self::HOOK . '/csv?t=' . $tk, 'lines' => $csv['count']];
    }

    private function CsvFile(string $token): string
    {
        $dir = IPS_GetKernelDir() . 'EVAnalytics' . DIRECTORY_SEPARATOR;
        if (!is_dir($dir)) {
            @mkdir($dir);
        }
        return $dir . $this->InstanceID . '_' . preg_replace('/[^a-f0-9]/', '', $token) . '.csv';
    }

    protected function ProcessHookData()
    {
        $tk = preg_replace('/[^a-f0-9]/', '', (string) ($_GET['t'] ?? ''));
        $tokens = $this->ReadJson('CsvTokens');
        $info = $tokens[$tk] ?? null;
        $file = $this->CsvFile($tk);
        if ($tk === '' || !$info || $info['exp'] < time() || !is_file($file)) {
            header('HTTP/1.1 404 Not Found');
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Der Link ist abgelaufen. Bitte in der Kachel neu erzeugen.';
            return;
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $info['name']) . '"');
        header('Cache-Control: no-store');
        echo file_get_contents($file);
    }

    public function Destroy()
    {
        // der Eintrag in der WebHook Control bleibt sonst stehen
        $ids = IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}');
        if (count($ids) && IPS_GetKernelRunlevel() == KR_READY) {
            $hooks = json_decode(IPS_GetProperty($ids[0], 'Hooks'), true);
            if (is_array($hooks)) {
                $keep = array_values(array_filter($hooks, function ($h) {
                    return (int) ($h['TargetID'] ?? 0) !== $this->InstanceID;
                }));
                if (count($keep) !== count($hooks)) {
                    IPS_SetProperty($ids[0], 'Hooks', json_encode($keep));
                    IPS_ApplyChanges($ids[0]);
                }
            }
        }
        parent::Destroy();
    }

    /**
     * Hook /hook/evauswertung auf diese Instanz legen. Klassische Module haben kein RegisterHook
     * (das gibt es nur für IPSModuleStrict ab 8.1), deshalb über die Liste "Hooks" der WebHook Control,
     * wie im offiziellen Beispiel symcon/SymconTest libs/WebHookModule.php.
     */
    private function RegisterCsvHook()
    {
        $ids = IPS_GetInstanceListByModuleID('{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}');   // WebHook Control
        if (count($ids) == 0) {
            return;
        }
        $hooks = json_decode(IPS_GetProperty($ids[0], 'Hooks'), true);
        if (!is_array($hooks)) {
            $hooks = [];
        }
        foreach ($hooks as $i => $h) {
            if ($h['Hook'] === self::HOOK) {
                if ((int) $h['TargetID'] === $this->InstanceID) {
                    return;
                }
                $hooks[$i]['TargetID'] = $this->InstanceID;
                IPS_SetProperty($ids[0], 'Hooks', json_encode($hooks));
                IPS_ApplyChanges($ids[0]);
                return;
            }
        }
        $hooks[] = ['Hook' => self::HOOK, 'TargetID' => $this->InstanceID];
        IPS_SetProperty($ids[0], 'Hooks', json_encode($hooks));
        IPS_ApplyChanges($ids[0]);
    }

    // ================================================================ Hilfen

    /** Mehrere Datensammler liefern parallel; Attribute darf immer nur ein Lauf schreiben. */
    private function Locked(callable $fn)
    {
        $name = 'EVAW_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($name, 15000)) {
            return null;
        }
        try {
            return $fn();
        } finally {
            IPS_SemaphoreLeave($name);
        }
    }

    private function ArchiveID(): int
    {
        $ids = IPS_GetInstanceListByModuleID(self::ARCHIVE_GUID);
        return count($ids) ? $ids[0] : 0;
    }

    private function Ident(string $id): string
    {
        return preg_replace('/[^A-Za-z0-9_]/', '_', $id);
    }

    private function EnsureCategory(int $parent, string $ident, string $name, int $pos): int
    {
        $id = @IPS_GetObjectIDByIdent($ident, $parent);
        if ($id === false) {
            $id = IPS_CreateCategory();
            IPS_SetParent($id, $parent);
            IPS_SetIdent($id, $ident);
            IPS_SetPosition($id, $pos);
        }
        IPS_SetName($id, $name);
        return $id;
    }

    /** Variable mit Ident unter $parent anlegen, Archivierung einschalten (Aggregation 0 = Standard, 1 = Zähler) */
    private function EnsureVar(int $parent, string $ident, int $type, string $name, string $profile, int $aggregation, int $pos): int
    {
        $id = @IPS_GetObjectIDByIdent($ident, $parent);
        if ($id === false) {
            $id = IPS_CreateVariable($type);
            IPS_SetParent($id, $parent);
            IPS_SetIdent($id, $ident);
            IPS_SetVariableCustomProfile($id, $profile);
            $ac = $this->ArchiveID();
            if ($ac) {
                AC_SetLoggingStatus($ac, $id, true);
                AC_SetAggregationType($ac, $id, $aggregation);
                if ($aggregation === 1) {
                    // Beim Anlegen landet der Anfangswert 0 im Archiv; ohne diese Einstellung zählte
                    // der Sprung 0 → Zählerstand als Verbrauch
                    AC_SetCounterIgnoreZeros($ac, $id, true);
                }
                IPS_ApplyChanges($ac);
            }
        }
        IPS_SetName($id, $name);
        IPS_SetPosition($id, $pos);
        return $id;
    }

    private function RegisterProfiles()
    {
        if (!IPS_VariableProfileExists('EVAW.kW')) {
            IPS_CreateVariableProfile('EVAW.kW', 2);
            IPS_SetVariableProfileDigits('EVAW.kW', 2);
            IPS_SetVariableProfileText('EVAW.kW', '', ' kW');
            IPS_SetVariableProfileIcon('EVAW.kW', 'EnergyProduction');
        }
        if (!IPS_VariableProfileExists('EVAW.kWh')) {
            IPS_CreateVariableProfile('EVAW.kWh', 2);
            IPS_SetVariableProfileDigits('EVAW.kWh', 2);
            IPS_SetVariableProfileText('EVAW.kWh', '', ' kWh');
            IPS_SetVariableProfileIcon('EVAW.kWh', 'Electricity');
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

    /**
     * HTML-Kachel. Ab Symcon 9.1 auch im Vollbild (Typ 2), davor zeigt das Vollbild die Variablen der Instanz.
     */
    private static function VisuType(): int
    {
        return defined('INSTANCE_VISUALIZATION_TYPE_HTML_FULLSCREEN') ? (int) constant('INSTANCE_VISUALIZATION_TYPE_HTML_FULLSCREEN') : 1;
    }
}
