import test from 'node:test';
import assert from 'node:assert/strict';
import {isExchangeClosed} from '../public/lib/exchange-status.mjs';

const us = {type:'Stock',currency:'USD',mic:'XNAS',timeZone:'America/New_York',closeSchedule:'us_retained_calendar'};
const ams = {type:'Stock',currency:'EUR',mic:'XAMS',timeZone:'Europe/Amsterdam',closeSchedule:'ams_j0_continuous_2026'};
const xetra = {type:'ETF',currency:'EUR',mic:'XETR',timeZone:'Europe/Berlin',closeSchedule:'xetra_etf_regular_2026'};
const at = (instrument,instant,quote=null) => isExchangeClosed(instrument,Date.parse(instant),quote);

test('US state follows current regular-session boundaries, never the retained quote',()=>{
  for (const mic of ['XNAS','XNYS','XASE','ARCX','BATS']) {
    const instrument = {...us,mic};
    assert.equal(at(instrument,'2026-10-12T13:29:59Z'),true);
    assert.equal(at(instrument,'2026-10-12T13:30:00Z',{status:'closed',date:'2026-10-09'}),false);
    assert.equal(at(instrument,'2026-10-12T19:59:59Z'),false);
    assert.equal(at(instrument,'2026-10-12T20:00:00Z',{status:'delayed'}),true);
  }
});

test('US holidays and known early closes use the verified 2026 calendar',()=>{
  for (const day of ['01-01','01-19','02-16','04-03','05-25','06-19','07-03','09-07','11-26','12-25'])
    assert.equal(at(us,`2026-${day}T16:00:00Z`),true,day);
  for (const day of ['11-27','12-24']) {
    assert.equal(at(us,`2026-${day}T17:59:59Z`),false);
    assert.equal(at(us,`2026-${day}T18:00:00Z`),true);
  }
  assert.equal(at(us,'2026-07-02T18:00:00Z'),false,'July2 is a full session in2026');
  assert.equal(at(us,'2026-10-12T16:00:00Z'),false,'Columbus Day is not a stock-market holiday');
  assert.equal(at(us,'2026-11-11T16:00:00Z'),false,'Veterans Day is not a stock-market holiday');
});

test('DST changes are exchange-local, including weeks when US and Europe differ',()=>{
  for (const [before,open] of [
    ['2026-03-06T14:29:59Z','2026-03-06T14:30:00Z'],
    ['2026-03-09T13:29:59Z','2026-03-09T13:30:00Z'],
    ['2026-10-26T13:29:59Z','2026-10-26T13:30:00Z'],
    ['2026-11-02T14:29:59Z','2026-11-02T14:30:00Z'],
  ]) { assert.equal(at(us,before),true); assert.equal(at(us,open),false); }
  assert.equal(at(ams,'2026-10-23T07:00:00Z'),false);
  assert.equal(at(ams,'2026-10-26T07:59:59Z'),true);
  assert.equal(at(ams,'2026-10-26T08:00:00Z'),false);
  assert.equal(at(ams,'2026-10-23T15:40:00Z'),true);
  assert.equal(at(ams,'2026-10-26T15:40:00Z'),false);
  assert.equal(at(ams,'2026-10-26T16:40:00Z'),true);
});

test('reviewed Euronext groups include the closing auction and trading at last',()=>{
  const listings = [ams,
    {...ams,mic:'XBRU',timeZone:'Europe/Brussels',closeSchedule:'bru_a0_continuous_2026'},
    {...ams,mic:'XPAR',timeZone:'Europe/Paris',closeSchedule:'par_stock_continuous_2026'},
    {...ams,type:'ETF',closeSchedule:'ams_eur_etf_continuous_2026'},
    {...ams,type:'ETF',mic:'XPAR',timeZone:'Europe/Paris',closeSchedule:'par_eur_etf_continuous_2026'},
  ];
  for (const instrument of listings) {
    assert.equal(at(instrument,'2026-10-13T06:59:59Z'),true);
    assert.equal(at(instrument,'2026-10-13T07:00:00Z'),false);
    for (const time of ['15:30:00','15:35:00','15:39:59']) assert.equal(at(instrument,`2026-10-13T${time}Z`),false);
    assert.equal(at(instrument,'2026-10-13T15:40:00Z'),true);
  }
});

test('Xetra closes at the regular-session boundary without an unknown closing window',()=>{
  for (const mic of ['XETR','XETA']) {
    const instrument = {...xetra,mic};
    assert.equal(at(instrument,'2026-10-13T06:54:59Z'),true);
    assert.equal(at(instrument,'2026-10-13T06:55:00Z'),false,'opening call has started');
    assert.equal(at(instrument,'2026-10-13T15:29:59Z'),false);
    for (const time of ['15:30:00','15:35:00','15:59:59']) assert.equal(at(instrument,`2026-10-13T${time}Z`),true);
    assert.equal(at(instrument,'2026-10-13T16:00:00Z'),true);
    assert.equal(at(instrument,'2026-10-13T19:00:00Z'),true,'label excludes extended retail hours');
  }
  assert.equal(at({...xetra,type:'Stock'},'2026-10-13T22:00:00Z'),false,'unreviewed stock model stays unknown');
});

test('weekends, venue holidays and local-date rollover are distinct from old quote dates',()=>{
  for (const instrument of [us,ams,xetra]) for (const day of ['2026-10-10','2026-10-11'])
    assert.equal(at(instrument,`${day}T14:00:00Z`,{status:'delayed',date:'2026-10-09'}),true);
  assert.equal(at(ams,'2026-12-25T12:00:00Z'),true);
  for (const day of ['2026-12-24','2026-12-31']) assert.equal(at(xetra,`${day}T12:00:00Z`),true);
  assert.equal(at(xetra,'2026-05-14T12:00:00Z'),false,'Ascension is a trading day');
  assert.equal(at(xetra,'2026-05-25T12:00:00Z'),false,'Whit Monday is a trading day');
  assert.equal(at(us,'2027-01-01T02:00:00Z'),true,'New York is still inside the reviewed2026 year');
  assert.equal(at(ams,'2026-12-31T23:00:00Z'),false,'Amsterdam has entered unreviewed2027');
});

test('unreviewed special sessions and dates do not produce a Closed claim',()=>{
  for (const day of ['2026-12-24','2026-12-31']) for (const hour of ['02','12','21'])
    assert.equal(at(ams,`${day}T${hour}:00:00Z`),false);
  for (const hour of ['02','12','21']) assert.equal(at(xetra,`2026-12-30T${hour}:00:00Z`),false);
  for (const instrument of [us,ams,xetra]) for (const day of ['2025-10-11','2028-10-10'])
    assert.equal(at(instrument,`${day}T14:00:00Z`),false);
  assert.equal(at(ams,'2026-08-02T12:00:00Z'),false,'before the reviewed Euronext schedule begins');
  assert.equal(at(ams,'2026-08-03T06:00:00Z'),true);
});

test('funds, incomplete identity, unsupported listings and invalid clocks stay unlabelled',()=>{
  for (const instrument of [null,undefined,{},
    {...us,type:'Fund'},{...us,type:'Option'},{...us,mic:'XUNK'},
    {...us,currency:'EUR'},{...us,timeZone:'UTC'},{...us,closeSchedule:undefined},
    {...ams,closeSchedule:'unknown'},{...ams,mic:'XPAR'},{...ams,type:'ETF'},
    {...ams,currency:'USD'},{...ams,timeZone:'Europe/Paris'},
    {...xetra,mic:'XFRA'},
  ]) assert.equal(at(instrument,'2026-10-11T22:00:00Z',{status:'closed'}),false);
  for (const invalid of [NaN,Infinity,-Infinity,null,'2026-10-13',1e20]) assert.equal(isExchangeClosed(us,invalid),false);
});

test('the helper is independent of quote state and does not mutate its inputs',()=>{
  const instrument=Object.freeze({...us});
  const quote=Object.freeze({status:'closed',date:'2026-10-09',priceKind:'close',observedAt:'2026-10-09T20:00:00Z'});
  assert.equal(at(instrument,'2026-10-12T13:31:00Z',quote),false);
  assert.equal(at(instrument,'2026-10-12T20:01:00Z',Object.freeze({status:'missing'})),true);
  assert.equal(quote.date,'2026-10-09');
});
