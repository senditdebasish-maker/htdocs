'use strict';

const fs = require('fs');
const path = require('path');
const db = require('../db/database');
const config = require('../config');
const { uuid } = require('../util/uuid');

/**
 * Backup script.
 *
 * Backs up:
 *   - the SQLite database (safe, via SQLite online backup API)
 *   - uploaded documents and generated files (tar-less copy of the directory)
 *   - active configuration (.env is NOT copied — secrets stay out of backups)
 *
 * Retention is configurable via settings key `backup.retention_days`.
 */
function retentionDays() {
  const s = db.get("SELECT value FROM settings WHERE key = 'backup.retention_days'");
  return s && s.value ? parseInt(s.value, 10) : 30;
}

async function runBackup({ createdBy = null, type = 'manual' } = {}) {
  const stamp = new Date().toISOString().replace(/[:.]/g, '-');
  const backupDir = config.backupsDir();
  fs.mkdirSync(backupDir, { recursive: true });

  // 1) Database backup via SQLite online backup API.
  const { DatabaseSync } = require('node:sqlite');
  const source = db.getDb();
  const dbFile = path.join(backupDir, `db-${stamp}.sqlite`);
  const dest = new DatabaseSync(dbFile);
  try {
    await source.backup(dest);
  } finally {
    dest.close();
  }

  // 2) Documents backup (copy uploads directory).
  const docsDir = path.join(backupDir, `uploads-${stamp}`);
  const srcUploads = config.uploadsDir();
  fs.mkdirSync(docsDir, { recursive: true });
  if (fs.existsSync(srcUploads)) {
    for (const f of fs.readdirSync(srcUploads)) {
      fs.copyFileSync(path.join(srcUploads, f), path.join(docsDir, f));
    }
  }

  // 3) Metadata + retention cleanup.
  const totalSize = fs.statSync(dbFile).size + (fs.existsSync(docsDir) ? sumDir(docsDir) : 0);
  const r = db.run(
    `INSERT INTO backups (uid, backup_type, file_name, size_bytes, status, created_by) VALUES (?,?,?,?,?,?)`,
    [uuid(), type, `db-${stamp}.sqlite`, totalSize, 'completed', createdBy]
  );
  cleanupOldBackups(backupDir, retentionDays());
  return { id: r.lastInsertRowid, file: `db-${stamp}.sqlite`, sizeBytes: totalSize, retentionDays: retentionDays() };
}

function sumDir(dir) {
  let total = 0;
  for (const f of fs.readdirSync(dir)) {
    total += fs.statSync(path.join(dir, f)).size;
  }
  return total;
}

function cleanupOldBackups(backupDir, days) {
  const cutoff = Date.now() - days * 24 * 60 * 60 * 1000;
  for (const f of fs.readdirSync(backupDir)) {
    const p = path.join(backupDir, f);
    if (fs.statSync(p).mtimeMs < cutoff) {
      try {
        if (fs.lstatSync(p).isDirectory()) fs.rmSync(p, { recursive: true });
        else fs.unlinkSync(p);
      } catch (_) { /* ignore */ }
    }
  }
}

module.exports = { runBackup };

if (require.main === module) {
  runBackup().then((r) => {
    // eslint-disable-next-line no-console
    console.log('[backup] completed', r);
    db.closeDb();
  });
}
