<?php

declare(strict_types=1);

use KamauPutt\Core\PdoOwnerAuth;
use KamauPutt\Core\PdoOwnerControl;
use KamauPutt\Http\OwnerAuthController;

require_once __DIR__ . '/../src/Core/PdoOwnerAuth.php';
require_once __DIR__ . '/../src/Core/PdoDispatchGate.php';
require_once __DIR__ . '/../src/Core/PdoOwnerControl.php';
require_once __DIR__ . '/../src/Http/OwnerAuthController.php';

header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$allowedRoutes = [
    'POST /api/auth/login',
    'GET /api/auth/session',
    'POST /api/auth/logout',
    'GET /api/control/status',
    'POST /api/control/stop',
    'POST /api/control/resume/challenge',
    'POST /api/control/resume/confirm',
];
if (!in_array($method . ' ' . $requestPath, $allowedRoutes, true)) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"error":"not_found"}';
    exit;
}

$httpsFlag = strtolower(trim((string) ($_SERVER['HTTPS'] ?? '')));
$secureTransport = in_array($httpsFlag, ['on', '1'], true);
if (!$secureTransport) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"error":"https_required"}';
    exit;
}
header('Strict-Transport-Security: max-age=31536000');

$required = ['KP_MYSQL_DSN', 'KP_MYSQL_USER', 'KP_MYSQL_PASSWORD', 'KP_ALLOWED_ORIGIN', 'KP_MFA_ENCRYPTION_KEY', 'KP_AUTH_RATE_LIMIT_KEY'];
$config = [];
foreach ($required as $name) {
    $value = getenv($name);
    if (!is_string($value) || $value === '') {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo '{"error":"service_unavailable"}';
        exit;
    }
    $config[$name] = $value;
}
$mfaKey = base64_decode($config['KP_MFA_ENCRYPTION_KEY'], true);
$rateKey = base64_decode($config['KP_AUTH_RATE_LIMIT_KEY'], true);
if (!function_exists('sodium_crypto_secretbox') || !is_string($mfaKey) || strlen($mfaKey) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES || !is_string($rateKey) || strlen($rateKey) < 32) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"error":"service_unavailable"}';
    exit;
}

if ($method === 'POST' && !hash_equals($config['KP_ALLOWED_ORIGIN'], (string) ($_SERVER['HTTP_ORIGIN'] ?? ''))) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"error":"origin_denied"}';
    exit;
}

try {
    if (strlen((string) ($_SERVER['CONTENT_LENGTH'] ?? '0')) > 8 || (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 8192) {
        http_response_code(413);
        exit;
    }
    $contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
    $body = file_get_contents('php://input', false, null, 0, 8193);
    if (!is_string($body) || strlen($body) > 8192 || ($method === 'POST' && $contentType !== 'application/json')) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo '{"error":"invalid_request"}';
        exit;
    }
    $db = new PDO($config['KP_MYSQL_DSN'], $config['KP_MYSQL_USER'], $config['KP_MYSQL_PASSWORD'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $auth = new PdoOwnerAuth($db, $mfaKey, $rateKey);
    $control = new PdoOwnerControl($db);
    $controller = new OwnerAuthController($auth, $config['KP_ALLOWED_ORIGIN'], $control);
    $response = $controller->handle(
        $method,
        (string) $requestPath,
        $_SERVER['HTTP_ORIGIN'] ?? null,
        $_COOKIE,
        $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null,
        $body,
        true,
        $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    );
    foreach ($response['headers'] as $name => $value) { header($name . ': ' . $value); }
    foreach ($response['cookies'] as $name => $cookie) {
        setcookie($name, $cookie['value'], [
            'expires' => $cookie['expires'],
            'path' => $cookie['path'],
            'secure' => $cookie['secure'],
            'httponly' => $cookie['http_only'],
            'samesite' => $cookie['same_site'],
        ]);
    }
    http_response_code($response['status']);
    if ($response['status'] !== 204) {
        echo json_encode($response['body'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
} catch (Throwable) {
    error_log('Kamau Putt owner-auth request failed');
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo '{"error":"service_unavailable"}';
}
