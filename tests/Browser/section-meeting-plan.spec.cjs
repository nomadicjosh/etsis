const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => String(n).padStart(26, '0');

async function fixture(page, { pending = false, empty = false } = {}) {
    const state = { calls: [], errors: [], published: null, pending, empty, delay: false, release: null };
    const section = { id: id(1), code: 'MATH.101.26FA.A', name: 'Fictional course section', version: 0 };
    const definition = { section_id: section.id, section_code: section.code, term_code: '26/FA',
        timezone: 'America/Los_Angeles', locations: [{ room_id: id(2), code: 'LIB.101',
            name: 'Fictional <img src=x onerror=alert(1)> room', building_id: id(3),
            building_code: 'LIB', building_name: 'Fictional library' }],
        meetings: Array.from({ length: 21 }, (_, n) => ({ booking_id: id(4),
            occurrence_id: id(100 + n), offering_publication_id: id(5), room_id: n % 2 ? id(2) : null,
            starts_at: '2026-08-10T17:00:00Z', ends_at: '2026-08-10T18:00:00Z' })) };
    const working = () => state.empty ? { ...definition, meetings: [], locations: [] } : definition;
    const base = `/sis/sections/${section.id}/meeting-plan/`;
    page.on('pageerror', error => state.errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        state.calls.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/data/sections/') result.items = [section];
        else if (path.endsWith('/roster/')) result = { section, items: [] };
        else if (path === `${base}setup/`) result = { working: working(), published: state.published,
            changed: !state.published, pending_booking_ids: state.pending ? [id(4)] : [] };
        else if (path === `${base}review/`) {
            result = { definition: structuredClone(working()), previous: state.published, reason: data.reason,
                expires_at: Math.floor(Date.now() / 1000) + 1200, review_token: 'fictional-protected-review' };
            state.reviewed = result;
            if (state.delay) await new Promise(resolve => { state.release = resolve; });
        } else if (path === `${base}publish/`) {
            state.published = { definition: structuredClone(state.reviewed.definition), version: 1,
                publication_id: id(7), recorded_at: '2026-08-10T17:00:00Z', reason: state.reviewed.reason };
            result = { operation_id: id(7), first_version: 1, last_version: 1, event_ids: [id(8)] };
        } else if (path === `${base}versions/`) result = { items: [state.published], through_version: 1, next_cursor: null };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="courses"]').click();
    await page.getByRole('link', { name: 'Sections', exact: true }).click();
    await page.getByRole('button', { name: section.code, exact: true }).click();
    await page.getByRole('button', { name: 'Meeting plan', exact: true }).click();
    await expect(page.getByRole('heading', { name: `${section.code} · 26/FA`, exact: true })).toBeVisible();
    return state;
}

test('meeting plan review pages hybrid meetings, escapes labels and retains publication history', async ({ page }) => {
    const state = await fixture(page);
    const body = page.locator('#drawer-body');
    await expect(body.locator('tbody tr')).toHaveCount(20);
    await expect(body).toContainText('Online · No room');
    await expect(body).toContainText('Fictional library');
    await expect(body.locator('img')).toHaveCount(0);
    await page.getByRole('button', { name: 'Next meetings', exact: true }).click();
    await expect(body.locator('tbody tr')).toHaveCount(1);
    await page.getByLabel('Reason for publication').fill('Fictional reviewed schedule');
    await page.getByRole('button', { name: 'Review meeting plan', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Review before publishing', exact: true })).toBeVisible();
    await expect(body).toContainText('first meeting plan');
    await expect(page.getByRole('button', { name: 'Publish reviewed meeting plan', exact: true })).toBeVisible();
    const accessibility = await new AxeBuilder({ page }).include('#record-drawer').analyze();
    expect(accessibility.violations).toEqual([]);
    await page.setViewportSize({ width: 320, height: 900 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.getByRole('button', { name: 'Publish reviewed meeting plan', exact: true }).click();
    await expect(body).toContainText('Published plan 1 matches');
    await page.getByRole('button', { name: 'Publication history', exact: true }).click();
    await page.getByRole('button', { name: 'View retained plan', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Published meeting plan 1', exact: true })).toBeVisible();
    await expect(body).toContainText('Fictional reviewed schedule');
    expect(state.calls.filter(call => call.path.includes('/meeting-plan/')).every(call => call.csrf === 'fictional-test-token')).toBe(true);
    expect(state.calls.filter(call => call.path.endsWith('/publish/')).map(call => call.data))
        .toEqual([{ review_token: 'fictional-protected-review' }]);
    expect(state.errors).toEqual([]);
});

test('pending requests block publishing and empty plans require an explicit confirmation', async ({ page }) => {
    const state = await fixture(page, { pending: true, empty: true });
    await expect(page.getByRole('button', { name: 'Review request 1', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Review meeting plan', exact: true })).toHaveCount(0);
    state.pending = false;
    await page.getByRole('button', { name: 'Reload meeting plan', exact: true }).click();
    await page.getByLabel('Reason for publication').fill('Fictional asynchronous offering');
    await page.getByRole('button', { name: 'Review meeting plan', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('No scheduled meetings');
    await page.getByRole('button', { name: 'Publish plan with no scheduled meetings', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Published plan 1 matches');
    expect(state.errors).toEqual([]);
});

test('editing a reason while review loads discards that response and preserves the changed draft', async ({ page }) => {
    const state = await fixture(page, { empty: true });
    state.delay = true;
    await page.getByLabel('Reason for publication').fill('Fictional old reason');
    await page.getByRole('button', { name: 'Review meeting plan', exact: true }).click();
    await expect.poll(() => !!state.release).toBe(true);
    await page.getByLabel('Reason for publication').fill('Fictional corrected reason');
    state.release();
    await expect(page.getByRole('button', { name: 'Review meeting plan', exact: true })).toBeEnabled();
    await expect(page.getByRole('heading', { name: 'Review before publishing', exact: true })).toHaveCount(0);
    await expect(page.getByLabel('Reason for publication')).toHaveValue('Fictional corrected reason');
    expect(state.calls.filter(call => call.path.endsWith('/publish/'))).toEqual([]);
    expect(state.errors).toEqual([]);
});
