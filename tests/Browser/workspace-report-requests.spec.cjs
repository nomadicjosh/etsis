const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => String(n).padStart(26, '0');
const requestId = id(1), reportId = id(2);
async function accessible(page) {
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(v => ({ id: v.id, targets: v.nodes.map(n => n.target) }))).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
}
async function fixture(page, { manager = false, initial = 'open', interrupted = false, stale = false } = {}) {
    const sent = [], errors = [], receipts = new Map(); let serial = 10, status = initial, version = 0, assigned = initial === 'waiting', report = null;
    let question = 'How many fictional students are enrolled?\nGroup by program.', title = 'Fictional <script>headcount</script>', purpose = 'Plan fictional teaching resources.';
    let exists = initial !== null, attempts = 0;
    const history = [{ id: requestId, version: 0, action: 'created', note: '', actor: 'Requester', created_at: '2026-08-10T10:00:00Z' }];
    const actions = () => ({ claim: manager && !assigned && ['open', 'in_progress'].includes(status), clarify: manager && assigned && status === 'in_progress', reply: !manager && status === 'waiting', deliver: manager && assigned && status === 'in_progress', cancel: ['open', 'in_progress', 'waiting'].includes(status), reopen: ['delivered', 'cancelled'].includes(status), note: true });
    const metadata = () => ({ id: requestId, title, status, version, due_on: '2026-09-01', owned: !manager, assigned_to_me: manager && assigned, assigned, assignee_name: assigned ? 'Taylor Example' : null, requester_name: 'Alex Example', report_id: report, created_at: '2026-08-10T10:00:00Z', updated_at: '2026-08-10T11:00:00Z' });
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData(); const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        if (data) sent.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        let result = {}, code = 200;
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/operation-id/') result = { id: exists ? id(serial++) : requestId };
        else if (path.startsWith('/sis/ui/data/')) result = { items: path.includes('/reports/') ? [{ id: reportId, name: 'Fictional program headcount' }] : [], next_after: null };
        else if (path === '/sis/report-requests/') {
            attempts++; exists = true; title = data.title; question = data.question; purpose = data.purpose; status = 'open';
            result = { request_id: requestId, operation_id: requestId, status, version: 0 };
            if (interrupted && attempts === 1) { code = 500; result = { error: 'Fictional connection interrupted. Check the saved request.' }; } else code = 201;
        } else if (path === '/sis/report-requests/page/') result = { items: exists ? [metadata()] : [], next_after: null, can_manage: manager };
        else if (path === `/sis/report-requests/${requestId}/`) {
            if (exists) result = { ...metadata(), question, purpose, actions: actions() };
            else { code = 404; result = { error: 'Record not found.' }; }
        } else if (path.endsWith('/history/')) result = { items: history, next_after_version: null };
        else if (path.endsWith('/delivery-review/')) result = { request_id: requestId, version, operation_id: id(90), report: { id: reportId, name: 'Fictional program headcount', definition_version: 3 }, requester_name: 'Alex Example', delivery_revision: 'a'.repeat(64) };
        else if (path.endsWith('/actions/')) {
            if (stale) { code = 409; result = { error: 'This record changed. Reload it before trying again.' }; }
            else if (receipts.has(data.operation_id)) result = receipts.get(data.operation_id);
            else {
                version++;
                if (data.action === 'claim') { assigned = true; status = 'in_progress'; }
                if (data.action === 'clarify') status = 'waiting';
                if (data.action === 'reply') status = 'in_progress';
                if (data.action === 'deliver') { status = 'delivered'; report = data.report_id; }
                if (data.action === 'cancel') status = 'cancelled';
                history.push({ id: data.operation_id, version, action: data.action, note: data.note || '', actor: 'You', created_at: '2026-08-10T11:00:00Z' });
                result = { request_id: requestId, operation_id: data.operation_id, status, version, report_id: report }; receipts.set(data.operation_id, result);
                if (interrupted && data.action === 'deliver') { code = 500; result = { error: 'Fictional connection interrupted. Check the saved action.' }; }
            }
        } else if (path.includes('/receipts/')) result = receipts.get(path.split('/').at(-2)) || { request_id: requestId };
        await route.fulfill({ status: code, json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    return { sent, errors, attempts: () => attempts };
}
async function openRequests(page, manager = false) {
    await page.goto(manager ? '/admin/report-manager-fixture/' : '/admin/report-fixture/');
    await page.locator(`.workspace-nav-link[data-workspace="${manager ? 'reports' : 'sharedreports'}"]`).click();
    await page.getByRole('button', { name: manager ? 'Report request queue' : 'My report requests', exact: true }).click();
}

test('a report recipient submits a question without authoring access and retries the captured request after interruption', async ({ page }) => {
    const f = await fixture(page, { initial: null, interrupted: true }); await openRequests(page);
    await page.locator('#drawer-body').getByRole('button', { name: 'Request a report', exact: true }).click();
    await page.getByLabel('Short title').fill('Fictional fall headcount');
    await page.getByLabel('What do you need to know?').fill('Count students by program.');
    await page.getByLabel('How will you use the answer?').fill('Plan fictional teaching resources.');
    await page.getByLabel('Date needed (optional)').fill('2026-09-01');
    await page.setViewportSize({ width: 375, height: 812 }); await accessible(page);
    await page.getByRole('button', { name: 'Submit report request', exact: true }).click();
    await expect(page.locator('#drawer-error')).toContainText('interrupted');
    await expect(page.getByLabel('What do you need to know?')).toBeDisabled();
    await page.getByRole('button', { name: 'Submit report request', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Count students by program.');
    const posts = f.sent.filter(r => r.path === '/sis/report-requests/');
    expect(posts).toHaveLength(2); expect(posts[0].data).toEqual(posts[1].data);
    expect(posts[0]).toMatchObject({ csrf: 'fictional-test-token', data: { operation_id: requestId, due_on: '2026-09-01', title: 'Fictional fall headcount' } });
    await expect(page.getByRole('button', { name: 'Report request queue', exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Open Report Studio', exact: true })).toHaveCount(0);
    await accessible(page); expect(f.errors).toEqual([]);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});

test('an interrupted submission can be recovered without a second create', async ({ page }) => {
    const f = await fixture(page, { initial: null, interrupted: true }); await openRequests(page);
    await page.locator('#drawer-body').getByRole('button', { name: 'Request a report', exact: true }).click();
    await page.getByLabel('Short title').fill('A fictional question'); await page.getByLabel('What do you need to know?').fill('Count fictional students.');
    await page.getByLabel('How will you use the answer?').fill('Fictional planning.');
    await page.getByRole('button', { name: 'Submit report request', exact: true }).click();
    await expect(page.locator('#drawer-error')).toContainText('interrupted');
    await page.getByRole('button', { name: 'Check saved request' }).click();
    await expect(page.locator('#drawer-body')).toContainText('Count fictional students.'); expect(f.attempts()).toBe(1);
    expect(f.errors).toEqual([]);
});

test('the requester answers a clarification in the same accessible case with escaped question text', async ({ page }) => {
    const f = await fixture(page, { initial: 'waiting' }); await openRequests(page);
    await page.getByRole('button', { name: 'Fictional <script>headcount</script>', exact: true }).click();
    await expect(page.locator('#drawer-body script')).toHaveCount(0);
    await page.getByRole('button', { name: 'Reply to question', exact: true }).click();
    await page.getByLabel('Your reply').fill('Fall 2026, please.');
    await page.setViewportSize({ width: 375, height: 812 }); await accessible(page);
    await page.locator('#drawer-body').getByRole('button', { name: 'Reply to question', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Fall 2026, please.');
    await expect(page.locator('#drawer-body')).toContainText('In progress');
    expect(f.sent.find(r => r.path.endsWith('/actions/'))?.data).toMatchObject({ action: 'reply', note: 'Fall 2026, please.', version: 0 });
    expect(f.sent.some(r => r.path.startsWith('/sis/ui/data/students/'))).toBe(false);
    expect(f.errors).toEqual([]);
});

test('a specialist claims a request and reviews its recipient and live report before sharing with receipt recovery', async ({ page }) => {
    const f = await fixture(page, { manager: true, interrupted: true }); await openRequests(page, true);
    await page.getByRole('button', { name: 'Fictional <script>headcount</script>', exact: true }).click();
    await page.getByRole('button', { name: 'Take this request', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm assignment', exact: true }).click();
    await page.getByRole('button', { name: 'Share finished report', exact: true }).click();
    await page.getByRole('combobox', { name: 'Finished report', exact: true }).selectOption(reportId);
    await page.getByLabel('Explain the answer or how to use this report').fill('Use the term filter to compare fictional programs.');
    await page.getByRole('button', { name: 'Review report handoff', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Share with Alex Example');
    await expect(page.locator('#drawer-body')).toContainText('Fictional program headcount');
    await expect(page.locator('#drawer-body')).toContainText('source-data permissions are checked separately');
    expect(f.sent.some(r => r.data.action === 'deliver')).toBe(false);
    await page.setViewportSize({ width: 375, height: 812 }); await accessible(page);
    await page.getByRole('button', { name: 'Confirm and share report', exact: true }).click();
    await expect(page.locator('#drawer-error')).toContainText('interrupted');
    await page.getByRole('button', { name: 'Check saved action', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Report shared');
    await expect(page.getByRole('button', { name: 'Open linked report', exact: true })).toBeVisible();
    expect(f.sent.filter(r => r.data.action === 'deliver')).toHaveLength(1);
    expect(f.sent.find(r => r.data.action === 'deliver')).toMatchObject({ csrf: 'fictional-test-token', data: { version: 1, report_id: reportId, operation_id: id(90), delivery_revision: 'a'.repeat(64) } });
    expect(f.sent.every(r => r.csrf === 'fictional-test-token')).toBe(true);
    expect(f.errors).toEqual([]); await accessible(page);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});

test('stale assignment stays in context and is never resubmitted automatically', async ({ page }) => {
    const f = await fixture(page, { manager: true, stale: true }); await openRequests(page, true);
    await page.getByRole('button', { name: 'Fictional <script>headcount</script>', exact: true }).click();
    await page.getByRole('button', { name: 'Take this request', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm assignment', exact: true }).click();
    await expect(page.locator('#drawer-error')).toContainText('record changed');
    expect(f.sent.filter(r => r.path.endsWith('/actions/'))).toHaveLength(1);
    await page.getByRole('button', { name: 'Back to request', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('The question'); expect(f.errors).toEqual([]);
});


test('rejected form values remain editable while permission loss clears the private conversation', async ({ page }) => {
    const f = await fixture(page, { initial: null }); await openRequests(page);
    await page.locator('#drawer-body').getByRole('button', { name: 'Request a report', exact: true }).click();
    await page.getByLabel('Short title').fill('A fictional planning question');
    await page.getByLabel('What do you need to know?').fill('Count fictional students.');
    await page.getByLabel('How will you use the answer?').fill('Fictional planning.');
    await page.getByLabel('Date needed (optional)').fill('2020-01-01');
    await page.route('**/sis/report-requests/', async route => {
        await route.fulfill({ status: 422, json: { error: 'Please correct the highlighted fields.', errors: [{ field: 'due_on', message: 'Choose today or a later requested date.' }] } });
    });
    await page.getByRole('button', { name: 'Submit report request', exact: true }).click();
    await expect(page.locator('#drawer-error')).toContainText('Choose today');
    await expect(page.getByLabel('Date needed (optional)')).toBeEnabled();
    await page.getByLabel('Date needed (optional)').fill('2026-09-01');
    await page.unroute('**/sis/report-requests/');
    await page.getByRole('button', { name: 'Submit report request', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Count fictional students.');
    await page.getByRole('button', { name: 'Add a message', exact: true }).click();
    await page.getByLabel('Message or reason').fill('Fictional private follow-up.');
    await page.route('**/sis/report-requests/*/actions/', route => route.fulfill({ status: 403, json: { error: 'Access denied.' } }));
    await page.locator('#drawer-body').getByRole('button', { name: 'Add a message', exact: true }).click();
    await expect(page.locator('#drawer-error')).toContainText('additional access');
    await expect(page.locator('#drawer-body')).toContainText('Access to this request is no longer available.');
    await expect(page.locator('#drawer-body')).not.toContainText('Fictional private follow-up.');
    await expect(page.locator('#drawer-body')).not.toContainText('Count fictional students.');
    expect(f.errors).toEqual([]); await accessible(page);
});
