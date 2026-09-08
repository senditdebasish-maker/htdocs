# Changelog

## Unreleased — Arena hardening pass

### Added

- Secure first-admin installer flow with no shipped production default password.
- Session timeout tracking and login failed-attempt lockout.
- Panchayat tenant columns and scope helpers for many business tables.
- Audit hash-chain fields (`prev_hash`, `entry_hash`).
- PDF draft generation for generated documents using native PHP output.
- Agreement draft generation.
- Additional DB-backed PDF generators for tender document, BOQ, technical evaluation, comparative statement, bill record and complete tender-file index.
- Panchayat settings, scheme, fund, budget and annual procurement-plan pages, including plan-to-project and plan-to-tender conversion.
- Safe document upload route, document register and protected downloads.
- Public NIT download gate limited to published/active tenders.
- Global-admin JSON backup/create/download/restore module.
- Composite scoped unique indexes for key Panchayat/FY numbers.
- Tender-register PDF report export alongside CSV export.
- `tests/e2e_lifecycle.php` full lifecycle SQLite smoke test.
- `tests/static_mysql_portability.py` static MySQL/MariaDB schema-portability check.
- `scripts/windows/xampp_mysql_acceptance.bat` disposable Windows XAMPP MySQL acceptance runner.
- `BUILD_STATUS.md`, `TEST_PLAN.md`, `XAMPP_INSTALLATION.md`, `.env.example` and deployment/admin/user/security/database/backup docs.

### Changed

- Money parsing avoids floating-point conversion for form inputs.
- Tender submission runs persisted compliance checks and blocks only blocking findings.
- Verification-required findings no longer produce a misleading `passed` compliance status; not-applicable rules are counted separately.
- Tender publication requires generated NIT status.
- Technical/financial evaluation and award services now enforce stricter workflow state checks.
- User creation uses stronger password policy, creates real global admins only when Super Admin is selected by a global admin, and prevents Panchayat admins creating cross-Panchayat/super-admin users.
- Public portal and lists use scoped Panchayat context where appropriate.
- Number generation and unique indexes are Panchayat/FY scoped for core reference numbers.
- Completion closure now also closes the linked tender when appropriate.
- MySQL schema creation order now avoids forward foreign-key references, and MySQL DDL explicitly uses InnoDB/utf8mb4 table options.
- Optional form dates/datetimes are normalized to `NULL`, `YYYY-MM-DD` or `YYYY-MM-DD HH:MM:SS`, and `now_iso()` now stores MySQL-safe `Y-m-d H:i:s` DATETIME values.
- Automatic MySQL database creation now validates and quotes configured database names as simple identifiers.
- `install.sql` regenerated/reordered from the current schema definition and no longer embeds a password.

### Known unverified

A temporary PHP 8.3.33 CLI was built in `/tmp`; PHP linting and SQLite smoke tests passed. Windows XAMPP/MySQL execution remains to be performed outside this sandbox.
