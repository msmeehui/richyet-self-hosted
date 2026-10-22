import test from 'node:test';
import assert from 'node:assert/strict';
import {awaitingDataReason} from '../public/lib/awaiting-data.mjs';
import {selectComparisonEntries} from '../public/lib/comparison-modes.mjs';

const now=Date.parse('2026-10-16T08:00:00Z');
const entry={holding:{id:'fictional-position',instrumentId:'fictional-oak',quantity:'2',averageCost:'80'},
  instrument:{id:'fictional-oak',type:'Stock',currency:'EUR',mic:'XAMS',timeZone:'Europe/Amsterdam',closeSchedule:'ams_j0_continuous_2026'},
  quote:{price:'105',previousClose:'100',date:'2026-10-16',previousDate:'2026-10-15',observedAt:'2026-10-16T07:45:00Z',priceKind:'trade',status:'delayed'}};
function explanation(row, at = now) {
  const s=selectComparisonEntries([row],'active',at);
  assert.equal(s.awaitingEntries.length,1);
  return awaitingDataReason(s.awaitingEntries[0],s.openMarkets.waitingInstrumentIds.includes(row.instrument.id));
}

test('a retained close before the first current-session price is explained without claiming the close is missing',()=>{
  const row=structuredClone(entry);row.quote={...row.quote,date:'2026-10-15',previousDate:'2026-10-14',observedAt:null,priceKind:'close',status:'closed'};
  const before=structuredClone(row),text=explanation(row);
  assert.match(text,/today’s first price update/);assert.doesNotMatch(text,/closing price.*not available/);
  assert.deepEqual(row,before);
});

test('a received current price distinguishes a missing close from missing currency information',()=>{
  const close=structuredClone(entry);close.quote.previousClose=null;
  assert.match(explanation(close),/Today’s price has arrived.*previous closing price/);
  const fx=structuredClone(entry);Object.assign(fx.instrument,{currency:'USD',mic:'XNAS',timeZone:'America/New_York',closeSchedule:'us_retained_calendar'});fx.quote.observedAt='2026-10-16T13:45:00Z';fx.quote.fx='0.9';fx.quote.previousFx=null;
  const usOpen=Date.parse('2026-10-16T14:00:00Z');
  assert.match(explanation(fx,usOpen),/Today’s price has arrived.*Currency information/);
  assert.doesNotMatch(explanation(fx,usOpen),/first price update/);
  const zero=structuredClone(entry);zero.quote.previousClose='0';
  assert.match(explanation(zero),/comparison is not ready/);
});
