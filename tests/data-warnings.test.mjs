import test from 'node:test';
import assert from 'node:assert/strict';
import {comparisonWarnings} from '../public/lib/data-warnings.mjs';
import {selectComparisonEntries} from '../public/lib/comparison-modes.mjs';
import {calculateLatestContribution,calculateHolding} from '../public/lib/portfolio.mjs';
import {normalizeMarketSnapshot,holdingDataMessages} from '../public/lib/market.mjs';

const now=Date.parse('2026-10-16T11:00:00Z');
const instrument={id:'fictional-europe',name:'Fictional Europe',type:'ETF',isin:'ZZ0000000321',currency:'EUR',mic:'XPAR',timeZone:'Europe/Paris',closeSchedule:'par_eur_etf_continuous_2026'};
const entry={instrument,holding:{id:'fictional-h',instrumentId:instrument.id,quantity:'3',averageCost:'60',eurCost:null},quote:{price:'105',previousClose:'100',date:'2026-10-16',previousDate:'2026-10-15',observedAt:'2026-10-16T08:44:00Z',priceKind:'trade',status:'stale',sourceAvailable:false,basis:'same_units',fx:'1',previousFx:'1'}};
const warnings=(e,mode='all',opts={})=>{const selection=selectComparisonEntries([e],mode,now);return comparisonWarnings([e],selection.comparisonEntries,calculateLatestContribution(selection.comparisonEntries,selection.referenceEntries),{now,...opts});};

test('both daily views explain a retained valid pair consistently without claiming exclusion',()=>{
 const before=structuredClone(entry),all=warnings(entry),active=warnings(entry,'active');
 assert.deepEqual(active,all);assert.equal(all.length,1);
 assert.match(all[0].reason,/last valid price and comparison/);assert.match(all[0].reason,/16 Oct 2026/);
 assert.doesNotMatch(all[0].reason,/left out|unavailable|zero/);
 const selection=selectComparisonEntries([entry],'active',now);
 assert.equal(calculateLatestContribution(selection.comparisonEntries,selection.referenceEntries).change,15);
 assert.equal(calculateHolding(entry.holding,instrument,entry.quote).purchaseGain,135);assert.deepEqual(entry,before);
});

test('unsafe and missing inputs are never restored from an earlier pair',()=>{
 for(const change of [{invalidations:['comparison']},{basis:'unverified'},{invalidations:['listing']},{invalidations:['current']}]) {
  const snapshot=normalizeMarketSnapshot({dataMode:'live',quotes:{[instrument.id]:{...entry.quote,...change}},issues:[]},[instrument]);
  const unsafe={...entry,quote:snapshot.quotes[instrument.id]},warn=warnings(unsafe);
  assert.equal(calculateHolding(entry.holding,instrument,unsafe.quote).completeChange,false);
  assert.match(warn[0].reason,/latest change is unavailable and is left out/);
  assert.doesNotMatch(warn[0].reason,/last valid price and comparison/);
 }
 for(const changes of [{previousClose:null},{price:null,status:'missing'},{fx:null,previousFx:null}]) {
  const changed={...entry,instrument:{...instrument,currency:changes.fx===null?'USD':'EUR'},quote:{...entry.quote,...changes}};
  assert.equal(calculateHolding(changed.holding,changed.instrument,changed.quote).completeChange,false);
 }
});

test('a delayed hidden holding is not blamed for the filtered movement; missing reference is explained separately',()=>{
 const hidden={...entry,instrument:{...instrument,id:'fictional-hidden'},holding:{...entry.holding,id:'hidden',instrumentId:'fictional-hidden'}};
 let result=comparisonWarnings([hidden],[],{missingInstrumentIds:[],missingReferenceInstrumentIds:[]},{now});
 assert.deepEqual(result,[]);
 result=comparisonWarnings([hidden],[],{missingInstrumentIds:[],missingReferenceInstrumentIds:['fictional-hidden']},{now});
 assert.equal(result.length,1);assert.match(result[0].reason,/portfolio percentage is unavailable/);assert.doesNotMatch(result[0].reason,/left out/);
});

test('network failures retain daily warnings while Since purchase quietly uses valid earlier data',()=>{
 const recovered={...entry,quote:{...entry.quote,status:'delayed',sourceAvailable:true}};
 assert.deepEqual(warnings(recovered),[]);assert.match(warnings(recovered,'all',{failed:true})[0].reason,/last valid price/);
 assert.deepEqual(warnings(entry,'purchase',{purchase:true}),[]);
 assert.deepEqual(holdingDataMessages(instrument,entry.quote,calculateHolding(entry.holding,instrument,entry.quote),[],now,{suppressFreshness:true}),[]);
 const nextDay=selectComparisonEntries([entry],'active',Date.parse('2026-10-19T11:00:00Z'));
 assert.equal(nextDay.comparisonEntries.length,0,'a retained Friday price cannot enter Monday before its first quote');
});

test('quiet purchase freshness does not hide missing prices, currency or unsafe listing data',()=>{
 for (const changes of [{price:null,status:'missing'},{invalidations:['listing']},{invalidations:['current']}]) {
  const snapshot=normalizeMarketSnapshot({dataMode:'live',quotes:{[instrument.id]:{...entry.quote,...changes}},issues:[]},[instrument]);
  const unsafe={...entry,quote:snapshot.quotes[instrument.id]},result=calculateHolding(unsafe.holding,instrument,unsafe.quote);
  const warn=warnings(unsafe,'purchase',{purchase:true});
  assert.equal(warn.length,1);assert.equal(warn[0].kind,'issue');assert.match(warn[0].reason,/price or currency information is missing or invalid/);
  assert.match(holdingDataMessages(instrument,unsafe.quote,result,[],now,{suppressFreshness:true}).join(' '),/unavailable|not been verified/);
 }
 const missingBaseline={...entry,quote:{...entry.quote,previousClose:null}};
 assert.deepEqual(warnings(missingBaseline,'purchase',{purchase:true}),[],'a daily baseline is not needed for purchase performance');
 assert.match(holdingDataMessages(instrument,missingBaseline.quote,calculateHolding(entry.holding,instrument,missingBaseline.quote),[],now,{suppressFreshness:true}).join(' '),/Previous closing value unavailable/,'expanded daily comparison still explains an actual missing input');
});

test('a session or future-time safety gate never describes an excluded pair as retained',()=>{
 const future={...entry,quote:{...entry.quote,observedAt:'2026-10-16T11:01:00Z'}};
 const result=warnings(future);
 assert.match(result[0].reason,/latest change is unavailable and is left out/);
 assert.doesNotMatch(result[0].reason,/last valid price and comparison/);
});

test('retained valid figures do not hide an independent missing-market-hours issue',()=>{
 const warning=warnings(entry,'all',{unknownIds:[instrument.id]});
 assert.equal(warning.length,1);assert.equal(warning[0].kind,'issue');
 assert.match(warning[0].reason,/last valid price and comparison/);
 assert.match(warning[0].reason,/market hours are unavailable/);
});
