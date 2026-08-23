/**
 * Local payment reminders: the reminders card and its permission states, the
 * admin "Remind" button, and the end-to-end path from a due reminder to a
 * shown-and-acknowledged local notification via the service worker. See
 * TESTPLAN.md "11. reminders.spec.ts".
 *
 * The month-end logic runs on the server clock, so every date-dependent test
 * pins it to a fixed timestamp via TestApi.clock() (offset = target - now).
 * Sessions must be created AFTER the jump — they idle out after 30 days.
 */

import { request as newRequestContext, APIRequestContext } from '@playwright/test';

import { test, expect } from '../helpers/fixtures';
import { MAIN_URL, TEST_TOKEN } from '../helpers/env';

/** Seconds to add to the real clock to land on the given UTC timestamp. */
function offsetTo(utcMillis: number): number {
  return Math.round(utcMillis / 1000) - Math.floor(Date.now() / 1000);
}

const LAST_OF_MAY_2031 = Date.UTC(2031, 4, 31, 12, 0, 0);
const MID_JUNE_2031 = Date.UTC(2031, 5, 15, 12, 0, 0);

interface RemindersBody {
  monthEnd: { month: string; balanceCents: number } | null;
  admin: { requestedAt: number; balanceCents: number } | null;
}

async function readReminders(page: import('@playwright/test').Page): Promise<RemindersBody> {
  const res = await page.request.get(`${MAIN_URL}/api/reminders`);
  expect(res.status(), 'GET /api/reminders').toBe(200);
  return (await res.json()) as RemindersBody;
}

/** A fresh request context logged in (via the test API) as the given user. */
async function loginContext(userId: string): Promise<APIRequestContext> {
  const ctx = await newRequestContext.newContext({ baseURL: MAIN_URL });
  const res = await ctx.post('/api/test/login', {
    headers: { 'X-Test-Token': TEST_TOKEN },
    data: { userId },
  });
  if (!res.ok()) {
    throw new Error(`test login failed: HTTP ${res.status()} ${await res.text()}`);
  }
  return ctx;
}

/*
 * The default Chromium for headless runs is chrome-headless-shell, which has
 * no Notifications API at all: `Notification.permission` is stuck on 'denied'
 * there, even after grantPermissions(). This file is the only one that needs
 * that API, so it asks for the full Chrome-for-Testing build instead (a new
 * browser is launched for this file only). A local run whose config pins
 * launchOptions.executablePath keeps that binary — see playwright.config.ts.
 */
test.use({ channel: 'chromium' });

test.describe('reminders', () => {
  test.beforeEach(async ({ testApi }) => {
    await testApi.reset();
  });

  test('without the notification permission, the card offers the enable button', async ({ page, testApi }) => {
    const [user] = await testApi.seed([{ firstName: 'Quiet', lastName: 'User' }]);
    await testApi.loginAs(page, user.id);
    await page.goto('/');

    await expect(page.getByTestId('notify-status')).toHaveText(
      'Get a notification at the end of the month while your tab is still open.'
    );
    await expect(page.getByTestId('btn-notify-enable')).toBeVisible();
  });

  test('with the permission granted, the card reports reminders as on', async ({ page, testApi, context }) => {
    await context.grantPermissions(['notifications'], { origin: MAIN_URL });
    const [user] = await testApi.seed([{ firstName: 'Granted', lastName: 'User' }]);
    await testApi.loginAs(page, user.id);
    await page.goto('/');

    // Headless Chromium has no periodic background sync, so the on-open
    // wording is the expected one here.
    await expect(page.getByTestId('notify-status')).toHaveText(
      'Reminders are on — they appear at the latest when the app is opened.'
    );
    await expect(page.getByTestId('btn-notify-enable')).toBeHidden();
  });

  test('the admin Remind button queues a reminder the user then receives via /api/reminders', async ({ page, testApi }) => {
    const [admin, user] = await testApi.seed([
      { firstName: 'Ad', lastName: 'Min' },
      { firstName: 'Owing', lastName: 'User', coffees: 2 }, // 3.00 EUR open
    ]);

    await testApi.loginAs(page, admin.id);
    await page.goto('/');
    // The user list is its own page now.
    await page.getByTestId('nav-users').click();
    await expect(page.getByTestId('page-users')).toBeVisible();

    const row = page.locator(`[data-testid="admin-row"][data-user-id="${user.id}"]`);
    await row.getByTestId('admin-remind-btn').click();
    await expect(row.getByTestId('admin-remind-status')).toHaveText(
      'Reminder queued — it appears on their device.'
    );

    // The queued reminder is delivered through the user's own session.
    await testApi.loginAs(page, user.id);
    const due = await readReminders(page);
    expect(due.admin).not.toBeNull();
    expect(due.admin!.requestedAt).toBeGreaterThan(0);
    expect(due.admin!.balanceCents).toBe(300);
    expect(due.monthEnd).toBeNull();

    // Reading is not consuming.
    const again = await readReminders(page);
    expect(again.admin).not.toBeNull();
  });

  test('mid-month, no month-end reminder is due', async ({ page, testApi }) => {
    await testApi.clock(offsetTo(MID_JUNE_2031));
    const [user] = await testApi.seed([{ firstName: 'Mid', lastName: 'Month', coffees: 2 }]);
    await testApi.loginAs(page, user.id);
    await page.goto('/');

    const due = await readReminders(page);
    expect(due.monthEnd).toBeNull();
    expect(due.admin).toBeNull();
  });

  test('on the last day of the month, opening the app shows the notifications and acknowledges them', async ({ page, testApi, context }) => {
    await context.grantPermissions(['notifications'], { origin: MAIN_URL });
    await testApi.clock(offsetTo(LAST_OF_MAY_2031));

    const [admin, user] = await testApi.seed([
      { firstName: 'Ad', lastName: 'Min' },
      { firstName: 'Owing', lastName: 'User', coffees: 2 }, // 3.00 EUR open
    ]);

    // Queue the admin reminder through a separate request context: the page
    // itself must only ever run as the user, or its service worker could
    // check (and acknowledge) reminders against the wrong session.
    const adminCtx = await loginContext(admin.id);
    try {
      const remindRes = await adminCtx.post('/api/admin/remind', { data: { userId: user.id } });
      expect(remindRes.status()).toBe(200);
    } finally {
      await adminCtx.dispose();
    }

    // No page navigation has happened yet, so no service worker can race
    // these API-level reads.
    await testApi.loginAs(page, user.id);
    const due = await readReminders(page);
    expect(due.monthEnd?.month).toBe('2031-05');
    expect(due.admin).not.toBeNull();

    // Opening the app pokes the service worker, which shows both local
    // notifications and acknowledges exactly what it showed.
    await page.goto('/');
    await expect
      .poll(async () => {
        return page.evaluate(() =>
          navigator.serviceWorker.ready
            .then((registration) => registration.getNotifications())
            .then((notifications) => notifications.map((n) => n.tag).sort())
        );
      })
      .toEqual(['admin-reminder', 'month-end-2031-05']);

    await expect
      .poll(async () => {
        const after = await readReminders(page);
        return { monthEnd: after.monthEnd, admin: after.admin };
      })
      .toEqual({ monthEnd: null, admin: null });
  });
});
