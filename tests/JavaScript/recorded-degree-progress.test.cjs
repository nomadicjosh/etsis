'use strict';
const test=require('node:test'),assert=require('node:assert/strict');
const vm=require('node:vm'),fs=require('node:fs');
const {node,walk}=require('./support/elements.cjs');
const wait=()=>new Promise(resolve=>setImmediate(resolve));
const summary=()=>({as_of_date:'2026-08-10',outcome:'feasible',current_record_count:1,components:[{label:'Degree',context:{name:'Fictional degree',academic_year_code:'2026',version:1},minimums:[],gpa:{cumulative:{ready:true,milli:4000},overall:{ready:false,milli:null}},groups:[]}]});
function fixture({owner=false,uncertain=false,deferred=false}={}) {
    const window={};vm.runInNewContext(fs.readFileSync('public/assets/js/degree-progress-guide.js','utf8'),{window});
    const sent=[];let release;
    const grant={operation_id:'record',revision:'revision',reason:'Fictional reason',review_token:'private-proof',summary:summary()};
    const c=window.createRecordedDegreeProgress({node,request:async(path,method,data)=>{
        sent.push({path,method,data});
        if(path.endsWith('/page/'))return {items:[],can_record:true,next_after:null};
        if(path.endsWith('/review/')){
            if(deferred)await new Promise(resolve=>{release=resolve;});
            return {...grant,reason:data.reason};
        }
        if(path.endsWith('/confirm/')){
            if(uncertain){uncertain=false;throw new Error('Fictional interrupted confirmation');}
            return {operation_id:'record'};
        }
        return {summary:summary(),recorded_at:'2026-08-10'};
    }},'/records/',!owner);
    const find=label=>walk(c.element).find(e=>e.tag==='button'&&e.textContent===label);
    return {c,sent,find,release:()=>release(),input:()=>walk(c.element).find(e=>e.tag==='textarea'),submit:()=>walk(c.element).find(e=>e.tag==='form').events.submit({preventDefault(){}})};
}
test('owner history cannot offer recording even if an unexpected response advertises it',async()=>{
    const f=fixture({owner:true});await f.c.start();assert.equal(f.find('Record a new progress review'),undefined);
    assert.equal(f.sent.length,1);
});
test('revise preserves the reason and an uncertain confirmation retries the original proof',async()=>{
    const f=fixture({uncertain:true});await f.c.start();await f.find('Record a new progress review').events.click();
    f.input().value='Fictional reason';f.input().events.input();f.submit();await wait();
    await f.find('Revise recorded review').events.click();assert.equal(f.input().value,'Fictional reason');
    f.submit();await wait();await f.find('Confirm recorded progress').events.click();
    assert.match(f.c.element.textContent,/Fictional interrupted confirmation/);
    await f.find('Confirm recorded progress').events.click();
    const confirmations=f.sent.filter(x=>x.path.endsWith('/confirm/'));
    assert.equal(confirmations.length,2);assert.deepEqual(confirmations[0].data,confirmations[1].data);
    assert.match(f.c.element.textContent,/Progress review recorded/);
});
test('editing during a delayed review preserves the new draft and discards the old response',async()=>{
    const f=fixture({deferred:true});await f.c.start();await f.find('Record a new progress review').events.click();
    f.input().value='Original reason';f.input().events.input();f.submit();await wait();
    f.input().value='Changed reason';f.input().events.input();f.release();await wait();
    assert.equal(f.input().value,'Changed reason');assert.equal(f.find('Confirm recorded progress'),undefined);
});
