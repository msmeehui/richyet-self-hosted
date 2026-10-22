<?php
declare(strict_types=1);
namespace RichYet;
require_once __DIR__ . '/MarketCalendars.php';

/** Dated on-book main-session observations, never an official closing reference. */
final class EuronextProviders
{
    private const BASE = 'https://marketdata.euronext.com/data-reporting-service/trades-file/download/';
    private const ZIP_LIMIT = 16777216;
    private const CSV_LIMIT = 134217728;
    private const ROW_LIMIT = 500000;
    private const TARGET_LIMIT = 200000;
    private const SCHEDULES = [
        'ams_j0_continuous_2026' => ['type'=>'Stock','mic'=>'XAMS','route'=>'EQUITIES/SINCE_PREVIOUS_TRADING_DAY/AMS','file'=>'Trades_Equities.csv'],
        'bru_a0_continuous_2026' => ['type'=>'Stock','mic'=>'XBRU','route'=>'EQUITIES/SINCE_PREVIOUS_TRADING_DAY/BRU','file'=>'Trades_Equities.csv'],
        'par_eur_etf_continuous_2026' => ['type'=>'ETF','mic'=>'XPAR','route'=>'ETFS_FUNDS/SINCE_PREVIOUS_TRADING_DAY/PAR','file'=>'Trades_ETFSFunds.csv'],
        'ams_eur_etf_continuous_2026' => ['type'=>'ETF','mic'=>'XAMS','route'=>'ETFS_FUNDS/SINCE_PREVIOUS_TRADING_DAY/AMS','file'=>'Trades_ETFSFunds.csv'],
        'par_stock_continuous_2026' => ['type'=>'Stock','mic'=>'XPAR','route'=>'EQUITIES/SINCE_PREVIOUS_TRADING_DAY/PAR','file'=>'Trades_Equities.csv'],
    ];
    private const ZONES = ['XAMS'=>'Europe/Amsterdam','XPAR'=>'Europe/Paris','XBRU'=>'Europe/Brussels'];
    private const FIELDS = ['TradingDateTime','PublicationDateTime','MifidInstrumentID','MifidPrice','MifidQuantity','MifidPriceNotation','MifidCurrency','MmtMarketMechanism','MmtNegotiationIndicator','MmtModificationIndicator','MmtBenchMarkIndicator','MmtContributionToPrice','MmtAlgorithmicIndicator','MmtPublicationMode','Venue','ThirdCountryTradingVenueExecution','TradeUniqueIdentifier','MissingPrice','MmtContingentTransactionIndicator','VenueOfPublication'];

    /** GET(url,maxBytes) is supplied by the fixed-host transport; browser reads never call it. */
    public static function refresh(array $instruments,array $state,callable $get,int $now):array
    {
        $quotes=$issues=$groups=[];$retryAvailable=true;
        foreach($instruments as $i){
            if(($i['provider']??null)!=='euronext')continue;
            $id=$i['id']??'';$schedule=$i['providerConfig']['tradingSchedule']??'';
            if(!is_string($id)||$id==='')continue;
            if(!self::identity($i)){$quotes[$id]=self::missing('listing_unverified');$issues[]=['instrumentId'=>$id,'code'=>'listing_unverified'];continue;}
            if(MarketCalendars::day($i['mic'],self::local($now,self::ZONES[$i['mic']])->format('Y-m-d'))===null){$quotes[$id]=self::missing('source_unsupported');$issues[]=['instrumentId'=>$id,'code'=>'source_unsupported'];continue;}
            $groups[$schedule][]=$i;
        }
        foreach($groups as $schedule=>$selected){
            $zone=self::ZONES[self::SCHEDULES[$schedule]['mic']];
            $legacyFingerprint=hash('sha256',json_encode(array_map(static fn($i)=>[$i['id'],$i['isin'],$i['currency'],$i['mic'],$i['type'],$i['providerConfig']],$selected),JSON_THROW_ON_ERROR));
            $members=[];foreach($selected as $i)$members[$i['id']]=hash('sha256',json_encode([$i['isin'],$i['currency'],$i['mic'],$i['type'],$i['providerConfig']],JSON_THROW_ON_ERROR));ksort($members);
            $prior=$state[$schedule]??[];$routeState=$prior;$changed=[];
            if(isset($prior['members'])){
                foreach($members as $id=>$identity)if(($prior['members'][$id]??null)!==$identity)$changed[$id]=true;
            }elseif(($prior['fingerprint']??null)!==$legacyFingerprint){
                // A legacy route has no per-listing ledger. Only its exact old
                // registry fingerprint can establish that cached identities match.
                $routeState=[];$changed=array_fill_keys(array_keys($members),true);
            }
            foreach(['lastObservation','invalidDates','invalidThrough','lastQuotes'] as $field){
                foreach(array_keys($routeState[$field]??[]) as $id)if(isset($changed[$id])||!isset($members[$id]))unset($routeState[$field][$id]);
            }
            $routeState['members']=$members;$routeState['fingerprint']=hash('sha256',json_encode($members,JSON_THROW_ON_ERROR));
            $lastAttempt=$routeState['attemptAt']??null;
            // Upgrade without resetting an existing rolling gate. A future
            // attempt time also remains blocked after a backwards clock jump.
            $nextAttempt=is_int($routeState['nextAttempt']??null)?$routeState['nextAttempt']:(is_int($lastAttempt)?$lastAttempt+3600:null);
            if(!$changed&&is_int($nextAttempt)&&$now<$nextAttempt
                &&(isset($routeState['lastQuotes'])||($routeState['successAt']??null)!==$lastAttempt)){
                // A skipped upstream request is not a missing direct quote. In
                // particular, older history must not replace a newer trade or
                // erase a correction veto during this normal hourly interval.
                foreach(self::cachedQuotes($routeState,$now,$zone) as $id=>$quote){$quotes[$id]=$quote;foreach($quote['issues'] as $code)$issues[]=['instrumentId'=>$id,'code'=>$code];}
                $state[$schedule]=$routeState;continue;
            }
            $routeState['attemptAt']=$now;
            $routeState['nextAttempt']=$now+3600;
            $started=microtime(true);$stage='request';$diagnostic=['attemptedAt'=>$now,'outcome'=>'failed','attempts'=>0,'retries'=>0,'responseBytes'=>null,'failure'=>null];
            try{
                $spec=self::SCHEDULES[$schedule];
                $local=self::local($now,$zone);
                $window=self::normalDate($local->format('Y-m-d'),$zone)&&$local->format('H:i:s')>='09:20:00'?'CURRENT_TRADING_DAY':'SINCE_PREVIOUS_TRADING_DAY';
                $route=str_replace('SINCE_PREVIOUS_TRADING_DAY',$window,$spec['route']);
                $zip=self::download(self::BASE.$route,$get,$retryAvailable,$diagnostic);
                $diagnostic['responseBytes']=strlen($zip);$stage='archive';
                $stream=self::csvStream($zip,$spec['file'],$started);unset($zip);
                $stage='csv';
                try{$parsed=self::parseCsv($stream,$selected,$routeState,$now,$started,$zone,$window);}finally{fclose($stream);}
                $routeState=$parsed['state'];$routeState['lastAttemptFailed']=$parsed['sourceRegressed']??false;
                $diagnostic['outcome']=$routeState['lastAttemptFailed']?'regressed':'complete';
                if($routeState['lastAttemptFailed'])$diagnostic['failure']=['stage'=>'csv','reason'=>'source_regressed','httpStatus'=>null,'transportCode'=>null,'retryAfterSeconds'=>null];
                if(!$routeState['lastAttemptFailed']){
                    $routeState['successAt']=$now;
                    $routeState['nextAttempt']=(intdiv($now,3600)+1)*3600;
                    // The reviewed stream admits ordinary trades from 09:05
                    // with a fifteen-minute delay. A successful early check
                    // must not hold yesterday's trade until the next hour.
                    // Only shorten a successful gate; failed attempts above
                    // keep their full-hour backoff and existing gates are not
                    // reset when a collector starts or a clock moves back.
                    if(self::normalDate($local->format('Y-m-d'),$zone)){
                        $opening=$local->setTime(9,21)->getTimestamp();
                        if($now<$opening&&$opening<$routeState['nextAttempt'])$routeState['nextAttempt']=$opening;
                    }
                }
                $routeState['lastQuotes']=$parsed['quotes'];
                foreach($parsed['quotes'] as $id=>$quote){$quotes[$id]=$quote;foreach($quote['issues'] as $code)$issues[]=['instrumentId'=>$id,'code'=>$code];}
            }catch(\Throwable $error){
                $diagnostic['failure']=self::failure($error,$stage);
                $routeState['lastAttemptFailed']=true;
                foreach(self::cachedQuotes($routeState,$now,$zone) as $id=>$quote)$quotes[$id]=$quote;
                foreach($selected as $i){$issues[]=['instrumentId'=>$i['id'],'code'=>'source_unavailable'];if(isset($changed[$i['id']])&&isset($prior['fingerprint']))$quotes[$i['id']]=self::missing('source_unavailable');}
            }
            $diagnostic['elapsedMilliseconds']=min(180000,max(0,(int)round((microtime(true)-$started)*1000)));
            $routeState['downloadCheck']=$diagnostic;
            $state[$schedule]=$routeState;
        }
        return ['quotes'=>$quotes,'state'=>$state,'issues'=>$issues];
    }

    /** One extra read for a recognized transient failure across this whole
     * batch. Every read still goes through the ordinary transport/usage caps.
     * Authentication, throttle, Retry-After, TLS, parser and budget failures
     * retain the existing backoff without an extra request.
     */
    private static function download(string $url,callable $get,bool &$retryAvailable,array &$diagnostic):string
    {
        while(true){
            $diagnostic['attempts']++;
            try{return $get($url,self::ZIP_LIMIT);}
            catch(\Throwable $error){
                $diagnostic['failure']=self::failure($error,'request');
                $transient=($error instanceof ProviderHttpTransport&&in_array($error->transportCode,[6,7,18,28,52,55,56],true))
                    ||($error instanceof ProviderHttpStatus&&$error->retryAfterSeconds===null&&in_array($error->status,[408,500,502,503,504],true));
                if(!$retryAvailable||!$transient)throw $error;
                $retryAvailable=false;$diagnostic['retries']++;
            }
        }
    }

    /** Fixed reason codes only; never retain an exception message or URL. */
    private static function failure(\Throwable $error,string $stage):array
    {
        $result=['stage'=>$stage,'reason'=>'other_failure','httpStatus'=>null,'transportCode'=>null,'retryAfterSeconds'=>null];
        if($error instanceof ProviderHttpStatus){$result['reason']='http';$result['httpStatus']=$error->status;$result['retryAfterSeconds']=$error->retryAfterSeconds;}
        elseif($error instanceof ProviderHttpTransport){$result['reason']='transport';$result['transportCode']=$error->transportCode;}
        else{
            $reasons=['Invalid archive size.'=>'archive_size','Archive directory missing.'=>'archive_directory','Unsupported archive directory.'=>'archive_directory','Unsupported archive member.'=>'archive_member','Conflicting archive headers.'=>'archive_headers','Conflicting archive size.'=>'archive_size','Truncated archive member.'=>'archive_truncated','Invalid archive descriptor.'=>'archive_descriptor','Conflicting archive descriptor.'=>'archive_descriptor','Unexpected archive content.'=>'archive_content','Archive buffer unavailable.'=>'archive_buffer','Archive processing limit.'=>'processing_limit','Invalid compressed member.'=>'archive_compression','Decoded archive limit.'=>'archive_size','Archive buffer failed.'=>'archive_buffer','Archive integrity failed.'=>'archive_integrity','CSV processing limit.'=>'processing_limit','CSV header unavailable.'=>'csv_header','Invalid CSV schema.'=>'csv_schema','Malformed CSV record.'=>'csv_record','Incomplete CSV record.'=>'csv_record','Invalid source time.'=>'source_time','Invalid trade identity.'=>'trade_identity','Target trade limit.'=>'target_limit','Provider request limit reached.'=>'operation_budget','Provider request unavailable.'=>'request_unavailable'];
            $result['reason']=$reasons[$error->getMessage()]??'other_failure';
        }
        return $result;
    }

    /** Reuse source observations, while age and failed attempts remain visible. */
    private static function cachedQuotes(array $state,int $now,string $zone):array
    {
        $quotes=$state['lastQuotes']??[];
        foreach($quotes as $id=>&$quote){
            if($quote['price']!==null&&self::correctionVeto($state,$id,$quote['date'])){
                $quote=self::missing('price_missing');$quote['eligibilityIssue']='unresolved_correction';
            }
            if($quote['price']!==null){
                $quote['status']=self::status($quote['date'],self::instant($quote['observedAt']),$now,$zone);
                $quote['issues']=array_values(array_diff($quote['issues'],['price_stale']));
                if($quote['status']==='stale')$quote['issues'][]='price_stale';
            }
            if(($state['lastAttemptFailed']??false)===true){
                if($quote['price']!==null)$quote['status']='stale';
                $quote['sourceAvailable']=false;$quote['issues']=array_values(array_unique([...$quote['issues'],'source_unavailable']));
            }
        }
        unset($quote);return $quotes;
    }

    private static function identity(array $i):bool
    {
        $p=$i['providerConfig']??[];$s=self::SCHEDULES[$p['tradingSchedule']??'']??null;
        return $s!==null&&($p['identityConfirmed']??false)===true&&($i['currency']??null)==='EUR'
            &&($i['type']??null)===$s['type']&&($i['mic']??null)===$s['mic']
            &&(!isset($p['venue'])||$p['venue']===$s['mic'])&&(!isset($p['publicationVenue'])||$p['publicationVenue']===$s['mic'])
            &&(!isset($i['timeZone'])||$i['timeZone']===self::ZONES[$s['mic']])
            &&is_string($i['isin']??null)&&preg_match('/^[A-Z]{2}[A-Z0-9]{9}[0-9]$/D',$i['isin'])===1;
    }

    /** One exact CSV member, no extraction, ZIP64/encryption/extra members refused. */
    private static function csvStream(string $zip,string $expected,float $started)
    {
        $length=strlen($zip);if($length<22||$length>self::ZIP_LIMIT)throw new \RuntimeException('Invalid archive size.');
        $end=strrpos($zip,"PK\x05\x06");if($end===false||$end<$length-65557||$end+22>$length)throw new \RuntimeException('Archive directory missing.');
        $e=unpack('vdisk/vdirectoryDisk/ventriesDisk/ventries/Vsize/Voffset/vcomment',substr($zip,$end+4,18));
        if(!$e||$e['disk']!==0||$e['directoryDisk']!==0||$e['entriesDisk']!==1||$e['entries']!==1||$end+22+$e['comment']!==$length
            ||$e['offset']+$e['size']!==$end||$e['offset']<30||$e['offset']+46>$end||substr($zip,$e['offset'],4)!=="PK\x01\x02")throw new \RuntimeException('Unsupported archive directory.');
        $c=unpack('vmade/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vdecoded/vname/vextra/vcomment/vdisk/vinternal/Vexternal/Vlocal',substr($zip,$e['offset']+4,42));
        if(!$c||$c['disk']!==0||$c['local']!==0||$c['method']!==8||($c['flags']&~0x0808)!==0||$c['compressed']>self::ZIP_LIMIT||$c['decoded']>self::CSV_LIMIT
            ||$c['decoded']<1||46+$c['name']+$c['extra']+$c['comment']!==$e['size']||substr($zip,$e['offset']+46,$c['name'])!==$expected||substr($zip,0,4)!=="PK\x03\x04")throw new \RuntimeException('Unsupported archive member.');
        $l=unpack('vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/Vdecoded/vname/vextra',substr($zip,4,26));
        if(!$l||$l['flags']!==$c['flags']||$l['method']!==$c['method']||substr($zip,30,$l['name'])!==$expected)throw new \RuntimeException('Conflicting archive headers.');
        foreach(['crc','compressed','decoded'] as $field)if($l[$field]!==$c[$field]&&!(($c['flags']&8)!==0&&$l[$field]===0))throw new \RuntimeException('Conflicting archive size.');
        $start=30+$l['name']+$l['extra'];$dataEnd=$start+$c['compressed'];
        if($dataEnd>$e['offset'])throw new \RuntimeException('Truncated archive member.');
        if(($c['flags']&8)!==0){
            $descriptor=$dataEnd;if(substr($zip,$descriptor,4)==="PK\x07\x08")$descriptor+=4;
            if($descriptor+12!==$e['offset'])throw new \RuntimeException('Invalid archive descriptor.');
            $d=unpack('Vcrc/Vcompressed/Vdecoded',substr($zip,$descriptor,12));
            if(!$d||$d['crc']!==$c['crc']||$d['compressed']!==$c['compressed']||$d['decoded']!==$c['decoded'])throw new \RuntimeException('Conflicting archive descriptor.');
        }elseif($dataEnd!==$e['offset'])throw new \RuntimeException('Unexpected archive content.');
        $stream=fopen('php://temp/maxmemory:2097152','w+b');if($stream===false)throw new \RuntimeException('Archive buffer unavailable.');
        try{
            $ctx=inflate_init(ZLIB_ENCODING_RAW);$crc=hash_init('crc32b');$decoded=0;
            for($position=$start;$position<$dataEnd;$position+=4096){
                if(microtime(true)-$started>45)throw new \RuntimeException('Archive processing limit.');
                $part=@inflate_add($ctx,substr($zip,$position,min(4096,$dataEnd-$position)),ZLIB_SYNC_FLUSH);
                if($part===false)throw new \RuntimeException('Invalid compressed member.');
                $decoded+=strlen($part);if($decoded>$c['decoded']||$decoded>self::CSV_LIMIT)throw new \RuntimeException('Decoded archive limit.');
                hash_update($crc,$part);if(fwrite($stream,$part)!==strlen($part))throw new \RuntimeException('Archive buffer failed.');
            }
            if(inflate_get_status($ctx)!==ZLIB_STREAM_END||inflate_get_read_len($ctx)!==$c['compressed']||$decoded!==$c['decoded']||hash_final($crc)!==sprintf('%08x',$c['crc']))throw new \RuntimeException('Archive integrity failed.');
            rewind($stream);return $stream;
        }catch(\Throwable $e){fclose($stream);throw $e;}
    }

    private static function parseCsv($stream,array $instruments,array $state,int $now,float $started,string $zone,string $window):array
    {
        $header=null;$lineCount=0;$targets=$sessions=$seen=[];
        foreach($instruments as $i)$targets[$i['isin'].'/'.$i['mic']][]=$i;
        while(($line=fgets($stream,65538))!==false){
            if(strlen($line)>65536||++$lineCount>self::ROW_LIMIT||($lineCount%1024===0&&microtime(true)-$started>45))throw new \RuntimeException('CSV processing limit.');
            if($lineCount===1)$line=preg_replace('/^\xEF\xBB\xBF/','',$line);
            if(trim($line)==='')continue;
            if($header===null){
                $fields=str_getcsv(rtrim($line,"\r\n"),',','"','');
                if(!in_array('TradingDateTime',$fields,true)){if($lineCount>=8)throw new \RuntimeException('CSV header unavailable.');continue;}
                if(count($fields)>64||count($fields)!==count(array_unique($fields))||array_diff(self::FIELDS,$fields))throw new \RuntimeException('Invalid CSV schema.');
                $header=$fields;continue;
            }
            if(preg_match('/\A(?:"(?:[^"]|"")*"|[^",\r\n]*)(?:,(?:"(?:[^"]|"")*"|[^",\r\n]*))*\r?\n?\z/D',$line)!==1)throw new \RuntimeException('Malformed CSV record.');
            $fields=str_getcsv(rtrim($line,"\r\n"),',','"','');if(count($fields)!==count($header))throw new \RuntimeException('Incomplete CSV record.');
            $row=array_combine($header,$fields);$matched=$targets[$row['MifidInstrumentID'].'/'.$row['Venue']]??[];
            if(!$matched)continue;
            $trade=self::instant($row['TradingDateTime']);$published=self::instant($row['PublicationDateTime']);
            foreach($matched as $i){
                $id=$i['id'];$date=$trade===null?null:self::local($trade,$zone)->format('Y-m-d');
                // Corrections precede ordinary eligibility filters, including
                // out-of-session flags. Unknown linkage invalidates the date.
                if($row['MmtModificationIndicator']!=='-'){
                    if($date!==null&&$trade<=$now)$state['invalidDates'][$id][$date]=true;
                    else $state['invalidThrough'][$id]=self::local($now,$zone)->format('Y-m-d');
                    continue;
                }
                if($trade===null||$published===null||$trade>$now||$published>$now||strcmp(self::timeKey($row['PublicationDateTime']),self::timeKey($row['TradingDateTime']))<0)throw new \RuntimeException('Invalid source time.');
                $transaction=$row['TradeUniqueIdentifier'];
                if(!preg_match('/^[A-Za-z0-9._-]{1,128}$/D',$transaction))throw new \RuntimeException('Invalid trade identity.');
                $signature=$date.hash('sha256',json_encode($row,JSON_THROW_ON_ERROR));
                if(isset($seen[$id][$transaction])&&$seen[$id][$transaction]!==$signature){$state['invalidDates'][$id][$date]=true;$state['invalidDates'][$id][substr($seen[$id][$transaction],0,10)]=true;continue;}
                if(!isset($seen[$id][$transaction])&&count($seen[$id]??[])>=self::TARGET_LIMIT)throw new \RuntimeException('Target trade limit.');
                $seen[$id][$transaction]=$signature;
                if(!self::normalDate($date,$zone)||$trade>$now-900)continue;
                $time=self::local($trade,$zone)->format('H:i:s');if($time<'09:05:00'||$time>='17:30:00')continue;
                if($row['MifidCurrency']!=='EUR'||$row['MifidPriceNotation']!=='MONE'||$row['VenueOfPublication']!==$i['mic']
                    ||$row['MmtMarketMechanism']!=='1'||$row['ThirdCountryTradingVenueExecution']!==''||$row['MissingPrice']!==''
                    ||!in_array($row['MmtAlgorithmicIndicator'],['-','ALGO'],true))continue;
                $ordinary=true;foreach(['MmtNegotiationIndicator','MmtBenchMarkIndicator','MmtContributionToPrice','MmtPublicationMode','MmtContingentTransactionIndicator'] as $field)if($row[$field]!=='-')$ordinary=false;
                $price=self::decimal($row['MifidPrice']);if(!$ordinary||$price===null||self::decimal($row['MifidQuantity'])===null)continue;
                $key=self::timeKey($row['TradingDateTime']);$old=$sessions[$id][$date]??null;
                if($old===null||$key>$old['key'])$sessions[$id][$date]=['price'=>$price,'time'=>$row['TradingDateTime'],'published'=>$row['PublicationDateTime'],'key'=>$key,'ambiguous'=>false];
                elseif($key===$old['key']&&$price!==$old['price'])$sessions[$id][$date]['ambiguous']=true;
            }
        }
        if($header===null)throw new \RuntimeException('CSV header unavailable.');
        // A stale upstream archive can retreat without reporting a correction.
        // Keep the previous dated observations, but fail this attempt. Finish
        // examining every target first so actual corrections/conflicts from
        // the same response remain binding on the retained cache.
        $regressed=false;
        foreach($instruments as $i){
            $id=$i['id'];$dates=$sessions[$id]??[];krsort($dates);$date=array_key_first($dates);$candidate=$date===null?null:$dates[$date];
            $last=$state['lastObservation'][$id]??null;
            if($candidate!==null&&$last!==null){
                if($candidate['key']<$last['key'])$regressed=true;
                elseif($candidate['key']===$last['key']&&$candidate['price']!==$last['price'])$state['invalidDates'][$id][$last['date']]=true;
            }
        }
        if($regressed){
            $state['lastAttemptFailed']=true;
            return ['quotes'=>self::cachedQuotes($state,$now,$zone),'state'=>$state,'sourceRegressed'=>true];
        }
        $quotes=[];
        foreach($instruments as $i){
            $id=$i['id'];$dates=$sessions[$id]??[];krsort($dates);$date=array_key_first($dates);$candidate=$date===null?null:$dates[$date];
            $invalid=$date!==null&&(isset($state['invalidDates'][$id][$date])||$date<=($state['invalidThrough'][$id]??''));
            // A corrected newest date may contain no ordinary trade at all.
            $invalidDates=array_keys($state['invalidDates'][$id]??[]);rsort($invalidDates);
            if($invalidDates&&($date===null||$invalidDates[0]>=$date))$invalid=true;
            if($candidate===null||$invalid||$candidate['ambiguous']){
                $quotes[$id]=self::missing('price_missing');
                $quotes[$id]['eligibilityIssue']=$invalid?'unresolved_correction':($candidate!==null&&$candidate['ambiguous']?'conflicting_latest_trade_prices':'no_eligible_observation');
                if(!$invalid&&$candidate!==null&&$candidate['ambiguous'])$quotes[$id]['eligibilityDate']=$date;
            }else{
                $status=self::status($date,self::instant($candidate['time']),$now,$zone);
                $codes=['baseline_missing'];if($status==='stale')$codes[]='price_stale';
                $quotes[$id]=['price'=>$candidate['price'],'previousClose'=>null,'date'=>$date,'previousDate'=>null,'observedAt'=>$candidate['time'],'previousObservedAt'=>null,
                    'publicationAt'=>$candidate['published'],'retrievedAt'=>gmdate('Y-m-d\TH:i:s\Z',$now),'status'=>$status,'source'=>'Euronext delayed main-session trades',
                    'observationKind'=>'main_session_trade','sourceWindow'=>$window,'fx'=>'1','previousFx'=>'1','issues'=>$codes];
                $state['lastObservation'][$id]=['key'=>$candidate['key'],'date'=>$date,'price'=>$candidate['price']];
            }
            if(isset($state['invalidDates'][$id])){krsort($state['invalidDates'][$id]);$state['invalidDates'][$id]=array_slice($state['invalidDates'][$id],0,5,true);}
        }
        return ['quotes'=>$quotes,'state'=>$state];
    }

    private static function correctionVeto(array $state,string $id,string $date):bool
    {
        if($date<=($state['invalidThrough'][$id]??''))return true;
        foreach(array_keys($state['invalidDates'][$id]??[]) as $invalidDate)if($invalidDate>=$date)return true;
        return false;
    }

    private static function normalDate(string $date,string $zone):bool
    {
        // This adapter validates the reviewed normal trading groups. A future
        // half-day needs its trade-window/publication validation reviewed too.
        $day=MarketCalendars::day((string)array_search($zone,self::ZONES,true),$date);
        return $date>='2026-08-03'&&$day!==null&&$day['isSession']&&$day['hoursKnown']&&$day['openMinute']===540&&$day['closeMinute']===1060;
    }
    private static function status(string $date,int $trade,int $now,string $zone):string
    {
        $local=self::local($now,$zone);$today=$local->format('Y-m-d');$time=$local->format('H:i:s');
        if((MarketCalendars::day((string)array_search($zone,self::ZONES,true),$today)['hoursKnown']??false)===false)return 'stale';
        $open=self::normalDate($today,$zone)&&$time>='09:20:00'&&$time<'17:45:00';
        $expected=$local;
        if(!self::normalDate($today,$zone)||$time<'09:20:00'){
            do{
                $expected=$expected->modify('-1 day');
                // An unverified half-day traded; it is not a holiday to skip.
                if((MarketCalendars::day((string)array_search($zone,self::ZONES,true),$expected->format('Y-m-d'))['hoursKnown']??false)===false)return 'stale';
            }while($expected->format('Y-m-d')>='2026-08-03'&&!self::normalDate($expected->format('Y-m-d'),$zone));
        }
        $sessionEnd=(new \DateTimeImmutable($date.'T17:30:00',new \DateTimeZone($zone)))->getTimestamp();
        $ageAt=$open?min($now,$sessionEnd):$sessionEnd;
        if($date!==$expected->format('Y-m-d')||$ageAt-$trade>5400)return 'stale';
        return $open?'delayed':'closed';
    }
    private static function local(int $time,string $zone):\DateTimeImmutable{return (new \DateTimeImmutable('@'.$time))->setTimezone(new \DateTimeZone($zone));}
    private static function instant(string $value):?int
    {
        if(!preg_match('/^(\d{4})-(\d{2})-(\d{2})T([01]\d|2[0-3]):([0-5]\d):([0-5]\d)(?:\.\d{1,9})?Z$/D',$value,$m)||!checkdate((int)$m[2],(int)$m[3],(int)$m[1]))return null;
        return strtotime(substr($value,0,19).'Z');
    }
    private static function timeKey(string $value):string{$p=explode('.',rtrim($value,'Z'),2);return $p[0].'.'.str_pad($p[1]??'',9,'0');}
    private static function decimal(string $value):?string
    {
        if(strlen($value)>60||!preg_match('/^(?:0|[1-9]\d*)(?:\.\d+)?$/D',$value)||!preg_match('/[1-9]/',$value))return null;
        return str_contains($value,'.')?rtrim(rtrim($value,'0'),'.'):$value;
    }
    private static function missing(string $issue):array
    {
        return ['price'=>null,'previousClose'=>null,'date'=>null,'previousDate'=>null,'observedAt'=>null,'previousObservedAt'=>null,'status'=>'missing',
            'source'=>'Euronext delayed main-session trades','observationKind'=>'main_session_trade','fx'=>'1','previousFx'=>'1','issues'=>[$issue,'baseline_missing']];
    }
}
