# Market calendars

Reviewed on 23 November 2026. The app uses explicit exchange calendars, not a weekday-only guess. Unconfirmed hours remain unknown; they do not prevent using a valid retained price.

| Venue | Verified static coverage | Remaining gap |
| --- | --- | --- |
| Euronext Amsterdam, Paris and Brussels | 2026–2027 holidays and normal trading days | Exact hours for 24 and 31 December in both years remain unpublished. These are known trading days with unknown session hours. |
| Xetra | 2026–2027 holidays; shortened 30 December 2026 session | Final-session hours for 30 December 2027 remain unconfirmed. |
| NYSE, NYSE American, NYSE Arca | 2026–2027 holidays and early closes | None within these years. |
| Nasdaq | Static 2026; validated date-specific Alpaca calendar when available | No static 2027 schedule verified. |
| Cboe US equities | 2026–2027 holidays | The early close on 26 November 2027 is confirmed, but its exact hour is not. |

The existing US collector can supply dated session hours within its requested range. A failed fetch does not advance that calendar's receipt time. This does not add requests or change collection frequency. BND continues to use its separate weekday 09:00–17:30 Amsterdam contribution window, which is not an exchange opening schedule.

## Official sources

- [Euronext 2027 calendar, 19 November erratum](https://www.euronext.com/en/media/16439/download) and [holiday hours](https://live.euronext.com/en/resources/trading-hours-holidays). Amsterdam, Paris and Brussels close on 1 January, 26 March and 29 March 2027; 27 and 28 December are full trading days. Do not borrow Dublin's or London's observed holidays.
- [Xetra calendar](https://www.cashmarket.deutsche-boerse.com/cash-en/trading/trading-calendar-and-trading-hours) and [final session 2026](https://www.cashmarket.deutsche-boerse.com/resource/blob/5673886/22764993c4b1617c5f465186066344e6/data/Final%20trading%20session%202026.pdf). On 30 December, continuous trading ends at 14:00 Berlin time; closing-auction price determination starts no earlier than 14:05. RichYet's `~14:05` is approximate; an actual price retains its source timestamp.
- [NYSE](https://www.nyse.com/trade/hours-calendars), [Nasdaq](https://www.nasdaqtrader.com/Trader.aspx?id=Calendar), [Cboe](https://www.cboe.com/about/hours/) and [Alpaca calendar API](https://docs.alpaca.markets/us/v1.4.2/reference/getcalendar-1).

## Maintenance

Review the official pages each October and before an unresolved shortened session. The next manual review target is 15 December 2026. This is an offline reminder in the calendar file, not a scheduled job or a claim that publications will be available then.

1. Run `npm run calendars:review` to see coverage and pending hours. This command does not fetch websites.
2. Update `data/market-calendars.json` only from the relevant venue's official publication. Keep unconfirmed hours in `uncertainHours`. Do not copy another exchange's dates or times.
3. Run `node tools/update-market-calendars.mjs`, then `npm run check` and `npm test`. Both the PHP and browser artifacts are generated from the same file. Tests cover holidays, year boundaries, daylight saving and incomplete calendar evidence.
4. Deploy both artifacts with their helpers. Review any affected provider's session gate separately: adding early-close times does not automatically authorize parsing prices for a new trading group. Euronext's current direct-price adapter admits only its reviewed normal sessions.

Keep price identity, split/correction checks and actual source dates intact. Calendar coverage alone does not prove fresh quotes or continuous future delivery.
