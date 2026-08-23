/**
 * First-run setup wizard on the dedicated "setup" instance (empty
 * adminPublicKey/namePepper, so it boots into the wizard -- see
 * TESTPLAN.md "Infrastructure" and "1. setup-wizard.spec.ts").
 *
 * The wizard writes settings straight into the DB and /api/test/reset does
 * NOT clear the settings table, so every test starts from a truly fresh
 * instance by deleting the SQLite file directly; migrations recreate it on
 * the next request.
 */

import * as fs from 'node:fs';
import * as path from 'node:path';
import { Page } from '@playwright/test';

import { test, expect } from '../helpers/fixtures';
import { addVirtualAuthenticator, registerUserViaUi } from '../helpers/webauthn';
import { DB_SETUP_PATH, SETUP_URL } from '../helpers/env';
import { TestApi } from '../helpers/test-api';

const SETUP_TOKEN_PATH = path.join(path.dirname(DB_SETUP_PATH), 'setup-token.txt');

function removeIfPresent(filePath: string): void {
  try {
    fs.unlinkSync(filePath);
  } catch (err) {
    if ((err as NodeJS.ErrnoException).code !== 'ENOENT') {
      throw err;
    }
  }
}

function removeSetupDb(): void {
  removeIfPresent(DB_SETUP_PATH);
  // A completed setup deletes the token file, so a run that starts from a
  // fresh database must start from a fresh token too.
  removeIfPresent(SETUP_TOKEN_PATH);
}

/**
 * The setup token as the operator would obtain it: read off the deployment's
 * filesystem. The server writes it on the first setup/status or setup/init,
 * so the wizard page has to have been loaded before this is called.
 */
function readSetupToken(): string {
  return fs.readFileSync(SETUP_TOKEN_PATH, 'utf8').trim();
}

/** Drives the wizard to completion via the real UI, including the key download. */
async function finishSetupViaUi(page: Page, priceEuros: string, invite: string): Promise<void> {
  await page.goto('/');
  await expect(page.getByTestId('view-setup')).toBeVisible();

  await page.getByTestId('setup-token').fill(readSetupToken());
  await page.getByTestId('setup-price').fill(priceEuros);
  await page.getByTestId('setup-invite').fill(invite);

  const downloadPromise = page.waitForEvent('download');
  await page.getByTestId('btn-setup-generate').click();
  await downloadPromise;
  await expect(page.getByTestId('btn-setup-init')).toBeEnabled();

  await page.getByTestId('btn-setup-init').click();
  await expect(page.getByTestId('view-auth')).toBeVisible();
}

test.describe.configure({ mode: 'serial' });

test.describe('setup wizard', () => {
  test.beforeEach(async () => {
    removeSetupDb();
  });

  test('a fresh instance shows only the setup view', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByTestId('view-setup')).toBeVisible();
    await expect(page.getByTestId('view-auth')).toBeHidden();
    await expect(page.getByTestId('view-app')).toBeHidden();
  });

  test('generate key & download produces a PKCS#8 PEM and enables finish setup', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByTestId('btn-setup-init')).toBeDisabled();

    const downloadPromise = page.waitForEvent('download');
    await page.getByTestId('btn-setup-generate').click();
    const download = await downloadPromise;

    expect(download.suggestedFilename()).toBe('admin-private.pem');
    const filePath = await download.path();
    expect(filePath).not.toBeNull();
    const content = fs.readFileSync(filePath as string, 'utf8');
    expect(content.startsWith('-----BEGIN PRIVATE KEY-----')).toBe(true);

    await expect(page.getByTestId('btn-setup-init')).toBeEnabled();
  });

  test('an invalid price is rejected client-side with no request', async ({ page }) => {
    await page.goto('/');

    let setupInitRequests = 0;
    page.on('request', (req) => {
      if (req.url().includes('/api/setup/init')) {
        setupInitRequests++;
      }
    });

    await page.getByTestId('setup-token').fill(readSetupToken());
    await page.getByTestId('setup-price').fill('0');
    await page.getByTestId('setup-invite').fill('WIZARD-INVITE');

    const downloadPromise = page.waitForEvent('download');
    await page.getByTestId('btn-setup-generate').click();
    await downloadPromise;
    await expect(page.getByTestId('btn-setup-init')).toBeEnabled();

    await page.getByTestId('btn-setup-init').click();
    await expect(page.getByTestId('setup-status')).toHaveText('invalid_price');
    expect(setupInitRequests).toBe(0);
  });

  test('a too-short invite is rejected client-side with no request', async ({ page }) => {
    await page.goto('/');

    let setupInitRequests = 0;
    page.on('request', (req) => {
      if (req.url().includes('/api/setup/init')) {
        setupInitRequests++;
      }
    });

    await page.getByTestId('setup-token').fill(readSetupToken());
    await page.getByTestId('setup-price').fill('2.00');
    await page.getByTestId('setup-invite').fill('abc'); // 3 chars, min is 4

    const downloadPromise = page.waitForEvent('download');
    await page.getByTestId('btn-setup-generate').click();
    await downloadPromise;
    await expect(page.getByTestId('btn-setup-init')).toBeEnabled();

    await page.getByTestId('btn-setup-init').click();
    await expect(page.getByTestId('setup-status')).toHaveText('invalid_invite');
    expect(setupInitRequests).toBe(0);
  });

  test('a missing setup token is caught client-side with no request', async ({ page }) => {
    await page.goto('/');

    let setupInitRequests = 0;
    page.on('request', (req) => {
      if (req.url().includes('/api/setup/init')) {
        setupInitRequests++;
      }
    });

    await page.getByTestId('setup-price').fill('2.00');
    await page.getByTestId('setup-invite').fill('WIZARD-INVITE');

    const downloadPromise = page.waitForEvent('download');
    await page.getByTestId('btn-setup-generate').click();
    await downloadPromise;

    await page.getByTestId('btn-setup-init').click();
    await expect(page.getByTestId('setup-status')).toContainText('setup token');
    expect(setupInitRequests).toBe(0);
  });

  /*
   * The gate itself. setup/init cannot require a session -- no account exists
   * yet -- and it fixes the RSA key every name is sealed to, which can never
   * be re-keyed. Without this, whoever reached a freshly uploaded instance
   * first would install their own key and become its administrator.
   */
  test('a wrong setup token is refused and leaves the instance unclaimed', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByTestId('view-setup')).toBeVisible();

    await page.getByTestId('setup-token').fill('0'.repeat(32));
    await page.getByTestId('setup-price').fill('2.00');
    await page.getByTestId('setup-invite').fill('WIZARD-INVITE');

    const downloadPromise = page.waitForEvent('download');
    await page.getByTestId('btn-setup-generate').click();
    await downloadPromise;
    await page.getByTestId('btn-setup-init').click();

    await expect(page.getByTestId('setup-status')).toHaveText('invalid_setup_token');
    await expect(page.getByTestId('view-setup')).toBeVisible();

    const status = await page.request.get(SETUP_URL + '/api/setup/status');
    expect((await status.json()).needsSetup).toBe(true);
  });

  test('the token file is generated on first contact and removed once setup completes', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByTestId('view-setup')).toBeVisible();

    expect(fs.existsSync(SETUP_TOKEN_PATH)).toBe(true);
    expect(readSetupToken()).toMatch(/^[0-9a-f]{32}$/);
    // The token never travels over HTTP -- reading it off the deployment is
    // the whole proof it stands for.
    const status = await page.request.get(SETUP_URL + '/api/setup/status');
    const body = await status.json();
    expect(body.setupTokenReady).toBe(true);
    expect(body.setupToken).toBeUndefined();
    expect(body.setupTokenPath).toBeUndefined();

    await finishSetupViaUi(page, '2.00', 'WIZARD-INVITE');
    expect(fs.existsSync(SETUP_TOKEN_PATH)).toBe(false);
  });

  test('finish setup stays disabled until a key has been generated, so no request can leave the page', async ({
    page,
  }) => {
    await page.goto('/');

    let setupInitRequests = 0;
    page.on('request', (req) => {
      if (req.url().includes('/api/setup/init')) {
        setupInitRequests++;
      }
    });

    await page.getByTestId('setup-price').fill('2.00');
    await page.getByTestId('setup-invite').fill('WIZARD-INVITE');

    // No key has been generated yet -- "Finish setup" must stay disabled and
    // therefore unclickable (see the finding about the "Generate the key
    // first." message in the final report: it is unreachable through real
    // UI interaction precisely because of this disabled state).
    await expect(page.getByTestId('btn-setup-init')).toBeDisabled();
    expect(setupInitRequests).toBe(0);
  });

  test('finishing setup switches to the auth view and setup/status reports needsSetup: false', async ({ page }) => {
    await finishSetupViaUi(page, '2.00', 'WIZARD-INVITE');

    const response = await page.request.get('/api/setup/status');
    expect(response.ok()).toBe(true);
    const body = await response.json();
    expect(body.needsSetup).toBe(false);
  });

  test('a second /api/setup/init returns 409 already_initialized', async ({ page }) => {
    await finishSetupViaUi(page, '2.00', 'WIZARD-INVITE');

    const response = await page.request.post('/api/setup/init', { data: {} });
    expect(response.status()).toBe(409);
    const body = await response.json();
    expect(body.error).toBe('already_initialized');
  });

  test('the first account registered after setup becomes administrator; a second does not', async ({ page }) => {
    await finishSetupViaUi(page, '2.00', 'WIZARD-INVITE');

    const setupApi = await TestApi.create(SETUP_URL);
    try {
      const authenticator = await addVirtualAuthenticator(page);
      try {
        await registerUserViaUi(page, { firstName: 'First', lastName: 'Admin', invite: 'WIZARD-INVITE' });
        await expect(page.getByTestId('admin-nav')).toBeVisible();
        await expect(page.getByTestId('price')).toHaveText('2.00 €');

        let state = await setupApi.state();
        expect(state.users).toHaveLength(1);
        expect(state.users[0].admin).toBe(true);
        const firstUserId = state.users[0].id;

        await page.getByTestId('btn-logout').click();
        await expect(page.getByTestId('view-auth')).toBeVisible();

        await registerUserViaUi(page, { firstName: 'Second', lastName: 'Person', invite: 'WIZARD-INVITE' });
        await expect(page.getByTestId('admin-nav')).toBeHidden();

        state = await setupApi.state();
        expect(state.users).toHaveLength(2);
        const admins = state.users.filter((u) => u.admin);
        expect(admins).toHaveLength(1);
        expect(admins[0].id).toBe(firstUserId);
      } finally {
        await authenticator.remove();
      }
    } finally {
      await setupApi.dispose();
    }
  });
});
