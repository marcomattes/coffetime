/**
 * TESTPLAN.md section 5 -- stats.spec.ts (main instance). Leaderboard,
 * anonymity, history chart and streak, all asserted against both the UI and
 * server truth via TestApi.state() where relevant.
 */

import { test, expect } from '../helpers/fixtures';

test.describe('stats', () => {
  test.beforeEach(async ({ testApi }) => {
    await testApi.reset();
  });

  test('leaderboard: ranks, totals and the "(me)" marker', async ({ page, testApi }) => {
    // Seed three users with 7/4/1 coffees; sign in as the middle one so the
    // first-seeded-user-is-admin rule stays irrelevant to this test.
    const [, mine] = await testApi.seed([
      { firstName: 'Top', lastName: 'Scorer', coffees: 7 },
      { firstName: 'Middle', lastName: 'Coffee', coffees: 4 },
      { firstName: 'Low', lastName: 'Sipper', coffees: 1 },
    ]);
    await testApi.loginAs(page, mine.id);
    await page.goto('/');
    await expect(page.getByTestId('view-app')).toBeVisible();

    await expect(page.getByTestId('total')).toHaveText('12');
    await expect(page.getByTestId('rank')).toHaveText('2');

    const items = page.locator('#distribution li');
    await expect(items).toHaveCount(3);

    const texts = await items.allTextContents();
    expect(texts.some((t) => t.includes('Rank 1') && t.includes('7 coffees'))).toBe(true);
    expect(texts.some((t) => t.includes('Rank 2') && t.includes('4 coffees'))).toBe(true);
    expect(texts.some((t) => t.includes('Rank 3') && t.includes('1 coffee'))).toBe(true);

    // Anonymity: no leaderboard row ever contains a seeded name.
    for (const t of texts) {
      expect(t).not.toContain('Top');
      expect(t).not.toContain('Scorer');
      expect(t).not.toContain('Middle');
      expect(t).not.toContain('Coffee');
      expect(t).not.toContain('Low');
      expect(t).not.toContain('Sipper');
    }

    // Exactly one row is marked as "me", and it's the signed-in user's row.
    const meItems = page.locator('#distribution li.me');
    await expect(meItems).toHaveCount(1);
    await expect(meItems).toContainText('(me)');
    await expect(meItems).toContainText('Rank 2');
  });

  test('history: today\'s count and the 14-day chart', async ({ page, testApi }) => {
    const [u] = await testApi.seed([{ firstName: 'Coffee', lastName: 'Tester' }]);
    await testApi.loginAs(page, u.id);
    await page.goto('/');
    await expect(page.getByTestId('view-app')).toBeVisible();

    await page.getByTestId('btn-add').click();
    await page.getByTestId('btn-add').click();

    await expect(page.getByTestId('today')).toHaveText('2');

    const days = page.getByTestId('history-day');
    await expect(days).toHaveCount(14);

    const todayColumn = days.last();
    const label = await todayColumn.getAttribute('aria-label');
    expect(label).toContain(': 2 coffees');
  });

  test('streak: consecutive days show the streak line; a single day stays hidden', async ({ page, testApi }) => {
    const [u] = await testApi.seed([{ firstName: 'Coffee', lastName: 'Tester' }]);
    await testApi.loginAs(page, u.id);
    await page.goto('/');
    await expect(page.getByTestId('view-app')).toBeVisible();

    try {
      // Only today booked so far -- a single-day streak stays hidden.
      await page.getByTestId('btn-add').click();
      await page.reload();
      await expect(page.getByTestId('view-app')).toBeVisible();
      await expect(page.getByTestId('streak')).toBeHidden();

      // Book "yesterday" via the server clock offset, then return to "today"
      // and book again -- two consecutive days.
      await testApi.clock(-86400);
      const response = await page.request.post('/api/coffee', { data: {} });
      expect(response.ok()).toBe(true);
      await testApi.clock(0);
      await page.getByTestId('btn-add').click();

      await page.reload();
      await expect(page.getByTestId('view-app')).toBeVisible();
      await expect(page.getByTestId('streak')).toBeVisible();
      await expect(page.getByTestId('streak')).toHaveText('🔥 2 days in a row');
    } finally {
      // Never let a leaked clock offset escape into the next test.
      await testApi.clock(0);
    }
  });
});
