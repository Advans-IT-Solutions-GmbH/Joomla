const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '..', 'media', 'com_appointments', 'js', 'booking.js'), 'utf8');

function page({ request = true, result = true, nativeResultFocus = false, invalid = false, modal = false,
    populated = true, interaction = false, unrelatedFocus = false, reveal = true, persisted = false,
    readyState = 'loading' } = {}) {
    const listeners = {};
    const windowListeners = {};
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
        readyState,
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
        addEventListener(name, callback) { windowListeners[name] = callback; },
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
    listeners.DOMContentLoaded?.();
    const show = () => windowListeners.pageshow?.({ persisted });
    if (reveal) {
        show();
    }
    return {
        document, status, container, field, body,
        show,
        render() { populated = true; if (!disconnected) observer?.(); },
        interact() { listeners.keydown(); },
        expire() { timeout?.(); },
        disconnected: () => disconnected,
    };
}

test('Result focus waits until fragment navigation has revealed the page', () => {
    const fixture = page({ request: false, reveal: false });
    assert.equal(fixture.document.activeElement, fixture.body);
    fixture.show();
    assert.equal(fixture.document.activeElement, fixture.status);
});

test('A cached history restore does not move focus', () => {
    const fixture = page({ request: false, persisted: true });
    assert.equal(fixture.document.activeElement, fixture.body);
});

test('Interaction before page reveal prevents result focus', () => {
    const fixture = page({ request: false, reveal: false });
    fixture.interact();
    fixture.show();
    assert.equal(fixture.document.activeElement, fixture.body);
});

test('A pointer interaction prevents result focus at page reveal', () => {
    const fixture = page({ request: false, interaction: true });
    assert.equal(fixture.document.activeElement, fixture.body);
});

test('A script loaded after pageshow handles the outcome once', () => {
    const fixture = page({ request: false, readyState: 'complete' });
    assert.equal(fixture.document.activeElement, fixture.status);
    fixture.document.activeElement = fixture.body;
    fixture.show();
    assert.equal(fixture.document.activeElement, fixture.body);
});

for (const guard of ['invalid', 'modal', 'unrelatedFocus']) {
    test(`Page reveal preserves ${guard}`, () => {
        const fixture = page({ request: false, [guard]: true, reveal: false });
        const active = fixture.document.activeElement;
        fixture.show();
        assert.equal(fixture.document.activeElement, active);
    });
}

test('Repeated page reveal does not create another message watcher', () => {
    const fixture = page({ populated: false });
    fixture.expire();
    fixture.show();
    fixture.render();
    assert.equal(fixture.document.activeElement, fixture.body);
});

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
