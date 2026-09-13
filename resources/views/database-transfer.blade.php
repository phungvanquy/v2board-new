<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Database Transfer — {{ config('v2board.app_name', 'V2Board') }}</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; margin: 0; background: #f5f5f5; color: #333; }
        .wrap { max-width: 860px; margin: 32px auto; padding: 0 16px; }
        .card { background: #fff; border-radius: 8px; padding: 24px; margin-bottom: 20px; box-shadow: 0 1px 4px rgba(0,0,0,.08); }
        h1 { margin: 0 0 8px; font-size: 22px; }
        h2 { margin: 0 0 12px; font-size: 16px; border-bottom: 1px solid #eee; padding-bottom: 8px; }
        .muted { color: #888; font-size: 13px; }
        .row { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin: 12px 0; }
        .btn { padding: 8px 16px; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; }
        .btn-primary { background: #1677ff; color: #fff; }
        .btn-danger { background: #ff4d4f; color: #fff; }
        .btn:disabled { opacity: .5; cursor: not-allowed; }
        select, input[type=file], input[type=text] { padding: 6px 8px; border: 1px solid #d9d9d9; border-radius: 6px; font-size: 14px; }
        input[type=text] { min-width: 160px; }
        .progress { height: 6px; background: #eee; border-radius: 3px; overflow: hidden; }
        .progress-bar { height: 100%; background: #1677ff; width: 0; transition: width .3s; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th, td { text-align: left; padding: 8px 6px; border-bottom: 1px solid #f0f0f0; }
        th { color: #666; font-weight: 600; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 12px; }
        .badge-success { background: #f6ffed; color: #389e0d; border: 1px solid #b7eb8f; }
        .badge-failed { background: #fff2f0; color: #cf1322; border: 1px solid #ffa39e; }
        .badge-pending { background: #fffbe6; color: #ad6800; border: 1px solid #ffe58f; }
        .alert { padding: 10px 14px; border-radius: 6px; font-size: 13px; margin: 10px 0; }
        .alert-error { background: #fff2f0; border: 1px solid #ffccc7; color: #a8071a; }
        .alert-success { background: #f6ffed; border: 1px solid #b7eb8f; color: #135200; }
        .alert-warn { background: #fffbe6; border: 1px solid #ffe58f; color: #614700; }
        #importModal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.45); align-items: center; justify-content: center; }
        #importModal.open { display: flex; }
        .modal-box { background: #fff; border-radius: 8px; padding: 24px; width: 420px; max-width: 92vw; }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1>{{ __('Database Transfer') }}</h1>
        <p class="muted">{{ __('Export a full dump to migrate servers, or import a previously exported file.') }} {{ __('Destructive actions require typing') }} <code>RESTORE</code>.</p>
        @if(!empty($auth_data))
            <p class="muted"><a href="{{ url('/' . $secure_path) }}">&larr; {{ __('Back to admin') }}</a></p>
        @endif
    </div>

    <div class="card">
        <h2>{{ __('Export') }}</h2>
        <p class="muted">{{ __('Downloads a complete SQL dump of the current database.') }}</p>
        <div class="row">
            <label>{{ __('Format') }}:
                <select id="exportFormat">
                    <option value="full" selected>{{ __('Full (.tar.gz — dump + admin config, recommended for migration)') }}</option>
                    <option value="gz">.sql.gz ({{ __('database only') }})</option>
                    <option value="sql">.sql ({{ __('database only') }})</option>
                </select>
            </label>
            <button class="btn btn-primary" id="exportBtn" onclick="doExport()">{{ __('Download export') }}</button>
        </div>
        <div id="exportMsg"></div>
        <p class="muted" id="lastExportMeta"></p>
    </div>

    <div class="card">
        <h2>{{ __('Import / Restore') }}</h2>
        <p class="muted">{{ __('Upload a') }} <code>.tar.gz</code> ({{ __('full backup') }}), <code>.sql.gz</code> {{ __('or') }} <code>.sql</code> {{ __('database dump.') }} {{ __('A full import also restores System config & theme; a .sql file restores the database only.') }}</p>
        <div class="row">
            <input type="file" id="importFile" accept=".sql,.sql.gz,.tar.gz,.tgz">
            <label><input type="checkbox" id="skipSafety"> {{ __('Skip safety backup') }}</label>
        </div>
        <div class="row">
            <button class="btn btn-danger" onclick="openImportModal()">{{ __('Restore from file') }}</button>
        </div>
        <div id="importMsg"></div>
        <div id="importProgress" style="display:none; margin-top: 10px;">
            <div class="progress"><div class="progress-bar" id="importBar"></div></div>
            <p class="muted" id="importStatusText">{{ __('Queued…') }}</p>
        </div>
    </div>

    <div id="importModal">
        <div class="modal-box">
            <h2 style="border: none; padding: 0;">{{ __('Confirm restore') }}</h2>
            <p class="muted">{{ __('This will overwrite the current database.') }} {{ __('Type') }} <strong>RESTORE</strong> {{ __('to confirm') }}.</p>
            <input type="text" id="confirmInput" placeholder="RESTORE" autocomplete="off">
            <div class="row" style="justify-content: flex-end; margin-top: 16px;">
                <button class="btn" onclick="closeImportModal()">{{ __('Cancel') }}</button>
                <button class="btn btn-danger" id="confirmBtn" onclick="doImport()">{{ __('Confirm & restore') }}</button>
            </div>
            <p class="muted" style="margin-top:8px; font-size:12px;">{{ __('Selected file') }}: <span id="modalFileName">—</span></p>
        </div>
    </div>

    <div class="card">
        <h2>{{ __('History') }}</h2>
        <p class="muted">{{ __('Server-side copies of recent exports can be re-downloaded. Imports link to the pre-restore safety backup taken just before them.') }}</p>
        <div id="historyMsg" class="muted">{{ __('Loading…') }}</div>
        <table id="historyTable" style="display:none;">
            <thead><tr><th>#</th><th>{{ __('Action') }}</th><th>{{ __('File') }}</th><th>{{ __('Size') }}</th><th>{{ __('Status') }}</th><th>{{ __('When') }}</th><th>{{ __('Message') }}</th></tr></thead>
            <tbody id="historyBody"></tbody>
        </table>
        <div class="row" style="justify-content: space-between;">
            <button class="btn" onclick="loadHistory()">{{ __('Refresh') }}</button>
            <span class="muted" id="historyTotal"></span>
        </div>
    </div>
</div>

<script>
const SECURE_PATH = @json($secure_path);
const QUERY_AUTH = @json((string) ($auth_data ?? ''));

function getAuth() {
    if (QUERY_AUTH) return QUERY_AUTH;
    try {
        const v = localStorage.getItem('authorization') || '';
        if (v) return v;
    } catch (e) {}
    const m = document.cookie.match(/(?:^|;\s*)auth_data=([^;]*)/);
    return m ? decodeURIComponent(m[1]) : '';
}

// 401|403 from any admin API means the session is gone (expiry / logout / ban /
// demotion) — drop the stale JWT so the next navigation doesn't retry it forever,
// and send the admin back to the SPA login. QUERY_AUTH pages (loadHistory/auth
// Data) are rendered with a baked-in token; they don't loop on getAuth, so
// redirecting is safe.
function authExpiredRedirect() {
    try { localStorage.removeItem('authorization'); } catch(e) {}
    window.location.replace('/' + SECURE_PATH);
}

function api(path, opts = {}) {
    const auth = getAuth();
    const headers = opts.headers || {};
    if (auth) {
        headers['authorization'] = auth;
        headers['Authorization'] = auth;
    }
    if (auth && opts.method === 'GET' && path.indexOf('auth_data=') === -1) {
        path += (path.indexOf('?') === -1 ? '?' : '&') + 'auth_data=' + encodeURIComponent(auth);
    }
    return fetch(path, Object.assign({}, opts, { headers: headers })).then(function (r) {
        if (r.status === 401 || r.status === 403) { authExpiredRedirect(); }
        return r;
    });
}

function showMsg(elId, html, cls) {
    const el = document.getElementById(elId);
    el.innerHTML = html ? ('<div class="alert alert-' + cls + '">' + escapeHtml(html) + '</div>') : '';
}

function doExport() {
    const fmt = document.getElementById('exportFormat').value;
    const auth = getAuth();
    if (!auth) {
        showMsg('exportMsg', 'Not logged in. Open this page from the admin panel while authenticated (click /database from Admin > Backup).', 'error');
        return;
    }
    showMsg('exportMsg', 'Starting download…', 'warn');
    const url = '/api/v1/' + SECURE_PATH + '/database/export?format=' + encodeURIComponent(fmt) + '&auth_data=' + encodeURIComponent(auth);
    const a = document.createElement('a');
    a.href = url;
    a.style.display = 'none';
    document.body.appendChild(a);
    a.click();
    setTimeout(function () { a.remove(); showMsg('exportMsg', 'Download started. Check your browser downloads.', 'success'); loadHistory(); }, 800);
}

function openImportModal() {
    const f = (document.getElementById('importFile').files || [])[0];
    if (!f) { showMsg('importMsg', 'Please choose a .sql or .sql.gz file first.', 'error'); return; }
    document.getElementById('modalFileName').textContent = f.name + ' (' + (f.size / 1024 / 1024).toFixed(2) + ' MB)';
    document.getElementById('confirmInput').value = '';
    document.getElementById('importModal').classList.add('open');
    document.getElementById('confirmInput').focus();
}

function closeImportModal() {
    document.getElementById('importModal').classList.remove('open');
}

document.getElementById('importModal').addEventListener('click', function (e) {
    if (e.target.id === 'importModal') closeImportModal();
});

let pollTimer = null;

function doImport() {
    const phrase = document.getElementById('confirmInput').value.trim();
    if (phrase !== 'RESTORE') { showMsg('importMsg', 'Please type RESTORE exactly.', 'error'); return; }
    const fileInput = document.getElementById('importFile');
    const f = (fileInput.files || [])[0];
    if (!f) { showMsg('importMsg', 'No file selected.', 'error'); return; }
    closeImportModal();
    showMsg('importMsg', '', '');
    const bar = document.getElementById('importBar');
    const prog = document.getElementById('importProgress');
    const statusText = document.getElementById('importStatusText');
    prog.style.display = 'block';
    bar.style.width = '20%';
    statusText.textContent = 'Uploading…';
    document.getElementById('confirmBtn').disabled = true;

    const fd = new FormData();
    fd.append('file', f);
    fd.append('confirm', 'RESTORE');
    if (document.getElementById('skipSafety').checked) fd.append('skip_safety_backup', '1');
    const auth = getAuth();
    if (auth) fd.append('auth_data', auth);

    const headers = {};
    if (auth) { headers['authorization'] = auth; headers['Authorization'] = auth; }

    fetch('/api/v1/' + SECURE_PATH + '/database/import', { method: 'POST', body: fd, headers: headers })
        .then(function (r) {
            if (r.status === 401 || r.status === 403) { authExpiredRedirect(); }
            return r.json().catch(function () { return {}; }).then(function (j) { return { r: r, j: j }; });
        })
        .then(function (pair) {
            const r = pair.r, j = pair.j;
            if (!r.ok) {
                throw new Error((j && j.message) ? j.message : 'Upload failed (' + r.status + ')');
            }
            const d = j.data || j;
            bar.style.width = '60%';
            if (d.status === 'pending' && d.id) {
                statusText.textContent = 'Restore queued — polling…';
                pollStatus(d.id);
            } else {
                bar.style.width = '100%';
                statusText.textContent = d.message || 'Restore completed.';
                showMsg('importMsg', statusText.textContent, 'success');
                loadHistory();
                setTimeout(function () { prog.style.display = 'none'; bar.style.width = '0'; }, 2000);
            }
        })
        .catch(function (e) {
            bar.style.width = '0';
            prog.style.display = 'none';
            showMsg('importMsg', e.message || 'Restore failed.', 'error');
            loadHistory();
        })
        .then(function () { document.getElementById('confirmBtn').disabled = false; });
}

function pollStatus(id) {
    if (pollTimer) clearInterval(pollTimer);
    const bar = document.getElementById('importBar');
    const statusText = document.getElementById('importStatusText');
    let attempts = 0;
    pollTimer = setInterval(function () {
        attempts++;
        api('/api/v1/' + SECURE_PATH + '/database/status/' + id, { method: 'GET' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                const d = j.data || j;
                const s = String(d.status || '').toLowerCase();
                if (s === 'success') {
                    clearInterval(pollTimer); pollTimer = null;
                    bar.style.width = '100%';
                    statusText.textContent = d.message || 'Restore completed.';
                    showMsg('importMsg', statusText.textContent, 'success');
                    loadHistory();
                    setTimeout(function () { document.getElementById('importProgress').style.display = 'none'; bar.style.width = '0'; }, 2000);
                } else if (s === 'failed') {
                    clearInterval(pollTimer); pollTimer = null;
                    bar.style.width = '0';
                    document.getElementById('importProgress').style.display = 'none';
                    showMsg('importMsg', d.message || 'Restore failed.', 'error');
                    loadHistory();
                } else {
                    bar.style.width = Math.min(90, 60 + attempts * 2) + '%';
                    statusText.textContent = d.message || ('Status: ' + d.status + '…');
                }
            })
            .catch(function () {});
        if (attempts > 180) { clearInterval(pollTimer); pollTimer = null; statusText.textContent = 'Polling timed out — refresh History to check.'; }
    }, 2000);
}

function toDisplayTime(createdAt) {
    if (createdAt == null || createdAt === '') return '—';
    const n = Number(createdAt);
    let ms;
    // MySQL int(11) seconds vs Date.now()/1000 both land in the 1e9 range. Guard just
    // in case a future change switches to Carbon strings or ISO dates.
    if (!Number.isNaN(n) && n > 0 && n < 5e10) ms = (n < 1e11 ? n * 1000 : n);
    else { const t = Date.parse(String(createdAt)); ms = Number.isNaN(t) ? null : t; }
    return ms == null ? escapeHtml(String(createdAt)) : new Date(ms).toLocaleString();
}

function loadHistory() {
    const msg = document.getElementById('historyMsg');
    const tbl = document.getElementById('historyTable');
    const body = document.getElementById('historyBody');
    const totalEl = document.getElementById('historyTotal');
    msg.textContent = 'Loading…';
    api('/api/v1/' + SECURE_PATH + '/database/history?current=1&page_size=20', { method: 'GET' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
            const rows = j.data || [];
            const total = j.total != null ? j.total : rows.length;
            totalEl.textContent = total ? (total + ' record(s)') : '';
            if (!rows.length) { msg.textContent = 'No transfers yet.'; tbl.style.display = 'none'; return; }
            msg.textContent = '';
            tbl.style.display = '';
            body.innerHTML = rows.map(function (r2) {
                const badge = r2.status === 'success' ? 'badge-success' : r2.status === 'failed' ? 'badge-failed' : 'badge-pending';
                const size = r2.file_size ? ((r2.file_size / 1024 / 1024).toFixed(2) + ' MB') : '—';
                let file = r2.file_name ? ('<code style="font-size:12px;">' + escapeHtml(r2.file_name) + '</code>') : '—';
                if (r2.has_file) {
                    file += ' <a href="/api/v1/' + SECURE_PATH + '/database/download/' + r2.id + '?auth_data=' + encodeURIComponent(getAuth()) + '">Download</a>';
                }
                return '<tr><td>' + r2.id + '</td><td>' + escapeHtml(r2.action) + '</td><td>' + file + '</td><td>' + size + '</td><td><span class="badge ' + badge + '">' + escapeHtml(r2.status) + '</span></td><td>' + toDisplayTime(r2.created_at) + '</td><td style="max-width:220px; overflow:hidden; text-overflow:ellipsis;">' + escapeHtml(r2.message || '') + '</td></tr>';
            }).join('');
            const lastExport = rows.slice().reverse().find(function (r3) { return r3.action === 'export' && r3.status === 'success'; }) || rows.find(function (r3) { return r3.action === 'export'; });
            const meta = document.getElementById('lastExportMeta');
            if (lastExport) meta.textContent = 'Last export: #' + lastExport.id + ' — ' + (lastExport.file_name || '') + ' — ' + toDisplayTime(lastExport.created_at);
        })
        .catch(function () { msg.textContent = 'Failed to load history — are you authenticated?'; });
}

function escapeHtml(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

loadHistory();
</script>
</body>
</html>
