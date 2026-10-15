/** Focused isolated tests; no browser, network, database or dependencies. */
'use strict';
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const root = process.argv[2] ? path.resolve(process.argv[2]) : path.resolve(__dirname, '..');
const source = name => fs.readFileSync(path.join(root, 'assets/js', name + '.js'), 'utf8');
let passed = 0;
async function test(name, run) {
  if (process.argv[3] && !name.includes(process.argv[3])) return;
  await run(); passed++; console.log('PASS ' + name);
}
function fixture() {
  const nodes = new Map(), requests = [], timers = [], frames = [], listeners = {};
  const classes = new Set(); let ready;
  const box = { contains: node => !!node?.inside, classList: {
    toggle: (name, on) => on ? classes.add(name) : classes.delete(name),
    remove: name => classes.delete(name)
  }, addEventListener: (name, fn) => { listeners['box:' + name] = fn; } };
  const document = { body: { classList: { contains: () => false } }, activeElement: null,
    getElementById: id => id === 'mwseo-cmp' ? box : null,
    querySelectorAll: () => [], createTextNode: text => ({ textContent: text }) };
  function $(selector) {
    if (typeof selector === 'function') { ready = selector; return; }
    if (!nodes.has(selector)) nodes.set(selector, {
      value: '', textValue: '', attrs: {}, props: {}, events: {}, children: [],
      val(v) { if (!arguments.length) return this.value; this.value = v; return this; },
      text(v) { if (!arguments.length) return this.textValue; this.textValue = String(v ?? ''); return this; },
      attr(k,v) { this.attrs[k]=v; return this; },
      prop(k,v) { if (arguments.length===1) return this.props[k]; this.props[k]=v; return this; },
      on(names,...args) { for (const n of names.split(' ')) (this.events[n] ||= []).push(args.at(-1)); return this; },
      off(names,fn) { for (const n of names.split(' ')) this.events[n]=(this.events[n] || []).filter(x=>x!==fn); return this; },
      trigger(name,payload=[]) { for (const fn of [...(this.events[name] || [])]) fn({type:name},...payload); return this; },
      empty() { this.children=[]; return this; }, append(v) { this.children.push(v); return this; },
      appendTo() { return this; }, addClass() { return this; }, css() { return this; },
      toggleClass() { return this; }, map() { return {get:()=>[]}; }, each() { return this; }
    });
    return nodes.get(selector);
  }
  $.trim = s => s.trim();
  const cfg = { postId:42, hasGsc:false, i18n:{error:'Localized <error>', na:'N/A'} };
  const wp = {data:{select:()=>null,dispatch(){}}, apiFetch: options => new Promise((resolve,reject)=>requests.push({options,resolve,reject}))};
  const window = {wp,jQuery:$,mwseoBox:cfg,mwseoCompanionCfg:{i18n:{analysing:'Analysing'}},pageYOffset:0,
    localStorage:{getItem:()=>null,setItem(){}},
    DOMParser: class { parseFromString(s) { return {body:{textContent:s.replace(/<[^>]*>/g,'')}}; } },
    requestAnimationFrame:fn=>frames.push(fn),
    addEventListener:(name,fn)=>{listeners[name]=fn;}, removeEventListener:name=>{delete listeners[name];}
  };
  const context = vm.createContext({window,document,jQuery:$,setTimeout:fn=>{timers.push(fn);return timers.length;},clearTimeout(){}});
  const run = name => vm.runInContext(source(name),context,{filename:name+'.js'});
  run('companion-core');
  const flush = async()=>{await Promise.resolve();await Promise.resolve();await Promise.resolve();};
  return {$,window,document,context,run,requests,frames,listeners,classes,cfg,
    start(){run('metabox');ready();},
    update(){$('.mwseo-box').trigger('input');timers.pop()();},
    async success(i,res){requests[i].resolve(res);await flush();},
    async failure(i){requests[i].reject(new Error('network'));await flush();},
    scroll(y){window.pageYOffset=y;listeners.scroll();},
    frame(){frames.shift()();}
  };
}
const result = score => ({score,state:'ok',sections:{},verdict:{summary:'Current'},checks:[],notices:[],words:320});
async function main() {
  await test('Current failure clears cached result and both subscribed view states; recovery restores unchanged result',async()=>{
    const f=fixture(), core=f.window.mwseoCompanion, states={classic:undefined,block:undefined};
    core.onScore(res=>{states.classic=res;}); core.onScore(res=>{states.block=res;});
    const scoreEvents=[];f.$(f.document).on('mwseo:score',(e,res)=>scoreEvents.push(res));
    f.start();const first=result(75);await f.success(0,first);
    assert.equal(core.latest(),first);assert.equal(states.classic,first);assert.equal(states.block,first);
    f.$('#mwseo-checks, #mwseo-tips').append('previous');f.update();await f.failure(1);
    assert.equal(core.latest(),null);assert.equal(states.classic,null);assert.equal(states.block,null);
    assert.equal(core.ringState(null),'none');assert.equal(core.verdictText(null),f.cfg.i18n.error);
    assert.equal(f.$('#mwseo-ring-num').text(),'–');assert.equal(f.$('#mwseo-ring').attrs['data-state'],'none');
    assert.equal(f.$('#mwseo-subscores').text(),'');assert.equal(f.$('#mwseo-checks, #mwseo-tips').children.length,0);
    assert.equal(f.$('#mwseo-verdict').text(),'Localized <error>');
    assert.equal(scoreEvents.length,1,'No null payload added to public score event');
    f.update();const recovered=result(88);await f.success(2,recovered);
    assert.equal(core.latest(),recovered);assert.equal(states.block,recovered);assert.equal(f.window.mwseoAnalysisError,'');
    assert.equal(scoreEvents[1],recovered);
  });
  await test('Initial failure and late sidebar mount see no stale score',async()=>{
    const f=fixture();f.start();await f.failure(0);
    assert.equal(f.window.mwseoCompanion.latest(),null);
    assert.equal(f.window.mwseoCompanion.verdictText(null),f.cfg.i18n.error);
  });
  await test('Actual Classic view clears displayed score and checks after failure',async()=>{
    const f=fixture();f.run('companion-classic');f.start();await f.success(0,result(75));
    assert.equal(f.$('#mwseo-cmp-ring-num').text(),'75');
    f.$('#mwseo-cmp-checks').append('old check');f.update();await f.failure(1);
    assert.equal(f.$('#mwseo-cmp-ring-num').text(),'–');
    assert.equal(f.$('#mwseo-cmp-ring').attrs['data-state'],'none');
    assert.equal(f.$('#mwseo-cmp-verdict').text(),f.cfg.i18n.error);
    assert.equal(f.$('#mwseo-cmp-checks').children.length,0);
    f.update();await f.success(2,result(83));assert.equal(f.$('#mwseo-cmp-ring-num').text(),'83');
  });
  await test('Older failure cannot clear newer success',async()=>{
    const f=fixture();f.start();f.update();const latest=result(91);await f.success(1,latest);await f.failure(0);
    assert.equal(f.window.mwseoCompanion.latest(),latest);assert.equal(f.window.mwseoAnalysisError,'');
  });
  await test('Older success cannot replace current failure',async()=>{
    const f=fixture();f.start();f.update();await f.failure(1);await f.success(0,result(90));
    assert.equal(f.window.mwseoCompanion.latest(),null);assert.equal(f.$('#mwseo-ring-num').text(),'–');
  });
  await test('Unmount unsubscribes success and error handlers',()=>{
    const f=fixture();let count=0;const off=f.window.mwseoCompanion.onScore(()=>count++);off();
    f.$(f.document).trigger('mwseo:score',[result(3)]).trigger('mwseo:analysis-error');assert.equal(count,0);
  });
  for(const modern of [false,true]) await test((modern?'Modern':'WP 6.4')+' selectors find nested headings and first nonempty introduction without edits',()=>{
    const f=fixture();const attrs={h:{level:2,content:'<b>First</b>'},nested:{level:3,content:'Nested'},h4:{level:4,content:'Skip'},empty:{content:' '},intro:{content:'Introduction'}};
    const ids=['group','h','empty','nested','intro','h4'];
    const names={group:'core/group',h:'core/heading',nested:'core/heading',h4:'core/heading',empty:'core/paragraph',intro:'core/paragraph'};
    let nativeCalls=0;const be={getClientIdsWithDescendants:()=>ids,getBlockName:id=>names[id],getBlockAttributes:id=>attrs[id]};
    if(modern) be.getBlocksByName=name=>{nativeCalls++;return ids.filter(id=>names[id]===name);};
    const selected=[];Object.assign(f.window.wp,{plugins:{registerPlugin(){}},components:{},editor:{PluginSidebar(){}},primitives:{},
      element:{createElement(){},},data:{select:()=>be,dispatch:()=>({selectBlock:id=>selected.push(id)})}});
    const injected=source('companion-block').replace('wp.plugins.registerPlugin( NAME,', 'core.testHelpers = { headingSignature: headingSignature, firstParagraphId: firstParagraphId, goToBlock: goToBlock }; wp.plugins.registerPlugin( NAME,');
    vm.runInContext(injected,f.context);const h=f.window.mwseoCompanion.testHelpers;
    assert.equal(h.headingSignature(()=>be),JSON.stringify([['h',2,'First'],['nested',3,'Nested']]));
    assert.equal(h.firstParagraphId(),'intro');h.goToBlock('nested');f.frame();assert.deepEqual(selected,['nested']);
    assert.equal(nativeCalls,modern?2:0);
  });
  await test('No block store yields no headings or introduction',()=>{
    const f=fixture();Object.assign(f.window.wp,{plugins:{registerPlugin(){}},components:{},editor:{PluginSidebar(){}},primitives:{},element:{createElement(){}},data:{select:()=>null}});
    vm.runInContext(source('companion-block').replace('wp.plugins.registerPlugin( NAME,','core.testHelpers = { headingSignature: headingSignature, firstParagraphId: firstParagraphId }; wp.plugins.registerPlugin( NAME,'),f.context);
    const h=f.window.mwseoCompanion.testHelpers;assert.equal(h.headingSignature(()=>null),'[]');assert.equal(h.firstParagraphId(),null);
  });
  function classic(){const f=fixture();f.run('companion-classic');f.$('#mwseo-cmp-autohide').prop('checked',true).trigger('change');return f;}
  await test('Auto-hide hides only unfocused controls while scrolling down',()=>{
    const f=classic();f.scroll(300);f.frame();assert.equal(f.classes.has('is-away'),true);
    f.document.activeElement={inside:true};f.scroll(400);f.frame();assert.equal(f.classes.has('is-away'),false);
  });
  await test('Focus entering after scroll but before animation protects controls',()=>{
    const f=classic();f.scroll(300);f.document.activeElement={inside:true};f.frame();assert.equal(f.classes.has('is-away'),false);
  });
  await test('Focusin reveals already hidden controls',()=>{
    const f=classic();f.scroll(300);f.frame();f.listeners['box:focusin']();assert.equal(f.classes.has('is-away'),false);
  });
  await test('Disabling auto-hide with queued animation does not hide controls',()=>{
    const f=classic();f.scroll(300);f.$('#mwseo-cmp-autohide').prop('checked',false).trigger('change');f.frame();assert.equal(f.classes.has('is-away'),false);
  });
  await test('Scroll up and top-of-page keep controls visible',()=>{
    const f=classic();f.scroll(100);f.frame();assert.equal(f.classes.has('is-away'),false);
    f.scroll(300);f.frame();f.scroll(250);f.frame();assert.equal(f.classes.has('is-away'),false);
  });
  await test('CSS independently excludes focused companion from hiding',()=>{
    assert.match(fs.readFileSync(path.join(root,'assets/css/companion.css'),'utf8'),/\.is-away:not\(:focus-within\) \.mwseo-cmp__body\s*\{\s*display: none;/);
  });
  console.log(`${passed} focused isolated tests passed. Browser/editor verification is separate.`);
}
main().catch(error=>{console.error(error);process.exitCode=1;});
