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
    await page.goto('/administrator/index.php');
    await page.fill('#mod-login-username', fixture.username);
    await page.fill('#mod-login-password', fixture.password);
    await page.click('#btn-login-submit');
    await page.waitForLoadState('load');

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
        };
    });

    const dark = testInfo.project.use.colorScheme === 'dark';
    // The component surface sits on the same side of the scheme as Atum's page.
    expect(luminance(m.surface) < 0.4).toBe(dark);
    expect(luminance(m.page) < 0.4).toBe(dark);
    // Text stays readable on the surface (WCAG AA for normal text).
    expect(contrast(m.text, m.surface)).toBeGreaterThanOrEqual(4.5);
    // The page never scrolls sideways; wide tables scroll inside their containers.
    expect(m.overflow).toBeLessThanOrEqual(1);
    expect(m.containers.length).toBeGreaterThan(0);
    for (const container of m.containers) {
        expect(container.overflowX).toBe('auto');
        expect(container.right).toBeLessThanOrEqual(m.viewport + 1);
    }
});
