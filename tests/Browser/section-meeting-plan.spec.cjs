const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => String(n).padStart(26, '0');

async function fixture(page, { pending = false, empty = false, faculty = null, office = false, published = false, setupFailure = 0 } = {}) {
    const state = { calls: [], errors: [], published: null, pending, empty, delay: false, release: null,
        checkStatus: 'verified', checkDenied: false, setupDenied: false, setupFailure, checkDelay: false, checkRelease: null, receipts: [], historyPages: false };
    const section = { id: id(1), code: 'MATH.101.26FA.A', name: 'Fictional course section', version: 0 };
    const definition = { section_id: section.id, section_code: section.code, term_code: '26/FA',
        timezone: 'America/Los_Angeles', locations: [{ room_id: id(2), code: 'LIB.101',
            name: 'Fictional <img src=x onerror=alert(1)> room', building_id: id(3),
            building_code: 'LIB', building_name: 'Fictional library' }],
        meetings: Array.from({ length: 21 }, (_, n) => ({ booking_id: id(4),
            occurrence_id: id(100 + n), offering_publication_id: id(5), room_id: n % 2 ? id(2) : null,
            starts_at: '2026-08-10T17:00:00Z', ends_at: '2026-08-10T18:00:00Z' })) };
    const assessment = faculty ? { mode: faculty, revision: 2, status: 'conflict', checked_faculty: 1,
        compared_sections: 1, unassigned_meetings: 0, pairs: 1, blocked: faculty === 'block',
        conflicts: [{ section_code: 'ENG.<img src=x onerror=alert(1)>', term_code: '26/FA',
            starts_at: '2026-08-10T17:00:00Z', ends_at: '2026-08-10T18:00:00Z' }] }
        : { mode: 'block', revision: 0, status: empty ? 'not_needed' : 'clear', checked_faculty: empty ? 0 : 1,
            compared_sections: 0, unassigned_meetings: 0, pairs: 0, blocked: false, conflicts: [] };
    const working = () => state.empty ? { ...definition, meetings: [], locations: [] } : definition;
    if (published) state.published = { faculty_assessment: assessment, definition: structuredClone(working()), version: 2,
        publication_id: id(7), recorded_at: '2026-08-10T17:00:00Z', reason: 'Fictional accepted publication' };
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
        else if (path === `${base}setup/` && (state.setupDenied || state.setupFailure)) return route.fulfill({ status: state.setupDenied ? 403 : state.setupFailure, json: { error: 'Fictional source or access changed.' } });
        else if (path.includes('/history-checks/meetingplan/')) {
            if (state.checkDenied) return route.fulfill({ status: 403, json: { error: 'Fictional support access changed.' } });
            if (path.endsWith('/run/')) {
                if (state.checkDelay) await new Promise(resolve => { state.checkRelease = resolve; });
                const verified = state.checkStatus === 'verified';
                const receipt = { reference: id(80 + state.receipts.length), status: state.checkStatus,
                    events_checked: state.checkStatus === 'limited' ? 200 : 2, checked_at: '2026-11-27T18:00:00Z' };
                state.receipts.unshift(receipt);
                result = { result: { scope: 'section_meeting_plan', section_reference: section.id, status: state.checkStatus,
                    events_checked: receipt.events_checked, publications_checked: verified ? 1 : null,
                    current_meetings_checked: verified ? working().meetings.length : null, records_changed: false,
                    issues: state.checkStatus === 'unverified' ? ['projection', 'offering_reference', 'identity'] : [] }, receipt, event_limit: 200 };
            } else result = { items: state.receipts, next_after: null };
        }
        else if (path === `${base}setup/`) result = { faculty_assessment: assessment, working: working(), published: state.published,
            changed: !state.published, pending_booking_ids: state.pending ? [id(4)] : [] };
        else if (path === `${base}review/`) {
            result = { faculty_assessment: assessment, definition: structuredClone(working()), previous: state.published, reason: data.reason,
                expires_at: Math.floor(Date.now() / 1000) + 1200, review_token: 'fictional-protected-review' };
            state.reviewed = result;
            if (state.delay) await new Promise(resolve => { state.release = resolve; });
        } else if (path === `${base}publish/`) {
            state.published = { faculty_assessment: assessment, definition: structuredClone(state.reviewed.definition), version: 1,
                publication_id: id(7), recorded_at: '2026-08-10T17:00:00Z', reason: state.reviewed.reason };
            result = { operation_id: id(7), first_version: 1, last_version: 1, event_ids: [id(8)] };
        } else if (path === `${base}versions/`) result = { items: [data.cursor ? { ...state.published, version: 1 } : state.published],
            through_version: state.published.version, next_cursor: state.historyPages && !data.cursor ? 'fictional-meeting-plan-page' : null };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto(office ? '/admin/meeting-plan-office-fixture/' : '/admin/');
    await page.locator('.workspace-nav-link[data-workspace="courses"]').click();
    await page.getByRole('link', { name: 'Sections', exact: true }).click();
    await page.getByRole('button', { name: section.code, exact: true }).click();
    await page.getByRole('button', { name: 'Meeting plan', exact: true }).click();
    if (setupFailure) await expect(page.locator('#drawer-body')).toContainText('This meeting plan is unavailable.');
    else await expect(page.getByRole('heading', { name: `${section.code} · 26/FA`, exact: true })).toBeVisible();
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


test('faculty blocking policy shows escaped comparison details and prevents publication review', async ({ page }) => {
    const state = await fixture(page, { faculty: 'block' });
    const region = page.getByRole('region', { name: 'Faculty schedule review' });
    await expect(region).toContainText('Resolve the schedule before publishing.');
    await expect(region).toContainText('Times below are UTC.');
    await expect(region.locator('img')).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Review meeting plan', exact: true })).toHaveCount(0);
    expect(state.calls.filter(item => item.path.endsWith('/review/'))).toEqual([]);
    await page.setViewportSize({ width: 320, height: 900 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    expect((await new AxeBuilder({ page }).include('#record-drawer').analyze()).violations).toEqual([]);
    expect(state.errors).toEqual([]);
});

test('faculty warning needs acknowledgement before publishing and retains its review summary', async ({ page }) => {
    const state = await fixture(page, { faculty: 'warn' });
    await page.getByLabel('Reason for publication').fill('Fictional reviewed overlap');
    await page.getByRole('button', { name: 'Review meeting plan', exact: true }).click();
    const publish = page.getByRole('button', { name: 'Publish reviewed meeting plan', exact: true });
    await publish.click();
    await expect(page.getByLabel('I reviewed the faculty overlaps.')).toBeFocused();
    expect(state.calls.filter(item => item.path.endsWith('/publish/'))).toEqual([]);
    await page.getByLabel('I reviewed the faculty overlaps.').check();
    await publish.click();
    await page.getByRole('button', { name: 'View published plan', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Faculty schedule review' })).toContainText('1 faculty overlap.');
    expect(state.calls.filter(item => item.path.endsWith('/publish/')).every(item => item.csrf === 'fictional-test-token')).toBe(true);
    expect(state.errors).toEqual([]);
});


test('meeting guidance keeps an unfinished reason and focuses existing details without requests', async ({ page }) => {
    const state = await fixture(page, { faculty: 'warn' });
    await page.getByLabel('Reason for publication').fill('Fictional unsaved reason');
    const count = state.calls.length;
    await page.locator('[data-review-guide] > summary').click();
    await expect(page.locator('[data-review-guide]')).toContainText('Acknowledge faculty warnings');
    await page.getByRole('button', { name: 'Show faculty review', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Faculty schedule', exact: true })).toBeFocused();
    await page.getByRole('button', { name: 'Show meeting times', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Approved working meetings', exact: true })).toBeFocused();
    await expect(page.getByLabel('Reason for publication')).toHaveValue('Fictional unsaved reason');
    expect(state.calls.length).toBe(count);
    await page.setViewportSize({ width: 320, height: 900 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    expect((await new AxeBuilder({ page }).include('#record-drawer').analyze()).violations).toEqual([]);
    await page.getByRole('button', { name: 'Review meeting plan', exact: true }).click();
    await expect(page.locator('[data-review-guide]')).toHaveCount(1);
    await expect(page.locator('[data-review-guide]')).not.toHaveAttribute('open');
    expect(state.errors).toEqual([]);
});


const checkLabel = 'Check meeting-plan history';
test('meeting-plan support preserves the unfinished reason and keyboard return with private bounded results', async ({ page }) => {
    const state = await fixture(page);
    const check = page.getByRole('button', { name: checkLabel, exact: true });
    await expect(check.locator('.bi')).toHaveCount(1);
    await page.getByLabel('Reason for publication').fill('PRIVATE unfinished publication reason');
    await check.focus();
    await page.keyboard.press('Enter');
    expect(state.calls.some(c => c.path.endsWith('/run/'))).toBe(false);
    await page.getByRole('button', { name: 'Run history check', exact: true }).click();
    const result = page.getByRole('region', { name: 'Current check result', exact: true });
    await expect(result).toContainText('Retained meeting-plan publications checked: 1');
    await expect(result).toContainText('Current published meetings checked: 21');
    await expect(result).toContainText('maximum 200');
    await expect(result).not.toContainText('PRIVATE');
    await expect(result).not.toContainText('Fictional library');
    await page.setViewportSize({ width: 320, height: 920 });
    await page.addStyleTag({ content: 'html { font-size: 200%; }' });
    const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(axe.violations.map(v => ({ id: v.id, targets: v.nodes.map(n => n.target) }))).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.getByRole('button', { name: 'Back to record', exact: true }).click();
    await expect(page.getByLabel('Reason for publication')).toHaveValue('PRIVATE unfinished publication reason');
    await expect(check).toBeFocused();
    expect(state.calls.filter(c => c.path.endsWith('/setup/'))).toHaveLength(2);
    expect(state.calls.filter(c => c.path.includes('/history-checks/')).every(c => c.csrf === 'fictional-test-token')).toBe(true);
    expect(state.calls.some(c => c.path.endsWith('/publish/'))).toBe(false);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(state.errors).toEqual([]);
});

test('meeting-plan support returns to a freshly authorized publication page at its saved cursor', async ({ page }) => {
    const state = await fixture(page, { published: true });
    state.historyPages = true;
    await page.getByRole('button', { name: 'Publication history', exact: true }).click();
    await page.getByRole('button', { name: 'More publications', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Plan 1');
    await page.getByRole('button', { name: checkLabel, exact: true }).click();
    await page.getByRole('button', { name: 'Back to record', exact: true }).click();
    await expect(page.getByRole('button', { name: checkLabel, exact: true })).toBeFocused();
    expect(state.calls.filter(c => c.path.endsWith('/versions/')).at(-1).data.cursor).toBe('fictional-meeting-plan-page');
    expect(state.calls.filter(c => c.path.endsWith('/setup/'))).toHaveLength(2);
    await page.getByRole('button', { name: 'View retained plan', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Published meeting plan 1', exact: true })).toBeVisible();
    expect(state.errors).toEqual([]);
});

for (const [outcome, heading] of [
    ['observed_empty', 'No meeting-plan publication observed'], ['limited', 'Check limit reached'],
    ['changed', 'Records changed during the check'], ['unverified', 'Unable to verify'],
]) test(`meeting-plan support explains ${outcome} without completed partial counts`, async ({ page }) => {
    const state = await fixture(page);
    state.checkStatus = outcome;
    await page.getByRole('button', { name: checkLabel, exact: true }).click();
    await page.getByRole('button', { name: 'Run history check', exact: true }).click();
    const result = page.getByRole('region', { name: 'Current check result', exact: true });
    await expect(result.getByRole('heading', { name: heading, exact: true })).toBeVisible();
    await expect(result).not.toContainText('Current published meetings checked');
    await expect(result).not.toContainText('Retained meeting-plan publications checked');
    if (outcome === 'unverified') {
        await expect(result).toContainText('Current meeting-plan publication pointer');
        await expect(result).toContainText('Original retained offering references');
        await expect(result).toContainText('Section, course and term routing');
        await expect(result).not.toContainText('Person identity');
    }
    if (outcome === 'observed_empty') await expect(result).toContainText('does not establish an earlier timetable');
    expect(state.errors).toEqual([]);
});

test('meeting-plan support shows zero current meetings for a verified explicitly empty plan', async ({ page }) => {
    const state = await fixture(page, { published: true, empty: true });
    await page.getByRole('button', { name: checkLabel, exact: true }).click();
    await page.getByRole('button', { name: 'Run history check', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Current check result' })).toContainText('Current published meetings checked: 0');
    expect(state.errors).toEqual([]);
});

test('meeting-plan support ignores late results after returning to the record', async ({ page }) => {
    const state = await fixture(page);
    state.checkDelay = true;
    await page.getByRole('button', { name: checkLabel, exact: true }).click();
    await page.getByRole('button', { name: 'Run history check', exact: true }).click();
    await expect.poll(() => typeof state.checkRelease).toBe('function');
    await page.getByRole('button', { name: 'Back to record', exact: true }).click();
    state.checkRelease();
    await expect(page.getByRole('button', { name: checkLabel, exact: true })).toBeFocused();
    await expect(page.getByRole('region', { name: 'Current check result' })).toHaveCount(0);
    expect(state.errors).toEqual([]);
});

test('meeting-plan support clears denied results and never restores a denied private source draft', async ({ page }) => {
    const state = await fixture(page);
    await page.getByLabel('Reason for publication').fill('PRIVATE retained draft');
    await page.getByRole('button', { name: checkLabel, exact: true }).click();
    await page.getByRole('button', { name: 'Run history check', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Current check result' })).toContainText('Verified within this check');
    state.checkDenied = true;
    await page.getByRole('button', { name: 'Refresh previous checks', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Current check result' })).toBeEmpty();
    await expect(page.getByRole('region', { name: 'Previous history checks' })).toBeEmpty();
    state.setupDenied = true;
    await page.getByRole('button', { name: 'Back to record', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('This meeting plan is unavailable.');
    await expect(page.locator('#drawer-body')).not.toContainText('PRIVATE');
    await expect(page.getByLabel('Reason for publication')).toHaveCount(0);
    expect(state.errors).toEqual([]);
});

test('ordinary scheduling and catalog staff receive no meeting-plan support action', async ({ page }) => {
    const state = await fixture(page, { office: true });
    await expect(page.getByRole('button', { name: checkLabel, exact: true })).toHaveCount(0);
    expect(state.calls.some(c => c.path.includes('/history-checks/'))).toBe(false);
    expect(state.errors).toEqual([]);
});


test('meeting-plan support remains reachable when the ordinary editor cannot load damaged evidence', async ({ page }) => {
    const state = await fixture(page, { setupFailure: 500 });
    state.checkStatus = 'unverified';
    await page.getByRole('button', { name: checkLabel, exact: true }).click();
    await page.getByRole('button', { name: 'Run history check', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Current check result' })).toContainText('Current meeting-plan publication pointer');
    await page.getByRole('button', { name: 'Back to record', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('This meeting plan is unavailable.');
    await expect(page.getByRole('button', { name: checkLabel, exact: true })).toBeFocused();
    expect(state.calls.some(c => /\/(review|publish)\/$/.test(c.path))).toBe(false);
    expect(state.errors).toEqual([]);
});
