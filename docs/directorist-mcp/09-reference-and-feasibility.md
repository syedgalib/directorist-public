# Directorist MCP — Architecture and Feasibility

Reviewed: 18 October 2026. Status: source baseline assessed before implementation. Statements about missing functionality below describe that baseline. The later core implementation and focused runtime checks are documented in the [implementation notes](../../docs/mcp/README.md). No plugin build was run.

## Evidence and authority

This assessment identifies existing Directorist integration points, functionality gaps, and compatibility constraints for the proposed MCP feature. Source links support statements about current Directorist behavior; the feature PRDs define the new behavior to implement.

Directorist's inspected plugin header/config identify **8.10.5** and PHP **7.4+**. These are source observations, not claims about the latest published version. Executable source takes precedence over older overview documentation for version and behavior facts. MCP requires the WordPress 6.9+ Abilities API and a compatible WordPress MCP Adapter; the supported adapter-version range must be verified for release.

### Local Directorist evidence

Paths below are relative to this document and point to the inspected source. Line numbers in descriptions are review pointers, not immutable anchors.

| Source | Verified fact | Product implication |
|---|---|---|
| [Plugin entry point](../../directorist-base.php), [configuration](../../config.php) | Header/config version 8.10.5; listings use `at_biz_dir`; directory types use `atbdp_listing_types`; three listing taxonomy constants | Use actual domain identifiers; do not conflate directory types with filesystem folders |
| [Role registration](../../includes/classes/class-roles.php) | Administrators receive full listing capabilities; editor and owner-role groups receive different listing capabilities; a legacy `manage_atbdp_options` also exists | Inspect effective capabilities; subscriber/owner is not synonymous with read-only |
| [Settings backend](../../includes/classes/class-settings-panel.php) | Settings menu/save use `manage_options`; AJAX `save_settings_data`; registered fields written into `atbdp_option`; `directorist_options_updated` runs after updates | Use the observed configuration gate rather than assuming the legacy named capability controls saves |
| [Settings entry](../../assets/src/js/admin/settings-manager.js), [Vue shell](../../assets/src/js/admin/vue/apps/settings-manager/Settings_Manager.vue), [tab rendering](../../assets/src/js/admin/vue/apps/settings-manager/TabContents.vue) | Vue settings shell, registry hooks, navigation, save feedback and sections | Add root Other Features → MCP navigation; preserve existing settings locations and shell contracts |
| [Field rendering](../../assets/src/js/admin/vue/modules/Sections_Module.vue), [component registration](../../assets/src/js/admin/vue/global-component.js) | Field/component dispatch and specialized controls exist, but no dedicated MCP configuration field with the required connection drawer is present | Implement the new `mcp-configuration` field and drawer lifecycle; generic toggle/button controls alone do not supply the required experience |
| [Settings template](../../views/admin-templates/settings-manager/settings.php), [shared store](../../assets/src/js/admin/vue/store/CPT_Manager_Store.js) | Base64-encoded builder data and shared Vuex fields/layouts/options/config state | A new transport must not redesign the persisted configuration format or overwrite shared state |
| [Directory manager](../../includes/modules/multi-directory-setup/class-multi-directory-manager.php), [helper](../../includes/modules/multi-directory-setup/trait-multi-directory-helper.php) | Builder save/delete request handlers check `manage_options`; supported configuration stored as term meta; helper supports create/update | Reuse business persistence through a service adapter, not AJAX globals or terminating response handlers |
| [Builder data](../../includes/modules/multi-directory-setup/class-builder-data.php) | General, submission, single-page, all-listing, and search-form areas; preset/custom widgets and configurable layouts | Full MCP builder management needs all five areas and runtime schema discovery |
| [Builder REST controller](../../includes/rest-api/Version1/class-builder-controller.php) | GET routes list four tab enums; reader implements only some branches; `get_item_permissions_check` returns true | This reader is not a sufficient full management API or authorization boundary; do not expose full raw builder state by wrapping it |
| [Listing REST controller](../../includes/rest-api/Version1/class-listings-controller.php) | Builder-aware creation and status-update handling; creation maps form values, validates terms/media, and uses submission classes | Build on listing workflows; do not assume every required mutation already exists in REST |
| [Post registration](../../includes/classes/class-custom-post.php) | Listing post type declares `capability_type` from `ATBDP_POST_TYPE` and `map_meta_cap: true` | Object-level edit/read/delete checks and status-dependent primitive capabilities matter |
| [Taxonomy registration](../../includes/classes/class-custom-taxonomy.php) | Categories/locations hierarchical; tags flat; assign capability tied to listing publication capability in default registration | Resolve taxonomy capabilities dynamically; assignment is distinct from editing/creating terms |
| [Term controller](../../includes/rest-api/Version1/class-abstract-terms-controller.php), [permission helpers](../../includes/rest-api/functions.php) | Taxonomy operations map read to `manage_terms`, create/edit to `edit_terms`, delete to `delete_terms`, with Directorist permission filtering | Provide a separate safe assignment lookup for owners; do not weaken management-reader authorization |
| [Category controller](../../includes/rest-api/Version1/class-categories-controller.php), [location controller](../../includes/rest-api/Version1/class-locations-controller.php) | Supported term metadata includes category icon, images, and directory references where applicable | Discover supported properties rather than accepting arbitrary term meta |

### Directorist MCP integration requirements

Directorist will register its own abilities, transport permission gate, settings controls, typed tool schemas, and operation handlers. The dedicated server must expose only the intended Directorist tool allowlist and enforce enablement, capabilities, ownership, and field access on every execution path.

Configuration and destructive operations require a five-minute preview confirmation bound to the site, user, exact action, and relevant state. Mutation execution requires idempotency protection with 24-hour outcome retention and concurrency controls. These are Directorist product requirements, not capabilities assumed to exist in its current business APIs. Cache-based token storage alone does not satisfy concurrent-write guarantees.

Setup guidance must use trusted HTTPS, server-generated credential placeholders, and native WordPress application-password creation/revocation. The dedicated drawer can fill and copy a snippet from locally entered credentials without sending them to Directorist. Directorist must not install dependencies silently or disable certificate verification in generated examples.

The tool catalog distinguishes WordPress ability names from MCP wire names. Verify adapter name mapping, schemas, protocol negotiation, and session behavior against the supported adapter release. Publish tested adapter/client versions rather than treating a locally available version as a permanent compatibility guarantee.

### Official external references

- [WordPress Abilities API](https://developer.wordpress.org/apis/abilities-api/) describes typed abilities and their permissions, available in WordPress 6.9+. This supports a feature-level prerequisite without requiring a change to Directorist's overall WordPress support policy.
- [WordPress MCP Adapter](https://github.com/WordPress/mcp-adapter) provides the ability-to-MCP bridge, custom servers, transport permissions, and per-ability checks. Directorist must integrate through verified supported adapter APIs.
- [WordPress REST authentication](https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/) documents application passwords over HTTPS. Use a personal client credential and native revocation.
- [MCP authorization](https://modelcontextprotocol.io/specification/2025-11-25/basic/authorization) describes the OAuth-based authorization framework. The chosen application-password mode is a constrained compatibility option for suitable clients/bridges, not implementation of that OAuth flow.

## Existing behavior versus proposed additions

| Topic | Existing evidence | New V1 requirement |
|---|---|---|
| Directorist MCP | Existing business APIs/settings; no proposed Directorist server or dedicated connection field found in inspected areas | Core bootstrap, endpoint, Other Features → MCP navigation, dedicated field/Configure drawer, 31 tools and explicit schemas |
| Settings safety | UI save gate and registered-key persistence | Typed exposure classification, normalized validation, targeted preview/reset, extension contract, safe concurrency |
| Builder | Native UI and metadata; incomplete REST reader | Complete schema and management operations with dependency/stale-state checks |
| Listing policy | Post capabilities and native submission/status workflows | Permission-filtered MCP adapters; complete single/bulk lifecycle and safe retries |
| Taxonomy | Native taxonomies/controllers and assignment caps | Owner-safe assignment lookups, conservative deletion, association integrity and bounded bulk |
| Confirmation | No Directorist MCP confirmation mechanism exists in the inspected feature areas | Directorist-specific state/user/payload binding and invalidation on disable |
| Logging | Adapter integration/observability mechanisms | Safe Directorist operation events; no new durable audit-log product |

## Settings Panel Feasibility Report

### Summary

- **Request:** Add root Other Features → MCP settings navigation and a dedicated configuration field whose enabled state reveals Configure, opening a connection side-panel modal; support the documented management abilities.
- **Recommended approach:** Keep the Vue settings shell, `atbdp_option`, current admin capability, and update hooks. Add a new `mcp-configuration` field type bound to `mcp_enabled`, with immediate targeted persistence, a conditional Configure action, and a right-side MCP Connection modal. Build validated ability/service adapters around existing business operations.
- **Risk level:** Documentation is low risk. Eventual MCP configuration writes and builder/listing mutations are high risk because permissions, typed validation, concurrency, and frontend side effects must agree.

### Easy To Achieve

- Additive disabled-by-default boolean storage for `mcp_enabled` and root Other Features navigation, preserving existing keys and locations.
- Static setup copy, endpoint display, native profile/application-password links, and manual dependency guidance inside the new drawer.
- Reuse the existing settings shell and reference field/widget catalogs for discovery after explicit exposure classification.
- Define a core/extension registration contract without migrating existing options or metadata.

### Complex Or Risky

- The complete connection UI is not supported by a current generic field. The new dedicated field must integrate rendering, status/loading/error states, drawer lifecycle, transient credential state, client tabs and clipboard handling without leaking secrets into the shared store.
- Immediate enablement must synchronize current/cached state, preserve unrelated dirty fields and exclude stale MCP values from later generic saves. An optimistic unsaved toggle must not expose Configure.
- The modal needs responsive positioning outside clipped settings containers, focus trapping/restoration, inert background, keyboard close behavior and cleanup on navigation/disable/permission loss.
- Settings write validation must be stronger than accepting an arbitrary registered key; controls can have nested values, dependencies, secrets and side effects.
- Raw AJAX handler reuse can terminate execution or rely on request globals/nonces; service boundaries are needed without changing the native UI contract.
- Multi-key builder writes and default-directory changes need coordinated consistency; dependency checks must include hidden and trashed content.
- Safe idempotency and stale-state protection require concurrency control, not a cache lookup alone.
- Listing entitlements, submission policies, extension fields, and term associations need runtime schema/permission validation.
- Application-password connections will not serve OAuth-only clients; shipping support claims require actual client/bridge tests.

### Affected Areas

- **PHP settings builder:** Root `other_features` layout, `mcp` submenu, registered `mcp_enabled` field of type `mcp-configuration`, protected status/template reads, exposure registry, validation/service adapters, and existing update hooks.
- **Vue app/store:** New dedicated field component/dispatch, Configure drawer, client tabs/copy behavior, local credential lifecycle, current/cached state synchronization and general-save exclusion. Preserve shared fields/layouts/config contracts and unrelated dirty state.
- **SCSS/build output:** Feature-card action layout and 640px-capped right-side responsive modal with correct stacking/scrolling/focus presentation; scope styles to the MCP component. Future implementation asset work follows the repository's build policy. This documentation task runs no builds.
- **Saved options/data:** New preference in `atbdp_option`; existing setting keys, directory term meta and listing values retain their identities/storage; no migration assumed.
- **Connected frontend/admin behavior:** Submission, search, card/detail rendering, status notifications, maps, pages/permalinks, caches, and registered extension behavior.

### Questions Before Risky Work

V1 product decisions are core delivery, off by default, administrators plus eligible owners, registered extension settings without secret writes, application-password mode, bounded bulk, and conservative deletion. The required native UI is Other Features → MCP with a new dedicated field, immediate enablement and an enabled-only Configure action opening the connection side panel. These decisions govern implementation and acceptance criteria; a new field type is explicitly required rather than left as an optional generic-control composition.

Implementation must verify the installed adapter contract and supported client/bridge versions, complete each field/widget exposure classification, and prove the consistency guarantees. These are engineering/release validation work, not permission to weaken requirements. Any subsequently discovered need to change existing storage, role grants, save payloads, defaults, or migrations requires a separate documented design decision under the repository workflow; no such implementation is included here.

### Verification Plan

- **Static checks:** Document links, story/criterion/tool/test references, unique IDs, requirement consistency, source paths and distinctions between current/proposed behavior.
- **Browser/manual QA:** Future release testing of root navigation/deep links/search, dedicated field rendering, successful/failed immediate saves with unrelated dirty settings, enabled-only Configure, responsive modal/focus/close behavior, client-tab/clipboard/error states and password cleanup. Also test permission fixtures, native builder/listing/taxonomy round trips and frontend effects. Verify status/templates/network/storage/logs do not leak entered credentials.
- **Build policy:** No builds needed for these Markdown files. Future code work follows the [repository settings-panel workflow](../../docs/agents/directorist-settings-panel/SKILL.md); do not alter generated bundles as part of documentation.

## Compatibility and delivery constraints

- Require WordPress 6.9+ only for MCP. Older or incompatible environments continue normal Directorist behavior with MCP unavailable and a clear prerequisite message.
- Detect available adapter functions/classes and supported APIs. Reuse a compatible already loaded provider and avoid double loading or coupling Directorist to another product's dependency loader. Publish a tested adapter-version range with the eventual release.
- Preserve PHP compatibility within Directorist's supported runtime; no blanket PHP-version increase is specified by this PRD package.
- Preserve settings storage, existing defaults, field identities, term/listing relationships, native hooks and extension data. Do not introduce migrations/backfills as a shortcut.
- Package gates: source-informed specification → implementation and focused tests → adapter/client integration tests → staging frontend/permission verification → release opt-in. No automatic enablement on upgrade.
- Sites can disable MCP immediately to stop subsequent ability execution; this is the feature rollback control. It does not revoke WordPress credentials or roll back already committed content changes.

Relevant repository guidance: [AGENTS.md](../../AGENTS.md), [settings-panel skill](../../docs/agents/directorist-settings-panel/SKILL.md), [current panel map](../../docs/agents/directorist-settings-panel/references/current-settings-panel-map.md), [option catalog](../../docs/agents/directorist-settings-panel/references/settings-option-catalog.md). No source or instruction file was edited by preparing this package.


## Order and extension scope addition

This assessment supplements the original pre-implementation baseline above. The current core module contains 31 tools and limited extension settings/policy hooks. [PRD 10](10-order-management-prd.md) and [PRD 11](11-extension-extensibility-prd.md) propose order management and a general extension framework; this documentation update implements neither. The expanded target is 45 core tools plus validated registered extension tools.

### Source evidence for the additions

| Inspected source | Observed behavior | Requirement or gap |
|---|---|---|
| [Order controller](../../includes/rest-api/Version1/class-order-controller.php), [base controller](../../includes/rest-api/Version1/class-abstract-controller.php) | Staff routes use the native administration check, currently manage_options; customer queries use the current purchaser; customer cancellation requires purchaser ownership and pending status | Preserve distinct staff and own-order scopes; listing authorship is insufficient; add narrow customer-only MCP admission |
| [Legacy-compatible orders controller](../../includes/rest-api/Version1/class-orders-controller.php), [order repository](../../includes/repositories/order-repository.php), [order model](../../includes/db-models/order.php), [status enum](../../includes/enums/Order/Status.php) | Multiple order representations and status mappings exist; the current order model uses custom tables and repository hooks | Resolve one authoritative record/service; publish supported versions; do not assume a post ID, arbitrary status setter or automatic migration |
| [Payment repository](../../includes/repositories/payment-repository.php), [payment model](../../includes/db-models/payment.php) | Native payment persistence and order-linked status operations exist | Reuse native services only after action/amount/provider validation; direct paid-status updates do not prove money was received |
| [Refund controller](../../includes/rest-api/Version1/class-order-refund-controller.php), [refund repository](../../includes/repositories/refund-repository.php), [refund model](../../includes/db-models/refund.php), [refund DTO](../../includes/dto/Refund/dto.php) | Administration-gated refund records, remaining-amount checks, paid-refund totals and native hooks exist; the DTO uses a floating amount | Recording a refund alone is not evidence of provider settlement; specify exact currency precision, pending reservations, concurrency and provider reconciliation |
| [MCP catalog](../../includes/modules/mcp/class-catalog.php), [tool dispatcher](../../includes/modules/mcp/class-tools.php), [module](../../includes/modules/mcp/class-module.php) | The core catalog/dispatch/server summaries are fixed around the existing tool set | Introduce one validated registry that drives names, schemas, authorization, dispatch, discovery and UI summaries; avoid parallel hard-coded inventories |
| [MCP settings adapter](../../includes/modules/mcp/class-settings.php), [access checks](../../includes/modules/mcp/class-access.php), [write guard](../../includes/modules/mcp/class-write-guard.php) | Explicit settings registration, permission hooks and a core write wrapper exist; they are narrower than a general extension/tool/provider contract | Preserve compatibility while adding owner/version contracts, per-entry-point checks, custom-table/cache/lock declarations and durable financial outcomes |

### Addition feasibility report

**Summary:** Add native order service adapters and an owner-scoped, versioned registry. Preserve the existing Other Features → MCP dedicated configuration field, enabled-only Configure action and connection side panel. Tool groups/counts and administrator extension diagnostics become registry-derived. No second endpoint, credential store or extension-specific authentication flow is introduced.

**Easy to achieve:** Reuse native purchaser filters and administration checks, bounded repository reads, safe order/status projections, and existing settings registration conventions. Add typed read schemas, source documentation, compatibility descriptors and discovery summaries without moving stored data. These reuse opportunities still require permission and contract tests.

**Complex or risky:** Exact monetary arithmetic across currencies; order/payment/refund consistency; pending refund reservations; concurrent requests and duplicate native events; provider timeouts; process failure after an external effect; and durable reconciliation. A database rollback cannot undo a provider action. The current generic retry retention is insufficient for financial duplicate prevention. Every participating custom table and external store needs an explicit transaction/locking or reconciliation contract. Unsupported providers must remain unavailable for writes.

The registry must be built before caller filtering and transport admission, reject collisions and malformed contracts, and apply the same core gates through direct ability execution and bulk items. Extension deactivation, reactivation or schema/version changes must invalidate affected previews/retries and stop fresh dispatch while retaining safe reconciliation references. Hook exceptions must not expose payloads or make unrelated tools fail. Installed PHP extensions remain trusted server code; this is an exposure contract, not a sandbox.

**Affected areas:** Core catalog, dispatcher, abilities/server registration, transport admission, field/schema adapters, preview fingerprints, idempotency and operation storage, transaction/lock participation, payment/refund provider adapters, diagnostic events and setup summaries. Frontend purchase fulfillment, listing entitlements, native hooks, caches and notifications need consistency checks. Settings storage, existing authentication, role grants and unrelated values retain their contracts; no migration is assumed.

**Questions before risky work:** Engineering must establish authoritative storage/version mappings, safe native calculation/action boundaries, currency precision rules, provider capabilities and financial operation retention. It must define registry/API compatibility versions and prove storage/provider consistency before exposing writes. These are release gates; the proposed API names and descriptors in PRD 11 are not claims of already available hooks or permission to bypass native policy.

**Verification plan:** Add purchaser/staff/custom-role fixtures, legacy/current order references, a trusted test extension and fake or sandbox providers. Run QA-043–QA-061 and cross-cutting gates QA-X09–QA-X10 for visibility, exact amounts, pending/unknown settlement, concurrent/refused actions, duplicate recovery, custom stores, extension lifecycle and frontend effects. Run the developer contract suite through MCP and alternate ability entry points. No live financial action, extension activation, plugin build or runtime test was performed for this documentation update; earlier core test results do not cover these additions.

## Initial implementation follow-up

The order/extension scope assessed above now has an initial implementation in includes/modules/mcp. The [developer contract](../../docs/mcp/extensions.md), [native order contract](../../docs/mcp/orders.md) and [verification record](../../docs/mcp/verification.md) distinguish delivered behavior, tested paths and remaining release gates. Earlier missing-functionality statements retain their source-baseline meaning. Registry-derived summaries reuse the existing dedicated field and drawer; no settings key/layout/save migration or generated bundle build was required.

## Dashboard data scope addition

[PRD 12](12-dashboard-data-prd.md) proposes five read tools T46–T50, expanding the specification to 50 core definitions. The currently implemented 45-tool runtime has not been changed by this documentation addition.

| Inspected source | Observed behavior | Requirement or integration constraint |
|---|---|---|
| [Dashboard activity/metrics service](../../includes/classes/class-extension-activity.php) | Native listing counts/views/expiry, paid-order aggregates, charts, setup predicates and paginated activity; views may be a distribution rather than daily history | Safe typed projection, explicit metric/range/source definitions, own-scope query adapters and bounded paging; do not pass through global aggregates to owners |
| [Dashboard page controller](../../includes/classes/class-extension.php), [page template](../../views/admin-templates/theme-extensions/theme-extension.php) | Composes welcome/account state, quick actions, setup visibility, activity and recommendations; activity AJAX uses manage_options and nonce | Preserve native page/UI/AJAX contracts; resolve read data without rendering the page, invoking action handlers or exposing account/license/raw presentation payloads |
| [Frontend listing dashboard model](../../includes/model/ListingDashboard.php) | Current-author listing/status queries, favorites, optional enquiries and conditional purchase-history navigation | Own listing eligibility differs from purchaser eligibility; apply each source's native read/ownership rules before aggregates and pagination |
| [Owner purchase dashboard](../../assets/src/js/react/frontend/listing-owner-dashboard/app.tsx), [MCP orders adapter](../../includes/modules/mcp/class-orders.php) | Customer history uses native orders; MCP already separates purchaser/staff and exposes safe financial reads | Reuse PRD 10 authorization/mapping/money rules; own purchase amounts are spending, not earnings; dashboard reads cannot cancel, charge, refund or fulfill |
| [MCP registry](../../includes/modules/mcp/class-registry.php), [read guard](../../includes/modules/mcp/class-read-guard.php) | Validated tools/fields and shared read-safety wrapper exist; dashboard contribution adapter is not yet implemented | Version a read-only dashboard contract explicitly; retain domain floors, schema validation, lifecycle/cache invalidation and alternate-entry enforcement |

The existing native financial dashboard sums amounts with floats and labels them using the configured currency. MCP must group exact values by recorded currency, deduplicate migrated representations and label missing legacy coverage honestly. Existing stock, post-date series and rolling financial windows have different meanings; the PRD defines explicit local-date periods and stock/lifetime range labels instead of silently presenting them as one interchangeable range.

The [PRD feasibility report](12-dashboard-data-prd.md#feasibility-and-compatibility-report) records reuse, risky integrations, affected systems, compatibility constraints and release decisions. This source-based documentation work stores no live counts, identities, account/product state or screenshots. No account refresh, external provider call, UI change, runtime dashboard test or build was performed. QA-062–QA-070 and QA-X11 remain future release verification.

## Site Health Info scope addition

[PRD 13](13-site-health-info-prd.md) proposes three administrative read tools T51–T53 for safe information discovery, individual sections and text/JSON support reports. The combined specification targets 53 core definitions; the implemented runtime baseline remains 45. This is information access, without a Site Health Status test runner, repair or update-check workflow.

| Inspected source | Observed behavior | Requirement or integration constraint |
|---|---|---|
| [WordPress Site Health screen](https://github.com/WordPress/WordPress/blob/master/wp-admin/site-health.php), [capability handling](https://github.com/WordPress/WordPress/blob/master/wp-includes/capabilities.php) | Native screen requires view_site_health_checks; WordPress derives effective grants and applies multisite rules | Require live native capability plus Directorist manage_options, with source/network restrictions; no role grant, owner/purchaser escalation or cross-site selectors |
| [Info screen](https://github.com/WordPress/WordPress/blob/master/wp-admin/site-health-info.php), [debug data provider](https://github.com/WordPress/WordPress/blob/master/wp-admin/includes/class-wp-debug-data.php) | Native screen runs update checks; full collection invokes multiple sources, can request WordPress.org and applies debug_information | Use safe bounded local adapters, not page rendering/full-collector passthrough or private-method reflection; no external requests, status tests or update refresh |
| [Debug field definitions and formatter](https://github.com/WordPress/WordPress/blob/master/wp-admin/includes/class-wp-debug-data.php) | Separate value/debug representations; native private flags exclude data from copied output, while some unflagged constants/directory fields contain paths | Both representations and nested metadata require stricter explicit allowlisting/redaction before output/cache/formatting; no raw/private mode |
| [Site Health REST controller](https://github.com/WordPress/WordPress/blob/master/wp-includes/rest-api/endpoints/class-wp-rest-site-health-controller.php) | Separate status-test routes and directory-size collection exist | Do not call tests, initiate recursive size collection or assume GET means no effects; only previously measured safe local snapshots are eligible |
| [MCP registry](../../includes/modules/mcp/class-registry.php), [read guard](../../includes/modules/mcp/class-read-guard.php) | Validated contributions and read wrappers exist; arbitrary diagnostics and a site_health_info adapter are not current contracts | Add explicit compatible read-only diagnostic registration and per-tool capability/redaction rules; prevent collector HTTP/SQL writes without weakening shared guards |

The [feasibility assessment](13-site-health-info-prd.md#feasibility-and-compatibility) records compatible source adapters, field policy, local runtime identity, cache bounds and isolated large-inventory verification. No live Site Health dump, credential, server path or user-specific state was collected/stored for this documentation. QA-071–QA-078 and QA-X12 remain future release checks; existing runtime verification does not establish T51–T53 support.

## Dashboard and Site Health implementation follow-up — 19 October 2026

The initial 53-tool runtime now implements T46–T53 and additive read-adapter API 1. Earlier gap descriptions retain their source-assessment meaning. The [implementation guide](../../docs/mcp/dashboard-health.md) records fresh noncached collection, native permission-scan limits, exact currency and database TIMESTAMP handling, unsupported optional-source behavior, strict diagnostic redaction and extension registration. Focused CLI/HTTPS/bridge evidence is recorded separately; minimum-runtime/multisite/full-matrix release gates are not presumed complete. No native page, settings save/key, generated assets, role grants or migrations were changed.
