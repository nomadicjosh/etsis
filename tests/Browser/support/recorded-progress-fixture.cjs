const { expect } = require('@playwright/test');
const id = n => String(n).padStart(26,'0');
const summary = () => ({as_of_date:'2026-08-10',outcome:'infeasible',sharing:'one_component',current_record_count:2,
    components:[{label:'Degree',context:{name:'Computing <b>fictional</b>',academic_year_code:'2026-2027',version:1},
        minimums:[{key:'min_credits_milli',required_milli:120000,actual_minimum_milli:6000,actual_maximum_milli:6000,status:'below_minimum'}],
        gpa:{cumulative:{ready:true,milli:4000},overall:{ready:false,milli:null}},
        groups:[{name:'Foundations',requirement_type:'credits',required_credits_milli:12000,required_courses:0,coverage:null}]}]});
async function recordedFixture(page, pattern, {canRecord=false, uncertain=false, empty=false}={}) {
    const calls=[];let recorded=!empty;
    await page.route(pattern,async route=>{
        const request=route.request(), path=new URL(request.url()).pathname;
        const raw=request.postData();
        const data=raw?JSON.parse(new URLSearchParams(raw).get('_workspace_json')):null;
        calls.push({path,data});
        if(data) expect(request.headers()['x-csrf-token']).toBeTruthy();
        let response;
        if(path.endsWith('/page/')) response={items:recorded?[{id:id(60),as_of_date:'2026-08-10',recorded_at:'2026-08-10T12:00:00+00:00',outcome:'infeasible',component_count:1}]:[],next_after:null,can_record:canRecord};
        else if(path.endsWith('/review/')) response={operation_id:id(60),revision:'a'.repeat(64),reason:data.reason,summary:summary(),review_token:'fictional-private-proof'};
        else if(path.endsWith('/confirm/')) {
            recorded=true;
            if(uncertain){uncertain=false;await route.fulfill({status:503,json:{error:'Fictional connection interruption; retry confirmation.'}});return;}
            response={status:'recorded',operation_id:id(60)};
        } else response={id:id(60),recorded_at:'2026-08-10T12:00:00+00:00',summary:summary()};
        await route.fulfill({json:response});
    });
    return {calls};
}
module.exports={recordedFixture};
