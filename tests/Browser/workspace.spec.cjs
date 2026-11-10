const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;

test('connected workspace, form studio, CSV mapping, rules and narrow-screen accessibility', async ({ page }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const program = '01K00000000000000000000001';
    const term = '01K00000000000000000000002';
    const person = '01K00000000000000000000003';
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        let result = {};
        if (path === '/sis/ui/metrics/') result = {
            cards: [
                { label: 'Active students', value: 1250, caption: 'Fictional fixture', icon: 'mortarboard', destination: 'students' },
                { label: 'Applications to review', value: 27, caption: 'Fictional fixture', icon: 'inbox', destination: 'applications' },
                { label: 'Active course sections', value: 86, caption: 'Fictional fixture', icon: 'collection', destination: 'sections' },
                { label: 'Student accounts', value: 1208, caption: 'Fictional fixture', icon: 'wallet2', destination: 'accounts' },
            ],
            intake: [{ label: '2025FA', value: 110 }, { label: '2026SP', value: 140 }, { label: '2026FA', value: 180 }],
            pipeline: [{ label: 'Submitted', value: 27 }, { label: 'Accepted', value: 45 }],
            queue: [{ label: 'Room requests', value: 3, destination: 'bookings' }],
        };
        else if (path.startsWith('/sis/ui/data/')) {
            const dataset = path.split('/')[4];
            result = { items: dataset === 'programs' ? [{ id: program, code: 'BSCS', title: 'Computing' }] : dataset === 'terms' ? [{ id: term, code: '2026FA' }] : dataset === 'people' ? [{ id: person, given_name: 'Alex', family_name: 'Example', institutional_id: 'S-EXAMPLE' }] : [], next_after: null };
        } else if (path === `/sis/ui/people/${person}/`) result = {
            person: { id: { value: person }, givenName: 'Alex', familyName: 'Example', institutionalId: { value: 'S-EXAMPLE' }, ownerUserId: null, identityVersion: 0 },
            students: [], applications: [], accounts: [], employees: [],
        };
        else if (path === '/sis/forms/') result = { items: [] };
        else if (path === '/sis/imports/preview/') {
            result = { headers: ['Source', 'Code', 'Title', 'Credits'], samples: [['example-1', 'CS-101', 'Fictional Computing', '3']], row_count: 1, ready: true, errors: [], fingerprint: 'fictional-fingerprint' };
        } else if (path === '/sis/rules/preview/') result = { items: [], count: 0 };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    const accessible = async () => {
        const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
        expect(scan.violations.map(item => ({ id: item.id, nodes: item.nodes.map(node => node.target) }))).toEqual([]);
    };
    await page.goto('/admin/');
    await expect(page.locator('#metric-grid')).toContainText('1,250');
    await accessible();
    await page.screenshot({ path: 'test-results/workspace-desktop.png' });
    await page.locator('.workspace-nav-link[data-workspace="people"]').click();
    await page.locator('#directory-search').fill('a');
    await page.locator('#directory-search').press('Enter');
    await expect(page.locator('#directory-results')).toContainText('Alex Example');
    await page.getByRole('button', { name: 'Alex Example', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('S-EXAMPLE');
    await accessible();
    await page.getByRole('button', { name: 'Close record', exact: true }).click();
    await page.locator('.workspace-nav-link[data-workspace="forms"]').click();
    await page.locator('#add-field-menu').click();
    await page.getByRole('button', { name: 'Short answer', exact: true }).click();
    await page.getByLabel('Question or field label').fill('Preferred name');
    await page.getByLabel('Stable field key').fill('preferred_name');
    await page.getByRole('button', { name: 'Preview form', exact: true }).click();
    await expect(page.getByLabel('Preferred name')).toBeVisible();
    await accessible();
    await page.getByRole('button', { name: 'Close record', exact: true }).click();
    await page.locator('.workspace-nav-link[data-workspace="imports"]').click();
    await page.getByRole('button', { name: 'Discard and leave' }).click();
    await page.locator('#import-file').setInputFiles({ name: 'example.csv', mimeType: 'text/csv', buffer: Buffer.from('Source,Code,Title,Credits\nexample-1,CS-101,Fictional Computing,3\n') });
    await page.locator('#import-inspect').click();
    await expect(page.locator('#import-mapping')).toContainText('Course code');
    await accessible();
    await page.locator('.workspace-nav-link[data-workspace="rules"]').click();
    await expect(page.locator('#student-rule-builder .rule-container')).toBeVisible();
    await accessible();
    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto('/admin/');
    await expect(page.locator('#metric-grid')).toContainText('1,250');
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await accessible();
    await page.screenshot({ path: 'test-results/workspace-mobile.png' });
    expect(errors).toEqual([]);
});
