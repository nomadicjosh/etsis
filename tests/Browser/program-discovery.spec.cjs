const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const program = '01K00000000000000000000001', version = '01K00000000000000000000020';
test('public program search pages use published cards and retain a direct application selection through auth links', async ({ page }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/portal/api/**', route => {
        const path = new URL(route.request().url()).pathname;
        const person = '01K00000000000000000000003';
        const json = path === '/portal/api/me/' ? { person: { id: person }, contact: {}, applications: [],
            students: [], accounts: [], news: [] }
            : path === '/portal/api/applications/' ? { person_id: person, items: [] }
                : { items: [], entries: [], areas: {}, new_id: version };
        return route.fulfill({ json });
    });
    await page.goto('/portal/programs/?guest=1');
    await expect(page.locator('.campus-program-card')).toHaveCount(12);
    await page.setViewportSize({ width: 320, height: 812 });
    await page.evaluate(() => { document.documentElement.style.fontSize = '200%'; });
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => item.id)).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.evaluate(() => { document.documentElement.style.fontSize = ''; });
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.getByRole('link', { name: 'More programs', exact: true }).click();
    await expect(page.locator('.campus-program-card')).toHaveCount(2);
    await page.getByRole('link', { name: 'Back to first results', exact: true }).click();
    await page.getByLabel('Program code or title', { exact: true }).fill('Computer');
    await page.getByRole('button', { name: 'Find programs', exact: true }).click();
    await expect(page.locator('.campus-program-card')).toHaveCount(1);
    await page.locator('.campus-program-card').click();
    await expect(page.getByRole('heading', { name: 'Computer Science', exact: true })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Majors', exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Back to programs', exact: true })).toHaveAttribute('href', '/portal/programs/?q=Computer');
    await expect(page.locator('.campus-program-description')).toContainText('<script>window.injectedProgram = true</script>');
    expect(await page.evaluate(() => window.injectedProgram)).toBeUndefined();
    await expect(page.getByRole('link', { name: 'Apply to this program', exact: true }))
        .toHaveAttribute('href', `/portal/apply/?program=${program}`);
    await page.getByRole('link', { name: 'Apply to this program', exact: true }).click();
    await expect(page.getByLabel('Choose your academic program', { exact: true })).toHaveValue(program);
    await expect(page.getByRole('link', { name: 'Sign in to apply', exact: true })).toHaveCount(0);
    await page.goto(`/portal/apply/?guest=1&program=${program}`);
    await page.getByLabel('When would you like to begin?', { exact: true }).selectOption('01K00000000000000000000002');
    await page.getByRole('button', { name: 'See your next step', exact: true }).click();
    const signIn = page.getByRole('link', { name: 'Sign in to apply', exact: true });
    await expect(signIn).toHaveAttribute('href', `/login/?continue=apply&program=${program}&term=01K00000000000000000000002`);
    await signIn.click();
    await expect(page.locator('input[name="program"]')).toHaveValue(program);
    await page.getByRole('link', { name: 'Create your campus account', exact: true }).click();
    await expect(page.locator('input[name="program"]')).toHaveValue(program);
    await expect(page.locator('input[name="term"]')).toHaveValue('01K00000000000000000000002');
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(errors).toEqual([]);
});
for (const theme of ['campus-harbor', 'campus-quad', 'campus-horizon']) test(`${theme} program details reflow with literal content and a closed application state`, async ({ page }) => {
    await page.goto(`/portal/programs/${program}/?theme=${theme}&closed=1&guest=1`);
    await expect(page.getByRole('link', { name: 'Apply to this program', exact: true })).toHaveCount(0);
    await expect(page.locator('main')).toContainText('Applications are not currently available');
    await expect(page.getByRole('heading', { name: 'Incompatible curriculum choices', exact: true })).toBeVisible();
    await expect(page.locator('main')).toContainText('English <script>literal</script>');
    const navigation = page.getByRole('navigation', { name: 'Main navigation', exact: true });
    const courses = await navigation.getByRole('link', { name: 'Courses', exact: true }).boundingBox();
    const programs = await navigation.getByRole('link', { name: 'Programs', exact: true }).boundingBox();
    expect(programs.y).toBe(courses.y);
    if (theme === 'campus-harbor') await page.screenshot({ path: '/tmp/etsis-public-program-desktop.png' });
    await page.setViewportSize({ width: 320, height: 812 });
    await page.evaluate(() => { document.documentElement.style.fontSize = '200%'; });
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => ({ id: item.id, targets: item.nodes.map(n => n.target) }))).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    if (theme === 'campus-harbor') await page.screenshot({ path: '/tmp/etsis-public-program-phone.png', fullPage: true });
});
test('website catalog publication previews literal retained text, preserves revision drafts and hides explicitly', async ({ page }) => {
    const calls = [], errors = [];
    let visible = false, revision = 0, stale = true;
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        if (data) calls.push({ path, data });
        let json = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') json = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/data/programs/') json = { items: [{ id: program, code: 'ARTS-UG', title: 'Fictional Arts' }], next_after: null };
        else if (path.endsWith('/website-catalog/')) json = { program: { id: program, code: 'ARTS-UG', title: 'Fictional Arts' }, version: revision, visible, catalog_id: visible ? version : null };
        else if (path.endsWith('/choices/')) json = { items: [{ id: version, year_code: '2026-27', title: 'Fictional published Arts', revision: 1 }], next_after: null };
        else if (path.endsWith('/review/') && stale) {
            stale = false; return route.fulfill({ status: 409, json: { error: 'Fictional catalog source changed. Review again.' } });
        }
        else if (path.endsWith('/review/')) json = { ...data, operation_id: version, revision: 'a'.repeat(64), review_token: 'fictional-website-proof',
            program: { code: 'ARTS-UG', title: 'Fictional published Arts', academic_year_code: '2026-27', description: 'Literal <script>published</script> wording.', components: [] } };
        else if (path.endsWith('/commit/')) {
            expect(route.request().headers()['x-csrf-token']).toBe('fictional-test-token');
            visible = data.visible; revision++;
            json = { program: { id: program, code: 'ARTS-UG', title: 'Fictional Arts' }, version: revision, visible, catalog_id: version };
        }
        await route.fulfill({ json });
    });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="courses"]').click();
    await page.getByRole('link', { name: 'Programs', exact: true }).click();
    await page.getByRole('button', { name: 'Fictional Arts', exact: true }).click();
    await page.getByRole('button', { name: 'Website catalog', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Back to record', exact: true })).toBeVisible();
    await page.getByLabel('Published program definition', { exact: true }).selectOption(version);
    await page.getByLabel('Show this program in the public catalog', { exact: true }).check();
    await page.getByRole('button', { name: 'Review website catalog', exact: true }).click();
    await expect(page.getByLabel('Published program definition', { exact: true })).toHaveValue(version);
    await expect(page.getByLabel('Show this program in the public catalog', { exact: true })).toBeChecked();
    await page.getByRole('button', { name: 'Review website catalog', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Literal <script>published</script> wording.');
    await page.screenshot({ path: '/tmp/etsis-website-program-review.png' });
    await expect(page.locator('#drawer-body script')).toHaveCount(0);
    await page.getByRole('button', { name: 'Revise catalog settings', exact: true }).click();
    await expect(page.getByLabel('Published program definition', { exact: true })).toHaveValue(version);
    await page.getByRole('button', { name: 'Review website catalog', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm catalog publication', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Website catalog updated.');
    await expect(page.getByRole('link', { name: 'View public program', exact: true })).toBeVisible();
    await page.getByLabel('Show this program in the public catalog', { exact: true }).uncheck();
    await page.getByRole('button', { name: 'Review website catalog', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm removal from public catalog', exact: true }).click();
    await expect(page.getByRole('link', { name: 'View public program', exact: true })).toHaveCount(0);
    expect(calls.filter(item => item.path.endsWith('/commit/')).map(item => item.data.visible)).toEqual([true, false]);
    expect(errors).toEqual([]);
});
