import test from 'node:test';
import assert from 'node:assert/strict';
import {getFundContribution,normalizeFundContribution} from '../public/lib/fund-contribution.mjs';
import {selectHoldingSections} from '../public/lib/holding-sections.mjs';
import {selectOpenMarketEntries} from '../public/lib/exchange-status.mjs';
import {calculatePortfolio,calculateLatestSubtotal} from '../public/lib/portfolio.mjs';
import {normalizeMarketSnapshot} from '../public/lib/market.mjs';
const fund={id:'fixture-fund',type:'Fund',currency:'EUR',contributionSchedule:'bnd_daily_amsterdam'};
const at=Date.parse;
function row(firstObservedAt='2026-10-13T06:00:00Z',windowDate='2026-10-13',changes={}) {
  return {holding:{id:'holding-fund',instrumentId:fund.id,quantity:'3',averageCost:'80'},instrument:fund,
    quote:{date:'2026-10-12',previousDate:'2026-10-09',price:'102',previousClose:'100',priceKind:'nav',status:'nav',fx:'1',previousFx:'1',sourceAvailable:true,
      fundContribution:{valuationDate:'2026-10-12',firstObservedAt,windowDate},...changes}};
}
const active=(r,t)=>getFundContribution(r.instrument,r.quote,at(t)).active;
test('one Amsterdam weekday window, no overnight or next-day replay from repeated reads',()=>{
  const r=row();
  for(const [time,expected] of [['06:59:59',false],['07:00:00',true],['15:29:59',true],['15:30:00',false],['22:00:00',false]])
    assert.equal(active(r,`2026-10-13T${time}Z`),expected,time);
  for(const date of ['2026-10-14','2026-10-15','2026-10-17','2026-10-19'])
    assert.equal(active(r,`${date}T10:00:00Z`),false,date);
  for(let n=0;n<10;n++) assert.equal(active(r,'2026-10-13T09:30:00Z'),true);
});
test('late arrivals get only the remaining hours; after-close and weekend receipts start next weekday',()=>{
  const late=row('2026-10-13T15:25:00Z');
  assert.equal(active(late,'2026-10-13T15:24:59Z'),false);
  assert.equal(active(late,'2026-10-13T15:25:00Z'),true);
  assert.equal(active(late,'2026-10-13T15:30:00Z'),false);
  for(const first of ['2026-10-16T15:30:00Z','2026-10-17T08:00:00Z','2026-10-18T18:00:00Z']) {
    const r=row(first,'2026-10-19');
    assert.equal(active(r,'2026-10-17T10:00:00Z'),false);
    assert.equal(active(r,'2026-10-19T06:59:59Z'),false);
    assert.equal(active(r,'2026-10-19T07:00:00Z'),true);
    assert.equal(active(r,'2026-10-19T15:30:00Z'),false);
  }
});
test('Amsterdam clock changes do not move the civil reporting window',()=>{
  for(const [first,day,start,end] of [['2026-10-23T15:31:00Z','2026-10-26','08:00:00','16:30:00'],['2026-03-27T16:31:00Z','2026-03-30','07:00:00','15:30:00']]) {
    const r=row(first,day,{date:'2026-03-26',fundContribution:{valuationDate:'2026-03-26',firstObservedAt:first,windowDate:day}});
    assert.equal(active(r,`${day}T${start}Z`),true);
    assert.equal(active(r,`${day}T${end}Z`),false);
  }
});
test('missing, forged, mismatched and future metadata never creates eligibility',()=>{
  const valid=row().quote.fundContribution;
  for(const raw of [null,{}, {...valid,valuationDate:'2026-10-11'}, {...valid,windowDate:'2026-10-14'},
    {...valid,firstObservedAt:'2026-02-30T06:00:00Z'}, {...valid,firstObservedAt:'2026-10-13T25:00:00Z'}, {...valid,windowDate:'2026-10-17'}]) {
    assert.equal(normalizeFundContribution(raw,'2026-10-12'),null);
    assert.equal(active(row(undefined,undefined,{fundContribution:raw}),'2026-10-13T10:00:00Z'),false);
  }
  for(const i of [{...fund,type:'ETF'},{...fund,currency:'USD'},{...fund,contributionSchedule:null}]) assert.equal(getFundContribution(i,row().quote,at('2026-10-13T10:00:00Z')).active,false);
});
test('fund contribution uses its exact daily pair without changing value, purchase gain or saved order',()=>{
  const f=row(),eu={holding:{id:'holding-eu',instrumentId:'fixture-eu',quantity:'2',averageCost:'50'},
    instrument:{id:'fixture-eu',type:'Stock',currency:'EUR',mic:'XAMS',timeZone:'Europe/Amsterdam',closeSchedule:'ams_j0_continuous_2026'},
    quote:{price:'110',previousClose:'100',date:'2026-10-13',previousDate:'2026-10-12',observedAt:'2026-10-13T08:00:00Z',priceKind:'trade',status:'delayed',fx:'1',previousFx:'1'}};
  const rows=[f,eu],before=structuredClone(rows),full=calculatePortfolio(rows);
  const day=selectHoldingSections(rows,at('2026-10-13T10:00:00Z'));
  assert.equal(day.sections.length,1);assert.equal(day.sections[0].title,'Open markets & daily funds');
  assert.deepEqual(day.sections[0].entries.map(e=>e.holding.id),['holding-fund','holding-eu']);
  const total=calculateLatestSubtotal(day.openMarkets.comparisonEntries);
  assert.equal(total.change,26);assert.equal(total.changePercent,5.2);assert.equal(total.partial,false);
  const later=selectHoldingSections(rows,at('2026-10-13T15:35:00Z'));
  assert.equal(later.sections.length,2);assert.equal(later.sections[1].title,'Closed markets & other holdings');
  assert.equal(later.sections[1].entries[0].comparisonQuote,f.quote,'fund tile retains latest dated result');
  assert.equal(calculateLatestSubtotal(later.openMarkets.comparisonEntries).change,20);
  assert.deepEqual(calculatePortfolio(rows),full);assert.deepEqual(rows,before);
});
test('eligible but unavailable fund comparison is Partial alongside a good contribution, not zero',()=>{
  const good=row(),other={...row(),holding:{...row().holding,id:'holding-two',instrumentId:'fund-two'},instrument:{...fund,id:'fund-two'}};
  for(const change of [{previousClose:null}]) {
    const broken={...other,quote:{...other.quote,...change}};
    const selection=selectOpenMarketEntries([good,broken],at('2026-10-13T10:00:00Z'));
    assert.equal(selection.activeFundCount,2);
    const total=calculateLatestSubtotal(selection.comparisonEntries);
    assert.equal(total.partial,true);assert.equal(total.includedCount,1);assert.equal(total.change,6);assert.equal(total.changePercent,2);
    assert.equal(calculatePortfolio([broken]).purchaseGain,66);
  }
});
test('an eligible fund retains a valid dated pair after a failed update without extending its window',()=>{
 const good=row(),before=structuredClone(good);
 for(const change of [{status:'stale'},{sourceAvailable:false}]) {
  const retained={...good,quote:{...good.quote,...change}};
  const during=selectOpenMarketEntries([retained],at('2026-10-13T10:00:00Z'));
  assert.equal(calculateLatestSubtotal(during.comparisonEntries).change,6);
  assert.equal(selectOpenMarketEntries([retained],at('2026-10-13T17:00:00Z')).comparisonEntries.length,0);
 }
 assert.deepEqual(good,before);
});
test('retained timing survives an empty current quote without inventing a price or a new window',()=>{
  const good=row(),broken=row(undefined,undefined,{price:null,date:null,previousClose:null,previousDate:null,status:'missing'});
  const normalized=normalizeMarketSnapshot({dataMode:'live',quotes:{[fund.id]:broken.quote},issues:[]},[fund]);
  broken.quote=normalized.quotes[fund.id];
  const selection=selectOpenMarketEntries([good,broken],at('2026-10-13T10:00:00Z'));
  assert.equal(selection.activeFundCount,2);
  const total=calculateLatestSubtotal(selection.comparisonEntries);
  assert.equal(total.partial,true);assert.equal(total.includedCount,1);assert.equal(total.change,6);
  assert.equal(broken.quote.price,null);assert.equal(broken.quote.date,null);
  assert.equal(calculatePortfolio([broken]).value,null);
  assert.equal(active(broken,'2026-10-14T10:00:00Z'),false);
});
