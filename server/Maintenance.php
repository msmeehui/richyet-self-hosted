<?php
declare(strict_types=1);
namespace RichYet;
require_once __DIR__.'/CatalogueStore.php';
require_once __DIR__.'/InstrumentDirectory.php';
require_once __DIR__.'/ReportingStore.php';

/** Operational backups are private JSON, never source-controlled SQL dumps. */
final class Maintenance
{
    public static function backup(\PDO $db):array
    {
        $db->beginTransaction();
        try{
            $data=['schemaVersion'=>4,'createdAt'=>gmdate('Y-m-d\TH:i:s\Z')];
            $tables=[
                'users'=>['users','id,email,password_hash,created_at','id',['id','created_at']],
                'portfolios'=>['portfolios','owner_id,revision,holdings_json,updated_at','owner_id',['owner_id','revision','updated_at']],
                'markets'=>['market_accounts','owner_id,registry_json,snapshot_json,state_json,updated_at','owner_id',['owner_id','updated_at']],
                'catalogues'=>['market_catalogues','id,audience,registry_json,snapshot_json,state_json,policy_json,revision,updated_at','id',['revision','updated_at']],
                'memberships'=>['market_catalogue_accounts','owner_id,catalogue_id,created_at','owner_id',['owner_id','created_at']],
                'directory'=>['market_directory','catalogue_id,instrument_id,name_key,symbol_key,isin_key,instrument_json,policy_json,preparation_json,attempted_at,retry_after,touched_at,lease_token,lease_until','catalogue_id,instrument_id',['attempted_at','retry_after','touched_at','lease_until']],
                'usage'=>['market_usage','provider,window_start,calls','provider,window_start',['window_start','calls']]];
            if((new ReportingStore($db))->available()){
                $data['schemaVersion']=5;
                $tables['reportingSettings']=['reporting_settings','owner_id,currency,updated_at','owner_id',['owner_id','updated_at']];
                $tables['reportingState']=['reporting_state','state_key,value_json,updated_at','state_key',['updated_at']];
            }
            foreach($tables as $key=>[$table,$fields,$order,$ints]){
                $rows=$db->query('SELECT '.$fields.' FROM '.$table.' ORDER BY '.$order)->fetchAll(\PDO::FETCH_ASSOC);
                foreach($rows as &$row)foreach($ints as $field)$row[$field]=(int)$row[$field];unset($row);
                $data[$key]=$rows;
            }
            $db->commit();return $data;
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    /** Restore into an empty app database. Existing quota counters can only increase.
     * The optional same-human mapping must come from a separately trusted private
     * identity/configuration, never from the backup being restored.
     */
    public static function restore(\PDO $db,array $backup,?array $sameHumanTest=null):void
    {
        $version=$backup['schemaVersion']??null;
        if(!in_array($version,[1,2,3,4,5],true)||!is_array($backup['users']??null)||!is_array($backup['portfolios']??null)||count($backup['users'])!==count($backup['portfolios'])||count($backup['users'])>100)throw new \RuntimeException('Invalid backup format.');
        $ids=$owners=$registries=[];
        foreach($backup['users'] as $u){
            if(!is_array($u)||!is_int($u['id']??null)||$u['id']<=0||isset($ids[$u['id']])||!is_int($u['created_at']??null)||!array_key_exists('password_hash',$u)||($u['password_hash']!==null&&(!is_string($u['password_hash'])||!preg_match('~^\$2[aby]\$[0-9]{2}\$[./A-Za-z0-9]{53}$~D',$u['password_hash']))))throw new \RuntimeException('Invalid user in backup.');
            if(Backend::email($u['email']??null)!==$u['email'])throw new \RuntimeException('Backup email is not canonical.');$ids[$u['id']]=true;
        }
        $markets=$version>=2?($backup['markets']??null):[];
        $catalogues=$version>=3?($backup['catalogues']??null):[];$members=$version>=3?($backup['memberships']??null):[];$usage=$version>=3?($backup['usage']??null):[];
        $directory=$version>=4?($backup['directory']??null):[];
        if(!is_array($directory)||!array_is_list($directory)||count($directory)>20000)throw new \RuntimeException('Invalid directory backup.');
        foreach([$markets,$catalogues,$members,$usage] as $rows)if(!is_array($rows)||!array_is_list($rows)||count($rows)>10000)throw new \RuntimeException('Invalid market backup.');
        if(count($markets)>100||count($catalogues)>100||count($members)>100)throw new \RuntimeException('Market backup exceeds account limit.');
        foreach($markets as $m){
            if(!is_array($m)||!is_int($m['owner_id']??null)||!isset($ids[$m['owner_id']])||isset($registries[$m['owner_id']])||!is_int($m['updated_at']??null))throw new \RuntimeException('Invalid market owner in backup.');
            foreach(['registry_json','snapshot_json','state_json'] as $field)if(!is_string($m[$field]??null)||strlen($m[$field])>12000000)throw new \RuntimeException('Invalid market JSON in backup.');
            $registries[$m['owner_id']]=MarketStore::validateRegistry(json_decode($m['registry_json'],true,32,JSON_THROW_ON_ERROR));
            MarketStore::validateSnapshot(json_decode($m['snapshot_json'],true,64,JSON_THROW_ON_ERROR),$registries[$m['owner_id']]);
            if(!is_array(json_decode($m['state_json'],true,64,JSON_THROW_ON_ERROR)))throw new \RuntimeException('Invalid provider state.');
        }
        $catalogueIds=$catalogueRegistries=[];
        foreach($catalogues as $c){
            if(!is_array($c)||!is_string($c['id']??null)||isset($catalogueIds[$c['id']])||!is_int($c['revision']??null)||$c['revision']<0||!is_int($c['updated_at']??null))throw new \RuntimeException('Invalid catalogue backup.');
            $catalogueRegistries[$c['id']]=CatalogueStore::validateRow($c);$catalogueIds[$c['id']]=$c;
        }
        if($sameHumanTest!==null){
            $keys=['sameHumanOwnerId','sameHumanEmail','testOwnerId','testEmail','catalogueId'];
            if(count($sameHumanTest)!==count($keys)||array_diff(array_keys($sameHumanTest),$keys)
                ||!is_int($sameHumanTest['sameHumanOwnerId']??null)||$sameHumanTest['sameHumanOwnerId']<1
                ||!is_int($sameHumanTest['testOwnerId']??null)||$sameHumanTest['testOwnerId']<1
                ||$sameHumanTest['sameHumanOwnerId']===$sameHumanTest['testOwnerId']
                ||!CatalogueStore::id($sameHumanTest['catalogueId']??null)
                ||($catalogueIds[$sameHumanTest['catalogueId']]['audience']??null)!=='owner')throw new \RuntimeException('Invalid same-human recovery identity.');
            $usersById=array_column($backup['users'],null,'id');
            foreach([['sameHumanOwnerId','sameHumanEmail'],['testOwnerId','testEmail']] as [$idKey,$emailKey]){
                if(!is_string($sameHumanTest[$emailKey]??null)||Backend::email($sameHumanTest[$emailKey])!==$sameHumanTest[$emailKey]
                    ||($usersById[$sameHumanTest[$idKey]]['email']??null)!==$sameHumanTest[$emailKey])throw new \RuntimeException('Same-human recovery account changed.');
            }
            if($sameHumanTest['sameHumanEmail']===$sameHumanTest['testEmail'])throw new \RuntimeException('Distinct recovery accounts required.');
        }
        $directoryIds=[];
        foreach($directory as $entry){
            if(!is_array($entry)||!isset($catalogueIds[$entry['catalogue_id']??'']))throw new \RuntimeException('Invalid directory catalogue.');
            $parsed=InstrumentDirectory::validateRow($entry,$catalogueIds[$entry['catalogue_id']]['audience']);
            $active=array_column($catalogueRegistries[$entry['catalogue_id']],null,'id');
            if(isset($active[$entry['instrument_id']])){
                $policies=json_decode($catalogueIds[$entry['catalogue_id']]['policy_json'],true,32,JSON_THROW_ON_ERROR);
                if($parsed['instrument']!==$active[$entry['instrument_id']]||$parsed['policy']!==$policies[$entry['instrument_id']])throw new \RuntimeException('Directory cannot redefine an active listing.');
            }
            $key=$entry['catalogue_id'].':'.$entry['instrument_id'];
            if(isset($directoryIds[$key]))throw new \RuntimeException('Duplicate directory listing.');
            $directoryIds[$key]=true;
        }
        $memberIds=$privateCatalogues=[];
        foreach($members as $m){
            if(!is_array($m)||!is_int($m['owner_id']??null)||!isset($ids[$m['owner_id']])||isset($memberIds[$m['owner_id']])||!is_string($m['catalogue_id']??null)||!isset($catalogueIds[$m['catalogue_id']])||!is_int($m['created_at']??null))throw new \RuntimeException('Invalid catalogue membership.');
            if($catalogueIds[$m['catalogue_id']]['audience']==='owner'){
                $seen=$privateCatalogues[$m['catalogue_id']]??[];
                if($seen&&($sameHumanTest===null||$m['catalogue_id']!==$sameHumanTest['catalogueId']||count($seen)!==1
                    ||!in_array($m['owner_id'],[$sameHumanTest['sameHumanOwnerId'],$sameHumanTest['testOwnerId']],true)
                    ||!in_array($seen[0],[$sameHumanTest['sameHumanOwnerId'],$sameHumanTest['testOwnerId']],true)))throw new \RuntimeException('Private catalogue has multiple members.');
                $privateCatalogues[$m['catalogue_id']][]=$m['owner_id'];
            }
            $memberIds[$m['owner_id']]=true;$registries[$m['owner_id']]=$catalogueRegistries[$m['catalogue_id']];
        }
        if($sameHumanTest!==null){
            $actual=$privateCatalogues[$sameHumanTest['catalogueId']]??[];$expected=[$sameHumanTest['sameHumanOwnerId'],$sameHumanTest['testOwnerId']];sort($actual);sort($expected);
            if($actual!==$expected)throw new \RuntimeException('Exact same-human catalogue membership required.');
        }
        foreach($backup['portfolios'] as $p){
            if(!is_array($p)||!is_int($p['owner_id']??null)||!isset($ids[$p['owner_id']])||isset($owners[$p['owner_id']])||!is_int($p['revision']??null)||$p['revision']<0||!is_int($p['updated_at']??null)||!is_string($p['holdings_json']??null))throw new \RuntimeException('Invalid portfolio in backup.');
            $registry=$registries[$p['owner_id']]??null;Backend::holdings(json_decode($p['holdings_json'],false,32,JSON_THROW_ON_ERROR),$registry===null?null:array_column($registry,'id'),$registry!==null);$owners[$p['owner_id']]=true;
        }
        $usageKeys=[];
        foreach($usage as $u){
            if(!is_array($u)||!in_array($u['provider']??null,['alpaca','tickerlayer','european','newton','eodhd','restore_guard'],true)||!is_int($u['window_start']??null)||$u['window_start']<0||!is_int($u['calls']??null)||$u['calls']<0||$u['calls']>1000000||isset($usageKeys[$u['provider'].':'.$u['window_start']]))throw new \RuntimeException('Invalid quota backup.');
            $usageKeys[$u['provider'].':'.$u['window_start']]=true;
        }
        $settings=$version>=5?($backup['reportingSettings']??null):[];$reportingState=$version>=5?($backup['reportingState']??null):[];
        if(!is_array($settings)||!array_is_list($settings)||count($settings)>100||!is_array($reportingState)||!array_is_list($reportingState)||count($reportingState)>20101)throw new \RuntimeException('Invalid reporting backup.');
        $settingIds=$stateKeys=[];
        foreach($settings as $setting){
            if(!is_array($setting)||count($setting)!==3||!is_int($setting['owner_id']??null)||!isset($ids[$setting['owner_id']])||isset($settingIds[$setting['owner_id']])||!in_array($setting['currency']??null,['EUR','USD','GBP'],true)||!is_int($setting['updated_at']??null)||$setting['updated_at']<0)throw new \RuntimeException('Invalid reporting owner setting.');
            $settingIds[$setting['owner_id']]=true;
        }
        foreach($reportingState as $entry){
            if(!is_array($entry)||count($entry)!==3||!is_string($entry['state_key']??null)||!preg_match('/^(?:fx|receipt:[a-f0-9]{64}|account:[1-9][0-9]{0,18}:number-format)$/D',$entry['state_key'])||isset($stateKeys[$entry['state_key']])||!is_string($entry['value_json']??null)||strlen($entry['value_json'])>524288||!is_int($entry['updated_at']??null)||$entry['updated_at']<0||!is_array($value=json_decode($entry['value_json'],true,32,JSON_THROW_ON_ERROR)))throw new \RuntimeException('Invalid reporting state backup.');
            if(preg_match('/^account:([1-9][0-9]*):number-format$/D',$entry['state_key'],$match)
                &&(!isset($ids[$match[1]])||array_keys($value)!==['numberFormat']||!in_array($value['numberFormat'],['nl-NL','en-GB'],true)))throw new \RuntimeException('Invalid number format owner setting.');
            $stateKeys[$entry['state_key']]=true;
        }
        $hasReporting=(new ReportingStore($db))->available();
        if($version>=5&&!$hasReporting){
            // MySQL DDL commits implicitly. Check the target before this additive
            // setup, then keep all restored rows inside the existing transaction.
            foreach(['users','portfolios','sessions','password_tokens','market_accounts','market_catalogues','market_catalogue_accounts','market_directory','market_directory_tokens'] as $table)if((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()!==0)throw new \RuntimeException('Restore requires an empty database.');
            ReportingStore::initialize($db);$hasReporting=true;
        }
        $db->beginTransaction();
        try{
            foreach(['users','portfolios','sessions','password_tokens','market_accounts','market_catalogues','market_catalogue_accounts','market_directory','market_directory_tokens'] as $table)if((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()!==0)throw new \RuntimeException('Restore requires an empty database.');
            if($hasReporting)foreach(['reporting_settings','reporting_state'] as $table)if((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()!==0)throw new \RuntimeException('Restore requires empty reporting tables; existing settings cannot follow a reassigned account ID.');
            $s=$db->prepare('INSERT INTO users (id,email,password_hash,created_at) VALUES (?,?,?,?)');foreach($backup['users'] as $u)$s->execute([$u['id'],$u['email'],$u['password_hash'],$u['created_at']]);
            $s=$db->prepare('INSERT INTO portfolios (owner_id,revision,holdings_json,updated_at) VALUES (?,?,?,?)');foreach($backup['portfolios'] as $p)$s->execute([$p['owner_id'],$p['revision'],$p['holdings_json'],$p['updated_at']]);
            $s=$db->prepare('INSERT INTO market_accounts (owner_id,registry_json,snapshot_json,state_json,updated_at) VALUES (?,?,?,?,?)');foreach($markets as $m)$s->execute([$m['owner_id'],$m['registry_json'],$m['snapshot_json'],$m['state_json'],$m['updated_at']]);
            $s=$db->prepare('INSERT INTO market_catalogues (id,audience,registry_json,snapshot_json,state_json,policy_json,revision,updated_at) VALUES (?,?,?,?,?,?,?,?)');foreach($catalogues as $c)$s->execute([$c['id'],$c['audience'],$c['registry_json'],$c['snapshot_json'],$c['state_json'],$c['policy_json'],$c['revision'],$c['updated_at']]);
            $s=$db->prepare('INSERT INTO market_catalogue_accounts (owner_id,catalogue_id,created_at) VALUES (?,?,?)');foreach($members as $m)$s->execute([$m['owner_id'],$m['catalogue_id'],$m['created_at']]);
            (new InstrumentDirectory($db,null))->restoreValidatedRows($directory);
            if($hasReporting){
                $s=$db->prepare('INSERT INTO reporting_settings(owner_id,currency,updated_at) VALUES(?,?,?)');foreach($settings as $row)$s->execute([$row['owner_id'],$row['currency'],$row['updated_at']]);
                $s=$db->prepare('INSERT INTO reporting_state(state_key,value_json,updated_at) VALUES(?,?,?)');foreach($reportingState as $row)$s->execute([$row['state_key'],$row['value_json'],$row['updated_at']]);
            }
            // Old backups cannot know subsequent provider calls. No restored database
            // may fetch in this UTC day; existing counters are merged by maximum.
            $usage[]=['provider'=>'restore_guard','window_start'=>intdiv(time(),86400)*86400,'calls'=>1];
            $mysql=$db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='mysql';
            $s=$db->prepare($mysql?'INSERT INTO market_usage (provider,window_start,calls) VALUES (?,?,?) ON DUPLICATE KEY UPDATE calls = GREATEST(calls,VALUES(calls))':'INSERT INTO market_usage (provider,window_start,calls) VALUES (?,?,?) ON CONFLICT(provider,window_start) DO UPDATE SET calls = MAX(calls,excluded.calls)');
            foreach($usage as $u)$s->execute([$u['provider'],$u['window_start'],$u['calls']]);
            $db->commit();
        }catch(\Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    public static function privatePath(string $path, bool $mustExist): string
    {
        $parent = realpath(dirname($path));
        $source = realpath(dirname(__DIR__));
        $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
        $public = realpath($documentRoot !== '' ? $documentRoot : dirname(__DIR__) . '/public');
        if (!$parent || (fileperms($parent) & 0077) !== 0 || is_link($path)
            || ($source && ($parent === $source || str_starts_with($parent, $source . '/')))
            || ($public && ($parent === $public || str_starts_with($parent, $public . '/')))
            || str_contains($parent, '/Dropbox/') || basename($path) === '.' || basename($path) === '..') {
            throw new \RuntimeException('Backup needs an owner-only directory outside source, Dropbox and public files.');
        }
        if ($mustExist && (!is_file($path) || (fileperms($path) & 0077) !== 0 || filesize($path) > 67108864)) {
            throw new \RuntimeException('Backup file is missing, oversized or not private.');
        }
        return $parent . '/' . basename($path);
    }

    public static function writePrivate(string $path, string $contents): void
    {
        $oldMask = umask(0077);
        $file = @fopen($path, 'x');
        umask($oldMask);
        if (!$file) throw new \RuntimeException('Could not create a new private file. Existing files are never replaced.');
        try {
            if (fwrite($file, $contents) !== strlen($contents) || !fflush($file)) throw new \RuntimeException('Private file write failed.');
        } catch (\Throwable $error) {
            fclose($file);
            @unlink($path);
            throw $error;
        }
        fclose($file);
    }
}
