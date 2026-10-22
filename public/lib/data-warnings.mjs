import {calculateHolding} from './portfolio.mjs';
import {holdingFreshness,priceDateMessage} from './market.mjs';

/** Explain actual inclusion separately from source freshness. Never replace
 * normalized inputs, restore an invalid pair or hide a missing comparison.
 */
export function comparisonWarnings(entries,selectedEntries,latest,{issues=[],failed=false,reporting=null,now=Date.now(),purchase=false,unknownIds=[]}={}) {
  const selected=new Map(selectedEntries.map(e=>[e.holding.id,e]));
  const missing=new Set(latest.missingInstrumentIds),reference=new Set(latest.missingReferenceInstrumentIds);
  const unknown=new Set(unknownIds),warnings=[];
  for (const entry of entries) {
    const {holding,instrument,quote}=entry;
    const visible=selected.has(holding.id),codes=new Set(issues.filter(i=>i.instrumentId===instrument.id).map(i=>i.code));
    const result=calculateHolding(holding,instrument,selected.get(holding.id)?.quote??quote,reporting);
    const freshness=holdingFreshness(instrument,quote,issues,now);
    const delayed=failed || quote.sourceAvailable===false || freshness.priceStale || freshness.fxStale
      || freshness.updateFailed || freshness.updateIncomplete || codes.has('source_unavailable') || codes.has('history_source_unavailable');
    // Since purchase accepts older usable values quietly. A genuinely missing
    // valuation is still a data issue; this never changes financial inputs.
    if (purchase) {
      if (result.value == null) warnings.push({id:instrument.id,name:instrument.name,
        reason:'Its value and change since purchase are unavailable because price or currency information is missing or invalid.',kind:'issue'});
      continue;
    }
    const notes=[];
    if (visible && delayed) notes.push(result.completeChange
      ? 'An update could not be completed. Showing the last valid price and comparison.'
      : 'An update could not be completed. Any available price is still shown.');
    if (!purchase && missing.has(instrument.id)) notes.push('Its latest change is unavailable and is left out of the portfolio change.');
    if (!purchase && reference.has(instrument.id)) notes.push('An earlier price or exchange rate is missing, so the portfolio percentage is unavailable.');
    if (!purchase && unknown.has(instrument.id)) notes.push('Its market hours are unavailable, so it cannot be included with open markets.');
    if (notes.length) {
      if (visible && delayed && quote.price!=null) notes.push(priceDateMessage(instrument,quote,now)+'.');
      const usable = result.completeChange || purchase && result.value != null;
      const kind = visible && delayed && usable && (purchase || !missing.has(instrument.id) && !reference.has(instrument.id) && !unknown.has(instrument.id)) ? 'earlier' : 'issue';
      warnings.push({id:instrument.id,name:instrument.name,reason:notes.join(' '),kind});
    }
  }
  return warnings;
}
