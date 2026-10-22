<?php
declare(strict_types=1);
// Fictional, offline coverage of the shared last-valid ledger for every
// supported provider family. No provider requests or personal inputs.
require dirname(__DIR__).'/server/bootstrap.php';
use RichYet\{LastValidMarket as L,MarketStore as M};
$checks=0;
function check(bool $ok,string $why):void{global $checks;if(!$ok)throw new RuntimeException('FAIL '.$why);$checks++;}
function snap(array $i,array $q,array $codes=[]):array{$s=M::emptySnapshot([$i]);$s['quotes'][$i['id']]=$q;$s['issues']=array_map(fn($c)=>['instrumentId'=>$i['id'],'code'=>$c],$codes);return $s;}
$now=strtotime('2026-10-22T08:00:00Z');
$providers=[
 ['euronext','XAMS','Stock','ams_j0_continuous_2026'],
 ['euronext','XPAR','ETF','par_j0_continuous_2026'],
 ['xetra','XETA','ETF',null],
 ['alpaca','XNAS','Stock',null],
 ['bnd',null,'Fund',null],
];
foreach($providers as [$provider,$mic,$type,$schedule]){
 $id='fictional-'.($mic??'fund');$usd=$provider==='alpaca';
 $i=['id'=>$id,'name'=>'Fictional '.$type,'symbol'=>'FICT','type'=>$type,'provider'=>$provider,'currency'=>$usd?'USD':'EUR','mic'=>$mic,'isin'=>$usd?null:'ZZ0000000002',
     'providerConfig'=>['identityConfirmed'=>true,'symbol'=>'FICT','fundId'=>1]];
 if($schedule!==null)$i['providerConfig']['tradingSchedule']=$schedule;
 $q=['price'=>'105','previousClose'=>'100','date'=>'2026-10-21','previousDate'=>'2026-10-20','observedAt'=>$type==='Fund'?null:'2026-10-21T15:00:00Z','previousObservedAt'=>$type==='Fund'?null:'2026-10-20T15:00:00Z',
     'fx'=>$usd?['numerator'=>'1','denominator'=>'1.25']:'1','previousFx'=>$usd?['numerator'=>'1','denominator'=>'1.20']:'1','status'=>$type==='Fund'?'nav':'closed',
     'source'=>'Fictional accepted feed','listingId'=>$id,'currency'=>$i['currency'],'mic'=>$mic,'isin'=>$i['isin'],'priceKind'=>$type==='Fund'?'nav':'close','basis'=>'same_units','invalidations'=>[],
     'sourceAvailable'=>true,'fxBaselineVerified'=>true];
 $initial=L::reconcile([$i],snap($i,$q),[],$now);$seed=$initial['state'];
 check(($seed['lastValidMarket'][$id]['comparison']??null)===$q,$id.' retains its accepted complete tuple');
 foreach(['absent',null,''] as $date)foreach(['absent',null,''] as $marker){
  $state=$seed;$bad=M::missing();
  if($date==='absent')unset($bad['date']);else $bad['date']=$date;
  if($marker!=='absent'){
   if($provider==='alpaca')$state['us']['basisInvalidThrough'][$id]=$marker;
   elseif($provider==='euronext')$state['europe']['euronext'][$schedule]['invalidThrough'][$id]=$marker;
  }
  $out=L::reconcile([$i],snap($i,$bad,['price_missing','baseline_missing']),$state,$now);
  $saved=$out['snapshot']['quotes'][$id];$expected=array_replace($q,['status'=>'stale','sourceAvailable'=>false]);
  check($saved===$expected,$id.' missing candidate date/marker preserves the entire dated price, comparison and FX tuple');
  check(($out['state']['lastValidMarket'][$id]['comparison']??null)===$q&&isset($out['snapshot']['earlierData'][$id]),$id.' failure preserves its durable record with an earlier-data explanation');
 }
 // Repeat after serializing/reloading state: one failure must not poison later
 // failures, and a new valid observation must be able to replace the old tuple.
 $state=$seed;
 for($n=0;$n<3;$n++){
  $state=json_decode(json_encode($state,JSON_THROW_ON_ERROR),true,64,JSON_THROW_ON_ERROR);
  $out=L::reconcile([$i],snap($i,M::missing(),['source_unavailable']),$state,$now+$n*600);$state=$out['state'];
  check($out['snapshot']['quotes'][$id]===array_replace($q,['status'=>'stale','sourceAvailable'=>false]),$id.' repeated failure/reload preserves both comparison inputs');
 }
 $new=array_replace($q,['price'=>'108','previousClose'=>'105','date'=>'2026-10-22','previousDate'=>'2026-10-21','observedAt'=>$type==='Fund'?null:'2026-10-22T07:30:00Z','previousObservedAt'=>$q['observedAt']]);
 $out=L::reconcile([$i],snap($i,$new),$state,$now+1800);
 check($out['snapshot']['quotes'][$id]===$new&&$out['snapshot']['earlierData']===[],$id.' a valid later observation replaces retained values');
 $changed=$i;$changed['providerConfig']['identityConfirmed']=false;
 $out=L::reconcile([$changed],snap($changed,M::missing(),['listing_unverified']),$seed,$now);
 check($out['snapshot']['quotes'][$id]['price']===null&&!isset($out['state']['lastValidMarket'][$id]['comparison']),$id.' unverified identity cannot borrow the accepted tuple');
 $out=L::reconcile([$i],snap($i,array_replace(M::missing(),['invalidations'=>['listing']])), $seed,$now);
 check($out['snapshot']['quotes'][$id]['price']===null&&!isset($out['state']['lastValidMarket'][$id]['comparison']),$id.' explicit listing rejection still discards unsafe figures');
 if($provider==='alpaca'||$provider==='euronext'){
  $state=$seed;
  if($provider==='alpaca')$state['us']['basisInvalidThrough'][$id]='2026-10-21';
  else $state['europe']['euronext'][$schedule]['invalidThrough'][$id]='2026-10-21';
  $out=L::reconcile([$i],snap($i,M::missing(),['price_missing']),$state,$now);
  check($out['snapshot']['quotes'][$id]['price']===null&&!isset($out['state']['lastValidMarket'][$id]['comparison']),$id.' a real dated correction still vetoes a saved pair even when the incoming date is absent');
 }
 if($provider!=='bnd'){
  $state=$seed;
  if($provider==='alpaca')$state['us']['corporateActions']=['forward_splits'=>[['symbol'=>'FICT','ex_date'=>'2026-10-22']]];
  elseif($provider==='xetra')$state['europe']['xetra']['referenceActions'][$id]['2026-10-22']=true;
  else $state['europe']['actions'][$id]=['timeZone'=>'Europe/Amsterdam','events'=>['2026-10-22'=>true]];
  $out=L::reconcile([$i],snap($i,M::missing(),['source_unavailable']),$state,$now);
  check($out['snapshot']['quotes'][$id]['price']===null&&!isset($out['state']['lastValidMarket'][$id]['comparison']),$id.' known effective action still prevents reuse in different units');
 }
 if($usd)foreach(['fx','previousFx'] as $field){
  $out=L::reconcile([$i],snap($i,array_replace($q,[$field=>null]),[$field==='fx'?'fx_missing':'baseline_fx_missing']),$seed,$now);
  check($out['snapshot']['quotes'][$id]===array_replace($q,['status'=>'stale','sourceAvailable'=>false]),'Missing '.$field.' keeps both exact accepted currency conversions together');
 }
}
echo "Provider-scope retention checks passed: $checks\n";
