"use strict";
const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const path = require('node:path');
const id = n => String(n).padStart(26, '0');

test('catalog staff retain equivalent-course drafts across search, conflict and publication review', async ({ page }) => {
    const target = {id: id(1), code: 'SCI.201', title: 'Fictional advanced science', active: true, credits_milli: 3000};
    const a = {id: id(2), code: 'SCI.101', title: 'Fictional science', active: true};
    const c = {id: id(3), code: 'SCI.110', title: 'Alternative <img> science', active: true};
    let equivalents = null, revision = 'a'.repeat(64), stale = true;
    const saves = [], errors = [];
    page.on('pageerror', error => errors.push(error.message));
    const definition = () => ({code: target.code, title: target.title, credits_milli: 3000,
        prerequisites: [a.id], prerequisite_groups: [[a.id]], prerequisite_group_minimums: [1],
        prerequisite_grade_points_milli: [3000], prerequisite_equivalents: equivalents,
        transcript_title: null, description: null, subject: null,
        classifications: {department: null, academic_level: null, course_level: null}});
    await page.route('**/sis/**', async route => {
        const url = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const body = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        let result = {items: [], next_after: null};
        if (url === '/sis/ui/metrics/') result = {cards: [], intake: [], pipeline: [], queue: []};
        else if (url === '/sis/ui/data/courses/') result = {items: body?.search ? [c] : [target, a, c], next_after: null};
        else if (url.endsWith('/prerequisite-groups/')) {
            if (body) {
                saves.push({body, csrf: route.request().headers()['x-csrf-token']});
                if (stale) { stale = false; return route.fulfill({status: 409, json: {error: 'Reload the prerequisite editor before saving your changes.'}}); }
                equivalents = body.equivalents; revision = 'b'.repeat(64);
            }
            result = {course: target, groups: [[a.id]], minimums: [1], grade_points_milli: [body?.grade_points_milli?.[0] ?? 3000],
                corequisites: [], completion_windows: null, equivalents, courses: [a, c], revision};
        } else if (url.endsWith('/publication/page/')) result = {items: [], next_cursor: null};
        else if (url.endsWith('/publication/review/')) result = {expected_version: 0, definition: definition(), reason: body.reason, review_token: 'fictional-equivalent-proof'};
        else if (url === '/sis/prerequisite-labels/') result = {items: [a, c]};
        await route.fulfill({json: result, headers: {'Cache-Control': 'private, no-store'}});
    });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="courses"]').click();
    await page.getByRole('button', {name: target.title, exact: true}).click();
    await page.getByRole('button', {name: 'Edit prerequisites', exact: true}).click();
    await page.getByLabel('Minimum grade points', {exact: true}).fill('3.5');
    await page.getByRole('button', {name: 'Equivalent courses for SCI.101', exact: true}).click();
    await expect(page.getByText('No equivalent courses selected.', {exact: true})).toBeVisible();
    await page.getByRole('button', {name: 'Add equivalent course', exact: true}).click();
    await expect(page.getByRole('button', {name: 'Choose SCI.101', exact: true})).toHaveCount(0);
    await page.getByLabel('Find a course', {exact: true}).fill('SCI.110');
    await page.getByRole('button', {name: 'Search courses', exact: true}).click();
    await page.getByRole('button', {name: 'Choose SCI.110', exact: true}).click();
    await expect(page.getByText('SCI.110 · Alternative <img> science', {exact: true})).toBeVisible();
    await expect(page.locator('#record-drawer img')).toHaveCount(0);
    await page.setViewportSize({width: 320, height: 800});
    await page.addStyleTag({content: 'html {font-size:200% !important;}'});
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    const scan = await new AxeBuilder({page}).include('#record-drawer').withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => item.id)).toEqual([]);
    await page.screenshot({path: '/tmp/etsis-prerequisite-equivalents-phone.png', fullPage: true});
    await page.getByRole('button', {name: 'Back to prerequisites', exact: true}).click();
    await expect(page.getByRole('button', {name: 'Equivalent courses for SCI.101', exact: true})).toBeFocused();
    await expect(page.getByLabel('Minimum grade points', {exact: true})).toHaveValue('3.5');
    await page.getByRole('button', {name: 'Save requirements', exact: true}).click();
    await expect(page.getByRole('alert').filter({hasText: 'Reload the prerequisite editor'})).toBeVisible();
    await expect(page.getByLabel('Minimum grade points', {exact: true})).toHaveValue('3.5');
    await page.getByRole('button', {name: 'Save requirements', exact: true}).click();
    expect(saves.at(-1).body.equivalents).toEqual({[a.id]: [c.id]});
    expect(saves.at(-1).body.grade_points_milli).toEqual([3500]);
    expect(saves.at(-1).csrf).toBeTruthy();
    await page.getByRole('button', {name: 'Review course publication', exact: true}).click();
    await page.getByLabel('Reason for publication', {exact: true}).fill('Fictional equivalent course approval.');
    await page.getByRole('button', {name: 'Review current definition', exact: true}).click();
    await page.locator('details summary').filter({hasText: 'Complete one course'}).click();
    await expect(page.locator('#record-drawer')).toContainText('Equivalent courses: SCI.110');
    expect(errors).toEqual([]);
});

test('clone review prevents dropping an equivalent from a retained prerequisite', async ({page}) => {
    const course = {id: id(1), code: 'SCI.201', title: 'Fictional clone source', credits_milli: 3000, active: true};
    const named = {id: id(2), code: 'SCI.101', title: 'Named prerequisite', active: true};
    const alternative = {id: id(3), code: 'SCI.110', title: 'Permitted equivalent', active: true};
    const calls = [], errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const url = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const body = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        let result = {items: [], next_after: null};
        if (url === '/sis/ui/metrics/') result = {cards: [], intake: [], pipeline: [], queue: []};
        else if (url === '/sis/ui/data/courses/') result = {items: [course], next_after: null};
        else if (url.endsWith('/clone/review/')) result = {source: course, prerequisites: [named, alternative],
            prerequisite_groups: [[named.id]], prerequisite_minimums: [1], prerequisite_grade_points_milli: [null],
            prerequisite_equivalents: {[named.id]: [alternative.id]}, operation_id: id(4), revision: 'a'.repeat(64)};
        else if (url.endsWith('/clone/commit/')) {
            calls.push(body); result = {id: id(4), code: body.code, title: body.title, credits_milli: 3000, active: false};
        }
        await route.fulfill({json: result});
    });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="courses"]').click();
    await page.getByRole('button', {name: course.title, exact: true}).click();
    await page.getByRole('button', {name: 'Clone as a course draft', exact: true}).click();
    await page.getByRole('textbox', {name: 'New course code', exact: true}).fill('SCI.202');
    await page.getByLabel('SCI.101 — Named prerequisite', {exact: true}).check();
    await page.getByRole('button', {name: 'Review course clone', exact: true}).click();
    await expect(page.getByText('Keep every equivalent of a retained prerequisite or omit that prerequisite entirely.', {exact: true})).toBeVisible();
    await expect(page.getByRole('button', {name: 'Save course draft', exact: true})).toBeDisabled();
    await page.getByRole('button', {name: 'Change clone details', exact: true}).click();
    await page.getByLabel('SCI.110 — Permitted equivalent', {exact: true}).check();
    await page.getByRole('button', {name: 'Review course clone', exact: true}).click();
    await expect(page.getByRole('button', {name: 'Save course draft', exact: true})).toBeEnabled();
    await page.getByRole('button', {name: 'Save course draft', exact: true}).click();
    expect(calls[0].prerequisites).toEqual([named.id, alternative.id]);
    expect(errors).toEqual([]);
});

for (const context of ['workspace-config', 'campus-config']) {
    test(`${context} explains retained equivalents with safe labels and distinct course counting`, async ({page}) => {
        await page.setContent(`<!doctype html><html lang="en"><head><title>Fictional registration</title></head><body><main class="container p-3"><h1>Review registration</h1><script id="${context}" type="application/json">{}</script><div id="checks"></div></main></body></html>`);
        await page.addStyleTag({path: path.resolve('public/assets/vendor/bootstrap.min.css')});
        await page.addScriptTag({path: path.resolve('public/assets/js/registration-schedule.js')});
        await page.evaluate(() => {
            const node = (tag, attrs = {}, ...children) => {
                const el = document.createElement(tag);
                for (const [key, value] of Object.entries(attrs)) el.setAttribute(key, value);
                el.append(...children.filter(value => value != null)); return el;
            };
            document.getElementById('checks').append(window.createRegistrationSchedule({node}).prerequisites({
                course_statuses: ['met', 'missing'], groups: [{positions: [0, 1], required_count: 2, completed_count: 1, met: false,
                    equivalents: [{required_position: 0, positions: [2]}, {required_position: 1, positions: [2]}]}],
                group_policy: {required_count: 1, completed_count: 0, total_count: 1},
            }, [{code: 'SCI.101', title: 'Science'}, {code: 'SCI.102', title: 'Advanced science'}, {code: 'SCI.110', title: 'Alternative <img> science'}]));
        });
        await page.getByText('Required courses', {exact: true}).press('Enter');
        await expect(page.getByText('SCI.101 · Equivalent courses: SCI.110 · Alternative <img> science', {exact: true})).toBeVisible();
        await expect(page.getByText('SCI.102 · Equivalent courses: SCI.110 · Alternative <img> science', {exact: true})).toBeVisible();
        await expect(page.locator('ol')).toContainText('2 distinct courses required · 1 verified');
        await expect(page.locator('img')).toHaveCount(0);
        await page.setViewportSize({width: 320, height: 800});
        await page.addStyleTag({content: 'html {font-size:200% !important;}'});
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        const scan = await new AxeBuilder({page}).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
        expect(scan.violations.map(item => item.id)).toEqual([]);
    });
}
