<?php
declare(strict_types=1);
namespace RichYet;
require_once __DIR__.'/MarketAdmission.php';

/** Scoped market data only. Membership never contains quantities, costs or credentials. */
final class CatalogueStore
{
    public function __construct(private readonly \PDO $db, private readonly ?string $ownerEmail) {}
    private function query(string $sql,array $params=[]):\PDOStatement{$s=$this->db->prepare($sql);$s->execute($params);return $s;}
    private function lock():string{return $this->db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';}
    public static function id(mixed $id):bool{return is_string($id)&&preg_match('/^[A-Za-z0-9_-]{1,60}$/D',$id)===1;}
    public static function policies(mixed $policies,array $registry,string $audience):array
    {
        if(!in_array($audience,['owner','invited'],true)||!is_array($policies))throw new \RuntimeException('Invalid catalogue policy.');
        $ids=array_column($registry,'id');
        if(array_diff(array_keys($policies),$ids)||array_diff($ids,array_keys($policies)))throw new \RuntimeException('Catalogue policy must cover every listing exactly.');
        foreach($registry as $i){
            $p=$policies[$i['id']];
            if(!is_array($p)||array_diff(array_keys($p),['priceAccess','fxAccess','priceHistory','fxHistory']))throw new \RuntimeException('Invalid source policy fields.');
            foreach(['priceAccess','fxAccess'] as $key)if(!in_array($p[$key]??null,['owner','invited','none','unverified'],true))throw new \RuntimeException('Source access must be explicit.');
            foreach(['priceHistory','fxHistory'] as $key)if(!is_bool($p[$key]??null))throw new \RuntimeException('History capability must be explicit.');
            // Real owner-only data can never enter a catalogue exposed to guests.
            if($audience==='invited'&&($p['priceAccess']!=='invited'||($i['currency']!=='EUR'&&$p['fxAccess']!=='invited')))throw new \RuntimeException('Invited catalogue requires eligible price and FX sources.');
        }
        return $policies;
    }
    public static function validateRow(array $row):array
    {
        if(!self::id($row['id']??null)||!in_array($row['audience']??null,['owner','invited'],true))throw new \RuntimeException('Invalid catalogue identity.');
        foreach(['registry_json','snapshot_json','state_json','policy_json'] as $key)if(!is_string($row[$key]??null)||strlen($row[$key])>12000000)throw new \RuntimeException('Invalid catalogue data.');
        $registry=MarketStore::validateRegistry(json_decode($row['registry_json'],true,32,JSON_THROW_ON_ERROR));
        MarketStore::validateSnapshot(json_decode($row['snapshot_json'],true,64,JSON_THROW_ON_ERROR),$registry);
        self::policies(json_decode($row['policy_json'],true,32,JSON_THROW_ON_ERROR),$registry,$row['audience']);
        if(!is_array(json_decode($row['state_json'],true,64,JSON_THROW_ON_ERROR)))throw new \RuntimeException('Invalid catalogue state.');
        return $registry;
    }
    public function row(string $id):array
    {
        if(!self::id($id))throw new \RuntimeException('Invalid catalogue identity.');
        $row=$this->query('SELECT * FROM market_catalogues WHERE id = ?',[$id])->fetch();
        if(!$row)throw new \RuntimeException('Catalogue is not configured.');
        return $row;
    }
    public function account(int $ownerId,bool $lock=false):?array
    {
        $row=$this->query('SELECT c.*, a.owner_id, u.email FROM market_catalogue_accounts a JOIN market_catalogues c ON c.id = a.catalogue_id JOIN users u ON u.id = a.owner_id WHERE a.owner_id = ?'.($lock?$this->lock():''),[$ownerId])->fetch();
        if(!$row)return null;
        if($row['audience']==='owner'&&($this->ownerEmail===null||Backend::email($this->ownerEmail)!==$row['email']))throw new HttpError(403,'owner_only','This catalogue is restricted to its configured owner.');
        self::validateRow($row);
        $row['catalogue_id']=$row['id'];
        return $row;
    }
    /** Operator-only definition. A new catalogue is cold until a bounded refresh succeeds. */
    public function install(array $definition,int $now):void
    {
        if(($definition['schemaVersion']??null)!==1||array_diff(array_keys($definition),['schemaVersion','id','audience','instruments','policies'])||!self::id($definition['id']??null))throw new \RuntimeException('Invalid catalogue definition.');
        $registry=MarketStore::validateRegistry($definition['instruments']??null);
        $policy=self::policies($definition['policies']??null,$registry,$definition['audience']??'');
        $this->query('INSERT INTO market_catalogues (id,audience,registry_json,snapshot_json,state_json,policy_json,revision,updated_at) VALUES (?,?,?,?,?,?,0,?)',[$definition['id'],$definition['audience'],json_encode($registry,JSON_THROW_ON_ERROR),json_encode(MarketStore::emptySnapshot($registry),JSON_THROW_ON_ERROR),'{}',json_encode($policy,JSON_THROW_ON_ERROR),$now]);
    }
    /** Add/reorder reviewed listings without erasing existing identity, history or policy. */
    public function extend(array $definition,int $expectedRevision,int $now):void
    {
        if(($definition['schemaVersion']??null)!==1||array_diff(array_keys($definition),['schemaVersion','id','audience','instruments','policies'])||!self::id($definition['id']??null))throw new \RuntimeException('Invalid catalogue definition.');
        $row=$this->row($definition['id']);$old=self::validateRow($row);
        $registry=MarketStore::validateRegistry($definition['instruments']??null);
        $policy=self::policies($definition['policies']??null,$registry,$definition['audience']??'');
        if($definition['audience']!==$row['audience'])throw new \RuntimeException('Catalogue audience cannot change.');
        $priorPolicy=json_decode($row['policy_json'],true,32,JSON_THROW_ON_ERROR);$byId=array_column($registry,null,'id');
        foreach($old as $i){
            if(!isset($byId[$i['id']]))throw new \RuntimeException('Existing catalogue listings cannot be removed.');
            foreach(['id','symbol','mic','currency','type','isin','provider','providerConfig','timeZone'] as $field)if(($i[$field]??null)!==($byId[$i['id']][$field]??null))throw new \RuntimeException('Existing listing/source identity cannot change.');
            if($priorPolicy[$i['id']]!==$policy[$i['id']])throw new \RuntimeException('Existing source access cannot change during expansion.');
        }
        $snapshot=json_decode($row['snapshot_json'],true,64,JSON_THROW_ON_ERROR);$state=json_decode($row['state_json'],true,64,JSON_THROW_ON_ERROR);
        foreach($registry as $i)if(!array_key_exists($i['id'],$snapshot['quotes'])){$snapshot['quotes'][$i['id']]=MarketStore::missing();$snapshot['issues'][]=['instrumentId'=>$i['id'],'code'=>'price_missing'];}
        MarketStore::validateSnapshot($snapshot,$registry);
        $s=$this->query('UPDATE market_catalogues SET registry_json = ?, policy_json = ?, snapshot_json = ?, state_json = ?, revision = revision + 1, updated_at = ? WHERE id = ? AND revision = ?',[json_encode($registry,JSON_THROW_ON_ERROR),json_encode($policy,JSON_THROW_ON_ERROR),json_encode($snapshot,JSON_THROW_ON_ERROR),json_encode($state,JSON_THROW_ON_ERROR),$now,$definition['id'],$expectedRevision]);
        if($s->rowCount()!==1)throw new HttpError(409,'conflict','Catalogue changed before expansion.');
    }
    /** Atomic CAS prevents a concurrent preparation from replacing newer coherent data. */
    public function saveRefresh(string $id,int $revision,array $snapshot,array $state,int $now):void
    {
        $row=$this->row($id);self::validateRow($row);
        $candidate=$row;$candidate['snapshot_json']=json_encode($snapshot,JSON_THROW_ON_ERROR);$candidate['state_json']=json_encode($state,JSON_THROW_ON_ERROR);
        // Validate the exact bytes readers and saved-file restoration will see,
        // before either the coherent snapshot or its source evidence changes.
        self::validateRow($candidate);
        $s=$this->query('UPDATE market_catalogues SET snapshot_json = ?, state_json = ?, revision = revision + 1, updated_at = ? WHERE id = ? AND revision = ?',[$candidate['snapshot_json'],$candidate['state_json'],$now,$id,$revision]);
        if($s->rowCount()!==1)throw new HttpError(409,'conflict','Catalogue changed while preparing market data.');
    }
    /** Explicit migration/attachment after a fresh backup; existing listing identity cannot change. */
    public function attach(int $ownerId,string $id,int $backedRevision,?string $backedRegistry,int $now):void
    {
        $ownTransaction=!$this->db->inTransaction();if($ownTransaction)$this->db->beginTransaction();
        try{
            $email=$this->query('SELECT email FROM users WHERE id = ?'.$this->lock(),[$ownerId])->fetchColumn();
            $portfolio=$this->query('SELECT revision, holdings_json FROM portfolios WHERE owner_id = ?'.$this->lock(),[$ownerId])->fetch();
            $row=$this->query('SELECT * FROM market_catalogues WHERE id = ?'.$this->lock(),[$id])->fetch();
            if(!$email||!$portfolio||!$row||(int)$portfolio['revision']!==$backedRevision)throw new HttpError(409,'conflict','Account changed after backup.');
            if($row['audience']==='owner'&&($this->ownerEmail===null||$email!==Backend::email($this->ownerEmail)))throw new \RuntimeException('Private catalogue cannot be assigned to another owner.');
            if($this->query('SELECT owner_id FROM market_catalogue_accounts WHERE owner_id = ?',[$ownerId])->fetchColumn()!==false)throw new \RuntimeException('Account already has a catalogue.');
            $registry=self::validateRow($row);$legacy=$this->query('SELECT registry_json FROM market_accounts WHERE owner_id = ?'.$this->lock(),[$ownerId])->fetch();
            if(($legacy?$legacy['registry_json']:null)!==$backedRegistry)throw new HttpError(409,'conflict','Registry changed after backup.');
            if($legacy){
                $new=array_column($registry,null,'id');
                foreach(MarketStore::validateRegistry(json_decode($legacy['registry_json'],true,32,JSON_THROW_ON_ERROR)) as $old){
                    if(!isset($new[$old['id']]))throw new \RuntimeException('Migration cannot remove an existing listing.');
                    foreach(['id','symbol','mic','currency','type','isin'] as $key)if(($new[$old['id']][$key]??null)!==($old[$key]??null))throw new \RuntimeException('Migration cannot change listing identity.');
                }
            }
            $holdings=Backend::holdings(json_decode($portfolio['holdings_json'],false,32,JSON_THROW_ON_ERROR),array_column($registry,'id'),true);
            $admissions=(new MarketStore($this->db,$this->ownerEmail,$now))->describeCatalogue($row);$byId=array_column($admissions['instruments'],null,'id');
            foreach($holdings as $h)if(($byId[$h->instrumentId]['admission']['canAdd']??false)!==true)throw new \RuntimeException('Prepare a complete catalogue before attaching existing holdings.');
            $this->query('INSERT INTO market_catalogue_accounts (owner_id,catalogue_id,created_at) VALUES (?,?,?)',[$ownerId,$id,$now]);
            // Retain legacy data for independent rollback; membership determines the active path.
            if($ownTransaction)$this->db->commit();
        }catch(\Throwable $e){if($ownTransaction&&$this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
}
