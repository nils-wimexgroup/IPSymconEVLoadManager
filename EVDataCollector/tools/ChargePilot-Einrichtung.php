<?php

// ChargePilot-Einrichtung für den EV Datensammler
// ------------------------------------------------
// Legt den Ordner und die ModBus-Adressen an, die der EV Datensammler beim ChargePilot erwartet:
//   <Ordner> / Standort        Anzahl Ladepunkte (4108), Hauptsicherung (4112)
//   <Ordner> / <Säule> / <Ladepunkt>   Status, Leistung, Energie, optional Strom L1, Soll-Strom, Connector-ID
// Nur lesend: Lesefunktion 4 (Input Register), keine Schreibadresse.
//
// So nutzen:
//  1. Client Socket zum ChargePilot-Controller (über das VPN, Port laut The Mobility House, meist 502)
//     und darauf ein ModBus Gateway mit Geräte-ID 1 anlegen.
//  2. Dieses Skript in Symcon anlegen, die Einstellungen unten anpassen und einmal ausführen.
//     Ein zweiter Lauf legt nichts doppelt an.
//  3. Im EV Datensammler „Ordner einlesen“. Die angezeigten Connector-IDs mit den Ladepunkten vergleichen,
//     dann „Zuordnung übernehmen“.

// ---------------- Einstellungen
$GATEWAY = 0;                        // ID des ModBus Gateway zum ChargePilot
$ORDNER = 'Ladesäulen Regenstauf';
$PARENT = 0;                         // unter welchem Objekt der Ordner angelegt wird (0 = oberste Ebene)

// Ladepunkte in der Reihenfolge der Registerblöcke. Der ChargePilot sortiert nach Charger-ID und dann nach
// Ladepunktnummer. Stimmt die Reihenfolge nicht, zeigt „Ordner einlesen“ das an den Connector-IDs.
$LADEPUNKTE = [
    ['Säule 1', 'links'],
    ['Säule 1', 'rechts'],
];

$MIT_STROM = true;                   // Strom L1 für die Live-Übersicht
$MIT_SOLL = true;                    // aktuelles Stromangebot (Soll) für die Live-Übersicht
$MIT_CONNECTOR_ID = true;            // Connector-ID als Text zur Kontrolle der Zuordnung
$TAKT_MS = 5000;                     // Abfrage der Ladepunkte
$TAKT_STANDORT_MS = 3600000;         // Abfrage von Anzahl und Sicherung (stündlich)
// ---------------- Ende der Einstellungen

const MODBUS_ADDRESS = '{CB197E50-273D-4535-8C91-BB35273E3CA5}';
const UINT32 = 3;
const INT32 = 6;
const STRING_PLAIN = 10;

if (!$GATEWAY || !IPS_InstanceExists($GATEWAY)) {
    echo 'Bitte oben die ID des ModBus Gateway eintragen.';
    return;
}

function Ordner(int $parent, string $name): int
{
    foreach (IPS_GetChildrenIDs($parent) as $id) {
        if (IPS_GetObject($id)['ObjectType'] === 0 && IPS_GetName($id) === $name) {
            return $id;
        }
    }
    $id = IPS_CreateCategory();
    IPS_SetName($id, $name);
    IPS_SetParent($id, $parent);
    return $id;
}

function Adresse(int $parent, string $name, int $addr, int $type, int $poll, int $gateway, int $length = 0): string
{
    foreach (IPS_GetChildrenIDs($parent) as $id) {
        if (IPS_GetObject($id)['ObjectType'] === 1 && IPS_GetInstance($id)['ModuleInfo']['ModuleID'] === MODBUS_ADDRESS
            && (int) IPS_GetProperty($id, 'ReadAddress') === $addr) {
            return "  vorhanden: $name ($addr)\n";
        }
    }
    $id = IPS_CreateInstance(MODBUS_ADDRESS);
    IPS_SetName($id, $name);
    IPS_SetParent($id, $parent);
    IPS_SetProperty($id, 'DataType', $type);
    IPS_SetProperty($id, 'ReadFunctionCode', 4);    // Input Register
    IPS_SetProperty($id, 'ReadAddress', $addr);
    IPS_SetProperty($id, 'WriteFunctionCode', 0);   // nie schreiben
    IPS_SetProperty($id, 'WriteAddress', 0);
    IPS_SetProperty($id, 'ByteOrder', 0);           // Big-Endian, höherwertiges Wort zuerst
    IPS_SetProperty($id, 'Poller', $poll);
    if ($length > 0) {
        IPS_SetProperty($id, 'Length', $length);
    }
    IPS_ApplyChanges($id);
    if (IPS_GetInstance($id)['ConnectionID'] !== $gateway) {
        IPS_ConnectInstance($id, $gateway);
    }
    return "  angelegt:  $name ($addr)\n";
}

$root = Ordner($PARENT, $ORDNER);
$log = "Ordner: " . IPS_GetLocation($root) . "\n";
$site = Ordner($root, 'Standort');
$log .= Adresse($site, 'Anzahl Ladepunkte', 4108, UINT32, $TAKT_STANDORT_MS, $GATEWAY);
$log .= Adresse($site, 'Hauptsicherung', 4112, UINT32, $TAKT_STANDORT_MS, $GATEWAY);

foreach ($LADEPUNKTE as $n => [$saeule, $lp]) {
    $base = 12288 + $n * 256;
    $f = Ordner(Ordner($root, $saeule), $lp);
    $log .= "$saeule / $lp (Block " . ($n + 1) . ")\n";
    $log .= Adresse($f, 'Status', $base + 40, UINT32, $TAKT_MS, $GATEWAY);
    $log .= Adresse($f, 'Leistung', $base + 16, UINT32, $TAKT_MS, $GATEWAY);
    $log .= Adresse($f, 'Energie Sitzung', $base + 36, UINT32, $TAKT_MS, $GATEWAY);
    if ($MIT_STROM) {
        $log .= Adresse($f, 'Strom L1', $base + 18, INT32, $TAKT_MS, $GATEWAY);
    }
    if ($MIT_SOLL) {
        $log .= Adresse($f, 'Soll-Strom', $base + 86, UINT32, $TAKT_MS, $GATEWAY);
    }
    if ($MIT_CONNECTOR_ID) {
        $log .= Adresse($f, 'Connector-ID', $base, STRING_PLAIN, $TAKT_STANDORT_MS, $GATEWAY, 32);
    }
}
echo $log . "\nFertig. Jetzt im EV Datensammler „Ordner einlesen“.";
