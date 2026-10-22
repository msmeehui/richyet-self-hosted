<?php
declare(strict_types=1);

namespace RichYet;

require_once __DIR__ . '/Backend.php';
require_once __DIR__ . '/MarketStore.php';
require_once __DIR__ . '/USProviders.php';
require_once __DIR__ . '/StatusStore.php';

/** Secrets are loaded only from an explicit path, never from public files. */
function loadConfig(string $path): array
{
    $resolved = realpath($path);
    $source = realpath(dirname(__DIR__));
    $documentRoot = $_SERVER['DOCUMENT_ROOT'] ?? '';
    $public = realpath($documentRoot !== '' ? $documentRoot : dirname(__DIR__) . '/public');
    if ($resolved === false || is_link($path) || !is_file($resolved)
        || ($source && str_starts_with($resolved, $source . DIRECTORY_SEPARATOR))
        || ($public && str_starts_with($resolved, $public . DIRECTORY_SEPARATOR))
        || str_contains($resolved, '/Dropbox/') || filesize($resolved) > 16384) {
        throw new \RuntimeException('Configuration must be a private file outside the application and document root.');
    }
    $parent = dirname($resolved);
    if ((fileperms($resolved) & 0077) !== 0 || (fileperms($parent) & 0077) !== 0
        || (function_exists('posix_geteuid') && (fileowner($resolved) !== posix_geteuid() || fileowner($parent) !== posix_geteuid()))) {
        throw new \RuntimeException('Configuration file and directory require owner-only access.');
    }
    $config = json_decode(file_get_contents($resolved), true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($config) || !is_string($config['origin'] ?? null)
        || !is_string($config['app_key'] ?? null) || strlen($config['app_key']) < 32
        || str_contains($config['app_key'], 'REPLACE')
        || !is_array($config['database'] ?? null)
        || (array_key_exists('account_display_names', $config) && !is_array($config['account_display_names']))) {
        throw new \RuntimeException('Configuration is incomplete.');
    }
    $origin = $config['origin'];
    $local = ($config['allow_insecure_localhost'] ?? false) === true;
    if (!preg_match('~^https://[a-z0-9.-]+(?::[0-9]{1,5})?$~D', $origin)
        && !($local && preg_match('~^http://(?:127\.0\.0\.1|localhost):[0-9]{1,5}$~D', $origin))) {
        throw new \RuntimeException('A canonical HTTPS origin is required.');
    }
    if ($local && !preg_match('~^http://(?:127\.0\.0\.1|localhost):[0-9]{1,5}$~D', $origin)) {
        throw new \RuntimeException('Insecure development mode is restricted to localhost.');
    }
    if (!is_string($config['database']['dsn'] ?? null)
        || (!str_starts_with($config['database']['dsn'], 'mysql:')
            && !($local && str_starts_with($config['database']['dsn'], 'sqlite:')))) {
        throw new \RuntimeException('Production requires MySQL or MariaDB.');
    }
    return $config;
}

function connect(array $config): \PDO
{
    $database = $config['database'];
    $pdo = new \PDO($database['dsn'], $database['username'] ?? '', $database['password'] ?? '', [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        \PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    if ($pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
    }
    return $pdo;
}
