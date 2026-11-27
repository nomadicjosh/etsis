'use strict';
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const notificationPerson = require('./helpers/notification-person.cjs');
const term = '01K00000000000000000000002';
const initial = () => ({ id: term, revision: 1, status: 'open', unread: true,
    term_code: 'FALL <b>Fictional</b> ' + 'Long term label '.repeat(8),
    opens_at: '2026-11-27T09:00:00Z', closes_at: '2026-12-20T23:59:59Z',
    recorded_at: '2026-11-27T09:00:00.000000Z' });
const dialog = page => page.locator('dialog.workspace-notification-dialog');
async function setup(page, portal = false) {
    const f = await notificationPerson(page, { portal });
    const state = { item: initial(), deleted: false, mode: 'ok', posts: [], release: null, arrived: false };
    await page.route('**/portal/api/registration-open/**', async route => {
        const request = route.request(), url = new URL(request.url());
        if (state.mode === 'unavailable') return route.fulfill({ status: 503, json: { error: 'PRIVATE upstream failure' } });
        if (request.method() === 'POST') {
            const body = JSON.parse(new URLSearchParams(request.postData()).get('_workspace_json'));
            expect(request.headers()['x-csrf-token']).toBe('fictional-test-token');
            state.posts.push({ path: url.pathname, body });
            if (body.revision !== state.item.revision) return route.fulfill({ status: 409, json: { error: 'PRIVATE source evidence' } });
            if (state.mode === 'delay') { state.arrived = true; await new Promise(resolve => { state.release = resolve; }); }
            if (url.pathname.endsWith('/delete/')) state.deleted = true;
            else state.item.unread = false;
            return route.fulfill({ json: url.pathname.endsWith('/delete/') ? { deleted: true } : { unread: false } }).catch(() => {});
        }
        await route.fulfill({ json: { items: state.deleted || (!url.searchParams.get('include_read') && !state.item.unread)
            ? [] : [{ ...state.item }], next: null }, headers: { 'Cache-Control': 'private, no-store' } });
    });
    const open = async () => {
        if (portal) await page.goto('/portal/student/profile/');
        else if (!page.url().includes('/admin/')) await page.goto('/admin/');
        await page.locator(portal ? '#campus-notifications-open' : '#workspace-notifications-open').click();
        await expect(dialog(page).getByRole('button', { name: 'Refresh notifications', exact: true })).toBeEnabled();
    };
    return { ...f, state, open };
}

for (const portal of [false, true]) {
    test(`${portal ? 'portal' : 'workspace'} shows current opening guidance and deletes only its displayed publication`, async ({ page }) => {
        const f = await setup(page, portal); await f.open();
        const card = dialog(page).locator(`[data-update-id="${term}"]`);
        await expect(card).toContainText('Course registration is open');
        await expect(card).toContainText('Registration window');
        await expect(card).toContainText('Holds, prerequisites, section availability, course limits and fees still apply.');
        await expect(card).toContainText('Registration closes:');
        await expect(card.locator('b')).toHaveCount(0);
        const link = card.getByRole('link', { name: 'Find sections for this term', exact: true });
        await expect(link).toHaveAttribute('href', `/portal/student/registration/?term=${term}`);
        await page.setViewportSize({ width: 320, height: 900 });
        await page.addStyleTag({ content: 'html { font-size: 200%; }' });
        const scan = await new AxeBuilder({ page }).include('.workspace-notification-dialog')
            .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
        expect(scan.violations.map(item => item.id)).toEqual([]);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
        await card.getByRole('button', { name: 'Delete notification', exact: true }).click();
        await expect(card).toHaveCount(0);
        expect(f.state.posts).toEqual([{ path: `/portal/api/registration-open/${term}/delete/`, body: { revision: 1 } }]);
        await dialog(page).getByRole('button', { name: 'Refresh notifications', exact: true }).click();
        await expect(card).toHaveCount(0);
        f.state.item.revision = 2; f.state.deleted = false;
        await dialog(page).getByRole('button', { name: 'Refresh notifications', exact: true }).click();
        await expect(card).toContainText('Unread');
        expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
        expect(f.errors).toEqual([]);
    });

    test(`${portal ? 'portal' : 'workspace'} acknowledges the displayed revision before keyboard navigation to its term`, async ({ page }) => {
        const f = await setup(page, portal); await f.open();
        await page.route('**/portal/student/registration/**', route => route.fulfill({ contentType: 'text/html',
            body: '<main><h1>Fictional registration destination</h1></main>' }));
        await dialog(page).getByRole('link', { name: 'Find sections for this term', exact: true }).press('Enter');
        await expect(page).toHaveURL(new RegExp(`/portal/student/registration/\\?term=${term}$`));
        expect(f.state.posts).toEqual([{ path: `/portal/api/registration-open/${term}/read/`, body: { revision: 1 } }]);
        expect(f.state.item.unread).toBe(false);
        expect(f.errors).toEqual([]);
    });
}

test('a stale action preserves the new opening and offers deliberate refresh without private errors or navigation', async ({ page }) => {
    const f = await setup(page, true); await f.open();
    await expect(dialog(page)).toContainText('Course registration is open');
    f.state.item.revision = 2;
    await dialog(page).getByRole('link', { name: 'Find sections for this term', exact: true }).click();
    await expect(dialog(page)).toContainText('This notification changed or is unavailable.');
    await expect(page).toHaveURL(/\/portal\/student\/profile\/$/);
    await expect(dialog(page)).not.toContainText('PRIVATE');
    await dialog(page).getByRole('button', { name: 'Refresh notifications', exact: true }).click();
    await expect(dialog(page)).toContainText('Course registration is open');
    await dialog(page).getByRole('button', { name: 'Delete notification', exact: true }).click();
    expect(f.state.posts.map(row => row.body)).toEqual([{ revision: 1 }, { revision: 2 }]);
    expect(f.errors).toEqual([]);
});

test('an unavailable source recovers on refresh and a late deletion cannot reopen a closed bell', async ({ page }) => {
    const f = await setup(page, true); f.state.mode = 'unavailable'; await f.open();
    await expect(dialog(page)).toContainText('Some notifications could not load.');
    await expect(dialog(page)).not.toContainText('PRIVATE');
    f.state.mode = 'ok';
    await dialog(page).getByRole('button', { name: 'Refresh notifications', exact: true }).click();
    await expect(dialog(page)).toContainText('Course registration is open');
    f.state.mode = 'delay';
    await dialog(page).getByRole('button', { name: 'Delete notification', exact: true }).click();
    await expect.poll(() => f.state.arrived).toBe(true);
    await page.keyboard.press('Escape'); f.state.release();
    await expect(dialog(page)).toBeHidden();
    expect(f.state.posts).toHaveLength(1);
    expect(f.errors).toEqual([]);
});

test('the registration screen selects only a current configured term from the notification link', async ({ page }) => {
    await notificationPerson(page, { portal: true });
    const terms = [], errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/portal/api/me/', route => route.fulfill({ json: { person: { id: 'fictional-person' },
        students: [{ id: '01K00000000000000000000003', primary_program_id: '01K00000000000000000000001', enrollments: [] }],
        applications: [], accounts: [], contact: {}, news: [] } }));
    await page.route('**/portal/api/courses/**', route => {
        terms.push(new URL(route.request().url()).searchParams.get('term'));
        return route.fulfill({ json: { items: [], next_after: null } });
    });
    await page.goto(`/portal/student/registration/?term=${term}`);
    await expect(page.getByRole('combobox', { name: 'Academic term', exact: true })).toHaveValue(term);
    expect(terms).toEqual([term]);
    await page.goto('/portal/student/registration/?term=unrecognized-value');
    await expect(page.getByRole('combobox', { name: 'Academic term', exact: true })).toHaveValue('');
    expect(terms).toEqual([term, '']);
    expect(errors).toEqual([]);
});
