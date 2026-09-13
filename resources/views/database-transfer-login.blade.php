<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Database Transfer') }} — {{ config('v2board.app_name', 'V2Board') }}</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin: 0; background: #f5f5f5; color: #333; }
        .wrap { max-width: 860px; margin: 32px auto; padding: 0 16px; }
        .card { background: #fff; border-radius: 8px; padding: 24px; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
        .muted { color: #888; font-size: 13px; }
        a { color: #1677ff; }
        .btn { display: inline-block; padding: 8px 16px; border-radius: 6px; background: #1677ff; color: #fff; text-decoration: none; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card" id="bridge">
        <h1 style="margin:0 0 8px; font-size:22px;">{{ __('Database Transfer') }}</h1>
        <p class="muted" id="bridgeMsg">{{ __('Connecting to your admin session…') }}</p>
    </div>
</div>
<script>
(function () {
    const SECURE = @json($secure_path);
    const EXPIRED = @json((bool) ($expired ?? false));
    const QUERY_TOKEN = @json((string) ($token ?? ''));
    function getAuth() {
        try {
            const v = localStorage.getItem('authorization') || '';
            if (v) return v;
        } catch (e) {}
        const m = document.cookie.match(/(?:^|;\s*)auth_data=([^;]*)/);
        return m ? decodeURIComponent(m[1]) : '';
    }
    const target = '/' + SECURE + '/database';
    const admin = '/' + SECURE;
    const msg = document.getElementById('bridgeMsg');
    if (EXPIRED) {
        try { const cur = getAuth(); if (cur && cur === QUERY_TOKEN) localStorage.removeItem('authorization'); } catch(e) {}
        msg.innerHTML =
            '<span style="color:#cf1322;">Session expired — please log in again.</span><br>' +
            '<a class="btn" href="' + admin + '" style="margin-top:10px;">Go to login</a> <span class="muted">Redirecting…</span>';
        setTimeout(function () { window.location.replace(admin); }, 1800);
        return;
    }
    if (getAuth()) {
        window.location.replace(target + '?auth_data=' + encodeURIComponent(getAuth()));
        msg.textContent = 'Auth found — reloading…';
        return;
    }
    msg.innerHTML =
        '<span style="color:#cf1322;">Not logged in in this browser.</span><br>' +
        'Open <a href="' + admin + '">' + admin + '</a> in <strong>this same browser</strong> first, log in, ' +
        'then reopen <a href="' + target + '">' + target + '</a> in the same browser.<br>' +
        'Tip: <code>auth_data</code> lives in <code>localStorage</code> under key ' +
        '<code>authorization</code> — that storage is per-origin and does not roam across browsers.';
})();
</script>
</body>
</html>
