<?php
declare(strict_types=1);

namespace RichYet;
require_once __DIR__ . '/XetraReference.php';
require_once __DIR__ . '/EuronextProviders.php';
require_once __DIR__ . '/EuropeanHistory.php';
require_once __DIR__ . '/EuropeanActions.php';

/** Public sources only. The caller owns TLS/host restrictions and atomic state storage. */
final class EuropeanProviders
{
    private const BND = 'https://robotapi-a4cjfeaygwg3cke0.westeurope-01.azurewebsites.net';
    private const XETRA = 'https://mfs.deutsche-boerse.com';
    private const FILE_LIMIT = 20;
    private const SESSION_LIMIT = 100000;
    private const SESSION_FINGERPRINT_BYTES = 16777216;
    private const SESSION_WITNESSES = 128;
    public const XETRA_EVIDENCE_BYTES = 6291456;

    /** GET($url, $maxBytes) returns raw bytes or throws; failures omit the quote. */
    public static function refresh(array $instruments, array $state, callable $get, int $now): array
    {
        $quotes = $issues = $xetra = $euronext = [];
        foreach ($instruments as $instrument) {
            $id = $instrument['id'] ?? '';
            if (!is_string($id) || $id === '') continue;
            $provider = $instrument['provider'] ?? null;
            if ($provider === 'xetra') { $xetra[] = $instrument; continue; }
            if ($provider === 'euronext') { $euronext[] = $instrument; continue; }
            if ($provider !== 'bnd') continue;
            if (!self::identity($instrument)) {
                $quotes[$id] = self::missing($provider, 'listing_unverified');
                $issues[] = ['instrumentId' => $id, 'code' => 'listing_unverified'];
                continue;
            }
            $config = $instrument['providerConfig'] ?? [];
            if (($config['identityConfirmed'] ?? false) !== true) {
                $quotes[$id] = self::missing('Brand New Day NAV', 'listing_unverified');
                $issues[] = ['instrumentId' => $id, 'code' => 'listing_unverified'];
                continue;
            }
            $fund = $config['fundId'] ?? null;
            if (!is_int($fund) || $fund <= 0 || $fund > 99999999) {
                $quotes[$id] = self::missing('Brand New Day NAV', 'listing_unverified');
                $issues[] = ['instrumentId' => $id, 'code' => 'listing_unverified'];
                continue;
            }
            $fundFingerprint = hash('sha256', json_encode([$fund, $instrument['isin'], $instrument['currency']], JSON_THROW_ON_ERROR));
            $lastAttempt = $state['bnd'][$id] ?? [];
            $nextAttempt = is_int($lastAttempt['nextAttempt'] ?? null) ? $lastAttempt['nextAttempt']
                : (is_int($lastAttempt['at'] ?? null) ? $lastAttempt['at'] + 3600 : null);
            if (($lastAttempt['fingerprint'] ?? null) === $fundFingerprint
                && is_int($nextAttempt) && $now < $nextAttempt) continue;
            // Retain a real attempt time for diagnostics/rollback. Successful
            // reads align to clock hours; failures keep a full-hour backoff.
            $state['bnd'][$id] = ['fingerprint' => $fundFingerprint, 'at' => $now, 'nextAttempt' => $now + 3600];
            try {
                $facts = self::json($get(self::BND . '/v1/fund/' . $fund . '/factsheetdata', 1048576));
                $rates = self::json($get(self::BND . '/v1/fundrates?id=' . $fund, 4194304));
                $quotes[$id] = self::bndQuote($instrument, $facts, $rates, $now);
                if ($quotes[$id]['price'] !== null) $state['bnd'][$id]['nextAttempt'] = (intdiv($now, 3600) + 1) * 3600;
                foreach ($quotes[$id]['issues'] as $code) $issues[] = ['instrumentId' => $id, 'code' => $code];
            } catch (\Throwable) {
                $issues[] = ['instrumentId' => $id, 'code' => 'source_unavailable'];
            }
        }
        if ($euronext) {
            $result = EuronextProviders::refresh($euronext, $state['euronext'] ?? [], $get, $now);
            $quotes += $result['quotes'];
            $issues = array_merge($issues, $result['issues']);
            $state['euronext'] = $result['state'];
        }
        if ($xetra) {
            $result = self::xetraRefresh($xetra, $state['xetra'] ?? [], $get, $now);
            $quotes += $result['quotes'];
            $issues = array_merge($issues, $result['issues']);
            $state['xetra'] = $result['state'];
            $state['nextPollSeconds'] = $result['state']['nextPollSeconds'];
        }
        $historyInstruments=array_values(array_filter($instruments,static fn($i)=>isset($i['providerConfig']['historyProvider'])));
        if($historyInstruments){
            $history=EuropeanHistory::refresh($historyInstruments,$state['history']??[],$get,$now,$quotes);
            $state['history']=$history['state'];
            foreach($historyInstruments as $i){
                $id=$i['id'];$direct=$quotes[$id]??null;$historical=$history['quotes'][$id]??null;
                $joined=self::withHistory($i,$direct,$historical,$state['history'][$id]??[],$state['actions']??[],$get,$now,$state['xetra']??[],$state['euronext'][$i['providerConfig']['tradingSchedule']??'']??[]);
                $state['actions']=$joined['actions'];
                if($joined['quote']!==null){
                    $quotes[$id]=$joined['quote'];
                    $issues=array_values(array_filter($issues,static fn($issue)=>$issue['instrumentId']!==$id));
                    foreach($quotes[$id]['issues']??[] as $code)$issues[]=['instrumentId'=>$id,'code'=>$code];
                }
            }
        }
        // The ambiguity date is adapter evidence used by withHistory above,
        // not part of the persisted/public quote schema. Keep the original in
        // Euronext's lastQuotes for the next gated check, while retaining its
        // eligibility issue and every invalidation on the selected result.
        foreach ($quotes as &$quote) unset($quote['eligibilityDate']);
        unset($quote);
        return ['quotes' => $quotes, 'state' => $state, 'issues' => $issues];
    }

    /** Complete closes are a coherent fallback; a new intraday session needs
     * its own dated action check, never yesterday's adjustment ratio alone. */
    public static function withHistory(array $i,?array $direct,?array $history,array $historyState,array $actions,callable $get,int $now,array $referenceState=[],array $correctionState=[]):array
    {
        if(($i['providerConfig']['historyIdentityRule']??null)==='xetra_auction_match'&&($historyState['identityRevoked']??false)===true){
            $history=null;$historyState['rows']=[];$historyState['retainedCloses']=[];
            if(($direct['price']??null)===null)return ['quote'=>self::missing('Newton Analytics daily close','listing_unverified'),'actions'=>$actions];
        }
        $quote=$direct;$id=$i['id'];$context=EuropeanHistory::sessionContext($i['mic'],$now);
        if($context===null){
            if($quote!==null){$quote['previousClose']=null;$quote['previousDate']=null;$quote['previousObservedAt']=null;$quote['basis']='unverified';$quote['invalidations']=['comparison'];$quote['issues'][]='calendar_unverified';}
            return ['quote'=>$quote,'actions'=>$actions];
        }
        // Simultaneous distinct final trades do not pick an arbitrary price.
        // A separately validated completed close can resolve that limited
        // ambiguity, but yesterday's close cannot hide today's ambiguous trade.
        $ambiguityResolved=($i['provider']??null)==='euronext'
            &&($direct['eligibilityIssue']??null)==='conflicting_latest_trade_prices'
            &&MarketJson::date($direct['eligibilityDate']??null)
            &&EuropeanHistory::isSession($i['mic'],$direct['eligibilityDate'])===true
            &&($history['date']??null)===$context['expectedCloseDate']&&$history['date']>=$direct['eligibilityDate']
            &&($history['previousDate']??null)===$context['previousDate']
            &&($history['price']??null)!==null&&($history['previousClose']??null)!==null
            &&($history['listingId']??null)===$id&&($history['mic']??null)===$i['mic']
            &&($history['isin']??null)===($i['isin']??null)&&($history['currency']??null)===$i['currency']
            &&($history['basis']??null)==='same_units'&&($history['invalidations']??null)===[]
            &&($history['status']??null)==='closed'&&is_int($history['freshUntil']??null)&&$history['freshUntil']>$now
            &&($history['sourceAvailable']??null)===true;
        $knownVeto=$direct!==null&&($direct['price']??null)===null&&(count(array_intersect(['corporate_action_unverified','listing_unverified'],$direct['issues']??[]))>0||(isset($direct['eligibilityIssue'])&&!$ambiguityResolved));
        $directComplete=($direct['previousClose']??null)!==null&&($direct['price']??null)!==null
            &&($direct['date']??'')>=($history['date']??'')&&($direct['status']??null)!=='stale'
            &&($direct['previousDate']??null)===EuropeanHistory::previousSession($i['mic'],$direct['date']);
        if($history!==null&&($history['price']??null)!==null&&!$knownVeto&&!$directComplete
            &&(($direct['price']??null)===null||($history['date']??'')>=($direct['date']??'')))$quote=$history;
        elseif($direct!==null&&($direct['price']??null)!==null&&$context!==null){
            $previous=EuropeanHistory::previousSession($i['mic'],$direct['date']);
            $row=$previous===null?null:($historyState['rows'][$previous]??null);
            $retained=$previous===null?null:EuropeanHistory::retainedClose($i,$historyState,$previous,$now);
            $retainedBaseline=$retained!==null
                &&(($row['close']??null)===null||$row['close']===$retained['close'])
                &&(($row['adjustedClose']??null)===null||$row['adjustedClose']===$retained['adjustedClose']);
            if($retainedBaseline)$row=$retained;
            $correctionVeto=$previous!==null&&self::historyCorrectionVeto($correctionState,$id,$previous);
            $basis=($direct['previousClose']??null)!==null&&($direct['previousDate']??null)===$previous;
            // A complete Xetra pair already passed its stronger dated-reference
            // or specifically reviewed auction-pair proof in xetraQuote.
            $historyIdentity=($i['providerConfig']['historyIdentityRule']??null)!=='xetra_auction_match'
                ||(($historyState['identityVerified']??false)===true&&($historyState['identityRevoked']??false)!==true);
            if(!$basis&&$historyIdentity&&!$correctionVeto&&$row!==null&&($row['close']??null)!==null
                &&($retainedBaseline||(($historyState['sourceAvailable']??false)===true&&($historyState['fetchedAt']??0)>=$now-3*3600))){
                $checked=self::referenceCompatible($i,$referenceState,$actions,$previous,$direct['date'])
                    ?['compatible'=>true,'state'=>$actions]:EuropeanActions::check($i,$previous,$direct['date'],$actions,$get,$now);$actions=$checked['state'];
                if($checked['compatible']){
                    $quote=$direct;$quote['previousClose']=$row['close'];$quote['previousDate']=$previous;$quote['previousObservedAt']=null;
                    $quote['source'].='; Newton Analytics previous close';$quote['issues']=array_values(array_diff($quote['issues']??[],['baseline_missing','corporate_action_unverified']));$basis=true;
                }
            }
            if($basis){
                $time=MarketJson::instant($quote['observedAt']??null);
                $kind=($quote['priceKind']??null)==='close'||($quote['status']??null)==='closed'?'close':'trade';
                $fresh=$kind==='close'&&$quote['date']===$context['expectedCloseDate']?$context['freshUntil']:($time??0)+5400;
                if(($quote['status']??null)==='stale')$fresh=min($fresh,$now);
                $quote=array_replace($quote,['listingId'=>$id,'mic'=>$i['mic'],'currency'=>'EUR','priceKind'=>$kind,
                    'previousKind'=>'close','expectedPreviousDate'=>$previous,'basis'=>'same_units','freshUntil'=>$fresh,'invalidations'=>[],'sourceAvailable'=>true]);
            }else{
                $quote=$direct;$quote['previousClose']=null;$quote['previousDate']=null;$quote['previousObservedAt']=null;
                $quote['basis']='unverified';$quote['invalidations']=['comparison'];$quote['issues'][]='baseline_missing';
                $time=MarketJson::instant($quote['observedAt']??null);
                $kind=($quote['priceKind']??null)==='close'||($quote['status']??null)==='closed'?'close':'trade';
                $fresh=$kind==='close'&&$quote['date']===$context['expectedCloseDate']?$context['freshUntil']:($time??0)+5400;
                if(($quote['status']??null)==='stale')$fresh=min($fresh,$now);
                $quote=array_replace($quote,['listingId'=>$id,'mic'=>$i['mic'],'currency'=>'EUR','priceKind'=>$kind,
                    'previousKind'=>'close','expectedPreviousDate'=>$previous,'freshUntil'=>$fresh,'sourceAvailable'=>true]);
            }
        }
        $unitsThrough=$quote['date']??null;
        // A completed old pair proves units only through its own final date.
        // Before the next session opens, check whether today's units changed;
        // this same interval later qualifies today's direct-price comparison.
        // Ordinary weekends require no new interval or extra action request.
        if($quote!==null&&($quote['price']??null)!==null&&$context['isSession']&&($quote['date']??'')<$context['date']){
            $checked=self::referenceCompatible($i,$referenceState,$actions,$quote['date'],$context['date'])
                ?['compatible'=>true,'state'=>$actions]:EuropeanActions::check($i,$quote['date'],$context['date'],$actions,$get,$now);$actions=$checked['state'];
            if(!$checked['compatible']){
                $quote['price']=$quote['previousClose']=null;$quote['status']='missing';
                $quote['invalidations']=['current','comparison'];$quote['issues'][]='corporate_action_unverified';
            }else $unitsThrough=$context['date'];
        }
        // Cache-only reads cannot initiate this check. Expire readiness at the
        // next local session date so a late collector cannot cross it silently.
        if($quote!==null&&($quote['price']??null)!==null&&is_string($unitsThrough)&&isset($i['timeZone'])&&is_int($quote['freshUntil']??null)){
            $nextSession=EuropeanHistory::nextSession($i['mic'],$unitsThrough);
            if($nextSession!==null)$quote['freshUntil']=min($quote['freshUntil'],(new \DateTimeImmutable($nextSession.' 00:00:00',new \DateTimeZone($i['timeZone'])))->getTimestamp());
        }
        if($quote!==null&&EuropeanActions::oldBasisUnsafe($actions,$id,$quote['date']??null,$now)){
            $quote['price']=$quote['previousClose']=null;$quote['status']='missing';$quote['invalidations']=['current','comparison'];$quote['issues'][]='corporate_action_unverified';
        }
        // A known action is a veto even if another source later publishes an
        // apparently normal reference or unchanged adjusted-price ratio.
        if($quote!==null&&is_string($quote['previousDate']??null)&&is_string($quote['date']??null)
            &&self::knownActionBetween($actions,$id,$quote['previousDate'],$quote['date'])){
            $quote['previousClose']=null;$quote['previousObservedAt']=null;$quote['basis']='unverified';
            $quote['invalidations']=array_values(array_unique([...($quote['invalidations']??[]),'comparison']));$quote['issues'][]='corporate_action_unverified';
        }
        // Retention never overrides an explicit exchange cancellation/conflict.
        if($quote!==null&&self::historyCorrectionVeto($correctionState,$id,$quote['date']??null)){
            $quote['price']=$quote['previousClose']=null;$quote['status']='missing';
            $quote['invalidations']=['current','comparison'];$quote['issues'][]='corporate_action_unverified';
        }elseif($quote!==null&&self::historyCorrectionVeto($correctionState,$id,$quote['previousDate']??null)){
            $quote['previousClose']=$quote['previousObservedAt']=null;$quote['basis']='unverified';
            $quote['invalidations']=array_values(array_unique([...($quote['invalidations']??[]),'comparison']));$quote['issues'][]='corporate_action_unverified';
        }
        return ['quote'=>$quote,'actions'=>$actions];
    }

    private static function historyCorrectionVeto(array $state,string $id,?string $date):bool
    {
        return $date!==null&&(isset($state['invalidDates'][$id][$date])||$date<=($state['invalidThrough'][$id]??''));
    }

    /** Warm exact daily exchange references can replace the per-listing API
     * check. Neither a missing older day nor a newer file is backdated. */
    private static function referenceCompatible(array $i,array $reference,array $actions,string $previous,string $current):bool
    {
        if(($i['provider']??null)!=='xetra'||($i['providerConfig']['identityConfirmed']??false)!==true
            ||self::knownActionBetween($actions,$i['id'],$previous,$current)||!XetraReference::compatible($reference,$i['id'],$current,$previous))return false;
        foreach([$previous,$current] as $date){
            $identity=$reference['references'][$date]['rows'][$i['id']]['identity']??null;
            if(!is_string($identity))return false;$parts=explode('|',$identity);
            if(count($parts)!==5||$parts[1]!==($i['isin']??null)||$parts[2]!==($i['providerConfig']['venue']??null)||$parts[3]!=='Shares'||$parts[4]!==($i['currency']??null))return false;
        }
        return true;
    }
    private static function knownActionBetween(array $actions,string $id,string $previous,string $current):bool
    {
        foreach($actions[$id]['events']??[] as $date=>$_)if($date>$previous&&$date<=$current)return true;
        return false;
    }

    /** Factsheet ISIN is mandatory; values use NAV, never bid/ask or total-return fields. */
    public static function bndQuote(array $instrument, array $facts, array $history, int $now): array
    {
        $fund = $instrument['providerConfig']['fundId'] ?? null;
        if (!self::identity($instrument) || ($instrument['providerConfig']['identityConfirmed'] ?? false) !== true
            || !is_int($fund) || $fund <= 0) {
            return self::missing('Brand New Day NAV', 'listing_unverified');
        }
        // A partial response cannot establish a new NAV, but does not revoke
        // accepted older values. Check present identifiers first: a different
        // fund/share class remains a veto even alongside a missing field.
        $complete = true;
        foreach ([$facts['fundId'] ?? null, $history['fundId'] ?? null] as $receivedFund) {
            if ($receivedFund === null || $receivedFund === '') { $complete = false; continue; }
            if ((!is_int($receivedFund) && !is_string($receivedFund)) || (string) $receivedFund !== (string) $fund)
                return self::missing('Brand New Day NAV', 'listing_unverified');
        }
        $receivedIsin = $facts['basicCharacteristics']['isin'] ?? null;
        if ($receivedIsin === null || $receivedIsin === '') $complete = false;
        elseif ($receivedIsin !== $instrument['isin']) return self::missing('Brand New Day NAV', 'listing_unverified');
        if (!$complete) throw new \RuntimeException('Incomplete fund identity response.');
        if (!is_array($history['rates'] ?? null) || count($history['rates']) > 20000) throw new \RuntimeException('Invalid NAV history.');
        $dates = [];
        $today = (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone('Europe/Amsterdam'))->format('Y-m-d');
        foreach ($history['rates'] as $row) {
            if (!is_array($row) || !is_string($row['date'] ?? null)
                || !preg_match('/^(\d{4}-\d{2}-\d{2})(?:T00:00:00)?$/D', $row['date'], $match)
                || !self::date($match[1]) || $match[1] > $today || !self::price($row['nav'] ?? null)) {
                throw new \RuntimeException('Invalid NAV observation.');
            }
            $date = $match[1];
            $value = self::price($row['nav']);
            if (isset($dates[$date]) && $dates[$date] !== $value) throw new \RuntimeException('Conflicting NAV date.');
            $dates[$date] = $value;
        }
        krsort($dates, SORT_STRING);
        $days = array_keys($dates);
        if (!$days) return self::missing('Brand New Day NAV', 'price_missing');
        $latest = $days[0];
        $previous = $days[1] ?? null;
        // Publication cadence is not guaranteed. Preserve expected weekend lag;
        // flag a full calendar week without a new valuation conservatively.
        $stale = $latest < gmdate('Y-m-d', $now - 7 * 86400);
        $issues = $previous === null ? ['baseline_missing'] : [];
        if ($stale) $issues[] = 'price_stale';
        return ['price' => $dates[$latest], 'previousClose' => $previous ? $dates[$previous] : null,
            'date' => $latest, 'previousDate' => $previous, 'observedAt' => null, 'previousObservedAt' => null,
            'status' => $stale ? 'stale' : 'nav', 'source' => 'Brand New Day NAV', 'fx' => '1', 'previousFx' => '1', 'issues' => $issues,
            'listingId'=>$instrument['id'],'mic'=>$instrument['mic']??null,'currency'=>'EUR','isin'=>$instrument['isin'],
            'priceKind'=>'nav','previousKind'=>'nav','expectedPreviousDate'=>$previous,'basis'=>'same_units',
            'freshUntil'=>(new \DateTimeImmutable($latest.'T00:00:00',new \DateTimeZone('Europe/Amsterdam')))->modify('+7 days')->getTimestamp(),
            'invalidations'=>[],'sourceAvailable'=>true];
    }

    private static function xetraRefresh(array $instruments, array $state, callable $get, int $now): array
    {
        $quotes = $issues = $valid = [];
        foreach ($instruments as $instrument) {
            $config = $instrument['providerConfig'] ?? [];
            if (!self::identity($instrument) || ($config['identityConfirmed'] ?? false) !== true
                || !preg_match('/^[A-Z0-9]{4}$/D', $config['venue'] ?? '')
                || !preg_match('/^[A-Z0-9]{4}$/D', $config['publicationVenue'] ?? '')) {
                $quotes[$instrument['id']] = self::missing('Xetra delayed trades', 'listing_unverified');
                $issues[] = ['instrumentId' => $instrument['id'], 'code' => 'listing_unverified'];
            } else $valid[] = $instrument;
        }
        $fingerprint = hash('sha256', json_encode(array_map(fn($i) => [$i['id'], $i['isin'], $i['currency'], $i['providerConfig']], $valid), JSON_THROW_ON_ERROR));
        $members=[];foreach($valid as $i)$members[$i['id']]=hash('sha256',json_encode([$i['isin'],$i['currency'],$i['mic']??null,$i['providerConfig']['venue'],$i['providerConfig']['publicationVenue']],JSON_THROW_ON_ERROR));
        if(isset($state['members'])){
            $changed=false;
            foreach($members as $id=>$identity)if(($state['members'][$id]??null)!==$identity){
                $changed=true;
                foreach(['sessions','reviewedPairs','referenceIdentity','referenceRevoked','referenceActions'] as $field)unset($state[$field][$id]);
                foreach($state['references']??[] as $date=>$_)unset($state['references'][$date]['rows'][$id]);
            }
            if($changed){$state['cursor']=null;unset($state['lastDailyCheckedAt'],$state['referenceAttemptAt']);}
        }elseif(($state['fingerprint']??null)!==$fingerprint){
            // Legacy state has no per-listing identity ledger. Migration can
            // install it after checking the old registry; otherwise cold-start.
            $state=['sessions'=>[],'cursor'=>null];
        }
        $state['fingerprint']=$fingerprint;$state['members']=$members;
        $state['nextPollSeconds'] = 600;
        if (!$valid) return ['quotes' => $quotes, 'state' => $state, 'issues' => $issues];
        $state = XetraReference::refresh($valid, $state, $get, $now);
        $failed = $catchup = false;
        $began = microtime(true);
        // These bounded diagnostics describe the collection attempt, not quote
        // freshness. Keep them private in provider state; never store source
        // response bodies or exception messages here.
        $collection = ['attemptedAt' => gmdate('Y-m-d\TH:i:s\Z', $now), 'outcome' => 'complete',
            'plannedFiles' => 0, 'downloadedFiles' => 0, 'processedFiles' => 0, 'skippedFiles' => 0,
            'cursorBefore' => $state['cursor'] ?? null, 'newestPlannedFile' => null, 'failureStage' => null,
            'failureFile' => null, 'failureFileKind' => null,
            'unrecognizedFileCount' => 0, 'unavailableDailyFiles' => 0, 'failureHttpStatus' => null,
            'dailyAliasFiles' => 0, 'failedDailyAliasFiles' => 0, 'dailyAliasFailureStage' => null];
        $stage = 'index';
        $activeFile = null; $activeKind = 'index';
        try {
            $index = self::json($get(self::XETRA . '/api/DETR-posttrade', 2097152));
            // Index rotation must not send a warm collector backwards to an
            // older daily archive. Only a fully committed, correctly dated
            // success for the current listing membership establishes this floor.
            $dailyFloor = null;
            if (is_int($state['lastDailyCheckedAt'] ?? null) && $state['lastDailyCheckedAt'] > 0 && $state['lastDailyCheckedAt'] <= $now
                && preg_match('/^DETR-posttrade-daily-(\d{4}-\d{2}-\d{2})\.json\.gz$/D', $state['lastDaily'] ?? '', $dailyMatch)
                && self::date($dailyMatch[1])) $dailyFloor = $dailyMatch[1];
            $plan = self::xetraPlan($index, $state['cursor'] ?? null, $dailyFloor);
            $files = $plan['files'];
            $collection['dailyAliasFiles'] = count($plan['dailyAliases']);
            $collection['unrecognizedFileCount'] = $plan['unrecognizedFileCount'];
            if ($plan['unrecognizedFileCount'] > 0) {
                $state['archiveUncertain'] = true;
                $state['archiveUncertainDate'] = max($state['archiveUncertainDate'] ?? '', $plan['coverageDate'] ?? substr($state['cursor'] ?? '', 0, 10));
            }
            $collection['plannedFiles'] = count($files);
            $collection['newestPlannedFile'] = $files ? $files[array_key_last($files)] : null;
            if ($plan['retentionGap']) {
                $state['retentionGap'] = true;
                $state['retentionGapDate'] = max($state['retentionGapDate'] ?? '', $plan['retentionGapDate']);
            }
            $processed = 0; $archiveCursor = null;
            foreach ($files as $file) {
                $dailyAlias = isset($plan['dailyAliases'][$file]);
                $activeFile = $file;
                $activeKind = $dailyAlias ? 'daily_alias' : (str_contains($file, '-daily-') ? 'daily' : 'minute');
                // An alias is not coverage until its canonical archive has
                // passed the whole gzip/record/evidence path below. Only then
                // may it suppress same-day minute duplicates already planned.
                if (!str_contains($file, '-daily-') && $archiveCursor !== null
                    && substr($file, strlen('DETR-posttrade-'), 16) <= $archiveCursor) {
                    $collection['skippedFiles']++;
                    continue;
                }
                if ($file === ($state['lastDaily'] ?? null) && is_int($state['lastDailyCheckedAt'] ?? null)
                    && $state['lastDailyCheckedAt'] <= $now && $now - $state['lastDailyCheckedAt'] < 3600) {
                    $collection['skippedFiles']++;
                    continue;
                }
                $aliasFailure = $state['dailyAliasFailure'] ?? null;
                if ($dailyAlias && is_string($state['cursor'] ?? null) && is_array($aliasFailure)
                    && ($aliasFailure['file'] ?? null) === $file && is_int($aliasFailure['at'] ?? null)
                    && $aliasFailure['at'] <= $now && $now - $aliasFailure['at'] < 3600) {
                    $collection['skippedFiles']++;
                    $collection['failedDailyAliasFiles']++;
                    $collection['dailyAliasFailureStage'] = $aliasFailure['stage'];
                    $collection['failureHttpStatus'] = $aliasFailure['httpStatus'];
                    $collection['failureFile'] = $file; $collection['failureFileKind'] = $activeKind;
                    continue;
                }
                if ($processed >= self::FILE_LIMIT || microtime(true) - $began > 45) {
                    $collection['outcome'] = $processed >= self::FILE_LIMIT ? 'file_limit' : 'time_limit';
                    $catchup = true;
                    break;
                }
                $stage = 'download';
                try {
                $unavailable = $state['unavailableDaily'] ?? null;
                if (is_array($unavailable) && ($unavailable['file'] ?? null) === $file && $file === ($state['lastDaily'] ?? null)
                    && is_string($state['cursor'] ?? null) && is_int($state['lastDailyCheckedAt'] ?? null)
                    && $state['lastDailyCheckedAt'] <= $now && is_int($unavailable['at'] ?? null) && $unavailable['at'] <= $now && $now - $unavailable['at'] < 3600) {
                    $collection['skippedFiles']++;
                    $collection['unavailableDailyFiles']++;
                    $collection['failureHttpStatus'] = 404;
                    $collection['failureFile'] = $file; $collection['failureFileKind'] = $activeKind;
                    continue;
                }
                try { $raw = $get(self::XETRA . '/api/download/' . $file, 33554432); }
                catch (ProviderHttpStatus $error) {
                    $collection['failureHttpStatus'] = $error->status;
                    $collection['failureFile'] = $file; $collection['failureFileKind'] = $activeKind;
                    // An expired archive already parsed successfully must not
                    // strand newer minute files. Keep correction uncertainty;
                    // no unseen archive or failed minute receives this exception.
                    if ($error->status !== 404 || $file !== ($state['lastDaily'] ?? null)
                        || !is_int($state['lastDailyCheckedAt'] ?? null) || $state['lastDailyCheckedAt'] > $now
                        || !is_string($state['cursor'] ?? null)) throw $error;
                    $state['unavailableDaily'] = ['file'=>$file, 'at'=>$now, 'status'=>404];
                    $state['archiveUncertain'] = true;
                    $state['archiveUncertainDate'] = max($state['archiveUncertainDate'] ?? '', $plan['coverageDate'] ?? substr($state['cursor'], 0, 10));
                    $collection['unavailableDailyFiles']++;
                    continue;
                }
                $collection['downloadedFiles']++;
                $stage = 'parse';
                // Each file is atomic: malformed/truncated input cannot advance
                // the cursor or partially replace a previously coherent value.
                $working = $state;
                $maxPublication = null;
                foreach (self::gzipRows($raw) as $row) {
                    $published = self::instant($row['publicationDateAndTime'] ?? null);
                    if ($published !== null && $published <= $now && ($maxPublication === null || $published > $maxPublication)) $maxPublication = $published;
                    foreach ($valid as $instrument) self::xetraRecord($working, $instrument, $row, $now);
                }
                if (str_contains($file, '-daily-')) {
                    $dailyDate = substr($file, strlen('DETR-posttrade-daily-'), 10);
                    // The observed index alias is only a routing hint. Reject
                    // empty/wrong-day payloads instead of certifying coverage.
                    if ($maxPublication === null || gmdate('Y-m-d', $maxPublication) !== $dailyDate) {
                        throw new \RuntimeException('Daily archive date not established.');
                    }
                    if ($maxPublication !== null) $working['cursor'] = max($working['cursor'] ?? '', gmdate('Y-m-d\TH_i', $maxPublication));
                    $working['lastDaily'] = $file;
                    $working['lastDailyCheckedAt'] = $now;
                    // A complete daily archive re-establishes observed current
                    // values after a retention gap; no lost baseline is inferred.
                    if ($dailyDate >= ($working['retentionGapDate'] ?? '')) {
                        $working['retentionGap'] = false;
                        $working['retentionGapDate'] = null;
                    }
                    // A newly complete archive can restore value coverage when
                    // remaining index entries are recognized (including the
                    // verified alias) and it covers the earlier affected date.
                    if ($plan['unrecognizedFileCount'] === 0 && $dailyDate >= ($working['archiveUncertainDate'] ?? '')) {
                        $working['archiveUncertain'] = false;
                        $working['archiveUncertainDate'] = null;
                        $working['unavailableDaily'] = null;
                        $working['dailyAliasFailure'] = null;
                    }
                } elseif (preg_match('/^DETR-posttrade-(\d{4}-\d{2}-\d{2}T\d{2}_\d{2})\.json\.gz$/D', $file, $match)) {
                    $working['cursor'] = max($working['cursor'] ?? '', $match[1]);
                }
                self::trimSessions($working);
                $stage = 'evidence';
                self::packTransactionEvidence($working);
                $state = $working;
                if (str_contains($file, '-daily-') && $maxPublication !== null) $archiveCursor = gmdate('Y-m-d\TH_i', $maxPublication);
                $processed++;
                $collection['processedFiles']++;
                } catch (\Throwable $error) {
                    if (!$dailyAlias || !is_string($state['cursor'] ?? null)) throw $error;
                    // A failed alias stays explicitly unverified. Warm minute
                    // catch-up remains possible, including after a truncated
                    // archive; the per-file working copy was never committed.
                    $state['archiveUncertain'] = true;
                    $state['archiveUncertainDate'] = max($state['archiveUncertainDate'] ?? '', substr($file, strlen('DETR-posttrade-daily-'), 10));
                    $state['dailyAliasFailure'] = ['file'=>$file, 'at'=>$now, 'stage'=>$stage,
                        'httpStatus'=>$error instanceof ProviderHttpStatus ? $error->status : null];
                    $collection['failedDailyAliasFiles']++;
                    $collection['dailyAliasFailureStage'] = $stage;
                    $collection['failureHttpStatus'] = $state['dailyAliasFailure']['httpStatus'];
                    $collection['failureFile'] = $file; $collection['failureFileKind'] = $activeKind;
                }
            }
            $state['lastPollAt'] = gmdate('Y-m-d\TH:i:s\Z', $now);
            $state['catchupPending'] = $catchup || ($state['archiveUncertain'] ?? false);
            if (!$catchup && ($state['archiveUncertain'] ?? false)) $collection['outcome'] = 'archive_incomplete';
            if (!$catchup && ($state['retentionGap'] ?? false)) $collection['outcome'] = 'retention_gap';
        } catch (\Throwable $error) {
            if ($error instanceof ProviderHttpStatus) $collection['failureHttpStatus'] = $error->status;
            $failed = true;
            $state['catchupPending'] = true;
            $collection['outcome'] = 'source_failure';
            $collection['failureStage'] = $stage;
            $collection['failureFile'] = $activeFile; $collection['failureFileKind'] = $activeKind;
        }
        $collection['cursorAfter'] = $state['cursor'] ?? null;
        $collection['retentionGap'] = ($state['retentionGap'] ?? false) === true;
        $collection['archiveUncertain'] = ($state['archiveUncertain'] ?? false) === true;
        $collection['elapsedMilliseconds'] = max(0, (int) round((microtime(true) - $began) * 1000));
        $state['collection'] = $collection;
        foreach ($valid as $instrument) {
            $id = $instrument['id'];
            // On total retrieval failure omit an unseen quote; core may retain
            // an older snapshot as stale. Explicit invalidation remains explicit.
            if (!isset($state['sessions'][$id]) && $failed) {
                $issues[] = ['instrumentId' => $id, 'code' => 'source_unavailable'];
                continue;
            }
            $quote = self::xetraQuote($state['sessions'][$id] ?? [], $now, $failed || $catchup || ($state['retentionGap'] ?? false) || ($state['archiveUncertain'] ?? false), $state, $id);
            $quotes[$id] = $quote;
            foreach ($quote['issues'] as $code) $issues[] = ['instrumentId' => $id, 'code' => $code];
            if ($failed || ($state['referenceFailed'] ?? false)) $issues[] = ['instrumentId' => $id, 'code' => 'source_unavailable'];
        }
        return ['quotes' => $quotes, 'state' => $state, 'issues' => $issues];
    }

    /** Safe filenames only; daily bootstrap then bounded chronological minute catch-up. */
    public static function xetraPlan(array $index, ?string $cursor, ?string $dailyFloor = null): array
    {
        if (($index['SourcePrefix'] ?? null) !== 'DETR-posttrade' || !is_array($index['CurrentFiles'] ?? null)
            || count($index['CurrentFiles']) > 5000 || ($cursor !== null && !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}_\d{2}$/D', $cursor))
            || ($dailyFloor !== null && !self::date($dailyFloor))) {
            throw new \RuntimeException('Invalid Xetra index.');
        }
        $daily = $aliases = $minutes = []; $unrecognized = 0;
        foreach ($index['CurrentFiles'] as $file) {
            if (!is_string($file)) throw new \RuntimeException('Invalid Xetra filename.');
            if (preg_match('/^DETR-posttrade-daily-(\d{4}-\d{2}-\d{2})\.json\.gz$/D', $file, $m) && self::date($m[1])) $daily[$m[1]] = $file;
            elseif (preg_match('/^DETR-posttrade-(\d{4})-daily-(\d{2})-(\d{2})\.json\.gz$/D', $file, $m) && self::date($m[1].'-'.$m[2].'-'.$m[3])) {
                // Observed official index defect: its alternate spelling
                // returns400, while this existing documented route returns
                // the complete archive. Never request arbitrary alternatives.
                $date=$m[1].'-'.$m[2].'-'.$m[3];$aliases[$date]='DETR-posttrade-daily-'.$date.'.json.gz';
            }
            elseif (preg_match('/^DETR-posttrade-(\d{4}-\d{2}-\d{2})T(\d{2})_(\d{2})\.json\.gz$/D', $file, $m)
                && self::date($m[1]) && (int) $m[2] <= 23 && (int) $m[3] <= 59) $minutes[$m[1] . 'T' . $m[2] . '_' . $m[3]] = $file;
            elseif ($cursor !== null && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,119}$/D', $file) && !str_contains($file, '..')
                && !preg_match('/^DETR-posttrade-(?:daily-\d{4}-\d{2}-\d{2}|\d{4}-daily-\d{2}-\d{2}|\d{4}-\d{2}-\d{2}T\d{2}_\d{2})\.json\.gz$/D', $file)) $unrecognized++;
            else throw new \RuntimeException('Unexpected Xetra filename.');
        }
        ksort($daily); ksort($aliases); ksort($minutes);
        $files = [];
        $dailyDate = $daily ? array_key_last($daily) : null;
        $aliasDate = $aliases ? array_key_last($aliases) : null;
        $dailyAliases = []; $selectedDailyDate = null;
        // Include the available daily archive for bootstrap/correction checks;
        // refresh gates repeat downloads hourly. Replays are idempotent.
        if ($aliasDate !== null && ($dailyDate === null || $aliasDate > $dailyDate) && ($dailyFloor === null || $aliasDate >= $dailyFloor)) {
            $files[] = $aliases[$aliasDate];$dailyAliases[$aliases[$aliasDate]]=true;$selectedDailyDate=$aliasDate;
        } elseif ($dailyDate !== null && ($dailyFloor === null || $dailyDate >= $dailyFloor)) {
            $files[] = $daily[$dailyDate];$selectedDailyDate=$dailyDate;
        }
        $earliest = null;
        foreach ($minutes as $minute => $file) {
            if ($cursor !== null && $minute < $cursor) continue; // overlap cursor minute
            $earliest ??= $minute;
            $files[] = $file;
        }
        // Old advertised files must not conceal a missing overlap when the
        // available stream has moved into a new day. Do not infer same-day
        // minute holes: the index may legitimately omit quiet intervals.
        $gap = $cursor !== null && $earliest !== null && substr($cursor, 0, 10) < substr($earliest, 0, 10)
            && ($selectedDailyDate === null || $selectedDailyDate < substr($earliest, 0, 10));
        return ['files' => $files, 'dailyAliases'=>$dailyAliases, 'retentionGap' => $gap, 'unrecognizedFileCount' => $unrecognized,
            'retentionGapDate' => $gap ? substr($earliest, 0, 10) : null,
            'coverageDate' => max($dailyDate ?? '', $aliasDate ?? '', $minutes ? substr(array_key_last($minutes), 0, 10) : '') ?: null];
    }

    /** Parse concatenated gzip members incrementally, including empty first members. */
    public static function gzipRows(string $compressed, int $maxDecoded = 335544320, int $maxRows = 600000): \Generator
    {
        if (!function_exists('inflate_init')) throw new \RuntimeException('Gzip support unavailable.');
        if ($compressed === '' || strlen($compressed) > 33554432) throw new \RuntimeException('Invalid compressed size.');
        $context = inflate_init(ZLIB_ENCODING_GZIP);
        $position = $decoded = $rows = 0;
        $members = 1;
        $line = '';
        $ended = false;
        $length = strlen($compressed);
        while ($position < $length) {
            if ($ended) {
                if (++$members > 2000) throw new \RuntimeException('Too many gzip members.');
                $context = inflate_init(ZLIB_ENCODING_GZIP);
                $ended = false;
            }
            $part = substr($compressed, $position, 4096);
            $before = inflate_get_read_len($context);
            $output = @inflate_add($context, $part, ZLIB_SYNC_FLUSH);
            if ($output === false) throw new \RuntimeException('Invalid gzip member.');
            $consumed = inflate_get_read_len($context) - $before;
            if ($consumed <= 0) throw new \RuntimeException('Gzip decoder made no progress.');
            $position += $consumed;
            $decoded += strlen($output);
            if ($decoded > $maxDecoded) throw new \RuntimeException('Decoded archive too large.');
            $lines = explode("\n", $line . $output);
            $line = array_pop($lines);
            foreach ($lines as $raw) {
                if (strlen($raw) > 65536 || ++$rows > $maxRows) throw new \RuntimeException('Archive row limit exceeded.');
                if (trim($raw) === '') continue;
                $row = self::json($raw);
                if (!is_array($row)) throw new \RuntimeException('Invalid trade row.');
                yield $row;
            }
            if (strlen($line) > 65536) throw new \RuntimeException('Archive line too long.');
            $ended = inflate_get_status($context) === ZLIB_STREAM_END;
        }
        if (!$ended) throw new \RuntimeException('Truncated gzip archive.');
        if (trim($line) !== '') {
            if (++$rows > $maxRows) throw new \RuntimeException('Archive row limit exceeded.');
            yield self::json($line);
        }
    }

    private static function xetraRecord(array &$state, array $instrument, array $row, int $now): void
    {
        if (($row['instrumentIdentificationCode'] ?? null) !== $instrument['isin']) return;
        $config = $instrument['providerConfig'];
        if (($row['venueOfExecution'] ?? null) !== $config['venue']) return;
        $time = self::instant($row['tradingDateAndTime'] ?? null);
        $published = self::instant($row['publicationDateAndTime'] ?? null);
        if ($time === null || $published === null || $time > $now || $published > $now
            || strcmp(self::timeKey($row['publicationDateAndTime']), self::timeKey($row['tradingDateAndTime'])) < 0) throw new \RuntimeException('Invalid trade time.');
        $date = (new \DateTimeImmutable('@' . $time))->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('Y-m-d');
        $id = $instrument['id'];
        // An excluded normal trade must not create an empty newest session that
        // would hide the preceding eligible close. Amendments/cancellations still
        // reach session invalidation even when their other flags are ineligible.
        if (($row['mmtModificationInd'] ?? null) === '-'
            && (!in_array($row['mmtTradingMode'] ?? null, ['2', 'O', 'I', 'K'], true)
                || ($row['tradingSystem'] ?? null) !== '1' || ($row['mmtBenchmarkRefprcInd'] ?? null) !== '-'
                || ($row['mmtPubModeDefReason'] ?? null) !== '-')) return;
        $session =& $state['sessions'][$id][$date];
        if (!is_array($session)) $session = ['trades' => [], 'invalid' => false];
        if (($row['priceCurrency'] ?? null) !== $instrument['currency']
            || ($row['venueOfPublication'] ?? null) !== $config['publicationVenue']
            || (string) ($row['priceNotation'] ?? '') !== '1') { $session['invalid'] = true; return; }
        // A/C linkage is not documented by this public provider. Invalidate the
        // affected session rather than dropping the message or guessing a target.
        if (($row['mmtModificationInd'] ?? null) !== '-') { $session['invalid'] = true; return; }
        $price = self::price($row['price'] ?? null);
        $transaction = $row['transactionIdentificationCode'] ?? null;
        if ($price === null || !is_string($transaction) || !preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $transaction)) throw new \RuntimeException('Invalid eligible trade.');
        $trade = ['price' => $price, 'time' => $row['tradingDateAndTime'], 'published' => $row['publicationDateAndTime'], 'mode' => $row['mmtTradingMode']];
        self::unpackTransactionEvidence($session);
        $fingerprint=hash('sha256',json_encode($trade,JSON_THROW_ON_ERROR));
        if(isset($session['_transactionFingerprints'][$transaction])&&$session['_transactionFingerprints'][$transaction]!==$fingerprint){$session['invalid']=true;return;}
        if(($session['capacityExceeded']??false)===true)return;
        // Capacity failure belongs to this listing, never to an entire exchange
        // archive. Continue scanning all rows so explicit corrections still veto.
        if(!isset($session['_transactionFingerprints'][$transaction])&&count($session['_transactionFingerprints'])>=self::SESSION_LIMIT){$session['capacityExceeded']=true;return;}
        $session['_transactionFingerprints'][$transaction]=$fingerprint;
        $latest=$session['trades']?reset($session['trades']):null;
        if($latest===null||self::timeKey($trade['time'])>self::timeKey($latest['time']))$session['trades']=[$transaction=>$trade];
        elseif(self::timeKey($trade['time'])===self::timeKey($latest['time'])){
            if(!isset($session['trades'][$transaction])&&count($session['trades'])>=self::SESSION_WITNESSES)$session['capacityExceeded']=true;
            else $session['trades'][$transaction]=$trade;
        }
    }

    /** Keep exact latest-time witnesses, and compressed full transaction hashes
     * for duplicate/contradiction checks. Old verbose caches migrate on first use. */
    private static function unpackTransactionEvidence(array &$session):void
    {
        if(isset($session['_transactionFingerprints']))return;
        $fingerprints=[];
        if(isset($session['transactionFingerprints'])){
            $encoded=$session['transactionFingerprints'];
            if(!is_string($encoded)||strlen($encoded)>8388608||!is_int($session['transactionCount']??null)||$session['transactionCount']<0||$session['transactionCount']>self::SESSION_LIMIT)throw new \RuntimeException('Invalid transaction evidence.');
            $compressed=base64_decode($encoded,true);$json=$compressed===false?false:@gzinflate($compressed,self::SESSION_FINGERPRINT_BYTES);
            if($json===false)throw new \RuntimeException('Invalid transaction evidence.');
            $fingerprints=json_decode($json,true,8,JSON_THROW_ON_ERROR);
            if(!is_array($fingerprints)||count($fingerprints)!==$session['transactionCount'])throw new \RuntimeException('Incomplete transaction evidence.');
            foreach($fingerprints as $transaction=>$hash)if(!preg_match('/^[A-Za-z0-9_-]{1,128}$/D',(string)$transaction)||!is_string($hash)||!preg_match('/^[a-f0-9]{64}$/D',$hash))throw new \RuntimeException('Invalid transaction evidence.');
        }
        $latest=null;$retained=[];
        foreach($session['trades']??[] as $transaction=>$trade){
            $hash=hash('sha256',json_encode($trade,JSON_THROW_ON_ERROR));
            if(isset($fingerprints[$transaction])&&$fingerprints[$transaction]!==$hash)$session['invalid']=true;
            $fingerprints[$transaction]=$hash;$time=self::timeKey($trade['time']);
            if($latest===null||$time>$latest){$retained=[$transaction=>$trade];$latest=$time;}elseif($time===$latest)$retained[$transaction]=$trade;
        }
        if(count($fingerprints)>self::SESSION_LIMIT||count($retained)>self::SESSION_WITNESSES)$session['capacityExceeded']=true;
        $session['_transactionFingerprints']=$fingerprints;$session['trades']=$retained;
    }
    private static function packTransactionEvidence(array &$state):void
    {
        foreach($state['sessions']??[] as $id=>$_)foreach($state['sessions'][$id] as &$session){
            if(!isset($session['_transactionFingerprints'])&&!isset($session['transactionFingerprints'])&&!empty($session['trades']))self::unpackTransactionEvidence($session);
            if(!isset($session['_transactionFingerprints']))continue;
            $map=$session['_transactionFingerprints'];ksort($map,SORT_STRING);$json=json_encode($map,JSON_THROW_ON_ERROR);
            if(strlen($json)>self::SESSION_FINGERPRINT_BYTES)$session['capacityExceeded']=true;
            if(($session['capacityExceeded']??false)===true){self::discardCapacityEvidence($session);continue;}
            $packed=gzdeflate($json,6);if($packed===false)throw new \RuntimeException('Transaction evidence compression failed.');
            $session['transactionFingerprints']=base64_encode($packed);$session['transactionCount']=count($map);unset($session['_transactionFingerprints']);
        }
        unset($session);
        // Preserve each existing listing's latest pair before allocating space
        // to appended listings, even if its latest session is an older date.
        // Third-session evidence has lower priority than every latest pair.
        $pairs=$older=$stubs=[];foreach($state['sessions']??[] as $id=>$sessions){krsort($sessions);$rank=0;foreach($sessions as $date=>$session){if($rank++<2)$pairs[]=[$id,$date];else $older[]=[$id,$date];self::discardCapacityEvidence($session);$stubs[$id][$date]=$session;}}
        // Reserve every listing/date key and every capacity marker first; a
        // near-exact fit cannot overflow just by adding an unavailable marker.
        $bytes=strlen(json_encode($stubs,JSON_THROW_ON_ERROR));
        foreach(array_merge($pairs,$older) as [$id,$date]){
            $session=&$state['sessions'][$id][$date];
            $extra=strlen(json_encode($session,JSON_THROW_ON_ERROR))-strlen(json_encode($stubs[$id][$date],JSON_THROW_ON_ERROR));
            if($bytes+$extra>self::XETRA_EVIDENCE_BYTES)self::discardCapacityEvidence($session);
            else $bytes+=$extra;
            unset($session);
        }
    }
    private static function discardCapacityEvidence(array &$session):void
    {
        // Removing evidence never authorizes a quote: the permanent per-session
        // marker prevents this cache from reappearing as a complete observation.
        $session['capacityExceeded']=true;$session['trades']=[];
        unset($session['_transactionFingerprints'],$session['transactionFingerprints'],$session['transactionCount']);
    }
    /** Pure bounded representation for merging held/hot source caches. Caller
     * owns which listings remain active and must not discard their vetoes. */
    public static function compactXetraState(array $state):array
    {
        self::trimSessions($state);self::packTransactionEvidence($state);return $state;
    }

    /** Pure preparation step for a locked, backed-up private operator import. */
    public static function bootstrapReviewedPair(array $state, array $instrument, array $evidence, int $now): array
    {
        $evidence=XetraReference::validateReviewedPair($evidence,$instrument,$now);
        if (($state['catchupPending']??false) || ($state['retentionGap']??false)) throw new \RuntimeException('Historical source collection is incomplete.');
        $id=$instrument['id'];$current=$evidence['current'];$prior=$evidence['previous'];
        $sessions=$state['sessions'][$id]??[];
        $existing=self::xetraQuote($sessions,$now);
        $known=$sessions[$current['date']]['trades'][$current['trade']['transactionIdentificationCode']??'']??null;
        if ($existing['date']!==$current['date'] || !XetraReference::samePrice($existing['price'],$current['rawClose'])
            || $existing['observedAt']!==$current['trade']['tradingDateAndTime'] || $known===null || $known['mode']!=='K'
            || $known['time']!==$current['trade']['tradingDateAndTime'] || $known['published']!==$current['trade']['publicationDateAndTime']
            || !XetraReference::samePrice($known['price'],$current['rawClose'])) throw new \RuntimeException('Reviewed current auction no longer matches the cached source.');
        if (isset($state['reviewedPairs'][$id])) throw new \RuntimeException('A reviewed pair already exists.');
        // The ordinary trade validator supplies the previous source record. A
        // pre-existing correction or conflicting record remains invalid.
        self::xetraRecord($state,$instrument,$prior['trade'],$now);
        $state['reviewedPairs'][$id]=$evidence;
        $prepared=self::xetraQuote($state['sessions'][$id],$now,false,$state,$id);
        if ($prepared['date']!==$current['date'] || $prepared['previousDate']!==$prior['date']
            || !XetraReference::samePrice($prepared['price'],$current['rawClose']) || !XetraReference::samePrice($prepared['previousClose'],$prior['rawClose'])
            || $prepared['previousObservedAt']!==$prior['trade']['tradingDateAndTime']) throw new \RuntimeException('Reviewed historical pair cannot be safely prepared.');
        self::packTransactionEvidence($state);
        return $state;
    }

    public static function xetraQuote(array $sessions, int $now, bool $incomplete = false, array $referenceState = [], ?string $id = null): array
    {
        if (!$sessions) return self::missing('Xetra delayed trades', 'price_missing');
        krsort($sessions);
        $date = array_key_first($sessions);
        if ($id !== null && XetraReference::oldBasisUnsafe($referenceState, $id, $date, $now)) return self::missing('Xetra delayed trades', 'corporate_action_unverified');
        $session = $sessions[$date];
        if (($session['invalid'] ?? true) === true) return self::missing('Xetra delayed trades', 'corporate_action_unverified');
        if(($session['capacityExceeded']??false)===true)return self::missing('Xetra delayed trades','source_unavailable');
        $trades = array_values($session['trades'] ?? []);
        if (!$trades) return self::missing('Xetra delayed trades', 'price_missing');
        usort($trades, fn($a, $b) => strcmp(self::timeKey($a['time']), self::timeKey($b['time'])));
        $latest = $trades[array_key_last($trades)];
        $latestTime = self::timeKey($latest['time']);
        $atLatest = array_values(array_filter($trades, fn($trade) => self::timeKey($trade['time']) === $latestTime));
        $prices = array_unique(array_map(static fn($trade) => str_contains($trade['price'], '.')
            ? rtrim(rtrim($trade['price'], '0'), '.') : $trade['price'], $atLatest));
        // The public feed supplies no established tie-breaking sequence. Array
        // order cannot choose between different prices at the same final instant.
        if (count($prices) > 1) {
            $quote = self::missing('Xetra delayed trades', 'price_missing');
            $quote['eligibilityIssue'] = 'conflicting_latest_trade_prices';
            return $quote;
        }
        $closed = count(array_filter($atLatest, static fn($trade) => $trade['mode'] === 'K')) > 0;
        $age = $now - (self::instant($latest['time']) ?? 0);
        $expectedClose = $closed && $date === XetraReference::expectedCloseDate($now);
        $aged = !$expectedClose && $age > 5400;
        $stale = $incomplete || $aged;
        $previous = null;
        $previousDate = XetraReference::previousSession($date);
        if (!$incomplete && $id !== null && $previousDate !== null && isset($sessions[$previousDate])) {
            $candidate = self::xetraQuote([$previousDate => $sessions[$previousDate]], $now);
            $normal=XetraReference::compatible($referenceState,$id,$date,$previousDate);
            $reviewed=!$normal && $candidate['price']!==null && XetraReference::reviewedCompatible($referenceState,$id,
                ['date'=>$date,'price'=>$latest['price'],'observedAt'=>$latest['time']],$candidate,$now);
            if ($candidate['price'] !== null && ($normal || $reviewed)) {
                foreach ($sessions[$previousDate]['trades'] ?? [] as $trade) {
                    if ($trade['mode'] === 'K' && self::timeKey($trade['time']) === self::timeKey($candidate['observedAt'])) { $previous = $candidate; break; }
                }
            }
        }
        $issues = $previous === null ? ['baseline_missing', 'corporate_action_unverified'] : [];
        if ($stale) $issues[] = 'price_stale';
        $quote = ['price' => $latest['price'], 'previousClose' => $previous['price'] ?? null, 'date' => $date, 'previousDate' => $previous['date'] ?? null,
            'observedAt' => $latest['time'], 'previousObservedAt' => $previous['observedAt'] ?? null, 'status' => $stale ? 'stale' : ($closed ? 'closed' : 'delayed'),
            // Auction identity does not change when a later collection fails.
            'priceKind' => $closed ? 'close' : 'trade',
            'source' => 'Xetra delayed trades', 'fx' => '1', 'previousFx' => '1', 'issues' => $issues];
        // An unread file may contain a cancellation, so incomplete collection
        // must still block readiness. Distinguish that reason from an old price
        // without changing the dated value, stale status, or admission guard.
        if ($incomplete && !$aged) $quote['eligibilityIssue'] = 'incomplete_trade_collection';
        return $quote;
    }

    /** Diagnostic parser: absent trading mode must never become an eligible last trade. */
    public static function euronextCsv(string $csv, array $instrument): array
    {
        if (strlen($csv) > 16777216) throw new \RuntimeException('CSV too large.');
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $csv); rewind($stream);
        try {
            $header = fgetcsv($stream, 65537, ',', '"', '');
            if (!in_array('TradingDateTime', $header ?: [], true)) $header = fgetcsv($stream, 65537, ',', '"', '');
            if (!is_array($header) || count($header) !== count(array_unique($header))) throw new \RuntimeException('Invalid CSV header.');
            foreach (['TradingDateTime', 'PublicationDateTime', 'MifidInstrumentID', 'MifidPrice', 'MifidCurrency', 'Venue', 'MmtModificationIndicator'] as $field) {
                if (!in_array($field, $header, true)) throw new \RuntimeException('Missing CSV field.');
            }
            $quote = self::missing('Euronext delayed trades', 'source_unsupported');
            $quote['eligibilityIssue'] = in_array('MmtTradingMode', $header, true)
                ? 'unvalidated_trading_group_and_correction_rules' : 'trading_mode_and_session_reference_unavailable';
            return $quote;
        } finally { fclose($stream); }
    }

    private static function trimSessions(array &$state): void
    {
        foreach ($state['sessions'] ?? [] as $id => $sessions) {
            krsort($sessions);
            $state['sessions'][$id] = array_slice($sessions, 0, 3, true);
        }
    }

    private static function identity(array $instrument): bool
    {
        return is_string($instrument['isin'] ?? null) && preg_match('/^[A-Z]{2}[A-Z0-9]{9}[0-9]$/D', $instrument['isin'])
            && ($instrument['currency'] ?? null) === 'EUR';
    }

    private static function missing(string $source, string $issue): array
    {
        return ['price' => null, 'previousClose' => null, 'date' => null, 'previousDate' => null,
            'observedAt' => null, 'previousObservedAt' => null, 'status' => 'missing', 'source' => $source,
            'fx' => '1', 'previousFx' => '1', 'issues' => [$issue]];
    }

    private static function date(string $date): bool
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $m)) return false;
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private static function instant(mixed $value): ?int
    {
        if (!is_string($value) || !preg_match('/^(\d{4}-\d{2}-\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.[0-9]{1,9})?Z$/D', $value, $m)
            || !self::date($m[1]) || (int) $m[2] > 23 || (int) $m[3] > 59 || (int) $m[4] > 59) return null;
        return strtotime($m[1] . 'T' . $m[2] . ':' . $m[3] . ':' . $m[4] . 'Z');
    }

    private static function timeKey(string $value): string
    {
        $parts = explode('.', rtrim($value, 'Z'), 2);
        return $parts[0] . '.' . str_pad($parts[1] ?? '', 9, '0');
    }

    private static function price(mixed $value): ?string
    {
        if (is_int($value)) $value = (string) $value;
        if (!is_string($value) || strlen($value) > 60 || !preg_match('/^(?:0|[1-9]\d*)(?:\.\d+)?$/D', $value)
            || preg_match('/^0(?:\.0+)?$/D', $value)) return null;
        return $value;
    }

    private static function json(string $raw): array
    {
        if (class_exists(MarketJson::class)) {
            $decoded = MarketJson::decode($raw);
        } else {
            // Keep numeric lexemes exact; normal decoding first validates JSON
            // syntax, so the replacement cannot make malformed input acceptable.
            json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            $quoted = preg_replace_callback('/"(?:[^"\\\\]|\\\\.)*"(*SKIP)(*F)|-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?/', fn($m) => '"' . $m[0] . '"', $raw);
            $decoded = json_decode($quoted, true, 64, JSON_THROW_ON_ERROR);
        }
        if (!is_array($decoded)) throw new \RuntimeException('Expected JSON object.');
        return $decoded;
    }
}
