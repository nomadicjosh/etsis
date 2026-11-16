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
            .filter(row => row.status === (data.scope === 'retired' ? 'template_retired' : 'template'))
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

test('institutional template review separates authoring, approval and shared reader access', async ({ page }) => {
    const source = { id: id(200), owner: 'author', status: 'template', version: 0,
        subject: 'Fictional institutional notice <img>', body: 'First fictional wording.' };
    const records = new Map([[source.id, source]]), calls = [], errors = [];
    let actor = 'author';
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname, raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        calls.push({ path, data });
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/data/campaigns/') result.items = [...records.values()]
            .filter(row => row.owner === actor).map(row => ({ id: row.id, status: row.status }));
        else if (path === '/sis/campaigns/templates/page/') {
            const status = { mine: 'template', submitted: 'template_review', review: 'template_review',
                institutional: 'template_shared', retired: 'template_retired' }[data.scope];
            result.items = [...records.values()].filter(row => row.status === status
                && (['review', 'institutional'].includes(data.scope) || row.owner === actor))
                .map(row => ({ id: row.id, subject: row.subject, version: row.version }));
        } else if (path === `/sis/campaigns/${source.id}/template/review/`) {
            expect(data.version).toBe(source.version);
            source.status = { submit: 'template_review', approve: 'template_shared', return: 'template', withdraw: 'template' }[data.action];
            source.version++;
            result = { status: 'saved' };
        } else if (path === `/sis/campaigns/${source.id}/template/draft/`) {
            result = { ...source, id: id(201), owner: actor, status: 'draft', version: 0 };
            records.set(result.id, result);
        } else if (path === `/sis/campaigns/${source.id}/template/edit/`) {
            Object.assign(source, { subject: data.subject, body: data.body, version: source.version + 1 });
            result = { status: 'saved' };
        } else {
            const match = path.match(/^\/sis\/campaigns\/([A-Z0-9]{26})\/$/);
            if (match) {
                const record = records.get(match[1]);
                result = { ...record, is_owner: record.owner === actor, can_dispatch: false };
            }
        }
        await route.fulfill({ json: result });
    });
    const body = page.locator('#drawer-body');
    const library = async (who, admin = false) => {
        actor = who;
        await page.goto(admin ? '/admin/communications-admin-fixture/' : '/admin/communications-fixture/');
        await page.locator('.workspace-nav-link[data-workspace="campaigns"]').click();
        await page.getByRole('button', { name: 'My message templates', exact: true }).click();
    };
    const open = () => page.getByRole('button', { name: `Open template ${source.subject}`, exact: true }).click();
    const submit = async () => {
        await page.getByRole('button', { name: 'Submit for institutional approval', exact: true }).click();
        await page.getByRole('button', { name: 'Confirm template submission', exact: true }).click();
        await expect(page.getByRole('button', { name: 'Edit template', exact: true })).toHaveCount(0);
    };
    await library('author');
    await expect(page.getByRole('button', { name: 'Awaiting approval', exact: true })).toHaveCount(0);
    await open(); await submit();
    await expect(page.getByRole('button', { name: 'Approve institutional template', exact: true })).toHaveCount(0);
    await library('reviewer', true);
    await page.getByRole('button', { name: 'Awaiting approval', exact: true }).click(); await open();
    await page.getByRole('button', { name: 'Return to author', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm return to author', exact: true }).click();
    await library('author'); await open();
    await page.getByRole('button', { name: 'Edit template', exact: true }).click();
    await page.getByRole('textbox', { name: 'Message', exact: true }).fill('Reviewed fictional wording.');
    await page.getByRole('button', { name: 'Save message template', exact: true }).click();
    await submit();
    await library('reviewer', true);
    await page.getByRole('button', { name: 'Awaiting approval', exact: true }).click(); await open();
    await page.getByRole('button', { name: 'Approve institutional template', exact: true }).click();
    await expect(body).toContainText('Reviewed fictional wording.');
    await page.getByRole('button', { name: 'Confirm institutional approval', exact: true }).click();
    await library('reader');
    await page.getByRole('button', { name: 'Institutional library', exact: true }).click(); await open();
    await expect(page.getByRole('button', { name: 'Retire template', exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Return to private editing', exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Edit template', exact: true })).toHaveCount(0);
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations).toEqual([]);
    await expect(body.locator('img')).toHaveCount(0);
    await page.getByRole('button', { name: 'Start a new message', exact: true }).click();
    await expect(body).toContainText('Reviewed fictional wording.');
    await library('author');
    await page.getByRole('button', { name: 'Institutional library', exact: true }).click(); await open();
    await page.getByRole('button', { name: 'Return to private editing', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm withdrawal to private editing', exact: true }).click();
    await library('reader');
    await page.getByRole('button', { name: 'Institutional library', exact: true }).click();
    await expect(body).toContainText('No templates in this library.');
    expect(records.get(id(201)).body).toBe('Reviewed fictional wording.');
    expect(calls.filter(call => call.path.endsWith('/template/review/')).map(call => call.data.action))
        .toEqual(['submit', 'return', 'submit', 'approve', 'withdraw']);
    expect(calls.some(call => /\/(queue|dispatch)\/$/.test(call.path))).toBe(false);
    expect(errors).toEqual([]);
});
