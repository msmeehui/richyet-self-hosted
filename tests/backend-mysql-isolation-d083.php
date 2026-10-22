<?php
declare(strict_types=1);

/**
 * Test-only native PDO adapter including the two reporting tables. Every app table and test trigger is rewritten to
 * an unpredictable, validated prefix. Never copy this file into the public root.
 */
final class IsolatedMysql extends PDO
{
    public const TABLES = ['users', 'portfolios', 'sessions', 'password_tokens', 'rate_limits', 'market_accounts', 'market_usage', 'market_catalogues', 'market_catalogue_accounts', 'market_directory', 'market_directory_tokens', 'reporting_settings', 'reporting_state'];
    private array $created = [];

    public function __construct(private readonly array $config, public readonly string $prefix)
    {
        if (!preg_match('/^rycheck_[a-f0-9]{16}_$/D', $prefix) || !str_starts_with($config['database']['dsn'] ?? '', 'mysql:')) {
            throw new RuntimeException('Native tests require a random isolated prefix and MySQL configuration.');
        }
        parent::__construct($config['database']['dsn'], $config['database']['username'], $config['database']['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    private function rewrite(string $sql): string
    {
        // Values are always bound parameters. No user-provided SQL is accepted.
        return preg_replace('/(?<![A-Za-z0-9_])(?:users|portfolios|sessions|password_tokens|rate_limits|market_accounts|market_usage|market_catalogues|market_catalogue_accounts|market_directory|market_directory_tokens|reporting_settings|reporting_state|fail_write)(?![A-Za-z0-9_])/', $this->prefix . '$0', $sql);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare($this->rewrite($query), $options);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        return $fetchMode === null ? parent::query($this->rewrite($query)) : parent::query($this->rewrite($query), $fetchMode, ...$fetchModeArgs);
    }

    public function exec(string $statement): int|false
    {
        return parent::exec($this->rewrite($statement));
    }

    public function initialize(): void
    {
        $probe = parent::prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        foreach (self::TABLES as $table) {
            $probe->execute([$this->prefix . $table]);
            if ((int) $probe->fetchColumn() !== 0) throw new RuntimeException('Refusing to reuse an existing native test table.');
        }
        $schema = file_get_contents(dirname(__DIR__) . '/server/schema.mysql.sql');
        // Source schema is trusted. Each successful CREATE is recorded so a
        // failed setup only drops tables that this run actually created.
        foreach (explode(';', $schema) as $statement) {
            if (!preg_match('/CREATE TABLE IF NOT EXISTS (users|portfolios|sessions|password_tokens|rate_limits|market_accounts|market_usage|market_catalogues|market_catalogue_accounts|market_directory|market_directory_tokens)\s*\(/', $statement, $match)) continue;
            $sql = str_replace('CREATE TABLE IF NOT EXISTS', 'CREATE TABLE', $statement);
            $this->exec($sql);
            $this->created[] = $match[1];
        }
        // ReportingStore owns this additive schema. Track either table even
        // when initialization fails halfway, so cleanup remains exact.
        try { \RichYet\ReportingStore::initialize($this); }
        finally {
            foreach (['reporting_settings', 'reporting_state'] as $table) {
                $probe->execute([$this->prefix . $table]);
                if ((int) $probe->fetchColumn() === 1) $this->created[] = $table;
            }
        }
        if (count($this->created) !== count(self::TABLES)) throw new RuntimeException('Incomplete native test schema.');
    }

    public function reconnect(): self { return new self($this->config, $this->prefix); }

    public function cleanup(): void
    {
        if ($this->inTransaction()) $this->rollBack();
        // No FOREIGN_KEY_CHECKS bypass, broad wildcard delete, or unprefixed DDL.
        foreach (['reporting_settings', 'reporting_state', 'market_directory_tokens', 'market_directory', 'market_catalogue_accounts', 'market_catalogues', 'sessions', 'password_tokens', 'market_accounts', 'portfolios', 'users', 'rate_limits', 'market_usage'] as $table) {
            if (in_array($table, $this->created, true)) {
                parent::exec('DROP TABLE `' . $this->prefix . $table . '`');
                $this->created = array_values(array_diff($this->created, [$table]));
            }
        }
    }
}
