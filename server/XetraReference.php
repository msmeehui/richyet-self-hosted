<?php
declare(strict_types=1);
namespace RichYet;
require_once __DIR__.'/MarketCalendars.php';

/** Dated public T7 references. Blank CUM/EX is a bounded normal-session inference,
 * not a guarantee that the exchange has published every corporate action. */
final class XetraReference
{
    public const PAGE = 'https://www.cashmarket.deutsche-boerse.com/cash-en/trading/Tradable-Instruments-Xetra/xetra/3884!search';
    private const HOST = 'https://www.cashmarket.deutsche-boerse.com';

    public static function refresh(array $instruments, array $state, callable $get, int $now): array
    {
        $attempt = $state['referenceAttemptAt'] ?? null;
        if (is_int($attempt) && $attempt <= $now && $now - $attempt < 3600) return $state;
        $state['referenceAttemptAt'] = $now;
        try {
            $page = $get(self::PAGE, 2097152);
            if (!preg_match_all('~/resource/blob/1528/[a-f0-9]{32}/data/t7-xetr-allTradableInstruments\.csv~', $page, $matches)) throw new \RuntimeException('Reference link unavailable.');
            $paths = array_values(array_unique($matches[0]));
            if (count($paths) !== 1) throw new \RuntimeException('Ambiguous reference link.');
            $csv = $get(self::HOST . $paths[0], 8388608);
            $reference = self::parse($csv, $instruments, $now);
            foreach ($reference['rows'] as $id => &$row) {
                if ($row['valid']) {
                    if (isset($state['referenceIdentity'][$id]) && $state['referenceIdentity'][$id] !== $row['identity']) {
                        $state['referenceRevoked'][$id] = min($state['referenceRevoked'][$id] ?? $reference['date'], $reference['date']);
                        $row['valid'] = false;
                    } else $state['referenceIdentity'][$id] = $row['identity'];
                }
                // An explicit action stays known even if a later replacement
                // file removes its marker or all subsequent requests fail.
                $effective = $row['indicator'] === 'E' ? $reference['date']
                    : ($row['indicator'] === 'C' ? (self::nextSession($reference['date']) ?? (new \DateTimeImmutable($reference['date']))->modify('+1 day')->format('Y-m-d')) : null);
                if ($effective !== null) {
                    $state['referenceActions'][$id][$effective] = true;
                    krsort($state['referenceActions'][$id]);
                    $state['referenceActions'][$id] = array_slice($state['referenceActions'][$id], 0, 16, true);
                }
            }
            unset($row);
            $reference['retrievedAt'] = $now;
            $state['references'][$reference['date']] = $reference;
            krsort($state['references']);
            $state['references'] = array_slice($state['references'], 0, 8, true);
            $state['referenceFailed'] = false;
        } catch (\Throwable) { $state['referenceFailed'] = true; }
        return $state;
    }

    /** Exact required columns, complete rows, and one exact listing per ISIN. */
    public static function parse(string $csv, array $instruments, int $now): array
    {
        if ($csv === '' || strlen($csv) > 8388608 || str_contains($csv, "\0")) throw new \RuntimeException('Invalid reference size.');
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, preg_replace('/^\xEF\xBB\xBF/', '', $csv)); rewind($stream);
        try {
            $market = fgetcsv($stream, 65537, ';', '"', '');
            $dated = fgetcsv($stream, 65537, ';', '"', '');
            if ($market !== ['Market:', 'XETR'] || !is_array($dated) || count($dated) !== 2
                || $dated[0] !== 'Date Last Update:' || !preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/D', $dated[1], $m)) throw new \RuntimeException('Invalid reference preamble.');
            $date = $m[3] . '-' . $m[2] . '-' . $m[1];
            if (!self::session($date) || $date < gmdate('Y-m-d', $now - 14 * 86400)
                || $date > gmdate('Y-m-d', $now + 7 * 86400)) throw new \RuntimeException('Invalid reference business date.');
            $header = fgetcsv($stream, 65537, ';', '"', '');
            $required = ['Product Status', 'Instrument Status', 'ISIN', 'Instrument ID', 'MIC Code', 'Reporting Market',
                'Unit of Quotation', 'Currency', 'Settlement Currency', 'CUM/EX Indicator'];
            if (!is_array($header) || count($header) > 256 || count($header) !== count(array_unique($header))
                || array_diff($required, $header)) throw new \RuntimeException('Incomplete reference header.');
            $rows = $found = []; $count = 0;
            foreach ($instruments as $instrument) $rows[$instrument['id']] = ['valid' => false, 'identity' => null, 'indicator' => null];
            while (($values = fgetcsv($stream, 65537, ';', '"', '')) !== false) {
                if ($values === [null]) continue;
                if (++$count > 20000 || count($values) !== count($header)) throw new \RuntimeException('Incomplete reference row.');
                $row = array_combine($header, $values);
                foreach ($instruments as $instrument) {
                    if ($row['ISIN'] !== $instrument['isin'] || $row['Reporting Market'] !== $instrument['providerConfig']['venue']) continue;
                    $id = $instrument['id'];
                    if (isset($found[$id])) throw new \RuntimeException('Duplicate reference listing.');
                    $found[$id] = true;
                    $indicator = $row['CUM/EX Indicator'];
                    $valid = $row['Product Status'] === 'Active' && $row['Instrument Status'] === 'Active'
                        && preg_match('/^[1-9][0-9]{0,19}$/D', $row['Instrument ID']) && $row['MIC Code'] === 'XETR'
                        && $row['Unit of Quotation'] === 'Shares' && $row['Currency'] === $instrument['currency']
                        && $row['Settlement Currency'] === $instrument['currency'] && in_array($indicator, ['', 'C', 'E'], true);
                    $rows[$id] = ['valid' => (bool) $valid, 'identity' => $valid ? implode('|', [$row['Instrument ID'], $row['ISIN'], $row['Reporting Market'], $row['Unit of Quotation'], $row['Currency']]) : null,
                        'indicator' => in_array($indicator, ['', 'C', 'E'], true) ? $indicator : null];
                }
            }
            if ($count === 0) throw new \RuntimeException('Empty reference catalogue.');
            return ['date' => $date, 'sha256' => hash('sha256', $csv), 'rows' => $rows];
        } finally { fclose($stream); }
    }

    public static function compatible(array $state, string $id, string $date, string $previous): bool
    {
        if (self::previousSession($date) !== $previous || isset($state['referenceRevoked'][$id])) return false;
        $current = $state['references'][$date]['rows'][$id] ?? null;
        $prior = $state['references'][$previous]['rows'][$id] ?? null;
        foreach ($state['referenceActions'][$id] ?? [] as $action => $_) if ($action > $previous && $action <= $date) return false;
        return ($current['valid'] ?? false) === true && ($prior['valid'] ?? false) === true
            && $current['indicator'] === '' && in_array($prior['indicator'], ['', 'E'], true) && $current['identity'] === $prior['identity'];
    }

    /** Operator-reviewed browser evidence supplements one historical pair only.
     * It is not a reconstructed catalogue or a general manual-price input. */
    public static function validateReviewedPair(array $evidence, array $instrument, int $now): array
    {
        $keys = ['schemaVersion','kind','instrumentId','isin','currency','venue','publicationVenue','sourceUrl','methodUrl',
            'retrievedAt','rawAdjustments','comparisonAdjustments','evidenceSha256','current','previous'];
        if (array_diff($keys,array_keys($evidence)) || array_diff(array_keys($evidence),$keys)
            || ($evidence['schemaVersion']??null)!==1 || ($evidence['kind']??null)!=='xetra-reviewed-history-pair'
            || ($instrument['provider']??null)!=='xetra' || ($instrument['type']??null)!=='ETF'
            || ($instrument['providerConfig']['identityConfirmed']??false)!==true) throw new \RuntimeException('Invalid reviewed pair evidence.');
        foreach (['instrumentId'=>'id','isin'=>'isin','currency'=>'currency'] as $field=>$registryField) {
            if (!is_string($evidence[$field]) || $evidence[$field]!==($instrument[$registryField]??null)) throw new \RuntimeException('Reviewed pair identity mismatch.');
        }
        foreach (['venue','publicationVenue'] as $field) if ($evidence[$field]!==($instrument['providerConfig'][$field]??null)) throw new \RuntimeException('Reviewed pair venue mismatch.');
        if ($evidence['currency']!=='EUR' || $evidence['venue']!=='XETA' || $evidence['publicationVenue']!=='XCEF'
            || !is_string($evidence['sourceUrl']) || !preg_match('~^https://live\.deutsche-boerse\.com/en/etf/[a-z0-9-]+/price-history/historical-prices-and-volumes\?currency=EUR$~D',$evidence['sourceUrl'])
            || $evidence['methodUrl']!=='https://live.deutsche-boerse.com/en/adjusted-prices') throw new \RuntimeException('Reviewed pair source mismatch.');
        foreach (['rawAdjustments'=>['splits'=>false,'dividends'=>false,'subscriptionRights'=>false],
            'comparisonAdjustments'=>['splits'=>true,'dividends'=>false,'subscriptionRights'=>false]] as $field=>$expected) {
            if (!is_array($evidence[$field]) || count($evidence[$field])!==3) throw new \RuntimeException('Reviewed adjustment filters incomplete.');
            foreach ($expected as $name=>$value) if (($evidence[$field][$name]??null)!==$value) throw new \RuntimeException('Reviewed adjustment filters mismatch.');
        }
        $checked = self::instant($evidence['retrievedAt']);
        if ($checked===null || $checked>$now || $checked<$now-86400) throw new \RuntimeException('Reviewed pair requires a recent completed review.');
        $digests=$evidence['evidenceSha256'];
        if (!is_array($digests) || count($digests)!==3) throw new \RuntimeException('Reviewed source captures missing.');
        foreach (['rawScreenshot','splitScreenshot','browserEvidence'] as $field) if (!self::digest($digests[$field]??null)) throw new \RuntimeException('Reviewed source capture digest missing.');
        foreach (['current','previous'] as $side) {
            $row=$evidence[$side];$fields=['date','rawClose','splitOnlyClose','archiveSha256','trade'];
            if (!is_array($row) || array_diff($fields,array_keys($row)) || array_diff(array_keys($row),$fields)
                || !is_string($row['date']) || !self::session($row['date']) || !self::digest($row['archiveSha256'])
                || !self::samePrice($row['rawClose'],$row['splitOnlyClose']) || !is_array($row['trade'])
                || count($row['trade'])>30 || strlen(json_encode($row['trade'],JSON_THROW_ON_ERROR))>8192) throw new \RuntimeException('Invalid reviewed closing pair.');
            $trade=$row['trade'];$at=self::instant($trade['tradingDateAndTime']??null);$published=self::instant($trade['publicationDateAndTime']??null);
            if ($at===null || $published===null || $published>$checked || $at>$checked || self::timeKey($trade['publicationDateAndTime'])<self::timeKey($trade['tradingDateAndTime'])
                || self::localDate($at)!==$row['date'] || !self::samePrice($row['rawClose'],$trade['price']??null)
                || ($trade['instrumentIdentificationCode']??null)!==$evidence['isin'] || ($trade['venueOfExecution']??null)!==$evidence['venue']
                || ($trade['venueOfPublication']??null)!==$evidence['publicationVenue'] || ($trade['priceCurrency']??null)!=='EUR'
                || (string)($trade['priceNotation']??'')!=='1' || ($trade['tradingSystem']??null)!=='1'
                || ($trade['mmtTradingMode']??null)!=='K' || ($trade['mmtModificationInd']??null)!=='-'
                || ($trade['mmtBenchmarkRefprcInd']??null)!=='-' || ($trade['mmtPubModeDefReason']??null)!=='-') throw new \RuntimeException('Reviewed price is not the matching eligible auction.');
        }
        if (self::previousSession($evidence['current']['date'])!==$evidence['previous']['date']
            || $evidence['current']['archiveSha256']===$evidence['previous']['archiveSha256']) throw new \RuntimeException('Reviewed pair dates or archives mismatch.');
        return $evidence;
    }

    /** Match exact observed pair, including source timestamps; never another day. */
    public static function reviewedCompatible(array $state, string $id, array $current, array $previous, int $now): bool
    {
        $evidence=$state['reviewedPairs'][$id]??null;
        if (!is_array($evidence) || ($evidence['kind']??null)!=='xetra-reviewed-history-pair' || ($evidence['instrumentId']??null)!==$id
            || isset($state['referenceRevoked'][$id]) || self::oldBasisUnsafe($state,$id,$current['date']??null,$now)
            || self::previousSession($current['date']??'')!==($previous['date']??null)) return false;
        foreach (['current'=>$current,'previous'=>$previous] as $side=>$quote) {
            $row=$evidence[$side]??[];
            if (($row['date']??null)!==($quote['date']??null) || !self::samePrice($row['rawClose']??null,$quote['price']??null)
                || !self::samePrice($row['rawClose']??null,$row['splitOnlyClose']??null)
                || ($row['trade']['tradingDateAndTime']??null)!==($quote['observedAt']??null)) return false;
            $reference=$state['references'][$quote['date']]['rows'][$id]??null;
            if ($reference!==null && (($reference['valid']??false)!==true || ($side==='current' && ($reference['indicator']??null)!=='')
                || ($side==='previous' && !in_array($reference['indicator']??null,['','E'],true)))) return false;
        }
        foreach ($state['referenceActions'][$id]??[] as $action=>$_) if ($action>$previous['date'] && $action<=$current['date']) return false;
        return true;
    }

    public static function samePrice(mixed $left, mixed $right): bool
    {
        foreach ([$left,$right] as $value) if (!is_string($value) || strlen($value)>60 || !preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/D',$value) || !preg_match('/[1-9]/',$value)) return false;
        $canonical=static fn($value)=>str_contains($value,'.')?rtrim(rtrim($value,'0'),'.'):$value;
        return $canonical($left)===$canonical($right);
    }
    private static function digest(mixed $value): bool { return is_string($value) && preg_match('/^[a-f0-9]{64}$/D',$value)===1; }
    private static function instant(mixed $value): ?int
    {
        if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})T([01]\d|2[0-3]):([0-5]\d):([0-5]\d)(?:\.\d{1,9})?Z$/D',$value,$m)
            || !checkdate((int)$m[2],(int)$m[3],(int)$m[1])) return null;
        return strtotime(substr($value,0,19).'Z');
    }
    private static function timeKey(string $value): string { $p=explode('.',rtrim($value,'Z'),2);return $p[0].'.'.str_pad($p[1]??'',9,'0'); }

    /** Used on cache-only reads too, before the first post-action observation. */
    public static function oldBasisUnsafe(array $state, string $id, ?string $quoteDate, int $now): bool
    {
        if ($quoteDate === null) return false;
        $today = self::localDate($now);
        if (isset($state['referenceRevoked'][$id]) && $state['referenceRevoked'][$id] <= $today) return true;
        foreach ($state['referenceActions'][$id] ?? [] as $action => $_) if ($action <= $today && $quoteDate < $action) return true;
        $references = $state['references'] ?? []; krsort($references);
        foreach ($references as $date => $reference) {
            if ($date > $today) continue;
            // Only the newest effective reference governs listing availability.
            return ($reference['rows'][$id]['valid'] ?? false) !== true;
        }
        return false;
    }

    public static function previousSession(string $date): ?string { return self::adjacent($date, -1); }
    public static function nextSession(string $date): ?string { return self::adjacent($date, 1); }
    private static function adjacent(string $date, int $direction): ?string
    {
        if (!self::session($date)) return null;
        $day = new \DateTimeImmutable($date, new \DateTimeZone('Europe/Berlin'));
        for ($n = 0; $n < 10; $n++) {
            $day = $day->modify($direction < 0 ? '-1 day' : '+1 day');
            $candidate = $day->format('Y-m-d');
            if (MarketCalendars::isSession('XETR',$candidate) === null) return null;
            if (self::session($candidate)) return $candidate;
        }
        return null;
    }
    /** Expected latest close during closures and before delayed opening prices.
     * A fresh K record itself proves today's close; no closing time is invented. */
    public static function expectedCloseDate(int $now): ?string
    {
        $local = (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone('Europe/Berlin'));
        if (MarketCalendars::isSession('XETR',$local->format('Y-m-d')) === null) return null;
        $today = $local->format('Y-m-d');
        if (self::session($today) && $local->format('H:i') >= '09:15') return $today;
        for ($n = 0; $n < 10; $n++) {
            $local = $local->modify('-1 day');
            $date = $local->format('Y-m-d');
            if (MarketCalendars::isSession('XETR',$date) === null) return null;
            if (self::session($date)) return $date;
        }
        return null;
    }

    /** Unknown dates never become tradable merely because they are weekdays. */
    public static function session(string $date): bool
    {
        return MarketCalendars::isSession('XETR',$date) === true;
    }
    private static function localDate(int $now): string
    {
        return (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('Y-m-d');
    }
}
