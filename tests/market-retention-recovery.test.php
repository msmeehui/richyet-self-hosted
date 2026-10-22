<?php
declare(strict_types=1);
// Fictional data; the same test also runs against the frozen hosting runtime.
require dirname(__DIR__).'/server/bootstrap.php';
// The ordinary API bootstraps without loading the collector/preparation code.
// Exercise that cold include graph before adding collector-only dependencies.
if(!class_exists(RichYet\EuropeanHistory::class,false))throw new RuntimeException('API retention dependency unavailable.');
require __DIR__.'/backend-mysql-isolation-d083.php';
use RichYet\{EuropeanHistory as H,EuropeanActions as A,LastValidMarket as L,MarketStore as M,InstrumentPreparation as P};
$checks=0;
function check(bool $ok,string $why):void{global $checks;if(!$ok)throw new RuntimeException('FAIL '.$why);$checks++;}
function snapshot(array $i,array $q,array $codes=[]):array{$s=M::emptySnapshot([$i]);$s['quotes'][$i['id']]=$q;$s['issues']=array_map(fn($c)=>['instrumentId'=>$i['id'],'code'=>$c],$codes);return $s;}
$i=['id'=>'fictional-amsterdam','name'=>'Fictional Amsterdam','symbol'=>'FICT','exchange'=>'Euronext Amsterdam','type'=>'Stock','currency'=>'EUR','mic'=>'XAMS','isin'=>'ZZ0000000002','timeZone'=>'Europe/Amsterdam','coverage'=>['price'=>true,'comparison'=>true],'provider'=>'euronext','providerConfig'=>['identityConfirmed'=>true,'historyProvider'=>'newton','historySymbol'=>'FICT.AS','historyIdentityConfirmed'=>true,'actionsProvider'=>'eodhd','actionsSymbol'=>'FICT.AS','actionsIdentityConfirmed'=>true,'tradingSchedule'=>'ams_j0_continuous_2026']];
$id=$i['id'];$close=strtotime('2026-10-21T16:10:00Z');$morning=strtotime('2026-10-22T07:30:00Z');
$raw=json_encode(['status'=>'200','data'=>[[(string)strtotime('2026-10-21T12:00:00Z'),'102','105','105'],[(string)strtotime('2026-10-20T12:00:00Z'),'99','100','100']]],JSON_THROW_ON_ERROR);
$history=H::refresh([$i],[],fn()=>$raw,$close);$actions=A::check($i,'2026-10-20','2026-10-21',[],fn()=>'[]',$close)['state'];
$q=$history['quotes'][$id];$seed=L::reconcile([$i],snapshot($i,$q),['europe'=>['history'=>$history['state'],'actions'=>$actions]],$close);
check(isset($seed['state']['lastValidMarket'][$id]['comparison']),'Fixture first accepts and separately retains a complete dated pair');
$missing=M::missing();$missing['eligibilityIssue']='conflicting_latest_trade_prices';
// Previously null <= absent invalidThrough compared two empty strings and
// discarded the valid set. Exercise null/empty/missing date and route fields.
foreach([[],['date'=>null],['date'=>'']] as $fields){
 $bad=$missing;foreach($fields as $k=>$v)$bad[$k]=$v;
 foreach([[],['invalidThrough'=>[]],['invalidThrough'=>[$id=>null]]] as $route){
  $state=$seed['state'];$state['europe']['euronext'][$i['providerConfig']['tradingSchedule']]=$route;
  $r=L::reconcile([$i],snapshot($i,$bad,['price_missing','baseline_missing']),$state,$morning);
  foreach(['price','previousClose','date','previousDate','observedAt','fx','previousFx'] as $field)check($r['snapshot']['quotes'][$id][$field]===$q[$field],'Absent dates/correction markers retain exact '.$field);
  check(isset($r['snapshot']['earlierData'][$id])&&isset($r['state']['lastValidMarket'][$id]['comparison']),'Unavailable candidate retains the saved pair and earlier-data explanation');
 }
}
// Recover an installation whose secondary record was already erased. Only
// authenticated positive closes and an accepted exact interval may supply it.
$lost=$seed['state'];unset($lost['lastValidMarket']);$before=$lost;
$r=L::reconcile([$i],snapshot($i,$missing,['price_missing','baseline_missing']),$lost,$morning);
$recovered=$r['snapshot']['quotes'][$id];
check($recovered['price']==='105'&&$recovered['previousClose']==='100'&&$recovered['date']==='2026-10-21'&&$recovered['previousDate']==='2026-10-20','Confirmed completed closes recover the lost secondary record');
check($recovered['status']==='stale'&&$recovered['sourceAvailable']===false&&$recovered['observedAt']===null,'Recovery neither invents a trade timestamp nor claims a fresh download');
check($recovered['freshUntil']<=$close&&$r['state']['europe']['history']===$before['europe']['history']&&$r['state']['europe']['actions']===$before['europe']['actions'],'Recovery preserves original evidence clocks, hashes and action ledger');
check(array_column($r['snapshot']['issues'],'code')===['source_unavailable'],'A recovered pair removes misleading missing-value errors');
// Persist/merge repeated failures exactly as the recurring collector does.
$native=($argv[1]??null)==='--mysql-config';
if($native){
 if($argc!==4||$argv[3]!=='--isolated-tables')throw new RuntimeException('Native test arguments refused.');
 $db=new IsolatedMysql(RichYet\loadConfig($argv[2]),'rycheck_'.bin2hex(random_bytes(8)).'_');
 register_shutdown_function(static fn()=>$db->cleanup());$db->initialize();
}else{
 $db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
 $db->exec(file_get_contents(__DIR__.'/backend-schema.sqlite.sql'));
}
$db->exec("INSERT INTO users(id,email,created_at) VALUES(1,'fictional@example.test',0)");
$held='[{"id":"held","instrumentId":"fictional-amsterdam","quantity":"3.123456","averageCost":"80.12","eurCost":null}]';
$db->prepare('INSERT INTO portfolios(owner_id,revision,holdings_json,updated_at) VALUES(1,4,?,0)')->execute([$held]);
$db->prepare('INSERT INTO market_accounts(owner_id,registry_json,snapshot_json,state_json,updated_at) VALUES(1,?,?,?,?)')->execute([json_encode([$i]),json_encode($r['snapshot']),json_encode($r['state']),$morning]);
for($n=0;$n<12;$n++){
 require_once dirname(__DIR__).'/server/InstrumentPreparation.php';
 $at=$morning+$n*600;$store=new M($db,'fictional@example.test',$at);$row=$store->account(1);
 $state=json_decode($row['state_json'],true);$r=L::reconcile([$i],snapshot($i,$missing,['price_missing','baseline_missing']),$state,$at);
 $r['state']=P::mergeState($state,P::stateFor($r['state'],$i),$i);$store->saveRefresh(1,$r['snapshot'],$r['state'],$at);
 $out=(new M($db,'fictional@example.test',$at))->market(1);$got=(array)$out['quotes'];
 check($got[$id]['price']==='105'&&$got[$id]['previousClose']==='100'&&isset($out['earlierData'][$id]),'Repeated persisted refresh/reopen keeps value and both comparison inputs');
 check($db->query('SELECT holdings_json FROM portfolios WHERE owner_id=1')->fetchColumn()===$held,'Repeated refresh never changes exact holdings/costs/order');
}
foreach(['events','identity','date-conflict','source-identity','future-confirmation','missing-unit-proof','different-unit-ratio','unknown-correction'] as $fault){
 $bad=$lost;$attempt=$missing;
 if($fault==='events')$bad['europe']['actions'][$id]['events']['2026-10-22']=true;
 if($fault==='identity')$bad['europe']['actions'][$id]['identity']=str_repeat('0',64);
 if($fault==='date-conflict'){$bad['europe']['history'][$id]['retainedInvalidDates']['2026-10-21']=$morning;unset($bad['europe']['history'][$id]['retainedCloses']['2026-10-21']);}
 if($fault==='source-identity')$bad['europe']['history'][$id]['fingerprint']=str_repeat('0',64);
 if($fault==='future-confirmation')$bad['europe']['history'][$id]['retainedCloses']['2026-10-21']['lastConfirmedAt']=$morning+1;
 if($fault==='missing-unit-proof')$bad['europe']['actions']=[];
 if($fault==='different-unit-ratio')$bad['europe']['history'][$id]['retainedCloses']['2026-10-21']['adjustedClose']='52.5';
 if($fault==='unknown-correction')$bad['europe']['euronext'][$i['providerConfig']['tradingSchedule']]['invalidThrough'][$id]='2026-10-22';
 $out=L::reconcile([$i],snapshot($i,$attempt,['price_missing','baseline_missing']),$bad,$morning);
 check($out['snapshot']['quotes'][$id]['price']===null,'Unsafe/unproven recovery stays unavailable: '.$fault);
}
// A malformed response is unavailability; concrete contradictory dates veto
// the saved values. Do not reject every history parse error as a correction.
$malformed=L::reconcile([$i],snapshot($i,array_replace($missing,['invalidations'=>['current','comparison']]),['history_invalid','price_missing','baseline_missing']),$seed['state'],$morning);
check($malformed['snapshot']['quotes'][$id]['price']==='105','Malformed history cannot erase an independently accepted pair');
$conflicted=$seed['state'];$conflicted['europe']['history'][$id]['retainedInvalidDates']['2026-10-21']=$morning;unset($conflicted['europe']['history'][$id]['retainedCloses']['2026-10-21']);
$out=L::reconcile([$i],snapshot($i,$missing,['history_invalid','price_missing']),$conflicted,$morning);
check($out['snapshot']['quotes'][$id]['price']===null,'Confirmed contradictory date still prevents stale recovery');
$corrected=$seed['state'];$corrected['europe']['history'][$id]['retainedCloses']['2026-10-21']['close']='106';$corrected['europe']['history'][$id]['retainedCloses']['2026-10-21']['adjustedClose']='106';
$corrected['europe']['history'][$id]['rows']['2026-10-21']=['close'=>'106','adjustedClose'=>'106'];
$out=L::reconcile([$i],snapshot($i,$missing,['price_missing','baseline_missing']),$corrected,$morning);
check($out['snapshot']['quotes'][$id]['price']==='106'&&$out['snapshot']['quotes'][$id]['previousClose']==='100','A positive corrected completed pair replaces the superseded saved revision even while today is unavailable');
$trade=array_replace($q,['price'=>'105.2','observedAt'=>'2026-10-21T15:29:59Z','source'=>'Euronext delayed main-session trades; Newton Analytics previous close','observationKind'=>'main_session_trade']);
$out=L::reconcile([$i],snapshot($i,$trade),$seed['state'],$morning);
check($out['snapshot']['quotes'][$id]['price']==='105.2'&&$out['snapshot']['quotes'][$id]['previousClose']==='100','A valid retained exchange trade is not rejected merely because the separate daily close differs');
// An ordinary new accepted pair replaces the old data without extra requests.
$new=array_replace($q,['price'=>'108','previousClose'=>'105','date'=>'2026-10-22','previousDate'=>'2026-10-21','observedAt'=>'2026-10-22T08:00:00Z','priceKind'=>'trade','status'=>'delayed','expectedPreviousDate'=>'2026-10-21','freshUntil'=>$morning+7200]);
$out=L::reconcile([$i],snapshot($i,$new),$r['state'],$morning+3600);
check($out['snapshot']['quotes'][$id]['price']==='108'&&$out['snapshot']['earlierData']===[],'A genuine new observation replaces earlier data and clears the routine warning');
if($native)$db->cleanup();
if(($argv[1]??null)==='--json')echo json_encode(['instrument'=>$i,'snapshot'=>snapshot($i,$recovered,['source_unavailable']),'checks'=>$checks],JSON_THROW_ON_ERROR);
else echo "Market retention recovery checks passed: $checks\n";
