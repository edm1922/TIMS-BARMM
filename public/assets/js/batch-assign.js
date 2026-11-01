/**
 * Batch membership assignment ("Assign existing beneficiaries").
 *
 * The picker lists unassigned beneficiaries; a free-text filter narrows the
 * rendered options, and the submit button blocks a double submission so an
 * impatient click cannot assign a person twice. No libraries, matching the
 * rest of the offline UI.
 */
(function () {
    'use strict';

    var form = document.getElementById('batch-assign-form');

    if (!form) {
        return;
    }

    /* -- Filter the option list as the reviewer types ---------------------- */
    var search = document.getElementById('assign-search');
    var select = document.getElementById('assign-select');

    if (search && select) {
        search.addEventListener('input', function () {
            var needle = search.value.trim().toUpperCase();

            Array.prototype.forEach.call(select.options, function (option) {
                option.hidden = needle !== '' && option.text.toUpperCase().indexOf(needle) === -1;
            });
        });
    }

    /* -- Guard against a double submission --------------------------------- */
    form.addEventListener('submit', function () {
        if (select && select.selectedOptions.length === 0) {
            return;
        }

        var button = form.querySelector('[data-assign-submit]');

        if (button) {
            button.disabled = true;
            button.textContent = 'Assigning…';
        }
    });
})();
