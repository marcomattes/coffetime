/* Coffee Time – vanilla JavaScript, no build step or external requests. */
(function () {
  'use strict';

  var state = { me: null, users: [], pendingBook: false, adminKey: null };

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

  /* --------------------------------------------------------- Ansichten -- */

  function show(view) {
    el('view-auth').hidden = view !== 'auth';
    el('view-app').hidden = view !== 'app';
    el('view-admin').hidden = !(view === 'app' && state.me && state.me.admin === true);
  }

  function renderMe(me) {
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

  function renderAdmin(users) {
    state.users = users || [];
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
      right.textContent = money(user.balanceCents);
      head.appendChild(left);
      head.appendChild(right);

      var cipher = document.createElement('span');
      cipher.className = 'row-cipher';
      cipher.textContent = user.decryptedName || user.nameEncrypted || '(no ciphertext)';

      row.appendChild(head);
      row.appendChild(cipher);
      container.appendChild(row);
    });
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
          api('/api/stats').then(renderStats).catch(function () {})
        ];
        if (me.admin === true) {
          jobs.push(
            api('/api/admin/users')
              .then(function (data) {
                renderAdmin(data.users);
              })
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

  function addCoffee() {
    var button = el('btn-add');
    busy(button, true);
    api('/api/coffee', {})
      .then(function (data) {
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
    el('btn-add').addEventListener('click', addCoffee);
    el('btn-undo').addEventListener('click', undoCoffee);
    el('btn-logout').addEventListener('click', logout);
    el('private-key-input').addEventListener('change', selectPrivateKey);
    checkPendingBook();
    registerServiceWorker();
    show('auth');
    refresh();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', ready);
  } else {
    ready();
  }
})();
