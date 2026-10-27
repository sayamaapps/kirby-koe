# Koe: technische Grundlage für den Datenschutztext

(English version: [PRIVACY.md](PRIVACY.md). Optionen heißen `koe.*`; die Namen
stehen im [README](README.md#options).)

Dieser Baustein beschreibt die Implementierung. Rechtsgrundlage, Interessenabwägung
und die Formulierung müssen vor Veröffentlichung für den konkreten Betrieb fachlich
geprüft und ergänzt werden. Der Text ist keine Rechtsberatung.

## Textbaustein für die Datenschutzerklärung

Kurz halten: Besucher brauchen nur, was gezeigt wird, dass beim Aufruf nichts
zu Google geht, was ein Klick auslöst und wie man widerspricht. Seite und
Anrede (Sie/du) an die eigene Erklärung anpassen.

> ## Google-Bewertungen
>
> Auf der Startseite zeigen wir ausgewählte öffentliche Google-Bewertungen.
> Texte und Profilbilder liefert unser eigener Server aus: Beim Aufruf der
> Seite wird keine Verbindung zu Google aufgebaut, und wir geben keine Daten
> von Ihnen an Google weiter. Erst wenn Sie einen Link zu Google anklicken,
> öffnet sich Google Maps; dort gilt die Datenschutzerklärung von Google. Wenn
> Ihre Bewertung hier nicht erscheinen soll, genügt eine kurze Nachricht an
> uns.

## Technischer Hintergrund (für Rückfragen, nicht für die Erklärung)

Auf unserer Website zeigen wir die Gesamtbewertung, die Anzahl der Bewertungen und
ausgewählte Bewertungen unseres Betriebs aus Google Maps. Diese Inhalte werden
**nicht beim Aufruf der Seite von Google geladen**: Ihr Browser baut beim Besuch
unserer Website keine Verbindung zu Google auf. Es wird kein Skript, keine
Einbettung, keine Schrift und kein Bild von Google-Servern nachgeladen; die
Profilbilder der Bewertenden und das Google-Maps-Logo liefert unser eigener
Server aus. Erst wenn Sie einen Bewertenden, den Link zum Profil oder den Link
„Alle auf Google lesen" anklicken, öffnet Ihr Browser Google Maps — wie bei
jedem anderen Link auf eine fremde Website. Dort gilt die Datenschutzerklärung
von Google.

Die Bewertungen ruft unser Webserver über die Google-Places-Schnittstelle ab,
wenn eine Seite sie anzeigen soll und sein Zwischenspeicher keine enthält. Dabei
werden nur die Kennung unseres Google-Eintrags und eine Sprachangabe übermittelt,
niemals Daten unserer Website-Besucher; der Abruf erfolgt erst, nachdem die Seite
ausgeliefert wurde. Anschließend lädt unser Server die Profilbilder der
angezeigten Bewertungen von Google, ebenfalls ohne Besucherdaten.

Im Zwischenspeicher unseres Webservers liegen nur die Kennung unseres
Google-Eintrags, die Gesamtbewertung, die Anzahl der Bewertungen und die
angezeigten Bewertungen (in der Vorgabe höchstens drei) mit Anzeigename,
Profilbild, Datum, Sternezahl, Text und Link zur Bewertung bei Google Maps.
Weitere von Google gelieferte Bewertungen und Angaben werden sofort verworfen.
Der Zwischenspeicher verfällt nach einer festen Haltezeit (in der Vorgabe
24 Stunden, Option `koe.cacheHours`) und wird beim nächsten Seitenaufruf danach
gelöscht; jeder Abruf ersetzt ihn vollständig. Es gibt keine dauerhafte Kopie
und kein Archiv, die Bewertungen liegen weder im Inhaltsverzeichnis der Website
noch in deren Versionsverwaltung. Eine Bewertung, die Google nicht mehr liefert
(etwa weil sie zurückgezogen wurde), verschwindet spätestens nach Ablauf der
Haltezeit von unserer Website. Schlägt ein Abruf fehl, zeigt die Website keine
Bewertungen.

Auf der Website erscheinen nur Bewertungen, die festgelegte Regeln erfüllen
(in der Vorgabe: fünf Sterne, mindestens 80 Zeichen Text, nicht älter als
24 Monate, die drei neuesten; längere Texte werden an einer Wortgrenze gekürzt
und mit „…" gekennzeichnet) und gegebenenfalls nur solche, die wir ausdrücklich
freigegeben haben. Bewertende werden mit ihrem bei Google öffentlichen
Anzeigenamen und Profilbild genannt; beides verweist auf die Originalbewertung
bei Google Maps. Ein Hinweis unter den Bewertungen nennt die Auswahlregeln.

Das Modul setzt keine Cookies, verwendet keinen Browser-Speicher und erhebt
keine Daten über Besucher dieser Website.

## Hinweise für den Betreiber

- **Abrufort.** Der Abruf läuft auf dem Webserver der Website. Wer ihn
  betreibt (der Hoster), gehört ohnehin in die Liste der Auftragsverarbeiter;
  Google steht im Verhältnis eines Anbieters öffentlicher Inhalte.
- **Betroffene** sind die Bewertenden, deren bei Google öffentliche Angaben die
  Website wiedergibt. Löschungen bei Google wirken spätestens nach Ablauf der
  Haltezeit auf die Seite durch.
- **Zwischenspeicher.** Kirbys Cache-Verzeichnis (in der Vorgabe
  `site/cache/`), nicht im Repository, nicht über das Web erreichbar außer den
  Profilbildern unter `/koe/avatar/…`, solange sie angezeigt werden. Von
  Sicherungen ausnehmen. Sicherungen des gesamten Webspace durch den Hoster
  können ihn für deren Aufbewahrungsdauer enthalten.
- **Schlüssel.** Der Google-API-Schlüssel liegt auf dem Webserver (Option
  `koe.key` aus einer Datei außerhalb des Repositorys oder Umgebungsvariable
  `GOOGLE_PLACES_API_KEY`), nie im Browser. Empfehlung: ein eigenes
  Google-Cloud-Projekt je Betrieb, Schlüssel nur für die Places API (New),
  wenn möglich auf die IP-Adresse des Servers beschränkt, Tageskontingent
  gedeckelt.
- **Google-Vorgaben** (Places-Richtlinien): Autor mit Profilbild nennen und
  auf die Bewertung bei Google Maps verlinken, Attribution „Google Maps" zeigen,
  Auswahlregeln nennen. Das Vorgabe-Snippet erfüllt das; wer eigenes Markup
  baut, muss es selbst einhalten.
