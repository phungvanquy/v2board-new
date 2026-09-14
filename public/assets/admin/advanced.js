/**
 * Advanced — inject a sidebar entry that links to the out-of-panel Blade
 * admin pages without rebuilding public/assets/admin/umi.js.
 *
 * The admin SPA hydrates asynchronously after umi.js runs and React
 * re-renders the sidebar on every in-app navigation (layout/showNav),
 * which wipes any manually injected DOM. So instead of polling with a
 * timeout (which misses slow mounts and never recovers from re-renders),
 * this script ensures the entry idempotently and re-runs the check via a
 * MutationObserver whenever the DOM changes.
 */
(function () {
    function securePath() {
        return (window.settings && window.settings.secure_path) || '';
    }

    function buildNodes(href) {
        // Build the same DOM shape as the SPA's renderMenu("heading"/"item").
        var heading = document.createElement('li');
        heading.className = 'nav-main-heading';
        heading.setAttribute('data-advanced-link', 'heading');
        heading.textContent = 'Advanced';

        var item = document.createElement('li');
        item.className = 'nav-main-item';
        item.setAttribute('data-advanced-link', 'item');
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

        return { heading: heading, item: item };
    }

    function ensure() {
        var sp = securePath();
        if (!sp) return;
        var nav = document.querySelector('.nav-main');
        if (!nav) return;
        // Idempotent: bail out if our entry survived this render.
        // (Also matches nodes injected by an older cached copy of this
        // script, so a version skew can never produce duplicates.)
        if (nav.querySelector('[data-advanced-link="item"]')) return;

        var nodes = buildNodes('/' + sp + '/advanced');

        // Insert before the "Metrics" heading if present, else at end.
        var metrics = null;
        for (var i = 0; i < nav.children.length; i++) {
            var li = nav.children[i];
            if (li.classList.contains('nav-main-heading') && li.textContent.trim() === 'Metrics') {
                metrics = li;
                break;
            }
        }
        if (metrics) {
            nav.insertBefore(nodes.item, metrics);
            nav.insertBefore(nodes.heading, nodes.item);
        } else {
            nav.appendChild(nodes.heading);
            nav.appendChild(nodes.item);
        }
    }

    // Coalesce bursts of mutations (React renders touch many nodes at once)
    // into a single check per frame.
    var scheduled = false;
    function scheduleEnsure() {
        if (scheduled) return;
        scheduled = true;
        var run = function () {
            scheduled = false;
            try {
                ensure();
            } catch (e) {
                /* never break the host page */
            }
        };
        if (typeof window.requestAnimationFrame === 'function') {
            window.requestAnimationFrame(run);
        } else {
            setTimeout(run, 0);
        }
    }

    // Observe the whole document: the sidebar mounts asynchronously after
    // the SPA hydrates, and React may replace its subtree on navigation.
    // Our own insertions also trigger the observer, but ensure() is
    // idempotent so they collapse into a no-op.
    if (typeof window.MutationObserver === 'function') {
        var observer = new MutationObserver(scheduleEnsure);
        var root = document.documentElement || document.body;
        observer.observe(root, { childList: true, subtree: true });
    } else {
        // Ancient browser fallback: keep polling without a time limit.
        setInterval(ensure, 1000);
    }

    // Run immediately in case the sidebar is already mounted.
    scheduleEnsure();
})();
