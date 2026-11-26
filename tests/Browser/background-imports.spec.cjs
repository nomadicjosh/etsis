const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = '01K00000000000000000000001';

test('people review stays paged and private; confirmation queues only a proof and opens server activity', async ({ page }) => {
    const errors = [], writes = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const data = route.request().method() === 'POST' ? JSON.parse(new URLSearchParams(route.request().postData()).get('_workspace_json')) : {};
        if (route.request().method() === 'POST') writes.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        let result = { items: [], cards: [], intake: [], pipeline: [], queue: [] };
        if (path === '/sis/imports/people/preview/') result = {
            headers: ['Given', 'Family', 'Email'], ready: Object.keys(data.mapping).length === 3,
            review_token: Object.keys(data.mapping).length === 3 ? 'private-fictional-proof' : null,
            rows: Object.keys(data.mapping).length ? Array.from({ length: 32 }, (_, i) => ({ row: i + 2, given_name: 'Fictional' + i, family_name: 'Intake', email: `intake${i}@example.invalid`, issues: [] })) : [],
        };
        if (path === '/sis/imports/people/commit/') result = { id, state: 'queued', total: 32 };
        if (path === `/sis/imports/jobs/${id}/`) result = { id, target: 'people', state: 'queued', total: 32, processed: 0, imported: 0, linked: 0, skipped: 0, omitted: 0 };
        if (path === '/sis/imports/jobs/') result = { items: [{ id, target: 'people', state: 'queued', total: 32, processed: 0 }], next_after: null };
        await route.fulfill({ json: result });
    });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="imports"]').click();
    await page.locator('#import-people').click();
    await page.getByLabel('CSV file', { exact: true }).setInputFiles({ name: 'fictional.csv', mimeType: 'text/csv', buffer: Buffer.from('Given,Family,Email\nFictional,Intake,intake@example.invalid\n') });
    await page.getByLabel('Reason for creating these people').fill('Fictional new-person intake');
    await page.getByRole('button', { name: 'Read columns', exact: true }).click();
    await page.getByLabel('Given name', { exact: true }).selectOption('0');
    await page.getByLabel('Family name', { exact: true }).selectOption('1');
    await page.getByLabel('Email', { exact: true }).selectOption('2');
    await page.getByRole('button', { name: 'Review people', exact: true }).click();
    await expect(page.locator('#drawer-body tbody tr')).toHaveCount(25);
    await page.getByRole('button', { name: 'Next', exact: true }).click();
    await expect(page.locator('#drawer-body tbody tr')).toHaveCount(7);
    await page.setViewportSize({ width: 320, height: 812 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => item.id)).toEqual([]);
    await page.getByRole('button', { name: 'Queue import', exact: true }).click();
    await expect(page.locator('#drawer-title')).toHaveText('Import progress');
    await expect(page.locator('#drawer-body')).toContainText('You can leave this screen');
    await page.getByRole('button', { name: 'Back to import activity', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('0 / 32');
    expect(writes.find(call => call.path.endsWith('/commit/')).data).toEqual({ review_token: 'private-fictional-proof' });
    expect(writes.every(call => call.csrf === 'fictional-test-token')).toBe(true);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(errors).toEqual([]);
});

test('persistent import notices support read filtering and permanent deletion', async ({ page }) => {
    let removed = false, read = false;
    const mutations = [];
    await page.route('**/sis/**', async route => {
        const url = new URL(route.request().url());
        if (url.pathname.endsWith('/notices/read/')) { read = true; mutations.push('read'); }
        if (url.pathname.endsWith('/notices/remove/')) { removed = true; mutations.push('remove'); }
        let result = { items: [], cards: [], intake: [], pipeline: [], queue: [] };
        if (url.pathname === '/sis/imports/notices/') result = { items: removed || (read && url.searchParams.get('scope') !== 'all') ? [] : [
            { id, job_id: id, target: 'people', state: 'completed', processed: 32, total: 32, unread: !read, recorded_at: '2026-11-25T12:00:00.000000Z' },
        ], next_after: null };
        await route.fulfill({ json: result });
    });
    await page.goto('/admin/');
    await page.locator('#workspace-notifications-open').click();
    await expect(page.locator('[data-notification-count]')).toHaveText('1');
    await page.getByText('Import updates', { exact: true }).click();
    await expect(page.locator('.workspace-notification-dialog-body')).toContainText('32 / 32');
    await expect(page.getByRole('button', { name: 'Mark as read', exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'Delete notification', exact: true }).click();
    await expect(page.getByText('There are no matching import updates.', { exact: true })).toBeVisible();
    expect(mutations).toEqual(['remove']);
});

test('import-only staff can use catalog activity without receiving person creation controls', async ({ page }) => {
    await page.route('**/sis/**', route => route.fulfill({ json: { items: [], cards: [], intake: [], pipeline: [], queue: [] } }));
    await page.goto('/admin/course-import-fixture/');
    await page.locator('.workspace-nav-link[data-workspace="imports"]').click();
    await expect(page.locator('#import-people')).toBeHidden();
    await page.locator('#import-activity').click();
    await expect(page.locator('#drawer-body')).toContainText('No imports have been queued');
});
