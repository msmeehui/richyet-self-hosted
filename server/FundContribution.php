<?php
declare(strict_types=1);
namespace RichYet;

require_once __DIR__.'/MarketJson.php';

/** RichYet's weekday reporting window is not a fund's trading/publication schedule.
 * The watermark survives source retries so an old daily change cannot re-arm. */
final class FundContribution
{
    public const SCHEDULE='bnd_daily_amsterdam';

    public static function identity(array $instrument):?string
    {
        $p=$instrument['providerConfig']??[];
        if(($instrument['provider']??null)!=='bnd'||($instrument['type']??null)!=='Fund'
            ||($instrument['currency']??null)!=='EUR'||!in_array($instrument['mic']??null,[null,'XXXX'],true)
            ||($p['identityConfirmed']??false)!==true||!is_int($p['fundId']??null)||$p['fundId']<1||$p['fundId']>999999
            ||!is_string($instrument['id']??null)||!is_string($instrument['isin']??null)
            ||!preg_match('/^[A-Z]{2}[A-Z0-9]{9}[0-9]$/D',$instrument['isin']))return null;
        return hash('sha256',json_encode([$instrument['id'],$p['fundId'],$instrument['isin'],'EUR','Fund'],JSON_THROW_ON_ERROR));
    }

    /** After 17:30, and throughout a weekend, use the next weekday. */
    public static function windowDate(int $firstObservedAt):string
    {
        $local=(new \DateTimeImmutable('@'.$firstObservedAt))->setTimezone(new \DateTimeZone('Europe/Amsterdam'));
        if($local->format('H:i:s')>='17:30:00')$local=$local->modify('+1 day');
        while((int)$local->format('N')>5)$local=$local->modify('+1 day');
        return $local->format('Y-m-d');
    }

    private static function acceptedDate(array $instrument,array $quote):?string
    {
        if(($quote['listingId']??null)!==$instrument['id']||($quote['isin']??null)!==$instrument['isin']
            ||($quote['currency']??null)!=='EUR'||($quote['priceKind']??null)!=='nav'
            ||MarketJson::decimal($quote['price']??null)===null||!MarketJson::date($quote['date']??null)
            ||array_intersect(is_array($quote['invalidations']??null)?$quote['invalidations']:[],['listing','current']))return null;
        return $quote['date'];
    }

    /** Validate the public receipt without mistaking first observation for publication. */
    public static function validReceipt(mixed $receipt,?string $date):bool
    {
        if(!is_array($receipt)||count($receipt)!==3||array_diff(array_keys($receipt),['valuationDate','firstObservedAt','windowDate'])
            ||!MarketJson::date($receipt['valuationDate']??null)||$receipt['valuationDate']!==$date
            ||!MarketJson::date($receipt['windowDate']??null))return false;
        if(!is_string($receipt['firstObservedAt']??null)||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D',$receipt['firstObservedAt']))return false;
        $observed=MarketJson::instant($receipt['firstObservedAt']);
        if($observed===null||$receipt['windowDate']!==self::windowDate($observed))return false;
        $observedDate=(new \DateTimeImmutable('@'.$observed))->setTimezone(new \DateTimeZone('Europe/Amsterdam'))->format('Y-m-d');
        return $receipt['valuationDate']<=$observedDate;
    }

    public static function validQuoteReceipt(array $instrument,array $quote):bool
    {
        $receipt=$quote['fundContribution']??null;
        return self::identity($instrument)!==null&&is_array($receipt)&&is_string($receipt['valuationDate']??null)
            &&self::validReceipt($receipt,$receipt['valuationDate']??null)
            &&self::compatibleReceiptQuote($instrument,$quote,$receipt['valuationDate']);
    }

    /** Timing eligibility remains known when an accepted NAV disappears from a
     * later response. Its amount is still unavailable and must remain Partial. */
    private static function compatibleReceiptQuote(array $instrument,array $quote,string $valuationDate):bool
    {
        foreach(['listingId'=>$instrument['id'],'isin'=>$instrument['isin'],'currency'=>'EUR','priceKind'=>'nav'] as $field=>$expected)
            if(array_key_exists($field,$quote)&&$quote[$field]!==$expected)return false;
        $invalidations=$quote['invalidations']??[];
        if(!is_array($invalidations)||array_diff($invalidations,['listing','current','comparison'])||in_array('listing',$invalidations,true)
            ||in_array('listing_unverified',is_array($quote['issues']??null)?$quote['issues']:[],true))return false;
        $date=$quote['date']??null;
        if($date===null)return ($quote['price']??null)===null;
        return $date===$valuationDate;
    }

    /** Public response only. Persisting this extra quote key would make an older
     * release reject the cache after a source-only rollback. */
    public static function receipt(array $instrument,array $quote,mixed $value):?array
    {
        $ledger=self::retainedLedger($instrument,$value);
        if($ledger===null)return null;
        $receipt=['valuationDate'=>$ledger['latestValuationDate'],'firstObservedAt'=>$ledger['firstObservedAt'],'windowDate'=>$ledger['windowDate']];
        return self::validReceipt($receipt,$ledger['latestValuationDate'])&&self::compatibleReceiptQuote($instrument,$quote,$ledger['latestValuationDate'])?$receipt:null;
    }

    private static function retainedLedger(array $instrument,mixed $value):?array
    {
        $identity=self::identity($instrument);
        if($identity===null||!is_array($value)||($value['version']??null)!==1||($value['identity']??null)!==$identity
            ||!MarketJson::date($value['latestValuationDate']??null))return null;
        $ledger=['version'=>1,'identity'=>$identity,'latestValuationDate'=>$value['latestValuationDate'],'firstObservedAt'=>null,'windowDate'=>null];
        $receipt=['valuationDate'=>$value['latestValuationDate'],'firstObservedAt'=>$value['firstObservedAt']??null,'windowDate'=>$value['windowDate']??null];
        if(self::validReceipt($receipt,$value['latestValuationDate'])){
            $ledger['firstObservedAt']=$receipt['firstObservedAt'];$ledger['windowDate']=$receipt['windowDate'];
        }
        return $ledger;
    }

    /** An older directory preparation cannot rewind a held fund's watermark.
     * For the same valuation retain its original proof, including a migration
     * watermark with no window: copying a later preparation must not replay it. */
    public static function mergeLedger(array $instrument,mixed $old,mixed $new):?array
    {
        $before=self::retainedLedger($instrument,$old);$after=self::retainedLedger($instrument,$new);
        if($before===null)return $after;
        if($after===null||$before['latestValuationDate']>=$after['latestValuationDate'])return $before;
        return $after;
    }

    /** Called after source/identity/regression guards choose the accepted snapshot.
     * A deployment seeds the existing valuation without claiming a new arrival. */
    public static function reconcile(array $instrument,array $oldQuote,array $quote,mixed $prior,int $now):array
    {
        $identity=self::identity($instrument);
        if($identity===null)return ['ledger'=>null,'receipt'=>null];
        $ledger=self::retainedLedger($instrument,$prior)??['version'=>1,'identity'=>$identity,'latestValuationDate'=>null,'firstObservedAt'=>null,'windowDate'=>null];
        $oldDate=self::acceptedDate($instrument,$oldQuote);
        if($oldDate!==null&&($ledger['latestValuationDate']===null||$oldDate>$ledger['latestValuationDate'])){
            $ledger['latestValuationDate']=$oldDate;$ledger['firstObservedAt']=$ledger['windowDate']=null;
        }
        $date=self::acceptedDate($instrument,$quote);
        $localDate=(new \DateTimeImmutable('@'.$now))->setTimezone(new \DateTimeZone('Europe/Amsterdam'))->format('Y-m-d');
        if($date!==null&&$date<=$localDate&&($ledger['latestValuationDate']===null||$date>$ledger['latestValuationDate'])){
            $ledger['latestValuationDate']=$date;$ledger['firstObservedAt']=gmdate('Y-m-d\TH:i:s\Z',$now);$ledger['windowDate']=self::windowDate($now);
        }
        return ['ledger'=>$ledger,'receipt'=>self::receipt($instrument,$quote,$ledger)];
    }
}
