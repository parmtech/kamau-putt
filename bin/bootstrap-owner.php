<?php

declare(strict_types=1);

use KamauPutt\Core\PdoOwnerAuth;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require_once __DIR__ . '/../src/Core/PdoOwnerAuth.php';

$required = ['KP_MYSQL_DSN', 'KP_MYSQL_USER', 'KP_MYSQL_PASSWORD', 'KP_OWNER_EMAIL', 'KP_OWNER_PASSWORD', 'KP_OWNER_TOTP_SECRET', 'KP_MFA_ENCRYPTION_KEY', 'KP_AUTH_RATE_LIMIT_KEY'];
$values = [];
foreach ($required as $name) {
    $value = getenv($name);
    if (!is_string($value) || $value === '') {
        fwrite(STDERR, "Missing required environment setting: {$name}\n");
        exit(2);
    }
    $values[$name] = $value;
}

$encryptionKey = base64_decode($values['KP_MFA_ENCRYPTION_KEY'], true);
$rateLimitKey = base64_decode($values['KP_AUTH_RATE_LIMIT_KEY'], true);
if (!function_exists('sodium_crypto_secretbox') || !is_string($encryptionKey) || strlen($encryptionKey) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES || !is_string($rateLimitKey) || strlen($rateLimitKey) < 32) {
    fwrite(STDERR, "Authentication key configuration is invalid\n");
    exit(2);
}

try {
    $db = new PDO($values['KP_MYSQL_DSN'], $values['KP_MYSQL_USER'], $values['KP_MYSQL_PASSWORD'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    (new PdoOwnerAuth($db, $encryptionKey, $rateLimitKey))->bootstrapOwner(
        $values['KP_OWNER_EMAIL'],
        $values['KP_OWNER_PASSWORD'],
        $values['KP_OWNER_TOTP_SECRET'],
    );
    fwrite(STDOUT, "Owner account provisioned. Store the TOTP secret in an authenticator, then remove bootstrap secrets from the environment.\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'Owner provisioning failed: ' . $error->getMessage() . "\n");
    exit(1);
}
