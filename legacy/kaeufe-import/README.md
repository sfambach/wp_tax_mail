# kaeufe-import

Käufe aus Bestellmails (eBay, AliExpress, weitere Shops) in den Taxonomy Modeller übernehmen
(Knoten «Käufe» auf fambach.net).

## Stand

- `kaeufe_import/fetch.py` — holt Mails aus `einkauf@fambach.net` nur lesend als `.eml` nach `samples/`.
  Zugangsdaten in `~/.config/kaeufe-import/imap.ini` (Vorlage `imap.ini.example`, `chmod 600`).
- `kaeufe_import/parse.py` — liest `samples/*.eml` und schreibt `samples/orders.json`: je Shop ein Modul unter
  `kaeufe_import/shops/` (eBay mit Käufen und Verkäufen, Mouser, reichelt, Conrad, AliExpress), ein Vorgang je
  Plattform + Bestellnummer. Werbung und Mails ohne Modul bleiben liegen; Apotheke/PayPal werden nicht gelesen;
  vom Käufer nur der eBay-Name.
- `kaeufe_import/writer.py` — schreibt `samples/orders.json` über die Ability `taxmod/write-data` in den
  Taxonomy Modeller (Bestellungen + Käufe, Verkäufe + Verkaufte Artikel). Vorgabe ist der Probelauf: Änderungslisten
  nach `samples/plan/`, Zählung neu / doppelt / unvollständig. `--write` schickt je Vorgang einen Aufruf, ≥ 5 s Pause,
  Abbruch beim ersten Fehler, Ids nach `samples/written.log`. Zugang: `~/.config/kaeufe-import/wp.ini`
  (Vorlage `wp.ini.example`) oder `TAXMOD_URL` / `TAXMOD_USER` / `TAXMOD_APP_PASSWORD`; ohne Zugang
  `--snapshot samples/model` (Momentaufnahme von `read-node`).

## Geplanter Aufbau

- Je Shop ein Modul; Module liefern Käufe in einem einheitlichen Format, nur der Schreiber schreibt
  in den Taxonomy Modeller. Duplikate über Plattform + Bestellnummer.
- Aufräumen im Postfach: `Importiert` (bleibt), `Ignoriert` (nach 30 Tagen gelöscht, nur was ein
  Modul sicher als irrelevant erkennt), `Ohne Modul` (bleibt, bis es ein Modul gibt), `Fehler` (bleibt).
- **Was verarbeitet ist, zeigt der Ordner der Mail**: eine Mail in `INBOX` ist neu, alles andere ist
  erledigt — sichtbar auch im Webmail. Reihenfolge je Mail: erst in den Taxonomy Modeller schreiben,
  dann verschieben. Bricht ein Lauf dazwischen ab, kommt die Mail beim nächsten Lauf wieder; die
  Duplikatprüfung über Plattform + Bestellnummer verhindert einen zweiten Eintrag (Versand- und
  Zustellmails ergänzen den vorhandenen Eintrag nur).
- Käufe, die vorher von Hand oder über den Connector eingetragen wurden, brauchen deshalb ihre
  Bestellnummer — sonst legt der Importer sie ein zweites Mal an.
- Läuft später auf dem Proxmox-Server in einem eigenen Container.
- Schreiben erst, wenn im Taxonomy Modeller INF-072 umgesetzt ist (Berechtigung nur für Daten);
  WordPress-Benutzer `auto_einkauf`, Rolle Redakteur.

## Später (vermerkt, nicht gebaut)

- **Weboberfläche statt Skripte** — andere Benutzer sollen nichts skripten müssen: Container
  installieren, Einrichtung und Konfiguration über ein Webinterface. Eventuell ein eigenes
  Git-Projekt. (Wunsch vom 2026-10-18)
