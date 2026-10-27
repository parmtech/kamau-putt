<?php

declare(strict_types=1);

namespace KamauPutt\Core;

use PDO;

/** Encrypted, bounded JSON payloads kept separate from durable queue metadata. */
final class PdoJobPayloadStore
{
    public const MAX_PAYLOAD_BYTES = 65_536;

    /** @param callable():int|null $clock Testable Unix clock. */
    public function __construct(private readonly PDO $db, private readonly string $encryptionKey, private readonly mixed $clock = null)
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') { throw new \RuntimeException('MySQL/MariaDB required'); }
        if (!function_exists('sodium_crypto_secretbox') || strlen($encryptionKey) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \InvalidArgumentException('Job payload encryption key must be exactly 32 bytes');
        }
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }

    /** Persist an encrypted JSON object and return its opaque reference for kp_durable_jobs. */
    public function store(array $payload): string
    {
        if ($payload === [] || array_is_list($payload)) { throw new \InvalidArgumentException('Job payload must be a non-empty JSON object'); }
        try { $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }
        catch (\JsonException $error) { throw new \InvalidArgumentException('Job payload is not valid JSON data', 0, $error); }
        if (strlen($json) > self::MAX_PAYLOAD_BYTES) { sodium_memzero($json); throw new \InvalidArgumentException('Job payload exceeds the 64 KiB limit'); }

        $reference = self::uuidV4();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $payloadKey = self::keyForReference($this->encryptionKey, $reference);
        try {
            $ciphertext = sodium_crypto_secretbox($json, $nonce, $payloadKey);
            $stmt = $this->db->prepare('INSERT INTO kp_job_payloads(payload_ref,payload_nonce,payload_ciphertext,created_epoch) VALUES(?,?,?,?)');
            $stmt->execute([$reference, $nonce, $ciphertext, $this->now()]);
        } finally { sodium_memzero($json); sodium_memzero($payloadKey); }
        return $reference;
    }

    /** Load only by opaque reference; authentication failure is fail-closed. */
    public function load(string $reference): array
    {
        if (!self::validUuid($reference)) { throw new \InvalidArgumentException('Job payload reference is invalid'); }
        $stmt = $this->db->prepare('SELECT payload_nonce,payload_ciphertext FROM kp_job_payloads WHERE payload_ref=?');
        $stmt->execute([$reference]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) { throw new \RuntimeException('Job payload is unavailable'); }
        $payloadKey = self::keyForReference($this->encryptionKey, $reference);
        try { $json = sodium_crypto_secretbox_open((string) $row['payload_ciphertext'], (string) $row['payload_nonce'], $payloadKey); }
        finally { sodium_memzero($payloadKey); }
        if (!is_string($json)) { throw new \RuntimeException('Job payload authentication failed: fail closed'); }
        try {
            $payload = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($payload) || $payload === [] || array_is_list($payload)) { throw new \RuntimeException('Stored job payload has invalid shape'); }
            return $payload;
        } finally { sodium_memzero($json); }
    }

    /** Delete payload only after its linked job reached a known terminal state. */
    public function purgeTerminal(string $reference): void
    {
        if (!self::validUuid($reference)) { throw new \InvalidArgumentException('Job payload reference is invalid'); }
        $this->db->beginTransaction();
        try {
            $jobs = $this->db->prepare('SELECT status FROM kp_durable_jobs WHERE payload_ref=? FOR UPDATE');
            $jobs->execute([$reference]);
            $linked = $jobs->fetchAll(PDO::FETCH_COLUMN);
            if ($linked === [] || array_diff($linked, ['completed', 'failed', 'canceled']) !== []) {
                throw new \RuntimeException('Payload is retained until its job has a known terminal outcome');
            }
            $delete = $this->db->prepare('DELETE FROM kp_job_payloads WHERE payload_ref=?');
            $delete->execute([$reference]);
            $this->db->commit();
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            throw $error;
        }
    }

    private function now(): int
    {
        $value = $this->clock === null ? time() : ($this->clock)();
        if (!is_int($value) || $value < 0) { throw new \RuntimeException('Invalid system clock'); }
        return $value;
    }

    private static function keyForReference(string $masterKey, string $reference): string
    {
        return hash_hkdf('sha256', $masterKey, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'kamau-putt/job-payload/v1', $reference);
    }

    private static function validUuid(string $id): bool { return preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/Di', $id) === 1; }
    private static function uuidV4(): string
    {
        $bytes = random_bytes(16); $bytes[6] = chr((ord($bytes[6]) & 15) | 64); $bytes[8] = chr((ord($bytes[8]) & 63) | 128); $hex = bin2hex($bytes);
        return sprintf('%s-%s-%s-%s-%s', substr($hex,0,8),substr($hex,8,4),substr($hex,12,4),substr($hex,16,4),substr($hex,20));
    }
}
