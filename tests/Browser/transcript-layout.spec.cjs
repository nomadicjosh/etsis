const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => String(n).padStart(26, '0');
const layouts = [
    ['classic-letter', 'Classic · Letter', 'letter'], ['classic-a4', 'Classic · A4', 'a4'],
    ['modern-letter', 'Modern · Letter', 'letter'], ['modern-a4', 'Modern · A4', 'a4'],
].map(([code, label, paper]) => ({ code, label, paper, revision: 'a'.repeat(64) }));

test('registered transcript layouts render accessible retained values on both paper sizes', async ({ page }) => {
    for (const layout of layouts) {
        await page.goto(`/fixture/transcript-document/?layout=${layout.code}&preview=1`);
        await expect(page.locator('body')).toHaveClass(layout.code.startsWith('modern') ? 'transcript-modern' : 'transcript-classic');
        await expect(page.getByText('Preview only. This transcript has not been prepared or issued.', { exact: true })).toBeVisible();
        await expect(page.locator('footer')).toContainText(layout.label);
        await expect(page.getByRole('table')).toContainText('MATH.101');
        await page.setViewportSize({ width: 375, height: 812 });
        const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
        expect(scan.violations.map(v => ({ id: v.id, targets: v.nodes.map(n => n.target) }))).toEqual([]);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    }
});

test('staff choose a layout, preview it privately and prepare only the newly acknowledged review', async ({ page }) => {
    const requestId = id(1), documentId = id(2), base = `/sis/transcript-requests/${requestId}/`;
    const state = { layout: layouts[0], saved: false, operation: 20, calls: [], errors: [] };
    const snapshot = { student: { name: 'Alex Example', institutional_id: 'S-FICTIONAL' },
        issuer: { name: 'Fictional University', address: 'Fictional campus' },
        program: { code: 'BA', title: 'Fictional Arts' }, credits: [],
        totals: { attempted_milli: 0, completed_milli: 0, gpa_milli: null } };
    const metadata = { id: documentId, created_at: '2026-11-19T12:00:00Z', prepared_version: 1, pdf_hash: 'a'.repeat(64) };
    page.on('pageerror', e => state.errors.push(e.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname, raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        state.calls.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        let result = {};
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path.startsWith('/sis/ui/data/')) result = { items: [{ id: requestId, student_id: id(3),
            delivery: 'pickup', status: state.saved ? 'ready' : 'pending', version: state.saved ? 1 : 0 }], next_after: null };
        else if (path.endsWith('/fulfillment/')) result = { status: state.saved ? 'ready' : 'pending',
            version: state.saved ? 1 : 0, document: state.saved ? metadata : null };
        else if (path.endsWith('/review/')) {
            state.layout = layouts.find(item => item.code === data.layout_code) || layouts[0];
            result = { snapshot, layout: state.layout, layouts, revision: 'f'.repeat(64), operation_id: id(++state.operation),
                delivery: 'pickup', review_token: `fictional-${state.layout.code}` };
        } else if (path.endsWith('/preview/')) {
            const html = await page.request.get(`/fixture/transcript-document/?layout=${state.layout.code}&preview=1`);
            return route.fulfill({ body: await html.text(), contentType: 'text/html; charset=UTF-8',
                headers: { 'Cache-Control': 'private, no-store' } });
        } else if (path.endsWith('/prepare/')) {
            state.saved = true;
            result = metadata;
        } else if (path.includes('/documents/')) result = { ...metadata, snapshot: { ...snapshot, presentation: state.layout },
            academic_check: 'verified', academic_changed: false, is_latest: true, delivery: null };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.route('**/portal/api/transcripts/**', route => route.fulfill({ json: { status: 'pending', delivery: 'pickup' } }));
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="transcripts"]').click();
    await page.locator('#directory-results tbody button').first().click();
    await page.getByRole('button', { name: 'Review preparation', exact: true }).click();
    await page.getByLabel('I reviewed the academic record and institutional release requirements', { exact: true }).check();
    await page.getByRole('radio', { name: 'Modern · A4', exact: true }).check();
    const acknowledgement = page.getByLabel('I reviewed the academic record and institutional release requirements', { exact: true });
    await expect(acknowledgement).not.toBeChecked();
    await expect(page.getByRole('button', { name: 'Prepare transcript', exact: true })).toBeDisabled();
    await page.getByRole('button', { name: 'Preview layout', exact: true }).click();
    const frame = page.locator('iframe[title="Modern · A4 transcript preview"]');
    await expect(frame).toHaveAttribute('sandbox', '');
    await expect(frame).toHaveAttribute('srcdoc', /Content-Security-Policy/);
    await expect(page.frameLocator('iframe').getByText('Preview only. This transcript has not been prepared or issued.', { exact: true })).toBeVisible();
    expect(state.saved).toBe(false);
    await page.setViewportSize({ width: 375, height: 812 });
    const scan = await new AxeBuilder({ page }).exclude('iframe').withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(v => ({ id: v.id, targets: v.nodes.map(n => n.target) }))).toEqual([]);
    await acknowledgement.check();
    await page.getByRole('button', { name: 'Prepare transcript', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Document layout');
    await expect(page.locator('#drawer-body')).toContainText('Modern · A4');
    const commands = state.calls.filter(call => call.path.endsWith('/prepare/'));
    expect(commands).toHaveLength(1);
    expect(commands[0].data).toMatchObject({ layout_code: 'modern-a4', release_reviewed: true,
        review_token: 'fictional-modern-a4' });
    expect(state.calls.filter(call => call.data).every(call => call.csrf === 'fictional-test-token')).toBe(true);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(state.errors).toEqual([]);
});
