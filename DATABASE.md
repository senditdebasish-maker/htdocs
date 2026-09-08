# Database Guide

## Driver

Production uses MySQL/MariaDB through PDO. SQLite support exists only for development experiments when the PHP SQLite extension is available.

## Schema source of truth

`app/schema.php` defines the schema. `install.sql` is generated from that definition for phpMyAdmin imports.

The schema contains tables for:

- RBAC: roles, permissions, users, role assignments.
- Panchayat and financial-year reference data.
- Rule sets, rule references and rule evaluations.
- Schemes, funds, budget allocations and projects.
- Tender files: tenders, versions, approval docs, NIT versions, BOQ versions/items, corrigenda, cancellations, re-tenders.
- Bidders, bid documents, technical openings, criteria, technical/financial evaluations, rankings and awards.
- Agreements, work orders, progress, measurements, bills, payments and completion.
- Documents, document generations, notifications, audit logs, imports and backups.

## Tenant isolation

Most business tables include nullable `panchayat_id` for upgrade compatibility. New writes attach a Panchayat id through server-side service helpers. Scoped users can access only matching Panchayat rows; global administrators without a Panchayat id can access all data.

During upgrade, `schema_backfill_panchayat_ids()` fills existing single-tenant rows with the first Panchayat id where a table has `panchayat_id` and the value is null.

## Constraints and indexes

- Foreign keys are generated for declared references.
- Composite unique indexes scope important numbers by Panchayat/FY where appropriate. The schema healer attempts to drop older single-column unique indexes that these scoped indexes replace on MySQL upgrades.
- MySQL/MariaDB table DDL explicitly uses `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci` so foreign keys and Unicode data work consistently on XAMPP.
- Table creation order avoids forward foreign-key references for stricter MySQL/MariaDB import behavior.
- Secondary indexes cover list/report access patterns.
- Audit rows include `prev_hash` and `entry_hash` for tamper-evident chaining.

## Non-destructive repair

`schema_heal()`:

1. Detects missing tables/columns.
2. Creates/adds missing pieces with foreign-key checks disabled during repair.
3. Re-runs idempotent seeders.
4. Backfills tenant ids.
5. Creates indexes.
6. Stamps `settings.schema.version`.

It does not drop data. Destructive rebuild is limited to explicit `php seed.php --reset` or full restore by a global administrator.

## install.sql

`install.sql` creates the database and all tables/indexes but does not hard-code an administrator password. Use the web installer or CLI seeder after import.
