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

async function content(page, { status = 'draft', audience = 'public', rejectRead = false, delayed = false, limited = false, writerOnly = false, publisherOnly = false, rich = false } = {}) {
    const state = { calls: [], errors: [], rejectSave: false, stale: false,
        post: { id, kind: 'news', code: 'fictional-welcome', title: 'Fictional campus welcome <img>',
            content: 'Fictional campus news.\nThis text is only a test fixture. <script>Example</script>',
            audience, status, content_format: 'plain', content_html: null, version: 2, revision: '2'.padStart(64, '0'), can_edit: !publisherOnly, can_publish: !writerOnly, visible_from: null, visible_until: null, review_note: null, visibility: status } };
    if (rich) Object.assign(state.post, {content_format:'html',
        content:'<h2>Retained headline</h2><p>A <strong>formatted</strong> update with <a href="https://example.invalid/news">a link</a>.</p><ul><li><p>Retained list item</p></li></ul><script>window.editorAttack=1</script><img src="https://example.invalid/tracker" onerror="window.editorAttack=1">'});
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
            result = { ...state.post, visibility: state.post.status };
        } else if (path === '/sis/portal/editor/') {
            if (state.rejectSave) {
                state.rejectSave = false;
                return route.fulfill({ status: 422, json: { error: 'Please correct the highlighted fields.',
                    errors: [{ field: 'title', message: 'Fictional title needs review.' }] } });
            }
            state.post = { ...state.post, ...data, version: state.post.version + 1, status: 'draft' };
            state.post.revision = String(state.post.version).padStart(64, '0');
            state.post.content_html = state.post.content_format === 'html' ? state.post.content : null;
        } else if (path === `/sis/portal/posts/${id}/publication/review/`) {
            if (state.reviewGate) await state.reviewGate;
            result = { ...data, post: state.post, operation_id: '01K00000000000000000000002', review_token: 'fictional-publication-proof' };
        } else if (path === `/sis/portal/posts/${id}/publication/`) {
            if (state.stale) {
                state.stale = false;
                state.post.version++;
                state.post.revision = String(state.post.version).padStart(64, '0');
                state.post.title = 'Updated fictional notice';
                return route.fulfill({ status: 409, json: { error: 'Fictional stale revision. Reload and review again.' } });
            }
            state.post.status = { approve: 'published', archive: 'archived', submit: 'pending', return: 'draft' }[data.action];
            state.post.review_note = data.note;
            Object.assign(state.post, data.window);
            state.post.version++;
            state.post.revision = String(state.post.version).padStart(64, '0');
        }
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto(writerOnly ? '/admin/campus-content-fixture/' : limited ? '/admin/campus-publisher-fixture/' : '/admin/');
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
    expect(state.publications()[0].data).toMatchObject({ action: 'approve', revision: '3'.padStart(64, '0'), window: { visible_from: null, visible_until: null }, review_token: 'fictional-publication-proof' });
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
    expect(state.publications().map(call => call.data.revision)).toEqual([2, 3].map(v => String(v).padStart(64, '0')));
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
        content: 'https://example.invalid/fictional-campus/', audience: 'campus', content_format: 'plain',
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
    expect(state.publications()[0].data).toMatchObject({ action: 'archive', revision: '2'.padStart(64, '0'), review_token: 'fictional-publication-proof' });
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

test('program imports share mapping, dependency setup and explicit links without course-credit controls', async ({ page }) => {
    const calls = [], errors = [];
    let choices = [], linked = false;
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname, raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        calls.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/imports/preview/') {
            choices = data.choices;
            linked = choices.some(c => c.action === 'link');
            result = { headers: ['External ID', 'Program code', 'Program title', 'Program description', 'Campus/site code'],
                samples: [['new', 'BA.ENG', 'Fictional English <img>', 'Fictional <b>description</b>', 'MAIN']], row_count: 2,
                ready: !!Object.keys(data.mapping).length && linked, errors: [], review_token: linked ? 'fictional-program-import' : null,
                summary: { create: 1, link: linked ? 1 : 0, skip: 0, omitted: 0, match: linked ? 0 : 1, conflict: 0 },
                plan: [{ row: 2, external_id: 'new', code: 'BA.ENG', title: 'Fictional English <img>', action: 'create', program_id: null,
                    description: 'Fictional <b>description</b>', description_mapped: true, message: 'Create a working program definition.',
                    source_references: { campus: 'MAIN' }, references: { campus: { id, code: 'MAIN', title: 'Fictional campus' } } },
                { row: 3, external_id: 'link', code: 'BS.CS', title: 'Computing', action: linked ? 'link' : 'match', program_id: id,
                    matched_program: { id, code: 'BS.CS', title: 'Computing' }, message: 'Approve the existing program link.' }] };
        } else if (path === '/sis/imports/commit/') result = { imported: 1, linked: 1, skipped: 0, omitted: 0, total: 2 };
        else if (path === '/sis/catalog/classifications/campus/page/') result = { items: [{ id, code: 'MAIN', title: 'Fictional campus', active: true }], next_after: null };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="imports"]').click();
    await page.getByLabel('Import destination', { exact: true }).selectOption('program');
    await expect(page.locator('#import-source')).toHaveValue('program-catalog');
    await expect(page.getByRole('link', { name: 'Sample CSV', exact: true })).toHaveAttribute('href', '/assets/examples/programs.csv');
    await page.locator('#import-file').setInputFiles({ name: 'fictional-programs.csv', mimeType: 'text/csv',
        buffer: Buffer.from('External ID,Program code,Program title,Program description,Campus/site code\nnew,BA.ENG,Fictional English,Fictional description,MAIN\nlink,BS.CS,Computing,,\n') });
    await page.getByRole('button', { name: 'Match fields', exact: true }).click();
    await expect(page.getByRole('combobox', { name: /^Program code/ })).toHaveValue('1');
    await expect(page.locator('#map-credits')).toHaveCount(0);
    await expect(page.locator('#map-description-sample')).toHaveText('Example: Fictional <b>description</b>');
    await page.getByRole('button', { name: 'Review import', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Import programs', exact: true })).toBeDisabled();
    await page.getByRole('button', { name: 'Link program for row 3', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Import programs', exact: true })).toBeDisabled();
    await page.getByRole('button', { name: 'Review choices', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Import programs', exact: true })).toBeEnabled();
    await page.locator('#import-catalog-setup summary').click();
    await page.getByRole('button', { name: 'Campuses & sites', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Fictional campus');
    await page.getByRole('button', { name: 'Close record', exact: true }).click();
    await expect(page.locator('#import-plan-table')).toContainText('Fictional English <img>');
    await expect(page.locator('#import-plan-table img, #import-plan-table b')).toHaveCount(0);
    await expect(page.locator('#import-plan-table')).not.toContainText('Credits');
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.getByRole('button', { name: 'Import programs', exact: true }).click();
    await expect(page.locator('#import-summary')).toContainText('Working programs created: 1');
    await expect(page.getByRole('link', { name: 'Review program details', exact: true })).toHaveAttribute('href', '/admin/workspace/programs/');
    const command = calls.find(c => c.path === '/sis/imports/commit/').data;
    expect(command.target).toBe('program');
    expect(command.choices).toEqual([{ external_id: 'link', action: 'link', program_id: id }]);
    expect(command.mapping.campus_code).toBe(4);
    expect(command.mapping.credits).toBeUndefined();
    expect(calls.filter(c => c.data).every(c => c.csrf === 'fictional-test-token')).toBe(true);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(errors).toEqual([]);
});


test('scheduled publication invalidates earlier reviews, preserves dates and ignores delayed responses', async ({ page }) => {
    const state = await content(page);
    await page.getByRole('button', { name: 'Review publication', exact: true }).click();
    const confirm = page.getByRole('button', { name: 'Confirm publication', exact: true });
    await expect(confirm).toBeEnabled();
    await page.getByLabel('Start publishing', { exact: true }).fill('2028-09-01T09:00');
    await page.getByLabel('Stop publishing', { exact: true }).fill('2028-09-02T09:00');
    await expect(confirm).toBeDisabled();
    let release;
    state.reviewGate = new Promise(resolve => { release = resolve; });
    await page.getByRole('button', { name: 'Review schedule', exact: true }).click();
    await expect.poll(() => state.calls.filter(c => c.path.endsWith('/publication/review/')).length).toBe(2);
    await page.getByLabel('Stop publishing', { exact: true }).fill('2028-09-03T09:00');
    state.reviewGate = null; release();
    await expect(confirm).toBeDisabled();
    await page.getByRole('button', { name: 'Review schedule', exact: true }).click();
    await expect(confirm).toBeEnabled();
    await expect(page.getByLabel('Stop publishing', { exact: true })).toHaveValue('2028-09-03T09:00');
    await reflow(page);
    await confirm.click();
    const request = state.publications()[0];
    expect(new Date(request.data.window.visible_until) > new Date(request.data.window.visible_from)).toBe(true);
    expect(request.data.window.visible_until).toContain('2028-09-03');
    expect(request.csrf).toBe('fictional-test-token');
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(state.errors).toEqual([]);
});

test('authors request review without publishing and publishers return feedback without editing another draft', async ({ page }) => {
    let state = await content(page, { writerOnly: true });
    await expect(page.getByRole('button', { name: 'Review publication', exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'Request review', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm review request', exact: true }).click();
    expect(state.publications()[0].data.action).toBe('submit');
    await page.unroute('**/sis/**');
    state = await content(page, { publisherOnly: true, status: 'pending', limited: true });
    await expect(page.getByRole('button', { name: 'Save draft', exact: true })).toHaveCount(0);
    await expect(page.getByRole('region', { name: 'Content awaiting review', exact: true })).toContainText('Fictional campus news.');
    await page.getByRole('button', { name: 'Return to author', exact: true }).click();
    await page.getByLabel('Note to author', { exact: true }).fill('Fictional request to clarify the date.');
    await page.getByRole('button', { name: 'Review return', exact: true }).click();
    await accessible(page);
    await page.getByRole('button', { name: 'Return to author', exact: true }).click();
    expect(state.publications()[0].data.note).toBe('Fictional request to clarify the date.');
    expect(state.publications()[0].data.action).toBe('return');
    expect(state.errors).toEqual([]);
});


test('news uses the self-hosted rich editor and saves semantic formatting without changing publication', async ({ page }) => {
    const state = await content(page);
    const editor = page.getByRole('textbox', { name: /^Content/u });
    await expect(editor).toHaveAttribute('contenteditable', 'true');
    await expect(page.getByRole('group', { name: 'News formatting', exact: true })).toBeVisible();
    await editor.fill('Fictional welcome to campus.');
    await editor.press('ControlOrMeta+A');
    await page.getByRole('button', { name: 'Bold', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Bold', exact: true })).toHaveAttribute('aria-pressed', 'true');
    await expect(page.locator('textarea[name="content"]')).toHaveValue(/<strong>Fictional welcome to campus\.<\/strong>/);
    await page.getByRole('combobox', { name: 'Text style', exact: true }).selectOption('2');
    await expect(page.locator('textarea[name="content"]')).toHaveValue(/<h2>/);
    await accessible(page);
    await page.setViewportSize({ width: 375, height: 812 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await accessible(page);
    await page.getByRole('button', { name: 'Save draft', exact: true }).click();
    const save = state.calls.find(c => c.path === '/sis/portal/editor/');
    expect(save.data.content_format).toBe('html');
    expect(save.data.content).toContain('<strong>');
    expect(save.data.content).toContain('<h2>');
    expect(state.publications()).toHaveLength(0);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(state.errors).toEqual([]);
});

test('tiptap preserves retained HTML and literal plain text while rejecting unsafe pasted markup', async ({page}) => {
    const requests=[];
    page.on('request', request => requests.push(request.url()));
    const state = await content(page,{rich:true});
    const editor=page.getByRole('textbox',{name:/^Content/u});
    await expect(editor).toHaveAttribute('contenteditable','true');
    await expect(editor.locator('h2')).toHaveText('Retained headline');
    await expect(editor.locator('strong')).toHaveText('formatted');
    await expect(editor.locator('li')).toContainText('Retained list item');
    await expect(editor.locator('a')).toHaveAttribute('href','https://example.invalid/news');
    await expect(editor.locator('script,img,iframe')).toHaveCount(0);
    await editor.press('ControlOrMeta+End');
    await editor.evaluate(element=>{
        const clipboardData=new DataTransfer();
        clipboardData.setData('text/html','<p>Safe pasted <em>word</em><a href="javascript:window.editorAttack=1">bad link</a><img src="https://example.invalid/pasted-tracker" onerror="window.editorAttack=1"></p>');
        element.dispatchEvent(new ClipboardEvent('paste',{clipboardData,bubbles:true,cancelable:true}));
    });
    await expect(editor).toContainText('Safe pasted');
    await expect(editor.locator('script,img,iframe,a[href^="javascript:"]')).toHaveCount(0);
    expect(await page.evaluate(()=>window.editorAttack)).toBeUndefined();
    expect(requests.some(url=>url.startsWith('https://example.invalid/'))).toBe(false);
    await page.getByRole('button',{name:'Save draft',exact:true}).click();
    const save=state.calls.find(c=>c.path==='/sis/portal/editor/');
    expect(save.data.content).toContain('<h2>Retained headline</h2>');
    expect(save.data.content).toContain('<em>word</em>');
    expect(save.data.content).not.toMatch(/editorAttack|<img|<script|javascript:/);
    expect(state.errors).toEqual([]);
});

test('tiptap formatting links undo redo and content-type changes preserve the working draft', async ({page}) => {
    const state=await content(page);
    await expect(page.getByRole('textbox',{name:/^Content/u})).toContainText('<script>Example</script>');
    await page.getByRole('button',{name:'Close record',exact:true}).click();
    await page.getByRole('button',{name:'Create content',exact:true}).click();
    await page.getByRole('combobox',{name:/^Content type/u}).selectOption('news');
    const editor=page.getByRole('textbox',{name:/^Content/u});
    await expect(editor).toHaveAttribute('contenteditable','true');
    await editor.fill('Fictional formatted draft');
    await editor.press('ControlOrMeta+A');
    for(const name of ['Bold','Italic','Underline','Strikethrough']) {
        await page.getByRole('button',{name,exact:true}).click();
        await expect(page.getByRole('button',{name,exact:true})).toHaveAttribute('aria-pressed','true');
    }
    await page.getByRole('button',{name:'Add or edit link',exact:true}).click();
    await page.getByLabel('Link address',{exact:true}).fill('javascript:alert(1)');
    await page.getByRole('button',{name:'Apply link',exact:true}).click();
    await expect(editor.locator('a')).toHaveCount(0);
    await page.getByLabel('Link address',{exact:true}).fill('https://example.invalid/news');
    await page.getByRole('button',{name:'Apply link',exact:true}).click();
    await expect(editor.locator('a')).toHaveAttribute('href','https://example.invalid/news');
    await page.getByRole('button',{name:'Undo',exact:true}).click();
    await expect(editor.locator('a')).toHaveCount(0);
    await page.getByRole('button',{name:'Redo',exact:true}).click();
    await expect(editor.locator('a')).toHaveCount(1);
    await page.getByRole('combobox',{name:/^Content type/u}).selectOption('page');
    await expect(page.locator('textarea[name="content"]')).toHaveValue('Fictional formatted draft');
    await page.getByRole('combobox',{name:/^Content type/u}).selectOption('news');
    await expect(editor.locator('strong')).toContainText('Fictional formatted draft');
    await expect(editor.locator('a')).toHaveCount(1);
    await editor.click();
    await editor.press('ControlOrMeta+A');
    await page.getByRole('button',{name:'Clear formatting',exact:true}).click();
    await expect(editor.locator('strong,em,u,s,a')).toHaveCount(0);
    for (const [name, tag] of [['Bulleted list','ul'],['Numbered list','ol'],['Quote','blockquote']]) {
        await page.getByRole('button',{name,exact:true}).click();
        await expect(editor.locator(tag)).toHaveCount(1);
        await page.getByRole('button',{name,exact:true}).click();
        await expect(editor.locator(tag)).toHaveCount(0);
    }
    await page.getByRole('button',{name:'Bold',exact:true}).focus();
    await page.getByRole('button',{name:'Bold',exact:true}).press('Space');
    await expect(page.getByRole('button',{name:'Bold',exact:true})).toHaveAttribute('aria-pressed','true');

    await page.setViewportSize({width:320,height:812});
    await accessible(page);
    expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
    expect(state.publications()).toHaveLength(0);
    expect(state.errors).toEqual([]);
});

test('failed tiptap load preserves saved HTML blocks saving and supports an explicit retry', async ({page}) => {
    await page.route('**/assets/vendor/tiptap.js', route=>route.abort());
    const state=await content(page,{rich:true});
    await expect(page.getByRole('button',{name:'Retry news editor',exact:true})).toBeVisible();
    await expect(page.locator('textarea[name="content"]')).toHaveValue(state.post.content);
    await expect(page.locator('input[name="content_format"]')).toHaveValue('html');
    await page.getByRole('button',{name:'Save draft',exact:true}).click();
    expect(state.calls.some(c=>c.path==='/sis/portal/editor/')).toBe(false);
    await page.unroute('**/assets/vendor/tiptap.js');
    await page.getByRole('button',{name:'Retry news editor',exact:true}).click();
    const editor=page.getByRole('textbox',{name:/^Content/u});
    await expect(editor.locator('h2')).toHaveText('Retained headline');
    await expect(editor.locator('img,script')).toHaveCount(0);
    await expect(page.getByRole('button',{name:'Retry news editor',exact:true})).toHaveCount(0);
    expect(state.errors).toEqual([]);
});

test('tiptap empty and oversize drafts remain editable and never submit partial content', async ({page}) => {
    const state=await content(page);
    const editor=page.getByRole('textbox',{name:/^Content/u});
    await editor.fill('');
    await page.getByRole('button',{name:'Save draft',exact:true}).click();
    await expect(page.getByRole('alert').filter({hasText:'Add some news text'})).toBeVisible();
    await expect(editor).toBeFocused();
    await editor.fill('é'.repeat(31000));
    await page.getByRole('button',{name:'Save draft',exact:true}).click();
    await expect(page.getByRole('alert').filter({hasText:'too long'})).toBeVisible();
    expect(state.calls.some(c=>c.path==='/sis/portal/editor/')).toBe(false);
    await editor.fill('A shorter fictional update.');
    await page.getByRole('button',{name:'Save draft',exact:true}).click();
    expect(state.calls.find(c=>c.path==='/sis/portal/editor/').data.content).toBe('<p>A shorter fictional update.</p>');
    expect(state.errors).toEqual([]);
});

test('a delayed tiptap load cannot restore a closed content editor', async ({page}) => {
    let release, markRequested;
    const pending=new Promise(resolve=>{release=resolve;});
    const requested=new Promise(resolve=>{markRequested=resolve;});
    await page.route('**/assets/vendor/tiptap.js',async route=>{markRequested();await pending;await route.continue();});
    const state=await content(page);
    await requested;
    await page.getByRole('button',{name:'Close record',exact:true}).click();
    const loaded=page.waitForResponse(response=>response.url().endsWith('/assets/vendor/tiptap.js'));
    release();await loaded;
    await expect(page.locator('.campus-news-editor')).toHaveCount(0);
    await expect(page.locator('#record-drawer')).not.toHaveClass(/show/u);
    expect(state.calls.some(c=>c.path==='/sis/portal/editor/')).toBe(false);
    expect(state.errors).toEqual([]);
});
