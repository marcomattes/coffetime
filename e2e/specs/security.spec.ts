/**
 * API-level security checks: session enforcement, admin authorization, the
 * test-control guard, method/path handling, and server-side validation. See
 * TESTPLAN.md "9. security.spec.ts". No browser needed -- everything here
 * goes through Playwright's `request` fixture / fresh request contexts
 * against the main instance.
 */

import { request as newRequestContext, APIRequestContext } from '@playwright/test';

import { test, expect } from '../helpers/fixtures';
import { MAIN_URL, PRICE_CENTS, TEST_TOKEN } from '../helpers/env';

/** Every endpoint that requires a session, with its expected method. */
const PROTECTED_ENDPOINTS: Array<{ path: string; method: 'GET' | 'POST' }> = [
  { path: '/api/me', method: 'GET' },
  { path: '/api/coffee', method: 'POST' },
  { path: '/api/coffee/undo', method: 'POST' },
  { path: '/api/stats', method: 'GET' },
  { path: '/api/history', method: 'GET' },
  { path: '/api/logout', method: 'POST' },
  { path: '/api/link/code', method: 'POST' },
  { path: '/api/reminders', method: 'GET' },
  { path: '/api/reminders/ack', method: 'POST' },
  { path: '/api/admin/users', method: 'GET' },
  { path: '/api/admin/payment', method: 'POST' },
  { path: '/api/admin/remind', method: 'POST' },
  { path: '/api/admin/link-code', method: 'POST' },
  { path: '/api/admin/user/delete', method: 'POST' },
  { path: '/api/admin/settings', method: 'GET' },
  { path: '/api/admin/settings/update', method: 'POST' },
];

/** A fresh request context logged in (via the test API) as the given user. */
async function loginContext(userId: string): Promise<APIRequestContext> {
  const ctx = await newRequestContext.newContext({ baseURL: MAIN_URL });
  const res = await ctx.post('/api/test/login', {
    headers: { 'X-Test-Token': TEST_TOKEN },
    data: { userId },
  });
  if (!res.ok()) {
    throw new Error(`test login failed: HTTP ${res.status()} ${await res.text()}`);
  }
  return ctx;
}

test.describe('security', () => {
  test.beforeEach(async ({ testApi }) => {
    await testApi.reset();
  });

  test('every session-protected endpoint returns 401 without a session', async ({ request }) => {
    for (const { path, method } of PROTECTED_ENDPOINTS) {
      const res = method === 'GET' ? await request.get(path) : await request.post(path, { data: {} });
      expect(res.status(), `${method} ${path}`).toBe(401);
      const body = await res.json();
      expect(body.error, `${method} ${path}`).toBe('unauthorized');
    }
  });

  test('/api/logout checks the session before the method: GET without a session is still 401, not 405', async ({ request }) => {
    const res = await request.get('/api/logout');
    expect(res.status()).toBe(401);
    expect((await res.json()).error).toBe('unauthorized');
  });

  test('admin endpoints return 403 for a signed-in non-admin user', async ({ testApi }) => {
    const [admin, user] = await testApi.seed([
      { firstName: 'Ad', lastName: 'Min' },
      { firstName: 'Normal', lastName: 'User' },
    ]);
    const ctx = await loginContext(user.id);
    try {
      const usersRes = await ctx.get('/api/admin/users');
      expect(usersRes.status()).toBe(403);
      expect((await usersRes.json()).error).toBe('forbidden');

      const paymentRes = await ctx.post('/api/admin/payment', { data: { userId: admin.id, amountCents: 100 } });
      expect(paymentRes.status()).toBe(403);
      expect((await paymentRes.json()).error).toBe('forbidden');

      const settingsRes = await ctx.get('/api/admin/settings');
      expect(settingsRes.status()).toBe(403);

      const remindRes = await ctx.post('/api/admin/remind', { data: { userId: admin.id } });
      expect(remindRes.status()).toBe(403);
      expect((await remindRes.json()).error).toBe('forbidden');

      // The one irreversible admin action: prove the refusal actually left the
      // account standing, not just that the response said 403.
      const deleteRes = await ctx.post('/api/admin/user/delete', { data: { userId: admin.id } });
      expect(deleteRes.status()).toBe(403);
      expect((await deleteRes.json()).error).toBe('forbidden');
      const stateRes = await ctx.get('/api/test/state', { headers: { 'X-Test-Token': TEST_TOKEN } });
      expect((await stateRes.json()).users.some((u: { id: string }) => u.id === admin.id)).toBe(true);
    } finally {
      await ctx.dispose();
    }
  });

  /*
   * "?book=1" auto-booked whenever document.referrer was empty, which a
   * foreign page produces at will with referrerpolicy="no-referrer". The
   * session cookie is SameSite=Lax and rides along on a top-level navigation,
   * so any page could charge a coffee to whoever was signed in. Only the
   * installed app books unasked now; a browser tab has to be tapped.
   */
  test('a cross-site no-referrer navigation to /?book=1 asks instead of booking', async ({ page, testApi }) => {
    const [user] = await testApi.seed([{ firstName: 'Vic', lastName: 'Tim' }]);
    await testApi.loginAs(page, user.id);

    const attacker = 'http://drive-by.invalid/';
    await page.route(attacker, (route) =>
      route.fulfill({
        status: 200,
        contentType: 'text/html',
        body: `<!doctype html><meta name="referrer" content="no-referrer">
               <a id="go" rel="noreferrer" referrerpolicy="no-referrer"
                  href="${MAIN_URL}/?book=1">go</a>
               <script>document.getElementById('go').click()</script>`,
      }),
    );

    await page.goto(attacker);
    await page.waitForURL((u) => u.origin === new URL(MAIN_URL).origin);

    // The referrer really is empty -- the guard this replaces would have booked.
    expect(await page.evaluate(() => document.referrer)).toBe('');
    await expect(page.getByTestId('book-confirm')).toBeVisible();
    expect((await testApi.state()).users.find((u) => u.id === user.id)?.coffees).toBe(0);
  });

  test('confirming the prompt books, so the tag and shortcut flow still works', async ({ page, testApi }) => {
    const [user] = await testApi.seed([{ firstName: 'Tag', lastName: 'User' }]);
    await testApi.loginAs(page, user.id);

    await page.goto('/?book=1');
    await page.getByTestId('btn-book-confirm').click();

    await expect(page.getByTestId('counter')).toHaveText('1');
    expect((await testApi.state()).users.find((u) => u.id === user.id)?.coffees).toBe(1);
  });

  test('/api/test/* is 404 without a token and with a wrong token', async ({ request }) => {
    const noToken = await request.get('/api/test/state');
    expect(noToken.status()).toBe(404);

    const wrongToken = await request.get('/api/test/state', { headers: { 'X-Test-Token': 'wrong' } });
    expect(wrongToken.status()).toBe(404);
  });

  test('wrong method on an existing route is 405; an unknown /api path is 404', async ({ testApi }) => {
    const [admin] = await testApi.seed([{ firstName: 'Ad', lastName: 'Min' }]);
    const ctx = await loginContext(admin.id);
    try {
      // /api/coffee is POST-only; a valid session makes it past the session
      // check, so the method mismatch is what actually gets hit.
      const wrongMethod = await ctx.get('/api/coffee');
      expect(wrongMethod.status()).toBe(405);
      expect((await wrongMethod.json()).error).toBe('method_not_allowed');

      const unknown = await ctx.get('/api/nope');
      expect(unknown.status()).toBe(404);
      expect((await unknown.json()).error).toBe('not_found');
    } finally {
      await ctx.dispose();
    }
  });

  test('app routes serve the shell; unknown or traversal paths do not', async ({ request }) => {
    for (const path of ['/', '/app', '/admin', '/login']) {
      const res = await request.get(path);
      expect(res.status(), path).toBe(200);
      expect(res.headers()['content-type'] ?? '', path).toContain('text/html');
      expect(await res.text(), path).toContain('Coffee Time');
    }

    const styleCss = await request.get('/style.css');
    expect(styleCss.status()).toBe(200);

    const configPhp = await request.get('/config.php');
    expect(configPhp.status()).toBe(404);

    const traversal = await request.get('/../src/Db.php');
    expect(traversal.status()).toBe(404);

    const unknownApi = await request.get('/api/nope');
    expect(unknownApi.status()).toBe(404);
  });

  test('registration options reject a wrong invite before validating names', async ({ request }) => {
    const res = await request.post('/api/register/options', {
      data: { invite: 'WRONG-INVITE', firstName: '', lastName: '' },
    });
    expect(res.status()).toBe(403);
    expect((await res.json()).error).toBe('invalid_invite');
  });

  test('admin payment rejects a string, a float and zero amountCents', async ({ testApi }) => {
    const [admin] = await testApi.seed([{ firstName: 'Ad', lastName: 'Min' }]);
    const ctx = await loginContext(admin.id);
    try {
      for (const amountCents of ['200', 1.5, 0]) {
        const res = await ctx.post('/api/admin/payment', { data: { userId: admin.id, amountCents } });
        expect(res.status(), JSON.stringify(amountCents)).toBe(400);
        expect((await res.json()).error, JSON.stringify(amountCents)).toBe('invalid_amount');
      }

      const state = await testApi.state();
      expect(state.users.find((u) => u.id === admin.id)?.paidCents).toBe(0);
    } finally {
      await ctx.dispose();
    }
  });

  test('admin settings update rejects an out-of-range price and a too-short invite', async ({ testApi }) => {
    const [admin] = await testApi.seed([{ firstName: 'Ad', lastName: 'Min' }]);
    const ctx = await loginContext(admin.id);
    try {
      const badPrice = await ctx.post('/api/admin/settings/update', { data: { priceCents: 0 } });
      expect(badPrice.status()).toBe(400);
      expect((await badPrice.json()).error).toBe('invalid_settings');

      const badInvite = await ctx.post('/api/admin/settings/update', { data: { invite: 'abc' } });
      expect(badInvite.status()).toBe(400);
      expect((await badInvite.json()).error).toBe('invalid_settings');

      // Neither rejected update may have persisted.
      const state = await testApi.state();
      expect(state.config.priceCents).toBe(PRICE_CENTS);
    } finally {
      await ctx.dispose();
    }
  });
});
