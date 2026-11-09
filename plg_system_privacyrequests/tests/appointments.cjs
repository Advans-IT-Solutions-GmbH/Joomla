/**
 * Coupled installed-Core regression: committed erasure can lose its email match,
 * but cannot lose the actionable reference to a failed internal cleanup.
 */
const fs = require('node:fs');
const path = require('node:path');
const {createHash, createHmac} = require('node:crypto');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const {root, route, submit, check} = require('./browser.cjs');
const {fixture, create, action} = require('./lifecycle.cjs');

function totp(secret) {
    const bits = [...secret.toUpperCase()].map(char =>
        'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'.indexOf(char).toString(2).padStart(5, '0')).join('');
    const key = Buffer.from(bits.match(/.{8}/g).map(byte => parseInt(byte, 2)));
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)));
    const digest = createHmac('sha1', key).update(counter).digest();
    return String((digest.readUInt32BE(digest[19] & 15) & 0x7fffffff) % 1000000).padStart(6, '0');
}

async function main() {
    for (const file of ['appointments.cjs', 'lifecycle.cjs', 'browser.cjs', 'fixture.php']) {
        console.log('EXECUTION_BINDING', JSON.stringify({file,
            sha256: createHash('sha256').update(fs.readFileSync(path.join(__dirname, file))).digest('hex')}));
    }
    console.log('CORE_BINDINGS', JSON.stringify(fixture('bindings')));
    console.log('APPOINTMENTS_BINDINGS', JSON.stringify(fixture('appointments-bindings')));
    const moduleUrl = fixture('shared-module-url');
    console.log('SHARED_HELPER_BINDING', JSON.stringify(moduleUrl));
    check('Installed shared check edits the lowest administrator module id, not a lower site id',
        moduleUrl.existing_url === `index.php?option=com_modules&task=module.edit&client_id=1&id=${moduleUrl.module_ids[1]}`);
    check('Installed shared check adds the registered module when administrator instances are absent',
        moduleUrl.missing_url === `index.php?option=com_modules&task=module.add&client_id=1&eid=${moduleUrl.extension_id}`
        && moduleUrl.rolled_back);
    fixture('admission-reset');
    fixture('appointments-reset-calendars');
    const browser = await chromium.launch({channel: 'msedge', headless: true});
    try {
        const page = await browser.newPage();
        const email = `coupled-${new URL(root).port}-${Date.now()}@example.test`;
        const booking = fixture('appointments-booking', email);
        const copy = fixture('appointments-copy', booking.booking_id);
        check('Native calendar services create a real sealed booking copy', copy.objects === 1);
        const mail = await create(page, email);
        check('Actual synthetic-mail token confirms through native Core',
            (await submit(page, email, 'confirm', mail.token)).includes('No data has been removed'));
        const receipt = fixture('snapshot', email).requests[0];
        const admin = await browser.newPage();
        await admin.goto(`${root}/administrator/index.php`);
        await admin.locator('#mod-login-username').fill(process.env.JOOMLA_ADMIN_USERNAME);
        await admin.locator('#mod-login-password').fill(process.env.JOOMLA_ADMIN_PASSWORD);
        await Promise.all([admin.waitForNavigation(), admin.locator('button[type="submit"]').click()]);
        if (!booking.host_second_factor) {
            check('Native authenticator plugin is enabled in the synthetic fixture',
                fixture('appointments-host-state').totp_enabled === 1);
            const staffContext = await browser.newContext();
            try {
                const staff = await staffContext.newPage();
                await staff.goto(`${root}/index.php?option=com_users&view=login`);
                await staff.locator('#username').fill('privacy-fixture-host');
                await staff.locator('#password').fill(process.env.JOOMLA_ADMIN_PASSWORD);
                await Promise.all([staff.waitForNavigation(), staff.locator('form.com-users-login__form button[type="submit"]').click()]);
                await staff.goto(`${root}/index.php?option=com_users&task=method.add&method=totp&user_id=${booking.host_user_id}`);
                const form = staff.locator('#com-users-method-edit');
                if (!await form.count() || !await form.locator('input[name="key"]').count()) {
                    if (process.env.PRIVACY_TEST_REPORT_DIR) {
                        fs.writeFileSync(path.join(process.env.PRIVACY_TEST_REPORT_DIR, 'mfa-setup-failure.html'), await staff.content());
                    }
                    throw new Error('Native authenticator setup did not render; private page evidence retained.');
                }
                await form.locator('input[name="code"]').fill(totp(await form.locator('input[name="key"]').inputValue()));
                await Promise.all([staff.waitForNavigation(), form.locator('button[type="submit"]').click()]);
                check('Native Core MFA setup validates a real authenticator code for the synthetic staff owner',
                    fixture('appointments-host-state').second_factor);
            } finally {
                await staffContext.close();
            }
        }
        let url = `${root}/administrator/index.php?option=com_privacy&view=request&id=${receipt.id}`;
        let bookingId = booking.booking_id;
        const remove = async (review = false) => {
            await admin.goto(url);
            if (review) {
                await admin.locator('input[name="jform[privacy_remediation_reviewed]"]').check();
            }
            await action(admin, 'request.remove');
            console.log('NATIVE_MESSAGES', JSON.stringify(await admin.locator('joomla-alert').allTextContents()));
            return fixture('appointments-snapshot', bookingId);
        };
        const complete = async (requestEmail = email, genericEnabled = true) => {
            await admin.goto(url);
            const review = admin.locator('input[name="jform[privacy_scope_reviewed]"]');
            if (!genericEnabled) {
                check('Authorized native request view has no generic review field with adapter natively disabled',
                    await review.count() === 0);
            }
            if (await review.count()) await review.check();
            await action(admin, 'request.complete');
            if (!genericEnabled) {
                const messages = await admin.locator('joomla-alert').allTextContents();
                console.log('INDEPENDENT_CONTENT_GUARD_MESSAGES', JSON.stringify(messages));
                check('Native Core Complete reports the independent Appointments content-guard veto',
                    messages.join(' ').includes('Appointments reported incomplete removal.'));
            }
            return Number(fixture('snapshot', requestEmail).requests[0].status);
        };
        fixture('probe', 'conference-fail');
        let state = await remove();
        check('First partial commits anonymisation but actual failed internal copy and room still exist',
            state.booking.email_cleared === 1 && state.booking.room_cleared === 1
            && state.copies === 1 && state.room_hashes.includes(booking.room_hash));
        check('Minimal encrypted actionable cleanup survives loss of email/conference fields',
            state.cleanup.length === 1 && Number(state.cleanup[0].request_id) === Number(receipt.id)
            && Number(state.cleanup[0].guest) === 0);
        check('Reviewed Complete is blocked after committed partial erasure', await complete() === 1);
        fixture('generic-disable');
        try {
            check('Bundled native content guard blocks partial completion even without the generic adapter',
                await complete(email, false) === 1);
        } finally {
            fixture('generic-enable');
        }
        fixture('appointments-copy-unblock');
        fixture('probe', 'conference-allow');
        fixture('appointments-provenance-toggle', booking.booking_id);
        state = await remove(true);
        check('Tampered AAD alone rejects cleanup while real copy deletion and provider teardown are available',
            state.copies === 1 && state.cleanup.length === 1 && state.room_hashes.includes(booking.room_hash));
        fixture('appointments-provenance-toggle', booking.booking_id);
        fixture('appointments-payload-toggle', booking.booking_id);
        state = await remove(true);
        check('Tampered ciphertext alone rejects cleanup without altering actual copy or room resources',
            state.copies === 1 && state.cleanup.length === 1 && state.room_hashes.includes(booking.room_hash));
        fixture('appointments-payload-toggle', booking.booking_id);
        fixture('appointments-copy-block', booking.booking_id);
        fixture('probe', 'conference-fail');
        state = await remove(true);
        check('A repeated no-match removal cannot clear unresolved cleanup',
            state.copies === 1 && state.cleanup.length === 1 && state.room_hashes.includes(booking.room_hash));
        check('Complete stays blocked after empty retry', await complete() === 1);
        fixture('appointments-copy-unblock');
        state = await remove();
        check('Core Remove actually retries and deletes the previously orphaned internal copy',
            state.copies === 0 && state.cleanup.length === 1 && state.room_hashes.includes(booking.room_hash));
        check('Fixing one cleanup does not waive the other failure', await complete() === 1);
        // Explicit authorized deletion of this now-empty synthetic calendar also resolves
        // the separate feature-off/unsearched calendar warning; it grants no content key.
        fixture('appointments-calendar-delete', copy.calendar_id);
        fixture('probe', 'conference-allow');
        state = await remove();
        check('Real provider cleanup uses retained encrypted reference after original room was cleared',
            state.copies === 0 && state.cleanup.length === 0 && !state.room_hashes.includes(booking.room_hash));
        check('Successful repair and retry do not complete the Core request automatically',
            Number(fixture('snapshot', email).requests[0].status) === 1);
        check('Separate reviewed native Complete succeeds only after actual remediation', await complete() === 2);
        const final = fixture('snapshot', email).requests[0];
        check('Partial retries preserve original Core receipt id and date',
            Number(final.id) === Number(receipt.id) && final.requested_at === receipt.requested_at);

        const guestEmail = `coupled-guest-${Date.now()}@example.test`;
        const guestBooking = fixture('appointments-booking', guestEmail, 'guest');
        bookingId = guestBooking.booking_id;
        const guestCopy = fixture('appointments-copy', bookingId);
        const guestMail = await create(page, guestEmail);
        check('Additional guest confirms its own Core request without an account',
            (await submit(page, guestEmail, 'confirm', guestMail.token)).includes('No data has been removed'));
        const guestReceipt = fixture('snapshot', guestEmail).requests[0];
        url = `${root}/administrator/index.php?option=com_privacy&view=request&id=${guestReceipt.id}`;
        state = await remove();
        const primaryHash = createHash('sha256').update('other@example.test').digest('hex');
        const guestsHash = createHash('sha256').update('bystander@example.test').digest('hex');
        check('Guest partial preserves the primary booking, other guest and conference but records failed copy cleanup',
            Number(state.booking.state) === 1 && state.primary_hash === primaryHash && state.guests_hash === guestsHash
            && state.copies === 1 && state.cleanup.length === 1 && Number(state.cleanup[0].guest) === 1
            && state.room_hashes.includes(guestBooking.room_hash));
        state = await remove(true);
        check('No-match guest retry and acknowledgment cannot clear an actual failed internal copy',
            state.copies === 1 && state.cleanup.length === 1 && await complete(guestEmail) === 1);
        fixture('appointments-copy-unblock');
        state = await remove();
        check('Guest repair removes the old copy without deleting its owner conference or other guest',
            state.copies === 0 && state.cleanup.length === 0 && state.primary_hash === primaryHash
            && state.guests_hash === guestsHash && state.room_hashes.includes(guestBooking.room_hash));
        fixture('appointments-calendar-delete', guestCopy.calendar_id);
        await remove();
        check('Guest request completes separately only after real cleanup and scope review', await complete(guestEmail) === 2);

        const rebuildEmail = `rebuild-guest-${Date.now()}@example.test`;
        const rebuildBooking = fixture('appointments-booking', rebuildEmail, 'guest');
        bookingId = rebuildBooking.booking_id;
        const rebuildCopy = fixture('appointments-copy', bookingId);
        fixture('appointments-copy-unblock');
        fixture('appointments-rebuild-setup', bookingId);
        const rebuildMail = await create(page, rebuildEmail);
        check('Enabled-calendar guest repair starts from a genuine confirmed Core request',
            (await submit(page, rebuildEmail, 'confirm', rebuildMail.token)).includes('No data has been removed'));
        const rebuildReceipt = fixture('snapshot', rebuildEmail).requests[0];
        url = `${root}/administrator/index.php?option=com_privacy&view=request&id=${rebuildReceipt.id}`;
        state = await remove();
        check('Real enabled-calendar putSealed failure remains actionable after old guest copy was removed',
            state.copies === 0 && state.cleanup.length === 1 && state.primary_hash === primaryHash
            && state.guests_hash === guestsHash && state.room_hashes.includes(rebuildBooking.room_hash));
        state = await remove(true);
        check('No-match guest rebuild failure cannot silently forget provenance or permit Complete',
            state.copies === 0 && state.cleanup.length === 1 && await complete(rebuildEmail) === 1);
        fixture('appointments-rebuild-unblock');
        state = await remove();
        check('Native Remove genuinely rebuilds the remaining participants copy and resolves operational provenance',
            state.copies === 1 && state.cleanup.length === 0 && state.room_hashes.includes(rebuildBooking.room_hash));
        const opened = fixture('appointments-copy-inspect', rebuildCopy.calendar_id, rebuildEmail);
        check('Genuine owner-key decryption verifies rebuilt copy omits removed guest and retains primary/bystander',
            opened.opened === 1 && opened.removed_guest_absent && opened.primary_present && opened.bystander_present);
        check('Fixture inspection key does not grant the Core handler unreadable-calendar completion',
            await complete(rebuildEmail) === 1);
        fixture('appointments-calendar-delete', rebuildCopy.calendar_id);
        fixture('appointments-rebuild-restore');
        await remove();
        check('Rebuild request closes only after actual repair and separate remaining-scope resolution',
            await complete(rebuildEmail) === 2);
    } finally {
        fixture('appointments-copy-unblock');
        fixture('appointments-rebuild-restore');
        fixture('probe', 'block');
        await browser.close();
    }
}
main().catch(error => {console.error(error); process.exitCode = 1;});
