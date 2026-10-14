<?php

/**
 * Erzeugt tests/out/tile-preview.html: die Auswertungs-Kachel mit Testdaten, ohne Symcon.
 * Alle Kombinationen aus Zeitraum und Auswahl werden vorab berechnet; ein Ersatz fuer
 * requestAction beantwortet die Klicks daraus.
 * Aufruf: php tests/preview.php
 */
declare(strict_types=1);
date_default_timezone_set('Europe/Berlin');
require __DIR__ . '/../EVAnalytics/lib/EVPeriod.php';
require __DIR__ . '/../EVAnalytics/lib/EVStats.php';
require __DIR__ . '/../EVAnalytics/lib/EVReport.php';
require __DIR__ . '/fixtures.php';

$biz = ['from' => 7, 'to' => 18];
$views = [];
$scopes = ["all", "site:ADF", "site:RGST", "saeule:ADF:ADF-LS01", "saeule:RGST:RGST-LS01"];
foreach (EVPeriod::TYPES as $type) {
    for ($off = 0; $off >= -1; $off--) {
        foreach ($scopes as $sc) {
            $d = EVReport::Build($meta, $r, $type, $off, $sc, $now, $biz);
            $views[$type . '|' . $off . '|' . $sc] = $d;
            if (!$d['canPrev']) {
                continue 2;
            }
        }
    }
}
$init = ['ev' => 'report', 'data' => $views['monat|0|all']];
$tile = file_get_contents(__DIR__ . '/../EVAnalytics/module.html');
$tile = str_replace('%%INITIAL%%', json_encode($init), $tile);

$stub = '<script>
var EV_VIEWS = ' . json_encode($views) . ';
function requestAction(ident, value) {
  var v = JSON.parse(value);
  setTimeout(function () {
    if (ident === "View") {
      var d = EV_VIEWS[v.type + "|" + v.off + "|" + v.scope];
      handleMessage(JSON.stringify(d ? { ev: "report", cid: v.cid, data: d } : { ev: "csv", cid: v.cid, error: "keine Vorschau für diese Ansicht" }));
      handleMessage(JSON.stringify({ ev: "report", cid: "fremde-kachel", data: EV_VIEWS["jahr|0|all"] }));   // muss ignoriert werden
    } else if (ident === "Csv") {
      handleMessage({ ev: "csv", cid: v.cid, url: "#csv-" + v.mode, lines: 42 });
    }
  }, 150);
}
</script>';

$page = '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Vorschau EV Auswertung</title>
<style>:root{--card-color:#ffffff;--content-color:#1f2933;--accent-color:#2F80ED}
@media (prefers-color-scheme: dark){:root{--card-color:#2b2d31;--content-color:#f1f3f5}}
body{margin:0;padding:16px;background:var(--card-color)}</style>' . $stub . '</head><body>' . $tile . '</body></html>';

@mkdir(__DIR__ . '/out');
file_put_contents(__DIR__ . '/out/tile-preview.html', $page);
echo 'Vorschau mit ' . count($views) . ' Ansichten: tests/out/tile-preview.html (' . round(strlen($page) / 1024) . " KB)\n";
