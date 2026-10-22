<?php
declare(strict_types=1);

ini_set('display_errors', '0');

// The public entry point contains no credentials. Backend source stays outside
// the document root; RICHYET_SERVER_DIR supports a separate Plesk private path.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

try {
    $serverDir = getenv('RICHYET_SERVER_DIR') ?: dirname(__DIR__, 2) . '/server';
    require_once $serverDir . '/bootstrap.php';
    $configPath = getenv('RICHYET_CONFIG') ?: '';
    $config = \RichYet\loadConfig($configPath);
    $local = ($config['allow_insecure_localhost'] ?? false) === true;
    if (!$local && (!isset($_SERVER['HTTPS']) || !in_array(strtolower((string) $_SERVER['HTTPS']), ['on', '1'], true))) {
        throw new \RichYet\HttpError(400, 'https_required', 'Use the secure website address.');
    }
    $fetchSite = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? '';
    if ($fetchSite !== '' && !in_array($fetchSite, ['same-origin', 'none'], true)) {
        throw new \RichYet\HttpError(403, 'csrf', 'Open RichYet directly and try again.');
    }
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET', 'POST', 'PUT'], true)) {
        throw new \RichYet\HttpError(405, 'method_not_allowed', 'This request is not supported.');
    }
    if ($method !== 'GET') {
        if (($_SERVER['HTTP_ORIGIN'] ?? '') !== $config['origin']) {
            throw new \RichYet\HttpError(403, 'csrf', 'Open RichYet directly and try again.');
        }
        if (!preg_match('~^application/json(?:\s*;\s*charset=utf-8)?$~iD', $_SERVER['CONTENT_TYPE'] ?? '')) {
            throw new \RichYet\HttpError(415, 'invalid_input', 'Send a JSON request.');
        }
    }
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 262144) {
        throw new \RichYet\HttpError(413, 'invalid_input', 'This portfolio is too large.');
    }
    $raw = file_get_contents('php://input', false, null, 0, 262145);
    if (strlen($raw) > 262144) {
        throw new \RichYet\HttpError(413, 'invalid_input', 'This portfolio is too large.');
    }
    try {
        $body = $method === 'GET' ? new \stdClass() : json_decode($raw, false, 32, JSON_THROW_ON_ERROR);
    } catch (\JsonException $error) {
        throw new \RichYet\HttpError(422, 'invalid_input', 'The request contains invalid JSON.');
    }
    if (!$body instanceof \stdClass) {
        throw new \RichYet\HttpError(422, 'invalid_input', 'The request must be an object.');
    }
    $action = $_GET['action'] ?? '';
    if (!is_string($action)) {
        throw new \RichYet\HttpError(404, 'not_found', 'This request is not available.');
    }
    $cookieName = $local ? 'richyet_dev' : '__Host-richyet';
    $cookie = $_COOKIE[$cookieName] ?? null;
    $db = \RichYet\connect($config);
    $market = new \RichYet\MarketStore($db, $config['market_owner_email'] ?? null);
    $prepare=static function(int $ownerId,string $instrumentId)use($db,$config,$serverDir,$configPath):array{
        require_once $serverDir.'/PreparationRuntime.php';
        return \RichYet\PreparationRuntime::run($db,$config,$configPath,$ownerId,$instrumentId);
    };
    $status = new \RichYet\StatusStore($db, $config['market_owner_email'] ?? null, ($config['status_enabled'] ?? true) === true);
    $backend = new \RichYet\Backend($db, $config['app_key'], null, $market, $prepare, $config['account_display_names'] ?? [], $status);
    $query=$_GET;unset($query['action']);
    $result = $backend->handle($action, $method, $body, is_string($cookie) ? $cookie : null,
        $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '', $_SERVER['REMOTE_ADDR'] ?? 'unknown',$query);
    if (isset($result['cookie'])) {
        setcookie($cookieName, $result['cookie'], [
            'expires' => time() + \RichYet\Backend::AUTH_SECONDS,
            'path' => '/', 'secure' => !$local, 'httponly' => true, 'samesite' => 'Strict',
        ]);
    }
    http_response_code($result['status']);
    echo json_encode($result['body'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
} catch (\RichYet\HttpError $error) {
    http_response_code($error->status);
    if ($error->status === 429) header('Retry-After: 900');
    echo json_encode(['error' => ['code' => $error->errorCode, 'message' => $error->getMessage()]]);
} catch (\Throwable $error) {
    // Never include exception messages, request bodies, DSNs, hashes or tokens.
    error_log('RichYet API unavailable: ' . get_class($error));
    http_response_code(503);
    echo '{"error":{"code":"unavailable","message":"RichYet is temporarily unavailable. Your changes have not been saved."}}';
}
