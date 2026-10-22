import { aboutContentForMode } from './lib/about.mjs';
import { PortfolioClient } from './lib/persistence.mjs';

const demoMode = new URLSearchParams(location.search).get('demo') === '1';
const content = document.getElementById('about-content');
const homeURL = new URL(demoMode ? './?demo=1' : './', location.href);
content.innerHTML = aboutContentForMode(demoMode ? 'demo' : 'private');
document.querySelector('.skip-link')?.addEventListener('click', event => {
  event.preventDefault();
  document.getElementById('main').focus();
});
for (const link of document.querySelectorAll('.portfolio-return')) {
  link.href = homeURL.href;
  link.addEventListener('click', event => {
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    // Returning through history restores the portfolio's filter, expansion and
    // scroll position. A direct About visit still has a normal home link.
    let fromPortfolio = false;
    try {
      const previous = new URL(document.referrer);
      fromPortfolio = previous.origin === homeURL.origin && previous.pathname === homeURL.pathname
        && (new URLSearchParams(previous.search).get('demo') === '1') === demoMode;
    } catch {}
    if (fromPortfolio && history.length > 1) {event.preventDefault(); history.back();}
  });
}
if (!demoMode) {
  const client = new PortfolioClient();
  let generation = 0;
  async function loadContent() {
    const current = ++generation;
    content.innerHTML = aboutContentForMode('private');
    try {
      if (await client.session() && current === generation) {
        content.innerHTML = aboutContentForMode(client.dataMode === 'live' ? 'live' : 'fictional');
      }
    } catch { /* The generic private explanation remains valid while offline. */ }
  }
  window.addEventListener('pagehide', () => {generation++; content.innerHTML = aboutContentForMode('private');});
  window.addEventListener('pageshow', event => {if (event.persisted) void loadContent();});
  await loadContent();
}
