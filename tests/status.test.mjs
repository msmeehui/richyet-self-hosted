import test from 'node:test';
import assert from 'node:assert/strict';
import {normalizeStatus,renderStatus} from '../public/lib/status.mjs';
import {formatPriceTime} from '../public/lib/dates.mjs';
import {statusFixture} from './fixtures/status.mjs';

test('status distinguishes cache writes, collector runs, source timestamps and dated closes',()=>{
  const raw=statusFixture();
  raw.holdings[1].observedAt='2026-10-12T20:00:00Z'; // Even accidental calendar data must not imply a quote instant.
  raw.holdings[2].observedAt='2026-10-09T18:00:00Z';
  const html=renderStatus(normalizeStatus(raw));
  assert.match(html,/Server cache saved/);
  assert.match(html,/does not necessarily mean a provider was called or a price changed/);
  assert.ok(html.indexOf('API usage') < html.indexOf('Investment sources'));
  assert.ok(html.includes('Price observation · '+formatPriceTime('2026-10-13T11:45:00Z')));
  assert.match(html,/Closing price · 12 Oct 2026/);
  assert.match(html,/Fund value · 9 Oct 2026/);
  assert.ok(!html.includes(formatPriceTime('2026-10-12T20:00:00Z')));
  assert.ok(!html.includes(formatPriceTime('2026-10-09T18:00:00Z')));
  assert.match(html,/Source publication/);
  assert.match(html,/portfolio can keep an earlier complete price and comparison/);
  assert.match(html,/Last update in your portfolio records when newer price information arrived/);
  assert.match(html,/Source retrieval recorded/);
});

test('usage shows recorded zero honestly without inventing hourly counters or provider balance',()=>{
  const raw=statusFixture();raw.providers[0].providerRemaining=999;
  const result=normalizeStatus(raw),html=renderStatus(result);
  assert.equal(result.providers[0].providerRemaining,null);
  assert.match(html,/<strong>0 \/ 300<\/strong>/);
  assert.match(html,/Recorded previous hour<\/dt><dd>8/);
  assert.match(html,/Recorded today \(UTC\)<\/dt><dd>24/);
  assert.match(html,/Provider balance<\/dt><dd>Unknown/);
  assert.match(html,/Window started/);
  assert.match(html,/Remaining under RichYet’s limit/);
  assert.doesNotMatch(html,/Recorded this hour/); // Already shown in hourly headline; daily ledger has none.
  assert.doesNotMatch(html,/999/);
  assert.match(html,/https:\/\/eodhd.com\/cp\/api/);
});

test('unknown, invalid or absent fields cannot become zero counters or invented dates',()=>{
  const raw=statusFixture();
  Object.assign(raw.providers[0],{used:'0',remaining:-1,internalLimit:1.5,currentHourUsed:'0',todayUsed:undefined,resetAt:'2026-02-30T01:00:00Z'});
  Object.assign(raw.holdings[0],{priceDate:'2026-02-30',observedAt:'2026-10-13T25:00:00Z',baselineDate:'2026-10-12T00:00:00Z'});
  const value=normalizeStatus(raw);
  assert.equal(value.providers[0].used,null);assert.equal(value.providers[0].remaining,null);
  assert.equal(value.providers[0].todayUsed,null);assert.equal(value.providers[0].resetAt,null);
  assert.equal(value.holdings[0].priceDate,null);assert.equal(value.holdings[0].observedAt,null);
  assert.equal(value.holdings[0].baselineDate,null);
  assert.match(renderStatus(value),/<strong>Unknown \/ Unknown<\/strong>/);
  for(const bad of [null,{...raw,schemaVersion:2},{...raw,checkedAt:'tomorrow'},{...raw,holdings:null}])assert.throws(()=>normalizeStatus(bad));
});

test('status escapes source text, drops private extras and only links official public URLs',()=>{
  const raw=statusFixture();
  raw.holdings[0].name='<img src=x onerror=alert(1)>';
  raw.holdings[0].quantity='PRIVATE_QUANTITY';raw.holdings[0].apiKey='PRIVATE_API_KEY';
  raw.providers[0].providerAllowance.url='https://docs.alpaca.markets/docs?api_key=PRIVATE_KEY';
  raw.providers[1].providerAllowance.url='https://eodhd.com@attacker.example/';
  const value=normalizeStatus(raw),html=renderStatus(value);
  assert.doesNotMatch(JSON.stringify(value),/PRIVATE_/);
  assert.doesNotMatch(html,/<img|api_key|attacker.example/);
  assert.match(html,/&lt;img src=x onerror=alert\(1\)&gt;/);
  assert.equal(value.providers[0].providerAllowance.url,null);
  assert.equal(value.providers[1].providerAllowance.url,null);
});

test('paused collection, genuine missing data and empty source lists remain explicit',()=>{
  const raw=statusFixture();raw.collectionPausedUntil='2026-10-14T00:00:00Z';
  raw.manualRefresh.enabled=true; // Never create a provider-refresh action from response content.
  Object.assign(raw.holdings[0],{priceAvailable:false,baselineAvailable:false,sourceAvailable:false,status:'stale'});
  const value=normalizeStatus(raw),html=renderStatus(value);
  assert.equal(value.manualRefresh.enabled,false);assert.match(html,/Price collection is paused/);
  assert.match(html,/Stored price unavailable/);assert.match(html,/Stored comparison price unavailable/);assert.match(html,/source check did not complete/);
  assert.match(renderStatus(normalizeStatus({...raw,holdings:[]})),/No investments to show/);
});

test('retained source expiry is explicit while missing observation times do not imply a close',()=>{
  const raw=statusFixture();
  Object.assign(raw.holdings[0],{observedAt:null,status:'stale',freshUntil:'2026-10-13T11:00:00Z',freshUntilPassed:true});
  raw.providers[0].previousDayUsed=41;
  const value=normalizeStatus(raw),html=renderStatus(value);
  assert.equal(value.holdings[0].freshUntilPassed,true);
  assert.match(html,/older than expected/);assert.match(html,/Price dated · 13 Oct 2026/);
  assert.doesNotMatch(html,/Closing price · 13 Oct 2026/);
  assert.match(html,/Recorded previous day \(UTC\)<\/dt><dd>41/);
});


test('status uses the account number format without changing counters or date wording',()=>{
  const raw=statusFixture();raw.providers[0].used=1234;raw.providers[0].internalLimit=12345;
  const status=normalizeStatus(raw);
  assert.match(renderStatus(status),/<strong>1\.234 \/ 12\.345<\/strong>/);
  assert.match(renderStatus(status,{numberFormat:'en-GB'}),/<strong>1,234 \/ 12,345<\/strong>/);
  assert.equal(renderStatus(status,{numberFormat:'unsupported'}),renderStatus(status));
  assert.equal(status.providers[0].used,1234);
});
