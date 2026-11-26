const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => String(n).padStart(26, '0');

test('course import reviews matches and exclusions with bounded rows, safe rendering and an identical retry', async ({ page }) => {
    const calls = [], errors = [], title = 'Fictional <img src=x onerror=alert(1)> Algebra';
    let failed = false, reviewCount = 0;
    const base = Array.from({ length: 30 }, (_, i) => ({ row: i + 2, external_id: String(i + 1), code: `SUBJ.${i}`,
        title: `Fictional course ${i}`, credits_milli: 3000, action: 'create', course_id: null,
        matched_course: null, message: 'Create an inactive course draft.' }));
    Object.assign(base[0], { code: 'MATH.101', title, action: 'match', course_id: id(1),
        matched_course: { id: id(1), code: 'MATH.101', title, credits_milli: 3000, active: true }, message: 'Approve the matching course link.' });
    Object.assign(base[1], { external_id: '__proto__', code: 'BIO.101', action: 'conflict', course_id: id(2),
        matched_course: { id: id(2), code: 'BIO.101', title: 'Existing biology', credits_milli: 4000, active: true }, message: 'The existing course has different credits.' });
    Object.assign(base[2], { action: 'skip', course_id: id(3), message: 'Previously imported; current course left unchanged.' });
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname, raw = route.request().postData();
        const body = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : {};
        calls.push({ path, body, method: route.request().method(), csrf: route.request().headers()['x-csrf-token'] });
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        if (path === '/sis/imports/preview/') {
            const choices = Object.fromEntries((body.choices || []).map(choice => [choice.external_id, choice]));
            const plan = Object.keys(body.mapping).length ? base.map(row => ({ ...row, course_number: body.mapping.course_number != null ? '001A' : null, number_mapped: body.mapping.course_number != null,
                action: choices[row.external_id]?.action === 'skip' ? 'omitted' : choices[row.external_id]?.action === 'link' ? 'link' : row.action })) : [];
            const summary = { create: 0, link: 0, skip: 0, omitted: 0, match: 0, conflict: 0 };
            plan.forEach(row => summary[row.action]++);
            const ready = plan.length > 0 && !summary.match && !summary.conflict;
            result = { headers: ['External ID', 'Code', 'Title', 'Credits', 'Number'], samples: [['1', 'MATH.101', title, '3', '001A']],
                row_count: 30, ready, errors: [], fingerprint: 'fictional-source-hash', plan, summary,
                review_token: ready ? `fictional-review-${++reviewCount}` : null, review_minutes: 20 };
        }
        if (path === '/sis/imports/commit/') {
            if (!failed) { failed = true; await route.fulfill({ status: 500, json: { error: 'Fictional temporary interruption.' } }); return; }
            result = { operation_id: id(10), imported: 27, linked: 1, skipped: 1, omitted: 1, total: 30 };
        }
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    const accessible = async () => {
        const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
        expect(result.violations.map(item => ({ id: item.id, nodes: item.nodes.map(node => node.target) }))).toEqual([]);
    };
    await page.goto('/admin/course-import-fixture/');
    await page.locator('.workspace-nav-link[data-workspace="imports"]').click();
    await page.locator('#import-file').setInputFiles({ name: 'fictional.csv', mimeType: 'text/csv',
        buffer: Buffer.from('External ID,Code,Title,Credits,Number\n1,MATH.101,Fictional Algebra,3,001A\n') });
    await page.getByRole('button', { name: 'Match fields', exact: false }).click();
    await page.getByText('Add course details & classifications', { exact: true }).click();
    await page.locator('#map-course_number').selectOption('4');
    await page.getByRole('button', { name: 'Review import', exact: false }).click();
    await expect(page.getByLabel('Review rows', { exact: true })).toHaveValue('unresolved');
    await expect(page.getByRole('table', { name: 'Reviewed course import rows' })).toContainText(title);
    await expect(page.locator('[data-import-panel="3"] img')).toHaveCount(0);
    await expect(page.getByRole('table', { name: 'Reviewed course import rows' })).toContainText('Course number: 001A');
    await expect(page.locator('#import-commit')).toBeDisabled();
    await page.getByRole('button', { name: 'Link course for row 2', exact: true }).click();
    await page.getByRole('button', { name: 'Leave out row 3', exact: true }).click();
    await expect(page.locator('#import-choice-status')).toContainText('Choices changed.');
    await expect(page.locator('#import-commit')).toBeDisabled();
    await accessible();
    await page.getByRole('button', { name: 'Review choices', exact: true }).click();
    for (const [label, count] of [['New drafts', '27'], ['Approved links', '1'], ['Prior imports', '1'], ['Left out', '1']]) {
        await expect(page.locator('#import-plan-summary').getByText(label, { exact: true }).locator('..').locator('dd')).toHaveText(count);
    }
    await expect(page.getByRole('table', { name: 'Reviewed course import rows' }).locator('tbody tr')).toHaveCount(25);
    await page.getByRole('button', { name: 'Next rows', exact: true }).click();
    await expect(page.locator('#import-plan-status')).toHaveText('26–30 of 30 matching rows.');
    await expect(page.getByRole('table', { name: 'Reviewed course import rows' }).locator('tbody tr')).toHaveCount(5);
    await page.getByLabel('Find an import row', { exact: true }).fill('SUBJ.29');
    await expect(page.getByRole('table', { name: 'Reviewed course import rows' }).locator('tbody tr')).toHaveCount(1);
    await page.getByLabel('Find an import row', { exact: true }).fill('');
    await page.setViewportSize({ width: 320, height: 812 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await accessible();
    await page.locator('#import-commit').click();
    await expect(page.locator('#workspace-alert')).toContainText('Fictional temporary interruption.');
    await expect(page.locator('#import-commit')).toBeEnabled();
    await page.locator('#import-commit').click();
    await expect(page.locator('#import-summary')).toContainText('Rows queued for server processing: 30');
    await expect(page.getByRole('link', { name: 'Review course drafts', exact: false })).toBeHidden();
    const commits = calls.filter(call => call.path === '/sis/imports/commit/');
    expect(commits).toHaveLength(2);
    expect(commits[0].body).toEqual(commits[1].body);
    expect(Object.keys(commits[0].body)).toEqual(['review_token']);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(calls.filter(call => call.method === 'POST').every(call => call.csrf === 'fictional-test-token')).toBe(true);
    expect(errors).toEqual([]);
});
