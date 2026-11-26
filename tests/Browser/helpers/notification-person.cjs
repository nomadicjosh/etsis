'use strict';
const { expect } = require('@playwright/test');
const installAudio = require('./notification-audio.cjs');
const person = '01K00000000000000000000010', user = '01K00000000000000000000011';
module.exports = async function notificationPerson(page, options = {}) {
    const errors = [], posts = [];
    let preferences = { sound: false, sound_id: 'tone', keep_visible: false }, revision = 0;
    await installAudio(page, options);
    page.on('pageerror', error => errors.push(error.message));
    await page.route(/\/(?:sis\/|portal\/api\/).*/, async route => {
        const req = route.request(), path = new URL(req.url()).pathname;
        const raw = req.postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        let result = { items: [], cards: [], intake: [], pipeline: [], queue: [] };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path.startsWith('/sis/ui/data/')) result = { items: [{ id: person, owner_user_id: user,
            given_name: 'Fictional', family_name: 'Person', institutional_id: 'S-FICTIONAL' }], next_after: null };
        else if (path === `/sis/ui/people/${person}/`) result = { person: { id: { value: person }, ownerUserId: { value: user },
            givenName: 'Fictional', familyName: 'Person', institutionalId: { value: 'S-FICTIONAL' } },
        students: [], employees: [], accounts: [], applications: [] };
        else if (path === '/portal/api/me/') result = { person: { id: person, given_name: 'Fictional', family_name: 'Person', institutional_id: 'S-FICTIONAL' },
            contact: { preferred_name: '', phone: '', address: '', version: 0 }, students: [], programs: [], applications: [], holds: [], accounts: [], transcript: [], registrations: [], transcript_requests: [] };
        else if (path.endsWith('/notification-preferences/')) {
            if (data) {
                posts.push({ path, data, csrf: req.headers()['x-csrf-token'] });
                if (options.stale) return route.fulfill({ status: 409, json: { error: 'Preferences changed.' } });
                preferences = data.preferences; revision++;
            }
            result = { preferences: path.startsWith('/portal/') && options.other ? { sound: false, sound_id: 'tone', keep_visible: false } : preferences,
                revision, person_id: person, own: !options.other || path.startsWith('/portal/') };
        }
        await route.fulfill({ json: result });
    });
    async function open() {
        if (options.portal) {
            await page.goto('/portal/student/profile/');
            await page.getByRole('button', { name: 'Manage notification preferences', exact: true }).click();
        } else {
            if (await page.locator('.workspace-notification-dialog[open]').count()) await page.keyboard.press('Escape');
            await page.locator('.workspace-nav-link[data-workspace="people"]').click();
            await page.getByLabel('Search this workspace').fill('Fictional');
            await page.getByLabel('Search this workspace').press('Enter');
            await page.getByRole('button', { name: 'Fictional Person', exact: true }).click();
            await page.getByRole('button', { name: 'More person actions', exact: true }).click();
            await page.getByRole('button', { name: 'Notification preferences', exact: true }).click();
        }
        await expect(page.getByLabel('Play notification sounds', { exact: true })).toBeVisible();
    }
    async function save() {
        await page.getByRole('button', { name: 'Save notification preferences', exact: true }).click();
        if (!options.stale) await expect(page.getByText('Notification preferences saved.', { exact: true })).toBeVisible();
    }
    if (!options.portal) await page.goto('/admin/');
    return { errors, posts, open, save, values: () => preferences };
};
