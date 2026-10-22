import test from 'node:test';
import assert from 'node:assert/strict';
import {portfolioBreakdown,donutPath} from '../public/lib/portfolio-breakdown.mjs';
import {calculateHolding,calculatePortfolio} from '../public/lib/portfolio.mjs';
import {selectHoldingSections} from '../public/lib/holding-sections.mjs';

// Synthetic positions only. Allocation uses current position value, not gain,
// original cost, unit price, comparison availability or the visible daily filter.
const now=Date.parse('2026-10-15T10:00:00Z');
const context=currency=>({currency,nowMs:now,fx:{series:'ecb-reference-daily',base:'EUR',status:'ready',
  current:{date:'2026-10-14',rates:{USD:'1.25',GBP:'0.8'}},history:[],
  fetchedAt:'2026-10-15T09:00:00Z',freshUntil:'2026-10-15T16:00:00Z'}});
const entry=(id,currency,quantity,price,fx='1',extra={})=>({
  holding:{id:'position-'+id,instrumentId:id,quantity,averageCost:null,eurCost:null},
  instrument:{id,name:'Fictional '+id,symbol:id.toUpperCase(),currency,type:'Stock',
    mic:currency==='USD'?'XNAS':'XAMS',timeZone:currency==='USD'?'America/New_York':'Europe/Amsterdam',...extra},
  quote:{price,fx,previousClose:null,previousFx:null,date:'2026-10-15',status:'delayed',
    observedAt:'2026-10-15T09:45:00Z',priceKind:'trade'},
});
const three=()=>[entry('oak','EUR','2','150'),entry('maple','USD','4','125','0.8'),entry('birch','GBP','2','120','1.25')];
const near=(actual,expected,tolerance=1e-10)=>assert.ok(Math.abs(actual-expected)<=tolerance,`${actual} != ${expected}`);
function model(rows,reporting=null){
  return portfolioBreakdown(rows.map(({holding,instrument,quote})=>({id:holding.id,name:instrument.name,
    value:calculateHolding(holding,instrument,quote,reporting).value})),calculatePortfolio(rows,reporting));
}
const shares=result=>Object.fromEntries(result.items.map(item=>[item.id,item.share]));

test('breakdown uses full current values in EUR, USD and GBP without needing purchase costs or daily baselines',()=>{
  const rows=three(),before=structuredClone(rows);
  for(const [currency,total] of [['EUR',1000],['USD',1250],['GBP',800]]){
    const result=model(rows,context(currency));
    assert.equal(result.status,'ready');assert.equal(result.total,total);
    assert.deepEqual(shares(result),{'position-maple':40,'position-oak':30,'position-birch':30});
    assert.equal(result.items[0].id,'position-maple');
    near(result.items.reduce((sum,item)=>sum+item.share,0),100);
    for(const item of result.items)near(item.end-item.start,item.share/100);
    assert.equal(result.items[0].start,0);near(result.items.at(-1).end,1);
  }
  assert.deepEqual(rows,before,'allocation must not alter units, costs, identities, order or source data');
});

test('Open markets selection cannot redefine full-portfolio allocation or change saved ordering',()=>{
  const rows=three();
  for(const row of rows){row.quote.previousClose=row.quote.price;row.quote.previousDate='2026-10-14';row.quote.previousFx=row.quote.fx;}
  const before=structuredClone(rows),expected=model(rows);
  const filtered=selectHoldingSections(rows,now,'active');
  assert.ok(filtered.visibleEntries.length<rows.length,'the fixture must contain a currently closed holding');
  assert.deepEqual(model(filtered.entries),expected,'presentation keeps full entries for portfolio allocation');
  assert.deepEqual(rows,before);
  const incomplete=portfolioBreakdown(expected.items.filter(item=>item.id!=='position-maple'),calculatePortfolio(rows));
  assert.equal(incomplete.status,'unavailable','a mistakenly filtered list must not appear to be a whole portfolio');
  assert.ok(incomplete.items.every(item=>item.share===null));
});

test('missing price or required conversion never turns known positions into a misleading 100% chart',()=>{
  for(const change of [row=>{row.quote.price=null;},row=>{row.quote.fx=null;},row=>{row.quote.status='unavailable';},row=>{row.holding.instrumentId='wrong-listing';}]){
    const rows=three();change(rows[1]);const before=structuredClone(rows),result=model(rows);
    assert.equal(result.status,'unavailable');assert.equal(result.total,null);
    assert.equal(result.items.length,3);assert.ok(result.items.every(item=>item.share===null));
    assert.deepEqual(rows,before);
  }
  const staleFx=context('GBP');staleFx.fx.status='stale';
  const converted=model(three(),staleFx);
  assert.equal(converted.status,'ready');assert.ok(converted.items.every(item=>item.share!==null));
});

test('retained dated prices remain usable for allocation when comparison history alone is missing',()=>{
  const rows=three();for(const row of rows){row.quote.status='stale';row.quote.previousDate=null;row.quote.previousClose=null;}
  const total=calculatePortfolio(rows);assert.equal(total.completeChange,false);assert.equal(total.completeValue,true);
  assert.equal(model(rows).status,'ready');assert.deepEqual(shares(model(rows)),{'position-maple':40,'position-oak':30,'position-birch':30});
});

test('empty and all-zero portfolios do not invent proportions; a sole positive position is 100%',()=>{
  assert.equal(model([]).status,'empty');assert.deepEqual(model([]).items,[]);
  const zero=model([entry('zero','EUR','10','0')]);
  assert.equal(zero.status,'zero');assert.ok(zero.items.every(item=>item.share===null));
  const single=model([entry('single','EUR','0.5','0.02')]);
  assert.equal(single.status,'ready');assert.equal(single.total,0.01);assert.equal(single.items[0].share,100);
  assert.equal(single.items[0].start,0);assert.equal(single.items[0].end,1);
  const mixed=model([entry('zero','EUR','10','0'),entry('positive','EUR','10','1')]);
  assert.equal(mixed.status,'ready');assert.equal(mixed.items.find(item=>item.id==='position-zero').share,0);
  assert.equal(mixed.items.find(item=>item.id==='position-positive').share,100);
});

test('negative, nonfinite and contradictory values fail closed while tiny legitimate values remain visible',()=>{
  for(const value of [-1,NaN,Infinity,-Infinity,null,undefined,'20']){
    const result=portfolioBreakdown([{id:'a',name:'A',value},{id:'b',name:'B',value:20}],{value:40,completeValue:true});
    assert.equal(result.status,'unavailable');assert.ok(result.items.every(item=>item.share===null));
  }
  for(const total of [{value:40,completeValue:false},{value:21,completeValue:true},{value:Infinity,completeValue:true}]){
    const result=portfolioBreakdown([{id:'a',name:'A',value:20}],total);
    assert.equal(result.status,'unavailable');assert.equal(result.items[0].share,null);
  }
  const tiny=portfolioBreakdown([{id:'tiny',name:'Tiny',value:0.01},{id:'large',name:'Large',value:999999.99}],{value:1000000,completeValue:true});
  assert.equal(tiny.status,'ready');near(tiny.items.find(item=>item.id==='tiny').share,0.000001);
});

test('decimal conversion tolerances do not reject a sound complete portfolio or use rounded display values',()=>{
  const result=model([entry('small-a','EUR','0.1','0.3'),entry('small-b','EUR','0.2','0.3')]);
  assert.equal(result.status,'ready');assert.equal(result.total,0.09);
  near(result.items.find(item=>item.id==='position-small-a').share,100/3);
  near(result.items.find(item=>item.id==='position-small-b').share,200/3);
  const tinySubset=portfolioBreakdown([{id:'tiny',name:'Tiny',value:1e-16}],{value:2e-16,completeValue:true});
  assert.equal(tinySubset.status,'unavailable','a floating-point tolerance cannot excuse a missing half of a small portfolio');
});

test('many investments all keep readable identities and exact individual shares without mutating caller order',()=>{
  const items=Array.from({length:80},(_,i)=>({id:'holding-'+i,name:'Fictional long investment name '+i,value:i+1}));
  const original=structuredClone(items),total=80*81/2;
  const result=portfolioBreakdown(items,{value:total,completeValue:true});
  assert.equal(result.status,'ready');assert.equal(result.items.length,80);assert.equal(new Set(result.items.map(i=>i.id)).size,80);
  assert.equal(result.items[0].id,'holding-79');assert.equal(result.items.at(-1).id,'holding-0');
  for(const item of result.items){near(item.share,item.value/total*100);assert.match(item.color,/^(?:#[a-f\d]{3,8}|hsl\([\d.,%\s-]+\))$/i);}
  near(result.items.reduce((sum,item)=>sum+item.share,0),100);near(result.items.at(-1).end,1);
  assert.deepEqual(items,original);
});

test('large portfolios group only the smaller chart slices and preserve every exact list share',()=>{
  const items=Array.from({length:10},(_,i)=>({id:'holding-'+i,name:'Fictional '+i,value:i+1}));
  const result=portfolioBreakdown(items,{value:55,completeValue:true});
  assert.equal(result.slices.length,8);assert.equal(result.items.length,10);
  const other=result.slices.find(slice=>slice.holdingIds.length>1);
  assert.deepEqual(other.holdingIds,['holding-2','holding-1','holding-0']);
  near(other.share,6/55*100);near(other.end-other.start,6/55);
  assert.equal(new Set(result.slices.flatMap(slice=>slice.holdingIds)).size,10);
  for(const item of result.items){
    const slice=result.slices.find(slice=>slice.holdingIds.includes(item.id));
    assert.equal(item.sliceId,slice.id);assert.equal(item.color,slice.color);near(item.share,item.value/55*100);
  }
  near(result.slices.reduce((sum,slice)=>sum+slice.share,0),100);
  const eight=portfolioBreakdown(items.slice(0,8),{value:36,completeValue:true});
  assert.equal(eight.slices.length,8);assert.ok(eight.slices.every(slice=>slice.holdingIds.length===1));
  const withZero=portfolioBreakdown([...items.slice(0,8),{id:'zero',name:'Zero',value:0}],{value:36,completeValue:true});
  assert.equal(withZero.items.length,9);assert.equal(withZero.slices.length,8);assert.ok(withZero.slices.every(slice=>slice.holdingIds.length===1));
});

test('donut paths handle full and tiny slices without NaN, infinity or executable content',()=>{
  for(const [start,end] of [[0,1],[0,0.5],[0.3,0.4],[0.99999999,1]]){
    const path=donutPath(start,end);
    assert.equal(typeof path,'string');assert.ok(path.length>0);
    assert.doesNotMatch(path,/NaN|Infinity|undefined|<|>|['"]/);
    assert.match(path,/^[Mm]/);assert.match(path,/[Zz]\s*$/);
  }
  for(const [start,end] of [[0,0],[0.8,0.2],[-1,0.5],[0,2],[NaN,1],[0,Infinity]])assert.equal(donutPath(start,end),'');
});
