'use strict';
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const fs = require('node:fs/promises');
const timetable = require('./helpers/academic-timetable.cjs');
const id = value => String(value).padStart(26, '0');
const title = 'Take your advising calendar with you';
const action = 'Download advising calendar (.ics)';
const failed = 'The advising calendar could not be prepared. Refresh the calendar and try again.';
const file = 'BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:' + 'b'.repeat(64)
    + '@etsis\r\nDTSTAMP:20260810T170000Z\r\nSEQUENCE:2\r\nDTSTART:20260811T170000Z\r\n'
    + 'DTEND:20260811T173000Z\r\nSUMMARY:Advising appointment\r\nCLASS:PRIVATE\r\n'
    + 'STATUS:CANCELLED\r\nTRANSP:TRANSPARENT\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n';
async function fixture(page, theme = 'campus-harbor') {
    await page.addInitScript(() => {
        window.advisingBlobs = { created: 0, revoked: 0 };
        const create = URL.createObjectURL.bind(URL), revoke = URL.revokeObjectURL.bind(URL);
        URL.createObjectURL = value => { advisingBlobs.created++; return create(value); };
        URL.revokeObjectURL = value => { advisingBlobs.revoked++; return revoke(value); };
    });
    const state = await timetable(page, { theme, empty: true });
    Object.assign(state, { mode: 'success', arrived: false, release: null, exports: [] });
    await page.route('**/portal/api/advising-appointments/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const data = JSON.parse(new URLSearchParams(route.request().postData()).get('_workspace_json'));
        let result;
        if (path.endsWith('/export/')) {
            state.exports.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
            if (['denied', 'stale'].includes(state.mode)) return route.fulfill({ status: state.mode === 'denied' ? 403 : 409,
                json: { error: 'PRIVATE upstream details must not be shown' } });
            if (state.mode === 'delay') { state.arrived = true; await new Promise(resolve => { state.release = resolve; }); }
            result = { calendar: state.mode === 'malformed' ? '<html>PRIVATE</html>' : state.mode === 'oversize'
                ? 'BEGIN:VCALENDAR\r\n' + 'a'.repeat(2097153) + 'END:VCALENDAR\r\n' : file,
                source: 'Never render private source evidence' };
        } else {
            const month = data.view === 'month';
            const start = new Date(`${data.date || '2026-08-10'}T12:00:00Z`);
            start.setUTCDate(month ? 1 : start.getUTCDate() - (start.getUTCDay() + 6) % 7);
            const count = month ? 31 : 7, from = start.toISOString().slice(0, 10);
            const next = new Date(start); next.setUTCDate(next.getUTCDate() + count);
            const previous = new Date(start); previous.setUTCDate(previous.getUTCDate() - count);
            const item = { id: id(11), student_id: id(1), pursuit_id: id(2), host_name: 'Fictional Advisor',
                program_code: 'ARTS-UG', program_title: 'Fictional Arts', status: 'scheduled',
                starts_at: '2026-08-11T10:00:00-07:00', ends_at: '2026-08-11T10:30:00-07:00' };
            result = { from, through: month ? '2026-08-31' : '2026-08-16', previous: previous.toISOString().slice(0, 10),
                next: next.toISOString().slice(0, 10), view: month ? 'month' : 'week', timezone: 'America/Los_Angeles',
                include_cancelled: Boolean(data.include_cancelled), items: [item], days: [{ date: '2026-08-11',
                    items: [{ id: item.id, continues_before: false, continues_after: false }] }] };
        }
        await route.fulfill({ json: result }).catch(() => {});
    });
    await page.getByRole('button', { name: 'Refresh appointment calendar', exact: true }).click();
    await expect(page.getByRole('button', { name: /View appointment · Fictional Advisor/ })).toBeVisible();
    return state;
}
for (const theme of ['campus-harbor', 'campus-quad', 'campus-horizon']) {
    test(`${theme}: owner exports the displayed period with privacy guidance and accessible controls`, async ({ page }) => {
        const state = await fixture(page, theme);
        await page.getByLabel('Appointment calendar view', { exact: true }).selectOption('month');
        await expect(page.getByRole('region', { name: title })).toBeHidden();
        await page.getByLabel('Show cancelled appointments', { exact: true }).check();
        await page.getByRole('button', { name: 'Refresh appointment calendar', exact: true }).click();
        const card = page.getByRole('region', { name: title });
        await expect(card).toContainText('Names, program details and appointment messages are omitted.');
        const control = card.getByRole('button', { name: action, exact: true });
        const arrival = page.waitForEvent('download');
        await control.press('Enter');
        const download = await arrival;
        expect(download.suggestedFilename()).toBe('my-advising-appointments.ics');
        expect(await fs.readFile(await download.path(), 'utf8')).toBe(file);
        expect(state.exports).toEqual([{ path: '/portal/api/advising-appointments/export/',
            data: { date: '2026-08-01', view: 'month', include_cancelled: true }, csrf: 'fictional-test-token' }]);
        await expect(card).toContainText('Calendar download started.');
        await expect.poll(() => page.evaluate(() => advisingBlobs.revoked)).toBe(1);
        await expect(control).toBeEnabled();
        await page.setViewportSize({ width: 320, height: 900 });
        await page.addStyleTag({ content: 'html { font-size: 200%; }' });
        await control.scrollIntoViewIfNeeded();
        const scan = await new AxeBuilder({ page }).include('section[aria-label="Take your advising calendar with you"]')
            .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
        expect(scan.violations.map(value => value.id)).toEqual([]);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
        expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
        expect(state.errors).toEqual([]);
    });
}

test('changing the displayed period aborts a late download and requires a refreshed source', async ({ page }) => {
    const state = await fixture(page);
    state.mode = 'delay';
    await page.getByRole('button', { name: action, exact: true }).click();
    await expect.poll(() => state.arrived).toBe(true);
    const stopped = page.waitForEvent('requestfailed', request => request.url().endsWith('/advising-appointments/export/'));
    await page.getByLabel('Advising date', { exact: true }).fill('2026-08-24');
    await stopped; state.release();
    await expect(page.getByRole('region', { name: title })).toBeHidden();
    expect(await page.evaluate(() => advisingBlobs.created)).toBe(0);
    state.mode = 'success';
    await page.getByRole('button', { name: 'Refresh appointment calendar', exact: true }).click();
    await expect(page.getByRole('button', { name: action, exact: true })).toBeEnabled();
    expect(state.errors).toEqual([]);
});

test('leaving the calendar cannot turn a late response into a download', async ({ page }) => {
    const state = await fixture(page);
    state.mode = 'delay';
    await page.getByRole('button', { name: action, exact: true }).click();
    await expect.poll(() => state.arrived).toBe(true);
    await page.getByRole('region', { name: title }).evaluate(card => card.parentElement.remove());
    state.release();
    await page.waitForTimeout(70);
    expect(await page.evaluate(() => advisingBlobs.created)).toBe(0);
    expect(state.errors).toEqual([]);
});

for (const mode of ['stale', 'denied', 'malformed', 'oversize']) {
    test(`${mode} export clears or preserves the current view safely and permits deliberate recovery`, async ({ page }) => {
        const state = await fixture(page);
        state.mode = mode;
        await page.getByRole('button', { name: action, exact: true }).click();
        if (mode === 'denied') {
            await expect(page.getByRole('region', { name: title })).toBeHidden();
            await expect(page.getByRole('button', { name: /View appointment · Fictional Advisor/ })).toHaveCount(0);
        } else {
            await expect(page.getByRole('region', { name: title })).toContainText(failed);
            await expect(page.getByRole('button', { name: /View appointment · Fictional Advisor/ })).toBeVisible();
            await expect(page.getByRole('button', { name: action, exact: true })).toBeEnabled();
        }
        await expect(page.locator('#campus-service-content')).not.toContainText('PRIVATE');
        expect(await page.evaluate(() => advisingBlobs.created)).toBe(0);
        state.mode = 'success';
        await page.getByRole('button', { name: 'Refresh appointment calendar', exact: true }).click();
        await expect(page.getByRole('button', { name: action, exact: true })).toBeEnabled();
        expect(state.errors).toEqual([]);
    });
}

test('a timed-out preparation releases the control without downloading a partial file', async ({ page }) => {
    const state = await fixture(page);
    await page.clock.install();
    state.mode = 'delay';
    await page.getByRole('button', { name: action, exact: true }).click();
    await expect.poll(() => state.arrived).toBe(true);
    await page.clock.runFor(15001);
    await expect(page.getByRole('region', { name: title })).toContainText(failed);
    await expect(page.getByRole('button', { name: action, exact: true })).toBeEnabled();
    state.release();
    expect(await page.evaluate(() => advisingBlobs.created)).toBe(0);
    expect(state.errors).toEqual([]);
});
