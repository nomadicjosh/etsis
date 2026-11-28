'use strict';
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const personFixture = require('./helpers/notification-person.cjs');
const picture = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAEAAAABgCAIAAAAip+O/AAAApklEQVR4nO3PUQkAIBTAwFfGZMa3hCH8OITBAtxm7fN1wwUNaEEDWtCAFjSgBQ1oQQNa0IAWNKAFDWhBA1rQgBY0oAUNaEEDWtCAFjSgBQ1oQQNa0IAWNKAFDWhBA1rQgBY0oAUNaEEDWtCAFjSgBQ1oQQNa0IAWNKAFDWhBA1rQgBY0oAUNaEEDWtCAFjSgBQ1oQQNa0IAWNKAFDWhBA1rQgBY8dgH7RMn/nICJpgAAAABJRU5ErkJggg==', 'base64');
const person = '01K00000000000000000000010';
const id = n => String(n).padStart(26, '0');

async function fixture(page, options = {}) {
    const f = await personFixture(page, { portal: options.portal });
    const state = { version: 0, has: false, institutional: !!options.locked, deny: false, stale: false, calls: [], hold: null };
    const base = options.portal ? '/portal/api/person/photo/' : `/sis/people/${person}/photo/`;
    await page.route('**' + base + '**', async route => {
        const req = route.request(), path = new URL(req.url()).pathname;
        let data = null;
        if (req.postDataBuffer()) {
            const raw = req.postDataBuffer().toString('utf8');
            if ((req.headers()['content-type'] || '').startsWith('multipart/')) {
                const match = raw.match(/name="_workspace_json"\r\n\r\n([\s\S]*?)\r\n--/);
                data = JSON.parse(match[1]);
            } else data = JSON.parse(new URLSearchParams(raw).get('_workspace_json'));
        }
        state.calls.push({ path, data, csrf: req.headers()['x-csrf-token'] });
        if (state.deny) return route.fulfill({ status: 403, json: { error: 'Fictional photo access lost.' } });
        if (path.includes('/content/') || path.includes('/preview/')) return route.fulfill({
            contentType: 'image/png', body: picture, headers: { 'Cache-Control': 'private, no-store' } });
        let result = { version: state.version, has_photo: state.has, institutional: state.institutional,
            source: state.institutional ? 'camera' : 'upload', enabled: true, gravatar_enabled: true,
            can_edit: !options.readonly && (!options.portal || !state.institutional) };
        if (path.endsWith('/review/')) {
            if (state.hold) await state.hold;
            result = { draft_id: id(90), version: state.version, review_token: 'fictional-photo-proof',
                source: data.source, removed: data.source === 'removed', institutional: !options.portal };
        } else if (path.endsWith('/confirm/')) {
            if (state.stale) { state.stale = false; state.version++;
                return route.fulfill({ status: 409, json: { error: 'Fictional current photo changed.' } }); }
            state.version++; state.has = true; state.institutional = !options.portal;
            result = { accepted: true, version: state.version };
        } else if (path.endsWith('/discard/')) result = { discarded: true };
        return route.fulfill({ json: result });
    });
    async function open() {
        if (options.portal) { await page.goto('/portal/student/profile/'); return; }
        await page.locator('.workspace-nav-link[data-workspace="people"]').click();
        await page.getByLabel('Search this workspace').fill('Fictional');
        await page.getByLabel('Search this workspace').press('Enter');
        await page.getByRole('button', { name: 'Fictional Person', exact: true }).click();
        await page.getByRole('button', { name: 'Manage profile photo', exact: true }).click();
    }
    async function choose() {
        await page.getByLabel('Choose a PNG or JPG', { exact: true }).setInputFiles({ name: 'fictional.png', mimeType: 'image/png', buffer: picture });
        await expect.poll(() => page.locator('cropper-selection').evaluate(el => el.width)).toBeGreaterThan(0);
        await page.getByLabel('Reason for photo change', { exact: true }).fill('Fictional reviewed portrait.');
    }
    return { ...f, state, base, open, choose };
}

test('real cropping, reviewed saving and record returns preserve privacy and the institution flag', async ({ page }, testInfo) => {
    const f = await fixture(page); await f.open(); await f.choose();
    await expect.poll(() => page.locator('cropper-image').evaluate(image => image.$getTransform()[0])).toBeGreaterThan(1);
    const initialCrop = await page.locator('cropper-selection').evaluate(selection => ({
        width: selection.width, centerX: selection.x + selection.width / 2,
        canvasCenterX: selection.parentElement.getBoundingClientRect().width / 2,
    }));
    expect(initialCrop.width, JSON.stringify(initialCrop)).toBeGreaterThan(100);
    expect(Math.abs(initialCrop.centerX - initialCrop.canvasCenterX)).toBeLessThan(2);
    expect(await page.locator('cropper-selection').evaluate(async selection => {
        const canvas = await selection.$toCanvas({ width: 16, height: 16 });
        return canvas.getContext('2d').getImageData(8, 8, 1, 1).data[3];
    })).toBe(255);
    await page.getByRole('button', { name: 'Move crop right', exact: true }).click();
    await page.screenshot({ path: testInfo.outputPath('photo-editor-desktop.png'), fullPage: true });
    await page.getByRole('button', { name: 'Review photo change', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Confirm photo change', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Confirm photo change', exact: true }).click();
    await expect(page.getByText('Profile photo updated.', { exact: true })).toBeVisible();
    await expect(page.getByText('Institution-managed photo', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Back to record', exact: true }).click();
    await expect(page.getByRole('button', { name: 'More person actions', exact: true })).toBeVisible();
    expect(f.state.calls.filter(c => c.data && !c.path.endsWith('/discard/')).every(c => c.csrf === 'fictional-test-token')).toBe(true);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});

test('a late review cannot approve an edited photo reason', async ({ page }) => {
    const f = await fixture(page); await f.open(); await f.choose();
    let release; f.state.hold = new Promise(resolve => { release = resolve; });
    await page.getByRole('button', { name: 'Review photo change', exact: true }).click();
    await expect.poll(() => f.state.calls.some(c => c.path.endsWith('/review/'))).toBe(true);
    await page.getByLabel('Reason for photo change', { exact: true }).fill('Fictional revised reason.');
    release(); f.state.hold = null;
    await expect.poll(() => f.state.calls.some(c => c.path.endsWith('/discard/'))).toBe(true);
    await expect(page.getByRole('button', { name: 'Confirm photo change', exact: true })).toHaveCount(0);
    await expect(page.getByLabel('Reason for photo change', { exact: true })).toHaveValue('Fictional revised reason.');
    expect(f.errors).toEqual([]);
});

test('stale confirmation reloads the current version while preserving the crop and reason', async ({ page }) => {
    const f = await fixture(page); await f.open(); await f.choose();
    await page.getByRole('button', { name: 'Review photo change', exact: true }).click();
    f.state.stale = true;
    await page.getByRole('button', { name: 'Confirm photo change', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Confirm photo change', exact: true })).toHaveCount(0);
    await page.getByRole('button', { name: 'Reload current photo', exact: true }).click();
    await expect(page.getByLabel('Reason for photo change', { exact: true })).toHaveValue('Fictional reviewed portrait.');
    await expect(page.locator('cropper-selection')).toBeVisible();
    await page.getByRole('button', { name: 'Review photo change', exact: true }).click();
    await expect.poll(() => f.state.calls.filter(c => c.path.endsWith('/review/')).at(-1).data.version).toBe(1);
    expect(f.errors).toEqual([]);
});

test('owner Gravatar requests require consent and use private local previews', async ({ page }) => {
    const external = []; page.on('request', req => { if (req.url().includes('gravatar.com')) external.push(req.url()); });
    const f = await fixture(page, { portal: true }); await f.open();
    await page.getByRole('button', { name: 'Review my Gravatar', exact: true }).click();
    await expect(page.getByText('Confirm your Gravatar consent before requesting a photo.', { exact: true })).toBeVisible();
    expect(f.state.calls.some(c => c.path.endsWith('/review/'))).toBe(false);
    await page.getByLabel('I agree to send a hash of my current email address to Gravatar to retrieve my photo.', { exact: true }).check();
    await page.getByRole('button', { name: 'Review my Gravatar', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Confirm photo change', exact: true })).toBeVisible();
    expect(f.state.calls.find(c => c.path.endsWith('/review/')).data.consent).toBe(true);
    expect(external).toEqual([]); expect(f.errors).toEqual([]);
});

test('institution-managed owner photos have no upload camera removal or Gravatar controls', async ({ page }) => {
    const f = await fixture(page, { portal: true, locked: true }); f.state.has = true; await f.open();
    await expect(page.getByText('Your institution manages this photo. Contact staff for a correction.', { exact: true })).toBeVisible();
    await expect(page.getByLabel('Choose a PNG or JPG', { exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Use camera', exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Review my Gravatar', exact: true })).toHaveCount(0);
    expect(f.errors).toEqual([]);
});

test('camera capture and leaving the drawer stop every acquired track', async ({ page }) => {
    await page.addInitScript(() => {
        window.photoTracksStopped = 0;
        navigator.mediaDevices.getUserMedia = async () => {
            const canvas = document.createElement('canvas'); canvas.width = 160; canvas.height = 120;
            const context = canvas.getContext('2d'); context.fillStyle = '#2563eb'; context.fillRect(0, 0, 160, 120);
            const stream = canvas.captureStream(5);
            for (const track of stream.getTracks()) { const stop = track.stop.bind(track);
                track.stop = () => { window.photoTracksStopped++; stop(); }; }
            return stream;
        };
    });
    const f = await fixture(page); await f.open();
    await page.getByRole('button', { name: 'Use camera', exact: true }).click();
    await expect.poll(() => page.locator('video').evaluate(el => el.videoWidth)).toBeGreaterThan(0);
    await page.getByRole('button', { name: 'Capture photo', exact: true }).click();
    await expect.poll(() => page.evaluate(() => window.photoTracksStopped)).toBe(1);
    await page.getByRole('button', { name: 'Use camera', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Stop camera', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Back to record', exact: true }).click();
    await expect.poll(() => page.evaluate(() => window.photoTracksStopped)).toBe(2);
    expect(f.errors).toEqual([]);
});

test('the editor reflows at 320px and removes private images after access loss', async ({ page }, testInfo) => {
    const f = await fixture(page); await f.open(); await f.choose();
    const geometry = () => page.locator('cropper-selection').evaluate(selection => {
        const image = selection.parentElement.querySelector('cropper-image').getBoundingClientRect();
        const frame = selection.parentElement.getBoundingClientRect();
        return { x: (selection.x - image.left + frame.left) / image.width,
            y: (selection.y - image.top + frame.top) / image.height, size: selection.width / image.width };
    });
    const before = await geometry();
    await page.setViewportSize({ width: 320, height: 900 });
    await expect.poll(async () => {
        const after = await geometry();
        return Math.max(...['x','y','size'].map(key => Math.abs(after[key] - before[key])));
    }).toBeLessThan(0.01);
    const scan = await new AxeBuilder({ page }).include('#drawer-body').withTags(['wcag2a','wcag2aa','wcag21aa','wcag22aa']).analyze();
    expect(scan.violations.map(v => v.id)).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.screenshot({ path: testInfo.outputPath('photo-editor-phone.png'), fullPage: true });
    f.state.deny = true;
    await page.getByRole('button', { name: 'Reload current photo', exact: true }).click();
    await expect(page.getByText('Photo access is no longer available. Reopen the person or sign in again.', { exact: true })).toBeVisible();
    await expect(page.locator('#drawer-body input[type=file]')).toHaveCount(0);
    await expect(page.locator('cropper-selection')).toHaveCount(0);
    expect(f.errors).toEqual([]);
});

test('photo filename matching queues a background import with CSRF and opens import activity', async ({ page }) => {
    const f = await fixture(page); let staged = null, submitted = false;
    await page.route('**/sis/people/photos/import/review/', async route => {
        staged = route.request().postDataBuffer().toString('utf8');
        await route.fulfill({ json: { ready: true, job_id: id(100), review_token: 'fictional-import-proof', plan: [
            { filename: person + '.png', issue: null, basis: { person_label: 'Fictional Person' } }] } });
    });
    await page.route('**/sis/imports/commit/', async route => { submitted = route.request().headers()['x-csrf-token'] === 'fictional-test-token';
        await route.fulfill({ json: { id: id(100), state: 'queued' } }); });
    await page.route(`**/sis/imports/jobs/${id(100)}/`, route => route.fulfill({ json: {
        id: id(100), target: 'photos', state: 'completed', processed: 1, total: 1, imported: 1, linked: 0, skipped: 0, omitted: 0 } }));
    await page.locator('.workspace-nav-link[data-workspace="imports"]').click();
    await page.getByRole('button', { name: 'Import institution photos', exact: true }).click();
    await page.getByLabel('Photo or ZIP file', { exact: true }).setInputFiles({ name: person + '.png', mimeType: 'image/png', buffer: picture });
    await page.getByLabel('Reason for institution photo import', { exact: true }).fill('Fictional institution import.');
    await page.getByRole('button', { name: 'Review photo matches', exact: true }).click();
    await expect(page.getByText('One photo match is ready for server processing.', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Queue photo import', exact: true }).click();
    await expect(page.getByText('Photo import queued. Processing continues on the server after you leave this screen.', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Open import activity', exact: true }).click();
    await expect(page.locator('#drawer-body').getByRole('button', { name: 'Refresh', exact: true })).toBeVisible();
    expect(staged).toContain('Fictional institution import.'); expect(submitted).toBe(true); expect(f.errors).toEqual([]);
});

test('import activity cancels remaining steps and reports the retained completed count', async ({ page }) => {
    const f = await fixture(page); let state = 'queued', csrf = null;
    const summary = () => ({ id: id(150), target: 'photos', state, processed: 1, total: 2, imported: 1, linked: 0, skipped: 0, omitted: 0 });
    await page.route('**/sis/imports/jobs/', route => route.fulfill({ json: { items: [summary()], next_after: null } }));
    await page.route(`**/sis/imports/jobs/${id(150)}/`, route => route.fulfill({ json: summary() }));
    await page.route(`**/sis/imports/jobs/${id(150)}/cancel/`, async route => {
        csrf = route.request().headers()['x-csrf-token']; state = 'cancelled'; await route.fulfill({ json: summary() });
    });
    await page.locator('.workspace-nav-link[data-workspace="imports"]').click();
    await page.getByRole('button', { name: 'Import activity', exact: true }).click();
    await page.locator('#drawer-body table button').first().click();
    await page.getByRole('button', { name: 'Cancel remaining import steps', exact: true }).click();
    await expect(page.getByText('Remaining import steps were cancelled. Completed records have not been changed.', { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Cancel remaining import steps', exact: true })).toHaveCount(0);
    expect(csrf).toBe('fictional-test-token'); expect(f.errors).toEqual([]);
});

test('the account profile mounts the owner editor with the same private crop and confirmation workflow', async ({ page }) => {
    const f = await fixture(page, { portal: true }); await page.goto('/admin/profile/'); await f.choose();
    await page.getByRole('button', { name: 'Review photo change', exact: true }).click();
    await page.getByRole('button', { name: 'Confirm photo change', exact: true }).click();
    await expect(page.getByText('Profile photo updated.', { exact: true })).toBeVisible();
    await expect(page.getByText('Institution-managed photo', { exact: true })).toHaveCount(0);
    expect(f.state.calls.find(c => c.path.endsWith('/confirm/')).csrf).toBe('fictional-test-token');
    expect(f.errors).toEqual([]);
});
