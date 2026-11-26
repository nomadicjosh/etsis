'use strict';
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const fs = require('node:fs/promises');
const fixture = require('./helpers/academic-timetable.cjs');

async function trackBlobs(page) {
    await page.addInitScript(() => {
        window.calendarBlobs = { created: 0, revoked: 0 };
        const create = URL.createObjectURL.bind(URL), revoke = URL.revokeObjectURL.bind(URL);
        URL.createObjectURL = value => { window.calendarBlobs.created++; return create(value); };
        URL.revokeObjectURL = value => { window.calendarBlobs.revoked++; return revoke(value); };
    });
}

for (const theme of ['campus-harbor', 'campus-quad', 'campus-horizon']) {
    test(`${theme}: current private class calendar downloads the whole selected period and releases its blob`, async ({ page }) => {
        await trackBlobs(page);
        const state = await fixture(page, { theme, overlaps: true });
        await page.getByRole('button', { name: 'Month', exact: true }).click();
        await page.getByLabel('Show overlapping meetings only', { exact: true }).check();
        const control = page.getByRole('button', { name: 'Download class calendar (.ics)', exact: true });
        const card = page.getByRole('region', { name: 'Take your class timetable with you', exact: true });
        await expect(card).toContainText('Daily pages and overlap filters do not limit the download.');
        await expect(card).toContainText('private class information');
        const arrival = page.waitForEvent('download');
        await control.press('Enter');
        const file = await arrival;
        expect(file.suggestedFilename()).toBe('my-timetable.ics');
        const contents = await fs.readFile(await file.path(), 'utf8');
        expect(contents).toContain('CLASS:PRIVATE');
        expect(contents).toContain('TRANSP:OPAQUE');
        expect(contents).not.toContain('Never render protected evidence');
        expect(state.calls.filter(call => call.path.endsWith('/export/'))).toEqual([{
            path: '/portal/api/timetable/export/',
            data: { term_id: '00000000000000000000000001', date: '2026-08-01', view: 'month' },
            csrf: 'fictional-test-token',
        }]);
        await expect(card).toContainText('Calendar download started.');
        await expect.poll(() => page.evaluate(() => calendarBlobs.revoked)).toBe(1);
        await expect(control).toBeEnabled();
        await page.setViewportSize({ width: 320, height: 900 });
        await page.addStyleTag({ content: 'html { font-size: 200%; }' });
        await control.scrollIntoViewIfNeeded();
        const scan = await new AxeBuilder({ page }).include('.academic-timetable section[aria-label="Take your class timetable with you"]')
            .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
        expect(scan.violations.map(v => v.id)).toEqual([]);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
        if (theme === 'campus-harbor') await page.screenshot({ path: '/tmp/etsis-private-calendar-phone.png' });
        expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
        expect(state.errors).toEqual([]);
    });
}

test('a changed timetable view discards a late export before creating any downloadable blob', async ({ page }) => {
    await trackBlobs(page);
    const state = await fixture(page);
    state.exportMode = 'delay';
    await page.getByRole('button', { name: 'Download class calendar (.ics)', exact: true }).click();
    await expect.poll(() => state.exportArrive).toBe(true);
    const stopped = page.waitForEvent('requestfailed', request => request.url().endsWith('/timetable/export/'));
    await page.getByLabel('Calendar date', { exact: true }).fill('2026-08-24');
    await stopped; state.exportRelease();
    await page.waitForTimeout(50);
    expect(await page.evaluate(() => calendarBlobs.created)).toBe(0);
    await expect(page.locator('.academic-timetable')).toContainText('Choose View timetable');
    expect(state.errors).toEqual([]);
});

test('a stalled calendar preparation times out and leaves the current view ready to retry', async ({ page }) => {
    await trackBlobs(page);
    const state = await fixture(page);
    await page.clock.install();
    state.exportMode = 'delay';
    const control = page.getByRole('button', { name: 'Download class calendar (.ics)', exact: true });
    await control.click();
    await expect.poll(() => state.exportArrive).toBe(true);
    await page.clock.runFor(15001);
    await expect(page.locator('.academic-timetable')).toContainText('The class calendar could not be prepared.');
    await expect(control).toBeEnabled();
    expect(await page.evaluate(() => calendarBlobs.created)).toBe(0);
    state.exportRelease();
    expect(state.errors).toEqual([]);
});

for (const denied of [false, true]) {
    test(`${denied ? 'access loss clears' : 'failed preparation preserves'} the private timetable without exposing error details`, async ({ page }) => {
        await trackBlobs(page);
        const state = await fixture(page);
        state.exportMode = denied ? 'denied' : 'failed';
        await page.getByRole('button', { name: 'Download class calendar (.ics)', exact: true }).click();
        if (denied) {
            await expect(page.locator('.academic-timetable')).toContainText('Your timetable is unavailable for this account.');
            await expect(page.locator('.academic-timetable')).not.toContainText('MATH.101');
            await expect(page.getByLabel('Academic term', { exact: true })).toHaveCount(0);
        } else {
            await expect(page.locator('.academic-timetable')).toContainText('The class calendar could not be prepared.');
            await expect(page.getByRole('button', { name: 'Download class calendar (.ics)', exact: true })).toBeEnabled();
            await expect(page.getByLabel('Academic term', { exact: true })).toBeVisible();
        }
        await expect(page.locator('body')).not.toContainText('PRIVATE');
        expect(await page.evaluate(() => calendarBlobs.created)).toBe(0);
        expect(state.errors).toEqual([]);
    });
}
