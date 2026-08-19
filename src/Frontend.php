<?php

declare(strict_types=1);

namespace Coffee;

/**
 * Der HTML-Rumpf der Anwendung. Enthält keine Nutzdaten – alles Dynamische
 * holt sich `app.js` über die API und schreibt es per `textContent`.
 */
final class Frontend
{
    public static function shell(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#f6f1ea" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#17120e" media="(prefers-color-scheme: dark)">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="Kaffeeliste">
<title>Kaffeeliste</title>
<link rel="manifest" href="/manifest.webmanifest">
<link rel="icon" href="/icons/favicon-32.png" sizes="32x32" type="image/png">
<link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
<link rel="stylesheet" href="/style.css">
</head>
<body>
<main class="wrap">

  <section id="view-auth" data-testid="view-auth" hidden>
    <h1 class="brand"><span aria-hidden="true">&#9749;</span> Kaffeeliste</h1>
    <p class="lead">Anmeldung mit Passkey &ndash; ohne Benutzername, ohne Passwort.</p>

    <div class="card">
      <h2>Anmelden</h2>
      <p class="hint">Dein Gerät wählt den passenden Passkey selbst.</p>
      <button type="button" id="btn-login" data-testid="btn-login" class="btn btn-primary">Mit Passkey anmelden</button>
    </div>

    <div class="card">
      <h2>Neu dabei?</h2>
      <div class="field">
        <label for="firstname-input">Vorname</label>
        <input type="text" id="firstname-input" data-testid="firstname-input"
               autocomplete="given-name" maxlength="64" spellcheck="false">
      </div>
      <div class="field">
        <label for="lastname-input">Nachname</label>
        <input type="text" id="lastname-input" data-testid="lastname-input"
               autocomplete="family-name" maxlength="64" spellcheck="false">
      </div>
      <div class="field">
        <label for="invite-input">Einladungscode</label>
        <input type="text" id="invite-input" data-testid="invite-input"
               autocomplete="off" spellcheck="false">
      </div>
      <button type="button" id="btn-register" data-testid="btn-register" class="btn">Passkey einrichten</button>
      <p class="hint">Dein Name wird verschlüsselt gespeichert und nie im Klartext abgelegt.</p>
    </div>

    <p id="auth-error" data-testid="auth-error" class="error" role="alert"></p>
  </section>

  <section id="view-app" data-testid="view-app" hidden>
    <div class="card tally">
      <p class="tally-label">Meine Kaffees</p>
      <p id="counter" data-testid="counter" class="tally-count">0</p>
      <button type="button" id="btn-add" data-testid="btn-add" class="btn btn-add">
        <span aria-hidden="true">&#9749;</span> Kaffee nehmen
      </button>
      <button type="button" id="btn-undo" data-testid="btn-undo" class="btn btn-quiet">Letzten zurücknehmen</button>
    </div>

    <div class="card grid">
      <div class="stat">
        <span class="stat-label">Offen</span>
        <strong id="balance" data-testid="balance" class="stat-value">0.00 &euro;</strong>
      </div>
      <div class="stat">
        <span class="stat-label">Preis</span>
        <strong id="price" data-testid="price" class="stat-value">0.00 &euro;</strong>
      </div>
      <div class="stat">
        <span class="stat-label">Mein Platz</span>
        <strong id="rank" data-testid="rank" class="stat-value">-</strong>
      </div>
      <div class="stat">
        <span class="stat-label">Alle Kaffees</span>
        <strong id="total" data-testid="total" class="stat-value">0</strong>
      </div>
    </div>

    <div class="card">
      <h2>Rangliste</h2>
      <p class="hint">Anonym &ndash; nur Platz und Anzahl.</p>
      <ol id="distribution" class="dist"></ol>
    </div>

    <p id="app-error" class="error" role="alert"></p>
    <button type="button" id="btn-logout" data-testid="btn-logout" class="btn btn-quiet">Abmelden</button>
  </section>

  <section id="view-admin" data-testid="view-admin" hidden>
    <div class="card">
      <h2>Verwaltung</h2>
      <p class="hint">Namen bleiben verschlüsselt &ndash; auch hier. Wer wieviel schuldet, klärt der
        Offline-Export mit <code>tools/decrypt-users.php --xlsx</code> ausserhalb der App.</p>
      <div id="admin-users" data-testid="admin-users" class="rows"></div>
    </div>
  </section>

</main>
<script src="/app.js"></script>
</body>
</html>
HTML;
    }
}
