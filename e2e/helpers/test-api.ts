/**
 * Thin wrapper around the `/api/test/*` control surface (see
 * src/Api.php dispatchTest() and TESTPLAN.md "State control"). Built on
 * Playwright's APIRequestContext so it works both from spec-level `request`
 * fixtures and standalone in global setup/teardown-adjacent code.
 */

import { APIRequestContext, Page, request as playwrightRequest } from '@playwright/test';

import { MAIN_URL, TEST_TOKEN } from './env';

export interface SeedUserInput {
  firstName: string;
  lastName: string;
  coffees?: number;
  paidCents?: number;
}

export interface SeedUserResult {
  id: string;
  firstName: string;
  lastName: string;
}

export interface TestStateUser {
  id: string;
  coffees: number;
  paidCents: number;
  balanceCents: number;
  admin: boolean;
}

export interface TestState {
  users: TestStateUser[];
  credentials: number;
  sessions: number;
  config: {
    priceCents: number;
    admins: string[];
  };
}

async function assertOk(response: { ok(): boolean; status(): number; text(): Promise<string> }, label: string): Promise<void> {
  if (!response.ok()) {
    throw new Error(`${label} failed: HTTP ${response.status()} ${await response.text()}`);
  }
}

export class TestApi {
  private constructor(
    private readonly context: APIRequestContext,
    private readonly baseURL: string
  ) {}

  static async create(baseURL: string = MAIN_URL): Promise<TestApi> {
    const context = await playwrightRequest.newContext({
      baseURL,
      extraHTTPHeaders: { 'X-Test-Token': TEST_TOKEN },
    });
    return new TestApi(context, baseURL);
  }

  /** Wipes every table and zeroes the clock offset -- the standard per-test starting point. */
  async reset(): Promise<void> {
    const response = await this.context.post('/api/test/reset');
    await assertOk(response, 'TestApi.reset');
    await this.clock(0);
  }

  async seed(users: SeedUserInput[]): Promise<SeedUserResult[]> {
    const response = await this.context.post('/api/test/seed', { data: { users } });
    await assertOk(response, 'TestApi.seed');
    const body = (await response.json()) as { users: SeedUserResult[] };
    return body.users;
  }

  async state(): Promise<TestState> {
    const response = await this.context.get('/api/test/state');
    await assertOk(response, 'TestApi.state');
    return (await response.json()) as TestState;
  }

  async clock(offsetSeconds: number): Promise<void> {
    const response = await this.context.post('/api/test/clock', { data: { offsetSeconds } });
    await assertOk(response, 'TestApi.clock');
  }

  /**
   * Logs a page in as `userId` without a WebAuthn ceremony. Posts through
   * `page.request` (not this.context) so the Set-Cookie session lands in the
   * same browser context the page will navigate in next.
   */
  async loginAs(page: Page, userId: string): Promise<void> {
    const response = await page.request.post(`${this.baseURL}/api/test/login`, {
      headers: { 'X-Test-Token': TEST_TOKEN },
      data: { userId },
    });
    await assertOk(response, 'TestApi.loginAs');
  }

  async dispose(): Promise<void> {
    await this.context.dispose();
  }
}
