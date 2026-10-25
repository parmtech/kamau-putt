<?php

declare(strict_types=1);

namespace KamauPutt\Core;

use PDO;
use Throwable;

/**
 * AUD-only immutable double-entry posting foundation. No provider calls or
 * untrusted identity decisions occur here. All posts serialize on control row.
 */
final class PdoDoubleEntryLedger
{
    private const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    public function __construct(private readonly PDO $db)
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            throw new \RuntimeException('MySQL/MariaDB InnoDB required');
        }
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    /**
     * Each line: ['account'=>code,'debit_cents'=>int,'credit_cents'=>int,'memo'=>text].
     * The complete journal entry must balance before any row is written.
     * @return array{status:string,transaction_id:string}
     */
    public function post(string $key, string $sourceReference, string $actor, array $lines): array
    {
        return $this->transaction(function () use ($key, $sourceReference, $actor, $lines): array {
            $this->lockControl();
            return $this->postInTransaction($key, $sourceReference, $actor, $lines);
        });
    }

    /**
     * Compose a ledger post with an existing finance transaction (e.g. reserve
     * settlement). Caller must hold an active transaction and lock control row.
     */
    public function postInTransaction(string $key, string $sourceReference, string $actor, array $lines): array
    {
        if (!$this->db->inTransaction()) {
            throw new \LogicException('Ledger composition requires an active transaction');
        }
        $this->validateIdentity($key, $sourceReference, $actor);
        $normal = $this->normalizeLines($lines);
        $this->validateBalanced($normal);
        $requestHash = hash('sha256', json_encode([$sourceReference, $actor, $normal], JSON_THROW_ON_ERROR));

        $prior = $this->one('SELECT transaction_id, request_hash FROM kp_ledger_transactions WHERE idempotency_key=? FOR UPDATE', [$key]);
        if ($prior !== null) {
            if (!hash_equals((string) $prior['request_hash'], $requestHash)) {
                throw new \RuntimeException('Ledger idempotency conflict');
            }
            return ['status' => 'already_posted', 'transaction_id' => (string) $prior['transaction_id']];
        }
        $sameSource = $this->one('SELECT transaction_id FROM kp_ledger_transactions WHERE source_reference=? FOR UPDATE', [$sourceReference]);
        if ($sameSource !== null) {
            throw new \RuntimeException('Ledger source reference already posted with another key');
        }

        $codes = array_values(array_unique(array_column($normal, 'account')));
        $placeholders = implode(',', array_fill(0, count($codes), '?'));
        $accounts = $this->db->prepare("SELECT account_code,currency,is_active FROM kp_ledger_accounts WHERE account_code IN ($placeholders) FOR UPDATE");
        $accounts->execute($codes);
        $known = [];
        foreach ($accounts->fetchAll(PDO::FETCH_ASSOC) as $account) {
            $known[$account['account_code']] = $account;
        }
        foreach ($normal as $line) {
            $account = $known[$line['account']] ?? null;
            if ($account === null || !(bool) $account['is_active']) {
                throw new \RuntimeException('Unknown or inactive ledger account: ' . $line['account']);
            }
            if ($account['currency'] !== 'AUD') {
                throw new \RuntimeException('Only AUD ledger accounts are supported');
            }
        }

        $tail = $this->one('SELECT entry_hash FROM kp_ledger_transactions ORDER BY id DESC LIMIT 1 FOR UPDATE');
        $previous = $tail === null ? self::GENESIS : (string) $tail['entry_hash'];
        $id = $this->uuidV4();
        $hashMaterial = json_encode([$previous, $id, $key, $sourceReference, $actor, $normal], JSON_THROW_ON_ERROR);
        $entryHash = hash('sha256', $hashMaterial);
        $this->exec(
            'INSERT INTO kp_ledger_transactions(transaction_id,idempotency_key,source_reference,actor,request_hash,previous_hash,entry_hash) VALUES(?,?,?,?,?,?,?)',
            [$id, $key, $sourceReference, $actor, $requestHash, $previous, $entryHash]
        );
        foreach ($normal as $index => $line) {
            $this->exec(
                'INSERT INTO kp_ledger_entries(transaction_id,line_number,account_code,debit_cents,credit_cents,currency,memo) VALUES(?,?,?,?,?,?,?)',
                [$id, $index + 1, $line['account'], $line['debit_cents'], $line['credit_cents'], 'AUD', $line['memo']]
            );
        }
        return ['status' => 'posted', 'transaction_id' => $id];
    }

    /** Recomputes every transaction balance and the tamper-evident global chain. */
    public function verify(): array
    {
        $previous = self::GENESIS;
        $transactions = $this->db->query('SELECT * FROM kp_ledger_transactions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($transactions as $transaction) {
            $lines = $this->db->prepare('SELECT account_code AS account,debit_cents,credit_cents,memo FROM kp_ledger_entries WHERE transaction_id=? ORDER BY line_number');
            $lines->execute([$transaction['transaction_id']]);
            $storedLines = $lines->fetchAll(PDO::FETCH_ASSOC);
            foreach ($storedLines as &$storedLine) {
                $storedLine['debit_cents'] = $this->safeInt($storedLine['debit_cents']);
                $storedLine['credit_cents'] = $this->safeInt($storedLine['credit_cents']);
            }
            unset($storedLine);
            $normal = $this->normalizeLines($storedLines);
            $this->validateBalanced($normal);
            $expectedRequest = hash('sha256', json_encode([$transaction['source_reference'], $transaction['actor'], $normal], JSON_THROW_ON_ERROR));
            $expectedEntry = hash('sha256', json_encode([$previous, $transaction['transaction_id'], $transaction['idempotency_key'], $transaction['source_reference'], $transaction['actor'], $normal], JSON_THROW_ON_ERROR));
            if (!hash_equals($previous, $transaction['previous_hash']) ||
                !hash_equals($expectedRequest, $transaction['request_hash']) ||
                !hash_equals($expectedEntry, $transaction['entry_hash'])) {
                throw new \RuntimeException('Ledger chain or transaction integrity failure at ' . $transaction['transaction_id']);
            }
            $previous = $expectedEntry;
        }
        return ['transactions' => count($transactions), 'tail_hash' => $previous];
    }

    /** Debit-positive for asset/expense; credit-positive for liability/equity/income. */
    public function accountBalance(string $accountCode): int
    {
        $account = $this->one('SELECT account_type FROM kp_ledger_accounts WHERE account_code=?', [$accountCode]);
        if ($account === null) {
            throw new \RuntimeException('Unknown or inactive ledger account');
        }
        $totals = $this->one('SELECT COALESCE(SUM(e.debit_cents),0) AS debit_total, COALESCE(SUM(e.credit_cents),0) AS credit_total FROM kp_ledger_entries e WHERE e.account_code=?', [$accountCode]);
        $debit = $this->safeInt($totals['debit_total']);
        $credit = $this->safeInt($totals['credit_total']);
        $debitNormal = in_array($account['account_type'], ['asset', 'expense'], true);
        if ($debitNormal) {
            if ($credit > 0 && $debit < PHP_INT_MIN + $credit) { throw new \OverflowException('Ledger balance overflow'); }
            return $debit - $credit;
        }
        if ($debit > 0 && $credit < PHP_INT_MIN + $debit) { throw new \OverflowException('Ledger balance overflow'); }
        return $credit - $debit;
    }

    private function normalizeLines(array $lines): array
    {
        if (count($lines) < 2 || count($lines) > 100) {
            throw new \InvalidArgumentException('A posting requires 2 to 100 lines');
        }
        $normalized = [];
        $seenAccounts = [];
        foreach ($lines as $line) {
            if (!is_array($line) || !preg_match('/^[a-z][a-z0-9_]{1,63}$/D', (string) ($line['account'] ?? ''))) {
                throw new \InvalidArgumentException('Invalid ledger account code');
            }
            $account = (string) $line['account'];
            if (isset($seenAccounts[$account])) {
                throw new \InvalidArgumentException('Combine duplicate account lines before posting');
            }
            $seenAccounts[$account] = true;
            $debit = $line['debit_cents'] ?? null;
            $credit = $line['credit_cents'] ?? null;
            $memo = $line['memo'] ?? '';
            if (!is_int($debit) || !is_int($credit) || $debit < 0 || $credit < 0 ||
                $debit > PHP_INT_MAX || $credit > PHP_INT_MAX || !is_string($memo) || strlen($memo) > 255 ||
                (($debit > 0) === ($credit > 0))) {
                throw new \InvalidArgumentException('Each AUD line must have one positive integer-cent side');
            }
            $normalized[] = ['account' => $account, 'debit_cents' => $debit, 'credit_cents' => $credit, 'memo' => $memo];
        }
        usort($normalized, static fn(array $a, array $b): int => [$a['account'], $a['debit_cents'], $a['credit_cents'], $a['memo']] <=> [$b['account'], $b['debit_cents'], $b['credit_cents'], $b['memo']]);
        return $normalized;
    }

    private function validateBalanced(array $lines): void
    {
        $debits = 0;
        $credits = 0;
        foreach ($lines as $line) {
            if ($line['debit_cents'] > PHP_INT_MAX - $debits ||
                $line['credit_cents'] > PHP_INT_MAX - $credits) {
                throw new \OverflowException('Ledger posting total exceeds supported integer cents');
            }
            $debits += $line['debit_cents'];
            $credits += $line['credit_cents'];
        }
        if ($debits <= 0 || $debits !== $credits) {
            throw new \InvalidArgumentException('Ledger transaction must balance to a positive amount in AUD');
        }
    }

    private function validateIdentity(string $key, string $sourceReference, string $actor): void
    {
        if (!preg_match('/^[A-Za-z0-9._:-]{8,128}$/D', $key) ||
            !preg_match('/^[A-Za-z0-9._:-]{1,191}$/D', $sourceReference) ||
            !preg_match('/^[A-Za-z0-9._:@-]{1,128}$/D', $actor)) {
            throw new \InvalidArgumentException('Invalid ledger idempotency, source reference, or actor');
        }
    }

    private function lockControl(): void
    {
        $statement = $this->db->query('SELECT id FROM kp_finance_control WHERE id=1 FOR UPDATE');
        if ($statement->fetchColumn() === false) {
            throw new \RuntimeException('Finance control not initialized');
        }
    }

    private function one(string $sql, array $params = []): ?array
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function exec(string $sql, array $params): void
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        if ($statement->rowCount() !== 1) {
            throw new \RuntimeException('Ledger invariant failed');
        }
    }

    private function transaction(callable $operation): mixed
    {
        if ($this->db->inTransaction()) { throw new \RuntimeException('Use postInTransaction for composed postings'); }
        $this->db->beginTransaction();
        try { $result = $operation(); $this->db->commit(); return $result; }
        catch (Throwable $error) { if ($this->db->inTransaction()) { $this->db->rollBack(); } throw $error; }
    }

    private function safeInt(mixed $value): int
    {
        if (is_int($value)) { return $value; }
        $parsed = filter_var((string) $value, FILTER_VALIDATE_INT);
        if ($parsed === false) { throw new \OverflowException('Account total exceeds supported integer cents'); }
        return $parsed;
    }

    private function uuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }
}

