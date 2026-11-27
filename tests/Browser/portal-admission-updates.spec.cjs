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
        } else if (path === '/portal/api/notification-preferences/') json = {};
        else if (/^\/portal\/api\/(academic|standing|transcript|registration|advising|enrollment)-updates\/$/.test(path)) json = { items: [], next: null };
        else if (path === '/portal/api/announcements/') json = { items: [], next_after: null };
        else throw new Error(`Unexpected request: ${path}`);
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

test('an exact bell link opens current guidance and deletes only its displayed personal notice', async ({ page }) => {
    const notice = id(3), application = id(4), writes = [], requests = [], errors = [];
    let deleted = false, stale = true;
    const item = { id: notice, application_id: application, revision: 2, unread: true,
        program_code: 'ARTS-UG', program_title: 'Fictional Undergraduate Arts', term_code: '26/FA',
        updated_at: '2026-11-26T10:00:00.000000Z' };
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/portal/api/**', async route => {
        const request = route.request(), path = new URL(request.url()).pathname;
        requests.push(path);
        let json = { items: [], next: null };
        if (path === '/portal/api/admissions-updates/') json = { items: deleted ? [] : [item], unread_count: deleted ? 0 : 1, next: null };
        else if (path === `/portal/api/admissions-updates/${notice}/`) json = item;
        else if (path.endsWith('/next-steps/')) json = { office: 'Fictional admissions', next_step: 'Review your fictional offer.', items: [] };
        else if (request.method() === 'POST') {
            expect(request.headers()['x-csrf-token']).toBe('fictional-test-token');
            writes.push({ path, body: JSON.parse(new URLSearchParams(request.postData()).get('_workspace_json')) });
            if (path.endsWith('/delete/')) {
                if (stale) return route.fulfill({ status: 409, json: { error: 'Fictional changed notice.' } });
                deleted = true; json = { deleted: true };
            } else json = { unread: false };
        }
        await route.fulfill({ json, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto(`/portal/admissions-updates/?update=${notice}`);
    const detail = page.getByRole('region', { name: 'Admissions update details' });
    await expect(detail).toContainText('Review your fictional offer.');
    await expect(detail).toContainText('Update marked read.');
    expect(requests.indexOf(`/portal/api/admissions-updates/${notice}/`)).toBeLessThan(requests.indexOf(`/portal/api/applications/${application}/next-steps/`));
    expect(requests).not.toContain('/portal/api/me/');
    await page.getByRole('button', { name: 'Delete update', exact: true }).click();
    await expect(page.getByText('This update changed or is unavailable. Refresh the list.', { exact: true })).toBeVisible();
    await expect(detail).toContainText('Review your fictional offer.');
    stale = false;
    await page.setViewportSize({ width: 320, height: 812 });
    await page.evaluate(() => { document.documentElement.style.fontSize = '200%'; });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.getByRole('button', { name: 'Delete update', exact: true }).click();
    await expect(page.getByText('Admission update deleted.', { exact: true })).toBeFocused();
    await expect(detail).toBeHidden();
    await expect(page.getByText('Fictional Undergraduate Arts', { exact: false })).toHaveCount(0);
    expect(writes.map(write => write.body)).toEqual([{ revision: 2 }, { revision: 2 }, { revision: 2 }]);
    expect(writes.every(write => write.path.startsWith('/portal/api/admissions-updates/'))).toBe(true);
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(issue => issue.id)).toEqual([]);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(errors).toEqual([]);
});

async function verifyBell(page, workspace = false) {
    const notice = id(5), writes = [], errors = [];
    let deleted = false;
    page.on('pageerror', error => errors.push(error.message));
    if (workspace) await page.route('**/sis/**', route => route.fulfill({
        json: new URL(route.request().url()).pathname === '/sis/ui/metrics/'
            ? { cards: [], intake: [], pipeline: [], queue: [] } : { items: [], next_after: null },
        headers: { 'Cache-Control': 'private, no-store' },
    }));
    await page.route('**/portal/api/**', async route => {
        const request = route.request(), path = new URL(request.url()).pathname;
        let json = { items: [], next: null };
        if (path === '/portal/api/admissions-updates/') json = { items: deleted ? [] : [{ id: notice,
            application_id: id(6), revision: 4, unread: true, program_code: 'ARTS-UG', program_title: 'Fictional Arts',
            term_code: '26/FA', updated_at: '2026-11-26T10:00:00.000000Z' }], unread_count: deleted ? 0 : 31, next: null };
        else if (path.endsWith('/delete/')) {
            expect(path).toBe(`/portal/api/admissions-updates/${notice}/delete/`);
            expect(request.headers()['x-csrf-token']).toBe('fictional-test-token');
            writes.push(JSON.parse(new URLSearchParams(request.postData()).get('_workspace_json')));
            deleted = true; json = { deleted: true };
        }
        await route.fulfill({ json, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto(workspace ? '/admin/' : '/portal/admissions-updates/');
    const name = workspace ? 'Recent workspace notifications' : 'Recent notifications';
    const trigger = page.getByRole('button', { name, exact: true });
    await expect(trigger.locator('[data-notification-count]')).toHaveText('31');
    await trigger.click();
    const bell = page.getByRole('dialog', { name, exact: true });
    const section = bell.locator('.notification-feed');

    await expect(section).toContainText('Fictional Arts');
    await expect(section.getByRole('link', { name: 'View admissions update' })).toHaveAttribute('href', `/portal/admissions-updates/?update=${notice}`);
    await expect(section.locator('input:visible,select:visible')).toHaveCount(0);
    expect(writes).toEqual([]);
    await section.getByRole('button', { name: 'Delete notification', exact: true }).click();
    await expect(section).toContainText('Notification deleted.');
    await expect(section.locator('[data-update-id]')).toHaveCount(0);
    expect(writes).toEqual([{ revision: 4 }]);
    await page.keyboard.press('Escape');
    await expect(trigger).toBeFocused();
    await page.reload();
    await trigger.click();
    await expect(section).toContainText('You have no notifications.');
    expect(errors).toEqual([]);
}
test('the portal bell offers an exact admissions link and persistent deletion without preferences or read writes',
    async ({ page }) => verifyBell(page));
test('the workspace bell offers the same private admissions link and persistent deletion',
    async ({ page }) => verifyBell(page, true));
