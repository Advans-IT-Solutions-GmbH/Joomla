// Release test 16.11.2026 (com_appointments 1.5.0): after a booking, a reschedule, a cancellation and
// an erasure the focus stayed on the page body. A site template keeps a closed dialog in every page
// (role="dialog" aria-modal="true", hidden with visibility:hidden), and booking.js took any
// [aria-modal="true"] for an open dialog that must keep the focus. These pages load the shipped
// booking.js in a real browser (Chromium and Firefox) and check the focus with the keyboard.
const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');

const script = fs.readFileSync(path.join(__dirname, '..', '..', '..', 'com_appointments', 'media', 'com_appointments', 'js', 'booking.js'), 'utf8');
const origin = 'http://appointments.localhost';

const outcomes = {
    confirmed: { target: 'appointment-confirmation', status: 'Your appointment is confirmed.' },
    rescheduled: { target: 'appointment-result', status: 'Your appointment has been rescheduled.' },
    cancelled: { target: 'appointment-result', status: 'Your appointment has been cancelled.' },
    erased: { target: 'appointment-result', status: 'Your data has been erased. No further action is needed.' },
};

// The closed layer as the site template renders it, opened by a class on the body.
const page = (outcome, layerOpen) => `<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>${outcome}</title>
<style>.layer{visibility:hidden;position:fixed;inset:0}.layer-open .layer{visibility:visible}</style>
<script>window.Joomla = { getOptions: () => undefined };</script>
<script src="/booking.js" defer></script></head>
<body class="${layerOpen ? 'layer-open' : ''}">
<header><a href="#" id="home">Home</a><button type="button" aria-controls="layer">Newsletter</button></header>
<div class="layer" id="layer" role="dialog" aria-modal="true" aria-labelledby="layer-heading">
  <h2 id="layer-heading">Newsletter</h2><button type="button" id="layer-close">Close</button>
</div>
<main><div id="${outcomes[outcome].target}">
  <h1>Outcome</h1>
  <p class="com-appointments-status" role="status" tabindex="-1" autofocus data-appointments-result>${outcomes[outcome].status}</p>
  <a href="#" id="next-action">Book a new appointment</a>
</div></main>
</body></html>`;

const open = async (browserPage, outcome, layerOpen = false) => {
    await browserPage.route(`${origin}/**`, (route) => {
        const url = new URL(route.request().url());

        return route.fulfill(url.pathname === '/booking.js'
            ? { contentType: 'text/javascript', body: script }
            : { contentType: 'text/html', body: page(outcome, layerOpen) });
    });
    // The redirect after the change lands at the fragment of the outcome.
    await browserPage.goto(`${origin}/${outcome}#${outcomes[outcome].target}`, { waitUntil: 'load' });
};

const focused = (browserPage) => browserPage.evaluate(() => {
    const active = document.activeElement;

    return active === document.body || !active ? 'BODY' : active.id || active.getAttribute('data-appointments-result') !== null && 'result' || active.tagName;
});

for (const outcome of Object.keys(outcomes)) {
    test(`${outcome}: the outcome takes the focus although a closed dialog is in the page`, async ({ page: browserPage }) => {
        await open(browserPage, outcome);
        await expect.poll(() => focused(browserPage)).toBe('result');
        // The keyboard goes on from the outcome, not from the start of the page.
        await browserPage.keyboard.press('Tab');
        expect(await focused(browserPage)).toBe('next-action');
    });
}

test('an open dialog keeps the focus where it is', async ({ page: browserPage }) => {
    await open(browserPage, 'confirmed', true);
    // Give the script the same time it needs above, then check that it left the focus alone.
    await browserPage.waitForTimeout(500);
    expect(await focused(browserPage)).not.toBe('result');
});
