<?php

/**
 * Belegung, Ladevorgaenge und Heatmap aus geloggten Ja/Nein-Werten (ohne Symcon-Abhaengigkeiten).
 * Eingabe: geloggte Werte aufsteigend sortiert, je ['TimeStamp' => int, 'Value' => bool|int],
 * dazu der Zustand unmittelbar vor dem Zeitraum.
 */
final class EVStats
{
    /**
     * Belegt-Intervalle innerhalb [$from, $to). Ein Intervall, das vor $from beginnt, wird abgeschnitten.
     * @return array [[start, ende, beganInZeitraum(bool)], …]
     */
    public static function Intervals(array $logged, bool $initial, int $from, int $to): array
    {
        $out = [];
        $on = $initial;
        $since = $from;
        $began = false;   // das laufende Intervall begann vor $from
        foreach ($logged as $r) {
            $t = (int) $r['TimeStamp'];
            $v = (bool) $r['Value'];
            if ($t < $from) {
                $on = $v;
                continue;
            }
            if ($t >= $to) {
                break;
            }
            if ($v && !$on) {
                $on = true;
                $since = $t;
                $began = true;
            } elseif (!$v && $on) {
                if ($t > $since) {
                    $out[] = [$since, $t, $began];
                }
                $on = false;
            }
        }
        if ($on && $to > $since) {
            $out[] = [$since, $to, $began];
        }
        return $out;
    }

    /** Zeitpunkte, an denen ein Ladevorgang begann (nein → ja) innerhalb [$from, $to) */
    public static function Starts(array $intervals): array
    {
        $s = [];
        foreach ($intervals as $iv) {
            if ($iv[2]) {
                $s[] = $iv[0];
            }
        }
        return $s;
    }

    public static function Seconds(array $intervals): int
    {
        $sum = 0;
        foreach ($intervals as $iv) {
            $sum += $iv[1] - $iv[0];
        }
        return $sum;
    }

    /**
     * Belegte Sekunden je Wochentag (0 = Mo) und Stunde (0–23), lokale Zeit.
     * @return array [7][24] Sekunden
     */
    public static function HourSeconds(array $intervals): array
    {
        $g = array_fill(0, 7, array_fill(0, 24, 0));
        foreach ($intervals as $iv) {
            $a = $iv[0];
            $b = $iv[1];
            while ($a < $b) {
                $h = (int) date('G', $a);
                $next = mktime($h + 1, 0, 0, (int) date('n', $a), (int) date('j', $a), (int) date('Y', $a));
                if ($next <= $a) {          // Sicherheitsnetz bei Zeitumstellung
                    $next = $a + 3600;
                }
                $end = min($b, $next);
                $g[(int) date('N', $a) - 1][$h] += $end - $a;
                $a = $end;
            }
        }
        return $g;
    }

    /** Belegte Sekunden innerhalb der Geschäftszeiten (Mo–Fr, $hFrom bis $hTo Uhr) */
    public static function BizSeconds(array $hourSeconds, int $hFrom, int $hTo): int
    {
        $sum = 0;
        for ($w = 0; $w < 5; $w++) {
            for ($h = $hFrom; $h < $hTo; $h++) {
                $sum += $hourSeconds[$w][$h];
            }
        }
        return $sum;
    }

    /** Anzahl Tage je Wochentag in [$from, $to) */
    public static function WeekdayCounts(int $from, int $to): array
    {
        $c = array_fill(0, 7, 0);
        for ($d = EVPeriod::Day0($from); $d < $to; $d = EVPeriod::AddDays($d, 1)) {
            $c[EVPeriod::Weekday($d)]++;
        }
        return $c;
    }

    /** Sekunden der Geschäftszeit eines Ladepunkts in [$from, $to) */
    public static function BizWindowSeconds(int $from, int $to, int $hFrom, int $hTo): int
    {
        $sum = 0;
        for ($d = EVPeriod::Day0($from); $d < $to; $d = EVPeriod::AddDays($d, 1)) {
            if (EVPeriod::Weekday($d) > 4) {
                continue;
            }
            $a = max($from, mktime($hFrom, 0, 0, (int) date('n', $d), (int) date('j', $d), (int) date('Y', $d)));
            $b = min($to, mktime($hTo, 0, 0, (int) date('n', $d), (int) date('j', $d), (int) date('Y', $d)));
            if ($b > $a) {
                $sum += $b - $a;
            }
        }
        return $sum;
    }
}
