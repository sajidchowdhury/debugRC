# RC_ERP_v2

Migration of the **Remote Center ERP** system from a custom PHP/MySQL codebase to **Laravel 11 + PostgreSQL 16**, deployed on a BDIX VPS.

> **Status:** Phase 0 (Pre-Migration Security Cleanup) — in progress

---

## Repository structure

```
RC_ERP_v2/
├── legacy/              # Phase 0-patched legacy PHP app (runs on VPS during transition)
├── laravel/             # Laravel 11 app (added in Phase 2.2, grows through Phase 13)
├── docs/
│   └── migration/       # accounting rules, schema mapping, ETL reports
├── mini-services/       # AI sidecar (Phase 13)
├── MIGRATION_PLAN.md    # the master migration plan (phases 0–13)
└── .gitignore
```

## The migration

This repo tracks the full migration defined in [`MIGRATION_PLAN.md`](./MIGRATION_PLAN.md):

| Phase | Name | Status |
|---|---|---|
| 0 | Pre-Migration Security Cleanup | 🔄 In progress |
| 1 | VPS BDIX Provisioning | ⬜ Pending |
| 2 | Database Migration to PostgreSQL | ⬜ Pending |
| 3 | Laravel Foundation + Auth | ⬜ Pending |
| 4 | Master Data Modules | ⬜ Pending |
| 5 | Reporting Layer | ⬜ Pending |
| 6 | Inventory Module | ⬜ Pending |
| 7 | Purchase Module | ⬜ Pending |
| 8 | Sales Module | ⬜ Pending |
| 9 | Accounting Engine | ⬜ Pending |
| 10 | Notifications (Alerts only) | ⬜ Pending |
| 11 | Investigation Mode (simplified) | ⬜ Pending |
| 12 | Cutover & Decommission | ⬜ Pending |
| 13 | AI Sidecar | ⬜ Pending |

## Four non-negotiable principles

1. **Database conversion** (MySQL → PostgreSQL) — Phase 2
2. **Application conversion** (custom PHP MVC → Laravel 11) — Phases 3–9
3. **Keep the existing UI** — Blade views reproduce legacy markup verbatim; no SPA rewrite
4. **Re-derive business logic, don't copy-paste** — stock costing, journal posting, reconciliation are re-derived from first principles and verified against historical data in shadow mode before cutover

## Removed features

Per project decision, the following are **removed completely** (not ported to Laravel):

- TOTP 2FA on login (Google Authenticator)
- `PendingLogin` intermediate 2FA state
- Telegram login notifications / login alerts
- `verify_2fa` view and route
- `users.totp_secret`, `users.totp_enabled` columns

> **Note:** Telegram **business alerts** (sales alerts, reconciliation alerts, accounting alerts) are **kept** — they are not part of login.

## Manual action required (Phase 0 — cannot be done in code)

These items require human/operational action and are tracked separately:

- [ ] Rotate Telegram bot token (revoked in old public repo history)
- [ ] Rotate FCM server key + VAPID key pair
- [ ] Reset all production user passwords (bcrypt hashes were in the public SQL dump)
- [ ] Delete or make-private the old public repo `sajidchowdhury/RC_ERP` (and the public `sajidchowdhury/RC_ERP_Laravel`)
- [ ] Set production `config/local.php` with new credentials (chmod 600, never committed)

## License

Proprietary — Remote Center ERP.
