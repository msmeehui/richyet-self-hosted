// Static, trusted markup for the About page.
export const aboutContent = `
  <div class="about-intro">
    <p class="about-creator">Made by <a href="mailto:marc.smeehuijzen@easytech.nl">marc.smeehuijzen@easytech.nl</a>, with Codex.</p>
  </div>
  <div class="about-sections">
    <details class="about-section" name="richyet-about">
      <summary><span>How to read your portfolio</span><span class="about-section-chevron" aria-hidden="true"></span></summary>
      <div class="about-section-body">
        <h3>Your portfolio</h3>
        <p>Choose EUR, USD or GBP under Portfolio currency in your account menu. Choose Dutch (1.234,56) or English (1,234.56) under Number format; this also sets how you enter amounts. Quoted prices keep their original currency. Open Portfolio breakdown below the list to see each investment’s share of the whole portfolio, or expand an investment for its individual share.</p>
        <h3>Latest change</h3>
        <p>Each investment is compared with its preceding closing price or fund valuation, including currency changes. Investments can use different dates, so this is not a single calendar-day return.</p>
        <p><strong>Open markets only</strong> shows holdings with usable updates in their current session. When checked, the amount and percentage show how these holdings affect your whole portfolio. Both filter settings compare the change with the same previous value of the whole portfolio, so the percentages stay comparable.</p>
        <p>With this filter checked, a new BND daily value is included during one weekday 09:00–17:30 Amsterdam window. These are RichYet’s reporting hours, not fund trading hours.</p>
        <p>RichYet keeps the last valid figures when an update is delayed. Last update names any investments using earlier data. “Data issue” means some figures cannot safely be shown.</p>
        <h3>Since purchase</h3>
        <p>Gain or loss on the units you still own, excluding sold investments and dividends received. When the original cost in your portfolio currency is unknown, we estimate it using the latest exchange rate. This can leave out currency gains or losses since purchase.</p>
        <h3>Prices and supported investments</h3>
        <p>Prices may be delayed. Expand a holding for its price date and time; ~ marks an approximate closing time. Times use your device’s time zone. Funds show their daily-value date. Open Last update below the list to see which displayed investments received the latest prices, when they arrived and the dates or times those prices represent. With Open markets only checked, it also lists investments still waiting for their first usable update.</p>
        <p>RichYet supports selected stocks and ETFs in euros or US dollars, plus checked funds. Individual bonds, options, futures and cryptocurrencies are not supported.</p>
      </div>
    </details>

    <details class="about-section" name="richyet-about">
      <summary><span>Data sources</span><span class="about-section-chevron" aria-hidden="true"></span></summary>
      <div class="about-section-body">
        <p><strong>This is a fictional preview.</strong> Investments, prices and currency rates are examples, fixed at 9 October 2026. The funds deliberately use 8 October valuations to show their publication delay. Prices do not refresh.</p>
        <p>Demo changes are saved in this browser only. They do not sync between devices, and clearing browser data removes them.</p>
        <dl class="about-source-list">
          <div><dt>US shares and currency rates</dt><dd><a href="https://docs.alpaca.markets/us/docs/market-data-faq" target="_blank" rel="noopener noreferrer">Alpaca</a> and <a href="https://tickerlayer.com/docs/products/forex" target="_blank" rel="noopener noreferrer">TickerLayer</a> supply the private live version. Neither is connected to this preview.</dd></div>
          <div><dt>European investments and funds</dt><dd>The private live version uses selected exchange, historical-price and fund-provider sources. This preview uses only fixed fictional values.</dd></div>
        </dl>
      </div>
    </details>

    <details class="about-section" name="richyet-about">
      <summary><span>Add to home screen</span><span class="about-section-chevron" aria-hidden="true"></span></summary>
      <div class="about-section-body">
        <p>Add a shortcut for quick access to RichYet, using the same coin icon as the browser tab. If an older shortcut shows a different icon, add RichYet again from your browser.</p>
        <h3>iPhone or iPad · Chrome</h3>
        <ol>
          <li>Open RichYet in Chrome and tap <strong>Share</strong> beside the address bar.</li>
          <li>Choose <strong>Add to Home Screen</strong>, check the name, then tap <strong>Add</strong>.</li>
        </ol>
        <p><a href="https://support.google.com/chrome/answer/15085120?co=GENIE.Platform%3DiOS&amp;hl=en-GB" target="_blank" rel="noopener noreferrer">Google’s Chrome guide</a></p>
        <h3>iPhone · Safari</h3>
        <ol>
          <li>Open RichYet in Safari. Tap <strong>Share</strong>, or open the page menu first to find it.</li>
          <li>Choose <strong>Add to Home Screen</strong>. Turn on <strong>Open as Web App</strong> if offered, then tap <strong>Add</strong>.</li>
        </ol>
        <p><a href="https://support.apple.com/guide/iphone/open-as-web-app-iphea86e5236/ios" target="_blank" rel="noopener noreferrer">Apple’s Safari guide</a></p>
        <h3>Android · Chrome</h3>
        <ol>
          <li>Open RichYet in Chrome and open the <strong>More</strong> menu beside the address bar.</li>
          <li>Choose <strong>Install and create shortcut</strong>, then <strong>Create shortcut</strong>. Check the name and tap <strong>Add</strong>.</li>
        </ol>
        <p><a href="https://support.google.com/chrome/answer/15085120?co=GENIE.Platform%3DAndroid&amp;hl=en-GB" target="_blank" rel="noopener noreferrer">Google’s Android guide</a></p>
        <p>The shortcut needs an internet connection. This preview has no account or cross-device sync.</p>
      </div>
    </details>

    <details class="about-section" name="richyet-about">
      <summary><span>Changelog</span><span class="about-section-chevron" aria-hidden="true"></span></summary>
      <div class="about-section-body about-changelog">
        <h3>21 October 2026 · Clearer display and personal formatting</h3>
        <p>Choose Dutch or English number formatting. A warmer design and improved layouts make RichYet easier to read, including with larger text. Last update brings price dates and routine update delays together.</p>
        <h3>17 October 2026 · Keep useful figures during delayed updates</h3>
        <p>Last valid prices and comparisons stay visible when an update fails, with their original dates.</p>
        <h3>16 October 2026 · Portfolio breakdown and simpler editing</h3>
        <p>Explore your portfolio in a donut chart and named list. Use Edit to add, change, reorder or remove holdings.</p>
        <h3>15 October 2026 · Portfolio currencies and market filter</h3>
        <p>Choose EUR, USD or GBP. Open markets only shows the eligible holdings’ contribution to your whole portfolio; switch it off to see all holdings.</p>
        <h3>11 October 2026 · Broader investment search</h3>
        <p>Search stocks and ETFs by name or symbol. RichYet checks the selected listing’s price data before you add your holding.</p>
        <h3>10 October 2026 · Private portfolio</h3>
        <p>Save your holdings across devices and compare Latest change with Since purchase.</p>
      </div>
    </details>
  </div>
`;

export function aboutContentForMode(mode) {
  if (mode === true || mode === 'demo') return aboutContent.replace(
    /<h3>Latest change<\/h3>[\s\S]*?(?=<h3>Since purchase<\/h3>)/,
    '<h3>Latest change and Open markets only</h3><p>This fictional preview uses fixed example comparisons with either filter setting. In the live app, Open markets only includes ready open-market holdings and eligible daily funds. Both filter settings compare the change with the same previous value of the whole portfolio.</p>\n        '
  );
  if (mode === 'live' || mode === 'private') {
    const dataStart = aboutContent.indexOf('        <p><strong>This is a fictional preview.</strong>');
    const dataEnd = aboutContent.indexOf('      </div>', dataStart);
    const explanation = mode === 'live'
      ? `<dl class="about-source-list">
          <div><dt>US shares and ETFs</dt><dd><a href="https://docs.alpaca.markets/us/docs/market-data-faq" target="_blank" rel="noopener noreferrer">Alpaca</a> supplies delayed US prices, closing prices and session hours. <a href="https://tickerlayer.com/docs/products/forex" target="_blank" rel="noopener noreferrer">TickerLayer</a> supplies hourly EUR/USD rates for euro comparisons.</dd></div>
          <div><dt>Portfolio currency conversion</dt><dd>USD and GBP reporting use daily reference rates from the <a href="https://www.ecb.europa.eu/stats/policy_and_exchange_rates/euro_reference_exchange_rates/html/index.en.html" target="_blank" rel="noopener noreferrer">European Central Bank</a>. Cross-currency conversions are calculated from those rates; they are daily references, not live trading quotes.</dd></div>
          <div><dt>European investments</dt><dd>Selected listings use delayed <a href="https://marketdata.euronext.com/data-reporting-service/trades-file" target="_blank" rel="noopener noreferrer">Euronext</a> and <a href="https://www.mds.deutsche-boerse.com/mds-en/real-time-data/Delayed-data" target="_blank" rel="noopener noreferrer">Deutsche Börse/Xetra</a> observations. Closing-price history also comes from <a href="https://www.newtonanalytics.com/docs/api/stockprice.php" target="_blank" rel="noopener noreferrer">Newton Analytics</a>. <a href="https://eodhd.com/financial-apis/api-splits-dividends" target="_blank" rel="noopener noreferrer">EODHD</a> helps check for share splits, so a split is not mistaken for a price loss.</dd></div>
          <div><dt>Supported funds</dt><dd><a href="https://new.brandnewday.nl/fondsen-en-koersen/" target="_blank" rel="noopener noreferrer">Brand New Day</a> supplies valuations for the exact supported fund share class.</dd></div>
        </dl>`
      : '<p>Sign in to see your private portfolio and its data availability. The separate demo uses fictional investments and fixed example prices.</p>';
    return (aboutContent.slice(0, dataStart) + '        ' + explanation
      + aboutContent.slice(dataEnd))
      .replace('The shortcut needs an internet connection. This preview has no account or cross-device sync.', 'The shortcut needs an internet connection. Sign in with your email address and password to open your saved portfolio. Open or reload the portfolio on another device to see the latest saved holdings.');
  }
  return aboutContent
    .replace('<strong>This is a fictional preview.</strong>', '<strong>This private version still uses fictional data.</strong>')
    .replace('Demo changes are saved in this browser only. They do not sync between devices, and clearing browser data removes them.', 'Holdings and their order are saved to your signed-in account after the server confirms each change. Open or reload the portfolio on another device to see the latest saved version. Do not enter real holdings while the fictional catalogue is in use.')
    .replace('Neither is connected to this preview.', 'Neither is connected to this private test version.')
    .replace('The shortcut needs an internet connection. This preview has no account or cross-device sync.', 'The shortcut needs an internet connection. Sign in with your email address and password to open your saved portfolio.');
}
