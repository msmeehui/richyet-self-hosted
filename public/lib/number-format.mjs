/** Presentation/input preference only. Stored decimals and calculations stay unchanged. */
export const NUMBER_FORMATS = Object.freeze({
  'nl-NL': Object.freeze({name:'Dutch', example:'1.234,56', decimal:',', group:'.'}),
  'en-GB': Object.freeze({name:'English', example:'1,234.56', decimal:'.', group:','}),
});
export function isNumberFormat(value) {
  return typeof value === 'string' && Object.hasOwn(NUMBER_FORMATS,value);
}
export function normalizeNumberFormat(value) {
  return isNumberFormat(value) ? value : 'nl-NL';
}
function invalid(message) {
  const error = new Error(message);
  error.name = 'ValidationError'; error.code = 'INVALID_NUMBER';
  return error;
}
/** The chosen locale has one meaning; never guess based on the entered separators. */
export function parseLocalizedNumber(text, {allowZero=false, numberFormat='nl-NL'} = {}) {
  if (typeof text !== 'string') throw invalid('Enter a number.');
  const input = text.trim(), locale = normalizeNumberFormat(numberFormat);
  if (!input || input.length > 120) throw invalid(input ? 'This number is too long.' : 'Enter a number.');
  const valid = locale === 'en-GB'
    ? /^(?:\d+|[1-9]\d{0,2}(?:,\d{3})+)(?:\.\d{1,6})?$/
    : /^(?:\d+|[1-9]\d{0,2}(?:\.\d{3})+)(?:,\d{1,6})?$/;
  if (!valid.test(input)) throw invalid(locale === 'en-GB'
    ? 'Use a dot for decimals, up to 6 decimal places, and commas only for thousands (1,234.56).'
    : 'Use a comma for decimals, up to 6 decimal places, and dots only for thousands (1.234,56).');
  const format = NUMBER_FORMATS[locale];
  const [whole,fraction=''] = input.replaceAll(format.group,'').split(format.decimal);
  const integer = whole.replace(/^0+(?=\d)/,''), decimals = fraction.replace(/0+$/,'');
  const result = decimals ? `${integer}.${decimals}` : integer;
  if (result === '0' && !allowZero) throw invalid('Enter a number greater than zero.');
  return result;
}
/** Editor prefills never pass through floating point or display rounding. */
export function formatDecimalInput(value, numberFormat='nl-NL') {
  if (value == null) return '';
  if (typeof value !== 'string' || !/^(?:0|[1-9]\d*)(?:\.\d+)?$/.test(value)) throw invalid('The saved number could not be read.');
  return value.replace('.',NUMBER_FORMATS[normalizeNumberFormat(numberFormat)].decimal);
}
