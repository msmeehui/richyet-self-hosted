import test from 'node:test';
import assert from 'node:assert/strict';
import {approximateClosingInstant, formatApproximateClosingTime} from '../public/lib/closing-times.mjs';
import {latestComparisonDates, priceDateMessage, normalizeMarketSnapshot} from '../public/lib/market.mjs';
import {calculateHolding} from '../public/lib/portfolio.mjs';

const end = Date.parse('2027-01-01T12:00:00Z');
const ams = {id:'example',type:'Stock',currency:'EUR',mic:'XAMS',timeZone:'Europe/Amsterdam',closeSchedule:'ams_j0_continuous_2026'};
const xetra = {...ams,type:'ETF',mic:'XETR',timeZone:'Europe/Berlin',closeSchedule:'xetra_etf_regular_2026'};
const us = {...ams,currency:'USD',mic:'XNAS',timeZone:'America/New_York',closeSchedule:'us_retained_calendar'};
const q = {price:'110',previousClose:'100',date:'2026-10-12',previousDate:'2026-10-09',observedAt:null,previousObservedAt:null,
  priceKind:'close',previousKind:'close',status:'closed',fx:'1',previousFx:'1',basis:'same_units'};

test('reviewed European continuous groups use their approximate auction close, including both ETF routes',()=>{
  for (const i of [ams,
    {...ams,type:'ETF',closeSchedule:'ams_eur_etf_continuous_2026'},
    {...ams,mic:'XBRU',timeZone:'Europe/Brussels',closeSchedule:'bru_a0_continuous_2026'},
    {...ams,mic:'XPAR',timeZone:'Europe/Paris',closeSchedule:'par_stock_continuous_2026'},
    {...ams,type:'ETF',mic:'XPAR',timeZone:'Europe/Paris',closeSchedule:'par_eur_etf_continuous_2026'},xetra]) {
    assert.equal(approximateClosingInstant(i,'2026-10-12',end),'2026-10-12T15:35:00.000Z');
    assert.match(priceDateMessage(i,q,end),/Closing price as of 12 Oct 2026, ~\d{2}:35/);
    assert.match(latestComparisonDates(i,q,end).detail,/Compared with 9 Oct 2026, ~\d{2}:35/);
  }
});

test('the original XETA ETF keeps its exact identity and observation while gaining the reviewed comparison time',()=>{
  const i={...xetra,mic:'XETA'}, saved=structuredClone(i);
  assert.equal(approximateClosingInstant(i,'2026-10-12',end),'2026-10-12T15:35:00.000Z');
  assert.match(priceDateMessage(i,q,end),/Closing price as of 12 Oct 2026, ~\d{2}:35$/);
  const actual={...q,priceKind:'trade',observedAt:'2026-10-12T15:36:09Z'};
  assert.match(priceDateMessage(i,actual,end),/^Price as of 12 Oct 2026, \d{2}:36$/);
  assert.doesNotMatch(priceDateMessage(i,actual,end),/~/);
  assert.match(latestComparisonDates(i,actual,end).detail,/Compared with 9 Oct 2026, ~\d{2}:35$/);
  assert.deepEqual(i,saved);assert.equal(actual.observedAt,'2026-10-12T15:36:09Z');
  for(const change of [{type:'Stock'},{type:'Fund'},{mic:'XUNK'},{closeSchedule:undefined}])
    assert.equal(approximateClosingInstant({...i,...change},q.date,end),null);
});

test('exchange daylight saving changes are applied before formatting in the device timezone',()=>{
  assert.equal(approximateClosingInstant(xetra,'2026-03-27',end),'2026-03-27T16:35:00.000Z');
  assert.equal(approximateClosingInstant(xetra,'2026-03-30',end),'2026-03-30T15:35:00.000Z');
  assert.equal(approximateClosingInstant(ams,'2026-10-23',end),'2026-10-23T15:35:00.000Z');
  const after=approximateClosingInstant(ams,'2026-10-26',end);
  assert.equal(after,'2026-10-26T16:35:00.000Z');
  assert.equal(formatApproximateClosingTime(after,'Europe/Amsterdam'),'26 Oct 2026, ~17:35');
  assert.equal(formatApproximateClosingTime(after,'America/New_York'),'26 Oct 2026, ~12:35');
  assert.equal(formatApproximateClosingTime(after,'Pacific/Kiritimati'),'27 Oct 2026, ~06:35');
});

test('calendar limits, unknown special sessions and mismatched listings never acquire a made-up closing time',()=>{
  for (const day of ['2025-12-29','2027-01-04','2026-02-30','2026-01-01','2026-04-03','2026-04-06','2026-05-01',
    '2026-10-10','2026-12-24','2026-12-25','2026-12-26','2026-12-30','2026-12-31'])
    assert.equal(approximateClosingInstant(xetra,day,end),null,day);
  assert.equal(approximateClosingInstant(ams,'2026-07-31',end),null,'prior to reviewed manual effective date');
  assert.equal(approximateClosingInstant(ams,'2026-12-30',end),'2026-12-30T16:35:00.000Z','Euronext is a full day');
  for(const change of [{closeSchedule:null},{closeSchedule:'guess'},{mic:'XPAR'},{type:'ETF'},{currency:'USD'},{timeZone:'America/New_York'}]){
    const i={...ams,...change};assert.equal(approximateClosingInstant(i,q.date,end),null);
    assert.equal(priceDateMessage(i,q,end),'Closing price dated 12 Oct 2026');
    assert.equal(latestComparisonDates(i,q,end).detail,'Compared with 9 Oct 2026');
  }
  assert.equal(approximateClosingInstant({...xetra,type:'Stock'},q.date,end),null,'unknown one-auction stock model');
});

test('a closing date cannot gain a future scheduled time or borrow the collector timestamp',()=>{
  const now=Date.parse('2026-10-12T15:34:59Z');
  assert.equal(priceDateMessage(ams,{...q,retrievedAt:'2026-10-12T18:00:00Z'},now),'Closing price dated 12 Oct 2026');
  assert.equal(approximateClosingInstant(ams,q.date,now),null);
  assert.equal(approximateClosingInstant(ams,q.date,now+1000),'2026-10-12T15:35:00.000Z');
  assert.match(latestComparisonDates(ams,q,now).detail,/9 Oct 2026, ~\d{2}:35$/,'the genuinely previous session remains available');
});

test('exact European trade and auction observations take precedence over scheduled approximations',()=>{
  const exact={...q,observedAt:'2026-10-12T15:36:17Z',previousObservedAt:'2026-10-09T15:35:28Z'};
  assert.match(priceDateMessage(xetra,exact,end),/Closing price as of 12 Oct 2026, \d{2}:36$/);
  assert.doesNotMatch(priceDateMessage(xetra,exact,end),/~|approx/);
  assert.match(latestComparisonDates(xetra,exact,end).detail,/9 Oct 2026, \d{2}:35$/);
  assert.doesNotMatch(latestComparisonDates(xetra,exact,end).detail,/~|approx/);
  assert.match(priceDateMessage(ams,{...exact,priceKind:'trade',observationKind:'minute'},end),/^Price as of .*:36$/);
});

test('US retained calendar closes are approximate, retaining early-close and US versus European DST differences',()=>{
  for (const [day,at,local] of [
    ['2026-10-12','2026-10-12T20:00:00Z','12 Oct 2026, ~22:00'],
    ['2026-10-26','2026-10-26T20:00:00Z','26 Oct 2026, ~21:00'],
    ['2026-11-02','2026-11-02T21:00:00Z','2 Nov 2026, ~22:00'],
    ['2026-11-27','2026-11-27T18:00:00Z','27 Nov 2026, ~19:00'],
  ]) {
    assert.equal(formatApproximateClosingTime(at,'Europe/Amsterdam'),local);
    assert.match(priceDateMessage(us,{...q,date:day,observedAt:at}),/~\d{2}:00$/);
  }
  const current={...q,observedAt:'2026-10-12T20:00:00Z',previousObservedAt:'2026-10-09T20:00:00Z'};
  assert.match(latestComparisonDates(us,current,end).detail,/9 Oct 2026, ~\d{2}:00$/);
  assert.doesNotMatch(priceDateMessage(us,{...current,priceKind:'trade',observationKind:'minute',observedAt:'2026-10-12T18:16:00Z'}),/~|approx/);
  assert.equal(priceDateMessage(us,{...current,observedAt:'2026-10-09T20:00:00Z'}),'Closing price dated 12 Oct 2026');
});

test('fund valuations and unavailable dates stay date-only',()=>{
  const fund={...ams,type:'Fund'};
  assert.equal(priceDateMessage(fund,{...q,observedAt:'2026-10-12T15:35:00Z'}),'Fund value dated 12 Oct 2026');
  assert.equal(latestComparisonDates(fund,{...q,previousObservedAt:'2026-10-09T15:35:00Z'},end).detail,'Compared with 9 Oct 2026');
  assert.equal(priceDateMessage(ams,{...q,date:null},end),'Price unavailable');
  assert.equal(priceDateMessage(ams,{...q,price:null},end),'Price unavailable');
});

test('approximate labels leave financial inputs, normalization, freshness and arithmetic unchanged',()=>{
  const holding={id:'example-holding',instrumentId:ams.id,quantity:'2',averageCost:'80',eurCost:null};
  const saved=structuredClone(q), before=calculateHolding(holding,ams,q);
  const normalized=normalizeMarketSnapshot({dataMode:'live',quotes:{[ams.id]:q},issues:[]},[ams]);
  priceDateMessage(ams,q,end);latestComparisonDates(ams,q,end);
  assert.deepEqual(q,saved);assert.equal(q.observedAt,null);assert.equal(q.previousObservedAt,null);
  assert.deepEqual(calculateHolding(holding,ams,q),before);
  assert.equal(normalized.quotes[ams.id].observedAt,null);assert.equal(normalized.quotes[ams.id].status,'closed');
});
