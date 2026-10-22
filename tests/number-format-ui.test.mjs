import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';

// Execute the real app and HTTP client against a minimal DOM. This checks state
// and displayed calculation results; it is not a browser or device test.
test('number format refresh and save races preserve values and unfinished editor input',async()=>{
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
  const saved=[{id:'fictional-position',instrumentId:instrument.id,quantity:'1234.123456',averageCost:'80.123456',eurCost:null}],before=structuredClone(saved);
  let serverCurrency='EUR',serverFormat='nl-NL',finishOldMarket=null,holdNextMarket=false,failSettings=false,revision=3;
  const portfolioWrites=[];
  const calls=[];
  const market=()=>({dataMode:'live',reportingCurrency:serverCurrency,numberFormat:serverFormat,issues:[],quotes:{[instrument.id]:{
    status:'closed',date:'2026-10-14',previousDate:'2026-10-13',priceKind:'close',observedAt:null,previousObservedAt:null,price:'102',previousClose:'100',fx:null,previousFx:null}},
    reportingFx:{series:'ecb-reference-daily',base:'EUR',status:'ready',current:{date:'2026-10-14',rates:{USD:'1.25',GBP:'0.75'}},
      history:[{date:'2026-10-14',rates:{USD:'1.25',GBP:'0.75'}},{date:'2026-10-13',rates:{USD:'1.2',GBP:'0.8'}}],
      fetchedAt:'2026-10-15T10:00:00Z',freshUntil:'2026-10-15T16:00:00Z'},
    priceUpdates:{[instrument.id]:{receivedAt:'2026-10-15T10:05:00Z',observationDate:'2026-10-14',observedAt:null}}});
  const response=body=>({ok:true,status:200,json:async()=>body});
  const fetchImpl=async(url,options)=>{
    const action=new URL(url,location).searchParams.get('action');calls.push({action,method:options.method,body:options.body});
    if(action==='session')return response({authenticated:true,user:{email:'fictional@example.test'},csrfToken:'fictional-csrf',dataMode:'live',reportingCurrency:serverCurrency,numberFormat:serverFormat});
    if(action==='portfolio'){if(options.method==='PUT'){const body=JSON.parse(options.body);assert.equal(body.revision,revision);portfolioWrites.push(body);saved.splice(0,saved.length,...body.holdings);revision++;}return response({dataMode:'live',revision,holdings:saved,instruments:[instrument],updatedAt:null});}
    if(action==='instruments')return response({dataMode:'live',instruments:[instrument],market:market()});
    if(action==='market'){
      const captured=market();
      if(holdNextMarket){holdNextMarket=false;await new Promise(resolve=>{finishOldMarket=resolve;});}
      return response(captured);
    }
    if(action==='settings'){assert.equal(options.method,'PUT');const body=JSON.parse(options.body);assert.deepEqual(Object.keys(body),['numberFormat']);if(failSettings)return {ok:false,status:503,json:async()=>({error:{message:'Try again'}})};serverFormat=body.numberFormat;return response({currency:serverCurrency,numberFormat:serverFormat});}
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
  const choose=async format=>{get('account-number-format').emit('click',{detail:1});get('number-format').value=format;await get('number-format-form').emit('submit');await settle();};
  try {
    await import('../public/app.mjs?number-format-ui-test');await settle();
    assert.equal(get('account-number-format-value').textContent,'1.234,56');
    assert.match(get('portfolio-value').textContent,/125\.881/);
    assert.match(get('summary-change').innerHTML,/\+2,00%/);
    holdNextMarket=true;await refresh();assert.ok(finishOldMarket);
    await choose('en-GB');
    assert.equal(get('account-number-format-value').textContent,'1,234.56');
    assert.match(get('portfolio-value').textContent,/125,881/);
    assert.match(get('summary-change').innerHTML,/\+2\.00%/);
    assert.equal(doc.activeElement.id,'account-button');
    assert.ok(get('account-button').classList.contains('pointer-return-focus'));
    finishOldMarket();await settle();
    assert.equal(get('account-number-format-value').textContent,'1,234.56','older market response cannot undo the saved preference');
    get('period-switch').emit('change',{target:{value:'purchase',matches:()=>true}});
    assert.match(get('summary-change').innerHTML,/\+27\.30%/);
    get('portfolio-breakdown').emit('click',{detail:1});
    assert.match(get('breakdown-list').innerHTML,/100\.00%/);
    serverFormat='nl-NL';await refresh();
    assert.match(get('breakdown-list').innerHTML,/100,00%/,'open breakdown updates despite unchanged numeric model');
    get('close-breakdown').emit('click');
    assert.equal(get('account-number-format-value').textContent,'1.234,56');
    assert.match(get('summary-change').innerHTML,/\+27,30%/);
    failSettings=true;await choose('en-GB');
    assert.equal(get('number-format-dialog').open,true);
    assert.match(get('number-format-error').textContent,/could not be confirmed/);
    assert.equal(get('account-number-format-value').textContent,'1.234,56','failed save does not falsely change the displayed preference');
    assert.equal(get('save-number-format').disabled,false);
    get('cancel-number-format').emit('click');failSettings=false;
    await choose('en-GB');
    assert.deepEqual(saved,before,'all format changes leave original holding inputs and order untouched');
    assert.equal(portfolioWrites.length,0);
    // Open the actual editor. Its decimal meaning must stay stable if another
    // device changes the account preference while this draft is unfinished.
    location.hash='#edit/fictional-position';windowEvents.get('hashchange')();
    assert.equal(get('quantity').value,'1234.123456');
    assert.equal(get('average-cost').value,'80.123456');
    assert.match(get('number-input-hint').textContent,/English.*1,234\.56/);
    get('quantity').value='2.500001';get('average-cost').value='0.123456';
    serverFormat='nl-NL';await refresh();
    assert.equal(get('quantity').value,'2.500001');
    assert.equal(get('average-cost').value,'0.123456');
    assert.match(get('number-input-hint').textContent,/English/);
    await get('investment-form').emit('submit');await settle();
    assert.equal(portfolioWrites.length,1);
    assert.equal(portfolioWrites[0].holdings[0].quantity,'2.500001','draft is parsed using its original dot decimal format');
    assert.equal(portfolioWrites[0].holdings[0].averageCost,'0.123456');
    assert.equal(get('account-number-format-value').textContent,'1.234,56','returning portfolio uses the newer account setting');
    location.hash='#edit/fictional-position';windowEvents.get('hashchange')();
    assert.equal(get('quantity').value,'2,500001');
    assert.match(get('number-input-hint').textContent,/Dutch/);
    get('quantity').value='2.50';
    await get('investment-form').emit('submit');await settle();
    assert.match(get('quantity-error').textContent,/comma/);
    assert.equal(portfolioWrites.length,1,'wrong-locale input cannot save a silently different quantity');
  } finally {
    Date.now=originalNow;
    for(const [name,descriptor] of originals){if(descriptor)Object.defineProperty(globalThis,name,descriptor);else delete globalThis[name];}
  }
});
