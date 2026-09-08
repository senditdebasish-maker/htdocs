# Windows XAMPP Installation and Acceptance Guide

Target production-style path:

```text
C:\xampp\htdocs\gp-tender\
http://localhost/gp-tender/
```

This guide is Windows-focused. The application itself is native PHP and MySQL/MariaDB; it does not require Node.js, npm, Docker, Python, Redis, cloud services or internet access for normal operation.

## 1. Install and prepare XAMPP

1. Install a current XAMPP release for Windows 10/11.
2. Open **XAMPP Control Panel**.
3. Start **Apache**.
4. Start **MySQL**.
5. Confirm Apache responds at `http://localhost/`.
6. Confirm phpMyAdmin opens at `http://localhost/phpmyadmin/`.

Recommended PHP extensions in `C:\xampp\php\php.ini`:

```ini
extension=pdo_mysql
extension=openssl
extension=fileinfo
```

Optional for development only:

```ini
extension=pdo_sqlite
```

Restart Apache after any `php.ini` change.

## 2. Copy the application

Copy the complete application directory to:

```text
C:\xampp\htdocs\gp-tender\
```

The folder should contain files such as:

```text
C:\xampp\htdocs\gp-tender\index.php
C:\xampp\htdocs\gp-tender\install.php
C:\xampp\htdocs\gp-tender\install.sql
C:\xampp\htdocs\gp-tender\app\schema.php
C:\xampp\htdocs\gp-tender\assets\app.css
```

Do not place only the `app` folder or only static assets in `htdocs`.

## 3. Configure database settings

The default local XAMPP settings in `config.php` are:

```php
define('DB_DRIVER', 'mysql');
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', 'gp_portal');
define('DB_USER', 'root');
define('DB_PASS', '');
```

If your local MySQL root account has a password, edit `config.php` locally or set environment variables in Apache. Do not commit real credentials.

For a stronger local deployment, create a dedicated database user in phpMyAdmin and grant it privileges only on the GP portal database.

## 4. Clean web installer path

1. Open:

   ```text
   http://localhost/gp-tender/
   ```

2. A fresh database should redirect to:

   ```text
   http://localhost/gp-tender/install
   ```

3. Create the first administrator with:
   - administrator name;
   - administrator email;
   - strong password;
   - matching confirmation.

4. There is no shipped production default password. Do not use `admin/admin` or a shared account.

5. After installation, unauthenticated access to `/install` must show **Installer disabled**. Database repair is available only after signing in as an administrator.

## 5. Manual phpMyAdmin import option

If you prefer manual SQL import:

1. Open `http://localhost/phpmyadmin/`.
2. Create database:

   ```text
   gp_portal
   ```

   Collation:

   ```text
   utf8mb4_unicode_ci
   ```

3. Import:

   ```text
   C:\xampp\htdocs\gp-tender\install.sql
   ```

4. Open:

   ```text
   http://localhost/gp-tender/install
   ```

5. Create the first administrator.

`install.sql` creates schema only; it intentionally does not insert a default admin password.

## 6. Run disposable MySQL CLI acceptance smoke

This is optional but strongly recommended before real use. It uses a disposable database named `gp_portal_acceptance` by default and will drop/recreate it.

Open **Command Prompt**:

```bat
cd C:\xampp\htdocs\gp-tender
scripts\windows\xampp_mysql_acceptance.bat
```

Optional variables:

```bat
set XAMPP_DIR=C:\xampp
set DB_NAME=gp_portal_acceptance
set DB_USER=root
set DB_PASS=your-local-password-if-any
scripts\windows\xampp_mysql_acceptance.bat
```

Expected result:

```text
[1/5] Dropping and recreating disposable database ...
[2/5] Importing install.sql ...
[3/5] Running schema health check ...
[4/5] Running full MySQL lifecycle/PDF/audit acceptance smoke ...
[5/5] CLI MySQL acceptance completed ...
```

Do not run this script against a production database. It sets `ALLOW_E2E_MYSQL_RESET=1` and intentionally resets the selected database.

## 7. Apache subfolder checks

Using a browser, verify these paths stay under `/gp-tender`:

```text
http://localhost/gp-tender/login
http://localhost/gp-tender/
http://localhost/gp-tender/tenders
http://localhost/gp-tender/projects
http://localhost/gp-tender/documents
http://localhost/gp-tender/public
http://localhost/gp-tender/public/tenders
```

Check:

- CSS loads from `/gp-tender/assets/app.css`.
- Forms post to `/gp-tender/...` URLs.
- Redirects return to `/gp-tender/login`, not `/login`.
- Session cookie path is `/gp-tender`.
- Logout returns to `/gp-tender/login`.
- Generated/downloaded PDFs stream without PHP warnings.

Apache must allow `.htaccess` and `mod_rewrite`. In XAMPP this usually works by default. If pretty routes fail, confirm Apache config allows overrides for `C:\xampp\htdocs`.

## 8. Production setup checklist after first login

1. Configure **Panchayat** details and official office identity.
2. Review numbering patterns under **Settings**.
3. Review rules and mark externally verified rules only after checking official sources.
4. Create financial year.
5. Create schemes, funds and budget allocations.
6. Create procurement plan/project/tender records.
7. Generate and visually inspect PDFs.
8. Create named users and least-privilege roles.
9. Test public portal confidentiality.
10. Create a first full backup.

## 9. Storage and uploads

Runtime data is stored below:

```text
C:\xampp\htdocs\gp-tender\data\
```

Subdirectories are created automatically:

```text
data\uploads\
data\generated\
data\backups\
data\logs\
```

The included `data\.htaccess` blocks direct web access. Documents should be downloaded through authenticated `/documents/{id}` routes, not by filesystem path.

If your local security policy allows it, you may move runtime data outside the web tree by setting `DATA_DIR` in the environment or editing `config.php` locally.

## 10. Backup and restore

From the application:

1. Sign in as a global administrator.
2. Open **Backups**.
3. Click **Create full backup**.
4. Download and store the backup outside the PC if possible.

Restore is destructive. Use it only on the intended database and only after preserving the current system state.

For disaster recovery, keep copies of:

```text
install.sql
config.php local settings
data\uploads\
data\generated\
data\backups\
```

## 11. Security recommendations

- Replace the default empty local MySQL root password where practical.
- Prefer a dedicated MySQL user for the portal.
- Use strong named user accounts; avoid shared logins.
- Do not expose XAMPP directly to the public internet.
- If deployed beyond localhost/LAN, use HTTPS and set `SESSION_COOKIE_SECURE=1`.
- Keep Windows, XAMPP, PHP and MariaDB updated.
- Review all legal/procurement rule configurations against current official sources.
- Do not claim DSC, e-procurement API or bank payment integration unless separately implemented and tested.

## 12. Troubleshooting

### Database unavailable

- Start MySQL in XAMPP Control Panel.
- Check `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` in `config.php`.
- Confirm phpMyAdmin can connect.

### Installer does not appear

- Open `/gp-tender/install` directly.
- If users already exist, the installer is intentionally disabled for anonymous visitors.

### Pretty URLs fail

- Confirm `.htaccess` exists in `C:\xampp\htdocs\gp-tender\`.
- Confirm Apache `mod_rewrite` is enabled.
- Confirm `AllowOverride All` or equivalent is enabled for `htdocs`.

### Upload fails

- Confirm the file type is allowed.
- Confirm PHP `upload_max_filesize` and `post_max_size` are large enough.
- Confirm Windows permissions allow Apache to write to `data\`.

### PDF does not open

- Check that `data\generated\` is writable.
- Re-generate the document from the related tender/project/bill page.
- Check Apache/PHP error logs; users should not see raw stack traces.

## 13. Acceptance evidence to record

Before marking production-ready, record in `DELIVERY_REPORT.md`:

- XAMPP version;
- PHP version;
- MySQL/MariaDB version;
- `install.sql` import result;
- installer result;
- login/logout result;
- schema-health result;
- MySQL lifecycle test result;
- PDF visual review result;
- public/confidential document tests;
- Panchayat isolation tests;
- backup/restore test result;
- report export result.
