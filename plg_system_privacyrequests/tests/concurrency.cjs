/**
 * Installed-Core concurrency controls. The caller must provide a second, genuinely
 * separate PHP server for this same isolated site/database, not another browser tab.
 */
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {spawn} = require('node:child_process');
const {createHash} = require('node:crypto');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const {root, route, mailDir, tokenFor, check} = require('./browser.cjs');
const {fixture, action} = require('./lifecycle.cjs');

async function postFull(context, server, email, operation, token = '') {
    const url = server + new URL(route).pathname + new URL(route).search;
    const html = await (await context.request.get(url)).text();
    const csrf = /name="([a-f0-9]{32})" value="1"/.exec(html)?.[1];
    assert(csrf, 'Real session-bound form token required');
    const response = await context.request.post(url, {
        form: {email, operation, confirm_token: token, [csrf]: '1'},
    });
    return {status: response.status(), html: await response.text()};
}

async function post(context, server, email, operation, token = '') {
    return (await postFull(context, server, email, operation, token)).html;
}

async function hold(id) {
    const child = spawn(process.env.PRIVACY_PHP, [
        ...JSON.parse(process.env.PRIVACY_PHP_ARGS || '[]'), path.join(__dirname, 'fixture.php'),
        'operation-hold', String(id),
    ], {env: process.env, stdio: ['pipe', 'pipe', 'pipe']});
    let output = '';
    let errors = '';
    child.stderr.on('data', chunk => {errors += chunk;});
    const exited = new Promise(resolve => child.once('close', code => resolve(code)));
    await new Promise((resolve, reject) => {
        const timer = setTimeout(() => {
            child.stdin.end('\n');
            reject(new Error('Actual database lock was not acquired'));
        }, 15000);
        child.stdout.on('data', chunk => {
            output += chunk;
            if (output.includes('LOCK_HELD')) {clearTimeout(timer); resolve();}
        });
        child.once('error', error => {clearTimeout(timer); reject(error);});
        child.once('exit', code => {
            clearTimeout(timer);
            if (!output.includes('LOCK_HELD')) reject(new Error(`Lock process exited ${code}: ${errors}`));
        });
    });
    return async () => {
        child.stdin.end('\n');
        assert.equal(await exited, 0, 'Actual lock owner must release its connection cleanly');
    };
}

async function main() {
    for (const file of ['concurrency.cjs', 'lifecycle.cjs', 'browser.cjs', 'fixture.php']) {
        console.log('EXECUTION_BINDING', JSON.stringify({file,
            sha256: createHash('sha256').update(fs.readFileSync(path.join(__dirname, file))).digest('hex')}));
    }
    console.log('CORE_BINDINGS', JSON.stringify(fixture('bindings')));
    const worker = process.env.PRIVACY_CONCURRENT_URL;
    assert(worker && worker !== root, 'A separate PHP process endpoint is mandatory');
    fixture('admission-reset');
    const browser = await chromium.launch({channel: 'msedge', headless: true});
    try {
        const contexts = await Promise.all([browser.newContext(), browser.newContext()]);
        const servers = [root, worker];
        const email = `parallel-${Date.now()}@example.test`;
        const before = new Set(fs.readdirSync(mailDir));
        const responses = await Promise.all(contexts.map((context, i) => post(context, servers[i], email, 'request')));
        check('Two real PHP processes concurrently accept the same address without a public delivery claim',
            responses.every(html => html.includes('does not confirm receipt')));
        const created = fixture('snapshot', email);
        check('Concurrent create/renew retains exactly one pending receipt and one truthful creation audit',
            created.requests.length === 1 && Number(created.requests[0].status) === 0
            && created.audit.filter(row => row.message_language_key === 'COM_PRIVACY_ACTION_LOG_CREATED_REQUEST').length === 1);
        const receipt = created.requests[0];
        const selected = await tokenFor(email, before);
        // The first delivery may have been superseded. Gather each genuine captured
        // token and try the same set from both independent sessions/processes.
        const tokens = new Set([selected.token]);
        for (const file of fs.readdirSync(mailDir).filter(file => !before.has(file))) {
            const captured = JSON.parse(fs.readFileSync(path.join(mailDir, file), 'utf8'));
            if (!captured.envelope.some(line => line.toLowerCase().includes(`<${email}>`))) continue;
            const token = /confirm_token=([a-z0-9]{16,64})/i.exec(
                captured.mime.replace(/=\r?\n/g, '').replace(/=3D/g, '='))?.[1];
            if (token && !tokens.has(token)) {
                tokens.add(token);
                await tokenFor(email, new Set(fs.readdirSync(mailDir).filter(name => name !== file)));
            }
        }
        let confirmations = 0;
        for (const token of tokens) {
            const replies = await Promise.all(contexts.map((context, i) => post(context, servers[i], email, 'confirm', token)));
            confirmations += replies.filter(html => html.includes('No data has been removed')).length;
        }
        const confirmed = fixture('snapshot', email);
        check('Concurrent confirmation consumes the current token exactly once, including superseded deliveries',
            confirmations === 1 && Number(confirmed.requests[0].status) === 1
            && confirmed.requests[0].requested_at === receipt.requested_at
            && !confirmed.requests[0].confirm_token);

        const stamp = Date.now();
        const asciiAddress = `muller-${stamp}@example.test`;
        const unicodeAddress = `müller-${stamp}@example.test`;
        const distinctBefore = new Set(fs.readdirSync(mailDir));
        // Unicode or other special characters before the @ are not supported, as everywhere in Joomla.
        // They are refused with a proper error before any receipt, subject row or mail exists.
        const rejected = await Promise.all(['request', 'confirm'].map((operation, i) =>
            postFull(contexts[i], servers[i], unicodeAddress, operation, operation === 'confirm' ? 'abcdefghijklmnop' : '')));
        const rejectedState = fixture('snapshot', unicodeAddress);
        check('A Unicode local-part address is cleanly rejected with a proper error: no 500, no receipt, no mail',
            rejected.every(reply => reply.status === 200 && reply.html.includes('Enter a valid email address')
                && !reply.html.includes('does not confirm receipt') && !reply.html.includes('No data has been removed'))
            && rejectedState.requests.length === 0 && rejectedState.deliveries.length === 0
            && fs.readdirSync(mailDir).every(name => distinctBefore.has(name)));
        const accepted = await post(contexts[0], servers[0], asciiAddress, 'request');
        const asciiRow = fixture('snapshot', asciiAddress);
        check('The ASCII address of the same person is still accepted as an actual Core receipt',
            accepted.includes('does not confirm receipt')
            && asciiRow.requests.length === 1 && Number(asciiRow.requests[0].status) === 0 && asciiRow.accounts === 0);

        const admin = await browser.newPage();
        await admin.goto(`${root}/administrator/index.php`);
        await admin.locator('#mod-login-username').fill(process.env.JOOMLA_ADMIN_USERNAME);
        await admin.locator('#mod-login-password').fill(process.env.JOOMLA_ADMIN_PASSWORD);
        await Promise.all([admin.waitForNavigation(), admin.locator('button[type="submit"]').click()]);
        const asciiMail = await tokenFor(asciiAddress, distinctBefore);
        const asciiAccount = fixture('account-create', asciiAddress);
        const originalAsciiAccount = fixture('account-snapshot', asciiAccount.user_id);
        const wrong = await postFull(contexts[1], servers[1], unicodeAddress, 'confirm', asciiMail.token);
        check('A Unicode lookalike address cannot consume the token of the ASCII address',
            wrong.status === 200 && wrong.html.includes('Enter a valid email address')
            && Number(fixture('snapshot', asciiAddress).requests[0].status) === 0
            && fixture('snapshot', unicodeAddress).requests.length === 0
            && JSON.stringify(fixture('account-snapshot', asciiAccount.user_id)) === JSON.stringify(originalAsciiAccount));
        await admin.goto(`${root}/administrator/index.php?option=com_privacy&task=request.add`);
        await admin.locator('#jform_email').fill(asciiAddress);
        await admin.locator('#jform_request_type').selectOption('export');
        await action(admin, 'request.save');
        const exportRow = fixture('snapshot', asciiAddress).requests.find(row => row.request_type === 'export');
        check('Native Core creates a separate pending export alongside removal', exportRow && Number(exportRow.status) === 0);
        const exactConfirmation = await post(contexts[0], servers[0], asciiAddress, 'confirm', asciiMail.token);
        check('Pinned native confirmation selects the exact remove row without confirming the export',
            exactConfirmation.includes('No data has been removed')
            && fixture('snapshot', asciiAddress).requests.some(row => row.request_type === 'remove' && Number(row.status) === 1)
            && JSON.stringify(fixture('snapshot', asciiAddress).requests.find(row => row.id === exportRow.id)) === JSON.stringify(exportRow));
        const requestUrl = `${root}/administrator/index.php?option=com_privacy&view=request&id=${receipt.id}`;
        const release = await hold(receipt.id);
        try {
            for (const task of ['request.remove', 'request.complete']) {
                await admin.goto(requestUrl);
                await admin.locator('input[name="jform[privacy_scope_reviewed]"]').check();
                const original = fixture('snapshot', email);
                await action(admin, task);
                check(`Actual held database operation lock refuses ${task} before any callback/audit/status mutation`,
                    JSON.stringify(fixture('snapshot', email)) === JSON.stringify(original)
                    && (await admin.locator('body').innerText()).includes('privacy operation'));
            }
        } finally {
            await release();
        }
        fixture('probe', 'allow');
        await admin.goto(requestUrl);
        await action(admin, 'request.remove');
        check('Released real operation lock admits native Remove again',
            Number(fixture('snapshot', email).outcomes[0]?.reviewed_result) === 1);
    } finally {
        fixture('probe', 'block');
        await browser.close();
    }
}
main().catch(error => {console.error(error); process.exitCode = 1;});
