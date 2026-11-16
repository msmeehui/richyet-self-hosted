<?php
declare(strict_types=1);
// Fictional instruments, trades and HTTP pages only.
require dirname(__DIR__).'/server/bootstrap.php';
require dirname(__DIR__).'/server/EuropeanProviders.php';
require dirname(__DIR__).'/server/ProviderHttp.php';
require dirname(__DIR__).'/server/InstrumentPreparation.php';
use RichYet\{EuropeanProviders as E, EuropeanHistory as H, InstrumentPreparation as P, ProviderHttp as Http, LastValidMarket as L, MarketStore as M};
$checks=0;
function check(bool $ok,string $why):void{global $checks;if(!$ok)throw new RuntimeException('FAIL '.$why);$checks++;}
function row(int $n,string $date='2026-11-13',array $extra=[]):array{
    $time=gmdate('Y-m-d\TH:i:s',strtotime($date.'T08:00:00Z')+$n).'.123456000Z';
    return array_replace(['instrumentIdentificationCode'=>'ZZ0000000002','venueOfExecution'=>'XETA','venueOfPublication'=>'XCEF',
        'priceCurrency'=>'EUR','priceNotation'=>'MONE','rts'=>1,'tradingSystem'=>'CLOB','price'=>100,
        'tradingDateAndTime'=>$time,'publicationDateAndTime'=>$time,'transactionIdentificationCode'=>'trade-'.$date.'-'.$n,'flags'=>'ALGO'],$extra);
}
function refresh(array &$s,array $i,callable $get,int $now):array{
    return (new ReflectionMethod(E::class,'xetraViewRefresh'))->invokeArgs(null,[[$i],&$s,$get,$now]);
}
$i=['id'=>'fictional-view','name'=>'Fictional View ETF','symbol'=>'FICT','exchange'=>'Example Xetra','type'=>'ETF',
    'provider'=>'xetra','currency'=>'EUR','mic'=>'XETA','isin'=>'ZZ0000000002','timeZone'=>'Europe/Berlin','coverage'=>['price'=>true,'comparison'=>true],
    'providerConfig'=>['identityConfirmed'=>true,'venue'=>'XETA','publicationVenue'=>'XCEF','historyProvider'=>'newton','historySymbol'=>'FICT.DE','historyIdentityConfirmed'=>true]];
$id=$i['id'];$now=strtotime('2026-11-16T16:05:00Z');$latest=row(0,'2026-11-16',['price'=>105,'tradingDateAndTime'=>'2026-11-16T15:40:00.123456000Z','publicationDateAndTime'=>'2026-11-16T15:40:00.124456000Z']);
$page=array_map(fn($n)=>row($n),range(0,99));$token=(string)(strtotime('2026-11-13T08:01:39Z')*1000+124);
$first=json_encode([['continue'=>$token],...$page],JSON_THROW_ON_ERROR);
$s=['cursor'=>'2026-11-13T22_00','retentionGap'=>true,'archiveUncertain'=>true,'catchupPending'=>true];$before=$s;$requests=[];
$get=function($url)use(&$requests,$first,$page,$latest,$token){parse_str(parse_url($url,PHP_URL_QUERY),$q);$requests[]=$q;
    check(Http::provider($url)==='european'&&Http::xetraViewRequest($url)===$q,'Exact public search shares bounded European allowance');
    return count($requests)===1?$first:json_encode([$page[99],$latest]);};
$q=refresh($s,$i,$get,$now)[$id]??null;
check($q!==null&&$q['price']==='105'&&$q['date']==='2026-11-16'&&$q['status']==='delayed','Complete current-day publications supply a correctly dated delayed price');
check($requests[1]['start']===(string)((int)$token-1),'Next page overlaps the final publication millisecond');
check($s['views'][$id]['sessions'][$id]['2026-11-13']['transactionCount']===100,'Overlap deduplicates identical transaction evidence');
foreach($before as $k=>$v)check($s[$k]===$v,'View proof preserves shared file evidence: '.$k);
check(!isset($s['sessions'][$id]['2026-11-16']['trades']),'Reduced-precision transactions never enter file ledger');
$good=$s;
// Day partitions can produce a short continuing page and a page over 100 reports.
$short=json_encode([['continue'=>$token],$page[99]]);$s2=[];$calls=0;
$dayPage=[...$page,$latest];
check(isset(refresh($s2,$i,function()use(&$calls,$short,$latest){return ++$calls===1?$short:json_encode([$latest]);},$now)[$id]),'Short day-boundary page must still follow continuation');
$s2=[];check(isset(refresh($s2,$i,fn()=>json_encode($dayPage),$now)[$id]),'Cross-day terminal page may exceed 100 reports');
// The validated independent close and exact references still qualify arithmetic.
$history=H::refresh([$i],[],fn()=>json_encode(['status'=>'200','data'=>[[strtotime('2026-11-13T12:00:00Z'),'0','100','100'],[strtotime('2026-11-12T12:00:00Z'),'0','99','99']]]),strtotime('2026-11-13T17:05:00Z'))['state'][$id];
foreach(['2026-11-13','2026-11-16'] as $date)$s['references'][$date]=['date'=>$date,'retrievedAt'=>$now,'sha256'=>str_repeat('a',64),'rows'=>[$id=>['valid'=>true,'identity'=>'123|ZZ0000000002|XETA|Shares|EUR','indicator'=>'']]];
$joined=E::withHistory($i,$q,null,$history,[],fn()=>throw new RuntimeException('No extra requests'),$now,$s)['quote'];
check($joined['previousClose']==='100'&&$joined['previousDate']==='2026-11-13'&&$joined['basis']==='same_units','Same-listing previous close remains separately validated');
$snap=M::emptySnapshot([$i]);$snap['quotes'][$id]=$joined;$snap['issues']=[];
$r=L::reconcile([$i],$snap,['europe'=>['xetra'=>$s,'history'=>[$id=>$history]]],$now);
check($r['snapshot']['quotes'][$id]['price']==='105'&&$r['state']['lastValidMarket'][$id]['comparison']['price']==='105','Complete comparison survives ordinary retention');
M::validateSnapshot($r['snapshot'],[$i]);
// Ordinary refresh handles the failed file route, then persists the viewer pair.
$full=$r['state'];$full['europe']['xetra']['referenceAttemptAt']=$now;
$full['europe']['xetra']['members'][$id]=hash('sha256',json_encode([$i['isin'],$i['currency'],$i['mic'],$i['providerConfig']['venue'],$i['providerConfig']['publicationVenue']],JSON_THROW_ON_ERROR));
$full['europe']['history'][$id]['at']=$now;
$index=json_encode(['SourcePrefix'=>'DETR-posttrade','CurrentFiles'=>['DETR-posttrade-2026-11-16T00_00.json.gz']]);
$ordinaryGet=function($url)use($index){
 if($url==='https://mfs.deutsche-boerse.com/api/DETR-posttrade')return $index;
 if(str_starts_with($url,'https://mfs.deutsche-boerse.com/api/download/'))throw new RichYet\ProviderHttpStatus(404);
 if(str_starts_with($url,'https://mvs.deutsche-boerse.com/api?'))return '[]';
 throw new RuntimeException('Unexpected ordinary request');
};
$ordinary=RichYet\MarketRefresh::build([$i],$r['snapshot'],$full,$ordinaryGet,$now+600);
check($ordinary['snapshot']['quotes'][$id]['price']==='105'&&$ordinary['snapshot']['quotes'][$id]['status']==='delayed','Ordinary failed-file refresh selects completed viewer pair');
check($ordinary['state']['europe']['xetra']['collection']['failureHttpStatus']===404&&$ordinary['state']['europe']['xetra']['catchupPending'],'Actual failed-file diagnostics remain intact after fallback');
$failed=RichYet\MarketRefresh::build([$i],$ordinary['snapshot'],$ordinary['state'],fn()=>throw new RuntimeException('Offline'),$now+1200);
check($failed['snapshot']['quotes'][$id]['price']==='105'&&$failed['snapshot']['quotes'][$id]['status']==='stale','Later incomplete refresh retains the accepted pair with stale status');
$cancel=array_replace($latest,['flags'=>'CANC','publicationDateAndTime'=>'2026-11-16T15:55:01Z']);
$revoked=RichYet\MarketRefresh::build([$i],$ordinary['snapshot'],$ordinary['state'],function($url)use($ordinaryGet,$cancel){return str_starts_with($url,'https://mvs.deutsche-boerse.com/api?')?json_encode([$cancel]):$ordinaryGet($url);},$now+1200);
$merged=P::mergeState($ordinary['state'],$revoked['state'],$i);
$revokedRead=L::reconcile([$i],$revoked['snapshot'],$merged,$now+1200);
check($revokedRead['snapshot']['quotes'][$id]['price']===null,'Ordinary late cancellation survives singleton merging and removes saved price');
// Same-second lower-precision observations cannot replace a newer saved trade.
$newer=$r['snapshot'];$newer['quotes'][$id]['observedAt']='2026-11-16T15:40:00.123456001Z';$newer['quotes'][$id]['price']='106';
$precision=RichYet\MarketRefresh::build([$i],$newer,$full,$ordinaryGet,$now+600);
check($precision['snapshot']['quotes'][$id]['price']==='106'&&$precision['snapshot']['quotes'][$id]['status']==='stale','Ordinary refresh retains the newer subsecond observation');
// A retained complete window continues on the next session without pretending the old trade is today's.
$next=$good;$queried=null;$out=refresh($next,$i,function($url)use(&$queried){parse_str(parse_url($url,PHP_URL_QUERY),$p);$queried=(int)$p['start'];return '[]';},strtotime('2026-11-17T10:00:00Z'));
check($out===[]&&$queried===$good['views'][$id]['through']-60000,'Next trading day keeps continuous overlap but never re-dates an older trade');
$expired=$good;$expired['views'][$id]['next']=strtotime('2026-11-12T00:00:00Z')*1000;$start=null;
refresh($expired,$i,function($url)use(&$start){parse_str(parse_url($url,PHP_URL_QUERY),$p);$start=(int)$p['start'];return '[]';},$now);
check($start===strtotime('2026-11-13T00:00:00Z')*1000,'Expired cursor bootstraps only the advertised retained business-day window');
// Warm reads request only the overlap/new publications, not the whole archive.
$warmStart=null;$warm=$good;refresh($warm,$i,function($url)use(&$warmStart){parse_str(parse_url($url,PHP_URL_QUERY),$p);$warmStart=(int)$p['start'];return '[]';},$now+600);
check($warmStart===$good['views'][$id]['through']-60000&&$warm['views'][$id]['through']>$good['views'][$id]['through'],'Warm coverage advances from retained overlap');
// A cancellation on the overlap reuses the same ID and must not be deduplicated away.
foreach(['CANC','AMND'] as $flag){
 $s=$good;$cancel=array_replace($latest,['flags'=>$flag,'tradingSystem'=>'RETL','publicationDateAndTime'=>'2026-11-16T15:45:01Z']);
 $out=refresh($s,$i,fn()=>json_encode([$cancel]),$now+600);
 check($out===[]&&$s['sessions'][$id]['2026-11-16']['hardInvalid']===true,'Late retail '.$flag.' revokes current session across both paths');
 $blocked=L::reconcile([$i],$r['snapshot'],array_replace_recursive($r['state'],['europe'=>['xetra'=>$s]]),$now+600);
 check($blocked['snapshot']['quotes'][$id]['price']===null,'Cache-only retention cannot resurrect '.$flag.' price');
}
foreach(['invalid','hardInvalid','formatUnavailable','capacityExceeded'] as $flag){$s=$good;$s['sessions'][$id]['2026-11-16'][$flag]=true;
 check(refresh($s,$i,fn()=>'[]',$now+600)===[],'Existing file veto remains binding: '.$flag);}
// Incomplete/malformed/error responses never certify coverage.
$faults=['OK','{}','[','null',json_encode([array_replace($latest,['instrumentIdentificationCode'=>'ZZ0000000003'])]),
 json_encode([array_replace($latest,['publicationDateAndTime'=>'2026-11-16T16:00:00Z'])]),json_encode([$latest,$page[0]]),
 json_encode([['continue'=>'bad'],...$page]),json_encode([['continue'=>$token]])];
foreach($faults as $n=>$body){$s=[];check(refresh($s,$i,fn()=>$body,$now)===[]&&$s['views'][$id]['complete']===false,'Invalid page fails closed '.$n);}
$s=[];$calls=0;$out=refresh($s,$i,function()use(&$calls,$first){if(++$calls===1)return $first;throw new RuntimeException('404');},$now);
check($out===[]&&$s['views'][$id]['complete']===false&&$calls===2,'A failed later page preserves progress without admitting price');
$resumeStart=$s['views'][$id]['next'];$resumed=null;
check(isset(refresh($s,$i,function($url)use(&$resumed,$latest){parse_str(parse_url($url,PHP_URL_QUERY),$p);$resumed=(int)$p['start'];return json_encode([$latest]);},$now)[$id])&&$resumed===$resumeStart,'Interrupted pagination resumes the fixed incomplete window');
$s=[];$calls=0;$out=refresh($s,$i,function()use(&$calls,$first){$calls++;return $first;},$now);
check($out===[]&&$calls===2,'Repeated token or saturated boundary fails closed');
$s=[];check(refresh($s,$i,fn()=>json_encode([array_replace($latest,['priceCurrency'=>'USD'])]),$now)===[]&&$s['sessions'][$id]['2026-11-16']['hardInvalid'],'Wrong currency is a cross-path veto');
$s=[];$conflict=array_replace($latest,['price'=>106]);check(refresh($s,$i,fn()=>json_encode([$latest,$conflict]),$now)===[],'Conflicting same-ID trade remains blocked');
// Preparation scopes coverage, and replacement cannot recursively resurrect a removed pending window.
$state=['europe'=>['xetra'=>$good]];$state['europe']['xetra']['views']['other']=['complete'=>true];
$one=P::stateFor($state,$i);check(!isset($one['europe']['xetra']['views']['other']),'Preparation does not borrow another listing coverage');
$new=$one;$new['europe']['xetra']['views'][$id]=['identity'=>'changed','complete'=>false];
$merged=P::mergeState($state,$new,$i);check($merged['europe']['xetra']['views'][$id]===$new['europe']['xetra']['views'][$id]&&isset($merged['europe']['xetra']['views']['other']),'Atomic per-listing coverage replacement preserves other members');
foreach(['https://mvs.deutsche-boerse.com/api?sourceOfData=DETR&exchangeType=pretrade&isin=ZZ0000000002&start=1794556800000&end=1794847499999','https://mvs.deutsche-boerse.com/api?sourceOfData=DETR&exchangeType=posttrade&isin=ZZ0000000002&start=1794556800000&end=1794847499999&extra=1'] as $url){try{Http::provider($url);check(false,'Unsafe view address');}catch(RuntimeException){check(true,'Unreviewed search refused');}}
$bounded=$one;$bounded['europe']['xetra']['views']['other']=['padding'=>str_repeat('x',2097000)];
$cap=P::mergeState($bounded,$one,$i);check($cap['europe']['xetra']['views'][$id]['complete']===false&&$cap['europe']['xetra']['views'][$id]['outcome']==='evidence_limit','Merged view evidence enforces shared size bound');
$order=new ReflectionMethod(RichYet\MarketRefresh::class,'observationOrder');
check($order->invoke(null,'2026-11-16T15:40:00.999000000Z')<$order->invoke(null,'2026-11-16T15:40:00.999500000Z'),'Switching delivery cannot regress within the same second');
echo "Xetra view checks passed: $checks\n";
