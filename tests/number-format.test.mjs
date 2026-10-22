import test from 'node:test';
import assert from 'node:assert/strict';
import {normalizeNumberFormat, parseLocalizedNumber, formatDecimalInput} from '../public/lib/number-format.mjs';
import {formatMoney,formatPercent,formatQuantity,calculateHolding,calculatePortfolio} from '../public/lib/portfolio.mjs';
import {PortfolioClient} from '../public/lib/persistence.mjs';

test('number format is explicitly selected and safely defaults to Dutch',()=>{
  assert.equal(normalizeNumberFormat('en-GB'),'en-GB');
  for(const value of [undefined,null,'en-US','de-DE','Dutch','toString',['en-GB'],{toString:()=> 'en-GB'}])assert.equal(normalizeNumberFormat(value),'nl-NL');
  assert.equal(formatMoney(1234.56),'€\u00a01.234,56');
  assert.equal(formatMoney(1234.56,'EUR',{numberFormat:'en-GB'}),'€1,234.56');
  assert.equal(formatMoney(-1234.5,'USD',{numberFormat:'en-GB',fractionDigits:0,signed:true}),'-US$1,235');
  assert.equal(formatMoney(1234.5,'GBP',{numberFormat:'en-GB',fractionDigits:0,signed:true}),'+£1,235');
  assert.equal(formatPercent(12.345,{numberFormat:'en-GB'}),'+12.35%');
  assert.equal(formatPercent(-0.001,{numberFormat:'en-GB'}),'0.00%');
  assert.equal(formatPercent(100,{numberFormat:'en-GB',signed:false}),'100.00%');
  assert.equal(formatQuantity('1234.000001',{numberFormat:'en-GB'}),'1,234.000001');
  assert.equal(formatMoney(null,'EUR',{numberFormat:'en-GB'}),'—');
  assert.equal(formatPercent(null,{numberFormat:'en-GB'}),'—');
  assert.equal(formatQuantity(null,{numberFormat:'en-GB'}),'—');
});

test('English entry uses exact comma grouping and dot decimals without locale guessing',()=>{
  for(const [input,expected] of [['1,234.56','1234.56'],['1,234','1234'],['1.234','1.234'],['00012.340000','12.34'],['999999999999.123456','999999999999.123456'],['0.000001','0.000001']])
    assert.equal(parseLocalizedNumber(input,{numberFormat:'en-GB'}),expected);
  for(const input of ['1.234,56','1234,56','12,34','1 234.56','1\u00a0234.56','1e3','-1','+1','.5','1.','1.0000001','1,234.0000000','',null])
    assert.throws(()=>parseLocalizedNumber(input,{numberFormat:'en-GB'}),error=>error.code==='INVALID_NUMBER');
  assert.throws(()=>parseLocalizedNumber('0.000000',{numberFormat:'en-GB'}),/greater than zero/);
  assert.equal(parseLocalizedNumber('0.000000',{numberFormat:'en-GB',allowZero:true}),'0');
  assert.equal(parseLocalizedNumber('1,234',{numberFormat:'nl-NL'}),'1.234');
  assert.throws(()=>parseLocalizedNumber('1234.56'),/comma/);
});

test('editor prefill and reparse preserve every stored decimal digit in both formats',()=>{
  for(const value of ['999999999999.123456','0.000001','1234','1.234567','0'])for(const numberFormat of ['nl-NL','en-GB']){
    const formatted=formatDecimalInput(value,numberFormat);
    assert.equal(parseLocalizedNumber(formatted,{numberFormat,allowZero:true}),value);
  }
  assert.equal(formatDecimalInput(null,'en-GB'),'');
  assert.throws(()=>formatDecimalInput(1.23,'en-GB'),/saved number/);
  assert.throws(()=>formatDecimalInput('1,23','nl-NL'),/saved number/);
});

test('format choices leave mixed-currency value and both comparisons unchanged',()=>{
  const rows=[{holding:{id:'eur',instrumentId:'eur',quantity:'12.123456',averageCost:'90.010001',eurCost:null},instrument:{id:'eur',currency:'EUR'},quote:{price:'102.345',previousClose:'100.01',date:'2026-10-20',previousDate:'2026-10-19',status:'closed'}},
    {holding:{id:'usd',instrumentId:'usd',quantity:'5.123456',averageCost:'50.123456',eurCost:null},instrument:{id:'usd',currency:'USD'},quote:{price:'120.5',previousClose:'115.2',fx:'0.9',previousFx:'0.91',date:'2026-10-20',previousDate:'2026-10-19',status:'closed'}}];
  const before=structuredClone(rows), total=calculatePortfolio(rows);
  const results=rows.map(r=>calculateHolding(r.holding,r.instrument,r.quote));
  assert.ok(total.completeValue && total.completeChange && total.completePurchaseGain);
  for(const numberFormat of ['nl-NL','en-GB'])for(const result of [...results,total]){
    assert.notEqual(formatMoney(result.value,'EUR',{numberFormat}),'—');
    assert.notEqual(formatMoney(result.change,'EUR',{numberFormat,signed:true}),'—');
    assert.notEqual(formatMoney(result.purchaseGain,'EUR',{numberFormat,signed:true}),'—');
    assert.notEqual(formatPercent(result.changePercent,{numberFormat}),'—');
    assert.notEqual(formatPercent(result.purchaseGainPercent,{numberFormat}),'—');
  }
  assert.deepEqual(calculatePortfolio(rows),total);
  assert.deepEqual(rows,before);
});

const session={authenticated:true,user:{email:'fictional@example.test'},csrfToken:'csrf',dataMode:'live',reportingCurrency:'GBP'};
const response=(body,status=200)=>({ok:status===200,status,json:async()=>body});
test('number format saves independently of currency and holdings, with Dutch default',async()=>{
  const calls=[],client=new PortfolioClient({fetchImpl:async(url,options)=>{calls.push({url,options});return response({numberFormat:'en-GB',currency:'GBP'});}});
  client.acceptSession(session);client.snapshot={revision:7,holdings:[]};
  assert.equal(client.numberFormat,'nl-NL');
  assert.equal(await client.saveNumberFormat('en-GB'),'en-GB');
  assert.equal(client.reportingCurrency,'GBP');assert.deepEqual(client.snapshot,{revision:7,holdings:[]});
  assert.equal(calls.length,1);assert.match(calls[0].url,/action=settings$/);
  assert.deepEqual(JSON.parse(calls[0].options.body),{numberFormat:'en-GB'});
  assert.equal(calls[0].options.headers['X-CSRF-Token'],'csrf');
  for(const invalid of ['en-US',['en-GB'],{toString:()=> 'en-GB'}])await assert.rejects(client.saveNumberFormat(invalid),/Choose Dutch or English/);assert.equal(calls.length,1);
  client.acceptSession({...session,numberFormat:'en-GB'});assert.equal(client.numberFormat,'en-GB');
  client.acceptSession({...session,numberFormat:'invalid'});assert.equal(client.numberFormat,'nl-NL');
});
test('failed or unconfirmed preference saves retain the previous format',async()=>{
  for(const reply of [response({error:{message:'Try again'}},503),response({numberFormat:'nl-NL'}),response({})]){
    const client=new PortfolioClient({fetchImpl:async()=>reply});client.acceptSession(session);
    await assert.rejects(client.saveNumberFormat('en-GB'));assert.equal(client.numberFormat,'nl-NL');assert.equal(client.reportingCurrency,'GBP');
  }
});
test('late format response cannot cross an account change',async()=>{
  let finish;const client=new PortfolioClient({fetchImpl:()=>new Promise(resolve=>{finish=resolve;})});client.acceptSession(session);
  const request=client.saveNumberFormat('en-GB');
  client.acceptSession({...session,user:{email:'different@example.test'},csrfToken:'different',numberFormat:'nl-NL'});
  finish(response({numberFormat:'en-GB'}));
  await assert.rejects(request,error=>error.code==='session_changed');assert.equal(client.numberFormat,'nl-NL');
});
