'use strict';
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const baseFixture = require('./helpers/notification-person.cjs');
const id = n => String(n).padStart(26, '0');
const actor = '01K00000000000000000000011', actorPerson = '01K00000000000000000000010';
async function fixture(page) {
    const base = await baseFixture(page); const posts = [], queries = [];
    let version = 0, assignee = null, pin = null, name = 'Unassigned', deny = false, conflict = false;
    const rows = [{ id: id(100), student_id: id(200), status: 'pending', delivery: 'pickup', requested_at: '2026-11-25T12:00:00Z', version: 0 },
        { id: id(99), student_id: id(201), status: 'ready', delivery: 'electronic', requested_at: '2026-11-24T12:00:00Z', version: 1 }];
    const colleagues = Array.from({ length: 25 }, (_, n) => ({ id: id(300+n), person_id: id(400+n),
        given_name: 'Fictional', family_name: `Registrar ${n+1}`, institutional_id: `STAFF-${n+1}` }));
    await page.route('**/sis/**', async route => {
        const req = route.request(), url = new URL(req.url()), path = url.pathname;
        if (path === '/sis/transcript-worklist/') {
            queries.push(Object.fromEntries(url.searchParams));
            const scope = url.searchParams.get('scope'), status = url.searchParams.get('status');
            const items = rows.filter(row => (scope !== 'mine' || (row.id === id(100) && assignee === actor))
                && (scope !== 'unassigned' || row.id === id(100) && !assignee)
                && (status === 'all' || status === 'active' && ['pending','ready'].includes(row.status) || row.status === status));
            return route.fulfill({ json: { items: items.map(row => ({ ...row, assignee_name: row.id === id(100) ? name : 'Fictional Colleague' })), next_after: null } });
        }
        if (path === '/sis/transcript-worklist/assignees/') {
            const after = url.searchParams.get('after'), search = url.searchParams.get('search') || '';
            const items = search.length < 2 ? [] : colleagues.filter(row => !after || row.id > after);
            return route.fulfill({ json: { items: items.slice(0,20), next_after: items.length > 20 ? items[19].id : null, search_required: search.length < 2 } });
        }
        if (path.endsWith('/assignment/')) {
            if (deny) return route.fulfill({ status: 403, json: { error: 'Fictional denied office' } });
            if (req.method() === 'POST') {
                const data = JSON.parse(new URLSearchParams(req.postData()).get('_workspace_json'));
                posts.push(data); expect(req.headers()['x-csrf-token']).toBe('fictional-test-token');
                if (conflict) { conflict = false; version++; name = 'Fictional Colleague'; return route.fulfill({ status: 409, json: { error: 'Fictional stale assignment' } }); }
                expect(data.version).toBe(version); expect(data.request_version).toBe(0);
                version++; assignee = data.assignee_id; pin = data.assignee_person_id;
                name = assignee === null ? 'Unassigned' : assignee === actor ? 'Fictional Operator'
                    : `${colleagues.find(row => row.id === assignee).given_name} ${colleagues.find(row => row.id === assignee).family_name}`;
            }
            return route.fulfill({ json: { version, request_version: 0, status: 'pending', assignee_id: assignee,
                assignee_person_id: pin, assignee_name: name, actor_id: actor, actor_person_id: actorPerson, can_assign: true } });
        }
        if (path.endsWith('/fulfillment/')) return route.fulfill({ json: { status: 'pending', version: 0, document: null } });
        return route.fallback();
    });
    await page.route('**/portal/api/transcripts/**', route => route.fulfill({ json: { id: id(100), status: 'pending', delivery: 'pickup', recipient: '' } }));
    await page.locator('[data-workspace="transcripts"].workspace-nav-link').click();
    await expect(page.locator('#directory-results')).toContainText('Unassigned');
    const open = async () => { await page.locator('#directory-results button[aria-label^="Open "]').first().click();
        await expect(page.getByRole('heading', { name: 'Work assignment', exact: true })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Save assignment', exact: true })).toBeVisible(); };
    const save = async () => { await page.getByRole('button', { name: 'Save assignment', exact: true }).click(); };
    return { ...base, posts, queries, open, save, deny: () => { deny = true; }, conflict: () => { conflict = true; },
        get: () => ({ version, assignee, pin }) };
}

test('the existing transcript directory filters assigned work and saves an explicit claim and clear', async ({ page }) => {
    const f = await fixture(page); await f.open();
    await page.getByRole('button', { name: 'Assign to me', exact: true }).click(); expect(f.posts).toHaveLength(0);
    await f.save(); await expect(page.getByText('Transcript assignment saved.', { exact: true })).toBeVisible();
    expect(f.posts[0]).toEqual({ version: 0, request_version: 0, assignee_id: actor, assignee_person_id: actorPerson });
    await page.getByRole('button', { name: 'Close record', exact: true }).click();
    await page.getByLabel('Assigned work', { exact: true }).selectOption('mine');
    await expect(page.locator('#directory-results')).toContainText('Fictional Operator');
    expect(f.queries.at(-1).scope).toBe('mine');
    await f.open(); await page.getByRole('button', { name: 'Clear assignment', exact: true }).click();
    await f.save(); await expect(page.getByText('Transcript assignment saved.', { exact: true })).toBeVisible();
    expect(f.get()).toEqual({ version: 2, assignee: null, pin: null });
    expect(f.errors).toEqual([]);
});

test('colleague search pages before explicit assignment and keeps a canonical person pin', async ({ page }) => {
    const f = await fixture(page); await f.open();
    await page.getByLabel('Search eligible colleagues', { exact: true }).fill('Registrar');
    await page.getByRole('button', { name: 'Search colleagues', exact: true }).click();
    await page.getByRole('button', { name: 'More colleagues', exact: true }).click();
    await page.getByRole('button', { name: 'Fictional Registrar 25 · STAFF-25', exact: true }).click();
    expect(f.posts).toHaveLength(0); await f.save();
    await expect(page.getByText('Transcript assignment saved.', { exact: true })).toBeVisible();
    expect(f.get().assignee).toBe(id(324)); expect(f.get().pin).toBe(id(424)); expect(f.errors).toEqual([]);
});

test('stale assignment saves retain the selected colleague until a deliberate context reload', async ({ page }) => {
    const f = await fixture(page); await f.open();
    await page.getByRole('button', { name: 'Assign to me', exact: true }).click(); f.conflict(); await f.save();
    await expect(page.getByText('The request or assignment changed. Reload the assignment before saving; your selection is retained.', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Reload assignment', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Current assignment: Fictional Colleague');
    await expect(page.getByText('You', { exact: true })).toBeVisible();
    await f.save(); await expect(page.getByText('Transcript assignment saved.', { exact: true })).toBeVisible();
    expect(f.posts[1].version).toBe(1); expect(f.get().assignee).toBe(actor); expect(f.errors).toEqual([]);
});

test('late colleague searches cannot restore private choices after returning from the request', async ({ page }) => {
    const f = await fixture(page); await f.open(); let arrive, release;
    const requested = new Promise(resolve => { arrive = resolve; }), gate = new Promise(resolve => { release = resolve; });
    await page.route('**/sis/transcript-worklist/assignees/**', async route => {
        arrive(); await gate; await route.fulfill({ json: { items: [{ id: id(300), person_id: id(400),
            given_name: 'Late private', family_name: 'Colleague', institutional_id: 'PRIVATE' }], next_after: null } });
    });
    await page.getByLabel('Search eligible colleagues', { exact: true }).fill('Registrar');
    await page.getByRole('button', { name: 'Search colleagues', exact: true }).click(); await requested;
    await page.getByRole('button', { name: 'Close record', exact: true }).click(); release();
    await page.waitForTimeout(50); await expect(page.locator('body')).not.toContainText('Late private Colleague');
    expect(f.posts).toHaveLength(0); expect(f.errors).toEqual([]);
});

test('assignment keyboard controls reflow at 320px with enlarged text and clear on access denial', async ({ page }) => {
    const f = await fixture(page); await f.open();
    await page.getByRole('button', { name: 'Assign to me', exact: true }).press('Enter');
    await page.setViewportSize({ width: 320, height: 812 });
    await page.evaluate(() => { document.documentElement.style.fontSize = '200%'; });
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a','wcag2aa','wcag21aa','wcag22aa']).analyze();
    expect(scan.violations.map(v => ({ id: v.id, nodes: v.nodes.map(n => n.target) }))).toEqual([]);
    expect(await page.locator('#record-drawer').evaluate(el => el.scrollWidth <= el.clientWidth + 1)).toBe(true);
    await page.getByRole('button', { name: 'Save assignment', exact: true }).scrollIntoViewIfNeeded();
    await page.screenshot({ path: '/tmp/etsis-transcript-worklist-phone.png' });
    f.deny(); await f.save(); await expect(page.getByLabel('Search eligible colleagues', { exact: true })).toHaveCount(0);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0); expect(f.errors).toEqual([]);
});
