'use strict';
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => String(n).padStart(26, '0');
async function fixture(page, staff = false) {
    let denied = false, conflict = false;
    const errors = [], calls = [];
    const checkin = {id: id(10), status: 'open', version: 0, held_on: '2026-08-10', due_on: null,
        next_step: 'Fictional exact follow-up <img> wording.', response: null, resolution: null,
        created_at: '2026-08-10T12:00:00Z', responded_at: null, resolved_at: null};
    const notice = () => ({id: id(checkin.status === 'closed' ? 101 : 100), student_id: id(1), pursuit_id: id(2),
        checkin_id: checkin.id, revision: checkin.status === 'closed' ? 2 : 0, status: checkin.status === 'closed' ? 'closed' : 'open',
        program_code: 'ARTS.UG', program_title: 'Fictional arts program', recorded_at: '2026-08-10T12:00:00.000000Z', unread: true});
    let items = [notice()];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', route => route.fulfill({json: {cards: [], intake: [], pipeline: [], queue: [], items: []}}));
    await page.route('**/portal/api/**', async route => {
        const req = route.request(), url = new URL(req.url()), raw = req.postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        calls.push({path: url.pathname, data, csrf: req.headers()['x-csrf-token']});
        let result = {items: [], next: null};
        if (url.pathname.startsWith('/portal/api/advising-updates/')) {
            if (denied) return route.fulfill({status: 403, json: {error: 'Fictional denied source'}});
            if (conflict) return route.fulfill({status: 409, json: {error: 'Fictional changed source'}});
            if (req.method() === 'POST') {
                expect(req.headers()['x-csrf-token']).toBe('fictional-test-token');
                const selected = items.find(item => url.pathname.includes(item.id));
                expect(data).toEqual({revision: selected.revision});
                if (url.pathname.endsWith('/delete/')) { items = []; result = {deleted: true}; }
                else { selected.unread = false; result = {unread: false}; }
            } else result = {items: items.filter(item => item.unread || url.searchParams.get('include_read') === '1'), next: null};
        } else if (url.pathname.endsWith('/checkins/list/')) {
            expect(data.checkin_id).toBe(checkin.id);
            if (denied) return route.fulfill({status: 403, json: {error: 'Fictional denied check-in'}});
            result = {items: [{...checkin}], next_after: null, new_id: id(20), today: '2026-08-10', can_record: false};
        } else if (url.pathname.endsWith('/checkins/act/')) {
            expect(data).toMatchObject({checkin_id: checkin.id, action: 'respond', version: 0});
            checkin.response = data.message; checkin.status = 'responded'; checkin.version = 1;
            checkin.responded_at = '2026-08-10T13:00:00Z';
            result = {id: checkin.id, status: 'responded', version: 1};
        }
        await route.fulfill({json: result, headers: {'Cache-Control': 'private, no-store'}});
    });
    await page.goto(staff ? '/admin/' : '/portal/');
    const trigger = page.getByRole('button', {name: staff ? 'Recent workspace notifications' : 'Recent notifications', exact: true});
    await trigger.click();
    const dialog = page.getByRole('dialog', {name: staff ? 'Recent workspace notifications' : 'Recent notifications', exact: true});
    const disclosure = dialog.locator('summary').filter({hasText: 'Advising updates'});
    const section = disclosure.locator('..');
    return {trigger, dialog, disclosure, section, calls, errors, deny: () => {denied = true;},
        conflict: () => {conflict = true;}, resolve: () => {
            checkin.status = 'closed'; checkin.version = 2; checkin.resolution = 'Fictional advisor outcome.';
            checkin.resolved_at = '2026-08-10T14:00:00Z'; items = [notice()];
        }};
}

for (const staff of [false, true]) test(`${staff ? 'workspace' : 'portal'} opens exact advising follow-up lazily, responds and returns to notices`, async ({page}) => {
    const f = await fixture(page, staff);
    expect(f.calls.filter(call => call.path.includes('advising-updates'))).toEqual([]);
    await f.disclosure.click();
    await expect(f.section).toContainText('New advising follow-up');
    expect(f.calls.some(call => call.path.includes('/checkins/'))).toBe(false);
    await f.section.getByRole('button', {name: 'View check-in', exact: true}).click();
    await expect(f.section).toContainText('Fictional exact follow-up <img> wording.');
    await expect(f.section.locator('img')).toHaveCount(0);
    await expect(f.section.getByRole('button', {name: 'Record a check-in', exact: true})).toHaveCount(0);
    await f.section.getByLabel('Your response', {exact: true}).fill('Fictional student follow-up response.');
    await f.section.getByRole('button', {name: 'Send response', exact: true}).click();
    await expect(f.section).toContainText('Fictional student follow-up response.');
    await f.section.getByRole('button', {name: 'Back to advising updates', exact: true}).click();
    await expect(f.section).toContainText('New advising follow-up');
    await expect(f.section).toContainText('Read');
    await expect(f.section.locator('[data-update-id] button').first()).toBeFocused();
    f.resolve();
    await f.disclosure.click(); await f.disclosure.click();
    await expect(f.section).toContainText('Advising follow-up closed');
    await f.section.getByRole('button', {name: 'View check-in', exact: true}).click();
    await expect(f.section).toContainText('Fictional advisor outcome.');
    await expect(f.section.getByRole('button', {name: 'Send response', exact: true})).toHaveCount(0);
    expect(await page.evaluate(() => ({local: {...localStorage}, session: {...sessionStorage}})))
        .toEqual({local: {}, session: {}});
    expect(f.errors).toEqual([]);
});

test('personal advising deletion persists after reload and creates no replacement toast', async ({page}) => {
    const f = await fixture(page);
    await f.disclosure.click();
    await f.section.getByRole('button', {name: 'Delete update', exact: true}).click();
    await expect(f.section).toContainText('Advising update deleted.');
    await page.keyboard.press('Escape');
    await page.reload();
    await f.trigger.click(); await f.disclosure.click();
    await expect(f.section).toContainText('There are no matching advising updates.');
    await expect(page.locator('.workspace-notification-history li')).toHaveCount(0);
    expect(f.calls.filter(call => call.path.endsWith('/delete/'))[0].data).toEqual({revision: 0});
    expect(f.errors).toEqual([]);
});

test('stale notices preserve the list and denied selected follow-up clears its private contents', async ({page}) => {
    const f = await fixture(page);
    await f.disclosure.click();
    await expect(f.section).toContainText('New advising follow-up');
    f.conflict();
    await f.section.getByRole('button', {name: 'View check-in', exact: true}).click();
    await expect(f.section).toContainText('These notifications are unavailable. Reopen this section to try again.');
    await expect(f.section).toContainText('Fictional arts program');
    await page.reload();
    const fresh = await fixture(page);
    await fresh.disclosure.click();
    await fresh.section.getByRole('button', {name: 'View check-in', exact: true}).click();
    await expect(fresh.section).toContainText('Fictional exact follow-up <img> wording.');
    fresh.deny();
    await fresh.section.getByRole('button', {name: 'Refresh check-ins', exact: true}).click();
    await expect(fresh.dialog).not.toBeVisible();
    await expect(page.locator('body')).not.toContainText('Fictional exact follow-up');
    await expect(page.locator('body')).not.toContainText('Fictional arts program');
    expect(fresh.errors).toEqual([]);
});

test('closing the notification drawer discards a delayed selected-check-in read', async ({page}) => {
    const f = await fixture(page);
    await f.disclosure.click();
    let release;
    const held = new Promise(resolve => {release = resolve;});
    let arrived;
    const waiting = new Promise(resolve => {arrived = resolve;});
    await page.route('**/portal/api/advising-updates/*/read/', async route => {
        arrived(); await held; await route.fulfill({json: {unread: false}});
    });
    await f.section.getByRole('button', {name: 'View check-in', exact: true}).click();
    await waiting;
    await page.keyboard.press('Escape');
    release();
    await f.trigger.click();
    await expect(f.dialog).not.toContainText('Fictional exact follow-up');
    expect(f.calls.some(call => call.path.includes('/checkins/'))).toBe(false);
    expect(f.errors).toEqual([]);
});

test('selected advising follow-up supports keyboard use and 320px enlarged-text accessibility', async ({page}) => {
    const f = await fixture(page);
    await f.disclosure.press('Enter');
    await f.section.getByRole('button', {name: 'View check-in', exact: true}).press('Enter');
    await expect(f.section.getByLabel('Your response', {exact: true})).toBeVisible();
    await page.setViewportSize({width: 320, height: 812});
    await page.evaluate(() => {document.documentElement.style.fontSize = '200%';});
    const scan = await new AxeBuilder({page}).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => ({id: item.id, targets: item.nodes.map(node => node.target)}))).toEqual([]);
    expect(await f.dialog.evaluate(el => el.scrollWidth <= el.clientWidth + 1)).toBe(true);
    await page.screenshot({path: '/tmp/etsis-advising-updates-phone.png'});
    await page.keyboard.press('Escape');
    await expect(f.trigger).toBeFocused();
    expect(f.errors).toEqual([]);
});
