# Owner HQ web shell

`public/hq/` is a small same-origin browser UI for the existing owner API. It provides password + TOTP login, financial/control status, masked provider-key inventory, authenticated STOP, fresh-MFA resume review and the exact confirmation step.

The session cookie is HttpOnly; JavaScript reads only the CSRF cookie needed by the same-origin API. The UI does not persist passwords, TOTP codes, session tokens, API keys or resume challenges in local/session storage. Resume challenge data exists only in page memory and is cleared after confirmation or error. Provider keys are never displayed; the vault panel is read-only because there is no trusted provider smoke-test adapter yet.

The page uses local assets only, a restrictive CSP, `no-store`, no framing, and same-origin `fetch`. Apache response headers are configured in `public/hq/.htaccess` when `mod_headers` is available. The API itself requires HTTPS and exact configured Origin. Hosting must still be verified to serve the static shell with HTTPS, apply these headers, and route `/api/*` to `public/index.php`. Do not deploy it before the hosting, TLS, DB, backup/restore and independent security gates pass. Browser-level integration has not been verified against a configured staging database.
