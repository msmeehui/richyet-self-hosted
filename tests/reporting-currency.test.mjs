import test from 'node:test';
import assert from 'node:assert/strict';
import {calculateHolding,calculatePortfolio,calculateLatestContribution,purchaseComparison,formatMoney} from '../public/lib/portfolio.mjs';
import {portfolioCurrency,reportingExchangeRate,reportingQuote,reportingIssues} from '../public/lib/reporting-currency.mjs';
import {createActiveReadinessTracker} from '../public/lib/active-readiness.mjs';
import {selectComparisonEntries} from '../public/lib/comparison-modes.mjs';
import {selectHoldingSections} from '../public/lib/holding-sections.mjs';

const now = Date.parse('2026-10-15T12:00:00Z');
const fx = () => ({series:'ecb-reference-daily',base:'EUR',status:'ready',
  current:{date:'2026-10-14',rates:{USD:'1.25',GBP:'0.75'}},
  history:[{date:'2026-10-14',rates:{USD:'1.25',GBP:'0.75'}},
    {date:'2026-10-13',rates:{USD:'1.20',GBP:'0.80'}},
    {date:'2026-10-09',rates:{USD:'1.10',GBP:'0.85'}}],
  fetchedAt:'2026-10-15T10:00:00Z',freshUntil:'2026-10-15T16:00:00Z'});
const context = currency => ({currency,fx:fx(),nowMs:now});
const row = (currency='EUR',overrides={}) => ({
  holding:{id:'fictional-position-'+currency,instrumentId:'fictional-'+currency,quantity:'5',averageCost:'90',eurCost:null,...overrides.holding},
  instrument:{id:'fictional-'+currency,currency,type:'Stock',...overrides.instrument},
  quote:{status:'closed',date:'2026-10-14',previousDate:'2026-10-13',price:'104',previousClose:'100',
    priceKind:'close',fx:'0.90',previousFx:'0.92',...overrides.quote},
});
const calc = (entry,reporting) => calculateHolding(entry.holding,entry.instrument,entry.quote,reporting);
const near = (actual,expected) => assert.ok(Math.abs(actual-expected) < 1e-10,`${actual} != ${expected}`);

test('EUR default and explicit setting retain every existing result and saved input exactly',()=>{
  const rows=[row(),row('USD'),row('USD',{holding:{id:'known-cost',eurCost:'500'}})];
  const before=structuredClone(rows),eur={...context('EUR'),fx:null};
  for(const entry of rows){
    assert.deepEqual(calc(entry,eur),calc(entry));
    assert.deepEqual(purchaseComparison(entry.holding,entry.instrument,calc(entry,eur),eur),purchaseComparison(entry.holding,entry.instrument,calc(entry)));
  }
  assert.deepEqual(calculatePortfolio(rows,eur),calculatePortfolio(rows));
  assert.deepEqual(calculateLatestContribution([rows[0]],rows,eur),calculateLatestContribution([rows[0]],rows));
  assert.deepEqual(rows,before);
  assert.equal(portfolioCurrency(null),'EUR');assert.equal(portfolioCurrency({currency:'CHF'}),'EUR');
});

test('cross rates keep exact direction and can reverse the sign of a rising local price',()=>{
  const entry=row('USD'),gbp=context('GBP'),result=calc(entry,gbp);
  assert.deepEqual(reportingExchangeRate('USD',gbp),{numerator:'0.75',denominator:'1.25'});
  assert.deepEqual(reportingExchangeRate('USD',gbp,'2026-10-13'),{numerator:'0.80',denominator:'1.20'});
  assert.equal(result.value,312);near(result.baseline,1000/3);near(result.change,-64/3);
  near(result.changePercent,-6.4);assert.equal(result.localChangePercent,4);
  const euro=calc(row('EUR'),gbp);
  assert.equal(euro.value,390);assert.equal(euro.baseline,400);assert.equal(euro.change,-10);assert.equal(euro.changePercent,-2.5);
});

test('native currency values and comparisons need no FX service or saved EUR conversion',()=>{
  const usd=row('USD',{quote:{fx:null,previousFx:null}}),missing={currency:'USD',fx:null,nowMs:now};
  const result=calc(usd,missing);
  assert.equal(result.value,520);assert.equal(result.baseline,500);assert.equal(result.change,20);assert.equal(result.changePercent,4);
  assert.equal(result.purchaseGain,70);assert.equal(result.purchaseGainEstimated,false);
  const gbp=row('GBP');assert.equal(calc(gbp,{currency:'GBP'}).value,520);
  assert.equal(reportingExchangeRate('CAD',context('GBP')),null);
});

test('Latest percentages keep one complete reporting-currency reference across the filter',()=>{
  const euro=row('EUR'),pound=row('GBP'),all=[euro,pound],gbp=context('GBP');
  const selected=calculateLatestContribution([euro],all,gbp),whole=calculateLatestContribution(all,all,gbp);
  assert.equal(selected.referenceBaseline,900);assert.equal(whole.referenceBaseline,900);
  assert.equal(selected.change,-10);assert.equal(whole.change,10);
  near(selected.changePercent,-10/9);near(whole.changePercent,10/9);
  const missing=row('EUR',{holding:{id:'hidden',instrumentId:'hidden'},instrument:{id:'hidden'},quote:{previousDate:'2026-09-01'}});
  const incomplete=calculateLatestContribution([pound],[pound,missing],gbp);
  assert.equal(incomplete.change,20);assert.equal(incomplete.changePercent,null);
  assert.deepEqual(incomplete.missingReferenceInstrumentIds,['hidden']);
});

test('historical FX uses the preceding published date without looking forward or borrowing distant values',()=>{
  const gbp=context('GBP');
  assert.deepEqual(reportingExchangeRate('EUR',gbp,'2026-10-12'),{numerator:'0.85',denominator:'1'});
  assert.equal(reportingExchangeRate('EUR',gbp,'2026-10-08'),null);
  assert.equal(reportingExchangeRate('EUR',gbp,'2026-10-16'),null);
  const incomplete={...gbp,fx:{...gbp.fx,history:[{date:'2026-10-01',rates:{USD:'1',GBP:'0.9'}}]}};
  assert.equal(reportingExchangeRate('EUR',incomplete,'2026-10-13'),null);
  const invalid=structuredClone(gbp);invalid.fx.history[1].rates.GBP='0';
  assert.equal(reportingExchangeRate('EUR',invalid,'2026-10-13'),null);
  const conflict=structuredClone(gbp);conflict.fx.history.push({date:'2026-10-13',rates:{USD:'1.2',GBP:'0.81'}});
  assert.equal(reportingExchangeRate('EUR',conflict,'2026-10-13'),null);
  const entry=row('EUR',{quote:{previousDate:'2026-10-08'}});
  assert.equal(calc(entry,gbp).value,390);assert.equal(calc(entry,gbp).change,null);assert.equal(calc(entry,gbp).purchaseGain,52.5);
});

test('missing, invalid or future current FX withholds converted values, never native values',()=>{
  for(const mutate of [c=>{c.fx.current.date='2026-10-16';},c=>{c.fx.fetchedAt='2026-10-15T12:01:00Z';},
    c=>{c.fx.current.rates.GBP='0';},c=>{c.fx=null;}]){
    const c=context('GBP');mutate(c);
    assert.equal(calc(row('EUR'),c).value,null);assert.equal(calc(row('EUR'),c).purchaseGain,null);
    assert.equal(calc(row('GBP'),c).value,520);
    assert.equal(calculatePortfolio([row('EUR'),row('GBP')],c).value,null);
  }
});

test('Since purchase uses native costs directly and explicitly estimates cross-currency costs',()=>{
  const known=row('USD',{holding:{eurCost:'500'}}),gbp=context('GBP'),usd=context('USD');
  assert.equal(calc(known).purchaseGain,-32);
  assert.equal(calc(known,usd).purchaseGain,70);assert.equal(calc(known,usd).purchaseGainEstimated,false);
  const converted=calc(known,gbp);
  assert.equal(converted.purchaseCost,270);assert.equal(converted.purchaseGain,42);assert.equal(converted.purchaseGainEstimated,true);
  near(converted.purchaseGainPercent,70/450*100);
  assert.deepEqual(purchaseComparison(known.holding,known.instrument,converted,gbp),{
    amount:42,percentage:70/450*100,currency:'GBP',estimated:true,excludesCurrencyChanges:true,missingCost:false,
  });
  const onlyEuro=row('USD',{holding:{averageCost:null,eurCost:'400'}}),before=structuredClone(onlyEuro);
  const fallback=calc(onlyEuro,usd);
  assert.equal(fallback.purchaseCost,500);assert.equal(fallback.purchaseGain,20);assert.equal(fallback.purchaseGainEstimated,true);
  assert.equal(purchaseComparison(onlyEuro.holding,onlyEuro.instrument,fallback,usd).missingCost,false);
  assert.deepEqual(onlyEuro,before);
  const unknown=row('USD',{holding:{averageCost:null,eurCost:null}});
  assert.equal(calc(unknown,gbp).purchaseGain,null);assert.equal(calc(unknown,gbp).change,-64/3);
  const total=calculatePortfolio([known,onlyEuro],usd);
  assert.equal(total.purchaseGain,90);assert.equal(total.purchaseCost,950);near(total.purchaseGainPercent,90/950*100);
  assert.equal(total.purchaseGainEstimated,true);
});

test('huge decimal values keep cents through cross-rate arithmetic and aggregate before rounding',()=>{
  const entry=row('EUR',{holding:{quantity:'1',averageCost:'10000000000000000'},quote:{price:'10000000000000000.02',previousClose:'10000000000000000'}});
  const gbp=context('GBP');gbp.fx.history[1].rates.GBP='0.75';
  assert.equal(calc(entry,gbp).change,0.015);assert.equal(calc(entry,gbp).purchaseGain,0.015);
  assert.equal(calculatePortfolio([entry,entry],gbp).change,0.03);
  assert.match(formatMoney(0.015,'GBP'),/0,02/);
});

test('reporting metadata removes only irrelevant EUR FX problems and retains independent price failures',()=>{
  const entry=row('USD',{quote:{status:'stale',fx:null,previousFx:null,freshUntil:Math.floor(now/1000)+60}});
  const issues=[{instrumentId:entry.instrument.id,code:'fx_stale'}],usd={currency:'USD',nowMs:now};
  assert.equal(reportingQuote(entry.instrument,entry.quote,issues,usd).status,'closed');
  assert.equal(reportingQuote(entry.instrument,entry.quote,issues,usd).fx,'1');
  assert.deepEqual(reportingIssues(entry.instrument,entry.quote,issues,usd),[]);
  for(const extra of ['price_stale','history_price_stale','corporate_action_unverified','source_unavailable']){
    const original=[...issues,{instrumentId:entry.instrument.id,code:extra}];
    assert.equal(reportingQuote(entry.instrument,entry.quote,original,usd).status,'stale');
    assert.deepEqual(reportingIssues(entry.instrument,entry.quote,original,usd),[{instrumentId:entry.instrument.id,code:extra}]);
  }
  assert.equal(reportingQuote(entry.instrument,{...entry.quote,freshUntil:null},issues,usd).status,'stale');
  assert.equal(reportingQuote(entry.instrument,{...entry.quote,sourceAvailable:false},issues,usd).status,'stale');
});

test('open-market readiness and detail selection calculate in the chosen currency without altering source inputs',()=>{
  const at=Date.parse('2026-10-14T14:00:00Z'),entry=row('USD',{instrument:{mic:'XNAS',timeZone:'America/New_York',closeSchedule:'us_retained_calendar'},quote:{status:'delayed',observedAt:'2026-10-14T13:50:00Z',priceKind:'trade',fx:null,previousFx:null}});
  const before=structuredClone(entry),usd={currency:'USD',fx:null,nowMs:at},tracker=createActiveReadinessTracker();tracker.bind('fictional@example.test');
  assert.equal(selectComparisonEntries([entry],'active',at,null).comparisonEntries.length,0);
  const ready=selectComparisonEntries([entry],'active',at,tracker,usd);
  assert.equal(ready.comparisonEntries.length,1);assert.deepEqual(ready.failedInstrumentIds,[]);
  assert.equal(selectHoldingSections([entry],at,'active',tracker,usd).visibleEntries.length,1);
  const failed={...entry,quote:{...entry.quote,previousClose:null}};
  assert.deepEqual(selectComparisonEntries([failed],'active',at,tracker,usd).failedInstrumentIds,[entry.instrument.id]);
  assert.deepEqual(entry,before);
});

test('last valid reporting rates remain numeric after expiry with a dated stale warning',()=>{
 for(const mutate of [c=>{c.fx.status='stale';},c=>{c.fx.freshUntil='2026-10-15T11:59:59Z';}]) {const c=context('GBP');mutate(c);const e=row('EUR');assert.equal(calc(e,c).value,390);assert.equal(calc(e,c).purchaseGain,52.5);assert.ok(reportingIssues(e.instrument,e.quote,[],c).some(x=>x.code==='fx_stale'));}
});
