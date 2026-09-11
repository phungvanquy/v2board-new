#!/usr/bin/env node
// Tasks 5.2 and 6.2 — staged-bundle verification before publish.
//
//   node admin-i18n/verify.mjs admin-i18n/build       asserts before translate.mjs publishes
//   node admin-i18n/verify.mjs                         asserts the staged dir if it exists
//
// translate.mjs already guarantees that the staged bundles parse as JavaScript and hold
// no translatable residue. This file layers a second assertion: the staged residues are
// checked by guard.mjs (which applies the allowlist via the same AST path), and the
// inventory is asserted fresh from the parts files by extract.mjs. If either drifts — a
// staged literal missing from the table, or an authored key not matching a live literal —
// the publish aborts. A future headless-DOM harness replaces the body of this file only;
// the surrounding stage/publish flow does not change.
import { spawnSync } from 'child_process';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const STAGE = process.argv[2] ? path.resolve(process.argv[2]) : path.join(HERE, 'build');

if (!fs.existsSync(path.join(STAGE, 'umi.js'))) {
  console.error(`no staged bundles at ${path.relative(path.resolve(HERE, '..'), STAGE)}/`);
  process.exit(1);
}

function run(tool, args) {
  const r = spawnSync(process.execPath, [path.join(HERE, tool), ...args], { encoding: 'utf8' });
  if (r.status !== 0) {
    console.error(`${tool}: ${JSON.stringify(args)}\n${r.stdout || ''}${r.stderr || ''}`.slice(0, 1400));
    return false;
  }
  return true;
}

// 1. The staged tree must be live-tree inventory clean: same 1586 literals, same allowlist,
//    and any residue is a failure. This proves the bundle reflects the table.
let ok = run('guard.mjs', [STAGE]);
// 2. The inventory must not have drifted since staging. Parts files and bundle agree.
ok = run('extract.mjs', ['--check']) && ok;
// 3. Every authored translation must still pass the shape contract after the round-trip
//    through the scanner (padding, newline, digit, and technical-token parity).
ok = run('check-shape.mjs', []) && ok;

if (!ok) {
  console.error('\nstaged verification failed — nothing published');
  process.exit(1);
}
console.log('\nstaged verification: guard + inventory + shape checks passed');
console.log('TODO: when a headless-DOM harness is added, it replaces this stub inside verify.mjs');
