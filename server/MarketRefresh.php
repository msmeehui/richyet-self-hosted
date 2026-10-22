<?php
declare(strict_types=1);
namespace RichYet;
require_once __DIR__.'/USProviders.php';
require_once __DIR__.'/EuropeanProviders.php';
require_once __DIR__.'/FundContribution.php';
require_once __DIR__.'/LastValidMarket.php';

final class MarketRefresh
{
    public static function run(MarketStore $store,int $ownerId,callable $get,int $now,?callable $reportingDownload=null):array
    {
        $account=$store->account($ownerId);if(!$account)throw new \RuntimeException('Live portfolio is not configured.');
        if(isset($account['catalogue_id'])){
            $preparation=$store->preparation($get,$now);$preparation->prune($account['catalogue_id'],$now);$account=$store->account($ownerId);
            $selected=$preparation->refreshRegistry($account['catalogue_id']);
        }else $selected=null;
        $registry=$store->registry($ownerId);$snapshot=json_decode($account['snapshot_json'],true,64,JSON_THROW_ON_ERROR);$state=json_decode($account['state_json'],true,64,JSON_THROW_ON_ERROR);
        $result=$selected===null?self::build($registry,$snapshot,$state,$get,$now):self::selected($registry,$selected,$snapshot,$state,$get,$now);
        if($selected===[]){self::reporting($store,[],[],[],$now,$reportingDownload);return $result['counts'];}
        if($selected!==null)$result['counts']=$preparation->saveHeldRefresh($account,$result['snapshot'],$result['state'],$now);
        else $store->saveRefresh($ownerId,$result['snapshot'],$result['state'],$now,isset($account['catalogue_id'])?(int)$account['revision']:null);
        $saved=$store->account($ownerId);
        $savedQuotes=json_decode($saved['snapshot_json'],true,64,JSON_THROW_ON_ERROR)['quotes'];
        self::reporting($store,$selected??$registry,$snapshot['quotes'],$savedQuotes,$now,$reportingDownload);
        return $result['counts'];
    }
    /** CLI preparation only: no owner data is read or copied into the shared cache. */
    public static function catalogue(MarketStore $store,string $id,callable $get,int $now,?callable $reportingDownload=null):array
    {
        $preparation=$store->preparation($get,$now);$preparation->prune($id,$now);$selected=$preparation->refreshRegistry($id);
        $catalogues=$store->catalogues();$row=$catalogues->row($id);$registry=CatalogueStore::validateRow($row);$snapshot=json_decode($row['snapshot_json'],true,64,JSON_THROW_ON_ERROR);$state=json_decode($row['state_json'],true,64,JSON_THROW_ON_ERROR);
        $result=$selected===null?self::build($registry,$snapshot,$state,$get,$now):self::selected($registry,$selected,$snapshot,$state,$get,$now);
        if($selected===[]){self::reporting($store,[],[],[],$now,$reportingDownload);return $result['counts'];}
        if($selected!==null)$result['counts']=$preparation->saveHeldRefresh($row,$result['snapshot'],$result['state'],$now);
        else $catalogues->saveRefresh($id,(int)$row['revision'],$result['snapshot'],$result['state'],$now);
        $saved=$catalogues->row($id);
        $savedQuotes=json_decode($saved['snapshot_json'],true,64,JSON_THROW_ON_ERROR)['quotes'];
        self::reporting($store,$selected??$registry,$snapshot['quotes'],$savedQuotes,$now,$reportingDownload);
        return $result['counts'];
    }
    private static function reporting(MarketStore $store,array $registry,array $old,array $new,int $now,?callable $download=null):void
    {
        // Reporting failures cannot overwrite successfully accepted market data.
        try{$store->reporting()->recordUpdates($registry,$old,$new,$download===null?time():$now);}catch(\Throwable){error_log('RichYet received-price receipt unavailable.');}
        try{$store->reporting()->collectFx($download===null?time():$now,$download);}catch(\Throwable){error_log('RichYet reporting currency cache unavailable.');}
    }
    /** Refresh only member-held IDs while preserving inactive cached prices and
     * their correction/action evidence for a later bounded preparation. */
    private static function selected(array $registry,array $selected,array $snapshot,array $state,callable $get,int $now):array
    {
        if(!$selected)return ['snapshot'=>$snapshot,'state'=>$state,'counts'=>['instruments'=>0,'prices'=>0,'issues'=>0]];
        $ids=array_fill_keys(array_column($selected,'id'),true);$partial=$snapshot;$partial['quotes']=array_intersect_key($snapshot['quotes'],$ids);$partial['issues']=array_values(array_filter($snapshot['issues'],static fn($issue)=>isset($ids[$issue['instrumentId']])));$subset=[];
        foreach($selected as $i)$subset=InstrumentPreparation::mergeState($subset,InstrumentPreparation::stateFor($state,$i),$i);
        $result=self::build($selected,$partial,$subset,$get,$now);$merged=$state;
        foreach($selected as $i)$merged=InstrumentPreparation::mergeState($merged,$result['state'],$i);
        // These clocks cover the selected recurring set. Singleton preparation
        // deliberately cannot move them for the other recurring holdings.
        foreach(['members','nextAttempt','corporateActionsCheckedAt'] as $field)if(isset($result['state']['us'][$field]))$merged['us'][$field]=$result['state']['us'][$field];
        foreach($result['state']['europe']['euronext']??[] as $schedule=>$route)foreach(['members','fingerprint','attemptAt','nextAttempt','successAt','lastAttemptFailed'] as $field)if(array_key_exists($field,$route))$merged['europe']['euronext'][$schedule][$field]=$route[$field];
        foreach($result['state']['europe']['xetra']??[] as $field=>$value)if(!in_array($field,['sessions','reviewedPairs','referenceIdentity','referenceRevoked','referenceActions','references'],true))$merged['europe']['xetra'][$field]=$value;
        $snapshot['quotes']=array_replace($snapshot['quotes'],$result['snapshot']['quotes']);$snapshot['issues']=array_merge(array_values(array_filter($snapshot['issues'],static fn($issue)=>!isset($ids[$issue['instrumentId']]))),$result['snapshot']['issues']);
        $snapshot['earlierData']=array_replace(array_diff_key($snapshot['earlierData']??[],$ids),$result['snapshot']['earlierData']??[]);
        foreach(['snapshotId','generatedAt','lastAttemptAt','nextRefreshAt'] as $field)$snapshot[$field]=$result['snapshot'][$field];
        $fx=$merged['us']['currentFx']??null;
        if($fx!==null&&USProviders::fxWindowAllowed($fx['start'],$fx['end']))foreach($registry as $i)if($i['currency']==='USD'&&$snapshot['quotes'][$i['id']]['price']!==null&&!isset($snapshot['earlierData'][$i['id']])){
            $q=&$snapshot['quotes'][$i['id']];$q['fx']=$fx['rate'];$q['fxObservedAt']=gmdate('Y-m-d\TH:i:s\Z',$fx['start']);$q['fxBarEnd']=gmdate('Y-m-d\TH:i:s\Z',$fx['end']);$q['fxFreshUntil']=USProviders::fxFreshUntil($fx,$now);$q['fxSeries']='tickerlayer-eurusd-hourly';unset($q);
        }
        MarketStore::validateSnapshot($snapshot,$registry);
        return ['snapshot'=>$snapshot,'state'=>$merged,'counts'=>$result['counts']];
    }
    public static function build(array $registry,array $snapshot,array $state,callable $get,int $now):array
    {
        $retained=LastValidMarket::reconcile($registry,$snapshot,$state,$now);$state=$retained['state'];
        // The source adapter replaces its attempt record on every request. Keep
        // the accepted valuation watermark independently of that request cycle.
        $priorFundState=$state['europe']['bnd']??[];$priorQuotes=$snapshot['quotes'];
        foreach(['us','europe'] as $group){
            $selected=array_values(array_filter($registry,static fn($i)=>$group==='us'?$i['provider']==='alpaca':in_array($i['provider'],['bnd','xetra','euronext'],true)));
            if(!$selected)continue;
            try{$result=$group==='us'?USProviders::refresh($selected,$state[$group]??[],$get,$now):EuropeanProviders::refresh($selected,$state[$group]??[],$get,$now);}
            catch(\Throwable){$result=['quotes'=>[],'state'=>$state[$group]??[],'issues'=>array_map(static fn($i)=>['instrumentId'=>$i['id'],'code'=>'source_unavailable'],$selected)];}
            $state[$group]=$result['state'];$touched=array_unique(array_merge(array_keys($result['quotes']),array_column($result['issues'],'instrumentId')));
            $snapshot['issues']=array_values(array_filter($snapshot['issues'],static fn($i)=>!in_array($i['instrumentId'],$touched,true)));
            foreach($result['quotes'] as $id=>$quote){
                if(!in_array($id,array_column($selected,'id'),true))throw new \RuntimeException('Unexpected source listing.');
                $quote=MarketStore::applyInvalidations(array_replace(MarketStore::missing(),$quote));$old=$snapshot['quotes'][$id]??MarketStore::missing();
                $codes=array_column(array_filter($result['issues'],static fn($issue)=>$issue['instrumentId']===$id),'code');
                $regressed=$quote['price']!==null&&$old['price']!==null&&(($quote['date']??'')<($old['date']??'')||($quote['date']===$old['date']&&MarketJson::instant($quote['observedAt'])!==null&&MarketJson::instant($quote['observedAt'])<(MarketJson::instant($old['observedAt'])??0)));
                $invalidations=$quote['invalidations']??[];$comparisonInvalidated=is_array($invalidations)&&in_array('comparison',$invalidations,true);
                $partialPair=$quote['price']!==null&&$old['previousClose']!==null&&$quote['previousClose']===null&&!in_array('corporate_action_unverified',$codes,true)&&!$comparisonInvalidated;
                if($regressed||$partialPair){$quote=$old;if($comparisonInvalidated){$quote['previousClose']=null;$quote['previousFx']=null;$quote['invalidations']=array_values(array_unique(array_merge($quote['invalidations']??[],['comparison'])));}$quote['status']='stale';$quote['sourceAvailable']=false;$snapshot['issues'][]=['instrumentId'=>$id,'code'=>'source_unavailable'];}
                $snapshot['quotes'][$id]=$quote;
            }
            foreach($result['issues'] as $issue){$snapshot['issues'][]=$issue;if($issue['code']==='source_unavailable'&&($snapshot['quotes'][$issue['instrumentId']]['price']??null)!==null){$snapshot['quotes'][$issue['instrumentId']]['status']='stale';$snapshot['quotes'][$issue['instrumentId']]['sourceAvailable']=false;}}
        }
        // A single current USD observation applies to every USD holding, including
        // a retained price after a partial provider failure. Its baseline stays paired.
        $fx=$state['us']['currentFx']??null;if($fx!==null&&!USProviders::fxWindowAllowed($fx['start'],$fx['end']))$fx=null;
        foreach($registry as $i){$id=$i['id'];$snapshot['quotes'][$id]=MarketStore::applyInvalidations($snapshot['quotes'][$id]);$q=&$snapshot['quotes'][$id];
            if(in_array($i['provider'],['euronext','xetra'],true)&&EuropeanActions::oldBasisUnsafe($state['europe']['actions']??[],$id,$q['date'],$now)){$q['price']=null;$q['previousClose']=null;$q['status']='missing';$snapshot['issues'][]=['instrumentId'=>$id,'code'=>'corporate_action_unverified'];}
            if($i['provider']==='alpaca'&&(USProviders::oldBasisUnsafe($state['us']['corporateActions']??[],$q['date'],$i['providerConfig']['symbol'],$now)||($q['date']!==null&&$q['date']<=($state['us']['basisInvalidThrough'][$id]??'')))){$q['price']=null;$q['previousClose']=null;$q['status']='missing';$snapshot['issues'][]=['instrumentId'=>$id,'code'=>'corporate_action_unverified'];}
            if($i['provider']==='xetra'&&XetraReference::oldBasisUnsafe($state['europe']['xetra']??[],$id,$q['date'],$now)){$q['price']=null;$q['previousClose']=null;$q['status']='missing';$snapshot['issues'][]=['instrumentId'=>$id,'code'=>'corporate_action_unverified'];}
            if($i['provider']==='unsupported')$snapshot['issues'][]=['instrumentId'=>$id,'code'=>'source_unsupported'];
            if($i['currency']==='USD'&&$fx===null)$q['fx']=null;
            if($i['currency']==='USD'&&$fx!==null&&$q['price']!==null){$q['fx']=$fx['rate'];$q['fxObservedAt']=gmdate('Y-m-d\TH:i:s\Z',$fx['start']);$q['fxBarEnd']=gmdate('Y-m-d\TH:i:s\Z',$fx['end']);$q['fxFreshUntil']=USProviders::fxFreshUntil($fx,$now);$q['fxSeries']='tickerlayer-eurusd-hourly';if(USProviders::fxStale($fx,$now))$snapshot['issues'][]=['instrumentId'=>$id,'code'=>'fx_stale'];}
            if($q['price']===null)$snapshot['issues'][]=['instrumentId'=>$id,'code'=>'price_missing'];
            elseif($q['fx']===null)$snapshot['issues'][]=['instrumentId'=>$id,'code'=>'fx_missing'];
            if($q['previousClose']===null)$snapshot['issues'][]=['instrumentId'=>$id,'code'=>'baseline_missing'];
            elseif($q['previousFx']===null)$snapshot['issues'][]=['instrumentId'=>$id,'code'=>'baseline_fx_missing'];
            if(FundContribution::identity($i)!==null){
                $contribution=FundContribution::reconcile($i,$priorQuotes[$id]??MarketStore::missing(),$q,$priorFundState[$id]['contribution']??null,$now);
                $state['europe']['bnd'][$id]['contribution']=$contribution['ledger'];
            }
            // Add the receipt only when serving the authenticated response. The
            // persisted quote schema stays compatible with the rollback source.
            unset($q['fundContribution']);
            unset($q);
        }
        $issues=[];foreach($snapshot['issues'] as $issue)$issues[$issue['instrumentId'].':'.$issue['code']]=$issue;$snapshot['issues']=array_values($issues);
        // A new provider result gets a new decision; an untouched failed cache
        // keeps its prior earlier-data marker until an actual valid replacement.
        foreach($registry as $i)if(($snapshot['quotes'][$i['id']]['sourceAvailable']??false)===true)unset($snapshot['earlierData'][$i['id']]);
        $retained=LastValidMarket::reconcile($registry,$snapshot,$state,$now);$snapshot=$retained['snapshot'];$state=$retained['state'];
        $snapshot['snapshotId']=bin2hex(random_bytes(16));$snapshot['generatedAt']=$snapshot['lastAttemptAt']=gmdate('Y-m-d\TH:i:s\Z',$now);$snapshot['nextRefreshAt']=gmdate('Y-m-d\TH:i:s\Z',$now+600);
        return ['snapshot'=>$snapshot,'state'=>$state,'counts'=>['instruments'=>count($registry),'prices'=>count(array_filter($snapshot['quotes'],static fn($q)=>$q['price']!==null)),'issues'=>count($snapshot['issues'])]];
    }
}
