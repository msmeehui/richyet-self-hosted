<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/Maintenance.php';
require __DIR__ . '/ProviderHttp.php';
require __DIR__ . '/MarketRefresh.php';
ini_set('zend.exception_ignore_args', '1');

try {
    $command = $argv[1] ?? '';
    $args = [];
    for ($i = 2; $i < $argc; $i += 2) {
        if (!in_array($argv[$i], ['--config', '--email', '--output', '--input', '--credentials', '--catalogue', '--instrument'], true) || !isset($argv[$i + 1]) || isset($args[$argv[$i]])) {
            throw new RuntimeException('Invalid arguments.');
        }
        $args[$argv[$i]] = $argv[$i + 1];
    }
    if (!in_array($command, ['init', 'invite', 'recover', 'backup', 'restore', 'market-configure', 'live-import', 'market-refresh', 'market-coverage', 'catalogue-install', 'catalogue-prepare', 'catalogue-attach','directory-import','instrument-prepare'], true)) {
        throw new RuntimeException('Usage: php server/cli.php init|invite|recover|backup|restore --config /private/config.json [--email address --output /private/link.txt | --output /private/backup.json | --input /private/backup.json]');
    }
    $config = \RichYet\loadConfig($args['--config'] ?? (getenv('RICHYET_CONFIG') ?: ''));
    $db = \RichYet\connect($config);
    if ($command === 'init') {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') throw new RuntimeException('Initialization requires MySQL or MariaDB.');
        $db->exec(file_get_contents(__DIR__ . '/schema.mysql.sql'));
        fwrite(STDOUT, "Database schema ready. No account or holdings were added.\n");
    } elseif (in_array($command, ['invite', 'recover'], true)) {
        $path = \RichYet\Maintenance::privatePath($args['--output'] ?? '', false);
        if (file_exists($path)) throw new RuntimeException('Choose a new private output file; existing files are never replaced.');
        $token = (new \RichYet\Backend($db, $config['app_key']))->invite($args['--email'] ?? '', $command === 'invite');
        \RichYet\Maintenance::writePrivate($path, $config['origin'] . '/#password/' . $token . "\n");
        fwrite(STDOUT, "One-use password link saved in the private output file. It expires in one hour. No password or bearer token is printed.\n");
    } elseif ($command === 'backup') {
        $path = \RichYet\Maintenance::privatePath($args['--output'] ?? '', false);
        $json = json_encode(\RichYet\Maintenance::backup($db), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        \RichYet\Maintenance::writePrivate($path, $json);
        fwrite(STDOUT, "Private backup written. Sessions and recovery tokens are excluded.\n");
    } elseif ($command === 'restore') {
        $path = \RichYet\Maintenance::privatePath($args['--input'] ?? '', true);
        $backup = json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($backup)) throw new RuntimeException('Invalid backup.');
        \RichYet\Maintenance::restore($db, $backup);
        fwrite(STDOUT, "Backup restored into the empty database. Sign in again on each device. Provider collection is paused until the next UTC day to prevent quota reuse.\n");
    } else {
        $configPath=\RichYet\Maintenance::privatePath($args['--config'] ?? (getenv('RICHYET_CONFIG') ?: ''),true);
        $lockPath=\RichYet\Maintenance::privatePath(dirname($configPath).'/market-refresh.lock',false);
        if(is_link($lockPath))throw new RuntimeException('Unsafe refresh lock.');
        $oldMask=umask(0077);$lock=@fopen($lockPath,'c+');umask($oldMask);
        if(!$lock || (fstat($lock)['mode']&0077)!==0 || (function_exists('posix_geteuid') && fstat($lock)['uid']!==posix_geteuid()))throw new RuntimeException('Private refresh lock unavailable.');
        if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);fwrite(STDOUT,"Another market operation is running; no work started.\n");exit(0);}
        try {
            if($command==='market-configure'){
                $email=\RichYet\Backend::email($args['--email']??'');
                $owner=$db->prepare('SELECT id FROM users WHERE email = ?');$owner->execute([$email]);
                if($owner->fetchColumn()===false)throw new RuntimeException('Configured owner must be an existing account.');
                $credentials=\RichYet\Maintenance::privatePath($args['--credentials']??'',true);
                \RichYet\ProviderHttp::credentials($credentials);
                $config['market_owner_email']=$email;$config['market_credentials_path']=$credentials;
                $temporary=$configPath.'.new-'.bin2hex(random_bytes(8));
                \RichYet\Maintenance::writePrivate($temporary,json_encode($config,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
                try{if(!rename($temporary,$configPath))throw new RuntimeException('Configuration replacement failed.');}finally{if(is_file($temporary))unlink($temporary);}
                fwrite(STDOUT,"Private market configuration updated; existing database and application credentials preserved.\n");
            } elseif($command==='directory-import') {
                $inputPath=\RichYet\Maintenance::privatePath($args['--input']??'',true);
                $definition=json_decode(file_get_contents($inputPath),true,64,JSON_THROW_ON_ERROR);
                if(!is_array($definition)||array_diff(array_keys($definition),['schemaVersion','catalogueId','entries'])||($definition['schemaVersion']??null)!==1||($definition['catalogueId']??null)!==($args['--catalogue']??null)||!is_array($definition['entries']??null))throw new RuntimeException('Invalid directory definition.');
                $path=\RichYet\Maintenance::privatePath($args['--output']??'',false);
                \RichYet\Maintenance::writePrivate($path,json_encode(\RichYet\Maintenance::backup($db),JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
                $directory=new \RichYet\InstrumentDirectory($db,$config['market_owner_email']??null);
                $db->beginTransaction();
                try{
                    $directory->seedCatalogue($definition['catalogueId'],time());
                    $count=$directory->import($definition['catalogueId'],$definition['entries'],time());
                    $db->commit();
                }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
                fwrite(STDOUT,json_encode(['result'=>'directory_imported','entries'=>$count,'holdingsChanged'=>false],JSON_THROW_ON_ERROR)."\n");
            } elseif($command==='instrument-prepare') {
                require_once __DIR__.'/InstrumentPreparation.php';
                $email=\RichYet\Backend::email($args['--email']??$config['market_owner_email']??'');
                $owner=$db->prepare('SELECT id FROM users WHERE email = ?');$owner->execute([$email]);$ownerId=$owner->fetchColumn();
                if($ownerId===false)throw new RuntimeException('Account must already exist.');
                $http=null;
                $get=static function(string $url,int $bytes=8388608)use(&$http,$db,$config):string{
                    if($http===null){$keys=\RichYet\ProviderHttp::credentials($config['market_credentials_path']??'',$config['market_eodhd_credentials_path']??null);$http=new \RichYet\ProviderHttp(new \RichYet\MarketStore($db,$config['market_owner_email']??null),$keys,time(),25,12,true);}
                    return $http->get($url,$bytes);
                };
                $result=(new \RichYet\InstrumentPreparation($db,$config['market_owner_email']??null,$get))->prepare((int)$ownerId,$args['--instrument']??'');
                fwrite(STDOUT,json_encode(['result'=>$result['status'],'message'=>$result['message'],'transport'=>$http?->evidence()],JSON_THROW_ON_ERROR)."\n");
            } elseif(in_array($command,['catalogue-install','catalogue-prepare','catalogue-attach'],true)) {
                $store=new \RichYet\MarketStore($db,$config['market_owner_email']??null);
                if($command==='catalogue-prepare'){
                    $keys=\RichYet\ProviderHttp::credentials($config['market_credentials_path']??'',$config['market_eodhd_credentials_path']??null);
                    $http=new \RichYet\ProviderHttp($store,$keys,time());
                    $result=\RichYet\MarketRefresh::catalogue($store,$args['--catalogue']??'',[$http,'get'],time());
                    fwrite(STDOUT,json_encode(['result'=>'catalogue_prepared','counts'=>$result],JSON_THROW_ON_ERROR)."\n");
                }else{
                    $definition=null;
                    if($command==='catalogue-install'){
                        $inputPath=\RichYet\Maintenance::privatePath($args['--input']??'',true);
                        $definition=json_decode(file_get_contents($inputPath),true,64,JSON_THROW_ON_ERROR);
                        if(!is_array($definition))throw new RuntimeException('Invalid catalogue definition.');
                    }
                    $backup=\RichYet\Maintenance::backup($db);
                    $path=\RichYet\Maintenance::privatePath($args['--output']??'',false);
                    \RichYet\Maintenance::writePrivate($path,json_encode($backup,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
                    if($command==='catalogue-install')$store->catalogues()->install($definition,time());
                    else{
                        $email=\RichYet\Backend::email($args['--email']??'');$ownerId=null;$revision=null;$registry=null;
                        foreach($backup['users'] as $user)if($user['email']===$email)$ownerId=$user['id'];
                        foreach($backup['portfolios'] as $p)if($p['owner_id']===$ownerId)$revision=$p['revision'];
                        foreach($backup['markets'] as $m)if($m['owner_id']===$ownerId)$registry=$m['registry_json'];
                        if($ownerId===null||$revision===null)throw new RuntimeException('Account must already exist.');
                        $store->catalogues()->attach($ownerId,$args['--catalogue']??'',$revision,$registry,time());
                    }
                    fwrite(STDOUT,"Catalogue operation completed after private backup. Holdings and their revisions were preserved.\n");
                }
            } else {
                $email=\RichYet\Backend::email($config['market_owner_email']??'');
                if(isset($args['--email']) && \RichYet\Backend::email($args['--email'])!==$email)throw new RuntimeException('Requested account is not the configured market owner.');
                $owner=$db->prepare('SELECT id FROM users WHERE email = ?');$owner->execute([$email]);$ownerId=$owner->fetchColumn();
                if($ownerId===false)throw new RuntimeException('Configured owner does not exist.');$ownerId=(int)$ownerId;
                $store=new \RichYet\MarketStore($db,$email);
                if($command==='live-import'){
                    $inputPath=\RichYet\Maintenance::privatePath($args['--input']??'',true);
                    $input=json_decode(file_get_contents($inputPath),true,64,JSON_THROW_ON_ERROR);if(!is_array($input))throw new RuntimeException('Invalid import JSON.');
                    \RichYet\MarketStore::validateRegistry($input['instruments']??null);
                    $backup=\RichYet\Maintenance::backup($db);$revision=null;
                    foreach($backup['portfolios'] as $p)if($p['owner_id']===$ownerId)$revision=$p['revision'];
                    if($revision===null)throw new RuntimeException('Portfolio is missing.');
                    $backupPath=\RichYet\Maintenance::privatePath($args['--output']??'',false);
                    \RichYet\Maintenance::writePrivate($backupPath,json_encode($backup,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
                    $store->import($ownerId,$input,$revision,time());
                    fwrite(STDOUT,"Live holdings imported atomically. The prior portfolio is preserved in the private backup; existing tabs must reload.\n");
                } elseif($command==='market-coverage') {
                    $inputPath=\RichYet\Maintenance::privatePath($args['--input']??'',true);
                    $input=json_decode(file_get_contents($inputPath),true,32,JSON_THROW_ON_ERROR);if(!is_array($input))throw new RuntimeException('Invalid coverage JSON.');
                    $backup=\RichYet\Maintenance::backup($db);$revision=null;$registry=null;
                    foreach($backup['portfolios'] as $p)if($p['owner_id']===$ownerId)$revision=$p['revision'];
                    foreach($backup['markets'] as $m)if($m['owner_id']===$ownerId)$registry=$m['registry_json'];
                    if($revision===null || $registry===null)throw new RuntimeException('Live portfolio is missing.');
                    $backupPath=\RichYet\Maintenance::privatePath($args['--output']??'',false);
                    \RichYet\Maintenance::writePrivate($backupPath,json_encode($backup,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
                    $store->updateCoverage($ownerId,$input,$revision,$registry,time());
                    fwrite(STDOUT,"Coverage metadata updated after private backup. Holdings, revisions and cached observations are unchanged.\n");
                } else {
                    $keys=\RichYet\ProviderHttp::credentials($config['market_credentials_path']??'',$config['market_eodhd_credentials_path']??null);
                    $http=new \RichYet\ProviderHttp($store,$keys,time());
                    $result=\RichYet\MarketRefresh::run($store,$ownerId,[$http,'get'],time());
                    fwrite(STDOUT,json_encode(['result'=>'cached','counts'=>$result],JSON_THROW_ON_ERROR)."\n");
                }
            }
        } finally { flock($lock,LOCK_UN);fclose($lock); }
    }
} catch (Throwable $error) {
    $message = $error instanceof PDOException ? 'Database operation failed; no database credentials are printed.' : $error->getMessage();
    fwrite(STDERR, $message . "\n");
    exit(1);
}
