const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = number => String(number).padStart(26, '0');
const application = id(1), person = id(2), user = id(3), program = id(4), message = id(5);

async function fixture(page) {
    const state = { calls: [], errors: [], status: 'draft', subject: '', body: '' };
    const recipient = { id: person, user_id: user, given_name: 'Fictional', family_name: 'Applicant', institutional_id: 'EXAMPLE' };
    page.on('pageerror', error => state.errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const request = route.request(), path = new URL(request.url()).pathname;
        const data = request.postData() ? JSON.parse(new URLSearchParams(request.postData()).get('_workspace_json')) : null;
        state.calls.push({ path, data, method: request.method() });
        if (request.method() === 'POST') expect(request.headers()['x-csrf-token']).toBe('fictional-test-token');
        let json;
        if (path === '/sis/ui/metrics/') json = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path.startsWith('/sis/ui/data/')) json = { items: path.includes('/applications/')
            ? [{ id: application, given_name: 'Fictional', family_name: 'Applicant', status: 'submitted' }] : [], next_after: null };
        else if (path === `/sis/applications/${application}/review/`) json = {
            application: { id: { value: application }, status: 'submitted', version: 0 },
            person: { id: { value: person }, givenName: 'Fictional', familyName: 'Applicant', institutionalId: { value: 'EXAMPLE' } },
            labels: { program_code: 'BA.ART', program_title: 'Fictional Arts', term_code: '26/FA' },
        };
        else if (path === `/sis/applications/${application}/casework/`) json = { assignee_name: 'Unassigned', program_id: program,
            new_preset_id: id(6), case: { version: 0, office: '', work_status: 'open', follow_up_on: '', private_notes: '', next_step: '', items: [] } };
        else if (path === `/sis/campaigns/recipients/application/${application}/`) json = { items: [recipient] };
        else if (path === '/sis/campaigns/') {
            state.subject = data.subject; state.body = data.body;
            json = { id: message, status: 'draft', version: 0 };
        } else if (path === `/sis/campaigns/${message}/recipient-review/`) json = {
            subject: state.subject, body: state.body, version: 0, recipients: [recipient],
        };
        else if (path === `/sis/campaigns/${message}/queue/`) { state.status = 'queued'; json = { delivery_ids: [id(7)] }; }
        else if (path === `/sis/campaigns/${message}/`) json = {
            id: message, subject: state.subject, body: state.body, status: state.status, version: state.status === 'draft' ? 0 : 1,
            is_owner: true, can_dispatch: false,
        };
        else if (path === `/sis/campaigns/${message}/delivery-progress/`) json = {
            status: 'unconfirmed', campaign_status: 'queued', version: 0, total: 1, waiting: false,
            counts: { queued: 1, sending: 0, sent: 0, uncertain: 0, closed: 0 },
        };
        else if (path === `/sis/ui/context/deliveries/${message}/`) json = { items: [], next_after: null };
        else throw new Error(`Unexpected request: ${path}`);
        await route.fulfill({ json, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="applications"]').click();
    await page.getByRole('button', { name: 'Fictional Applicant', exact: true }).click();
    return state;
}

test('the applicant shortcut keeps the existing draft review queue progress and return path together', async ({ page }) => {
    const state = await fixture(page), drawer = page.locator('#drawer-body');
    await drawer.getByRole('button', { name: 'Message applicant', exact: true }).click();
    await expect(drawer).toContainText('To: Fictional Applicant · EXAMPLE.');
    await drawer.getByLabel('Subject', { exact: true }).fill('Your application next steps');
    await drawer.getByLabel('Message', { exact: true }).fill('Sign in to review your next steps.');
    await drawer.getByRole('button', { name: 'Save and continue', exact: false }).click();
    await expect(drawer.getByRole('region', { name: 'Selected recipients' })).toContainText('Fictional Applicant');
    await expect(drawer.getByRole('button', { name: 'Back to application', exact: true })).toBeVisible();
    await drawer.getByRole('button', { name: 'Review message and recipients', exact: true }).click();
    await drawer.getByRole('button', { name: 'Confirm recipient queue', exact: true }).click();
    await expect(drawer.getByRole('progressbar', { name: 'Confirmed sends' })).toBeVisible();
    expect(state.calls.find(call => call.path.endsWith('/queue/')).data.recipients).toEqual([{ user_id: user }]);
    expect(state.calls.some(call => /delivery-confirm|\/dispatch\//.test(call.path))).toBe(false);
    await drawer.getByRole('button', { name: 'Back to application', exact: true }).click();
    await expect(drawer.getByRole('button', { name: 'Message applicant', exact: true })).toBeVisible();
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => item.id)).toEqual([]); expect(state.errors).toEqual([]);
});

test('starting a message preserves unfinished applicant casework', async ({ page }) => {
    const state = await fixture(page), drawer = page.locator('#drawer-body');
    await drawer.getByRole('button', { name: 'Checklist & follow-up', exact: true }).click();
    await drawer.getByText('Routing & next steps', { exact: true }).click();
    await drawer.getByLabel('Private staff notes', { exact: true }).fill('Fictional unfinished note.');
    await drawer.getByRole('button', { name: 'Message applicant', exact: true }).click();
    await expect(drawer).toContainText('Save casework before starting a message.');
    await expect(drawer.getByLabel('Private staff notes', { exact: true })).toHaveValue('Fictional unfinished note.');
    expect(state.calls.some(call => call.path.startsWith('/sis/campaigns/'))).toBe(false);
    expect(state.errors).toEqual([]);
});
