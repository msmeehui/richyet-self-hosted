const UNAVAILABLE = 'Unavailable';

function civilDate(value) {
  if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return null;
  const date = new Date(`${value}T00:00:00Z`);
  return Number.isFinite(date.getTime()) && date.toISOString().slice(0, 10) === value ? date : null;
}

function instant(value) {
  // A source timestamp must identify an instant, never an assumed local time.
  if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d+)?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/.test(value) ||
      civilDate(value.slice(0, 10)) === null) return null;
  const date = new Date(value);
  return Number.isFinite(date.getTime()) ? date : null;
}

function format(date, options) {
  if (date === null) return UNAVAILABLE;
  try { return new Intl.DateTimeFormat('en-GB', options).format(date); }
  catch { return UNAVAILABLE; }
}

/** Omitted timeZone uses the device's timezone, not the exchange's location. */
export function formatSnapshotDate(value, timeZone = undefined) {
  return format(instant(value), { day: 'numeric', month: 'long', year: 'numeric', timeZone });
}

export function formatPriceTime(value, timeZone = undefined) {
  return format(instant(value), {
    day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', timeZone,
  });
}

/** A source's civil day has no implied observation or publication time. */
export function formatClosingDate(value) {
  return format(civilDate(value), { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' });
}

export const formatValuationDate = formatClosingDate;
