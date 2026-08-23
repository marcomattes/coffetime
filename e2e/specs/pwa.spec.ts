/**
 * PWA plumbing: the manifest and its icons, service worker activation, the
 * "/?book=1" NFC-tag / app-shortcut booking shortcut (signed in and signed
 * out), the install card, and the pull-to-refresh gesture that replaces the
 * missing reload button in the installed app. See TESTPLAN.md "10.
 * pwa.spec.ts".
 */

import { test, expect } from '../helpers/fixtures';
import { addVirtualAuthenticator, registerUserViaUi } from '../helpers/webauthn';
import { INVITE, MAIN_URL } from '../helpers/env';

/** Chromium's own install event, as the page sees it. */
function dispatchInstallPrompt(page: import('@playwright/test').Page): Promise<void> {
  return page.evaluate(() => {
    const event = new Event('beforeinstallprompt') as Event & {
      prompt?: () => Promise<void>;
      userChoice?: Promise<{ outcome: string }>;
    };
    event.prompt = () => Promise.resolve();
    event.userChoice = Promise.resolve({ outcome: 'dismissed' });
    window.dispatchEvent(event);
  });
}

/**
 * A one-finger drag straight down from near the top of the viewport. Only
 * CDP can produce real touch events; page.touchscreen can tap but not swipe.
 */
async function swipeDown(page: import('@playwright/test').Page, distance: number): Promise<void> {
  const cdp = await page.context().newCDPSession(page);
  const x = 190;
  const startY = 100;
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x, y: startY }] });
  for (let travelled = 20; travelled <= distance; travelled += 20) {
    await cdp.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x, y: startY + travelled }] });
  }
  await cdp.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
  await cdp.detach();
}

test.describe('pwa', () => {
  test.beforeEach(async ({ testApi }) => {
    await testApi.reset();
  });

  test('the manifest is served, its icons exist, and the shell links it', async ({ page, request }) => {
    const manifestRes = await request.get('/manifest.webmanifest');
    expect(manifestRes.status()).toBe(200);
    const manifest = await manifestRes.json();

    expect(Array.isArray(manifest.icons)).toBe(true);
    expect(manifest.icons.length).toBeGreaterThan(0);
    for (const icon of manifest.icons as Array<{ src: string }>) {
      const iconRes = await request.get(icon.src);
      expect(iconRes.status(), icon.src).toBe(200);
    }

    await page.goto('/');
    const href = await page.locator('link[rel="manifest"]').getAttribute('href');
    expect(href).toBe('/manifest.webmanifest');
  });

  test('the service worker registers and activates on load', async ({ page }) => {
    await page.goto('/');
    await expect(
      page.evaluate(() => navigator.serviceWorker.ready.then(() => undefined))
    ).resolves.toBeUndefined();
  });

  test('/?book=1 while signed in books exactly one coffee and cleans the URL', async ({ page, testApi }) => {
    const [user] = await testApi.seed([{ firstName: 'Book', lastName: 'Er' }]);
    await testApi.loginAs(page, user.id);

    await page.goto('/?book=1');
    await expect(page.getByTestId('counter')).toHaveText('1');
    await expect(page).toHaveURL(`${MAIN_URL}/`);

    const state = await testApi.state();
    expect(state.users.find((u) => u.id === user.id)?.coffees).toBe(1);

    // Reloading must not re-book (the "book=1" param was stripped already).
    await page.reload();
    await expect(page.getByTestId('counter')).toHaveText('1');
    const stateAfterReload = await testApi.state();
    expect(stateAfterReload.users.find((u) => u.id === user.id)?.coffees).toBe(1);
  });

  test('/?book=1 while signed out shows the NFC hint and books once signed in', async ({ page, testApi }) => {
    const authenticator = await addVirtualAuthenticator(page);
    try {
      await registerUserViaUi(page, { firstName: 'Book', lastName: 'Later', invite: INVITE });
      await expect(page.getByTestId('counter')).toHaveText('0');

      await page.getByTestId('btn-logout').click();
      await expect(page.getByTestId('view-auth')).toBeVisible();

      await page.goto('/?book=1');
      // No data-testid on this element (see src/Frontend.php) -- same
      // situation as #admin-key-status; fall back to its plain id.
      await expect(page.locator('#nfc-hint')).toBeVisible();
      await expect(page.getByTestId('view-app')).toBeHidden();

      await page.getByTestId('btn-login').click();
      await expect(page.getByTestId('view-app')).toBeVisible();
      await expect(page.getByTestId('counter')).toHaveText('1');

      const state = await testApi.state();
      expect(state.users.some((u) => u.coffees === 1)).toBe(true);
    } finally {
      await authenticator.remove();
    }
  });

  test('the install card offers the browser prompt and stays dismissed', async ({ page, testApi }) => {
    const [user] = await testApi.seed([{ firstName: 'In', lastName: 'Staller' }]);
    await testApi.loginAs(page, user.id);

    await page.goto('/');
    await expect(page.getByTestId('view-app')).toBeVisible();
    // Nothing to install from: a desktop browser with no beforeinstallprompt
    // and no iOS Share sheet must not show an install card at all.
    await expect(page.getByTestId('install-card')).toBeHidden();

    await dispatchInstallPrompt(page);
    await expect(page.getByTestId('install-card')).toBeVisible();
    await expect(page.getByTestId('btn-install')).toBeVisible();

    await page.getByTestId('btn-install-dismiss').click();
    await expect(page.getByTestId('install-card')).toBeHidden();

    // "Not now" has to survive a restart, or the card nags on every start.
    await page.reload();
    await expect(page.getByTestId('view-app')).toBeVisible();
    await dispatchInstallPrompt(page);
    await expect(page.getByTestId('install-card')).toBeHidden();
  });

  test('pull to refresh reloads only in the installed app', async ({ browser, testApi }) => {
    const [user] = await testApi.seed([{ firstName: 'Pull', lastName: 'Down' }]);

    /* `standalone` is how iOS reports a home-screen launch; Chromium never
       sets it, so the flag has to be injected to exercise that branch. */
    async function openApp(standalone: boolean) {
      const context = await browser.newContext({
        baseURL: MAIN_URL,
        hasTouch: true,
        viewport: { width: 390, height: 844 },
      });
      if (standalone) {
        await context.addInitScript(() => {
          Object.defineProperty(navigator, 'standalone', { get: () => true, configurable: true });
        });
      }
      const page = await context.newPage();
      let refreshes = 0;
      page.on('request', (req) => {
        if (new URL(req.url()).pathname === '/api/me') {
          refreshes++;
        }
      });
      await testApi.loginAs(page, user.id);
      await page.goto('/');
      await expect(page.getByTestId('view-app')).toBeVisible();
      await expect.poll(() => refreshes).toBe(1);
      return { context, page, count: () => refreshes };
    }

    const installed = await openApp(true);
    try {
      await swipeDown(installed.page, 300);
      await expect.poll(() => installed.count()).toBe(2);
    } finally {
      await installed.context.close();
    }

    const tab = await openApp(false);
    try {
      // In a browser tab the gesture belongs to the browser's own reload;
      // handling it a second time here would refresh twice per pull.
      await swipeDown(tab.page, 300);
      await tab.page.waitForTimeout(500);
      expect(tab.count()).toBe(1);
    } finally {
      await tab.context.close();
    }
  });
});
