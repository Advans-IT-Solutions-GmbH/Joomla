/**
 * @package     Appointments Component
 *
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * Progressive enhancement of the booking page. Without this script every day, month and
 * time-zone change is a normal page request that lands at #appointment-picker again.
 * With it, only the date-and-time part (#appointment-picker) is loaded in the background
 * from the same address plus format=raw and replaced in place, so the page neither
 * reloads nor scrolls and the details already typed stay untouched. The browser's time
 * zone is applied once on arrival. The script writes no text of its own: everything it
 * shows comes from the server-rendered part. When a request fails, it falls back to the
 * normal page request. No inline code, no third-party request.
 */
(() => {
    'use strict';

    const enhance = (root) => {
        let picker = root.querySelector('[data-appointments-picker]');

        if (!picker || !window.fetch || !window.DOMParser || !window.URL || !window.AbortController) {
            return;
        }

        const details = root.querySelector('#appointment-details');
        const zoneSelect = root.querySelector('#appointment-zone');
        const zoneForm = root.querySelector('#appointment-zone-form');
        const status = document.createElement('p');
        let pending = null;

        root.classList.add('com-appointments-js');
        status.className = 'visually-hidden com-appointments-status';
        status.setAttribute('aria-live', 'polite');
        root.appendChild(status);

        // Keep the other controls in step with the part that was just loaded.
        const sync = () => {
            if (details) {
                details.hidden = picker.dataset.hasSlots !== '1';
            }

            if (zoneSelect && picker.dataset.zone) {
                zoneSelect.value = picker.dataset.zone;
            }

            if (zoneForm && zoneForm.elements.month && picker.dataset.month) {
                zoneForm.elements.month.value = picker.dataset.month;
            }
        };

        // focus: selector of the control that gets the focus back after the update, or ''.
        // remember: write the address of the shown day into the history entry (day and month links).
        const load = (href, focus, remember) => {
            const page = new URL(href, window.location.href);
            const part = new URL(page.href);
            const request = new AbortController();

            part.hash = '';
            part.searchParams.set('format', 'raw');

            if (pending) {
                pending.abort();
            }

            pending = request;
            picker.setAttribute('aria-busy', 'true');
            // Hold the current height while loading, so nothing below moves twice.
            picker.style.minHeight = picker.offsetHeight + 'px';

            return fetch(part.href, {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                signal: request.signal,
            })
                .then((response) => {
                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status);
                    }

                    return response.text();
                })
                .then((html) => {
                    const fresh = new DOMParser().parseFromString(html, 'text/html').querySelector('[data-appointments-picker]');

                    if (!fresh) {
                        throw new Error('Unexpected response');
                    }

                    const adopted = document.importNode(fresh, true);

                    picker.replaceWith(adopted);
                    picker = adopted;
                    sync();

                    if (remember && window.history && window.history.replaceState) {
                        window.history.replaceState(window.history.state, '', page.href);
                    }

                    const target = (focus && picker.querySelector(focus + ':not(.disabled)'))
                        || picker.querySelector('.com-appointments-calendar-next');

                    if (target && focus) {
                        target.focus({ preventScroll: true });
                    }

                    const summary = picker.querySelector('.com-appointments-slots-legend, .com-appointments-empty');

                    status.textContent = summary ? summary.textContent.trim() : '';
                })
                .catch((error) => {
                    if (error.name !== 'AbortError') {
                        window.location.assign(page.href);
                    }
                })
                .finally(() => {
                    if (pending === request) {
                        pending = null;
                        picker.removeAttribute('aria-busy');
                        picker.style.minHeight = '';
                    }
                });
        };

        root.addEventListener('click', (event) => {
            const link = event.target.closest('a[data-appointments-load]');

            if (!link || !root.contains(link) || event.defaultPrevented || event.button !== 0
                || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return;
            }

            event.preventDefault();

            let focus = '.com-appointments-day-selected';

            if (link.classList.contains('com-appointments-calendar-prev')) {
                focus = '.com-appointments-calendar-prev';
            } else if (link.classList.contains('com-appointments-calendar-next')) {
                focus = '.com-appointments-calendar-next';
            }

            load(link.href, focus, true);
        });

        const zoneAddress = (zone) => {
            const address = new URL(zoneForm.action, window.location.href);

            Array.prototype.forEach.call(zoneForm.elements, (element) => {
                if (element.name && element.type === 'hidden') {
                    address.searchParams.set(element.name, element.value);
                }
            });
            address.searchParams.set('timezone', zone);

            return address.href;
        };

        if (zoneSelect && zoneForm) {
            zoneSelect.addEventListener('change', () => load(zoneAddress(zoneSelect.value), '', false));

            // The visitor's own zone, unless the address already names one (a chosen zone,
            // a day link or a refused submission keep theirs).
            let browserZone = '';

            try {
                browserZone = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
            } catch (error) {
                browserZone = '';
            }

            const known = Array.prototype.some.call(zoneSelect.options, (option) => option.value === browserZone);

            if (known && browserZone !== picker.dataset.zone && !new URL(window.location.href).searchParams.has('timezone')) {
                load(zoneAddress(browserZone), '', false);
            }
        }
    };

    const start = () => document.querySelectorAll('[data-appointments-booking]').forEach(enhance);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
