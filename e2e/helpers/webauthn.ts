/**
 * WebAuthn ceremonies via Chromium's CDP virtual authenticator -- see
 * TESTPLAN.md "WebAuthn". No mocking of the app: real navigator.credentials
 * calls, answered by a virtual (but protocol-real) authenticator.
 */

import { CDPSession, Page, expect } from '@playwright/test';

export interface VirtualAuthenticator {
  client: CDPSession;
  authenticatorId: string;
  /** Detaches the virtual authenticator. Safe to call even if the page/context already closed. */
  remove(): Promise<void>;
}

export async function addVirtualAuthenticator(page: Page): Promise<VirtualAuthenticator> {
  const client = await page.context().newCDPSession(page);
  await client.send('WebAuthn.enable');
  const { authenticatorId } = await client.send('WebAuthn.addVirtualAuthenticator', {
    options: {
      protocol: 'ctap2',
      transport: 'internal',
      hasResidentKey: true,
      hasUserVerification: true,
      isUserVerified: true,
      automaticPresenceSimulation: true,
    },
  });

  return {
    client,
    authenticatorId,
    async remove(): Promise<void> {
      try {
        await client.send('WebAuthn.removeVirtualAuthenticator', { authenticatorId });
      } catch {
        // Page/context already closed -- nothing left to clean up.
      }
    },
  };
}

export interface RegisterViaUiInput {
  firstName: string;
  lastName: string;
  invite: string;
}

/**
 * Drives the real registration UI: fills the auth-view form and clicks
 * "Create passkey". The caller must have already attached a virtual
 * authenticator to `page`'s context before calling this.
 */
export async function registerUserViaUi(page: Page, { firstName, lastName, invite }: RegisterViaUiInput): Promise<void> {
  await page.goto('/');
  await expect(page.getByTestId('view-auth')).toBeVisible();
  await page.getByTestId('firstname-input').fill(firstName);
  await page.getByTestId('lastname-input').fill(lastName);
  await page.getByTestId('invite-input').fill(invite);
  await page.getByTestId('btn-register').click();
  await expect(page.getByTestId('view-app')).toBeVisible();
}
