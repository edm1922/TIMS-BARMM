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

    // ------- Auto-save draft to localStorage (begin) -------
    var draftStatus = function () {
        var div = form.querySelector('.tims-draft-status');
        if (!div) {
            div = document.createElement('div');
            div.className = 'tims-draft-status small mt-2';
            var firstGroup = form.querySelector('.form-group');
            if (firstGroup) {
                form.insertBefore(div, firstGroup);
            } else {
                form.prepend(div);
            }
        }
        return div;
    }();

    // The user id is injected by the view as a data attribute; static JS files
    // under public/ are served without PHP processing, so no PHP may appear here.
    var draftKey = 'tims-barmm-beneficiary-draft-' + (form.getAttribute('data-draft-user') || 'anonymous');

    var debounce = function (fn, wait) {
        var timeout = null;
        return function () {
            var context = this, args = arguments;
            clearTimeout(timeout);
            timeout = setTimeout(function () {
                fn.apply(context, args);
            }, wait);
        };
    };

    var saveDraft = function () {
        try {
            var data = {};

            var getVal = function (sel) {
                var el = form.querySelector(sel);
                if (!el) return '';
                if (el.type === 'checkbox') return el.checked ? 'on' : '';
                if (el.type === 'radio') {
                    var radios = form.querySelectorAll('[name="' + el.name + '"]');
                    for (var i = 0; i < radios.length; i++) {
                        if (radios[i].checked) return radios[i].value;
                    }
                    return '';
                }
                if (el.type === 'select-one') return el.value;
                return el.value || '';
            };

            data.first_name = getVal('#first_name') || '';
            data.middle_name = getVal('#middle_name') || '';
            data.last_name = getVal('#last_name') || '';
            data.extension_name = getVal('select[name="extension_name"]') || '';
            data.birthdate = getVal('[data-birthdate]') || '';
            data.sex = getVal('select[name="sex"]') || '';
            data.civil_status = getVal('select[name="civil_status"]') || '';
            data.barangay = getVal('input[name="barangay"]') || '';
            data.city_municipality = getVal('input[name="city_municipality"]') || '';
            data.province = getVal('input[name="province"]') || '';
            data.district = getVal('input[name="district"]') || '';
            data.address = getVal('input[name="address"]') || '';
            data.id_type = getVal('select[name="id_type"]') || '';
            data.id_type_other = getVal('input[name="id_type_other"]') || '';
            data.source_id_no = getVal('input[name="source_id_no"]') || '';
            data.cos_no = getVal('input[name="cos_no"]') || '';
            data.contact_no = getVal('input[name="contact_no"]') || '';
            data.dependent_name = getVal('input[name="dependent_name"]') || '';
            data.dependent_contact_no = getVal('input[name="dependent_contact_no"]') || '';
            data.beneficiary_type = getVal('select[name="beneficiary_type"]') || '';
            data.beneficiary_signify = getVal('select[name="beneficiary_signify"]') || '';
            data.occupation = getVal('select[name="occupation"]') || '';
            data.occupation_other = getVal('input[name="occupation_other"]') || '';
            data.avg_monthly_income = getVal('input[name="avg_monthly_income"]') || '';
            data.skills_training_needed = getVal('input[name="skills_training_needed"]') || '';

            localStorage.setItem(draftKey(), JSON.stringify(data));
            draftStatus().textContent = 'Draft saved on this device';
            draftStatus().className = 'tims-draft-status small mt-2 text-success';
        } catch (e) {
            draftStatus().textContent = 'Could not save draft locally';
            draftStatus().className = 'tims-draft-status small mt-2 text-danger';
        }
    };

    var debouncedSave = debounce(saveDraft, 300);

    form.querySelectorAll('input, select, textarea').forEach(function (el) {
        el.addEventListener('change', debouncedSave);
        el.addEventListener('input', debouncedSave);
    });

    window.addEventListener('beforeunload', function () {
        try {
            saveDraft();
        } catch (e) {}
    });

    var restoreDraft = function () {
        try {
            var stored = localStorage.getItem(draftKey());
            if (!stored) return;

            var data = JSON.parse(stored);

            var setVal = function (sel, val) {
                var el = form.querySelector(sel);
                if (!el) return;
                if (el.type === 'checkbox') {
                    el.checked = (val === 'on');
                    return;
                }
                if (el.type === 'radio') {
                    var radios = form.querySelectorAll('[name="' + el.name + '"]');
                    for (var i = 0; i < radios.length; i++) {
                        if (radios[i].value === val) {
                            radios[i].checked = true;
                            return;
                        }
                    }
                    return;
                }
                if (el.type === 'select-one') {
                    el.value = val;
                    return;
                }
                if (el.tagName === 'INPUT' && el.type === 'date') {
                    el.value = val ? val : '';
                    return;
                }
                el.value = val !== undefined && val !== null ? val : '';
            };

            setVal('#first_name', data.first_name);
            setVal('#middle_name', data.middle_name);
            setVal('#last_name', data.last_name);
            setVal('select[name="extension_name"]', data.extension_name);
            setVal('[data-birthdate]', data.birthdate);
            setVal('select[name="sex"]', data.sex);
            setVal('select[name="civil_status"]', data.civil_status);
            setVal('input[name="barangay"]', data.barangay);
            setVal('input[name="city_municipality"]', data.city_municipality);
            setVal('input[name="province"]', data.province);
            setVal('input[name="district"]', data.district);
            setVal('input[name="address"]', data.address);
            setVal('select[name="id_type"]', data.id_type);
            setVal('input[name="id_type_other"]', data.id_type_other);
            setVal('input[name="source_id_no"]', data.source_id_no);
            setVal('input[name="cos_no"]', data.cos_no);
            setVal('input[name="contact_no"]', data.contact_no);
            setVal('input[name="dependent_name"]', data.dependent_name);
            setVal('input[name="dependent_contact_no"]', data.dependent_contact_no);
            setVal('select[name="beneficiary_type"]', data.beneficiary_type);
            setVal('select[name="beneficiary_signify"]', data.beneficiary_signify);
            setVal('select[name="occupation"]', data.occupation);
            setVal('input[name="occupation_other"]', data.occupation_other);
            setVal('input[name="avg_monthly_income"]', data.avg_monthly_income);
            setVal('input[name="skills_training_needed"]', data.skills_training_needed);

            draftStatus().textContent = 'Connection restored — your draft is safe';
            draftStatus().className = 'tims-draft-status small mt-2 text-success';
            localStorage.removeItem(draftKey());
        } catch (e) {
            draftStatus().textContent = 'Could not restore draft';
            draftStatus().className = 'tims-draft-status small mt-2 text-danger';
        }
    };

    setTimeout(restoreDraft, 250);
    // ---------- Auto-save draft (end) -----------

    // Philippine phone validation. Server-side validation is authoritative;
    // this gives the encoder immediate feedback while typing/leaving the field.
    var phoneMessage = 'Enter a valid Philippine phone number, for example 0917 123 4567 or (02) 8888 8888.';
    var phoneHintDefault = 'Format: 0917 123 4567, +63 917 123 4567, or (02) 8888 8888.';

    var normalizePhone = function (value) {
        return String(value || '').replace(/[\s\-().]+/g, '');
    };

    var isValidPhone = function (value) {
        var number = normalizePhone(value);

        if (number.indexOf('+63') === 0) {
            number = '0' + number.slice(3);
        } else if (number.indexOf('63') === 0) {
            number = '0' + number.slice(2);
        } else if (/^9\d{9}$/.test(number)) {
            number = '0' + number;
        }

        return /^09\d{9}$/.test(number) || /^0[2-8]\d{8,9}$/.test(number);
    };

    var validatePhone = function (input, force) {
        var value = input.value.trim();
        var digits = normalizePhone(value).replace(/\D/g, '');

        var reset = function () {
            input.setCustomValidity('');
            input.classList.remove('is-invalid');

            if (hint) {
                hint.textContent = phoneHintDefault;
                hint.classList.remove('text-danger', 'text-success');
            }
        };

        if (value === '' || (!force && digits.length < 9)) {
            reset();
            return true;
        }

        var valid = isValidPhone(value);
        input.setCustomValidity(valid ? '' : phoneMessage);
        input.classList.toggle('is-invalid', !valid);

        if (hint) {
            hint.textContent = valid ? phoneHintDefault : phoneMessage;
            hint.classList.toggle('text-danger', !valid);
            hint.classList.toggle('text-success', valid);
        }

        return valid;
    };

    form.querySelectorAll('[data-ph-phone]').forEach(function (input) {
        input.addEventListener('blur', function () {
            validatePhone(input, true);
        });
        input.addEventListener('input', function () {
            validatePhone(input, false);
        });
    });

    form.addEventListener('submit', function (event) {
        var firstInvalid = null;

        form.querySelectorAll('[data-ph-phone]').forEach(function (input) {
            if (!validatePhone(input, true) && firstInvalid === null) {
                firstInvalid = input;
            }
        });

        if (firstInvalid !== null) {
            event.preventDefault();
            firstInvalid.focus();
            firstInvalid.reportValidity();
        }
    });

    // Government ID No. live masking: when the selected ID type has a known
    // format, separators are inserted automatically as the operator types —
    // no manual "-" needed. Masks come from the server as a single source of
    // truth (window.TIMS_ID_MASKS), so the stored value matches what the
    // server-side normalization would produce anyway.
    var idTypeSelect = form.querySelector('#id_type');
    var idNumberInput = form.querySelector('#source_id_no');

    var applyIdMask = function () {
        if (!idTypeSelect || !idNumberInput) {
            return;
        }

        var mask = (window.TIMS_ID_MASKS || {})[idTypeSelect.value];

        if (!mask) {
            return; // free-form types (OTHERS, UMID, …) keep what is typed
        }

        var cleaned = (idNumberInput.value || '').replace(new RegExp(mask.pattern, 'gi'), '');

        if (mask.uppercase) {
            cleaned = cleaned.toUpperCase();
        }

        var parts = [];
        var position = 0;

        mask.groups.forEach(function (size) {
            if (position >= cleaned.length) {
                return;
            }

            parts.push(cleaned.substring(position, position + size));
            position += size;
        });

        if (position < cleaned.length) {
            parts.push(cleaned.substring(position));
        }

        var formatted = parts.join('-');

        if (formatted !== idNumberInput.value) {
            idNumberInput.value = formatted;
        }
    };

    if (idTypeSelect && idNumberInput) {
        idTypeSelect.addEventListener('change', applyIdMask);
        idNumberInput.addEventListener('input', applyIdMask);
        idNumberInput.addEventListener('blur', applyIdMask);
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