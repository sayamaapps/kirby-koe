# Koe: technische Grundlage für den Datenschutztext

(English version: [PRIVACY.md](PRIVACY.md). Optionen heißen `koe.*`; die Namen
stehen im [README](README.md#options).)

Dieser Baustein beschreibt die Implementierung. Rechtsgrundlage, Interessenabwägung
und die Formulierung müssen vor Veröffentlichung für den konkreten Betrieb fachlich
geprüft und ergänzt werden. Der Text ist keine Rechtsberatung.

## Google-Bewertungen — technischer Textbaustein

Auf unserer Website zeigen wir die Gesamtbewertung, die Anzahl der Bewertungen und
ausgewählte Bewertungen unseres Betriebs aus Google Maps. Diese Inhalte werden
**nicht beim Aufruf der Seite von Google geladen**: Ihr Browser baut beim Besuch
unserer Website keine Verbindung zu Google auf. Es wird kein Skript, keine
Einbettung, keine Schrift und kein Bild von Google-Servern nachgeladen; das
Google-Maps-Logo liegt auf unserem eigenen Server. Erst wenn Sie den Namen eines
Bewertenden, den Link zum Profil oder den Link „Alle auf Google lesen" anklicken,
öffnet Ihr Browser Google Maps — wie bei jedem anderen Link auf eine fremde
Website. Dort gilt die Datenschutzerklärung von Google.

Die gezeigten Bewertungen sind eine Kopie, die unser Server (bzw. ein von uns
beauftragter Dienst zur Erstellung der Website) einmal täglich über die
Google-Places-Schnittstelle abruft. Übermittelt werden dabei nur Name und
Anschrift unseres Betriebs, niemals Daten unserer Website-Besucher. Gespeichert
werden die Kennung unseres Google-Eintrags, die Gesamtbewertung, die Anzahl der
Bewertungen sowie höchstens fünf Bewertungen mit Anzeigename und Profil-Link des
Bewertenden, Link zur Bewertung auf Google Maps, Datum, Sternezahl, Text und der
Google-Kennung der Bewertung. Jeder erfolgreiche Abruf ersetzt die gespeicherte
Kopie vollständig, auch wenn Google keinen Bewertungstext mehr liefert (die Seite
zeigt dann nur Gesamtbewertung und Anzahl); es gibt kein Archiv. Eine Bewertung,
die Google nicht mehr liefert (etwa weil sie zurückgezogen wurde), verschwindet
mit dem nächsten erfolgreichen Abruf, in der Regel innerhalb eines Tages, von
unserer Website. Schlägt der Abruf fehl (etwa Kontingent erschöpft, Schlüssel
widerrufen, Ausfall), bleibt die Kopie unverändert; die Website zeigt sie dann
nur noch, solange der letzte erfolgreiche Abruf nicht länger als sieben Tage
zurückliegt (Option `koe.maxAgeDays`), danach erscheint der Abschnitt nicht
mehr.

Auf der Website erscheinen nur Bewertungen, die festgelegte Regeln erfüllen
(in der Vorgabe: fünf Sterne, mindestens 80 Zeichen Text, nicht älter als
24 Monate, die drei neuesten; längere Texte werden an einer Wortgrenze gekürzt
und mit „…" gekennzeichnet) und gegebenenfalls nur solche, die wir ausdrücklich
freigegeben haben. Bewertende werden mit ihrem bei Google öffentlichen
Anzeigenamen genannt; der Name verweist auf die Originalbewertung bei Google
Maps.

Das Modul setzt keine Cookies, verwendet keinen Browser-Speicher und erhebt
keine Daten über Besucher dieser Website.

## Hinweise für den Betreiber

- **Abrufort.** Der nächtliche Abruf läuft dort, wo der Schlüssel liegt: in
  der Regel auf einem CI-Läufer (GitHub Actions) oder per Cron auf dem Server.
  Wer den Abruf ausführt, gehört in die Liste der Auftragsverarbeiter bzw.
  Dienstleister — nicht Google (dessen Verhältnis ist das zu einem Anbieter
  öffentlicher Inhalte), sondern der Betreiber des Läufers.
- **Betroffene** sind die Bewertenden, deren bei Google öffentliche Angaben die
  Website wiedergibt. Löschungen bei Google wirken durch den täglichen Ersatz
  innerhalb eines Tages nach dem nächsten erfolgreichen Abruf auf die Seite
  durch. Die Git-Geschichte des
  Repositories enthält ältere Stände der Datei; wer zurückgezogene
  Bewertungen auch dort entfernen will, muss das in Git selbst tun.
- **Datei.** `site/data/reviews.json` (oder `koe.file`), außerhalb des
  Dokumentstamms, nicht über das Web erreichbar; im Repository und auf dem
  Server.
- **Schlüssel.** Der Google-API-Schlüssel liegt nur am Ort des Abrufs
  (Repository-Secret `GOOGLE_PLACES_API_KEY`), nie in der Kirby-Konfiguration,
  nie auf dem Webserver. Empfehlung: ein eigenes Google-Cloud-Projekt je
  Betrieb, Schlüssel nur für die Places API (New), Tageskontingent gedeckelt.
- **Google-Pflichten** (Places-Richtlinien): Autor nennen, Name auf die
  Bewertung bei Google Maps verlinken, Attribution „Google Maps" zeigen,
  Inhalte höchstens etwa 24 Stunden zwischenspeichern. Das Vorgabe-Snippet
  erfüllt alle vier; wer eigenes Markup baut, muss sie selbst einhalten.
