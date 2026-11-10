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
 *     (#appointment-picker) and the time-zone note below it (#appointment-zone-note),
 *     requested as the same address plus format=raw;
 *   - the "Next" button of a time and the "Back" link of the details (a[data-appointments-step])
 *     replace the whole step ([data-appointments-view]) with the one of the full page;
 *   - the back and forward buttons load the step of their address in the same way.
 *
 * After each change the focus moves to the new content and a live region announces it. The
 * details typed into step 2 are kept when the visitor goes back to choose another time. The
 * browser's time zone is applied once on arrival. It also turns the time-zone select into a
 * field with a searchable list and the 12/24-hour switch, the further guests into chips, and
 * shows the browser's check of the details at the fields like a refusal of the server. The
 * script writes no text of its own: everything it shows or announces comes from the
 * server-rendered page, the browser (zone names, times, validation messages) or the visitor.
 * When a request fails, it falls back to the normal page request. No inline code, no
 * third-party request.
 */
(() => {
    'use strict';

    let userInteracted = false;
    document.addEventListener('pointerdown', () => { userInteracted = true; }, { capture: true, once: true });
    document.addEventListener('keydown', () => { userInteracted = true; }, { capture: true, once: true });

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

            // Scroll only when the focused control is out of view, and to the control itself: on a
            // narrow screen the information column above it can be taller than the window.
            const box = element.getBoundingClientRect();

            if (box.top < 0 || box.bottom > window.innerHeight) {
                element.scrollIntoView({ block: element.id === 'appointment-step-title' ? 'start' : 'center' });
            }
        };

        /*
         * The time-zone field: the select of the page gives way to a button with the zone and its
         * current time, which opens a panel with a search field (ARIA combobox) over the list of
         * zones (ARIA listbox) and the switch between the 12-hour and the 24-hour clock. The list
         * is built from the options of the select; names and times of the zones come from the
         * browser (Intl). Choosing a zone changes the select, which loads the times as before.
         */
        const zoneView = (() => {
            let zones = null;
            let shown = [];
            let active = -1;
            let ticking = false;
            const language = document.documentElement.lang || undefined;
            const parts = () => {
                const box = root.querySelector('[data-appointments-zone]');

                return box ? {
                    box,
                    select: root.querySelector('#appointment-zone'),
                    button: box.querySelector('[data-appointments-zone-button]'),
                    current: box.querySelector('[data-appointments-zone-current]'),
                    now: box.querySelector('[data-appointments-zone-now]'),
                    panel: box.querySelector('[data-appointments-zone-panel]'),
                    search: box.querySelector('[data-appointments-zone-search]'),
                    list: box.querySelector('[data-appointments-zone-list]'),
                    empty: box.querySelector('[data-appointments-zone-empty]'),
                } : null;
            };
            const formats = new Map();
            const timeIn = (zone, clock) => {
                const key = zone + '|' + clock;

                try {
                    if (!formats.has(key)) {
                        formats.set(key, new Intl.DateTimeFormat(language, { timeZone: zone, hour: 'numeric', minute: '2-digit', hour12: clock === '12' }));
                    }

                    return formats.get(key).format(new Date());
                } catch (error) {
                    return '';
                }
            };
            // The name of a zone in the page language: the generic name without summer or winter time
            // ("Mitteleuropäische Zeit") where the browser knows it, else the current one.
            const zoneName = (zone, style) => {
                try {
                    const part = new Intl.DateTimeFormat(language, { timeZone: zone, timeZoneName: style })
                        .formatToParts(new Date()).find((item) => item.type === 'timeZoneName');

                    return part ? part.value : '';
                } catch (error) {
                    return '';
                }
            };
            const longName = (zone) => {
                const generic = zoneName(zone, 'longGeneric');

                // Without a name of its own the browser answers with an offset such as "GMT+05:45".
                return generic && !/^(?:GMT|UTC)[+-]/.test(generic) ? generic : zoneName(zone, 'long');
            };
            const list = (p) => {
                if (!zones) {
                    zones = Array.prototype.map.call(p.select.options, (option) => ({
                        value: option.value, text: option.text, long: longName(option.value),
                    }));
                }

                return zones;
            };
            const setActive = (p, index) => {
                const items = p.list.children;

                Array.prototype.forEach.call(items, (item, i) => item.classList.toggle('is-active', i === index));
                active = index;

                if (index >= 0 && items[index]) {
                    p.search.setAttribute('aria-activedescendant', items[index].id);
                    items[index].scrollIntoView({ block: 'nearest' });
                } else {
                    p.search.removeAttribute('aria-activedescendant');
                }
            };
            const render = (p) => {
                const query = p.search.value.trim().toLowerCase().replace(/[_/]/g, ' ');
                const clock = p.box.dataset.clock;

                shown = list(p).filter((zone) => query === ''
                    || (zone.text + ' ' + zone.value + ' ' + zone.long).toLowerCase().replace(/[_/]/g, ' ').includes(query));
                p.list.textContent = '';
                shown.forEach((zone, index) => {
                    const item = document.createElement('li');
                    const name = document.createElement('span');
                    const meta = document.createElement('span');

                    item.id = 'appointment-zone-option-' + index;
                    item.className = 'com-appointments-zone-option';
                    item.setAttribute('role', 'option');
                    item.setAttribute('aria-selected', zone.value === p.select.value ? 'true' : 'false');
                    item.dataset.value = zone.value;
                    name.className = 'com-appointments-zone-option-name';
                    name.textContent = zone.text;
                    meta.className = 'com-appointments-zone-option-meta';
                    meta.textContent = [zone.long, timeIn(zone.value, clock)].filter(Boolean).join(' · ');
                    item.append(name, meta);
                    p.list.appendChild(item);
                });
                p.empty.hidden = shown.length > 0;
                p.search.setAttribute('aria-expanded', shown.length > 0 ? 'true' : 'false');
                setActive(p, shown.length ? Math.max(0, shown.findIndex((zone) => zone.value === p.select.value)) : -1);
            };
            const refresh = () => {
                const p = parts();

                if (!p || !p.select) {
                    return;
                }

                const option = p.select.options[p.select.selectedIndex];
                const name = option ? option.text : p.select.value;
                const generic = longName(p.select.value);

                // Like a hosted scheduling page: the name of the zone in the page language, the
                // identifier of the zone (city) as the tooltip and in the list.
                p.current.textContent = generic || name;
                p.button.title = name;
                p.now.textContent = timeIn(p.select.value, p.box.dataset.clock);
                p.box.querySelectorAll('[data-clock]').forEach((button) => {
                    if (button.tagName === 'BUTTON') {
                        button.setAttribute('aria-pressed', button.dataset.clock === p.box.dataset.clock ? 'true' : 'false');
                    }
                });

                if (!p.panel.hidden) {
                    render(p);
                }
            };
            const close = (p, returnFocus) => {
                p.panel.hidden = true;
                p.button.setAttribute('aria-expanded', 'false');

                if (returnFocus) {
                    p.button.focus();
                }
            };
            const open = (p) => {
                p.panel.hidden = false;
                p.button.setAttribute('aria-expanded', 'true');
                p.search.value = '';
                render(p);
                p.search.focus();
            };
            const choose = (p, value) => {
                close(p, true);

                if (value && value !== p.select.value) {
                    p.select.value = value;
                    refresh();
                    p.select.dispatchEvent(new Event('change', { bubbles: true }));
                }
            };
            const init = () => {
                const p = parts();

                if (!p || !p.select || p.box.dataset.ready) {
                    return;
                }

                p.box.dataset.ready = '1';
                p.box.hidden = false;
                refresh();
                p.button.addEventListener('click', () => (p.panel.hidden ? open(p) : close(p, false)));
                p.search.addEventListener('input', () => render(p));
                p.search.addEventListener('keydown', (event) => {
                    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                        event.preventDefault();
                        if (shown.length) {
                            setActive(p, (active + (event.key === 'ArrowDown' ? 1 : shown.length - 1)) % shown.length);
                        }
                    } else if (event.key === 'PageDown' || event.key === 'PageUp') {
                        event.preventDefault();
                        if (shown.length) {
                            setActive(p, Math.min(shown.length - 1, Math.max(0, active + (event.key === 'PageDown' ? 8 : -8))));
                        }
                    } else if (event.key === 'Enter') {
                        event.preventDefault();
                        if (active >= 0 && shown[active]) {
                            choose(p, shown[active].value);
                        }
                    }
                });
                p.panel.addEventListener('keydown', (event) => {
                    if (event.key === 'Escape') {
                        event.preventDefault();
                        close(p, true);
                    }
                });
                p.list.addEventListener('click', (event) => {
                    const item = event.target.closest('[role="option"]');

                    if (item) {
                        choose(p, item.dataset.value);
                    }
                });
                p.box.addEventListener('focusout', (event) => {
                    if (!p.panel.hidden && event.relatedTarget && !p.box.contains(event.relatedTarget)) {
                        close(p, false);
                    }
                });
                document.addEventListener('click', (event) => {
                    if (!p.panel.hidden && !p.box.contains(event.target)) {
                        close(p, false);
                    }
                });
                p.box.querySelectorAll('button[data-clock]').forEach((button) => {
                    button.addEventListener('click', () => {
                        if (button.dataset.clock === p.box.dataset.clock) {
                            return;
                        }

                        p.box.dataset.clock = button.dataset.clock;
                        refresh();
                        // The times of the page follow the clock: the same part again, in that clock.
                        loadPart(zoneAddress(p.select.value), 'replace', null);
                    });
                });
                if (!ticking) {
                    ticking = true;
                    window.setInterval(() => {
                        const q = parts();

                        if (q && q.select) {
                            q.now.textContent = timeIn(q.select.value, q.box.dataset.clock);
                        }
                    }, 30000);
                }
            };

            return { init, refresh };
        })();

        /*
         * Further guests as chips: an address typed into the entry field becomes a chip with Enter,
         * a space, a comma or a semicolon, and when the field loses the focus; pasted text becomes
         * one chip per address. Backspace in the empty field removes the last chip. An address that
         * is no e-mail address, or one beyond the maximum, is marked. The text field of the form
         * stays the value that is sent (the addresses separated by commas) and the server checks
         * it as before. The texts (hint, "remove", "invalid") come from data attributes.
         */
        const guestsView = (form) => {
            const area = form.querySelector('[data-appointments-guests]');

            if (!area) {
                return null;
            }

            if (area.appointmentsCommit) {
                return area.appointmentsCommit;
            }

            const max = parseInt(area.dataset.max, 10) || 0;
            const field = area.closest('.com-appointments-field');
            const label = field ? field.querySelector('label[for="booking-guests"]') : null;
            const hint = form.querySelector('#booking-guests-hint');
            const box = document.createElement('div');
            const chips = document.createElement('ul');
            const entry = document.createElement('input');
            const addresses = [];
            const pattern = /^[^\s@,;<>()"]+@[^\s@,;<>()"]+\.[^\s@,;<>()".]{2,}$/;
            const text = (template, value) => (template || '').replace('%s', value);

            box.className = 'com-appointments-chips' + (area.classList.contains('is-invalid') ? ' is-invalid' : '');
            chips.className = 'com-appointments-chip-list';
            entry.className = 'form-control com-appointments-chip-entry';
            entry.id = 'booking-guests-entry';
            entry.type = 'text';
            entry.inputMode = 'email';
            entry.autocomplete = 'off';
            entry.spellcheck = false;
            ['aria-describedby', 'aria-invalid'].forEach((name) => {
                if (area.hasAttribute(name)) {
                    entry.setAttribute(name, area.getAttribute(name));
                }
            });
            box.append(chips, entry);
            area.after(box);
            area.hidden = true;
            area.tabIndex = -1;

            if (label) {
                label.htmlFor = entry.id;
                chips.setAttribute('aria-label', label.textContent.trim());
            }

            if (hint && area.dataset.hint) {
                hint.textContent = area.dataset.hint;
            }

            const invalid = (address, index) => !pattern.test(address) || (max > 0 && index >= max);
            const draw = () => {
                chips.textContent = '';
                addresses.forEach((address, index) => {
                    const chip = document.createElement('li');
                    const label = document.createElement('span');
                    const remove = document.createElement('button');
                    const bad = invalid(address, index);

                    chip.className = 'com-appointments-chip' + (bad ? ' is-invalid' : '');
                    label.className = 'com-appointments-chip-text';
                    label.textContent = address;

                    if (bad) {
                        const note = document.createElement('span');

                        note.className = 'visually-hidden';
                        note.textContent = ' ' + text(area.dataset.invalid, address);
                        label.appendChild(note);
                        chip.title = text(area.dataset.invalid, address);
                    }

                    remove.type = 'button';
                    remove.className = 'com-appointments-chip-remove';
                    remove.setAttribute('aria-label', text(area.dataset.remove, address));
                    remove.innerHTML = '<svg class="com-appointments-icon" viewBox="0 0 16 16" width="16" height="16" aria-hidden="true" focusable="false"><path d="m4 4 8 8M12 4l-8 8" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
                    remove.addEventListener('click', () => {
                        addresses.splice(index, 1);
                        draw();
                        entry.focus();
                    });
                    chip.append(label, remove);
                    chips.appendChild(chip);
                });
                area.value = addresses.join(', ');

                const first = addresses.find((address, index) => invalid(address, index));

                box.classList.toggle('is-invalid', first !== undefined || area.classList.contains('is-invalid'));
                entry.setCustomValidity(first !== undefined ? text(area.dataset.invalid, first) : '');
            };
            const commit = (value) => {
                (value || '').split(/[\s,;]+/).map((item) => item.trim()).filter(Boolean).forEach((address) => {
                    if (!addresses.some((known) => known.toLowerCase() === address.toLowerCase())) {
                        addresses.push(address);
                    }
                });
                entry.value = '';
                draw();
            };

            entry.addEventListener('keydown', (event) => {
                if ((event.key === 'Enter' || event.key === ',' || event.key === ';' || event.key === ' ') && entry.value.trim() !== '') {
                    event.preventDefault();
                    commit(entry.value);
                } else if (event.key === 'Enter') {
                    // An empty entry field never sends the booking by accident.
                    event.preventDefault();
                } else if (event.key === 'Backspace' && entry.value === '' && addresses.length) {
                    event.preventDefault();
                    addresses.pop();
                    draw();
                }
            });
            entry.addEventListener('paste', (event) => {
                const pasted = event.clipboardData ? event.clipboardData.getData('text') : '';

                if (pasted) {
                    event.preventDefault();
                    commit(entry.value + ' ' + pasted);
                }
            });
            entry.addEventListener('blur', () => commit(entry.value));
            box.addEventListener('click', (event) => {
                if (event.target === box || event.target === chips) {
                    entry.focus();
                }
            });
            commit(area.value);

            if (area.hasAttribute('autofocus')) {
                entry.focus();
            }

            area.appointmentsCommit = () => commit(entry.value);

            return area.appointmentsCommit;
        };

        /*
         * The browser's own check of the details before sending, shown like a refusal of the
         * server: the message of each invalid control below it, the control marked invalid and
         * described by the message, the focus on the first one. The message is the browser's
         * (validationMessage), so it is in the visitor's language.
         */
        const validation = (form) => {
            if (form.appointmentsCheck) {
                return form.appointmentsCheck;
            }

            form.noValidate = true;

            const clear = (element) => {
                const message = element.id ? form.querySelector('#' + CSS.escape(element.id) + '-check') : null;

                if (message) {
                    message.remove();
                }

                if (element.dataset.checked) {
                    const group = element.closest('.com-appointments-tel');

                    delete element.dataset.checked;
                    element.removeAttribute('aria-invalid');
                    element.classList.remove('is-invalid');

                    // The line of a telephone field is drawn by its group.
                    if (group && group.dataset.checked) {
                        delete group.dataset.checked;
                        group.classList.remove('is-invalid');
                    }
                    element.setAttribute('aria-describedby', (element.getAttribute('aria-describedby') || '')
                        .split(' ').filter((id) => id && id !== element.id + '-check').join(' '));

                    if (!element.getAttribute('aria-describedby')) {
                        element.removeAttribute('aria-describedby');
                    }
                }
            };
            const show = (element) => {
                const holder = element.closest('fieldset.com-appointments-field, .com-appointments-field, .form-check') || element.parentElement;
                const message = document.createElement('div');

                clear(element);
                message.id = element.id + '-check';
                message.className = 'invalid-feedback com-appointments-field-error';
                // The page's own wording for the two common cases, the browser's for the rest.
                message.textContent = (element.validity.valueMissing && form.dataset.messageRequired)
                    || (element.validity.typeMismatch && element.type === 'email' && form.dataset.messageEmail)
                    || element.validationMessage;
                holder.appendChild(message);

                if (element.getAttribute('aria-invalid') !== 'true') {
                    const group = element.closest('.com-appointments-tel');

                    element.dataset.checked = '1';
                    element.setAttribute('aria-invalid', 'true');
                    element.classList.add('is-invalid');

                    if (group && !group.classList.contains('is-invalid')) {
                        group.dataset.checked = '1';
                        group.classList.add('is-invalid');
                    }
                }

                element.setAttribute('aria-describedby', ((element.getAttribute('aria-describedby') || '') + ' ' + message.id).trim());
            };

            form.addEventListener('input', (event) => {
                if (event.target.id && event.target.validity && event.target.validity.valid) {
                    clear(event.target);
                }
            });
            form.addEventListener('change', (event) => {
                if (event.target.id && event.target.validity && event.target.validity.valid) {
                    clear(event.target);
                }
            });

            form.appointmentsCheck = () => {
                let first = null;
                const groups = new Set();

                Array.prototype.forEach.call(form.elements, (element) => {
                    if (!element.id || !element.willValidate || element.type === 'hidden') {
                        return;
                    }

                    if (element.validity.valid) {
                        clear(element);

                        return;
                    }

                    // A group of radio buttons gets one message, at its first button.
                    if (element.type === 'radio') {
                        if (groups.has(element.name)) {
                            return;
                        }

                        groups.add(element.name);
                    }

                    show(element);
                    first = first || element;
                });

                if (first) {
                    focus(first);
                }

                return first === null;
            };

            return form.appointmentsCheck;
        };

        let checkDetails = null;
        let commitGuests = null;

        const enhanceView = () => {
            const form = root.querySelector('#appointment-details-form');

            zoneView.init();
            commitGuests = null;
            checkDetails = null;

            if (form) {
                commitGuests = guestsView(form);
                checkDetails = validation(form);
            }
        };

        // Keep the controls outside the date-and-time part in step with the part just loaded.
        const sync = () => {
            const part = picker();
            const zoneSelect = root.querySelector('#appointment-zone');
            const zoneForm = root.querySelector('#appointment-zone-form');
            const layout = root.querySelector('#appointment-booking');
            const zoneBox = root.querySelector('[data-appointments-zone]');

            if (!part) {
                return;
            }

            if (layout) {
                layout.classList.toggle('com-appointments-layout-day', part.classList.contains('com-appointments-picker-day'));
            }

            if (zoneSelect && part.dataset.zone) {
                zoneSelect.value = part.dataset.zone;
            }

            if (zoneBox && part.dataset.clock) {
                zoneBox.dataset.clock = part.dataset.clock;
            }

            zoneView.refresh();

            if (zoneForm) {
                Array.prototype.slice.call(zoneForm.querySelectorAll('input[name="month"], input[name="day"], input[name="clock"]')).forEach((input) => {
                    input.remove();
                });

                const clock = zoneBox && zoneBox.dataset.clock !== zoneBox.dataset.defaultClock ? zoneBox.dataset.clock : '';

                [['month', part.dataset.month], ['day', part.dataset.day], ['clock', clock]].forEach(([name, value]) => {
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

                // The visitor's latest input wins, also over values the server shows again after a
                // refused submission: those are older than what was typed since.
                if (element.type === 'checkbox' || element.type === 'radio') {
                    element.checked = value;
                } else {
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

            // A step still loading is given up for this part (request() aborts it): the step stays usable.
            view().removeAttribute('aria-busy');

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
                    const note = page.querySelector('#appointment-zone-note');
                    const shownNote = root.querySelector('#appointment-zone-note');

                    current.replaceWith(adopted);

                    if (note && shownNote) {
                        shownNote.replaceWith(document.importNode(note, true));
                    }

                    sync();
                    write(address, history);

                    if (target) {
                        focus(target(adopted));
                    }

                    // The legend shows the day only; its full wording is the hidden text inside it.
                    announce(adopted.querySelector('.com-appointments-slots-legend-full')
                        || adopted.querySelector('.com-appointments-slots-legend, .com-appointments-empty')
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
                    enhanceView();
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
            if (form.getAttribute('id') === 'appointment-details-form') {
                // A typed guest address counts, and the browser's check comes first.
                if (commitGuests) {
                    commitGuests();
                }

                if (checkDetails && !checkDetails()) {
                    event.preventDefault();
                }

                return;
            }

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

            const zoneBox = root.querySelector('[data-appointments-zone]');

            address.searchParams.delete('start');
            address.searchParams.set('timezone', zone);

            // The clock of the switch; the addresses leave out the clock of the language.
            if (zoneBox && zoneBox.dataset.clock && zoneBox.dataset.clock !== zoneBox.dataset.defaultClock) {
                address.searchParams.set('clock', zoneBox.dataset.clock);
            } else {
                address.searchParams.delete('clock');
            }
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
        enhanceView();

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

    /*
     * The outcome of a completed form ([data-appointments-result]) takes the focus, so a screen
     * reader reads it out. The redirect lands at its fragment, which keeps the browser from
     * running the autofocus of the page; without the fragment autofocus already did it.
     */
    const focusResult = () => {
        const result = document.querySelector('[data-appointments-result]');

        if (result && (!document.activeElement || document.activeElement === document.body)) {
            // The fragment already scrolled to the container with its heading; keep that view.
            result.focus({ preventScroll: window.location.hash !== '' });
        }
    };

    const visibleSystemMessage = (container) => Array.from(container.querySelectorAll('joomla-alert, .alert'))
        .find((message) => message.textContent.trim() && message.getClientRects().length > 0);

    const focusSystemMessage = () => {
        const active = document.activeElement;
        const container = document.getElementById('system-message-container');
        const message = container && visibleSystemMessage(container);

        if (userInteracted || (active && active !== document.body && active !== document.documentElement)
            || document.visibilityState !== 'visible'
            || document.querySelector('[data-appointments-result], [autofocus], [aria-invalid="true"], [aria-modal="true"], dialog[open]')
            || !message || container.getClientRects().length === 0) {
            return;
        }

        container.setAttribute('tabindex', '-1');
        container.focus();
    };

    const watchSystemMessages = () => {
        const container = document.getElementById('system-message-container');

        if (!container) {
            return;
        }

        if (visibleSystemMessage(container)) {
            focusSystemMessage();
            return;
        }

        const observer = new MutationObserver(() => {
            if (visibleSystemMessage(container)) {
                observer.disconnect();
                window.requestAnimationFrame(focusSystemMessage);
            }
        });
        observer.observe(container, { childList: true, subtree: true });
    };

    const start = () => {
        focusResult();
        watchSystemMessages();
        document.querySelectorAll('[data-appointments-booking]').forEach(enhance);
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
