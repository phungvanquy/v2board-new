// Sentinels shared by extract.mjs, translate.mjs and guard.mjs.
// Kept in their own module so importing them does not run extract.mjs's script body.

// No English translation has been authored for this string yet.
export const TBD = '__TBD__';

// The string is CJK in the bundle on purpose (regex fragment, measurement probe,
// entity table). It is allowlisted via strings/admin-ui.allowlist.json and must be
// copied through unchanged.
export const KEEP = '__KEEP__';

// An intentional empty display value. Some upstream English resources really are empty
// (the ECharts screen-reader description uses no separators in English), so "empty"
// has to be expressible without clashing with the blank used for "not translated yet".
export const EMPTY = '__EMPTY__';
