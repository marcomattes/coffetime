/**
 * Login flow on the main instance. See TESTPLAN.md "3. login.spec.ts".
 */

import { test, expect } from '../helpers/fixtures';
import { addVirtualAuthenticator, registerUserViaUi } from '../helpers/webauthn';
import { INVITE } from '../helpers/env';

test.describe('login', () => {
  test.beforeEach(async ({ testApi }) => {
    await testApi.reset();
  });

  test('register, sign out, sign in with passkey returns to the same account', async ({ page }) => {
    const authenticator = await addVirtualAuthenticator(page);
    try {
      await registerUserViaUi(page, { firstName: 'Radia', lastName: 'Perlman', invite: INVITE });
      await expect(page.getByTestId('view-app')).toBeVisible();

      // Book one coffee so the round trip can prove the account (not just a
      // session) survives sign-out/sign-in.
      await page.getByTestId('btn-add').click();
      await expect(page.getByTestId('counter')).toHaveText('1');

      await page.getByTestId('btn-logout').click();
      await expect(page.getByTestId('view-auth')).toBeVisible();

      await page.getByTestId('btn-login').click();
      await expect(page.getByTestId('view-app')).toBeVisible();
      await expect(page.getByTestId('counter')).toHaveText('1');
    } finally {
      await authenticator.remove();
    }
  });

  test('signing in with an authenticator that has no matching credential fails', async ({ page }) => {
    const authenticator = await addVirtualAuthenticator(page);
    try {
      await page.goto('/');
      await expect(page.getByTestId('view-auth')).toBeVisible();

      await page.getByTestId('btn-login').click();

      await expect(page.getByTestId('auth-error')).not.toBeEmpty();
      await expect(page.getByTestId('view-auth')).toBeVisible();
      await expect(page.getByTestId('view-app')).toBeHidden();
    } finally {
      await authenticator.remove();
    }
  });
});
