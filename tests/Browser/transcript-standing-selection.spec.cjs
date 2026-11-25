'use strict';
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => String(n).padStart(26, '0');
async function fixture(page, { selected = null, reject = false, saveWait = null } = {}) {
    const definitions = Array.from({ length: 30 }, (_, n) => ({ id: id(100 + n), code: `REF${String(n).padStart(2, '0')}`,
        title: n === 1 ? 'Fictional <b>reference</b>' : `Fictional standing ${n}`, active: n !== 1, version: 1 }));
    let policy = { revision: 0, include_standing: true, standing_definitions: selected,
        checks: [{ id: id(1), label: 'Preserved check', details: 'Retained instructions', stage: 'both' }] };
    const calls = [], errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const url = new URL(route.request().url()), raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        calls.push({ path: url.pathname, query: Object.fromEntries(url.searchParams), data });
        let result = { items: [], next_after: null };
        if (url.pathname === '/sis/transcript-policy/') {
            if (data) {
                if (reject) { reject = false; return route.fulfill({ status: 409, json: { error: 'Reload setup before saving.' } }); }
                if (saveWait) await saveWait(data);
                policy = { revision: policy.revision + 1, checks: data.checks, include_standing: data.include_standing,
                    standing_definitions: data.standing_definitions, standing_presentation: data.standing_presentation };
            }
            result = { ...policy, standing_choices: definitions.filter(item => policy.standing_definitions?.includes(item.id)) };
        } else if (url.pathname === '/sis/transcript-policy/standings/') {
            const term = (url.searchParams.get('search') || '').toLowerCase(), after = url.searchParams.get('after');
            const matches = definitions.filter(item => (!after || item.id > after)
                && `${item.code} ${item.title}`.toLowerCase().includes(term));
            result = { items: matches.slice(0, 20), next_after: matches.length > 20 ? matches[19].id : null };
        } else if (url.pathname === '/sis/ui/metrics/') result = { cards: [], pipeline: [], intake: [], queue: [] };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="transcripts"]').click();
    await page.getByRole('button', { name: 'Transcript setup', exact: true }).click();
    await expect(page.locator('#record-drawer')).toHaveClass(/\bshow\b/);
    return { calls, errors, definitions };
}

test('transcript standing choices page without losing setup drafts and survive a stale save', async ({ page }) => {
    const { calls, errors } = await fixture(page, { reject: true });
    await page.getByText('Preserved check', { exact: true }).click();
    await page.getByLabel('Check title', { exact: true }).fill('Unfinished release check');
    await page.getByLabel('Standings to include', { exact: true }).selectOption('selected');
    const choices = page.getByRole('group', { name: 'Available standing definitions', exact: true });
    await expect(choices.getByRole('checkbox')).toHaveCount(20);
    const first = choices.getByRole('checkbox', { name: /REF00/ });
    await first.focus(); await page.keyboard.press('Space');
    await expect(first).toBeChecked();
    await expect(choices.locator('b')).toHaveCount(0);
    await expect(choices).toContainText('Fictional <b>reference</b>');
    await page.getByRole('button', { name: 'More definitions', exact: true }).click();
    await expect(choices.getByRole('checkbox')).toHaveCount(10);
    await choices.getByRole('checkbox', { name: /REF20/ }).check();
    const picked = page.getByRole('group', { name: 'Selected standing definitions', exact: true });
    await expect(picked).toContainText('REF00');
    await expect(picked).toContainText('REF20');
    await page.getByRole('button', { name: 'Previous definitions', exact: true }).click();
    await expect(choices.getByRole('checkbox', { name: /REF00/ })).toBeChecked();
    await page.getByLabel('Find a standing definition', { exact: true }).fill('No matching definition');
    await page.getByRole('button', { name: 'Find definitions', exact: true }).click();
    await expect(choices.getByRole('checkbox')).toHaveCount(0);
    await expect(picked).toContainText('REF20');
    await expect(page.getByLabel('Check title', { exact: true })).toHaveValue('Unfinished release check');
    await page.getByRole('button', { name: 'Save setup', exact: true }).click();
    await expect(picked).toContainText('REF00');
    await expect(page.getByLabel('Check title', { exact: true })).toHaveValue('Unfinished release check');
    await page.getByRole('button', { name: 'Save setup', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Setup revision 1');
    const saves = calls.filter(call => call.path === '/sis/transcript-policy/' && call.data);
    expect(saves[0].data).toEqual(saves[1].data);
    expect(saves[1].data.standing_definitions).toEqual([id(100), id(120)]);
    expect(saves[1].data.checks[0].label).toBe('Unfinished release check');
    expect(errors).toEqual([]);
});

test('standing selection has a 25-definition bound and preserves picked references across result pages', async ({ page }) => {
    const { calls } = await fixture(page);
    await page.getByLabel('Standings to include', { exact: true }).selectOption('selected');
    const choices = page.getByRole('group', { name: 'Available standing definitions', exact: true });
    await expect(choices.getByRole('checkbox')).toHaveCount(20);
    for (let n = 0; n < 20; n++) await choices.getByRole('checkbox').nth(n).check();
    await page.getByRole('button', { name: 'More definitions', exact: true }).click();
    await expect(choices.getByRole('checkbox')).toHaveCount(10);
    for (let n = 0; n < 5; n++) await choices.getByRole('checkbox').nth(n).check();
    await expect(choices.getByRole('checkbox').nth(5)).toBeDisabled();
    await choices.getByRole('checkbox').nth(0).uncheck();
    await expect(choices.getByRole('checkbox').nth(5)).toBeEnabled();
    await choices.getByRole('checkbox').nth(5).check();
    await page.getByRole('button', { name: 'Save setup', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Setup revision 1');
    expect(calls.filter(call => call.path === '/sis/transcript-policy/' && call.data).at(-1).data.standing_definitions).toHaveLength(25);
});

test('newer setup edits survive an accepted save and the following save uses the accepted revision', async ({ page }) => {
    let begin, release, paused = true;
    const pending = new Promise(resolve => { begin = resolve; }), wait = new Promise(resolve => { release = resolve; });
    const { calls, errors } = await fixture(page, { selected: [id(100)], saveWait: async () => {
        if (paused) { paused = false; begin(); await wait; }
    } });
    await page.getByText('Preserved check', { exact: true }).click();
    await page.getByRole('button', { name: 'Save setup', exact: true }).click();
    await pending;
    await page.getByLabel('Check title', { exact: true }).fill('Newer unsaved instructions');
    await page.getByLabel('Standing periods to include', { exact: true }).selectOption('effective_at_cutoff');
    await page.getByLabel('Print standing effective dates', { exact: true }).uncheck();
    release();
    await expect(page.locator('#drawer-body')).toContainText('Newer edits are still unsaved');
    await expect(page.getByLabel('Check title', { exact: true })).toHaveValue('Newer unsaved instructions');
    await page.getByRole('button', { name: 'Save setup', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Setup revision 2');
    const posts = calls.filter(call => call.path === '/sis/transcript-policy/' && call.data);
    expect(posts[0].data.checks[0].label).toBe('Preserved check');
    expect(posts[1].data.revision).toBe(1);
    expect(posts[1].data.checks[0].label).toBe('Newer unsaved instructions');
    expect(posts[0].data.standing_presentation).toEqual({ periods: 'through_cutoff', show_dates: true });
    expect(posts[1].data.standing_presentation).toEqual({ periods: 'effective_at_cutoff', show_dates: false });
    expect(errors).toEqual([]);
});

test('standing chooser clears setup on authority loss and reflows with enlarged phone text', async ({ page }) => {
    const { errors } = await fixture(page, { selected: [id(100), id(101)] });
    await page.screenshot({ path: '/tmp/etsis-transcript-selection-desktop.png' });
    await page.setViewportSize({ width: 320, height: 812 });
    await page.evaluate(() => { document.documentElement.style.fontSize = '200%'; });
    const axe = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(axe.violations.map(v => ({ id: v.id, targets: v.nodes.map(n => n.target) }))).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.getByRole('group', { name: 'Selected standing definitions', exact: true }).scrollIntoViewIfNeeded();
    await page.screenshot({ path: '/tmp/etsis-transcript-selection-phone.png' });
    await page.route('**/transcript-policy/standings/**', route => route.fulfill({ status: 403, json: { error: 'Access denied.' } }));
    await page.getByRole('button', { name: 'Find definitions', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Transcript setup is no longer available');
    await expect(page.getByLabel('Standings to include', { exact: true })).toHaveCount(0);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(errors).toEqual([]);
});

test('empty selected mode requires an explicit choice and failed lookups preserve the draft', async ({ page }) => {
    const { calls, errors } = await fixture(page);
    await page.getByText('Preserved check', { exact: true }).click();
    await page.getByLabel('Check title', { exact: true }).fill('Pending private setup');
    await page.getByLabel('Standings to include', { exact: true }).selectOption('selected');
    await expect(page.getByRole('group', { name: 'Available standing definitions', exact: true }).getByRole('checkbox')).toHaveCount(20);
    await page.getByRole('button', { name: 'Save setup', exact: true }).click();
    expect(calls.some(call => call.path === '/sis/transcript-policy/' && call.data)).toBe(false);
    await expect(page.locator('#drawer-body')).toContainText('Choose at least one definition or include all standings.');
    await page.getByRole('checkbox', { name: /REF00/ }).check();
    await page.route('**/transcript-policy/standings/**', route => route.fulfill({ status: 503, json: { error: 'Try again later.' } }));
    await page.getByRole('button', { name: 'Find definitions', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Your choices are kept');
    await expect(page.getByRole('group', { name: 'Selected standing definitions', exact: true })).toContainText('REF00');
    await expect(page.getByLabel('Check title', { exact: true })).toHaveValue('Pending private setup');
    await page.getByRole('button', { name: 'Save setup', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Setup revision 1');
    expect(errors).toEqual([]);
});

test('closing a pending standing lookup discards its late result', async ({ page }) => {
    const { errors } = await fixture(page);
    let begin, release;
    const pending = new Promise(resolve => { begin = resolve; }), wait = new Promise(resolve => { release = resolve; });
    await page.route('**/transcript-policy/standings/**', async route => {
        begin(); await wait;
        await route.fulfill({ json: { items: [{ id: id(500), code: 'LATE', title: 'Late reference', active: true }], next_after: null } });
    });
    await page.getByLabel('Standings to include', { exact: true }).selectOption('selected');
    await pending;
    await page.getByRole('button', { name: 'Close record', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Close record', exact: true })).toBeHidden();
    const response = page.waitForResponse(response => response.url().includes('/transcript-policy/standings/'));
    release(); await response;
    await expect(page.getByRole('button', { name: 'Close record', exact: true })).toBeHidden();
    await expect(page.getByLabel('Standings to include', { exact: true })).toHaveCount(0);
    expect(errors).toEqual([]);
});

test('temporarily disabling standing preserves the selected definitions for a later re-enable', async ({ page }) => {
    const { calls, errors } = await fixture(page, { selected: [id(100)] });
    await page.getByLabel('Include recorded academic standing', { exact: true }).uncheck();
    await page.getByRole('button', { name: 'Save setup', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Setup revision 1');
    await expect(page.getByLabel('Standings to include', { exact: true })).toBeHidden();
    await page.getByLabel('Include recorded academic standing', { exact: true }).check();
    await expect(page.getByLabel('Standings to include', { exact: true })).toHaveValue('selected');
    await expect(page.getByRole('group', { name: 'Selected standing definitions', exact: true })).toContainText('REF00');
    await page.getByRole('button', { name: 'Save setup', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Setup revision 2');
    const posts = calls.filter(call => call.path === '/sis/transcript-policy/' && call.data);
    expect(posts.map(post => post.data.include_standing)).toEqual([false, true]);
    expect(posts.map(post => post.data.standing_definitions)).toEqual([[id(100)], [id(100)]]);
    expect(errors).toEqual([]);
});


test('period and date choices survive stale saves, definition lookup and temporarily disabling standing', async ({ page }) => {
    const { calls, errors } = await fixture(page, { reject: true });
    await page.getByLabel('Standing periods to include', { exact: true }).selectOption('effective_at_cutoff');
    await page.getByLabel('Print standing effective dates', { exact: true }).uncheck();
    await page.getByLabel('Standings to include', { exact: true }).selectOption('selected');
    const choices = page.getByRole('group', { name: 'Available standing definitions', exact: true });
    await choices.getByRole('checkbox', { name: /REF00/ }).check();
    await page.getByRole('button', { name: 'More definitions', exact: true }).click();
    await expect(choices.getByRole('checkbox')).toHaveCount(10);
    await page.getByRole('button', { name: 'Save setup', exact: true }).click();
    await expect(page.getByLabel('Standing periods to include', { exact: true })).toHaveValue('effective_at_cutoff');
    await expect(page.getByLabel('Print standing effective dates', { exact: true })).not.toBeChecked();
    await page.getByRole('button', { name: 'Save setup', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Setup revision 1');
    await page.getByLabel('Include recorded academic standing', { exact: true }).uncheck();
    await expect(page.getByLabel('Standing periods to include', { exact: true })).not.toBeVisible();
    await page.getByRole('button', { name: 'Save setup', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Setup revision 2');
    await page.getByLabel('Include recorded academic standing', { exact: true }).check();
    await expect(page.getByLabel('Standing periods to include', { exact: true })).toHaveValue('effective_at_cutoff');
    await expect(page.getByLabel('Print standing effective dates', { exact: true })).not.toBeChecked();
    expect(calls.filter(c => c.path === '/sis/transcript-policy/' && c.data).every(c =>
        c.data.standing_presentation.periods === 'effective_at_cutoff' && !c.data.standing_presentation.show_dates)).toBe(true);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(errors).toEqual([]);
});

test('standing presentation controls have keyboard access and reflow at 320px and enlarged text', async ({ page }) => {
    const { errors } = await fixture(page);
    await page.setViewportSize({ width: 320, height: 900 });
    const dates = page.getByLabel('Print standing effective dates', { exact: true });
    await dates.scrollIntoViewIfNeeded();
    await dates.focus();
    await expect(dates).toBeFocused();
    await dates.press('Space');
    await expect(dates).not.toBeChecked();
    await page.getByLabel('Standing periods to include', { exact: true }).selectOption('effective_at_cutoff');
    for (const size of ['100%', '200%']) {
        await page.evaluate(size => { document.documentElement.style.fontSize = size; }, size);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
        expect(await page.locator('#drawer-body').evaluate(el => el.scrollWidth <= el.clientWidth + 1)).toBe(true);
        const scan = await new AxeBuilder({ page }).include('#record-drawer')
            .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
        expect(scan.violations.map(v => v.id)).toEqual([]);
    }
    await dates.scrollIntoViewIfNeeded();
    await page.screenshot({ path: '/tmp/etsis-transcript-standing-presentation-phone.png' });
    expect(errors).toEqual([]);
});
