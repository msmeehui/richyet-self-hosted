<?php
declare(strict_types=1);
namespace RichYet;
require_once __DIR__.'/MarketJson.php';
require_once __DIR__.'/EuropeanHistory.php';
require_once __DIR__.'/XetraHistoryRecovery.php';

/** Bounded accepted prices and complete comparisons per exact listing. No
 * personal positions, new source confirmations or provider requests live here. */
final class LastValidMarket
{
    private const HISTORY_LIMIT = 8;
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
    private static function blocked(array $i,array $q,array $state,array $codes,int $now,bool $retained=false):bool
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
            $independent=XetraHistoryRecovery::allows($i,$q,$history,$state['europe']['xetra']??[],$state['europe']['actions']??[],$now,$retained);
            foreach([$q['date']??null,$q['previousDate']??null] as $d)
                if($d!==null&&($state['europe']['xetra']['sessions'][$id][$d]['invalid']??false)===true&&!$independent)return true;
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
    /** Scope only provider-confirmed date-local corrections. A generic
     * current/comparison veto is not proof that earlier units remain safe. */
    private static function candidateAffects(array $i,array $candidate,array $saved,array $state,array $codes):bool
    {
        $invalid=$candidate['invalidations']??[];
        if(in_array('listing',$invalid,true)||in_array('history_basis_unverified',$codes,true))return true;
        $localCorrection=static function(?string $date)use($i,$state):bool{
            if(!MarketJson::date($date))return false;
            if($i['provider']==='xetra')return ($state['europe']['xetra']['sessions'][$i['id']][$date]['invalid']??false)===true;
            if($i['provider']==='euronext')return isset($state['europe']['euronext'][$i['providerConfig']['tradingSchedule']??'']['invalidDates'][$i['id']][$date]);
            return false;
        };
        $dates=[];
        if(in_array('current',$invalid,true)){
            if(!$localCorrection($candidate['date']??null))return true;
            $dates[]=$candidate['date'];
        }
        if(in_array('comparison',$invalid,true)){
            $current=$candidate['date']??null;$previous=$candidate['previousDate']??null;
            if(!$localCorrection($current)&&!$localCorrection($previous))return true;
            if($localCorrection($current))$dates[]=$current;
            if($localCorrection($previous))$dates[]=$previous;
        }
        return (bool)array_intersect($dates,[$saved['date']??null,$saved['previousDate']??null]);
    }
    /** Keep at most eight dated revisions. Repeated intraday polls replace
     * their own date/type, so they cannot evict every preceding session. */
    private static function remember(array $history,array $quote,bool $comparison):array
    {
        $key=$quote['date'].($comparison?':comparison':':price');
        $history[$key]=['quote'=>$quote,'comparison'=>$comparison];
        uksort($history,static fn($a,$b)=>strcmp($b,$a));
        return array_slice($history,0,self::HISTORY_LIMIT,true);
    }
    private static function recoveredUnits(array $i,array $quote,array $state,int $now):bool
    {
        return EuropeanActions::retainedCompatible($i,$state['europe']['actions']??[],$quote['previousDate'],$quote['date'],$now)
            ||($i['provider']==='xetra'&&XetraReference::compatible($state['europe']['xetra']??[],$i['id'],$quote['date'],$quote['previousDate']));
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
            $candidateBlocked=self::blocked($i,$q,$state,$codes,$now);
            // Cache-only reads can encounter a new veto beside an older saved
            // independent quote. Do not leave that rejected candidate visible
            // merely because the provider selector is not running on this read.
            if($candidateBlocked&&$i['provider']==='xetra'&&($q['source']??null)==='Newton Analytics daily close'){
                foreach([$q['date']??null,$q['previousDate']??null] as $day)if($day!==null
                    &&($state['europe']['xetra']['sessions'][$id][$day]['invalid']??false)===true){
                    $q=array_replace($q,['price'=>null,'previousClose'=>null,'status'=>'missing']);
                    $snapshot['quotes'][$id]=$q;break;
                }
            }
            $invalid=$q['invalidations']??[];
            $incomplete=array_intersect($codes,['baseline_missing','baseline_fx_missing','history_price_missing','history_source_unavailable','history_baseline_missing','history_request_limit','history_invalid']);
            $softCheck=self::softComparisonFailure($i,$state)&&in_array('corporate_action_unverified',$codes,true)
                ||$incomplete&&!in_array('history_basis_unverified',$codes,true);
            $hardInvalid=in_array('listing',$invalid,true)||array_intersect($invalid,['current','comparison'])&&!$softCheck;
            if($candidateBlocked||$hardInvalid){$record['lastRejectedAt']=$now;$record['lastFailureCodes']=array_values(array_unique($codes));}
            $accepted=[];
            foreach($record['history']??[] as $revision){
                if(!is_array($revision)||!is_array($revision['quote']??null)||!is_bool($revision['comparison']??null))continue;
                $saved=$revision['quote'];$comparison=$revision['comparison'];
                if(self::valid($i,$saved,$now,$comparison))$accepted=self::remember($accepted,$saved,$comparison);
            }
            // Migrate the existing secondary record without inventing acceptance.
            foreach(['price'=>false,'comparison'=>true] as $field=>$comparison)if(isset($record[$field])&&self::valid($i,$record[$field],$now,$comparison))
                $accepted=self::remember($accepted,$record[$field],$comparison);
            foreach($accepted as $key=>$revision)if(self::blocked($i,$revision['quote'],$state,$codes,$now,true)
                ||($hardInvalid&&self::candidateAffects($i,$q,$revision['quote'],$state,$codes))){
                unset($accepted[$key]);$record['lastRejectedAt']=$now;$record['lastFailureCodes']=array_values(array_unique($codes));
            }
            unset($record['price'],$record['comparison']);
            foreach($accepted as $revision){
                if(!isset($record['price']))$record['price']=$revision['quote'];
                if($revision['comparison']&&!isset($record['comparison']))$record['comparison']=$revision['quote'];
            }
            // Earlier versions could erase this secondary record after an
            // incomplete response. Recover from independently retained positive
            // history plus its accepted dated unit proof, without fetching.
            if(!isset($record['comparison'])&&in_array($i['provider'],['euronext','xetra'],true)){
                $remaining=$history;
                for($n=0;$n<12;$n++){
                    $recovered=EuropeanHistory::retainedPair($i,$remaining,$now);
                    if($recovered===null)break;
                    if(self::recoveredUnits($i,$recovered,$state,$now)
                        &&!($hardInvalid&&self::candidateAffects($i,$q,$recovered,$state,$codes))
                        &&!self::blocked($i,$recovered,$state,$codes,$now)&&self::valid($i,$recovered,$now,true)){
                        $record['comparison']=$recovered;$accepted=self::remember($accepted,$recovered,true);break;
                    }
                    // Try an older accepted interval without altering source
                    // evidence or its retained correction markers in state.
                    unset($remaining['retainedCloses'][$recovered['date']]);
                }
            }
            $wasEarlier=isset($snapshot['earlierData'][$id]);
            $goodPrice=!$candidateBlocked&&!array_intersect($codes,['corporate_action_unverified','history_basis_unverified'])&&self::valid($i,$q,$now,false);
            $goodPair=$goodPrice&&self::valid($i,$q,$now,true);
            if(!$wasEarlier&&($q['sourceAvailable']??true)!==false){
                if($goodPrice){$record['price']=$q;$accepted=self::remember($accepted,$q,$goodPair);}
                if($goodPair)$record['comparison']=$q;
            }
            $saved=$record['comparison']??$record['price']??null;
            if((!$goodPair||$wasEarlier)&&is_array($saved)&&!($hardInvalid&&self::candidateAffects($i,$q,$saved,$state,$codes))
                &&!self::blocked($i,$saved,$state,$codes,$now,true)&&self::valid($i,$saved,$now,isset($record['comparison']))){
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
            $record['history']=$accepted;
            $state['lastValidMarket'][$id]=$record;
        }
        $snapshot['earlierData']=$earlier;
        return ['snapshot'=>$snapshot,'state'=>$state];
    }
}
