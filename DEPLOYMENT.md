# Deployment Guide

## Supported production profile

- Windows XAMPP
- Apache + PHP + MySQL/MariaDB
- Application under an Apache subfolder such as `/gp-tender/`
- No mandatory build step or external service

## Apache

`.htaccess` routes non-file requests to `index.php`. `data/.htaccess` denies direct access to uploads, generated documents, backups, logs and optional SQLite files.

If pretty URLs return 404, enable Apache rewrite module in XAMPP:

```apache
LoadModule rewrite_module modules/mod_rewrite.so
```

Restart Apache.

## File permissions

Apache/PHP must be able to create and write:

```text
data/uploads/
data/generated/
data/backups/
data/logs/
```

Keep `data/` protected. Do not move generated uploads into public folders.

## URL/subfolder support

The app computes its base path from `SCRIPT_NAME`, so `http://localhost/gp-tender/`, `http://localhost/some-folder/`, and a web-root deployment work without hard-coded localhost paths.

## Environment overrides

`.env.example` lists available environment variables. XAMPP users may instead edit `config.php` locally. Do not commit local credentials.

## Upgrade process

1. Back up the database from **Backups** and copy the whole `data/` directory.
2. Replace application PHP/assets/docs files.
3. Open the site. The schema healer adds missing tables/columns and indexes without dropping data.
4. Visit `/install` while signed in as an administrator if manual repair is needed.
5. Review `CHANGELOG.md` and `DELIVERY_REPORT.md`.

## Deployment cautions

- Set `SESSION_COOKIE_SECURE=1` only when serving over HTTPS.
- Do not expose phpMyAdmin publicly.
- The built-in backup/restore is full-database and restricted to global administrators.
- E-procurement, DSC and bank execution are manual references only unless a real integration is built later.
