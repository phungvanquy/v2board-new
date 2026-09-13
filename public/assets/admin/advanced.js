/**
 * Advanced — inject a sidebar entry that links to the out-of-panel Blade
 * admin pages without rebuilding public/assets/admin/umi.js.
 *
 * The admin SPA hydrates synchronously after umi.js runs. This script:
 *  - watches for the sidebar heading "Metrics" (the last sidebar section
 *    today — stable anchor), inserts "Advanced" right before it;
 *  - falls back to appending at the end if that heading is not found;
 *  - collapses with the sidebar (just an <li> in .nav-main, no fixed
 *    positioning).
 */
(function () {
    var ATTEMPT_LIMIT = 30; // 15s @ 500ms
    var injected = false;
    var tries = 0;

    function inject() {
        if (injected || tries++ > ATTEMPT_LIMIT) return;
        var nav = document.querySelector('.nav-main');
        if (!nav) return;
        if (nav.querySelector('[data-advanced-link]')) { injected = true; return; }

        var sp = (window.settings && window.settings.secure_path) || '';
        if (!sp) return;
        var href = '/' + sp + '/advanced';

        // Build the same DOM shape as the SPA's renderMenu("heading"/"item").
        var heading = document.createElement('li');
        heading.className = 'nav-main-heading';
        heading.textContent = 'Advanced';

        var item = document.createElement('li');
        item.className = 'nav-main-item';
        item.setAttribute('data-advanced-link', '1');
        var a = document.createElement('a');
        a.className = 'nav-main-link';
        a.href = href;
        // Keep the bridge semantics: open in-place (same tab) so the
        // ?auth_data redirect can pick up localStorage if needed.
        // No target=_blank — user stays in the admin origin.
        var icon = document.createElement('i');
        icon.className = 'nav-main-link-icon si si-settings';
        var label = document.createElement('span');
        label.className = 'nav-main-link-name';
        label.textContent = 'Advanced Settings';
        a.appendChild(icon);
        a.appendChild(label);
        item.appendChild(a);

        // Insert before the "Metrics" heading if present, else at end.
        var metrics = null;
        for (var i = 0; i < nav.children.length; i++) {
            var li = nav.children[i];
            if (li.classList.contains('nav-main-heading') && li.textContent.trim() === 'Metrics') {
                metrics = li; break;
            }
        }
        if (metrics) {
            nav.insertBefore(item, metrics);
            nav.insertBefore(heading, item);
        } else {
            nav.appendChild(heading);
            nav.appendChild(item);
        }
        injected = true;
    }

    // Poll until the SPA's sidebar mounts, then keep observing for SPA re-renders.
    var timer = setInterval(inject, 500);
    setTimeout(function () { clearInterval(timer); }, 16000);
    // Also run on SPA navigation (hash/pushState) — the sidebar node persists
    // but a future navigation could re-render it.
    window.addEventListener('popstate', function () { injected = false; setTimeout(inject, 300); });
})();
