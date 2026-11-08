const { defineConfig } = require('@playwright/test');
const fs = require('node:fs');

if (!process.env.BACKEND_POINTER_FIXTURE) {
    throw new Error('BACKEND_POINTER_FIXTURE must name a CLI-created disposable-site fixture.');
}
const fixture = JSON.parse(fs.readFileSync(process.env.BACKEND_POINTER_FIXTURE, 'utf8'));
const baseURL = new URL(fixture.baseURL);
if (!['localhost', '127.0.0.1', '[::1]'].includes(baseURL.hostname)) {
    throw new Error('Pointer regression tests may only use a loopback test installation.');
}

module.exports = defineConfig({
    testDir: __dirname,
    testMatch: 'backend-tabs.spec.cjs',
    outputDir: 'test-results',
    retries: 0,
    workers: 1,
    timeout: 120_000,
    expect: { timeout: 5_000 },
    reporter: [['list'], ['json', { outputFile: 'test-results/results.json' }]],
    use: {
        baseURL: fixture.baseURL,
        actionTimeout: 5_000,
        // Screenshots contain only the synthetic test site; never persist authenticated traces.
        screenshot: 'only-on-failure',
    },
    projects: [
        ['edge', 'fr-FR', 'eventtype'],
        ['firefox', 'de-DE', 'eventtype'],
        ['firefox', 'en-GB', 'eventtype'],
        ['firefox', 'fr-FR', 'eventtype'],
        ['edge', 'fr-FR', 'module'],
        ['firefox', 'de-DE', 'module'],
    ].flatMap(([browser, locale, surface]) =>
        [375, 768, 1024, 1280, 1440, 1600, 1920].flatMap(width =>
            ['light', 'dark'].map(colorScheme => ({
                name: `${browser}-${locale}-${width}-${colorScheme}-${surface}`,
                grep: new RegExp(`${surface}: ${[375, 1600].includes(width) ? '' : 'every tab'}`),
                use: {
                    browserName: browser === 'edge' ? 'chromium' : 'firefox',
                    ...(browser === 'edge' ? { channel: 'msedge' } : {}),
                    viewport: { width, height: 900 },
                    colorScheme,
                    locale,
                },
            }))
        )
    ),
});
