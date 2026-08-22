/**
 * Offline booking queue: a booking tapped while the network is down must
 * never be lost. It is queued client-side (localStorage) with a client-
 * generated eventId, retried once connectivity returns, and collapsed by the
 * server's idempotency check (Users::addCoffee via /api/coffee eventId) so a
 * retried entry never books twice.
 *
 * See TESTPLAN.md "6. offline-queue.spec.ts" and frontend/app.ts
 * (enqueueBooking/flushQueue/updateQueueHint).
 */

import { Page } from '@playwright/test';

import { test, expect } from '../helpers/fixtures';
import { TestApi } from '../helpers/test-api';

function hintText(count: number): string {
  return count + (count === 1 ? ' booking' : ' bookings') + ' waiting for connection — they sync automatically.';
}

async function seedAndSignIn(page: Page, testApi: TestApi, firstName: string, lastName: string) {
  const [user] = await testApi.seed([{ firstName, lastName }]);
  await testApi.loginAs(page, user.id);
  await page.goto('/');
  await expect(page.getByTestId('view-app')).toBeVisible();
  return user;
}

async function serverCoffees(testApi: TestApi, userId: string): Promise<number | undefined> {
  const state = await testApi.state();
  return state.users.find((candidate) => candidate.id === userId)?.coffees;
}

test.describe('offline queue', () => {
  test.beforeEach(async ({ testApi }) => {
    await testApi.reset();
  });

  test('an offline booking is queued locally while the server stays at zero', async ({ page, context, testApi }) => {
    const user = await seedAndSignIn(page, testApi, 'Off', 'Line');

    await context.setOffline(true);
    await page.getByTestId('btn-add').click();

    await expect(page.getByTestId('queue-hint')).toHaveText(hintText(1));
    // A queued booking never touches the counter until it is flushed.
    await expect(page.getByTestId('counter')).toHaveText('0');
    // Regression: the early return in addCoffee()'s offline branch used to
    // skip busy(button, false), leaving the button dead after one tap.
    await expect(page.getByTestId('btn-add')).toBeEnabled();

    expect(await serverCoffees(testApi, user.id)).toBe(0);
  });

  test('going back online flushes the queued booking exactly once', async ({ page, context, testApi }) => {
    const user = await seedAndSignIn(page, testApi, 'Off', 'Line');

    await context.setOffline(true);
    await page.getByTestId('btn-add').click();
    await expect(page.getByTestId('queue-hint')).toHaveText(hintText(1));

    await context.setOffline(false);
    // Deterministic trigger instead of relying on the browser's own
    // connectivity detection: flushQueue() is wired to this event.
    await page.evaluate(() => window.dispatchEvent(new Event('online')));

    await expect(page.getByTestId('counter')).toHaveText('1');
    await expect(page.getByTestId('queue-hint')).toBeHidden();

    // Idempotency: the flush must never duplicate the original booking.
    await expect.poll(() => serverCoffees(testApi, user.id)).toBe(1);
  });

  test('multiple offline bookings all queue and flush completely', async ({ page, context, testApi }) => {
    const user = await seedAndSignIn(page, testApi, 'Off', 'Line');

    await context.setOffline(true);
    await page.getByTestId('btn-add').click();
    await expect(page.getByTestId('queue-hint')).toHaveText(hintText(1));
    await expect(page.getByTestId('btn-add')).toBeEnabled();
    await page.getByTestId('btn-add').click();
    await expect(page.getByTestId('queue-hint')).toHaveText(hintText(2));
    await expect(page.getByTestId('btn-add')).toBeEnabled();
    await page.getByTestId('btn-add').click();
    await expect(page.getByTestId('queue-hint')).toHaveText(hintText(3));
    // Still queued, still zero server-side.
    expect(await serverCoffees(testApi, user.id)).toBe(0);

    await context.setOffline(false);
    await page.evaluate(() => window.dispatchEvent(new Event('online')));

    await expect(page.getByTestId('counter')).toHaveText('3');
    await expect(page.getByTestId('queue-hint')).toBeHidden();

    await expect.poll(() => serverCoffees(testApi, user.id)).toBe(3);
  });

  test('the queue survives a reload while offline and flushes after reconnecting', async ({ page, context, testApi }) => {
    const user = await seedAndSignIn(page, testApi, 'Off', 'Line');

    // The very first load only registers the service worker; give it a
    // moment to activate, then reload once so this page is actually
    // controlled by it before we cut the network.
    await page.evaluate(() => navigator.serviceWorker.ready.then(() => undefined));
    await page.reload();
    await expect(page.getByTestId('view-app')).toBeVisible();
    await page.evaluate(() => navigator.serviceWorker.ready.then(() => undefined));

    await context.setOffline(true);
    await page.getByTestId('btn-add').click();
    await expect(page.getByTestId('queue-hint')).toHaveText(hintText(1));
    await expect(page.getByTestId('btn-add')).toBeEnabled();
    await page.getByTestId('btn-add').click();
    await expect(page.getByTestId('queue-hint')).toHaveText(hintText(2));

    // A full navigation while offline only succeeds at all if the shell
    // (HTML/CSS/JS) comes from the service worker's cache -- without it the
    // browser would show its native offline error page instead of the app.
    // /api/me itself still has no network to reach, so the app falls back
    // to the auth view; the queued count must still be intact regardless.
    await page.reload();
    await expect(page.getByTestId('queue-hint')).toHaveText(hintText(2));
    expect(await serverCoffees(testApi, user.id)).toBe(0);

    await context.setOffline(false);
    await page.evaluate(() => window.dispatchEvent(new Event('online')));

    await expect(page.getByTestId('view-app')).toBeVisible();
    await expect(page.getByTestId('counter')).toHaveText('2');
    await expect(page.getByTestId('queue-hint')).toBeHidden();

    await expect.poll(() => serverCoffees(testApi, user.id)).toBe(2);
  });
});
