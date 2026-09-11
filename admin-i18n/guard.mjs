#!/usr/bin/env node
// Tasks 5.1, 5.2 and 6.2 — verify staged admin bundles before publish and as a CI gate.
//
//   node admin-i18n/guard.mjs                         re-scan public/assets/admin/
//   node admin-i18n/guard.mjs admin-i18n/build        re-scan the staged dir
//
// A plain `grep '[一-鿿]'` over the bundles cannot serve as a gate: 8 literals are
// deliberately kept because they encode non-display plumbing (regjsparser regex fragments,
// core-js's U+3000 whitespace table, the `he` entity table, zrender's CJK glyph-width
// probes) and are listed in admin-ui.allowlist.json. This tool re-scans through the same
// AST path and applies that allowlist, then reports any translatable residue — which catches
// both untranslated display text and a stale table that no longer matches a rebuilt bundle.
//
// The same tool is invoked by translate.mjs (after staging, before publish) and by CI.
// The reported count is the authoritative pass/fail signal; the spec's "no Chinese text
// remains in the admin UI" is this script exiting 0 against both trees.
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { scanFile, hasCJK } from './lib/scan.mjs';
import { KEEP } from './lib/constants.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const ROOT = path.resolve(HERE, '..');
const TARGET = process.argv[2] ? path.resolve(ROOT, process.argv[2]) : path.join(ROOT, 'public/assets/admin');
const FILES = ['umi.js', 'vendors.async.js', 'components.async.js'];

const STRINGS = path.join(HERE, 'strings', 'admin-ui.en-US.json');
const ALLOW = path.join(HERE, 'strings', 'admin-ui.allowlist.json');

const inventory = fs.existsSync(STRINGS) ? JSON.parse(fs.readFileSync(STRINGS, 'utf8')) : {};
const allow = JSON.parse(fs.readFileSync(ALLOW, 'utf8'));
const allowlisted = new Set(allow.modules.map((m) => `${m.file}@${m.module}`));

let residues = 0
  , keep = 0;

for (const file of FILES) {
  const fp = path.join(TARGET, file);
  if (!fs.existsSync(fp)) {
    console.error(`missing ${path.relative(ROOT, fp)}`);
    process.exit(1);
  }
  let lits;
  try {
    lits = scanFile(fp);
  } catch (err) {
    console.error(`${file}: ${err.message}`);
    process.exit(1);
  }
  for (const lit of lits) {
    const key = `${file}@${lit.module}`;
    if (allowlisted.has(key)) { keep++; continue; }
    const row = (inventory[key] || {})[lit.zh];
    if (row === KEEP) { keep++; continue; }
    // No rule for this literal at all — either the bundle was swapped without re-extracting,
    // or the staged output lost a translation. Report the residue verbatim.
    residues++;
    console.error(`${path.relative(ROOT, fp)}@${lit.module}: `
      + `${JSON.stringify(lit.zh)}`
      + (row !== undefined ? ` (${row === '__TBD__' ? 'TBD' : JSON.stringify(row).slice(0, 24)})` : ' (no inventory entry)'));
  }
  console.log(`${file}: ${lits.length} CJK literal(s), ${keep ? `allowlisted ${keep},` : ''} translatable residue ${residues}`);
}

if (residues) {
  console.error(`\nguard: ${residues} translatable CJK literal(s) remain in ${path.relative(ROOT, TARGET)}/ — fail`);
  process.exit(1);
}
console.log(`\nguard: clean — no translatable CJK remains in ${path.relative(ROOT, TARGET)}/`
  + ` (${keep} kept by allowlist)`);
