/**
 * @package     Appointments Component
 *
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * Progressive enhancement of «My calendars»: a copy button for the app password, and a form
 * marked data-appointments-once is sent only once (a double click creates no second device).
 * Without this script the password is selected with one click (user-select: all) and the
 * server refuses a second submission on its own.
 */
(() => {
    'use strict';

    const ready = (fn) => {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    };

    ready(() => {
        document.querySelectorAll('[data-appointments-copy]').forEach((button) => {
            const source = document.getElementById(button.getAttribute('data-appointments-copy'));
            const status = button.parentElement ? button.parentElement.querySelector('[role="status"]') : null;

            if (!source || !navigator.clipboard || !window.isSecureContext) {
                return;
            }

            button.hidden = false;
            button.addEventListener('click', () => {
                navigator.clipboard.writeText(source.textContent.trim()).then(() => {
                    if (status) {
                        status.textContent = button.getAttribute('data-appointments-copied') || '';
                    }
                }, () => {
                    const range = document.createRange();
                    range.selectNodeContents(source);
                    const selection = window.getSelection();
                    selection.removeAllRanges();
                    selection.addRange(range);
                });
            });
        });

        document.querySelectorAll('form[data-appointments-once]').forEach((form) => {
            form.addEventListener('submit', (event) => {
                if (form.dataset.appointmentsSent === '1') {
                    event.preventDefault();

                    return;
                }

                form.dataset.appointmentsSent = '1';
                form.querySelectorAll('button[type="submit"]').forEach((button) => {
                    button.setAttribute('aria-disabled', 'true');
                });
            });
        });
    });

    // A page restored from the back-forward cache may be sent again.
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            document.querySelectorAll('form[data-appointments-once]').forEach((form) => {
                delete form.dataset.appointmentsSent;
                form.querySelectorAll('button[type="submit"]').forEach((button) => button.removeAttribute('aria-disabled'));
            });
        }
    });
})();
