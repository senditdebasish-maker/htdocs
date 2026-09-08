# Administrator Guide

## First administrator

The first administrator is created through `/install`. No production default password is shipped.

## Users and roles

Use **Users & Roles** to create users. Non-global Panchayat administrators are restricted to their own Panchayat and cannot assign the Super Admin role. Use named accounts rather than shared credentials.

Typical roles:

- Super Admin — global system administration.
- Panchayat Admin — Panchayat-scoped administration.
- Pradhan / Upa-Pradhan — approval and oversight.
- Panchayat Secretary — operational workflow management.
- Technical Officer — estimates, BOQ, measurements and technical checks.
- Accounts Officer — bill and payment records.
- Tender Committee — evaluation and recommendations.
- Auditor / Viewer — read-only roles.

## Configuration

Use **Panchayat** for office identity, officials, letterhead/header/footer text used in generated documents. Use **Schemes**, **Funds** and **Budgets** to maintain planning masters. Use **Procurement Plan** to enter annual planned works and convert them to projects/tenders. Use **Settings** for numbering patterns and operational settings. Rule sets are stored in the database and should be reviewed against current official circulars before operational reliance.

## Installer repair

After installation, `/install` is disabled for anonymous visitors. Signed-in administrators with `settings.manage` can use repair to add missing schema pieces after an upgrade.

## Backups

Use **Backups** as a global administrator to create/download full JSON database snapshots. Restore is destructive and requires typing `RESTORE`.

## Audit

The audit log records login, workflow, tender, document, payment and backup actions. Hash-chain fields are present for tamper-evident review, but external notarisation is not implemented.
