// Copied next to shared/tests/browser/package.json by the workflow, which provides the pinned
// Playwright. Runs the list-filter browser checks in Chromium and Firefox at 1600 and 375 px.
const { defineConfig } = require('@playwright/test');
const fs = require('node:fs');

if (!process.env.LIST_FILTERS_FIXTURE) {
    throw new Error('LIST_FILTERS_FIXTURE must name a CLI-created disposable-site fixture.');
}
const fixture = JSON.parse(fs.readFileSync(process.env.LIST_FILTERS_FIXTURE, 'utf8'));
if (!['localhost', '127.0.0.1', '[::1]'].includes(new URL(fixture.baseURL).hostname)) {
    throw new Error('List filter browser tests may only use a loopback test installation.');
}

module.exports = defineConfig({
    testDir: __dirname,
    testMatch: 'list-filters.spec.cjs',
    outputDir: 'test-results',
    retries: 0,
    workers: 1,
    timeout: 120_000,
    expect: { timeout: 10_000 },
    reporter: [['list'], ['json', { outputFile: 'test-results/list-filters.json' }]],
    use: {
        baseURL: fixture.baseURL,
        actionTimeout: 10_000,
        // Screenshots contain only the synthetic test site; never persist authenticated traces.
        screenshot: 'only-on-failure',
    },
    projects: ['chromium', 'firefox'].flatMap(browserName => [1600, 375].map(width => ({
        name: `${browserName}-${width}`,
        use: { browserName, viewport: { width, height: 900 } },
    }))),
});
