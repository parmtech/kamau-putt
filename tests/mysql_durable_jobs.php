<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Core/PdoOwnerAuth.php';
require_once __DIR__ . '/../src/Core/PdoOwnerControl.php';
require_once __DIR__ . '/../src/Core/PdoSpendReservations.php';
require_once __DIR__ . '/../src/Core/PdoDurableJobs.php';

if (PHP_SAPI !== 'cli' || getenv('KP_TEST_ALLOW_DB_WRITE') !== 'I_UNDERSTAND_STAGING_ONLY') { fwrite(STDERR, "SKIP: explicit disposable database confirmation required\n"); exit(2); }
$dsn = getenv('KP_TEST_MYSQL_DSN');
if (!is_string($dsn) || !str_starts_with($dsn, 'mysql:') || !extension_loaded('pdo_mysql')) { fwrite(STDERR, "SKIP: configured MySQL PDO driver required\n"); exit(2); }
$db = new PDO($dsn, getenv('KP_TEST_MYSQL_USER') ?: '', getenv('KP_TEST_MYSQL_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
if ((int) $db->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE()')->fetchColumn() !== 0) { throw new RuntimeException('Durable-jobs test requires an EMPTY disposable database'); }
foreach (['001_financial_control.mysql.sql','002_double_entry_ledger.mysql.sql','004_owner_auth.mysql.sql','005_owner_control.mysql.sql','006_owner_control_audit.mysql.sql','008_durable_jobs.mysql.sql'] as $migration) {
    $sql = file_get_contents(__DIR__ . '/../database/' . $migration);
    foreach (preg_split('/;\s*(?=\n|$)/', $sql) as $statement) { if (trim($statement) !== '') { $db->exec(trim($statement)); } }
}
$now = 1_800_000_000;
$clock = static function () use (&$now): int { return $now; };
$auth = new KamauPutt\Core\PdoOwnerAuth($db, random_bytes(32), random_bytes(32), $clock);
$auth->bootstrapOwner('dad@example.test', 'test-only-owner-password-123', KamauPutt\Core\PdoOwnerAuth::generateTotpSecret());
$db->exec("UPDATE kp_finance_control SET operating_mode='growth',owner_emergency_stopped=0,dispatch_epoch=1 WHERE id=1");
$db->exec("INSERT INTO kp_budget_envelopes(scope,approved_limit_cents,per_action_cap_cents) VALUES('ai_api',5000,1000)");
$db->exec('UPDATE kp_financial_position SET settled_cash_cents=10000 WHERE id=1');
$reservations = new KamauPutt\Core\PdoSpendReservations($db);
$jobs = new KamauPutt\Core\PdoDurableJobs($db, $clock);
$control = new KamauPutt\Core\PdoOwnerControl($db, $clock);
$passed = 0;
$check = static function (string $label, bool $ok) use (&$passed): void { echo ($ok ? 'PASS ' : 'FAIL ') . $label . PHP_EOL; if (!$ok) { throw new RuntimeException('Failed durable-job invariant: ' . $label); } $passed++; };
$makeReservation = static function (int $n) use ($reservations): array { return $reservations->reserve(sprintf('job-reserve-%04d', $n), 'ai_api', 'ai_call', 100, 1000, true); };
$state = static function (string $jobId) use ($db): array { $stmt=$db->prepare('SELECT status,attempt_count FROM kp_durable_jobs WHERE id=?');$stmt->execute([$jobId]);return $stmt->fetch(PDO::FETCH_ASSOC); };

$check('reservation_allows_durable_job_intent', $makeReservation(1)['allowed']);
$queued = $jobs->enqueue('job-reserve-0001', 'job-key-00000001', 'payload-ref:00000001');
$replay = $jobs->enqueue('job-reserve-0001', 'job-key-00000001', 'payload-ref:00000001');
$check('enqueue_is_idempotent_and_stores_opaque_reference', $queued['status'] === 'queued' && !$queued['idempotent'] && $replay['idempotent']);
$lease = $jobs->claimNext();
$check('worker_claim_issues_one_time_lease_and_stable_provider_key', is_array($lease) && strlen($lease['lease_token']) === 43 && str_starts_with($lease['provider_idempotency_key'], 'kp-'));
$accepted = $jobs->dispatch($lease['id'], $lease['lease_token'], static fn(array $request): array => ['outcome' => 'accepted', 'reference' => 'provider-ref-accepted-0001']);
$check('accepted_outcome_completes_job_and_posts_ledger_atomically', $accepted['status'] === 'completed' && $state($lease['id'])['status'] === 'completed' && $reservations->commit('job-reserve-0001', 'provider-ref-accepted-0001') === 'idempotent_committed');
$check('lease_cannot_be_replayed_after_completion', $jobs->dispatch($lease['id'], $lease['lease_token'], static fn(): array => ['outcome' => 'accepted', 'reference' => 'provider-ref-replay-0001'])['reason'] === 'lease_unavailable');

$check('second_reservation_is_created', $makeReservation(2)['allowed']);
$jobs->enqueue('job-reserve-0002', 'job-key-00000002', 'payload-ref:00000002');
$unknownLease = $jobs->claimNext();
$unknown = $jobs->dispatch($unknownLease['id'], $unknownLease['lease_token'], static fn(): array => ['outcome' => 'unknown']);
$check('unknown_provider_result_keeps_reservation_uncertain', $unknown['status'] === 'uncertain' && $reservations->markUncertain('job-reserve-0002') === 'idempotent_uncertain');
$check('uncertain_work_is_not_automatically_retried', $jobs->claimNext() === null && $state($unknownLease['id'])['attempt_count'] === 1);

$check('third_reservation_is_created', $makeReservation(3)['allowed']);
$jobs->enqueue('job-reserve-0003', 'job-key-00000003', 'payload-ref:00000003');
$control->emergencyStop();
$check('owner_stop_cancels_queued_jobs_atomically', $state($queued['id'])['status'] === 'completed' && $state((string) $db->query("SELECT id FROM kp_durable_jobs WHERE job_key='job-key-00000003'")->fetchColumn())['status'] === 'canceled');
$summary = $control->summary();
$check('stop_summary_counts_only_open_or_uncertain_work', $summary['emergency_stopped'] && $summary['job_queue_actions'] === 1);

$db->exec('UPDATE kp_finance_control SET owner_emergency_stopped=0,dispatch_epoch=dispatch_epoch+1 WHERE id=1'); // isolated test fixture: owner-approved resume is covered separately
$check('fourth_reservation_is_created_after_test_resume', $makeReservation(4)['allowed']);
$jobs->enqueue('job-reserve-0004', 'job-key-00000004', 'payload-ref:00000004');
$cancelLease = $jobs->claimNext();
$control->emergencyStop();
$check('stop_fences_a_claimed_but_not_dispatched_job', $state($cancelLease['id'])['status'] === 'cancel_requested' && $jobs->dispatch($cancelLease['id'], $cancelLease['lease_token'], static fn(): array => ['outcome' => 'accepted', 'reference' => 'never-call-0004'])['reason'] === 'lease_unavailable');
$now += 31;
$check('expired_cancelled_lease_becomes_uncertain_without_retry', $jobs->claimNext() === null && $state($cancelLease['id'])['status'] === 'uncertain' && $reservations->markUncertain('job-reserve-0004') === 'idempotent_uncertain');

$db->exec('UPDATE kp_finance_control SET owner_emergency_stopped=0,dispatch_epoch=dispatch_epoch+1 WHERE id=1');
$check('fifth_reservation_is_created_for_verified_no_effect_path', $makeReservation(5)['allowed']);
$jobs->enqueue('job-reserve-0005', 'job-key-00000005', 'payload-ref:00000005');
$noEffectLease = $jobs->claimNext();
$noEffect = $jobs->dispatch($noEffectLease['id'], $noEffectLease['lease_token'], static fn(): array => ['outcome' => 'no_effect']);
$check('verified_no_effect_releases_budget_and_closes_job', $noEffect['status'] === 'failed_no_effect' && $state($noEffectLease['id'])['status'] === 'failed' && $reservations->release('job-reserve-0005') === 'idempotent_released');
$audit = $jobs->verifyAudit();
$check('durable_job_hash_chain_verifies', $audit['events'] === 15 && preg_match('/^[a-f0-9]{64}$/', $audit['last_hash']) === 1);
$eventUpdateDenied = false;
try { $db->exec("UPDATE kp_durable_job_events SET event_type='queued' WHERE id=1"); } catch (PDOException) { $eventUpdateDenied = true; }
$check('durable_job_events_are_append_only', $eventUpdateDenied && $jobs->verifyAudit()['events'] === 15);
echo $passed . " durable job cases passed\n";
