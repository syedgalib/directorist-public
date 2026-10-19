# PRD 11 — MCP Extension Framework

Dashboard scope addition: [PRD 12](12-dashboard-data-prd.md#caching-extensions-and-read-safety) proposes a versioned read-only dashboard contribution contract for metrics, sections and guidance. Its registration/permissions/lifecycle requirements inherit this PRD. The additive read-adapter API 1 is now implemented; see the [read-adapter guide](../../docs/mcp/dashboard-health.md#read-adapter-api-1) for compatible versioning and focused contract tests.

Site Health scope addition: [PRD 13](13-site-health-info-prd.md#extension-contributions) proposes explicitly registered read-only diagnostic sections under a versioned site_health_info adapter. It inherits these ownership/schema/lifecycle rules plus native Site Health capabilities and stricter field redaction. WordPress debug_information contributions are not automatically MCP registrations, and the additive read-adapter API 1 implements this contract without changing existing tool/field descriptors.

Status: initial extension API 1 implementation is available for review. See the [developer contract](../../docs/mcp/extensions.md) and [verification](../../docs/mcp/verification.md). Related: [settings](02-settings-panel-management-prd.md), [orders](10-order-management-prd.md), [shared safety](06-access-control-and-write-safety-prd.md), [catalog](07-mcp-tool-catalog.md), [test matrix](08-acceptance-test-matrix.md).

## Product outcome

An installed Directorist extension can safely expose its own supported data and actions through the core MCP server. Developers can contribute settings, builder widgets, listing/taxonomy/order fields, business policies and new tools without editing Directorist core or creating a competing authentication/server implementation.

Core owns transport, WordPress identity, enablement, registration, schemas, authorization sequencing, previews, retries, result/error envelopes and safe events. Extensions own their domain validation, additional permissions, data projections, native persistence and declared side effects. A registration never grants a user new permissions.

## Existing behavior and gap

At the specification baseline, MCP had explicit extension settings registration, several policy/dependency/visibility hooks and a builder widget allowlist, with a fixed catalog and dispatch model. The initial implementation now uses a central registry, typed domain adapters and durable external-effect records; compatibility and remaining release gates are documented separately. A widget-name allowlist alone is insufficient proof of safe write support.

This PRD expands that foundation. Existing extension settings registrations must remain compatible through a documented compatibility adapter. No commercial extension becomes automatically MCP-enabled merely because it is installed or modifies an admin schema.

## Scope and prerequisites

| Extension contribution | Requirements |
|---|---|
| Settings | Typed safe fields, defaults, sensitivity, additional capability, side-effect-free validator, reader and atomic change-set writer |
| Builder widgets/options | Stable widget/storage identity, supported areas/slots, typed option/default schema, placement/reference/conditional validation and native serialization |
| Listing/taxonomy fields | Explicit read/write projections, directory/object/field access, validators, preservation of omitted values and native persistence |
| Order/item/payment/refund integration | Native object/provider permissions, exact money rules, entitlement effects, supported operations and durable reconciliation where external effects occur |
| Additional tools | Unique names, complete input/output schemas, owner, visibility/permission callbacks, read or mutation handler, safety metadata and supported result contract |
| Business rules/dependencies | Additional restrictions, protected relationships and state/version contributions; no replacement of mandatory core guards |
| Discovery and diagnostics | Current eligible registrations, versions, tool groups and safe reasons for unavailable integrations |

Prerequisites: core MCP is enabled and compatible; the owning extension and its declared dependencies are active/ready; the registration satisfies the supported extension API major version; the current actor passes transport, tool, object, field and native business checks. Anonymous access, plugin installation/activation through MCP, remote registration URLs, uploaded executable callbacks, arbitrary hook execution, role grants and credential exposure remain excluded.

## Public registration contract

The following contract is implemented as registry API major 1; concrete descriptor properties and runnable fixtures are documented in the developer guide:

1. Directorist emits a PHP registration action named **directorist_mcp_register** with a core-owned registry object before ability/server catalog construction.
2. The extension uses **register_extension**, **register_tool**, **register_field_adapter** and **register_policy** on that registry. Registration is local PHP from an installed plugin; clients cannot register tools over MCP.
3. Registration validates deterministically at boot/catalog construction. Runtime availability and permission callbacks are reevaluated at discovery and execution. Do not filter the boot-time inventory using the current user before WordPress authentication completes.
4. Core resolves the registry for ability names, schemas, dispatch, permissions, supported bulk adapters and UI summaries. Core tool declarations use the same pipeline; no separate unguarded extension execution branch is allowed.
5. A compatibility adapter translates supported existing directorist_mcp_settings registrations and documented hooks. It cannot expose previously excluded secrets or automatically turn old widget allowlists into unrestricted writers.

### Extension descriptor

| Property | Required contract |
|---|---|
| owner | Stable unique plugin/vendor slug and canonical plugin identity; translated labels do not define identity |
| label / version | Human-readable name and installed extension version |
| api_version / requires | Supported core registry API major, minimum core version and explicit plugin/service dependencies |
| contract_version | Behavior/schema revision used in previews, versions, operation records and compatibility decisions |
| availability | Side-effect-free readiness callback; safe unavailable reason; no network installation or automatic permission elevation |
| contributions | Owned tool/field/policy/storage adapter identifiers; no claim on an existing core or other owner's identifier |
| documentation | Developer support/setup reference and supported compatibility information; never credential-bearing URLs |

### Tool and field descriptor

| Property | Required contract |
|---|---|
| Identity | Owner, stable action, group, translated label/description, contract version |
| Naming | Core abilities remain directorist/action. Extension abilities use directorist/owner-action; corresponding wire name directorist-owner-action. Example: directorist/example-hours-get-schedule. |
| Schemas | Concrete bounded JSON input/output schemas, explicit unknown-property behavior, defaults/enums and secret/private/write classifications |
| Authorization | Explicit discovery predicate plus independent live tool/object/field permission checks; permission callback is mandatory |
| Read execution | Bounded reader with a deliberate safe projection; no raw model serialization or implicit mutation |
| Mutation planning | Side-effect-free normalize/validate/preview handler, affected object/policy/provider versions, confirmation requirement and safe impact |
| Mutation execution | Core-wrapped native writer, declared transaction/lock participants, idempotency/replay policy and side effects |
| External effects | Durable intent/dispatch reference, provider idempotency and read-only reconciliation; absence makes unsafe external writes unsupported |
| Persistence | Stable adapter identifier; complete-unit writer; declared affected tables/cache/native hooks; no client-selected SQL, table or callback |
| Bulk | Explicitly registered compatible item action with bounds and per-item guards, or unavailable; registration does not create bulk support automatically |
| Results | Shared committed/error/replay/pending/unknown semantics, safe event metadata and output projection applied again at replay |

Core annotations reflect the most consequential execution path. An extension cannot mark a mutating tool read-only, relax a required confirmation, extend input/bulk limits or claim idempotency that its writer/provider does not support.

## User stories and acceptance criteria

### EXT-01 — Register an extension integration

As an extension developer, I want a documented registration API so that my integration uses the Directorist MCP server without core edits.

Access: installed plugin PHP registration; no remote registration tool.

- **EXT-01-AC1:** Given an active compatible extension with a valid descriptor, when the registration lifecycle runs, then its owned contributions are accepted into the central registry before ability/server construction without modifying core files.
- **EXT-01-AC2:** Given a missing permission callback, invalid/boundless schema, unsupported API major, missing owner or incomplete write-safety contract, when registering, then the contribution is rejected with administrator-safe diagnostics and no callable partial tool.
- **EXT-01-AC3:** Given core or another extension already owns a name/field/adapter identifier, when a conflicting registration is attempted, then it cannot replace the existing contribution; unrelated registrations and normal Directorist use remain available.

### EXT-02 — Discover available integrations and tools

As an administrator or authorized user, I want discovery to show currently eligible extension features so that I know what my assistant can use.

Access: administrators can inspect safe integration diagnostics; other users see only permitted active contributions.

- **EXT-02-AC1:** Given active permitted contributions, when listing tools, context, schemas or the connection summary, then names, owner/group labels and counts come from the same current registry rather than a fixed 31-tool list.
- **EXT-02-AC2:** Given an extension tool forbidden to the caller, when discovering tools or integration details, then its protected schemas/data/diagnostics are omitted and direct invocation is still independently denied.
- **EXT-02-AC3:** Given a missing dependency or unavailable provider, when an administrator inspects integrations, then a safe unavailable reason and recovery step are shown without secrets, raw callback details or automatic activation.
- **EXT-02-AC4:** Given a client with a cached list after activation/deactivation, when discovery refreshes or a previously seen tool is invoked, then live availability is applied and the client is instructed to rediscover when necessary.

### EXT-03 — Preserve core permission boundaries

As a site administrator, I want extension tools to respect current capabilities and object ownership so that installing an integration cannot expand a user's authority.

Access: core guards plus all registered additional permission checks.

- **EXT-03-AC1:** Given an extension tool with valid tool permission but an inaccessible object/field, when invoked, then the core/extension checks deny protected access before data is returned or changed.
- **EXT-03-AC2:** Given an unfamiliar custom role, an order-only customer or a caller whose capability was revoked after preview, when executing, then effective live permissions and the narrow allowed scope apply without granting unrelated core tools.
- **EXT-03-AC3:** Given a callback returning true or permissive discovery metadata, when it conflicts with core enablement, administration, ownership or sensitive-field restrictions, then the mandatory core guard still rejects the action.
- **EXT-03-AC4:** Given the same extension ability invoked through the dedicated server, default adapter server, direct API or bulk item, when disabled, unready or unauthorized, then it fails under the same checks as the normal tool entry.

### EXT-04 — Extend existing Directorist data safely

As an extension developer, I want typed field/widget adapters so that users can manage my supported data alongside native Directorist features.

Access: domain permission plus registered field/widget capability; same native logical-unit rules as the host feature.

- **EXT-04-AC1:** Given a supported extension setting/widget/listing/taxonomy/order field, when its domain schema is requested, then it reports stable identity, type, defaults, constraints, dependencies, sensitivity, owner and effective read/write/reset support.
- **EXT-04-AC2:** Given a valid explicit field or widget change, when confirmed where required, then the registered native adapter validates/persists it without bypassing host-domain capabilities, placement, directory, hierarchy, entitlement or monetary rules.
- **EXT-04-AC3:** Given unknown/read-only extension data or an omitted extension field, when another supported value changes, then unrelated stored data and stable keys remain intact and unsupported writes fail explicitly.
- **EXT-04-AC4:** Given mixed independent stores, an invalid extension validator or a writer without complete-unit guarantees, when a combined change set is requested, then it is rejected before any save or external side effect.

### EXT-05 — Add extension-specific tools

As an extension developer, I want to register domain-specific tools so that an assistant can use capabilities that do not fit an existing core action.

Access: mandatory registered permissions and core execution wrapper; names remain owner-scoped.

- **EXT-05-AC1:** Given a valid owner-scoped read or mutation tool, when discovered and executed, then its schema, handler, namespaced ability/wire mapping and shared result contract are used without a hard-coded core dispatch-group edit.
- **EXT-05-AC2:** Given a read-only declaration whose handler attempts a registered write path, when invoked, then the write is blocked or the registration is refused before release; read/preview contract tests detect hidden writes and emitted mutation effects.
- **EXT-05-AC3:** Given unknown arguments, raw callback names, executable code, unregistered metadata paths or oversized inputs, when submitted, then the request is rejected under the core envelope and tool schema before handler execution.
- **EXT-05-AC4:** Given a tool that needs bulk processing, when registration omits a compatible bulk adapter or per-object checks, then no bulk variant is exposed; supported bulk variants retain the 50-item cap and independent outcomes.

### EXT-06 — Reuse previews, retries and external-effect protection

As a user, I want extension mutations to have the same write guarantees as core tools so that retries do not repeat actions or apply stale changes.

Access: every mutation passes core planning/confirmation/idempotency plus its native adapter.

- **EXT-06-AC1:** Given a consequential extension mutation, when previewed, then typed normalized input, relevant versions/dependencies, safe effects and a five-minute confirmation are returned without product writes or external dispatch.
- **EXT-06-AC2:** Given a changed extension contract/version, dependency, permission, payload or relevant state, when a preview is executed, then it is invalidated before the writer runs.
- **EXT-06-AC3:** Given a concurrent/repeated exact mutation key, when execution or replay occurs, then one logical operation is reserved, current output permissions are reapplied and no hidden second commit or side-effect dispatch occurs.
- **EXT-06-AC4:** Given an external operation accepted before timeout, when failure/reconciliation occurs, then durable references and declared provider safeguards preserve pending/unknown outcomes; database rollback is never reported as reversal of an external effect.

### EXT-07 — Handle activation, deactivation and updates

As a site administrator, I want integrations to follow extension availability so that removed or incompatible code cannot remain callable through cached MCP state.

Access: native plugin lifecycle and core dependency checks; MCP does not install/activate extensions.

- **EXT-07-AC1:** Given an active compatible extension, when a new request discovers tools, then eligible contributions appear without core edits or a data migration; disabled MCP still blocks them.
- **EXT-07-AC2:** Given owner/dependency deactivation after discovery or preview, when invoking through any entry point, then the contribution becomes unavailable, old confirmations cannot authorize it and saved domain data remains intact.
- **EXT-07-AC3:** Given deactivation/reactivation or a contract-changing update, when old tokens are reused, then they remain invalid despite the owner being active again; versions/lifecycle state prevent resurrection.
- **EXT-07-AC4:** Given an in-flight external effect during deactivation, when reconciliation is requested, then core retains an authorized safe operation tombstone/reference and reports provider-unavailable until reconciliation is possible without reissuing the write.

### EXT-08 — Isolate invalid registrations and failures

As a site operator, I want safe, attributable integration failures so that one extension problem does not corrupt other features.

Access: normal tool permissions; operational diagnostics are administrator-only.

- **EXT-08-AC1:** Given a catchable exception during registration/schema/availability/handler execution, when core contains it, then unrelated valid integrations remain usable and a safe owner/action/error reference is returned without stack traces or secrets.
- **EXT-08-AC2:** Given a validation failure or a persistence failure, when reported, then failed, committed, pending and unknown states are distinguished; an uncertain item cannot be mislabeled as rolled back.
- **EXT-08-AC3:** Given private fields, credentials or instruction-like content in extension storage, when reads/previews/errors/events are produced, then schema projection and redaction prevent disclosure and no content triggers a tool action.
- **EXT-08-AC4:** Given extension writes to additional tables or side-effect services, when a transaction is planned, then the declared participants and cache/lock strategy are verified; unsupported atomicity is rejected before mutation.

### EXT-09 — Maintain a versioned compatibility contract

As an extension developer, I want a stable versioned API so that supported integrations survive Directorist updates predictably.

Access: validated registration; no implicit compatibility claim for every extension.

- **EXT-09-AC1:** Given compatible core/extension API versions and existing settings registrations, when core updates, then published backward-compatible behavior and existing supported registrations continue through the compatibility adapter.
- **EXT-09-AC2:** Given an unsupported major version or missing required dependency, when registering, then contributions are unavailable with administrator-safe compatibility guidance rather than partially executing.
- **EXT-09-AC3:** Given a supported contract change or deprecated API, when release documentation is published, then names/versions, migration guidance and tested adapter/runtime combinations are explicit without silent storage/key/capability changes.

### EXT-10 — Validate an integration before release

As an extension developer and QA engineer, I want runnable examples and contract tests so that I can prove an integration is safe and compatible.

Access: isolated development/test site; no production provider transactions.

- **EXT-10-AC1:** Given the developer kit, when following its examples, then a controlled extension can register a read tool, confirmed mutation, additional-restriction policy and supported field/widget adapter without editing core.
- **EXT-10-AC2:** Given that fixture extension, when running its contract suite, then schemas, denied roles/owners, secret redaction, read/preview nonmutation, stale versions, duplicate retries, dependency removal and external-effect failure paths are tested.
- **EXT-10-AC3:** Given a release candidate, when compatibility is claimed, then documentation records the actual tested core/registry/extension/adapter/client versions and pending limitations; installing an extension is not treated as certification.

## Lifecycle, isolation and UI requirements

- Registration is not an authorization cache. Core builds a complete validated inventory independently of request identity; discovery applies current visibility and execution repeats every guard.
- Core retains ownership of its names, permission floors, secret exclusions, hard-deletion restrictions, request/page/bulk limits, confirmed financial actions and safe result envelopes. Extensions can add restrictions and new explicitly authorized domains, not remove those floors.
- A trusted PHP plugin can execute arbitrary code in WordPress; this framework is not a PHP sandbox. Contract tests and explicit support declarations are required. Catchable failures can be isolated; process-level fatal errors and malicious installed code are outside the isolation guarantee.
- A contribution declares every table/cache/native writer needed for its logical transaction. Cross-adapter mutations are rejected unless a supported orchestrator proves the whole unit safe. External effects require an explicit dispatch/reconciliation lifecycle.
- Use a schema/owner/lifecycle fingerprint in previews and operation records. Do not rely solely on active-plugin names: disable/re-enable, schema upgrades and dependency changes must invalidate old authority.
- Extend the existing **Other Features → MCP** field and Configure drawer summary to derive active groups/counts from the registry. Include Orders and authorized extension groups; do not add another root menu, server toggle, credential store or authentication flow.
- Administrators may inspect registered owner/version/readiness and safe unsupported reasons. Ordinary users see only their eligible active integrations through T45 and existing context/discovery; no installed-plugin inventory leak.
- Preserve the existing targeted enablement save and component-local credential handling. No extension registration, secret, handler name or access-policy mutation is editable through ordinary settings tools.

## Success and failure scenarios

| Scenario | Expected result |
|---|---|
| Controlled extension adds a schedule reader and confirmed editor | Central discovery, object permissions, native storage, preview and retry behavior work without core edits |
| Extension contributes an order item or refund provider | Native calculation/eligibility plus the order PRD's financial intent, confirmation and reconciliation contract apply |
| Owner is deactivated after preview | Direct/cached/alternate entry points reject; data is retained and old token stays invalid after reactivation |
| Two plugins register the same tool | Existing ownership is preserved; conflicting contribution is unavailable and diagnosed |
| Writer lacks rollback or provider duplicate protection | Unsafe operation is unsupported before mutation |
| A catchable integration exception occurs | Safe attributable failure; unrelated valid core/extension tools continue |

## Delivery and verification

Deliver the registry, public contract documentation, compatibility adapter, controlled fixture extension and reusable contract suite together. Release must exercise [QA-052–QA-061 and QA-X10](08-acceptance-test-matrix.md), including direct/default-server execution and users with only own-order access.

Initial framework acceptance requires representative settings, builder, listing/taxonomy and order/provider fixture adapters. It does not require implementing every commercial extension. No additional extension was installed, activated or certified by preparing this PRD.
