<?php
/**
 * Database schema, defined once in a dialect-neutral form and rendered to
 * MySQL (XAMPP) or SQLite DDL on demand. Mirrors the application's canonical
 * schema (roles, permissions, panchayats, financial years, rules, projects,
 * tenders, bids, awards, execution, bills, payments, audit, etc.).
 */

declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Column DSL:
 *   ['id']                       auto-increment primary key
 *   ['uid']                      VARCHAR(36) NOT NULL UNIQUE
 *   ['pk','col1','col2']         composite primary key
 *   ['str','col',len,nullable?,unique?]
 *   ['text','col',nullable?]
 *   ['int','col',default?]        INTEGER (NULL default)
 *   ['big','col',default?]        money / large int (BIGINT in MySQL)
 *   ['real','col',default?]
 *   ['date','col',nullable?]      date string (VARCHAR(10))
 *   ['ts','col']                  created/updated timestamp
 *   ['tsx','col',nullable?]       nullable timestamp (no default)
 *   ['bool','col',default?]
 *   ['fk','col','table','on_delete','nullable?']
 *   ['json','col',nullable?]      JSON-serialised TEXT
 *   ['enum','col',[..values],default]  TEXT + CHECK (advisory)
 */
function schema_tables(): array
{
    return [
        // ================= v1 — identity / RBAC / panchayat / FY =================
        'roles' => [
            ['id'], ['uid'], ['str', 'code', 64, false, true], ['str', 'name', 120],
            ['text', 'description'], ['bool', 'is_system', 0], ['ts', 'created_at'], ['ts', 'updated_at'],
        ],
        'permissions' => [
            ['id'], ['str', 'code', 64, false, true], ['str', 'name', 160], ['str', 'category', 40],
        ],
        'role_permissions' => [
            ['fk', 'role_id', 'roles', 'CASCADE'], ['fk', 'permission_id', 'permissions', 'CASCADE'],
            ['pk', 'role_id', 'permission_id'],
        ],
        'panchayats' => [
            ['id'], ['uid'],
            ['str', 'state', 60, false], ['str', 'district', 80, true], ['str', 'block', 80, true],
            ['str', 'gram_panchayat', 160], ['str', 'gp_code', 40, true, true],
            ['text', 'office_address'], ['str', 'pin', 10, true], ['str', 'phone', 40, true], ['str', 'email', 190, true],
            ['str', 'pradhan', 120, true], ['str', 'upa_pradhan', 120, true], ['str', 'panchayat_secretary', 120, true],
            ['str', 'technical_officer', 120, true], ['str', 'accounts_officer', 120, true],
            ['text', 'letterhead_html', true], ['text', 'document_header_html', true], ['text', 'document_footer_html', true],
            ['json', 'signature_config', true], ['json', 'seal_config', true],
            ['bool', 'is_active', 1], ['ts', 'created_at'], ['ts', 'updated_at'],
        ],
        'users' => [
            ['id'], ['uid'],
            ['fk', 'panchayat_id', 'panchayats', 'RESTRICT', true],
            ['str', 'email', 190, false, true], ['str', 'password_hash', 255],
            ['str', 'name', 160], ['str', 'designation', 120, true], ['str', 'phone', 40, true],
            ['bool', 'is_active', 1], ['bool', 'is_global_admin', 0],
            ['str', 'totp_secret', 128, true], ['bool', 'totp_enabled', 0],
            ['tsx', 'last_login_at', true], ['int', 'failed_login_count', 0], ['tsx', 'locked_until', true],
            ['ts', 'created_at'], ['ts', 'updated_at'], ['tsx', 'deleted_at', true],
        ],
        'user_roles' => [
            ['fk', 'user_id', 'users', 'CASCADE'], ['fk', 'role_id', 'roles', 'CASCADE'],
            ['pk', 'user_id', 'role_id'],
        ],
        'panchayat_members' => [
            ['id'], ['uid'], ['fk', 'panchayat_id', 'panchayats', 'CASCADE'],
            ['str', 'role_name', 80], ['str', 'person_name', 160], ['str', 'designation', 120, true],
            ['str', 'phone', 40, true], ['str', 'email', 190, true],
            ['bool', 'is_authority', 0], ['int', 'authority_level'], ['ts', 'created_at'], ['ts', 'updated_at'],
        ],
        'financial_years' => [
            ['id'], ['uid'], ['str', 'label', 9, false, true], ['int', 'start_year'],
            ['date', 'start_date'], ['date', 'end_date'],
            ['enum', 'status', ['open', 'closed'], 'open'], ['bool', 'is_current', 0],
            ['fk', 'created_by', 'users', 'SET NULL', true], ['ts', 'created_at'], ['ts', 'updated_at'],
        ],

        // ================= v2 — rules & compliance =================
        'rulesets' => [
            ['id'], ['uid'], ['str', 'name', 200], ['str', 'issuing_authority', 200, true],
            ['str', 'jurisdiction', 80, true], ['date', 'effective_date', true], ['date', 'expiry_date', true],
            ['str', 'reference_number', 160, true], ['str', 'source_url', 500, true],
            ['int', 'source_document_id'], ['int', 'version', 1],
            ['str', 'procurement_type', 40, true], ['str', 'tender_type', 40, true],
            ['bool', 'is_active', 1], ['text', 'notes'], ['fk', 'created_by', 'users', 'SET NULL', true],
            ['ts', 'created_at'], ['ts', 'updated_at'],
        ],
        'rules' => [
            ['id'], ['uid'], ['fk', 'ruleset_id', 'rulesets', 'CASCADE'],
            ['str', 'code', 64], ['str', 'title', 200], ['text', 'description'],
            ['str', 'category', 40, false], ['enum', 'severity', ['info', 'warning', 'blocking', 'verification_required'], 'info'],
            ['enum', 'rule_type', ['manual', 'approval_required', 'document_required', 'notice_period', 'amount_threshold', 'date_sequence', 'field_required'], 'manual'],
            ['json', 'config'], ['bool', 'is_active', 1], ['int', 'reference_id'], ['int', 'sort_order', 0],
            ['ts', 'created_at'], ['ts', 'updated_at'],
        ],
        'rule_references' => [
            ['id'], ['uid'], ['str', 'title', 200], ['str', 'authority', 200, true],
            ['str', 'manual_name', 160, true], ['str', 'reference_number', 160, true],
            ['date', 'publication_date', true], ['date', 'effective_date', true],
            ['str', 'source_url', 500, true], ['int', 'source_document_id'],
            ['str', 'version', 40, true], ['text', 'notes'], ['ts', 'created_at'], ['ts', 'updated_at'],
        ],
        'rule_evaluations' => [
            ['id'], ['uid'], ['fk', 'panchayat_id', 'panchayats', 'RESTRICT', true], ['fk', 'ruleset_id', 'rulesets', 'SET NULL', true],
            ['str', 'entity_type', 40], ['int', 'entity_id'], ['text', 'results'], ['text', 'summary'],
            ['fk', 'evaluated_by', 'users', 'SET NULL', true], ['ts', 'evaluated_at'],
        ],

        // ================= v3 — schemes/funds/projects/contractors =================
        'schemes' => [
            ['id'], ['uid'], ['str', 'name', 200], ['str', 'code', 40, true, true],
            ['text', 'description', true], ['str', 'sponsoring_authority', 200, true],
            ['bool', 'is_active', 1], ['ts', 'created_at'], ['ts', 'updated_at'],
        ],
        'funds' => [
            ['id'], ['uid'], ['str', 'name', 200], ['str', 'code', 40, true, true],
            ['str', 'funding_source', 120, true], ['str', 'head_of_account', 40, true],
            ['bool', 'is_active', 1], ['ts', 'created_at'], ['ts', 'updated_at'],
        ],
        'budget_allocations' => [
            ['id'], ['uid'], ['fk', 'panchayat_id', 'panchayats', 'RESTRICT', true], ['fk', 'fy_id', 'financial_years', 'RESTRICT'],
            ['fk', 'scheme_id', 'schemes', 'RESTRICT', true], ['fk', 'fund_id', 'funds', 'RESTRICT', true],
            ['big', 'sanctioned_amount_minor', 0], ['ts', 'created_at'], ['ts', 'updated_at'],
        ],
        'projects' => [
            ['id'], ['uid'], ['fk', 'panchayat_id', 'panchayats', 'RESTRICT', true], ['fk', 'fy_id', 'financial_years', 'RESTRICT'],
            ['fk', 'scheme_id', 'schemes', 'RESTRICT', true], ['fk', 'fund_id', 'funds', 'RESTRICT', true],
            ['str', 'head_of_account', 40, true], ['str', 'project_code', 60, true, false],
            ['str', 'work_name', 255], ['text', 'description', true], ['str', 'location', 200, true],
            ['str', 'administrative_approval_no', 120, true], ['date', 'administrative_approval_date', true],
            ['str', 'administrative_approval_authority', 120, true], ['big', 'administrative_approval_amount_minor'],
            ['str', 'technical_sanction_no', 120, true], ['date', 'technical_sanction_date', true],
            ['str', 'technical_sanction_authority', 120, true], ['big', 'technical_sanction_amount_minor'],
            ['big', 'estimate_amount_minor'], ['big', 'sanctioned_amount_minor'],
            ['big', 'tender_value_minor'], ['big', 'awarded_amount_minor'],
            ['int', 'contractor_id'], ['int', 'work_order_id'],
            ['date', 'start_date', true], ['date', 'planned_completion_date', true], ['date', 'actual_completion_date', true],
            ['real', 'physical_progress', 0], ['real', 'financial_progress', 0],
            ['enum', 'status', ['planned', 'approved', 'tendered', 'awarded', 'in_progress', 'completed', 'closed', 'cancelled'], 'planned'],
            ['fk', 'created_by', 'users', 'SET NULL', true], ['ts', 'created_at'], ['ts', 'updated_at'], ['tsx', 'deleted_at', true],
        ],
        'procurement_plans' => [
            ['id'], ['uid'], ['fk', 'panchayat_id', 'panchayats', 'RESTRICT', true], ['fk', 'fy_id', 'financial_years', 'RESTRICT'],
            ['fk', 'scheme_id', 'schemes', 'RESTRICT', true], ['fk', 'fund_id', 'funds', 'RESTRICT', true],
            ['fk', 'project_id', 'projects', 'SET NULL', true], ['str', 'work_name', 255],
            ['big', 'estimated_amount_minor', 0], ['str', 'funding_source', 120, true],
            ['str', 'procurement_method', 60, true], ['date', 'expected_date', true],
            ['str', 'responsible_officer', 120, true], ['text', 'budget_provision'],
            ['enum', 'status', ['planned', 'initiated', 'tender_created', 'cancelled'], 'planned'],
            ['text', 'remarks'], ['fk', 'created_by', 'users', 'SET NULL', true],
            ['ts', 'created_at'], ['ts', 'updated_at'],
        ],
        'contractors' => [
            ['id'], ['uid'], ['fk', 'panchayat_id', 'panchayats', 'RESTRICT', true], ['str', 'contractor_code', 40, true, false], ['str', 'legal_name', 200],
            ['str', 'business_name', 200, true], ['text', 'address', true], ['str', 'mobile', 40, true],
            ['str', 'email', 190, true], ['str', 'registration_no', 120, true], ['str', 'registration_class', 60, true],
            ['date', 'registration_valid_from', true], ['date', 'registration_valid_to', true],
            ['str', 'pan', 20, true], ['str', 'gst', 20, true],
            ['str', 'bank_name', 160, true], ['str', 'bank_account_no', 40, true], ['str', 'bank_ifsc', 20, true],
            ['text', 'experience_summary', true], ['real', 'performance_rating'], ['text', 'performance_remarks', true],
            ['bool', 'is_debarred', 0], ['text', 'debarment_reason', true], ['date', 'debarment_from', true], ['date', 'debarment_to', true],
            ['enum', 'status', ['active', 'inactive', 'debarred'], 'active'],
            ['fk', 'created_by', 'users', 'SET NULL', true], ['ts', 'created_at'], ['ts', 'updated_at'], ['tsx', 'deleted_at', true],
        ],
        'contractor_documents' => [
            ['id'], ['uid'], ['fk', 'panchayat_id', 'panchayats', 'RESTRICT', true], ['fk', 'contractor_id', 'contractors', 'CASCADE'],
            ['str', 'doc_type', 40], ['str', 'doc_name', 200], ['int', 'document_id'],
            ['date', 'issued_date', true], ['date', 'expiry_date', true],
            ['enum', 'expiry_status', ['valid', 'expiring_soon', 'expired', 'verification_required', 'not_applicable'], 'valid'],
            ['text', 'remarks', true], ['ts', 'created_at'], ['ts', 'updated_at'],
        ],

        // ================= v4 — documents/tenders/NIT/BOQ/versions =================
        'documents' => [
            ['id'], ['uid'], ['fk', 'panchayat_id', 'panchayats', 'RESTRICT', true], ['str', 'entity_type', 40], ['int', 'entity_id'],
            ['str', 'category', 60], ['str', 'original_name', 255], ['str', 'stored_name', 255],
            ['str', 'mime_type', 120, true], ['int', 'size_bytes'], ['str', 'sha256', 64, true],
            ['fk', 'uploaded_by', 'users', 'SET NULL', true], ['int', 'version', 1],
            ['ts', 'created_at'], ['ts', 'updated_at'],
        ],
        'tenders' => [
            ['id'], ['uid'], ['fk', 'panchayat_id', 'panchayats', 'RESTRICT', true], ['fk', 'fy_id', 'financial_years', 'RESTRICT'],
            ['fk', 'project_id', 'projects', 'SET NULL', true], ['fk', 'scheme_id', 'schemes', 'SET NULL', true],
            ['fk', 'fund_id', 'funds', 'SET NULL', true], ['fk', 'ruleset_id', 'rulesets', 'SET NULL', true],
            ['str', 'tender_number', 80, false, false], ['str', 'nit_number', 80, true, false],
            ['str', 'tender_type', 40], ['str', 'procurement_category', 40], ['str', 'procurement_method', 60, true],
            ['str', 'title', 255], ['text', 'description', true], ['str', 'location', 200, true], ['str', 'work_name', 255, true],
            ['str', 'admin_approval_no', 120, true], ['date', 'admin_approval_date', true], ['str', 'admin_approval_authority', 120, true],
            ['big', 'admin_approval_amount_minor'],
            ['str', 'tech_sanction_no', 120, true], ['date', 'tech_sanction_date', true], ['str', 'tech_sanction_authority', 120, true],
            ['big', 'tech_sanction_amount_minor'],
            ['big', 'estimated_cost_minor', 0], ['big', 'tender_value_minor', 0],
            ['big', 'emd_minor'], ['text', 'emd_exemption_notes', true], ['big', 'tender_fee_minor'],
            ['big', 'security_deposit_minor'], ['real', 'security_deposit_pct'], ['json', 'tax_config', true],
            ['text', 'budget_provision', true], ['str', 'head_of_account', 40, true], ['int', 'completion_period_days'],
            ['text', 'technical_specification', true], ['text', 'eligibility_notes', true],
            ['text', 'general_conditions', true], ['text', 'special_conditions', true], ['text', 'payment_conditions', true],
            ['text', 'completion_conditions', true], ['text', 'extension_conditions', true], ['text', 'penalty_provisions', true],
            ['text', 'defect_liability', true],
            ['date', 'publication_date', true], ['date', 'bid_start_date', true], ['date', 'bid_close_date', true],
            ['date', 'technical_open_date', true], ['date', 'financial_open_date', true], ['int', 'bid_validity_days'],
            ['enum', 'status', ['draft', 'under_approval', 'approved', 'nit_generated', 'published', 'bidding', 'bid_closed', 'technical_evaluation', 'financial_evaluation', 'awarded', 'cancelled', 'retendered', 'closed'], 'draft'],
            ['str', 'compliance_status', 20, true], ['str', 'workflow_stage', 40, false], ['int', 'award_recommendation_id'],
            ['fk', 'created_by', 'users', 'SET NULL', true], ['ts', 'created_at'], ['ts', 'updated_at'], ['tsx', 'deleted_at', true],
        ],
        'tender_approval_docs' => [
            ['id'], ['uid'], ['fk', 'panchayat_id', 'panchayats', 'RESTRICT', true], ['fk', 'tender_id', 'tenders', 'CASCADE'],
            ['str', 'doc_kind', 40], ['str', 'doc_number', 120, true], ['date', 'doc_date', true],
            ['big', 'amount_minor'], ['str', 'authority', 120, true], ['int', 'document_id'],
            ['text', 'remarks'], ['ts', 'created_at'], ['ts', 'updated_at'],
        ],
        'tender_versions' => [
            ['id'], ['uid'], ['fk', 'panchayat_id', 'panchayats', 'RESTRICT', true], ['fk', 'tender_id', 'tenders', 'CASCADE'], ['int', 'version_no'],
            ['str', 'change_type', 40], ['text', 'reason'], ['text', 'snapshot'],
            ['fk', 'changed_by', 'users', 'SET NULL', true], ['ts', 'created_at'],
        ],
        'nit_versions' => [
            ['id'], ['uid'], ['fk', 'panchayat_id', 'panchayats', 'RESTRICT', true], ['fk', 'tender_id', 'tenders', 'CASCADE'], ['int', 'version_no'],
            ['int', 'template_id'], ['text', 'content_json'], ['fk', 'generated_by', 'users', 'SET NULL', true],
            ['int', 'document_id'], ['ts', 'generated_at'],
        ],
        'boq_items' => [
            ['id'], ['uid'], ['fk', 'panchayat_id', 'panchayats', 'RESTRICT', true], ['fk', 'tender_id', 'tenders', 'CASCADE'],
            ['str', 'item_no', 40], ['str', 'group_name', 120, true], ['str', 'description', 500],
            ['text', 'specification', true], ['str', 'unit', 40, true], ['real', 'quantity', 0],
            ['big', 'estimated_rate_minor', 0], ['real', 'tax_pct', 0], ['int', 'sort_order', 0],
            ['ts', 'created_at'], ['ts', 'updated_at'],
        ],
        'boq_versions' => [
            ['id'], ['uid'], ['fk', 'panchayat_id', 'panchayats', 'RESTRICT', true], ['fk', 'tender_id', 'tenders', 'CASCADE'], ['int', 'version_no'],
            ['bool', 'locked', 0], ['text', 'snapshot'], ['fk', 'locked_by', 'users', 'SET NULL', true],
            ['ts', 'created_at'],
        ],

        // ================= v5 — bidders/bids/evaluation/award =================
        'tender_bidders' => [
            ['id'], ['uid'], ['fk', 'panchayat_id', 'panchayats', 'RESTRICT', true], ['fk', 'tender_id', 'tenders', 'CASCADE'], ['fk', 'contractor_id', 'contractors', 'RESTRICT'],
            ['str', 'bidder_label', 200, true], ['tsx', 'submission_time'],
            ['enum', 'bid_status', ['submitted', 'technical_opened', 'technically_qualified', 'technically_disqualified', 'clarification_required', 'financial_opened', 'awarded', 'rejected', 'withdrawn'], 'submitted'],
            ['big', 'emd_paid_minor'], ['text', 'emd_details', true], ['json', 'technical_info', true], ['json', 'financial_info', true],
            ['text', 'rejection_reason', true], ['int', 'rank'], ['bool', 'is_confidential', 1],
            ['fk', 'created_by', 'users', 'SET NULL', true], ['ts', 'created_at'], ['ts', 'updated_at'],
        ],
        'bid_documents' => [
            ['id'], ['uid'], ['fk', 'panchayat_id', 'panchayats', 'RESTRICT', true], ['fk', 'bidder_id', 'tender_bidders', 'CASCADE'],
            ['str', 'doc_type', 40], ['int', 'document_id'], ['bool', 'is_confidential', 1], ['ts', 'created_at'],
        ],
        'technical_openings' => [
            ['id'], ['uid'], ['fk', 'panchayat_id', 'panchayats', 'RESTRICT', true], ['fk', 'tender_id', 'tenders', 'CASCADE'], ['date', 'opening_date'],
            ['fk', 'opened_by', 'users', 'SET NULL', true], ['json', 'attendees', true], ['text', 'observations', true],
            ['int', 'minutes_document_id'], ['str', 'status', 20, false], ['ts', 'created_at'],
        ],
        'evaluation_criteria' => [
            ['id'], ['uid'], ['fk', 'panchayat_id', 'panchayats', 'RESTRICT', true], ['fk', 'tender_id', 'tenders', 'CASCADE'],
            ['str', 'code', 64], ['str', 'criterion', 300], ['text', 'requirement', true],
            ['bool', 'is_required', 1], ['int', 'sort_order', 0], ['ts', 'created_at'],
        ],
        'technical_evaluations' => [
            ['id'], ['uid'], ['fk', 'panchayat_id', 'panchayats', 'RESTRICT', true], ['fk', 'tender_id', 'tenders', 'CASCADE'], ['fk', 'bidder_id', 'tender_bidders', 'CASCADE'],
            ['fk', 'criterion_id', 'evaluation_criteria', 'SET NULL', true],
            ['enum', 'result', ['pass', 'fail', 'clarification_required', 'not_applicable', 'verification_required'], 'pass'],
            ['text', 'bidder_response', true], ['text', 'remarks', true], ['int', 'evidence_document_id'],
            ['fk', 'evaluated_by', 'users', 'SET NULL', true], ['ts', 'evaluated_at'],
        ],
        'financial_bids' => [
            ['id'], ['uid'], ['fk', 'panchayat_id', 'panchayats', 'RESTRICT', true], ['fk', 'tender_id', 'tenders', 'CASCADE'], ['fk', 'bidder_id', 'tender_bidders', 'CASCADE'],
            ['big', 'total_amount_minor', 0], ['big', 'base_amount_minor', 0], ['big', 'tax_amount_minor', 0],
            ['big', 'discount_minor', 0], ['tsx', 'quoted_on', true], ['bool', 'is_confidential', 1],
            ['fk', 'created_by', 'users', 'SET NULL', true], ['ts', 'created_at'],
        ],
        'financial_bid_items' => [
            ['id'], ['uid'], ['fk', 'panchayat_id', 'panchayats', 'RESTRICT', true], ['fk', 'financial_bid_id', 'financial_bids', 'CASCADE'],
            ['fk', 'boq_item_id', 'boq_items', 'SET NULL', true], ['str', 'item_no', 40, true],
            ['big', 'bidder_rate_minor', 0], ['real', 'quantity', 0], ['big', 'amount_minor', 0], ['ts', 'created_at'],
        ],
        'awards' => [
            ['id'], ['uid'], ['fk', 'panchayat_id', 'panchayats', 'RESTRICT', true], ['fk', 'tender_id', 'tenders', 'RESTRICT'], ['fk', 'contractor_id', 'contractors', 'RESTRICT'],
            ['fk', 'bidder_id', 'tender_bidders', 'SET NULL', true], ['big', 'awarded_amount_minor', 0], ['int', 'rank'],
            ['str', 'approval_authority', 120, true], ['date', 'approval_date', true],
            ['str', 'loa_number', 80, true, false], ['date', 'loa_date', true], ['big', 'security_deposit_minor'],
            ['int', 'agreement_id'], ['int', 'work_order_id'],
            ['enum', 'status', ['recommended', 'approved', 'loa_issued', 'agreement_done', 'work_order_issued', 'completed', 'cancelled'], 'recommended'],
            ['text', 'remarks', true], ['fk', 'created_by', 'users', 'SET NULL', true], ['ts', 'created_at'], ['ts', 'updated_at'],
        ],
        'agreements' => [
            ['id'], ['uid'], ['fk', 'panchayat_id', 'panchayats', 'RESTRICT', true], ['fk', 'tender_id', 'tenders', 'RESTRICT'], ['fk', 'award_id', 'awards', 'SET NULL', true],
            ['str', 'agreement_number', 80, true, false], ['fk', 'contractor_id', 'contractors', 'RESTRICT'],
            ['big', 'amount_minor', 0], ['int', 'completion_period_days'], ['text', 'conditions', true],
            ['big', 'security_deposit_minor'], ['date', 'execution_date', true], ['int', 'document_id'],
            ['fk', 'created_by', 'users', 'SET NULL', true], ['ts', 'created_at'], ['ts', 'updated_at'],
        ],
        'work_orders' => [
            ['id'], ['uid'], ['fk', 'panchayat_id', 'panchayats', 'RESTRICT', true], ['fk', 'tender_id', 'tenders', 'RESTRICT'], ['fk', 'award_id', 'awards', 'SET NULL', true],
            ['fk', 'agreement_id', 'agreements', 'SET NULL', true], ['fk', 'project_id', 'projects', 'SET NULL', true],
            ['fk', 'contractor_id', 'contractors', 'RESTRICT'], ['str', 'work_order_number', 80, true, false],
            ['big', 'amount_minor', 0], ['date', 'start_date', true], ['date', 'completion_date', true],
            ['text', 'conditions', true], ['int', 'document_id'], ['fk', 'created_by', 'users', 'SET NULL', true],
            ['ts', 'created_at'], ['ts', 'updated_at'],
        ],

        // ================= v6 — execution/measurements/bills/payments/completion =================
        'work_progress' => [
            ['id'], ['uid'], ['fk', 'panchayat_id', 'panchayats', 'RESTRICT', true], ['fk', 'project_id', 'projects', 'CASCADE'], ['fk', 'tender_id', 'tenders', 'SET NULL', true],
            ['date', 'progress_date'], ['real', 'physical_progress', 0], ['real', 'financial_progress', 0],
            ['str', 'milestone', 200, true], ['text', 'notes', true], ['int', 'site_photo_document_id'],
            ['fk', 'created_by', 'users', 'SET NULL', true], ['ts', 'created_at'],
        ],
        'site_instructions' => [
            ['id'], ['uid'], ['fk', 'panchayat_id', 'panchayats', 'RESTRICT', true], ['fk', 'project_id', 'projects', 'CASCADE'], ['date', 'instruction_date'],
            ['text', 'instruction'], ['fk', 'issued_by', 'users', 'SET NULL', true], ['int', 'document_id'],
            ['ts', 'created_at'],
        ],
        'extension_requests' => [
            ['id'], ['uid'], ['fk', 'panchayat_id', 'panchayats', 'RESTRICT', true], ['fk', 'project_id', 'projects', 'CASCADE'], ['int', 'requested_days', 0],
            ['text', 'reason'], ['enum', 'status', ['pending', 'approved', 'rejected'], 'pending'],
            ['fk', 'decided_by', 'users', 'SET NULL', true], ['tsx', 'decided_at', true], ['text', 'remarks'], ['ts', 'created_at'],
        ],
        'measurements' => [
            ['id'], ['uid'], ['fk', 'panchayat_id', 'panchayats', 'RESTRICT', true], ['fk', 'project_id', 'projects', 'CASCADE'], ['fk', 'tender_id', 'tenders', 'SET NULL', true],
            ['str', 'measurement_number', 60], ['date', 'measurement_date'], ['str', 'location', 200, true],
            ['text', 'remarks', true], ['fk', 'measured_by', 'users', 'SET NULL', true],
            ['fk', 'checked_by', 'users', 'SET NULL', true], ['fk', 'approved_by', 'users', 'SET NULL', true],
            ['enum', 'status', ['draft', 'checked', 'approved', 'final'], 'draft'], ['ts', 'created_at'], ['ts', 'updated_at'],
        ],
        'measurement_items' => [
            ['id'], ['uid'], ['fk', 'panchayat_id', 'panchayats', 'RESTRICT', true], ['fk', 'measurement_id', 'measurements', 'CASCADE'], ['fk', 'boq_item_id', 'boq_items', 'SET NULL', true],
            ['str', 'item_no', 40, true], ['str', 'description', 500], ['str', 'unit', 40, true],
            ['real', 'previous_quantity', 0], ['real', 'current_quantity', 0], ['real', 'cumulative_quantity', 0],
            ['big', 'rate_minor', 0], ['big', 'amount_minor', 0], ['bool', 'overrun_flag', 0], ['text', 'remarks', true],
            ['ts', 'created_at'],
        ],
        'bills' => [
            ['id'], ['uid'], ['fk', 'panchayat_id', 'panchayats', 'RESTRICT', true], ['fk', 'fy_id', 'financial_years', 'RESTRICT'], ['fk', 'project_id', 'projects', 'CASCADE'],
            ['fk', 'tender_id', 'tenders', 'SET NULL', true], ['fk', 'work_order_id', 'work_orders', 'SET NULL', true],
            ['fk', 'contractor_id', 'contractors', 'SET NULL', true], ['str', 'bill_number', 60, false, false],
            ['enum', 'bill_type', ['running', 'final'], 'running'], ['date', 'bill_date'],
            ['big', 'gross_work_value_minor', 0], ['big', 'previous_certified_minor', 0], ['big', 'current_bill_minor', 0],
            ['big', 'cumulative_minor', 0], ['big', 'retention_minor', 0], ['big', 'tax_minor', 0],
            ['big', 'deductions_minor', 0], ['big', 'recoveries_minor', 0], ['big', 'net_payable_minor', 0],
            ['enum', 'status', ['draft', 'submitted', 'checked', 'certified', 'approved', 'paid', 'partially_paid', 'rejected', 'returned'], 'draft'],
            ['fk', 'certified_by', 'users', 'SET NULL', true], ['fk', 'approved_by', 'users', 'SET NULL', true],
            ['text', 'remarks', true], ['fk', 'created_by', 'users', 'SET NULL', true], ['ts', 'created_at'], ['ts', 'updated_at'],
        ],
        'bill_items' => [
            ['id'], ['uid'], ['fk', 'panchayat_id', 'panchayats', 'RESTRICT', true], ['fk', 'bill_id', 'bills', 'CASCADE'], ['fk', 'measurement_item_id', 'measurement_items', 'SET NULL', true],
            ['str', 'item_no', 40, true], ['str', 'description', 500], ['real', 'quantity', 0], ['str', 'unit', 40, true],
            ['big', 'rate_minor', 0], ['big', 'amount_minor', 0], ['ts', 'created_at'],
        ],
        'payments' => [
            ['id'], ['uid'], ['fk', 'panchayat_id', 'panchayats', 'RESTRICT', true], ['fk', 'fy_id', 'financial_years', 'RESTRICT'], ['fk', 'bill_id', 'bills', 'SET NULL', true],
            ['fk', 'project_id', 'projects', 'SET NULL', true], ['fk', 'contractor_id', 'contractors', 'SET NULL', true],
            ['str', 'voucher_no', 60, true, false], ['date', 'payment_date'],
            ['big', 'gross_amount_minor', 0], ['big', 'deductions_minor', 0], ['big', 'net_amount_minor', 0],
            ['str', 'payment_method', 40, true], ['str', 'transaction_reference', 120, true, false],
            ['enum', 'status', ['recorded', 'approved', 'cancelled'], 'recorded'],
            ['bool', 'is_bank_integrated', 0], ['str', 'approval_authority', 120, true], ['text', 'remarks', true],
            ['fk', 'created_by', 'users', 'SET NULL', true], ['ts', 'created_at'], ['ts', 'updated_at'],
        ],
        'completions' => [
            ['id'], ['uid'], ['fk', 'panchayat_id', 'panchayats', 'RESTRICT', true], ['fk', 'project_id', 'projects', 'CASCADE'], ['fk', 'tender_id', 'tenders', 'SET NULL', true],
            ['date', 'completion_date', true], ['date', 'inspection_date', true],
            ['fk', 'final_measurement_id', 'measurements', 'SET NULL', true], ['fk', 'final_bill_id', 'bills', 'SET NULL', true],
            ['fk', 'final_payment_id', 'payments', 'SET NULL', true], ['date', 'security_release_date', true],
            ['date', 'handover_date', true],
            ['enum', 'status', ['in_progress', 'inspection_done', 'final_measurement_done', 'final_bill_done', 'final_payment_done', 'security_released', 'closed'], 'in_progress'],
            ['int', 'certificate_document_id'], ['int', 'closure_report_document_id'], ['text', 'remarks', true],
            ['ts', 'created_at'], ['ts', 'updated_at'],
        ],
        'contractor_performance' => [
            ['id'], ['uid'], ['fk', 'panchayat_id', 'panchayats', 'RESTRICT', true], ['fk', 'contractor_id', 'contractors', 'CASCADE'], ['fk', 'project_id', 'projects', 'SET NULL', true],
            ['fk', 'tender_id', 'tenders', 'SET NULL', true], ['real', 'quality_rating'], ['real', 'timeliness_rating'],
            ['real', 'completion_rating'], ['text', 'defect_notes'], ['text', 'delay_notes'], ['text', 'extension_notes'],
            ['text', 'overall_remarks'], ['fk', 'rated_by', 'users', 'SET NULL', true], ['ts', 'rated_at'],
        ],

        // ================= v7 — corrigenda/cancellations/retenders/workflow/audit/etc =================
        'corrigenda' => [
            ['id'], ['uid'], ['fk', 'panchayat_id', 'panchayats', 'RESTRICT', true], ['fk', 'tender_id', 'tenders', 'CASCADE'], ['str', 'corrigendum_number', 60],
            ['text', 'reason'], ['text', 'changes'], ['json', 'new_dates'],
            ['enum', 'status', ['draft', 'approved', 'published'], 'draft'], ['int', 'document_id'],
            ['fk', 'created_by', 'users', 'SET NULL', true], ['ts', 'created_at'], ['ts', 'updated_at'],
        ],
        'tender_cancellations' => [
            ['id'], ['uid'], ['fk', 'panchayat_id', 'panchayats', 'RESTRICT', true], ['fk', 'tender_id', 'tenders', 'CASCADE'], ['text', 'reason'],
            ['str', 'authority', 120, true], ['date', 'cancel_date', true], ['str', 'approval_authority', 120, true],
            ['int', 'notice_document_id'], ['fk', 'created_by', 'users', 'SET NULL', true], ['ts', 'created_at'],
        ],
        'retenders' => [
            ['id'], ['uid'], ['fk', 'panchayat_id', 'panchayats', 'RESTRICT', true], ['fk', 'original_tender_id', 'tenders', 'RESTRICT'], ['fk', 'new_tender_id', 'tenders', 'SET NULL', true],
            ['text', 'reason', true], ['json', 'carry_forward'], ['fk', 'created_by', 'users', 'SET NULL', true], ['ts', 'created_at'],
        ],
        'approval_steps' => [
            ['id'], ['uid'], ['str', 'entity_type', 40], ['int', 'entity_id'], ['int', 'step_order'],
            ['str', 'step_name', 160], ['str', 'required_role', 60, true],
            ['enum', 'status', ['pending', 'in_progress', 'approved', 'rejected', 'returned', 'clarification', 'skipped', 'cancelled'], 'pending'],
            ['fk', 'actor_id', 'users', 'SET NULL', true], ['str', 'action', 40, true], ['text', 'remarks', true],
            ['tsx', 'acted_at', true], ['int', 'version_no', 1], ['ts', 'created_at'],
        ],
        'notifications' => [
            ['id'], ['uid'], ['fk', 'user_id', 'users', 'CASCADE'], ['str', 'type', 60], ['str', 'title', 200],
            ['text', 'body'], ['str', 'entity_type', 40, true], ['int', 'entity_id'], ['str', 'link', 300, true],
            ['enum', 'severity', ['info', 'warning', 'critical'], 'info'], ['bool', 'is_read', 0],
            ['str', 'channel', 20, false], ['ts', 'created_at'],
        ],
        'audit_logs' => [
            ['id'], ['uid'], ['fk', 'actor_id', 'users', 'SET NULL', true], ['str', 'actor_name', 160, true],
            ['str', 'actor_role', 60, true], ['int', 'panchayat_id'], ['str', 'action', 100],
            ['str', 'entity_type', 40], ['int', 'entity_id'], ['str', 'entity_label', 255, true],
            ['text', 'old_value', true], ['text', 'new_value', true], ['text', 'reason', true],
            ['str', 'ip_address', 64, true], ['text', 'user_agent', true], ['str', 'request_id', 64, true], ['str', 'prev_hash', 64, true], ['str', 'entry_hash', 64, true],
            ['ts', 'created_at'],
        ],
        'numbering_sequences' => [
            ['id'], ['str', 'scope', 60], ['fk', 'fy_id', 'financial_years', 'RESTRICT', true],
            ['fk', 'panchayat_id', 'panchayats', 'RESTRICT', true], ['str', 'prefix', 60, false],
            ['int', 'last_value', 0], ['ts', 'updated_at'],
        ],
        'settings' => [
            ['id'], ['str', 'key', 100, false, true], ['text', 'value'], ['text', 'description', true], ['ts', 'updated_at'],
        ],
        'templates' => [
            ['id'], ['uid'], ['str', 'code', 60], ['str', 'name', 200], ['str', 'doc_type', 60],
            ['int', 'version', 1], ['text', 'content_html'], ['json', 'merge_fields'],
            ['bool', 'is_active', 1], ['bool', 'is_default', 0], ['fk', 'created_by', 'users', 'SET NULL', true],
            ['ts', 'created_at'], ['ts', 'updated_at'],
        ],
        'document_generations' => [
            ['id'], ['uid'], ['fk', 'panchayat_id', 'panchayats', 'RESTRICT', true], ['str', 'doc_type', 60], ['str', 'entity_type', 40, true], ['int', 'entity_id'],
            ['int', 'template_id'], ['int', 'template_version'], ['int', 'document_id'],
            ['str', 'verification_code', 32, true], ['fk', 'generated_by', 'users', 'SET NULL', true], ['ts', 'generated_at'],
        ],
        'external_refs' => [
            ['id'], ['uid'], ['fk', 'panchayat_id', 'panchayats', 'RESTRICT', true], ['str', 'entity_type', 40], ['int', 'entity_id'], ['str', 'official_portal', 200, true],
            ['str', 'external_tender_id', 120, true], ['str', 'external_reference_no', 120, true],
            ['str', 'publication_status', 60, true], ['str', 'official_url', 500, true],
            ['tsx', 'publication_timestamp', true], ['str', 'external_status', 60, true], ['tsx', 'last_synced_at', true],
            ['str', 'sync_method', 20, false], ['ts', 'created_at'], ['ts', 'updated_at'],
        ],
        'imports' => [
            ['id'], ['uid'], ['fk', 'panchayat_id', 'panchayats', 'RESTRICT', true], ['str', 'import_type', 60], ['str', 'file_name', 255, true],
            ['enum', 'status', ['validating', 'preview', 'committed', 'failed'], 'validating'],
            ['int', 'total_rows', 0], ['int', 'valid_rows', 0], ['text', 'error_report'],
            ['fk', 'created_by', 'users', 'SET NULL', true], ['ts', 'created_at'],
        ],
        'backups' => [
            ['id'], ['uid'], ['fk', 'panchayat_id', 'panchayats', 'RESTRICT', true], ['str', 'backup_type', 20, false], ['str', 'file_name', 255, true],
            ['int', 'size_bytes'], ['str', 'status', 20, false], ['bool', 'verified', 0],
            ['fk', 'created_by', 'users', 'SET NULL', true], ['ts', 'created_at'],
        ],
    ];
}

/** Quote an identifier for the active driver (MySQL/MariaDB backticks). */
function schema_quote(string $id): string
{
    return DB::isSqlite() ? $id : '`' . $id . '`';
}

/**
 * Render a single column definition from the DSL, or null for the table-level
 * composite-primary-key marker (whose columns come from their own 'fk' rows).
 *
 * When $alter is true the definition is relaxed (NOT NULL without DEFAULT →
 * NULL, UNIQUE stripped) so it can be added to an existing table safely.
 */
function column_ddl(array $c, bool $alter = false): ?string
{
    $sqlite = DB::isSqlite();
    $name = isset($c[1]) ? schema_quote((string) $c[1]) : '';
    $line = null;
    switch ($c[0]) {
        case 'id':
            $line = $sqlite ? 'id INTEGER PRIMARY KEY AUTOINCREMENT' : 'id INT AUTO_INCREMENT PRIMARY KEY';
            break;
        case 'uid':
            $line = $sqlite ? 'uid TEXT NOT NULL UNIQUE' : 'uid VARCHAR(36) NOT NULL UNIQUE';
            break;
        case 'pk':
            return null;
        case 'str':
            $null = ($c[3] ?? false) ? 'NULL' : 'NOT NULL';
            $uniq = !empty($c[4]) ? ' UNIQUE' : '';
            $line = "$name VARCHAR({$c[2]}) $null$uniq";
            break;
        case 'text':
            $null = ($c[2] ?? false) ? 'NULL' : 'NOT NULL';
            $line = "$name TEXT $null";
            break;
        case 'int':
            $def = $c[2] ?? null;
            $line = "$name INTEGER " . ($def === null ? 'NULL' : "NOT NULL DEFAULT $def");
            break;
        case 'big':
            $def = $c[2] ?? null;
            $type = $sqlite ? 'INTEGER' : 'BIGINT';
            $line = "$name $type " . ($def === null ? 'NULL' : "NOT NULL DEFAULT $def");
            break;
        case 'real':
            $def = $c[2] ?? null;
            $type = $sqlite ? 'REAL' : 'DOUBLE';
            $line = "$name $type " . ($def === null ? 'NULL' : "NOT NULL DEFAULT $def");
            break;
        case 'date':
            $null = ($c[2] ?? false) ? 'NULL' : 'NOT NULL';
            $type = $sqlite ? 'TEXT' : 'DATE';
            $line = "$name $type $null";
            break;
        case 'ts':
            $line = $sqlite
                ? "$name TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP"
                : "$name DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP";
            break;
        case 'tsx':
            $null = ($c[2] ?? false) ? 'NULL' : 'NOT NULL';
            $type = $sqlite ? 'TEXT' : 'DATETIME';
            $line = "$name $type $null";
            break;
        case 'bool':
            $def = $c[2] ?? 0;
            $line = "$name INTEGER NOT NULL DEFAULT $def";
            break;
        case 'fk':
            $null = ($c[4] ?? false) ? 'NULL' : 'NOT NULL';
            $line = "$name INTEGER $null";
            break;
        case 'json':
            $null = ($c[2] ?? false) ? 'NULL' : 'NOT NULL';
            $line = "$name TEXT $null";
            break;
        case 'enum':
            $def = $c[3] ?? null;
            $type = $sqlite ? 'TEXT' : 'VARCHAR(64)';
            $line = "$name $type " . ($def === null ? 'NULL' : "NOT NULL DEFAULT '$def'");
            break;
        default:
            return null;
    }
    if ($alter) {
        // ALTER TABLE ADD COLUMN cannot apply most constraints; relax them.
        if (strpos($line, 'PRIMARY KEY') !== false || strpos($line, 'AUTO_INCREMENT') !== false) {
            return null; // key columns cannot be appended to an existing table
        }
        if (strpos($line, 'NOT NULL') !== false && strpos($line, 'DEFAULT') === false) {
            $line = str_replace('NOT NULL', 'NULL', $line);
        }
        $line = str_replace(' UNIQUE', '', $line);
    }
    return $line;
}

/** Render the CREATE TABLE statement for a single table. */
function table_ddl(string $table): string
{
    $cols = schema_tables()[$table];
    $lines = [];
    $checks = [];
    $pks = [];
    foreach ($cols as $c) {
        if ($c[0] === 'pk') {
            $pks = array_map('schema_quote', array_slice($c, 1));
            continue;
        }
        $line = column_ddl($c);
        if ($line !== null) {
            $lines[] = $line;
        }
        if ($c[0] === 'enum') {
            $vals = array_map(function ($v) { return "'" . addslashes($v) . "'"; }, $c[2]);
            // Table-level CHECK constraints must follow all column definitions
            // in SQLite, so collect them and append below.
            $checks[] = 'CHECK (' . schema_quote((string) $c[1]) . ' IN (' . implode(',', $vals) . '))';
        }
    }
    foreach ($cols as $c) {
        if ($c[0] === 'fk') {
            $lines[] = 'FOREIGN KEY (' . schema_quote((string) $c[1]) . ') REFERENCES ' . schema_quote((string) $c[2]) . '(id) ON DELETE ' . $c[3];
        }
    }
    foreach ($checks as $check) {
        $lines[] = $check;
    }
    if ($pks) {
        $lines[] = 'PRIMARY KEY (' . implode(', ', $pks) . ')';
    }
    $ddl = 'CREATE TABLE ' . schema_quote($table) . " (\n  " . implode(",\n  ", $lines) . "\n)";
    if (!DB::isSqlite()) {
        $ddl .= ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    }
    return $ddl;
}

/** Convert the DSL into CREATE TABLE statements for the active driver. */
function schema_ddl(): array
{
    $out = [];
    foreach (array_keys(schema_tables()) as $table) {
        $out[] = table_ddl($table);
    }
    return $out;
}

/** Secondary indexes for search, reports and tenant-scoped lists. */
function schema_indexes(): array
{
    return [
        ['idx_tenders_scope_fy_status', 'tenders', ['panchayat_id', 'fy_id', 'status']],
        ['idx_rule_evaluations_scope', 'rule_evaluations', ['panchayat_id', 'entity_type', 'entity_id']],
        ['idx_tenders_numbers', 'tenders', ['tender_number', 'nit_number']],
        ['idx_projects_scope_fy_status', 'projects', ['panchayat_id', 'fy_id', 'status']],
        ['idx_contractors_scope_status', 'contractors', ['panchayat_id', 'status']],
        ['idx_documents_entity', 'documents', ['entity_type', 'entity_id', 'category']],
        ['idx_document_generations_scope', 'document_generations', ['panchayat_id', 'doc_type', 'generated_at']],
        ['idx_bidders_tender_status', 'tender_bidders', ['tender_id', 'bid_status']],
        ['idx_financial_bids_tender', 'financial_bids', ['tender_id', 'bidder_id']],
        ['idx_awards_tender_status', 'awards', ['tender_id', 'status']],
        ['idx_bills_scope_fy_status', 'bills', ['panchayat_id', 'fy_id', 'status']],
        ['idx_payments_scope_fy_status', 'payments', ['panchayat_id', 'fy_id', 'status']],
        ['idx_audit_entity', 'audit_logs', ['entity_type', 'entity_id']],
        ['idx_audit_scope_created', 'audit_logs', ['panchayat_id', 'created_at']],
    ];
}

/** Composite unique indexes for scoped reference/document numbers. */
function schema_unique_indexes(): array
{
    return [
        ['ux_projects_scope_code', 'projects', ['panchayat_id', 'fy_id', 'project_code']],
        ['ux_contractors_scope_code', 'contractors', ['panchayat_id', 'contractor_code']],
        ['ux_tenders_scope_number', 'tenders', ['panchayat_id', 'fy_id', 'tender_number']],
        ['ux_tenders_scope_nit', 'tenders', ['panchayat_id', 'fy_id', 'nit_number']],
        ['ux_awards_scope_loa', 'awards', ['panchayat_id', 'loa_number']],
        ['ux_agreements_scope_number', 'agreements', ['panchayat_id', 'agreement_number']],
        ['ux_work_orders_scope_number', 'work_orders', ['panchayat_id', 'work_order_number']],
        ['ux_bills_scope_number', 'bills', ['panchayat_id', 'fy_id', 'bill_number']],
        ['ux_payments_scope_voucher', 'payments', ['panchayat_id', 'fy_id', 'voucher_no']],
        ['ux_payments_scope_txref', 'payments', ['panchayat_id', 'transaction_reference']],
        ['ux_numbering_sequences_scope', 'numbering_sequences', ['scope', 'fy_id', 'panchayat_id', 'prefix']],
    ];
}

/** Create indexes idempotently; duplicate-index errors are ignored. */
function schema_create_indexes(): void
{
    $pdo = DB::pdo();
    $sets = [
        [false, schema_indexes()],
        [true, schema_unique_indexes()],
    ];
    foreach ($sets as [$unique, $indexes]) {
        foreach ($indexes as [$name, $table, $cols]) {
            $quotedCols = implode(', ', array_map('schema_quote', $cols));
            $sql = 'CREATE ' . ($unique ? 'UNIQUE ' : '') . 'INDEX ' . schema_quote($name) . ' ON ' . schema_quote($table) . ' (' . $quotedCols . ')';
            try {
                $pdo->exec($sql);
            } catch (Throwable $e) {
                $msg = strtolower($e->getMessage());
                // Existing upgraded installations may contain duplicates or old
                // global UNIQUE constraints. Report via health docs; do not stop
                // non-destructive repair from completing.
                if (strpos($msg, 'duplicate') === false && strpos($msg, 'already exists') === false && strpos($msg, '1061') === false && strpos($msg, '23000') === false) {
                    throw $e;
                }
            }
        }
    }
}

/** Backfill tenant columns for upgrades from a single-Panchayat installation. */
function schema_backfill_panchayat_ids(): void
{
    $pid = DB::val('SELECT id FROM panchayats ORDER BY id LIMIT 1');
    if ($pid === null) {
        return;
    }
    foreach (schema_tables() as $table => $cols) {
        $has = false;
        foreach ($cols as $c) {
            if (($c[1] ?? null) === 'panchayat_id') { $has = true; break; }
        }
        if ($has) {
            try {
                DB::run('UPDATE ' . schema_quote($table) . ' SET panchayat_id = ? WHERE panchayat_id IS NULL', [(int) $pid]);
            } catch (Throwable $ignore) {
                // ignore during early bootstrap when a table is not created yet
            }
        }
    }
}


/** Drop old single-column UNIQUE indexes that were replaced by Panchayat/FY-scoped indexes. */
function schema_drop_legacy_unique_indexes(): array
{
    if (DB::isSqlite()) {
        return [];
    }
    $legacy = [
        ['projects', 'project_code'],
        ['procurement_plans', 'plan_number'],
        ['contractors', 'contractor_code'],
        ['tenders', 'tender_number'],
        ['tenders', 'nit_number'],
        ['awards', 'loa_number'],
        ['agreements', 'agreement_number'],
        ['work_orders', 'work_order_number'],
        ['bills', 'bill_number'],
        ['payments', 'voucher_no'],
        ['payments', 'transaction_reference'],
    ];
    $dropped = [];
    foreach ($legacy as [$table, $column]) {
        try {
            $indexes = DB::all(
                'SELECT INDEX_NAME
                   FROM INFORMATION_SCHEMA.STATISTICS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = ?
                    AND NON_UNIQUE = 0
                  GROUP BY INDEX_NAME
                 HAVING COUNT(*) = 1 AND MAX(COLUMN_NAME) = ? AND INDEX_NAME <> ?',
                [$table, $column, 'PRIMARY']
            );
            foreach ($indexes as $idx) {
                $name = $idx['INDEX_NAME'] ?? null;
                if (!$name) { continue; }
                DB::pdo()->exec('ALTER TABLE ' . schema_quote($table) . ' DROP INDEX ' . schema_quote((string) $name));
                $dropped[] = $table . '.' . $name;
            }
        } catch (Throwable $ignore) {
            // Keep repair non-destructive; scoped indexes may still be created on clean installs.
        }
    }
    return $dropped;
}

/** Disable foreign-key enforcement; returns the statement that re-enables it. */
function schema_fk_off(): string
{
    DB::pdo()->exec(DB::isSqlite() ? 'PRAGMA foreign_keys = OFF' : 'SET FOREIGN_KEY_CHECKS = 0');
    return DB::isSqlite() ? 'PRAGMA foreign_keys = ON' : 'SET FOREIGN_KEY_CHECKS = 1';
}

/** Names of the tables that currently exist in the database. */
function db_tables(): array
{
    if (DB::isSqlite()) {
        return array_map(fn($r) => $r['name'], DB::all("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"));
    }
    return array_map(fn($r) => $r['TABLE_NAME'], DB::all('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()'));
}

/** Existing column names for one table (empty when the table is absent). */
function db_columns(string $table): array
{
    if (DB::isSqlite()) {
        return array_map(fn($r) => $r['name'], DB::all('PRAGMA table_info(' . schema_quote($table) . ')'));
    }
    return array_map(fn($r) => $r['COLUMN_NAME'], DB::all(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
        [$table]
    ));
}

/** Stable fingerprint of the schema definition (drift detection). */
function schema_version(): string
{
    return substr(md5(serialize([schema_tables(), schema_indexes(), schema_unique_indexes()])), 0, 12);
}

/** Compare the live database against the schema DSL. */
function schema_health(): array
{
    $missingTables = [];
    $missingColumns = [];
    $existing = array_fill_keys(db_tables(), true);
    foreach (schema_tables() as $table => $cols) {
        if (!isset($existing[$table])) {
            $missingTables[] = $table;
            continue;
        }
        $actual = array_fill_keys(db_columns($table), true);
        foreach ($cols as $c) {
            $col = $c[1] ?? null;
            if ($col !== null && $c[0] !== 'pk' && !isset($actual[$col])) {
                $missingColumns[$table][] = $col;
            }
        }
    }
    return ['missing_tables' => $missingTables, 'missing_columns' => $missingColumns];
}

/**
 * Non-destructive schema repair: create missing tables (with foreign-key
 * checks off, so order never matters), add missing columns to existing tables,
 * then (re)seed idempotent reference data and stamp the schema version.
 * Returns a report of what was repaired.
 */
function schema_heal(?array $admin = null): array
{
    $report = ['created_tables' => [], 'added_columns' => [], 'dropped_legacy_unique_indexes' => [], 'seeded' => false];
    $health = schema_health();
    $pdo = DB::pdo();

    if ($health['missing_tables'] || $health['missing_columns']) {
        $restore = schema_fk_off();
        try {
            foreach ($health['missing_tables'] as $table) {
                $pdo->exec(table_ddl($table));
                $report['created_tables'][] = $table;
            }
            foreach ($health['missing_columns'] as $table => $cols) {
                foreach ($cols as $col) {
                    $def = null;
                    foreach (schema_tables()[$table] as $c) {
                        if (($c[1] ?? null) === $col) {
                            $def = column_ddl($c, true);
                            break;
                        }
                    }
                    if ($def !== null) {
                        $pdo->exec('ALTER TABLE ' . schema_quote($table) . ' ADD COLUMN ' . $def);
                        $report['added_columns'][$table][] = $col;
                    }
                }
            }
        } finally {
            $pdo->exec($restore);
        }
    }

    if ($report['created_tables'] || $report['added_columns'] || !schema_uptodate()) {
        seed_all(false, $admin);
        $report['seeded'] = true;
    }
    schema_backfill_panchayat_ids();
    $report['dropped_legacy_unique_indexes'] = schema_drop_legacy_unique_indexes();
    schema_create_indexes();

    schema_stamp();
    return $report;
}

/** Record the current schema version so later requests can fast-path. */
function schema_stamp(): void
{
    $k = schema_quote('key');
    $v = schema_quote('value');
    $existing = DB::one("SELECT id FROM settings WHERE $k = 'schema.version'");
    if ($existing) {
        DB::run("UPDATE settings SET $v = ? WHERE $k = 'schema.version'", [schema_version()]);
    } else {
        DB::insert("INSERT INTO settings ($k, $v) VALUES (?,?)", ['schema.version', schema_version()]);
    }
}

/** True if the schema is installed and matches the current definition. */
function schema_uptodate(): bool
{
    if (!schema_installed()) {
        return false;
    }
    try {
        $k = schema_quote('key');
        $v = schema_quote('value');
        $stored = DB::val("SELECT $v FROM settings WHERE $k = 'schema.version'");
    } catch (Throwable $e) {
        $stored = null;
    }
    return $stored === schema_version();
}

/** Drop and recreate all tables (foreign-key checks off throughout). */
function create_tables(bool $drop = false): void
{
    $pdo = DB::pdo();
    $restore = schema_fk_off();
    try {
        if ($drop) {
            foreach (array_keys(schema_tables()) as $t) {
                $pdo->exec('DROP TABLE IF EXISTS ' . schema_quote($t));
            }
        }
        foreach (schema_ddl() as $ddl) {
            $pdo->exec($ddl);
        }
        schema_create_indexes();
    } finally {
        $pdo->exec($restore);
    }
}

/** True if the schema is already installed. */
function schema_installed(): bool
{
    try {
        DB::one('SELECT 1 FROM users LIMIT 1');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}
