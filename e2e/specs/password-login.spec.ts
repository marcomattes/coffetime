/**
 * Administrator password sign-in and the invite link. Both exist for the same
 * kind of machine: a managed workstation where passkeys are blocked, and a
 * colleague who should not have to retype an invite code by hand.
 *
 * The password tests deliberately attach NO virtual authenticator to the page
 * that signs in -- that is the whole point of the feature, and a spec that
 * quietly had one available would not prove it.
 */

import { test, expect } from '../helpers/fixtures';
import { addVirtualAuthenticator, registerUserViaUi } from '../helpers/webauthn';
import { INVITE } from '../helpers/env';

const PASSWORD = 'kitchen tab passphrase';

/**
 * The password form lives in a closed <details> under the passkey button, so
 * it stays out of the way of the everyday path. Opening it is a real click on
 * the summary -- no JS toggling behind the UI's back.
 */
async function openPasswordForm(page: import('@playwright/test').Page): Promise<void> {
  await page.getByTestId('password-login').locator('summary').click();
  await expect(page.getByTestId('pw-password-input')).toBeVisible();
}

/** The admin's own password card lives on the Settings page. */
async function openAdminSettings(page: import('@playwright/test').Page): Promise<void> {
  await page.getByTestId('nav-settings').click();
  await expect(page.getByTestId('page-settings')).toBeVisible();
}

test.describe('admin password sign-in', () => {
  test.beforeEach(async ({ testApi }) => {
    await testApi.reset();
  });

  test('an admin sets a password and signs in with it on a passkey-less browser', async ({ page, testApi, browser }) => {
    // The first user in a fresh database becomes the administrator.
    const [admin] = await testApi.seed([{ firstName: 'Grace', lastName: 'Hopper' }]);
    await testApi.loginAs(page, admin.id);
    await page.goto('/');

    await expect(page.getByTestId('admin-nav')).toBeVisible();
    await openAdminSettings(page);
    await expect(page.getByTestId('admin-password-state')).toHaveText(/No password set/);

    await page.getByTestId('admin-password-input').fill(PASSWORD);
    await page.getByTestId('admin-password-repeat').fill(PASSWORD);
    await page.getByTestId('btn-admin-password').click();
    await expect(page.getByTestId('admin-password-status')).toHaveText(/Password saved/);
    await expect(page.getByTestId('admin-password-state')).toHaveText(/A password is set/);

    // A completely separate context: no session, no authenticator, nothing but
    // the name and the password -- the managed-workstation case.
    const fresh = await browser.newContext();
    const passwordPage = await fresh.newPage();
    try {
      await passwordPage.goto('/');
      await expect(passwordPage.getByTestId('view-auth')).toBeVisible();

      await openPasswordForm(passwordPage);
      await passwordPage.getByTestId('pw-firstname-input').fill('Grace');
      await passwordPage.getByTestId('pw-lastname-input').fill('Hopper');
      await passwordPage.getByTestId('pw-password-input').fill(PASSWORD);
      await passwordPage.getByTestId('btn-login-password').click();

      await expect(passwordPage.getByTestId('view-app')).toBeVisible();
      await expect(passwordPage.getByTestId('admin-nav')).toBeVisible();
      // The field must not keep the password around on a shared device.
      await expect(passwordPage.getByTestId('pw-password-input')).toHaveValue('');
    } finally {
      await fresh.close();
    }
  });

  test('a wrong password keeps the auth view and says nothing about the account', async ({ page, testApi }) => {
    const [admin] = await testApi.seed([{ firstName: 'Grace', lastName: 'Hopper' }]);
    await testApi.loginAs(page, admin.id);
    await page.goto('/');
    await openAdminSettings(page);
    await page.getByTestId('admin-password-input').fill(PASSWORD);
    await page.getByTestId('admin-password-repeat').fill(PASSWORD);
    await page.getByTestId('btn-admin-password').click();
    await expect(page.getByTestId('admin-password-state')).toHaveText(/A password is set/);
    await page.getByTestId('btn-logout').click();
    await expect(page.getByTestId('view-auth')).toBeVisible();

    await openPasswordForm(page);
    await page.getByTestId('pw-firstname-input').fill('Grace');
    await page.getByTestId('pw-lastname-input').fill('Hopper');
    await page.getByTestId('pw-password-input').fill('not the password');
    await page.getByTestId('btn-login-password').click();

    await expect(page.getByTestId('auth-error')).toHaveText('Wrong name or password.');
    await expect(page.getByTestId('view-app')).toBeHidden();

    // An account that does not exist at all answers identically -- no oracle.
    await page.getByTestId('pw-firstname-input').fill('Nobody');
    await page.getByTestId('pw-lastname-input').fill('Here');
    await page.getByTestId('pw-password-input').fill(PASSWORD);
    await page.getByTestId('btn-login-password').click();
    await expect(page.getByTestId('auth-error')).toHaveText('Wrong name or password.');
  });

  test('a non-admin never gets the password card', async ({ page, testApi }) => {
    const [, user] = await testApi.seed([
      { firstName: 'Grace', lastName: 'Hopper' },
      { firstName: 'Normal', lastName: 'User' },
    ]);

    await testApi.loginAs(page, user.id);
    await page.goto('/');

    await expect(page.getByTestId('view-app')).toBeVisible();
    await expect(page.getByTestId('admin-nav')).toBeHidden();
    await expect(page.getByTestId('admin-password-input')).toBeHidden();
  });

  test('removing the password closes the path again', async ({ page, testApi }) => {
    const [admin] = await testApi.seed([{ firstName: 'Grace', lastName: 'Hopper' }]);
    await testApi.loginAs(page, admin.id);
    await page.goto('/');
    await openAdminSettings(page);

    await page.getByTestId('admin-password-input').fill(PASSWORD);
    await page.getByTestId('admin-password-repeat').fill(PASSWORD);
    await page.getByTestId('btn-admin-password').click();
    await expect(page.getByTestId('admin-password-state')).toHaveText(/A password is set/);

    page.once('dialog', (dialog) => dialog.accept());
    await page.getByTestId('btn-admin-password-remove').click();
    await expect(page.getByTestId('admin-password-state')).toHaveText(/No password set/);

    await page.getByTestId('btn-logout').click();
    await expect(page.getByTestId('view-auth')).toBeVisible();
    await openPasswordForm(page);
    await page.getByTestId('pw-firstname-input').fill('Grace');
    await page.getByTestId('pw-lastname-input').fill('Hopper');
    await page.getByTestId('pw-password-input').fill(PASSWORD);
    await page.getByTestId('btn-login-password').click();
    await expect(page.getByTestId('auth-error')).toHaveText('Wrong name or password.');
  });

  test('mismatched repeat is refused before anything reaches the server', async ({ page, testApi }) => {
    const [admin] = await testApi.seed([{ firstName: 'Grace', lastName: 'Hopper' }]);
    await testApi.loginAs(page, admin.id);
    await page.goto('/');
    await openAdminSettings(page);

    await page.getByTestId('admin-password-input').fill(PASSWORD);
    await page.getByTestId('admin-password-repeat').fill(PASSWORD + ' typo');
    await page.getByTestId('btn-admin-password').click();

    await expect(page.getByTestId('admin-password-status')).toHaveText('The two entries do not match.');
    await expect(page.getByTestId('admin-password-state')).toHaveText(/No password set/);
  });

  test('a password shorter than the server minimum is refused client-side', async ({ page, testApi }) => {
    const [admin] = await testApi.seed([{ firstName: 'Grace', lastName: 'Hopper' }]);
    await testApi.loginAs(page, admin.id);
    await page.goto('/');
    await openAdminSettings(page);

    await page.getByTestId('admin-password-input').fill('short');
    await page.getByTestId('admin-password-repeat').fill('short');
    await page.getByTestId('btn-admin-password').click();

    await expect(page.getByTestId('admin-password-status')).toHaveText(/at least 12 characters/);
    await expect(page.getByTestId('admin-password-state')).toHaveText(/No password set/);
  });
});

test.describe('invite link', () => {
  test.beforeEach(async ({ testApi }) => {
    await testApi.reset();
  });

  test('?invite= prefills the code, announces it, and leaves the URL clean', async ({ page }) => {
    await page.goto(`/?invite=${encodeURIComponent(INVITE)}`);
    await expect(page.getByTestId('view-auth')).toBeVisible();

    await expect(page.getByTestId('invite-input')).toHaveValue(INVITE);
    await expect(page.getByTestId('invite-link-hint')).toBeVisible();
    // The code must not linger in the address bar, history or a bookmark.
    expect(new URL(page.url()).search).toBe('');
  });

  test('registration from an invite link works without typing the code', async ({ page }) => {
    const authenticator = await addVirtualAuthenticator(page);
    try {
      await page.goto(`/?invite=${encodeURIComponent(INVITE)}`);
      await expect(page.getByTestId('invite-input')).toHaveValue(INVITE);

      await page.getByTestId('firstname-input').fill('Barbara');
      await page.getByTestId('lastname-input').fill('Liskov');
      await page.getByTestId('btn-register').click();

      await expect(page.getByTestId('view-app')).toBeVisible();
    } finally {
      await authenticator.remove();
    }
  });

  test('a nonsense invite parameter is dropped rather than prefilled', async ({ page }) => {
    await page.goto('/?invite=ab');
    await expect(page.getByTestId('view-auth')).toBeVisible();

    await expect(page.getByTestId('invite-input')).toHaveValue('');
    await expect(page.getByTestId('invite-link-hint')).toBeHidden();
    expect(new URL(page.url()).search).toBe('');
  });

  test('an invite link alongside ?book=1 keeps the booking parameter working', async ({ page, testApi }) => {
    const [user] = await testApi.seed([{ firstName: 'Grace', lastName: 'Hopper' }]);
    await testApi.loginAs(page, user.id);

    await page.goto(`/?invite=${encodeURIComponent(INVITE)}&book=1`);

    // checkInviteLink() strips only its own parameter; checkPendingBook() must
    // still see book=1 and book the coffee.
    await expect(page.getByTestId('view-app')).toBeVisible();
    await expect(page.getByTestId('counter')).toHaveText('1');
    expect(new URL(page.url()).search).toBe('');
  });
});
