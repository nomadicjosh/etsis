const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => String(n).padStart(26, '0');
async function accessible(page) {
    const result = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(result.violations.map(item => ({ id: item.id, targets: item.nodes.map(node => node.target) }))).toEqual([]);
}
async function fixture(page, owner = false, { stale = false, delayed = null, area = 'addresses', denyRefresh = false, workspace = 'contact', identityRows = false, identityDelay = null, denyIdentity = false, profileFailure = false, denyProfile = null } = {}) {
    const calls = [], errors = [], fetches = [], person = id(10), user = id(11);
    const endpoint = owner ? '/portal/api/profile-update-requests/' : `/sis/ui/people/${person}/update-requests/`;
    let rows = (owner || identityRows) ? [{ id: id(30), area, status: 'requested', version: 0,
        instructions: 'Fictional <script>unsafe()</script> address correction', response: null, resolution: null,
        created_at: '2026-11-20T12:00:00Z' }] : [];
    let reads = 0;
    let profileConflicts = profileFailure ? 1 : 0;
    let names = { givenName: 'Fictional', familyName: 'Person', directoryRestricted: true };
    let education = { version: 1, entries: [{key:'school_one',institution:'Fictional School',
        start_date:'2020-08-01',end_date:'2024-05-01',review_status:'changes_requested',feedback:'Correct the final attendance date.'}] };
    const contact = { preferred_name: 'Fictional', phone: '001234', address: '', version: 0, mailing_managed: false };
    page.on('pageerror', e => errors.push(e.message));
    await page.route(/\/(?:sis\/|portal\/api\/).*/, async route => {
        const req = route.request(), url = new URL(req.url()), path = url.pathname;
        const raw = req.postData();
        const data = !raw ? null : req.headers()['content-type']?.includes('application/json') ? JSON.parse(raw)
            : JSON.parse(new URLSearchParams(raw).get('_workspace_json'));
        if (!data) fetches.push(path);
        let result = {};
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path.startsWith('/sis/ui/data/')) result = { items: [{ id: person, owner_user_id: user,
            given_name: 'Fictional', family_name: 'Person', institutional_id: 'S-FICTIONAL' }], next_after: null };
        else if (path === `/sis/ui/people/${person}/`) result = { person: { id: { value: person },
            ownerUserId: { value: user }, givenName: 'Fictional', familyName: 'Person', institutionalId: { value: 'S-FICTIONAL' } },
            students: [], employees: [], accounts: [], applications: [] };
        else if (path === '/portal/api/me/') result = { person: { id: person, given_name: 'Fictional', family_name: 'Person',
            institutional_id: 'S-FICTIONAL' }, contact, students: [], programs: [], applications: [], holds: [],
            accounts: [], transcript: [], registrations: [], transcript_requests: [] };
        else if (path.endsWith('/update-requests/identity/') || path === '/portal/api/profile-update-requests/identity/') {
            if (identityDelay) await identityDelay();
            if (denyIdentity) return route.fulfill({ status: 403, json: { error: 'Access removed.' } });
            result = { given_name: names.givenName, family_name: names.familyName, institutional_id: 'S-FICTIONAL <id>' };
        }
        else if (path === `/sis/ui/people/${person}/profile/`) {
            if (denyProfile === (data ? 'write' : 'read')) return route.fulfill({ status: 403, json: { error: 'Access removed.' } });
            if (data) {
                calls.push({ path, data, csrf: req.headers()['x-csrf-token'] });
                if (profileConflicts-- > 0) return route.fulfill({ status: 409, json: { error: 'Fictional name save conflict.' } });
                names = { givenName: data.given_name, familyName: data.family_name, directoryRestricted: data.directory_restricted };
                result = { status: 'saved' };
            } else result = { person: { ...names }, revision: 'a'.repeat(64) };
        }
        else if (path.endsWith('/contact/')) result = contact;
        else if (path.endsWith('/addresses/')) result = { addresses: [], version: 0, mailing_managed: false,
            new_ids: Array.from({ length: 10 }, (_, n) => id(100 + n)), today: '2026-11-20',
            purposes: { mailing: 'Mailing' }, countries: { US: 'United States' }, earlier_mailing_address: '', mailing_address: '' };
        else if (path.endsWith('/emergency-contacts/')) result = { contacts: [], version: 0 };
        else if (path.endsWith('/education/review/')) {
            calls.push({path,data,csrf:req.headers()['x-csrf-token']});
            education={version:education.version+1,entries:education.entries.map(entry=>({...entry,
                review_status:data.review_status,feedback:data.reason}))};
            result={status:'saved'};
        }
        else if (path === `/sis/applications/${person}/review/`) result={
            application:{id:{value:person},status:'submitted',version:0},
            person:{id:{value:person},givenName:'Fictional',familyName:'Person',institutionalId:{value:'S-FICTIONAL'}},
            labels:{program_code:'ARTS.UG',program_title:'Fictional Arts',term_code:'26/FA'},
        };
        else if (path.endsWith('/education/')) {
            if (data) {
                calls.push({path,data,csrf:req.headers()['x-csrf-token']});
                education={version:education.version+1,entries:data.entries.map(entry=>({...entry,review_status:'unreviewed',feedback:''}))};
            }
            result=education;
        }
        else if (path.startsWith(endpoint)) {
            if (data) {
                calls.push({ path, data, csrf: req.headers()['x-csrf-token'] });
                if (delayed) await delayed();
                if (stale) return route.fulfill({ status: 409, json: { error: 'This request changed. Refresh requests and review its current status.' } });
                if (path === endpoint) rows.unshift({ id: data.request_id, area: data.area, instructions: data.message,
                    status: 'requested', version: 0, created_at: '2026-11-20T12:00:00Z' });
                else {
                    const row = rows.find(row => path.includes(row.id));
                    row.version++;
                    if (owner) { row.status = 'responded'; row.response = data.message; }
                    else { row.status = data.action === 'close' ? 'closed' : 'cancelled'; row.resolution = data.message; }
                }
                result = { status: 'saved' };
            } else if (denyRefresh && reads++ > 0) return route.fulfill({status:403,json:{error:'Access removed.'}});
            else result = { items: rows.filter(row => ['closed', 'cancelled'].includes(row.status) === (url.searchParams.get('finished') === '1') && (!url.searchParams.get('area') || row.area === url.searchParams.get('area'))),
                next_after: null, new_id: id(50 + calls.length), areas: {} };
        }
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    if (owner) await page.goto('/portal/student/profile/');
    else {
        await page.goto('/admin/');
        await page.locator(`.workspace-nav-link[data-workspace="${workspace === 'application' ? 'applications':'people'}"]`).click();
        await page.getByLabel('Search this workspace').fill('Fictional');
        await page.getByLabel('Search this workspace').press('Enter');
        await page.getByRole('button', { name: 'Fictional Person', exact: true }).click();
        if (workspace !== 'application') await page.getByRole('button', { name: workspace === 'education' ? 'Educational background':'Contact information', exact: true }).click();
    }
    return { calls, errors, fetches, panel: page.getByRole('region', { name: 'Profile update requests', exact: true }) };
}
for (const area of ['identity', 'contact', 'addresses', 'emergency_contacts', 'education']) test(`staff requests and closes ${area} follow-up while contact drafts survive`, async ({ page }) => {
    const f = await fixture(page);
    await page.getByLabel('Phone number', { exact: true }).fill('Draft phone');
    await f.panel.getByRole('button', { name: 'Request a profile update', exact: true }).click();
    await f.panel.getByLabel('Information to update', { exact: true }).selectOption(area);
    await f.panel.getByLabel('Instructions visible to the owner').fill('Fictional <img src=x onerror=unsafe()> next step');
    await f.panel.getByRole('button', { name: 'Send profile update request', exact: true }).click();
    await expect(f.panel.getByRole('article')).toHaveCount(1);
    await expect(f.panel.locator('img, script')).toHaveCount(0);
    await f.panel.getByLabel('Resolution note visible to the owner').fill('Fictional current details reviewed.');
    await f.panel.getByRole('button', { name: 'Save request outcome', exact: true }).click();
    await expect(f.panel).toContainText('No active profile update requests.');
    await f.panel.getByRole('button', { name: 'Finished requests', exact: true }).click();
    await expect(f.panel).toContainText('Closed');
    await expect(page.getByLabel('Phone number', { exact: true })).toHaveValue('Draft phone');
    expect(f.calls.map(call => call.data.area || call.data.action)).toEqual([area, 'close']);
    expect(f.calls.every(call => call.csrf === 'fictional-test-token')).toBe(true);
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    expect(f.errors).toEqual([]);
});
test('owner opens the dated editor, preserves an unsent reply across refresh, and responds without staff authority', async ({ page }) => {
    const f = await fixture(page, true);
    await expect(f.panel.getByRole('article')).toHaveCount(1);
    await expect(f.panel.locator('script')).toHaveCount(0);
    await f.panel.getByLabel('Update for staff', { exact: true }).fill('Fictional reply draft');
    await f.panel.getByRole('button', { name: 'Refresh requests', exact: true }).click();
    await expect(f.panel.getByLabel('Update for staff', { exact: true })).toHaveValue('Fictional reply draft');
    await f.panel.getByRole('button', { name: 'Open dated addresses', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Add address', exact: true })).toBeVisible();
    await f.panel.getByRole('button', { name: 'Send update for review', exact: true }).click();
    await expect(f.panel).toContainText('Ready for staff review');
    await expect(f.panel.getByRole('button', { name: 'Save request outcome', exact: true })).toHaveCount(0);
    expect(f.calls[0].data).toEqual({ version: 0, message: 'Fictional reply draft' });
    expect(f.calls[0].csrf).toBe('fictional-test-token');
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    expect(f.errors).toEqual([]);
});
test('stale owner response leaves the private draft available for a deliberate refresh', async ({ page }) => {
    const f = await fixture(page, true, { stale: true });
    await f.panel.getByLabel('Update for staff', { exact: true }).fill('Fictional stale reply');
    await f.panel.getByRole('button', { name: 'Send update for review', exact: true }).click();
    await expect(page.getByRole('alert')).toContainText('This request changed');
    await expect(f.panel.getByLabel('Update for staff', { exact: true })).toHaveValue('Fictional stale reply');
    await f.panel.getByRole('button', { name: 'Refresh requests', exact: true }).click();
    await expect(f.panel.getByLabel('Update for staff', { exact: true })).toHaveValue('Fictional stale reply');
    expect(f.calls).toHaveLength(1);
    expect(f.errors).toEqual([]);
});


test('owner education follow-up opens the existing draft and keeps edits separate from the reply', async ({page}) => {
    const f=await fixture(page,true,{area:'education'});
    await f.panel.getByRole('button',{name:'Open educational background',exact:true}).click();
    const editor=page.getByRole('region',{name:'Institutions attended editor',exact:true});
    await expect(editor.locator('summary').first()).toBeFocused();
    await editor.getByText('Fictional School',{exact:true}).click();
    await editor.getByLabel('Attended through',{exact:true}).fill('2024-06-01');
    await f.panel.getByLabel('Update for staff',{exact:true}).fill('Fictional reply draft');
    await f.panel.getByRole('button',{name:'Open educational background',exact:true}).click();
    await expect(editor.getByLabel('Attended through',{exact:true})).toHaveValue('2024-06-01');
    await expect(f.panel.getByLabel('Update for staff',{exact:true})).toHaveValue('Fictional reply draft');
    await editor.getByLabel('Reason for update',{exact:true}).fill('Fictional corrected attendance.');
    await editor.getByRole('button',{name:'Save background',exact:true}).click();
    await expect(editor).toContainText('Not yet reviewed');
    await expect(f.panel).toContainText('Update requested');
    expect(f.calls.filter(c=>c.path.endsWith('/respond/'))).toHaveLength(0);
    await f.panel.getByRole('button',{name:'Send update for review',exact:true}).click();
    await expect(f.panel).toContainText('Ready for staff review');
    expect(f.calls.find(c=>c.path.endsWith('/education/')).data.entries[0].end_date).toBe('2024-06-01');
    expect(f.calls.find(c=>c.path.endsWith('/respond/')).data.message).toBe('Fictional reply draft');
    await page.setViewportSize({width:320,height:1000}); await accessible(page);
    expect(await page.evaluate(()=>localStorage.length+sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});

test('denied education-request refresh clears private instructions and unfinished replies', async ({page}) => {
    const f=await fixture(page,true,{area:'education',denyRefresh:true});
    await f.panel.getByLabel('Update for staff',{exact:true}).fill('Fictional private draft');
    await f.panel.getByRole('button',{name:'Refresh requests',exact:true}).click();
    await expect(f.panel).toContainText('Private request details cleared');
    await expect(f.panel.getByRole('article')).toHaveCount(0);
    await expect(f.panel.getByRole('textbox')).toHaveCount(0);
    expect(f.errors).toEqual([]);
});


test('staff education follow-up preserves request instructions and education drafts', async ({page}) => {
    const f=await fixture(page,false,{workspace:'education'});
    const editor=page.getByRole('region',{name:'Institutions attended editor',exact:true});
    await editor.getByRole('button',{name:'Request an education update',exact:true}).click();
    await expect(f.panel.getByLabel('Information to update',{exact:true})).toHaveValue('education');
    await f.panel.getByLabel('Instructions visible to the owner',{exact:true}).fill('Fictional education draft');
    await editor.getByRole('button',{name:'Request an education update',exact:true}).click();
    await expect(f.panel.getByLabel('Information to update',{exact:true})).toHaveValue('education');
    await expect(f.panel.getByLabel('Instructions visible to the owner',{exact:true})).toHaveValue('Fictional education draft');
    await editor.getByText('Fictional School',{exact:true}).click();
    await editor.getByLabel('Attended through',{exact:true}).fill('2024-06-01');
    await editor.getByRole('button',{name:'Request an education update',exact:true}).click();
    await expect(editor).toContainText('Your draft is still here');
    await expect(editor.getByLabel('Attended through',{exact:true})).toHaveValue('2024-06-01');
    expect(f.calls).toHaveLength(0);
    await page.setViewportSize({width:320,height:1000}); await accessible(page);
    expect(await page.evaluate(()=>localStorage.length+sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});

test('admissions education review hands off to an explicit request without deciding admission', async ({page}) => {
    const f=await fixture(page,false,{workspace:'application'});
    expect(f.fetches.some(path=>path.endsWith('/update-requests/'))).toBe(false);
    const editor=page.getByRole('region',{name:'Institutions attended editor',exact:true});
    await page.getByRole('button',{name:'Institutions attended',exact:true}).click();
    await editor.getByText('Fictional School',{exact:true}).click();
    await editor.getByText('Review entry',{exact:true}).click();
    await editor.getByLabel('Message to applicant',{exact:true}).fill('Fictional education dates need correction.');
    await editor.getByRole('button',{name:'Request changes',exact:true}).click();
    await expect(editor).toContainText('Fictional education dates need correction.');
    await editor.getByRole('button',{name:'Request an education update',exact:true}).click();
    await expect(f.panel.getByLabel('Information to update',{exact:true})).toHaveValue('education');
    await f.panel.getByLabel('Instructions visible to the owner',{exact:true}).fill('Review the attendance dates, then reply here.');
    await f.panel.getByRole('button',{name:'Send profile update request',exact:true}).click();
    await expect(f.panel).toContainText('Update requested');
    expect(f.calls.map(c=>c.data.area||c.data.review_status)).toEqual(['changes_requested','education']);
    await page.setViewportSize({width:320,height:1000}); await accessible(page);
    expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
    expect(f.errors).toEqual([]);
});


test('late education reply cannot restore details after a denied refresh', async ({page}) => {
    let release;
    const pending=new Promise(resolve=>{release=resolve;});
    const f=await fixture(page,true,{area:'education',denyRefresh:true,delayed:()=>pending});
    await f.panel.getByLabel('Update for staff',{exact:true}).fill('Fictional pending reply');
    await f.panel.getByRole('button',{name:'Send update for review',exact:true}).click();
    await expect(f.panel.getByRole('button',{name:'Send update for review',exact:true})).toBeDisabled();
    await f.panel.getByRole('button',{name:'Refresh requests',exact:true}).click();
    await expect(f.panel).toContainText('Private request details cleared');
    const response=page.waitForResponse(response=>response.url().includes('/respond/')&&response.status()===200);
    release();await response;
    await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));
    await expect(f.panel.getByRole('article')).toHaveCount(0);
    await expect(f.panel.getByRole('textbox')).toHaveCount(0);
    await expect(f.panel).toContainText('Private request details cleared');
    expect(f.errors).toEqual([]);
});


test('owner identity review keeps replies separate and clears private names after access is lost', async ({ page }) => {
    const f = await fixture(page, true, { area: 'identity', denyRefresh: true });
    await f.panel.getByLabel('Update for staff', { exact: true }).fill('Fictional corrected spelling.');
    await f.panel.getByRole('button', { name: 'Review recorded identity', exact: true }).click();
    const identity = f.panel.getByRole('region', { name: 'Current recorded identity', exact: true });
    await expect(identity).toContainText('S-FICTIONAL <id>');
    await expect(f.panel).toContainText('Sending a reply does not change identity details');
    await expect(identity.getByRole('textbox')).toHaveCount(0);
    await expect(identity.locator('id')).toHaveCount(0);
    await identity.getByRole('button', { name: 'Close identity review', exact: true }).click();
    await expect(f.panel.getByLabel('Update for staff', { exact: true })).toHaveValue('Fictional corrected spelling.');
    await f.panel.getByRole('button', { name: 'Review recorded identity', exact: true }).click();
    await page.setViewportSize({ width: 320, height: 1000 });
    await page.addStyleTag({ content: 'html { font-size: 200%; }' });
    await accessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await f.panel.getByRole('button', { name: 'Refresh requests', exact: true }).click();
    await expect(f.panel).toContainText('Private request details cleared');
    await expect(f.panel).not.toContainText('S-FICTIONAL');
    await expect(f.panel.getByRole('textbox')).toHaveCount(0);
    expect(f.calls).toHaveLength(0);
    expect(f.errors).toEqual([]);
});

test('staff applies canonical name edits inside identity follow-up without losing reply or contact drafts', async ({ page }) => {
    const f = await fixture(page, false, { area: 'identity', identityRows: true, profileFailure: true });
    await page.getByLabel('Phone number', { exact: true }).fill('Preserved contact draft');
    const note = f.panel.getByLabel('Resolution note visible to the owner', { exact: true });
    await note.fill('Fictional evidence reviewed; name correction pending.');
    await f.panel.getByRole('button', { name: 'Review recorded identity', exact: true }).click();
    await f.panel.getByRole('button', { name: 'Edit person names', exact: true }).click();
    await f.panel.getByRole('textbox', { name: 'Given name', exact: true }).fill('Fictional Revised <name>');
    await f.panel.getByRole('textbox', { name: 'Reason for profile correction', exact: true }).fill('Fictional reviewed name evidence.');
    await f.panel.getByRole('button', { name: 'Finished requests', exact: true }).click();
    await expect(f.panel.getByRole('button', { name: 'Finished requests', exact: true })).toBeVisible();
    await f.panel.getByRole('button', { name: 'Save request outcome', exact: true }).click();
    expect(f.calls).toHaveLength(0);
    await f.panel.getByRole('button', { name: 'Refresh recorded identity', exact: true }).click();
    await expect(f.panel.getByRole('textbox', { name: 'Given name', exact: true })).toHaveValue('Fictional Revised <name>');
    await f.panel.getByRole('button', { name: 'Save person names', exact: true }).click();
    await expect(page.getByRole('alert')).toContainText('Fictional name save conflict');
    await expect(f.panel.getByRole('textbox', { name: 'Given name', exact: true })).toHaveValue('Fictional Revised <name>');
    await expect(note).toHaveValue('Fictional evidence reviewed; name correction pending.');
    await f.panel.getByRole('button', { name: 'Save person names', exact: true }).click();
    await expect(f.panel.getByRole('region', { name: 'Current recorded identity', exact: true })).toContainText('Fictional Revised <name>');
    await page.setViewportSize({ width: 375, height: 1000 });
    await page.addStyleTag({ content: 'html { font-size: 200%; }' });
    await accessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    for (const toast of await page.getByRole('button', { name: 'Dismiss notification', exact: true }).all()) await toast.click();
    await f.panel.getByRole('region', { name: 'Current recorded identity', exact: true }).scrollIntoViewIfNeeded();
    await page.screenshot({ path: '/tmp/etsis-identity-staff-mobile.png' });
    await expect(page.getByLabel('Phone number', { exact: true })).toHaveValue('Preserved contact draft');
    await expect(f.panel).toContainText('Update requested');
    expect(f.calls.filter(call => call.path.endsWith('/resolve/'))).toHaveLength(0);
    await f.panel.getByRole('button', { name: 'Save request outcome', exact: true }).click();
    expect(f.calls.map(call => call.path.endsWith('/profile/') ? call.data.given_name : call.data.action)).toEqual(['Fictional Revised <name>', 'Fictional Revised <name>', 'close']);
    expect(f.calls[1].data.directory_restricted).toBe(true);
    expect(f.calls.every(call => call.csrf === 'fictional-test-token')).toBe(true);
    await page.setViewportSize({ width: 375, height: 1000 }); await accessible(page);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});

test('admissions opens an identity-only follow-up and keeps admission decisions separate', async ({ page }) => {
    const f = await fixture(page, false, { workspace: 'application', area: 'identity', identityRows: true });
    await page.getByRole('button', { name: 'Identity correction requests', exact: true }).click();
    const panel = page.getByRole('region', { name: 'Identity correction requests', exact: true });
    await expect(panel.getByRole('article')).toHaveCount(1);
    await panel.getByRole('button', { name: 'Review recorded identity', exact: true }).click();
    await panel.getByRole('button', { name: 'Edit person names', exact: true }).click();
    await panel.getByRole('textbox', { name: 'Family name', exact: true }).fill('Revised fictional family');
    await panel.getByRole('button', { name: 'Back to identity review', exact: true }).click();
    await expect(panel.getByRole('region', { name: 'Current recorded identity', exact: true })).toContainText('Person');
    await panel.getByLabel('Resolution note visible to the owner', { exact: true }).fill('Fictional original identity remains correct.');
    await panel.getByRole('button', { name: 'Save request outcome', exact: true }).click();
    expect(f.calls.map(call => call.data.action)).toEqual(['close']);
    expect(f.fetches.some(path => path.endsWith('/identity/'))).toBe(true);
    await page.setViewportSize({ width: 320, height: 1000 }); await accessible(page);
    expect(f.errors).toEqual([]);
});

test('late identity reads cannot restore a denied request panel', async ({ page }) => {
    let release;
    const pending = new Promise(resolve => { release = resolve; });
    const f = await fixture(page, true, { area: 'identity', denyRefresh: true, identityDelay: () => pending });
    await f.panel.getByRole('button', { name: 'Review recorded identity', exact: true }).click();
    await expect(f.panel).toContainText('Loading recorded identity');
    await f.panel.getByRole('button', { name: 'Refresh requests', exact: true }).click();
    await expect(f.panel).toContainText('Private request details cleared');
    const response = page.waitForResponse(value => value.url().includes('/identity/') && value.status() === 200);
    release(); await response;
    await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
    await expect(f.panel).not.toContainText('S-FICTIONAL');
    await expect(f.panel.getByRole('article')).toHaveCount(0);
    expect(f.errors).toEqual([]);
});


for (const stage of ['read', 'write']) test(`denied staff identity ${stage} clears the whole private follow-up`, async ({ page }) => {
    const f = await fixture(page, false, { area: 'identity', identityRows: true, denyProfile: stage });
    await f.panel.getByLabel('Resolution note visible to the owner', { exact: true }).fill('Fictional unfinished resolution.');
    await f.panel.getByRole('button', { name: 'Review recorded identity', exact: true }).click();
    await f.panel.getByRole('button', { name: 'Edit person names', exact: true }).click();
    if (stage === 'write') {
        await f.panel.getByRole('textbox', { name: 'Family name', exact: true }).fill('Fictional private change');
        await f.panel.getByRole('textbox', { name: 'Reason for profile correction', exact: true }).fill('Fictional correction evidence.');
        await f.panel.getByRole('button', { name: 'Save person names', exact: true }).click();
    }
    await expect(f.panel).toContainText('Private request details cleared');
    await expect(f.panel.getByRole('textbox')).toHaveCount(0);
    await expect(f.panel).not.toContainText('S-FICTIONAL');
    await expect(f.panel).not.toContainText('Fictional unfinished resolution');
    expect(f.errors).toEqual([]);
});
