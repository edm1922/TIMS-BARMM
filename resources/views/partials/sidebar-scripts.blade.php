<script>
(function () {
    'use strict';

    var KEY = 'tims-sidebar';
    var root = document.documentElement;
    var toggle = document.getElementById('sidebarMenuToggle');
    var drawer = document.getElementById('appSidebar');
    var desktop = window.matchMedia ? window.matchMedia('(min-width: 992px)') : null;
    var navLinks = Array.prototype.slice.call(document.querySelectorAll('#appSidebar .app-nav .nav-link'));

    function isDesktop() {
        return desktop ? desktop.matches : window.innerWidth >= 992;
    }

    function hidden() {
        return root.getAttribute('data-sidebar') === 'hidden';
    }

    function drawerOpen() {
        return drawer ? drawer.classList.contains('show') : false;
    }

    function syncToggle() {
        if (!toggle) {
            return;
        }

        var open = isDesktop() ? !hidden() : drawerOpen();
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        toggle.setAttribute('aria-label', open ? 'Close navigation' : 'Open navigation');
        toggle.setAttribute('title', open ? 'Close navigation' : 'Open navigation');
    }

    function setHidden(isHidden, persist) {
        if (isHidden) {
            root.setAttribute('data-sidebar', 'hidden');
        } else {
            root.removeAttribute('data-sidebar');
        }

        if (persist) {
            try {
                window.localStorage.setItem(KEY, isHidden ? 'hidden' : 'open');
            } catch (error) {
                // Storage unavailable: the state still applies to this page.
            }
        }

        syncToggle();
    }

    function openDrawer() {
        if (!drawer || !window.bootstrap) {
            return;
        }

        bootstrap.Offcanvas.getOrCreateInstance(drawer).show();
    }

    function closeDrawer() {
        if (!drawer || !window.bootstrap) {
            return;
        }

        var instance = bootstrap.Offcanvas.getInstance(drawer);
        if (instance) {
            instance.hide();
        }
    }

    if (toggle) {
        toggle.addEventListener('click', function (event) {
            if (isDesktop()) {
                event.preventDefault();
                setHidden(!hidden(), true);
                return;
            }

            if (drawerOpen()) {
                closeDrawer();
            } else {
                openDrawer();
            }
        });
    }

    if (drawer) {
        drawer.addEventListener('shown.bs.offcanvas', syncToggle);
        drawer.addEventListener('hidden.bs.offcanvas', syncToggle);
    }

    navLinks.forEach(function (link) {
        if (link.classList.contains('active') && !link.hasAttribute('aria-current')) {
            link.setAttribute('aria-current', 'page');
        }

        link.addEventListener('click', function () {
            if (isDesktop()) {
                return;
            }

            closeDrawer();
        });
    });

    if (desktop) {
        var onBreakpointChange = function () {
            syncToggle();
        };

        if (desktop.addEventListener) {
            desktop.addEventListener('change', onBreakpointChange);
        } else if (desktop.addListener) {
            desktop.addListener(onBreakpointChange);
        }
    }

    syncToggle();
})();
</script>
