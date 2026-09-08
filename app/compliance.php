<?php
/**
 * Rules & Compliance engine.
 *
 * Evaluates an entity (currently: tender) against the active rules of a rule
 * set. Findings never claim a record is "legally compliant" — only that it
 * passes the configured system checks. Uncertain rules return
 * "Verification Required".
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

const SEVERITY_LABELS = [
    'info' => 'INFO',
    'warning' => 'WARNING',
    'blocking' => 'BLOCKING',
    'verification_required' => 'VERIFICATION REQUIRED',
];

function compliance_get_ruleset(int $id): ?array
{
    return DB::one('SELECT * FROM rulesets WHERE id = ?', [$id]);
}

function compliance_get_rules(int $rulesetId): array
{
    return DB::all('SELECT * FROM rules WHERE ruleset_id = ? AND is_active = 1 ORDER BY sort_order, id', [$rulesetId]);
}

function compliance_ref_label(?int $refId): ?array
{
    if (!$refId) {
        return null;
    }
    $r = DB::one('SELECT * FROM rule_references WHERE id = ?', [$refId]);
    if (!$r) {
        return null;
    }
    return [
        'title' => $r['title'],
        'authority' => $r['authority'],
        'reference_number' => $r['reference_number'],
        'source_url' => $r['source_url'],
        'manual_name' => $r['manual_name'],
    ];
}

function compliance_tender_doc_categories(int $tenderId): array
{
    return DB::all('SELECT category, COUNT(*) c FROM documents WHERE entity_type = ? AND entity_id = ? GROUP BY category', ['tender', $tenderId]);
}

function make_finding(array $rule, string $status, string $message, string $why, ?string $action, ?array $source, bool $blocks): array
{
    $severityLabel = $status === 'not_applicable' ? 'NOT APPLICABLE' : (SEVERITY_LABELS[$rule['severity']] ?? $rule['severity']);
    return [
        'ruleCode' => $rule['code'],
        'ruleTitle' => $rule['title'],
        'ruleCategory' => $rule['category'],
        'severity' => $rule['severity'],
        'severityLabel' => $severityLabel,
        'status' => $status,
        'message' => $message,
        'why' => $why,
        'action' => $action,
        'source' => $source,
        'blocksWorkflow' => $blocks,
    ];
}

function compliance_rule_applies(array $config, array $tender): bool
{
    $when = $config['when'] ?? null;
    if (!is_array($when) || !$when) {
        return true;
    }
    foreach ($when as $field => $expected) {
        $actual = $tender[$field] ?? null;
        $actualNorm = strtolower(trim((string) $actual));
        if (is_array($expected)) {
            $ok = false;
            foreach ($expected as $v) {
                if ($actualNorm === strtolower(trim((string) $v))) { $ok = true; break; }
            }
            if (!$ok) { return false; }
        } elseif ($actualNorm !== strtolower(trim((string) $expected))) {
            return false;
        }
    }
    return true;
}

function compliance_evaluate_rule(array $rule, array $tender, array $ctx): array
{
    $config = json_load($rule['config'], []);
    $source = compliance_ref_label($rule['reference_id'] !== null ? (int) $rule['reference_id'] : null);
    $blocks = $rule['severity'] === 'blocking';
    if (!compliance_rule_applies($config, $tender)) {
        return make_finding($rule, 'not_applicable', 'Rule not applicable for this tender.', $rule['description'] ?? '', null, $source, false);
    }
    $valueMinor = (int) ($tender['tender_value_minor'] ?: $tender['estimated_cost_minor'] ?: 0);

    switch ($rule['rule_type']) {
        case 'approval_required':
            $field = $config['field'] ?? '';
            $val = $tender[$field] ?? null;
            if (!$val || trim((string) $val) === '') {
                return make_finding($rule, 'fail',
                    'Required approval is missing: "' . $rule['title'] . '". The field "' . $field . '" is empty.',
                    'A work/tender cannot proceed to publication without the required approval recorded.',
                    'Record the approval number, date and authority on the tender, or obtain the approval before proceeding.',
                    $source, $blocks);
            }
            return make_finding($rule, 'pass',
                'Required approval present (' . $field . ' recorded).',
                'This check confirms the required approval field is populated.',
                null, $source, false);

        case 'document_required':
            $kinds = isset($config['docKind']) ? [$config['docKind']] : ($config['docKinds'] ?? []);
            $have = [];
            foreach ($ctx['docCategories'] as $d) {
                $have[$d['category']] = true;
            }
            $boqSatisfy = !empty($config['boqItemsSatisfy']) && $ctx['boqItemCount'] > 0;
            $missing = array_filter($kinds, function ($k) use ($have) { return empty($have[$k]); });
            if ($missing && !$boqSatisfy) {
                return make_finding($rule, 'fail',
                    'Required document(s) missing: ' . implode(', ', $missing) . '.',
                    'The required supporting document must be attached before the record can be considered complete.',
                    'Upload the required document(s) in the tender file room, or attach them to the record.',
                    $source, $blocks);
            }
            return make_finding($rule, 'pass',
                'Required document(s) present.',
                'This check confirms the required document category is attached.',
                null, $source, false);

        case 'notice_period':
            $tiers = $config['tiers'] ?? [];
            usort($tiers, function ($a, $b) {
                $am = $a['max_minor'] ?? PHP_INT_MAX;
                $bm = $b['max_minor'] ?? PHP_INT_MAX;
                return $am <=> $bm;
            });
            $tier = null;
            foreach ($tiers as $t) {
                if ($valueMinor <= ($t['max_minor'] ?? PHP_INT_MAX)) {
                    $tier = $t;
                    break;
                }
            }
            if ($tier === null && $tiers) {
                $tier = end($tiers);
            }
            $requiredDays = $tier['days'] ?? null;
            $days = days_between($tender['publication_date'] ?? null, $tender['bid_close_date'] ?? null);
            if (empty($tender['publication_date']) || empty($tender['bid_close_date'])) {
                return make_finding($rule, 'verification',
                    'Notice-period check could not run: publication date or bid-closing date is not set.',
                    'The configured notice-period rule requires both dates to compute the gap.',
                    'Set the publication and bid-closing dates, then re-run the compliance check.',
                    $source, false);
            }
            if ($days === null || $days < 0) {
                return make_finding($rule, 'fail',
                    'Bid-closing date (' . $tender['bid_close_date'] . ') is before the publication date (' . $tender['publication_date'] . ').',
                    'Bids cannot close before the tender is published.',
                    'Correct the schedule so bid closing is after publication.',
                    $source, true);
            }
            if ($requiredDays !== null && $days < $requiredDays) {
                return make_finding($rule, 'fail',
                    'Notice period is ' . $days . ' day(s); the configured rule requires at least ' . $requiredDays . ' day(s) for this value band.',
                    'The configured notice-period rule sets a minimum of ' . $requiredDays . ' days for this value band.',
                    'Extend the bid-closing date to satisfy the notice period, or select/configure a rule set that matches your authority\'s requirements.',
                    $source, false);
            }
            return make_finding($rule, 'pass',
                'Notice period OK (' . $days . ' day(s) ≥ ' . $requiredDays . ' required).',
                'The configured notice-period check is satisfied.',
                null, $source, false);

        case 'amount_threshold':
            if (!empty($config['required_tender_type'])) {
                $cat = !empty($config['procurement_category']) ? $config['procurement_category'] : null;
                $matches = !$cat || $tender['procurement_category'] === $cat;
                if ($matches && $valueMinor >= ($config['min_minor'] ?? 0)) {
                    if (strtolower((string) $tender['tender_type']) !== strtolower((string) $config['required_tender_type'])) {
                        return make_finding($rule, 'fail',
                            'This ' . $tender['procurement_category'] . ' tender (value ' . inr($valueMinor) . ') exceeds the configured threshold and should use "' . $config['required_tender_type'] . '". Current type: ' . $tender['tender_type'] . '.',
                            'The configured threshold rule mandates a specific tender method above this value.',
                            'Change the tender type, or record a justification/reference if a different method is authorised for this case.',
                            $source, $blocks);
                    }
                    return make_finding($rule, 'pass',
                        'Tender method "' . $tender['tender_type'] . '" satisfies the configured threshold rule.',
                        'The configured threshold requirement is met.',
                        null, $source, false);
                }
                return make_finding($rule, 'info',
                    'Threshold rule not applicable at this value/category.',
                    'The configured threshold does not apply to this tender.',
                    null, $source, false);
            }
            if (!empty($config['bands'])) {
                $band = null;
                foreach ($config['bands'] as $b) {
                    if ($valueMinor <= ($b['max_minor'] ?? PHP_INT_MAX)) {
                        $band = $b;
                        break;
                    }
                }
                if ($band === null) {
                    $band = end($config['bands']);
                }
                return make_finding($rule, 'info',
                    'For this value (' . inr($valueMinor) . '), the configured publication manner is: ' . $band['manner'] . '.',
                    'Publication requirements vary by estimated value.',
                    'Ensure the tender is published per the indicated manner.',
                    $source, false);
            }
            return make_finding($rule, 'info', 'Rule requires manual review.', $rule['description'] ?? '', null, $source, false);

        case 'field_required':
            $missing = [];
            foreach (($config['fields'] ?? []) as $f) {
                if (empty($tender[$f]) || trim((string) $tender[$f]) === '') {
                    $missing[] = $f;
                }
            }
            if ($missing) {
                return make_finding($rule, 'verification',
                    'Verification Required: field(s) not recorded — ' . implode(', ', $missing) . '.',
                    $rule['description'] ?? 'This condition requires information that is not currently recorded.',
                    'Provide the missing information or confirm with the competent authority whether this condition applies.',
                    $source, false);
            }
            return make_finding($rule, 'pass',
                'Required condition information is present.',
                $rule['description'] ?? '', null, $source, false);

        case 'date_sequence':
            foreach (($config['pairs'] ?? []) as $pair) {
                [$a, $b] = $pair;
                if (!empty($tender[$a]) && !empty($tender[$b]) && is_after($tender[$a], $tender[$b])) {
                    return make_finding($rule, 'fail',
                        'Date sequence invalid: ' . $a . ' (' . $tender[$a] . ') must not be after ' . $b . ' (' . $tender[$b] . ').',
                        'Workflow dates must be chronologically consistent.',
                        'Correct the date sequence.',
                        $source, true);
                }
            }
            return make_finding($rule, 'pass', 'Date sequence OK.', 'Dates are in a valid order.', null, $source, false);

        case 'manual':
        default:
            $isVerify = $rule['severity'] === 'verification_required';
            return make_finding($rule, $isVerify ? 'verification' : 'info',
                $isVerify ? 'Verification Required — manual check.' : $rule['title'],
                $rule['description'] ?? 'This is a configured manual check.',
                $isVerify ? 'Verify this condition against the referenced source and current circulars, and record the outcome.' : null,
                $source, false);
    }
}

function compliance_summarize(array $findings): array
{
    $s = ['blocking' => 0, 'warning' => 0, 'info' => 0, 'verification_required' => 0, 'not_applicable' => 0, 'passed' => 0];
    foreach ($findings as $f) {
        if ($f['status'] === 'not_applicable') {
            $s['not_applicable']++;
        } elseif ($f['severity'] === 'blocking' && $f['status'] === 'fail') {
            $s['blocking']++;
        } elseif ($f['severity'] === 'warning' && $f['status'] === 'fail') {
            $s['warning']++;
        } elseif ($f['severity'] === 'verification_required' || $f['status'] === 'verification') {
            $s['verification_required']++;
        } elseif ($f['status'] === 'pass') {
            $s['passed']++;
        } else {
            $s['info']++;
        }
    }
    return $s;
}

/** Evaluate a tender against a rule set; optionally persist a snapshot. */
function compliance_evaluate_tender(array $tender, array $opts = []): array
{
    $rulesetId = $opts['rulesetId'] ?? $tender['ruleset_id'] ?? null;
    $ruleset = $rulesetId ? compliance_get_ruleset((int) $rulesetId) : null;
    if (!$ruleset) {
        $ruleset = DB::one('SELECT * FROM rulesets WHERE is_active = 1 ORDER BY id LIMIT 1');
    }
    $rules = $ruleset ? compliance_get_rules((int) $ruleset['id']) : [];
    $ctx = [
        'docCategories' => compliance_tender_doc_categories((int) $tender['id']),
        'boqItemCount' => (int) DB::val('SELECT COUNT(*) FROM boq_items WHERE tender_id = ?', [(int) $tender['id']]),
    ];
    $findings = [];
    foreach ($rules as $r) {
        $findings[] = compliance_evaluate_rule($r, $tender, $ctx);
    }
    $summary = compliance_summarize($findings);

    if (!empty($opts['persist'])) {
        DB::insert(
            'INSERT INTO rule_evaluations (uid, panchayat_id, ruleset_id, entity_type, entity_id, results, summary, evaluated_by)
             VALUES (?,?,?,?,?,?,?,?)',
            [uid(), $tender['panchayat_id'] !== null ? (int) $tender['panchayat_id'] : null, $ruleset ? (int) $ruleset['id'] : null, 'tender', (int) $tender['id'], json_store($findings), json_store($summary), $opts['actorId'] ?? null]
        );
        $status = $summary['blocking'] > 0 ? 'blocking' : ($summary['warning'] > 0 ? 'warning' : (($summary['verification_required'] ?? 0) > 0 ? 'verification_required' : 'passed'));
        DB::run('UPDATE tenders SET compliance_status = ? WHERE id = ?', [$status, (int) $tender['id']]);
    }
    return ['ruleset' => $ruleset, 'findings' => $findings, 'summary' => $summary];
}

function compliance_get_latest(string $entityType, int $entityId): ?array
{
    $r = DB::one('SELECT * FROM rule_evaluations WHERE entity_type = ? AND entity_id = ? ORDER BY id DESC LIMIT 1', [$entityType, $entityId]);
    if (!$r) {
        return null;
    }
    return [
        'id' => (int) $r['id'],
        'ruleset_id' => $r['ruleset_id'],
        'findings' => json_load($r['results'], []),
        'summary' => json_load($r['summary'], []),
        'evaluated_at' => $r['evaluated_at'],
    ];
}
