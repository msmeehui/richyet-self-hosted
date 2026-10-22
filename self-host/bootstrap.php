<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');
ini_set('zend.exception_ignore_args', '1');
require __DIR__ . '/bootstrap-lib.php';

try {
    $mode = $argv[1] ?? '';
    $options = [];
    for ($index = 2; $index < $argc; $index += 2) {
        $name = $argv[$index];
        if (!in_array($name, ['--config', '--input'], true) || isset($options[$name]) || !isset($argv[$index + 1])) throw new RuntimeException('Invalid setup arguments.');
        $options[$name] = $argv[$index + 1];
    }
    if (!in_array($mode, ['check', 'initialize', 'catalogue'], true) || !isset($options['--config'])
        || (($mode === 'catalogue') !== isset($options['--input']))) {
        throw new RuntimeException('Usage: php self-host/bootstrap.php check|initialize|catalogue --config /absolute/private/config.json [--input /absolute/private/catalogue.json]');
    }
    \RichYetSelfHost\Bootstrap::checkRuntime();
    $config = \RichYet\loadConfig($options['--config']);
    $db = \RichYet\connect($config);
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') throw new RuntimeException('Installation requires MySQL or MariaDB.');
    if ($mode === 'check') {
        echo json_encode(\RichYetSelfHost\Bootstrap::check($db), JSON_THROW_ON_ERROR) . "\n";
        exit;
    }
    $configPath = \RichYet\Maintenance::privatePath($options['--config'], true);
    $lockPath = \RichYet\Maintenance::privatePath(dirname($configPath) . '/market-refresh.lock', false);
    if (is_link($lockPath)) throw new RuntimeException('Unsafe setup lock.');
    $mask = umask(0077); $lock = @fopen($lockPath, 'c+'); umask($mask);
    if (!$lock || (fstat($lock)['mode'] & 0077) !== 0 || fstat($lock)['uid'] !== posix_geteuid()) throw new RuntimeException('Private setup lock unavailable.');
    if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); throw new RuntimeException('Another market operation is running. Try again after it finishes.'); }
    try {
        $result = $mode === 'initialize' ? \RichYetSelfHost\Bootstrap::initialize($db)
            : \RichYetSelfHost\Bootstrap::catalogue($db, $config['market_owner_email'] ?? '', \RichYetSelfHost\Bootstrap::readDefinition($options['--input']), time());
        echo json_encode($result, JSON_THROW_ON_ERROR) . "\n";
    } finally { flock($lock, LOCK_UN); fclose($lock); }
} catch (Throwable $error) {
    // Database errors can contain connection details, and JSON errors can
    // reflect private input. Print only our fixed validation messages.
    $message = $error instanceof PDOException ? 'Database operation failed. Check the private configuration and use a fresh dedicated database.'
        : ($error instanceof JsonException ? 'Private JSON input is invalid.' : $error->getMessage());
    fwrite(STDERR, $message . "\n");
    exit(1);
}
