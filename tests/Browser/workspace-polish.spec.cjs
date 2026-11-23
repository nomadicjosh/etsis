const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => `01K000000000000000000000${String(n).padStart(2, '0')}`;

async function fixture(page, path = '/admin/') {
    const errors = [], requests = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        requests.push({ path, method: route.request().method() });
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = {
            cards: [
                { label: 'Active students', value: 1250, caption: 'Current student records', icon: 'mortarboard', destination: 'students' },
                { label: 'Applications to review', value: 27, caption: 'Ready for the admissions office', icon: 'inbox', destination: 'applications' },
                { label: 'Active sections', value: 86, caption: 'Teaching and learning this term', icon: 'collection', destination: 'sections' },
                { label: 'Student accounts', value: 1208, caption: 'Connected billing records', icon: 'wallet2', destination: 'accounts' },
            ],
            intake: [{ label: '2025 Fall', value: 110 }, { label: '2026 Spring', value: 140 }, { label: '2026 Fall', value: 180 }],
            pipeline: [{ label: 'Submitted', value: 27 }, { label: 'Accepted', value: 45 }, { label: 'Matriculated', value: 32 }],
            queue: [{ label: 'Space requests', value: 3, destination: 'bookings' }],
        };
        else if (path.startsWith('/sis/ui/data/')) {
            const dataset = path.split('/')[4];
            const records = {
                courses: [{ id: id(1), code: 'MATH.101', title: 'College Algebra', credits_milli: 3000, active: 1 }],
                people: [{ id: id(2), given_name: 'Alex', family_name: 'Example', institutional_id: 'S-FICTIONAL' }],
                accounts: [{ id: id(3), given_name: 'Alex', family_name: 'Example', institutional_id: 'S-FICTIONAL', currency: 'USD' }],
                programs: [{ id: id(4), code: 'BA.ENG', title: 'English' }],
                terms: [{ id: id(5), code: '26/FA' }],
                employees: [{ id: id(8), person_id: id(2), given_name: 'Alex', family_name: 'Example', institutional_id: 'S-FICTIONAL', job_title: 'Academic advisor', active: true }],
                rooms: [{ id: id(9), code: 'LIB.101', name: 'Advising room', building_name: 'Campus library', capacity: 24 }],
                bookings: [{ id: id(10), title: 'Welcome seminar', status: 'approved' }],
                students: [{ id: id(13), person_id: id(2), given_name: 'Alex', family_name: 'Example', institutional_id: 'S-FICTIONAL', active: true }],
                applications: [{ id: id(14), given_name: 'Alex', family_name: 'Example', institutional_id: 'S-FICTIONAL', status: 'submitted' }],
            };
            result = { items: records[dataset] || [], next_after: null };
        } else if (path === `/sis/applications/${id(14)}/review/`) result = {
            application: { id: { value: id(14) }, status: 'submitted' },
            person: { id: { value: id(2) }, givenName: 'Alex', familyName: 'Example', institutionalId: { value: 'S-FICTIONAL' } },
            labels: { program_code: 'BA.ENG', program_title: 'English', term_code: '26/FA' },
            submission: { form: { name: 'First-year application', fields: [
                { key: 'school', type: 'text', label: 'Previous school' },
                { key: 'consent', type: 'checkbox', label: 'Contact permission' },
                { key: 'statement', type: 'textarea', label: 'Why this program?' },
            ] }, answers: { school: 'Fictional Academy', consent: false,
                statement: 'I enjoy reading, writing and discussing ideas.\nThis is a fictional application for visual testing.' } },
        };
        else if (path === `/sis/students/${id(13)}/record/`) result = {
            id: id(13), person_id: id(2), given_name: 'Alex', family_name: 'Example', institutional_id: 'S-FICTIONAL',
            active: true, primary_program: { id: id(4), code: 'BA.ENG', title: 'English' },
            entry_term: { id: id(5), code: '26/FA' }, revision: 'fictional-student-revision',
        };
        else if (path === `/sis/students/${id(13)}/programs/`) result = { items: [
            { id: id(16), program_id: id(4), program_code: 'BA.ENG', program_title: 'English', term_code: '26/FA',
                catalog_year: '2026-2027', catalog_revision: 1, status: 'active', is_primary: true },
            { id: id(17), program_id: id(18), program_code: 'BS.CS', program_title: 'Computing', term_code: '25/FA',
                catalog_year: '2025-2026', catalog_revision: 1, status: 'closed', is_primary: false },
        ], next_after: null };
        else if (path === `/sis/employees/${id(8)}/`) result = {
            id: id(8), person_id: id(2), given_name: 'Alex', family_name: 'Example', institutional_id: 'S-FICTIONAL',
            job_title: 'Academic advisor', active: true, earlier_history_unknown: true, revision: 'fictional-revision',
        };
        else if (path === `/sis/rooms/${id(9)}/`) result = {
            id: id(9), code: 'LIB.101', name: 'Advising room', capacity: 24, building_id: id(12),
            building: { code: 'LIB', name: 'Campus library' },
        };
        else if (path === `/sis/bookings/${id(10)}/`) result = {
            id: id(10), title: 'Welcome seminar', room_id: id(9), status: 'approved', version: 1,
            occurrences: [{ id: id(11), status: 'approved', starts_at: '2026-11-17T17:00:00Z', ends_at: '2026-11-17T18:00:00Z' }],
        };
        else if (path === `/sis/accounts/${id(3)}/`) result = {
            id: id(3), student_id: id(2), term_id: id(5), institutional_id: 'S-FICTIONAL', currency: 'USD', balance_minor: 2500, position: 3,
            entries: [
                { operation_id: id(6), type: 'charge', minor: 10000, currency: 'USD', reversed: false, can_credit_charge: true, remaining_charge_minor: 10000, credited_minor: 0, linked_credit_count: 0 },
                { operation_id: id(7), type: 'payment', minor: 7500, currency: 'USD', reversed: false, can_correct_amount: true, can_correct_payment_details: true, linked_refund_count: 0, linked_refund_minor: 0, remaining_refundable_minor: 7500 },
            ],
        };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto(path);
    await expect(page.locator('#metric-grid')).toContainText('1,250');
    return { errors, requests };
}
async function accessible(page) {
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(v => ({ id: v.id, targets: v.nodes.map(n => n.target) }))).toEqual([]);
}

test('the compact account menu supports keyboard navigation and preserves private sign-out', async ({ page }) => {
    const f = await fixture(page);
    const toggle = page.getByRole('button', { name: 'Account menu', exact: true });
    await toggle.focus(); await page.keyboard.press('ArrowDown');
    const menu = page.locator('#account-menu');
    await expect(toggle).toHaveAttribute('aria-expanded', 'true');
    await expect(menu.getByRole('link', { name: 'Profile', exact: true })).toBeFocused();
    await page.keyboard.press('ArrowDown');
    await expect(menu.getByRole('link', { name: 'Timetable', exact: true })).toBeFocused();
    await expect(menu.getByRole('link', { name: 'Bookings', exact: true })).toHaveAttribute('href', '/portal/bookings/');
    await expect(menu.locator('form')).toHaveAttribute('method', 'post');
    await expect(menu.locator('form')).toHaveAttribute('action', '/logout/');
    await expect(menu.locator('input[name="csrf_token"]')).toHaveValue('fictional-test-token');
    await expect(menu.locator('.bi[aria-hidden="true"]')).toHaveCount(6);
    await accessible(page);
    await page.screenshot({ path: 'test-results/workspace-polished-account.png' });
    await page.keyboard.press('Escape');
    await expect(toggle).toBeFocused();
    await expect(toggle).toHaveAttribute('aria-expanded', 'false');
    expect(f.errors).toEqual([]);
});

test('catalog navigation and grouped tools remain distinct with keyboard and return focus', async ({ page }) => {
    const f = await fixture(page);
    await page.locator('.workspace-nav-link[data-workspace="courses"]').click();
    await expect(page.locator('#directory-tabs > button')).toHaveCount(0);
    await expect(page.locator('#directory-tools > .workspace-action-menu')).toHaveCount(3);
    const toggle = page.locator('#directory-tools').getByRole('button', { name: 'Curriculum', exact: true });
    await toggle.focus(); await page.keyboard.press('ArrowDown');
    await expect(page.getByRole('button', { name: 'Course group library', exact: true })).toBeFocused();
    await page.keyboard.press('ArrowDown'); await page.keyboard.press('ArrowDown');
    await expect(page.getByRole('button', { name: 'Subjects', exact: true })).toBeFocused();
    await accessible(page);
    await page.screenshot({ path: 'test-results/workspace-polished-catalog.png' });
    await page.keyboard.press('Enter');
    await expect(page.locator('#drawer-title')).toHaveText('Course subjects');
    await page.getByRole('button', { name: 'Close record', exact: true }).click();
    await expect(toggle).toBeFocused();
    await page.getByRole('button', { name: 'College Algebra', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Course content', exact: true })).toBeVisible();
    await expect(page.getByRole('region', { name: 'Publication & reuse', exact: true })).toBeVisible();
    await accessible(page);
    await page.screenshot({ path: 'test-results/workspace-polished-course.png' });
    await page.getByRole('button', { name: 'Close record', exact: true }).click();
    await page.locator('#directory-tabs [data-workspace="programs"]').click();
    await expect(page.locator('.workspace-nav-link[data-workspace="courses"]')).toHaveAttribute('aria-current', 'page');
    await expect(page.locator('#topbar-parent')).toContainText('Academic catalog');
    expect(f.errors).toEqual([]);
});

test('grouped person fields preserve validation, private submission and phone reflow', async ({ page }) => {
    const f = await fixture(page);
    const saves = [];
    await page.route('**/sis/ui/operation-id/', route => route.fulfill({ json: { id: id(90) } }));
    await page.route('**/sis/persons/', async route => {
        expect(route.request().headers()['x-csrf-token']).toBe('fictional-test-token');
        saves.push(JSON.parse(new URLSearchParams(route.request().postData()).get('_workspace_json')));
        await route.fulfill({ status: 422, json: { error: 'Please correct the highlighted fields.',
            errors: [{ field: 'email', message: 'Use a different fictional email address.' }] } });
    });
    await page.locator('[data-action="new-person"]').click();
    const form = page.locator('#drawer-body .record-form');
    await expect(form.getByRole('group', { name: 'Person details', exact: true })).toBeVisible();
    await expect(form.getByRole('group', { name: 'Institutional ID', exact: true })).toBeVisible();
    await expect(form.getByRole('group', { name: 'Directory privacy', exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'Save and continue', exact: true }).click();
    await expect(form.getByRole('textbox', { name: 'Given name', exact: true })).toBeFocused();
    expect(saves).toHaveLength(0);
    await form.getByRole('textbox', { name: 'Given name', exact: true }).fill('Alex');
    await form.getByRole('textbox', { name: 'Family name', exact: true }).fill('Example');
    await form.getByRole('textbox', { name: 'Email address', exact: true }).fill('alex@example.invalid');
    await page.screenshot({ path: 'test-results/workspace-polished-person-form.png' });
    await page.getByRole('button', { name: 'Save and continue', exact: true }).click();
    await expect(form.getByRole('textbox', { name: 'Email address', exact: true })).toHaveAttribute('aria-invalid', 'true');
    await expect(form.getByRole('textbox', { name: 'Given name', exact: true })).toHaveValue('Alex');
    expect(saves).toEqual([{ given_name: 'Alex', family_name: 'Example', email: 'alex@example.invalid', operation_id: id(90) }]);
    await page.setViewportSize({ width: 320, height: 812 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await accessible(page);
    await page.screenshot({ path: 'test-results/workspace-polished-person-phone.png' });
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await accessible(page);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});

test('row action menus stay in the viewport of a horizontally scrolled account table', async ({ page }) => {
    const f = await fixture(page);
    await page.locator('.workspace-nav-link[data-workspace="accounts"]').click();
    await page.getByRole('button', { name: 'Alex Example', exact: true }).click();
    await page.setViewportSize({ width: 375, height: 850 });
    const toggle = page.getByRole('button', { name: `More actions for entry ${id(7).slice(-8)}`, exact: true });
    await toggle.click();
    const menu = page.locator(`#${await toggle.getAttribute('aria-controls')}`);
    await expect(menu.getByRole('button', { name: 'Correct receipt details', exact: true })).toBeVisible();
    const box = await menu.boundingBox();
    expect(box.x).toBeGreaterThanOrEqual(0);
    expect(box.x + box.width).toBeLessThanOrEqual(375);
    await accessible(page);
    await page.keyboard.press('Escape');
    await expect(toggle).toBeFocused();
    await page.screenshot({ path: 'test-results/workspace-polished-record.png' });
    expect(f.requests.filter(r => r.method !== 'GET' && !r.path.startsWith('/sis/ui/data/'))).toEqual([]);
    expect(f.errors).toEqual([]);
});

test('phone navigation and 200 percent text scaling keep essential controls reachable', async ({ page }) => {
    const f = await fixture(page);
    await page.setViewportSize({ width: 320, height: 812 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.getByRole('button', { name: 'Open navigation', exact: true }).click();
    const navigation = page.locator('#workspace-navigation');
    const box = await navigation.boundingBox();
    expect(box.width).toBeLessThanOrEqual(320);
    await navigation.getByRole('link', { name: 'Academic catalog', exact: true }).click();
    await expect(page.locator('#directory-heading')).toHaveText('Academic catalog');
    await expect(navigation).not.toHaveClass(/show/);
    await page.locator('#directory-tools').getByRole('button', { name: 'Academic calendar', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Academic years', exact: true })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await accessible(page);
    await page.screenshot({ path: 'test-results/workspace-polished-mobile.png' });
    await page.keyboard.press('Escape');
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await accessible(page);
    expect(f.errors).toEqual([]);
});

test('read-only finance access does not offer ledger navigation or hidden posting actions', async ({ page }) => {
    await fixture(page, '/admin/account-read-fixture/');
    await page.locator('.workspace-nav-link[data-workspace="accounts"]').click();
    await expect(page.locator('#directory-tabs [data-workspace="journals"]')).toHaveCount(0);
    await expect(page.locator('#directory-tabs [data-workspace="ledger"]')).toHaveCount(0);
    await page.getByRole('button', { name: 'Alex Example', exact: true }).click();
    await expect(page.locator('#drawer-body [data-workspace-menu]')).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Post a transaction', exact: true, includeHidden: true })).toHaveCount(0);
});

test('finishing a directory request does not take keyboard focus away from its tools', async ({ page }) => {
    await fixture(page);
    let finish;
    const pending = new Promise(resolve => { finish = resolve; });
    await page.route('**/sis/ui/data/courses/', async route => {
        await pending;
        await route.fulfill({ json: { items: [], next_after: null } });
    });
    await page.locator('.workspace-nav-link[data-workspace="courses"]').click();
    const toggle = page.locator('#directory-tools').getByRole('button', { name: 'Academic calendar', exact: true });
    await toggle.focus(); await page.keyboard.press('ArrowDown');
    const years = page.getByRole('button', { name: 'Academic years', exact: true });
    await expect(years).toBeFocused();
    finish();
    await expect(page.locator('#directory-results')).toContainText('No records yet');
    await expect(years).toBeFocused();
});

test('employment summaries keep routine tasks visible and secondary actions keyboard accessible', async ({ page }) => {
    const f = await fixture(page);
    await page.locator('.workspace-nav-link[data-workspace="employees"]').click();
    await page.getByRole('button', { name: 'Alex Example', exact: true }).click();
    const summary = page.locator('#drawer-body .record-summary');
    await expect(summary).toContainText('Academic advisor');
    await expect(summary).toContainText('Time submissions enabled');
    const toggle = page.getByRole('button', { name: 'More employment actions', exact: true });
    await toggle.focus(); await page.keyboard.press('ArrowDown');
    const edit = page.getByRole('button', { name: 'Edit employment', exact: true });
    await expect(edit).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(toggle).toBeFocused();
    await accessible(page);
    await page.screenshot({ path: 'test-results/workspace-polished-employment.png' });
    await toggle.click(); await edit.click();
    await expect(page.getByRole('group', { name: 'Employment details', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Back to record', exact: true }).click();
    await page.setViewportSize({ width: 320, height: 812 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await accessible(page);
    expect(f.requests.some(r => r.path.endsWith('/edit/'))).toBe(false);
    expect(f.requests.some(r => r.path.endsWith('/history/'))).toBe(false);
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await accessible(page);
    expect(f.errors).toEqual([]);
});

test('booking menus and compact meeting actions retain context and require confirmation', async ({ page }) => {
    const f = await fixture(page);
    await page.locator('.workspace-nav-link[data-workspace="bookings"]').click();
    await page.getByRole('button', { name: 'Welcome seminar', exact: true }).click();
    await expect(page.locator('#drawer-body > .record-task-content > .record-summary')).toContainText('Campus library');
    await expect(page.getByRole('button', { name: /^Reschedule meeting on / })).toHaveText('Reschedule');
    const cancel = page.getByRole('button', { name: /^Cancel meeting on / });
    await expect(cancel).toHaveText('Cancel meeting');
    const toggle = page.getByRole('button', { name: 'More booking actions', exact: true });
    await toggle.focus(); await page.keyboard.press('ArrowDown');
    await expect(page.getByRole('button', { name: 'View audit history', exact: true })).toBeFocused();
    await page.keyboard.press('ArrowDown');
    await expect(page.getByRole('button', { name: 'Change category', exact: true })).toBeFocused();
    await page.keyboard.press('Escape'); await expect(toggle).toBeFocused();
    await accessible(page);
    await page.screenshot({ path: 'test-results/workspace-polished-booking.png' });
    await page.setViewportSize({ width: 320, height: 812 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await accessible(page);
    await cancel.click();
    await expect(page.getByRole('button', { name: 'Confirm meeting cancellation', exact: true })).toBeVisible();
    expect(f.requests.some(r => r.path.includes('/occurrences/'))).toBe(false);
    expect(f.errors).toEqual([]);
});

test('application tasks precede the answers and decisions still require a reasoned review', async ({ page }) => {
    const f = await fixture(page);
    await page.locator('.workspace-nav-link[data-workspace="applications"]').click();
    await page.getByRole('button', { name: 'Alex Example', exact: true }).click();
    const tasks = page.getByRole('group', { name: 'Application tasks', exact: true });
    await expect(tasks.getByRole('button', { name: 'Accept application', exact: true })).toBeVisible();
    const answers = page.getByRole('button', {name:'Application answers',exact:true});
    await expect(answers).toBeVisible();
    const beforeAnswers = await tasks.evaluate((element, target) => Boolean(element.compareDocumentPosition(target) & Node.DOCUMENT_POSITION_FOLLOWING), await answers.elementHandle());
    expect(beforeAnswers).toBe(true);
    const toggle = page.getByRole('button', { name: 'More application actions', exact: true });
    await toggle.focus(); await page.keyboard.press('ArrowDown');
    await expect(page.getByRole('button', { name: 'Application history', exact: true })).toBeFocused();
    await page.keyboard.press('Escape'); await expect(toggle).toBeFocused();
    await accessible(page);
    await page.screenshot({ path: 'test-results/workspace-polished-admissions.png' });
    await page.setViewportSize({ width: 320, height: 812 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await accessible(page);
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await accessible(page);
    await tasks.getByRole('button', { name: 'Accept application', exact: true }).click();
    await expect(page.getByRole('group', { name: 'Decision reason', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Review admissions decision', exact: true }).click();
    await expect(page.getByRole('textbox', { name: 'Reason for admissions decision', exact: true })).toBeFocused();
    expect(f.requests.some(r => r.path.endsWith('/decision/review/') || r.path.endsWith('/decision/commit/'))).toBe(false);
    expect(f.errors).toEqual([]);
});

for (const readOnly of [false, true]) {
    test(`student pursuits keep degree tasks visible with ${readOnly ? 'read-only access' : 'contextual registrar actions'}`, async ({ page }) => {
        const f = await fixture(page, readOnly ? '/admin/student-read-fixture/' : '/admin/');
        await page.locator('.workspace-nav-link[data-workspace="students"]').click();
        await page.getByLabel('Search this workspace', { exact: true }).fill('Alex');
        await page.getByLabel('Search this workspace', { exact: true }).press('Enter');
        await page.getByRole('button', { name: 'Alex Example', exact: true }).click();
        const programs = page.getByRole('group', { name: 'Program assignments', exact: true });
        const current = programs.getByRole('article').filter({ hasText: 'BA.ENG' });
        const former = programs.getByRole('article').filter({ hasText: 'BS.CS' });
        await expect(current.getByText('Primary', { exact: true })).toBeVisible();
        await expect(former).toContainText('Closed');
        await expect(current.getByRole('button', { name: 'Degree requirements', exact: true })).toBeVisible();
        await expect(current.getByRole('button', { name: 'Specializations', exact: true })).toBeVisible();
        if (readOnly) {
            await expect(programs.locator('[data-workspace-menu]')).toHaveCount(2);
            for (const label of ['Close assignment','Reopen assignment','Make primary'])
                await expect(programs.getByRole('button', {name:label,exact:true,includeHidden:true})).toHaveCount(0);
            await expect(page.getByRole('button', { name: 'Change program', exact: true, includeHidden: true })).toHaveCount(0);
        } else {
            const toggle = former.getByRole('button', { name: 'More actions for BS.CS · 25/FA', exact: true });
            await toggle.focus(); await page.keyboard.press('ArrowDown');
            const menu = page.locator('#' + await toggle.getAttribute('aria-controls'));
            await expect(menu.getByRole('button').first()).toBeFocused();
            await expect(former.getByRole('button', { name: 'Program status history', exact: true })).toBeVisible();
            await expect(former.getByRole('button', { name: 'Reopen assignment', exact: true })).toBeVisible();
            await expect(current.getByRole('button', { name: 'Close assignment', exact: true, includeHidden: true })).toHaveCount(0);
            await page.keyboard.press('Escape'); await expect(toggle).toBeFocused();
            expect(f.requests.some(r => r.path.includes('/status/'))).toBe(false);
            await page.screenshot({ path: 'test-results/workspace-polished-student.png' });
        }
        await page.setViewportSize({ width: 320, height: 812 });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        await accessible(page);
        await page.setViewportSize({ width: 1440, height: 1000 });
        await page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        await accessible(page);
        expect(f.errors).toEqual([]);
    });
}
