# Owner HQ web shell

`public/hq/` is a small same-origin browser UI for the existing owner API. It provides password + TOTP login, financial/control status, masked provider-key inventory, authenticated STOP, fresh-MFA resume review and the exact confirmation step.

The session cookie is HttpOnly; JavaScript reads only the CSRF cookie needed by the same-origin API. The UI does not persist passwords, TOTP codes, session tokens, API keys or resume challenges in local/session storage. Resume challenge data exists only in page memory and is cleared after confirmation or error. Provider keys are never displayed; the vault panel is read-only because there is no trusted provider smoke-test adapter yet.

The page uses local assets only, a restrictive CSP meta policy, and same-origin `fetch`. The CSP meta policy cannot enforce `frame-ancestors`; anti-framing and `no-store` depend on HTTP response headers in `public/hq/.htaccess`, configured only when Apache `mod_headers` is available. Hosting/proxy must be verified to serve those headers and route `/api/*` to `public/index.php`. The API itself requires HTTPS and exact configured Origin. Do not deploy before hosting, TLS, DB, backup/restore and independent security gates pass. Browser-level integration has not been verified against a configured staging database.

Emergency STOP fences new provider-attempt starts and cancels queued or leased-but-unstarted work. A request already authorized into the durable `dispatching` state may have reached a provider; the dashboard reports it as open work until its exact outcome is stored or reconciled. STOP cannot recall a request already sent over the network.
