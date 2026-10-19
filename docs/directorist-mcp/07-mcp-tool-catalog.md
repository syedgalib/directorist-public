# Directorist MCP — Proposed Tool Catalog

Status: initial implemented interface: **53 core tool definitions plus explicitly registered extension tools**. T01–T31 are the original core catalog; T32–T45 add order management and integration discovery; T46–T53 add dashboard data and Site Health Info. Registered extension tools remain owner-scoped. See [verification](../../docs/mcp/verification.md). Story IDs resolve through the [overview](README.md). [PRD 06](06-access-control-and-write-safety-prd.md) governs every entry.

[PRD 12](12-dashboard-data-prd.md) proposes T46–T50, expanding the specification to **50 core tools plus registered extension tools**. These five dashboard tools now have an initial implementation and focused verification.

[PRD 13](13-site-health-info-prd.md) adds proposed T51–T53, bringing the latest specification to **53 core tools plus registered extension tools**. The initial runtime now implements both groups; the earlier 45-tool verification baseline remains historical.

## Server and naming

- Server ID and ability category: `directorist`.
- Endpoint: site-generated REST URL for namespace `directorist`, route `mcp`, conventionally `/wp-json/directorist/mcp`. Do not assume a domain-root or pretty-permalink installation.
- WordPress ability names use `directorist/<hyphenated-action>`, for example `directorist/get-context`. Directorist's adapter integration must expose the corresponding MCP wire name `directorist-get-context`. Verify this mapping against the supported adapter release. Connector wrappers may normalize names again; discovery is authoritative.
- Explicitly expose only the validated core and explicitly registered extension allowlist on the dedicated server. Extension tools use directorist/owner-action abilities and directorist-owner-action wire names; collisions after normalization are rejected. No generic execute-SQL, arbitrary metadata, user administration, remote file download, or broad “run action” tool.
- HTTP transport uses WordPress authentication/application passwords for compatible clients. Initialization, protocol negotiation, sessions, and pagination of the tool list follow the supported adapter's contract.
- V1 exposes tools, not additional MCP resource or prompt products. Abilities must remain protected if another adapter server or direct API exposes them.

## Common inputs and outputs

These are minimum product-facing contracts. Implementation must publish concrete JSON Schemas for each tool using discovered enums/field definitions; this document does not replace those schemas.

| Contract | Requirement |
|---|---|
| IDs and enums | Positive numeric object IDs; only registered directory/area/taxonomy/status values. Unknown arguments fail validation. |
| Collection inputs | `page` (default 1), `per_page` (default 20, 1–100), declared filters and order. Stable ID tie-breaker. |
| Collection result | `items`, `page`, `per_page`, authorized `total`, `total_pages`, `has_more`. Empty authorized collections have `total: 0`. |
| Bounded dashboard activity exception | T49 returns authorized `window_total`, `history_start`, `truncated` and continuation limits instead of implying a complete historic total; see PRD 12. |
| Detail/schema result | Authorized data plus an opaque `version` for editable existing state; schema reports supported operations and constraints. |
| Mutation controls | `dry_run` default false, optional `confirm_token`, required execution `idempotency_key`; existing-object mutations require `expected_version` from read/preview. |
| Preview result | `dry_run: true`, normalized `changes`, safe `impact`, `warnings`, `confirm_token` when eligible, `expires_in_seconds: 300`; bulk includes per-item preview/errors. |
| Mutation success | `success: true`, safe object ID/data or deletion result, effective status/version where applicable, `committed: true`, warnings, `idempotent_replay` boolean. |
| Bulk envelope | One `action`, 1–50 `items`, each with unique `client_ref` and action inputs; explicit object IDs for existing records. One taxonomy per term batch. |
| Bulk result | `results` in request order, each with `client_ref`, object ID when authorized/known, outcome, committed state, and safe data/error; summary counts for succeeded/failed/indeterminate. |
| Error | `success: false`, `error.code`, safe `message`, optional `field_errors`/authorized dependency details, `retryable`, `next_step`, `request_id`, and `committed` (`false`, `true`, or `unknown`). |

Return logical failures as MCP tool errors using the adapter's supported error/content mechanism. Authentication/transport failures use the appropriate transport response. Do not invent HTTP-success semantics that conceal a tool failure. Use structured MCP content where supported and a consistent safe JSON representation for clients that consume text.

`expected_version` is not a field a user needs to invent: details and previews supply it. Creation uses discovered schema/source versions as part of its validated state; it has no target-record version. A confirmation binds the normalized action and relevant versions. A replay uses the same logical mutation payload even if the confirmation token has been consumed. See PRD 06 for key scope and ordering.

### Permission shorthand

- **ACCESS:** enabled/ready MCP, authenticated user, meaningful native Directorist capability/own-order eligibility or registered narrow extension-domain access; output filtered to that user.
- **ADMIN:** ACCESS plus `manage_options`, and any additional registered field permission.
- **LIST:** ACCESS plus runtime post-type capability, object access, directory policy, field visibility, and business checks for the specific action.
- **TERM:** ACCESS plus runtime taxonomy manage/edit/delete capability and Directorist filters for the action.
- **EXT:** ACCESS plus a valid active owner registration, mandatory core domain floors and the registered tool/object/field/provider checks.
- **ASSIGN:** ACCESS plus assignment capability and eligible directory scope; listing mutation also requires object edit access.
- **P:** preview/confirmation always required for execution. **C:** confirmation required under the conditional policy in PRD 06. **R:** read-only.

## Context and reference tools

| ID | MCP wire name | Main inputs | Main result | Access/mode | Stories |
|---|---|---|---|---|---|
| T01 | `directorist-get-context` | None | Safe user/site identity, timezone, feature readiness, effective groups, permitted actions, limits, guide references; no protected global counts | ACCESS / R | MCP-03, MCP-04, MCP-06, SEC-01, SEC-02, ORD-01, EXT-02, EXT-03 |
| T02 | `directorist-get-reference-data` | `kind`: directories, assignment_terms, media, pages; directory/taxonomy/search/IDs and paging as appropriate; proposed order_references for authorized staff | Minimal eligible choices and IDs, never raw configuration | ACCESS with per-kind checks / R | MCP-06, SET-01, LST-02, LST-05, TAX-01, SEC-03, ORD-03, EXT-04 |

`pages` is ADMIN-only and validates page choices for settings. `directories` returns only names/IDs and submission eligibility needed by the caller; full management detail is T07/T08. `assignment_terms` is ASSIGN-only with safe labels and directory scope, not taxonomy-management metadata. `media` is bounded and restricted to attachments the caller can select under native policy, not an unrestricted media-library export. Full management taxonomy queries use T26/T27.

## Settings tools

| ID | MCP wire name | Main inputs | Main result | Access/mode | Stories |
|---|---|---|---|---|---|
| T03 | `directorist-discover-settings` | Optional supported `group`, explicit `keys`, paging | Field schema, constraints, dependencies, owner, sensitivity/read/write/reset classification | ADMIN / R | SET-01, SET-05 |
| T04 | `directorist-get-settings` | Explicit `keys` or supported `group`, paging | Authorized effective/default values and saved-override state | ADMIN / R | SET-02, SET-05 |
| T05 | `directorist-update-settings` | `changes` keyed by supported field; mutation controls | Validated targeted change-set preview or committed effective values | ADMIN / P | SET-03, SET-05, SEC-04, SEC-06 |
| T06 | `directorist-reset-settings` | Explicit `keys`; mutation controls | Registered-default preview or effective reset values | ADMIN / P | SET-04, SET-05 |

Settings operations exclude secrets and `mcp_enabled`. A change set must use one compatible persistence adapter; independent extension stores require separate calls. MCP admin status/enablement stays in the nonce-protected native UI/control route and is not represented as a remotely callable tool here.

The native connection experience lives under Directorist Settings → Other Features → MCP in the dedicated `mcp-configuration` field. Its immediate switch save, conditional Configure button, side-panel status reads and placeholder-template reads do not add MCP tools. Local credential entry, client tabs and snippet copying are component state/actions and never appear as tool parameters or persisted settings.

## Directory and builder tools

| ID | MCP wire name | Main inputs | Main result | Access/mode | Stories |
|---|---|---|---|---|---|
| T07 | `directorist-list-directories` | Search, default filter, supported order, paging | Management summaries with listing count | ADMIN / R | BLD-01 |
| T08 | `directorist-get-directory` | `directory_id`, optional `area` | General/configuration details and versions, limited to requested area | ADMIN / R | BLD-01, BLD-03 |
| T09 | `directorist-get-builder-schema` | `directory_id`, `area` | Supported widgets/options/placements, stable IDs, required items, dependency rules | ADMIN / R | BLD-01, BLD-04, BLD-05, BLD-06, BLD-07 |
| T10 | `directorist-create-directory` | Name, slug, supported general values; mutation controls | New directory with native default configuration | ADMIN / P | BLD-02 |
| T11 | `directorist-duplicate-directory` | Source directory/version, new name/slug; mutation controls | New configuration-only directory; dependency warnings | ADMIN / P | BLD-02 |
| T12 | `directorist-update-directory` | `directory_id`, explicit general `changes`; mutation controls | Saved supported general configuration and version | ADMIN / P | BLD-03 |
| T13 | `directorist-set-default-directory` | `directory_id`, current default/configuration version; mutation controls | Resolved default directory ID | ADMIN / P | BLD-03 |
| T14 | `directorist-delete-directory` | `directory_id`; mutation controls | Dependency preview or deleted ID; blocked when ineligible | ADMIN / P | BLD-08 |
| T15 | `directorist-update-builder` | `directory_id`, `area`, explicit typed `operations` on item/section IDs; mutation controls | Validated area diff or saved configuration/version | ADMIN / P | BLD-04, BLD-05, BLD-06, BLD-07 |

Area enum: `general`, `submission_form`, `search_form`, `single_listing`, `listing_cards`, matching the adapter's mapping to existing builder keys. T15 accepts only the four form/layout areas; general updates use T12. Operations are `add`, `update`, `move`, `reorder`, or `remove` where the schema allows them, and use typed properties rather than raw term-meta paths. `reorder` must identify the complete affected sibling order; `move` names the target section/position. Moving/removing a section cannot silently delete children.

Create/duplicate require multi-directory mode. Duplicate copies configuration, not listings or term associations. T14 has no `force`, replacement-directory, or cascade option. Builder field storage-key migration is not supported.

## Listing tools

| ID | MCP wire name | Main inputs | Main result | Access/mode | Stories |
|---|---|---|---|---|---|
| T16 | `directorist-list-listings` | `scope`: management/public; declared search, ID, directory, author, status, term, featured/date filters; order/paging | Authorized compact listing rows and totals | LIST read / R | LST-01 |
| T17 | `directorist-get-listing` | `listing_id`, scope, optional supported field selection | Authorized details, actual status, permitted actions, version | LIST read / R | LST-01, LST-03, LST-04 |
| T18 | `directorist-get-listing-schema` | `directory_id`, optional eligible `listing_id` | Caller-specific form fields, constraints, consent/media/term rules, allowed actions, schema version | LIST create/edit / R | LST-02, LST-03, LST-05 |
| T19 | `directorist-create-listing` | `directory_id`, supported `fields`, explicit consents, existing term/media references, optional permitted initial status; mutation controls | New listing ID, actual native status, normalized permitted fields | LIST create / C | LST-02, SEC-05 |
| T20 | `directorist-update-listing` | `listing_id`, explicit supported `changes`; mutation controls | Saved fields/status/version | LIST edit / C | LST-03, LST-05 |
| T21 | `directorist-change-listing-status` | `listing_id`, permitted target status; mutation controls | Actual resulting status/version, native-policy messages | LIST transition / P | LST-04 |
| T22 | `directorist-trash-listing` | `listing_id`; mutation controls | Trashed ID/status/version | LIST delete / P | LST-06 |
| T23 | `directorist-restore-listing` | `listing_id`; mutation controls | Restored ID, actual status/version | LIST restore / P | LST-06 |
| T24 | `directorist-bulk-listings` | One action: create, update, change_status, assign_terms, trash, restore; explicit `items`; mutation controls | Per-item preview or execution results and summary | LIST per item / P | LST-07, SEC-02, SEC-05, SEC-06 |

T20 supports explicit term assignment operations (`add`, `remove`, `replace`) and media reference changes, with their separate authorization/validation; no implicit replacement of omitted arrays. Initial public publication under T19 requires confirmation even if derived from a directory default. T20 does not accept ownership, directory transfer, generic entitlement/featured/expiry overrides, or direct publication-status changes; use T21 for supported status transitions. Schema-driven native edit-status changes are returned rather than hidden.

## Taxonomy tools

| ID | MCP wire name | Main inputs | Main result | Access/mode | Stories |
|---|---|---|---|---|---|
| T25 | `directorist-list-taxonomies` | None | Three allowed taxonomy schemas, hierarchy, supported metadata, effective management/assignment permissions | ACCESS filtered / R | TAX-01, SEC-02 |
| T26 | `directorist-list-terms` | Allowed `taxonomy`, directory/parent/search/explicit ID filters, supported order/paging | Management term rows, authorized counts/associations | TERM manage / R | TAX-01 |
| T27 | `directorist-get-term` | `taxonomy`, `term_id` | Management term detail, supported metadata, associations, version | TERM manage / R | TAX-01, TAX-03, TAX-04 |
| T28 | `directorist-create-term` | `taxonomy`, name, supported properties, valid scope; mutation controls | New normalized term ID/data | TERM create / C | TAX-02 |
| T29 | `directorist-update-term` | `taxonomy`, `term_id`, explicit properties/association operations; mutation controls | Validated saved term/version or impact preview | TERM edit / C | TAX-03, TAX-04 |
| T30 | `directorist-delete-term` | `taxonomy`, `term_id`; mutation controls | Dependency preview or deleted ID | TERM delete / P | TAX-05 |
| T31 | `directorist-bulk-terms` | One allowed `taxonomy`, one action: create/update/delete; explicit `items`; mutation controls | Per-item preview/execution results and summary | TERM per item / P | TAX-06, SEC-05, SEC-06 |

Assignment-only callers use T02 instead of T26/T27. T25 exposes permission/schema metadata, not protected term records. T30 has no force/reassign/delete-children parameter. Parent references and directory associations must be valid before processing; bulk creates do not resolve client references to newly created parents.

## Order tools

[PRD 10](10-order-management-prd.md) defines these actions. **ORDER-SELF** means native purchaser access plus object/field policy. **ORDER-ADMIN** means the native administration gate, currently manage_options, plus object/action/provider checks. Every order write is P; no money movement is hidden in generic status, settings or listing tools.

| ID | MCP wire name | Main inputs | Main result | Access/mode | Stories |
|---|---|---|---|---|---|
| T32 | directorist-get-order-schema | Purpose/read scope, optional eligible order_id and item/provider type | Caller-specific fields, filters, money precision, native actions, versions and safe provider support | ORDER-SELF or ORDER-ADMIN / R | ORD-01, ORD-03, ORD-04, ORD-05, ORD-06, ORD-07, EXT-04 |
| T33 | directorist-list-orders | Own/management scope, allowed IDs/status/customer/listing/item/date/currency filters, order and paging | Authorized summaries/totals; no mixed-currency monetary total | ORDER-SELF or ORDER-ADMIN / R | ORD-02 |
| T34 | directorist-get-order | order_id, optional permitted field selection | Safe order detail, independently permitted relationships, allowed actions and version | ORDER-SELF or ORDER-ADMIN / R | ORD-02, ORD-04, ORD-05 |
| T35 | directorist-create-order | Existing customer/item/listing references, supported native calculation inputs, schema_version; mutation controls | Preview or one native unpaid order with authoritative totals/status/version; no charge | ORDER-ADMIN create / P | ORD-03 |
| T36 | directorist-update-order | order_id, explicit supported unpaid-order changes; mutation controls | Validated recalculation diff or saved values/version | ORDER-ADMIN edit / P | ORD-04 |
| T37 | directorist-change-order-status | order_id, discovered eligible nonfinancial action/status and reason; mutation controls | Native resulting state/version and declared fulfillment effects; no arbitrary paid/refunded state | ORDER-ADMIN transition / P | ORD-05 |
| T38 | directorist-cancel-order | order_id, permitted reason; mutation controls | Native cancellation outcome/status/version | ORDER-SELF pending cancellation or ORDER-ADMIN native cancellation / P | ORD-05 |
| T39 | directorist-record-order-payment | order_id, eligible offline method, exact amount/currency, required safe receipt/evidence reference; mutation controls | One recorded offline receipt, actual order/fulfillment state and durable operation ID; never an online charge | ORDER-ADMIN plus native payment-record policy / P | ORD-06, ORD-09 |
| T40 | directorist-list-order-payments | order_id, allowed status/date filters, paging | Bounded permission-projected payment history; no raw provider payload/secret | ORDER-SELF or ORDER-ADMIN read / R | ORD-02, ORD-06 |
| T41 | directorist-list-order-refunds | order_id, allowed filters, paging | Bounded refund history and authorized settled/reserved/refundable totals in one currency | ORDER-SELF or ORDER-ADMIN read / R | ORD-02, ORD-07 |
| T42 | directorist-create-order-refund | order_id, eligible payment reference, positive exact amount/currency, reason, explicit mode record_only or gateway, registered provider/evidence inputs; mutation controls | Refund/operation ID, ledger state, distinct external settlement state and reconciliation next step | ORDER-ADMIN plus native refund/provider policy / P | ORD-07, ORD-09, EXT-06 |
| T43 | directorist-bulk-orders | One action update or cancel, 1–50 explicit items with unique client_ref and versions; mutation controls | Per-item reviewed effects/results and succeeded/failed/indeterminate totals | ORDER-ADMIN with per-item checks / P | ORD-08, ORD-09 |
| T44 | directorist-get-order-operation | Opaque operation_id | Authorized local/external effect state, safe original correlation and next step; no dispatch | ORDER-ADMIN or permitted initiating purchaser cancellation / R | ORD-06, ORD-07, ORD-08, ORD-09, EXT-06, EXT-07 |

T02's proposed order_references kind provides bounded eligible customer/item/listing/offline-method choices to order administrators under the same native object/provider rules. It does not expose general user administration or a global customer directory to purchasers. T32 returns schema and bounded reference descriptors, not every available customer/item in one response.

Creation cannot choose an already settled initial status. T36 rejects customer reassignment, settled financial edits, raw metadata and entitlement overrides. T37 cannot manufacture paid/refunded states; T39/T42 invoke the distinct validated native workflows. T43 has no payment/refund/create/financial-status action.

For accepted asynchronous financial work, return success: true, completed: false, operation_id and state: pending, with local committed state and external_effect stated separately. This means accepted, not settled. Unknown provider outcomes return outcome_unknown plus an authorized operation reference. T44 reads/reconciles the original reference without issuing a new write. Durable financial deduplication outlives the ordinary 24-hour replay cache.

## Extension discovery and registered tools

[PRD 11](11-extension-extensibility-prd.md) defines the registry, descriptors and safety wrapper. It replaces fixed catalog/dispatch/summary assumptions with a validated owner-aware inventory.

| ID | MCP wire name | Main inputs | Main result | Access/mode | Stories |
|---|---|---|---|---|---|
| T45 | directorist-list-mcp-extensions | Optional owned group/search filters, paging; administrator diagnostic flag; operation_id for initiating-user external-operation recovery | Permitted active owner labels/versions/groups/tools; safe unavailable reasons only for administrators; private initiating-user operation metadata/tombstone without stored payloads | ACCESS plus contribution visibility; ADMIN for diagnostics / R | EXT-01, EXT-02, EXT-07, EXT-08, EXT-09 |

An extension registers a tool through the local PHP registry action, never a client-callable register-tool endpoint. Dynamic tools use directorist-<owner>-<action>, complete bounded input/output schemas, explicit permissions and shared mutation controls/results; they have no fixed T-number until a product-specific PRD assigns one. A new tool does not automatically create bulk support.

T01, T02, relevant core field schemas, dedicated/default server abilities, invocation dispatch, discovery filtering and connection summaries must use that registry. Registration inventory is built before authentication without user-based pruning; visibility is filtered at request time. The same live enablement, owner/dependency, capability, object/field, preview/retry and output checks apply through every entry point.

| Integration contract | Owning stories |
|---|---|
| Registry descriptors, collision validation and stable names | EXT-01, EXT-05, EXT-09 |
| Additional permission floors and domain admission | EXT-03 |
| Settings/widget/listing/taxonomy/order field and native provider adapters | EXT-04 |
| Mutation planning, transactions, durable external effects and replay | EXT-06 |
| Deactivation/reactivation, dependency changes and in-flight reconciliation | EXT-07 |
| Safe failures, bounded projections, events and secrets | EXT-08 |
| Versioned examples, fixture extension and reusable contract tests | EXT-10 |

## Dashboard data tools (proposed)

[PRD 12](12-dashboard-data-prd.md) defines metric semantics, source coverage, native reuse, bounds and freshness. **DASH-SITE** requires ADMIN plus source/object/field rules. **DASH-OWN** requires native own-dashboard eligibility and each source's permissions; purchaser-only eligibility grants only own purchase sections. Unsupported or explicit forbidden sections fail before querying. All five tools are read-only, with no preview, confirmation, idempotency key or financial dispatch.

| ID | MCP wire name | Main inputs | Main result | Access/mode | Stories |
|---|---|---|---|---|---|
| T46 | `directorist-get-dashboard-schema` | Optional scope site/own | Authorized sections/metrics, typed definitions, filters, defaults, limits, availability and compatible contribution versions | DASH-SITE or DASH-OWN / R | DASH-01, DASH-03, DASH-08 |
| T47 | `directorist-get-dashboard-overview` | scope; optional explicit sections, directory_id, preset period or bounded date range | Listing health/views, currency-separated order values, eligible own summary; per-section source, state, effective range and freshness | DASH-SITE or DASH-OWN with per-section checks / R | DASH-02, DASH-03, DASH-05, DASH-09 |
| T48 | `directorist-get-dashboard-series` | scope, metric; optional directory_id, period/dates, currency, compare_previous, series_kind auto/daily | At most 90 dated daily points per currency or explicitly typed distribution, coverage and safe previous-period comparison | DASH-SITE or DASH-OWN with metric/source checks / R | DASH-04, DASH-05, DASH-08, DASH-09 |
| T49 | `directorist-list-dashboard-activity` | scope; optional discovered type, directory_id, period/dates, paging | Separate bounded past and upcoming collections with independent paging/totals, stable identities/order, safe references and history metadata | DASH-SITE or DASH-OWN with record/source checks / R | DASH-03, DASH-06, DASH-09 |
| T50 | `directorist-get-dashboard-guidance` | scope; optional sections setup/quick_actions/recommendations, directory_id, paging | Native setup state, safe next-action descriptors and optional bounded recommendations; separate collection pagination | DASH-SITE; DASH-OWN for eligible own actions/registered guidance / R | DASH-07, DASH-08, DASH-09 |

Use the existing endpoint and registry. Extend T01 and live discovery/group summaries when implemented; do not hard-code future tool counts into current status. Dashboard contributions require an explicitly versioned read-only contract under PRD 11; the additive read-adapter API 1 is implemented without changing existing extension API 1 descriptors. Cache hits and all ability entry points recheck live scopes, ownership, fields and source availability. No raw admin-page, account, license, enquiry or analytics payload is exposed.

## Site Health Info tools (proposed)

[PRD 13](13-site-health-info-prd.md) defines the approved fields, privacy, native source adapters, multisite boundaries and support-report contract. **HEALTH-ADMIN** requires ACCESS, `manage_options` and effective `view_site_health_checks`, plus section/source checks; shared network/host inventories on multisite additionally require super-administrator authority. No own/purchaser scope or raw/private bypass exists.

| ID | MCP wire name | Main inputs | Main result | Access/mode | Stories |
|---|---|---|---|---|---|
| T51 | `directorist-discover-site-health-info` | Optional section_id, paging | Eligible safe section/field descriptors, types, source availability, limits, report formats and privacy-policy version | HEALTH-ADMIN / R | SHI-01, SHI-04, SHI-06, SHI-07 |
| T52 | `directorist-get-site-health-info` | Required discovered section_id; optional 1–100 field_keys, paging | One typed redacted environment/component section, authorized totals, runtime/source/freshness metadata and safe unavailable reasons | HEALTH-ADMIN plus section checks / R | SHI-01, SHI-02, SHI-03, SHI-04, SHI-06, SHI-07, SHI-08 |
| T53 | `directorist-get-site-health-report` | Optional 1–10 section_ids; format json/text default text | Bounded support report using the same safe projection, explicit coverage and metadata; returned only in MCP response | HEALTH-ADMIN with every selected section checked / R | SHI-03, SHI-04, SHI-05, SHI-07, SHI-08 |

Use the existing endpoint, authentication and registry. T01/discovery/group summaries reflect actual eligible registrations when implemented. Reports default to WordPress/runtime/Directorist/MCP sections and cap at 1,000 fields/items and 256 KiB; oversized selections fail with narrowing guidance rather than silent truncation. No preview, confirmation, replay operation, file publication, external transmission, status-test runner, update check or recursive size scan is introduced. Contributed diagnostic sections require explicit versioned read-only registration; native debug_information filters alone do not authorize MCP exposure.

## Error codes and client behavior

| Code | Meaning / next step |
|---|---|
| `mcp_disabled`, `dependency_unavailable` | Stop; administrator must enable or restore prerequisites |
| `unauthenticated`, `forbidden` | Correct credentials or authorized account/capability; no automatic elevation |
| `not_found` | Object absent or inaccessible; do not infer which |
| `validation_failed`, `unsupported_operation`, `sensitive_field` | Correct explicit inputs or use a supported native UI workflow |
| `dependency_blocked` | Resolve authorized relationship/child/occupancy constraints explicitly |
| `confirmation_required`, `confirmation_invalid`, `confirmation_expired` | Obtain a fresh preview; do not reuse a token for changed inputs |
| `state_changed` | Refetch relevant state and preview again |
| `idempotency_required`, `idempotency_conflict` | Supply a key or use a new key for a genuinely different reviewed mutation |
| `extension_unavailable`, `contract_changed` | Owner/dependency unavailable or changed contract; refresh discovery and obtain a new eligible preview |
| `operation_in_progress` | Same operation still running; poll/read or retry the same key after the indicated interval |
| `outcome_unknown` | Reconcile via read and request correlation; no blind new-key retry |
| `operation_failed` | Safe internal failure; inspect `committed`, `retryable`, and `next_step` |

Read tools advertise read-only annotations; deletion/trash tools advertise destructive behavior. Mixed-action/configuration tools use conservative annotations when any path is consequential. Idempotency annotations must match actual guarantees; annotations are client hints and never access control.

Before release, verify the expected registry inventory through discovery and invocation, including the target 45 core definitions when dependencies and permissions permit, plus the controlled extension tools. The earlier 31-tool results do not prove these additions. Test name mapping, empty-object schemas, live permissions, provider availability and negotiated protocol behavior; an enabled switch is insufficient.

Dashboard release additionally requires the 50-definition catalog baseline, permission-filtered T46–T50 discovery/invocation, registered dashboard contributions and the PRD 12 verification gates. This does not change the 45-definition baseline of previously recorded runtime tests.

The initial combined dashboard/Site Health implementation provides 53 definitions. Verify T51–T53 through the dual native/Directorist capability gate, multisite and alternate entry points, redacted text/JSON parity and zero test/update/network side effects. Proposed counts must not appear in live status before implementation.
