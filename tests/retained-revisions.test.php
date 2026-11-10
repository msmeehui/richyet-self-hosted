<?php
declare(strict_types=1);
// Wholly fictional accepted observations and correction events. No network.
require dirname(__DIR__).'/server/bootstrap.php';
require dirname(__DIR__).'/server/EuropeanProviders.php';
require dirname(__DIR__).'/server/InstrumentPreparation.php';
use RichYet\{LastValidMarket as L,MarketStore as M,EuropeanProviders as E,EuropeanHistory as H,InstrumentPreparation as P};
$checks=0;
function check(bool $ok,string $why):void{global $checks;if(!$ok)throw new RuntimeException('FAIL '.$why);$checks++;}
function snap(array $i,array $q,array $codes=[]):array{$s=M::emptySnapshot([$i]);$s['quotes'][$i['id']]=$q;$s['issues']=array_map(fn($c)=>['instrumentId'=>$i['id'],'code'=>$c],$codes);return $s;}
$i=['id'=>'fictional-xetra','name'=>'Fictional Xetra ETF','symbol'=>'FICT','exchange'=>'Xetra','type'=>'ETF','currency'=>'EUR','mic'=>'XETA','isin'=>'ZZ0000000002','timeZone'=>'Europe/Berlin','coverage'=>['price'=>true,'comparison'=>true],'provider'=>'xetra',
    'providerConfig'=>['identityConfirmed'=>true,'venue'=>'XETA','publicationVenue'=>'XCEF','historyProvider'=>'newton','historySymbol'=>'FICT.DE','historyIdentityConfirmed'=>true]];
$id=$i['id'];$now=strtotime('2026-11-10T09:00:00Z');
function quote(array $i,string $date,string $previous,string $price,string $close):array{return ['listingId'=>$i['id'],'mic'=>$i['mic'],'currency'=>'EUR','isin'=>$i['isin'],'price'=>$price,'previousClose'=>$close,'date'=>$date,'previousDate'=>$previous,
    'observedAt'=>$date.'T16:30:00Z','previousObservedAt'=>$previous.'T16:30:00Z','priceKind'=>'close','previousKind'=>'close','status'=>'closed','source'=>'Xetra delayed trades','fx'=>'1','previousFx'=>'1','basis'=>'same_units','invalidations'=>[],'sourceAvailable'=>true];}
$older=quote($i,'2026-11-05','2026-11-04','100','98');$prior=quote($i,'2026-11-06','2026-11-05','102','100');$latest=quote($i,'2026-11-09','2026-11-06','105','102');
$state=[];
foreach([$older,$prior,$latest] as $q)$state=L::reconcile([$i],snap($i,$q),$state,$now)['state'];
check($state['lastValidMarket'][$id]['comparison']===$latest,'Latest accepted pair is primary while preceding sessions remain retained');
// The real Xetra selector rejects an entire dated session after a cancellation.
$state['europe']['xetra']['sessions'][$id]['2026-11-09']=['invalid'=>true,'trades'=>[]];
$missing=E::xetraQuote($state['europe']['xetra']['sessions'][$id],$now,false,$state['europe']['xetra'],$id);
check($missing['price']===null&&$missing['issues']===['corporate_action_unverified'],'Actual Xetra session veto supplies the missing-price candidate');
$out=L::reconcile([$i],snap($i,$missing,$missing['issues']),$state,$now);
$expected=array_replace($prior,['status'=>'stale','sourceAvailable'=>false]);
check($out['snapshot']['quotes'][$id]===$expected,'A correction on Monday recovers the complete accepted Friday/Thursday pair');
check($out['state']['lastValidMarket'][$id]['comparison']===$prior,'Recovered earlier pair keeps its original acceptance provenance');
check(array_column($out['snapshot']['issues'],'code')===['source_unavailable'],'Displayed old pair is explained as earlier data, not an unusable price');
foreach($out['state']['lastValidMarket'][$id]['history'] as $revision)check(!in_array('2026-11-09',[$revision['quote']['date'],$revision['quote']['previousDate']],true),'Affected revisions are removed from the retained archive');

// Persist through the same owner-bound store and merge used by refreshes.
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec(file_get_contents(__DIR__.'/backend-schema.sqlite.sql'));
$db->exec("INSERT INTO users(id,email,created_at) VALUES(1,'fictional@example.test',0)");
$held='[{"id":"held","instrumentId":"fictional-xetra","quantity":"3.123456","averageCost":"80.12","eurCost":null}]';
$db->prepare('INSERT INTO portfolios(owner_id,revision,holdings_json,updated_at) VALUES(1,4,?,0)')->execute([$held]);
$db->prepare('INSERT INTO market_accounts(owner_id,registry_json,snapshot_json,state_json,updated_at) VALUES(1,?,?,?,?)')->execute([json_encode([$i]),json_encode($out['snapshot']),json_encode($out['state']),$now]);
for($n=0;$n<4;$n++){
 $at=$now+$n*600;$store=new M($db,'fictional@example.test',$at);$stored=$store->account(1);$saved=json_decode($stored['state_json'],true,64,JSON_THROW_ON_ERROR);
 $out=L::reconcile([$i],snap($i,$missing,$missing['issues']),$saved,$at);
 $out['state']=P::mergeState($saved,P::stateFor($out['state'],$i),$i);$store->saveRefresh(1,$out['snapshot'],$out['state'],$at);
 $market=(new M($db,'fictional@example.test',$at))->market(1);$actual=(array)$market['quotes'];
 check($actual[$id]===$expected&&isset($market['earlierData'][$id]),'Correction fallback survives persisted repeated refresh and cache-only reopen');
 check($db->query('SELECT holdings_json FROM portfolios WHERE owner_id=1')->fetchColumn()===$held,'Fallback never rewrites units, costs, order or portfolio revision');
}
$renderSnapshot=$market;$renderSnapshot['quotes']=(array)$renderSnapshot['quotes'];
// The archive cannot override real unit, identity or date conflicts.
foreach(['split','identity','all-dates','unknown-invalidation','changed-registry'] as $fault){
 $s=$state;$attempt=$missing;$instrument=$i;
 if($fault==='split')$s['europe']['xetra']['referenceActions'][$id]['2026-11-10']=true;
 if($fault==='identity')$s['europe']['xetra']['referenceRevoked'][$id]='2026-11-10';
 if($fault==='all-dates')foreach(['2026-11-04','2026-11-05','2026-11-06'] as $d)$s['europe']['xetra']['sessions'][$id][$d]['invalid']=true;
 if($fault==='unknown-invalidation')$attempt['invalidations']=['current','comparison'];
 if($fault==='changed-registry')$instrument['providerConfig']['venue']='XETR';
 $bad=L::reconcile([$instrument],snap($instrument,$attempt,$missing['issues']),$s,$now);
 check($bad['snapshot']['quotes'][$id]['price']===null&&!isset($bad['state']['lastValidMarket'][$id]['comparison']),'Unsafe recovery remains unavailable: '.$fault);
}
// A candidate with a dated correction must not discard unrelated archived days.
$invalid=array_replace($latest,['price'=>null,'previousClose'=>null,'status'=>'missing','invalidations'=>['current']]);
$dated=L::reconcile([$i],snap($i,$invalid,['corporate_action_unverified']),$state,$now);
check($dated['snapshot']['quotes'][$id]===$expected,'A dated candidate veto is scoped to the affected saved dates');
$basisConflict=L::reconcile([$i],snap($i,$invalid,['corporate_action_unverified','history_basis_unverified']),$state,$now);
check($basisConflict['snapshot']['quotes'][$id]['price']===null,'A simultaneous unverified history basis cannot be narrowed to a local correction');
$unclassified=$state;unset($unclassified['europe']['xetra']['sessions']);
$uncertain=L::reconcile([$i],snap($i,$invalid,['corporate_action_unverified']),$unclassified,$now);
check($uncertain['snapshot']['quotes'][$id]['price']===null,'A dated but unclassified current-value veto cannot imply unaffected historical units');
$cold=L::reconcile([$i],snap($i,$missing,$missing['issues']),[],$now);
check($cold['snapshot']['quotes'][$id]['price']===null,'An installation with no accepted observations cannot fabricate a fallback');

// Earlier versions may have erased the secondary record. Retained Newton
// closes from the reviewed exact Xetra listing may use the original dated
// exchange references as unit proof; no EODHD check is invented or renewed.
$closedAt=strtotime('2026-11-06T18:00:00Z');
$raw=json_encode(['status'=>'200','data'=>[[(string)strtotime('2026-11-06T12:00:00Z'),'100','102','102'],[(string)strtotime('2026-11-05T12:00:00Z'),'98','100','100']]],JSON_THROW_ON_ERROR);
$history=H::refresh([$i],[],fn()=>$raw,$closedAt,[$id=>$prior]);
$references=[];
foreach(['2026-11-05','2026-11-06'] as $date)$references[$date]=['date'=>$date,'retrievedAt'=>$closedAt,'sha256'=>str_repeat('a',64),'rows'=>[$id=>['valid'=>true,'identity'=>'123|ZZ0000000002|XETA|Shares|EUR','indicator'=>'']]];
$lost=['europe'=>['history'=>$history['state'],'xetra'=>['references'=>$references]]];
$recovered=L::reconcile([$i],snap($i,$missing,$missing['issues']),$lost,$now);$rq=$recovered['snapshot']['quotes'][$id];
check($rq['price']==='102'&&$rq['previousClose']==='100'&&$rq['date']==='2026-11-06'&&$rq['previousDate']==='2026-11-05','Lost Xetra record recovers accepted history using its exact exchange-reference interval');
check($rq['observedAt']===null&&$rq['sourceAvailable']===false&&$rq['status']==='stale','History recovery cannot invent an auction timestamp or new download');
check($recovered['state']['europe']===$lost['europe'],'Recovery preserves source hashes, reference identity and all confirmation times');
foreach(['missing-proof','changed-units','later-action','history-identity'] as $fault){
 $s=$lost;
 if($fault==='missing-proof')unset($s['europe']['xetra']['references']['2026-11-05']);
 if($fault==='changed-units')$s['europe']['xetra']['references']['2026-11-06']['rows'][$id]['identity']='different';
 if($fault==='later-action')$s['europe']['xetra']['referenceActions'][$id]['2026-11-09']=true;
 if($fault==='history-identity')$s['europe']['history'][$id]['identityRevoked']=true;
 $bad=L::reconcile([$i],snap($i,$missing,$missing['issues']),$s,$now);
 check($bad['snapshot']['quotes'][$id]['price']===null,'Unproven historical recovery is refused: '.$fault);
}
// A lost secondary record can also skip a rejected newest completed session
// using older positive closes and their separately retained reference interval.
$laterRaw=json_encode(['status'=>'200','data'=>[[(string)strtotime('2026-11-09T12:00:00Z'),'102','105','105'],[(string)strtotime('2026-11-06T12:00:00Z'),'100','102','102'],[(string)strtotime('2026-11-05T12:00:00Z'),'98','100','100']]],JSON_THROW_ON_ERROR);
$laterHistory=H::refresh([$i],$history['state'],fn()=>$laterRaw,strtotime('2026-11-09T18:00:00Z'),[$id=>$latest]);
$lostLater=$lost;$lostLater['europe']['history']=$laterHistory['state'];
$lostLater['europe']['xetra']['references']['2026-11-09']=$references['2026-11-06'];
$lostLater['europe']['xetra']['sessions'][$id]['2026-11-09']['invalid']=true;
$again=L::reconcile([$i],snap($i,$missing,$missing['issues']),$lostLater,$now);
check($again['snapshot']['quotes'][$id]['price']==='102'&&$again['snapshot']['quotes'][$id]['date']==='2026-11-06','Lost-record recovery skips a rejected newest session and selects an older positively confirmed interval');
check($again['state']['europe']===$lostLater['europe'],'Searching older intervals never clears the rejected-session evidence');
// Repeated observations of the same date stay bounded, while a genuinely new
// safe pair replaces the earlier-data display without borrowing rejected dates.
$new=quote($i,'2026-11-11','2026-11-10','110','108');$at=strtotime('2026-11-11T18:00:00Z');
$healthy=L::reconcile([$i],snap($i,$new),$out['state'],$at);
check($healthy['snapshot']['quotes'][$id]===$new&&$healthy['snapshot']['earlierData']===[],'Fresh valid pair restores ordinary display after the rejected session');
$bounded=[];
for($n=1;$n<=20;$n++){
 $date=sprintf('2026-10-%02d',$n+1);$previous=sprintf('2026-10-%02d',$n);$q=quote($i,$date,$previous,(string)(100+$n),(string)(99+$n));
 $bounded=L::reconcile([$i],snap($i,$q),$bounded,$now)['state'];
}
check(count($bounded['lastValidMarket'][$id]['history'])<=8,'Accepted revision history remains bounded across many sessions');
$before=count($bounded['lastValidMarket'][$id]['history']);
for($n=0;$n<30;$n++){$q['price']=(string)(121+$n);$bounded=L::reconcile([$i],snap($i,$q),$bounded,$now)['state'];}
check(count($bounded['lastValidMarket'][$id]['history'])===$before,'Repeated same-date observations do not consume additional retained-session slots');
if(($argv[1]??null)==='--json')echo json_encode(['instrument'=>M::metadata([$i])[0],'snapshot'=>$renderSnapshot,'checks'=>$checks],JSON_THROW_ON_ERROR);
else echo "Retained revision checks passed: $checks\n";
