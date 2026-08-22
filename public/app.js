/* Coffee Time – vanilla JavaScript, no build step or external requests. */
(function () {
  'use strict';

  var state = { me: null, users: [], pendingBook: false, adminKey: null, setupPublicKey: null };

  function el(id) {
    return document.getElementById(id);
  }

  function money(cents) {
    var value = typeof cents === 'number' && isFinite(cents) ? cents : 0;
    var sign = value < 0 ? '-' : '';
    return sign + (Math.abs(value) / 100).toFixed(2) + ' €';
  }

  function text(node, value) {
    if (node) {
      node.textContent = value;
    }
  }

  /* ------------------------------------------------------ base64url ------ */

  function toBase64Url(buffer) {
    var bytes = new Uint8Array(buffer);
    var binary = '';
    for (var i = 0; i < bytes.length; i++) {
      binary += String.fromCharCode(bytes[i]);
    }
    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }

  function fromBase64Url(value) {
    var normalized = String(value).replace(/-/g, '+').replace(/_/g, '/');
    while (normalized.length % 4 !== 0) {
      normalized += '=';
    }
    var binary = atob(normalized);
    var bytes = new Uint8Array(binary.length);
    for (var i = 0; i < binary.length; i++) {
      bytes[i] = binary.charCodeAt(i);
    }
    return bytes;
  }

  /* ------------------------------------------------------------ API ----- */

  function api(path, body) {
    var options = {
      method: body === undefined ? 'GET' : 'POST',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' }
    };
    if (body !== undefined) {
      options.headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify(body === null ? {} : body);
    }
    return fetch(path, options).then(function (response) {
      return response.text().then(function (raw) {
        var data = {};
        if (raw) {
          try {
            data = JSON.parse(raw);
          } catch (e) {
            data = {};
          }
        }
        if (!response.ok) {
          var error = new Error(data && data.error ? data.error : 'http_' + response.status);
          error.status = response.status;
          error.code = data && data.error ? data.error : 'http_' + response.status;
          throw error;
        }
        return data;
      });
    });
  }

  /* ---------------------------------------------------- Offline queue --- */

  /*
   * Coffee kitchens have bad wifi: a booking made while offline must not be
   * lost. Every booking attempt carries a client-generated id; if the
   * network request fails outright (not an HTTP error, a fetch rejection),
   * the id is queued in localStorage and retried later with that SAME id,
   * so the server-side idempotency check (see Users::addCoffee) collapses
   * any retries into the single original booking.
   */

  var QUEUE_KEY = 'coffeeQueue';
  var QUEUE_MAX = 50;

  function newEventId() {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') {
      return window.crypto.randomUUID();
    }
    return randomHexId();
  }

  function randomHexId() {
    var bytes = new Uint8Array(16);
    if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
      window.crypto.getRandomValues(bytes);
    } else {
      for (var i = 0; i < bytes.length; i++) {
        bytes[i] = Math.floor(Math.random() * 256);
      }
    }
    var hex = '';
    for (var j = 0; j < bytes.length; j++) {
      var piece = bytes[j].toString(16);
      hex += piece.length === 1 ? '0' + piece : piece;
    }
    return hex;
  }

  /* A network-level failure (fetch rejected) never sets .status; an HTTP
     error response (401, 4xx, 5xx) always does, via api() above. */
  function isNetworkError(error) {
    return !!error && typeof error.status === 'undefined';
  }

  function isQueueEntry(value) {
    return !!value && typeof value.id === 'string' && value.id !== '';
  }

  /* Storage may be unavailable (private browsing, cleared site data, quota) –
     every read and write is wrapped so the app still works, just without a
     persistent queue in that case. */
  function loadQueue() {
    var queue = [];
    try {
      var raw = window.localStorage.getItem(QUEUE_KEY);
      if (raw) {
        var parsed = JSON.parse(raw);
        if (Object.prototype.toString.call(parsed) === '[object Array]') {
          for (var i = 0; i < parsed.length; i++) {
            if (isQueueEntry(parsed[i])) {
              queue.push({
                id: parsed[i].id,
                at: typeof parsed[i].at === 'number' ? parsed[i].at : Date.now()
              });
            }
          }
        }
      }
    } catch (e) {
      queue = [];
    }
    return queue;
  }

  function saveQueue(queue) {
    try {
      window.localStorage.setItem(QUEUE_KEY, JSON.stringify(queue));
    } catch (e) {
      /* Cannot persist (storage unavailable or full) – the in-memory queue
         used for this call still gets its turn; it just will not survive
         a reload. */
    }
  }

  function updateQueueHint(queue) {
    var hint = el('queue-hint');
    if (!hint) {
      return;
    }
    var list = queue || loadQueue();
    if (list.length === 0) {
      hint.hidden = true;
      text(hint, '');
      return;
    }
    hint.hidden = false;
    text(
      hint,
      list.length + (list.length === 1 ? ' booking' : ' bookings') +
        ' waiting for connection — they sync automatically.'
    );
  }

  /* Returns false (and queues nothing) once QUEUE_MAX is reached – the
     caller shows an error instead of silently dropping the tap. */
  function enqueueBooking(id) {
    var queue = loadQueue();
    if (queue.length >= QUEUE_MAX) {
      return false;
    }
    queue.push({ id: id, at: Date.now() });
    saveQueue(queue);
    updateQueueHint(queue);
    return true;
  }

  /*
   * Sends queued bookings one at a time, in order (a promise chain, not
   * parallel requests). A network failure or a 401 stops the flush and
   * keeps the remainder for the next trigger; any other HTTP error (e.g.
   * invalid_event) can never succeed, so that entry is dropped and the
   * flush continues. Never rejects – callers can always chain onto it.
   */
  function flushQueue() {
    return flushStep(loadQueue(), false);
  }

  function flushStep(queue, changed) {
    if (queue.length === 0) {
      return changed ? refresh() : Promise.resolve();
    }
    var entry = queue[0];
    return api('/api/coffee', { eventId: entry.id }).then(
      function () {
        queue.shift();
        saveQueue(queue);
        updateQueueHint(queue);
        return flushStep(queue, true);
      },
      function (error) {
        if (error && error.status === 401) {
          // No session – the user must sign in again before this can sync.
          return changed ? refresh() : undefined;
        }
        if (isNetworkError(error)) {
          // Still offline – stop here, try again on the next trigger.
          return changed ? refresh() : undefined;
        }
        // Any other rejection (e.g. invalid_event) will never succeed.
        queue.shift();
        saveQueue(queue);
        updateQueueHint(queue);
        return flushStep(queue, true);
      }
    );
  }

  /* --------------------------------------------------------- Ansichten -- */

  function show(view) {
    el('view-setup').hidden = view !== 'setup';
    el('view-auth').hidden = view !== 'auth';
    el('view-app').hidden = view !== 'app';
    el('view-admin').hidden = !(view === 'app' && state.me && state.me.admin === true);
  }

  function renderMe(me) {
    // addCoffee()/undoCoffee() rebuild `me` from a smaller response and do
    // not know the current passkey count – fall back to the previous value
    // instead of flashing the device count to 0 in that case.
    var previousCredentials = state.me ? state.me.credentials : undefined;
    state.me = me;
    text(el('counter'), String(me.coffees));
    text(el('balance'), money(me.balanceCents));
    text(el('price'), money(me.priceCents));

    var streakNode = el('streak');
    if (streakNode) {
      var streak = typeof me.streakDays === 'number' ? me.streakDays : 0;
      streakNode.hidden = streak < 2;
      text(streakNode, '🔥 ' + streak + ' days in a row');
    }

    var credentials = typeof me.credentials === 'number' ? me.credentials : previousCredentials;
    if (typeof credentials === 'number') {
      state.me.credentials = credentials;
      text(el('device-count'), credentials + (credentials === 1 ? ' passkey' : ' passkeys'));
    }

    updateBadge(me.balanceCents);
  }

  /* App-Icon-Badge: offener Betrag, aufgerundet auf ganze Euro. Rein kosmetisch. */
  function updateBadge(balanceCents) {
    if (!('setAppBadge' in navigator)) {
      return;
    }
    var amount = Math.round((typeof balanceCents === 'number' ? balanceCents : 0) / 100);
    try {
      if (amount > 0) {
        navigator.setAppBadge(amount).catch(function () {});
      } else if ('clearAppBadge' in navigator) {
        navigator.clearAppBadge().catch(function () {});
      }
    } catch (e) {
      /* Badging API ist ein Bonus, kein Muss. */
    }
  }

  function renderHistory(history) {
    text(el('today'), String(history && typeof history.today === 'number' ? history.today : 0));

    var container = el('history-chart');
    if (!container) {
      return;
    }
    container.textContent = '';

    var days = (history && history.days ? history.days : []).slice(-14);
    var max = 0;
    days.forEach(function (day) {
      if (typeof day.coffees === 'number' && day.coffees > max) {
        max = day.coffees;
      }
    });

    days.forEach(function (day, index) {
      var isToday = index === days.length - 1;
      var coffees = typeof day.coffees === 'number' ? day.coffees : 0;
      var pct = max > 0 ? Math.round((coffees / max) * 100) : 0;
      var label = day.date + ': ' + coffees + (coffees === 1 ? ' coffee' : ' coffees');
      var date = new Date(day.date + 'T00:00:00Z');

      var col = document.createElement('div');
      col.className = 'chart-col' + (isToday ? ' chart-col-today' : '');
      col.setAttribute('data-testid', 'history-day');
      col.setAttribute('role', 'img');
      col.setAttribute('aria-label', label);
      col.title = label;

      var track = document.createElement('div');
      track.className = 'chart-track';
      track.setAttribute('aria-hidden', 'true');

      var bar = document.createElement('div');
      bar.className = 'chart-bar' + (isToday ? ' bar-today' : '');
      bar.style.height = pct + '%';
      track.appendChild(bar);

      var dayLabel = document.createElement('span');
      dayLabel.className = 'chart-label';
      dayLabel.setAttribute('aria-hidden', 'true');
      dayLabel.textContent = String(date.getUTCDate());

      col.appendChild(track);
      col.appendChild(dayLabel);
      container.appendChild(col);
    });
  }

  function renderStats(stats) {
    text(el('total'), String(stats.total));
    text(el('rank'), stats.rank === null || stats.rank === undefined ? '-' : String(stats.rank));

    var list = el('distribution');
    if (!list) {
      return;
    }
    list.textContent = '';
    var mine = state.me ? state.me.coffees : null;
    var myRank = stats.rank;
    var marked = false;
    (stats.distribution || []).forEach(function (entry) {
      var item = document.createElement('li');
      var left = document.createElement('span');
      left.textContent = 'Rank ' + entry.rank;
      var right = document.createElement('span');
      right.textContent = entry.coffees + (entry.coffees === 1 ? ' coffee' : ' coffees');
      if (!marked && entry.rank === myRank && entry.coffees === mine) {
        item.className = 'me';
        left.textContent = 'Rank ' + entry.rank + ' (me)';
        marked = true;
      }
      item.appendChild(left);
      item.appendChild(right);
      list.appendChild(item);
    });
  }

  function renderAdminTotals(users) {
    var totalsNode = el('admin-totals');
    if (!totalsNode) {
      return;
    }
    var coffees = 0;
    var balance = 0;
    users.forEach(function (user) {
      coffees += typeof user.coffees === 'number' ? user.coffees : 0;
      balance += typeof user.balanceCents === 'number' ? user.balanceCents : 0;
    });
    text(
      totalsNode,
      users.length + (users.length === 1 ? ' account' : ' accounts') +
        ' · ' + coffees + (coffees === 1 ? ' coffee' : ' coffees') +
        ' · ' + money(balance) + ' outstanding'
    );
  }

  function renderAdminSettings(settings) {
    var priceInput = el('admin-price-input');
    var inviteInput = el('admin-invite-input');
    if (priceInput && settings && typeof settings.priceCents === 'number') {
      priceInput.value = (settings.priceCents / 100).toFixed(2);
    }
    if (inviteInput && settings && typeof settings.invite === 'string') {
      inviteInput.value = settings.invite;
    }
  }

  function renderAdmin(users) {
    state.users = users || [];
    renderAdminTotals(state.users);

    var container = el('admin-users');
    if (!container) {
      return;
    }
    container.textContent = '';
    if (state.users.length === 0) {
      var empty = document.createElement('p');
      empty.className = 'hint';
      empty.textContent = 'No accounts yet.';
      container.appendChild(empty);
      return;
    }

    state.users.forEach(function (user) {
      var row = document.createElement('div');
      row.className = 'row';
      row.setAttribute('data-testid', 'admin-row');
      row.setAttribute('data-user-id', user.id);

      var head = document.createElement('span');
      head.className = 'row-head';
      var left = document.createElement('span');
      left.textContent = 'Account ' + user.id + ' · ' + user.coffees + (user.coffees === 1 ? ' coffee' : ' coffees');

      var right = document.createElement('span');
      right.className = 'row-amounts';
      var balanceLine = document.createElement('strong');
      balanceLine.textContent = money(user.balanceCents);
      var paidLine = document.createElement('span');
      paidLine.className = 'row-paid';
      paidLine.textContent = 'paid ' + money(user.paidCents);
      right.appendChild(balanceLine);
      right.appendChild(paidLine);

      head.appendChild(left);
      head.appendChild(right);

      var cipher = document.createElement('span');
      cipher.className = 'row-cipher';
      cipher.textContent = user.decryptedName || user.nameEncrypted || '(no ciphertext)';

      var form = document.createElement('div');
      form.className = 'row-payment';

      var input = document.createElement('input');
      input.type = 'number';
      input.step = '0.01';
      input.min = '0.01';
      input.setAttribute('inputmode', 'decimal');
      input.placeholder = '€';
      input.className = 'row-payment-input';
      input.setAttribute('data-testid', 'admin-payment-input');
      input.setAttribute('aria-label', 'Payment amount for account ' + user.id);

      var payButton = document.createElement('button');
      payButton.type = 'button';
      payButton.className = 'btn btn-quiet row-payment-btn';
      payButton.textContent = 'Record payment';
      payButton.setAttribute('data-testid', 'admin-payment-btn');
      payButton.addEventListener('click', function () {
        recordPayment(user, input, payButton);
      });

      var recoveryButton = document.createElement('button');
      recoveryButton.type = 'button';
      recoveryButton.className = 'btn btn-quiet row-payment-btn';
      recoveryButton.textContent = 'Recovery code';
      recoveryButton.setAttribute('data-testid', 'admin-recovery-btn');

      var recoveryCode = document.createElement('span');
      recoveryCode.className = 'hint row-recovery';
      recoveryCode.setAttribute('data-testid', 'admin-recovery-code');
      recoveryCode.hidden = true;

      recoveryButton.addEventListener('click', function () {
        requestRecoveryCode(user, recoveryButton, recoveryCode);
      });

      form.appendChild(input);
      form.appendChild(payButton);
      form.appendChild(recoveryButton);

      row.appendChild(head);
      row.appendChild(cipher);
      row.appendChild(form);
      row.appendChild(recoveryCode);
      container.appendChild(row);
    });
  }

  function requestRecoveryCode(user, button, node) {
    busy(button, true);
    api('/api/admin/link-code', { userId: user.id })
      .then(function (data) {
        text(node, 'Code ' + data.code + ' — valid 60 min');
        node.hidden = false;
      })
      .catch(function (error) {
        text(node, error && error.code ? error.code : 'unknown_error');
        node.hidden = false;
      })
      .then(function () {
        busy(button, false);
      });
  }

  function recordPayment(user, input, button) {
    var statusNode = el('admin-status');
    text(statusNode, '');

    var value = parseFloat(input.value);
    if (!isFinite(value) || value <= 0 || value > 10000) {
      text(statusNode, 'invalid_amount');
      return;
    }
    var amountCents = Math.round(value * 100);

    busy(button, true);
    api('/api/admin/payment', { userId: user.id, amountCents: amountCents })
      .then(function (data) {
        var updated = data.user;
        if (user.decryptedName) {
          updated.decryptedName = user.decryptedName;
        }
        for (var i = 0; i < state.users.length; i++) {
          if (state.users[i].id === user.id) {
            state.users[i] = updated;
            break;
          }
        }
        renderAdmin(state.users);
      })
      .catch(function (error) {
        fail(statusNode, error);
      })
      .then(function () {
        busy(button, false);
      });
  }

  function saveAdminSettings() {
    var button = el('btn-admin-settings');
    var statusNode = el('admin-settings-status');
    text(statusNode, '');

    var priceValue = parseFloat(el('admin-price-input').value);
    if (!isFinite(priceValue) || priceValue <= 0 || priceValue > 1000) {
      text(statusNode, 'invalid_settings');
      return;
    }
    var priceCents = Math.round(priceValue * 100);

    var inviteRaw = el('admin-invite-input').value;
    var invite = typeof inviteRaw === 'string' ? inviteRaw.trim() : '';
    if (invite.length < 4 || invite.length > 64) {
      text(statusNode, 'invalid_settings');
      return;
    }

    busy(button, true);
    api('/api/admin/settings/update', { priceCents: priceCents, invite: invite })
      .then(function () {
        return refresh();
      })
      .then(function () {
        text(statusNode, 'Saved. New bookings use the new price; existing tabs are unchanged.');
      })
      .catch(function (error) {
        fail(statusNode, error);
      })
      .then(function () {
        busy(button, false);
      });
  }

  function csvField(value) {
    var str = value === undefined || value === null ? '' : String(value);
    if (/[",\n]/.test(str)) {
      str = '"' + str.replace(/"/g, '""') + '"';
    }
    return str;
  }

  function exportAdminCsv() {
    var rows = [['id', 'name', 'coffees', 'paidEuros', 'balanceEuros']];
    state.users.forEach(function (user) {
      rows.push([
        user.id,
        user.decryptedName || '',
        typeof user.coffees === 'number' ? user.coffees : 0,
        ((typeof user.paidCents === 'number' ? user.paidCents : 0) / 100).toFixed(2),
        ((typeof user.balanceCents === 'number' ? user.balanceCents : 0) / 100).toFixed(2)
      ]);
    });
    var csv = rows.map(function (row) {
      return row.map(csvField).join(',');
    }).join('\r\n');

    var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    var url = URL.createObjectURL(blob);
    var link = document.createElement('a');
    link.href = url;
    link.download = 'coffee-time.csv';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
  }

  function downloadTextFile(content, filename) {
    var blob = new Blob([content], { type: 'application/x-pem-file;charset=utf-8;' });
    var url = URL.createObjectURL(blob);
    var link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
  }

  /* DER (ArrayBuffer) -> PEM text, wrapped at 64 base64 characters per line. */
  function toPem(buffer, label) {
    var bytes = new Uint8Array(buffer);
    var binary = '';
    for (var i = 0; i < bytes.length; i++) {
      binary += String.fromCharCode(bytes[i]);
    }
    var base64 = btoa(binary);
    var lines = [];
    for (var j = 0; j < base64.length; j += 64) {
      lines.push(base64.slice(j, j + 64));
    }
    return '-----BEGIN ' + label + '-----\n' + lines.join('\n') + '\n-----END ' + label + '-----\n';
  }

  function pemBytes(pem) {
    var normalized = pem.replace(/-----BEGIN PRIVATE KEY-----|-----END PRIVATE KEY-----|\s/g, '');
    if (!normalized) throw new Error('invalid_key');
    var binary = atob(normalized);
    var bytes = new Uint8Array(binary.length);
    for (var i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
    return bytes;
  }

  function decryptAdminName(user) {
    var prefix = 'rsa-oaep-sha1:';
    if (!state.adminKey || !user.nameEncrypted || user.nameEncrypted.indexOf(prefix) !== 0) return Promise.resolve();
    var raw = atob(user.nameEncrypted.slice(prefix.length));
    var bytes = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) bytes[i] = raw.charCodeAt(i);
    return crypto.subtle.decrypt({ name: 'RSA-OAEP' }, state.adminKey, bytes).then(function (plain) {
      var data = JSON.parse(new TextDecoder().decode(plain));
      user.decryptedName = (String(data.firstName || '') + ' ' + String(data.lastName || '')).trim();
    });
  }

  function selectPrivateKey(event) {
    var file = event.target.files && event.target.files[0];
    var status = el('admin-key-status');
    state.adminKey = null;
    state.users.forEach(function (user) { delete user.decryptedName; });
    if (!file || !window.crypto || !crypto.subtle) {
      text(status, 'Web Crypto is unavailable or no file was selected.');
      renderAdmin(state.users);
      return;
    }
    file.text().then(function (pem) {
      return crypto.subtle.importKey('pkcs8', pemBytes(pem), { name: 'RSA-OAEP', hash: 'SHA-1' }, false, ['decrypt']);
    }).then(function (key) {
      state.adminKey = key;
      return Promise.all(state.users.map(decryptAdminName));
    }).then(function () {
      text(status, 'Names decrypted locally. The key has not left this browser.');
      renderAdmin(state.users);
    }).catch(function () {
      state.adminKey = null;
      text(status, 'Could not decrypt names. Check that this is the matching PKCS#8 key.');
      renderAdmin(state.users);
    });
  }

  /* ----------------------------------------------------------- Laden ---- */

  function refresh() {
    return api('/api/me')
      .then(function (me) {
        renderMe(me);
        show('app');
        consumePendingBook();
        var jobs = [
          api('/api/stats').then(renderStats).catch(function () {}),
          api('/api/history').then(renderHistory).catch(function () {})
        ];
        if (me.admin === true) {
          jobs.push(
            api('/api/admin/users')
              .then(function (data) {
                renderAdmin(data.users);
              })
              .catch(function () {})
          );
          jobs.push(
            api('/api/admin/settings')
              .then(renderAdminSettings)
              .catch(function () {})
          );
        }
        return Promise.all(jobs);
      })
      .catch(function () {
        state.me = null;
        show('auth');
      });
  }

  /* ------------------------------------------------------- WebAuthn ----- */

  function creationOptions(options) {
    var selection = options.authenticatorSelection || {};
    return {
      rp: { id: options.rp.id, name: options.rp.name },
      user: {
        id: fromBase64Url(options.user.id),
        name: options.user.name,
        displayName: options.user.displayName
      },
      challenge: fromBase64Url(options.challenge),
      pubKeyCredParams: options.pubKeyCredParams || [],
      // Nur die Felder weitergeben, die der Browser kennt.
      authenticatorSelection: {
        residentKey: selection.residentKey,
        requireResidentKey: selection.requireResidentKey === true,
        userVerification: selection.userVerification
      },
      attestation: options.attestation,
      timeout: options.timeout,
      excludeCredentials: (options.excludeCredentials || []).map(function (item) {
        return { id: fromBase64Url(item.id), type: item.type, transports: item.transports };
      })
    };
  }

  function requestOptions(options) {
    return {
      challenge: fromBase64Url(options.challenge),
      rpId: options.rpId,
      userVerification: options.userVerification,
      timeout: options.timeout,
      allowCredentials: (options.allowCredentials || []).map(function (item) {
        return { id: fromBase64Url(item.id), type: item.type, transports: item.transports };
      })
    };
  }

  function serializeAttestation(credential) {
    var response = credential.response;
    var transports = [];
    if (typeof response.getTransports === 'function') {
      try {
        transports = response.getTransports() || [];
      } catch (e) {
        transports = [];
      }
    }
    return {
      id: credential.id,
      rawId: toBase64Url(credential.rawId),
      type: credential.type,
      response: {
        clientDataJSON: toBase64Url(response.clientDataJSON),
        attestationObject: toBase64Url(response.attestationObject),
        transports: transports
      },
      clientExtensionResults: credential.getClientExtensionResults
        ? credential.getClientExtensionResults()
        : {}
    };
  }

  function serializeAssertion(credential) {
    var response = credential.response;
    return {
      id: credential.id,
      rawId: toBase64Url(credential.rawId),
      type: credential.type,
      response: {
        clientDataJSON: toBase64Url(response.clientDataJSON),
        authenticatorData: toBase64Url(response.authenticatorData),
        signature: toBase64Url(response.signature),
        userHandle: response.userHandle ? toBase64Url(response.userHandle) : null
      },
      clientExtensionResults: credential.getClientExtensionResults
        ? credential.getClientExtensionResults()
        : {}
    };
  }

  /* ------------------------------------------------------- Aktionen ----- */

  function busy(button, isBusy) {
    if (button) {
      button.disabled = isBusy;
    }
  }

  function fail(node, error) {
    text(node, error && error.code ? error.code : 'unknown_error');
  }

  function register() {
    var button = el('btn-register');
    var errorNode = el('auth-error');
    text(errorNode, '');
    if (!window.PublicKeyCredential) {
      text(errorNode, 'passkeys_unavailable');
      return;
    }
    var payload = {
      firstName: el('firstname-input').value,
      lastName: el('lastname-input').value,
      invite: el('invite-input').value
    };
    busy(button, true);
    api('/api/register/options', payload)
      .then(function (options) {
        return navigator.credentials.create({ publicKey: creationOptions(options) });
      })
      .then(function (credential) {
        if (!credential) {
          throw new Error('cancelled');
        }
        var body = {
          firstName: payload.firstName,
          lastName: payload.lastName,
          invite: payload.invite,
          credential: serializeAttestation(credential)
        };
        return api('/api/register/verify', body);
      })
      .then(function () {
        el('invite-input').value = '';
        return refresh();
      })
      .catch(function (error) {
        fail(errorNode, error);
      })
      .then(function () {
        busy(button, false);
      });
  }

  function login() {
    var button = el('btn-login');
    var errorNode = el('auth-error');
    text(errorNode, '');
    if (!window.PublicKeyCredential) {
      text(errorNode, 'passkeys_unavailable');
      return;
    }
    busy(button, true);
    api('/api/login/options', {})
      .then(function (options) {
        return navigator.credentials.get({ publicKey: requestOptions(options) });
      })
      .then(function (credential) {
        if (!credential) {
          throw new Error('cancelled');
        }
        return api('/api/login/verify', { credential: serializeAssertion(credential) });
      })
      .then(function () {
        return refresh();
      })
      .catch(function (error) {
        fail(errorNode, error);
      })
      .then(function () {
        busy(button, false);
      });
  }

  function linkDevice() {
    var button = el('btn-link-device');
    var errorNode = el('auth-error');
    text(errorNode, '');
    if (!window.PublicKeyCredential) {
      text(errorNode, 'passkeys_unavailable');
      return;
    }
    var code = el('link-code-input').value;
    busy(button, true);
    api('/api/link/options', { code: code })
      .then(function (options) {
        return navigator.credentials.create({ publicKey: creationOptions(options) });
      })
      .then(function (credential) {
        if (!credential) {
          throw new Error('cancelled');
        }
        return api('/api/link/verify', { code: code, credential: serializeAttestation(credential) });
      })
      .then(function () {
        el('link-code-input').value = '';
        return refresh();
      })
      .catch(function (error) {
        fail(errorNode, error);
      })
      .then(function () {
        busy(button, false);
      });
  }

  function linkCode() {
    var button = el('btn-link-code');
    var errorNode = el('link-code-error');
    var display = el('link-code-display');
    var hint = el('link-code-hint');
    text(errorNode, '');
    busy(button, true);
    api('/api/link/code', {})
      .then(function (data) {
        text(display, data.code);
        display.hidden = false;
        hint.hidden = false;
      })
      .catch(function (error) {
        fail(errorNode, error);
      })
      .then(function () {
        busy(button, false);
      });
  }

  function generateSetupKey() {
    var button = el('btn-setup-generate');
    var initButton = el('btn-setup-init');
    var status = el('setup-status');
    text(status, '');
    if (!window.crypto || !crypto.subtle || typeof crypto.subtle.generateKey !== 'function') {
      text(status, 'Setup needs a modern browser with Web Crypto support.');
      return;
    }
    busy(button, true);
    crypto.subtle.generateKey(
      { name: 'RSA-OAEP', modulusLength: 4096, publicExponent: new Uint8Array([1, 0, 1]), hash: 'SHA-1' },
      true,
      ['encrypt', 'decrypt']
    )
      .then(function (pair) {
        return Promise.all([
          crypto.subtle.exportKey('pkcs8', pair.privateKey),
          crypto.subtle.exportKey('spki', pair.publicKey)
        ]);
      })
      .then(function (exported) {
        var privatePem = toPem(exported[0], 'PRIVATE KEY');
        var publicPem = toPem(exported[1], 'PUBLIC KEY');
        state.setupPublicKey = publicPem;
        downloadTextFile(privatePem, 'admin-private.pem');
        text(
          status,
          'Key file downloaded. Store it safely — without it, names can never be ' +
            'decrypted. It is never uploaded.'
        );
        if (initButton) {
          initButton.disabled = false;
        }
      })
      .catch(function () {
        state.setupPublicKey = null;
        text(status, 'Could not generate the key pair in this browser.');
      })
      .then(function () {
        busy(button, false);
      });
  }

  function initSetup() {
    var button = el('btn-setup-init');
    var status = el('setup-status');

    var priceValue = parseFloat(el('setup-price').value);
    if (!isFinite(priceValue) || priceValue <= 0 || priceValue > 1000) {
      text(status, 'invalid_price');
      return;
    }
    var priceCents = Math.round(priceValue * 100);

    var inviteRaw = el('setup-invite').value;
    var invite = typeof inviteRaw === 'string' ? inviteRaw.trim() : '';
    if (invite.length < 4 || invite.length > 64) {
      text(status, 'invalid_invite');
      return;
    }

    if (!state.setupPublicKey) {
      text(status, 'Generate the key first.');
      return;
    }

    busy(button, true);
    api('/api/setup/init', { adminPublicKey: state.setupPublicKey, priceCents: priceCents, invite: invite })
      .then(function () {
        text(status, 'Setup complete — register the first account below; it becomes the administrator.');
        show('auth');
      })
      .catch(function (error) {
        text(status, error && error.code ? error.code : 'unknown_error');
      })
      .then(function () {
        busy(button, false);
      });
  }

  function addCoffee() {
    var button = el('btn-add');
    var eventId = newEventId();
    busy(button, true);
    api('/api/coffee', { eventId: eventId })
      .then(
        function (data) {
          renderMe({
            id: state.me ? state.me.id : '',
            admin: state.me ? state.me.admin : false,
            coffees: data.coffees,
            balanceCents: data.balanceCents,
            priceCents: state.me ? state.me.priceCents : 0
          });
          vibrate([18, 40, 18]);
          bump(el('counter'));
          bump(el('btn-add'));
          return refresh();
        },
        function (error) {
          // Only a rejected /api/coffee call lands here – a failure while
          // rendering the result afterwards does not (see the .then/.catch
          // split below), so a successful booking is never re-queued.
          if (error && error.status === 401) {
            show('auth');
            return;
          }
          if (isNetworkError(error)) {
            if (!enqueueBooking(eventId)) {
              fail(el('app-error'), { code: 'queue_full' });
            }
            return;
          }
          fail(el('app-error'), error);
        }
      )
      .then(function () {
        return flushQueue();
      })
      .catch(function () {
        /* flushQueue() never rejects; this only guards busy() below. */
      })
      .then(function () {
        busy(button, false);
      });
  }

  function undoCoffee() {
    var button = el('btn-undo');
    busy(button, true);
    api('/api/coffee/undo', {})
      .then(function (data) {
        renderMe({
          id: state.me ? state.me.id : '',
          admin: state.me ? state.me.admin : false,
          coffees: data.coffees,
          balanceCents: data.balanceCents,
          priceCents: state.me ? state.me.priceCents : 0
        });
        return refresh();
      })
      .catch(function (error) {
        if (error && error.status === 401) {
          show('auth');
        } else {
          fail(el('app-error'), error);
        }
      })
      .then(function () {
        busy(button, false);
      });
  }

  function logout() {
    api('/api/logout', {})
      .catch(function () {})
      .then(function () {
        state.me = null;
        show('auth');
        if ('clearAppBadge' in navigator) {
          navigator.clearAppBadge().catch(function () {});
        }
      });
  }

  /* ------------------------------------------------------- Feedback ----- */

  /* Kurzes, zufriedenes Doppel-Summen – bewusst kein einzelner harter Ruck. */
  function vibrate(pattern) {
    if (window.navigator && typeof window.navigator.vibrate === 'function') {
      try {
        window.navigator.vibrate(pattern);
      } catch (e) {
        /* Manche Browser werfen ausserhalb einer Nutzergeste – einfach ignorieren. */
      }
    }
  }

  function bump(node) {
    if (!node) {
      return;
    }
    node.classList.remove('bump');
    // Reflow erzwingen, damit die Animation bei wiederholtem Antippen neu startet.
    void node.offsetWidth;
    node.classList.add('bump');
  }

  /* ------------------------------------------------- Shortcut / NFC-Tag -- */

  /*
   * "/?book=1" immediately books a coffee. It is shared by the app shortcut
   * and NFC tags, and is removed immediately to prevent duplicate bookings.
   */
  function checkPendingBook() {
    var params = new URLSearchParams(window.location.search);
    if (params.get('book') !== '1') {
      return;
    }
    state.pendingBook = true;
    window.history.replaceState(null, '', window.location.pathname);
    var hint = el('nfc-hint');
    if (hint) {
      hint.hidden = false;
    }
  }

  function consumePendingBook() {
    if (!state.pendingBook) {
      return;
    }
    state.pendingBook = false;
    addCoffee();
  }

  /* ---------------------------------------------------------- Start ----- */

  function registerServiceWorker() {
    if ('serviceWorker' in navigator) {
      navigator.serviceWorker.register('/sw.js').catch(function () {
        /* Offline caching is optional; the app works without a service worker. */
      });
    }
  }

  function ready() {
    el('btn-register').addEventListener('click', register);
    el('btn-login').addEventListener('click', login);
    el('btn-link-device').addEventListener('click', linkDevice);
    el('btn-link-code').addEventListener('click', linkCode);
    el('btn-setup-generate').addEventListener('click', generateSetupKey);
    el('btn-setup-init').addEventListener('click', initSetup);
    el('btn-add').addEventListener('click', addCoffee);
    el('btn-undo').addEventListener('click', undoCoffee);
    el('btn-logout').addEventListener('click', logout);
    el('private-key-input').addEventListener('change', selectPrivateKey);
    el('btn-admin-csv').addEventListener('click', exportAdminCsv);
    el('btn-admin-settings').addEventListener('click', saveAdminSettings);
    checkPendingBook();
    registerServiceWorker();
    updateQueueHint();
    window.addEventListener('online', function () {
      flushQueue();
    });

    function startNormalFlow() {
      show('auth');
      return refresh().then(function () {
        return flushQueue();
      });
    }

    api('/api/setup/status')
      .then(function (status) {
        if (status && status.needsSetup) {
          show('setup');
          return undefined;
        }
        return startNormalFlow();
      })
      .catch(function () {
        return startNormalFlow();
      });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', ready);
  } else {
    ready();
  }
})();
