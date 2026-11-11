// Rendered layout of the cleanup page in Atum: colors follow the active color
// scheme and the extension tables never widen the page.
const { test, expect } = require('@playwright/test');
const fs = require('node:fs');

const fixture = JSON.parse(fs.readFileSync(process.env.CLEANUP_LAYOUT_FIXTURE, 'utf8'));

function luminance(rgb) {
    const [r, g, b] = rgb.match(/[\d.]+/g).slice(0, 3).map(Number).map(v => {
        const c = v / 255;
        return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
    });
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

function contrast(a, b) {
    const [hi, lo] = [luminance(a), luminance(b)].sort((x, y) => y - x);
    return (hi + 0.05) / (lo + 0.05);
}

test('cleanup page follows the Atum color scheme and fits the viewport', async ({ page }, testInfo) => {
    // Atum's own color-scheme switch stores the choice in this cookie; it wins over
    // the template and user settings, so each project renders the scheme it tests.
    await page.context().addCookies([{
        name: 'userColorScheme',
        value: testInfo.project.use.colorScheme,
        url: fixture.baseURL,
    }]);
    await page.goto('/administrator/index.php');
    await page.fill('#mod-login-username', fixture.username);
    await page.fill('#mod-login-password', fixture.password);
    await page.click('#btn-login-submit');
    // The logout link exists only once the administrator session is established.
    await expect(page.locator('a[href*="task=logout"]').first()).toBeAttached({ timeout: 30_000 });

    await page.goto('/administrator/index.php?option=com_j2store_cleanup');
    await expect(page.locator('.j2cleanup')).toBeVisible();
    await expect(page.locator('.j2cleanup code', { hasText: fixture.probeElement })).toHaveCount(1);

    const m = await page.evaluate(() => {
        const root = document.querySelector('.j2cleanup');
        const style = getComputedStyle(root);
        const opaque = color => !/^rgba\(.*,\s*0\)$/.test(color) && color !== 'transparent';
        const pageColor = [root.parentElement, document.body, document.documentElement]
            .map(el => el && getComputedStyle(el).backgroundColor)
            .find(color => color && opaque(color)) || 'rgb(255, 255, 255)';
        return {
            surface: style.backgroundColor,
            text: style.color,
            page: pageColor,
            overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
            containers: [...document.querySelectorAll('.j2cleanup .table-responsive')].map(el => ({
                overflowX: getComputedStyle(el).overflowX,
                right: el.getBoundingClientRect().right,
            })),
            viewport: document.documentElement.clientWidth,
            scheme: {
                colorScheme: document.documentElement.getAttribute('data-color-scheme'),
                bsTheme: document.documentElement.getAttribute('data-bs-theme'),
                bodyBg: getComputedStyle(root).getPropertyValue('--body-bg').trim(),
                bsBodyBg: getComputedStyle(root).getPropertyValue('--bs-body-bg').trim(),
            },
        };
    });
    const details = JSON.stringify(m);
    console.log(`${testInfo.project.name}: ${details}`);

    const dark = testInfo.project.use.colorScheme === 'dark';
    expect(m.scheme.colorScheme, `Atum renders the requested scheme: ${details}`)
        .toBe(testInfo.project.use.colorScheme);
    // The component surface sits on the same side of the scheme as Atum's page.
    expect(luminance(m.page) < 0.4, `Atum page follows the scheme: ${details}`).toBe(dark);
    expect(luminance(m.surface) < 0.4, `component surface follows the scheme: ${details}`).toBe(dark);
    // Text stays readable on the surface (WCAG AA for normal text).
    expect(contrast(m.text, m.surface), `text contrast: ${details}`).toBeGreaterThanOrEqual(4.5);
    // The page never scrolls sideways; wide tables scroll inside their containers.
    expect(m.overflow).toBeLessThanOrEqual(1);
    expect(m.containers.length).toBeGreaterThan(0);
    for (const container of m.containers) {
        expect(container.overflowX).toBe('auto');
        expect(container.right).toBeLessThanOrEqual(m.viewport + 1);
    }
});
