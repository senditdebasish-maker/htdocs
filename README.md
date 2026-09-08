# Gram Panchayat Tender & Work Management Portal

Native PHP/MySQL portal for a West Bengal Gram Panchayat procurement lifecycle:

**Financial year → scheme/fund → project/work → approvals/sanctions → tender/NIT/BOQ/publication → bidders → technical/financial evaluation → comparative L1/L2/L3 → recommendation/approval → LOA/agreement/work order → execution/progress/measurement → bills → payment records → completion/handover → audit/reports/public portal.**

The codebase is a modular PHP application designed for **Windows XAMPP** at:

```text
C:\xampp\htdocs\gp-tender\
http://localhost/gp-tender/
```

No Node.js, Composer, Docker, Redis, external database, cloud service, or internet connection is required for normal operation.

## Important integrity notice

- The portal records workflow data and runs **configured system checks**. It does **not** certify legal compliance.
- Rules that are not verified against current official circulars remain labelled **VERIFICATION REQUIRED**.
- Government e-procurement, banking payment execution, and digital signatures are **not faked**. The system records manual references and payment records only.
- Generated NIT, tender document, BOQ, technical evaluation, comparative statement, LOA, agreement, work-order, bill, completion and complete-file PDFs are **system-generated drafts** and must be signed/issued by the competent authority before external use. Tender-register CSV/PDF exports are generated from live database records.

## Runtime requirements

| Component | Requirement |
|---|---|
| XAMPP | 8.x recommended |
| PHP | 8.2+ recommended; PHP 8.0+ expected |
| PHP extensions | `pdo_mysql`, `openssl`, `fileinfo`; `pdo_sqlite` optional for development |
| Database | MySQL/MariaDB bundled with XAMPP |
| Apache | `mod_rewrite` enabled for pretty URLs |

## Quick installation

1. Copy the repository contents to `C:\xampp\htdocs\gp-tender\`.
2. Start **Apache** and **MySQL** in XAMPP Control Panel.
3. Open `http://localhost/gp-tender/`.
4. On a fresh install the app redirects to `/install`.
5. Enter the first administrator name, email, and password. There is **no shipped production default password**.
6. Sign in and configure users, Panchayat settings, numbering, rules, schemes, funds, budgets, and procurement plans.

Manual phpMyAdmin alternative:

1. Create database `gp_portal` with `utf8mb4_unicode_ci` collation.
2. Import `install.sql`.
3. Open `/install` to seed reference data and create the first administrator, or run `php seed.php` from XAMPP Shell with a local `SEED_ADMIN_PASSWORD` configured.

## Key modules

- `app/schema.php` — schema DSL, create/heal logic, tenant columns, indexes.
- `app/auth.php` — session handling, login throttling, RBAC and CSRF support.
- `app/services.php` — numbering, tender, bidder, evaluation, award, execution, bill, payment, completion and reporting services.
- `app/compliance.php` — configurable rule engine with verification-required semantics.
- `app/documents.php` — PDF draft generation and safe upload/download helpers.
- `app/backup.php` — JSON database backup/restore for global administrators.
- `index.php` — Apache front controller and server-rendered UI routes.

## Security highlights

- Prepared SQL through PDO helper methods.
- CSRF token on state-changing forms.
- Server-side RBAC checks on actions.
- Panchayat-scoped data isolation helpers on business entities.
- Session expiry, login failure lockout, secure password hashing.
- Protected `data/` directory for uploads, generated PDFs, backups and optional SQLite DB.
- File upload allow-list with extension/MIME checks, random stored names and SHA-256 hashes.
- Append-only audit log with hash chaining columns.

## Smoke tests

For local development smoke testing, run against a disposable SQLite file:

```bash
DB_DRIVER=sqlite DB_SQLITE_FILE=/tmp/gp-e2e/app.db php tests/e2e_lifecycle.php
```

The test resets the configured database, creates clearly labelled `[DEMO]` lifecycle records, verifies major database records/audit events, and generates PDF files for the major tender/work/bill document set. Do **not** run it against a production MySQL database; the script refuses MySQL resets unless `ALLOW_E2E_MYSQL_RESET=1` is explicitly set.

## Documentation

See:

- `INSTALLATION.md`
- `XAMPP_INSTALLATION.md`
- `DEPLOYMENT.md`
- `DATABASE.md`
- `ADMIN_GUIDE.md`
- `USER_GUIDE.md`
- `SECURITY.md`
- `BACKUP_RESTORE.md`
- `TEST_PLAN.md`
- `BUILD_STATUS.md`
- `DELIVERY_REPORT.md`
- `CHANGELOG.md`

`DELIVERY_REPORT.md` is intentionally honest about what was and was not tested in this Arena environment.
