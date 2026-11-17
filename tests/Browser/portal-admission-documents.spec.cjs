const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = number => String(number).padStart(26, '0');

test('applicants upload privately in a full-width panel while status refresh preserves their selection', async ({ page }) => {
    const application = id(1), person = id(2), upload = id(3), writes = [], errors = [];
    let uploaded = false, ready = false, statusReads = 0;
    page.on('pageerror', error => errors.push(error.message));
    await page.route(/\/(?:portal\/api|sis\/forms)\//, async route => {
        const request = route.request(), path = new URL(request.url()).pathname;
        let json;
        if (path === '/portal/api/applications/') {
            statusReads++;
            json = { person_id: person, items: [{ id: application, person_id: person, status: 'submitted',
                program_id: id(4), entry_term_id: id(5), program_code: 'BA.ART', program_title: 'Fictional Arts', term_code: '26/FA', submission_id: null }] };
        } else if (path === `/portal/api/applications/${application}/documents/`) {
            if (request.method() === 'POST') {
                expect(request.headers()['content-type']).toMatch(/^multipart\/form-data; boundary=/);
                expect(request.headers()['x-csrf-token']).toBe('fictional-test-token');
                expect(request.postData()).toContain(upload);
                writes.push(path); uploaded = true; json = { id: upload, status: 'quarantined' };
            } else json = { enabled: true, can_retain: false, upload_id: upload, max_bytes: 5242880, items: uploaded ? [{
                id: upload, name: 'fictional.pdf', bytes: 50, status: ready ? 'ready' : 'quarantined', version: ready ? 3 : 1,
                created_at: '2026-11-17T00:00:00.000000Z', expires_at: '2027-11-17T00:00:00.000000Z', can_download: ready,
            }] : [] };
        } else if (path.includes('/active/')) json = { id: id(9), fields: [], layout: [], submission_id: id(10) };
        else throw new Error(`Unexpected request: ${path}`);
        await route.fulfill({ json, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/portal/apply/');
    await page.getByRole('button', { name: /Documents for BA.ART/ }).click();
    const input = page.getByLabel('Choose a document', { exact: true });
    await input.setInputFiles({ name: 'fictional.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\nFictional document.\n%%EOF') });
    expect(await input.locator('xpath=ancestor::table').count()).toBe(0);
    const reads = statusReads;
    await page.getByRole('button', { name: 'Refresh status', exact: true }).click();
    await expect(page.getByText('Save or clear document changes before starting another task.', { exact: true })).toBeVisible();
    expect(statusReads).toBe(reads);
    expect(await input.evaluate(element => element.files.length)).toBe(1);
    await page.getByRole('button', { name: 'Upload document', exact: true }).click();
    await expect(page.getByText('Waiting for safety scan', { exact: true })).toBeVisible();
    expect(writes).toHaveLength(1);
    expect(await page.getByRole('link', { name: 'Download', exact: true }).count()).toBe(0);
    ready = true;
    await page.getByRole('button', { name: 'Refresh documents', exact: true }).click();
    await expect(page.getByRole('link', { name: 'Download', exact: true })).toHaveAttribute('href',
        `/portal/api/applications/${application}/documents/${upload}/download/`);
    await expect(page.getByText('Retention controls', { exact: true })).toHaveCount(0);
    await page.setViewportSize({ width: 390, height: 844 });
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => item.id)).toEqual([]); expect(errors).toEqual([]);
});
