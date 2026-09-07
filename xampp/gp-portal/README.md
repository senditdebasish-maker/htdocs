# GP Procurement & Work Management Portal — XAMPP (PHP + MySQL)

A production-grade **Gram Panchayat Procurement, Tender, Work, Billing, Payment,
Audit & Reporting Management Portal** written in native **PHP + MySQL** so it
runs directly inside **XAMPP** (Apache + PHP + MySQL/MariaDB). No Node.js, no
build step, no Composer dependencies.

Lifecycle covered: **FY → Scheme → Project → Administrative Approval →
Technical Sanction → Tender → NIT → Publication → Bids → Technical Evaluation →
Financial Evaluation → Comparative (L1/L2/L3) → Approval → LOA → Agreement →
Work Order → Execution → Measurement → Bills → Payments → Completion → Audit →
Reports.**

---

## 1. Requirements

| Component | Minimum |
|---|---|
| XAMPP | 8.x (PHP 8.0+) — PHP 8.1/8.2/8.3 recommended |
| PHP extensions | `pdo_mysql`, `pdo_sqlite` (optional dev), `mbstring`, `openssl`, `fileinfo` |
| Web server | Apache with `mod_rewrite` enabled (on by default in XAMPP) |
| Database | MySQL / MariaDB (bundled with XAMPP) |

Verify from the XAMPP Control Panel that **Apache** and **MySQL** are running.

---

## 2. Install (quick start)

1. Copy the `gp-portal` folder into XAMPP's document root, e.g.
   ```
   C:\xampp\htdocs\gp-portal
   ```
2. Start Apache + MySQL in the XAMPP Control Panel.
3. Open the installer in your browser:
   ```
   http://localhost/gp-portal/install.php
   ```
   (or `http://localhost/gp-portal/install`)
4. Click **Install now**. The installer:
   - creates the `gp_portal` database (if missing),
   - creates all tables,
   - seeds reference data (roles, permissions, financial years, rules & rule
     references, schemes/funds, document templates, settings).
5. Sign in at `http://localhost/gp-portal/login` with the seed administrator:
   - Email: `admin@panchayat.local`
   - Password: `ChangeMe@12345`  ← **change immediately** (Settings → your account, or edit `SEED_ADMIN_PASSWORD` in `config.php` before installing).

> The seed admin password is set in `config.php` (`SEED_ADMIN_PASSWORD`) and only
> used during installation. It never appears again in plain text.

### Manual alternative (phpMyAdmin)

1. In phpMyAdmin, create a database `gp_portal` (utf8mb4_unicode_ci).
2. Import `install.sql` (in this folder).
3. Run `php seed.php` from this folder in a terminal (XAMPP Shell), **or**
   visit `install.php` and click **Install now** (it will only seed; tables
   already exist).

---

## 3. Configuration

Everything lives in **`config.php`**. Defaults target a stock XAMPP install and
can be overridden with environment variables (optional) or by editing the file.

| Setting | Default | Notes |
|---|---|---|
| `DB_DRIVER` | `mysql` | `mysql` for XAMPP, `sqlite` for a zero-setup dev DB |
| `DB_HOST` / `DB_PORT` | `127.0.0.1` / `3306` | XAMPP MySQL |
| `DB_NAME` | `gp_portal` | created automatically by the installer |
| `DB_USER` / `DB_PASS` | `root` / `` (empty) | XAMPP default; set a password if you configured one |
| `SEED_ADMIN_EMAIL` / `SEED_ADMIN_PASSWORD` | see file | first-install admin only |
| `SESSION_COOKIE_SECURE` | `0` | set `1` only when served over HTTPS |
| `RATE_LIMIT_*` | enabled | login brute-force throttling |

The app auto-detects its base path from `SCRIPT_NAME`, so it works at the web
root **or** inside `htdocs\gp-portal` with no further changes.

---

## 4. Folder layout

```
gp-portal/
├── index.php          Front controller / router (all requests)
├── install.php        Web installer (also reachable at /install)
├── install.sql        MySQL schema for phpMyAdmin import
├── seed.php           CLI seeder (php seed.php --reset --demo)
├── config.php         All configuration
├── .htaccess          Rewrite + deny raw DB files
├── assets/            Public CSS/JS
├── app/               Application code (PHP includes; blocked over HTTP)
│   ├── db.php             PDO wrapper (MySQL + SQLite)
│   ├── schema.php         61-table schema + DDL renderer
│   ├── auth.php           Session, CSRF, RBAC, login
│   ├── workflow.php       Configurable approval workflows
│   ├── compliance.php     Rules & compliance engine
│   ├── services.php       Domain services (tenders, bills, payments…)
│   ├── documents.php      Generated-draft documents (NIT/LOA/WO/completion)
│   ├── seed.php           Idempotent seeders + [DEMO] lifecycle
│   ├── view.php           Layout/HTML helpers
│   └── …
└── data/              Runtime data (uploads, generated docs, logs, SQLite dev DB)
    └── .htaccess      Denies direct HTTP access
```

---

## 5. Seeding the demonstration lifecycle

A clearly-labelled **`[DEMO]`** end-to-end tender can be seeded for a full walk
through of the lifecycle:

```
php seed.php --demo
```

This creates `[DEMO]` contractors, a project, a tender that reaches the awarded
stage (NIT → bids → technical/financial evaluation → L1 → award → LOA →
agreement → work order), a measurement, a running bill, a recorded payment and a
closed completion certificate — the complete loop.

Reset it at any time:

```
php seed.php --demo-reset
```

Run `php seed.php --reset` to rebuild base reference data (roles, rules, etc.)
from scratch.

---

## 6. Key design notes

- **Decimal-safe money** — every amount is stored as integer *minor units*
  (paise). No floating-point arithmetic for money.
- **Server-side authorization** — every action re-checks RBAC on the server; the
  HTML layer only reflects what the server permits.
- **Audit trail** — append-only `audit_logs` record every meaningful action.
- **Configurable workflow & numbering** — approval chains and number formats
  (e.g. `GP/NIT/2026-27/001`) are data-driven, not hard-coded.
- **Rules & compliance engine** — rules are versioned and sourced from published
  West Bengal Government references. Where a rule depends on facts the system
  cannot verify, it reports **“Verification Required”** instead of asserting
  compliance. The system **never** claims a tender is legally compliant — only
  that it “passes the configured system checks.”
- **Generated documents** — NIT/LOA/Work Order/Completion drafts are
  *system-generated drafts*, explicitly labelled **not official documents**.
  No digital signatures, e-procurement sync, or bank execution are faked —
  payments are “recorded,” never “executed.”
- **Record locking** — tenders are locked at the award stage; changes require
  corrigendum/versioning (original data is never overwritten).

---

## 7. Post-install checklist

1. Change the seed admin password.
2. Create real users with least-privilege roles (Pradhan, Secretary, Technical
   Officer, Data Entry, etc.).
3. Review the default rules and their sources (Rules & Refs page); adjust the
   active rule set to your Panchayat’s circulars.
4. Set `SESSION_COOKIE_SECURE = 1` if you serve the portal over HTTPS.
5. Back up regularly — `data/backups` is the convention for exports.

## 8. Troubleshooting

- **“Could not connect to MySQL”** — MySQL not started in XAMPP, or wrong
  credentials in `config.php` (XAMPP root password is empty by default).
- **Pretty URLs 404** — `mod_rewrite` is disabled. Enable it in
  `xampp/apache/conf/httpd.conf` (`LoadModule rewrite_module …`), then restart
  Apache. The app also works via `index.php/...` paths without rewrites.
- **Installer loops** — `data/` must be writable by Apache so generated files
  and the SQLite dev DB can be created.
