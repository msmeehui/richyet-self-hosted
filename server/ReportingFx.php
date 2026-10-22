<?php
declare(strict_types=1);
namespace RichYet;

require_once __DIR__.'/MarketJson.php';

/** Free daily reference rates. This source never replaces the existing hourly
 * EUR valuation path. Each published decimal remains text. */
final class ReportingFx
{
    public const URL='https://www.ecb.europa.eu/stats/eurofxref/eurofxref-hist-90d.xml';
    public const SERIES='ecb-reference-daily';
    public const MAX_BYTES=524288;

    public static function businessDay(\DateTimeImmutable $day):bool
    {
        if((int)$day->format('N')>5||in_array($day->format('m-d'),['01-01','05-01','12-25','12-26'],true))return false;
        // Gregorian Easter, independent of server timezone and optional calendar extension.
        $y=(int)$day->format('Y');$a=$y%19;$b=intdiv($y,100);$c=$y%100;$d=intdiv($b,4);$e=$b%4;$f=intdiv($b+8,25);$g=intdiv($b-$f+1,3);$h=(19*$a+$b-$d-$g+15)%30;$i=intdiv($c,4);$k=$c%4;$l=(32+2*$e+2*$i-$h-$k)%7;$m=intdiv($a+11*$h+22*$l,451);$month=intdiv($h+$l-7*$m+114,31);$date=($h+$l-7*$m+114)%31+1;
        $easter=$day->setDate($y,$month,$date);
        return !in_array($day->format('Y-m-d'),[$easter->modify('-2 days')->format('Y-m-d'),$easter->modify('+1 day')->format('Y-m-d')],true);
    }

    public static function freshUntil(string $date):int
    {
        $day=new \DateTimeImmutable($date.' 18:00:00',new \DateTimeZone('Europe/Amsterdam'));
        do{$day=$day->modify('+1 day');}while(!self::businessDay($day));
        return $day->getTimestamp();
    }

    public static function parse(string $xml,int $now):array
    {
        if(strlen($xml)>self::MAX_BYTES||preg_match('/<!DOCTYPE|<!ENTITY|&/i',$xml))throw new \RuntimeException('Reference rate document unavailable.');
        // Accept the documented small ECB feed grammar, not arbitrary XML. This
        // avoids entity expansion and works on the minimal production/test PHP build.
        $xml=preg_replace_callback('~([A-Za-z:]+)=([\x27\x22])([^\x27\x22<>]*)\2~',static fn(array $m):string=>$m[1].'="'.$m[3].'"',$xml);
        if(!is_string($xml)||str_contains($xml,"'"))throw new \RuntimeException('Invalid reference rate attributes.');
        $q='"';
        $pattern='~\A\s*(?:<\?xml[^?]*\?>\s*)?<gesmes:Envelope\s+([^>]+)>\s*<gesmes:subject>Reference rates</gesmes:subject>\s*<gesmes:Sender>\s*<gesmes:name>(?:ECB|European Central Bank)</gesmes:name>\s*</gesmes:Sender>\s*<Cube>\s*(.*?)\s*</Cube>\s*</gesmes:Envelope>\s*\z~s';
        if(!preg_match($pattern,$xml,$document)||!preg_match('~xmlns="http://www\.ecb\.int/vocabulary/2002-08-01/eurofxref"~',$document[1])||!preg_match('~xmlns:gesmes="http://www\.gesmes\.org/xml/2002-08-01"~',$document[1])||trim(preg_replace('~xmlns(?::gesmes)?="[^"]+"~','',$document[1]))!=='')throw new \RuntimeException('Invalid reference rate document.');
        if(preg_match_all('~xmlns(?::gesmes)?="[^"]+"~',$document[1])!==2)throw new \RuntimeException('Duplicate reference rate namespace.');
        $body=$document[2];$dayPattern='~<Cube\s+time='.$q.'(\d{4}-\d{2}-\d{2})'.$q.'\s*>\s*(.*?)\s*</Cube>~s';
        if(!preg_match_all($dayPattern,$body,$days,PREG_SET_ORDER)||count($days)<2||count($days)>100||trim(preg_replace($dayPattern,'',$body))!=='')throw new \RuntimeException('Incomplete reference rate history.');
        $today=(new \DateTimeImmutable('@'.$now))->setTimezone(new \DateTimeZone('Europe/Amsterdam'))->format('Y-m-d');$rows=[];
        foreach($days as $day){
            $date=$day[1];if(!MarketJson::date($date)||$date>$today||isset($rows[$date]))throw new \RuntimeException('Invalid reference rate date.');
            $ratePattern='~<Cube\s+currency='.$q.'([A-Z]{3})'.$q.'\s+rate='.$q.'([0-9]+(?:\.[0-9]+)?)'.$q.'\s*/>~';
            if(!preg_match_all($ratePattern,$day[2],$nodes,PREG_SET_ORDER)||trim(preg_replace($ratePattern,'',$day[2]))!=='')throw new \RuntimeException('Invalid reference rate row.');
            $rates=[];$seen=[];foreach($nodes as $node){
                $currency=$node[1];if(isset($seen[$currency]))throw new \RuntimeException('Duplicate reference currency.');$seen[$currency]=true;
                if(!in_array($currency,['USD','GBP'],true))continue;$raw=$node[2];$rate=MarketJson::decimal($raw);
                if($rate===null||!preg_match('/^(?:[0-9]{1,3})(?:\.[0-9]{1,8})?$/D',$raw))throw new \RuntimeException('Invalid reference rate.');$rates[$currency]=$rate;
            }
            if(count($rates)!==2)throw new \RuntimeException('Missing reporting currency rate.');
            $rows[$date]=['date'=>$date,'rates'=>['USD'=>$rates['USD'],'GBP'=>$rates['GBP']]];
        }
        krsort($rows);$rows=array_values($rows);
        if(strtotime($rows[0]['date'].'T00:00:00Z')<$now-7*86400)throw new \RuntimeException('Reference rate history is out of date.');
        return ['series'=>self::SERIES,'base'=>'EUR','current'=>$rows[0],'history'=>$rows,'fetchedAt'=>gmdate('Y-m-d\TH:i:s\Z',$now),'freshUntil'=>gmdate('Y-m-d\TH:i:s\Z',self::freshUntil($rows[0]['date'])),'status'=>self::freshUntil($rows[0]['date'])>$now?'ready':'stale'];
    }

    /** Fixed URL, no credentials, redirects, cookies, arbitrary paths or proxy. */
    public static function download():string
    {
        if(!function_exists('curl_init'))throw new \RuntimeException('Reference rate transport unavailable.');
        $body='';$overflow=false;$curl=curl_init(self::URL);
        curl_setopt_array($curl,[CURLOPT_HTTPHEADER=>['Accept: application/xml,text/xml','User-Agent: RichYet-private/1'],CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROXY=>'',CURLOPT_NETRC=>CURL_NETRC_IGNORED,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_RETURNTRANSFER=>false,CURLOPT_WRITEFUNCTION=>static function($curl,string $chunk)use(&$body,&$overflow):int{if(strlen($body)+strlen($chunk)>self::MAX_BYTES){$overflow=true;return 0;}$body.=$chunk;return strlen($chunk);}]);
        $ok=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);unset($curl);
        if($ok===false||$overflow||$status!==200)throw new \RuntimeException('Reference rate request failed.');
        return $body;
    }
}
