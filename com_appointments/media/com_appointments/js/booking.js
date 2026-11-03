/**
 * @package     Appointments Component
 *
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

/*
 * Progressive enhancement of the booking page. Without this script every step is a normal
 * page request: a day or month is a link, the chosen time is sent with its "Next" button as
 * a GET form (start=…), the details are a POST form. With it, the same addresses are loaded
 * in the background and written into the browser history, so the page neither reloads nor
 * scrolls, and the back button and a reload show the same step:
 *
 *   - a day or month (a[data-appointments-load]) replaces only the date-and-time part
 *     (#appointment-picker), requested as the same address plus format=raw;
 *   - the "Next" button of a time and the "Back" link of the details (a[data-appointments-step])
 *     replace the whole step ([data-appointments-view]) with the one of the full page;
 *   - the back and forward buttons load the step of their address in the same way.
 *
 * After each change the focus moves to the new content and a live region announces it. The
 * details typed into step 2 are kept when the visitor goes back to choose another time. The
 * browser's time zone is applied once on arrival. The script writes no text of its own:
 * everything it shows or announces comes from the server-rendered page. When a request
 * fails, it falls back to the normal page request. No inline code, no third-party request.
 */
(() => {
    'use strict';

    const enhance = (root) => {
        if (!root.querySelector('[data-appointments-view]') || !window.fetch || !window.DOMParser || !window.URL
            || !window.AbortController || !window.FormData || !window.history || !window.history.pushState) {
            return;
        }

        const status = document.createElement('p');
        const kept = new Map();
        let pending = null;

        root.classList.add('com-appointments-js');
        status.className = 'visually-hidden com-appointments-status';
        status.setAttribute('role', 'status');
        status.setAttribute('aria-live', 'polite');
        root.appendChild(status);

        const view = () => root.querySelector('[data-appointments-view]');
        const picker = () => root.querySelector('[data-appointments-picker]');
        const announce = (element) => {
            // A new text in the live region is read out; the same text twice needs a change first.
            status.textContent = '';
            window.setTimeout(() => {
                status.textContent = element ? element.textContent.replace(/\s+/g, ' ').trim() : '';
            }, 50);
        };
        const focus = (element) => {
            if (!element) {
                return;
            }

            element.focus({ preventScroll: true });

            const box = (root.querySelector('#appointment-booking') || element).getBoundingClientRect();

            // Bring the top of the booking card back into view when the step starts above it.
            if (box.top < 0) {
                (root.querySelector('#appointment-booking') || element).scrollIntoView({ block: 'start' });
            }
        };

        // Keep the controls outside the date-and-time part in step with the part just loaded.
        const sync = () => {
            const part = picker();
            const zoneSelect = root.querySelector('#appointment-zone');
            const zoneForm = root.querySelector('#appointment-zone-form');
            const layout = root.querySelector('#appointment-booking');

            if (!part) {
                return;
            }

            if (layout) {
                layout.classList.toggle('com-appointments-layout-day', part.classList.contains('com-appointments-picker-day'));
            }

            if (zoneSelect && part.dataset.zone) {
                zoneSelect.value = part.dataset.zone;
            }

            if (zoneForm) {
                Array.prototype.slice.call(zoneForm.querySelectorAll('input[name="month"], input[name="day"]')).forEach((input) => {
                    input.remove();
                });

                [['month', part.dataset.month], ['day', part.dataset.day]].forEach(([name, value]) => {
                    if (value) {
                        const input = document.createElement('input');

                        input.type = 'hidden';
                        input.name = name;
                        input.value = value;
                        zoneForm.appendChild(input);
                    }
                });
            }
        };

        // The details typed so far, so a visitor who goes back for another time keeps them.
        const remember = () => {
            const form = root.querySelector('#appointment-details-form');

            if (!form) {
                return;
            }

            kept.clear();
            Array.prototype.forEach.call(form.elements, (element) => {
                if (!element.name || !element.name.startsWith('booking[') || element.type === 'hidden'
                    || element.name === 'booking[start]' || element.name === 'booking[timezone]') {
                    return;
                }

                if (element.type === 'checkbox' || element.type === 'radio') {
                    kept.set(element.id, element.checked);
                } else {
                    kept.set(element.id, element.value);
                }
            });
        };

        const restore = () => {
            const form = root.querySelector('#appointment-details-form');

            if (!form || kept.size === 0) {
                return;
            }

            kept.forEach((value, id) => {
                const element = id ? form.querySelector('#' + CSS.escape(id)) : null;

                if (!element) {
                    return;
                }

                if (element.type === 'checkbox' || element.type === 'radio') {
                    element.checked = value;
                } else if (element.value === '' || element.tagName === 'SELECT') {
                    element.value = value;
                }
            });

            const guests = form.querySelector('.com-appointments-guests');

            if (guests && form.querySelector('#booking-guests') && form.querySelector('#booking-guests').value !== '') {
                guests.open = true;
            }
        };

        const request = (address, part) => {
            const target = new URL(address, window.location.href);
            const controller = new AbortController();

            target.hash = '';

            if (part) {
                target.searchParams.set('format', 'raw');
            }

            if (pending) {
                pending.abort();
            }

            pending = controller;

            return fetch(target.href, {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                signal: controller.signal,
            })
                .then((response) => {
                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status);
                    }

                    return response.text();
                })
                .then((html) => new DOMParser().parseFromString(html, 'text/html'))
                .finally(() => {
                    if (pending === controller) {
                        pending = null;
                    }
                });
        };

        const fail = (address) => (error) => {
            if (error.name !== 'AbortError') {
                window.location.assign(address);
            }
        };

        // history: 'push' for a new step or day, 'replace' for the same step in another zone, '' for none.
        const write = (address, history) => {
            if (history === 'push') {
                window.history.pushState({ appointments: true }, '', address);
            } else if (history === 'replace') {
                window.history.replaceState({ appointments: true }, '', address);
            }
        };

        // Only the date-and-time part: a day, a month, another time zone.
        const loadPart = (address, history, target) => {
            const current = picker();

            if (!current) {
                return loadStep(address, history);
            }

            current.setAttribute('aria-busy', 'true');
            // The times shown belong to the previous choice: they cannot be chosen or sent meanwhile.
            current.querySelectorAll('input[name="start"], .com-appointments-slot-next').forEach((input) => {
                input.disabled = true;
            });
            // Hold the current height while loading, so nothing below moves twice.
            current.style.minHeight = current.offsetHeight + 'px';

            return request(address, true)
                .then((page) => {
                    const fresh = page.querySelector('[data-appointments-picker]');

                    if (!fresh) {
                        throw new Error('Unexpected response');
                    }

                    const adopted = document.importNode(fresh, true);

                    current.replaceWith(adopted);
                    sync();
                    write(address, history);

                    if (target) {
                        focus(target(adopted));
                    }

                    announce(adopted.querySelector('.com-appointments-slots-legend, .com-appointments-empty')
                        || adopted.querySelector('.com-appointments-calendar-title'));
                })
                .catch(fail(address))
                .finally(() => {
                    const part = picker();

                    // Still the old part and no newer request running: it stays, usable again.
                    if (!pending && part && part === current) {
                        part.removeAttribute('aria-busy');
                        part.style.minHeight = '';
                        part.querySelectorAll('input[name="start"], .com-appointments-slot-next').forEach((input) => {
                            input.disabled = false;
                        });
                    }
                });
        };

        // A whole step: the time chosen, back to the times, the back and forward buttons.
        const loadStep = (address, history) => {
            const current = view();

            current.setAttribute('aria-busy', 'true');
            remember();

            return request(address, false)
                .then((page) => {
                    const fresh = page.querySelector('[data-appointments-booking] [data-appointments-view]');

                    if (!fresh) {
                        throw new Error('Unexpected response');
                    }

                    const adopted = document.importNode(fresh, true);

                    view().replaceWith(adopted);
                    sync();
                    restore();
                    write(address, history);

                    const title = adopted.querySelector('#appointment-step-title');

                    focus(title);
                    announce(title);
                })
                .catch(fail(address))
                .finally(() => {
                    if (!pending) {
                        current.removeAttribute('aria-busy');
                    }
                });
        };

        const firstOf = (selectors) => (part) => {
            for (const selector of selectors) {
                const found = part.querySelector(selector);

                if (found && found.offsetParent !== null) {
                    return found;
                }
            }

            return null;
        };

        root.addEventListener('click', (event) => {
            const link = event.target.closest('a[data-appointments-load], a[data-appointments-step]');

            if (!link || !root.contains(link) || event.defaultPrevented || event.button !== 0
                || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return;
            }

            event.preventDefault();

            if (link.hasAttribute('data-appointments-step')) {
                loadStep(link.href, 'push');

                return;
            }

            let target = firstOf(['input[name="start"]:checked', 'input[name="start"]', '#appointment-times', '.com-appointments-day-selected']);

            if (link.classList.contains('com-appointments-calendar-prev')) {
                target = firstOf(['.com-appointments-calendar-prev:not(.disabled)', '.com-appointments-calendar-next']);
            } else if (link.classList.contains('com-appointments-calendar-next')) {
                target = firstOf(['.com-appointments-calendar-next']);
            } else if (link.classList.contains('com-appointments-times-back')) {
                target = firstOf(['.com-appointments-day-available', '.com-appointments-calendar-next']);
            }

            loadPart(link.href, 'push', target);
        });

        root.addEventListener('submit', (event) => {
            const form = event.target;

            // No booking while a step is being replaced; the visitor sends again once it is shown.
            if (pending) {
                event.preventDefault();

                return;
            }

            // Attributes, not properties: the hidden fields "id" and "option" of a non-SEF address
            // shadow form.id and similar properties of the form element.
            if (form.getAttribute('id') !== 'appointment-time-form') {
                return;
            }

            event.preventDefault();

            const address = new URL(form.getAttribute('action') || '', window.location.href);

            address.search = '';
            new FormData(form).forEach((value, name) => {
                address.searchParams.append(name, value);
            });
            loadStep(address.href, 'push');
        });

        const zoneAddress = (zone) => {
            const address = new URL(window.location.href);
            const part = picker();

            address.searchParams.delete('start');
            address.searchParams.set('timezone', zone);
            address.searchParams.set('month', part ? part.dataset.month : '');

            if (part && part.dataset.day) {
                address.searchParams.set('day', part.dataset.day);
            } else {
                address.searchParams.delete('day');
            }

            address.hash = 'appointment-picker';

            return address.href;
        };

        // A zone the visitor chose is kept in the address of the page, with the day shown.
        root.addEventListener('change', (event) => {
            if (event.target.id === 'appointment-zone') {
                loadPart(zoneAddress(event.target.value), 'replace', null);
            }
        });

        // The back and forward buttons: the step of that address, loaded like any other.
        window.addEventListener('popstate', (event) => {
            if (event.state && event.state.appointments) {
                loadStep(window.location.href, '');
            }
        });

        window.history.replaceState({ appointments: true }, '', window.location.href);

        // The visitor's own zone, unless the address already names one (a chosen zone, a day
        // link or a refused submission keep theirs) or the step is past the choice of a time.
        const part = picker();
        const zoneSelect = root.querySelector('#appointment-zone');
        let browserZone = '';

        try {
            browserZone = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
        } catch (error) {
            browserZone = '';
        }

        if (part && zoneSelect && browserZone !== part.dataset.zone
            && Array.prototype.some.call(zoneSelect.options, (option) => option.value === browserZone)
            && !new URL(window.location.href).searchParams.has('timezone')) {
            // The address then names the zone, so the back button returns to the times in that zone.
            loadPart(zoneAddress(browserZone), 'replace', null);
        }
    };

    const start = () => document.querySelectorAll('[data-appointments-booking]').forEach(enhance);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
