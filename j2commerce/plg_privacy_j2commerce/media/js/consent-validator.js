/**
 * Consent checkbox validation for J2Commerce checkout.
 * Reads the error message from a data attribute to avoid inline scripts (CSP).
 *
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
document.addEventListener('DOMContentLoaded', function () {
    var validator = document.getElementById('j2commerce-consent-validator');
    if (!validator) {
        return;
    }

    var errorMessage = validator.dataset.error || '';

    var forms = document.querySelectorAll('form[action*="j2store"], form.j2store-checkout-form');
    forms.forEach(function (form) {
        form.addEventListener('submit', function (e) {
            var consent = document.getElementById('j2commerce_privacy_consent');
            if (consent && !consent.checked) {
                e.preventDefault();
                alert(errorMessage);
                consent.focus();
            }
        });
    });
});
