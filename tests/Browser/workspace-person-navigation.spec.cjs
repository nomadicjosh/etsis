const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => `01K00000000000000000${String(n).padStart(7, '0')}`;

async function fixture(page, { limited = false, empty = false, delayed = false, educationRequest = false } = {}) {
    const calls = [], errors = [];
    let release;
    const pending = new Promise(resolve => { release = resolve; });
    const person = { id: { value: id(1) }, givenName: 'Alex', familyName: 'Example',
        institutionalId: { value: 'S-FICTIONAL' }, ownerUserId: null };
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        calls.push({ path, method: route.request().method() });
        let data = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') data = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/data/people/') data.items = [
            { id: id(1), given_name: 'Alex', family_name: 'Example', institutional_id: 'S-FICTIONAL' },
        ];
        else if (path === `/sis/ui/people/${id(1)}/`) {
            if (delayed) await pending;
            data = { person, students: empty ? [] : [{ id: id(2), active: 1 }] };
            if (!limited) Object.assign(data, {
                applications: [{ id: id(3), status: 'submitted' }, { id: id(4), status: 'returned_to_review' }],
                accounts: [{ id: id(5), currency: 'USD' }, { id: id(6), currency: 'EUR' }],
                employees: [{ id: id(7), job_title: 'Library services <script>fictional</script>', active: 0 }],
            });
        } else if (path === `/sis/students/${id(2)}/record/`) data = {
            id: id(2), person_id: id(1), given_name: 'Alex', family_name: 'Example',
            institutional_id: 'S-FICTIONAL', active: true,
            primary_program: { code: 'ARTS.UG', title: 'Undergraduate Arts' }, entry_term: { code: '26/FA' },
        };
        else if (path === `/sis/employees/${id(7)}/`) data = {
            id: id(7), person_id: id(1), given_name: 'Alex', family_name: 'Example',
            institutional_id: 'S-FICTIONAL', job_title: 'Library services', active: false,
        };
        else if (path.endsWith('/contact/')) data = {
            preferred_name: 'Alex', phone: '', address: '', version: 0, mailing_managed: false,
        };
        else if (path.endsWith('/education/')) data = { entries: [], version: 0 };
        else if (path.endsWith('/update-requests/')) data = {
            items: educationRequest ? [{ id: id(41), area: 'education', status: 'requested',
                instructions: 'Review institutions attended.', created_at: '2026-11-22', version: 0 }] : [],
            next_after: null, new_id: id(40), areas: { contact: 'Contact information' },
        };
        await route.fulfill({ json: data, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto(limited ? '/admin/person-contact-fixture/' : '/admin/');
    await page.locator('.workspace-nav-link[data-workspace="people"]').click();
    const search = page.getByLabel('Search this workspace', { exact: true });
    await search.fill('Alex'); await search.press('Enter');
    await page.getByRole('button', { name: 'Alex Example', exact: true }).click();
    return { calls, errors, release, person };
}

async function accessible(page) {
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(v => ({ id: v.id, targets: v.nodes.map(n => n.target) }))).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
}

test('person capabilities show distinct record cards and explicit workspace actions', async ({ page }) => {
    const f = await fixture(page);
    await page.getByRole('tab', { name: 'Admissions', exact: true }).click();
    const admissions = page.getByRole('tabpanel', { name: 'Admissions', exact: true });
    await expect(admissions).toContainText('2 connected records on this page');
    await expect(admissions.getByRole('article', { name: 'Application 1', exact: true })).toContainText('Submitted');
    await expect(admissions.getByRole('article', { name: 'Application 2', exact: true })).toContainText('Returned to review');
    await expect(admissions.getByRole('button', { name: 'Open application: Application 2', exact: true })).toBeVisible();
    await accessible(page);
    await page.getByRole('tab', { name: 'Accounts', exact: true }).click();
    const accounts = page.getByRole('tabpanel', { name: 'Accounts', exact: true });
    await expect(accounts.getByRole('article', { name: 'Student account 1', exact: true })).toContainText('USD');
    await expect(accounts.getByRole('article', { name: 'Student account 2', exact: true })).toContainText('EUR');
    await page.getByRole('tab', { name: 'Employment', exact: true }).click();
    await expect(page.getByRole('tabpanel', { name: 'Employment', exact: true })).toContainText('Time submissions paused');
    await expect(page.locator('.person-connection-card script')).toHaveCount(0);
    await page.screenshot({ path: '/tmp/etsis-person-connections-desktop.png' });
    await page.setViewportSize({ width: 320, height: 812 });
    await expect.poll(() => page.getByRole('tab', { name: 'Employment', exact: true }).evaluate(el => {
        const r = el.getBoundingClientRect(), bounds = el.parentElement.getBoundingClientRect();
        return r.left >= bounds.left - 1 && r.right <= bounds.right + 1;
    })).toBe(true);
    expect((await page.getByRole('heading', { name: 'Library services <script>fictional</script>', exact: true }).boundingBox()).width).toBeGreaterThan(120);
    expect(await page.getByRole('tabpanel', { name: 'Employment', exact: true }).locator('.status-badge')
        .evaluate(el => el.scrollWidth <= el.clientWidth + 1)).toBe(true);
    await accessible(page);
    await page.screenshot({ path: '/tmp/etsis-person-connections-phone.png' });
    await page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
    await accessible(page);
    expect(f.errors).toEqual([]);
    expect(f.calls.filter(call => call.method !== 'GET' && !call.path.startsWith('/sis/ui/data/'))).toEqual([]);
});

test('person tabs retain keyboard selection, visible focus and a reachable panel at phone width', async ({ page }) => {
    const f = await fixture(page);
    await page.setViewportSize({ width: 320, height: 812 });
    const tabs = page.getByRole('tablist', { name: 'Person record sections', exact: true });
    await tabs.getByRole('tab', { name: 'Overview', exact: true }).focus();
    await page.keyboard.press('End');
    const last = tabs.getByRole('tab').last();
    await expect(last).toBeFocused();
    await expect(last).toHaveAttribute('aria-selected', 'true');
    expect(await last.evaluate(el => {
        const r = el.getBoundingClientRect(), parent = el.parentElement.getBoundingClientRect();
        return r.left >= parent.left - 1 && r.right <= parent.right + 1;
    })).toBe(true);
    await page.keyboard.press('Home');
    await page.keyboard.press('ArrowRight');
    await expect(tabs.getByRole('tab', { name: 'Academics', exact: true })).toBeFocused();
    await page.keyboard.press('Tab');
    await expect(page.getByRole('tabpanel', { name: 'Academics', exact: true })).toBeFocused();
    await accessible(page);
    expect(f.errors).toEqual([]);
});

test('student and employment cards return to the person through fresh reads', async ({ page }) => {
    const f = await fixture(page);
    await page.getByRole('tab', { name: 'Academics', exact: true }).click();
    await page.getByRole('button', { name: 'Open student record: Student record 1', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Student programs', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Back to record', exact: true }).click();
    await expect(page.getByRole('tab', { name: 'Academics', exact: true })).toHaveAttribute('aria-selected', 'true');
    await page.getByRole('tab', { name: 'Employment', exact: true }).click();
    await page.getByRole('button', { name: 'Open employment record: Library services <script>fictional</script>', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Recent time entries', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Back to record', exact: true }).click();
    await expect(page.getByRole('tab', { name: 'Employment', exact: true })).toHaveAttribute('aria-selected', 'true');
    expect(f.calls.filter(call => call.path === `/sis/ui/people/${id(1)}/`)).toHaveLength(3);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});

test('omitted capabilities stay absent and an authorized empty section has an honest empty state', async ({ page }) => {
    const f = await fixture(page, { limited: true, empty: true });
    await expect(page.getByRole('tab', { name: 'Employment', exact: true })).toHaveCount(0);
    await expect(page.getByRole('tab', { name: 'Accounts', exact: true })).toHaveCount(0);
    await expect(page.getByRole('tab', { name: 'Admissions', exact: true })).toHaveCount(0);
    await page.getByRole('tab', { name: 'Academics', exact: true }).click();
    await expect(page.getByRole('tabpanel', { name: 'Academics', exact: true })).toContainText('No connected records.');
    await accessible(page);
    expect(f.calls.some(call => /\/sis\/(?:employees|accounts|applications)\//u.test(call.path))).toBe(false);
    expect(f.errors).toEqual([]);
});

test('returning to a person falls back to Overview when a capability is no longer returned', async ({ page }) => {
    const f = await fixture(page);
    await page.getByRole('tab', { name: 'Employment', exact: true }).click();
    await page.getByRole('button', { name: 'Open employment record: Library services <script>fictional</script>', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Recent time entries', exact: true })).toBeVisible();
    await page.route(`**/sis/ui/people/${id(1)}/`, route => route.fulfill({
        json: { person: f.person }, headers: { 'Cache-Control': 'private, no-store' },
    }));
    await page.getByRole('button', { name: 'Back to record', exact: true }).click();
    await expect(page.getByRole('tab', { name: 'Overview', exact: true })).toHaveAttribute('aria-selected', 'true');
    await expect(page.getByRole('tab', { name: 'Employment', exact: true })).toHaveCount(0);
    await expect(page.locator('#drawer-body')).not.toContainText('Library services');
    expect(f.errors).toEqual([]);
});

test('contact tools have named panels and preserve the contact draft while opening follow-up', async ({ page }) => {
    const f = await fixture(page, { limited: true, educationRequest: true });
    await page.getByRole('button', { name: 'Contact information', exact: true }).click();
    for (const title of ['Contact details', 'Addresses', 'Emergency contacts', 'Profile update requests']) {
        await expect(page.getByRole('region', { name: title, exact: true })).toBeVisible();
    }
    const contact = page.getByRole('region', { name: 'Contact details', exact: true });
    await expect(page.getByRole('region', { name: 'Educational background', exact: true })).toHaveCount(0);
    await contact.getByLabel('Preferred name', { exact: true }).fill('Private unsaved name');
    await page.getByRole('button', { name: 'Refresh requests', exact: true }).click();
    await expect(contact.getByLabel('Preferred name', { exact: true })).toHaveValue('Private unsaved name');
    for (let visit = 0; visit < 2; visit++) {
        await page.getByRole('button', { name: 'Open educational background', exact: true }).click();
        await expect(page.getByRole('region', { name: 'Institutions attended editor', exact: true })).toBeVisible();
        await page.getByRole('button', { name: 'Back to contact information', exact: true }).click();
        await expect(contact.getByLabel('Preferred name', { exact: true })).toHaveValue('Private unsaved name');
        await expect(page.getByRole('button', { name: 'Back to person', exact: true })).toHaveCount(1);
    }
    await page.locator('#record-drawer .offcanvas-body').evaluate(el => { el.scrollTop = 0; });
    await page.screenshot({ path: '/tmp/etsis-contact-panels-desktop.png' });
    await page.setViewportSize({ width: 320, height: 812 });
    await accessible(page);
    await page.screenshot({ path: '/tmp/etsis-contact-panels-phone.png' });
    expect(f.calls.filter(call => call.method !== 'GET' && !call.path.startsWith('/sis/ui/data/'))).toEqual([]);
    expect(f.errors).toEqual([]);
});

test('a delayed person read cannot restore a closed record surface', async ({ page }) => {
    const f = await fixture(page, { delayed: true });
    await expect(page.locator('#drawer-body')).toContainText('Loading person record');
    await page.getByRole('button', { name: 'Close record', exact: true }).click();
    await expect(page.locator('#drawer-body')).toBeEmpty();
    const finished = page.waitForEvent('requestfinished', {
        predicate: request => new URL(request.url()).pathname === `/sis/ui/people/${id(1)}/`,
    });
    f.release(); await finished;
    await page.waitForLoadState('networkidle');
    await expect(page.locator('#drawer-body')).toBeEmpty();
    expect(f.errors).toEqual([]);
});
