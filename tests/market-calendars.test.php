<?php
declare(strict_types=1);
// Pure synthetic checks. Safe inside the release helper's closure with bootstrap loaded.
require_once dirname(__DIR__).'/server/MarketCalendars.php';
require_once dirname(__DIR__).'/server/EuropeanHistory.php';
require_once dirname(__DIR__).'/server/XetraReference.php';
require_once dirname(__DIR__).'/server/USProviders.php';
require_once dirname(__DIR__).'/server/MarketStore.php';
$calendarChecks=0;
$calendarCheck=static function(bool $ok,string $label)use(&$calendarChecks):void{if(!$ok)throw new RuntimeException('Calendar check failed: '.$label);$calendarChecks++;};
foreach(['2027-01-01','2027-03-26','2027-03-29','2027-12-24','2027-12-31'] as $date)$calendarCheck(\RichYet\MarketCalendars::isSession('XETA',$date)===false,'Xetra closure '.$date);
$calendarCheck(\RichYet\MarketCalendars::isSession('XETA','2027-12-27')===true,'No invented Monday holiday');
$calendarCheck(\RichYet\MarketCalendars::day('XNYS','2027-11-26')['closeMinute']===780,'NYSE 13:00 early close');
$calendarCheck(\RichYet\MarketCalendars::isSession('XNYS','2027-06-18')===false,'NYSE observed Juneteenth');
foreach(['XAMS','XPAR','XBRU','XNAS','BATS'] as $mic)$calendarCheck(\RichYet\MarketCalendars::day($mic,'2027-01-04')===null,'Unreviewed static venue '.$mic);
$calendarCheck(\RichYet\MarketCalendars::day('XETA','2027-12-30')['hoursKnown']===false,'Unreviewed final-session hours');
$calendarCheck(\RichYet\MarketCalendars::day('XETA','2027-02-29')===null,'Invalid civil date');
$calendarCheck(\RichYet\MarketCalendars::coverageEnd('XETA','2026-12-30')==='2028-01-01','Consecutive Xetra year coverage');
$calendarCheck(\RichYet\MarketCalendars::coverageEnd('XAMS','2026-12-30')==='2027-01-01','Euronext publication boundary');
$calendarCheck(\RichYet\XetraReference::previousSession('2027-01-04')==='2026-12-30','Prior actual Xetra session at year boundary');
$calendarCheck(\RichYet\XetraReference::previousSession('2027-03-30')==='2027-03-25','Easter previous session');
$calendarCheck(\RichYet\XetraReference::previousSession('2028-01-03')===null,'Unreviewed future year blocked');
$raw=['source'=>'alpaca_calendar','timeZone'=>'America/New_York','from'=>'2027-03-01','through'=>'2027-03-22','checkedAt'=>'2027-03-15T14:00:00Z','sessions'=>[
    ['date'=>'2027-03-12','openAt'=>'2027-03-12T14:30:00Z','closeAt'=>'2027-03-12T21:00:00Z'],
    ['date'=>'2027-03-15','openAt'=>'2027-03-15T13:30:00Z','closeAt'=>'2027-03-15T20:00:00Z']]];
$now=strtotime('2027-03-15T14:01:00Z');
$calendarCheck(\RichYet\USProviders::publicCalendar($raw,$now)===$raw,'Valid exact DST sessions');
$calendarCheck(\RichYet\USProviders::publicCalendar($raw,strtotime('2027-03-15T13:59:59Z'))===null,'Future receipt');
$calendarCheck(\RichYet\USProviders::publicCalendar($raw,strtotime('2027-03-23T14:00:00Z'))===null,'Expired receipt');
$calendarCheck(\RichYet\USProviders::publicCalendar(null,$now)===null,'Old states without optional calendar');
$badCases=[];
foreach(['source'=>'other','timeZone'=>'UTC','from'=>'2026-01-01','through'=>'2027-03-14','checkedAt'=>'2027-03-15T14:00:00+00:00','sessions'=>[]] as $key=>$value){$bad=$raw;$bad[$key]=$value;$badCases[]=$bad;}
$bad=$raw;$bad['sessions'][]=$bad['sessions'][0];$badCases[]=$bad;
foreach(['date'=>'2027-03-13','openAt'=>'2027-03-12T13:30:00Z','closeAt'=>'2027-03-15T20:00:00Z'] as $key=>$value){$bad=$raw;$bad['sessions'][0][$key]=$value;$badCases[]=$bad;}
foreach($badCases as $bad)$calendarCheck(\RichYet\USProviders::publicCalendar($bad,$now)===null,'Malformed session metadata');
$parsed=\RichYet\USProviders::calendar([['date'=>'2027-03-12','open'=>'09:30','close'=>'16:00'],['date'=>'2027-03-15','open'=>'09:30','close'=>'16:00'],['date'=>'2027-11-26','open'=>'09:30','close'=>'13:00']]);
$calendarCheck(gmdate('H:i',$parsed['2027-03-12']['open'])==='14:30'&&gmdate('H:i',$parsed['2027-03-15']['open'])==='13:30','Provider calendar DST conversion');
$calendarCheck(gmdate('H:i',$parsed['2027-11-26']['close'])==='18:00','Provider early close conversion');
// Exercise ordinary refresh with an entirely offline injected source. A valid
// calendar remains useful when a later price request fails, without a new call.
$registry=[['id'=>'fictional-us','provider'=>'alpaca','mic'=>'XNAS','currency'=>'USD','providerConfig'=>['symbol'=>'FICT','identityConfirmed'=>true]]];
$calls=[];$calendarFailure=false;
$get=static function(string $url)use(&$calls,&$calendarFailure):string{
    $path=parse_url($url,PHP_URL_PATH);$calls[]=$path;
    if(str_starts_with($path,'/v2/assets/'))return '{"id":"11111111-2222-3333-4444-555555555555","symbol":"FICT","class":"us_equity","status":"active","exchange":"NASDAQ"}';
    if($path==='/v2/calendar'&&!$calendarFailure)return '[{"date":"2027-03-12","open":"09:30","close":"16:00"},{"date":"2027-03-15","open":"09:30","close":"16:00"}]';
    throw new RuntimeException('Synthetic source unavailable');
};
$result=\RichYet\USProviders::refresh($registry,[],$get,$now);
$stored=$result['state']['sessionCalendar']??null;
$calendarCheck(is_array($stored)&&$stored['checkedAt']==='2027-03-15T14:01:00Z','Successful calendar preserved despite later price failure');
$calendarCheck(count(array_filter($calls,static fn($p)=>$p==='/v2/calendar'))===1,'Only existing calendar request');
$calendarCheck($result['state']['nextAttempt']===$now+3600,'Ordinary failure backoff unchanged');
$count=count($calls);$waiting=\RichYet\USProviders::refresh($registry,$result['state'],$get,$now+600);
$calendarCheck(count($calls)===$count&&$waiting['quotes']===[],'No extra requests inside existing gate');
$calendarFailure=true;$later=\RichYet\USProviders::refresh($registry,$result['state'],$get,$now+3600);
$calendarCheck(($later['state']['sessionCalendar']??null)===$stored,'Failed calendar check retains original receipt without invented confirmation');
$calendarCheck($later['state']['identities']['fictional-us']['assetId']==='11111111-2222-3333-4444-555555555555','Exact listing identity preserved');
echo $calendarChecks," calendar checks passed.\n";
