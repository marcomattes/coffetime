/**
 * Base test extended with a per-test `testApi` fixture. Specs import `test`
 * and `expect` from here instead of from '@playwright/test' directly.
 */

import { test as base } from '@playwright/test';

import { TestApi } from './test-api';

export const test = base.extend<{ testApi: TestApi }>({
  testApi: async ({}, use) => {
    const testApi = await TestApi.create();
    await use(testApi);
    await testApi.dispose();
  },
});

export { expect } from '@playwright/test';
