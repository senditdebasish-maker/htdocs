'use strict';

const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const db = require('../db/database');
const config = require('../config');
const { uuid } = require('../util/uuid');
const { notFound, validation, forbidden, badRequest } = require('../util/errors');

/**
 * File storage + document metadata service.
 *
 * Files are stored on disk under data/uploads keyed by a random stored name.
 * Metadata (original name, mime, size, sha256, category, uploader, version)
 * lives in the `documents` table. Downloads are audit-logged.
 */

const ALLOWED_EXT = new Set([
  '.pdf', '.png', '.jpg', '.jpeg', '.gif', '.webp',
  '.xls', '.xlsx', '.csv', '.doc', '.docx',
  '.dwg', '.dxf', '.zip', '.txt', '.rtf',
]);

const ALLOWED_MIME = new Set([
  'application/pdf',
  'image/png', 'image/jpeg', 'image/gif', 'image/webp',
  'application/vnd.ms-excel',
  'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
  'text/csv',
  'application/msword',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
  'application/zip', 'application/x-zip-compressed',
  'text/plain', 'application/rtf', 'application/octet-stream',
]);

function extOf(name) {
  return path.extname(String(name || '')).toLowerCase();
}

function validateFile(name, mime, size) {
  if (!name) throw validation('File name is required');
  if (size === 0) throw validation('File is empty');
  if (size > config.maxUploadBytes) {
    throw validation(`File exceeds the maximum allowed size of ${Math.round(config.maxUploadBytes / 1024 / 1024)} MB`);
  }
  const ext = extOf(name);
  if (!ALLOWED_EXT.has(ext)) throw validation(`File type "${ext}" is not allowed`);
  if (mime && mime !== 'application/octet-stream' && !ALLOWED_MIME.has(mime)) {
    throw validation(`File MIME type "${mime}" is not allowed`);
  }
  return true;
}

function sha256(buffer) {
  return crypto.createHash('sha256').update(buffer).digest('hex');
}

function storeFile({ entityType, entityId = null, category, originalName, buffer, mimeType = null, uploadedBy = null, version = 1 }) {
  validateFile(originalName, mimeType, buffer.length);
  const storedName = `${uuid()}${extOf(originalName)}`;
  const absDir = config.uploadsDir();
  fs.writeFileSync(path.join(absDir, storedName), buffer);
  const hash = sha256(buffer);
  const r = db.run(
    `INSERT INTO documents
      (uid, entity_type, entity_id, category, original_name, stored_name, mime_type, size_bytes, sha256, uploaded_by, version)
     VALUES (?,?,?,?,?,?,?,?,?,?,?)`,
    [uuid(), entityType, entityId, category, originalName, storedName, mimeType, buffer.length, hash, uploadedBy, version]
  );
  return getDocument(r.lastInsertRowid);
}

function getDocument(id) {
  const d = db.get('SELECT * FROM documents WHERE id = ?', [id]);
  if (!d) throw notFound('Document not found');
  return d;
}

function getDocumentByUid(uid) {
  const d = db.get('SELECT * FROM documents WHERE uid = ?', [uid]);
  if (!d) throw notFound('Document not found');
  return d;
}

function listDocuments(entityType, entityId) {
  return db.all(
    `SELECT d.*, u.name AS uploader_name
       FROM documents d LEFT JOIN users u ON u.id = d.uploaded_by
      WHERE d.entity_type = ? AND (? IS NULL OR d.entity_id = ?)
      ORDER BY d.created_at DESC`,
    [entityType, entityId, entityId]
  );
}

function filePath(doc) {
  return path.join(config.uploadsDir(), doc.stored_name);
}

function removeDocument(id) {
  const d = getDocument(id);
  const p = filePath(d);
  if (fs.existsSync(p)) fs.unlinkSync(p);
  return db.run('DELETE FROM documents WHERE id = ?', [id]).changes;
}

/** Record a download in the audit trail (callers provide actor via req). */
function logDownload(doc, req) {
  const audit = require('./auditService');
  audit.record({
    actor: req.user,
    action: 'document.download',
    entityType: 'document',
    entityId: doc.id,
    entityLabel: doc.original_name,
    ipAddress: req.ip,
    userAgent: req.get('user-agent'),
    requestId: req.requestId,
  });
}

module.exports = { validateFile, sha256, storeFile, getDocument, getDocumentByUid, listDocuments, filePath, removeDocument, logDownload, extOf };
