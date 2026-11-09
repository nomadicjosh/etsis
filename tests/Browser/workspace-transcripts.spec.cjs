const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => `01K000000000000000000000${String(n).padStart(2, '0')}`;
async function accessible(page) {
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => ({ id: item.id, targets: item.nodes.map(node => node.target) }))).toEqual([]);
}

test('transcript fulfillment requires reviewed preparation and separate evidence after protected PDF download', async ({ page }) => {
    const request = id(1), student = id(2), document = id(3), operation = id(4);
    const calls = [], errors = []; let status = 'pending', saved = false, evidence = null;
    const snapshot = { student: { name: 'Alex Example', institutional_id: 'S-FICTIONAL' }, program: { code: 'BS.CS', title: 'Computer Science' },
        issuer: { name: 'Fictional University', address: 'Fictional campus' },
        credits: [{ term_code: '26/FA', course_code: 'MATH.101', course_title: 'Algebra <script>example</script>', attempted_milli: 3000, completed_milli: 3000, grade_code: 'A' }],
        totals: { attempted_milli: 3000, completed_milli: 3000, gpa_milli: 4000 } };
    const metadata = { id: document, created_at: '2026-11-07T12:00:00Z', prepared_version: 1, pdf_hash: 'a'.repeat(64) };
    page.on('pageerror', error => errors.push(error.message));
    const handler = async route => {
        const path = new URL(route.request().url()).pathname, raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        if (data) calls.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        if (path.endsWith('/pdf/')) { await route.fulfill({ body: '%PDF-1.4 Fictional PDF fixture', contentType: 'application/pdf', headers: { 'Cache-Control': 'private, no-store' } }); return; }
        let result = {};
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path.startsWith('/sis/ui/data/')) result = { items: path.includes('/transcripts/') ? [{ id: request, student_id: student, delivery: 'pickup', status, version: saved ? 1 : 0 }] : [], next_after: null };
        else if (path === `/portal/api/transcripts/${request}/`) result = { id: request, student_id: student, delivery: 'pickup', recipient: '', status, version: saved ? 1 : 0 };
        else if (path.endsWith('/fulfillment/')) result = { status, version: saved ? 1 : 0, document: saved ? metadata : null };
        else if (path.endsWith('/review/')) result = { snapshot, operation_id: document, revision: 'f'.repeat(64), delivery: 'pickup', request_version: 0 };
        else if (path.endsWith('/prepare/')) { saved = true; status = 'ready'; result = metadata; }
        else if (path === '/sis/ui/operation-id/') result = { id: operation };
        else if (path.endsWith('/deliver/')) { status = 'fulfilled'; evidence = { evidence: data.evidence, delivered_at: data.delivered_at }; result = { status }; }
        else if (path === `/sis/transcript-requests/${request}/documents/${document}/`) result = { ...metadata, snapshot, is_latest: true, academic_changed: false, delivery: evidence };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    };
    await page.route('**/sis/**', handler); await page.route('**/portal/api/transcripts/**', handler);
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="transcripts"]').click();
    await page.locator('#directory-results tbody button').first().click();
    await page.getByRole('button', { name: 'Review document preparation', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Prepare protected transcript PDF', exact: true })).toBeDisabled();
    await expect(page.locator('#drawer-body')).toContainText('GPA: 4.000');
    await expect(page.locator('#drawer-body script')).toHaveCount(0);
    expect(calls.some(call => call.path.endsWith('/prepare/'))).toBe(false);
    await page.setViewportSize({ width: 375, height: 812 }); await accessible(page);
    await page.getByLabel('I reviewed the academic record and institutional release requirements').check();
    await page.getByRole('button', { name: 'Prepare protected transcript PDF', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Prepared at');
    const downloaded = page.waitForEvent('download');
    await page.getByRole('button', { name: 'Download protected PDF', exact: true }).click();
    expect((await downloaded).suggestedFilename()).toBe(`transcript-${document}.pdf`);
    expect(status).toBe('ready'); expect(calls.some(call => call.path.endsWith('/deliver/'))).toBe(false);
    await page.getByRole('button', { name: 'Record completed delivery', exact: true }).click();
    await page.getByLabel('Actual delivery time').fill('2026-11-07T12:00');
    await page.getByLabel('Delivery evidence / institutional reference').fill('Fictional pickup evidence <script>example</script>');
    await accessible(page);
    await page.getByRole('button', { name: 'Confirm recorded delivery', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Recorded delivery evidence', exact: true })).toContainText('Fictional pickup evidence');
    await expect(page.getByRole('button', { name: 'Record completed delivery', exact: true })).toHaveCount(0);
    expect(calls.find(call => call.path.endsWith('/prepare/'))).toMatchObject({ data: { operation_id: document, release_reviewed: true, revision: 'f'.repeat(64) }, csrf: 'fictional-test-token' });
    expect(calls.find(call => call.path.endsWith('/deliver/'))).toMatchObject({ data: { operation_id: operation, version: 1 }, csrf: 'fictional-test-token' });
    expect(errors).toEqual([]);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});

test('the issued transcript HTML companion is accessible and reflows on narrow screens', async ({ page }) => {
    await page.goto('/fixture/transcript-document/');
    await expect(page.getByRole('heading', { name: 'Official academic transcript', exact: true })).toBeVisible();
    await expect(page.getByRole('table')).toContainText('MATH.101');
    await accessible(page);
    await page.setViewportSize({ width: 375, height: 812 }); await accessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    expect(await page.evaluate(() => document.scripts.length)).toBe(0);
});
