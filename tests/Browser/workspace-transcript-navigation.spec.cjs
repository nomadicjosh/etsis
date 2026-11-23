const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => String(n).padStart(26, '0');

async function fixture(page, { status = 'ready', limited = false, check = 'verified', delayed = null, historical = false } = {}) {
    const calls = [], errors = [];
    let release;
    const pending = new Promise(resolve => { release = resolve; });
    const metadata = { id: id(3), created_at: '2026-11-20T12:00:00Z', prepared_version: 7 };
    const layout = { code: 'classic-letter', label: 'Classic · Letter', revision: 'fictional-layout' };
    const snapshot = {
        presentation: layout, student: { name: 'Alex <script>Example</script>', institutional_id: 'S-FICTIONAL' },
        program: { code: 'ARTS.UG', title: 'Undergraduate Arts' }, issuer: { name: 'Fictional University' },
        credits: [{ term_code: '26/FA', course_code: 'HIST.101', course_title: 'World History', attempted_milli: 3000,
            completed_milli: 3000, grade_code: 'A', completed_on: '2026-05-12', history_recorded: true }],
        totals: { attempted_milli: 3000, completed_milli: 3000, gpa_milli: 4000 },
    };
    const policy = { revision: 3, checks: [{ id: id(11), label: 'Verify recipient', details: 'Fictional office check.', stage: 'delivery' }] };
    const handler = async route => {
        const request = route.request(), path = new URL(request.url()).pathname, raw = request.postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        calls.push({ path, data, method: request.method() });
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/data/transcripts/') result.items = [{ id: id(1), status, delivery: 'pickup', version: 7 }];
        else if (path === `/portal/api/transcripts/${id(1)}/`) result = { id: id(1), status, recipient: '', delivery: 'pickup' };
        else if (path.endsWith('/fulfillment/')) {
            if (delayed === 'request') await pending;
            result = { status, version: 7, document: ['ready', 'fulfilled'].includes(status) ? metadata : null };
        } else if (path === `/sis/transcript-requests/${id(1)}/documents/${id(3)}/`) {
            if (delayed === 'document') await pending;
            result = { ...metadata, snapshot, is_latest: !historical, academic_check: check,
                academic_changed: check === 'unavailable' ? null : false, delivery: null };
        } else if (path.endsWith('/review/')) result = {
            snapshot, layout, layouts: [layout], release_policy: policy, operation_id: id(20), revision: 'fictional-review',
            review_token: 'fictional-preparation-token', delivery: 'pickup',
        };
        else if (path === '/sis/transcript-policy/') result = policy;
        else if (path.endsWith('/delivery-review/')) result = {
            delivery: data, release_policy: policy, operation_id: id(21), review_token: 'fictional-delivery-token',
        };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    };
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', handler);
    await page.route('**/portal/api/transcripts/**', handler);
    await page.goto(limited ? '/admin/transcript-office-fixture/' : '/admin/');
    await page.locator('.workspace-nav-link[data-workspace="transcripts"]').click();
    await page.locator('#directory-results tbody button').first().click();
    return { calls, errors, release };
}

async function accessible(page) {
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(v => ({ id: v.id, targets: v.nodes.map(n => n.target) }))).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
}

for (const [status, step] of [['pending', 'Prepare document'], ['ready', 'Record delivery'], ['fulfilled', null]]) {
    test(`${status} transcript requests show truthful progress and the available task cards`, async ({ page }) => {
        const f = await fixture(page, { status });
        const progress = page.getByRole('list', { name: 'Transcript fulfillment progress', exact: true });
        await expect(progress.getByRole('listitem')).toHaveCount(3);
        if (step) await expect(progress.locator('[aria-current="step"]')).toContainText(step);
        else {
            await expect(progress.locator('[aria-current="step"]')).toHaveCount(0);
            await expect(progress.locator('.complete')).toHaveCount(3);
        }
        if (status !== 'fulfilled') await expect(page.getByRole('region', { name: 'Preparation', exact: true })).toBeVisible();
        else await expect(page.getByRole('button', { name: 'Review new revision', exact: true })).toHaveCount(0);
        if (status === 'pending') {
            await page.getByRole('button', { name: 'Review preparation', exact: true }).click();
            await page.getByRole('button', { name: 'Back to request', exact: true }).click();
            await expect(progress.locator('[aria-current="step"]')).toContainText('Prepare document');
        }
        await accessible(page);
        expect(f.calls.some(call => call.path.endsWith('/prepare/') || call.path.endsWith('/deliver/'))).toBe(false);
        expect(f.errors).toEqual([]);
    });
}

test('request-only staff see metadata without academic task cards or fulfillment reads', async ({ page }) => {
    const f = await fixture(page, { limited: true });
    await expect(page.locator('#drawer-body')).toContainText('also requires student academic read permission');
    await expect(page.getByRole('group', { name: 'Transcript request tasks', exact: true })).toHaveCount(0);
    await expect(page.getByRole('list', { name: 'Transcript fulfillment progress', exact: true })).toHaveCount(0);
    expect(f.calls.some(call => call.path.endsWith('/fulfillment/') || call.path.includes('/documents/'))).toBe(false);
    expect(f.errors).toEqual([]);
});

test('cancelled requests do not suggest future preparation or delivery steps', async ({ page }) => {
    const f = await fixture(page, { status: 'cancelled' });
    await expect(page.locator('#drawer-body > .record-task-content > .record-summary')).toContainText('cancelled');
    await expect(page.getByRole('list', { name: 'Transcript fulfillment progress', exact: true })).toHaveCount(0);
    await expect(page.getByRole('group', { name: 'Transcript request tasks', exact: true })).toHaveCount(0);
    expect(f.errors).toEqual([]);
});

test('document and delivery returns reread the right record without recording delivery', async ({ page }) => {
    const f = await fixture(page);
    await page.getByRole('button', { name: 'Review transcript', exact: true }).click();
    await expect(page.locator('.transcript-document-identity')).toContainText('S-FICTIONAL');
    await page.getByRole('button', { name: 'Record delivery', exact: true }).click();
    await page.getByLabel('Actual delivery time', { exact: false }).fill('2026-11-20T10:45');
    await page.getByLabel('Delivery evidence / institutional reference', { exact: false }).fill('Fictional pickup evidence.');
    await page.getByLabel('Verify recipient', { exact: true }).check();
    await page.getByRole('button', { name: 'Refresh checks', exact: true }).click();
    await expect(page.getByLabel('Actual delivery time', { exact: false })).toHaveValue('2026-11-20T10:45');
    await expect(page.getByLabel('Delivery evidence / institutional reference', { exact: false })).toHaveValue('Fictional pickup evidence.');
    await expect(page.getByLabel('Verify recipient', { exact: true })).not.toBeChecked();
    await expect(page.getByRole('button', { name: 'Review delivery', exact: true })).toBeDisabled();
    await page.getByLabel('Verify recipient', { exact: true }).check();
    await page.getByRole('button', { name: 'Review delivery', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Reviewed delivery evidence', exact: true })).toContainText('Fictional pickup evidence.');
    await page.getByRole('button', { name: 'Change details', exact: true }).click();
    await expect(page.getByLabel('Delivery evidence / institutional reference', { exact: false })).toHaveValue('Fictional pickup evidence.');
    await page.getByRole('button', { name: 'Back to document', exact: true }).click();
    await page.getByRole('button', { name: 'Back to transcript request', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Prepared document', exact: true })).toBeVisible();
    expect(f.calls.filter(call => call.path.endsWith('/documents/' + id(3) + '/'))).toHaveLength(2);
    expect(f.calls.some(call => call.path.endsWith('/deliver/'))).toBe(false);
    expect(f.errors).toEqual([]);
});

test('request cards and prepared identity reflow with escaped labels and visible progress', async ({ page }) => {
    const f = await fixture(page);
    await expect(page.getByRole('region', { name: 'Prepared document', exact: true })).toBeVisible();
    await expect(page.locator('#drawer-body > .record-task-content > .record-summary').getByText('ready', { exact: true })).toBeVisible();
    await page.screenshot({ path: '/tmp/etsis-transcript-request-desktop.png' });
    await page.setViewportSize({ width: 320, height: 812 });
    await accessible(page);
    await page.screenshot({ path: '/tmp/etsis-transcript-request-phone.png' });
    await page.getByRole('button', { name: 'Review transcript', exact: true }).click();
    await expect(page.locator('.transcript-document-identity')).toContainText('Alex <script>Example</script>');
    await expect(page.locator('#drawer-body script')).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Accessible view (opens in a new tab)', exact: true })).toHaveAttribute('rel', 'noopener');
    await accessible(page);
    await page.screenshot({ path: '/tmp/etsis-transcript-document-phone.png' });
    await page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
    await accessible(page);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});

for (const options of [{ historical: true }, { check: 'unavailable' }]) {
    test(`${options.historical ? 'historical' : 'unverified'} prepared documents keep delivery unavailable`, async ({ page }) => {
        const f = await fixture(page, options);
        await page.getByRole('button', { name: 'Review transcript', exact: true }).click();
        await expect(page.getByRole('button', { name: 'Download protected PDF', exact: true })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Record delivery', exact: true })).toHaveCount(0);
        if (options.historical) await expect(page.locator('.transcript-document-identity')).toContainText('Earlier document');
        expect(f.calls.some(call => call.path.endsWith('/delivery-review/'))).toBe(false);
        expect(f.errors).toEqual([]);
    });
}

for (const delayed of ['request', 'document']) {
    test(`a delayed transcript ${delayed} read cannot restore a closed drawer`, async ({ page }) => {
        const f = await fixture(page, { delayed });
        if (delayed === 'document') await page.getByRole('button', { name: 'Review transcript', exact: true }).click();
        await expect(page.locator('#drawer-body').getByRole('status')).toContainText('Loading');
        await page.getByRole('button', { name: 'Close record', exact: true }).click();
        await expect(page.locator('#drawer-body')).toBeEmpty();
        const finished = page.waitForEvent('requestfinished', { predicate: request => {
            const path = new URL(request.url()).pathname;
            return delayed === 'request' ? path.endsWith('/fulfillment/') : path.endsWith(`/documents/${id(3)}/`);
        } });
        f.release(); await finished;
        await page.waitForLoadState('networkidle');
        await expect(page.locator('#drawer-body')).toBeEmpty();
        expect(f.calls.filter(call => call.path.endsWith('/fulfillment/'))).toHaveLength(1);
        expect(f.errors).toEqual([]);
    });
}
