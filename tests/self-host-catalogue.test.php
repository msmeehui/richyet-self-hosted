<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('zend.exception_ignore_args', '1');
require dirname(__DIR__) . '/self-host/bootstrap-lib.php';
require_once dirname(__DIR__) . '/server/USProviders.php';

use RichYetSelfHost\Bootstrap;
use RichYet\Backend;
use RichYet\CatalogueStore;
use RichYet\InstrumentDirectory;
use RichYet\Maintenance;
use RichYet\MarketCalendars;
use RichYet\MarketStore;
use RichYet\ReportingStore;
use RichYet\USProviders;

$checks = 0;
function catalogueCheck(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL ' . $message);
    $checks++;
}
function catalogueRejects(callable $operation, string $message): void {
    try { $operation(); } catch (Throwable) { catalogueCheck(true, $message); return; }
    throw new RuntimeException('FAIL ' . $message);
}
function catalogueDatabase(): PDO {
    $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA foreign_keys = ON');
    Bootstrap::initializeSchema($db, file_get_contents(__DIR__ . '/backend-schema.sqlite.sql'));
    return $db;
}

$now = strtotime('2026-10-21T16:00:00Z');
$definition = json_decode(file_get_contents(dirname(__DIR__) . '/self-host/starter-catalogue.json'), true, 32, JSON_THROW_ON_ERROR);
$registry = MarketStore::validateRegistry($definition['instruments']);
$expected = ['AAPL' => ['XNAS', 'USD', 'Stock'], 'MSFT' => ['XNAS', 'USD', 'Stock'],
    'AMZN' => ['XNAS', 'USD', 'Stock'], 'GOOGL' => ['XNAS', 'USD', 'Stock'], 'META' => ['XNAS', 'USD', 'Stock'],
    'AMD' => ['XNAS', 'USD', 'Stock'], 'QQQ' => ['XNAS', 'USD', 'ETF'], 'SPY' => ['ARCX', 'USD', 'ETF'],
    'ASML' => ['XAMS', 'EUR', 'Stock'], 'INGA' => ['XAMS', 'EUR', 'Stock'], 'IWDA' => ['XAMS', 'EUR', 'ETF']];
catalogueCheck(count($registry) === count($expected), 'Reviewed starter set has exactly eleven listing definitions');
$ids = array_column($registry, 'id');
catalogueCheck(count(array_unique($ids)) === count($registry), 'Listings have independent stable IDs');
catalogueCheck(array_diff(array_keys($definition['policies']), $ids) === [] && count($definition['policies']) === count($registry), 'Every listing has exactly one access policy');
foreach ($registry as $instrument) {
    catalogueCheck(($expected[$instrument['symbol']] ?? null) === [$instrument['mic'], $instrument['currency'], $instrument['type']], 'Exact reviewed venue, currency and security type survive packaging');
    $policy = $definition['policies'][$instrument['id']];
    catalogueCheck($policy['priceAccess'] === 'owner' && $policy['fxAccess'] === 'owner', 'Starter policy never grants another account access');
    catalogueCheck((MarketCalendars::day($instrument['mic'], '2026-10-21')['isSession'] ?? null) === true, 'Included venue has an actual supported calendar');
}
$bySymbol = array_column($registry, null, 'symbol');
catalogueCheck($bySymbol['ASML']['isin'] === 'NL0010273215' && $bySymbol['INGA']['isin'] === 'NL0011821202' && $bySymbol['IWDA']['isin'] === 'IE00B4L5Y983', 'Amsterdam listings retain exact reviewed ISINs');
catalogueCheck($bySymbol['IWDA']['currency'] === 'EUR' && str_contains($bySymbol['IWDA']['name'], 'USD'), 'Fund-name currency does not replace Amsterdam trading currency');
catalogueCheck($bySymbol['IWDA']['providerConfig']['tradingSchedule'] === 'ams_eur_etf_continuous_2026', 'Amsterdam ETF uses its ETF route, not the stock route');
$wrong = $bySymbol['IWDA']; $wrong['providerConfig']['tradingSchedule'] = 'ams_j0_continuous_2026';
catalogueRejects(fn() => MarketStore::validateRegistry([$wrong]), 'A stock schedule cannot be assigned to the Amsterdam ETF');

$db = catalogueDatabase();
(new Backend($db, str_repeat('c', 64), $now))->invite('owner@example.test', true);
$ownerId = (int) $db->query('SELECT id FROM users')->fetchColumn();
$reporting = new ReportingStore($db); $reporting->setCurrency($ownerId, 'GBP', $now); $reporting->setNumberFormat($ownerId, 'en-GB', $now);
$before = Maintenance::backup($db);
$result = Bootstrap::catalogue($db, 'owner@example.test', $definition, $now);
catalogueCheck($result['searchableListings'] === 11 && $result['providerRequests'] === 0, 'Full starter set installs without provider transport');
$after = Maintenance::backup($db);
foreach (['users', 'portfolios', 'reportingSettings', 'reportingState', 'usage'] as $field) {
    catalogueCheck(($before[$field] ?? null) === ($after[$field] ?? null), 'Catalogue install preserves ' . $field);
}
$directory = new InstrumentDirectory($db, 'owner@example.test', $now);
$catalogue = (new CatalogueStore($db, 'owner@example.test'))->account($ownerId);
$snapshot = json_decode($catalogue['snapshot_json'], true, 64, JSON_THROW_ON_ERROR);
foreach ($registry as $instrument) {
    $shortName = implode(' ', array_slice(explode(' ', $instrument['name']), 0, 3));
    foreach (array_unique([$instrument['symbol'], $shortName, $instrument['isin'] ?? $instrument['symbol']]) as $query) {
        $found = $directory->search($ownerId, $query)['instruments'];
        catalogueCheck(count($found) === 1 && $found[0]['id'] === $instrument['id'], 'Normal search resolves exact starter ticker, short name or ISIN');
        catalogueCheck($found[0]['canAdd'] === false && $found[0]['canPrepare'] === true, 'Search result still needs actual preparation before adding');
    }
    $quote = $snapshot['quotes'][$instrument['id']];
    catalogueCheck($quote['price'] === null && $quote['previousClose'] === null, 'Catalogue contains neither invented price nor comparison');
}
catalogueCheck($db->query('SELECT holdings_json FROM portfolios')->fetchColumn() === '[]', 'Catalogue is never a portfolio import');
catalogueCheck((int) $db->query('SELECT COUNT(*) FROM market_usage')->fetchColumn() === 0, 'Offline installation and search consume no provider allowance');
catalogueRejects(fn() => $directory->search($ownerId + 1, 'AAPL'), 'Another account cannot search the owner catalogue');

// Exercise the real US adapter's identity boundary with synthetic responses.
// Every non-identity URL throws: this does not contact any provider or assert
// that a newly registered provider account has usable market-data access.
$us = array_values(array_filter($registry, static fn($i) => $i['provider'] === 'alpaca'));
$assets = [];
foreach ($us as $index => $instrument) $assets[$instrument['symbol']] = ['id' => sprintf('00000000-0000-4000-8000-%012d', $index + 1),
    'symbol' => $instrument['symbol'], 'class' => 'us_equity', 'status' => 'active', 'exchange' => $instrument['mic'] === 'ARCX' ? 'ARCA' : 'NASDAQ'];
$stubCalls = 0;
$stub = static function(string $url) use (&$stubCalls, $assets): string {
    $stubCalls++;
    if (str_starts_with($url, 'https://paper-api.alpaca.markets/v2/assets/')) return json_encode($assets[basename(parse_url($url, PHP_URL_PATH))], JSON_THROW_ON_ERROR);
    throw new RuntimeException('Offline test intentionally supplies no market data.');
};
$accepted = USProviders::refresh($us, [], $stub, $now);
catalogueCheck(count($accepted['state']['identities'] ?? []) === 8, 'Existing adapter accepts all eight exact US venue mappings, including NYSE Arca');
catalogueCheck($accepted['quotes'] === [], 'Valid identity alone never fabricates a quote');
foreach ($us as $instrument) catalogueCheck($accepted['state']['identities'][$instrument['id']]['mic'] === $instrument['mic'], 'Verified asset identity remains bound to the selected MIC');
$wrongVenue = static function(string $url) use ($assets): string {
    if (!str_starts_with($url, 'https://paper-api.alpaca.markets/v2/assets/')) throw new RuntimeException('Unexpected offline request.');
    $asset = $assets[basename(parse_url($url, PHP_URL_PATH))]; $asset['exchange'] = 'NYSE';
    return json_encode($asset, JSON_THROW_ON_ERROR);
};
$rejected = USProviders::refresh($us, [], $wrongVenue, $now);
catalogueCheck(count($rejected['issues']) === 8 && !isset($rejected['state']['identities']), 'Same tickers on the wrong exchange are all rejected');
foreach ($rejected['quotes'] as $quote) catalogueCheck($quote['price'] === null && $quote['previousClose'] === null, 'Wrong-exchange response cannot become either displayed comparison');

// The documented extension path is idempotent for the same definitions and
// rejects attempts to retarget an existing ID to a different listing.
$entries = array_map(static fn($i) => ['instrument' => $i, 'policy' => $definition['policies'][$i['id']]], $registry);
$beforeImport = Maintenance::backup($db);
$directory->import('personal', $entries, $now + 60);
catalogueCheck(array_diff_key(Maintenance::backup($db), ['createdAt' => true]) === array_diff_key($beforeImport, ['createdAt' => true]), 'Repeated directory import leaves installed data byte-equivalent');
$changed = $entries[0]; $changed['instrument']['mic'] = 'XNYS';
catalogueRejects(fn() => $directory->import('personal', [$changed], $now + 60), 'Existing listing ID cannot be silently redirected');
catalogueCheck(array_diff_key(Maintenance::backup($db), ['createdAt' => true]) === array_diff_key($beforeImport, ['createdAt' => true]), 'Rejected listing edit preserves all saved records');
$restored = catalogueDatabase(); Maintenance::restore($restored, $beforeImport);
$restoredDirectory = new InstrumentDirectory($restored, 'owner@example.test', $now);
catalogueCheck(count($restoredDirectory->search($ownerId)['instruments']) === 11, 'Backup restore retains the full searchable starter set');
catalogueCheck($restoredDirectory->search($ownerId, 'IE00B4L5Y983')['instruments'][0]['id'] === 'starter_iwda_xams', 'Restored ISIN search keeps the Amsterdam ETF identity');
catalogueCheck($restored->query('SELECT holdings_json FROM portfolios')->fetchColumn() === '[]', 'Restore does not convert metadata into holdings');

echo json_encode(['checks' => $checks, 'result' => 'passed', 'providerRequests' => 0, 'scope' => 'offline starter metadata, setup, search, identity guards and restore'], JSON_THROW_ON_ERROR) . "\n";
