#!/usr/bin/env node
// Tasks 5.1 and 5.2 — apply admin-i18n/strings/admin-ui.en-US.json to the
// compiled admin bundles.
//
//   node admin-i18n/translate.mjs             verify, then publish
//   node admin-i18n/translate.mjs --dry-run   report changes, write nothing
//   node admin-i18n/translate.mjs --stage     write admin-i18n/build/ and stop
//   node admin-i18n/translate.mjs --no-verify skip the runtime assertions
//
// The pipeline is idempotent by construction: it always scans whatever is currently
// in public/assets/admin/ and rewrites only the CJK literals it finds there. Running
// it twice is a no-op the second time, so a double-apply cannot restore Chinese text
// or stack edits. admin-i18n/orig/ keeps a one-time pristine backup for recovery.
//
// Safety properties, enforced rather than assumed:
//   * Rewrites happen only at acorn token spans, so regex sources, identifiers and
//     template syntax are structurally out of reach.
//   * Literals whose text is also a value the backend compares (the `模糊` filter
//     condition) are allowlisted as wire values and copied through — translating one
//     side alone breaks the feature. See admin-ui.wire.json and lib/wire-check.mjs.
//   * Every staged bundle is re-parsed; an unparseable result aborts before anything
//     is published.
//   * Untranslated or CJK-still-present literals abort the run instead of shipping a
//     half-translated UI.
//   * The staged output is asserted against a headless DOM (verify.mjs) before publish.
import fs from 'fs';
import path from 'path';
import { execFileSync } from 'child_process';
import { fileURLToPath } from 'url';
import { scanFile, encodeLiteral, hasCJK, BUNDLE_DIR, BUNDLE_FILES } from './lib/scan.mjs';
import { TBD, KEEP, EMPTY } from './lib/constants.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(HERE, '..');
const STRINGS = path.join(HERE, 'strings', 'admin-ui.en-US.json');
const STAGE = path.join(HERE, 'build');
const ORIG = path.join(HERE, 'orig');

const argv = process.argv.slice(2);
const DRY = argv.includes('--dry-run');
const STAGE_ONLY = argv.includes('--stage');
const NO_VERIFY = argv.includes('--no-verify');

if (!fs.existsSync(STRINGS)) {
  console.error('missing strings/admin-ui.en-US.json — run `node admin-i18n/extract.mjs` first');
  process.exit(1);
}
const inventory = JSON.parse(fs.readFileSync(STRINGS, 'utf8'));

function fail(msg, lines) {
  console.error('\n' + msg);
  if (lines) for (const l of [...new Set(lines)].slice(0, 25)) console.error('  - ' + l);
  process.exit(1);
}

// ------------------------------------------------------------------ rewrite --
const plan = []
  , blocking = [];

for (const file of BUNDLE_FILES) {
  const srcPath = path.join(ROOT, BUNDLE_DIR, file);
  if (!fs.existsSync(srcPath)) fail(`missing bundle: ${path.relative(ROOT, srcPath)}`);
  const src = fs.readFileSync(srcPath, 'utf8');
  const literals = scanFile(srcPath); // also asserts the bundle parses
  const edits = [];

  for (const lit of literals) {
    const modKey = `${file}@${lit.module}`;
    const table = inventory[modKey];
    const preview = JSON.stringify(lit.zh.length > 40 ? lit.zh.slice(0, 40) + '…' : lit.zh);
    if (!table) {
      blocking.push(`${modKey}: no inventory entry for ${preview} — run extract.mjs`);
      continue;
    }
    const en = table[lit.zh];
    if (en === KEEP) continue; // allowlisted: copied through untouched
    if (en === undefined || en === TBD) {
      blocking.push(`${modKey}: untranslated ${preview}`);
      continue;
    }
    if (hasCJK(en)) {
      blocking.push(`${modKey}: translation still contains CJK: ${JSON.stringify(en.slice(0, 40))}`);
      continue;
    }
    const value = en === EMPTY ? '' : en;
    if (value !== lit.zh) edits.push({ start: lit.offset, end: lit.end, text: encodeLiteral(value) });
  }

  if (edits.length > 1) {
    // Spans from a single token walk are disjoint and ordered; sort defensively so
    // reverse application can never overlap.
    edits.sort((a, b) => a.start - b.start);
    for (let i = 1; i < edits.length; i++) {
      if (edits[i].start < edits[i - 1].end) {
        fail(`overlapping token spans in ${file} — the scanner and the bundle disagree`);
      }
    }
  }

  let out = src;
  for (let i = edits.length - 1; i >= 0; i--) {
    const e = edits[i];
    out = out.slice(0, e.start) + e.text + out.slice(e.end);
  }
  plan.push({ file, srcPath, src, out, edits: edits.length, literals: literals.length });
  console.log(`${file}: ${literals.length} CJK literal(s), ${edits.length} rewrite(s)`);
}

if (blocking.length) fail(`refusing to write: ${blocking.length} unresolved literal(s)`, blocking);

if (DRY) {
  console.log('\ndry run: nothing written');
  process.exit(0);
}

// ------------------------------------------------------------------- stage ---
fs.rmSync(STAGE, { recursive: true, force: true });
fs.mkdirSync(STAGE, { recursive: true });
for (const p of plan) fs.writeFileSync(path.join(STAGE, p.file), p.out);

// ------------------------------------------------------- assert the staged ---
// Re-scan each staged bundle: it must parse, and no literal may remain that the
// inventory says should have been translated. This is what proves 3.1's "no
// hard-coded Chinese display text" claim instead of asserting it.
for (const p of plan) {
  const staged = path.join(STAGE, p.file);
  let after;
  try {
    after = scanFile(staged);
  } catch (err) {
    fail(`staged ${p.file} does not parse as JavaScript:\n  ${err.message}`);
  }
  // Only allowlisted literals may survive. Anything else still holding CJK means the
  // rewrite missed it, which is a hard failure rather than a warning.
  const residue = [];
  for (const lit of after) {
    const table = inventory[`${p.file}@${lit.module}`] || {};
    if (table[lit.zh] === KEEP) continue;
    residue.push(`${p.file}@${lit.module}: ${JSON.stringify(lit.zh.slice(0, 40))}`
      + (lit.zh in table ? ` (expected ${JSON.stringify(String(table[lit.zh]).slice(0, 40))})` : ' (no inventory entry)'));
  }
  if (residue.length) fail(`staged ${p.file} still holds ${residue.length} translatable literal(s)`, residue);
  console.log(`staged ${p.file}: parses, ${p.literals.length} -> ${after.length} CJK literal(s)`);
}

if (!NO_VERIFY) {
  try {
    execFileSync(process.execPath, [path.join(HERE, 'verify.mjs'), STAGE], { stdio: 'inherit' });
  } catch (err) {
    fail('runtime verification failed — nothing published');
  }
}

if (STAGE_ONLY) {
  console.log(`\nstaged under ${path.relative(ROOT, STAGE)}/ (not published)`);
  process.exit(0);
}

// ---------------------------------------------------- one-time pristine copy ---
fs.mkdirSync(ORIG, { recursive: true });
for (const p of plan) {
  const archive = path.join(ORIG, p.file);
  if (fs.existsSync(archive)) continue;
  fs.copyFileSync(p.srcPath, archive);
  console.log(`backed up untranslated original: ${path.relative(ROOT, archive)}`);
}

// ----------------------------------------------------------------- publish ---
for (const p of plan) {
  fs.writeFileSync(path.join(ROOT, BUNDLE_DIR, p.file), p.out);
}
console.log('\npublished. Run `node admin-i18n/guard.mjs` to assert the tree is clean.');
