// Copied next to shared/tests/browser/package.json by the workflow, so it uses
// the pinned @playwright/test installed there.
const { defineConfig } = require('@playwright/test');
const fs = require('node:fs');

if (!process.env.CLEANUP_LAYOUT_FIXTURE) {
    throw new Error('CLEANUP_LAYOUT_FIXTURE must name a CLI-created disposable-site fixture.');
}
const fixture = JSON.parse(fs.readFileSync(process.env.CLEANUP_LAYOUT_FIXTURE, 'utf8'));
if (!['localhost', '127.0.0.1', '[::1]'].includes(new URL(fixture.baseURL).hostname)) {
    throw new Error('Layout tests may only use a loopback test installation.');
}

module.exports = defineConfig({
    testDir: __dirname,
    testMatch: 'cleanup-layout.spec.cjs',
    outputDir: 'test-results',
    retries: 0,
    workers: 1,
    timeout: 120_000,
    expect: { timeout: 10_000 },
    reporter: [['list']],
    use: {
        baseURL: fixture.baseURL,
        actionTimeout: 10_000,
        // Screenshots contain only the synthetic test site; never persist authenticated traces.
        screenshot: 'only-on-failure',
    },
    projects: ['chromium', 'firefox'].flatMap(browserName =>
        [375, 1600].flatMap(width =>
            ['light', 'dark'].map(colorScheme => ({
                name: `${browserName}-${width}-${colorScheme}`,
                use: { browserName, viewport: { width, height: 900 }, colorScheme, locale: 'en-GB' },
            }))
        )
    ),
});
