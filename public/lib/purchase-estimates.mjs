import {calculateHolding,purchaseComparison} from './portfolio.mjs';
import {portfolioCurrency} from './reporting-currency.mjs';

const currencyName = currency => ({EUR:'euros',USD:'US dollars',GBP:'British pounds'}[currency] || currency);

/** Explain only estimates in the current calculation, not merely blank inputs.
 * Returned names are plain text; callers must render them as text or escape HTML.
 */
export function purchaseEstimateDetails(entries, reporting = null) {
  const currency=portfolioCurrency(reporting),target=currencyName(currency);
  return entries.flatMap(({holding,instrument,quote})=>{
    const result=calculateHolding(holding,instrument,quote,reporting);
    if (!purchaseComparison(holding,instrument,result,reporting).estimated) return [];
    const reason=currency === 'EUR'
      ? `The original euro cost is missing. We convert the gain or loss in ${currencyName(instrument.currency)} to euros using the latest available exchange rate.`
      : holding.averageCost == null
        ? `Only an original euro cost is recorded. We convert it to ${target} using the latest available exchange rate.`
        : `The average purchase price is in ${currencyName(instrument.currency)}. We convert that cost to ${target} using the latest available exchange rate.`;
    return [{id:holding.id,instrumentId:instrument.id,name:instrument.name,reason}];
  });
}
