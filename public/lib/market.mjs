import {formatPriceTime, formatClosingDate, formatValuationDate} from './dates.mjs';
import {approximateClosingInstant, hasReviewedEuropeanClose, isRetainedUSClose, formatApproximateClosingTime} from './closing-times.mjs';
import {normalizeFundContribution} from './fund-contribution.mjs';
import {normalizeUSSessionCalendar} from './market-calendars.mjs';

// Authenticated source observations stay in memory. This layer does not fetch providers.
const identifier = /^[\w-]{1,60}$/;
const validId = value => typeof value === 'string' && identifier.test(value);
const statuses = new Set(['delayed', 'closed', 'nav', 'stale', 'missing']);
const decimal = value => typeof value === 'string' && value.length <= 120 && /^(?:0|[1-9]\d*)(?:\.\d+)?$/.test(value);
const date = value => typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value)
  && Number.isFinite(Date.parse(value + 'T00:00:00Z')) && new Date(value + 'T00:00:00Z').toISOString().slice(0, 10) === value;
const instant = value => typeof value === 'string' && /^\d{4}-\d{2}-\d{2}T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d+)?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/.test(value)
  && date(value.slice(0, 10)) && Number.isFinite(Date.parse(value));
const rate = value => decimal(value) && /[1-9]/.test(value) || Boolean(value && typeof value === 'object'
  && decimal(value.numerator) && /[1-9]/.test(value.numerator) && decimal(value.denominator) && /[1-9]/.test(value.denominator));

function normalizeAdmission(raw, id) {
  const unavailable = {instrumentId:id, capability:'unverified', status:'unverified', canAdd:false,
    currentReady:false, latestReady:false, reasons:['identity_unverified']};
  if (!raw || raw.instrumentId !== id || !['supported', 'unsupported', 'unverified'].includes(raw.capability)
    || !['ready', 'temporarily_unavailable', 'unsupported', 'unverified'].includes(raw.status)
    || !['canAdd', 'currentReady', 'latestReady'].every(key => typeof raw[key] === 'boolean')
    || !Array.isArray(raw.reasons) || raw.reasons.length > 40
    || !raw.reasons.every(reason => typeof reason === 'string' && /^[a-z_]{1,60}$/.test(reason))
    || (raw.latestReady && !raw.currentReady)
    || (raw.canAdd !== (raw.status === 'ready'))
    || (raw.canAdd && (raw.capability !== 'supported' || !raw.latestReady))) return unavailable;
  return {instrumentId:id, capability:raw.capability, status:raw.status, canAdd:raw.canAdd,
    currentReady:raw.currentReady, latestReady:raw.latestReady, reasons:[...raw.reasons]};
}

export function normalizeInstruments(instruments) {
  if (!Array.isArray(instruments)) throw new Error('The investment list could not be read.');
  const ids = new Set();
  return instruments.filter(item => item && validId(item.id) && !ids.has(item.id) && ids.add(item.id)).map(item => {
    const admission = normalizeAdmission(item.admission, item.id);
    return {
      ...item,
      name: typeof item.name === 'string' && item.name.trim() ? item.name : 'Unavailable investment',
      symbol: typeof item.symbol === 'string' ? item.symbol : item.id,
      exchange: typeof item.exchange === 'string' ? item.exchange : 'Listing unavailable',
      currency: typeof item.currency === 'string' && /^[A-Z]{3}$/.test(item.currency) ? item.currency : null,
      type: ['Stock', 'ETF', 'Fund'].includes(item.type) ? item.type : 'Investment',
      admission, canAdd:admission.canAdd,
    };
  });
}

export function unavailableInstrument(id) {
  return {id, name:'Unavailable investment', symbol:id, exchange:'Listing unavailable', currency:null, type:'Investment', canAdd:false};
}

export function canAddInstrument(instrument) {
  if (instrument.admission) return Boolean(instrument.currency) && instrument.admission.canAdd === true
    && instrument.admission.status === 'ready' && instrument.admission.currentReady === true && instrument.admission.latestReady === true;
  // Only the explicit fictional catalogue bypasses live admission normalization.
  return Boolean(instrument.currency) && instrument.canAdd !== false
    && instrument.coverage?.price !== false && instrument.coverage?.price !== 'unsupported';
}

export function admissionMessage(instrument) {
  if (canAddInstrument(instrument)) return 'Price and latest comparison ready.';
  const admission = instrument.admission;
  if (admission?.status === 'unsupported') return 'This investment is not supported yet.';
  if (!admission || admission.status === 'unverified') return 'This listing’s data has not been verified yet.';
  const reasons = admission.reasons;
  if (reasons.some(reason => ['price_stale', 'price_unavailable', 'data_unavailable', 'current_invalidated'].includes(reason))) return 'Latest price unavailable. Please try again later.';
  if (reasons.some(reason => ['fx_unavailable', 'fx_comparison_unavailable'].includes(reason))) return 'Currency comparison unavailable. Please try again later.';
  return 'Latest comparison unavailable. Please try again later.';
}

export function normalizeMarketSnapshot(snapshot, instruments = []) {
  if (!snapshot || snapshot.dataMode !== 'live' || !snapshot.quotes || typeof snapshot.quotes !== 'object'
    || Array.isArray(snapshot.quotes) || !Array.isArray(snapshot.issues)) throw new Error('Market data could not be read.');
  const byId = new Map(instruments.map(item => [item.id, item]));
  const issues = snapshot.issues.filter(issue => issue && validId(issue.instrumentId) && typeof issue.code === 'string')
    .map(({instrumentId, code}) => ({instrumentId, code}));
  const quotes = Object.create(null);
  for (const [id, raw] of Object.entries(snapshot.quotes)) {
    if (!validId(id)) continue;
    if (!raw || typeof raw !== 'object' || !statuses.has(raw.status)) {
      quotes[id] = {status:'missing'};
      issues.push({instrumentId:id, code:'source_unavailable'});
      continue;
    }
    const q = {...raw};
    q.date = date(raw.date) ? raw.date : null;
    q.sessionCalendar = normalizeUSSessionCalendar(raw.sessionCalendar);
    q.fundContribution = normalizeFundContribution(raw.fundContribution,q.date);
    q.previousDate = date(raw.previousDate) && raw.previousDate < q.date ? raw.previousDate : null;
    q.observedAt = instant(raw.observedAt) ? raw.observedAt : null;
    q.previousObservedAt = instant(raw.previousObservedAt) ? raw.previousObservedAt : null;
    q.priceKind = ['trade', 'close', 'nav'].includes(raw.priceKind) ? raw.priceKind : null;
    const dateOnlyClose = ['Stock', 'ETF'].includes(byId.get(id)?.type) && q.priceKind === 'close' && raw.observedAt === null;
    q.price = decimal(raw.price) && q.date && (byId.get(id)?.type === 'Fund' || q.observedAt || dateOnlyClose) ? raw.price : null;
    q.previousClose = decimal(raw.previousClose) && q.previousDate ? raw.previousClose : null;
    q.fx = rate(raw.fx) ? raw.fx : null;
    q.previousFx = rate(raw.previousFx) ? raw.previousFx : null;
    if (q.status === 'missing') q.price = null;
    if (issues.some(issue => issue.instrumentId === id && ['corporate_action_unverified', 'history_basis_unverified'].includes(issue.code))
      || (Object.hasOwn(raw, 'basis') && !['same_units', 'split_adjusted'].includes(raw.basis))) {
      q.previousClose = null;
      q.previousFx = null;
    }
    if (Object.hasOwn(raw, 'invalidations')) {
      const valid = Array.isArray(raw.invalidations) && raw.invalidations.every(value => ['listing', 'current', 'comparison'].includes(value));
      if (!valid || raw.invalidations.includes('listing') || raw.invalidations.includes('current')) q.price = null;
      if (!valid || raw.invalidations.includes('listing') || raw.invalidations.includes('comparison')) {
        q.previousClose = null; q.previousFx = null;
      }
    }
    if (issues.some(issue => issue.instrumentId === id && issue.code === 'listing_unverified')) {
      q.price = null;
      q.previousClose = null;
    }
    if (raw.sourceAvailable === false) issues.push({instrumentId:id,code:'source_unavailable'});
    if (issues.some(issue => issue.instrumentId === id && ['price_stale', 'history_price_stale', 'fx_stale'].includes(issue.code)) && q.status !== 'missing') q.status = 'stale';
    quotes[id] = q;
  }
  return {...snapshot, quotes, issues};
}

export function normalizeCatalogue(response) {
  if (response?.dataMode !== 'live') throw new Error('The investment list could not be read.');
  const instruments = normalizeInstruments(response.instruments);
  const market = normalizeMarketSnapshot(response.market, instruments);
  // A contradictory or malformed ready payload must not expose personal-entry fields.
  for (const instrument of instruments.filter(canAddInstrument)) {
    const q = market.quotes[instrument.id];
    if (!q || q.price == null || q.previousClose == null || !/[1-9]/.test(q.previousClose) || ['missing', 'stale'].includes(q.status)
      || (instrument.currency !== 'EUR' && (q.fx == null || q.previousFx == null))) {
      throw new Error('The investment list and its prices could not be read together.');
    }
  }
  return {instruments, market};
}

// Discovery is not admission: even a "ready" search row must be prepared and
// returned with its coherent catalogue before personal-entry fields open.
export function normalizeSearchResults(response, offset = 0) {
  if (!response || !Array.isArray(response.instruments) || response.instruments.length > 100
    || response.offset !== offset || typeof response.hasMore !== 'boolean'
    || (response.hasMore && response.instruments.length === 0)) throw new Error('The search results could not be read.');
  const ids = new Set();
  for (const item of response.instruments) {
    if (!item || !validId(item.id) || ids.has(item.id)
      || !['ready', 'available', 'unavailable'].includes(item.preparationStatus)) throw new Error('The search results could not be read.');
    ids.add(item.id);
  }
  const instruments = normalizeInstruments(response.instruments).map(item => ({...item,
    message: typeof item.message === 'string' ? item.message.slice(0, 500) : null}));
  return {instruments, hasMore:response.hasMore, offset};
}

export function normalizePreparation(response, id) {
  if (!response || !['ready', 'unavailable', 'busy'].includes(response.status)) throw new Error('The investment check could not be read.');
  const catalogue = response.catalogue == null ? null : normalizeCatalogue(response.catalogue);
  if (response.status === 'ready' && (!catalogue || !catalogue.instruments.some(i => i.id === id && canAddInstrument(i)))) {
    throw new Error('The investment and its prices could not be read together.');
  }
  return {status:response.status, message:typeof response.message === 'string' ? response.message.slice(0, 500) : null, catalogue};
}

const issueText = {
  price_missing:'Price unavailable.', baseline_missing:'Previous closing value unavailable.',
  fx_missing:'Currency rate unavailable.', baseline_fx_missing:'Currency rate for the comparison date unavailable.',
  source_unavailable:'The data source could not be refreshed. Any displayed value uses its last available observation.',
  price_stale:'The price is out of date.', fx_stale:'The currency rate is out of date.',
  corporate_action_unverified:'Latest change is unavailable because price adjustments are unverified.',
  listing_unverified:'This listing has not been verified. Its value is unavailable.',
  source_unsupported:'Market data for this investment is not supported yet.',
};
issueText.history_source_unavailable = issueText.source_unavailable;
issueText.history_price_stale = issueText.price_stale;
issueText.history_baseline_missing = issueText.baseline_missing;
issueText.history_basis_unverified = issueText.corporate_action_unverified;

/** Dates describe the selected price pair, never the collector's refresh time. */
export function latestComparisonDates(instrument, quote, now = Date.now()) {
  if (quote.price == null || quote.previousClose == null || !date(quote.date)
    || !date(quote.previousDate) || quote.previousDate >= quote.date) return {row:'', detail:''};
  let previous = formatClosingDate(quote.previousDate);
  if (instrument.type !== 'Fund') {
    if (instant(quote.previousObservedAt)) {
      if (isRetainedUSClose(instrument,quote.previousObservedAt,quote.previousDate)) previous = formatApproximateClosingTime(quote.previousObservedAt);
      else if (hasReviewedEuropeanClose(instrument)) previous = formatPriceTime(quote.previousObservedAt);
    }
    else {
      const scheduled = approximateClosingInstant(instrument,quote.previousDate,now);
      if (scheduled) previous = formatApproximateClosingTime(scheduled);
    }
  }
  const detail = `Compared with ${previous}`;
  let today;
  try {
    // Without a verified listing timezone, retain the comparison date in details
    // but do not infer whether the source represents an earlier local day.
    if (typeof instrument.timeZone !== 'string' || !Number.isFinite(now)) return {row:'', detail};
    const parts = new Intl.DateTimeFormat('en-GB', {timeZone:instrument.timeZone,
      year:'numeric', month:'2-digit', day:'2-digit'}).formatToParts(new Date(now));
    const values = Object.fromEntries(parts.map(({type,value}) => [type,value]));
    today = `${values.year}-${values.month}-${values.day}`;
  } catch { return {row:'', detail}; }
  const short = new Intl.DateTimeFormat('en-GB', {day:'numeric', month:'short', timeZone:'UTC'})
    .format(new Date(`${quote.date}T00:00:00Z`));
  return {row:quote.date < today ? `As of ${short}` : '', detail};
}

/** A closing observation does not establish whether the market is open now. */
export function priceDateMessage(instrument, quote, now = Date.now()) {
  if (instrument.type === 'Fund') return date(quote.date) ? `Fund value dated ${formatValuationDate(quote.date)}` : 'Fund value unavailable';
  if (instant(quote.observedAt)) {
    const close = quote.priceKind === 'close' || quote.observationKind === 'close';
    if (close && instrument.closeSchedule === 'us_retained_calendar' && !isRetainedUSClose(instrument,quote.observedAt,quote.date))
      return date(quote.date) ? `Closing price dated ${formatClosingDate(quote.date)}` : 'Price unavailable';
    const formatted = close && isRetainedUSClose(instrument,quote.observedAt,quote.date)
      ? formatApproximateClosingTime(quote.observedAt) : formatPriceTime(quote.observedAt);
    return `${close ? 'Closing price' : 'Price'} as of ${formatted}`;
  }
  const scheduled = quote.priceKind === 'close' && quote.price != null ? approximateClosingInstant(instrument,quote.date,now) : null;
  if (scheduled) return `Closing price as of ${formatApproximateClosingTime(scheduled)}`;
  return quote.priceKind === 'close' && quote.price != null && date(quote.date)
    ? `Closing price dated ${formatClosingDate(quote.date)}` : 'Price unavailable';
}

/** Collection completeness and price age are independent warning causes. */
export function holdingFreshness(instrument, quote, issues = [], now = Date.now()) {
  const codes = new Set(issues.filter(issue => issue.instrumentId === instrument.id).map(issue => issue.code));
  const fxStale = codes.has('fx_stale');
  const stale = codes.has('price_stale') || codes.has('history_price_stale') || (quote.status === 'stale' && !fxStale);
  const age = instant(quote.observedAt) ? now - Date.parse(quote.observedAt) : NaN;
  const recent = stale && quote.status === 'stale' && quote.price != null && date(quote.date)
    && Number.isFinite(age) && age >= 0 && age <= 90 * 60 * 1000;
  const updateIncomplete = recent && quote.eligibilityIssue === 'incomplete_trade_collection';
  const updateFailed = recent && !updateIncomplete && quote.sourceAvailable === false
    && (codes.has('source_unavailable') || codes.has('history_source_unavailable'));
  return {priceStale:stale && !updateIncomplete && !updateFailed, fxStale, updateIncomplete, updateFailed};
}

export function holdingDataMessages(instrument, quote, calculation, issues = [], now = Date.now(), {suppressFreshness=false} = {}) {
  const codes = new Set(issues.filter(issue => issue.instrumentId === instrument.id).map(issue => issue.code));
  if (!instrument.currency) codes.add('listing_unverified');
  if (quote.price == null || quote.status === 'missing') codes.add('price_missing');
  else if (instrument.currency !== 'EUR' && quote.fx == null) codes.add('fx_missing');
  if (quote.price != null && !calculation.completeChange) {
    if (quote.previousClose == null) codes.add('baseline_missing');
    else if (instrument.currency !== 'EUR' && quote.previousFx == null) codes.add('baseline_fx_missing');
  }
  if (quote.status === 'stale' && !codes.has('price_stale') && !codes.has('fx_stale')) codes.add('price_stale');
  const freshness = holdingFreshness(instrument,quote,issues,now);
  if (suppressFreshness) for (const code of ['price_stale','history_price_stale','fx_stale','source_unavailable','history_source_unavailable']) codes.delete(code);
  return [...new Set([...codes].map(code => {
    if (freshness.updateIncomplete && ['price_stale','history_price_stale'].includes(code)) return 'The latest price update is incomplete. Showing the last available observation.';
    if (freshness.updateFailed && ['price_stale','history_price_stale','source_unavailable','history_source_unavailable'].includes(code)) return 'The latest data check could not be completed. Showing the last available observation.';
    return issueText[code];
  }).filter(Boolean))];
}
