<?php
declare(strict_types=1);

namespace RichYetSelfHost;

require_once dirname(__DIR__) . '/server/bootstrap.php';
require_once dirname(__DIR__) . '/server/Maintenance.php';
require_once dirname(__DIR__) . '/server/InstrumentDirectory.php';

/** Fresh personal installations only. No provider transport or portfolio import. */
final class Bootstrap
{
    public const TABLES = ['users', 'portfolios', 'sessions', 'password_tokens', 'rate_limits',
        'market_accounts', 'market_usage', 'market_catalogues', 'market_catalogue_accounts',
        'market_directory', 'market_directory_tokens', 'reporting_settings', 'reporting_state'];

    public static function checkRuntime(): void
    {
        if (PHP_VERSION_ID < 80200) throw new \RuntimeException('Use PHP 8.2 or newer.');
        foreach (['pdo', 'pdo_mysql', 'curl', 'zlib', 'posix'] as $extension) {
            if (!extension_loaded($extension)) throw new \RuntimeException('Required PHP extension unavailable: ' . $extension . '.');
        }
    }

    /** Returns names only; never reads any account or portfolio values. */
    public static function tables(\PDO $db): array
    {
        $driver = $db->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') $names = $db->query('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchAll(\PDO::FETCH_COLUMN);
        elseif ($driver === 'sqlite') $names = $db->query("SELECT name FROM sqlite_master WHERE type IN ('table','view') AND name NOT LIKE 'sqlite_%'")->fetchAll(\PDO::FETCH_COLUMN);
        else throw new \RuntimeException('Unsupported database driver.');
        sort($names, SORT_STRING);
        return $names;
    }

    public static function check(\PDO $db): array
    {
        $tables = self::tables($db);
        return ['database' => $tables === [] ? 'empty' : (array_diff(self::TABLES, $tables) === [] ? 'initialized' : 'other_or_partial_schema'),
            'requiredTables' => count(self::TABLES), 'missingTables' => count(array_diff(self::TABLES, $tables)), 'providerRequests' => 0, 'writes' => 0];
    }

    public static function initialize(\PDO $db): array
    {
        if ($db->getAttribute(\PDO::ATTR_DRIVER_NAME) !== 'mysql') throw new \RuntimeException('Installation requires MySQL or MariaDB.');
        $schema = file_get_contents(dirname(__DIR__) . '/server/schema.mysql.sql');
        if ($schema === false) throw new \RuntimeException('Application schema unavailable.');
        return self::initializeSchema($db, $schema);
    }

    /** The supplied schema is trusted application source, not a CLI option.
     * SQLite fixtures exercise the same fresh-database boundary offline. */
    public static function initializeSchema(\PDO $db, string $schema): array
    {
        if ($db->inTransaction() || self::tables($db) !== []) throw new \RuntimeException('Initialization requires a completely empty dedicated database. Existing tables are never changed.');
        // MySQL DDL commits implicitly. Never attempt destructive cleanup if
        // a server failure leaves partial setup; the next run refuses it.
        $db->exec($schema);
        \RichYet\ReportingStore::initialize($db);
        $tables = self::tables($db);
        $expected = self::TABLES; sort($expected, SORT_STRING);
        if ($tables !== $expected) throw new \RuntimeException('Schema initialization was incomplete. Do not reuse a partially initialized database.');
        return ['result' => 'initialized', 'tables' => count($tables), 'accountsAdded' => 0, 'holdingsAdded' => 0, 'providerRequests' => 0];
    }

    public static function readDefinition(string $path): array
    {
        $path = \RichYet\Maintenance::privatePath($path, true);
        if (!function_exists('posix_geteuid') || fileowner($path) !== posix_geteuid() || fileowner(dirname($path)) !== posix_geteuid()) {
            throw new \RuntimeException('Catalogue file and directory must be owned by the setup user.');
        }
        if (filesize($path) > 1048576) throw new \RuntimeException('Catalogue definition is too large.');
        $definition = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($definition)) throw new \RuntimeException('Invalid catalogue definition.');
        return $definition;
    }

    public static function catalogue(\PDO $db, string $configuredOwner, array $definition, int $now): array
    {
        $email = \RichYet\Backend::email($configuredOwner);
        if (($definition['schemaVersion'] ?? null) !== 1 || ($definition['audience'] ?? null) !== 'owner'
            || array_diff(array_keys($definition), ['schemaVersion', 'id', 'audience', 'instruments', 'policies'])
            || !\RichYet\CatalogueStore::id($definition['id'] ?? null)) throw new \RuntimeException('Use an exact owner-only catalogue definition.');
        $registry = \RichYet\MarketStore::validateRegistry($definition['instruments'] ?? null);
        $policies = \RichYet\CatalogueStore::policies($definition['policies'] ?? null, $registry, 'owner');
        foreach ($registry as $instrument) {
            $policy = $policies[$instrument['id']];
            if ($policy['priceAccess'] !== 'owner' || $policy['fxAccess'] === 'invited'
                || ($instrument['currency'] !== 'EUR' && $policy['fxAccess'] !== 'owner')) {
                throw new \RuntimeException('Personal price and currency access must be reviewed before installation.');
            }
        }
        if ($now < 1 || $db->inTransaction() || array_diff(self::TABLES, self::tables($db))) throw new \RuntimeException('Initialize the complete database before installing its catalogue.');
        $lock = $db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $db->beginTransaction();
        try {
            $users = $db->query('SELECT id, email FROM users' . $lock)->fetchAll(\PDO::FETCH_ASSOC);
            if (count($users) !== 1 || $users[0]['email'] !== $email) throw new \RuntimeException('Catalogue setup requires only the configured owner account.');
            $ownerId = (int) $users[0]['id'];
            $portfolios = $db->query('SELECT owner_id, revision, holdings_json, updated_at FROM portfolios' . $lock)->fetchAll(\PDO::FETCH_ASSOC);
            if (count($portfolios) !== 1 || (int) $portfolios[0]['owner_id'] !== $ownerId
                || (int) $portfolios[0]['revision'] !== 0 || json_decode($portfolios[0]['holdings_json'], true, 32, JSON_THROW_ON_ERROR) !== []) {
                throw new \RuntimeException('Catalogue setup requires a new empty portfolio. Existing portfolios are never changed.');
            }
            foreach (['market_accounts', 'market_catalogues', 'market_catalogue_accounts', 'market_directory', 'market_directory_tokens'] as $table) {
                if ((int) $db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn() !== 0) throw new \RuntimeException('Market setup already exists. It is never replaced.');
            }
            $catalogues = new \RichYet\CatalogueStore($db, $email);
            $catalogues->install($definition, $now);
            $count = (new \RichYet\InstrumentDirectory($db, $email))->seedCatalogue($definition['id'], $now);
            $catalogues->attach($ownerId, $definition['id'], 0, null, $now);
            $after = $db->query('SELECT owner_id, revision, holdings_json, updated_at FROM portfolios' . $lock)->fetchAll(\PDO::FETCH_ASSOC);
            if ($after !== $portfolios) throw new \RuntimeException('Portfolio preservation check failed.');
            $db->commit();
            return ['result' => 'personal_catalogue_installed', 'searchableListings' => $count, 'holdingsAdded' => 0, 'providerRequests' => 0, 'prices' => 'awaiting_preparation'];
        } catch (\Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
    }
}
