<?php
declare(strict_types=1);
namespace RichYet;
require_once __DIR__.'/InstrumentDirectory.php';
require_once __DIR__.'/MarketRefresh.php';

/** Bounded preparation under the caller's collector lock; never changes personal holdings. */
final class InstrumentPreparation
{
    private const REQUEST_LIMIT=12;
    private const HOT_LIMIT=40;
    private const HOT_SECONDS=86400;
    private const CATALOGUE_JSON_LIMIT=12000000;
    private readonly \Closure $get;
    public function __construct(private readonly \PDO $db,private readonly ?string $ownerEmail,callable $get,private readonly ?int $clock=null){$this->get=\Closure::fromCallable($get);}
    private function now():int{return $this->clock??time();}
    private function query(string $sql,array $parameters=[]):\PDOStatement{$s=$this->db->prepare($sql);$s->execute($parameters);return $s;}
    private function result(string $status,string $message,?array $catalogue=null):array{return ['status'=>$status,'message'=>$message]+($catalogue===null?[]:['catalogue'=>$catalogue]);}
    private static function boundedJson(array $value,int $limit):string
    {
        $json=json_encode($value,JSON_THROW_ON_ERROR);
        if(strlen($json)>$limit)throw new \RuntimeException('Prepared evidence exceeds the storage limit.');
        return $json;
    }
    private static function boundedState(array $state,bool $newXetra):void
    {
        self::boundedJson($state,self::CATALOGUE_JSON_LIMIT);
        // A new listing must also fit the collector's retained-evidence budget
        // so the following routine refresh cannot immediately discard its pair.
        if($newXetra)self::boundedJson($state['europe']['xetra']['sessions']??[],EuropeanProviders::XETRA_EVIDENCE_BYTES);
    }
    private function read(int $ownerId,int $now):array
    {
        $store=new MarketStore($this->db,$this->ownerEmail,$now);$row=$store->catalogues()->account($ownerId);
        if($row===null)throw new HttpError(409,'live_unavailable','Live investments have not been configured.');
        return [$store,$row];
    }
    public function prepare(int $ownerId,string $instrumentId):array
    {
        if(!CatalogueStore::id($instrumentId))throw new HttpError(422,'invalid_input','Choose an investment from the search results.');
        $now=$this->now();[$store,$catalogue]=$this->read($ownerId,$now);$directory=new InstrumentDirectory($this->db,$this->ownerEmail,$now);$entry=$directory->row($catalogue['id'],$instrumentId);
        if($entry===null)return $this->result('unavailable','This investment is not in the supported directory.');
        $definition=InstrumentDirectory::validateRow($entry,$catalogue['audience']);$instrument=$definition['instrument'];$policy=$definition['policy'];
        $described=$store->describeCatalogue($catalogue);$active=array_column($described['instruments'],null,'id');
        if(($active[$instrumentId]['canAdd']??false)===true){
            $this->query('UPDATE market_directory SET touched_at = ? WHERE catalogue_id = ? AND instrument_id = ?',[$now,$catalogue['id'],$instrumentId]);
            return $this->result('ready','Price and latest comparison are ready.',$described);
        }
        $listing=$instrument;$listing['identityConfirmed']=$instrument['providerConfig']['identityConfirmed'];
        if(MarketAdmission::evaluate($listing,$policy,null,$catalogue['audience'],$now)['capability']!=='supported')return $this->result('unavailable','This investment does not have a supported price source.');
        if($entry['lease_until']>$now)return $this->result('busy','This investment is being checked. Try again shortly.');
        if($entry['retry_after']>$now)return $this->result('unavailable','Prices could not be prepared yet. Please try again later.');
        $token=bin2hex(random_bytes(16));
        $claimed=$this->query('UPDATE market_directory SET lease_token = ?, lease_until = ?, attempted_at = ? WHERE catalogue_id = ? AND instrument_id = ? AND lease_until <= ? AND retry_after <= ?',[$token,$now+60,$now,$catalogue['id'],$instrumentId,$now,$now]);
        if($claimed->rowCount()!==1)return $this->result('busy','This investment is being checked. Try again shortly.');
        try{
            $priorState=json_decode($catalogue['state_json'],true,64,JSON_THROW_ON_ERROR);$prepared=$definition['prepared'];
            // Merge remembered per-listing evidence first. A failed attempt or an
            // evicted inactive listing cannot forget a known correction/action.
            $priorState=self::mergeState($priorState,$prepared['state']??[],$instrument,false);
            // Refuse admission before spending provider quota when remembered
            // evidence already cannot coexist with the current shared state.
            $newXetra=$instrument['provider']==='xetra'&&!isset($active[$instrumentId]);
            self::boundedState($priorState,$newXetra);
            $singleton=self::stateFor($priorState,$instrument);unset($singleton['us']['nextAttempt']);
            $snapshot=MarketStore::emptySnapshot([$instrument]);
            if(isset($prepared['snapshot']))$snapshot=$prepared['snapshot'];
            $existing=json_decode($catalogue['snapshot_json'],true,64,JSON_THROW_ON_ERROR);
            if(isset($existing['quotes'][$instrumentId])){$snapshot['quotes'][$instrumentId]=$existing['quotes'][$instrumentId];$snapshot['issues']=array_values(array_filter($existing['issues'],static fn($issue)=>$issue['instrumentId']===$instrumentId));}
            $calls=0;$started=hrtime(true);$get=$this->get;
            $bounded=static function(string $url,int $maxBytes=8388608)use(&$calls,$started,$get):string{
                if($calls>=self::REQUEST_LIMIT||(hrtime(true)-$started)/1e9>=25)throw new \RuntimeException('Preparation budget exhausted.');
                $calls++;return $get($url,min($maxBytes,16777216));
            };
            $cached=$catalogue;$cached['registry_json']=json_encode([$instrument],JSON_THROW_ON_ERROR);$cached['policy_json']=json_encode([$instrumentId=>$policy],JSON_THROW_ON_ERROR);$cached['snapshot_json']=json_encode($snapshot,JSON_THROW_ON_ERROR);$cached['state_json']=json_encode($singleton,JSON_THROW_ON_ERROR);
            $cachedProof=$store->describeCatalogue($cached);
            $result=$cachedProof['instruments'][0]['canAdd']?['snapshot'=>$snapshot,'state'=>$singleton]:MarketRefresh::build([$instrument],$snapshot,$singleton,$bounded,$now);
            $finished=$this->now();$newState=self::mergeState($priorState,$result['state'],$instrument);
            self::boundedState($newState,$newXetra);
            $probe=$catalogue;$probe['registry_json']=json_encode([$instrument],JSON_THROW_ON_ERROR);$probe['policy_json']=json_encode([$instrumentId=>$policy],JSON_THROW_ON_ERROR);$probe['snapshot_json']=json_encode($result['snapshot'],JSON_THROW_ON_ERROR);$probe['state_json']=json_encode(self::stateFor($newState,$instrument),JSON_THROW_ON_ERROR);
            $proof=(new MarketStore($this->db,$this->ownerEmail,$finished))->describeCatalogue($probe);$ready=$proof['instruments'][0]['canAdd']===true;
            $remember=self::boundedJson(['state'=>self::stateFor($newState,$instrument),'snapshot'=>$result['snapshot']],InstrumentDirectory::PREPARATION_JSON_LIMIT);
            $this->db->beginTransaction();
            try{
                $fresh=$store->catalogues()->account($ownerId,true);
                if($fresh===null||$fresh['id']!==$catalogue['id']||(int)$fresh['revision']!==(int)$catalogue['revision'])throw new HttpError(409,'conflict','Market data changed while this investment was being prepared.');
                $lease=$this->query('SELECT lease_token FROM market_directory WHERE catalogue_id = ? AND instrument_id = ?',[$catalogue['id'],$instrumentId])->fetchColumn();
                if($lease!==$token)throw new HttpError(409,'conflict','Preparation was superseded.');
                $registry=CatalogueStore::validateRow($fresh);$policies=json_decode($fresh['policy_json'],true,32,JSON_THROW_ON_ERROR);$next=json_decode($fresh['snapshot_json'],true,64,JSON_THROW_ON_ERROR);
                $held=$this->heldIds($catalogue['id']);
                // Preserve held entries even if a fresh preparation is unavailable.
                // Successful inactive additions are subject to a bounded hot set.
                $wasActive=in_array($instrumentId,array_column($registry,'id'),true);
                if($ready&&!$wasActive){$registry[]=$instrument;$policies[$instrumentId]=$policy;$next['quotes'][$instrumentId]=$result['snapshot']['quotes'][$instrumentId];}
                if($wasActive||$ready){
                    $next['quotes'][$instrumentId]=$result['snapshot']['quotes'][$instrumentId];
                    $next['issues']=array_values(array_filter($next['issues'],static fn($issue)=>$issue['instrumentId']!==$instrumentId));
                    $next['issues']=array_merge($next['issues'],$result['snapshot']['issues']);
                    [$registry,$policies,$next,$newState]=$this->select($catalogue['id'],$registry,$policies,$next,$newState,$held,$finished,$ready?$instrumentId:null);
                    if(!in_array($instrumentId,array_column($registry,'id'),true))$ready=false;
                    $this->save($fresh,$registry,$policies,$next,$newState,$finished);
                }
                $this->query('UPDATE market_directory SET preparation_json = ?, retry_after = ?, touched_at = ?, lease_token = NULL, lease_until = 0 WHERE catalogue_id = ? AND instrument_id = ? AND lease_token = ?',[$remember,$ready?0:$finished+300,$ready?$finished:$entry['touched_at'],$catalogue['id'],$instrumentId,$token]);
                $this->db->commit();
            }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
            if(!$ready)return $this->result('unavailable','A complete price and latest comparison are not available yet. Please try again later.');
            return $this->result('ready','Price and latest comparison are ready.',(new MarketStore($this->db,$this->ownerEmail,$finished))->catalogue($ownerId));
        }catch(\Throwable $e){
            if($this->db->inTransaction())$this->db->rollBack();
            $this->query('UPDATE market_directory SET lease_token = NULL, lease_until = 0, retry_after = ? WHERE catalogue_id = ? AND instrument_id = ? AND lease_token = ?',[$this->now()+300,$catalogue['id'],$instrumentId,$token]);
            if($e instanceof HttpError&&$e->status===409)return $this->result('busy','Market data changed while checking. Please try again shortly.');
            return $this->result('unavailable','Prices could not be prepared yet. Please try again later.');
        }
    }
    private function heldIds(string $catalogueId):array
    {
        $held=[];$lock=$this->db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        foreach($this->query('SELECT p.holdings_json FROM portfolios p JOIN market_catalogue_accounts a ON a.owner_id = p.owner_id WHERE a.catalogue_id = ?'.$lock,[$catalogueId])->fetchAll() as $row){
            $holdings=json_decode($row['holdings_json'],true,32,JSON_THROW_ON_ERROR);
            foreach($holdings as $holding){$id=$holding['instrumentId']??null;if(!CatalogueStore::id($id))throw new \RuntimeException('Invalid held listing.');$held[$id]=true;}
        }
        return $held;
    }
    /** Null retains the legacy operator-prepared collector. An empty imported
     * directory portfolio deliberately makes no recurring source requests. */
    public function refreshRegistry(string $catalogueId):?array
    {
        if((int)$this->query('SELECT COUNT(*) FROM market_directory WHERE catalogue_id = ?',[$catalogueId])->fetchColumn()===0)return null;
        $row=(new CatalogueStore($this->db,$this->ownerEmail))->row($catalogueId);$registry=CatalogueStore::validateRow($row);$held=$this->heldIds($catalogueId);
        if(array_diff(array_keys($held),array_column($registry,'id')))throw new \RuntimeException('A held investment is missing from the active catalogue.');
        return array_values(array_filter($registry,static fn($i)=>isset($held[$i['id']])));
    }
    /** Invoke under the same collector lock before scheduled collection. */
    public function prune(string $catalogueId,int $now):int
    {
        $store=new CatalogueStore($this->db,$this->ownerEmail);$row=$store->row($catalogueId);$registry=CatalogueStore::validateRow($row);
        // Legacy catalogues without an imported directory remain unchanged.
        if((int)$this->query('SELECT COUNT(*) FROM market_directory WHERE catalogue_id = ?',[$catalogueId])->fetchColumn()===0)return 0;
        $this->db->beginTransaction();
        try{
            [$next,$policies,$snapshot,$state]=$this->select($catalogueId,$registry,json_decode($row['policy_json'],true,32,JSON_THROW_ON_ERROR),json_decode($row['snapshot_json'],true,64,JSON_THROW_ON_ERROR),json_decode($row['state_json'],true,64,JSON_THROW_ON_ERROR),$this->heldIds($catalogueId),$now,null);
            $removed=count($registry)-count($next);if($removed>0)$this->save($row,$next,$policies,$snapshot,$state,$now);$this->db->commit();return $removed;
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    /** Held-only collection can enlarge its evidence while hot entries retain
     * old evidence. Persist eviction and the refreshed held cache atomically. */
    public function saveHeldRefresh(array $old,array $snapshot,array $state,int $now):array
    {
        $this->db->beginTransaction();
        try{
            $lock=$this->db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
            $fresh=$this->query('SELECT * FROM market_catalogues WHERE id = ?'.$lock,[$old['id']])->fetch();
            if(!$fresh||(int)$fresh['revision']!==(int)$old['revision'])throw new HttpError(409,'conflict','Catalogue changed during collection.');
            $registry=CatalogueStore::validateRow($fresh);$policy=json_decode($fresh['policy_json'],true,32,JSON_THROW_ON_ERROR);
            $held=$this->heldIds($old['id']);
            [$registry,$policy,$snapshot,$state]=$this->select($old['id'],$registry,$policy,$snapshot,$state,$held,$now,null,true);
            $this->save($fresh,$registry,$policy,$snapshot,$state,$now);$this->db->commit();
            return ['instruments'=>count($held),'prices'=>count(array_filter(array_intersect_key($snapshot['quotes'],$held),static fn($q)=>$q['price']!==null)),'issues'=>count(array_filter($snapshot['issues'],static fn($issue)=>isset($held[$issue['instrumentId']])))];
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    private function select(string $catalogueId,array $registry,array $policy,array $snapshot,array $state,array $held,int $now,?string $selected,bool $fitRefreshed=false):array
    {
        $byId=array_column($registry,null,'id');if(array_diff(array_keys($held),array_keys($byId)))throw new \RuntimeException('Cannot remove a member-held listing.');
        $keep=$held;$directoryIds=array_fill_keys($this->query('SELECT instrument_id FROM market_directory WHERE catalogue_id = ?',[$catalogueId])->fetchAll(\PDO::FETCH_COLUMN),true);
        // Until an operator has seeded a legacy entry into the directory, there
        // is nowhere durable to retain its correction/action evidence on eviction.
        foreach($registry as $i)if(!isset($directoryIds[$i['id']]))$keep[$i['id']]=true;
        $recent=$this->query('SELECT instrument_id FROM market_directory WHERE catalogue_id = ? AND touched_at > ? ORDER BY touched_at DESC, instrument_id',[$catalogueId,$now-self::HOT_SECONDS])->fetchAll();
        $hot=$selected===null?[]:[$selected];foreach($recent as $r)if(isset($byId[$r['instrument_id']]))$hot[]=$r['instrument_id'];
        $count=0;foreach(array_unique($hot) as $id){if(isset($keep[$id]))continue;if($count>=self::HOT_LIMIT||count($keep)>=200)break;$keep[$id]=true;$count++;}
        // An empty owner can still discover investments; retain one catalogue
        // member because the legacy active-cache contract requires a nonempty list.
        if(!$keep&&$registry)$keep[$registry[0]['id']]=true;
        if($fitRefreshed){
            // Provider collection already bounded the held subset. Retained hot
            // listings must yield space to that evidence, rather than stall an
            // ordinary holdings refresh when their merged footprint grows.
            $sessions=$state['europe']['xetra']['sessions']??[];
            $removable=array_reverse(array_keys($keep));
            while(strlen(json_encode(array_intersect_key($sessions,$keep),JSON_THROW_ON_ERROR))>EuropeanProviders::XETRA_EVIDENCE_BYTES){
                $removed=false;
                foreach($removable as $id){
                    if(!isset($keep[$id],$directoryIds[$id],$sessions[$id])||isset($held[$id]))continue;
                    unset($keep[$id]);$removed=true;break;
                }
                if(!$removed){
                    // A membership can change after collection, or the held
                    // subset itself can reach capacity. Keep every position;
                    // mark evidence-limited prices explicitly unavailable.
                    $working=$state['europe']['xetra'];$working['sessions']=[];
                    foreach(array_keys($held+$keep) as $id)if(isset($keep[$id],$sessions[$id]))$working['sessions'][$id]=$sessions[$id];
                    $bounded=EuropeanProviders::compactXetraState($working);
                    self::boundedJson($bounded['sessions']??[],EuropeanProviders::XETRA_EVIDENCE_BYTES);
                    foreach($working['sessions'] as $id=>$prior){
                        $next=$bounded['sessions'][$id]??[];$state['europe']['xetra']['sessions'][$id]=$next;
                        $q=$snapshot['quotes'][$id]??null;if($q===null)continue;
                        $affected=false;foreach(['date','previousDate'] as $field){$date=$q[$field]??null;if($date!==null&&(($next[$date]['capacityExceeded']??false)===true||(isset($prior[$date])&&!isset($next[$date]))))$affected=true;}
                        if($affected){
                            $q['invalidations']=array_values(array_unique(array_merge($q['invalidations']??[],['current','comparison'])));$q['sourceAvailable']=false;
                            $snapshot['quotes'][$id]=MarketStore::applyInvalidations($q);
                            foreach(['source_unavailable','price_missing','baseline_missing'] as $code)$snapshot['issues'][]=['instrumentId'=>$id,'code'=>$code];
                        }
                    }
                    break;
                }
            }
        }
        foreach($registry as $i)if(!isset($keep[$i['id']])){
            $single=MarketStore::emptySnapshot([$i]);$single['quotes'][$i['id']]=$snapshot['quotes'][$i['id']];$single['issues']=array_values(array_filter($snapshot['issues'],static fn($issue)=>$issue['instrumentId']===$i['id']));
            foreach(['snapshotId','generatedAt','lastAttemptAt','nextRefreshAt'] as $field)if(array_key_exists($field,$snapshot))$single[$field]=$snapshot[$field];
            $remember=self::boundedJson(['state'=>self::stateFor($state,$i),'snapshot'=>$single],InstrumentDirectory::PREPARATION_JSON_LIMIT);
            $this->query('UPDATE market_directory SET preparation_json = ? WHERE catalogue_id = ? AND instrument_id = ?',[$remember,$catalogueId,$i['id']]);
        }
        $registry=array_values(array_filter($registry,static fn($i)=>isset($keep[$i['id']])));$policy=array_intersect_key($policy,$keep);$snapshot['quotes']=array_intersect_key($snapshot['quotes'],$keep);$snapshot['issues']=array_values(array_filter($snapshot['issues'],static fn($issue)=>isset($keep[$issue['instrumentId']])));
        $filtered=[];foreach($registry as $i)$filtered=self::mergeState($filtered,self::stateFor($state,$i),$i);
        return [$registry,$policy,$snapshot,$filtered];
    }
    private function save(array $old,array $registry,array $policy,array $snapshot,array $state,int $now):void
    {
        $snapshot['snapshotId']=bin2hex(random_bytes(16));$snapshot['generatedAt']=gmdate('Y-m-d\TH:i:s\Z',$now);
        $fx=$state['us']['currentFx']??null;
        if($fx!==null&&USProviders::fxWindowAllowed($fx['start'],$fx['end']))foreach($registry as $i)if($i['currency']==='USD'&&$snapshot['quotes'][$i['id']]['price']!==null&&!isset($snapshot['earlierData'][$i['id']])){
            $q=&$snapshot['quotes'][$i['id']];$q['fx']=$fx['rate'];$q['fxObservedAt']=gmdate('Y-m-d\TH:i:s\Z',$fx['start']);$q['fxBarEnd']=gmdate('Y-m-d\TH:i:s\Z',$fx['end']);$q['fxFreshUntil']=USProviders::fxFreshUntil($fx,$now);$q['fxSeries']='tickerlayer-eurusd-hourly';unset($q);
            $snapshot['issues']=array_values(array_filter($snapshot['issues'],static fn($issue)=>$issue['instrumentId']!==$i['id']||!in_array($issue['code'],['fx_missing','fx_stale'],true)));
            if(USProviders::fxStale($fx,$now))$snapshot['issues'][]=['instrumentId'=>$i['id'],'code'=>'fx_stale'];
        }
        $candidate=$old;
        foreach(['registry_json'=>$registry,'policy_json'=>$policy,'snapshot_json'=>$snapshot,'state_json'=>$state] as $field=>$value)$candidate[$field]=self::boundedJson($value,self::CATALOGUE_JSON_LIMIT);
        // Validate the exact bytes that will be committed. Any rejection also
        // rolls back directory evictions made earlier in this transaction.
        CatalogueStore::validateRow($candidate);
        $s=$this->query('UPDATE market_catalogues SET registry_json = ?, policy_json = ?, snapshot_json = ?, state_json = ?, revision = revision + 1, updated_at = ? WHERE id = ? AND revision = ?',[$candidate['registry_json'],$candidate['policy_json'],$candidate['snapshot_json'],$candidate['state_json'],$now,$old['id'],(int)$old['revision']]);
        if($s->rowCount()!==1)throw new HttpError(409,'conflict','Catalogue changed during preparation.');
    }
    /** Keep singleton evidence isolated; never move another owner's data. */
    public static function stateFor(array $state,array $instrument):array
    {
        $id=$instrument['id'];$out=[];
        if(isset($state['lastValidMarket'][$id]))$out['lastValidMarket'][$id]=$state['lastValidMarket'][$id];
        if($instrument['provider']==='alpaca'){
            $us=$state['us']??[];
            foreach(['currentFx','weekendFx','baselineFx','baselineFxInvalid','corporateActionsCheckedAt','nextAttempt'] as $field)if(isset($us[$field]))$out['us'][$field]=$us[$field];
            if(isset($us['corporateActions']))foreach($us['corporateActions'] as $category=>$rows){
                $filtered=[];foreach($rows as $row){$identities=[];foreach(['symbol','old_symbol','new_symbol','initiating_symbol','target_symbol'] as $field)if(is_string($row[$field]??null)&&$row[$field]!=='')$identities[]=$row[$field];if(!$identities||in_array($instrument['providerConfig']['symbol'],$identities,true))$filtered[]=$row;}
                if($filtered)$out['us']['corporateActions'][$category]=$filtered;
            }
            foreach(['members','identities','basisInvalidThrough','priceChecks','comparisonChecks'] as $field)if(isset($us[$field][$id]))$out['us'][$field][$id]=$us[$field][$id];
        }else{
            $eu=$state['europe']??[];
            foreach(['bnd','history','actions'] as $field)if(isset($eu[$field][$id]))$out['europe'][$field][$id]=$eu[$field][$id];
            if($instrument['provider']==='euronext'){
                $schedule=$instrument['providerConfig']['tradingSchedule']??'';$route=$eu['euronext'][$schedule]??[];
                foreach(['attemptAt','nextAttempt','successAt','lastAttemptFailed','fingerprint','downloadCheck'] as $field)if(isset($route[$field]))$out['europe']['euronext'][$schedule][$field]=$route[$field];
                foreach(['members','lastObservation','invalidDates','invalidThrough','lastQuotes'] as $field)if(isset($route[$field][$id]))$out['europe']['euronext'][$schedule][$field][$id]=$route[$field][$id];
            }
            if($instrument['provider']==='xetra'){
                $xetra=$eu['xetra']??[];
                foreach(['members','sessions','reviewedPairs','referenceIdentity','referenceRevoked','referenceActions'] as $field)if(isset($xetra[$field][$id]))$out['europe']['xetra'][$field][$id]=$xetra[$field][$id];
                foreach($xetra as $field=>$value)if(!in_array($field,['members','sessions','reviewedPairs','referenceIdentity','referenceRevoked','referenceActions','references'],true))$out['europe']['xetra'][$field]=$value;
                foreach($xetra['references']??[] as $date=>$reference){$reference['rows']=isset($reference['rows'][$id])?[$id=>$reference['rows'][$id]]:[];$out['europe']['xetra']['references'][$date]=$reference;}
            }
        }
        return $out;
    }
    /** Recursive map merge retains vetoes and other members; list actions deduplicate. */
    public static function mergeState(array $old,array $new,array $instrument,bool $replaceSessionEvidence=true):array
    {
        $one=self::stateFor($new,$instrument);
        $merge=static function(array $a,array $b)use(&$merge):array{foreach($b as $key=>$value){
            if(is_array($value)&&isset($a[$key])&&is_array($a[$key])){
                if(array_is_list($value)&&array_is_list($a[$key])){$combined=[];foreach(array_merge($a[$key],$value) as $row)$combined[json_encode($row,JSON_THROW_ON_ERROR)]=$row;$a[$key]=array_values($combined);}
                else $a[$key]=$merge($a[$key],$value);
            }else $a[$key]=$value;
        }return $a;};
        $merged=$merge($old,$one);$id=$instrument['id'];
        if(isset($one['lastValidMarket'][$id]))$merged['lastValidMarket'][$id]=$replaceSessionEvidence||!isset($old['lastValidMarket'][$id])?$one['lastValidMarket'][$id]:$old['lastValidMarket'][$id];
        // A rejected FX window must not be resurrected by recursive merging,
        // including if an older runtime is used for a source-only rollback.
        if($replaceSessionEvidence&&array_key_exists('baselineFx',$one['us']??[]))$merged['us']['baselineFx']=$one['us']['baselineFx'];
        if($instrument['provider']==='bnd'&&(isset($old['europe']['bnd'][$id]['contribution'])||isset($one['europe']['bnd'][$id]['contribution']))){
            $merged['europe']['bnd'][$id]['contribution']=FundContribution::mergeLedger($instrument,$old['europe']['bnd'][$id]['contribution']??null,$one['europe']['bnd'][$id]['contribution']??null);
        }
        // A collector starts from the retained session and may deliberately
        // compact it or discard it at a capacity limit. Recursive map merging
        // would resurrect removed trade bodies and undo those size bounds.
        if($replaceSessionEvidence&&array_key_exists($id,$one['europe']['xetra']['sessions']??[]))$merged['europe']['xetra']['sessions'][$id]=[];
        foreach($one['europe']['xetra']['sessions'][$id]??[] as $date=>$session){
            $prior=$old['europe']['xetra']['sessions'][$id][$date]??null;
            $next=$replaceSessionEvidence||$prior===null?$session:$prior;
            if(($prior['invalid']??false)===true||($session['invalid']??false)===true)$next['invalid']=true;
            if(($prior['capacityExceeded']??false)===true||($session['capacityExceeded']??false)===true){
                $next['capacityExceeded']=true;$next['trades']=[];unset($next['transactionFingerprints'],$next['transactionCount']);
            }
            unset($next['_transactionFingerprints']);
            $merged['europe']['xetra']['sessions'][$id][$date]=$next;
        }
        // A complete authoritative history entry includes its bounded retention
        // ledger. Recursive merge would revive rejected or pruned rows and attach
        // a newer response clock to omitted older values.
        if($replaceSessionEvidence&&array_key_exists($id,$one['europe']['history']??[]))$merged['europe']['history'][$id]=$one['europe']['history'][$id];
        if(!$replaceSessionEvidence&&isset($old['europe']['history'][$id]))$merged['europe']['history'][$id]=$old['europe']['history'][$id];
        if(($old['europe']['history'][$id]['identityRevoked']??false)===true||($one['europe']['history'][$id]['identityRevoked']??false)===true)$merged['europe']['history'][$id]['identityRevoked']=true;
        // Each adapter quote is one complete decision, not a growing map.
        // Recursive merge would keep obsolete ambiguity dates, failure issues
        // and invalidations after a later valid observation replaced them.
        // Actual correction vetoes remain in the separate monotonic ledgers.
        $schedule=$instrument['providerConfig']['tradingSchedule']??'';
        if(array_key_exists($id,$one['europe']['euronext'][$schedule]['lastQuotes']??[])){
            $merged['europe']['euronext'][$schedule]['lastQuotes'][$id]=$replaceSessionEvidence||!isset($old['europe']['euronext'][$schedule]['lastQuotes'][$id])
                ?$one['europe']['euronext'][$schedule]['lastQuotes'][$id]:$old['europe']['euronext'][$schedule]['lastQuotes'][$id];
        }
        // A singleton collection did not advance the cursor or freshness of
        // every other member. Preserve shared polling clocks and archive cursors.
        if(array_diff(array_keys($old['us']['members']??[]),[$id]))foreach(['nextAttempt','corporateActionsCheckedAt'] as $field){if(isset($old['us'][$field]))$merged['us'][$field]=$old['us'][$field];else unset($merged['us'][$field]);}
        if(array_diff(array_keys($old['europe']['euronext'][$schedule]['members']??[]),[$id]))foreach(['attemptAt','nextAttempt','successAt','lastAttemptFailed','fingerprint','downloadCheck'] as $field){if(isset($old['europe']['euronext'][$schedule][$field]))$merged['europe']['euronext'][$schedule][$field]=$old['europe']['euronext'][$schedule][$field];else unset($merged['europe']['euronext'][$schedule][$field]);}
        if(array_diff(array_keys($old['europe']['xetra']['members']??[]),[$id]))foreach($merged['europe']['xetra']??[] as $field=>$_){
            if(in_array($field,['members','sessions','reviewedPairs','referenceIdentity','referenceRevoked','referenceActions','references'],true))continue;
            if(array_key_exists($field,$old['europe']['xetra']))$merged['europe']['xetra'][$field]=$old['europe']['xetra'][$field];else unset($merged['europe']['xetra'][$field]);
        }
        foreach(['currentFx','weekendFx'] as $field)if(isset($old['us'][$field])&&($old['us'][$field]['end']??0)>($merged['us'][$field]['end']??0))$merged['us'][$field]=$old['us'][$field];
        // Dates recording known unsafe bases are monotonic across retries.
        foreach([['us','basisInvalidThrough'],['europe','euronext',$instrument['providerConfig']['tradingSchedule']??'','invalidThrough']] as $path){$a=$old;$b=$merged;foreach($path as $part){$a=$a[$part]??[];$b=$b[$part]??[];}if(isset($a[$id],$b[$id])&&is_string($a[$id])&&is_string($b[$id])&&$a[$id]>$b[$id]){$target=&$merged;foreach($path as $part)$target=&$target[$part];$target[$id]=$a[$id];unset($target);}}
        if(isset($old['europe']['xetra']['referenceRevoked'][$id],$merged['europe']['xetra']['referenceRevoked'][$id]))$merged['europe']['xetra']['referenceRevoked'][$id]=min($old['europe']['xetra']['referenceRevoked'][$id],$merged['europe']['xetra']['referenceRevoked'][$id]);
        return $merged;
    }
}
