'use strict';
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => `01K${String(n).padStart(23, '0')}`;
const section = id(1), student = id(2), person = id(3), entry = id(4);
async function fixture(page, options = {}) {
    const calls = [], errors = [];
    let reviews = 0, confirmations = 0, historyReads = 0;
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const url = new URL(route.request().url()), path = url.pathname, raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        calls.push({ path, search: url.search, data, csrf: route.request().headers()['x-csrf-token'] });
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path.startsWith('/sis/ui/data/')) result.items = path.includes('/sections/') ? [{
            id: section, code: 'HIST.101.A', course_id: id(8), term_id: id(9), term_code: '26/FA',
            course_title: 'Fictional History', capacity: 2, active: 1,
        }] : [];
        else if (path.endsWith('/waitlist/')) result = {
            section_code: 'HIST.101.A', policy: { enabled: true, maximum_active: 50 },
            waiting_count: 22, seats_remaining: 1, next_after: url.search ? null : 20,
            items: [{ entry_id: url.search ? entry : id(5), student_id: url.search ? student : id(6),
                current_name: url.search ? 'Alex <script>Example</script>' : 'Jordan Example',
                current_institutional_id: 'S-FICTIONAL', accepted_position: url.search ? 22 : 2,
                rank: url.search ? 21 : 1 }],
        };
        else if (path.endsWith('/bypass/history/')) {
            ++historyReads;
            if (options.delayHistory) await options.delayHistory();
            if (options.denyHistory && historyReads > 1) return route.fulfill({ status: 403, json: { error: 'Access denied.' } });
            result = { section_code: 'HIST.101.A', through: Number(url.searchParams.get('through') || 25),
                next_before: url.searchParams.has('before') ? null : 22,
                items: [{ current_name: 'Alex <script>Example</script>', current_institutional_id: 'S-FICTIONAL',
                    effective_at: '2026-11-23T12:00:00+00:00', earlier_requests: 20, reason: 'Recorded <b>institutional</b> reason',
                    actor_id: id(50), enrollment_id: id(99), accepted_position: url.searchParams.has('before') ? 21 : 24 }],
            };
        } else if (path.endsWith('/bypass/review/')) {
            ++reviews;
            if (options.delayReview) await options.delayReview(reviews);
            result = { operation_id: id(20 + reviews), student_id: student, person_id: person, entry_id: entry,
                version: 25, assessment_revision: 'a'.repeat(64), reason: data.reason,
                review_token: `fictional-bypass-proof-${reviews}`, earlier_requests: 20, can_confirm: !options.blocked,
                section: { code: 'HIST.101.A', course_code: 'HIST.101', course_title: 'Fictional History',
                    credits_milli: 3000, seats_remaining: options.blocked ? 0 : 1 },
                student: { given_name: 'Alex', family_name: '<script>Example</script>', institutional_id: 'S-FICTIONAL',
                    readiness: [{ code: 'activity', message: 'Student is active.' },
                        { code: 'waitlist', message: 'A separately authorized bypass is required.' }],
                    issues: [], charges: { currency: 'USD', total_minor: 15000, problems: [], items: [
                        { name: 'Section fee', minor: 10000, currency: 'USD', status: 'new' },
                        { name: 'Fictional term fee', minor: 5000, currency: 'USD', status: 'new' },
                        { name: 'Earlier assessment', minor: 3000, currency: 'USD', status: 'not_due' },
                    ] } },
            };
        } else if (path.endsWith('/bypass/confirm/')) {
            ++confirmations;
            if (options.confirmStatus && confirmations === 1) return route.fulfill({
                status: options.confirmStatus, json: { error: options.confirmStatus === 403
                    ? 'Access denied.' : 'Fictional confirmation needs review.' },
            });
            result = { enrollment_id: id(99), entry_id: entry, operation_id: data.operation_id, accepted_position: 26 };
        }
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto(options.noDuty ? '/admin/waitlist-office-fixture/' : '/admin/');
    await page.locator('.workspace-nav-link[data-workspace="courses"]').click();
    await page.getByRole('link', { name: 'Sections', exact: true }).click();
    await page.getByRole('button', { name: 'HIST.101.A', exact: true }).click();
    await page.getByRole('button', { name: 'Section waitlist', exact: true }).click();
    await page.getByRole('button', { name: 'Next waitlist page', exact: true }).click();
    if (!options.noDuty) await page.getByRole('button', { name: 'Review priority bypass', exact: true }).click();
    return { calls, errors };
}
async function review(page, reason = 'Fictional <b>institutional</b> reason') {
    await page.getByLabel('Institutional reason for this bypass', { exact: true }).fill(reason);
    await page.getByRole('button', { name: 'Review bypass and registration', exact: true }).click();
}
test('reviewed bypass shows actual new charges, preserves revised reasons and returns to the same queue page', async ({ page }) => {
    const f = await fixture(page);
    await review(page);
    await expect(page.locator('#drawer-body')).toContainText('Alex <script>Example</script>');
    await expect(page.locator('#drawer-body')).toContainText('Total new charges: $150.00');
    await expect(page.locator('#drawer-body')).toContainText('Already assessed; no new charge');
    await expect(page.locator('#drawer-body script')).toHaveCount(0);
    expect(f.calls.filter(call => call.path.endsWith('/bypass/confirm/'))).toHaveLength(0);
    await page.getByRole('button', { name: 'Revise reason', exact: true }).click();
    await expect(page.getByLabel('Institutional reason for this bypass')).toHaveValue('Fictional <b>institutional</b> reason');
    await review(page, 'Fictional corrected reason');
    const confirm = page.getByRole('button', { name: 'Confirm bypass and register', exact: true });
    await confirm.focus(); await page.keyboard.press('Enter');
    await expect(page.locator('#drawer-body')).toContainText('Registration completed with the reviewed waitlist bypass.');
    const command = f.calls.find(call => call.path.endsWith('/bypass/confirm/')).data;
    expect(command).toMatchObject({ entry_id: entry, student_id: student, reason: 'Fictional corrected reason',
        review_token: 'fictional-bypass-proof-2' });
    expect(Object.keys(command)).toHaveLength(8);
    await page.getByRole('button', { name: 'Back to queue', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Previous waitlist page', exact: true })).toBeVisible();
    expect(f.calls.filter(call => call.path.endsWith('/waitlist/')).at(-1).search).toBe('?after=20');
    expect(f.calls.filter(call => call.data).every(call => call.csrf === 'fictional-test-token')).toBe(true);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});
test('bypass confirmation is accessible at desktop and 320-pixel enlarged-text sizes', async ({ page }) => {
    const f = await fixture(page); await review(page);
    await expect(page.getByRole('button', { name: 'Confirm bypass and register', exact: true })).toBeVisible();
    await expect(page.locator('#drawer-body')).not.toContainText(/\bnull\b/);
    await page.screenshot({ path: '/tmp/etsis-waitlist-bypass-desktop.png' });
    await page.setViewportSize({ width: 320, height: 812 });
    await page.evaluate(() => { document.documentElement.style.fontSize = '200%'; });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(v => ({ id: v.id, targets: v.nodes.map(n => n.target) }))).toEqual([]);
    await page.getByRole('button', { name: 'Confirm bypass and register', exact: true }).scrollIntoViewIfNeeded();
    await page.screenshot({ path: '/tmp/etsis-waitlist-bypass-phone.png' });
    expect(f.errors).toEqual([]);
});
test('an uncertain confirmation retains the exact reviewed command for retry', async ({ page }) => {
    const f = await fixture(page, { confirmStatus: 503 }); await review(page);
    await page.getByRole('button', { name: 'Confirm bypass and register', exact: true }).click();
    await expect(page.getByRole('alert')).toContainText('Fictional confirmation needs review.');
    await page.getByRole('button', { name: 'Confirm bypass and register', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Registration completed');
    const commands = f.calls.filter(call => call.path.endsWith('/bypass/confirm/'));
    expect(commands).toHaveLength(2); expect(commands[0].data).toEqual(commands[1].data);
    expect(f.errors).toEqual([]);
});
test('a stale bypass keeps its reason and a deliberate refresh obtains a fresh proof', async ({ page }) => {
    const f = await fixture(page, { confirmStatus: 409 }); await review(page);
    await page.getByRole('button', { name: 'Confirm bypass and register', exact: true }).click();
    await expect(page.getByRole('alert')).toContainText('Fictional confirmation needs review.');
    await page.getByRole('button', { name: 'Refresh bypass review', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Fictional <b>institutional</b> reason');
    await page.getByRole('button', { name: 'Confirm bypass and register', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Registration completed');
    expect(f.calls.filter(call => call.path.endsWith('/bypass/confirm/')).at(-1).data.review_token).toBe('fictional-bypass-proof-2');
    expect(f.errors).toEqual([]);
});
test('denied bypass confirmation clears the private review and its controls', async ({ page }) => {
    const f = await fixture(page, { confirmStatus: 403 }); await review(page);
    await page.getByRole('button', { name: 'Confirm bypass and register', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Access to this waitlist changed.');
    await expect(page.locator('#drawer-body')).not.toContainText('Fictional <b>institutional</b> reason');
    await expect(page.getByRole('button', { name: 'Confirm bypass and register', exact: true })).toHaveCount(0);
    expect(f.errors).toEqual([]);
});
test('normal capacity issues prevent confirmation while preserving the editable reason', async ({ page }) => {
    const f = await fixture(page, { blocked: true }); await review(page);
    await expect(page.locator('#drawer-body')).toContainText('Resolve the listed registration, seat or pricing issues');
    await expect(page.getByRole('button', { name: 'Confirm bypass and register', exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'Revise reason', exact: true }).click();
    await expect(page.getByLabel('Institutional reason for this bypass')).toHaveValue('Fictional <b>institutional</b> reason');
    expect(f.errors).toEqual([]);
});
test('ordinary waitlist office access does not show bypass actions', async ({ page }) => {
    const f = await fixture(page, { noDuty: true });
    await expect(page.getByRole('button', { name: 'Review removal', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Review priority bypass', exact: true })).toHaveCount(0);
    expect(f.errors).toEqual([]);
});
test('newer reason edits own their pending review responses', async ({ page }) => {
    let arrived, release;
    const pending = new Promise(resolve => { arrived = resolve; });
    const held = new Promise(resolve => { release = resolve; });
    const f = await fixture(page, { delayReview: async count => { if (count === 1) { arrived(); await held; } } });
    await review(page, 'Fictional original reason'); await pending;
    await page.getByLabel('Institutional reason for this bypass').fill('Fictional newer reason');
    release();
    await expect(page.getByRole('alert')).toContainText('Your reason changed while the review loaded.');
    await expect(page.getByLabel('Institutional reason for this bypass')).toHaveValue('Fictional newer reason');
    await review(page, 'Fictional newer reason');
    await page.getByRole('button', { name: 'Back to queue', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Previous waitlist page', exact: true })).toBeVisible();
    expect(f.calls.filter(call => call.path.endsWith('/bypass/confirm/'))).toHaveLength(0);
    expect(f.errors).toEqual([]);
});
test('closing a pending bypass review prevents its late response from reopening private details', async ({ page }) => {
    let arrived, release;
    const pending = new Promise(resolve => { arrived = resolve; });
    const held = new Promise(resolve => { release = resolve; });
    const f = await fixture(page, { delayReview: async () => { arrived(); await held; } });
    await review(page); await pending;
    await page.getByRole('button', { name: 'Close record', exact: true }).click();
    const finished = page.waitForResponse(response => response.url().endsWith('/bypass/review/'));
    release(); await finished;
    await expect(page.getByRole('button', { name: 'Confirm bypass and register', exact: true })).toHaveCount(0);
    expect(f.calls.filter(call => call.path.endsWith('/bypass/confirm/'))).toHaveLength(0);
    expect(f.errors).toEqual([]);
});
test('restricted bypass history keeps its frozen page boundary and returns to the same queue', async ({ page }) => {
    const f = await fixture(page);
    await page.getByRole('button', { name: 'Back to queue', exact: true }).click();
    await page.getByRole('button', { name: 'Bypass decision history', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Recorded <b>institutional</b> reason');
    await expect(page.locator('#drawer-body script')).toHaveCount(0);
    await page.getByRole('button', { name: 'Older bypass decisions', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Newer bypass decisions', exact: true })).toBeVisible();
    const query = new URLSearchParams(f.calls.filter(call => call.path.endsWith('/bypass/history/')).at(-1).search);
    expect(query.get('before')).toBe('22'); expect(query.get('through')).toBe('25');
    await page.setViewportSize({ width: 320, height: 812 });
    await page.evaluate(() => { document.documentElement.style.fontSize = '200%'; });
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(v => v.id)).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.getByRole('button', { name: 'Back to queue', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Previous waitlist page', exact: true })).toBeVisible();
    expect(f.errors).toEqual([]);
});
test('lost bypass-history authority clears the recorded reason', async ({ page }) => {
    const f = await fixture(page, { denyHistory: true });
    await page.getByRole('button', { name: 'Back to queue', exact: true }).click();
    await page.getByRole('button', { name: 'Bypass decision history', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Recorded <b>institutional</b> reason');
    await page.getByRole('button', { name: 'Refresh bypass history', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Access to this waitlist changed.');
    await expect(page.locator('#drawer-body')).not.toContainText('Recorded <b>institutional</b> reason');
    expect(f.errors).toEqual([]);
});
test('a closed history reader ignores its late private response', async ({ page }) => {
    let arrived, release;
    const pending = new Promise(resolve => { arrived = resolve; });
    const held = new Promise(resolve => { release = resolve; });
    const f = await fixture(page, { delayHistory: async () => { arrived(); await held; } });
    await page.getByRole('button', { name: 'Back to queue', exact: true }).click();
    await page.getByRole('button', { name: 'Bypass decision history', exact: true }).click();
    await pending;
    await page.getByRole('button', { name: 'Close record', exact: true }).click();
    const finished = page.waitForResponse(response => response.url().includes('/bypass/history/'));
    release(); await finished;
    await expect(page.locator('#drawer-body')).not.toContainText('Recorded <b>institutional</b> reason');
    expect(f.errors).toEqual([]);
});
