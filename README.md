# Kaffeeliste

Eine gemeinsame Kaffeekasse für ein Büro. Jede Person zählt ihre eigenen Kaffees:
anmelden mit Passkey, ein Knopf pro Kaffee, darunter steht was offen ist.
Niemand sieht den Namen oder den Zählerstand von jemand anderem – geteilt werden
nur die Gesamtzahl und eine anonyme Rangliste. Zahlungen bucht eine Person mit
Adminrechten.

Vor- und Nachname werden beim Anlegen des Kontos gegen einen öffentlichen
Admin-Schlüssel versiegelt und danach nie wieder entschlüsselt – auch nicht für
die Adminansicht. Nur ein Offline-Werkzeug mit dem passenden privaten Schlüssel
kann die Namen lesen.

## Was drin ist

- Anmeldung ausschliesslich per Passkey (WebAuthn), ohne Benutzername, ohne
  Passwort, ohne E-Mail. Die Anmeldung ist namenlos: das Gerät wählt den
  auffindbaren Passkey selbst.
- Ein Konto, beliebig viele Geräte.
- Serverautoritativer Zähler mit Rücknahme, die bei null stoppt.
- `balanceCents = coffees × priceCents − paidCents`, Preis immer aus der
  Konfiguration.
- Anonyme Statistik: Gesamtzahl, Anzahl der Konten, eigener Platz, Verteilung.
- Adminansicht mit verschlüsselten Namen und Buchung von Zahlungen.
- Kein Framework, kein Build, kein npm. Eine einzige Composer-Abhängigkeit:
  `web-auth/webauthn-lib`.

## Aufbau

```
config.php              Konfiguration (nicht im Repository, siehe config.example.php)
config.example.php      Vorlage mit Erklärungen
composer.json           die eine Abhängigkeit
public/                 das einzige Dokumentenverzeichnis
  index.php             Front-Controller: /api/* und die Oberfläche
  app.js, style.css     Vanilla JS und CSS
src/                    Anwendungsklassen (Namensraum Coffee\), nicht über HTTP erreichbar
tools/decrypt-users.php Offline-Werkzeug für den Administrator
tests/CryptoTest.php    Tests ohne Framework
data/                   SQLite-Datei, wird bei Bedarf angelegt (nicht im Repository)
```

`config.php`, `src/`, `tools/`, `tests/` und `data/` liegen ausserhalb von
`public/` und sind über HTTP nicht erreichbar.

## Lokal starten

```bash
composer install
cp config.example.php config.php
php tools/decrypt-users.php --genkey     # Schlüsselpaar erzeugen
# den öffentlichen Schlüssel in config.php als adminPublicKey eintragen,
# den privaten Schlüssel ausserhalb des Servers aufbewahren
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8123 -t public
```

Danach `http://localhost:8123` öffnen. `rpId` muss der Host ohne Port sein
(`localhost`), `origin` die vollständige Adresse mit Port
(`http://localhost:8123`). `localhost` gilt als sicherer Kontext, deshalb
funktionieren Passkeys dort auch ohne HTTPS – überall sonst ist HTTPS Pflicht.

## Auf ein gewöhnliches Webhosting bringen (FTP)

1. Lokal `composer install --no-dev` ausführen; das erzeugte `vendor/` gehört
   mit auf den Server. Auf dem Hosting selbst ist kein Composer nötig.
2. Per FTP hochladen: `public/`, `src/`, `tools/`, `tests/`, `vendor/`,
   `composer.json` und `config.php`.
3. Die Domain (oder Subdomain) auf `public/` zeigen lassen. Falls das Hosting
   nur ein festes Verzeichnis wie `htdocs/` erlaubt: den *Inhalt* von `public/`
   dorthin legen und `src/`, `tools/`, `vendor/` sowie `config.php` eine Ebene
   darüber – die Pfade in `public/index.php` sind relativ (`__DIR__ . '/../…'`)
   und passen dann weiterhin.
4. Ein beschreibbares Verzeichnis für die Datenbank anlegen, zum Beispiel
   `data/`, und `dbPath` in `config.php` darauf zeigen lassen (absoluter Pfad,
   ausserhalb des Dokumentenverzeichnisses). Fehlt es, legt die Anwendung es
   beim ersten Zugriff selbst an.
5. Die Seite einmal aufrufen: Schema und Datenbankdatei entstehen automatisch,
   Migrationen laufen bei jedem Start und sind wiederholbar.
6. HTTPS aktivieren und `origin` auf `https://…` setzen. Damit erhält das
   Sitzungscookie automatisch das `Secure`-Flag.

Es gibt keine Update-Skripte: neue Version hochladen, Seite aufrufen, fertig.
Migrationen sind rein additiv, bestehende Zeilen bleiben unangetastet.

## Konfiguration

`config.php` liegt neben `public/` und gibt ein Array zurück. Die Datei wird bei
jedem Request neu gelesen – Änderungen wirken sofort, ohne Neustart.

| Schlüssel | Bedeutung |
| --- | --- |
| `priceCents` | Preis eines Kaffees in Cent (int). Nur hier, nie in der Datenbank. |
| `invite` | Einladungscode für die Registrierung. Leer heisst: niemand kann sich registrieren. |
| `admins` | Liste von Benutzer-IDs **als Strings**, z. B. `['3']`. Nur diese Konten sind Administrator. |
| `rpId` | WebAuthn Relying Party ID – der Host ohne Schema und Port. |
| `origin` | Vollständige Origin inklusive Schema und Port. |
| `dbPath` | Absoluter Pfad zur SQLite-Datei, ausserhalb von `public/`. |
| `testMode` | `true` schaltet die Teststeuerung `/api/test/*` frei. In Produktion `false`. |
| `testToken` | Token, das die Teststeuerung im Header `X-Test-Token` erwartet. |
| `adminPublicKey` | Öffentlicher X25519-Schlüssel (64 Hex-Zeichen). Namen werden dagegen versiegelt. |
| `namePepper` | Geheimer Schlüssel für den HMAC, mit dem doppelte Namen erkannt werden. |

Wer in `admins` steht, ist beim unmittelbar nächsten Request Administrator; wer
herausgenommen wird, verliert das Recht genauso schnell. In der Datenbank gibt es
dafür keine Spalte.

Der private Schlüssel zu `adminPublicKey` gehört **nicht** auf den Server. Ohne
ihn kann niemand – auch kein Angreifer mit vollem Dateizugriff – die Namen lesen.

## Kodierung von `nameEncrypted`

`GET /api/admin/users` liefert je Konto das Feld `nameEncrypted`: das Ergebnis von
`sodium_crypto_box_seal()` über das JSON `{"firstName":…,"lastName":…}`, kodiert
als **Standard-Base64 nach RFC 4648, mit Padding** (also `A–Z a–z 0–9 + / =`) –
nicht base64url. Genau dieselbe Zeichenkette steht in der Spalte
`users.name_encrypted`. Die Anwendung entschlüsselt sie nie; sie besitzt den
privaten Schlüssel nicht.

Konten aus einer älteren Schemaversion, deren Name noch im Klartext in der
Datenbank steht, liefern `nameEncrypted` als leeren String. Der alte Klartext
wird von keiner Antwort ausgegeben und von der Migration nicht verändert.

## Wer schuldet was? – `tools/decrypt-users.php`

Das Werkzeug läuft **offline**, auf dem Rechner des Administrators, mit einer
Kopie der SQLite-Datei. Es braucht nur `sodium` und `pdo_sqlite`, keinen
Composer und keine Anwendungsklasse, und öffnet die Datenbank nur lesend.

```bash
# einmalig: Schlüsselpaar erzeugen
php tools/decrypt-users.php --genkey
# private: 9f3c…   -> sicher aufbewahren, z. B. in admin.key
# public:  4846…   -> als adminPublicKey in config.php

# Datenbank vom Server holen und auswerten
scp server:/pfad/data/coffee.sqlite ./coffee.sqlite
php tools/decrypt-users.php --db ./coffee.sqlite --key ./admin.key
```

Ausgabe: eine Zeile pro Konto, sonst nichts.

```
1|Ada|Lovelace|7|750
2|Grace|Hopper|4|350
```

Die Felder sind `id|firstName|lastName|coffees|balanceCents`. Der Preis kommt aus
`config.php` neben dem Werkzeug; alternativ `--price 150` angeben. Passt der
private Schlüssel nicht, endet das Werkzeug mit einer Meldung auf stderr und
einem Rückgabewert ungleich null – ohne eine einzige Datenzeile auszugeben.

Weiterverarbeitung, zum Beispiel „wer schuldet mehr als 5 Euro“:

```bash
php tools/decrypt-users.php --db ./coffee.sqlite --key ./admin.key \
  | awk -F'|' '$5 > 500 { printf "%s %s: %.2f EUR\n", $2, $3, $5/100 }'
```

Zahlungen werden nicht mit dem Werkzeug gebucht, sondern in der Adminansicht der
Anwendung: Konto antippen, Betrag in Cent eintragen, „Zahlung buchen“. Die
Zuordnung erfolgt über die ID, die in beiden Ansichten dieselbe ist.

## Tests

```bash
php tests/CryptoTest.php        # Versiegeln und Hashen, ohne Framework
php -l public/index.php         # Syntaxprüfung
```

## Datenschutz in einem Satz

In der Datenbank stehen: eine ID, ein Chiffrat, ein HMAC, ein Zähler, ein
bezahlter Betrag, Passkey-Daten. Kein Klartextname, keine E-Mail, kein Passwort.
