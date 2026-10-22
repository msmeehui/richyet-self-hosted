import test from 'node:test';
import assert from 'node:assert/strict';
import {calculateLatestContribution,calculateHolding,calculatePortfolio} from '../public/lib/portfolio.mjs';
import {selectComparisonEntries} from '../public/lib/comparison-modes.mjs';

function row(id,price,previousClose,options={}) {
  return {holding:{id,instrumentId:id,quantity:'10',averageCost:'80'},instrument:{id,currency:'EUR'},
    quote:{status:'closed',date:'2026-10-14',previousDate:'2026-10-13',price,previousClose,...options}};
}

test('filtering changes only the contribution numerator; both percentages use the entire prior portfolio',()=>{
  const active=row('morning','95','100'),closed=row('overnight','199','200'),fund=row('daily','300','300');
  const all=[active,closed,fund],before=structuredClone(all);
  const filtered=calculateLatestContribution([active],all),unfiltered=calculateLatestContribution(all,all);
  assert.equal(filtered.change,-50);assert.equal(unfiltered.change,-60);
  assert.equal(filtered.referenceBaseline,6000);assert.equal(unfiltered.referenceBaseline,6000);
  assert.equal(filtered.changePercent,-5/6);assert.equal(unfiltered.changePercent,-1);
  assert.notEqual(filtered.changePercent,calculateHolding(active.holding,active.instrument,active.quote).changePercent);
  assert.equal(calculatePortfolio(all).value,5940);assert.deepEqual(all,before);
});

test('a missing reference in a hidden holding withholds the percentage rather than shrinking its denominator',()=>{
  const active=row('ready','105','100'),hidden=row('hidden','200',null),all=[active,hidden];
  for(const selected of [[active],all]){
    const result=calculateLatestContribution(selected,all);
    assert.equal(result.change,50);assert.equal(result.changePercent,null);assert.equal(result.referenceBaseline,null);
    assert.equal(result.completeReference,false);assert.deepEqual(result.missingReferenceInstrumentIds,['hidden']);
  }
});

test('missing selected current prices keep their valid prior reference and flag partial movement',()=>{
  const good=row('ready','105','100'),missing=row('missing',null,'200'),all=[good,missing];
  const result=calculateLatestContribution(all,all);
  assert.equal(result.change,50);assert.equal(result.referenceBaseline,3000);assert.equal(result.changePercent,5/3);
  assert.equal(result.partial,true);assert.equal(result.completeReference,true);
  assert.deepEqual(result.missingInstrumentIds,['missing']);
});

test('whole-reference contributions retain exact cents and reciprocal FX until final presentation',()=>{
  const tiny=row('tiny','10000000000000000.02','10000000000000000');tiny.holding.quantity='1';
  const dollar=row('dollar','100','100',{fx:{numerator:'1',denominator:'1.25'},previousFx:{numerator:'1',denominator:'1.25'}});
  dollar.instrument.currency='USD';dollar.holding.quantity='1';
  const result=calculateLatestContribution([tiny],[tiny,dollar]);
  assert.equal(result.change,0.02);assert.ok(Math.abs(result.changePercent-0.02/10000000000000080*100)<1e-30);
  assert.equal(result.completeReference,true);
});

test('empty selection, genuine zero movement, and zero reference are distinct',()=>{
  const flat=row('flat','100','100'),zero=row('zero','100','0');
  assert.equal(calculateLatestContribution([],[flat]).change,null);
  assert.equal(calculateLatestContribution([],[flat]).changePercent,null);
  assert.equal(calculateLatestContribution([flat],[flat]).changePercent,0);
  assert.equal(calculateLatestContribution([zero],[zero]).changePercent,null);
  assert.equal(calculateLatestContribution([flat],[]).changePercent,null);
  assert.throws(()=>calculateLatestContribution([flat],null),TypeError);
});

test('reference selection applies future-observation and identity safeguards before contribution arithmetic',()=>{
  const valid=row('valid','105','100'),future=row('future','200','190',{observedAt:'2026-10-16T10:00:00Z'});
  const selection=selectComparisonEntries([valid,future],'all',Date.parse('2026-10-15T10:00:00Z'));
  assert.equal(calculateLatestContribution([valid],selection.referenceEntries).changePercent,null);
  future.quote.observedAt=null;future.instrument.id='another-listing';
  assert.equal(calculateLatestContribution([valid],[valid,future]).changePercent,null);
});
