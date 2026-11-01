{{--
    Shared modal interaction behaviour:
    - prevents accidental duplicate form submissions from any modal
    - shows an in-button spinner while the browser is submitting
    Bootstrap already provides focus trapping, ESC handling and focus restore.
--}}
<script>
(function () {
    document.addEventListener('submit', function (event) {
        var form = event.target;

        if (!(form instanceof HTMLFormElement) || !form.closest('.modal')) {
            return;
        }

        if (form.dataset.timsSubmitting === '1') {
            event.preventDefault();
            return;
        }

        var submitter = event.submitter;
        var buttons = form.querySelectorAll('button[type="submit"], input[type="submit"]');

        buttons.forEach(function (button) {
            if (button.disabled) {
                return;
            }

            if (button === submitter || !submitter) {
                button.dataset.timsLabel = button.innerHTML;
                button.classList.add('is-loading');
                button.setAttribute('aria-busy', 'true');
            }

            button.disabled = true;
        });

        form.dataset.timsSubmitting = '1';
    });

    // If the browser restores the page from the back/forward cache, undo the
    // loading state so the form can be used again.
    window.addEventListener('pageshow', function () {
        document.querySelectorAll('.modal form[data-tims-submitting], form.modal-content[data-tims-submitting]').forEach(function (form) {
            delete form.dataset.timsSubmitting;

            form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(function (button) {
                button.disabled = false;
                button.classList.remove('is-loading');
                button.removeAttribute('aria-busy');

                if (button.dataset.timsLabel) {
                    button.innerHTML = button.dataset.timsLabel;
                    delete button.dataset.timsLabel;
                }
            });
        });
    });
})();
</script>
