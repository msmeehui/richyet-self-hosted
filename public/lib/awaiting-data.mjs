import {calculateHolding} from './portfolio.mjs';

/** Explain an existing readiness decision without changing its inputs. */
export function awaitingDataReason(entry, waitingForPrice = false, reporting = null) {
  const quote = entry.quote ?? {};
  if (waitingForPrice || quote.price == null) return 'Waiting for today’s first price update.';
  if (quote.previousClose == null || !quote.previousDate) {
    return 'Today’s price has arrived. Its previous closing price is not available yet.';
  }
  const result = calculateHolding(entry.holding,entry.instrument,quote,reporting);
  if (result.value === null || result.baseline === null) {
    return 'Today’s price has arrived. Currency information for its change is not available yet.';
  }
  return 'Today’s price has arrived. Its comparison is not ready yet.';
}
