import {MARKET_CALENDARS} from './market-calendar-data.mjs';
export {MARKET_CALENDARS};
const date=value=>typeof value==='string'&&/^\d{4}-\d{2}-\d{2}$/.test(value)
  &&Number.isFinite(Date.parse(`${value}T00:00:00Z`))&&new Date(`${value}T00:00:00Z`).toISOString().slice(0,10)===value;
/** Reviewed dates only. A known session can still have unreviewed special hours. */
export function calendarDay(mic,day){
  const venue=MARKET_CALENDARS.venues[mic];
  if(!date(day)||!venue?.years.includes(day.slice(0,4)))return null;
  const calendar=MARKET_CALENDARS.calendars[venue.calendar],year=calendar.years[day.slice(0,4)],md=day.slice(5);
  const isSession=![0,6].includes(new Date(`${day}T00:00:00Z`).getUTCDay())&&!year.closed.includes(md);
  return {isSession,hoursKnown:!isSession||!year.uncertainHours.includes(md),openMinute:calendar.openMinute,
    closeMinute:year.earlyCloses[md]??calendar.closeMinute,approxCloseMinute:year.earlyCloses[md]??calendar.approxCloseMinute};
}
const instant=value=>typeof value==='string'&&/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/.test(value)
  &&Number.isFinite(Date.parse(value))&&new Date(value).toISOString().replace('.000Z','Z')===value;
const newYork=new Intl.DateTimeFormat('en-GB',{timeZone:'America/New_York',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',second:'2-digit',hourCycle:'h23'});
function sessionParts(at){const p=Object.fromEntries(newYork.formatToParts(new Date(at)).map(({type,value})=>[type,value]));return {day:`${p.year}-${p.month}-${p.day}`,minute:Number(p.hour)*60+Number(p.minute),second:Number(p.second)};}
/** Optional authenticated API metadata, not a quote and never a price timestamp. */
export function normalizeUSSessionCalendar(raw){
  if(!raw||typeof raw!=='object'||Array.isArray(raw)||raw.source!=='alpaca_calendar'||raw.timeZone!=='America/New_York'
    ||!date(raw.from)||!date(raw.through)||raw.from>raw.through||Date.parse(raw.through)-Date.parse(raw.from)>40*86400000
    ||!instant(raw.checkedAt)||!Array.isArray(raw.sessions)||!raw.sessions.length||raw.sessions.length>40)return null;
  const checkedDay=sessionParts(raw.checkedAt).day;
  if(checkedDay<raw.from||checkedDay>raw.through)return null;
  const sessions=[],seen=new Set();
  for(const row of raw.sessions){
    if(!row||!date(row.date)||row.date<raw.from||row.date>raw.through||seen.has(row.date)
      ||![1,2,3,4,5].includes(new Date(`${row.date}T00:00:00Z`).getUTCDay())||!instant(row.openAt)||!instant(row.closeAt))return null;
    const open=Date.parse(row.openAt),close=Date.parse(row.closeAt),a=sessionParts(open),b=sessionParts(close);
    // The existing adapter covers core stock/ETF sessions, not extended trading.
    if(a.day!==row.date||b.day!==row.date||a.minute!==570||a.second!==0||b.second!==0||close<=open||close-open>8*3600000)return null;
    seen.add(row.date);sessions.push({date:row.date,openAt:row.openAt,closeAt:row.closeAt});
  }
  sessions.sort((a,b)=>a.date.localeCompare(b.date));
  return {source:raw.source,timeZone:raw.timeZone,from:raw.from,through:raw.through,checkedAt:raw.checkedAt,sessions};
}
/** A successful complete query establishes closures inside its requested range.
 * Missing/expired metadata does not extrapolate a future year or a closure. */
export function retainedUSSession(calendar,day,nowMs){
  if(!Number.isFinite(nowMs)||!date(day))return null;
  const record=normalizeUSSessionCalendar(calendar);if(!record)return null;
  const checked=Date.parse(record.checkedAt);
  if(checked>nowMs||nowMs-checked>7*86400000||day<record.from||day>record.through)return null;
  const session=record.sessions.find(row=>row.date===day);
  return session?{state:nowMs>=Date.parse(session.openAt)&&nowMs<Date.parse(session.closeAt)?'open':'closed',date:day}:{state:'closed',date:day};
}
