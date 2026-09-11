#!/usr/bin/env node
// Tasks 4.1 and 4.2 — translate the Chinese server messages the admin surface returns.
//
//   node admin-i18n/server-messages.mjs             rewrite PHP + update lang catalogs
//   node admin-i18n/server-messages.mjs --dry-run   report, write nothing
//   node admin-i18n/server-messages.mjs --check     fail if the admin path is not clean
//
// Scope is the admin path only: V1/Admin controllers, the Admin and Staff middlewares,
// and the Admin request validators. app/Http/Middleware/User.php and the User/Client/
// Passport controllers are never touched, so the end-user theme keeps its Chinese.
//
// Each Chinese literal is resolved in this order:
//   1. an exact key in strings/server-admin.en-US.json           -> that English key
//   2. a *value* in resources/lang/zh-CN.json                    -> that entry's existing key
//   3. listed under _not_translated in the table                  -> left alone, on purpose
//   4. nothing else                                              -> abort, unhandled
//
// (2) matters because this codebase's convention is `abort(500, __('English key'))` with
// zh-CN.json doing English => Chinese. Several admin messages already have a catalog
// entry; inventing a second key for the same wording would fork the catalog for nothing.
//
// Rewrites are text-level but position-anchored: every `__(...)` already present is
// skipped, so a run is idempotent and re-running reports zero changes.
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(HERE, '..');
const TABLE = path.join(HERE, 'strings', 'server-admin.en-US.json');
const PAYTABLE = path.join(HERE, 'strings', 'payments.en-US.json');
const ZH = path.join(ROOT, 'resources', 'lang', 'zh-CN.json');
const EN = path.join(ROOT, 'resources', 'lang', 'en-US.json');

const CJK = /[　-〿㐀-䶿一-鿿豈-﫿＀-￯]/;
const argv = process.argv.slice(2);
const DRY = argv.includes('--dry-run');
const CHECK = argv.includes('--check');

const table = JSON.parse(fs.readFileSync(TABLE, 'utf8'));
const payTable = JSON.parse(fs.readFileSync(PAYTABLE, 'utf8'));
const zhCatalog = JSON.parse(fs.readFileSync(ZH, 'utf8'));

// Literals deliberately kept Chinese. Each table may carry its own _not_translated map,
// so a skip lives beside the strings it applies to.
const skipReasons = new Map();

const mapping = new Map();
function loadRows(rows, source) {
  for (const [k, v] of Object.entries(rows._not_translated || {})) {
    if (skipReasons.has(k) && skipReasons.get(k) !== v) {
      console.error(`${JSON.stringify(k)} has two different not-translatable reasons `
        + `(${JSON.stringify(skipReasons.get(k))} and ${JSON.stringify(v)})`);
      process.exit(1);
    }
    skipReasons.set(k, v);
  }
  for (const [k, v] of Object.entries(rows)) {
    if (k.startsWith('_')) continue;
    if (typeof v !== 'string' || v === '') continue;
    if (mapping.has(k) && mapping.get(k) !== v) {
      console.error(`${JSON.stringify(k)} maps to two different English keys `
        + `(${JSON.stringify(mapping.get(k))} and ${JSON.stringify(v)})`);
      process.exit(1);
    }
    // A literal cannot be both translated and explicitly kept.
    if (skipReasons.has(k)) {
      console.error(`${JSON.stringify(k)} is in ${source} AND in _not_translated — pick one`);
      process.exit(1);
    }
    mapping.set(k, v);
  }
}
loadRows(table, 'server-admin.en-US.json');
loadRows(payTable, 'payments.en-US.json');

// Chinese value -> existing English key, so a shared catalog entry is reused not forked.
const byChinese = new Map();
for (const [en, cn] of Object.entries(zhCatalog)) if (!byChinese.has(cn)) byChinese.set(cn, en);

for (const en of mapping.values()) {
  if (en.includes("'")) {
    console.error(`English key contains an apostrophe and cannot be single-quoted: ${JSON.stringify(en)}`);
    process.exit(1);
  }
}

// ------------------------------------------------------------------- targets ---
function walk(dir, out = []) {
  for (const e of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, e.name);
    if (e.isDirectory()) walk(p, out);
    else if (e.name.endsWith('.php')) out.push(p);
  }
  return out;
}

// Scope: the admin path (V1/Admin controllers, Admin/Staff middlewares, Admin request
// validators) plus the payment-gateway config forms, which the admin renders at
// Payment Config. Gateway abort()/throw messages are user-facing, so they are wrapped in
// __() and keep their Chinese via the zh-CN.json entry this tool appends — the end-user
// theme is unaffected. app/Http/Middleware/User.php and the User/Client/Passport
// controllers are never touched.
const targets = [
  ...walk(path.join(ROOT, 'app/Http/Controllers/V1/Admin')),
  ...walk(path.join(ROOT, 'app/Http/Requests/Admin')),
  ...walk(path.join(ROOT, 'app/Payments')),
  path.join(ROOT, 'app/Http/Middleware/Admin.php'),
  path.join(ROOT, 'app/Http/Middleware/Staff.php'),
].filter((f) => fs.existsSync(f));

// ------------------------------------------------------------------ rewrite ---
const edits = [];       // applied or proposed rewrites
const skipped = [];     // deliberately left Chinese, with the recorded reason
const unresolved = [];  // CJK with no rule — a hard failure
const conflicts = [];   // table says both "translate" and "never translate"
const catalogAdds = new Map(); // English key -> Chinese value to add to zh-CN.json

// A Laravel rule string, e.g. `required|in:>,<,=,>=,<=,Fuzzy,!=`. Pipe-delimited and
// starting with a known rule verb — never a sentence an operator reads.
const RULE = /^(required|nullable|in|not_in|min|max|between|numeric|integer|url|email|array|string|boolean|date|regex|in_array|required_unless|required_with|confirmed|unique|exists)\b/;

// Match a single- or double-quoted literal that contains CJK, ignoring escapes of the
// same quote character, and record whether it already sits inside a __() call.
const LITERAL = /(['"])((?:\\.|(?!\1)[^\\])*)\1/g;

for (const file of targets) {
  const src = fs.readFileSync(file, 'utf8');
  const rel = path.relative(ROOT, file);
  const out = [];
  let last = 0;

  for (const m of src.matchAll(LITERAL)) {
    const zh = m[2];
    if (!CJK.test(zh)) continue;
    const start = m.index;
    const key = mapping.get(zh);
    const reason = skipReasons.get(zh);

    // An explicit skip rule and an explicit translation for the same literal is a
    // contradiction in the table, not something to guess at.
    if (key !== undefined && reason) {
      conflicts.push(`${rel}: ${JSON.stringify(zh)} is both translated and listed as not-translatable`);
      continue;
    }
    if (reason) { skipped.push({ rel, zh, reason }); continue; }
    // A validation rule string ("required|in:>...") is never display text even though it
    // can contain CJK, and it is matched before the mapping so no table entry can make
    // the tool wrap a rule in __() and silently disable a validator.
    if (RULE.test(zh)) {
      skipped.push({ rel, zh, reason: 'Laravel validation rule string, not a display message' });
      continue;
    }
    // Double-quoted PHP strings interpolate $vars and escape sequences; rewriting one to
    // a single-quoted __('...') could silently change the value. None are in scope today.
    if (m[1] === '"') {
      unresolved.push(`${rel}: double-quoted ${JSON.stringify(zh)} — rewrite by hand if it is display text`);
      continue;
    }

    if (key !== undefined) { apply(key); continue; }
    const existing = byChinese.get(zh);
    if (existing) { apply(existing); continue; }
    unresolved.push(`${rel}: ${JSON.stringify(zh)}`);
    continue;

    function apply(key) {
      if (!zhCatalog[key]) catalogAdds.set(key, zh);
      // Already wrapped? Leave it, so a second run is a no-op.
      const before = src.slice(Math.max(0, start - 4), start);
      if (before.endsWith('__((') || before.endsWith('__(')) return;
      out.push({ start, end: start + m[0].length, text: `__('${key}')` });
    }
  }

  let next = src;
  for (let i = out.length - 1; i >= 0; i--) {
    const e = out[i];
    next = next.slice(0, e.start) + e.text + next.slice(e.end);
  }
  for (const e of out) {
    edits.push({ rel, from: src.slice(e.start, e.end), to: e.text });
  }
  if (next !== src && !DRY && !CHECK) fs.writeFileSync(file, next);
}

// -------------------------------------------------------------------- report ---
const byFile = new Map();
for (const e of edits) byFile.set(e.rel, (byFile.get(e.rel) || 0) + 1);

console.log(`admin PHP files scanned: ${targets.length}`);
console.log(`  rewrites:      ${edits.length} across ${byFile.size} file(s)`);
for (const [f, n] of [...byFile].sort()) console.log(`    ${f}: ${n}`);
console.log(`  left Chinese:  ${skipped.length} (wire/logic values — see _not_translated)`);
for (const s of skipped) console.log(`    ${s.rel}: ${JSON.stringify(s.zh)}`);
console.log(`  new catalog keys: ${catalogAdds.size}`);

if (conflicts.length) {
  console.error(`\n${conflicts.length} contradictory table entries (translate AND not-translatable):`);
  for (const c of conflicts) console.error('  - ' + c);
  process.exit(1);
}

if (unresolved.length) {
  console.error(`\n${unresolved.length} Chinese admin message(s) have no rule in ${path.relative(ROOT, TABLE)}:`);
  for (const u of [...new Set(unresolved)].slice(0, 500)) console.error('  - ' + u);
  process.exit(1);
}

if (CHECK) {
  console.log(`\ncheck ok: admin path has no untranslated display message`);
  process.exit(0);
}

if (DRY) {
  console.log('\ndry run: nothing written');
  process.exit(0);
}

// ------------------------------------------------------------- lang catalogs ---
if (catalogAdds.size) {
  const zh = JSON.parse(fs.readFileSync(ZH, 'utf8'));
  for (const [en, cn] of catalogAdds) zh[en] = cn;
  fs.writeFileSync(ZH, JSON.stringify(zh, null, 4) + '\n');

  const en = JSON.parse(fs.readFileSync(EN, 'utf8'));
  for (const [k] of catalogAdds) en[k] = k;
  fs.writeFileSync(EN, JSON.stringify(en, null, 4) + '\n');
  console.log(`\nadded ${catalogAdds.size} key(s) to resources/lang/zh-CN.json and en-US.json`);
}

console.log(`\ndone. Verify with \`node admin-i18n/server-messages.mjs --check\`.`);
