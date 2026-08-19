/* Kaffeeliste – Vanilla JS, kein Build, keine externen Aufrufe. */
(function () {
  'use strict';

  var state = { me: null, users: [], selectedId: null };

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
      left.textContent = 'Platz ' + entry.rank;
      var right = document.createElement('span');
      right.textContent = entry.coffees + (entry.coffees === 1 ? ' Kaffee' : ' Kaffees');
      if (!marked && entry.rank === myRank && entry.coffees === mine) {
        item.className = 'me';
        left.textContent = 'Platz ' + entry.rank + ' (ich)';
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
      empty.textContent = 'Noch niemand angemeldet.';
      container.appendChild(empty);
      state.selectedId = null;
      return;
    }
    var stillThere = state.users.some(function (user) {
      return user.id === state.selectedId;
    });
    if (!stillThere) {
      state.selectedId = state.users[0].id;
    }

    state.users.forEach(function (user) {
      var row = document.createElement('button');
      row.type = 'button';
      row.className = 'row';
      row.setAttribute('data-testid', 'admin-row');
      row.setAttribute('data-user-id', user.id);
      row.setAttribute('aria-pressed', user.id === state.selectedId ? 'true' : 'false');

      var head = document.createElement('span');
      head.className = 'row-head';
      var left = document.createElement('span');
      left.textContent = 'Konto ' + user.id + ' · ' + user.coffees + ' Kaffees';
      var right = document.createElement('span');
      right.textContent = money(user.balanceCents);
      head.appendChild(left);
      head.appendChild(right);

      var cipher = document.createElement('span');
      cipher.className = 'row-cipher';
      cipher.textContent = user.nameEncrypted || '(kein Chiffrat)';

      row.appendChild(head);
      row.appendChild(cipher);
      row.addEventListener('click', function () {
        state.selectedId = user.id;
        renderAdmin(state.users);
      });
      container.appendChild(row);
    });
  }

  /* ----------------------------------------------------------- Laden ---- */

  function refresh() {
    return api('/api/me')
      .then(function (me) {
        renderMe(me);
        show('app');
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
    text(node, error && error.code ? error.code : 'unbekannter_fehler');
  }

  function register() {
    var button = el('btn-register');
    var errorNode = el('auth-error');
    text(errorNode, '');
    if (!window.PublicKeyCredential) {
      text(errorNode, 'passkeys_nicht_verfuegbar');
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
          throw new Error('abgebrochen');
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
      text(errorNode, 'passkeys_nicht_verfuegbar');
      return;
    }
    busy(button, true);
    api('/api/login/options', {})
      .then(function (options) {
        return navigator.credentials.get({ publicKey: requestOptions(options) });
      })
      .then(function (credential) {
        if (!credential) {
          throw new Error('abgebrochen');
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
      });
  }

  function addDevice() {
    var button = el('btn-add-device');
    var note = el('device-note');
    text(note, '');
    if (!window.PublicKeyCredential) {
      text(note, 'passkeys_nicht_verfuegbar');
      return;
    }
    var payload = { invite: el('device-invite-input').value };
    busy(button, true);
    api('/api/register/options', payload)
      .then(function (options) {
        return navigator.credentials.create({ publicKey: creationOptions(options) });
      })
      .then(function (credential) {
        if (!credential) {
          throw new Error('abgebrochen');
        }
        return api('/api/register/verify', {
          invite: payload.invite,
          credential: serializeAttestation(credential)
        });
      })
      .then(function () {
        el('device-invite-input').value = '';
        text(note, 'Gerät hinzugefügt.');
        return refresh();
      })
      .catch(function (error) {
        fail(note, error);
      })
      .then(function () {
        busy(button, false);
      });
  }

  function book() {
    var errorNode = el('admin-error');
    text(errorNode, '');
    var amount = parseInt(el('admin-amount').value, 10);
    if (!state.selectedId) {
      text(errorNode, 'kein_konto_gewaehlt');
      return;
    }
    if (!isFinite(amount) || amount < 0) {
      text(errorNode, 'ungueltiger_betrag');
      return;
    }
    var button = el('btn-book');
    busy(button, true);
    api('/api/admin/payment', { userId: state.selectedId, amountCents: amount })
      .then(function () {
        el('admin-amount').value = '0';
        return refresh();
      })
      .catch(function (error) {
        fail(errorNode, error);
      })
      .then(function () {
        busy(button, false);
      });
  }

  /* ---------------------------------------------------------- Start ----- */

  function ready() {
    el('btn-register').addEventListener('click', register);
    el('btn-login').addEventListener('click', login);
    el('btn-add').addEventListener('click', addCoffee);
    el('btn-undo').addEventListener('click', undoCoffee);
    el('btn-logout').addEventListener('click', logout);
    el('btn-book').addEventListener('click', book);
    el('btn-add-device').addEventListener('click', addDevice);
    show('auth');
    refresh();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', ready);
  } else {
    ready();
  }
})();
