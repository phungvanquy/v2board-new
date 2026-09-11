#!/usr/bin/env node
// Task 4.2 — finish what server-messages.mjs deliberately leaves undone.
//
//   node admin-i18n/payments-concat.mjs             rewrite whole expressions
//   node admin-i18n/payments-concat.mjs --dry-run   report, write nothing
//   node admin-i18n/payments-concat.mjs --check     fail if any of them is still Chinese
//
// server-messages.mjs rewrites a literal only when the literal IS the message, because
// wrapping one operand of `'Paytaro 响应异常（HTTP ' . $status . '）'` would produce an
// English fragment glued to a Chinese one. Those expressions are recorded under
// _not_translated in strings/payments.en-US.json so that tool skips them, and are handled
// here as whole expressions instead.
//
// Each rewrite is an exact, whitespace-sensitive replacement of a complete expression with
// an equivalent one using __() and sprintf(). Nothing is inferred: every planned rewrite
// is asserted to be either pending (its `from` matches exactly once) or already applied
// (its `to` matches exactly once). Any other state means the source drifted from this
// table, and the run fails rather than silently skipping a string.
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(HERE, '..');
const TABLE = path.join(HERE, 'strings', 'payments.en-US.json');
const argv = process.argv.slice(2);
const DRY = argv.includes('--dry-run');
const CHECK = argv.includes('--check');

const REWRITES = [
  {
    file: 'app/Payments/EPayQrcode.php',
    from: `abort(500, 'EPay 配置不完整：' . $key);`,
    to: `abort(500, sprintf(__('The EPay configuration is incomplete: %s'), $key));`,
    zh: 'EPay 配置不完整：%s',
    why: 'config-missing message shown when a required gateway field was left blank',
  },
  {
    file: 'app/Payments/PaytaroQR.php',
    from: `abort(500, 'Paytaro 响应异常（HTTP ' . $status . '）');`,
    to: `abort(500, sprintf(__('Paytaro responded abnormally (HTTP %s)'), $status));`,
    zh: 'Paytaro 响应异常（HTTP %s）',
    why: 'non-JSON response from the gateway, with the HTTP status interpolated',
  },
  {
    file: 'app/Payments/PaytaroQR.php',
    from: `abort(500, 'Paytaro：' . (string) ($data['error'] ?? $data['message'] ?? ('HTTP ' . $status)));`,
    to: `abort(500, sprintf(__('Paytaro: %s'), (string) ($data['error'] ?? $data['message'] ?? ('HTTP ' . $status))));`,
    zh: 'Paytaro：%s',
    why: 'gateway error text passed through under a Chinese prefix',
  },
];

// ------------------------------------------------------------------ preflight ---
// Each rewrite is either PENDING (its `from` occurs exactly once) or DONE (its `to`
// occurs exactly once and `from` is gone). Anything else means the source drifted from
// this table, which must fail loudly rather than skip a string.
const table = JSON.parse(fs.readFileSync(TABLE, 'utf8'));
const problems = []
  , pending = [];

for (const r of REWRITES) {
  const file = path.join(ROOT, r.file);
  if (!fs.existsSync(file)) { problems.push(`${r.file}: file missing`); continue; }
  const src = fs.readFileSync(file, 'utf8');
  const fromN = src.split(r.from).length - 1;
  const toN = src.split(r.to).length - 1;
  if (fromN === 1 && toN === 0) pending.push(r);
  else if (fromN === 0 && toN === 1) continue; // already applied
  else problems.push(`${r.file}: from=${fromN} to=${toN} for ${JSON.stringify(r.from)} — ${r.why}`);
  // The English must not need catalog entries that clash with existing keys.
  for (const m of r.to.matchAll(/__\('([^']*)'\)/g)) {
    if (table._not_translated && m[1] in table._not_translated) {
      problems.push(`${r.file}: English key ${JSON.stringify(m[1])} is also a skip reason`);
    }
  }
}

if (problems.length) {
  console.error(`${REWRITES.length} expression rewrite(s) planned; ${problems.length} problem(s):\n`);
  for (const p of problems) console.error('  - ' + p);
  console.error('\nUpdate the REWRITES table to match the current source.');
  process.exit(1);
}

if (CHECK) {
  if (pending.length) {
    console.error(`${pending.length} concatenation rewrite(s) still pending — run without --check:`);
    for (const p of pending) console.error(`  ${p.file}: ${p.from}`);
    process.exit(1);
  }
  console.log(`concat check ok: all ${REWRITES.length} concatenation rewrite(s) applied`);
  process.exit(0);
}

// --------------------------------------------------------------------- rewrite ---
const en = (r) => r.to.match(/__\('([^']*)'\)/)[1];

if (DRY) {
  for (const r of pending) {
    console.log(`${r.file}: would rewrite ${r.why}`);
    console.log(`    - ${r.from}`);
    console.log(`    + ${r.to}`);
  }
  console.log(`    and add ${pending.length} key(s) to resources/lang/`);
  console.log('\ndry run: nothing written');
  process.exit(0);
}

for (const r of pending) {
  const file = path.join(ROOT, r.file);
  const src = fs.readFileSync(file, 'utf8');
  fs.writeFileSync(file, src.replace(r.from, r.to));
  console.log(`${r.file}: rewrote ${r.why}`);
  console.log(`    - ${r.from}`);
  console.log(`    + ${r.to}`);
}

// Catalog entries for the new keys. server-messages.mjs cannot add them, because these
// literals are in its _not_translated set — this tool owns them. config/app.php defaults
// to zh-CN, so an unregistered key would render the English in a Chinese checkout and the
// English-only key in an English one; registering the Chinese keeps both modes correct.
{
  const zhPath = path.join(ROOT, 'resources', 'lang', 'zh-CN.json');
  const enPath = path.join(ROOT, 'resources', 'lang', 'en-US.json');
  const zh = JSON.parse(fs.readFileSync(zhPath, 'utf8'));
  const enCat = JSON.parse(fs.readFileSync(enPath, 'utf8'));
  let added = 0;
  for (const r of REWRITES) { // idempotent: skips keys already registered correctly
    const key = en(r);
    if (zh[key] === r.zh) continue;
    if (key in zh && zh[key] !== r.zh) {
      console.error(`refusing to overwrite existing zh-CN entry for ${JSON.stringify(key)}`);
      process.exit(1);
    }
    zh[key] = r.zh;
    enCat[key] = key;
    added++;
  }
  if (added) {
    fs.writeFileSync(zhPath, JSON.stringify(zh, null, 4) + '\n');
    fs.writeFileSync(enPath, JSON.stringify(enCat, null, 4) + '\n');
    console.log(`added ${added} key(s) to resources/lang/`);
  }
}

console.log('\napplied the expression rewrites.');
