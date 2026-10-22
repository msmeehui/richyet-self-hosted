import {formatPriceTime} from './dates.mjs';

import {MARKET_CALENDARS, calendarDay} from './market-calendars.mjs';

// Display-only reviewed schedules, never exact price observations.
const schedules = MARKET_CALENDARS.schedules;

function localParts(at, timeZone) {
  const parts = new Intl.DateTimeFormat('en-GB',{timeZone,year:'numeric',month:'2-digit',day:'2-digit',
    hour:'2-digit',minute:'2-digit',second:'2-digit',hourCycle:'h23'}).formatToParts(new Date(at));
  return Object.fromEntries(parts.map(({type,value})=>[type,value]));
}

export function hasReviewedEuropeanClose(instrument) {
  const schedule = schedules[instrument.closeSchedule];
  // The initial reviewed ETF retains its execution MIC XETA; newer catalogue
  // entries use XETR. The server admits that legacy route explicitly, without
  // rewriting either listing identity or observation.
  const legacyXetraETF = instrument.closeSchedule === 'xetra_etf_regular_2026' && instrument.mic === 'XETA';
  return Boolean(schedule && (instrument.mic === schedule[0] || legacyXetraETF) && instrument.type === schedule[1]
    && instrument.currency === 'EUR' && instrument.timeZone === schedule[2]);
}

/** A known regular close converted using the exchange's DST rules. */
export function approximateClosingInstant(instrument, day, now = Date.now()) {
  const schedule = schedules[instrument.closeSchedule];
  if (!hasReviewedEuropeanClose(instrument) || !calendarDay(instrument.mic,day) || !Number.isFinite(now)) return null;
  const [mic,,zone,from] = schedule;
  const session = calendarDay(instrument.mic,day);
  if (day < from || !session.isSession || !session.hoursKnown) return null;
  const hour=String(Math.floor(session.approxCloseMinute/60)).padStart(2,'0'), minute=String(session.approxCloseMinute%60).padStart(2,'0');
  const civil = Date.parse(`${day}T${hour}:${minute}:00Z`);
  try {
    const p = localParts(civil,zone);
    const offset = Date.parse(`${p.year}-${p.month}-${p.day}T${p.hour}:${p.minute}:${p.second}Z`) - civil;
    const at = civil - offset, check = localParts(at,zone);
    if (`${check.year}-${check.month}-${check.day}` !== day || check.hour !== hour || check.minute !== minute || at > now) return null;
    return new Date(at).toISOString();
  } catch { return null; }
}

/** Retained US calendar boundaries are scheduled times, not exact final trades. */
export function isRetainedUSClose(instrument, at, day) {
  if (instrument.closeSchedule !== 'us_retained_calendar' || instrument.currency !== 'USD'
    || !['Stock','ETF'].includes(instrument.type) || instrument.timeZone !== 'America/New_York'
    || typeof at !== 'string' || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/.test(at)
    || !Number.isFinite(Date.parse(at)) || new Date(`${at.slice(0,10)}T00:00:00Z`).toISOString().slice(0,10) !== at.slice(0,10)) return false;
  try {
    const p = localParts(Date.parse(at),'America/New_York');
    return `${p.year}-${p.month}-${p.day}` === day;
  } catch { return false; }
}

export function formatApproximateClosingTime(at, timeZone = undefined) {
  return formatPriceTime(at,timeZone).replace(/, (?=\d{2}:\d{2}$)/, ', ~');
}
