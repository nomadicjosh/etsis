const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => String(n).padStart(26, '0');

test('private message templates create independent drafts and retire without changing earlier messages', async ({ page }) => {
    const records = new Map(), calls = [], errors = [];
    let sequence = 100, rejectEdit = true;
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname, raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        calls.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/data/campaigns/') result.items = [...records.values()].map(row => ({ id: row.id, status: row.status }));
        else if (path === '/sis/campaigns/templates/page/') result.items = [...records.values()]
            .filter(row => row.status === (data.retired ? 'template_retired' : 'template'))
            .map(row => ({ id: row.id, subject: row.subject, version: row.version }));
        else if (path === '/sis/campaigns/templates/create/') {
            result = { ...data, id: id(++sequence), status: 'template', version: 0 };
            records.set(result.id, result);
        } else {
            const match = path.match(/^\/sis\/campaigns\/([A-Z0-9]{26})\/(?:template\/(draft|edit|status)\/)?$/);
            if (match) {
                const source = records.get(match[1]);
                if (match[2] === 'edit') {
                    if (rejectEdit) {
                        rejectEdit = false;
                        return route.fulfill({ status: 422, json: { error: 'Please review the highlighted field.',
                            errors: [{ field: 'subject', message: 'Fictional template needs review.' }] } });
                    }
                    Object.assign(source, { subject: data.subject, body: data.body, version: source.version + 1 });
                    result = { status: 'saved' };
                } else if (match[2] === 'draft') {
                    result = { ...source, id: id(++sequence), status: 'draft', version: 0 };
                    records.set(result.id, result);
                } else if (match[2] === 'status') {
                    source.status = data.retired ? 'template_retired' : 'template'; source.version++;
                    result = { status: 'saved' };
                } else result = { ...source, can_dispatch: false };
            }
        }
        await route.fulfill({ json: result });
    });
    await page.goto('/admin/');
    const nav = () => page.locator('.workspace-nav-link[data-workspace="campaigns"]').click();
    const close = () => page.getByRole('button', { name: 'Close record', exact: true }).click();
    const body = page.locator('#drawer-body');
    await nav();
    await page.getByRole('button', { name: 'My message templates', exact: true }).click();
    await expect(body).toContainText('No reusable templates yet.');
    await page.getByRole('button', { name: 'Create reusable template', exact: true }).click();
    await page.getByRole('textbox', { name: 'Subject', exact: true }).fill('Fictional welcome <img>');
    await page.getByRole('textbox', { name: 'Message', exact: true }).fill('Fictional reusable wording.');
    await page.getByRole('button', { name: 'Save reusable template', exact: true }).click();
    await page.getByRole('button', { name: 'Open template Fictional welcome <img>', exact: true }).click();
    await expect(body.locator('img')).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Choose recipients', exact: true })).toHaveCount(0);
    await expect(body).not.toContainText('Delivery queue');
    await page.getByRole('button', { name: 'Edit template', exact: true }).click();
    await page.getByRole('textbox', { name: 'Subject', exact: true }).fill('Fictional reusable notice');
    await page.getByRole('textbox', { name: 'Message', exact: true }).fill('Reviewed version one.');
    await page.getByRole('button', { name: 'Save message template', exact: true }).click();
    await expect(body).toContainText('Fictional template needs review.');
    await expect(page.getByRole('textbox', { name: 'Message', exact: true })).toHaveValue('Reviewed version one.');
    await page.getByRole('button', { name: 'Save message template', exact: true }).click();
    await page.getByRole('button', { name: 'Start a new message', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Choose recipients', exact: true })).toBeVisible();
    const draft = [...records.values()].find(row => row.status === 'draft');
    expect(draft.body).toBe('Reviewed version one.');
    await close(); await nav();
    await page.getByRole('button', { name: 'My message templates', exact: true }).click();
    await page.getByRole('button', { name: 'Open template Fictional reusable notice', exact: true }).click();
    await page.getByRole('button', { name: 'Edit template', exact: true }).click();
    await page.getByRole('textbox', { name: 'Message', exact: true }).fill('Future template wording.');
    await page.getByRole('button', { name: 'Save message template', exact: true }).click();
    await page.getByRole('button', { name: 'Retire template', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm template retirement', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Start a new message', exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'Back to my templates', exact: true }).click();
    await expect(body).toContainText('Fictional reusable notice');
    await page.getByRole('button', { name: 'Show active templates', exact: true }).click();
    await expect(body).toContainText('No reusable templates yet.');
    await page.getByRole('button', { name: 'Show retired templates', exact: true }).click();
    await page.getByRole('button', { name: 'Open template Fictional reusable notice', exact: true }).click();
    await page.getByRole('button', { name: 'Restore template', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm template restoration', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Start a new message', exact: true })).toBeVisible();
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations).toEqual([]);
    await page.setViewportSize({ width: 320, height: 900 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
    await page.setViewportSize({ width: 1440, height: 1000 });
    await close(); await nav();
    await page.getByRole('button', { name: `…${draft.id.slice(-8)}`, exact: true }).click();
    await expect(body).toContainText('Reviewed version one.');
    await expect(body).not.toContainText('Future template wording.');
    expect(calls.some(call => /\/(queue|dispatch)\/$/.test(call.path))).toBe(false);
    expect(calls.filter(call => call.data).every(call => call.csrf === 'fictional-test-token')).toBe(true);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(errors).toEqual([]);
});
