import {formatPriceTime, formatClosingDate} from './dates.mjs';

const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const text = (value, max = 240) => typeof value === 'string' ? value.slice(0,max) : '';
const count = value => Number.isSafeInteger(value) && value >= 0 ? value : null;
const bool = value => typeof value === 'boolean' ? value : null;
const instant = value => typeof value === 'string' && /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/.test(value) && formatPriceTime(value) !== 'Unavailable' ? value : null;
const date = value => typeof value === 'string' && formatClosingDate(value) !== 'Unavailable' ? value : null;
const time = value => value ? formatPriceTime(value) : 'Not recorded';
const day = value => value ? formatClosingDate(value) : 'Not available';
const dashboard = {alpaca:'https://app.alpaca.markets/',tickerlayer:'https://tickerlayer.com/dashboard',eodhd:'https://eodhd.com/cp/api'};
const publicHosts = new Set(['docs.alpaca.markets','alpaca.markets','tickerlayer.com','eodhd.com','www.newtonanalytics.com','newtonanalytics.com','marketdata.euronext.com','www.mds.deutsche-boerse.com','new.brandnewday.nl','www.ecb.europa.eu']);
function publicUrl(value) {
  try {const url = new URL(value); return url.protocol === 'https:' && publicHosts.has(url.hostname) && !url.username && !url.password && !url.search && !url.hash ? url.href : null;} catch {return null;}
}

// Only the fields needed for the page are accepted; no holdings or credential
// object can be passed through to the markup by an expanded API response.
export function normalizeStatus(value) {
  if (!value || value.schemaVersion !== 1 || !instant(value.checkedAt)
    || !Array.isArray(value.holdings) || value.holdings.length > 500
    || !Array.isArray(value.providers) || value.providers.length > 40) throw new Error('Status could not be read.');
  return {
    checkedAt:instant(value.checkedAt), cacheUpdatedAt:instant(value.cacheUpdatedAt),
    collectorLastAttemptAt:instant(value.collectorLastAttemptAt), collectorNextCheckAt:instant(value.collectorNextCheckAt),
    collectionPausedUntil:instant(value.collectionPausedUntil),
    manualRefresh:{enabled:false,reason:text(value.manualRefresh?.reason,500) || 'Status reads saved information only. Price updates are scheduled on the server.'},
    holdings:value.holdings.map(row => {
      if (!row || typeof row !== 'object') throw new Error('Status could not be read.');
      return {listingId:text(row.listingId,80),name:text(row.name),symbol:text(row.symbol,60),exchange:text(row.exchange,120),currency:text(row.currency,3),source:text(row.source,120),
        priceDate:date(row.priceDate),observedAt:instant(row.observedAt),publicationAt:instant(row.publicationAt),retrievedAt:instant(row.retrievedAt),baselineDate:date(row.baselineDate),baselineObservedAt:instant(row.baselineObservedAt),
        freshUntil:instant(row.freshUntil),freshUntilPassed:bool(row.freshUntilPassed),status:text(row.status,40),sourceAvailable:bool(row.sourceAvailable),priceAvailable:bool(row.priceAvailable),baselineAvailable:bool(row.baselineAvailable),
        issues:Array.isArray(row.issues) ? row.issues.filter(item=>typeof item==='string').slice(0,20).map(item=>text(item,120)) : [],
        checks:Array.isArray(row.checks) ? row.checks.slice(0,20).filter(item=>item && typeof item==='object').map(item=>({id:text(item.id,60),label:text(item.label,120),lastAttemptAt:instant(item.lastAttemptAt),lastSuccessAt:instant(item.lastSuccessAt),nextEligibleAt:instant(item.nextEligibleAt),note:text(item.note,500)})) : [],
      };
    }),
    providers:value.providers.map(row => {
      if (!row || typeof row !== 'object') throw new Error('Status could not be read.');
      return {id:text(row.id,60),label:text(row.label,120),periodSeconds:count(row.periodSeconds),internalLimit:count(row.internalLimit),
        windowStart:instant(row.windowStart),resetAt:instant(row.resetAt),used:count(row.used),remaining:count(row.remaining),
        currentHourUsed:count(row.currentHourUsed),previousHourUsed:count(row.previousHourUsed),todayUsed:count(row.todayUsed),previousDayUsed:count(row.previousDayUsed),dayStart:instant(row.dayStart),
        providerAllowance:row.providerAllowance && typeof row.providerAllowance === 'object' ? {text:text(row.providerAllowance.text,500),url:publicUrl(row.providerAllowance.url)} : null,
        note:text(row.note,500), providerRemaining:null};
    }),
  };
}
function fact(label,value) {return `<div><dt>${esc(label)}</dt><dd>${esc(value)}</dd></div>`;}
function quoteDate(row) {
  if (row.priceAvailable !== true) return 'Stored price unavailable';
  if (['closed','nav'].includes(row.status)) return `${row.status === 'nav' ? 'Fund value' : 'Closing price'} · ${day(row.priceDate)}`;
  if (row.observedAt) return `Price observation · ${time(row.observedAt)}`;
  return `Price dated · ${day(row.priceDate)}`;
}
function holding(row) {
  const warnings = [row.priceAvailable === false ? 'Stored price unavailable.' : '',row.baselineAvailable === false ? 'Stored comparison price unavailable.' : '',row.sourceAvailable === false ? 'The latest source check did not complete.' : '',row.freshUntilPassed === true ? 'This source record is older than expected.' : '',row.status === 'stale' ? 'The portfolio may be using earlier valid figures.' : ''].filter(Boolean);
  const checks = row.checks.map(check=>`<div class="status-check"><h4>${esc(check.label || 'Source check')}</h4><dl class="status-facts">${fact('Last attempted',time(check.lastAttemptAt))}${fact('Last successful',time(check.lastSuccessAt))}${fact('Next eligible',time(check.nextEligibleAt))}</dl>${check.note ? `<p class="status-small">${esc(check.note)}</p>` : ''}</div>`).join('');
  return `<article class="status-holding"><h3>${esc(row.name || row.symbol || 'Investment')}</h3><p class="status-small">${esc([row.symbol,row.exchange,row.currency].filter(Boolean).join(' · '))}</p><p class="status-price-date">${esc(quoteDate(row))}</p>${warnings.length ? `<p class="status-warning">${esc(warnings.join(' '))}</p>` : ''}<dl class="status-facts">${fact('Source',row.source || 'Not recorded')}${fact('Source retrieval recorded',time(row.retrievedAt))}</dl><details class="status-checks"><summary>Dates and update checks</summary><dl class="status-facts">${fact('Comparison price date',day(row.baselineDate))}${fact('Price expected fresh until',time(row.freshUntil))}${fact('Source publication',time(row.publicationAt))}</dl>${checks}</details></article>`;
}
function provider(row, locale) {
  const number = value => value === null ? 'Unknown' : new Intl.NumberFormat(locale).format(value);
  const window = row.periodSeconds === 3600 ? 'hour' : row.periodSeconds === 86400 ? 'day' : 'window';
  const allowance = row.providerAllowance?.text ? `<p class="status-small">Provider allowance: ${esc(row.providerAllowance.text)}${row.providerAllowance.url ? ` <a href="${esc(row.providerAllowance.url)}" target="_blank" rel="noopener noreferrer">Details</a>` : ''}</p>` : '';
  return `<article class="status-provider"><h3>${esc(row.label || row.id || 'Provider')}</h3><p class="status-use"><strong>${esc(number(row.used))} / ${esc(number(row.internalLimit))}</strong><span>RichYet use this ${window}</span></p><dl class="status-facts">${row.currentHourUsed !== null && row.periodSeconds !== 3600 ? fact('Recorded this hour',number(row.currentHourUsed)) : ''}${row.previousHourUsed !== null ? fact('Recorded previous hour',number(row.previousHourUsed)) : ''}${fact('Recorded today (UTC)',number(row.todayUsed))}</dl><details class="status-checks"><summary>Limits and provider account</summary><dl class="status-facts">${fact('Window started',time(row.windowStart))}${fact('Recorded previous day (UTC)',number(row.previousDayUsed))}${fact('Remaining under RichYet’s limit',number(row.remaining))}${fact('RichYet limit resets',time(row.resetAt))}${fact('Provider balance','Unknown — not checked with provider')}</dl>${row.note ? `<p class="status-small">${esc(row.note)}</p>` : ''}${allowance}${dashboard[row.id] ? `<p class="status-small"><a href="${dashboard[row.id]}" target="_blank" rel="noopener noreferrer">Open provider dashboard</a></p>` : ''}</details></article>`;
}
export function renderStatus(status, {numberFormat = 'nl-NL'} = {}) {
  const locale = numberFormat === 'en-GB' ? 'en-GB' : 'nl-NL';
  return `<section class="status-section" aria-labelledby="status-updates-heading"><h2 id="status-updates-heading">Price updates</h2><dl class="status-overview status-facts">${fact('Status read',time(status.checkedAt))}${fact('Server cache saved',time(status.cacheUpdatedAt))}${fact('Last server check',time(status.collectorLastAttemptAt))}${fact('Next server check',time(status.collectorNextCheckAt))}</dl>${status.collectionPausedUntil ? `<p class="status-warning">Price collection is paused until ${esc(time(status.collectionPausedUntil))}.</p>` : ''}<p class="status-small">A server check or cache save does not necessarily mean a provider was called or a price changed. Last update in your portfolio records when newer price information arrived; source retrieval times here describe downloads. Times use your device’s time zone. A closing or fund date alone does not give an exact trade time.</p></section><section class="status-section" aria-labelledby="status-usage-heading"><h2 id="status-usage-heading">API usage</h2><p class="status-small">These are requests recorded by RichYet, including failed attempts, under its own safety limits. They are not your provider’s live balance. A zero count this hour can still accompany prices saved earlier.</p><div class="status-providers">${status.providers.map(row=>provider(row,locale)).join('')}</div></section><section class="status-section" aria-labelledby="status-investments-heading"><h2 id="status-investments-heading">Investment sources</h2><p class="status-small">These are the latest saved source records and checks. After an incomplete update, your portfolio can keep an earlier complete price and comparison, so its displayed figures may come from an older record.</p>${status.holdings.length ? `<div class="status-holdings">${status.holdings.map(holding).join('')}</div>` : '<p class="status-small">No investments to show.</p>'}</section>`;
}
