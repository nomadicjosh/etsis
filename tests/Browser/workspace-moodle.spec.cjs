const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
async function fixture(page, options = {}) {
    const calls = [], errors = [];
    let checks = 0, sectionLinked = false;
    const approved = { id: '01K9Q4S3E5W4Y3P2F1R6V7N8M9', instance_fingerprint: 'a'.repeat(64), site_fingerprint: 'b'.repeat(64), approved_at: '2026-11-11T12:00:00Z' };
    let binding = options.bound ? approved : null;
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        calls.push({ path, method: route.request().method(), data, csrf: route.request().headers()['x-csrf-token'] });
        let result = {};
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/moodle/status/') result = { enabled: !options.disabled };
        else if (path === '/sis/moodle/sections/') result = { sync_enabled: false, items: [{ id: '01KAT5HZXS0000000000000001', term_code: '26/FA', code: 'MATH.101.01', title: 'Fictional Algebra', moodle_course_id: sectionLinked ? '81' : null, issue: 'none', members_needing_setup: sectionLinked ? 2 : 0 }] };
        else if (path.endsWith('/link/') && path.startsWith('/sis/moodle/sections/')) {
            sectionLinked = true;
            if (options.sectionInterrupted) { await route.fulfill({ status: 503, json: { error: 'Fictional interrupted reply. Search again to check the saved link.' } }); return; }
            result = { linked: true };
        }
        else if (path === '/sis/moodle/installation/') result = { binding };
        else if (path === '/sis/moodle/installation/review/') {
            if (options.connectorDisabled) {
                await route.fulfill({ status: 503, json: { error: 'Moodle instance verification is disabled. In Moodle, enable "Enable instance verification" and save changes.' } });
                return;
            }
            if (options.delayReview) await new Promise(resolve => setTimeout(resolve, 250));
            result = { review_token: 'fictional-private-review-token', instance_fingerprint: approved.instance_fingerprint, site_fingerprint: approved.site_fingerprint, expires_at: 1794410000 };
        }
        else if (path === '/sis/moodle/installation/confirm/') {
            binding = approved;
            if (options.uncertainApproval) {
                await route.fulfill({ status: 503, json: { error: 'Fictional response interrupted.' } });
                return;
            }
            result = { binding };
        }
        else if (path === '/sis/moodle/installation/verify/') {
            if (options.mismatch) {
                await route.fulfill({ status: 409, json: { error: 'This record changed. Reload it before trying again.' } });
                return;
            }
            result = { binding, verified: true };
        }
        else if (path === '/sis/moodle/check/') {
            checks++;
            if (options.failure && checks === 1) {
                await route.fulfill({ status: 503, json: { error: 'Moodle check failed. Verify the institutional configuration.' }, headers: { 'Cache-Control': 'private, no-store' } });
                return;
            }
            result = options.disabledDuringCheck ? { enabled: false, capabilities: null } :
                { enabled: true, capabilities: { core_webservice_get_site_info: true, local_etsis_get_instance_info: true, core_user_get_users_by_field: false,
                    core_course_get_courses_by_field: false, core_course_create_courses: true,
                    core_course_update_courses: true, enrol_manual_enrol_users: true,
                    enrol_manual_unenrol_users: false, gradereport_user_get_grade_items: true,
                    '<script>window.moodleExecuted = true</script>': true } };
            if (options.sectionService) {
                result = { enabled: true, capabilities: {
                    core_webservice_get_site_info: true, local_etsis_get_instance_info: true,
                    local_etsis_get_account_policy: true, local_etsis_provision_account: true,
                    local_etsis_get_account_receipt: true, local_etsis_get_roster_policy: true,
                    local_etsis_sync_roster_member: !options.missingRoster,
                    core_user_get_users_by_field: true, core_course_get_courses_by_field: true,
                } };
            }
        }
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto(options.denied ? '/admin/moodle-denied-fixture/' : '/admin/');
    return { calls, errors };
}
async function accessible(page) {
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(v => ({ id: v.id, targets: v.nodes.map(n => n.target) }))).toEqual([]);
}
test('Moodle status opens without automatic network diagnostics and explicit check uses CSRF', async ({ page }) => {
    const f = await fixture(page);
    expect(f.calls.some(c => c.path.startsWith('/sis/moodle/'))).toBe(false);
    await page.getByRole('link', { name: 'Moodle integration', exact: true }).click();
    await expect(page.locator('#moodle-status')).toContainText('No connection check has run');
    expect(f.calls.filter(c => c.path === '/sis/moodle/check/')).toHaveLength(0);
    await page.getByRole('button', { name: 'Check Moodle connection', exact: true }).click();
    await expect(page.locator('#moodle-status')).toContainText('Site URL and REST connection verified');
    await expect(page.locator('#moodle-results')).toContainText('Targeted user lookup');
    await expect(page.locator('#moodle-results')).toContainText('Installation identity plugin');
    await expect(page.locator('#moodle-results')).toContainText('Not advertised');
    await expect(page.locator('#moodle-results')).not.toContainText('<script>');
    expect(f.calls.find(c => c.path === '/sis/moodle/check/')).toMatchObject({ method: 'POST', data: {}, csrf: 'fictional-test-token' });
    expect(await page.evaluate(() => window.moodleExecuted)).toBeUndefined();
    await expect(page.locator('#moodle-results h3')).toBeFocused();
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.calls.every(c => !/inspect|sync|credential/.test(c.path))).toBe(true);
    expect(f.errors).toEqual([]);
});
test('disabled integration does not allow a check and refreshing status makes no external request', async ({ page }) => {
    const f = await fixture(page, { disabled: true });
    await page.getByRole('link', { name: 'Moodle integration', exact: true }).click();
    await expect(page.locator('#moodle-status')).toContainText('disabled');
    await expect(page.getByRole('button', { name: 'Check Moodle connection', exact: true })).toBeDisabled();
    await expect(page.getByRole('button', { name: 'Review installation', exact: true })).toBeDisabled();
    await expect(page.getByLabel('Reason for approving this installation')).toBeDisabled();
    await page.getByRole('button', { name: 'Refresh status', exact: true }).click();
    await expect(page.locator('#moodle-status')).toContainText('disabled');
    expect(f.calls.filter(c => c.path === '/sis/moodle/check/')).toHaveLength(0);
    expect(f.errors).toEqual([]);
});

test('a complete section service shows expected missing generic functions separately', async ({ page }) => {
    const f = await fixture(page, { sectionService: true });
    await page.getByRole('link', { name: 'Moodle integration', exact: true }).click();
    await page.getByRole('button', { name: 'Check Moodle connection', exact: true }).click();
    await expect(page.locator('#moodle-results')).toContainText('9 of 9 connector functions advertised');
    const connector = page.locator('[data-moodle-connector-functions]');
    await expect(connector).toContainText('Managed student and instructor access');
    await expect(connector).not.toContainText('Not advertised');
    await expect(connector).not.toContainText('Manual unenrollment');
    const additional = page.locator('#moodle-results details');
    await expect(additional).not.toHaveAttribute('open');
    await additional.locator('summary').focus();
    await page.keyboard.press('Enter');
    await expect(additional).toHaveAttribute('open', '');
    await expect(additional).toContainText('Missing functions here are expected');
    await expect(additional).toContainText('Not advertised');
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    expect(f.calls.filter(c => c.path === '/sis/moodle/check/')).toHaveLength(1);
    expect(f.errors).toEqual([]);
});

test('a missing connector function remains visible in the main service list', async ({ page }) => {
    const f = await fixture(page, { sectionService: true, missingRoster: true });
    await page.getByRole('link', { name: 'Moodle integration', exact: true }).click();
    await page.getByRole('button', { name: 'Check Moodle connection', exact: true }).click();
    await expect(page.locator('#moodle-results')).toContainText('8 of 9 connector functions advertised');
    await expect(page.locator('[data-moodle-connector-functions]')).toContainText('Not advertised');
    await expect(page.locator('#moodle-results')).toContainText('does not verify course permissions or enable synchronization');
    expect(f.errors).toEqual([]);
});

test('installation review requires explicit keyboard confirmation and keeps its token out of storage', async ({ page }) => {
    const f = await fixture(page);
    await page.getByRole('link', { name: 'Moodle integration', exact: true }).click();
    await expect(page.locator('#moodle-installation-status')).toContainText('No installation approved');
    expect(f.calls.filter(c => /installation\/(review|confirm|verify)/.test(c.path))).toHaveLength(0);
    await page.getByLabel('Reason for approving this installation').fill('Institution-approved teaching platform.');
    await page.getByRole('button', { name: 'Review installation', exact: true }).click();
    await expect(page.locator('#moodle-installation-preview h3')).toBeFocused();
    await expect(page.locator('#moodle-installation-preview')).toContainText('a'.repeat(64));
    const confirm = page.getByRole('button', { name: 'Approve reviewed installation', exact: true });
    await expect(confirm).toBeDisabled();
    expect(f.calls.some(c => c.path.endsWith('/confirm/'))).toBe(false);
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    const acknowledgement = page.getByRole('checkbox', { name: 'I checked the fingerprint', exact: false });
    await acknowledgement.focus();
    await page.keyboard.press('Space');
    await expect(confirm).toBeEnabled();
    await page.keyboard.press('Tab');
    await expect(confirm).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(page.locator('#moodle-installation-status')).toContainText('Installation approved');
    await expect(page.locator('#moodle-installation-status')).toBeFocused();
    await expect(page.locator('#moodle-installation-form')).toBeHidden();
    await expect(page.locator('#moodle-installation-preview')).toBeEmpty();
    await expect(page.locator('body')).not.toContainText('fictional-private-review-token');
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    for (const call of f.calls.filter(c => /installation\/(review|confirm)\//.test(c.path))) {
        expect(call).toMatchObject({ method: 'POST', csrf: 'fictional-test-token' });
    }
    await page.getByRole('button', { name: 'Verify approved installation', exact: true }).click();
    await expect(page.locator('#moodle-installation-status')).toContainText('matches the saved approval');
    expect(f.errors).toEqual([]);
});

test('disabled connector guidance is shown in the review panel without creating an approval', async ({ page }) => {
    const f = await fixture(page, { connectorDisabled: true });
    await page.getByRole('link', { name: 'Moodle integration', exact: true }).click();
    await page.getByLabel('Reason for approving this installation').fill('Fictional institutional setup.');
    await page.getByRole('button', { name: 'Review installation', exact: true }).click();
    await expect(page.locator('#moodle-installation-status')).toContainText('Enable instance verification');
    await expect(page.locator('#workspace-alert')).toContainText('Enable instance verification');
    await expect(page.locator('#moodle-installation-approval')).toBeHidden();
    expect(f.calls.some(c => c.path.endsWith('/confirm/'))).toBe(false);
    expect(f.errors).toEqual([]);
});

test('canceling and leaving a review clear pending approval without a write', async ({ page }) => {
    const f = await fixture(page);
    await page.getByRole('link', { name: 'Moodle integration', exact: true }).click();
    const reason = page.getByLabel('Reason for approving this installation');
    await reason.fill('Fictional initial setup.');
    await page.getByRole('button', { name: 'Review installation', exact: true }).click();
    await expect(page.locator('#moodle-installation-approval')).toBeVisible();
    await page.getByRole('button', { name: 'Cancel review', exact: true }).click();
    await expect(reason).toBeFocused();
    await expect(page.locator('#moodle-installation-preview')).toBeEmpty();
    await page.getByRole('button', { name: 'Review installation', exact: true }).click();
    await expect(page.locator('#moodle-installation-approval')).toBeVisible();
    await page.getByRole('link', { name: 'Overview', exact: true }).click();
    await page.getByRole('link', { name: 'Moodle integration', exact: true }).click();
    await expect(reason).toHaveValue('');
    await expect(page.locator('#moodle-installation-approval')).toBeHidden();
    expect(f.calls.some(c => c.path.endsWith('/confirm/'))).toBe(false);
    expect(f.errors).toEqual([]);
});

test('refresh discovers an approval after an interrupted response without automatically confirming twice', async ({ page }) => {
    const f = await fixture(page, { uncertainApproval: true });
    await page.getByRole('link', { name: 'Moodle integration', exact: true }).click();
    await page.getByLabel('Reason for approving this installation').fill('Fictional initial setup.');
    await page.getByRole('button', { name: 'Review installation', exact: true }).click();
    await page.getByRole('checkbox', { name: 'I checked the fingerprint', exact: false }).check();
    await page.getByRole('button', { name: 'Approve reviewed installation', exact: true }).click();
    await expect(page.locator('#moodle-installation-status')).toContainText('Refresh saved details');
    await page.getByRole('button', { name: 'Refresh status', exact: true }).click();
    await expect(page.locator('#moodle-installation-saved')).toBeVisible();
    await expect(page.locator('#moodle-installation-form')).toBeHidden();
    expect(f.calls.filter(c => c.path.endsWith('/confirm/'))).toHaveLength(1);
    expect(f.errors).toEqual([]);
});

test('a failed verification retains the saved approval without offering replacement', async ({ page }) => {
    const f = await fixture(page, { bound: true, mismatch: true });
    await page.getByRole('link', { name: 'Moodle integration', exact: true }).click();
    await page.getByRole('button', { name: 'Verify approved installation', exact: true }).click();
    await expect(page.locator('#moodle-installation-status')).toContainText('has not been replaced');
    await expect(page.locator('#moodle-installation-saved')).toContainText('a'.repeat(64));
    await expect(page.locator('#moodle-installation-form')).toBeHidden();
    expect(f.calls.some(c => /\/(review|confirm)\//.test(c.path))).toBe(false);
    expect(f.errors).toEqual([]);
});
test('outage permits retry while disabling during a check leaves the control disabled', async ({ page }) => {
    const f = await fixture(page, { failure: true, disabledDuringCheck: true });
    await page.getByRole('link', { name: 'Moodle integration', exact: true }).click();
    const check = page.getByRole('button', { name: 'Check Moodle connection', exact: true });
    await expect(check).toBeEnabled();
    await check.click();
    await expect(page.locator('#moodle-status')).toContainText('could not be verified');
    await expect(check).toBeEnabled();
    await check.click();
    await expect(page.locator('#moodle-status')).toContainText('disabled');
    await expect(check).toBeDisabled();
    expect(f.calls.filter(c => c.path === '/sis/moodle/check/')).toHaveLength(2);
    expect(f.errors).toEqual([]);
});
test('unrelated office grants do not show Moodle navigation or request its status', async ({ page }) => {
    const f = await fixture(page, { denied: true });
    await expect(page.getByRole('link', { name: 'Moodle integration', exact: true })).toHaveCount(0);
    expect(f.calls.some(c => c.path.startsWith('/sis/moodle/'))).toBe(false);
    expect(f.errors).toEqual([]);
});

test('section mapping searches on demand and confirms one course with accessible explicit acknowledgement', async ({ page }) => {
    const f = await fixture(page, { bound: true });
    await page.getByRole('link', { name: 'Moodle integration', exact: true }).click();
    expect(f.calls.some(c => c.path.startsWith('/sis/moodle/sections/'))).toBe(false);
    await page.getByLabel('Search by section code').fill('MATH.101');
    await page.getByRole('button', { name: 'Find sections', exact: true }).click();
    await expect(page.locator('#moodle-section-results')).toContainText('26/FA · MATH.101.01 · Fictional Algebra');
    await page.getByLabel('Moodle course ID', { exact: true }).fill('81');
    await page.getByLabel('Administrative reason', { exact: true }).fill('Independently verified fictional section.');
    const ack = page.getByRole('checkbox', { name: 'I independently verified that this Moodle course belongs to this SIS section.' });
    await ack.focus(); await page.keyboard.press('Space');
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.getByRole('button', { name: 'Confirm Moodle course link', exact: true }).click();
    await expect(page.locator('#moodle-section-results')).toContainText('Course link saved');
    expect(f.calls.find(c => c.path.endsWith('/link/'))).toMatchObject({ method: 'POST', csrf: 'fictional-test-token', data: { course: '81', acknowledged: true } });
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.calls.some(c => /sync|provision/.test(c.path))).toBe(false);
    expect(f.errors).toEqual([]);
});
test('searching after an interrupted course-link confirmation shows the retained mapping', async ({ page }) => {
    const f = await fixture(page, { bound: true, sectionInterrupted: true });
    await page.getByRole('link', { name: 'Moodle integration', exact: true }).click();
    await page.getByLabel('Search by section code').fill('MATH');
    await page.getByRole('button', { name: 'Find sections', exact: true }).click();
    await page.getByLabel('Moodle course ID', { exact: true }).fill('81');
    await page.getByLabel('Administrative reason', { exact: true }).fill('Fictional course check.');
    await page.getByRole('checkbox', { name: 'I independently verified that this Moodle course belongs to this SIS section.' }).check();
    await page.getByRole('button', { name: 'Confirm Moodle course link', exact: true }).click();
    await expect(page.locator('#workspace-alert')).toContainText('Search again');
    await page.getByRole('button', { name: 'Find sections', exact: true }).click();
    await expect(page.locator('#moodle-section-results')).toContainText('Moodle course 81');
    await expect(page.locator('#moodle-section-results')).toContainText('2 members still need setup');
    await expect(page.getByRole('button', { name: 'Confirm Moodle course link', exact: true })).toHaveCount(0);
    expect(f.calls.filter(c => c.path.endsWith('/link/'))).toHaveLength(1);
    expect(f.errors).toEqual([]);
});
