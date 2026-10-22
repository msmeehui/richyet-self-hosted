import test from 'node:test';
import assert from 'node:assert/strict';
import {calculateHolding, calculatePortfolio, calculateLatestSubtotal, formatMoney} from '../public/lib/portfolio.mjs';
import {normalizeMarketSnapshot} from '../public/lib/market.mjs';

// Fictional holdings only. Each endpoint stays in exact decimal text.
function entry(id, options = {}) {
  const {currency = 'EUR', quantity = '10', price = '102', previousClose = '100',
    fx = null, previousFx = null, date = '2026-10-13', previousDate = '2026-10-12',
    status = 'delayed', averageCost = '90'} = options;
  return {
    holding: {id:`holding-${id}`, instrumentId:id, quantity, averageCost},
    instrument: {id, name:'Fictional investment', currency, type:'Stock'},
    quote: {price, previousClose, fx, previousFx, date, previousDate, status, observedAt:`${date}T10:00:00Z`},
  };
}
const close = (actual, expected) => assert.ok(Math.abs(actual - expected) < 1e-12);

test('complete Latest subtotal agrees with full mixed-currency calculation and ignores purchase costs', () => {
  const rows = [entry('oak', {averageCost:null}), entry('elm', {
    currency:'USD', quantity:'5', price:'104', fx:'0.90', previousFx:'0.92', averageCost:null,
  })];
  const subtotal = calculateLatestSubtotal(rows), full = calculatePortfolio(rows);
  for (const field of ['value','baseline','change','changePercent']) assert.equal(subtotal[field], full[field]);
  assert.deepEqual(subtotal, {value:1488, baseline:1460, change:28, changePercent:140/73,
    includedCount:2, totalCount:2, missingInstrumentIds:[], partial:false, stale:false});
  assert.equal(full.purchaseGain, null);
});

test('partial Latest uses the same included subset for amount and weighted percentage', () => {
  const rows = [entry('oak'), entry('elm', {currency:'USD', quantity:'5', price:'104', fx:'0.90', previousFx:'0.92'}),
    entry('birch', {quantity:'1', price:'5000', previousClose:null})];
  const subtotal = calculateLatestSubtotal(rows), full = calculatePortfolio(rows);
  assert.equal(subtotal.value, 1488);
  assert.equal(subtotal.baseline, 1460);
  assert.equal(subtotal.change, 28);
  close(subtotal.changePercent, 28/1460*100);
  assert.notEqual(subtotal.changePercent, 28/full.value*100);
  assert.notEqual(subtotal.changePercent, (2 + 8/460*100)/2);
  assert.deepEqual(subtotal.missingInstrumentIds, ['birch']);
  assert.equal(subtotal.includedCount, 2);
  assert.equal(subtotal.totalCount, 3);
  assert.equal(subtotal.partial, true);
  assert.equal(full.value, 6488);
  assert.equal(full.change, null);
  assert.equal(full.changePercent, null);
  assert.equal(full.purchaseGain, 5093); // 6488 minus exact costs 900 + 405 + 90.
});

test('missing prices, dated baselines, currency endpoints and identity never become zero contributions', () => {
  const missing = [entry('price',{price:null}), entry('date',{previousDate:null}),
    entry('same-day',{previousDate:'2026-10-13'}), entry('current-fx',{currency:'USD',fx:null,previousFx:'0.9'}),
    entry('prior-fx',{currency:'USD',fx:'0.9',previousFx:null}), entry('status',{status:'unavailable'}), entry('identity')];
  missing.at(-1).instrument.id = 'other-listing';
  const subtotal = calculateLatestSubtotal([entry('good'), ...missing]);
  assert.equal(subtotal.change, 20);
  assert.equal(subtotal.changePercent, 2);
  assert.equal(subtotal.includedCount, 1);
  assert.equal(subtotal.totalCount, 8);
  assert.deepEqual(subtotal.missingInstrumentIds, missing.map(row=>row.holding.instrumentId));
});

test('no usable comparisons and empty input are unavailable, distinct from genuine zero movement', () => {
  for (const rows of [[], [entry('missing',{previousClose:null})], [null]]) {
    const subtotal = calculateLatestSubtotal(rows);
    for (const field of ['value','baseline','change','changePercent']) assert.equal(subtotal[field], null);
    assert.equal(subtotal.includedCount, 0);
    assert.equal(subtotal.totalCount, rows.length);
    assert.equal(subtotal.partial, false);
  }
  assert.deepEqual(calculateLatestSubtotal([entry('flat',{price:'100'}),entry('missing',{price:null})]), {
    value:1000, baseline:1000, change:0, changePercent:0, includedCount:1, totalCount:2,
    missingInstrumentIds:['missing'], partial:true, stale:false,
  });
  assert.throws(()=>calculateLatestSubtotal(null), TypeError);
});

test('zero prices and zero denominators keep their existing distinct arithmetic meanings', () => {
  const zeroPrice = calculateLatestSubtotal([entry('zero',{price:'0'}),entry('missing',{previousClose:null})]);
  assert.equal(zeroPrice.change, -1000);
  assert.equal(zeroPrice.changePercent, -100);
  const zeroBaseline = calculateLatestSubtotal([entry('zero',{previousClose:'0'})]);
  assert.equal(zeroBaseline.change, 1020);
  assert.equal(zeroBaseline.changePercent, null);
  assert.equal(zeroBaseline.includedCount, 1);
});

test('closed, NAV and stale usable comparisons retain their dated semantics without filtering markets', () => {
  const rows = [entry('closed',{status:'closed',date:'2026-10-12',previousDate:'2026-10-09'}),
    entry('fund',{status:'nav',date:'2026-10-09',previousDate:'2026-10-08'}), entry('stale',{status:'stale'})];
  rows[1].instrument.type = 'Fund';
  const subtotal = calculateLatestSubtotal(rows);
  assert.equal(subtotal.change, 60);
  assert.equal(subtotal.changePercent, 2);
  assert.equal(subtotal.includedCount, 3);
  assert.equal(subtotal.partial, false);
  assert.equal(subtotal.stale, true);
  assert.equal(calculateLatestSubtotal([entry('good'),entry('missing-stale',{status:'stale',price:null})]).stale, false);
});

test('explicit adapter invalidations remain excluded after normal snapshot normalization', () => {
  const rows = [entry('good'),entry('cancelled'),entry('split'),entry('listing')];
  const quotes = Object.fromEntries(rows.map(row=>[row.instrument.id,row.quote]));
  quotes.cancelled.invalidations = ['comparison'];
  quotes.split.invalidations = ['current'];
  quotes.listing.invalidations = ['listing'];
  const normalized = normalizeMarketSnapshot({dataMode:'live',quotes,issues:[]},rows.map(row=>row.instrument));
  const subtotal = calculateLatestSubtotal(rows.map(row=>({...row,quote:normalized.quotes[row.instrument.id]})));
  assert.equal(subtotal.includedCount, 1);
  assert.equal(subtotal.change, 20);
  assert.deepEqual(subtotal.missingInstrumentIds, ['cancelled','split','listing']);
});

test('subtotal aggregates exact cents and rational FX before Number conversion or display rounding', () => {
  const large = calculateLatestSubtotal([
    entry('gain',{quantity:'1',price:'10000000000000000.02',previousClose:'10000000000000000'}),
    entry('loss',{quantity:'1',price:'10000000000000000',previousClose:'10000000000000000.01'}),
    entry('missing',{price:null}),
  ]);
  assert.equal(large.value - large.baseline, 0);
  assert.equal(large.change, 0.01);
  assert.equal(large.changePercent, 5e-17);
  const converted = calculateLatestSubtotal(['a','b'].map(id=>entry(id,{currency:'USD',quantity:'1',
    price:'10000000000000000.02',previousClose:'10000000000000000',
    fx:{numerator:'1',denominator:'1.25'},previousFx:{numerator:'1',denominator:'1.25'}})));
  assert.equal(converted.change, 0.032);
  const rounded = calculateLatestSubtotal(['a','b'].map(id=>entry(id,{quantity:'1',price:'100.25'})));
  assert.equal(rounded.change, 0.5);
  assert.equal(formatMoney(rounded.change,'EUR',{fractionDigits:0,signed:true}), '€\u00a0+1');
});

test('subtotal preserves caller data, stable missing identities and existing full-total meaning', () => {
  const rows = [entry('good'),entry('missing',{previousClose:null}),entry('missing',{price:null})];
  const original = structuredClone(rows), full = calculatePortfolio(rows), held = rows.map(row=>calculateHolding(row.holding,row.instrument,row.quote));
  const freeze = value => {if(value&&typeof value==='object'){Object.values(value).forEach(freeze);Object.freeze(value);}};
  freeze(rows);
  const subtotal = calculateLatestSubtotal(rows);
  assert.deepEqual(subtotal.missingInstrumentIds, ['missing']);
  assert.equal(subtotal.includedCount, 1);
  assert.equal(subtotal.totalCount, 3);
  assert.deepEqual(rows, original);
  assert.deepEqual(calculatePortfolio(rows), full);
  assert.deepEqual(rows.map(row=>calculateHolding(row.holding,row.instrument,row.quote)), held);
});
