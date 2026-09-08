# Security Notes

## Implemented controls

- PHP sessions with HttpOnly, SameSite=Lax cookies.
- Configurable session lifetime.
- CSRF tokens on POST forms.
- Server-side RBAC checks before actions.
- Panchayat scope checks on core business records.
- Password hashing using Argon2id when available, otherwise PHP default.
- Password complexity for installer/user creation.
- Login failed-attempt lockout using configured rate-limit constants.
- Prepared PDO statements.
- Output escaping for server-rendered HTML.
- Security headers including CSP, X-Frame-Options, Referrer-Policy and nosniff.
- Upload allow-list, random stored names and SHA-256 hash recording.
- `data/` denied from direct Apache serving.
- Audit log hash-chain fields.

## Operational requirements

- Protect the server and XAMPP Control Panel.
- Use HTTPS and set `SESSION_COOKIE_SECURE=1` when exposed beyond localhost.
- Use a non-empty MySQL password for real deployments.
- Restrict OS permissions on `data/` and backups.
- Review roles regularly.
- Verify procurement rules from current official sources.

## Not implemented / not claimed

- No digital signature certificate integration.
- No direct e-procurement API integration.
- No banking/payment execution.
- No antivirus scanning for uploads.
- No external audit-log notarisation.

These are intentional non-fakes: the system records manual references where integration does not exist.
