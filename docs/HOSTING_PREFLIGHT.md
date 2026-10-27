# KP-003 — Hosting feasibility (provider research; account NOT verified)

Checked Namecheap's published shared-hosting documentation on 24 October 2026. Published capabilities are not proof of the owner's account configuration or suitability for sustained AI workloads.

| Constraint | Published Namecheap information | Project response |
|---|---|---|
| PHP | PHP 8.0–8.5 options are advertised | PHP 8.4 preferred, confirm actual selector & CLI |
| SQL | MariaDB 11.4 listed, MySQL compatibility | Check InnoDB, TLS/backups, PDO MySQL driver |
| Cron | Less than 5-minute intervals are disallowed | One batched cron scheduler at intervals of 5 minutes or longer, short resumable tasks |
| Background daemons | Stand-alone daemons disallowed on shared | Never rely on permanent worker, websocket daemon or busy loop |
| Resource quotas | Stellar Plus shared resources are capped | Bound memory, CPU, job runtime and concurrent requests |
| SSH/Git | SSH may require enablement; Git listed | Check Jailshell, Composer availability, safe deploy |
| Backups | Shared limits can disqualify provider backups | Encrypted independent backups with restore drills |
| AI media | Video rendering/transcoding may be resource intensive | External provider/storage/worker, not on shared PHP host |

**Official references (accessed 2026-10-24):**
- https://www.namecheap.com/support/knowledgebase/article.aspx/1212/22/do-you-allow-cron-jobs/
- https://www.namecheap.com/support/knowledgebase/article.aspx/157/22/do-you-have-any-server-resource-restrictions/
- https://www.namecheap.com/support/knowledgebase/article.aspx/129/22/what-version-of-the-software-is-used-on-your-servers/
- https://www.namecheap.com/legal/hosting/aup/
- https://www.namecheap.com/support/knowledgebase/article.aspx/1127/103/a-handy-guide-to-resource-limits-or-what-is-lve/

**Still required for KP-003 completion:** read-only inspection of the actual cPanel PHP version/extensions (`pdo_mysql`, `openssl`, `sodium`), DB version/engine, cron configuration, process/memory limits, SSH availability, DNS/HTTPS, deploy directories, backup/restore and staging isolation. Do not ask for or commit plaintext passwords or API tokens. If unsupported, choose a minimal PHP control plane plus external queue/worker.

Recommendation: maintain cron-driven, event-queued financial/core architecture; if heavier autonomy becomes necessary, pay only from approved budgets for a separate worker rather than violating shared-host policies.
