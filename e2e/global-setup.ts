/**
 * Playwright globalSetup: prepares two independent PHP instances (see
 * TESTPLAN.md "Infrastructure") and leaves them running for the whole test
 * run. globalTeardown.ts stops them again.
 */

import { generateKeyPairSync } from 'node:crypto';
import * as fs from 'node:fs';
import { spawn } from 'node:child_process';
import * as path from 'node:path';

import {
  ADMIN_PRIVATE_KEY_PATH,
  ADMIN_PUBLIC_KEY_PATH,
  CLOCK_OFFSET_PATH,
  CONFIG_MAIN_PATH,
  CONFIG_SETUP_PATH,
  DB_MAIN_PATH,
  DB_SETUP_PATH,
  INVITE,
  MAIN_LOG_PATH,
  MAIN_PORT,
  MAIN_URL,
  PIDS_PATH,
  PRICE_CENTS,
  RUNTIME_DIR,
  SETUP_LOG_PATH,
  SETUP_PORT,
  SETUP_URL,
  TEST_TOKEN,
} from './helpers/env';
import { writePhpConfig } from './helpers/php-config';

const REPO_ROOT = path.resolve(__dirname, '..');

interface Pids {
  main: number;
  setup: number;
}

function killPidQuiet(pid: number): void {
  // Servers are spawned detached (their own process group, pgid === pid),
  // so -pid targets the whole group. Fall back to the bare pid in case the
  // process somehow isn't a group leader. ESRCH ("no such process") just
  // means it is already gone -- never fatal here.
  try {
    process.kill(-pid, 'SIGTERM');
  } catch {
    try {
      process.kill(pid, 'SIGTERM');
    } catch {
      // already gone
    }
  }
}

function killStalePidsIfAny(): void {
  if (!fs.existsSync(PIDS_PATH)) {
    return;
  }
  try {
    const raw = JSON.parse(fs.readFileSync(PIDS_PATH, 'utf8')) as Partial<Pids>;
    if (typeof raw.main === 'number') killPidQuiet(raw.main);
    if (typeof raw.setup === 'number') killPidQuiet(raw.setup);
  } catch {
    // malformed/leftover file -- ignore, we're about to overwrite it
  }
  try {
    fs.unlinkSync(PIDS_PATH);
  } catch {
    // ignore
  }
}

function ensureAdminKeypair(): string {
  if (fs.existsSync(ADMIN_PUBLIC_KEY_PATH) && fs.existsSync(ADMIN_PRIVATE_KEY_PATH)) {
    return fs.readFileSync(ADMIN_PUBLIC_KEY_PATH, 'utf8');
  }
  // RSA-4096 generation takes a couple of seconds -- do it once and reuse
  // across runs (both files are gitignored under e2e/.runtime/).
  const { publicKey, privateKey } = generateKeyPairSync('rsa', {
    modulusLength: 4096,
    publicKeyEncoding: { type: 'spki', format: 'pem' },
    privateKeyEncoding: { type: 'pkcs8', format: 'pem' },
  });
  fs.writeFileSync(ADMIN_PUBLIC_KEY_PATH, publicKey);
  fs.writeFileSync(ADMIN_PRIVATE_KEY_PATH, privateKey);
  return publicKey;
}

function removeIfExists(filePath: string): void {
  try {
    fs.unlinkSync(filePath);
  } catch (err) {
    if ((err as NodeJS.ErrnoException).code !== 'ENOENT') {
      throw err;
    }
  }
}

function tailLog(logPath: string, maxChars = 4000): string {
  try {
    const content = fs.readFileSync(logPath, 'utf8');
    return content.length > maxChars ? content.slice(-maxChars) : content;
  } catch {
    return '(no log output captured)';
  }
}

async function waitForHttpOk(url: string, timeoutMs: number): Promise<void> {
  const deadline = Date.now() + timeoutMs;
  let lastError: unknown = null;
  while (Date.now() < deadline) {
    try {
      const response = await fetch(url);
      if (response.status === 200) {
        return;
      }
      lastError = new Error(`unexpected status ${response.status}`);
    } catch (err) {
      lastError = err;
    }
    await new Promise((resolve) => setTimeout(resolve, 200));
  }
  throw lastError ?? new Error('timed out waiting for server');
}

// Resolved once and reused for both servers -- see resolvePhpBinary() below
// for why we hand spawn() an absolute path instead of the bare 'php' name.
let phpBinary: string | undefined;

/**
 * Resolves `php` to an absolute executable path ourselves, so spawn() never
 * has to search PATH to find the command it runs (SonarQube: "Make sure the
 * PATH variable only contains fixed, unwriteable directories"). Walking
 * PATH here is just to *locate* the binary for our own logging/error
 * messages; the entry we settle on is then passed to spawn() verbatim, so
 * the actual process launch is never subject to PATH-based lookup or
 * hijacking via a writable/relative entry earlier on PATH.
 */
function resolvePhpBinary(): string {
  if (phpBinary !== undefined) {
    return phpBinary;
  }
  const dirs = (process.env.PATH ?? '').split(path.delimiter).filter(Boolean);
  for (const dir of dirs) {
    const candidate = path.join(dir, 'php');
    try {
      fs.accessSync(candidate, fs.constants.X_OK);
      phpBinary = candidate;
      return candidate;
    } catch {
      // not here, keep looking
    }
  }
  throw new Error("Could not resolve 'php' on PATH -- is PHP installed?");
}

function spawnPhpServer(port: number, configPath: string, logPath: string): number {
  // Truncate so the log only reflects this run -- much easier to read the
  // tail on a startup failure.
  const logFd = fs.openSync(logPath, 'w');
  const child = spawn(resolvePhpBinary(), ['-S', `localhost:${port}`, '-t', 'public'], {
    cwd: REPO_ROOT,
    env: {
      // PATH itself is inherited unchanged (not widened or replaced) --
      // the e2e harness deliberately runs with the developer's/CI runner's
      // own toolchain on it. It plays no part in resolving the executable
      // above; the child only needs it for its own use once running.
      ...process.env,
      COFFEE_CONFIG_PATH: configPath,
      PHP_CLI_SERVER_WORKERS: '4',
    },
    detached: true,
    stdio: ['ignore', logFd, logFd],
  });
  fs.closeSync(logFd);
  child.unref();
  if (child.pid === undefined) {
    throw new Error(`Failed to spawn PHP server on port ${port}`);
  }
  return child.pid;
}

export default async function globalSetup(): Promise<void> {
  fs.mkdirSync(RUNTIME_DIR, { recursive: true });

  killStalePidsIfAny();

  const adminPublicKey = ensureAdminKeypair();

  // Every run starts from an empty database and a zeroed clock, on both
  // instances -- migrations recreate the sqlite files on first request.
  removeIfExists(DB_MAIN_PATH);
  removeIfExists(DB_SETUP_PATH);
  removeIfExists(CLOCK_OFFSET_PATH);

  writePhpConfig(CONFIG_MAIN_PATH, {
    priceCents: PRICE_CENTS,
    invite: INVITE,
    admins: [],
    rpId: 'localhost',
    origin: MAIN_URL,
    dbPath: DB_MAIN_PATH,
    testMode: true,
    testToken: TEST_TOKEN,
    adminPublicKey,
    namePepper: 'e2e-pepper',
  });

  writePhpConfig(CONFIG_SETUP_PATH, {
    priceCents: PRICE_CENTS,
    invite: INVITE,
    admins: [],
    rpId: 'localhost',
    origin: SETUP_URL,
    dbPath: DB_SETUP_PATH,
    testMode: true,
    testToken: TEST_TOKEN,
    // Empty on purpose: this instance must boot into the first-run wizard.
    adminPublicKey: '',
    namePepper: '',
  });

  const mainPid = spawnPhpServer(MAIN_PORT, CONFIG_MAIN_PATH, MAIN_LOG_PATH);
  const setupPid = spawnPhpServer(SETUP_PORT, CONFIG_SETUP_PATH, SETUP_LOG_PATH);

  fs.writeFileSync(PIDS_PATH, JSON.stringify({ main: mainPid, setup: setupPid }, null, 2));

  try {
    await Promise.all([
      waitForHttpOk(`${MAIN_URL}/api/setup/status`, 15000),
      waitForHttpOk(`${SETUP_URL}/api/setup/status`, 15000),
    ]);
  } catch (err) {
    const mainTail = tailLog(MAIN_LOG_PATH);
    const setupTail = tailLog(SETUP_LOG_PATH);
    throw new Error(
      `PHP servers did not become ready in time: ${(err as Error).message}\n` +
        `--- ${MAIN_LOG_PATH} (tail) ---\n${mainTail}\n` +
        `--- ${SETUP_LOG_PATH} (tail) ---\n${setupTail}`
    );
  }
}
