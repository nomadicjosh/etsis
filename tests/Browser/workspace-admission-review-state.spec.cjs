const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const { recordAction } = require('./support/workspace-actions.cjs');
const id = n => String(n).padStart(26, '0');

for (const withdraw of [true, false]) {
    test(`staff ${withdraw ? 'withdraw an application' : 'return an application to review'} through one reviewed flow`, async ({ page }) => {
        const application = id(1), person = id(2), writes = [], errors = [];
        let status = withdraw ? 'accepted' : 'withdrawn';
        page.on('pageerror', error => errors.push(error.message));
        await page.route('**/sis/**', async route => {
            const req = route.request(), path = new URL(req.url()).pathname;
            const data = req.postData() ? JSON.parse(new URLSearchParams(req.postData()).get('_workspace_json')) : null;
            if (data) writes.push({ path, data });
            let json;
            if (path === '/sis/ui/metrics/') json = { cards: [], intake: [], pipeline: [], queue: [] };
            else if (path.startsWith('/sis/ui/data/')) json = { next_after: null, items: path.includes('/applications/')
                ? [{ id: application, given_name: 'Fictional', family_name: 'Applicant', status }] : [] };
            else if (path.endsWith('/review-state/review/')) json = {
                previous_status: status, status: withdraw ? 'withdrawn' : 'submitted',
                person: { given_name: 'Fictional', family_name: 'Applicant', institutional_id: 'S-EXAMPLE' },
                source: { program_code: 'BA.ART', program_title: 'Fictional Arts', entry_term_code: '26/FA' },
                effective_on: '2026-11-17', review_token: 'fictional-reviewed-change' };
            else if (path.endsWith('/review-state/commit/')) { status = withdraw ? 'withdrawn' : 'submitted'; json = { status }; }
            else if (path === `/sis/applications/${application}/review/`) json = {
                application: { id: { value: application }, status, version: 1 },
                person: { id: { value: person }, givenName: 'Fictional', familyName: 'Applicant', institutionalId: { value: 'S-EXAMPLE' } },
                labels: { program_code: 'BA.ART', program_title: 'Fictional Arts', term_code: '26/FA' } };
            else throw new Error(`Unexpected fictional request: ${path}`);
            await route.fulfill({ json, headers: { 'Cache-Control': 'private, no-store' } });
        });
        await page.goto('/admin/');
        await page.locator('.workspace-nav-link[data-workspace="applications"]').click();
        await page.getByRole('button', { name: 'Fictional Applicant', exact: true }).click();
        await recordAction(page, withdraw ? 'Withdraw application' : 'Return to review');
        await page.getByRole('textbox', { name: 'Reason for this change', exact: true }).fill('Fictional private review reason.');
        await page.getByRole('button', { name: 'Review change', exact: true }).click();
        const confirmName = withdraw ? 'Confirm withdrawal' : 'Confirm return to review';
        await expect(page.getByRole('heading', { name: 'Fictional Applicant', exact: true })).toBeFocused();
        expect(writes.filter(write => write.path.endsWith('/commit/'))).toHaveLength(0);
        await expect(page.locator('#drawer-body')).toContainText('person account and any separate student record remain unchanged');
        await page.getByRole('button', { name: 'Revise change', exact: true }).click();
        await expect(page.getByLabel('Reason for this change')).toHaveValue('Fictional private review reason.');
        await page.getByRole('button', { name: 'Review change', exact: true }).click();
        await page.getByRole('button', { name: confirmName, exact: true }).click();
        await expect(page.getByRole('button', { name: withdraw ? 'Return to review' : 'Accept application', exact: true })).toBeVisible();
        expect(writes.at(-1).data).toEqual({ review_token: 'fictional-reviewed-change' });
        expect(writes.find(write => write.path.endsWith('/review-state/review/')).data).toEqual({ withdraw, reason: 'Fictional private review reason.' });
        await page.setViewportSize({ width: 390, height: 844 });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
        expect(scan.violations.map(item => item.id)).toEqual([]);
        expect(errors).toEqual([]);
    });
}
