const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => String(n).padStart(26, '0');
const body = page => page.locator('#drawer-body');
const button = (page, name) => body(page).getByRole('button', { name, exact: true });

async function fixture(page) {
    const f = { calls: [], errors: [], primary: false, status: 'pending', deny: false };
    const employee = id(1), entry = id(3), base = `/sis/employees/${employee}`;
    const profile = { id: employee, person_id: id(2), given_name: 'Alex', family_name: 'Example',
        institutional_id: 'P-FICTIONAL', job_title: 'Fictional assistant', active: true, is_self: false,
        revision: 'fictional-profile', earlier_history_unknown: false };
    const time = { id: entry, employee_id: employee, starts_at: '2026-08-10T16:00:00Z',
        ends_at: '2026-08-10T17:00:00Z', duration_seconds: 3600, version: 0 };
    const positions = [4, 5].map(n => ({ id: id(n), employee_id: employee,
        job: { id: id(6), code: `LIB.${n}`, title: `Fictional appointment ${n}`, currency: 'USD', basis: 'hourly' },
        title: `Fictional appointment ${n}`, job_code: `LIB.${n}`, currency: 'USD', basis: 'hourly',
        valid_from: '2026-08-01', valid_through: null, version: 1, voided: false,
        rate_minor: 2500, currency_digits: 2, weekly_minutes: 1200, grade: null, replaces_id: null,
        recorded_at: '2026-08-01T12:00:00Z', recorded_by: 'Fictional HR' }));
    page.on('pageerror', error => f.errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const req = route.request(), path = new URL(req.url()).pathname;
        const data = req.postData() ? JSON.parse(new URLSearchParams(req.postData()).get('_workspace_json')) : {};
        f.calls.push({ path, data, csrf: req.headers()['x-csrf-token'], method: req.method() });
        if (f.deny && path.includes('/primary-position/')) return route.fulfill({ status: 403, json: { error: 'Fictional primary access denied.' } });
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/data/employees/') result.items = [profile];
        else if (path === `${base}/`) result = profile;
        else if (path === `${base}/time-entries/`) result.items = [{ ...time, status: f.status }];
        else if (path === `/sis/timesheets/${entry}/`) result = { ...profile, ...time, id: entry, status: f.status,
            can_approve_exception: true, position: { code: 'LAB', title: 'Fictional worked position' },
            time_review: { scope: f.status === 'rejected' ? 'rejection' : 'selected_entry', policy: { version: 1, publication_id: id(8),
                rules: { daily_minutes: null, weekly_minutes: null, overlaps: false, mode: 'advisory',
                    timezone: 'America/Los_Angeles', contact_unit_minutes: 50, contact_rounding: 'nearest', require_primary_position: true } },
                flags: [], days: [{ date: '2026-08-10', seconds: 3600 }], weeks: [{ week: '2026-08-10', seconds: 3600 }],
                requires_exception: false, revision: (f.primary ? 'b' : 'a').repeat(64), approval_blocked: f.status === 'pending' && !f.primary,
                primary_position: { required: true, status: f.status === 'rejected' ? 'not_assessed' : f.primary ? 'current' : 'unrecorded', ...(f.status !== 'rejected' ? { checked_on: '2026-11-23' } : {}) } } };
        else if (path === `${base}/primary-position/`) result = { status: f.primary ? 'current' : 'unrecorded',
            version: f.primary ? 1 : 0, selection: f.primary ? { code: 'LIB.5', title: 'Fictional appointment 5' } : null, operation_id: id(9) };
        else if (path === `${base}/primary-position/review/`) result = { before: null,
            after: { code: 'LIB.5', title: 'Fictional appointment 5' }, review_token: 'fictional-primary-proof' };
        else if (path === `${base}/primary-position/confirm/`) { f.primary = true; result = { status: 'recorded' }; }
        else if (path === `${base}/positions/page/`) result = { items: [positions[data.after ? 1 : 0]],
            next_after: data.after ? null : positions[0].id, new_id: id(10), today: '2026-11-23' };
        else if (path.startsWith(`${base}/positions/`)) result = positions.find(p => path === `${base}/positions/${p.id}/`) || result;
        else if (path === `/sis/timesheets/${entry}/decision/`) { f.status = data.approve ? 'approved' : 'rejected'; result = { status: 'decided' }; }
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="employees"]').click();
    await page.getByRole('button', { name: 'Alex Example', exact: true }).click();
    await button(page, 'Review time entry').click();
    return f;
}

async function accessible(page) {
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(v => ({ id: v.id, nodes: v.nodes.map(n => n.target) }))).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
}

test('required primary approval returns through paged assignments and designation to the original time entry', async ({ page }) => {
    const f = await fixture(page);
    await expect(button(page, 'Approve time entry')).toBeDisabled();
    await expect(button(page, 'Reject time entry')).toBeEnabled();
    expect(await body(page).evaluate(el => [...el.childNodes].some(n => n.nodeType === Node.TEXT_NODE && n.textContent.trim() === 'null'))).toBe(false);
    await expect(body(page)).toContainText('The time-exception duty cannot bypass this requirement.');
    await page.setViewportSize({ width: 320, height: 812 });
    await accessible(page);
    await page.screenshot({ path: '/tmp/etsis-primary-approval-blocked.png', fullPage: true });
    await button(page, 'Review primary position').focus();
    await page.keyboard.press('Enter');
    await expect(button(page, 'Back to time entry')).toBeVisible();
    await button(page, 'Choose from position assignments').click();
    await expect(button(page, 'Back to time entry')).toBeVisible();
    await button(page, 'Next assignments').click();
    await button(page, 'Open position assignment').click();
    await button(page, 'Designate as primary position').click();
    await page.getByLabel('Reason for primary designation', { exact: true }).fill('Fictional authorized primary appointment.');
    await button(page, 'Review primary designation').click();
    await button(page, 'Revise primary designation').click();
    await expect(page.getByLabel('Reason for primary designation', { exact: true })).toHaveValue('Fictional authorized primary appointment.');
    await button(page, 'Review primary designation').click();
    await button(page, 'Confirm primary designation').click();
    await expect(body(page)).toContainText('Fictional appointment 5');
    await button(page, 'Back to assignment').click();
    await button(page, 'Back to assignments').click();
    await expect(body(page)).toContainText('Fictional appointment 5');
    await button(page, 'Back to time entry').click();
    await expect(button(page, 'Approve time entry')).toBeEnabled();
    await expect(body(page)).toContainText('Position worked: LAB · Fictional worked position');
    await expect(body(page)).toContainText('Current at this review');
    await button(page, 'Approve time entry').click();
    await accessible(page);
    await button(page, 'Confirm time approval').click();
    await expect(body(page)).toContainText('approved');
    expect(f.calls.find(c => c.path.endsWith('/decision/')).data.review_revision).toBe('b'.repeat(64));
    expect(f.calls.filter(c => c.method === 'POST').every(c => c.csrf === 'fictional-test-token')).toBe(true);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});

test('a missing primary designation still permits deliberate rejection', async ({ page }) => {
    const f = await fixture(page);
    await button(page, 'Review primary position').click();
    await button(page, 'Back to time entry').click();
    await expect(button(page, 'Approve time entry')).toBeDisabled();
    await button(page, 'Reject time entry').click();
    await button(page, 'Confirm time rejection').click();
    await expect(body(page)).toContainText('rejected');
    await expect(body(page)).toContainText('The primary designation was not assessed for this rejection');
    expect(f.calls.find(c => c.path.endsWith('/decision/')).data.approve).toBe(false);
    expect(f.errors).toEqual([]);
});

test('denied designation access clears the affected view and preserves the blocked time entry on return', async ({ page }) => {
    const f = await fixture(page);
    f.deny = true;
    await button(page, 'Review primary position').click();
    await expect(body(page)).toContainText('Access to this position view is no longer available.');
    await expect(button(page, 'Choose from position assignments')).toHaveCount(0);
    await button(page, 'Back to employment').click();
    await button(page, 'Review time entry').click();
    await expect(button(page, 'Approve time entry')).toBeDisabled();
    await expect(button(page, 'Reject time entry')).toBeEnabled();
    expect(f.calls.some(c => c.path.endsWith('/primary-position/confirm/'))).toBe(false);
    expect(f.errors).toEqual([]);
});
