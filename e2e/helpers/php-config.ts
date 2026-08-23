/**
 * Minimal writer for the `<?php return [...];` config files the app reads
 * via `COFFEE_CONFIG_PATH` (see src/Config.php::path()). We hand-roll the PHP
 * literal instead of shelling out to PHP so global setup stays pure Node.
 */

import * as fs from 'node:fs';

type PhpValue = string | number | boolean | PhpValue[];

function phpString(value: string): string {
  // Single-quoted PHP strings only need `\` and `'` escaped; literal
  // newlines (as in a PEM block) are fine as-is.
  const escaped = value.replaceAll('\\', String.raw`\\`).replaceAll("'", String.raw`\'`);
  return `'${escaped}'`;
}

function phpValue(value: PhpValue): string {
  if (typeof value === 'string') {
    return phpString(value);
  }
  if (typeof value === 'number') {
    // PHP parses both int and float literals from the same text (e.g. `150`
    // or `1.5`), so no int/float branching is needed here.
    return String(value);
  }
  if (typeof value === 'boolean') {
    return value ? 'true' : 'false';
  }
  if (Array.isArray(value)) {
    return `[${value.map(phpValue).join(', ')}]`;
  }
  throw new Error(`Unsupported PHP config value: ${String(value)}`);
}

/** Writes a `<?php return [...];` file readable by src/Config.php. */
export function writePhpConfig(path: string, config: Record<string, PhpValue>): void {
  const lines: string[] = ['<?php', '', 'return ['];
  for (const [key, value] of Object.entries(config)) {
    lines.push(`    ${phpString(key)} => ${phpValue(value)},`);
  }
  lines.push('];', '');
  fs.writeFileSync(path, lines.join('\n'));
}
