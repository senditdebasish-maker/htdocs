#!/usr/bin/env python3
"""
Static MySQL/MariaDB portability checks for the native PHP XAMPP app.

This does not replace a real MySQL import/runtime test. It catches issues that
previously blocked XAMPP-style acceptance, such as install.sql drifting from
app/schema.php, non-InnoDB tables, forward FK creation order and accidental
SQLite-only SQL in production paths.
"""
from __future__ import annotations

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCHEMA = ROOT / "app" / "schema.php"
INSTALL = ROOT / "install.sql"


def fail(msg: str) -> None:
    print(f"FAIL: {msg}")
    sys.exit(1)


def main() -> None:
    schema = SCHEMA.read_text(encoding="utf-8")
    install = INSTALL.read_text(encoding="utf-8")

    schema_tables = []
    for line in schema.splitlines():
        m = re.match(r"\s{8}'([a-zA-Z0-9_]+)'\s*=>\s*\[", line)
        if m:
            schema_tables.append(m.group(1))
    sql_tables = re.findall(r"CREATE TABLE `([^`]+)` \(", install)
    if schema_tables != sql_tables:
        fail(
            "install.sql table order/content does not match app/schema.php\n"
            f"schema-only={sorted(set(schema_tables) - set(sql_tables))}\n"
            f"sql-only={sorted(set(sql_tables) - set(schema_tables))}"
        )
    if len(schema_tables) != 59:
        fail(f"expected 59 schema tables, found {len(schema_tables)}")

    create_blocks = list(re.finditer(r"CREATE TABLE `([^`]+)` \((.*?)\n\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;", install, re.S))
    if len(create_blocks) != len(sql_tables):
        fail("every CREATE TABLE must specify ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci")

    pos = {table: idx for idx, table in enumerate(sql_tables)}
    forward_refs = []
    missing_refs = []
    fk_count = 0
    for m in create_blocks:
        table = m.group(1)
        body = m.group(2)
        for fk in re.finditer(r"FOREIGN KEY \(`([^`]+)`\) REFERENCES `([^`]+)`\(id\) ON DELETE (\w+)", body):
            fk_count += 1
            ref = fk.group(2)
            if ref not in pos:
                missing_refs.append((table, fk.group(1), ref))
            elif pos[ref] > pos[table]:
                forward_refs.append((table, fk.group(1), ref))
    if missing_refs:
        fail(f"foreign keys reference missing tables: {missing_refs}")
    if forward_refs:
        fail(f"install.sql creates tables before referenced tables: {forward_refs}")
    if fk_count < 100:
        fail(f"unexpectedly low FK count: {fk_count}")

    indexes = re.findall(r"CREATE (UNIQUE )?INDEX `([^`]+)`", install)
    normal = sum(1 for unique, _name in indexes if not unique)
    unique = sum(1 for unique, _name in indexes if unique)
    if normal != 14 or unique != 11:
        fail(f"unexpected index counts: normal={normal}, unique={unique}")

    if re.search(r"INSERT\s+INTO\s+`?users`?", install, re.I):
        fail("install.sql must not seed users or password hashes")

    disallowed_sqlite = re.compile(r"INSERT\s+OR\s+|INSERT\s+OR\s+REPLACE|strftime\s*\(|last_insert_rowid\s*\(|julianday\s*\(", re.I)
    offenders = []
    for path in [*ROOT.glob("*.php"), *Path(ROOT / "app").glob("*.php")]:
        text = path.read_text(encoding="utf-8", errors="ignore")
        if disallowed_sqlite.search(text):
            offenders.append(str(path.relative_to(ROOT)))
    if offenders:
        fail("SQLite-only SQL found in PHP files: " + ", ".join(offenders))

    if "SEED_ADMIN_PASSWORD', env('SEED_ADMIN_PASSWORD', '')" not in (ROOT / "config.php").read_text(encoding="utf-8"):
        fail("config.php should keep SEED_ADMIN_PASSWORD blank by default")

    print("PASS: static MySQL portability checks")
    print(f"tables={len(schema_tables)} foreign_keys={fk_count} indexes={normal} unique_indexes={unique} engines={len(create_blocks)}")


if __name__ == "__main__":
    main()
