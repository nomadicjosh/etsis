const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
async function fixture(page, options = {}) {
    const calls = [], errors = [];
    let checks = 0;
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        calls.push({ path, method: route.request().method(), data, csrf: route.request().headers()['x-csrf-token'] });
        let result = {};
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/moodle/status/') result = { enabled: !options.disabled };
        else if (path === '/sis/moodle/check/') {
            checks++;
            if (options.failure && checks === 1) {
                await route.fulfill({ status: 503, json: { error: 'Moodle check failed. Verify the institutional configuration.' }, headers: { 'Cache-Control': 'private, no-store' } });
                return;
            }
            result = options.disabledDuringCheck ? { enabled: false, capabilities: null } :
                { enabled: true, capabilities: { core_webservice_get_site_info: true, core_user_get_users_by_field: false,
                    core_course_get_courses_by_field: false, core_course_create_courses: true,
                    core_course_update_courses: true, enrol_manual_enrol_users: true,
                    enrol_manual_unenrol_users: false, gradereport_user_get_grade_items: true,
                    '<script>window.moodleExecuted = true</script>': true } };
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
    await page.getByRole('button', { name: 'Refresh status', exact: true }).click();
    await expect(page.locator('#moodle-status')).toContainText('disabled');
    expect(f.calls.filter(c => c.path === '/sis/moodle/check/')).toHaveLength(0);
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
