<?php
declare(strict_types=1);
namespace RichYet;
require_once __DIR__.'/MarketStore.php';

/** Searchable, operator-imported listing definitions. Searching never retrieves prices. */
final class InstrumentDirectory
{
    public const PAGE_SIZE = 20;
    public const PREPARATION_JSON_LIMIT = 4000000;
    private const INSERT_PARAMETERS = 900;
    private const INSERT_BYTES = 1048576;
    public function __construct(private readonly \PDO $db, private readonly ?string $ownerEmail, private readonly ?int $clock = null) {}
    private function query(string $sql,array $parameters=[]):\PDOStatement{$s=$this->db->prepare($sql);$s->execute($parameters);return $s;}
    private static function key(string $text):string{return strtolower(trim($text));}
    private static function tokens(array $instrument):array
    {
        $tokens=[];
        foreach(['name','symbol','isin','exchange','mic'] as $field){
            $value=self::key($instrument[$field]??'');
            if($value!=='')$tokens[$value]=true;
            foreach(preg_split('/[^\p{L}\p{N}.]+/u',$value,-1,PREG_SPLIT_NO_EMPTY)?:[] as $word)if(strlen($word)<=160)$tokens[$word]=true;
        }
        return array_slice(array_keys($tokens),0,40);
    }
    public static function validateRow(array $row,string $audience):array
    {
        if(!CatalogueStore::id($row['catalogue_id']??null)||!CatalogueStore::id($row['instrument_id']??null))throw new \RuntimeException('Invalid directory identity.');
        foreach(['instrument_json','policy_json','preparation_json'] as $field)if(!is_string($row[$field]??null)||strlen($row[$field])>($field==='preparation_json'?self::PREPARATION_JSON_LIMIT:2000000))throw new \RuntimeException('Invalid directory storage.');
        $instrument=json_decode($row['instrument_json'],true,32,JSON_THROW_ON_ERROR);
        MarketStore::validateRegistry([$instrument]);
        if($instrument['id']!==$row['instrument_id'])throw new \RuntimeException('Directory identity mismatch.');
        $policy=json_decode($row['policy_json'],true,32,JSON_THROW_ON_ERROR);
        CatalogueStore::policies([$instrument['id']=>$policy],[$instrument],$audience);
        foreach(['name'=>'name_key','symbol'=>'symbol_key','isin'=>'isin_key'] as $field=>$key)if(($row[$key]??null)!==self::key($instrument[$field]??''))throw new \RuntimeException('Directory search identity mismatch.');
        foreach(['attempted_at','retry_after','touched_at','lease_until'] as $field)if(!is_int($row[$field]??null)||$row[$field]<0)throw new \RuntimeException('Invalid directory time.');
        if(($row['lease_token']??null)!==null&&(!is_string($row['lease_token'])||!preg_match('/^[a-f0-9]{32}$/D',$row['lease_token'])))throw new \RuntimeException('Invalid preparation lease.');
        $prepared=json_decode($row['preparation_json'],true,64,JSON_THROW_ON_ERROR);
        if(!is_array($prepared)||array_diff(array_keys($prepared),['state','snapshot']))throw new \RuntimeException('Invalid preparation record.');
        if(isset($prepared['state'])&&!is_array($prepared['state']))throw new \RuntimeException('Invalid preparation evidence.');
        if(isset($prepared['snapshot']))MarketStore::validateSnapshot($prepared['snapshot'],[$instrument]);
        return ['instrument'=>$instrument,'policy'=>$policy,'prepared'=>$prepared];
    }
    private static function typed(array $row):array{foreach(['attempted_at','retry_after','touched_at','lease_until'] as $key)$row[$key]=(int)$row[$key];return $row;}
    public function row(string $catalogueId,string $instrumentId):?array
    {
        $row=$this->query('SELECT * FROM market_directory WHERE catalogue_id = ? AND instrument_id = ?',[$catalogueId,$instrumentId])->fetch();
        return $row?self::typed($row):null;
    }
    /** Trusted operator import only; URLs and provider mappings never come from an API client. */
    public function import(string $catalogueId,array $entries,int $now):int
    {
        if(!array_is_list($entries)||count($entries)>20000||$now<1)throw new \RuntimeException('Invalid directory import.');
        $catalogue=(new CatalogueStore($this->db,$this->ownerEmail))->row($catalogueId);
        $active=array_column(CatalogueStore::validateRow($catalogue),null,'id');$activePolicy=json_decode($catalogue['policy_json'],true,32,JSON_THROW_ON_ERROR);$seen=[];
        $ownsTransaction=!$this->db->inTransaction();if($ownsTransaction)$this->db->beginTransaction();
        try{
            // Read immutable definitions once. Never load potentially large
            // preparation evidence just to decide whether a listing exists.
            $existing=[];$scan=$this->query('SELECT instrument_id,instrument_json,policy_json FROM market_directory WHERE catalogue_id = ?',[$catalogueId]);
            while($row=$scan->fetch())$existing[$row['instrument_id']]=[$row['instrument_json'],$row['policy_json']];
            foreach($entries as $entry){
                if(!is_array($entry)||array_diff(array_keys($entry),['instrument','policy']))throw new \RuntimeException('Invalid directory entry.');
                $i=$entry['instrument']??null;MarketStore::validateRegistry([$i]);$id=$i['id'];
                if(isset($seen[$id]))throw new \RuntimeException('Duplicate directory listing.');$seen[$id]=true;
                CatalogueStore::policies([$id=>$entry['policy']??null],[$i],$catalogue['audience']);
                if(isset($active[$id])&&($active[$id]!==$i||$activePolicy[$id]!==$entry['policy']))throw new \RuntimeException('Directory cannot redefine an active listing.');
                $old=$existing[$id]??null;
                if($old!==null&&(json_decode($old[0],true,32,JSON_THROW_ON_ERROR)!==$i||json_decode($old[1],true,32,JSON_THROW_ON_ERROR)!==$entry['policy']))throw new \RuntimeException('Directory identity changes require a separate reviewed migration.');
            }
            $rows=static function()use($entries,$existing,$active,$catalogueId,$now):\Generator{foreach($entries as $entry){$i=$entry['instrument'];$id=$i['id'];if(isset($existing[$id]))continue;
                yield [$catalogueId,$id,self::key($i['name']),self::key($i['symbol']),self::key($i['isin']??''),json_encode($i,JSON_THROW_ON_ERROR),json_encode($entry['policy'],JSON_THROW_ON_ERROR),'{}',0,0,isset($active[$id])?$now:0,null,0];
            }};
            $this->insertDirectoryRows($rows());
            $tokens=static function()use($entries,$existing,$catalogueId):\Generator{foreach($entries as $entry){$i=$entry['instrument'];if(isset($existing[$i['id']]))continue;foreach(self::tokens($i) as $token)yield [$catalogueId,$token,$i['id']];}};
            $this->insertTokenRows($tokens());
            if($ownsTransaction)$this->db->commit();
        }catch(\Throwable $e){if($ownsTransaction&&$this->db->inTransaction())$this->db->rollBack();throw $e;}
        return count($entries);
    }
    public function seedCatalogue(string $catalogueId,int $now):int
    {
        $row=(new CatalogueStore($this->db,$this->ownerEmail))->row($catalogueId);$policy=json_decode($row['policy_json'],true,32,JSON_THROW_ON_ERROR);
        return $this->import($catalogueId,array_map(static fn($i)=>['instrument'=>$i,'policy'=>$policy[$i['id']]],CatalogueStore::validateRow($row)),$now);
    }
    /** Derived search tokens can be rebuilt after restoring directory records. */
    public function reindex(string $catalogueId,string $instrumentId):void
    {
        $row=$this->row($catalogueId,$instrumentId);if($row===null)throw new \RuntimeException('Directory entry is missing.');
        $ownsTransaction=!$this->db->inTransaction();if($ownsTransaction)$this->db->beginTransaction();
        try{
            $this->query('DELETE FROM market_directory_tokens WHERE catalogue_id = ? AND instrument_id = ?',[$catalogueId,$instrumentId]);
            $this->insertTokenRows(array_map(static fn($token)=>[$catalogueId,$token,$instrumentId],self::tokens(json_decode($row['instrument_json'],true,32,JSON_THROW_ON_ERROR))));
            if($ownsTransaction)$this->db->commit();
        }catch(\Throwable $e){if($ownsTransaction&&$this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    /** Maintenance has validated the entire backup before starting its restore
     * transaction. Leases are deliberately cleared; token indexes are derived. */
    public function restoreValidatedRows(array $entries):void
    {
        if(!$this->db->inTransaction())throw new \RuntimeException('Directory restore requires the backup transaction.');
        $rows=static function()use($entries):\Generator{foreach($entries as $entry){
            $row=array_map(static fn($key)=>$entry[$key],['catalogue_id','instrument_id','name_key','symbol_key','isin_key','instrument_json','policy_json','preparation_json','attempted_at','retry_after','touched_at']);
            yield array_merge($row,[null,0]);
        }};
        $this->insertDirectoryRows($rows());
        $tokens=static function()use($entries):\Generator{foreach($entries as $entry)foreach(self::tokens(json_decode($entry['instrument_json'],true,32,JSON_THROW_ON_ERROR)) as $token)yield [$entry['catalogue_id'],$token,$entry['instrument_id']];};
        $this->insertTokenRows($tokens());
    }
    private function insertDirectoryRows(iterable $rows):void
    {
        $this->insertBatches('INSERT INTO market_directory (catalogue_id,instrument_id,name_key,symbol_key,isin_key,instrument_json,policy_json,preparation_json,attempted_at,retry_after,touched_at,lease_token,lease_until) VALUES ',13,$rows);
    }
    private function insertTokenRows(iterable $rows):void
    {
        $this->insertBatches('INSERT INTO market_directory_tokens (catalogue_id,token,instrument_id) VALUES ',3,$rows);
    }
    /** Fewer database round trips without exceeding SQLite's portable parameter
     * count or accumulating a whole directory's bound values in memory. */
    private function insertBatches(string $sql,int $columns,iterable $rows):void
    {
        $parameters=[];$count=0;$bytes=0;$statements=[];$limit=intdiv(self::INSERT_PARAMETERS,$columns);
        $flush=function()use($sql,$columns,&$parameters,&$count,&$bytes,&$statements):void{
            if($count===0)return;
            $statement=$statements[$count]??$this->db->prepare($sql.implode(',',array_fill(0,$count,'('.implode(',',array_fill(0,$columns,'?')).')')));
            if(count($statements)<8)$statements[$count]=$statement;
            $statement->execute($parameters);$parameters=[];$count=0;$bytes=0;
        };
        foreach($rows as $row){
            if(count($row)!==$columns)throw new \RuntimeException('Invalid directory batch.');
            $rowBytes=0;foreach($row as $value)$rowBytes+=is_string($value)?strlen($value):16;
            if($count>0&&($count>=$limit||$bytes+$rowBytes>self::INSERT_BYTES))$flush();
            foreach($row as $value)$parameters[]=$value;$count++;$bytes+=$rowBytes;
        }
        $flush();
    }
    public function search(int $ownerId,string $query='',int $offset=0):array
    {
        if(strlen($query)>160||preg_match('/[\x00-\x1f\x7f]/',$query)||$offset<0||$offset>20000)throw new HttpError(422,'invalid_input','Use a short investment name, ticker or ISIN.');
        $now=$this->clock??time();$store=new MarketStore($this->db,$this->ownerEmail,$now);$catalogue=$store->catalogues()->account($ownerId);
        if($catalogue===null)throw new HttpError(409,'live_unavailable','Live investments have not been configured.');
        $current=$store->describeCatalogue($catalogue);$active=array_column($current['instruments'],null,'id');$terms=preg_split('/[^\p{L}\p{N}.]+/u',self::key($query),-1,PREG_SPLIT_NO_EMPTY)?:[];
        if(trim($query)!==''&&!$terms)return ['instruments'=>[],'hasMore'=>false,'offset'=>$offset];
        if(count($terms)>6)throw new HttpError(422,'invalid_input','Use a shorter investment name.');
        $parameters=[$catalogue['id']];$where='d.catalogue_id = ?';$join='';
        foreach($terms as $n=>$term){
            // Range bounds keep the token prefix query indexable on both SQLite
            // and MariaDB without depending on their different LIKE collations.
            if($n===0){$join=' JOIN market_directory_tokens t0 ON t0.catalogue_id = d.catalogue_id AND t0.instrument_id = d.instrument_id';$where.=' AND t0.token >= ? AND t0.token < ?';}
            else $where.=' AND EXISTS (SELECT 1 FROM market_directory_tokens t WHERE t.catalogue_id = d.catalogue_id AND t.instrument_id = d.instrument_id AND t.token >= ? AND t.token < ?)';
            $parameters[]=$term;$parameters[]=$term."\u{10FFFF}";
        }
        $parameters[]=self::key($query);$parameters[]=self::key($query);
        $rows=$this->query('SELECT DISTINCT d.* FROM market_directory d'.$join.' WHERE '.$where.' ORDER BY CASE WHEN d.symbol_key = ? OR d.isin_key = ? THEN 0 ELSE 1 END, d.name_key, d.instrument_id LIMIT '.(self::PAGE_SIZE+1).' OFFSET '.$offset,$parameters)->fetchAll();
        $more=count($rows)>self::PAGE_SIZE;$rows=array_slice($rows,0,self::PAGE_SIZE);$instruments=[];
        foreach($rows as $raw){$row=self::typed($raw);$parsed=self::validateRow($row,$catalogue['audience']);$i=MarketStore::metadata([$parsed['instrument']])[0];$id=$i['id'];
            $listing=$parsed['instrument'];$listing['identityConfirmed']=$listing['providerConfig']['identityConfirmed'];
            $admission=$active[$id]['admission']??MarketAdmission::evaluate($listing,$parsed['policy'],null,$catalogue['audience'],$now);
            $status=$admission['canAdd']?'ready':($row['lease_until']>$now||$row['retry_after']>$now||$admission['capability']!=='supported'?'unavailable':'available');
            $i['admission']=$admission;$i['canAdd']=$admission['canAdd'];$i['preparationStatus']=$status;$i['canPrepare']=$status==='available';
            if($row['lease_until']>$now&&!$admission['canAdd'])$i['message']='This investment is being checked. Try again shortly.';
            $instruments[]=$i;
        }
        return ['instruments'=>$instruments,'hasMore'=>$more,'offset'=>$offset];
    }
}
