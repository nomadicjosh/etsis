const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => `01K000000000000000000000${String(n).padStart(2, '0')}`;

async function fixture(page, path = '/admin/') {
    const errors = [], requests = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        requests.push({ path, method: route.request().method() });
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = {
            cards: [
                { label: 'Active students', value: 1250, caption: 'Current student records', icon: 'mortarboard', destination: 'students' },
                { label: 'Applications to review', value: 27, caption: 'Ready for the admissions office', icon: 'inbox', destination: 'applications' },
                { label: 'Active sections', value: 86, caption: 'Teaching and learning this term', icon: 'collection', destination: 'sections' },
                { label: 'Student accounts', value: 1208, caption: 'Connected billing records', icon: 'wallet2', destination: 'accounts' },
            ],
            intake: [{ label: '2025 Fall', value: 110 }, { label: '2026 Spring', value: 140 }, { label: '2026 Fall', value: 180 }],
            pipeline: [{ label: 'Submitted', value: 27 }, { label: 'Accepted', value: 45 }, { label: 'Matriculated', value: 32 }],
            queue: [{ label: 'Space requests', value: 3, destination: 'bookings' }],
        };
        else if (path.startsWith('/sis/ui/data/')) {
            const dataset = path.split('/')[4];
            const records = {
                courses: [{ id: id(1), code: 'MATH.101', title: 'College Algebra', credits_milli: 3000, active: 1 }],
                people: [{ id: id(2), given_name: 'Alex', family_name: 'Example', institutional_id: 'S-FICTIONAL' }],
                accounts: [{ id: id(3), given_name: 'Alex', family_name: 'Example', institutional_id: 'S-FICTIONAL', currency: 'USD' }],
                programs: [{ id: id(4), code: 'BA.ENG', title: 'English' }],
                terms: [{ id: id(5), code: '26/FA' }],
            };
            result = { items: records[dataset] || [], next_after: null };
        } else if (path === `/sis/accounts/${id(3)}/`) result = {
            id: id(3), student_id: id(2), term_id: id(5), institutional_id: 'S-FICTIONAL', currency: 'USD', balance_minor: 2500, position: 3,
            entries: [
                { operation_id: id(6), type: 'charge', minor: 10000, currency: 'USD', reversed: false, can_credit_charge: true, remaining_charge_minor: 10000, credited_minor: 0, linked_credit_count: 0 },
                { operation_id: id(7), type: 'payment', minor: 7500, currency: 'USD', reversed: false, can_correct_amount: true, can_correct_payment_details: true, linked_refund_count: 0, linked_refund_minor: 0, remaining_refundable_minor: 7500 },
            ],
        };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto(path);
    await expect(page.locator('#metric-grid')).toContainText('1,250');
    return { errors, requests };
}
async function accessible(page) {
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(v => ({ id: v.id, targets: v.nodes.map(n => n.target) }))).toEqual([]);
}

test('the compact account menu supports keyboard navigation and preserves private sign-out', async ({ page }) => {
    const f = await fixture(page);
    const toggle = page.getByRole('button', { name: 'Account menu', exact: true });
    await toggle.focus(); await page.keyboard.press('ArrowDown');
    const menu = page.locator('#account-menu');
    await expect(toggle).toHaveAttribute('aria-expanded', 'true');
    await expect(menu.getByRole('link', { name: 'Profile', exact: true })).toBeFocused();
    await page.keyboard.press('ArrowDown');
    await expect(menu.getByRole('link', { name: 'Timetable', exact: true })).toBeFocused();
    await expect(menu.getByRole('link', { name: 'Bookings', exact: true })).toHaveAttribute('href', '/portal/bookings/');
    await expect(menu.locator('form')).toHaveAttribute('method', 'post');
    await expect(menu.locator('form')).toHaveAttribute('action', '/logout/');
    await expect(menu.locator('input[name="csrf_token"]')).toHaveValue('fictional-test-token');
    await expect(menu.locator('.bi[aria-hidden="true"]')).toHaveCount(6);
    await accessible(page);
    await page.screenshot({ path: 'test-results/workspace-polished-account.png' });
    await page.keyboard.press('Escape');
    await expect(toggle).toBeFocused();
    await expect(toggle).toHaveAttribute('aria-expanded', 'false');
    expect(f.errors).toEqual([]);
});

test('catalog navigation and grouped tools remain distinct with keyboard and return focus', async ({ page }) => {
    const f = await fixture(page);
    await page.locator('.workspace-nav-link[data-workspace="courses"]').click();
    await expect(page.locator('#directory-tabs > button')).toHaveCount(0);
    await expect(page.locator('#directory-tools > .workspace-action-menu')).toHaveCount(3);
    const toggle = page.locator('#directory-tools').getByRole('button', { name: 'Curriculum', exact: true });
    await toggle.focus(); await page.keyboard.press('ArrowDown');
    await expect(page.getByRole('button', { name: 'Course group library', exact: true })).toBeFocused();
    await page.keyboard.press('ArrowDown'); await page.keyboard.press('ArrowDown');
    await expect(page.getByRole('button', { name: 'Subjects', exact: true })).toBeFocused();
    await accessible(page);
    await page.screenshot({ path: 'test-results/workspace-polished-catalog.png' });
    await page.keyboard.press('Enter');
    await expect(page.locator('#drawer-title')).toHaveText('Course subjects');
    await page.getByRole('button', { name: 'Close record', exact: true }).click();
    await expect(toggle).toBeFocused();
    await page.locator('#directory-tabs [data-workspace="programs"]').click();
    await expect(page.locator('.workspace-nav-link[data-workspace="courses"]')).toHaveAttribute('aria-current', 'page');
    await expect(page.locator('#topbar-parent')).toContainText('Academic catalog');
    expect(f.errors).toEqual([]);
});

test('row action menus stay in the viewport of a horizontally scrolled account table', async ({ page }) => {
    const f = await fixture(page);
    await page.locator('.workspace-nav-link[data-workspace="accounts"]').click();
    await page.getByRole('button', { name: 'Alex Example', exact: true }).click();
    await page.setViewportSize({ width: 375, height: 850 });
    const toggle = page.getByRole('button', { name: `More actions for entry ${id(7).slice(-8)}`, exact: true });
    await toggle.click();
    const menu = page.locator(`#${await toggle.getAttribute('aria-controls')}`);
    await expect(menu.getByRole('button', { name: 'Correct receipt details', exact: true })).toBeVisible();
    const box = await menu.boundingBox();
    expect(box.x).toBeGreaterThanOrEqual(0);
    expect(box.x + box.width).toBeLessThanOrEqual(375);
    await accessible(page);
    await page.keyboard.press('Escape');
    await expect(toggle).toBeFocused();
    await page.screenshot({ path: 'test-results/workspace-polished-record.png' });
    expect(f.requests.filter(r => r.method !== 'GET' && !r.path.startsWith('/sis/ui/data/'))).toEqual([]);
    expect(f.errors).toEqual([]);
});

test('phone navigation and 200 percent text scaling keep essential controls reachable', async ({ page }) => {
    const f = await fixture(page);
    await page.setViewportSize({ width: 320, height: 812 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.getByRole('button', { name: 'Open navigation', exact: true }).click();
    const navigation = page.locator('#workspace-navigation');
    const box = await navigation.boundingBox();
    expect(box.width).toBeLessThanOrEqual(320);
    await navigation.getByRole('link', { name: 'Academic catalog', exact: true }).click();
    await expect(page.locator('#directory-heading')).toHaveText('Academic catalog');
    await expect(navigation).not.toHaveClass(/show/);
    await page.locator('#directory-tools').getByRole('button', { name: 'Academic calendar', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Academic years', exact: true })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await accessible(page);
    await page.screenshot({ path: 'test-results/workspace-polished-mobile.png' });
    await page.keyboard.press('Escape');
    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.addStyleTag({ content: 'html { font-size: 200% !important; }' });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await accessible(page);
    expect(f.errors).toEqual([]);
});

test('read-only finance access does not offer ledger navigation or hidden posting actions', async ({ page }) => {
    await fixture(page, '/admin/account-read-fixture/');
    await page.locator('.workspace-nav-link[data-workspace="accounts"]').click();
    await expect(page.locator('#directory-tabs [data-workspace="journals"]')).toHaveCount(0);
    await expect(page.locator('#directory-tabs [data-workspace="ledger"]')).toHaveCount(0);
    await page.getByRole('button', { name: 'Alex Example', exact: true }).click();
    await expect(page.locator('#drawer-body [data-workspace-menu]')).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Post a transaction', exact: true, includeHidden: true })).toHaveCount(0);
});

test('finishing a directory request does not take keyboard focus away from its tools', async ({ page }) => {
    await fixture(page);
    let finish;
    const pending = new Promise(resolve => { finish = resolve; });
    await page.route('**/sis/ui/data/courses/', async route => {
        await pending;
        await route.fulfill({ json: { items: [], next_after: null } });
    });
    await page.locator('.workspace-nav-link[data-workspace="courses"]').click();
    const toggle = page.locator('#directory-tools').getByRole('button', { name: 'Academic calendar', exact: true });
    await toggle.focus(); await page.keyboard.press('ArrowDown');
    const years = page.getByRole('button', { name: 'Academic years', exact: true });
    await expect(years).toBeFocused();
    finish();
    await expect(page.locator('#directory-results')).toContainText('No records yet');
    await expect(years).toBeFocused();
});
