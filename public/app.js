"use strict";
/* Coffee Time – TypeScript, compiled to a plain classic script (no build step
   needed on the server; the compiled public/app.js is committed). */
(() => {
    'use strict';
    class ApiError extends Error {
        status;
        code;
        constructor(status, code) {
            super(code);
            this.status = status;
            this.code = code;
        }
    }
    const state = {
        me: null,
        users: [],
        pendingBook: false,
        adminKey: null,
        setupPublicKey: null,
        passwordMinLength: 12
    };
    function byId(id) {
        return document.getElementById(id);
    }
    // Kept as `el` to match the original naming throughout this file.
    const el = byId;
    function money(cents) {
        const value = typeof cents === 'number' && isFinite(cents) ? cents : 0;
        const sign = value < 0 ? '-' : '';
        return sign + (Math.abs(value) / 100).toFixed(2) + ' €';
    }
    function text(node, value) {
        if (node) {
            node.textContent = value;
        }
    }
    /* ------------------------------------------------------ base64url ------ */
    function toBase64Url(buffer) {
        const bytes = new Uint8Array(buffer);
        let binary = '';
        for (let i = 0; i < bytes.length; i++) {
            binary += String.fromCharCode(bytes[i]);
        }
        return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }
    function fromBase64Url(value) {
        let normalized = String(value).replace(/-/g, '+').replace(/_/g, '/');
        while (normalized.length % 4 !== 0) {
            normalized += '=';
        }
        const binary = atob(normalized);
        const bytes = new Uint8Array(binary.length);
        for (let i = 0; i < binary.length; i++) {
            bytes[i] = binary.charCodeAt(i);
        }
        return bytes;
    }
    /* ------------------------------------------------------------ API ----- */
    async function api(path, body) {
        const options = {
            method: body === undefined ? 'GET' : 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json' }
        };
        if (body !== undefined) {
            options.headers = { ...options.headers, 'Content-Type': 'application/json' };
            options.body = JSON.stringify(body === null ? {} : body);
        }
        const response = await fetch(path, options);
        const raw = await response.text();
        let data = {};
        if (raw) {
            try {
                data = JSON.parse(raw);
            }
            catch (e) {
                data = {};
            }
        }
        if (!response.ok) {
            const code = data && data.error ? data.error : 'http_' + response.status;
            throw new ApiError(response.status, code);
        }
        return data;
    }
    /* ---------------------------------------------------- Offline queue --- */
    /*
     * Coffee kitchens have bad wifi: a booking made while offline must not be
     * lost. Every booking attempt carries a client-generated id; if the
     * network request fails outright (not an HTTP error, a fetch rejection),
     * the id is queued in localStorage and retried later with that SAME id,
     * so the server-side idempotency check (see Users::addCoffee) collapses
     * any retries into the single original booking.
     *
     * Each entry also records the account it belongs to. The kitchen tablet
     * this app is built for is shared, so a queue that survived a sign-out
     * would otherwise let one person's offline coffee land on the next
     * person's tab.
     */
    const QUEUE_KEY = 'coffeeQueue';
    const QUEUE_MAX = 50;
    function newEventId() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }
        return randomHexId();
    }
    function randomHexId() {
        const bytes = new Uint8Array(16);
        if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
            window.crypto.getRandomValues(bytes);
        }
        else {
            for (let i = 0; i < bytes.length; i++) {
                bytes[i] = Math.floor(Math.random() * 256);
            }
        }
        let hex = '';
        for (let j = 0; j < bytes.length; j++) {
            const piece = bytes[j].toString(16);
            hex += piece.length === 1 ? '0' + piece : piece;
        }
        return hex;
    }
    /* A network-level failure (fetch rejected) never sets .status; an HTTP
       error response (401, 4xx, 5xx) always does, via api() above. */
    function isNetworkError(error) {
        return !!error && !(error instanceof ApiError);
    }
    function isQueueEntry(value) {
        return !!value && typeof value.id === 'string' && value.id !== '';
    }
    /* Storage may be unavailable (private browsing, cleared site data, quota) –
       every read and write is wrapped so the app still works, just without a
       persistent queue in that case. */
    function loadQueue() {
        let queue = [];
        try {
            const raw = window.localStorage.getItem(QUEUE_KEY);
            if (raw) {
                const parsed = JSON.parse(raw);
                if (Object.prototype.toString.call(parsed) === '[object Array]') {
                    for (const item of parsed) {
                        if (isQueueEntry(item)) {
                            queue.push({
                                id: item.id,
                                at: typeof item.at === 'number' ? item.at : Date.now(),
                                uid: typeof item.uid === 'string' && item.uid !== '' ? item.uid : undefined
                            });
                        }
                    }
                }
            }
        }
        catch (e) {
            queue = [];
        }
        return queue;
    }
    function saveQueue(queue) {
        try {
            window.localStorage.setItem(QUEUE_KEY, JSON.stringify(queue));
        }
        catch (e) {
            /* Cannot persist (storage unavailable or full) – the in-memory queue
               used for this call still gets its turn; it just will not survive
               a reload. */
        }
    }
    function updateQueueHint(queue) {
        const hint = el('queue-hint');
        if (!hint) {
            return;
        }
        const list = queue || loadQueue();
        if (list.length === 0) {
            hint.hidden = true;
            text(hint, '');
            return;
        }
        hint.hidden = false;
        text(hint, list.length + (list.length === 1 ? ' booking' : ' bookings') +
            ' waiting for connection — they sync automatically.');
    }
    /* Returns false (and queues nothing) once QUEUE_MAX is reached – the
       caller shows an error instead of silently dropping the tap. */
    function enqueueBooking(id) {
        const queue = loadQueue();
        if (queue.length >= QUEUE_MAX) {
            return false;
        }
        queue.push({ id: id, at: Date.now(), uid: state.me ? state.me.id : undefined });
        saveQueue(queue);
        updateQueueHint(queue);
        return true;
    }
    /* Drops the queue outright. Called on sign-out so nothing can be replayed
       under the next account on a shared device. */
    function clearQueue() {
        try {
            window.localStorage.removeItem(QUEUE_KEY);
        }
        catch (e) {
            /* Storage unavailable -- there is nothing persisted to clear. */
        }
        updateQueueHint([]);
    }
    /*
     * Sends queued bookings one at a time, in order (sequential awaits, not
     * parallel requests). A network failure or a 401 stops the flush and
     * keeps the remainder for the next trigger; any other HTTP error (e.g.
     * invalid_event) can never succeed, so that entry is dropped and the
     * flush continues. Never rejects – callers can always await it.
     */
    async function flushQueue() {
        const queue = loadQueue();
        let changed = false;
        const currentUid = state.me ? state.me.id : null;
        while (queue.length > 0) {
            const entry = queue[0];
            // A booking stamped with a different account can never be sent
            // correctly -- the server would charge it to whoever is signed in now.
            // Drop it rather than misattribute a coffee.
            if (entry.uid !== undefined && currentUid !== null && entry.uid !== currentUid) {
                queue.shift();
                saveQueue(queue);
                updateQueueHint(queue);
                changed = true;
                continue;
            }
            try {
                await api('/api/coffee', { eventId: entry.id });
                queue.shift();
                saveQueue(queue);
                updateQueueHint(queue);
                changed = true;
            }
            catch (error) {
                if (error instanceof ApiError && error.status === 401) {
                    // No session – the user must sign in again before this can sync.
                    break;
                }
                if (isNetworkError(error)) {
                    // Still offline – stop here, try again on the next trigger.
                    break;
                }
                // Any other rejection (e.g. invalid_event) will never succeed.
                queue.shift();
                saveQueue(queue);
                updateQueueHint(queue);
                changed = true;
                continue;
            }
        }
        if (changed) {
            await refresh();
        }
    }
    /* ------------------------------------------------------- Installation -- */
    /*
     * Whether the app runs from the home screen rather than in a browser tab.
     * iOS answers `navigator.standalone`; everything else has the display-mode
     * media query.
     */
    function isStandalone() {
        const legacy = navigator.standalone;
        if (legacy === true) {
            return true;
        }
        try {
            return window.matchMedia('(display-mode: standalone)').matches;
        }
        catch (e) {
            return false;
        }
    }
    /* iPadOS reports itself as a Mac, but a Mac has no touch screen. */
    function isIos() {
        const ua = navigator.userAgent;
        return /iPad|iPhone|iPod/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1);
    }
    const INSTALL_DISMISSED_KEY = 'installDismissed';
    /* Chromium fires beforeinstallprompt; the deferred event is the only way
       to open the install dialog later, from a real user gesture. */
    let installPrompt = null;
    function installDismissed() {
        try {
            return window.localStorage.getItem(INSTALL_DISMISSED_KEY) === '1';
        }
        catch (e) {
            return false;
        }
    }
    function rememberInstallDismissed(dismissed) {
        try {
            if (dismissed) {
                window.localStorage.setItem(INSTALL_DISMISSED_KEY, '1');
            }
            else {
                window.localStorage.removeItem(INSTALL_DISMISSED_KEY);
            }
        }
        catch (e) {
            /* Storage unavailable – the card then reappears on the next start. */
        }
    }
    /*
     * The card explains what installing buys the user and how to do it. On
     * Chromium that is one button; iOS has no install API at all, so the only
     * thing that works there is describing the Share-sheet steps.
     */
    function updateInstallUi() {
        const card = el('install-card');
        const steps = el('install-steps');
        const button = el('btn-install');
        if (!card || !steps || !button) {
            return;
        }
        const hide = () => {
            card.hidden = true;
            steps.hidden = true;
            button.hidden = true;
        };
        if (isStandalone() || installDismissed()) {
            hide();
            return;
        }
        if (installPrompt !== null) {
            card.hidden = false;
            steps.hidden = true;
            button.hidden = false;
            text(el('install-text'), 'Install Coffee Time to book from your home screen, get reminders and use it offline.');
            return;
        }
        if (isIos()) {
            card.hidden = false;
            steps.hidden = false;
            button.hidden = true;
            text(el('install-text'), 'Reminders and the full-screen app need Coffee Time on your home screen. On iPhone and iPad that takes three taps:');
            return;
        }
        hide();
    }
    function showInstallHelp() {
        rememberInstallDismissed(false);
        updateInstallUi();
        const card = el('install-card');
        if (card && !card.hidden && typeof card.scrollIntoView === 'function') {
            card.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }
    async function runInstallPrompt() {
        const prompt = installPrompt;
        if (prompt === null) {
            return;
        }
        // The event can be used exactly once, whatever the user chooses.
        installPrompt = null;
        try {
            await prompt.prompt();
            await prompt.userChoice;
        }
        catch (e) {
            /* Dialog refused by the browser – the card falls back to hidden. */
        }
        updateInstallUi();
    }
    function initInstall() {
        window.addEventListener('beforeinstallprompt', (event) => {
            // Keeping the default would show the browser's own mini-infobar on top
            // of our card.
            event.preventDefault();
            installPrompt = event;
            updateInstallUi();
        });
        window.addEventListener('appinstalled', () => {
            installPrompt = null;
            rememberInstallDismissed(false);
            updateInstallUi();
            updateReminderUi();
        });
        updateInstallUi();
    }
    /* ---------------------------------------------------------- Reminders -- */
    /*
     * Local month-end and admin payment reminders. The actual check-and-show
     * logic lives in the service worker (see sw.ts); the page only manages
     * the notification permission and pokes the worker. Where the browser
     * supports periodic background sync (installed PWA on Chromium),
     * reminders also fire while the app is closed; everywhere else they
     * appear on the next app start.
     */
    function notificationsSupported() {
        return 'Notification' in window && 'serviceWorker' in navigator;
    }
    /* Resolves to true when background checks are registered on this device. */
    async function registerReminderSync() {
        try {
            const registration = await navigator.serviceWorker.ready;
            const periodicSync = registration.periodicSync;
            if (!periodicSync || typeof periodicSync.register !== 'function') {
                return false;
            }
            await periodicSync.register('reminders', { minInterval: 6 * 60 * 60 * 1000 });
            return true;
        }
        catch (e) {
            // Not installed as an app, permission missing, or unsupported – the
            // on-open check below still covers these devices.
            return false;
        }
    }
    function requestReminderCheck() {
        if (!notificationsSupported() || Notification.permission !== 'granted') {
            return;
        }
        navigator.serviceWorker.ready
            .then((registration) => {
            if (registration.active) {
                registration.active.postMessage({ type: 'check-reminders' });
            }
        })
            .catch(() => { });
    }
    function updateReminderUi(backgroundChecks) {
        const status = el('notify-status');
        const button = el('btn-notify-enable');
        const help = el('btn-notify-install');
        if (!status || !button) {
            return;
        }
        if (help) {
            help.hidden = true;
        }
        if (!notificationsSupported()) {
            button.hidden = true;
            // iOS exposes no Notification API at all in a browser tab: the very
            // same device supports reminders once the app sits on the home screen.
            // Saying "not supported" there would be plain wrong, so point at the
            // one step that actually fixes it.
            if (isIos() && !isStandalone()) {
                if (help) {
                    help.hidden = false;
                }
                text(status, 'Reminders work once Coffee Time is on your home screen — iOS only allows them for installed apps.');
                return;
            }
            text(status, 'This browser does not support notifications.');
            return;
        }
        const permission = Notification.permission;
        if (permission === 'granted') {
            button.hidden = true;
            text(status, backgroundChecks === true
                ? 'Reminders are on — this device also checks in the background.'
                : 'Reminders are on — they appear at the latest when the app is opened.');
        }
        else if (permission === 'denied') {
            button.hidden = true;
            // Installed on iOS the switch lives in the system settings, not in a
            // browser -- so name neither of them specifically.
            text(status, 'Notifications are blocked — allow them for Coffee Time in your browser or device settings.');
        }
        else {
            button.hidden = false;
            text(status, 'Get a notification at the end of the month while your tab is still open.');
        }
    }
    async function enableReminders() {
        const button = el('btn-notify-enable');
        if (!notificationsSupported()) {
            updateReminderUi();
            return;
        }
        busy(button, true);
        try {
            const permission = await Notification.requestPermission();
            let backgroundChecks = false;
            if (permission === 'granted') {
                backgroundChecks = await registerReminderSync();
                requestReminderCheck();
            }
            updateReminderUi(backgroundChecks);
        }
        catch (e) {
            updateReminderUi();
        }
        busy(button, false);
    }
    function initReminders() {
        updateReminderUi();
        if (notificationsSupported() && Notification.permission === 'granted') {
            // Re-register on every start: the registration is idempotent and a
            // reinstalled app or cleared site data would otherwise lose it.
            registerReminderSync()
                .then(updateReminderUi)
                .catch(() => { });
        }
    }
    /* ------------------------------------------------------------- Views -- */
    function show(view) {
        el('view-setup').hidden = view !== 'setup';
        el('view-auth').hidden = view !== 'auth';
        el('view-app').hidden = view !== 'app';
        el('view-admin').hidden = !(view === 'app' && state.me !== null && state.me.admin === true);
    }
    function renderMe(me) {
        // addCoffee()/undoCoffee() rebuild `me` from a smaller response and do
        // not know the current passkey count – fall back to the previous value
        // instead of flashing the device count to 0 in that case.
        const previousCredentials = state.me ? state.me.credentials : undefined;
        state.me = me;
        text(el('counter'), String(me.coffees));
        text(el('balance'), money(me.balanceCents));
        text(el('price'), money(me.priceCents));
        const streakNode = el('streak');
        if (streakNode) {
            const streak = typeof me.streakDays === 'number' ? me.streakDays : 0;
            streakNode.hidden = streak < 2;
            text(streakNode, '🔥 ' + streak + ' days in a row');
        }
        const credentials = typeof me.credentials === 'number' ? me.credentials : previousCredentials;
        if (typeof credentials === 'number') {
            state.me.credentials = credentials;
            text(el('device-count'), credentials + (credentials === 1 ? ' passkey' : ' passkeys'));
        }
        updateBadge(me.balanceCents);
    }
    /* App icon badge: outstanding balance, rounded to whole euros. Purely cosmetic. */
    function updateBadge(balanceCents) {
        if (!('setAppBadge' in navigator)) {
            return;
        }
        const amount = Math.round((typeof balanceCents === 'number' ? balanceCents : 0) / 100);
        try {
            if (amount > 0) {
                navigator.setAppBadge(amount).catch(() => { });
            }
            else if ('clearAppBadge' in navigator) {
                navigator.clearAppBadge().catch(() => { });
            }
        }
        catch (e) {
            /* The badging API is a nice-to-have, not a requirement. */
        }
    }
    function renderHistory(history) {
        text(el('today'), String(history && typeof history.today === 'number' ? history.today : 0));
        const container = el('history-chart');
        if (!container) {
            return;
        }
        container.textContent = '';
        const days = (history && history.days ? history.days : []).slice(-14);
        let max = 0;
        days.forEach((day) => {
            if (typeof day.coffees === 'number' && day.coffees > max) {
                max = day.coffees;
            }
        });
        days.forEach((day, index) => {
            const isToday = index === days.length - 1;
            const coffees = typeof day.coffees === 'number' ? day.coffees : 0;
            const pct = max > 0 ? Math.round((coffees / max) * 100) : 0;
            const label = day.date + ': ' + coffees + (coffees === 1 ? ' coffee' : ' coffees');
            const date = new Date(day.date + 'T00:00:00Z');
            const col = document.createElement('div');
            col.className = 'chart-col' + (isToday ? ' chart-col-today' : '');
            col.setAttribute('data-testid', 'history-day');
            col.setAttribute('role', 'img');
            col.setAttribute('aria-label', label);
            col.title = label;
            const track = document.createElement('div');
            track.className = 'chart-track';
            track.setAttribute('aria-hidden', 'true');
            const bar = document.createElement('div');
            bar.className = 'chart-bar' + (isToday ? ' bar-today' : '');
            bar.style.height = pct + '%';
            track.appendChild(bar);
            const dayLabel = document.createElement('span');
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
        const list = el('distribution');
        if (!list) {
            return;
        }
        list.textContent = '';
        const mine = state.me ? state.me.coffees : null;
        const myRank = stats.rank;
        let marked = false;
        (stats.distribution || []).forEach((entry) => {
            const item = document.createElement('li');
            const left = document.createElement('span');
            left.textContent = 'Rank ' + entry.rank;
            const right = document.createElement('span');
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
        const totalsNode = el('admin-totals');
        if (!totalsNode) {
            return;
        }
        let coffees = 0;
        let outstanding = 0;
        let credit = 0;
        users.forEach((user) => {
            coffees += typeof user.coffees === 'number' ? user.coffees : 0;
            const balance = typeof user.balanceCents === 'number' ? user.balanceCents : 0;
            // Netting the two would let an overpaid account mask somebody else's
            // real debt, so they are counted -- and shown -- separately.
            if (balance > 0) {
                outstanding += balance;
            }
            else {
                credit -= balance;
            }
        });
        text(totalsNode, users.length + (users.length === 1 ? ' account' : ' accounts') +
            ' · ' + coffees + (coffees === 1 ? ' coffee' : ' coffees') +
            ' · ' + money(outstanding) + ' outstanding' +
            (credit > 0 ? ' · ' + money(credit) + ' in credit' : ''));
    }
    function renderAdminSettings(settings) {
        const priceInput = el('admin-price-input');
        const inviteInput = el('admin-invite-input');
        if (priceInput && settings && typeof settings.priceCents === 'number') {
            priceInput.value = (settings.priceCents / 100).toFixed(2);
        }
        if (inviteInput && settings && typeof settings.invite === 'string') {
            inviteInput.value = settings.invite;
        }
        if (settings && typeof settings.passwordMinLength === 'number' && settings.passwordMinLength > 0) {
            state.passwordMinLength = settings.passwordMinLength;
        }
        renderAdminPasswordState(settings ? settings.passwordSet === true : false, settings ? settings.passwordSetAt : 0);
    }
    /* The password is never readable back from the server – only whether one
       exists, and since when. */
    function renderAdminPasswordState(isSet, setAt) {
        const stateNode = el('admin-password-state');
        const removeButton = el('btn-admin-password-remove');
        const since = typeof setAt === 'number' && setAt > 0
            ? ' (set ' + new Date(setAt * 1000).toLocaleDateString() + ')'
            : '';
        text(stateNode, isSet
            ? 'A password is set' + since + '. Saving a new one replaces it.'
            : 'No password set. Passkey sign-in only.');
        if (removeButton) {
            removeButton.hidden = !isSet;
        }
    }
    function renderAdmin(users) {
        state.users = users || [];
        renderAdminTotals(state.users);
        const container = el('admin-users');
        if (!container) {
            return;
        }
        container.textContent = '';
        if (state.users.length === 0) {
            const empty = document.createElement('p');
            empty.className = 'hint';
            empty.textContent = 'No accounts yet.';
            container.appendChild(empty);
            return;
        }
        state.users.forEach((user) => {
            const row = document.createElement('div');
            row.className = 'row';
            row.setAttribute('data-testid', 'admin-row');
            row.setAttribute('data-user-id', user.id);
            const head = document.createElement('span');
            head.className = 'row-head';
            const left = document.createElement('span');
            left.textContent = 'Account ' + user.id + ' · ' + user.coffees + (user.coffees === 1 ? ' coffee' : ' coffees');
            const right = document.createElement('span');
            right.className = 'row-amounts';
            const balanceLine = document.createElement('strong');
            balanceLine.textContent = money(user.balanceCents);
            const paidLine = document.createElement('span');
            paidLine.className = 'row-paid';
            paidLine.textContent = 'paid ' + money(user.paidCents);
            right.appendChild(balanceLine);
            right.appendChild(paidLine);
            head.appendChild(left);
            head.appendChild(right);
            const cipher = document.createElement('span');
            cipher.className = 'row-cipher';
            cipher.textContent = user.decryptedName || user.nameEncrypted || '(no ciphertext)';
            const form = document.createElement('div');
            form.className = 'row-payment';
            const input = document.createElement('input');
            input.type = 'number';
            input.step = '0.01';
            input.min = '0.01';
            input.setAttribute('inputmode', 'decimal');
            input.placeholder = '€';
            input.className = 'row-payment-input';
            input.setAttribute('data-testid', 'admin-payment-input');
            input.setAttribute('aria-label', 'Payment amount for account ' + user.id);
            const payButton = document.createElement('button');
            payButton.type = 'button';
            payButton.className = 'btn btn-quiet row-payment-btn';
            payButton.textContent = 'Record payment';
            payButton.setAttribute('data-testid', 'admin-payment-btn');
            payButton.addEventListener('click', () => {
                recordPayment(user, input, payButton);
            });
            const recoveryButton = document.createElement('button');
            recoveryButton.type = 'button';
            recoveryButton.className = 'btn btn-quiet row-payment-btn';
            recoveryButton.textContent = 'Recovery code';
            recoveryButton.setAttribute('data-testid', 'admin-recovery-btn');
            const recoveryCode = document.createElement('span');
            recoveryCode.className = 'hint row-recovery';
            recoveryCode.setAttribute('data-testid', 'admin-recovery-code');
            recoveryCode.hidden = true;
            recoveryButton.addEventListener('click', () => {
                requestRecoveryCode(user, recoveryButton, recoveryCode);
            });
            const remindButton = document.createElement('button');
            remindButton.type = 'button';
            remindButton.className = 'btn btn-quiet row-payment-btn';
            remindButton.textContent = 'Remind';
            remindButton.setAttribute('data-testid', 'admin-remind-btn');
            const remindStatus = document.createElement('span');
            remindStatus.className = 'hint row-recovery';
            remindStatus.setAttribute('data-testid', 'admin-remind-status');
            remindStatus.hidden = true;
            remindButton.addEventListener('click', () => {
                sendReminder(user, remindButton, remindStatus);
            });
            form.appendChild(input);
            form.appendChild(payButton);
            form.appendChild(recoveryButton);
            form.appendChild(remindButton);
            row.appendChild(head);
            row.appendChild(cipher);
            row.appendChild(form);
            row.appendChild(recoveryCode);
            row.appendChild(remindStatus);
            container.appendChild(row);
        });
    }
    async function requestRecoveryCode(user, button, node) {
        busy(button, true);
        try {
            const data = await api('/api/admin/link-code', { userId: user.id });
            text(node, 'Code ' + data.code + ' — valid 60 min');
            node.hidden = false;
        }
        catch (error) {
            text(node, error instanceof ApiError ? error.code : 'unknown_error');
            node.hidden = false;
        }
        busy(button, false);
    }
    async function sendReminder(user, button, node) {
        busy(button, true);
        try {
            await api('/api/admin/remind', { userId: user.id });
            text(node, 'Reminder queued — it appears on their device.');
            node.hidden = false;
        }
        catch (error) {
            text(node, error instanceof ApiError ? error.code : 'unknown_error');
            node.hidden = false;
        }
        busy(button, false);
    }
    async function recordPayment(user, input, button) {
        const statusNode = el('admin-status');
        text(statusNode, '');
        const value = parseFloat(input.value);
        if (!isFinite(value) || value <= 0 || value > 10000) {
            text(statusNode, 'invalid_amount');
            return;
        }
        const amountCents = Math.round(value * 100);
        busy(button, true);
        try {
            const data = await api('/api/admin/payment', { userId: user.id, amountCents: amountCents });
            const updated = data.user;
            if (user.decryptedName) {
                updated.decryptedName = user.decryptedName;
            }
            for (let i = 0; i < state.users.length; i++) {
                if (state.users[i].id === user.id) {
                    state.users[i] = updated;
                    break;
                }
            }
            renderAdmin(state.users);
        }
        catch (error) {
            fail(statusNode, error);
        }
        busy(button, false);
    }
    async function saveAdminSettings() {
        const button = el('btn-admin-settings');
        const statusNode = el('admin-settings-status');
        text(statusNode, '');
        const priceValue = parseFloat(el('admin-price-input').value);
        if (!isFinite(priceValue) || priceValue <= 0 || priceValue > 1000) {
            text(statusNode, 'invalid_settings');
            return;
        }
        const priceCents = Math.round(priceValue * 100);
        const inviteRaw = el('admin-invite-input').value;
        const invite = typeof inviteRaw === 'string' ? inviteRaw.trim() : '';
        if (invite.length < 4 || invite.length > 64) {
            text(statusNode, 'invalid_settings');
            return;
        }
        busy(button, true);
        try {
            await api('/api/admin/settings/update', { priceCents: priceCents, invite: invite });
            await refresh();
            text(statusNode, 'Saved. New bookings use the new price; existing tabs are unchanged.');
        }
        catch (error) {
            fail(statusNode, error);
        }
        busy(button, false);
    }
    function csvField(value) {
        let str = value === undefined || value === null ? '' : String(value);
        if (/[",\n]/.test(str)) {
            str = '"' + str.replace(/"/g, '""') + '"';
        }
        return str;
    }
    function exportAdminCsv() {
        const rows = [['id', 'name', 'coffees', 'paidEuros', 'balanceEuros']];
        state.users.forEach((user) => {
            rows.push([
                user.id,
                user.decryptedName || '',
                typeof user.coffees === 'number' ? user.coffees : 0,
                ((typeof user.paidCents === 'number' ? user.paidCents : 0) / 100).toFixed(2),
                ((typeof user.balanceCents === 'number' ? user.balanceCents : 0) / 100).toFixed(2)
            ]);
        });
        const csv = rows.map((row) => row.map(csvField).join(',')).join('\r\n');
        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = 'coffee-time.csv';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    }
    function downloadTextFile(content, filename) {
        const blob = new Blob([content], { type: 'application/x-pem-file;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    }
    /* DER (ArrayBuffer) -> PEM text, wrapped at 64 base64 characters per line. */
    function toPem(buffer, label) {
        const bytes = new Uint8Array(buffer);
        let binary = '';
        for (let i = 0; i < bytes.length; i++) {
            binary += String.fromCharCode(bytes[i]);
        }
        const base64 = btoa(binary);
        const lines = [];
        for (let j = 0; j < base64.length; j += 64) {
            lines.push(base64.slice(j, j + 64));
        }
        return '-----BEGIN ' + label + '-----\n' + lines.join('\n') + '\n-----END ' + label + '-----\n';
    }
    function pemBytes(pem) {
        const normalized = pem.replace(/-----BEGIN PRIVATE KEY-----|-----END PRIVATE KEY-----|\s/g, '');
        if (!normalized)
            throw new Error('invalid_key');
        const binary = atob(normalized);
        const bytes = new Uint8Array(binary.length);
        for (let i = 0; i < binary.length; i++)
            bytes[i] = binary.charCodeAt(i);
        return bytes;
    }
    async function decryptAdminName(user) {
        const prefix = 'rsa-oaep-sha1:';
        if (!state.adminKey || !user.nameEncrypted || user.nameEncrypted.indexOf(prefix) !== 0)
            return;
        const raw = atob(user.nameEncrypted.slice(prefix.length));
        const bytes = new Uint8Array(raw.length);
        for (let i = 0; i < raw.length; i++)
            bytes[i] = raw.charCodeAt(i);
        const plain = await crypto.subtle.decrypt({ name: 'RSA-OAEP' }, state.adminKey, bytes);
        const data = JSON.parse(new TextDecoder().decode(plain));
        user.decryptedName = (String(data.firstName || '') + ' ' + String(data.lastName || '')).trim();
    }
    async function selectPrivateKey(event) {
        const target = event.target;
        const file = target.files && target.files[0];
        const status = el('admin-key-status');
        state.adminKey = null;
        state.users.forEach((user) => { delete user.decryptedName; });
        if (!file || !window.crypto || !crypto.subtle) {
            text(status, 'Web Crypto is unavailable or no file was selected.');
            renderAdmin(state.users);
            return;
        }
        try {
            const pem = await file.text();
            const key = await crypto.subtle.importKey('pkcs8', pemBytes(pem), { name: 'RSA-OAEP', hash: 'SHA-1' }, false, ['decrypt']);
            state.adminKey = key;
            // allSettled, not all: one unreadable ciphertext (a legacy or corrupt
            // row) must not hide every other name behind a misleading "wrong key".
            const results = await Promise.allSettled(state.users.map(decryptAdminName));
            const failed = results.filter((entry) => entry.status === 'rejected').length;
            if (failed === results.length && results.length > 0) {
                // Nothing decrypted at all: that really is the wrong key.
                state.adminKey = null;
                text(status, 'Could not decrypt names. Check that this is the matching PKCS#8 key.');
            }
            else if (failed > 0) {
                text(status, 'Names decrypted locally. ' + failed + ' entr' + (failed === 1 ? 'y' : 'ies') + ' could not be read.');
            }
            else {
                text(status, 'Names decrypted locally. The key has not left this browser.');
            }
            renderAdmin(state.users);
        }
        catch (e) {
            state.adminKey = null;
            text(status, 'Could not decrypt names. Check that this is the matching PKCS#8 key.');
            renderAdmin(state.users);
        }
    }
    /* --------------------------------------------------------- Loading ---- */
    async function refresh() {
        try {
            const me = await api('/api/me');
            renderMe(me);
            show('app');
            consumePendingBook();
            requestReminderCheck();
            const jobs = [
                api('/api/stats').then(renderStats).catch(() => { }),
                api('/api/history').then(renderHistory).catch(() => { })
            ];
            if (me.admin === true) {
                jobs.push(api('/api/admin/users')
                    .then((data) => {
                    renderAdmin(data.users);
                })
                    .catch(() => { }));
                jobs.push(api('/api/admin/settings')
                    .then(renderAdminSettings)
                    .catch(() => { }));
            }
            await Promise.all(jobs);
        }
        catch (e) {
            state.me = null;
            show('auth');
        }
    }
    /* ------------------------------------------------------- WebAuthn ----- */
    function creationOptions(options) {
        const selection = options.authenticatorSelection || {};
        return {
            rp: { id: options.rp.id, name: options.rp.name },
            user: {
                id: fromBase64Url(options.user.id),
                name: options.user.name,
                displayName: options.user.displayName
            },
            challenge: fromBase64Url(options.challenge),
            pubKeyCredParams: options.pubKeyCredParams || [],
            // Only pass through the fields the browser recognizes.
            authenticatorSelection: {
                residentKey: selection.residentKey,
                requireResidentKey: selection.requireResidentKey === true,
                userVerification: selection.userVerification
            },
            attestation: options.attestation,
            timeout: options.timeout,
            excludeCredentials: (options.excludeCredentials || []).map((item) => ({
                id: fromBase64Url(item.id), type: item.type, transports: item.transports
            }))
        };
    }
    function requestOptions(options) {
        return {
            challenge: fromBase64Url(options.challenge),
            rpId: options.rpId,
            userVerification: options.userVerification,
            timeout: options.timeout,
            allowCredentials: (options.allowCredentials || []).map((item) => ({
                id: fromBase64Url(item.id), type: item.type, transports: item.transports
            }))
        };
    }
    function serializeAttestation(credential) {
        const response = credential.response;
        let transports = [];
        if (typeof response.getTransports === 'function') {
            try {
                transports = response.getTransports() || [];
            }
            catch (e) {
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
        const response = credential.response;
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
    /* -------------------------------------------------------- Actions ----- */
    function busy(button, isBusy) {
        if (button) {
            button.disabled = isBusy;
        }
    }
    function fail(node, error) {
        text(node, error instanceof ApiError ? error.code : 'unknown_error');
    }
    async function register() {
        const button = el('btn-register');
        const errorNode = el('auth-error');
        text(errorNode, '');
        if (!window.PublicKeyCredential) {
            text(errorNode, 'passkeys_unavailable');
            return;
        }
        const payload = {
            firstName: el('firstname-input').value,
            lastName: el('lastname-input').value,
            invite: el('invite-input').value
        };
        busy(button, true);
        try {
            const options = await api('/api/register/options', payload);
            const credential = await navigator.credentials.create({ publicKey: creationOptions(options) });
            if (!credential) {
                throw new Error('cancelled');
            }
            const body = {
                firstName: payload.firstName,
                lastName: payload.lastName,
                invite: payload.invite,
                credential: serializeAttestation(credential)
            };
            await api('/api/register/verify', body);
            el('invite-input').value = '';
            await refresh();
        }
        catch (error) {
            fail(errorNode, error);
        }
        busy(button, false);
    }
    async function login() {
        const button = el('btn-login');
        const errorNode = el('auth-error');
        text(errorNode, '');
        if (!window.PublicKeyCredential) {
            text(errorNode, 'passkeys_unavailable');
            return;
        }
        busy(button, true);
        try {
            const options = await api('/api/login/options', {});
            const credential = await navigator.credentials.get({ publicKey: requestOptions(options) });
            if (!credential) {
                throw new Error('cancelled');
            }
            await api('/api/login/verify', { credential: serializeAssertion(credential) });
            await refresh();
        }
        catch (error) {
            fail(errorNode, error);
        }
        busy(button, false);
    }
    /*
     * Password sign-in. Administrators only, and only for the case WebAuthn
     * cannot cover: a managed workstation whose policy blocks authenticators.
     * The passkey button above stays the primary path for everyone else.
     */
    async function loginWithPassword() {
        const button = el('btn-login-password');
        const errorNode = el('auth-error');
        text(errorNode, '');
        const firstName = el('pw-firstname-input').value.trim();
        const lastName = el('pw-lastname-input').value.trim();
        const passwordInput = el('pw-password-input');
        const password = passwordInput.value;
        if (!firstName || !lastName || !password) {
            text(errorNode, 'Enter your name and password.');
            return;
        }
        busy(button, true);
        try {
            await api('/api/login/password', { firstName: firstName, lastName: lastName, password: password });
            // Cleared on success as well as on failure: the field must not keep the
            // password around on a shared kitchen device.
            passwordInput.value = '';
            await refresh();
        }
        catch (error) {
            passwordInput.value = '';
            if (error instanceof ApiError && error.status === 401) {
                // Deliberately one message for every rejection – the server does not
                // distinguish "no such account" from "wrong password" either.
                text(errorNode, 'Wrong name or password.');
            }
            else if (error instanceof ApiError && error.status === 429) {
                text(errorNode, 'Too many attempts. Try again in a few minutes.');
            }
            else {
                fail(errorNode, error);
            }
        }
        busy(button, false);
    }
    async function linkDevice() {
        const button = el('btn-link-device');
        const errorNode = el('auth-error');
        text(errorNode, '');
        if (!window.PublicKeyCredential) {
            text(errorNode, 'passkeys_unavailable');
            return;
        }
        const code = el('link-code-input').value;
        busy(button, true);
        try {
            const options = await api('/api/link/options', { code: code });
            const credential = await navigator.credentials.create({ publicKey: creationOptions(options) });
            if (!credential) {
                throw new Error('cancelled');
            }
            await api('/api/link/verify', { code: code, credential: serializeAttestation(credential) });
            el('link-code-input').value = '';
            await refresh();
        }
        catch (error) {
            fail(errorNode, error);
        }
        busy(button, false);
    }
    async function linkCode() {
        const button = el('btn-link-code');
        const errorNode = el('link-code-error');
        const display = el('link-code-display');
        const hint = el('link-code-hint');
        text(errorNode, '');
        busy(button, true);
        try {
            const data = await api('/api/link/code', {});
            text(display, data.code);
            display.hidden = false;
            hint.hidden = false;
        }
        catch (error) {
            fail(errorNode, error);
        }
        busy(button, false);
    }
    async function generateSetupKey() {
        const button = el('btn-setup-generate');
        const initButton = el('btn-setup-init');
        const status = el('setup-status');
        text(status, '');
        if (!window.crypto || !crypto.subtle || typeof crypto.subtle.generateKey !== 'function') {
            text(status, 'Setup needs a modern browser with Web Crypto support.');
            return;
        }
        busy(button, true);
        try {
            const pair = await crypto.subtle.generateKey({ name: 'RSA-OAEP', modulusLength: 4096, publicExponent: new Uint8Array([1, 0, 1]), hash: 'SHA-1' }, true, ['encrypt', 'decrypt']);
            const [privateRaw, publicRaw] = await Promise.all([
                crypto.subtle.exportKey('pkcs8', pair.privateKey),
                crypto.subtle.exportKey('spki', pair.publicKey)
            ]);
            const privatePem = toPem(privateRaw, 'PRIVATE KEY');
            const publicPem = toPem(publicRaw, 'PUBLIC KEY');
            state.setupPublicKey = publicPem;
            downloadTextFile(privatePem, 'admin-private.pem');
            text(status, 'Key file downloaded. Store it safely — without it, names can never be ' +
                'decrypted. It is never uploaded.');
            if (initButton) {
                initButton.disabled = false;
            }
        }
        catch (e) {
            state.setupPublicKey = null;
            text(status, 'Could not generate the key pair in this browser.');
        }
        busy(button, false);
    }
    async function initSetup() {
        const button = el('btn-setup-init');
        const status = el('setup-status');
        const priceValue = parseFloat(el('setup-price').value);
        if (!isFinite(priceValue) || priceValue <= 0 || priceValue > 1000) {
            text(status, 'invalid_price');
            return;
        }
        const priceCents = Math.round(priceValue * 100);
        const inviteRaw = el('setup-invite').value;
        const invite = typeof inviteRaw === 'string' ? inviteRaw.trim() : '';
        if (invite.length < 4 || invite.length > 64) {
            text(status, 'invalid_invite');
            return;
        }
        if (!state.setupPublicKey) {
            text(status, 'Generate the key first.');
            return;
        }
        busy(button, true);
        try {
            await api('/api/setup/init', { adminPublicKey: state.setupPublicKey, priceCents: priceCents, invite: invite });
            text(status, 'Setup complete — register the first account below; it becomes the administrator.');
            show('auth');
        }
        catch (error) {
            text(status, error instanceof ApiError ? error.code : 'unknown_error');
        }
        busy(button, false);
    }
    async function saveAdminPassword() {
        const button = el('btn-admin-password');
        const statusNode = el('admin-password-status');
        const passwordInput = el('admin-password-input');
        const repeatInput = el('admin-password-repeat');
        text(statusNode, '');
        const password = passwordInput.value;
        const minimum = state.passwordMinLength;
        if (password.length < minimum) {
            text(statusNode, 'Use at least ' + minimum + ' characters.');
            return;
        }
        // The repeat field is not security, it is a typo guard: getting locked out
        // of a password you cannot read back is a bad way to find out.
        if (password !== repeatInput.value) {
            text(statusNode, 'The two entries do not match.');
            return;
        }
        busy(button, true);
        try {
            const data = await api('/api/admin/password', { password: password });
            passwordInput.value = '';
            repeatInput.value = '';
            renderAdminPasswordState(data.passwordSet === true, data.passwordSetAt);
            text(statusNode, 'Password saved. Your passkeys keep working.');
        }
        catch (error) {
            fail(statusNode, error);
        }
        busy(button, false);
    }
    async function removeAdminPassword() {
        const button = el('btn-admin-password-remove');
        const statusNode = el('admin-password-status');
        text(statusNode, '');
        if (!window.confirm('Remove the password? Afterwards this account signs in with passkeys only.')) {
            return;
        }
        busy(button, true);
        try {
            const data = await api('/api/admin/password', { remove: true });
            el('admin-password-input').value = '';
            el('admin-password-repeat').value = '';
            renderAdminPasswordState(data.passwordSet === true, data.passwordSetAt);
            text(statusNode, 'Password removed.');
        }
        catch (error) {
            fail(statusNode, error);
        }
        busy(button, false);
    }
    async function addCoffee() {
        const button = el('btn-add');
        const eventId = newEventId();
        busy(button, true);
        try {
            try {
                const data = await api('/api/coffee', { eventId: eventId });
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
                await refresh();
            }
            catch (error) {
                // Only a rejected /api/coffee call lands here – a failure while
                // rendering the result afterwards does not (see the try/catch
                // split above), so a successful booking is never re-queued.
                if (error instanceof ApiError && error.status === 401) {
                    show('auth');
                    return;
                }
                if (isNetworkError(error)) {
                    if (!enqueueBooking(eventId)) {
                        fail(el('app-error'), new ApiError(0, 'queue_full'));
                    }
                    return;
                }
                fail(el('app-error'), error);
            }
            await flushQueue();
        }
        catch (e) {
            /* flushQueue() never rejects; this only keeps the handler from throwing. */
        }
        finally {
            // The catch branches above return early (queued offline booking, 401)
            // -- the button must come back on those paths too, not only after a
            // completed request, or a second offline tap would be impossible
            // until the next reload.
            busy(button, false);
        }
    }
    async function undoCoffee() {
        const button = el('btn-undo');
        busy(button, true);
        try {
            const data = await api('/api/coffee/undo', {});
            renderMe({
                id: state.me ? state.me.id : '',
                admin: state.me ? state.me.admin : false,
                coffees: data.coffees,
                balanceCents: data.balanceCents,
                priceCents: state.me ? state.me.priceCents : 0
            });
            await refresh();
        }
        catch (error) {
            if (error instanceof ApiError && error.status === 401) {
                show('auth');
            }
            else {
                fail(el('app-error'), error);
            }
        }
        busy(button, false);
    }
    async function logout() {
        let serverEnded = true;
        try {
            await api('/api/logout', {});
        }
        catch (e) {
            // Offline: the cookie is HttpOnly, so only the server can really end
            // the session. Everything local is still cleared, and the user is told
            // the sign-out is not complete yet.
            serverEnded = !isNetworkError(e);
        }
        // Always drop the queue: these bookings belong to the account signing
        // out, and this device may well be handed to someone else next.
        clearQueue();
        state.me = null;
        state.users = [];
        state.adminKey = null;
        show('auth');
        if (!serverEnded) {
            text(el('auth-error'), 'Signed out on this device. You were offline, so the session ends on the server at the next connection.');
        }
        if ('clearAppBadge' in navigator) {
            navigator.clearAppBadge().catch(() => { });
        }
    }
    /* ------------------------------------------------------- Feedback ----- */
    /* Short, satisfied double buzz – deliberately not a single hard jolt. */
    function vibrate(pattern) {
        if (window.navigator && typeof window.navigator.vibrate === 'function') {
            try {
                window.navigator.vibrate(pattern);
            }
            catch (e) {
                /* Some browsers throw outside a user gesture – simply ignore it. */
            }
        }
    }
    function bump(node) {
        if (!node) {
            return;
        }
        node.classList.remove('bump');
        // Force a reflow so the animation restarts on repeated taps.
        void node.offsetWidth;
        node.classList.add('bump');
    }
    /* --------------------------------------------------- Invitation link -- */
    /*
     * "/?invite=CODE" prefills the invite field, so an invitation can be a
     * single link instead of a code someone has to copy by hand. The code is the
     * same shared invite as before -- the link is a convenience, not a second
     * credential, and it is only as secret as wherever it was pasted.
     *
     * The parameter is stripped from the URL right away so it does not linger in
     * the address bar, in history or in a bookmark. Other parameters survive:
     * an invite link is removed here, and a "?book=1" alongside it still has to
     * reach checkPendingBook().
     */
    function checkInviteLink() {
        const params = new URLSearchParams(window.location.search);
        const invite = params.get('invite');
        if (invite === null) {
            return;
        }
        params.delete('invite');
        const rest = params.toString();
        window.history.replaceState(null, '', window.location.pathname + (rest ? '?' + rest : ''));
        const code = invite.trim();
        // Same bounds the server enforces; a link carrying nonsense just opens the
        // app with an empty field instead of prefilling it with nonsense.
        if (code.length < 4 || code.length > 64) {
            return;
        }
        const input = el('invite-input');
        if (input) {
            input.value = code;
        }
        const hint = el('invite-link-hint');
        if (hint) {
            hint.hidden = false;
        }
    }
    /* ------------------------------------------------- Shortcut / NFC tag -- */
    /*
     * "/?book=1" immediately books a coffee. It is shared by the app shortcut
     * and NFC tags, and is removed immediately to prevent duplicate bookings.
     */
    function checkPendingBook() {
        const params = new URLSearchParams(window.location.search);
        if (params.get('book') !== '1') {
            return;
        }
        window.history.replaceState(null, '', window.location.pathname);
        // The session cookie is SameSite=Lax, so a plain top-level link from any
        // site would carry it and book a coffee with a single click. An NFC tag
        // or the installed app's shortcut opens with no referrer, and a link
        // inside the app is same-origin -- only those book without being asked.
        // Anything arriving from another site just opens the app.
        if (!openedWithoutForeignReferrer()) {
            return;
        }
        state.pendingBook = true;
        const hint = el('nfc-hint');
        if (hint) {
            hint.hidden = false;
        }
    }
    function openedWithoutForeignReferrer() {
        const referrer = document.referrer;
        if (!referrer) {
            return true;
        }
        try {
            return new URL(referrer).origin === window.location.origin;
        }
        catch (e) {
            return false;
        }
    }
    function consumePendingBook() {
        if (!state.pendingBook) {
            return;
        }
        state.pendingBook = false;
        addCoffee();
    }
    /* -------------------------------------------------- Pull to refresh --- */
    /*
     * Installed on the home screen there is no browser chrome and therefore no
     * reload button, so the standard gesture has to be provided by the page.
     * Only there: a browser tab already has both a reload button and its own
     * pull-to-refresh, and a second one on top of it would fight the first.
     */
    const PULL_TRIGGER = 72; // px of travel that arms the refresh
    const PULL_MAX = 110;
    const PULL_RESISTANCE = 0.5;
    function initPullToRefresh() {
        const indicator = el('pull-indicator');
        const wrap = document.querySelector('.wrap');
        if (!indicator || !wrap || !isStandalone()) {
            return;
        }
        let startY = 0;
        let distance = 0;
        let tracking = false;
        let refreshing = false;
        function paint(offset, animate) {
            const transition = animate ? 'transform 0.25s ease, opacity 0.25s ease' : '';
            indicator.style.transition = transition;
            indicator.style.transform = 'translateY(' + offset + 'px)';
            indicator.style.opacity = String(Math.min(1, offset / PULL_TRIGGER));
            wrap.style.transition = animate ? 'transform 0.25s ease' : '';
            wrap.style.transform = offset > 0 ? 'translateY(' + offset + 'px)' : '';
        }
        function reset() {
            tracking = false;
            distance = 0;
            paint(0, true);
        }
        /* The setup wizard has no session yet – refresh() would sign the visitor
           out of a flow they are in the middle of. */
        function allowed() {
            const setup = el('view-setup');
            // `hidden` is boolean | "until-found" – any truthy value means hidden.
            return !refreshing && (setup === null || Boolean(setup.hidden));
        }
        async function run() {
            refreshing = true;
            indicator.classList.add('is-busy');
            paint(PULL_TRIGGER, true);
            try {
                await refresh();
                await flushQueue();
            }
            finally {
                refreshing = false;
                indicator.classList.remove('is-busy');
                reset();
            }
        }
        document.addEventListener('touchstart', (event) => {
            // Pinches and two-finger scrolls are not a pull.
            if (event.touches.length !== 1 || window.scrollY > 0 || !allowed()) {
                tracking = false;
                return;
            }
            startY = event.touches[0].clientY;
            distance = 0;
            tracking = true;
        }, { passive: true });
        document.addEventListener('touchmove', (event) => {
            if (!tracking) {
                return;
            }
            const delta = event.touches[0].clientY - startY;
            if (delta <= 0 || window.scrollY > 0) {
                // Turned into an ordinary scroll – hand the gesture back.
                if (distance > 0) {
                    reset();
                }
                tracking = false;
                return;
            }
            distance = Math.min(PULL_MAX, delta * PULL_RESISTANCE);
            if (event.cancelable) {
                event.preventDefault();
            }
            paint(distance, false);
        }, { passive: false });
        function release() {
            if (!tracking) {
                return;
            }
            tracking = false;
            if (distance >= PULL_TRIGGER && allowed()) {
                run();
                return;
            }
            reset();
        }
        document.addEventListener('touchend', release, { passive: true });
        document.addEventListener('touchcancel', release, { passive: true });
    }
    /* ---------------------------------------------------------- Start ----- */
    function registerServiceWorker() {
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/sw.js').catch(() => {
                /* Offline caching is optional; the app works without a service worker. */
            });
        }
    }
    function ready() {
        el('btn-register').addEventListener('click', register);
        el('btn-login').addEventListener('click', login);
        el('btn-login-password').addEventListener('click', loginWithPassword);
        // A login form is expected to submit on Enter; these inputs are not inside
        // a <form>, so the key has to be handled explicitly.
        el('pw-password-input').addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                loginWithPassword();
            }
        });
        el('btn-link-device').addEventListener('click', linkDevice);
        el('btn-link-code').addEventListener('click', linkCode);
        el('btn-setup-generate').addEventListener('click', generateSetupKey);
        el('btn-setup-init').addEventListener('click', initSetup);
        el('btn-add').addEventListener('click', addCoffee);
        el('btn-undo').addEventListener('click', undoCoffee);
        el('btn-logout').addEventListener('click', logout);
        el('btn-notify-enable').addEventListener('click', enableReminders);
        el('btn-notify-install').addEventListener('click', showInstallHelp);
        el('btn-install').addEventListener('click', runInstallPrompt);
        el('btn-install-dismiss').addEventListener('click', () => {
            rememberInstallDismissed(true);
            updateInstallUi();
        });
        el('private-key-input').addEventListener('change', selectPrivateKey);
        el('btn-admin-csv').addEventListener('click', exportAdminCsv);
        el('btn-admin-settings').addEventListener('click', saveAdminSettings);
        el('btn-admin-password').addEventListener('click', saveAdminPassword);
        el('btn-admin-password-remove').addEventListener('click', removeAdminPassword);
        // Before checkPendingBook(): that one rewrites the URL without any query
        // string at all, which would take an "?invite=" alongside it with it.
        checkInviteLink();
        checkPendingBook();
        registerServiceWorker();
        initInstall();
        initReminders();
        initPullToRefresh();
        updateQueueHint();
        window.addEventListener('online', () => {
            flushQueue();
        });
        async function startNormalFlow() {
            show('auth');
            await refresh();
            await flushQueue();
        }
        api('/api/setup/status')
            .then((status) => {
            if (status && status.needsSetup) {
                show('setup');
                return undefined;
            }
            return startNormalFlow();
        })
            .catch(() => {
            return startNormalFlow();
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', ready);
    }
    else {
        ready();
    }
})();
