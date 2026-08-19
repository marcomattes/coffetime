# Technische Notizen

Entscheidungen, die man beim Lesen des Codes sonst rekonstruieren müsste.

## SQLite-Schema

Schemaversion in `PRAGMA user_version`, Zielversion in `Db::SCHEMA_VERSION` (3).

```sql
-- Version 1
CREATE TABLE users (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    name        TEXT,                              -- nur Altbestand, Klartext
    coffees     INTEGER NOT NULL DEFAULT 0,
    paid_cents  INTEGER NOT NULL DEFAULT 0,
    created_at  INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE credentials (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id       INTEGER NOT NULL,
    credential_id TEXT NOT NULL,                   -- base64url
    public_key    TEXT NOT NULL,                   -- base64url, COSE
    sign_count    INTEGER NOT NULL DEFAULT 0,
    created_at    INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE sessions (
    id         TEXT PRIMARY KEY,                   -- sha256(Cookie-Token), hex
    user_id    INTEGER NOT NULL,
    created_at INTEGER NOT NULL DEFAULT 0,
    expires_at INTEGER NOT NULL DEFAULT 0
);

-- Version 2 (rein additiv)
ALTER TABLE users       ADD COLUMN name_encrypted   TEXT;    -- sealed box, Base64
ALTER TABLE users       ADD COLUMN name_hash        TEXT;    -- HMAC-SHA256, hex
ALTER TABLE users       ADD COLUMN user_handle      TEXT;    -- base64url, 16 Byte
ALTER TABLE credentials ADD COLUMN aaguid           TEXT;
ALTER TABLE credentials ADD COLUMN transports       TEXT;    -- JSON
ALTER TABLE credentials ADD COLUMN attestation_type TEXT;
ALTER TABLE credentials ADD COLUMN trust_path       TEXT;    -- JSON
ALTER TABLE credentials ADD COLUMN last_used_at     INTEGER NOT NULL DEFAULT 0;
CREATE TABLE ceremonies (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    kind       TEXT NOT NULL,                      -- 'register' | 'login'
    challenge  TEXT NOT NULL,                      -- base64url, UNIQUE
    options    TEXT NOT NULL,                      -- serialisierte Optionen
    payload    TEXT,                               -- JSON: Modus, Chiffrat, HMAC, Handle
    used       INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL DEFAULT 0
);

-- Version 3 (rein additiv)
CREATE TABLE coffee_events (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL,
    created_at INTEGER NOT NULL DEFAULT 0
);

CREATE UNIQUE INDEX idx_users_name_hash ON users (name_hash) WHERE name_hash IS NOT NULL;
CREATE UNIQUE INDEX idx_users_handle    ON users (user_handle) WHERE user_handle IS NOT NULL;
CREATE UNIQUE INDEX idx_credentials_credential_id ON credentials (credential_id);
CREATE UNIQUE INDEX idx_ceremonies_challenge      ON ceremonies (challenge);
CREATE INDEX idx_coffee_events_user               ON coffee_events (user_id);
```

Die beiden UNIQUE-Indizes auf `users` sind **partiell**: Zeilen aus Version 1
haben weder HMAC noch Handle und dürfen trotzdem existieren.

### Migrationen

`Db::migrate()` läuft bei jedem Öffnen der Verbindung:

1. `PRAGMA user_version` lesen. Ist sie schon aktuell, wird nur `ensureSchema()`
   ausgeführt.
2. Sonst in einer `BEGIN IMMEDIATE`-Transaktion alle Schritte mit
   `version > user_version` in Reihenfolge anwenden und die Version setzen. Die
   exklusive Transaktion verhindert, dass mehrere gleichzeitig startende Worker
   dieselben Schritte doppelt ausführen.
3. `ensureSchema()` ist die Absicherung für fremde oder halb migrierte
   Datenbanken: es vergleicht die erwarteten Spalten mit `PRAGMA table_info` und
   ergänzt fehlende per `ALTER TABLE ADD COLUMN`; Indizes entstehen mit
   `IF NOT EXISTS`. Beides ist idempotent und rein additiv.

Es gibt kein `DROP` und kein `CREATE TABLE` ohne `IF NOT EXISTS`. Ein Name, den
eine ältere Version im Klartext in `users.name` geschrieben hat, bleibt genau so
stehen: er wird nicht nachträglich verschlüsselt, nicht gelöscht und von keiner
Antwort ausgegeben. Nur neue Registrierungen gehen durch den verschlüsselten Weg.
Das Offline-Werkzeug gibt solche Altnamen direkt aus (getrennt am ersten
Leerzeichen), weil sie dort ohnehin im Klartext vorliegen.

## WebAuthn: welche Klassen wofür

Serializer für alle Richtungen (Objekt → JSON für den Browser, JSON → Objekt beim
Verify):

- `Webauthn\Denormalizer\WebauthnSerializerFactory`, gefüttert mit einem
  `AttestationStatementSupportManager([new NoneAttestationStatementSupport()])`.

Gemeinsame Basis beider Ceremonien:

- `Webauthn\CeremonyStep\CeremonyStepManagerFactory` mit
  `setAllowedOrigins([$config['origin']])`. Damit prüft `CheckAllowedOrigins`
  die Origin auf exakte Gleichheit – kein Sonderfall für HTTP nötig, `localhost`
  wird schlicht erlaubt, weil es genau so in der Konfiguration steht.

**Registrierung**

- `PublicKeyCredentialCreationOptions::create()` mit
  `PublicKeyCredentialRpEntity` (`id` = `rpId`), `PublicKeyCredentialUserEntity`
  (`id` = 16 Zufallsbytes, `name`/`displayName` bewusst opak: `kaffee-…`),
  `PublicKeyCredentialParameters` für ES256 (−7) und RS256 (−257),
  `AuthenticatorSelectionCriteria` mit `residentKey: required` und
  `userVerification: required`, `attestation: none`.
- `CeremonyStepManagerFactory::creationCeremony()` →
  `AuthenticatorAttestationResponseValidator::check($response, $options, $rpId)`
  liefert einen `Webauthn\CredentialRecord`.

**Anmeldung**

- `PublicKeyCredentialRequestOptions::create($challenge, $rpId, [], 'required')` –
  `allowCredentials` bleibt leer, die Anmeldung ist namenlos.
- `CeremonyStepManagerFactory::requestCeremony()` →
  `AuthenticatorAssertionResponseValidator::check($record, $response, $options, $rpId, null)`.
  Der `userHandle`-Parameter ist absichtlich `null`: der Benutzer war vor der
  Ceremonie nicht bekannt. `CheckUserHandle` verlangt dann, dass die Assertion
  selbst ein Handle mitbringt und dass es zum gespeicherten passt.
- Der `CredentialRecord` wird aus der Zeile in `credentials` plus dem
  `user_handle` des Kontos rekonstruiert (`Credentials::toRecord()`).

**Zähler**: `CheckCounter` mit `ThrowExceptionIfInvalid` weist jeden Wert ab, der
nicht grösser als der gespeicherte ist (Ausnahme: beide sind 0, wie bei
Authenticatoren ohne Zähler). Nach erfolgreicher Prüfung schreibt
`Credentials::updateSignCount()` den neuen Wert zurück.

**Challenge-Bindung ohne Korrelations-ID**: Beim Verify kommt nur das nackte
`PublicKeyCredential` an. Die Challenge wird deshalb aus
`response.clientDataJSON` gelesen (base64url → JSON → `.challenge`), damit wird
die Zeile in `ceremonies` gefunden und in derselben Transaktion mit
`UPDATE … SET used = 1 WHERE id = ? AND used = 0` verbraucht. Nur wenn dieses
Update genau eine Zeile trifft, geht es weiter – ein Replay derselben
`clientDataJSON` findet nichts mehr. Zusätzlich prüft die Bibliothek die
Challenge noch einmal gegen die gespeicherten Optionen.

**Doppelte Passkeys**: `excludeCredentials` enthält bei angemeldeten Benutzern
alle eigenen Credentials, und serverseitig wird nach der Attestation geprüft, ob
die Credential-ID schon existiert (`409 credential_exists`).

## Sitzungen

- Anlegen (`Sessions::start()`): Token = `base64url(random_bytes(32))`, 43
  Zeichen, keine Struktur, kein Hex. In der Datenbank liegt nur
  `sha256(token)` als Primärschlüssel – eine Kopie der Datei enthält damit keine
  gültigen Tokens.
- Cookie: `coffee_session=<token>; Max-Age=31536000; Expires=…; Path=/;
  HttpOnly; SameSite=Lax` und `Secure` genau dann, wenn `origin` mit `https://`
  beginnt. Der Header wird selbst gebaut, damit `Max-Age` exakt ein Jahr ist und
  nicht von der (verschiebbaren) Uhr abhängt.
- Nachschlagen (`Sessions::currentUser()`): Token aus dem Cookie – nie aus
  Querystring, Header oder Body –, Hash bilden, Zeile lesen. Ist
  `expires_at <= now()`, wird die Zeile gelöscht und der Request mit 401
  beantwortet.
- Verlängern: bei **jedem** authentifizierten Request wird `expires_at` auf
  `now() + 30 Tage` gesetzt, per `UPDATE` in der Datenbank, und das Cookie neu
  gesendet. Wer die App benutzt, wird nicht abgemeldet.
- Zwei Lebensdauern, mit Absicht: das Cookie hält ein Jahr (Anforderung), die
  serverseitige Leerlauffrist 30 Tage (`Sessions::IDLE_LIFETIME`). Ein Gerät, das
  einen Monat nicht angefasst wurde, muss den Passkey erneut zeigen.
- Abmelden löscht genau die Zeile dieser Sitzung. Andere Geräte behalten ihre.
- Weil alles in SQLite steht, überlebt jede Sitzung einen Neustart des Servers.

## Eindeutigkeit von Namen, ohne den Namen zu lesen

`Crypto` (in `src/Crypto.php`, ohne Abhängigkeiten) kann nur zwei Dinge:

1. `sealName()` – `sodium_crypto_box_seal()` über
   `{"firstName":…,"lastName":…}` gegen `config['adminPublicKey']`, Ergebnis als
   Standard-Base64. Der private Schlüssel existiert auf dem Server nicht, es gibt
   also gar keine Funktion zum Entschlüsseln.
2. `nameHash()` – `hash_hmac('sha256', "<vorname>\x1f<nachname>", namePepper)`.
   Deterministisch, mit `UNIQUE` auf der Spalte. `register/options` fragt
   ausschliesslich diesen Hash ab (`Users::idForNameHash()`); Chiffrate werden
   nie verglichen und nie geöffnet. Vor dem Hashen werden nur Rand- und
   Mehrfach-Whitespace normalisiert, das Trennzeichen `\x1f` verhindert, dass
   „AdaLove|lace“ und „Ada|Lovelace“ kollidieren.

Der Pepper verlässt den Server nie: kein Endpunkt gibt ihn aus, `/api/test/state`
liefert nur `priceCents` und `admins`.

Der Name wird bereits in `register/options` versiegelt und liegt bis zum Verify in
der `ceremonies`-Zeile. Damit kann der Verify-Schritt kein anderes Chiffrat
unterschieben, und ohne gültigen Einladungscode entsteht überhaupt keine Zeile.

Die versiegelten Namen aus `/api/test/seed` laufen durch genau dieselben zwei
Funktionen – gesäte und registrierte Konten sind in der Datenbank nicht
unterscheidbar (`name` ist NULL, Chiffrat und HMAC sind gesetzt); der einzige
Unterschied ist, dass ein gesätes Konto keinen Passkey hat und sich daher nicht
anmelden kann.

## Parallelität

Acht gleichzeitige `POST /api/coffee` derselben Sitzung müssen genau acht Kaffees
ergeben. Was dafür nötig war:

1. **Atomar rechnen, nie lesen-ändern-schreiben**:
   `UPDATE users SET coffees = coffees + 1 WHERE id = ?`. Die Rücknahme ist
   `… coffees = coffees - 1 WHERE id = ? AND coffees > 0` – dadurch kann der
   Zähler nicht negativ werden, ohne dass der Wert vorher gelesen werden muss.
2. **WAL** (`PRAGMA journal_mode = WAL`, einmalig in der Datei) und ein
   **Busy-Timeout** von 15 s (`PRAGMA busy_timeout`, zusätzlich
   `PDO::ATTR_TIMEOUT`). Der Modus wird nur umgestellt, wenn er noch nicht WAL
   ist: das Umschalten braucht eine exklusive Sperre und würde unter Last selbst
   scheitern.
3. **`BEGIN IMMEDIATE`** für jede Schreiboperation (`Db::transaction()`), damit
   die Schreibsperre sofort geholt wird und keine Lesetransaktion später
   „hochgestuft“ werden muss. Danach wird im selben Block der neue Stand gelesen,
   sodass jede Antwort einen konsistenten Zähler zurückgibt.
4. **Der eigentliche Stolperstein: offene Cursor.** `PDOStatement::fetch()` lässt
   die Anweisung offen, und eine offene Anweisung hält in WAL eine
   Lesetransaktion mit einem Snapshot. Ein anschliessendes `BEGIN IMMEDIATE`
   scheitert dann *sofort* mit `SQLITE_BUSY_SNAPSHOT` – „database is locked“, und
   zwar ohne dass das Busy-Timeout überhaupt greift. Genau daran ist die erste
   Fassung gescheitert (4 von 8 Requests mit 500). Alle Lesezugriffe laufen
   deshalb über `Db::fetchRow()`, `Db::fetchRows()` und `Db::fetchValue()`, die
   nach dem Lesen `closeCursor()` aufrufen; `fetchColumn()` und ein nackter
   `fetch()` kommen im Code nicht mehr vor.
5. **Wiederholen als Netz**: `Db::transaction()` versucht es bei einer
   Busy-Meldung bis zu zwölfmal mit kurzer, zufälliger Pause.

Gemessen: acht parallele Anfragen, acht Antworten mit 200 und den Ständen 1…8,
Endstand 8. Wiederholt reproduzierbar.

## Zeit

`Clock::now()` ist die einzige Stelle im Code, die `time()` aufruft; alles mit
Zeitstempel geht darüber. `/api/test/clock` verschiebt die Uhr für die laufende
Serverinstanz.

Der Offset liegt in `<dbDir>/clock-offset.json`, damit alle Worker des eingebauten
Servers denselben Wert sehen (statische PHP-Variablen leben nur für einen
Request). Damit er einen Neustart *nicht* überlebt, wird die PID des setzenden
Prozesses mitgeschrieben: lebt dieser Prozess nicht mehr (`/proc/<pid>` bzw.
`posix_kill($pid, 0)`), gilt der Offset als veraltet und wird verworfen. Die
Worker des eingebauten Servers leben genauso lange wie der Server selbst.

## Fehler und Sichtbarkeit

- `display_errors` wird als Erstes abgeschaltet, ein eigener Error-Handler
  schluckt alles (die Bibliothek löst unter anderem `E_USER_DEPRECATED` aus, was
  einen Request nicht abbrechen und erst gar nicht im Body landen darf) und
  schreibt es ins Log.
- Exception- und Shutdown-Handler antworten mit `{"error":"server_error"}` und
  Status 500, nachdem sie den Ausgabepuffer verworfen haben. Kein Stacktrace,
  keine PHP-Meldung, kein HTML.
- Der Front-Controller liest niemals eine Datei vom Dateisystem: die Oberfläche
  wird für eine kleine Liste bekannter Pfade (`/`, `/app`, `/admin`, `/login`)
  aus PHP heraus erzeugt, alles andere ist ein JSON-404. Damit sind
  `/config.php`, `/data/coffee.sqlite`, `/.git/config` und Traversal-Versuche
  strukturell nicht bedienbar.
- `/api/test/*` antwortet ohne `testMode` oder ohne passendes `X-Test-Token` mit
  404 – nicht 401, nicht 403: in Produktion soll nicht erkennbar sein, dass es
  diese Endpunkte gibt. Der Vergleich läuft über `hash_equals()`.
- Bei geschützten Endpunkten wird die Sitzung *vor* der Methode geprüft: ohne
  Sitzung gibt es 401, auch bei falschem Verb. Mit Sitzung und falschem Verb 405.

## Apache und die Punktdateien

Der eingebaute PHP-Server leitet Anfragen ohne passende Datei von selbst auf
`index.php` – Apache tut das nicht. Deshalb liegen zwei `.htaccess`-Dateien bei,
die beide unter `php -S` wirkungslos sind:

- `public/.htaccess`: `RewriteCond %{REQUEST_FILENAME} -f` liefert vorhandene
  Dateien direkt aus, alles andere geht an `index.php`. Ohne diese Datei
  antwortet Apache auf `/api/me` mit einem eigenen 404.
- `.htaccess` in der Projektwurzel: greift nur, wenn die Dokumentenwurzel auf
  den Projektstamm zeigt (statt auf `public/`). Sie leitet statische Dateien
  nach `public/` und alles andere an `public/index.php`. Nebeneffekt: Anfragen
  auf `config.php`, `src/…`, `data/…` oder `vendor/…` erreichen nie das
  Dateisystem, sondern enden im Front-Controller mit einem JSON-404. Zeigt die
  Wurzel korrekt auf `public/`, liest Apache die Datei nicht, weil sie oberhalb
  der Wurzel liegt.

Beide Dateien verweigern zusätzlich alle Punktdateien (`<FilesMatch "^\.">`) –
ohne das würde die Umschreibung in der Projektwurzel `/.htaccess` auf
`public/.htaccess` abbilden und den Regelsatz ausliefern.

`src/`, `tools/`, `tests/` und das angelegte `data/` bekommen ein
`Require all denied`. Das ist reines Sicherheitsnetz für den Fall, dass die
Dokumentenwurzel falsch gesetzt ist.

Bei Hostern mit `open_basedir` auf der Dokumentenwurzel (Plesk, netcup) kommt
PHP nicht an `src/`, `vendor/` und `config.php`, wenn diese darüber liegen.
Entweder `open_basedir` auf die Ebene darüber erweitern oder den ganzen Baum in
die Dokumentenwurzel legen – dann greift die `.htaccess` der Projektwurzel.
Beides wurde gegen Apache 2.4 mit php-fpm und gesetztem `open_basedir` geprüft.

## Frontend

Eine Seite, drei Abschnitte (`view-auth`, `view-app`, `view-admin`), umgeschaltet
über das `hidden`-Attribut. Alles Dynamische wird per `textContent` geschrieben –
es gibt kein `innerHTML` mit Nutzdaten, damit kann keine Eingabe zu Markup
werden. Die Anwendung zeigt ohnehin nirgends einen Namen an; in der Adminansicht
steht das Chiffrat, umbruchsicher formatiert.

Der Kaffeeknopf steht im ersten Abschnitt der angemeldeten Ansicht und ist auf
390 × 844 ohne Scrollen erreichbar. Alle Bedienelemente sind mindestens 48 px
hoch. Es gibt keine externe Ressource: keine Schrift, kein Icon-Set, kein
Analytics – nur `style.css` und `app.js` von derselben Herkunft.

Nach jedem Zählerklick wird zusätzlich `/api/me` neu geladen, damit der angezeigte
Stand auch bei mehreren schnellen Klicks der Serverwahrheit entspricht.

## PWA und Zahlungsbuchung

`manifest.webmanifest` und `sw.js` liegen in `public/` und werden wie
`app.js`/`style.css` als statische Dateien ausgeliefert (siehe
`public/.htaccess`). Der Service Worker cacht ausschliesslich die Hülle
(`/`, `style.css`, `app.js`, Manifest, Icons); jede Anfrage unter `/api/`
geht immer ans Netz – der Zähler bleibt serverautoritativ, auch offline
zeigt die App nur den zuletzt bekannten Stand.

`navigator.vibrate(...)` läuft bei jedem gebuchten Kaffee, zusammen mit einer
kurzen CSS-Animation (`.bump`) auf Zähler und Knopf. Beides ist rein
kosmetisches Feedback ohne Serverzustand; ein Browser ohne Vibration-API
bekommt einfach nur die Animation.

`POST /api/admin/payment` (Zahlung buchen) wurde entfernt, `paid_cents`
bleibt als Spalte bestehen, wird aber ab Registrierung nie mehr geschrieben.
Wer wem was schuldet, klärt `tools/decrypt-users.php --xlsx <pfad>` offline:
dieselbe Entschlüsselung wie bisher, zusätzlich als `.xlsx` statt nur als
Pipe-Text. Das hält die Kernidee bei – der Server entschlüsselt nie einen
Namen –, verlagert das Verrechnen aber komplett aus der App heraus.

## Serie (Streak)

`coffee_events` protokolliert ein Ereignis je gebuchtem Kaffee (Version 3).
`Users::addCoffee()`/`undoCoffee()` schreiben bzw. löschen das jeweils letzte
Ereignis in derselben Transaktion wie das Hoch-/Runterzählen von
`users.coffees` – die beiden Zähler laufen also immer synchron. `undoCoffee()`
löscht dabei gezielt `ORDER BY id DESC LIMIT 1`, nicht irgendein Ereignis.

`Users::streakDays()` gruppiert die Ereignisse eines Kontos per SQLite
`date(created_at, 'unixepoch')` zu Kalendertagen und zählt von heute (oder,
falls heute noch nichts gebucht wurde, von gestern) rückwärts, bis ein Tag
fehlt. Rein additiv und rein lesend – `coffees`/`balanceCents` bleiben
unverändert die Wahrheit, `streakDays` ist nur ein zusätzliches Feld in
`meView()`. Mit Schema-Version 1/2 angelegte Konten haben keine Ereignisse
und zeigen deshalb `streakDays: 0`, bis sie das erste Mal über die neue
Version buchen.

## Schnellstart: App-Shortcut, NFC-Tag, Badge

`/?book=1` ist die gemeinsame Eintrittsstelle für den Manifest-Shortcut
("Kaffee buchen" beim Icon-Long-Press) und einen extern beschriebenen
NFC-Tag – beides sind einfach Links auf dieselbe URL, kein Sonderfall im
Backend. `app.js` liest den Parameter beim Start (`checkPendingBook()`),
entfernt ihn sofort per `history.replaceState` (kein versehentliches
Doppelbuchen bei Reload) und merkt sich `state.pendingBook`. Ist man
eingeloggt, holt `refresh()` die Buchung sofort nach; ist man es nicht,
zeigt `#nfc-hint` einen Hinweis, und die Buchung läuft automatisch nach,
sobald `refresh()` nach Login/Registrierung erfolgreich durchläuft.
`sw.js` matcht Navigationsanfragen mit `{ ignoreSearch: true }`, damit
`/?book=1` auch offline die gecachte Hülle `/` trifft.

Web-NFC-*Schreiben* (`NDEFReader.write`) gibt es bewusst nicht: die API
existiert nur unter Chrome/Android, auf iOS/Safari fehlt sie komplett. Tags
werden extern beschrieben (z. B. mit einer NFC-Tools-App).

Die App-Icon-Badge (`navigator.setAppBadge`/`clearAppBadge`) zeigt den
offenen Betrag, auf ganze Euro gerundet; ohne Guthaben oder nach Logout wird
sie gelöscht. Rein kosmetisch, mit Feature-Detection und leise
verschluckten Fehlern – ein Browser ohne Badging API sieht einfach nichts.
