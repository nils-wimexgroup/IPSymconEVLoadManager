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
  EVDataCollector/   EV Datensammler (Prefix EVDS): liest einen Standort, liefert an die Auswertung
    lib/             Zaehlerlogik, Quellen, Minuten-Datensaetze (ohne Symcon testbar)
    tools/           ChargePilot-Einrichtung.php: legt Ordner und ModBus-Adressen fuer den Datensammler an
  EVAnalytics/       EV Auswertung (Prefix EVAW): Archiv, Auswertungs-Kachel, CSV-Export
    lib/             Zeitraeume, Belegung, Bericht, Archivzugriff
  tests/             Tests ohne Symcon (php tests/run.php, php tests/sim/run_sim.php)
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

## EV Datensammler und EV Auswertung
Zwei weitere Module werten die Ladesaeulen ueber laengere Zeit aus: Energie,
Ladevorgaenge, Auslastung (belegt und davon aktiv geladen) mit Heatmap, Leistungsspitzen gegen Budget bzw.
Saeulen-Limit und CSV-Export, wahlweise fuer alle Standorte, einen Standort oder
eine Saeule.

**EV Datensammler** (eine Instanz je Standort)
- Quelle **EVLM-Instanz**: Saeulen, Ladepunkte, Variablen, Budget und Limits
  kommen aus deren Konfiguration.
- Quelle **ChargePilot**: Ordner mit normalen Symcon-ModBus-Instanzen
  (ModBus Address oder ModBus Device), je Saeule ein Unterordner, darin je
  Ladepunkt ein Unterordner. Pflicht je Ladepunkt: Status (12328), Leistung
  (12304), Energie (12324); optional Strom L1 (12306) und Soll-Strom (12374).
  Jeder weitere Ladepunkt: Adresse + 256. Optional im Unterordner `Standort`:
  4108 (Anzahl Ladepunkte) und 4112 (Hauptsicherung, ergibt das Budget).
  Lesefunktion 4, ByteOrder 0 (Big-Endian), keine Schreibadresse.
  „Ordner einlesen“ erkennt Ladepunkt und Wert an der Leseadresse, „Zuordnung
  uebernehmen“ legt sie fest. Aendert der ChargePilot die Zahl der Ladepunkte,
  haelt der Datensammler an (Status 205), bis neu eingelesen wird.
- Fuehrt je Ladepunkt einen geprueften Zaehler (Ausreisser und Zaehlertausch
  werden abgefangen) und schickt jede Minute einen Datensatz sowie den
  Live-Stand. Ungelieferte Datensaetze bleiben im Puffer und werden nachgeholt.
- „Alte Historie uebernehmen“ uebergibt die bisher archivierten Zaehlerstaende
  des EVLM einmalig an die Auswertung; ein zweiter Lauf verdoppelt nichts.
- Kennungen wie `ADF-LS01-1` haengen an der Statusvariable, nicht an der
  Listenposition. Umsortieren vermischt keine Historie.

**EV Auswertung** (eine Instanz, z. B. in der Zentrale)
- Legt je Standort eine Kategorie mit archivierten Variablen an: je Ladepunkt
  Zaehler (Aggregation Zaehler), Belegt, Leistung und Laedt aktiv, dazu Leistung
  je Saeule und Standort.
- Auslastung: belegt = Fahrzeug angesteckt; davon aktiv geladen = Status „Laedt“
  wie im Lastmanagement und mindestens 0,1 kW. Beides haelt der Datensammler bei
  kurzen Verbindungsausfaellen. Fuer Daten von vor Version 1.2 rechnet die
  Auswertung „aktiv geladen“ einmalig aus Belegt und Leistung nach (beim
  Uebernehmen vorgemerkt, beim Aufraeumen drei Ladepunkte je Minute).
- Kachel mit Zeitraum (Woche, Monat, Jahr, 12 Monate), Auswahl und CSV-Export.
  Der CSV-Link laeuft ueber `/hook/evauswertung/csv` und gilt 5 Minuten.
- Geschaeftszeiten fuer die Auslastung einstellbar (Standard Mo-Fr 7-18 Uhr).
- Zaehlervariablen ignorieren Nullwerte in der Aggregation (AC_SetCounterIgnoreZeros), sonst
  zaehlte der beim Anlegen geloggte Anfangswert 0 den ganzen Zaehlerstand als Verbrauch.
  Bestehende Variablen werden beim Uebernehmen umgestellt und neu aggregiert; Knopf
  „Zaehlervariablen neu aggregieren“ fuer den Fall unplausibler Energiewerte.

Oeffentliche Funktionen: `EVDS_Sample`, `EVDS_ScanFolder`, `EVDS_ApplyScan`,
`EVDS_ImportHistory`, `EVDS_TestDelivery`, `EVAW_Ingest`, `EVAW_Housekeeping`.

## Tests
Die Rechenteile und beide Module laufen ohne Symcon:
```
php tests/run.php            Einzeltests (Zaehler, Zeitraeume, Belegung, Bericht, Quellen)
php tests/sim/run_sim.php    zwei Tage Betrieb im Symcon-Nachbau (EVLM + ChargePilot)
php tests/preview.php        Kachel mit Testdaten nach tests/out/tile-preview.html
```

## Hinweis
Erstellt fuer eine Alfen Eve Double Pro-line (Modbus TCP), aber
herstellerneutral. Sicherheitsrelevante Grenzwerte (Budget) muss ein
Elektriker freigeben. Ohne Gewaehr - vor Produktivbetrieb testen
(klein anfangen, Verhalten beobachten).
