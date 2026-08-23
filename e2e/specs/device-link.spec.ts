/**
 * Device linking: a signed-in device generates a short-lived one-time code
 * (LinkCodes::create, SELF_TTL = 15 min) that a second, signed-out device
 * redeems to attach a brand-new passkey to the SAME account (Api::linkCode /
 * linkOptions / linkVerify). Each account has at most one unconsumed code at
 * a time, a redeemed code can never be reused, and an expired code is
 * rejected exactly like an invalid one.
 *
 * See TESTPLAN.md "7. device-link.spec.ts", src/Api.php (linkCode/
 * linkOptions/linkVerify) and src/LinkCodes.php (peek/consume/TTLs).
 */

import { Browser, BrowserContext, Page } from '@playwright/test';

import { test, expect } from '../helpers/fixtures';
import { addVirtualAuthenticator, registerUserViaUi } from '../helpers/webauthn';
import { INVITE } from '../helpers/env';

/** Reads the plaintext code out of the signed-in device's "Link another device" card. */
async function generateLinkCode(page: Page): Promise<string> {
  await page.getByTestId('btn-link-code').click();
  const display = page.getByTestId('link-code-display');
  await expect(display).toBeVisible();
  const raw = await display.textContent();
  const code = (raw ?? '').trim();
  expect(code.length).toBeGreaterThan(0);
  return code;
}

/** Opens a fresh, signed-out context+page with its own virtual authenticator. */
async function openSignedOutDevice(browser: Browser): Promise<{ context: BrowserContext; page: Page; remove: () => Promise<void> }> {
  const context = await browser.newContext();
  const page = await context.newPage();
  const authenticator = await addVirtualAuthenticator(page);
  await page.goto('/');
  await expect(page.getByTestId('view-auth')).toBeVisible();
  return {
    context,
    page,
    remove: async () => {
      await authenticator.remove();
      await context.close();
    },
  };
}

test.describe('device link', () => {
  test.beforeEach(async ({ testApi }) => {
    await testApi.reset();
  });

  test('linking a second device signs it into the same account and raises the passkey count', async ({ page, browser, testApi }) => {
    const authA = await addVirtualAuthenticator(page);
    try {
      await registerUserViaUi(page, { firstName: 'Link', lastName: 'Owner', invite: INVITE });
      // Book on A first so "same account" is provably more than two fresh
      // zero counters happening to match.
      await page.getByTestId('btn-add').click();
      await expect(page.getByTestId('counter')).toHaveText('1');
      await expect(page.getByTestId('device-count')).toHaveText('1 passkey');

      const code = await generateLinkCode(page);

      const deviceB = await openSignedOutDevice(browser);
      try {
        await deviceB.page.getByTestId('link-code-input').fill(code);
        await deviceB.page.getByTestId('btn-link-device').click();
        await expect(deviceB.page.getByTestId('view-app')).toBeVisible();
        await expect(deviceB.page.getByTestId('counter')).toHaveText('1');
      } finally {
        await deviceB.remove();
      }

      // Server truth: exactly one account, two credentials for it.
      const state = await testApi.state();
      expect(state.users).toHaveLength(1);
      expect(state.credentials).toBe(2);

      await page.reload();
      await expect(page.getByTestId('device-count')).toHaveText('2 passkeys');
    } finally {
      await authA.remove();
    }
  });

  test('an invalid code is rejected on the linking device', async ({ browser }) => {
    const deviceB = await openSignedOutDevice(browser);
    try {
      await deviceB.page.getByTestId('link-code-input').fill('ZZZZ-ZZZZ');
      await deviceB.page.getByTestId('btn-link-device').click();
      await expect(deviceB.page.getByTestId('auth-error')).toHaveText('invalid_code');
      await expect(deviceB.page.getByTestId('view-auth')).toBeVisible();
    } finally {
      await deviceB.remove();
    }
  });

  test('a used link code cannot be redeemed a second time', async ({ page, browser, testApi }) => {
    const authA = await addVirtualAuthenticator(page);
    try {
      await registerUserViaUi(page, { firstName: 'Used', lastName: 'Code', invite: INVITE });
      const code = await generateLinkCode(page);

      const deviceB = await openSignedOutDevice(browser);
      try {
        await deviceB.page.getByTestId('link-code-input').fill(code);
        await deviceB.page.getByTestId('btn-link-device').click();
        await expect(deviceB.page.getByTestId('view-app')).toBeVisible();
      } finally {
        await deviceB.remove();
      }

      // A second, independent device tries the SAME (now-consumed) code.
      const deviceC = await openSignedOutDevice(browser);
      try {
        await deviceC.page.getByTestId('link-code-input').fill(code);
        await deviceC.page.getByTestId('btn-link-device').click();
        await expect(deviceC.page.getByTestId('auth-error')).toHaveText('invalid_code');
        await expect(deviceC.page.getByTestId('view-auth')).toBeVisible();
      } finally {
        await deviceC.remove();
      }

      // Still exactly two credentials -- the reuse attempt created nothing.
      const state = await testApi.state();
      expect(state.credentials).toBe(2);
    } finally {
      await authA.remove();
    }
  });

  test('an expired link code cannot be redeemed', async ({ page, browser, testApi }) => {
    const authA = await addVirtualAuthenticator(page);
    try {
      await registerUserViaUi(page, { firstName: 'Expired', lastName: 'Code', invite: INVITE });
      const code = await generateLinkCode(page);

      // SELF_TTL is 15 minutes; push the server clock 16 minutes ahead so
      // the code we already hold is now expired.
      await testApi.clock(16 * 60);

      const deviceB = await openSignedOutDevice(browser);
      try {
        await deviceB.page.getByTestId('link-code-input').fill(code);
        await deviceB.page.getByTestId('btn-link-device').click();
        await expect(deviceB.page.getByTestId('auth-error')).toHaveText('invalid_code');
        await expect(deviceB.page.getByTestId('view-auth')).toBeVisible();
      } finally {
        await deviceB.remove();
      }
    } finally {
      await testApi.clock(0);
      await authA.remove();
    }
  });
});
