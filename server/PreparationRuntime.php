<?php
declare(strict_types=1);
namespace RichYet;
require_once __DIR__.'/InstrumentPreparation.php';
require_once __DIR__.'/Maintenance.php';
require_once __DIR__.'/ProviderHttp.php';

/** Only the explicit authenticated prepare action can reach upstream sources. */
final class PreparationRuntime
{
    public static function run(\PDO $db,array $config,string $configPath,int $ownerId,string $instrumentId):array
    {
        $configPath=Maintenance::privatePath($configPath,true);
        $lockPath=dirname($configPath).'/market-refresh.lock';
        if(is_link($lockPath))throw new \RuntimeException('Unsafe market lock.');
        $mask=umask(0077);$lock=@fopen($lockPath,'c+');umask($mask);
        if(!$lock)throw new \RuntimeException('Market lock unavailable.');
        try{
            $stat=fstat($lock);
            if(!$stat||($stat['mode']&0170000)!==0100000||($stat['mode']&0077)!==0||(function_exists('posix_geteuid')&&$stat['uid']!==posix_geteuid()))throw new \RuntimeException('Market lock is not private.');
            if(!flock($lock,LOCK_EX|LOCK_NB))return ['status'=>'busy','message'=>'Prices are being updated. Please try again shortly.'];
            $http=null;
            $get=static function(string $url,int $maxBytes=8388608)use(&$http,$db,$config):string{
                if($http===null){
                    $keys=ProviderHttp::credentials($config['market_credentials_path']??'',$config['market_eodhd_credentials_path']??null);
                    $http=new ProviderHttp(new MarketStore($db,$config['market_owner_email']??null),$keys,time(),25,12,true);
                }
                return $http->get($url,$maxBytes);
            };
            return (new InstrumentPreparation($db,$config['market_owner_email']??null,$get))->prepare($ownerId,$instrumentId);
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
}
