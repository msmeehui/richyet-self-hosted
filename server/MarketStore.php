<?php
declare(strict_types=1);
namespace RichYet;

require_once __DIR__ . '/MarketJson.php';
require_once __DIR__ . '/XetraReference.php';
require_once __DIR__ . '/CatalogueStore.php';
require_once __DIR__ . '/EuropeanActions.php';
require_once __DIR__ . '/FundContribution.php';
require_once __DIR__ . '/ReportingStore.php';
require_once __DIR__ . '/LastValidMarket.php';

/** Owner-bound registry and atomic cached snapshots. No network runs here. */
final class MarketStore
{
    public const ISSUES = ['price_missing','baseline_missing','fx_missing','baseline_fx_missing','source_unavailable','price_stale','fx_stale','corporate_action_unverified','listing_unverified','source_unsupported','calendar_unverified','history_source_unavailable','history_invalid','history_request_limit','history_price_missing','history_baseline_missing','history_basis_unverified','history_price_stale'];
    public function __construct(private readonly \PDO $db, private readonly ?string $ownerEmail, private readonly ?int $clock = null) {}
    public function ownerEmail(): ?string { return $this->ownerEmail; }
    public function reporting(): ReportingStore { return new ReportingStore($this->db); }
    private function query(string $sql, array $params = []): \PDOStatement { $s=$this->db->prepare($sql); $s->execute($params); return $s; }
    public function catalogues(): CatalogueStore { return new CatalogueStore($this->db,$this->ownerEmail); }
    public function preparation(callable $get,?int $now=null):InstrumentPreparation
    {
        require_once __DIR__.'/InstrumentPreparation.php';
        return new InstrumentPreparation($this->db,$this->ownerEmail,$get,$now);
    }
    public function account(int $ownerId): ?array
    {
        $catalogue=$this->catalogues()->account($ownerId);
        if($catalogue!==null)return $catalogue;
        $row=$this->query('SELECT m.*, u.email FROM market_accounts m JOIN users u ON u.id = m.owner_id WHERE m.owner_id = ?', [$ownerId])->fetch();
        if (!$row) return null;
        if ($this->ownerEmail === null || Backend::email($this->ownerEmail) !== $row['email']) throw new HttpError(403,'owner_only','Live market data is restricted to its configured owner.');
        return $row;
    }
    public function mode(int $ownerId): string { return $this->account($ownerId) === null ? 'fictional' : 'live'; }
    public function registry(int $ownerId): array
    {
        $row=$this->account($ownerId);
        if (!$row) throw new HttpError(409,'live_unavailable','Live investments have not been configured.');
        return self::validateRegistry(json_decode($row['registry_json'],true,32,JSON_THROW_ON_ERROR));
    }
    public static function validateRegistry(mixed $instruments): array
    {
        if (!is_array($instruments) || !array_is_list($instruments) || count($instruments)<1 || count($instruments)>200) throw new \RuntimeException('Invalid private instrument registry.');
        $seen=[];
        foreach ($instruments as $instrument) {
            if (!is_array($instrument)) throw new \RuntimeException('Invalid instrument metadata.');
            $keys=['id','name','symbol','exchange','mic','currency','type','isin','coverage','provider','providerConfig','monogram','tone','timeZone'];
            if (array_diff(array_keys($instrument),$keys)) throw new \RuntimeException('Unexpected registry fields.');
            foreach (['id','name','symbol','exchange','currency','type','provider'] as $key) {
                if (!is_string($instrument[$key]??null) || strlen($instrument[$key])>160 || preg_match('/[\x00-\x1f\x7f]/',$instrument[$key])) throw new \RuntimeException('Invalid registry text.');
            }
            $id=$instrument['id'];
            if (!preg_match('/^[A-Za-z0-9_-]{1,60}$/D',$id) || isset($seen[$id]) || in_array($id,Backend::FICTIONAL_IDS,true)) throw new \RuntimeException('Live identities must be unique and distinct from fictional identities.');
            $seen[$id]=true;
            if (!in_array($instrument['currency'],['EUR','USD'],true) || !in_array($instrument['type'],['Stock','ETF','Fund'],true)
                || !in_array($instrument['provider'],['alpaca','bnd','xetra','euronext','unsupported'],true)
                || !(($instrument['type']==='Fund'&&array_key_exists('mic',$instrument)&&$instrument['mic']===null)||(is_string($instrument['mic']??null)&&preg_match('/^[A-Z0-9]{4}$/D',$instrument['mic'])))) throw new \RuntimeException('Unsupported instrument identity.');
            if (isset($instrument['isin']) && (!is_string($instrument['isin']) || !preg_match('/^[A-Z]{2}[A-Z0-9]{9}[0-9]$/D',$instrument['isin']))) throw new \RuntimeException('Invalid ISIN.');
            if (!is_array($instrument['coverage']??null) || (count($instrument['coverage'])!==2 || array_diff(array_keys($instrument['coverage']),['price','comparison'])) || !is_bool($instrument['coverage']['price']) || !is_bool($instrument['coverage']['comparison'])) throw new \RuntimeException('Invalid source coverage.');
            $p=$instrument['providerConfig']??null;
            if (!is_array($p) || array_diff(array_keys($p),['symbol','identityConfirmed','fundId','venue','publicationVenue','tradingSchedule','historyProvider','historySymbol','historyIdentityConfirmed','historyIdentityRule','actionsProvider','actionsSymbol','actionsIdentityConfirmed'])) throw new \RuntimeException('Invalid provider configuration.');
            $pendingHistory=($p['historyIdentityRule']??null)==='xetra_auction_match'&&$instrument['provider']==='xetra'&&$instrument['mic']==='XETR'&&($p['historyProvider']??null)==='newton'&&($p['historyIdentityConfirmed']??null)===false;
            if(isset($p['historyIdentityRule'])&&!$pendingHistory)throw new \RuntimeException('Unsupported history identity rule.');
            if(isset($instrument['timeZone'])&&(!is_string($instrument['timeZone'])||!in_array($instrument['timeZone'],\DateTimeZone::listIdentifiers(),true)))throw new \RuntimeException('Invalid listing time zone.');
            if(isset($p['historyProvider'])||isset($p['historySymbol'])||isset($p['historyIdentityConfirmed'])){
                if(!in_array($p['historyProvider']??null,['newton','eodhd'],true)||!is_string($p['historySymbol']??null)||!preg_match('/^[A-Z0-9.-]{1,30}$/D',$p['historySymbol'])||(($p['historyIdentityConfirmed']??null)!==true&&!$pendingHistory)||!in_array($instrument['provider'],['xetra','euronext'],true))throw new \RuntimeException('Invalid reviewed history configuration.');
            }
            if(isset($p['actionsProvider'])||isset($p['actionsSymbol'])||isset($p['actionsIdentityConfirmed'])){
                if(($p['actionsProvider']??null)!=='eodhd'||!is_string($p['actionsSymbol']??null)||!preg_match('/^[A-Z0-9.-]{1,30}$/D',$p['actionsSymbol'])||($p['actionsIdentityConfirmed']??null)!==true||!in_array($instrument['provider'],['xetra','euronext'],true))throw new \RuntimeException('Invalid reviewed action configuration.');
            }
            if (!is_bool($p['identityConfirmed']??null)) throw new \RuntimeException('Listing confirmation must be explicit.');
            if (isset($p['symbol']) && (!is_string($p['symbol']) || !preg_match('/^[A-Z0-9.-]{1,20}$/D',$p['symbol']))) throw new \RuntimeException('Invalid provider symbol.');
            if (isset($p['fundId']) && (!is_int($p['fundId']) || $p['fundId']<1 || $p['fundId']>999999)) throw new \RuntimeException('Invalid fund identity.');
            foreach (['venue','publicationVenue'] as $key) if (isset($p[$key]) && (!is_string($p[$key]) || !preg_match('/^[A-Z0-9]{4}$/D',$p[$key]))) throw new \RuntimeException('Invalid venue.');
            if(array_key_exists('tradingSchedule',$p)) self::validateTradingSchedule($instrument,$p['tradingSchedule']);
            if (($instrument['provider']==='alpaca' && ($instrument['currency']!=='USD' || !isset($p['symbol'])))
                || (in_array($instrument['provider'],['bnd','xetra','euronext'],true) && ($instrument['currency']!=='EUR' || !isset($instrument['isin'])))) throw new \RuntimeException('Provider identity is incomplete.');
            foreach (['monogram','tone'] as $key) if (isset($instrument[$key]) && (!is_string($instrument[$key]) || !preg_match('/^[A-Za-z0-9_-]{1,20}$/D',$instrument[$key]))) throw new \RuntimeException('Invalid display metadata.');
        }
        return $instruments;
    }
    private static function validateTradingSchedule(array $instrument,mixed $schedule):void
    {
        $matches=($schedule==='ams_j0_continuous_2026' && $instrument['mic']==='XAMS' && $instrument['type']==='Stock')
            || ($schedule==='bru_a0_continuous_2026' && $instrument['mic']==='XBRU' && $instrument['type']==='Stock')
            || ($schedule==='par_eur_etf_continuous_2026' && $instrument['mic']==='XPAR' && $instrument['type']==='ETF')
            || ($schedule==='ams_eur_etf_continuous_2026' && $instrument['mic']==='XAMS' && $instrument['type']==='ETF')
            || ($schedule==='par_stock_continuous_2026' && $instrument['mic']==='XPAR' && $instrument['type']==='Stock');
        if($instrument['provider']!=='euronext' || $instrument['currency']!=='EUR' || !$matches) throw new \RuntimeException('Unsupported reviewed trading schedule.');
    }
    public static function metadata(array $registry): array
    {
        return array_map(static function(array $i):array {
            // Display-only calendar identity. Never expose the private source
            // configuration or turn a scheduled close into an observation.
            $p=$i['providerConfig']??[];$schedule=null;
            if(($p['identityConfirmed']??false)===true){
                if(($i['provider']??null)==='euronext'&&isset($p['tradingSchedule'])){
                    try{self::validateTradingSchedule($i,$p['tradingSchedule']);$schedule=$p['tradingSchedule'];}catch(\RuntimeException){}
                }elseif(($i['provider']??null)==='xetra'&&($i['currency']??null)==='EUR'&&($i['type']??null)==='ETF'
                    &&(($i['mic']??null)==='XETR'||(($i['mic']??null)==='XETA'&&($p['venue']??null)==='XETA'&&($p['publicationVenue']??null)==='XCEF'))){
                    $schedule='xetra_etf_regular_2026';
                }elseif(($i['provider']??null)==='alpaca'&&($i['currency']??null)==='USD'&&in_array($i['type']??null,['Stock','ETF'],true)){
                    $schedule='us_retained_calendar';
                }
            }
            $fundSchedule=FundContribution::identity($i)!==null;
            unset($i['provider'],$i['providerConfig'],$i['closeSchedule'],$i['contributionSchedule']);
            if($schedule!==null)$i['closeSchedule']=$schedule;
            if($fundSchedule)$i['contributionSchedule']=FundContribution::SCHEDULE;
            return $i;
        },$registry);
    }
    public static function missing(): array
    {
        return ['price'=>null,'previousClose'=>null,'date'=>null,'previousDate'=>null,'observedAt'=>null,'previousObservedAt'=>null,'fx'=>null,'previousFx'=>null,'status'=>'missing','source'=>null];
    }
    public function market(int $ownerId): array
    {
        $row=$this->account($ownerId);
        if (!$row) throw new HttpError(409,'live_unavailable','Live investments have not been configured.');
        return $this->snapshot($row,self::validateRegistry(json_decode($row['registry_json'],true,32,JSON_THROW_ON_ERROR)));
    }
    private function snapshot(array $row,array $registry):array
    {
        $snapshot=json_decode($row['snapshot_json'],true,64,JSON_THROW_ON_ERROR);
        $now=$this->clock??time();
        if ($snapshot['generatedAt']!==null && (strtotime($snapshot['generatedAt'])?:0)<$now-7200) {
            foreach ($snapshot['quotes'] as $id=>&$q) if ($q['price']!==null) { $q['status']='stale'; $snapshot['issues'][]=['instrumentId'=>$id,'code'=>'source_unavailable']; }
            unset($q);
        }
        $state=json_decode($row['state_json'],true,64,JSON_THROW_ON_ERROR);
        foreach($registry as $instrument){$id=$instrument['id'];$currentFx=$state['us']['currentFx']??null;if($instrument['currency']==='USD'&&$currentFx!==null&&!USProviders::fxWindowAllowed($currentFx['start'],$currentFx['end'])){$snapshot['quotes'][$id]['fx']=null;$snapshot['issues'][]=['instrumentId'=>$id,'code'=>'fx_missing'];}if($instrument['provider']==='alpaca'&&(USProviders::oldBasisUnsafe($state['us']['corporateActions']??[],$snapshot['quotes'][$id]['date'],$instrument['providerConfig']['symbol'],$now)||($snapshot['quotes'][$id]['date']!==null&&$snapshot['quotes'][$id]['date']<=($state['us']['basisInvalidThrough'][$id]??'')))){
            $snapshot['quotes'][$id]=array_replace($snapshot['quotes'][$id],['price'=>null,'previousClose'=>null,'status'=>'missing']);$snapshot['issues'][]=['instrumentId'=>$id,'code'=>'corporate_action_unverified'];
        }if($instrument['provider']==='xetra' && XetraReference::oldBasisUnsafe($state['europe']['xetra']??[],$id,$snapshot['quotes'][$id]['date']??null,$now)){
            $snapshot['quotes'][$id]=array_replace($snapshot['quotes'][$id],['price'=>null,'previousClose'=>null,'status'=>'missing']);$snapshot['issues'][]=['instrumentId'=>$id,'code'=>'corporate_action_unverified'];
        }}
        foreach($registry as $i){$id=$i['id'];$snapshot['quotes'][$id]=self::applyInvalidations($snapshot['quotes'][$id]);
            if(in_array($i['provider'],['euronext','xetra'],true)&&EuropeanActions::oldBasisUnsafe($state['europe']['actions']??[],$id,$snapshot['quotes'][$id]['date']??null,$now)){$snapshot['quotes'][$id]=array_replace($snapshot['quotes'][$id],['price'=>null,'previousClose'=>null,'status'=>'missing']);$snapshot['issues'][]=['instrumentId'=>$id,'code'=>'corporate_action_unverified'];}
        }
        // Quote and FX proofs can expire independently of a successful collector tick.
        foreach($registry as $i){$id=$i['id'];$q=&$snapshot['quotes'][$id];
            if($q['price']!==null&&isset($q['freshUntil'])&&$q['freshUntil']<=$now){$q['status']='stale';$snapshot['issues'][]=['instrumentId'=>$id,'code'=>'price_stale'];}
            if($i['currency']==='USD'&&$q['fx']!==null&&isset($q['fxFreshUntil'])&&$q['fxFreshUntil']<=$now)$snapshot['issues'][]=['instrumentId'=>$id,'code'=>'fx_stale'];
            unset($q);
        }
        $issues=[];foreach($snapshot['issues'] as $issue)$issues[$issue['instrumentId'].':'.$issue['code']]=$issue;$snapshot['issues']=array_values($issues);
        $retained=LastValidMarket::reconcile($registry,$snapshot,$state,$now);$snapshot=$retained['snapshot'];
        $sessionCalendar=USProviders::publicCalendar($state['us']['sessionCalendar']??null,$now);
        foreach($registry as $i){$id=$i['id'];
            if($i['provider']==='alpaca'&&$sessionCalendar!==null)$snapshot['quotes'][$id]['sessionCalendar']=$sessionCalendar;
            if(FundContribution::identity($i)!==null)$snapshot['quotes'][$id]['fundContribution']=FundContribution::receipt($i,$snapshot['quotes'][$id],$state['europe']['bnd'][$id]['contribution']??null);
        }
        $reporting=$this->reporting();
        $snapshot['reportingFx']=$reporting->fx($now);
        $snapshot['priceUpdates']=$reporting->updates($registry,$snapshot['quotes'],$now);
        $snapshot['quotes']=(object)$snapshot['quotes'];
        return $snapshot;
    }
    public static function applyInvalidations(array $quote):array
    {
        $invalidations=$quote['invalidations']??[];
        if(!is_array($invalidations))$invalidations=['current'];
        if(array_intersect($invalidations,['listing','current'])){$quote['price']=null;$quote['previousClose']=null;$quote['previousFx']=null;$quote['status']='missing';}
        elseif(in_array('comparison',$invalidations,true)){$quote['previousClose']=null;$quote['previousFx']=null;}
        return $quote;
    }
    /** One row read binds readiness to the exact market snapshot returned with it. */
    public function catalogue(int $ownerId):array
    {
        $row=$this->account($ownerId);
        if(!$row)throw new HttpError(409,'live_unavailable','Live investments have not been configured.');
        return $this->describeCatalogue($row);
    }
    /** Trusted operator/attachment preflight; routes obtain the row through account(). */
    public function describeCatalogue(array $row):array
    {
        $registry=self::validateRegistry(json_decode($row['registry_json'],true,32,JSON_THROW_ON_ERROR));
        $market=$this->snapshot($row,$registry);$metadata=self::metadata($registry);
        if(isset($row['catalogue_id'])||isset($row['policy_json'])){
            $policies=CatalogueStore::policies(json_decode($row['policy_json'],true,32,JSON_THROW_ON_ERROR),$registry,$row['audience']);
            foreach($registry as $n=>$i){
                $listing=$i;$listing['identityConfirmed']=$i['providerConfig']['identityConfirmed'];
                $quote=(array)$market['quotes'];$q=$quote[$i['id']]??null;
                // A stale retained estimate may still be displayed, but is not admitted for a new holding.
                if($q!==null&&$q['status']==='stale')$q['freshUntil']=0;
                $a=MarketAdmission::evaluate($listing,$policies[$i['id']],$q,$row['audience'],$this->clock??time());
                $metadata[$n]['admission']=$a;$metadata[$n]['canAdd']=$a['canAdd'];
            }
        }
        return ['dataMode'=>'live','instruments'=>$metadata,'market'=>$market];
    }
    /** Called within the portfolio transaction; only new IDs need admission. */
    public function assertAdditions(int $ownerId,array $old,array $next):void
    {
        if($this->catalogues()->account($ownerId,true)===null)return;
        $oldIds=array_map(static fn($h)=>$h->instrumentId,$old);
        $newIds=array_diff(array_map(static fn($h)=>$h->instrumentId,$next),$oldIds);
        if(!$newIds)return;
        $catalogue=$this->catalogue($ownerId);$byId=array_column($catalogue['instruments'],null,'id');
        foreach($newIds as $id)if(($byId[$id]['admission']['canAdd']??false)!==true)throw new HttpError(422,'instrument_unavailable','This investment cannot be added until its price and comparison are ready. Your saved portfolio has not changed.');
    }
    public static function emptySnapshot(array $registry): array
    {
        $quotes=[];$issues=[];
        foreach ($registry as $i) { $quotes[$i['id']]=self::missing(); $issues[]=['instrumentId'=>$i['id'],'code'=>'price_missing']; }
        return ['dataMode'=>'live','snapshotId'=>bin2hex(random_bytes(16)),'generatedAt'=>null,'lastAttemptAt'=>null,'nextRefreshAt'=>null,'quotes'=>$quotes,'issues'=>$issues];
    }
    public static function validateSnapshot(mixed $snapshot,array $registry):array
    {
        if (!is_array($snapshot) || ($snapshot['dataMode']??null)!=='live' || !is_string($snapshot['snapshotId']??null) || !preg_match('/^[a-f0-9]{32}$/D',$snapshot['snapshotId']) || !is_array($snapshot['quotes']??null) || !is_array($snapshot['issues']??null)) throw new \RuntimeException('Invalid market snapshot.');
        foreach (['generatedAt','lastAttemptAt','nextRefreshAt'] as $field) if (!array_key_exists($field,$snapshot) || ($snapshot[$field]!==null && MarketJson::instant($snapshot[$field])===null)) throw new \RuntimeException('Invalid market time.');
        $ids=array_column($registry,'id');
        if (array_diff(array_keys($snapshot['quotes']),$ids) || array_diff($ids,array_keys($snapshot['quotes']))) throw new \RuntimeException('Incomplete market snapshot.');
        foreach ($snapshot['quotes'] as $quote) {
            $allowed=['price','previousClose','date','previousDate','observedAt','previousObservedAt','fx','previousFx','status','source','feed','currency','observationKind','baselineClose','fxObservedAt','fxBarEnd','previousFxObservedAt','issues','eligibilityIssue','listingId','mic','isin','priceKind','previousKind','expectedPreviousDate','basis','freshUntil','invalidations','sourceAvailable','fxFreshUntil','fxSeries','previousFxSeries','fxBaselineDate','fxBaselineVerified','comparisonSource','publicationAt','retrievedAt','sourceWindow'];
            if(is_array($quote)&&array_diff(array_keys($quote),$allowed))throw new \RuntimeException('Unexpected quote fields.');
            foreach(['freshUntil','fxFreshUntil'] as $field)if(isset($quote[$field])&&(!is_int($quote[$field])||$quote[$field]<0))throw new \RuntimeException('Invalid quote freshness proof.');
            foreach(['sourceAvailable','fxBaselineVerified'] as $field)if(isset($quote[$field])&&!is_bool($quote[$field]))throw new \RuntimeException('Invalid quote proof flag.');
            foreach(['listingId','mic','isin','priceKind','previousKind','basis','fxSeries','previousFxSeries','comparisonSource','sourceWindow'] as $field)if(isset($quote[$field])&&(!is_string($quote[$field])||strlen($quote[$field])>160||preg_match('/[\\x00-\\x1f\\x7f]/',$quote[$field])))throw new \RuntimeException('Invalid quote proof text.');
            foreach(['expectedPreviousDate','fxBaselineDate'] as $field)if(isset($quote[$field])&&!MarketJson::date($quote[$field]))throw new \RuntimeException('Invalid quote proof date.');
            if(isset($quote['invalidations'])&&(!is_array($quote['invalidations'])||!array_is_list($quote['invalidations'])||array_diff($quote['invalidations'],['listing','current','comparison'])))throw new \RuntimeException('Invalid quote invalidations.');
            if (!is_array($quote) || !in_array($quote['status']??null,['delayed','closed','nav','stale','missing'],true)) throw new \RuntimeException('Invalid quote status.');
            foreach (['price','previousClose'] as $key) if (!array_key_exists($key,$quote) || ($quote[$key]!==null && MarketJson::decimal($quote[$key])===null)) throw new \RuntimeException('Invalid quote decimal.');
            foreach (['fx','previousFx'] as $key) {
                $v=$quote[$key]??null;
                if ($v!==null && (is_array($v) ? ((count($v)!==2 || array_diff(array_keys($v),['numerator','denominator'])) || MarketJson::decimal($v['numerator'])===null || MarketJson::decimal($v['denominator'])===null) : MarketJson::decimal($v)===null)) throw new \RuntimeException('Invalid FX decimal.');
            }
            foreach (['date','previousDate'] as $key) if (($quote[$key]??null)!==null && !MarketJson::date($quote[$key])) throw new \RuntimeException('Invalid valuation date.');
            foreach (['observedAt','previousObservedAt','publicationAt','retrievedAt'] as $key) if (($quote[$key]??null)!==null && MarketJson::instant($quote[$key])===null) throw new \RuntimeException('Invalid price time.');
        }
        foreach ($snapshot['issues'] as $issue) if (!is_array($issue) || !in_array($issue['instrumentId']??null,$ids,true) || !in_array($issue['code']??null,self::ISSUES,true)) throw new \RuntimeException('Invalid market issue.');
        return $snapshot;
    }
    /** Import only after a private backup captured this exact portfolio revision. */
    public function import(int $ownerId, array $input, int $backedRevision, int $now): void
    {
        if (($input['schemaVersion']??null)!==1 || ($input['dataMode']??null)!=='live' || array_diff(array_keys($input),['schemaVersion','dataMode','holdings','instruments'])) throw new \RuntimeException('Invalid live import.');
        $registry=self::validateRegistry($input['instruments']??null);
        $holdings=Backend::holdings(json_decode(json_encode($input['holdings']??null,JSON_THROW_ON_ERROR)),array_column($registry,'id'),true);
        $this->db->beginTransaction();
        try {
            $lock=$this->db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
            $user=$this->query('SELECT email FROM users WHERE id = ?'.$lock,[$ownerId])->fetchColumn();
            if ($this->ownerEmail===null || $user!==Backend::email($this->ownerEmail)) throw new \RuntimeException('Import is restricted to the configured owner.');
            if ($this->query('SELECT owner_id FROM market_accounts WHERE owner_id = ?',[$ownerId])->fetchColumn()!==false) throw new \RuntimeException('An existing live portfolio cannot be replaced by import.');
            $updated=$this->query('UPDATE portfolios SET holdings_json = ?, revision = revision + 1, updated_at = ? WHERE owner_id = ? AND revision = ?', [json_encode($holdings,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),$now,$ownerId,$backedRevision]);
            if ($updated->rowCount()!==1) throw new HttpError(409,'conflict','Portfolio changed after backup. Create a new backup before importing.');
            $this->query('INSERT INTO market_accounts (owner_id, registry_json, snapshot_json, state_json, updated_at) VALUES (?, ?, ?, ?, ?)',[$ownerId,json_encode($registry,JSON_THROW_ON_ERROR),json_encode(self::emptySnapshot($registry),JSON_THROW_ON_ERROR),'{}',$now]);
            $this->db->commit();
        } catch (\Throwable $e) { if($this->db->inTransaction())$this->db->rollBack(); throw $e; }
    }
    public function saveRefresh(int $ownerId,array $snapshot,array $state,int $now,?int $expectedRevision=null):void
    {
        $account=$this->account($ownerId);
        if(isset($account['catalogue_id'])){$this->catalogues()->saveRefresh($account['catalogue_id'],$expectedRevision??(int)$account['revision'],$snapshot,$state,$now);return;}
        self::validateSnapshot($snapshot,$this->registry($ownerId));
        $statement=$this->query('UPDATE market_accounts SET snapshot_json = ?, state_json = ?, updated_at = ? WHERE owner_id = ?', [json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),json_encode($state,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES),$now,$ownerId]);
        // MySQL reports changed rows: an identical cache write legitimately
        // affects zero rows. Distinguish that from a vanished owner record.
        if($statement->rowCount()===0 && $this->account($ownerId)===null)throw new \RuntimeException('Market cache update failed.');
    }
    /** Change reviewed source capability/schedule metadata after an exact private backup. */
    public function updateCoverage(int $ownerId,array $input,int $backedRevision,string $backedRegistry,int $now):void
    {
        if($this->catalogues()->account($ownerId)!==null)throw new \RuntimeException('Catalogue coverage must be changed through a reviewed catalogue operation.');
        if (($input['schemaVersion']??null)!==1 || array_diff(array_keys($input),['schemaVersion','coverage'])
            || !is_array($input['coverage']??null) || !array_is_list($input['coverage'])
            || count($input['coverage'])<1 || count($input['coverage'])>200) throw new \RuntimeException('Invalid coverage update.');
        $changes=[];
        foreach($input['coverage'] as $item){
            if(!is_array($item) || array_diff(array_keys($item),['id','price','comparison','tradingSchedule'])
                || !is_string($item['id']??null) || !preg_match('/^[A-Za-z0-9_-]{1,60}$/D',$item['id'])
                || isset($changes[$item['id']]) || !is_bool($item['price']??null) || !is_bool($item['comparison']??null)) throw new \RuntimeException('Invalid coverage fields.');
            $changes[$item['id']]=['price'=>$item['price'],'comparison'=>$item['comparison']];
            if(array_key_exists('tradingSchedule',$item)) $changes[$item['id']]['tradingSchedule']=$item['tradingSchedule'];
        }
        $this->db->beginTransaction();
        try{
            $lock=$this->db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
            $email=$this->query('SELECT email FROM users WHERE id = ?'.$lock,[$ownerId])->fetchColumn();
            if($this->ownerEmail===null || $email!==Backend::email($this->ownerEmail)) throw new \RuntimeException('Coverage updates are restricted to the configured owner.');
            $revision=$this->query('SELECT revision FROM portfolios WHERE owner_id = ?'.$lock,[$ownerId])->fetchColumn();
            $row=$this->query('SELECT registry_json FROM market_accounts WHERE owner_id = ?'.$lock,[$ownerId])->fetch();
            if(!$row || $revision===false || (int)$revision!==$backedRevision || !hash_equals($backedRegistry,$row['registry_json'])) throw new HttpError(409,'conflict','Account changed after backup. Create a new backup before updating coverage.');
            $registry=self::validateRegistry(json_decode($row['registry_json'],true,32,JSON_THROW_ON_ERROR));
            if(array_diff(array_keys($changes),array_column($registry,'id'))) throw new \RuntimeException('Coverage cannot add or replace an investment.');
            foreach($registry as &$instrument) if(isset($changes[$instrument['id']])) {
                $change=$changes[$instrument['id']];
                if(array_key_exists('tradingSchedule',$change)){
                    self::validateTradingSchedule($instrument,$change['tradingSchedule']);
                    $instrument['providerConfig']['tradingSchedule']=$change['tradingSchedule'];
                    unset($change['tradingSchedule']);
                }
                $instrument['coverage']=$change;
            }
            unset($instrument);
            self::validateRegistry($registry);
            $this->query('UPDATE market_accounts SET registry_json = ?, updated_at = ? WHERE owner_id = ?', [json_encode($registry,JSON_THROW_ON_ERROR),$now,$ownerId]);
            // No holdings, revisions, quotes, warnings or provider state change.
            // Only a validated provider refresh may make a comparison available.
            $this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    /** Charge before a request: a crash cannot silently replenish quota. */
    public function reserveRequest(string $provider,int $now,bool $preparation=false):void
    {
        $limits=['tickerlayer'=>[86400,80],'alpaca'=>[3600,100],'european'=>[3600,180],'newton'=>[86400,120],'eodhd'=>[86400,18]];
        if(!isset($limits[$provider]))throw new \RuntimeException('Unknown provider budget.');
        if($this->query('SELECT calls FROM market_usage WHERE provider = ? AND window_start = ?',['restore_guard',intdiv($now,86400)*86400])->fetchColumn()!==false)throw new \RuntimeException('Provider collection is paused until the next UTC day after restore; audit account usage first.');
        [$period,$limit]=$limits[$provider];
        // Interactive discovery has a lower ceiling in the same durable ledger.
        // Routine collection can still use the reserved remainder for holdings.
        if($preparation)$limit=['tickerlayer'=>50,'alpaca'=>60,'european'=>90,'newton'=>80,'eodhd'=>6][$provider];
        $window=intdiv($now,$period)*$period;
        $mysql=$this->db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql';
        $this->db->beginTransaction();
        try {
            $sql=$mysql?'INSERT INTO market_usage (provider, window_start, calls) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE calls = calls + 1':'INSERT INTO market_usage (provider, window_start, calls) VALUES (?, ?, 1) ON CONFLICT(provider, window_start) DO UPDATE SET calls = calls + 1';
            $this->query($sql,[$provider,$window]);
            $used=(int)$this->query('SELECT calls FROM market_usage WHERE provider = ? AND window_start = ?',[$provider,$window])->fetchColumn();
            if($used>$limit)throw new \RuntimeException('Provider request budget exhausted.');
            $this->query('DELETE FROM market_usage WHERE window_start < ?',[$now-32*86400]);
            $this->db->commit();
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
