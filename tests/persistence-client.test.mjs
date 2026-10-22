import test from 'node:test';
import assert from 'node:assert/strict';
import {PortfolioClient, validateHoldings} from '../public/lib/persistence.mjs';

const initial = [{id:'position-1', instrumentId:'world', quantity:'12.123456', averageCost:'99.010001', eurCost:null}];
const result = (data, status = 200) => ({ok:status >= 200 && status < 300, status, json:async () => structuredClone(data)});
const snapshot = (holdings = initial, revision = 1) => ({holdings, revision, dataMode:'fictional', updatedAt:'2026-10-10T10:00:00Z'});
const session = {authenticated:true, user:{email:'fictional@example.test'}, csrfToken:'test-csrf', dataMode:'fictional'};
const clientWith = fetchImpl => new PortfolioClient({instrumentIds:['world', 'oak'], fetchImpl});

test('reporting preference defaults to EUR and saves separately without resubmitting holdings', async()=>{
  const calls=[];
  const client=clientWith(async(url,options)=>{calls.push({url,options});return result({currency:'GBP'});});
  client.acceptSession(session);
  client.snapshot=snapshot();
  const before=structuredClone(client.snapshot);
  assert.equal(client.reportingCurrency,'EUR');
  assert.equal(await client.saveReportingCurrency('GBP'),'GBP');
  assert.deepEqual(client.snapshot,before);
  assert.equal(calls.length,1);
  assert.match(calls[0].url,/action=settings$/);
  assert.deepEqual(JSON.parse(calls[0].options.body),{currency:'GBP'});
  assert.equal(calls[0].options.headers['X-CSRF-Token'],'test-csrf');
  client.acceptSession({...session,reportingCurrency:'USD'});
  assert.equal(client.reportingCurrency,'USD');
  client.acceptSession({...session,reportingCurrency:'JPY'});
  assert.equal(client.reportingCurrency,'EUR');
  await assert.rejects(client.saveReportingCurrency('JPY'),/Choose/);
  assert.equal(calls.length,1);
});

test('a delayed settings result cannot cross into another signed-in account',async()=>{
  let complete;
  const client=clientWith(()=>new Promise(resolve=>{complete=resolve;}));
  client.acceptSession(session);
  const save=client.saveReportingCurrency('USD');
  client.acceptSession({...session,user:{email:'different@example.test'},csrfToken:'different'});
  complete(result({currency:'USD'}));
  await assert.rejects(save,error=>error.code==='session_changed');
  assert.equal(client.reportingCurrency,'EUR');
});

test('session display names are optional, bounded text and isolated across account changes', () => {
  const client = clientWith(async () => result(session));
  client.acceptSession({...session, user:{...session.user, displayName:'  Example Owner  '}});
  assert.deepEqual(client.user, {email:session.user.email, displayName:'Example Owner'});
  client.acceptSession({...session, user:{email:'other@example.test'}, csrfToken:'other-csrf'});
  assert.deepEqual(client.user, {email:'other@example.test'});
  client.acceptSession({...session, user:{...session.user, displayName:'É'.repeat(120)}});
  assert.equal(client.user.displayName, 'É'.repeat(120));
  for (const displayName of [null, '', '   ', ['invalid'], 'É'.repeat(121), 'Example\nOwner', 'Example\u202eOwner']) {
    client.acceptSession({...session, user:{...session.user, displayName}});
    assert.deepEqual(client.user, {email:session.user.email});
  }
  client.acceptSession({...session, authenticated:false, user:{...session.user, displayName:'Never anonymous'}});
  assert.equal(client.user, null);
});

test('saving retains exact decimal strings and ordering and uses the loaded revision', async () => {
  const calls = [];
  const next = [{id:'position-2', instrumentId:'oak', quantity:'0.000001', averageCost:'0', eurCost:'0.000009'}, ...initial];
  const client = clientWith(async (url, options) => {
    calls.push({url, options});
    if (url.endsWith('session')) return result(session);
    return result(options.method === 'GET' ? snapshot() : snapshot(next, 2));
  });
  await client.session();
  await client.loadPortfolio();
  const saved = await client.savePortfolio(next);
  assert.deepEqual(saved.holdings, next);
  assert.deepEqual(JSON.parse(calls.at(-1).options.body), {revision:1, holdings:next});
  assert.equal(calls.at(-1).options.headers['X-CSRF-Token'], 'test-csrf');
  assert.equal(calls.at(-1).options.credentials, 'same-origin');
  assert.equal(calls.at(-1).options.cache, 'no-store');
  saved.holdings[0].quantity = '999';
  assert.equal(client.snapshot.holdings[0].quantity, '0.000001');
});

test('a conflicting device edit is never overwritten or retried without a reload', async () => {
  let revision = 1, puts = 0;
  const client = clientWith(async (_url, options) => {
    if (options.method === 'GET') return result(snapshot(initial, revision));
    puts++;
    return result({error:{code:'conflict', message:'Changed elsewhere'}}, 409);
  });
  await client.loadPortfolio();
  revision = 2;
  await assert.rejects(client.savePortfolio([]), error => error.status === 409);
  assert.deepEqual(client.snapshot.holdings, initial);
  assert.equal(client.snapshot.revision, 1);
  await assert.rejects(client.savePortfolio([]), /Reload/);
  assert.equal(puts, 1);
  await client.loadPortfolio();
  assert.equal(client.snapshot.revision, 2);
  assert.equal(client.needsReload, false);
});

test('a lost response may have committed: reload discovers the actual saved state', async () => {
  let stored = snapshot();
  const client = clientWith(async (_url, options) => {
    if (options.method === 'GET') return result(stored);
    stored = snapshot([], 2);
    throw new TypeError('connection dropped after commit');
  });
  await client.loadPortfolio();
  await assert.rejects(client.savePortfolio([]), /Could not reach/);
  assert.equal(client.needsReload, true);
  assert.deepEqual(client.snapshot.holdings, initial);
  assert.deepEqual((await client.loadPortfolio()).holdings, []);
  assert.equal(client.snapshot.revision, 2);
});

test('interrupted additions are never blindly retried, whether or not the server saved them', async t => {
  for (const committed of [false, true]) await t.test(committed ? 'response lost after saving' : 'connection lost before saving', async () => {
    let stored = snapshot(), puts = 0, interrupt = true;
    const added = {id:'added-once',instrumentId:'oak',quantity:'0.000001',averageCost:'17.123456',eurCost:null};
    const next = [...initial, added];
    const client = clientWith(async (_url, options) => {
      if (options.method === 'GET') return result(stored);
      puts++;
      const submitted = JSON.parse(options.body);
      assert.equal(submitted.revision, stored.revision);
      if (!interrupt || committed) stored = snapshot(submitted.holdings, stored.revision + 1);
      if (interrupt) throw new TypeError('Synthetic interrupted request');
      return result(stored);
    });
    await client.loadPortfolio();
    await assert.rejects(client.savePortfolio(next), /Could not reach/);
    assert.deepEqual(client.snapshot.holdings, initial, 'an unconfirmed addition is not presented as saved');
    assert.equal(client.saving, false);
    assert.equal(client.requests.size, 0);
    await assert.rejects(client.savePortfolio(next), /Reload/);
    assert.equal(puts, 1, 'another click cannot repeat an uncertain write');
    const actual = await client.loadPortfolio();
    assert.deepEqual(actual.holdings, committed ? next : initial);
    interrupt = false;
    if (!committed) await client.savePortfolio(next);
    assert.equal(stored.holdings.filter(h => h.id === added.id).length, 1);
    assert.deepEqual(stored.holdings, next, 'both recovery paths preserve exact values and saved order');
  });
});

test('a timed-out save remains uncertain until the actual server state is reloaded', async () => {
  const client = new PortfolioClient({instrumentIds:['world'], timeoutMs:5, fetchImpl:async (_url, options) => {
    if (options.method === 'GET') return result(snapshot());
    return new Promise((_resolve, reject) => options.signal.addEventListener('abort',
      () => reject(new DOMException('Synthetic timeout', 'AbortError')), {once:true}));
  }});
  await client.loadPortfolio();
  await assert.rejects(client.savePortfolio([]), /Could not reach/);
  assert.equal(client.needsReload, true);
  assert.equal(client.saving, false);
  assert.equal(client.requests.size, 0);
  assert.deepEqual(client.snapshot.holdings, initial);
});

test('expired sessions clear authentication and do not report a save', async () => {
  const client = clientWith(async (url, options) => {
    if (url.endsWith('session')) return result(session);
    return options.method === 'GET' ? result(snapshot()) : result({error:{code:'unauthorized'}}, 401);
  });
  await client.session();
  await client.loadPortfolio();
  await assert.rejects(client.savePortfolio([]), error => error.status === 401);
  assert.equal(client.user, null);
  assert.equal(client.snapshot.revision, 1);
  assert.equal(client.needsReload, true);
});

test('server rounding or omitted positions cannot become a confirmed save', async () => {
  const client = clientWith(async (_url, options) => result(options.method === 'GET' ? snapshot() : snapshot([{...initial[0], quantity:'12.12'}], 2)));
  await client.loadPortfolio();
  await assert.rejects(client.savePortfolio(initial), /did not confirm/);
  assert.equal(client.snapshot.holdings[0].quantity, '12.123456');
  assert.equal(client.needsReload, true);
});

test('an unreadable server response never replaces a loaded portfolio with demo data', async () => {
  let loaded = false;
  const client = clientWith(async () => {
    if (loaded) return {ok:true, status:200, json:async () => {throw new Error('HTML error page');}};
    loaded = true;
    return result(snapshot());
  });
  await client.loadPortfolio();
  await assert.rejects(client.loadPortfolio(), /unreadable/);
  assert.deepEqual(client.snapshot.holdings, initial);
});

test('duplicate, unknown and imprecise holdings fail before any request', async () => {
  const ids = new Set(['world']);
  for (const holdings of [[...initial, ...initial], [{...initial[0], instrumentId:'different-listing'}], [{...initial[0], quantity:12.123456}], [{...initial[0], quantity:'12.1234567'}]]) {
    assert.throws(() => validateHoldings(holdings, ids));
  }
});

test('a second save cannot race the first save against the same revision', async () => {
  let complete;
  const client = clientWith(async (_url, options) => options.method === 'GET' ? result(snapshot()) : new Promise(resolve => {complete = resolve;}));
  await client.loadPortfolio();
  const first = client.savePortfolio([]);
  await assert.rejects(client.savePortfolio(initial), /Wait/);
  complete(result(snapshot([], 2)));
  await first;
  assert.equal(client.snapshot.revision, 2);
});

test('live holdings retain unknown costs and unsupported listings through an exact confirmed save', async () => {
  const unknown = [{...initial[0], instrumentId:'unavailable-listing', averageCost:null}];
  const live = (holdings, revision) => ({...snapshot(holdings, revision), dataMode:'live', instruments:[]});
  let savedBody;
  const client = clientWith(async (_url, options) => {
    if (options.method === 'GET') return result(live(unknown, 3));
    savedBody = JSON.parse(options.body);
    return result(live(savedBody.holdings, 4));
  });
  assert.deepEqual((await client.loadPortfolio()).holdings, unknown);
  const next = [{...unknown[0], quantity:'7.000001'}];
  assert.deepEqual((await client.savePortfolio(next)).holdings, next);
  assert.equal(savedBody.holdings[0].averageCost, null);
  assert.throws(() => validateHoldings(unknown, new Set(['unavailable-listing'])));
});

test('a market-data outage cannot prevent saving holdings or inject fictional quotes', async () => {
  const live = (holdings, revision) => ({...snapshot(holdings, revision), dataMode:'live', instruments:[]});
  const client = clientWith(async (url, options) => {
    if (url.endsWith('market')) return result({error:{code:'unavailable'}}, 503);
    return result(options.method === 'GET' ? live(initial, 1) : live([], 2));
  });
  await client.loadPortfolio();
  await assert.rejects(client.loadMarket([]), error => error.status === 503);
  assert.equal(client.needsReload, false);
  assert.deepEqual((await client.savePortfolio([])).holdings, []);
});

test('a response cannot turn an attempted live save into a fictional portfolio', async () => {
  const client = clientWith(async (_url, options) => result(options.method === 'GET'
    ? {...snapshot(), dataMode:'live', instruments:[]}
    : snapshot([], 2)));
  await client.loadPortfolio();
  await assert.rejects(client.savePortfolio([]), /did not confirm/);
  assert.equal(client.snapshot.dataMode, 'live');
  assert.deepEqual(client.snapshot.holdings, initial);
});

test('catalogue metadata and its price pair arrive in one cache-only API request', async () => {
  const calls = [];
  const i = {id:'example-eur',name:'Example ETF',symbol:'EXMP',type:'ETF',currency:'EUR',exchange:'Example market',
    admission:{instrumentId:'example-eur',capability:'supported',status:'ready',canAdd:true,currentReady:true,latestReady:true,reasons:[]}};
  const market = {dataMode:'live',quotes:{[i.id]:{price:'102',previousClose:'100',priceKind:'close',observedAt:null,
    previousObservedAt:null,date:'2026-10-09',previousDate:'2026-10-08',status:'closed'}},issues:[]};
  const client = clientWith(async (url, options) => {
    calls.push({url,options}); return result({dataMode:'live',instruments:[i],market});
  });
  const catalogue = await client.loadInstruments();
  assert.equal(calls.length, 1);
  assert.match(calls[0].url, /action=instruments$/);
  assert.equal(calls[0].options.method, 'GET');
  assert.equal(catalogue.instruments[0].admission.canAdd, true);
  assert.equal(catalogue.market.quotes[i.id].previousClose, '100');
  assert.equal(catalogue.market.quotes[i.id].observedAt, null);
});

test('explicit pre-write admission rejection preserves the loaded revision and allows a later deliberate retry', async () => {
  let reject = true, puts = 0;
  const live = (holdings, revision) => ({...snapshot(holdings, revision),dataMode:'live',instruments:[]});
  const client = clientWith(async (_url, options) => {
    if (options.method === 'GET') return result(live([], 4));
    puts++;
    return reject ? result({error:{code:'instrument_unavailable'}}, 422) : result(live(initial, 5));
  });
  await client.loadPortfolio();
  await assert.rejects(client.savePortfolio(initial), error => error.status === 422 && error.code === 'instrument_unavailable');
  assert.equal(client.needsReload, false);
  assert.equal(client.snapshot.revision, 4);
  assert.deepEqual(client.snapshot.holdings, []);
  assert.equal(puts, 1);
  reject = false;
  assert.deepEqual((await client.savePortfolio(initial)).holdings, initial);
  assert.equal(puts, 2);
});

test('other validation failures still require reload rather than assuming a safe retry', async () => {
  const client = clientWith(async (_url, options) => options.method === 'GET' ? result(snapshot()) : result({error:{code:'invalid_request'}}, 422));
  await client.loadPortfolio();
  await assert.rejects(client.savePortfolio(initial));
  assert.equal(client.needsReload, true);
});

test('a late old-session error cannot clear a newly accepted account', async () => {
  let complete;
  const client = clientWith(async () => new Promise(resolve => {complete = resolve;}));
  client.acceptSession(session);
  const pending = client.loadInstruments();
  client.acceptSession({...session,user:{email:'second@example.test'},csrfToken:'second-csrf'});
  complete(result({error:{code:'unauthorized'}}, 401));
  await assert.rejects(pending);
  assert.equal(client.user.email, 'second@example.test');
  assert.equal(client.csrfToken, 'second-csrf');
});

test('a late portfolio response cannot install another account’s positions after an account switch', async () => {
  let complete;
  const client = clientWith(async () => new Promise(resolve => {complete = resolve;}));
  client.acceptSession(session);
  const pending = client.loadPortfolio();
  client.acceptSession({...session,user:{email:'second@example.test'},csrfToken:'second-csrf'});
  complete(result(snapshot()));
  await assert.rejects(pending, error => error.code === 'session_changed');
  assert.equal(client.snapshot, null);
  assert.equal(client.user.email, 'second@example.test');
});

test('a late session response cannot restore the earlier account identity', async () => {
  let complete;
  const client = clientWith(async () => new Promise(resolve => {complete = resolve;}));
  client.acceptSession({...session, user:{...session.user, displayName:'Example First'}});
  const pending = client.session();
  client.acceptSession({...session,user:{email:'second@example.test', displayName:'Example Second'},csrfToken:'second-csrf'});
  complete(result({...session, user:{...session.user, displayName:'Example First'}}));
  await assert.rejects(pending, error => error.code === 'session_changed');
  assert.equal(client.user.email, 'second@example.test');
  assert.equal(client.user.displayName, 'Example Second');
});

const discovered = {id:'fictional-new',name:'Fictional New ETF',symbol:'FNWE',exchange:'Example Exchange',currency:'EUR',type:'ETF',isin:'ZZ0000000001',preparationStatus:'available'};
const prepared = () => ({dataMode:'live',instruments:[{...discovered,admission:{instrumentId:discovered.id,capability:'supported',status:'ready',canAdd:true,currentReady:true,latestReady:true,reasons:[]}}],market:{dataMode:'live',quotes:{[discovered.id]:{price:'102',previousClose:'100',priceKind:'close',observedAt:null,previousObservedAt:null,date:'2026-10-09',previousDate:'2026-10-08',status:'closed'}},issues:[]}});

test('broad search safely encodes an ISIN/name/exchange query and keeps discovery separate from holdings', async () => {
  let sent;
  const client = clientWith(async (url,options) => {sent={url,options};return result({instruments:[discovered],hasMore:true,offset:20});});
  client.snapshot = snapshot();
  const found = await client.searchInstruments(' Example & ZZ0000000001 / exchange ',20);
  const params = new URL(sent.url,'https://example.test').searchParams;
  assert.equal(params.get('action'),'search');assert.equal(params.get('query'),'Example & ZZ0000000001 / exchange');assert.equal(params.get('offset'),'20');
  assert.equal(sent.options.method,'GET');assert.equal(sent.options.credentials,'same-origin');assert.equal(sent.options.cache,'no-store');assert.equal(sent.options.body,undefined);
  assert.equal(found.hasMore,true);assert.equal(found.instruments[0].preparationStatus,'available');assert.equal(found.instruments[0].canAdd,false);
  assert.deepEqual(client.snapshot,snapshot());
});

test('preparation uses authenticated CSRF POST and a coherent catalogue without saving a holding', async () => {
  let sent;
  const client = clientWith(async (url,options) => {sent={url,options};return result({status:'ready',catalogue:prepared()});});
  client.acceptSession({...session,dataMode:'live'});client.snapshot=snapshot();
  const checked = await client.prepareInstrument(discovered.id);
  assert.match(sent.url,/action=prepare$/);assert.equal(sent.options.method,'POST');assert.equal(sent.options.headers['X-CSRF-Token'],'test-csrf');
  assert.deepEqual(JSON.parse(sent.options.body),{instrumentId:discovered.id});assert.equal(checked.status,'ready');
  assert.equal(checked.catalogue.instruments[0].canAdd,true);assert.equal(checked.catalogue.market.quotes[discovered.id].previousClose,'100');
  assert.deepEqual(client.snapshot,snapshot());assert.equal(client.needsReload,false);
});

test('busy and unavailable preparation preserve the query result explanation and loaded portfolio', async () => {
  for (const status of ['busy','unavailable']) {
    const client = clientWith(async () => result({status,message:'The closing comparison is not available yet.'}));
    client.snapshot=snapshot();const checked=await client.prepareInstrument(discovered.id);
    assert.equal(checked.status,status);assert.equal(checked.catalogue,null);assert.match(checked.message,/comparison/);
    assert.deepEqual(client.snapshot,snapshot());assert.equal(client.needsReload,false);
  }
});

test('a false ready response cannot admit the requested listing or replace saved holdings', async () => {
  for (const change of [c=>{delete c.catalogue;},c=>{c.catalogue.market.quotes[discovered.id].previousClose=null;},c=>{c.catalogue.instruments[0].id='different';}]) {
    const response={status:'ready',catalogue:prepared()};change(response);
    const client=clientWith(async()=>result(response));client.snapshot=snapshot();
    await assert.rejects(client.prepareInstrument(discovered.id));assert.deepEqual(client.snapshot,snapshot());assert.equal(client.needsReload,false);
  }
});

test('a cancelled old search cannot appear after a newer query, even if transport delivers late', async () => {
  let finishOld;
  const client=clientWith(async url=>new URL(url,'https://example.test').searchParams.get('query')==='first'
    ? new Promise(resolve=>{finishOld=resolve;}) : result({instruments:[discovered],hasMore:false,offset:0}));
  const controller=new AbortController();const old=client.searchInstruments('first',0,{signal:controller.signal});
  controller.abort();const current=await client.searchInstruments('second');
  finishOld(result({instruments:[{...discovered,id:'old-result'}],hasMore:false,offset:0}));
  await assert.rejects(old,error=>error.code==='cancelled');assert.equal(current.instruments[0].id,discovered.id);
});

test('leaving preparation cancels late success without changing the saved portfolio', async () => {
  let finish;
  const client=clientWith(async()=>new Promise(resolve=>{finish=resolve;}));client.snapshot=snapshot();
  const controller=new AbortController();const pending=client.prepareInstrument(discovered.id,{signal:controller.signal});controller.abort();
  finish(result({status:'ready',catalogue:prepared()}));await assert.rejects(pending,error=>error.code==='cancelled');
  assert.deepEqual(client.snapshot,snapshot());assert.equal(client.needsReload,false);
});

test('late discovery and preparation cannot cross a changed account', async () => {
  for (const prepare of [false,true]) {
    let finish,requestSignal;
    const client=clientWith(async(_url,options)=>{requestSignal=options.signal;return new Promise(resolve=>{finish=resolve;});});
    client.acceptSession(session);
    const pending=prepare?client.prepareInstrument(discovered.id):client.searchInstruments('Fictional');
    client.acceptSession({...session,user:{email:'another@example.test'},csrfToken:'another-csrf'});
    assert.equal(requestSignal.aborted,true);
    finish(result(prepare?{status:'ready',catalogue:prepared()}:{instruments:[discovered],hasMore:false,offset:0}));
    await assert.rejects(pending,error=>error.code==='session_changed');assert.equal(client.user.email,'another@example.test');assert.equal(client.snapshot,null);
  }
});

test('preparation has its own bounded timeout and a timeout does not mark the portfolio unsafely saved', async () => {
  const client=new PortfolioClient({timeoutMs:1,prepareTimeoutMs:15,fetchImpl:async(_url,options)=>new Promise((_resolve,reject)=>{
    options.signal.addEventListener('abort',()=>reject(new DOMException('Timed out','AbortError')),{once:true});
  })});client.snapshot=snapshot();
  await assert.rejects(client.prepareInstrument(discovered.id),/Could not reach/);
  assert.equal(client.requests.size,0);assert.equal(client.needsReload,false);assert.deepEqual(client.snapshot,snapshot());
});

test('invalid discovery parameters and listing IDs fail before sending requests', async () => {
  let calls=0;const client=clientWith(async()=>{calls++;return result({});});
  for(const args of [['x',-1],['x',1.5],['x',10001],['x'.repeat(161),0]])await assert.rejects(client.searchInstruments(...args));
  for(const id of ['other/listing','',null])await assert.rejects(client.prepareInstrument(id));
  const controller=new AbortController();controller.abort();await assert.rejects(client.searchInstruments('Fictional',0,{signal:controller.signal}),error=>error.code==='cancelled');
  assert.equal(calls,0);
});

test('status capability is exact, optional and removed when the authenticated account changes',()=>{
  const client=clientWith(async()=>result(session));
  client.acceptSession({...session,user:{...session.user,canViewStatus:true}});
  assert.equal(client.user.canViewStatus,true);
  for(const canViewStatus of [false,undefined,'true',1]){
    client.acceptSession({...session,user:{email:'other@example.test',canViewStatus}});
    assert.equal(client.user.canViewStatus,undefined);
  }
});

test('status uses a single cache-only GET and cannot cross an account-generation change',async()=>{
  const calls=[];let complete;
  const client=clientWith((url,options)=>{calls.push({url,options});return new Promise(resolve=>{complete=resolve;});});
  client.acceptSession({...session,user:{...session.user,canViewStatus:true}});
  const request=client.loadStatus();
  assert.equal(calls[0].url,'./api/index.php?action=status');
  assert.equal(calls[0].options.method,'GET');assert.equal(calls[0].options.body,undefined);
  assert.equal(calls[0].options.cache,'no-store');assert.equal(calls[0].options.credentials,'same-origin');
  client.acceptSession({...session,user:{email:'other@example.test'},csrfToken:'other-token'});
  complete(result({schemaVersion:1,holdings:[],providers:[]}));
  await assert.rejects(request,error=>error.code==='session_changed');
  assert.equal(calls.length,1);
});
