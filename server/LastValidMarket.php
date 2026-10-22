<?php
declare(strict_types=1);
namespace RichYet;
require_once __DIR__.'/MarketJson.php';
require_once __DIR__.'/EuropeanHistory.php';

/** One last validated price and complete comparison per exact listing. No
 * personal positions, new source confirmations or provider requests live here. */
final class LastValidMarket
{
    private static function identity(array $i,array $state):string
    {
        return hash('sha256',json_encode([$i['id'],$i['provider'],$i['currency'],$i['mic']??null,$i['isin']??null,
            $i['providerConfig'],$state['us']['identities'][$i['id']]['assetId']??null],JSON_THROW_ON_ERROR));
    }
    private static function rate(mixed $rate):bool
    {
        return is_array($rate)?MarketJson::decimal($rate['numerator']??null)!==null&&MarketJson::decimal($rate['denominator']??null)!==null:MarketJson::decimal($rate)!==null;
    }
    private static function valid(array $i,array $q,int $now,bool $comparison):bool
    {
        if(isset($q['invalidations'])&&(!is_array($q['invalidations'])||array_diff($q['invalidations'],['current','comparison','listing'])))return false;
        if(($i['providerConfig']['identityConfirmed']??false)!==true||!in_array($q['status']??null,['closed','delayed','nav','stale'],true)
            ||MarketJson::decimal($q['price']??null)===null||!MarketJson::date($q['date']??null)||$q['date']>gmdate('Y-m-d',$now)
            ||($q['listingId']??$i['id'])!==$i['id']||($q['mic']??$i['mic']??null)!==($i['mic']??null)||($q['currency']??$i['currency'])!==$i['currency']
            ||($q['isin']??$i['isin']??null)!==($i['isin']??null)
            ||array_intersect($q['invalidations']??[],['current','listing'])
            ||($i['currency']!=='EUR'&&!self::rate($q['fx']??null)))return false;
        foreach(['observedAt','previousObservedAt','fxObservedAt','previousFxObservedAt'] as $field)
            if(isset($q[$field])&&(MarketJson::instant($q[$field])===null||MarketJson::instant($q[$field])>$now))return false;
        if(($q['fxSeries']??null)==='tickerlayer-eurusd-hourly'){
            $start=MarketJson::instant($q['fxObservedAt']??null);$end=MarketJson::instant($q['fxBarEnd']??null);
            if($start===null||$end===null||!USProviders::fxWindowAllowed($start,$end))return false;
        }
        if(!$comparison)return true;
        return MarketJson::decimal($q['previousClose']??null)!==null&&MarketJson::date($q['previousDate']??null)&&$q['previousDate']<$q['date']
            &&!($q['invalidations']??[])&&in_array($q['basis']??'same_units',['same_units','split_adjusted'],true)
            &&($i['currency']==='EUR'||self::rate($q['previousFx']??null))
            &&($i['provider']!=='alpaca'||($q['fxBaselineVerified']??false)===true&&($q['fxBaselineDate']??$q['previousDate'])===$q['previousDate']);
    }
    private static function blocked(array $i,array $q,array $state,array $codes,int $now):bool
    {
        $id=$i['id'];$date=$q['previousDate']??$q['date']??null;
        if(in_array('listing_unverified',$codes,true)||($i['providerConfig']['identityConfirmed']??false)!==true)return true;
        if($i['provider']==='alpaca')return ($state['us']['baselineFxInvalid'][(string)(MarketJson::instant($q['baselineClose']??$q['previousObservedAt']??null)??0)]??false)===true
            ||USProviders::oldBasisUnsafe($state['us']['corporateActions']??[],$date,$i['providerConfig']['symbol'],$now)
            ||(MarketJson::date($q['date']??null)&&MarketJson::date($state['us']['basisInvalidThrough'][$id]??null)
                &&$q['date']<=$state['us']['basisInvalidThrough'][$id])
            ||($state['us']['comparisonChecks'][$id]??null)==='incompatible';
        if(in_array($i['provider'],['euronext','xetra'],true)&&EuropeanActions::oldBasisUnsafe($state['europe']['actions']??[],$id,$date,$now))return true;
        if(($state['europe']['history'][$id]['identityRevoked']??false)===true)return true;
        $history=$state['europe']['history'][$id]??[];
        if(in_array($i['provider'],['euronext','xetra'],true)){
            // An exchange's retained final trade need not equal its separately
            // published daily close. Only compare revisions of the same series.
            $historyPrice=str_starts_with($q['source']??'','Newton Analytics');
            $historyBaseline=$historyPrice||str_contains($q['source']??'','Newton Analytics previous close');
            $previous=$historyBaseline&&MarketJson::date($q['previousDate']??null)?EuropeanHistory::retainedClose($i,$history,$q['previousDate'],$now):null;
            $current=$historyPrice&&($q['priceKind']??null)==='close'&&MarketJson::date($q['date']??null)?EuropeanHistory::retainedClose($i,$history,$q['date'],$now):null;
            if(($previous!==null&&($q['previousClose']??null)!==$previous['close'])||($current!==null&&($q['price']??null)!==$current['close']))return true;
        }
        foreach([$q['date']??null,$q['previousDate']??null] as $d)if($d!==null&&isset($history['retainedInvalidDates'][$d])){
            $confirmed=EuropeanHistory::retainedClose($i,$history,$d,$now);
            if($confirmed===null||($d===($q['previousDate']??null)&&$q['previousClose']!==$confirmed['close'])
                ||($d===($q['date']??null)&&(($q['priceKind']??null)!=='close'||$q['price']!==$confirmed['close'])))return true;
        }
        if($i['provider']==='xetra'){
            if(XetraReference::oldBasisUnsafe($state['europe']['xetra']??[],$id,$date,$now))return true;
            foreach([$q['date']??null,$q['previousDate']??null] as $d)
                if($d!==null&&($state['europe']['xetra']['sessions'][$id][$d]['invalid']??false)===true)return true;
        }
        if($i['provider']==='euronext'){
            $route=$state['europe']['euronext'][$i['providerConfig']['tradingSchedule']??'']??[];
            $through=$route['invalidThrough'][$id]??null;$quoteDate=$q['date']??null;
            if(MarketJson::date($through)&&MarketJson::date($quoteDate)&&$quoteDate<=$through)return true;
            foreach([$q['date']??null,$q['previousDate']??null] as $d)if($d!==null&&isset($route['invalidDates'][$id][$d]))return true;
        }
        return false;
    }
    /** A failed check is distinct from positive evidence of a changed unit basis. */
    private static function softComparisonFailure(array $i,array $state):bool
    {
        return $i['provider']==='alpaca'?($state['us']['comparisonChecks'][$i['id']]??null)==='unavailable'
            :($state['europe']['actions'][$i['id']]['failed']??false)===true;
    }
    public static function reconcile(array $registry,array $snapshot,array $state,int $now):array
    {
        $earlier=[];$ids=array_fill_keys(array_column($registry,'id'),true);
        $state['lastValidMarket']=array_intersect_key($state['lastValidMarket']??[],$ids);
        foreach($registry as $i){
            $id=$i['id'];$q=$snapshot['quotes'][$id]??MarketStore::missing();$fingerprint=self::identity($i,$state);
            $history=$state['europe']['history'][$id]??[];
            $record=$state['lastValidMarket'][$id]??[];
            if(($record['identity']??null)!==$fingerprint)$record=['identity'=>$fingerprint];
            $codes=array_column(array_filter($snapshot['issues'],static fn($v)=>$v['instrumentId']===$id),'code');
            foreach(['price','comparison'] as $field)if(isset($record[$field])&&self::blocked($i,$record[$field],$state,$codes,$now)){
                unset($record[$field]);$record['lastRejectedAt']=$now;$record['lastFailureCodes']=array_values(array_unique($codes));
            }
            $candidateBlocked=self::blocked($i,$q,$state,$codes,$now);
            $invalid=$q['invalidations']??[];
            $incomplete=array_intersect($codes,['baseline_missing','baseline_fx_missing','history_price_missing','history_source_unavailable','history_baseline_missing','history_request_limit','history_invalid']);
            $softCheck=self::softComparisonFailure($i,$state)&&in_array('corporate_action_unverified',$codes,true)
                ||$incomplete&&!in_array('history_basis_unverified',$codes,true);
            $hardInvalid=in_array('listing',$invalid,true)||array_intersect($invalid,['current','comparison'])&&!$softCheck;
            // Known corrections/revocations must also discard the retained set.
            if($candidateBlocked||$hardInvalid)$record=['identity'=>$fingerprint,'lastRejectedAt'=>$now,'lastFailureCodes'=>array_values(array_unique($codes))];
            // Earlier versions could erase this secondary record after an
            // incomplete response. Recover from independently retained positive
            // history plus its accepted dated unit proof, without fetching.
            if(!isset($record['comparison'])&&!$candidateBlocked&&!$hardInvalid&&in_array($i['provider'],['euronext','xetra'],true)){
                $recovered=EuropeanHistory::retainedPair($i,$history,$now);
                if($recovered!==null&&EuropeanActions::retainedCompatible($i,$state['europe']['actions']??[],$recovered['previousDate'],$recovered['date'],$now)
                    &&!self::blocked($i,$recovered,$state,$codes,$now)&&self::valid($i,$recovered,$now,true))$record['comparison']=$recovered;
            }
            $wasEarlier=isset($snapshot['earlierData'][$id]);
            $goodPrice=!$candidateBlocked&&!array_intersect($codes,['corporate_action_unverified','history_basis_unverified'])&&self::valid($i,$q,$now,false);
            $goodPair=$goodPrice&&self::valid($i,$q,$now,true);
            if(!$wasEarlier&&($q['sourceAvailable']??true)!==false){
                if($goodPrice)$record['price']=$q;
                if($goodPair)$record['comparison']=$q;
            }
            $saved=$record['comparison']??$record['price']??null;
            if((!$goodPair||$wasEarlier)&&is_array($saved)&&!$candidateBlocked&&!$hardInvalid
                &&!self::blocked($i,$saved,$state,$codes,$now)&&self::valid($i,$saved,$now,isset($record['comparison']))){
                $q=$saved;$q['status']='stale';$q['sourceAvailable']=false;
                $snapshot['quotes'][$id]=$q;$earlier[$id]=['date'=>$q['date'],'previousDate'=>$q['previousDate']??null];
                // These failures concern the rejected replacement, not the saved
                // validated pair. Keep their diagnostics privately on the ledger.
                $record['lastFailureCodes']=array_values(array_unique($codes));
                $snapshot['issues']=array_values(array_filter($snapshot['issues'],static fn($v)=>$v['instrumentId']!==$id
                    ||!in_array($v['code'],['price_missing','baseline_missing','fx_missing','baseline_fx_missing','corporate_action_unverified','history_basis_unverified','history_invalid','history_price_missing','history_baseline_missing'],true)));
                $snapshot['issues'][]=['instrumentId'=>$id,'code'=>'source_unavailable'];
            }elseif($goodPair&&!$wasEarlier){
                // Merely re-reading a stored pair does not make a new receipt.
                if(($q['sourceAvailable']??true)===false||array_intersect($codes,['source_unavailable','history_source_unavailable','price_stale','fx_stale']))
                    $earlier[$id]=['date'=>$q['date'],'previousDate'=>$q['previousDate']];
            }
            $state['lastValidMarket'][$id]=$record;
        }
        $snapshot['earlierData']=$earlier;
        return ['snapshot'=>$snapshot,'state'=>$state];
    }
}
