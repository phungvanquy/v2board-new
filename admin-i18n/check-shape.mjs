#!/usr/bin/env node
// Shape checks for admin-i18n translations.
//
// Parts files are authored positionally against a generated key list, so a single
// skipped or duplicated line would silently shift every later translation by one —
// a failure mode that reads fine to the eye. These checks assert that each Chinese
// key and its English value agree on structure that a real translation must preserve:
//
//   * leading/trailing whitespace, because the UI pads labels with literal spaces
//   * newline and tab counts, for multi-line placeholders
//   * technical tokens (URLs, host:port examples, config keys, protocol names such as
//     SNI / REALITY / BBR / geoip:cn) that must survive translation verbatim
//   * digit sequences and printf-style %s / %d / {placeholder} tokens
//
//   node admin-i18n/check-shape.mjs            check every part file
//   node admin-i18n/check-shape.mjs <token>    check one part file
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { hasCJK } from './lib/scan.mjs';
import { TBD, KEEP, EMPTY } from './lib/constants.mjs';

const HERE = path.dirname(fileURLToPath(import.meta.url));
const PARTS = path.join(HERE, 'strings', 'parts');

// Tokens that must appear unchanged in the English: URLs, code-ish identifiers,
// and latin technical abbreviations (2+ chars, containing a digit or uppercase run).
function techTokens(s) {
  const out = new Set();
  for (const m of s.matchAll(/https?:\/\/[^\s"'(),，。）]+/g)) out.add(m[0]);
  for (const m of s.matchAll(/\b[a-z]+:[a-z][\w.-]*\b/g)) out.add(m[0]);   // geoip:cn, geosite:netflix
  for (const m of s.matchAll(/\b\d{1,3}(?:\.\d{1,3}){3}(?:\/\d+)?\b/g)) out.add(m[0]); // 127.0.0.1, 10.0.0.0/8
  for (const m of s.matchAll(/\b[A-Z][A-Z0-9]{1,}(?:[_-][A-Z0-9]+)*\b/g)) out.add(m[0]); // SNI, CF_DNS_API_TOKEN
  for (const m of s.matchAll(/\b[a-z][a-z_]{2,}\b(?==)/g)) out.add(m[0]);
  return [...out].filter((t) => !/^(https?|http)$/.test(t));
}

function placeholders(s) {
  return (s.match(/%[sd]/g) || []).join(',') + '|' + (s.match(/\{\w+\}/g) || []).sort().join(',');
}

function edges(s) {
  const lead = (s.match(/^\s*/) || [''])[0];
  const trail = (s.match(/\s*$/) || [''])[0];
  return [lead, trail];
}

export function checkPair(zh, en) {
  const problems = [];
  if (en === TBD || en === KEEP || en === EMPTY || typeof en !== 'string' || en === '') return problems;

  const [zl, zt] = edges(zh)
    , [el, et] = edges(en);
  if (zl !== el) problems.push(`leading whitespace ${JSON.stringify(zl)} -> ${JSON.stringify(el)}`);
  if (zt !== et) problems.push(`trailing whitespace ${JSON.stringify(zt)} -> ${JSON.stringify(et)}`);

  const count = (s, c) => (s.split(c).length - 1);
  if (count(zh, '\n') !== count(en, '\n')) {
    problems.push(`newline count ${count(zh, '\n')} -> ${count(en, '\n')}`);
  }
  if (count(zh, '\t') !== count(en, '\t')) problems.push(`tab count ${count(zh, '\t')} -> ${count(en, '\t')}`);
  if (count(zh, '"') !== count(en, '"')) problems.push(`double-quote count ${count(zh, '"')} -> ${count(en, '"')}`);

  const zp = placeholders(zh)
    , ep = placeholders(en);
  if (zp !== ep) problems.push(`placeholders ${zp} -> ${ep}`);

  // A URL only has to stay a URL to the same place: an anchor written in Chinese is
  // legitimately rendered percent-encoded, so compare host + path rather than bytes.
  const zhUrl = /^https?:\/\/\S+$/.test(zh.trim());
  if (zhUrl) {
    const a = zh.trim()
      , b = en.trim();
    if (!/^https?:\/\/\S+$/.test(b)) {
      problems.push('value is no longer a URL');
    } else {
      const strip = (u) => u.replace(/#.*$/, '');
      if (strip(a) !== strip(b)) problems.push(`URL target changed: ${strip(a)} -> ${strip(b)}`);
    }
  } else {
    const missing = techTokens(zh).filter((t) => !en.includes(t));
    if (missing.length) problems.push(`dropped technical tokens: ${missing.map((m) => JSON.stringify(m)).join(', ')}`);
  }

  // Numbers survive translation except where English idiom replaces the digit: a bare
  // month label ("1月" -> "Jan") and moment's singular form ("1 天" -> "a day").
  const monthLabel = /^\d{1,2}月$/.test(zh) || /^[一二三四五六七八九十]+月$/.test(zh);
  const momentOne = /^1 (个)?(秒|分钟|小时|天|周|个月|年)$/.test(zh);
  if (!zhUrl && !monthLabel && !momentOne) {
    // English writes months and weekdays as words where Chinese uses digits, so fold
    // those words back to numbers before comparing; a genuinely dropped number still
    // fails. Ordinal suffixes ("1st", "2nd") are stripped first.
    // 'may' is deliberately absent: as a modal verb it is far more common in UI copy
    // than as the month, and folding it would invent digits. Bare month labels that do
    // mean May are already exempted by the monthLabel pattern above.
    const MONTHS = ['january', 'february', 'march', 'april', 'june', 'july',
      'august', 'september', 'october', 'november', 'december'];
    const norm = (x) => {
      let y = x.toLowerCase().replace(/\b(\d+)(?:st|nd|rd|th)\b/g, '$1');
      MONTHS.forEach((m, i) => { y = y.split(m).join(String(i + 1)); });
      return y;
    };
    const zdigits = (zh.match(/\d+(?:\.\d+)?/g) || []).sort().join(',');
    const edigits = (norm(en).match(/\d+(?:\.\d+)?/g) || []).sort().join(',');
    if (zdigits !== edigits) problems.push(`numbers ${zdigits || '(none)'} -> ${edigits || '(none)'}`);
  }

  if (hasCJK(en)) problems.push('value still contains CJK');
  return problems;
}

// The two library locale part files hold the bundle's OWN upstream English strings
// (extracted from vendors.async.js, not translated by us), and a Chinese locale and its
// English counterpart are different sentences: ECharts aria fragments legitimately start
// with a space in English where the Chinese has none, and English prose uses curly quotes
// where Chinese uses none. Shape parity is not a meaningful claim about them, so they are
// exempt — every app-facing string in default.json and the override files is still checked.
const EXEMPT = new Set(['vendors_async_js.71kh.json', 'vendors_async_js.XDpg.json']);

const only = process.argv[2];
const files = fs.readdirSync(PARTS).filter((n) => n.endsWith('.json'))
  .filter((n) => !only || n === `${only}.json`);
if (only && !files.length) {
  console.error(`no part file named ${only}.json`);
  process.exit(1);
}

let checked = 0
  , bad = 0;
const lines = [];
let exemptSkipped = 0;
for (const f of files) {
  if (EXEMPT.has(f)) {
    const o = JSON.parse(fs.readFileSync(path.join(PARTS, f), 'utf8'));
    exemptSkipped += Object.values(o).filter((v) => v !== '').length;
    continue;
  }
  const obj = JSON.parse(fs.readFileSync(path.join(PARTS, f), 'utf8'));
  for (const [zh, en] of Object.entries(obj)) {
    if (en === '') continue; // not translated yet; extract.mjs --check reports those
    checked++;
    const problems = checkPair(zh, en);
    if (problems.length) {
      bad++;
      lines.push(`${f}: ${JSON.stringify(zh)}\n    -> ${JSON.stringify(en)}\n    ${problems.join('\n    ')}`);
    }
  }
}
for (const l of lines) console.error(l);
console.log(`shape check: ${checked} translated pair(s), ${bad} suspicious`
  + (exemptSkipped ? `, ${exemptSkipped} exempt (upstream library locale)` : ''));
process.exit(bad ? 1 : 0);
