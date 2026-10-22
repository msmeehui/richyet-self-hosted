// RichYet reporting hours, not a fund trading session or publication promise.
const date = value => typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value)
  && Number.isFinite(Date.parse(`${value}T00:00:00Z`))
  && new Date(`${value}T00:00:00Z`).toISOString().slice(0,10) === value;
function amsterdamClock(now) {
  const parts = new Intl.DateTimeFormat('en-GB',{timeZone:'Europe/Amsterdam',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',hourCycle:'h23'}).formatToParts(now);
  const p = Object.fromEntries(parts.map(({type,value})=>[type,value]));
  return {date:`${p.year}-${p.month}-${p.day}`,minute:Number(p.hour)*60+Number(p.minute)};
}
/** Scheduled reporting window only, independent of whether a NAV has arrived. */
export function getFundWindowState(instrument, nowMs = Date.now()) {
  if (instrument?.type !== 'Fund' || instrument.currency !== 'EUR'
    || instrument.contributionSchedule !== 'bnd_daily_amsterdam' || !Number.isFinite(nowMs)) return 'unknown';
  try {
    const clock = amsterdamClock(nowMs), weekday = new Date(`${clock.date}T00:00:00Z`).getUTCDay();
    return weekday !== 0 && weekday !== 6 && clock.minute >= 9*60 && clock.minute < 17*60+30 ? 'open' : 'closed';
  } catch { return 'unknown'; }
}
function assignedDay(firstObservedAt) {
  const clock = amsterdamClock(firstObservedAt);
  const day = new Date(`${clock.date}T00:00:00Z`);
  if (clock.minute >= 17*60+30) day.setUTCDate(day.getUTCDate()+1);
  while ([0,6].includes(day.getUTCDay())) day.setUTCDate(day.getUTCDate()+1);
  return day.toISOString().slice(0,10);
}
export function normalizeFundContribution(raw, valuationDate) {
  // A retained receipt proves timing even when the current price/date is missing.
  // A different present valuation date must never borrow that earlier window.
  if (!raw || (valuationDate != null && raw.valuationDate !== valuationDate) || !date(raw.valuationDate) || !date(raw.windowDate)
    || typeof raw.firstObservedAt !== 'string' || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/.test(raw.firstObservedAt)) return null;
  const observed = Date.parse(raw.firstObservedAt);
  if (!Number.isFinite(observed) || new Date(observed).toISOString().replace('.000Z','Z') !== raw.firstObservedAt) return null;
  try {
    if (raw.valuationDate > amsterdamClock(observed).date || assignedDay(observed) !== raw.windowDate) return null;
  } catch { return null; }
  return {valuationDate:raw.valuationDate,firstObservedAt:raw.firstObservedAt,windowDate:raw.windowDate};
}
export function getFundContribution(instrument, quote, nowMs = Date.now()) {
  const unavailable = {active:false,windowDate:null};
  if (instrument?.type !== 'Fund' || instrument.currency !== 'EUR'
    || instrument.contributionSchedule !== 'bnd_daily_amsterdam' || !Number.isFinite(nowMs)) return unavailable;
  const record = normalizeFundContribution(quote?.fundContribution,quote?.date);
  if (!record || Date.parse(record.firstObservedAt) > nowMs) return unavailable;
  try {
    const clock = amsterdamClock(nowMs);
    return {active:clock.date === record.windowDate && clock.minute >= 9*60 && clock.minute < 17*60+30,
      windowDate:record.windowDate};
  } catch { return unavailable; }
}
