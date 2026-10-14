<?php

/**
 * Baut die Daten fuer die Auswertungs-Kachel und den CSV-Export (ohne Symcon-Abhaengigkeiten).
 *
 * $meta  = Standorte wie im Attribut "Sites" der EV Auswertung:
 *   [ 'ADF' => [ 'name' => 'Arensdorf', 'sourceLabel' => 'EVLM', 'budgetKw' => 13.8|null,
 *                'histSince' => ts, 'detailSince' => ts, 'varP' => id,
 *                'saeulen' => [ [ 'id' => 'ADF-LS01', 'name' => 'Säule 1', 'limitKw' => 13.8|null, 'varP' => id,
 *                                 'points' => [ [ 'id' => 'ADF-LS01-1', 'name' => 'links', 'varE' => id, 'varB' => id, 'varP' => id ] ] ] ] ] ]
 *
 * $reader = Objekt mit den Methoden (im Modul ueber AC_*, im Test mit erfundenen Daten):
 *   DailyEnergy(int $varE, int $from, int $to): array  [Tagesbeginn => kWh]
 *   DailyMax(int $varP, int $from, int $to): array     [Tagesbeginn => kW]
 *   Logged(int $varB, int $from, int $to): array       aufsteigend [['TimeStamp' => t, 'Value' => bool]]
 *   StateBefore(int $varB, int $ts): bool
 */
final class EVReport
{
    public const LIMIT_HIT = 0.985;   // ab diesem Anteil am Limit gilt ein Tag als "am Budget"

    // ------------------------------------------------------------------ Auswahl

    /** Alle Ladepunkte flach, mit Standort- und Säulenbezug */
    public static function Points(array $meta): array
    {
        $out = [];
        foreach ($meta as $sk => $site) {
            foreach ($site['saeulen'] ?? [] as $sa) {
                foreach ($sa['points'] ?? [] as $p) {
                    $out[] = $p + [
                        'site' => (string) $sk, 'siteName' => (string) ($site['name'] ?? $sk),
                        'saeule' => (string) $sa['id'], 'saeuleName' => (string) ($sa['name'] ?? $sa['id']),
                        'histSince' => (int) ($site['histSince'] ?? 0), 'detailSince' => (int) ($site['detailSince'] ?? 0)
                    ];
                }
            }
        }
        return $out;
    }

    /** 'all' | 'site:ADF' | 'saeule:ADF:ADF-LS01'; Unbekanntes wird zu 'all' */
    public static function ParseScope(array $meta, string $s): array
    {
        $p = explode(':', $s, 3);
        if (($p[0] ?? '') === 'site' && isset($meta[$p[1] ?? ''])) {
            return ['level' => 'site', 'site' => $p[1]];
        }
        if (($p[0] ?? '') === 'saeule' && isset($meta[$p[1] ?? ''])) {
            foreach ($meta[$p[1]]['saeulen'] ?? [] as $sa) {
                if ($sa['id'] === ($p[2] ?? '')) {
                    return ['level' => 'saeule', 'site' => $p[1], 'saeule' => $p[2]];
                }
            }
        }
        return ['level' => 'all'];
    }

    public static function ScopeString(array $sc): string
    {
        return $sc['level'] === 'site' ? 'site:' . $sc['site'] : ($sc['level'] === 'saeule' ? 'saeule:' . $sc['site'] . ':' . $sc['saeule'] : 'all');
    }

    public static function ScopeOptions(array $meta): array
    {
        $o = [['group' => null, 'v' => 'all', 'l' => 'Alle Standorte']];
        foreach ($meta as $sk => $site) {
            $name = (string) ($site['name'] ?? $sk);
            $o[] = ['group' => $name, 'v' => 'site:' . $sk, 'l' => $name . ' gesamt'];
            foreach ($site['saeulen'] ?? [] as $sa) {
                $o[] = ['group' => $name, 'v' => 'saeule:' . $sk . ':' . $sa['id'], 'l' => $name . ' · ' . ($sa['name'] ?? $sa['id'])];
            }
        }
        return $o;
    }

    private static function inScope(array $p, array $sc): bool
    {
        return $sc['level'] === 'all' || ($p['site'] === $sc['site'] && ($sc['level'] === 'site' || $p['saeule'] === $sc['saeule']));
    }

    private static function saeuleOf(array $meta, string $site, string $id): ?array
    {
        foreach ($meta[$site]['saeulen'] ?? [] as $sa) {
            if ($sa['id'] === $id) {
                return $sa;
            }
        }
        return null;
    }

    // ------------------------------------------------------------------ Bausteine

    /** Energie je Tag eines Ladepunkts, nur Tage mit Daten */
    private static function energyDays($reader, array $p, int $from, int $to): array
    {
        $a = max($from, EVPeriod::Day0(max(1, $p['histSince'])));
        return $to > $a ? $reader->DailyEnergy((int) $p['varE'], $a, $to) : [];
    }

    /** Belegt-Intervalle eines Ladepunkts im Zeitraum, begrenzt auf die Zeit mit eigenen Daten */
    private static function intervals($reader, array $p, int $from, int $to): array
    {
        $a = max($from, $p['detailSince']);
        if ($p['detailSince'] <= 0 || $to <= $a) {
            return [[], 0, 0];
        }
        $log = $reader->Logged((int) $p['varB'], $a, $to);
        $iv = EVStats::Intervals($log, $reader->StateBefore((int) $p['varB'], $a), $a, $to);
        return [$iv, $a, $to];
    }

    private static function bucketOf(array $buckets, int $ts): ?int
    {
        foreach ($buckets as $i => $b) {
            if ($ts >= $b['s'] && $ts < $b['e']) {
                return $i;
            }
        }
        return null;
    }

    // ------------------------------------------------------------------ Kachel

    public static function Build(array $meta, $reader, string $type, int $off, string $scopeStr, int $now, array $biz): array
    {
        $today0 = EVPeriod::Day0($now);
        $P = EVPeriod::Build($type, $off, $today0);
        $sc = self::ParseScope($meta, $scopeStr);
        $all = self::Points($meta);
        $pts = array_values(array_filter($all, function ($p) use ($sc) {
            return self::inScope($p, $sc);
        }));
        $histStart = PHP_INT_MAX;
        $setup = PHP_INT_MAX;
        foreach ($meta as $site) {
            if (!empty($site['histSince'])) {
                $histStart = min($histStart, (int) $site['histSince']);
            }
            if (!empty($site['detailSince'])) {
                $setup = min($setup, (int) $site['detailSince']);
            }
        }
        $histStart = $histStart === PHP_INT_MAX ? $now : $histStart;
        $setup = $setup === PHP_INT_MAX ? $now : $setup;
        $end = min($P['end'], $now);   // nichts aus der Zukunft abfragen

        $site = $sc['level'] !== 'all' ? $meta[$sc['site']] : null;
        $sa = $sc['level'] === 'saeule' ? self::saeuleOf($meta, $sc['site'], $sc['saeule']) : null;
        $limitKw = $sc['level'] === 'site' ? ($site['budgetKw'] ?? null) : ($sc['level'] === 'saeule' ? ($sa['limitKw'] ?? null) : null);
        $groupOf = function ($p) use ($sc) {
            return $sc['level'] === 'all' ? $p['siteName'] : ($sc['level'] === 'site' ? $p['saeuleName'] : $p['name']);
        };

        // ---- Energie, Belegung je Ladepunkt
        $energy = [];
        foreach ($P['buckets'] as $b) {
            $energy[] = ['x' => $b['x'], 't' => $b['t'], 'kwh' => 0.0, 'by' => [], 'ses' => 0,
                'future' => $b['s'] > $now, 'nodata' => true, 'detail' => false];
        }
        $acc = [];
        $heatSec = array_fill(0, 7, array_fill(0, 24, 0));
        $heatDays = array_fill(0, 7, 0);
        $bizBusy = 0;
        $bizWin = 0;
        $detailDaySet = [];
        foreach ($pts as $k => $p) {
            $g = $groupOf($p);
            $a = ['kwh' => 0.0, 'kwhDetail' => 0.0, 'ses' => 0, 'busy' => 0, 'obs' => 0];
            foreach (self::energyDays($reader, $p, $P['start'], $end) as $day => $kwh) {
                $i = self::bucketOf($P['buckets'], (int) $day);
                if ($i === null) {
                    continue;
                }
                $energy[$i]['kwh'] += $kwh;
                $energy[$i]['by'][$g] = ($energy[$i]['by'][$g] ?? 0.0) + $kwh;
                $energy[$i]['nodata'] = false;
                $a['kwh'] += $kwh;
                if ($p['detailSince'] > 0 && $day >= EVPeriod::Day0($p['detailSince'])) {
                    $a['kwhDetail'] += $kwh;
                }
            }
            [$iv, $from, $to] = self::intervals($reader, $p, $P['start'], $end);
            if ($to > $from) {
                $a['obs'] = $to - $from;
                $a['busy'] = EVStats::Seconds($iv);
                foreach (EVStats::Starts($iv) as $st) {
                    $a['ses']++;
                    $i = self::bucketOf($P['buckets'], $st);
                    if ($i !== null) {
                        $energy[$i]['ses']++;
                    }
                }
                $hs = EVStats::HourSeconds($iv);
                foreach ($hs as $w => $row) {
                    foreach ($row as $h => $sec) {
                        $heatSec[$w][$h] += $sec;
                    }
                }
                $wc = EVStats::WeekdayCounts($from, $to);
                foreach ($wc as $w => $n) {
                    $heatDays[$w] += $n;   // Summe über Ladepunkte = Nenner der Heatmap
                }
                $bizBusy += EVStats::BizSeconds($hs, $biz['from'], $biz['to']);
                $bizWin += EVStats::BizWindowSeconds($from, $to, $biz['from'], $biz['to']);
                for ($d = EVPeriod::Day0($from); $d < $to; $d = EVPeriod::AddDays($d, 1)) {
                    $detailDaySet[$d] = true;
                    $i = self::bucketOf($P['buckets'], $d);
                    if ($i !== null) {
                        $energy[$i]['detail'] = true;
                    }
                }
            }
            $acc[$k] = $a;
        }
        foreach ($energy as &$e) {
            $e['kwh'] = round($e['kwh'], 3);
            foreach ($e['by'] as &$v) {
                $v = round($v, 3);
            }
            unset($v);
        }
        unset($e);

        // ---- Leistungsspitzen
        $peaks = [];
        $sitePeaks = null;
        $peakMax = null;
        $peakSite = null;
        $limitDays = 0;
        $peakDays = 0;
        foreach ($P['buckets'] as $b) {
            $peaks[] = ['x' => $b['x'], 't' => $b['t'], 'kw' => null, 'limitDays' => 0, 'days' => 0];
        }
        if ($sc['level'] === 'all') {
            $sitePeaks = [];
            $bestRatio = -1.0;
            foreach ($meta as $sk => $s) {
                $sp = ['key' => (string) $sk, 'name' => (string) ($s['name'] ?? $sk), 'budget' => $s['budgetKw'] ?? null, 'peak' => null, 'limitDays' => 0, 'days' => 0];
                if (!empty($s['varP']) && !empty($s['detailSince'])) {
                    $a = max($P['start'], EVPeriod::Day0((int) $s['detailSince']));
                    foreach ($end > $a ? $reader->DailyMax((int) $s['varP'], $a, $end) : [] as $kw) {
                        $sp['peak'] = max($sp['peak'] ?? 0.0, (float) $kw);
                        $sp['days']++;
                        if ($sp['budget'] && $kw >= self::LIMIT_HIT * $sp['budget']) {
                            $sp['limitDays']++;
                        }
                    }
                }
                if ($sp['peak'] !== null) {
                    $sp['peak'] = round($sp['peak'], 2);
                    $ratio = $sp['budget'] ? $sp['peak'] / $sp['budget'] : 0.0;
                    if ($ratio > $bestRatio) {
                        $bestRatio = $ratio;
                        $peakMax = $sp['peak'];
                        $peakSite = $sp['name'];
                    }
                    $limitDays += $sp['limitDays'];
                    $peakDays += $sp['days'];
                }
                $sitePeaks[] = $sp;
            }
        } else {
            $varP = $sc['level'] === 'site' ? ($site['varP'] ?? 0) : ($sa['varP'] ?? 0);
            $a = max($P['start'], EVPeriod::Day0((int) ($site['detailSince'] ?? 0)));
            if ($varP && !empty($site['detailSince']) && $end > $a) {
                foreach ($reader->DailyMax((int) $varP, $a, $end) as $day => $kw) {
                    $i = self::bucketOf($P['buckets'], (int) $day);
                    if ($i === null) {
                        continue;
                    }
                    $kw = (float) $kw;
                    $peaks[$i]['kw'] = round(max($peaks[$i]['kw'] ?? 0.0, $kw), 2);
                    $peaks[$i]['days']++;
                    $peakDays++;
                    $peakMax = max($peakMax ?? 0.0, $kw);
                    if ($limitKw && $kw >= self::LIMIT_HIT * $limitKw) {
                        $peaks[$i]['limitDays']++;
                        $limitDays++;
                    }
                }
            }
            $peakMax = $peakMax === null ? null : round($peakMax, 2);
        }

        // ---- Tabelle
        $sum = function (array $keys) use ($acc) {
            $o = ['kwh' => 0.0, 'kwhDetail' => 0.0, 'ses' => 0, 'busy' => 0, 'obs' => 0];
            foreach ($keys as $k) {
                foreach ($o as $f => $_) {
                    $o[$f] += $acc[$k][$f];
                }
            }
            return $o;
        };
        $row = function (string $label, string $sub, array $o, ?string $drill) {
            $has = $o['obs'] > 0;
            return [
                'label' => $label, 'sub' => $sub, 'kwh' => round($o['kwh'], 3),
                'ses' => $has ? $o['ses'] : null,
                'avgKwh' => $o['ses'] ? round($o['kwhDetail'] / $o['ses'], 3) : null,
                'avgDur' => $o['ses'] ? round($o['busy'] / 3600 / $o['ses'], 3) : null,
                'occ' => $has ? round($o['busy'] / $o['obs'], 4) : null,
                'drill' => $drill
            ];
        };
        $rows = [];
        if ($sc['level'] === 'all') {
            foreach ($meta as $sk => $s) {
                $keys = array_keys(array_filter($pts, function ($p) use ($sk) {
                    return $p['site'] === (string) $sk;
                }));
                $nS = count($s['saeulen'] ?? []);
                $sub = $nS . ($nS === 1 ? ' Säule · ' : ' Säulen · ') . count($keys) . ' Ladepunkte · ' . ($s['sourceLabel'] ?? '');
                $rows[] = $row((string) ($s['name'] ?? $sk), $sub, $sum($keys), 'site:' . $sk);
            }
        } else {
            foreach ($pts as $k => $p) {
                $label = $sc['level'] === 'site' ? $p['saeuleName'] . ' · ' . $p['name'] : $p['name'];
                $rows[] = $row($label, '', $sum([$k]), $sc['level'] === 'site' ? 'saeule:' . $p['site'] . ':' . $p['saeule'] : null);
            }
        }
        $T = $sum(array_keys($pts));

        // ---- Vergleich
        $Q = EVPeriod::Previous($P, $today0);
        $prev = null;
        foreach ($pts as $p) {
            foreach (self::energyDays($reader, $p, $Q['start'], min($Q['end'], $now)) as $kwh) {
                $prev = ($prev ?? 0.0) + $kwh;
            }
        }

        // ---- Heatmap
        $grid = [];
        for ($w = 0; $w < 7; $w++) {
            $r = [];
            for ($h = 0; $h < 24; $h++) {
                $r[] = $heatDays[$w] > 0 ? round($heatSec[$w][$h] / ($heatDays[$w] * 3600), 4) : null;
            }
            $grid[] = $r;
        }

        $crumbs = [['l' => 'Alle Standorte', 'v' => 'all']];
        if ($site) {
            $crumbs[] = ['l' => (string) $site['name'], 'v' => 'site:' . $sc['site']];
        }
        if ($sa) {
            $crumbs[] = ['l' => (string) $sa['name'], 'v' => self::ScopeString($sc)];
        }
        $hasDetail = $T['obs'] > 0;

        return [
            'label' => $P['label'], 'partial' => $P['partial'], 'unit' => $P['unit'], 'type' => $P['type'], 'off' => $P['off'],
            'canPrev' => $P['start'] > EVPeriod::Day0($histStart), 'canNext' => $off < 0, 'biz' => $biz,
            'scope' => [
                'level' => $sc['level'], 'value' => self::ScopeString($sc), 'crumbs' => $crumbs,
                'limitKw' => $limitKw, 'limitLabel' => $sc['level'] === 'saeule' ? 'Säulen-Limit' : 'Budget',
                'points' => count($pts), 'note' => $site['note'] ?? null
            ],
            'kpi' => [
                'kwh' => round($T['kwh'], 3), 'prevKwh' => $prev === null ? null : round($prev, 3), 'cmpText' => EVPeriod::CompareText($P),
                'ses' => $hasDetail ? $T['ses'] : null,
                'avgKwh' => $T['ses'] ? round($T['kwhDetail'] / $T['ses'], 3) : null,
                'avgDur' => $T['ses'] ? round($T['busy'] / 3600 / $T['ses'], 3) : null,
                'occ' => $hasDetail ? round($T['busy'] / $T['obs'], 4) : null,
                'bizOcc' => $bizWin > 0 ? round($bizBusy / $bizWin, 4) : null,
                'peak' => $peakMax, 'peakSite' => $peakSite, 'limitDays' => $limitDays, 'detailDays' => $peakDays
            ],
            'energy' => $energy, 'peaks' => $peaks, 'sitePeaks' => $sitePeaks,
            'heat' => ['grid' => $grid, 'days' => count($detailDaySet)],
            'rows' => $rows, 'scopeOptions' => self::ScopeOptions($meta),
            'source' => ['histStart' => $histStart, 'setup' => $setup]
        ];
    }

    // ------------------------------------------------------------------ CSV

    /** Semikolon, Dezimalkomma, UTF-8 mit BOM, damit Excel Umlaute und Zahlen richtig öffnet */
    public static function Csv(array $meta, $reader, string $type, int $off, string $scopeStr, string $mode, int $now): array
    {
        $today0 = EVPeriod::Day0($now);
        $P = EVPeriod::Build($type, $off, $today0);
        $sc = self::ParseScope($meta, $scopeStr);
        $pts = array_values(array_filter(self::Points($meta), function ($p) use ($sc) {
            return self::inScope($p, $sc);
        }));
        $end = min($P['end'], $now);
        $f2 = function ($v) {
            return $v === null ? '' : number_format((float) $v, 2, ',', '');
        };
        $ds = function (int $t) {
            return date('d.m.Y', $t);
        };
        $esc = function (string $s) {
            return preg_match('/[;"\r\n]/', $s) ? '"' . str_replace('"', '""', $s) . '"' : $s;
        };
        $L = [];
        if ($mode === 'summen') {
            $L[] = 'Standort;Säule;Ladepunkt;Von;Bis;Energie kWh;Ladevorgänge;Ø kWh je Vorgang;Ø Dauer h;Belegt h;Auslastung %';
            foreach ($pts as $p) {
                $kwh = array_sum(self::energyDays($reader, $p, $P['start'], $end));
                $kwhDet = 0.0;
                foreach (self::energyDays($reader, $p, $P['start'], $end) as $day => $v) {
                    if ($p['detailSince'] > 0 && $day >= EVPeriod::Day0($p['detailSince'])) {
                        $kwhDet += $v;
                    }
                }
                [$iv, $from, $to] = self::intervals($reader, $p, $P['start'], $end);
                $has = $to > $from;
                $ses = $has ? count(EVStats::Starts($iv)) : null;
                $busy = $has ? EVStats::Seconds($iv) : null;
                $L[] = implode(';', [
                    $esc($p['siteName']), $esc($p['saeuleName']), $esc($p['name']), $ds($P['start']), $ds(EVPeriod::AddDays(EVPeriod::Day0($end - 1), 0)),
                    $f2($kwh), $ses === null ? '' : (string) $ses, $ses ? $f2($kwhDet / $ses) : '', $ses ? $f2($busy / 3600 / $ses) : '',
                    $has ? $f2($busy / 3600) : '', $has ? $f2($busy / ($to - $from) * 100) : ''
                ]);
            }
        } else {
            $L[] = ($P['unit'] === 'Tag' ? 'Datum' : 'Monat') . ';Standort;Säule;Ladepunkt;Energie kWh;Ladevorgänge;Belegt h';
            $perPoint = [];
            foreach ($pts as $k => $p) {
                $perPoint[$k] = [self::energyDays($reader, $p, $P['start'], $end), self::intervals($reader, $p, $P['start'], $end)];
            }
            foreach ($P['buckets'] as $b) {
                if ($b['s'] > $now) {
                    continue;
                }
                foreach ($pts as $k => $p) {
                    [$days, [$iv, $from, $to]] = $perPoint[$k];
                    $kwh = 0.0;
                    $any = false;
                    foreach ($days as $day => $v) {
                        if ($day >= $b['s'] && $day < $b['e']) {
                            $kwh += $v;
                            $any = true;
                        }
                    }
                    if (!$any) {
                        continue;
                    }
                    $det = $to > $from && $b['e'] > $from && $b['s'] < $to;
                    $ses = 0;
                    $busy = 0;
                    if ($det) {
                        foreach ($iv as $x) {
                            if ($x[2] && $x[0] >= $b['s'] && $x[0] < $b['e']) {
                                $ses++;
                            }
                            $busy += max(0, min($x[1], $b['e']) - max($x[0], $b['s']));
                        }
                    }
                    $L[] = implode(';', [
                        $P['unit'] === 'Tag' ? $ds($b['s']) : date('m.Y', $b['s']),
                        $esc($p['siteName']), $esc($p['saeuleName']), $esc($p['name']),
                        $f2($kwh), $det ? (string) $ses : '', $det ? $f2($busy / 3600) : ''
                    ]);
                }
            }
        }
        $tag = $sc['level'] === 'all' ? 'alle' : ($sc['level'] === 'site' ? $sc['site'] : $sc['saeule']);
        $name = preg_replace('/[^A-Za-z0-9ÄÖÜäöüß_-]+/u', '-', 'ev-auswertung_' . $tag . '_' . $P['label'] . '_' . $mode) . '.csv';
        return ['filename' => $name, 'text' => "\xEF\xBB\xBF" . implode("\r\n", $L) . "\r\n", 'count' => count($L) - 1];
    }
}
