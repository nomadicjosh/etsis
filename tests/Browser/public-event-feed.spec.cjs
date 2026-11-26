'use strict';
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const fs = require('node:fs/promises');

for (const theme of ['campus-harbor', 'campus-quad', 'campus-horizon']) {
    test(`${theme}: public calendar feed is explicit, accessible and separate from visible filters`, async ({ page }) => {
        const requests = [], errors = [];
        page.on('pageerror', error => errors.push(error.message));
        page.on('request', request => requests.push(new URL(request.url()).pathname));
        await page.goto(`/portal/events/?guest=1&theme=${theme}&feed=on`);
        const address = page.getByLabel('Public calendar feed address', { exact: true });
        await expect(address).toHaveValue('http://127.0.0.1:8127/portal/events/calendar.ics');
        await expect(address).toHaveAttribute('readonly', '');
        const card = address.locator('..');
        await expect(card).toContainText('past seven days and next 90 days');
        await expect(card).toContainText('A downloaded copy does not update.');
        expect(requests.some(path => path.endsWith('.ics'))).toBe(false);
        await page.getByLabel('Find an event or location').fill('Keep this search');
        await page.getByRole('button', { name: 'Explore events', exact: true }).click();
        await expect(page.locator('[data-public-events]')).toContainText('0 public event times');
        await expect(address).toHaveValue('http://127.0.0.1:8127/portal/events/calendar.ics');
        await address.focus();
        await page.keyboard.press('ControlOrMeta+A');
        expect(await address.evaluate(node => node.selectionEnd - node.selectionStart)).toBeGreaterThan(20);
        const download = page.waitForEvent('download');
        await page.getByRole('link', { name: 'Download public calendar (.ics)', exact: true }).press('Enter');
        const file = await download;
        expect(file.suggestedFilename()).toBe('campus-events.ics');
        const content = await fs.readFile(await file.path(), 'utf8');
        expect(content).toContain('BEGIN:VCALENDAR\r\n');
        expect(content).toContain('SUMMARY:Fictional public open house');
        expect(content).not.toContain('Keep this search');
        expect(content).not.toContain('PRIVATE');
        await page.setViewportSize({ width: 320, height: 900 });
        await page.addStyleTag({ content: 'html { font-size: 200%; }' });
        await address.scrollIntoViewIfNeeded();
        const scan = await new AxeBuilder({ page }).include('[aria-labelledby="public-event-feed-heading"]')
            .withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
        expect(scan.violations.map(v => v.id)).toEqual([]);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
        if (theme === 'campus-harbor') await page.screenshot({ path: '/tmp/etsis-event-feed-phone.png' });
        expect(requests.filter(path => path.includes('/portal/api/')).every(path => path === '/portal/api/events/')).toBe(true);
        expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
        expect(errors).toEqual([]);
    });
}

test('disabled public sharing hides feed controls and no-script visitors can use the feed address', async ({ browser }) => {
    const context = await browser.newContext({ javaScriptEnabled: false });
    const page = await context.newPage();
    await page.goto('/portal/events/?guest=1');
    await expect(page.getByLabel('Public calendar feed address', { exact: true })).toHaveCount(0);
    await page.goto('/portal/events/?guest=1&feed=on');
    await expect(page.getByLabel('Public calendar feed address', { exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Download public calendar (.ics)', exact: true }))
        .toHaveAttribute('href', 'http://127.0.0.1:8127/portal/events/calendar.ics');
    await context.close();
});
