#!/usr/bin/env node
// Classify Chinese literals that occur in BOTH the admin bundles and the backend.
//
//   node admin-i18n/lib/wire-check.mjs            report, grouped by risk
//   node admin-i18n/lib/wire-check.mjs --strict   exit 1 if any UNRESOLVED coupling remains
//
// Two literals sharing the same text on both sides is not by itself a problem, so this
// tool separates them into the only distinction that matters:
//
//   COUPLED — the backend compares or validates against the literal (`=== 'x'`, or an
//     `in:...` rule), and the bundle emits the same text. Translating one side alone
//     breaks the feature while the UI still looks correct, so the two sides must change
//     together. `模糊` -> `Fuzzy` is the worked example: the bundle sent it as a filter
//     condition, Admin/UserFetch + Admin/OrderFetch validated it, and both controllers
//     mapped it to SQL `like`.
//   INDEPENDENT — the backend merely produces or echoes the text (a CSV cell, a label),
//     and the bundle renders it somewhere unrelated. Translating each side on its own is
//     safe, and the identical text is a coincidence worth noting rather than fixing.
//
// Every coupling is cross-checked against admin-i18n/strings/server-admin.en-US.json:
// a COUPLED literal listed under _not_translated, or already renamed on both sides, is
// RESOLVED. Anything coupled and unaccounted for is reported UNRESOLVED, which is what
// --strict fails on.
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { scanAll, hasCJK } from './scan.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(HERE, '..', '..');
const SERVER_TABLE = path.join(ROOT, 'admin-i18n', 'strings', 'server-admin.en-US.json');

function walk(dir, out = []) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) {
      if (p.includes('/vendor') || p.includes('/node_modules')) continue;
      walk(p, out);
    } else if (p.endsWith('.php')) out.push(p);
  }
  return out;
}

const bundleLiterals = new Map(); // zh -> Set("file@module")
for (const it of scanAll(ROOT)) {
  if (!bundleLiterals.has(it.zh)) bundleLiterals.set(it.zh, new Set());
  bundleLiterals.get(it.zh).add(`${it.file}@${it.module}`);
}

// A line that compares or validates against a literal. Covers `=== 'x'`, `== 'x'`,
// `in_array('x', …)` and the `in:...,x,...` rule form.
const COUPLED_LINE = /(===|!==|==)\s*'|'\s*(===|!==|==)|in_array\(|\bin:[^']*\|/;

const coupled = new Map();   // zh -> [site]
const independent = new Map(); // zh -> [site]
for (const file of [...walk(path.join(ROOT, 'app')), path.join(ROOT, 'routes/web.php')]) {
  if (!fs.existsSync(file) || fs.statSync(file).isDirectory()) continue;
  const src = fs.readFileSync(file, 'utf8');
  src.split('\n').forEach((line, i) => {
    if (!hasCJK(line)) return;
    for (const m of line.matchAll(/'([^'\n]*)'|"([^"\n]*)"/g)) {
      const v = m[1] ?? m[2];
      if (!hasCJK(v) || !bundleLiterals.has(v)) continue;
      const site = `${path.relative(ROOT, file)}:${i + 1}`;
      const bucket = COUPLED_LINE.test(line) ? coupled : independent;
      if (!bucket.has(v)) bucket.set(v, new Set());
      bucket.get(v).add(site);
    }
  });
}

const table = fs.existsSync(SERVER_TABLE)
  ? JSON.parse(fs.readFileSync(SERVER_TABLE, 'utf8')) : {};
const skips = new Map(Object.entries(table._not_translated || {}));

const coupledZh = [...coupled.keys()].sort();
const indepZh = [...independent.keys()].sort();
console.log(`COUPLED (backend compares/validates the literal the bundle emits): ${coupledZh.length}`);
const unresolved = [];
for (const zh of coupledZh) {
  const mods = [...bundleLiterals.get(zh)].slice(0, 2).join(', ');
  const sites = [...coupled.get(zh)].join(', ');
  const status = skips.has(zh)
    ? (table[zh] !== undefined ? 'UNRESOLVED — listed as both translated and kept' : 'RESOLVED — kept on both sides by design')
    : 'UNRESOLVED — no rule in strings/server-admin.en-US.json';
  console.log(`  ${JSON.stringify(zh)}  [${status}]`);
  console.log(`      bundle: ${mods}${bundleLiterals.get(zh).size > 2 ? ' …' : ''}`);
  console.log(`      backend: ${sites}`);
  if (status.startsWith('UNRESOLVED')) unresolved.push(zh);
}
console.log(`\nINDEPENDENT (identical text, no comparison — safe to translate per side): ${indepZh.length}`);
for (const zh of indepZh) {
  const mods = [...bundleLiterals.get(zh)].slice(0, 2).join(', ');
  const sites = [...independent.get(zh)].slice(0, 2).join(', ');
  console.log(`  ${JSON.stringify(zh)}  bundle: ${mods}  backend: ${sites}`);
}

if (process.argv.includes('--strict')) {
  if (unresolved.length) {
    console.error(`\nwire-check --strict: ${unresolved.length} unresolved coupling(s)`);
    process.exit(1);
  }
  console.log('\nwire-check --strict ok: every coupling between bundle and backend is accounted for');
}
