<?php
declare(strict_types=1);
namespace RichYet;

/** Pure reference-data imports. These define candidates, never price readiness.
 * No network, credentials, portfolios or provider quota lives in this class. */
final class DirectorySources
{
    private const EXCHANGES = ['NASDAQ'=>['XNAS','Nasdaq'],'NYSE'=>['XNYS','NYSE'],'ARCA'=>['ARCX','NYSE Arca'],'BATS'=>['BATS','Cboe BZX'],'AMEX'=>['XASE','NYSE American']];

    /** Exact Alpaca symbol/exchange join with Nasdaq's explicit ETF classification.
     * A non-ETF is admitted as Stock only when its security name says shares/stock.
     * Alias keys are MIC|currency|symbol and values are reviewed registry entries. */
    public static function us(array $assets,string $nasdaq,string $other,int $now,array $aliases=[]):array
    {
        if(!array_is_list($assets)||count($assets)>20000)throw new \RuntimeException('Invalid asset directory.');
        $a=self::nasdaq($nasdaq,false,$now);$b=self::nasdaq($other,true,$now);$reference=[];$excluded=[];
        foreach(array_merge($a['rows'],$b['rows']) as $row){$key=$row['exchange'].'|'.$row['symbol'];if(isset($reference[$key]))throw new \RuntimeException('Duplicate exchange reference.');$reference[$key]=$row;}
        $instruments=[];$seen=[];
        foreach($assets as $asset){
            if(!is_array($asset))throw new \RuntimeException('Invalid asset row.');
            $symbol=$asset['symbol']??null;$exchange=$asset['exchange']??null;
            if(!self::symbol($symbol)||!is_string($exchange)){self::omit($excluded,'invalid_identity');continue;}
            $key=$exchange.'|'.$symbol;if(isset($seen[$key]))throw new \RuntimeException('Duplicate asset listing.');$seen[$key]=true;
            if(($asset['class']??null)!=='us_equity'||($asset['status']??null)!=='active'||($asset['tradable']??null)!==true||!isset(self::EXCHANGES[$exchange])){self::omit($excluded,'unsupported_asset');continue;}
            $row=$reference[$key]??null;
            if($row===null||$row['type']===null){self::omit($excluded,'unmatched_or_ambiguous_type');continue;}
            if(!self::text($asset['name']??null)){self::omit($excluded,'invalid_name');continue;}
            [$mic,$label]=self::EXCHANGES[$exchange];$identity=$mic.'|USD|'.$symbol;
            $i=['id'=>self::id($identity),'name'=>$asset['name'],'symbol'=>$symbol,'exchange'=>$label,'mic'=>$mic,'currency'=>'USD','type'=>$row['type'],
                'coverage'=>['price'=>true,'comparison'=>true],'provider'=>'alpaca','providerConfig'=>['symbol'=>$symbol,'identityConfirmed'=>true],'timeZone'=>'America/New_York'];
            $instruments[]=self::alias($i,$identity,$aliases);
        }
        return ['instruments'=>$instruments,'evidence'=>['source'=>'Alpaca active assets + Nasdaq symbol directories','assetRows'=>count($assets),'nasdaqRows'=>count($a['rows']),'otherRows'=>count($b['rows']),
            'nasdaqDate'=>$a['date'],'otherDate'=>$b['date'],'nasdaqSha256'=>hash('sha256',$nasdaq),'otherSha256'=>hash('sha256',$other),'count'=>count($instruments)],'excluded'=>$excluded];
    }

    private static function nasdaq(string $raw,bool $other,int $now):array
    {
        if(strlen($raw)>5000000||str_contains($raw,"\0"))throw new \RuntimeException('Invalid symbol directory size.');
        $lines=preg_split('/\r?\n/',trim($raw));$header=str_getcsv(array_shift($lines),'|','"','');
        $required=$other?['ACT Symbol','Security Name','Exchange','ETF','Test Issue']:['Symbol','Security Name','Market Category','Test Issue','Financial Status','ETF','NextShares'];
        if(count($header)!==count(array_unique($header))||array_diff($required,$header)||count($lines)>20001)throw new \RuntimeException('Invalid symbol directory header.');
        $rows=[];$footer=null;
        foreach($lines as $line){
            if(str_starts_with($line,'File Creation Time: ')){
                if($footer!==null||!preg_match('/^File Creation Time: (\d{2})(\d{2})(\d{4})(\d{2}):(\d{2})\|+$/D',$line,$m)||!checkdate((int)$m[1],(int)$m[2],(int)$m[3])||(int)$m[4]>23||(int)$m[5]>59)throw new \RuntimeException('Invalid symbol directory timestamp.');
                $footer=$m[3].'-'.$m[1].'-'.$m[2];continue;
            }
            if($footer!==null)throw new \RuntimeException('Data after symbol directory footer.');
            $values=str_getcsv($line,'|','"','');if(count($values)!==count($header))throw new \RuntimeException('Incomplete symbol directory row.');$r=array_combine($header,$values);
            $symbol=$r[$other?'ACT Symbol':'Symbol'];$exchange=$other?(['N'=>'NYSE','P'=>'ARCA','Z'=>'BATS','A'=>'AMEX','V'=>'IEX'][$r['Exchange']]??null):'NASDAQ';
            if(!self::symbol($symbol)||!self::text($r['Security Name'])||$exchange===null)continue;
            $type=null;
            if($r['Test Issue']==='N'&&($other||($r['Financial Status']==='N'&&$r['NextShares']==='N'&&in_array($r['Market Category'],['Q','G','S'],true)))){
                if($r['ETF']==='Y')$type='ETF';
                elseif($r['ETF']==='N'&&preg_match('/\b(?:common stock|common shares?|ordinary shares?|depositary shares?|depositary receipts?)\b/i',$r['Security Name'])
                    &&!preg_match('/\b(?:preferred|preference|warrants?|rights?|units?|notes?|debentures?|bonds?|convertible)\b/i',$r['Security Name']))$type='Stock';
            }
            $rows[]=['symbol'=>$symbol,'exchange'=>$exchange,'type'=>$type];
        }
        if($footer===null||!$rows||$footer<gmdate('Y-m-d',$now-7*86400)||$footer>gmdate('Y-m-d',$now))throw new \RuntimeException('Stale or missing symbol directory timestamp.');
        return ['rows'=>$rows,'date'=>$footer];
    }

    /** Exchange reference contains authoritative identity and security type.
     * Provider mappings must come from a separate exact-listing cross-check.
     * Unmapped candidates remain metadata, and are not admitted as instruments.
     * mappings[ISIN] = {symbol, historySymbol, publicationVenue, actionsSymbol?}. */
    public static function xetra(string $csv,int $now,array $aliases=[],array $mappings=[]):array
    {
        if($csv===''||strlen($csv)>8388608||str_contains($csv,"\0"))throw new \RuntimeException('Invalid reference size.');
        $stream=fopen('php://temp','w+');fwrite($stream,preg_replace('/^\xEF\xBB\xBF/','',$csv));rewind($stream);
        try{
            $market=fgetcsv($stream,65537,';','"','');$dated=fgetcsv($stream,65537,';','"','');
            if($market!==['Market:','XETR']||!is_array($dated)||count($dated)!==2||$dated[0]!=='Date Last Update:'||!preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/D',$dated[1],$m)||!checkdate((int)$m[2],(int)$m[1],(int)$m[3]))throw new \RuntimeException('Invalid reference preamble.');
            $date=$m[3].'-'.$m[2].'-'.$m[1];if($date<gmdate('Y-m-d',$now-7*86400)||$date>gmdate('Y-m-d',$now+7*86400))throw new \RuntimeException('Stale reference date.');
            $header=fgetcsv($stream,65537,';','"','');$required=['Product Status','Instrument Status','Instrument','ISIN','Instrument ID','Mnemonic','MIC Code','Reporting Market','Instrument Type','Unit of Quotation','Currency','Settlement Currency','CUM/EX Indicator'];
            if(!is_array($header)||count($header)>256||count($header)!==count(array_unique($header))||array_diff($required,$header))throw new \RuntimeException('Invalid reference columns.');
            $count=0;$seen=[];$candidates=[];$instruments=[];$excluded=[];
            while(($values=fgetcsv($stream,65537,';','"',''))!==false){
                if($values===[null])continue;if(++$count>20000||count($values)!==count($header))throw new \RuntimeException('Incomplete reference row.');$r=array_combine($header,$values);
                if($r['Product Status']!=='Active'||$r['Instrument Status']!=='Active'||$r['Currency']!=='EUR'||$r['Settlement Currency']!=='EUR'||$r['MIC Code']!=='XETR'||$r['Unit of Quotation']!=='Shares'||!in_array($r['Instrument Type'],['CS','ETF'],true)){self::omit($excluded,'unsupported_reference');continue;}
                if(!preg_match('/^[A-Z]{2}[A-Z0-9]{9}[0-9]$/D',$r['ISIN'])||!self::symbol($r['Mnemonic'])||!self::text($r['Instrument'])||!preg_match('/^[1-9][0-9]{0,19}$/D',$r['Instrument ID'])||!in_array($r['Reporting Market'],['XETA','XETB','XETS'],true)||!in_array($r['CUM/EX Indicator'],['','C','E'],true)){self::omit($excluded,'invalid_reference');continue;}
                $isin=$r['ISIN'];if(isset($seen[$isin]))throw new \RuntimeException('Ambiguous reference listing.');$seen[$isin]=true;
                $c=['name'=>$r['Instrument'],'isin'=>$isin,'symbol'=>$r['Mnemonic'],'mic'=>'XETR','currency'=>'EUR','type'=>$r['Instrument Type']==='ETF'?'ETF':'Stock','venue'=>$r['Reporting Market'],'instrumentId'=>$r['Instrument ID'],'referenceDate'=>$date,'indicator'=>$r['CUM/EX Indicator']];$candidates[]=$c;
                $map=$mappings[$isin]??null;if($map===null){self::omit($excluded,'provider_mapping_needed');continue;}
                if(!is_array($map)||array_diff(array_keys($map),['symbol','historySymbol','publicationVenue','actionsSymbol','historyIdentityRule'])||($map['symbol']??null)!==$c['symbol']||($map['historySymbol']??null)!==$c['symbol'].'.DE'||$c['venue']!=='XETA'||($map['publicationVenue']??null)!=='XCEF'||(isset($map['actionsSymbol'])&&$map['actionsSymbol']!==$c['symbol'].'.XETRA')||(isset($map['historyIdentityRule'])&&$map['historyIdentityRule']!=='xetra_auction_match'))throw new \RuntimeException('Mismatched provider mapping.');
                $p=['identityConfirmed'=>true,'venue'=>$c['venue'],'publicationVenue'=>$map['publicationVenue'],'historyProvider'=>'newton','historySymbol'=>$map['historySymbol'],'historyIdentityConfirmed'=>true];
                if(isset($map['historyIdentityRule'])){$p['historyIdentityRule']=$map['historyIdentityRule'];$p['historyIdentityConfirmed']=false;}
                if(isset($map['actionsSymbol']))$p+=['actionsProvider'=>'eodhd','actionsSymbol'=>$map['actionsSymbol'],'actionsIdentityConfirmed'=>true];
                $identity='XETR|EUR|'.$isin;$i=['id'=>self::id($identity),'name'=>$c['name'],'symbol'=>$c['symbol'],'exchange'=>'Xetra','mic'=>'XETR','currency'=>'EUR','type'=>$c['type'],'isin'=>$isin,'coverage'=>['price'=>true,'comparison'=>true],'provider'=>'xetra','providerConfig'=>$p,'timeZone'=>'Europe/Berlin'];
                $instruments[]=self::alias($i,$identity,$aliases);
            }
            if($count===0)throw new \RuntimeException('Empty reference directory.');
            return ['instruments'=>$instruments,'candidates'=>$candidates,'evidence'=>['source'=>'Deutsche Börse T7 Xetra reference','date'=>$date,'sha256'=>hash('sha256',$csv),'rows'=>$count,'candidates'=>count($candidates),'count'=>count($instruments)],'excluded'=>$excluded];
        }finally{fclose($stream);}
    }

    /** Private operator runner must reserve these three costs in the host ledger.
     * Credentials are appended only by ProviderHttp, never by this pure builder. */
    public static function eodDirectoryRequests():array
    {
        return array_map(static fn($exchange)=>['exchange'=>$exchange,'url'=>'https://eodhd.com/api/exchange-symbol-list/'.$exchange.'?fmt=json','maxBytes'=>8388608,'cost'=>1],['AS','PA','XETRA']);
    }
    /** EOD's whole exchange metadata is evidence for its own identifiers. It
     * does not prove that another provider uses an equivalent symbol. */
    public static function eod(string $raw,string $exchange,int $retrievedAt,int $now):array
    {
        if(!in_array($exchange,['AS','PA','BR','XETRA'],true)||strlen($raw)>8388608||$retrievedAt>$now||$retrievedAt<$now-7*86400)throw new \RuntimeException('Invalid exchange directory evidence.');
        $rows=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        if(!is_array($rows)||!array_is_list($rows)||count($rows)<1||count($rows)>20000)throw new \RuntimeException('Invalid exchange directory.');
        $candidates=$excluded=$seen=[];
        foreach($rows as $r){
            if(!is_array($r)||array_diff(['Code','Name','Exchange','Currency','Type','Isin'],array_keys($r)))throw new \RuntimeException('Incomplete exchange directory row.');
            if(($r['Exchange']??null)!==$exchange)throw new \RuntimeException('Wrong exchange directory.');
            if(!self::symbol($r['Code'])||!self::text($r['Name'])){self::omit($excluded,'invalid_identity');continue;}
            if(isset($seen[$r['Code']]))throw new \RuntimeException('Duplicate exchange symbol.');$seen[$r['Code']]=true;
            if($r['Currency']!=='EUR'||!in_array($r['Type'],['Common Stock','ETF'],true)||!is_string($r['Isin'])||!preg_match('/^[A-Z]{2}[A-Z0-9]{9}[0-9]$/D',$r['Isin'])){self::omit($excluded,'unsupported_identity');continue;}
            $candidates[]=['name'=>$r['Name'],'symbol'=>$r['Code'],'isin'=>$r['Isin'],'currency'=>'EUR','type'=>$r['Type']==='ETF'?'ETF':'Stock','mic'=>['AS'=>'XAMS','PA'=>'XPAR','BR'=>'XBRU','XETRA'=>'XETR'][$exchange],'actionsSymbol'=>$r['Code'].'.'.$exchange];
        }
        return ['candidates'=>$candidates,'instruments'=>[],'evidence'=>['source'=>'EODHD exchange symbol directory','exchange'=>$exchange,'retrievedAt'=>$retrievedAt,'sha256'=>hash('sha256',$raw),'rows'=>count($rows),'candidates'=>count($candidates)],'excluded'=>$excluded];
    }
    /** Automated exact directory join. Every new Newton mapping is pending
     * runtime corroboration with the exact ISIN's official Xetra auction. */
    public static function xetraJoined(string $csv,string $eodRaw,int $retrievedAt,int $now,array $aliases=[]):array
    {
        $reference=self::xetra($csv,$now);$eod=self::eod($eodRaw,'XETRA',$retrievedAt,$now);$by=[];$maps=[];
        foreach($eod['candidates'] as $c)$by[$c['isin'].'|'.$c['symbol']]=$c;
        foreach($reference['candidates'] as $c){
            $match=$by[$c['isin'].'|'.$c['symbol']]??null;
            if($c['venue']!=='XETA'||$match===null||$match['type']!==$c['type']||$match['currency']!==$c['currency'])continue;
            $maps[$c['isin']]=['symbol'=>$c['symbol'],'historySymbol'=>$c['symbol'].'.DE','publicationVenue'=>'XCEF','actionsSymbol'=>$match['actionsSymbol'],'historyIdentityRule'=>'xetra_auction_match'];
        }
        $result=self::xetra($csv,$now,$aliases,$maps);$result['evidence']['eod']=$eod['evidence'];$result['evidence']['historyIdentityRule']='exact directory join, then runtime official auction match';return $result;
    }
    /** Euronext currently needs independently reviewed symbols and continuous
     * trading schedules. Never infer an auction-only listing's schedule from
     * merely sharing its exchange with a reviewed large company. */
    public static function euronext(string $raw,string $exchange,int $retrievedAt,int $now,array $mappings=[],array $aliases=[]):array
    {
        if(!in_array($exchange,['AS','PA','BR'],true))throw new \RuntimeException('Unsupported Euronext directory.');
        $result=self::eod($raw,$exchange,$retrievedAt,$now);
        $schedules=['XAMS|Stock'=>'ams_j0_continuous_2026','XAMS|ETF'=>'ams_eur_etf_continuous_2026','XPAR|Stock'=>'par_stock_continuous_2026','XPAR|ETF'=>'par_eur_etf_continuous_2026','XBRU|Stock'=>'bru_a0_continuous_2026'];
        $markets=['XAMS'=>['Euronext Amsterdam','Europe/Amsterdam'],'XPAR'=>['Euronext Paris','Europe/Paris'],'XBRU'=>['Euronext Brussels','Europe/Brussels']];
        foreach($result['candidates'] as $c){
            $map=$mappings[$c['isin']]??null;if($map===null)continue;
            if(!is_array($map)||array_diff(array_keys($map),['symbol','mic','type','historySymbol','tradingSchedule'])||($map['symbol']??null)!==$c['symbol']||($map['mic']??null)!==$c['mic']||($map['type']??null)!==$c['type']||($map['historySymbol']??null)!==$c['symbol'].'.'.$exchange||($map['tradingSchedule']??null)!==($schedules[$c['mic'].'|'.$c['type']]??false))throw new \RuntimeException('Euronext reviewed mapping mismatch.');
            $identity=$c['mic'].'|EUR|'.$c['isin'];$i=['id'=>self::id($identity),'name'=>$c['name'],'symbol'=>$c['symbol'],'exchange'=>$markets[$c['mic']][0],'mic'=>$c['mic'],'currency'=>'EUR','type'=>$c['type'],'isin'=>$c['isin'],
                'coverage'=>['price'=>true,'comparison'=>true],'provider'=>'euronext','providerConfig'=>['identityConfirmed'=>true,'venue'=>$c['mic'],'publicationVenue'=>$c['mic'],'tradingSchedule'=>$map['tradingSchedule'],'historyProvider'=>'newton','historySymbol'=>$map['historySymbol'],'historyIdentityConfirmed'=>true,'actionsProvider'=>'eodhd','actionsSymbol'=>$c['actionsSymbol'],'actionsIdentityConfirmed'=>true],'timeZone'=>$markets[$c['mic']][1]];
            $result['instruments'][]=self::alias($i,$identity,$aliases);
        }
        $result['evidence']['count']=count($result['instruments']);return $result;
    }
    /** Audience permission is an explicit operator input, not inferred by a parser. */
    public static function entries(array $result,array $policy):array
    {
        if(array_diff(array_keys($policy),['priceAccess','fxAccess','priceHistory','fxHistory'])||!in_array($policy['priceAccess']??null,['owner','invited','none','unverified'],true)||!in_array($policy['fxAccess']??null,['owner','invited','none','unverified'],true)||!is_bool($policy['priceHistory']??null)||!is_bool($policy['fxHistory']??null))throw new \RuntimeException('Explicit directory policy required.');
        return array_map(static fn($i)=>['instrument'=>$i,'policy'=>$policy],$result['instruments']);
    }
    private static function alias(array $i,string $key,array $aliases):array
    {
        if(!isset($aliases[$key]))return $i;$alias=$aliases[$key];
        if(!is_array($alias))throw new \RuntimeException('Invalid listing alias.');
        foreach(['currency','type','provider'] as $field)if(($alias[$field]??null)!==$i[$field])throw new \RuntimeException('Listing alias mismatch.');
        $exactBook=$i['provider']==='xetra'&&$i['mic']==='XETR'&&($alias['mic']??null)==='XETA'&&($alias['providerConfig']['venue']??null)==='XETA'&&$i['providerConfig']['venue']==='XETA';
        if((($alias['mic']??null)!==$i['mic']&&!$exactBook)||($alias['symbol']??null)!==$i['symbol']||!preg_match('/^[A-Za-z0-9_-]{1,60}$/D',$alias['id']??''))throw new \RuntimeException('Listing alias identity mismatch.');
        if($i['provider']==='alpaca'&&($alias['providerConfig']['symbol']??null)!==$i['providerConfig']['symbol'])throw new \RuntimeException('Asset alias mismatch.');
        if($i['provider']==='xetra'&&(($alias['isin']??null)!==$i['isin']||($alias['providerConfig']['venue']??null)!==$i['providerConfig']['venue']||($alias['providerConfig']['publicationVenue']??null)!==$i['providerConfig']['publicationVenue']))throw new \RuntimeException('Reference alias mismatch.');
        if($i['provider']==='euronext'&&(($alias['isin']??null)!==$i['isin']||($alias['providerConfig']['tradingSchedule']??null)!==$i['providerConfig']['tradingSchedule']))throw new \RuntimeException('Euronext alias mismatch.');
        if(in_array($i['provider'],['xetra','euronext'],true)){
            foreach(['historyProvider','historySymbol'] as $field)if(($alias['providerConfig'][$field]??null)!==$i['providerConfig'][$field])throw new \RuntimeException('History alias mismatch.');
            foreach(['actionsProvider','actionsSymbol'] as $field)if(isset($alias['providerConfig'][$field],$i['providerConfig'][$field])&&$alias['providerConfig'][$field]!==$i['providerConfig'][$field])throw new \RuntimeException('Action alias mismatch.');
        }
        return $alias;
    }
    private static function id(string $identity):string{return 'dir-'.substr(hash('sha256',$identity),0,24);}
    private static function symbol(mixed $s):bool{return is_string($s)&&preg_match('/^[A-Z0-9][A-Z0-9.-]{0,19}$/D',$s)===1;}
    private static function text(mixed $s):bool{return is_string($s)&&$s!==''&&strlen($s)<=160&&!preg_match('/[\x00-\x1f\x7f]/',$s);}
    private static function omit(array &$counts,string $reason):void{$counts[$reason]=($counts[$reason]??0)+1;}
}
