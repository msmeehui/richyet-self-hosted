import test from 'node:test';
import assert from 'node:assert/strict';
import {normalizeComparisonMode,readComparisonMode,writeComparisonMode,selectComparisonEntries,normalizeComparisonPreferences,comparisonModeForPreferences,comparisonModeForHoldings,comparisonFilterState,areAllMarketsClosed,readComparisonPreferences,writeComparisonPreferences} from '../public/lib/comparison-modes.mjs';
import {createActiveReadinessTracker} from '../public/lib/active-readiness.mjs';
import {selectHoldingSections} from '../public/lib/holding-sections.mjs';
import {calculateHolding,calculatePortfolio,calculateLatestSubtotal,calculateLatestContribution} from '../public/lib/portfolio.mjs';
import {normalizeMarketSnapshot} from '../public/lib/market.mjs';
import {comparisonWarnings} from '../public/lib/data-warnings.mjs';

const at=Date.parse;
const eu={id:'fictional-eu',type:'Stock',currency:'EUR',mic:'XAMS',timeZone:'Europe/Amsterdam',closeSchedule:'ams_j0_continuous_2026'};
const us={id:'fictional-us',type:'Stock',currency:'USD',mic:'XNAS',timeZone:'America/New_York',closeSchedule:'us_retained_calendar'};
const fund={id:'fictional-fund',type:'Fund',currency:'EUR',mic:'XXXX',timeZone:'Europe/Amsterdam',contributionSchedule:'bnd_daily_amsterdam'};
function row(instrument,changes={},holding={}) {
  return {instrument,holding:{id:`holding-${instrument.id}`,instrumentId:instrument.id,quantity:'2',averageCost:'80',eurCost:null,...holding},
    quote:{price:'110',previousClose:'100',date:'2026-10-13',previousDate:'2026-10-12',observedAt:'2026-10-13T09:00:00Z',priceKind:'trade',status:'delayed',fx:'1',previousFx:'1',...changes}};
}
function nav(changes={}) {
  return row(fund,{price:'102',previousClose:'100',date:'2026-10-12',previousDate:'2026-10-09',observedAt:null,priceKind:'nav',status:'nav',sourceAvailable:true,
    fundContribution:{valuationDate:'2026-10-12',firstObservedAt:'2026-10-13T06:00:00Z',windowDate:'2026-10-13'},...changes},{quantity:'3'});
}
const subtotal=(rows,mode,now)=>calculateLatestSubtotal(selectComparisonEntries(rows,mode,at(now)).comparisonEntries);
const ids=rows=>rows.map(r=>r.holding.id);
const holdingResult=entry=>calculateHolding(entry.holding,entry.instrument,entry.quote);

test('all closed temporarily shows all dated comparisons in saved order for either checkbox preference',()=>{
  const rows=[row(us,{observedAt:'2026-10-13T13:35:00Z'}),nav(),row(eu)],before=structuredClone(rows),now=at('2026-10-13T22:00:00Z');
  assert.equal(areAllMarketsClosed(rows,now),true);
  for(const activeOnly of [true,false]){
    const prefs={mode:'latest',activeOnly},mode=comparisonModeForHoldings(comparisonModeForPreferences(prefs),rows.length,areAllMarketsClosed(rows,now));
    assert.equal(mode,'all');
    const selected=selectComparisonEntries(rows,mode,now);
    assert.deepEqual(comparisonFilterState(rows,selected,now),{kind:'status',text:'All markets closed'});
    assert.deepEqual(ids(selectHoldingSections(rows,now,mode).visibleEntries),ids(rows));
    assert.equal(calculateLatestSubtotal(selected.comparisonEntries).change,46);
    assert.deepEqual(prefs,{mode:'latest',activeOnly});
  }
  assert.equal(comparisonModeForHoldings('purchase',rows.length,true),'purchase');assert.deepEqual(rows,before);
});

test('an open exchange without any new data never becomes All markets closed',()=>{
  const rows=[row(us,{observedAt:'2026-10-12T14:00:00Z',date:'2026-10-12',previousDate:'2026-10-09'}),row(eu,{price:null,previousClose:null})];
  const now=at('2026-10-13T07:00:00Z');
  assert.equal(areAllMarketsClosed(rows,now),false);
  assert.equal(comparisonFilterState(rows,selectComparisonEntries(rows,'active',now),now).kind,'checkbox');
  assert.equal(comparisonModeForHoldings('active',rows.length,areAllMarketsClosed(rows,now)),'active');
  assert.equal(areAllMarketsClosed([row({...eu,closeSchedule:'unknown'}),row(us)],at('2026-10-13T22:00:00Z')),false);
  assert.equal(areAllMarketsClosed([],now),false);assert.equal(areAllMarketsClosed(rows,NaN),false);
});

test('fund reporting hours block the closed shortcut even when no NAV window is eligible',()=>{
  const rows=[row(us),nav({date:null,price:null,fundContribution:null})];
  for(const instant of ['2026-10-13T07:00:00Z','2026-10-13T10:00:00Z']){
    const now=at(instant);assert.equal(areAllMarketsClosed(rows,now),false);
    assert.equal(comparisonFilterState(rows,selectComparisonEntries(rows,'active',now),now).kind,'checkbox');
  }
  assert.equal(areAllMarketsClosed([row(eu),nav()],at('2026-10-13T15:30:00Z')),false,'Euronext remains open after the fund window ends');
  assert.equal(areAllMarketsClosed([row(eu),nav()],at('2026-10-13T15:40:00Z')),true);
  assert.equal(areAllMarketsClosed(rows,at('2026-10-17T10:00:00Z')),true,'Weekend has no fund reporting window');
  assert.equal(areAllMarketsClosed([row(us),nav()],at('2026-10-27T08:00:00Z')),false,'Amsterdam winter time still opens at09:00');
  assert.equal(areAllMarketsClosed([row(us),nav()],at('2026-10-27T07:59:59Z')),true);
  assert.equal(areAllMarketsClosed([row(us),row({...fund,contributionSchedule:'unknown'})],at('2026-10-13T22:00:00Z')),false);
});

test('closed shortcut keeps missing comparison data missing rather than manufacturing zero',()=>{
  const rows=[row(eu),row(us,{previousClose:null})],now=at('2026-10-13T22:00:00Z');
  const mode=comparisonModeForHoldings('active',rows.length,areAllMarketsClosed(rows,now));
  const selection=selectComparisonEntries(rows,mode,now),result=calculateLatestSubtotal(selection.comparisonEntries);
  assert.equal(mode,'all');assert.equal(result.includedCount,1);assert.equal(result.partial,true);
  assert.deepEqual(ids(selectHoldingSections(rows,now,mode).visibleEntries),ids(rows));
  assert.deepEqual(result.missingInstrumentIds,[us.id]);assert.equal(rows[1].quote.previousClose,null);
});

test('redundant filter status follows actual ready comparisons, not opening hours alone',()=>{
  const now=at('2026-10-13T14:00:00Z'),rows=[row(eu),row(us,{observedAt:'2026-10-13T13:35:00Z'})];
  for(const mode of ['all','active']){
    const selection=selectComparisonEntries(rows,mode,now);
    assert.deepEqual(comparisonFilterState(rows,selection,now),{kind:'status',text:'All markets open'});
    assert.deepEqual(calculateLatestSubtotal(selection.activeEntries),calculateLatestSubtotal(selection.referenceEntries));
  }
  for(const changes of [{date:'2026-10-12',previousDate:'2026-10-09',observedAt:'2026-10-12T20:00:00Z'},
    {previousClose:null},{previousFx:null}]){
    const waiting=[rows[0],row(us,{...changes})];
    assert.equal(comparisonFilterState(waiting,selectComparisonEntries(waiting,'active',now),now).kind,'checkbox');
  }
});

test('a complete retained Xetra comparison does not keep a no-effect filter visible',()=>{
  const now=at('2026-10-13T14:00:00Z');
  const xetra={id:'fictional-xetra',type:'ETF',currency:'EUR',mic:'XETA',timeZone:'Europe/Berlin',closeSchedule:'xetra_etf_regular_2026'};
  const rows=[row(xetra,{observedAt:'2026-10-13T09:06:46Z',status:'stale',sourceAvailable:false}),
    row(eu,{observedAt:'2026-10-13T13:45:00Z'}),row(us,{observedAt:'2026-10-13T13:39:00Z'}),nav()];
  const before=structuredClone(rows),tracker=createActiveReadinessTracker();tracker.bind('fictional-owner');
  const expected=calculateLatestContribution(rows,rows);
  for(const mode of ['active','all']) for(let n=0;n<3;n++){
    const selection=selectComparisonEntries(rows,mode,now,tracker);
    assert.deepEqual(ids(selection.comparisonEntries),ids(rows),'The same ordered holdings are selected');
    assert.deepEqual(calculateLatestContribution(selection.comparisonEntries,selection.referenceEntries),expected);
    assert.deepEqual(comparisonFilterState(rows,selection,now),{kind:'status',text:'All markets open'});
    const warnings=comparisonWarnings(rows,selection.comparisonEntries,expected,{now});
    assert.deepEqual(warnings.map(({id,kind})=>({id,kind})),[{id:xetra.id,kind:'earlier'}],
      'Hiding a redundant checkbox does not discard the retained-data explanation');
  }
  assert.deepEqual(rows,before);
  for(const change of [{previousClose:null},{price:null},{previousClose:'0'}]){
    const invalid=[{...rows[0],quote:{...rows[0].quote,...change}},...rows.slice(1)];
    const selection=selectComparisonEntries(invalid,'active',now,tracker);
    assert.equal(comparisonFilterState(invalid,selection,now).kind,'checkbox','A genuinely unavailable comparison remains distinct');
  }
});

test('later source failures restore the filter and retain the named warning until recovery',()=>{
  const now=at('2026-10-13T14:00:00Z'),rows=[row(eu),row(us,{observedAt:'2026-10-13T13:35:00Z'})],tracker=createActiveReadinessTracker();tracker.bind('fictional-owner');
  assert.equal(comparisonFilterState(rows,selectComparisonEntries(rows,'active',now,tracker),now).text,'All markets open');
  const broken=[rows[0],row(us,{observedAt:'2026-10-13T13:35:00Z',sourceAvailable:false,previousClose:null})];
  const failed=selectComparisonEntries(broken,'active',now,tracker);
  assert.deepEqual(failed.failedInstrumentIds,[us.id]);assert.equal(failed.activeEntries.length,2);
  assert.equal(comparisonFilterState(broken,failed,now).kind,'checkbox');
  assert.equal(comparisonFilterState(rows,selectComparisonEntries(rows,'active',now,tracker),now).text,'All markets open');
});

test('a closing market restores the saved filter without changing inputs or custom order',()=>{
  const rows=[row(us,{observedAt:'2026-10-13T13:35:00Z'}),row(eu)],before=structuredClone(rows),preferences={mode:'latest',activeOnly:true};
  const during=at('2026-10-13T14:00:00Z'),after=at('2026-10-13T16:00:00Z');
  assert.equal(comparisonFilterState(rows,selectComparisonEntries(rows,'active',during),during).kind,'status');
  assert.equal(comparisonFilterState(rows,selectComparisonEntries(rows,'active',after),after).kind,'checkbox');
  assert.equal(comparisonModeForPreferences(preferences),'active');assert.deepEqual(rows,before);
  assert.deepEqual(ids(selectHoldingSections(rows,after,'active').visibleEntries),[rows[0].holding.id]);
});

test('one investment remains visible with its dated comparison even with a saved checked filter at night',()=>{
  const rows=[row(eu)],now=at('2026-10-13T22:00:00Z'),preferences={mode:'latest',activeOnly:true};
  const mode=comparisonModeForHoldings(comparisonModeForPreferences(preferences),rows.length);
  assert.equal(mode,'all');assert.equal(comparisonModeForPreferences(preferences),'active');
  assert.equal(selectHoldingSections(rows,now,mode).visibleEntries.length,1);
  assert.equal(subtotal(rows,mode,'2026-10-13T22:00:00Z').change,20);
  assert.deepEqual(comparisonFilterState(rows,selectComparisonEntries(rows,mode,now),now),{kind:'status',text:'Market closed'});
  assert.equal(comparisonFilterState(rows,selectComparisonEntries(rows,'all',at('2026-10-13T10:00:00Z')),at('2026-10-13T10:00:00Z')).text,'Market open');
  assert.equal(comparisonModeForHoldings('purchase',1),'purchase');assert.equal(comparisonModeForHoldings('active',2),'active');
});

test('daily funds and unknown calendars never claim a market is open or closed',()=>{
  for(const rows of [[nav()],[row({...eu,closeSchedule:'unknown'})]]){
    const now=at('2026-10-13T10:00:00Z'),selection=selectComparisonEntries(rows,'all',now);
    assert.equal(comparisonFilterState(rows,selection,now).text,rows[0].instrument.type==='Fund'?'Daily value':'Market hours unavailable');
  }
  assert.deepEqual(comparisonFilterState([],selectComparisonEntries([],'all'),Date.now()),{kind:'hidden',text:''});
  const rows=[row(eu),nav()],now=at('2026-10-13T10:00:00Z');
  assert.equal(comparisonFilterState(rows,selectComparisonEntries(rows,'active',now),now).text,'All markets open');
  const nextDay=at('2026-10-14T10:00:00Z');
  assert.equal(comparisonFilterState(rows,selectComparisonEntries(rows,'active',nextDay),nextDay).kind,'checkbox','An old NAV must not restart its window');
});

test('comparison preference stores only the selected mode and migrates earlier daily labels to Active',()=>{
  const writes=[],storage={value:'latest',getItem(key){assert.equal(key,'richyet-comparison-mode');return this.value;},setItem(key,value){writes.push([key,value]);this.value=value;}};
  for(const value of ['today','latest','active',null,undefined,'invalid',{},['all']])assert.equal(normalizeComparisonMode(value),'active');
  assert.equal(readComparisonMode(storage),'active');
  assert.equal(writeComparisonMode('all',storage),true);assert.equal(readComparisonMode(storage),'all');
  assert.equal(writeComparisonMode('purchase',storage),true);assert.equal(readComparisonMode(storage),'purchase');
  assert.equal(writeComparisonMode('today',storage),true);assert.equal(readComparisonMode(storage),'active');
  assert.deepEqual(writes,[['richyet-comparison-mode','all'],['richyet-comparison-mode','purchase'],['richyet-comparison-mode','active']]);
  const broken={getItem(){throw Error('storage denied');},setItem(){throw Error('storage full');}};
  assert.equal(readComparisonMode(broken),'active');assert.equal(writeComparisonMode('all',broken),false);
  assert.equal(readComparisonMode(null),'active');assert.equal(writeComparisonMode('all',null),false);
});

test('All holdings combines each complete dated pair while Active waits for a newly opened market',()=>{
  const europe=row(eu,{date:'2026-10-12',previousDate:'2026-10-09',observedAt:'2026-10-12T08:00:00Z'});
  const america=row(us,{price:'104',previousClose:'100',date:'2026-10-09',previousDate:'2026-10-08',observedAt:'2026-10-09T20:00:00Z',priceKind:'close',status:'closed',fx:'0.9',previousFx:'0.92'},{quantity:'5'});
  const rows=[america,europe],now='2026-10-12T13:35:00Z',before=structuredClone(rows);
  const active=subtotal(rows,'active',now),all=subtotal(rows,'all',now);
  assert.equal(active.includedCount,1);assert.equal(active.change,20);assert.equal(active.partial,false);
  assert.equal(all.includedCount,2);assert.equal(all.change,28);assert.equal(all.baseline,660);assert.equal(all.changePercent,140/33);assert.equal(all.partial,false);
  const grouped=selectHoldingSections(rows,at(now),'all');
  assert.equal(grouped.entries[0].comparisonQuote,america.quote);assert.equal(grouped.entries[0].waitingForCurrentSession,false);
  assert.equal(selectHoldingSections(rows,at(now),'active').entries[0].waitingForCurrentSession,true);
  assert.deepEqual(rows,before);
});

test('a new source price missing its own preceding close cannot borrow the earlier completed pair',()=>{
  const old=row(us,{date:'2026-10-12',previousDate:'2026-10-09',observedAt:'2026-10-12T20:00:00Z',priceKind:'close',status:'closed'});
  const arrived={...old,quote:{...old.quote,price:'112',date:'2026-10-13',previousDate:'2026-10-12',previousClose:null,observedAt:'2026-10-13T13:45:00Z',priceKind:'trade',status:'delayed'}};
  const rows=[row(eu),arrived],now='2026-10-13T14:00:00Z';
  for(const mode of ['active','all']){
    const result=subtotal(rows,mode,now);assert.equal(result.change,20);assert.equal(result.partial,mode==='all');assert.deepEqual(result.missingInstrumentIds,mode==='all'?[us.id]:[]);
  }
  assert.equal(holdingResult(old).change,20);assert.equal(arrived.quote.previousClose,null);
  assert.equal(holdingResult(arrived).value,224);assert.equal(holdingResult(arrived).purchaseGain,64);
});

test('night-time All includes closed markets and BND without activating them or changing portfolio value',()=>{
  const rows=[nav(),row(us),row(eu)],now='2026-10-13T22:00:00Z',before=structuredClone(rows),full=calculatePortfolio(rows);
  const active=subtotal(rows,'active',now),all=subtotal(rows,'all',now);
  assert.equal(active.includedCount,0);assert.equal(active.change,null);
  assert.equal(all.includedCount,3);assert.equal(all.change,46);assert.equal(all.baseline,700);assert.equal(all.changePercent,46/7);assert.equal(all.partial,false);
  for(const mode of ['active','all','purchase']){
    const p=selectHoldingSections(rows,at(now),mode);assert.equal(p.openMarkets.activeFundCount,0);assert.equal(p.sections.length,1);assert.equal(p.sections[0].id,'closed');
    assert.deepEqual(ids(p.sections[0].entries),ids(rows));
  }
  assert.deepEqual(calculatePortfolio(rows),full);assert.deepEqual(rows,before);
});

test('All includes valid unknown-calendar pairs; missing fields and explicit invalidations remain Partial',()=>{
  const unknown=row({...eu,id:'fictional-unknown',closeSchedule:'unreviewed'});
  const invalids=['listing','current','comparison'].map((v,n)=>row({...eu,id:`fictional-invalid-${n}`},{invalidations:[v]}));
  const action=row({...eu,id:'fictional-action'}),fx=row({...us,id:'fictional-fx'},{previousFx:null});
  const rows=[unknown,...invalids,action,fx];
  const normalized=normalizeMarketSnapshot({dataMode:'live',quotes:Object.fromEntries(rows.map(r=>[r.instrument.id,r.quote])),issues:[{instrumentId:action.instrument.id,code:'corporate_action_unverified'}]},rows.map(r=>r.instrument));
  const safe=rows.map(r=>({...r,quote:normalized.quotes[r.instrument.id]}));
  const result=subtotal(safe,'all','2026-10-13T10:00:00Z');
  assert.equal(result.includedCount,1);assert.equal(result.totalCount,6);assert.equal(result.change,20);assert.equal(result.partial,true);
  assert.deepEqual(result.missingInstrumentIds,rows.slice(1).map(r=>r.instrument.id));
  assert.equal(selectComparisonEntries(safe,'all',at('2026-10-13T10:00:00Z')).openMarkets.unknownCount,1);
});

test('fund-window membership affects Active only and missing fund amounts are never silently omitted from All coverage',()=>{
  const good=row(eu),broken=nav({price:null,date:null,previousClose:null,previousDate:null,status:'missing'}),rows=[good,broken];
  const during='2026-10-13T10:00:00Z',after='2026-10-13T15:35:00Z';
  for(const mode of ['active','all'])assert.equal(subtotal(rows,mode,during).partial,mode==='all');
  assert.equal(subtotal(rows,'active',after).partial,false);assert.equal(subtotal(rows,'all',after).partial,true);
  const retained=nav({status:'stale'}),all=subtotal([good,retained],'all',during);
  assert.equal(all.change,26);assert.equal(all.stale,true);assert.equal(retained.quote.status,'stale');
  assert.equal(subtotal([good,retained],'active',during).partial,false,'An unready fund has not joined Active');
});

test('All cannot turn future observations or future valuation dates into available comparisons',()=>{
  const now=at('2026-10-13T10:00:00Z');
  for(const changes of [{observedAt:'2026-10-13T10:00:01Z'},{date:'2026-10-14',observedAt:null,priceKind:'close'},
    {date:'2026-10-14',observedAt:'2026-10-13T09:00:00Z'}]){
    const candidate=row(eu,changes),before=holdingResult(candidate);
    const projected=selectHoldingSections([candidate],now,'all').entries[0];
    assert.equal(calculateHolding(projected.holding,projected.instrument,projected.comparisonQuote).change,null);
    assert.equal(projected.quote,candidate.quote);assert.deepEqual(holdingResult(candidate),before);
  }
  const localTomorrow=row({...eu,timeZone:'Asia/Tokyo'},{date:'2026-10-14',previousDate:'2026-10-13',observedAt:null,priceKind:'close'});
  assert.equal(calculateLatestSubtotal(selectComparisonEntries([localTomorrow],'all',at('2026-10-13T16:00:00Z')).comparisonEntries).change,20,'Civil dates are checked in the listing timezone');
  assert.equal(calculateLatestSubtotal(selectComparisonEntries([row(eu)],'all',NaN).comparisonEntries).change,null);
});

test('mode changes preserve filtered custom ordering and Since purchase uses the original dated opposite comparison',()=>{
  const retained=row(us,{date:'2026-10-12',previousDate:'2026-10-09',observedAt:'2026-10-12T20:00:00Z',priceKind:'close',status:'closed',fx:{numerator:'10',denominator:'11'},previousFx:{numerator:'5',denominator:'6'}});
  const rows=[retained,nav(),row(eu)],before=structuredClone(rows),full=calculatePortfolio(rows),now=at('2026-10-13T13:35:00Z');
  const freeze=value=>{if(value&&typeof value==='object'){Object.values(value).forEach(freeze);Object.freeze(value);}};freeze(rows);
  const projections=['active','all','purchase'].map(mode=>selectHoldingSections(rows,now,mode));
  for(const projection of projections){assert.deepEqual(projection.sections.map(s=>[s.id,ids(s.entries)]),projections[0].sections.map(s=>[s.id,ids(s.entries)]));assert.deepEqual(ids(projection.entries),ids(rows));}
  assert.equal(projections[0].entries[0].waitingForCurrentSession,true);
  for(const p of projections.slice(1)){assert.equal(p.entries[0].waitingForCurrentSession,false);assert.equal(p.entries[0].comparisonQuote,retained.quote);}
  assert.equal(holdingResult(retained).purchaseGainEstimated,true);assert.deepEqual(calculatePortfolio(rows),full);assert.deepEqual(rows,before);
  assert.throws(()=>selectComparisonEntries(null,'all',now),TypeError);
});

test('available zero movement is a real result while no available comparisons remains unavailable',()=>{
  const good=row(eu,{price:'100'}),missing=row({...eu,id:'fictional-missing'},{previousClose:null});
  const partial=subtotal([good,missing],'all','2026-10-13T10:00:00Z');assert.equal(partial.change,0);assert.equal(partial.changePercent,0);assert.equal(partial.partial,true);
  for(const rows of [[],[missing]]){const result=subtotal(rows,'all','2026-10-13T10:00:00Z');assert.equal(result.change,null);assert.equal(result.changePercent,null);assert.equal(result.includedCount,0);}
});


test('two comparison preferences default to unfiltered Latest change and migrate each earlier view',()=>{
  for(const legacy of [null,'invalid','all','purchase','active','today','latest']) {
    const values=new Map(legacy==null?[]:[['richyet-comparison-mode',legacy]]);
    const storage={getItem:key=>values.get(key)??null};
    assert.deepEqual(readComparisonPreferences(storage),{mode:legacy==='purchase'?'purchase':'latest',activeOnly:['active','today','latest'].includes(legacy)});
  }
  assert.deepEqual(readComparisonPreferences(null),{mode:'latest',activeOnly:false});
  assert.equal(comparisonModeForPreferences(null),'all');
  assert.deepEqual(normalizeComparisonPreferences({mode:'unexpected',activeOnly:'true'}),{mode:'latest',activeOnly:false});
});

test('purchase selection preserves the independent Active only choice across storage and return',()=>{
  const values=new Map(),storage={getItem:key=>values.get(key)??null,setItem:(key,value)=>values.set(key,value)};
  for(const activeOnly of [true,false]) {
    assert.equal(writeComparisonPreferences({mode:'purchase',activeOnly},storage),true);
    const read=readComparisonPreferences(storage);assert.deepEqual(read,{mode:'purchase',activeOnly});
    assert.equal(comparisonModeForPreferences(read),'purchase');
    assert.equal(comparisonModeForPreferences({...read,mode:'latest'}),activeOnly?'active':'all');
    assert.deepEqual(JSON.parse(values.get('richyet-comparison-preferences')),{version:1,mode:'purchase',activeOnly});
  }
  assert.equal(values.size,1,'only display preferences are written');
});

test('invalid or inaccessible preference storage safely returns unfiltered Latest change',()=>{
  const fallback={mode:'latest',activeOnly:false};
  for(const raw of ['invalid','null','[]','{"version":2,"mode":"purchase","activeOnly":true}','x'.repeat(201)]) {
    assert.deepEqual(readComparisonPreferences({getItem:()=>raw}),fallback);
  }
  const blocked={getItem(){throw Error('denied');},setItem(){throw Error('full');}};
  assert.deepEqual(readComparisonPreferences(blocked),fallback);assert.equal(writeComparisonPreferences({mode:'purchase',activeOnly:true},blocked),false);
  assert.equal(writeComparisonPreferences({},null),false);
});


test('awaiting data identifies only hidden open holdings, then clears on admission or closing',()=>{
  const now=at('2026-10-13T14:00:00Z'),tracker=createActiveReadinessTracker();tracker.bind('fictional-owner');
  const waiting=row(eu,{date:'2026-10-12',observedAt:'2026-10-12T13:00:00Z'});
  const current=row(us,{observedAt:'2026-10-13T13:35:00Z'});
  const before=structuredClone([waiting,current]);
  assert.deepEqual(selectComparisonEntries([waiting,current],'active',now,tracker).awaitingEntries.map(e=>e.instrument.id),[eu.id]);
  assert.deepEqual([waiting,current],before);
  const ready=row(eu,{observedAt:'2026-10-13T13:45:00Z'});
  assert.deepEqual(selectComparisonEntries([ready,current],'active',now,tracker).awaitingEntries,[]);
  const failed=row(eu,{observedAt:'2026-10-13T13:45:00Z',previousClose:null,sourceAvailable:false});
  const later=selectComparisonEntries([failed,current],'active',now,tracker);
  assert.deepEqual(later.awaitingEntries,[]);assert.deepEqual(later.failedInstrumentIds,[eu.id]);
  assert.deepEqual(selectComparisonEntries([waiting,current],'active',at('2026-10-13T22:00:00Z'),tracker).awaitingEntries,[]);
  const unknown=row({...eu,closeSchedule:'unknown'});
  assert.deepEqual(selectComparisonEntries([unknown,current],'active',now,tracker).awaitingEntries,[]);
});
