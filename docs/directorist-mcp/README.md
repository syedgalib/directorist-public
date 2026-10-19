# Directorist MCP — Product Requirements

Status: initial implementation of the 53-tool core catalog, extension API 1 and additive read-adapter API 1 is available for review. Full release acceptance remains subject to the verification gates.\
Prepared: 18 October 2026.\
Delivery: Directorist core; opt-in; documentation only in this package.

## Product summary

Directorist will expose a permission-aware Model Context Protocol (MCP) server so an AI assistant can help an authorized WordPress user configure a directory and manage its content. Users should be able to describe tasks such as “add an optional website field to the restaurant submission form” or “update the phone number on my listing” and receive a clear result or actionable validation error.

Directorist will expose WordPress abilities through the WordPress MCP Adapter using a dedicated endpoint, application-password authentication, and permission checks for each operation. Directorist will own its MCP settings, tools, validation, and authorization as part of its core plugin.

This package remains the product specification. The [implementation and verification notes](../../docs/mcp/README.md) describe the delivered core module, setup, extension contracts and checks actually executed. The [architecture and feasibility document](09-reference-and-feasibility.md) records the source baseline assessed before implementation.

The new [order management](10-order-management-prd.md) and [extension framework](11-extension-extensibility-prd.md) PRDs add 14 proposed core interfaces, bringing the target catalog to **45 core tools plus registered extension tools**. The initial implementation and focused checks are recorded in the [verification notes](../../docs/mcp/verification.md). These checks do not certify every provider/client or the entire release matrix.

[Dashboard data access](12-dashboard-data-prd.md) adds five proposed read-only interfaces for schema discovery, overview, series, recent activity and guidance. Dashboard data is scoped to the administrator's site access or the current user's eligible own records. [Site Health Info](13-site-health-info-prd.md) adds three proposed administrative read interfaces for discovery, section data and redacted support reports. The expanded specification targets **53 core tools plus registered extension tools**; the initial runtime now includes these additions. See the [dashboard/Site Health implementation guide](../../docs/mcp/dashboard-health.md) for supported sources, bounds, focused verification and remaining release gates.

## Goals and success criteria

1. An administrator can find MCP under Other Features, enable it through the dedicated field, open Configure in a side panel, connect a compatible client, and complete a read-only smoke test using the setup guide.
2. Administrators can manage supported settings and all five builder areas without corrupting unrelated configuration.
3. Listing owners can create and manage their own eligible listings; staff and custom roles receive only the access their effective capabilities permit.
4. Authorized taxonomy managers can manage categories, locations, and tags while preserving directory and hierarchy integrity.
5. Every write validates its inputs, preserves native business rules, and returns a verifiable result. Destructive and high-impact changes require a preview.
6. Permission loss, disabled MCP, missing dependencies, and missing extensions fail closed without breaking ordinary Directorist use.

7. Customers can inspect their own orders and cancel eligible pending purchases; authorized staff can maintain orders and execute explicitly supported payment/refund workflows with durable reconciliation.
8. Extensions can register tools, typed data adapters and additional business policies through a versioned core registry without core edits or weakened permission checks.
9. Authorized users can inspect dashboard metrics, trends, activity and guidance with explicit source, period and freshness; own-data summaries cannot disclose site totals or another owner's records.
10. Administrators with native Site Health access can inspect safe environment/dependency information and prepare redacted support reports without running status tests, update checks or repairs.

Release acceptance requires every scenario in the [test matrix](08-acceptance-test-matrix.md) to pass on the supported environment. Focused implementation tests are recorded separately; they do not constitute completion of the entire release matrix.

## Audience and personas

| Persona | Intended tasks | Boundary |
|---|---|---|
| Site administrator | Enable MCP; configure settings and directory builders; manage content | Requires the relevant effective capabilities, including `manage_options` for configuration |
| Content editor or delegated staff | Maintain eligible listings; moderate where authorized; manage terms where authorized | No settings or builder access solely because the role is named editor |
| Listing owner | Submit, edit, and trash/restore eligible own listings; use assignment lookups | Ownership and actual listing capabilities; no configuration management |
| Customer/purchaser | Read own order/payment/refund history and cancel an eligible pending order | Purchaser ownership and native order policy; linked-listing ownership alone is insufficient |
| Order administrator | Maintain unpaid orders and supported payment/refund workflows | Native administration, object/provider policy, preview and financial reconciliation |
| Extension developer | Register tools, typed fields/widgets and restrictive policies | Versioned registry, schemas, permissions, native adapters and write-safety contract |
| Support and QA | Diagnose setup failures and verify behavior | No credential disclosure or implicit production access |

## Agreed V1 scope

- Ship in Directorist core, off by default, using an installed compatible WordPress MCP Adapter and WordPress 6.9+ Abilities API.
- Place MCP under the new root-level Other Features settings menu. A dedicated `mcp-configuration` field combines immediate enablement with a Configure button, visible only when enabled, opening the MCP Connection side panel.
- Support core settings and explicitly registered extension settings, excluding secrets and credential changes.
- Manage directories, submission forms, search forms, single-listing layouts, and grid/list cards.
- Manage listings and the three existing listing taxonomies, including bounded bulk listing and term operations.
- Authenticate remote clients using WordPress application passwords over HTTPS. Document compatible direct HTTP clients and local bridges.
- Trash and restore listings. Delete only unused terms and empty, non-default directories; retain existing listing values when builder fields are removed.
- Add order schema/search/detail, unpaid-order creation/editing, eligible transitions/cancellation, offline payment recording, refund workflows, histories and durable operation lookup; bulk is limited to eligible edits/cancellations.
- Provide a versioned extension registry for tools, settings, builder widgets, listing/taxonomy/order fields, native providers and additional permission/business-rule restrictions.
- Expose read-only dashboard metrics, chart data, recent activity, setup and safe quick actions/recommendations under explicit site/own scopes, with optional registered dashboard contributions as specified in PRD 12.
- Expose administrator-only Site Health Info discovery, approved local environment/component facts and redacted text/JSON reports, including explicitly registered diagnostic contributions under PRD 13; exclude private fields, active tests and remote refreshes.

## Exclusions

V1 excludes OAuth authorization-server work; OAuth-only connections; permanent listing/order/payment/refund deletion; term merging; cascading deletion/reassignment; existing-listing directory transfers; arbitrary SQL/PHP/options/metadata access; user/role/capability administration; raw uploads and remote media fetching; gateway credential changes; automatic charging/capture; subscription lifecycle mutations; settled-ledger editing; direct paid-entitlement overrides outside native order fulfillment; automatic migration; and embedded AI chat. Supported order/payment/refund workflows are now in scope under PRD 10, replacing the original blanket exclusion.

Extension support is explicit, not automatic support for every installed add-on. PRD 11 expands existing settings/policy hooks into a general tool and adapter framework. Contributions are writable only with registered schemas, authorization, native persistence and safety contracts. Unsupported data is preserved on unrelated edits.

## Document index

| Document | Purpose |
|---|---|
| [01 — MCP settings and setup](01-mcp-settings-and-setup-prd.md) | Other Features navigation, dedicated field, Configure side panel, connection guide, diagnostics, revocation |
| [02 — Settings Panel Management](02-settings-panel-management-prd.md) | Discover, read, update, reset, extension registration |
| [03 — Directory Builder Management](03-directory-builder-management-prd.md) | Directory lifecycle and five builder areas |
| [04 — Listings Management](04-listings-management-prd.md) | Listing lifecycle, fields, media, terms, bulk operations |
| [05 — Taxonomy Management](05-taxonomy-management-prd.md) | Categories, locations, tags, associations, hierarchy, bulk |
| [06 — Access control and write safety](06-access-control-and-write-safety-prd.md) | Shared authorization, privacy, previews, retries, errors |
| [07 — MCP tool catalog](07-mcp-tool-catalog.md) | Proposed tool interfaces and story mappings |
| [08 — Acceptance test matrix](08-acceptance-test-matrix.md) | Story-to-test traceability and release gates |
| [09 — Architecture and feasibility](09-reference-and-feasibility.md) | Directorist integration points, gaps, constraints, feasibility report |
| [10 — Order Management](10-order-management-prd.md) | Purchaser/staff scopes, lifecycle, payments/refunds, bulk and reconciliation |
| [11 — MCP Extension Framework](11-extension-extensibility-prd.md) | Versioned registration, tools/domain adapters, permissions, discovery, lifecycle and developer tests |
| [12 — Dashboard Data Access](12-dashboard-data-prd.md) | Site/own overview, metrics and charts, recent activity, setup/guidance, freshness and dashboard contributions |
| [13 — Site Health Info](13-site-health-info-prd.md) | Administrator-only environment/component information, redacted support reports, native capabilities, multisite and diagnostic contributions |

Read document 06 together with every feature PRD. Its shared authorization and write rules apply even where a feature story does not repeat them. Document 07 is the canonical proposed tool inventory; document 08 links all stories to verification.

## Terminology and chosen defaults

| Term or default | Definition |
|---|---|
| MCP | Model Context Protocol; “MPC” in the original request is interpreted as MCP |
| Directory | A Directorist directory type represented by `atbdp_listing_types`, not a filesystem folder |
| Listing | A Directorist `at_biz_dir` post with its directory-specific fields and business rules |
| Taxonomies | `at_biz_dir-category`, `at_biz_dir-location`, and `at_biz_dir-tags` |
| Capability | The current WordPress user's effective permission, including custom roles and filters |
| Proposed endpoint | Site-generated REST URL for `/wp-json/directorist/mcp`; respect subdirectory and non-pretty REST URLs |
| Pagination | Default 20, maximum 100; one-based pages; invalid limits are rejected rather than silently ignored |
| Bulk limit | 1–50 explicit listing/term items or eligible order edits/cancellations; extension bulk requires registration; no query-wide writes or bulk payments/refunds |
| Preview | `dry_run: true`; validates and describes effects without changing product data |
| Confirmation | Opaque token valid for 5 minutes, bound to site, user, tool, exact change, and relevant state |
| Retry protection | Required idempotency key for mutation execution; retain successful outcomes for 24 hours |
| Configuration writes | Settings, directories, and builder changes require preview/confirmation |
| Existing installations | No migration or role-capability grants merely to introduce MCP; no changes to existing defaults |
| MCP UI location | Directorist Settings → Other Features → MCP; Other Features is a root settings-sidebar item |
| Dedicated MCP field | `mcp_enabled` boolean using new `mcp-configuration` type; immediate targeted save; Configure visible only for confirmed enabled state |
| Configuration surface | MCP Connection right-side modal, 640px desktop width capped by viewport; full width on small screens |
| Order | Purchase resolved through its authoritative native service; not necessarily a legacy post |
| Order owner | Recorded purchaser; linked-listing ownership does not automatically own the purchase |
| Extension registry | Validated source of names, schemas, dispatch, permissions, versions and discovery/UI summaries |
| Order writes | Preview/confirmation and native action permission required for every mutation |
| Financial outcome | Local recording and external settlement are separate; pending/unknown effects require reconciliation |

These are product decisions for V1. They are not descriptions of the current Directorist API or promises that every adapter/client version has already been tested.
