import { createAccountMenu } from './lib/account-menu.mjs';
import { getExchangeSession } from './lib/exchange-status.mjs';
import { createActiveReadinessTracker } from './lib/active-readiness.mjs';
import { normalizeComparisonMode, readComparisonPreferences, writeComparisonPreferences, comparisonModeForPreferences, comparisonModeForHoldings, comparisonFilterState, areAllMarketsClosed, selectComparisonEntries } from './lib/comparison-modes.mjs';
import { selectHoldingSections, formatHoldingObservation } from './lib/holding-sections.mjs';
import { moveItem, pointerDestination } from './lib/reorder.mjs';
import { PortfolioClient, PortfolioError, validateHoldings } from './lib/persistence.mjs';
import { instruments as demoInstruments, quotes as demoQuotes, initialHoldings } from './lib/demo.mjs';
import {unavailableInstrument, canAddInstrument, admissionMessage, holdingDataMessages, holdingFreshness, priceDateMessage} from './lib/market.mjs';
import { calculateHolding as calculateHoldingIn, calculatePortfolio as calculatePortfolioIn, calculateLatestContribution as calculateLatestContributionIn, purchaseComparison as purchaseComparisonIn, formatMoney as formatMoneyIn, formatPercent as formatPercentIn, formatQuantity as formatQuantityIn } from './lib/portfolio.mjs';

import {NUMBER_FORMATS, isNumberFormat, parseLocalizedNumber, formatDecimalInput} from './lib/number-format.mjs';
import {portfolioCurrency, reportingQuote, reportingIssues} from './lib/reporting-currency.mjs';
import {latestPriceUpdate, priceUpdateLabel, priceUpdateReceivedLabel} from './lib/price-updates.mjs';
import {awaitingDataReason} from './lib/awaiting-data.mjs';
import {comparisonWarnings} from './lib/data-warnings.mjs';
import {portfolioBreakdown,donutPath} from './lib/portfolio-breakdown.mjs';
import {purchaseEstimateDetails} from './lib/purchase-estimates.mjs';

let reportingCurrency = 'EUR', reportingFx = null, priceUpdates = {}, currencySaving = false, currencyEpoch = 0, reportingNow = null;
let numberFormat = 'nl-NL', inputNumberFormat = 'nl-NL', numberFormatSaving = false, numberFormatEpoch = 0;
const formatMoney = (value,currency,options={}) => formatMoneyIn(value,currency,{...options,numberFormat});
const formatPercent = (value,options={}) => formatPercentIn(value,{...options,numberFormat});
const formatQuantity = value => formatQuantityIn(value,{numberFormat});
const reportingContext = () => ({currency:reportingCurrency,fx:reportingFx,nowMs:reportingNow ?? Date.now()});
function withReportingClock(action) {
  const previous=reportingNow; reportingNow=previous ?? Date.now();
  try {return action();} finally {reportingNow=previous;}
}
const calculateHolding = (h,i,q) => calculateHoldingIn(h,i,q,reportingContext());
const calculatePortfolio = entries => calculatePortfolioIn(entries,reportingContext());
const calculateLatestContribution = (entries,reference) => calculateLatestContributionIn(entries,reference,reportingContext());
const purchaseComparison = (h,i,c) => purchaseComparisonIn(h,i,c,reportingContext());

const $ = id => document.getElementById(id);
$('eur-cost-hint').textContent = 'The total euros you paid for the units you still own. This includes currency changes when your portfolio is shown in euros.';
const accountMenu = createAccountMenu();
const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const wholeEuros = (value, signed=false) => formatMoney(value, reportingCurrency, {signed, fractionDigits:0});
const signClass = n => n == null || n === 0 ? 'neutral' : n > 0 ? 'positive' : 'negative';
const STORAGE_KEY = 'richyet.fictional-preview.v1';
const demoMode = new URLSearchParams(location.search).get('demo') === '1';
const client = new PortfolioClient({instrumentIds: demoInstruments.map(i => i.id)});
let dataMode = demoMode ? 'fictional' : null;
let instruments = demoMode ? demoInstruments : [], quotes = demoMode ? demoQuotes : {};
let byId = new Map(instruments.map(i => [i.id, i]));
let marketIssues = [], marketFailed = false, marketLoading = false, catalogueFailed = false;
let marketTimer = null, exchangeStatusTimer = null, privateGeneration = 0;
let foregroundMarketTimer = null, foregroundMarketPending = false, lastMarketCheckAt = null, privateLoadToken = null;
let selectionGeneration = 0, selectionLoading = false, selectionController = null, selectionRetryId = null;
let searchGeneration = 0, searchController = null, searchTimer = null, searchLoading = false;
let searchTerm = '', searchResults = [], searchHasMore = false, searchOffset = 0, searchFailedOffset = null;
let ready = demoMode, busy = false, authBusy = false, routeQueued = false;
let passwordToken = /^#password\/([A-Za-z0-9_-]{32,200})$/.exec(location.hash)?.[1] || null;
if (passwordToken) history.replaceState(null, '', location.pathname + location.search);
const homeURL = demoMode ? './?demo=1' : './';
$('open-about').href = demoMode ? './about.html?demo=1' : './about.html';
$('form-portfolio-return').href = homeURL;
const numberPattern = /^(?:0|[1-9]\d{0,11})(?:\.\d{1,6})?$/;
let expandedId = null, managing = false, editingId = null, selectedId = null, lastOpenerId = 'add-investment';
let searchOptions = [], searchIndex = -1, undo = null, toastTimer;
let drag = null, dragFrame = null, suppressClickUntil = 0, reorderDialogId = null;
const initialComparison = readComparisonPreferences();
let activeOnly = initialComparison.activeOnly;
let period = comparisonModeForPreferences(initialComparison);
let comparisonCoverage = [];
let estimateOpener = null, priceUpdateOpener = null, coverageOpener = null, pointerFocusTarget = null;
let numberFormatOpener = null;
let breakdownOpener = null, breakdownSelectedId = null, breakdownModel = null, breakdownSignature = null;
let priceUpdateHoldingIds = new Set(), awaitingData = [], earlierData = [];
let readinessStorage = null;
try { readinessStorage = sessionStorage; } catch {}
const activeReadiness = createActiveReadinessTracker(readinessStorage);
let holdings = demoMode ? loadHoldings() : [];
let screen = null, formReturning = false, pendingReturn = null;
let formBaseline = null, formNavigation = null, restoringFormNavigation = false;
history.scrollRestoration = 'manual';

function loadHoldings() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (!raw) return initialHoldings();
    if (raw.length > 20000) throw new Error('Invalid demo storage');
    const saved = JSON.parse(raw);
    if (saved.version !== 1 || !Array.isArray(saved.holdings) || saved.holdings.length > instruments.length) throw new Error('Invalid demo storage');
    return validateHoldings(saved.holdings, new Set(byId.keys()));
  } catch {
    $('storage-warning').textContent = 'The saved demo could not be loaded. The example portfolio has been restored on screen.';
    $('storage-warning').hidden = false;
    return initialHoldings();
  }
}
function connectionNotice(message = '', reload = false) {
  $('connection-message').textContent = message;
  $('connection-notice').hidden = !message;
  $('reload-portfolio').hidden = !reload;
}
function setBusy(value) {
  busy = value;
  accountMenu.setBusy(value);
  $('portfolio-screen').inert = value;
  $('investment-screen').inert = value;
  $('main').setAttribute('aria-busy', String(value));
  if (!value && routeQueued) {
    routeQueued = false;
    queueMicrotask(handleNavigation);
  }
  if (!value) scheduleForegroundMarketRefresh();
}
async function commitHoldings(next) {
  if (busy || !ready) return false;
  if (demoMode) {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify({version:1, holdings:next}));
      holdings = next;
      if (screen !== 'portfolio') formBaseline = formValues();
      $('storage-warning').hidden = true;
      return true;
    } catch {
      $('storage-warning').textContent = 'This browser could not save the demo. Your change has not been applied.';
      $('storage-warning').hidden = false;
      return false;
    }
  }
  if (client.needsReload) {
    connectionNotice('Reload the saved portfolio before making another change. Unsaved form entries will be replaced.', true);
    return false;
  }
  setBusy(true);
  connectionNotice('Saving…');
  let recoveryFocus = null;
  try {
    const saved = await client.savePortfolio(next);
    holdings = saved.holdings;
    // A Back action queued during saving must not call confirmed saved fields
    // unsaved when the busy guard releases that navigation.
    if (screen !== 'portfolio') formBaseline = formValues();
    if (saved.dataMode === 'live') {
      instruments = mergeInstruments(saved.instruments, instruments);
      for (const i of instruments) byId.set(i.id, i);
    }
    connectionNotice();
    return true;
  } catch (error) {
    if (error.status === 401) {
      showAuth('Your session expired. Sign in again to load the saved portfolio. Your last change was not confirmed.');
      recoveryFocus = 'auth-email';
    }
    else if (error.status === 422 && error.code === 'instrument_unavailable') {
      connectionNotice();
      if (screen === 'add' && selectedId) {
        $('form-error').textContent = 'The latest price or comparison is unavailable, so this investment was not added. Your entries are kept. Check again or choose a different investment.';
        $('save-investment').disabled = true;
        $('retry-selection').hidden = false;
        $('selected-readiness').hidden = true;
        recoveryFocus = 'retry-selection';
      } else connectionNotice('This investment’s latest data is unavailable, so it could not be added. Please try again later.');
    }
    else {
      connectionNotice(error.status === 409
        ? 'Your portfolio changed in another session. This change was not saved. Reload the saved portfolio; unsaved form entries will be replaced.'
        : 'The save could not be confirmed. Reload the saved portfolio to check what was saved before trying again. Unsaved form entries will be replaced.', true);
      recoveryFocus = 'reload-portfolio';
    }
    return false;
  } finally {
    setBusy(false);
    // Making a saving form inert removes its keyboard focus. After a failure,
    // put it on the recovery action once that action can be used again.
    const target = recoveryFocus && $(recoveryFocus);
    if (target && !target.disabled && !target.closest('[hidden]')) target.focus();
  }
}
function clearPrivateView(preserveReadiness = false) {
  privateGeneration++;
  privateLoadToken = null;
  clearTimeout(foregroundMarketTimer); foregroundMarketTimer = null;
  foregroundMarketPending = false; lastMarketCheckAt = null;
  cancelSelectionCheck();
  clearTimeout(marketTimer);
  clearTimeout(exchangeStatusTimer); exchangeStatusTimer = null;
  finishDrag(true);
  reorderDialogId = null;
  estimateOpener = null; priceUpdateOpener = null; coverageOpener = null;
  numberFormatOpener = null;
  breakdownOpener = null; breakdownSelectedId = null; breakdownModel = null; breakdownSignature = null;
  $('breakdown-chart').replaceChildren(); $('breakdown-list').replaceChildren(); $('breakdown-selection').textContent='';
  priceUpdateHoldingIds.clear(); awaitingData = []; earlierData = []; activeReadiness.clear({keepReceipt:preserveReadiness});
  pointerFocusTarget?.classList.remove('pointer-return-focus'); pointerFocusTarget = null;
  for (const dialog of document.querySelectorAll('dialog[open]')) dialog.close();
  comparisonCoverage = [];
  $('coverage-list').replaceChildren();
  $('coverage-title').textContent = 'Data issue';
  $('coverage-description').textContent = '';
  $('estimate-description').textContent = '';
  $('estimate-list').replaceChildren(); $('estimate-list').hidden = true;
  $('estimate-guidance').textContent = '';
  $('reorder-title').textContent = 'Move investment';
  $('reorder-position').replaceChildren();
  ready = false;
  holdings = [];
  instruments = []; quotes = {}; byId = new Map(); marketIssues = [];
  reportingCurrency = 'EUR'; reportingFx = null; priceUpdates = {};
  numberFormat = 'nl-NL'; inputNumberFormat = 'nl-NL'; numberFormatSaving = false; numberFormatEpoch++;
  for (const id of ['save-number-format','cancel-number-format','close-number-format','number-format']) $(id).disabled = false;
  $('price-update').hidden = true; $('price-update-list').replaceChildren();
  $('awaiting-data-section').hidden = true; $('awaiting-data-list').replaceChildren();
  $('earlier-data-section').hidden = true; $('earlier-data-list').replaceChildren();
  dataMode = null; marketFailed = false; catalogueFailed = false; marketLoading = false;
  expandedId = null; managing = false; undo = null;
  $('portfolio-screen').hidden = true;
  $('investment-screen').hidden = true;
  $('holdings-list').replaceChildren();
  $('summary-change').replaceChildren();
  $('portfolio-value').textContent = '';
  $('toast').hidden = true;
  accountMenu.hide();
  $('investment-form').reset();
  $('selected-instrument').replaceChildren();
  $('search-results').replaceChildren();
  searchResults = []; searchTerm = ''; searchHasMore = false; searchOffset = 0;
  editingId = null; selectedId = null; pendingReturn = null;
  formBaseline = null; formNavigation = null; restoringFormNavigation = false;
  $('form-page-header').hidden = true;
  $('portfolio-header').hidden = false;
  $('open-about').hidden = true;
}
function showAuth(message = '') {
  clearPrivateView();
  connectionNotice();
  screen = null;
  $('auth-screen').hidden = false;
  $('auth-form').hidden = false;
  $('auth-retry').hidden = true;
  $('auth-help').hidden = Boolean(passwordToken);
  $('auth-title').textContent = passwordToken ? 'Choose your password' : 'Your investment portfolio';
  $('auth-description').textContent = message || (passwordToken ? 'Set a password for your private RichYet account.' : 'Stocks and ETFs');
  $('auth-email-field').hidden = Boolean(passwordToken);
  $('auth-email').required = !passwordToken;
  $('auth-confirm-field').hidden = !passwordToken;
  $('auth-confirm').required = Boolean(passwordToken);
  $('auth-password-hint').hidden = !passwordToken;
  $('auth-password-label').textContent = passwordToken ? 'New password' : 'Password';
  $('auth-password').autocomplete = passwordToken ? 'new-password' : 'current-password';
  $('auth-password').minLength = passwordToken ? 12 : 1;
  $('auth-password').value = '';
  $('auth-confirm').value = '';
  $('auth-submit').textContent = passwordToken ? 'Save password' : 'Sign in';
  $('auth-return').hidden = !passwordToken;
  document.title = passwordToken ? 'Choose password — RichYet?' : 'Sign in — RichYet?';
}
async function loadPrivatePortfolio() {
  // Other tabs share the session cookie and can rotate it by signing in/out.
  // Refresh identity and CSRF before accepting a reloaded portfolio, without
  // retrying any failed mutation or carrying a draft into another account.
  const previousEmail = client.user?.email;
  const previousMode = dataMode, previousQuotes = quotes, previousIssues = marketIssues;
  const generation = ++privateGeneration;
  cancelSelectionCheck();
  clearTimeout(marketTimer);
  clearTimeout(foregroundMarketTimer); foregroundMarketTimer = null; foregroundMarketPending = false;
  marketLoading = false;
  let saved;
  try {
    if (!await client.session()) throw new PortfolioError('Sign in again.', {status:401, code:'unauthorized'});
    if (generation !== privateGeneration) return;
    saved = await client.loadPortfolio();
  } catch (error) {if (generation !== privateGeneration) return; throw error;}
  if (generation !== privateGeneration) return;
  undo = null; pendingReturn = null;
  $('toast').hidden = true;
  clearTimeout(toastTimer);
  holdings = saved.holdings;
  reportingCurrency = client.reportingCurrency;
  numberFormat = client.numberFormat;
  dataMode = saved.dataMode;
  marketIssues = []; marketFailed = false; catalogueFailed = false;
  let catalogueLoaded = false;
  if (dataMode === 'live') {
    instruments = saved.instruments;
    const sameAccount = previousMode === 'live' && previousEmail === client.user.email;
    quotes = sameAccount ? previousQuotes : {};
    marketIssues = sameAccount ? previousIssues : [];
    try {
      const catalogue = await client.loadInstruments();
      if (generation !== privateGeneration) return;
      instruments = mergeInstruments(saved.instruments, catalogue.instruments);
      acceptMarket(catalogue.market);
      lastMarketCheckAt = Date.now();
      catalogueLoaded = true;
    }
    catch (error) { if (generation !== privateGeneration) return; if (error.status === 401) throw error; catalogueFailed = true; }
  } else { instruments = demoInstruments; quotes = demoQuotes; }
  if (generation !== privateGeneration) return;
  byId = new Map(instruments.map(i => [i.id, i]));
  for (const holding of holdings) if (!byId.has(holding.instrumentId)) byId.set(holding.instrumentId, unavailableInstrument(holding.instrumentId));
  ready = true;
  activeReadiness.bind(client.user.email);
  $('auth-screen').hidden = true;
  accountMenu.show(client.user);
  $('data-mode-notice').hidden = dataMode === 'live';
  $('data-mode-note').innerHTML = dataMode === 'live'
    ? ''
    : '<strong>Private test</strong> · Fictional investments and prices. Saved to your account.';
  $('reset-demo').hidden = dataMode === 'live';
  $('reset-demo').textContent = 'Reset examples';
  $('reset-dialog').querySelector('p').textContent = 'This replaces the fictional holdings saved to your account with the example portfolio. It will also change the portfolio on your other devices.';
  $('confirm-reset').textContent = 'Reset examples';
  connectionNotice();
  screen = null;
  applyRoute(true);
  if (dataMode === 'live') {
    if (catalogueLoaded) scheduleMarketRefresh();
    else await refreshMarket();
  }
}
function mergeInstruments(...groups) {
  return [...new Map(groups.flat().map(item => [item.id, item])).values()];
}
function scheduleMarketRefresh() {
  clearTimeout(marketTimer);
  if (ready && dataMode === 'live') marketTimer = setTimeout(() => {
    if (!document.hidden) void refreshMarket(); else scheduleMarketRefresh();
  }, 5 * 60 * 1000);
}
function queueForegroundMarketRefresh() {
  if (!ready || dataMode !== 'live' || document.hidden) return;
  refreshExchangeStatus();
  scheduleExchangeStatusRefresh();
  foregroundMarketPending = true;
  scheduleForegroundMarketRefresh();
}
function refreshExchangeStatus() {return withReportingClock(refreshExchangeStatusAtCurrentTime);}
function holdingComparisonMode(entries) {
  return comparisonModeForHoldings(dataMode !== 'live' && period === 'active' ? 'all' : period,entries.length,
    areAllMarketsClosed(entries,reportingContext().nowMs));
}
function refreshExchangeStatusAtCurrentTime() {
  if (!ready || document.hidden) return;
  const now = reportingContext().nowMs, entries = holdings.map(entry), presentation = selectHoldingSections(entries,now,holdingComparisonMode(entries),activeReadiness,reportingContext());
  renderSummary(calculatePortfolio(entries),entries,now);
  if ($('breakdown-dialog').open) renderPortfolioBreakdown();
  if (screen !== 'portfolio' || drag) return;
  updateHoldingPresentation(presentation,now);
}
function updateHoldingPresentation(presentation,now) {
  const entriesById = new Map(presentation.entries.map(item=>[item.holding.id,item]));
  const visibleIds = new Set(presentation.visibleEntries.map(({holding})=>holding.id));
  $('holdings-list').hidden = !managing && visibleIds.size === 0;
  const total = calculatePortfolio(presentation.entries);
  const completeShares = portfolioSharesAvailable(total,presentation.entries.map(({holding,instrument,quote})=>calculateHolding(holding,instrument,quote)));
  for (const item of $('holdings-list').querySelectorAll('[data-holding-id]')) {
    const selected = entriesById.get(item.dataset.holdingId);
    if (!selected) continue;
    const hide = !managing && !visibleIds.has(selected.holding.id);
    if (hide && item.contains?.(document.activeElement)) document.querySelector(`input[name="period"][value="${period === 'purchase' ? 'purchase' : 'latest'}"]`)?.focus({preventScroll:true});
    item.hidden = hide;
    const {holding:h,instrument:i,quote:q,comparisonQuote,waitingForCurrentSession,sessionState,activeFund} = selected;
    const c = calculateHolding(h,i,q), latest = calculateHolding(h,i,comparisonQuote);
    item.classList.toggle('is-closed',sessionState === 'closed' || (sessionState === 'fund' && !activeFund));
    const observation = item.querySelector('[data-observation-id]');
    const time = formatHoldingObservation(i,q,now);
    if (observation) {observation.textContent = time || 'Date unavailable'; observation.hidden = false;}
    const row = item.querySelector('.row-change');
    if (row) {row.className = rowChangeClass(h,i,c,latest); row.innerHTML = rowChangeContent(h,i,c,latest);}
    const grid = item.querySelector('.details-grid');
    if (grid) {
      const share = completeShares ? c.value / total.value * 100 : null;
      grid.innerHTML = detailGroups(h,i,q,c,latest,share,waitingForCurrentSession);
      const note = item.querySelector('.holding-data-note'), messages = dataMode === 'live' ? holdingDataMessages(i,q,c,issuesFor(i),now,{suppressFreshness:period === 'purchase'}) : [];
      note.textContent = messages.join(' '); note.hidden = !messages.length;
      item.querySelector('.price-timestamp').textContent = priceDateMessage(i,q);
    }
  }
}
function scheduleExchangeStatusRefresh() {
  clearTimeout(exchangeStatusTimer);
  if (ready && dataMode === 'live') exchangeStatusTimer = setTimeout(() => {
    refreshExchangeStatus();
    scheduleExchangeStatusRefresh();
  }, 60000 - Date.now() % 60000);
}
function scheduleForegroundMarketRefresh() {
  if (!foregroundMarketPending || foregroundMarketTimer !== null || busy || selectionLoading || marketLoading) return;
  // Browsers often emit visibilitychange, pageshow and focus for one return.
  foregroundMarketTimer = setTimeout(() => {
    foregroundMarketTimer = null;
    if (!ready || dataMode !== 'live' || document.hidden) {foregroundMarketPending = false; return;}
    if (busy || selectionLoading || marketLoading) return;
    foregroundMarketPending = false;
    if (lastMarketCheckAt !== null && Date.now() - lastMarketCheckAt < 1000) return;
    void refreshMarket();
  }, 100);
}
async function refreshMarket() {
  if (!ready || dataMode !== 'live' || marketLoading) return;
  if (selectionLoading) {scheduleMarketRefresh(); return;}
  const generation = privateGeneration;
  const selection = selectionGeneration, requestedCurrencyEpoch = currencyEpoch, requestedNumberFormatEpoch = numberFormatEpoch;
  marketLoading = true;
  try {
    const market = await client.loadMarket(instruments);
    if (!ready || generation !== privateGeneration || selection !== selectionGeneration) return;
    acceptMarket(market, requestedCurrencyEpoch, requestedNumberFormatEpoch); marketFailed = false;
    lastMarketCheckAt = Date.now();
  } catch (error) {
    if (!ready || generation !== privateGeneration || selection !== selectionGeneration) return;
    if (error.status === 401) {showAuth('Your session expired. Sign in again to load the saved portfolio.'); return;}
    marketFailed = true;
  } finally {
    if (ready && generation === privateGeneration) {
      marketLoading = false;
      if (selection === selectionGeneration) lastMarketCheckAt = Date.now();
      if (screen === 'portfolio' && !drag) {
        const focusId = document.activeElement?.id;
        if (expandedId || managing) refreshExchangeStatus(); else render();
        if (focusId) $(focusId)?.focus({preventScroll:true});
      }
      scheduleMarketRefresh();
      scheduleForegroundMarketRefresh();
    }
  }
}
async function startPrivate() {
  if (privateLoadToken) return;
  clearPrivateView();
  const token = {}; privateLoadToken = token;
  $('auth-screen').hidden = false;
  $('auth-form').hidden = true;
  $('auth-retry').hidden = true;
  $('auth-help').hidden = true;
  $('auth-description').textContent = 'Checking your sign-in…';
  try {
    const user = await client.session();
    if (privateLoadToken !== token) return;
    if (passwordToken || !user) showAuth();
    else await loadPrivatePortfolio();
  } catch (error) {
    if (privateLoadToken !== token) return;
    if (error.status === 401) {showAuth('Your session expired. Sign in again to load the saved portfolio.'); return;}
    $('auth-description').textContent = 'Your saved portfolio could not be loaded. Check your connection and try again.';
    $('auth-retry').hidden = false;
  } finally {if (privateLoadToken === token) privateLoadToken = null;}
}
function acceptMarket(market, requestedCurrencyEpoch = null, requestedNumberFormatEpoch = null) {
  quotes = market.quotes; marketIssues = market.issues;
  reportingFx = market.reportingFx ?? null; priceUpdates = market.priceUpdates ?? {};
  if (requestedCurrencyEpoch === currencyEpoch && !currencySaving && ['EUR','USD','GBP'].includes(market.reportingCurrency)) {
    reportingCurrency = market.reportingCurrency; client.reportingCurrency = reportingCurrency;
  }
  if (requestedNumberFormatEpoch === numberFormatEpoch && !numberFormatSaving && isNumberFormat(market.numberFormat)) {
    numberFormat = market.numberFormat; client.numberFormat = numberFormat;
    // Existing editor fields keep the format captured when the editor opened.
  }
}
function entry(holding) {
  const instrument = byId.get(holding.instrumentId) || unavailableInstrument(holding.instrumentId);
  const quote = quotes[holding.instrumentId] || {status:'missing'};
  return {holding,instrument,quote:reportingQuote(instrument,quote,marketIssues,reportingContext())};
}
function issuesFor(instrument) { return reportingIssues(instrument,quotes[instrument.id] || {},marketIssues,reportingContext()); }
const infoIcon = '<svg width="15" height="15" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false"><circle cx="10" cy="10" r="8"/><path d="M10 9v5M10 5v1"/></svg>';
function displayedPriceUpdate(now=Date.now()) {
  return latestPriceUpdate(holdings.filter(h=>priceUpdateHoldingIds.has(h.id)),instruments,quotes,priceUpdates,now);
}
function renderPriceUpdate(selection,effectiveMode,now=Date.now()) {
  const visible = effectiveMode === 'active' ? selection.activeEntries.map(item=>item.holding) : holdings;
  priceUpdateHoldingIds = new Set(visible.map(h=>h.id));
  const update = displayedPriceUpdate(now);
  $('price-update').textContent = priceUpdateLabel(update) || 'Last update';
  $('price-update').hidden = (!update && !awaitingData.length && !earlierData.length) || managing;
  if ($('price-update-dialog').open) renderPriceUpdateDetails(now);
  $('account-currency-value').textContent = reportingCurrency;
  $('account-number-format-value').textContent = NUMBER_FORMATS[numberFormat].example;
}
function renderPriceUpdateDetails(now=Date.now()) {
  const update = displayedPriceUpdate(now);
  $('price-update-title').textContent = update ? priceUpdateReceivedLabel(update,now) : 'Last update';
  $('price-update-list').hidden = !update;
  $('price-update-list').innerHTML = update ? update.items.map(item=>`<li><strong>${esc(item.name)}</strong><span>${esc(priceDateMessage(byId.get(item.instrumentId),quotes[item.instrumentId]))}</span></li>`).join('') : '';
  $('price-update-empty').hidden = Boolean(update) || awaitingData.length > 0 || earlierData.length > 0;
  $('earlier-data-section').hidden = earlierData.length === 0;
  $('earlier-data-list').innerHTML = earlierData.map(({name,reason})=>`<li><strong>${esc(name)}</strong><span>${esc(reason)}</span></li>`).join('');
  $('awaiting-data-section').hidden = awaitingData.length === 0;
  $('awaiting-data-title').textContent = awaitingData.every(item=>item.waitingForPrice) ? 'Awaiting first update' : 'Awaiting data';
  $('awaiting-data-list').innerHTML = awaitingData.map(item=>`<li><strong>${esc(item.name)}</strong><span>${esc(item.reason)}</span></li>`).join('');
}
function instrumentCopy(i,showObservation=false) {
  showObservation = showObservation && i.type === 'Fund';
  const observation = showObservation ? formatHoldingObservation(i,quotes[i.id]) : '';
  return `<span class="instrument-copy"><span class="instrument-name">${esc(i.name)}</span><span class="instrument-meta">${showObservation ? `Daily value <span aria-hidden="true">·</span> <span class="observation-time" data-observation-id="${esc(i.id)}">${esc(observation || 'Date unavailable')}</span>` : `${esc(i.symbol)} <span aria-hidden="true">·</span> ${esc(i.exchange)}`}</span></span>`;
}
function renderPortfolioBreakdown() {
  const entries=holdings.map(entry);
  const model=portfolioBreakdown(entries.map(({holding,instrument,quote})=>({id:holding.id,name:instrument.name,
    value:calculateHolding(holding,instrument,quote).value})),calculatePortfolio(entries));
  const signature=JSON.stringify([numberFormat,model]);
  if (signature === breakdownSignature) return;
  const focused=document.activeElement?.closest('[data-breakdown-id]');
  const focusId=focused?.dataset.breakdownId, focusKind=focused?.dataset.breakdownKind;
  breakdownModel=model; breakdownSignature=signature;
  if (![...model.items.map(item=>'list:'+item.id),...(model.slices || []).map(item=>'slice:'+item.id)].includes(breakdownSelectedId)) breakdownSelectedId=null;
  const messages={empty:'Add an investment to see your portfolio breakdown.',
    unavailable:'Some investments cannot be valued yet, so their shares of the whole portfolio are unavailable.',
    zero:'Portfolio shares are unavailable because the total value is zero.'};
  $('breakdown-message').textContent=messages[model.status] || '';
  $('breakdown-message').hidden=model.status === 'ready';
  $('breakdown-chart').hidden=model.status !== 'ready';
  $('breakdown-selection').hidden=model.status !== 'ready';
  $('breakdown-chart').innerHTML=model.status === 'ready' ? `<svg viewBox="0 0 240 240" role="group" aria-label="Portfolio shares. Choose a slice or an investment below.">${model.slices.map(item=>`<path class="breakdown-slice" d="${donutPath(item.start,item.end)}" fill="${item.color}" role="button" tabindex="0" data-breakdown-id="${esc(item.id)}" data-breakdown-kind="slice" aria-label="${esc(item.name)}, ${esc(formatPercent(item.share,{signed:false}))}" aria-pressed="false"><title>${esc(item.name)} · ${esc(formatPercent(item.share,{signed:false}))}</title></path>`).join('')}</svg><div class="breakdown-centre" aria-hidden="true"><strong id="breakdown-centre-percent">100%</strong><span id="breakdown-centre-label">Whole portfolio</span></div>` : '';
  $('breakdown-list').innerHTML=model.items.map(item=>`<li>${model.status === 'ready' ? `<button type="button" class="breakdown-row" data-breakdown-id="${esc(item.id)}" data-breakdown-kind="list" aria-pressed="false">` : '<div class="breakdown-row">'}<span class="breakdown-swatch" aria-hidden="true"><svg viewBox="0 0 16 16" focusable="false"><rect x="1" y="1" width="14" height="14" rx="4" fill="${model.status === 'ready' ? item.color : '#b9bdb7'}"/></svg></span><span class="breakdown-name">${esc(item.name)}</span><strong class="breakdown-share">${esc(formatPercent(item.share,{signed:false}))}</strong>${model.status === 'ready' ? '</button>' : '</div>'}</li>`).join('');
  updateBreakdownSelection();
  if (focusId) ([...$('breakdown-dialog').querySelectorAll('[data-breakdown-id]')].find(node=>node.dataset.breakdownId === focusId && node.dataset.breakdownKind === focusKind) || $('close-breakdown')).focus({preventScroll:true});
}
function updateBreakdownSelection() {
  const ready=breakdownModel?.status === 'ready';
  const selectedItem=ready ? breakdownModel.items.find(item=>'list:'+item.id === breakdownSelectedId) : null;
  const selectedSlice=ready ? breakdownModel.slices.find(item=>'slice:'+item.id === breakdownSelectedId) : null;
  const selected=selectedItem || selectedSlice;
  for (const node of $('breakdown-dialog').querySelectorAll('[data-breakdown-id]')) {
    const active=node.dataset.breakdownKind === 'slice'
      ? node.dataset.breakdownId === (selectedItem?.sliceId || selectedSlice?.id)
      : node.dataset.breakdownId === selectedItem?.id || Boolean(selectedSlice?.holdingIds.includes(node.dataset.breakdownId));
    node.setAttribute('aria-pressed',String(active));
    node.classList.toggle('is-selected',active);
  }
  $('breakdown-chart').classList.toggle('has-selection',Boolean(selected));
  if ($('breakdown-centre-percent')) $('breakdown-centre-percent').textContent=selected ? formatPercent(selected.share,{signed:false}) : '100%';
  if ($('breakdown-centre-label')) $('breakdown-centre-label').textContent=selectedSlice?.holdingIds.length > 1 ? 'Other investments' : selected ? 'Selected holding' : 'Whole portfolio';
  $('breakdown-selection').textContent=selected ? `${selected.name} · ${formatPercent(selected.share,{signed:false})}` : 'Tap a slice or investment to highlight it.';
}
function selectBreakdownItem(node) {
  if (!node?.dataset.breakdownId) return;
  const selection=node.dataset.breakdownKind+':'+node.dataset.breakdownId;
  breakdownSelectedId=breakdownSelectedId === selection ? null : selection;
  updateBreakdownSelection();
}
function detailLine(label,value,cls='') { return `<div class="detail-line"><span>${esc(label)}</span><strong class="${cls}">${esc(value)}</strong></div>`; }
function estimateButton(id=null) {
  return `<button id="estimate-${id === null ? 'portfolio' : esc(id)}" class="estimate-button" type="button" data-estimate="${id === null ? 'portfolio' : 'holding'}"${id === null ? '' : ` data-id="${esc(id)}"`} aria-label="Explain estimated ${id === null ? 'portfolio' : 'holding'} gain or loss" aria-haspopup="dialog" aria-controls="estimate-dialog"><span>Estimated</span><svg width="16" height="16" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false"><circle cx="10" cy="10" r="8"/><path d="M10 9v5M10 5v1"/></svg></button>`;
}
function renderEstimateDetails(opener) {
  const portfolio = !opener.holdingId, entries=holdings.map(entry);
  if (portfolio && (period !== 'purchase' || !calculatePortfolio(entries).purchaseGainEstimated)) return false;
  const estimates=purchaseEstimateDetails(entries,reportingContext())
    .filter(item=>portfolio || item.id === opener.holdingId);
  if (!estimates.length) return false;
  $('estimate-description').textContent = portfolio
    ? 'The portfolio’s since-purchase result includes estimates for:'
    : 'This holding’s since-purchase result is estimated:';
  $('estimate-list').innerHTML=estimates.map(item=>`<li><strong>${esc(item.name)}</strong> · ${esc(item.reason)}</li>`).join('');
  $('estimate-list').hidden=false;
  $('estimate-guidance').textContent = reportingCurrency === 'EUR'
    ? 'This excludes currency changes since purchase. Add the original euro cost for these holdings to include them.'
    : 'Currency gains or losses since purchase may not be fully included. Your saved purchase prices and euro costs have not been changed.';
  return true;
}
function explainEstimate(button, pointer = false) {
  if (!ready || busy) return;
  const opener={id:button.id,holdingId:button.dataset.estimate === 'portfolio' ? null : button.dataset.id,pointer};
  if (!withReportingClock(()=>renderEstimateDetails(opener))) return;
  estimateOpener = opener;
  $('estimate-dialog').showModal();
}

function detailGroups(h,i,q,c,latest,share,waitingForCurrentSession) {
  const showPurchase = period !== 'purchase', comparison = purchaseComparison(h,i,c);
  const amount = showPurchase ? comparison.amount : latest.change;
  const percentage = showPurchase ? comparison.percentage : latest.changePercent;
  const currency = showPurchase ? comparison.currency : reportingCurrency;
  const gainHeading = showPurchase ? `${amount == null ? 'Change' : amount < 0 ? 'Loss' : amount > 0 ? 'Gain' : 'No change'} since purchase` : 'Latest change';
  const missingCost = showPurchase && comparison.missingCost;
  const gainNote = showPurchase && comparison.excludesCurrencyChanges ? estimateButton(h.id)
    : amount == null ? `<p class="date-note">${missingCost ? i.currency === 'EUR' ? 'Add your average purchase price to see this result.' : 'Add your average purchase price or original cost in euros to see this result.' : showPurchase ? 'Waiting for valuation data.' : waitingForCurrentSession ? 'Waiting for today’s price and comparison.' : 'Waiting for daily comparison data.'}</p>` : '';
  const purchaseEstimate = !showPurchase && comparison.estimated ? `<div class="detail-estimate"><span>Since-purchase change</span>${estimateButton(h.id)}</div>` : '';
  return `<div class="detail-group"><h3>Your holding</h3>${detailLine('Units',formatQuantity(h.quantity))}${detailLine('Price',i.currency ? formatMoney(q.price == null ? null : Number(q.price),i.currency) : '—')}${detailLine('Value',formatMoney(c.value,reportingCurrency))}${detailLine('Share of portfolio',formatPercent(share,{signed:false}))}${detailLine('Average purchase price',i.currency ? formatMoney(h.averageCost == null ? null : Number(h.averageCost),i.currency) : '—')}${purchaseEstimate}</div>
      <div class="detail-group${showPurchase ? '' : ' latest-comparison'}"><h3>${gainHeading}</h3>${detailLine('Amount',formatMoney(amount,currency,{signed:true}),signClass(amount))}${detailLine('Percentage',formatPercent(percentage),signClass(percentage))}${gainNote}</div>`;
}
function renderDetails(h,i,q,c,latest,share,waitingForCurrentSession) {
  const messages = dataMode === 'live' ? holdingDataMessages(i,q,c,issuesFor(i),reportingContext().nowMs,{suppressFreshness:period === 'purchase'}) : [];
  return `<div id="details-${esc(h.id)}" class="holding-details" role="region" aria-label="${esc(i.name)} details">
    <div class="details-heading"><span>${esc(i.type)}</span></div>
    <div class="details-grid">${detailGroups(h,i,q,c,latest,share,waitingForCurrentSession)}</div>
    <p class="holding-data-note"${messages.length ? '' : ' hidden'}>${esc(messages.join(' '))}</p>
    <div class="details-footer"><p class="price-timestamp">${esc(priceDateMessage(i,q))}</p>${i.contributionSchedule === 'bnd_daily_amsterdam' ? '<p class="date-note">With Open markets only selected, each new daily value contributes once, during 09:00–17:30 Amsterdam on a weekday. These are RichYet’s reporting hours, not fund trading hours.</p>' : ''}</div>
  </div>`;
}
function rowChangeClass(h,i,c,latest) {
  const amount = period === 'purchase' ? purchaseComparison(h,i,c).amount : latest.change;
  return `row-change ${signClass(amount)}${period === 'active' ? ' is-latest' : ''}`;
}
function rowChangeContent(h,i,c,latest) {
  const comparison = purchaseComparison(h,i,c), purchase = period === 'purchase';
  const amount = purchase ? comparison.amount : latest.change, percentage = purchase ? comparison.percentage : latest.changePercent;
  return `<span class="change-percent">${esc(formatPercent(percentage))}</span><span class="change-amount">${esc(formatMoney(amount,purchase ? comparison.currency : reportingCurrency,{signed:true,fractionDigits:0}))}</span>`;
}
function portfolioSharesAvailable(total,calculations) {
  return total.completeValue && Number.isFinite(total.value) && total.value > 0
    && calculations.every(c => Number.isFinite(c.value) && c.value >= 0 && c.value <= total.value);
}
function renderSummary(total,entries,now=Date.now()) {
  const sincePurchase = period === 'purchase';
  // The fixed fictional demo has no real exchange calendars. Both daily modes
  // retain its example pairs; live Active alone applies session/window gates.
  const effectiveMode = holdingComparisonMode(entries);
  const selection = selectComparisonEntries(entries,effectiveMode,now,activeReadiness,reportingContext());
  const filter = comparisonFilterState(entries,selection,now);
  awaitingData = !sincePurchase && effectiveMode === 'active' && dataMode === 'live' && filter.kind === 'checkbox'
    ? selection.awaitingEntries.map(item=>({id:item.instrument.id,name:item.instrument.name,
      waitingForPrice:selection.openMarkets.waitingInstrumentIds.includes(item.instrument.id) || item.quote?.price == null,
      reason:awaitingDataReason(item,selection.openMarkets.waitingInstrumentIds.includes(item.instrument.id),reportingContext())})) : [];
  const openMarkets = effectiveMode === 'active' && dataMode === 'live' ? selection.openMarkets : null;
  const latest = calculateLatestContribution(selection.comparisonEntries,selection.referenceEntries);
  const referenceIssue = latest.includedCount > 0 && !latest.completeReference;
  const activeIssue = !sincePurchase && dataMode === 'live' && (selection.failedInstrumentIds.length > 0
    || effectiveMode === 'active' && (openMarkets?.unknownCount ?? 0) > 0);
  const partial = !sincePurchase && (referenceIssue || latest.includedCount > 0 && latest.partial
    || effectiveMode === 'active' && (openMarkets?.unknownCount ?? 0) > 0);
  const heldIssues=entries.flatMap(({instrument})=>issuesFor(instrument));
  comparisonCoverage=comparisonWarnings(entries,selection.comparisonEntries,latest,{issues:heldIssues,failed:marketFailed,
    reporting:reportingContext(),now,purchase:sincePurchase,
    unknownIds:openMarkets ? entries.filter(({instrument,quote})=>getExchangeSession(instrument,now,quote).state==='unknown').map(({instrument})=>instrument.id) : []});
  earlierData = comparisonCoverage.filter(item=>item.kind === 'earlier');
  const dataIssues = comparisonCoverage.filter(item=>item.kind !== 'earlier');
  renderPriceUpdate(selection,effectiveMode,now);
  if ($('estimate-dialog').open && estimateOpener && !renderEstimateDetails(estimateOpener)) $('estimate-dialog').close();
  const marketState = openMarkets && openMarkets.openCount + openMarkets.activeFundCount === 0 && openMarkets.unknownCount > 0 ? 'Market hours unavailable'
    : openMarkets && selection.activeEntries.length === 0 && !activeIssue ? 'No active holdings' : '';

  const amount = sincePurchase ? total.purchaseGain : latest.change;
  const percentage = sincePurchase ? total.purchaseGainPercent : latest.changePercent;
  const complete = sincePurchase ? total.completePurchaseGain : latest.includedCount > 0;
  const estimated = sincePurchase && complete && total.purchaseGainEstimated;
  const empty = holdings.length === 0;
  const cls = signClass(amount);
  $('summary-change').setAttribute('aria-label',sincePurchase ? `${estimated ? 'Estimated portfolio' : 'Portfolio'} change since purchase` : `${partial ? 'Partial contribution' : 'Contribution'} to the portfolio from ${effectiveMode === 'active' ? 'open-market' : 'all'} holdings`);
  $('summary-change').hidden = empty;
  $('summary-change').innerHTML = empty ? '' : `${marketState ? `<p class="summary-market-state">${esc(marketState)}</p>` : `<div class="summary-numbers"><p class="summary-percent ${cls}">${esc(formatPercent(complete ? percentage : null))}</p><p class="summary-amount ${cls}">${esc(wholeEuros(complete ? amount : null,true))}</p></div>`}${estimated ? estimateButton() : ''}${dataIssues.length ? `<button id="show-coverage" class="coverage-button" type="button" aria-haspopup="dialog" aria-controls="coverage-dialog">Data issue</button>` : ''}`;
  $('portfolio-screen').dataset.comparisonMode = period;
  $('portfolio-value').textContent = wholeEuros(total.value);
  $('value-caption').textContent = total.completeValue ? '' : `Known subtotal ${wholeEuros(total.knownValue)}`;
  $('value-caption').hidden = total.completeValue || total.knownValue == null;
  $('period-switch').hidden = holdings.length === 0;
  $('active-filter').hidden = holdings.length === 0 || sincePurchase;
  $('active-filter-choice').hidden = filter.kind !== 'checkbox';
  $('market-filter-status').hidden = filter.kind !== 'status';
  const fundDate = filter.text === 'Daily value' ? formatHoldingObservation(entries[0].instrument,entries[0].quote,now) : '';
  $('market-filter-status').textContent = filter.text + (fundDate ? ` · ${fundDate}` : '');
  if ((sincePurchase || filter.kind !== 'checkbox') && document.activeElement === $('active-only')) $('mode-latest').focus({preventScroll:true});
  $('active-only').checked = activeOnly;
  for (const input of document.querySelectorAll('input[name="period"]')) input.checked = input.value === (sincePurchase ? 'purchase' : 'latest');
  let status = '';
  if (holdings.length) {
    if (sincePurchase) {
      status = !total.completeValue ? 'Waiting for valuation data.'
        : !total.completePurchaseCost ? 'Add the missing average purchase prices or original euro costs to see the portfolio’s total change since purchase.'
        : !total.completePurchaseGain ? 'Waiting for valuation data.'
        : percentage == null ? (total.purchaseCost === 0 ? 'Percentage unavailable because the purchase cost is zero.' : 'Percentage unavailable.') : '';
    }
    if (!total.completeValue) status = `Some investments cannot be valued. ${status}`.trim();
    const freshness = holdings.map(h => {const {instrument,quote} = entry(h); return holdingFreshness(instrument,quote,heldIssues);});
    if (!sincePurchase && !comparisonCoverage.length && freshness.some(item => item.updateIncomplete)) status += `${status ? ' ' : ''}Some price updates are incomplete.`;
    if (!sincePurchase && !comparisonCoverage.length && (marketFailed || heldIssues.some(issue => issue.code === 'source_unavailable'))) status += `${status ? ' ' : ''}Market data could not be refreshed. Displayed amounts use the last available observations.`;
  }
  $('summary-status').textContent = status;
  $('summary-status').hidden = !status;
}
function render() {return withReportingClock(renderAtCurrentTime);}
function renderAtCurrentTime() {
  if (!ready) return;
  const now = reportingContext().nowMs, entries = holdings.map(entry), total = calculatePortfolio(entries);
  const presentation = selectHoldingSections(entries,now,holdingComparisonMode(entries),activeReadiness,reportingContext());
  const rows = presentation.entries;
  const calculations = entries.map(({holding,instrument,quote}) => calculateHolding(holding,instrument,quote));
  const completeShares = portfolioSharesAvailable(total,calculations);
  const byHolding = new Map(holdings.map((h,index)=>[h.id,calculations[index]]));
  renderSummary(total,entries,now);
  $('manage-list').textContent = managing ? 'Done' : 'Edit';
  $('manage-list').setAttribute('aria-pressed',String(managing));
  $('manage-list').disabled = !holdings.length;
  $('portfolio-breakdown').hidden = managing || !holdings.length;
  $('holdings-footer').hidden = managing || !holdings.length;
  $('add-investment').hidden = !managing;
  $('holdings-list').classList.toggle('manage-mode',managing);
  $('empty-state').hidden = holdings.length !== 0;
  const rowMarkup = new Map(rows.map(({holding:h,instrument:i,quote:q,comparisonQuote,waitingForCurrentSession,sessionState,activeFund},index) => {
    const c = byHolding.get(h.id), latest = calculateHolding(h,i,comparisonQuote), open = !managing && expandedId === h.id;
    const closed = sessionState === 'closed' || (sessionState === 'fund' && !activeFund);
    const share = completeShares ? c.value / total.value * 100 : null;
    return [h.id,`<article class="holding-item ${open ? 'is-expanded' : ''}${closed ? ' is-closed' : ''}" data-holding-id="${esc(h.id)}" data-market-id="${esc(i.id)}"><div class="holding-row">
      ${managing ? `<button class="drag-handle" id="drag-${esc(h.id)}" type="button" data-id="${esc(h.id)}" aria-label="Move ${esc(i.name)}, position ${index+1} of ${holdings.length}. Drag, use arrow keys, or activate to choose a position." aria-keyshortcuts="ArrowUp ArrowDown Home End" title="Drag or choose position" ${holdings.length<2?'disabled':''}><svg width="22" height="22" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><circle cx="8" cy="5" r="1.7"/><circle cx="16" cy="5" r="1.7"/><circle cx="8" cy="12" r="1.7"/><circle cx="16" cy="12" r="1.7"/><circle cx="8" cy="19" r="1.7"/><circle cx="16" cy="19" r="1.7"/></svg></button><button class="instrument-cell manage-instrument edit-holding" id="edit-${esc(h.id)}" type="button" data-id="${esc(h.id)}" aria-label="Edit ${esc(i.name)}">${instrumentCopy(i,true)}<svg class="edit-pencil" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m16 3 5 5M4 20l5-1L21 7a2.1 2.1 0 0 0-5-5L4 14l-1 7Z"/></svg></button><button class="remove-button" id="remove-${esc(h.id)}" type="button" data-id="${esc(h.id)}" aria-label="Remove ${esc(i.name)}"><svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13M10 11v5m4-5v5"/></svg></button>` : `<button class="holding-toggle" id="toggle-${esc(h.id)}" type="button" data-id="${esc(h.id)}" aria-expanded="${open}" aria-controls="details-${esc(h.id)}"><span class="instrument-cell">${instrumentCopy(i,true)}</span><span class="${rowChangeClass(h,i,c,latest)}">${rowChangeContent(h,i,c,latest)}</span><svg class="chevron" width="18" height="18" viewBox="0 0 18 18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m4 7 5 5 5-5"/></svg></button>`}
    </div>${open ? renderDetails(h,i,q,c,latest,share,waitingForCurrentSession) : `<div id="details-${esc(h.id)}" hidden></div>`}</article>`];
  }));
  const listMarkup = rows.map(({holding})=>rowMarkup.get(holding.id)).join('');
  $('holdings-list').innerHTML = managing ? listMarkup : `<div class="holding-group-items">${listMarkup}</div>`;
  if ($('breakdown-dialog').open) renderPortfolioBreakdown();
  updateHoldingPresentation(presentation,now);
  scheduleExchangeStatusRefresh();
  if (screen === 'portfolio') rememberPortfolioView();
}
function notify(message, removable = false) {
  if (!ready) return;
  clearTimeout(toastTimer); $('toast-message').textContent = message; $('undo-remove').hidden = !removable; $('toast').hidden = false;
  toastTimer = setTimeout(() => { $('toast').hidden = true; undo = null; },removable ? 15000 : 6500);
}
function focusHolding(id,preventScroll=true) {
  const handle = $('drag-'+id);
  (managing ? (handle && !handle.disabled ? handle : $('edit-'+id)) : $('toggle-'+id))?.focus({preventScroll});
}
function announceMove(id) {
  const index = holdings.findIndex(h => h.id === id);
  if (index >= 0) notify(`${byId.get(holdings[index].instrumentId).name} moved to position ${index+1} of ${holdings.length}.`);
}
async function moveTo(id,target) {
  if (busy || !ready) return;
  const next = moveItem(holdings,id,target);
  if (next === holdings) return;
  if (await commitHoldings(next) && ready) { render(); focusHolding(id,false); announceMove(id); }
}
function openReorder(id) {
  const index = holdings.findIndex(h=>h.id===id);
  if (!managing || index < 0 || holdings.length < 2) return;
  reorderDialogId = id;
  $('reorder-title').textContent = `Move ${byId.get(holdings[index].instrumentId).name}`;
  $('reorder-position').innerHTML = holdings.map((_,position)=>`<option value="${position}">${position+1}${position===0?' (first)':position===holdings.length-1?' (last)':''}</option>`).join('');
  $('reorder-position').value = String(index);
  $('reorder-dialog').showModal(); $('reorder-position').focus();
}
function updateDragPosition() {
  if (!drag?.started) return;
  const rows = [...$('holdings-list').children].map(element=>{
    const rect = element.getBoundingClientRect();
    return {id:element.dataset.holdingId,top:rect.top,bottom:rect.bottom};
  });
  const next = moveItem(holdings,drag.id,pointerDestination(rows,drag.id,drag.y));
  if (next === holdings) return;
  holdings = next;
  const elements = new Map([...$('holdings-list').children].map(element=>[element.dataset.holdingId,element]));
  holdings.forEach(h=>$('holdings-list').append(elements.get(h.id)));
}
function scrollWhileDragging() {
  if (!drag?.started) return;
  const edge = 72, maximum = 12;
  const step = drag.y < edge ? -maximum*Math.min(1,(edge-drag.y)/edge)
    : drag.y > window.innerHeight-edge ? maximum*Math.min(1,(drag.y-window.innerHeight+edge)/edge) : 0;
  if (step) { window.scrollBy(0,step); updateDragPosition(); }
  dragFrame = requestAnimationFrame(scrollWhileDragging);
}
function finishDrag(cancelled=false) {
  if (!drag) return null;
  const finished = drag; drag = null;
  cancelAnimationFrame(dragFrame); dragFrame = null;
  document.documentElement.style.scrollBehavior = finished.scrollBehavior;
  document.body.classList.remove('is-reordering');
  $('holdings-list').classList.remove('is-reordering');
  if ($('holdings-list').hasPointerCapture?.(finished.pointerId)) $('holdings-list').releasePointerCapture(finished.pointerId);
  suppressClickUntil = Date.now()+500;
  if (finished.started) {
    const changed = holdings.some((h,index)=>h.id!==finished.original[index].id);
    const next = holdings;
    holdings = finished.original;
    render();
    if (cancelled) notify('Move cancelled.');
    else if (changed) void commitHoldings(next).then(saved => {
      if (saved && ready) { render(); if (document.hasFocus()) focusHolding(finished.id); announceMove(finished.id); }
    });
    if (document.hasFocus()) focusHolding(finished.id);
  }
  return finished;
}
async function remove(id) {
  if (busy || !ready) return;
  const index = holdings.findIndex(h => h.id === id); if(index<0) return;
  const holding = holdings[index];
  if (!await commitHoldings(holdings.filter(h=>h.id!==id)) || !ready) return;
  undo={holding,index};
  if(expandedId===id) expandedId=null;
  if(!holdings.length) managing=false;
  render();
  const next=holdings[Math.min(index,holdings.length-1)];
  if(next) focusHolding(next.id); else $('empty-add').focus();
  notify(`${byId.get(holding.instrumentId).name} removed.`,true);
}
function clearErrors() {
  for(const id of ['quantity','average-cost','eur-cost']) { $(id).removeAttribute('aria-invalid'); $(id+'-error').textContent=''; }
  $('form-error').textContent='';
}
function cancelSearch() {
  searchGeneration++;
  clearTimeout(searchTimer); searchTimer = null;
  searchController?.abort(); searchController = null; searchLoading = false;
  $('search-status').textContent = '';
  $('search-retry').hidden = true;
  $('search-more').disabled = false;
}
function cancelSelectionCheck() {
  selectionGeneration++;
  selectionController?.abort(); selectionController = null;
  selectionLoading = false; selectionRetryId = null;
  cancelSearch();
  $('search-results').removeAttribute('aria-busy');
  $('catalogue-status').textContent = '';
  $('catalogue-refresh').disabled = false;
  $('retry-preparation').hidden = true;
  $('retry-selection').disabled = false;
  scheduleForegroundMarketRefresh();
}
function installCatalogue(catalogue) {
  const owned = new Set(holdings.map(h => h.instrumentId));
  instruments = mergeInstruments(instruments.filter(i => owned.has(i.id)), catalogue.instruments);
  byId = new Map(instruments.map(i => [i.id, i]));
  for (const holding of holdings) if (!byId.has(holding.instrumentId)) byId.set(holding.instrumentId, unavailableInstrument(holding.instrumentId));
  acceptMarket(catalogue.market);
  lastMarketCheckAt = Date.now();
  catalogueFailed = false; marketFailed = false;
  // Keep discovery-only rows separate from the trusted, priced catalogue.
  searchResults = searchResults.map(i => byId.has(i.id) ? {...byId.get(i.id), preparationStatus:canAddInstrument(byId.get(i.id)) ? 'ready' : 'available'} : i);
}
function liveSearchActive() { return dataMode === 'live' && $('instrument-search').value.trim().length >= 2; }
function queueSearch(immediate = false) {
  cancelSearch();
  searchTerm = $('instrument-search').value.trim();
  searchResults = []; searchHasMore = false; searchOffset = 0; searchFailedOffset = null;
  if (!liveSearchActive()) {renderSearch(); return;}
  searchLoading = true;
  $('search-status').textContent = 'Searching investments…';
  renderSearch();
  const generation = searchGeneration;
  searchTimer = setTimeout(() => {if (generation === searchGeneration) void searchInvestments(0);}, immediate ? 0 : 300);
}
async function searchInvestments(offset = 0) {
  if (busy || !ready || screen !== 'add' || formReturning || !liveSearchActive() || selectionLoading) return;
  const term = $('instrument-search').value.trim();
  const generation = ++searchGeneration, account = privateGeneration;
  searchController?.abort();
  const controller = new AbortController(); searchController = controller;
  searchLoading = true; searchFailedOffset = null;
  $('search-status').textContent = offset ? 'Loading more investments…' : 'Searching investments…';
  $('search-retry').hidden = true;
  renderSearch();
  try {
    const result = await client.searchInstruments(term, offset, {signal:controller.signal});
    if (generation !== searchGeneration || account !== privateGeneration || !ready || screen !== 'add' || formReturning || term !== $('instrument-search').value.trim()) return;
    searchTerm = term;
    searchResults = offset ? mergeInstruments(searchResults, result.instruments) : result.instruments;
    searchHasMore = result.hasMore; searchOffset = offset + result.instruments.length;
    $('search-status').textContent = searchResults.length ? `${searchResults.length} investment${searchResults.length === 1 ? '' : 's'} found${searchHasMore ? '. More available below.' : '.'}` : 'No matching supported investment found. Try its ticker or ISIN.';
  } catch (error) {
    if (generation !== searchGeneration || account !== privateGeneration || !ready || screen !== 'add' || formReturning) return;
    if (error.status === 401) {showAuth('Your session expired. Sign in again to load the saved portfolio.'); return;}
    if (error.code === 'cancelled') return;
    searchFailedOffset = offset;
    $('search-status').textContent = 'Search is unavailable right now. Your search is kept; please try again.';
    $('search-retry').hidden = false;
  } finally {
    if (generation === searchGeneration && account === privateGeneration && ready) {
      searchLoading = false; searchController = null; renderSearch();
    }
  }
}
async function checkSelection(id = null, preserveFields = false) {
  if (busy || !ready || screen !== 'add' || formReturning) return;
  if (dataMode !== 'live') {if (id) showSelection(id); return;}
  cancelSearch();
  selectionController?.abort();
  const controller = new AbortController(); selectionController = controller;
  const selection = ++selectionGeneration, generation = privateGeneration;
  selectionLoading = true; selectionRetryId = null;
  $('retry-preparation').hidden = true;
  $('catalogue-refresh').disabled = true;
  $('retry-selection').disabled = true;
  $('save-investment').disabled = true;
  const chosen = id ? searchResults.find(i => i.id === id) || byId.get(id) : null;
  const checking = chosen ? `Checking ${chosen.name}’s price and latest comparison…` : 'Checking the latest price and comparison…';
  if (preserveFields) {
    $('selected-readiness').hidden = true;
    $('form-error').textContent = checking;
  }
  else $('catalogue-status').textContent = id ? checking : 'Refreshing the investment list…';
  renderSearch();
  try {
    const result = id ? await client.prepareInstrument(id, {signal:controller.signal})
      : {status:'ready', catalogue:await client.loadInstruments({signal:controller.signal})};
    if (!ready || generation !== privateGeneration || selection !== selectionGeneration || screen !== 'add' || formReturning) return;
    if (result.catalogue) installCatalogue(result.catalogue);
    const instrument = id ? byId.get(id) : null;
    if (id && result.status === 'ready' && instrument && canAddInstrument(instrument) && !holdings.some(h => h.instrumentId === id)) {
      showSelection(id, !preserveFields);
    } else if (id) {
      const message = result.message || (result.status === 'busy' ? 'Another price check is in progress. Please try again shortly.'
        : instrument ? admissionMessage(instrument) : 'A current price and comparison are not available for this investment yet.');
      if (preserveFields) {
        $('form-error').textContent = `${message} Your entries are kept.`;
        $('retry-selection').hidden = false;
      } else {
        $('catalogue-status').textContent = message;
        selectionRetryId = id; $('retry-preparation').hidden = false;
      }
    } else $('catalogue-status').textContent = 'Investment list updated.';
  } catch (error) {
    if (!ready || generation !== privateGeneration || selection !== selectionGeneration || screen !== 'add' || formReturning) return;
    if (error.status === 401) {showAuth('Your session expired. Sign in again to load the saved portfolio.'); return;}
    if (error.code === 'cancelled') return;
    const message = 'The latest price and comparison could not be checked. Please try again.';
    if (preserveFields) {$('form-error').textContent = `${message} Your entries are kept.`; $('retry-selection').hidden = false;}
    else {
      $('catalogue-status').textContent = message;
      if (id) {selectionRetryId = id; $('retry-preparation').hidden = false;}
      else catalogueFailed = true;
    }
  } finally {
    if (ready && generation === privateGeneration && selection === selectionGeneration) {
      selectionLoading = false; selectionController = null;
      $('catalogue-refresh').disabled = false;
      $('retry-selection').disabled = false;
      renderSearch(); scheduleMarketRefresh(); scheduleForegroundMarketRefresh();
    }
  }
}
function renderSearch() {
  const term=$('instrument-search').value.trim().toLocaleLowerCase('en');
  $('catalogue-refresh').hidden = dataMode !== 'live' || !catalogueFailed;
  if (!term) {
    searchOptions = []; searchIndex = -1;
    $('instrument-search').removeAttribute('aria-activedescendant');
    $('instrument-search').setAttribute('aria-expanded', 'false');
    $('search-results').replaceChildren();
    $('search-results').hidden = true;
    $('search-results').removeAttribute('aria-busy');
    $('search-more').hidden = true;
    return;
  }
  $('search-results').hidden = false;
  $('instrument-search').setAttribute('aria-expanded', 'true');
  const remote=liveSearchActive();
  const matching=remote ? (searchTerm.toLocaleLowerCase('en') === term ? searchResults : [])
    : instruments.filter(i=>`${i.name} ${i.symbol} ${i.isin || ''} ${i.exchange} ${i.type}`.toLocaleLowerCase('en').includes(term));
  const status = i => dataMode !== 'live' ? 'ready' : i.preparationStatus || (canAddInstrument(i) ? 'ready'
    : i.admission?.status === 'temporarily_unavailable' ? 'available' : 'unavailable');
  searchOptions=matching.filter(i=>status(i) !== 'unavailable' && !holdings.some(h=>h.instrumentId===i.id) && !selectionLoading); searchIndex=-1;
  $('instrument-search').removeAttribute('aria-activedescendant');
  $('search-results').setAttribute('aria-busy', String(searchLoading || selectionLoading));
  $('search-results').innerHTML = matching.length ? matching.map(i=>{
    const owned=holdings.some(h=>h.instrumentId===i.id), state=status(i), unsupported=state==='unavailable';
    const message = i.message || (unsupported ? admissionMessage(i) : '');
    return `<button type="button" class="search-option" role="option" aria-selected="false" id="option-${esc(i.id)}" data-instrument="${esc(i.id)}" ${owned||unsupported||selectionLoading?'disabled aria-disabled="true"':''}><span class="search-copy"><strong>${esc(i.name)}</strong><span>${esc(i.symbol)} · ${esc(i.exchange)} · ${esc(i.currency || 'Currency unavailable')}</span>${i.isin ? `<small>${esc(i.isin)}</small>` : ''}${dataMode === 'live' && message && !owned ? `<small>${esc(message)}</small>` : ''}</span><span class="search-badge">${owned?'Added':unsupported?'Unavailable':dataMode === 'live'?state==='ready'?'Ready':'Check prices':esc(i.type)}</span></button>`;
  }).join('') : `<p class="search-no-results">${dataMode === 'live' ? searchLoading ? 'Searching…' : remote ? searchFailedOffset !== null ? 'Your results could not be loaded.' : 'No matching investment found.' : catalogueFailed ? 'The investment list could not be loaded. Refresh the list to try again.' : term.length === 1 ? 'Type at least two characters to search more investments.' : 'Search by name, ticker, ISIN or exchange.' : 'No matching investments in this demo.<br>Try “Oak”, “Pacific” or “Aurora”.'}</p>`;
  $('search-more').hidden = !remote || !searchHasMore;
  $('search-more').disabled = searchLoading || selectionLoading;
}
function showSelection(id,focus=true) {
  const i=byId.get(id); if(!i) return;
  if (!editingId && !canAddInstrument(i)) return;
  selectedId=id; $('search-step').hidden=true; $('holding-fields').hidden=false;
  $('selected-instrument').innerHTML=`${instrumentCopy(i)}<span class="search-badge">${esc(i.currency)}</span>`;
  $('selected-readiness').hidden = dataMode !== 'live' || Boolean(editingId);
  $('selected-readiness').textContent = 'Price and latest comparison ready.';
  $('retry-selection').hidden = true;
  $('average-cost-label').textContent=`Average purchase price (${i.currency === 'EUR' ? '€' : i.currency === 'USD' ? '$' : i.currency})${dataMode === 'live' ? ' · optional' : ''}`;
  $('eur-cost-field').hidden=i.currency==='EUR';
  $('change-selection').hidden=Boolean(editingId);
  clearErrors();
  $('save-investment').disabled = !i.currency;
  if (!i.currency) $('form-error').textContent = 'The listing details are unavailable. You can still move or remove this investment from your list.';
  if(focus) $('quantity').focus();
}
function portfolioView() {
  return {period,activeOnly,managing,expandedId,opener:lastOpenerId,scrollY:Math.max(0,Math.round(window.scrollY))};
}
function rememberPortfolioView() {
  history.replaceState({richyetScreen:'portfolio',richyetView:portfolioView()},'',location.href);
}
function restorePortfolioView(view,restorePeriod=true) {
  if (!view || typeof view !== 'object') return;
  if (restorePeriod) {
    activeOnly = typeof view.activeOnly === 'boolean' ? view.activeOnly : view.period === 'purchase' ? activeOnly : normalizeComparisonMode(view.period) === 'active';
    period = comparisonModeForPreferences({mode:view.period === 'purchase' ? 'purchase' : 'latest',activeOnly});
    saveComparisonPreferences();
  }
  managing = Boolean(view.managing) && holdings.length > 0;
  expandedId = holdings.some(h=>h.id===view.expandedId) ? view.expandedId : null;
  lastOpenerId = typeof view.opener === 'string' ? view.opener : 'manage-list';
}
function prepareForm(id) {
  cancelSelectionCheck();
  inputNumberFormat = numberFormat;
  const inputFormat = NUMBER_FORMATS[inputNumberFormat];
  $('number-input-hint').textContent = `Use ${inputFormat.name} numbers, for example ${inputFormat.example}.`;
  $('quantity').placeholder = `e.g. ${formatDecimalInput('12.5',inputNumberFormat)}`;
  $('average-cost').placeholder = `e.g. ${formatDecimalInput('100.00',inputNumberFormat)}`;
  $('eur-cost').placeholder = `e.g. ${inputFormat.example}`;
  editingId=id; selectedId=null; formReturning=false;
  $('investment-form').reset(); clearErrors();
  searchResults = []; searchTerm = ''; searchHasMore = false; searchOffset = 0; searchFailedOffset = null;
  $('investment-screen-title').textContent=id ? 'Edit your holding' : 'Add an investment';
  $('save-investment').textContent=id ? 'Save changes' : 'Add investment';
  $('save-investment').disabled=false;
  $('retry-selection').hidden = true;
  $('catalogue-refresh').hidden = dataMode !== 'live' || !catalogueFailed;
  $('search-hint').textContent = dataMode === 'live'
    ? 'Search by name, ticker, ISIN or exchange. We check prices and the latest comparison before you enter your holding.'
    : 'Try a name, ticker or exchange in this fictional catalogue.';
  $('search-step').hidden=Boolean(id); $('holding-fields').hidden=!id;
  if(id) {
    const h=holdings.find(h=>h.id===id);
    showSelection(h.instrumentId,false);
    $('quantity').value=formatDecimalInput(h.quantity,inputNumberFormat);
    $('average-cost').value=formatDecimalInput(h.averageCost,inputNumberFormat);
    $('eur-cost').value=formatDecimalInput(h.eurCost,inputNumberFormat);
  } else renderSearch();
  formBaseline = formValues();
  formNavigation = {url:location.href,state:history.state};
}
function formValues() {
  return JSON.stringify([selectedId,$('quantity').value,$('average-cost').value,$('eur-cost').value]);
}
function formHasUnsavedChanges() {
  return ready && screen !== 'portfolio' && formBaseline !== null && formValues() !== formBaseline;
}
function confirmDiscardChanges() {
  return !formHasUnsavedChanges() || window.confirm('Discard your unsaved changes?');
}
function handleNavigation(event) {
  if (busy) {routeQueued = true; return;}
  const token = /^#password\/([A-Za-z0-9_-]{32,200})$/.exec(location.hash)?.[1];
  if (!demoMode && token) {
    passwordToken = token;
    history.replaceState(null, '', location.pathname + location.search);
    void startPrivate();
    return;
  }
  if (restoringFormNavigation) {
    if (location.href === formNavigation?.url) restoringFormNavigation = false;
    return;
  }
  if (!formReturning && formNavigation && location.href !== formNavigation.url && !confirmDiscardChanges()) {
    if (event?.type === 'popstate' && formNavigation.state?.richyetFormReturn === true) {
      // Our editor was opened from the portfolio. Restore that history entry,
      // keeping the existing form DOM and the user's draft untouched.
      restoringFormNavigation = true;
      history.forward();
    } else history.replaceState(formNavigation.state,'',formNavigation.url);
    return;
  }
  applyRoute();
}
function applyRoute(initial=false) {
  if (busy) {routeQueued = true; return;}
  if (!ready) return;
  $('auth-screen').hidden = true;
  $('open-about').hidden = false;
  const edit = /^#edit\/([\w-]{1,60})$/.exec(location.hash);
  const editing = edit && holdings.some(h=>h.id===edit[1]);
  const route = location.hash === '#add' ? 'add' : editing ? `edit/${edit[1]}` : 'portfolio';
  const invalidEdit = location.hash.startsWith('#edit') && !editing;
  if (route === 'portfolio' && location.hash) history.replaceState(history.state,'',location.pathname+location.search);
  if (screen === route && !initial) return;
  screen=route;
  accountMenu.close();
  finishDrag(true);
  for (const dialog of document.querySelectorAll('dialog[open]')) dialog.close();
  if (initial) restorePortfolioView(history.state?.richyetView,false);
  const isForm=route!=='portfolio';
  $('portfolio-screen').hidden=isForm;
  $('investment-screen').hidden=!isForm;
  $('portfolio-header').hidden=isForm;
  $('form-page-header').hidden=!isForm;
  if (isForm) {
    $('form-return-label').textContent=editing ? 'Back' : 'Portfolio';
    prepareForm(editing ? edit[1] : null);
    document.title=`${editing ? 'Edit holding' : 'Add investment'} — RichYet?`;
    window.scrollTo({top:0,behavior:'instant'});
    $('investment-screen-title').focus({preventScroll:true});
  } else {
    cancelSelectionCheck();
    const view=history.state?.richyetView || portfolioView();
    restorePortfolioView(view,!initial);
    const focusId=pendingReturn?.focusId || lastOpenerId;
    if (pendingReturn?.expandedId) expandedId=pendingReturn.expandedId;
    pendingReturn=null; editingId=null; selectedId=null; formReturning=false;
    formBaseline=null; formNavigation=null; restoringFormNavigation=false;
    document.title='RichYet? — Portfolio';
    render();
    window.scrollTo({top:Number.isFinite(view.scrollY) ? Math.max(0,view.scrollY) : 0,behavior:'instant'});
    rememberPortfolioView();
    if (!initial) {
      const target=$(focusId);
      if (target && !target.closest('[hidden]') && !target.disabled) target.focus({preventScroll:true});
      else $('main').focus({preventScroll:true});
    }
    if (invalidEdit) notify('That holding is no longer in this portfolio.');
  }
}
function openForm(id=null,opener='add-investment') {
  if (busy || !ready || screen!=='portfolio' || (id && !holdings.some(h=>h.id===id))) return;
  lastOpenerId=opener; rememberPortfolioView();
  history.pushState({richyetFormReturn:true,richyetView:portfolioView()},'',id ? `#edit/${id}` : '#add');
  applyRoute();
}
function closeForm({saved=false}={}) {
  if (busy || screen==='portfolio' || formReturning || restoringFormNavigation) return;
  if (!saved && !confirmDiscardChanges()) return;
  cancelSelectionCheck();
  formReturning=true;
  $('save-investment').disabled=true;
  if (history.state?.richyetFormReturn === true) history.back();
  else {
    history.replaceState({richyetView:history.state?.richyetView || portfolioView()},'',location.pathname+location.search);
    applyRoute();
  }
}
function readField(id,allowZero,optional=false) {
  const value=$(id).value.trim();
  if(optional && !value) return null;
  try {
    const parsed=parseLocalizedNumber(value,{allowZero,numberFormat:inputNumberFormat});
    if(!numberPattern.test(parsed)) throw new Error('Use at most 12 digits before the decimal separator and 6 after it.');
    return parsed;
  } catch(error) {
    $(id).setAttribute('aria-invalid','true'); $(id+'-error').textContent=error.message; return undefined;
  }
}

$('holdings-list').addEventListener('click',event=>{
  if (Date.now()<suppressClickUntil && event.detail!==0) {event.preventDefault();return;}
  const button=event.target.closest('button'); if(!button) return;
  const id=button.dataset.id;
  if(button.dataset.estimate) explainEstimate(button,event.detail > 0);
  else if(button.classList.contains('holding-toggle')) {expandedId=expandedId===id?null:id; render(); $('toggle-'+id)?.focus({preventScroll:true});}
  else if(button.classList.contains('edit-holding')) openForm(id,button.id);
  else if(button.classList.contains('drag-handle')) openReorder(id);
  else if(button.classList.contains('remove-button')) remove(id);
});
$('holdings-list').addEventListener('keydown',event=>{
  const handle = event.target.closest('.drag-handle');
  if (!handle || drag) return;
  const index = holdings.findIndex(h=>h.id===handle.dataset.id);
  const targets = {ArrowUp:index-1,ArrowDown:index+1,Home:0,End:holdings.length-1};
  if (Object.hasOwn(targets,event.key)) {event.preventDefault();moveTo(handle.dataset.id,targets[event.key]);}
});
$('holdings-list').addEventListener('pointerdown',event=>{
  const handle = event.target.closest('.drag-handle');
  if (busy || !ready || !managing || drag || !handle || handle.disabled || !event.isPrimary || event.button!==0) return;
  event.preventDefault(); handle.focus({preventScroll:true});
  drag = {id:handle.dataset.id,pointerId:event.pointerId,x:event.clientX,y:event.clientY,startX:event.clientX,startY:event.clientY,started:false,original:holdings.slice(),scrollBehavior:document.documentElement.style.scrollBehavior};
  $('holdings-list').setPointerCapture?.(event.pointerId);
});
document.addEventListener('pointermove',event=>{
  if (!drag || event.pointerId!==drag.pointerId) return;
  event.preventDefault(); drag.x=event.clientX; drag.y=event.clientY;
  if (!drag.started && Math.hypot(drag.x-drag.startX,drag.y-drag.startY)>=6) {
    drag.started=true;
    document.documentElement.style.scrollBehavior='auto';
    document.body.classList.add('is-reordering'); $('holdings-list').classList.add('is-reordering');
    $('drag-'+drag.id).closest('.holding-item').classList.add('is-dragging');
    dragFrame=requestAnimationFrame(scrollWhileDragging);
  }
  updateDragPosition();
},{passive:false});
document.addEventListener('pointerup',event=>{
  if (!drag || event.pointerId!==drag.pointerId) return;
  const finished=finishDrag();
  if (!finished.started) openReorder(finished.id);
});
document.addEventListener('pointercancel',event=>{if(drag && event.pointerId===drag.pointerId) finishDrag(true);});
$('holdings-list').addEventListener('lostpointercapture',event=>{if(drag && event.pointerId===drag.pointerId) finishDrag(true);});
document.addEventListener('keydown',event=>{if(event.key==='Escape' && drag){event.preventDefault();finishDrag(true);}});
window.addEventListener('blur',()=>{if(drag) finishDrag(true);});
document.addEventListener('visibilitychange',()=>{if(document.hidden && drag) finishDrag(true);if(!document.hidden)queueForegroundMarketRefresh();});
$('reorder-cancel').addEventListener('click',()=>$('reorder-dialog').close());
$('reorder-save').addEventListener('click',()=>{
  const id=reorderDialogId,target=Number($('reorder-position').value);
  $('reorder-dialog').close(); moveTo(id,target);
});
$('reorder-dialog').addEventListener('close',()=>{
  const id=reorderDialogId; reorderDialogId=null;
  if(id) focusHolding(id);
});
function saveComparisonPreferences() {
  writeComparisonPreferences({mode:period === 'purchase' ? 'purchase' : 'latest',activeOnly});
}
function updateComparisonView() {return withReportingClock(updateComparisonViewAtCurrentTime);}
function updateComparisonViewAtCurrentTime() {
  saveComparisonPreferences();
  const now=reportingContext().nowMs, entries=holdings.map(entry);
  renderSummary(calculatePortfolio(entries),entries,now);
  updateHoldingPresentation(selectHoldingSections(entries,now,holdingComparisonMode(entries),activeReadiness,reportingContext()),now);
  if (screen === 'portfolio') rememberPortfolioView();
}
$('period-switch').addEventListener('change',event=>{
  if (!event.target.matches('input[name="period"]')) return;
  period = comparisonModeForPreferences({mode:event.target.value === 'purchase' ? 'purchase' : 'latest',activeOnly});
  updateComparisonView();
});
$('active-only').addEventListener('change',()=>{
  if (period === 'purchase') return;
  activeOnly = $('active-only').checked;
  period = activeOnly ? 'active' : 'all';
  updateComparisonView();
});
$('account-currency').addEventListener('click',()=>{
  if (!ready || busy || currencySaving || numberFormatSaving) return;
  accountMenu.close();
  $('reporting-currency').value = reportingCurrency;
  $('currency-error').textContent = '';
  $('currency-dialog').showModal();
});
for (const id of ['close-currency','cancel-currency']) $(id).addEventListener('click',()=>{
  if (!currencySaving) $('currency-dialog').close();
});
$('currency-dialog').addEventListener('cancel',event=>{if(currencySaving)event.preventDefault();});
$('currency-form').addEventListener('submit',async event=>{
  event.preventDefault();
  if (!ready || busy || currencySaving || numberFormatSaving) return;
  const currency = portfolioCurrency($('reporting-currency').value), generation = privateGeneration;
  if (currency === reportingCurrency) {$('currency-dialog').close();return;}
  currencySaving = true; currencyEpoch++;
  for (const id of ['save-currency','cancel-currency','close-currency','reporting-currency']) $(id).disabled = true;
  $('currency-error').textContent = '';
  try {
    if (!demoMode) await client.saveReportingCurrency(currency);
    if (!ready || generation !== privateGeneration) return;
    reportingCurrency = currency;
    $('currency-dialog').close();
    render();
    notify(`Portfolio currency saved: ${currency}.`);
  } catch (error) {
    if (generation !== privateGeneration) return;
    if (error.status === 401) {showAuth('Your session expired. Sign in again.');return;}
    $('currency-error').textContent = 'The currency change could not be confirmed. Close this window and reload to check your saved setting.';
  } finally {
    currencySaving = false; currencyEpoch++;
    for (const id of ['save-currency','cancel-currency','close-currency','reporting-currency']) $(id).disabled = false;
  }
});
$('account-number-format').addEventListener('click',event=>{
  if (!ready || busy || numberFormatSaving || currencySaving) return;
  accountMenu.close();
  numberFormatOpener = {id:'account-button',pointer:event.detail > 0};
  $('number-format').value = numberFormat;
  $('number-format-error').textContent = '';
  $('number-format-dialog').showModal();
});
for (const id of ['close-number-format','cancel-number-format']) $(id).addEventListener('click',()=>{
  if (!numberFormatSaving) $('number-format-dialog').close();
});
$('number-format-dialog').addEventListener('cancel',event=>{if(numberFormatSaving)event.preventDefault();});
$('number-format-dialog').addEventListener('close',()=>{
  const opener = numberFormatOpener; numberFormatOpener = null; restoreInfoFocus(opener);
});
$('number-format-form').addEventListener('submit',async event=>{
  event.preventDefault();
  if (!ready || busy || numberFormatSaving || currencySaving) return;
  const chosen = $('number-format').value, generation = privateGeneration;
  if (!isNumberFormat(chosen)) return;
  if (chosen === numberFormat) {$('number-format-dialog').close();return;}
  numberFormatSaving = true; numberFormatEpoch++;
  for (const id of ['save-number-format','cancel-number-format','close-number-format','number-format']) $(id).disabled = true;
  $('number-format-error').textContent = '';
  try {
    if (!demoMode) await client.saveNumberFormat(chosen);
    if (!ready || generation !== privateGeneration) return;
    numberFormat = chosen;
    $('number-format-dialog').close();
    render();
    notify(`Number format saved: ${NUMBER_FORMATS[chosen].example}.`);
  } catch (error) {
    if (generation !== privateGeneration) return;
    if (error.status === 401) {showAuth('Your session expired. Sign in again.');return;}
    $('number-format-error').textContent = 'The number format change could not be confirmed. Close this window and reload to check your saved setting.';
  } finally {
    if (generation === privateGeneration) {
      numberFormatSaving = false; numberFormatEpoch++;
      for (const id of ['save-number-format','cancel-number-format','close-number-format','number-format']) $(id).disabled = false;
    }
  }
});
$('price-update').addEventListener('click',event=>{
  if (!displayedPriceUpdate() && !awaitingData.length && !earlierData.length) return;
  priceUpdateOpener = {id:'price-update',pointer:event.detail > 0};
  renderPriceUpdateDetails();
  $('price-update-dialog').showModal();
});
$('close-price-update').addEventListener('click',()=>$('price-update-dialog').close());
$('price-update-dialog').addEventListener('close',()=>{
  const opener=priceUpdateOpener; priceUpdateOpener=null; restoreInfoFocus(opener);
});
$('portfolio-breakdown').addEventListener('click',event=>{
  breakdownOpener={id:'portfolio-breakdown',pointer:event.detail > 0};
  breakdownSelectedId=null; breakdownSignature=null;
  withReportingClock(renderPortfolioBreakdown);
  $('breakdown-dialog').showModal();
  $('close-breakdown').focus();
});
$('breakdown-dialog').addEventListener('click',event=>selectBreakdownItem(event.target.closest('[data-breakdown-id]')));
$('breakdown-chart').addEventListener('keydown',event=>{
  if (event.key !== 'Enter' && event.key !== ' ') return;
  const node=event.target.closest('[data-breakdown-id]');
  if (!node) return;
  event.preventDefault(); selectBreakdownItem(node);
});
$('close-breakdown').addEventListener('click',()=>$('breakdown-dialog').close());
$('breakdown-dialog').addEventListener('close',()=>{
  const opener=breakdownOpener; breakdownOpener=null; restoreInfoFocus(opener);
});
$('summary-change').addEventListener('click',event=>{
  const estimate = event.target.closest('button[data-estimate]');
  if (estimate?.dataset.estimate) {explainEstimate(estimate,event.detail > 0);return;}
  if (!event.target.closest('#show-coverage')) return;
  coverageOpener = {id:'show-coverage',pointer:event.detail > 0};
  $('coverage-title').textContent = 'Data issue';
  $('coverage-description').textContent = 'Some investment data needs attention.';
  $('coverage-list').innerHTML = comparisonCoverage.filter(item=>item.kind !== 'earlier').map(({name,reason})=>`<li><strong>${esc(name)}</strong> · ${esc(reason)}</li>`).join('');
  $('coverage-dialog').showModal();
  $('close-coverage').focus();
});
$('close-estimate').addEventListener('click',()=>$('estimate-dialog').close());
$('estimate-dialog').addEventListener('close',()=>{
  const opener = estimateOpener; estimateOpener = null; restoreInfoFocus(opener);
});
function restoreInfoFocus(opener) {
  if (!opener || !ready) return;
  const target = [$(opener.id),opener.holdingId ? $('toggle-'+opener.holdingId) : null,
    document.querySelector(`input[name="period"][value="${period === 'purchase' ? 'purchase' : 'latest'}"]`)].find(node=>node && !node.closest('[hidden]'));
  if (!target) return;
  if (opener.pointer) {
    pointerFocusTarget?.classList.remove('pointer-return-focus');
    pointerFocusTarget = target; target.classList.add('pointer-return-focus');
    target.addEventListener('blur',()=>target.classList.remove('pointer-return-focus'),{once:true});
  }
  target.focus({preventScroll:true});
}
document.addEventListener('keydown',()=>{
  if (estimateOpener) estimateOpener.pointer = false;
  if (priceUpdateOpener) priceUpdateOpener.pointer = false;
  if (coverageOpener) coverageOpener.pointer = false;
  if (breakdownOpener) breakdownOpener.pointer = false;
  if (numberFormatOpener) numberFormatOpener.pointer = false;
  pointerFocusTarget?.classList.remove('pointer-return-focus'); pointerFocusTarget = null;
});
$('close-coverage').addEventListener('click',()=>$('coverage-dialog').close());
$('coverage-dialog').addEventListener('close',()=>{const opener=coverageOpener;coverageOpener=null;restoreInfoFocus(opener);});
$('manage-list').addEventListener('click',()=>{finishDrag(true);managing=!managing;expandedId=null;render();});
$('add-investment').addEventListener('click',()=>openForm());
$('empty-add').addEventListener('click',()=>openForm(null,'empty-add'));
$('search-results').addEventListener('click',event=>{const option=event.target.closest('[data-instrument]'); if(option && !option.disabled) void checkSelection(option.dataset.instrument);});
$('instrument-search').addEventListener('input',()=>{cancelSelectionCheck();queueSearch();});
$('catalogue-refresh').addEventListener('click',()=>{cancelSelectionCheck();if(liveSearchActive())queueSearch(true);else void checkSelection();});
$('search-more').addEventListener('click',()=>void searchInvestments(searchOffset));
$('search-retry').addEventListener('click',()=>void searchInvestments(searchFailedOffset ?? 0));
$('retry-preparation').addEventListener('click',()=>{if(selectionRetryId)void checkSelection(selectionRetryId);});
$('retry-selection').addEventListener('click',()=>void checkSelection(selectedId, true));
$('instrument-search').addEventListener('keydown',event=>{
  if(event.key==='ArrowDown'||event.key==='ArrowUp') {
    event.preventDefault(); if(!searchOptions.length) return;
    const direction=event.key==='ArrowDown'?1:-1;
    searchIndex=searchIndex<0 ? (direction>0?0:searchOptions.length-1) : (searchIndex+direction+searchOptions.length)%searchOptions.length;
    document.querySelectorAll('.search-option').forEach(el=>el.setAttribute('aria-selected','false'));
    const option=$('option-'+searchOptions[searchIndex].id); if(!option)return; option.setAttribute('aria-selected','true'); option.scrollIntoView({block:'nearest'}); $('instrument-search').setAttribute('aria-activedescendant',option.id);
  } else if(event.key==='Enter') {event.preventDefault(); if(searchIndex>=0) void checkSelection(searchOptions[searchIndex].id); else if(liveSearchActive() && !selectionLoading)queueSearch(true);}
});
$('change-selection').addEventListener('click',()=>{cancelSelectionCheck();selectedId=null;$('holding-fields').hidden=true;$('search-step').hidden=false;renderSearch();$('instrument-search').focus();});
document.querySelector('.cancel-form').addEventListener('click',closeForm);
$('form-portfolio-return').addEventListener('click',event=>{event.preventDefault();closeForm();});
document.querySelector('.wordmark').addEventListener('click',event=>{
  if(busy) {event.preventDefault();return;}
  if(ready && screen!=='portfolio') {event.preventDefault();closeForm();}
});
document.querySelector('.skip-link').addEventListener('click',event=>{event.preventDefault();$('main').focus();});
window.addEventListener('popstate',handleNavigation);
window.addEventListener('hashchange',handleNavigation);
window.addEventListener('pageshow',event=>{
  if (event.persisted && !ready) {if (!demoMode) void startPrivate();}
  else if (event.persisted && demoMode) {holdings=loadHoldings(); screen=null; render(); applyRoute();}
  else queueForegroundMarketRefresh();
});
window.addEventListener('focus',queueForegroundMarketRefresh);
$('investment-form').addEventListener('submit',async event=>{
  event.preventDefault(); if(busy || !ready || selectionLoading || $('save-investment').disabled || screen==='portfolio' || formReturning || !selectedId) return; clearErrors();
  if (!byId.get(selectedId)?.currency) {$('form-error').textContent='The listing details are unavailable. Reload the portfolio before editing this holding.'; return;}
  const i=byId.get(selectedId),quantity=readField('quantity',false),averageCost=readField('average-cost',true,dataMode==='live'),eurCost=i.currency==='EUR'?null:readField('eur-cost',true,true);
  if([quantity,averageCost,eurCost].includes(undefined)) {document.querySelector('[aria-invalid="true"]')?.focus();return;}
  if(!editingId && holdings.some(h=>h.instrumentId===selectedId)) {$('form-error').textContent='This investment is already in your portfolio. Edit its existing holding instead.';return;}
  const id=editingId||crypto.randomUUID(),h={id,instrumentId:selectedId,quantity,averageCost,eurCost};
  const isEdit=Boolean(editingId);
  const next = isEdit ? holdings.map(old=>old.id===editingId?h:old) : [...holdings,h];
  if (!await commitHoldings(next) || !ready) return;
  if (screen !== 'portfolio') {
    pendingReturn={expandedId:managing ? null : id,focusId:managing ? 'edit-'+id : 'toggle-'+id};
    closeForm({saved:true});
  } else render();
  notify(isEdit?'Holding updated.':`${i.name} added.`);
});
$('undo-remove').addEventListener('click',async ()=>{
  if(!undo || busy || !ready) return;
  const restoring = undo, next = holdings.slice();
  if(next.some(h=>h.instrumentId===restoring.holding.instrumentId)) return;
  next.splice(Math.min(restoring.index,next.length),0,restoring.holding);
  if (!await commitHoldings(next) || !ready) return;
  undo=null; render(); focusHolding(restoring.holding.id); notify('Investment restored.');
});
$('close-toast').addEventListener('click',()=>{$('toast').hidden=true;undo=null;clearTimeout(toastTimer);});
$('reset-demo').addEventListener('click',()=>$('reset-dialog').showModal());
$('close-reset').addEventListener('click',()=>$('reset-dialog').close());
$('cancel-reset').addEventListener('click',()=>$('reset-dialog').close());
$('confirm-reset').addEventListener('click',async ()=>{
  $('reset-dialog').close();
  if (dataMode === 'live') return;
  if (!await commitHoldings(initialHoldings()) || !ready) return;
  expandedId=null;managing=false;undo=null;render();notify('Example portfolio restored.');
});
for(const d of document.querySelectorAll('dialog')) d.addEventListener('click',e=>{if(e.target===d){const r=d.getBoundingClientRect();if(e.clientX<r.left||e.clientX>r.right||e.clientY<r.top||e.clientY>r.bottom) d.close();}});
$('auth-form').addEventListener('submit', async event => {
  event.preventDefault();
  if (authBusy) return;
  $('auth-error').textContent = '';
  const password = $('auth-password').value;
  if (passwordToken && new TextEncoder().encode(password).length > 72) { $('auth-error').textContent = 'This password is too long. Use fewer characters, especially symbols or emoji.'; return; }
  if (passwordToken && password !== $('auth-confirm').value) { $('auth-error').textContent = 'The passwords do not match.'; $('auth-confirm').focus(); return; }
  authBusy = true;
  $('auth-submit').disabled = true;
  let signedIn = false;
  try {
    // Refresh the anonymous CSRF token too, so an idle sign-in form still works.
    await client.session();
    if (passwordToken) await client.setPassword(passwordToken, password);
    else await client.login($('auth-email').value.trim(), password);
    signedIn = true;
    passwordToken = null;
    $('auth-password').value = ''; $('auth-confirm').value = '';
    await loadPrivatePortfolio();
  } catch (error) {
    if (signedIn) {
      if (error.status === 401) {showAuth('Your session expired. Sign in again to load the saved portfolio.'); return;}
      $('auth-form').hidden = true;
      $('auth-description').textContent = 'Signed in, but your saved portfolio could not be loaded. Please try again.';
      $('auth-retry').hidden = false;
      return;
    }
    $('auth-error').textContent = error.status === 401 ? 'The email address or password was not recognised.'
      : error.code === 'invalid_token' ? 'This link has expired or has already been used. Ask the administrator of this installation for a new recovery link.'
      : error.status === 429 ? 'Too many attempts. Please wait a few minutes and try again.'
      : 'Could not finish signing in. Please try again.';
    $('auth-return').hidden = !passwordToken;
    $('auth-password').value = ''; $('auth-confirm').value = '';
  } finally { authBusy = false; $('auth-submit').disabled = false; }
});
$('auth-retry').addEventListener('click', () => void startPrivate());
$('reload-portfolio').addEventListener('click', async () => {
  if (busy) return;
  $('reload-portfolio').disabled = true;
  setBusy(true);
  try { await loadPrivatePortfolio(); }
  catch (error) {
    if (error.status === 401) showAuth('Your session expired. Sign in again to load the saved portfolio.');
    else connectionNotice('Your saved portfolio could not be loaded. Check your connection and try again.', true);
  } finally {
    setBusy(false);
    $('reload-portfolio').disabled = false;
  }
});
$('sign-out').addEventListener('click', async () => {
  if (busy) return;
  setBusy(true);
  try {
    await client.session();
    await client.logout();
    history.replaceState(null, '', location.pathname + location.search);
    showAuth();
  } catch (error) {
    if (error.status === 401) showAuth();
    else connectionNotice('Sign-out could not be confirmed. Please try again before leaving this device.');
  } finally { setBusy(false); }
});
$('open-about').addEventListener('click', event => {
  if (busy) {event.preventDefault(); return;}
  if (ready && screen === 'portfolio') {lastOpenerId='open-about'; rememberPortfolioView();}
});
window.addEventListener('pagehide', () => {if (!demoMode) clearPrivateView(true);});
window.addEventListener('beforeunload', event => { if (busy || formHasUnsavedChanges()) {event.preventDefault();event.returnValue='';} });
if (demoMode) {render(); applyRoute(true);} else void startPrivate();
