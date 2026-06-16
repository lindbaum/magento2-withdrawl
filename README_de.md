# Widerrufsbutton für Magento 2

> Magento 2 Erweiterung zur Umsetzung des EU-Widerrufsrechts per Button-Klick.
> Entwickelt von **Zwernemann Medienentwicklung**.

---

## Worum geht es?

Die EU-Richtlinie **(EU) 2023/2673** schreibt vor, dass Verbraucher Online-Kaufvertraege künftig genauso einfach widerrufen koennen muessen, wie sie abgeschlossen wurden. **Ab dem 19. Juni 2026** ist ein gut sichtbarer Widerrufsbutton in Online-Shops Pflicht.

Dieses Magento 2 Modul liefert genau das: Ihre Kunden koennen Bestellungen mit wenigen Klicks widerrufen -- direkt aus dem Kundenkonto oder ueber ein separates Formular fuer Gastbestellungen. Sie als Shopbetreiber behalten dabei den vollen Ueberblick im Adminbereich.

---

## Was macht das Modul?

### Für Ihre Kunden

**Widerrufsbutton in der Bestelluebersicht**

In der Ansicht *Mein Konto > Meine Bestellungen* erscheint pro Bestellung eine neue Spalte. Dort sieht der Kunde auf einen Blick:

- Einen **Widerrufs-Link**, solange die Frist laeuft
- Den Hinweis **"Widerruf eingereicht"**, falls bereits widerrufen wurde
- Den Hinweis **"Frist abgelaufen"**, wenn die Widerrufsfrist verstrichen ist

Zusätzlich wird auf der Bestelldetailseite ein **"Bestellung widerrufen"**-Button angezeigt.

**Widerrufs-Detailseite**

Vor dem eigentlichen Widerruf sieht der Kunde eine Zusammenfassung seiner Bestellung:

- Bestellnummer, Datum, Status, Gesamtbetrag
- Alle bestellten Positionen mit Name, Artikelnummer, Menge und Preis
- Bis wann der Widerruf moeglich ist (berechnet ab Versanddatum der letzten Lieferung)
- Einen Button zum endgueltigen Absenden -- mit vorgeschalteter Sicherheitsabfrage

**Gastbestellungen**

Kunden, die ohne Kundenkonto bestellt haben, erreichen den Widerruf ueber ein eigenes Suchformular. Dort genuegen Bestellnummer und E-Mail-Adresse, um die Bestellung zu finden und den Widerruf einzuleiten.

Erreichbar unter: `/withdrawal/guest/search`

**Bestaetigungsseite**

Nach dem Absenden wird der Kunde auf eine Erfolgsseite weitergeleitet. Dort wird bestaetigt, dass der Widerruf eingegangen ist und eine E-Mail unterwegs ist.

### Für Sie als Shopbetreiber

**Admin-Uebersicht aller Widerrufe**

Unter *Verkäufe > Withdrawals* finden Sie eine tabellarische Uebersicht saemtlicher eingegangener Widerrufe:

- ID, Bestellnummer, Kundenname, E-Mail
- Status (Ausstehend / Bestaetigt / Abgelehnt)
- Widerrufstyp (Vollstaendig / Teilweise)
- Datum der Bestellung und Datum des Widerrufs
- Direktlink zur jeweiligen Bestellansicht

Alle Spalten sind filterbar und sortierbar.

**Detailansicht der widerrufenen Artikel**

Ueber den **"View"**-Link in der Actions-Spalte gelangen Sie zur Detailansicht eines Widerrufs. Dort sehen Sie:

- Alle Widerrufsinformationen (ID, Status, Typ, Erstellungsdatum, Kommentar)
- Bestelldetails mit verlinkter Bestellnummer (fuehrt direkt zur Order-View)
- Kundendaten (Name, E-Mail)
- **Tabelle aller widerrufenen Artikel** mit Produktname, SKU und widerrufener Menge

Diese Ansicht gibt Ihnen einen vollstaendigen Ueberblick darueber, welche Produkte in welcher Menge widerrufen wurden. Ein Zurueck-Button fuehrt Sie zur Widerrufsliste zurueck.

**Automatische Benachrichtigung per E-Mail**

Sobald ein Widerruf eingeht, werden zwei E-Mails verschickt:

1. **An den Kunden** -- Bestaetigung mit Bestelldetails
2. **An Sie** -- Benachrichtigung mit allen relevanten Daten

Zusätzlich erhalten Sie eine BCC-Kopie der Kundenmail. Die E-Mail-Vorlagen lassen sich im Admin anpassen.

**Vermerk in der Bestellung**

Jeder Widerruf wird automatisch als Kommentar in der Bestellhistorie hinterlegt. So ist auch in der Bestellansicht sofort ersichtlich, dass ein Widerruf vorliegt.

**Konfigurierbar**

Im Admin unter *Stores > Configuration > Sales > Withdrawal Settings*:

- Modul ein- und ausschalten
- Empfaenger-Adresse fuer Benachrichtigungen festlegen
- Widerrufsfrist in Tagen einstellen, gezaehlt ab Versanddatum der letzten Lieferung (Standard: 14)
- E-Mail-Absender und Vorlagen waehlen
- **Ausgeschlossene Produktattribute konfigurieren** (siehe unten)

---

## Produkte vom Widerruf ausschließen

### Übersicht

Bestimmte Produkte können nach EU-Recht nicht widerrufen werden (z.B. personalisierte Artikel, verderbliche Waren, maßgefertigte Produkte). Dieses Modul ermöglicht den Ausschluss von Produkten vom Widerruf basierend auf Produktattributen.

### Konfiguration

1. Navigieren Sie zu **Stores > Configuration > Sales > Withdrawal Settings**
2. Geben Sie im Feld **"Excluded Product Attributes"** eine kommaseparierte Liste von Produktattribut-Codes ein
3. Beispiel: `is_personalized,is_perishable,custom_made`

### Funktionsweise

- Produkte mit einem der konfigurierten Attribute auf `Yes`, `1` oder `true` werden **vom Widerruf ausgeschlossen**
- Wenn eine Bestellung sowohl widerrufbare als auch nicht-widerrufbare Artikel enthält, können Kunden einen **Teilwiderruf** durchführen
- Kunden können **mehrere Teilwiderrufe** für dieselbe Bestellung einreichen, bis alle widerrufbaren Artikel widerrufen wurden
- Der Button-Text ändert sich nach einem Teilwiderruf zu **"Weitere Artikel widerrufen"**

### Teilwiderruf

Wenn eine Bestellung gemischte Artikel enthält:

- **Widerrufbare Artikel** werden normal angezeigt und können widerrufen werden
- **Nicht widerrufbare Artikel** werden in einem separaten Bereich mit dem Hinweis angezeigt: "Dieses Produkt kann nicht widerrufen werden"
- **Bereits widerrufene Artikel** (aus vorherigen Teilwiderrufen) werden durchgestrichen mit Badge dargestellt

E-Mails für Teilwiderrufe enthalten:
- Liste der widerrufenen Artikel
- Liste der nicht widerrufbaren Artikel
- Widerrufstyp (Vollständig/Teilweise)
- Artikel-Anzahl (X von Y)

**E-Mail-Vorlagen für Teilwiderrufe/Updates:**
- Für neue Widerrufe werden die Standard-Vorlagen verwendet
- Für Teilwiderrufe/Updates werden automatisch spezielle `*_update_template` Varianten ausgewählt:
  - `zwernemann_withdrawal_email_customer_update_template` - An Kundin/Kunde auf Update
  - `zwernemann_withdrawal_email_admin_update_template` - An Admin auf Update
- Vorlagen können unter System > Email-Vorlagen angepasst werden

### Admin-Grid

Die Widerrufsübersicht (*Verkäufe > Withdrawals*) zeigt:
- **Widerrufstyp**-Spalte: Vollständiger Widerruf oder Teilwiderruf
- **Widerrufene Artikel**-Spalte: Anzahl der widerrufenen Artikel
- Filterbar und sortierbar nach beiden Spalten

### Debug-Logging

Wenn ein konfiguriertes Attribut auf einem Produkt nicht existiert, wird ein Debug-Log-Eintrag mit Details über das fehlende Attribut erstellt. Prüfen Sie `var/log/debug.log` bei vermuteten Konfigurationsproblemen.

## Hyvä-Theme-Kompatibilität

Wenn Sie das Hyvä-Theme verwenden, installieren Sie bitte das Hyvä-Kompatibilitätsmodul:

https://github.com/lindbaum/module-withdrawal-hyva

Dieses Modul ergänzt die erforderliche Hyvä-Frontend-Integration für den Widerrufs-Button und stellt die Kompatibilität mit dem Hyvä-Template-System sicher.

Das Basismodul bleibt weiterhin erforderlich.

---

## Hyvä-Theme-Kompatibilität

Wenn Sie das Hyvä-Theme verwenden, installieren Sie bitte das Hyvä-Kompatibilitätsmodul:

https://github.com/Zwernemann/magento2-withdrawl-hyva

Dieses Modul ergänzt die erforderliche Hyvä-Frontend-Integration für den Widerrufs-Button und stellt die Kompatibilität mit dem Hyvä-Template-System sicher.

Das Basismodul bleibt weiterhin erforderlich.

### REST API

Der REST API-Endpunkt `/V1/zwernemann/withdrawals` enthält nun:
- `withdrawn_items`: Array der widerrufenen Artikel-IDs
- `withdrawal_type`: "full" oder "partial"
- `withdrawn_item_count`: Anzahl der widerrufenen Artikel

---

Widerrufseintaege lassen sich auch programmatisch abrufen:

```bash
GET /rest/V1/zwernemann/withdrawals
```

Zugriff ist per ACL-Berechtigung geschuetzt (`Zwernemann_Withdrawal::withdrawals`).

### Mehrsprachigkeit

Komplett uebersetzt in alle offiziellen 24 Sprachen der EU (je 97 Zeichenketten). Weitere Sprachen koennen ueber eigene CSV-Dateien ergaenzt werden.

---

## Systemvoraussetzungen

| Komponente | Version |
|---|---|
| Magento 2 Open Source | 2.4.6 bis 2.4.8-p4 |
| PHP | 7.4 oder hoeher |


---

## Installation

### Per ZIP-Datei

1. Entpacken Sie die ZIP-Datei und kopieren Sie den gesamten Inhalt nach:

   ```
   app/code/Zwernemann/Withdrawal/
   ```

   Kontrollieren Sie, dass die Struktur so aussieht:

   ```
   app/code/Zwernemann/Withdrawal/
       Api/
       Block/
       Controller/
       Helper/
       Model/
       Ui/
       etc/
       i18n/
       view/
       composer.json
       registration.php
   ```

2. Fuehren Sie folgende Befehle im Magento-Root aus:

   ```bash
   php bin/magento setup:upgrade
   php bin/magento setup:di:compile
   php bin/magento setup:static-content:deploy de_DE en_US
   php bin/magento cache:flush
   ```

3. Pruefen Sie, ob das Modul aktiv ist:

   ```bash
   php bin/magento module:status Zwernemann_Withdrawal
   ```

### Per Composer

1. Fügen Sie das Repository zu Ihrer `composer.json` hinzu:

```json
"repositories": {
    "zwernemann": {
        "type": "vcs",
        "url": "https://github.com/lindbaum/magento2-withdrawl",
        "only": ["zwernemann/module-withdrawal"]
    }
}
```

2. Installieren Sie das Modul:

```bash
composer require zwernemann/module-withdrawal
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento setup:static-content:deploy de_DE en_US
php bin/magento cache:flush
```

---

## Einrichtung

1. Im Magento Admin einloggen
2. Zu **Stores > Configuration > Sales > Withdrawal Settings** navigieren
3. **Modul aktivieren** auf *Ja* setzen
4. **Benachrichtigungs-E-Mail** eintragen -- hierhin gehen die Widerrufs-Meldungen
5. **Widerrufsfrist** anpassen, falls die gesetzliche Frist abweicht
6. Bei Bedarf E-Mail-Absender und Vorlagen konfigurieren
7. Speichern und Cache leeren

### Gastbestellungs-Formular verlinken

Das Suchformular fuer Gastbestellungen liegt unter:

```
https://www.ihr-shop.de/withdrawal/guest/search
```

Binden Sie diesen Link z.B. hier ein:

- Im Footer Ihres Shops
- In Bestellbestaetigungs-E-Mails
- Auf Ihrer Widerrufsbelehrungs-Seite

Mit Magento URL-Rewrites koennen Sie die Adresse beliebig anpassen, etwa auf `/widerruf`.

---

## Deinstallation

```bash
php bin/magento module:disable Zwernemann_Withdrawal
php bin/magento setup:upgrade
php bin/magento setup:di:compile
php bin/magento cache:flush
```

Danach das Verzeichnis `app/code/Zwernemann/Withdrawal/` loeschen.

Die Datenbanktabelle `zwernemann_withdrawal` bleibt erhalten und kann bei Bedarf manuell entfernt werden.

---

## Über diese Version

**v1.7.0** - 16. Juni 2026

Diese Version fügt eine **Detailansicht für Widerrufe** im Admin-Bereich hinzu:

- ✅ Neue "View"-Aktion im Admin-Grid
- ✅ Detailseite mit vollständigen Widerrufsinformationen
- ✅ Tabelle aller widerrufenen Artikel mit Produktname, SKU und Menge
- ✅ Verlinkte Bestellnummer zur direkten Order-Ansicht
- ✅ Zurück-Button zur Widerrufsliste

---

## Versionshistorie

### 1.7.0
- Neue Admin-Detailansicht für Widerrufe mit vollständiger Artikel-Übersicht
- "View"-Aktion im Admin-Grid hinzugefügt
- Verlinkte Bestellnummer zur direkten Navigation zur Order-View
- Übersichtliche Darstellung aller widerrufenen Artikel mit Mengen
- Zurück-Button zur einfachen Navigation

### 1.6.0
- Fehler bei doppelten Widerrufs-Artikeln behoben: Mehrfache Teilwiderrufe desselben Artikels aktualisieren nun korrekt `qty_withdrawn` in einer einzelnen Zeile, anstatt doppelte Einträge zu erstellen
- Verbesserte Datenbankeffizienz für Teilwiderrufs-Szenarien

### 1.5.0
- Neu hinzugefügte Sprachen: Bulgarisch, Dänisch, Estnisch, Finnisch, Französisch, Griechisch, Irisch, Italienisch, Kroatisch, Lettisch, Litauisch, Maltesisch, Niederländisch, Polnisch, Portugiesisch, Rumänisch, Schwedisch, Slowakisch, Slowenisch, Spanisch, Tschechisch, Ungarisch. Das Modul unterstützt jetzt alle 24 offiziellen Amtssprachen der Europäischen Union. Alle Übersetzungen verwenden den juristisch korrekten Begriff für das gesetzliche Widerrufsrecht gemäß EU-Verbraucherrechterichtlinie (2011/83/EU).

### 1.4.0
- Deleted the version attribute from composer.json. Composer has great integration with version control systems Git and there is no need to manually track version numbers in a text file for Composer at all. The field only exists for special situations where a version control system is not in use.

### 1.3.0
- Admin kann Widerrufe nun direkt aus dem Grid bestätigen oder ablehnen
- Kontextabhängige Aktionslinks pro Zeile (Bestätigen / Ablehnen) — nur angezeigt, wenn ein Statuswechsel sinnvoll ist
- Massenaktionen zum Bestätigen oder Ablehnen mehrerer Widerrufsersuche
- Methoden getById() und updateStatus() zu WithdrawalRepositoryInterface und WithdrawalRepository hinzugefügt
- Umfassende Code-Review-Dokumentation hinzugefügt
- Implementierungsleitfaden und API-Referenz erstellt

### 1.2.0

- Widerrufsfrist beginnt nun ab dem Versanddatum der letzten Lieferung statt ab Bestelleingang (gesetzlich korrekt gemaess EU-Richtlinie 2011/83/EU)
- Bei noch nicht versandten Bestellungen ist der Widerruf immer moeglich
- Fristanzeige entsprechend aktualisiert

### 1.1.0

- Kompletter Widerrufs-Workflow fuer eingeloggte Kunden und Gastbestellungen
- Widerrufsbutton in der Bestelluebersicht und auf der Bestelldetailseite
- Detailseite mit Bestellzusammenfassung und Fristanzeige
- Bestaetigungsseite nach erfolgreichem Widerruf
- E-Mail-Benachrichtigungen an Kunde und Shopbetreiber (inkl. BCC)
- Admin-Grid mit Filter, Sortierung, Paging und Direktlink zur Bestellung
- Konfigurationsbereich fuer Modul, Fristen und E-Mail-Einstellungen
- ACL-gestuetzte Berechtigungen und abgesicherte REST API
- CSRF-Schutz und JavaScript-Bestaetigungsdialog
- Vollstaendige Uebersetzungen DE/EN

### 1.0.3

- Widerruf fuer Gastbestellungen ermoeglicht
- Erfolgsseite nach Absenden des Widerrufs

### 1.0.2

- Spalte "Bestellung aufgegeben am" im Admin-Grid
- Aktion "Bestellung ansehen" im Admin-Grid
- Automatischer Kommentar in der Bestellhistorie

### 1.0.1

- Shop-E-Mail als BCC in der Bestaetigungsmail
- Bestelldetails ueber dem Widerrufsformular

### 1.0.0

- Erstveroeffentlichung
- Getestet mit Magento 2.4.6 bis 2.4.8-p1

---

## Geplant

- REST API um Schreibzugriffe erweitern
- Individuelle Widerrufsfristen pro Produkt (ueber Produktattribute)

---

## Kontakt & Support

**Zwernemann Medienentwicklung**\
Martin Zwernemann\
79730 Murg

[Zur Website](https://www.zwernemann.de/widerrufsbutton-fuer-magento-2/)

Bei Fragen, Problemen oder Ideen fuer neue Funktionen -- melden Sie sich gerne.

---

## Lizenz

- **`README.md`** - Englische Übersicht
- **`README_de.md`** - Diese Datei (deutsche Übersicht)
- **`CHANGELOG.md`** - Versionshistorie und Release-Hinweise