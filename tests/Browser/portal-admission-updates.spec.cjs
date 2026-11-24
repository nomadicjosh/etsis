const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = number => String(number).padStart(26, '0');

test('applicants read their next steps and acknowledge an update without loading the student overview', async ({ page }) => {
    const application = id(1), notice = id(2), requests = [], writes = [], errors = [];
    let read = false;
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/portal/api/**', async route => {
        const request = route.request(), path = new URL(request.url()).pathname;
        requests.push(path);
        let json;
        if (path === '/portal/api/admissions-updates/') json = { items: read ? [] : [{ id: notice,
            application_id: application, revision: 3, unread: true, program_code: 'BA.ART',
            program_title: 'Fictional Arts', term_code: '26/FA', updated_at: '2026-11-17T10:00:00.000000Z' }],
            unread_count: read ? 0 : 1, next: null };
        else if (path === `/portal/api/applications/${application}/next-steps/`) json = { office: 'Admissions',
            next_step: 'Arrange delivery of your previous transcript.', items: [] };
        else if (path === `/portal/api/admissions-updates/${notice}/read/`) {
            expect(request.method()).toBe('POST');
            expect(request.headers()['x-csrf-token']).toBe('fictional-test-token');
            writes.push(JSON.parse(new URLSearchParams(request.postData()).get('_workspace_json')));
            read = true; json = { unread: false };
        } else throw new Error(`Unexpected request: ${path}`);
        await route.fulfill({ json, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/portal/admissions-updates/');
    await expect(page.getByRole('heading', { name: 'Admissions updates', exact: true })).toBeVisible();
    await expect(page.getByText('1 unread admissions updates', { exact: true })).toBeVisible();
    expect(writes).toEqual([]);
    await page.getByRole('button', { name: 'Read update', exact: true }).click();
    const detail = page.getByRole('region', { name: 'Admissions update details' });
    await expect(detail).toContainText('Arrange delivery of your previous transcript.');
    await expect(detail).toContainText('Update marked read.');
    expect(writes).toEqual([{ revision: 3 }]);
    expect(requests).not.toContain('/portal/api/me/');
    await page.setViewportSize({ width: 390, height: 844 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => ({ id: item.id, nodes: item.nodes.map(node => ({ target: node.target, summary: node.failureSummary })) }))).toEqual([]);
    expect(errors).toEqual([]);
});
