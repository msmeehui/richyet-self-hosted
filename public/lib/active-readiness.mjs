import {calculateHolding} from './portfolio.mjs';
import {getExchangeSession} from './exchange-status.mjs';
import {getFundContribution} from './fund-contribution.mjs';
import {portfolioCurrency} from './reporting-currency.mjs';

const KEY = 'richyet-active-readiness-v1';

// Only readiness receipts, never financial values. Tab-scoped storage lets a
// reload retain a warning; another account cannot inherit those receipts.
export function createActiveReadinessTracker(storage = null) {
  let owner = null, records = new Map();
  return {
    bind(nextOwner) {
      if (nextOwner === owner) return;
      owner = nextOwner; records = new Map();
      try {
        const raw = storage?.getItem(KEY);
        if (!raw || raw.length > 100000) return;
        const saved = JSON.parse(raw);
        if (saved.owner === owner && Array.isArray(saved.records) && saved.records.length <= 200) {
          records = new Map(saved.records.filter(r => Array.isArray(r) && r.length === 2
            && r.every(v => typeof v === 'string' && v.length <= 2000)));
        }
      } catch { /* Storage is optional; the current view still tracks readiness. */ }
    },
    has(id, window) { return records.get(id) === window; },
    update(next) {
      if (JSON.stringify([...records]) === JSON.stringify([...next])) return;
      records = next;
      try { if (owner) storage?.setItem(KEY,JSON.stringify({owner,records:[...records]})); } catch {}
    },
    clear({keepReceipt = false} = {}) {
      if (owner && !keepReceipt) { try { storage?.removeItem(KEY); } catch {} }
      owner = null; records = new Map();
    },
  };
}

/** A valid current-window pair survives an ordinary refresh failure. Keep its
 * actual source date and flag the delay, including after reload. A genuinely
 * missing/invalid pair stays visible only if it was admitted earlier.
 */
export function selectReadyActiveEntries(candidates, nowMs, tracker = null, reporting = null) {
  const comparisonEntries = [], failedInstrumentIds = [], records = new Map();
  let completeCount = 0;
  for (const entry of candidates) {
    const {holding, instrument, quote} = entry;
    const session = getExchangeSession(instrument,nowMs,quote);
    const fund = session.state === 'fund' ? getFundContribution(instrument,quote,nowMs) : null;
    const windowParts = [instrument.id,instrument.isin,instrument.mic,instrument.currency,
      instrument.closeSchedule,instrument.contributionSchedule,
      fund ? fund.windowDate : session.date,fund ? quote?.fundContribution?.valuationDate : null];
    if (portfolioCurrency(reporting) !== 'EUR') windowParts.push(portfolioCurrency(reporting));
    const window = JSON.stringify(windowParts);
    const result = calculateHolding(holding,instrument,quote,reporting);
    const ready = result.completeChange && Number.isFinite(result.changePercent)
      && !result.stale && quote?.sourceAvailable !== false;
    if (result.completeChange && Number.isFinite(result.changePercent)) {
      completeCount++;
      records.set(holding.id,window); comparisonEntries.push(entry);
      if (!ready) failedInstrumentIds.push(instrument.id);
    } else if (tracker?.has(holding.id,window)) {
      records.set(holding.id,window); failedInstrumentIds.push(instrument.id);
      comparisonEntries.push({...entry,quote:{...quote,previousClose:null,previousFx:null}});
    }
  }
  tracker?.update(records);
  return {comparisonEntries,failedInstrumentIds,completeCount};
}
