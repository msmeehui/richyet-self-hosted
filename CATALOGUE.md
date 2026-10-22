# Search catalogue

The self-hosting package starts with **11 searchable stocks and ETFs** across the existing US and Amsterdam connections. These are listing definitions, not recommendations or a sample portfolio. Installation adds no holdings, quantities, purchase costs, cached quotes or personal account information.

| Investment | Ticker | Exact exchange | Trading currency | Type |
| --- | --- | --- | --- | --- |
| [Apple](https://www.nasdaq.com/market-activity/stocks/aapl) | AAPL | Nasdaq | USD | Stock |
| [Microsoft](https://www.microsoft.com/en-us/investor/FAQ) | MSFT | Nasdaq | USD | Stock |
| [Amazon](https://www.nasdaq.com/market-activity/stocks/amzn/advanced-charting) | AMZN | Nasdaq | USD | Stock |
| [Alphabet Class A](https://www.nasdaq.com/market-activity/stocks/googl) | GOOGL | Nasdaq | USD | Stock |
| [Meta Platforms Class A](https://www.nasdaq.com/market-activity/stocks/meta) | META | Nasdaq | USD | Stock |
| [Advanced Micro Devices](https://ir.amd.com/contacts-faq/faq) | AMD | Nasdaq | USD | Stock |
| [Invesco QQQ Trust Series 1](https://www.nasdaq.com/market-activity/etf/qqq) | QQQ | Nasdaq | USD | ETF |
| [State Street SPDR S&P 500 ETF Trust](https://www.ssga.com/us/en/individual/etfs/state-street-spdr-sp-500-etf-trust-spy) | SPY | NYSE Arca | USD | ETF |
| [ASML Holding](https://live.euronext.com/en/product/equities/NL0010273215-XAMS) | ASML | Euronext Amsterdam | EUR | Stock |
| [ING Groep](https://live.euronext.com/en/search_instruments/ING) | INGA | Euronext Amsterdam | EUR | Stock |
| [iShares Core MSCI World UCITS ETF USD (Acc)](https://www.ishares.com/nl/particuliere-belegger/nl/producten/251882/ishares-core-msci-world-ucits-etf-acc-fund) | IWDA | Euronext Amsterdam | EUR | ETF |

Public listing identities were reviewed on 21 October 2026. They do **not** guarantee that every provider will supply usable prices and comparisons under a new account. RichYet checks each selected listing before it can be added. The bounded release rehearsal verified scheduled AAPL and ASML price progression, currency conversion and both comparisons. It did not fetch every catalogue entry. The [provider guide](DATA_PROVIDERS.md) describes the evidence and free-limit restrictions.

The catalogue is deliberately small; it does not reproduce the original installation's much larger directory. Most US stocks can use the existing US connection, but their exact listing still needs a reviewed entry. Adding investments using a different data service requires code and testing; supplying another API key alone is not enough.

## Before installation

Copy `self-host/starter-catalogue.json` into your private configuration directory. Keep the starter set, remove unwanted entries and their matching policies, or add separately reviewed listings. At least one listing is required. Keep `audience`, `priceAccess` and `fxAccess` set to `owner`; these are access restrictions, not a grant of provider rights. Search and catalogue setup make no provider requests. Preparing or holding an investment does use your own installation's allowances; eleven search choices are not a guarantee that eleven simultaneous positions will fit every free plan.

The Amsterdam ISINs are `NL0010273215` (ASML), `NL0011821202` (INGA) and `IE00B4L5Y983` (IWDA). Their history/action symbols are respectively `ASML.AS`, `INGA.AS` and `IWDA.AS`. The price connection selects the exact ISIN on Amsterdam. Never substitute ASML's US listing, ING's ADR or IWDA's London USD listing. IWDA's fund name includes USD, but the selected Amsterdam listing trades in **EUR**. The schedule identifiers retain a historical `2026` name; actual supported dates come from the reviewed calendar data, not that label alone.

For US entries, the connection verifies the provider's current asset ID, exact symbol and exchange before accepting prices. For all entries, the existing date, split/correction and currency guards still apply. No starter definition supplies a price or bypasses these checks.

Each entry separates:

- **Identity:** a stable internal ID, name, symbol, exchange/MIC, currency, type and, for European listings, ISIN.
- **Source mapping:** the exact provider symbol, execution/publication venue, history/action identifiers and supported schedule.
- **Policy:** which audience may use the configured source capabilities.

`coverage` describes the adapter’s intended capability. It does not make a cold listing ready: RichYet still checks actual prices, the prior session, currency conversion and compatible units before allowing an addition. Failed checks must not be bypassed by inserting fabricated prices.

## Extending a small installed catalogue

Prefer doing this before entering holdings. After installation, back up first and use the existing `directory-import` command with a **new private file** shaped like:

```json
{
  "schemaVersion": 1,
  "catalogueId": "personal",
  "entries": [
    {"instrument": "REPLACE_WITH_A_REVIEWED_INSTRUMENT_OBJECT", "policy": "REPLACE_WITH_ITS_OWNER_POLICY_OBJECT"}
  ]
}
```

The strings above are placeholders, deliberately not importable definitions. Use the complete objects from the starter catalogue as structural examples. Then:

```sh
php server/cli.php directory-import --config /absolute/private/app-config.json --catalogue personal --input /absolute/private/additions.json --output /absolute/private/before-directory-addition.json
```

The command writes a private backup before import; it preserves existing listing definitions and holdings. A searchable new listing is prepared only when selected, within the same budgets. Do not reuse an old ID for a different listing or assume that identical tickers identify identical investments.

For larger directories, `server/DirectorySources.php` contains parsers for your own freshly obtained Alpaca/Nasdaq, Xetra and EODHD reference inputs. The parsers reject stale or conflicting records; Euronext and Xetra require separately reviewed mappings. They are building blocks for an operator, not an included automatic download wizard. Do not copy another installation’s database, saved observations or provider keys.

Only EUR/USD instruments with an existing supported adapter are candidates. The GBP portfolio-reporting option does not add London/GBP instrument coverage. Other funds, bonds, options, futures and crypto cannot be enabled merely by adding their names to JSON.
