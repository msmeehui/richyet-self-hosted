<?php
declare(strict_types=1);
// Fictional offline records only. No installation configuration or real HTTP.
require dirname(__DIR__).'/server/bootstrap.php';
require dirname(__DIR__).'/server/EuropeanProviders.php';
require dirname(__DIR__).'/server/InstrumentPreparation.php';
use RichYet\{EuropeanProviders as E,EuropeanHistory as H,InstrumentPreparation as P,LastValidMarket as L,MarketStore as M};
$checks=0;
function check(bool $ok,string $why):void{global $checks;if(!$ok)throw new RuntimeException('FAIL '.$why);$checks++;}
function modern(array $changes=[]):array{return array_replace([
    'messageId'=>'FICTIONAL-MESSAGE','rts'=>'1','instrumentIdentificationCode'=>'ZZ0000000002',
    'venueOfExecution'=>'XETA','venueOfPublication'=>'XCEF','priceCurrency'=>'EUR','priceNotation'=>'MONE',
    'price'=>'105','quantity'=>'7','tradingDateAndTime'=>'2026-11-10T09:01:00.123456789Z',
    'publicationDateAndTime'=>'2026-11-10T09:01:00.223456789Z','transactionIdentificationCode'=>'fictional-t1','tradingSystem'=>'CLOB',
],$changes);}
function legacy(array $changes=[]):array{return array_replace(modern(),[
    'priceNotation'=>'1','tradingSystem'=>'1','mmtModificationInd'=>'-','mmtTradingMode'=>'2',
    'mmtBenchmarkRefprcInd'=>'-','mmtPubModeDefReason'=>'-',
],$changes);}
function record(array &$state,array $i,array $row,int $now,string $file='DETR-posttrade-2026-11-10T09_01.json.gz',int $line=1):void{
    static $method=null;$method??=new ReflectionMethod(E::class,'xetraRecord');
    $method->invokeArgs(null,[&$state,$i,$row,$now,$file,$line]);
}
function noRequests(string $url):string{throw new RuntimeException('Unexpected fixture request: '.$url);}
function snap(array $i,array $q,array $codes=[]):array{$s=M::emptySnapshot([$i]);$s['quotes'][$i['id']]=$q;$s['issues']=array_map(fn($c)=>['instrumentId'=>$i['id'],'code'=>$c],$codes);return $s;}
$i=['id'=>'fictional-format','name'=>'Fictional Format ETF','symbol'=>'FICT','exchange'=>'Example Xetra','type'=>'ETF','provider'=>'xetra',
    'currency'=>'EUR','mic'=>'XETA','isin'=>'ZZ0000000002','timeZone'=>'Europe/Berlin',
    'providerConfig'=>['identityConfirmed'=>true,'venue'=>'XETA','publicationVenue'=>'XCEF']];
$id=$i['id'];$date='2026-11-10';$now=strtotime('2026-11-10T18:00:00Z');

foreach([null,'ALGO'] as $flags){
    $row=modern();if($flags!==null)$row['flags']=$flags;
    $s=[];record($s,$i,$row,$now);$q=E::xetraQuote($s['sessions'][$id],$now);
    check(($s['sessions'][$id][$date]['invalid']??false)===false,'Ordinary modern monetary record does not invalidate the session');
    check($q['price']==='105'&&$q['observedAt']===$row['tradingDateAndTime'],'Modern record preserves exact price and nanosecond source time');
    check($q['priceKind']==='trade'&&$q['status']!=='closed'&&$q['previousClose']===null,'Missing phase never invents a closing auction or previous close');
    check(reset($s['sessions'][$id][$date]['trades'])['mode']==='unknown','Modern phase remains explicitly unknown');
}
foreach(['2','O','I','K'] as $mode){
    $s=[];record($s,$i,legacy(['mmtTradingMode'=>$mode]),$now);$q=E::xetraQuote($s['sessions'][$id],$now);
    check($q['price']==='105'&&$q['priceKind']===($mode==='K'?'close':'trade'),'Legacy phase semantics remain intact: '.$mode);
}
// Berlin wall-clock boundaries matter independently of UTC summer/winter time.
foreach([
    ['2026-11-10T07:59:59Z',false],['2026-11-10T08:00:00Z',true],
    ['2026-11-10T16:29:59Z',true],['2026-11-10T16:30:00Z',false],
    ['2026-07-14T06:59:59Z',false],['2026-07-14T07:00:00Z',true],
    ['2026-07-14T15:29:59Z',true],['2026-07-14T15:30:00Z',false],
    ['2026-11-08T10:00:00Z',false],
] as [$time,$eligible]){
    $s=[];record($s,$i,modern(['tradingDateAndTime'=>$time,'publicationDateAndTime'=>$time]),$now);
    $trades=[];foreach($s['sessions'][$id]??[] as $session)$trades=array_merge($trades,array_values($session['trades']??[]));
    check((count($trades)>0)===$eligible,'Modern eligibility follows the reviewed local session: '.$time);
}
$s=[];record($s,$i,legacy(['tradingDateAndTime'=>'2026-11-09T16:35:00Z','publicationDateAndTime'=>'2026-11-09T16:35:01Z','mmtTradingMode'=>'K']),$now);
record($s,$i,modern(['tradingSystem'=>'RETL','retail'=>'RETL']),$now);
check(!isset($s['sessions'][$id][$date])&&E::xetraQuote($s['sessions'][$id],$now)['date']==='2026-11-09','Excluded retail record cannot create an empty newest session hiding the preceding close');
$s=[];record($s,$i,modern(['retail'=>'RETL']),$now);
check(empty($s['sessions'][$id]),'An explicit retail marker remains excluded even when its trading-system field says CLOB');

// Positive corrections and identity contradictions cannot be hidden by an
// otherwise excluded retail, benchmark, or out-of-hours record.
foreach([
    modern(['flags'=>'CANC','tradingSystem'=>'RETL','retail'=>'RETL']),
    modern(['flags'=>'ALGO, CANC']),
    modern(['flags'=>'CANC,BAD']),
    modern(['flags'=>'ALGO,AMND, ']),
    modern(['flags'=>'AMND','tradingDateAndTime'=>'2026-11-10T07:00:00Z','publicationDateAndTime'=>'2026-11-10T07:00:01Z']),
    legacy(['mmtModificationInd'=>'C','mmtTradingMode'=>'X']),
    legacy(['mmtModificationInd'=>'A','mmtBenchmarkRefprcInd'=>'B']),
    modern(['priceCurrency'=>'USD','tradingSystem'=>'RETL']),
    modern(['venueOfPublication'=>'XXXX','tradingSystem'=>'RETL']),
] as $n=>$bad){
    $s=[];record($s,$i,$bad,$now);record($s,$i,modern(['transactionIdentificationCode'=>'later-normal']),$now);
    check(($s['sessions'][$id][$date]['invalid']??false)===true,'Explicit veto survives later ordinary data: '.$n);
    check(E::xetraQuote($s['sessions'][$id],$now)['price']===null,'Explicit veto cannot produce a quote: '.$n);
    $compact=E::compactXetraState(json_decode(json_encode($s,JSON_THROW_ON_ERROR),true));
    check(($compact['sessions'][$id][$date]['invalid']??false)===true,'Compaction and serialization preserve explicit veto: '.$n);
}
foreach([modern(['flags'=>'UNREVIEWED']),modern(['flags'=>['ALGO']]),modern(['flags'=>'ALGO,,']),
    modern(['mmtModificationInd'=>'-']),modern(['priceNotation'=>'UNREVIEWED']),modern(['tradingSystem'=>'UNREVIEWED'])] as $row){
    $s=[];record($s,$i,$row,$now,'DETR-posttrade-2026-11-10T09_01.json.gz',42);$session=$s['sessions'][$id][$date];
    check(($session['formatUnavailable']??false)===true&&($session['invalid']??false)===false,'Unreviewed format is unavailable evidence, not proof of a corporate action');
    $q=E::xetraQuote($s['sessions'][$id],$now);
    check($q['price']===null&&!in_array('corporate_action_unverified',$q['issues'],true),'Unsupported format cannot appear as a valid price or invented corporate-action diagnosis');
    $first=$session['rejections']['first']??[];
    check(($first['file']??null)==='DETR-posttrade-2026-11-10T09_01.json.gz'&&($first['line']??null)===42,'Rejection identifies the source filename and one-based line');
    check(is_string($first['reason']??null)&&$first['reason']!==''&&is_string($first['schema']??null)
        &&preg_match('/^[a-f0-9]{64}$/D',$first['rowSha256']??'')===1,'Rejection records bounded reason/schema and exact-row digest');
    for($n=0;$n<30;$n++)record($s,$i,array_replace($row,['transactionIdentificationCode'=>'rejected-'.$n]),$now,'DETR-posttrade-2026-11-10T09_01.json.gz',43+$n);
    $rejections=$s['sessions'][$id][$date]['rejections'];
    check($rejections['first']===$first&&($rejections['last']['line']??null)===72&&strlen(json_encode($rejections,JSON_THROW_ON_ERROR))<2048,'Rejection history keeps first/last evidence in fixed space');
    check(!isset($rejections['first']['record'],$rejections['first']['price'],$rejections['first']['quantity']),'Diagnostics do not retain raw records or position-like fields');
    $cleared=$s;$cleared['sessions'][$id][$date]['formatUnavailable']=false;
    foreach([[$s,$cleared],[$cleared,$s]] as [$old,$new])foreach([true,false] as $replace){
        $merged=P::mergeState(['europe'=>['xetra'=>$old]],['europe'=>['xetra'=>$new]],$i,$replace);
        check(($merged['europe']['xetra']['sessions'][$id][$date]['formatUnavailable']??false)===true,'Both merge directions retain unsupported-format evidence, including cache-only merges');
    }
}
$older=['price'=>'102','previousClose'=>'100','date'=>'2026-11-06','previousDate'=>'2026-11-05',
    'observedAt'=>'2026-11-06T16:35:00Z','previousObservedAt'=>'2026-11-05T16:35:00Z',
    'status'=>'closed','source'=>'Xetra delayed trades','fx'=>'1','previousFx'=>'1','priceKind'=>'close',
    'basis'=>'same_units','invalidations'=>[],'sourceAvailable'=>true];
$accepted=L::reconcile([$i],snap($i,$older),[],$now);
$accepted['state']['europe']['xetra']=$s;
$unsupported=E::xetraQuote($s['sessions'][$id],$now);
$retained=L::reconcile([$i],snap($i,$unsupported,$unsupported['issues']),$accepted['state'],$now);
check($retained['snapshot']['quotes'][$id]===array_replace($older,['status'=>'stale','sourceAvailable'=>false]),'Unsupported new evidence preserves the complete earlier accepted tuple and original dates');
$current=array_replace($older,['price'=>'105','previousClose'=>'104','date'=>$date,'previousDate'=>'2026-11-09',
    'observedAt'=>modern()['tradingDateAndTime'],'previousObservedAt'=>null,'priceKind'=>'trade','status'=>'delayed']);
$acceptedCurrent=L::reconcile([$i],snap($i,$current),$accepted['state'],$now);
$corrected=[];record($corrected,$i,modern(['flags'=>'ALGO,AMND, ']),$now);
$acceptedCurrent['state']['europe']['xetra']=$corrected;
$cancelled=E::xetraQuote($corrected['sessions'][$id],$now);
$afterCorrection=L::reconcile([$i],snap($i,$cancelled,$cancelled['issues']),$acceptedCurrent['state'],$now);
check($afterCorrection['snapshot']['quotes'][$id]===array_replace($older,['status'=>'stale','sourceAvailable'=>false]),'Same-date correction rejects the current pair but retains the unaffected older complete pair');
foreach($afterCorrection['state']['lastValidMarket'][$id]['history'] as $revision)
    check(!in_array($date,[$revision['quote']['date'],$revision['quote']['previousDate']],true),'Correction removes every affected accepted revision from the last-valid ledger');
// A legacy boolean alone does not prove a format-only rejection. No migration
// may silently erase a real cancellation that happened before the first trade.
$opaque=['sessions'=>[$id=>[$date=>['invalid'=>true,'trades'=>[]]]]];
$s=$opaque;record($s,$i,modern(),$now);
check($s['sessions'][$id][$date]['invalid']===true&&E::xetraQuote($s['sessions'][$id],$now)['price']===null,'An opaque legacy invalid session remains guarded after valid modern records');
$replacement=$s;$replacement['sessions'][$id][$date]['invalid']=false;
$merged=P::mergeState(['europe'=>['xetra'=>$opaque]],['europe'=>['xetra'=>$replacement]],$i);
check($merged['europe']['xetra']['sessions'][$id][$date]['invalid']===true,'Singleton cache merge cannot clear an opaque or genuine prior veto');

$s=[];$row=modern();record($s,$i,$row,$now);record($s,$i,$row,$now);
$s=E::compactXetraState($s);
check($s['sessions'][$id][$date]['transactionCount']===1&&($s['sessions'][$id][$date]['invalid']??false)===false,'Exact duplicate remains idempotent after compact storage');
$s=json_decode(json_encode($s,JSON_THROW_ON_ERROR),true);record($s,$i,modern(['price'=>'106']),$now);
check($s['sessions'][$id][$date]['invalid']===true,'Conflicting same-transaction price survives compressed fingerprint reload');
$s=[];record($s,$i,legacy(),$now);record($s,$i,modern(),$now);
check($s['sessions'][$id][$date]['invalid']===true,'Contradictory phase representations of the same transaction fail closed');
$s=[];record($s,$i,modern(),$now);record($s,$i,modern(['price'=>'106','transactionIdentificationCode'=>'fictional-t2']),$now);
check(E::xetraQuote($s['sessions'][$id],$now)['price']===null,'Different prices at the same latest instant cannot be resolved by record order');

// Exercise the actual gzip collector, cursor transaction, and persistence merge.
$member=hash('sha256',json_encode([$i['isin'],$i['currency'],$i['mic'],'XETA','XCEF'],JSON_THROW_ON_ERROR));
$seed=['xetra'=>['members'=>[$id=>$member],'referenceAttemptAt'=>$now,'sessions'=>[],'cursor'=>null]];
$file='DETR-posttrade-2026-11-10T09_01.json.gz';$archive=gzencode(json_encode(modern(),JSON_THROW_ON_ERROR)."\n");
$calls=[];$get=static function(string $url)use(&$calls,$file,$archive):string{
    $calls[]=$url;
    if(str_ends_with($url,'/api/DETR-posttrade'))return json_encode(['SourcePrefix'=>'DETR-posttrade','CurrentFiles'=>[$file]],JSON_THROW_ON_ERROR);
    if(str_ends_with($url,'/api/download/'.$file))return $archive;
    return noRequests($url);
};
$result=E::refresh([$i],$seed,$get,$now);
check($result['state']['xetra']['cursor']==='2026-11-10T09_01'&&$result['state']['xetra']['collection']['processedFiles']===1,'Actual collector atomically consumes a valid modern minute');
check($result['quotes'][$id]['price']==='105'&&$result['quotes'][$id]['priceKind']==='trade'&&count($calls)===2,'Collector uses only its bounded index/archive fixture requests');
$roundTrip=json_decode(json_encode(['europe'=>$result['state']],JSON_THROW_ON_ERROR),true);
$roundTrip=P::mergeState($roundTrip,P::stateFor($roundTrip,$i),$i);
check(E::xetraQuote($roundTrip['europe']['xetra']['sessions'][$id],$now)['price']==='105','Modern compact session survives saved JSON and singleton merge');
$rejectedArchive=gzencode("\n".json_encode(modern(['flags'=>'UNREVIEWED']),JSON_THROW_ON_ERROR)."\n");
$getRejected=static fn(string $url):string=>str_ends_with($url,'/api/DETR-posttrade')?json_encode(['SourcePrefix'=>'DETR-posttrade','CurrentFiles'=>[$file]],JSON_THROW_ON_ERROR):(str_ends_with($url,'/api/download/'.$file)?$rejectedArchive:noRequests($url));
$rejected=E::refresh([$i],$seed,$getRejected,$now);
check(($rejected['state']['xetra']['sessions'][$id][$date]['rejections']['first']['line']??null)===2,'Collector rejection uses the physical JSON line, including blank lines');
$nextFile='DETR-posttrade-2026-11-10T09_02.json.gz';
foreach(['truncated','malformed-time'] as $fault){
    $before=$result['state'];
    $bytes=gzencode(json_encode(modern(['transactionIdentificationCode'=>'fictional-next','price'=>'107']),JSON_THROW_ON_ERROR)."\n");
    if($fault==='truncated')$bytes=substr($bytes,0,-6);
    else $bytes=gzencode(json_encode(modern(['transactionIdentificationCode'=>'fictional-next','price'=>'107']),JSON_THROW_ON_ERROR)."\n".json_encode(modern(['tradingDateAndTime'=>'not-a-time']),JSON_THROW_ON_ERROR)."\n");
    $getBad=static fn(string $url):string=>str_ends_with($url,'/api/DETR-posttrade')?json_encode(['SourcePrefix'=>'DETR-posttrade','CurrentFiles'=>[$nextFile]],JSON_THROW_ON_ERROR):(str_ends_with($url,'/api/download/'.$nextFile)?$bytes:noRequests($url));
    $bad=E::refresh([$i],$before,$getBad,$now+1);
    check($bad['state']['xetra']['cursor']===$before['xetra']['cursor']&&$bad['state']['xetra']['sessions']===$before['xetra']['sessions'],'Failed archive cannot partially advance cursor or trade evidence: '.$fault);
    check($bad['state']['xetra']['collection']['outcome']==='source_failure','Failed archive remains an explicitly incomplete attempt: '.$fault);
}

// A phase-less current trade can join a separately validated exact previous
// close; neither a new phase nor an auction observation time is manufactured.
$historyInstrument=$i;$historyInstrument['providerConfig']+=['historyProvider'=>'newton','historySymbol'=>'FICT.DE','historyIdentityConfirmed'=>true,'historyIdentityRule'=>'xetra_auction_match'];
$at=strtotime('2026-11-10T10:00:00Z');$s=[];record($s,$historyInstrument,modern(),$at);
$direct=E::xetraQuote($s['sessions'][$id],$at);
$history=['identityVerified'=>true,'identityRevoked'=>false,'fingerprint'=>H::fingerprint($historyInstrument),
    'sourceAvailable'=>true,'fetchedAt'=>$at-60,'rows'=>['2026-11-09'=>['close'=>'100','adjustedClose'=>'100']]];
$references=[];foreach(['2026-11-09','2026-11-10'] as $d)$references[$d]=['date'=>$d,'retrievedAt'=>$at-60,'rows'=>[$id=>['valid'=>true,'identity'=>'123|ZZ0000000002|XETA|Shares|EUR','indicator'=>'']]];
$reference=['references'=>$references];
$joined=E::withHistory($historyInstrument,$direct,null,$history,[],'noRequests',$at,$reference)['quote'];
check($joined['price']==='105'&&$joined['previousClose']==='100'&&$joined['previousDate']==='2026-11-09','Modern current price joins the verified exact preceding-session Newton close');
check($joined['priceKind']==='trade'&&$joined['observedAt']===modern()['tradingDateAndTime']&&$joined['previousObservedAt']===null&&$joined['invalidations']===[],'Joined pair preserves the original trade time and does not invent a baseline auction time');
foreach(['unverified-history','changed-reference','action'] as $fault){
    $h=$history;$r=$reference;$actions=[];
    if($fault==='unverified-history')$h['identityVerified']=false;
    if($fault==='changed-reference')$r['references']['2026-11-10']['rows'][$id]['identity']='different';
    if($fault==='action')$actions[$id]=['events'=>['2026-11-10'=>true]];
    $q=E::withHistory($historyInstrument,$direct,null,$h,$actions,'noRequests',$at,$r)['quote'];
    check($q['previousClose']===null,'Modern format cannot bypass prior-close identity/unit proof: '.$fault);
}
$cancelled=[];record($cancelled,$historyInstrument,modern(['flags'=>'CANC']),$at);
$veto=E::xetraQuote($cancelled['sessions'][$id],$at);
$q=E::withHistory($historyInstrument,$veto,$joined,$history,[],'noRequests',$at,$reference)['quote'];
check($q['price']===null,'Historical fallback cannot conceal a positive current-session cancellation');
$accepted=L::reconcile([$historyInstrument],snap($historyInstrument,$joined),['europe'=>['history'=>[$id=>$history],'xetra'=>$reference]],$at);
$accepted['state']['europe']['xetra']['sessions'][$id]['2026-11-09']=['invalid'=>true,'trades'=>[]];
$blocked=L::reconcile([$historyInstrument],snap($historyInstrument,M::missing(),['source_unavailable']),$accepted['state'],$at);
check($blocked['snapshot']['quotes'][$id]['price']===null,'A prior-session veto still blocks a retained joined pair');
echo "Xetra format checks passed: $checks\n";
