<?php
declare(strict_types=1);
// Fictional references only; no installation configuration or HTTP requests.
require dirname(__DIR__).'/server/bootstrap.php';
use RichYet\{XetraReference as X,LastValidMarket as L,MarketStore as M};
$checks=0;
function check(bool $ok,string $why):void{global $checks;if(!$ok)throw new RuntimeException('FAIL '.$why);$checks++;}
function referenceCsv(array $changes=[],string $date='10.11.2026'):string{
    $row=array_replace(['Product Status'=>'Active','Instrument Status'=>'Active','ISIN'=>'ZZ0000000002','Instrument ID'=>'123456',
        'MIC Code'=>'XETR','Reporting Market'=>'XETA','Unit of Quotation'=>'Shares','Currency'=>'EUR','Settlement Currency'=>'EUR','CUM/EX Indicator'=>''],$changes);
    $s=fopen('php://temp','w+');
    foreach([['Market:','XETR'],['Date Last Update:',$date],array_keys($row),array_values($row)] as $values)fputcsv($s,$values,';','"','');
    rewind($s);$csv=stream_get_contents($s);fclose($s);return $csv;
}
function refreshReference(array $i,array $state,string $csv,int $now):array{
    return X::refresh([$i],$state,static fn(string $url):string=>$url===X::PAGE
        ?'/resource/blob/1528/'.str_repeat('a',32).'/data/t7-xetr-allTradableInstruments.csv':$csv,$now);
}
function snapshot(array $i,array $q,array $codes=[]):array{
    $s=M::emptySnapshot([$i]);$s['quotes'][$i['id']]=$q;
    $s['issues']=array_map(static fn($code)=>['instrumentId'=>$i['id'],'code'=>$code],$codes);return $s;
}
$now=strtotime('2026-11-10T11:00:00Z');
$i=['id'=>'fictional-xetra','name'=>'Fictional ETF','symbol'=>'FICT','type'=>'ETF','provider'=>'xetra','currency'=>'EUR','mic'=>'XETA','isin'=>'ZZ0000000002',
    'providerConfig'=>['identityConfirmed'=>true,'venue'=>'XETA','publicationVenue'=>'XCEF']];
$id=$i['id'];$xetra=['references'=>[]];
foreach(['2026-11-06'=>'06.11.2026','2026-11-09'=>'09.11.2026'] as $date=>$display)$xetra['references'][$date]=X::parse(referenceCsv([],$display),[$i],$now);
$xetra['referenceIdentity'][$id]=$xetra['references']['2026-11-09']['rows'][$id]['identity'];
$q=['price'=>'105','previousClose'=>'100','date'=>'2026-11-09','previousDate'=>'2026-11-06','observedAt'=>'2026-11-09T16:35:00Z','previousObservedAt'=>'2026-11-06T16:35:00Z',
    'status'=>'closed','source'=>'Xetra delayed trades','fx'=>'1','previousFx'=>'1','priceKind'=>'close','basis'=>'same_units','invalidations'=>[],'sourceAvailable'=>true];
$seed=L::reconcile([$i],snapshot($i,$q),['europe'=>['xetra'=>$xetra]],$now);
check(isset($seed['state']['lastValidMarket'][$id]['comparison']),'A complete exact-listing pair is accepted before the incomplete reference');
$badHeader=refreshReference($i,$xetra,str_replace('Product Status','Unknown column',referenceCsv()),$now);
check(($badHeader['referenceFailed']??false)===true&&$badHeader['references']===$xetra['references']
    &&!X::oldBasisUnsafe($badHeader,$id,$q['date'],$now),'A missing required reference column preserves accepted evidence without confirming a new reference');
$incomplete=['omitted-listing'=>['ISIN'=>'ZZ0000000003'],'omitted-venue'=>['Reporting Market'=>''],'other-venue'=>['Reporting Market'=>'XETB']];
foreach(['Product Status','Instrument Status','Instrument ID','MIC Code','Unit of Quotation','Currency','Settlement Currency'] as $field)
    foreach(['',' '] as $blank)$incomplete[$field.'-'.strlen($blank)]=[$field=>$blank];
foreach($incomplete as $name=>$changes){
    $updated=refreshReference($i,$xetra,referenceCsv($changes),$now);$row=$updated['references']['2026-11-10']['rows'][$id];
    check($row['valid']===false&&$row['incompatible']===false,'Missing reference evidence is classified unknown: '.$name);
    check(!X::oldBasisUnsafe($updated,$id,$q['date'],$now),'Missing evidence cannot revoke the accepted valuation: '.$name);
    check(!X::compatible($updated,$id,'2026-11-10','2026-11-09'),'Unknown evidence cannot admit a new comparison: '.$name);
    $state=$seed['state'];$state['europe']['xetra']=$updated;
    $out=L::reconcile([$i],snapshot($i,M::missing(),['source_unavailable','price_missing','baseline_missing']),$state,$now);
    check($out['snapshot']['quotes'][$id]['price']==='105'&&$out['snapshot']['quotes'][$id]['previousClose']==='100'
        &&$out['snapshot']['quotes'][$id]['date']===$q['date']&&isset($out['snapshot']['earlierData'][$id]),'Warm reference outage retains the exact dated pair: '.$name);
    $cold=L::reconcile([$i],snapshot($i,M::missing(),['price_missing']),['europe'=>['xetra'=>$updated]],$now);
    check($cold['snapshot']['quotes'][$id]['price']===null,'Unknown reference cannot fabricate a cold-install valuation: '.$name);
}
$conflicts=['Product Status'=>'Inactive','Instrument Status'=>'Suspended','Instrument ID'=>'broken','MIC Code'=>'XAMS',
    'Unit of Quotation'=>'Percent','Currency'=>'USD','Settlement Currency'=>'USD','CUM/EX Indicator'=>'invalid'];
foreach($conflicts as $field=>$value){
    $changes=[$field=>$value];$changes[$field==='Product Status'?'Instrument Status':'Product Status']='';
    $updated=refreshReference($i,$xetra,referenceCsv($changes),$now);
    check($updated['references']['2026-11-10']['rows'][$id]['incompatible']===true,'Explicit conflict still wins alongside an omission: '.$field);
    check(X::oldBasisUnsafe($updated,$id,$q['date'],$now),'Explicit reference conflict still blocks old valuations: '.$field);
    $state=$seed['state'];$state['europe']['xetra']=$updated;
    $out=L::reconcile([$i],snapshot($i,M::missing(),['corporate_action_unverified']),$state,$now);
    check($out['snapshot']['quotes'][$id]['price']===null,'Confirmed incompatible evidence is not hidden by retention: '.$field);
}
$changed=refreshReference($i,$xetra,referenceCsv(['Instrument ID'=>'654321','Currency'=>'']),$now);
check(isset($changed['referenceRevoked'][$id])&&X::oldBasisUnsafe($changed,$id,$q['date'],$now),'Changed instrument identity remains a veto despite another missing field');
foreach(['C'=>'2026-11-11','E'=>'2026-11-10'] as $indicator=>$effective){
    $state=refreshReference($i,$xetra,referenceCsv(['CUM/EX Indicator'=>$indicator,'Product Status'=>'']),$now);
    check(isset($state['referenceActions'][$id][$effective]),'Explicit action remains known despite incomplete identity proof: '.$indicator);
    check(X::oldBasisUnsafe($state,$id,$q['date'],strtotime($effective.'T11:00:00Z')),'Effective action still blocks an older unit basis: '.$indicator);
}
// Do not reinterpret ambiguous legacy cache rows as evidence of mere omission.
$legacy=$xetra;$legacy['references']['2026-11-10']=['rows'=>[$id=>['valid'=>false,'identity'=>null,'indicator'=>null]]];
check(X::oldBasisUnsafe($legacy,$id,$q['date'],$now),'Unclassified legacy invalid reference remains guarded');
$next=$now+86400;
$unknown=refreshReference($i,$legacy,referenceCsv(['ISIN'=>'ZZ0000000003'],'11.11.2026'),$next);
check(X::oldBasisUnsafe($unknown,$id,$q['date'],$next),'New absent evidence cannot hide an earlier ambiguous veto');
$resolved=refreshReference($i,$legacy,referenceCsv([],'11.11.2026'),$next);
check(!X::oldBasisUnsafe($resolved,$id,$q['date'],$next),'A fresh valid reference can resolve the legacy ambiguity');
$priorConflict=refreshReference($i,$xetra,referenceCsv(['Product Status'=>'Inactive']),$now);
$sameDay=refreshReference($i,$priorConflict,referenceCsv(['ISIN'=>'ZZ0000000003']),$now+3601);
check(X::oldBasisUnsafe($sameDay,$id,$q['date'],$now+3601),'A same-day incomplete replacement cannot erase an explicit incompatibility');
check($sameDay['references']['2026-11-10']['rows'][$id]['retainedVeto']['retrievedAt']===$now,'Retained veto preserves its original confirmation time');
$legacySameDay=refreshReference($i,$legacy,referenceCsv(['Currency'=>'']),$now+3601);
check(X::oldBasisUnsafe($legacySameDay,$id,$q['date'],$now+3601),'A same-day incomplete replacement cannot reinterpret legacy invalidity');
$unknown=refreshReference($i,$priorConflict,referenceCsv(['ISIN'=>'ZZ0000000003'],'11.11.2026'),$next);
check(X::oldBasisUnsafe($unknown,$id,$q['date'],$next),'A later omission cannot hide an earlier explicit incompatibility');
foreach(['11','12','13','16','17','18','19','20','23'] as $day){
    $at=strtotime('2026-11-'.$day.'T11:00:00Z');
    $sameDay=refreshReference($i,$sameDay,referenceCsv(['Currency'=>''],$day.'.11.2026'),$at);
}
check(!isset($sameDay['references']['2026-11-10'])&&X::oldBasisUnsafe($sameDay,$id,$q['date'],$at),'Pruning old reference dates cannot erase a retained incompatibility');
$revalidated=refreshReference($i,$sameDay,referenceCsv([],'23.11.2026'),$at+3601);
check(!X::oldBasisUnsafe($revalidated,$id,$q['date'],$at+3601),'A fresh valid row can resolve a carried availability veto');
$roundTrip=json_decode(json_encode($seed['state'],JSON_THROW_ON_ERROR),true);
for($n=0;$n<3;$n++){
    $at=$now+$n*3601;$roundTrip['europe']['xetra']=refreshReference($i,$roundTrip['europe']['xetra'],referenceCsv(['Currency'=>'']),$at);
    $out=L::reconcile([$i],snapshot($i,M::missing(),['source_unavailable']),$roundTrip,$at);$roundTrip=json_decode(json_encode($out['state'],JSON_THROW_ON_ERROR),true);
    check($out['snapshot']['quotes'][$id]['price']==='105'&&$out['snapshot']['quotes'][$id]['previousClose']==='100','Repeated incomplete references and reloads retain the original tuple');
}
echo "Xetra reference retention checks passed: $checks\n";
