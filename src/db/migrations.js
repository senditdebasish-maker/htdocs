'use strict';

/**
 * Database schema (migrations).
 *
 * Conventions:
 *  - All tables use INTEGER PRIMARY KEY AUTOINCREMENT for internal joins,
 *    plus a public immutable `uid` (UUID v4) for API exposure.
 *  - Money is stored as INTEGER minor units (paise) in columns suffixed `_minor`.
 *    No floating point money anywhere.
 *  - Timestamps are UTC ISO-8601 TEXT. `created_at` / `updated_at` always present.
 *  - Soft delete via `deleted_at` only where a record must be retained for audit.
 *  - Financial year linkage via `fy_id` on every transactional entity.
 */

module.exports = [
  // =====================================================================
  // v1 — Identity, RBAC, Panchayat, Financial Years
  // =====================================================================
  {
    version: 1,
    name: 'core-identity-rbac-panchayat-fy',
    sql: [
      // ---- Roles ----
      `CREATE TABLE roles (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid TEXT NOT NULL UNIQUE,
        code TEXT NOT NULL UNIQUE,
        name TEXT NOT NULL,
        description TEXT,
        is_system INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      );`,

      // ---- Permissions ----
      `CREATE TABLE permissions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code TEXT NOT NULL UNIQUE,
        name TEXT NOT NULL,
        category TEXT NOT NULL
      );`,

      `CREATE TABLE role_permissions (
        role_id INTEGER NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
        permission_id INTEGER NOT NULL REFERENCES permissions(id) ON DELETE CASCADE,
        PRIMARY KEY (role_id, permission_id)
      );`,

      // ---- Users ----
      `CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid TEXT NOT NULL UNIQUE,
        panchayat_id INTEGER REFERENCES panchayats(id) ON DELETE RESTRICT,
        email TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        name TEXT NOT NULL,
        designation TEXT,
        phone TEXT,
        is_active INTEGER NOT NULL DEFAULT 1,
        is_global_admin INTEGER NOT NULL DEFAULT 0,
        totp_secret TEXT,
        totp_enabled INTEGER NOT NULL DEFAULT 0,
        last_login_at TEXT,
        failed_login_count INTEGER NOT NULL DEFAULT 0,
        locked_until TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        deleted_at TEXT
      );`,

      `CREATE TABLE user_roles (
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        role_id INTEGER NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
        PRIMARY KEY (user_id, role_id)
      );`,

      // ---- Panchayat ----
      `CREATE TABLE panchayats (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid TEXT NOT NULL UNIQUE,
        state TEXT NOT NULL DEFAULT 'West Bengal',
        district TEXT,
        block TEXT,
        gram_panchayat TEXT NOT NULL,
        gp_code TEXT UNIQUE,
        office_address TEXT,
        pin TEXT,
        phone TEXT,
        email TEXT,
        pradhan TEXT,
        upa_pradhan TEXT,
        panchayat_secretary TEXT,
        technical_officer TEXT,
        accounts_officer TEXT,
        letterhead_html TEXT,
        document_header_html TEXT,
        document_footer_html TEXT,
        signature_config TEXT,
        seal_config TEXT,
        is_active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      );`,

      `CREATE TABLE panchayat_members (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid TEXT NOT NULL UNIQUE,
        panchayat_id INTEGER NOT NULL REFERENCES panchayats(id) ON DELETE CASCADE,
        role_name TEXT NOT NULL,          -- e.g. Pradhan, Secretary, Technical Officer
        person_name TEXT NOT NULL,
        designation TEXT,
        phone TEXT,
        email TEXT,
        is_authority INTEGER NOT NULL DEFAULT 0,
        authority_level INTEGER,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      );`,

      // ---- Financial years ----
      `CREATE TABLE financial_years (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid TEXT NOT NULL UNIQUE,
        label TEXT NOT NULL UNIQUE,             -- "2026-27"
        start_year INTEGER NOT NULL,
        start_date TEXT NOT NULL,               -- "2026-04-01"
        end_date TEXT NOT NULL,                 -- "2027-03-31"
        status TEXT NOT NULL DEFAULT 'open' CHECK (status IN ('open','closed')),
        is_current INTEGER NOT NULL DEFAULT 0,
        created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (start_date, end_date)
      );`,
    ],
  },

  // =====================================================================
  // v2 — Rules & Compliance engine
  // =====================================================================
  {
    version: 2,
    name: 'rules-compliance',
    sql: [
      `CREATE TABLE rulesets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid TEXT NOT NULL UNIQUE,
        name TEXT NOT NULL,
        issuing_authority TEXT,
        jurisdiction TEXT,
        effective_date TEXT,
        expiry_date TEXT,
        reference_number TEXT,               -- G.O. / order number
        source_url TEXT,
        source_document_id INTEGER,          -- references documents(id)
        version INTEGER NOT NULL DEFAULT 1,
        procurement_type TEXT,
        tender_type TEXT,
        is_active INTEGER NOT NULL DEFAULT 1,
        notes TEXT,
        created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      );`,

      `CREATE TABLE rules (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid TEXT NOT NULL UNIQUE,
        ruleset_id INTEGER NOT NULL REFERENCES rulesets(id) ON DELETE CASCADE,
        code TEXT NOT NULL,
        title TEXT NOT NULL,
        description TEXT,
        category TEXT NOT NULL DEFAULT 'general',  -- approval | document | notice | emd | fee | eligibility | evaluation | award | security | financial
        severity TEXT NOT NULL DEFAULT 'info'
          CHECK (severity IN ('info','warning','blocking','verification_required')),
        rule_type TEXT NOT NULL DEFAULT 'manual'
          CHECK (rule_type IN ('manual','approval_required','document_required','notice_period','amount_threshold','date_sequence','field_required')),
        config TEXT,                          -- JSON: thresholds, required fields, comparison
        is_active INTEGER NOT NULL DEFAULT 1,
        reference_id INTEGER,                 -- optional link to rule_references
        sort_order INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (ruleset_id, code)
      );`,

      `CREATE TABLE rule_references (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid TEXT NOT NULL UNIQUE,
        title TEXT NOT NULL,
        authority TEXT,
        manual_name TEXT,                    -- e.g. WBFR, CPWD Works Manual
        reference_number TEXT,
        publication_date TEXT,
        effective_date TEXT,
        source_url TEXT,
        source_document_id INTEGER,
        version TEXT,
        notes TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      );`,

      // Snapshot of compliance evaluation run against an entity.
      `CREATE TABLE rule_evaluations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid TEXT NOT NULL UNIQUE,
        ruleset_id INTEGER REFERENCES rulesets(id) ON DELETE SET NULL,
        entity_type TEXT NOT NULL,           -- tender | bill | project | ...
        entity_id INTEGER NOT NULL,
        results TEXT NOT NULL,               -- JSON array of findings
        summary TEXT NOT NULL,               -- JSON {blocking, warning, info, verification_required, passed}
        evaluated_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
        evaluated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      );`,
      `CREATE INDEX idx_rule_evaluations_entity ON rule_evaluations(entity_type, entity_id);`,
    ],
  },

  // =====================================================================
  // v3 — Schemes, Funds, Projects, Procurement plans, Contractors
  // =====================================================================
  {
    version: 3,
    name: 'schemes-funds-projects-contractors',
    sql: [
      `CREATE TABLE schemes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid TEXT NOT NULL UNIQUE,
        name TEXT NOT NULL,
        code TEXT UNIQUE,
        description TEXT,
        sponsoring_authority TEXT,
        is_active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      );`,

      `CREATE TABLE funds (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid TEXT NOT NULL UNIQUE,
        name TEXT NOT NULL,
        code TEXT UNIQUE,
        funding_source TEXT,                 -- e.g. State Plan, 15th FC, CFC
        head_of_account TEXT,
        is_active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      );`,

      // Budget allocations per FY (scheme x fund)
      `CREATE TABLE budget_allocations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid TEXT NOT NULL UNIQUE,
        fy_id INTEGER NOT NULL REFERENCES financial_years(id) ON DELETE RESTRICT,
        scheme_id INTEGER REFERENCES schemes(id) ON DELETE RESTRICT,
        fund_id INTEGER REFERENCES funds(id) ON DELETE RESTRICT,
        sanctioned_amount_minor INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (fy_id, scheme_id, fund_id)
      );`,

      `CREATE TABLE projects (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid TEXT NOT NULL UNIQUE,
        fy_id INTEGER NOT NULL REFERENCES financial_years(id) ON DELETE RESTRICT,
        scheme_id INTEGER REFERENCES schemes(id) ON DELETE RESTRICT,
        fund_id INTEGER REFERENCES funds(id) ON DELETE RESTRICT,
        head_of_account TEXT,
        project_code TEXT UNIQUE,
        work_name TEXT NOT NULL,
        description TEXT,
        location TEXT,
        administrative_approval_no TEXT,
        administrative_approval_date TEXT,
        administrative_approval_authority TEXT,
        administrative_approval_amount_minor INTEGER,
        technical_sanction_no TEXT,
        technical_sanction_date TEXT,
        technical_sanction_authority TEXT,
        technical_sanction_amount_minor INTEGER,
        estimate_amount_minor INTEGER,
        sanctioned_amount_minor INTEGER,
        tender_value_minor INTEGER,
        awarded_amount_minor INTEGER,
        contractor_id INTEGER,
        work_order_id INTEGER,
        start_date TEXT,
        planned_completion_date TEXT,
        actual_completion_date TEXT,
        physical_progress REAL NOT NULL DEFAULT 0,
        financial_progress REAL NOT NULL DEFAULT 0,
        status TEXT NOT NULL DEFAULT 'planned'
          CHECK (status IN ('planned','approved','tendered','awarded','in_progress','completed','closed','cancelled')),
        created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        deleted_at TEXT
      );`,
      `CREATE INDEX idx_projects_fy ON projects(fy_id);`,
      `CREATE INDEX idx_projects_status ON projects(status);`,

      `CREATE TABLE procurement_plans (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid TEXT NOT NULL UNIQUE,
        fy_id INTEGER NOT NULL REFERENCES financial_years(id) ON DELETE RESTRICT,
        scheme_id INTEGER REFERENCES schemes(id) ON DELETE RESTRICT,
        fund_id INTEGER REFERENCES funds(id) ON DELETE RESTRICT,
        project_id INTEGER REFERENCES projects(id) ON DELETE SET NULL,
        work_name TEXT NOT NULL,
        estimated_amount_minor INTEGER NOT NULL DEFAULT 0,
        funding_source TEXT,
        procurement_method TEXT,
        expected_date TEXT,
        responsible_officer TEXT,
        budget_provision TEXT,
        status TEXT NOT NULL DEFAULT 'planned'
          CHECK (status IN ('planned','initiated','tender_created','cancelled')),
        remarks TEXT,
        created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      );`,
      `CREATE INDEX idx_procurement_plans_fy ON procurement_plans(fy_id);`,

      `CREATE TABLE contractors (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid TEXT NOT NULL UNIQUE,
        contractor_code TEXT UNIQUE,
        legal_name TEXT NOT NULL,
        business_name TEXT,
        address TEXT,
        mobile TEXT,
        email TEXT,
        registration_no TEXT,
        registration_class TEXT,
        registration_valid_from TEXT,
        registration_valid_to TEXT,
        pan TEXT,
        gst TEXT,
        bank_name TEXT,
        bank_account_no TEXT,
        bank_ifsc TEXT,
        experience_summary TEXT,
        performance_rating REAL,
        performance_remarks TEXT,
        is_debarred INTEGER NOT NULL DEFAULT 0,
        debarment_reason TEXT,
        debarment_from TEXT,
        debarment_to TEXT,
        status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','inactive','debarred')),
        created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        deleted_at TEXT
      );`,
      `CREATE INDEX idx_contractors_status ON contractors(status);`,

      `CREATE TABLE contractor_documents (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid TEXT NOT NULL UNIQUE,
        contractor_id INTEGER NOT NULL REFERENCES contractors(id) ON DELETE CASCADE,
        doc_type TEXT NOT NULL,              -- registration | gst | pan | labour | certificate | experience | other
        doc_name TEXT NOT NULL,
        document_id INTEGER,                 -- references documents(id)
        issued_date TEXT,
        expiry_date TEXT,
        expiry_status TEXT NOT NULL DEFAULT 'valid'
          CHECK (expiry_status IN ('valid','expiring_soon','expired','verification_required','not_applicable')),
        remarks TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      );`,
      `CREATE INDEX idx_contractor_documents_contractor ON contractor_documents(contractor_id);`,
    ],
  },

  // =====================================================================
  // v4 — Documents, Tenders, NIT, BOQ, tender documents, versions
  // =====================================================================
  {
    version: 4,
    name: 'documents-tenders-nit-boq',
    sql: [
      `CREATE TABLE documents (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid TEXT NOT NULL UNIQUE,
        entity_type TEXT NOT NULL,           -- tender | project | contractor | bid | bill | payment | measurement | completion | ruleset | template | general
        entity_id INTEGER,
        category TEXT NOT NULL,              -- file-room folder key
        original_name TEXT NOT NULL,
        stored_name TEXT NOT NULL,
        mime_type TEXT,
        size_bytes INTEGER NOT NULL,
        sha256 TEXT,
        uploaded_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
        version INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      );`,
      `CREATE INDEX idx_documents_entity ON documents(entity_type, entity_id);`,

      // Field-level document references (approvals, sanctions etc.)
      `CREATE TABLE tender_approval_docs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid TEXT NOT NULL UNIQUE,
        tender_id INTEGER NOT NULL REFERENCES tenders(id) ON DELETE CASCADE,
        doc_kind TEXT NOT NULL,              -- administrative_approval | technical_sanction | estimate | other
        doc_number TEXT,
        doc_date TEXT,
        amount_minor INTEGER,
        authority TEXT,
        document_id INTEGER,
        remarks TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      );`,

      `CREATE TABLE tenders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid TEXT NOT NULL UNIQUE,
        fy_id INTEGER NOT NULL REFERENCES financial_years(id) ON DELETE RESTRICT,
        project_id INTEGER REFERENCES projects(id) ON DELETE SET NULL,
        scheme_id INTEGER REFERENCES schemes(id) ON DELETE SET NULL,
        fund_id INTEGER REFERENCES funds(id) ON DELETE SET NULL,
        ruleset_id INTEGER REFERENCES rulesets(id) ON DELETE SET NULL,
        tender_number TEXT NOT NULL UNIQUE,
        nit_number TEXT,
        tender_type TEXT NOT NULL,           -- open | limited | e-tender | quotation | short_notice | retender | single
        procurement_category TEXT NOT NULL,  -- works | supply | services | consultancy | other
        procurement_method TEXT,
        title TEXT NOT NULL,
        description TEXT,
        location TEXT,
        work_name TEXT,
        admin_approval_no TEXT,
        admin_approval_date TEXT,
        admin_approval_authority TEXT,
        admin_approval_amount_minor INTEGER,
        tech_sanction_no TEXT,
        tech_sanction_date TEXT,
        tech_sanction_authority TEXT,
        tech_sanction_amount_minor INTEGER,
        estimated_cost_minor INTEGER NOT NULL DEFAULT 0,
        tender_value_minor INTEGER NOT NULL DEFAULT 0,
        emd_minor INTEGER,
        emd_exemption_notes TEXT,
        tender_fee_minor INTEGER,
        security_deposit_minor INTEGER,
        security_deposit_pct REAL,
        tax_config TEXT,
        budget_provision TEXT,
        head_of_account TEXT,
        completion_period_days INTEGER,
        technical_specification TEXT,
        eligibility_notes TEXT,
        general_conditions TEXT,
        special_conditions TEXT,
        payment_conditions TEXT,
        completion_conditions TEXT,
        extension_conditions TEXT,
        penalty_provisions TEXT,
        defect_liability TEXT,
        publication_date TEXT,
        bid_start_date TEXT,
        bid_close_date TEXT,
        technical_open_date TEXT,
        financial_open_date TEXT,
        bid_validity_days INTEGER,
        status TEXT NOT NULL DEFAULT 'draft'
          CHECK (status IN ('draft','under_approval','approved','nit_generated','published','bidding','bid_closed','technical_evaluation','financial_evaluation','awarded','cancelled','retendered','closed')),
        compliance_status TEXT NOT NULL DEFAULT 'not_evaluated',
        workflow_stage TEXT NOT NULL DEFAULT 'draft',
        award_recommendation_id INTEGER,
        created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        deleted_at TEXT
      );`,
      `CREATE INDEX idx_tenders_fy ON tenders(fy_id);`,
      `CREATE INDEX idx_tenders_status ON tenders(status);`,
      `CREATE INDEX idx_tenders_type ON tenders(tender_type);`,

      // Versioned content snapshots (tender fields, NIT content, BOQ versions).
      `CREATE TABLE tender_versions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid TEXT NOT NULL UNIQUE,
        tender_id INTEGER NOT NULL REFERENCES tenders(id) ON DELETE CASCADE,
        version_no INTEGER NOT NULL,
        change_type TEXT NOT NULL,           -- create | update | corrigendum | nit_generate | boq_lock | cancel | retender
        reason TEXT,
        snapshot TEXT NOT NULL,              -- JSON
        changed_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (tender_id, version_no)
      );`,

      `CREATE TABLE nit_versions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid TEXT NOT NULL UNIQUE,
        tender_id INTEGER NOT NULL REFERENCES tenders(id) ON DELETE CASCADE,
        version_no INTEGER NOT NULL,
        template_id INTEGER,
        content_json TEXT NOT NULL,
        generated_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
        document_id INTEGER,
        generated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (tender_id, version_no)
      );`,

      `CREATE TABLE boq_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid TEXT NOT NULL UNIQUE,
        tender_id INTEGER NOT NULL REFERENCES tenders(id) ON DELETE CASCADE,
        item_no TEXT NOT NULL,
        group_name TEXT,
        description TEXT NOT NULL,
        specification TEXT,
        unit TEXT,
        quantity REAL NOT NULL DEFAULT 0,
        estimated_rate_minor INTEGER NOT NULL DEFAULT 0,
        tax_pct REAL NOT NULL DEFAULT 0,
        sort_order INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      );`,
      `CREATE INDEX idx_boq_items_tender ON boq_items(tender_id);`,

      `CREATE TABLE boq_versions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid TEXT NOT NULL UNIQUE,
        tender_id INTEGER NOT NULL REFERENCES tenders(id) ON DELETE CASCADE,
        version_no INTEGER NOT NULL,
        locked INTEGER NOT NULL DEFAULT 0,
        snapshot TEXT NOT NULL,
        locked_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (tender_id, version_no)
      );`,
    ],
  },

  // =====================================================================
  // v5 — Bidders, bids, technical & financial evaluation, award
  // =====================================================================
  {
    version: 5,
    name: 'bids-evaluation-award',
    sql: [
      `CREATE TABLE tender_bidders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid TEXT NOT NULL UNIQUE,
        tender_id INTEGER NOT NULL REFERENCES tenders(id) ON DELETE CASCADE,
        contractor_id INTEGER NOT NULL REFERENCES contractors(id) ON DELETE RESTRICT,
        bidder_label TEXT,
        submission_time TEXT,
        bid_status TEXT NOT NULL DEFAULT 'submitted'
          CHECK (bid_status IN ('submitted','technical_opened','technically_qualified','technically_disqualified','clarification_required','financial_opened','awarded','rejected','withdrawn')),
        emd_paid_minor INTEGER,
        emd_details TEXT,
        technical_info TEXT,                 -- JSON (experience, turnover etc.)
        financial_info TEXT,                 -- JSON (quoted totals)
        rejection_reason TEXT,
        rank INTEGER,
        is_confidential INTEGER NOT NULL DEFAULT 1,
        created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (tender_id, contractor_id)
      );`,
      `CREATE INDEX idx_bidders_tender ON tender_bidders(tender_id);`,

      `CREATE TABLE bid_documents (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid TEXT NOT NULL UNIQUE,
        bidder_id INTEGER NOT NULL REFERENCES tender_bidders(id) ON DELETE CASCADE,
        doc_type TEXT NOT NULL,              -- technical | financial | emd | declaration | other
        document_id INTEGER,
        is_confidential INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      );`,

      `CREATE TABLE technical_openings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid TEXT NOT NULL UNIQUE,
        tender_id INTEGER NOT NULL REFERENCES tenders(id) ON DELETE CASCADE,
        opening_date TEXT NOT NULL,
        opened_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
        attendees TEXT,                      -- JSON list of {name, role}
        observations TEXT,
        minutes_document_id INTEGER,
        status TEXT NOT NULL DEFAULT 'completed',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      );`,

      `CREATE TABLE evaluation_criteria (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid TEXT NOT NULL UNIQUE,
        tender_id INTEGER NOT NULL REFERENCES tenders(id) ON DELETE CASCADE,
        code TEXT NOT NULL,
        criterion TEXT NOT NULL,
        requirement TEXT,
        is_required INTEGER NOT NULL DEFAULT 1,
        sort_order INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      );`,
      `CREATE INDEX idx_eval_criteria_tender ON evaluation_criteria(tender_id);`,

      `CREATE TABLE technical_evaluations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid TEXT NOT NULL UNIQUE,
        tender_id INTEGER NOT NULL REFERENCES tenders(id) ON DELETE CASCADE,
        bidder_id INTEGER NOT NULL REFERENCES tender_bidders(id) ON DELETE CASCADE,
        criterion_id INTEGER REFERENCES evaluation_criteria(id) ON DELETE SET NULL,
        result TEXT NOT NULL DEFAULT 'pass'
          CHECK (result IN ('pass','fail','clarification_required','not_applicable','verification_required')),
        bidder_response TEXT,
        remarks TEXT,
        evidence_document_id INTEGER,
        evaluated_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
        evaluated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (bidder_id, criterion_id)
      );`,

      `CREATE TABLE financial_bids (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid TEXT NOT NULL UNIQUE,
        tender_id INTEGER NOT NULL REFERENCES tenders(id) ON DELETE CASCADE,
        bidder_id INTEGER NOT NULL REFERENCES tender_bidders(id) ON DELETE CASCADE,
        total_amount_minor INTEGER NOT NULL DEFAULT 0,
        base_amount_minor INTEGER NOT NULL DEFAULT 0,
        tax_amount_minor INTEGER NOT NULL DEFAULT 0,
        discount_minor INTEGER NOT NULL DEFAULT 0,
        quoted_on TEXT,
        is_confidential INTEGER NOT NULL DEFAULT 1,
        created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (tender_id, bidder_id)
      );`,

      `CREATE TABLE financial_bid_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid TEXT NOT NULL UNIQUE,
        financial_bid_id INTEGER NOT NULL REFERENCES financial_bids(id) ON DELETE CASCADE,
        boq_item_id INTEGER REFERENCES boq_items(id) ON DELETE SET NULL,
        item_no TEXT,
        bidder_rate_minor INTEGER NOT NULL DEFAULT 0,
        quantity REAL NOT NULL DEFAULT 0,
        amount_minor INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      );`,

      `CREATE TABLE awards (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid TEXT NOT NULL UNIQUE,
        tender_id INTEGER NOT NULL REFERENCES tenders(id) ON DELETE RESTRICT,
        contractor_id INTEGER NOT NULL REFERENCES contractors(id) ON DELETE RESTRICT,
        bidder_id INTEGER REFERENCES tender_bidders(id) ON DELETE SET NULL,
        awarded_amount_minor INTEGER NOT NULL DEFAULT 0,
        rank INTEGER,
        approval_authority TEXT,
        approval_date TEXT,
        loa_number TEXT UNIQUE,
        loa_date TEXT,
        security_deposit_minor INTEGER,
        agreement_id INTEGER,
        work_order_id INTEGER,
        status TEXT NOT NULL DEFAULT 'recommended'
          CHECK (status IN ('recommended','approved','loa_issued','agreement_done','work_order_issued','completed','cancelled')),
        remarks TEXT,
        created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      );`,
      `CREATE INDEX idx_awards_tender ON awards(tender_id);`,

      `CREATE TABLE agreements (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid TEXT NOT NULL UNIQUE,
        tender_id INTEGER NOT NULL REFERENCES tenders(id) ON DELETE RESTRICT,
        award_id INTEGER REFERENCES awards(id) ON DELETE SET NULL,
        agreement_number TEXT UNIQUE,
        contractor_id INTEGER NOT NULL REFERENCES contractors(id) ON DELETE RESTRICT,
        amount_minor INTEGER NOT NULL DEFAULT 0,
        completion_period_days INTEGER,
        conditions TEXT,
        security_deposit_minor INTEGER,
        execution_date TEXT,
        document_id INTEGER,
        created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      );`,

      `CREATE TABLE work_orders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid TEXT NOT NULL UNIQUE,
        tender_id INTEGER NOT NULL REFERENCES tenders(id) ON DELETE RESTRICT,
        award_id INTEGER REFERENCES awards(id) ON DELETE SET NULL,
        agreement_id INTEGER REFERENCES agreements(id) ON DELETE SET NULL,
        project_id INTEGER REFERENCES projects(id) ON DELETE SET NULL,
        contractor_id INTEGER NOT NULL REFERENCES contractors(id) ON DELETE RESTRICT,
        work_order_number TEXT UNIQUE,
        amount_minor INTEGER NOT NULL DEFAULT 0,
        start_date TEXT,
        completion_date TEXT,
        conditions TEXT,
        document_id INTEGER,
        created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      );`,
    ],
  },

  // =====================================================================
  // v6 — Execution, measurements, bills, payments, completion
  // =====================================================================
  {
    version: 6,
    name: 'execution-measurement-bills-payments-completion',
    sql: [
      `CREATE TABLE work_progress (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid TEXT NOT NULL UNIQUE,
        project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
        tender_id INTEGER REFERENCES tenders(id) ON DELETE SET NULL,
        progress_date TEXT NOT NULL,
        physical_progress REAL NOT NULL DEFAULT 0,
        financial_progress REAL NOT NULL DEFAULT 0,
        milestone TEXT,
        notes TEXT,
        site_photo_document_id INTEGER,
        created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      );`,

      `CREATE TABLE site_instructions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid TEXT NOT NULL UNIQUE,
        project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
        instruction_date TEXT NOT NULL,
        instruction TEXT NOT NULL,
        issued_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
        document_id INTEGER,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      );`,

      `CREATE TABLE extension_requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid TEXT NOT NULL UNIQUE,
        project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
        requested_days INTEGER NOT NULL DEFAULT 0,
        reason TEXT,
        status TEXT NOT NULL DEFAULT 'pending'
          CHECK (status IN ('pending','approved','rejected')),
        decided_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
        decided_at TEXT,
        remarks TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      );`,

      `CREATE TABLE measurements (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid TEXT NOT NULL UNIQUE,
        project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
        tender_id INTEGER REFERENCES tenders(id) ON DELETE SET NULL,
        measurement_number TEXT NOT NULL,
        measurement_date TEXT NOT NULL,
        location TEXT,
        remarks TEXT,
        measured_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
        checked_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
        approved_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
        status TEXT NOT NULL DEFAULT 'draft'
          CHECK (status IN ('draft','checked','approved','final')),
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      );`,
      `CREATE INDEX idx_measurements_project ON measurements(project_id);`,

      `CREATE TABLE measurement_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid TEXT NOT NULL UNIQUE,
        measurement_id INTEGER NOT NULL REFERENCES measurements(id) ON DELETE CASCADE,
        boq_item_id INTEGER REFERENCES boq_items(id) ON DELETE SET NULL,
        item_no TEXT,
        description TEXT,
        unit TEXT,
        previous_quantity REAL NOT NULL DEFAULT 0,
        current_quantity REAL NOT NULL DEFAULT 0,
        cumulative_quantity REAL NOT NULL DEFAULT 0,
        rate_minor INTEGER NOT NULL DEFAULT 0,
        amount_minor INTEGER NOT NULL DEFAULT 0,
        overrun_flag INTEGER NOT NULL DEFAULT 0,
        remarks TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      );`,

      `CREATE TABLE bills (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid TEXT NOT NULL UNIQUE,
        fy_id INTEGER NOT NULL REFERENCES financial_years(id) ON DELETE RESTRICT,
        project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
        tender_id INTEGER REFERENCES tenders(id) ON DELETE SET NULL,
        work_order_id INTEGER REFERENCES work_orders(id) ON DELETE SET NULL,
        contractor_id INTEGER REFERENCES contractors(id) ON DELETE SET NULL,
        bill_number TEXT NOT NULL,
        bill_type TEXT NOT NULL DEFAULT 'running' CHECK (bill_type IN ('running','final')),
        bill_date TEXT NOT NULL,
        gross_work_value_minor INTEGER NOT NULL DEFAULT 0,
        previous_certified_minor INTEGER NOT NULL DEFAULT 0,
        current_bill_minor INTEGER NOT NULL DEFAULT 0,
        cumulative_minor INTEGER NOT NULL DEFAULT 0,
        retention_minor INTEGER NOT NULL DEFAULT 0,
        tax_minor INTEGER NOT NULL DEFAULT 0,
        deductions_minor INTEGER NOT NULL DEFAULT 0,
        recoveries_minor INTEGER NOT NULL DEFAULT 0,
        net_payable_minor INTEGER NOT NULL DEFAULT 0,
        status TEXT NOT NULL DEFAULT 'draft'
          CHECK (status IN ('draft','submitted','checked','certified','approved','paid','partially_paid','rejected','returned')),
        certified_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
        approved_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
        remarks TEXT,
        created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      );`,
      `CREATE INDEX idx_bills_fy ON bills(fy_id);`,
      `CREATE INDEX idx_bills_project ON bills(project_id);`,

      `CREATE TABLE bill_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid TEXT NOT NULL UNIQUE,
        bill_id INTEGER NOT NULL REFERENCES bills(id) ON DELETE CASCADE,
        measurement_item_id INTEGER REFERENCES measurement_items(id) ON DELETE SET NULL,
        item_no TEXT,
        description TEXT,
        quantity REAL NOT NULL DEFAULT 0,
        unit TEXT,
        rate_minor INTEGER NOT NULL DEFAULT 0,
        amount_minor INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      );`,

      `CREATE TABLE payments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid TEXT NOT NULL UNIQUE,
        fy_id INTEGER NOT NULL REFERENCES financial_years(id) ON DELETE RESTRICT,
        bill_id INTEGER REFERENCES bills(id) ON DELETE SET NULL,
        project_id INTEGER REFERENCES projects(id) ON DELETE SET NULL,
        contractor_id INTEGER REFERENCES contractors(id) ON DELETE SET NULL,
        voucher_no TEXT,
        payment_date TEXT NOT NULL,
        gross_amount_minor INTEGER NOT NULL DEFAULT 0,
        deductions_minor INTEGER NOT NULL DEFAULT 0,
        net_amount_minor INTEGER NOT NULL DEFAULT 0,
        payment_method TEXT,                 -- manual record; no bank integration unless configured
        transaction_reference TEXT,
        status TEXT NOT NULL DEFAULT 'recorded'
          CHECK (status IN ('recorded','approved','cancelled')),
        is_bank_integrated INTEGER NOT NULL DEFAULT 0,
        approval_authority TEXT,
        remarks TEXT,
        created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      );`,
      `CREATE INDEX idx_payments_fy ON payments(fy_id);`,
      `CREATE INDEX idx_payments_bill ON payments(bill_id);`,

      `CREATE TABLE completions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid TEXT NOT NULL UNIQUE,
        project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
        tender_id INTEGER REFERENCES tenders(id) ON DELETE SET NULL,
        completion_date TEXT,
        inspection_date TEXT,
        final_measurement_id INTEGER REFERENCES measurements(id) ON DELETE SET NULL,
        final_bill_id INTEGER REFERENCES bills(id) ON DELETE SET NULL,
        final_payment_id INTEGER REFERENCES payments(id) ON DELETE SET NULL,
        security_release_date TEXT,
        handover_date TEXT,
        status TEXT NOT NULL DEFAULT 'in_progress'
          CHECK (status IN ('in_progress','inspection_done','final_measurement_done','final_bill_done','final_payment_done','security_released','closed')),
        certificate_document_id INTEGER,
        closure_report_document_id INTEGER,
        remarks TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      );`,

      `CREATE TABLE contractor_performance (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid TEXT NOT NULL UNIQUE,
        contractor_id INTEGER NOT NULL REFERENCES contractors(id) ON DELETE CASCADE,
        project_id INTEGER REFERENCES projects(id) ON DELETE SET NULL,
        tender_id INTEGER REFERENCES tenders(id) ON DELETE SET NULL,
        quality_rating REAL,
        timeliness_rating REAL,
        completion_rating REAL,
        defect_notes TEXT,
        delay_notes TEXT,
        extension_notes TEXT,
        overall_remarks TEXT,
        rated_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
        rated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      );`,
    ],
  },

  // =====================================================================
  // v7 — Corrigenda, cancellations, retenders, workflow, audit, notifications, settings, templates, integrations
  // =====================================================================
  {
    version: 7,
    name: 'workflow-audit-notifications-settings-templates',
    sql: [
      `CREATE TABLE corrigenda (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid TEXT NOT NULL UNIQUE,
        tender_id INTEGER NOT NULL REFERENCES tenders(id) ON DELETE CASCADE,
        corrigendum_number TEXT NOT NULL,
        reason TEXT,
        changes TEXT NOT NULL,               -- JSON [{field, old_value, new_value}]
        new_dates TEXT,                      -- JSON
        status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','approved','published')),
        document_id INTEGER,
        created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      );`,
      `CREATE INDEX idx_corrigenda_tender ON corrigenda(tender_id);`,

      `CREATE TABLE tender_cancellations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid TEXT NOT NULL UNIQUE,
        tender_id INTEGER NOT NULL REFERENCES tenders(id) ON DELETE CASCADE,
        reason TEXT,
        authority TEXT,
        cancel_date TEXT,
        approval_authority TEXT,
        notice_document_id INTEGER,
        created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      );`,

      `CREATE TABLE retenders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid TEXT NOT NULL UNIQUE,
        original_tender_id INTEGER NOT NULL REFERENCES tenders(id) ON DELETE RESTRICT,
        new_tender_id INTEGER REFERENCES tenders(id) ON DELETE SET NULL,
        reason TEXT,
        carry_forward TEXT NOT NULL DEFAULT '[]',  -- JSON list of carried-forward sections
        created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      );`,

      // Generic workflow approval steps (tenders, bills, corrigenda, etc.)
      `CREATE TABLE approval_steps (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid TEXT NOT NULL UNIQUE,
        entity_type TEXT NOT NULL,           -- tender | bill | corrigendum | project | ...
        entity_id INTEGER NOT NULL,
        step_order INTEGER NOT NULL,
        step_name TEXT NOT NULL,             -- e.g. "Secretary Approval"
        required_role TEXT,
        status TEXT NOT NULL DEFAULT 'pending'
          CHECK (status IN ('pending','in_progress','approved','rejected','returned','clarification','skipped','cancelled')),
        actor_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
        action TEXT,
        remarks TEXT,
        acted_at TEXT,
        version_no INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      );`,
      `CREATE INDEX idx_approval_steps_entity ON approval_steps(entity_type, entity_id);`,

      `CREATE TABLE notifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid TEXT NOT NULL UNIQUE,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        type TEXT NOT NULL,
        title TEXT NOT NULL,
        body TEXT,
        entity_type TEXT,
        entity_id INTEGER,
        link TEXT,
        severity TEXT NOT NULL DEFAULT 'info' CHECK (severity IN ('info','warning','critical')),
        is_read INTEGER NOT NULL DEFAULT 0,
        channel TEXT NOT NULL DEFAULT 'inapp',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      );`,
      `CREATE INDEX idx_notifications_user ON notifications(user_id, is_read);`,

      `CREATE TABLE audit_logs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid TEXT NOT NULL UNIQUE,
        actor_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
        actor_name TEXT,
        actor_role TEXT,
        panchayat_id INTEGER,
        action TEXT NOT NULL,
        entity_type TEXT NOT NULL,
        entity_id INTEGER,
        entity_label TEXT,
        old_value TEXT,
        new_value TEXT,
        reason TEXT,
        ip_address TEXT,
        user_agent TEXT,
        request_id TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      );`,
      `CREATE INDEX idx_audit_entity ON audit_logs(entity_type, entity_id);`,
      `CREATE INDEX idx_audit_actor ON audit_logs(actor_id);`,
      `CREATE INDEX idx_audit_created ON audit_logs(created_at);`,

      `CREATE TABLE numbering_sequences (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        scope TEXT NOT NULL,                 -- tender_nit | bill | contractor | measurement | work_order | agreement | ...
        fy_id INTEGER REFERENCES financial_years(id) ON DELETE RESTRICT,
        panchayat_id INTEGER REFERENCES panchayats(id) ON DELETE RESTRICT,
        prefix TEXT NOT NULL DEFAULT '',
        last_value INTEGER NOT NULL DEFAULT 0,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (scope, fy_id, panchayat_id, prefix)
      );`,

      `CREATE TABLE settings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        key TEXT NOT NULL UNIQUE,
        value TEXT,
        description TEXT,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      );`,

      `CREATE TABLE templates (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid TEXT NOT NULL UNIQUE,
        code TEXT NOT NULL,
        name TEXT NOT NULL,
        doc_type TEXT NOT NULL,              -- nit | tender_form | boq | loa | agreement | work_order | completion_certificate | ...
        version INTEGER NOT NULL DEFAULT 1,
        content_html TEXT NOT NULL,
        merge_fields TEXT,                   -- JSON list of available merge fields
        is_active INTEGER NOT NULL DEFAULT 1,
        is_default INTEGER NOT NULL DEFAULT 0,
        created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      );`,

      `CREATE TABLE document_generations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid TEXT NOT NULL UNIQUE,
        doc_type TEXT NOT NULL,
        entity_type TEXT,
        entity_id INTEGER,
        template_id INTEGER,
        template_version INTEGER,
        document_id INTEGER,
        verification_code TEXT,
        generated_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
        generated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      );`,

      // Official e-tender portal integration readiness (manual reference by default).
      `CREATE TABLE external_refs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid TEXT NOT NULL UNIQUE,
        entity_type TEXT NOT NULL,           -- tender | award
        entity_id INTEGER NOT NULL,
        official_portal TEXT,
        external_tender_id TEXT,
        external_reference_no TEXT,
        publication_status TEXT,
        official_url TEXT,
        publication_timestamp TEXT,
        external_status TEXT,
        last_synced_at TEXT,
        sync_method TEXT NOT NULL DEFAULT 'manual',
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      );`,

      `CREATE TABLE imports (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid TEXT NOT NULL UNIQUE,
        import_type TEXT NOT NULL,
        file_name TEXT,
        status TEXT NOT NULL DEFAULT 'validating'
          CHECK (status IN ('validating','preview','committed','failed')),
        total_rows INTEGER NOT NULL DEFAULT 0,
        valid_rows INTEGER NOT NULL DEFAULT 0,
        error_report TEXT,
        created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      );`,

      `CREATE TABLE backups (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid TEXT NOT NULL UNIQUE,
        backup_type TEXT NOT NULL DEFAULT 'manual',
        file_name TEXT,
        size_bytes INTEGER,
        status TEXT NOT NULL DEFAULT 'completed',
        verified INTEGER NOT NULL DEFAULT 0,
        created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
      );`,
    ],
  },
];
