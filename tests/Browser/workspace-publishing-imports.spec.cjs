const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = '01K00000000000000000000001';

async function accessible(page) {
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => ({ id: item.id, targets: item.nodes.map(node => node.target) }))).toEqual([]);
}

async function reflow(page, preview) {
    await page.setViewportSize({ width: 320, height: 812 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await accessible(page);
    if (preview) await page.screenshot({ path: `test-results/${preview}-phone.png` });
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await accessible(page);
}

async function imports(page, delayed = false) {
    const state = { calls: [], errors: [] };
    let release;
    const pending = new Promise(resolve => { release = resolve; });
    state.release = release;
    page.on('pageerror', error => state.errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const request = route.request(), path = new URL(request.url()).pathname;
        state.calls.push(path);
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        if (path === '/sis/imports/preview/') {
            if (delayed) await pending;
            result = {
                headers: ['External ID', 'Code', 'Title', 'Credits'],
                samples: [['fictional-1', 'MATH.101', 'Fictional Algebra <img>', '3']], row_count: 1,
                ready: true, errors: [], review_token: 'fictional-review',
                summary: { create: 1, link: 0, skip: 0, omitted: 0, match: 0, conflict: 0 },
                plan: [{ row: 2, external_id: 'fictional-1', code: 'MATH.101', title: 'Fictional Algebra <img>',
                    credits_milli: 3000, action: 'create', message: 'Create an inactive draft.' }],
            };
        }
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="imports"]').click();
    await page.locator('#import-file').setInputFiles({ name: 'fictional-catalog.csv', mimeType: 'text/csv',
        buffer: Buffer.from('External ID,Code,Title,Credits\nfictional-1,MATH.101,Fictional Algebra,3\n') });
    await page.getByRole('button', { name: 'Match fields', exact: true }).click();
    return state;
}

test('mapping examples follow selected columns and review actions precede the row table', async ({ page }) => {
    const state = await imports(page);
    await expect(page.locator('[data-import-panel="2"] h2')).toBeFocused();
    await expect(page.locator('#import-file-summary')).toContainText('fictional-catalog.csv');
    await expect(page.locator('#map-title-sample')).toHaveText('Example: Fictional Algebra <img>');
    await page.getByRole('combobox', { name: /^Course title/u }).selectOption('1');
    await expect(page.locator('#map-title-sample')).toHaveText('Example: MATH.101');
    await page.getByRole('combobox', { name: /^Course title/u }).selectOption('2');
    expect(state.calls.filter(path => path === '/sis/imports/preview/')).toHaveLength(1);
    await expect(page.locator('#import-mapping img')).toHaveCount(0);
    await accessible(page);
    await page.screenshot({ path: 'test-results/workspace-polished-import-mapping.png' });
    await page.getByRole('button', { name: 'Review import', exact: true }).click();
    await expect(page.locator('[data-import-step="3"]')).toHaveAttribute('aria-current', 'step');
    await expect(page.locator('#import-plan-summary').getByText('New drafts', { exact: true }).locator('..').locator('dd')).toHaveText('1');
    const commit = page.getByRole('button', { name: 'Import courses', exact: true });
    await expect(commit).toBeEnabled();
    expect((await commit.boundingBox()).y).toBeLessThan((await page.locator('#import-plan-table').boundingBox()).y);
    await accessible(page);
    await page.screenshot({ path: 'test-results/workspace-polished-import-review.png' });
    await reflow(page, 'workspace-polished-import-review');
    expect(state.calls.some(path => path === '/sis/imports/commit/')).toBe(false);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(state.errors).toEqual([]);
});

test('a late import inspection does not move focus away from another workspace', async ({ page }) => {
    const state = await imports(page, true);
    await expect(page.locator('#import-source')).toBeDisabled();
    await expect(page.locator('#import-file')).toBeDisabled();
    await page.locator('.workspace-nav-link[data-workspace="portal"]').click();
    const create = page.getByRole('button', { name: 'Create content', exact: true });
    await create.focus();
    state.release();
    await expect(page.locator('#map-title-sample')).toHaveText('Example: Fictional Algebra <img>');
    await expect(create).toBeFocused();
    await expect(page.locator('[data-panel="imports"]')).toBeHidden();
    expect(state.errors).toEqual([]);
});

async function content(page, { status = 'draft', audience = 'public', rejectRead = false, delayed = false, limited = false } = {}) {
    const state = { calls: [], errors: [], rejectSave: false, stale: false,
        post: { id, kind: 'news', code: 'fictional-welcome', title: 'Fictional campus welcome <img>',
            content: 'Fictional campus news.\nThis text is only a test fixture. <script>Example</script>',
            audience, status, version: 2 } };
    let release;
    const pending = new Promise(resolve => { release = resolve; });
    state.release = release;
    page.on('pageerror', error => state.errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const request = route.request(), path = new URL(request.url()).pathname;
        const raw = request.postData(), data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        state.calls.push({ path, data, csrf: request.headers()['x-csrf-token'] });
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/data/portal/') result.items = [{ id, title: state.post.title, kind: state.post.kind, status: state.post.status }];
        else if (path === `/sis/portal/editor/${id}/`) {
            if (delayed) await pending;
            if (rejectRead) return route.fulfill({ status: 403, json: { error: 'Access denied.' } });
            result = state.post;
        } else if (path === '/sis/portal/editor/') {
            if (state.rejectSave) {
                state.rejectSave = false;
                return route.fulfill({ status: 422, json: { error: 'Please correct the highlighted fields.',
                    errors: [{ field: 'title', message: 'Fictional title needs review.' }] } });
            }
            state.post = { ...state.post, ...data, version: state.post.version + 1, status: 'draft' };
        } else if (path === `/sis/portal/posts/${id}/publication/`) {
            if (state.stale) {
                state.stale = false;
                state.post.version++;
                state.post.title = 'Updated fictional notice';
                return route.fulfill({ status: 409, json: { error: 'Fictional stale revision. Reload and review again.' } });
            }
            state.post.status = data.publish ? 'published' : 'archived';
            state.post.version++;
        }
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto(limited ? '/admin/campus-content-fixture/' : '/admin/');
    await page.locator('.workspace-nav-link[data-workspace="portal"]').click();
    state.open = () => page.locator('#directory-results tbody button').first().click();
    await state.open();
    state.publications = () => state.calls.filter(call => call.path.endsWith('/publication/'));
    return state;
}

test('content keeps failed edits and requires review of the exact saved audience and revision', async ({ page }) => {
    const state = await content(page);
    const body = page.locator('#drawer-body');
    const title = page.getByRole('textbox', { name: /^Title/u });
    const review = page.getByRole('button', { name: 'Review publication', exact: true });
    await expect(page.getByRole('combobox', { name: /^Content type/u })).toBeDisabled();
    await expect(page.getByRole('textbox', { name: /^Unique code/u })).toHaveAttribute('readonly', '');
    await expect(body.getByRole('link', { name: 'Page builder', exact: true })).toHaveAttribute('href', '/admin/manager');
    await title.fill('Updated fictional welcome');
    await expect(review).toBeDisabled();
    await expect(body).toContainText('Save your changes before reviewing');
    state.rejectSave = true;
    await page.getByRole('button', { name: 'Save draft', exact: true }).click();
    await expect(body).toContainText('Fictional title needs review.');
    await expect(title).toHaveValue('Updated fictional welcome');
    await expect(review).toBeDisabled();
    await page.getByRole('button', { name: 'Save draft', exact: true }).click();
    await expect(review).toBeEnabled();
    await accessible(page);
    await page.screenshot({ path: 'test-results/workspace-polished-content-editor.png' });
    await review.click();
    await expect(page.getByRole('region', { name: 'Saved content review', exact: true })).toContainText('Updated fictional welcome');
    await expect(body).toContainText('may be read without signing in');
    await expect(body.locator('script, img')).toHaveCount(0);
    expect(state.publications()).toHaveLength(0);
    await accessible(page);
    await page.screenshot({ path: 'test-results/workspace-polished-content-review.png' });
    await reflow(page, 'workspace-polished-content-review');
    await page.getByRole('button', { name: 'Confirm publication', exact: true }).click();
    await expect(page.locator('#record-drawer')).not.toHaveClass(/show/u);
    expect(state.publications()[0].data).toEqual({ publish: true, version: 3 });
    expect(state.publications()[0].csrf).toBe('fictional-test-token');
    expect(state.errors).toEqual([]);
});

test('a stale publication needs a fresh saved-content review', async ({ page }) => {
    const state = await content(page);
    state.stale = true;
    await page.getByRole('button', { name: 'Review publication', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm publication', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Fictional stale revision');
    await expect(page.getByRole('button', { name: 'Confirm publication', exact: true, includeHidden: true })).toHaveCount(0);
    expect(state.publications()).toHaveLength(1);
    await page.getByRole('button', { name: 'Back to editor', exact: true }).click();
    await expect(page.getByRole('textbox', { name: /^Title/u })).toHaveValue('Updated fictional notice');
    await page.getByRole('button', { name: 'Review publication', exact: true }).click();
    await expect(page.getByRole('region', { name: 'Saved content review', exact: true })).toContainText('Updated fictional notice');
    await page.getByRole('button', { name: 'Confirm publication', exact: true }).click();
    await expect(page.locator('#record-drawer')).not.toHaveClass(/show/u);
    expect(state.publications().map(call => call.data.version)).toEqual([2, 3]);
    expect(state.errors).toEqual([]);
});

test('new website links use the same structured editor and save a draft without publication', async ({ page }) => {
    const state = await content(page, { limited: true });
    await page.getByRole('button', { name: 'Close record', exact: true }).click();
    await page.getByRole('button', { name: 'Create content', exact: true }).click();
    await page.getByRole('combobox', { name: /^Content type/u }).selectOption('link');
    const link = page.getByRole('textbox', { name: /^HTTPS link/u });
    await expect(link).toHaveAttribute('rows', '3');
    await link.fill('https://example.invalid/fictional-campus/');
    await page.getByRole('textbox', { name: /^Unique code/u }).fill('fictional-link');
    await page.getByRole('textbox', { name: /^Title/u }).fill('Fictional campus link');
    await page.getByRole('combobox', { name: /^Audience/u }).selectOption('campus');
    await accessible(page);
    await page.getByRole('button', { name: 'Save and continue', exact: false }).click();
    await expect(page.locator('#record-drawer')).not.toHaveClass(/show/u);
    expect(state.calls.find(call => call.path === '/sis/portal/editor/').data).toEqual({
        kind: 'link', code: 'fictional-link', title: 'Fictional campus link',
        content: 'https://example.invalid/fictional-campus/', audience: 'campus',
    });
    expect(state.publications()).toHaveLength(0);
    expect(state.errors).toEqual([]);
});

test('archiving needs a separate confirmation and does not offer direct republication', async ({ page }) => {
    const state = await content(page, { status: 'published', audience: 'campus', limited: true });
    await expect(page.locator('#drawer-body').getByRole('link', { name: 'Page builder', exact: true, includeHidden: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'Review archive', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Its saved wording is retained');
    expect(state.publications()).toHaveLength(0);
    await page.getByRole('button', { name: 'Confirm archive', exact: true }).click();
    await expect(page.locator('#record-drawer')).not.toHaveClass(/show/u);
    expect(state.publications()[0].data).toEqual({ publish: false, version: 2 });
    await state.open();
    await expect(page.getByRole('button', { name: /^Review (publication|archive)$/u, includeHidden: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Save draft', exact: true })).toBeVisible();
    expect(state.errors).toEqual([]);
});

test('denied content reads render no publication controls or private wording', async ({ page }) => {
    const state = await content(page, { rejectRead: true, limited: true });
    await expect(page.locator('#drawer-body')).toContainText('This task needs additional access.');
    await expect(page.locator('#drawer-body')).not.toContainText(state.post.content);
    await expect(page.locator('#drawer-body').getByRole('textbox', { includeHidden: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: /^Review (publication|archive)$/u, includeHidden: true })).toHaveCount(0);
    expect(state.publications()).toHaveLength(0);
    expect(state.errors).toEqual([]);
});

test('closing an editor before its content arrives does not reopen it', async ({ page }) => {
    const state = await content(page, { delayed: true });
    await page.getByRole('button', { name: 'Close record', exact: true }).click();
    await expect(page.locator('#drawer-body')).toBeEmpty();
    const finished = page.waitForEvent('requestfinished', { predicate: request => new URL(request.url()).pathname === `/sis/portal/editor/${id}/` });
    state.release();
    await finished;
    // The isolated fixture has no polling; settle the released read before asserting no late editor.
    await page.waitForLoadState('networkidle');
    await expect(page.locator('#drawer-body')).toBeEmpty();
    expect(state.publications()).toHaveLength(0);
    expect(state.errors).toEqual([]);
});
