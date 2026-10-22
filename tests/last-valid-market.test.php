<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/server/bootstrap.php';
require_once dirname(__DIR__).'/server/MarketRefresh.php';
require_once dirname(__DIR__).'/server/InstrumentPreparation.php';
use RichYet\{LastValidMarket,MarketStore,MarketRefresh,InstrumentPreparation};
$checks=0;function check(bool $ok,string $why):void{global $checks;if(!$ok)throw new RuntimeException('FAIL '.$why);$checks++;}
$now=strtotime('2026-10-16T22:00:00Z');
$i=['id'=>'fictional-oak','name'=>'Fictional Oak','symbol'=>'OAK','type'=>'Stock','currency'=>'USD','mic'=>'XNAS','provider'=>'alpaca','providerConfig'=>['symbol'=>'OAK','identityConfirmed'=>true]];
$q=['price'=>'104','previousClose'=>'100','date'=>'2026-10-16','previousDate'=>'2026-10-15','observedAt'=>'2026-10-16T20:00:00Z','previousObservedAt'=>'2026-10-15T20:00:00Z','fx'=>['numerator'=>'1','denominator'=>'1.25'],'previousFx'=>['numerator'=>'1','denominator'=>'1.20'],'status'=>'closed','source'=>'Fictional feed','listingId'=>$i['id'],'mic'=>'XNAS','basis'=>'same_units','invalidations'=>[],'sourceAvailable'=>true,'fxBaselineVerified'=>true];
function snap(array $i,array $q,array $codes=[]):array{$s=MarketStore::emptySnapshot([$i]);$s['quotes'][$i['id']]=$q;$s['issues']=array_map(fn($code)=>['instrumentId'=>$i['id'],'code'=>$code],$codes);return $s;}
$s=snap($i,$q);$seed=LastValidMarket::reconcile([$i],$s,[],$now);check($seed['snapshot']['earlierData']===[],'Friday close on a closed weekend is not automatically earlier data');
check($seed['state']['lastValidMarket'][$i['id']]['comparison']===$q,'Complete validated pair is retained without changing source provenance');
$weekend=$now+2*86400;$before=$seed['state'];
foreach([
 ['previousFx'=>null],['fx'=>null],['previousClose'=>null],['price'=>null,'status'=>'missing'],
] as $change){
 $failed=LastValidMarket::reconcile([$i],snap($i,array_replace($q,$change),['source_unavailable']),$before,$weekend);
 $kept=$failed['snapshot']['quotes'][$i['id']];
 foreach(['price','previousClose','fx','previousFx','date','previousDate','observedAt','previousObservedAt'] as $key)check($kept[$key]===$q[$key],'A failed component retains the complete exact dated bundle: '.$key);
 check(isset($failed['snapshot']['earlierData'][$i['id']])&&$kept['status']==='stale','Ordinary failure is explicitly marked, without inventing a new quote');
 check($failed['state']['lastValidMarket'][$i['id']]['comparison']===$q,'Repeated failures cannot overwrite the last valid set');
}
$failed=snap($i,array_replace($q,['previousClose'=>null,'basis'=>'unverified','invalidations'=>['comparison']]),['corporate_action_unverified']);
$state=$before;$state['us']['comparisonChecks'][$i['id']]='unavailable';
$soft=LastValidMarket::reconcile([$i],$failed,$state,$weekend);
check($soft['snapshot']['quotes'][$i['id']]['previousClose']==='100','An unavailable action check retains an already validated comparison');
check(!in_array('corporate_action_unverified',array_column($soft['snapshot']['issues'],'code'),true),'A rejected replacement cannot invalidate the retained pair in the client');
$state['us']['comparisonChecks'][$i['id']]='incompatible';$unsafe=LastValidMarket::reconcile([$i],$failed,$state,$weekend);
check($unsafe['snapshot']['quotes'][$i['id']]['previousClose']===null&&!isset($unsafe['state']['lastValidMarket'][$i['id']]['comparison']),'Positive incompatible evidence clears saved comparisons');
foreach(['listing','current','comparison'] as $veto){$bad=LastValidMarket::reconcile([$i],snap($i,array_replace($q,['invalidations'=>[$veto],'previousClose'=>null])), $before,$weekend);check(!isset($bad['state']['lastValidMarket'][$i['id']]['comparison']),'An explicit unclassified veto is never bypassed: '.$veto);}
$changed=$i;$changed['providerConfig']['symbol']='DIFFERENT';$x=LastValidMarket::reconcile([$changed],snap($changed,array_replace($q,['price'=>null,'status'=>'missing'])),$before,$weekend);
check(!isset($x['state']['lastValidMarket'][$i['id']]['comparison']),'Listing configuration changes cannot borrow the old identity');
$state=$before;$state['us']['corporateActions']=['forward_splits'=>[['symbol'=>'OAK','ex_date'=>'2026-10-17']]];
$x=LastValidMarket::reconcile([$i],snap($i,array_replace($q,['previousFx'=>null])),$state,$weekend);
check(!isset($x['state']['lastValidMarket'][$i['id']]['comparison']),'A known effective split blocks old figures even when the new failure is FX');
$first=LastValidMarket::reconcile([$i],snap($i,array_replace($q,['previousFx'=>null])),[],$now);
check($first['snapshot']['quotes'][$i['id']]['previousFx']===null,'No complete historic set never fabricates a comparison');
$persisted=json_decode(json_encode($soft,JSON_THROW_ON_ERROR),true,64,JSON_THROW_ON_ERROR);
$again=LastValidMarket::reconcile([$i],$persisted['snapshot'],$persisted['state'],$weekend+3600);
check($again['snapshot']['quotes'][$i['id']]['fx']===$q['fx'],'Persistence and another failed refresh retain the original FX together');
$new=array_replace($q,['price'=>'108','date'=>'2026-10-19','previousDate'=>'2026-10-16','previousClose'=>'104','observedAt'=>'2026-10-19T14:00:00Z','previousObservedAt'=>'2026-10-16T20:00:00Z']);
$recovered=LastValidMarket::reconcile([$i],snap($i,$new),$before,strtotime('2026-10-19T15:00:00Z'));
check($recovered['snapshot']['earlierData']===[]&&$recovered['state']['lastValidMarket'][$i['id']]['comparison']['price']==='108','A valid new session replaces the retained set and clears the warning');
$one=InstrumentPreparation::stateFor($soft['state'],$i);check(isset($one['lastValidMarket'][$i['id']]),'Preparation preserves the per-listing record');
$other=$before;$other['lastValidMarket']['fictional-other']=['identity'=>'different'];$merged=InstrumentPreparation::mergeState($other,$unsafe['state'],$i);
check(!isset($merged['lastValidMarket'][$i['id']]['comparison'])&&$merged['lastValidMarket']['fictional-other']===['identity'=>'different'],'State merging keeps removals and unrelated listings');
// Run the actual refresh selector with a provider outage; not a mocked selector.
$state=$before;$state['us']['nextAttempt']=0;$calls=0;
$built=MarketRefresh::build([$i],$s,$state,static function()use(&$calls):string{$calls++;throw new RuntimeException('Fictional network outage');},$now+3600);
check($built['snapshot']['quotes'][$i['id']]['previousFx']===$q['previousFx']&&isset($built['state']['lastValidMarket'][$i['id']]['comparison']),'Actual refresh retains complete values through a transport outage');
check($calls>0&&$calls<5,'Outage follows existing bounded request behavior');
// The actual US adapter first receives valid Friday data, then a baseline-FX
// or action-check failure on Saturday after the 24-hour revalidation boundary.
$fault=null;$at=strtotime('2026-10-16T22:00:03Z');$requests=[];
$get=static function(string $url)use(&$fault,&$at,&$requests):string{
 $p=parse_url($url);parse_str($p['query']??'',$a);$route=$p['path'];$requests[]=$route;
 if(str_starts_with($route,'/v2/assets/'))return '{"id":"11111111-2222-3333-4444-555555555555","symbol":"OAK","class":"us_equity","status":"active","exchange":"NASDAQ"}';
 if($route==='/v2/calendar')return '[{"date":"2026-10-15","open":"09:30","close":"16:00"},{"date":"2026-10-16","open":"09:30","close":"16:00"}]';
 if($route==='/v1/corporate-actions'){if($fault==='actions')throw new RuntimeException('Fictional unavailable action check');return '{"corporate_actions":{"cash_dividends":[]},"next_page_token":null}';}
 if($route==='/v2/stocks/bars')return '{"bars":{"OAK":[{"t":"2026-10-15T04:00:00Z","c":"100"},{"t":"2026-10-16T04:00:00Z","c":"104"}]},"next_page_token":null}';
 if(str_ends_with($route,'/prev'))return '{"symbol":"EURUSD","result":{"c":"1.25","t":"2026-10-16T19:00:00Z"},"bar_start":"2026-10-16T19:00:00Z","bar_end":"2026-10-16T20:00:00Z"}';
 if(str_contains($route,'/1/hour/')){if($fault==='baseline_fx')throw new RuntimeException('Fictional unavailable historical FX');return '{"symbol":"EURUSD","results":[{"t":"2026-10-15T19:00:00Z","c":"1.20"}],"next_offset":null}';}
 throw new RuntimeException('Unexpected fixture request');
};
$initial=MarketRefresh::build([$i],MarketStore::emptySnapshot([$i]),[],$get,$at);
check($initial['snapshot']['quotes'][$i['id']]['previousFx']!==null&&isset($initial['state']['lastValidMarket'][$i['id']]['comparison']),'Actual successful adapter populates durable complete comparison');
foreach(['baseline_fx','actions'] as $failure){
 $fault=$failure;$at=$now+90000;$broken=MarketRefresh::build([$i],$initial['snapshot'],$initial['state'],$get,$at);
 check($broken['snapshot']['quotes'][$i['id']]['price']==='104'&&$broken['snapshot']['quotes'][$i['id']]['previousClose']==='100'&&$broken['snapshot']['quotes'][$i['id']]['previousFx']===$initial['snapshot']['quotes'][$i['id']]['previousFx'],'Actual '.$failure.' revalidation failure retains the validated tuple');
 check(isset($broken['snapshot']['earlierData'][$i['id']]),'Actual '.$failure.' failure warns quietly');
 $fault=null;$fixed=MarketRefresh::build([$i],$broken['snapshot'],$broken['state'],$get,$at+3600);
 check($fixed['snapshot']['earlierData']===[],'Actual adapter recovery clears warning without deleting ledger');
}
$eu=$i;$eu['id']='fictional-europe';$eu['currency']='EUR';$eu['provider']='euronext';$eu['mic']='XAMS';$eu['isin']='ZZ0000000321';$eu['providerConfig']=['identityConfirmed'=>true,'tradingSchedule'=>'ams_j0_continuous_2026'];
$eq=array_replace($q,['listingId'=>$eu['id'],'mic'=>'XAMS','isin'=>$eu['isin'],'fx'=>'1','previousFx'=>'1']);
$es=LastValidMarket::reconcile([$eu],snap($eu,$eq),[],$now);$es['state']['europe']['actions'][$eu['id']]=['failed'=>true];
$bad=snap($eu,array_replace($eq,['price'=>null,'previousClose'=>null,'status'=>'missing','invalidations'=>['current','comparison']]),['corporate_action_unverified']);
$kept=LastValidMarket::reconcile([$eu],$bad,$es['state'],$now+86400);
check($kept['snapshot']['quotes'][$eu['id']]['price']==='104','Failed European action lookup retains previously validated price and comparison');
$es['state']['europe']['euronext']['ams_j0_continuous_2026']['invalidDates'][$eu['id']]['2026-10-16']=true;
$veto=LastValidMarket::reconcile([$eu],$bad,$es['state'],$now+86400);
check($veto['snapshot']['quotes'][$eu['id']]['price']===null,'An actual correction still overrides a failed-check fallback');
$omitted=snap($eu,array_replace($eq,['previousClose'=>null,'basis'=>'unverified','invalidations'=>['comparison']]),['baseline_missing','history_baseline_missing']);
$kept=LastValidMarket::reconcile([$eu],$omitted,LastValidMarket::reconcile([$eu],snap($eu,$eq),[],$now)['state'],$now+3600);
check($kept['snapshot']['quotes'][$eu['id']]['previousClose']==='100','An omitted European close keeps its separately retained verified pair');
$omitted['issues'][]=['instrumentId'=>$eu['id'],'code'=>'history_basis_unverified'];
$blocked=LastValidMarket::reconcile([$eu],$omitted,LastValidMarket::reconcile([$eu],snap($eu,$eq),[],$now)['state'],$now+3600);
check($blocked['snapshot']['quotes'][$eu['id']]['previousClose']===null,'Conflicting history basis remains a veto rather than a missing response');
if(($argv[1]??null)==='--json')echo json_encode(['instrument'=>$i,'snapshot'=>$soft['snapshot'],'checks'=>$checks],JSON_THROW_ON_ERROR);
else echo "$checks last-valid market checks passed.\n";
