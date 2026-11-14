const { test, expect } = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = number => `01K000000000000000000000${String(number).padStart(2, '0')}`;

async function fixture(page, options = {}) {
    const calls = [], errors = [], publications = [];
    let definition = { code: 'BSCS', title: 'Computer Science', description: options.description || null, classifications: { department: null, academic_level: null, award: null } };
    const rows = [
        { id: id(3), kind: 'department', code: 'SCI', title: 'Sciences', active: true },
        { id: id(4), kind: 'academic_level', code: 'UG', title: 'Undergraduate', active: true },
        { id: id(5), kind: 'award', code: 'BA', title: 'Bachelor of Arts <script>fictional</script>', active: true },
    ];
    let reviews = 0, accepted = null;
    const specializations = options.specializations ? Array.from({length:23},(_,i)=>({id:id(30+i),program_id:id(1),code:`FOCUS.${String(i).padStart(2,'0')}`,title:`Fictional specialization ${i} <b>example</b>`,description:'A fictional description.',revision:1,active:true})) : [];
    let specializationFailure = !!options.specializationFailure;
    let institution = {revision:0,minimum:0,maximum:1,default_maximum:1};
    let programPolicy = {revision:0,minimum:0,maximum:null,default_maximum:null};
    const specializationSnapshot = () => ({program_id:id(1),institution:{revision:institution.revision,maximum:institution.maximum,default_maximum:institution.default_maximum},program:{revision:programPolicy.revision,minimum:programPolicy.minimum,maximum:programPolicy.maximum},effective:{minimum:programPolicy.minimum,maximum:programPolicy.maximum??institution.default_maximum},offered:specializations.filter(s=>s.active).map(({id,code,title,description,revision})=>({id,code,title,description,revision}))});
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/sis/**', async route => {
        const path = new URL(route.request().url()).pathname;
        const raw = route.request().postData();
        const data = raw ? JSON.parse(new URLSearchParams(raw).get('_workspace_json')) : null;
        if (data) calls.push({ path, data, csrf: route.request().headers()['x-csrf-token'] });
        let result = { items: [], next_after: null };
        if (path === '/sis/ui/metrics/') result = { cards: [], intake: [], pipeline: [], queue: [] };
        else if (path === '/sis/ui/data/programs/') result = { items: [{ id: id(1), code: definition.code, title: definition.title }], next_after: null };
        else if (path === `/sis/programs/${id(1)}/details/`) {
            if (data) definition = { code: data.code, title: data.title, description: data.description, classifications: Object.fromEntries(['department', 'academic_level', 'award'].map(kind => {
                const row = rows.find(row => row.id === data[`${kind}_id`]);
                return [kind, row ? { id: row.id, code: row.code, title: row.title } : null];
            })) };
            result = { id: id(1), definition, revision: 'a'.repeat(64) };
        } else if (path === `/sis/programs/${id(1)}/specializations/page/`) {
            const matches = specializations.filter(row=>(!data.after||row.id>data.after)&&`${row.code} ${row.title}`.toLowerCase().includes(data.search.toLowerCase())).sort((a,b)=>a.id.localeCompare(b.id));
            result={program:{id:id(1),code:definition.code,title:definition.title},items:matches.slice(0,20).map(({description,...row})=>row),next_after:matches.length>20?matches[19].id:null};
        } else if (path === `/sis/programs/${id(1)}/specializations/save/`) {
            if (specializationFailure) {specializationFailure=false;await route.fulfill({status:409,json:{error:'Reload this specialization before saving your changes.'}});return;}
            const previous=data.id?specializations.find(row=>row.id===data.id):null;
            result={...data,id:previous?.id||id(99),program_id:id(1),code:data.code.trim().toUpperCase(),revision:(previous?.revision||0)+1};
            if(previous) Object.assign(previous,result);else specializations.push(result);
        } else if (path === `/sis/programs/${id(1)}/specializations/policy/`) {
            if(data){
                if((data.maximum??institution.default_maximum)>institution.maximum || data.minimum>(data.maximum??institution.default_maximum)) {
                    await route.fulfill({status:422,json:{error:'Please correct the highlighted fields.',errors:[{field:'minimum',message:'The required number must fit within the program maximum.'}]}});return;
                }
                programPolicy={...programPolicy,...data,revision:programPolicy.revision+1};result={status:'saved'};
            }else{
                const invalid=(programPolicy.maximum??institution.default_maximum)>institution.maximum||programPolicy.minimum>(programPolicy.maximum??institution.default_maximum);
                result={program:{id:id(1),code:definition.code,title:definition.title},institution,settings:programPolicy,effective:invalid?null:specializationSnapshot().effective,errors:invalid?[{field:'maximum',message:'Outside institutional policy.'}]:[],can_manage_institution:!options.catalogOnly};
            }
        } else if(path==='/sis/catalog/specialization-policy/') {
            institution={...institution,...data,revision:institution.revision+1};result={status:'saved'};
        } else if (path.startsWith(`/sis/programs/${id(1)}/specializations/`)) {
            result=specializations.find(row=>row.id===path.split('/').at(-2));
        } else if (path === `/sis/programs/${id(1)}/assistance/`) {
            if (data && options.assistanceFailure) {
                await route.fulfill({ status: 503, json: { error: 'The assistance provider rejected authentication. Ask an administrator to check the AI credential and authentication setting. Your draft is unchanged.' } }); return;
            }
            if (data && options.editWhileRunning) await page.getByRole('textbox', { name: 'Program description', exact: true }).fill('My later edit');
            result = data ? { task: data.task, advice: { summary: '<script>window.programAiExecuted = true</script> Review this suggestion.', suggestions: ['Verify the description.'], description: 'A fictional program <img src=x onerror=alert(1)>.' } } : {
                enabled: !options.assistanceDisabled, label: 'Fictional provider', tasks: options.tasks || ['description', ...(definition.description ? ['rewrite', 'expand'] : [])], revision: 'b'.repeat(64), saved_revision: 'a'.repeat(64),
                draft: { kind: 'academic_program', code: definition.code, title: definition.title, description: definition.description, classifications: Object.fromEntries(Object.entries(definition.classifications).map(([kind, row]) => [kind, row ? { code: row.code, title: row.title } : null])) },
            };
        } else if (path.startsWith('/sis/catalog/classifications/')) {
            result = { items: rows.filter(row => row.kind === path.split('/')[4] && `${row.code} ${row.title}`.toLowerCase().includes(data.search.toLowerCase())), next_after: null };
        } else if (path === '/sis/academic-years/') result = { items: [{ id: id(2), code: '2024-2025' }].filter(year => year.code.includes(data.search)), next_after: null };
        else if (path.endsWith('/publication/review/')) {
            accepted = { academic_year_id: data.academic_year_id, academic_year_code: '2024-2025', catalog_revision: publications.length + 1,
                definition: JSON.parse(JSON.stringify(definition)), specializations: JSON.parse(JSON.stringify(specializationSnapshot())), reason: data.reason, review_token: `fictional-program-proof-${++reviews}` };
            result = accepted;
        } else if (path.endsWith('/publication/commit/')) {
            publications.push({ ...accepted, version_id: id(6), version: publications.length + 1, effective_at: '2026-11-10T12:00:00Z' });
            result = { operation_id: id(6) };
        } else if (path.endsWith('/publication/page/')) result = { items: publications, next_cursor: null };
        await route.fulfill({ json: result, headers: { 'Cache-Control': 'private, no-store' } });
    });
    await page.goto('/admin/');
    await page.locator('.workspace-nav-link[data-workspace="courses"]').click();
    await page.getByRole('link', { name: 'Programs', exact: true }).click();
    await page.getByRole('button', { name: 'Computer Science', exact: true }).click();
    await page.getByRole('button', { name: 'Program details & publications', exact: true }).click();
    return { calls, errors };
}

async function accessible(page) {
    await page.setViewportSize({ width: 375, height: 812 });
    const scan = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa']).analyze();
    expect(scan.violations.map(item => item.id)).toEqual([]);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
}

test('program details and classifications are edited together and explicitly reviewed for an academic year', async ({ page }) => {
    const f = await fixture(page);
    await page.getByRole('textbox', { name: 'Program title', exact: true }).fill('Fictional Science');
    await page.getByRole('textbox', { name: 'Program description', exact: true }).fill('A fictional description <img src=x onerror=alert(1)>');
    for (const [category, code] of [['department', 'SCI'], ['academic level', 'UG'], ['award', 'BA']]) {
        await page.getByRole('button', { name: `Choose ${category}`, exact: true }).click();
        await page.getByRole('button', { name: `Choose ${code}`, exact: true }).click();
    }
    await expect(page.getByRole('textbox', { name: 'Program title', exact: true })).toHaveValue('Fictional Science');
    await expect(page.getByRole('button', { name: 'Publish for an academic year', exact: true })).toBeDisabled();
    await accessible(page);
    await page.getByRole('button', { name: 'Save program details', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: 'Program details saved.' })).toBeVisible();
    await page.getByRole('button', { name: 'Publish for an academic year', exact: true }).click();
    await page.getByLabel('Reason for program publication', { exact: true }).fill('Fictional reviewed 2024 requirements context');
    await page.getByRole('button', { name: 'Choose academic year', exact: true }).click();
    await page.getByRole('button', { name: 'Use academic year 2024-2025', exact: true }).click();
    await expect(page.getByLabel('Reason for program publication', { exact: true })).toHaveValue('Fictional reviewed 2024 requirements context');
    await page.getByRole('button', { name: 'Review program publication', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('2024-2025 · Catalog revision 1');
    await expect(page.locator('#drawer-body')).toContainText('BA — Bachelor of Arts <script>fictional</script>');
    await page.getByRole('button', { name: 'Revise program publication', exact: true }).click();
    await expect(page.getByLabel('Reason for program publication', { exact: true })).toHaveValue('Fictional reviewed 2024 requirements context');
    await page.getByRole('button', { name: 'Review program publication', exact: true }).click();
    await page.getByRole('button', { name: 'Refresh program review', exact: true }).click();
    await accessible(page);
    const confirm = page.getByRole('button', { name: 'Confirm program publication', exact: true });
    await confirm.focus(); await page.keyboard.press('Enter');
    await expect(page.getByRole('status').filter({ hasText: 'Catalog revision 1 published.' })).toBeVisible();
    expect(f.calls.find(call => call.path.endsWith('/publication/commit/'))).toMatchObject({ csrf: 'fictional-test-token', data: { review_token: 'fictional-program-proof-3' } });
    expect(f.calls.filter(call => call.path.endsWith('/publication/commit/'))).toHaveLength(1);
    await page.getByRole('button', { name: 'Published program details', exact: true }).click();
    await page.getByText('2024-2025 · Revision 1 · Fictional Science', { exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('Sciences');
    expect(await page.locator('#drawer-body img, #drawer-body script').count()).toBe(0);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});

test('choosers and publication history preserve unsaved program text without saving or publishing', async ({ page }) => {
    const f = await fixture(page);
    await page.getByRole('textbox', { name: 'Program title', exact: true }).fill('Unsaved fictional program');
    await page.getByRole('textbox', { name: 'Program description', exact: true }).fill('A private draft in page memory.');
    await page.getByRole('button', { name: 'Choose award', exact: true }).click();
    await page.getByRole('button', { name: 'Back to program details', exact: true }).click();
    await expect(page.getByRole('textbox', { name: 'Program title', exact: true })).toHaveValue('Unsaved fictional program');
    await page.getByRole('button', { name: 'Published program details', exact: true }).click();
    await expect(page.locator('#drawer-body')).toContainText('No program details have been published yet.');
    await page.getByRole('button', { name: 'Back to program details', exact: true }).click();
    await expect(page.getByRole('textbox', { name: 'Program description', exact: true })).toHaveValue('A private draft in page memory.');
    expect(f.calls.filter(call => call.path.endsWith('/details/') || call.path.endsWith('/publication/commit/'))).toHaveLength(0);
    expect(f.errors).toEqual([]);
});


test('program assistance requires consent, renders plain text and needs explicit copy and save', async ({ page }) => {
    const f = await fixture(page);
    await page.getByRole('button', { name: 'Optional AI help', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Optional program assistance' })).toBeFocused();
    await page.getByText('Inspect exactly what will be sent', { exact: true }).click();
    await expect(page.locator('#drawer-body pre')).toContainText('academic_program');
    await page.getByRole('button', { name: 'Suggest a program description', exact: true }).click();
    expect(f.calls.filter(call => call.path.endsWith('/assistance/'))).toHaveLength(0);
    await accessible(page);
    await page.getByRole('checkbox').check();
    await page.getByRole('button', { name: 'Suggest a program description', exact: true }).click();
    await expect(page.getByText('Suggestions received. No text has been saved or published.', { exact: true })).toBeVisible();
    await expect(page.getByRole('checkbox')).not.toBeChecked();
    await expect(page.getByRole('textbox', { name: 'Program description', exact: true })).toHaveValue('');
    expect(f.calls.filter(call => call.path.endsWith('/assistance/'))).toEqual([{ path: `/sis/programs/${id(1)}/assistance/`, csrf: 'fictional-test-token', data: { task: 'description', consent: true, revision: 'b'.repeat(64) } }]);
    expect(f.calls.filter(call => call.path.endsWith('/details/'))).toHaveLength(0);
    expect(await page.evaluate(() => window.programAiExecuted)).toBeUndefined();
    expect(await page.locator('#drawer-body script, #drawer-body img').count()).toBe(0);
    await page.getByRole('button', { name: 'Use suggested description in this editor', exact: true }).click();
    await expect(page.getByRole('textbox', { name: 'Program description', exact: true })).toHaveValue('A fictional program <img src=x onerror=alert(1)>.');
    await expect(page.getByRole('button', { name: 'Publish for an academic year', exact: true })).toBeDisabled();
    await page.getByRole('button', { name: 'Save program details', exact: true }).click();
    expect(f.calls.filter(call => call.path.endsWith('/details/'))).toHaveLength(1);
    expect(f.calls.filter(call => call.path.endsWith('/publication/commit/'))).toHaveLength(0);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});

test('program assistance protects unsaved edits and offers only granted text actions', async ({ page }) => {
    const f = await fixture(page, { description: 'Saved fictional program.', tasks: ['rewrite'], editWhileRunning: true });
    await page.getByRole('button', { name: 'Optional AI help', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Suggest a program description', exact: true })).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'Expand description', exact: true })).toHaveCount(0);
    await page.getByRole('checkbox').check();
    await page.getByRole('button', { name: 'Rewrite description', exact: true }).click();
    await page.getByRole('button', { name: 'Use suggested description in this editor', exact: true }).click();
    await expect(page.getByRole('textbox', { name: 'Program description', exact: true })).toHaveValue('My later edit');
    await expect(page.getByRole('alert').filter({ hasText: 'Your program has changed' })).toBeVisible();
    expect(f.calls.filter(call => call.path.endsWith('/details/'))).toHaveLength(0);
    expect(f.errors).toEqual([]);
});

test('disabled and failed program assistance leave editing available with fresh consent', async ({ page }) => {
    for (const options of [{ assistanceDisabled: true }, { assistanceFailure: true }]) {
        const f = await fixture(page, options);
        await page.getByRole('button', { name: 'Optional AI help', exact: true }).click();
        if (options.assistanceFailure) {
            await page.getByRole('checkbox').check();
            await page.getByRole('button', { name: 'Suggest a program description', exact: true }).click();
            await expect(page.getByRole('alert').filter({ hasText: 'rejected authentication' })).toBeVisible();
            await expect(page.getByRole('checkbox')).not.toBeChecked();
        } else await expect(page.locator('#drawer-body')).toContainText('Optional assistance is disabled');
        await page.getByRole('textbox', { name: 'Program description', exact: true }).fill('Staff can still edit.');
        await expect(page.getByRole('button', { name: 'Save program details', exact: true })).toBeEnabled();
        expect(f.calls.filter(call => call.path.endsWith('/details/'))).toHaveLength(0);
        expect(f.errors).toEqual([]);
    }
});

test("program specialization setup preserves the program draft and saves literal text with explicit availability", async ({
    page,
}) => {
    const f = await fixture(page);
    await page.getByRole("textbox", { name: "Program title", exact: true }).fill("My unsaved program title");
    await page
        .getByRole("textbox", { name: "Program description", exact: true })
        .fill("My unsaved program description");
    await page.getByRole("button", { name: "Program specializations", exact: true }).click();
    await expect(page.getByText("No specializations match this search.", { exact: true })).toBeVisible();
    await page.getByRole("button", { name: "Add a specialization", exact: true }).click();
    await page.getByRole("textbox", { name: "Specialization code", exact: true }).fill("DATA.SCI");
    await page
        .getByRole("textbox", { name: "Specialization title", exact: true })
        .fill("Data science <b>fictional</b>");
    await page
        .getByRole("textbox", { name: "Specialization description", exact: true })
        .fill("Fictional first paragraph.\nSecond paragraph.");
    await page.getByRole("checkbox", { name: "Active specialization", exact: true }).uncheck();
    await accessible(page);
    await page.getByRole("button", { name: "Save specialization", exact: true }).click();
    await expect(page.getByText("Specialization saved.", { exact: true })).toBeVisible();
    await expect(page.getByText("DATA.SCI — Data science <b>fictional</b>", { exact: true })).toBeVisible();
    await expect(page.getByText("Inactive — retained for reference", { exact: true })).toBeVisible();
    await page.getByRole("button", { name: "Edit specialization DATA.SCI", exact: true }).click();
    await expect(page.getByRole("textbox", { name: "Specialization description", exact: true })).toHaveValue(
        "Fictional first paragraph.\nSecond paragraph.",
    );
    await expect(
        page.getByRole("checkbox", { name: "Active specialization", exact: true }),
    ).not.toBeChecked();
    await page.getByRole("button", { name: "Cancel specialization changes", exact: true }).click();
    await page.getByRole("button", { name: "Back to program details", exact: true }).click();
    await expect(page.getByRole("textbox", { name: "Program title", exact: true })).toHaveValue(
        "My unsaved program title",
    );
    await expect(page.getByRole("textbox", { name: "Program description", exact: true })).toHaveValue(
        "My unsaved program description",
    );
    const saves = f.calls.filter((c) => c.path.endsWith("/specializations/save/"));
    expect(saves).toHaveLength(1);
    expect(saves[0].data).toMatchObject({ id: null, revision: null, active: false });
    expect(f.calls.some((c) => c.path.endsWith("/details/") || c.path.endsWith("/publication/commit/"))).toBe(
        false,
    );
    expect(f.calls.every((c) => c.csrf === "fictional-test-token")).toBe(true);
    expect(f.errors).toEqual([]);
    expect(await page.evaluate(() => localStorage.length + sessionStorage.length)).toBe(0);
});

test("specialization lists page and search within their program and rejected edits preserve entered values", async ({
    page,
}) => {
    const f = await fixture(page, { specializations: true, specializationFailure: true });
    await page.getByRole("button", { name: "Program specializations", exact: true }).click();
    await expect(page.getByRole("button", { name: /^Edit specialization / })).toHaveCount(20);
    await page.getByRole("button", { name: "More specializations", exact: true }).click();
    await expect(page.getByRole("button", { name: /^Edit specialization / })).toHaveCount(3);
    await page.getByRole("button", { name: "Edit specialization FOCUS.22", exact: true }).click();
    await page
        .getByRole("textbox", { name: "Specialization title", exact: true })
        .fill("Updated focus <script>fictional</script>");
    await page.getByRole("checkbox", { name: "Active specialization", exact: true }).uncheck();
    await page.getByRole("button", { name: "Save specialization", exact: true }).click();
    await expect(
        page.getByText("Reload this specialization before saving your changes.", { exact: true }),
    ).toBeVisible();
    await expect(page.getByRole("textbox", { name: "Specialization title", exact: true })).toHaveValue(
        "Updated focus <script>fictional</script>",
    );
    await page.getByRole("button", { name: "Save specialization", exact: true }).click();
    await expect(page.getByRole("button", { name: /^Edit specialization / })).toHaveCount(3);
    await page.getByRole("button", { name: "Previous specializations", exact: true }).click();
    await expect(page.getByRole("button", { name: /^Edit specialization / })).toHaveCount(20);
    await page
        .getByRole("searchbox", { name: "Search this program's specializations", exact: true })
        .fill("FOCUS.03");
    await page.getByRole("button", { name: "Search specializations", exact: true }).click();
    await expect(page.getByRole("button", { name: /^Edit specialization / })).toHaveCount(1);
    await expect(
        page.getByRole("button", { name: "Edit specialization FOCUS.03", exact: true }),
    ).toBeVisible();
    await page.setViewportSize({ width: 375, height: 812 });
    await accessible(page);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    expect(await page.locator("#record-drawer b,#record-drawer script").count()).toBe(0);
    expect(
        f.calls.filter((c) => c.path.endsWith("/specializations/save/")).map((c) => c.data.revision),
    ).toEqual([1, 1]);
    expect(f.errors).toEqual([]);
});

test('institutional ceilings and program specialization limits are reviewed with the exact publication choices',async({page})=>{
    const f=await fixture(page,{specializations:true});
    await page.getByRole('button',{name:'Program specializations',exact:true}).click();
    await page.getByRole('button',{name:'Specialization selection policy',exact:true}).click();
    await expect(page.getByRole('spinbutton',{name:'Program maximum specializations',exact:true})).toBeDisabled();
    await expect(page.getByText(/Institutional ceiling: 1\./)).toBeVisible();
    await page.locator('summary').filter({hasText:'Institution-wide boundaries'}).click();
    await page.getByRole('spinbutton',{name:'Institution-wide maximum specializations',exact:true}).fill('3');
    await page.getByRole('button',{name:'Save institutional specialization boundaries',exact:true}).click();
    await expect(page.getByText('Institutional specialization boundaries saved. Earlier publications are unchanged.',{exact:true})).toBeVisible();
    await page.getByRole('spinbutton',{name:'Required specializations (zero means optional)',exact:true}).fill('1');
    await page.getByRole('checkbox',{name:'Use institutional default maximum',exact:true}).uncheck();
    await page.getByRole('spinbutton',{name:'Program maximum specializations',exact:true}).fill('2');
    await page.getByRole('button',{name:'Save program specialization policy',exact:true}).click();
    await expect(page.getByRole('spinbutton',{name:'Program maximum specializations',exact:true})).toHaveValue('2');
    await page.getByRole('button',{name:'Back to specializations',exact:true}).click();
    await page.getByRole('button',{name:'Back to program details',exact:true}).click();
    await page.getByRole('button',{name:'Publish for an academic year',exact:true}).click();
    await page.getByLabel('Reason for program publication',{exact:true}).fill('Fictional reviewed specialization policy.');
    await page.getByRole('button',{name:'Choose academic year',exact:true}).click();
    await page.getByRole('button',{name:'Use academic year 2024-2025',exact:true}).click();
    await page.getByRole('button',{name:'Review program publication',exact:true}).click();
    const context=page.getByRole('region',{name:'Published specialization choices and policy',exact:true});
    await expect(context).toContainText('Required: 1 · Maximum per pursuit: 2 · Institutional ceiling: 3.');
    await expect(context.getByRole('article')).toHaveCount(0);
    await context.locator('summary').filter({hasText:'23 offered specializations'}).click();
    await expect(context.getByRole('article')).toHaveCount(20);
    await page.setViewportSize({width:375,height:812});await accessible(page);
    expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
    await context.getByRole('button',{name:'More offered specializations',exact:true}).click();
    await expect(context.getByText('Offered specializations 21–23 of 23',{exact:true})).toBeFocused();
    await expect(context).toContainText('FOCUS.22');
    expect(await context.locator('b,script').count()).toBe(0);
    await page.getByRole('button',{name:'Confirm program publication',exact:true}).click();
    await page.getByRole('button',{name:'Published program details',exact:true}).click();
    await page.locator('summary').filter({hasText:'2024-2025 · Revision 1'}).click();
    await expect(page.getByRole('region',{name:'Published specialization choices and policy',exact:true})).toContainText('Maximum per pursuit: 2');
    expect(f.calls.find(c=>c.path==='/sis/catalog/specialization-policy/').data).toEqual({revision:0,maximum:3,default_maximum:1});
    expect(f.calls.find(c=>c.path.endsWith('/specializations/policy/')).data).toEqual({revision:0,minimum:1,maximum:2});
    expect(f.errors).toEqual([]);
    expect(await page.evaluate(()=>localStorage.length+sessionStorage.length)).toBe(0);
});

test('catalog staff see institutional boundaries and retain rejected program policy edits without an institutional grant',async({page})=>{
    const f=await fixture(page,{catalogOnly:true});
    await page.getByRole('button',{name:'Program specializations',exact:true}).click();
    await page.getByRole('button',{name:'Specialization selection policy',exact:true}).click();
    await expect(page.getByText('Institution-wide boundaries',{exact:true})).toHaveCount(0);
    const required=page.getByRole('spinbutton',{name:'Required specializations (zero means optional)',exact:true});
    await required.fill('2');
    await page.getByRole('button',{name:'Save program specialization policy',exact:true}).click();
    await expect(page.getByText(/The required number must fit within the program maximum\./)).toBeVisible();
    await expect(required).toHaveValue('2');
    await required.fill('0');
    await page.getByRole('button',{name:'Save program specialization policy',exact:true}).click();
    await expect(page.getByRole('checkbox',{name:'Use institutional default maximum',exact:true})).toBeChecked();
    await page.setViewportSize({width:375,height:812});await accessible(page);
    expect(f.calls.some(c=>c.path==='/sis/catalog/specialization-policy/')).toBe(false);
    expect(f.calls.filter(c=>c.path.endsWith('/specializations/policy/')).map(c=>c.data.revision)).toEqual([0,0]);
    expect(f.errors).toEqual([]);
});
