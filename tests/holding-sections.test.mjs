import test from 'node:test';
import assert from 'node:assert/strict';
import {selectHoldingSections, formatHoldingObservation} from '../public/lib/holding-sections.mjs';
import {calculateHolding, calculatePortfolio, calculateLatestSubtotal} from '../public/lib/portfolio.mjs';

const at = Date.parse;
const us = {id:'fictional-us',type:'Stock',currency:'USD',mic:'XNAS',timeZone:'America/New_York',closeSchedule:'us_retained_calendar'};
const ams = {id:'fictional-eu',type:'Stock',currency:'EUR',mic:'XAMS',timeZone:'Europe/Amsterdam',closeSchedule:'ams_j0_continuous_2026'};
const xetra = {id:'fictional-etf',type:'ETF',currency:'EUR',mic:'XETA',timeZone:'Europe/Berlin',closeSchedule:'xetra_etf_regular_2026'};
const fund = {id:'fictional-fund',type:'Fund',currency:'EUR',mic:null};
const unknown = {...ams,id:'fictional-other',closeSchedule:'unreviewed'};
function entry(instrument, changes = {}) {
  return {holding:{id:`holding-${instrument.id}`,instrumentId:instrument.id,quantity:'10',averageCost:'90',eurCost:null},instrument,
    quote:{status:'delayed',price:'102',previousClose:'100',date:'2026-10-13',previousDate:'2026-10-12',
      observedAt:'2026-10-13T09:00:00Z',priceKind:'trade',fx:'1',previousFx:'1',...changes}};
}
const ids = rows => rows.map(row => row.holding.id);
const calculation = row => calculateHolding(row.holding,row.instrument,row.comparisonQuote);

test('sections filter the saved order without price-weight sorting, duplicated or missing positions',()=>{
  const second = {...ams,id:'fictional-second'};
  const rows = [entry(us),entry(fund),entry(second,{price:'999'}),entry(unknown),entry(ams)];
  const result = selectHoldingSections(rows,at('2026-10-13T10:00:00Z'));
  assert.deepEqual(result.sections.map(({id,title})=>({id,title})),[
    {id:'open',title:'Open markets'},
    {id:'closed',title:'Closed markets & other holdings'},
  ]);
  assert.deepEqual(ids(result.entries),ids(rows));
  assert.deepEqual(ids(result.sections[0].entries),[rows[2].holding.id,rows[4].holding.id]);
  assert.deepEqual(new Set(result.sections.flatMap(s=>ids(s.entries))),new Set(ids(rows)));
  assert.equal(result.sections.reduce((n,s)=>n+s.entries.length,0),rows.length);
  assert.deepEqual(selectHoldingSections([],at('2026-10-13T10:00:00Z')).sections,[]);
  assert.throws(()=>selectHoldingSections(null),TypeError);
});

test('single nonempty groups and daily fund values never claim exchange hours',()=>{
  const now = at('2026-10-13T10:00:00Z');
  for (const [instrument,id] of [[ams,'open'],[us,'closed'],[fund,'closed'],[unknown,'closed']]) {
    const result = selectHoldingSections([entry(instrument)],now);
    assert.deepEqual(result.sections.map(section=>section.id),[id]);
    assert.equal(result.entries[0].quote,result.entries[0].comparisonQuote);
  }
  const nav = entry(fund,{date:'2026-10-12',previousDate:'2026-10-09',observedAt:null,priceKind:'nav'});
  const selected = selectHoldingSections([nav],now).entries[0];
  assert.equal(selected.sessionState,'fund');
  assert.equal(selected.waitingForCurrentSession,false);
  assert.equal(calculation(selected).change,20);
});

test('opening with a retained prior-session quote waits in the row and top without changing value or purchase gain',()=>{
  const quote = {date:'2026-10-09',previousDate:'2026-10-08',observedAt:'2026-10-09T15:00:00Z',priceKind:'close'};
  const row = entry(ams,quote), before = calculateHolding(row.holding,row.instrument,row.quote);
  const result = selectHoldingSections([row],at('2026-10-12T07:01:00Z'));
  const displayed = result.entries[0], after = calculation(displayed);
  assert.equal(displayed.sessionState,'open');
  assert.equal(displayed.waitingForCurrentSession,true);
  assert.deepEqual(result.openMarkets.waitingInstrumentIds,[ams.id]);
  assert.equal(displayed.quote,row.quote,'details retain the original source and dates');
  assert.notEqual(displayed.comparisonQuote,row.quote);
  assert.equal(after.change,null);assert.equal(after.changePercent,null);
  assert.equal(calculateLatestSubtotal(result.openMarkets.comparisonEntries).change,null);
  for (const key of ['value','purchaseCost','purchaseGain','purchaseGainPercent']) assert.equal(after[key],before[key]);
  assert.equal(displayed.comparisonQuote.price,'102');
  assert.equal(displayed.comparisonQuote.date,'2026-10-09');
  assert.equal(displayed.comparisonQuote.observedAt,'2026-10-09T15:00:00Z');
  assert.equal(row.quote.previousClose,'100');
});

test('the same waiting rule reaches estimated EUR purchase gains without suppressing or changing them',()=>{
  const row = entry(us,{date:'2026-10-12',previousDate:'2026-10-09',observedAt:'2026-10-12T20:00:00Z',priceKind:'close',
    fx:{numerator:'10',denominator:'11'},previousFx:{numerator:'5',denominator:'6'}});
  const before = calculateHolding(row.holding,row.instrument,row.quote);
  const displayed = selectHoldingSections([row],at('2026-10-13T13:35:00Z')).entries[0], after = calculation(displayed);
  assert.equal(displayed.waitingForCurrentSession,true);
  assert.equal(after.change,null);
  assert.equal(after.purchaseGainEstimated,true);
  for (const key of ['value','purchaseCost','purchaseGain','purchaseGainPercent','purchaseCostEstimated','purchaseGainEstimated']) assert.equal(after[key],before[key]);
});

test('actual current-session arrival restores exactly the same daily comparison in the row and subtotal',()=>{
  const row = entry(us,{observedAt:'2026-10-13T13:39:00Z',observationKind:'minute_bar'});
  const result = selectHoldingSections([row],at('2026-10-13T14:00:00Z'));
  assert.equal(result.entries[0].comparisonQuote,row.quote);
  assert.equal(result.entries[0].waitingForCurrentSession,false);
  assert.equal(calculation(result.entries[0]).change,20);
  assert.equal(calculateLatestSubtotal(result.openMarkets.comparisonEntries).change,20);
  assert.equal(calculateLatestSubtotal(result.openMarkets.comparisonEntries).partial,false);
});

test('current timestamp with missing baseline is a data gap rather than waiting for the first session quote',()=>{
  const rows = [entry(ams),entry({...ams,id:'fictional-missing'},{previousClose:null}),
    entry({...ams,id:'fictional-waiting'},{observedAt:null})];
  const result = selectHoldingSections(rows,at('2026-10-13T10:00:00Z'));
  assert.deepEqual(result.entries.map(row=>row.waitingForCurrentSession),[false,false,true]);
  assert.deepEqual(result.entries.map(row=>calculation(row).change),[20,null,null]);
  const subtotal = calculateLatestSubtotal(result.openMarkets.comparisonEntries);
  assert.equal(subtotal.totalCount,3);assert.equal(subtotal.includedCount,1);assert.equal(subtotal.change,20);assert.equal(subtotal.partial,true);
});

test('unverified timestamps cannot make an open row contribute yesterday movement or a fabricated zero',()=>{
  for (const changes of [{observedAt:null},{priceKind:'close'},{observationKind:'close'},
    {observedAt:'2026-10-13T14:01:00Z'},{observedAt:'2026-10-12T14:00:00Z'},
    {observedAt:'2026-10-13T12:00:00Z'},{date:'2026-10-12',observedAt:'2026-10-13T13:39:00Z'},
    {observedAt:'2026-10-13'},{observedAt:'bad',retrievedAt:'2026-10-13T13:39:00Z'}]) {
    const row = entry(us,{observedAt:'2026-10-13T13:39:00Z',...changes});
    const result = selectHoldingSections([row],at('2026-10-13T14:00:00Z'));
    assert.equal(result.entries[0].waitingForCurrentSession,true);
    assert.equal(calculation(result.entries[0]).change,null);
    assert.equal(calculateLatestSubtotal(result.openMarkets.comparisonEntries).change,null);
    assert.equal(calculation(result.entries[0]).value,1020);
  }
});

test('current-clock closure beats stored quote status and keeps its dated comparison',()=>{
  const row = entry(ams,{status:'delayed'});
  const result = selectHoldingSections([row],at('2026-10-13T17:00:00Z'));
  assert.equal(result.entries[0].sessionState,'closed');
  assert.equal(result.entries[0].waitingForCurrentSession,false);
  assert.equal(result.entries[0].comparisonQuote,row.quote);
  assert.equal(calculation(result.entries[0]).change,20);
  assert.equal(calculateLatestSubtotal(result.openMarkets.comparisonEntries).change,null);
  assert.equal(selectHoldingSections([entry(ams,{status:'closed'})],at('2026-10-13T10:00:00Z')).entries[0].sessionState,'open');
});

test('unreviewed calendars remain neutral and never overwrite their comparison',()=>{
  for (const [instrument,now] of [[ams,'2027-10-13T10:00:00Z'],[unknown,'2026-10-13T10:00:00Z']]) {
    const row = entry(instrument), result = selectHoldingSections([row],at(now));
    assert.equal(result.sections[0].id,'closed');assert.equal(result.entries[0].sessionState,'unknown');
    assert.equal(result.entries[0].comparisonQuote,row.quote);assert.equal(result.openMarkets.unknownCount,1);
  }
});

test('repartitioning at a market boundary preserves each filtered custom order and all saved inputs',()=>{
  const us2 = {...us,id:'fictional-us-two'}, rows = [entry(us2),entry(ams),entry(us),entry(fund)];
  const clone = structuredClone(rows), total = calculatePortfolio(rows);
  const freeze = value => {if(value && typeof value === 'object'){Object.values(value).forEach(freeze);Object.freeze(value);}};
  freeze(rows);
  const before = selectHoldingSections(rows,at('2026-10-13T13:29:59Z'));
  const after = selectHoldingSections(rows,at('2026-10-13T13:30:00Z'));
  assert.deepEqual(ids(before.sections.find(s=>s.id==='closed').entries),[rows[0].holding.id,rows[2].holding.id,rows[3].holding.id]);
  assert.deepEqual(ids(after.sections.find(s=>s.id==='open').entries),[rows[1].holding.id]);
  assert.deepEqual(ids(after.entries),ids(rows));assert.deepEqual(rows,clone);assert.deepEqual(calculatePortfolio(rows),total);
});

test('inline actual observation times use the viewer day and timezone including midnight and year boundaries',()=>{
  const q = entry(ams).quote, now = at('2026-10-13T10:00:00Z');
  assert.equal(formatHoldingObservation(ams,q,now,'Europe/Amsterdam'),'11:00');
  assert.equal(formatHoldingObservation(ams,q,now,'America/New_York'),'05:00');
  assert.equal(formatHoldingObservation(ams,{...q,observedAt:'2026-10-12T22:30:00Z'},now,'Europe/Amsterdam'),'00:30');
  assert.equal(formatHoldingObservation(ams,{...q,observedAt:'2026-10-12T22:30:00Z'},now,'America/New_York'),'12 Oct');
  assert.equal(formatHoldingObservation(ams,{...q,observedAt:'2025-12-31T20:00:00Z'},now,'Europe/Amsterdam'),'31 Dec 2025');
});

test('date-only NAV and scheduled US daily close never acquire a fabricated observation time',()=>{
  const now = at('2026-10-13T21:00:00Z'), q = entry(fund,{date:'2026-10-13',observedAt:'2026-10-13T20:00:00Z',priceKind:'nav'}).quote;
  assert.equal(formatHoldingObservation(fund,q,now,'Europe/Amsterdam'),'13 Oct');
  for (const changes of [{priceKind:'close'},{observationKind:'close'}])
    assert.equal(formatHoldingObservation(us,{...q,...changes},now,'Europe/Amsterdam'),'13 Oct');
  assert.equal(formatHoldingObservation(xetra,{...q,priceKind:'close',observedAt:'2026-10-13T15:36:00Z'},now,'Europe/Amsterdam'),'17:36','verified auction timestamp remains a source instant');
});

test('invalid or future observation time falls back only to a valid explicit date, never retrieval time',()=>{
  const now = at('2026-10-13T10:00:00Z'), q = entry(ams).quote;
  for (const observedAt of [null,'bad','2026-10-13','2026-10-13T09:00:00','2026-10-13T11:00:00Z','2026-02-30T09:00:00Z']) {
    assert.equal(formatHoldingObservation(ams,{...q,observedAt,date:'2026-10-12',retrievedAt:'2026-10-13T09:59:00Z'},now,'Europe/Amsterdam'),'12 Oct');
    assert.equal(formatHoldingObservation(ams,{...q,observedAt,date:'2026-02-30'},now,'Europe/Amsterdam'),'');
  }
  assert.equal(formatHoldingObservation(ams,q,NaN,'Europe/Amsterdam'),'');
  assert.equal(formatHoldingObservation(ams,q,now,'invalid'),'');
  assert.equal(formatHoldingObservation(null,q,now),'');
});


test('visible list keeps full custom order in All and purchase but only eligible open holdings and daily funds in Active',()=>{
  const daily={...fund,contributionSchedule:'bnd_daily_amsterdam'};
  const nav=entry(daily,{status:'nav',priceKind:'nav',date:'2026-10-12',previousDate:'2026-10-09',observedAt:null,
    fundContribution:{valuationDate:'2026-10-12',firstObservedAt:'2026-10-13T06:00:00Z',windowDate:'2026-10-13'}});
  const rows=[entry(us),nav,entry(ams,{previousClose:null}),entry(unknown),entry(xetra)];
  const original=structuredClone(rows),now=at('2026-10-13T10:00:00Z');
  assert.deepEqual(ids(selectHoldingSections(rows,now,'active').visibleEntries),[nav.holding.id,rows[4].holding.id]);
  for(const mode of ['all','purchase'])assert.deepEqual(ids(selectHoldingSections(rows,now,mode).visibleEntries),ids(rows));
  assert.deepEqual(selectHoldingSections(rows,at('2026-10-13T23:00:00Z'),'active').visibleEntries,[]);
  assert.deepEqual(rows,original);
});
