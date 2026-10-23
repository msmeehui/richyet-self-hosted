import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';

// Execute the real app against a deliberately small DOM/HTTP fixture. These
// tests verify application state transitions, not rendering or device support.
test('live Add keeps search, preparation and personal entry in separate cancellable steps', async t => {
  const html=await readFile(new URL('../public/index.html',import.meta.url),'utf8');
  const elements=new Map(), events=new Map(), docEvents=new Map(), timers=new Map();let timerId=0;
  const originalNow=Date.now;let clock=originalNow();Date.now=()=>clock;
  class Element {
    constructor(id){this.id=id;this.hidden=false;this.disabled=false;this.value='';this.textContent='';this.innerHTML='';this.listeners=new Map();this.attributes=new Map();this.dataset={};this.style={};this.classes=new Set();this.classList={contains:name=>this.classes.has(name),toggle:(name,on)=>{if(on??!this.classes.has(name))this.classes.add(name);else this.classes.delete(name);},add:(...names)=>names.forEach(name=>this.classes.add(name)),remove:(...names)=>names.forEach(name=>this.classes.delete(name))};}
    get innerHTML(){
      let index=0;
      return (this.html??'').replace(/<article class="[^"]*" data-holding-id="[^"]*" data-market-id="[^"]*">[\s\S]*?<\/article>/g,original=>this.marketRows?.[index++]?.render()??original);
    }
    set innerHTML(value){
      this.html=value;
      this.exchangeLabels=[...value.matchAll(/<span data-exchange-id="([^"]+)"( hidden)?>/g)].map(match=>({dataset:{exchangeId:match[1]},hidden:Boolean(match[2])}));
      this.observationLabels=[...value.matchAll(/<span class="observation-time" data-observation-id="([^"]+)"( hidden)?>([^<]*)<\/span>/g)].map(match=>({dataset:{observationId:match[1]},hidden:Boolean(match[2]),textContent:match[3]}));
      // Production CSP does not apply inline HTML styles. Only explicit style
      // property writes can populate these fixture nodes' width.
      this.weightBars=[...value.matchAll(/<span class="holding-weight"[^>]*>/g)].map(()=>({style:{}}));
      this.marketRows=[...value.matchAll(/<article class="([^"]*)" data-holding-id="([^"]*)" data-market-id="([^"]+)">([\s\S]*?)<\/article>/g)].map((match,index)=>{
        const classes=new Set(match[1].split(/\s+/)), body=match[4];
        const change=/<span class="(row-change[^"]*)">([\s\S]*?)<\/span><svg class="chevron"/.exec(body);
        const estimate=/<button class="estimate-button row-estimate-button"[^>]*>/.exec(body);
        const grid=/<div class="details-grid">([\s\S]*?)<\/div>\s*<p class="holding-data-note"/.exec(body);
        const nodes=new Map([
          ['[data-observation-id]',this.observationLabels.find(e=>e.dataset.observationId===match[3])],
          ['.row-change',change?{className:change[1],innerHTML:change[2]}:null],
          ['.row-estimate-button',estimate?{hidden:/ hidden>/.test(estimate[0])}:null],
          ['.holding-row',{classList:{toggle(){}}}],
          ['.details-grid',grid?{innerHTML:grid[1]}:null],
          ['.holding-data-note',grid?{updated:false,get textContent(){return this.text??'';},set textContent(value){this.text=value;this.updated=true;},hidden:false}:null],
          ['.price-timestamp',grid?{textContent:''}:null],
          ['.holding-weight',this.weightBars[index]],
        ]);
        return {hidden:false,dataset:{holdingId:match[2],marketId:match[3]},querySelector:selector=>nodes.get(selector)||null,
          classList:{contains:name=>classes.has(name),toggle(name,on){if(on)classes.add(name);else classes.delete(name);}},
          render(){
            let content=body;
            if(estimate)content=content.replace(estimate[0],estimate[0].replace(/ hidden(?=>)/,'').replace(/>$/,nodes.get('.row-estimate-button').hidden?' hidden>':'>'));
            if(change){const node=nodes.get('.row-change');content=content.replace(/<span class="row-change[^"]*">[\s\S]*?<\/span><svg class="chevron"/,`<span class="${node.className}">${node.innerHTML}</span><svg class="chevron"`);}
            if(grid){
              content=content.replace(/<div class="details-grid">[\s\S]*?<\/div>\s*<p class="holding-data-note"/,`<div class="details-grid">${nodes.get('.details-grid').innerHTML}</div><p class="holding-data-note"`);
              const note=nodes.get('.holding-data-note'),timestamp=nodes.get('.price-timestamp');
              if(note.updated)content=content.replace(/<p class="holding-data-note"[^>]*>[\s\S]*?<\/p>/,`<p class="holding-data-note"${note.hidden?' hidden':''}>${note.textContent}</p>`);
              if(timestamp.textContent)content=content.replace(/<p class="price-timestamp">[\s\S]*?<\/p>/,`<p class="price-timestamp">${timestamp.textContent}</p>`);
            }
            return `<article class="${[...classes].join(' ')}" data-holding-id="${match[2]}" data-market-id="${match[3]}"${this.hidden?' hidden':''}>${content}</article>`;
          }};
      });
    }
    addEventListener(type,fn){this.listeners.set(type,fn);}
    setAttribute(k,v){this.attributes.set(k,String(v));}
    removeAttribute(k){this.attributes.delete(k);}
    getAttribute(k){return this.attributes.get(k)??null;}
    showModal(){this.open=true;this.emit('toggle');}
    close(){this.open=false;this.emit('close');}
    replaceChildren(){this.innerHTML='';this.textContent='';}
    focus(){document.activeElement=this;}
    scrollIntoView(){}
    closest(selector){return selector==='[hidden]'?(this.hidden?this:null):this;}
    querySelector(selector){return get(this.id+selector);}
    querySelectorAll(selector){return selector==='.holding-weight'?this.weightBars:selector==='[data-holding-id]'?this.marketRows:[];}
    reset(){for(const e of elements.values())if(e.input)e.value='';}
    emit(type,values={}){return this.listeners.get(type)?.({type,target:this,preventDefault(){},...values});}
  }
  const get=id=>{if(!elements.has(id))elements.set(id,new Element(id));return elements.get(id);};
  for(const match of html.matchAll(/<([a-z]+)\b[^>]*\bid="([^"]+)"[^>]*>/g)){const e=get(match[2]);e.hidden=/\shidden(?:\s|>)/.test(match[0]);e.input=match[1]==='input';}
  const radios=[...html.matchAll(/<input[^>]*name="period"[^>]*value="([^"]+)"[^>]*>/g)].map(match=>{const input=get('period-'+match[1]);input.value=match[1];input.checked=/checked/.test(match[0]);return input;});
  const stored=new Map();let storageBlocked=false;
  const storage={getItem(key){if(storageBlocked)throw new Error('Storage unavailable');return stored.get(key)??null;},setItem(key,value){if(storageBlocked)throw new Error('Storage unavailable');stored.set(key,String(value));},removeItem(key){stored.delete(key);}};
  const location=new URL('https://fixture.example.test/');
  const setURL=url=>{if(url)location.href=new URL(url,location).href;};
  let discardAnswer=true,confirmCount=0;
  const window={scrollY:0,confirm(){confirmCount++;return discardAnswer;},addEventListener(type,fn){events.set(type,fn);},scrollTo({top}){this.scrollY=top;}};
  const doc={hidden:false,activeElement:null,title:'',body:get('body'),documentElement:get('html'),getElementById:get,
    querySelector(selector){return get(selector);},querySelectorAll(selector){if(selector==='input[name="period"]')return radios;if(selector==='dialog[open]')return [...elements.values()].filter(e=>e.open);const collection={'[data-exchange-id]':'exchangeLabels','[data-market-id]':'marketRows','[data-observation-id]':'observationLabels'}[selector];return collection?[...elements.values()].flatMap(e=>e[collection]):[];},addEventListener(type,fn){docEvents.set(type,fn);}};
  const history={state:null,backEntry:null,forwardEntry:null,
    replaceState(state,_unused,url){this.state=state;setURL(url);},
    pushState(state,_unused,url){this.backEntry={state:this.state,url:location.href};this.forwardEntry=null;this.state=state;setURL(url);},
    back(){this.forwardEntry={state:this.state,url:location.href};this.state=this.backEntry?.state??this.state;setURL(this.backEntry?.url??'/');events.get('popstate')?.({type:'popstate'});events.get('hashchange')?.({type:'hashchange'});},
    forward(){const next=this.forwardEntry;if(!next)return;this.state=next.state;setURL(next.url);events.get('popstate')?.({type:'popstate'});events.get('hashchange')?.({type:'hashchange'});}};
  const originals=new Map();
  for(const [name,value] of Object.entries({document:doc,window,location,history,localStorage:storage,performance:{getEntriesByType:type=>type==='navigation'?[{type:'reload'}]:[]},setTimeout:(fn,ms)=>{const id=++timerId;timers.set(id,{fn,ms});return id;},clearTimeout:id=>timers.delete(id)})){
    originals.set(name,Object.getOwnPropertyDescriptor(globalThis,name));Object.defineProperty(globalThis,name,{configurable:true,writable:true,value});
  }
  const listing=(id,name,preparationStatus='available')=>({id,name,symbol:id.toUpperCase(),isin:'ZZ0000000001',exchange:'Example Exchange',currency:'EUR',type:'ETF',preparationStatus});
  const old=listing('fixture-ready','Fictional Ready'), fresh=listing('fixture-new','Fictional New');
  let quoteOverrides={},marketFixtureIssues=[],marketFixtureReceipts={};
  const catalogue=items=>({dataMode:'live',instruments:items.map(i=>{
    const ready=quoteOverrides[i.id]?.fx!==null && quoteOverrides[i.id]?.status!=='stale';
    return {...i,admission:{instrumentId:i.id,capability:'supported',status:ready?'ready':'temporarily_unavailable',canAdd:ready,currentReady:ready,latestReady:ready,reasons:ready?[]:['fx_unavailable']}};
  }),market:{dataMode:'live',quotes:Object.fromEntries(items.map(i=>[i.id,{price:'102',previousClose:'100',priceKind:'close',observedAt:null,previousObservedAt:null,date:'2026-10-09',previousDate:'2026-10-08',status:'closed',fx:i.currency==='USD'?'0.9':null,previousFx:i.currency==='USD'?'0.9':null,...quoteOverrides[i.id]}])),issues:marketFixtureIssues,priceUpdates:marketFixtureReceipts}});
  let savedHoldings=[], catalogueItems=[old], holdSave=null;
  let authenticated=true, handleSession=null, handleMarket=()=>catalogue(catalogueItems).market;
  const calls=[];let handleSearch=()=>({instruments:[fresh],hasMore:false,offset:0}), handlePrepare=()=>({status:'ready',catalogue:catalogue([old,fresh])});
  const result=data=>({ok:true,status:200,json:async()=>data});
  originals.set('fetch',Object.getOwnPropertyDescriptor(globalThis,'fetch'));
  globalThis.fetch=async(url,options)=>{
    const params=new URL(url,location).searchParams,action=params.get('action');calls.push({action,params,options});
    if(action==='session')return result(handleSession?await handleSession():{authenticated,user:authenticated?{email:'fictional@example.test'}:null,csrfToken:'fixture-csrf',dataMode:'live'});
    if(action==='logout'){authenticated=false;return result({authenticated:false,csrfToken:'fixture-anonymous',dataMode:'live'});}
    if(action==='market'){const market=await handleMarket(params,options);return market?.ok===false?market:result(market);}
    if(action==='portfolio'){
      let revision=1;if(options.method==='PUT'){const body=JSON.parse(options.body);savedHoldings=structuredClone(body.holdings);revision=body.revision+1;if(holdSave)await holdSave();}
      return result({dataMode:'live',revision,holdings:savedHoldings,instruments:catalogueItems,updatedAt:null});
    }
    if(action==='instruments')return result(catalogue(catalogueItems));
    if(action==='search')return result(await handleSearch(params,options));
    if(action==='prepare')return result(await handlePrepare(JSON.parse(options.body),options));
    throw new Error('Unexpected fixture request: '+action);
  };
  const settle=async()=>{for(let i=0;i<30;i++)await Promise.resolve();};
  const runDebounce=async()=>{for(const [id,timer] of [...timers])if(timer.ms<=300){timers.delete(id);timer.fn();}await settle();};
  const runTimers=async ms=>{clock+=ms;for(const [id,timer] of [...timers])if(timer.ms===ms){timers.delete(id);timer.fn();}await settle();};
  const foreground=()=>{doc.hidden=false;docEvents.get('visibilitychange')?.();events.get('focus')?.();events.get('pageshow')?.({persisted:false});};
  const marketCalls=()=>calls.filter(c=>c.action==='market').length;
  const selectView=value=>{
    get('period-switch').emit('change',{target:{value:value==='purchase'?'purchase':'latest',matches:()=>true}});
    if(value!=='purchase'){get('active-only').checked=value==='active';get('active-only').emit('change');}
  };

  const search=async term=>{get('instrument-search').value=term;get('instrument-search').emit('input');await runDebounce();};
  const select=async id=>{const option=get('option-'+id);option.dataset.instrument=id;get('search-results').emit('click',{target:option});await settle();};
  try{
    await import('../public/app.mjs?search-flow-test');await settle();
    await t.test('return signals coalesce with initial cached loading and one another without loading holdings or preparing data',async()=>{
      const before=calls.length;foreground();await runTimers(100);
      assert.equal(calls.length,before);
      clock+=5000;doc.hidden=true;events.get('focus')();await runTimers(100);assert.equal(calls.length,before);
      foreground();await runTimers(100);
      assert.equal(marketCalls(),1);assert.deepEqual(calls.slice(before).map(c=>[c.action,c.options.method]),[['market','GET']]);
      events.get('focus')();events.get('pageshow')({persisted:true});await runTimers(100);assert.equal(marketCalls(),1);
    });
    await t.test('an in-flight cache read absorbs return bursts, and ordinary five-minute polling remains',async()=>{
      let finish;handleMarket=()=>new Promise(resolve=>{finish=resolve;});clock+=5000;foreground();await runTimers(100);
      const count=marketCalls();clock+=5000;foreground();await runTimers(100);assert.equal(marketCalls(),count);
      finish(catalogue(catalogueItems).market);await settle();await runTimers(100);assert.equal(marketCalls(),count);
      handleMarket=()=>catalogue(catalogueItems).market;
      doc.hidden=true;await runTimers(300000);assert.equal(marketCalls(),count);
      foreground();await runTimers(100);assert.equal(marketCalls(),count+1);
      await runTimers(300000);assert.equal(marketCalls(),count+2);
    });
    await t.test('empty portfolios show no change placeholder in either mode and retain the Add control',async()=>{
      for (const value of ['active','all','purchase','active']) {
        selectView(value);
        assert.equal(get('summary-change').hidden,true);assert.equal(get('summary-change').innerHTML,'');
        assert.equal(get('empty-state').hidden,false);assert.equal(get('empty-add').hidden,false);
        assert.equal(get('period-switch').hidden,true);assert.equal(get('summary-status').hidden,true);
      }
    });
    await t.test('breakdown replaces tile bars, uses full current values in every mode and leaves holdings untouched',async()=>{
      const usd={...listing('weight-usd','Fictional Dollar <img src=x onerror=alert(1)> & Shares'),currency:'USD',type:'Stock'};
      catalogueItems=[old,usd];quoteOverrides={[old.id]:{price:'100'},[usd.id]:{price:'100',fx:'0.5'}};
      savedHoldings=[{id:'weight-eur',instrumentId:old.id,quantity:'1',averageCost:null,eurCost:null},
        {id:'weight-usd',instrumentId:usd.id,quantity:'6',averageCost:null,eurCost:null}];
      get('reload-portfolio').emit('click');await settle();
      const saved=structuredClone(savedHoldings),before=calls.length;
      const openBreakdown=()=>{get('portfolio-breakdown').emit('click',{detail:1});assert.equal(get('breakdown-dialog').open,true);};
      const chartShares=()=>[...get('breakdown-list').innerHTML.matchAll(/class="breakdown-share">([^<]*)<\/strong>/g)].map(match=>match[1]);
      assert.equal(get('holdings-list').weightBars.length,0);assert.match(get('portfolio-value').textContent,/€\s*400/);
      for(const value of ['purchase','all','active']) {
        selectView(value);
        openBreakdown();assert.deepEqual(chartShares(),['75,00%','25,00%'],'unavailable purchase costs and selected comparisons do not change current-value proportions');
        assert.doesNotMatch(get('breakdown-chart').innerHTML,/<img|onerror=alert\(1\)>/,'untrusted instrument names cannot inject markup');
        assert.match(get('breakdown-list').innerHTML,/&lt;img src=x onerror=alert\(1\)&gt; &amp; Shares/);
        get('close-breakdown').emit('click');assert.equal(document.activeElement.id,'portfolio-breakdown');
        assert.equal(get('portfolio-breakdown').classList.contains('pointer-return-focus'),true);
      }
      openBreakdown();docEvents.get('keydown')({key:'Escape'});get('breakdown-dialog').close();
      assert.equal(document.activeElement.id,'portfolio-breakdown');assert.equal(get('portfolio-breakdown').classList.contains('pointer-return-focus'),false,'keyboard closing keeps a visible focus indicator');
      openBreakdown();
      const firstSlice=/data-breakdown-id="([^"]+)" data-breakdown-kind="slice"/.exec(get('breakdown-chart').innerHTML);
      assert.ok(firstSlice);const slice={dataset:{breakdownId:firstSlice[1],breakdownKind:'slice'},closest(){return this;}};
      get('breakdown-chart').emit('keydown',{key:'Enter',target:slice});
      assert.equal(get('breakdown-selection').textContent,usd.name+' · 75,00%');
      get('breakdown-dialog').emit('click',{target:slice});assert.equal(get('breakdown-selection').textContent,'Tap a slice or investment to highlight it.');
      get('close-breakdown').emit('click');
      assert.equal(calls.length,before,'opening, selecting and closing breakdown needs no server or provider request');assert.deepEqual(savedHoldings,saved);
      const toggle={dataset:{id:'weight-eur'},closest(){return this;},classList:{contains:kind=>kind==='holding-toggle'}};
      get('holdings-list').emit('click',{target:toggle});
      assert.match(get('holdings-list').innerHTML,/class="holding-details"/);assert.equal(get('holdings-list').weightBars.length,0);
      assert.match(get('holdings-list').innerHTML,/<span>Share of portfolio<\/span><strong class="">25,00%<\/strong>/);
      const expand=()=>{if(!get('holdings-list').innerHTML.includes('class="holding-details"'))get('holdings-list').emit('click',{target:toggle});};
      get('manage-list').emit('click');assert.equal(get('portfolio-breakdown').hidden,true);
      get('manage-list').emit('click');assert.equal(get('portfolio-breakdown').hidden,false);
      for (const override of [{fx:null},{price:null},{price:'not-a-price'}]) {
        quoteOverrides[usd.id]={price:'100',fx:'0.5',...override};
        get('reload-portfolio').emit('click');await settle();openBreakdown();assert.equal(get('breakdown-chart').hidden,true,'never normalize a known subtotal');
        assert.deepEqual(chartShares(),['—','—']);assert.match(get('breakdown-message').textContent,/cannot be valued/);get('close-breakdown').emit('click');
        expand();assert.match(get('holdings-list').innerHTML,/<span>Share of portfolio<\/span><strong class="">—<\/strong>/,'the known holding cannot claim a partial portfolio share');
      }
      quoteOverrides={[old.id]:{price:'0'},[usd.id]:{price:'0',fx:'0.5'}};
      get('reload-portfolio').emit('click');await settle();openBreakdown();assert.equal(get('breakdown-chart').hidden,true,'a zero total has no proportions');
      assert.match(get('breakdown-message').textContent,/zero/);get('close-breakdown').emit('click');
      expand();assert.match(get('holdings-list').innerHTML,/<span>Share of portfolio<\/span><strong class="">—<\/strong>/);
      quoteOverrides={};savedHoldings=savedHoldings.slice(0,1);
      get('reload-portfolio').emit('click');await settle();openBreakdown();assert.deepEqual(chartShares(),['100,00%']);get('close-breakdown').emit('click');
      expand();assert.match(get('holdings-list').innerHTML,/<span>Share of portfolio<\/span><strong class="">100,00%<\/strong>/);
      savedHoldings=[];catalogueItems=[old];get('reload-portfolio').emit('click');await settle();assert.equal(get('portfolio-breakdown').hidden,true);assert.equal(get('holdings-list').weightBars.length,0);
    });
    await t.test('single holding retains its dated change through opening and preserves an edit draft',async()=>{
      clock=Date.parse('2026-10-12T13:29:00Z');
      const usd={...listing('clock-usd','Fictional Calendar Shares'),currency:'USD',type:'Stock',mic:'XNAS',timeZone:'America/New_York',closeSchedule:'us_retained_calendar'};
      catalogueItems=[usd];quoteOverrides={};savedHoldings=[{id:'clock-holding',instrumentId:usd.id,quantity:'2',averageCost:'90',eurCost:null}];
      get('reload-portfolio').emit('click');await settle();
      assert.equal(get('holdings-list').hidden,false);assert.equal(get('holdings-list').marketRows[0].hidden,false);
      assert.equal(get('active-filter-choice').hidden,true);assert.equal(get('market-filter-status').textContent,'Market closed');
      assert.doesNotMatch(get('holdings-list').innerHTML,/data-exchange-id/,'the section replaces repeated Closed labels');
      assert.equal(get('holdings-list').marketRows[0].classList.contains('is-closed'),true);
      const callsBefore=calls.length;
      await runTimers(60000);
      assert.equal(get('holdings-list').hidden,false);assert.equal(get('holdings-list').marketRows[0].hidden,false);
      assert.equal(get('market-filter-status').textContent,'Market open');
      assert.equal(get('holdings-list').marketRows[0].classList.contains('is-closed'),false);
      assert.match(get('holdings-list').marketRows[0].querySelector('.row-change').innerHTML,/\+2,00%/,'Retains the last dated comparison without inventing a new quote');
      assert.equal(calls.length,callsBefore);
      const edit={dataset:{id:'clock-holding'},closest(){return this;},classList:{contains:kind=>kind==='edit-holding'}};
      get('holdings-list').emit('click',{target:edit});
      const markup=get('holdings-list').innerHTML;
      get('quantity').value='7,25';get('average-cost').value='88,50';get('eur-cost').value='123,45';get('quantity').focus();
      assert.equal(get('eur-cost-hint').textContent,'The total euros you paid for the units you still own. This includes currency changes when your portfolio is shown in euros.');
      clock=Date.parse('2026-10-12T19:59:00Z');await runTimers(60000);
      assert.equal(get('holdings-list').innerHTML,markup,'hidden rows are not regrouped during editing');
      assert.equal(get('quantity').value,'7,25');assert.equal(get('average-cost').value,'88,50');assert.equal(get('eur-cost').value,'123,45');
      assert.equal(document.activeElement.id,'quantity');assert.equal(savedHoldings[0].quantity,'2');assert.equal(calls.length,callsBefore);
      doc.hidden=true;clock=Date.parse('2026-10-13T13:31:00Z');foreground();
      assert.equal(calls.length,callsBefore);await runTimers(100);
      assert.deepEqual(calls.slice(callsBefore).map(c=>c.action),['market']);
      assert.equal(get('quantity').value,'7,25');assert.equal(get('eur-cost').value,'123,45');
      get('form-portfolio-return').emit('click');await settle();
      savedHoldings=[];catalogueItems=[old];get('reload-portfolio').emit('click');await settle();
    });
    await t.test('ready market status replaces a redundant filter and restores its preference at closing',async()=>{
      const priorClock=clock;
      const eu={...listing('filter-eu','Fictional Europe'),type:'Stock',mic:'XAMS',timeZone:'Europe/Amsterdam',closeSchedule:'ams_j0_continuous_2026'};
      const us={...listing('filter-us','Fictional US'),type:'Stock',currency:'USD',mic:'XNAS',timeZone:'America/New_York',closeSchedule:'us_retained_calendar'};
      try {
        clock=Date.parse('2026-10-13T14:00:00Z');catalogueItems=[eu,us];
        savedHoldings=[{id:'filter-us-holding',instrumentId:us.id,quantity:'2',averageCost:'80',eurCost:null},{id:'filter-eu-holding',instrumentId:eu.id,quantity:'3',averageCost:'70',eurCost:null}];
        quoteOverrides=Object.fromEntries([eu,us].map(i=>[i.id,{date:'2026-10-13',previousDate:'2026-10-12',observedAt:'2026-10-13T13:45:00Z',priceKind:'trade',status:'delayed'}]));
        const saved=JSON.stringify(savedHoldings);get('reload-portfolio').emit('click');await settle();selectView('active');
        assert.equal(get('active-filter-choice').hidden,true);assert.equal(get('market-filter-status').textContent,'All markets open');
        assert.equal(get('active-only').checked,true);const movement=get('summary-change').innerHTML,callsBefore=calls.length;
        selectView('all');assert.equal(get('summary-change').innerHTML,movement);selectView('active');
        selectView('purchase');assert.equal(get('active-filter').hidden,true);assert.equal(get('active-only').checked,true);
        get('period-switch').emit('change',{target:{value:'latest',matches:()=>true}});
        assert.equal(get('active-filter-choice').hidden,true);assert.equal(get('active-only').checked,true);
        clock=Date.parse('2026-10-13T16:00:00Z');await runTimers(60000);
        assert.equal(get('active-filter-choice').hidden,false);assert.equal(get('market-filter-status').hidden,true);assert.equal(get('active-only').checked,true);
        assert.equal(get('holdings-list').marketRows.find(r=>r.dataset.marketId===eu.id).hidden,true);
        assert.equal(get('holdings-list').marketRows.find(r=>r.dataset.marketId===us.id).hidden,false);
        clock=Date.parse('2026-10-13T22:00:00Z');await runTimers(60000);
        assert.equal(get('active-filter-choice').hidden,true);assert.equal(get('market-filter-status').textContent,'All markets closed');
        assert.equal(get('active-only').checked,true);assert.equal(get('holdings-list').hidden,false);
        assert.equal(get('holdings-list').marketRows.every(r=>!r.hidden),true);
        assert.equal(get('summary-change').innerHTML,movement,'Overnight headline includes all latest dated comparisons');
        selectView('purchase');assert.equal(get('active-filter').hidden,true);
        get('period-switch').emit('change',{target:{value:'latest',matches:()=>true}});
        assert.equal(get('market-filter-status').textContent,'All markets closed');assert.equal(get('active-only').checked,true);
        clock=Date.parse('2026-10-14T07:00:00Z');await runTimers(60000);
        assert.equal(get('active-filter-choice').hidden,false);assert.equal(get('market-filter-status').hidden,true);
        assert.equal(get('active-only').checked,true);
        assert.equal(get('holdings-list').marketRows.every(r=>r.hidden),true,'Open exchange still waits for its first current-session quote');
        assert.equal(calls.length,callsBefore);assert.equal(JSON.stringify(savedHoldings),saved);
      } finally {clock=priorClock;quoteOverrides={};savedHoldings=[];catalogueItems=[old];get('reload-portfolio').emit('click');await settle();}
    });
    await t.test('All markets open remains visible for complete retained comparisons while update details stay available',async()=>{
      const priorClock=clock;
      const xetra={...listing('retained-filter-xetra','Fictional Retained Xetra'),type:'ETF',mic:'XETA',timeZone:'Europe/Berlin',closeSchedule:'xetra_etf_regular_2026'};
      const us={...listing('retained-filter-us','Fictional Ready US'),type:'Stock',currency:'USD',mic:'XNAS',timeZone:'America/New_York',closeSchedule:'us_retained_calendar'};
      try {
        clock=Date.parse('2026-10-13T14:00:00Z');catalogueItems=[xetra,us];
        savedHoldings=[{id:'retained-filter-xetra-h',instrumentId:xetra.id,quantity:'3',averageCost:'70',eurCost:null},
          {id:'retained-filter-us-h',instrumentId:us.id,quantity:'2',averageCost:'80',eurCost:null}];
        const saved=JSON.stringify(savedHoldings),writes=calls.filter(c=>c.action==='portfolio'&&c.options?.method==='PUT').length;
        const current={date:'2026-10-13',previousDate:'2026-10-12',observedAt:'2026-10-13T13:39:00Z',priceKind:'trade',status:'delayed'};
        quoteOverrides={[xetra.id]:{...current,observedAt:'2026-10-13T09:06:46Z',status:'stale',sourceAvailable:false},[us.id]:current};
        get('reload-portfolio').emit('click');await settle();selectView('active');
        assert.equal(get('active-filter-choice').hidden,true);assert.equal(get('market-filter-status').textContent,'All markets open');
        assert.equal(get('holdings-list').marketRows.every(r=>!r.hidden),true);
        const numbers=get('summary-change').innerHTML;
        assert.doesNotMatch(numbers,/Using earlier data|show-coverage/);
        get('price-update').emit('click');assert.equal(get('earlier-data-section').hidden,false);
        assert.match(get('earlier-data-list').innerHTML,/Fictional Retained Xetra/);get('close-price-update').emit('click');
        selectView('all');assert.equal(get('summary-change').innerHTML,numbers);
        assert.equal(get('active-filter-choice').hidden,true);assert.equal(get('active-only').checked,false);
        selectView('active');selectView('purchase');assert.equal(get('active-filter').hidden,true);
        selectView('active');assert.equal(get('active-only').checked,true);
        quoteOverrides[xetra.id]={...quoteOverrides[xetra.id],previousClose:null};
        get('reload-portfolio').emit('click');await settle();selectView('active');
        assert.equal(get('active-filter-choice').hidden,false);assert.match(get('summary-change').innerHTML,/Data issue/);
        quoteOverrides[xetra.id]=current;get('reload-portfolio').emit('click');await settle();selectView('active');
        assert.equal(get('active-filter-choice').hidden,true);assert.equal(get('market-filter-status').textContent,'All markets open');
        assert.equal(JSON.stringify(savedHoldings),saved);
        assert.equal(calls.filter(c=>c.action==='portfolio'&&c.options?.method==='PUT').length,writes);
      } finally {clock=priorClock;quoteOverrides={};savedHoldings=[];catalogueItems=[old];get('reload-portfolio').emit('click');await settle();}
    });
    await t.test('visible-list receipts, awaiting explanations and popup focus follow filtering without changing holdings',async()=>{
      const priorClock=clock;
      const eu={...listing('receipt-eu','Fictional Awaiting Europe'),type:'Stock',mic:'XAMS',timeZone:'Europe/Amsterdam',closeSchedule:'ams_j0_continuous_2026'};
      const us={...listing('receipt-us','Fictional Ready US'),type:'Stock',currency:'USD',mic:'XNAS',timeZone:'America/New_York',closeSchedule:'us_retained_calendar'};
      try {
        clock=Date.parse('2026-10-13T14:00:00Z');catalogueItems=[eu,us];
        savedHoldings=[{id:'receipt-eu-h',instrumentId:eu.id,quantity:'2',averageCost:'80',eurCost:null},{id:'receipt-us-h',instrumentId:us.id,quantity:'3',averageCost:'70',eurCost:null}];
        const before=JSON.stringify(savedHoldings),callsBefore=calls.length;
        quoteOverrides={
          [eu.id]:{date:'2026-10-12',previousDate:'2026-10-09',observedAt:'2026-10-12T13:45:00Z',priceKind:'trade',status:'delayed'},
          [us.id]:{date:'2026-10-13',previousDate:'2026-10-12',observedAt:'2026-10-13T13:35:00Z',priceKind:'trade',status:'delayed'},
        };
        marketFixtureReceipts=Object.fromEntries(catalogueItems.map(i=>[i.id,{
          receivedAt:i.id===eu.id?'2026-10-13T13:55:00Z':'2026-10-13T13:50:00Z',observationDate:quoteOverrides[i.id].date,observedAt:quoteOverrides[i.id].observedAt}]));
        get('reload-portfolio').emit('click');await settle();selectView('all');
        get('price-update').emit('click',{detail:1});assert.match(get('price-update-list').innerHTML,/Fictional Awaiting Europe/);
        assert.match(get('price-update-title').textContent,/^Received at /);
        assert.doesNotMatch(get('price-update-list').innerHTML,/Received by RichYet/);assert.match(get('price-update-list').innerHTML,/12 Oct/);
        get('close-price-update').emit('click');assert.equal(document.activeElement.id,'price-update');
        assert.equal(get('price-update').classList.contains('pointer-return-focus'),true);
        docEvents.get('keydown')();assert.equal(get('price-update').classList.contains('pointer-return-focus'),false);
        const allReceipt=get('price-update-title').textContent;
        assert.equal(get('price-update').textContent,'Last update');
        selectView('active');assert.equal(get('price-update').textContent,'Last update');
        assert.doesNotMatch(html,/id="awaiting-data"|id="awaiting-data-dialog"/);
        get('price-update').emit('click',{detail:0});assert.match(get('price-update-list').innerHTML,/Fictional Ready US/);
        assert.equal(get('awaiting-data-section').hidden,false);
        assert.equal(get('awaiting-data-title').textContent,'Awaiting first update');
        assert.match(get('awaiting-data-list').innerHTML,/Fictional Awaiting Europe/);
        assert.notEqual(get('price-update-title').textContent,allReceipt,'The visible-list receipt time remains in the popup');
        assert.doesNotMatch(get('price-update-list').innerHTML,/Fictional Awaiting Europe/);
        get('close-price-update').emit('click');assert.equal(document.activeElement.id,'price-update');
        assert.equal(get('price-update').classList.contains('pointer-return-focus'),false,'Keyboard focus keeps its visible indicator');
        selectView('purchase');
        get('price-update').emit('click');assert.equal(get('awaiting-data-section').hidden,true);
        assert.match(get('price-update-list').innerHTML,/Fictional Awaiting Europe/);get('close-price-update').emit('click');
        selectView('active');get('price-update').emit('click',{detail:1});
        quoteOverrides[eu.id]={...quoteOverrides[eu.id],date:'2026-10-13',previousDate:'2026-10-12',observedAt:'2026-10-13T13:54:00Z'};
        marketFixtureReceipts[eu.id]={...marketFixtureReceipts[eu.id],observationDate:'2026-10-13',observedAt:'2026-10-13T13:54:00Z'};
        await runTimers(5*60*1000);assert.equal(get('awaiting-data-section').hidden,true);
        assert.doesNotMatch(get('awaiting-data-list').innerHTML,/Fictional Awaiting Europe/);
        assert.match(get('price-update-list').innerHTML,/Fictional Awaiting Europe/,'An open modal follows the new accepted receipt');
        get('close-price-update').emit('click');assert.equal(document.activeElement.id,'price-update');
        assert.equal(JSON.stringify(savedHoldings),before);assert.equal(calls.slice(callsBefore).some(c=>c.options.method==='PUT'),false);
      } finally {clock=priorClock;marketFixtureReceipts={};quoteOverrides={};savedHoldings=[];catalogueItems=[old];get('reload-portfolio').emit('click');await settle();}
    });
    await t.test('empty open-market view explains first-price and comparison waits, then clears on recovery',async()=>{
      const priorClock=clock;
      const eu={...listing('first-eu','Fictional <Awaiting> One'),type:'Stock',mic:'XAMS',timeZone:'Europe/Amsterdam',closeSchedule:'ams_j0_continuous_2026'};
      const eu2={...eu,id:'first-eu-two',name:'Fictional Awaiting Two'};
      try {
        clock=Date.parse('2026-10-13T08:01:00Z');catalogueItems=[eu,eu2];marketFixtureReceipts={};
        savedHoldings=catalogueItems.map(i=>({id:i.id+'-h',instrumentId:i.id,quantity:'2',averageCost:'80',eurCost:null}));
        quoteOverrides=Object.fromEntries(catalogueItems.map(i=>[i.id,{date:'2026-10-12',previousDate:'2026-10-09',observedAt:'2026-10-12T13:45:00Z',priceKind:'trade',status:'delayed'}]));
        get('reload-portfolio').emit('click');await settle();selectView('active');
        assert.equal(get('holdings-list').marketRows.every(r=>r.hidden),true);
        assert.match(get('summary-change').innerHTML,/Waiting for today’s first updates/);
        assert.doesNotMatch(get('summary-change').innerHTML,/No active holdings|summary-numbers/);
        assert.equal(get('active-filter-choice').hidden,false);
        assert.equal(get('holdings-footer').hidden,false);assert.equal(get('price-update').hidden,false);
        get('price-update').emit('click',{detail:1});
        assert.equal(get('price-update-title').textContent,'Last update');assert.equal(get('price-update-list').hidden,true);
        assert.equal(get('awaiting-data-section').hidden,false);assert.equal(get('price-update-empty').hidden,true);
        assert.match(get('awaiting-data-list').innerHTML,/Fictional &lt;Awaiting&gt; One/);
        assert.match(get('awaiting-data-list').innerHTML,/Fictional Awaiting Two/);
        get('close-price-update').emit('click');assert.equal(document.activeElement.id,'price-update');
        assert.doesNotMatch(get('summary-change').innerHTML,/show-coverage/,'A normal first-price wait alone is not a data failure');
        quoteOverrides[eu.id]={date:'2026-10-13',previousDate:null,previousClose:null,observedAt:'2026-10-13T07:45:00Z',priceKind:'trade',status:'delayed'};
        get('reload-portfolio').emit('click');await settle();
        assert.match(get('summary-change').innerHTML,/Waiting for comparison data/,'a received price must not be described as a missing first update');
        assert.equal(get('holdings-list').marketRows.every(r=>r.hidden),true);
        get('price-update').emit('click',{detail:1});
        assert.equal(get('awaiting-data-title').textContent,'Awaiting data');
        assert.match(get('awaiting-data-list').innerHTML,/Its previous closing price is not available yet/);
        get('close-price-update').emit('click');
        quoteOverrides[eu.id]={...quoteOverrides[eu.id],previousDate:'2026-10-12',previousClose:'100'};
        get('reload-portfolio').emit('click');await settle();
        assert.doesNotMatch(get('summary-change').innerHTML,/Waiting for|No active holdings/);
        assert.match(get('summary-change').innerHTML,/summary-numbers/);
        assert.deepEqual(get('holdings-list').marketRows.filter(r=>!r.hidden).map(r=>r.dataset.holdingId),[eu.id+'-h']);
        savedHoldings=[];get('reload-portfolio').emit('click');await settle();
        assert.equal(get('holdings-footer').hidden,true);assert.equal(get('price-update').hidden,true);
      } finally {clock=priorClock;marketFixtureReceipts={};quoteOverrides={};savedHoldings=[];catalogueItems=[old];get('reload-portfolio').emit('click');await settle();}
    });
    await t.test('Active filters one saved order while All and purchase show the full list and Edit list manages every holding',async()=>{
      clock=Date.parse('2026-10-13T10:00:00Z');
      const eu={...listing('group-eu','Fictional Europe'),type:'Stock',mic:'XAMS',timeZone:'Europe/Amsterdam',closeSchedule:'ams_j0_continuous_2026'};
      const eu2={...eu,id:'group-eu-two',name:'Fictional Europe Two'};
      const us={...listing('group-us','Fictional US'),type:'Stock',currency:'USD',mic:'XNAS',timeZone:'America/New_York',closeSchedule:'us_retained_calendar'};
      const fund={...listing('group-fund','Fictional Daily Fund'),type:'Fund'},other=listing('group-other','Fictional Unknown Hours');
      catalogueItems=[us,fund,eu2,other,eu];
      savedHoldings=catalogueItems.map((i,index)=>({id:'group-'+index,instrumentId:i.id,quantity:String(index+1),averageCost:'90',eurCost:null}));
      const master=structuredClone(savedHoldings);
      quoteOverrides=Object.fromEntries([eu,eu2].map(i=>[i.id,{date:'2026-10-13',previousDate:'2026-10-12',priceKind:'trade',status:'delayed',observedAt:'2026-10-13T09:59:00Z'}]));
      get('reload-portfolio').emit('click');await settle();
      const sections=()=>[...get('holdings-list').innerHTML.matchAll(/data-section-id="([^"]+)"/g)].map(m=>m[1]);
      const order=()=>get('holdings-list').marketRows.filter(row=>!row.hidden).map(row=>row.dataset.holdingId);
      assert.deepEqual(sections(),[]);
      assert.deepEqual(order(),['group-2','group-4']);
      assert.equal(get('holdings-list').weightBars.length,0,'tile bars were replaced by the explicit full-portfolio breakdown');
      const allocation=()=>{get('portfolio-breakdown').emit('click');const result=get('breakdown-list').innerHTML;get('close-breakdown').emit('click');return result;};
      const wholeBreakdown=allocation();assert.equal((wholeBreakdown.match(/class="breakdown-name"/g)||[]).length,master.length,'closed and hidden holdings remain in the breakdown');
      for(const value of ['purchase','all','active']){
        selectView(value);
        assert.deepEqual(sections(),[]);assert.deepEqual(order(),value==='active'?['group-2','group-4']:master.map(h=>h.id));
        assert.equal(allocation(),wholeBreakdown,'filtering never changes portfolio breakdown proportions');
      }
      assert.deepEqual(savedHoldings,master,'display grouping never saves a different order');
      get('manage-list').emit('click');assert.deepEqual(sections(),[]);assert.deepEqual(order(),master.map(h=>h.id));
      const move={dataset:{id:'group-4'},closest(){return this;}};
      get('holdings-list').emit('keydown',{target:move,key:'Home'});await settle();
      assert.deepEqual(savedHoldings.map(h=>h.id),['group-4','group-0','group-1','group-2','group-3']);
      get('manage-list').emit('click');assert.deepEqual(order(),['group-4','group-2']);
      get('reload-portfolio').emit('click');await settle();assert.deepEqual(order(),['group-4','group-2']);
      const toggle={dataset:{id:'group-4'},closest(){return this;},classList:{contains:kind=>kind==='holding-toggle'}};
      get('holdings-list').emit('click',{target:toggle});
      savedHoldings=master;
      get('reload-portfolio').emit('click');await settle();
      assert.deepEqual(order(),['group-2','group-4'],'explicit reload adopts a changed saved order even with the same IDs and expanded holding');
      savedHoldings=[];catalogueItems=[old];quoteOverrides={};get('reload-portfolio').emit('click');await settle();assert.deepEqual(sections(),[]);
    });
    await t.test('BND daily window changes Active visibility and summary without editing saved holdings',async()=>{
      const fund={...listing('window-fund','Fictional Daily Window Fund'),type:'Fund',contributionSchedule:'bnd_daily_amsterdam'};
      const us={...listing('window-us','Fictional Window US'),type:'Stock',currency:'USD',mic:'XNAS',timeZone:'America/New_York',closeSchedule:'us_retained_calendar'};
      catalogueItems=[fund,us];savedHoldings=catalogueItems.map((i,index)=>({id:'window-'+index,instrumentId:i.id,quantity:'2',averageCost:'90',eurCost:null}));
      const saved=JSON.stringify(savedHoldings);
      quoteOverrides={[fund.id]:{date:'2026-10-12',previousDate:'2026-10-09',status:'nav',priceKind:'nav',sourceAvailable:true,
        fundContribution:{valuationDate:'2026-10-12',firstObservedAt:'2026-10-13T06:00:00Z',windowDate:'2026-10-13'}},
        [us.id]:{date:'2026-10-13',previousDate:'2026-10-12',status:'delayed',priceKind:'trade',observedAt:'2026-10-13T14:59:00Z',fx:'0.9',previousFx:'0.9'}};
      clock=Date.parse('2026-10-13T06:59:00Z');get('reload-portfolio').emit('click');await settle();
      assert.equal(get('market-filter-status').textContent,'All markets closed');
      assert.equal(get('holdings-list').marketRows.every(row=>!row.hidden),true);
      assert.match(get('summary-change').innerHTML,/€\s*\+4/,'Retain the fund movement before the reporting window; future US data stays unavailable');
      assert.match(get('holdings-list').innerHTML,/Daily value/);assert.equal(get('holdings-list').observationLabels[0].textContent,'12 Oct');
      const requests=calls.length;await runTimers(60000);
      assert.doesNotMatch(get('summary-change').innerHTML,/Open markets &amp; daily funds/,'the selected Active mode already supplies the scope label');
      assert.match(get('summary-change').innerHTML,/€\s*\+4/);assert.match(get('summary-change').innerHTML,/summary-percent[^>]*>—/,'future US input cannot establish a full reference');
      assert.equal(get('holdings-list').marketRows[0].classList.contains('is-closed'),false);
      assert.equal(calls.length,requests,'clock transition does not query providers or API');
      clock=Date.parse('2026-10-13T15:29:00Z');get('reload-portfolio').emit('click');await settle();
      assert.match(get('summary-change').innerHTML,/€\s*\+8/);
      const edit={dataset:{id:'window-0'},closest(){return this;},classList:{contains:kind=>kind==='edit-holding'}};
      get('holdings-list').emit('click',{target:edit});get('quantity').value='7,25';get('quantity').focus();
      const callsBefore=calls.length;await runTimers(60000);
      assert.equal(get('quantity').value,'7,25');assert.equal(document.activeElement.id,'quantity');assert.equal(calls.length,callsBefore);
      assert.match(get('summary-change').innerHTML,/€\s*\+4/);assert.doesNotMatch(get('summary-change').innerHTML,/daily funds/);
      get('form-portfolio-return').emit('click');await settle();
      assert.deepEqual(get('holdings-list').marketRows.filter(row=>!row.hidden).map(row=>row.dataset.holdingId),['window-1']);
      assert.equal(get('holdings-list').marketRows[0].hidden,true);
      assert.equal(get('holdings-list').marketRows[0].classList.contains('is-closed'),true);
      assert.equal(JSON.stringify(savedHoldings),saved);
      clock=Date.parse('2026-10-14T10:00:00Z');get('reload-portfolio').emit('click');await settle();
      assert.match(get('summary-change').innerHTML,/No active holdings/,'same NAV does not re-enter tomorrow');
      selectView('purchase');
      assert.match(get('summary-change').innerHTML,/€\s*\+46/,'both holdings still contribute to Since purchase');
      selectView('active');
      savedHoldings=[];catalogueItems=[old];quoteOverrides={};get('reload-portfolio').emit('click');await settle();
    });
    await t.test('an expanded row retains its node across opening, mode switching and cache refresh in saved order',async()=>{
      clock=Date.parse('2026-10-13T13:29:00Z');
      const us={...listing('stable-us','Fictional Stable US'),type:'Stock',currency:'USD',mic:'XNAS',timeZone:'America/New_York',closeSchedule:'us_retained_calendar'};
      const eu={...listing('stable-eu','Fictional Stable Europe'),type:'Stock',mic:'XAMS',timeZone:'Europe/Amsterdam',closeSchedule:'ams_j0_continuous_2026'};
      catalogueItems=[us,eu];savedHoldings=catalogueItems.map((i,index)=>({id:'stable-'+index,instrumentId:i.id,quantity:'2',averageCost:'90',eurCost:null}));
      quoteOverrides={[us.id]:{date:'2026-10-12',previousDate:'2026-10-09'},[eu.id]:{date:'2026-10-13',previousDate:'2026-10-12',priceKind:'trade',status:'delayed',observedAt:'2026-10-13T13:28:00Z'}};
      get('reload-portfolio').emit('click');await settle();
      const toggle={dataset:{id:'stable-0'},closest(){return this;},classList:{contains:kind=>kind==='holding-toggle'}};
      selectView('purchase');
      get('holdings-list').emit('click',{target:toggle});get('edit-stable-0').focus();
      const beforeNodes=get('holdings-list').marketRows,markup=get('holdings-list').innerHTML,callsBefore=calls.length;
      const value=get('portfolio-value').textContent,purchase=get('summary-change').innerHTML;
      await runTimers(60000);
      assert.equal(get('holdings-list').marketRows,beforeNodes,'no row rebuild at the opening');
      assert.deepEqual([...get('holdings-list').innerHTML.matchAll(/data-section-id="([^"]+)"/g)].map(m=>m[1]),[...markup.matchAll(/data-section-id="([^"]+)"/g)].map(m=>m[1]),'no section movement while expanded');
      assert.equal(document.activeElement.id,'edit-stable-0');assert.equal(calls.length,callsBefore);
      const row=beforeNodes.find(row=>row.dataset.holdingId==='stable-0');
      assert.match(row.querySelector('.details-grid').innerHTML,/<h3>Latest change<\/h3>[\s\S]*Amount<\/span><strong class="positive">€\s*\+3,60/);
      assert.doesNotMatch(row.querySelector('.details-grid').innerHTML,/Waiting for today’s price and comparison/,'Since purchase keeps the all-holdings opposite comparison');
      assert.match(row.querySelector('.details-grid').innerHTML,/Since-purchase change[\s\S]*Estimated/,'purchase estimate remains in expanded details');
      assert.equal(get('portfolio-value').textContent,value);assert.equal(get('summary-change').innerHTML,purchase);
      assert.match(row.querySelector('.price-timestamp').textContent,/12 Oct 2026/,'actual older closing date stays visible');
      selectView('active');
      assert.deepEqual(get('holdings-list').marketRows.map(row=>row.dataset.holdingId),['stable-0','stable-1'],'switching comparison preserves saved order');
      assert.doesNotMatch(get('holdings-list').innerHTML,/data-section-id/);
      const openRow=get('holdings-list').marketRows.find(row=>row.dataset.holdingId==='stable-0');
      assert.match(openRow.querySelector('.row-change').innerHTML,/change-percent">—/);
      quoteOverrides[us.id]={date:'2026-10-13',previousDate:'2026-10-12',priceKind:'trade',status:'delayed',observedAt:'2026-10-13T13:30:00Z'};
      clock+=5000;foreground();await runTimers(100);
      assert.equal(get('holdings-list').marketRows.find(row=>row.dataset.holdingId==='stable-0'),openRow,'cache arrival patches the same expanded row');
      assert.match(openRow.querySelector('.row-change').innerHTML,/\+2,00%/);
      get('holdings-list').emit('click',{target:toggle});
      assert.deepEqual(get('holdings-list').marketRows.map(row=>row.dataset.holdingId),['stable-0','stable-1']);
      assert.doesNotMatch(get('holdings-list').innerHTML,/data-section-id="closed"/,'the empty former section disappears');
      assert.deepEqual(savedHoldings.map(h=>h.id),['stable-0','stable-1']);
      savedHoldings=[];catalogueItems=[old];quoteOverrides={};get('reload-portfolio').emit('click');await settle();
    });
    await t.test('exchange overview omits observation labels while expanded details retain dates and times',async()=>{
      const previousClock=clock;
      const stock={...listing('time-source','Fictional Timed Shares'),type:'Stock',timeZone:'Europe/Amsterdam',mic:'XAMS',closeSchedule:'ams_j0_continuous_2026'};
      const set=async(instrument,quote)=>{
        catalogueItems=[instrument];savedHoldings=[{id:'time-holding',instrumentId:instrument.id,quantity:'1',averageCost:'90',eurCost:null}];
        quoteOverrides={[instrument.id]:quote};get('reload-portfolio').emit('click');await settle();
      };
      try {
        for (const now of ['2026-10-13T10:00:00Z','2026-10-13T19:00:00Z']) {
          clock=Date.parse(now);
          await set(stock,{date:'2026-10-13',previousDate:'2026-10-12',priceKind:'trade',status:'delayed',observedAt:'2026-10-13T09:45:00Z'});
          assert.equal(get('holdings-list').observationLabels.length,0);
          const toggle={dataset:{id:'time-holding'},closest(){return this;},classList:{contains:kind=>kind==='holding-toggle'}};
          get('holdings-list').emit('click',{target:toggle});
          assert.match(get('holdings-list').innerHTML,/Price as of 13 Oct 2026/);
          assert.match(get('holdings-list').innerHTML,/11:45/);
          get('holdings-list').emit('click',{target:toggle});
        }
        await set({...stock,type:'Fund'},{date:'2026-10-12',priceKind:'nav',status:'nav',observedAt:null});
        assert.equal(get('holdings-list').observationLabels[0].textContent,'12 Oct','daily funds keep their valuation date');
        assert.doesNotMatch(get('holdings-list').innerHTML,/holding-section-title|data-section-id/,'no list headers remain');
      } finally {
        clock=previousClock;quoteOverrides={};savedHoldings=[];catalogueItems=[old];get('reload-portfolio').emit('click');await settle();
      }
    });
    await t.test('two comparisons and Open markets only change the selected scope, preserve expanded nodes, and explain Partial on demand',async()=>{
      clock=Date.parse('2026-10-14T07:01:00Z');
      const eu={...listing('mode-eu','Fictional Morning Europe'),type:'Stock',mic:'XAMS',timeZone:'Europe/Amsterdam',closeSchedule:'ams_j0_continuous_2026'};
      const us={...listing('mode-us','Fictional Overnight US'),type:'Stock',currency:'USD',mic:'XNAS',timeZone:'America/New_York',closeSchedule:'us_retained_calendar'};
      const fund={...listing('mode-fund','Fictional Dated Fund'),type:'Fund',contributionSchedule:'bnd_daily_amsterdam'};
      catalogueItems=[us,eu,fund];savedHoldings=catalogueItems.map((i,n)=>({id:'mode-'+n,instrumentId:i.id,quantity:'2',averageCost:'90',eurCost:null}));
      quoteOverrides=Object.fromEntries(catalogueItems.map(i=>[i.id,{date:'2026-10-13',previousDate:'2026-10-12',price:'110',previousClose:'100',...(i.type==='Fund'?{status:'nav',priceKind:'nav'}:{})}]));
      get('reload-portfolio').emit('click');await settle();
      const switchMode=value=>selectView(value);
      switchMode('active');
      const saved=JSON.stringify(savedHoldings),value=get('portfolio-value').textContent,requests=calls.length;
      const toggle={dataset:{id:'mode-1'},closest(){return this;},classList:{contains:kind=>kind==='holding-toggle'}};
      get('holdings-list').emit('click',{target:toggle});
      const nodes=get('holdings-list').marketRows,order=nodes.map(row=>row.dataset.holdingId);
      const euro=nodes.find(row=>row.dataset.holdingId==='mode-1'),dollar=nodes.find(row=>row.dataset.holdingId==='mode-0');
      assert.match(euro.querySelector('.row-change').innerHTML,/change-percent">—/);
      assert.match(get('summary-change').innerHTML,/Waiting for today’s first updates/);
      assert.equal(get('summary-status').hidden,true,'ordinary waiting has no repetitive sentence');
      switchMode('all');
      assert.equal(get('portfolio-screen').dataset.comparisonMode,'all');
      assert.match(get('summary-change').innerHTML,/€\s*\+58/);assert.match(get('summary-change').innerHTML,/\+10,00%/);
      assert.doesNotMatch(get('summary-change').innerHTML,/Partial/);
      assert.match(euro.querySelector('.row-change').innerHTML,/\+10,00%/,'All keeps the valid prior-session pair even after opening');
      assert.match(dollar.querySelector('.row-change').className,/positive/);assert.doesNotMatch(dollar.querySelector('.row-change').className,/is-latest/,'closed All results retain gain colors');
      assert.equal(dollar.classList.contains('is-closed'),true,'calendar styling is retained');assert.equal(dollar.hidden,false);
      for(const mode of ['purchase','all','active','all']){
        switchMode(mode);assert.equal(get('portfolio-value').textContent,value);
        assert.equal(get('active-filter').hidden,mode==='purchase');
        assert.equal(radios.find(r=>r.value===(mode==='purchase'?'purchase':'latest')).checked,true);
        if(mode!=='purchase')assert.equal(get('active-only').checked,mode==='active');
        assert.deepEqual(nodes.filter(row=>!row.hidden).map(row=>row.dataset.holdingId),mode==='active'?[]:order);
        assert.equal(get('holdings-list').marketRows,nodes,'mode switches never replace rows');
        assert.deepEqual(get('holdings-list').marketRows.map(row=>row.dataset.holdingId),order);
        assert.ok(euro.querySelector('.details-grid'),'the same holding stays expanded');
      }
      selectView('active');
      get('period-switch').emit('change',{target:{value:'purchase',matches:()=>true}});
      assert.equal(get('active-filter').hidden,true);assert.equal(get('active-only').checked,true);
      get('period-switch').emit('change',{target:{value:'latest',matches:()=>true}});
      assert.equal(get('portfolio-screen').dataset.comparisonMode,'active');assert.equal(get('active-filter').hidden,false);assert.equal(get('active-only').checked,true);
      selectView('all');
      assert.equal(calls.length,requests,'scope switches are local and make no API calls');assert.equal(JSON.stringify(savedHoldings),saved);
      // A genuine missing baseline is not repaired by All or by switching modes.
      quoteOverrides[eu.id]={...quoteOverrides[eu.id],date:'2026-10-14',previousDate:'2026-10-13',observedAt:'2026-10-14T07:00:00Z',status:'delayed',priceKind:'trade',previousClose:null};
      get('reload-portfolio').emit('click');await settle();switchMode('all');
      assert.match(get('summary-change').innerHTML,/coverage-button[^>]*>(?:Partial|Data issue)/);
      assert.match(get('summary-change').innerHTML,/€\s*\+38/);assert.match(get('summary-change').innerHTML,/summary-percent[^>]*>—/,'an incomplete full reference cannot shrink the denominator');
      assert.equal(get('summary-status').textContent,'');
      const coverage=get('show-coverage');get('summary-change').emit('click',{target:coverage});
      assert.equal(get('coverage-dialog').open,true);assert.equal(document.activeElement.id,'close-coverage');
      assert.match(get('coverage-list').innerHTML,/Fictional Morning Europe/);assert.doesNotMatch(get('coverage-list').innerHTML,/Fictional Overnight US|Fictional Dated Fund/);
      get('close-coverage').emit('click');assert.equal(get('coverage-dialog').open,false);assert.equal(document.activeElement.id,'show-coverage');
      // The same complete daily pairs remain accessible after every market closes.
      quoteOverrides[eu.id]={date:'2026-10-14',previousDate:'2026-10-13',price:'110',previousClose:'100'};
      clock=Date.parse('2026-10-14T22:00:00Z');get('reload-portfolio').emit('click');await settle();
      switchMode('active');assert.equal(get('market-filter-status').textContent,'All markets closed');
      assert.match(get('summary-change').innerHTML,/€\s*\+58/);assert.equal(get('holdings-list').marketRows.every(row=>!row.hidden),true);
      switchMode('all');assert.match(get('summary-change').innerHTML,/€\s*\+58/);assert.doesNotMatch(get('summary-change').innerHTML,/Partial/);
      assert.equal(JSON.stringify(savedHoldings),saved);
      switchMode('active');savedHoldings=[];catalogueItems=[old];quoteOverrides={};get('reload-portfolio').emit('click');await settle();
    });
    get('empty-add').emit('click');await settle();
    await t.test('blank search shows no suggestions; short input does not fetch and longer search is debounced',async()=>{
      assert.equal(get('holding-fields').hidden,true);
      assert.equal(get('search-results').hidden,true);assert.equal(get('search-results').innerHTML,'');
      assert.equal(get('instrument-search').attributes.get('aria-expanded'),'false');
      const count=calls.length;get('instrument-search').value='F';get('instrument-search').emit('input');await settle();assert.equal(calls.length,count);
      get('instrument-search').value='Fictional';get('instrument-search').emit('input');await settle();assert.equal(calls.length,count);
      await runDebounce();assert.equal(calls.at(-1).action,'search');assert.match(get('search-results').innerHTML,/Check prices/);assert.equal(get('holding-fields').hidden,true);
    });
    await t.test('selection shows checking without personal fields, then opens only a validated ready pair',async()=>{
      const callStart=calls.length;let finish;handlePrepare=()=>new Promise(resolve=>{finish=resolve;});await select(fresh.id);
      assert.match(get('catalogue-status').textContent,/Checking Fictional New/);assert.equal(get('holding-fields').hidden,true);
      const before=marketCalls();clock+=5000;foreground();await runTimers(100);assert.equal(marketCalls(),before);
      assert.equal(get('instrument-search').value,'Fictional');
      finish({status:'ready',catalogue:catalogue([old,fresh])});await settle();assert.equal(get('holding-fields').hidden,false);assert.equal(document.activeElement.id,'quantity');
      assert.equal(calls.slice(callStart).filter(c=>c.action==='portfolio').every(c=>c.options.method==='GET'),true);
      await runTimers(100);assert.equal(marketCalls(),before);
      get('change-selection').emit('click');
    });
    await t.test('unavailable and busy checks retain search and a deliberate retry before personal entry',async()=>{
      handlePrepare=()=>({status:'unavailable',message:'Comparison not published yet.'});await select(fresh.id);
      assert.equal(get('holding-fields').hidden,true);assert.equal(get('instrument-search').value,'Fictional');assert.match(get('catalogue-status').textContent,/not published/);assert.equal(get('retry-preparation').hidden,false);
      handlePrepare=()=>({status:'busy'});get('retry-preparation').emit('click');await settle();assert.match(get('catalogue-status').textContent,/try again shortly/);assert.equal(get('holding-fields').hidden,true);
    });
    await t.test('new query cancels a preparation; its late ready response never opens the old holding',async()=>{
      let finish,signal;handlePrepare=(_body,options)=>{signal=options.signal;return new Promise(resolve=>{finish=resolve;});};await select(fresh.id);
      await search('Another');assert.equal(signal.aborted,true);finish({status:'ready',catalogue:catalogue([old,fresh])});await settle();
      assert.equal(get('holding-fields').hidden,true);assert.equal(get('instrument-search').value,'Another');
    });
    await t.test('a failed search can retry the same query; Load more appends the next page',async()=>{
      handleSearch=()=>{throw new Error('Source unavailable');};await search('Fictional');assert.equal(get('search-retry').hidden,false);assert.equal(get('instrument-search').value,'Fictional');
      handleSearch=params=>({instruments:[listing('page-'+params.get('offset'),'Fictional Page '+params.get('offset'))],hasMore:params.get('offset')==='0',offset:Number(params.get('offset'))});
      get('search-retry').emit('click');await settle();assert.match(get('search-results').innerHTML,/Page 0/);assert.equal(get('search-more').hidden,false);
      get('search-more').emit('click');await settle();assert.equal(calls.at(-1).params.get('offset'),'1');assert.match(get('search-results').innerHTML,/Page 0/);assert.match(get('search-results').innerHTML,/Page 1/);assert.equal(get('search-more').hidden,true);
    });
    await t.test('clearing the query removes results and ignores an in-flight response',async()=>{
      let finish,signal;handleSearch=(_params,options)=>{signal=options.signal;return new Promise(resolve=>{finish=resolve;});};
      await search('Pending');await search('   ');
      assert.equal(signal.aborted,true);assert.equal(get('search-results').hidden,true);
      assert.equal(get('search-results').innerHTML,'');assert.equal(get('search-more').hidden,true);
      finish({instruments:[fresh],hasMore:false,offset:0});await settle();
      assert.equal(get('search-results').hidden,true);assert.equal(get('search-results').innerHTML,'');
      const count=calls.length;get('instrument-search').emit('keydown',{key:'ArrowDown'});get('instrument-search').emit('keydown',{key:'Enter'});await settle();
      assert.equal(calls.length,count);assert.equal(get('holding-fields').hidden,true);
    });
    await t.test('keyboard selection rechecks an initially Ready listing instead of trusting cached readiness',async()=>{
      handleSearch=()=>({instruments:[{...old,preparationStatus:'ready'}],hasMore:false,offset:0});
      await search('Fictional Ready');let finish;handlePrepare=()=>new Promise(resolve=>{finish=resolve;});
      get('instrument-search').emit('keydown',{key:'ArrowDown'});get('instrument-search').emit('keydown',{key:'Enter'});await settle();
      assert.equal(calls.at(-1).action,'prepare');assert.equal(get('holding-fields').hidden,true);
      finish({status:'ready',catalogue:catalogue([old,fresh])});await settle();assert.equal(get('holding-fields').hidden,false);get('change-selection').emit('click');
    });
    await t.test('leaving Add cancels pending work and cannot reopen the form after a late response',async()=>{
      let finish,signal;handlePrepare=(_body,options)=>{signal=options.signal;return new Promise(resolve=>{finish=resolve;});};await select(old.id);
      get('form-portfolio-return').emit('click');await settle();assert.equal(signal.aborted,true);assert.equal(get('investment-screen').hidden,true);
      finish({status:'ready',catalogue:catalogue([old,fresh])});await settle();assert.equal(get('investment-screen').hidden,true);assert.equal(get('portfolio-screen').hidden,false);
    });
    await t.test('retained refresh failures keep both views numeric, explain inclusion consistently and recover without writes',async()=>{
      const priorClock=clock;
      const eu={...listing('retained-eu','Fictional Retained Europe'),type:'Stock',mic:'XAMS',timeZone:'Europe/Amsterdam',closeSchedule:'ams_j0_continuous_2026'};
      const us={...listing('retained-us','Fictional Closed US'),type:'Stock',currency:'USD',mic:'XNAS',timeZone:'America/New_York',closeSchedule:'us_retained_calendar'};
      try {
        clock=Date.parse('2026-10-16T11:00:00Z');catalogueItems=[eu,us];
        savedHoldings=[{id:'retained-eu-h',instrumentId:eu.id,quantity:'3',averageCost:'60',eurCost:null},{id:'retained-us-h',instrumentId:us.id,quantity:'2',averageCost:'90',eurCost:null}];
        const original=JSON.stringify(savedHoldings),writes=calls.filter(c=>c.action==='portfolio'&&c.options?.method==='PUT').length;
        const valid={price:'105',previousClose:'100',date:'2026-10-16',previousDate:'2026-10-15',observedAt:'2026-10-16T08:44:00Z',priceKind:'trade',status:'delayed'};
        quoteOverrides={[eu.id]:valid};get('reload-portfolio').emit('click');await settle();selectView('active');
        const numbers=get('summary-change').innerHTML,rows=get('holdings-list').innerHTML;
        quoteOverrides[eu.id]={...valid,status:'stale',sourceAvailable:false};
        get('reload-portfolio').emit('click');await settle();selectView('active');
        assert.equal(get('holdings-list').marketRows.find(r=>r.dataset.holdingId==='retained-eu-h').hidden,false);
        assert.match(get('summary-change').innerHTML,/€\s*\+15/);assert.doesNotMatch(get('summary-change').innerHTML,/Using earlier data|show-coverage/);
        get('price-update').emit('click',{detail:1});
        const explanation=get('earlier-data-list').innerHTML;
        assert.match(explanation,/Fictional Retained Europe/);assert.match(explanation,/last valid price and comparison/);assert.doesNotMatch(explanation,/left out/);
        assert.equal(get('earlier-data-section').hidden,false);get('close-price-update').emit('click');
        assert.equal(document.activeElement.id,'price-update');assert.equal(get('price-update').classList.contains('pointer-return-focus'),true);
        selectView('all');get('price-update').emit('click');
        assert.equal(get('earlier-data-list').innerHTML,explanation);get('close-price-update').emit('click');
        selectView('purchase');assert.match(get('summary-change').innerHTML,/Estimated/);assert.doesNotMatch(get('summary-change').innerHTML,/Using earlier data|Data issue/);assert.equal(get('summary-status').textContent,'');
        const toggle={dataset:{id:'retained-eu-h'},closest(){return this;},classList:{contains:kind=>kind==='holding-toggle'}};
        get('holdings-list').emit('click',{target:toggle});
        assert.equal(get('holdings-list').marketRows[0].querySelector('.holding-data-note').hidden,true);
        assert.match(get('holdings-list').innerHTML,/Price as of 16 Oct 2026/,'source dates remain visible');
        get('holdings-list').emit('click',{target:toggle});
        quoteOverrides[eu.id]={...valid,previousClose:null,sourceAvailable:false};get('reload-portfolio').emit('click');await settle();selectView('all');
        get('summary-change').emit('click',{target:get('show-coverage')});assert.match(get('coverage-list').innerHTML,/latest change is unavailable and is left out/);get('close-coverage').emit('click');
        quoteOverrides[eu.id]=valid;get('reload-portfolio').emit('click');await settle();selectView('active');
        assert.equal(get('summary-change').innerHTML,numbers);assert.equal(get('holdings-list').innerHTML,rows);
        assert.equal(JSON.stringify(savedHoldings),original);assert.equal(calls.filter(c=>c.action==='portfolio'&&c.options?.method==='PUT').length,writes);
      } finally {clock=priorClock;quoteOverrides={};savedHoldings=[];catalogueItems=[old];get('reload-portfolio').emit('click');await settle();}
    });
    await t.test('Open markets shows the exact available subset, partial coverage and session transitions without changing holdings',async()=>{
      const priorClock=clock;
      const euro={...listing('open-eur','Fictional European Share'),type:'Stock',mic:'XAMS',timeZone:'Europe/Amsterdam',closeSchedule:'ams_j0_continuous_2026'};
      const second={...euro,id:'open-second',name:'Fictional Second European Share'};
      const us={...listing('open-us','Fictional US Share'),type:'Stock',currency:'USD',mic:'XNAS',timeZone:'America/New_York',closeSchedule:'us_retained_calendar'};
      const fund={...listing('open-fund','Fictional Daily Fund'),type:'Fund'};
      const current={price:'110',previousClose:'100',priceKind:'trade',date:'2026-10-13',previousDate:'2026-10-12',observedAt:'2026-10-13T09:59:00Z',status:'delayed'};
      const switchPeriod=value=>selectView(value);
      const load=async()=>{get('reload-portfolio').emit('click');await settle();switchPeriod('active');};
      try {
        clock=Date.parse('2026-10-13T10:00:00Z');catalogueItems=[euro,second,us,fund];
        savedHoldings=catalogueItems.map((i,n)=>({id:'open-holding-'+n,instrumentId:i.id,quantity:'2',averageCost:'90',eurCost:null}));
        const saved=JSON.stringify(savedHoldings);
        quoteOverrides={[euro.id]:current,[second.id]:{...current,previousClose:null}};
        await load();
        assert.doesNotMatch(get('summary-change').innerHTML,/Partial/);
        assert.match(get('summary-change').innerHTML,/summary-percent[^>]*>—/,'missing reference withholds percentage');
        assert.match(get('summary-change').innerHTML,/Data issue/);
        assert.match(get('summary-change').innerHTML,/€\s*\+20/);
        assert.equal(get('summary-status').textContent,'');
        const value=get('portfolio-value').textContent;
        switchPeriod('purchase');assert.doesNotMatch(get('summary-change').innerHTML,/Open markets|Partial/);
        assert.match(get('summary-change').innerHTML,/Estimated/);assert.equal(get('portfolio-value').textContent,value);
        quoteOverrides[second.id]=current;await load();
        assert.match(get('summary-change').innerHTML,/€\s*\+40/);assert.doesNotMatch(get('summary-change').innerHTML,/Partial/);
        assert.equal(get('summary-status').textContent,'');
        quoteOverrides[second.id]={...current,previousClose:null};await load();
        assert.match(get('summary-change').innerHTML,/Data issue/,'a previously ready holding retains a small warning');
        assert.equal(get('holdings-list').marketRows.find(row=>row.dataset.holdingId==='open-holding-1').hidden,false);
        assert.match(get('summary-change').innerHTML,/€\s*\+20/,'failed data never contributes an invented zero or older change');
        get('summary-change').emit('click',{target:get('show-coverage')});
        assert.match(get('coverage-list').innerHTML,/Fictional Second European Share/);get('close-coverage').emit('click');
        quoteOverrides[second.id]=current;await load();assert.doesNotMatch(get('summary-change').innerHTML,/Data issue/);
        // Opening alone does not admit yesterday's US movement or create Partial.
        clock=Date.parse('2026-10-13T13:29:00Z');await load();const noFetch=marketCalls();
        await runTimers(60000);assert.doesNotMatch(get('summary-change').innerHTML,/Partial/);
        assert.match(get('summary-change').innerHTML,/€\s*\+40/);assert.equal(marketCalls(),noFetch);
        quoteOverrides[us.id]={...current,observedAt:'2026-10-13T13:39:00Z',fx:'0.9',previousFx:'0.9'};
        clock=Date.parse('2026-10-13T13:40:00Z');await load();
        assert.match(get('summary-change').innerHTML,/€\s*\+58/);assert.match(get('summary-change').innerHTML,/\+7,44%/,'closed fund remains in the whole-portfolio reference');
        assert.doesNotMatch(get('summary-change').innerHTML,/Partial/);
        clock=Date.parse('2026-10-13T15:39:00Z');await load();
        const edit={dataset:{id:'open-holding-0'},closest(){return this;},classList:{contains:kind=>kind==='edit-holding'}};
        get('holdings-list').emit('click',{target:edit});get('quantity').value='123,456';
        const editCalls=calls.length;await runTimers(60000);
        assert.equal(get('quantity').value,'123,456');assert.equal(get('investment-screen').hidden,false);
        assert.match(get('summary-change').innerHTML,/€\s*\+18/);assert.equal(calls.length,editCalls);
        get('form-portfolio-return').emit('click');await settle();
        clock=Date.parse('2026-10-13T19:59:00Z');await load();await runTimers(60000);
        assert.match(get('summary-change').innerHTML,/No active holdings/);
        assert.doesNotMatch(get('summary-change').innerHTML,/summary-amount|summary-percent/);
        assert.match(get('holdings-list').innerHTML,/change-amount[^>]*>€\s*\+20/,'individual last comparisons remain visible');
        assert.equal(JSON.stringify(savedHoldings),saved);
        // An unknown schedule is neither silently included nor declared closed.
        catalogueItems.push(old);savedHoldings.push({id:'unknown',instrumentId:old.id,quantity:'1',averageCost:'90',eurCost:null});
        await load();assert.match(get('summary-change').innerHTML,/Market hours unavailable/);
        clock=Date.parse('2026-10-13T10:00:00Z');await load();assert.match(get('summary-change').innerHTML,/Data issue/);
        assert.equal(get('summary-status').textContent,'');
        catalogueItems=[euro];savedHoldings=savedHoldings.slice(0,1);quoteOverrides={[euro.id]:{...current,previousClose:null}};
        await load();assert.equal(get('summary-status').textContent,'');
        assert.match(get('summary-change').innerHTML,/summary-amount[^>]*>—/);assert.match(get('summary-change').innerHTML,/Data issue/);assert.doesNotMatch(get('summary-change').innerHTML,/0,00%/);
        catalogueItems=[fund];savedHoldings=[{id:'only-fund',instrumentId:fund.id,quantity:'1',averageCost:'90',eurCost:null}];
        quoteOverrides={};await load();assert.equal(get('market-filter-status').textContent,'Daily value · 9 Oct');assert.match(get('summary-change').innerHTML,/\+2,00%/);
        switchPeriod('purchase');assert.match(get('summary-change').innerHTML,/€\s*\+12/);
      } finally {
        clock=priorClock;quoteOverrides={};savedHoldings=[];catalogueItems=[old];await load();
      }
    });
    await t.test('EUR purchase estimates label the row, details and complete total and clear when inputs change',async()=>{
      const usd={...listing('fixture-usd','Fictional Dollar Share'),currency:'USD',type:'Stock'};
      catalogueItems=[usd];savedHoldings=[{id:'usd-holding',instrumentId:usd.id,quantity:'10',averageCost:'90',eurCost:null}];
      get('reload-portfolio').emit('click');await settle();
      assert.equal(get('data-mode-notice').hidden,true);
      assert.equal(get('summary-change').hidden,false);
      const toggle={dataset:{id:'usd-holding'},closest(){return this;},classList:{contains:kind=>kind==='holding-toggle'}};
      get('holdings-list').emit('click',{target:toggle});
      assert.match(get('holdings-list').innerHTML,/aria-controls="estimate-dialog"><span>Estimated/);
      assert.match(get('holdings-list').innerHTML,/€\s*\+108/);
      assert.match(get('holdings-list').innerHTML,/<span>Amount<\/span><strong class="positive">€\s*\+108,00<\/strong>/);
      assert.match(get('holdings-list').innerHTML,/<span>Percentage<\/span><strong class="positive">\+13,33%<\/strong>/);
      const switchPeriod=value=>selectView(value);
      switchPeriod('purchase');
      assert.match(get('holdings-list').marketRows[0].querySelector('.details-grid').innerHTML,/Since-purchase change[\s\S]*Estimated/);
      assert.doesNotMatch(get('holdings-list').innerHTML,/row-estimate-button/);
      assert.match(get('holdings-list').innerHTML,/change-amount">€\s*\+108/);
      assert.match(get('summary-change').innerHTML,/€\s*\+108/);
      assert.match(get('summary-change').innerHTML,/>Estimated</);
      assert.equal(get('summary-change').attributes.get('aria-label'),'Estimated portfolio change since purchase');
      assert.equal(get('summary-status').textContent,'');
      assert.equal(savedHoldings[0].eurCost,null);
      const beforeExplanation=calls.length, savedBeforeExplanation=JSON.stringify(savedHoldings);
      const explanation=Object.assign(get('estimate-usd-holding'),{dataset:{id:'usd-holding',estimate:'holding'},closest:selector=>selector==='[hidden]'?null:explanation});
      get('holdings-list').emit('click',{target:explanation,detail:1});
      assert.equal(get('estimate-dialog').open,true);
      assert.match(get('estimate-list').innerHTML,/Fictional Dollar Share/);
      assert.match(get('estimate-list').innerHTML,/gain or loss in US dollars.*latest available exchange rate/);
      assert.match(get('estimate-guidance').textContent,/excludes currency changes since purchase/);
      get('close-estimate').emit('click');assert.equal(get('estimate-dialog').open,false);
      assert.equal(document.activeElement.id,'estimate-usd-holding');assert.equal(explanation.classList.contains('pointer-return-focus'),true);
      docEvents.get('keydown')();assert.equal(explanation.classList.contains('pointer-return-focus'),false);
      get('summary-change').emit('click',{target:{closest:selector=>selector==='button[data-estimate]'?{id:'estimate-portfolio',dataset:{estimate:'portfolio'}}:null}});
      assert.equal(get('estimate-dialog').open,true);assert.match(get('estimate-description').textContent,/includes estimates for/);
      assert.match(get('estimate-list').innerHTML,/Fictional Dollar Share/);assert.equal(get('estimate-list').hidden,false);
      get('close-estimate').emit('click');
      assert.equal(calls.length,beforeExplanation,'explanations make no API requests');assert.equal(JSON.stringify(savedHoldings),savedBeforeExplanation);
      switchPeriod('all');
      get('holdings-list').emit('click',{target:explanation});assert.equal(get('estimate-dialog').open,true,'expanded purchase estimate works in All holdings');
      get('close-estimate').emit('click');
      switchPeriod('active');
      assert.equal(get('market-filter-status').textContent,'Market hours unavailable');assert.match(get('summary-change').innerHTML,/\+2,00%/);
      assert.doesNotMatch(get('summary-change').innerHTML,/Estimated/);
      assert.doesNotMatch(get('holdings-list').innerHTML,/row-estimate-button/);
      assert.match(get('holdings-list').innerHTML,/aria-controls="estimate-dialog"><span>Estimated/);
      switchPeriod('purchase');
      savedHoldings=[{...savedHoldings[0],eurCost:'1100'}];
      get('reload-portfolio').emit('click');await settle();
      assert.doesNotMatch(get('holdings-list').innerHTML,/aria-controls="estimate-dialog"><span>Estimated/);
      assert.match(get('holdings-list').innerHTML,/€\s*-182/);
      assert.match(get('summary-change').innerHTML,/€\s*-182/);
      assert.doesNotMatch(get('summary-change').innerHTML,/Estimated/);
      savedHoldings=[{...savedHoldings[0],eurCost:null,averageCost:null}];
      get('reload-portfolio').emit('click');await settle();
      assert.doesNotMatch(get('holdings-list').innerHTML,/aria-controls="estimate-dialog"><span>Estimated/);
      assert.doesNotMatch(get('summary-change').innerHTML,/Estimated/);
      assert.equal(get('summary-change').hidden,false);
      assert.match(get('summary-change').innerHTML,/summary-amount[^>]*>—/);
      assert.match(get('summary-status').textContent,/missing average purchase prices/);
      savedHoldings=[{...savedHoldings[0],averageCost:'0'}];
      get('reload-portfolio').emit('click');await settle();
      assert.match(get('summary-change').innerHTML,/€\s*\+918/);
      assert.match(get('summary-change').innerHTML,/>Estimated</);
      assert.match(get('summary-status').textContent,/purchase cost is zero/);
      quoteOverrides={[usd.id]:{fx:null}};
      get('reload-portfolio').emit('click');await settle();
      assert.doesNotMatch(get('summary-change').innerHTML,/Estimated/);
      assert.doesNotMatch(get('holdings-list').innerHTML,/aria-controls="estimate-dialog"><span>Estimated/);
      assert.equal(get('summary-change').hidden,false);
      assert.match(get('summary-change').innerHTML,/summary-amount[^>]*>—/);
      assert.match(get('summary-status').textContent,/Waiting for valuation data/);
      quoteOverrides={};
      catalogueItems.push(old);savedHoldings.push({id:'incomplete-holding',instrumentId:old.id,quantity:'1',averageCost:null,eurCost:null});
      get('reload-portfolio').emit('click');await settle();
      assert.match(get('holdings-list').innerHTML,/aria-controls="estimate-dialog"><span>Estimated/);
      assert.doesNotMatch(get('summary-change').innerHTML,/Estimated/);
      assert.equal(get('summary-change').hidden,false);
      assert.match(get('summary-change').innerHTML,/summary-amount[^>]*>—/);
      savedHoldings=[];
      get('reload-portfolio').emit('click');await settle();
      assert.equal(get('summary-change').hidden,true);assert.equal(get('summary-change').innerHTML,'');
      assert.equal(get('empty-state').hidden,false);assert.equal(get('empty-add').hidden,false);
    });
    await t.test('expanded comparison rows preserve signs, zero and missing results in both modes',async()=>{
      catalogueItems=[old];quoteOverrides={};
      const switchPeriod=value=>selectView(value);
      const expand=()=>{
        if(!get('holdings-list').innerHTML.includes('class="holding-details"')) {
          const toggle={dataset:{id:'layout-holding'},closest(){return this;},classList:{contains:kind=>kind==='holding-toggle'}};
          get('holdings-list').emit('click',{target:toggle});
        }
      };
      for(const [averageCost,heading,amount,percentage,sign] of [
        ['90','Gain since purchase',/€\s*\+120,06/,/\+13,33%/,'positive'],
        ['110','Loss since purchase',/€\s*-80,04/,/-7,27%/,'negative'],
        ['102','No change since purchase',/€\s*0,00/,/0,00%/,'neutral'],
        [null,'Change since purchase',/—/,/—/,'neutral'],
      ]) {
        savedHoldings=[{id:'layout-holding',instrumentId:old.id,quantity:'10.005',averageCost,eurCost:null}];
        get('reload-portfolio').emit('click');await settle();switchPeriod('active');expand();
        assert.match(get('holdings-list').innerHTML,/<span>Value<\/span><strong class="">€\s*1\.020,51<\/strong>/);
        assert.match(get('portfolio-value').textContent,/^€\s*1\.021$/);
        assert.match(get('holdings-list').innerHTML,/change-amount">€\s*\+20<\/span>/);
        assert.equal(get('market-filter-status').textContent,'Market hours unavailable');assert.match(get('summary-change').innerHTML,/\+2,00%/);
        const details=get('holdings-list').innerHTML.split('<div class="detail-group"><h3>')[2];
        assert.ok(details.startsWith(heading+'</h3>'));
        assert.match(details,new RegExp(`<span>Amount</span><strong class="${sign}">${amount.source}</strong>`));
        assert.match(details,new RegExp(`<span>Percentage</span><strong class="${sign}">${percentage.source}</strong>`));
        assert.doesNotMatch(details,/gain-number|gain-percent/);
        assert.equal(savedHoldings[0].averageCost,averageCost);
        switchPeriod('purchase');
        const daily=get('holdings-list').innerHTML.split('<div class="detail-group latest-comparison"><h3>')[1];
        assert.match(daily,/^Latest change<\/h3>/);
        assert.match(daily,/<span>Amount<\/span><strong class="positive">€\s*\+20,01<\/strong>/);
        assert.match(daily,/<span>Percentage<\/span><strong class="positive">\+2,00%<\/strong>/);
      }
      quoteOverrides={[old.id]:{previousClose:null}};
      get('reload-portfolio').emit('click');await settle();expand();
      const missing=get('holdings-list').innerHTML.split('<div class="detail-group latest-comparison"><h3>')[1];
      assert.match(missing,/<span>Amount<\/span><strong class="neutral">—<\/strong>/);
      assert.match(missing,/<span>Percentage<\/span><strong class="neutral">—<\/strong>/);
      assert.match(missing,/Waiting for daily comparison data/);
      quoteOverrides={};
    });
    await t.test('overview stays compact while expanded dates and source warnings remain in both modes',async()=>{
      const now=Date.now;
      Date.now=()=>Date.parse('2026-10-12T13:40:00Z');
      try {
        const usd={...listing('dated-usd','Fictional Dated Share'),currency:'USD',type:'Stock',timeZone:'America/New_York'};
        catalogueItems=[usd];savedHoldings=[{id:'dated-holding',instrumentId:usd.id,quantity:'1',averageCost:'90',eurCost:'90'}];
        quoteOverrides={[usd.id]:{observedAt:'2026-10-09T20:00:00Z'}};
        get('reload-portfolio').emit('click');await settle();
        const switchPeriod=value=>selectView(value);
        switchPeriod('active');
        assert.doesNotMatch(get('holdings-list').innerHTML,/change-note">As of/);
        assert.doesNotMatch(get('summary-change').innerHTML,/Includes earlier comparison dates/);
        const toggle={dataset:{id:'dated-holding'},closest(){return this;},classList:{contains:kind=>kind==='holding-toggle'}};
        get('holdings-list').emit('click',{target:toggle});
        assert.match(get('holdings-list').innerHTML,/Closing price as of/);
        assert.doesNotMatch(get('holdings-list').innerHTML,/Compared with/);
        assert.doesNotMatch(get('holdings-list').innerHTML,/Latest change:|9 Oct 2026 compared with/);
        assert.doesNotMatch(get('holdings-list').innerHTML,/Market closed/);
        switchPeriod('purchase');
        assert.doesNotMatch(get('holdings-list').innerHTML,/change-note">As of/);
        assert.doesNotMatch(get('summary-change').innerHTML,/Includes earlier comparison dates/);
        assert.doesNotMatch(get('holdings-list').innerHTML,/Compared with/);
        assert.doesNotMatch(get('holdings-list').innerHTML,/Latest change:|9 Oct 2026 compared with/);
        quoteOverrides={[usd.id]:{date:'2026-10-12',previousDate:'2026-10-09',observedAt:'2026-10-12T13:31:00Z',priceKind:'trade',status:'delayed'}};
        get('reload-portfolio').emit('click');await settle();switchPeriod('active');
        assert.doesNotMatch(get('holdings-list').innerHTML,/change-note">As of/);
        assert.doesNotMatch(get('summary-change').innerHTML,/Includes earlier comparison dates/);
        assert.equal(savedHoldings[0].eurCost,'90');
        quoteOverrides={[usd.id]:{date:'2026-10-12',previousDate:'2026-10-09',observedAt:'2026-10-12T13:25:00Z',priceKind:'trade',status:'stale',eligibilityIssue:'incomplete_trade_collection'}};
        get('reload-portfolio').emit('click');await settle();
        assert.doesNotMatch(get('summary-change').innerHTML,/Using earlier data|show-coverage/);
        assert.equal(get('price-update').hidden,false);get('price-update').emit('click');
        assert.equal(get('earlier-data-section').hidden,false);assert.match(get('earlier-data-list').innerHTML,/last valid price and comparison/);get('close-price-update').emit('click');
        assert.doesNotMatch(get('summary-status').textContent,/prices are out of date/);
        assert.match(get('holdings-list').innerHTML,/latest price update is incomplete/);
        Date.now=()=>Date.parse('2026-10-12T15:00:01Z');switchPeriod('purchase');
        assert.doesNotMatch(get('summary-status').textContent,/Some prices.*out of date/);
        assert.equal(get('summary-status').hidden,true);
        assert.doesNotMatch(get('summary-status').textContent,/updates are incomplete/);
        assert.doesNotMatch(get('holdings-list').innerHTML,/The price is out of date/);
        assert.doesNotMatch(get('summary-change').innerHTML,/Using earlier data/);
        Date.now=()=>Date.parse('2026-10-12T13:40:00Z');
        quoteOverrides={[usd.id]:{date:'2026-10-12',previousDate:'2026-10-09',observedAt:'2026-10-12T13:25:00Z',priceKind:'trade',status:'stale',sourceAvailable:false}};
        get('reload-portfolio').emit('click');await settle();switchPeriod('all');
        assert.doesNotMatch(get('summary-change').innerHTML,/Using earlier data|show-coverage/);
        assert.equal(get('price-update').hidden,false);get('price-update').emit('click');
        assert.equal(get('earlier-data-section').hidden,false);assert.match(get('earlier-data-list').innerHTML,/last valid price and comparison/);get('close-price-update').emit('click');
        assert.doesNotMatch(get('summary-status').textContent,/prices are out of date|updates are incomplete/);
        assert.match(get('holdings-list').innerHTML,/latest data check could not be completed/);
        assert.doesNotMatch(get('holdings-list').innerHTML,/The price is out of date/);
        for (const codes of [['fx_stale'],['price_stale','fx_stale']]) {
          marketFixtureIssues=codes.map(code=>({instrumentId:usd.id,code}));
          quoteOverrides={[usd.id]:{date:'2026-10-12',previousDate:'2026-10-09',observedAt:'2026-10-12T13:25:00Z',priceKind:'trade',status:'stale'}};
          get('reload-portfolio').emit('click');await settle();
          assert.equal(get('summary-status').textContent,'');assert.equal(get('summary-status').hidden,true);
          assert.match(get('holdings-list').innerHTML,/The currency rate is out of date/);
          if(codes.includes('price_stale'))assert.match(get('holdings-list').innerHTML,/The price is out of date/);
        }
      } finally { Date.now=now;marketFixtureIssues=[]; }
    });
    await t.test('returning with an edit draft refreshes only cached prices and preserves entered fields and selection',async()=>{
      const i={...old,timeZone:'Europe/Amsterdam'};catalogueItems=[i];quoteOverrides={};savedHoldings=[{id:'draft-holding',instrumentId:i.id,quantity:'3',averageCost:'90',eurCost:null}];
      get('reload-portfolio').emit('click');await settle();
      const toggle={dataset:{id:'draft-holding'},closest(){return this;},classList:{contains:kind=>kind==='holding-toggle'}};
      get('holdings-list').emit('click',{target:toggle});
      const edit={dataset:{id:'draft-holding'},closest(){return this;},classList:{contains:kind=>kind==='edit-holding'}};
      get('holdings-list').emit('click',{target:edit});
      get('quantity').value='7,25';get('average-cost').value='88,50';get('quantity').focus();
      const selection=get('selected-instrument').innerHTML, before=calls.length;clock+=5000;foreground();await runTimers(100);
      assert.deepEqual(calls.slice(before).map(c=>[c.action,c.options.method]),[['market','GET']]);
      assert.equal(get('quantity').value,'7,25');assert.equal(get('average-cost').value,'88,50');
      assert.equal(get('selected-instrument').innerHTML,selection);assert.equal(document.activeElement.id,'quantity');assert.equal(savedHoldings[0].quantity,'3');
      get('form-portfolio-return').emit('click');await settle();
      assert.match(get('holdings-list').innerHTML,/<div class="details-heading">/);
      assert.doesNotMatch(get('holdings-list').innerHTML,/Edit holding|edit-holding/);
    });
    await t.test('single Edit keeps the full list, protects a draft and returns save/cancel to the same edit position',async()=>{
      catalogueItems=[old,fresh];quoteOverrides={};savedHoldings=[
        {id:'draft-holding',instrumentId:old.id,quantity:'3',averageCost:'90',eurCost:null},
        {id:'second-holding',instrumentId:fresh.id,quantity:'2',averageCost:'91',eurCost:null}];
      get('reload-portfolio').emit('click');await settle();selectView('purchase');
      assert.equal(get('manage-list').textContent,'Edit');get('manage-list').emit('click');
      assert.equal(get('manage-list').textContent,'Done');
      assert.match(get('holdings-list').innerHTML,/<button class="instrument-cell manage-instrument edit-holding"[^>]*aria-label="Edit Fictional Ready"/);
      assert.match(get('holdings-list').innerHTML,/class="edit-pencil"/);
      assert.deepEqual([...get('holdings-list').innerHTML.matchAll(/data-holding-id="([^"]+)"/g)].map(m=>m[1]),['draft-holding','second-holding']);
      const edit={id:'edit-draft-holding',dataset:{id:'draft-holding'},closest(){return this;},classList:{contains:kind=>kind==='edit-holding'}};
      window.scrollY=317;get('holdings-list').emit('click',{target:edit});
      assert.equal(get('portfolio-header').hidden,true);assert.equal(get('form-page-header').hidden,false);assert.equal(window.scrollY,0);
      get('quantity').value='4';const writes=calls.filter(c=>c.action==='portfolio'&&c.options.method==='PUT').length;
      discardAnswer=false;let count=confirmCount;
      get('form-portfolio-return').emit('click');await settle();
      assert.equal(confirmCount,count+1);assert.equal(get('investment-screen').hidden,false);assert.equal(get('quantity').value,'4');
      history.back();await settle();
      assert.equal(confirmCount,count+2);assert.equal(location.hash,'#edit/draft-holding');assert.equal(get('quantity').value,'4');
      assert.equal(get('investment-screen').hidden,false,'declining browser Back retains the editor DOM');
      discardAnswer=true;history.back();await settle();
      assert.equal(get('investment-screen').hidden,true);assert.equal(get('manage-list').textContent,'Done');assert.equal(window.scrollY,317);
      assert.equal(document.activeElement.id,'edit-draft-holding');assert.equal(savedHoldings[0].quantity,'3');
      assert.equal(calls.filter(c=>c.action==='portfolio'&&c.options.method==='PUT').length,writes);
      get('holdings-list').emit('click',{target:edit});get('quantity').value='4';count=confirmCount;
      await get('investment-form').emit('submit');await settle();
      assert.equal(confirmCount,count,'confirmed save never asks to discard');assert.equal(savedHoldings[0].quantity,'4');
      assert.equal(get('manage-list').textContent,'Done');assert.equal(window.scrollY,317);assert.equal(document.activeElement.id,'edit-draft-holding');
      assert.equal(get('portfolio-header').hidden,false);assert.equal(get('form-page-header').hidden,true);
      get('holdings-list').emit('click',{target:edit});get('quantity').value='5';count=confirmCount;
      let finishSave;holdSave=()=>new Promise(resolve=>{finishSave=resolve;});
      const saving=get('investment-form').emit('submit');await settle();history.back();
      assert.equal(get('investment-screen').hidden,false,'navigation waits for the in-flight save outcome');
      finishSave();await saving;await settle();holdSave=null;
      assert.equal(confirmCount,count,'Back queued during a successful save does not ask to discard saved fields');
      assert.equal(savedHoldings[0].quantity,'5');assert.equal(get('investment-screen').hidden,true);assert.equal(get('manage-list').textContent,'Done');
      get('manage-list').emit('click');assert.equal(get('manage-list').textContent,'Edit');assert.equal(get('portfolio-screen').dataset.comparisonMode,'purchase');
      assert.doesNotMatch(get('holdings-list').innerHTML,/edit-holding/);selectView('all');
    });
    await t.test('failed foreground reads keep saved values with a warning and never spin or prepare investments',async()=>{
      const before=marketCalls();const prior=get('portfolio-value').textContent;
      handleMarket=()=>{throw new Error('Offline');};clock+=5000;foreground();await runTimers(100);
      assert.equal(marketCalls(),before+1);assert.equal(get('portfolio-value').textContent,prior);
      assert.doesNotMatch(get('summary-change').innerHTML,/Using earlier data|show-coverage/);
      assert.equal(get('price-update').hidden,false);get('price-update').emit('click');
      assert.equal(get('earlier-data-section').hidden,false);assert.match(get('earlier-data-list').innerHTML,/last valid price and comparison/);get('close-price-update').emit('click');
      foreground();await runTimers(100);assert.equal(marketCalls(),before+1);
      handleMarket=()=>catalogue(catalogueItems).market;clock+=5000;foreground();await runTimers(100);
      assert.equal(marketCalls(),before+2);assert.doesNotMatch(get('summary-status').textContent,/Market data could not be refreshed/);
    });
    await t.test('comparison choice survives back and a fresh module reload and storage failures stay harmless',async()=>{
      const switchMode=value=>selectView(value);
      switchMode('all');assert.equal(get('portfolio-screen').dataset.comparisonMode,'all');
      assert.deepEqual(JSON.parse(stored.get('richyet-comparison-preferences')),{version:1,mode:'latest',activeOnly:false});
      const before=calls.length;
      const edit={dataset:{id:'draft-holding'},closest(){return this;},classList:{contains:kind=>kind==='edit-holding'}};
      get('holdings-list').emit('click',{target:edit});get('quantity').value='12,345';
      get('form-portfolio-return').emit('click');await settle();
      assert.equal(get('portfolio-screen').dataset.comparisonMode,'all');assert.equal(calls.length,before);
      // Re-import the actual application with fresh module state, preserving only
      // browser storage/history; this catches an unconditional reload-to-Active.
      events.get('pagehide')();
      await import('../public/app.mjs?search-flow-mode-reload');await settle();
      assert.equal(get('portfolio-screen').dataset.comparisonMode,'all');
      assert.equal(radios.find(r=>r.value==='latest')?.checked,true);
      storageBlocked=true;
      try{switchMode('purchase');assert.equal(get('portfolio-screen').dataset.comparisonMode,'purchase');switchMode('active');assert.equal(get('portfolio-screen').dataset.comparisonMode,'active');}
      finally{storageBlocked=false;}
      switchMode('all');
      const key='richyet-comparison-preferences';assert.ok(stored.has(key));
      stored.set(key,'untrusted-invalid-choice');history.state=null;events.get('pagehide')();
      await import('../public/app.mjs?search-flow-invalid-mode-reload');await settle();
      assert.equal(get('portfolio-screen').dataset.comparisonMode,'all');
      // Return from an edit via older history, rather than a same-route no-op.
      get('holdings-list').emit('click',{target:edit});
      location.hash='';
      history.state={richyetView:{period:'today',managing:false,expandedId:null}};events.get('popstate')();await settle();
      assert.equal(get('portfolio-screen').dataset.comparisonMode,'active');
    });
    await t.test('back-forward restoration coalesces initial loading and rejects responses from the page that was left',async()=>{
      let finish;handleMarket=()=>new Promise(resolve=>{finish=resolve;});clock+=5000;foreground();await runTimers(100);
      events.get('pagehide')();const before=calls.length;let resume;
      handleSession=()=>new Promise(resolve=>{resume=resolve;});events.get('pageshow')({persisted:true});events.get('pageshow')({persisted:true});foreground();
      assert.equal(calls.slice(before).filter(c=>c.action==='session').length,1);
      finish({...catalogue(catalogueItems).market,quotes:{}});await settle();assert.equal(get('portfolio-screen').hidden,true);
      handleMarket=()=>catalogue(catalogueItems).market;handleSession=null;
      resume({authenticated:true,user:{email:'fictional@example.test'},csrfToken:'fixture-csrf',dataMode:'live'});await settle();
      const count=marketCalls();foreground();await runTimers(100);assert.equal(marketCalls(),count);assert.equal(get('portfolio-screen').hidden,false);
    });
    await t.test('sign-out and an expired session clear Partial holding names and cannot be revived by pending foreground data',async()=>{
      const missing={...old,id:'privacy-missing',name:'Fictional Private Missing Holding'};
      catalogueItems=[old,missing];savedHoldings=catalogueItems.map((instrument,index)=>({id:'privacy-'+index,instrumentId:instrument.id,quantity:'1',averageCost:'90',eurCost:null}));
      quoteOverrides={[missing.id]:{previousClose:null}};get('reload-portfolio').emit('click');await settle();
      selectView('all');
      const openCoverage=()=>{get('portfolio-breakdown').emit('click');assert.match(get('breakdown-list').innerHTML,/Fictional Private Missing Holding/);get('summary-change').emit('click',{target:get('show-coverage')});assert.equal(get('coverage-dialog').open,true);assert.match(get('coverage-list').innerHTML,/Fictional Private Missing Holding/);assert.ok(get('coverage-description').textContent);};
      const assertCoverageCleared=()=>{assert.equal(get('coverage-dialog').open,false);assert.equal(get('coverage-list').innerHTML,'');assert.equal(get('coverage-description').textContent,'');assert.equal(get('breakdown-dialog').open,false);assert.equal(get('breakdown-list').innerHTML,'');assert.equal(get('breakdown-chart').innerHTML,'');assert.equal(get('breakdown-selection').textContent,'');};
      assert.match(get('summary-change').innerHTML,/coverage-button[^>]*>(?:Partial|Data issue)/);openCoverage();
      let finish;handleMarket=()=>new Promise(resolve=>{finish=resolve;});clock+=5000;
      const calendarDelay=60000-clock%60000;foreground();await runTimers(100);
      const calendarTimers=[...timers].filter(([,timer])=>timer.ms===calendarDelay).map(([id])=>id);
      assert.equal(calendarTimers.length,1);
      get('sign-out').emit('click');await settle();assert.equal(get('portfolio-screen').hidden,true);assertCoverageCleared();
      assert.equal(get('open-about').hidden,true);assert.equal(get('auth-title').textContent,'Your investment portfolio');
      assert.equal(get('auth-description').textContent,'Stocks and ETFs');assert.equal(get('auth-help').hidden,false);
      assert.equal(calendarTimers.some(id=>timers.has(id)),false,'sign-out clears the clock-only update');
      finish(catalogue(catalogueItems).market);await settle();foreground();await runTimers(100);
      assert.equal(get('portfolio-screen').hidden,true);assert.equal(get('holdings-list').innerHTML,'');assert.equal(get('account-controls').hidden,true);assertCoverageCleared();
      authenticated=true;handleMarket=()=>catalogue(catalogueItems).market;
      events.get('pagehide')();events.get('pageshow')({persisted:true});await settle();
      assert.equal(get('open-about').hidden,false,'About is available again for the signed-in portfolio');
      selectView('all');openCoverage();
      handleMarket=()=>({ok:false,status:401,json:async()=>({error:{code:'unauthorized'}})});clock+=5000;foreground();await runTimers(100);
      assert.equal(get('portfolio-screen').hidden,true);assert.match(get('auth-description').textContent,/session expired/);assertCoverageCleared();
      assert.equal(get('open-about').hidden,true);assert.equal(get('estimate-list').innerHTML,'');assert.equal(get('estimate-list').hidden,true);
    });
  }finally{Date.now=originalNow;events.get('pagehide')?.();for(const [name,descriptor] of originals)if(descriptor)Object.defineProperty(globalThis,name,descriptor);else delete globalThis[name];}
});
