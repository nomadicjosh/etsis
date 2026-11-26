const { test, expect } = require('@playwright/test');
const { recordAction } = require('./support/workspace-actions.cjs');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => String(n).padStart(26, '0');

async function fixture(page, options = {}) {
    const student = id(1), section = id(2), enrollment = id(3), calls = [], errors = [];
    const base = `/sis/students/${student}/sections/${section}/enrollments/${enrollment}/earned-ceus/`;
    let award = null, reject = options.reject, command = null, release;
    const pending = new Promise(resolve => { release = resolve; });
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/data/students/') result = { items: [{ id: student, person_id: id(9), given_name: 'Alex', family_name: 'Example', institutional_id: 'S-FICTIONAL', active: 1 }], next_after: null };
        else if ([ `/sis/students/${student}/`, `/sis/students/${student}/record/` ].includes(path)) result = { id: student, person_id: id(9), given_name: 'Alex', family_name: 'Example', institutional_id: 'S-FICTIONAL', active: true, primary_program: { id: id(8), code: 'ARTS-UG', title: 'Fictional Arts' }, entry_term: { id: id(7), code: '26/FA' }, revision: 'student' };
        else if (path.includes('/context/enrollments/')) result = { items: [{ id: enrollment, section_id: section,
            course_code: 'CE.101', section_code: 'CE.101.A', status: 'A' }], next_after: null };
        else if (path.endsWith('/earned-ceus/records/')) result = { items: data.after ? [] : [{ id: id(10),
            enrollment_id: enrollment, section_id: section, course_code: 'CE.101', course_title: 'Fictional continuing education' }],
            next_after: data.after ? null : id(10) };
        else if (path.startsWith(base)) {
            calls.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
            if (path.endsWith('/view/')) {
                if (options.delayed) await pending;
                result = { course_code: 'CE.101', course_title: 'Fictional continuing education', offered_milli: 1250,
                    earned_ceus: award, completed_on: options.withdrawn || options.ungraded ? null
                        : options.academicDate || '2026-08-01',
                    expected_version: 2, expected_position: 3,
                    withdrawn: !!options.withdrawn,
                    can_record: !options.readOnly, can_manage: !options.readOnly, today: '2026-08-10' };
            } else if (path.endsWith('/review/')) {
                if (options.denied) return route.fulfill({ status: 403, json: { error: 'Fictional authority ended.' } });
                command = { operation_id: id(4), expected_version: data.expected_version, expected_position: data.expected_position,
                    previous_award: award, award: { earned_milli: data.earned_milli, offered_milli: 1250,
                        section_version_id: id(5), completed_on: data.completed_on || '2026-08-01' }, effective_on: data.effective_on,
                    reason: data.reason, acknowledged: data.acknowledged };
                result = { command, review_token: 'fictional-earned-ceu-review' };
            } else if (path.endsWith('/confirm/')) {
                if (reject) { reject = false; return route.fulfill({ status: 409, json: { error: 'Fictional CEU review changed.' } }); }
                award = command.award; result = { operation_id: command.operation_id };
            } else if (path.endsWith('/history/')) result = { items: data.cursor ? [{ before: null,
                after: { earned_milli: 750 }, effective_at: '2026-08-01T00:00:00-07:00', recorded_at: '2026-08-10T17:00:00Z',
                reason: 'Fictional earlier evidence.' }] : [], next_cursor: data.cursor ? null : 'fictional-older' };
        }
        return route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto(options.list && options.readOnly ? '/admin/student-read-fixture/' : '/admin/');
    await page.locator('.workspace-nav-link[data-workspace="students"]').click();
    await page.getByLabel('Search this workspace').fill('Alex');
    await page.getByLabel('Search this workspace').press('Enter');
    await page.getByRole('button', { name: 'Alex Example', exact: true }).click();
    if (options.list) await recordAction(page, 'Earned CEU records');
    else await page.getByRole('button', { name: 'Earned CEUs', exact: true }).click();
    return { calls, errors, release };
}

async function enter(page) {
    await page.getByLabel('Earned continuing education units (CEUs)', { exact: true }).fill('1.234');
    await page.getByLabel('Verified CEU completion date', { exact: true }).fill('2026-08-01');
    await page.getByLabel('Reason and institutional completion evidence', { exact: true }).fill('Fictional reviewed completion evidence.');
    await page.getByRole('checkbox', { name: /I verified the institutional evidence/ }).check();
}
async function accessible(page) {
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(v => ({ id: v.id, nodes: v.nodes.map(n => n.target) }))).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
}

test('earned CEU review keeps drafts through paged history, revision and a rejected confirmation', async ({ page }) => {
    const f = await fixture(page, { reject: true }); await enter(page);
    await page.getByRole('button', { name: 'Earned CEU history', exact: true }).click();
    await expect(page.getByText('No earned CEU decisions appear on this history page.', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Older history', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Fictional earlier evidence.');
    await page.getByRole('button', { name: 'Return to CEU decision', exact: true }).click();
    await expect(page.getByLabel('Earned continuing education units (CEUs)', { exact: true })).toHaveValue('1.234');
    await page.setViewportSize({ width: 320, height: 812 });
    await accessible(page);
    await page.screenshot({ path: '/tmp/etsis-earned-ceu-mobile.png', fullPage: true });
    const rootSize = await page.evaluate(() => parseFloat(getComputedStyle(document.documentElement).fontSize));
    const fontSize = await page.getByRole('button', { name: 'Review earned CEUs', exact: true })
        .evaluate(el => parseFloat(getComputedStyle(el).fontSize));
    await page.setViewportSize({ width: 1280, height: 900 });
    await page.route('**/fixtures/earned-ceu-enlarged.css', route => route.fulfill({ contentType: 'text/css',
        body: `html {font-size:${rootSize * 2}px!important;}` }));
    await page.addStyleTag({ url: '/fixtures/earned-ceu-enlarged.css' });
    await expect.poll(() => page.getByRole('button', { name: 'Review earned CEUs', exact: true })
        .evaluate(el => parseFloat(getComputedStyle(el).fontSize))).toBeGreaterThanOrEqual(fontSize * 2);
    await accessible(page);
    const review = page.getByRole('button', { name: 'Review earned CEUs', exact: true });
    await review.focus(); await page.keyboard.press('Enter');
    await expect(page.locator('#drawer-body')).toContainText('1.234 CEUs');
    await page.getByRole('button', { name: 'Change CEU decision', exact: true }).click();
    await expect(page.getByLabel('Reason and institutional completion evidence', { exact: true })).toHaveValue('Fictional reviewed completion evidence.');
    await review.click();
    await page.getByRole('button', { name: 'Confirm CEU decision', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Fictional CEU review changed.');
    await page.getByRole('button', { name: 'Confirm CEU decision', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Earned CEU decision recorded.');
    expect(f.calls.find(c => c.path.endsWith('/review/')).data).toMatchObject({ earned_milli: 1234, expected_version: 2,
        expected_position: 3, acknowledged: true });
    expect(f.calls.every(c => c.csrf === 'fictional-test-token')).toBe(true);
    await page.getByRole('button', { name: 'Back to record', exact: true }).click();
    await expect(page.locator('#drawer-title')).toHaveText('Alex Example');
    expect(f.errors).toEqual([]);
});

test('lost CEU authority clears the private editor without losing the record return', async ({ page }) => {
    const f = await fixture(page, { denied: true }); await enter(page);
    await page.getByRole('button', { name: 'Review earned CEUs', exact: true }).click();
    await expect(page.getByLabel('Reason and institutional completion evidence', { exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Back to record', exact: true })).toBeVisible();
    expect(f.errors).toEqual([]);
});

test('a late CEU read cannot replace the returned student record', async ({ page }) => {
    const f = await fixture(page, { delayed: true });
    await page.getByRole('button', { name: 'Back to record', exact: true }).click(); f.release();
    await expect(page.locator('#drawer-title')).toHaveText('Alex Example');
    await expect(page.getByLabel('Earned continuing education units (CEUs)', { exact: true })).toHaveCount(0);
    expect(f.errors).toEqual([]);
});

test('read-only CEU context has no award form or protected-history action', async ({ page }) => {
    const f = await fixture(page, { readOnly: true });
    await expect(page.locator('#drawer-body')).toContainText('No earned CEU decision recorded');
    await expect(page.getByRole('button', { name: 'Review earned CEUs', exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Earned CEU history', exact: true })).toHaveCount(0);
    await accessible(page);
    expect(f.errors).toEqual([]);
});

test('owner academic records omit CEUs and a separate CEU record has no academic-credit or GPA totals', async ({ page }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/portal/api/**', route => {
        const url = new URL(route.request().url());
        let json = { items: [] };
        if (url.pathname === '/portal/api/me/') json = {
            person: { id: id(1), given_name: 'Alex', family_name: 'Example', institutional_id: 'S-FICTIONAL' },
            contact: { preferred_name: 'Alex', phone: '', address: '', version: 0 },
            students: [{ id: id(2), primary_program_id: id(3), entry_term_id: id(4), active: 1, enrollments: [] }],
            applications: [], restrictions: [], accounts: [], news: [], transcript_requests: [], request_id: id(5),
        };
        else if (url.pathname.endsWith('/transcript/')) json = { items: [{ course_code: 'CE.101',
            course_title: 'Fictional continuing education', ...(url.searchParams.get('purpose') === 'continuing_education'
                ? { earned_ceus: { earned_milli: 1250, completed_on: '2026-08-01' } }
                : { completed_milli: 3000, grade_code: 'P' }) }] };
        return route.fulfill({ json, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/portal/student/transcripts/');
    await page.getByRole('button', { name: /View unofficial record/ }).click();
    await expect(page.locator('#campus-dialog')).toContainText('Credits earned');
    await expect(page.locator('#campus-dialog')).not.toContainText('Earned CEUs');
    await page.getByRole('button', { name: 'Close details', exact: true }).click();
    await page.getByRole('button', { name: 'View CEU record', exact: true }).click();
    await expect(page.locator('#campus-dialog')).toContainText('Unofficial continuing education record');
    await expect(page.locator('#campus-dialog')).toContainText('1.25 CEUs');
    await expect(page.locator('#campus-dialog')).not.toContainText('Credits earned');
    await page.setViewportSize({ width: 320, height: 812 });
    await accessible(page);
    expect(errors).toEqual([]);
});

test('student-read staff can browse CEU attempts and return without registration or gradebook grants', async ({ page }) => {
    const f = await fixture(page, { list: true, readOnly: true });
    await expect(page.getByRole('button', { name: /CE.101 · Fictional continuing education/ })).toBeVisible();
    await page.getByRole('button', { name: 'More CEU records', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('No CEU-bearing attempts appear on this page.');
    await page.getByRole('button', { name: 'Previous CEU records', exact: true }).click();
    await page.getByRole('button', { name: /CE.101 · Fictional continuing education/ }).click();
    await expect(page.getByRole('button', { name: 'Review earned CEUs', exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'Back to CEU records', exact: true }).click();
    await expect(page.locator('#drawer-title')).toHaveText('Earned CEU records');
    await page.getByRole('button', { name: 'Back to record', exact: true }).click();
    await expect(page.locator('#drawer-title')).toHaveText('Alex Example');
    expect(f.errors).toEqual([]);
});

for (const layout of ['phone', 'enlarged text']) {
    test(`withdrawn CEUs keep a separate completion date through review and history (${layout})`, async ({ page }) => {
        const f = await fixture(page, { withdrawn: true, list: true });
        await page.getByRole('button', { name: /CE.101 · Fictional continuing education/ }).click();
        await expect(page.locator('#drawer-body')).toContainText('enrollment stays withdrawn and academic earned credit stays zero');
        await enter(page);
        await page.getByLabel('Verified CEU completion date', { exact: true }).fill('2026-08-02');
        await page.getByRole('button', { name: 'Earned CEU history', exact: true }).click();
        await page.getByRole('button', { name: 'Return to CEU decision', exact: true }).click();
        await expect(page.getByLabel('Verified CEU completion date', { exact: true })).toHaveValue('2026-08-02');
        await page.setViewportSize({ width: layout === 'phone' ? 320 : 1280, height: 812 });
        if (layout === 'enlarged text') {
            const rootSize = await page.evaluate(() => parseFloat(getComputedStyle(document.documentElement).fontSize));
            await page.route('**/fixtures/withdrawn-ceu-enlarged.css', route => route.fulfill({ contentType: 'text/css',
                body: `html {font-size:${rootSize * 2}px!important;}` }));
            await page.addStyleTag({ url: '/fixtures/withdrawn-ceu-enlarged.css' });
        }
        await accessible(page);
        await page.getByRole('button', { name: 'Review earned CEUs', exact: true }).click();
        await expect(page.locator('#drawer-body')).toContainText('2026-08-02');
        expect(f.calls.find(c => c.path.endsWith('/review/')).data).toMatchObject({ completed_on: '2026-08-02', earned_milli: 1234 });
        await page.getByRole('button', { name: 'Confirm CEU decision', exact: true }).click();
        await expect(page.locator('#drawer-body')).toContainText('Earned CEU decision recorded.');
        await page.getByRole('button', { name: 'Back to CEU records', exact: true }).click();
        await expect(page.locator('#drawer-title')).toHaveText('Earned CEU records');
        expect(f.errors).toEqual([]);
    });
}

test('withdrawn CEU authority explains the extra duty without exposing protected history', async ({ page }) => {
    const f = await fixture(page, { withdrawn: true, readOnly: true });
    await expect(page.locator('#drawer-body')).toContainText('both earned-CEU and closed-record permissions');
    await expect(page.getByLabel('Verified CEU completion date', { exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Earned CEU history', exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Back to record', exact: true })).toBeVisible();
    expect(f.errors).toEqual([]);
});

for (const ungraded of [true, false]) test(`${ungraded ? 'ungraded' : 'graded'} CEUs use an independent completion date and preserve it through review`, async ({ page }) => {
    const f = await fixture(page, { ungraded, academicDate: '2026-08-03' });
    const completion = page.getByLabel('Verified CEU completion date', { exact: true });
    await expect(completion).toHaveValue(ungraded ? '' : '2026-08-03');
    await expect(page.locator('#drawer-body')).toContainText('An academic grade is not required');
    await page.getByLabel('Earned continuing education units (CEUs)', { exact: true }).fill('1.000');
    await page.getByLabel('Reason and institutional completion evidence', { exact: true })
        .fill('Fictional verified professional development completion.');
    await page.getByRole('checkbox', { name: /I verified the institutional evidence/ }).check();
    if (ungraded) {
        await page.getByRole('button', { name: 'Review earned CEUs', exact: true }).click();
        expect(f.calls.filter(call => call.path.endsWith('/review/'))).toHaveLength(0);
    }
    await completion.fill('2026-08-02');
    await page.getByLabel('Effective date of this CEU decision', { exact: true }).fill('2026-08-02');
    await page.getByRole('button', { name: 'Review earned CEUs', exact: true }).click();
    expect(f.calls.find(call => call.path.endsWith('/review/')).data)
        .toMatchObject({ earned_milli: 1000, completed_on: '2026-08-02', effective_on: '2026-08-02' });
    await page.getByRole('button', { name: 'Change CEU decision', exact: true }).click();
    await expect(completion).toHaveValue('2026-08-02');
    await page.setViewportSize({ width: 320, height: 812 });
    await accessible(page);
    await page.getByRole('button', { name: 'Review earned CEUs', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm CEU decision', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Earned CEU decision recorded.');
    await page.getByRole('button', { name: 'Back to record', exact: true }).click();
    await expect(page.locator('#drawer-title')).toHaveText('Alex Example');
    expect(f.errors).toEqual([]);
});
