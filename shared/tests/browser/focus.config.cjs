// The focus of the com_appointments outcome pages in real browsers (booking-focus.spec.cjs). The
// pages are served by the test itself, so no site and no fixture is needed.
const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
    testDir: __dirname,
    testMatch: 'booking-focus.spec.cjs',
    outputDir: 'test-results/booking-focus',
    retries: 0,
    workers: 1,
    timeout: 30_000,
    expect: { timeout: 5_000 },
    reporter: [['list']],
    projects: [
        { name: 'chromium', use: { browserName: 'chromium' } },
        { name: 'firefox', use: { browserName: 'firefox' } },
    ],
});
