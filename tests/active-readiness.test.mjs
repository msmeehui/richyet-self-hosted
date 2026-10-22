import test from 'node:test';
import assert from 'node:assert/strict';
import {createActiveReadinessTracker} from '../public/lib/active-readiness.mjs';
import {selectComparisonEntries} from '../public/lib/comparison-modes.mjs';
import {selectHoldingSections} from '../public/lib/holding-sections.mjs';
import {calculateLatestSubtotal,calculatePortfolio} from '../public/lib/portfolio.mjs';
const instrument={id:'fictional-ready-us',isin:'ZZ0000000999',type:'Stock',currency:'USD',mic:'XNAS',timeZone:'America/New_York',closeSchedule:'us_retained_calendar'};
const row=(changes={})=>({instrument,holding:{id:'fictional-position',instrumentId:instrument.id,quantity:'2',averageCost:'80',eurCost:null},quote:{date:'2026-10-14',previousDate:'2026-10-13',observedAt:'2026-10-14T13:50:00Z',priceKind:'trade',status:'delayed',price:'110',previousClose:'100',fx:'0.9',previousFx:'0.9',...changes}});
const now=Date.parse('2026-10-14T14:00:00Z');
const storage=()=>{const values=new Map();return {getItem:k=>values.get(k)??null,setItem:(k,v)=>values.set(k,v),removeItem:k=>values.delete(k),values};};
const select=(r,tracker,time=now,mode='active')=>selectComparisonEntries(r,mode,time,tracker);

test('first usable pair admits a holding; missing first price, comparison or FX produces no partial subtotal',()=>{
 const tracker=createActiveReadinessTracker();tracker.bind('fictional@example.test');
 for(const changes of [{date:'2026-10-13'},{previousClose:null},{fx:null},{previousFx:null},{price:'0',previousClose:'0'}]){
  const result=select([row(changes)],tracker);
  assert.equal(result.comparisonEntries.length,0);assert.deepEqual(result.failedInstrumentIds,[]);
  assert.equal(calculateLatestSubtotal(result.comparisonEntries).partial,false);
 }
 for(const price of ['100','95','110']){
  const current=row({price}),before=structuredClone(current),selection=select([current],tracker);
  assert.equal(selection.comparisonEntries.length,1);assert.equal(calculateLatestSubtotal(selection.comparisonEntries).change,Number(price)*1.8-180);
  assert.deepEqual(current,before);
 }
});

test('a later invalid pair stays visible with unavailable movement, a named warning and exact available subtotal',()=>{
 const tracker=createActiveReadinessTracker();tracker.bind('fictional@example.test');
 const first=row(),other={...row(),instrument:{...instrument,id:'fictional-second'},holding:{...row().holding,id:'fictional-second-position',instrumentId:'fictional-second'}};
 select([first,other],tracker);
 for(const changes of [{previousClose:null},{observedAt:null},{date:null,price:null}]){
  const failed=row(changes),selection=select([failed,other],tracker),subtotal=calculateLatestSubtotal(selection.comparisonEntries);
  assert.deepEqual(selection.failedInstrumentIds,[instrument.id]);assert.equal(subtotal.change,18);assert.equal(subtotal.changePercent,10);assert.equal(subtotal.partial,true);
  const view=selectHoldingSections([failed,other],now,'active',tracker);
  assert.deepEqual(view.visibleEntries.map(r=>r.holding.id),[first.holding.id,other.holding.id]);
  assert.equal(selection.comparisonEntries[0].quote.previousClose,null);
  assert.equal(calculatePortfolio([failed,other]).purchaseGain,changes.price===null?null:108);
 }
 const recovered=select([first,other],tracker);assert.deepEqual(recovered.failedInstrumentIds,[]);assert.equal(calculateLatestSubtotal(recovered.comparisonEntries).change,36);
});

test('failed refreshes retain the exact valid pair and movement across repeated reads and reload',()=>{
 const store=storage(),tracker=createActiveReadinessTracker(store);tracker.bind('fictional@example.test');
 const valid=row(),expected=calculateLatestSubtotal([valid]);select([valid],tracker);
 for(const changes of [{sourceAvailable:false},{status:'stale'},{sourceAvailable:false,status:'stale'}]) {
  const retained=row(changes),before=structuredClone(retained);
  for(const mode of ['active','all']) for(let n=0;n<3;n++) {
   const selection=select([retained],tracker,now,mode),actual=calculateLatestSubtotal(selection.comparisonEntries);
   assert.equal(actual.change,expected.change);assert.equal(actual.changePercent,expected.changePercent);
   assert.equal(selection.comparisonEntries[0].quote.previousClose,'100');
   assert.deepEqual(selection.failedInstrumentIds,[instrument.id]);assert.deepEqual(retained,before);
  }
  const reloaded=createActiveReadinessTracker();reloaded.bind('fictional@example.test');
  assert.equal(calculateLatestSubtotal(select([retained],reloaded).comparisonEntries).change,expected.change);
 }
 assert.deepEqual(select([valid],tracker).failedInstrumentIds,[]);
});

test('reload retains tab-scoped readiness without storing prices, quantities or costs; account change clears it',()=>{
 const store=storage(),first=createActiveReadinessTracker(store);first.bind('fictional@example.test');select([row()],first);
 const receipt=[...store.values.values()][0];assert.ok(receipt);assert.doesNotMatch(receipt,/quantity|averageCost|eurCost|previousClose|"price"/);
 first.clear({keepReceipt:true});assert.equal(store.values.size,1,'pagehide clears memory without deleting the reload receipt');
 const reloaded=createActiveReadinessTracker(store);reloaded.bind('fictional@example.test');assert.equal(select([row({previousClose:null})],reloaded).failedInstrumentIds.length,1);
 reloaded.bind('another@example.test');assert.equal(select([row({previousClose:null})],reloaded).comparisonEntries.length,0);
 reloaded.clear();assert.equal(store.values.size,0);
});

test('closed window, next session, removed holding or changed exact listing cannot inherit a prior failure',()=>{
 for(const reset of ['close','day','remove','listing']){
  const tracker=createActiveReadinessTracker();tracker.bind('fictional@example.test');select([row()],tracker);
  let failed=row({previousClose:null}),time=now;
  if(reset==='close')select([row()],tracker,Date.parse('2026-10-14T21:00:00Z'));
  if(reset==='remove')select([],tracker);
  if(reset==='day'){time=Date.parse('2026-10-15T14:00:00Z');failed=row({date:'2026-10-15',observedAt:'2026-10-15T13:50:00Z',previousClose:null});}
  if(reset==='listing')failed={...failed,instrument:{...instrument,isin:'ZZ0000000002'}};
  assert.deepEqual(select([failed],tracker,time).comparisonEntries,[],reset);
 }
});

test('All and Since purchase retain original pairs and full value while readiness tracks independent of selected mode',()=>{
 const tracker=createActiveReadinessTracker();tracker.bind('fictional@example.test');const ready=row(),before=structuredClone(ready),total=calculatePortfolio([ready]);
 for(const mode of ['all','purchase'])assert.equal(select([ready],tracker,now,mode).comparisonEntries[0],ready);
 const failed=row({previousClose:null});assert.equal(select([failed],tracker).failedInstrumentIds.length,1);
 assert.deepEqual(ready,before);assert.deepEqual(calculatePortfolio([ready]),total);
 const blocked=createActiveReadinessTracker({getItem(){throw Error('unavailable');},setItem(){throw Error('unavailable');},removeItem(){throw Error('unavailable');}});
 blocked.bind('fictional@example.test');select([ready],blocked);assert.equal(select([failed],blocked).failedInstrumentIds.length,1);blocked.clear();
});
