<?php

declare(strict_types=1);

namespace KamauPutt\Http;

use KamauPutt\Core\PdoOwnerAuth;
use KamauPutt\Core\PdoOwnerControl;
use KamauPutt\Core\PdoCredentialVault;

/** Same-origin JSON controller for the owner-auth core; no CORS or registration. */
final class OwnerAuthController
{
    private const SESSION_COOKIE = '__Host-kp_session';
    private const CSRF_COOKIE = '__Host-kp_csrf';
    private const SESSION_MAX_AGE = 43200;

    public function __construct(private readonly PdoOwnerAuth $auth, private readonly string $allowedOrigin, private readonly ?PdoOwnerControl $control = null, private readonly ?PdoCredentialVault $vault = null)
    {
        $parts = parse_url($allowedOrigin);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || !in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new \InvalidArgumentException('Allowed owner origin must be one HTTPS origin');
        }
    }

    /** @return array{status:int,headers:array<string,string>,cookies:array<string,array<string,mixed>>,body:array<string,mixed>} */
    public function handle(
        string $method,
        string $path,
        ?string $origin,
        array $cookies,
        ?string $csrfHeader,
        string $body,
        bool $secureTransport,
        string $trustedRemoteAddress,
    ): array {
        if (!$secureTransport) { return $this->reply(400, ['error' => 'https_required']); }
        $method = strtoupper($method);
        if ($path === '/api/auth/login' && $method === 'POST') {
            if (!$this->validOrigin($origin)) { return $this->reply(403, ['error' => 'origin_denied']); }
            $payload = json_decode($body, true);
            if (!is_array($payload) || !is_string($payload['email'] ?? null) || !is_string($payload['password'] ?? null) || !is_string($payload['totp'] ?? null)) {
                return $this->reply(400, ['error' => 'invalid_request']);
            }
            $result = $this->auth->authenticate($payload['email'], $payload['password'], $payload['totp'], $trustedRemoteAddress);
            if (!$result['authenticated']) {
                return $this->reply($result['reason'] === 'rate_limited' ? 429 : 401, ['error' => $result['reason']]);
            }
            return $this->reply(200, ['authenticated' => true], [
                self::SESSION_COOKIE => $this->cookie($result['session_token'], true, self::SESSION_MAX_AGE),
                self::CSRF_COOKIE => $this->cookie($result['csrf_token'], false, self::SESSION_MAX_AGE),
            ]);
        }

        if ($path === '/api/auth/session' && $method === 'GET') {
            $identity = $this->identity($cookies, $csrfHeader);
            return $identity === null
                ? $this->reply(401, ['authenticated' => false])
                : $this->reply(200, ['authenticated' => true, 'email' => $identity['email']]);
        }

        if ($path === '/api/auth/logout' && $method === 'POST') {
            if (!$this->validOrigin($origin)) { return $this->reply(403, ['error' => 'origin_denied']); }
            if ($this->identity($cookies, $csrfHeader) === null) { return $this->reply(401, ['error' => 'authentication_required']); }
            $this->auth->revokeSession((string) $cookies[self::SESSION_COOKIE]);
            return $this->reply(204, [], [
                self::SESSION_COOKIE => $this->cookie('', true, 0),
                self::CSRF_COOKIE => $this->cookie('', false, 0),
            ]);
        }

        if ($path === '/api/control/status' && $method === 'GET') {
            if ($this->control === null) { return $this->reply(503, ['error' => 'control_unavailable']); }
            if ($this->identity($cookies, $csrfHeader) === null) { return $this->reply(401, ['authenticated' => false]); }
            return $this->reply(200, ['control' => $this->control->summary()]);
        }

        if ($path === '/api/control/stop' && $method === 'POST') {
            if (!$this->validOrigin($origin)) { return $this->reply(403, ['error' => 'origin_denied']); }
            if ($this->control === null) { return $this->reply(503, ['error' => 'control_unavailable']); }
            if ($this->identity($cookies, $csrfHeader) === null) { return $this->reply(401, ['error' => 'authentication_required']); }
            return $this->reply(200, ['control' => $this->control->emergencyStop()]);
        }

        if ($path === '/api/control/resume/challenge' && $method === 'POST') {
            if (!$this->validOrigin($origin)) { return $this->reply(403, ['error' => 'origin_denied']); }
            if ($this->control === null) { return $this->reply(503, ['error' => 'control_unavailable']); }
            $identity = $this->identity($cookies, $csrfHeader);
            if ($identity === null) { return $this->reply(401, ['error' => 'authentication_required']); }
            $payload = json_decode($body, true);
            if (!is_array($payload) || !is_string($payload['password'] ?? null) || !is_string($payload['totp'] ?? null)) {
                return $this->reply(400, ['error' => 'invalid_request']);
            }
            $stepUp = $this->auth->authenticate($identity['email'], $payload['password'], $payload['totp'], $trustedRemoteAddress);
            if (!$stepUp['authenticated']) { return $this->reply($stepUp['reason'] === 'rate_limited' ? 429 : 401, ['error' => $stepUp['reason']]); }
            try {
                $challenge = $this->control->prepareResume($stepUp['session_token'], $stepUp['csrf_token']);
            } catch (\PDOException $error) {
                $this->auth->revokeSession($stepUp['session_token']);
                throw $error;
            } catch (\RuntimeException) {
                $this->auth->revokeSession($stepUp['session_token']);
                return $this->reply(409, ['error' => 'resume_not_available']);
            }
            return $this->reply(201, [
                'challenge' => $challenge['token'],
                'expires_epoch' => $challenge['expires_epoch'],
                'confirmation_phrase' => 'RESUME KAMAU PUTT',
                'control' => $challenge['summary'],
            ], [
                self::SESSION_COOKIE => $this->cookie($stepUp['session_token'], true, self::SESSION_MAX_AGE),
                self::CSRF_COOKIE => $this->cookie($stepUp['csrf_token'], false, self::SESSION_MAX_AGE),
            ]);
        }

        if ($path === '/api/control/resume/confirm' && $method === 'POST') {
            if (!$this->validOrigin($origin)) { return $this->reply(403, ['error' => 'origin_denied']); }
            if ($this->control === null) { return $this->reply(503, ['error' => 'control_unavailable']); }
            if ($this->identity($cookies, $csrfHeader) === null) { return $this->reply(401, ['error' => 'authentication_required']); }
            $payload = json_decode($body, true);
            if (!is_array($payload) || !is_string($payload['challenge'] ?? null) || !is_string($payload['confirmation'] ?? null)) {
                return $this->reply(400, ['error' => 'invalid_request']);
            }
            try {
                $control = $this->control->confirmResume($cookies[self::SESSION_COOKIE], $csrfHeader, $payload['challenge'], $payload['confirmation']);
            } catch (\PDOException $error) {
                throw $error;
            } catch (\RuntimeException) {
                return $this->reply(409, ['error' => 'resume_not_confirmed']);
            }
            return $this->reply(200, ['control' => $control]);
        }

        if ($path === '/api/vault/credentials' && $method === 'GET') {
            if ($this->vault === null) { return $this->reply(503, ['error' => 'vault_unavailable']); }
            if ($this->identity($cookies, $csrfHeader) === null) { return $this->reply(401, ['authenticated' => false]); }
            return $this->reply(200, ['credentials' => $this->vault->listForOwner($cookies[self::SESSION_COOKIE], $csrfHeader)]);
        }

        if ($path === '/api/vault/credentials' && $method === 'POST') {
            if (!$this->validOrigin($origin)) { return $this->reply(403, ['error' => 'origin_denied']); }
            if ($this->vault === null) { return $this->reply(503, ['error' => 'vault_unavailable']); }
            if ($this->identity($cookies, $csrfHeader) === null) { return $this->reply(401, ['error' => 'authentication_required']); }
            $payload = json_decode($body, true);
            if (!is_array($payload) || !is_string($payload['provider'] ?? null) || !is_string($payload['label'] ?? null) || !is_string($payload['secret'] ?? null)) {
                return $this->reply(400, ['error' => 'invalid_request']);
            }
            try {
                $credential = $this->vault->storePending($cookies[self::SESSION_COOKIE], $csrfHeader, $payload['provider'], $payload['label'], $payload['secret']);
            } catch (\PDOException $error) {
                throw $error;
            } catch (\InvalidArgumentException) {
                return $this->reply(400, ['error' => 'invalid_request']);
            } catch (\RuntimeException) {
                return $this->reply(409, ['error' => 'credential_not_stored']);
            }
            return $this->reply(201, ['credential' => $credential]);
        }

        if (preg_match('#^/api/vault/credentials/([1-9][0-9]*)/activate$#D', $path, $match) === 1 && $method === 'POST') {
            if (!$this->validOrigin($origin)) { return $this->reply(403, ['error' => 'origin_denied']); }
            if ($this->vault === null) { return $this->reply(503, ['error' => 'vault_unavailable']); }
            $identity = $this->identity($cookies, $csrfHeader);
            if ($identity === null) { return $this->reply(401, ['error' => 'authentication_required']); }
            $payload = json_decode($body, true);
            if (!is_array($payload) || !is_string($payload['password'] ?? null) || !is_string($payload['totp'] ?? null) || !is_string($payload['confirmation'] ?? null)) {
                return $this->reply(400, ['error' => 'invalid_request']);
            }
            if ($payload['confirmation'] !== 'ACTIVATE CREDENTIAL') { return $this->reply(409, ['error' => 'explicit_confirmation_required']); }
            $stepUp = $this->auth->authenticate($identity['email'], $payload['password'], $payload['totp'], $trustedRemoteAddress);
            if (!$stepUp['authenticated']) { return $this->reply($stepUp['reason'] === 'rate_limited' ? 429 : 401, ['error' => $stepUp['reason']]); }
            try {
                $this->vault->activate($stepUp['session_token'], $stepUp['csrf_token'], (int) $match[1], $payload['confirmation']);
            } catch (\PDOException $error) {
                $this->auth->revokeSession($stepUp['session_token']);
                throw $error;
            } catch (\RuntimeException) {
                $this->auth->revokeSession($stepUp['session_token']);
                return $this->reply(409, ['error' => 'credential_activation_denied']);
            }
            return $this->reply(200, ['activated' => true], [
                self::SESSION_COOKIE => $this->cookie($stepUp['session_token'], true, self::SESSION_MAX_AGE),
                self::CSRF_COOKIE => $this->cookie($stepUp['csrf_token'], false, self::SESSION_MAX_AGE),
            ]);
        }

        if (preg_match('#^/api/vault/credentials/([1-9][0-9]*)/revoke$#D', $path, $match) === 1 && $method === 'POST') {
            if (!$this->validOrigin($origin)) { return $this->reply(403, ['error' => 'origin_denied']); }
            if ($this->vault === null) { return $this->reply(503, ['error' => 'vault_unavailable']); }
            if ($this->identity($cookies, $csrfHeader) === null) { return $this->reply(401, ['error' => 'authentication_required']); }
            try {
                $this->vault->revoke($cookies[self::SESSION_COOKIE], $csrfHeader, (int) $match[1]);
            } catch (\PDOException $error) {
                throw $error;
            } catch (\RuntimeException) {
                return $this->reply(409, ['error' => 'credential_revocation_denied']);
            }
            return $this->reply(204, []);
        }

        if (in_array($path, ['/api/auth/login', '/api/auth/session', '/api/auth/logout'], true)) {
            $allow = $path === '/api/auth/session' ? 'GET' : 'POST';
            return $this->reply(405, ['error' => 'method_not_allowed'], [], ['Allow' => $allow]);
        }
        if (in_array($path, ['/api/control/status', '/api/control/stop', '/api/control/resume/challenge', '/api/control/resume/confirm'], true)) {
            return $this->reply(405, ['error' => 'method_not_allowed'], [], ['Allow' => $path === '/api/control/status' ? 'GET' : 'POST']);
        }
        if (preg_match('#^/api/vault/credentials(?:/[1-9][0-9]*/(?:activate|revoke))?$#D', $path) === 1) {
            return $this->reply(405, ['error' => 'method_not_allowed'], [], ['Allow' => $path === '/api/vault/credentials' ? 'GET, POST' : 'POST']);
        }
        return $this->reply(404, ['error' => 'not_found']);
    }

    private function identity(array $cookies, ?string $csrfHeader): ?array
    {
        $session = $cookies[self::SESSION_COOKIE] ?? null;
        $csrfCookie = $cookies[self::CSRF_COOKIE] ?? null;
        if (!is_string($session) || !is_string($csrfCookie) || !is_string($csrfHeader) || !hash_equals($csrfCookie, $csrfHeader)) { return null; }
        return $this->auth->validateSession($session, $csrfHeader);
    }

    private function validOrigin(?string $origin): bool
    {
        return is_string($origin) && hash_equals($this->allowedOrigin, $origin);
    }

    private function cookie(string $value, bool $httpOnly, int $maxAge): array
    {
        return [
            'value' => $value,
            'expires' => $maxAge > 0 ? time() + $maxAge : time() - 3600,
            'max_age' => $maxAge,
            'path' => '/',
            'secure' => true,
            'http_only' => $httpOnly,
            'same_site' => 'Strict',
        ];
    }

    private function reply(int $status, array $body, array $cookies = [], array $extraHeaders = []): array
    {
        return [
            'status' => $status,
            'headers' => array_merge([
                'Content-Type' => 'application/json; charset=utf-8',
                'Cache-Control' => 'no-store, max-age=0',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
                'Referrer-Policy' => 'no-referrer',
            ], $extraHeaders),
            'cookies' => $cookies,
            'body' => $body,
        ];
    }
}
