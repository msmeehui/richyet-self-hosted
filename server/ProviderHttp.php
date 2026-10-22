<?php
declare(strict_types=1);
namespace RichYet;

/** Bounded status only: no response body, URL, credential or redirect query. */
final class ProviderHttpStatus extends \RuntimeException
{
    public function __construct(public readonly int $status,public readonly ?int $retryAfterSeconds=null) { parent::__construct('Provider returned an unsuccessful HTTP status.'); }
}

/** Only a bounded cURL code is retained; URLs, bodies and keys are excluded. */
final class ProviderHttpTransport extends \RuntimeException
{
    public function __construct(public readonly int $transportCode) { parent::__construct('Provider transport could not complete.'); }
}

/** Fixed read-only HTTPS endpoints. Provider errors never include body/key/URL. */
final class ProviderHttp
{
    private int $calls=0;
    private int $bytes=0;
    private array $statuses=[];
    private readonly float $started;
    public function __construct(private readonly MarketStore $store,private readonly array $keys,private readonly int $now,private readonly int $maxSeconds=180,private readonly int $maxRequests=80,private readonly bool $preparation=false) {
        if($maxSeconds<1||$maxSeconds>180||$maxRequests<1||$maxRequests>80)throw new \RuntimeException('Invalid provider operation budget.');
        $this->started=microtime(true);
    }
    public function evidence():array { return ['requests'=>$this->calls,'responseBytes'=>$this->bytes,'httpStatuses'=>$this->statuses,'elapsedSeconds'=>(int)(microtime(true)-$this->started)]; }
    public static function credentials(string $path,?string $evaluationPath=null):array
    {
        $path=Maintenance::privatePath($path,true);
        if (!function_exists('posix_geteuid') || filesize($path)>16384 || fileowner($path)!==posix_geteuid() || fileowner(dirname($path))!==posix_geteuid()) throw new \RuntimeException('Provider credentials are not private.');
        $file=@fopen($path,'rb');if(!$file)throw new \RuntimeException('Provider credentials unavailable.');
        try{$stat=fstat($file);if(!$stat||($stat['mode']&0170000)!==0100000||($stat['mode']&0077)!==0||$stat['uid']!==posix_geteuid()||$stat['size']>16384)throw new \RuntimeException('Provider credentials are not a private regular file.');$json=stream_get_contents($file,16385);if($json===false||strlen($json)>16384)throw new \RuntimeException('Provider credential size invalid.');}finally{fclose($file);}
        $keys=json_decode($json,true,16,JSON_THROW_ON_ERROR);
        foreach (['ALPACA_API_KEY_ID','ALPACA_API_SECRET_KEY','TICKERLAYER_API_KEY'] as $key) {
            if (!is_string($keys[$key]??null) || strlen($keys[$key])<8 || strlen($keys[$key])>512 || !preg_match('/^[\x21-\x7e]+$/D',$keys[$key])) throw new \RuntimeException('Provider credentials are incomplete.');
        }
        if($evaluationPath!==null){
            $extraPath=Maintenance::privatePath($evaluationPath,true);
            if(filesize($extraPath)>16384||fileowner($extraPath)!==posix_geteuid()||fileowner(dirname($extraPath))!==posix_geteuid())throw new \RuntimeException('Evaluation credentials are not private.');
            $file=@fopen($extraPath,'rb');if(!$file)throw new \RuntimeException('Evaluation credentials unavailable.');
            try{$stat=fstat($file);if(!$stat||($stat['mode']&0170000)!==0100000||($stat['mode']&0077)!==0||$stat['uid']!==posix_geteuid()||$stat['size']>16384)throw new \RuntimeException('Evaluation credentials are not a private regular file.');$extra=json_decode(stream_get_contents($file,16385),true,16,JSON_THROW_ON_ERROR);}finally{fclose($file);}
            $key=$extra['EODHD_API_KEY']??null;if(!is_string($key)||strlen($key)<8||strlen($key)>512||!preg_match('/^[A-Za-z0-9._-]+$/D',$key))throw new \RuntimeException('Evaluation credentials are incomplete.');
            $keys['EODHD_API_KEY']=$key;
        }
        return $keys;
    }
    public static function provider(string $url):string
    {
        $u=parse_url($url);
        if (!$u || ($u['scheme']??'')!=='https' || (isset($u['user']) || isset($u['pass'])) || isset($u['port']) || isset($u['fragment']) || !isset($u['host'],$u['path']) || str_contains($u['path'],'..') || str_contains($u['path'],'%')) throw new \RuntimeException('Provider URL refused.');
        $host=$u['host']; $path=$u['path'];
        if ($host==='paper-api.alpaca.markets' && preg_match('~^/v2/(?:calendar|assets|assets/[A-Z0-9.-]{1,20})$~D',$path)) return 'alpaca';
        if ($host==='www.nasdaqtrader.com' && !isset($u['query']) && in_array($path,['/dynamic/symdir/nasdaqlisted.txt','/dynamic/symdir/otherlisted.txt'],true)) return 'european';
        if ($host==='data.alpaca.markets' && in_array($path,['/v2/stocks/bars','/v1/corporate-actions'],true)) return 'alpaca';
        if ($host==='api.tickerlayer.com' && preg_match('~^/forex/agg/EURUSD/(?:prev|1/hour/\d{4}-\d{2}-\d{2}/\d{4}-\d{2}-\d{2})$~D',$path)) return 'tickerlayer';
        if ($host==='api.newtonanalytics.com' && $path==='/price/') {
            parse_str($u['query']??'',$q);$expected=['ticker','interval','dataType','observations'];
            if(count($q)===4&&!array_diff(array_keys($q),$expected)&&is_string($q['ticker'])&&preg_match('/^[A-Z0-9.-]{1,30}$/D',$q['ticker'])&&$q['interval']==='1d'&&$q['dataType']==='0146'&&$q['observations']==='10')return 'newton';
        }
        if ($host==='eodhd.com' && preg_match('~^/api/splits/[A-Z0-9.-]{1,30}$~D',$path)) {
            parse_str($u['query']??'',$q);
            if(count($q)===3&&!array_diff(array_keys($q),['from','to','fmt'])&&MarketJson::date($q['from']??null)&&MarketJson::date($q['to']??null)&&$q['from']<=$q['to']&&$q['fmt']==='json')return 'eodhd';
        }
        if ($host==='eodhd.com' && preg_match('~^/api/exchange-symbol-list/(?:AS|PA|BR|XETRA)$~D',$path) && ($u['query']??null)==='fmt=json') return 'eodhd';
        if ($host==='robotapi-a4cjfeaygwg3cke0.westeurope-01.azurewebsites.net' && preg_match('~^/v1/(?:fund/[0-9]{1,6}/factsheetdata|fundrates)$~D',$path)) return 'european';
        if ($host==='mfs.deutsche-boerse.com' && preg_match('~^/api/(?:DETR-posttrade|download/DETR-posttrade-(?:daily-\d{4}-\d{2}-\d{2}|\d{4}-\d{2}-\d{2}T\d{2}_\d{2})\.json\.gz)$~D',$path)) return 'european';
        if ($host==='www.cashmarket.deutsche-boerse.com' && !isset($u['query']) && ($path==='/cash-en/trading/Tradable-Instruments-Xetra/xetra/3884!search'
            || preg_match('~^/resource/blob/1528/[a-f0-9]{32}/data/t7-xetr-allTradableInstruments\.csv$~D',$path))) return 'european';
        if ($host==='marketdata.euronext.com' && !isset($u['query']) && in_array($path,[
            '/data-reporting-service/trades-file/download/EQUITIES/CURRENT_TRADING_DAY/AMS',
            '/data-reporting-service/trades-file/download/EQUITIES/CURRENT_TRADING_DAY/PAR',
            '/data-reporting-service/trades-file/download/EQUITIES/CURRENT_TRADING_DAY/BRU',
            '/data-reporting-service/trades-file/download/ETFS_FUNDS/CURRENT_TRADING_DAY/AMS',
            '/data-reporting-service/trades-file/download/ETFS_FUNDS/CURRENT_TRADING_DAY/PAR',
            '/data-reporting-service/trades-file/download/EQUITIES/SINCE_PREVIOUS_TRADING_DAY/AMS',
            '/data-reporting-service/trades-file/download/EQUITIES/SINCE_PREVIOUS_TRADING_DAY/PAR',
            '/data-reporting-service/trades-file/download/EQUITIES/SINCE_PREVIOUS_TRADING_DAY/BRU',
            '/data-reporting-service/trades-file/download/ETFS_FUNDS/SINCE_PREVIOUS_TRADING_DAY/AMS',
            '/data-reporting-service/trades-file/download/ETFS_FUNDS/SINCE_PREVIOUS_TRADING_DAY/PAR'],true)) return 'european';
        throw new \RuntimeException('Provider URL refused.');
    }
    public static function xetraRedirect(string $original,string $location):bool
    {
        $a=parse_url($original);$b=parse_url($location);
        if(!$a||!$b||($a['host']??'')!=='mfs.deutsche-boerse.com'||!str_starts_with($a['path']??'','/api/download/')||($b['scheme']??'')!=='https'||($b['host']??'')!=='storage.googleapis.com'||isset($b['user'])||isset($b['pass'])||isset($b['port'])||isset($b['fragment']))return false;
        $name=basename($a['path']);
        if(!preg_match('/^DETR-posttrade-(?:daily-\d{4}-\d{2}-\d{2}|\d{4}-\d{2}-\d{2}T\d{2}_\d{2})\.json\.gz$/D',$name))return false;
        $expected='/mv-cef-prod-europe-west3-private-min-by-min-files/DETR/'.(str_starts_with($name,'DETR-posttrade-daily-')?'daily/':'').$name;
        return ($b['path']??null)===$expected;
    }
    public function get(string $url,int $maxBytes=8388608):string
    {
        $provider=self::provider($url);$original=$url;
        if($provider==='eodhd'&&(!is_string($this->keys['EODHD_API_KEY']??null)||$this->keys['EODHD_API_KEY']===''))throw new \RuntimeException('Action source is not configured.');
        if(!function_exists('curl_init')||$maxBytes<1||$maxBytes>33554432)throw new \RuntimeException('Provider request unavailable.');
        for($hop=0;$hop<2;$hop++){
            $remainingTime=$this->maxSeconds-(microtime(true)-$this->started);
            $limit=min($maxBytes,50331648-$this->bytes);
            if($this->calls>=$this->maxRequests||$limit<1||$remainingTime<1)throw new \RuntimeException('Provider request limit reached.');
            // Account for elapsed runtime so calls crossing an hour/day boundary
            // cannot be charged to the previous quota window.
            $requestTime=time();
            $this->store->reserveRequest($provider,$requestTime,$this->preparation);$this->calls++;
            // The Paris file service returns 406 when asked for JSON; its
            // documented download is a ZIP, unlike the JSON quote endpoints.
            $accept=str_starts_with($url,'https://marketdata.euronext.com/')?'application/zip':'application/json';
            $headers=['Accept: '.$accept,'User-Agent: RichYet-private/1'];
            if($provider==='alpaca'){$headers[]='APCA-API-KEY-ID: '.$this->keys['ALPACA_API_KEY_ID'];$headers[]='APCA-API-SECRET-KEY: '.$this->keys['ALPACA_API_SECRET_KEY'];}
            if($provider==='tickerlayer')$headers[]='x-api-key: '.$this->keys['TICKERLAYER_API_KEY'];
            $requestUrl=$url;
            if($provider==='eodhd'){
                $key=$this->keys['EODHD_API_KEY']??null;if(!is_string($key)||$key==='')throw new \RuntimeException('Action source is not configured.');
                $requestUrl.='&api_token='.rawurlencode($key);
            }
            $body='';$overflow=false;$location=null;$retryAfter=null;
            $curl=curl_init($requestUrl);
            curl_setopt_array($curl,[CURLOPT_HTTPHEADER=>$headers,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_PROXY=>'',CURLOPT_NETRC=>CURL_NETRC_IGNORED,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>min(15,(int)$remainingTime),CURLOPT_HEADER=>false,CURLOPT_RETURNTRANSFER=>false,
                CURLOPT_HEADERFUNCTION=>static function($curl,string $header)use(&$location,&$retryAfter):int{if(stripos($header,'Location:')===0)$location=trim(substr($header,9));if(stripos($header,'Retry-After:')===0){$v=trim(substr($header,12));$retryAfter=preg_match('/^[0-9]{1,8}$/D',$v)?min(86400,(int)$v):86400;}return strlen($header);},
                CURLOPT_WRITEFUNCTION=>static function($curl,string $chunk)use(&$body,&$overflow,$limit):int{if(strlen($body)+strlen($chunk)>$limit){$overflow=true;return 0;}$body.=$chunk;return strlen($chunk);}]);
            $ok=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);$transportCode=curl_errno($curl);unset($curl);$this->bytes+=strlen($body);$this->statuses[$provider.':'.(string)$status]=($this->statuses[$provider.':'.(string)$status]??0)+1;
            if($overflow)throw new \RuntimeException('Provider request unavailable.');
            if($ok===false)throw new ProviderHttpTransport($transportCode);
            if($status===200)return $body;
            // Only this observed public download handoff may redirect. No keys
            // are ever attached to either its original or storage request.
            if($hop===0&&$status===301&&$provider==='european'&&is_string($location)&&self::xetraRedirect($original,$location)){$url=$location;continue;}
            throw new ProviderHttpStatus($status,$retryAfter);
        }
        throw new \RuntimeException('Provider redirect refused.');
    }
}
