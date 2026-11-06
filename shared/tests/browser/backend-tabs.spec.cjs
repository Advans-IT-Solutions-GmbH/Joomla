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

function tabTrigger(tabs, id) {
    return tabs.locator(`:scope > div[role="tablist"] > button[aria-controls="${id}"], :scope > button[aria-controls="${id}"]`).filter({ visible: true });
}

async function showPane(tabs, id) {
    const pane = tabs.locator(`:scope > joomla-tab-element[id="${id}"]`);
    if (!await pane.isVisible()) {
        await clickTab(tabTrigger(tabs, id));
    }
    await expect(pane).toBeVisible();
}

async function openEditor(page, testInfo, surface) {
    await page.context().addCookies([
        ...fixture.cookiesByLocale[testInfo.project.use.locale],
        { name: 'userColorScheme', value: testInfo.project.use.colorScheme, url: fixture.baseURL },
    ]);
    const route = surface === 'eventtype'
        ? `index.php?option=com_appointments&task=eventtype.edit&id=${fixture.eventtypeId}`
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
    if (surface === 'eventtype') {
        await expect(page.locator('#jform_asset_id')).toHaveValue(String(fixture.eventtypeAssetId));
    }
    await page.setViewportSize(testInfo.project.use.viewport);
    await expect(rootTabs).toHaveAttribute('view', testInfo.project.use.viewport.width <= 768 ? 'accordion' : 'tabs');
    return { rootTabs, permissions, route };
}

async function applyForm(page) {
    const apply = page.locator('#toolbar-apply button');
    if (!await apply.isVisible()) {
        await page.locator('button[aria-controls="subhead-container"]').click({ timeout: 2_000 });
    }
    await apply.click({ timeout: 2_000 });
}

async function exerciseTabs(page, tabs) {
    let clicks = 0;
    const controls = await tabs.locator(':scope > joomla-tab-element').evaluateAll(panes => panes.map(pane => pane.id));
    expect(controls.length).toBeGreaterThan(0);
    for (const id of controls) {
        const trigger = tabTrigger(tabs, id);
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
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        const { rootTabs, permissions } = await openEditor(page, testInfo, surface);
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

    test(`${surface}: valid saves and required-field rejection preserve record rules`, async ({ page }, testInfo) => {
        const { rootTabs, permissions, route } = await openEditor(page, testInfo, surface);
        const title = page.locator('#jform_title');
        const task = surface === 'eventtype' ? 'eventtype.apply' : 'module.apply';
        const submissions = [];
        page.on('request', request => {
            if (request.method() === 'POST' && new URLSearchParams(request.postData()).get('task') === task) {
                submissions.push(request);
            }
        });
        await title.fill('');
        await showPane(rootTabs, 'permissions');
        await applyForm(page);
        await expect(title).toHaveAttribute('aria-invalid', 'true');
        expect(submissions).toHaveLength(0);

        await showPane(rootTabs, surface === 'eventtype' ? 'details' : 'general');
        const savedTitle = `Synthetic saved ${testInfo.project.name}`;
        await title.fill(savedTitle);
        await showPane(rootTabs, 'permissions');
        const rule = permissions.locator(`select[name$="[${fixture.permissionGroupId}]"]`).first();
        const paneId = await rule.evaluate(element => element.closest('joomla-tab-element').id);
        await showPane(permissions, paneId);
        const name = await rule.getAttribute('name');
        const value = await rule.inputValue() === '1' ? '0' : '1';
        await rule.selectOption(value);
        const saved = page.waitForResponse(response => response.request().method() === 'POST'
            && new URLSearchParams(response.request().postData()).get('task') === task);
        await applyForm(page);
        const response = await saved;
        expect(response.status()).toBeLessThan(400);
        expect(new URLSearchParams(response.request().postData()).get(name)).toBe(value);
        await page.goto(`/administrator/${route}`);
        await expect(title).toHaveValue(savedTitle);
        await expect(rule).toHaveValue(value);
    });
}
