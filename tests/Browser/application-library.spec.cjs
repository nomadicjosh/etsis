const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => `01K${String(n).padStart(23, '0')}`;
const level = id(1000), program = id(1001);
async function fixture(page, options = {}) {
    const requests = [], errors = [];
    const forms = Array.from({ length: 47 }, (_, index) => ({ id: id(index + 1),
        name: index === 0 ? 'Needle% application' : index === 4 ? 'Recoverable form' : `Fictional form ${index + 1}`,
        academic_level_id: level, program_id: null, program: 'Professional diploma',
        status: (index + 1) % 3 === 0 ? 'published' : 'draft', version: 0,
        enabled_id: [3, 45].includes(index + 1) ? id(index + 1) : null })).reverse();
    let reject = options.reject === true;
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const req = route.request(), url = new URL(req.url()), path = url.pathname;
        requests.push({ path, query: Object.fromEntries(url.searchParams) });
        let json = {};
        if (path === '/sis/ui/metrics/') json = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path.startsWith('/sis/ui/data/')) json = { items: [{ id: program, code: 'DIP.ART', title: 'Studio Arts' }], next_after: null };
        else if (path === '/sis/forms/academic-levels/') json = { items: [{ id: level, code: 'DIP', title: 'Professional diploma' }] };
        else if (path === '/sis/forms/') {
            const search = url.searchParams.get('search') || '', state = url.searchParams.get('state') || 'all', after = url.searchParams.get('after');
            if (search === 'slow' && options.slow) await options.slow;
            if (search === 'Recoverable' && reject) { reject = false; return route.fulfill({ status: 503, json: { error: 'Please try the form search again.' } }); }
            const matching = forms.filter(form => form.name.toLowerCase().includes(search.toLowerCase())
                && (state === 'all' || (state === 'enabled' ? form.enabled_id : form.status === state)) && (!after || form.id < after));
            json = { items: matching.slice(0, 20), next_after: matching.length > 20 ? matching[19].id : null };
        } else if (/^\/sis\/forms\/[^/]+\/$/.test(path)) {
            const record = forms.find(form => form.id === path.split('/')[3]);
            json = { ...record, fields: [{ key: 'question', label: 'Question', type: 'text', options: [], help: '', required: false, show_when: null }], layout: [] };
        }
        await route.fulfill({ json, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="forms"]').click();
    await expect(page.locator('#form-library-status')).toContainText('20 forms shown');
    return { requests, errors };
}
async function add(page, label) {
    await page.locator('#add-field-menu').click(); await page.locator('[data-add-field="text"]').first().click();
    await page.getByLabel('Question or field label').last().fill(label);
}
async function accessible(page) {
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => ({ id: item.id, nodes: item.nodes.map(node => node.target) }))).toEqual([]);
}

test('form search, statuses and paging preserve an unsaved draft and avoid repeating reference requests', async ({ page }) => {
    const { requests, errors } = await fixture(page);
    expect(await page.locator('#form-library-list').evaluate(list => list.scrollHeight > list.clientHeight)).toBe(true);
    await page.screenshot({ path: 'test-results/application-library-desktop.png', fullPage: true });
    await page.locator('#form-name').fill('Unsaved admission form'); await page.locator('#form-level').selectOption(level);
    await add(page, 'Unsaved question');
    const input = await page.getByLabel('Question or field label').elementHandle();
    await page.getByRole('button', { name: 'Next', exact: true }).click();
    await expect(page.locator('#form-library-status')).toContainText('Page 2');
    await expect(page.locator('.form-library-item')).toHaveCount(20);
    await page.getByRole('button', { name: 'Next', exact: true }).click();
    await expect(page.locator('#form-library-status')).toContainText('7 forms shown');
    await page.getByRole('button', { name: 'Previous', exact: true }).click();
    await expect(page.locator('#form-library-status')).toContainText('Page 2');
    await page.getByLabel('Find a form', { exact: true }).fill('Needle%');
    await expect(page.getByRole('button', { name: 'Next', exact: true })).toBeDisabled();
    await page.getByRole('button', { name: 'Find', exact: true }).click();
    await expect(page.locator('.form-library-item')).toHaveCount(1);
    await expect(page.locator('.form-library-item')).toContainText('Needle% application');
    expect(requests.filter(item => item.path === '/sis/forms/').at(-1).query).toEqual({ search: 'Needle%' });
    await page.getByRole('button', { name: 'Clear', exact: true }).click();
    await page.getByLabel('Show', { exact: true }).selectOption('enabled');
    await expect(page.locator('.form-library-item')).toHaveCount(2);
    expect(requests.filter(item => item.path === '/sis/forms/').at(-1).query).toEqual({ state: 'enabled' });
    expect(requests.filter(item => item.path === '/sis/ui/data/programs/')).toHaveLength(1);
    expect(requests.filter(item => item.path === '/sis/forms/academic-levels/')).toHaveLength(1);
    await expect(page.locator('#form-name')).toHaveValue('Unsaved admission form');
    expect(await input.inputValue()).toBe('Unsaved question');
    expect(await input.evaluate(control => control.isConnected)).toBe(true);
    await expect(page.getByRole('button', { name: 'Discard and leave' })).toBeHidden();
    await page.getByRole('button', { name: 'Hide library', exact: true }).click();
    await expect(page.locator('#form-library-panel')).toBeHidden();
    await expect(page.locator('#form-studio-layout')).toHaveClass(/library-hidden/);
    await accessible(page);
    await page.screenshot({ path: 'test-results/form-studio-focused.png', fullPage: true });
    expect(errors).toEqual([]);
});

test('late and failed library requests preserve current results and editable draft values', async ({ page }) => {
    let release;
    const slow = new Promise(resolve => { release = resolve; });
    const { errors } = await fixture(page, { slow, reject: true });
    await page.locator('#form-name').fill('Keep this draft');
    await page.getByLabel('Find a form', { exact: true }).fill('slow');
    await page.getByRole('button', { name: 'Find', exact: true }).click();
    await page.getByRole('button', { name: 'Clear', exact: true }).click();
    await expect(page.locator('#form-library-status')).toContainText('20 forms shown');
    release();
    await expect(page.locator('.form-library-item')).toHaveCount(20);
    await page.getByLabel('Find a form', { exact: true }).fill('Recoverable');
    await page.getByRole('button', { name: 'Find', exact: true }).click();
    await expect(page.locator('#form-library-status')).toContainText('Earlier results are still shown');
    await expect(page.locator('.form-library-item')).toHaveCount(20);
    await expect(page.locator('#form-name')).toHaveValue('Keep this draft');
    await page.getByRole('button', { name: 'Find', exact: true }).click();
    await expect(page.locator('.form-library-item')).toHaveCount(1);
    await expect(page.locator('.form-library-item')).toContainText('Recoverable');
    expect(errors).toEqual([]);
});

test('typing during a pending search clearly leaves the newer search unapplied', async ({ page }) => {
    let release;
    const slow = new Promise(resolve => { release = resolve; });
    await fixture(page, { slow });
    await page.getByLabel('Find a form', { exact: true }).fill('slow');
    await page.getByRole('button', { name: 'Find', exact: true }).click();
    await page.getByLabel('Find a form', { exact: true }).fill('Needle%');
    release();
    await expect(page.locator('#form-library-status')).toContainText('Search changed. Select Find');
    await expect(page.getByLabel('Find a form', { exact: true })).toHaveValue('Needle%');
    await page.getByRole('button', { name: 'Find', exact: true }).click();
    await expect(page.locator('.form-library-item')).toHaveCount(1);
    await expect(page.locator('.form-library-item')).toContainText('Needle% application');
});

test('visible field icons support keyboard use and mobile library selection focuses the editor', async ({ page }) => {
    const { errors } = await fixture(page);
    await add(page, 'First question'); await add(page, 'Second question');
    const duplicate = page.getByRole('button', { name: 'Duplicate field 1', exact: true });
    await expect(duplicate.locator('.bi-copy')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Remove field 1', exact: true }).locator('.bi-trash3')).toBeVisible();
    await expect(page.locator('#designer-fields .workspace-action-menu')).toHaveCount(0);
    await page.getByRole('button', { name: 'Move field down: First question', exact: true }).focus();
    await page.keyboard.press('Tab');
    await expect(duplicate).toBeFocused(); await duplicate.press('Enter');
    await expect(page.getByLabel('Question or field label').nth(1)).toHaveValue('First question copy');
    await expect(page.getByLabel('Question or field label').nth(1)).toBeFocused();
    await page.getByRole('button', { name: 'Remove field 2', exact: true }).focus();
    await page.keyboard.press('Enter');
    await expect(page.locator('.form-field-drag').nth(1)).toBeFocused();
    await expect(page.locator('.designer-field')).toHaveCount(2);
    await accessible(page);
    await page.setViewportSize({ width: 320, height: 800 });
    await page.reload();
    await page.getByRole('button', { name: 'Open navigation', exact: true }).click();
    await page.locator('.workspace-nav-link[data-workspace="forms"]').click();
    await expect(page.getByRole('button', { name: 'Show library', exact: true })).toHaveAttribute('aria-expanded', 'false');
    await page.getByRole('button', { name: 'Show library', exact: true }).click();
    await expect(page.locator('#form-library-panel')).toBeVisible();
    await page.locator('.form-library-item').first().click();
    await expect(page.locator('#form-name')).toHaveValue('Fictional form 47');
    await expect(page.locator('#form-name')).toBeFocused();
    await expect(page.locator('#form-library-panel')).toBeHidden();
    await page.getByRole('button', { name: 'Collapse field 1', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Duplicate field 1', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Remove field 1', exact: true })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await accessible(page);
    await page.screenshot({ path: 'test-results/form-library-mobile.png', fullPage: true });
    expect(errors).toEqual([]);
});
