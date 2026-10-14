<?php

/**
 * Sammelt die Messungen einer Minute und macht daraus einen Minuten-Datensatz (ohne Symcon-Abhaengigkeiten).
 *
 * Datensatz: ['seq' => n, 't' => Minutenbeginn,
 *             'p' => [Kennung => [Zähler kWh (geprüft), belegt 0/1, Ø kW]],
 *             's' => [Säule => max kW], 'site' => max kW]
 */
final class EVMinute
{
    /**
     * @param array $acc     bisheriger Stand ['t' => Minutenbeginn, 'p' => [id => ['sum','n','b','e']], 's' => [sid => max], 'site' => max]
     * @param array $samples je Ladepunkt ['id','saeule','kw','belegt','counter'] (counter = geprüfter Zähler oder null)
     * @return array [neuer Stand, fertiger Datensatz oder null]
     */
    public static function Add(array $acc, int $now, array $samples, int $seq): array
    {
        $minute = $now - $now % 60;
        $done = null;
        if (isset($acc['t']) && $acc['t'] !== $minute && !empty($acc['p'])) {
            $done = self::Finish($acc, $seq);
        }
        if (!isset($acc['t']) || $acc['t'] !== $minute) {
            $acc = ['t' => $minute, 'p' => [], 's' => [], 'site' => 0.0];
        }
        $perSaeule = [];
        $siteKw = 0.0;
        foreach ($samples as $s) {
            $id = $s['id'];
            $p = $acc['p'][$id] ?? ['sum' => 0.0, 'n' => 0, 'b' => 0, 'e' => null];
            $p['sum'] += (float) $s['kw'];
            $p['n']++;
            $p['b'] = $s['belegt'] ? 1 : 0;
            if ($s['counter'] !== null) {
                $p['e'] = (float) $s['counter'];
            }
            $acc['p'][$id] = $p;
            $perSaeule[$s['saeule']] = ($perSaeule[$s['saeule']] ?? 0.0) + (float) $s['kw'];
            $siteKw += (float) $s['kw'];
        }
        foreach ($perSaeule as $sid => $kw) {
            $acc['s'][$sid] = max($acc['s'][$sid] ?? 0.0, $kw);
        }
        $acc['site'] = max((float) $acc['site'], $siteKw);
        return [$acc, $done];
    }

    public static function Finish(array $acc, int $seq): array
    {
        $p = [];
        foreach ($acc['p'] as $id => $v) {
            $p[$id] = [$v['e'] === null ? null : round($v['e'], 3), $v['b'], $v['n'] ? round($v['sum'] / $v['n'], 3) : 0.0];
        }
        $s = [];
        foreach ($acc['s'] as $sid => $kw) {
            $s[$sid] = round($kw, 3);
        }
        return ['seq' => $seq, 't' => (int) $acc['t'], 'p' => $p, 's' => $s, 'site' => round((float) $acc['site'], 3)];
    }
}
