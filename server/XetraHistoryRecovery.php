<?php
declare(strict_types=1);
namespace RichYet;
require_once __DIR__.'/EuropeanHistory.php';
require_once __DIR__.'/EuropeanActions.php';
require_once __DIR__.'/XetraReference.php';

/** An opaque trade-feed warning does not authenticate or invalidate another
 * source's completed closes. Qualify that independent pair without clearing
 * the warning, admitting its trades, or inventing its original cause. */
final class XetraHistoryRecovery
{
    public static function allows(array $i,array $q,array $history,array $xetra,array $actions,int $now,bool $retained=false):bool
    {
        return self::evidence($i,$q,$history,$xetra,$actions,$now,$retained)
            && (self::referenceProof($i,$q,$xetra)||self::actionProof($i,$q,$actions,$now,$retained));
    }

    public static function opaqueSession(array $session):bool
    {
        return ($session['invalid']??false)===true&&(!array_key_exists('hardInvalid',$session)||$session['hardInvalid']===false)
            &&!array_key_exists('rejections',$session);
    }

    /** A valid current trade may use an independently confirmed previous close.
     * This excuses only the previous session's opaque flag, never its trades or
     * a warning on the current session. Retention keeps its original timestamps. */
    public static function allowsTradeBaseline(array $i,array $q,array $history,array $xetra,array $actions,int $now,bool $retained=false):bool
    {
        if(($i['provider']??null)!=='xetra'||($i['providerConfig']['identityConfirmed']??false)!==true
            ||($i['providerConfig']['historyProvider']??null)!=='newton'
            ||($q['source']??null)!=='Xetra delayed trades; Newton Analytics previous close'
            ||($q['listingId']??null)!==($i['id']??null)||($q['mic']??null)!==($i['mic']??null)
            ||($q['isin']??$i['isin']??null)!==($i['isin']??null)
            ||($i['currency']??null)!=='EUR'||($q['currency']??null)!=='EUR'
            ||($q['fx']??null)!=='1'||($q['previousFx']??null)!=='1'
            ||($q['priceKind']??null)!=='trade'||($q['previousKind']??null)!=='close'
            ||($q['previousObservedAt']??null)!==null||($q['basis']??null)!=='same_units'
            ||($q['invalidations']??null)!==[]||isset($q['eligibilityIssue'])
            ||!in_array($q['status']??null,['delayed','stale'],true)
            ||!MarketJson::date($q['date']??null)||!MarketJson::date($q['previousDate']??null))return false;
        $date=$q['date'];$previous=$q['previousDate'];$id=$i['id'];$at=MarketJson::instant($q['observedAt']??null);
        if($at===null||$at>$now||(new \DateTimeImmutable('@'.$at))->setTimezone(new \DateTimeZone($i['timeZone']))->format('Y-m-d')!==$date
            ||EuropeanHistory::previousSession($i['mic'],$date)!==$previous||($q['expectedPreviousDate']??null)!==$previous)return false;
        $current=$xetra['sessions'][$id][$date]??[];$baseline=$xetra['sessions'][$id][$previous]??[];
        if(($current['invalid']??true)!==false||($current['hardInvalid']??false)===true
            ||($current['formatUnavailable']??false)===true||($current['capacityExceeded']??false)===true
            ||!self::opaqueSession($baseline)||isset($xetra['referenceRevoked'][$id])||($history['identityRevoked']??false)===true
            ||XetraReference::oldBasisUnsafe($xetra,$id,$previous,$now)
            ||EuropeanActions::oldBasisUnsafe($actions,$id,$previous,$now))return false;
        try{
            if(($history['fingerprint']??null)!==EuropeanHistory::fingerprint($i))return false;
            $close=EuropeanHistory::retainedClose($i,$history,$previous,$now);
        }catch(\Throwable){return false;}
        if($close===null||$close['evidenceKind']!=='newton-response'||$close['close']!==($q['previousClose']??null))return false;
        foreach(['close','adjustedClose'] as $field){
            $value=MarketJson::decimal($history['rows'][$previous][$field]??null);
            if($value!==null&&$value!==$close[$field])return false;
        }
        foreach($xetra['referenceActions'][$id]??[] as $day=>$_)if($day>$previous&&$day<=$date)return false;
        foreach($actions[$id]['events']??[] as $day=>$_)if($day>$previous&&$day<=$date)return false;
        $timeKey=static function(string $time):string{
            $parts=explode('.',rtrim($time,'Z'),2);return $parts[0].'.'.str_pad($parts[1]??'',9,'0');
        };
        $observedKey=$timeKey($q['observedAt']);$matched=false;$newer=false;
        foreach($current['trades']??[] as $trade){
            if(!is_string($trade['time']??null)||MarketJson::instant($trade['time'])===null
                ||MarketJson::decimal($trade['price']??null)===null)return false;
            $key=$timeKey($trade['time']);
            if($key===$observedKey){
                if(!XetraReference::samePrice($trade['price'],$q['price']??''))return false;
                $matched=true;
            }
            if(strcmp($key,$observedKey)>0)$newer=true;
        }
        if(!$retained){
            if(($q['status']??null)!=='delayed'||($q['sourceAvailable']??null)!==true
                ||!is_int($q['freshUntil']??null)||$q['freshUntil']<=$now||$q['freshUntil']>$at+5400
                ||($xetra['catchupPending']??false)||($xetra['retentionGap']??false)||($xetra['archiveUncertain']??false))return false;
            if(!$matched||$newer)return false;
        }
        return self::referenceProof($i,$q,$xetra)||self::actionProof($i,$q,$actions,$now,$retained);
    }

    private static function evidence(array $i,array $q,array $history,array $xetra,array $actions,int $now,bool $retained):bool
    {
        if(($i['provider']??null)!=='xetra'||($i['providerConfig']['historyProvider']??null)!=='newton'
            ||($q['source']??null)!=='Newton Analytics daily close'||($q['listingId']??null)!==($i['id']??null)
            ||($q['isin']??null)!==($i['isin']??null)||($q['mic']??null)!==($i['mic']??null)
            ||($q['currency']??null)!=='EUR'||($i['currency']??null)!=='EUR'
            ||($q['fx']??null)!=='1'||($q['previousFx']??null)!=='1'
            ||($q['priceKind']??null)!=='close'||($q['previousKind']??null)!=='close'
            ||($q['observedAt']??null)!==null||($q['previousObservedAt']??null)!==null
            ||($q['basis']??null)!=='same_units'||($q['invalidations']??null)!==[]
            ||!in_array($q['status']??null,['closed','stale'],true)
            ||!MarketJson::date($q['date']??null)||!MarketJson::date($q['previousDate']??null))return false;
        $date=$q['date'];$previous=$q['previousDate'];$id=$i['id'];
        if(EuropeanHistory::previousSession($i['mic'],$date)!==$previous
            ||($q['expectedPreviousDate']??null)!==$previous)return false;
        try{
            if(($history['fingerprint']??null)!==EuropeanHistory::fingerprint($i))return false;
            $current=EuropeanHistory::retainedClose($i,$history,$date,$now);
            $prior=EuropeanHistory::retainedClose($i,$history,$previous,$now);
        }catch(\Throwable){return false;}
        if($current===null||$prior===null||$current['close']!==($q['price']??null)||$prior['close']!==($q['previousClose']??null)
            ||!EuropeanHistory::sameUnits($current['close'],$current['adjustedClose'],$prior['close'],$prior['adjustedClose']))return false;
        foreach([$date=>$current,$previous=>$prior] as $day=>$record){
            // This exception needs independently received provider evidence,
            // not an imported snapshot or merely an unverified current row.
            if($record['evidenceKind']!=='newton-response')return false;
            foreach(['close','adjustedClose'] as $field){
                $value=MarketJson::decimal($history['rows'][$day][$field]??null);
                if($value!==null&&$value!==$record[$field])return false;
                if(!$retained&&$value!==$record[$field])return false;
            }
            $session=$xetra['sessions'][$id][$day]??[];
            // Diagnostics predating hardInvalid cannot prove that the first/
            // last bounded examples contain every positive veto. Stay guarded.
            if(($session['hardInvalid']??false)===true
                ||(($session['invalid']??false)===true&&!self::opaqueSession($session)))return false;
        }
        if(isset($xetra['referenceRevoked'][$id])||($history['identityRevoked']??false)===true
            ||XetraReference::oldBasisUnsafe($xetra,$id,$previous,$now)
            ||EuropeanActions::oldBasisUnsafe($actions,$id,$previous,$now))return false;
        foreach($xetra['referenceActions'][$id]??[] as $day=>$_)if($day>$previous&&$day<=$date)return false;
        foreach($actions[$id]['events']??[] as $day=>$_)if($day>$previous&&$day<=$date)return false;
        if(!$retained){
            $context=EuropeanHistory::sessionContext($i['mic'],$now);$fetched=$history['fetchedAt']??null;
            if($context===null||$date!==$context['expectedCloseDate']||$previous!==$context['previousDate']
                ||($q['status']??null)!=='closed'||($q['sourceAvailable']??null)!==true||($history['sourceAvailable']??null)!==true
                ||!is_int($fetched)||$fetched>$now||$fetched<$now-3*3600
                ||!is_int($q['freshUntil']??null)||$q['freshUntil']<=$now||$q['freshUntil']>$context['freshUntil']
                ||$current['lastConfirmedAt']!==$fetched||$prior['lastConfirmedAt']!==$fetched)return false;
        }
        return true;
    }

    private static function referenceProof(array $i,array $q,array $xetra):bool
    {
        if(!XetraReference::compatible($xetra,$i['id'],$q['date'],$q['previousDate']))return false;
        foreach([$q['date'],$q['previousDate']] as $day){
            $identity=$xetra['references'][$day]['rows'][$i['id']]['identity']??null;
            if(!is_string($identity))return false;$parts=explode('|',$identity);
            if(count($parts)!==5||$parts[1]!==$i['isin']||$parts[2]!==($i['providerConfig']['venue']??null)
                ||$parts[3]!=='Shares'||$parts[4]!==$i['currency'])return false;
        }
        return true;
    }

    private static function actionProof(array $i,array $q,array $actions,int $now,bool $retained):bool
    {
        if(!EuropeanActions::retainedCompatible($i,$actions,$q['previousDate'],$q['date'],$now))return false;
        if($retained)return true;
        $today=(new \DateTimeImmutable('@'.$now))->setTimezone(new \DateTimeZone($i['timeZone']))->format('Y-m-d');
        $ttl=$q['date']<$today?86400:21600;
        $fingerprint=hash('sha256',json_encode([$i['isin'],$i['mic'],$i['providerConfig']['actionsSymbol'],$q['previousDate'],$q['date']],JSON_THROW_ON_ERROR));
        $entry=$actions[$i['id']]??[];
        foreach($entry['checks']??[$entry] as $check){
            if(($check['previousDate']??null)===$q['previousDate']&&($check['date']??null)===$q['date']
                &&($check['fingerprint']??null)===$fingerprint
                &&($check['compatible']??false)===true&&($check['failed']??false)!==true
                &&is_int($check['checkedAt']??null)&&($check['retrievedAt']??null)===$check['checkedAt']
                &&$check['checkedAt']<=$now&&$check['checkedAt']>$now-$ttl)return true;
        }
        return false;
    }
}
