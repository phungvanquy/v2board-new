// Shared scanner for the admin dashboard compiled bundles.
//
// Everything else in admin-i18n trusts this module for two guarantees:
//   1. Only real JavaScript string literals are reported. The bundles are parsed with
//      acorn, so regex sources, identifiers and template syntax are structurally out of
//      reach of any rewrite — a naive text substitution could corrupt them.
//   2. Each literal is attributed to its exact webpack module, so the same Chinese word
//      can be translated differently where it genuinely means different things.
import fs from 'fs';
import path from 'path';
import * as acorn from 'acorn';

export const BUNDLE_DIR = 'public/assets/admin';
export const BUNDLE_FILES = ['umi.js', 'vendors.async.js', 'components.async.js'];

// CJK unified ideographs (+ ext. A), CJK punctuation, and half/full-width forms.
const CJK = /[　-〿㐀-䶿一-鿿豈-﫿＀-￯]/;

export function hasCJK(s) {
  return typeof s === 'string' && CJK.test(s);
}

function tokenize(src) {
  const tokens = [];
  acorn.parse(src, {
    ecmaVersion: 'latest',
    sourceType: 'script',
    allowHashBang: true,
    onToken: tokens,
  });
  return tokens;
}

// The webpack module map is the object literal with by far the most function-valued
// properties in the bundle (hundreds, versus a handful for any incidental object).
// Picking it that way is deterministic; a `name: function` text regex is not usable
// because inner methods (a generator's `stop: function`, for one) look identical, and
// "first object with two functions" in AST order lands on an inner object instead.
function collectModuleMaps(root) {
  const found = [];
  (function walk(node) {
    if (!node || typeof node !== 'object') return;
    if (Array.isArray(node)) {
      for (const n of node) walk(n);
      return;
    }
    if (node.type === 'ObjectExpression') {
      const fns = node.properties.filter((p) =>
        p.type === 'Property'
        && p.value
        && (p.value.type === 'FunctionExpression' || p.value.type === 'ArrowFunctionExpression'));
      if (fns.length >= 2) {
        found.push({
          start: node.start,
          end: node.end,
          props: fns.map((p) => ({
            id: p.key.type === 'Literal' ? String(p.key.value) : p.key.name,
            start: p.start,
            end: p.end,
          })).sort((a, b) => a.start - b.start),
        });
      }
    }
    for (const key of Object.keys(node)) {
      if (key === 'type' || key === 'start' || key === 'end' || key === 'loc' || key === 'range') continue;
      const v = node[key];
      if (v && typeof v === 'object') walk(v);
    }
  })(root);
  return found;
}

function buildModuleIndex(ast, label) {
  const maps = collectModuleMaps(ast);
  if (!maps.length) {
    throw new Error(`${label}: no webpack module map found; refusing to guess string attribution`);
  }
  // The map itself, plus any nested candidate, is registered so that offsets inside
  // the real map resolve there and offsets elsewhere still resolve to something sane.
  const main = maps.reduce((a, b) => (b.props.length > a.props.length ? b : a));
  const marks = main.props;
  return function moduleAt(offset) {
    let lo = 0
      , hi = marks.length - 1
      , found = null;
    while (lo <= hi) {
      const mid = (lo + hi) >> 1;
      if (marks[mid].start <= offset) {
        found = marks[mid];
        lo = mid + 1;
      } else {
        hi = mid - 1;
      }
    }
    return found && offset < found.end ? found.id : '(unknown)';
  };
}

// Parse a bundle and return every CJK string literal with its exact decoded value,
// byte span and owning module. Throws if the source is not valid JavaScript, so a
// corrupted build can never be scanned into a clean-looking result.
export function scanFile(filePath) {
  const src = fs.readFileSync(filePath, 'utf8');
  let ast;
  try {
    ast = acorn.parse(src, {
      ecmaVersion: 'latest',
      sourceType: 'script',
      allowHashBang: true,
      ranges: false,
    });
  } catch (err) {
    throw new Error(`${filePath} does not parse as JavaScript: ${err.message}`);
  }
  const moduleAt = buildModuleIndex(ast, filePath);
  const found = [];
  for (const tok of tokenize(src)) {
    if (tok.type.label !== 'string') continue;
    if (!hasCJK(tok.value)) continue;
    found.push({
      file: path.basename(filePath),
      module: moduleAt(tok.start),
      offset: tok.start,
      end: tok.end,
      raw: src.slice(tok.start, tok.end),
      zh: tok.value,
      before: src.slice(Math.max(0, tok.start - 60), tok.start),
      after: src.slice(tok.end, Math.min(src.length, tok.end + 60)),
    });
  }
  return found;
}

export function scanAll(root = '.') {
  const alt = path.join(root, 'admin-i18n', 'orig');
  if (fs.existsSync(path.join(alt, 'umi.js')) && !process.env.ADMIN_I18N_FROM_LIVE) {
    return [
      ...scanFile(path.join(alt, 'umi.js')),
      ...scanFile(path.join(alt, 'vendors.async.js')),
      ...scanFile(path.join(alt, 'components.async.js')),
    ];
  }
  const all = [];
  for (const f of BUNDLE_FILES) all.push(...scanFile(path.join(root, BUNDLE_DIR, f)));
  return all;
}

// Re-encode a JS string value as a double-quoted literal, keeping non-ASCII as
// \uXXXX escapes so the emitted bundle stays ASCII-only like the original build output.
export function encodeLiteral(s) {
  let out = '';
  for (const ch of s) {
    const c = ch.codePointAt(0);
    if (ch === '"') out += '\\"';
    else if (ch === '\\') out += '\\\\';
    else if (ch === '\n') out += '\\n';
    else if (ch === '\r') out += '\\r';
    else if (ch === '\t') out += '\\t';
    else if (c > 0x7e) {
      if (c > 0xffff) {
        const s2 = c - 0x10000;
        out += '\\u' + (0xd800 + (s2 >> 10)).toString(16)
             + '\\u' + (0xdc00 + (s2 & 0x3ff)).toString(16);
      } else {
        out += '\\u' + c.toString(16).padStart(4, '0');
      }
    } else {
      out += ch;
    }
  }
  return '"' + out + '"';
}
