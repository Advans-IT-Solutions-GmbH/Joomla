/**
 * Consent checkbox validation for J2Commerce checkout.
 * Reads the error message from a data attribute to avoid inline scripts (CSP).
 */
/**
 * Blocks the step while a required consent checkbox is unticked.
 * Returns true when the click/submit was blocked.
 */
function j2commercePrivacyBlocked(e) {
    var validator = document.getElementById('j2commerce-consent-validator');
    if (!validator) {
        return false;
    }

    var consent = document.getElementById('j2commerce_privacy_consent');
    if (!consent || consent.checked) {
        return false;
    }

    e.preventDefault();
    alert(validator.dataset.error || '');
    consent.focus();

    return true;
}

// J2Store 4 does not submit a form: its checkout script posts the step on a click
// on #button-payment-method, and it has no server-side consent check. The guard
// therefore runs in the capture phase on the document, before that handler, and
// also covers steps that are loaded into the page later by AJAX.
if (!window.j2commercePrivacyClickGuard) {
    window.j2commercePrivacyClickGuard = true;

    document.addEventListener('click', function (e) {
        var target = e.target;
        var button = target && target.closest ? target.closest('#button-payment-method') : null;
        if (!button) {
            return;
        }

        if (j2commercePrivacyBlocked(e)) {
            e.stopPropagation();
            if (typeof e.stopImmediatePropagation === 'function') {
                e.stopImmediatePropagation();
            }
        }
    }, true);
}

document.addEventListener('DOMContentLoaded', function () {
    var validator = document.getElementById('j2commerce-consent-validator');
    if (!validator) {
        return;
    }

    var forms = document.querySelectorAll('form[action*="j2store"], form.j2store-checkout-form');
    forms.forEach(function (form) {
        form.addEventListener('submit', function (e) {
            j2commercePrivacyBlocked(e);
        });
    });
});
