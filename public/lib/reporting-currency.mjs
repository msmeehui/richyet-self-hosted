/** Daily reporting rates are shared market data, never stored holding inputs.
 * EUR keeps its existing quote-rate series. USD/GBP use one coherent ECB series.
 */
export const PORTFOLIO_CURRENCIES = Object.freeze(['EUR','USD','GBP']);

export function portfolioCurrency(context = null) {
  const currency = typeof context === 'string' ? context : context?.currency;
  return PORTFOLIO_CURRENCIES.includes(currency) ? currency : 'EUR';
}

const validDecimal = value => typeof value === 'string' && value.length <= 120
  && /^(?:0|[1-9]\d*)(?:\.\d+)?$/.test(value) && /[1-9]/.test(value);
const validDate = value => typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value)
  && Number.isFinite(Date.parse(value+'T00:00:00Z'))
  && new Date(value+'T00:00:00Z').toISOString().slice(0,10) === value;
const day = value => Date.parse(value+'T00:00:00Z');

function clock(context) {
  const now = context?.nowMs ?? Date.now();
  if (!Number.isFinite(now)) return null;
  try {
    const values = Object.fromEntries(new Intl.DateTimeFormat('en-GB',{
      timeZone:'Europe/Amsterdam',year:'numeric',month:'2-digit',day:'2-digit',
    }).formatToParts(now).map(({type,value})=>[type,value]));
    return {now,date:`${values.year}-${values.month}-${values.day}`};
  } catch { return null; }
}

function ratesAt(context, previousDate = null) {
  const data = context?.fx, at = clock(context);
  if (!at || data?.series !== 'ecb-reference-daily' || data?.base !== 'EUR') return null;
  if (previousDate == null) {
    const freshUntil = Date.parse(data.freshUntil), fetchedAt = Date.parse(data.fetchedAt);
    const current = data.current;
    if (!['ready','stale'].includes(data.status) || !Number.isFinite(freshUntil)
      || !Number.isFinite(fetchedAt) || fetchedAt > at.now || !validDate(current?.date)
      || current.date > at.date) return null;
    return current.rates;
  }
  if (!validDate(previousDate) || previousDate > at.date || !Array.isArray(data.history)
    || data.history.length > 100) return null;
  // Select the latest published value no later than the price's baseline date.
  // A missing/invalid latest row cannot silently fall back to an older rate.
  const candidates = data.history.filter(row => validDate(row?.date) && row.date <= previousDate)
    .sort((a,b)=>b.date.localeCompare(a.date));
  const selected = candidates[0];
  if (!selected || day(previousDate)-day(selected.date) > 7*86400000) return null;
  if (candidates.some(row => row.date === selected.date
    && JSON.stringify(row.rates) !== JSON.stringify(selected.rates))) return null;
  return selected.rates;
}

/** Exact target units / quoted unit. No floating-point division or inversion. */
export function reportingExchangeRate(sourceCurrency, context, previousDate = null) {
  const target = portfolioCurrency(context);
  if (!PORTFOLIO_CURRENCIES.includes(sourceCurrency)) return null;
  if (sourceCurrency === target) return '1';
  const rates = ratesAt(context,previousDate);
  const numerator = target === 'EUR' ? '1' : rates?.[target];
  const denominator = sourceCurrency === 'EUR' ? '1' : rates?.[sourceCurrency];
  return validDecimal(numerator) && validDecimal(denominator) ? {numerator,denominator} : null;
}

const legacyFxCodes = new Set(['fx_missing','baseline_fx_missing','fx_stale']);

/** Adapt only FX display metadata; listing/action/price invalidations survive.
 * Clearing an old EUR-FX stale marker requires independent live price proof.
 */
export function reportingQuote(instrument, quote = {}, issues = [], context = null) {
  if (portfolioCurrency(context) === 'EUR') return quote;
  const current = reportingExchangeRate(instrument?.currency,context);
  const previous = reportingExchangeRate(instrument?.currency,context,quote.previousDate);
  const own = issues.filter(issue=>issue.instrumentId === instrument?.id);
  const now = context?.nowMs ?? Date.now();
  const onlyFxStale = own.some(issue=>issue.code === 'fx_stale')
    && !own.some(issue=>!legacyFxCodes.has(issue.code));
  const priceFresh = Number.isInteger(quote.freshUntil) && quote.freshUntil*1000 > now;
  const recover = quote.status === 'stale' && onlyFxStale && priceFresh
    && quote.sourceAvailable !== false && current !== null;
  const status = recover ? quote.priceKind === 'nav' ? 'nav'
    : quote.priceKind === 'close' ? 'closed' : 'delayed' : quote.status;
  return {...quote,fx:current,previousFx:previous,status};
}

/** Replace EUR-only FX warnings with warnings for the selected report. */
export function reportingIssues(instrument, quote = {}, issues = [], context = null) {
  if (portfolioCurrency(context) === 'EUR') return issues;
  const result = issues.filter(issue=>issue.instrumentId !== instrument?.id || !legacyFxCodes.has(issue.code));
  if (reportingExchangeRate(instrument?.currency,context) === null) {
    result.push({instrumentId:instrument?.id,code:context?.fx?.status === 'stale' ? 'fx_stale' : 'fx_missing'});
  } else if (instrument?.currency !== portfolioCurrency(context) && (context?.fx?.status === 'stale' || Date.parse(context?.fx?.freshUntil) <= (context?.nowMs ?? Date.now()))) {
    result.push({instrumentId:instrument?.id,code:'fx_stale'});
  }
  if (validDate(quote.previousDate) && reportingExchangeRate(instrument?.currency,context,quote.previousDate) === null) {
    result.push({instrumentId:instrument?.id,code:'baseline_fx_missing'});
  }
  return result;
}
