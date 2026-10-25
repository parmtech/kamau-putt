<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/PdoOwnerAuth.php';

if (PHP_SAPI !== 'cli' || getenv('KP_TEST_ALLOW_DB_WRITE') !== 'I_UNDERSTAND_STAGING_ONLY') {
    fwrite(STDERR, "SKIP: explicit disposable database confirmation required\n");
    exit(2);
}
$dsn = getenv('KP_TEST_MYSQL_DSN');
if (!is_string($dsn) || !str_starts_with($dsn, 'mysql:') || !extension_loaded('pdo_mysql')) {
    fwrite(STDERR, "SKIP: configured MySQL PDO driver required\n");
    exit(2);
}
$db = new PDO($dsn, getenv('KP_TEST_MYSQL_USER') ?: '', getenv('KP_TEST_MYSQL_PASSWORD') ?: '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_EMULATE_PREPARES => false,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
if ((int) $db->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn() !== 0) {
    throw new RuntimeException('Owner-auth test requires an EMPTY disposable database');
}
$sql = file_get_contents(__DIR__ . '/../database/004_owner_auth.mysql.sql');
foreach (preg_split('/;\s*(?=\n|$)/', $sql) as $statement) { if (trim($statement) !== '') { $db->exec(trim($statement)); } }
$now = 1_800_000_000;
$clock = static function () use (&$now): int { return $now; };
$auth = new KamauPutt\Core\PdoOwnerAuth($db, random_bytes(32), random_bytes(32), $clock);
$email = 'dad@example.test';
$password = 'test-only-owner-password-123';
$secretText = KamauPutt\Core\PdoOwnerAuth::generateTotpSecret();
$decode = static function (string $text): string {
    $bits = '';
    foreach (str_split(rtrim(strtoupper($text), '=')) as $char) { $bits .= str_pad(decbin(strpos('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', $char)), 5, '0', STR_PAD_LEFT); }
    $out = '';
    foreach (str_split($bits, 8) as $byte) { if (strlen($byte) === 8) { $out .= chr(bindec($byte)); } }
    return $out;
};
$code = static function (string $key, int $time): string {
    $step = intdiv($time, 30);
    $hash = hash_hmac('sha1', pack('N2', intdiv($step, 0x100000000), $step % 0x100000000), $key, true);
    $offset = ord($hash[19]) & 15;
    $binary = ((ord($hash[$offset]) & 127) << 24) | ((ord($hash[$offset + 1]) & 255) << 16) | ((ord($hash[$offset + 2]) & 255) << 8) | (ord($hash[$offset + 3]) & 255);
    return str_pad((string) ($binary % 1_000_000), 6, '0', STR_PAD_LEFT);
};
$passed = 0;
$check = static function (string $label, bool $ok) use (&$passed): void { echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL; if (!$ok) { throw new RuntimeException('Failed owner-auth invariant: ' . $label); } $passed++; };
$auth->bootstrapOwner($email, $password, $secretText);
$check('password_and_totp_owner_bootstrap', (int) $db->query('SELECT COUNT(*) FROM kp_owner_accounts')->fetchColumn() === 1);
$wrongPassword = $auth->authenticate($email, 'wrong-password-value', '000000', '192.0.2.10');
$check('invalid_password_denied_generically', !$wrongPassword['authenticated'] && $wrongPassword['reason'] === 'invalid_credentials');
$secret = $decode($secretText);
$goodCode = $code($secret, $now);
$acceptedCodes = [$code($secret, $now - 30), $goodCode, $code($secret, $now + 30)];
$wrongCode = sprintf('%06d', ((int) $goodCode + 1) % 1_000_000);
while (in_array($wrongCode, $acceptedCodes, true)) { $wrongCode = sprintf('%06d', ((int) $wrongCode + 1) % 1_000_000); }
$badOtp = $auth->authenticate($email, $password, $wrongCode, '192.0.2.10');
$check('invalid_totp_denied', !$badOtp['authenticated']);
$login = $auth->authenticate($email, $password, $code($secret, $now), '192.0.2.10');
$check('valid_password_and_totp_login', $login['authenticated']);
$identity = $auth->validateSession($login['session_token'], $login['csrf_token']);
$check('opaque_session_and_csrf_valid', $identity === ['id' => 1, 'email' => $email]);
$check('wrong_csrf_denied', $auth->validateSession($login['session_token'], str_repeat('A', 43)) === null);
$replay = $auth->authenticate($email, $password, $code($secret, $now), '192.0.2.10');
$check('totp_step_cannot_be_replayed', !$replay['authenticated']);
$now += 1900;
$check('idle_session_expires', $auth->validateSession($login['session_token'], $login['csrf_token']) === null);
$newLogin = $auth->authenticate($email, $password, $code($secret, $now), '192.0.2.10');
$check('new_totp_step_authenticates', $newLogin['authenticated']);
$auth->revokeSession($newLogin['session_token']);
$check('revoked_session_denied', $auth->validateSession($newLogin['session_token'], $newLogin['csrf_token']) === null);
for ($i = 0; $i < 5; $i++) { $auth->authenticate($email, $password, $wrongCode, '192.0.2.99'); }
$limited = $auth->authenticate($email, $password, $code($secret, $now), '192.0.2.99');
$check('repeated_failures_are_rate_limited', !$limited['authenticated'] && $limited['reason'] === 'rate_limited');
$now += 43_201;
$absoluteLogin = $auth->authenticate($email, $password, $code($secret, $now), '192.0.2.10');
$check('absolute_session_ttl_starts_fresh', $absoluteLogin['authenticated']);
$now += 43_201;
$check('absolute_session_expiry', $auth->validateSession($absoluteLogin['session_token'], $absoluteLogin['csrf_token']) === null);
try { $auth->bootstrapOwner('second@example.test', $password, $secretText); $check('second_owner_bootstrap_blocked', false); }
catch (RuntimeException) { $check('second_owner_bootstrap_blocked', true); }
echo $passed . " owner auth cases passed\n";

