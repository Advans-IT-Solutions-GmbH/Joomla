const { test, expect } = require('@playwright/test');
const fs = require('node:fs');

const fixture = JSON.parse(fs.readFileSync(process.env.BACKEND_POINTER_FIXTURE, 'utf8'));

async function clickTab(button) {
    // Ordinary scrolling is necessary with Atum's sticky toolbar. No force or DOM clicks.
    await button.evaluate(element => element.scrollIntoView({ block: 'center', inline: 'center', behavior: 'instant' }));
    await button.click();
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
            ...fixture.cookies,
            { name: 'userColorScheme', value: testInfo.project.use.colorScheme, url: fixture.baseURL },
        ]);
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
        const title = page.locator('#jform_title');
        await title.fill('Synthetic unsaved pointer title');
        const ruleValues = await permissions.locator('select').evaluateAll(fields => fields.map(field => [field.name, field.value]));
        expect(ruleValues.length).toBeGreaterThan(0);
        const clicks = await exerciseTabs(page, rootTabs);
        expect(clicks).toBeGreaterThan(fixture.groupCount);
        testInfo.annotations.push({ type: 'pointer-clicks', description: String(clicks) });
        await expect(permissions).toBeVisible();
        await expect(title).toHaveValue('Synthetic unsaved pointer title');
        expect(await permissions.locator('select').evaluateAll(fields => fields.map(field => [field.name, field.value]))).toEqual(ruleValues);
        expect(errors).toEqual([]);
    });
}
