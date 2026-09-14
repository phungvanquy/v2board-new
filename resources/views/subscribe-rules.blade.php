<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Subscribe Rules') }} — {{ config('v2board.app_name', 'V2Board') }}</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin: 0; background: #f5f5f5; color: #333; }
        .wrap { max-width: 760px; margin: 32px auto; padding: 0 16px; }
        .card { background: #fff; border-radius: 8px; padding: 24px; margin-bottom: 20px; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
        h1 { margin: 0 0 8px; font-size: 22px; }
        h2 { margin: 0 0 12px; font-size: 16px; border-bottom: 1px solid #eee; padding-bottom: 8px; }
        .muted { color: #888; font-size: 13px; }
        .row { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin: 12px 0; }
        .form-row { display: flex; flex-direction: column; gap: 6px; margin: 16px 0; }
        .form-row label { font-weight: 600; font-size: 14px; }
        .hint { color: #888; font-size: 12px; }
        textarea { padding: 8px 10px; border: 1px solid #d9d9d9; border-radius: 6px; font-size: 13px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; min-height: 110px; resize: vertical; }
        .switch { display: flex; align-items: center; gap: 8px; }
        .switch input[type=checkbox] { width: 18px; height: 18px; }
        .btn { padding: 8px 16px; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; }
        .btn-primary { background: #1677ff; color: #fff; }
        .btn:disabled { opacity: .5; cursor: not-allowed; }
        .alert { padding: 10px 14px; border-radius: 6px; font-size: 13px; margin: 10px 0; }
        .alert-error { background: #fff2f0; border: 1px solid #ffccc7; color: #a8071a; }
        .alert-success { background: #f6ffed; border: 1px solid #b7eb8f; color: #135200; }
        .alert-warn { background: #fffbe6; border: 1px solid #ffe58f; color: #614700; }
        .preview { background: #fafafa; border: 1px solid #eee; border-radius: 6px; padding: 10px 12px; font-size: 12px; font-family: ui-monospace, monospace; white-space: pre-wrap; word-break: break-all; max-height: 220px; overflow: auto; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1>{{ __('Subscribe Rules') }}</h1>
        <p class="muted">{{ __('Control which destinations bypass the VPN (go DIRECT) in supported clients. After saving, update the subscription in the app and reconnect.') }}</p>
        <p class="muted"><a href="{{ url('/' . $secure_path) }}">&larr; {{ __('Back to admin') }}</a></p>
    </div>

    <div class="card">
        <h2>{{ __('Russia — bypass VPN (DIRECT)') }}</h2>
        <p class="muted">{{ __('When enabled, Russian sites (.ru / .su / .рф / moscow / tatar, vk/yandex/kaspersky), RU IPs, and the extra domains below receive DIRECT rules in Clash, Stash, Surge, Surfboard, sing-box, Karing, and Happ subscriptions.') }}</p>
        <div class="alert alert-warn">
            <p>{{ __('Use rule-based routing in the app. Global proxy mode can override bypass rules.') }}</p>
            <p>{{ __('Happ: update the subscription, check that its DIRECT routing profile is active, then reconnect.') }}</p>
            <p>{{ __('Karing: turn off Disable ISP diversion rules, update the subscription, and reconnect.') }}</p>
            <p>{{ __('Hiddify: the subscription contains sing-box rules, but the app can replace them with its own routing settings. If imported rules are ignored, add the domain to the app’s DIRECT rules. Server-side settings alone cannot guarantee bypass in every Hiddify version.') }}</p>
            <p>{{ __('Other apps that import only server links need their own bypass rules. Individual node links do not contain this policy.') }}</p>
        </div>

        <div class="form-row">
            <label class="switch">
                <input type="checkbox" id="ruEnable">
                <span>{{ __('Enable Russia DIRECT') }}</span>
            </label>
            <span class="hint">{{ __('Applies to the base list and all extra domains. Turning this off stops adding these rules; other rules in the app or custom templates can still apply.') }}</span>
        </div>

        <div class="form-row">
            <label for="ruDomains">{{ __('Extra domain suffixes (optional)') }}</label>
            <textarea id="ruDomains" placeholder="example.ru&#10;another.custom&#10;— one per line, e.g. ozon.ru"></textarea>
            <span class="hint">{{ __('Enter bare domains, one per line: for example, 2ip.io. Do not include https://, paths, or DOMAIN-SUFFIX syntax. A suffix covers the domain itself and its subdomains. Use punycode for internationalized names. The base list is always included when enabled.') }}</span>
        </div>

        <div class="form-row">
            <label>{{ __('Preview (base + extras)') }}</label>
            <div class="preview" id="preview">—</div>
        </div>

        <div class="row">
            <button class="btn btn-primary" id="saveBtn" onclick="doSave()">{{ __('Save') }}</button>
            <span id="saveMsg" class="muted"></span>
        </div>
        <div id="alertBox"></div>
    </div>
</div>
<script>
const SECURE = @json($secure_path);
function getAuth() {
    try { const v = localStorage.getItem('authorization') || ''; if (v) return v; } catch(e) {}
    const m = document.cookie.match(/(?:^|;\s*)auth_data=([^;]*)/);
    return m ? decodeURIComponent(m[1]) : '';
}
// Session gone (expired / logged out / demoted / banned): 401|403 from the admin
// API => drop the stale JWT and send the admin to the SPA login to re-authenticate.
function authExpiredRedirect() {
    try { localStorage.removeItem('authorization'); } catch(e) {}
    window.location.replace('/' + SECURE);
}
function showMsg(id, text, kind) {
    const el = document.getElementById(id);
    if (!el) return;
    el.textContent = text;
    el.className = kind ? ('alert alert-' + kind) : 'muted';
    if (text && kind === 'success') setTimeout(() => { el.textContent=''; el.className='muted'; }, 3000);
}
function api(path, opts) {
    opts = opts || {};
    opts.headers = opts.headers || {};
    const auth = getAuth();
    if (auth) { opts.headers['Authorization'] = auth; }
    // also append ?auth_data for routes that read it from query
    const sep = path.indexOf('?') === -1 ? '?' : '&';
    const url = '/api/v1/' + SECURE + path + (auth ? sep + 'auth_data=' + encodeURIComponent(auth) : '');
    return fetch(url, opts).then(function (r) {
        if (r.status === 401 || r.status === 403) { authExpiredRedirect(); }
        return r;
    });
}
const BASE_SUFFIXES = ['ru','su','xn--p1ai','moscow','tatar'];
const BASE_DOMAINS = ['vk.com','yandex.com','yandex.net','kaspersky.com'];
function renderPreview() {
    const extra = (document.getElementById('ruDomains').value || '').split(/[\r\n,;\s]+/).map(s=>s.trim().toLowerCase().replace(/^\.+/,'')).filter(s=>s && /^[a-z0-9.\-]+$/.test(s));
    const seen = new Set(); const all=[];
    [...BASE_SUFFIXES, ...BASE_DOMAINS, ...extra].forEach(s=>{ if(!seen.has(s)){ seen.add(s); all.push(s);} });
    const enabled = document.getElementById('ruEnable').checked;
    if (!enabled) { document.getElementById('preview').textContent = '(disabled — no RU rules will be injected)'; return; }
    const lines = [];
    // clash/stash DOMAINs
    all.forEach(s=> lines.push('DOMAIN-SUFFIX,'+s+',DIRECT'));
    lines.push('GEOIP,RU,DIRECT');
    lines.push('—');
    lines.push('sing-box: rule_set geosite-ru (geosite-category-ru.srs) + geoip-ru (ru.srs) → DIRECT');
    lines.push('sing-box: all suffixes above → DIRECT routing + local DNS');
    lines.push('Happ: subscription routing profile with the same suffixes + RU IPs');
    if (extra.length) lines.push('extras: ' + extra.join(', '));
    document.getElementById('preview').textContent = lines.join('\n');
}
let _loading = false;
function load() {
    if (_loading) return; _loading=true;
    api('/subscribe-rules/fetch').then(r=>r.json()).then(j=>{
        const d = (j && j.data) || {};
        document.getElementById('ruEnable').checked = !!d.subscribe_ru_direct_enable;
        document.getElementById('ruDomains').value = d.subscribe_ru_direct_domains || '';
        renderPreview();
        _loading=false;
    }).catch(e=>{
        showMsg('alertBox', e.message || 'Failed to load.', 'error');
        _loading=false;
    });
}
function doSave() {
    const btn = document.getElementById('saveBtn');
    btn.disabled = true;
    showMsg('saveMsg', 'Saving…', '');
    const payload = {
        subscribe_ru_direct_enable: document.getElementById('ruEnable').checked ? 1 : 0,
        subscribe_ru_direct_domains: document.getElementById('ruDomains').value || ''
    };
    api('/subscribe-rules/save', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    }).then(r=>r.json().then(j=>({r,j}))).then(({r,j})=>{
        if (!r.ok) throw new Error((j && j.message) || 'Save failed ('+r.status+')');
        showMsg('saveMsg', 'Saved. Update the subscription in the app, then reconnect.', 'success');
        showMsg('alertBox', '', '');
        renderPreview();
    }).catch(e=>{
        showMsg('alertBox', e.message || 'Save failed.', 'error');
        showMsg('saveMsg', '', '');
    }).finally(()=>{ btn.disabled=false; });
}
document.getElementById('ruEnable').addEventListener('change', renderPreview);
document.getElementById('ruDomains').addEventListener('input', renderPreview);
load();
</script>
</body>
</html>
