const {test,expect}=require('@playwright/test');
const AxeBuilder=require('@axe-core/playwright').default;
const {workspaceTool}=require('./support/workspace-actions.cjs');
const exact={rounding:'none',decimal_places:null,cap_at_hundred:false};
async function fixture(page,mode='retry') {
    const calls=[],errors=[];let reviews=0,confirms=0,version=0,release,first;
    const requested=new Promise(resolve=>{first=resolve;});
    const delayed=new Promise(resolve=>{release=resolve;});
    page.on('pageerror',e=>errors.push(e.message));
    await page.route('**/sis/**',async route=>{
        const request=route.request(),path=new URL(request.url()).pathname;
        const body=request.postData()?JSON.parse(new URLSearchParams(request.postData()).get('_workspace_json')):null;
        calls.push({path,body,csrf:request.headers()['x-csrf-token']});
        let result={items:[],next_after:null};
        if(path==='/sis/ui/metrics/')result={cards:[],intake:[],pipeline:[],queue:[]};
        if(path==='/sis/grade-comparison-policy/')result={rules:exact,version,publication_id:null};
        if(path.endsWith('/grade-comparison-policy/review/')) {
            reviews++;
            if(mode==='delayed'&&reviews===1){first();await delayed;}
            if(mode==='stale'&&reviews===1){version=1;return route.fulfill({status:409,json:{error:'Policy changed. Refresh its source.'}});}
            result={...body,publication_id:'00000000000000000000000001',review_token:'fictional-proof'};
        }
        if(path.endsWith('/grade-comparison-policy/publish/')) {
            confirms++;
            if(mode==='denied')return route.fulfill({status:403,json:{error:'Access denied.'}});
            if(mode==='retry'&&confirms===1)return route.fulfill({status:503,json:{error:'Fictional interrupted response.'}});
            result={status:'published',version:version+1,publication_id:body.publication_id};
        }
        await route.fulfill({json:result,headers:{'Cache-Control':'private, no-store'}});
    });
    await page.goto('/admin/');await page.locator('.workspace-nav-link[data-workspace="students"]').click();await workspaceTool(page,'Publish institutional grade comparison policy');
    return {calls,errors,requested,release};
}
async function fill(page) {
    await page.getByLabel('Rounding rule',{exact:true}).selectOption('half_even');
    await page.getByLabel('Decimal places',{exact:true}).selectOption('2');
    await page.getByLabel('Reason for policy publication',{exact:true}).fill('Fictional policy <img src=x onerror=alert(1)>');
}
test('institutional publication retains revised rules and retries the original reviewed proof',async({page})=>{
    const f=await fixture(page);await fill(page);
    await page.getByRole('button',{name:'Review institutional policy',exact:true}).click();
    await expect(page.locator('#drawer-body')).toContainText('Proposed:');
    await expect(page.locator('#drawer-body')).toContainText('Explicit section overrides and posted grades keep their recorded rules');
    await page.getByRole('button',{name:'Revise policy',exact:true}).click();
    await expect(page.getByLabel('Rounding rule',{exact:true})).toHaveValue('half_even');
    await expect(page.getByLabel('Reason for policy publication',{exact:true})).toHaveValue('Fictional policy <img src=x onerror=alert(1)>');
    await page.setViewportSize({width:320,height:900});
    const scan=await new AxeBuilder({page}).withTags(['wcag2a','wcag2aa','wcag21aa','wcag22aa']).analyze();expect(scan.violations.map(v=>v.id)).toEqual([]);
    expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBe(true);
    await page.getByRole('button',{name:'Review institutional policy',exact:true}).click();
    await expect(page.locator('#drawer-body img')).toHaveCount(0);
    await page.getByRole('button',{name:'Publish comparison policy',exact:true}).click();
    await expect(page.locator('#drawer-body')).toContainText('Fictional interrupted response');
    await page.getByRole('button',{name:'Publish comparison policy',exact:true}).click();
    await expect(page.locator('#drawer-body')).toContainText('version 1 published');
    const confirms=f.calls.filter(c=>c.path.endsWith('/publish/'));expect(confirms).toHaveLength(2);expect(confirms[0]).toEqual(confirms[1]);
    expect(confirms[0].csrf).toBe('fictional-test-token');expect(await page.evaluate(()=>localStorage.length+sessionStorage.length)).toBe(0);expect(f.errors).toEqual([]);
});
test('refreshing an institutional policy source preserves the desired rules and reason',async({page})=>{
    const f=await fixture(page,'stale');await fill(page);
    await page.getByRole('button',{name:'Review institutional policy',exact:true}).click();
    await expect(page.locator('#drawer-body')).toContainText('Refresh its source');
    await page.getByRole('button',{name:'Refresh policy source',exact:true}).click();
    await expect(page.getByLabel('Rounding rule',{exact:true})).toHaveValue('half_even');
    await page.getByRole('button',{name:'Review institutional policy',exact:true}).click();
    await expect(page.locator('#drawer-body')).toContainText('Current publication: 1');
    expect(f.calls.filter(c=>c.path.endsWith('/review/')).at(-1).body.version).toBe(1);expect(f.errors).toEqual([]);
});
test('publication authority loss clears the current reason and proof',async({page})=>{
    const f=await fixture(page,'denied');await fill(page);
    await page.getByRole('button',{name:'Review institutional policy',exact:true}).click();
    await page.getByRole('button',{name:'Publish comparison policy',exact:true}).click();
    await expect(page.locator('#drawer-body')).toContainText('Policy access changed');
    await expect(page.locator('#drawer-body')).not.toContainText('Fictional policy');
    await expect(page.getByRole('button',{name:'Publish comparison policy',exact:true})).toHaveCount(0);expect(f.errors).toEqual([]);
});
test('editing while institutional review is delayed keeps the newer draft',async({page})=>{
    const f=await fixture(page,'delayed');await fill(page);
    await page.getByRole('button',{name:'Review institutional policy',exact:true}).click();await f.requested;
    await page.getByLabel('Reason for policy publication',{exact:true}).fill('Fictional newer draft');f.release();
    await expect(page.getByLabel('Reason for policy publication',{exact:true})).toHaveValue('Fictional newer draft');
    await expect(page.getByRole('button',{name:'Publish comparison policy',exact:true})).toHaveCount(0);
    await page.getByRole('button',{name:'Review institutional policy',exact:true}).click();
    await expect(page.locator('#drawer-body')).toContainText('Fictional newer draft');expect(f.errors).toEqual([]);
});
