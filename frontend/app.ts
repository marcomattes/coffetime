/* Coffee Time – TypeScript, compiled to a plain classic script (no build step
   needed on the server; the compiled public/app.js is committed). */
(() => {
  'use strict';

  /* --------------------------------------------------------- Types ------ */

  interface MeResponse {
    id: string;
    admin: boolean;
    coffees: number;
    balanceCents: number;
    priceCents: number;
    streakDays?: number;
    credentials?: number;
  }

  interface DistributionEntry {
    rank: number;
    coffees: number;
  }

  interface StatsResponse {
    total: number;
    users: number;
    rank: number | null;
    distribution: DistributionEntry[];
  }

  interface HistoryDay {
    date: string;
    coffees: number;
  }

  interface HistoryResponse {
    today: number;
    days: HistoryDay[];
  }

  interface AdminUser {
    id: string;
    nameEncrypted: string;
    coffees: number;
    paidCents: number;
    balanceCents: number;
    decryptedName?: string;
  }

  interface AdminUsersResponse {
    users: AdminUser[];
  }

  interface SetupStatus {
    needsSetup: boolean;
    priceCents: number;
  }

  interface AdminSettings {
    priceCents: number;
    invite: string;
  }

  interface LinkCode {
    code: string;
    expiresAt?: string;
  }

  interface CoffeeResponse {
    coffees: number;
    balanceCents: number;
  }

  interface AdminPaymentResponse {
    user: AdminUser;
  }

  interface QueueEntry {
    id: string;
    at: number;
  }

  class ApiError extends Error {
    status: number;
    code: string;

    constructor(status: number, code: string) {
      super(code);
      this.status = status;
      this.code = code;
    }
  }

  interface AppState {
    me: MeResponse | null;
    users: AdminUser[];
    pendingBook: boolean;
    adminKey: CryptoKey | null;
    setupPublicKey: string | null;
  }

  const state: AppState = { me: null, users: [], pendingBook: false, adminKey: null, setupPublicKey: null };

  function byId<T extends HTMLElement>(id: string): T | null {
    return document.getElementById(id) as T | null;
  }

  // Kept as `el` to match the original naming throughout this file.
  const el = byId;

  function money(cents: unknown): string {
    const value = typeof cents === 'number' && isFinite(cents) ? cents : 0;
    const sign = value < 0 ? '-' : '';
    return sign + (Math.abs(value) / 100).toFixed(2) + ' €';
  }

  function text(node: HTMLElement | null, value: string): void {
    if (node) {
      node.textContent = value;
    }
  }

  /* ------------------------------------------------------ base64url ------ */

  function toBase64Url(buffer: ArrayBuffer): string {
    const bytes = new Uint8Array(buffer);
    let binary = '';
    for (let i = 0; i < bytes.length; i++) {
      binary += String.fromCharCode(bytes[i]);
    }
    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }

  function fromBase64Url(value: string): Uint8Array<ArrayBuffer> {
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

  async function api<T>(path: string, body?: unknown): Promise<T> {
    const options: RequestInit = {
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
    let data: any = {};
    if (raw) {
      try {
        data = JSON.parse(raw);
      } catch (e) {
        data = {};
      }
    }
    if (!response.ok) {
      const code = data && data.error ? data.error : 'http_' + response.status;
      throw new ApiError(response.status, code);
    }
    return data as T;
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

  const QUEUE_KEY = 'coffeeQueue';
  const QUEUE_MAX = 50;

  function newEventId(): string {
    if (window.crypto && typeof window.crypto.randomUUID === 'function') {
      return window.crypto.randomUUID();
    }
    return randomHexId();
  }

  function randomHexId(): string {
    const bytes = new Uint8Array(16);
    if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
      window.crypto.getRandomValues(bytes);
    } else {
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
  function isNetworkError(error: unknown): boolean {
    return !!error && !(error instanceof ApiError);
  }

  function isQueueEntry(value: unknown): value is QueueEntry {
    return !!value && typeof (value as any).id === 'string' && (value as any).id !== '';
  }

  /* Storage may be unavailable (private browsing, cleared site data, quota) –
     every read and write is wrapped so the app still works, just without a
     persistent queue in that case. */
  function loadQueue(): QueueEntry[] {
    let queue: QueueEntry[] = [];
    try {
      const raw = window.localStorage.getItem(QUEUE_KEY);
      if (raw) {
        const parsed = JSON.parse(raw);
        if (Object.prototype.toString.call(parsed) === '[object Array]') {
          for (const item of parsed as unknown[]) {
            if (isQueueEntry(item)) {
              queue.push({
                id: item.id,
                at: typeof item.at === 'number' ? item.at : Date.now()
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

  function saveQueue(queue: QueueEntry[]): void {
    try {
      window.localStorage.setItem(QUEUE_KEY, JSON.stringify(queue));
    } catch (e) {
      /* Cannot persist (storage unavailable or full) – the in-memory queue
         used for this call still gets its turn; it just will not survive
         a reload. */
    }
  }

  function updateQueueHint(queue?: QueueEntry[]): void {
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
    text(
      hint,
      list.length + (list.length === 1 ? ' booking' : ' bookings') +
        ' waiting for connection — they sync automatically.'
    );
  }

  /* Returns false (and queues nothing) once QUEUE_MAX is reached – the
     caller shows an error instead of silently dropping the tap. */
  function enqueueBooking(id: string): boolean {
    const queue = loadQueue();
    if (queue.length >= QUEUE_MAX) {
      return false;
    }
    queue.push({ id: id, at: Date.now() });
    saveQueue(queue);
    updateQueueHint(queue);
    return true;
  }

  /*
   * Sends queued bookings one at a time, in order (sequential awaits, not
   * parallel requests). A network failure or a 401 stops the flush and
   * keeps the remainder for the next trigger; any other HTTP error (e.g.
   * invalid_event) can never succeed, so that entry is dropped and the
   * flush continues. Never rejects – callers can always await it.
   */
  async function flushQueue(): Promise<void> {
    const queue = loadQueue();
    let changed = false;
    while (queue.length > 0) {
      const entry = queue[0];
      try {
        await api('/api/coffee', { eventId: entry.id });
        queue.shift();
        saveQueue(queue);
        updateQueueHint(queue);
        changed = true;
      } catch (error) {
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

  /* ------------------------------------------------------- Erinnerungen -- */

  /*
   * Local month-end and admin payment reminders. The actual check-and-show
   * logic lives in the service worker (see sw.ts); the page only manages
   * the notification permission and pokes the worker. Where the browser
   * supports periodic background sync (installed PWA on Chromium),
   * reminders also fire while the app is closed; everywhere else they
   * appear on the next app start.
   */

  function notificationsSupported(): boolean {
    return 'Notification' in window && 'serviceWorker' in navigator;
  }

  /* Resolves to true when background checks are registered on this device. */
  async function registerReminderSync(): Promise<boolean> {
    try {
      const registration = await navigator.serviceWorker.ready;
      const periodicSync = (registration as any).periodicSync;
      if (!periodicSync || typeof periodicSync.register !== 'function') {
        return false;
      }
      await periodicSync.register('reminders', { minInterval: 6 * 60 * 60 * 1000 });
      return true;
    } catch (e) {
      // Not installed as an app, permission missing, or unsupported – the
      // on-open check below still covers these devices.
      return false;
    }
  }

  function requestReminderCheck(): void {
    if (!notificationsSupported() || Notification.permission !== 'granted') {
      return;
    }
    navigator.serviceWorker.ready
      .then((registration) => {
        if (registration.active) {
          registration.active.postMessage({ type: 'check-reminders' });
        }
      })
      .catch(() => { /* no service worker – reminders simply stay off */ });
  }

  function updateReminderUi(backgroundChecks?: boolean): void {
    const status = el('notify-status');
    const button = el<HTMLButtonElement>('btn-notify-enable');
    if (!status || !button) {
      return;
    }
    if (!notificationsSupported()) {
      button.hidden = true;
      text(status, 'This browser does not support notifications.');
      return;
    }
    const permission = Notification.permission;
    if (permission === 'granted') {
      button.hidden = true;
      text(
        status,
        backgroundChecks === true
          ? 'Reminders are on — this device also checks in the background.'
          : 'Reminders are on — they appear at the latest when the app is opened.'
      );
    } else if (permission === 'denied') {
      button.hidden = true;
      text(status, 'Notifications are blocked for this site in the browser settings.');
    } else {
      button.hidden = false;
      text(status, 'Get a notification at the end of the month while your tab is still open.');
    }
  }

  async function enableReminders(): Promise<void> {
    const button = el<HTMLButtonElement>('btn-notify-enable');
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
    } catch (e) {
      updateReminderUi();
    }
    busy(button, false);
  }

  function initReminders(): void {
    updateReminderUi();
    if (notificationsSupported() && Notification.permission === 'granted') {
      // Re-register on every start: the registration is idempotent and a
      // reinstalled app or cleared site data would otherwise lose it.
      registerReminderSync()
        .then(updateReminderUi)
        .catch(() => {});
    }
  }

  /* --------------------------------------------------------- Ansichten -- */

  function show(view: 'setup' | 'auth' | 'app'): void {
    el('view-setup')!.hidden = view !== 'setup';
    el('view-auth')!.hidden = view !== 'auth';
    el('view-app')!.hidden = view !== 'app';
    el('view-admin')!.hidden = !(view === 'app' && state.me !== null && state.me.admin === true);
  }

  function renderMe(me: MeResponse): void {
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

  /* App-Icon-Badge: offener Betrag, aufgerundet auf ganze Euro. Rein kosmetisch. */
  function updateBadge(balanceCents: unknown): void {
    if (!('setAppBadge' in navigator)) {
      return;
    }
    const amount = Math.round((typeof balanceCents === 'number' ? balanceCents : 0) / 100);
    try {
      if (amount > 0) {
        (navigator as any).setAppBadge(amount).catch(() => {});
      } else if ('clearAppBadge' in navigator) {
        (navigator as any).clearAppBadge().catch(() => {});
      }
    } catch (e) {
      /* Badging API ist ein Bonus, kein Muss. */
    }
  }

  function renderHistory(history: HistoryResponse | null | undefined): void {
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

  function renderStats(stats: StatsResponse): void {
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

  function renderAdminTotals(users: AdminUser[]): void {
    const totalsNode = el('admin-totals');
    if (!totalsNode) {
      return;
    }
    let coffees = 0;
    let balance = 0;
    users.forEach((user) => {
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

  function renderAdminSettings(settings: AdminSettings): void {
    const priceInput = el<HTMLInputElement>('admin-price-input');
    const inviteInput = el<HTMLInputElement>('admin-invite-input');
    if (priceInput && settings && typeof settings.priceCents === 'number') {
      priceInput.value = (settings.priceCents / 100).toFixed(2);
    }
    if (inviteInput && settings && typeof settings.invite === 'string') {
      inviteInput.value = settings.invite;
    }
  }

  function renderAdmin(users: AdminUser[]): void {
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

  async function requestRecoveryCode(user: AdminUser, button: HTMLButtonElement, node: HTMLElement): Promise<void> {
    busy(button, true);
    try {
      const data = await api<LinkCode>('/api/admin/link-code', { userId: user.id });
      text(node, 'Code ' + data.code + ' — valid 60 min');
      node.hidden = false;
    } catch (error) {
      text(node, error instanceof ApiError ? error.code : 'unknown_error');
      node.hidden = false;
    }
    busy(button, false);
  }

  async function sendReminder(user: AdminUser, button: HTMLButtonElement, node: HTMLElement): Promise<void> {
    busy(button, true);
    try {
      await api('/api/admin/remind', { userId: user.id });
      text(node, 'Reminder queued — it appears on their device.');
      node.hidden = false;
    } catch (error) {
      text(node, error instanceof ApiError ? error.code : 'unknown_error');
      node.hidden = false;
    }
    busy(button, false);
  }

  async function recordPayment(user: AdminUser, input: HTMLInputElement, button: HTMLButtonElement): Promise<void> {
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
      const data = await api<AdminPaymentResponse>('/api/admin/payment', { userId: user.id, amountCents: amountCents });
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
    } catch (error) {
      fail(statusNode, error);
    }
    busy(button, false);
  }

  async function saveAdminSettings(): Promise<void> {
    const button = el<HTMLButtonElement>('btn-admin-settings');
    const statusNode = el('admin-settings-status');
    text(statusNode, '');

    const priceValue = parseFloat(el<HTMLInputElement>('admin-price-input')!.value);
    if (!isFinite(priceValue) || priceValue <= 0 || priceValue > 1000) {
      text(statusNode, 'invalid_settings');
      return;
    }
    const priceCents = Math.round(priceValue * 100);

    const inviteRaw = el<HTMLInputElement>('admin-invite-input')!.value;
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
    } catch (error) {
      fail(statusNode, error);
    }
    busy(button, false);
  }

  function csvField(value: unknown): string {
    let str = value === undefined || value === null ? '' : String(value);
    if (/[",\n]/.test(str)) {
      str = '"' + str.replace(/"/g, '""') + '"';
    }
    return str;
  }

  function exportAdminCsv(): void {
    const rows: unknown[][] = [['id', 'name', 'coffees', 'paidEuros', 'balanceEuros']];
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

  function downloadTextFile(content: string, filename: string): void {
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
  function toPem(buffer: ArrayBuffer, label: string): string {
    const bytes = new Uint8Array(buffer);
    let binary = '';
    for (let i = 0; i < bytes.length; i++) {
      binary += String.fromCharCode(bytes[i]);
    }
    const base64 = btoa(binary);
    const lines: string[] = [];
    for (let j = 0; j < base64.length; j += 64) {
      lines.push(base64.slice(j, j + 64));
    }
    return '-----BEGIN ' + label + '-----\n' + lines.join('\n') + '\n-----END ' + label + '-----\n';
  }

  function pemBytes(pem: string): Uint8Array<ArrayBuffer> {
    const normalized = pem.replace(/-----BEGIN PRIVATE KEY-----|-----END PRIVATE KEY-----|\s/g, '');
    if (!normalized) throw new Error('invalid_key');
    const binary = atob(normalized);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
    return bytes;
  }

  async function decryptAdminName(user: AdminUser): Promise<void> {
    const prefix = 'rsa-oaep-sha1:';
    if (!state.adminKey || !user.nameEncrypted || user.nameEncrypted.indexOf(prefix) !== 0) return;
    const raw = atob(user.nameEncrypted.slice(prefix.length));
    const bytes = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; i++) bytes[i] = raw.charCodeAt(i);
    const plain = await crypto.subtle.decrypt({ name: 'RSA-OAEP' }, state.adminKey, bytes);
    const data = JSON.parse(new TextDecoder().decode(plain));
    user.decryptedName = (String(data.firstName || '') + ' ' + String(data.lastName || '')).trim();
  }

  async function selectPrivateKey(event: Event): Promise<void> {
    const target = event.target as HTMLInputElement;
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
      await Promise.all(state.users.map(decryptAdminName));
      text(status, 'Names decrypted locally. The key has not left this browser.');
      renderAdmin(state.users);
    } catch (e) {
      state.adminKey = null;
      text(status, 'Could not decrypt names. Check that this is the matching PKCS#8 key.');
      renderAdmin(state.users);
    }
  }

  /* ----------------------------------------------------------- Laden ---- */

  async function refresh(): Promise<void> {
    try {
      const me = await api<MeResponse>('/api/me');
      renderMe(me);
      show('app');
      consumePendingBook();
      requestReminderCheck();
      const jobs: Promise<void>[] = [
        api<StatsResponse>('/api/stats').then(renderStats).catch(() => {}),
        api<HistoryResponse>('/api/history').then(renderHistory).catch(() => {})
      ];
      if (me.admin === true) {
        jobs.push(
          api<AdminUsersResponse>('/api/admin/users')
            .then((data) => {
              renderAdmin(data.users);
            })
            .catch(() => {})
        );
        jobs.push(
          api<AdminSettings>('/api/admin/settings')
            .then(renderAdminSettings)
            .catch(() => {})
        );
      }
      await Promise.all(jobs);
    } catch (e) {
      state.me = null;
      show('auth');
    }
  }

  /* ------------------------------------------------------- WebAuthn ----- */

  function creationOptions(options: any): PublicKeyCredentialCreationOptions {
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
      // Nur die Felder weitergeben, die der Browser kennt.
      authenticatorSelection: {
        residentKey: selection.residentKey,
        requireResidentKey: selection.requireResidentKey === true,
        userVerification: selection.userVerification
      },
      attestation: options.attestation,
      timeout: options.timeout,
      excludeCredentials: (options.excludeCredentials || []).map((item: any) => ({
        id: fromBase64Url(item.id), type: item.type, transports: item.transports
      }))
    };
  }

  function requestOptions(options: any): PublicKeyCredentialRequestOptions {
    return {
      challenge: fromBase64Url(options.challenge),
      rpId: options.rpId,
      userVerification: options.userVerification,
      timeout: options.timeout,
      allowCredentials: (options.allowCredentials || []).map((item: any) => ({
        id: fromBase64Url(item.id), type: item.type, transports: item.transports
      }))
    };
  }

  function serializeAttestation(credential: PublicKeyCredential): any {
    const response = credential.response as AuthenticatorAttestationResponse;
    let transports: string[] = [];
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

  function serializeAssertion(credential: PublicKeyCredential): any {
    const response = credential.response as AuthenticatorAssertionResponse;
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

  function busy(button: HTMLButtonElement | null, isBusy: boolean): void {
    if (button) {
      button.disabled = isBusy;
    }
  }

  function fail(node: HTMLElement | null, error: unknown): void {
    text(node, error instanceof ApiError ? error.code : 'unknown_error');
  }

  async function register(): Promise<void> {
    const button = el<HTMLButtonElement>('btn-register');
    const errorNode = el('auth-error');
    text(errorNode, '');
    if (!window.PublicKeyCredential) {
      text(errorNode, 'passkeys_unavailable');
      return;
    }
    const payload = {
      firstName: el<HTMLInputElement>('firstname-input')!.value,
      lastName: el<HTMLInputElement>('lastname-input')!.value,
      invite: el<HTMLInputElement>('invite-input')!.value
    };
    busy(button, true);
    try {
      const options = await api<any>('/api/register/options', payload);
      const credential = await navigator.credentials.create({ publicKey: creationOptions(options) }) as PublicKeyCredential | null;
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
      el<HTMLInputElement>('invite-input')!.value = '';
      await refresh();
    } catch (error) {
      fail(errorNode, error);
    }
    busy(button, false);
  }

  async function login(): Promise<void> {
    const button = el<HTMLButtonElement>('btn-login');
    const errorNode = el('auth-error');
    text(errorNode, '');
    if (!window.PublicKeyCredential) {
      text(errorNode, 'passkeys_unavailable');
      return;
    }
    busy(button, true);
    try {
      const options = await api<any>('/api/login/options', {});
      const credential = await navigator.credentials.get({ publicKey: requestOptions(options) }) as PublicKeyCredential | null;
      if (!credential) {
        throw new Error('cancelled');
      }
      await api('/api/login/verify', { credential: serializeAssertion(credential) });
      await refresh();
    } catch (error) {
      fail(errorNode, error);
    }
    busy(button, false);
  }

  async function linkDevice(): Promise<void> {
    const button = el<HTMLButtonElement>('btn-link-device');
    const errorNode = el('auth-error');
    text(errorNode, '');
    if (!window.PublicKeyCredential) {
      text(errorNode, 'passkeys_unavailable');
      return;
    }
    const code = el<HTMLInputElement>('link-code-input')!.value;
    busy(button, true);
    try {
      const options = await api<any>('/api/link/options', { code: code });
      const credential = await navigator.credentials.create({ publicKey: creationOptions(options) }) as PublicKeyCredential | null;
      if (!credential) {
        throw new Error('cancelled');
      }
      await api('/api/link/verify', { code: code, credential: serializeAttestation(credential) });
      el<HTMLInputElement>('link-code-input')!.value = '';
      await refresh();
    } catch (error) {
      fail(errorNode, error);
    }
    busy(button, false);
  }

  async function linkCode(): Promise<void> {
    const button = el<HTMLButtonElement>('btn-link-code');
    const errorNode = el('link-code-error');
    const display = el('link-code-display')!;
    const hint = el('link-code-hint')!;
    text(errorNode, '');
    busy(button, true);
    try {
      const data = await api<LinkCode>('/api/link/code', {});
      text(display, data.code);
      display.hidden = false;
      hint.hidden = false;
    } catch (error) {
      fail(errorNode, error);
    }
    busy(button, false);
  }

  async function generateSetupKey(): Promise<void> {
    const button = el<HTMLButtonElement>('btn-setup-generate');
    const initButton = el<HTMLButtonElement>('btn-setup-init');
    const status = el('setup-status');
    text(status, '');
    if (!window.crypto || !crypto.subtle || typeof crypto.subtle.generateKey !== 'function') {
      text(status, 'Setup needs a modern browser with Web Crypto support.');
      return;
    }
    busy(button, true);
    try {
      const pair = await crypto.subtle.generateKey(
        { name: 'RSA-OAEP', modulusLength: 4096, publicExponent: new Uint8Array([1, 0, 1]), hash: 'SHA-1' },
        true,
        ['encrypt', 'decrypt']
      ) as CryptoKeyPair;
      const [privateRaw, publicRaw] = await Promise.all([
        crypto.subtle.exportKey('pkcs8', pair.privateKey),
        crypto.subtle.exportKey('spki', pair.publicKey)
      ]);
      const privatePem = toPem(privateRaw, 'PRIVATE KEY');
      const publicPem = toPem(publicRaw, 'PUBLIC KEY');
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
    } catch (e) {
      state.setupPublicKey = null;
      text(status, 'Could not generate the key pair in this browser.');
    }
    busy(button, false);
  }

  async function initSetup(): Promise<void> {
    const button = el<HTMLButtonElement>('btn-setup-init');
    const status = el('setup-status');

    const priceValue = parseFloat(el<HTMLInputElement>('setup-price')!.value);
    if (!isFinite(priceValue) || priceValue <= 0 || priceValue > 1000) {
      text(status, 'invalid_price');
      return;
    }
    const priceCents = Math.round(priceValue * 100);

    const inviteRaw = el<HTMLInputElement>('setup-invite')!.value;
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
    } catch (error) {
      text(status, error instanceof ApiError ? error.code : 'unknown_error');
    }
    busy(button, false);
  }

  async function addCoffee(): Promise<void> {
    const button = el<HTMLButtonElement>('btn-add');
    const eventId = newEventId();
    busy(button, true);
    try {
      try {
        const data = await api<CoffeeResponse>('/api/coffee', { eventId: eventId });
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
      } catch (error) {
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
    } catch (e) {
      /* flushQueue() never rejects; this only keeps the handler from throwing. */
    } finally {
      // The catch branches above return early (queued offline booking, 401)
      // -- the button must come back on those paths too, not only after a
      // completed request, or a second offline tap would be impossible
      // until the next reload.
      busy(button, false);
    }
  }

  async function undoCoffee(): Promise<void> {
    const button = el<HTMLButtonElement>('btn-undo');
    busy(button, true);
    try {
      const data = await api<CoffeeResponse>('/api/coffee/undo', {});
      renderMe({
        id: state.me ? state.me.id : '',
        admin: state.me ? state.me.admin : false,
        coffees: data.coffees,
        balanceCents: data.balanceCents,
        priceCents: state.me ? state.me.priceCents : 0
      });
      await refresh();
    } catch (error) {
      if (error instanceof ApiError && error.status === 401) {
        show('auth');
      } else {
        fail(el('app-error'), error);
      }
    }
    busy(button, false);
  }

  async function logout(): Promise<void> {
    try {
      await api('/api/logout', {});
    } catch (e) {
      /* ignored – the client-side state is cleared regardless. */
    }
    state.me = null;
    show('auth');
    if ('clearAppBadge' in navigator) {
      (navigator as any).clearAppBadge().catch(() => {});
    }
  }

  /* ------------------------------------------------------- Feedback ----- */

  /* Kurzes, zufriedenes Doppel-Summen – bewusst kein einzelner harter Ruck. */
  function vibrate(pattern: number[]): void {
    if (window.navigator && typeof window.navigator.vibrate === 'function') {
      try {
        window.navigator.vibrate(pattern);
      } catch (e) {
        /* Manche Browser werfen ausserhalb einer Nutzergeste – einfach ignorieren. */
      }
    }
  }

  function bump(node: HTMLElement | null): void {
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
  function checkPendingBook(): void {
    const params = new URLSearchParams(window.location.search);
    if (params.get('book') !== '1') {
      return;
    }
    state.pendingBook = true;
    window.history.replaceState(null, '', window.location.pathname);
    const hint = el('nfc-hint');
    if (hint) {
      hint.hidden = false;
    }
  }

  function consumePendingBook(): void {
    if (!state.pendingBook) {
      return;
    }
    state.pendingBook = false;
    addCoffee();
  }

  /* ---------------------------------------------------------- Start ----- */

  function registerServiceWorker(): void {
    if ('serviceWorker' in navigator) {
      navigator.serviceWorker.register('/sw.js').catch(() => {
        /* Offline caching is optional; the app works without a service worker. */
      });
    }
  }

  function ready(): void {
    el('btn-register')!.addEventListener('click', register);
    el('btn-login')!.addEventListener('click', login);
    el('btn-link-device')!.addEventListener('click', linkDevice);
    el('btn-link-code')!.addEventListener('click', linkCode);
    el('btn-setup-generate')!.addEventListener('click', generateSetupKey);
    el('btn-setup-init')!.addEventListener('click', initSetup);
    el('btn-add')!.addEventListener('click', addCoffee);
    el('btn-undo')!.addEventListener('click', undoCoffee);
    el('btn-logout')!.addEventListener('click', logout);
    el('btn-notify-enable')!.addEventListener('click', enableReminders);
    el('private-key-input')!.addEventListener('change', selectPrivateKey);
    el('btn-admin-csv')!.addEventListener('click', exportAdminCsv);
    el('btn-admin-settings')!.addEventListener('click', saveAdminSettings);
    checkPendingBook();
    registerServiceWorker();
    initReminders();
    updateQueueHint();
    window.addEventListener('online', () => {
      flushQueue();
    });

    async function startNormalFlow(): Promise<void> {
      show('auth');
      await refresh();
      await flushQueue();
    }

    api<SetupStatus>('/api/setup/status')
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
  } else {
    ready();
  }
})();
