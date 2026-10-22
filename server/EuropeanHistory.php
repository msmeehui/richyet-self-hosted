<?php
declare(strict_types=1);
namespace RichYet;

require_once __DIR__ . '/MarketJson.php';
require_once __DIR__ . '/MarketCalendars.php';

/**
 * Bounded, date-only history for explicitly reviewed European listings.
 *
 * Newton's documented 0146 columns are epoch label, open, raw close, adjusted
 * close: https://www.newtonanalytics.com/docs/api/stockprice.php . The epoch is
 * a daily bar label, NEVER an observation or closing-auction timestamp. Values
 * stay decimal strings, including the source's floating-point artifacts.
 *
 * Reviewed calendar coverage is shared with direct feeds and UI through
 * MarketCalendars. See docs/MARKET_CALENDARS.md; unknown years fail closed.
 * Euronext half-days remain sessions, with the same conservative completion gate.
 *
 * The 18:00 eligibility gate and next-session 10:30 expiry are application
 * safeguards, NOT a claimed Newton publication SLA. Today's bar cannot become
 * a completed close before the gate; a previous close cannot look current
 * beyond 90 minutes of the next regular session. Live exchange quotes remain
 * the intraday source. Extended Xetra retail sessions are outside this series.
 */
final class EuropeanHistory
{
    // XETA is the reviewed Xetra ETF book segment; preserve that precise MIC.
    private const ZONES = ['XAMS' => 'Europe/Amsterdam', 'XPAR' => 'Europe/Paris', 'XBRU' => 'Europe/Brussels', 'XETR' => 'Europe/Berlin', 'XETA' => 'Europe/Berlin'];
    private const SUFFIXES = ['XAMS' => '.AS', 'XPAR' => '.PA', 'XBRU' => '.BR', 'XETR' => '.DE', 'XETA' => '.DE'];
    private const MAX_REQUESTS = 12;
    private const MAX_BYTES = 65536;

    /**
     * GET($url, $maxBytes) returns bytes or throws. The caller enforces TLS,
     * hosts, timeout and persistence. State is keyed by instrument id:
     * fingerprint, at (attempt), fetchedAt, expectedCloseDate, sourceAvailable,
     * rows[date] => {close: ?decimal, adjustedClose: ?decimal}.
     *
     * Rows are exposed only after identity/fingerprint validation. They can
     * supply an exact prior-session baseline to a direct quote, but the caller
     * must separately prove units through that CURRENT quote's date. A ratio
     * from two old rows cannot certify an intraday quote after those rows.
     */
    public static function refresh(array $registry, array $priorState, callable $get, int $now, array $directQuotes = []): array
    {
        if ($now < 1) throw new \InvalidArgumentException('Invalid history clock.');
        $quotes = $state = $issues = $touched = [];
        $requests = 0;
        foreach ($registry as $instrument) {
            $id = $instrument['id'] ?? null;
            $config = $instrument['providerConfig'] ?? [];
            if (!is_string($id) || !preg_match('/^[A-Za-z0-9_-]{1,60}$/D', $id)
                || !isset($config['historyProvider'])) continue;
            $touched[] = $id;
            if (!self::identity($instrument)) {
                $quotes[$id] = self::missing($instrument, 'listing_unverified');
                $issues[] = ['instrumentId' => $id, 'code' => 'listing_unverified'];
                continue;
            }
            $context = self::sessionContext($instrument['mic'], $now);
            if ($context === null) {
                $quotes[$id] = self::missing($instrument, 'calendar_unverified');
                $issues[] = ['instrumentId' => $id, 'code' => 'calendar_unverified'];
                continue;
            }
            $fingerprint = self::fingerprint($instrument);
            $entry = $priorState[$id] ?? [];
            if (!is_array($entry) || ($entry['fingerprint'] ?? null) !== $fingerprint) $entry = [];
            $entry += ['fingerprint' => $fingerprint, 'at' => 0, 'fetchedAt' => null, 'rows' => [], 'sourceAvailable' => false];
            $entry['retainedCloses'] = self::retained($instrument, $entry, $now);
            $confirmedRaw = null;
            // Successful closed-day pairs need only one daily poll. Incomplete
            // rows retry after two hours, including weekend publication delays.
            // Scheduling still uses the latest response's completeness, not a
            // retained fallback, so missing windows keep their original retry.
            $windowCurrent = $entry['rows'][$context['expectedCloseDate']] ?? [];
            $windowPrevious = $entry['rows'][$context['previousDate']] ?? [];
            $windowComplete = self::sameUnits($windowCurrent['close'] ?? null, $windowCurrent['adjustedClose'] ?? null,
                $windowPrevious['close'] ?? null, $windowPrevious['adjustedClose'] ?? null);
            $windowIdentity = ($config['historyIdentityRule'] ?? null) !== 'xetra_auction_match'
                || (($entry['identityVerified'] ?? false) === true && ($entry['identityRevoked'] ?? false) !== true);
            $interval = !$context['isSession'] && $windowComplete && $windowIdentity
                && $entry['sourceAvailable'] === true ? 86400 : 7200;
            $due = !is_int($entry['at']) || $entry['at'] > $now || $now - $entry['at'] >= $interval
                || ($entry['expectedCloseDate'] ?? null) !== $context['expectedCloseDate'];
            if ($due && $requests < self::MAX_REQUESTS) {
                $requests++;
                $entry['at'] = $now;
                $entry['expectedCloseDate'] = $context['expectedCloseDate'];
                try {
                    $raw = $get('https://api.newtonanalytics.com/price/?ticker=' . rawurlencode($config['historySymbol'])
                        . '&interval=1d&dataType=0146&observations=10', self::MAX_BYTES);
                } catch (\Throwable) {
                    $raw = null;
                    $entry['sourceAvailable'] = false;
                    $issues[] = ['instrumentId' => $id, 'code' => 'history_source_unavailable'];
                }
                if ($raw !== null) {
                    try {
                        // The response window is authoritative, including its
                        // nulls. Separately retain positive completed closes:
                        // an absent/null cell has no documented withdrawal meaning.
                        $entry['rows'] = self::parse($raw, $instrument['mic'], $now);
                        $entry['fetchedAt'] = $now;
                        $entry['sourceAvailable'] = true;
                        $confirmedRaw = $raw;
                    } catch (\Throwable $error) {
                        $entry['rows'] = [];
                        // Unavailable/malformed data is not a withdrawal. Only
                        // concrete conflicting prices veto a retained revision.
                        if ($error instanceof EuropeanHistoryConflict) {
                            foreach ($error->dates as $date) {
                                unset($entry['retainedCloses'][$date]);
                                $entry['retainedInvalidDates'][$date] = $now;
                            }
                        }
                        $entry['fetchedAt'] = null;
                        $entry['sourceAvailable'] = false;
                        $issues[] = ['instrumentId' => $id, 'code' => 'history_invalid'];
                    }
                }
            } elseif ($due) {
                $entry['sourceAvailable'] = false;
                $issues[] = ['instrumentId' => $id, 'code' => 'history_request_limit'];
            }
            if(($config['historyIdentityRule']??null)==='xetra_auction_match')$entry=self::corroborate($entry,$directQuotes[$id]??null,$now);
            if ($confirmedRaw !== null) $entry = self::remember($instrument, $entry, hash('sha256', $confirmedRaw), $now);
            if (isset($entry['retainedInvalidDates'])) {
                krsort($entry['retainedInvalidDates'], SORT_STRING);
                $entry['retainedInvalidDates'] = array_slice($entry['retainedInvalidDates'], 0, 12, true);
            }
            $state[$id] = $entry;
            $quotes[$id] = self::quote($instrument, $entry, $context, $now);
            foreach ($quotes[$id]['issues'] as $code) $issues[] = ['instrumentId' => $id, 'code' => $code];
        }
        return ['quotes' => $quotes, 'state' => $state, 'issues' => $issues, 'touched' => array_values(array_unique($touched))];
    }

    /** Exact source/listing identity; private state never shares a ticker alone. */
    public static function fingerprint(array $instrument): string
    {
        if (!self::identity($instrument)) throw new \InvalidArgumentException('Unverified history identity.');
        $c = $instrument['providerConfig'];
        $parts = [$instrument['id'], $instrument['isin'], $instrument['mic'], $instrument['currency'],
            $instrument['type'], $instrument['timeZone'], $c['historyProvider'], $c['historySymbol'],
            $c['identityConfirmed'], $c['historyIdentityConfirmed']];
        if (isset($c['historyIdentityRule'])) $parts[] = $c['historyIdentityRule'];
        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
    }

    /** A previously confirmed completed close, never a freshly confirmed null. */
    public static function retainedClose(array $instrument, array $entry, string $date, int $now): ?array
    {
        return self::retained($instrument, $entry, $now)[$date] ?? null;
    }

    /** Recover only a coherent, positively confirmed completed pair. This is
     * older evidence, never a new download, trade or price-update receipt. */
    public static function retainedPair(array $instrument, array $entry, int $now): ?array
    {
        $rows = self::retained($instrument, $entry, $now);
        foreach ($rows as $date => $current) {
            $previousDate = self::previousSession($instrument['mic'], $date);
            $previous = $rows[$previousDate ?? ''] ?? null;
            if ($previous === null || !self::sameUnits($current['close'], $current['adjustedClose'],
                $previous['close'], $previous['adjustedClose'])) continue;
            // A concrete newer revision or dated conflict invalidates the old
            // price, whereas a null/omitted response cannot withdraw it.
            foreach ([$date => $current, $previousDate => $previous] as $day => $saved) {
                foreach (['close', 'adjustedClose'] as $field) {
                    $value = MarketJson::decimal($entry['rows'][$day][$field] ?? null);
                    if ($value !== null && $value !== $saved[$field]) continue 3;
                }
            }
            return ['listingId' => $instrument['id'], 'mic' => $instrument['mic'], 'currency' => 'EUR',
                'isin' => $instrument['isin'], 'price' => $current['close'], 'date' => $date,
                'observedAt' => null, 'priceKind' => 'close', 'previousClose' => $previous['close'],
                'previousDate' => $previousDate, 'previousObservedAt' => null, 'previousKind' => 'close',
                'expectedPreviousDate' => $previousDate, 'basis' => 'same_units', 'fx' => '1', 'previousFx' => '1',
                'freshUntil' => min($current['lastConfirmedAt'], $previous['lastConfirmedAt']),
                'invalidations' => [], 'sourceAvailable' => false, 'source' => 'Newton Analytics retained completed closes',
                'status' => 'stale', 'issues' => ['source_unavailable']];
        }
        return null;
    }

    /** Pure recovery primitive. The operator must separately authenticate the
     * SHA-pinned production capture, its exact close quote and paired unit proof.
     * This method neither fetches nor writes, and never changes response clocks. */
    public static function seedRetainedClose(array $instrument, array $entry, array $evidence, int $now): array
    {
        $date = $evidence['date'] ?? null;
        if (!is_string($date) || ($evidence['evidenceKind'] ?? null) !== 'saved-production-state')
            throw new \InvalidArgumentException('Invalid recovery evidence.');
        $record = $evidence; unset($record['date']);
        $test = $entry; $test['retainedCloses'] = [$date => $record];
        if (self::retainedClose($instrument, $test, $date, $now) === null)
            throw new \RuntimeException('Recovery identity, date or provenance refused.');
        $existing = self::retainedClose($instrument, $entry, $date, $now);
        if ($existing !== null) {
            if ($existing['lastConfirmedAt'] >= $record['lastConfirmedAt']) return $entry;
            if ($existing['close'] !== $record['close'] || $existing['adjustedClose'] !== $record['adjustedClose'])
                throw new \RuntimeException('Recovery conflicts with a retained revision.');
        }
        $current = $entry['rows'][$date] ?? [];
        foreach (['close', 'adjustedClose'] as $field) {
            $positive = MarketJson::decimal($current[$field] ?? null);
            if ($positive !== null && $positive !== $record[$field])
                throw new \RuntimeException('A current positive correction prevents recovery.');
        }
        // Do not replace the provenance of a newer positive current response.
        if (MarketJson::decimal($current['close'] ?? null) !== null
            && MarketJson::decimal($current['adjustedClose'] ?? null) !== null
            && ($entry['fetchedAt'] ?? 0) > $record['lastConfirmedAt']) return $entry;
        $entry['retainedCloses'][$date] = $record;
        $entry['retainedCloses'] = self::retained($instrument, $entry, $now);
        return $entry;
    }

    /** Validate and bound records to the twelve most recent completed sessions.
     * Source confirmation time stays attached to that positive revision; later
     * omission, null, transport failure and cache rebuilding cannot renew it. */
    private static function retained(array $instrument, array $entry, int $now): array
    {
        if (!self::identity($instrument) || ($entry['identityRevoked'] ?? false) === true) return [];
        $fingerprint = self::fingerprint($instrument);
        if (($entry['fingerprint'] ?? null) !== $fingerprint) return [];
        if (($instrument['providerConfig']['historyIdentityRule'] ?? null) === 'xetra_auction_match'
            && ($entry['identityVerified'] ?? false) !== true) return [];
        $context = self::sessionContext($instrument['mic'], $now);
        if ($context === null) return [];
        $allowed = []; $date = $context['expectedCloseDate'];
        for ($n = 0; $n < 12 && $date !== null; $n++) {
            $allowed[$date] = true; $date = self::previousSession($instrument['mic'], $date);
        }
        $out = [];
        foreach ($entry['retainedCloses'] ?? [] as $date => $r) {
            if (!isset($allowed[$date]) || !is_array($r) || ($r['identityFingerprint'] ?? null) !== $fingerprint
                || MarketJson::decimal($r['close'] ?? null) === null || MarketJson::decimal($r['adjustedClose'] ?? null) === null
                || !is_int($r['firstConfirmedAt'] ?? null) || !is_int($r['lastConfirmedAt'] ?? null)
                || $r['firstConfirmedAt'] > $r['lastConfirmedAt'] || $r['lastConfirmedAt'] > $now
                || $r['lastConfirmedAt'] <= ($entry['retentionInvalidatedAt'] ?? 0)
                || $r['lastConfirmedAt'] <= ($entry['retainedInvalidDates'][$date] ?? 0)
                || !in_array($r['evidenceKind'] ?? null, ['newton-response', 'saved-production-state'], true)
                || !is_string($r['evidenceSha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $r['evidenceSha256'])) continue;
            $completedAt = (new \DateTimeImmutable($date . ' 18:00:00', new \DateTimeZone($instrument['timeZone'])))->getTimestamp();
            if ($r['firstConfirmedAt'] < $completedAt) continue;
            $out[$date] = array_intersect_key($r, array_flip(['close', 'adjustedClose', 'identityFingerprint',
                'firstConfirmedAt', 'lastConfirmedAt', 'evidenceKind', 'evidenceSha256']));
        }
        krsort($out, SORT_STRING);
        return $out;
    }

    private static function remember(array $instrument, array $entry, string $sha, int $now): array
    {
        $ledger = self::retained($instrument, $entry, $now);
        $context = self::sessionContext($instrument['mic'], $now);
        if ($context === null || ($entry['identityRevoked'] ?? false) === true) return $entry;
        if (($instrument['providerConfig']['historyIdentityRule'] ?? null) === 'xetra_auction_match'
            && ($entry['identityVerified'] ?? false) !== true) return $entry;
        foreach ($entry['rows'] as $date => $row) {
            if ($date > $context['expectedCloseDate']) continue;
            $close = MarketJson::decimal($row['close'] ?? null); $adjusted = MarketJson::decimal($row['adjustedClose'] ?? null);
            $old = $ledger[$date] ?? null;
            if ($close === null || $adjusted === null) {
                // A contradictory positive partial correction is evidence against
                // the old revision, unlike an entirely absent/null row.
                if ($old !== null && (($close !== null && $close !== $old['close'])
                    || ($adjusted !== null && $adjusted !== $old['adjustedClose']))) {
                    unset($ledger[$date]); $entry['retainedInvalidDates'][$date] = $now;
                }
                continue;
            }
            unset($entry['retainedInvalidDates'][$date]);
            $same = $old !== null && $old['close'] === $close && $old['adjustedClose'] === $adjusted;
            $ledger[$date] = ['close' => $close, 'adjustedClose' => $adjusted,
                'identityFingerprint' => $entry['fingerprint'], 'firstConfirmedAt' => $same ? $old['firstConfirmedAt'] : $now,
                'lastConfirmedAt' => $now, 'evidenceKind' => 'newton-response', 'evidenceSha256' => $sha];
        }
        if (isset($entry['retainedInvalidDates'])) {
            krsort($entry['retainedInvalidDates'], SORT_STRING);
            $entry['retainedInvalidDates'] = array_slice($entry['retainedInvalidDates'], 0, 12, true);
        }
        $entry['retainedCloses'] = $ledger;
        $entry['retainedCloses'] = self::retained($instrument, $entry, $now);
        return $entry;
    }

    /** null means the venue/date lies outside the reviewed calendar. */
    public static function isSession(string $mic, string $date): ?bool
    {
        if (!isset(self::ZONES[$mic]) || !MarketJson::date($date)) return null;
        return MarketCalendars::isSession($mic,$date);
    }

    public static function previousSession(string $mic, string $date): ?string { return self::adjacentSession($mic, $date, -1); }
    public static function nextSession(string $mic, string $date): ?string { return self::adjacentSession($mic, $date, 1); }

    /** Expected completed close, its previous session, and a bounded expiry. */
    public static function sessionContext(string $mic, int $now): ?array
    {
        if (!isset(self::ZONES[$mic]) || $now < 1) return null;
        $local = (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone(self::ZONES[$mic]));
        $today = $local->format('Y-m-d');
        $session = self::isSession($mic, $today);
        if ($session === null) return null;
        $expected = $session && $local->format('H:i:s') >= '18:00:00' ? $today : self::previousSession($mic, $today);
        if ($expected === null) return null;
        $previous = self::previousSession($mic, $expected);
        if ($previous === null) return null;
        $next = self::nextSession($mic, $expected);
        $nextOpen = $next === null ? null : (new \DateTimeImmutable($next . ' 09:00:00', $local->getTimezone()))->getTimestamp();
        // Never extend an old calendar into an unreviewed new year.
        $calendarEnd = (new \DateTimeImmutable(MarketCalendars::coverageEnd($mic,$today) . ' 00:00:00', $local->getTimezone()))->getTimestamp();
        return ['date' => $today, 'isSession' => $session, 'expectedCloseDate' => $expected,
            'previousDate' => $previous, 'nextOpenAt' => $nextOpen,
            'freshUntil' => $nextOpen === null ? $calendarEnd : min($calendarEnd, $nextOpen + 5400)];
    }

    /**
     * Unchanged raw/adjusted ratio is evidence of the same adjustment basis
     * across THIS pair only. A changed dividend adjustment is conservatively
     * rejected too: it is not evidence of a split ratio. Float tolerance is a
     * quality check for source precision; monetary output is never calculated
     * with floats. 1e-7 accommodates two IEEE single-precision source closes.
     */
    public static function sameUnits(mixed $raw, mixed $adjusted, mixed $previousRaw, mixed $previousAdjusted): bool
    {
        $values = array_map([MarketJson::class, 'decimal'], [$raw, $adjusted, $previousRaw, $previousAdjusted]);
        if (in_array(null, $values, true)) return false;
        [$r, $a, $pr, $pa] = array_map('floatval', $values);
        $ratio = $a / $r; $previousRatio = $pa / $pr;
        return is_finite($ratio) && is_finite($previousRatio) && $ratio > 0 && $previousRatio > 0
            && abs($ratio - $previousRatio) <= max($ratio, $previousRatio) * 1e-7;
    }

    /** Strict normalization of a documented Newton 0146 response. */
    public static function parse(string $raw, string $mic, int $now): array
    {
        if (strlen($raw) > self::MAX_BYTES || !isset(self::ZONES[$mic])) throw new \RuntimeException('Invalid history payload.');
        $body = MarketJson::decode($raw);
        if (!is_array($body) || ($body['status'] ?? null) !== '200' || !is_array($body['data'] ?? null)
            || !array_is_list($body['data']) || count($body['data']) > 40) throw new \RuntimeException('Invalid history response.');
        $zone = new \DateTimeZone(self::ZONES[$mic]);
        $today = (new \DateTimeImmutable('@' . $now))->setTimezone($zone)->format('Y-m-d');
        $rows = $conflicts = [];
        try { foreach ($body['data'] as $row) {
            if (!is_array($row) || !array_is_list($row) || count($row) !== 4 || !is_string($row[0])
                || !preg_match('/^[0-9]{10}$/D', $row[0])) throw new \RuntimeException('Invalid history row.');
            $date = (new \DateTimeImmutable('@' . $row[0]))->setTimezone($zone)->format('Y-m-d');
            if ($date > $today || self::isSession($mic, $date) !== true) throw new \RuntimeException('Invalid history date.');
            $value = ['close' => MarketJson::decimal($row[2]), 'adjustedClose' => MarketJson::decimal($row[3])];
            foreach ([2 => 'close', 3 => 'adjustedClose'] as $index => $field) {
                if ($row[$index] !== null && $value[$field] === null) throw new \RuntimeException('Invalid history price.');
            }
            if (array_key_exists($date, $rows) && $rows[$date] !== $value) $conflicts[$date] = true;
            $rows[$date] = $value;
        }
        } catch (\Throwable $error) {
            if ($conflicts !== []) throw new EuropeanHistoryConflict(array_keys($conflicts));
            throw $error;
        }
        if ($conflicts !== []) throw new EuropeanHistoryConflict(array_keys($conflicts));
        krsort($rows, SORT_STRING);
        return $rows;
    }

    private static function identity(array $instrument): bool
    {
        $config = $instrument['providerConfig'] ?? [];
        $mic = $instrument['mic'] ?? '';
        $symbol = $config['historySymbol'] ?? null;
        return is_string($mic) && isset(self::ZONES[$mic])
            && ($instrument['currency'] ?? null) === 'EUR'
            && in_array($instrument['type'] ?? null, ['Stock', 'ETF'], true)
            && is_string($instrument['isin'] ?? null) && preg_match('/^[A-Z]{2}[A-Z0-9]{9}[0-9]$/D', $instrument['isin'])
            && ($instrument['timeZone'] ?? null) === self::ZONES[$mic]
            && ($config['identityConfirmed'] ?? null) === true && (($config['historyIdentityConfirmed'] ?? null) === true
                || (($config['historyIdentityRule']??null)==='xetra_auction_match'&&($config['historyIdentityConfirmed']??null)===false&&($instrument['provider']??null)==='xetra'))
            && ($config['historyProvider'] ?? null) === 'newton'
            && is_string($symbol) && preg_match('/^[A-Z0-9][A-Z0-9.-]{0,24}$/D', $symbol)
            && str_ends_with($symbol, self::SUFFIXES[$mic]);
    }

    private static function adjacentSession(string $mic, string $date, int $direction): ?string
    {
        if (self::isSession($mic, $date) === null) return null;
        $day = new \DateTimeImmutable($date, new \DateTimeZone(self::ZONES[$mic]));
        for ($i = 0; $i < 12; $i++) {
            $day = $day->modify($direction < 0 ? '-1 day' : '+1 day');
            $candidate = $day->format('Y-m-d');
            $session = self::isSession($mic, $candidate);
            if ($session === null) return null;
            if ($session) return $candidate;
        }
        return null;
    }

    private static function quote(array $instrument, array $entry, array $context, int $now): array
    {
        if(($instrument['providerConfig']['historyIdentityRule']??null)==='xetra_auction_match'
            &&(($entry['identityVerified']??false)!==true||($entry['identityRevoked']??false)===true))return self::missing($instrument,'listing_unverified');
        $current = $entry['rows'][$context['expectedCloseDate']] ?? [];
        $previous = $entry['rows'][$context['previousDate']] ?? [];
        if (MarketJson::decimal($current['close'] ?? null) === null)
            $current = self::retainedClose($instrument, $entry, $context['expectedCloseDate'], $now) ?? $current;
        if (MarketJson::decimal($previous['close'] ?? null) === null)
            $previous = self::retainedClose($instrument, $entry, $context['previousDate'], $now) ?? $previous;
        $price = MarketJson::decimal($current['close'] ?? null);
        $previousClose = MarketJson::decimal($previous['close'] ?? null);
        $basis = self::sameUnits($price, $current['adjustedClose'] ?? null, $previousClose, $previous['adjustedClose'] ?? null);
        $issues = [];
        if ($price === null) $issues[] = 'history_price_missing';
        if ($previousClose === null) $issues[] = 'history_baseline_missing';
        elseif ($price !== null && !$basis) $issues[] = 'history_basis_unverified';
        if ($context['freshUntil'] <= $now) $issues[] = 'history_price_stale';
        if (($entry['sourceAvailable'] ?? false) !== true) $issues[] = 'history_source_unavailable';
        return ['listingId' => $instrument['id'], 'mic' => $instrument['mic'], 'currency' => $instrument['currency'],
            'isin' => $instrument['isin'], 'price' => $price, 'date' => $price === null ? null : $context['expectedCloseDate'],
            'observedAt' => null, 'priceKind' => 'close', 'previousClose' => $basis ? $previousClose : null,
            'previousDate' => $basis ? $context['previousDate'] : null, 'previousObservedAt' => null,
            'previousKind' => 'close', 'expectedPreviousDate' => $context['previousDate'],
            'basis' => $basis ? 'same_units' : 'unverified', 'fx' => '1', 'previousFx' => '1',
            'freshUntil' => $context['freshUntil'], 'invalidations' => $price === null ? ['current', 'comparison'] : ($basis ? [] : ['comparison']),
            'sourceAvailable' => ($entry['sourceAvailable'] ?? false) === true, 'source' => 'Newton Analytics daily close',
            'status' => $price === null ? 'missing' : ($context['freshUntil'] <= $now ? 'stale' : 'closed'), 'issues' => $issues];
    }

    /** Metadata supplies a candidate symbol. A direct exact-ISIN auction must
     * independently match before the daily series can be used. The tolerance
     * covers the source's IEEE float representation, not a different price. */
    private static function corroborate(array $entry,?array $direct,int $now):array
    {
        if(($entry['identityRevoked']??false)===true){$entry['identityVerified']=false;return $entry;}
        $date=$direct['date']??null;$instant=MarketJson::instant($direct['observedAt']??null);
        if(($entry['sourceAvailable']??false)!==true||($direct['status']??null)!=='closed'||!is_string($date)
            ||$instant===null||$instant>$now||($direct['price']??null)===null||isset($direct['eligibilityIssue'])
            ||array_intersect(['listing_unverified','price_stale','source_unavailable'],$direct['issues']??[]))return $entry;
        // baseline_missing/corporate_action_unverified also accompany a good
        // current auction when only its previous comparison proof is absent.
        // An actual current-session correction returns price:null upstream.
        $raw=MarketJson::decimal($entry['rows'][$date]['close']??null);$price=MarketJson::decimal($direct['price']);
        if($raw===null||$price===null)return $entry;
        if(abs((float)$raw-(float)$price)>max((float)$raw,(float)$price)*1e-7){$entry['identityVerified']=false;$entry['identityRevoked']=true;return $entry;}
        $entry['identityVerified']=true;$entry['identityProof']=['date'=>$date,'price'=>$price,'observedAt'=>$direct['observedAt'],'checkedAt'=>$now];
        return $entry;
    }

    private static function missing(array $instrument, string $issue): array
    {
        return ['listingId' => $instrument['id'] ?? null, 'mic' => $instrument['mic'] ?? null,
            'currency' => $instrument['currency'] ?? null, 'isin' => $instrument['isin'] ?? null,
            'price' => null, 'date' => null, 'observedAt' => null, 'priceKind' => 'close',
            'previousClose' => null, 'previousDate' => null, 'previousObservedAt' => null, 'previousKind' => 'close',
            'expectedPreviousDate' => null, 'basis' => 'unverified', 'fx' => '1', 'previousFx' => '1',
            'freshUntil' => 0, 'invalidations' => $issue === 'listing_unverified' ? ['listing'] : ['current', 'comparison'], 'sourceAvailable' => false,
            'source' => 'Newton Analytics daily close', 'status' => 'missing', 'issues' => [$issue]];
    }
}

/** Concrete same-date price disagreement, distinct from unreadable source data. */
final class EuropeanHistoryConflict extends \RuntimeException
{
    public function __construct(public readonly array $dates) { parent::__construct('Conflicting history dates.'); }
}
