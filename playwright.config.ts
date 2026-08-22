import { defineConfig, devices, chromium } from '@playwright/test';
import * as fs from 'node:fs';

import { MAIN_URL, SETUP_URL } from './e2e/helpers/env';

/**
 * Chromium is pre-installed for this environment under
 * PLAYWRIGHT_BROWSERS_PATH (see README/CI notes) but its revision does not
 * always match the one this @playwright/test version expects by default. If
 * Playwright's own default resolution already finds a real binary, prefer
 * it unchanged (this is what a fresh `npx playwright install chromium` in CI
 * produces). Only fall back to the known pre-installed binary when the
 * default path does not exist -- never hardcode it unconditionally.
 */
function resolveChromiumExecutablePath(): string | undefined {
  try {
    const defaultPath = chromium.executablePath();
    if (defaultPath && fs.existsSync(defaultPath)) {
      return undefined;
    }
  } catch {
    // fall through to the pre-installed fallback below
  }
  const fallback = '/opt/pw-browsers/chromium';
  return fs.existsSync(fallback) ? fallback : undefined;
}

const chromiumExecutablePath = resolveChromiumExecutablePath();

export default defineConfig({
  globalSetup: require.resolve('./e2e/global-setup'),
  globalTeardown: require.resolve('./e2e/global-teardown'),
  // One shared server + database for the whole run; specs coordinate state
  // through TestApi.reset() rather than through test isolation, so no
  // parallelism across or within files.
  workers: 1,
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    ...(chromiumExecutablePath ? { launchOptions: { executablePath: chromiumExecutablePath } } : {}),
  },
  projects: [
    {
      name: 'main',
      testDir: './e2e/specs',
      testIgnore: 'setup-wizard.spec.ts',
      use: { ...devices['Desktop Chrome'], baseURL: MAIN_URL },
    },
    {
      name: 'setup',
      testDir: './e2e/specs',
      testMatch: 'setup-wizard.spec.ts',
      use: { ...devices['Desktop Chrome'], baseURL: SETUP_URL },
    },
  ],
});
