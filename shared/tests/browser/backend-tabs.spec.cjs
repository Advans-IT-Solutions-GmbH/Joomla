const { test, expect } = require('@playwright/test');
const fs = require('node:fs');

const fixture = JSON.parse(fs.readFileSync(process.env.BACKEND_POINTER_FIXTURE, 'utf8'));

async function clickTab(button) {
    // Ordinary scrolling is necessary with Atum's sticky toolbar. No force or DOM clicks.
    await button.evaluate(element => element.scrollIntoView({ block: 'center', inline: 'center' }));
    await button.click();
}

async function exerciseTabs(page, tabs) {
    const controls = await tabs.locator(':scope > joomla-tab-element').evaluateAll(panes => panes.map(pane => pane.id));
    expect(controls.length).toBeGreaterThan(0);
    for (const id of controls) {
        const trigger = tabs.locator(`:scope > div[role="tablist"] > button[aria-controls="${id}"], :scope > button[aria-controls="${id}"]`).filter({ visible: true });
        const pane = tabs.locator(`:scope > joomla-tab-element[id="${id}"]`);
        await expect(trigger).toHaveCount(1);
        const accordion = await tabs.getAttribute('view') === 'accordion';
        const wasOpen = await pane.isVisible();
        await clickTab(trigger);
        if (accordion && wasOpen) {
            await expect(pane).toBeHidden();
            await clickTab(trigger);
        }
        await expect(pane).toBeVisible();
        if (!accordion) {
            await expect(trigger).toHaveAttribute('aria-selected', 'true');
        }
        const nested = pane.locator('joomla-tab');
        if (await nested.count()) {
            await expect(nested).toHaveCount(1);
            await exerciseTabs(page, nested);
        }
    }
}

for (const surface of ['eventtype', 'module']) {
    test(`${surface}: every tab and permission group accepts pointer clicks`, async ({ page }, testInfo) => {
        await page.context().addCookies(fixture.cookies);
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        const route = surface === 'eventtype'
            ? 'index.php?option=com_appointments&view=eventtype&layout=edit&id=0'
            : `index.php?option=com_modules&task=module.edit&id=${fixture.moduleId}`;
        const response = await page.goto(`/administrator/${route}`);
        expect(response.status()).toBe(200);
        await expect(page.locator('#form-login')).toHaveCount(0);
        await expect(page.locator('html')).toHaveAttribute('data-bs-theme', testInfo.project.use.colorScheme);
        const rootTabs = page.locator(surface === 'eventtype' ? '#eventtype-tabs' : '#myTab');
        await expect(rootTabs.locator(':scope > div[role=tablist]')).toHaveCount(1);
        await expect(rootTabs.locator(':scope > joomla-tab-element')).toHaveCount(surface === 'eventtype' ? 2 : 3);
        const permissions = page.locator('#permissions-sliders');
        await expect(permissions.locator(':scope > joomla-tab-element')).toHaveCount(fixture.groupCount);
        await exerciseTabs(page, rootTabs);
        await expect(permissions).toBeVisible();
        expect(errors).toEqual([]);
    });
}
