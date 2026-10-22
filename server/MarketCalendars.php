<?php
declare(strict_types=1);
namespace RichYet;
require_once __DIR__.'/MarketCalendarData.php';
require_once __DIR__.'/MarketJson.php';
/** Reviewed static venue calendars. No network or storage work. */
final class MarketCalendars
{
    private static function data():array { static $data=null;return $data??=json_decode(MarketCalendarData::JSON,true,32,JSON_THROW_ON_ERROR); }
    public static function day(string $mic,string $date):?array
    {
        $data=self::data();$venue=$data['venues'][$mic]??null;$year=substr($date,0,4);
        if(!MarketJson::date($date)||!$venue||!in_array($year,$venue['years'],true))return null;
        $calendar=$data['calendars'][$venue['calendar']];$row=$calendar['years'][$year];$md=substr($date,5);
        $isSession=(int)(new \DateTimeImmutable($date,new \DateTimeZone('UTC')))->format('N')<=5&&!in_array($md,$row['closed'],true);
        return ['isSession'=>$isSession,'hoursKnown'=>!$isSession||!in_array($md,$row['uncertainHours'],true),
            'openMinute'=>$calendar['openMinute'],'closeMinute'=>$row['earlyCloses'][$md]??$calendar['closeMinute'],
            'approxCloseMinute'=>$row['earlyCloses'][$md]??$calendar['approxCloseMinute']];
    }
    public static function isSession(string $mic,string $date):?bool { return self::day($mic,$date)['isSession']??null; }
    /** First date beyond the consecutive verified years containing $date. */
    public static function coverageEnd(string $mic,string $date):?string
    {
        if(self::day($mic,$date)===null)return null;
        $year=(int)substr($date,0,4);$venue=self::data()['venues'][$mic];
        while(in_array((string)($year+1),$venue['years'],true))$year++;
        return ($year+1).'-01-01';
    }
}
