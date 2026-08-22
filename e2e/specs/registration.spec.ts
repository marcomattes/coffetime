/**
 * Registration flow on the main instance (has a configured admin key and a
 * fixed invite/price already, so no setup wizard is involved here). See
 * TESTPLAN.md "2. registration.spec.ts".
 */

import { test, expect } from '../helpers/fixtures';
import { addVirtualAuthenticator, registerUserViaUi } from '../helpers/webauthn';
import { INVITE } from '../helpers/env';

test.describe('registration', () => {
  test.beforeEach(async ({ testApi }) => {
    await testApi.reset();
  });

  test('happy path: valid names + invite creates a passkey and lands in the app view', async ({ page, testApi }) => {
    const authenticator = await addVirtualAuthenticator(page);
    try {
      await registerUserViaUi(page, { firstName: 'Ada', lastName: 'Lovelace', invite: INVITE });

      await expect(page.getByTestId('counter')).toHaveText('0');
      await expect(page.getByTestId('price')).toHaveText('1.50 €');
      await expect(page.getByTestId('device-count')).toHaveText('1 passkey');

      const state = await testApi.state();
      expect(state.users).toHaveLength(1);
    } finally {
      await authenticator.remove();
    }
  });

  test('wrong invite shows invalid_invite and creates no user', async ({ page, testApi }) => {
    await page.goto('/');
    await expect(page.getByTestId('view-auth')).toBeVisible();

    await page.getByTestId('firstname-input').fill('Grace');
    await page.getByTestId('lastname-input').fill('Hopper');
    await page.getByTestId('invite-input').fill('WRONG-INVITE');
    await page.getByTestId('btn-register').click();

    await expect(page.getByTestId('auth-error')).toHaveText('invalid_invite');
    await expect(page.getByTestId('view-auth')).toBeVisible();

    const state = await testApi.state();
    expect(state.users).toHaveLength(0);
  });

  test('empty first name shows invalid_name', async ({ page, testApi }) => {
    await page.goto('/');
    await expect(page.getByTestId('view-auth')).toBeVisible();

    await page.getByTestId('firstname-input').fill('');
    await page.getByTestId('lastname-input').fill('Hopper');
    await page.getByTestId('invite-input').fill(INVITE);
    await page.getByTestId('btn-register').click();

    await expect(page.getByTestId('auth-error')).toHaveText('invalid_name');

    const state = await testApi.state();
    expect(state.users).toHaveLength(0);
  });

  test('empty last name shows invalid_name', async ({ page, testApi }) => {
    await page.goto('/');
    await expect(page.getByTestId('view-auth')).toBeVisible();

    await page.getByTestId('firstname-input').fill('Grace');
    await page.getByTestId('lastname-input').fill('');
    await page.getByTestId('invite-input').fill(INVITE);
    await page.getByTestId('btn-register').click();

    await expect(page.getByTestId('auth-error')).toHaveText('invalid_name');

    const state = await testApi.state();
    expect(state.users).toHaveLength(0);
  });

  test('duplicate (normalized) name shows name_taken', async ({ page, testApi }) => {
    await testApi.seed([{ firstName: 'Anna', lastName: 'Schmidt' }]);

    await page.goto('/');
    await expect(page.getByTestId('view-auth')).toBeVisible();

    await page.getByTestId('firstname-input').fill('Anna');
    await page.getByTestId('lastname-input').fill('Schmidt');
    await page.getByTestId('invite-input').fill(INVITE);
    await page.getByTestId('btn-register').click();

    await expect(page.getByTestId('auth-error')).toHaveText('name_taken');

    const state = await testApi.state();
    expect(state.users).toHaveLength(1);
  });

  test('the session survives a reload', async ({ page }) => {
    const authenticator = await addVirtualAuthenticator(page);
    try {
      await registerUserViaUi(page, { firstName: 'Katherine', lastName: 'Johnson', invite: INVITE });
      await expect(page.getByTestId('view-app')).toBeVisible();

      await page.reload();
      await expect(page.getByTestId('view-app')).toBeVisible();
      await expect(page.getByTestId('view-auth')).toBeHidden();
    } finally {
      await authenticator.remove();
    }
  });

  test('signing out returns to the auth view and invalidates the session', async ({ page }) => {
    const authenticator = await addVirtualAuthenticator(page);
    try {
      await registerUserViaUi(page, { firstName: 'Margaret', lastName: 'Hamilton', invite: INVITE });
      await expect(page.getByTestId('view-app')).toBeVisible();

      await page.getByTestId('btn-logout').click();
      await expect(page.getByTestId('view-auth')).toBeVisible();

      const response = await page.request.get('/api/me');
      expect(response.status()).toBe(401);
    } finally {
      await authenticator.remove();
    }
  });
});
