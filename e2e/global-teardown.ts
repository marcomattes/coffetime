/**
 * Playwright globalTeardown: stops the two PHP servers global-setup.ts
 * started, using the PIDs it recorded.
 */

import * as fs from 'node:fs';

import { PIDS_PATH } from './helpers/env';

interface Pids {
  main: number;
  setup: number;
}

function killPid(pid: number): void {
  // Servers were spawned detached, so pid is also the process group id.
  // Killing the group (negative pid) takes any children (e.g. the actual
  // php-cli workers) down with it. ESRCH just means it's already gone.
  try {
    process.kill(-pid, 'SIGTERM');
    return;
  } catch (err) {
    if ((err as NodeJS.ErrnoException).code === 'ESRCH') {
      return;
    }
  }
  try {
    process.kill(pid, 'SIGTERM');
  } catch (err) {
    if ((err as NodeJS.ErrnoException).code !== 'ESRCH') {
      throw err;
    }
  }
}

export default async function globalTeardown(): Promise<void> {
  if (!fs.existsSync(PIDS_PATH)) {
    return;
  }

  try {
    const pids = JSON.parse(fs.readFileSync(PIDS_PATH, 'utf8')) as Partial<Pids>;
    if (typeof pids.main === 'number') killPid(pids.main);
    if (typeof pids.setup === 'number') killPid(pids.setup);
  } finally {
    try {
      fs.unlinkSync(PIDS_PATH);
    } catch {
      // already gone -- fine
    }
  }
}
