<?php
declare(strict_types=1);
// Real adapter/collector paths with wholly fictional offline HTTP responses.
require dirname(__DIR__).'/server/bootstrap.php';
require dirname(__DIR__).'/server/MarketRefresh.php';
use RichYet\{MarketRefresh as R,MarketStore as M};
$checks=0;
function check(bool $ok,string $why):void{global $checks;if(!$ok)throw new RuntimeException('FAIL '.$why);$checks++;}
function encode(mixed $value):string{return json_encode($value,JSON_THROW_ON_ERROR);}
function samePair(array $a,array $b):bool{
 foreach(['price','previousClose','date','previousDate','observedAt','previousObservedAt','fx','previousFx'] as $field)if(($a[$field]??null)!==($b[$field]??null))return false;
 return true;
}
$start=strtotime('2026-10-16T22:00:00Z');$later=$start+90000;
$us=['id'=>'fictional_us','name'=>'Fictional US stock','symbol'=>'FICT','exchange'=>'NASDAQ','type'=>'Stock','currency'=>'USD','mic'=>'XNAS','provider'=>'alpaca','providerConfig'=>['symbol'=>'FICT','identityConfirmed'=>true]];
$identity=['id'=>'11111111-2222-3333-4444-555555555555','symbol'=>'FICT','class'=>'us_equity','status'=>'active','exchange'=>'NASDAQ'];
$assetResponse=$identity;$calls=[];
$usGet=static function(string $url)use(&$assetResponse,&$calls):string{
 $path=parse_url($url,PHP_URL_PATH);$calls[]=$path;
 if(str_starts_with($path,'/v2/assets/')){if($assetResponse==='transport')throw new RuntimeException('Fictional unavailable response');return $assetResponse==='invalid-json'?'not json':encode($assetResponse);}
 if($path==='/v2/calendar')return encode([['date'=>'2026-10-15','open'=>'09:30','close'=>'16:00'],['date'=>'2026-10-16','open'=>'09:30','close'=>'16:00']]);
 if($path==='/v2/stocks/bars')return encode(['bars'=>['FICT'=>[['t'=>'2026-10-15T04:00:00Z','c'=>'100'],['t'=>'2026-10-16T04:00:00Z','c'=>'104']]],'next_page_token'=>null]);
 if($path==='/v1/corporate-actions')return '{"corporate_actions":{"cash_dividends":[]},"next_page_token":null}';
 if(str_ends_with($path,'/prev'))return '{"symbol":"EURUSD","result":{"c":"1.25","t":"2026-10-16T19:00:00Z"},"bar_start":"2026-10-16T19:00:00Z","bar_end":"2026-10-16T20:00:00Z"}';
 if(str_contains($path,'/1/hour/'))return '{"symbol":"EURUSD","results":[{"t":"2026-10-15T19:00:00Z","c":"1.20"}],"next_offset":null}';
 throw new RuntimeException('Unexpected fictional route.');
};
$accepted=R::build([$us],M::emptySnapshot([$us]),[],$usGet,$start);$uq=$accepted['snapshot']['quotes'][$us['id']];
check(isset($accepted['state']['lastValidMarket'][$us['id']]['comparison'])&&$uq['price']==='104','Actual US adapter accepts an exact identified quote and comparison before failures');
$incomplete=['transport'=>'transport','invalid-json'=>'invalid-json','empty-object'=>(object)[]];
foreach(['id','symbol','class','status','exchange'] as $field)foreach(['absent',null,''] as $value){$a=$identity;if($value==='absent')unset($a[$field]);else $a[$field]=$value;$incomplete[$field.'-'.($value??'null')]=$a;}
foreach($incomplete as $name=>$candidate){
 $assetResponse=$candidate;$calls=[];$out=R::build([$us],$accepted['snapshot'],$accepted['state'],$usGet,$later);
 $saved=$out['snapshot']['quotes'][$us['id']];$codes=array_column($out['snapshot']['issues'],'code');
 check(samePair($saved,$uq)&&isset($out['state']['lastValidMarket'][$us['id']]['comparison']),'US '.$name.' retains exact accepted price/comparison/FX and dates');
 check($saved['status']==='stale'&&$saved['sourceAvailable']===false&&isset($out['snapshot']['earlierData'][$us['id']]),'US '.$name.' remains earlier data without a new observation');
 check($out['state']['us']['identities']===$accepted['state']['us']['identities']&&!in_array('listing_unverified',$codes,true),'US '.$name.' neither renews identity proof nor claims a contradictory listing');
 check($calls===['/v2/assets/FICT'],'US '.$name.' cannot admit or request replacement prices after incomplete identity');
 $cold=R::build([$us],M::emptySnapshot([$us]),[],$usGet,$later);
 check($cold['snapshot']['quotes'][$us['id']]['price']===null&&!isset($cold['state']['lastValidMarket'][$us['id']]['comparison'])&&!isset($cold['state']['us']['identities'][$us['id']]),'US '.$name.' supplies no cold-install price or accepted identity');
}
$conflicts=[];
foreach(['id'=>'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee','symbol'=>'OTHER','class'=>'crypto','status'=>'inactive','exchange'=>'NYSE'] as $field=>$value){
 $conflicts['different-'.$field]=array_replace($identity,[$field=>$value]);
 $a=$conflicts['different-'.$field];$a[$field==='id'?'status':'id']=null;$conflicts['different-'.$field.'-with-omission']=$a;
}
$conflicts['malformed-id']=array_replace($identity,['id'=>'broken']);
foreach($conflicts as $name=>$candidate){
 $assetResponse=$candidate;$out=R::build([$us],$accepted['snapshot'],$accepted['state'],$usGet,$later);
 check($out['snapshot']['quotes'][$us['id']]['price']===null&&!isset($out['state']['lastValidMarket'][$us['id']]['comparison'])&&in_array('listing_unverified',array_column($out['snapshot']['issues'],'code'),true),'US '.$name.' still rejects contradictory or malformed explicit identity');
}
$assetResponse=(object)[];$retained=$accepted;
for($n=0;$n<3;$n++){$retained=R::build([$us],$retained['snapshot'],json_decode(encode($retained['state']),true),$usGet,$later+$n*3601);check(samePair($retained['snapshot']['quotes'][$us['id']],$uq),'Repeated US incomplete checks/reload cannot erase the accepted tuple');}
$assetResponse=$identity;$out=R::build([$us],$retained['snapshot'],$retained['state'],$usGet,$later+4*3601);
check(samePair($out['snapshot']['quotes'][$us['id']],$uq)&&$out['snapshot']['earlierData']===[],'Successful US identity revalidation restores normal automatic collection');

$fund=['id'=>'fictional_fund','name'=>'Fictional fund','symbol'=>'FUND','exchange'=>'Daily fund','type'=>'Fund','currency'=>'EUR','mic'=>null,'isin'=>'NL0000000001','provider'=>'bnd','providerConfig'=>['fundId'=>1,'identityConfirmed'=>true]];
$facts=['fundId'=>1,'basicCharacteristics'=>['isin'=>$fund['isin']]];$history=['fundId'=>1,'rates'=>[['date'=>'2026-10-16','nav'=>'104'],['date'=>'2026-10-15','nav'=>'100']]];
$factsResponse=$facts;$historyResponse=$history;$calls=[];
$fundGet=static function(string $url)use(&$factsResponse,&$historyResponse,&$calls):string{
 $path=parse_url($url,PHP_URL_PATH);$calls[]=$path;$response=str_ends_with($path,'/factsheetdata')?$factsResponse:$historyResponse;
 if($response==='transport')throw new RuntimeException('Fictional unavailable response');return $response==='invalid-json'?'not json':encode($response);
};
$accepted=R::build([$fund],M::emptySnapshot([$fund]),[],$fundGet,$start);$fq=$accepted['snapshot']['quotes'][$fund['id']];
check(isset($accepted['state']['lastValidMarket'][$fund['id']]['comparison'])&&$fq['price']==='104','Actual fund adapter first accepts a complete exact-share-class NAV pair');
$incomplete=['transport'=>['transport',$history],'invalid-json'=>['invalid-json',$history],'empty-facts'=>[(object)[],$history],'empty-history'=>[$facts,(object)[]]];
foreach(['facts-fund','history-fund','facts-isin'] as $field)foreach(['absent',null,''] as $value){
 $f=$facts;$h=$history;
 if($field==='facts-fund'){if($value==='absent')unset($f['fundId']);else $f['fundId']=$value;}
 elseif($field==='history-fund'){if($value==='absent')unset($h['fundId']);else $h['fundId']=$value;}
 else{if($value==='absent')unset($f['basicCharacteristics']['isin']);else $f['basicCharacteristics']['isin']=$value;}
 $incomplete[$field.'-'.($value??'null')]=[$f,$h];
}
foreach($incomplete as $name=>[$factsResponse,$historyResponse]){
 $out=R::build([$fund],$accepted['snapshot'],$accepted['state'],$fundGet,$later);$saved=$out['snapshot']['quotes'][$fund['id']];
 check(samePair($saved,$fq)&&isset($out['state']['lastValidMarket'][$fund['id']]['comparison']),'Fund '.$name.' retains the complete original NAV comparison');
 check($saved['status']==='stale'&&$saved['sourceAvailable']===false&&isset($out['snapshot']['earlierData'][$fund['id']])&&!in_array('listing_unverified',array_column($out['snapshot']['issues'],'code'),true),'Fund '.$name.' is unavailability rather than a new or contradictory valuation');
 check($out['state']['europe']['bnd'][$fund['id']]['contribution']===$accepted['state']['europe']['bnd'][$fund['id']]['contribution'],'Fund '.$name.' cannot rearm a daily contribution window');
 $cold=R::build([$fund],M::emptySnapshot([$fund]),[],$fundGet,$later);
 check($cold['snapshot']['quotes'][$fund['id']]['price']===null&&!isset($cold['state']['lastValidMarket'][$fund['id']]['comparison']),'Fund '.$name.' cannot supply a cold-install price');
}
$factsResponse=(object)[];$historyResponse=['fundId'=>1,'rates'=>[['date'=>'2026-10-17','nav'=>'108'],['date'=>'2026-10-16','nav'=>'104']]];
$out=R::build([$fund],$accepted['snapshot'],$accepted['state'],$fundGet,$later);
check(samePair($out['snapshot']['quotes'][$fund['id']],$fq)&&$out['state']['europe']['bnd'][$fund['id']]['contribution']===$accepted['state']['europe']['bnd'][$fund['id']]['contribution'],'A newer NAV cannot be admitted or rearm a window while its identity response is incomplete');
$conflicts=[
 'different-facts-fund'=>[array_replace($facts,['fundId'=>2]),$history],
 'different-history-fund'=>[$facts,array_replace($history,['fundId'=>2])],
 'different-isin'=>[['fundId'=>1,'basicCharacteristics'=>['isin'=>'NL0000000002']],$history],
 'different-fund-with-missing-isin'=>[['fundId'=>2],$history],
 'different-isin-with-missing-fund'=>[['basicCharacteristics'=>['isin'=>'NL0000000002']],$history],
 'different-history-fund-with-missing-facts'=>[(object)[],array_replace($history,['fundId'=>2])],
 'malformed-isin'=>[['fundId'=>1,'basicCharacteristics'=>['isin'=>'broken']],$history],
];
foreach($conflicts as $name=>[$factsResponse,$historyResponse]){
 $out=R::build([$fund],$accepted['snapshot'],$accepted['state'],$fundGet,$later);
 check($out['snapshot']['quotes'][$fund['id']]['price']===null&&!isset($out['state']['lastValidMarket'][$fund['id']]['comparison'])&&in_array('listing_unverified',array_column($out['snapshot']['issues'],'code'),true),'Fund '.$name.' still rejects the changed share class or malformed explicit identity');
}
$factsResponse=(object)[];$historyResponse=$history;$retained=$accepted;
for($n=0;$n<3;$n++){$retained=R::build([$fund],$retained['snapshot'],json_decode(encode($retained['state']),true),$fundGet,$later+$n*3601);check(samePair($retained['snapshot']['quotes'][$fund['id']],$fq),'Repeated fund incomplete checks/reload preserve its accepted NAV pair');}
$factsResponse=$facts;$out=R::build([$fund],$retained['snapshot'],$retained['state'],$fundGet,$later+4*3601);
check(samePair($out['snapshot']['quotes'][$fund['id']],$fq)&&$out['snapshot']['earlierData']===[],'Complete fund identity restores normal automatic collection');
echo "Incomplete identity retention checks passed: $checks\n";
