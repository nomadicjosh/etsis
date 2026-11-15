const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => String(n).padStart(26, '0');
async function accessible(page) {
    const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(result.violations.map(item => ({ id: item.id, targets: item.nodes.map(node => node.target) }))).toEqual([]);
}
for (const [portal, conflict] of [[false, false], [true, false], [true, true]]) {
    test(`${portal ? 'owner' : 'staff'} edits dated addresses without losing contact drafts${conflict ? ' during another contact edit' : ''}`, async ({ page }) => {
        const person = id(10), user = id(11), events = [], errors = [];
        let contact = { preferred_name: 'Fictional', phone: '001234', address: 'Earlier saved mailing text', version: 4, mailing_managed: false };
        let book = { addresses: [], version: 0, mailing_managed: false };
        const endpoint = portal ? '/portal/api/addresses/' : `/sis/ui/people/${person}/addresses/`;
        const metadata = () => ({ ...book, new_ids: Array.from({ length: 10 }, (_, i) => id(100 + i + book.version * 10)),
            contact_revision: { preferred_name: contact.preferred_name, phone: contact.phone, version: contact.version },
            today: '2026-08-10', purposes: { mailing: 'Mailing', home: 'Home', billing: 'Billing', campus: 'Campus', other: 'Other' },
            countries: { US: 'United States', DO: 'Dominican Republic' }, earlier_mailing_address: book.mailing_managed ? '' : contact.address,
            mailing_address: book.mailing_managed ? 'Fictional new home\n00123\nUnited States' : contact.address });
        page.on('pageerror', e => errors.push(e.message));
        await page.route(/\/(?:sis\/|portal\/api\/).*/, async route => {
            const req = route.request(), path = new URL(req.url()).pathname;
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
            else if (path.endsWith('/contact/')) {
                if (data) {
                    events.push({ path, data });
                    if (data.version !== contact.version) return route.fulfill({ status: 409, json: { error: 'This contact record changed. Reload before saving.' } });
                    contact = { ...contact, ...data, version: contact.version + 1 };
                }
                result = data ? { status: 'saved' } : contact;
            } else if (path === endpoint) {
                if (data) {
                    expect(req.headers()['x-csrf-token']).toBe('fictional-test-token');
                    events.push({ path, data });
                    if (data.addresses.length > 1 && !data.addresses[0].valid_until) {
                        return route.fulfill({ status: 422, json: { error: 'Mailing address dates overlap. End the earlier address before the next starts.' } });
                    }
                    if (!book.mailing_managed && contact.address) contact.version++;
                    book = { addresses: data.addresses, version: book.version + 1, mailing_managed: true };
                    contact.address = metadata().mailing_address; contact.mailing_managed = true;
                    if (conflict) { contact.phone = '009999'; contact.version++; }
                }
                result = data ? { status: 'saved' } : metadata();
            }
            await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
        });
        if (portal) await page.goto('/portal/student/profile/');
        else {
            await page.goto('/admin/');
            await page.locator('.workspace-nav-link[data-workspace="people"]').click();
            await page.getByLabel('Search this workspace').fill('Fictional');
            await page.getByLabel('Search this workspace').press('Enter');
            await page.getByRole('button', { name: 'Fictional Person', exact: true }).click();
            await page.getByRole('button', { name: 'Contact information', exact: true }).click();
        }
        const preferred = page.getByLabel(portal ? 'What would you like us to call you?' : 'Preferred name', { exact: true });
        await preferred.fill('Unsaved preferred name');
        await page.getByRole('button', { name: 'Manage addresses', exact: true }).click();
        await expect(page.getByText('Earlier saved mailing text', { exact: true })).toBeVisible();
        await page.getByRole('button', { name: 'Add address', exact: true }).click();
        let card = page.locator('[data-address-list] fieldset').first();
        await card.getByLabel('Street or postal address', { exact: true }).fill('Fictional new home');
        await card.getByLabel('Postal code', { exact: true }).fill('00123');
        await card.getByLabel('Country', { exact: true }).selectOption('US');
        await page.getByRole('button', { name: 'Add address', exact: true }).click();
        const future = page.locator('[data-address-list] fieldset').nth(1);
        await future.getByLabel('Street or postal address', { exact: true }).fill('Fictional future home');
        await future.getByLabel('Country', { exact: true }).selectOption('DO');
        await future.getByLabel('From date', { exact: true }).fill('2026-09-01');
        await page.getByRole('button', { name: 'Save addresses', exact: true }).click();
        await expect(page.getByText(/Mailing address dates overlap/).first()).toBeVisible();
        await expect(card.getByLabel('Postal code', { exact: true })).toHaveValue('00123');
        await card.getByLabel('Through date', { exact: true }).fill('2026-08-31');
        await accessible(page);
        await page.getByRole('button', { name: 'Save addresses', exact: true }).click();
        await expect(page.getByLabel('Mailing address', { exact: true })).toHaveAttribute('readonly', '');
        await expect(page.getByLabel('Mailing address', { exact: true })).toHaveValue(metadata().mailing_address);
        await expect(preferred).toHaveValue('Unsaved preferred name');
        const posted = events.filter(e => e.path === endpoint);
        expect(posted[1].data.addresses[0]).toMatchObject({ id: id(100), postal_code: '00123', valid_until: '2026-08-31' });
        expect(posted[1].data.addresses[1].country_code).toBe('DO');
        expect(posted[1].data.version).toBe(0);
        await page.getByRole('button', { name: portal ? 'Save personal information' : 'Save contact information', exact: true }).click();
        await expect.poll(() => events.filter(e => e.path.endsWith('/contact/')).length).toBe(1);
        expect(events.find(e => e.path.endsWith('/contact/')).data).toMatchObject({ preferred_name: 'Unsaved preferred name',
            address: metadata().mailing_address, version: conflict ? 4 : 5 });
        if (conflict) {
            await expect(page.getByText('This contact record changed. Reload before saving.', { exact: true }).first()).toBeVisible();
            await expect(preferred).toHaveValue('Unsaved preferred name');
        }
        await page.setViewportSize({ width: 375, height: 812 });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        await accessible(page);
        expect(errors).toEqual([]);
        expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    });
}
