<?php
declare(strict_types=1);
namespace RichYet;

require_once __DIR__.'/ReportingFx.php';

/** Additive account settings and public-data receipts. No holdings are copied. */
final class ReportingStore
{
    private ?bool $available=null;
    public function __construct(private readonly \PDO $db){}
    private function query(string $sql,array $params=[]):\PDOStatement{$s=$this->db->prepare($sql);$s->execute($params);return $s;}
    private function mysql():bool{return $this->db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql';}
    public static function initialize(\PDO $db):void
    {
        if($db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql'){
            $db->exec('CREATE TABLE IF NOT EXISTS reporting_settings (owner_id BIGINT UNSIGNED NOT NULL PRIMARY KEY, currency CHAR(3) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, updated_at BIGINT NOT NULL, FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB');
            $db->exec('CREATE TABLE IF NOT EXISTS reporting_state (state_key VARCHAR(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY, value_json MEDIUMTEXT NOT NULL, updated_at BIGINT NOT NULL) ENGINE=InnoDB');
        }else{
            $db->exec('CREATE TABLE IF NOT EXISTS reporting_settings (owner_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE, currency TEXT NOT NULL, updated_at INTEGER NOT NULL)');
            $db->exec('CREATE TABLE IF NOT EXISTS reporting_state (state_key TEXT PRIMARY KEY, value_json TEXT NOT NULL, updated_at INTEGER NOT NULL)');
        }
    }
    public function available():bool
    {
        if($this->available!==null)return $this->available;
        try{$this->query('SELECT state_key FROM reporting_state WHERE 1=0');$this->query('SELECT owner_id FROM reporting_settings WHERE 1=0');return $this->available=true;}
        catch(\PDOException $e){if(in_array($e->getCode(),['42S02','HY000'],true)&&preg_match('/no such table|doesn.t exist/i',$e->getMessage()))return $this->available=false;throw $e;}
    }
    public function currency(int $ownerId):string
    {
        if(!$this->available())return 'EUR';$value=$this->query('SELECT currency FROM reporting_settings WHERE owner_id=?',[$ownerId])->fetchColumn();
        return in_array($value,['EUR','USD','GBP'],true)?$value:'EUR';
    }
    public function setCurrency(int $ownerId,string $currency,int $now):array
    {
        if(!in_array($currency,['EUR','USD','GBP'],true))throw new HttpError(422,'invalid_input','Choose EUR, USD or GBP.');
        if(!$this->available())throw new HttpError(503,'unavailable','Portfolio currency settings are not available yet.');
        $sql=$this->mysql()?'INSERT INTO reporting_settings(owner_id,currency,updated_at) VALUES(?,?,?) ON DUPLICATE KEY UPDATE currency=VALUES(currency),updated_at=VALUES(updated_at)':'INSERT INTO reporting_settings(owner_id,currency,updated_at) VALUES(?,?,?) ON CONFLICT(owner_id) DO UPDATE SET currency=excluded.currency,updated_at=excluded.updated_at';
        $this->query($sql,[$ownerId,$currency,$now]);return ['currency'=>$currency];
    }
    public function numberFormat(int $ownerId):string
    {
        if(!$this->available())return 'nl-NL';
        $value=$this->state('account:'.$ownerId.':number-format')['numberFormat']??null;
        return in_array($value,['nl-NL','en-GB'],true)?$value:'nl-NL';
    }
    public function setNumberFormat(int $ownerId,string $numberFormat,int $now):array
    {
        if(!in_array($numberFormat,['nl-NL','en-GB'],true))throw new HttpError(422,'invalid_input','Choose Dutch or English number formatting.');
        if(!$this->available())throw new HttpError(503,'unavailable','Number format settings are not available yet.');
        // The authenticated owner is supplied by Backend, never by the request.
        // This namespaced setting shares the existing backup-covered state table.
        if($ownerId<1||$this->query('SELECT id FROM users WHERE id=?',[$ownerId])->fetchColumn()===false)throw new HttpError(422,'invalid_input','A saved account is required.');
        $this->save('account:'.$ownerId.':number-format',['numberFormat'=>$numberFormat],$now);
        return ['numberFormat'=>$numberFormat];
    }
    public function preferences(int $ownerId):array
    {
        return ['currency'=>$this->currency($ownerId),'numberFormat'=>$this->numberFormat($ownerId)];
    }
    private function state(string $key):?array
    {
        $raw=$this->query('SELECT value_json FROM reporting_state WHERE state_key=?',[$key])->fetchColumn();if($raw===false)return null;
        try{$value=json_decode($raw,true,32,JSON_THROW_ON_ERROR);return is_array($value)?$value:null;}catch(\JsonException){return null;}
    }
    private function save(string $key,array $value,int $now):void
    {
        $sql=$this->mysql()?'INSERT INTO reporting_state(state_key,value_json,updated_at) VALUES(?,?,?) ON DUPLICATE KEY UPDATE value_json=VALUES(value_json),updated_at=VALUES(updated_at)':'INSERT INTO reporting_state(state_key,value_json,updated_at) VALUES(?,?,?) ON CONFLICT(state_key) DO UPDATE SET value_json=excluded.value_json,updated_at=excluded.updated_at';
        $this->query($sql,[$key,json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),$now]);
    }
    public function fx(int $now):array
    {
        $missing=['series'=>ReportingFx::SERIES,'base'=>'EUR','current'=>null,'history'=>[],'fetchedAt'=>null,'freshUntil'=>null,'status'=>'missing'];
        if(!$this->available())return $missing;$value=$this->state('fx')??[];$data=$value['data']??null;
        if(!is_array($data)||($data['series']??null)!==ReportingFx::SERIES||($data['base']??null)!=='EUR'||!is_array($data['current']??null)||!is_array($data['history']??null)||MarketJson::instant($data['fetchedAt']??null)===null||MarketJson::instant($data['freshUntil']??null)===null)return $missing;
        $data['status']=strtotime($data['freshUntil'])>$now?'ready':'stale';return $data;
    }
    /** Only the ordinary CLI collector invokes this. Shared limit: four HTTP
     * attempts per UTC day, with persisted reservation and at least one hour backoff. */
    public function collectFx(int $now,?callable $download=null):array
    {
        if(!$this->available())return ['requested'=>false,'status'=>'not_initialized'];
        if($this->db->inTransaction())throw new \RuntimeException('Reference rate reservation requires its own transaction.');
        $this->db->beginTransaction();
        try{
            // Seed and lock one row to share the same allowance across accounts and workers.
            $sql=$this->mysql()?'INSERT IGNORE INTO reporting_state(state_key,value_json,updated_at) VALUES(?,?,?)':'INSERT OR IGNORE INTO reporting_state(state_key,value_json,updated_at) VALUES(?,?,?)';$this->query($sql,['fx','{}',$now]);
            $raw=$this->query('SELECT value_json FROM reporting_state WHERE state_key=?'.($this->mysql()?' FOR UPDATE':''),['fx'])->fetchColumn();$state=json_decode($raw,true,32,JSON_THROW_ON_ERROR);
            $day=gmdate('Y-m-d',$now);$attempts=($state['attemptDay']??null)===$day?(int)($state['attempts']??0):0;
            if(($state['nextAttemptAt']??0)>$now||$attempts>=4){$this->db->commit();return ['requested'=>false,'status'=>'cached'];}
            // Honor the existing restore guard rather than creating a budget bypass.
            if($this->query('SELECT calls FROM market_usage WHERE provider=? AND window_start=?',['restore_guard',intdiv($now,86400)*86400])->fetchColumn()!==false){$this->db->commit();return ['requested'=>false,'status'=>'restore_guard'];}
            $state['attemptDay']=$day;$state['attempts']=$attempts+1;$state['lastAttemptAt']=$now;$state['nextAttemptAt']=$now+3600;
            $this->save('fx',$state,$now);$this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
        try{
            $data=ReportingFx::parse($download!==null?$download():ReportingFx::download(),$now);
            $old=$state['data']['current']['date']??null;if(is_string($old)&&$data['current']['date']<$old)throw new \RuntimeException('Reference rate history regressed.');
            $state['data']=$data;$state['lastSuccessAt']=$now;$state['lastAttemptFailed']=false;
            $local=(new \DateTimeImmutable('@'.$now))->setTimezone(new \DateTimeZone('Europe/Amsterdam'));
            if($data['current']['date']===$local->format('Y-m-d')||!ReportingFx::businessDay($local)||$local->format('H:i')<'16:10'){
                $next=$local->setTime(16,10);if($next->getTimestamp()<=$now||!ReportingFx::businessDay($next))do{$next=$next->modify('+1 day');}while(!ReportingFx::businessDay($next));
                $state['nextAttemptAt']=max($now+3600,$next->getTimestamp());
            }
            $this->save('fx',$state,$now);return ['requested'=>true,'status'=>$data['status']];
        }catch(\Throwable){$state['lastAttemptFailed']=true;$this->save('fx',$state,$now);return ['requested'=>true,'status'=>'failed'];}
    }
    private static function identity(array $i):string
    {
        return hash('sha256',json_encode([$i['id']??null,$i['symbol']??null,$i['isin']??null,$i['mic']??null,$i['currency']??null,$i['type']??null,$i['provider']??null,$i['providerConfig']['symbol']??null,$i['providerConfig']['fundId']??null,$i['providerConfig']['venue']??null,$i['providerConfig']['publicationVenue']??null],JSON_THROW_ON_ERROR));
    }
    private static function observation(array $i,array $q,int $now,bool $watermark=false):?array
    {
        if(($i['providerConfig']['identityConfirmed']??false)!==true||($q['listingId']??null)!==($i['id']??null)||($q['currency']??null)!==($i['currency']??null)||MarketJson::decimal($q['price']??null)===null||!MarketJson::date($q['date']??null)||(!$watermark&&(($q['sourceAvailable']??true)!==true||in_array($q['status']??null,['missing','stale'],true))))return null;
        foreach(['mic','isin'] as $key)if(isset($q[$key])&&isset($i[$key])&&$q[$key]!==$i[$key])return null;
        $invalidations=$q['invalidations']??[];if(!is_array($invalidations)||array_intersect($invalidations,['listing','current']))return null;
        $today=(new \DateTimeImmutable('@'.$now))->setTimezone(new \DateTimeZone($i['timeZone']??'Europe/Amsterdam'))->format('Y-m-d');if($q['date']>$today)return null;
        if(($i['type']??null)==='Fund')return ['date'=>$q['date'],'observedAt'=>null,'order'=>$q['date']];
        $instant=MarketJson::instant($q['observedAt']??null);
        if($instant===null){
            // Some accepted daily closing feeds publish only a valuation date.
            // A newer date counts, but an undated same-day correction does not.
            if(($q['observedAt']??null)!==null||($q['priceKind']??null)!=='close')return null;
            return ['date'=>$q['date'],'observedAt'=>null,'order'=>$q['date'].'|0000000000|000000000000'];
        }
        if($instant>$now)return null;
        // Preserve sub-second ordering so a newer observation with the same price counts.
        $dt=new \DateTimeImmutable($q['observedAt']);$fraction=preg_match('/\.(\d+)/',$q['observedAt'],$m)?substr(str_pad($m[1],12,'0'),0,12):str_repeat('0',12);
        return ['date'=>$q['date'],'observedAt'=>$q['observedAt'],'order'=>$q['date'].'|'.$dt->format('U').'|'.$fraction];
    }
    /** Called only after an accepted snapshot is durably saved. Existing quotes
     * seed a watermark without inventing their historic receipt time. */
    public function recordUpdates(array $registry,array $oldQuotes,array $quotes,int $now):void
    {
        if(!$this->available())return;
        foreach($registry as $i){$id=$i['id'];$key='receipt:'.self::identity($i);$ledger=$this->state($key);$old=self::observation($i,$oldQuotes[$id]??[],$now,true);$new=self::observation($i,$quotes[$id]??[],$now);
            if($old!==null&&(!is_array($ledger)||($ledger['observation']['order']??'')<$old['order']))$ledger=['observation'=>$old,'receivedAt'=>null];
            if($new!==null&&(!is_array($ledger)||($ledger['observation']['order']??'')<$new['order']))$ledger=['observation'=>$new,'receivedAt'=>gmdate('Y-m-d\TH:i:s\Z',$now)];
            if(is_array($ledger))$this->save($key,$ledger,$now);
        }
    }
    public function updates(array $registry,array $quotes,int $now):object
    {
        $updates=[];if(!$this->available())return (object)$updates;
        // A later source outage does not erase the time this exact retained
        // observation originally arrived. It must not create or advance it.
        foreach($registry as $i){$id=$i['id'];$q=self::observation($i,$quotes[$id]??[],$now,true);$ledger=$this->state('receipt:'.self::identity($i));$received=MarketJson::instant($ledger['receivedAt']??null);
            if($q!==null&&is_array($ledger)&&($ledger['observation']['order']??null)===$q['order']&&$received!==null&&$received<=$now)$updates[$id]=['receivedAt'=>$ledger['receivedAt'],'observationDate'=>$q['date'],'observedAt'=>$q['observedAt']];
        }return (object)$updates;
    }
}
