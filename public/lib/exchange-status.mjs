import {getFundContribution} from './fund-contribution.mjs';

import {MARKET_CALENDARS, calendarDay, retainedUSSession} from './market-calendars.mjs';

// Date coverage and special sessions are reviewed in data/market-calendars.json.
// US metadata reuses the collector's existing calendar; never a provider call.
const usVenues = new Set(['XNAS','XNYS','XASE','ARCX','BATS']);
const schedules = MARKET_CALENDARS.schedules;
const clocks = new Map();

function localClock(nowMs, timeZone) {
  if (!clocks.has(timeZone)) clocks.set(timeZone,new Intl.DateTimeFormat('en-GB',{
    timeZone,year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',hourCycle:'h23',
  }));
  const p = Object.fromEntries(clocks.get(timeZone).formatToParts(new Date(nowMs)).map(({type,value})=>[type,value]));
  const day = `${p.year}-${p.month}-${p.day}`;
  return {day,year:p.year,weekday:new Date(`${day}T00:00:00Z`).getUTCDay(),minute:Number(p.hour)*60+Number(p.minute)};
}

/** Scheduled session state, never a live exchange/halt observation.
 * Unknown calendars are not open. Grouping follows regular trading hours;
 * closing auctions and later final publication do not prolong that group.
 */
export function getExchangeSession(instrument, nowMs = Date.now(), quote = null) {
  const unknown = {state:'unknown',date:null};
  if (!instrument || typeof instrument !== 'object' || !Number.isFinite(nowMs)
    || !['Stock','ETF','Fund'].includes(instrument.type)) return unknown;
  if (instrument.type === 'Fund') return {state:'fund',date:null};
  const us = instrument.closeSchedule === 'us_retained_calendar';
  const schedule = schedules[instrument.closeSchedule];
  const xetra = instrument.closeSchedule === 'xetra_etf_regular_2026';
  if (us) {
    if (!usVenues.has(instrument.mic) || instrument.currency !== 'USD' || instrument.timeZone !== 'America/New_York') return unknown;
  } else if (!schedule || instrument.currency !== 'EUR' || instrument.type !== schedule[1]
    || instrument.timeZone !== schedule[2] || (instrument.mic !== schedule[0] && !(xetra && instrument.mic === 'XETA'))) return unknown;
  try {
    const clock = localClock(nowMs,instrument.timeZone);
    if (!us && clock.day < schedule[3]) return unknown;
    if (us) {
      const retained = retainedUSSession(quote?.sessionCalendar,clock.day,nowMs);
      if (retained) return retained;
    }
    const day = calendarDay(instrument.mic,clock.day);
    if (!day || !day.hoursKnown) return unknown;
    if (!day.isSession) return {state:'closed',date:clock.day};
    const open = day.openMinute, close = day.closeMinute;
    // Xetra's opening call starts at 08:55. For Euronext ETFs, omit the label
    // from 09:00 even though their individual opening uncross may be 09:04.
    if (clock.minute < open || clock.minute >= close) return {state:'closed',date:clock.day};
    return {state:'open',date:clock.day};
  } catch { return unknown; }
}

/** false also means unknown: callers must not invert this into an Open claim.
 * The stored quote remains irrelevant to the current scheduled Closed label.
 */
export function isExchangeClosed(instrument, nowMs = Date.now(), quote = null) {
  return getExchangeSession(instrument,nowMs,quote).state === 'closed';
}

function currentSessionObservation(instrument, quote, sessionDate, nowMs) {
  if (!quote || quote.date !== sessionDate || quote.priceKind === 'close' || quote.observationKind === 'close'
    || typeof quote.observedAt !== 'string'
    || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/.test(quote.observedAt)) return false;
  const at = Date.parse(quote.observedAt);
  if (!Number.isFinite(at) || at > nowMs) return false;
  try {
    const civil = quote.observedAt.slice(0,10);
    if (new Date(`${civil}T00:00:00Z`).toISOString().slice(0,10) !== civil) return false;
    return localClock(at,instrument.timeZone).day === sessionDate
      && (instrument.closeSchedule === 'us_retained_calendar' && retainedUSSession(quote.sessionCalendar,sessionDate,nowMs)
        ? quote.sessionCalendar.sessions.some(row=>row.date===sessionDate&&at>=Date.parse(row.openAt)&&at<Date.parse(row.closeAt))
        : getExchangeSession(instrument,at).state === 'open');
  } catch { return false; }
}

/** Inputs for the Open markets subtotal, without altering full portfolio data.
 * Keep every known-open position in the denominator's eligibility count. A
 * prior-session or date-only quote has its copied comparison withheld until an
 * actual current-session observation arrives. Other quote safety stays with
 * normalization and the ordinary calculation helpers.
 */
export function selectOpenMarketEntries(entries, nowMs = Date.now()) {
  if (!Array.isArray(entries)) throw new TypeError('Portfolio entries must be an array.');
  const comparisonEntries = [], waitingInstrumentIds = new Set();
  const counts = {openCount:0,closedCount:0,unknownCount:0,fundCount:0,activeFundCount:0};
  for (const entry of entries) {
    const instrument = entry?.instrument, quote = entry?.quote;
    const session = getExchangeSession(instrument,nowMs,quote);
    counts[`${session.state}Count`]++;
    if (session.state === 'fund' && getFundContribution(instrument,quote,nowMs).active) {
      counts.activeFundCount++;
      // Retain a normalized valid NAV pair after an ordinary failed refresh.
      // Readiness reports its warning separately; invalid inputs stay missing.
      comparisonEntries.push(entry);
      continue;
    }
    if (session.state !== 'open') continue;
    if (currentSessionObservation(instrument,quote,session.date,nowMs)) {
      comparisonEntries.push(entry);
    } else {
      const id = entry?.holding?.instrumentId;
      if (typeof id === 'string' && id.length > 0) waitingInstrumentIds.add(id);
      comparisonEntries.push({...entry,quote:{...quote,previousClose:null,previousFx:null}});
    }
  }
  return {comparisonEntries,...counts,waitingInstrumentIds:[...waitingInstrumentIds]};
}
