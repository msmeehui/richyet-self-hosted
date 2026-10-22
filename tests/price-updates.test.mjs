import test from 'node:test';
import assert from 'node:assert/strict';
import {latestPriceUpdate,priceUpdateLabel,priceUpdateTime,priceUpdateReceivedLabel} from '../public/lib/price-updates.mjs';

const now=Date.parse('2026-10-15T12:30:00Z');
const instruments=[{id:'oak',name:'Fictional Oak'},{id:'maple',name:'Fictional Maple'},{id:'other',name:'Other account holding'}];
const holdings=[{instrumentId:'oak'},{instrumentId:'maple'}];
const quote={price:'100',date:'2026-10-15',observedAt:'2026-10-15T11:45:00Z',status:'delayed'};
const receipt={receivedAt:'2026-10-15T12:00:03Z',observationDate:quote.date,observedAt:quote.observedAt};

test('latest receipt counts only currently owned investments and never suggests all prices updated',()=>{
  const update=latestPriceUpdate(holdings,instruments,{oak:quote,maple:quote,other:quote},
    {oak:receipt,maple:{...receipt,receivedAt:'2026-10-15T11:00:03Z'},other:{...receipt,receivedAt:'2026-10-15T12:15:00Z'}},now);
  assert.equal(priceUpdateLabel(update),'Last update');
  assert.equal(priceUpdateReceivedLabel(update,now,'Europe/Amsterdam'),'Received at 14:00');
  assert.deepEqual(update.items.map(i=>i.name),['Fictional Oak']);
  assert.equal(update.total,2);
});

test('reloading or rereading a cache does not advance the displayed receipt',()=>{
  const args=[holdings,instruments,{oak:quote,maple:quote},{oak:receipt,maple:receipt}];
  assert.equal(latestPriceUpdate(...args,now).at,latestPriceUpdate(...args,now+1800000).at);
  assert.equal(priceUpdateLabel(latestPriceUpdate(...args,now)),'Last update');
  assert.equal(priceUpdateReceivedLabel(latestPriceUpdate(...args,now),now,'Europe/Amsterdam'),'Received at 14:00');
});

test('unmatched observations, missing prices and future receipts never create update claims',()=>{
  for(const invalid of [{...receipt,observedAt:'2026-10-15T11:44:00Z'},
    {...receipt,observationDate:'2026-10-14'},{...receipt,receivedAt:'2026-10-15T13:00:00Z'},
    {...receipt,receivedAt:'not a timestamp'}]) {
    assert.equal(latestPriceUpdate(holdings,instruments,{oak:quote},{oak:invalid},now),null);
  }
  for(const invalid of [{...quote,price:null},{...quote,status:'missing'}])
    assert.equal(latestPriceUpdate(holdings,instruments,{oak:invalid},{oak:receipt},now),null);
  assert.equal(latestPriceUpdate([],instruments,{oak:quote},{oak:receipt},now),null);
});

test('date-only fund observations need the exact valuation date and show older receipt dates',()=>{
  const nav={...quote,date:'2026-10-14',observedAt:null,status:'nav'};
  const r={...receipt,receivedAt:'2026-10-14T12:00:03Z',observationDate:nav.date,observedAt:null};
  const update=latestPriceUpdate(holdings,instruments,{oak:nav},{oak:r},now);
  assert.equal(priceUpdateLabel(update),'Last update');
  assert.equal(priceUpdateReceivedLabel(update,now,'Europe/Amsterdam'),'Received on 14 Oct at 14:00');
  assert.equal(priceUpdateTime(NaN,now),'');
});

test('receipt headings distinguish today from earlier local dates, including midnight and year boundaries',()=>{
  assert.equal(priceUpdateReceivedLabel({at:Date.parse(receipt.receivedAt)},now,'Europe/Amsterdam'),'Received at 14:00');
  const midnight=Date.parse('2026-10-15T22:01:00Z');
  const beforeMidnight={at:Date.parse('2026-10-15T21:59:00Z')};
  assert.equal(priceUpdateReceivedLabel(beforeMidnight,midnight,'Europe/Amsterdam'),'Received on 15 Oct at 23:59');
  assert.equal(priceUpdateReceivedLabel(beforeMidnight,midnight,'America/New_York'),'Received at 17:59');
  const newYear=Date.parse('2026-01-01T00:05:00Z');
  const previousYear={at:Date.parse('2025-12-31T22:59:00Z')};
  assert.equal(priceUpdateReceivedLabel(previousYear,newYear,'Europe/Amsterdam'),'Received on 31 Dec 2025 at 23:59');
  // Two UTC years can still be the same local day.
  const localToday={at:Date.parse('2025-12-31T23:30:00Z')};
  assert.equal(priceUpdateReceivedLabel(localToday,newYear,'Europe/Amsterdam'),'Received at 00:30');
  for(const invalid of [null,{at:NaN},{at:now+1}]) assert.equal(priceUpdateReceivedLabel(invalid,now,'Europe/Amsterdam'),'');
});


test('filtering receipts uses the visible holdings for both the count and the receipt time',()=>{
  const quotes={oak:quote,maple:quote},receipts={oak:{...receipt,receivedAt:'2026-10-15T12:10:00Z'},maple:receipt};
  const all=latestPriceUpdate(holdings,instruments,quotes,receipts,now);
  const visible=latestPriceUpdate([holdings[1]],instruments,quotes,receipts,now);
  assert.equal(all.items[0].name,'Fictional Oak');
  assert.equal(visible.items[0].name,'Fictional Maple');
  assert.equal(visible.at,Date.parse(receipt.receivedAt));assert.ok(visible.at<all.at);
  assert.equal(visible.count,1);assert.equal(visible.total,1);
  assert.equal(visible.items[0].observedAt,quote.observedAt);
  assert.equal(visible.items[0].observationDate,quote.date);
  assert.equal(latestPriceUpdate([],instruments,quotes,receipts,now),null);
});
