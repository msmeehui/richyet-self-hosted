import test from 'node:test';
import assert from 'node:assert/strict';
import {purchaseEstimateDetails} from '../public/lib/purchase-estimates.mjs';
import {calculateHolding,calculatePortfolio} from '../public/lib/portfolio.mjs';

const now=Date.parse('2026-10-21T12:00:00Z');
const reporting=currency=>({currency,nowMs:now,fx:{series:'ecb-reference-daily',base:'EUR',status:'ready',
  current:{date:'2026-10-20',rates:{USD:'1.25',GBP:'0.75'}},history:[{date:'2026-10-19',rates:{USD:'1.2',GBP:'0.8'}}],
  fetchedAt:'2026-10-20T16:00:00Z',freshUntil:'2026-10-21T18:00:00Z'}});
const entry=(id,currency,holding={})=>({instrument:{id,name:'Fictional '+id,currency,type:'Stock'},
  holding:{id:'position-'+id,instrumentId:id,quantity:'2',averageCost:'50',eurCost:null,...holding},
  quote:{status:'closed',date:'2026-10-20',previousDate:'2026-10-19',price:'100',previousClose:'90',fx:'0.8',previousFx:'0.8'}});

test('EUR estimate list names only available missing-euro-cost estimates in saved order',()=>{
  const rows=[entry('Euro','EUR'),entry('Dollar','USD'),entry('Known','USD',{eurCost:'90'}),
    entry('Unknown','USD',{averageCost:null}),entry('Zero','USD',{averageCost:'0'}),entry('Unavailable','USD')];
  rows.at(-1).quote.price=null;
  const before=structuredClone(rows),details=purchaseEstimateDetails(rows,reporting('EUR'));
  assert.deepEqual(details.map(item=>item.id),['position-Dollar','position-Zero']);
  assert.match(details[0].reason,/original euro cost is missing/);
  assert.match(details[0].reason,/US dollars to euros/);
  rows[1].holding.eurCost='0';
  assert.deepEqual(purchaseEstimateDetails(rows,reporting('EUR')).map(item=>item.id),['position-Zero'],'a recorded zero cost is not missing');
  rows[1].holding.eurCost=null;assert.deepEqual(rows,before);
});

test('list follows reporting-currency calculations and handles recorded euro-only costs accurately',()=>{
  const rows=[entry('Euro','EUR'),entry('Dollar','USD',{eurCost:'90'}),entry('Pound','GBP'),
    entry('Euro-only','USD',{averageCost:null,eurCost:'80'})];
  const before=structuredClone(rows);
  for(const currency of ['EUR','USD','GBP']) {
    const context=reporting(currency),details=purchaseEstimateDetails(rows,context);
    const affected=rows.filter(({holding,instrument,quote})=>calculateHolding(holding,instrument,quote,context).purchaseGainEstimated);
    assert.deepEqual(details.map(item=>item.id),affected.map(({holding})=>holding.id));
    assert.equal(details.length>0,calculatePortfolio(rows,context).purchaseGainEstimated);
  }
  const dollars=purchaseEstimateDetails(rows,reporting('USD'));
  assert.deepEqual(dollars.map(item=>item.id),['position-Euro','position-Pound','position-Euro-only']);
  assert.match(dollars[0].reason,/average purchase price is in euros/);
  assert.match(dollars[2].reason,/Only an original euro cost is recorded/);
  assert.doesNotMatch(dollars.map(item=>item.reason).join(' '),/euro cost is missing/);
  assert.deepEqual(purchaseEstimateDetails(rows,{...reporting('GBP'),fx:null}),[],'missing conversion is unavailable, not an estimate');
  assert.deepEqual(rows,before);
});
