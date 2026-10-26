# Owner authentication foundation

The current owner-auth path is a backend prototype on the financial feature branch. It has a CLI-only first-owner bootstrap, password + TOTP verification, encrypted TOTP secret storage, replay prevention, login throttling, opaque server-side sessions and same-origin JSON routes.

## Routes

- `POST /api/auth/login` with JSON `{ "email", "password", "totp" }`; succeeds only on the configured HTTPS origin and sets an HttpOnly `__Host-kp_session` cookie plus a readable `__Host-kp_csrf` cookie.
- `GET /api/auth/session` returns the authenticated owner's email when the session cookie, CSRF cookie and `X-CSRF-Token` header match.
- `POST /api/auth/logout` requires the configured Origin and matching CSRF token; it revokes the server-side session and clears both cookies.
- `GET /api/control/status` returns the authenticated financial and reservation snapshot.
- `POST /api/control/stop` persists owner STOP through the dispatch gate and returns the post-stop snapshot.
- `POST /api/control/resume/challenge` requires the active session, password and a fresh, unused TOTP code. It issues a five-minute one-use challenge, rotates the session cookie and returns the exact financial snapshot that must be reviewed.
- `POST /api/control/resume/confirm` requires the rotated session, matching CSRF/origin, the challenge and exact phrase `RESUME KAMAU PUTT`. It resumes only if the session is still fresh, the challenge is unused, the system remains stopped and the financial snapshot/dispatch epoch have not changed.
- `GET /api/vault/credentials` returns owner-authenticated masked credential metadata; `POST /api/vault/credentials` stores a provider key encrypted and inactive.
- `POST /api/vault/credentials/{id}/activate` requires fresh password/TOTP, exact phrase `ACTIVATE CREDENTIAL`, and a passing internal smoke-test record no older than five minutes.
- `POST /api/vault/credentials/{id}/revoke` revokes a key; immutable credential rows remain for audit. No route performs an external provider test or returns key material.
- Owner-control status includes open/uncertain outbox actions. STOP cancels queued work and fences leased work in the same transaction as the dispatch epoch change.

Cookies use `Secure`, `Path=/`, `SameSite=Strict`; the session token is HttpOnly. There is no CORS allowance or public registration route. The front controller requires HTTPS and uses the direct peer address for throttling; it does not trust forwarded IP headers.

The summary includes settled cash, customer/tax/refund reserves, discretionary reservations, open reservation count, open/uncertain jobs and current mode/epoch. Normal resume preserves Survival mode; it never silently turns paid dispatch back on. Safe Shutdown requires a separate recovery decision. STOP, repeated STOP, challenge issuance and successful resume append owner-control events to the hash-chained audit journal in the same transaction as each state change; SQL triggers reject audit updates and deletes. See [durable jobs](DURABLE_JOBS.md) for queue and STOP semantics.

## Provisioning configuration

Apply the available migrations `001`, `002`, and `004` through `008` in order using a restricted migration/admin runner. Provision the only owner with `php bin/bootstrap-owner.php` from a trusted CLI after setting these environment values:

- `KP_MYSQL_DSN`, `KP_MYSQL_USER`, `KP_MYSQL_PASSWORD`
- `KP_OWNER_EMAIL`, `KP_OWNER_PASSWORD`, `KP_OWNER_TOTP_SECRET`
- `KP_MFA_ENCRYPTION_KEY` (base64-encoded 32 random bytes)
- `KP_AUTH_RATE_LIMIT_KEY` (base64-encoded, at least 32 random bytes)
- `KP_VAULT_ENCRYPTION_KEY` (base64-encoded 32 random bytes, kept separate from the MFA key; required for vault routes)
- For the web front controller: `KP_ALLOWED_ORIGIN` (one exact HTTPS origin)

Never put these values in Git, the public webroot, a URL, application logs or the Android app. The bootstrap is single-use and refuses an existing owner row. The owner must enroll the supplied TOTP secret into an authenticator before discarding the bootstrap environment. Back up the vault key separately: losing it permanently prevents decrypting stored provider credentials.

Credential lifecycle evidence is in `tests/mysql_credential_vault.php`; MySQL 8.4 run `40247547998` passed all six DB suites and the vault suite's 16 lifecycle assertions. The PHP workflow for the same commit also passed. The current code intentionally has no provider adapter, key smoke-test transport, model router, usage metering or cost ledger connection. Internal test reports are trusted service inputs, not owner-submitted HTTP data. Do not activate or use a real provider key until that adapter, STOP fencing, cost reservation, privacy review and independent security review exist.

## Limits before deployment

MySQL 8.4 run `40247011094` passed five DB suites, including 19 auth/controller and 15 owner-control/audit assertions. Credential-vault lifecycle passed 16 assertions on run `40247547998`. The durable-jobs suite is pending CI. Routes have no UI, password reset, MFA recovery, session inventory, role system, verified scheduler/worker, independent security review or verified hosting setup. The cPanel document root must point to `public/`; SSL, environment-variable support, PDO MySQL, rewrite support, database grants, backup/restore and HTTPS behavior must be checked on the actual account before use. Do not deploy this as a live control surface yet.
