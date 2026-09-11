#!/usr/bin/env node
// Task 2.2 supplement — verify that report/qa-checklist.md still covers every admin
// route the bundle defines, so a new route cannot silently avoid qa.
//
//   node admin-i18n/check-qa.mjs              check
//   node admin-i18n/check-qa.mjs --coverage   print which checklist entry covers which route
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(HERE, '..');
const QA = path.join(HERE, 'report', 'qa-checklist.md');
const BUNDLE = path.join(ROOT, 'public/assets/admin', 'umi.js');
const ORIG = path.join(HERE, 'orig', 'umi.js');
const src = fs.existsSync(ORIG) ? fs.readFileSync(ORIG, 'utf8') : fs.readFileSync(BUNDLE, 'utf8');
const decoded = src.replace(/\\u([0-9a-f]{4})/g, (_, h) => String.fromCharCode(parseInt(h, 16)));
const bundleRoutes = new Set();
for (const m of decoded.matchAll(/\bpath:\s*"([^"]*)"/g)) bundleRoutes.add(m[1]);

// Routes that are infra, not dashboard surfaces. Keep this list short and require a
// comment per entry, because exempting a real dashboard route would hide translation drift.
const EXCLUDE = new Map(Object.entries({
  '/': ['umbrella wrapper for <Secure> that matches every admin path — tested as /dashboard'],
  '/login': ['staff/user auth flow outside admin scope — handled separately'],
}));

const qa = fs.readFileSync(QA, 'utf8');
const norm = (p) => p.replace(/\/:[^/]+/g, '/:param');
const missing = [];
for (const r of bundleRoutes) {
  if (EXCLUDE.has(r)) continue;
  const n = norm(r);
  // Matches bare (`/dashboard`), prefixed (`/admin/dashboard`), or param forms.
  const re = new RegExp(`\`${n.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\b|\`${r.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\b|/admin${r.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}\\b`);
  if (re.test(qa)) continue;
  missing.push(r);
}

if (process.argv.includes('--coverage')) {
  console.log('Bundle router routes:');
  for (const p of [...bundleRoutes].sort()) {
    const tag = EXCLUDE.has(p) ? `  (excluded: ${EXCLUDE.get(p)})` : (missing.includes(p) ? '  UNCOVERED' : '  covered');
    console.log(`  ${p}${tag}`);
  }
}

if (missing.length) {
  console.error(`\ncheck-qa: ${missing.length} bundle route(s) not cited in ${path.relative(ROOT, QA)}:`);
  for (const r of missing) console.error(`  ${r}`);
  process.exit(1);
}
console.log(`check-qa ok: every admin route in the shipped router is cited in the qa checklist`
  + ` (${bundleRoutes.size} routes, ${EXCLUDE.size} excluded as non-dashboard)`);
