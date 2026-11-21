const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => String(n).padStart(26, '0');
async function accessible(page) {
    const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(result.violations.map(item => ({ id: item.id, targets: item.nodes.map(node => node.target) }))).toEqual([]);
}
async function fixture(page, owner = false, { stale = false, delayed = null } = {}) {
    const calls = [], errors = [], person = id(10), user = id(11);
    const endpoint = owner ? '/portal/api/profile-update-requests/' : `/sis/ui/people/${person}/update-requests/`;
    let rows = owner ? [{ id: id(30), area: 'addresses', status: 'requested', version: 0,
        instructions: 'Fictional <script>unsafe()</script> address correction', response: null, resolution: null,
        created_at: '2026-11-20T12:00:00Z' }] : [];
    const contact = { preferred_name: 'Fictional', phone: '001234', address: '', version: 0, mailing_managed: false };
    page.on('pageerror', e => errors.push(e.message));
    await page.route(/\/(?:sis\/|portal\/api\/).*/, async route => {
        const req = route.request(), url = new URL(req.url()), path = url.pathname;
        const raw = req.postData();
        const data = !raw ? null : req.headers()['content-type']?.includes('application/json') ? JSON.parse(raw)
            : JSON.parse(new URLSearchParams(raw).get('_workspace_json'));
        let result = {};
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path.startsWith('/sis/ui/data/')) result = { items: [{ id: person, owner_user_id: user,
            given_name: 'Fictional', family_name: 'Person', institutional_id: 'S-FICTIONAL' }], next_after: null };
        else if (path === `/sis/ui/people/${person}/`) result = { person: { id: { value: person },
            ownerUserId: { value: user }, givenName: 'Fictional', familyName: 'Person', institutionalId: { value: 'S-FICTIONAL' } },
            students: [], employees: [], accounts: [], applications: [] };
        else if (path === '/portal/api/me/') result = { person: { id: person, given_name: 'Fictional', family_name: 'Person',
            institutional_id: 'S-FICTIONAL' }, contact, students: [], programs: [], applications: [], holds: [],
            accounts: [], transcript: [], registrations: [], transcript_requests: [] };
        else if (path.endsWith('/contact/')) result = contact;
        else if (path.endsWith('/addresses/')) result = { addresses: [], version: 0, mailing_managed: false,
            new_ids: Array.from({ length: 10 }, (_, n) => id(100 + n)), today: '2026-11-20',
            purposes: { mailing: 'Mailing' }, countries: { US: 'United States' }, earlier_mailing_address: '', mailing_address: '' };
        else if (path.endsWith('/emergency-contacts/')) result = { contacts: [], version: 0 };
        else if (path.startsWith(endpoint)) {
            if (data) {
                calls.push({ path, data, csrf: req.headers()['x-csrf-token'] });
                if (delayed) await delayed();
                if (stale) return route.fulfill({ status: 409, json: { error: 'This request changed. Refresh requests and review its current status.' } });
                if (path === endpoint) rows.unshift({ id: data.request_id, area: data.area, instructions: data.message,
                    status: 'requested', version: 0, created_at: '2026-11-20T12:00:00Z' });
                else {
                    const row = rows.find(row => path.includes(row.id));
                    row.version++;
                    if (owner) { row.status = 'responded'; row.response = data.message; }
                    else { row.status = data.action === 'close' ? 'closed' : 'cancelled'; row.resolution = data.message; }
                }
                result = { status: 'saved' };
            } else result = { items: rows.filter(row => ['closed', 'cancelled'].includes(row.status) === (url.searchParams.get('finished') === '1')),
                next_after: null, new_id: id(50 + calls.length), areas: {} };
        }
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    if (owner) await page.goto('/portal/student/profile/');
    else {
        await page.goto('/admin/');
        await page.locator('.workspace-nav-link[data-workspace="people"]').click();
        await page.getByLabel('Search this workspace').fill('Fictional');
        await page.getByLabel('Search this workspace').press('Enter');
        await page.getByRole('button', { name: 'Fictional Person', exact: true }).click();
        await page.getByRole('button', { name: 'Contact information', exact: true }).click();
    }
    return { calls, errors, panel: page.getByRole('region', { name: 'Profile update requests', exact: true }) };
}
for (const area of ['contact', 'addresses', 'emergency_contacts']) test(`staff requests and closes ${area} follow-up while contact drafts survive`, async ({ page }) => {
    const f = await fixture(page);
    await page.getByLabel('Phone number', { exact: true }).fill('Draft phone');
    await f.panel.getByRole('button', { name: 'Request a profile update', exact: true }).click();
    await f.panel.getByLabel('Information to update', { exact: true }).selectOption(area);
    await f.panel.getByLabel('Instructions visible to the owner').fill('Fictional <img src=x onerror=unsafe()> next step');
    await f.panel.getByRole('button', { name: 'Send profile update request', exact: true }).click();
    await expect(f.panel.getByRole('article')).toHaveCount(1);
    await expect(f.panel.locator('img, script')).toHaveCount(0);
    await f.panel.getByLabel('Resolution note visible to the owner').fill('Fictional current details reviewed.');
    await f.panel.getByRole('button', { name: 'Save request outcome', exact: true }).click();
    await expect(f.panel).toContainText('No active profile update requests.');
    await f.panel.getByRole('button', { name: 'Finished requests', exact: true }).click();
    await expect(f.panel).toContainText('Closed');
    await expect(page.getByLabel('Phone number', { exact: true })).toHaveValue('Draft phone');
    expect(f.calls.map(call => call.data.area || call.data.action)).toEqual([area, 'close']);
    expect(f.calls.every(call => call.csrf === 'fictional-test-token')).toBe(true);
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    expect(f.errors).toEqual([]);
});
test('owner opens the dated editor, preserves an unsent reply across refresh, and responds without staff authority', async ({ page }) => {
    const f = await fixture(page, true);
    await expect(f.panel.getByRole('article')).toHaveCount(1);
    await expect(f.panel.locator('script')).toHaveCount(0);
    await f.panel.getByLabel('Update for staff', { exact: true }).fill('Fictional reply draft');
    await f.panel.getByRole('button', { name: 'Refresh requests', exact: true }).click();
    await expect(f.panel.getByLabel('Update for staff', { exact: true })).toHaveValue('Fictional reply draft');
    await f.panel.getByRole('button', { name: 'Open dated addresses', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Add address', exact: true })).toBeVisible();
    await f.panel.getByRole('button', { name: 'Send update for review', exact: true }).click();
    await expect(f.panel).toContainText('Ready for staff review');
    await expect(f.panel.getByRole('button', { name: 'Save request outcome', exact: true })).toHaveCount(0);
    expect(f.calls[0].data).toEqual({ version: 0, message: 'Fictional reply draft' });
    expect(f.calls[0].csrf).toBe('fictional-test-token');
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    expect(f.errors).toEqual([]);
});
test('stale owner response leaves the private draft available for a deliberate refresh', async ({ page }) => {
    const f = await fixture(page, true, { stale: true });
    await f.panel.getByLabel('Update for staff', { exact: true }).fill('Fictional stale reply');
    await f.panel.getByRole('button', { name: 'Send update for review', exact: true }).click();
    await expect(page.getByRole('alert')).toContainText('This request changed');
    await expect(f.panel.getByLabel('Update for staff', { exact: true })).toHaveValue('Fictional stale reply');
    await f.panel.getByRole('button', { name: 'Refresh requests', exact: true }).click();
    await expect(f.panel.getByLabel('Update for staff', { exact: true })).toHaveValue('Fictional stale reply');
    expect(f.calls).toHaveLength(1);
    expect(f.errors).toEqual([]);
});
