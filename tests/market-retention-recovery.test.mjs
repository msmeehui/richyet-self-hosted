import test from 'node:test';
import assert from 'node:assert/strict';
import {execFileSync} from 'node:child_process';
import {fileURLToPath} from 'node:url';
import {normalizeMarketSnapshot} from '../public/lib/market.mjs';
import {calculateHolding,calculatePortfolio,calculateLatestContribution} from '../public/lib/portfolio.mjs';
import {selectHoldingSections} from '../public/lib/holding-sections.mjs';
const php=process.env.PHP_BINARY;
test('missing Euronext quote retains full totals in both views without admitting yesterday as today’s first update',{skip:!php},()=>{
 const {instrument:sourceInstrument,snapshot}=JSON.parse(execFileSync(php,[fileURLToPath(new URL('./market-retention-recovery.test.php',import.meta.url)),'--json'],{encoding:'utf8'}));
 const instrument={...sourceInstrument,closeSchedule:'ams_j0_continuous_2026'};
 const market=normalizeMarketSnapshot(snapshot,[instrument]);
 const holding={id:'fictional-position',instrumentId:instrument.id,quantity:'3',averageCost:'80',eurCost:null};
 const entry={holding,instrument,quote:market.quotes[instrument.id]};
 const healthy={...entry,holding:{...holding,id:'healthy',instrumentId:'healthy'},instrument:{...instrument,id:'healthy'},quote:{...entry.quote,price:'110',previousClose:'100',date:'2026-10-22',previousDate:'2026-10-21',observedAt:'2026-10-22T07:15:00Z',priceKind:'trade',status:'delayed',sourceAvailable:true}};
 const entries=[entry,healthy],total=calculatePortfolio(entries);
 assert.equal(total.value,645);assert.equal(total.change,45);assert.equal(total.changePercent,7.5);
 assert.equal(total.purchaseGain,165);assert.equal(total.purchaseGainPercent,34.375);
 const selected=calculateLatestContribution([healthy],entries);
 assert.equal(selected.change,30);assert.equal(selected.referenceBaseline,600);assert.equal(selected.changePercent,5);
 const now=Date.parse('2026-10-22T07:30:00Z');
 const active=selectHoldingSections(entries,now,'active');
 assert.deepEqual(active.visibleEntries.map(e=>e.holding.id),['healthy']);
 assert.equal(calculatePortfolio(active.entries).value,645,'Full portfolio value still includes the retained holding');
 for(const mode of ['all','purchase']){
  const view=selectHoldingSections(entries,now,mode);
  assert.equal(view.visibleEntries.length,2);assert.equal(calculatePortfolio(view.visibleEntries).value,645);
 }
 assert.equal(calculateHolding({...holding,quantity:'6',averageCost:'90'},instrument,entry.quote).purchaseGain,90,'Stored prices use current units and costs after editing');
 assert.equal(entry.quote.date,'2026-10-21');assert.equal(entry.quote.observedAt,null);
});
