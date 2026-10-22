<?php
declare(strict_types=1);
namespace RichYet;
require_once __DIR__.'/MarketJson.php';

/** A conflicting source value is stronger evidence than an unavailable request. */
final class USHistoricalFxConflict extends \RuntimeException {}

/** Delayed consolidated regular-session prices, exact settled hourly EURUSD. */
final class USProviders
{
    private static function iso(int $time):string{return gmdate('Y-m-d\TH:i:s\Z',$time);}
    private static function json(callable $get,string $url):array{$a=MarketJson::decode($get($url));if(!is_array($a))throw new \RuntimeException('Invalid source response.');return $a;}
    private static function url(string $base,array $params):string{return $base.'?'.http_build_query($params,'','&',PHP_QUERY_RFC3986);}
    /** Fixed diagnostic labels only, never provider URLs or exception text. */
    private static function requestStage(string $url):string
    {
        $p=parse_url($url);$path=$p['path']??'';parse_str($p['query']??'',$q);
        if(str_starts_with($path,'/v2/assets/'))return 'identity';
        if($path==='/v2/calendar')return 'calendar';
        if($path==='/v1/corporate-actions')return 'actions';
        if($path==='/v2/stocks/bars')return ($q['timeframe']??'')==='1Min'?'minutes':(($q['adjustment']??'')==='split'?'split':'daily');
        return str_ends_with($path,'/prev')?'current_fx':'baseline_fx';
    }
    private static function retryable(\Throwable $error):bool
    {
        if($error instanceof ProviderHttpStatus)return $error->retryAfterSeconds===null&&in_array($error->status,[408,500,502,503,504],true);
        return $error instanceof ProviderHttpTransport&&in_array($error->transportCode,[6,7,16,18,28,52,55,56,92],true);
    }
    private static function diagnostics(array $state,array $instruments,array $quotes,array $issues,array $attempt,int $now):array
    {
        foreach($instruments as $i){$id=$i['id'];$prior=$state['priceChecks'][$id]??[];$q=$quotes[$id]??[];
            $codes=array_column(array_filter($issues,static fn($r)=>$r['instrumentId']===$id),'code');
            $price=($q['price']??null)!==null&&($q['sourceAvailable']??false)===true;
            $complete=$price&&($q['previousClose']??null)!==null&&($q['fx']??null)!==null&&($q['previousFx']??null)!==null&&!array_diff($codes,['price_stale']);
            $state['priceChecks'][$id]=['attemptedAt'=>$now,'lastPriceSuccessAt'=>$price?$now:($prior['lastPriceSuccessAt']??null),'lastComparisonSuccessAt'=>$complete?$now:($prior['lastComparisonSuccessAt']??null),'outcome'=>$complete?'complete':($price?'partial':'failed'),'requests'=>$attempt['requests'],'retries'=>$attempt['retries'],'lastStage'=>$attempt['stage'],'failure'=>$attempt['failure']??($complete?null:['stage'=>$attempt['stage'],'kind'=>'validation','httpStatus'=>null,'transportCode'=>null])];
        }return $state;
    }
    public static function calendar(array $rows):array
    {
        $days=[];$zone=new \DateTimeZone('America/New_York');
        foreach($rows as $r){
            if(!is_array($r)||!MarketJson::date($r['date']??null)||!preg_match('/^\d{2}:\d{2}$/D',$r['open']??'')||!preg_match('/^\d{2}:\d{2}$/D',$r['close']??''))throw new \RuntimeException('Invalid market calendar.');
            $open=(new \DateTimeImmutable($r['date'].'T'.$r['open'],$zone))->getTimestamp();$close=(new \DateTimeImmutable($r['date'].'T'.$r['close'],$zone))->getTimestamp();
            if(substr($r['open'],0,2)>'23'||substr($r['open'],3,2)>'59'||substr($r['close'],0,2)>'23'||substr($r['close'],3,2)>'59'||$close<=$open||$close-$open>8*3600||isset($days[$r['date']]))throw new \RuntimeException('Invalid market session.');
            $days[$r['date']]=['date'=>$r['date'],'open'=>$open,'close'=>$close];
        }
        ksort($days);return $days;
    }
    /** Optional bounded session metadata from the already requested provider
     * calendar. This carries no price/account data and makes no request. */
    public static function publicCalendar(mixed $raw,int $now):?array
    {
        if(!is_array($raw)||($raw['source']??null)!=='alpaca_calendar'||($raw['timeZone']??null)!=='America/New_York'
            ||!MarketJson::date($raw['from']??null)||!MarketJson::date($raw['through']??null)||$raw['from']>$raw['through']
            ||strtotime($raw['through'])-strtotime($raw['from'])>40*86400||!is_string($raw['checkedAt']??null)
            ||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D',$raw['checkedAt'])
            ||($checked=MarketJson::instant($raw['checkedAt']))===null||$checked>$now||$now-$checked>7*86400
            ||!is_array($raw['sessions']??null)||!array_is_list($raw['sessions'])||count($raw['sessions'])<1||count($raw['sessions'])>40)return null;
        $zone=new \DateTimeZone('America/New_York');$checkedDay=(new \DateTimeImmutable('@'.$checked))->setTimezone($zone)->format('Y-m-d');
        if($checkedDay<$raw['from']||$checkedDay>$raw['through'])return null;
        $rows=[];$seen=[];
        foreach($raw['sessions'] as $row){
            if(!is_array($row)||!MarketJson::date($row['date']??null)||$row['date']<$raw['from']||$row['date']>$raw['through']||isset($seen[$row['date']])
                ||!is_string($row['openAt']??null)||!is_string($row['closeAt']??null)
                ||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D',$row['openAt'])||!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D',$row['closeAt'])
                ||($open=MarketJson::instant($row['openAt']))===null||($close=MarketJson::instant($row['closeAt']))===null||$close<=$open||$close-$open>8*3600)return null;
            $a=(new \DateTimeImmutable('@'.$open))->setTimezone($zone);$b=(new \DateTimeImmutable('@'.$close))->setTimezone($zone);
            if($a->format('Y-m-d')!==$row['date']||$b->format('Y-m-d')!==$row['date']||(int)$a->format('N')>5||$a->format('H:i:s')!=='09:30:00'||$b->format('s')!=='00')return null;
            $seen[$row['date']]=true;$rows[]=['date'=>$row['date'],'openAt'=>self::iso($open),'closeAt'=>self::iso($close)];
        }
        usort($rows,static fn($a,$b)=>strcmp($a['date'],$b['date']));
        return ['source'=>'alpaca_calendar','timeZone'=>'America/New_York','from'=>$raw['from'],'through'=>$raw['through'],'checkedAt'=>self::iso($checked),'sessions'=>$rows];
    }
    private static function bars(callable $get,array $symbols,string $timeframe,int $start,int $end,string $adjustment='raw'):array
    {
        $rows=[];$page=null;$seen=[];
        for($i=0;$i<5;$i++){
            $params=['symbols'=>implode(',',$symbols),'timeframe'=>$timeframe,'start'=>self::iso($start),'end'=>self::iso($end),'feed'=>'sip','currency'=>'USD','adjustment'=>$adjustment,'limit'=>'10000','sort'=>'asc'];
            if($page!==null)$params['page_token']=$page;
            $data=self::json($get,self::url('https://data.alpaca.markets/v2/stocks/bars',$params));
            if(!is_array($data['bars']??null))throw new \RuntimeException('Price bars unavailable.');
            foreach($data['bars'] as $symbol=>$bars){if(!in_array($symbol,$symbols,true)||!is_array($bars))throw new \RuntimeException('Unexpected listing.');foreach($bars as $bar)$rows[$symbol][]=$bar;}
            $page=$data['next_page_token']??null;
            if($page===null||$page==='')return $rows;
            if(!is_string($page)||strlen($page)>1024||isset($seen[$page]))throw new \RuntimeException('Invalid source pagination.');$seen[$page]=true;
        }
        throw new \RuntimeException('Price pagination budget reached.');
    }
    /** Pure session selector. Completed daily bars and intraday minutes never mix sessions. */
    public static function quote(array $daily,array $minutes,array $calendar,int $now,bool $basisVerified=false):array
    {
        $cutoff=$now-1200;$zone=new \DateTimeZone('America/New_York');$completed=[];$latestMinute=null;$minuteValues=[];
        foreach($daily as $bar){$t=MarketJson::instant($bar['t']??null);$price=MarketJson::decimal($bar['c']??null);if($t===null||$price===null)continue;
            $date=(new \DateTimeImmutable('@'.$t))->setTimezone($zone)->format('Y-m-d');$day=$calendar[$date]??null;
            if(!$day||$day['close']>$cutoff)continue;
            if(isset($completed[$date])&&$completed[$date]['price']!==$price)throw new \RuntimeException('Conflicting closing observations.');
            $completed[$date]=['price'=>$price,'date'=>$date,'time'=>$day['close']];
        }
        ksort($completed);
        foreach($minutes as $bar){$t=MarketJson::instant($bar['t']??null);$price=MarketJson::decimal($bar['c']??null);if($t===null||$price===null||$t+60>$cutoff)continue;
            $date=(new \DateTimeImmutable('@'.$t))->setTimezone($zone)->format('Y-m-d');$day=$calendar[$date]??null;
            if(!$day||$t<$day['open']||$t>=$day['close']||$day['close']<=$cutoff)continue;
            if(isset($minuteValues[$t])&&$minuteValues[$t]!==$price)throw new \RuntimeException('Conflicting minute observations.');$minuteValues[$t]=$price;
            if($latestMinute===null||$t>$latestMinute['time'])$latestMinute=['price'=>$price,'date'=>$date,'time'=>$t];
        }
        $current=$latestMinute??(count($completed)?end($completed):null);
        if($current===null)throw new \RuntimeException('No eligible regular-session price.');
        $previousSession=null;foreach($calendar as $date=>$day)if($date<$current['date'])$previousSession=$day;
        $baseline=$previousSession===null?null:($completed[$previousSession['date']]??null);
        $status=$latestMinute!==null?'delayed':'closed';
        foreach($calendar as $day)if($day['open']+1200<=$now&&$now<$day['close']+1200&&$current['time']<$now-5400)$status='stale';
        // A missed session is stale even when the exchange is currently closed.
        $expected=null;foreach($calendar as $day)if($day['close']<=$cutoff)$expected=$day['date'];
        if($expected!==null&&$current['date']<$expected)$status='stale';
        $freshUntil=$current['time']+5400;
        if($latestMinute===null)foreach($calendar as $day)if($day['date']>$current['date']){$freshUntil=$day['open']+5400;break;}
        if($status==='stale')$freshUntil=min($freshUntil,$now);
        return ['price'=>$current['price'],'previousClose'=>$basisVerified?($baseline['price']??null):null,'date'=>$current['date'],'previousDate'=>$previousSession['date']??null,'observedAt'=>self::iso($current['time']),'previousObservedAt'=>$previousSession?self::iso($previousSession['close']):null,'fx'=>null,'previousFx'=>null,'status'=>$status,'source'=>'Alpaca delayed consolidated SIP','feed'=>'sip','currency'=>'USD','observationKind'=>$latestMinute!==null?'minute':'close','baselineClose'=>$previousSession?self::iso($previousSession['close']):null,
            'priceKind'=>$latestMinute!==null?'trade':'close','previousKind'=>'close','expectedPreviousDate'=>$previousSession['date']??null,'freshUntil'=>$freshUntil];
    }
    private static function fxClosedAt(int $time):bool
    {
        $ny=(new \DateTimeImmutable('@'.$time))->setTimezone(new \DateTimeZone('America/New_York'));$day=(int)$ny->format('N');$hour=(int)$ny->format('G');
        return $day===6||($day===5&&$hour>=17)||($day===7&&$hour<17);
    }
    public static function fxWindowAllowed(int $start,int $end):bool
    {
        return $end===$start+3600&&!self::fxClosedAt($start)&&!self::fxClosedAt($end-1);
    }
    private static function fridayClose(int $now):?int
    {
        if(!self::fxClosedAt($now))return null;
        return (new \DateTimeImmutable('@'.$now))->setTimezone(new \DateTimeZone('America/New_York'))->modify('friday this week')->setTime(17,0)->getTimestamp();
    }
    public static function fxBar(array $data):array
    {
        if(($data['symbol']??null)!=='EURUSD')throw new \RuntimeException('FX pair mismatch.');
        $r=$data['result']??null;$c=MarketJson::decimal($r['c']??null);$start=MarketJson::instant($data['bar_start']??null);$end=MarketJson::instant($data['bar_end']??null);$t=MarketJson::instant($r['t']??null);
        if($c===null||$start===null||$end!==$start+3600||$t!==$start||!self::fxWindowAllowed($start,$end))throw new \RuntimeException('Invalid settled hourly FX.');
        return ['rate'=>['numerator'=>'1','denominator'=>$c],'start'=>$start,'end'=>$end,'source'=>'TickerLayer EURUSD hourly close'];
    }
    /** Closure is expected Fri17:00 through Sun17:00 New York; holidays not guessed. */
    public static function fxStale(array $bar,int $now):bool
    {
        if($bar['end']>$now||!self::fxWindowAllowed($bar['start'],$bar['end']))return true;
        if($now-$bar['start']<=10800)return false;
        $ny=(new \DateTimeImmutable('@'.$now))->setTimezone(new \DateTimeZone('America/New_York'));
        $weekday=(int)$ny->format('N');$hour=(int)$ny->format('G');
        $closed=$weekday===6||($weekday===5&&$hour>=17)||($weekday===7&&$hour<17);
        if(!$closed)return true;
        $friday=$ny->modify('friday this week')->setTime(17,0)->getTimestamp();
        return $bar['start']<$friday-3*3600||$bar['end']>$friday;
    }
    /** Deadline follows the same hourly-window and weekend rule as fxStale. */
    public static function fxFreshUntil(array $bar,int $now):int
    {
        if(self::fxStale($bar,$now))return $now;
        if(self::fxClosedAt($now))return (new \DateTimeImmutable('@'.$now))->setTimezone(new \DateTimeZone('America/New_York'))->modify('sunday this week')->setTime(17,0)->getTimestamp();
        return $bar['start']+10800;
    }
    private static function historicalFx(callable $get,int $close):array
    {
        $date=gmdate('Y-m-d',$close);$offset=0;$seen=[];$best=null;$observations=[];
        for($page=0;$page<3;$page++){
            $data=self::json($get,self::url('https://api.tickerlayer.com/forex/agg/EURUSD/1/hour/'.$date.'/'.$date,['sort'=>'asc','limit'=>'5000','offset'=>(string)$offset]));
            if(($data['symbol']??null)!=='EURUSD')throw new \RuntimeException('Historical FX pair mismatch.');
            if(!is_array($data['results']??null))throw new \RuntimeException('Historical FX unavailable.');
            foreach($data['results'] as $row){$start=MarketJson::instant($row['t']??null);$c=MarketJson::decimal($row['c']??null);if($start===null||$c===null)continue;if(isset($observations[$start])&&$observations[$start]!==$c)throw new USHistoricalFxConflict('Conflicting historical FX observations.');$observations[$start]=$c;$end=$start+3600;if(!self::fxWindowAllowed($start,$end))continue;if($end<=$close&&$close-$start<=10800&&($best===null||$start>$best['start']))$best=['rate'=>['numerator'=>'1','denominator'=>$c],'start'=>$start,'end'=>$end,'source'=>'TickerLayer EURUSD hourly close'];}
            $next=$data['next_offset']??null;
            if($next===null){if($best===null)throw new \RuntimeException('No suitable baseline FX.');return $best;}
            if(!is_string($next)||!preg_match('/^[0-9]{1,8}$/D',$next)||(int)$next<=$offset||isset($seen[$next]))throw new \RuntimeException('Invalid FX pagination.');$seen[$next]=true;$offset=(int)$next;
        }
        throw new \RuntimeException('FX pagination budget reached.');
    }
    /** A price-only cash dividend needs no adjustment; all other actions need known effective dates. */
    public static function corporateCompatible(array $actions,string $baseline,string $current,?string $symbol=null):bool
    {
        $known=['forward_splits','reverse_splits','unit_splits','stock_dividends','spin_offs','stock_mergers','cash_mergers','stock_and_cash_mergers','redemptions','name_changes','worthless_removals','rights_distributions','stock_dividend','splits'];
        foreach($actions as $category=>$rows){
            if(!is_array($rows))return false;
            if($category==='cash_dividends')continue;
            if(!in_array($category,$known,true))return false;
            foreach($rows as $row){
                if(!is_array($row))return false;
                if($symbol!==null){$identities=[];foreach(['symbol','old_symbol','new_symbol','initiating_symbol','target_symbol'] as $field)if(is_string($row[$field]??null)&&$row[$field]!=='')$identities[]=$row[$field];if(!$identities)return false;if(!in_array($symbol,$identities,true))continue;}
                $date=$row['ex_date']??$row['effective_date']??null;
                // process_date is processing time, never proof of effective date.
                if(!MarketJson::date($date))return false;
                if($date>$baseline&&$date<=$current)return false;
            }
        }
        return true;
    }
    /** Known effective actions make a retained pre-action price incompatible with current units. */
    public static function oldBasisUnsafe(array $actions,?string $quoteDate,string $symbol,int $now):bool
    {
        if($quoteDate===null)return false;
        $today=(new \DateTimeImmutable('@'.$now))->setTimezone(new \DateTimeZone('America/New_York'))->format('Y-m-d');
        foreach($actions as $category=>$rows){
            if($category==='cash_dividends'||!is_array($rows))continue;
            foreach($rows as $row){
                if(!is_array($row))continue;$identities=[];
                foreach(['symbol','old_symbol','new_symbol','initiating_symbol','target_symbol'] as $field)if(is_string($row[$field]??null)&&$row[$field]!=='')$identities[]=$row[$field];
                if($identities&&!in_array($symbol,$identities,true))continue;
                $date=$row['ex_date']??$row['effective_date']??null;
                if(MarketJson::date($date)&&$date>$quoteDate&&$date<=$today)return true;
            }
        }
        return false;
    }
    public static function currentSplitMismatch(array $raw,array $split,array $quote):bool
    {
        if(($quote['observationKind']??null)!=='close')return false;
        $date=$quote['date'];$prices=[];$zone=new \DateTimeZone('America/New_York');
        foreach(['raw'=>$raw,'split'=>$split] as $kind=>$rows)foreach($rows as $row){$t=MarketJson::instant($row['t']??null);$c=MarketJson::decimal($row['c']??null);if($t!==null&&$c!==null&&(new \DateTimeImmutable('@'.$t))->setTimezone($zone)->format('Y-m-d')===$date)$prices[$kind]=$c;}
        return isset($prices['raw'],$prices['split'])&&$prices['raw']!==$prices['split'];
    }
    public static function splitParity(array $raw,array $split,array $quote):bool
    {
        $values=[];$zone=new \DateTimeZone('America/New_York');
        foreach(['raw'=>$raw,'split'=>$split] as $kind=>$bars)foreach($bars as $bar){$t=MarketJson::instant($bar['t']??null);$c=MarketJson::decimal($bar['c']??null);if($t===null||$c===null)return false;$date=(new \DateTimeImmutable('@'.$t))->setTimezone($zone)->format('Y-m-d');if(isset($values[$date][$kind])&&$values[$date][$kind]!==$c)return false;$values[$date][$kind]=$c;}
        $baseline=$quote['previousDate'];if($baseline===null||!isset($values[$baseline]['raw'],$values[$baseline]['split'])||$values[$baseline]['raw']!==$values[$baseline]['split'])return false;
        $date=$quote['date'];
        if(($quote['observationKind']??null)==='close'&&!isset($values[$date]['raw'],$values[$date]['split']))return false;
        if(($quote['observationKind']??null)==='close'&&isset($values[$date]['raw'])&&(!isset($values[$date]['split'])||$values[$date]['raw']!==$values[$date]['split']))return false;
        return true;
    }
    private static function corporateActions(callable $get,array $symbols,int $now):array
    {
        $actions=[];$page=null;$seen=[];
        for($n=0;$n<3;$n++){
            $params=['symbols'=>implode(',',$symbols),'start'=>gmdate('Y-m-d',$now-90*86400),'end'=>gmdate('Y-m-d',$now+31*86400),'limit'=>'1000','data_quality'=>'all'];if($page!==null)$params['page_token']=$page;
            $data=self::json($get,self::url('https://data.alpaca.markets/v1/corporate-actions',$params));
            if(!is_array($data['corporate_actions']??null))throw new \RuntimeException('Corporate actions unavailable.');
            foreach($data['corporate_actions'] as $category=>$rows){if(!is_array($rows))throw new \RuntimeException('Invalid corporate action rows.');$actions[$category]=array_merge($actions[$category]??[],$rows);}
            $page=$data['next_page_token']??null;if($page===null||$page==='')return $actions;
            if(!is_string($page)||strlen($page)>1024||isset($seen[$page]))throw new \RuntimeException('Invalid corporate action pagination.');$seen[$page]=true;
        }
        throw new \RuntimeException('Corporate action pagination budget reached.');
    }
    /** A successful pre-opening batch must not delay the first eligible
     * completed regular-session minute by another hour. The calendar owns
     * holidays, DST and shortened sessions; the collector still owns cadence.
     * Successful ordinary attempts target the next UTC hour, so a few seconds
     * of collector jitter cannot make the next hour's slot miss its deadline.
     * Failure paths retain their actual-attempt-plus-one-hour backoff. */
    private static function nextPriceAttempt(array $calendar,int $now):int
    {
        $next=(intdiv($now,3600)+1)*3600;
        foreach($calendar as $day){
            $firstMinuteAvailable=$day['open']+1200+60;
            if($day['close']>$day['open']+60&&$firstMinuteAvailable>$now&&$firstMinuteAvailable<$next)$next=$firstMinuteAvailable;
        }
        return $next;
    }
    public static function refresh(array $registry,array $state,callable $get,int $now):array
    {
        $instruments=array_values(array_filter($registry,static fn($i)=>$i['provider']==='alpaca'));
        $members=[];foreach($instruments as $i)$members[$i['id']]=hash('sha256',json_encode([$i['mic'],$i['currency'],$i['providerConfig']],JSON_THROW_ON_ERROR));ksort($members);
        if(!$instruments||(($state['nextAttempt']??0)>$now&&($state['members']??null)===$members))return ['quotes'=>[],'state'=>$state,'issues'=>[]];
        // One transient retry across the entire batch, charged by the same
        // durable transport limits. Auth/quota errors, Retry-After, malformed
        // bodies and invalid corporate-action evidence never receive this retry.
        $attempt=['requests'=>0,'retries'=>0,'stage'=>'identity','failure'=>null];$sourceGet=$get;
        $get=static function(string $url)use($sourceGet,&$attempt):string{
            $attempt['stage']=self::requestStage($url);
            for($n=0;$n<2;$n++){
                $attempt['requests']++;
                try{return $sourceGet($url);}catch(\Throwable $error){
                    $attempt['failure']=['stage'=>$attempt['stage'],'kind'=>$error instanceof ProviderHttpStatus?'http':($error instanceof ProviderHttpTransport?'transport':'unavailable'),'httpStatus'=>$error instanceof ProviderHttpStatus?$error->status:null,'transportCode'=>$error instanceof ProviderHttpTransport?$error->transportCode:null];
                    if($attempt['retries']===0&&self::retryable($error)){$attempt['retries']++;continue;}throw $error;
                }
            }throw new \RuntimeException('US request failed.');
        };
        $state['members']=$members;
        $state['nextAttempt']=$now+3600;$quotes=[];$issues=[];$valid=[];
        foreach($instruments as $i){
            $identityFailure='source_unavailable';
            try{
                if(($i['providerConfig']['identityConfirmed']??false)!==true){$identityFailure='listing_unverified';throw new \RuntimeException('Unconfirmed listing.');}
                $symbol=$i['providerConfig']['symbol'];$identity=$state['identities'][$i['id']]??null;
                if(!$identity||!isset($identity['assetId'])||($identity['checkedAt']??0)<$now-86400){
                    $a=self::json($get,'https://paper-api.alpaca.markets/v2/assets/'.$symbol);
                    $exchange=['XNAS'=>'NASDAQ','XNYS'=>'NYSE','ARCX'=>'ARCA','BATS'=>'BATS','XASE'=>'AMEX'][$i['mic']]??null;
                    if($exchange===null){$identityFailure='listing_unverified';throw new \RuntimeException('Unsupported listing venue.');}
                    // Empty fields are an incomplete check, not proof that the
                    // accepted listing changed. Still inspect every present
                    // field so a real contradiction wins over another omission.
                    $complete=true;
                    foreach(['symbol'=>$symbol,'class'=>'us_equity','status'=>'active','exchange'=>$exchange] as $field=>$expected){
                        $value=$a[$field]??null;
                        if($value===null||$value===''){$complete=false;continue;}
                        if($value!==$expected){$identityFailure='listing_unverified';throw new \RuntimeException('Listing mismatch.');}
                    }
                    $assetId=$a['id']??null;
                    if($assetId===null||$assetId==='')$complete=false;
                    else{
                        if(!is_string($assetId)||!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD',$assetId)){$identityFailure='listing_unverified';throw new \RuntimeException('Invalid stable listing identity.');}
                        $assetId=strtolower($assetId);
                        if(isset($identity['assetId'])&&$identity['assetId']!==$assetId){$identityFailure='listing_unverified';throw new \RuntimeException('Listing identity changed.');}
                    }
                    if(!$complete)throw new \RuntimeException('Incomplete listing response.');
                    $state['identities'][$i['id']]=['checkedAt'=>$now,'symbol'=>$symbol,'mic'=>$i['mic'],'assetId'=>$assetId];
                }elseif(($identity['symbol']??null)!==$symbol||($identity['mic']??null)!==$i['mic']){$identityFailure='listing_unverified';throw new \RuntimeException('Cached listing mismatch.');}
                $valid[]=$i;
            }catch(\Throwable){if($identityFailure==='listing_unverified')$quotes[$i['id']]=MarketStore::missing();$issues[]=['instrumentId'=>$i['id'],'code'=>$identityFailure];}
        }
        if(!$valid)return ['quotes'=>$quotes,'state'=>self::diagnostics($state,$instruments,$quotes,$issues,$attempt,$now),'issues'=>$issues];
        try{
            $calendarFrom=gmdate('Y-m-d',$now-30*86400);$calendarThrough=gmdate('Y-m-d',$now+7*86400);
            $calendar=self::calendar(self::json($get,self::url('https://paper-api.alpaca.markets/v2/calendar',['start'=>$calendarFrom,'end'=>$calendarThrough])));
            if(!$calendar)throw new \RuntimeException('Calendar unavailable.');
            $sessionCalendar=self::publicCalendar(['source'=>'alpaca_calendar','timeZone'=>'America/New_York','from'=>$calendarFrom,'through'=>$calendarThrough,'checkedAt'=>self::iso($now),
                'sessions'=>array_values(array_map(static fn($day)=>['date'=>$day['date'],'openAt'=>self::iso($day['open']),'closeAt'=>self::iso($day['close'])],$calendar))],$now);
            // Do not refresh old evidence when this attempt supplies no valid calendar.
            if($sessionCalendar!==null)$state['sessionCalendar']=$sessionCalendar;
            $symbols=array_column(array_column($valid,'providerConfig'),'symbol');
            $daily=self::bars($get,$symbols,'1Day',$now-21*86400,$now-1200);
            $split=[];$actions=null;try{$split=self::bars($get,$symbols,'1Day',$now-21*86400,$now-1200,'split');$actions=self::corporateActions($get,$symbols,$now);
                foreach($state['corporateActions']??[] as $category=>$rows)if($category!=='cash_dividends'){
                    $known=[];foreach(array_merge($rows,$actions[$category]??[]) as $row)$known[json_encode($row,JSON_THROW_ON_ERROR)]=$row;$actions[$category]=array_values($known);
                }
                $state['corporateActions']=$actions;$state['corporateActionsCheckedAt']=$now;}catch(\Throwable){}
            $minutes=[];$active=null;foreach($calendar as $day)if($day['open']<=$now-1200&&$day['close']>$now-1200)$active=$day;
            if($active)$minutes=self::bars($get,$symbols,'1Min',$active['open'],min($active['close'],$now-1200));
            foreach($valid as $i){
                try{$symbol=$i['providerConfig']['symbol'];$q=self::quote($daily[$symbol]??[],$minutes[$symbol]??[],$calendar,$now,true);
                    if(self::currentSplitMismatch($daily[$symbol]??[],$split[$symbol]??[],$q)){$state['basisInvalidThrough'][$i['id']]=$q['date'];$q['price']=null;$q['previousClose']=null;$q['status']='missing';$issues[]=['instrumentId'=>$i['id'],'code'=>'corporate_action_unverified'];}
                    $compatible=$actions!==null&&self::splitParity($daily[$symbol]??[],$split[$symbol]??[],$q)&&self::corporateCompatible($actions,$q['previousDate']??$q['date'],$q['date'],$symbol);
                    $state['comparisonChecks'][$i['id']]=$compatible?'compatible':($actions===null?'unavailable':'incompatible');
                    if(!$compatible&&$q['previousClose']!==null){$q['previousClose']=null;$issues[]=['instrumentId'=>$i['id'],'code'=>'corporate_action_unverified'];}
                    $q['listingId']=$i['id'];$q['mic']=$i['mic'];$q['basis']=$compatible?'same_units':'unverified';$q['invalidations']=$q['price']===null?['current']:($compatible?[]:['comparison']);$q['sourceAvailable']=true;
                    $quotes[$i['id']]=$q;if($q['status']==='stale')$issues[]=['instrumentId'=>$i['id'],'code'=>'price_stale'];}
                catch(\Throwable){$issues[]=['instrumentId'=>$i['id'],'code'=>'source_unavailable'];}
            }
        }catch(\Throwable){foreach($valid as $i)$issues[]=['instrumentId'=>$i['id'],'code'=>'source_unavailable'];}
        $cachedFx=$state['currentFx']??null;
        if($cachedFx!==null&&!self::fxWindowAllowed($cachedFx['start'],$cachedFx['end']))$cachedFx=null;
        try{$fx=self::fxBar(self::json($get,'https://api.tickerlayer.com/forex/agg/EURUSD/prev?interval=1h'));if($fx['end']>$now||$fx['end']<($cachedFx['end']??0))throw new \RuntimeException('Invalid FX window progression.');$state['currentFx']=$fx;}
        catch(\Throwable){
            $fx=$cachedFx;$resolved=false;$friday=self::fridayClose($now);
            if($friday!==null){try{
                $fallback=$state['weekendFx']??null;
                if($fallback===null||($fallback['sessionClose']??null)!==$friday||($fallback['checkedAt']??0)<$now-86400){$fallback=self::historicalFx($get,$friday);$fallback['sessionClose']=$friday;$fallback['checkedAt']=$now;$state['weekendFx']=$fallback;}
                if(self::fxWindowAllowed($fallback['start'],$fallback['end'])&&$fallback['end']>=(($cachedFx['end']??0))){$fx=$fallback;$state['currentFx']=$fx;$resolved=true;}
            }catch(\Throwable){}}
            if($fx===null)unset($state['currentFx']);
            if(!$resolved)foreach($valid as $i)$issues[]=['instrumentId'=>$i['id'],'code'=>'source_unavailable'];
        }
        foreach($valid as $i){$id=$i['id'];if(!isset($quotes[$id])||$quotes[$id]['price']===null)continue;
            $quotes[$id]['fx']=$fx['rate']??null;$quotes[$id]['fxObservedAt']=$fx?self::iso($fx['start']):null;$quotes[$id]['fxBarEnd']=$fx?self::iso($fx['end']):null;
            $quotes[$id]['fxFreshUntil']=$fx?self::fxFreshUntil($fx,$now):$now;$quotes[$id]['fxSeries']='tickerlayer-eurusd-hourly';$quotes[$id]['previousFxSeries']='tickerlayer-eurusd-hourly';$quotes[$id]['fxBaselineDate']=$quotes[$id]['previousDate'];$quotes[$id]['fxBaselineVerified']=false;
            if($fx===null)$issues[]=['instrumentId'=>$id,'code'=>'fx_missing'];elseif(self::fxStale($fx,$now))$issues[]=['instrumentId'=>$id,'code'=>'fx_stale'];
            $close=MarketJson::instant($quotes[$id]['baselineClose']);
            // Only request historical FX once a usable, split-compatible price baseline exists.
            if($close!==null&&$quotes[$id]['previousClose']!==null){try{$key=(string)$close;$bar=$state['baselineFx'][$key]??null;if($bar===null||($bar['checkedAt']??0)<$now-86400||($state['baselineFxInvalid'][$key]??false)===true){$bar=self::historicalFx($get,$close);$bar['checkedAt']=$now;}$state['baselineFx'][$key]=$bar;$state['baselineFxInvalid'][$key]=false;$quotes[$id]['previousFx']=$bar['rate'];$quotes[$id]['previousFxObservedAt']=self::iso($bar['start']);$quotes[$id]['fxBaselineVerified']=self::fxWindowAllowed($bar['start'],$bar['end'])&&$bar['end']<=$close&&$close-$bar['start']<=10800;}catch(\Throwable $error){if($error instanceof USHistoricalFxConflict){$state['baselineFxInvalid'][(string)$close]=true;unset($state['baselineFx'][(string)$close]);}$issues[]=['instrumentId'=>$id,'code'=>'baseline_fx_missing'];}}
        }
        if(isset($state['baselineFx'])){$state['baselineFx']=array_filter($state['baselineFx'],static fn($v)=>($v['end']??0)>$now-45*86400);}
        if(isset($state['baselineFxInvalid']))$state['baselineFxInvalid']=array_filter($state['baselineFxInvalid'],static fn($key)=>(int)$key>$now-45*86400,ARRAY_FILTER_USE_KEY);
        // Do not turn provider failures or incomplete evidence into rapid
        // retries. A retained close may be marked stale during the single
        // minute between the delay cutoff and the first completed minute.
        $refreshProblems=array_filter($issues,static fn($issue)=>$issue['code']!=='price_stale');
        if(isset($calendar)&&!$refreshProblems&&count($quotes)===count($instruments))$state['nextAttempt']=self::nextPriceAttempt($calendar,$now);
        return ['quotes'=>$quotes,'state'=>self::diagnostics($state,$instruments,$quotes,$issues,$attempt,$now),'issues'=>$issues];
    }
}
