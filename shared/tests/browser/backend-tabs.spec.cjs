const { test, expect } = require('@playwright/test');
const fs = require('node:fs');

const fixture = JSON.parse(fs.readFileSync(process.env.BACKEND_POINTER_FIXTURE, 'utf8'));

async function exerciseConstraints(page) {
    await page.locator('main').evaluate(main => {
        for (const field of main.querySelectorAll('input:not([type="hidden"]), textarea, select')) {
            if (field.disabled || field.readOnly || !field.getBoundingClientRect().width) {
                continue;
            }
            field.focus();
            const original = field.value;
            if (field.required) {
                field.value = '';
                field.checkValidity();
            }
            if (['email', 'url'].includes(field.type)) {
                field.value = 'invalid';
                field.checkValidity();
            }
            if (field.type === 'number') {
                for (const value of [
                    ...(field.min !== '' ? [Number(field.min) - 1] : []),
                    ...(field.max !== '' ? [Number(field.max) + 1] : []),
                ]) {
                    field.value = String(value);
                    field.checkValidity();
                }
            }
            field.value = original;
        }
    });
}

async function clickTab(button) {
    await button.click({ timeout: 2_000 });
}

async function exerciseTabs(page, tabs) {
    let clicks = 0;
    const controls = await tabs.locator(':scope > joomla-tab-element').evaluateAll(panes => panes.map(pane => pane.id));
    expect(controls.length).toBeGreaterThan(0);
    for (const id of controls) {
        const trigger = tabs.locator(`:scope > div[role="tablist"] > button[aria-controls="${id}"], :scope > button[aria-controls="${id}"]`).filter({ visible: true });
        const pane = tabs.locator(`:scope > joomla-tab-element[id="${id}"]`);
        await expect(trigger).toHaveCount(1);
        const accordion = await tabs.getAttribute('view') === 'accordion';
        const wasOpen = await pane.isVisible();
        await clickTab(trigger);
        clicks++;
        if (accordion && wasOpen) {
            await expect(pane).toBeHidden();
            await clickTab(trigger);
            clicks++;
        }
        await expect(pane).toBeVisible();
        if (await tabs.getAttribute('id') === 'permissions-sliders') {
            const geometry = await pane.evaluate(element => {
                const box = element.getBoundingClientRect();
                return { left: box.left, right: box.right, viewport: innerWidth };
            });
            expect(geometry.left).toBeGreaterThanOrEqual(-1);
            expect(geometry.right).toBeLessThanOrEqual(geometry.viewport + 1);
            const widths = await pane.locator('select').evaluateAll(fields => fields.map(field => {
                const cell = field.closest('td').getBoundingClientRect();
                return { width: field.getBoundingClientRect().width, available: cell.width };
            }));
            for (const { width, available } of widths) {
                expect(width).toBeGreaterThan(Math.min(140, available * .7));
            }
        }
        if (!accordion) {
            await expect(trigger).toHaveAttribute('aria-selected', 'true');
        }
        const nested = pane.locator('joomla-tab');
        if (await nested.count()) {
            await expect(nested).toHaveCount(1);
            clicks += await exerciseTabs(page, nested);
        }
    }
    return clicks;
}

for (const surface of ['eventtype', 'module']) {
    test(`${surface}: every tab and permission group accepts pointer clicks`, async ({ page }, testInfo) => {
        await page.context().addCookies([
            ...fixture.cookiesByLocale[testInfo.project.use.locale],
            { name: 'userColorScheme', value: testInfo.project.use.colorScheme, url: fixture.baseURL },
        ]);
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        const route = surface === 'eventtype'
            ? 'index.php?option=com_appointments&view=eventtype&layout=edit&id=0'
            : `index.php?option=com_modules&task=module.edit&id=${fixture.moduleId}`;
        await page.setViewportSize({ width: 1600, height: 900 });
        const response = await page.goto(`/administrator/${route}`);
        expect(response.status()).toBe(200);
        await expect(page.locator('#form-login')).toHaveCount(0);
        await expect(page.locator('html')).toHaveAttribute('data-bs-theme', testInfo.project.use.colorScheme);
        await expect(page.locator('html')).toHaveAttribute('lang', new RegExp(`^${testInfo.project.use.locale}$`, 'i'));
        await expect(page.locator('html')).toHaveCSS('scroll-behavior', 'auto');
        const rootTabs = page.locator(surface === 'eventtype' ? '#eventtype-tabs' : '#myTab');
        await expect(rootTabs.locator(':scope > div[role=tablist]')).toHaveCount(1);
        await expect(rootTabs.locator(':scope > joomla-tab-element')).toHaveCount(surface === 'eventtype' ? 2 : 3);
        const permissions = page.locator('#permissions-sliders');
        await expect(permissions.locator(':scope > joomla-tab-element')).toHaveCount(fixture.groupCount);
        await page.setViewportSize(testInfo.project.use.viewport);
        await expect(rootTabs).toHaveAttribute('view', testInfo.project.use.viewport.width <= 768 ? 'accordion' : 'tabs');
        const title = page.locator('#jform_title');
        await title.fill('Synthetic unsaved pointer title');
        const ruleValues = await permissions.locator('select').evaluateAll(fields => fields.map(field => [field.name, field.value]));
        expect(ruleValues.length).toBeGreaterThan(0);
        await exerciseConstraints(page);
        const clicks = await exerciseTabs(page, rootTabs);
        expect(clicks).toBeGreaterThan(fixture.groupCount);
        testInfo.annotations.push({ type: 'pointer-clicks', description: String(clicks) });
        await expect(permissions).toBeVisible();
        await expect(title).toHaveValue('Synthetic unsaved pointer title');
        expect(await permissions.locator('select').evaluateAll(fields => fields.map(field => [field.name, field.value]))).toEqual(ruleValues);
        expect(errors).toEqual([]);
    });
}
