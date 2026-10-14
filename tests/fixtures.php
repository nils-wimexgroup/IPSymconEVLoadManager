<?php

/** Archiv-Ersatz mit fest vorgegebenen Daten */
final class StubReader
{
    public $energy = [];   // varE => [Tag => kWh]
    public $max = [];      // varP => [Tag => kW]
    public $logs = [];     // varB => aufsteigend [['TimeStamp','Value']]

    public function DailyEnergy(int $v, int $from, int $to): array
    {
        return array_filter($this->energy[$v] ?? [], function ($d) use ($from, $to) {
            return $d >= $from && $d < $to;
        }, ARRAY_FILTER_USE_KEY);
    }

    public function DailyMax(int $v, int $from, int $to): array
    {
        return array_filter($this->max[$v] ?? [], function ($d) use ($from, $to) {
            return $d >= $from && $d < $to;
        }, ARRAY_FILTER_USE_KEY);
    }

    public function Logged(int $v, int $from, int $to): array
    {
        return array_values(array_filter($this->logs[$v] ?? [], function ($r) use ($from, $to) {
            return $r['TimeStamp'] >= $from && $r['TimeStamp'] < $to;
        }));
    }

    public function StateBefore(int $v, int $ts): bool
    {
        $s = false;
        foreach ($this->logs[$v] ?? [] as $r) {
            if ($r['TimeStamp'] < $ts) {
                $s = (bool) $r['Value'];
            }
        }
        return $s;
    }
}

$now = mktime(12, 0, 0, 10, 14, 2026);
$meta = [
    'ADF' => ['name' => 'Arensdorf', 'sourceLabel' => 'EVLM', 'budgetKw' => 13.8, 'varP' => 501,
        'histSince' => mktime(0, 0, 0, 1, 1, 2025), 'detailSince' => mktime(0, 0, 0, 10, 1, 2026),
        'saeulen' => [['id' => 'ADF-LS01', 'name' => 'Säule 1', 'limitKw' => 13.8, 'varP' => 401, 'points' => [
            ['id' => 'ADF-LS01-1', 'name' => 'links', 'varE' => 101, 'varB' => 201, 'varP' => 301],
            ['id' => 'ADF-LS01-2', 'name' => 'rechts', 'varE' => 102, 'varB' => 202, 'varP' => 302]]]]],
    'RGST' => ['name' => 'Regenstauf', 'sourceLabel' => 'ChargePilot', 'budgetKw' => 22.1, 'varP' => 511,
        'note' => 'Regenstauf: ChargePilot per Modbus über VPN.',
        'histSince' => mktime(0, 0, 0, 10, 5, 2026), 'detailSince' => mktime(0, 0, 0, 10, 5, 2026),
        'saeulen' => [['id' => 'RGST-LS01', 'name' => 'Säule 1', 'limitKw' => null, 'varP' => 411, 'points' => [
            ['id' => 'RGST-LS01-1', 'name' => 'links', 'varE' => 111, 'varB' => 211, 'varP' => 311]]]]],
];
$r = new StubReader();
for ($d = mktime(0, 0, 0, 1, 1, 2025); $d <= EVPeriod::Day0($now); $d = EVPeriod::AddDays($d, 1)) {
    $r->energy[101][$d] = 10.0;
    $r->energy[102][$d] = 5.0;
    if ($d >= mktime(0, 0, 0, 10, 5, 2026)) {
        $r->energy[111][$d] = 20.0;
        $r->max[511][$d] = 10.0;
    }
    if ($d >= mktime(0, 0, 0, 10, 1, 2026)) {
        $r->max[501][$d] = $d === mktime(0, 0, 0, 10, 2, 2026) ? 13.8 : 7.0;
        $r->max[401][$d] = $d === mktime(0, 0, 0, 10, 2, 2026) ? 13.8 : 7.0;
        $r->logs[201][] = ['TimeStamp' => $d + 8 * 3600, 'Value' => true];
        $r->logs[201][] = ['TimeStamp' => $d + 12 * 3600, 'Value' => false];
    }
    if ($d >= mktime(0, 0, 0, 10, 5, 2026)) {
        $r->logs[211][] = ['TimeStamp' => $d + 9 * 3600, 'Value' => true];
        $r->logs[211][] = ['TimeStamp' => $d + 10 * 3600, 'Value' => false];
    }
}
