<?php
declare(strict_types=1);
namespace RichYet;
require_once __DIR__.'/MarketJson.php';

/** A bounded provider-reported split check for a reviewed listing and pair.
 * It is evidence for this interval, not a guarantee of complete action coverage.
 * Any reported event is withheld for review; no split factor is guessed.
 */
final class EuropeanActions
{
    // Keep current intervals for six hours and completed intervals for one day.
    // Several dated intervals share one listing ledger, so a carried close and
    // its own older comparison cannot evict each other's proof every tick.
    private const CACHE_SECONDS = 21600;
    private const ZONES = ['XAMS'=>'Europe/Amsterdam','XPAR'=>'Europe/Paris','XBRU'=>'Europe/Brussels','XETR'=>'Europe/Berlin','XETA'=>'Europe/Berlin'];
    private const SUFFIXES = ['XAMS'=>'.AS','XPAR'=>'.PA','XBRU'=>'.BR','XETR'=>'.XETRA','XETA'=>'.XETRA'];
    public static function check(array $instrument,string $previous,string $current,array $state,callable $get,int $now):array
    {
        $c=$instrument['providerConfig']??[];$id=$instrument['id'];
        $result=['compatible'=>false,'state'=>$state,'issue'=>'corporate_action_unverified'];
        $zone=self::ZONES[$instrument['mic']??'']??null;
        if($zone===null||(isset($instrument['timeZone'])&&$instrument['timeZone']!==$zone))return $result;
        $today=(new \DateTimeImmutable('@'.$now))->setTimezone(new \DateTimeZone($zone))->format('Y-m-d');
        if(($c['actionsProvider']??null)!=='eodhd'||($c['actionsIdentityConfirmed']??false)!==true
            ||($c['identityConfirmed']??false)!==true||($instrument['currency']??null)!=='EUR'
            ||!preg_match('/^[A-Z0-9.-]{1,30}$/D',$c['actionsSymbol']??'')
            ||!str_ends_with($c['actionsSymbol'],self::SUFFIXES[$instrument['mic']])
            ||!MarketJson::date($previous)||!MarketJson::date($current)||$previous>=$current
            ||$current>$today||strtotime($current)-strtotime($previous)>10*86400)return $result;
        $identity=hash('sha256',json_encode([$instrument['isin']??null,$instrument['mic']??null,$c['actionsSymbol']],JSON_THROW_ON_ERROR));
        $fingerprint=hash('sha256',json_encode([$instrument['isin']??null,$instrument['mic']??null,$c['actionsSymbol'],$previous,$current],JSON_THROW_ON_ERROR));
        $prior=$state[$id]??[];
        if(isset($prior['identity'])&&$prior['identity']!==$identity)$prior=[];
        $checks=$prior['checks']??[];
        if(!isset($prior['checks'])&&isset($prior['fingerprint']))$checks[$prior['fingerprint']]=array_intersect_key($prior,array_flip(['fingerprint','checkedAt','previousDate','date','compatible','retrievedAt','failed']));
        $events=$prior['events']??[];$cached=$checks[$fingerprint]??[];$ttl=$current<$today?86400:self::CACHE_SECONDS;
        if(is_int($cached['checkedAt']??null)&&$cached['checkedAt']<=$now&&$now-$cached['checkedAt']<$ttl){
            $result['compatible']=($cached['compatible']??false)===true&&!self::eventBetween($events,$previous,$current);
            $result['issue']=$result['compatible']?null:'corporate_action_unverified';return $result;
        }
        $next=['fingerprint'=>$fingerprint,'checkedAt'=>$now,'previousDate'=>$previous,'date'=>$current,'compatible'=>false];
        try{
            $url='https://eodhd.com/api/splits/'.$c['actionsSymbol'].'?'.http_build_query(['from'=>$previous,'to'=>$current,'fmt'=>'json'],'','&',PHP_QUERY_RFC3986);
            $rows=MarketJson::decode($get($url,1048576));
            if(!is_array($rows)||!array_is_list($rows)||count($rows)>100)throw new \RuntimeException('Invalid action response.');
            foreach($rows as $r){
                if(!is_array($r)||!MarketJson::date($r['date']??null)||$r['date']<$previous||$r['date']>$current
                    ||!is_string($r['split']??null)||!preg_match('~^[0-9]+(?:\.[0-9]+)?/[0-9]+(?:\.[0-9]+)?$~D',$r['split']))throw new \RuntimeException('Invalid split observation.');
                $events[$r['date']]=true;
            }
            $next['compatible']=!self::eventBetween($events,$previous,$current);$next['retrievedAt']=$now;
        }catch(\Throwable){$next['failed']=true;}
        $checks[$fingerprint]=$next;
        $checks=array_filter($checks,static fn($v)=>is_int($v['checkedAt']??null)&&$v['checkedAt']<=$now&&$v['checkedAt']>=$now-3*86400);
        uasort($checks,static fn($a,$b)=>$b['checkedAt']<=>$a['checkedAt']);$checks=array_slice($checks,0,6,true);
        $result['state'][$id]=$next+['identity'=>$identity,'timeZone'=>$zone,'events'=>$events,'checks'=>$checks];
        $result['compatible']=$next['compatible'];$result['issue']=$next['compatible']?null:'corporate_action_unverified';return $result;
    }
    private static function eventBetween(array $events,string $previous,string $current):bool
    {
        foreach($events as $date=>$_)if($date>$previous&&$date<=$current)return true;
        return false;
    }

    /** A previous successful exact-interval check may qualify its retained
     * completed pair. Expiry prevents admitting new prices, not reading old ones. */
    public static function retainedCompatible(array $instrument,array $state,string $previous,string $current,int $now):bool
    {
        $c=$instrument['providerConfig']??[];$entry=$state[$instrument['id']]??[];
        if(($c['identityConfirmed']??false)!==true||($c['actionsIdentityConfirmed']??false)!==true
            ||($c['actionsProvider']??null)!=='eodhd'||!MarketJson::date($previous)||!MarketJson::date($current)||$previous>=$current)return false;
        $identity=hash('sha256',json_encode([$instrument['isin']??null,$instrument['mic']??null,$c['actionsSymbol']??null],JSON_THROW_ON_ERROR));
        if(($entry['identity']??null)!==$identity||self::eventBetween($entry['events']??[],$previous,$current))return false;
        foreach($entry['checks']??[$entry] as $check)if(($check['previousDate']??null)===$previous&&($check['date']??null)===$current
            &&($check['compatible']??false)===true&&($check['failed']??false)!==true
            &&is_int($check['retrievedAt']??null)&&$check['retrievedAt']<=$now)return true;
        return false;
    }

    public static function oldBasisUnsafe(array $state,string $id,?string $quoteDate,int $now):bool
    {
        if($quoteDate===null)return false;
        $zone=$state[$id]['timeZone']??'UTC';if(!in_array($zone,array_values(self::ZONES),true))$zone='UTC';
        $today=(new \DateTimeImmutable('@'.$now))->setTimezone(new \DateTimeZone($zone))->format('Y-m-d');
        foreach($state[$id]['events']??[] as $date=>$_)if($date>$quoteDate&&$date<=$today)return true;
        return false;
    }
}
