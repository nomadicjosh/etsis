const {test,expect} = require('@playwright/test');
const AxeBuilder = require('@axe-core/playwright').default;
const id = n => `01K${String(n).padStart(23,'0')}`;
const student=id(41), program=id(42), pursuit=id(43);
async function fixture(page,options={}) {
    const calls=[], errors=[];
    let releaseFirst, markFirst;
    const waitFirst = new Promise(resolve => { releaseFirst = resolve; });
    const firstRequested = new Promise(resolve => { markFirst = resolve; });
    page.on('pageerror',error=>errors.push(error.message));
    await page.route('**/portal/api/**',async route=>{
        const request=route.request(),path=new URL(request.url()).pathname;
        const body=request.method()==='POST'?JSON.parse(new URLSearchParams(request.postData()).get('_workspace_json')):null;
        calls.push({path,body,csrf:request.headers()['x-csrf-token']});
        let data;
        if(path==='/portal/api/me/') data={students:options.noStudent?[]:[{id:student,primary_program_id:program}]};
        else if(path.endsWith('/degree-programs/')) data={items:[{id:body.after?id(44):pursuit,program_code:body.after?'DUAL':'BSCS',program_title:'Computing <script>fictional</script>',term_code:'26/FA',status:'active',catalog_year:'2026-2027'}],next_after:options.morePrograms&&!body.after?pursuit:null};
        else if(path.endsWith('/degree-progress/')) {
            if (options.delayFirst && path.includes(`/programs/${pursuit}/`)) { markFirst(); await waitFirst; }
            if(options.deny) return route.fulfill({status:403,json:{error:'Access denied.'}});
            if(options.limit) data={status:'limited'};
            else if(options.needsSelection) data={status:'needs_selection'};
            else data={status:'review',input_status:'history_gaps',current_record_count:21,
                context:{name:'B.S. Computing <script>fictional</script>',academic_year_code:'2026-2027',version:1,cohort_changed:true},
                minimums:[{key:'min_credits_milli',required_milli:120000,actual_minimum_milli:3000,actual_maximum_milli:3000,status:'below_minimum'},
                    {key:'min_cumulative_gpa_milli',required_milli:2000,actual_minimum_milli:4000,actual_maximum_milli:4000,status:'satisfied'},
                    {key:'min_overall_gpa_milli',required_milli:3000,actual_minimum_milli:null,actual_maximum_milli:null,status:'unknown'}],
                gpa:{cumulative:{ready:true,milli:4000,credits_milli:3000},overall:{ready:false,milli:null,credits_milli:null}},
                work_summary:{placement_established:true,unused_completed_count:1,awaiting_grade_count:1,awaiting_grade_milli:3000},
                allocation:{outcome:'feasible',groups:[{name:'General education <b>fictional</b>',requirement_type:'credits',required_credits_milli:12000,minimum_grade_points_milli:2000,placements:[{course_code:'MATH.101',course_title:'Retained Algebra',credits_milli:3000,via_substitution:false}]}]},
                records:Array.from({length:21},(_,index)=>({course_code:index===20?'LAST.101':'MATH.101',course_title:'Retained work <b>fictional</b>',term_code:'26/FA',term_label_retained:true,academic_state:index===0?'awaiting_grade':'completed',placement:'not_used',credit:'included',cumulative_gpa:'included',overall_gpa:'undetermined'})),
            };
        } else throw new Error(`Unexpected private request ${path}`);
        if (path.includes(`/programs/${id(44)}/`) && data.context) data.context.name = 'Second selected program';
        await route.fulfill({json:data,headers:{'Cache-Control':'private, no-store'}});
    });
    await page.goto('/portal/student/progress/');
    return {calls,errors,releaseFirst,firstRequested};
}
async function accessible(page) {
    const scan=await new AxeBuilder({page}).withTags(['wcag2a','wcag2aa','wcag21aa','wcag22aa']).analyze();
    expect(scan.violations.map(v=>({id:v.id,targets:v.nodes.map(n=>n.target)}))).toEqual([]);
    expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
}
test('portal reviews only the selected pursuit on demand and shares local accessible guidance',async({page})=>{
    const f=await fixture(page,{morePrograms:true});
    await expect(page.getByRole('heading',{name:'Degree progress',exact:true})).toBeVisible();
    await expect(page.getByRole('button',{name:'Review progress · BSCS · 26/FA',exact:true})).toBeVisible();
    expect(f.calls.some(c=>c.path.endsWith('/degree-progress/'))).toBe(false);
    await page.getByRole('button',{name:'More program pursuits',exact:true}).click();
    await expect(page.getByRole('button',{name:'Review progress · DUAL · 26/FA',exact:true})).toBeVisible();
    expect(f.calls.some(c=>c.path.endsWith('/degree-progress/'))).toBe(false);
    await page.getByRole('button',{name:'Review progress · BSCS · 26/FA',exact:true}).click();
    await expect(page.getByRole('heading',{name:'B.S. Computing <script>fictional</script> · 2026-2027 · Version 1',exact:true})).toBeFocused();
    await expect(page.locator('#campus-service-content')).toContainText('Minimum met');
    await expect(page.locator('#campus-service-content')).toContainText('Needs review');
    await expect(page.locator('#campus-service-content')).toContainText('Earlier history is unknown');
    await page.getByRole('button',{name:'Open degree progress guide',exact:true}).click();
    const guide=page.getByRole('complementary',{name:'Degree progress guide',exact:true});
    await guide.getByRole('button',{name:'Understand credits and GPA',exact:true}).click();
    await expect(guide).toContainText('must not be treated as zero');
    await guide.getByRole('button',{name:'Open credit and GPA detail',exact:true}).click();
    await expect(page.getByRole('heading',{name:'Recorded work in this review',exact:true})).toBeFocused();
    await expect(page.locator('#campus-service-content')).toContainText('Showing 1–20 of 21 records');
    await page.getByRole('button',{name:'More progress records',exact:true}).click();
    await expect(page.locator('#campus-service-content')).toContainText('LAST.101');
    await guide.getByRole('button',{name:'Understand course placement',exact:true}).click();
    await guide.getByRole('button',{name:'Open course-group detail',exact:true}).click();
    await expect(page.getByRole('heading',{name:'Your selected course groups',exact:true})).toBeFocused();
    await expect(page.locator('#campus-service-content')).toContainText('Retained Algebra');
    await guide.getByRole('button',{name:'Move guide left',exact:true}).click();
    await accessible(page);
    await page.setViewportSize({width:375,height:812}); await accessible(page);
    expect(await page.locator('#campus-service-content script, #campus-service-content b').count()).toBe(0);
    expect(f.calls.filter(c=>c.path.endsWith('/degree-progress/'))).toEqual([{path:`/portal/api/students/${student}/programs/${pursuit}/degree-progress/`,body:{},csrf:'fictional-test-token'}]);
    expect(f.calls.filter(c=>c.body).every(c=>c.csrf==='fictional-test-token')).toBe(true);
    expect(await page.evaluate(()=>localStorage.length+sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});
test('missing selection and review limits do not become partial progress or student decisions',async({page})=>{
    for(const options of [{limit:true},{needsSelection:true}]) {
        const f=await fixture(page,options);
        await page.getByRole('button',{name:'Review progress · BSCS · 26/FA',exact:true}).click();
        await expect(page.locator('#campus-service-content')).toContainText(options.limit?'No partial totals or placements':'select published degree requirements');
        await expect(page.locator('.portal-progress-value')).toHaveCount(0);
        await page.getByRole('button',{name:'Open degree progress guide',exact:true}).click();
        await expect(page.getByRole('button',{name:'Understand credits and GPA',exact:true})).toHaveCount(0);
        await page.getByRole('button',{name:'Refresh degree progress',exact:true}).click();
        await expect(page.getByRole('complementary',{name:'Degree progress guide',exact:true})).toHaveCount(0);
        expect(f.calls.filter(c=>c.path.endsWith('/degree-progress/'))).toHaveLength(2);
        expect(f.errors).toEqual([]);
    }
});
test('no student and denied owner reads leave no academic result or browser storage',async({page})=>{
    let f=await fixture(page,{noStudent:true});
    await expect(page.locator('#campus-service-content')).toContainText('once you have a student record');
    expect(f.calls).toHaveLength(1);
    await page.unroute('**/portal/api/**');
    f=await fixture(page,{deny:true});
    await page.getByRole('button',{name:'Review progress · BSCS · 26/FA',exact:true}).click();
    await expect(page.getByRole('alert').filter({hasText:'valid sign-in or additional access'})).toBeVisible();
    await expect(page.locator('.portal-progress-value')).toHaveCount(0);
    expect(await page.evaluate(()=>localStorage.length+sessionStorage.length)).toBe(0);
    expect(f.errors).toEqual([]);
});


test('a slower earlier pursuit response cannot replace the latest selected program',async({page})=>{
    const f=await fixture(page,{morePrograms:true,delayFirst:true});
    await page.getByRole('button',{name:'More program pursuits',exact:true}).click();
    const first=page.getByRole('button',{name:'Review progress · BSCS · 26/FA',exact:true});
    await first.click(); await f.firstRequested;
    await page.getByRole('button',{name:'Review progress · DUAL · 26/FA',exact:true}).click();
    const selected=page.getByRole('heading',{name:'Second selected program · 2026-2027 · Version 1',exact:true});
    await expect(selected).toBeVisible();
    f.releaseFirst(); await expect(first).toBeEnabled();
    await expect(selected).toBeVisible();
    await expect(page.getByRole('heading',{name:'B.S. Computing <script>fictional</script> · 2026-2027 · Version 1',exact:true})).toHaveCount(0);
    expect(f.calls.filter(c=>c.path.endsWith('/degree-progress/'))).toHaveLength(2);
    expect(f.errors).toEqual([]);
});
