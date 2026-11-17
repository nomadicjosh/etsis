const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => `01K000000000000000000000${String(n).padStart(2, '0')}`;

async function accessible(page) {
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => ({ id: item.id, targets: item.nodes.map(node => node.target) }))).toEqual([]);
}

async function teaching(page) {
    let release;
    const pending = new Promise(resolve => { release = resolve; });
    const state = { paths: [], errors: [], release };
    page.on('pageerror', error => state.errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        state.paths.push(path);
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/data/teaching/') result = { items: [
            { id: id(1), code: 'MATH.101.A', course_title: 'College Algebra', term_code: '26/FA', capacity: 25 },
        ], next_after: null };
        else if (path.includes('/context/roster/')) result = { can_grade: true, items: [
            { student_id: id(2), given_name: 'Alex', family_name: 'Example', institutional_id: 'S-FICTIONAL', status: 'A', grade_code: null },
        ], next_after: null };
        else if (path.endsWith('/attendance/')) {
            await pending;
            result = { items: [], next_after: null, counts: {}, term: { starts_on: '2026-08-24', ends_on: '2026-12-18' } };
        } else if (path.endsWith('/score-page/')) result = { items: [
            { assignment_id: id(3), title: 'Fictional problem set', possible_hundredths: 1000, earned_hundredths: null, version: 0 },
        ], next_after: null };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="teaching"]').click();
    await page.getByRole('button', { name: 'MATH.101.A', exact: true }).click();
    await page.getByRole('button', { name: 'Grade & attendance', exact: true }).click();
    await expect(page.getByRole('navigation', { name: 'Gradebook sections', exact: true })).toBeVisible();
    return state;
}

test('gradebook navigation works while reads finish without replacing keyboard focus', async ({ page }) => {
    const state = await teaching(page);
    const navigation = page.getByRole('navigation', { name: 'Gradebook sections', exact: true });
    await navigation.getByRole('button', { name: 'Scores', exact: true }).focus();
    await page.keyboard.press('Enter');
    const scores = page.getByRole('heading', { name: 'Assignment scores', exact: true });
    await expect(scores).toBeFocused();
    state.release();
    await expect(page.getByRole('table', { name: 'Assignment scores', exact: true })).toContainText('Fictional problem set');
    await expect(scores).toBeFocused();
    expect(state.paths.filter(path => path.endsWith('/score-page/'))).toHaveLength(1);
    await page.locator('#drawer-body').evaluate(element => { element.scrollTop = 0; });
    await accessible(page);
    await page.screenshot({ path: 'test-results/workspace-polished-gradebook.png' });
    await page.setViewportSize({ width: 320, height: 812 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await accessible(page);
    await page.screenshot({ path: 'test-results/workspace-polished-gradebook-phone.png' });
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await accessible(page);
    expect(state.errors).toEqual([]);
});

test('leaving a gradebook before attendance returns does not request private scores', async ({ page }) => {
    const state = await teaching(page);
    await page.getByRole('button', { name: 'Close record', exact: true }).click();
    await expect(page.locator('#drawer-body')).toBeEmpty();
    const finished = page.waitForEvent('requestfinished', { predicate: request => new URL(request.url()).pathname.endsWith('/attendance/') });
    state.release();
    await finished;
    // The isolated fixture has no polling; wait for the released read and its continuations to settle.
    await page.waitForLoadState('networkidle');
    expect(state.paths.some(path => path.endsWith('/score-page/'))).toBe(false);
    expect(state.errors).toEqual([]);
});

async function transcript(page, { latest = true, changed = false, limited = false } = {}) {
    const state = { paths: [], errors: [] };
    const snapshot = {
        issuer: { name: 'Fictional University' },
        student: { name: 'Alex Example', institutional_id: 'S-FICTIONAL' },
        program: { code: 'BA.ENG', title: 'Retained English program' },
        credits: [{ term_code: '26/FA', course_code: 'ENG.101', course_title: 'Retained course title', attempted_milli: 3000, completed_milli: 3000, grade_code: 'A' }],
        totals: { attempted_milli: 3000, completed_milli: 3000, gpa_milli: 4000 },
    };
    page.on('pageerror', error => state.errors.push(error.message));
    const respond = async route => {
        const path = new URL(route.request().url()).pathname;
        state.paths.push(path);
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/data/transcripts/') result = { items: [{ id: id(10), delivery: 'pickup', status: 'ready' }], next_after: null };
        else if (path === `/portal/api/transcripts/${id(10)}/`) result = { status: 'ready', delivery: 'pickup', recipient: '' };
        else if (path.endsWith('/fulfillment/')) result = { status: 'ready', version: 1, document: { id: id(11) } };
        else if (path === `/sis/transcript-requests/${id(10)}/documents/${id(11)}/`) result = {
            id: id(11), created_at: '2026-11-16T12:00:00Z', is_latest: latest, academic_changed: changed, snapshot, delivery: null,
        };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    };
    await page.route('**/sis/**', respond);
    await page.route('**/portal/api/transcripts/**', respond);
    await page.goto(limited ? '/admin/transcript-office-fixture/' : '/admin/');
    await page.locator('.workspace-nav-link[data-workspace="transcripts"]').click();
    await page.locator('#directory-results tbody button').first().click();
    return state;
}

for (const scenario of [
    { name: 'current', latest: true, changed: false, deliver: true },
    { name: 'outdated academic data', latest: true, changed: true, deliver: false },
    { name: 'superseded', latest: false, changed: false, deliver: false },
]) {
    test(`transcript document actions respect the ${scenario.name} revision`, async ({ page }) => {
        const state = await transcript(page, scenario);
        await page.getByRole('button', { name: 'Review transcript', exact: true }).click();
        const tasks = page.getByRole('group', { name: 'Transcript document tasks', exact: true });
        await expect(tasks.getByRole('button', { name: 'Record completed delivery', exact: true, includeHidden: true })).toHaveCount(scenario.deliver ? 1 : 0);
        const link = tasks.getByRole('link', { name: 'Accessible view (opens in a new tab)', exact: true });
        await expect(link).toHaveAttribute('target', '_blank');
        await expect(link).toHaveAttribute('rel', 'noopener');
        await expect(page.locator('#drawer-body')).toContainText('Retained course title');
        await expect(page.locator('#drawer-body')).not.toContainText(/\b(null|undefined)\b/u);
        if (scenario.changed) await expect(page.locator('#drawer-body .alert-warning')).toContainText('changed after preparation');
        if (!scenario.latest) await expect(page.locator('#drawer-body .alert-info')).toContainText('superseded');
        await expect(page.getByText('GPA', { exact: true }).locator('..').locator('dd')).toHaveText('4.000');
        await accessible(page);
        if (scenario.deliver) await page.screenshot({ path: 'test-results/workspace-polished-transcript.png' });
        await page.setViewportSize({ width: 320, height: 812 });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        await accessible(page);
        if (scenario.deliver) {
            await page.screenshot({ path: 'test-results/workspace-polished-transcript-phone.png' });
            await page.setViewportSize({ width: 1440, height: 1000 });
            await page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
            await accessible(page);
        }
        expect(state.paths.some(path => path.endsWith('/deliver/') || path.endsWith('/prepare/') || path.endsWith('/pdf/'))).toBe(false);
        expect(state.errors).toEqual([]);
    });
}

test('transcript-office access without academic read permission does not load documents', async ({ page }) => {
    const state = await transcript(page, { limited: true });
    await expect(page.locator('#drawer-body')).toContainText('student academic read permission');
    await expect(page.getByRole('button', { name: 'Review transcript', exact: true, includeHidden: true })).toHaveCount(0);
    expect(state.paths.some(path => path.endsWith('/fulfillment/') || path.includes('/documents/'))).toBe(false);
    expect(state.errors).toEqual([]);
});
