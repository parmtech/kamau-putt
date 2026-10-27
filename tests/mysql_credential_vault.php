<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/PdoOwnerAuth.php';
require_once __DIR__ . '/../src/Core/PdoCredentialVault.php';
require_once __DIR__ . '/../src/Http/OwnerAuthController.php';

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
    throw new RuntimeException('Credential-vault test requires an EMPTY disposable database');
}
foreach (['004_owner_auth.mysql.sql', '007_provider_credential_vault.mysql.sql'] as $migration) {
    $sql = file_get_contents(__DIR__ . '/../database/' . $migration);
    foreach (preg_split('/;\s*(?=\n|$)/', $sql) as $statement) { if (trim($statement) !== '') { $db->exec(trim($statement)); } }
}
$now = 1_800_000_000;
$clock = static function () use (&$now): int { return $now; };
$encryptionKey = random_bytes(32);
$auth = new KamauPutt\Core\PdoOwnerAuth($db, random_bytes(32), random_bytes(32), $clock);
$vault = new KamauPutt\Core\PdoCredentialVault($db, $encryptionKey, $clock);
$controller = new KamauPutt\Http\OwnerAuthController($auth, 'https://owner.example.test', null, $vault);
$email = 'dad@example.test';
$password = 'test-only-owner-password-123';
$secretText = KamauPutt\Core\PdoOwnerAuth::generateTotpSecret();
$auth->bootstrapOwner($email, $password, $secretText);
$bits = '';
foreach (str_split($secretText) as $char) { $bits .= str_pad(decbin(strpos('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', $char)), 5, '0', STR_PAD_LEFT); }
$totpSecret = '';
foreach (str_split($bits, 8) as $byte) { if (strlen($byte) === 8) { $totpSecret .= chr(bindec($byte)); } }
$totp = static function () use (&$now, $totpSecret): string {
    $step = intdiv($now, 30);
    $hash = hash_hmac('sha1', pack('N2', intdiv($step, 0x100000000), $step % 0x100000000), $totpSecret, true);
    $offset = ord($hash[19]) & 15;
    $number = ((ord($hash[$offset]) & 127) << 24) | ((ord($hash[$offset + 1]) & 255) << 16) | ((ord($hash[$offset + 2]) & 255) << 8) | (ord($hash[$offset + 3]) & 255);
    return str_pad((string) ($number % 1_000_000), 6, '0', STR_PAD_LEFT);
};
$passed = 0;
$check = static function (string $label, bool $ok) use (&$passed): void { echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL; if (!$ok) { throw new RuntimeException('Failed credential-vault invariant: ' . $label); } $passed++; };
$login = $controller->handle('POST', '/api/auth/login', 'https://owner.example.test', [], null, json_encode(['email' => $email, 'password' => $password, 'totp' => $totp()]), true, '192.0.2.21');
$cookies = ['__Host-kp_session' => $login['cookies']['__Host-kp_session']['value'], '__Host-kp_csrf' => $login['cookies']['__Host-kp_csrf']['value']];
$clearSecret = 'synthetic-provider-key-value-12345';
$store = $controller->handle('POST', '/api/vault/credentials', 'https://owner.example.test', $cookies, $cookies['__Host-kp_csrf'], json_encode(['provider' => 'openai', 'label' => 'test key', 'secret' => $clearSecret]), true, '192.0.2.21');
$credentialId = $store['body']['credential']['id'];
$check('key_is_stored_pending_and_never_returned', $store['status'] === 201 && $store['body']['credential']['status'] === 'pending' && !str_contains(json_encode($store['body']), $clearSecret));
$storedCiphertext = $db->query('SELECT secret_ciphertext FROM kp_provider_credentials WHERE id=' . (int) $credentialId)->fetchColumn();
$check('key_ciphertext_does_not_contain_clear_secret', is_string($storedCiphertext) && !str_contains($storedCiphertext, $clearSecret));
$storedFingerprint = $db->query('SELECT fingerprint_hash FROM kp_provider_credentials WHERE id=' . (int) $credentialId)->fetchColumn();
$check('key_fingerprint_is_keyed_and_does_not_expose_secret', is_string($storedFingerprint) && preg_match('/^[a-f0-9]{64}$/', $storedFingerprint) === 1 && !str_contains($storedFingerprint, $clearSecret));
$metadata = $controller->handle('GET', '/api/vault/credentials', null, $cookies, $cookies['__Host-kp_csrf'], '', true, '192.0.2.21');
$check('owner_listing_is_masked_and_pending', $metadata['status'] === 200 && $metadata['body']['credentials'][0]['masked_key'] === '••••2345' && $metadata['body']['credentials'][0]['status'] === 'pending' && !array_key_exists('secret', $metadata['body']['credentials'][0]));
$activationPath = '/api/vault/credentials/' . $credentialId . '/activate';
$now += 30; // TOTP replay prevention rejects reusing the login code for step-up authentication.
$blocked = $controller->handle('POST', $activationPath, 'https://owner.example.test', $cookies, $cookies['__Host-kp_csrf'], json_encode(['password' => $password, 'totp' => $totp(), 'confirmation' => 'ACTIVATE CREDENTIAL']), true, '192.0.2.21');
$check('activation_blocked_until_smoke_test_passes', $blocked['status'] === 409);
$vault->recordSmokeTest((int) $credentialId, false, 'test-request-0001', 100);
$failedMetadata = $vault->listForOwner($cookies['__Host-kp_session'], $cookies['__Host-kp_csrf']);
$check('failed_smoke_test_keeps_key_inactive', $failedMetadata[0]['status'] === 'pending' && $failedMetadata[0]['test_status'] === 'failed');
$vault->recordSmokeTest((int) $credentialId, true, 'test-request-0002', 98);
$metadataAfterTest = $vault->listForOwner($cookies['__Host-kp_session'], $cookies['__Host-kp_csrf']);
$check('passing_test_summary_is_visible_without_secret', $metadataAfterTest[0]['status'] === 'tested' && $metadataAfterTest[0]['test_status'] === 'passed' && $metadataAfterTest[0]['test_report']['latency_ms'] === 98);
$duplicate = $controller->handle('POST', '/api/vault/credentials', 'https://owner.example.test', $cookies, $cookies['__Host-kp_csrf'], json_encode(['provider' => 'openai', 'label' => 'duplicate', 'secret' => $clearSecret]), true, '192.0.2.21');
$check('duplicate_provider_key_rejected_without_database_error', $duplicate['status'] === 409 && $duplicate['body']['error'] === 'credential_not_stored');
$now += 301;
$stale = $controller->handle('POST', $activationPath, 'https://owner.example.test', $cookies, $cookies['__Host-kp_csrf'], json_encode(['password' => $password, 'totp' => $totp(), 'confirmation' => 'ACTIVATE CREDENTIAL']), true, '192.0.2.21');
$check('stale_smoke_test_or_session_cannot_activate', $stale['status'] === 409);
$now += 30;
$vault->recordSmokeTest((int) $credentialId, true, 'test-request-0003', 91);
$activation = $controller->handle('POST', $activationPath, 'https://owner.example.test', $cookies, $cookies['__Host-kp_csrf'], json_encode(['password' => $password, 'totp' => $totp(), 'confirmation' => 'ACTIVATE CREDENTIAL']), true, '192.0.2.21');
$check('fresh_mfa_and_confirmation_activate_key', $activation['status'] === 200 && $activation['body']['activated'] === true);
$freshCookies = ['__Host-kp_session' => $activation['cookies']['__Host-kp_session']['value'], '__Host-kp_csrf' => $activation['cookies']['__Host-kp_csrf']['value']];
$used = $vault->withActiveSecret((int) $credentialId, static fn(string $provider, string $secret): bool => $provider === 'openai' && hash_equals($clearSecret, $secret));
$check('trusted_adapter_can_decrypt_active_key', $used === true);
$invalidOrigin = $controller->handle('POST', '/api/vault/credentials/' . $credentialId . '/revoke', 'https://attacker.example', $freshCookies, $freshCookies['__Host-kp_csrf'], '{}', true, '192.0.2.21');
$check('revocation_rejects_wrong_origin', $invalidOrigin['status'] === 403);
$revoked = $controller->handle('POST', '/api/vault/credentials/' . $credentialId . '/revoke', 'https://owner.example.test', $freshCookies, $freshCookies['__Host-kp_csrf'], '{}', true, '192.0.2.21');
$check('owner_can_revoke_active_key', $revoked['status'] === 204 && $vault->listForOwner($freshCookies['__Host-kp_session'], $freshCookies['__Host-kp_csrf'])[0]['status'] === 'revoked');
$inactiveDenied = false;
try { $vault->withActiveSecret((int) $credentialId, static fn(string $provider, string $secret): string => $secret); }
catch (RuntimeException) { $inactiveDenied = true; }
$check('revoked_key_cannot_be_decrypted_for_use', $inactiveDenied);
$audit = $vault->verifyAudit();
$check('vault_lifecycle_audit_chain_verifies', $audit['events'] === 6 && preg_match('/^[a-f0-9]{64}$/', $audit['last_hash']) === 1);
$updateDenied = false;
try { $db->exec("UPDATE kp_credential_vault_events SET event_type='revoked' WHERE id=1"); }
catch (PDOException) { $updateDenied = true; }
$deleteDenied = false;
try { $db->exec('DELETE FROM kp_provider_credentials WHERE id=' . (int) $credentialId); }
catch (PDOException) { $deleteDenied = true; }
$auditDeleteDenied = false;
try { $db->exec('DELETE FROM kp_credential_vault_events WHERE id=1'); }
catch (PDOException) { $auditDeleteDenied = true; }
$check('credential_and_audit_rows_are_immutable', $updateDenied && $deleteDenied && $auditDeleteDenied && $vault->verifyAudit()['events'] === 6);
echo $passed . " credential vault cases passed\n";
