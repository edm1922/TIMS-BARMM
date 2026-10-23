/**
 * Pre-registration verification, required before registration. The modal cannot
 * be dismissed: the operator enters the name/birthdate and presses Verify. When
 * no match exists registration proceeds automatically; when a match is found
 * registration is blocked. It never merges — the server re-checks on save.
 */
document.addEventListener('DOMContentLoaded', function () {
    var modalEl = document.getElementById('duplicateCheckModal');
    if (!modalEl) {
        return;
    }

    var form = document.getElementById('duplicateCheckForm');
    var endpoint = form.getAttribute('data-endpoint');
    var alertBox = document.getElementById('duplicateCheckAlert');
    var resultBox = document.getElementById('duplicateCheckResults');
    var errorBox = document.getElementById('duplicateCheckErrors');
    var verify = document.getElementById('duplicateCheckVerify');
    var mainForm = document.getElementById('beneficiaryForm');
    var modal = bootstrap.Modal.getOrCreateInstance(modalEl);

    function field(name) {
        return form.querySelector('[name="' + name + '"]');
    }

    function unlockRegistration() {
        if (!mainForm) {
            return;
        }
        var flag = mainForm.querySelector('#duplicateCheckDone');
        var submit = mainForm.querySelector('#beneficiarySubmit');
        if (flag) {
            flag.value = '1';
        }
        if (submit) {
            submit.disabled = false;
        }
    }

    function carry() {
        if (!mainForm) {
            return;
        }
        ['first_name', 'middle_name', 'last_name', 'birthdate', 'extension_name'].forEach(function (name) {
            var source = field(name);
            var target = mainForm.querySelector('[name="' + name + '"]');
            if (source && target) {
                if (name === 'birthdate' || name === 'extension_name') {
                    target.value = source.value;
                } else {
                    target.value = source.value.toUpperCase();
                }

                // Programmatic value changes don't fire `change`; notify the
                // form script so the computed age updates.
                if (name === 'birthdate') {
                    target.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }
        });
    }

    function reset() {
        alertBox.classList.add('d-none');
        alertBox.className = 'alert d-none';
        resultBox.innerHTML = '';
        errorBox.classList.add('d-none');
        errorBox.innerHTML = '';
        verify.disabled = false;
    }

    function showErrors(errors) {
        var messages = [];
        Object.keys(errors).forEach(function (key) {
            errors[key].forEach(function (message) { messages.push(message); });
        });
        errorBox.innerHTML = messages.join('<br>');
        errorBox.classList.remove('d-none');
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        reset();
        verify.disabled = true;

        var body = new URLSearchParams(new FormData(form)).toString();

        fetch(endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': form.querySelector('[name="_token"]').value,
            },
            credentials: 'same-origin',
            body: body,
        })
            .then(function (response) {
                return response.json().then(function (data) {
                    return { ok: response.ok, data: data };
                });
            })
            .then(function (result) {
                if (!result.ok) {
                    showErrors(result.data.errors || { message: ['Unable to verify right now.'] });
                    verify.disabled = false;

                    return;
                }

                var data = result.data;

                if (data.duplicate) {
                    // Hard block: keep the registration form locked.
                    alertBox.className = 'alert alert-danger';
                    alertBox.innerHTML = '<strong>Duplicate found:</strong> ' + data.rule_label +
                        '. Registration is blocked until the matching record(s) below are reviewed.';
                    alertBox.classList.remove('d-none');

                    resultBox.innerHTML = '<ul class="mb-0 small">' + data.matches.map(function (match) {
                        return '<li><a class="font-monospace" href="' + match.url + '" target="_blank">' +
                            match.beneficiary_code + '</a> — ' + match.full_name +
                            (match.birthdate ? ', b. ' + match.birthdate : '') + '</li>';
                    }).join('') + '</ul>';

                    return;
                }

                // No match: confirm and proceed automatically.
                alertBox.className = 'alert alert-success';
                alertBox.textContent = 'No duplication — proceeding to registration.';
                alertBox.classList.remove('d-none');

                carry();
                unlockRegistration();
                modal.hide();
            })
            .catch(function () {
                showErrors({ message: ['Unable to verify right now.'] });
                verify.disabled = false;
            });
    });

    var openButton = document.getElementById('duplicateCheckOpen');
    if (openButton) {
        openButton.addEventListener('click', function () {
            modal.show();
        });
    }

    // Always require verification: open for a fresh registration, or after a
    // server-side validation error (the gate must be re-run).
    modal.show();

    var cancelButton = modalEl.querySelector('.modal-footer .btn-outline-secondary');
    if (cancelButton && mainForm) {
        cancelButton.addEventListener('click', function () {
            window.location.href = mainForm.querySelector('a.btn-outline-secondary').href || '/beneficiaries';
        });
    }
});