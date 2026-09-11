# admin-i18n operator guide

The admin frontend ships as compiled bundles checked into `public/assets/admin/`; it has no
upstream source repo to rebuild from (see `openspec/changes/translate-admin-dashboard-to-english/design.md`
→ "Resolved: there is no admin source repo"). Admin display text is instead authored
in this directory and stamped into the bundles by a deterministic pipeline. The bundles
are generated output, not source — they are never edited by hand.

## Before you do anything

```bash
cd admin-i18n
node check-shape.mjs
node extract.mjs --check
node ../admin-i18n/verify.mjs
```

All three should pass on a clean tree. If they don't, the tree is stale — rebuild before
editing.

## Correct one string (new contributor)

Fix a string that scans as pending (e.g. `"从未在线"` was the originally pending key for
the last-online column).

1. `node extract.mjs --seed` — skeletons new keys in `strings/parts/default.json` with
   empty values so you don't have to invent the Chinese side. If the string you want is
   already listed with a value of `""` there, skip this.
2. Edit the value in `strings/parts/default.json` (shared strings live there; see the
   next section for per-module overrides).
3. `node check-shape.mjs` — refuses a translation whose padding, newline/tab, digit,
   placeholder, or technical-token structure diverges from its source. Fix the value to
   match before continuing.
4. `node extract.mjs` — merges the parts into the generated
   `strings/admin-ui.en-US.json` inventory + the `report/admin-ui.zh-en.md` review sheet
   (do not edit either of those by hand).
5. `node translate.mjs --dry-run` — shows which bundle literals will be rewritten and
   blocks on any still-empty entry; write nothing. Then `node translate.mjs --stage` to
   produce `build/` which must re-parse and report **0 translatable residue**.
6. `node verify.mjs build` — same three assertions `translate.mjs` runs before publishing;
   fail-before-publish instead of warn-after.
7. Publish to the live tree when you are sure: `node translate.mjs` copies `build/` onto
   `../public/assets/admin/` and deposits a one-time pristine copy in `orig/` for
   rollback. Bump `../config/app.php` `version` so `?v={{$version}}` cache busts.
8. `node guard.mjs` — re-scans the live tree with the same AST path and allowlist (so a
   plain `grep '[一-鿿]'` is not enough). Exit 0 means no untranslated display text.

## When two modules need different English for the same Chinese

`default.json` is the shared table. If a Chinese literal genuinely means two different
things (关闭 is "Close" on a chart toolbar but "Closed" for a ticket's status; moment's
日 is the format token "D" while a user-facing label is "Day"), add a per-module override
instead:

```bash
node extract.mjs --conflicts  # which literals appear in more than one module
```

Author the override in `strings/parts/umi_js.<module>.json` (or `vendors_async_js.` /
`components_async_js.` for the other bundles). The lookup order is per-module file first,
then `default.json` — exactly the tier `extract.mjs` implements.

## The allowlist

`strings/admin-ui.allowlist.json` lists the webpack modules whose CJK literals are
not display text (regjsparser regex character-classes, core-js's U+3000 whitespace
table, the `he` entity table, zrender's glyph-width measurement probes). Add a new entry
only when you can state which library plumbing requires it and why it must not be
translated.

## The PHP complement (same pattern, different tree)

`strings/server-admin.en-US.json` and `strings/payments.en-US.json` are the source of
truth for Chinese literals the backend renders in the admin (controllers + Admin/Staff
middlewares + Admin request validators + payment-gateway config forms). Apply them via:

```bash
node server-messages.mjs --dry-run   # 369 PHP literals rewritten against the table
node server-messages.mjs             # rewrites the PHP + appends English keys to
                                     # ../resources/lang/zh-CN.json and en-US.json
node payments-concat.mjs --check     # concatenation-shaped displays (3) this tool
                                     # does not rewrite, e.g. 'EPay 配置不完整：' . $key
node server-messages.mjs --check
node payments-concat.mjs --check
```

Each `__()` call keeps its `zh-CN.json` entry, so user-facing code sharing that key
remains Chinese when the locale resolves to `zh-CN`; the admin path renders English
because `/api/v1/*` sets the locale from the `content-language` header (the admin
enforces it — see `app/Http/Middleware/Language.php`).

Records under `_not_translated` are deliberate: CSV export cell values (`不限制` etc.),
wire-coupled filter tokens, and halves of string concatenations. A literal cannot be
listed as both translated and skipped — that is a hard failure.

## Gates (the same tools CI runs)

```bash
node check-shape.mjs                         # every authored pair keeps its shape
node extract.mjs --check                     # inventory fresh against admin-i18n/orig/
node verify.mjs admin-i18n/build             # staged bundles parse + hold no residue
node guard.mjs                               # live bundles: 0 translatable residue
node lib/wire-check.mjs --strict             # bundle ↔ backend couplings accounted for
node server-messages.mjs --check
node payments-concat.mjs --check
docker run php:8.2-cli-alpine php -l app     # no syntax errors
```

## After a bundle upgrade

1. Check out the new `public/assets/admin/*.js` into place.
2. `node extract.mjs --seed` — creates skeletons for any new Chinese literals, drops
   stale keys, and records orphans with nearest-neighbor suggestions for mistyped entries.
3. Translate the pending items (check-shape → extract → translate --stage → verify).
4. Publish (`translate.mjs`) and bump `config/app.php` `version`.
