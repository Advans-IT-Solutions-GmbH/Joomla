/**
 * Installed-Core browser acceptance. Run only against an isolated synthetic site.
 * Requires Playwright (Edge and Firefox), a local captured-mail directory and a
 * real administrator created by the Joomla installer. No request status is seeded.
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {createHash} = require('node:crypto');
const {chromium, firefox} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

const root = (process.env.JOOMLA_URL || '').replace(/\/$/, '');
const mailDir = process.env.PRIVACY_MAIL_DIR;
assert(root && mailDir, 'Set JOOMLA_URL and PRIVACY_MAIL_DIR for an isolated fixture');
const route = `${root}/index.php?option=com_ajax&plugin=privacyrequests&group=system&format=html`;
const out = process.env.PRIVACY_SCREENSHOTS;
const checks = [];
function check(name, value) {
    assert(value, name);
    checks.push(name);
    console.log(`PASS ${name}`);
}
async function tokenFor(email, before) {
    for (let attempt = 0; attempt < 50; attempt++) {
        for (const file of fs.readdirSync(mailDir).filter(f => !before.has(f))) {
            const captured = fs.readFileSync(path.join(mailDir, file), 'utf8');
            const mail = JSON.parse(captured);
            if (!mail.envelope.some(line => line.toLowerCase().includes(`<${email.toLowerCase()}>`))) continue;
            const mime = mail.mime.replace(/=\r?\n/g, '').replace(/=3D/g, '=');
            const token = /confirm_token=([a-z0-9]{16,64})/i.exec(mime)?.[1];
            if (token) {
                if (process.env.PRIVACY_TEST_REPORT_DIR) {
                    const binding = {source: file, sha256: createHash('sha256').update(captured).digest('hex'),
                        token_sha256: createHash('sha256').update(token).digest('hex'),
                        recipient_sha256: createHash('sha256').update(email).digest('hex')};
                    fs.writeFileSync(path.join(process.env.PRIVACY_TEST_REPORT_DIR,
                        `captured-mail-${binding.sha256}.json`), captured, {flag: 'wx'});
                    console.log('MAIL_BINDING', JSON.stringify(binding));
                }
                return {token, mime};
            }
        }
        await new Promise(resolve => setTimeout(resolve, 100));
    }
    throw new Error('No actual captured confirmation mail');
}
async function submit(page, email, operation, token = '') {
    await page.locator('#privacy-email').fill(email);
    await page.locator('#privacy-token').fill(token);
    await Promise.all([
        page.waitForNavigation(),
        page.locator(`button[name="operation"][value="${operation}"]`).click(),
    ]);
    return page.locator('[role="status"]').innerText();
}
async function main() {
    const browser = await chromium.launch({channel: 'msedge', headless: true});
    try {
        const page = await browser.newPage();
        await page.goto(route);
        check('Installed public form needs no account', await page.locator('#privacy-email').count() === 1);
        const cap = await page.request.get(route.replace('format=html', 'format=json') + '&task=capabilities');
        const capability = (await cap.json()).data?.[0];
        check('Explicit enabled Core guest-removal capability', capability?.capability === 'core-privacy-guest-remove'
            && capability.schema === 1 && capability.enabled === true && capability.requiresAccount === false);
        const denied = await page.request.post(route, {form: {email: 'csrf@example.test', operation: 'request'}});
        check('Missing session CSRF token is refused', (await denied.text()).includes('security token'));
        const email = `visitor-${Date.now()}@example.test`;
        const before = new Set(fs.readdirSync(mailDir));
        check('Guest request gives neutral response', (await submit(page, email, 'request')).includes('If this address'));
        const mail = await tokenFor(email, before);
        check('Actual mail routes back to generic confirmation', mail.mime.includes('plugin=privacyrequests')
            && mail.mime.includes('group=system') && !mail.mime.includes('plugin=j2commercePrivacy'));
        check('Wrong address cannot confirm another request',
            (await submit(page, 'different@example.test', 'confirm', mail.token)).includes('could not be confirmed'));
        check('Email possession confirms through installed Core',
            (await submit(page, email.toUpperCase(), 'confirm', mail.token)).includes('No data has been removed'));
        check('One-use confirmation rejects replay',
            (await submit(page, email, 'confirm', mail.token)).includes('could not be confirmed'));
        await page.goto(route + '&return=https%3A%2F%2Fexample.invalid%2F&redirect=//example.invalid/');
        check('Untrusted return parameters never redirect away', new URL(page.url()).origin === new URL(root).origin);

        const admin = await browser.newPage();
        await admin.goto(`${root}/administrator/index.php`);
        await admin.locator('#mod-login-username').fill(process.env.JOOMLA_ADMIN_USERNAME);
        await admin.locator('#mod-login-password').fill(process.env.JOOMLA_ADMIN_PASSWORD);
        await Promise.all([admin.waitForNavigation(), admin.locator('button[type="submit"]').click()]);
        await admin.goto(`${root}/administrator/index.php?option=com_privacy&view=requests`);
        const row = admin.locator('tr').filter({hasText: email});
        check('Genuine Core backend lists the confirmed guest request', await row.count() === 1);
        const href = await row.locator('a').filter({hasText: email}).getAttribute('href');
        await admin.goto(new URL(href, `${root}/administrator/`).href);
        const id = new URL(admin.url()).searchParams.get('id');
        check('Native Core request record is addressable', Number(id) > 0);
        check('Explicit scope review is visible in native request form',
            await admin.locator('input[name="jform[privacy_scope_reviewed]"]').count() === 1);
        console.log(`REQUEST ${JSON.stringify({email, id})}`);
        if (process.env.PRIVACY_BACKEND_SCENARIOS === '1') {
            // The caller installs real fixture privacy plugins and varies their blocker/failure
            // parameters between scenarios. This script never fabricates a confirmed record.
            await admin.locator('#toolbar-complete button').click();
            await admin.waitForLoadState('networkidle');
            check('Complete before verified Remove is blocked', (await admin.locator('body').innerText()).includes('Completion is blocked'));
        }
        await admin.close();
        await page.close();
    } finally {
        await browser.close();
    }

    for (const [name, launcher, options] of [['edge', chromium, {channel: 'msedge'}], ['firefox', firefox, {}]]) {
        const engine = await launcher.launch({...options, headless: true});
        try {
            for (const colorScheme of ['light', 'dark']) {
                for (const width of [375, 768, 1024, 1280, 1440, 1600, 1920]) {
                    const page = await engine.newPage({viewport: {width, height: 1000}, colorScheme});
                    await page.goto(route);
                    check(`${name} ${colorScheme} ${width}px: form, controls and no horizontal overflow`,
                        await page.locator('#privacy-email').isVisible()
                        && await page.locator('button[value="request"]').isVisible()
                        && await page.locator('button[value="confirm"]').isVisible()
                        && await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
                    if (out) {
                        fs.mkdirSync(out, {recursive: true});
                        await page.screenshot({path: path.join(out, `${name}-${colorScheme}-${width}.png`), fullPage: true});
                    }
                    await page.close();
                }
            }
        } finally {
            await engine.close();
        }
    }
    console.log(`${checks.length} installed browser checks passed`);
}
module.exports = {tokenFor, submit, route, root, mailDir, check};
if (require.main === module) {
    main().catch(error => { console.error(error); process.exitCode = 1; });
}
