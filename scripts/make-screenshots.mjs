#!/usr/bin/env node

/**
 * Regenerates the manifest screenshots in public/screenshots/.
 *
 * Chromium's install dialog shows these instead of the narrow mini-infobar
 * (see the `screenshots` member in public/manifest.webmanifest). They have to
 * show the real app, so this boots a throwaway PHP instance in test mode,
 * fills it through the same /api/test/* surface the e2e suite uses, and
 * photographs the result. Nothing here touches the production database.
 *
 * Usage: node scripts/make-screenshots.mjs
 */

import { chromium } from '@playwright/test';
import { generateKeyPairSync } from 'node:crypto';
import { spawn } from 'node:child_process';
import * as fs from 'node:fs';
import * as path from 'node:path';
import { fileURLToPath } from 'node:url';

const REPO_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

// Deliberately outside the e2e port pair so a running test suite is undisturbed.
const PORT = 8241;
const BASE_URL = `http://localhost:${PORT}`;
const TEST_TOKEN = 'screenshot-token';

const RUNTIME_DIR = path.join(REPO_ROOT, 'e2e', '.runtime', 'screenshots');
const CONFIG_PATH = path.join(RUNTIME_DIR, 'config.php');
const DB_PATH = path.join(RUNTIME_DIR, 'screenshots.sqlite');
const LOG_PATH = path.join(RUNTIME_DIR, 'server.log');
const OUT_DIR = path.join(REPO_ROOT, 'public', 'screenshots');

/**
 * Both shots are taken at deviceScaleFactor 2, so the PNG is twice the
 * viewport. The manifest's `sizes` must state those real pixels, and Chromium
 * rejects a screenshot whose longer side exceeds 2.3x its shorter one --
 * 844/390 = 2.16 and 800/1280 = 0.63 both stay inside that.
 */
const SHOTS = [
  { name: 'mobile-home.png', width: 390, height: 844, scale: 2, formFactor: 'narrow' },
  { name: 'desktop-home.png', width: 1280, height: 800, scale: 2, formFactor: 'wide' },
];

function phpString(value) {
  return `'${String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;
}

function writeConfig(adminPublicKey) {
  const entries = {
    priceCents: 150,
    invite: 'SCREENSHOT',
    rpId: 'localhost',
    origin: BASE_URL,
    dbPath: DB_PATH,
    testMode: true,
    testToken: TEST_TOKEN,
    adminPublicKey,
    namePepper: 'screenshot-pepper',
  };
  const lines = ['<?php', '', 'return ['];
  for (const [key, value] of Object.entries(entries)) {
    const literal = typeof value === 'boolean'
      ? (value ? 'true' : 'false')
      : typeof value === 'number' ? String(value) : phpString(value);
    lines.push(`    ${phpString(key)} => ${literal},`);
  }
  lines.push("    'admins' => [],", '];', '');
  fs.writeFileSync(CONFIG_PATH, lines.join('\n'));
}

/** Reuses the e2e keypair when one is lying around; RSA-4096 is slow to make. */
function adminPublicKey() {
  const existing = path.join(REPO_ROOT, 'e2e', '.runtime', '8231-8232', 'admin-public.pem');
  if (fs.existsSync(existing)) {
    return fs.readFileSync(existing, 'utf8');
  }
  const { publicKey } = generateKeyPairSync('rsa', {
    modulusLength: 4096,
    publicKeyEncoding: { type: 'spki', format: 'pem' },
    privateKeyEncoding: { type: 'pkcs8', format: 'pem' },
  });
  return publicKey;
}

async function waitForServer(timeoutMs) {
  const deadline = Date.now() + timeoutMs;
  let lastError = null;
  while (Date.now() < deadline) {
    try {
      const response = await fetch(`${BASE_URL}/api/setup/status`);
      if (response.status === 200) return;
      lastError = new Error(`unexpected status ${response.status}`);
    } catch (err) {
      lastError = err;
    }
    await new Promise((resolve) => setTimeout(resolve, 200));
  }
  const tail = fs.existsSync(LOG_PATH) ? fs.readFileSync(LOG_PATH, 'utf8').slice(-2000) : '(no log)';
  throw new Error(`PHP server not ready: ${lastError?.message}\n--- server.log ---\n${tail}`);
}

async function main() {
  fs.mkdirSync(RUNTIME_DIR, { recursive: true });
  fs.mkdirSync(OUT_DIR, { recursive: true });
  fs.rmSync(DB_PATH, { force: true });
  writeConfig(adminPublicKey());

  const logFd = fs.openSync(LOG_PATH, 'w');
  const server = spawn('php', ['-S', `localhost:${PORT}`, '-t', 'public'], {
    cwd: REPO_ROOT,
    env: { ...process.env, COFFEE_CONFIG_PATH: CONFIG_PATH, PHP_CLI_SERVER_WORKERS: '4' },
    detached: true,
    stdio: ['ignore', logFd, logFd],
  });
  fs.closeSync(logFd);
  server.unref();

  let browser;
  try {
    await waitForServer(15000);

    browser = await chromium.launch();
    // deviceScaleFactor 2 -> the PNG is twice the viewport (see SHOTS).
    const context = await browser.newContext({ deviceScaleFactor: 2 });
    const api = context.request;
    const headers = { 'X-Test-Token': TEST_TOKEN };

    await api.post(`${BASE_URL}/api/test/reset`, { headers, data: {} });

    // The first seeded user becomes admin, so "me" is deliberately not first:
    // the shot should show the ordinary member view, not the admin extras.
    const seeded = await api.post(`${BASE_URL}/api/test/seed`, {
      headers,
      data: {
        users: [
          { firstName: 'Office', lastName: 'Admin', coffees: 23 },
          { firstName: 'Sam', lastName: 'Brewer', coffees: 18 },
          { firstName: 'Screenshot', lastName: 'User', coffees: 0 },
          { firstName: 'Alex', lastName: 'Roaster', coffees: 11 },
          { firstName: 'Robin', lastName: 'Grinder', coffees: 7 },
          { firstName: 'Kim', lastName: 'Filter', coffees: 3 },
        ],
      },
    });
    const me = (await seeded.json()).users[2];
    await api.post(`${BASE_URL}/api/test/login`, { headers, data: { userId: me.id } });

    /*
     * Seeding only sets a total, while the 14-day chart reads real events --
     * so the history is booked day by day through the server clock offset. The
     * gaps are intentional: a chart with a bar on every single day looks fake.
     */
    const perDay = [1, 2, 0, 1, 3, 2, 1, 0, 2, 1, 2, 3, 1, 2];
    for (let i = 0; i < perDay.length; i += 1) {
      const daysAgo = perDay.length - 1 - i;
      await api.post(`${BASE_URL}/api/test/clock`, { headers, data: { offsetSeconds: -daysAgo * 86400 } });
      for (let n = 0; n < perDay[i]; n += 1) {
        await api.post(`${BASE_URL}/api/coffee`, { data: {} });
      }
    }
    await api.post(`${BASE_URL}/api/test/clock`, { headers, data: { offsetSeconds: 0 } });

    for (const shot of SHOTS) {
      const page = await context.newPage();
      await page.setViewportSize({ width: shot.width, height: shot.height });
      // Keep the "Add to Home Screen" card out of a screenshot that exists to
      // advertise exactly that action.
      await page.addInitScript(() => {
        try {
          window.localStorage.setItem('installDismissed', '1');
        } catch {
          /* storage blocked -- the card stays, which is merely cosmetic */
        }
      });
      await page.emulateMedia({ colorScheme: 'light' });
      await page.goto(BASE_URL);
      await page.waitForSelector('[data-testid="view-app"]', { state: 'visible' });
      await page.waitForSelector('#distribution li');
      // The chart bars are sized from JS after the first paint.
      await page.waitForTimeout(500);

      const file = path.join(OUT_DIR, shot.name);
      await page.screenshot({ path: file, scale: 'device' });
      await page.close();

      const { size } = fs.statSync(file);
      console.log(
        `${shot.name}  ${shot.width * shot.scale}x${shot.height * shot.scale}  `
        + `${(size / 1024).toFixed(0)} KB  form_factor=${shot.formFactor}`
      );
    }
  } finally {
    if (browser) await browser.close();
    try {
      process.kill(-server.pid, 'SIGTERM');
    } catch {
      try {
        process.kill(server.pid, 'SIGTERM');
      } catch {
        /* already gone */
      }
    }
  }
}

await main();
