<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
set_error_handler(static function(int $severity,string $message,string $file,int $line):never{throw new ErrorException($message,0,$severity,$file,$line);});
require dirname(__DIR__).'/server/bootstrap.php';
require dirname(__DIR__).'/server/Maintenance.php';
require dirname(__DIR__).'/server/MarketRefresh.php';
use RichYet\{Backend,HttpError,ReportingStore,ReportingFx};
$checks=0;
function check(bool $value,string $why):void{global $checks;if(!$value)throw new RuntimeException('FAIL '.$why);$checks++;echo "PASS $why\n";}
function reject(callable $work,string $why):void{try{$work();}catch(Throwable){check(true,$why);return;}throw new RuntimeException('FAIL '.$why);}
function at(string $value):int{return (new DateTimeImmutable($value))->getTimestamp();}
function fixture():PDO{$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$db->exec(file_get_contents(__DIR__.'/backend-schema.sqlite.sql'));return $db;}
function xml(array $days):string{$body='';foreach($days as $d=>$r){$body.='<Cube time="'.$d.'">';foreach($r as $c=>$v)$body.='<Cube currency="'.$c.'" rate="'.$v.'"/>';$body.='</Cube>';}return '<?xml version="1.0"?><gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01" xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref"><gesmes:subject>Reference rates</gesmes:subject><gesmes:Sender><gesmes:name>European Central Bank</gesmes:name></gesmes:Sender><Cube>'.$body.'</Cube></gesmes:Envelope>';}
$now=at('2026-10-15T10:00:00Z');$rates=['2026-10-14'=>['USD'=>'1.12340','GBP'=>'0.85670'],'2026-10-13'=>['USD'=>'1.11000','GBP'=>'0.85000']];$feed=xml($rates);
$data=ReportingFx::parse($feed,$now);
check($data['current']['rates']===['USD'=>'1.1234','GBP'=>'0.8567']&&count($data['history'])===2,'ECB exact decimals and two currencies survive parsing without floats');
check(ReportingFx::parse(str_replace('"',"'",$feed),$now)===$data,'Official single-quoted serialization is accepted');
check($data['freshUntil']==='2026-10-15T16:00:00Z'&&$data['status']==='ready','Previous daily fixing remains valid until next publication deadline');
check(ReportingFx::freshUntil('2026-10-16')===at('2026-10-19T16:00:00Z'),'Weekend deadline is next ECB business day');
check(ReportingFx::freshUntil('2026-04-02')===at('2026-04-07T16:00:00Z'),'Good Friday and Easter Monday do not create false expiry');
check(ReportingFx::freshUntil('2026-12-24')===at('2026-12-28T17:00:00Z'),'Christmas and winter timezone are accounted for');
foreach([
    str_replace('2026-10-14','2026-10-16',$feed),
    str_replace('2026-10-13','2026-10-14',$feed),
    str_replace('<Cube currency="GBP" rate="0.85670"/>','',$feed),
    str_replace('rate="0.85670"','rate="0"',$feed),
    str_replace('rate="0.85670"','rate="NaN"',$feed),
    str_replace('<Cube currency="USD" rate="1.12340"/>','<Cube currency="USD" rate="1.12340"/><Cube currency="USD" rate="2"/>',$feed),
    str_replace('<Cube time="2026-10-14">','<Cube xmlns="https://other.test" time="2026-10-14">',$feed),
    str_replace('time="2026-10-14"',"time='2026-10-14\"",$feed),
    str_replace('<Cube>','<!DOCTYPE root [<!ENTITY x SYSTEM "file:///test">]><Cube>',$feed),
    substr($feed,0,-15),str_repeat('x',ReportingFx::MAX_BYTES+1),
] as $bad)reject(fn()=>ReportingFx::parse($bad,$now),'Unsafe or incomplete reference document is rejected');
reject(fn()=>ReportingFx::parse($feed,$now+8*86400),'Old source does not become fresh because it was downloaded now');
$db=fixture();$store=new ReportingStore($db);check($store->currency(1)==='EUR'&&$store->fx($now)['status']==='missing','Unmigrated read defaults to EUR without changing tables');
$tables=$db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll();ReportingStore::initialize($db);ReportingStore::initialize($db);$store=new ReportingStore($db);
check(count($db->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll())===count($tables)+2,'Migration adds exactly two tables and is idempotent');
$app=new Backend($db,str_repeat('x',64),$now);$obj=static fn(array $a=[]):stdClass=>(object)$a;
$request=static fn(string $action,string $method,array $input=[],?array $s=null)=>$app->handle($action,$method,$obj($input),$s['cookie']??null,$s['body']['csrfToken']??'','127.0.0.1');
$anon=$request('session','GET');reject(fn()=>$request('settings','GET',[],$anon),'Anonymous settings cannot expose or change preferences');
$token=$app->invite('one@example.test',true);$one=$request('password','POST',['token'=>$token,'password'=>'Disposable fictional 2026!'],$anon);
$anon=$request('session','GET');$token=$app->invite('two@example.test',true);$two=$request('password','POST',['token'=>$token,'password'=>'Disposable fictional 2026!'],$anon);
$positions=$db->query('SELECT * FROM portfolios ORDER BY owner_id')->fetchAll();
check($request('settings','GET',[],$one)['body']===['currency'=>'EUR','numberFormat'=>'nl-NL'],'New account defaults to EUR and Dutch formatting');
foreach(['USD','GBP','EUR'] as $currency)check($request('settings','PUT',['currency'=>$currency],$one)['body']['currency']===$currency,'Supported currency setting persists');
$request('settings','PUT',['currency'=>'GBP'],$one);
check($request('settings','GET',[],$two)['body']['currency']==='EUR','One owner cannot change another owner setting');
$second=new Backend($db,str_repeat('x',64),$now+1);check($second->handle('settings','GET',$obj(),$one['cookie'],'','127.0.0.1')['body']['currency']==='GBP','Setting survives another device session/backend instance');
check($request('session','GET',[],$one)['body']['reportingCurrency']==='GBP','Session exposes the saved reporting choice');
reject(fn()=>$request('settings','PUT',['currency'=>'JPY'],$one),'Unsupported currencies reject');
reject(fn()=>$request('settings','PUT',['currency'=>'USD','ownerId'=>2],$one),'Client-supplied owner identity rejects');
reject(fn()=>$app->handle('settings','PUT',$obj(['currency'=>'USD']),$one['cookie'],'','127.0.0.1'),'Currency update requires CSRF');
check($positions===$db->query('SELECT * FROM portfolios ORDER BY owner_id')->fetchAll(),'Settings preserve every holding record and portfolio revision');
$calls=0;$download=static function()use(&$calls,$feed):string{$calls++;return $feed;};
check($store->collectFx($now,$download)['requested']&&$calls===1,'First collector FX read uses one bounded request');
check(!$store->collectFx($now+600,$download)['requested']&&$calls===1,'Repeated ordinary collections reuse the shared daily cache');
check((new ReportingStore($db))->fx($now)['current']===$data['current'],'Another account/server reader uses identical shared rates without fetching');
check((new ReportingStore($db))->collectFx(at('2026-10-15T14:10:00Z'),$download)['requested']&&$calls===2,'Publication window triggers a bounded recheck');
check($store->fx(at('2026-10-15T16:00:01Z'))['status']==='stale','Expired fixing is explicitly stale');
$failed=static function():never{throw new RuntimeException('Fictional offline');};
$store->collectFx(at('2026-10-15T15:10:00Z'),$failed);$store->collectFx(at('2026-10-15T16:10:00Z'),$failed);
check(!$store->collectFx(at('2026-10-15T17:10:00Z'),$download)['requested']&&$calls===2,'Failures spend the same four-attempt daily allowance');
check($store->fx($now)['current']===$data['current'],'Failure retains dated cached reference values');
check((int)$db->query('SELECT COUNT(*) FROM market_usage')->fetchColumn()===0,'FX reads do not consume existing security-provider budgets');
$i=['id'=>'test_eur','symbol'=>'TEST','isin'=>'NL0000000001','mic'=>'XAMS','currency'=>'EUR','type'=>'Stock','provider'=>'euronext','providerConfig'=>['identityConfirmed'=>true]];
$q=['listingId'=>'test_eur','isin'=>'NL0000000001','mic'=>'XAMS','currency'=>'EUR','price'=>'100','date'=>'2026-10-15','observedAt'=>'2026-10-15T09:00:00.123456789Z','status'=>'delayed','sourceAvailable'=>true];
$store->recordUpdates([$i],['test_eur'=>$q],['test_eur'=>$q],$now);
check((array)$store->updates([$i],['test_eur'=>$q],$now)===[],'Migrating existing quote creates no invented receipt time');
$new=$q;$new['observedAt']='2026-10-15T09:30:00.123456789Z';$store->recordUpdates([$i],['test_eur'=>$q],['test_eur'=>$new],$now);
$updates=(array)$store->updates([$i],['test_eur'=>$new],$now);
check($updates['test_eur']['receivedAt']==='2026-10-15T10:00:00Z'&&$updates['test_eur']['observedAt']===$new['observedAt'],'Same numerical price with newer source observation records genuine receipt');
$store->recordUpdates([$i],['test_eur'=>$new],['test_eur'=>$new],$now+600);
check((array)$store->updates([$i],['test_eur'=>$new],$now+600)===$updates,'Repeated cache saves do not move receipt time');
$correction=$new;$correction['price']='101';$store->recordUpdates([$i],['test_eur'=>$new],['test_eur'=>$correction],$now+1200);
check((array)$store->updates([$i],['test_eur'=>$correction],$now+1200)===$updates,'Same-timestamp correction does not pretend to be a newer observation');
$store->recordUpdates([$i],['test_eur'=>$new],['test_eur'=>$q],$now+1800);
check((array)$store->updates([$i],['test_eur'=>$q],$now+1800)===[],'Regressed quote cannot borrow a newer receipt');
$wrong=$i;$wrong['isin']='NL0000000002';check((array)$store->updates([$wrong],['test_eur'=>$new],$now)===[],'Different listing cannot reuse receipt');
$future=$new;$future['observedAt']='2026-10-15T12:00:00Z';$store->recordUpdates([$i],['test_eur'=>$new],['test_eur'=>$future],$now);check((array)$store->updates([$i],['test_eur'=>$future],$now)===[],'Future observation cannot create a receipt');
$failedQuote=$new;$failedQuote['sourceAvailable']=false;$store->recordUpdates([$i],['test_eur'=>$new],['test_eur'=>$failedQuote],$now+3600);check((array)$store->updates([$i],['test_eur'=>$failedQuote],$now+3600)===$updates,'Source failure preserves original received time without advertising a new update');
$nano=$new;$nano['observedAt']='2026-10-15T09:30:00.123456790Z';$store->recordUpdates([$i],['test_eur'=>$new],['test_eur'=>$nano],$now+3600);check(((array)$store->updates([$i],['test_eur'=>$nano],$now+3600))['test_eur']['receivedAt']==='2026-10-15T11:00:00Z','Distinct sub-second source observations keep their ordering');
check($positions===$db->query('SELECT * FROM portfolios ORDER BY owner_id')->fetchAll(),'Rate and receipt collection never modify personal holdings');
$backup=RichYet\Maintenance::backup($db);check($backup['schemaVersion']===5&&count($backup['reportingSettings'])===1&&count($backup['reportingState'])===2,'Backup includes owner settings and original dated rate/receipt evidence');
$restored=fixture();RichYet\Maintenance::restore($restored,$backup);$after=RichYet\Maintenance::backup($restored);
check($after['reportingSettings']===$backup['reportingSettings']&&$after['reportingState']===$backup['reportingState']&&$after['portfolios']===$backup['portfolios'],'Empty-target restore preserves settings, FX dates, receipt times and exact holdings');
$restoredStore=new ReportingStore($restored);$called=false;$fake=static function()use(&$called,$feed):string{$called=true;return $feed;};$restoredStore->collectFx(time(),$fake);check(!$called,'Restored shared FX respects existing current-day restore guard');
$invalid=$backup;$invalid['reportingSettings'][0]['owner_id']=99;reject(fn()=>RichYet\Maintenance::restore(fixture(),$invalid),'Restore rejects setting belonging to an unknown account');
$invalid=$backup;$invalid['reportingSettings'][0]['currency']='JPY';reject(fn()=>RichYet\Maintenance::restore(fixture(),$invalid),'Restore rejects unsupported reporting currency');
$oldBackup=$backup;$oldBackup['schemaVersion']=4;unset($oldBackup['reportingSettings'],$oldBackup['reportingState']);$oldTarget=fixture();ReportingStore::initialize($oldTarget);RichYet\Maintenance::restore($oldTarget,$oldBackup);
check((new ReportingStore($oldTarget))->currency(1)==='EUR','Pre-feature backup restores with EUR default and no invented settings');
$occupied=fixture();ReportingStore::initialize($occupied);$occupied->prepare('INSERT INTO reporting_state(state_key,value_json,updated_at) VALUES(?,?,?)')->execute(['fx','{}',$now]);reject(fn()=>RichYet\Maintenance::restore($occupied,$oldBackup),'Restore refuses leftover reporting rows instead of reusing another account context');
check((int)$occupied->query('SELECT COUNT(*) FROM users')->fetchColumn()===0,'Reporting-state restore rejection leaves target users empty');

// Exercise the actual collector wrapper against a fictional daily source.
$db2=fixture();ReportingStore::initialize($db2);$db2->prepare('INSERT INTO users(id,email,password_hash,created_at) VALUES(?,?,NULL,?)')->execute([1,'collector@example.test',$now]);$db2->prepare('INSERT INTO portfolios(owner_id,revision,holdings_json,updated_at) VALUES(?,0,?,?)')->execute([1,'[]',$now]);
$fund=['id'=>'test_fund','name'=>'Fictional Fund','symbol'=>'TST','isin'=>'NL0000000003','mic'=>null,'exchange'=>'Fund','currency'=>'EUR','type'=>'Fund','provider'=>'bnd','providerConfig'=>['identityConfirmed'=>true,'fundId'=>3],'coverage'=>['price'=>true,'comparison'=>true]];
$market=new RichYet\MarketStore($db2,'collector@example.test',$now);$snapshot=RichYet\MarketStore::emptySnapshot([$fund]);$db2->prepare('INSERT INTO market_accounts(owner_id,registry_json,snapshot_json,state_json,updated_at) VALUES(?,?,?,?,?)')->execute([1,json_encode([$fund]),json_encode($snapshot),'{}',$now]);
$sourceCalls=0;$fundDate='2026-10-14';$get=static function(string $url,int $bytes)use(&$sourceCalls,&$fundDate):string{$sourceCalls++;if(str_ends_with($url,'/factsheetdata'))return json_encode(['fundId'=>3,'basicCharacteristics'=>['isin'=>'NL0000000003']]);if(str_contains($url,'/fundrates?'))return json_encode(['fundId'=>3,'rates'=>[['date'=>$fundDate,'nav'=>'101.12'],['date'=>'2026-10-13','nav'=>'100.10']]]);throw new RuntimeException('Unexpected fictional URL');};
$fxCalls=0;$fxDownload=static function()use(&$fxCalls,$feed):string{$fxCalls++;return $feed;};
RichYet\MarketRefresh::run($market,1,$get,$now,$fxDownload);$first=$market->market(1);$firstUpdates=(array)$first['priceUpdates'];
check($first['reportingFx']['status']==='ready'&&$fxCalls===1&&isset($firstUpdates['test_fund']),'Ordinary collector integration supplies shared FX and accepted NAV receipt');
$sourceBefore=$sourceCalls;$fxBefore=$fxCalls;$stateBefore=$db2->query('SELECT * FROM reporting_state ORDER BY state_key')->fetchAll();$market->market(1);$market->market(1);
check($sourceBefore===$sourceCalls&&$fxBefore===$fxCalls&&$stateBefore===$db2->query('SELECT * FROM reporting_state ORDER BY state_key')->fetchAll(),'Repeated authenticated price reads do not fetch or rewrite reporting state');
RichYet\MarketRefresh::run($market,1,$get,$now+600,$fxDownload);$second=(new RichYet\MarketStore($db2,'collector@example.test',$now+600))->market(1);
check((array)$second['priceUpdates']===$firstUpdates&&$fxCalls===1,'Repeated automatic refresh preserves original received time and uses shared FX');
$fundDate='2026-10-15';RichYet\MarketRefresh::run($market,1,$get,$now+7200,$fxDownload);$third=(new RichYet\MarketStore($db2,'collector@example.test',$now+7200))->market(1);
check(((array)$third['priceUpdates'])['test_fund']['observationDate']==='2026-10-15'&&((array)$third['priceUpdates'])['test_fund']['receivedAt']!==$firstUpdates['test_fund']['receivedAt'],'New accepted NAV automatically advances received date/time');
check((int)$db2->query('SELECT revision FROM portfolios')->fetchColumn()===0&&$db2->query('SELECT holdings_json FROM portfolios')->fetchColumn()==='[]','Automatic reporting collection preserves holdings and revision');
echo "$checks reporting checks passed\n";
