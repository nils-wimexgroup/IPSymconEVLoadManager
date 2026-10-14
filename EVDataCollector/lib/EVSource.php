<?php

/**
 * Quellen des EV Datensammlers (ohne Symcon-Abhaengigkeiten, damit testbar).
 *
 * Ein Ladepunkt wird immer gleich beschrieben, egal ob er aus dem EVLM oder vom ChargePilot kommt:
 *   ['id' => 'ADF-LS01-1', 'saeule' => 'ADF-LS01', 'saeuleName' => 'Säule 1', 'name' => 'links',
 *    'vars' => ['state' => id, 'power' => id, 'energy' => id, 'cur' => [id, id, id], 'set' => id], 'maxKw' => 22.1]
 * Zustände ("mode"): frei, bereit (angesteckt, lädt nicht), laden, fehler, offline.
 */
final class EVSource
{
    public const VOLT = 230.0;
    public const LADEN_KW = 0.1;   // ab dieser Leistung gilt ein belegter Ladepunkt als aktiv ladend (gleicher Wert in EVStats)

    // ChargePilot-Registerblock je Ladepunkt (Doku The Mobility House)
    public const CP_BASE = 12288;
    public const CP_STEP = 256;
    public const CP_OFFSETS = [0 => 'cid', 40 => 'state', 16 => 'power', 36 => 'energy', 18 => 'cur1', 20 => 'cur2', 22 => 'cur3', 86 => 'set'];
    public const CP_SITE = [4108 => 'count', 4110 => 'fuseSub', 4112 => 'fuseMain', 1 => 'powerAll'];
    public const CP_REQUIRED = ['state', 'power', 'energy'];

    public static function AmpToKw(float $a): float
    {
        return round($a * 3 * self::VOLT / 1000, 1);
    }

    /** Anzeige: "Saeule 1" aus dem EVLM-Formular wird "Säule 1" */
    public static function Pretty(string $s): string
    {
        return str_replace(['Saeule', 'saeule'], ['Säule', 'säule'], trim($s));
    }

    /** Kürzel einer Säule: "Saeule 3" → LS03, sonst laufende Nummer */
    public static function SaeuleCode(string $name, int $fallback): string
    {
        return preg_match('/(\d+)/', $name, $m) ? sprintf('LS%02d', (int) $m[1]) : sprintf('LS%02d', $fallback);
    }

    // ------------------------------------------------------------ Status

    /** Mode-3 (Alfen/IEC 61851) → Zustand. Alfen: A und E frei, nur F Fehler. Standard: E und F Fehler. */
    public static function ModeFromMode3(string $mode3, string $vendor, float $amps): string
    {
        $s = strtoupper(trim($mode3));
        if ($s === '') {
            return $amps > 0.5 ? 'laden' : 'frei';
        }
        $l = $s[0];
        if ($l === 'F' || ($l === 'E' && $vendor !== 'alfen')) {
            return 'fehler';
        }
        if ($l === 'A' || $l === 'E') {
            return 'frei';
        }
        if ($l === 'B' || $s === 'C1' || $s === 'D1') {
            return 'bereit';
        }
        if ($l === 'C' || $l === 'D') {
            return 'laden';
        }
        return 'frei';
    }

    /** ChargePilot-Status 0–4 → Zustand */
    public static function ModeFromChargePilot($code): string
    {
        $c = (int) $code;
        return [0 => 'offline', 1 => 'frei', 2 => 'bereit', 3 => 'laden', 4 => 'fehler'][$c] ?? 'offline';
    }

    public static function IsBelegt(string $mode): bool
    {
        return $mode === 'bereit' || $mode === 'laden';
    }

    /**
     * Aktiv geladen: Status "lädt" (wie im Lastmanagement) und es fließt tatsächlich Leistung.
     * Der Status allein reicht nicht, manche Fahrzeuge bleiben z. B. mit vollem Akku in C2.
     */
    public static function IsCharging(string $mode, float $kw): bool
    {
        return $mode === 'laden' && $kw >= self::LADEN_KW;
    }

    // ------------------------------------------------------------ EVLM

    /**
     * Ladepunkte aus der Konfiguration einer EVLM-Instanz.
     * @param array $groups  EVLM-Eigenschaft Groups (Name, Vendor, MaxA)
     * @param array $cps     EVLM-Eigenschaft ChargePoints
     * @param float $budgetA EVLM-Eigenschaft BudgetA
     * @param array $idMap   bisher vergebene Kennungen [Schlüsselvariable => Kennung]; wird ergänzt
     * @return array [Ladepunkte, Säulen [id => ['name','limitKw','vendor']], budgetKw, idMap]
     */
    public static function FromEVLM(string $site, array $groups, array $cps, float $budgetA, array $idMap): array
    {
        $budgetKw = self::AmpToKw($budgetA);
        $gInfo = [];
        foreach ($groups as $i => $g) {
            $n = trim((string) ($g['Name'] ?? ''));
            if ($n !== '') {
                $gInfo[$n] = ['pos' => $i + 1, 'vendor' => strtolower((string) ($g['Vendor'] ?? 'alfen')) === 'standard' ? 'standard' : 'alfen', 'maxA' => (float) ($g['MaxA'] ?? 0)];
            }
        }
        $used = array_flip(array_map('strval', $idMap));
        $points = [];
        $saeulen = [];
        $sockNr = [];
        foreach (array_slice(array_values($cps), 0, 8) as $k => $cp) {   // EVLM verarbeitet höchstens 8
            $name = trim((string) ($cp['Name'] ?? '')) ?: 'Ladepunkt ' . ($k + 1);
            $group = trim((string) ($cp['Group'] ?? ''));
            $gi = $gInfo[$group] ?? ['pos' => $k + 1, 'vendor' => 'alfen', 'maxA' => 0.0];
            $gname = $group !== '' ? $group : $name;
            $code = self::SaeuleCode($gname, $gi['pos']);
            $sockNr[$code] = ($sockNr[$code] ?? 0) + 1;
            $vars = [
                'state' => (int) ($cp['StateVar'] ?? 0), 'energy' => (int) ($cp['EnergyVar'] ?? 0), 'set' => (int) ($cp['SetVar'] ?? 0),
                'cur' => [(int) ($cp['CurL1'] ?? 0), (int) ($cp['CurL2'] ?? 0), (int) ($cp['CurL3'] ?? 0)], 'power' => 0
            ];
            // fester Schlüssel: Statusvariable, sonst Strom L1, sonst Listenposition
            $key = (string) ($vars['state'] ?: ($vars['cur'][0] ?: 'pos' . $k));
            if (!isset($idMap[$key])) {
                $id = $site . '-' . $code . '-' . $sockNr[$code];
                while (isset($used[$id])) {
                    $sockNr[$code]++;
                    $id = $site . '-' . $code . '-' . $sockNr[$code];
                }
                $idMap[$key] = $id;
                $used[$id] = true;
            }
            $id = $idMap[$key];
            $sid = substr($id, 0, (int) strrpos($id, '-'));
            if (!isset($saeulen[$sid])) {
                $limit = $gi['maxA'] > 0 ? min(self::AmpToKw($gi['maxA']), $budgetKw) : $budgetKw;
                $saeulen[$sid] = ['name' => self::Pretty($gname), 'limitKw' => $limit, 'vendor' => $gi['vendor']];
            }
            $points[] = [
                'id' => $id, 'saeule' => $sid, 'saeuleName' => self::Pretty($gname), 'name' => self::Pretty($name), 'pos' => $k,
                'vendor' => $gi['vendor'], 'vars' => $vars, 'maxKw' => self::AmpToKw((float) ($cp['MaxA'] ?? 32))
            ];
        }
        return [$points, $saeulen, $budgetKw, $idMap];
    }

    // ------------------------------------------------------------ ChargePilot

    /** Registeradresse → [Ladepunkt-Block (0…), Wert] bzw. ['site', Wert]; null wenn unbekannt */
    public static function ClassifyAddress(int $addr): ?array
    {
        if (isset(self::CP_SITE[$addr])) {
            return ['site', self::CP_SITE[$addr]];
        }
        if ($addr < self::CP_BASE) {
            return null;
        }
        $n = intdiv($addr - self::CP_BASE, self::CP_STEP);
        $off = ($addr - self::CP_BASE) % self::CP_STEP;
        return isset(self::CP_OFFSETS[$off]) ? [$n, self::CP_OFFSETS[$off]] : null;
    }

    /**
     * Erkennt die Ladepunkte aus den ModBus-Geräten eines Ordners.
     * @param array $devices je Gerät ['var' => Variablen-ID, 'addr' => Leseadresse, 'lp' => Ordnername darüber,
     *                       'saeule' => Ordnername darüber, 'path' => Anzeige-Pfad]
     * @return array ['points' => [...], 'site' => [Wert => var], 'issues' => [Text, …]]
     */
    public static function ScanChargePilot(string $site, array $devices, array $idMap): array
    {
        $blocks = [];
        $siteVars = [];
        $issues = [];
        foreach ($devices as $d) {
            $c = self::ClassifyAddress((int) $d['addr']);
            if ($c === null) {
                $issues[] = 'Adresse ' . $d['addr'] . ' (' . $d['path'] . ') ist kein bekanntes ChargePilot-Register und wird ignoriert.';
                continue;
            }
            if ($c[0] === 'site') {
                $siteVars[$c[1]] = (int) $d['var'];
                continue;
            }
            [$n, $what] = $c;
            if (isset($blocks[$n]['vars'][$what])) {
                $issues[] = 'Ladepunkt ' . ($n + 1) . ': „' . $what . '“ ist doppelt angelegt (' . $d['path'] . ').';
                continue;
            }
            $blocks[$n]['vars'][$what] = (int) $d['var'];
            $blocks[$n]['lp'] = $blocks[$n]['lp'] ?? (string) $d['lp'];
            $blocks[$n]['saeule'] = $blocks[$n]['saeule'] ?? (string) $d['saeule'];
        }
        ksort($blocks);
        $saeulenPos = [];
        $sockNr = [];
        $used = array_flip(array_map('strval', $idMap));
        $points = [];
        foreach ($blocks as $n => $b) {
            $missing = array_values(array_diff(self::CP_REQUIRED, array_keys($b['vars'])));
            if ($missing) {
                $issues[] = 'Ladepunkt ' . ($n + 1) . ' („' . $b['lp'] . '“): es fehlt ' . implode(', ', $missing) . '.';
                continue;
            }
            $sname = $b['saeule'] !== '' ? $b['saeule'] : 'Säule ' . ($n + 1);
            $saeulenPos[$sname] = $saeulenPos[$sname] ?? count($saeulenPos) + 1;
            $code = self::SaeuleCode($sname, $saeulenPos[$sname]);
            $sockNr[$code] = ($sockNr[$code] ?? 0) + 1;
            $key = (string) $b['vars']['state'];
            if (!isset($idMap[$key])) {
                $id = $site . '-' . $code . '-' . $sockNr[$code];
                while (isset($used[$id])) {
                    $sockNr[$code]++;
                    $id = $site . '-' . $code . '-' . $sockNr[$code];
                }
                $idMap[$key] = $id;
                $used[$id] = true;
            }
            $id = $idMap[$key];
            $points[] = [
                'id' => $id, 'saeule' => substr($id, 0, (int) strrpos($id, '-')), 'saeuleName' => self::Pretty($sname),
                'name' => self::Pretty($b['lp'] !== '' ? $b['lp'] : 'Ladepunkt ' . ($n + 1)), 'block' => $n,
                'vars' => [
                    'state' => $b['vars']['state'], 'power' => $b['vars']['power'], 'energy' => $b['vars']['energy'],
                    'cur' => [$b['vars']['cur1'] ?? 0, $b['vars']['cur2'] ?? 0, $b['vars']['cur3'] ?? 0], 'set' => $b['vars']['set'] ?? 0,
                    'cid' => $b['vars']['cid'] ?? 0
                ],
                'maxKw' => 22.1
            ];
        }
        if (!isset($siteVars['count'])) {
            $issues[] = 'Register 4108 (Anzahl Ladepunkte) fehlt. Ohne es fällt nicht auf, wenn sich die Blöcke nach einer neuen Säule verschieben.';
        }
        return ['points' => $points, 'site' => $siteVars, 'issues' => $issues, 'idMap' => $idMap, 'blocks' => count($blocks)];
    }

    /** Leistung des ChargePilot in kW; Werte über 100 sind Watt (AC-Ladepunkte liegen weit darunter) */
    public static function PowerKw($v): float
    {
        $v = (float) $v;
        return $v > 100 ? $v / 1000 : $v;
    }
}
