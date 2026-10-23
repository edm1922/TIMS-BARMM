/**
 * Beneficiary batch workflow.
 *
 * The create-batch form blocks a double submission, so a batch is never
 * created twice by an impatient click. Beneficiaries are registered inside a
 * batch from the batch management view — there is no beneficiary picker
 * anymore, so nothing else is needed here.
 */
(function () {
    'use strict';

    /* -- Create batch: guard against a double submission ------------------- */
    var createForm = document.getElementById('batch-create-form');

    if (createForm) {
        createForm.addEventListener('submit', function () {
            var button = createForm.querySelector('[data-batch-submit]');

            if (button) {
                button.disabled = true;
                button.textContent = 'Creating…';
            }
        });
    }
})();
