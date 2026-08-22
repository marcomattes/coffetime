/**
 * TESTPLAN.md section 4 -- booking.spec.ts (main instance, seeded user + test
 * login). Every assertion about money/counters is cross-checked against
 * server truth via TestApi.state() per "Conventions for implementers".
 */

import { test, expect } from '../helpers/fixtures';
import { INVITE, PRICE_CENTS } from '../helpers/env';

test.describe('booking', () => {
  test.beforeEach(async ({ testApi }) => {
    await testApi.reset();
  });

  test('taking a coffee increments the counter and the balance', async ({ page, testApi }) => {
    const [u] = await testApi.seed([{ firstName: 'Coffee', lastName: 'Tester' }]);
    await testApi.loginAs(page, u.id);
    await page.goto('/');
    await expect(page.getByTestId('view-app')).toBeVisible();

    await page.getByTestId('btn-add').click();

    await expect(page.getByTestId('counter')).toHaveText('1');
    await expect(page.getByTestId('balance')).toHaveText('1.50 €');
    await expect(page.getByTestId('today')).toHaveText('1');
    await expect(page.getByTestId('total')).toHaveText('1');

    const state = await testApi.state();
    const server = state.users.find((candidate) => candidate.id === u.id);
    expect(server?.coffees).toBe(1);
    expect(server?.balanceCents).toBe(150);
  });

  test('three bookings aggregate', async ({ page, testApi }) => {
    const [u] = await testApi.seed([{ firstName: 'Coffee', lastName: 'Tester' }]);
    await testApi.loginAs(page, u.id);
    await page.goto('/');
    await expect(page.getByTestId('view-app')).toBeVisible();

    await page.getByTestId('btn-add').click();
    await page.getByTestId('btn-add').click();
    await page.getByTestId('btn-add').click();

    await expect(page.getByTestId('counter')).toHaveText('3');
    await expect(page.getByTestId('balance')).toHaveText('4.50 €');

    const state = await testApi.state();
    const server = state.users.find((candidate) => candidate.id === u.id);
    expect(server?.coffees).toBe(3);
    expect(server?.balanceCents).toBe(450);
  });

  test('undo reverts the counter and the balance', async ({ page, testApi }) => {
    const [u] = await testApi.seed([{ firstName: 'Coffee', lastName: 'Tester' }]);
    await testApi.loginAs(page, u.id);
    await page.goto('/');
    await expect(page.getByTestId('view-app')).toBeVisible();

    await page.getByTestId('btn-add').click();
    await page.getByTestId('btn-add').click();
    await expect(page.getByTestId('counter')).toHaveText('2');
    await expect(page.getByTestId('balance')).toHaveText('3.00 €');

    await page.getByTestId('btn-undo').click();

    await expect(page.getByTestId('counter')).toHaveText('1');
    await expect(page.getByTestId('balance')).toHaveText('1.50 €');

    const state = await testApi.state();
    const server = state.users.find((candidate) => candidate.id === u.id);
    expect(server?.coffees).toBe(1);
    expect(server?.balanceCents).toBe(150);
  });

  test('undo at zero is a no-op', async ({ page, testApi }) => {
    const [u] = await testApi.seed([{ firstName: 'Coffee', lastName: 'Tester' }]);
    await testApi.loginAs(page, u.id);
    await page.goto('/');
    await expect(page.getByTestId('view-app')).toBeVisible();
    await expect(page.getByTestId('counter')).toHaveText('0');

    await page.getByTestId('btn-undo').click();

    // #app-error has no data-testid; it's the only element with this id.
    await expect(page.getByTestId('counter')).toHaveText('0');
    await expect(page.getByTestId('balance')).toHaveText('0.00 €');
    await expect(page.locator('#app-error')).toHaveText('');

    const state = await testApi.state();
    const server = state.users.find((candidate) => candidate.id === u.id);
    expect(server?.coffees).toBe(0);
    expect(server?.balanceCents).toBe(0);
  });

  test('price freeze: an in-flight price change never re-prices past bookings', async ({ page, testApi }) => {
    // The single seeded user is the first user in a fresh DB, so it is the
    // administrator -- required to hit /api/admin/settings/update.
    const [admin] = await testApi.seed([{ firstName: 'Coffee', lastName: 'Admin' }]);
    await testApi.loginAs(page, admin.id);
    await page.goto('/');
    await expect(page.getByTestId('view-app')).toBeVisible();

    try {
      // Book once at the original price (150).
      await page.getByTestId('btn-add').click();
      await expect(page.getByTestId('balance')).toHaveText('1.50 €');

      // Admin bumps the price to 200 through the same session's cookies.
      const settingsResponse = await page.request.post('/api/admin/settings/update', {
        data: { priceCents: 200, invite: INVITE },
      });
      expect(settingsResponse.ok()).toBe(true);
      await page.reload();
      await expect(page.getByTestId('view-app')).toBeVisible();

      // Second booking is priced at the new 200 -- 1.50 + 2.00 = 3.50.
      await page.getByTestId('btn-add').click();
      await expect(page.getByTestId('balance')).toHaveText('3.50 €');

      // Undo removes the *last* (200) event, refunding 2.00 -- not the
      // current price twice -- leaving the original 1.50 behind.
      await page.getByTestId('btn-undo').click();
      await expect(page.getByTestId('balance')).toHaveText('1.50 €');

      const state = await testApi.state();
      expect(state.config.priceCents).toBe(200);
    } finally {
      // /api/test/reset never clears the settings table -- always restore
      // the price, even if an assertion above failed.
      const restore = await page.request.post('/api/admin/settings/update', {
        data: { priceCents: PRICE_CENTS, invite: INVITE },
      });
      expect(restore.ok()).toBe(true);
      const state = await testApi.state();
      expect(state.config.priceCents).toBe(PRICE_CENTS);
    }
  });

  test('a seeded starting balance is reflected in the outstanding amount', async ({ page, testApi }) => {
    const [u] = await testApi.seed([{ firstName: 'Coffee', lastName: 'Tester', coffees: 4, paidCents: 300 }]);
    await testApi.loginAs(page, u.id);
    await page.goto('/');
    await expect(page.getByTestId('view-app')).toBeVisible();

    await expect(page.getByTestId('counter')).toHaveText('4');
    await expect(page.getByTestId('balance')).toHaveText('3.00 €');

    const state = await testApi.state();
    const server = state.users.find((candidate) => candidate.id === u.id);
    expect(server?.coffees).toBe(4);
    expect(server?.paidCents).toBe(300);
    expect(server?.balanceCents).toBe(300);
  });
});
