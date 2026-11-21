const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = number => String(number).padStart(26, '0');

test('staff maintain checklist progress office routing and applicant instructions inside application review', async ({ page }) => {
    const application = id(1), person = id(2), program = id(3), colleague = id(4);
    const posts = [], errors = [];
    let record = { application_id: application, assigned_to: null, version: 0, office: '', work_status: 'open',
        follow_up_on: '', private_notes: '', next_step: '', items: [] };
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const request = route.request(), path = new URL(request.url()).pathname;
        let result = {};
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path.startsWith('/sis/ui/data/')) result = { items: path.includes('/applications/')
            ? [{ id: application, given_name: 'Fictional', family_name: 'Applicant', status: 'submitted' }] : [], next_after: null };
        else if (path === '/sis/admissions/assignees/') result = { items: [{ id: colleague, given_name: 'Fictional',
            family_name: 'Colleague', institutional_id: 'STAFF.EXAMPLE' }], next_after: null, search_required: false };
        else if (path === `/sis/applications/${application}/review/`) result = {
            application: { id: { value: application }, status: 'submitted', version: 0 },
            person: { id: { value: person }, givenName: 'Fictional', familyName: 'Applicant', institutionalId: { value: 'EXAMPLE' } },
            labels: { program_code: 'BS.CS', program_title: 'Fictional computing', term_code: '26/FA' },
        };
        else if (path === `/sis/applications/${application}/casework/`) {
            if (request.method() === 'POST') {
                expect(request.headers()['x-csrf-token']).toBe('fictional-test-token');
                const body = JSON.parse(new URLSearchParams(request.postData()).get('_workspace_json'));
                posts.push(body); record = { ...body.values, version: 1, application_id: application, assigned_to: body.assignee_id };
                result = { status: 'saved' };
            } else result = { case: record, assignee_name: record.assigned_to ? 'Fictional staff' : 'Unassigned', program_id: program, new_preset_id: id(9) };
        } else throw new Error(`Unexpected request: ${path}`);
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="applications"]').click();
    await page.getByRole('button', { name: 'Fictional Applicant', exact: true }).click();
    const body = page.locator('#drawer-body');
    await body.getByRole('button', { name: 'Checklist & follow-up', exact: true }).click();
    const work = body.getByRole('region', { name: 'Application checklist and follow-up' });
    await work.getByRole('button', { name: 'Add item', exact: true }).click();
    await work.getByLabel('Item', { exact: true }).fill('Previous transcript');
    await work.getByLabel('Instructions', { exact: true }).fill('Arrange delivery through admissions.');
    await work.getByText('Routing & next steps', { exact: true }).click();
    await work.getByLabel('Office shown to applicant', { exact: true }).fill('Admissions');
    await work.getByLabel('Next step shown to applicant', { exact: true }).fill('Arrange your previous transcript.');
    await work.getByLabel('Private staff notes', { exact: true }).fill('Fictional staff-only note.');
    await page.locator('[data-review-guide] > summary').click();
    await expect(page.locator('[data-review-guide]')).toContainText('Unfinished edits do not count as saved evidence.');
    await page.getByRole('button', { name: 'Go to checklist', exact: true }).click();
    await expect(body.getByRole('button', { name: 'Checklist & follow-up', exact: true })).toBeFocused();
    await expect(work.getByLabel('Private staff notes', { exact: true })).toHaveValue('Fictional staff-only note.');
    expect(posts).toHaveLength(0);
    await work.getByLabel('Assignment', { exact: true }).selectOption('selected');
    await work.getByLabel('Colleague name or institutional ID', { exact: true }).fill('Fictional');
    await work.getByRole('button', { name: 'Find colleague', exact: true }).click();
    await work.getByRole('radio', { name: 'Fictional Colleague · STAFF.EXAMPLE', exact: true }).check();
    await work.getByRole('button', { name: 'Save casework', exact: true }).click();
    await expect(body).toContainText('Previous transcript');
    expect(posts).toHaveLength(1); expect(posts[0].version).toBe(0); expect(posts[0].claim).toBe('selected');
    expect(posts[0].assignee_id).toBe(colleague);
    expect(posts[0].values.private_notes).toBe('Fictional staff-only note.');
    expect(posts[0].values.items[0].visible).toBe(true);
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => item.id)).toEqual([]); expect(errors).toEqual([]);
});
