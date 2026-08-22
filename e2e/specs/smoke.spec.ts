/**
 * Proves the scaffolding itself works end-to-end: the shell serves, the
 * TestApi round trip (seed/state/loginAs) lands a session cookie the
 * browser will use, and a full WebAuthn ceremony via the CDP virtual
 * authenticator completes a real register -> logout -> login cycle.
 *
 * See TESTPLAN.md for the full spec plan this scaffolding supports.
 */

import { test, expect } from '../helpers/fixtures';
import { addVirtualAuthenticator, registerUserViaUi } from '../helpers/webauthn';
import { INVITE, SETUP_URL } from '../helpers/env';

test.describe('smoke', () => {
  test.beforeEach(async ({ testApi }) => {
    await testApi.reset();
  });

  test('serves the shell and shows the auth view with no session', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByTestId('view-auth')).toBeVisible();
    await expect(page.getByTestId('view-app')).toBeHidden();
  });

  test('TestApi round trip: seed, state, loginAs reflect in the UI', async ({ page, testApi }) => {
    const [user] = await testApi.seed([{ firstName: 'Smoke', lastName: 'User', coffees: 2, paidCents: 0 }]);

    const state = await testApi.state();
    const seeded = state.users.find((candidate) => candidate.id === user.id);
    expect(seeded).toBeDefined();
    expect(seeded?.coffees).toBe(2);
    expect(seeded?.balanceCents).toBe(300);

    await testApi.loginAs(page, user.id);
    await page.goto('/');

    await expect(page.getByTestId('view-app')).toBeVisible();
    await expect(page.getByTestId('counter')).toHaveText('2');
    await expect(page.getByTestId('balance')).toHaveText('3.00 €');
  });

  test('full WebAuthn round trip: register, logout, login', async ({ page }) => {
    const authenticator = await addVirtualAuthenticator(page);
    try {
      await registerUserViaUi(page, { firstName: 'Webauthn', lastName: 'Smoke', invite: INVITE });
      await expect(page.getByTestId('counter')).toHaveText('0');

      await page.getByTestId('btn-logout').click();
      await expect(page.getByTestId('view-auth')).toBeVisible();

      await page.getByTestId('btn-login').click();
      await expect(page.getByTestId('view-app')).toBeVisible();
    } finally {
      await authenticator.remove();
    }
  });
});

// Not part of the setup-wizard suite (that's owned separately) -- just a
// sanity check, by absolute URL, that the second instance this scaffolding
// spins up really did boot into "needs setup".
test('the setup instance reports needsSetup on a fresh database', async ({ request }) => {
  const response = await request.get(`${SETUP_URL}/api/setup/status`);
  expect(response.ok()).toBe(true);
  const body = await response.json();
  expect(body.needsSetup).toBe(true);
});
