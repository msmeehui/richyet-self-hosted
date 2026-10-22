import {PortfolioClient} from './lib/persistence.mjs';
import {normalizeStatus, renderStatus} from './lib/status.mjs';

export function createStatusPage({doc = document, win = window, client = null} = {}) {
  const get = id => doc.getElementById(id);
  client ||= new PortfolioClient({endpoint:doc.body.dataset.api || './api/index.php'});
  const home = doc.body.dataset.appHome || './';
  const content = get('status-content'), refresh = get('status-refresh'), message = get('status-message');
  get('status-sign-in').href = home;
  get('status-portfolio-return').href = home;
  doc.querySelector?.('.skip-link')?.addEventListener('click', event => {
    event.preventDefault();
    get('main').focus();
  });
  let generation = 0, loading = false, active = true, controller = null;
  function clear() {content.replaceChildren();content.hidden = true;}
  function say(value) {message.textContent = value;message.hidden = !value;}
  function invalidate() {generation++;controller?.abort();controller = null;loading = false;clear();}
  async function load() {
    if (loading || !active) return;
    const current = ++generation;
    loading = true;controller = new AbortController();
    clear();refresh.disabled = true;get('status-sign-in').hidden = true;
    say('Checking your access…');
    try {
      const user = await client.session();
      if (current !== generation || !active) return;
      if (!user) {refresh.hidden = true;get('status-sign-in').hidden = false;say('Sign in with the primary account to view Data status.');return;}
      if (user.canViewStatus !== true) {refresh.hidden = true;say('Data status is available to the primary account only.');return;}
      refresh.hidden = false;say('Reading saved status…');
      const status = normalizeStatus(await client.loadStatus({signal:controller.signal}));
      if (current !== generation || !active) return;
      content.innerHTML = renderStatus(status,{numberFormat:client.numberFormat});content.hidden = false;say('');
    } catch (error) {
      if (current !== generation || !active) return;
      clear();
      if (error.status === 401) {refresh.hidden = true;get('status-sign-in').hidden = false;say('Your session expired. Sign in again to view Data status.');}
      else if (error.status === 403) {refresh.hidden = true;say('Data status is available to the primary account only.');}
      else {refresh.hidden = false;say('Saved status could not be loaded. Please try again.');}
    } finally {if (current === generation) {loading = false;controller = null;refresh.disabled = false;}}
  }
  refresh.addEventListener('click',()=>void load());
  win.addEventListener('pagehide',()=>{active = false;invalidate();say('Checking your access…');});
  win.addEventListener('pageshow',event=>{if(event.persisted){active = true;void load();}});
  return {ready:load(),reload:load};
}
if (typeof document !== 'undefined') createStatusPage();
