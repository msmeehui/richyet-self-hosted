<?php
declare(strict_types=1);
// Fictional completed closes and trade warnings only. No installation or HTTP.
require dirname(__DIR__).'/server/bootstrap.php';
require dirname(__DIR__).'/server/EuropeanProviders.php';
require dirname(__DIR__).'/server/InstrumentPreparation.php';
use RichYet\{EuropeanProviders as E,EuropeanHistory as H,EuropeanActions as A,XetraHistoryRecovery as R,LastValidMarket as L,MarketStore as M,InstrumentPreparation as P};
$checks=0;
function check(bool $ok,string $why):void{global $checks;if(!$ok)throw new RuntimeException('FAIL '.$why);$checks++;}
function noRequests(string $url):string{throw new RuntimeException('Unexpected request: '.$url);}
function snap(array $i,array $q):array{$s=M::emptySnapshot([$i]);$s['quotes'][$i['id']]=$q;$s['issues']=array_map(fn($code)=>['instrumentId'=>$i['id'],'code'=>$code],$q['issues']??[]);return $s;}
function record(array &$x,array $i,array $changes,int $now):void{
    $row=array_replace(['instrumentIdentificationCode'=>$i['isin'],'venueOfExecution'=>'XETA','venueOfPublication'=>'XCEF',
        'priceCurrency'=>'EUR','priceNotation'=>'MONE','rts'=>'1','tradingSystem'=>'CLOB','price'=>'500',
        'tradingDateAndTime'=>'2026-11-10T15:00:00Z','publicationDateAndTime'=>'2026-11-10T15:00:01Z',
        'transactionIdentificationCode'=>'fictional-trade'],$changes);
    (new ReflectionMethod(E::class,'xetraRecord'))->invokeArgs(null,[&$x,$i,$row,$now,'fictional-minute.json.gz',1]);
}
$i=['id'=>'fictional-independent','name'=>'Fictional Independent ETF','symbol'=>'FICT','exchange'=>'Example Xetra','type'=>'ETF',
    'provider'=>'xetra','currency'=>'EUR','mic'=>'XETA','isin'=>'ZZ0000000002','timeZone'=>'Europe/Berlin','coverage'=>['price'=>true,'comparison'=>true],
    'providerConfig'=>['identityConfirmed'=>true,'venue'=>'XETA','publicationVenue'=>'XCEF','historyProvider'=>'newton',
        'historySymbol'=>'FICT.DE','historyIdentityConfirmed'=>true,'actionsProvider'=>'eodhd','actionsSymbol'=>'FICT.XETRA','actionsIdentityConfirmed'=>true]];
$id=$i['id'];$now=strtotime('2026-11-10T17:05:00Z');$confirmed=$now-60;
$raw=json_encode(['status'=>'200','data'=>[[(string)strtotime('2026-11-10T12:00:00Z'),'0','105','105'],
    [(string)strtotime('2026-11-09T12:00:00Z'),'0','100','100']]],JSON_THROW_ON_ERROR);
$response=H::refresh([$i],[],fn()=>$raw,$confirmed);$history=$response['state'][$id];$q=$response['quotes'][$id];
$x=['sessions'=>[$id=>['2026-11-09'=>['invalid'=>true,'trades'=>[]],'2026-11-10'=>['invalid'=>true,'trades'=>[]]]]];
foreach(['2026-11-09','2026-11-10'] as $day)$x['references'][$day]=['date'=>$day,'retrievedAt'=>$confirmed,'sha256'=>str_repeat('a',64),
    'rows'=>[$id=>['valid'=>true,'identity'=>'123|ZZ0000000002|XETA|Shares|EUR','indicator'=>'']]];
record($x,$i,[],$now);$x=E::compactXetraState($x);$unchanged=$x;
$direct=E::xetraQuote($x['sessions'][$id],$now,false,$x,$id);
check($direct['price']===null&&$x['sessions'][$id]['2026-11-10']['transactionCount']===1,'Accepted trade remains blocked by its original opaque session');
check(R::allows($i,$q,$history,$x,[],$now),'Independently received completed pair has all required evidence');
$selected=E::withHistory($i,$direct,$q,$history,[],'noRequests',$now,$x)['quote'];
check($selected['price']==='105'&&$selected['previousClose']==='100'&&$selected['source']==='Newton Analytics daily close','Selector uses both independent closes, never the blocked trade price');
check($selected['observedAt']===null&&$selected['previousObservedAt']===null&&$selected['priceKind']==='close','Independent pair does not invent an intraday or auction time');
check($x===$unchanged&&$x['sessions'][$id]['2026-11-09']['invalid']&&$x['sessions'][$id]['2026-11-10']['invalid'],'Independent recovery preserves opaque warnings and complete trade evidence');
$state=['europe'=>['history'=>[$id=>$history],'xetra'=>$x]];
$cold=L::reconcile([$i],snap($i,$selected),$state,$now);
check($cold['snapshot']['quotes'][$id]['price']==='105'&&$cold['snapshot']['earlierData']===[],'Cold recovery needs positive independent evidence, not an existing quote');
$older=array_replace($selected,['date'=>'2026-11-06','previousDate'=>'2026-11-05','expectedPreviousDate'=>'2026-11-05','price'=>'95','previousClose'=>'90','status'=>'stale','freshUntil'=>$now-86400]);
$warm=L::reconcile([$i],snap($i,$older),$state,$now);
$warm=L::reconcile([$i],snap($i,$selected),$warm['state'],$now);
check($warm['snapshot']['quotes'][$id]['price']==='105'&&$warm['snapshot']['earlierData']===[],'A fresh independent pair replaces an earlier retained pair');
check($warm['state']['europe']===$state['europe'],'Retention never edits provider warnings, identity or confirmation clocks');
M::validateSnapshot($warm['snapshot'],[$i]);check(true,'Recovered quote stays within the existing public snapshot schema');

// No source string, current row, malformed receipt or altered listing is proof.
$faults=['missing-ledger','missing-hash','invalid-hash','imported-proof','preclose-proof','future-proof','wrong-fingerprint','wrong-record-identity',
    'revoked-history','missing-current-row','revised-current-row','conflicting-history','different-adjustment','old-confirmation','source-failed','old-fetch','future-fetch',
    'wrong-quote-price','wrong-baseline-price','wrong-isin','wrong-mic','wrong-currency','wrong-fx','wrong-previous-fx','wrong-listing','unconfirmed-history',
    'hybrid-source','trade-kind','trade-time','wrong-previous-session','wrong-expected-previous','invalidated-quote','stale-quote','expired-quote','extended-freshness',
    'missing-reference','wrong-reference','reference-action','reference-revoked','known-action','diagnosed-legacy-warning','hard-current','hard-baseline'];
foreach($faults as $fault){
    $j=$i;$v=$q;$h=$history;$r=$x;$a=[];
    switch($fault){
        case 'missing-ledger':unset($h['retainedCloses']['2026-11-10']);break;
        case 'missing-hash':unset($h['retainedCloses']['2026-11-10']['evidenceSha256']);break;
        case 'invalid-hash':$h['retainedCloses']['2026-11-10']['evidenceSha256']='corrupt';break;
        case 'imported-proof':$h['retainedCloses']['2026-11-10']['evidenceKind']='saved-production-state';break;
        case 'preclose-proof':$h['retainedCloses']['2026-11-10']['firstConfirmedAt']=strtotime('2026-11-10T16:59:59Z');break;
        case 'future-proof':$h['retainedCloses']['2026-11-10']['lastConfirmedAt']=$now+1;break;
        case 'wrong-fingerprint':$h['fingerprint']=str_repeat('0',64);break;
        case 'wrong-record-identity':$h['retainedCloses']['2026-11-10']['identityFingerprint']=str_repeat('0',64);break;
        case 'revoked-history':$h['identityRevoked']=true;break;
        case 'missing-current-row':unset($h['rows']['2026-11-10']);break;
        case 'revised-current-row':$h['rows']['2026-11-10']['close']='106';break;
        case 'conflicting-history':$h['retainedInvalidDates']['2026-11-10']=$now;break;
        case 'different-adjustment':$h['rows']['2026-11-10']['adjustedClose']=$h['retainedCloses']['2026-11-10']['adjustedClose']='52.5';break;
        case 'old-confirmation':$h['retainedCloses']['2026-11-10']['lastConfirmedAt']=$confirmed-1;break;
        case 'source-failed':$h['sourceAvailable']=false;break;
        case 'old-fetch':$h['fetchedAt']=$now-10801;break;
        case 'future-fetch':$h['fetchedAt']=$now+1;break;
        case 'wrong-quote-price':$v['price']='106';break;
        case 'wrong-baseline-price':$v['previousClose']='99';break;
        case 'wrong-isin':$v['isin']='ZZ0000000003';break;
        case 'wrong-mic':$v['mic']='XETR';break;
        case 'wrong-currency':$v['currency']='USD';break;
        case 'wrong-fx':$v['fx']='1.1';break;
        case 'wrong-previous-fx':$v['previousFx']=['numerator'=>'1','denominator'=>'2'];break;
        case 'wrong-listing':$v['listingId']='another-listing';break;
        case 'unconfirmed-history':$j['providerConfig']['historyIdentityConfirmed']=false;break;
        case 'hybrid-source':$v['source']='Xetra delayed trades; Newton Analytics previous close';break;
        case 'trade-kind':$v['priceKind']='trade';break;
        case 'trade-time':$v['observedAt']='2026-11-10T15:00:00Z';break;
        case 'wrong-previous-session':$v['previousDate']='2026-11-06';break;
        case 'wrong-expected-previous':$v['expectedPreviousDate']='2026-11-06';break;
        case 'invalidated-quote':$v['invalidations']=['comparison'];break;
        case 'stale-quote':$v['status']='stale';break;
        case 'expired-quote':$v['freshUntil']=$now;break;
        case 'extended-freshness':$v['freshUntil']++;break;
        case 'missing-reference':unset($r['references']['2026-11-09']);break;
        case 'wrong-reference':$r['references']['2026-11-10']['rows'][$id]['identity']='124|ZZ0000000002|XETA|Shares|EUR';break;
        case 'reference-action':$r['referenceActions'][$id]['2026-11-10']=true;break;
        case 'reference-revoked':$r['referenceRevoked'][$id]='2026-11-10';break;
        case 'known-action':$a[$id]=['events'=>['2026-11-10'=>true],'timeZone'=>'Europe/Berlin'];break;
        case 'diagnosed-legacy-warning':$r['sessions'][$id]['2026-11-10']['rejections']=['count'=>1,'first'=>['reason'=>'unsupported_schema'],'last'=>['reason'=>'unsupported_schema']];break;
        case 'hard-current':$r['sessions'][$id]['2026-11-10']['hardInvalid']=true;break;
        case 'hard-baseline':$r['sessions'][$id]['2026-11-09']['hardInvalid']=true;break;
    }
    check(!R::allows($j,$v,$h,$r,$a,$now),'Independent admission refuses '.$fault);
    $bad=E::withHistory($j,E::xetraQuote($r['sessions'][$id],$now,false,$r,$id),$v,$h,$a,'noRequests',$now,$r)['quote'];
    check($bad['price']===null,'Provider selector cannot bypass '.$fault);
}
$beforeClose=strtotime('2026-11-10T16:59:59Z');
check(!R::allows($i,$q,$history,$x,[],$beforeClose),'A same-day row cannot be a completed close before18:00 local');
$listing=E::withHistory($i,array_replace($direct,['issues'=>['listing_unverified']]),$q,$history,[],'noRequests',$now,$x)['quote'];
check($listing['price']===null,'Independent-source rule cannot hide a listing-identity warning');

// The existing dated action ledger is an alternative unit proof, without a fetch.
$actions=A::check($i,'2026-11-09','2026-11-10',[],fn()=>'[]',$confirmed)['state'];
$noReferences=$x;unset($noReferences['references']);
check(R::allows($i,$q,$history,$noReferences,$actions,$now),'Existing successful exact action interval can qualify the completed pair');
$staleAction=$actions;foreach($staleAction[$id]['checks'] as &$c)$c['retrievedAt']=$now-21601;unset($c);
check(!R::allows($i,$q,$history,$noReferences,$staleAction,$now),'Old action proof cannot admit a new current pair');
foreach(['fingerprint','checkedAt','retrievedAt'] as $field){
    $badAction=$actions;foreach($badAction[$id]['checks'] as &$c)$c[$field]=$field==='fingerprint'?str_repeat('0',64):$now+1;unset($c);
    check(!R::allows($i,$q,$history,$noReferences,$badAction,$now),'Action admission binds exact '.$field.' to its own successful check');
}
$actions[$id]['events']['2026-11-10']=true;
check(!R::allows($i,$q,$history,$noReferences,$actions,$now),'Known action overrides an earlier successful action check');

// An already admitted pair remains an explicitly old tuple after ordinary
// failure or expiry. Neither a new unproven pair nor fresh timestamps are made.
foreach(['failure','omission','expiry'] as $fault){
    $s=$cold['state'];$at=$now+3600;
    if($fault==='failure')$s['europe']['history'][$id]['sourceAvailable']=false;
    if($fault==='omission')$s['europe']['history'][$id]['rows']=[];
    if($fault==='expiry')$at=strtotime('2026-11-11T12:00:00Z');
    $old=L::reconcile([$i],snap($i,$direct),$s,$at);
    $expected=array_replace($selected,['status'=>'stale','sourceAvailable'=>false]);
    check($old['snapshot']['quotes'][$id]===$expected&&isset($old['snapshot']['earlierData'][$id]),'Accepted pair keeps original tuple and warning through '.$fault);
    check($old['state']['europe']===$s['europe'],'Retention leaves confirmation clocks and warnings intact through '.$fault);
    $unaccepted=L::reconcile([$i],snap($i,$selected),['europe'=>$s['europe']],$at);
    check($unaccepted['snapshot']['quotes'][$id]['price']===null,'Cold copied pair cannot acquire acceptance through '.$fault);
}

// Positive evidence is permanent even when later bounded examples omit it.
foreach(['correction','listing','transaction','unpack'] as $fault){
    $r=$x;
    if($fault==='correction')record($r,$i,['flags'=>'CANC'],$now);
    if($fault==='listing')record($r,$i,['priceCurrency'=>'USD'],$now);
    if($fault==='transaction')record($r,$i,['price'=>'501'],$now);
    if($fault==='unpack'){
        $r['sessions'][$id]['2026-11-10']['transactionFingerprints']=base64_encode(gzdeflate(json_encode(['fictional-trade'=>str_repeat('0',64)],JSON_THROW_ON_ERROR)));
        // Already packed evidence is decoded lazily when an eligible record
        // arrives. A distinct transaction isolates the retained-row conflict.
        record($r,$i,['transactionIdentificationCode'=>'fictional-next'],$now);
        $r=E::compactXetraState($r);
    }
    check(($r['sessions'][$id]['2026-11-10']['hardInvalid']??false)===true,'Positive '.$fault.' has sticky hard classification');
    for($n=0;$n<3;$n++)record($r,$i,['flags'=>'UNREVIEWED'],$now);
    $r=E::compactXetraState($r);
    check(!R::allows($i,$q,$history,$r,[],$now),'Later soft diagnostics cannot hide '.$fault);
    $s=$cold['state'];$s['europe']['xetra']=$r;
    $read=L::reconcile([$i],snap($i,$selected),$s,$now);
    check($read['snapshot']['quotes'][$id]['price']===null&&!isset($read['state']['lastValidMarket'][$id]['comparison']),'Cache read withdraws recovered pair after '.$fault);
    $prior=$warm['state'];$prior['europe']['xetra']=$r;
    $fallback=L::reconcile([$i],snap($i,$selected),$prior,$now);
    check($fallback['snapshot']['quotes'][$id]['price']==='95'&&isset($fallback['snapshot']['earlierData'][$id]),'A later '.$fault.' keeps the unaffected earlier accepted pair');
    foreach([true,false] as $replace){
        foreach([false,true] as $reverse){
            $left=['europe'=>['xetra'=>$reverse?$x:$r]];$right=['europe'=>['xetra'=>$reverse?$r:$x]];
            $merged=P::mergeState($left,$right,$i,$replace);
            check(($merged['europe']['xetra']['sessions'][$id]['2026-11-10']['hardInvalid']??false)===true,'Merge retains '.$fault.' with either order/replacement mode');
        }
    }
}
foreach([true,false] as $replace){
    $saved=json_decode(json_encode($cold,JSON_THROW_ON_ERROR),true);
    $merged=P::mergeState($saved['state'],P::stateFor($saved['state'],$i),$i,$replace);
    $read=L::reconcile([$i],$saved['snapshot'],$merged,$now+30);
    check($read['snapshot']['quotes'][$id]['price']==='105'&&$read['snapshot']['earlierData']===[],'Accepted independent pair survives serialization and singleton merge');
    check($merged['europe']['xetra']['sessions'][$id]['2026-11-10']['invalid']===true,'Read/store merge leaves original warning intact');
}
// Exercise the actual persistence and cache-only read model with an in-memory
// owner account, not only the pure selector. No personal data or file is used.
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
$db->exec(file_get_contents(__DIR__.'/backend-schema.sqlite.sql'));RichYet\ReportingStore::initialize($db);
$db->exec("INSERT INTO users VALUES(1,'fixture@example.invalid',NULL,1)");
$insert=$db->prepare('INSERT INTO market_accounts VALUES(1,?,?,?,?)');
$insert->execute([json_encode([$i],JSON_THROW_ON_ERROR),json_encode(M::emptySnapshot([$i]),JSON_THROW_ON_ERROR),'{}',$now]);
$store=new M($db,'fixture@example.invalid',$now);
$store->saveRefresh(1,$cold['snapshot'],$cold['state'],$now);
$stored=$store->market(1);$storedQuotes=(array)$stored['quotes'];
check($storedQuotes[$id]['price']==='105'&&$stored['earlierData']===[],'Saved accepted independent pair remains fresh through the actual cache read');
$r=$x;record($r,$i,['flags'=>'CANC'],$now);$s=$cold['state'];$s['europe']['xetra']=$r;
$store->saveRefresh(1,$cold['snapshot'],$s,$now);
$before=$db->query('SELECT snapshot_json,state_json FROM market_accounts')->fetch();
$read=$store->market(1);$quotes=(array)$read['quotes'];
check($quotes[$id]['price']===null,'Actual cache-only read withdraws the saved pair after a positive same-day correction');
check($db->query('SELECT snapshot_json,state_json FROM market_accounts')->fetch()===$before,'Cache-only correction read does not mutate saved evidence');
$s=$warm['state'];$s['europe']['xetra']=$r;$store->saveRefresh(1,$warm['snapshot'],$s,$now);
$read=$store->market(1);$quotes=(array)$read['quotes'];
check($quotes[$id]['price']==='95'&&isset($read['earlierData'][$id]),'Actual cache-only read falls back to an unaffected older pair');
echo "Xetra history recovery checks passed: $checks\n";
