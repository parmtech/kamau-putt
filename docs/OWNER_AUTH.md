# Owner authentication foundation

The current owner-auth path is a backend prototype on the financial feature branch. It has a CLI-only first-owner bootstrap, password + TOTP verification, encrypted TOTP secret storage, replay prevention, login throttling, opaque server-side sessions and same-origin JSON routes.

## Routes

- `POST /api/auth/login` with JSON `{ "email", "password", "totp" }`; succeeds only on the configured HTTPS origin and sets an HttpOnly `__Host-kp_session` cookie plus a readable `__Host-kp_csrf` cookie.
- `GET /api/auth/session` returns the authenticated owner's email when the session cookie, CSRF cookie and `X-CSRF-Token` header match.
- `POST /api/auth/logout` requires the configured Origin and matching CSRF token; it revokes the server-side session and clears both cookies.

Cookies use `Secure`, `Path=/`, `SameSite=Strict`; the session token is HttpOnly. There is no CORS allowance or public registration route. The front controller requires HTTPS and uses the direct peer address for throttling; it does not trust forwarded IP headers.

## Provisioning configuration

Apply `database/004_owner_auth.mysql.sql` using a restricted migration/admin runner. Provision the only owner with `php bin/bootstrap-owner.php` from a trusted CLI after setting these environment values:

- `KP_MYSQL_DSN`, `KP_MYSQL_USER`, `KP_MYSQL_PASSWORD`
- `KP_OWNER_EMAIL`, `KP_OWNER_PASSWORD`, `KP_OWNER_TOTP_SECRET`
- `KP_MFA_ENCRYPTION_KEY` (base64-encoded 32 random bytes)
- `KP_AUTH_RATE_LIMIT_KEY` (base64-encoded, at least 32 random bytes)
- For the web front controller: `KP_ALLOWED_ORIGIN` (one exact HTTPS origin)

Never put these values in Git, the public webroot, a URL, application logs or the Android app. The bootstrap is single-use and refuses an existing owner row. The owner must enroll the supplied TOTP secret into an authenticator before discarding the bootstrap environment.

## Limits before deployment

The web routes and DB-backed checks are on the branch, but there is no UI, password reset, MFA recovery, session inventory, role system, owner-auth binding to STOP/resume, independent security review or verified hosting setup. The cPanel document root must point to `public/`; SSL, environment-variable support, PDO MySQL, rewrite support, database grants, backup/restore and HTTPS behavior must be checked on the actual account before use. Do not deploy this as a live control surface yet.
