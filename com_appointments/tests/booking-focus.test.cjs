const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '..', 'media', 'com_appointments', 'js', 'booking.js'), 'utf8');

function page({ request = true, result = true, nativeResultFocus = false, invalid = false, modal = false,
    populated = true, interaction = false, unrelatedFocus = false } = {}) {
    const listeners = {};
    let observer;
    let disconnected = false;
    let timeout;
    const body = {};
    const status = {
        'aria-describedby': 'appointment-conference-missing',
        getAttribute(name) { return this[name]; },
        setAttribute(name, value) { this[name] = value; },
        focus() { document.activeElement = status; },
    };
    const field = {};
    const message = { textContent: 'Notification could not be delivered', getClientRects: () => [{}] };
    const container = {
        querySelectorAll: () => populated ? [message] : [],
        getClientRects: () => [{}],
        setAttribute(name, value) { this[name] = value; },
        focus() { document.activeElement = container; },
    };
    const document = {
        body,
        documentElement: {},
        activeElement: invalid ? field : unrelatedFocus ? {} : nativeResultFocus ? status : body,
        visibilityState: 'visible',
        readyState: 'loading',
        addEventListener(name, callback) { listeners[name] = callback; },
        getElementById: () => container,
        querySelector(selector) {
            if (selector === '[data-appointments-result]') {
                return result ? status : null;
            }
            if (selector === '[autofocus]:not([data-appointments-result]), [aria-invalid="true"], [aria-modal="true"], dialog[open]') {
                return invalid || modal ? field : null;
            }
            // The old guard also blocks overrides containing the updated result status.
            if (selector.includes('[data-appointments-result]')) {
                return result || invalid || modal ? status : null;
            }
            return null;
        },
        querySelectorAll: () => [],
    };
    const window = {
        Joomla: { getOptions: () => request },
        location: { hash: '#appointment-result' },
        requestAnimationFrame: (callback) => callback(),
        setTimeout(callback) { timeout = callback; },
    };
    vm.runInNewContext(source, {
        document, window,
        MutationObserver: class {
            constructor(callback) { observer = callback; }
            observe() {}
            disconnect() { disconnected = true; }
        },
    });
    if (interaction) {
        listeners.pointerdown();
    }
    listeners.DOMContentLoaded();
    return {
        document, status, container, field, body,
        render() { populated = true; observer?.(); },
        interact() { listeners.keydown(); },
        expire() { timeout?.(); },
        disconnected: () => disconnected,
    };
}

for (const nativeResultFocus of [false, true]) {
    test(`Queued override warning wins over result focus (native=${nativeResultFocus})`, () => {
        const fixture = page({ nativeResultFocus });
        assert.equal(fixture.document.activeElement, fixture.container);
        assert.equal(fixture.container.tabindex, '-1');
        assert.equal(fixture.status['aria-describedby'], 'appointment-conference-missing system-message-container');
    });
}

test('Ordinary successful result keeps focus without a warning request', () => {
    const fixture = page({ request: false });
    assert.equal(fixture.document.activeElement, fixture.status);
});

test('Legacy override without result markup focuses its queued warning', () => {
    const fixture = page({ result: false });
    assert.equal(fixture.document.activeElement, fixture.container);
});

test('Deferred Joomla rendering surfaces the override warning and disconnects', () => {
    const fixture = page({ populated: false, nativeResultFocus: true });
    fixture.render();
    assert.equal(fixture.document.activeElement, fixture.container);
    assert.equal(fixture.disconnected(), true);
});

for (const guard of ['invalid', 'modal', 'interaction', 'unrelatedFocus']) {
    test(`Queued override warning does not steal focus: ${guard}`, () => {
        const fixture = page({ [guard]: true });
        assert.notEqual(fixture.document.activeElement, fixture.container);
    });
}

test('Interaction cancels the pending observer', () => {
    const fixture = page({ populated: false });
    fixture.interact();
    fixture.render();
    assert.notEqual(fixture.document.activeElement, fixture.container);
    assert.equal(fixture.disconnected(), true);
});

test('A control focused before deferred rendering is not replaced', () => {
    const fixture = page({ populated: false });
    const control = {};
    fixture.document.activeElement = control;
    fixture.render();
    assert.equal(fixture.document.activeElement, control);
    assert.equal(fixture.disconnected(), true);
});

test('An unrelated notice without the explicit option never takes focus', () => {
    const fixture = page({ request: false, result: false });
    assert.equal(fixture.document.activeElement, fixture.body);
});

test('An empty message watch expires', () => {
    const fixture = page({ populated: false });
    fixture.expire();
    assert.equal(fixture.disconnected(), true);
});
