#!/usr/bin/env bash
# End-to-end lifecycle smoke test via the HTTP API.
# Usage: ./scripts/lifecycle-test.sh [base_url]  (defaults to http://localhost:3000)
set -euo pipefail
BASE="${1:-http://localhost:3000}"
COOKIE="$(mktemp)"
trap 'rm -f "$COOKIE"' EXIT

J() { node -e "let s='';process.stdin.on('data',d=>s+=d).on('end',()=>{try{const j=JSON.parse(s);$1}catch(e){console.log('PARSE_ERR',s.slice(0,200));process.exit(1)}})"; }
STEP() { echo "=== $* ==="; }

STEP "Login"
curl -s -c "$COOKIE" -X POST "$BASE/api/auth/login" -H 'Content-Type: application/json' -d '{"email":"admin@panchayat.local","password":"ChangeMe@12345"}' | J 'if(!j.data)throw new Error(JSON.stringify(j));console.log("login OK:", j.data.user.email)'

STEP "Create contractors"
C1=$(curl -s -b "$COOKIE" -X POST "$BASE/api/contractors" -H 'Content-Type: application/json' -d '{"legal_name":"ABC Constructions","registration_class":"Class I","pan":"ABCDE1234F","gst":"19ABCDE1234F1Z5"}' | J 'console.log(j.data.id)')
C2=$(curl -s -b "$COOKIE" -X POST "$BASE/api/contractors" -H 'Content-Type: application/json' -d '{"legal_name":"XYZ Infra Pvt Ltd","registration_class":"Class II","pan":"XYZAB5678G"}' | J 'console.log(j.data.id)')
echo "contractors: $C1, $C2"

STEP "Create project"
P=$(curl -s -b "$COOKIE" -X POST "$BASE/api/projects" -H 'Content-Type: application/json' -d '{"fy_id":3,"scheme_id":1,"fund_id":1,"work_name":"Construction of CC road from school to temple","location":"Mouza Sample","estimate_amount_minor":100000000,"sanctioned_amount_minor":115000000,"administrative_approval_no":"AA-1","technical_sanction_no":"TS-1"}' | J 'console.log(j.data.id)')
echo "project: $P"

STEP "Create tender"
T=$(curl -s -b "$COOKIE" -X POST "$BASE/api/tenders" -H 'Content-Type: application/json' -d "{\"fy_id\":3,\"procurement_category\":\"works\",\"tender_type\":\"open\",\"ruleset_id\":1,\"work_name\":\"Construction of CC road from school to temple\",\"title\":\"CC road work\",\"location\":\"Mouza Sample\",\"scheme_id\":1,\"fund_id\":1,\"project_id\":$P}" | J 'console.log(j.data.id)')
echo "tender: $T"

STEP "Update tender (approvals, financials, schedule)"
curl -s -b "$COOKIE" -X PUT "$BASE/api/tenders/$T" -H 'Content-Type: application/json' -d '{"admin_approval_no":"AA-2026/01","admin_approval_date":"2026-06-01","admin_approval_authority":"Pradhan","admin_approval_amount_minor":120000000,"tech_sanction_no":"TS-2026/05","tech_sanction_date":"2026-06-05","tech_sanction_authority":"Executive Engineer","tech_sanction_amount_minor":115000000,"estimated_cost_minor":100000000,"tender_value_minor":100000000,"emd_minor":2000000,"tender_fee_minor":50000,"completion_period_days":90,"publication_date":"2026-07-01","bid_start_date":"2026-07-02","bid_close_date":"2026-07-15","technical_open_date":"2026-07-16","financial_open_date":"2026-07-18"}' | J 'console.log("updated:", j.data.tender_number)'

STEP "Add BOQ"
curl -s -b "$COOKIE" -X POST "$BASE/api/tenders/$T/boq" -H 'Content-Type: application/json' -d '{"items":[{"item_no":"1","description":"Earthwork excavation","unit":"cum","quantity":100,"estimated_rate_minor":25000,"tax_pct":0},{"item_no":"2","description":"CC M20 concrete","unit":"cum","quantity":50,"estimated_rate_minor":550000,"tax_pct":0}]}' | J 'console.log("BOQ total minor:", j.data.totals.totalMinor, "=", "₹"+(j.data.totals.totalMinor/100))'

STEP "Compliance check"
curl -s -b "$COOKIE" -X POST "$BASE/api/tenders/$T/compliance" -H 'Content-Type: application/json' -d '{}' | J 'console.log("summary:", JSON.stringify(j.data.summary))'

STEP "Submit + workflow approve"
curl -s -b "$COOKIE" -X POST "$BASE/api/tenders/$T/submit" -H 'Content-Type: application/json' -d '{}' | J 'console.log("submit:", j.data.status)'
for i in 1 2 3 4 5; do
  curl -s -b "$COOKIE" -X POST "$BASE/api/tenders/$T/workflow" -H 'Content-Type: application/json' -d '{"action":"approve","remarks":"ok"}' | J 'console.log("  step ->", j.data.tender.status, "done:", j.data.done)'
done

STEP "Generate NIT (PDF)"
NIT=$(curl -s -b "$COOKIE" -X POST "$BASE/api/tenders/$T/nit" -H 'Content-Type: application/json' -d '{}' | J 'console.log(j.data.document.id)')
echo "NIT doc id: $NIT"

STEP "Publish -> bidding -> close bids"
curl -s -b "$COOKIE" -X POST "$BASE/api/tenders/$T/publish" -H 'Content-Type: application/json' -d '{}' | J 'console.log("publish:", j.data.status)'
curl -s -b "$COOKIE" -X POST "$BASE/api/tenders/$T/bidding" -H 'Content-Type: application/json' -d '{}' | J 'console.log("bidding:", j.data.status)'

STEP "Record bidders"
B1=$(curl -s -b "$COOKIE" -X POST "$BASE/api/tenders/$T/bidders" -H 'Content-Type: application/json' -d "{\"contractor_id\":$C1}" | J 'console.log(j.data.id)')
B2=$(curl -s -b "$COOKIE" -X POST "$BASE/api/tenders/$T/bidders" -H 'Content-Type: application/json' -d "{\"contractor_id\":$C2}" | J 'console.log(j.data.id)')
echo "bidders: $B1, $B2"

curl -s -b "$COOKIE" -X POST "$BASE/api/tenders/$T/close-bids" -H 'Content-Type: application/json' -d '{}' | J 'console.log("close-bids:", j.data.status)'
curl -s -b "$COOKIE" -X POST "$BASE/api/tenders/$T/technical-open" -H 'Content-Type: application/json' -d '{}' | J 'console.log("technical-open -> tender:", j.data.id ? "ok" : "err")'
curl -s -b "$COOKIE" -X GET "$BASE/api/tenders/$T" | J 'console.log("tender status after open:", j.data.tender.status)'

STEP "Technical evaluation"
K1=$(curl -s -b "$COOKIE" -X POST "$BASE/api/tenders/$T/criteria" -H 'Content-Type: application/json' -d '{"code":"REG","criterion":"Valid Registration","requirement":"Submitted"}' | J 'console.log(j.data.id)')
K2=$(curl -s -b "$COOKIE" -X POST "$BASE/api/tenders/$T/criteria" -H 'Content-Type: application/json' -d '{"code":"EXP","criterion":"Similar Work Experience","requirement":"Submitted"}' | J 'console.log(j.data.id)')
echo "criteria: $K1, $K2"
curl -s -b "$COOKIE" -X POST "$BASE/api/tenders/$T/evaluations" -H 'Content-Type: application/json' -d "{\"bidder_id\":$B1,\"criterion_id\":$K1,\"result\":\"pass\"}" | J 'console.log("eval B1/K1:", j.data.result)'
curl -s -b "$COOKIE" -X POST "$BASE/api/tenders/$T/evaluations" -H 'Content-Type: application/json' -d "{\"bidder_id\":$B1,\"criterion_id\":$K2,\"result\":\"pass\"}" | J 'console.log("eval B1/K2:", j.data.result)'
curl -s -b "$COOKIE" -X POST "$BASE/api/tenders/$T/evaluations" -H 'Content-Type: application/json' -d "{\"bidder_id\":$B2,\"criterion_id\":$K1,\"result\":\"pass\"}" | J 'console.log("eval B2/K1:", j.data.result)'
curl -s -b "$COOKIE" -X POST "$BASE/api/tenders/$T/evaluations" -H 'Content-Type: application/json' -d "{\"bidder_id\":$B2,\"criterion_id\":$K2,\"result\":\"fail\"}" | J 'console.log("eval B2/K2:", j.data.result)'
curl -s -b "$COOKIE" -X POST "$BASE/api/tenders/$T/finalize-technical" -H 'Content-Type: application/json' -d "{\"rejectionReasons\":{\"$B2\":\"No similar work experience\"}}" | J 'console.log("finalize -> tender status:", j.data.tender ? j.data.tender.status : JSON.stringify(j))'

STEP "Financial bids + ranking"
curl -s -b "$COOKIE" -X POST "$BASE/api/tenders/$T/financial-bids" -H 'Content-Type: application/json' -d "{\"bidder_id\":$B1,\"totalAmountMinor\":95000000}" | J 'console.log("fin bid B1:", j.data.total_amount_minor)'
curl -s -b "$COOKIE" -X POST "$BASE/api/tenders/$T/rank" -H 'Content-Type: application/json' -d '{}' | J 'console.log("rankings:", JSON.stringify(j.data.rankings))'

STEP "Award -> LOA -> agreement -> work order"
A=$(curl -s -b "$COOKIE" -X POST "$BASE/api/awards/recommend" -H 'Content-Type: application/json' -d "{\"tender_id\":$T,\"contractor_id\":$C1,\"awarded_amount_minor\":95000000}" | J 'console.log(j.data.id)')
echo "award: $A"
curl -s -b "$COOKIE" -X POST "$BASE/api/awards/$A/approve" -H 'Content-Type: application/json' -d '{}' | J 'console.log("approve:", j.data.status)'
curl -s -b "$COOKIE" -X POST "$BASE/api/awards/$A/loa" -H 'Content-Type: application/json' -d '{}' | J 'console.log("loa:", j.data.award.loa_number)'
curl -s -b "$COOKIE" -X POST "$BASE/api/awards/$A/agreement" -H 'Content-Type: application/json' -d '{}' | J 'console.log("agreement:", j.data.agreement_number)'
WO=$(curl -s -b "$COOKIE" -X POST "$BASE/api/awards/$A/work-order" -H 'Content-Type: application/json' -d '{}' | J 'console.log(j.data.work_order_number)')
echo "work order: $WO"

STEP "Execution: measurement + bill + payment + completion"
curl -s -b "$COOKIE" -X POST "$BASE/api/projects/$P/progress" -H 'Content-Type: application/json' -d '{"physicalProgress":40,"financialProgress":30}' | J 'console.log("progress:", j.data.id ? "ok" : "err")'
M=$(curl -s -b "$COOKIE" -X POST "$BASE/api/projects/$P/measurements" -H 'Content-Type: application/json' -d '{}' | J 'console.log(j.data.id)')
echo "measurement: $M"
BOQ1=$(curl -s -b "$COOKIE" -X GET "$BASE/api/tenders/$T/boq" | J 'console.log(j.data.items[0].id)')
curl -s -b "$COOKIE" -X POST "$BASE/api/measurements/$M/items" -H 'Content-Type: application/json' -d "{\"boqItemId\":$BOQ1,\"itemNo\":\"1\",\"description\":\"Earthwork excavation\",\"unit\":\"cum\",\"currentQuantity\":40,\"rateMinor\":25000}" | J 'console.log("measurement item amount:", j.data.amount_minor, "overrun:", j.data.overrun_flag)'
BILL=$(curl -s -b "$COOKIE" -X POST "$BASE/api/projects/$P/bills" -H 'Content-Type: application/json' -d '{}' | J 'console.log(j.data.id)')
echo "bill: $BILL"
# gross work value ₹25,00,000 (40% of ₹62.5L? — just use a realistic value: 40 cum * ₹250 = ₹10,000; use gross 1000000 minor = ₹10,000)
curl -s -b "$COOKIE" -X PUT "$BASE/api/bills/$BILL" -H 'Content-Type: application/json' -d '{"grossWorkValueMinor":1000000,"retentionPct":5,"taxAmountMinor":0,"deductionsMinor":0,"recoveriesMinor":0}' | J 'console.log("bill net:", j.data.net_payable_minor)'
curl -s -b "$COOKIE" -X POST "$BASE/api/bills/$BILL/submit" -H 'Content-Type: application/json' -d '{}' | J 'console.log("bill submit:", j.data.status)'
# Approve bill workflow (Submitted -> Technical Check -> Accounts Certification -> Approval)
for a in 1 2 3 4; do
  curl -s -b "$COOKIE" -X POST "$BASE/api/bills/$BILL/workflow" -H 'Content-Type: application/json' -d '{"action":"approve","remarks":"ok"}' | J 'console.log("  bill step ->", j.data.status)'
done
curl -s -b "$COOKIE" -X POST "$BASE/api/bills/$BILL/payments" -H 'Content-Type: application/json' -d '{"netAmountMinor":950000}' | J 'console.log("payment:", j.data.voucher_no, "net:", j.data.net_amount_minor)'
curl -s -b "$COOKIE" -X POST "$BASE/api/projects/$P/completion" -H 'Content-Type: application/json' -d '{"status":"closed"}' | J 'console.log("completion:", j.data.status)'

STEP "Final state"
curl -s -b "$COOKIE" -X GET "$BASE/api/tenders/$T" | J 'console.log("tender status:", j.data.tender.status, "award:", j.data.award ? j.data.award.status : "none")'
curl -s -b "$COOKIE" -X GET "$BASE/api/reports/reconciliation?fyId=3" | J 'console.log("reconciliation rows:", j.data.length)'
curl -s -b "$COOKIE" -X GET "$BASE/api/dashboard?fyId=3" | J 'console.log("dashboard tenders:", j.data.tenders.total, "paid:", j.data.payments.paidMinor)'
echo "ALL OK"
