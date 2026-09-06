'use strict';

const PDFDocument = require('pdfkit');
const fs = require('fs');
const path = require('path');
const config = require('../config');
const { uuid } = require('../util/uuid');

/**
 * A4 PDF rendering engine (PDFKit).
 *
 * Provides:
 *  - A4 page with proper margins
 *  - running header (Panchayat letterhead) and footer (page numbers, generation
 *    timestamp, verification code) on every page
 *  - tables with repeating headers, no clipped text, no broken rows
 *  - signature blocks and annexure support
 *  - document metadata (title, number, FY, date, version)
 */

const A4 = { width: 595.28, height: 841.89 };
const MARGIN = { top: 92, bottom: 70, left: 56, right: 56 };

class PdfBuilder {
  constructor({ title, panchayat = null, meta = {} } = {}) {
    this.title = title || 'Document';
    this.panchayat = panchayat || {};
    this.meta = meta; // { docNumber, fy, date, version, verificationCode }
    this.doc = new PDFDocument({ size: 'A4', margins: MARGIN, bufferPages: true });
    this.doc.info.Title = title;
    if (meta.docNumber) this.doc.info.Subject = meta.docNumber;
  }

  header() {
    const p = this.panchayat;
    const center = (text, opts = {}) => this.doc.font('Helvetica-Bold').fontSize(opts.size || 12).text(text, { align: 'center', ...opts });
    center(p.gram_panchayat || config.systemName, { size: 13 });
    if (p.office_address) {
      this.doc.font('Helvetica').fontSize(8.5).text(p.office_address, { align: 'center' });
    }
    const contact = [p.phone, p.email].filter(Boolean).join(' | ');
    if (contact) this.doc.font('Helvetica').fontSize(8.5).text(contact, { align: 'center' });
    this.doc.moveDown(0.2);
    this.doc.moveTo(MARGIN.left, this.doc.y).lineTo(A4.width - MARGIN.right, this.doc.y).strokeColor('#000').lineWidth(0.8).stroke();
    this.doc.moveDown(0.3);
  }

  footer() {
    const range = this.doc.bufferedPageRange();
    const pages = range.count;
    for (let i = 0; i < pages; i++) {
      this.doc.switchToPage(i);
      const y = A4.height - 40;
      this.doc.moveTo(MARGIN.left, y).lineTo(A4.width - MARGIN.right, y).strokeColor('#888').lineWidth(0.5).stroke();
      this.doc.font('Helvetica').fontSize(7.5).fillColor('#555');
      this.doc.text(`Page ${i + 1} of ${pages}`, MARGIN.left, y + 6, { width: 200 });
      const right = `${this.meta.generatedAt || ''}  •  ${this.meta.verificationCode ? 'Code: ' + this.meta.verificationCode : ''}`;
      this.doc.text(right, MARGIN.left + 200, y + 6, { width: A4.width - MARGIN.left - MARGIN.right - 200, align: 'right' });
      this.doc.fillColor('#000');
    }
  }

  heading(text, opts = {}) {
    this.doc.moveDown(0.3);
    this.doc.font('Helvetica-Bold').fontSize(opts.size || 14).text(text, { align: 'center', underline: opts.underline });
    this.doc.moveDown(0.3);
  }

  para(text, opts = {}) {
    this.doc.font('Helvetica').fontSize(opts.size || 10).text(text || '', { lineGap: 2, align: opts.align || 'left' });
    this.doc.moveDown(0.2);
  }

  labelValue(label, value) {
    this.doc.font('Helvetica-Bold').fontSize(9.5).text(`${label}: `, { continued: true });
    this.doc.font('Helvetica').fontSize(9.5).text(String(value ?? ''));
  }

  /** Render a table. columns: [{key, header, width, align}] rows: objects. */
  table(columns, rows, opts = {}) {
    const total = columns.reduce((s, c) => s + (c.width || 1), 0);
    const usable = A4.width - MARGIN.left - MARGIN.right;
    const colWidths = columns.map((c) => (c.width || 1) / total * usable);

    const headerFont = 'Helvetica-Bold';
    const bodyFont = 'Helvetica';
    const fontSize = opts.fontSize || 8.5;
    const pad = 4;

    const drawRow = (cells, font, isHeader) => {
      // Measure row height to avoid broken rows.
      let maxLines = 1;
      const wrapped = cells.map((text, i) => {
        const w = colWidths[i] - pad * 2;
        const t = String(text ?? '');
        const lines = this.doc.font(font).fontSize(fontSize).heightOfString(t, { width: w }) / (fontSize * 1.15);
        const n = Math.max(1, Math.ceil(lines));
        maxLines = Math.max(maxLines, n);
        return { text: t, width: w, lines: n };
      });
      const rowH = maxLines * fontSize * 1.3 + pad * 2;
      // Page break if needed
      if (this.doc.y + rowH > A4.height - MARGIN.bottom) {
        this.doc.addPage();
        this.header();
        if (!isHeader) this.drawHeaderRow(colWidths, headerFont, fontSize, pad);
      }
      const startY = this.doc.y;
      wrapped.forEach((cell, i) => {
        const x = MARGIN.left + colWidths.slice(0, i).reduce((a, b) => a + b, 0);
        this.doc.rect(x, startY, colWidths[i], rowH).stroke('#999');
        this.doc.font(font).fontSize(fontSize).text(cell.text, x + pad, startY + pad, { width: cell.width });
      });
      this.doc.y = startY + rowH;
      return rowH;
    };

    this.drawHeaderRow = (widths, font, fsize, padp) => {
      const y = this.doc.y;
      columns.forEach((c, i) => {
        const x = MARGIN.left + widths.slice(0, i).reduce((a, b) => a + b, 0);
        this.doc.rect(x, y, widths[i], fsize * 1.4 + padp * 2).fill('#eee');
        this.doc.fillColor('#000').font(font).fontSize(fsize).text(c.header, x + padp, y + padp, { width: widths[i] - padp * 2 });
      });
      this.doc.y = y + fsize * 1.4 + padp * 2;
    };

    this.drawHeaderRow(colWidths, headerFont, fontSize, pad);
    for (const row of rows) {
      const cells = columns.map((c) => (c.render ? c.render(row, c) : row[c.key]));
      drawRow(cells, bodyFont, false);
    }
    this.doc.moveDown(0.4);
  }

  signatureBlock(entries) {
    this.doc.moveDown(1.5);
    const colW = (A4.width - MARGIN.left - MARGIN.right) / Math.max(entries.length, 1);
    let x = MARGIN.left;
    for (const e of entries) {
      this.doc.font('Helvetica-Bold').fontSize(9.5).text(e.title || '', x, this.doc.y, { width: colW - 8 });
      this.doc.font('Helvetica').fontSize(8.5).text(e.designation || '', x, this.doc.y, { width: colW - 8 });
      x += colW;
    }
  }

  /** Watermark/notice that this is a system-generated draft. */
  generationNotice() {
    this.doc.moveDown(1);
    this.doc.font('Helvetica-Oblique').fontSize(7.5).fillColor('#555').text(
      `Generated by ${config.systemName} on ${this.meta.generatedAt || ''}. ` +
      `${this.meta.verificationCode ? 'Verification code: ' + this.meta.verificationCode + '. ' : ''}` +
      'This is a system-generated draft based on configured templates and is not an official Government of West Bengal document unless duly signed and issued by the competent authority.',
      { align: 'left', lineGap: 1 }
    );
    this.doc.fillColor('#000');
  }

  build() {
    this.header();
    return this.doc;
  }

  finish() {
    this.footer();
    this.doc.end();
  }

  saveTo(filename) {
    return new Promise((resolve, reject) => {
      const outPath = path.join(config.generatedDir(), filename);
      const stream = fs.createWriteStream(outPath);
      this.doc.pipe(stream);
      this.finish();
      stream.on('finish', () => resolve(outPath));
      stream.on('error', reject);
    });
  }

  toBuffer() {
    return new Promise((resolve, reject) => {
      const chunks = [];
      this.doc.on('data', (c) => chunks.push(c));
      this.doc.on('end', () => resolve(Buffer.concat(chunks)));
      this.doc.on('error', reject);
      this.finish();
    });
  }
}

/** Build and save a PDF, returning { path, filename, buffer } and registering a generation record. */
async function renderPdf(builder, filenameBase, { docType, entityType, entityId, templateId, templateVersion, generatedBy, verificationCode }) {
  const filename = `${filenameBase}-${Date.now()}-${uuid().slice(0, 6)}.pdf`;
  const outPath = await builder.saveTo(filename);
  const buffer = fs.readFileSync(outPath);
  const documents = require('./documentService');
  const db = require('../db/database');
  const docRec = documents.storeFile({
    entityType: entityType || 'generated',
    entityId,
    category: docType,
    originalName: filename,
    buffer,
    mimeType: 'application/pdf',
    uploadedBy: generatedBy,
  });
  const code = verificationCode || uuid().slice(0, 8).toUpperCase();
  db.run(
    `INSERT INTO document_generations (uid, doc_type, entity_type, entity_id, template_id, template_version, document_id, verification_code, generated_by)
     VALUES (?,?,?,?,?,?,?,?,?)`,
    [uuid(), docType, entityType, entityId, templateId || null, templateVersion || null, docRec.id, code, generatedBy]
  );
  return { path: outPath, filename, document: docRec, verificationCode: code };
}

module.exports = { PdfBuilder, renderPdf, A4, MARGIN };
