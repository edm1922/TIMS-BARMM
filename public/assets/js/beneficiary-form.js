document.addEventListener('DOMContentLoaded', function () {
    // Live uppercase for every text field marked data-uppercase (main form and
    // the duplicate-check modal share the same behaviour).
    document.querySelectorAll('[data-uppercase]').forEach(function (input) {
        input.addEventListener('input', function () {
            var start = input.selectionStart;
            input.value = input.value.toUpperCase();
            if (start !== null) {
                input.setSelectionRange(start, start);
            }
        });
    });

    var form = document.getElementById('beneficiaryForm');
    if (!form) {
        return;
    }

    // Conditional OTHER / OTHERS detail fields.
    form.querySelectorAll('[data-other-for]').forEach(function (wrapper) {
        var field = wrapper.getAttribute('data-other-for');
        var select = form.querySelector('[name="' + field + '"]');
        if (!select) {
            return;
        }

        var toggle = function () {
            var isOther = select.value === 'OTHER' || select.value === 'OTHERS';
            wrapper.classList.toggle('d-none', !isOther);
        };

        select.addEventListener('change', toggle);
        toggle();
    });

    // Live computed age from birthdate.
    var birthdate = form.querySelector('[data-birthdate]');
    var ageDisplay = form.querySelector('[data-age-display]');
    if (birthdate && ageDisplay) {
        var updateAge = function () {
            if (!birthdate.value) {
                ageDisplay.value = '';
                return;
            }

            var born = new Date(birthdate.value);
            var today = new Date();
            var age = today.getFullYear() - born.getFullYear();
            var monthDiff = today.getMonth() - born.getMonth();
            if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < born.getDate())) {
                age--;
            }
            ageDisplay.value = age >= 0 ? age : '';
        };

        birthdate.addEventListener('input', updateAge);
        birthdate.addEventListener('change', updateAge);
        updateAge();
    }
});
