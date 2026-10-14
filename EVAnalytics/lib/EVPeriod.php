<?php

/**
 * Zeitraeume der Auswertung (ohne Symcon-Abhaengigkeiten, damit testbar).
 * Typen: woche, monat, jahr, 12m. Offset 0 = aktueller Zeitraum, -1 = davor usw.
 * Alle Grenzen sind lokale Mitternacht (mktime), damit Sommer-/Winterzeit stimmt.
 */
final class EVPeriod
{
    public const TYPES = ['woche', 'monat', 'jahr', '12m'];
    public const MON = ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];
    public const MONL = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
    public const WD = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];

    public static function Day0(int $ts): int
    {
        return mktime(0, 0, 0, (int) date('n', $ts), (int) date('j', $ts), (int) date('Y', $ts));
    }

    public static function AddDays(int $ts, int $n): int
    {
        return mktime(0, 0, 0, (int) date('n', $ts), (int) date('j', $ts) + $n, (int) date('Y', $ts));
    }

    public static function Month0(int $y, int $m): int
    {
        return mktime(0, 0, 0, $m, 1, $y);   // mktime rechnet Monate ausserhalb 1..12 selbst um
    }

    /** Wochentag 0 = Montag … 6 = Sonntag */
    public static function Weekday(int $ts): int
    {
        return (int) date('N', $ts) - 1;
    }

    private static function dm(int $ts): string
    {
        return date('j', $ts) . '.' . date('n', $ts) . '.';
    }

    /**
     * @param int $today0 lokale Mitternacht von heute
     * @return array ['type','off','start','end','label','unit','partial','buckets'=>[['s','e','x','t']]]
     */
    public static function Build(string $type, int $off, int $today0): array
    {
        if (!in_array($type, self::TYPES, true)) {
            $type = 'monat';
        }
        $b = [];
        $y = (int) date('Y', $today0);
        $m = (int) date('n', $today0);
        if ($type === 'woche') {
            $start = self::AddDays($today0, -self::Weekday($today0) + 7 * $off);
            $end = self::AddDays($start, 7);
            $unit = 'Tag';
            $label = 'KW ' . (int) date('W', $start) . ' · ' . self::dm($start) . '–' . self::dm(self::AddDays($start, 6)) . date('Y', self::AddDays($start, 6));
            for ($i = 0; $i < 7; $i++) {
                $d = self::AddDays($start, $i);
                $b[] = ['s' => $d, 'e' => self::AddDays($d, 1), 'x' => self::WD[$i], 't' => self::WD[$i] . ', ' . self::dm($d) . date('Y', $d)];
            }
        } elseif ($type === 'monat') {
            $start = self::Month0($y, $m + $off);
            $end = self::Month0((int) date('Y', $start), (int) date('n', $start) + 1);
            $unit = 'Tag';
            $label = self::MONL[(int) date('n', $start) - 1] . ' ' . date('Y', $start);
            for ($d = $start; $d < $end; $d = self::AddDays($d, 1)) {
                $b[] = ['s' => $d, 'e' => self::AddDays($d, 1), 'x' => date('j', $d), 't' => self::WD[self::Weekday($d)] . ', ' . self::dm($d) . date('Y', $d)];
            }
        } elseif ($type === 'jahr') {
            $start = mktime(0, 0, 0, 1, 1, $y + $off);
            $end = mktime(0, 0, 0, 1, 1, $y + $off + 1);
            $unit = 'Monat';
            $label = (string) ($y + $off);
            for ($k = 1; $k <= 12; $k++) {
                $b[] = ['s' => self::Month0($y + $off, $k), 'e' => self::Month0($y + $off, $k + 1), 'x' => self::MON[$k - 1], 't' => self::MONL[$k - 1] . ' ' . ($y + $off)];
            }
        } else {
            $end = self::Month0($y, $m + 1 + $off);
            $start = self::Month0((int) date('Y', $end), (int) date('n', $end) - 12);
            $unit = 'Monat';
            $last = self::Month0((int) date('Y', $end), (int) date('n', $end) - 1);
            $label = self::MON[(int) date('n', $start) - 1] . ' ' . date('Y', $start) . ' – ' . self::MON[(int) date('n', $last) - 1] . ' ' . date('Y', $last);
            for ($k = 0; $k < 12; $k++) {
                $s = self::Month0((int) date('Y', $start), (int) date('n', $start) + $k);
                $e = self::Month0((int) date('Y', $s), (int) date('n', $s) + 1);
                $b[] = ['s' => $s, 'e' => $e, 'x' => self::MON[(int) date('n', $s) - 1], 't' => self::MONL[(int) date('n', $s) - 1] . ' ' . date('Y', $s)];
            }
        }
        return [
            'type' => $type, 'off' => $off, 'start' => $start, 'end' => $end, 'label' => $label, 'unit' => $unit,
            'partial' => $start <= $today0 && $today0 < $end, 'buckets' => $b
        ];
    }

    /**
     * Vergleichszeitraum. Laeuft der Zeitraum noch, wird nur die gleiche Anzahl Tage verglichen.
     */
    public static function Previous(array $p, int $today0): array
    {
        $step = ['woche' => -1, 'monat' => -1, 'jahr' => -1, '12m' => -12][$p['type']];
        $q = self::Build($p['type'], $p['off'] + $step, $today0);
        if ($p['partial']) {
            $days = (int) round((self::AddDays($today0, 1) - $p['start']) / 86400);
            $q['end'] = self::AddDays($q['start'], $days);
        }
        return $q;
    }

    /** Text hinter der Veränderung, z. B. "▲ +12 % zum Vormonat" */
    public static function CompareText(array $p): string
    {
        if ($p['partial']) {
            return ['woche' => 'zum gleichen Zeitraum der Vorwoche', 'monat' => 'zum gleichen Zeitraum im Vormonat',
                'jahr' => 'zum gleichen Zeitraum im Vorjahr', '12m' => 'zu den 12 Monaten davor'][$p['type']];
        }
        return ['woche' => 'zur Vorwoche', 'monat' => 'zum Vormonat', 'jahr' => 'zum Vorjahr', '12m' => 'zu den 12 Monaten davor'][$p['type']];
    }
}
