# Build Status

Date: 2026-09-08
Branch: `arena/01a07d4b-htdocs`
Phase: Windows XAMPP + MySQL/MariaDB production-acceptance preparation

## Current objective

Take the existing SQLite-verified native PHP portal into the Windows XAMPP/MySQL acceptance phase without rebuilding or replacing the architecture.

## Environment found in this Arena run

- Repository path: `/home/user/htdocs`
- System `php`: not available in this run.
- Previous temporary `/tmp/php-build/php-8.3.33/sapi/cli/php`: no longer present because `/tmp` did not persist.
- MySQL/MariaDB binaries: not available (`mysql`, `mariadb`, `mysqld`, `mariadbd` not found).
- Windows XAMPP/Apache: not available in this Linux sandbox.
- `apt-get update`: Debian mirrors unreachable; package installation cannot be used for PHP/MySQL here.

## What was inspected this run

- Git status/diff.
- Existing documentation: `README.md`, `DATABASE.md`, `CHANGELOG.md`, `INSTALLATION.md`, `DEPLOYMENT.md`, `SECURITY.md`, `BACKUP_RESTORE.md`, `ADMIN_GUIDE.md`, `USER_GUIDE.md`, `DELIVERY_REPORT.md`, `.env.example`.
- Core code: `config.php`, `index.php`, `install.php`, `app/schema.php`, `app/db.php`, `app/auth.php`, `app/installer.php`, `app/services.php`, `app/documents.php`, `app/backup.php`, `app/seed.php`, tests.
- `install.sql` schema and index definitions.

## Completed in this run

1. Static MySQL/MariaDB schema-order review identified forward foreign-key table creation order risks:
   - `users.panchayat_id` referenced `panchayats` before `panchayats` was created.
   - `tender_approval_docs.tender_id` referenced `tenders` before `tenders` was created.
2. Reordered `app/schema.php` table definitions to create referenced tables first.
3. Reordered `install.sql` to match `app/schema.php`; static check now reports zero forward foreign-key references.
4. Added explicit MySQL table options to generated DDL in `app/schema.php`:
   - `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`
5. Updated all `install.sql` tables to include the same InnoDB/utf8mb4 table options.
6. Fixed a MySQL strict-mode portability issue by changing `now_iso()` to return `Y-m-d H:i:s` instead of ISO-8601 timezone strings for DATETIME columns.
7. Added `blank_to_null()`, `date_or_null()` and `datetime_or_null()` helpers to normalize optional form dates/datetimes before binding to MySQL DATE/DATETIME columns.
8. Applied date normalization to key direct/service inserts and updates for projects, procurement plans, contractors, tender dates, technical opening, awards, agreements, work orders, progress, measurements, bills, payments and completion flow.
9. Hardened automatic MySQL database creation so configured database names must be simple MySQL identifiers before they are quoted.
10. Added `tests/static_mysql_portability.py` and ran it successfully.
11. Added Windows acceptance runner: `scripts/windows/xampp_mysql_acceptance.bat`.
12. Added Windows-specific installation/acceptance guide: `XAMPP_INSTALLATION.md`.
13. Updated `CHANGELOG.md`, `TEST_PLAN.md` and `DELIVERY_REPORT.md` for this phase.

## Tests actually executed in this run

| Test | Result | Evidence |
|---|---:|---|
| Environment check for PHP/MySQL/XAMPP | BLOCKED | No `php`, no MySQL/MariaDB binaries, no Windows XAMPP in sandbox. |
| `apt-get update` | BLOCKED | Debian mirrors failed with connection errors; package install not available. |
| Static schema/install synchronization | PASS | `tests/static_mysql_portability.py` reported matching 59 tables. |
| Static MySQL DDL checks | PASS | `tests/static_mysql_portability.py` reported 164 FKs, 14 normal indexes, 11 unique indexes, 59 InnoDB tables, zero forward FKs. |
| `git diff --check` | PASS | No whitespace/error output. |
| Coarse PHP brace-balance check | PASS | `app/helpers.php`, `app/schema.php`, `app/services.php`, `app/db.php`, `index.php` balanced; this is not a PHP parser/lint replacement. |
| PHP lint after this run's PHP edits | NOT TESTED | PHP runtime unavailable in this run. Previous lint PASS in earlier baseline does not cover this run's edits. |
| MySQL import | NOT TESTED | MySQL/MariaDB server/client unavailable. |
| XAMPP Apache subfolder | NOT TESTED | Windows XAMPP/Apache unavailable. |

## Highest-priority next task

Run the new Windows/MySQL acceptance procedure on an actual Windows XAMPP machine:

```bat
cd C:\xampp\htdocs\gp-tender
scripts\windows\xampp_mysql_acceptance.bat
```

Then perform browser checks at:

```text
http://localhost/gp-tender/
```

Record actual XAMPP, Apache, PHP and MySQL/MariaDB evidence in `DELIVERY_REPORT.md`.

## Do not claim yet

Do not claim production-ready, XAMPP PASS, or MySQL PASS until actual Windows XAMPP/MySQL execution succeeds. Current MySQL status is improved/static-prepared only, not runtime-proven.
