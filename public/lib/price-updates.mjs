// A receipt is evidence of a newer accepted observation, never a cache-read time.
export function latestPriceUpdate(holdings, instruments, quotes, receipts, nowMs = Date.now()) {
  if (!Array.isArray(holdings) || !Array.isArray(instruments) || !receipts || !Number.isFinite(nowMs)) return null;
  const byId = new Map(instruments.map(item=>[item.id,item]));
  const found = [];
  for (const holding of holdings) {
    const id = holding.instrumentId, receipt = receipts[id], quote = quotes?.[id];
    if (!byId.has(id) || !receipt || !quote || quote.price == null || quote.status === 'missing') continue;
    const at = typeof receipt.receivedAt === 'string' ? Date.parse(receipt.receivedAt) : NaN;
    if (!Number.isFinite(at) || at > nowMs || receipt.observationDate !== quote.date
      || (receipt.observedAt ?? null) !== (quote.observedAt ?? null)) continue;
    found.push({instrumentId:id,name:byId.get(id).name,receivedAt:receipt.receivedAt,
      observationDate:quote.date,observedAt:quote.observedAt ?? null,at});
  }
  if (!found.length) return null;
  const at = Math.max(...found.map(item=>item.at));
  const items = found.filter(item=>item.at === at);
  return {at,items,count:items.length,total:holdings.length};
}

function receiptTimeParts(at, nowMs, timeZone) {
  if (!Number.isFinite(at) || !Number.isFinite(nowMs) || at > nowMs) return null;
  const day = new Intl.DateTimeFormat('en-GB',{year:'numeric',month:'2-digit',day:'2-digit',timeZone});
  const time = new Intl.DateTimeFormat('en-GB',{hour:'2-digit',minute:'2-digit',hourCycle:'h23',timeZone}).format(at);
  const today = day.format(at) === day.format(nowMs);
  const year = new Intl.DateTimeFormat('en-GB',{year:'numeric',timeZone});
  const date = new Intl.DateTimeFormat('en-GB',{day:'numeric',month:'short',
    ...(year.format(at) === year.format(nowMs) ? {} : {year:'numeric'}),timeZone}).format(at);
  return {time,date,today};
}

export function priceUpdateTime(at, nowMs = Date.now(), timeZone) {
  const parts = receiptTimeParts(at,nowMs,timeZone);
  return parts ? (parts.today ? parts.time : `${parts.date}, ${parts.time}`) : '';
}

export function priceUpdateReceivedLabel(update, nowMs = Date.now(), timeZone) {
  const parts = receiptTimeParts(update?.at,nowMs,timeZone);
  return parts ? (parts.today ? `Received at ${parts.time}` : `Received on ${parts.date} at ${parts.time}`) : '';
}

export function priceUpdateLabel(update) {
  return update ? 'Last update' : '';
}
