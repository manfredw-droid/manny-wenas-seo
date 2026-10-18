/** Native editor integration checks with store/network doubles. Real WordPress checks are separate. */
'use strict';
const fs=require('node:fs'), vm=require('node:vm'), assert=require('node:assert/strict'), path=require('node:path');
const root=path.resolve(__dirname,'..');
const source=name=>fs.readFileSync(path.join(root,'assets/js',name+'.js'),'utf8');
let passed=0;
async function test(name,run){await run();passed++;console.log('PASS '+name);}
function fixture(){
 const timers=new Map(),requests=[],subscribers=[],frames=[],edits=[]; let next=0;
 const post={id:42}, attributes={meta:{_mwseo_focus:'garden',_mwseo_related:[],_mwseo_title:'Garden',_mwseo_desc:'Description'},slug:'garden'};
 let ready=true,content='<p>Garden</p>';
 const ed={getCurrentPostId:()=>ready?42:0,getCurrentPost:()=>ready?post:null,getEditedPostAttribute:key=>attributes[key],getEditedPostContent:()=>content};
 const document={getElementById:()=>null,querySelectorAll:()=>[]};
 const wp={data:{select:()=>ed,subscribe:fn=>{subscribers.push(fn);return()=>{};},dispatch:()=>({editPost:patch=>{edits.push(patch);Object.assign(attributes.meta,patch.meta);subscribers.slice().forEach(fn=>fn());}})},
  apiFetch:options=>new Promise((resolve,reject)=>requests.push({options,resolve,reject}))};
 const window={wp,mwseoCompanionCfg:{postId:42,ownsTitle:false,fallback:{},i18n:{error:'Localized error',analysing:'Analysing'}},
  DOMParser:class {parseFromString(s){return{body:{textContent:s.replace(/<[^>]*>/g,'')}};}},requestAnimationFrame:fn=>frames.push(fn)};
 const context=vm.createContext({window,document,setTimeout:fn=>{timers.set(++next,fn);return next;},clearTimeout:id=>timers.delete(id)});
 vm.runInContext(source('companion-core'),context);
 const flush=async()=>{await Promise.resolve();await Promise.resolve();await Promise.resolve();};
 return {window,wp,context,attributes,requests,edits,frames,core:window.mwseoCompanion,
  emit:()=>subscribers.slice().forEach(fn=>fn()),runTimer:()=>{const current=[...timers.values()];timers.clear();current.forEach(fn=>fn());},
  content:value=>{content=value;subscribers.slice().forEach(fn=>fn());},ready:value=>{ready=value;subscribers.slice().forEach(fn=>fn());},
  success:async(i,result)=>{requests[i].resolve(result);await flush();},failure:async i=>{requests[i].reject(new Error('failure'));await flush();}};
}
const result=score=>({score,state:'ok',verdict:{summary:'Current'},checks:[],notices:[]});
(async()=>{
 await test('Mount and repeated store emits create no metadata or slug writes',()=>{const f=fixture();f.emit();f.emit();f.runTimer();assert.equal(f.edits.length,0);assert.equal(f.requests.length,1);assert.equal(f.requests[0].options.data.focus,'garden');});
 await test('Early store load waits for the active post and remains safe on repeated ready callbacks (PR28 guarantee)',()=>{const f=fixture();f.ready(false);f.runTimer();assert.equal(f.requests.length,0);f.ready(true);f.emit();f.emit();f.runTimer();assert.equal(f.requests.length,1);assert.equal(f.edits.length,0);});
 await test('Edits patch exactly the chosen key, including intentional empty and false',()=>{const f=fixture();f.core.edit('focus','');f.core.edit('noindex',false);assert.deepEqual(JSON.parse(JSON.stringify(f.edits)),[{meta:{_mwseo_focus:''}},{meta:{_mwseo_noindex:false}}]);assert.equal(f.attributes.meta._mwseo_title,'Garden');});
 await test('Unsaved focus, title, description, related, native slug and content feed one debounced analysis',()=>{const f=fixture();f.core.edit('focus','new');f.core.edit('related',['one','two']);f.attributes.slug='native-slug';f.content('<p>Updated</p>');f.runTimer();assert.equal(f.requests.length,1);assert.equal(f.requests[0].options.data.slug,'native-slug');assert.equal(f.requests[0].options.data.focus,'new');assert.equal(f.requests[0].options.data.content,'<p>Updated</p>');assert.deepEqual(JSON.parse(JSON.stringify(f.requests[0].options.data.related)),['one','two']);});
 await test('Changing input invalidates an older success immediately, including during debounce',async()=>{const f=fixture();f.runTimer();f.content('<p>New</p>');await f.success(0,result(99));assert.equal(f.core.latest(),null);f.runTimer();await f.success(1,result(41));assert.equal(f.core.latest().score,41);});
 await test('Current failure clears result and subscribers; recovery restores result',async()=>{const f=fixture();let view;f.core.onScore(res=>view=res);f.runTimer();await f.success(0,result(75));assert.equal(view.score,75);f.content('changed');f.runTimer();await f.failure(1);assert.equal(view,null);assert.equal(f.core.ringState(null),'none');assert.equal(f.core.verdictText(null),'Localized error');f.content('recover');f.runTimer();await f.success(2,result(88));assert.equal(view.score,88);assert.equal(f.window.mwseoAnalysisError,'');});
 await test('Older failure cannot clear a newer success; unmount unsubscribes',async()=>{const f=fixture();let count=0;const off=f.core.onScore(()=>count++);f.runTimer();f.content('changed');f.runTimer();await f.success(1,result(90));await f.failure(0);assert.equal(f.core.latest().score,90);assert.equal(count,1);off();f.content('again');f.runTimer();await f.success(2,result(70));assert.equal(count,1);});
 for(const modern of [false,true])await test((modern?'Modern':'WP6.4')+' nested heading and intro navigation performs no content edits',()=>{
  const f=fixture(),attrs={h:{level:2,content:'<b>First</b>'},nested:{level:3,content:'Nested'},h4:{level:4,content:'Skip'},empty:{content:' '},intro:{content:'Intro'}},ids=['group','h','empty','nested','intro','h4'],names={group:'core/group',h:'core/heading',nested:'core/heading',h4:'core/heading',empty:'core/paragraph',intro:'core/paragraph'};
  const be={getClientIdsWithDescendants:()=>ids,getBlockName:id=>names[id],getBlockAttributes:id=>attrs[id]};if(modern)be.getBlocksByName=name=>ids.filter(id=>names[id]===name);
  const selected=[];Object.assign(f.wp,{plugins:{registerPlugin(){}},components:{},editor:{PluginSidebar(){}},primitives:{},element:{createElement(){}},data:{select:()=>be,dispatch:()=>({selectBlock:id=>selected.push(id)})}});
  vm.runInContext(source('companion-block').replace('wp.plugins.registerPlugin( NAME,','core.testHelpers={headingSignature,firstParagraphId,goToBlock}; wp.plugins.registerPlugin( NAME,'),f.context);
  const h=f.core.testHelpers;assert.equal(h.headingSignature(()=>be),JSON.stringify([['h',2,'First'],['nested',3,'Nested']]));assert.equal(h.firstParagraphId(),'intro');h.goToBlock('nested');f.frames.shift()();assert.deepEqual(selected,['nested']);assert.equal(f.edits.length,0);
 });
 console.log(`${passed} focused native editor checks passed.`);
})().catch(error=>{console.error(error);process.exitCode=1;});
