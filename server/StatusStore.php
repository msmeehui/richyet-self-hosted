<?php
declare(strict_types=1);
namespace RichYet;
require_once __DIR__.'/MarketStore.php';

/** Owner-only projection of retained operational evidence. SELECTs only. */
final class StatusStore
{
    private readonly ?string $ownerEmail;
    private readonly int $now;
    private const BUDGETS = [
        'alpaca'=>['Alpaca',3600,100], 'tickerlayer'=>['TickerLayer',86400,80],
        'european'=>['Public data downloads',3600,180], 'newton'=>['Newton Analytics',86400,120],
        'eodhd'=>['EODHD',86400,18],
    ];
    public function __construct(private readonly \PDO $db,?string $ownerEmail,bool $enabled=true,?int $now=null)
    {
        $this->now=$now??time();$canonical=null;
        if($enabled&&$ownerEmail!==null){try{$canonical=Backend::email($ownerEmail);}catch(\Throwable){}}
        $this->ownerEmail=$canonical;
    }
    private function query(string $sql,array $params=[]):\PDOStatement{$s=$this->db->prepare($sql);$s->execute($params);return $s;}
    public function canView(int $ownerId):bool
    {
        return $ownerId>0&&$this->ownerEmail!==null
            &&$this->query('SELECT email FROM users WHERE id = ?',[$ownerId])->fetchColumn()===$this->ownerEmail;
    }
    private static function instant(mixed $value):?string
    {
        if(is_int($value))return $value>0?gmdate('Y-m-d\TH:i:s\Z',$value):null;
        return is_string($value)&&str_contains($value,'T')&&MarketJson::instant($value)!==null?$value:null;
    }
    private static function date(mixed $value):?string{return MarketJson::date($value)?$value:null;}
    private static function text(mixed $value,int $limit=160):?string
    {
        return is_string($value)&&strlen($value)<=$limit&&!preg_match('/[\x00-\x1f\x7f]/',$value)?$value:null;
    }
    private static function check(string $id,string $label,mixed $attempt,mixed $success,mixed $next,string $note=''):array
    {
        return ['id'=>$id,'label'=>$label,'lastAttemptAt'=>self::instant($attempt),'lastSuccessAt'=>self::instant($success),'nextEligibleAt'=>self::instant($next),'note'=>$note];
    }
    private static function checks(array $i,array $q,array $state):array
    {
        $id=$i['id'];$provider=$i['provider']??null;$eu=$state['europe']??[];$us=$state['us']??[];$checks=[];
        if($provider==='alpaca'){
            $p=$us['priceChecks'][$id]??[];
            $checks[]=self::check('price','US prices',$p['attemptedAt']??null,$p['lastPriceSuccessAt']??null,$us['nextAttempt']??null,'The latest attempt is '.($p['outcome']??'not recorded').'. A successful retrieval is separate from the price observation time.');
            $checks[]=self::check('us-actions','US corporate actions',null,$us['corporateActionsCheckedAt']??null,null,'This is the last retained successful action check, not a price observation time.');
            $checks[]=self::check('fx','Currency conversion',null,null,$us['nextAttempt']??null,'Currency-bar dates describe the observation window; they do not establish the retrieval time.');
        }elseif($provider==='euronext'){
            $r=$eu['euronext'][$i['providerConfig']['tradingSchedule']??'']??[];
            $next=$r['nextAttempt']??(is_int($r['attemptAt']??null)?$r['attemptAt']+3600:null);
            $d=$r['downloadCheck']??[];$outcome=$d['outcome']??null;$detail='';
            if($outcome==='complete')$detail=($d['retries']??0)===1?' Last check recovered after one temporary request failure.':' Last download and archive check completed.';
            elseif(in_array($outcome,['failed','regressed'],true)){
                $failure=$d['failure']??[];$http=$failure['httpStatus']??null;$transport=$failure['transportCode']??null;
                $detail=' Last check failed';
                if(is_int($http)&&$http>=100&&$http<=599)$detail.=' (HTTP '.$http.')';
                elseif(is_int($transport)&&$transport>=0&&$transport<=999)$detail.=' (network code '.$transport.')';
                elseif(($failure['reason']??null)==='source_regressed')$detail.=' because the source returned older trade data';
                elseif(in_array($failure['stage']??null,['archive','csv'],true))$detail.=' while validating the downloaded archive';
                $detail.='.';
            }
            $checks[]=self::check('price','Euronext trade archive',$r['attemptAt']??null,$r['successAt']??null,$next,'Archive checks are shared by listings on the same route. A successful archive read does not guarantee a newer eligible trade.'.$detail);
        }elseif($provider==='bnd'){
            $r=$eu['bnd'][$id]??[];$next=$r['nextAttempt']??(is_int($r['at']??null)?$r['at']+3600:null);
            $checks[]=self::check('price','Fund NAV',$r['at']??null,null,$next,'The source retains its valuation date. A separate successful retrieval time is not recorded.');
        }elseif($provider==='xetra'){
            $r=$eu['xetra']??[];$c=$r['collection']??[];$attempt=$c['attemptedAt']??null;
            $success=($c['outcome']??null)==='complete'?$attempt:null;
            $checks[]=self::check('price','Xetra trade files',$attempt,$success,null,'The last attempt is a shared file collection. A complete collection is not a new trade. No exact next attempt is stored; collection follows the shared server schedule.');
            $checks[]=self::check('xetra-reference','Xetra reference check',$r['referenceAttemptAt']??null,null,null,'Reference attempts are retained separately; no successful reference timestamp is inferred.');
        }
        $h=$eu['history'][$id]??[];
        if($h)$checks[]=self::check('history','Closing-price history',$h['at']??null,$h['fetchedAt']??null,null,'History uses session-dependent eligibility. No exact next attempt is stored; a previous successful retrieval may precede a later failure.');
        $a=$eu['actions'][$id]??[];
        if($a)$checks[]=self::check('actions','European split check',$a['checkedAt']??null,$a['retrievedAt']??null,null,'This is the most recently retained interval check, not complete corporate-action coverage. Different intervals have separate expiry rules.');
        return $checks;
    }
    private function providers():array
    {
        $hour=intdiv($this->now,3600)*3600;$day=intdiv($this->now,86400)*86400;$rows=[];
        foreach($this->query('SELECT provider,window_start,calls FROM market_usage WHERE window_start >= ? AND window_start <= ? ORDER BY provider,window_start',[$day-86400,$this->now])->fetchAll(\PDO::FETCH_ASSOC) as $r){
            if(!isset(self::BUDGETS[$r['provider']??'']))continue;
            $window=(int)$r['window_start'];$calls=(int)$r['calls'];$period=self::BUDGETS[$r['provider']][1];
            if($window<0||$window%$period!==0||$calls<0)throw new \RuntimeException('Invalid usage evidence.');
            $rows[$r['provider']][$window]=$calls;
        }
        $out=[];
        foreach(self::BUDGETS as $id=>[$label,$period,$limit]){
            $start=intdiv($this->now,$period)*$period;$used=$rows[$id][$start]??0;$today=$previousDay=0;
            foreach($rows[$id]??[] as $window=>$count){if($window>=$day)$today+=$count;else $previousDay+=$count;}
            $published=match($id){
                'alpaca'=>['text'=>'Basic historical market data: 200 requests per minute. Remaining provider-minute capacity is not retained or checked here.','url'=>'https://docs.alpaca.markets/us/docs/about-market-data-api'],
                'tickerlayer'=>['text'=>'Free plan: 3,000 REST calls per 30-day cycle from signup, and 10 requests per second. The current provider cycle and remaining allowance are not checked here.','url'=>'https://tickerlayer.com/docs/limits'],
                'eodhd'=>['text'=>'Free plan: 20 weighted API calls per day, resetting at midnight GMT. Actual account balance and other use are not checked here.','url'=>'https://eodhd.com/financial-apis/api-limits'],
                default=>null,
            };
            $out[]=['id'=>$id,'label'=>$label,'periodSeconds'=>$period,'internalLimit'=>$limit,'windowStart'=>self::instant($start),'resetAt'=>self::instant($start+$period),
                'used'=>$used,'remaining'=>max(0,$limit-$used),'currentHourUsed'=>$period===3600?($rows[$id][$hour]??0):null,
                'previousHourUsed'=>$period===3600?($rows[$id][$hour-3600]??0):null,'todayUsed'=>$today,'previousDayUsed'=>$previousDay,'dayStart'=>self::instant($day),
                'providerAllowance'=>$published,'providerRemaining'=>null,
                'note'=>'RichYet ledger only; reserved attempts include failed requests and investment preparation. Remaining means room under the internal cap, not the provider account balance.'.($period===86400?' This daily ledger cannot identify usage within an individual hour.':' Today totals the recorded UTC-hour windows; there is no separate internal daily cap.')];
        }
        return $out;
    }
    public function read(int $ownerId):array
    {
        if(!$this->canView($ownerId))throw new HttpError(403,'owner_only','Status is available only to the primary account.');
        $started=!$this->db->inTransaction();if($started)$this->db->beginTransaction();
        try{
            $holdings=$this->query('SELECT holdings_json FROM portfolios WHERE owner_id = ?',[$ownerId])->fetchColumn();
            $row=$this->query('SELECT c.registry_json,c.snapshot_json,c.state_json,c.updated_at FROM market_catalogues c JOIN market_catalogue_accounts a ON a.catalogue_id=c.id WHERE a.owner_id = ?',[$ownerId])->fetch(\PDO::FETCH_ASSOC);
            if(!$row)$row=$this->query('SELECT registry_json,snapshot_json,state_json,updated_at FROM market_accounts WHERE owner_id = ?',[$ownerId])->fetch(\PDO::FETCH_ASSOC);
            if(!$row||!is_string($holdings))throw new HttpError(409,'live_unavailable','Market status has not been configured.');
            $held=json_decode($holdings,true,32,JSON_THROW_ON_ERROR);$registry=json_decode($row['registry_json'],true,32,JSON_THROW_ON_ERROR);
            $snapshot=json_decode($row['snapshot_json'],true,64,JSON_THROW_ON_ERROR);$state=json_decode($row['state_json'],true,64,JSON_THROW_ON_ERROR);
            if(!is_array($held)||!array_is_list($held)||count($held)>200||!is_array($registry)||count($registry)>200||!is_array($snapshot)||!is_array($state))throw new \RuntimeException('Invalid retained market state.');
            $byId=array_column($registry,null,'id');$items=[];$seen=[];
            foreach($held as $holding){
                $id=$holding['instrumentId']??null;if(!is_string($id)||!preg_match('/^[A-Za-z0-9_-]{1,60}$/D',$id)||isset($seen[$id])||!isset($byId[$id]))throw new \RuntimeException('Held listing missing.');$seen[$id]=true;
                $i=$byId[$id];$q=$snapshot['quotes'][$id]??[];$issues=[];
                // US daily bars are date-based. Their stored observedAt and
                // previousObservedAt are calendar session boundaries, not
                // independently supplied exchange observation timestamps.
                $us=($i['provider']??null)==='alpaca';$usClose=$us&&($q['priceKind']??null)==='close';
                foreach($snapshot['issues']??[] as $issue)if(($issue['instrumentId']??null)===$id&&in_array($issue['code']??null,MarketStore::ISSUES,true))$issues[]=$issue['code'];
                $items[]=['listingId'=>$id,'name'=>self::text($i['name']??null),'symbol'=>self::text($i['symbol']??null,30),'exchange'=>self::text($i['exchange']??null),'currency'=>in_array($i['currency']??null,['EUR','USD'],true)?$i['currency']:null,
                    'source'=>self::text($q['source']??null),'priceDate'=>self::date($q['date']??null),'observedAt'=>$usClose?null:self::instant($q['observedAt']??null),'publicationAt'=>self::instant($q['publicationAt']??null),'retrievedAt'=>self::instant($q['retrievedAt']??null),
                    'baselineDate'=>self::date($q['previousDate']??null),'baselineObservedAt'=>$us?null:self::instant($q['previousObservedAt']??null),
                    'scheduledSessionCloseAt'=>$usClose?self::instant($q['observedAt']??null):null,'baselineSessionCloseAt'=>$us?self::instant($q['previousObservedAt']??null):null,
                    'status'=>in_array($q['status']??null,['delayed','closed','nav','stale','missing'],true)?$q['status']:'missing',
                    'freshUntil'=>self::instant($q['freshUntil']??null),'freshUntilPassed'=>is_int($q['freshUntil']??null)&&$q['freshUntil']>0?$q['freshUntil']<=$this->now:null,
                    'sourceAvailable'=>is_bool($q['sourceAvailable']??null)?$q['sourceAvailable']:null,'priceAvailable'=>MarketJson::decimal($q['price']??null)!==null,'baselineAvailable'=>MarketJson::decimal($q['previousClose']??null)!==null,
                    'issues'=>array_values(array_unique($issues)),'checks'=>self::checks($i,$q,$state)];
            }
            $day=intdiv($this->now,86400)*86400;$paused=$this->query('SELECT calls FROM market_usage WHERE provider = ? AND window_start = ?',['restore_guard',$day])->fetchColumn()!==false;
            $result=['schemaVersion'=>1,'checkedAt'=>self::instant($this->now),'cacheUpdatedAt'=>self::instant((int)$row['updated_at']),
                'collectorLastAttemptAt'=>self::instant($snapshot['lastAttemptAt']??null),'collectorNextCheckAt'=>self::instant($snapshot['nextRefreshAt']??null),
                'collectionPausedUntil'=>$paused?self::instant($day+86400):null,
                'manualRefresh'=>['enabled'=>false,'reason'=>'Status reads saved information only. Price updates are scheduled on the server.'],
                'holdings'=>$items,'providers'=>$this->providers()];
            if($started)$this->db->commit();return $result;
        }catch(\Throwable $e){if($started&&$this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
