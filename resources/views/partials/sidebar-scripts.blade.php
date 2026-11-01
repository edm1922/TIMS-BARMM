<script>
(function () {
    'use strict';

    var KEY = 'tims-sidebar';
    var root = document.documentElement;
    var toggle = document.getElementById('sidebarToggle');
    var label = toggle ? toggle.querySelector('[data-sidebar-label]') : null;
    var navLinks = Array.prototype.slice.call(document.querySelectorAll('#appSidebar .app-nav .nav-link'));

    function collapsed() {
        return root.getAttribute('data-sidebar') === 'collapsed';
    }

    function syncToggle() {
        if (!toggle) {
            return;
        }

        var isCollapsed = collapsed();
        toggle.setAttribute('aria-expanded', isCollapsed ? 'false' : 'true');
        toggle.setAttribute('aria-label', isCollapsed ? 'Expand sidebar' : 'Collapse sidebar');
        toggle.setAttribute('title', isCollapsed ? 'Expand sidebar' : 'Collapse sidebar');

        if (label) {
            label.textContent = isCollapsed ? 'Expand' : 'Collapse';
        }
    }

    function syncLinkHints() {
        navLinks.forEach(function (link) {
            var text = link.textContent.trim().replace(/\s+/g, ' ');
            link.setAttribute('data-label', text);

            if (collapsed()) {
                link.setAttribute('title', text);
            } else {
                link.removeAttribute('title');
            }
        });
    }

    function apply(isCollapsed, persist) {
        if (isCollapsed) {
            root.setAttribute('data-sidebar', 'collapsed');
        } else {
            root.removeAttribute('data-sidebar');
        }

        if (persist) {
            try {
                window.localStorage.setItem(KEY, isCollapsed ? 'collapsed' : 'expanded');
            } catch (error) {
                // Storage unavailable: state still applies for this page.
            }
        }

        syncToggle();
        syncLinkHints();
    }

    if (toggle) {
        toggle.addEventListener('click', function () {
            apply(!collapsed(), true);
        });
    }

    navLinks.forEach(function (link) {
        if (link.classList.contains('active') && !link.hasAttribute('aria-current')) {
            link.setAttribute('aria-current', 'page');
        }
    });

    // On mobile the sidebar is an off-canvas drawer: close it after a link is
    // selected so the destination page is immediately visible.
    document.querySelectorAll('#appSidebar .app-nav .nav-link').forEach(function (link) {
        link.addEventListener('click', function () {
            if (window.innerWidth >= 992 || !window.bootstrap) {
                return;
            }

            var drawer = document.getElementById('appSidebar');
            var instance = drawer ? bootstrap.Offcanvas.getInstance(drawer) : null;
            if (instance) {
                instance.hide();
            }
        });
    });

    // Sync decorative labels/tooltips on load and when crossing breakpoints.
    apply(collapsed(), false);
})();
</script>
