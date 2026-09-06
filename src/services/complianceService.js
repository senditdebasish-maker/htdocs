'use strict';

const db = require('../db/database');
const { uuid } = require('../util/uuid');
const { json, parseJson } = require('../db/database');
const money = require('../util/money');
const dates = require('../util/dates');

/**
 * Rules & Compliance engine.
 *
 * Evaluates an entity (currently: tender) against the active rules of a rule
 * set. Each rule produces a "finding" with:
 *   - what is wrong / what was checked
 *   - why it matters
 *   - which rule & source (reference) was used
 *   - what the user should do
 *   - whether it blocks workflow progression
 *
 * The engine deliberately distinguishes *system checks* from *legal advice*:
 * findings never state "this tender is legally compliant" — only that it
 * passes the configured system checks. Where a rule cannot be established
 * confidently, severity is `verification_required` and the wording is
 * "Verification Required".
 */

const SEVERITY = {
  info: 'INFO',
  warning: 'WARNING',
  blocking: 'BLOCKING',
  verification_required: 'VERIFICATION REQUIRED',
};

function getRuleset(id) {
  return db.get('SELECT * FROM rulesets WHERE id = ?', [id]);
}

function getRules(rulesetId) {
  return db.all('SELECT * FROM rules WHERE ruleset_id = ? AND is_active = 1 ORDER BY sort_order, id', [rulesetId]);
}

function refLabel(refId) {
  if (!refId) return null;
  const r = db.get('SELECT * FROM rule_references WHERE id = ?', [refId]);
  if (!r) return null;
  return {
    title: r.title,
    authority: r.authority,
    reference_number: r.reference_number,
    source_url: r.source_url,
    manual_name: r.manual_name,
  };
}

/** Tender documents grouped by category. */
function tenderDocCategories(tenderId) {
  return db.all('SELECT category, COUNT(*) c FROM documents WHERE entity_type = ? AND entity_id = ? GROUP BY category', [
    'tender',
    tenderId,
  ]);
}

function makeFinding({ rule, status, message, why, action, source, blocks }) {
  return {
    ruleCode: rule.code,
    ruleTitle: rule.title,
    ruleCategory: rule.category,
    severity: rule.severity,
    severityLabel: SEVERITY[rule.severity] || rule.severity,
    status, // 'pass' | 'fail' | 'info' | 'verification'
    message,
    why,
    action,
    source,
    blocksWorkflow: !!blocks,
  };
}

function evaluateRule(rule, tender, ctx) {
  const config = parseJson(rule.config, {});
  const source = refLabel(rule.reference_id);
  const blocks = rule.severity === 'blocking';
  const valueMinor = tender.tender_value_minor || tender.estimated_cost_minor || 0;

  switch (rule.rule_type) {
    case 'approval_required': {
      const field = config.field;
      const val = tender[field];
      if (!val || String(val).trim() === '') {
        return makeFinding({
          rule, status: 'fail',
          message: `Required approval is missing: "${rule.title}". The field "${field}" is empty.`,
          why: 'A work/tender cannot proceed to publication without the required approval recorded.',
          action: 'Record the approval number, date and authority on the tender (Step 2 of the wizard), or obtain the approval before proceeding.',
          source, blocks,
        });
      }
      return makeFinding({
        rule, status: 'pass',
        message: `Required approval present (${field} recorded).`,
        why: 'This check confirms the required approval field is populated.',
        action: null, source, blocks: false,
      });
    }

    case 'document_required': {
      const kinds = config.docKind ? [config.docKind] : (config.docKinds || []);
      const have = new Set(ctx.docCategories.map((d) => d.category));
      const boqItemsSatisfy = config.boqItemsSatisfy && ctx.boqItemCount > 0;
      const missing = kinds.filter((k) => !have.has(k));
      if (missing.length && !boqItemsSatisfy) {
        return makeFinding({
          rule, status: 'fail',
          message: `Required document(s) missing: ${missing.join(', ')}.`,
          why: 'The required supporting document must be attached before the record can be considered complete.',
          action: 'Upload the required document(s) in the tender file room, or attach them to the record.',
          source, blocks,
        });
      }
      return makeFinding({
        rule, status: 'pass',
        message: 'Required document(s) present.',
        why: 'This check confirms the required document category is attached.',
        action: null, source, blocks: false,
      });
    }

    case 'notice_period': {
      const tiers = (config.tiers || []).sort((a, b) => (a.max_minor ?? Infinity) - (b.max_minor ?? Infinity));
      const tier = tiers.find((t) => valueMinor <= (t.max_minor ?? Infinity)) || tiers[tiers.length - 1];
      const requiredDays = tier ? tier.days : null;
      const days = dates.daysBetween(tender.publication_date, tender.bid_close_date);
      if (!tender.publication_date || !tender.bid_close_date) {
        return makeFinding({
          rule, status: 'verification',
          message: 'Notice-period check could not run: publication date or bid-closing date is not set.',
          why: 'The configured notice-period rule requires both dates to compute the gap.',
          action: 'Set the publication and bid-closing dates, then re-run the compliance check.',
          source, blocks: false,
        });
      }
      if (days === null || days < 0) {
        return makeFinding({
          rule, status: 'fail',
          message: `Bid-closing date (${tender.bid_close_date}) is before the publication date (${tender.publication_date}).`,
          why: 'Bids cannot close before the tender is published.',
          action: 'Correct the schedule so bid closing is after publication.',
          source, blocks: true,
        });
      }
      if (requiredDays !== null && days < requiredDays) {
        return makeFinding({
          rule, status: 'fail',
          message: `Notice period is ${days} day(s); the configured rule requires at least ${requiredDays} day(s) for this value band.`,
          why: `The configured notice-period rule (source: ${source ? source.reference_number : rule.title}) sets a minimum of ${requiredDays} days for the tender's value band.`,
          action: 'Extend the bid-closing date to satisfy the notice period, or select/configure a rule set that matches your authority\'s requirements.',
          source, blocks: false, // warning-level per rule severity
        });
      }
      return makeFinding({
        rule, status: 'pass',
        message: `Notice period OK (${days} day(s) ≥ ${requiredDays} required).`,
        why: 'The configured notice-period check is satisfied.',
        action: null, source, blocks: false,
      });
    }

    case 'amount_threshold': {
      if (config.required_tender_type) {
        const matchesCategory = !config.procurement_category || tender.procurement_category === config.procurement_category;
        if (matchesCategory && valueMinor >= (config.min_minor || 0)) {
          if ((tender.tender_type || '').toLowerCase() !== String(config.required_tender_type).toLowerCase()) {
            return makeFinding({
              rule, status: 'fail',
              message: `This ${tender.procurement_category} tender (value ${money.formatMinorINR(valueMinor)}) exceeds the configured threshold and should use "${config.required_tender_type}". Current type: ${tender.tender_type}.`,
              why: 'The configured threshold rule mandates a specific tender method above this value.',
              action: 'Change the tender type, or record a justification/reference if a different method is authorised for this case.',
              source, blocks,
            });
          }
          return makeFinding({
            rule, status: 'pass',
            message: `Tender method "${tender.tender_type}" satisfies the configured threshold rule.`,
            why: 'The configured threshold requirement is met.',
            action: null, source, blocks: false,
          });
        }
        return makeFinding({
          rule, status: 'info',
          message: 'Threshold rule not applicable at this value/category.',
          why: 'The configured threshold does not apply to this tender.',
          action: null, source, blocks: false,
        });
      }
      if (config.bands) {
        const band = config.bands.find((b) => valueMinor <= (b.max_minor ?? Infinity));
        return makeFinding({
          rule, status: 'info',
          message: `For this value (${money.formatMinorINR(valueMinor)}), the configured publication manner is: ${band.manner}.`,
          why: 'Publication requirements vary by estimated value.',
          action: 'Ensure the tender is published per the indicated manner.',
          source, blocks: false,
        });
      }
      return makeFinding({ rule, status: 'info', message: 'Rule requires manual review.', why: rule.description, action: null, source, blocks: false });
    }

    case 'field_required': {
      const missing = (config.fields || []).filter((f) => !tender[f] || String(tender[f]).trim() === '');
      if (missing.length) {
        return makeFinding({
          rule, status: 'verification',
          message: `Verification Required: field(s) not recorded — ${missing.join(', ')}.`,
          why: rule.description || 'This condition requires information that is not currently recorded.',
          action: 'Provide the missing information or confirm with the competent authority whether this condition applies.',
          source, blocks: false,
        });
      }
      return makeFinding({
        rule, status: 'pass',
        message: 'Required condition information is present.',
        why: rule.description, action: null, source, blocks: false,
      });
    }

    case 'date_sequence': {
      const pairs = config.pairs || [];
      for (const [a, b] of pairs) {
        if (tender[a] && tender[b] && dates.isAfter(tender[a], tender[b])) {
          return makeFinding({
            rule, status: 'fail',
            message: `Date sequence invalid: ${a} (${tender[a]}) must not be after ${b} (${tender[b]}).`,
            why: 'Workflow dates must be chronologically consistent.',
            action: 'Correct the date sequence.',
            source, blocks: true,
          });
        }
      }
      return makeFinding({ rule, status: 'pass', message: 'Date sequence OK.', why: 'Dates are in a valid order.', action: null, source, blocks: false });
    }

    case 'manual':
    default:
      return makeFinding({
        rule, status: rule.severity === 'verification_required' ? 'verification' : 'info',
        message: rule.severity === 'verification_required' ? 'Verification Required — manual check.' : rule.title,
        why: rule.description || 'This is a configured manual check.',
        action: rule.severity === 'verification_required' ? 'Verify this condition against the referenced source and current circulars, and record the outcome.' : null,
        source, blocks: false,
      });
  }
}

function summarize(findings) {
  const s = { blocking: 0, warning: 0, info: 0, verification_required: 0, passed: 0 };
  for (const f of findings) {
    if (f.severity === 'blocking' && f.status === 'fail') s.blocking += 1;
    else if (f.severity === 'warning' && f.status === 'fail') s.warning += 1;
    else if (f.severity === 'verification_required') s.verification_required += 1;
    else if (f.status === 'pass') s.passed += 1;
    else s.info += 1;
  }
  return s;
}

/**
 * Evaluate a tender against a rule set.
 * Returns { ruleset, findings, summary } and persists a snapshot.
 */
function evaluateTender(tender, opts = {}) {
  const rulesetId = opts.rulesetId || tender.ruleset_id;
  let ruleset = rulesetId ? getRuleset(rulesetId) : null;
  let rules = [];
  if (!ruleset) {
    // Fall back to the first active rule set.
    ruleset = db.get('SELECT * FROM rulesets WHERE is_active = 1 ORDER BY id LIMIT 1') || null;
    rules = ruleset ? getRules(ruleset.id) : [];
  } else {
    rules = getRules(rulesetId);
  }
  const ctx = {
    docCategories: tenderDocCategories(tender.id),
    boqItemCount: db.get('SELECT COUNT(*) c FROM boq_items WHERE tender_id = ?', [tender.id]).c,
  };
  const findings = rules.map((r) => evaluateRule(r, tender, ctx));
  const summary = summarize(findings);

  if (opts.persist) {
    db.run(
      `INSERT INTO rule_evaluations (uid, ruleset_id, entity_type, entity_id, results, summary, evaluated_by)
       VALUES (?,?,?,?,?,?,?)`,
      [uuid(), ruleset ? ruleset.id : null, 'tender', tender.id, json(findings), json(summary), opts.actorId || null]
    );
    db.run('UPDATE tenders SET compliance_status = ? WHERE id = ?', [summary.blocking > 0 ? 'blocking' : summary.warning > 0 ? 'warning' : 'passed', tender.id]);
  }

  return { ruleset, findings, summary };
}

function getLatestEvaluation(entityType, entityId) {
  const r = db.get(
    'SELECT * FROM rule_evaluations WHERE entity_type = ? AND entity_id = ? ORDER BY id DESC LIMIT 1',
    [entityType, entityId]
  );
  if (!r) return null;
  return { ...r, results: parseJson(r.results, []), summary: parseJson(r.summary, {}) };
}

module.exports = { evaluateTender, getLatestEvaluation, getRuleset, getRules, summarize };
