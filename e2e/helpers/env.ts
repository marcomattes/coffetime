/**
 * Central place for every port, path, and constant the e2e scaffolding
 * needs. Both `playwright.config.ts` and the spec/helper files import from
 * here so there is exactly one source of truth for "where things live".
 */

import * as path from 'node:path';

function envInt(name: string, fallback: number): number {
  const raw = process.env[name];
  if (raw === undefined || raw.trim() === '') {
    return fallback;
  }
  const parsed = Number.parseInt(raw, 10);
  return Number.isFinite(parsed) && parsed > 0 ? parsed : fallback;
}

export const MAIN_PORT = envInt('E2E_PORT_MAIN', 8231);
export const SETUP_PORT = envInt('E2E_PORT_SETUP', 8232);

export const MAIN_URL = `http://localhost:${MAIN_PORT}`;
export const SETUP_URL = `http://localhost:${SETUP_PORT}`;

/** Fixed on purpose: every config file below embeds this same token. */
export const TEST_TOKEN = 'e2e-test-token';

export const INVITE = 'TEST-INVITE';
export const PRICE_CENTS = 150;

// Keyed by the port pair so runs with different E2E_PORT_* values (e.g.
// several working copies or agents verifying specs in parallel) get fully
// isolated configs, databases, logs, and pid files.
export const RUNTIME_DIR = path.resolve(__dirname, '..', '.runtime', `${MAIN_PORT}-${SETUP_PORT}`);

export const ADMIN_PUBLIC_KEY_PATH = path.join(RUNTIME_DIR, 'admin-public.pem');
export const ADMIN_PRIVATE_KEY_PATH = path.join(RUNTIME_DIR, 'admin-private.pem');

export const CONFIG_MAIN_PATH = path.join(RUNTIME_DIR, 'config-main.php');
export const CONFIG_SETUP_PATH = path.join(RUNTIME_DIR, 'config-setup.php');

export const DB_MAIN_PATH = path.join(RUNTIME_DIR, 'main.sqlite');
export const DB_SETUP_PATH = path.join(RUNTIME_DIR, 'setup.sqlite');

export const CLOCK_OFFSET_PATH = path.join(RUNTIME_DIR, 'clock-offset.json');

export const PIDS_PATH = path.join(RUNTIME_DIR, 'pids.json');

export const MAIN_LOG_PATH = path.join(RUNTIME_DIR, 'server-main.log');
export const SETUP_LOG_PATH = path.join(RUNTIME_DIR, 'server-setup.log');
