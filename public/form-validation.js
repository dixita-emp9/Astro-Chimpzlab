(function () {
    'use strict';

    function getErrorEl(input) {
        var el = input;
        while (el && el !== input.form) {
            var err = el.querySelector(':scope > .error-msg');
            if (err) return err;
            el = el.parentElement;
        }
        return null;
    }

    function isValid(input) {
        var val = (input.value || '').trim();
        var name = input.name;

        if (input.hasAttribute('required') && !val) return false;

        if (name === 'message') {
            var min = input.getAttribute('minlength');
            if (min && val.length < parseInt(min, 10)) return false;
        }

        if (name === 'name') {
            var minName = input.getAttribute('minlength');
            if (minName && val.length < parseInt(minName, 10)) return false;
        }

        if (input.pattern) {
            var re = new RegExp(input.pattern);
            if (!re.test(val)) return false;
        }

        return true;
    }

    function validateField(input) {
        var err = getErrorEl(input);
        if (!err) return true;
        var ok = isValid(input);
        err.classList.toggle('hidden', ok);
        return ok;
    }

    function validateForm(form) {
        var valid = true;
        var firstInvalid = null;

        form.querySelectorAll('input[name="name"], input[name="email"], input[name="phone"], textarea[name="message"]').forEach(function (field) {
            var ok = validateField(field);
            if (!ok) {
                valid = false;
                if (!firstInvalid) firstInvalid = field;
            }
        });

        var captchaError = form.querySelector('#recaptchaError');
        if (captchaError) {
            var api = null;
            if (typeof grecaptcha !== 'undefined') {
                api = (typeof grecaptcha.enterprise === 'object' && grecaptcha.enterprise !== null) ? grecaptcha.enterprise : grecaptcha;
            }
            var captchaOk = !!api && typeof api.getResponse === 'function' && api.getResponse().length > 0;
            if (!captchaOk) {
                valid = false;
                captchaError.classList.remove('hidden');
            } else {
                captchaError.classList.add('hidden');
            }
        }

        if (firstInvalid) firstInvalid.focus();

        return valid;
    }

    function initForm(form) {
        if (form.getAttribute('novalidate') !== '') form.setAttribute('novalidate', '');
        if (form.dataset.validated === 'true') return;
        form.dataset.validated = 'true';

        form.addEventListener('submit', function (e) {
            if (!validateForm(form)) {
                e.preventDefault();
            }
        });

        form.querySelectorAll('input[name="name"], input[name="email"], input[name="phone"], textarea[name="message"]').forEach(function (field) {
            field.addEventListener('input', function () { validateField(field); });
            field.addEventListener('blur', function () { validateField(field); });
        });

        form.querySelectorAll('input[name="phone"]').forEach(function (field) {
            field.addEventListener('beforeinput', function (e) {
                if (e.data && /\D/.test(e.data)) {
                    e.preventDefault();
                }
            });
            field.addEventListener('input', function () {
                var cleaned = field.value.replace(/\D/g, '');
                if (field.value !== cleaned) {
                    field.value = cleaned;
                }
            });
        });
    }

    function init() {
        document.querySelectorAll('form[id="contactForm"]').forEach(initForm);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
