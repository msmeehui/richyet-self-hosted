import test from 'node:test';
import assert from 'node:assert/strict';
import {calculateHolding, calculatePortfolio} from '../public/lib/portfolio.mjs';
import {normalizeInstruments, normalizeMarketSnapshot, normalizeCatalogue, normalizeSearchResults, normalizePreparation, unavailableInstrument, canAddInstrument, admissionMessage, holdingDataMessages, holdingFreshness, latestComparisonDates, priceDateMessage} from '../public/lib/market.mjs';
import {aboutContentForMode} from '../public/lib/about.mjs';

const instrument = {id:'example-us', name:'Example shares', symbol:'EXMP', exchange:'Example exchange', currency:'USD', type:'Stock', coverage:{price:true,comparison:true}};
const holding = {id:'position-us', instrumentId:instrument.id, quantity:'3', averageCost:null, eurCost:null};
const quote = {price:'3', previousClose:'2', date:'2026-10-09', previousDate:'2026-10-08', observedAt:'2026-10-09T20:00:00Z',
  previousObservedAt:'2026-10-08T20:00:00Z', fx:{numerator:'1',denominator:'3'}, previousFx:{numerator:'1',denominator:'2'},status:'closed'};
const snapshot = (quotes = {[instrument.id]:quote}, issues = []) => ({dataMode:'live', snapshotId:'test-1', generatedAt:'2026-10-10T14:00:00Z', quotes, issues});
const entry = (q = quote, h = holding, i = instrument) => ({holding:h,instrument:i,quote:q});
const admission = {instrumentId:instrument.id, capability:'supported', status:'ready', canAdd:true,
  currentReady:true, latestReady:true, reasons:[]};
const catalogue = (changes = {}, market = snapshot()) => ({dataMode:'live', instruments:[{...instrument,admission,...changes}],market});

test('retained US comparison shows its source date across market opening without claiming the exchange is closed', () => {
  const i = {...instrument,timeZone:'America/New_York'}, q = {...quote,priceKind:'close'};
  for (const now of ['2026-10-12T13:29:00Z','2026-10-12T13:30:00Z','2026-10-12T13:50:00Z']) {
    assert.deepEqual(latestComparisonDates(i,q,Date.parse(now)), {row:'As of 9 Oct',detail:'Compared with 8 Oct 2026'});
    assert.match(priceDateMessage(i,q), /^Closing price as of /);
    assert.doesNotMatch(priceDateMessage(i,q), /Market closed/);
  }
  assert.equal(latestComparisonDates(i,{...q,date:'2026-10-12',previousDate:'2026-10-09'},Date.parse('2026-10-12T14:00:00Z')).row,'');
  assert.deepEqual(holdingDataMessages(i,q,calculateHolding(holding,i,q)),[]);
});

test('comparison dates use the listing timezone and civil dates rather than device or refresh dates', () => {
  const i = {...instrument,timeZone:'America/New_York'}, now = Date.parse('2026-10-10T01:00:00Z');
  assert.equal(latestComparisonDates(i,{...quote,retrievedAt:'2026-10-10T01:00:00Z'},now).row,'');
  assert.equal(latestComparisonDates({...i,timeZone:'Europe/Amsterdam'},quote,now).row,'As of 9 Oct');
  assert.equal(latestComparisonDates({...i,timeZone:'invalid'},quote,now).row,'');
  assert.equal(latestComparisonDates(instrument,quote,now).detail,'Compared with 8 Oct 2026');
  for (const q of [{...quote,date:'2026-02-30'},{...quote,previousDate:quote.date},{...quote,previousClose:null},{...quote,price:null}])
    assert.deepEqual(latestComparisonDates(i,q,now),{row:'',detail:''});
});

test('older funds and stale prices keep exact dates and warnings instead of being called the previous trading day', () => {
  const i = {...instrument,type:'Fund',currency:'EUR',timeZone:'Europe/Amsterdam'}, q = {...quote,status:'stale',observedAt:null};
  assert.equal(latestComparisonDates(i,q,Date.parse('2026-10-16T14:00:00Z')).row,'As of 9 Oct');
  assert.equal(priceDateMessage(i,q),'Fund value dated 9 Oct 2026');
  assert.match(holdingDataMessages(i,q,calculateHolding(holding,i,q)).join(' '),/price is out of date/);
  assert.equal(priceDateMessage({...i,type:'ETF'},{...q,priceKind:'close'}),'Closing price dated 9 Oct 2026');
});

test('unreviewed closing dates gain a time only from a valid source observation, never a refresh or assumed market close', () => {
  const i = {...instrument,type:'Stock'}, q = {...quote,priceKind:'close',observedAt:null};
  for (const extra of [{}, {retrievedAt:'2026-10-12T15:00:00Z'}, {observedAt:'2026-10-09'}, {observedAt:'2026-10-09T16:00:00'}])
    assert.equal(priceDateMessage(i,{...q,...extra}),'Closing price dated 9 Oct 2026');
  assert.match(priceDateMessage(i,{...q,observedAt:'2026-10-09T19:47:00Z'}),/^Closing price as of 9 Oct 2026, \d{2}:47$/);
});

test('explicit incomplete collection rewords the recent-price warning without making its data ready', () => {
  const i = {...instrument,currency:'EUR',type:'ETF'}, now = Date.parse('2026-10-12T14:00:00Z');
  const raw = {...quote,date:'2026-10-12',previousDate:'2026-10-09',observedAt:'2026-10-12T13:45:00Z',status:'stale',eligibilityIssue:'incomplete_trade_collection',sourceAvailable:false};
  const issues = [{instrumentId:i.id,code:'price_stale'}];
  const market = normalizeMarketSnapshot(snapshot({[i.id]:raw},issues),[i]), q = market.quotes[i.id];
  const c = calculateHolding(holding,i,q), text = holdingDataMessages(i,q,c,market.issues,now).join(' ');
  assert.equal(q.status,'stale');assert.equal(c.stale,true);assert.equal(c.value,9);
  assert.deepEqual(holdingFreshness(i,q,market.issues,now),{priceStale:false,fxStale:false,updateIncomplete:true,updateFailed:false});
  assert.match(text,/price update is incomplete/);assert.match(text,/could not be refreshed/);
  assert.doesNotMatch(text,/price is out of date/);
  assert.throws(()=>normalizeCatalogue(catalogue({},snapshot({[i.id]:raw},issues))),/prices could not be read together/);
});

test('incomplete collection cannot hide real age, missing proof or an independent stale FX warning', () => {
  const now = Date.parse('2026-10-12T14:00:00Z'), q = {...quote,date:'2026-10-12',observedAt:'2026-10-12T13:45:00Z',status:'stale',eligibilityIssue:'incomplete_trade_collection'};
  for (const change of [{observedAt:'2026-10-12T12:29:59Z'},{observedAt:null},{observedAt:'2026-10-12T14:00:01Z'},{date:null},{price:null},{eligibilityIssue:'unknown'}]) {
    const actual = {...q,...change}, freshness = holdingFreshness(instrument,actual,[],now);
    assert.equal(freshness.updateIncomplete,false);assert.equal(freshness.priceStale,true);
    assert.match(holdingDataMessages(instrument,actual,calculateHolding(holding,instrument,actual),[],now).join(' '),/price is out of date/);
  }
  const issues = [{instrumentId:instrument.id,code:'price_stale'},{instrumentId:instrument.id,code:'fx_stale'}];
  const text = holdingDataMessages(instrument,q,calculateHolding(holding,instrument,q),issues,now).join(' ');
  assert.match(text,/price update is incomplete/);assert.match(text,/currency rate is out of date/);
});

test('a proved failed source check of a recent quote is distinct from an old price, preserving other warnings', () => {
  const now=Date.parse('2026-10-12T14:00:00Z'), q={...quote,date:'2026-10-12',previousDate:'2026-10-09',observedAt:'2026-10-12T13:45:00Z',status:'stale',sourceAvailable:false};
  const issues=[{instrumentId:instrument.id,code:'source_unavailable'},{instrumentId:instrument.id,code:'price_stale'},{instrumentId:instrument.id,code:'fx_stale'}];
  const text=holdingDataMessages(instrument,q,calculateHolding(holding,instrument,q),issues,now).join(' ');
  assert.match(text,/latest data check could not be completed/);assert.match(text,/currency rate is out of date/);assert.doesNotMatch(text,/price is out of date/);
  assert.equal(holdingFreshness(instrument,q,issues,now).updateFailed,true);
  for(const change of [{sourceAvailable:true},{observedAt:'2026-10-12T12:29:59Z'},{observedAt:null},{observedAt:'2026-10-12T14:01:00Z'}]){
    const actual={...q,...change};assert.equal(holdingFreshness(instrument,actual,issues,now).updateFailed,false);
    assert.match(holdingDataMessages(instrument,actual,calculateHolding(holding,instrument,actual),issues,now).join(' '),/price is out of date/);
  }
  assert.equal(holdingFreshness(instrument,q,[],now).updateFailed,false);
});

test('live Add requires an explicit complete admission, while the fictional catalogue remains usable', () => {
  assert.equal(canAddInstrument(normalizeCatalogue(catalogue()).instruments[0]), true);
  for (const value of [undefined, {...admission,instrumentId:'another-listing'}, {...admission,latestReady:false},
    {...admission,currentReady:false}, {...admission,capability:'unverified'}, {...admission,canAdd:'true'}]) {
    const [i] = normalizeInstruments([{...instrument,canAdd:true,admission:value}]);
    assert.equal(canAddInstrument(i), false);
  }
  assert.equal(canAddInstrument({id:'fictional',currency:'EUR',type:'ETF'}), true);
});

test('ready catalogue data carries a complete coherent pair and cannot borrow absent price or FX', () => {
  const ready = normalizeCatalogue(catalogue());
  assert.equal(ready.market.quotes[instrument.id].previousClose, '2');
  for (const change of [{price:null}, {previousClose:null}, {previousClose:'0'}, {fx:null}, {previousFx:null}, {status:'stale'}, {date:'2026-02-30'}]) {
    assert.throws(() => normalizeCatalogue(catalogue({},snapshot({[instrument.id]:{...quote,...change}}))), /prices could not be read together/);
  }
  assert.throws(() => normalizeCatalogue({...catalogue(),market:null}), /Market data could not be read/);
});

test('unavailable catalogue entries explain the failure without hiding existing position values', () => {
  const unavailable = {...admission,status:'temporarily_unavailable',canAdd:false,latestReady:false,reasons:['comparison_unavailable']};
  const c = normalizeCatalogue(catalogue({admission:unavailable},snapshot({[instrument.id]:{...quote,previousClose:null}})));
  const i = c.instruments[0], q = c.market.quotes[i.id];
  assert.equal(canAddInstrument(i), false);
  assert.match(admissionMessage(i), /Latest comparison unavailable/);
  assert.equal(calculateHolding({...holding,eurCost:'2'},i,q).purchaseGain, 1);
  assert.match(admissionMessage({...i,admission:{...unavailable,reasons:['fx_unavailable']}}), /Currency comparison unavailable/);
  assert.match(admissionMessage({...i,admission:{...unavailable,status:'unsupported'}}), /not supported/);
});

test('a date-only EUR stock or ETF close values immediately without inventing an observation time', () => {
  for (const type of ['Stock','ETF']) {
    const i = {...instrument,type,currency:'EUR'};
    const q = {...quote,priceKind:'close',observedAt:null,previousObservedAt:null};
    const result = normalizeCatalogue({dataMode:'live',instruments:[{...i,admission}],market:snapshot({[i.id]:q})});
    const accepted = result.market.quotes[i.id];
    assert.equal(accepted.observedAt, null);
    assert.equal(accepted.previousObservedAt, null);
    assert.equal(accepted.date, '2026-10-09');
    assert.equal(calculateHolding({...holding,averageCost:'1'},i,accepted).change, 3);
    assert.equal(calculateHolding({...holding,averageCost:'1'},i,accepted).purchaseGain, 6);
  }
});

test('date-only support cannot rehabilitate a missing trade timestamp or a malformed close timestamp', () => {
  for (const changes of [{priceKind:'trade',observedAt:null}, {observedAt:null}, {priceKind:'close',observedAt:'invalid'},
    {priceKind:'close',observedAt:undefined}, {priceKind:'nav',observedAt:null}]) {
    const q = normalizeMarketSnapshot(snapshot({[instrument.id]:{...quote,...changes}}), [instrument]).quotes[instrument.id];
    assert.equal(q.price, null);
  }
});

test('new quote proof invalidations cannot leave an apparently valid numeric comparison', () => {
  for (const proof of [{invalidations:['comparison']}, {basis:'unverified'}]) {
    const q = normalizeMarketSnapshot(snapshot({[instrument.id]:{...quote,...proof}}), [instrument]).quotes[instrument.id];
    assert.equal(calculateHolding({...holding,eurCost:'2'},instrument,q).purchaseGain, 1);
    assert.equal(calculateHolding(holding,instrument,q).change, null);
  }
  for (const invalidations of [['listing'], ['current'], ['unknown'], null]) {
    const q = normalizeMarketSnapshot(snapshot({[instrument.id]:{...quote,invalidations}}), [instrument]).quotes[instrument.id];
    assert.equal(calculateHolding(holding,instrument,q).value, null);
  }
});

test('an eligible retained history pair remains usable but reports a source outage visibly', () => {
  const market = normalizeMarketSnapshot(snapshot({[instrument.id]:{...quote,sourceAvailable:false}},
    [{instrumentId:instrument.id,code:'history_source_unavailable'}]), [instrument]);
  const q = market.quotes[instrument.id], c = calculateHolding(holding,instrument,q);
  assert.equal(c.completeChange, true);
  assert.deepEqual(holdingDataMessages(instrument,q,c,market.issues), ['The data source could not be refreshed. Any displayed value uses its last available observation.']);
});

test('raw EURUSD closes are inverted exactly on both sides, without rounded reciprocal arithmetic', () => {
  const result = calculateHolding(holding, instrument, quote);
  assert.equal(result.value, 3);
  assert.equal(result.baseline, 3);
  assert.equal(result.change, 0);
  assert.equal(result.purchaseGain, null);
  const precise = calculateHolding({...holding,quantity:'1'}, instrument, {...quote,
    price:'30000000000000000.03',previousClose:'30000000000000000',previousFx:quote.fx});
  assert.equal(precise.change, 0.01);
});

test('invalid or missing current and baseline ratios stay independently unavailable', () => {
  for (const fx of [{numerator:'1',denominator:'0'}, {numerator:'0',denominator:'3'}, {numerator:1,denominator:'3'}, {numerator:'1',denominator:'3e0'}]) {
    assert.equal(calculateHolding(holding, instrument, {...quote,fx}).value, null);
    const baseline = calculateHolding(holding, instrument, {...quote,previousFx:fx});
    assert.equal(baseline.value, 3);
    assert.equal(baseline.change, null);
  }
});

test('closed-market observations preserve their original dates and are not made stale by a weekend', () => {
  const market = normalizeMarketSnapshot(snapshot(), [instrument]);
  const q = market.quotes[instrument.id];
  const result = calculateHolding(holding, instrument, q);
  assert.equal(q.observedAt, quote.observedAt);
  assert.equal(q.status, 'closed');
  assert.equal(result.stale, false);
  assert.deepEqual(holdingDataMessages(instrument,q,result,market.issues), []);
});

test('an unsupported holding remains part of portfolio completeness instead of disappearing', () => {
  const missing = {holding:{...holding,id:'position-unknown',instrumentId:'unknown'}, instrument:unavailableInstrument('unknown'), quote:{status:'missing'}};
  const total = calculatePortfolio([entry(), missing]);
  assert.equal(total.count, 2);
  assert.equal(total.value, null);
  assert.equal(total.change, null);
  assert.equal(total.knownValue, 3);
  assert.equal(canAddInstrument(missing.instrument), false);
  assert.match(holdingDataMessages(missing.instrument,missing.quote,calculateHolding(missing.holding,missing.instrument,missing.quote)).join(' '), /listing has not been verified/);
});

test('malformed individual prices do not turn missing values into zero or discard valid neighbours', () => {
  const market = normalizeMarketSnapshot(snapshot({[instrument.id]:quote, broken:{...quote,price:null}, numeric:{...quote,price:0}, badDate:{...quote,date:'2026-02-30'}}), [instrument]);
  assert.equal(market.quotes[instrument.id].price, '3');
  for (const id of ['broken','numeric','badDate']) assert.equal(market.quotes[id].price, null);
  assert.throws(() => normalizeMarketSnapshot({quotes:{},issues:[]}), /could not be read/);
  assert.deepEqual(normalizeInstruments([{name:'No identifier'}]), []);
});

test('missing baselines preserve independent purchase results and missing FX blocks valuation', () => {
  const owned = {...holding,eurCost:'2'};
  const market = normalizeMarketSnapshot(snapshot({[instrument.id]:{...quote,previousClose:null}},[{instrumentId:instrument.id,code:'baseline_missing'}]), [instrument]);
  const q = market.quotes[instrument.id], c = calculateHolding(owned,instrument,q);
  assert.equal(c.value, 3);
  assert.equal(c.change, null);
  assert.equal(c.purchaseGain, 1);
  assert.match(holdingDataMessages(instrument,q,c,market.issues).join(' '), /Previous closing value unavailable/);
  const noFx = {...q,fx:null};
  assert.equal(calculateHolding(owned,instrument,noFx).value, null);
});

test('stale FX and unavailable refresh messages remain distinct from a stale price', () => {
  const issues = [{instrumentId:instrument.id,code:'fx_stale'},{instrumentId:instrument.id,code:'source_unavailable'}];
  const market = normalizeMarketSnapshot(snapshot(undefined,issues), [instrument]);
  const q = market.quotes[instrument.id], c = calculateHolding(holding,instrument,q);
  assert.equal(c.value, 3);
  assert.equal(c.stale, true);
  const text = holdingDataMessages(instrument,q,c,market.issues).join(' ');
  assert.match(text, /currency rate is out of date/);
  assert.match(text, /could not be refreshed/);
  assert.doesNotMatch(text, /price is out of date/);
});

test('unverified actions block only the comparison; unverified identity blocks valuation', () => {
  const action = normalizeMarketSnapshot(snapshot(undefined,[{instrumentId:instrument.id,code:'corporate_action_unverified'}]), [instrument]);
  assert.equal(calculateHolding(holding,instrument,action.quotes[instrument.id]).value, 3);
  assert.equal(calculateHolding(holding,instrument,action.quotes[instrument.id]).change, null);
  const identity = normalizeMarketSnapshot(snapshot(undefined,[{instrumentId:instrument.id,code:'listing_unverified'}]), [instrument]);
  assert.equal(calculateHolding(holding,instrument,identity.quotes[instrument.id]).value, null);
});

test('a stale fund remains a civil valuation date even without a publication timestamp', () => {
  const fund = {...instrument,currency:'EUR',type:'Fund'};
  const market = normalizeMarketSnapshot(snapshot({[fund.id]:{...quote,status:'stale',observedAt:null}}), [fund]);
  assert.equal(market.quotes[fund.id].date, '2026-10-09');
  assert.equal(market.quotes[fund.id].price, '3');
  assert.equal(calculateHolding(holding,fund,market.quotes[fund.id]).value, 9);
});

test('demo and live About text distinguish fictional prices, account saving and missing data', () => {
  const demo = aboutContentForMode('demo'), live = aboutContentForMode('live');
  assert.match(demo, /fictional preview/);
  assert.match(demo, /saved in this browser only/);
  assert.doesNotMatch(live, /This is a fictional preview|Prices do not refresh|This preview has no account/);
  assert.match(live, /original cost in your portfolio currency is unknown/);
  assert.match(live, /Prices may be delayed/);
  assert.doesNotMatch(live,/Your holdings are saved privately|Coverage is limited to checked listings/);
  assert.match(live,/same previous value of the whole portfolio/);
});


test('discovery ready is not permission to enter a holding without preparation', () => {
  const rows=['ready','available','unavailable'].map((preparationStatus,n)=>({...instrument,id:`fictional-${n}`,preparationStatus}));
  const result=normalizeSearchResults({instruments:rows,offset:0,hasMore:true});
  assert.deepEqual(result.instruments.map(i=>i.preparationStatus),['ready','available','unavailable']);
  for(const i of result.instruments)assert.equal(canAddInstrument(i),false);
  assert.throws(()=>normalizePreparation({status:'ready'},rows[0].id),/prices could not be read together/);
});

test('malformed pagination or duplicated listing identities fail instead of silently skipping a page', () => {
  const row={...instrument,preparationStatus:'available'};
  for(const response of [{instruments:[row],offset:20,hasMore:false},{instruments:[],offset:0,hasMore:true},
    {instruments:[row,row],offset:0,hasMore:false},{instruments:[{...row,preparationStatus:'pending'}],offset:0,hasMore:false}]) {
    assert.throws(()=>normalizeSearchResults(response,0),/could not be read/);
  }
  assert.equal(normalizeSearchResults({instruments:[],offset:40,hasMore:false},40).offset,40);
});

test('preparation requires the requested exact listing and bounds source explanations', () => {
  assert.equal(normalizePreparation({status:'ready',catalogue:catalogue()},instrument.id).status,'ready');
  assert.throws(()=>normalizePreparation({status:'ready',catalogue:catalogue()},'different-listing'),/could not be read together/);
  const unavailable=normalizePreparation({status:'unavailable',message:'x'.repeat(1000)},instrument.id);
  assert.equal(unavailable.message.length,500);assert.equal(unavailable.catalogue,null);
  assert.throws(()=>normalizePreparation({status:'accepted'},instrument.id),/could not be read/);
});
