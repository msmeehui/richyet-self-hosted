<?php
declare(strict_types=1);
namespace RichYet;

require_once __DIR__ . '/MarketJson.php';

/**
 * Pure preflight for a future shared catalogue. Not wired to production.
 *
 * listing: id, mic, currency (EUR/USD), type (Stock/ETF/Fund), identityConfirmed.
 *          Funds require an exact share-class ISIN; their mic may be null.
 *          timeZone is the reviewed IANA session/valuation zone whenever price
 *          observation timestamps exist; date-only closes/NAVs need no zone.
 * source: priceAccess and fxAccess = owner|invited|none|unverified;
 *         priceHistory and fxHistory = true|false (absent means unverified).
 *         History means a tested cold-start pair, not a cached pair seen today.
 * quote: existing decimal/date fields plus listingId, mic, currency, fund isin,
 *        priceKind
 *        (trade|close|nav), previousKind (close|nav), expectedPreviousDate,
 *        basis (same_units|split_adjusted|unverified), freshUntil, invalidations
 *        (listing|current|comparison), sourceAvailable. USD also requires fx,
 *        previousFx, fxObservedAt, previousFxObservedAt, fxFreshUntil, fxSeries,
 *        previousFxSeries, fxBaselineDate and fxBaselineVerified.
 *
 * Adapters must establish expectedPreviousDate from their market calendar,
 * basis for THIS pair (never total-return/dividend adjustment), and freshUntil
 * from publication/session rules. An old split outside the pair is irrelevant.
 * FX rates use EUR per quoted currency unit; an adapter verifies the baseline
 * window and binds it to fxBaselineDate. This class does not establish these
 * facts, permission, market calendars or provider availability by itself.
 *
 * Inputs and audience must come from trusted server-side adapter/auth context,
 * never from a client claiming permission or comparison validity.
 * No owner, quantities, costs, provider keys, source payloads, I/O or persistence.
 * Returned objects use an explicit allowlist; they never echo supplied quotes.
 */
final class MarketAdmission
{
    public static function evaluate(array $listing, array $source, ?array $quote, string $audience, int $now): array
    {
        if (!in_array($audience, ['owner', 'invited'], true) || $now < 1) throw new \InvalidArgumentException('Invalid admission context.');
        $reasons = [];
        $id = $listing['id'] ?? null;
        $isFund = ($listing['type'] ?? null) === 'Fund';
        $mic = $listing['mic'] ?? null;
        $validMic = is_string($mic) && preg_match('/^[A-Z0-9]{4}$/D', $mic);
        $isin = $listing['isin'] ?? null;
        $fundIdentity = is_string($isin) && preg_match('/^[A-Z]{2}[A-Z0-9]{9}[0-9]$/D', $isin)
            && ($mic === null || $validMic);
        $identity = is_string($id) && preg_match('/^[A-Za-z0-9_-]{1,60}$/D', $id)
            && ($isFund ? $fundIdentity : $validMic)
            && ($listing['identityConfirmed'] ?? null) === true;
        if (!$identity) $reasons[] = 'identity_unverified';
        $supported = in_array($listing['type'] ?? null, ['Stock', 'ETF', 'Fund'], true)
            && in_array($listing['currency'] ?? null, ['EUR', 'USD'], true);
        if (!$supported) $reasons[] = 'instrument_unsupported';
        $needsFx = ($listing['currency'] ?? null) !== 'EUR';
        $priceAccess = self::access($source['priceAccess'] ?? 'unverified', $audience);
        $fxAccess = $needsFx ? self::access($source['fxAccess'] ?? 'unverified', $audience) : 'allowed';
        foreach (['price' => $priceAccess, 'fx' => $fxAccess] as $name => $access) {
            if ($access !== 'allowed') $reasons[] = $name . '_access_' . $access;
        }
        $history = [$source['priceHistory'] ?? null];
        if ($needsFx) $history[] = $source['fxHistory'] ?? null;
        foreach (['priceHistory' => true, 'fxHistory' => $needsFx] as $field => $required) {
            if ($required && ($source[$field] ?? null) !== true) {
                $reasons[] = $field === 'priceHistory'
                    ? (($source[$field] ?? null) === false ? 'price_history_unsupported' : 'price_history_unverified')
                    : (($source[$field] ?? null) === false ? 'fx_history_unsupported' : 'fx_history_unverified');
            }
        }
        $unsupported = !$supported || $priceAccess === 'denied' || $fxAccess === 'denied' || in_array(false, $history, true);
        $unverified = !$identity || $priceAccess === 'unverified' || $fxAccess === 'unverified'
            || count(array_filter($history, static fn($v) => $v !== true)) > 0;
        $capability = $unsupported ? 'unsupported' : ($unverified ? 'unverified' : 'supported');
        $current = $identity && $supported && $priceAccess === 'allowed' && $fxAccess === 'allowed';
        $latest = false;
        if ($quote === null) {
            $current = false;
            $reasons[] = 'data_unavailable';
        } else {
            $invalidations = $quote['invalidations'] ?? null;
            $invalidationsValid = is_array($invalidations) && array_is_list($invalidations)
                && count(array_filter($invalidations, static fn($v) => !is_string($v))) === 0
                && !array_diff($invalidations, ['listing', 'current', 'comparison']);
            $sameListing = ($quote['listingId'] ?? null) === $id
                && ($quote['mic'] ?? null) === ($listing['mic'] ?? null)
                && ($quote['currency'] ?? null) === ($listing['currency'] ?? null)
                && (!$isFund || ($quote['isin'] ?? null) === $isin);
            if (!$sameListing) { $current = false; $reasons[] = 'quote_identity_mismatch'; }
            if (!$invalidationsValid) { $current = false; $reasons[] = 'invalidations_unverified'; }
            elseif (in_array('listing', $invalidations, true) || in_array('current', $invalidations, true)) {
                $current = false; $reasons[] = 'current_invalidated';
            }
            $kind = $quote['priceKind'] ?? null;
            $kindValid = $isFund ? $kind === 'nav' : in_array($kind, ['trade', 'close'], true);
            if (!$kindValid || !self::price($quote['price'] ?? null) || !self::date($quote['date'] ?? null, $now)
                || !self::priceObservation($quote, $listing, 'observedAt', 'date', $kind === 'trade', $now)) {
                $current = false; $reasons[] = 'price_unavailable';
            }
            if (!self::fresh($quote['freshUntil'] ?? null, $now)) { $current = false; $reasons[] = 'price_stale'; }
            if (($quote['sourceAvailable'] ?? null) !== true) $reasons[] = 'source_unavailable';
            $previousKind = $quote['previousKind'] ?? null;
            $pair = self::price($quote['previousClose'] ?? null) && self::date($quote['previousDate'] ?? null, $now)
                && self::date($quote['expectedPreviousDate'] ?? null, $now)
                && ($quote['previousDate'] ?? null) === ($quote['expectedPreviousDate'] ?? null)
                && ($quote['previousDate'] ?? '') < ($quote['date'] ?? '')
                && $previousKind === ($isFund ? 'nav' : 'close')
                && self::priceObservation($quote, $listing, 'previousObservedAt', 'previousDate', false, $now)
                && self::ordered($quote['previousObservedAt'] ?? null, $quote['observedAt'] ?? null);
            if (!$pair) $reasons[] = 'comparison_unavailable';
            $basis = in_array($quote['basis'] ?? null, ['same_units', 'split_adjusted'], true);
            if (!$basis) $reasons[] = 'comparison_basis_unverified';
            if ($invalidationsValid && in_array('comparison', $invalidations, true)) {
                $basis = false; $reasons[] = 'comparison_invalidated';
            }
            $fxPair = true;
            if ($needsFx) {
                $fxCurrent = self::rate($quote['fx'] ?? null)
                    && self::observation($quote, 'fxObservedAt', true, $now)
                    && self::fresh($quote['fxFreshUntil'] ?? null, $now);
                if (!$fxCurrent) { $current = false; $reasons[] = 'fx_unavailable'; }
                $series = $quote['fxSeries'] ?? null;
                $fxPair = self::rate($quote['previousFx'] ?? null)
                    && self::observation($quote, 'previousFxObservedAt', true, $now)
                    && is_string($series) && $series !== '' && strlen($series) <= 80
                    && ($quote['previousFxSeries'] ?? null) === $series
                    && ($quote['fxBaselineDate'] ?? null) === ($quote['previousDate'] ?? null)
                    && ($quote['fxBaselineVerified'] ?? null) === true
                    && self::ordered($quote['previousFxObservedAt'] ?? null, $quote['fxObservedAt'] ?? null);
                if (!$fxPair) $reasons[] = 'fx_comparison_unavailable';
            }
            $latest = $current && $pair && $basis && $fxPair;
        }
        $ready = $capability === 'supported' && $latest;
        return ['instrumentId' => $identity ? $id : null, 'capability' => $capability,
            'status' => $capability !== 'supported' ? $capability : ($ready ? 'ready' : 'temporarily_unavailable'),
            'canAdd' => $ready, 'currentReady' => $current, 'latestReady' => $latest,
            'reasons' => array_values(array_unique($reasons))];
    }

    /** Personal cost availability is applied separately, never put in shared data. */
    public static function holdingReadiness(array $admission, bool $costKnown): array
    {
        return ['latestReady' => ($admission['latestReady'] ?? false) === true,
            'sincePurchaseReady' => ($admission['currentReady'] ?? false) === true && $costKnown,
            'costRequired' => !$costKnown];
    }

    private static function access(mixed $permission, string $audience): string
    {
        if ($permission === 'invited' || ($permission === 'owner' && $audience === 'owner')) return 'allowed';
        return in_array($permission, ['owner', 'none'], true) ? 'denied' : 'unverified';
    }
    private static function price(mixed $value): bool { return MarketJson::decimal($value) !== null; }
    private static function rate(mixed $value): bool
    {
        return self::price($value) || (is_array($value) && count($value) === 2
            && self::price($value['numerator'] ?? null) && self::price($value['denominator'] ?? null));
    }
    private static function date(mixed $value, int $now): bool { return MarketJson::date($value) && $value <= gmdate('Y-m-d', $now); }
    private static function fresh(mixed $until, int $now): bool { return is_int($until) && $until > $now; }
    private static function ordered(mixed $earlier, mixed $later): bool
    {
        // Date-only closes carry no invented instant. Other validation owns
        // missing/invalid fields; compare actual instants only when both exist.
        if ($earlier === null || $later === null) return true;
        $a = MarketJson::instant($earlier); $b = MarketJson::instant($later);
        return $a !== null && $b !== null && $a <= $b;
    }
    private static function observation(array $quote, string $key, bool $required, int $now): bool
    {
        if (!array_key_exists($key, $quote)) return false;
        if ($quote[$key] === null) return !$required;
        $time = MarketJson::instant($quote[$key]);
        return $time !== null && $time <= $now;
    }
    private static function priceObservation(array $quote, array $listing, string $key, string $dateKey, bool $required, int $now): bool
    {
        if (!self::observation($quote, $key, $required, $now)) return false;
        if ($quote[$key] === null) return true;
        $zone = $listing['timeZone'] ?? null;
        if (!is_string($zone) || !in_array($zone, \DateTimeZone::listIdentifiers(), true)) return false;
        $date = (new \DateTimeImmutable('@' . MarketJson::instant($quote[$key])))
            ->setTimezone(new \DateTimeZone($zone))->format('Y-m-d');
        return $date === ($quote[$dateKey] ?? null);
    }
}
