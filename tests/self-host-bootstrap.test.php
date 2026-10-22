<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('zend.exception_ignore_args', '1');
require dirname(__DIR__) . '/self-host/bootstrap-lib.php';
require_once dirname(__DIR__) . '/server/InstrumentPreparation.php';

use RichYetSelfHost\Bootstrap;
use RichYet\Backend;
use RichYet\MarketStore;
use RichYet\Maintenance;
use RichYet\ReportingStore;

$checks = 0;
function check(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL ' . $message);
    $checks++;
}
function rejects(callable $operation, string $message): void {
    try { $operation(); } catch (Throwable) { check(true, $message); return; }
    throw new RuntimeException('FAIL ' . $message);
}
function emptyDatabase(): PDO {
    $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA foreign_keys = ON');
    return $db;
}
function fixture(): PDO {
    $db = emptyDatabase();
    Bootstrap::initializeSchema($db, file_get_contents(__DIR__ . '/backend-schema.sqlite.sql'));
    return $db;
}
function invite(PDO $db, string $email = 'owner@example.test'): string {
    return (new Backend($db, str_repeat('f', 64), 1792576800))->invite($email, true);
}
function definition(): array {
    $instrument = ['id' => 'selfhost_fictional', 'name' => 'Fictional Setup Company', 'symbol' => 'FICTION', 'exchange' => 'Nasdaq',
        'mic' => 'XNAS', 'currency' => 'USD', 'type' => 'Stock', 'coverage' => ['price' => true, 'comparison' => true],
        'provider' => 'alpaca', 'providerConfig' => ['symbol' => 'FICTION', 'identityConfirmed' => true]];
    return ['schemaVersion' => 1, 'id' => 'personal_test', 'audience' => 'owner', 'instruments' => [$instrument],
        'policies' => [$instrument['id'] => ['priceAccess' => 'owner', 'fxAccess' => 'owner', 'priceHistory' => true, 'fxHistory' => true]]];
}
function records(PDO $db): array {
    $result = [];
    foreach (Bootstrap::TABLES as $table) $result[$table] = $db->query('SELECT * FROM ' . $table)->fetchAll(PDO::FETCH_ASSOC);
    return $result;
}
function request(Backend $app, string $action, string $method, array $body = [], ?array $session = null): array {
    return $app->handle($action, $method, json_decode(json_encode((object) $body, JSON_THROW_ON_ERROR)), $session['cookie'] ?? null, $session['body']['csrfToken'] ?? '', '127.0.0.1');
}

$temporary = sys_get_temp_dir() . '/richyet-selfhost-' . bin2hex(random_bytes(12));
if (!mkdir($temporary, 0700)) throw new RuntimeException('Private test directory unavailable.');
try {
    $db = emptyDatabase();
    check(Bootstrap::check($db)['database'] === 'empty' && Bootstrap::tables($db) === [], 'Read-only check leaves a fresh database empty');
    rejects(fn() => Bootstrap::initialize($db), 'Production initializer rejects SQLite');
    check(Bootstrap::tables($db) === [], 'Rejected production initialization makes no writes');
    $result = Bootstrap::initializeSchema($db, file_get_contents(__DIR__ . '/backend-schema.sqlite.sql'));
    check($result['tables'] === 13 && count(Bootstrap::tables($db)) === 13, 'Fresh initialization includes all thirteen tables');
    check(Bootstrap::check($db)['database'] === 'initialized' && Bootstrap::check($db)['writes'] === 0, 'Read-only check identifies initialized schema');
    check(array_sum(array_map('count', records($db))) === 0, 'Initialization creates no accounts, holdings or source state');
    $before = records($db);
    rejects(fn() => Bootstrap::initializeSchema($db, file_get_contents(__DIR__ . '/backend-schema.sqlite.sql')), 'Reinitialization refuses even an empty existing app schema');
    check(records($db) === $before, 'Refused reinitialization preserves all tables');
    $foreign = emptyDatabase(); $foreign->exec('CREATE TABLE unrelated_app (value TEXT)'); $foreign->exec("INSERT INTO unrelated_app VALUES ('untouched')");
    rejects(fn() => Bootstrap::initializeSchema($foreign, file_get_contents(__DIR__ . '/backend-schema.sqlite.sql')), 'Unrelated application database is not an install target');
    check(Bootstrap::tables($foreign) === ['unrelated_app'] && $foreign->query('SELECT value FROM unrelated_app')->fetchColumn() === 'untouched', 'Unrelated application data remains untouched');
    $partial = emptyDatabase(); $partial->exec('CREATE TABLE users (id INTEGER)');
    rejects(fn() => Bootstrap::initializeSchema($partial, file_get_contents(__DIR__ . '/backend-schema.sqlite.sql')), 'Partial initialization refuses automatic repair');
    check(Bootstrap::check($partial)['database'] === 'other_or_partial_schema', 'Read-only check distinguishes a partial schema');
    rejects(fn() => Bootstrap::catalogue($db, 'owner@example.test', definition(), 1792576800), 'Catalogue requires the existing configured owner');
    check(records($db) === $before, 'Missing-owner rejection makes no writes');

    $token = invite($db);
    $ownerId = (int) $db->query('SELECT id FROM users')->fetchColumn();
    $reporting = new ReportingStore($db);
    $reporting->setCurrency($ownerId, 'GBP', 1792576800);
    $reporting->setNumberFormat($ownerId, 'en-GB', 1792576800);
    $before = records($db);
    rejects(fn() => Bootstrap::catalogue($db, 'different@example.test', definition(), 1792576800), 'A different configured owner cannot adopt the account');
    check(records($db) === $before, 'Wrong-owner rejection preserves private account settings');
    $bad = definition(); $bad['audience'] = 'invited';
    rejects(fn() => Bootstrap::catalogue($db, 'owner@example.test', $bad, 1792576800), 'Shared audience is outside personal setup');
    $bad = definition(); $bad['instruments'] = []; $bad['policies'] = [];
    rejects(fn() => Bootstrap::catalogue($db, 'owner@example.test', $bad, 1792576800), 'Empty registry cannot masquerade as usable search');
    $bad = definition(); $bad['policies']['selfhost_fictional']['fxAccess'] = 'unverified';
    rejects(fn() => Bootstrap::catalogue($db, 'owner@example.test', $bad, 1792576800), 'Unreviewed foreign-currency access cannot be silently enabled');
    $bad = definition(); $bad['holdings'] = [['quantity' => '999']];
    rejects(fn() => Bootstrap::catalogue($db, 'owner@example.test', $bad, 1792576800), 'Catalogue input cannot import portfolio amounts');
    check(records($db) === $before, 'Rejected catalogue definitions leave every table unchanged');

    $db->exec("CREATE TRIGGER setup_failure BEFORE INSERT ON market_catalogue_accounts BEGIN SELECT RAISE(ABORT, 'fictional failure'); END");
    rejects(fn() => Bootstrap::catalogue($db, 'owner@example.test', definition(), 1792576800), 'Final attachment failure is reported');
    check(records($db) === $before && !$db->inTransaction(), 'Catalogue and directory writes roll back together after attachment failure');
    $db->exec('DROP TRIGGER setup_failure');
    $result = Bootstrap::catalogue($db, 'owner@example.test', definition(), 1792576800);
    check($result['searchableListings'] === 1 && $result['holdingsAdded'] === 0 && $result['providerRequests'] === 0, 'One cold listing is installed without holdings or provider requests');
    $after = records($db);
    foreach (['users', 'portfolios', 'sessions', 'password_tokens', 'rate_limits', 'market_usage', 'reporting_settings', 'reporting_state'] as $table) {
        check($before[$table] === $after[$table], 'Personal setup preserves ' . $table);
    }
    $store = new MarketStore($db, 'owner@example.test', 1792576800);
    check($store->mode($ownerId) === 'live' && count($store->registry($ownerId)) === 1, 'Owner enters live mode through authenticated catalogue membership');
    $snapshot = json_decode($after['market_catalogues'][0]['snapshot_json'], true, 64, JSON_THROW_ON_ERROR);
    check($snapshot['quotes']['selfhost_fictional']['price'] === null && $snapshot['quotes']['selfhost_fictional']['previousClose'] === null, 'Cold prices and comparisons remain missing, never invented or zero');
    check(count($after['market_directory']) === 1 && count($after['market_directory_tokens']) > 0, 'Listing is indexed for actual search');
    $backend = new Backend($db, str_repeat('f', 64), 1792576800, $store);
    $anonymous = request($backend, 'session', 'GET');
    $signed = request($backend, 'password', 'POST', ['token' => $token, 'password' => 'Fictional selfhost password!'], $anonymous);
    check($signed['body']['authenticated'] && $signed['body']['dataMode'] === 'live', 'Normal password setup opens the fresh live account');
    $portfolio = request($backend, 'portfolio', 'GET', [], $signed);
    check($portfolio['body']['holdings'] === [] && $portfolio['body']['revision'] === 0, 'Authenticated owner still has an empty, unmodified portfolio');
    $search = $backend->handle('search', 'GET', (object) [], $signed['cookie'], '', '127.0.0.1', ['query' => 'FICTION', 'offset' => '0']);
    check(count($search['body']['instruments']) === 1 && $search['body']['instruments'][0]['id'] === 'selfhost_fictional', 'Normal authenticated search finds the installed listing');
    $beforeRepeat = records($db);
    rejects(fn() => Bootstrap::catalogue($db, 'owner@example.test', definition(), 1792576801), 'Installed catalogue cannot be replaced by rerunning setup');
    check(records($db) === $beforeRepeat, 'Repeat setup preserves all saved records');

    $privateDefinition = $temporary . '/catalogue.json';
    Maintenance::writePrivate($privateDefinition, json_encode(definition(), JSON_THROW_ON_ERROR));
    check(Bootstrap::readDefinition($privateDefinition) === definition(), 'Owner-private catalogue file round-trips exactly');
    chmod($privateDefinition, 0644); clearstatcache(true, $privateDefinition);
    rejects(fn() => Bootstrap::readDefinition($privateDefinition), 'World-readable catalogue input is rejected');
    chmod($privateDefinition, 0600); clearstatcache(true, $privateDefinition);
    $link = $temporary . '/linked.json'; symlink($privateDefinition, $link);
    rejects(fn() => Bootstrap::readDefinition($link), 'Symlink catalogue input is rejected');
    unlink($link);
    $backupPath = $temporary . '/backup.json';
    $backup = Maintenance::backup($db); Maintenance::writePrivate($backupPath, json_encode($backup, JSON_THROW_ON_ERROR));
    $restored = fixture();
    Maintenance::restore($restored, json_decode(file_get_contents($backupPath), true, 64, JSON_THROW_ON_ERROR));
    $restoredBackup = Maintenance::backup($restored);
    foreach (['users', 'portfolios', 'markets', 'catalogues', 'memberships', 'directory', 'reportingSettings', 'reportingState'] as $field) {
        check($restoredBackup[$field] === $backup[$field], 'Saved-file restore preserves ' . $field);
    }
    check((new ReportingStore($restored))->preferences($ownerId) === ['currency' => 'GBP', 'numberFormat' => 'en-GB'], 'Restored currency and number-format preferences work');
    check((int) $restored->query('SELECT COUNT(*) FROM sessions')->fetchColumn() === 0 && (int) $restored->query('SELECT COUNT(*) FROM password_tokens')->fetchColumn() === 0, 'Restore excludes bearer sessions and password links');

    foreach (['other_owner', 'nonempty', 'edited_empty', 'legacy'] as $scenario) {
        $guarded = fixture(); invite($guarded);
        if ($scenario === 'other_owner') invite($guarded, 'other@example.test');
        elseif ($scenario === 'nonempty') $guarded->exec("UPDATE portfolios SET holdings_json = '[{\"id\":\"example\"}]'");
        elseif ($scenario === 'edited_empty') $guarded->exec('UPDATE portfolios SET revision = 1');
        else $guarded->exec("INSERT INTO market_accounts VALUES (1, '[]', '{}', '{}', 1)");
        $prior = records($guarded);
        rejects(fn() => Bootstrap::catalogue($guarded, 'owner@example.test', definition(), 1792576800), 'Setup refuses ' . $scenario);
        check(records($guarded) === $prior, 'Setup preserves rejected ' . $scenario . ' installation');
    }

    // Exercise the documented metadata through setup without contacting any
    // actual provider. Searchability is not a live data/entitlement assertion.
    $starter = json_decode(file_get_contents(dirname(__DIR__) . '/self-host/starter-catalogue.json'), true, 32, JSON_THROW_ON_ERROR);
    $starterDb = fixture(); invite($starterDb);
    $starterResult = Bootstrap::catalogue($starterDb, 'owner@example.test', $starter, 1792576800);
    check($starterResult['searchableListings'] === 11 && (int) $starterDb->query('SELECT COUNT(*) FROM market_directory')->fetchColumn() === 11, 'Documented starter catalogue installs its eleven searchable definitions');
    check($starterDb->query('SELECT holdings_json FROM portfolios')->fetchColumn() === '[]' && (int) $starterDb->query('SELECT COUNT(*) FROM market_usage')->fetchColumn() === 0, 'Starter catalogue imports neither holdings nor provider requests');

    // The complete new-installation route, using only an injected fictional
    // NAV service: prepare -> authenticated save -> reopen -> scheduled update
    // -> reopen -> saved-file restore. No HTTP transport is instantiated.
    $sourcePath = $temporary . '/source.sqlite';
    $sourceDb = new PDO('sqlite:' . $sourcePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $sourceDb->exec('PRAGMA foreign_keys = ON');
    Bootstrap::initializeSchema($sourceDb, file_get_contents(__DIR__ . '/backend-schema.sqlite.sql'));
    $sourceToken = invite($sourceDb);
    $fund = ['id' => 'fictional_daily_fund', 'name' => 'Fictional Daily Fund', 'symbol' => 'FUND', 'exchange' => 'Fictional issuer',
        'mic' => null, 'currency' => 'EUR', 'type' => 'Fund', 'isin' => 'ZZ0000000001', 'coverage' => ['price' => true, 'comparison' => true],
        'provider' => 'bnd', 'providerConfig' => ['fundId' => 999, 'identityConfirmed' => true]];
    $sourceDefinition = ['schemaVersion' => 1, 'id' => 'source_test', 'audience' => 'owner', 'instruments' => [$fund],
        'policies' => [$fund['id'] => ['priceAccess' => 'owner', 'fxAccess' => 'owner', 'priceHistory' => true, 'fxHistory' => true]]];
    $firstAt = strtotime('2026-10-21T10:00:00Z'); $secondAt = strtotime('2026-10-22T10:00:00Z');
    Bootstrap::catalogue($sourceDb, 'owner@example.test', $sourceDefinition, $firstAt);
    $phase = 1; $sourceCalls = 0;
    $source = static function(string $url, int $maxBytes = 8388608) use (&$phase, &$sourceCalls): string {
        $sourceCalls++;
        if (str_ends_with($url, '/v1/fund/999/factsheetdata')) return '{"fundId":999,"basicCharacteristics":{"isin":"ZZ0000000001"}}';
        if (str_ends_with($url, '/v1/fundrates?id=999')) return json_encode(['fundId' => 999, 'rates' => $phase === 1
            ? [['date' => '2026-10-19', 'nav' => '12'], ['date' => '2026-10-20', 'nav' => '12.50']]
            : [['date' => '2026-10-20', 'nav' => '12.50'], ['date' => '2026-10-21', 'nav' => '12.75']]], JSON_THROW_ON_ERROR);
        throw new RuntimeException('Unexpected fixture request. Network access is not implemented.');
    };
    $sourceStore = new MarketStore($sourceDb, 'owner@example.test', $firstAt);
    $preparer = new \RichYet\InstrumentPreparation($sourceDb, 'owner@example.test', $source, $firstAt);
    $sourceApp = new Backend($sourceDb, str_repeat('f', 64), $firstAt, $sourceStore, fn(int $owner, string $id): array => $preparer->prepare($owner, $id));
    $sourceAnonymous = request($sourceApp, 'session', 'GET');
    $sourceSession = request($sourceApp, 'password', 'POST', ['token' => $sourceToken, 'password' => 'Fictional selfhost password!'], $sourceAnonymous);
    $prepared = request($sourceApp, 'prepare', 'POST', ['instrumentId' => $fund['id']], $sourceSession);
    check($prepared['body']['status'] === 'ready' && $sourceCalls === 2, 'New installation prepares a genuine complete fictional source pair');
    $holding = ['id' => 'fictional_position', 'instrumentId' => $fund['id'], 'quantity' => '2.123456', 'averageCost' => '9.123456', 'eurCost' => null];
    $saved = request($sourceApp, 'portfolio', 'PUT', ['revision' => 0, 'holdings' => [$holding]], $sourceSession);
    check($saved['body']['revision'] === 1 && json_decode(json_encode($saved['body']['holdings']), true) === [$holding], 'Normal authenticated save preserves exact units and purchase cost');
    $firstQuote = json_decode($sourceStore->catalogues()->row('source_test')['snapshot_json'], true, 64, JSON_THROW_ON_ERROR)['quotes'][$fund['id']];
    check($firstQuote['price'] === '12.50' && $firstQuote['previousClose'] === '12' && $firstQuote['date'] === '2026-10-20' && $firstQuote['previousDate'] === '2026-10-19', 'First comparison uses its actual source valuation pair');
    unset($sourceApp, $preparer, $sourceStore, $sourceDb);
    $sourceDb = new PDO('sqlite:' . $sourcePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $sourceDb->exec('PRAGMA foreign_keys = ON');
    $sourceStore = new MarketStore($sourceDb, 'owner@example.test', $secondAt);
    $persisted = json_decode($sourceStore->catalogues()->row('source_test')['snapshot_json'], true, 64, JSON_THROW_ON_ERROR)['quotes'][$fund['id']];
    check($persisted === $firstQuote, 'Closing and reopening the database preserves the first source pair');
    $sourcePortfolio = $sourceDb->query('SELECT * FROM portfolios')->fetchAll(PDO::FETCH_ASSOC);
    $phase = 2;
    \RichYet\MarketRefresh::run($sourceStore, 1, $source, $secondAt, static fn(): string => throw new RuntimeException('Offline reporting-rate fixture unavailable.'));
    $secondQuote = json_decode($sourceStore->catalogues()->row('source_test')['snapshot_json'], true, 64, JSON_THROW_ON_ERROR)['quotes'][$fund['id']];
    check($sourceCalls === 4 && $secondQuote['price'] === '12.75' && $secondQuote['previousClose'] === '12.50' && $secondQuote['date'] === '2026-10-21' && $secondQuote['previousDate'] === '2026-10-20', 'Ordinary collection advances both price and exact preceding valuation date');
    check($secondQuote['fx'] === '1' && $secondQuote['previousFx'] === '1' && $secondQuote['basis'] === 'same_units', 'Both comparison conversion inputs and unit basis remain complete');
    check($sourceDb->query('SELECT * FROM portfolios')->fetchAll(PDO::FETCH_ASSOC) === $sourcePortfolio, 'Automatic source advancement preserves units, cost, order and portfolio revision');
    unset($sourceStore, $sourceDb);
    $sourceDb = new PDO('sqlite:' . $sourcePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $savedQuote = json_decode((new MarketStore($sourceDb, 'owner@example.test', $secondAt))->catalogues()->row('source_test')['snapshot_json'], true, 64, JSON_THROW_ON_ERROR)['quotes'][$fund['id']];
    check($savedQuote === $secondQuote, 'The second successful source update is durable across reopening');
    $sourceBackupPath = $temporary . '/source-backup.json';
    Maintenance::writePrivate($sourceBackupPath, json_encode(Maintenance::backup($sourceDb), JSON_THROW_ON_ERROR));
    $sourceRestored = fixture(); Maintenance::restore($sourceRestored, json_decode(file_get_contents($sourceBackupPath), true, 64, JSON_THROW_ON_ERROR));
    $restoredQuote = json_decode((new MarketStore($sourceRestored, 'owner@example.test', $secondAt))->catalogues()->row('source_test')['snapshot_json'], true, 64, JSON_THROW_ON_ERROR)['quotes'][$fund['id']];
    check($restoredQuote === $secondQuote && $sourceRestored->query('SELECT * FROM portfolios')->fetchAll(PDO::FETCH_ASSOC) === $sourcePortfolio, 'Saved-file recovery preserves the second pair and all Since purchase inputs');
    unset($sourceDb);
    echo $checks . " self-host bootstrap checks passed; offline fictional data only.\n";
} finally {
    foreach (glob($temporary . '/*') ?: [] as $file) unlink($file);
    rmdir($temporary);
}
