import {getExchangeSession, selectOpenMarketEntries} from './exchange-status.mjs';
import {selectReadyActiveEntries} from './active-readiness.mjs';
import {getFundWindowState} from './fund-contribution.mjs';

const STORAGE_KEY = 'richyet-comparison-mode';
const PREFERENCES_KEY = 'richyet-comparison-preferences';

/** Independent comparison/filter preferences. Only nonfinancial UI choices. */
export function normalizeComparisonPreferences(value) {
  return {mode:value?.mode === 'purchase' ? 'purchase' : 'latest',activeOnly:value?.activeOnly === true};
}

export function comparisonModeForPreferences(value) {
  const {mode,activeOnly} = normalizeComparisonPreferences(value);
  return mode === 'purchase' ? 'purchase' : activeOnly ? 'active' : 'all';
}

/** Temporary display overrides never overwrite the saved filter preference. */
export function comparisonModeForHoldings(value, count, allClosed = false) {
  const mode = normalizeComparisonMode(value);
  return mode === 'active' && (count === 1 || allClosed) ? 'all' : mode;
}

/** Missing prices or unknown schedules cannot establish that markets are closed.
 * The daily fund uses its scheduled reporting window, not its NAV readiness.
 */
export function areAllMarketsClosed(entries, nowMs = Date.now()) {
  return entries.length > 0 && Number.isFinite(nowMs) && entries.every(({instrument,quote}) =>
    instrument?.type === 'Fund' ? getFundWindowState(instrument,nowMs) === 'closed'
      : getExchangeSession(instrument,nowMs,quote).state === 'closed');
}

/** Opening hours alone cannot make a readiness filter redundant. Complete
 * retained comparisons count; freshness warnings are independent of this control.
 * A missing first or later comparison still keeps the filter available.
 */
export function comparisonFilterState(entries, selection, nowMs = Date.now()) {
  if (!entries.length) return {kind:'hidden',text:''};
  if (entries.length === 1) {
    const state = getExchangeSession(entries[0].instrument,nowMs,entries[0].quote).state;
    return {kind:'status',text:state === 'fund' ? 'Daily value' : state === 'open' ? 'Market open'
      : state === 'closed' ? 'Market closed' : 'Market hours unavailable'};
  }
  if (areAllMarketsClosed(entries,nowMs)) return {kind:'status',text:'All markets closed'};
  const markets = selection.openMarkets;
  const allReady = selection.activeEntries.length === entries.length
    && selection.completeActiveCount === entries.length && markets.unknownCount === 0
    && markets.openCount + markets.activeFundCount === entries.length;
  return allReady ? {kind:'status',text:'All markets open'} : {kind:'checkbox',text:''};
}

export function readComparisonPreferences(storage = browserStorage()) {
  try {
    const saved = storage?.getItem(PREFERENCES_KEY);
    if (saved != null) {
      if (saved.length > 200) return normalizeComparisonPreferences(null);
      const value = JSON.parse(saved);
      return normalizeComparisonPreferences(value?.version === 1 ? value : null);
    }
    const legacy = storage?.getItem(STORAGE_KEY);
    return normalizeComparisonPreferences({mode:legacy === 'purchase' ? 'purchase' : 'latest',activeOnly:['active','today','latest'].includes(legacy)});
  } catch { return normalizeComparisonPreferences(null); }
}

export function writeComparisonPreferences(value,storage = browserStorage()) {
  try {
    if (!storage || typeof storage.setItem !== 'function') return false;
    storage.setItem(PREFERENCES_KEY,JSON.stringify({version:1,...normalizeComparisonPreferences(value)}));
    return true;
  } catch { return false; }
}

/** Legacy daily selections keep their existing Active meaning. */
export function normalizeComparisonMode(value) {
  return value === 'all' || value === 'purchase' ? value : 'active';
}

function browserStorage() {
  try { return globalThis.localStorage; } catch { return null; }
}

/** Only a presentation preference is stored; no holdings or market data. */
export function readComparisonMode(storage = browserStorage(), key = STORAGE_KEY) {
  try { return normalizeComparisonMode(storage?.getItem(key)); } catch { return 'active'; }
}

export function writeComparisonMode(value, storage = browserStorage(), key = STORAGE_KEY) {
  try {
    if (!storage || typeof storage.setItem !== 'function') return false;
    storage.setItem(key, normalizeComparisonMode(value));
    return true;
  } catch { return false; }
}

function datedComparison(entry, nowMs) {
  const quote = entry?.quote, instrument = entry?.instrument;
  let safe = Number.isFinite(nowMs);
  if (safe && quote?.observedAt != null) {
    const observed = Date.parse(quote.observedAt);
    safe = Number.isFinite(observed) && observed <= nowMs;
  }
  if (safe && quote?.date) {
    try {
      const timeZone = instrument?.timeZone
        ?? (instrument?.contributionSchedule === 'bnd_daily_amsterdam' ? 'Europe/Amsterdam' : 'UTC');
      const parts = new Intl.DateTimeFormat('en-GB',{timeZone,year:'numeric',month:'2-digit',day:'2-digit'}).formatToParts(nowMs);
      const local = Object.fromEntries(parts.map(({type,value})=>[type,value]));
      safe = quote.date <= `${local.year}-${local.month}-${local.day}`;
    } catch { safe = false; }
  }
  return safe ? entry : {...entry,quote:{...quote,previousClose:null,previousFx:null}};
}

/** Quotes have already passed market normalization. All holdings selects the
 * original dated pairs, never an older quote or an invented replacement close.
 * Keep unavailable entries in the selection so the subtotal reports coverage.
 * Purchase totals use calculatePortfolio; their opposite daily details use all.
 */
export function selectComparisonEntries(entries, value = 'active', nowMs = Date.now(), tracker = null, reporting = null) {
  if (!Array.isArray(entries)) throw new TypeError('Portfolio entries must be an array.');
  const mode = normalizeComparisonMode(value);
  const openMarkets = selectOpenMarketEntries(entries, nowMs);
  const active = selectReadyActiveEntries(openMarkets.comparisonEntries,nowMs,tracker,reporting);
  const activeIds = new Set(active.comparisonEntries.map(entry=>entry.holding.id));
  const awaitingEntries = openMarkets.comparisonEntries.filter(entry=>!activeIds.has(entry.holding.id)
    && getExchangeSession(entry.instrument,nowMs,entry.quote).state === 'open');
  const referenceEntries = entries.map(entry=>datedComparison(entry,nowMs));
  return {mode, referenceEntries, comparisonEntries:mode === 'active' ? active.comparisonEntries : referenceEntries,
    activeEntries:active.comparisonEntries,completeActiveCount:active.completeCount,
    awaitingEntries,failedInstrumentIds:active.failedInstrumentIds,openMarkets};
}
