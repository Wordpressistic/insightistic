#!/usr/bin/env node
/**
 * Deterministic minified-asset builder for Insightistic.
 *
 *   node scripts/build-assets.mjs            rebuild minified assets in place
 *   node scripts/build-assets.mjs --check    fail (exit 1) if committed files are stale
 *   node scripts/build-assets.mjs --size-only  enforce the tracking.min.js size budget
 *
 * Sources of truth:
 *   assets/css/admin.css     -> assets/css/admin.min.css
 *   assets/js/admin.js       -> assets/js/admin.min.js (+ admin.min.js.map, dev only)
 *   assets/js/tracking.js    -> assets/js/tracking.min.js  (< 3 KiB budget)
 *
 * Bundled Chart.js under assets/js/vendor/ is third-party and never rebuilt.
 */
import { minify as terserBuild } from 'terser';
import CleanCSS from 'clean-css';
import { readFileSync, writeFileSync, existsSync } from 'node:fs';
import { argv, exit } from 'node:process';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const ROOT = dirname(dirname(fileURLToPath(import.meta.url)));
const p = (rel) => join(ROOT, rel);
const Minifier = CleanCSS.Minifier || CleanCSS.default || CleanCSS;

const CHECK = argv.includes('--check');
const SIZE_ONLY = argv.includes('--size-only');
const TRACKING_BUDGET_BYTES = 3072; // < 3 KiB, enforced.

async function minifyJs(srcRel, outRel, withMap, extra = {}) {
    const source = readFileSync(p(srcRel), 'utf8');
    const result = await terserBuild(source, {
        compress: { passes: 2, drop_debugger: true },
        mangle: true,
        format: { comments: /copyright|license|insightistic/i },
        sourceMap: withMap ? { filename: outRel.split('/').pop(), url: outRel.split('/').pop() + '.map' } : false,
        ...extra,
    });
    // terser returns { code, map } — only persist the map when requested.
    return { code: result.code, map: withMap ? result.map : null };
}

async function minifyCss(srcRel) {
    const source = readFileSync(p(srcRel), 'utf8');
    return new Promise((resolve, reject) => {
        new Minifier({ level: 2, returnWarnings: false }).minify(source, (err, out) => {
            if (err) reject(new Error(`clean-css failed for ${srcRel}: ${err}`));
            else resolve({ code: out.styles, map: null });
        });
    });
}
async function buildPair(srcRel, outRel, mapRel, minifier, kind) {
    const out = await minifier(srcRel, outRel, Boolean(mapRel));
    if (CHECK) {
        if (!existsSync(p(outRel))) {
            console.error(`STALE: ${outRel} does not exist`);
            return false;
        }
        const current = readFileSync(p(outRel), 'utf8');
        if (current !== out.code) {
            console.error(`STALE: ${outRel} does not match a fresh build from ${srcRel}`);
            return false;
        }
        console.log(`OK: ${outRel} is current`);
        return true;
    }
    writeFileSync(p(outRel), Buffer.from(out.code, 'utf8'));
    if (mapRel && out.map) writeFileSync(p(mapRel), Buffer.from(out.map, 'utf8'));
    console.log(`built: ${outRel}${mapRel ? ' (+map)' : ''} [${kind}]`);
    return true;
}

async function main() {
    const jobs = [
        ['assets/css/admin.css', 'assets/css/admin.min.css', null, minifyCss, 'css'],
        ['assets/js/admin.js', 'assets/js/admin.min.js', 'assets/js/admin.min.js.map', minifyJs, 'js'],
        ['assets/js/tracking.js', 'assets/js/tracking.min.js', null,
            (srcRel, outRel) => minifyJs(srcRel, outRel, false, {
                // The tracker ships under a hard < 3 KiB budget: strip the
                // file header comment and mangle the top-level IIFE.
                compress: { passes: 3, drop_debugger: true },
                toplevel: true,
                format: { comments: false },
            }), 'js'],
    ];

    let ok = true;
    if (!SIZE_ONLY) {
        for (const [src, out, map, minifier, kind] of jobs) {
            ok = (await buildPair(src, out, map, minifier, kind)) && ok;
        }
    }

    // Size budget for the tracker — enforced in every mode.
    const minSize = readFileSync(p('assets/js/tracking.min.js')).length;
    if (minSize > TRACKING_BUDGET_BYTES) {
        console.error(`FAIL: assets/js/tracking.min.js is ${minSize} bytes (budget ${TRACKING_BUDGET_BYTES})`);
        ok = false;
    } else {
        console.log(`size: tracking.min.js ${minSize}/${TRACKING_BUDGET_BYTES} bytes OK`);
    }

    if (!ok) exit(1);
}

main().catch((e) => { console.error(e); exit(1); });

