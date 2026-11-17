// Browser checks of the administrator list tools that HTTP tests cannot exercise: dragging booking
// fields into a new order within their event type (also while the list is filtered), and the
// «Hide past dates» switch of the schedule form, which must only hide rows and never drop them.
const { test, expect } = require('@playwright/test');
const fs = require('node:fs');

const fixture = JSON.parse(fs.readFileSync(process.env.LIST_FILTERS_FIXTURE, 'utf8'));

function fieldsUrl(eventtype) {
    const query = new URLSearchParams({
        option: 'com_appointments',
        view: 'fields',
        'filter[search]': fixture.tag,
        'filter[eventtype_id]': eventtype === null ? '' : String(eventtype),
        'filter[type]': '',
        'filter[required]': '',
        'filter[published]': '',
        'list[fullordering]': 'f.ordering ASC',
        'list[limit]': '100',
    });

    return `/administrator/index.php?${query}`;
}

async function rowIds(page, group = null) {
    const rows = page.locator(group === null ? '#fieldList tbody tr' : `#fieldList tbody tr[data-draggable-group="${group}"]`);

    return rows.locator('input[name="cid[]"]').evaluateAll(inputs => inputs.map(input => Number(input.value)));
}

test.beforeEach(async ({ page }) => {
    await page.context().addCookies(fixture.cookies);
});

test('booking fields move only within their event type, filtered and unfiltered', async ({ page }) => {
    const [a, b] = [fixture.events.a, fixture.events.b];

    // Filtered by event type A.
    await page.goto(fieldsUrl(a), { waitUntil: 'networkidle' });
    await expect(page.locator('#form-login')).toHaveCount(0);
    await expect(page.locator('#fieldList tbody.js-draggable')).toHaveCount(1);
    const ordered = await rowIds(page);
    expect([...ordered].sort((x, y) => x - y)).toEqual([...fixture.fields.a].sort((x, y) => x - y));
    await dragLastToFirst(page);
    await page.goto(fieldsUrl(a), { waitUntil: 'networkidle' });
    expect(await rowIds(page)).toEqual([ordered[ordered.length - 1], ...ordered.slice(0, -1)]);

    // Unfiltered: both event types, grouped. Dragging in group A leaves group B as it was.
    await page.goto(fieldsUrl(null), { waitUntil: 'networkidle' });
    const groupB = await rowIds(page, b);
    expect(groupB).toEqual(fixture.fields.b);
    const groupA = await rowIds(page, a);
    expect(await rowIds(page)).toEqual([...groupA, ...groupB]);
    await dragLastToFirst(page, a);
    await page.goto(fieldsUrl(null), { waitUntil: 'networkidle' });
    expect(await rowIds(page, a)).toEqual([groupA[groupA.length - 1], ...groupA.slice(0, -1)]);
    expect(await rowIds(page, b)).toEqual(fixture.fields.b);
});

async function dragLastToFirst(page, group = null) {
    const rows = page.locator(group === null ? '#fieldList tbody tr' : `#fieldList tbody tr[data-draggable-group="${group}"]`);
    const handle = rows.last().locator('.sortable-handler');
    await handle.scrollIntoViewIfNeeded();
    const from = await handle.boundingBox();
    const to = await rows.first().boundingBox();
    const saved = page.waitForResponse(response => response.url().includes('task=fields.saveOrderAjax')
        && response.request().method() === 'POST');
    await page.mouse.move(from.x + from.width / 2, from.y + from.height / 2);
    await page.mouse.down();
    await page.mouse.move(from.x + from.width / 2, from.y + from.height / 2 - 8, { steps: 4 });
    await page.mouse.move(from.x + from.width / 2, to.y + 3, { steps: 20 });
    await page.mouse.up();
    const response = await saved;
    expect(response.status()).toBe(200);
    expect((await response.text()).trim()).toBe('1');
}

async function overrideRows(page) {
    return page.locator('.subform-repeatable-group').filter({ has: page.locator('input[name$="[specific_date]"]') });
}

async function visibleDates(page) {
    return (await overrideRows(page)).evaluateAll(rows => rows
        .filter(row => !row.hidden && getComputedStyle(row).display !== 'none')
        .map(row => row.querySelector('input[name$="[specific_date]"]').value.slice(0, 10)));
}

test('hiding past dates keeps every date when the schedule is saved', async ({ page }) => {
    const edit = `/administrator/index.php?option=com_appointments&task=schedule.edit&id=${fixture.scheduleId}`;
    await page.goto(edit, { waitUntil: 'networkidle' });
    await expect(page.locator('#form-login')).toHaveCount(0);
    await expect(await overrideRows(page)).toHaveCount(4);
    expect(await visibleDates(page)).toEqual(fixture.dates);

    const toggle = page.locator('#appointments-hide-past-overrides');
    await expect(toggle).not.toBeChecked();
    await toggle.check();
    const future = fixture.dates.filter(date => date >= fixture.today);
    await expect.poll(() => visibleDates(page)).toEqual(future);
    await expect(page.locator('#appointments-past-overrides-hidden')).toBeVisible();
    await expect(page.locator('#appointments-past-overrides-hidden')).toContainText(String(fixture.dates.length - future.length));

    // Save with the past rows hidden: they must still be stored.
    const apply = page.locator('#toolbar-apply button');
    if (!await apply.isVisible()) {
        await page.locator('button[aria-controls="subhead-container"]').click();
    }
    await Promise.all([page.waitForNavigation({ waitUntil: 'networkidle' }), apply.click()]);
    await expect(page.locator('joomla-alert[type="success"], .alert-success').first()).toBeVisible();
    await expect(await overrideRows(page)).toHaveCount(4);
    expect(await visibleDates(page)).toEqual(fixture.dates);

    await toggle.uncheck();
    await expect.poll(() => visibleDates(page)).toEqual(fixture.dates);
    await expect(page.locator('#appointments-past-overrides-hidden')).toBeHidden();
});
