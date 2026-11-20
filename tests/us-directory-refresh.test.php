<?php
declare(strict_types=1);
require dirname(__DIR__).'/self-host/bootstrap-lib.php';
require_once dirname(__DIR__).'/server/DirectorySources.php';
require_once dirname(__DIR__).'/server/USProviders.php';

use RichYet\Backend;
use RichYet\DirectorySources;
use RichYet\InstrumentDirectory;
use RichYet\Maintenance;
use RichYet\USProviders;
use RichYetSelfHost\Bootstrap;

$checks = 0;
function directoryCheck(bool $passed, string $description): void
{
    global $checks;
    if (!$passed) throw new RuntimeException($description);
    $checks++;
}

$now = strtotime('2026-11-20T08:00:00Z');
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec('PRAGMA foreign_keys = ON');
$schema = file_get_contents(__DIR__.'/backend-schema.sqlite.sql');
Bootstrap::initializeSchema($db, $schema);
(new Backend($db, str_repeat('t', 64), $now))->invite('owner@example.test', true);
$definition = json_decode(file_get_contents(dirname(__DIR__).'/self-host/starter-catalogue.json'), true, 32, JSON_THROW_ON_ERROR);
Bootstrap::catalogue($db, 'owner@example.test', $definition, $now);
$owner = (int)$db->query('SELECT id FROM users')->fetchColumn();
$directory = new InstrumentDirectory($db, 'owner@example.test');
$before = Maintenance::backup($db);

$assets = [];
foreach ([['AAPL','NASDAQ'],['MSFT','NYSE'],['FCO','NASDAQ'],['CLASH','NASDAQ'],['CLASH','NYSE'],['FCO-P','NASDAQ'],['NOUUID','NASDAQ']] as $index=>[$symbol,$exchange]) {
    $assets[] = ['id'=>$symbol==='NOUUID'?null:sprintf('00000000-0000-4000-8000-%012d',$index+1),
        'class'=>'us_equity','exchange'=>$exchange,'symbol'=>$symbol,'name'=>$symbol==='FCO'?'Fictional Components':'Updated '.$symbol,
        'status'=>'active','tradable'=>true];
}
$nasdaq = "Symbol|Security Name|Market Category|Test Issue|Financial Status|ETF|NextShares\n"
    ."AAPL|Existing Company Common Stock|Q|N|N|N|N\nFCO|Fictional Components Common Stock|Q|N|N|N|N\n"
    ."CLASH|Fictional Clash Common Stock|Q|N|N|N|N\nFCO-P|Preferred Stock|Q|N|N|N|N\n"
    ."NOUUID|Unverified Common Stock|Q|N|N|N|N\nFile Creation Time: 1120202601:00||||||\n";
$other = "ACT Symbol|Security Name|Exchange|ETF|Test Issue\nMSFT|Moved Company Common Stock|N|N|N\n"
    ."CLASH|Different Clash Common Stock|N|N|N\nFile Creation Time: 1120202601:00||||\n";
$responses = [
    'https://www.nasdaqtrader.com/dynamic/symdir/nasdaqlisted.txt'=>$nasdaq,
    'https://www.nasdaqtrader.com/dynamic/symdir/otherlisted.txt'=>$other,
    'https://paper-api.alpaca.markets/v2/assets?status=active&asset_class=us_equity'=>json_encode($assets, JSON_THROW_ON_ERROR),
];
$calls = [];
$get = static function(string $url, int $bytes) use ($responses, &$calls): string {
    $calls[$url] = $bytes;
    return $responses[$url] ?? throw new RuntimeException('Unexpected directory request');
};
$reference = DirectorySources::downloadUs($get, $now);
directoryCheck(array_keys($calls) === array_keys($responses) && max($calls) <= 16777216, 'Refresh requests exactly three bounded metadata endpoints');
directoryCheck(count($reference['instruments']) === 5, 'Class, exchange and stable asset ID checks exclude ineligible listings');
directoryCheck($directory->appendUsListings($owner,$reference['instruments'],$reference['assetIds'],$now) === 1, 'Only one new unambiguous listing is appended');
directoryCheck($directory->appendUsListings($owner,$reference['instruments'],$reference['assetIds'],$now) === 0, 'Repeated import is a no-op');
$renamed = $assets[2];
$renamed['symbol'] = 'RENAMED';
$renameReference = DirectorySources::us([$renamed], str_replace('FCO|','RENAMED|',$nasdaq), $other, $now, pinAssets:true);
directoryCheck($directory->appendUsListings($owner,$renameReference['instruments'],$renameReference['assetIds'],$now) === 0 &&
    $directory->search($owner,'RENAMED')['instruments'] === [], 'Known provider IDs cannot add duplicate listings after a ticker rename');

$matches = $directory->search($owner,'FCO')['instruments'];
$id = $matches[0]['id'];
directoryCheck(count($matches) === 1 && $matches[0]['symbol'] === 'FCO' && $matches[0]['mic'] === 'XNAS' &&
    $matches[0]['currency'] === 'USD' && $matches[0]['canPrepare'] && !$matches[0]['canAdd'], 'The new listing is searchable and needs normal preparation');
directoryCheck($directory->search($owner,'Fictional Components')['instruments'][0]['id'] === $id, 'Company-name search finds the listing');
directoryCheck($directory->search($owner,'CLASH')['instruments'] === [], 'Conflicting new tickers are excluded');
$after = Maintenance::backup($db);
foreach (['users','portfolios','catalogues','memberships','reportingSettings','reportingState','usage'] as $key) {
    directoryCheck($before[$key] === $after[$key], 'Refresh preserves '.$key);
}
directoryCheck(count($after['directory']) === count($before['directory']) + 1 &&
    array_values(array_filter($after['directory'], static fn($row)=>$row['instrument_id']!==$id)) === $before['directory'], 'All earlier directory definitions and existing evidence survive unchanged');

$newRow = $directory->row($definition['id'], $id);
$parsed = InstrumentDirectory::validateRow($newRow, 'owner');
$state = $parsed['prepared']['state']['us'];
directoryCheck($state['identities'][$id]['checkedAt'] === 0 && !isset($parsed['prepared']['snapshot']), 'Import records an expected identity with no quote or completed identity check');
$recycled = $assets[2];
$recycled['id'] = '00000000-0000-4000-8000-999999999999';
$calls = 0;
$response = static function(string $url) use ($recycled,&$calls): string {
    $calls++;
    if ($url !== 'https://paper-api.alpaca.markets/v2/assets/FCO') throw new RuntimeException('Unexpected price request');
    return json_encode($recycled, JSON_THROW_ON_ERROR);
};
$market = USProviders::refresh([$parsed['instrument']],$state,$response,$now);
directoryCheck($calls === 1 && $market['issues'] === [['instrumentId'=>$id,'code'=>'listing_unverified']], 'First preparation refuses a recycled provider identity before fetching prices');

$restored = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
Bootstrap::initializeSchema($restored, $schema);
Maintenance::restore($restored, $after);
$restoredDirectory = new InstrumentDirectory($restored,'owner@example.test');
directoryCheck($restoredDirectory->search($owner,'FCO')['instruments'][0]['id'] === $id &&
    $restoredDirectory->row($definition['id'],$id)['preparation_json'] === $newRow['preparation_json'], 'Backups restore both search and the reference identity');

echo "US directory refresh checks passed: $checks\n";
