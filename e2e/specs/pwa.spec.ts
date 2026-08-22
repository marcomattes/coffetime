/**
 * PWA plumbing: the manifest and its icons, service worker activation, and
 * the "/?book=1" NFC-tag / app-shortcut booking shortcut, signed in and
 * signed out. See TESTPLAN.md "10. pwa.spec.ts".
 */

import { test, expect } from '../helpers/fixtures';
import { addVirtualAuthenticator, registerUserViaUi } from '../helpers/webauthn';
import { INVITE, MAIN_URL } from '../helpers/env';

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
});
