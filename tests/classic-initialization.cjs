'use strict';
// Isolated control-flow checks; these do not establish WordPress compatibility.
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict'),path=require('node:path');
const source=path.join(process.argv[2] ? path.resolve(process.argv[2]) : path.resolve(__dirname,'..'),'assets/js/metabox.js');
const code=fs.readFileSync(source,'utf8'),results=[];
function fixture(o={}){
 const elements=new Map(),requests=[],timers=new Map(),subs=[];let ready,clock=0;
 const doc={body:{classList:{contains:()=>!!o.block}},getElementById:()=>null,createTextNode:text=>({textContent:text})};
 function $(s){if(typeof s==='function'){ready=s;return;}if(!elements.has(s))elements.set(s,{value:s==='#content'?(o.textarea===undefined?'STALE TEXTAREA':o.textarea):'',textValue:'',handlers:{},val(){return this.value;},text(v){if(v===undefined)return this.textValue;this.textValue=String(v);return this;},attr(){return this;},on(events,...args){this.handlers[events]=args.at(-1);return this;},trigger(){return this;},map(){return {get:()=>[]};},each(){return this;},empty(){return this;},append(){return this;},appendTo(){return this;},addClass(){return this;},prop(){return this;},toggleClass(){return this;}});return elements.get(s);}
 $.trim=s=>s.trim();
 let store=o.store===false?null:{getCurrentPostId:()=>o.id===undefined?42:o.id,getCurrentPostType:()=>o.type===undefined?'post':o.type,getCurrentPost:()=>o.record===undefined?{id:42}:o.record,getEditedPostContent:()=>o.content===undefined?'BLOCK CONTENT':o.content,getEditedPostAttribute:()=>''};
 if(o.missingSelector&&store)delete store[o.missingSelector];
 let tiny=o.tiny?{id:'content',initialized:o.initialized!==false,bindings:0,hidden:!!o.hidden,value:o.tinyContent===undefined?'TINY CONTENT':o.tinyContent,isHidden(){return this.hidden;},getContent(){return this.value;},on(events,fn){this.bindings++;this.events=events;this.handler=fn;}}:null;
 const wp={data:{select:()=>store,subscribe:fn=>subs.push(fn),dispatch(){}},apiFetch(options){return new Promise((resolve,reject)=>requests.push({options,resolve,reject}));}};
 vm.runInNewContext(code,{jQuery:$,document:doc,window:{wp,mwseoBox:{postId:42,hasGsc:false,i18n:{error:'Analysis error',na:'N/A'}},tinyMCE:{get:()=>tiny}},setTimeout(fn){const id=++clock;timers.set(id,fn);return id;},clearTimeout(id){timers.delete(id);}});
 ready();
 return {o,$,doc,requests,subs,get tiny(){return tiny;},setTiny(t){tiny=t;},flush(){const fns=[...timers.values()];timers.clear();fns.forEach(fn=>fn());},update(){subs.forEach(fn=>fn());},async settle(){await Promise.resolve();await Promise.resolve();await Promise.resolve();},body(){return requests.at(-1)?.options.data.content;}};
}
async function test(name,fn){try{await fn();results.push({name,status:'passed'});console.log('PASS '+name);}catch(e){results.push({name,status:'failed',error:e.stack});console.log('FAIL '+name+': '+e.message);}}
(async()=>{
 await test('Uninitialized visible TinyMCE uses populated textarea',()=>{const f=fixture({tiny:true,initialized:false,tinyContent:'',textarea:'EXISTING ARTICLE'});assert.equal(f.body(),'EXISTING ARTICLE');});
 await test('Uninitialized empty article remains empty',()=>{const f=fixture({tiny:true,initialized:false,tinyContent:'',textarea:''});assert.equal(f.body(),'');});
 await test('Native init refreshes an already bound editor without user input',()=>{const f=fixture({tiny:true,initialized:false,tinyContent:'',textarea:'EXISTING ARTICLE'});f.flush();assert.equal(f.body(),'EXISTING ARTICLE');assert.match(f.tiny.events,/(^| )init( |$)/);f.tiny.initialized=true;f.tiny.value='INITIALIZED ARTICLE';f.tiny.handler();f.flush();assert.equal(f.body(),'INITIALIZED ARTICLE');assert.equal(f.tiny.bindings,1);});
 await test('Initialization invalidates a pending pre-init result',async()=>{const f=fixture({tiny:true,initialized:false,tinyContent:'',textarea:'EXISTING ARTICLE'});const old=f.requests[0];f.tiny.initialized=true;f.tiny.value='CURRENT ARTICLE';f.tiny.handler();old.resolve({score:9,verdict:'Old',checks:[]});await f.settle();assert.equal(f.$('#mwseo-ring-num').textValue,'');f.flush();assert.equal(f.body(),'CURRENT ARTICLE');});
 await test('Clearing initialized Visual content never resurrects textarea',()=>{const f=fixture({tiny:true,textarea:'OLD ARTICLE'});f.tiny.value='';f.tiny.handler();f.flush();assert.equal(f.body(),'');});
 await test('Visual and Text switches use the active source including empty text',()=>{const f=fixture({tiny:true,textarea:'TEXT ARTICLE'});f.tiny.hidden=true;f.$('#content-tmce, #content-html').handlers.click();f.flush();f.flush();assert.equal(f.body(),'TEXT ARTICLE');f.$('#content').value='';f.$('#content').handlers['input change']();f.flush();assert.equal(f.body(),'');f.tiny.hidden=false;f.tiny.value='VISUAL ARTICLE';f.$('#content-tmce, #content-html').handlers.click();f.flush();f.flush();assert.equal(f.body(),'VISUAL ARTICLE');});
 for(const store of [true,false]){
  await test('Classic visible TinyMCE '+store,()=>{const f=fixture({store,tiny:true});assert.equal(f.body(),'TINY CONTENT');});
  await test('Classic empty TinyMCE does not fall back '+store,()=>{const f=fixture({store,tiny:true,tinyContent:''});assert.equal(f.body(),'');});
  await test('Classic hidden TinyMCE chooses textarea '+store,()=>{const f=fixture({store,tiny:true,hidden:true,textarea:'TEXTAREA CONTENT'});assert.equal(f.body(),'TEXTAREA CONTENT');});
  await test('Classic empty textarea does not fall back '+store,()=>{const f=fixture({store,tiny:true,hidden:true,textarea:''});assert.equal(f.body(),'');});
  await test('Classic textarea handler installed '+store,()=>{const f=fixture({store,textarea:'before'});f.$('#content').value='after';f.$('#content').handlers['input change']();f.flush();assert.equal(f.body(),'after');});
 }
 await test('Block content overrides stale Classic sources',()=>{assert.equal(fixture({block:true,tiny:true}).body(),'BLOCK CONTENT');});
 await test('Legitimate empty Block body stays empty',()=>{assert.equal(fixture({block:true,tiny:true,content:''}).body(),'');});
 for(const options of [{store:false},{id:null},{id:0},{id:99},{type:''},{record:{}},{record:{id:99}},{missingSelector:'getCurrentPost'},{content:null}])await test('Unavailable Block context '+JSON.stringify(options),()=>{assert.equal(fixture({block:true,...options}).requests.length,0);});
 await test('Block becomes ready with empty content',()=>{const f=fixture({block:true,id:null,content:''});assert.equal(f.requests.length,0);f.o.id=42;f.update();f.flush();assert.equal(f.body(),'');assert.equal(f.requests.length,1);});
 await test('Block content-only updates and clearing',()=>{const f=fixture({block:true});f.o.content='new';f.update();f.flush();assert.equal(f.body(),'new');f.o.content='';f.update();f.flush();assert.equal(f.body(),'');});
 await test('No stale response after content clears',async()=>{const f=fixture({tiny:true});const old=f.requests[0];f.tiny.value='';f.tiny.handler();old.resolve({score:39,verdict:'Old',checks:[]});await f.settle();assert.equal(f.$('#mwseo-ring-num').textValue,'');f.flush();f.requests.at(-1).resolve({score:9,verdict:'Empty',checks:[]});await f.settle();assert.equal(f.$('#mwseo-ring-num').textValue,'9');});
 await test('No stale error after later content request',async()=>{const f=fixture({tiny:true});const old=f.requests[0];f.tiny.value='';f.tiny.handler();f.flush();f.requests.at(-1).resolve({score:9,verdict:'Empty',checks:[]});await f.settle();old.reject(new Error('Old failure'));await f.settle();assert.equal(f.$('#mwseo-verdict').textValue,'Empty');});
 await test('Context loss invalidates pending response',async()=>{const f=fixture({block:true});f.o.id=null;f.update();f.requests[0].resolve({score:39,verdict:'Old',checks:[]});await f.settle();assert.equal(f.$('#mwseo-ring-num').textValue,'');});
 await test('Existing TinyMCE binds once; other editor ignored',()=>{const f=fixture({tiny:true});assert.equal(f.tiny.bindings,1);const init=f.$(f.doc).handlers['tinymce-editor-init'];init({},f.tiny);assert.equal(f.tiny.bindings,1);let calls=0;init({},{id:'other',on(){calls++;}});assert.equal(calls,0);});
 await test('Later and replacement TinyMCE bind once each',()=>{const f=fixture();const init=f.$(f.doc).handlers['tinymce-editor-init'];for(const value of ['later','replacement']){const ed={id:'content',initialized:true,bindings:0,isHidden:()=>false,getContent:()=>value,on(e,fn){this.bindings++;this.events=e;this.handler=fn;}};f.setTiny(ed);init({},ed);init({},ed);f.flush();assert.equal(f.body(),value);assert.equal(ed.bindings,1);}});
 console.log(`${results.filter(r=>r.status==='passed').length} passed; ${results.filter(r=>r.status==='failed').length} failed.`);process.exitCode=results.some(r=>r.status==='failed')?1:0;
})();
