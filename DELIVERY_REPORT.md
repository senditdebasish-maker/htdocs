# Delivery Report

Date: 2026-09-08
Branch: `arena/01a07d4b-htdocs`
Phase: Windows XAMPP + MySQL/MariaDB production-acceptance preparation

## Summary

The existing native PHP/XAMPP Gram Panchayat Tender, Procurement, Work, Billing, Payment, Document, Compliance and Audit Management Portal has **not** been rebuilt or replaced. This phase focused on the highest-priority remaining acceptance risk: real MySQL/MariaDB/XAMPP readiness.

Because the current Arena runtime does not include Windows XAMPP, Apache, PHP or MySQL/MariaDB, no real XAMPP/MySQL PASS claim is made. Work completed in this phase was limited to inspection, static MySQL portability improvements, Windows acceptance tooling and documentation.

## What changed in this phase

- Reordered `app/schema.php` table definitions to avoid forward foreign-key creation order risks.
- Reordered `install.sql` to match `app/schema.php` table order.
- Added explicit MySQL table options to schema-generated DDL:
  - `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`
- Updated all 59 tables in `install.sql` with the same InnoDB/utf8mb4 table options.
- Fixed MySQL strict-mode DATETIME portability by changing `now_iso()` from ISO-8601 timezone output to `Y-m-d H:i:s`.
- Added `blank_to_null()`, `date_or_null()` and `datetime_or_null()` helpers so blank optional form dates/datetimes bind as `NULL` instead of invalid MySQL `DATE`/`DATETIME` values.
- Normalized key date writes for procurement plans, projects, contractors, tenders, opening/evaluation/award, agreements, work orders, progress, measurements, bills, payments and completion.
- Hardened automatic MySQL database creation by validating configured database names before quoting them into `CREATE DATABASE`.
- Added static MySQL portability check: `tests/static_mysql_portability.py`.
- Added Windows disposable acceptance runner: `scripts/windows/xampp_mysql_acceptance.bat`.
- Added Windows-specific installation/acceptance guide: `XAMPP_INSTALLATION.md`.
- Added/updated `BUILD_STATUS.md`, `TEST_PLAN.md`, `CHANGELOG.md`, `README.md`, and `DATABASE.md`.

## Environment evidence from this run

| Item | Result |
|---|---|
| Repository | `/home/user/htdocs` |
| System PHP | Not available in this run |
| Previous `/tmp/php-build/php-8.3.33/sapi/cli/php` | Not present; `/tmp` did not persist |
| MySQL/MariaDB binaries | `mysql`, `mariadb`, `mysqld`, `mariadbd` not found |
| Windows XAMPP/Apache | Not available in Linux sandbox |
| Package install route | `sudo apt-get update` could not reach Debian mirrors |

## Delivery scorecard

| Area                   | Status            | Evidence              |
| ---------------------- | ----------------- | --------------------- |
| PHP lint               | PARTIAL | Prior baseline lint passed with temporary PHP 8.3.33 before this phase. Current phase changed PHP files, but no PHP runtime exists now, so current lint is **NOT TESTED**. |
| MySQL schema import    | NOT TESTED / BLOCKED BY ENVIRONMENT | No MySQL/MariaDB server/client available. Static check passed: `python3 tests/static_mysql_portability.py`. |
| Clean installer        | PARTIAL | Prior SQLite web installer passed. MySQL/XAMPP installer not tested in this run. |
| XAMPP Apache           | NOT TESTED / BLOCKED BY ENVIRONMENT | No Windows XAMPP/Apache runtime available. |
| Authentication         | PARTIAL | Prior SQLite login/logout/CSRF smoke passed. MySQL/XAMPP authentication not tested. |
| RBAC                   | PARTIAL | Prior tested routes enforced permissions; full Windows/MySQL role matrix remains required. |
| Panchayat isolation    | PARTIAL | Prior SQLite cross-Panchayat list/detail/document smoke passed. Full MySQL/XAMPP hostile matrix remains required. |
| Financial year         | PARTIAL | Prior SQLite FY lifecycle passed. MySQL/XAMPP FY controls not tested. |
| Tender lifecycle       | PARTIAL | Prior SQLite `tests/e2e_lifecycle.php` passed full demo workflow. MySQL lifecycle not tested. |
| Financial calculations | PARTIAL | Prior SQLite BOQ/bill/payment calculations passed in lifecycle smoke. MySQL decimal/date/strict-mode calculations not tested. |
| Compliance             | PARTIAL | Prior SQLite compliance semantics passed; legal content remains verification-required where not externally verified. MySQL compliance runtime not tested. |
| PDF generation         | PARTIAL | Prior SQLite generated NIT, tender document, BOQ, technical evaluation, comparative statement, LOA, agreement, work order, bill, completion certificate, complete tender file and report PDFs by header/size/checksum. MySQL data/PDF and visual PDF review not tested. |
| Reports                | PARTIAL | Prior SQLite CSV/PDF tender-register exports passed. MySQL reports and full report matrix not tested. |
| Document security      | PARTIAL | Prior anonymous NIT vs confidential LOA and cross-Panchayat document smoke passed. Full MySQL/XAMPP document matrix not tested. |
| Upload security        | PARTIAL | Prior SVG/fake-PDF rejected and text upload passed. Windows/browser corpus not tested. |
| Public portal          | PARTIAL | Prior public portal/NIT smoke passed. MySQL/XAMPP public confidentiality matrix not tested. |
| Backup/restore         | PARTIAL | Prior disposable SQLite backup/restore passed. MySQL backup/restore not tested. |
| Audit trail            | PARTIAL | Prior SQLite lifecycle created audit trail; audit immutability/hash fields present. MySQL audit runtime not tested. |
| Security testing       | PARTIAL | Prior targeted CSRF/open-redirect/upload/cross-scope tests passed. Full hostile QA on MySQL/XAMPP not tested. |
| Performance            | PARTIAL | Prior SQLite 500 project/tender direct performance smoke passed. MySQL realistic-volume/browser performance not tested. |
| XAMPP clean deployment | NOT TESTED / BLOCKED BY ENVIRONMENT | Must be performed on Windows XAMPP at `C:\xampp\htdocs\gp-tender\`. |

## Checks executed now

Commands:

```bash
python3 tests/static_mysql_portability.py
git diff --check
# plus a coarse brace-balance check for modified PHP files (not a PHP parser)
```

## Static MySQL portability check result

Command:

```bash
python3 tests/static_mysql_portability.py
```

Result:

```text
PASS: static MySQL portability checks
tables=59 foreign_keys=164 indexes=14 unique_indexes=11 engines=59
```

The static check verifies:

- `app/schema.php` and `install.sql` have the same 59 tables in the same order.
- All 59 `CREATE TABLE` statements include InnoDB/utf8mb4 table options.
- All foreign-key referenced tables exist.
- There are zero forward foreign-key table creation references.
- Index counts remain 14 normal indexes and 11 unique indexes.
- `install.sql` does not seed users or default password hashes.
- Obvious SQLite-only SQL constructs are not present in production PHP paths.

## Files added for Windows acceptance

- `XAMPP_INSTALLATION.md` — fresh Windows XAMPP installation and acceptance guide.
- `BUILD_STATUS.md` — current state, environment blockers and next task.
- `TEST_PLAN.md` — prior tests, current tests and required Windows/MySQL tests.
- `tests/static_mysql_portability.py` — static MySQL DDL/schema portability check.
- `scripts/windows/xampp_mysql_acceptance.bat` — disposable MySQL acceptance runner for Windows XAMPP.

## Required next actions on actual Windows XAMPP

1. Copy the repository to:

   ```text
   C:\xampp\htdocs\gp-tender\
   ```

2. Start Apache and MySQL/MariaDB in XAMPP.
3. Run the disposable CLI acceptance script:

   ```bat
   cd C:\xampp\htdocs\gp-tender
   scripts\windows\xampp_mysql_acceptance.bat
   ```

4. If the script passes, test the browser installer at:

   ```text
   http://localhost/gp-tender/
   ```

5. Create first administrator, login, run the demo or manual lifecycle, generate PDFs, export reports, upload/download documents, test public portal, test cross-Panchayat isolation and test backup/restore.
6. Update this report with exact XAMPP version, PHP version, MySQL/MariaDB version, command output and browser evidence.

## Known limitations before production use

1. Current PHP edits have not been PHP-linted because PHP is unavailable in this run.
2. `install.sql` has not been imported into real MySQL/MariaDB in this environment.
3. The web installer has not been run against MySQL/MariaDB here.
4. Apache `.htaccess` and `/gp-tender/` subfolder behavior have not been tested on Windows XAMPP.
5. Generated PDFs have not been visually reviewed in a Windows PDF viewer.
6. Full role matrix, hostile QA and realistic MySQL performance tests remain required.
7. MySQL backup/restore remains untested.
8. Legal/procurement rule content remains configurable and requires human/legal verification against official sources before operational reliance.
9. No DSC, e-procurement API synchronization or bank payment execution is implemented or claimed.

## Production readiness statement

**Not production-ready yet.** The application is better prepared for MySQL/MariaDB acceptance, but Windows XAMPP + MySQL/MariaDB runtime evidence is still required before any PASS/production-ready claim.
