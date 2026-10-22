<?php
declare(strict_types=1);

namespace RichYet;

require_once __DIR__.'/ReportingStore.php';

final class HttpError extends \RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}

final class Backend
{
    public const AUTH_SECONDS = 2592000; // Thirty-day absolute lifetime.
    public const IDLE_SECONDS = 604800; // Seven days without use.
    public const PASSWORD_TOKEN_SECONDS = 3600;
    public const FICTIONAL_IDS = ['world', 'northstar', 'europe', 'orion', 'horizon', 'oak', 'pacific', 'aurora', 'stone'];
    private const DUMMY_HASH = '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
    private readonly bool $mysql;
    private readonly int $now;

    public function __construct(private readonly \PDO $db, private readonly string $appKey, ?int $now = null, private readonly ?MarketStore $market = null, private readonly ?\Closure $prepare = null, private readonly array $accountDisplayNames = [], private readonly ?StatusStore $status = null)
    {
        $this->mysql = $db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql';
        $this->now = $now ?? time();
    }

    private function query(string $sql, array $params = []): \PDOStatement
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        return $statement;
    }

    private function transaction(callable $work): mixed
    {
        $this->db->beginTransaction();
        try {
            $result = $work();
            $this->db->commit();
            return $result;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }

    private function lock(): string { return $this->mysql ? ' FOR UPDATE' : ''; }

    private static function fields(\stdClass $input, array $keys): void
    {
        $actual = array_keys(get_object_vars($input));
        sort($actual);
        sort($keys);
        if ($actual !== $keys) self::invalid('The request has missing or unexpected fields.');
    }

    private static function invalid(string $message): never
    {
        throw new HttpError(422, 'invalid_input', $message);
    }

    public static function email(mixed $email): string
    {
        if (!is_string($email) || strlen($email) > 254 || !preg_match('/^[\x21-\x7e]+$/D', trim($email))
            || !filter_var(trim($email), FILTER_VALIDATE_EMAIL)) self::invalid('Enter a valid email address.');
        return strtolower(trim($email));
    }

    private static function password(mixed $password): string
    {
        // Bcrypt counts bytes and truncates after 72. Reject rather than truncate.
        if (!is_string($password) || strlen($password) > 72 || str_contains($password, "\0") || preg_match_all('/./us', $password) < 12) {
            self::invalid('Use at least 12 characters, within 72 bytes. A long passphrase works well.');
        }
        return $password;
    }

    public static function holdings(mixed $holdings, ?array $allowedIds = null, bool $allowUnknownCost = false): array
    {
        if (!is_array($holdings) || !array_is_list($holdings) || count($holdings) > 200) self::invalid('Enter a valid holdings list.');
        $seenIds = $seenInstruments = [];
        foreach ($holdings as $holding) {
            if (!$holding instanceof \stdClass) self::invalid('Each holding must be an object.');
            self::fields($holding, ['id', 'instrumentId', 'quantity', 'averageCost', 'eurCost']);
            if (!is_string($holding->id) || !preg_match('/^[A-Za-z0-9_-]{1,60}$/D', $holding->id)
                || isset($seenIds[$holding->id])) self::invalid('Each holding needs a unique valid identifier.');
            if (!is_string($holding->instrumentId) || !in_array($holding->instrumentId, $allowedIds ?? self::FICTIONAL_IDS, true)
                || isset($seenInstruments[$holding->instrumentId])) self::invalid('Choose one of your registered investments.');
            $seenIds[$holding->id] = $seenInstruments[$holding->instrumentId] = true;
            foreach (['quantity', 'averageCost', 'eurCost'] as $field) {
                $value = $holding->{$field};
                if (($field === 'eurCost' || ($field === 'averageCost' && $allowUnknownCost)) && $value === null) continue;
                if (!is_string($value) || !preg_match('/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,6})?$/D', $value)) {
                    self::invalid('Amounts must be exact decimal strings, with at most 12 whole and 6 fractional digits.');
                }
                if ($field === 'quantity' && !preg_match('/[1-9]/', $value)) self::invalid('Quantity must be greater than zero.');
            }
        }
        return $holdings;
    }

    /** Limits live in the shared DB, independent of cookies or workers. */
    private function rate(string $kind, string $identity, int $limit): void
    {
        $window = intdiv($this->now, 900);
        $key = hash_hmac('sha256', $kind . ':' . $identity . ':' . $window, $this->appKey);
        $sql = $this->mysql
            ? 'INSERT INTO rate_limits (bucket_key, attempts, expires_at) VALUES (?, 1, ?) ON DUPLICATE KEY UPDATE attempts = attempts + 1'
            : 'INSERT INTO rate_limits (bucket_key, attempts, expires_at) VALUES (?, 1, ?) ON CONFLICT(bucket_key) DO UPDATE SET attempts = attempts + 1';
        $this->query($sql, [$key, ($window + 1) * 900]);
        if ((int) $this->query('SELECT attempts FROM rate_limits WHERE bucket_key = ?', [$key])->fetchColumn() > $limit) {
            throw new HttpError(429, 'rate_limited', 'Too many attempts. Wait 15 minutes and try again.');
        }
    }

    private function session(?string $token, bool $required = true, bool $touch = true): ?array
    {
        $record = is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token)
            ? $this->query('SELECT s.*, u.email FROM sessions s LEFT JOIN users u ON u.id = s.user_id WHERE s.token_hash = ?', [hash('sha256', $token)])->fetch()
            : false;
        if (!$record || (int) $record['expires_at'] <= $this->now || (int) $record['last_seen_at'] <= $this->now - self::IDLE_SECONDS) {
            if ($required) throw new HttpError(401, 'unauthorized', 'Your session has ended. Sign in again.');
            return null;
        }
        if($touch)$this->query('UPDATE sessions SET last_seen_at = ? WHERE token_hash = ?', [$this->now, $record['token_hash']]);
        return $record;
    }

    private function createSession(?int $userId): array
    {
        $token = bin2hex(random_bytes(32));
        $csrf = bin2hex(random_bytes(32));
        $this->query('INSERT INTO sessions (token_hash, user_id, csrf_token, created_at, last_seen_at, expires_at) VALUES (?, ?, ?, ?, ?, ?)',
            [hash('sha256', $token), $userId, $csrf, $this->now, $this->now, $this->now + ($userId === null ? 3600 : self::AUTH_SECONDS)]);
        return ['cookie' => $token, 'session' => $this->session($token)];
    }

    private function sessionBody(array $session): array
    {
        $user = null;
        if ($session['user_id'] !== null) {
            $user = ['email' => $session['email'], 'canViewStatus' => $this->status?->canView((int)$session['user_id']) ?? false];
            $name = $this->accountDisplayNames[(int)$session['user_id']] ?? null;
            $name = is_string($name) ? preg_replace('/\A\s+|\s+\z/u', '', $name) : null;
            if (is_string($name) && preg_match('/\A[^\p{Cc}\p{Cf}]{1,120}\z/u', $name) === 1) {
                $user['displayName'] = $name;
            }
        }
        return [
            'authenticated' => $session['user_id'] !== null,
            'user' => $user,
            'reportingCurrency' => $session['user_id'] === null ? 'EUR' : (new ReportingStore($this->db))->currency((int)$session['user_id']),
            'numberFormat' => $session['user_id'] === null ? 'nl-NL' : (new ReportingStore($this->db))->numberFormat((int)$session['user_id']),
            'csrfToken' => $session['csrf_token'], 'dataMode' => $session['user_id'] === null ? 'fictional' : ($this->market?->mode((int)$session['user_id']) ?? 'fictional'),
        ];
    }

    public function handle(string $action, string $method, \stdClass $input, ?string $token, string $csrf, string $ip, array $query = []): array
    {
        $methods = ['session' => ['GET'], 'login' => ['POST'], 'logout' => ['POST'], 'password' => ['POST'], 'portfolio' => ['GET', 'PUT'], 'instruments' => ['GET'], 'market' => ['GET'], 'search' => ['GET'], 'prepare' => ['POST'], 'status'=>['GET'], 'settings'=>['GET','PUT']];
        if (!isset($methods[$action])) throw new HttpError(404, 'not_found', 'This request is not available.');
        if (!in_array($method, $methods[$action], true)) throw new HttpError(405, 'method_not_allowed', 'This request is not supported.');
        if ($action === 'session') {
            $session = $this->session($token, false);
            if ($session !== null) return ['status' => 200, 'body' => $this->sessionBody($session)];
            $this->rate('bootstrap', $ip, 60);
            $this->query('DELETE FROM sessions WHERE expires_at <= ? OR last_seen_at <= ?', [$this->now, $this->now - self::IDLE_SECONDS]);
            $this->query('DELETE FROM rate_limits WHERE expires_at <= ?', [$this->now]);
            $new = $this->createSession(null);
            return ['status' => 200, 'body' => $this->sessionBody($new['session']), 'cookie' => $new['cookie']];
        }
        $session = $this->session($token,true,$action!=='status');
        if (in_array($action, ['portfolio','instruments','market','search','prepare','status','settings'], true) && $session['user_id'] === null) throw new HttpError(401, 'unauthorized', 'Sign in to open your portfolio.');
        if ($method !== 'GET' && (!preg_match('/^[a-f0-9]{64}$/D', $csrf) || !hash_equals($session['csrf_token'], $csrf))) {
            throw new HttpError(403, 'csrf', 'Your security token has changed. Reload RichYet and try again.');
        }
        if($action==='settings'){
            if($query!==[])self::invalid('Settings do not accept account parameters.');
            $settings=new ReportingStore($this->db);$ownerId=(int)$session['user_id'];
            if($method==='GET')return ['status'=>200,'body'=>$settings->preferences($ownerId)];
            // Update exactly one preference, preserving a concurrent change to the other.
            $field=property_exists($input,'currency')?'currency':'numberFormat';self::fields($input,[$field]);
            if($field==='currency'&&(!is_string($input->currency)||!in_array($input->currency,['EUR','USD','GBP'],true)))self::invalid('Choose EUR, USD or GBP.');
            if($field==='numberFormat'&&(!is_string($input->numberFormat)||!in_array($input->numberFormat,['nl-NL','en-GB'],true)))self::invalid('Choose Dutch or English number formatting.');
            $this->rate('settings',(string)$ownerId,30);
            if($field==='currency')$settings->setCurrency($ownerId,$input->currency,$this->now);else $settings->setNumberFormat($ownerId,$input->numberFormat,$this->now);
            return ['status'=>200,'body'=>$settings->preferences($ownerId)];
        }
        if($action==='status'){
            if($query!==[])self::invalid('Status does not accept account or provider parameters.');
            if($this->status===null)throw new HttpError(403,'owner_only','Status is available only to the primary account.');
            return ['status'=>200,'body'=>$this->status->read((int)$session['user_id'])];
        }
        if ($action === 'search') {
            require_once __DIR__.'/InstrumentDirectory.php';
            $term=$query['query']??'';$offset=$query['offset']??'0';
            if(array_diff(array_keys($query),['query','offset'])||!is_string($term)||strlen($term)>160||preg_match('/[\x00-\x1f\x7f]/',$term)||!is_string($offset)||!preg_match('/^(?:0|[1-9][0-9]{0,4})$/D',$offset)) self::invalid('Enter a valid investment search.');
            $this->rate('search',(string)$session['user_id'],240);
            $directory=new InstrumentDirectory($this->db,$this->market?->ownerEmail(),$this->now);
            return ['status'=>200,'body'=>$directory->search((int)$session['user_id'],$term,(int)$offset)];
        }
        if ($action === 'prepare') {
            self::fields($input,['instrumentId']);
            if(!is_string($input->instrumentId)||!preg_match('/^[A-Za-z0-9_-]{1,60}$/D',$input->instrumentId)) self::invalid('Choose a supported investment.');
            if($this->prepare===null)throw new HttpError(409,'preparation_unavailable','Investment preparation is not available. Try again later.');
            $this->rate('prepare',(string)$session['user_id'],20);
            return ['status'=>200,'body'=>($this->prepare)((int)$session['user_id'],$input->instrumentId)];
        }
        if (in_array($action, ['instruments','market'], true)) {
            if ($this->market === null) throw new HttpError(409, 'live_unavailable', 'Live investments have not been configured.');
            $body = $action === 'market' ? $this->market->market((int)$session['user_id']) : $this->market->catalogue((int)$session['user_id']);
            $settings=(new ReportingStore($this->db))->preferences((int)$session['user_id']);
            if($action==='market'){$body['reportingCurrency']=$settings['currency'];$body['numberFormat']=$settings['numberFormat'];}
            else{$body['market']['reportingCurrency']=$settings['currency'];$body['market']['numberFormat']=$settings['numberFormat'];}
            return ['status'=>200,'body'=>$body];
        }
        if ($action === 'portfolio') {
            if ($method === 'GET') return ['status' => 200, 'body' => $this->portfolio((int) $session['user_id'])];
            self::fields($input, ['revision', 'holdings']);
            if (!is_int($input->revision) || $input->revision < 0 || $input->revision >= 2147483647) self::invalid('The portfolio revision is invalid.');
            $current = $this->portfolio((int)$session['user_id']);
            if ($input->revision !== $current['revision']) throw new HttpError(409, 'conflict', 'This portfolio changed in another session. Reload it before saving.');
            $holdings = self::holdings($input->holdings, $current['dataMode']==='live' ? array_column($this->market->registry((int)$session['user_id']),'id') : null, $current['dataMode']==='live');
            $snapshot = $this->transaction(function () use ($session, $input, $holdings, $current) {
                if($current['dataMode']==='live')$this->market->assertAdditions((int)$session['user_id'],$current['holdings'],$holdings);
                // The conditional update is atomic even when two independent
                // browsers start from the same revision. Owner comes from session.
                $statement = $this->query('UPDATE portfolios SET holdings_json = ?, revision = revision + 1, updated_at = ? WHERE owner_id = ? AND revision = ?',
                    [json_encode($holdings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $this->now, $session['user_id'], $input->revision]);
                if ($statement->rowCount() !== 1) {
                    throw new HttpError(409, 'conflict', 'This portfolio changed in another session. Reload it before saving.');
                }
                return $this->portfolio((int) $session['user_id']);
            });
            return ['status' => 200, 'body' => $snapshot];
        }
        if ($action === 'login') {
            self::fields($input, ['email', 'password']);
            $email = self::email($input->email);
            $this->rate('login-ip', $ip, 30);
            $this->rate('login-email', $email, 10);
            $password = is_string($input->password) && strlen($input->password) <= 72 && !str_contains($input->password, "\0") ? $input->password : '';
            $new = $this->transaction(function () use ($email, $password, $session) {
                $user = $this->query('SELECT * FROM users WHERE email = ?' . $this->lock(), [$email])->fetch();
                $hash = $user['password_hash'] ?? self::DUMMY_HASH;
                if (!password_verify($password, $hash) || !$user || $user['password_hash'] === null) {
                    throw new HttpError(401, 'invalid_credentials', 'The email address or password is incorrect.');
                }
                if (password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12])) {
                    $this->query('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]), $user['id']]);
                }
                $this->query('DELETE FROM sessions WHERE token_hash = ?', [$session['token_hash']]);
                return $this->createSession((int) $user['id']);
            });
        } elseif ($action === 'logout') {
            self::fields($input, []);
            $new = $this->transaction(function () use ($session) {
                $this->query('DELETE FROM sessions WHERE token_hash = ?', [$session['token_hash']]);
                return $this->createSession(null);
            });
        } else {
            self::fields($input, ['token', 'password']);
            $this->rate('password-ip', $ip, 15);
            if (!is_string($input->token) || !preg_match('/^[a-f0-9]{64}$/D', $input->token)) {
                throw new HttpError(400, 'invalid_token', 'This password link is invalid or has expired.');
            }
            $password = self::password($input->password);
            $tokenHash = hash('sha256', $input->token);
            $new = $this->transaction(function () use ($tokenHash, $password, $session) {
                $reset = $this->query('SELECT * FROM password_tokens WHERE token_hash = ?' . $this->lock(), [$tokenHash])->fetch();
                if (!$reset || (int) $reset['expires_at'] <= $this->now) throw new HttpError(400, 'invalid_token', 'This password link is invalid or has expired.');
                $this->query('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]), $reset['user_id']]);
                $this->query('DELETE FROM password_tokens WHERE token_hash = ?', [$tokenHash]);
                $this->query('DELETE FROM sessions WHERE user_id = ? OR token_hash = ?', [$reset['user_id'], $session['token_hash']]);
                return $this->createSession((int) $reset['user_id']);
            });
        }
        return ['status' => 200, 'body' => $this->sessionBody($new['session']), 'cookie' => $new['cookie']];
    }

    private function portfolio(int $ownerId): array
    {
        $record = $this->query('SELECT revision, holdings_json, updated_at FROM portfolios WHERE owner_id = ?', [$ownerId])->fetch();
        if (!$record) throw new \RuntimeException('Portfolio record missing.');
        // Corruption is not an empty portfolio and must never be overwritten.
        $holdings = json_decode($record['holdings_json'], false, 32, JSON_THROW_ON_ERROR);
        $mode = $this->market?->mode($ownerId) ?? 'fictional';
        $registry = $mode === 'live' ? $this->market->registry($ownerId) : [];
        try { self::holdings($holdings, $mode === 'live' ? array_column($registry,'id') : null, $mode === 'live'); }
        catch (HttpError $error) { throw new \RuntimeException('Saved portfolio is invalid.', 0, $error); }
        return ['revision' => (int) $record['revision'], 'holdings' => $holdings, 'dataMode' => $mode, 'instruments' => $mode === 'live' ? MarketStore::metadata($registry) : [],
            'updatedAt' => gmdate('Y-m-d\TH:i:s\Z', (int) $record['updated_at'])];
    }

    /** CLI-only operator action. No route exposes account creation or recovery. */
    public function invite(string $email, bool $create = false): string
    {
        $email = self::email($email);
        return $this->transaction(function () use ($email, $create) {
            $user = $this->query('SELECT id FROM users WHERE email = ?' . $this->lock(), [$email])->fetch();
            if ($create) {
                if ($user) throw new \RuntimeException('Account already exists. Use recover for a fresh password link.');
                $this->query('INSERT INTO users (email, password_hash, created_at) VALUES (?, NULL, ?)', [$email, $this->now]);
                $id = (int) $this->db->lastInsertId();
                $this->query("INSERT INTO portfolios (owner_id, revision, holdings_json, updated_at) VALUES (?, 0, '[]', ?)", [$id, $this->now]);
            } else {
                if (!$user) throw new \RuntimeException('Account does not exist.');
                $id = (int) $user['id'];
            }
            $token = bin2hex(random_bytes(32));
            $this->query('DELETE FROM password_tokens WHERE user_id = ?', [$id]);
            $this->query('INSERT INTO password_tokens (token_hash, user_id, expires_at) VALUES (?, ?, ?)', [hash('sha256', $token), $id, $this->now + self::PASSWORD_TOKEN_SECONDS]);
            return $token;
        });
    }
}
