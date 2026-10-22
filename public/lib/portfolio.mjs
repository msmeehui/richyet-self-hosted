/**
 * Current-position valuation, not transaction returns. Monetary arithmetic stays
 * exact until each public presentation result is converted to a Number.
 * Quote adapters own session selection, split compatibility and FX provenance.
 */

import {portfolioCurrency, reportingExchangeRate} from './reporting-currency.mjs';
import {normalizeNumberFormat, parseLocalizedNumber} from './number-format.mjs';

const ZERO = Object.freeze({ n: 0n, d: 1n });
const ONE = Object.freeze({ n: 1n, d: 1n });
const HUNDRED = Object.freeze({ n: 100n, d: 1n });
const MAX_INPUT_LENGTH = 120;
const QUOTE_STATUSES = new Set(['delayed', 'closed', 'nav', 'stale']);

/** Backward-compatible Dutch input; new editors select their locale explicitly. */
export function parseDutchNumber(text, options = {}) {
  return parseLocalizedNumber(text,{...options,numberFormat:'nl-NL'});
}

function gcd(a, b) {
  a = a < 0n ? -a : a;
  while (b !== 0n) [a, b] = [b, a % b];
  return a;
}

function rational(n, d = 1n) {
  if (n === 0n) return ZERO;
  if (d < 0n) { n = -n; d = -d; }
  const factor = gcd(n, d);
  return { n: n / factor, d: d / factor };
}

/** Market/storage decimals use '.', never grouping, exponents or JS coercion. */
function decimal(value, { positive = false } = {}) {
  if (typeof value !== 'string' || value.length > MAX_INPUT_LENGTH ||
      !/^(?:0|[1-9]\d*)(?:\.\d+)?$/.test(value)) return null;
  const [whole, fraction = ''] = value.split('.');
  const result = rational(BigInt(whole + fraction), 10n ** BigInt(fraction.length));
  return positive && result.n === 0n ? null : result;
}

function add(a, b) { return rational(a.n * b.d + b.n * a.d, a.d * b.d); }
function subtract(a, b) { return rational(a.n * b.d - b.n * a.d, a.d * b.d); }
function multiply(a, b) { return rational(a.n * b.n, a.d * b.d); }
function exchangeRate(value) {
  if (typeof value === 'string') return decimal(value, {positive:true});
  if (!value || typeof value !== 'object' || Array.isArray(value)) return null;
  const numerator = decimal(value.numerator, {positive:true});
  const denominator = decimal(value.denominator, {positive:true});
  return numerator && denominator
    ? rational(numerator.n * denominator.d, numerator.d * denominator.n) : null;
}
function percent(change, basis) {
  if (change === null || basis === null || basis.n <= 0n) return null;
  return multiply(rational(change.n * basis.d, change.d * basis.n), HUNDRED);
}

/** Convert only at the public boundary; avoid overflowing intermediate Numbers. */
function presentation(value) {
  if (value === null) return null;
  if (value.n === 0n) return 0;
  const negative = value.n < 0n;
  const numerator = negative ? -value.n : value.n;
  let exponent = numerator.toString().length - value.d.toString().length;
  if (exponent >= 0) {
    if (numerator < value.d * 10n ** BigInt(exponent)) exponent -= 1;
  } else if (numerator * 10n ** BigInt(-exponent) < value.d) exponent -= 1;
  // Twenty significant decimal digits preserve more than Number's precision.
  // This conversion happens after exact arithmetic and aggregation are complete.
  const shift = 19 - exponent;
  const digits = shift >= 0
    ? numerator * 10n ** BigInt(shift) / value.d
    : numerator / (value.d * 10n ** BigInt(-shift));
  const result = Number(`${negative ? '-' : ''}${digits}e${-shift}`);
  return Number.isFinite(result) ? (Object.is(result, -0) ? 0 : result) : null;
}

function dateIsValid(value) {
  if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return false;
  const timestamp = Date.parse(`${value}T00:00:00Z`);
  return Number.isFinite(timestamp) && new Date(timestamp).toISOString().slice(0, 10) === value;
}

function calculateExact(holding = {}, instrument = {}, quote = {}, reporting = null) {
  holding ??= {};
  instrument ??= {};
  quote ??= {};
  const quantity = decimal(holding.quantity, { positive: true });
  const identityMatches = typeof instrument.id === 'string' && instrument.id.length > 0 &&
    holding.instrumentId === instrument.id && /^[A-Z]{3}$/.test(instrument.currency ?? '');
  const usable = identityMatches && quantity !== null &&
    QUOTE_STATUSES.has(quote.status) && dateIsValid(quote.date);
  const comparisonDated = usable && dateIsValid(quote.previousDate) && quote.previousDate < quote.date;
  const price = usable ? decimal(quote.price) : null;
  const previous = comparisonDated ? decimal(quote.previousClose) : null;
  const currency = portfolioCurrency(reporting);
  // Preserve the existing EUR series; additional reporting currencies use the
  // explicitly dated reference-rate series, never a twice-rounded EUR value.
  const fx = currency === 'EUR'
    ? instrument.currency === 'EUR' ? ONE : exchangeRate(quote.fx)
    : exchangeRate(reportingExchangeRate(instrument.currency, reporting));
  const previousFx = currency === 'EUR'
    ? instrument.currency === 'EUR' ? ONE : exchangeRate(quote.previousFx)
    : exchangeRate(reportingExchangeRate(instrument.currency, reporting, quote.previousDate));
  const value = price !== null && fx !== null ? multiply(multiply(quantity, price), fx) : null;
  const baseline = previous !== null && previousFx !== null
    ? multiply(multiply(quantity, previous), previousFx) : null;
  const change = value !== null && baseline !== null ? subtract(value, baseline) : null;
  const averageCost = decimal(holding.averageCost);
  const localCost = quantity !== null && averageCost !== null ? multiply(quantity, averageCost) : null;
  const costIdentity = identityMatches && quantity !== null;
  const savedEuroCost = costIdentity ? decimal(holding.eurCost) : null;
  let actualPurchaseCost = null, acquisitionCost = null, purchaseCostEstimated = false;
  if (currency === 'EUR') {
    actualPurchaseCost = costIdentity
      ? (instrument.currency === 'EUR' ? localCost : savedEuroCost) : null;
    // With no entered EUR cost, convert both local endpoints with the current
    // rate. This is an estimate, never a historical FX claim.
    purchaseCostEstimated = costIdentity && instrument.currency !== 'EUR'
      && holding.eurCost == null && localCost !== null && value !== null;
    acquisitionCost = purchaseCostEstimated ? multiply(localCost, fx) : actualPurchaseCost;
  } else if (costIdentity && instrument.currency === currency && localCost !== null) {
    // A purchase price in the same currency needs no FX, even if the user also
    // saved a euro cost for the EUR report.
    actualPurchaseCost = acquisitionCost = localCost;
  } else if (costIdentity && value !== null) {
    if (localCost !== null && fx !== null) {
      acquisitionCost = multiply(localCost, fx);
    } else if (savedEuroCost !== null) {
      // Preserve a usable estimate when only an original euro total is known.
      // This does not reconstruct the target-currency cost at purchase time.
      const euroRate = exchangeRate(reportingExchangeRate('EUR', reporting));
      if (euroRate !== null) acquisitionCost = multiply(savedEuroCost, euroRate);
    }
    purchaseCostEstimated = acquisitionCost !== null;
  }
  const purchaseGain = value !== null && acquisitionCost !== null ? subtract(value, acquisitionCost) : null;
  const localPurchaseGain = price !== null && localCost !== null
    ? subtract(multiply(quantity, price), localCost) : null;
  return {
    value, baseline, change,
    changePercent: percent(change, baseline),
    actualPurchaseCost,
    purchaseCost: acquisitionCost,
    purchaseCostEstimated,
    purchaseGain,
    purchaseGainEstimated: purchaseCostEstimated && purchaseGain !== null,
    purchaseGainPercent: percent(purchaseGain, acquisitionCost),
    localChangePercent: price !== null && previous !== null ? percent(subtract(price, previous), previous) : null,
    localPurchaseGain,
    localPurchaseGainPercent: percent(localPurchaseGain, localCost),
    stale: quote.status === 'stale',
  };
}

export function calculateHolding(holding, instrument, quote, reporting = null) {
  const exact = calculateExact(holding, instrument, quote, reporting);
  const result = Object.fromEntries(Object.entries(exact).map(([key, value]) =>
    [key, typeof value === 'boolean' ? value : presentation(value)]));
  result.purchaseCostEstimated &&= result.purchaseCost !== null;
  result.purchaseGainEstimated &&= result.purchaseGain !== null;
  result.completeValue = result.value !== null;
  result.completeChange = result.value !== null && result.baseline !== null && result.change !== null;
  return result;
}

/** Select a holding's purchase result in the chosen reporting currency. */
export function purchaseComparison(holding = {}, instrument = {}, calculation = {}, reporting = null) {
  holding ??= {};
  instrument ??= {};
  calculation ??= {};
  const currency = portfolioCurrency(reporting);
  const usesAverageCost = currency !== 'EUR' || instrument.currency === 'EUR' || holding.eurCost == null;
  const amount = calculation.purchaseGain;
  const percentage = calculation.purchaseGainPercent;
  const cost = currency !== 'EUR' && holding.averageCost == null ? holding.eurCost
    : usesAverageCost ? holding.averageCost : holding.eurCost;
  const shownAmount = displayable(amount) ? amount : null;
  const estimated = shownAmount !== null && calculation.purchaseGainEstimated === true;
  return {
    amount: shownAmount,
    percentage: shownAmount !== null && displayable(percentage) ? percentage : null,
    currency,
    estimated,
    excludesCurrencyChanges: estimated,
    missingCost: currency === 'EUR' ? decimal(cost) === null
      : decimal(holding.averageCost) === null && decimal(holding.eurCost) === null,
  };
}

/** Invalid/missing holdings prevent a full total, while knownValue stays explicit. */
export function calculatePortfolio(entries, reporting = null) {
  if (!Array.isArray(entries)) throw new TypeError('Portfolio entries must be an array.');
  const rows = entries.map(entry => calculateExact(entry?.holding, entry?.instrument, entry?.quote, reporting));
  const known = rows.filter(row => row.value !== null && presentation(row.value) !== null);
  const completeValue = known.length === rows.length;
  const allBaselines = rows.every(row => row.baseline !== null && presentation(row.baseline) !== null);
  const valueExact = known.reduce((sum, row) => add(sum, row.value), ZERO);
  const baselineExact = allBaselines ? rows.reduce((sum, row) => add(sum, row.baseline), ZERO) : null;
  const completeChange = completeValue && allBaselines;
  const changeExact = completeChange ? subtract(valueExact, baselineExact) : null;
  const value = completeValue ? presentation(valueExact) : null;
  const baseline = presentation(baselineExact);
  const change = presentation(changeExact);
  const allPurchaseCosts = rows.every(row => row.purchaseCost !== null && presentation(row.purchaseCost) !== null);
  const purchaseCostExact = allPurchaseCosts ? rows.reduce((sum, row) => add(sum, row.purchaseCost), ZERO) : null;
  const purchaseCost = presentation(purchaseCostExact);
  const completePurchaseCost = allPurchaseCosts && purchaseCost !== null;
  const actualPurchaseCost = rows.every(row => row.actualPurchaseCost !== null)
    ? presentation(rows.reduce((sum, row) => add(sum, row.actualPurchaseCost), ZERO)) : null;
  const purchaseCostEstimated = completePurchaseCost && rows.some(row => row.purchaseCostEstimated);
  // A purchase comparison concerns the entire current portfolio. Never silently
  // omit a holding with unknown original cost or missing current valuation.
  const purchaseGainExact = rows.length > 0 && completeValue && value !== null && completePurchaseCost
    ? subtract(valueExact, purchaseCostExact) : null;
  const purchaseGain = presentation(purchaseGainExact);
  return {
    value,
    knownValue: known.length || rows.length === 0 ? presentation(valueExact) : null,
    baseline,
    change,
    changePercent: completeChange ? presentation(percent(changeExact, baselineExact)) : null,
    actualPurchaseCost,
    purchaseCost,
    purchaseCostEstimated,
    purchaseGain,
    purchaseGainEstimated: purchaseGain !== null && purchaseCostEstimated,
    purchaseGainPercent: purchaseGain !== null ? presentation(percent(purchaseGainExact, purchaseCostExact)) : null,
    completePurchaseCost,
    completePurchaseGain: purchaseGain !== null,
    completeValue: completeValue && value !== null,
    completeChange: completeChange && value !== null && baseline !== null && change !== null,
    stale: rows.some(row => row.stale),
    count: rows.length,
  };
}

/** Explicit available-only Latest subtotal; never a complete portfolio return.
 * Both endpoints and the percentage use the same usable comparison rows.
 * Adapters still own quote eligibility, split checks and dated FX provenance.
 */
export function calculateLatestSubtotal(entries, reporting = null) {
  if (!Array.isArray(entries)) throw new TypeError('Portfolio entries must be an array.');
  const included = [], missingInstrumentIds = new Set();
  for (const entry of entries) {
    const row = calculateExact(entry?.holding, entry?.instrument, entry?.quote, reporting);
    if ([row.value, row.baseline, row.change].every(value => presentation(value) !== null)) {
      included.push(row);
    } else {
      const id = entry?.holding?.instrumentId;
      if (typeof id === 'string' && id.length > 0) missingInstrumentIds.add(id);
    }
  }
  const valueExact = included.reduce((sum, row) => add(sum, row.value), ZERO);
  const baselineExact = included.reduce((sum, row) => add(sum, row.baseline), ZERO);
  const changeExact = subtract(valueExact, baselineExact);
  const value = presentation(valueExact), baseline = presentation(baselineExact), change = presentation(changeExact);
  const available = included.length > 0 && value !== null && baseline !== null && change !== null;
  return {
    value: available ? value : null,
    baseline: available ? baseline : null,
    change: available ? change : null,
    changePercent: available ? presentation(percent(changeExact, baselineExact)) : null,
    includedCount: included.length,
    totalCount: entries.length,
    missingInstrumentIds: [...missingInstrumentIds],
    partial: included.length > 0 && included.length < entries.length,
    stale: included.some(row => row.stale),
  };
}

/** Selected movements as a contribution to the entire portfolio. The reference
 * uses every holding's dated baseline, including those outside the filter.
 * An incomplete reference never becomes a smaller, available-only denominator.
 */
export function calculateLatestContribution(entries, referenceEntries, reporting = null) {
  if (!Array.isArray(referenceEntries)) throw new TypeError('Portfolio reference entries must be an array.');
  const subtotal = calculateLatestSubtotal(entries, reporting);
  const referenceRows = referenceEntries.map(entry => calculateExact(entry?.holding, entry?.instrument, entry?.quote, reporting));
  const missingReferenceInstrumentIds = [...new Set(referenceEntries.filter((entry,index) =>
    presentation(referenceRows[index].baseline) === null).map(entry => entry?.holding?.instrumentId).filter(id => typeof id === 'string'))];
  const completeReference = referenceRows.length > 0 && referenceRows.every(row => presentation(row.baseline) !== null);
  const referenceExact = completeReference ? referenceRows.reduce((sum,row) => add(sum,row.baseline),ZERO) : null;
  const referenceBaseline = presentation(referenceExact);
  const selectedRows = entries.map(entry => calculateExact(entry?.holding,entry?.instrument,entry?.quote, reporting))
    .filter(row => [row.value,row.baseline,row.change].every(value => presentation(value) !== null));
  const changeExact = selectedRows.reduce((sum,row) => add(sum,row.change),ZERO);
  return {...subtotal,
    changePercent: subtotal.includedCount > 0 ? presentation(percent(changeExact,referenceExact)) : null,
    referenceBaseline,
    completeReference: completeReference && referenceBaseline !== null,
    missingReferenceInstrumentIds,
  };
}

function displayable(value) { return typeof value === 'number' && Number.isFinite(value); }
function roundedZero(value, digits) {
  return Math.abs(value) < 0.5 * 10 ** -digits ? 0 : value;
}

export function formatMoney(value, currency = 'EUR', { signed = false, fractionDigits = 2, numberFormat = 'nl-NL' } = {}) {
  if (!Number.isInteger(fractionDigits) || fractionDigits < 0 || fractionDigits > 6) {
    throw new RangeError('Money fractionDigits must be an integer from 0 to 6.');
  }
  if (!displayable(value)) return '—';
  return new Intl.NumberFormat(normalizeNumberFormat(numberFormat), {
    style: 'currency', currency,
    minimumFractionDigits: fractionDigits, maximumFractionDigits: fractionDigits,
    roundingMode: 'halfExpand',
    signDisplay: signed ? 'exceptZero' : 'auto',
  }).format(roundedZero(value, fractionDigits));
}

/** Values are percentage points: 1.5 formats as +1,50%, not +150%. */
export function formatPercent(value, { signed = true, numberFormat = 'nl-NL' } = {}) {
  if (!displayable(value)) return '—';
  return new Intl.NumberFormat(normalizeNumberFormat(numberFormat), {
    minimumFractionDigits: 2, maximumFractionDigits: 2,
    signDisplay: signed ? 'exceptZero' : 'auto',
  }).format(roundedZero(value, 2)) + '%';
}

export function formatQuantity(value, {numberFormat = 'nl-NL'} = {}) {
  // Canonical strings avoid reinterpreting saved '.' decimals as Dutch grouping.
  const number = typeof value === 'string' ? presentation(decimal(value)) : value;
  if (!displayable(number)) return '—';
  return new Intl.NumberFormat(normalizeNumberFormat(numberFormat), {
    maximumFractionDigits: 6,
  }).format(roundedZero(number, 6));
}
