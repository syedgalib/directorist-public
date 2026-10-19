# PRD 10 — Order Management

Status: initial implementation is available for review; the acceptance criteria remain normative release requirements. See [verification](../../docs/mcp/verification.md). Related: [overview](README.md), [shared safety](06-access-control-and-write-safety-prd.md), [tools T32–T44](07-mcp-tool-catalog.md), [extension contract](11-extension-extensibility-prd.md), [acceptance matrix](08-acceptance-test-matrix.md).

## Product outcome

Authorized staff can find and maintain Directorist orders, record eligible offline payments, and manage supported refunds through an assistant. Customers can inspect their own purchase history and cancel an eligible pending order. Order ownership means the purchaser recorded on the order; owning a linked listing does not grant access to someone else's purchase.

This addition brings order operations into scope. Existing generic listing/settings tools remain unable to change paid entitlements or payment credentials. Legitimate entitlement changes happen only as a consequence of the native order/payment/refund workflow.

## Existing behavior and prerequisites

The inspected implementation has legacy order representations and a current repository/DTO architecture backed by Directorist order, payment and refund models. The admin order/refund controllers use manage_options. Customer order queries filter by the current user; customer cancellation checks purchaser ownership and pending status. The refund repository records refunds and fires hooks; this alone does **not** prove a gateway returned money. See the [source assessment](09-reference-and-feasibility.md#order-and-extension-scope-addition).

Prerequisites are enabled/ready MCP, live WordPress authentication, an available supported order service/storage version, and the action's effective native permission. Creation and payment actions also require applicable monetization, item, listing, gateway and extension services. Historical reads must continue when monetization is switched off if the storage remains readable.

The implementation must identify one authoritative service adapter per order. Publish supported storage versions and deterministic legacy-to-current ID/status mappings. An ID cannot resolve to different records depending on the route or caller; reject ambiguous/unsupported records before mutation. No migration, parallel ledger, automatic extension installation or hard-coded assumption that every order is a WordPress post is permitted.

## Scope and behavior

| Area | Required behavior |
|---|---|
| Discovery | Caller-specific read/write schema, supported item types, filters, status actions, monetary precision, provider capabilities and restrictions |
| Search/detail | Paginated orders, status/date/item/listing/customer filters where authorized, stable ordering, safe totals and relationship projections |
| Create | Staff-created unpaid order using validated existing customer/item/listing references and native price/tax/coupon calculation; no automatic charge |
| Edit | Explicit supported fields on eligible unpaid orders; retain omitted values; recalculate affected totals and preview differences |
| Status/cancel | State-dependent native actions; customer cancellation limited to their own pending order; no arbitrary paid/refunded flag |
| Payments | Bounded payment history and explicit recording of an already received eligible offline payment through the native service |
| Refunds | Bounded refund history; supported partial/full refund with explicit record-only or gateway mode and accurate settlement state |
| Bulk | 1–50 explicit updates or cancellations with independent outcomes; no bulk payment, refund, creation or financial-status shortcut |
| Reconciliation | Read a durable operation outcome without repeating its financial side effects |

Permanent order/payment/refund deletion, editing settled amounts or ledger history, customer reassignment, existing-order listing transfer, automatic charging/capture, card entry, payment tokens/secrets, arbitrary transaction IDs, subscription lifecycle changes and overriding plan quotas are excluded. Internal notes, receipt resends and exports are not included unless separately registered and specified as extension tools.

### Permissions and field visibility

| Actor/operation | Required permission and boundary |
|---|---|
| Staff order schema, all-order search/detail, create/edit/status/payment/refund | Native order administration gate, currently manage_options, plus action/object/provider checks. Do not invent a default role grant. |
| Customer own-order schema/history/detail/payment/refund summary | Native customer-order eligibility, authenticated purchaser identity, object ownership and customer-visible field projection |
| Customer cancellation | Own pending order and the native cancellation policy; never cancellation based only on linked-listing ownership |
| Custom role | Same effective native permissions as any other caller; role name does not authorize anything |
| Extension-owned order/item/payment | Core permission plus provider/object/field rules; an extension cannot weaken core administration or purchaser checks |
| Bulk or replay | Recheck the relevant checks for each target and current output visibility |

Customer-only MCP admission is a narrow native own-order permission, including an empty history. It must not grant listing, taxonomy, settings, builder, media-library or other customers' order access. The implementation provides this narrow admission path; provider/client and full release-matrix coverage remain verification gates.

Responses expose selected order IDs, safe item labels, customer identity only where permitted, authoritative status, currency, subtotal/discount/tax/total, timestamps, permitted payment/refund summaries, allowed actions and versions. A linked listing, customer or plan is independently permission-checked; an inaccessible related object is omitted or represented by a safe restricted relationship, never expanded. Customer output excludes staff notes, other customers' personal data, full gateway payloads and secret transaction/authorization details. Search predicates, totals and monetary aggregates apply authorization before pagination. Never combine currencies into one monetary total.

## User stories and acceptance criteria

### ORD-01 — Discover available order operations

As an authorized staff member or customer, I want an order schema for my permissions so that I can request supported operations with the correct inputs.

Access: native administration or own-order permission. Success returns an action-specific schema; unavailable providers fail safely or appear unavailable only to an authorized administrator.

- **ORD-01-AC1:** Given a connected eligible user, when requesting the order schema, then it identifies read scope, supported fields/filters/item types, allowed actions, permission requirements, currency precision and schema version.
- **ORD-01-AC2:** Given a customer or a user without order-administration permission, when discovering tools and schemas, then no staff mutation capability or protected customer/plan/reference data is exposed.
- **ORD-01-AC3:** Given unsupported storage, a missing item provider or a disabled gateway, when inspecting an affected operation, then it is unavailable with safe recovery guidance while unrelated supported reads continue.

### ORD-02 — Find and inspect authorized orders

As a customer or order administrator, I want to search and inspect eligible orders so that I can understand purchases, payment state and remaining actions.

Access: purchaser scope or native administration. A protected ID must look absent to an unauthorized caller.

- **ORD-02-AC1:** Given customer A and orders belonging to A and B, when A searches or requests B's ID, then list totals include only A's permitted orders and the protected detail returns not_found without revealing existence.
- **ORD-02-AC2:** Given permitted filters and paging, when searching, then results default to 20, reject more than 100, sort deterministically and distinguish order, payment and refund status.
- **ORD-02-AC3:** Given an authorized order with related payments/refunds and a restricted listing/customer/plan, when reading details or bounded relationship histories, then only permitted fields and independently authorized relationships are returned.
- **ORD-02-AC4:** Given monetization disabled after an earlier purchase, when the purchaser reads historical orders, then readable history remains available and disallowed new purchase/payment actions remain unavailable.

### ORD-03 — Create a validated unpaid order

As an order administrator, I want to create an unpaid order from supported existing references so that I can manage an authorized manual sale without inventing financial values.

Access: administration plus native create/item policy; preview required. Customers continue to use the native checkout for purchasing.

- **ORD-03-AC1:** Given a valid existing customer, supported item and eligible listing, when previewing creation, then native calculation returns the authoritative price, discount, tax, total, currency, initial unpaid status and expected fulfillment effects without creating an order or charge.
- **ORD-03-AC2:** Given confirmation of that unchanged preview, when executing with a new idempotency key, then one unpaid order is created through the native service with validated references and its actual ID/status/version returned.
- **ORD-03-AC3:** Given an invalid customer, unavailable item, cross-customer listing, forbidden coupon, unsupported price override or settled initial status, when validating, then creation fails before any order, payment or entitlement is persisted.
- **ORD-03-AC4:** Given a repeated request after a timeout, when retrying its exact key and payload, then the original order identity is recovered without a duplicate order, charge or fulfillment event.

### ORD-04 — Edit supported unpaid-order fields

As an order administrator, I want to change selected eligible order fields so that I can correct an unpaid order while preserving its other information.

Access: administration, object and field permissions; preview required. Settled financial history is immutable.

- **ORD-04-AC1:** Given an unpaid editable order, when previewing explicit supported changes, then the schema validates them as one unit, recalculates dependent totals and displays before/after amounts, currency, references and fulfillment implications.
- **ORD-04-AC2:** Given a valid confirmation and current version, when saving, then only the reviewed supported fields and native derived values change; omitted core/extension data and unrelated orders remain intact.
- **ORD-04-AC3:** Given a paid/refunded order, customer reassignment, raw metadata, direct entitlement, currency switch on an existing financial record or ledger edit, when requested, then the operation fails without partial persistence.
- **ORD-04-AC4:** Given a price, tax, coupon, provider schema or order change after preview, when execution begins, then the stale action is rejected and fresh state/preview is required.

### ORD-05 — Apply eligible transitions and cancellations

As an order administrator or purchaser, I want to perform allowed lifecycle actions so that an order's state follows its actual business workflow.

Access: administration for staff actions; purchaser plus native pending-cancellation policy for self-service; preview required.

- **ORD-05-AC1:** Given an order and caller, when discovering allowed actions, then only state- and permission-eligible transitions are advertised; a status enum is not treated as permission to choose every status.
- **ORD-05-AC2:** Given a purchaser's pending order and confirmed cancellation, when executed, then native cancellation returns the actual status, updates dependent reservations as native policy requires and does not charge or refund money.
- **ORD-05-AC3:** Given another purchaser's order, an ineligible status or a payment settling concurrently, when cancellation executes, then ownership/state are rechecked under concurrency protection and the invalid cancellation makes no financial change.
- **ORD-05-AC4:** Given an attempt to set paid/refunded directly, when invoking generic edit or status tools, then it is rejected; only the verified native payment/refund workflow may produce those states and related entitlement effects.

### ORD-06 — Record an eligible received offline payment

As an order administrator, I want to record a payment already received through a supported offline method so that the order and its native fulfillment reflect the receipt.

Access: administration plus explicit native offline-recording permission and provider eligibility; preview required. This records receipt and never initiates an online charge.

- **ORD-06-AC1:** Given an eligible unpaid order, offline method and non-secret receipt/evidence reference, when previewing payment recording, then the due amount/currency and expected order, listing/plan and notification effects are shown without recording payment.
- **ORD-06-AC2:** Given a confirmed current preview and verified native receipt rules, when executed, then one payment is recorded and the order/entitlements change only through the native fulfillment service; the result states that this was offline recording.
- **ORD-06-AC3:** Given a gateway requiring provider confirmation, arbitrary paid flag, mismatched amount/currency, duplicate receipt or missing authority, when recording is requested, then it is rejected without creating a false settled payment.
- **ORD-06-AC4:** Given a retry, concurrent receipt or failure during fulfillment, when processing, then a durable operation/payment reference prevents duplicate receipt and fulfillment; the result identifies committed, pending or unknown effects.

### ORD-07 — Inspect and create supported refunds

As an order administrator, I want accurate refund history and controlled partial/full refunds so that the ledger and any returned money remain consistent.

Access: administration plus native refund/provider policy for writes; purchasers receive only authorized read summaries. Every refund requires preview and explicit mode.

- **ORD-07-AC1:** Given an authorized order, when listing refunds or previewing a refund, then the response distinguishes recorded, pending, settled, failed and uncertain states and reports the authorized remaining refundable amount in the order's currency.
- **ORD-07-AC2:** Given a positive exact amount within the current refundable balance, explicit record_only or gateway mode and required evidence/provider, when previewing, then mode, amount, currency, affected payment, native entitlement effects and warnings are shown with no ledger or provider write.
- **ORD-07-AC3:** Given a confirmed record_only refund allowed by native policy with evidence of an external/manual refund, when executed, then the native record is created and the result explicitly says no gateway refund was performed; a record alone cannot be reported as proof of money returned.
- **ORD-07-AC4:** Given a registered gateway refund adapter supporting idempotency and reconciliation, when an eligible confirmed refund executes, then one provider operation is initiated, local state reflects its authoritative settlement result, and unsupported gateways return unsupported_operation before dispatch.
- **ORD-07-AC5:** Given concurrent refunds, precision/amount errors, a nonrefundable payment or a timeout after provider acceptance, when processing or retrying, then over-refunding and blind resubmission are blocked; a durable reference supports read-based reconciliation without falsely reporting rollback of transferred funds.

### ORD-08 — Perform bounded administrative batches

As an order administrator, I want bounded bulk edits or cancellations so that I can maintain several orders with explicit review and individual outcomes.

Access: administration and all per-order checks; one action, at most 50 explicit items, preview required.

- **ORD-08-AC1:** Given 1–50 uniquely referenced eligible and invalid items, when previewing one update or cancel action, then each item receives a safe impact preview or error and no product state changes.
- **ORD-08-AC2:** Given confirmation of the complete batch, when executing, then unchanged eligible items are processed in request order under fresh permissions and each item reports committed, failed or indeterminate outcome.
- **ORD-08-AC3:** Given 51 items, duplicate references, nested/query-wide input, bulk payments/refunds/creation or a financial-status shortcut, when submitted, then the envelope is rejected before any item executes.
- **ORD-08-AC4:** Given timeout or partial completion, when retrying the exact batch key, then earlier commits/side effects are not repeated and per-item progress is recoverable.

### ORD-09 — Reconcile and audit order operations

As an authorized operator, I want durable, attributable outcomes so that I can resolve an interrupted order/payment/refund operation safely.

Access: administration or the initiating purchaser's permitted own-order cancellation; independent linked-order visibility checks apply.

- **ORD-09-AC1:** Given a dispatched operation, when reading its opaque operation ID, then the caller receives only an authorized outcome with local committed state, external effect state, correlation reference and a safe next step.
- **ORD-09-AC2:** Given a financial timeout or a lost provider response, when reconciling, then native/provider lookup uses the original reference; no new payment or refund is submitted by the read tool.
- **ORD-09-AC3:** Given success, rejection, replay or a partial batch, when events are emitted, then safe actor/target/action/correlation/outcome metadata is recorded without credentials, raw gateway payloads, full receipt evidence or customer contact data.
- **ORD-09-AC4:** Given a revoked capability, another user's operation ID or an expired ordinary replay record, when accessed, then current authorization is enforced and retained native financial references still prevent duplicate settlement.

## Financial consistency and error contract

- Monetary inputs/outputs use validated decimal strings with currency-specific precision; convert at the native adapter boundary without binary-floating-point comparisons or an assumption of two decimal places. Discover supported currencies/rounding; reject negative, overprecision, nonfinite and mixed-currency inputs.
- Every existing-order write requires expected_version covering the order, payment/refund balance, applicable item/price/tax/entitlement policy and provider contract. Every write in this PRD requires confirmation; a token is not proof that a human approved it.
- Generic status/edit tools cannot fabricate payment evidence, settle refunds, change transaction identifiers or directly grant listing/plan privileges. External notifications/webhooks can still settle native operations; reconcile competing native changes.
- A new order adapter must declare all participating custom order/payment/refund tables and native write paths. Extend shared transaction/locking coordination beyond the current core-table-only guard. Do not claim atomicity for tables or native writers that do not participate.
- Persist financial intent and a unique operation/provider reference before external dispatch. Avoid holding a database transaction across network calls. Provider acceptance, local recording and fulfillment may complete separately; expose their states rather than claiming one distributed rollback.
- Reserve unsettled refund amounts as well as counting confirmed refunds; otherwise parallel pending operations can exceed the refundable balance. Account for partial refunds, taxes and native rounding.
- The ordinary replay window remains 24 hours. Financial deduplication references and reconciliation records must survive that cache window and satisfy the native ledger/provider retention contract. Expiry cannot authorize a second settlement.
- get-order-operation is read-only and cannot reissue an external write, poll an arbitrary URL or return raw provider secrets. Financial provider reconciliation requiring a read request uses the registered provider and stored reference only.

## Success and failure scenarios

| Scenario | Expected result |
|---|---|
| Customer finds and cancels their pending purchase | Own results only; confirmed native cancellation; no money movement |
| Staff creates an unpaid order and records a verified offline receipt | Native totals, one receipt, attributable fulfillment and actual resulting status |
| Staff submits a supported partial gateway refund | Explicit financial preview, one provider reference, authoritative settlement/reconciliation state |
| Listing owner requests another customer's order | Uniform not_found, including histories, totals, bulk and operation lookup |
| Native order uses a missing extension or ambiguous legacy identity | No write; safe dependency or unsupported-operation guidance |
| Provider accepted refund but the response was lost | Unknown/pending external state; original reference retained; no automatic new-key retry |

## Release verification

Run [QA-043–QA-051](08-acceptance-test-matrix.md) with separate customers A/B, administrator, editor, custom roles and revoked capabilities. Include current and supported legacy storage, native UI round trips, native fulfillment hooks, multiple currencies/precision, paid/unpaid/partial-refund states, concurrent webhooks/cancellations/refunds and provider failure before/after dispatch.

Use isolated fixtures and a controlled provider sandbox/fake, never real charges or refunds. These are future release criteria. The focused order/extension suite covers the implemented native and fake-provider paths; real provider and full release-matrix certification remain outstanding.
