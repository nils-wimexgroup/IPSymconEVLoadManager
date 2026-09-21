# EV Lastmanagement (IP-Symcon Modul)

Dynamisches Lastmanagement (Water-Filling) fuer mehrere Ladepunkte in IP-Symcon,
mit eigener Kachel-Visualisierung fuer Gesamtbudget und jeden einzelnen Ladepunkt.

Herstellerneutral: das Modul liest vorhandene Symcon-Variablen (z. B. deine
Alfen-Modbus-Variablen) und schreibt die Sollwerte per `RequestAction` zurueck.

## Struktur
```
IPSymconEVLoadManager/
  library.json
  EVLoadManager/
    module.json      Modul-Definition (Prefix EVLM, Typ 3/Device)
    module.php       Regel-Logik + Variablen + HTML-SDK-Kachel
    form.json        Konfigurationsformular
    locale.json      Uebersetzungen
    module.html      Visualisierungs-Kachel (HTML SDK)
```

## Installation
1. Ordner `IPSymconEVLoadManager` als Git-Repo pushen ODER lokal ablegen.
2. In IP-Symcon: Kern-Instanzen -> **Modules** (Module Control) -> **Hinzufuegen**
   -> Git-URL (oder bei lokaler Ablage den Pfad) angeben.
   Fuer lokale Entwicklung kann der Ordner auch direkt in das Symcon-
   `modules`-Verzeichnis kopiert werden (Symcon danach neu laden).
3. Neue Instanz anlegen: **EV Lastmanagement**.

## Konfiguration
- **Gesamtbudget pro Phase (A):** Dauer-Belastbarkeit des Lade-Stromkreises
  minus Reserve (NICHT der Hausanschluss). Vom Elektriker bestaetigen lassen.
- **Mindeststrom:** 6 A (darunter laedt kein Auto).
- **Regelintervall:** 15-30 s empfohlen.
- **Hysterese / Headroom:** Headroom MUSS groesser als Hysterese sein
  (Standard 2 A / 1 A), sonst pendelt ein selbstbegrenzendes Auto.
- **Watchdog Valid-Time:** wenn > 0, wird die Alfen-Valid-Time-Variable
  zyklisch aufgefrischt (Failsafe). 0 = aus.
- **Ladepunkte (Liste):** pro Ladepunkt die Symcon-Variablen waehlen:
  - Status (Alfen Reg 1201, String wie `C2`/`B2`) - optional; fehlt sie,
    wird "laedt" aus dem Ist-Strom (> 0,5 A) abgeleitet.
  - Strom L1/L2/L3 (Alfen Reg 320/322/324, Float).
  - **Sollwert (Alfen Reg 1210, Float, schreibbar!)** - Aktion muss aktiv sein.
  - Watchdog (Alfen Reg 1208) - optional.

## Visualisierung
Die Instanz bietet eine Kachel (HTML SDK): Gesamtbudget-Balken mit Auslastung
und Gesamtleistung, darunter je Ladepunkt ein Balken (Ist gefuellt, Soll als
Markierung) mit Status-Badge und Leistung. Zusaetzlich stehen alle Werte als
Variablen (Budget, Zugeteilt, Frei, aktive Ladepunkte, Leistung sowie je
Ladepunkt Status/Sollwert/Leistung) fuer eigene Visualisierungen bereit.

## Oeffentliche Funktion
```php
EVLM_Balance(<InstanceID>);   // eine Regelrunde sofort ausfuehren
```

## Hinweis
Erstellt fuer eine Alfen Eve Double Pro-line (Modbus TCP), aber
herstellerneutral. Sicherheitsrelevante Grenzwerte (Budget) muss ein
Elektriker freigeben. Ohne Gewaehr - vor Produktivbetrieb testen
(klein anfangen, Verhalten beobachten).
