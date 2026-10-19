# Directorist MCP order management

The runtime catalog includes 13 order tools and the integration discovery tool, alongside the original 31 core tools. It remains disabled by default. Other Features → MCP → Configure uses registry-derived counts/groups; there is no separate order authentication flow.

## Permissions and identity

Staff management uses the native administration gate, currently manage_options, followed by object, action, field and provider checks. Role names are never an allowlist. An authenticated eligible purchaser can connect for their own history and pending cancellation, even without listing capabilities. This narrow access does not expose listings, configuration, term management, other purchasers or staff references.

The authoritative IDs are current directorist_orders table IDs. Pass order_id or legacy_id, never both. legacy_id resolves exactly one previously migrated native row; unmigrated or ambiguous legacy records are unsupported. MCP performs no migration and does not guess between post IDs and table IDs. Customer ownership comes from the purchase, independently of the linked listing's author. Related listing details are independently checked and may return a restricted relationship.

## Tools

Ability names use directorist/action; MCP names use directorist-action.

| Tool | Behavior |
|---|---|
| get-order-schema | Caller-specific scopes, filters, actions, typed item schemas, exact currency precision, offline methods, registered providers and versions |
| list-orders | Permission-filtered search, native status/customer/listing/item/date/currency/ID filters and stable ID ordering; default 20, maximum 100 |
| get-order | Safe financial summary, independently permitted relationships, native state, actions and current version |
| create-order | Staff creation of an unpaid native order from existing customer/item/listing references; no charge |
| update-order | Eligible unpaid item changes/recalculation; omitted native fields survive |
| change-order-status | Discovered nonfinancial transitions; cannot choose paid/refunded flags |
| cancel-order | Eligible native cancellation; purchasers limited to their own pending orders |
| record-order-payment | Exact already-received bank-transfer receipt and unique non-secret evidence; invokes native fulfillment |
| list-order-payments | Bounded safe history, omitting transaction/evidence secrets |
| list-order-refunds | Bounded safe history plus settled, reserved and remaining refundable amounts |
| create-order-refund | Positive partial/full amount, payment, reason, currency and explicit record_only or gateway mode |
| bulk-orders | One update or cancel action, 1–50 explicit items, stable client_ref and versions, individual results |
| get-order-operation | Authorized durable outcome lookup; optional provider read-based reconciliation, never new dispatch |

Use get-reference-data with kind order_references for bounded administrator-only customer choices. Item choices and schemas come from get-order-schema. Existing registered item providers own native item/plan validation and calculation; featured_listing is the core item adapter. Its item input is an existing listing_id belonging to the selected customer. The core update contract supports recalculate:true and item inputs for the same order/listing. Arbitrary customer transfers, ledger amounts, subscription lifecycle, currency switches, entitlements and settled-history edits are rejected.

## Preview and retry example

1. Read the order/schema. Preview with dry_run:true and the selected action inputs.
2. Present the returned amount, currency, mode, state and native fulfillment/notification effects.
3. Execute exactly those inputs with expected_version from changes.version, confirm_token and a fresh idempotency_key. Creation uses schema/source state instead of a target version.
4. Retry the identical execution payload/key to recover its result. A key with different inputs is rejected. Bulk adds unique client_ref per explicit item and returns independent outcomes.

Every order write requires confirmation. Preview expiry is five minutes. Current authentication, capabilities, ownership, registry lifecycle, native prices and ledger balance are rechecked. Native order/payment/refund SQL writes participate in the same advisory lock while MCP is enabled; custom provider stores must declare and cooperate with their native lock/transaction contract.

Monetization-off installations retain readable historical purchases. New purchases and new payment recording remain unavailable. Trashing/deleting order, payment or refund records is not an MCP operation.

## Financial boundaries

Amounts are decimal strings. Arithmetic for comparisons, balances and reservations uses bounded integer minor units on 64-bit PHP. Native calculations and DTO conversion are isolated at the service boundary. The inspected native tables/calculators have two decimal places; writes requiring greater precision or exceeding DECIMAL(10,2) are refused. Discovery identifies this constraint rather than silently rounding. Zero- and three-decimal currency arithmetic is separately tested; this does not certify three-decimal native ledger writes.

An offline receipt records money already received. It does not initiate a gateway charge. Evidence is required, hashed for duplicate prevention and excluded from event/history output. Native repositories and fulfillment hooks update order/listing entitlements and notifications.

record_only refunds require evidence of an external/manual reimbursement. They explicitly report that no gateway refund was performed. Gateway mode requires a registered compatible refund_providers adapter with live permission, validation, idempotent dispatch and reconciliation. No commercial gateway is automatically enabled by installation. The native refund repository alone is not proof of money returned.

Financial intent is stored before dispatch in non-autoloaded operational options. Provider calls happen outside a database transaction; native recording follows in its own declared transaction after settlement. Pending/unknown refund amounts reserve the available balance. An uncertain response becomes an MCP outcome_unknown error carrying the original operation_id, committed state and recovery step; it cannot be blindly retried with a new key. Accepted pending work reports completed:false separately from settlement. Provider rejection reports provider_failed.

Financial operation, evidence and provider references outlive the ordinary 24-hour outcome cache. They are not removed by directorist_mcp_cleanup. Retention must follow the native ledger/provider policy; they are operational consistency records, not an audit-log product.

The native refund table has no payment allocation column. Core refunds therefore require one authoritative settled receipt and refuse multi-receipt allocations. Registered native allocation support needs a separately verified integration. Orders with unusually large ledgers (over 1,000 payment/refund entries or financial references) require native reconciliation instead of unbounded MCP reads.

## Completing an asynchronous native refund

get-order-operation uses only the saved provider reference for read reconciliation. It never creates another refund, repeats fulfillment or writes the ledger. If the provider settled but native recording is missing/uncertain, complete that original native workflow through the provider integration or native administration first.

From a trusted administrator/native integration context with manage_options, call Order_Operations::complete_refund(operation_id, native_refund_id). Core rechecks provider settlement, compatible contract, native order/refund identity, exact amount/status and unique refund proof before marking that existing operation committed and releasing its reservation. This method is local PHP, not a remotely exposed tool; it never dispatches or creates a refund. Webhooks must use their native verified processing path and queue administrator reconciliation if they lack that context. Disablement blocks new MCP calls without cancelling previously accepted native financial work.

## Verification

Run tests/wp-cli/directorist-mcp-orders-extensions.php on an isolated local site. It uses disposable users/orders, suppresses mail, registers fake providers and restores data/settings in finally. Real gateway settlement, supported legacy installations, high-volume native webhook races, every extension combination and the full release matrix still require staging/provider certification.
