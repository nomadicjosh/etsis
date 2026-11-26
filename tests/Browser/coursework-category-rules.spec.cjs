const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = number => String(number).padStart(26, '0');

async function fixture(page, { reject = false, many = false } = {}) {
    const calls = [], errors = [], section = id(1);
    const assignments = [{ id: id(2), title: 'Fictional exam', possible_hundredths: 10000 },
        { id: id(3), title: 'Fictional quiz', possible_hundredths: 1000 },
        { id: id(4), title: 'Fictional bonus', possible_hundredths: 0, bonus_hundredths: 1000 }];
    if (many) for (let n = 5; n < 60; ++n) assignments.push({ id: id(n), title: `Fictional task ${n}`, possible_hundredths: 1000 });
    let saved = { mode: 'category', weights: {}, categories: [{ title: 'Coursework', weight_hundredths: 10000,
        assignments: assignments.slice(0, 3).map(a => a.id), drop_lowest: 1 }], revision: 'a'.repeat(64),
        assignments, grade_policy: { rounding: 'none', decimal_places: null, cap_at_hundred: false },
        can_manage_grade_policy: false };
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname, raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/data/teaching/') result = { items: [{ id: section, code: 'ART.101.A',
            course_code: 'ART.101', course_title: 'Fictional studio', term_code: '26/FA', capacity: 10 }], next_after: null };
        else if (path.includes('/context/roster/')) result = { items: [], next_after: null, can_grade: true };
        else if (path.endsWith('/gradebook/calculation/')) {
            if (data) {
                calls.push({ data, csrf: route.request().headers()['x-csrf-token'] });
                if (reject) { reject = false; await route.fulfill({ status: 409,
                    json: { error: 'Fictional setup conflict. Review the draft.' } }); return; }
                saved = { ...saved, ...data, revision: 'b'.repeat(64) };
            }
            result = saved;
        }
        await route.fulfill({ json: result });
    });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="teaching"]').click();
    await page.getByRole('button', { name: 'ART.101.A', exact: true }).click();
    await page.getByRole('button', { name: 'Gradebook setup', exact: true }).click();
    return { calls, errors };
}

test('category rules survive search, paging, a rejected save and reopening', async ({ page }) => {
    const f = await fixture(page, { reject: true, many: true });
    const cap = page.getByLabel('Cap category average at 100%: Coursework', { exact: true });
    const protect = page.getByLabel('Protect from automatic drops: Fictional exam', { exact: true });
    const bonusLimit = page.getByLabel('Maximum bonus-only percentage points (optional): Coursework', { exact: true });
    const regularLimit = page.getByLabel('Maximum regular-assignment bonus percentage points (optional): Coursework', { exact: true });
    await bonusLimit.fill('5.25');
    await regularLimit.fill('2.75');
    await cap.check(); await protect.check();
    await expect(page.getByLabel('Protect from automatic drops: Fictional bonus', { exact: true })).toBeDisabled();
    await page.locator('#drawer-body').getByRole('button', { name: 'Next', exact: true }).click();
    await page.locator('#drawer-body').getByRole('button', { name: 'Previous', exact: true }).click();
    await expect(protect).toBeChecked();
    await expect(bonusLimit).toHaveValue('5.25');
    await expect(regularLimit).toHaveValue('2.75');
    await page.getByLabel('Calculate using', { exact: true }).selectOption('points');
    await page.getByLabel('Calculate using', { exact: true }).selectOption('category');
    await expect(regularLimit).toHaveValue('2.75');
    await page.getByLabel('Find assignments', { exact: true }).fill('quiz');
    await page.getByLabel('Find assignments', { exact: true }).fill('');
    await expect(protect).toBeChecked();
    await page.getByRole('button', { name: 'Save setup', exact: true }).click();
    await expect(cap).toBeChecked(); await expect(protect).toBeChecked();
    await page.getByRole('button', { name: 'Save setup', exact: true }).click();
    expect(f.calls).toHaveLength(2);
    expect(f.calls[0].data).toEqual(f.calls[1].data);
    expect(f.calls[1].csrf).toBe('fictional-test-token');
    expect(f.calls[1].data.categories[0]).toMatchObject({ cap_at_hundred: true, protected_assignments: [id(2)], bonus_only_cap_hundredths: 525, regular_bonus_cap_hundredths: 275 });
    await expect(page.getByRole('button', { name: 'Gradebook setup', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Gradebook setup', exact: true }).click();
    await expect(cap).toBeChecked(); await expect(protect).toBeChecked();
    await expect(bonusLimit).toHaveValue('5.25');
    await expect(regularLimit).toHaveValue('2.75');
    expect(f.errors).toEqual([]);
});

test('changing a category clears protection and leaves bonus-only protection unavailable', async ({ page }) => {
    const f = await fixture(page);
    const protect = page.getByLabel('Protect from automatic drops: Fictional exam', { exact: true });
    await protect.check();
    await page.locator(`#gradebook-category-${id(2)}`).selectOption('');
    await expect(protect).not.toBeChecked(); await expect(protect).toBeDisabled();
    await expect(page.locator('#drawer-body')).toContainText('Automatic-drop protection was cleared');
    await page.locator(`#gradebook-category-${id(2)}`).selectOption({ label: 'Coursework' });
    await expect(protect).toBeEnabled(); await expect(protect).not.toBeChecked();
    await page.getByRole('button', { name: 'Save setup', exact: true }).click();
    expect(f.calls[0].data.categories[0]).not.toHaveProperty('protected_assignments');
    expect(f.calls[0].data.categories[0]).not.toHaveProperty('cap_at_hundred');
    expect(f.errors).toEqual([]);
});

test('category controls support keyboard and enlarged phone reflow', async ({ page }) => {
    const f = await fixture(page);
    await page.setViewportSize({ width: 320, height: 1000 });
    await page.addStyleTag({ content: 'html { font-size: 32px; }' });
    const cap = page.getByLabel('Cap category average at 100%: Coursework', { exact: true });
    const bonusLimit = page.getByLabel('Maximum bonus-only percentage points (optional): Coursework', { exact: true });
    const regularLimit = page.getByLabel('Maximum regular-assignment bonus percentage points (optional): Coursework', { exact: true });
    await bonusLimit.fill('5.55');
    await bonusLimit.focus(); await page.keyboard.press('ArrowUp');
    await expect(bonusLimit).toHaveValue('5.56');
    await regularLimit.fill('2.50');
    await regularLimit.focus(); await page.keyboard.press('ArrowUp');
    await expect(regularLimit).toHaveValue('2.51');
    await cap.focus(); await page.keyboard.press('Space'); await expect(cap).toBeChecked();
    const protect = page.getByLabel('Protect from automatic drops: Fictional exam', { exact: true });
    await protect.focus(); await page.keyboard.press('Space'); await expect(protect).toBeChecked();
    expect(await page.locator('#drawer-body').evaluate(el => el.scrollWidth <= el.clientWidth + 1)).toBe(true);
    expect((await new AxeBuilder({ page }).include('#record-drawer').analyze()).violations).toEqual([]);
    await regularLimit.scrollIntoViewIfNeeded();
    await page.screenshot({ path: '/tmp/etsis-regular-bonus-phone.png' });
    expect(await page.evaluate(() => JSON.stringify({ ...localStorage, ...sessionStorage }))).not.toContain('Fictional exam');
    expect(f.errors).toEqual([]);
});

for (const [kind, label, field] of [
    ['bonus-only', 'Maximum bonus-only percentage points (optional): Coursework', 'bonus_only_cap_hundredths'],
    ['regular bonus', 'Maximum regular-assignment bonus percentage points (optional): Coursework', 'regular_bonus_cap_hundredths'],
]) {
test(`a blank ${kind} limit stays absent while zero saves an explicit disabled contribution`, async ({ page }) => {
    const f = await fixture(page);
    const limit = page.getByLabel(label, { exact: true });
    await expect(limit).toHaveValue('');
    await limit.fill('0');
    await page.getByRole('button', { name: 'Save setup', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Gradebook setup', exact: true })).toBeVisible();
    expect(f.calls[0].data.categories[0][field]).toBe(0);
    await page.getByRole('button', { name: 'Gradebook setup', exact: true }).click();
    await expect(limit).toHaveValue('0.00');
    await limit.fill('');
    await page.getByRole('button', { name: 'Save setup', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Gradebook setup', exact: true })).toBeVisible();
    expect(f.calls[1].data.categories[0]).not.toHaveProperty(field);
    expect(f.errors).toEqual([]);
});

test(`invalid ${kind} limits block saving and expose an accessible error without changing the draft`, async ({ page }) => {
    const f = await fixture(page);
    const limit = page.getByLabel(label, { exact: true });
    const save = page.getByRole('button', { name: 'Save setup', exact: true });
    for (const value of ['-1', '100.01', '5.555']) {
        await limit.fill(value);
        await expect(save).toBeDisabled();
        await expect(limit).toHaveAttribute('aria-invalid', 'true');
        await expect(page.getByRole('alert').filter({ hasText: 'Use 0 to 100 percentage points' })).toBeVisible();
    }
    expect(f.calls).toHaveLength(0);
    await limit.fill('5.55');
    await expect(save).toBeEnabled();
    await expect(limit).toHaveAttribute('aria-invalid', 'false');
    expect(f.errors).toEqual([]);
});
}
