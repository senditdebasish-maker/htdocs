# Installation Guide

For the full Windows production-acceptance checklist, also read `XAMPP_INSTALLATION.md`.

Target deployment path:

```text
C:\xampp\htdocs\gp-tender\
http://localhost/gp-tender/
```

## 1. Install XAMPP

Install a current XAMPP release with Apache, PHP and MySQL/MariaDB. PHP 8.2 or later is recommended. In `php.ini`, keep these extensions enabled:

```ini
extension=pdo_mysql
extension=openssl
extension=fileinfo
; optional development only
extension=pdo_sqlite
```

Restart Apache after editing `php.ini`.

## 2. Copy the application

Copy all repository files into:

```text
C:\xampp\htdocs\gp-tender\
```

Keep the directory name stable if users bookmark URLs.

## 3. Configure database settings

Defaults in `config.php` match a stock XAMPP MySQL install:

```php
define('DB_DRIVER', 'mysql');
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'gp_portal');
define('DB_USER', 'root');
define('DB_PASS', '');
```

If your XAMPP MySQL root user has a password, update `DB_PASS` locally. Do not commit real passwords.

## 4. Run the web installer

1. Start Apache and MySQL.
2. Open `http://localhost/gp-tender/`.
3. The installer creates/repairs tables and seeds reference data.
4. Enter the first administrator credentials. The application no longer ships a web default password.

After the first user exists, unauthenticated installer access is disabled. Database repair is available only to a signed-in user with `settings.manage`.

## 5. Manual import option

If you cannot let the app create tables automatically:

1. Open phpMyAdmin.
2. Create database `gp_portal`, collation `utf8mb4_unicode_ci`.
3. Import `install.sql`.
4. Open `/install` to seed records and create the first admin.

## 6. First-login checklist

- Create named users with least-privilege roles.
- Review default rule set and mark locally verified rules.
- Set Panchayat metadata, letterhead/header/footer text and numbering formats.
- Review/create schemes, funds, budgets and the first procurement plan.
- Create an initial backup from **Backups**.
- Confirm generated PDFs open from the Document register.
