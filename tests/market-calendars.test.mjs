import test from 'node:test';
import assert from 'node:assert/strict';
import {calendarDay,normalizeUSSessionCalendar,retainedUSSession} from '../public/lib/market-calendars.mjs';
import {getExchangeSession,selectOpenMarketEntries} from '../public/lib/exchange-status.mjs';
import {approximateClosingInstant} from '../public/lib/closing-times.mjs';
import {getFundWindowState} from '../public/lib/fund-contribution.mjs';
const us={type:'Stock',currency:'USD',mic:'XNAS',timeZone:'America/New_York',closeSchedule:'us_retained_calendar'};
const xetra={type:'ETF',currency:'EUR',mic:'XETA',timeZone:'Europe/Berlin',closeSchedule:'xetra_etf_regular_2026'};
const calendar=()=>({source:'alpaca_calendar',timeZone:'America/New_York',from:'2027-03-01',through:'2027-03-22',checkedAt:'2027-03-15T14:00:00Z',sessions:[{date:'2027-03-12',openAt:'2027-03-12T14:30:00Z',closeAt:'2027-03-12T21:00:00Z'},{date:'2027-03-15',openAt:'2027-03-15T13:30:00Z',closeAt:'2027-03-15T20:00:00Z'}]});
const state=(i,at,q)=>getExchangeSession(i,Date.parse(at),q).state;
test('verified 2027 venue closures do not leak to unverified venues or years',()=>{
  for(const date of ['2027-01-01','2027-03-26','2027-03-29','2027-12-24','2027-12-31'])assert.equal(calendarDay('XETA',date).isSession,false);
  assert.equal(calendarDay('XETA','2027-12-27').isSession,true,'no invented observed Christmas holiday');
  assert.equal(calendarDay('XNYS','2027-06-18').isSession,false);
  assert.equal(calendarDay('XNYS','2027-11-26').closeMinute,780);
  for(const mic of ['XNAS','BATS','XAMS','XPAR','XBRU'])assert.equal(calendarDay(mic,'2027-01-04'),null);
  for(const bad of ['2027-02-29','2028-01-04','not-a-date'])assert.equal(calendarDay('XETA',bad),null);
});
test('2027 exchange-local boundaries survive different US and European DST weeks',()=>{
  const nyse={...us,mic:'XNYS'};
  for(const [i,before,open] of [[nyse,'2027-03-12T14:29:59Z','2027-03-12T14:30:00Z'],[nyse,'2027-03-15T13:29:59Z','2027-03-15T13:30:00Z'],[xetra,'2027-03-25T07:54:59Z','2027-03-25T07:55:00Z'],[xetra,'2027-03-30T06:54:59Z','2027-03-30T06:55:00Z'],[xetra,'2027-11-01T07:54:59Z','2027-11-01T07:55:00Z']]){
    assert.equal(state(i,before),'closed');assert.equal(state(i,open),'open');
  }
  assert.equal(state(nyse,'2027-11-26T17:59:59Z'),'open');assert.equal(state(nyse,'2027-11-26T18:00:00Z'),'closed');
  assert.equal(state(xetra,'2027-12-30T12:00:00Z'),'unknown','last session special hours await review');
  assert.equal(approximateClosingInstant(xetra,'2027-03-30',Date.parse('2027-03-31T00:00:00Z')),'2027-03-30T15:35:00.000Z');
  assert.equal(approximateClosingInstant(xetra,'2027-12-30',Date.parse('2028-01-01T00:00:00Z')),null);
});
test('retained authenticated session dates extend NASDAQ without extra calendar requests',()=>{
  const raw=calendar(),before=JSON.stringify(raw),q={sessionCalendar:raw};
  assert.equal(state(us,'2027-03-15T14:01:00Z',q),'open');
  assert.equal(state(us,'2027-03-15T20:00:00Z',q),'closed');
  assert.equal(state(us,'2027-03-16T16:00:00Z',q),'closed','absence inside successful complete calendar query is a closed day');
  assert.equal(state(us,'2027-03-23T16:00:00Z',q),'unknown','not extrapolated beyond requested range or freshness');
  assert.equal(state(us,'2027-03-15T14:01:00Z'),'unknown','older API responses remain compatible and safely unknown');
  assert.equal(JSON.stringify(raw),before);
  assert.equal(retainedUSSession(raw,'2027-03-15',Date.parse('2027-03-15T13:59:59Z')),null,'future receipt not trusted');
  assert.equal(retainedUSSession(raw,'2027-03-15',Date.parse('2027-03-23T14:00:00Z')),null,'expired receipt not restamped');
});
test('malformed, mismatched and oversized optional calendar metadata is rejected',()=>{
  const changes=[r=>r.source='other',r=>r.timeZone='UTC',r=>r.from='2027-02-29',r=>r.from='2026-01-01',r=>r.sessions=[],r=>r.sessions.push({...r.sessions[0]}),r=>r.sessions[0].date='2027-03-13',r=>r.sessions[0].openAt='2027-03-12T13:30:00Z',r=>r.sessions[0].closeAt='2027-03-15T20:00:00Z',r=>r.checkedAt='2027-03-15T14:00:00+00:00',r=>r.through='2027-03-14'];
  for(const change of changes){const r=calendar();change(r);assert.equal(normalizeUSSessionCalendar(r),null);}
});
test('current quote admission uses its session even when calendar was received later',()=>{
  const q={sessionCalendar:calendar(),date:'2027-03-15',observedAt:'2027-03-15T13:39:00Z',priceKind:'intraday',previousClose:100,previousFx:1};
  const entry={holding:{instrumentId:'fictional-us'},instrument:us,quote:q};
  const now=Date.parse('2027-03-15T14:01:00Z');
  assert.deepEqual(selectOpenMarketEntries([entry],now).waitingInstrumentIds,[]);
  for(const observedAt of ['2027-03-15T13:29:00Z','2027-03-12T14:39:00Z'])assert.deepEqual(selectOpenMarketEntries([{...entry,quote:{...q,observedAt}}],now).waitingInstrumentIds,['fictional-us']);
  assert.equal(q.previousClose,100,'readiness selection does not edit stored baseline');
});
test('BND is still an explicit weekday reporting window, with no 2026 expiry',()=>{
  const fund={type:'Fund',currency:'EUR',contributionSchedule:'bnd_daily_amsterdam'};
  assert.equal(getFundWindowState(fund,Date.parse('2027-01-04T07:59:59Z')),'closed');
  assert.equal(getFundWindowState(fund,Date.parse('2027-01-04T08:00:00Z')),'open');
  assert.equal(getFundWindowState(fund,Date.parse('2027-07-05T15:30:00Z')),'closed');
  assert.equal(getFundWindowState(fund,Date.parse('2027-07-03T10:00:00Z')),'closed');
});
