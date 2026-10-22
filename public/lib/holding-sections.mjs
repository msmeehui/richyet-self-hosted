import {getExchangeSession} from './exchange-status.mjs';
import {formatClosingDate, formatPriceTime} from './dates.mjs';
import {getFundContribution} from './fund-contribution.mjs';
import {selectComparisonEntries} from './comparison-modes.mjs';

/** Display projection only: retain the one saved order and every source quote.
 * Active withholds a known-open holding's prior-session result using the same
 * current-session selection as the top subtotal. All holdings and the daily
 * details in Since purchase retain the original dated pair. Value and purchase
 * cost inputs are untouched. Active projects only eligible holdings; the other
 * modes retain the complete saved order. Valid current-session pairs remain
 * visible through ordinary refresh failures, with freshness reported separately.
 */
export function selectHoldingSections(entries, nowMs = Date.now(), value = 'active', tracker = null, reporting = null) {
  const {mode, comparisonEntries, activeEntries, openMarkets} = selectComparisonEntries(entries,value,nowMs,tracker,reporting);
  const comparisons = new Map(comparisonEntries.map(entry=>[entry.holding.id,entry]));
  const displayEntries = entries.map(entry => {
    const sessionState = getExchangeSession(entry?.instrument,nowMs,entry?.quote).state;
    const activeFund = sessionState === 'fund' && getFundContribution(entry.instrument,entry.quote,nowMs).active;
    const comparisonEntry = mode !== 'active' || sessionState === 'open' || activeFund
      ? comparisons.get(entry.holding.id) ?? {...entry,quote:{...entry.quote,previousClose:null,previousFx:null}} : entry;
    return {...entry,sessionState,activeFund,comparisonQuote:comparisonEntry?.quote,
      waitingForCurrentSession:mode === 'active' && openMarkets.waitingInstrumentIds.includes(entry.holding.instrumentId)};
  });
  const activeIds = new Set(activeEntries.map(entry=>entry.holding.id));
  const active = displayEntries.filter(entry=>activeIds.has(entry.holding.id));
  const inactive = displayEntries.filter(entry=>!activeIds.has(entry.holding.id));
  return {mode,entries:displayEntries,visibleEntries:mode === 'active' ? active : displayEntries,comparisonEntries,openMarkets,sections:[
    {id:'open',title:active.some(entry=>entry.activeFund) ? 'Open markets & daily funds' : 'Open markets',entries:active},
    {id:'closed',title:inactive.some(entry=>entry.sessionState !== 'closed') ? 'Closed markets & other holdings' : 'Closed markets',entries:inactive},
  ].filter(section=>section.entries.length > 0)};
}

/** Actual source time in the viewer's timezone, or its explicit civil date.
 * NAV dates and retained US daily bars have no verified trade/auction instant.
 * Neither retrieval time nor the scheduled exchange close supplies one.
 */
export function formatHoldingObservation(instrument, quote, nowMs = Date.now(), timeZone = undefined) {
  if (!instrument || !quote || !Number.isFinite(nowMs)) return '';
  try {
    const current = new Date(nowMs);
    const dayFormat = new Intl.DateTimeFormat('en-GB',{year:'numeric',month:'2-digit',day:'2-digit',timeZone});
    const currentYear = dayFormat.formatToParts(current).find(part => part.type === 'year').value;
    const dateOnly = instrument.type === 'Fund' || (instrument.closeSchedule === 'us_retained_calendar'
      && (quote.priceKind === 'close' || quote.observationKind === 'close'));
    if (!dateOnly && formatPriceTime(quote.observedAt,timeZone) !== 'Unavailable') {
      const at = new Date(quote.observedAt);
      if (at.getTime() <= nowMs) {
        if (dayFormat.format(at) === dayFormat.format(current)) {
          return new Intl.DateTimeFormat('en-GB',{hour:'2-digit',minute:'2-digit',hourCycle:'h23',timeZone}).format(at);
        }
        const year = dayFormat.formatToParts(at).find(part => part.type === 'year').value;
        return new Intl.DateTimeFormat('en-GB',{day:'numeric',month:'short',
          ...(year === currentYear ? {} : {year:'numeric'}),timeZone}).format(at);
      }
    }
    const date = formatClosingDate(quote.date);
    return date === 'Unavailable' ? '' : date.replace(new RegExp(` ${currentYear}$`),'');
  } catch { return ''; }
}
