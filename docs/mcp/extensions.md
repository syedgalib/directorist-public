# Directorist MCP extension API 1

Register during directorist_mcp_register, before ability/server construction. Directorist builds the inventory independently of the current user; live discovery and execution apply authentication afterward. The registry runs once per request and seals after registration. Return values from registration are true or safe WP_Error; one rejected contribution cannot replace an existing owner/name. Catchable registration failures are isolated. Registered PHP remains trusted server code, not a sandbox.

## Owner and tool contracts

| Registration | Required properties |
|---|---|
| register_extension | owner slug, canonical plugin path, label, version, contract_version, integer api_version:1, capability, availability(), admission(user_id); optional requires_core and requires plugin paths |
| register_tool | owner, action, domain, description, contract_version, write boolean, closed bounded schema and output_schema, discover(args,user_id), permission(args,user_id), project(result,args,user_id) |
| Read tool | read(args); no product writes or external POSTs |
| Mutation tool | preview_schema, prepare(args), tables, invalidate(); core injects mutation controls and requires confirmation/version/idempotency |
| Optional bulk | bulk:true on a local mutation registers owner-bulk-action with 1–50 explicit items and per-item permissions/plans; no implicit bulk support or external-effect batches |
| Restrictive policy | register_policy with owner, unique key, check(tool,args,is_discovery) and optional tools allowlist; false adds a restriction and never replaces a core gate |

Names are directorist/owner-action abilities and directorist-owner-action MCP tools, with normalized collisions rejected and the supported ability length enforced. Core names are reserved. domain is extension, settings, directories, listings, taxonomies, orders, dashboard or site_health_info. The two diagnostic domains are read-only and enforce the additional input, object, privacy and HTTP restrictions in the [read-adapter guide](dashboard-health.md). Existing-domain tools retain the core administration/object/ownership floors. Extension admission grants a narrow domain, not access to unrelated core tools. Discovery predicates are independent of execution permissions.

Schemas use explicit types, closed object properties, string bounds/enums and arrays capped at 100. Secret properties, missing permissions, unbounded schemas, unsupported API majors, invalid identities and incomplete write contracts are rejected. Mutation control names are reserved. Readers return only the declared output projection. Mutation prepare returns state, safe preview and callable execute; preview must match preview_schema. Core adds changes.version, a five-minute token and the shared result envelope. Existing-state execution requires expected_version equal to the preview version. New tools do not receive arbitrary callbacks, SQL, option names or executable code from clients.

Core wraps read/preview/dispatch database access. Reads/previews reject SQL writes, nested MCP mutations and external writes. Dispatch permits the declared external action while prohibiting local product writes; the native writer runs separately. These guards cover cooperating WordPress paths, not malicious PHP using independent connections/files/network APIs. Integrations must validate native policy and pass contract tests.

## Runnable developer kit

The controlled fixture at tests/fixtures/mcp-extension.php supplies a reader, confirmed editor, opt-in bulk tool, additional restriction, settings/listing/taxonomy/order fields, builder widget contract, native order-item quote, fake refund provider and generic external-effect tool. It is not loaded or activated in production.

For a disposable local development plugin, include the fixture and call directorist_mcp_register_fixture(registry, state, plugin_basename(__FILE__)) from the registration action. The state object needs active, option, dispatches, reconciliations, timeout, settlement, external_dispatches, external_reconciliations and external_timeout. Use an isolated option prefix, set active:true and initialize counters to zero; settlement is pending or settled. The fixture provider never contacts a real gateway. Its code is a contract example, not a production integration.

A real installed plugin should instead declare its own owner, schema, native adapters and effective permissions. Never hard-code manage_options for purchaser-owned objects merely because the fixture uses administrator-only demonstration tools.

## Domain field and widget adapters

register_field_adapter takes owner, domain, key, contract_version, schema, default, sensitive:false, permission(context,action), read(context) and validate(value,context). Writable fields also require writable:true, storage_adapter, declared tables, complete writer and invalidate(). Domains are settings, builder, listings, taxonomies, orders, order_items and refund_providers.

| Domain | Integration behavior |
|---|---|
| Settings | Discovery/read/update/reset use the existing settings adapter. Legacy directorist_mcp_settings remains supported. Native shared-option fields use storage_adapter directorist; independent stores use their complete change-set writer and separate calls. Reset requires explicit resettable support. |
| Listings/taxonomies/orders | Detail/schema responses contain extension_schema and permitted extension_fields. Core create/update calls accept an explicit extension_fields object, preserve omitted fields and validate the complete host/extension unit before save. Lifecycle/financial operations cannot hide extra field writes. |
| Combined field stores | Native-unit adapters use storage_adapter directorist and declare every participating transactional table. Independent stores require separate registered tools. Host permissions and stable native identities still apply. |
| Builder | Register the native widget through the existing builder APIs and its matching builder adapter key. Core discovery then reports owner, contract and typed option schema. validate receives explicit option changes and the current widget. The native builder serializer, placements, stable IDs and listing data preservation remain authoritative. |
| Order items | schema validates item inputs; validate(item,customer_id,existing_order) returns native OrderDTO, safe preview and relevant state. Core enforces purchaser/listing identity, unpaid status, no target ID/expiration override, money constraints and native persistence. |
| Refund providers | permission(order,action), bounded provider input schema, validate(input,order,payment), idempotent:true, dispatch(operation_id,order,args), reconcile(operation_id,provider_reference,order), native contract version. Dispatch/reconcile return state pending/settled/failed/unknown and an optional safe reference. |

Field schemas/readers must not return secrets, raw provider payloads or executable registration details. Read-only fields remain nonwritable. Changes to schema/native data/dependencies must be represented in state/contract versions. Core version/lifecycle fingerprints bind previews and retries; plugin activation/deactivation/upgrade invalidates old confirmations, including after reactivation.

## External tools and recovery

A single mutation can opt into external_effects:true with idempotent:true, dispatch(operation_id,args), reconcile(operation_id,reference,args) and verify_record(operation_id,args,native_result). Normal prepare/preview, permission, schemas, tables, native writer and cache invalidation remain mandatory. Use the supplied operation ID as the provider idempotency reference. Do not create local product data inside dispatch. A returned reference is never a client-selected URL.

Core persists a durable intent before dispatch, calls the provider outside a database transaction, then invokes the native writer only for verified synchronous settlement. Pending/unknown outcomes retain references and never rerun that writer automatically. A namespaced action-operation read tool is generated. If the owner is deactivated, list-mcp-extensions with operation_id returns only a safe initiating-user tombstone; it never exposes stored arguments/results. Normal integration discovery accepts group/search/pagination and administrator-only diagnostics.

When the existing native workflow has completed asynchronously, a trusted administrator integration may call Extension_Operations::complete(operation_id,native_result). Core rechecks provider settlement, current original action permissions, matching contract, declared native verify_record and result schema. It marks that original intent committed; it never calls dispatch or execute. Conflicting repeated proofs are rejected. APIs are local PHP, not generic MCP execution endpoints.

Safe replay rechecks current authorization and output projection. Native data may contain private content, so project must filter again at replay. Operational arguments/results are private non-autoloaded consistency records. External/financial deduplication references intentionally survive ordinary replay cleanup; the integration must document its provider/native retention and privacy policy. Operation events exclude values, passwords, credentials, full evidence and idempotency keys.

## Storage and compatibility

Declare every custom table and use transactional InnoDB storage for database units. Provide cache invalidation for commit and rollback. Native integration writers must participate in Write_Guard::native_lock() before reading state used for a subsequent native write; raw SQL/noncooperating external stores are not automatically atomic. External side effects cannot be rolled back by a database transaction. Core refuses missing/nontransactional participants and unsafe combined stores.

API major 1 preserves the legacy settings hook and existing restrictive hooks. Installed extensions are not automatically certified. Publish extension/API/core/adapter versions and increment contract_version when schema, policy or callback behavior changes. No plugin activation, role grant, data migration, credential store or alternate server is introduced.

Run tests/wp-cli/directorist-mcp-orders-extensions.php on a local test site. The fixture suite covers permissions, schemas, native fields/widgets/items, previews, denied/unknown writes, stale state, duplicate retries, bulk outcomes, lifecycle invalidation, alternate ability entry, fake external settlement/timeout and native reconciliation proofs. Real gateways and the full cross-provider/client/concurrency matrix remain release gates.

## Dashboard and Site Health read contributions

The additive read-adapter contract is now available as Registry::READ_ADAPTER_VERSION = 1. Use register_read_adapter for dashboard overview/metric/guidance or site_health_info sections, with explicit public classification, owner/scopes/filters, bounded schemas and read/projection/permission/availability callbacks. No writer or automatic debug_information exposure is accepted. See the [read-adapter guide](dashboard-health.md#read-adapter-api-1) for the full descriptor, core permission floors, no-cache/HTTP restrictions and examples. Existing API 1 tools/fields retain their contract.
