import test from 'node:test';
import assert from 'node:assert/strict';
import {
  parseDutchNumber, calculateHolding, calculatePortfolio, purchaseComparison,
  formatMoney, formatPercent, formatQuantity,
} from '../public/lib/portfolio.mjs';

// All identities and values below are fictional specification examples.
function entry({ currency = 'EUR', quantity = '10', averageCost = '90', eurCost = null,
                 price = '102', previousClose = '100', fx = null, previousFx = null,
                 date = '2026-10-09', previousDate = '2026-10-08', status = 'delayed',
                 id = 'fictional-eu-share' } = {}) {
  return {
    holding: { id: `holding-${id}`, instrumentId: id, quantity, averageCost, eurCost },
    instrument: { id, name: 'Fictional Example', symbol: 'EXAMPLE', exchange: 'EXAMPLE', currency, type: 'stock' },
    quote: { price, previousClose, date, previousDate, observedAt: `${date}T10:00:00Z`,
      previousObservedAt: `${previousDate}T15:30:00Z`, fx, previousFx, status, source: 'fictional' },
  };
}
function holdingResult(row) { return calculateHolding(row.holding, row.instrument, row.quote); }
function close(actual, expected, tolerance = 1e-12) {
  assert.ok(Math.abs(actual - expected) <= tolerance, `${actual} should be close to ${expected}`);
}

test('Dutch input accepts exact grouped integers and comma fractions without coercion', () => {
  for (const [input, expected] of [
    ['1.234,56', '1234.56'], ['1234,560000', '1234.56'], ['1.234', '1234'],
    ['1,234', '1.234'], ['00012,3400', '12.34'], [' 0,000001 ', '0.000001'],
    ['1.234.567,000001', '1234567.000001'], ['99999999999999999,123456', '99999999999999999.123456'],
  ]) assert.equal(parseDutchNumber(input), expected);
});

test('Dutch input rejects ambiguity, negatives, exponents, excess precision and empty values', () => {
  for (const input of ['', ' ', '-1', '+1', '12.34', '1234.56', '1,234.56', '1.2345',
    '1 234', '1\u00a0234', '1e3', 'Infinity', 'NaN', '1,', ',5', '.5', '1,0000001',
    '1.234,0000000', '1,2,3', '€12', '1'.repeat(121), null, undefined, 2]) {
    assert.throws(() => parseDutchNumber(input), error =>
      error.name === 'ValidationError' && error.code === 'INVALID_NUMBER', String(input));
  }
});

test('zero must be explicitly allowed; no negative-zero string can bypass it', () => {
  for (const input of ['0', '00', '0,000000']) {
    assert.throws(() => parseDutchNumber(input), { name: 'ValidationError' });
    assert.equal(parseDutchNumber(input, { allowZero: true }), '0');
  }
  assert.throws(() => parseDutchNumber('-0', { allowZero: true }), { name: 'ValidationError' });
});

test('EUR valuation and purchase gains ignore unnecessary FX fields', () => {
  const result = holdingResult(entry({ fx: '0.5', previousFx: '0.1', eurCost: '1' }));
  assert.equal(result.value, 1020);
  assert.equal(result.baseline, 1000);
  assert.equal(result.change, 20);
  assert.equal(result.changePercent, 2);
  assert.equal(result.purchaseGain, 120);
  close(result.purchaseGainPercent, 13.333333333333334);
  assert.equal(result.localChangePercent, 2);
  assert.equal(result.completeValue, true);
  assert.equal(result.completeChange, true);
});

test('USD comparison includes FX at both endpoints and original EUR acquisition cost', () => {
  const result = holdingResult(entry({ currency: 'USD', quantity: '5', price: '104',
    fx: '0.90', previousFx: '0.92', eurCost: '400' }));
  assert.equal(result.value, 468);
  assert.equal(result.baseline, 460);
  assert.equal(result.change, 8); // Price-only conversion would incorrectly give 18.
  close(result.changePercent, 1.7391304347826086);
  assert.equal(result.localChangePercent, 4);
  assert.equal(result.purchaseGain, 68);
  assert.equal(result.purchaseGainPercent, 17);
  assert.equal(result.localPurchaseGain, 70);
  close(result.localPurchaseGainPercent, 15.555555555555555);
});

test('mixed session/NAV dates aggregate by baseline value, not average percentage', () => {
  const rows = [entry(), entry({ id: 'fictional-us-share', currency: 'USD', quantity: '5',
    price: '104', fx: '0.90', previousFx: '0.92', date: '2026-10-08', previousDate: '2026-10-07', status: 'closed' }),
  entry({ id: 'fictional-fund', quantity: '100', price: '10.10', previousClose: '10',
    date: '2026-10-07', previousDate: '2026-10-06', status: 'nav' })];
  const result = calculatePortfolio(rows);
  assert.equal(result.value, 2498);
  assert.equal(result.knownValue, 2498);
  assert.equal(result.baseline, 2460);
  assert.equal(result.change, 38);
  close(result.changePercent, 1.5447154471544715);
  assert.equal(result.stale, false);
  assert.equal(result.count, 3);
  assert.equal(result.completeChange, true);
});

test('quantity edits revalue both sides; average-cost edits affect only purchase gain', () => {
  const before = holdingResult(entry({ quantity: '5' }));
  const after = holdingResult(entry({ quantity: '8' }));
  assert.equal(before.change, 10);
  assert.equal(after.value - before.value, 306);
  assert.equal(after.change, 16); // Hypothetical movement of current units, not earned trading P&L.
  const revisedCost = holdingResult(entry({ quantity: '8', averageCost: '99' }));
  assert.equal(revisedCost.change, 16);
  assert.equal(revisedCost.purchaseGain, 24);
  assert.equal(calculatePortfolio([]).change, 0); // Removing holdings is not a recorded loss.
});

test('unknown EUR cost estimates both endpoints at current FX without inventing an actual cost', () => {
  const result = holdingResult(entry({ currency: 'USD', quantity: '5', price: '104',
    fx: '0.90', previousFx: '0.92' }));
  assert.equal(result.value, 468);
  assert.equal(result.change, 8);
  assert.equal(result.actualPurchaseCost, null);
  assert.equal(result.purchaseCost, 405);
  assert.equal(result.purchaseCostEstimated, true);
  assert.equal(result.purchaseGain, 63);
  close(result.purchaseGainPercent, 70 / 450 * 100);
  assert.equal(result.purchaseGainEstimated, true);
  assert.equal(result.localPurchaseGain, 70);
  assert.equal(result.completeValue, true);
});

function displayedPurchase(row) {
  return purchaseComparison(row.holding, row.instrument, holdingResult(row));
}

test('an EUR estimate enters a mixed portfolio with weighted costs and never alters saved inputs', () => {
  const row = entry({currency:'USD', quantity:'5', price:'104', fx:'0.90', previousFx:'0.92'});
  const original = structuredClone(row);
  const comparison = displayedPurchase(row);
  assert.equal(comparison.amount, 63);
  close(comparison.percentage, 70 / 450 * 100);
  assert.equal(comparison.currency, 'EUR');
  assert.equal(comparison.estimated, true);
  assert.equal(comparison.excludesCurrencyChanges, true);
  assert.equal(comparison.missingCost, false);
  const exactForeign = entry({id:'known-foreign',currency:'USD',quantity:'5',price:'104',fx:'0.90',previousFx:'0.92',eurCost:'500'});
  const total = calculatePortfolio([entry(), row, exactForeign]);
  assert.equal(total.completeValue, true);
  assert.equal(total.completeChange, true);
  assert.equal(total.actualPurchaseCost, null);
  assert.equal(total.purchaseCost, 1805);
  assert.equal(total.purchaseGain, 151);
  close(total.purchaseGainPercent, 151 / 1805 * 100);
  assert.notEqual(total.purchaseGainPercent, [entry(), row, exactForeign].map(holdingResult).reduce((sum,r)=>sum+r.purchaseGainPercent,0)/3);
  assert.equal(total.purchaseCostEstimated, true);
  assert.equal(total.purchaseGainEstimated, true);
  assert.equal(total.completePurchaseGain, true);
  assert.equal(total.change, 36);
  assert.deepEqual(row, original);
});

test('known EUR cost keeps currency-inclusive purchase movement even when its sign differs', () => {
  const row = entry({currency:'USD', quantity:'5', price:'104', fx:'0.90', eurCost:'500'});
  assert.equal(holdingResult(row).localPurchaseGain, 70);
  assert.deepEqual(displayedPurchase(row), {
    amount:-32, percentage:-6.4, currency:'EUR', estimated:false, excludesCurrencyChanges:false, missingCost:false,
  });
  row.quote.fx = null;
  assert.deepEqual(displayedPurchase(row), {
    amount:null, percentage:null, currency:'EUR', estimated:false, excludesCurrencyChanges:false, missingCost:false,
  });
  assert.equal(holdingResult(row).actualPurchaseCost, 500);
  assert.equal(holdingResult(row).purchaseCostEstimated, false);
});

test('estimated purchase gain needs current FX but no daily baseline and retains stale status', () => {
  for (const changes of [{previousFx:null, previousClose:null}, {previousDate:null}, {status:'stale'}]) {
    const row = entry({currency:'USD', quantity:'5', price:'104', fx:'0.90', previousFx:'0.92', ...changes});
    const comparison = displayedPurchase(row);
    assert.equal(comparison.amount, 63);
    close(comparison.percentage, 70 / 450 * 100);
    assert.equal(comparison.excludesCurrencyChanges, true);
    assert.equal(holdingResult(row).stale, changes.status === 'stale');
  }
  const noFx = entry({currency:'USD',quantity:'5',price:'104',fx:null});
  assert.equal(displayedPurchase(noFx).amount, null);
  assert.equal(displayedPurchase(noFx).estimated, false);
  assert.equal(holdingResult(noFx).localPurchaseGain, 70);
  assert.equal(calculatePortfolio([noFx]).purchaseGainEstimated, false);
});

test('missing and zero local purchase costs remain distinct and malformed EUR cost does not select fallback', () => {
  for (const averageCost of [null, undefined, '', '-1']) {
    const row = entry({currency:'USD'});
    row.holding.averageCost = averageCost;
    assert.deepEqual(displayedPurchase(row), {
      amount:null, percentage:null, currency:'EUR', estimated:false, excludesCurrencyChanges:false, missingCost:true,
    });
  }
  assert.deepEqual(displayedPurchase(entry({currency:'USD', quantity:'5', price:'104', averageCost:'0',fx:'0.90'})), {
    amount:468, percentage:null, currency:'EUR', estimated:true, excludesCurrencyChanges:true, missingCost:false,
  });
  assert.deepEqual(displayedPurchase(entry({currency:'USD', eurCost:''})), {
    amount:null, percentage:null, currency:'EUR', estimated:false, excludesCurrencyChanges:false, missingCost:true,
  });
  const zero = entry({currency:'USD',quantity:'5',price:'90',averageCost:'90',fx:'0.90'});
  assert.equal(displayedPurchase(zero).amount, 0);
  assert.equal(displayedPurchase(zero).percentage, 0);
  assert.equal(displayedPurchase(zero).estimated, true);
  const total = calculatePortfolio([zero]);
  assert.equal(total.purchaseGain, 0);
  assert.equal(total.purchaseGainEstimated, true);
});

test('estimated purchase presentation never bypasses rejected price or listing evidence', () => {
  for (const changes of [{price:null}, {status:'unavailable'}, {date:null}]) {
    const comparison = displayedPurchase(entry({currency:'USD', fx:'0.90', ...changes}));
    assert.equal(comparison.amount, null);
    assert.equal(comparison.percentage, null);
    assert.equal(comparison.excludesCurrencyChanges, false);
    assert.equal(comparison.missingCost, false);
  }
  const wrongIdentity = entry({currency:'USD', fx:'0.90'});
  wrongIdentity.holding.instrumentId = 'another-listing';
  assert.equal(displayedPurchase(wrongIdentity).amount, null);
});

test('estimated EUR presentation retains exact small differences and leaves EUR holdings unchanged', () => {
  const tiny = displayedPurchase(entry({currency:'USD', quantity:'0.1', price:'0.3', averageCost:'0.1',fx:'0.90'}));
  assert.equal(tiny.amount, 0.018);
  assert.equal(tiny.percentage, 200);
  const large = displayedPurchase(entry({currency:'USD', quantity:'1', price:'10000000000000000.02', averageCost:'10000000000000000',fx:{numerator:'1',denominator:'1.25'}}));
  assert.equal(large.amount, 0.016);
  const euro = displayedPurchase(entry({quantity:'1', price:'104', averageCost:'100'}));
  assert.deepEqual(euro, {
    amount:4, percentage:4, currency:'EUR', estimated:false, excludesCurrencyChanges:false, missingCost:false,
  });
});

test('zero cost and baseline leave percentages unavailable, not infinite', () => {
  const result = holdingResult(entry({ averageCost: '0', previousClose: '0' }));
  assert.equal(result.purchaseGain, 1020);
  assert.equal(result.purchaseGainPercent, null);
  assert.equal(result.change, 1020);
  assert.equal(result.changePercent, null);
  assert.equal(result.localChangePercent, null);
  assert.equal(result.localPurchaseGainPercent, null);
  assert.equal(result.completeChange, true);
  assert.equal(calculatePortfolio([entry({ previousClose: '0' })]).changePercent, null);
});

test('explicit zero prices are distinct from unavailable prices', () => {
  const zero = holdingResult(entry({ price: '0' }));
  assert.equal(zero.value, 0);
  assert.equal(zero.change, -1000);
  assert.equal(zero.changePercent, -100);
  assert.equal(zero.completeValue, true);
  const missing = holdingResult(entry({ price: null }));
  assert.equal(missing.value, null);
  assert.equal(missing.change, null);
  assert.equal(missing.completeValue, false);
});

test('missing baseline preserves current value, while missing FX preserves only local metrics', () => {
  const missingBaseline = holdingResult(entry({ previousClose: null }));
  assert.equal(missingBaseline.value, 1020);
  assert.equal(missingBaseline.change, null);
  assert.equal(missingBaseline.completeChange, false);
  const noCurrentFx = holdingResult(entry({ currency: 'USD', fx: null, previousFx: '0.92' }));
  assert.equal(noCurrentFx.value, null);
  assert.equal(noCurrentFx.change, null);
  assert.equal(noCurrentFx.localChangePercent, 2);
  assert.equal(noCurrentFx.localPurchaseGain, 120);
  const noBaselineFx = holdingResult(entry({ currency: 'USD', fx: '0.90', previousFx: null }));
  assert.equal(noBaselineFx.value, 918);
  assert.equal(noBaselineFx.baseline, null);
  assert.equal(noBaselineFx.change, null);
});

test('partial and entirely missing portfolios never masquerade as complete totals', () => {
  const partial = calculatePortfolio([entry(), entry({ id: 'missing', price: null })]);
  assert.equal(partial.value, null);
  assert.equal(partial.knownValue, 1020);
  assert.equal(partial.change, null);
  assert.equal(partial.changePercent, null);
  assert.equal(partial.completeValue, false);
  assert.equal(partial.completeChange, false);
  const allMissing = calculatePortfolio([entry({ price: null })]);
  assert.equal(allMissing.knownValue, null);
  assert.equal(allMissing.value, null);
  const currentOnly = calculatePortfolio([entry({ previousClose: null })]);
  assert.equal(currentOnly.value, 1020);
  assert.equal(currentOnly.baseline, null);
  assert.equal(currentOnly.change, null);
});

test('an empty portfolio has zero value and no meaningful percentage', () => {
  assert.deepEqual(calculatePortfolio([]), {
    value: 0, knownValue: 0, baseline: 0, change: 0, changePercent: null,
    actualPurchaseCost: 0, purchaseCost: 0, purchaseGain: null, purchaseGainPercent: null,
    purchaseCostEstimated: false, purchaseGainEstimated: false,
    completePurchaseCost: true, completePurchaseGain: false,
    completeValue: true, completeChange: true, stale: false, count: 0,
  });
});

test('portfolio purchase gains use summed EUR acquisition costs and cost-weighted percentages', () => {
  const rows = [entry(), entry({ id:'foreign', currency:'USD', quantity:'5', averageCost:'999',
    price:'104', fx:'0.90', previousFx:'0.92', eurCost:'400' })];
  const total = calculatePortfolio(rows);
  assert.equal(total.value,1488);
  assert.equal(total.purchaseCost,1300); // 10 × €90 plus the original €400, not today's converted dollar cost.
  assert.equal(total.actualPurchaseCost,1300);
  assert.equal(total.purchaseCostEstimated,false);
  assert.equal(total.purchaseGainEstimated,false);
  assert.equal(total.purchaseGain,188);
  close(total.purchaseGainPercent,188/1300*100);
  assert.notEqual(total.purchaseGainPercent,rows.map(holdingResult).reduce((sum,row)=>sum+row.purchaseGainPercent,0)/2);
  assert.equal(total.completePurchaseCost,true);
  assert.equal(total.completePurchaseGain,true);
  assert.equal(total.change,28); // The new purchase comparison leaves daily movement unchanged.
});

test('one unknown or invalid purchase cost blocks the whole comparison without blocking current value', () => {
  for (const changes of [{eurCost:null,averageCost:null},{eurCost:undefined,averageCost:null},...['','-1','NaN'].map(eurCost=>({eurCost}))]) {
    const total = calculatePortfolio([entry(),entry({id:'foreign',currency:'USD',fx:'0.90',previousFx:'0.92',...changes})]);
    assert.equal(total.value,1938);
    assert.equal(total.completeValue,true);
    assert.equal(total.completeChange,true);
    assert.equal(total.purchaseCost,null);
    assert.equal(total.purchaseGain,null);
    assert.equal(total.purchaseGainPercent,null);
    assert.equal(total.completePurchaseCost,false);
    assert.equal(total.completePurchaseGain,false);
    assert.equal(total.purchaseGainEstimated,false);
  }
  const invalidAverage = calculatePortfolio([entry({averageCost:null})]);
  assert.equal(invalidAverage.value,1020);
  assert.equal(invalidAverage.purchaseCost,null);
  assert.equal(invalidAverage.purchaseGain,null);
  const wrongIdentity = entry();
  wrongIdentity.holding.instrumentId = 'another-listing';
  assert.equal(calculatePortfolio([wrongIdentity]).completePurchaseCost,false);
});

test('a usable estimate never conceals another holding with missing cost or valuation inputs', () => {
  const estimated = entry({currency:'USD',fx:'0.90',previousFx:'0.92'});
  for (const unavailable of [{averageCost:null},{price:null},{fx:null}]) {
    const total = calculatePortfolio([estimated,entry({id:'incomplete',currency:'USD',fx:'0.90',previousFx:'0.92',...unavailable})]);
    assert.equal(total.purchaseGain,null);
    assert.equal(total.purchaseGainPercent,null);
    assert.equal(total.completePurchaseGain,false);
    assert.equal(total.purchaseGainEstimated,false);
  }
});

test('missing current price or FX preserves known acquisition cost but prevents purchase gain', () => {
  const foreign = {id:'foreign',currency:'USD',quantity:'5',price:'104',fx:'0.90',previousFx:'0.92',eurCost:'400'};
  for (const unavailable of [{price:null},{fx:null},{status:'unavailable'}]) {
    const total = calculatePortfolio([entry(),entry({...foreign,...unavailable})]);
    assert.equal(total.value,null);
    assert.equal(total.knownValue,1020);
    assert.equal(total.purchaseCost,1300);
    assert.equal(total.completePurchaseCost,true);
    assert.equal(total.purchaseGain,null);
    assert.equal(total.purchaseGainPercent,null);
    assert.equal(total.completePurchaseGain,false);
  }
});

test('purchase comparison remains available without a daily baseline and with a dated stale value', () => {
  const foreign = {currency:'USD',quantity:'5',price:'104',fx:'0.90',previousFx:'0.92',eurCost:'400'};
  for (const unavailable of [{previousClose:null},{previousFx:null},{previousDate:null}]) {
    const total = calculatePortfolio([entry({...foreign,...unavailable})]);
    assert.equal(total.completeChange,false);
    assert.equal(total.change,null);
    assert.equal(total.purchaseGain,68);
    assert.equal(total.purchaseGainPercent,17);
    assert.equal(total.completePurchaseGain,true);
  }
  const stale = calculatePortfolio([entry({...foreign,status:'stale'})]);
  assert.equal(stale.purchaseGain,68);
  assert.equal(stale.completePurchaseGain,true);
  assert.equal(stale.stale,true);
});

test('nonempty zero-cost portfolios have a valid gain amount but no purchase percentage', () => {
  const total = calculatePortfolio([entry({averageCost:'0'}),entry({id:'foreign',currency:'USD',quantity:'5',price:'104',fx:'0.90',eurCost:'0'})]);
  assert.equal(total.count,2);
  assert.equal(total.purchaseCost,0);
  assert.equal(total.purchaseGain,1488);
  assert.equal(total.purchaseGainPercent,null);
  assert.equal(total.completePurchaseCost,true);
  assert.equal(total.completePurchaseGain,true);
  assert.equal(total.purchaseGainEstimated,false); // Explicit original EUR zero takes precedence.
  const estimated = calculatePortfolio([entry({currency:'USD',quantity:'5',price:'104',averageCost:'0',fx:'0.90'})]);
  assert.equal(estimated.purchaseCost,0);
  assert.equal(estimated.actualPurchaseCost,null);
  assert.equal(estimated.purchaseGain,468);
  assert.equal(estimated.purchaseGainPercent,null);
  assert.equal(estimated.purchaseGainEstimated,true);
  const worthless = calculatePortfolio([entry({averageCost:'0',price:'0'})]);
  assert.equal(worthless.purchaseGain,0);
  assert.equal(worthless.purchaseGainPercent,null);
  assert.equal(worthless.completePurchaseGain,true);
});

test('portfolio purchase comparison aggregates exact costs and values before rounding or Number conversion', () => {
  const small = calculatePortfolio(['a','b'].map(id=>entry({id,quantity:'1',price:'100.25',averageCost:'100'})));
  assert.equal(small.purchaseGain,0.5);
  assert.equal(formatMoney(small.purchaseGain,'EUR',{fractionDigits:0,signed:true}),'€\u00a0+1');
  const fractional = calculatePortfolio([entry({quantity:'0.1',price:'0.3',averageCost:'0.1'})]);
  assert.equal(fractional.purchaseCost,0.01);
  assert.equal(fractional.purchaseGain,0.02);
  assert.equal(fractional.purchaseGainPercent,200);
  const large = calculatePortfolio([
    entry({id:'gain',quantity:'1',price:'10000000000000000.02',averageCost:'10000000000000000'}),
    entry({id:'loss',quantity:'1',price:'10000000000000000',averageCost:'10000000000000000.01'}),
  ]);
  assert.equal(large.value-large.purchaseCost,0); // Public Numbers lose the cents; internal aggregation must retain them.
  assert.equal(large.purchaseGain,0.01);
  assert.equal(large.purchaseGainPercent,5e-17);
  const converted = calculatePortfolio(['a','b'].map(id=>entry({id,currency:'USD',quantity:'1',price:'10000000000000000.02',averageCost:'10000000000000000',fx:{numerator:'1',denominator:'1.25'}})));
  assert.equal(converted.value-converted.purchaseCost,0);
  assert.equal(converted.purchaseGain,0.032);
  assert.equal(converted.purchaseGainPercent,2e-16);
  assert.equal(converted.purchaseGainEstimated,true);
});

test('stale cache remains valued and flagged; unavailable status is not a usable cache', () => {
  const row = entry({ status: 'stale' });
  assert.equal(holdingResult(row).value, 1020);
  assert.equal(holdingResult(row).stale, true);
  assert.equal(calculatePortfolio([row]).stale, true);
  assert.equal(calculatePortfolio([row]).completeChange, true);
  assert.equal(holdingResult(entry({ status: 'unavailable' })).value, null);
});

test('unusable dates and reversed baselines do not produce invented changes', () => {
  for (const date of [null, '', '2026-02-30', '09-10-2026']) {
    assert.equal(holdingResult(entry({ date })).value, null);
  }
  for (const previousDate of [null, '', '2026-02-30', '2026-10-09', '2026-10-10']) {
    const result = holdingResult(entry({ previousDate }));
    assert.equal(result.value, 1020);
    assert.equal(result.baseline, null);
    assert.equal(result.change, null);
  }
  assert.equal(holdingResult(entry({ date: '2024-02-29', previousDate: '2024-02-28', status: 'closed' })).change, 20);
});

test('fractional units and decimal cancellation retain precision until presentation', () => {
  const tiny = holdingResult(entry({ quantity: '0.1', price: '0.3', previousClose: '0.2', averageCost: '0.1' }));
  assert.equal(tiny.value, 0.03);
  assert.equal(tiny.change, 0.01);
  assert.equal(tiny.purchaseGain, 0.02);
  const large = holdingResult(entry({ quantity: '1', price: '10000000000000000.02',
    previousClose: '10000000000000000.01', averageCost: '10000000000000000' }));
  assert.equal(large.change, 0.01); // Converting inputs to Number first would erase this.
  assert.equal(large.purchaseGain, 0.02);
});

test('portfolio aggregates unrounded values and exact differences', () => {
  const rows = ['a', 'b', 'c'].map(id => entry({ id, quantity: '1', price: '0.004', previousClose: '0.003' }));
  const total = calculatePortfolio(rows);
  assert.equal(total.value, 0.012);
  assert.equal(total.baseline, 0.009);
  assert.equal(total.change, 0.003);
  close(total.changePercent, 33.333333333333336);
  assert.equal(formatMoney(total.value), '€\u00a00,01');
  const cancelling = [entry({ id: 'large-up', quantity: '1', price: '10000000000000000.02', previousClose: '10000000000000000.01' }),
    entry({ id: 'large-down', quantity: '1', price: '10000000000000000.01', previousClose: '10000000000000000.02' })];
  assert.equal(calculatePortfolio(cancelling).change, 0);
});

test('whole-euro totals round after aggregation rather than adding rounded rows', () => {
  const rows = ['a', 'b'].map(id => entry({ id, quantity: '1', price: '100.25', previousClose: '100' }));
  const whole = { fractionDigits: 0 };
  const signedWhole = { ...whole, signed: true };
  for (const row of rows) {
    assert.equal(formatMoney(holdingResult(row).value, 'EUR', whole), '€\u00a0100');
    assert.equal(formatMoney(holdingResult(row).change, 'EUR', signedWhole), '€\u00a00');
  }
  const total = calculatePortfolio(rows);
  assert.equal(total.value, 200.5);
  assert.equal(total.change, 0.5);
  assert.equal(formatMoney(total.value, 'EUR', whole), '€\u00a0201');
  assert.equal(formatMoney(total.change, 'EUR', signedWhole), '€\u00a0+1');
});

test('whole-euro formatting rounds halves away from zero and suppresses rounded negative zero', () => {
  for (const [value, expected] of [
    [174.49, '€\u00a0174'], [174.50, '€\u00a0175'], [32932.50, '€\u00a032.933'],
    [-0.49, '€\u00a00'], [-0.50, '€\u00a0-1'], [-174.50, '€\u00a0-175'], [-0, '€\u00a00'],
  ]) assert.equal(formatMoney(value, 'EUR', { fractionDigits: 0 }), expected);
  assert.equal(formatMoney(174.5, 'EUR', { fractionDigits: 0, signed: true }), '€\u00a0+175');
  assert.equal(formatMoney(-0.49, 'EUR', { fractionDigits: 0, signed: true }), '€\u00a00');
  assert.equal(formatMoney(-0.50, 'EUR', { fractionDigits: 0, signed: true }), '€\u00a0-1');
});

test('money display precision is explicit and bounded while unavailable values stay unavailable', () => {
  assert.equal(formatMoney(1.2345678, 'EUR', { fractionDigits: 6 }), '€\u00a01,234568');
  assert.equal(formatMoney(174.5, 'EUR', { fractionDigits: 2 }), '€\u00a0174,50');
  for (const fractionDigits of [-1, 7, 0.5, '0', null, NaN, Infinity]) {
    assert.throws(() => formatMoney(1, 'EUR', { fractionDigits }), RangeError);
  }
  for (const value of [null, undefined, NaN, Infinity, -Infinity, '1']) {
    assert.equal(formatMoney(value, 'EUR', { fractionDigits: 0 }), '—');
  }
});

test('split-compatible inputs follow the provider-adjusted basis', () => {
  const result = holdingResult(entry({ quantity: '10', previousClose: '50', price: '51' }));
  assert.equal(result.baseline, 500);
  assert.equal(result.value, 510);
  assert.equal(result.change, 10);
  assert.equal(result.changePercent, 2);
});

test('invalid saved values and a mismatched instrument fail closed without coercion', () => {
  for (const quantity of ['0', '-1', '1e3', '', null, 1]) {
    assert.equal(holdingResult(entry({ quantity })).value, null);
  }
  for (const price of ['NaN', 'Infinity', '-1', '1,23', '', null, 102]) {
    assert.equal(holdingResult(entry({ price })).value, null);
  }
  for (const fx of ['0', '-1', 'NaN', '', null]) {
    assert.equal(holdingResult(entry({ currency: 'USD', fx })).value, null);
  }
  const wrongListing = entry();
  wrongListing.holding.instrumentId = 'different-listing';
  assert.equal(holdingResult(wrongListing).value, null);
  assert.equal(calculateHolding(null, null, null).value, null);
  assert.equal(calculatePortfolio([{}]).value, null);
});

test('Dutch formatters distinguish missing values, quantities, percentages and small negative zero', () => {
  assert.equal(formatMoney(1234.56), '€\u00a01.234,56');
  assert.equal(formatMoney(12.5, 'EUR', { signed: true }), '€\u00a0+12,50');
  assert.equal(formatMoney(-0.001, 'EUR', { signed: true }), '€\u00a00,00');
  assert.equal(formatPercent(1.5), '+1,50%');
  assert.equal(formatPercent(-1.5), '-1,50%');
  assert.equal(formatPercent(1.5, { signed: false }), '1,50%');
  assert.equal(formatPercent(-0.001), '0,00%');
  assert.equal(formatQuantity('1234.56789'), '1.234,56789');
  assert.equal(formatQuantity('0.000001'), '0,000001');
  assert.equal(formatQuantity('0'), '0');
  for (const value of [null, undefined, NaN, Infinity, '123']) {
    assert.equal(formatMoney(value), '—');
    assert.equal(formatPercent(value), '—');
  }
  assert.equal(formatQuantity(null), '—');
  assert.equal(formatQuantity('1,23'), '—');
});

test('calculation calls do not mutate saved holdings or observations', () => {
  const row = entry();
  const original = structuredClone(row);
  Object.freeze(row.holding); Object.freeze(row.instrument); Object.freeze(row.quote); Object.freeze(row);
  calculateHolding(row.holding, row.instrument, row.quote);
  calculatePortfolio([row]);
  assert.deepEqual(row, original);
});
