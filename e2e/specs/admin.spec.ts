/**
 * Admin surface: settings, payments, recovery codes, local decryption, CSV
 * export and XSS safety of decrypted names. See TESTPLAN.md "8. admin.spec.ts".
 *
 * Reminder for every test here: the FIRST user seeded into a freshly reset
 * database is flagged is_admin by Users::create() (src/Users.php) -- so
 * `testApi.seed([...])` always returns the admin as its first element.
 */

import { generateKeyPairSync } from 'node:crypto';
import * as fs from 'node:fs';

import { test, expect } from '../helpers/fixtures';
import { addVirtualAuthenticator, registerUserViaUi } from '../helpers/webauthn';
import { ADMIN_PRIVATE_KEY_PATH, INVITE, MAIN_URL, PRICE_CENTS } from '../helpers/env';

const CIPHER_PREFIX = 'rsa-oaep-sha1:';
const DECRYPT_OK_STATUS = 'Names decrypted locally. The key has not left this browser.';
const DECRYPT_FAIL_STATUS = 'Could not decrypt names. Check that this is the matching PKCS#8 key.';

function adminRow(page: import('@playwright/test').Page, userId: string) {
  return page.locator(`[data-testid="admin-row"][data-user-id="${userId}"]`);
}

/**
 * Admins work across three pages now. Every test below that touches the user
 * list or the settings form has to open the page it lives on first -- which is
 * also what proves the navigation actually switches pages.
 */
async function openAdminPage(
  page: import('@playwright/test').Page,
  name: 'coffee' | 'users' | 'settings',
): Promise<void> {
  await page.getByTestId('nav-' + name).click();
  await expect(page.getByTestId('page-' + name)).toBeVisible();
}

/**
 * Desktop Chromium has no Web NFC, so the write path can only be exercised
 * against a stub. It records the NDEF messages the page hands to the adapter,
 * which is exactly what ends up on the tag.
 */
async function stubWebNfc(page: import('@playwright/test').Page, mode: 'ok' | 'abort'): Promise<void> {
  await page.addInitScript((behaviour: string) => {
    (window as unknown as { __nfcWrites: unknown[] }).__nfcWrites = [];
    (window as unknown as { NDEFReader: unknown }).NDEFReader = class {
      write(message: unknown): Promise<void> {
        (window as unknown as { __nfcWrites: unknown[] }).__nfcWrites.push(message);
        if (behaviour === 'abort') {
          // What Chrome throws when no tag came near the phone in time.
          return Promise.reject(new DOMException('write timed out', 'AbortError'));
        }
        return Promise.resolve();
      }
    };
  }, mode);
}

function nfcWrites(page: import('@playwright/test').Page): Promise<unknown[]> {
  return page.evaluate(() => (window as unknown as { __nfcWrites: unknown[] }).__nfcWrites);
}

test.describe('admin', () => {
  test.beforeEach(async ({ testApi }) => {
    await testApi.reset();
  });

  test('a non-admin user does not see the admin view', async ({ page, testApi }) => {
    const [, user] = await testApi.seed([
      { firstName: 'Ad', lastName: 'Min' },
      { firstName: 'Normal', lastName: 'User', coffees: 2 },
    ]);

    await testApi.loginAs(page, user.id);
    await page.goto('/');

    await expect(page.getByTestId('view-app')).toBeVisible();
    await expect(page.getByTestId('admin-nav')).toBeHidden();
  });

  test('the admin sees the settings card, ciphertext rows and a correct totals line', async ({ page, testApi }) => {
    const [admin, user] = await testApi.seed([
      { firstName: 'Ad', lastName: 'Min' },
      { firstName: 'Normal', lastName: 'User', coffees: 2, paidCents: 100 },
    ]);

    const stateBefore = await testApi.state();
    expect(stateBefore.users.find((u) => u.id === admin.id)?.admin).toBe(true);
    expect(stateBefore.users.find((u) => u.id === user.id)?.admin).toBe(false);

    await testApi.loginAs(page, admin.id);
    await page.goto('/');

    // Admins land on the counter like everyone else; the other two pages are
    // a click away and neither is showing yet.
    await expect(page.getByTestId('admin-nav')).toBeVisible();
    await expect(page.getByTestId('page-coffee')).toBeVisible();
    await expect(page.getByTestId('page-settings')).toBeHidden();
    await expect(page.getByTestId('page-users')).toBeHidden();

    await openAdminPage(page, 'settings');
    await expect(page.getByTestId('admin-price-input')).toBeVisible();
    await expect(page.getByTestId('admin-invite-input')).toBeVisible();
    await expect(page.getByTestId('page-coffee')).toBeHidden();

    await openAdminPage(page, 'users');
    await expect(page.getByTestId('admin-row')).toHaveCount(2);
    await expect(page.getByTestId('admin-users')).toContainText(CIPHER_PREFIX);
    await expect(page.getByTestId('page-settings')).toBeHidden();

    // tab: 2 coffees * 150 = 300, minus 100 paid = 200 -> 2.00 EUR outstanding.
    await expect(page.getByTestId('admin-totals')).toHaveText('2 accounts · 2 coffees · 2.00 € outstanding');
  });

  test('the open page survives a reload and follows the back button', async ({ page, testApi }) => {
    const [admin] = await testApi.seed([{ firstName: 'Ad', lastName: 'Min' }]);
    await testApi.loginAs(page, admin.id);
    await page.goto('/');
    await expect(page.getByTestId('admin-nav')).toBeVisible();
    // The counter is the plain page, so it keeps a plain URL.
    expect(new URL(page.url()).hash).toBe('');

    await openAdminPage(page, 'settings');
    expect(new URL(page.url()).hash).toBe('#/settings');

    await page.reload();
    await expect(page.getByTestId('page-settings')).toBeVisible();
    await expect(page.getByTestId('page-coffee')).toBeHidden();

    await page.goBack();
    await expect(page.getByTestId('page-coffee')).toBeVisible();
    await expect(page.getByTestId('page-settings')).toBeHidden();
  });

  test('a non-admin cannot reach an admin page by its URL', async ({ page, testApi }) => {
    const [, user] = await testApi.seed([
      { firstName: 'Ad', lastName: 'Min' },
      { firstName: 'Normal', lastName: 'User' },
    ]);
    await testApi.loginAs(page, user.id);

    await page.goto('/#/settings');
    await expect(page.getByTestId('view-app')).toBeVisible();
    await expect(page.getByTestId('page-coffee')).toBeVisible();
    await expect(page.getByTestId('page-settings')).toBeHidden();
    await expect(page.getByTestId('admin-nav')).toBeHidden();
    // The hash is cleaned out rather than left pointing at a page that is not
    // there. (What actually protects the data is the API, tested separately.)
    await expect.poll(() => new URL(page.url()).hash).toBe('');
  });

  test('recording a payment reduces the outstanding balance and is reflected server-side', async ({ page, testApi }) => {
    const [admin, user] = await testApi.seed([
      { firstName: 'Ad', lastName: 'Min' },
      { firstName: 'Normal', lastName: 'User', coffees: 2 }, // tab 3.00 EUR
    ]);
    await testApi.loginAs(page, admin.id);
    await page.goto('/');
    await openAdminPage(page, 'users');

    const row = adminRow(page, user.id);
    await row.getByTestId('admin-payment-input').fill('2.00');
    await row.getByTestId('admin-payment-btn').click();

    await expect(row).toContainText('1.00 €');
    await expect(row).toContainText('paid 2.00 €');

    const state = await testApi.state();
    const updated = state.users.find((u) => u.id === user.id);
    expect(updated?.paidCents).toBe(200);
    expect(updated?.balanceCents).toBe(100);
  });

  test('invalid payment amounts are rejected client-side without a request', async ({ page, testApi }) => {
    const [admin, user] = await testApi.seed([
      { firstName: 'Ad', lastName: 'Min' },
      { firstName: 'Normal', lastName: 'User', coffees: 2 },
    ]);
    await testApi.loginAs(page, admin.id);
    await page.goto('/');
    await openAdminPage(page, 'users');

    let paymentRequestSeen = false;
    page.on('request', (req) => {
      if (req.url().includes('/api/admin/payment')) {
        paymentRequestSeen = true;
      }
    });

    const row = adminRow(page, user.id);
    const input = row.getByTestId('admin-payment-input');
    const button = row.getByTestId('admin-payment-btn');

    for (const value of ['', '0', '-5']) {
      await input.fill(value);
      await button.click();
      await expect(page.getByTestId('admin-status')).toHaveText('invalid_amount');
    }

    expect(paymentRequestSeen).toBe(false);
    const state = await testApi.state();
    expect(state.users.find((u) => u.id === user.id)?.paidCents).toBe(0);
  });

  test('deleting an account removes it here and server-side, and names the open balance first', async ({ page, testApi }) => {
    const [admin, leaving] = await testApi.seed([
      { firstName: 'Ad', lastName: 'Min' },
      { firstName: 'Leaving', lastName: 'Person', coffees: 2 },
    ]);
    await testApi.loginAs(page, admin.id);
    await page.goto('/');
    await openAdminPage(page, 'users');

    const messages: string[] = [];
    page.on('dialog', (dialog) => {
      messages.push(dialog.message());
      void dialog.accept();
    });

    await adminRow(page, leaving.id).getByTestId('admin-delete-btn').click();

    await expect(adminRow(page, leaving.id)).toHaveCount(0);
    await expect(page.getByTestId('admin-row')).toHaveCount(1);

    // The server does not refuse a delete over unpaid coffees, so the prompt is
    // the only place the money is put in front of the person deciding.
    expect(messages).toHaveLength(1);
    expect(messages[0]).toContain('cannot be undone');
    expect(messages[0]).toContain('An open balance of ' + (2 * PRICE_CENTS / 100).toFixed(2) + ' €');

    const state = await testApi.state();
    expect(state.users.find((u) => u.id === leaving.id)).toBeUndefined();
    expect(state.users.find((u) => u.id === admin.id)).toBeDefined();
  });

  test('dismissing the delete confirmation changes nothing', async ({ page, testApi }) => {
    const [admin, user] = await testApi.seed([
      { firstName: 'Ad', lastName: 'Min' },
      { firstName: 'Normal', lastName: 'User', coffees: 2 },
    ]);
    await testApi.loginAs(page, admin.id);
    await page.goto('/');
    await openAdminPage(page, 'users');

    let deleteRequestSeen = false;
    page.on('request', (req) => {
      if (req.url().includes('/api/admin/user/delete')) {
        deleteRequestSeen = true;
      }
    });
    page.on('dialog', (dialog) => void dialog.dismiss());

    await adminRow(page, user.id).getByTestId('admin-delete-btn').click();

    await expect(adminRow(page, user.id)).toHaveCount(1);
    expect(deleteRequestSeen).toBe(false);
    expect((await testApi.state()).users.find((u) => u.id === user.id)).toBeDefined();
  });

  test('the admin cannot delete the account they are signed in with', async ({ page, testApi }) => {
    const [admin, user] = await testApi.seed([
      { firstName: 'Ad', lastName: 'Min' },
      { firstName: 'Normal', lastName: 'User' },
    ]);
    await testApi.loginAs(page, admin.id);
    await page.goto('/');
    await openAdminPage(page, 'users');

    // Their only administrator deleting themselves would lock the installation
    // out for good, so the button is not offered rather than answering 400.
    await expect(adminRow(page, admin.id).getByTestId('admin-delete-btn')).toBeDisabled();
    await expect(adminRow(page, user.id).getByTestId('admin-delete-btn')).toBeEnabled();
  });

  test('settings: saving a new price and invite takes effect and is restored afterwards', async ({ page, testApi, browser }) => {
    const [admin] = await testApi.seed([{ firstName: 'Ad', lastName: 'Min' }]);
    await testApi.loginAs(page, admin.id);
    await page.goto('/');
    await openAdminPage(page, 'settings');

    try {
      await page.getByTestId('admin-price-input').fill('2.50');
      await page.getByTestId('admin-invite-input').fill('NEW-INVITE');
      await page.getByTestId('btn-admin-settings').click();
      await expect(page.getByTestId('admin-settings-status')).toContainText('Saved');
      await expect(page.getByTestId('price')).toHaveText('2.50 €');

      // Old invite now fails.
      const oldInviteContext = await browser.newContext();
      const oldInvitePage = await oldInviteContext.newPage();
      const oldAuthenticator = await addVirtualAuthenticator(oldInvitePage);
      try {
        await oldInvitePage.goto('/');
        await oldInvitePage.getByTestId('firstname-input').fill('Old');
        await oldInvitePage.getByTestId('lastname-input').fill('Invitee');
        await oldInvitePage.getByTestId('invite-input').fill(INVITE);
        await oldInvitePage.getByTestId('btn-register').click();
        await expect(oldInvitePage.getByTestId('auth-error')).toHaveText('invalid_invite');
        await expect(oldInvitePage.getByTestId('view-app')).toBeHidden();
      } finally {
        await oldAuthenticator.remove();
        await oldInviteContext.close();
      }

      // New invite succeeds.
      const newInviteContext = await browser.newContext();
      const newInvitePage = await newInviteContext.newPage();
      const newAuthenticator = await addVirtualAuthenticator(newInvitePage);
      try {
        await registerUserViaUi(newInvitePage, { firstName: 'New', lastName: 'Invitee', invite: 'NEW-INVITE' });
        await expect(newInvitePage.getByTestId('view-app')).toBeVisible();
      } finally {
        await newAuthenticator.remove();
        await newInviteContext.close();
      }
    } finally {
      // Restore the defaults through the SAME admin session, regardless of
      // whether the assertions above passed -- /api/test/reset never clears
      // the settings table, so a leaked price/invite would poison every
      // later test in the suite.
      await page.getByTestId('admin-price-input').fill((PRICE_CENTS / 100).toFixed(2));
      await page.getByTestId('admin-invite-input').fill(INVITE);
      await page.getByTestId('btn-admin-settings').click();
      await expect(page.getByTestId('admin-settings-status')).toContainText('Saved');

      const state = await testApi.state();
      expect(state.config.priceCents).toBe(PRICE_CENTS);
    }

    // The invite is not exposed by /api/test/state -- prove it was restored
    // by registering with it from a fresh context.
    const restoredContext = await browser.newContext();
    const restoredPage = await restoredContext.newPage();
    const restoredAuthenticator = await addVirtualAuthenticator(restoredPage);
    try {
      await registerUserViaUi(restoredPage, { firstName: 'Restored', lastName: 'Invite', invite: INVITE });
      await expect(restoredPage.getByTestId('view-app')).toBeVisible();
    } finally {
      await restoredAuthenticator.remove();
      await restoredContext.close();
    }
  });

  test('paypal: the button appears with the outstanding amount once a handle is configured', async ({ page, testApi }) => {
    const [admin] = await testApi.seed([{ firstName: 'Ad', lastName: 'Min', coffees: 2 }]);
    await testApi.loginAs(page, admin.id);
    await page.goto('/');
    await expect(page.getByTestId('view-app')).toBeVisible();

    // Nothing configured: no button, however much is outstanding.
    await expect(page.getByTestId('balance')).toHaveText('3.00 €');
    await expect(page.getByTestId('paypal-card')).toBeHidden();

    try {
      // A pasted link is accepted; the server stores the bare handle.
      await openAdminPage(page, 'settings');
      await page.getByTestId('admin-paypal-input').fill('https://paypal.me/CoffeeKitchen');
      await page.getByTestId('btn-admin-settings').click();
      await expect(page.getByTestId('admin-settings-status')).toContainText('Saved');
      await expect(page.getByTestId('admin-paypal-input')).toHaveValue('CoffeeKitchen');

      await openAdminPage(page, 'coffee');
      const link = page.getByTestId('paypal-link');
      await expect(page.getByTestId('paypal-card')).toBeVisible();
      await expect(link).toHaveText('Pay 3.00 € with PayPal');
      await expect(link).toHaveAttribute('href', 'https://www.paypal.com/paypalme/CoffeeKitchen/3.00EUR');
      // An installed PWA must not navigate out of its own scope.
      await expect(link).toHaveAttribute('target', '_blank');

      // Booking another coffee moves the amount in the link with the balance.
      await page.getByTestId('btn-add').click();
      await expect(page.getByTestId('balance')).toHaveText('4.50 €');
      await expect(link).toHaveAttribute('href', 'https://www.paypal.com/paypalme/CoffeeKitchen/4.50EUR');

      // A settled tab has nothing to pay, so the button goes away again.
      const payment = await page.request.post('/api/admin/payment', {
        data: { userId: admin.id, amountCents: 450 },
      });
      expect(payment.ok()).toBe(true);
      await page.reload();
      await expect(page.getByTestId('view-app')).toBeVisible();
      await expect(page.getByTestId('balance')).toHaveText('0.00 €');
      await expect(page.getByTestId('paypal-card')).toBeHidden();
    } finally {
      // /api/test/reset never clears the settings table -- always put the
      // handle back to "not configured", even if an assertion above failed.
      await openAdminPage(page, 'settings');
      await page.getByTestId('admin-paypal-input').fill('');
      await page.getByTestId('btn-admin-settings').click();
      await expect(page.getByTestId('admin-settings-status')).toContainText('Saved');
    }
  });

  test('recovery code: the admin issues a code and a fresh device signs into that account', async ({ page, testApi, browser }) => {
    const [admin, user] = await testApi.seed([
      { firstName: 'Ad', lastName: 'Min' },
      { firstName: 'Target', lastName: 'User', coffees: 5 },
    ]);
    await testApi.loginAs(page, admin.id);
    await page.goto('/');
    await openAdminPage(page, 'users');

    const row = adminRow(page, user.id);
    await row.getByTestId('admin-recovery-btn').click();
    const codeNode = row.getByTestId('admin-recovery-code');
    await expect(codeNode).toBeVisible();
    const codeText = await codeNode.innerText();
    expect(codeText).toContain('valid 60 min');
    const match = codeText.match(/Code\s+([A-Z0-9-]+)\s/);
    expect(match).toBeTruthy();
    const code = match![1];

    const context = await browser.newContext();
    const newDevicePage = await context.newPage();
    const authenticator = await addVirtualAuthenticator(newDevicePage);
    try {
      await newDevicePage.goto('/');
      await expect(newDevicePage.getByTestId('view-auth')).toBeVisible();
      await newDevicePage.getByTestId('link-code-input').fill(code);
      await newDevicePage.getByTestId('btn-link-device').click();
      await expect(newDevicePage.getByTestId('view-app')).toBeVisible();
      await expect(newDevicePage.getByTestId('counter')).toHaveText('5');
    } finally {
      await authenticator.remove();
      await context.close();
    }
  });

  test('local decryption: the matching key decrypts names, a wrong key fails and leaves ciphertext visible', async ({ page, testApi }, testInfo) => {
    const [admin, user] = await testApi.seed([
      { firstName: 'Ad', lastName: 'Min' },
      { firstName: 'Jane', lastName: 'Doe' },
    ]);
    await testApi.loginAs(page, admin.id);
    await page.goto('/');

    await expect(page.getByTestId('admin-users')).toContainText(CIPHER_PREFIX);

    await page.getByTestId('private-key-input').setInputFiles(ADMIN_PRIVATE_KEY_PATH);
    await expect(page.locator('#admin-key-status')).toHaveText(DECRYPT_OK_STATUS);
    await expect(adminRow(page, admin.id)).toContainText('Ad Min');
    await expect(adminRow(page, user.id)).toContainText('Jane Doe');
    await expect(page.getByTestId('admin-users')).not.toContainText(CIPHER_PREFIX);

    // A throwaway keypair that does NOT match the seeded ciphertexts.
    const { privateKey } = generateKeyPairSync('rsa', {
      modulusLength: 2048,
      publicKeyEncoding: { type: 'spki', format: 'pem' },
      privateKeyEncoding: { type: 'pkcs8', format: 'pem' },
    });
    const wrongKeyPath = testInfo.outputPath('wrong-key.pem');
    fs.writeFileSync(wrongKeyPath, privateKey);

    await page.getByTestId('private-key-input').setInputFiles(wrongKeyPath);
    await expect(page.locator('#admin-key-status')).toHaveText(DECRYPT_FAIL_STATUS);
    await expect(page.getByTestId('admin-users')).toContainText(CIPHER_PREFIX);
    await expect(page.getByTestId('admin-users')).not.toContainText('Jane Doe');
  });

  test('CSV export downloads coffee-time.csv with decrypted names and correct amounts', async ({ page, testApi }) => {
    const [admin, user] = await testApi.seed([
      { firstName: 'Ad', lastName: 'Min' },
      { firstName: 'Jane', lastName: 'Doe', coffees: 3, paidCents: 100 },
    ]);
    await testApi.loginAs(page, admin.id);
    await page.goto('/');
    await openAdminPage(page, 'users');

    await page.getByTestId('private-key-input').setInputFiles(ADMIN_PRIVATE_KEY_PATH);
    await expect(page.locator('#admin-key-status')).toHaveText(DECRYPT_OK_STATUS);

    const downloadPromise = page.waitForEvent('download');
    await page.getByTestId('btn-admin-csv').click();
    const download = await downloadPromise;
    expect(download.suggestedFilename()).toBe('coffee-time.csv');

    const csvPath = await download.path();
    expect(csvPath).toBeTruthy();
    const content = fs.readFileSync(csvPath as string, 'utf8');
    const lines = content.split('\r\n').filter((line) => line.length > 0);

    expect(lines[0]).toBe('id,name,coffees,paidEuros,balanceEuros');
    // tab = 3 * 150 = 450, minus 100 paid = 350 -> 3.50 EUR outstanding.
    expect(lines).toContain(`${user.id},Jane Doe,3,1.00,3.50`);
    expect(lines).toContain(`${admin.id},Ad Min,0,0.00,0.00`);
  });

  test('XSS safety: a name containing markup renders as text, never as an element', async ({ page, testApi }) => {
    // src/Crypto.php normalizeNamePart() only collapses whitespace and trims
    // -- '<' and '>' survive normalization unchanged, so this is a faithful
    // seeded name rather than something the server would have rejected.
    const maliciousFirst = '<img src=x onerror=window.__x=1>';
    const [admin, user] = await testApi.seed([
      { firstName: 'Ad', lastName: 'Min' },
      { firstName: maliciousFirst, lastName: 'Doe' },
    ]);
    await testApi.loginAs(page, admin.id);
    await page.goto('/');

    await page.getByTestId('private-key-input').setInputFiles(ADMIN_PRIVATE_KEY_PATH);
    await expect(page.locator('#admin-key-status')).toHaveText(DECRYPT_OK_STATUS);

    const row = adminRow(page, user.id);
    await expect(row).toContainText(maliciousFirst);
    await expect(page.locator('[data-testid="admin-users"] img')).toHaveCount(0);

    const flagged = await page.evaluate(() => (window as unknown as { __x?: number }).__x);
    expect(flagged).toBeUndefined();
  });
  test('the NFC card shows both tag links, and hides the write buttons without Web NFC', async ({ page, testApi }) => {
    const [admin] = await testApi.seed([{ firstName: 'Ad', lastName: 'Min' }]);
    await testApi.loginAs(page, admin.id);
    await page.goto('/');
    await openAdminPage(page, 'settings');

    await expect(page.getByTestId('nfc-card')).toBeVisible();
    await expect(page.getByTestId('nfc-book-url')).toHaveText(`${MAIN_URL}/?book=1`);
    await expect(page.getByTestId('nfc-invite-url')).toHaveText(`${MAIN_URL}/?invite=${INVITE}`);

    // Desktop Chromium has no NDEFReader: the links stay, the buttons do not.
    await expect(page.getByTestId('nfc-support')).toContainText('Chrome on Android');
    await expect(page.getByTestId('btn-nfc-book')).toBeHidden();
    await expect(page.getByTestId('btn-nfc-invite')).toBeHidden();
  });

  test('a non-admin never sees the NFC card', async ({ page, testApi }) => {
    const [, user] = await testApi.seed([
      { firstName: 'Ad', lastName: 'Min' },
      { firstName: 'Normal', lastName: 'User' },
    ]);
    await testApi.loginAs(page, user.id);
    await page.goto('/');

    await expect(page.getByTestId('view-app')).toBeVisible();
    await expect(page.getByTestId('nfc-card')).toBeHidden();
  });

  test('with Web NFC present, each button writes its own link as a url record', async ({ page, testApi }) => {
    const [admin] = await testApi.seed([{ firstName: 'Ad', lastName: 'Min' }]);
    await stubWebNfc(page, 'ok');
    await testApi.loginAs(page, admin.id);
    await page.goto('/');
    await openAdminPage(page, 'settings');

    await expect(page.getByTestId('nfc-support')).toContainText('hold a blank NFC sticker');

    await page.getByTestId('btn-nfc-book').click();
    await expect(page.getByTestId('nfc-status')).toContainText('Booking tag written');

    await page.getByTestId('btn-nfc-invite').click();
    await expect(page.getByTestId('nfc-status')).toContainText('Registration tag written');

    expect(await nfcWrites(page)).toEqual([
      { records: [{ recordType: 'url', data: `${MAIN_URL}/?book=1` }] },
      { records: [{ recordType: 'url', data: `${MAIN_URL}/?invite=${INVITE}` }] },
    ]);
    await expect(page.getByTestId('nfc-error')).toHaveText('');
  });

  test('a failed write reports what to do and leaves both buttons usable again', async ({ page, testApi }) => {
    const [admin] = await testApi.seed([{ firstName: 'Ad', lastName: 'Min' }]);
    await stubWebNfc(page, 'abort');
    await testApi.loginAs(page, admin.id);
    await page.goto('/');
    await openAdminPage(page, 'settings');

    await page.getByTestId('btn-nfc-book').click();

    await expect(page.getByTestId('nfc-error')).toContainText('No tag found');
    await expect(page.getByTestId('nfc-status')).toHaveText('');
    await expect(page.getByTestId('btn-nfc-book')).toBeEnabled();
    await expect(page.getByTestId('btn-nfc-invite')).toBeEnabled();
  });
});
