// Private data stays in memory. Only the explicitly selected demo uses browser storage.
import {normalizeNumberFormat, isNumberFormat} from './number-format.mjs';
import {normalizeInstruments, normalizeMarketSnapshot, normalizeCatalogue, normalizeSearchResults, normalizePreparation} from './market.mjs';
export class PortfolioError extends Error {
  constructor(message, {status = 0, code = 'unavailable'} = {}) {
    super(message);
    this.name = 'PortfolioError';
    this.status = status;
    this.code = code;
  }
}

const copy = value => structuredClone(value);
const decimal = /^(?:0|[1-9]\d{0,11})(?:\.\d{1,6})?$/;
export function validateHoldings(holdings, instrumentIds, {allowUnknownCost = false} = {}) {
  if (!Array.isArray(holdings) || holdings.length > (instrumentIds?.size ?? 500)) throw new PortfolioError('The saved portfolio could not be read.');
  const ids = new Set(), instruments = new Set();
  for (const holding of holdings) {
    if (!holding || Object.keys(holding).sort().join(',') !== 'averageCost,eurCost,id,instrumentId,quantity'
      || typeof holding.id !== 'string' || !/^[\w-]{1,60}$/.test(holding.id) || ids.has(holding.id)
      || typeof holding.instrumentId !== 'string' || !/^[\w-]{1,60}$/.test(holding.instrumentId)
      || (instrumentIds && !instrumentIds.has(holding.instrumentId)) || instruments.has(holding.instrumentId)
      || typeof holding.quantity !== 'string' || !decimal.test(holding.quantity) || Number(holding.quantity) <= 0
      || !(allowUnknownCost && holding.averageCost === null) && (typeof holding.averageCost !== 'string' || !decimal.test(holding.averageCost))
      || (holding.eurCost !== null && (typeof holding.eurCost !== 'string' || !decimal.test(holding.eurCost)))) {
      throw new PortfolioError('The saved portfolio contains unsupported values. Nothing has been replaced.');
    }
    ids.add(holding.id); instruments.add(holding.instrumentId);
  }
  return copy(holdings);
}

export class PortfolioClient {
  constructor({instrumentIds = [], fetchImpl = (...args) => fetch(...args), endpoint = './api/index.php', timeoutMs = 20000, prepareTimeoutMs = 45000} = {}) {
    this.instrumentIds = new Set(instrumentIds);
    this.fetchImpl = fetchImpl;
    this.endpoint = endpoint;
    this.timeoutMs = timeoutMs;
    this.prepareTimeoutMs = prepareTimeoutMs;
    this.requests = new Set();
    this.csrfToken = null;
    this.user = null;
    this.dataMode = null;
    this.reportingCurrency = 'EUR';
    this.numberFormat = 'nl-NL';
    this.snapshot = null;
    this.needsReload = false;
    this.saving = false;
    this.sessionGeneration = 0;
  }

  async request(action, method = 'GET', body, {params = {}, signal, timeoutMs = this.timeoutMs} = {}) {
    const generation = this.sessionGeneration;
    const controller = new AbortController();
    this.requests.add(controller);
    const cancel = () => controller.abort();
    signal?.addEventListener('abort', cancel, {once:true});
    if (signal?.aborted) cancel();
    const timeout = setTimeout(() => controller.abort(), timeoutMs);
    try {
      if (signal?.aborted) throw new PortfolioError('Request cancelled.', {code:'cancelled'});
      const query = new URLSearchParams({action, ...params});
      const response = await this.fetchImpl(`${this.endpoint}?${query}`, {
        method, credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: controller.signal,
        headers: {Accept: 'application/json', ...(body === undefined ? {} : {'Content-Type': 'application/json', 'X-CSRF-Token': this.csrfToken || ''})},
        ...(body === undefined ? {} : {body: JSON.stringify(body)}),
      });
      const data = await response.json().catch(() => null);
      if (signal?.aborted) throw new PortfolioError('Request cancelled.', {code:'cancelled'});
      if (!response.ok) {
        if (response.status === 401 && generation === this.sessionGeneration) this.user = null;
        throw new PortfolioError(data?.error?.message || 'RichYet could not complete that request.', {status: response.status, code: data?.error?.code});
      }
      if (!data || typeof data !== 'object') throw new PortfolioError('RichYet returned an unreadable response.');
      return data;
    } catch (error) {
      if (error instanceof PortfolioError) throw error;
      if (signal?.aborted) throw new PortfolioError('Request cancelled.', {code:'cancelled'});
      throw new PortfolioError('Could not reach RichYet. Check your connection and try again.');
    } finally { clearTimeout(timeout); signal?.removeEventListener('abort', cancel); this.requests.delete(controller); }
  }

  acceptSession(session) {
    if (typeof session.authenticated !== 'boolean' || typeof session.csrfToken !== 'string' || !session.csrfToken
      || (session.authenticated && typeof session.user?.email !== 'string')) throw new PortfolioError('The sign-in response could not be read.');
    const identityChanged = this.user?.email !== (session.authenticated ? session.user.email : undefined);
    if (this.csrfToken !== session.csrfToken || identityChanged) {
      this.sessionGeneration++;
      for (const controller of this.requests) controller.abort();
    }
    if (identityChanged) {this.snapshot = null; this.needsReload = false;}
    this.csrfToken = session.csrfToken;
    this.user = session.authenticated ? {email: session.user.email} : null;
    if (this.user && session.user.canViewStatus === true) this.user.canViewStatus = true;
    const displayName = typeof session.user?.displayName === 'string' ? session.user.displayName.trim() : '';
    if (this.user && displayName && [...displayName].length <= 120 && !/[\p{Cc}\p{Cf}]/u.test(displayName)) {
      this.user.displayName = displayName;
    }
    this.dataMode = ['fictional', 'live'].includes(session.dataMode) ? session.dataMode : null;
    this.reportingCurrency = ['EUR','USD','GBP'].includes(session.reportingCurrency) ? session.reportingCurrency : 'EUR';
    this.numberFormat = normalizeNumberFormat(session.numberFormat);
    if (!this.user) this.snapshot = null;
    return this.user;
  }

  async sessionRequest(action, method = 'GET', body) {
    const generation = this.sessionGeneration;
    const response = await this.request(action, method, body);
    if (generation !== this.sessionGeneration) throw new PortfolioError('Sign-in changed. Reload the saved portfolio.', {code:'session_changed'});
    return this.acceptSession(response);
  }
  async session() { return this.sessionRequest('session'); }
  async saveReportingCurrency(currency) {
    if (!['EUR','USD','GBP'].includes(currency)) throw new PortfolioError('Choose EUR, USD or GBP.');
    const generation = this.sessionGeneration;
    const response = await this.request('settings', 'PUT', {currency});
    if (generation !== this.sessionGeneration) throw new PortfolioError('Sign-in changed. Reload your portfolio.', {code:'session_changed'});
    if (response.currency !== currency) throw new PortfolioError('The currency change could not be confirmed. Reload your portfolio.');
    this.reportingCurrency = currency;
    return currency;
  }
  async saveNumberFormat(numberFormat) {
    if (!isNumberFormat(numberFormat)) throw new PortfolioError('Choose Dutch or English number formatting.');
    const generation = this.sessionGeneration;
    const response = await this.request('settings', 'PUT', {numberFormat});
    if (generation !== this.sessionGeneration) throw new PortfolioError('Sign-in changed. Reload your portfolio.', {code:'session_changed'});
    if (response.numberFormat !== numberFormat) throw new PortfolioError('The number format change could not be confirmed. Reload your portfolio.');
    this.numberFormat = numberFormat;
    return numberFormat;
  }
  async loadStatus({signal} = {}) {
    const generation = this.sessionGeneration;
    const response = await this.request('status', 'GET', undefined, {signal});
    if (generation !== this.sessionGeneration) throw new PortfolioError('Sign-in changed. Reload Data status.', {code:'session_changed'});
    return response;
  }
  async login(email, password) { return this.sessionRequest('login', 'POST', {email, password}); }
  async setPassword(token, password) { return this.sessionRequest('password', 'POST', {token, password}); }
  async logout() { await this.sessionRequest('logout', 'POST', {}); this.needsReload = false; }

  validateSnapshot(snapshot) {
    if (!Number.isSafeInteger(snapshot.revision) || snapshot.revision < 0 || !['fictional', 'live'].includes(snapshot.dataMode)) {
      throw new PortfolioError('This version of RichYet cannot read the saved portfolio. Nothing has been replaced.');
    }
    const live = snapshot.dataMode === 'live';
    return {revision: snapshot.revision,
      holdings: validateHoldings(snapshot.holdings, live ? null : this.instrumentIds, {allowUnknownCost:live}),
      dataMode: snapshot.dataMode, updatedAt: snapshot.updatedAt,
      ...(live ? {instruments:normalizeInstruments(snapshot.instruments)} : {})};
  }

  async loadPortfolio() {
    if (this.saving) throw new PortfolioError('Wait for the current save to finish.');
    const generation = this.sessionGeneration;
    const response = await this.request('portfolio');
    if (generation !== this.sessionGeneration) throw new PortfolioError('Sign-in changed. Reload the saved portfolio.', {code:'session_changed'});
    this.snapshot = this.validateSnapshot(response);
    this.needsReload = false;
    return copy(this.snapshot);
  }

  async loadInstruments({signal} = {}) {
    const generation = this.sessionGeneration;
    const response = await this.request('instruments', 'GET', undefined, {signal});
    if (generation !== this.sessionGeneration) throw new PortfolioError('Sign-in changed. Reload the investment list.', {code:'session_changed'});
    return normalizeCatalogue(response);
  }

  async searchInstruments(query, offset = 0, {signal} = {}) {
    if (typeof query !== 'string' || query.trim().length > 160 || !Number.isSafeInteger(offset) || offset < 0 || offset > 10000) {
      throw new PortfolioError('Use a shorter search and try again.');
    }
    const generation = this.sessionGeneration;
    const response = await this.request('search', 'GET', undefined, {params:{query:query.trim(), offset:String(offset)}, signal});
    if (generation !== this.sessionGeneration) throw new PortfolioError('Sign-in changed. Search again.', {code:'session_changed'});
    return normalizeSearchResults(response, offset);
  }

  async prepareInstrument(id, {signal} = {}) {
    if (typeof id !== 'string' || !/^[\w-]{1,60}$/.test(id)) throw new PortfolioError('Choose an investment from the search results.');
    const generation = this.sessionGeneration;
    const response = await this.request('prepare', 'POST', {instrumentId:id}, {signal, timeoutMs:this.prepareTimeoutMs});
    if (generation !== this.sessionGeneration) throw new PortfolioError('Sign-in changed. Check the investment again.', {code:'session_changed'});
    return normalizePreparation(response, id);
  }

  async loadMarket(instruments) {
    const generation = this.sessionGeneration;
    const response = await this.request('market');
    if (generation !== this.sessionGeneration) throw new PortfolioError('Sign-in changed. Reload the market data.', {code:'session_changed'});
    return normalizeMarketSnapshot(response, instruments);
  }

  async savePortfolio(holdings) {
    if (this.saving) throw new PortfolioError('Wait for the current save to finish.');
    if (!this.snapshot || this.needsReload) throw new PortfolioError('Reload the saved portfolio before making another change.');
    const live = this.snapshot.dataMode === 'live';
    const next = validateHoldings(holdings, live ? null : this.instrumentIds, {allowUnknownCost:live});
    const revision = this.snapshot.revision;
    const generation = this.sessionGeneration, dataMode = this.snapshot.dataMode;
    this.saving = true;
    try {
      const saved = this.validateSnapshot(await this.request('portfolio', 'PUT', {revision, holdings: next}));
      if (generation !== this.sessionGeneration) throw new PortfolioError('Sign-in changed. Reload the saved portfolio.', {code:'session_changed'});
      if (saved.dataMode !== dataMode) throw new PortfolioError('The server did not confirm the expected portfolio.');
      if (saved.revision !== revision + 1 || JSON.stringify(saved.holdings) !== JSON.stringify(next)) {
        // Property ordering is not significant; compare each accepted holding explicitly.
        const same = saved.revision === revision + 1 && saved.holdings.length === next.length
          && saved.holdings.every((holding, index) => Object.keys(holding).every(key => holding[key] === next[index][key]));
        if (!same) throw new PortfolioError('The server did not confirm the expected portfolio.');
      }
      this.snapshot = saved;
      return copy(saved);
    } catch (error) {
      if (generation !== this.sessionGeneration) throw error;
      // This explicit server rejection happens before any write. Keep the draft
      // and revision usable; conflicts and uncertain responses still need reload.
      if (error.status === 422 && error.code === 'instrument_unavailable') throw error;
      // A lost response may still have committed. Never retry against a guessed revision.
      this.needsReload = true;
      throw error;
    } finally { this.saving = false; }
  }
}
