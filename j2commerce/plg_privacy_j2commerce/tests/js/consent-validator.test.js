/**
 * Executable DOM test for media/js/consent-validator.js (no npm dependencies).
 *
 * The PHP render test (11-consent-ui-render.php) can only inspect the validator
 * source statically. This test actually RUNS the script against a minimal DOM
 * that models the one behaviour that matters for J2Store 4: the capture-phase
 * click guard on #button-payment-method. J2Store 4 has no server-side consent
 * check, so this client guard is the only enforcement, and a click on that
 * button (not a form submit) is what triggers the step. The test proves the
 * guard actually blocks the click before J2Store's own handler runs while a
 * required consent box is unticked, and lets it through once ticked.
 *
 * Run: node consent-validator.test.js  (exit code 0 = pass, 1 = fail)
 */
'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

const SCRIPT = path.resolve(__dirname, '../../media/js/consent-validator.js');
const source = fs.readFileSync(SCRIPT, 'utf8');

let failures = 0;
function assert(name, cond) {
    if (cond) {
        console.log('  PASS ' + name);
    } else {
        failures++;
        console.log('  FAIL ' + name);
    }
}

// ── Minimal DOM that models capture-phase click dispatch ────────────────────
// parentElement models the real ancestor chain: a click inside the Continue
// button has one of its children (text span, icon) as e.target, so the guard has
// to find the button through closest() walking upwards.
function makeElement(id, extra) {
    return Object.assign({
        id: id,
        dataset: {},
        checked: false,
        parentElement: null,
        focus() {},
        closest(sel) {
            for (let node = this; node; node = node.parentElement) {
                if (sel === '#' + node.id) {
                    return node;
                }
            }
            return null;
        },
    }, extra || {});
}

function makeDom(elements) {
    const captureClickListeners = [];
    const byId = {};
    for (const el of elements) {
        byId[el.id] = el;
    }
    const document = {
        getElementById(id) { return byId[id] || null; },
        querySelectorAll() { return []; },
        addEventListener(type, fn, useCapture) {
            if (type === 'click' && useCapture === true) {
                captureClickListeners.push(fn);
            }
            // DOMContentLoaded and other listeners are irrelevant to this test.
        },
    };
    return { document, captureClickListeners };
}

function makeClickEvent(target) {
    let defaultPrevented = false;
    let immediateStopped = false;
    let propagationStopped = false;
    return {
        type: 'click',
        target: target,
        preventDefault() { defaultPrevented = true; },
        stopPropagation() { propagationStopped = true; },
        stopImmediatePropagation() { immediateStopped = true; },
        get defaultPrevented() { return defaultPrevented; },
        get immediateStopped() { return immediateStopped; },
        get propagationStopped() { return propagationStopped; },
    };
}

// Load a fresh copy of the guard against the given DOM and dispatch a click on
// the target. Returns whether the click was blocked and whether it would have
// reached J2Store's own button handler.
function runScenario(elements, targetId) {
    const dom = makeDom(elements);
    const alertCalls = [];
    const sandbox = {
        document: dom.document,
        window: {},
        alert(msg) { alertCalls.push(msg); },
    };
    vm.createContext(sandbox);
    vm.runInContext(source, sandbox, { filename: 'consent-validator.js' });

    const target = elements.find((el) => el.id === targetId);
    const event = makeClickEvent(target);
    for (const listener of dom.captureClickListeners) {
        listener(event);
        if (event.immediateStopped) {
            break;
        }
    }
    // In a real DOM, a capture-phase stopImmediatePropagation prevents the event
    // from ever reaching the target's own click handler (J2Store's script).
    const reachedJ2StoreHandler = !event.immediateStopped;
    return { event, reachedJ2StoreHandler, alertCalls, registered: dom.captureClickListeners.length };
}

const VALIDATOR = { id: 'j2commerce-consent-validator', dataset: { error: 'Please accept the privacy policy.' } };
const BUTTON = makeElement('button-payment-method');

console.log('consent-validator.js — capture-phase click guard');

// The guard must register a capture-phase click listener at load time.
{
    const r = runScenario([VALIDATOR, makeElement('j2commerce_privacy_consent', { checked: false }), BUTTON], 'button-payment-method');
    assert('registers a capture-phase click listener', r.registered >= 1);
}

// Scenario A: required consent present and UNTICKED → click is blocked before
// it reaches J2Store's handler, the error is shown.
{
    const consent = makeElement('j2commerce_privacy_consent', { checked: false });
    const r = runScenario([VALIDATOR, consent, BUTTON], 'button-payment-method');
    assert('A: unticked click is prevented (preventDefault)', r.event.defaultPrevented === true);
    assert('A: unticked click stops immediate propagation', r.event.immediateStopped === true);
    assert('A: unticked click never reaches J2Store handler', r.reachedJ2StoreHandler === false);
    assert('A: unticked click shows the error message', r.alertCalls.length === 1 && r.alertCalls[0] === 'Please accept the privacy policy.');
}

// Scenario B: consent TICKED → click passes through to J2Store's handler.
{
    const consent = makeElement('j2commerce_privacy_consent', { checked: true });
    const r = runScenario([VALIDATOR, consent, BUTTON], 'button-payment-method');
    assert('B: ticked click is not prevented', r.event.defaultPrevented === false);
    assert('B: ticked click reaches J2Store handler', r.reachedJ2StoreHandler === true);
    assert('B: ticked click shows no error', r.alertCalls.length === 0);
}

// Scenario C: consent not required (no validator element) → click passes.
{
    const consent = makeElement('j2commerce_privacy_consent', { checked: false });
    const r = runScenario([consent, BUTTON], 'button-payment-method');
    assert('C: without the validator element the click is not blocked', r.event.defaultPrevented === false);
    assert('C: without the validator element the click reaches J2Store handler', r.reachedJ2StoreHandler === true);
}

// Scenario D: a click somewhere else is ignored even while unticked.
{
    const consent = makeElement('j2commerce_privacy_consent', { checked: false });
    const other = makeElement('some-other-button');
    const r = runScenario([VALIDATOR, consent, BUTTON, other], 'some-other-button');
    assert('D: a click on another element is not blocked', r.event.defaultPrevented === false);
    assert('D: a click on another element reaches its handler', r.reachedJ2StoreHandler === true);
}

// Scenario E: the click target is a CHILD of the Continue button (the normal case
// when the button contains a label span or an icon) → still blocked.
{
    const consent = makeElement('j2commerce_privacy_consent', { checked: false });
    const button = makeElement('button-payment-method');
    const label = makeElement('button-payment-method-label', { parentElement: button });
    const r = runScenario([VALIDATOR, consent, button, label], 'button-payment-method-label');
    assert('E: a click on a child of the button is prevented', r.event.defaultPrevented === true);
    assert('E: a click on a child of the button never reaches J2Store handler', r.reachedJ2StoreHandler === false);
}

// Scenario F: the script must not register the guard twice when it is loaded
// twice in the same page (window.j2commercePrivacyClickGuard).
{
    const dom = makeDom([VALIDATOR, makeElement('j2commerce_privacy_consent', { checked: false }), BUTTON]);
    const sandbox = { document: dom.document, window: {}, alert() {} };
    vm.createContext(sandbox);
    vm.runInContext(source, sandbox, { filename: 'consent-validator.js' });
    vm.runInContext(source, sandbox, { filename: 'consent-validator.js' });
    assert('F: loading the script twice registers the click guard once', dom.captureClickListeners.length === 1);
}

if (failures > 0) {
    console.log('\n' + failures + ' assertion(s) FAILED');
    process.exit(1);
}
console.log('\nAll assertions passed');
