import test from 'node:test';
import assert from 'node:assert/strict';
import {getExchangeSession, isExchangeClosed, selectOpenMarketEntries} from '../public/lib/exchange-status.mjs';
import {calculateLatestSubtotal, calculatePortfolio} from '../public/lib/portfolio.mjs';

const us = {id:'fictional-us',type:'Stock',currency:'USD',mic:'XNAS',timeZone:'America/New_York',closeSchedule:'us_retained_calendar'};
const ams = {id:'fictional-eu',type:'Stock',currency:'EUR',mic:'XAMS',timeZone:'Europe/Amsterdam',closeSchedule:'ams_j0_continuous_2026'};
const xetra = {id:'fictional-etf',type:'ETF',currency:'EUR',mic:'XETA',timeZone:'Europe/Berlin',closeSchedule:'xetra_etf_regular_2026'};
const fund = {id:'fictional-fund',type:'Fund',currency:'EUR',mic:null};
const at = Date.parse;
function entry(instrument, changes = {}) {
  return {holding:{id:`holding-${instrument.id}`,instrumentId:instrument.id,quantity:'10',averageCost:'90'},instrument,
    quote:{status:'delayed',price:'102',previousClose:'100',date:'2026-10-13',previousDate:'2026-10-12',
      observedAt:'2026-10-13T09:00:00Z',priceKind:'trade',fx:'1',previousFx:'1',...changes}};
}

test('explicit states distinguish scheduled open, closed, unknown and daily fund',()=>{
  assert.deepEqual(getExchangeSession(ams,at('2026-10-13T10:00:00Z')),{state:'open',date:'2026-10-13'});
  assert.deepEqual(getExchangeSession(us,at('2026-10-13T10:00:00Z')),{state:'closed',date:'2026-10-13'});
  assert.deepEqual(getExchangeSession({...ams,closeSchedule:'unknown'},at('2026-10-13T10:00:00Z')),{state:'unknown',date:null});
  assert.deepEqual(getExchangeSession(fund,at('2026-10-13T10:00:00Z')),{state:'fund',date:null});
  assert.equal(isExchangeClosed(fund,at('2026-10-13T10:00:00Z')),false);
  for(const now of [NaN,Infinity,at('2027-10-13T10:00:00Z')]) assert.equal(getExchangeSession(ams,now).state,'unknown');
});

test('Xetra closing publication remains Closed and does not make the open subtotal partial',()=>{
  assert.equal(getExchangeSession(xetra,at('2026-10-13T15:29:59Z')).state,'open');
  for(const instant of ['2026-10-13T15:30:00Z','2026-10-13T15:59:59Z']) {
    assert.equal(getExchangeSession(xetra,at(instant)).state,'closed');
    assert.equal(isExchangeClosed(xetra,at(instant)),true);
    const selected=selectOpenMarketEntries([entry(xetra),entry(us,{observedAt:'2026-10-13T15:00:00Z'})],at(instant));
    assert.equal(selected.unknownCount,0);
    assert.equal(selected.openCount,1);
    assert.equal(calculateLatestSubtotal(selected.comparisonEntries).partial,false);
  }
  assert.equal(getExchangeSession(xetra,at('2026-10-13T16:00:00Z')).state,'closed');
});

test('open selection respects exchange-local DST, holidays and verified early closes',()=>{
  assert.equal(getExchangeSession(us,at('2026-10-26T13:30:00Z')).state,'open');
  assert.equal(getExchangeSession(us,at('2026-11-02T13:30:00Z')).state,'closed');
  assert.equal(getExchangeSession(us,at('2026-11-02T14:30:00Z')).state,'open');
  assert.equal(getExchangeSession(us,at('2026-11-26T16:00:00Z')).state,'closed');
  assert.equal(getExchangeSession(us,at('2026-11-27T17:59:59Z')).state,'open');
  assert.equal(getExchangeSession(us,at('2026-11-27T18:00:00Z')).state,'closed');
  assert.equal(getExchangeSession(ams,at('2026-12-25T12:00:00Z')).state,'closed');
  assert.equal(getExchangeSession(ams,at('2026-12-24T12:00:00Z')).state,'unknown');
});

test('Monday opening waits for an actual current-session price instead of reusing Friday movement',()=>{
  const row=entry(ams,{date:'2026-10-09',previousDate:'2026-10-08',observedAt:'2026-10-09T15:00:00Z'});
  const selection=selectOpenMarketEntries([row],at('2026-10-12T07:01:00Z'));
  assert.equal(selection.openCount,1);
  assert.equal(selection.comparisonEntries.length,1);
  assert.deepEqual(selection.waitingInstrumentIds,[ams.id]);
  assert.equal(calculateLatestSubtotal(selection.comparisonEntries).change,null);
  assert.equal(calculatePortfolio([row]).change,20,'ordinary Latest and full valuation are not rewritten');
  assert.equal(row.quote.previousClose,'100');
});

test('a current US minute can contribute once available while retained close/date-only values wait',()=>{
  const now=at('2026-10-13T14:00:00Z');
  const live=entry(us,{observedAt:'2026-10-13T13:39:00Z',observationKind:'minute_bar'});
  const selection=selectOpenMarketEntries([live],now);
  assert.equal(selection.openCount,1);
  assert.deepEqual(selection.waitingInstrumentIds,[]);
  assert.equal(selection.comparisonEntries[0],live);
  assert.equal(calculateLatestSubtotal(selection.comparisonEntries).change,20);
  for(const changes of [{observedAt:null},{priceKind:'close'},{observationKind:'close'}]) {
    const waiting=selectOpenMarketEntries([entry(us,{observedAt:'2026-10-13T13:39:00Z',...changes})],now);
    assert.deepEqual(waiting.waitingInstrumentIds,[us.id]);
    assert.equal(calculateLatestSubtotal(waiting.comparisonEntries).change,null);
  }
});

test('future, mismatched, malformed and outside-session timestamps cannot establish current eligibility',()=>{
  const now=at('2026-10-13T14:00:00Z');
  for(const changes of [
    {observedAt:'2026-10-13T14:01:00Z'},
    {observedAt:'2026-10-12T14:00:00Z'},
    {date:'2026-10-12',observedAt:'2026-10-13T13:40:00Z'},
    {observedAt:'2026-10-13T12:00:00Z'}, // US premarket is outside this metric.
    {observedAt:'2026-10-13'}, {observedAt:'not-a-date'},
    {observedAt:null,retrievedAt:'2026-10-13T13:40:00Z'},
  ]) {
    const selection=selectOpenMarketEntries([entry(us,changes)],now);
    assert.equal(selection.openCount,1);
    assert.deepEqual(selection.waitingInstrumentIds,[us.id]);
    assert.equal(calculateLatestSubtotal(selection.comparisonEntries).includedCount,0);
  }
});

test('missing comparisons within open markets remain counted for a labelled partial result',()=>{
  const rows=[entry(ams),entry({...ams,id:'fictional-waiting'},{observedAt:null}),
    entry({...ams,id:'fictional-no-baseline'},{previousClose:null}),entry(us),entry(fund),
    entry({...ams,id:'fictional-unknown',closeSchedule:'unknown'})];
  const selection=selectOpenMarketEntries(rows,at('2026-10-13T10:00:00Z'));
  assert.equal(selection.openCount,3);
  assert.equal(selection.closedCount,1);
  assert.equal(selection.fundCount,1);
  assert.equal(selection.unknownCount,1);
  const subtotal=calculateLatestSubtotal(selection.comparisonEntries);
  assert.equal(subtotal.includedCount,1);
  assert.equal(subtotal.totalCount,3);
  assert.equal(subtotal.change,20);
  assert.equal(subtotal.changePercent,2);
  assert.equal(subtotal.partial,true);
  assert.deepEqual(subtotal.missingInstrumentIds,['fictional-waiting','fictional-no-baseline']);
});

test('all-closed, fund-only and unknown-only inputs remain distinguishable without fabricating zero',()=>{
  const now=at('2026-10-11T12:00:00Z');
  const closed=selectOpenMarketEntries([entry(ams),entry(us),entry(fund)],now);
  assert.equal(closed.openCount,0);assert.equal(closed.closedCount,2);assert.equal(closed.fundCount,1);assert.equal(closed.unknownCount,0);
  const onlyFund=selectOpenMarketEntries([entry(fund)],now);
  assert.equal(onlyFund.closedCount,0);assert.equal(onlyFund.fundCount,1);
  const unknown=selectOpenMarketEntries([entry({...ams,closeSchedule:'unknown'})],now);
  assert.equal(unknown.unknownCount,1);assert.equal(unknown.closedCount,0);
  for(const result of [closed,onlyFund,unknown]) assert.equal(calculateLatestSubtotal(result.comparisonEntries).change,null);
});

test('selection preserves saved inputs and all-portfolio calculations while excluding only chosen markets',()=>{
  const rows=[entry(ams),entry(us),entry(fund),entry({...ams,id:'fictional-prior'},{observedAt:null})];
  const saved=structuredClone(rows),full=calculatePortfolio(rows);
  const freeze=value=>{if(value&&typeof value==='object'){Object.values(value).forEach(freeze);Object.freeze(value);}};
  freeze(rows);
  const selection=selectOpenMarketEntries(rows,at('2026-10-13T10:00:00Z'));
  assert.deepEqual(rows,saved);
  assert.deepEqual(calculatePortfolio(rows),full);
  assert.notEqual(selection.comparisonEntries[1],rows[3]);
  assert.equal(selection.comparisonEntries[0],rows[0]);
  assert.throws(()=>selectOpenMarketEntries(null),TypeError);
});
