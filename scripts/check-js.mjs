#!/usr/bin/env node
/**
 * Syntax-check all first-party JavaScript (sources and built files).
 * The bundled Chart.js vendor bundle is skipped — it is third-party.
 *
 * Usage: node scripts/check-js.mjs
 */
import { readFileSync, readdirSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const ROOT = dirname(dirname(fileURLToPath(import.meta.url)));
const JS_DIR = join(ROOT, 'assets', 'js');

const targets = [];
for (const f of readdirSync(JS_DIR)) {
    if (!f.endsWith('.js')) continue;
    if (f.includes('.min.js') && f.endsWith('.min.js')) { targets.push(f); continue; }
    targets.push(f);
}
// Vendor bundle is excluded explicitly.
const files = targets.filter((f) => !f.startsWith('vendor'));

let failed = 0;
for (const f of files) {
    const p = join(JS_DIR, f);
    try {
        // `node --check` validates syntax without executing the file.
        execFileSync(process.execPath, ['--check', p], { stdio: 'pipe' });
        console.log(`OK   assets/js/${f}`);
    } catch (e) {
        failed++;
        console.error(`FAIL assets/js/${f}\n${e.stderr}`);
    }
}

console.log(failed ? `\nJS CHECK: FAIL (${failed})` : `\nJS CHECK: PASS (${files.length} files)`);
process.exit(failed ? 1 : 0);
