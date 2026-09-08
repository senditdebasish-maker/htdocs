# Backup & Restore

## Built-in backups

Signed-in global administrators can open **Backups** and create a full JSON database snapshot. The file is stored in:

```text
data/backups/
```

Backups include schema version metadata and table rows. They do not include arbitrary files outside the database; copy `data/uploads/` and `data/generated/` separately for a full disaster-recovery package.

## Download

Use the **Download** link in the backup register. Backup downloads are RBAC-protected and audited.

## Restore

Restore is destructive and available only to global administrators:

1. Copy the wanted backup JSON into `data/backups/` if it is not already present.
2. Ensure the backup row exists in the Backups register, or create/restore through a database-level method.
3. Type `RESTORE` in the confirmation field.
4. Submit restore.
5. Sign in again if session/user data changed.

The restore operation disables foreign-key checks, deletes rows from known schema tables and re-inserts rows from the backup snapshot.

## External backup recommendation

For production, also keep external backups:

- MySQL dump from phpMyAdmin or `mysqldump`.
- Copy of the whole application folder.
- Copy of `data/uploads`, `data/generated`, `data/backups` and local configuration.

Test restore on a non-production XAMPP instance before relying on backups.
