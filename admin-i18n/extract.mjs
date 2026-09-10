#!/usr/bin/env node
// Task 2.1 — build the admin dashboard UI string inventory from the compiled bundles.
//
//   node admin-i18n/extract.mjs             scan + merge, write inventory and report
//   node admin-i18n/extract.mjs --check     fail if the inventory is stale or incomplete
//   node admin-i18n/extract.mjs --seed      create/extend strings/parts/default.json
//   node admin-i18n/extract.mjs --conflicts list strings used by more than one module
//
// The scan is the authority on what exists: it walks the bundles with acorn, so only
// real string literals are collected — never regex sources or template syntax.
//
// English is authored in strings/parts/, keyed by the exact Chinese source. Two tiers,
// first non-blank match wins:
//   <file>.<module>.json   per-module override, for when the same Chinese word needs
//                          different English (关闭 is "Close" on a chart toolbar but
//                          "Closed" in a ticket status map; moment's 日 is a format
//                          token "D" while the UI word is "Day").
//   default.json           global table where most strings live, so a shared string is
//                          written once instead of per module.
// Run `extract.mjs --conflicts` to list strings used by more than one module; those are
// the ones where a single global value could be wrong and an override is warranted.
//
// Output is strings/admin-ui.en-US.json, shaped { "<file>@<module>": { zh: en } },
// which translate.mjs applies to the bundles and guard.mjs verifies.
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { scanAll, hasCJK } from './lib/scan.mjs';
import { TBD, KEEP, EMPTY } from './lib/constants.mjs';
export { TBD, KEEP, EMPTY };

export const DEFAULT_TOKEN = 'default';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(HERE, '..');
const PARTS = path.join(HERE, 'strings', 'parts');
const STRINGS = path.join(HERE, 'strings', 'admin-ui.en-US.json');
const ALLOW = path.join(HERE, 'strings', 'admin-ui.allowlist.json');
const REPORT = path.join(HERE, 'report', 'admin-ui.zh-en.md');

export function moduleToken(modKey) {
  const i = modKey.indexOf('@');
  const file = modKey.slice(0, i)
    , module = modKey.slice(i + 1);
  return `${file.replace(/\W+/g, '_')}.${module.replace(/\W+/g, '_')}`;
}

// ------------------------------------------------------------------ inputs ---
function loadAllowlist() {
  return fs.existsSync(ALLOW) ? JSON.parse(fs.readFileSync(ALLOW, 'utf8')) : { modules: [] };
}

function loadParts() {
  const byToken = new Map();
  if (!fs.existsSync(PARTS)) return byToken;
  for (const f of fs.readdirSync(PARTS).filter((n) => n.endsWith('.json')).sort()) {
    byToken.set(f.replace(/\.json$/, ''), JSON.parse(fs.readFileSync(path.join(PARTS, f), 'utf8')));
  }
  return byToken;
}

// An empty value means "not translated yet", so --seed skeletons stay visibly
// incomplete instead of silently applying blank text.
function authoredFor(parts, modKey, zh) {
  for (const token of [moduleToken(modKey), DEFAULT_TOKEN]) {
    const table = parts.get(token);
    if (!table || typeof table[zh] !== 'string') continue;
    if (table[zh] === '') continue; // blank means "not translated yet"
    return table[zh];
  }
  return null;
}

// Nearest-neighbour suggestions, so a mistyped Chinese key is reported as a typo of a
// real bundle string rather than looking like a harmless unused entry.
function editDistance(a, b, max) {
  if (Math.abs(a.length - b.length) > max) return max + 1;
  let prev = Array.from({ length: b.length + 1 }, (_, i) => i);
  for (let i = 1; i <= a.length; i++) {
    const cur = [i];
    let rowMin = i;
    for (let j = 1; j <= b.length; j++) {
      const cost = a[i - 1] === b[j - 1] ? 0 : 1;
      cur[j] = Math.min(prev[j] + 1, cur[j - 1] + 1, prev[j - 1] + cost);
      if (cur[j] < rowMin) rowMin = cur[j];
    }
    if (rowMin > max) return max + 1;
    prev = cur;
  }
  return prev[b.length];
}

const argv = process.argv.slice(2);
const MODE = argv.includes('--check') ? 'check'
  : argv.includes('--seed') ? 'seed'
  : argv.includes('--conflicts') ? 'conflicts'
  : 'write';

// -------------------------------------------------------------------- scan ---
const items = scanAll(ROOT);
if (items.length === 0) {
  console.error('No CJK string literals found. The bundles are missing, already translated, or the scanner is broken.');
  process.exit(1);
}

const allow = loadAllowlist();
const parts = loadParts();
const allowlisted = new Set(allow.modules.map((m) => `${m.file}@${m.module}`));

// Every (module, zh) pair present in the bundles, with occurrence counts.
const pairs = new Map();
for (const it of items) {
  const key = `${it.file}@${it.module}`;
  if (!pairs.has(key)) pairs.set(key, new Map());
  const inner = pairs.get(key);
  inner.set(it.zh, (inner.get(it.zh) || 0) + 1);
}

const inventory = {};
const problems = [];
let nTranslated = 0
  , nPending = 0
  , nKeep = 0;

for (const [modKey, inner] of [...pairs].sort()) {
  const table = {};
  const keepAll = allowlisted.has(modKey);
  for (const [zh, count] of [...inner].sort((a, b) => b[1] - a[1])) {
    if (keepAll) {
      table[zh] = KEEP;
      nKeep++;
      continue;
    }
    const val = authoredFor(parts, modKey, zh);
    if (typeof val === 'string' && (val === EMPTY || !hasCJK(val))) {
      table[zh] = val;
      nTranslated++;
    } else {
      table[zh] = TBD;
      nPending++;
      problems.push(`${modKey}: ${JSON.stringify(zh)} has no English translation`);
    }
  }
  inventory[modKey] = table;
}

// ---------------------------------------------------------------- conflicts --
if (MODE === 'conflicts') {
  const where = new Map();
  for (const [modKey, inner] of pairs) {
    if (allowlisted.has(modKey)) continue;
    for (const zh of inner.keys()) {
      if (!where.has(zh)) where.set(zh, []);
      where.get(zh).push(modKey);
    }
  }
  const multi = [...where].filter(([, ms]) => ms.length > 1)
    .sort((a, b) => b[1].length - a[1].length);
  console.log(`${multi.length} string(s) occur in more than one module. One English value is applied`);
  console.log(`to all of them unless you add a per-module override under strings/parts/:\n`);
  for (const [zh, ms] of multi) {
    console.log(`${JSON.stringify(zh)} -> ${JSON.stringify(inventory[ms[0]][zh])}`);
    console.log(`    ${ms.join(', ')}`);
  }
  process.exit(0);
}

// -------------------------------------------------------------------- seed ---
if (MODE === 'seed') {
  fs.mkdirSync(PARTS, { recursive: true });
  const file = path.join(PARTS, `${DEFAULT_TOKEN}.json`);
  const have = fs.existsSync(file) ? JSON.parse(fs.readFileSync(file, 'utf8')) : {};
  const add = {};
  for (const [modKey, inner] of pairs) {
    if (allowlisted.has(modKey)) continue;
    for (const zh of inner.keys()) {
      if (authoredFor(parts, modKey, zh) || zh in have || zh in add) continue;
      add[zh] = '';
    }
  }
  // Prune keys that no longer occur in any live, non-allowlisted module: they are
  // residue from a rebuilt bundle or a mistyped translation, and leaving them in
  // default.json would misreport progress forever.
  const live = new Set();
  for (const [modKey, inner] of pairs) {
    if (allowlisted.has(modKey)) continue;
    for (const zh of inner.keys()) live.add(zh);
  }
  const dropped = Object.keys(have).filter((zh) => !live.has(zh));
  const next = {};
  for (const [zh, en] of Object.entries(have)) if (live.has(zh)) next[zh] = en;
  Object.assign(next, add);
  // Sorted so the key order matches what `fill` zips values against.
  fs.writeFileSync(file, JSON.stringify(
    Object.fromEntries(Object.keys(next).sort().map((k) => [k, next[k]])), null, 2) + '\n');
  fs.writeFileSync(STRINGS, JSON.stringify(inventory, null, 2) + '\n');
  console.log(`seeded ${Object.keys(add).length} key(s) into ${path.relative(ROOT, file)}`
    + (dropped.length ? `, pruned ${dropped.length} stale key(s)` : ''));
  console.log(`${nTranslated} translated, ${nPending} pending, ${nKeep} allowlisted`);
  process.exit(0);
}

// ---------------------------------------------------------------- orphans ---
// Keys a part file asserts that no live bundle string matches: stale or mistyped.
const liveByModule = new Map();
for (const [modKey, inner] of pairs) {
  if (!allowlisted.has(modKey)) liveByModule.set(modKey, new Set(inner.keys()));
}
const orphans = [];
for (const [token, table] of parts) {
  let scope = null;
  if (token === DEFAULT_TOKEN) {
    scope = new Set();
    for (const set of liveByModule.values()) for (const zh of set) scope.add(zh);
  } else {
    for (const [modKey, set] of liveByModule) if (moduleToken(modKey) === token) scope = set;
  }
  if (!scope) {
    orphans.push({ token, zh: null, suggest: null, note: 'part file matches no known module' });
    continue;
  }
  for (const zh of Object.keys(table)) {
    if (scope.has(zh)) continue;
    let best = null;
    for (const cand of scope) {
      const d = editDistance(zh, cand, 3);
      if (d <= 3 && (!best || d < best.d)) best = { cand, d };
    }
    orphans.push({ token, zh, suggest: best });
  }
}

// ------------------------------------------------------------------- check ---
if (MODE === 'check') {
  const existing = fs.existsSync(STRINGS) ? JSON.parse(fs.readFileSync(STRINGS, 'utf8')) : null;
  const fails = [];
  if (problems.length) fails.push(`${problems.length} untranslated string(s)`);
  if (orphans.length) fails.push(`${orphans.length} authored key(s) match no bundle string`);
  if (!existing || JSON.stringify(existing) !== JSON.stringify(inventory)) {
    fails.push('strings/admin-ui.en-US.json is stale');
  }
  if (fails.length) {
    console.error('inventory check failed:\n  - ' + fails.join('\n  - '));
    for (const p of problems.slice(0, 25)) console.error('    untranslated: ' + p);
    for (const o of orphans.slice(0, 25)) {
      console.error(`    orphan: ${o.token} ${JSON.stringify(o.zh)}`
        + (o.suggest ? ` (nearest live: ${JSON.stringify(o.suggest.cand)}, distance ${o.suggest.d})` : '')
        + (o.note ? ` (${o.note})` : ''));
    }
    process.exit(1);
  }
  console.log(`inventory ok: ${nTranslated} translated, ${nKeep} allowlisted, 0 pending, 0 orphan keys`);
  process.exit(0);
}

// ------------------------------------------------------------------- write ---
fs.writeFileSync(STRINGS, JSON.stringify(inventory, null, 2) + '\n');

let md = '# Admin dashboard string inventory\n\n';
md += 'Generated by `node admin-i18n/extract.mjs` — do not edit.\n\n';
md += 'Author English in `admin-i18n/strings/parts/<file>.<module>.json`, then re-run\n';
md += '`extract.mjs`, `translate.mjs` and `guard.mjs`.\n\n';
md += `- CJK literals: **${items.length}** occurrences across **${pairs.size}** module(s)\n`;
md += `- translated: **${nTranslated}** | allowlisted: **${nKeep}** | pending: **${nPending}**\n\n`;
for (const [modKey, inner] of [...pairs].sort((a, b) => b[1].size - a[1].size)) {
  const al = allow.modules.find((m) => `${m.file}@${m.module}` === modKey);
  md += `## \`${modKey}\` — ${inner.size} string(s)${al ? `\n\n> Allowlisted (not display text): ${al.why}\n` : ''}\n\n`;
  for (const [zh, count] of [...inner].sort((a, b) => b[1] - a[1])) {
    const val = inventory[modKey][zh];
    const flag = val === KEEP ? ' `[keep]`' : val === TBD ? ' `[TBD]`' : '';
    md += `- ${JSON.stringify(zh)} → ${JSON.stringify(val === KEEP ? '(kept)' : val)} _(x${count})_${flag}\n`;
  }
  md += '\n';
}
fs.writeFileSync(REPORT, md);

console.log(`scanned ${items.length} CJK literals in ${pairs.size} module(s)`);
console.log(`translated ${nTranslated} | allowlisted ${nKeep} | pending ${nPending} | orphan keys ${orphans.length}`);
console.log(`inventory: ${path.relative(ROOT, STRINGS)}`);
console.log(`report:    ${path.relative(ROOT, REPORT)}`);
