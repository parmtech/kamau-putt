<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/PdoOwnerAuth.php';
require_once __DIR__ . '/../src/Core/PdoDispatchGate.php';
require_once __DIR__ . '/../src/Core/PdoOwnerControl.php';
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
    throw new RuntimeException('Owner-control test requires an EMPTY disposable database');
}
foreach (['001_financial_control.mysql.sql', '004_owner_auth.mysql.sql', '005_owner_control.mysql.sql', '006_owner_control_audit.mysql.sql'] as $migration) {
    $sql = file_get_contents(__DIR__ . '/../database/' . $migration);
    foreach (preg_split('/;\s*(?=\n|$)/', $sql) as $statement) { if (trim($statement) !== '') { $db->exec(trim($statement)); } }
}
$now = 1_800_000_000;
$clock = static function () use (&$now): int { return $now; };
$auth = new KamauPutt\Core\PdoOwnerAuth($db, random_bytes(32), random_bytes(32), $clock);
$control = new KamauPutt\Core\PdoOwnerControl($db, $clock);
$controller = new KamauPutt\Http\OwnerAuthController($auth, 'https://owner.example.test', $control);
$email = 'dad@example.test';
$password = 'test-only-owner-password-123';
$secretText = KamauPutt\Core\PdoOwnerAuth::generateTotpSecret();
$auth->bootstrapOwner($email, $password, $secretText);
$bits = '';
foreach (str_split($secretText) as $char) { $bits .= str_pad(decbin(strpos('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', $char)), 5, '0', STR_PAD_LEFT); }
$secret = '';
foreach (str_split($bits, 8) as $byte) { if (strlen($byte) === 8) { $secret .= chr(bindec($byte)); } }
$totp = static function () use (&$now, $secret): string {
    $step = intdiv($now, 30);
    $hash = hash_hmac('sha1', pack('N2', intdiv($step, 0x100000000), $step % 0x100000000), $secret, true);
    $offset = ord($hash[19]) & 15;
    $number = ((ord($hash[$offset]) & 127) << 24) | ((ord($hash[$offset + 1]) & 255) << 16) | ((ord($hash[$offset + 2]) & 255) << 8) | (ord($hash[$offset + 3]) & 255);
    return str_pad((string) ($number % 1_000_000), 6, '0', STR_PAD_LEFT);
};
$passed = 0;
$check = static function (string $label, bool $ok) use (&$passed): void { echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL; if (!$ok) { throw new RuntimeException('Failed owner-control invariant: ' . $label); } $passed++; };
$initial = $control->summary();
$check('new_control_state_is_stopped_and_unfunded', $initial['emergency_stopped'] && $initial['settled_cash_cents'] === 0 && $initial['operating_mode'] === 'survival');
$db->exec('UPDATE kp_finance_control SET owner_emergency_stopped=0 WHERE id=1'); // test-only fixture for the active-to-stopped transition
$login = $controller->handle('POST', '/api/auth/login', 'https://owner.example.test', [], null, json_encode(['email' => $email, 'password' => $password, 'totp' => $totp()]), true, '192.0.2.11');
$check('owner_can_log_in_before_control_actions', $login['status'] === 200);
$cookies = ['__Host-kp_session' => $login['cookies']['__Host-kp_session']['value'], '__Host-kp_csrf' => $login['cookies']['__Host-kp_csrf']['value']];
$status = $controller->handle('GET', '/api/control/status', null, $cookies, $cookies['__Host-kp_csrf'], '', true, '192.0.2.11');
$check('status_requires_owner_session_and_reports_snapshot', $status['status'] === 200 && $status['body']['control']['job_queue_actions'] === 0);
$badStopCsrf = $controller->handle('POST', '/api/control/stop', 'https://owner.example.test', $cookies, str_repeat('A', 43), '{}', true, '192.0.2.11');
$check('stop_rejects_wrong_csrf', $badStopCsrf['status'] === 401);
$stop = $controller->handle('POST', '/api/control/stop', 'https://owner.example.test', $cookies, $cookies['__Host-kp_csrf'], '{}', true, '192.0.2.11');
$stopEpoch = $stop['body']['control']['dispatch_epoch'];
$check('authenticated_stop_persists_and_advances_epoch', $stop['status'] === 200 && $stop['body']['control']['emergency_stopped'] && $stopEpoch === 1);
$sameStop = $control->emergencyStop();
$check('repeated_stop_is_idempotent', $sameStop['dispatch_epoch'] === $stopEpoch);
$db->exec("INSERT INTO kp_budget_envelopes(scope,approved_limit_cents,per_action_cap_cents) VALUES ('control-test',1000,500)");
$db->exec("INSERT INTO kp_spend_reservations(id,idempotency_key,request_hash,scope,action_type,amount_cents,status) VALUES ('00000000-0000-4000-8000-000000000001','owner-control-pending-0001',REPEAT('a',64),'control-test','supplier_order',125,'reserved')");
$db->exec('UPDATE kp_financial_position SET discretionary_reserved_cents=125 WHERE id=1');
$now += 30;
$challengeResponse = $controller->handle('POST', '/api/control/resume/challenge', 'https://owner.example.test', $cookies, $cookies['__Host-kp_csrf'], json_encode(['password' => $password, 'totp' => $totp()]), true, '192.0.2.11');
$check('resume_challenge_requires_fresh_password_and_totp', $challengeResponse['status'] === 201 && $challengeResponse['body']['confirmation_phrase'] === 'RESUME KAMAU PUTT' && $challengeResponse['body']['control']['open_reservation_count'] === 1 && $challengeResponse['body']['control']['discretionary_reserved_cents'] === 125);
$newCookies = ['__Host-kp_session' => $challengeResponse['cookies']['__Host-kp_session']['value'], '__Host-kp_csrf' => $challengeResponse['cookies']['__Host-kp_csrf']['value']];
$challenge = $challengeResponse['body']['challenge'];
$wrongPhrase = $controller->handle('POST', '/api/control/resume/confirm', 'https://owner.example.test', $newCookies, $newCookies['__Host-kp_csrf'], json_encode(['challenge' => $challenge, 'confirmation' => 'yes']), true, '192.0.2.11');
$check('resume_requires_exact_explicit_phrase', $wrongPhrase['status'] === 409 && $control->summary()['emergency_stopped']);
$resumed = $controller->handle('POST', '/api/control/resume/confirm', 'https://owner.example.test', $newCookies, $newCookies['__Host-kp_csrf'], json_encode(['challenge' => $challenge, 'confirmation' => 'RESUME KAMAU PUTT']), true, '192.0.2.11');
$check('confirmed_resume_advances_epoch_and_keeps_survival_mode', $resumed['status'] === 200 && !$resumed['body']['control']['emergency_stopped'] && $resumed['body']['control']['dispatch_epoch'] === $stopEpoch + 1 && $resumed['body']['control']['operating_mode'] === 'survival');
$replay = $controller->handle('POST', '/api/control/resume/confirm', 'https://owner.example.test', $newCookies, $newCookies['__Host-kp_csrf'], json_encode(['challenge' => $challenge, 'confirmation' => 'RESUME KAMAU PUTT']), true, '192.0.2.11');
$check('resume_challenge_cannot_be_replayed', $replay['status'] === 409);

$control->emergencyStop();
$now += 30;
$freshChallenge = $controller->handle('POST', '/api/control/resume/challenge', 'https://owner.example.test', $newCookies, $newCookies['__Host-kp_csrf'], json_encode(['password' => $password, 'totp' => $totp()]), true, '192.0.2.11');
$freshCookies = ['__Host-kp_session' => $freshChallenge['cookies']['__Host-kp_session']['value'], '__Host-kp_csrf' => $freshChallenge['cookies']['__Host-kp_csrf']['value']];
$db->exec('UPDATE kp_financial_position SET settled_cash_cents=1 WHERE id=1');
$stale = $controller->handle('POST', '/api/control/resume/confirm', 'https://owner.example.test', $freshCookies, $freshCookies['__Host-kp_csrf'], json_encode(['challenge' => $freshChallenge['body']['challenge'], 'confirmation' => 'RESUME KAMAU PUTT']), true, '192.0.2.11');
$check('resume_challenge_rejects_changed_financial_summary', $stale['status'] === 409 && $control->summary()['emergency_stopped']);
$db->exec("UPDATE kp_finance_control SET operating_mode='shutdown' WHERE id=1");
$shutdownDenied = false;
try { $control->prepareResume($freshCookies['__Host-kp_session'], $freshCookies['__Host-kp_csrf']); }
catch (RuntimeException) { $shutdownDenied = true; }
$check('safe_shutdown_does_not_resume_through_normal_route', $shutdownDenied && $control->summary()['emergency_stopped']);
$audit = $control->verifyAudit();
$check('stop_and_resume_events_have_valid_hash_chain', $audit['events'] === 6 && preg_match('/^[a-f0-9]{64}$/', $audit['last_hash']) === 1);
$updateDenied = false;
try { $db->exec("UPDATE kp_owner_control_audit SET event_type='stop_applied' WHERE id=1"); }
catch (PDOException) { $updateDenied = true; }
$check('audit_update_is_rejected', $updateDenied);
$deleteDenied = false;
try { $db->exec('DELETE FROM kp_owner_control_audit WHERE id=1'); }
catch (PDOException) { $deleteDenied = true; }
$check('audit_delete_is_rejected', $deleteDenied && $control->verifyAudit()['events'] === 6);

echo $passed . " owner control cases passed\n";
