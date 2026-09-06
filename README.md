# Gram Panchayat Procurement, Tender, Work, Billing, Payment, Audit & Reporting Portal

A production-grade management portal for West Bengal Gram Panchayats covering the full
public-works lifecycle:

**Financial Year → Scheme → Project → Administrative Approval → Technical Sanction →
Tender → NIT → Publication → Bidding → Technical Evaluation → Financial Evaluation →
Comparative Statement → L1/L2/L3 → Approval → LOA → Agreement → Work Order →
Execution → Measurement → Bills → Payments → Completion → Audit → Reports.**

> ⚠️ **Legal-integrity notice.** This system records and enforces *process*, not law.
> Generated documents are **system-generated drafts**, never official government
> documents. No digital signatures, e-procurement sync, or bank payment execution is
> faked. Payments are recorded as **"Payment Recorded"**, not "Payment Executed".
> Rules and thresholds are versioned configuration sourced from official West Bengal
> Government publications and are labelled **"Verification Required"** wherever the
> applicable authority/circular is uncertain.

---

## Technology

| Concern | Choice |
| --- | --- |
| Runtime | Node.js 22+ (built-in `node:sqlite`, zero native deps) |
| Web framework | Express 4 + EJS (CommonJS) |
| Storage | SQLite (WAL), single-file `data/app.db` |
| Money | **Integer minor units only** (₹1 = 100 minor). No floating point anywhere. |
| PDF | PDFKit (server-generated drafts) |
| Spreadsheet | ExcelJS (BOQ import/export, reports) |
| Uploads | Multer (25 MB default) |
| Tests | `node:test` + supertest |

---

## Quick start

```bash
npm install
cp .env.example .env        # then set SESSION_SECRET
npm start                   # http://localhost:3000
```

Default seeded admin (change in production):

| Field | Value |
| --- | --- |
| Email | `admin@panchayat.local` |
| Password | `ChangeMe@12345` |

Useful scripts:

```bash
npm start          # run server
npm run dev        # run with --watch
npm test           # full test suite (unit + integration, in-memory DB)
npm run lint       # eslint
npm run seed       # re-run essential seed (idempotent)
npm run seed:reset # drop all tables, migrate, reseed
npm run demo       # clearly-labelled [DEMO] end-to-end sample data
npm run demo:reset # remove [DEMO] sample data
npm run backup     # SQLite backup
```

`bash scripts/lifecycle-test.sh [base_url]` runs a full end-to-end HTTP smoke test
(login → tender → award → work order → bill → payment → completion) against a running
server. It assumes a freshly seeded database.

---

## Architecture

```
src/
├── server.js          # bootstrap (listen, session store)
├── app.js             # Express shell, middleware, error handling
├── config.js          # env loader + path defaults
├── sessionStore.js    # SQLite-backed express-session store
├── auth/              # passwords (bcrypt), permissions matrix, RBAC middleware
├── db/                # database wrapper, migrations (versioned), seed, demo data
├── routes/
│   ├── api.js         # full REST API (server-side authorization on every route)
│   └── pages.js       # EJS page router
├── services/          # domain logic (18 services)
├── util/              # money (minor-unit), dates (Indian FY), errors, uuid
└── views/             # EJS templates
```

### Money (decimal-safe)

`src/util/money.js` performs all arithmetic in **integer minor units** (paise).
API endpoints accept `*_minor` integer fields and return `*_minor` integers; the UI
formats them. There is no `float`/`double` money anywhere in the stack.

### Authorization

Every API route enforces a permission server-side (`src/auth/rbac.js`). The frontend
never gates business logic. Records lock at workflow stages (e.g. a published tender
cannot be edited; a certified bill cannot be silently changed).

### Workflow engine

`src/services/workflowService.js` drives role-based approval chains for tenders and
bills. Each step requires a specific role (`technical_officer`, `panchayat_secretary`,
`pradhan`, `tender_committee`, …); wrong-role attempts are rejected with 403.

### Rules & Compliance engine

`src/services/complianceService.js` evaluates a versioned, configurable rule set
(`rulesets` + `rules` tables, seeded from official WB sources in `rule_references`).
Every finding is one of `pass / fail / warning / info / verification`. Manual or
uncertain rules return **"Verification Required"** rather than a false legal claim.

### Numbering

`src/services/numberingService.js` issues FY-scoped sequence numbers such as
`GP/NIT/2026-27/001`, `LOA/2026-27/001`, `WO/2026-27/001`, `BILL/2026-27/001`,
`PVR/2026-27/0001`.

### Documents & templates

Uploaded files (contractor registrations, tenders) and generated PDF drafts (NIT, LOA,
Work Order, Completion Certificate) are stored under `data/`. Templates are versioned
in the `templates` table; every generated draft carries a verification code and a
"system-generated draft" notice.

---

## Domain model (summary)

- **Financial years** (`financial_years`) — Indian FY (Apr–Mar), one *current* at a time.
- **Schemes / Funds** — funding sources and schemes.
- **Projects** — approved work with estimate, admin approval & technical sanction.
- **Tenders** — BOQ, corrigenda, re-tender, cancellation; versioned snapshots (original data is never overwritten).
- **Bids & evaluations** — two-bid system (technical then financial), L1/L2/L3 ranking.
- **Awards** — recommendation → approval → LOA → agreement → work order.
- **Execution** — progress, extensions, measurements (with overrun flags).
- **Bills & payments** — running/final bills, retention, deductions, recoveries, recorded payments.
- **Completion** — inspection → final measurement → final bill → security release → closure + certificate.
- **Audit** — append-only audit log on every mutating action.
- **Reports** — reconciliation (awarded vs paid vs completed), dimensional reports.
- **Public portal** — `/public` exposes only published, non-confidential data.

---

## Tests

`npm test` runs 36 tests: money/dates/compliance/workflow unit tests, the full
end-to-end lifecycle integration test, and a hostile-QA security suite
(unauthenticated access, privilege escalation, record locking, duplicate numbers,
over-payment, document expiry, cancellation/reactivation, public-data leakage).

## Security notes

- bcrypt password hashing; session cookies (`httpOnly`, `sameSite=lax`); SQLite
  parameterised statements only (no string-built SQL for user input).
- Path-traversal-guarded uploads/downloads with allow-listed MIME types.
- Auth rate-limiting; CSRF-sensible (same-site + state-changing POSTs are session-bound).
- `X-Powered-By` disabled; security headers applied.
