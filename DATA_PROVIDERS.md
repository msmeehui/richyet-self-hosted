# Your data accounts

RichYet’s code is free. Your installation needs its own data access and existing hosting. Start with a small personal portfolio and free accounts; do not assume that every provider, country or listing qualifies. This package creates no subscriptions and includes no credentials or prices.

## Current connections

| Source | Used for | Setup |
| --- | --- | --- |
| Alpaca | Delayed US prices, previous closes, corporate-action checks and session hours | Your own **paper-account** key and secret. The adapter uses the paper API for identities/calendars and the data API for historical SIP prices. It does not place trades. |
| TickerLayer | Current and historical EUR/USD conversion | Your own key with access to the forex endpoints below. Required by the current runtime even if your initial selection is EUR-only. |
| Euronext | Delayed prices for reviewed Amsterdam/Paris/Brussels listings | Public exchange files; no key for those files. Exact listing and schedule mappings are still required. |
| Newton Analytics | European previous closing prices | Public historical endpoint, no key. Availability and exact listing coverage can vary. |
| EODHD | Additional split checks for European comparisons | Your own key. This is not the source of the European quoted price. |
| Deutsche Börse/Xetra | Delayed trades and instrument reference data | Public files, no key; not included in the starter catalogue. Initial history/correction coverage can take time. |
| ECB | Daily reporting rates for EUR/USD/GBP portfolio totals | Public reference rates; no key. This is separate from the historical EUR/USD rate needed for a US daily comparison. |
| Brand New Day | Selected, specifically reviewed daily-valued funds | Optional adapter, not in the starter catalogue. Exact fund/share-class and usage permission need review. |

Put credentials only in the private file described in INSTALL.md. Its placeholders are not working keys. The config can point both credential-path fields at that same private file. If your catalogue is US-only, remove the optional `market_eodhd_credentials_path` setting; Alpaca and TickerLayer remain required. Nothing connects to the original RichYet installation’s accounts.

## Set up your own free access

The following was checked against public provider documentation on **21 October 2026**. It establishes an advertised setup route, not a successful test with a newly registered account. No accounts, subscriptions or authenticated provider requests were made during this documentation review. If a free endpoint is refused, stop and investigate; do not upgrade automatically or create extra accounts to bypass a limit.

### Alpaca: US stocks and ETFs

Create a [Paper Only account](https://docs.alpaca.markets/us/docs/paper-trading), which Alpaca advertises as available worldwide through email signup. Use its paper API key and secret; RichYet needs neither a funded brokerage account nor real trading access. The [Basic plan](https://docs.alpaca.markets/us/docs/about-market-data-api) is the default free plan, with 200 historical requests per minute.

There is an important distinction in Alpaca's documentation: the paper-account page describes IEX-only data, while the more specific [market-data FAQ](https://docs.alpaca.markets/us/docs/market-data-faq) explicitly permits historical consolidated SIP queries when the end time is at least fifteen minutes old. RichYet uses that historical route, not paid latest/snapshot SIP endpoints. A new account still needs to pass the full identity, calendar, raw/split bar and corporate-action checks; a successful price request alone is insufficient.

### TickerLayer: hourly EUR/USD

Create a [free account](https://tickerlayer.com/signup), complete any email verification, then obtain its API key from the dashboard as described in the [quickstart](https://tickerlayer.com/docs/quickstart). The [homepage](https://tickerlayer.com/) advertises signup without a card. Requests are made by RichYet's server, so its separate browser-origin approval process is not needed.

The [Free allowance](https://tickerlayer.com/docs/limits) is 3,000 REST requests per thirty-day signup cycle, at up to ten per second. RichYet does not use the paid WebSocket service. The [forex reference](https://tickerlayer.com/docs/products/forex) documents both required requests:

- `/forex/agg/EURUSD/prev?interval=1h` for the latest completed hour;
- `/forex/agg/EURUSD/1/hour/YYYY-MM-DD/YYYY-MM-DD` for matching previous-session hours.

The pricing page could not be retrieved during this review. The public signup, limits and endpoint documentation support a free setup route, but they do not independently prove that a newly issued key has both endpoint permissions. The [Free terms](https://tickerlayer.com/terms-of-use) cover personal/internal use and exclude showing the data to other people. Keep this installation private to its owner.

### EODHD: European split checks

Choose the [Sandbox Free package](https://eodhd.com/pricing) and obtain your own API key. Current pricing lists twenty calls per day, twenty per minute and one year of history. It now explicitly includes dividends/splits; the current [split documentation](https://eodhd.com/financial-apis/api-splits-dividends) also lists Free access and a one-call request cost. Older references to support activation should not be treated as the current published requirement.

The advertised 500 welcome calls are temporary, so they are excluded from RichYet's ongoing capacity assumptions. Test the exact European split symbol under the normal daily allowance. RichYet uses this source for safeguards, not for its European quoted prices. The [personal-use terms](https://eodhd.com/financial-apis/terms-conditions) allow private analysis but restrict display and distribution; they do not establish a right to run a shared app. No new-account response or clarification of owner-only web display was obtained in this review.

### Public sources

No API account is needed for the public files currently used by these adapters:

- [Euronext](https://marketdata.euronext.com/data-reporting-service/trades-file) publishes delayed trade files free of charge after fifteen minutes, with at least twenty-four hours of availability. Its separate delayed-data terms still apply.
- [Deutsche Börse](https://mfs.deutsche-boerse.com/DETR-posttrade) publishes delayed files under its free non-commercial licence conditions. Cold-start history and correction coverage must be checked before trusting a comparison.
- [Newton](https://www.newtonanalytics.com/docs/api/stockprice.php) documents a free previous-closing-day API covering international tickers and ETFs. No numerical allowance or delivery guarantee was established; RichYet's limit is its own safeguard.
- [ECB](https://www.ecb.europa.eu/stats/policy_and_exchange_rates/euro_reference_exchange_rates/html/index.en.html) publishes daily reference-rate downloads. These are daily reporting rates, not a replacement for the hourly EUR/USD comparison series.

These sources' terms remain separate from RichYet's MIT licence. Do not republish cached data or invite unrelated users on the assumption that open-source code gives permission to share market data. A public demo should use fictional prices and make no provider requests.

## Verify your account and automatic updates

Before calling a fresh setup fully verified, use that installation owner's legitimate accounts to confirm: Alpaca's complete delayed-price/comparison path; TickerLayer's settled and historical hourly EUR/USD responses; and EODHD's exact European split symbols if those listings are enabled. Confirm current and previous-session dates as well as successful responses. Repeat through the normal collector and check both comparisons. Never copy the original RichYet owner's keys or allowance into another person's installation.

An existing ordinary free account is suitable for these checks. Confirm its current plan and that the required access does not depend on a paid trial, special grant or temporary welcome credits. There is no need to create a duplicate account just to test an installation. If one owner tests two installations with the same keys, their requests must share the same allowance and accounting; an empty database does not provide a new quota.

Existing-account tests establish the tested free route, not that every future signup has identical access. State whether signup itself was tested. A first release can be qualified with existing ordinary free accounts and that limitation made explicit; do not promise untested access for every country or listing. Each new operator must check their own setup.

For this release preparation, the existing accounts' dashboards were checked on 21 October 2026: Alpaca Basic, TickerLayer Free Tier and EODHD Free Plan. No upgrade or trial was activated. A fresh isolated installation then successfully prepared AAPL using six Alpaca and two TickerLayer requests: a 21 October delayed observation, its 20 October baseline and current/historical hourly EURUSD produced independently verified values and both comparisons. No EODHD call or welcome credit was used in that run.

The automatic-update rehearsal is complete. Two scheduled US collections on 21 October received a later AAPL observation and then its closing value, with the preceding session's baseline preserved and current/hourly EUR/USD advancing. European preparation used the Euronext, Newton and EODHD free routes. Two scheduled ASML collections on 22 October then advanced the source time from 10:49 to 11:49 Amsterdam while retaining the exact 21 October baseline. Both comparisons, portfolio values, source dates and saved Last update receipts were checked independently and in the isolated app. ECB reporting-rate retrieval also passed.

All requests used shared accounting within the ordinary free limits, without paid trials, welcome credits, new accounts or quota resets. This verifies the tested US/EUR/FX route for a small personal installation. It does not prove uninterrupted delivery, every starter listing, new-signup eligibility or permission to share data with other users.

## Limits and timing

RichYet’s protective ceilings are Alpaca 100 requests/hour, public European sources 180/hour, TickerLayer 80/day, Newton 120/day and EODHD 18/day. Explicit preparation has lower sublimits. These are application limits, **not** statements about your provider’s remaining balance. Other apps using the same keys also consume allowances.

The normal job runs every ten minutes; adapters generally target hourly checks, with source-specific opening/retry/history rules. Newer cached prices may appear when the app is reopened without another provider request. Data status shows saved checks and application usage. A complete calculation does not prove that its price is current.

A broad search catalogue does not mean a large portfolio is sustainable on these free limits. Keep the first installation small. Missing data, delayed publication, historical gaps and download backlogs remain possible. RichYet retains compatible earlier figures and their original dates; it never invents a price, substitutes another exchange or resets quotas to make a check pass.

Calendar maintenance is part of self-hosting. The package has reviewed Xetra/NYSE 2027 coverage and bounded US session-calendar data. Euronext 2027 coverage is still pending official publication/review; do not describe it as fully supported yet.
