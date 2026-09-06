'use strict';

/**
 * Static permission catalog (seeded into the permissions table).
 * Grouped by category. Role→permission grants are seeded in seed.js.
 *
 * Least privilege: every sensitive action has a distinct permission code,
 * enforced server-side in services/middleware.
 */
const PERMISSIONS = [
  // Panchayat & settings
  { code: 'panchayat.view', name: 'View Panchayat configuration', category: 'panchayat' },
  { code: 'panchayat.manage', name: 'Configure Panchayat', category: 'panchayat' },
  { code: 'settings.manage', name: 'Manage system settings', category: 'settings' },

  // Users & roles
  { code: 'user.view', name: 'View users', category: 'users' },
  { code: 'user.manage', name: 'Create/edit users', category: 'users' },
  { code: 'role.manage', name: 'Manage roles and permissions', category: 'users' },
  { code: 'audit.view', name: 'View audit trail', category: 'audit' },
  { code: 'audit.manage', name: 'Manage audit configuration', category: 'audit' },

  // Financial years
  { code: 'fy.view', name: 'View financial years', category: 'financial-year' },
  { code: 'fy.manage', name: 'Create/open/close financial years', category: 'financial-year' },

  // Rules & compliance
  { code: 'rules.view', name: 'View rules & references', category: 'rules' },
  { code: 'rules.manage', name: 'Manage rules & references', category: 'rules' },

  // Schemes & funds
  { code: 'scheme.view', name: 'View schemes/funds', category: 'master-data' },
  { code: 'scheme.manage', name: 'Manage schemes/funds', category: 'master-data' },

  // Projects & plans
  { code: 'project.view', name: 'View projects', category: 'project' },
  { code: 'project.manage', name: 'Create/edit projects', category: 'project' },
  { code: 'plan.view', name: 'View procurement plans', category: 'project' },
  { code: 'plan.manage', name: 'Manage procurement plans', category: 'project' },

  // Contractors
  { code: 'contractor.view', name: 'View contractors', category: 'contractor' },
  { code: 'contractor.manage', name: 'Create/edit contractors', category: 'contractor' },
  { code: 'contractor.sensitive', name: 'View contractor sensitive info (PAN/GST/bank)', category: 'contractor' },

  // Tenders
  { code: 'tender.view', name: 'View tenders', category: 'tender' },
  { code: 'tender.manage', name: 'Create/edit tenders', category: 'tender' },
  { code: 'tender.approve', name: 'Approve tender workflow', category: 'tender' },
  { code: 'tender.publish', name: 'Publish tenders', category: 'tender' },
  { code: 'tender.corrigendum', name: 'Issue corrigenda', category: 'tender' },
  { code: 'tender.cancel', name: 'Cancel tenders', category: 'tender' },
  { code: 'tender.retender', name: 'Create re-tenders', category: 'tender' },
  { code: 'boq.manage', name: 'Manage BOQ', category: 'tender' },
  { code: 'nit.manage', name: 'Generate/manage NIT', category: 'tender' },

  // Documents
  { code: 'document.view', name: 'View documents', category: 'documents' },
  { code: 'document.upload', name: 'Upload documents', category: 'documents' },
  { code: 'document.download', name: 'Download documents', category: 'documents' },
  { code: 'document.delete', name: 'Delete documents', category: 'documents' },

  // Bids & evaluation
  { code: 'bid.manage', name: 'Record/manage bidders', category: 'bids' },
  { code: 'bid.view_confidential', name: 'View confidential bid info', category: 'bids' },
  { code: 'technical_open.manage', name: 'Manage technical bid opening', category: 'bids' },
  { code: 'technical_eval.manage', name: 'Perform technical evaluation', category: 'bids' },
  { code: 'financial_open.manage', name: 'Manage financial bid opening', category: 'bids' },
  { code: 'financial_eval.manage', name: 'Manage financial evaluation', category: 'bids' },
  { code: 'comparative.view', name: 'View comparative statements', category: 'bids' },

  // Award
  { code: 'award.view', name: 'View awards/LOA/agreements/work orders', category: 'award' },
  { code: 'award.manage', name: 'Manage award/LOA/agreement/work order', category: 'award' },
  { code: 'award.approve', name: 'Approve awards', category: 'award' },

  // Execution, measurement, bills, payments
  { code: 'execution.manage', name: 'Manage work execution/progress', category: 'execution' },
  { code: 'measurement.manage', name: 'Manage measurements', category: 'execution' },
  { code: 'measurement.approve', name: 'Approve measurements', category: 'execution' },
  { code: 'bill.view', name: 'View bills', category: 'billing' },
  { code: 'bill.manage', name: 'Manage bills', category: 'billing' },
  { code: 'bill.approve', name: 'Approve/certify bills', category: 'billing' },
  { code: 'payment.view', name: 'View payments', category: 'billing' },
  { code: 'payment.manage', name: 'Record/manage payments', category: 'billing' },
  { code: 'payment.approve', name: 'Approve payments', category: 'billing' },
  { code: 'completion.manage', name: 'Manage completion/closing', category: 'execution' },

  // Reports
  { code: 'report.view', name: 'View reports', category: 'reports' },
  { code: 'report.export', name: 'Export reports (PDF/Excel)', category: 'reports' },

  // Public portal
  { code: 'public.view', name: 'View public portal data', category: 'public' },

  // System
  { code: 'import.manage', name: 'Import data (Excel/CSV)', category: 'system' },
  { code: 'backup.manage', name: 'Manage backups', category: 'system' },
  { code: 'template.manage', name: 'Manage document templates', category: 'system' },
  { code: 'notification.view', name: 'View notifications', category: 'system' },
];

// Role definitions (seeded). code → permission codes.
const ROLE_DEFS = [
  {
    code: 'super_admin',
    name: 'Super Admin',
    description: 'System-wide administrator (all permissions).',
    system: true,
    allPermissions: true,
  },
  {
    code: 'panchayat_admin',
    name: 'Panchayat Admin',
    description: 'Administers a single Panchayat: configuration, users, rules, data.',
    system: true,
    allPermissions: false,
    permissions: [
      'panchayat.view', 'panchayat.manage', 'settings.manage',
      'user.view', 'user.manage',
      'fy.view', 'fy.manage',
      'rules.view', 'rules.manage',
      'scheme.view', 'scheme.manage',
      'project.view', 'project.manage', 'plan.view', 'plan.manage',
      'contractor.view', 'contractor.manage', 'contractor.sensitive',
      'tender.view', 'tender.manage', 'tender.publish', 'tender.corrigendum', 'tender.cancel', 'tender.retender',
      'boq.manage', 'nit.manage',
      'document.view', 'document.upload', 'document.download',
      'bid.manage', 'bid.view_confidential', 'technical_open.manage',
      'report.view', 'report.export',
      'import.manage', 'backup.manage', 'template.manage', 'notification.view',
    ],
  },
  {
    code: 'pradhan',
    name: 'Pradhan',
    description: 'Elected head — approvals, oversight.',
    system: true,
    allPermissions: false,
    permissions: [
      'panchayat.view',
      'fy.view', 'rules.view', 'scheme.view', 'project.view', 'plan.view',
      'contractor.view', 'tender.view', 'tender.approve', 'award.view', 'award.approve',
      'document.view', 'document.download',
      'bid.view_confidential', 'comparative.view',
      'bill.view', 'bill.approve', 'payment.view', 'payment.approve',
      'report.view', 'report.export', 'notification.view',
    ],
  },
  {
    code: 'upa_pradhan',
    name: 'Upa-Pradhan',
    description: 'Deputy head — approvals when delegated.',
    system: true,
    allPermissions: false,
    permissions: [
      'panchayat.view', 'fy.view', 'rules.view', 'scheme.view', 'project.view',
      'contractor.view', 'tender.view', 'tender.approve',
      'document.view', 'document.download', 'comparative.view',
      'bill.view', 'bill.approve', 'payment.view',
      'report.view', 'report.export', 'notification.view',
    ],
  },
  {
    code: 'panchayat_secretary',
    name: 'Panchayat Secretary',
    description: 'Secretary — day-to-day records, submissions, workflow.',
    system: true,
    allPermissions: false,
    permissions: [
      'panchayat.view',
      'fy.view', 'rules.view', 'scheme.view', 'scheme.manage',
      'project.view', 'project.manage', 'plan.view', 'plan.manage',
      'contractor.view', 'contractor.manage',
      'tender.view', 'tender.manage', 'tender.approve', 'tender.publish', 'tender.corrigendum', 'tender.retender',
      'boq.manage', 'nit.manage',
      'document.view', 'document.upload', 'document.download',
      'bid.manage', 'bid.view_confidential', 'technical_open.manage', 'financial_open.manage',
      'technical_eval.manage', 'financial_eval.manage', 'comparative.view',
      'award.manage', 'execution.manage', 'measurement.manage', 'measurement.approve',
      'bill.manage', 'bill.approve', 'payment.manage', 'completion.manage',
      'report.view', 'report.export', 'import.manage', 'notification.view',
    ],
  },
  {
    code: 'technical_officer',
    name: 'Technical Officer / Engineer',
    description: 'Engineer — estimates, BOQ, measurements, execution.',
    system: true,
    allPermissions: false,
    permissions: [
      'panchayat.view', 'fy.view', 'rules.view', 'scheme.view',
      'project.view', 'project.manage', 'plan.view',
      'contractor.view',
      'tender.view', 'tender.manage', 'boq.manage', 'nit.manage',
      'document.view', 'document.upload', 'document.download',
      'bid.manage', 'technical_open.manage', 'technical_eval.manage', 'comparative.view',
      'execution.manage', 'measurement.manage', 'measurement.approve',
      'bill.view', 'bill.manage', 'completion.manage',
      'report.view', 'report.export', 'notification.view',
    ],
  },
  {
    code: 'accounts_officer',
    name: 'Accounts Officer',
    description: 'Accounts — bills, payments, reconciliation.',
    system: true,
    allPermissions: false,
    permissions: [
      'panchayat.view', 'fy.view', 'rules.view', 'scheme.view', 'project.view',
      'contractor.view', 'contractor.sensitive', 'tender.view', 'comparative.view',
      'document.view', 'document.download',
      'bill.view', 'bill.manage', 'bill.approve', 'payment.manage', 'payment.approve',
      'report.view', 'report.export', 'notification.view',
    ],
  },
  {
    code: 'tender_committee',
    name: 'Tender Committee Member',
    description: 'Tender committee — evaluation and recommendation.',
    system: true,
    allPermissions: false,
    permissions: [
      'panchayat.view', 'fy.view', 'rules.view', 'scheme.view', 'project.view',
      'contractor.view', 'tender.view',
      'document.view', 'document.download',
      'bid.view_confidential', 'technical_open.manage', 'technical_eval.manage', 'financial_open.manage',
      'financial_eval.manage', 'comparative.view', 'award.manage',
      'report.view', 'notification.view',
    ],
  },
  {
    code: 'data_entry',
    name: 'Data Entry Operator',
    description: 'Data entry only — no approvals, no financial finalization.',
    system: true,
    allPermissions: false,
    permissions: [
      'panchayat.view', 'fy.view', 'rules.view', 'scheme.view', 'project.view', 'project.manage',
      'contractor.view', 'contractor.manage', 'tender.view', 'tender.manage', 'boq.manage',
      'document.view', 'document.upload', 'bid.manage', 'report.view', 'notification.view',
    ],
  },
  {
    code: 'auditor',
    name: 'Auditor',
    description: 'Read-only across domains plus audit access.',
    system: true,
    allPermissions: false,
    permissions: [
      'panchayat.view', 'fy.view', 'rules.view', 'scheme.view', 'project.view', 'plan.view',
      'contractor.view', 'tender.view', 'comparative.view',
      'document.view', 'document.download', 'audit.view',
      'bill.view', 'payment.view', 'report.view', 'report.export', 'notification.view',
    ],
  },
  {
    code: 'viewer',
    name: 'Viewer',
    description: 'Read-only viewer.',
    system: true,
    allPermissions: false,
    permissions: [
      'panchayat.view', 'fy.view', 'rules.view', 'scheme.view', 'project.view',
      'contractor.view', 'tender.view', 'document.view', 'report.view', 'notification.view',
    ],
  },
];

module.exports = { PERMISSIONS, ROLE_DEFS };
