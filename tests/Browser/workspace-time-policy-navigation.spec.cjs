const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => String(n).padStart(26, '0');
const body = page => page.locator('#drawer-body');
const button = (page, name) => body(page).getByRole('button', { name, exact: true });
async function accessible(page) {
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(row => ({ id: row.id, nodes: row.nodes.map(node => node.target) }))).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    expect(await body(page).evaluate(el => el.scrollWidth <= el.clientWidth + 1)).toBe(true);
}
async function fixture(page) {
    const f = { calls: [], errors: [], version: 0, publications: new Set(), deny: null, delay: null, rejectReview: false, interrupt: false };
    f.rules = { daily_minutes: null, weekly_minutes: null, overlaps: false, mode: 'advisory', timezone: 'America/Los_Angeles', contact_unit_minutes: 50, contact_rounding: 'nearest' };
    let release; const pending = new Promise(resolve => { release = resolve; }); f.release = release;
    page.on('pageerror', error => f.errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const req = route.request(), path = new URL(req.url()).pathname;
        const data = req.postData() ? JSON.parse(new URLSearchParams(req.postData()).get('_workspace_json')) : {};
        f.calls.push({ path, data, method: req.method(), csrf: req.headers()['x-csrf-token'] });
        const delayed = f.delay === path;
        if (f.deny === path) return route.fulfill({ status: 403, json: { error: 'Fictional policy access denied.' } });
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/time-policy/') result = { rules: f.rules, version: f.version, publication_id: f.version ? id(9) : null };
        else if (path === '/sis/time-policy/review/') {
            if (f.rejectReview) { f.rejectReview = false; return route.fulfill({ status: 409, json: { error: 'Fictional policy changed. Reload the current policy.' } }); }
            result = { ...data, publication_id: id(10 + f.calls.filter(call => call.path === path).length), review_token: 'fictional-actor-bound-policy-proof' };
            f.review = result;
        } else if (path === '/sis/time-policy/publish/') {
            if (!f.publications.has(data.publication_id)) { f.publications.add(data.publication_id); f.rules = data.rules; f.version++; }
            if (f.interrupt) { f.interrupt = false; return route.fulfill({ status: 503, json: { error: 'Fictional publication response interrupted. Retry confirmation.' } }); }
            result = { status: 'published', version: f.version };
        }
        if (delayed) await pending;
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/'); await page.locator('.workspace-nav-link[data-workspace="employees"]').click();
    f.open = () => page.getByRole('button', { name: 'Institutional time review policy', exact: true }).click();
    f.reason = async () => page.getByLabel('Reason for policy publication', { exact: true }).fill('Fictional institution policy\nReviewed by HR.');
    f.ready = async () => { await f.open(); await f.reason(); await button(page, 'Review time policy').click(); await expect(button(page, 'Confirm time policy')).toBeVisible(); };
    return f;
}

test('policy settings and review separate clocked time, teaching contact and publication with retained draft values', async ({ page }) => {
    const f = await fixture(page); await f.open();
    await expect(page.getByRole('region', { name: 'Current policy', exact: true })).toContainText('Default settings');
    const clock = page.getByRole('group', { name: 'Clocked-time review', exact: true });
    const teaching = page.getByRole('group', { name: 'Teaching contact hours', exact: true });
    const publication = page.getByRole('group', { name: 'Policy publication', exact: true });
    expect((await clock.boundingBox()).y).toBeLessThan((await teaching.boundingBox()).y);
    expect((await teaching.boundingBox()).y).toBeLessThan((await publication.boundingBox()).y);
    await page.getByLabel('Daily limit in minutes (optional)', { exact: true }).fill('480');
    await page.getByLabel('Weekly limit in minutes (optional)', { exact: true }).fill('2400');
    await page.getByLabel('How flags affect approval', { exact: true }).selectOption('exception_required');
    await page.getByLabel('Flag overlapping work intervals', { exact: true }).check();
    await page.getByLabel('Instructional minutes per contact hour', { exact: true }).fill('60');
    await page.getByLabel('Contact-hours rounding', { exact: true }).selectOption('down');
    await f.reason(); await button(page, 'Review time policy').click();
    await expect(page.getByRole('region', { name: 'Clocked-time review', exact: true })).toContainText('480 minutes');
    await expect(page.getByRole('region', { name: 'Teaching contact hours', exact: true })).toContainText('Typical teaching week');
    await expect(page.getByRole('region', { name: 'Publication reason', exact: true })).toContainText('Reviewed by HR.');
    await button(page, 'Back to policy settings').click();
    await expect(page.getByLabel('Daily limit in minutes (optional)', { exact: true })).toHaveValue('480');
    await expect(page.getByLabel('Instructional minutes per contact hour', { exact: true })).toHaveValue('60');
    await expect(page.getByLabel('Contact-hours rounding', { exact: true })).toHaveValue('down');
    await expect(page.getByLabel('Flag overlapping work intervals', { exact: true })).toBeChecked();
    await expect(page.getByLabel('Reason for policy publication', { exact: true })).toHaveValue('Fictional institution policy\nReviewed by HR.');
    await button(page, 'Review time policy').click(); await button(page, 'Confirm time policy').click();
    await expect(body(page)).toContainText('Timesheet policy published.');
    expect(f.calls.find(call => call.path.endsWith('/publish/')).data).toEqual(f.review);
    expect(f.rules).toMatchObject({ daily_minutes: 480, weekly_minutes: 2400, overlaps: true, mode: 'exception_required', contact_unit_minutes: 60, contact_rounding: 'down' });
    expect(f.calls.filter(call => call.path.endsWith('/review/') || call.path.endsWith('/publish/')).every(call => call.csrf === 'fictional-test-token')).toBe(true);
    await button(page, 'View current policy').click();
    await expect(page.getByRole('region', { name: 'Current policy', exact: true })).toContainText('Published policy 1');
    await button(page, 'Back to Team & timesheets').click(); await expect(page.locator('#record-drawer')).not.toHaveClass(/\bshow\b/);
    expect(f.errors).toEqual([]);
});

test('stale review keeps settings and reason until an explicit reload reads current policy', async ({ page }) => {
    const f = await fixture(page); await f.open(); await f.reason();
    await page.getByLabel('Daily limit in minutes (optional)', { exact: true }).fill('480');
    f.rejectReview = true; await button(page, 'Review time policy').click();
    await expect(body(page)).toContainText('Fictional policy changed.');
    await expect(page.getByLabel('Daily limit in minutes (optional)', { exact: true })).toHaveValue('480');
    await expect(page.getByLabel('Reason for policy publication', { exact: true })).toHaveValue('Fictional institution policy\nReviewed by HR.');
    await accessible(page);
    f.version = 3; f.rules = { ...f.rules, contact_unit_minutes: 45 };
    await button(page, 'Reload current policy').click();
    await expect(page.getByLabel('Instructional minutes per contact hour', { exact: true })).toHaveValue('45');
    await expect(page.getByLabel('Reason for policy publication', { exact: true })).toHaveValue('');
    await f.reason(); await button(page, 'Review time policy').click();
    expect(f.review.version).toBe(3); expect(f.publications.size).toBe(0); expect(f.errors).toEqual([]);
});

test('interrupted accepted publication keeps its exact proof for a safe explicit retry', async ({ page }) => {
    const f = await fixture(page); await f.ready(); f.interrupt = true;
    await button(page, 'Confirm time policy').click(); await expect(body(page)).toContainText('Fictional publication response interrupted.');
    await expect(page.getByRole('region', { name: 'Publication reason', exact: true })).toContainText('Reviewed by HR.');
    await accessible(page);
    await button(page, 'Confirm time policy').click(); await expect(body(page)).toContainText('Timesheet policy published.');
    const publications = f.calls.filter(call => call.path.endsWith('/publish/'));
    expect(publications).toHaveLength(2); expect(publications[0].data).toEqual(publications[1].data);
    expect(f.publications.size).toBe(1); expect(f.version).toBe(1); expect(f.errors).toEqual([]);
});

for (const phase of ['read', 'review', 'publish']) {
    test(`denied policy ${phase} clears affected settings and retains the workspace return`, async ({ page }) => {
        const f = await fixture(page);
        if (phase === 'read') { f.deny = '/sis/time-policy/'; await f.open(); }
        else {
            await f.open(); await f.reason();
            if (phase === 'review') { f.deny = '/sis/time-policy/review/'; await button(page, 'Review time policy').click(); }
            else { await button(page, 'Review time policy').click(); f.deny = '/sis/time-policy/publish/'; await button(page, 'Confirm time policy').click(); }
        }
        await expect(body(page)).toContainText('Timesheet review policy access is no longer available.');
        await expect(page.getByLabel('Reason for policy publication', { exact: true })).toHaveCount(0);
        await expect(body(page)).not.toContainText('Reviewed by HR.');
        await expect(button(page, 'Confirm time policy')).toHaveCount(0);
        await button(page, 'Back to Team & timesheets').click(); await expect(page.locator('#record-drawer')).not.toHaveClass(/\bshow\b/);
        expect(f.publications.size).toBe(0); expect(f.errors).toEqual([]);
    });
}

test('policy settings and review remain accessible and reachable on phones and enlarged text', async ({ page }) => {
    const f = await fixture(page); await f.open(); await expect(page.getByRole('group', { name: 'Policy publication', exact: true })).toBeVisible();
    await accessible(page); await page.screenshot({ path: '/tmp/etsis-timesheet-policy-desktop.png' });
    await page.setViewportSize({ width: 320, height: 900 }); await accessible(page);
    await page.addStyleTag({ content: 'html { font-size: 200% !important; }' }); await accessible(page);
    await f.reason(); await button(page, 'Review time policy').scrollIntoViewIfNeeded(); await expect(button(page, 'Review time policy')).toBeInViewport();
    await button(page, 'Review time policy').click(); await expect(page.getByRole('region', { name: 'Teaching contact hours', exact: true })).toContainText('50');
    await accessible(page); await button(page, 'Confirm time policy').scrollIntoViewIfNeeded(); await expect(button(page, 'Confirm time policy')).toBeInViewport();
    await page.screenshot({ path: '/tmp/etsis-timesheet-policy-review-phone.png' });
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0); expect(f.errors).toEqual([]);
});

for (const phase of ['read', 'review', 'publish']) {
    test(`closing a pending policy ${phase} cannot reopen its drawer`, async ({ page }) => {
        const f = await fixture(page);
        if (phase === 'read') { f.delay = '/sis/time-policy/'; await f.open(); }
        else {
            await f.open(); await f.reason();
            if (phase === 'review') { f.delay = '/sis/time-policy/review/'; await button(page, 'Review time policy').click(); }
            else { await button(page, 'Review time policy').click(); f.delay = '/sis/time-policy/publish/'; await button(page, 'Confirm time policy').click(); }
        }
        await expect.poll(() => f.calls.some(call => call.path === f.delay)).toBe(true);
        await page.getByRole('button', { name: 'Close record', exact: true }).click(); await expect(body(page)).toBeEmpty();
        f.release(); await page.waitForLoadState('networkidle'); await expect(body(page)).toBeEmpty();
        await expect(page.locator('#record-drawer')).not.toHaveClass(/\bshow\b/);
        if (phase === 'publish') expect(f.publications.size).toBe(1);
        expect(f.errors).toEqual([]);
    });
}
