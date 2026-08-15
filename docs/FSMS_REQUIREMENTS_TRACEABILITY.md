# FuelFlow FSMS requirements traceability matrix

Status: normalized baseline for backlog, implementation, automated tests, and UAT
Source: Functional Requirements Document (FRD), version 1.0 Draft, 26 July 2026
Companion plan: `FSMS_IMPLEMENTATION_PLAN.md`

## 1. How to use this matrix

Each requirement must eventually carry:

- Backlog story/epic IDs.
- Design and API contract links.
- Migration/schema references.
- Automated test IDs.
- UAT evidence and acceptance owner.
- Final status: Planned, Implemented, Verified, Accepted, or Waived.

The `Planned proof` column defines the minimum evidence. It does not replace lower-level tests.

Source audit:

- 77 identifiers are printed in the FRD.
- 76 printed identifiers contain requirement text.
- `FR-DR-08` is printed without text.
- `FR-DR-04` is absent.
- This matrix retains the blank ID and adds the missing ID as a fixed plan-defined requirement.
- Flow-derived and non-functional requirements are listed separately so they cannot disappear during implementation.

### 1.1 FRD role baseline

Role behavior is not taken from the HTML samples.

| Role/capability profile | FRD source behavior |
|---|---|
| Administrator | Full system access and configuration; user/access administration and controlled administrative actions |
| Owner | Explicit FRD portfolio dashboard visibility across authorized stations and PO approval |
| Station Manager | Procurement, receiving, tanks, pumps, staff scheduling, and reconciliation for the assigned station |
| Cashier / Pump Attendant | Records sales, works the assigned pump, starts/ends assigned shifts, and submits readings/counts |
| Accountant | Reconciliation, reporting, and financial visibility with limited edit rights |
| Auditor | Read-only access to authorized modules and audit evidence |

`Station Controller`, `Shift Supervisor`, and `Group Director` are sample UI labels only and are not implemented as roles.

## 2. User Management

| ID | Normalized requirement / acceptance outcome | Planned implementation | Planned proof | Status |
|---|---|---|---|---|
| FR-UM-01 | Admin can create, edit, deactivate, and remove eligible accounts. Referenced accounts retain attribution. | User lifecycle service, soft delete, admin UI, policies | T-UM-01 API lifecycle; UAT create/edit/deactivate; assert referenced user cannot hard-delete | Planned |
| FR-UM-02 | System supports Administrator, Owner capability from FR-DB-06/PO flow, Station Manager, Cashier/Pump Attendant, Accountant, Auditor, and configurable permissions. | FRD role templates, role/permission tables, scoped overrides, Accountant limits from IMP-028 | T-UM-02 role/action/station matrix, including Owner and Accountant boundaries | Planned |
| FR-UM-03 | Login requires secure username/password authentication. | Sanctum sessions, Argon2id, rate limit, secure cookies, CSRF | T-UM-03 login success/failure/rate-limit/CSRF/session fixation | Planned |
| FR-UM-04 | Login, logout, and significant actions record actor, timestamp, and action. | Authentication/audit events and correlation IDs | T-UM-04 audit event completeness and immutability | Planned |
| FR-UM-05 | Admin can reset passwords and activate/deactivate accounts. | Reset flow, first-login reset, revoke sessions | T-UM-05 reset/activate/deactivate; deactivated session rejected | Planned |
| FR-UM-06 | Users can be assigned to one or more permitted stations; data is station-scoped. | User station assignment and scoped policies/queries | T-UM-06 cross-station and cross-organization IDOR suite | Planned |
| FR-UM-07 | Inactive sessions use a configurable timeout with a 24-hour default; sensitive actions re-authenticate after 30 minutes. | Organization/role/device timeout and re-authentication policy | T-UM-07 clock-controlled inactivity, shift close, and sensitive-action re-authentication | Planned |
| FR-UM-08 | Shift-sensitive actions are limited to the assigned attendant; Station Manager/Administrator override requires a reason. | Shift assignment policy and audited override | T-UM-08 own/other shift start/close, override, and crafted API tests | Planned |

## 3. Supplier Management

| ID | Normalized requirement / acceptance outcome | Planned implementation | Planned proof | Status |
|---|---|---|---|---|
| FR-SM-01 | Authorized users manage supplier identity, contact, address, registration, and protected bank data. | Supplier aggregate, field authorization, encrypted/protected response | T-SM-01 CRUD, validation, field permission, audit | Planned |
| FR-SM-02 | Supplier product pricing is stored by product and effective period. | Supplier product price terms | T-SM-02 price lookup and history | Planned |
| FR-SM-03 | Supplier payment terms are recorded. | Typed payment terms and notes | T-SM-03 terms validation and PO snapshot | Planned |
| FR-SM-04 | Supplier profile shows past orders and deliveries. | Cross-module read query | T-SM-04 ordered/received history with partial receipts | Planned |
| FR-SM-05 | Suppliers can be rated/flagged for timeliness, quality, and quantity accuracy. | Performance events and fixed explainable score from IMP-024 | T-SM-05 component math, rolling window, and exactly-once receipt update | Planned |
| FR-SM-06 | Deactivated suppliers cannot be selected for a new PO. | Active supplier query plus server validation | T-SM-06 UI exclusion and forged request rejection | Planned |
| FR-SM-07 | Contracts and licenses can be securely attached to a supplier. | Attachment service, object storage, signed access, malware scan | T-SM-07 upload/type/size/permission/download/retention | Planned |

## 4. Procurement

| ID | Normalized requirement / acceptance outcome | Planned implementation | Planned proof | Status |
|---|---|---|---|---|
| FR-PR-01 | Authorized user creates a PO with supplier, product, quantity, agreed price, and expected date. | PO aggregate, lines, form wizard | T-PR-01 valid/invalid PO creation and audit | Planned |
| FR-PR-02 | Supplier and current supplier-product price are suggested automatically. | Effective supplier price resolver; immutable PO snapshot | T-PR-02 effective price lookup and snapshot stability | Planned |
| FR-PR-03 | Every PO requires approval by an Administrator or Owner-capability user who is not the creator. | Fixed separation-of-duties approval rule and inbox | T-PR-03 unauthorized/self/authorized/rejected/concurrent approvals | Planned |
| FR-PR-04 | Every PO has a unique human-readable tracking number. | Transactional sequence per organization/station | T-PR-04 concurrent number generation uniqueness | Planned |
| FR-PR-05 | Editing/cancellation is restricted after dispatch; later commercial change uses an audited re-approved amendment. | PO state machine and revisions | T-PR-05 transition matrix and amendment history | Planned |
| FR-PR-06 | PO status is tracked through Draft, Pending Approval, Approved, Sent, Partially Received, Received, Closed, Rejected, and Cancelled. | Explicit state enum and transition service | T-PR-06 every allowed/disallowed transition | Planned |
| FR-PR-07 | System warns and blocks submission when planned delivery exceeds available safe tank capacity. | Capacity reservation/forecast service | T-PR-07 current/inbound/capacity boundary and concurrency | Planned |
| FR-PR-08 | Relevant staff are notified when delivery is due/overdue. | Scheduled job, notification deduplication and preferences | T-PR-08 due/overdue clock test and idempotent delivery log | Planned |

## 5. Fuel Receiving

| ID | Normalized requirement / acceptance outcome | Planned implementation | Planned proof | Status |
|---|---|---|---|---|
| FR-FR-01 | User selects an eligible open PO and records actual delivered quantity per product. | Receiving aggregate and PO remaining-quantity query | T-FR-01 eligible/ineligible PO and partial/full receipt | Planned |
| FR-FR-02 | Delivery records truck, driver, waybill/invoice, arrival, and departure. | Delivery header and validation | T-FR-02 required fields and chronological times | Planned |
| FR-FR-03 | Pre- and post-offload dip readings are mandatory. | Delivery dip readings linked to tank | T-FR-03 missing/invalid/order reading cases | Planned |
| FR-FR-04 | Ordered, invoiced/waybill, and dip-derived quantities are compared and flagged. | Exact variance calculator | T-FR-04 golden calculations, rounding, tolerance boundary | Planned |
| FR-FR-05 | Wrong product is blocked/flagged against PO and target tank. | Product and tank compatibility validation | T-FR-05 mismatch and forged-confirm request | Planned |
| FR-FR-06 | Confirmed receipt increases the correct tank stock exactly once. | Atomic stock ledger movement and cached balance | T-FR-06 transaction rollback, retry, concurrent confirmation | Planned |
| FR-FR-07 | Waybill and quality certificate can be attached securely. | Attachment service and delivery document type | T-FR-07 upload/security/retrieval | Planned |
| FR-FR-08 | Variance above the effective tolerance requires Station Manager sign-off. | Variance case, fixed default/configured stricter tolerance, reason/comment, sign-off | T-FR-08 below/equal/above 0.50% boundary and role tests | Planned |

## 6. Fuel Product Management

| ID | Normalized requirement / acceptance outcome | Planned implementation | Planned proof | Status |
|---|---|---|---|---|
| FR-PM-01 | Authorized user manages uniquely coded, named, measured, active/inactive products. | Product aggregate and catalog UI | T-PM-01 uniqueness, CRUD, deactivate, audit | Planned |
| FR-PM-02 | Current selling price has an effective date/time. | Effective-dated station/default price schedule | T-PM-02 before/at/after effective instant | Planned |
| FR-PM-03 | Full price change history records old/new, actor, time, and reason. | Append-only price change event | T-PM-03 immutable history and reason requirement | Planned |
| FR-PM-04 | A station may override the organization product price when configured. | Price inheritance and station override | T-PM-04 default/override/permission resolution | Planned |
| FR-PM-05 | Product with transaction history cannot be deleted; it can be deactivated. | FK protection and deactivation | T-PM-05 referenced delete failure and historical query | Planned |
| FR-PM-06 | Product links to compatible tank type/grade. | Compatibility rules | T-PM-06 valid/invalid tank assignment and receipt | Planned |

## 7. Tank Management

| ID | Normalized requirement / acceptance outcome | Planned implementation | Planned proof | Status |
|---|---|---|---|---|
| FR-TM-01 | Tank has unique ID, capacity, product, and safe min/max. | Tank aggregate and constraints | T-TM-01 uniqueness, threshold/capacity/product validation | Planned |
| FR-TM-02 | Running book stock is increased by receipts and reduced by sales. | Immutable stock ledger plus transactional balance cache | T-TM-02 full litre journey and recomputed ledger equality | Planned |
| FR-TM-03 | Manual dip is compared with book stock to calculate gain/loss. | Tank reading and variance service | T-TM-03 exact positive/negative/zero variance | Planned |
| FR-TM-04 | Low-stock alert triggers below configured minimum. | Threshold evaluator and deduplicated alert | T-TM-04 crossing/equal/recovery/re-crossing | Planned |
| FR-TM-05 | Planned delivery raises overfill warning when it exceeds capacity/safe max. | Shared capacity service | T-TM-05 PO and receiving boundary/concurrency tests | Planned |
| FR-TM-06 | System supports ATG integration and real-time readings. | REST/JSON, MQTT JSON and CSV mapping adapters, device registry, ingestion API, simulator, quarantine | T-TM-06 auth/signature/order/range/stale/quarantine and pilot hardware certification | Planned |
| FR-TM-07 | Every stock movement records type, quantity, source, timestamp, and actor/device. | Append-only stock ledger | T-TM-07 source attribution and mutation denial | Planned |
| FR-TM-08 | Compatible tank-to-tank transfer is atomic and fully audited. | Balanced transfer service | T-TM-08 two-sided commit/rollback/compatibility/idempotency | Planned |

## 8. Pump Management

| ID | Normalized requirement / acceptance outcome | Planned implementation | Planned proof | Status |
|---|---|---|---|---|
| FR-PM2-01 | Pump has unique ID and nozzles linked to the correct tank/product. | Pump/nozzle aggregate and compatibility check | T-PM2-01 uniqueness and valid/invalid topology | Planned |
| FR-PM2-02 | Opening and closing meter readings are stored per nozzle/shift. | Shift meter readings | T-PM2-02 assignment, required readings, monotonic/rollover | Planned |
| FR-PM2-03 | Expected nozzle volume equals closing minus opening, with one rollover only when a meter maximum is configured. | Meter volume calculator using IMP-027 | T-PM2-03 golden values, invalid decrease, one/multiple rollover boundary | Planned |
| FR-PM2-04 | Meter-derived volume is compared to recorded sales and discrepancy is flagged. | Pump reconciliation/variance case | T-PM2-04 match/tolerance/excess/missing sale | Planned |
| FR-PM2-05 | Under-maintenance/out-of-service pump or nozzle cannot be used for sales. | Service status policy | T-PM2-05 UI state and forged sale rejection | Planned |
| FR-PM2-06 | Pump maintenance/service history is retained. | Append-only maintenance events | T-PM2-06 create/close/history/audit | Planned |
| FR-PM2-07 | Attendant can be assigned to a pump for a shift. | Shift assignment with overlap constraints | T-PM2-07 valid assignment, collision, permission | Planned |

## 9. Sale Management

| ID | Normalized requirement / acceptance outcome | Planned implementation | Planned proof | Status |
|---|---|---|---|---|
| FR-SL-01 | Sale records pump/nozzle, product, litres, GHS effective price, and GHS amount rounded half-up to two decimals. | Atomic sale command, price snapshot, sale/line records | T-SL-01 GHS price/rounding/nozzle/product/idempotency/concurrency | Planned |

The following are binding implementation requirements derived from FRD Section 9.2, Section 9.4, module links, Dashboard `FR-DB-08`, and the offline note.

| ID | Derived requirement / acceptance outcome | Planned implementation | Planned proof | Status |
|---|---|---|---|---|
| DR-SL-02 | Sale records one cash, card, mobile money, credit, or fleet payment in GHS; no split payment. | Sale payment row and recording-only method policy | T-DSL-02 each method, GHS amount equality, reference, unsupported/split method | Planned |
| DR-SL-03 | Credit sale checks active account and available credit; only Administrator may override with a reason. | Customer/credit account ledger, aging buckets, row lock, override audit | T-DSL-03 limit boundary, concurrent sales, aging, override audit | Planned |
| DR-SL-04 | Confirmed sale generates a stable GHS business receipt with all IMP-018 fields. | Receipt number/payload and reprint audit | T-DSL-04 uniqueness, required fields, reproducibility, reprint | Planned |
| DR-SL-05 | Confirmed sale reduces the correct tank stock exactly once. | Sale stock movement in one DB transaction | T-DSL-05 retry/rollback/concurrent sale ledger equality | Planned |
| DR-SL-06 | Confirmed sale contributes to the assigned shift/pump/attendant totals. | Shift read query/materialized summary | T-DSL-06 correct attribution and closed-shift rejection | Planned |
| DR-SL-07 | Correction uses audited reversal/replacement; confirmed history is not silently edited. | Reversal commands and linked movements/payments | T-DSL-07 full reversal and locked-period rules | Planned |
| DR-SL-08 | Up to four hours offline, a Cashier/Pump Attendant may queue cash sales up to GHS 10,000 each and sync exactly once with visible conflicts. | PWA IndexedDB queue, device sequence, batch idempotency, offline limits | T-DSL-08 limits/disconnect/retry/duplicate/reorder/stale price/device revoke | Planned |

## 10. Daily Reconciliation

| ID | Normalized requirement / acceptance outcome | Planned implementation | Planned proof | Status |
|---|---|---|---|---|
| FR-DR-01 | Per station/day compile opening stock, receipts, closing book/dip stock, and sales volume. | Reconciliation source snapshot | T-DR-01 full stock equation and cutoff | Planned |
| FR-DR-02 | Compile GHS sales value and compare expected cash/payment totals with till counts. | Payment and till reconciliation | T-DR-02 cash/non-cash/single-method/GHS rounding cases | Planned |
| FR-DR-03 | Calculate book-versus-dip tank variance per product/tank. | Tank reconciliation lines | T-DR-03 exact calculation and product aggregation | Planned |
| FR-DR-04 | Plan-defined missing text: calculate meter-derived versus recorded-sale pump/nozzle variance. | Pump reconciliation lines | T-DR-04 per nozzle/shift/day and 0.50% default tolerance | Planned; defined by IMP-001 |
| FR-DR-05 | Calculate expected-versus-counted GHS cash variance per attendant/shift, with a default zero tolerance. | Cash reconciliation lines | T-DR-05 shortage/overage/zero, GHS rounding, and attribution | Planned |
| FR-DR-06 | Station Manager review/sign-off is required before Reconciled/locked. | Readiness checks, policy, sign-off event, 30-minute sensitive-action re-authentication | T-DR-06 role/readiness/concurrency/re-authentication | Planned |
| FR-DR-07 | Reconciled period blocks sale, receiving, and tank edits; admin may reopen with logged reason. | Policy plus DB lock enforcement and versioned reopening | T-DR-07 UI/API/job/import mutation denial and reopen history | Planned |
| FR-DR-08 | Plan-defined blank text: out-of-tolerance variance needs reason, comment, assigned resolver, and resolution before sign-off. | Unified variance case workflow | T-DR-08 blocking/non-blocking case and resolution audit | Planned; defined by IMP-002 |

## 11. Reporting

| ID | Normalized requirement / acceptance outcome | Planned implementation | Planned proof | Status |
|---|---|---|---|---|
| FR-RP-01 | Daily Sales Report supports product, pump, attendant, and payment breakdowns. | Sales report query and export | T-RP-01 source/output golden totals | Planned |
| FR-RP-02 | Stock Movement Report shows opening, receipts, sales, closing, and variance per tank/product. | Ledger/reconciliation report query | T-RP-02 equation and drill-to-source | Planned |
| FR-RP-03 | Procurement/Purchase History Report supports supplier, product, and cost trend. | PO/receipt report query | T-RP-03 partial/cancelled/date/cost cases | Planned |
| FR-RP-04 | Reconciliation Summary shows variances and sign-off by day. | Reconciliation report query | T-RP-04 current/version/reopened/locked cases | Planned |
| FR-RP-05 | Supplier Performance Report shows on-time percentage and variance history. | Performance report query | T-RP-05 score denominator and source events | Planned |
| FR-RP-06 | User Activity/Audit Report uses immutable audit events. | Audit report query with field redaction | T-RP-06 completeness, permission, redaction | Planned |
| FR-RP-07 | Reports filter by date range, station, product, and user as applicable. | Shared validated filter objects | T-RP-07 combinations, time zone boundaries, unauthorized station | Planned |
| FR-RP-08 | Reports export to PDF and Excel with GHS labels and values. | Queued snapshot export | T-RP-08 screen/PDF/XLSX GHS totals and metadata match | Planned |
| FR-RP-09 | Recurring reports can be emailed to configured recipients. | Scheduler, queue, signed links/attachments, delivery log | T-RP-09 time zone/idempotency/failure/retry/permission | Planned |

## 12. Dashboard

| ID | Normalized requirement / acceptance outcome | Planned implementation | Planned proof | Status |
|---|---|---|---|---|
| FR-DB-01 | Today's sales volume and value by product update in real time. | Station/portfolio sales read model and private event | T-DB-01 source equality, event update, reconnect/poll fallback | Planned |
| FR-DB-02 | Current tank stock shows accessible low/critical indicators. | Tank read model and threshold status | T-DB-02 threshold boundary, stale reading, text/icon cue | Planned |
| FR-DB-03 | Pending POs and expected deliveries are visible. | Procurement/receiving dashboard query | T-DB-03 status/date/station accuracy and drill-down | Planned |
| FR-DB-04 | Current/latest reconciliation status shows Pending, In Review, Reconciled, or Flagged. | Reconciliation status projection | T-DB-04 every state, latest business date, reopen | Planned |
| FR-DB-05 | Highest-priority tank, pump, and cash variances are visible. | Variance priority query | T-DB-05 severity/order/scope/source drill-down | Planned |
| FR-DB-06 | Dashboard is role/station scoped; FRD Owner sees the permitted portfolio and Station Manager sees the assigned station. | Separate Owner, Station Manager, and Cashier/Pump Attendant queries and policies | T-DB-06 role/station/private-channel isolation | Planned |
| FR-DB-07 | Each widget drills to the corresponding filtered detail/report. | Typed deep-link definitions | T-DB-07 navigation and exact filter/entity preservation | Planned |
| FR-DB-08 | Supplier delivery performance and outstanding GHS credit balances with fixed aging buckets appear as summaries. | Supplier and credit read models | T-DB-08 source equality, aging/limit definition, GHS, scope | Planned |

## 13. Cross-cutting requirements from FRD flows and notes

| ID | Derived requirement / acceptance outcome | Planned implementation | Planned proof | Status |
|---|---|---|---|---|
| NFR-DI-01 | Locked history is not silently editable. | Immutable ledger/events, reconciliation lock, reversals | T-NFR-DI-01 mutation and direct DB permission tests | Planned |
| NFR-OF-01 | Sale recording survives short network outages and syncs after reconnect. | Limited offline PWA and idempotent batch sync | T-DSL-08 plus pilot network chaos | Planned |
| NFR-MS-01 | Every module supports organization/station scope and filter. | Mandatory tenant/station keys and scoped query services | T-NFR-MS-01 resource-by-resource isolation matrix | Planned |
| NFR-AU-01 | Every financial or stock-affecting action is attributable to actor/device and timestamp. | Audit event plus ledger/source attribution | T-NFR-AU-01 significant-action coverage query | Planned |
| DR-UM-09 | New user receives one-time credentials and must set a new password; Cashier/Pump Attendant also sets a terminal PIN. | First-login state, one-time credential delivery, separately hashed terminal PIN | T-DR-UM-09 expired/reused credential, first-login change, PIN policy | Planned |
| DR-PR-09 | A low-stock alert can open a prefilled new PO without bypassing normal capacity and approval checks. | Alert-to-PO command/UI deep link | T-DR-PR-09 correct station/product/tank prefill and standard approval | Planned |
| DR-TM-09 | A reconciled closing book stock becomes the next business period's opening book stock without duplicating movement. | Period opening snapshot derived from signed close | T-DR-TM-09 consecutive-day carry-forward and reopen recalculation | Planned |
| DR-PM2-08 | A shift closes only after required readings/variance rules pass and then becomes reconciliation input. | Shift close readiness and source snapshot | T-DR-PM2-08 missing reading, unresolved variance, successful handoff | Planned |
| DR-RP-10 | Official financial/stock reports use reconciled data; live operational reports are visibly labeled. | Report source policy and status metadata | T-DR-RP-10 live/reconciled source selection and label | Planned |
| DR-E2E-01 | Confirmed receipt updates tank, PO, supplier performance, reporting source, and dashboard without partial effects. | Transaction plus outbox | T-E2E-01 receipt failure injection and exactly-once replay | Planned |
| DR-E2E-02 | Confirmed sale updates tank, shift, reconciliation source, reports, and dashboard without partial effects. | Transaction plus outbox | T-E2E-02 sale failure injection and exactly-once replay | Planned |
| DR-E2E-03 | Reconciled figures become official report data and dashboard status. | Versioned reconciliation projection | T-E2E-03 source/report/dashboard golden day | Planned |

## 14. Visual reference component mapping

The sample titles and navigation labels do not define roles or scope. Only their visual patterns are reused.

| Reference design element | Requirement mapping | Production treatment |
|---|---|---|
| Owner sample KPI cards | FR-DB-01, FR-DB-04, FR-DB-06, FR-RP-01/04 | FRD Owner portfolio KPIs in GHS with period selector and report drill-down |
| Owner sample station chart | FR-DB-06, FR-RP-01/07 | Accessible chart plus underlying table and station drill-down |
| Owner sample stock/priority layout | FR-DB-02/05, FR-TM-04 | Aggregate capacity, stale-data cue, actionable alerts |
| Owner sample activity feed | FR-UM-04, FR-RP-06 | Audit-derived feed with permission-aware detail |
| Station sample revenue/chart layout | FR-DB-01 | Station Manager live GHS KPI/chart |
| Station sample tank layout | FR-DB-02, FR-TM-02/04 | Live/manual source, capacity, threshold, last update |
| Station sample variance table | FR-DB-05, FR-DR-03/04/05/08 | Unified variance cases with direct resolution route |
| Station sample supplier cards | FR-DB-08, FR-SM-05 | Explainable performance metric and source history |
| Station sample deliveries list | FR-DB-03, FR-PR-08 | Filterable due/in-transit list and PO/delivery drill-down |
| Supervisor sample shift KPI layout | DR-SL-06, FR-PM2-03 | Station Manager oversight and Cashier/Pump Attendant shift totals; no Shift Supervisor role |
| Supervisor sample terminal layout | FR-UM-08, FR-DR-02/05 | Station Manager till oversight and assigned attendant state |
| Supervisor sample incident cards | FR-PM2-05/06 and operational flow | Typed incident, assigned resolver, status, maintenance link |
| Supervisor sample transaction table | FR-SL-01, DR-SL-02/04 | Station Manager transaction view and attendant recent receipts |
| Sample "Pause All Pumps" action | No FRD requirement | Visual reference only; do not implement this action |
| Sample close action | FR-UM-08, FR-PM2-02/04, FR-DR-02/05 | Cashier/Pump Attendant readiness checklist, counted GHS cash, readings, variance gate |

## 15. Acceptance ownership

| Area | Required acceptance owner |
|---|---|
| Identity, role, session, audit | Product Owner plus Security Owner |
| Supplier, PO, receiving | Procurement Lead plus Station Operations |
| Product, prices, money, credit | Finance Owner plus Product Owner |
| Tank, pump, meter, ATG | Station Operations plus Hardware/Integration Owner |
| Sales, shift, offline | Station Operations plus Finance and QA |
| Reconciliation and locking | Finance Owner plus Station Manager and Auditor |
| Reports and dashboards | Product Owner plus Finance/Operations by report |
| Backup, security, performance, rollout | Engineering Lead, Security, DevOps/SRE, QA |

No requirement reaches `Accepted` without its designated owner approving evidence in the target UAT environment.
