<?php

declare(strict_types=1);

namespace Coffee;

/**
 * The application shell contains no user data. JavaScript fetches all dynamic
 * content from the API and inserts it using `textContent`.
 */
final class Frontend
{
    public static function shell(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#f6f1ea" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#17120e" media="(prefers-color-scheme: dark)">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<!-- "default" (not black-translucent): iOS then reserves the status bar area
     and picks a legible colour for the clock instead of drawing the page
     underneath the notch. The safe-area padding in style.css covers the
     home indicator and, in landscape, the rounded corners. -->
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="Coffee Time">
<title>Coffee Time</title>
<link rel="manifest" href="/manifest.webmanifest">
<link rel="icon" href="/icons/favicon-32.png" sizes="32x32" type="image/png">
<link rel="apple-touch-icon" sizes="180x180" href="/icons/apple-touch-icon.png">
<link rel="stylesheet" href="/style.css">
</head>
<body>
<div class="safe-top" aria-hidden="true"></div>
<div id="pull-indicator" data-testid="pull-indicator" class="pull" aria-hidden="true"><span class="pull-spinner"></span></div>
<main class="wrap">

  <section id="view-setup" data-testid="view-setup" hidden>
    <h1 class="brand"><span aria-hidden="true">&#9749;</span> Coffee Time</h1>
    <p class="lead">First-run setup &ndash; this instance has no administrator yet.</p>

    <div class="card">
      <h2>Administrator setup</h2>
      <p class="hint">This runs once. A key pair is generated in this browser: the public
        key is sent to the server, the private key stays on this device and is required to
        decrypt names later.</p>
      <div class="field">
        <label for="setup-price">Price per coffee (&euro;)</label>
        <input type="number" id="setup-price" data-testid="setup-price"
               step="0.01" min="0.01" inputmode="decimal" value="1.50">
      </div>
      <div class="field">
        <label for="setup-invite">Invite code</label>
        <input type="text" id="setup-invite" data-testid="setup-invite"
               autocomplete="off" spellcheck="false" minlength="4" maxlength="64">
        <p class="hint">4 to 64 characters. Anyone with this code can register.</p>
      </div>
      <button type="button" id="btn-setup-generate" data-testid="btn-setup-generate" class="btn">Generate key &amp; download</button>
      <p id="setup-status" data-testid="setup-status" class="hint" role="status"></p>
      <button type="button" id="btn-setup-init" data-testid="btn-setup-init" class="btn btn-primary" disabled>Finish setup</button>
    </div>
  </section>

  <section id="view-auth" data-testid="view-auth" hidden>
    <h1 class="brand"><span aria-hidden="true">&#9749;</span> Coffee Time</h1>
    <p class="lead">Sign in with a passkey &ndash; no username or password.</p>
    <p id="nfc-hint" class="hint" hidden>Coffee tag detected &ndash; it will be booked after sign-in.</p>

    <div class="card">
      <h2>Sign in</h2>
      <p class="hint">Your device will select the matching passkey.</p>
      <button type="button" id="btn-login" data-testid="btn-login" class="btn btn-primary">Sign in with passkey</button>

      <details id="password-login" data-testid="password-login" class="fold">
        <summary>Passkeys blocked on this computer?</summary>
        <p class="hint">Administrators can set a password in the admin area and sign in with it here.
          Everyone else signs in with a passkey.</p>
        <div class="field">
          <label for="pw-firstname-input">First name</label>
          <input type="text" id="pw-firstname-input" data-testid="pw-firstname-input"
                 autocomplete="given-name" maxlength="48" spellcheck="false">
        </div>
        <div class="field">
          <label for="pw-lastname-input">Last name</label>
          <input type="text" id="pw-lastname-input" data-testid="pw-lastname-input"
                 autocomplete="family-name" maxlength="48" spellcheck="false">
        </div>
        <div class="field">
          <label for="pw-password-input">Password</label>
          <input type="password" id="pw-password-input" data-testid="pw-password-input"
                 autocomplete="current-password" maxlength="200">
        </div>
        <button type="button" id="btn-login-password" data-testid="btn-login-password" class="btn">Sign in with password</button>
      </details>
    </div>

    <div class="card">
      <h2>New here?</h2>
      <div class="field">
        <label for="firstname-input">First name</label>
        <input type="text" id="firstname-input" data-testid="firstname-input"
               autocomplete="given-name" maxlength="48" spellcheck="false">
      </div>
      <div class="field">
        <label for="lastname-input">Last name</label>
        <input type="text" id="lastname-input" data-testid="lastname-input"
               autocomplete="family-name" maxlength="48" spellcheck="false">
      </div>
      <div class="field">
        <label for="invite-input">Invite code</label>
        <input type="text" id="invite-input" data-testid="invite-input"
               autocomplete="off" spellcheck="false">
      </div>
      <p id="invite-link-hint" data-testid="invite-link-hint" class="hint" hidden>Invite code taken from your link.</p>
      <button type="button" id="btn-register" data-testid="btn-register" class="btn">Create passkey</button>
      <p class="hint">Your name is encrypted and is never stored as plaintext.</p>
    </div>

    <div class="card">
      <h2>Link this device</h2>
      <p class="hint">Already have an account? Generate a code on your signed-in device and
        enter it here to add this device to your account.</p>
      <div class="field">
        <label for="link-code-input">Code</label>
        <input type="text" id="link-code-input" data-testid="link-code-input"
               autocomplete="off" spellcheck="false">
      </div>
      <button type="button" id="btn-link-device" data-testid="btn-link-device" class="btn btn-quiet">Link with code</button>
    </div>

    <p id="auth-error" data-testid="auth-error" class="error" role="alert"></p>
  </section>

  <section id="view-app" data-testid="view-app" hidden>
    <div class="card" id="install-card" data-testid="install-card" hidden>
      <h2>Add to Home Screen</h2>
      <p id="install-text" data-testid="install-text" class="hint"></p>
      <ol id="install-steps" class="install-steps" hidden>
        <li>Tap <strong>Share</strong> in the Safari toolbar (the square with the arrow).</li>
        <li>Scroll down and choose <strong>Add to Home Screen</strong>.</li>
        <li>Confirm with <strong>Add</strong>, then open Coffee Time from the Home Screen.</li>
      </ol>
      <button type="button" id="btn-install" data-testid="btn-install" class="btn btn-primary" hidden>Add to Home Screen</button>
      <button type="button" id="btn-install-dismiss" data-testid="btn-install-dismiss" class="btn btn-quiet">Not now</button>
    </div>

    <div class="card tally">
      <p class="tally-label">My coffees</p>
      <p id="counter" data-testid="counter" class="tally-count">0</p>
      <p id="streak" data-testid="streak" class="hint" hidden></p>
      <p id="queue-hint" data-testid="queue-hint" class="hint" hidden></p>
      <button type="button" id="btn-add" data-testid="btn-add" class="btn btn-add">
        <span aria-hidden="true">&#9749;</span> Take a coffee
      </button>
      <button type="button" id="btn-undo" data-testid="btn-undo" class="btn btn-quiet">Undo last coffee</button>
    </div>

    <div class="card grid">
      <div class="stat">
        <span class="stat-label">Outstanding</span>
        <strong id="balance" data-testid="balance" class="stat-value">0.00 &euro;</strong>
      </div>
      <div class="stat">
        <span class="stat-label">Price</span>
        <strong id="price" data-testid="price" class="stat-value">0.00 &euro;</strong>
      </div>
      <div class="stat">
        <span class="stat-label">My rank</span>
        <strong id="rank" data-testid="rank" class="stat-value">-</strong>
      </div>
      <div class="stat">
        <span class="stat-label">All coffees</span>
        <strong id="total" data-testid="total" class="stat-value">0</strong>
      </div>
      <div class="stat">
        <span class="stat-label">Today</span>
        <strong id="today" data-testid="today" class="stat-value">0</strong>
      </div>
    </div>

    <div class="card">
      <h2>Last 14 days</h2>
      <div id="history-chart" data-testid="history-chart" class="chart"></div>
    </div>

    <div class="card">
      <h2>Leaderboard</h2>
      <p class="hint">Anonymous &ndash; ranks and totals only.</p>
      <ol id="distribution" class="dist"></ol>
    </div>

    <div class="card">
      <h2>Reminders</h2>
      <p id="notify-status" data-testid="notify-status" class="hint" role="status"></p>
      <button type="button" id="btn-notify-enable" data-testid="btn-notify-enable" class="btn btn-quiet" hidden>Enable reminders</button>
      <button type="button" id="btn-notify-install" data-testid="btn-notify-install" class="btn btn-quiet" hidden>Show me how</button>
    </div>

    <div class="card">
      <h2>Devices</h2>
      <p id="device-count" data-testid="device-count" class="hint"></p>
      <button type="button" id="btn-link-code" data-testid="btn-link-code" class="btn btn-quiet">Link another device</button>
      <p id="link-code-display" data-testid="link-code-display" class="link-code" hidden></p>
      <p id="link-code-hint" class="hint" hidden>Enter this code on the new device under &ldquo;Link this device&rdquo;. Valid for 15 minutes.</p>
      <p id="link-code-error" data-testid="link-code-error" class="error" role="alert"></p>
    </div>

    <p id="app-error" class="error" role="alert"></p>
    <button type="button" id="btn-logout" data-testid="btn-logout" class="btn btn-quiet">Sign out</button>
  </section>

  <section id="view-admin" data-testid="view-admin" hidden>
    <div class="card">
      <h2>Settings</h2>
      <div class="settings-form">
        <div class="field">
          <label for="admin-price-input">Price per coffee (&euro;)</label>
          <input type="number" id="admin-price-input" data-testid="admin-price-input"
                 step="0.01" min="0.01" inputmode="decimal">
        </div>
        <div class="field">
          <label for="admin-invite-input">Invite code</label>
          <input type="text" id="admin-invite-input" data-testid="admin-invite-input"
                 autocomplete="off" spellcheck="false" minlength="4" maxlength="64">
        </div>
        <button type="button" id="btn-admin-settings" data-testid="btn-admin-settings" class="btn settings-btn">Save settings</button>
      </div>
      <p id="admin-settings-status" data-testid="admin-settings-status" class="hint" role="status"></p>
    </div>

    <div class="card">
      <h2>Password sign-in</h2>
      <p class="hint">A password for <em>your own</em> admin account, for computers where passkeys are
        blocked. Passkeys keep working; this is an addition, not a replacement.</p>
      <p id="admin-password-state" data-testid="admin-password-state" class="hint" role="status"></p>
      <div class="field">
        <label for="admin-password-input">New password</label>
        <input type="password" id="admin-password-input" data-testid="admin-password-input"
               autocomplete="new-password" maxlength="200">
      </div>
      <div class="field">
        <label for="admin-password-repeat">Repeat password</label>
        <input type="password" id="admin-password-repeat" data-testid="admin-password-repeat"
               autocomplete="new-password" maxlength="200">
      </div>
      <button type="button" id="btn-admin-password" data-testid="btn-admin-password" class="btn">Save password</button>
      <button type="button" id="btn-admin-password-remove" data-testid="btn-admin-password-remove" class="btn btn-quiet" hidden>Remove password</button>
      <p id="admin-password-status" data-testid="admin-password-status" class="error" role="alert"></p>
    </div>

    <div class="card">
      <h2>Administration</h2>
      <p class="hint">Select the RSA private-key PEM file. Decryption happens only in this browser;
        the key is never uploaded or stored.</p>
      <div class="field">
        <label for="private-key-input">Private key file</label>
        <input type="file" id="private-key-input" data-testid="private-key-input" accept=".pem,.key,text/plain">
      </div>
      <p id="admin-key-status" class="hint" role="status">Encrypted names are shown until a key is selected.</p>
      <p id="admin-totals" data-testid="admin-totals" class="hint"></p>
      <button type="button" id="btn-admin-csv" data-testid="btn-admin-csv" class="btn btn-quiet">Export CSV</button>
      <p class="hint">Names appear in the CSV only after the matching private key file has been loaded.</p>
      <p id="admin-status" data-testid="admin-status" class="error" role="alert"></p>
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
