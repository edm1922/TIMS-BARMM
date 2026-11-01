<script>
(function () {
    'use strict';

    var KEY = 'tims-theme';
    var root = document.documentElement;
    var media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

    function stored() {
        try {
            var value = window.localStorage.getItem(KEY);
            return value === 'dark' || value === 'light' ? value : null;
        } catch (error) {
            return null;
        }
    }

    function preferred() {
        if (stored() !== null) {
            return stored();
        }
        return media && media.matches ? 'dark' : 'light';
    }

    function syncControls(theme) {
        var dark = theme === 'dark';

        document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
            button.setAttribute('aria-pressed', dark ? 'true' : 'false');
            button.setAttribute('aria-label', dark ? 'Switch to light theme' : 'Switch to dark theme');
            button.setAttribute('title', dark ? 'Switch to light theme' : 'Switch to dark theme');

            var label = button.querySelector('[data-theme-label]');
            if (label) {
                label.textContent = dark ? 'Light mode' : 'Dark mode';
            }
        });
    }

    function apply(theme, persist) {
        root.setAttribute('data-bs-theme', theme);
        if (persist) {
            try {
                window.localStorage.setItem(KEY, theme);
            } catch (error) {
                // Storage may be unavailable (private mode); the theme still
                // applies for this page view.
            }
        }
        syncControls(theme);
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-theme-toggle]');
        if (!button) {
            return;
        }
        apply(root.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark', true);
    });

    if (media) {
        var onSystemChange = function (event) {
            if (stored() === null) {
                apply(event.matches ? 'dark' : 'light', false);
            }
        };
        if (media.addEventListener) {
            media.addEventListener('change', onSystemChange);
        } else if (media.addListener) {
            media.addListener(onSystemChange);
        }
    }

    syncControls(root.getAttribute('data-bs-theme') || preferred());
})();
</script>
