import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';

// Execute the real app and HTTP client against a minimal DOM. This checks state
// and displayed calculation results; it is not a browser or device test.
test('currency settings survive an older in-flight market response and preserve both comparisons and saved holdings',async()=>{
  const html=await readFile(new URL('../public/index.html',import.meta.url),'utf8');
  const elements=new Map(),windowEvents=new Map(),documentEvents=new Map(),timers=new Map();
  let timerId=0,clock=Date.parse('2026-10-15T12:00:00Z'),clockStep=0;
  class Element {
    constructor(id){this.id=id;this.hidden=false;this.disabled=false;this.value='';this.textContent='';this.innerHTML='';this.listeners=new Map();this.attrs=new Map();this.dataset={};this.style={};this.classes=new Set();this.classList={contains:n=>this.classes.has(n),add:(...ns)=>ns.forEach(n=>this.classes.add(n)),remove:(...ns)=>ns.forEach(n=>this.classes.delete(n)),toggle:(n,on)=>{if(on??!this.classes.has(n))this.classes.add(n);else this.classes.delete(n);}};}
    addEventListener(type,fn){this.listeners.set(type,fn);}
    emit(type,values={}){return this.listeners.get(type)?.({target:this,preventDefault(){},...values});}
    setAttribute(k,v){this.attrs.set(k,String(v));}
    getAttribute(k){return this.attrs.get(k)??null;}
    removeAttribute(k){this.attrs.delete(k);}
    replaceChildren(){this.innerHTML='';this.textContent='';}
    showModal(){this.open=true;}
    close(){this.open=false;this.emit('close');}
    focus(){doc.activeElement=this;}
    contains(node){return node===this;}
    closest(selector){return selector==='[hidden]'?(this.hidden?this:null):this;}
    querySelector(selector){return get(this.id+selector);}
    querySelectorAll(){return [];}
    reset(){}
    scrollIntoView(){}
  }
  const get=id=>{if(!elements.has(id))elements.set(id,new Element(id));return elements.get(id);};
  for(const match of html.matchAll(/<([a-z]+)\b[^>]*\bid="([^"]+)"[^>]*>/g)){const e=get(match[2]);e.hidden=/\shidden(?:\s|>)/.test(match[0]);}
  const radios=[...html.matchAll(/<input[^>]*name="period"[^>]*value="([^"]+)"[^>]*>/g)].map(match=>{const e=get('period-'+match[1]);e.value=match[1];return e;});
  const stored=new Map(),storage={getItem:k=>stored.get(k)??null,setItem:(k,v)=>stored.set(k,String(v)),removeItem:k=>stored.delete(k)};
  const location=new URL('https://fixture.example.test/');
  const doc={hidden:false,activeElement:null,title:'',body:get('body'),documentElement:get('html'),getElementById:get,
    querySelector:selector=>get(selector),querySelectorAll:selector=>selector==='input[name="period"]'?radios:selector==='dialog[open]'?[...elements.values()].filter(e=>e.open):[],
    addEventListener:(type,fn)=>documentEvents.set(type,fn)};
  const history={state:null,replaceState(state,_unused,url){this.state=state;if(url)location.href=new URL(url,location).href;},pushState(state,_unused,url){this.replaceState(state,_unused,url);}};
  const originalNow=Date.now,originals=new Map();Date.now=()=>{const sampled=clock;clock+=clockStep;return sampled;};
  const instrument={id:'fictional-eur',name:'Fictional Euro Shares <safe & clear>',symbol:'FES',isin:'ZZ0000000001',exchange:'Example Exchange',currency:'EUR',type:'Stock',
    admission:{instrumentId:'fictional-eur',capability:'supported',status:'ready',canAdd:true,currentReady:true,latestReady:true,reasons:[]}};
  const saved=[{id:'fictional-position',instrumentId:instrument.id,quantity:'1',averageCost:'80',eurCost:null}],before=structuredClone(saved);
  let serverCurrency='EUR',finishOldMarket=null,holdNextMarket=false;
  const calls=[];
  const market=()=>({dataMode:'live',reportingCurrency:serverCurrency,issues:[],quotes:{[instrument.id]:{
    status:'closed',date:'2026-10-14',previousDate:'2026-10-13',priceKind:'close',observedAt:null,previousObservedAt:null,price:'102',previousClose:'100',fx:null,previousFx:null}},
    reportingFx:{series:'ecb-reference-daily',base:'EUR',status:'ready',current:{date:'2026-10-14',rates:{USD:'1.25',GBP:'0.75'}},
      history:[{date:'2026-10-14',rates:{USD:'1.25',GBP:'0.75'}},{date:'2026-10-13',rates:{USD:'1.2',GBP:'0.8'}}],
      fetchedAt:'2026-10-15T10:00:00Z',freshUntil:'2026-10-15T16:00:00Z'},
    priceUpdates:{[instrument.id]:{receivedAt:'2026-10-15T10:05:00Z',observationDate:'2026-10-14',observedAt:null}}});
  const response=body=>({ok:true,status:200,json:async()=>body});
  const fetchImpl=async(url,options)=>{
    const action=new URL(url,location).searchParams.get('action');calls.push({action,method:options.method,body:options.body});
    if(action==='session')return response({authenticated:true,user:{email:'fictional@example.test'},csrfToken:'fictional-csrf',dataMode:'live',reportingCurrency:serverCurrency});
    if(action==='portfolio'){assert.equal(options.method,'GET','currency choices must never rewrite holdings');return response({dataMode:'live',revision:3,holdings:saved,instruments:[instrument],updatedAt:null});}
    if(action==='instruments')return response({dataMode:'live',instruments:[instrument],market:market()});
    if(action==='market'){
      const captured=market();
      if(holdNextMarket){holdNextMarket=false;await new Promise(resolve=>{finishOldMarket=resolve;});}
      return response(captured);
    }
    if(action==='settings'){assert.equal(options.method,'PUT');const body=JSON.parse(options.body);assert.deepEqual(Object.keys(body),['currency']);serverCurrency=body.currency;return response({currency:serverCurrency});}
    throw Error('Unexpected request: '+action);
  };
  const globals={document:doc,window:{scrollY:0,addEventListener:(type,fn)=>windowEvents.set(type,fn),scrollTo(){}},location,history,
    localStorage:storage,sessionStorage:storage,fetch:fetchImpl,performance:{getEntriesByType:()=>[]},
    setTimeout:(fn,ms)=>{const id=++timerId;timers.set(id,{fn,ms});return id;},clearTimeout:id=>timers.delete(id)};
  for(const [name,value] of Object.entries(globals)){originals.set(name,Object.getOwnPropertyDescriptor(globalThis,name));Object.defineProperty(globalThis,name,{configurable:true,writable:true,value});}
  const settle=async()=>{for(let i=0;i<50;i++)await Promise.resolve();};
  const refresh=async()=>{
    clock+=5000;documentEvents.get('visibilitychange')?.();windowEvents.get('focus')?.();
    for(const [id,timer] of [...timers])if(timer.ms===100){timers.delete(id);timer.fn();}
    await settle();
  };
  const choose=async currency=>{get('account-currency').emit('click');get('reporting-currency').value=currency;await get('currency-form').emit('submit');await settle();};
  try {
    await import('../public/app.mjs?currency-ui-test');await settle();
    assert.match(get('portfolio-value').textContent,/€\s*102/);
    assert.match(get('summary-change').innerHTML,/\+2,00%/);
    assert.match(get('price-update').textContent,/Last update/);
    holdNextMarket=true;await refresh();assert.ok(finishOldMarket);
    await choose('USD');
    assert.equal(get('account-currency-value').textContent,'USD');
    assert.match(get('portfolio-value').textContent,/US\$\s*128/);
    assert.match(get('summary-change').innerHTML,/\+6,25%/);
    finishOldMarket();await settle();
    assert.equal(get('account-currency-value').textContent,'USD','older GET cannot undo successful PUT');
    assert.match(get('portfolio-value').textContent,/US\$\s*128/);
    get('period-switch').emit('change',{target:{value:'purchase',matches:()=>true}});
    assert.match(get('summary-change').innerHTML,/\+27,50%/);assert.match(get('summary-change').innerHTML,/Estimated/);
    const estimate=get('estimate-portfolio');estimate.dataset={estimate:'portfolio'};
    get('summary-change').emit('click',{target:estimate});
    assert.equal(get('estimate-dialog').open,true);
    assert.match(get('estimate-list').innerHTML,/Fictional Euro Shares &lt;safe &amp; clear&gt;/);
    assert.doesNotMatch(get('estimate-list').innerHTML,/<safe/);
    assert.match(get('estimate-list').innerHTML,/average purchase price is in euros.*cost to US dollars/);
    serverCurrency='GBP';await refresh();
    assert.equal(get('account-currency-value').textContent,'GBP','a later ordinary refresh picks up another device’s setting');
    assert.match(get('portfolio-value').textContent,/£\s*77/);
    assert.match(get('summary-change').innerHTML,/\+27,50%/);
    assert.match(get('estimate-list').innerHTML,/cost to British pounds/,'open explanation follows a later reporting-currency update');
    assert.doesNotMatch(get('estimate-list').innerHTML,/cost to US dollars/);
    get('close-estimate').emit('click');
    get('period-switch').emit('change',{target:{value:'latest',matches:()=>true}});
    assert.match(get('summary-change').innerHTML,/-4,38%/);
    await choose('EUR');
    assert.match(get('portfolio-value').textContent,/€\s*102/);assert.match(get('summary-change').innerHTML,/\+2,00%/);
    // Cross the FX-expiry boundary during a render. Every figure in that render
    // must use its same starting instant, then retain dated values with a warning after expiry.
    clock=Date.parse('2026-10-15T16:00:00Z')-1;clockStep=2;
    await choose('USD');
    assert.match(get('portfolio-value').textContent,/US\$\s*128/);
    assert.match(get('summary-change').innerHTML,/\+6,25%/);
    assert.match(get('holdings-list').innerHTML,/\+6,25%/);
    clockStep=0;
    get('period-switch').emit('change',{target:{value:'purchase',matches:()=>true}});
    assert.match(get('portfolio-value').textContent,/US\$\s*128/);
    assert.doesNotMatch(get('summary-change').innerHTML,/Using earlier data|Data issue/);
    assert.equal(get('summary-status').textContent,'');
    assert.deepEqual(saved,before);
    assert.deepEqual(calls.filter(c=>c.method==='PUT').map(c=>[c.action,JSON.parse(c.body)]),[['settings',{currency:'USD'}],['settings',{currency:'EUR'}],['settings',{currency:'USD'}]]);
  } finally {
    Date.now=originalNow;
    for(const [name,descriptor] of originals){if(descriptor)Object.defineProperty(globalThis,name,descriptor);else delete globalThis[name];}
  }
});
