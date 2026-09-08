# Test Plan and Actual Results

Date: 2026-09-08

This file separates tests already executed from tests that still require Windows XAMPP/MySQL. Do not convert any item to PASS without actual evidence.

## A. Tests executed in prior SQLite baseline

| Area | Status | Evidence |
|---|---:|---|
| PHP lint | PASS (prior baseline) | Earlier temporary PHP 8.3.33 CLI linted all PHP files before the current MySQL-portability edits. |
| SQLite reset/check/demo | PASS | Prior disposable SQLite runs installed schema, checked health and executed `seed.php --demo`. |
| SQLite full lifecycle | PASS | Prior `tests/e2e_lifecycle.php` verified full demo workflow and PDF categories on SQLite. |
| SQLite web smoke | PASS/PARTIAL | Prior built-in PHP server smoke checked major authenticated/public routes, reports and document gates. |
| Upload security | PASS/PARTIAL | Prior SVG/fake-PDF reject and text upload pass. Browser corpus still required. |
| Public NIT vs confidential LOA | PASS/PARTIAL | Prior anonymous NIT allowed; LOA redirected to login. Broader document matrix required. |
| Cross-Panchayat smoke | PASS/PARTIAL | Prior scoped user denied known foreign tender/document routes. Full hostile matrix required. |
| Backup/restore | PASS/PARTIAL | Prior disposable SQLite backup/restore passed. MySQL restore not tested. |

## B. Tests executed in this MySQL-acceptance preparation run

| Area | Status | Command / Evidence |
|---|---:|---|
| Environment detection | BLOCKED | `php`, `mysql`, `mariadb`, `mysqld`, `mariadbd` not present; XAMPP not present. |
| Package installation route | BLOCKED | `sudo apt-get update` could not reach Debian mirrors. |
| Static MySQL schema portability | PASS | `python3 tests/static_mysql_portability.py` |
| Schema/install sync | PASS | Static script: 59 schema tables and 59 install tables in same order. |
| Foreign-key create order | PASS | Static script: 164 FKs, zero forward FKs. |
| InnoDB/charset table options | PASS | Static script: 59 tables include `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`. |
| Index counts | PASS | Static script: 14 normal indexes and 11 unique indexes. |
| SQL user seed safety | PASS | Static script verifies no `INSERT INTO users` in `install.sql`. |
| `git diff --check` | PASS | No whitespace/error output. |
| Coarse PHP brace-balance check | PASS | Modified PHP files balanced; not a substitute for `php -l`. |
| PHP lint after MySQL portability edits | NOT TESTED | Blocked by missing PHP runtime in this run. |
| Real MySQL import | NOT TESTED | Blocked by missing MySQL/MariaDB runtime. |
| Real XAMPP Apache subfolder | NOT TESTED | Blocked by missing Windows XAMPP/Apache runtime. |

## C. Required Windows XAMPP tests

Use a disposable database first. Suggested CLI runner:

```bat
cd C:\xampp\htdocs\gp-tender
scripts\windows\xampp_mysql_acceptance.bat
```

Expected coverage:

1. Drop/recreate disposable MySQL database.
2. Import `install.sql`.
3. Run `php seed.php --check` against MySQL.
4. Run `php tests\e2e_lifecycle.php` against MySQL.
5. Verify generated PDFs exist and are valid files.

## D. Required browser acceptance on Windows XAMPP

Open:

```text
http://localhost/gp-tender/
```

Test:

- installer appears on empty DB;
- first admin creation with strong password;
- installer disabled after first admin;
- login/logout/session cookie path `/gp-tender`;
- dashboard loads;
- Panchayat settings save;
- schemes/funds/budgets/procurement plans create;
- plan-to-project and plan-to-tender conversion;
- tender create/update/submit/approve/NIT/publish;
- bidders, technical opening, technical evaluation;
- financial bid, rank and comparative statement;
- award, LOA, agreement, work order;
- progress, measurement, bill, payment, completion;
- document register and downloads;
- CSV/PDF reports;
- public portal;
- backup/download/restore on a disposable DB.

## E. Negative tests to run on MySQL/XAMPP

- Missing/invalid CSRF on every POST action.
- Invalid login, repeated failed login, locked account behavior.
- Invalid date values and blank optional dates.
- Project creation in closed FY.
- Tender submission without approvals/BOQ.
- BOQ edit after tender leaves draft.
- Financial bid before technical evaluation finalization.
- Award without ranking.
- LOA without approved award.
- Agreement/work order sequence bypass.
- Bill above awarded/sanctioned amount.
- Negative or excess payment.
- Direct document ID guessing.
- Cross-Panchayat list/detail/report/document/export access.
- Upload `.php`, `.phtml`, `.svg`, fake PDF, HTML, oversized files.
- SQL/XSS attempts in visible fields.

## F. Performance sanity on MySQL/XAMPP

Create synthetic disposable data and measure:

- dashboard load;
- tender listing;
- project listing;
- reports;
- CSV export;
- PDF generation.

Minimum target dataset for acceptance:

- several hundred projects;
- several hundred tenders;
- multiple BOQ items per tender;
- bidders/evaluations;
- bills/payments;
- multiple financial years.

Do not mark the 5,000-tender production-volume contract as PASS until tested on MySQL/MariaDB with realistic hardware.

## G. Acceptance reporting

After Windows testing, update `DELIVERY_REPORT.md` with:

- exact XAMPP version;
- PHP version;
- MySQL/MariaDB version;
- Apache/subfolder result;
- CLI acceptance output;
- installer/login evidence;
- PDF visual review evidence;
- security/hostile QA results;
- backup/restore evidence;
- remaining limitations.
