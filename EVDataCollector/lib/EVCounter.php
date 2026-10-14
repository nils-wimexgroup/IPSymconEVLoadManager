<?php

/**
 * Zaehlerlogik des EV Datensammlers (ohne Symcon-Abhaengigkeiten, damit testbar).
 *
 *  - MeterStep():   fortlaufender Zaehler aus einem echten Zaehlerstand (Alfen, kWh) mit
 *                   Plausibilitaetsfilter. Ruecksprung und unmoegliche Spruenge werden verworfen;
 *                   kommt mehrmals hintereinander ein neuer, in sich plausibler Stand
 *                   (Zaehlertausch), wird ohne Energiesprung neu angesetzt.
 *  - SessionStep(): fortlaufender Zaehler aus der Energie der laufenden Sitzung (ChargePilot, Wh).
 *                   Jeder Zuwachs wird addiert; wird der Wert kleiner oder kommt die Kennung
 *                   "keine Sitzung", beginnt eine neue Sitzung. Ist das Register doch ein
 *                   Gesamtzaehler, stimmt die Rechnung genauso.
 *
 * Zustand und Ergebnis sind einfache Arrays, damit sie als Attribut (JSON) gespeichert werden koennen.
 */
final class EVCounter
{
    public const NO_SESSION = 4294967295;   // ChargePilot: keine aktive Sitzung
    public const REBASE_AFTER = 5;          // so viele plausible neue Staende nacheinander = Zaehlertausch

    /**
     * @param array      $st    ['out' => float|null, 'raw' => float|null, 't' => int, 'cand' => float|null, 'rej' => int]
     * @param float|null $raw   aktueller Zaehlerstand in kWh (null = keine Daten)
     * @param int        $now   Zeitstempel der Messung
     * @param float      $maxKw hoechste plausible Leistung des Ladepunkts
     * @return array [neuer Zustand, Hinweis oder '']
     */
    public static function MeterStep(array $st, $raw, int $now, float $maxKw): array
    {
        $st += ['out' => null, 'raw' => null, 't' => 0, 'cand' => null, 'rej' => 0];
        if ($raw === null || !is_finite((float) $raw)) {
            return [$st, 'keine Daten'];
        }
        $raw = (float) $raw;
        if ($st['raw'] === null) {
            // erster Wert: der geprüfte Zähler startet beim echten Zählerstand
            $st['raw'] = $raw;
            $st['out'] = $st['out'] ?? $raw;
            $st['t'] = $now;
            return [$st, ''];
        }
        $dt = max(60, $now - (int) $st['t']);
        $limit = $maxKw * $dt / 3600.0 + 0.05;
        $delta = $raw - (float) $st['raw'];

        if ($delta >= -0.001 && $delta <= $limit) {
            $st['out'] = (float) $st['out'] + max(0.0, $delta);
            $st['raw'] = $raw;
            $st['t'] = $now;
            $st['cand'] = null;
            $st['rej'] = 0;
            return [$st, ''];
        }

        // verworfen: Ruecksprung oder unmoeglicher Sprung. Mehrere in sich plausible
        // neue Staende hintereinander bedeuten einen getauschten Zaehler.
        $candOk = $st['cand'] !== null && $raw >= (float) $st['cand'] - 0.001 && $raw - (float) $st['cand'] <= $limit;
        $st['rej'] = $candOk ? (int) $st['rej'] + 1 : 1;
        $st['cand'] = $raw;
        if ($st['rej'] >= self::REBASE_AFTER) {
            $st['raw'] = $raw;   // neu ansetzen, der geprüfte Zähler springt nicht
            $st['t'] = $now;
            $st['cand'] = null;
            $st['rej'] = 0;
            return [$st, 'Zählerwechsel erkannt, neu angesetzt'];
        }
        return [$st, $delta < 0 ? 'Rücksprung verworfen' : 'Sprung verworfen'];
    }

    /**
     * @param array    $st    ['out' => float, 'last' => float|null (kWh der laufenden Sitzung), 't' => int]
     * @param int|null $rawWh Sitzungsenergie in Wh, NO_SESSION oder negativ = keine Sitzung, null = keine Daten
     * @param int      $now
     * @param float    $maxKw
     * @return array [neuer Zustand, Hinweis oder '']
     */
    public static function SessionStep(array $st, $rawWh, int $now, float $maxKw): array
    {
        $st += ['out' => 0.0, 'last' => null, 't' => $now];
        if ($rawWh === null || !is_numeric($rawWh)) {
            return [$st, 'keine Daten'];
        }
        $rawWh = (float) $rawWh;
        if ($rawWh < 0 || $rawWh >= self::NO_SESSION) {
            $st['last'] = null;    // Sitzung beendet
            $st['t'] = $now;
            return [$st, ''];
        }
        $kwh = $rawWh / 1000.0;
        $dt = max(60, $now - (int) $st['t']);
        $limit = $maxKw * $dt / 3600.0 + 0.05;

        $note = '';
        if ($st['last'] === null || $kwh < (float) $st['last'] - 0.001) {
            // neue Sitzung: alles seit Sitzungsbeginn zählt
            $add = $kwh;
        } else {
            // laufende Sitzung: Zuwachs, höchstens so viel, wie in der Zeit seit dem letzten Wert möglich war
            $add = $kwh - (float) $st['last'];
            if ($add > $limit) {
                $add = $limit;
                $note = 'Zuwachs auf Höchstleistung begrenzt';
            }
        }
        $st['out'] = (float) $st['out'] + max(0.0, $add);
        $st['last'] = $kwh;
        $st['t'] = $now;
        return [$st, $note];
    }
}
