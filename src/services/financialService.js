'use strict';

const db = require('../db/database');
const money = require('../util/money');

/**
 * Financial calculation service — the single home for money math.
 * All values are INTEGER minor units (paise). No floating-point arithmetic.
 */

/** BOQ item estimated amount = quantity × rate (+tax if configured). Inputs in minor. */
function boqItemAmount(item) {
  const base = money.qtyRateMinor(item.quantity, item.estimated_rate_minor);
  return item.tax_pct ? base + money.pctMinor(base, item.tax_pct) : base;
}

function boqTotals(tenderId) {
  const items = db.all('SELECT * FROM boq_items WHERE tender_id = ?', [tenderId]);
  let base = 0;
  let tax = 0;
  for (const it of items) {
    const b = money.qtyRateMinor(it.quantity, it.estimated_rate_minor);
    base += b;
    if (it.tax_pct) tax += money.pctMinor(b, it.tax_pct);
  }
  return { items, count: items.length, baseMinor: base, taxMinor: tax, totalMinor: base + tax };
}

/**
 * Compute a running bill. All inputs and outputs are integer MINOR units.
 * Input: { grossWorkValueMinor, previousCertifiedMinor, retentionPct, taxAmountMinor, deductionsMinor, recoveriesMinor }
 */
function computeBill({ grossWorkValueMinor, previousCertifiedMinor = 0, retentionPct = 0, taxAmountMinor = 0, deductionsMinor = 0, recoveriesMinor = 0 }) {
  const gross = Math.round(Number(grossWorkValueMinor) || 0);
  const prev = Math.round(Number(previousCertifiedMinor) || 0);
  const current = gross - prev;
  const cumulative = gross;
  const retention = retentionPct ? money.pctMinor(current, retentionPct) : 0;
  const ded = Math.round(Number(deductionsMinor) || 0) + Math.round(Number(recoveriesMinor) || 0);
  const tax = Math.round(Number(taxAmountMinor) || 0);
  const net = Math.max(0, current - retention - ded);
  return {
    grossMinor: gross,
    previousCertifiedMinor: prev,
    currentMinor: current,
    cumulativeMinor: cumulative,
    retentionMinor: retention,
    taxMinor: tax,
    deductionsMinor: ded,
    netPayableMinor: net,
  };
}

/** Rank financial bids for a tender. L1 = lowest quoted total. */
function rankFinancialBids(tenderId) {
  const bids = db.all(
    `SELECT fb.*, tb.contractor_id, tb.bid_status
       FROM financial_bids fb JOIN tender_bidders tb ON tb.id = fb.bidder_id
      WHERE fb.tender_id = ?`,
    [tenderId]
  );
  const ranked = bids
    .map((b) => ({ ...b, total: b.total_amount_minor }))
    .sort((a, b) => a.total - b.total);
  ranked.forEach((b, i) => {
    b.rank = i + 1;
    db.run('UPDATE tender_bidders SET rank = ? WHERE id = ?', [b.rank, b.bidder_id]);
  });
  return ranked.map((b) => ({ bidder_id: b.bidder_id, contractor_id: b.contractor_id, totalMinor: b.total, rank: b.rank }));
}

/** Estimate → award → certified → paid reconciliation for a project. */
function projectReconciliation(projectId) {
  const p = db.get('SELECT * FROM projects WHERE id = ?', [projectId]);
  if (!p) return null;
  const award = db.get('SELECT COALESCE(SUM(awarded_amount_minor),0) s FROM awards a JOIN tenders t ON t.id = a.tender_id WHERE t.project_id = ? AND a.status != ?', [projectId, 'cancelled']);
  const certified = db.get("SELECT COALESCE(SUM(current_bill_minor),0) s FROM bills WHERE project_id = ? AND status IN ('certified','approved','paid','partially_paid')", [projectId]);
  const paid = db.get("SELECT COALESCE(SUM(net_amount_minor),0) s FROM payments WHERE project_id = ? AND status = 'recorded'", [projectId]);
  return {
    sanctioned: p.sanctioned_amount_minor || p.technical_sanction_amount_minor || 0,
    estimated: p.estimate_amount_minor || 0,
    tenderValue: p.tender_value_minor || 0,
    awarded: award.s,
    certified: certified.s,
    paid: paid.s,
    balance: (p.sanctioned_amount_minor || 0) - paid.s,
  };
}

module.exports = { boqItemAmount, boqTotals, computeBill, rankFinancialBids, projectReconciliation };
