'use strict';
// Isolated control-flow checks for the Gutenberg slug boot subscription in assets/js/metabox.js.
// They emulate @wordpress/data's emit semantics (listeners are called from a snapshot, so a listener
// that was just unsubscribed can still be called once more) and do not establish WordPress compatibility.
// node tests/slug-boot-subscription.cjs [plugin-root]
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict'),path=require('node:path');
const source=path.join(process.argv[2] ? path.resolve(process.argv[2]) : path.resolve(__dirname,'..'),'assets/js/metabox.js');
const code=fs.readFileSync(source,'utf8'),results=[];

function fixture(o={}){
 const elements=new Map(),timers=new Map();let ready,clock=0,unsubscribes=0,uncaught=[];
 let postId=o.initialId===undefined?null:o.initialId;
 const field={value:'native-slug',readOnly:true,bound:0};
 const help={textValue:'',text(v){if(v===undefined)return this.textValue;this.textValue=String(v);return this;}};
 const reg=(()=>{let listeners=[],first=null;return {
  subscribe(fn){listeners.push(fn);if(!first)first=fn;if(o.readyDuringSubscribe){postId=42;try{fn();}catch(e){uncaught.push(e);}}let live=true;return ()=>{if(live){live=false;unsubscribes++;}listeners=listeners.filter(x=>x!==fn);};},
  emit(){[...listeners].forEach(fn=>fn());},
  size(){return listeners.length;},
  get first(){return first;}};})();
 const store={getCurrentPostId:()=>postId,getCurrentPostType:()=>'post',getCurrentPost:()=>postId?{id:postId}:{},getEditedPostContent:()=>'BLOCK CONTENT',
  getEditedPostAttribute:a=>a==='slug'?'native-slug':'',getPostEdits:()=>({}),isSavingPost:()=>false,isAutosavingPost:()=>false,didPostSaveRequestSucceed:()=>true};
 const dispatchers={'core/editor':{editPost(){}},core:{invalidateResolution(){}}};
 function $(s){
  if(typeof s==='function'){ready=s;return;}
  if(s===field){return {on(){field.bound++;return this;}};}
  if(s==='#mwseo-slug-help')return help;
  if(!elements.has(s))elements.set(s,{value:'',textValue:'',val(){return this.value;},text(v){if(v===undefined)return this.textValue;this.textValue=String(v);return this;},attr(){return this;},on(){return this;},trigger(){return this;},map(){return {get:()=>[]};},each(){return this;},empty(){return this;},append(){return this;},appendTo(){return this;},addClass(){return this;},prop(){return this;},toggleClass(){return this;}});
  return elements.get(s);
 }
 $.trim=s=>s.trim();
 const doc={body:{classList:{contains:c=>c==='block-editor-page'}},getElementById:id=>id==='mwseo_slug'?field:null,createTextNode:t=>({textContent:t})};
 const wp={data:{select:name=>name==='core/editor'?store:undefined,dispatch:name=>dispatchers[name],subscribe:fn=>reg.subscribe(fn),resolveSelect:()=>({getEntityRecord:()=>Promise.resolve({slug:'native-slug'})})},apiFetch(){return new Promise(()=>{});}};
 vm.runInNewContext(code,{jQuery:$,document:doc,window:{wp,mwseoBox:{postId:42,hasGsc:false,i18n:{error:'Analysis error',na:'N/A',slugNative:'Waiting for the editor'}},tinyMCE:null},setTimeout(fn){const id=++clock;timers.set(id,fn);return id;},clearTimeout(id){timers.delete(id);}});
 return {field,help,reg,store,
  boot(){try{ready();}catch(e){uncaught.push(e);}},
  setId(id){postId=id;},
  emit(){try{reg.emit();}catch(e){uncaught.push(e);}},
  get uncaught(){return uncaught;},
  get unsubscribes(){return unsubscribes;}};
}
async function test(name,fn){try{await fn();results.push({name,status:'passed'});console.log('PASS '+name);}catch(e){results.push({name,status:'failed',error:e.stack});console.log('FAIL '+name+': '+e.message);}}

(async()=>{
 // One adapter initialisation binds exactly three field handlers (input, change/blur, keydown).
 const ONCE=3;

 await test('Editor already ready at load: adapter starts once, no boot subscription',()=>{
  const f=fixture({initialId:42});f.boot();
  assert.equal(f.uncaught.length,0);assert.equal(f.field.bound,ONCE);assert.equal(f.field.readOnly,false);
  f.emit();f.emit();assert.equal(f.field.bound,ONCE);assert.equal(f.unsubscribes,0);
 });

 await test('Delayed editor initialisation boots the adapter exactly once',()=>{
  const f=fixture();f.boot();
  assert.equal(f.field.readOnly,true);assert.equal(f.field.bound,0);
  f.emit();f.emit();assert.equal(f.field.bound,0,'not ready yet');
  f.setId(42);f.emit();
  assert.equal(f.uncaught.length,0);assert.equal(f.field.bound,ONCE);assert.equal(f.field.readOnly,false);
  assert.equal(f.unsubscribes,1);
  f.emit();f.emit();assert.equal(f.field.bound,ONCE,'later emits must not initialise again');
 });

 await test('Stale boot callback invoked again after the adapter started is a no-op',()=>{
  const f=fixture();f.boot();
  const stale=f.reg.first;          // the boot listener (first subscription), as still held by an emit snapshot
  f.setId(42);f.emit();             // normal path: boot, unsubscribe, start the adapter
  assert.equal(f.field.bound,ONCE);
  assert.doesNotThrow(()=>{stale();stale();});
  assert.equal(f.field.bound,ONCE);assert.equal(f.unsubscribes,1);
 });

 await test('Recursive emit: an earlier subscriber dispatches while the editor becomes ready',()=>{
  const f=fixture();
  // Third-party listener subscribed before the plugin boots; it emits again from inside its own call.
  let fired=false;f.reg.subscribe(()=>{if(!fired&&f.store.getCurrentPostId()){fired=true;f.reg.emit();}});
  f.boot();f.setId(42);f.emit();
  assert.equal(fired,true);
  assert.equal(f.uncaught.length,0,String(f.uncaught[0]));
  assert.equal(f.field.bound,ONCE,'adapter initialised once despite the nested emit');
  assert.equal(f.field.readOnly,false);assert.equal(f.unsubscribes,1);
 });

 await test('Callback running before subscribe() returns is ignored; a later emit boots once',()=>{
  // The editor turns ready while subscribe() is still on the stack: slugBootSubscription is not assigned yet.
  const f=fixture({readyDuringSubscribe:true});f.boot();
  assert.equal(f.uncaught.length,0,String(f.uncaught[0]));assert.equal(f.field.bound,0);
  f.emit();f.emit();
  assert.equal(f.uncaught.length,0,String(f.uncaught[0]));assert.equal(f.field.bound,ONCE);assert.equal(f.field.readOnly,false);assert.equal(f.unsubscribes,1);
 });

 await test('Editor that never becomes ready: no error, adapter stays unavailable',()=>{
  const f=fixture();f.boot();
  for(let i=0;i<20;i++)f.emit();
  assert.equal(f.uncaught.length,0);assert.equal(f.field.readOnly,true);assert.equal(f.field.bound,0);assert.equal(f.unsubscribes,0);
 });

 const failed=results.filter(r=>r.status==='failed').length;
 console.log(results.length-failed+'/'+results.length+' passed');
 process.exit(failed?1:0);
})();
