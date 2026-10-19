# PRD 06 — Access Control and Write Safety

Status: proposed V1; normative shared requirements for all feature PRDs. Related: [overview](README.md), [catalog](07-mcp-tool-catalog.md), [test matrix](08-acceptance-test-matrix.md).

Scope addition: [PRD 10](10-order-management-prd.md) and [PRD 11](11-extension-extensibility-prd.md) extend these rules to orders and registered extension contributions. Their initial runtime interfaces are available for review, with focused checks and remaining release gates recorded in the implementation notes.

[PRD 12](12-dashboard-data-prd.md) proposes read-only dashboard access. Its five tools apply the same live gates, including aggregate queries, cache hits and alternate ability entry points; dashboard discovery grants no new capability.

[PRD 13](13-site-health-info-prd.md) proposes three Site Health Info read tools. Each requires both `manage_options` and effective `view_site_health_checks`, plus source/network restrictions. Listing, purchaser or dashboard access does not grant Site Health access. Native private flags and the stricter Directorist allowlist apply to structured output, reports, caches and errors.

## Authorization model

The authenticated WordPress user is the actor. An application password identifies that user; it does not grant Directorist capabilities, provide per-tool scopes, or restrict the credential to Directorist APIs. Native role assignments, effective capabilities, ownership, object state, directory policy, and extension filters determine access on every request. Do not add capabilities to existing roles simply to introduce MCP.

Access requires: enabled/ready feature → authenticated live identity → meaningful Directorist capability → tool permission → object permission → field permission → native business-rule validation. No client annotation, natural-language instruction, role name, discovery result, or confirmation token substitutes for these checks.

### Capability matrix

Resolve post/taxonomy capability objects at runtime rather than hard-coding role names or assuming default assignments. The inspected default listing capability stem is `at_biz_dir`; custom roles and filters may change effective access.

| Operation | Required checks | Typical default-role outcome; not a role allowlist |
|---|---|---|
| Other Features → MCP dedicated field, Configure drawer, status/template reads, enable/disable | `manage_options`; nonce for admin requests; no submitted credentials from snippet inputs | Administrator |
| Read/change/reset settings | `manage_options`; additional registered field capabilities | Administrator |
| Full directory/builder management | `manage_options` | Administrator |
| Context and eligible directory/assignment references | Authenticated user with at least one meaningful Directorist management/submission capability; filter output | Authorized owners, staff, administrators |
| Public listing reads | Transport access plus site visibility and field policy | Eligible users; no bypass of login/membership restrictions |
| Own listing management search | Listing management/submission capability; restrict author and allowed statuses | Eligible owners |
| Others' listing management search | Runtime `edit_others_posts` equivalent plus per-record read/status rules | Editors/administrators if assigned |
| Protected listing detail | Object-level `current_user_can('read_post', id)` plus Directorist read/visibility policy | Own or delegated access only |
| Create listing | Runtime post-type `create_posts`/`edit_posts` mapping; directory/submission eligibility; current user as author | Eligible owners/staff |
| Update listing | Object-level `edit_post` plus field and submission/edit policy | Owner or staff with required others/status capabilities |
| Publish/change status | Object edit and runtime `publish_posts` or other native transition checks; moderation/entitlement rules | Only allowed transitions |
| Trash listing | Object-level `delete_post`; native lifecycle constraints; never hard-delete | Eligible owner/delegated staff |
| Restore listing | Object edit/delete and any required publication/status capability | Eligible owner/delegated staff |
| Taxonomy management read | Taxonomy `manage_terms` plus Directorist permission filters | Usually administrator; delegated capability may allow others |
| Create/update term | Taxonomy `edit_terms` and object checks where applicable, plus existing filters | Capability-dependent |
| Delete term | Taxonomy `delete_terms` and dependency checks, plus existing filters | Capability-dependent |
| Assign existing term | Object listing edit plus taxonomy `assign_terms` and directory compatibility | Eligible listing editors |
| Assignment-only term lookup | Relevant assignment capability plus directory/submission eligibility; display fields only | Eligible listing owners |
| Attach existing media | Object listing edit, native attachment authorization, field constraints | Eligible listing editors; no arbitrary library enumeration |
| Bulk | Envelope permission plus all applicable checks per item at preview and execution | No elevation from bulk access |
| Own-order schema/history/detail/payment/refund summaries | Native own-order eligibility, authenticated purchaser, object and field checks | Own purchases only; listing authorship is insufficient |
| Own pending-order cancellation | Native purchaser cancellation policy, pending state, preview and current version | Eligible purchaser |
| Staff order creation/edit/status/payment/refund/history | Native order administration, currently manage_options, plus object/action/provider policy | Administrator or custom role passing the same native gate |
| Order-operation reconciliation | Authorized actor plus linked-order visibility; purchaser limited to own cancellation | No repeated financial dispatch or cross-customer disclosure |
| Extension tool/field/provider | Valid active registration, narrow domain admission, mandatory core permission floors and additional checks | Registration grants no permission |
| Integration diagnostics | manage_options for private/unavailable diagnostics; ordinary users see only eligible active contributions | No installed-plugin inventory leak |
| Site dashboard metrics, activity, setup and recommendations | `manage_options`, source/object/field permissions; eligible directory/currency scope | Administrator or custom role passing the same gate |
| Own dashboard listings, favorites and optional enquiry KPIs | Native dashboard/listing eligibility plus current ownership, visibility and each source's read permission | Own authorized data only; no site counts or message bodies |
| Own dashboard purchase summary/activity | Native purchaser eligibility and PRD 10 ownership checks, independent of listing access | Order-only admission grants no listing/configuration/site access |
| Dashboard extension contribution | Active compatible registration, site/own core floors, source permission and safe projection | Read-only; cache and discovery cannot bypass current authorization |
| Site Health Info schema/sections/support report | `manage_options` and effective `view_site_health_checks`, source permission; shared network/host inventory additionally requires multisite super-administrator authority | Current addressed site only; no private/raw mode or cross-site selectors |
| Site Health diagnostic contribution | Active compatible read-only registration, all Site Health floors, field allowlist/private exclusions | No automatic exposure of arbitrary debug_information fields or hidden test/update execution |

The transport admits configuration, listing, taxonomy, native own-order or explicitly registered meaningful extension-domain access. General WordPress read is not blanket Directorist access: customer admission grants only native own-order reads/cancellation and safe context, including an empty own history. An extension admission predicate declares a narrow domain and cannot expose all core tools. Discovery, dedicated/default servers, direct abilities, bulk and replay all recheck live permissions.

## User stories and acceptance criteria

### SEC-01 — Enforce the feature and authentication boundary

As a site administrator, I want every invocation path to honor enablement and current identity so that disabling access cannot be bypassed through another server or API.

- **SEC-01-AC1:** Given disabled/unready MCP, when invoking a Directorist ability through the dedicated server, default adapter server, direct Abilities API, or an existing session, then execution is rejected before data is returned or changed.
- **SEC-01-AC2:** Given anonymous, invalid, revoked, or nonqualifying credentials, when connecting, then no protected context, schema, previews, or results are returned; ordinary native Directorist features remain usable.
- **SEC-01-AC3:** Given a valid user's capability removed after discovery or a preview, when executing, then current permissions apply and the former permission is not reused from a cache/token/session.

### SEC-02 — Enforce capabilities and ownership

As a user, I want MCP to respect my existing permissions so that neither I nor another user can act outside our assigned responsibilities.

- **SEC-02-AC1:** Given a custom role with the required capability but an unfamiliar role name, when invoking an eligible operation, then it succeeds under the same rules as a default role with that capability.
- **SEC-02-AC2:** Given owner A requesting owner B's protected record by ID, search, reference lookup, bulk item, or replay key, when access is evaluated, then the result reveals no protected record data or unauthorized aggregate count.
- **SEC-02-AC3:** Given an editor lacking `manage_options` or an owner lacking taxonomy edit capability, when calling configuration or term-creation tools directly, then the call is denied despite transport access.

### SEC-03 — Restrict fields and protect sensitive data

As a site administrator and listing owner, I want responses and writes limited to authorized fields so that credentials and private data are not exposed through an assistant.

- **SEC-03-AC1:** Given secret settings, private custom fields, contact visibility restrictions, or unsupported metadata, when reading schemas/details/previews/errors, then inaccessible values are excluded and no raw shared option/meta blob is returned.
- **SEC-03-AC2:** Given attempts to write arbitrary option/meta keys, owner IDs, directory transfers, capability changes, or paid entitlements, when validating, then unsupported or forbidden changes are rejected rather than ignored.
- **SEC-03-AC3:** Given imported text containing instructions to invoke another tool or disclose secrets, when returning listing/term content, then it is treated as untrusted data and never triggers an operation, broadens output, or changes authorization.

### SEC-04 — Preview destructive and high-impact changes

As an authorized operator, I want an accurate preview and bounded confirmation so that consequential changes use current data and match the reviewed action.

- **SEC-04-AC1:** Given a required-confirmation operation without a token, when execution is requested, then `confirmation_required` is returned and product data remains unchanged; a dry run returns a safe impact preview and five-minute token.
- **SEC-04-AC2:** Given a token with a different user/site/tool/payload, an expired/consumed token, or a changed relevant state, when executed, then it is rejected; confirmation cannot authorize additional objects or parameters.
- **SEC-04-AC3:** Given a valid token, when executing, then permissions, dependencies, and relevant object state are rechecked immediately before the write; token possession alone does not prove human approval.

### SEC-05 — Make retries and concurrent requests safe

As a user, I want retries to return known outcomes so that timeouts do not create duplicate records or repeat side effects.

- **SEC-05-AC1:** Given the same user/site/tool/idempotency key and normalized execution payload within 24 hours, when retried or submitted concurrently, then one logical mutation occurs and the saved outcome is returned with replay status.
- **SEC-05-AC2:** Given reuse of the key with a different execution payload, when requested, then `idempotency_conflict` is returned rather than applying a second action or returning the wrong cached result.
- **SEC-05-AC3:** Given permission loss before replay or an uncertain post-commit failure, when retried, then access is rechecked and no blind duplicate mutation occurs; return a safe known result or `outcome_unknown` with a read-based reconciliation step.

### SEC-06 — Validate complete changes and report precise failures

As a user, I want deterministic validation and error details so that I can correct a failed task without guessing what changed.

- **SEC-06-AC1:** Given unknown parameters, invalid types/ranges, unsupported actions, or invalid references, when a single settings/builder/record change is submitted, then it fails before persistence with field-level errors and no partial logical change.
- **SEC-06-AC2:** Given a valid bulk envelope containing both eligible and invalid items, when previewed/executed, then outcomes are independent per item; the response never labels the batch wholly successful while hiding failures.
- **SEC-06-AC3:** Given an exception or side-effect failure, when reporting, then the result identifies whether persistence committed, failed, or is uncertain and supplies a safe next step without stack traces, credentials, or internal filesystem paths.

### SEC-07 — Produce safe operation events

As a site operator, I want attributable operation events so that a site's logging integration can investigate MCP activity.

- **SEC-07-AC1:** Given an attempted mutation, when it completes or fails, then an integration event records timestamp, site/user, tool/action, safe target IDs, request correlation, outcome, and error code without full content or secrets.
- **SEC-07-AC2:** Given a bulk operation, when events are emitted, then item outcomes are correlated to the batch and idempotent replays are distinguishable from new commits.
- **SEC-07-AC3:** Given discovery/preview/validation or a replay, when no product write occurs, then no event claims that data was changed; logs never include authorization headers, application passwords, confirmation tokens, or full idempotency keys.

## Preview policy

| Action | Confirmation required? |
|---|---|
| Settings update/reset; directory create/duplicate/update/default/delete; all builder changes | Yes |
| Listing create resulting in public publication | Yes, including when native defaults would publish without an explicit status input |
| Listing create resulting in draft/pending; ordinary supported single-record content edit | Optional preview; no mandatory token |
| Explicit listing status transition, trash, restore | Yes |
| Term deletion; changes to an existing slug, parent, or directory association | Yes |
| Single-term creation; ordinary term name/description/supported-image update | Optional preview; no mandatory token |
| Every bulk execution | Yes, regardless of individual action type |
| Every order creation/edit/status/cancellation, offline payment record and refund | Yes; mode, amount/currency, native consequences and provider state must be explicit |
| Extension mutation | At least the host-domain/core requirement; destructive, financial or externally consequential actions always require confirmation |

All mutation tools accept `dry_run`. A preview must include normalized proposed changes, safe before/after data, relevant dependency counts, warnings, and eligible/failed items. Preview may write short-lived operational token state, but must not alter product data or fire mutation hooks. Confirmation is a server-side integrity guard, not a claim that MCP can prove a human clicked approval; clients should present consequential previews to the user.

A token binds normalized mutation inputs and affected state, including authorization context, schema/dependency versions, server enablement epoch, and dependency relationships. Disabling/re-enabling MCP invalidates old previews. Single-object state mismatch blocks that operation. For a bulk token, unchanged eligible items may execute while changed items fail individually; the original complete item list and each previewed item fingerprint remain bound. No new item may be added at execution.

Order tokens also bind payment/refund balance, monetary/fulfillment policy and provider contract. Extension tokens bind owner, contract/schema revision, dependencies and lifecycle fingerprint. Reactivation must not revive old tokens. Registered callbacks add checks without replacing core administration, purchaser/ownership, sensitive-field or enablement checks.

## Retry, consistency, and batch contract

Execution requires an idempotency key on every mutation. Preview does not. Scope keys to the site, user, tool, and complete normalized execution payload; exclude only transport controls (`dry_run`, token, key) from the mutation-payload digest. Keep the outcomes for 24 hours from commit. A valid replay is resolved after fresh authentication, enablement, and current output/object authorization but before demanding a consumed confirmation token. An expired replay window requires a fresh read/preview and new key.

Reserve/serialize logical operations so simultaneous requests cannot both commit. Transient-cache read-then-write alone is insufficient as a concurrency guarantee. Persist enough safe per-item progress to reconcile a partially completed bulk call. Do not promise exactly-once external delivery: native notifications/hooks can fail after a commit. Mark such outcomes committed-with-warning or indeterminate, prohibit blind retries, and provide a read-based reconciliation route.

For existing objects, require an opaque version/fingerprint from a current read or preview; compare relevant state at commit. Settings and builder changes validate as complete units. Listing/term changes validate as one record unit. Bulk is sequential, deterministic request order, with per-record commits and independent errors; revalidate each item against state produced by earlier successful items. Limit each batch to one action and, for terms, one taxonomy; no nested batches or cross-item creation references. Require unique `client_ref` values.

Deletion checks must be coordinated with concurrent assignments/creation, not only checked once during preview. Do not implement safety by suppressing native hooks or bypassing business services. If an adapter cannot guarantee a requested logical update safely, return `unsupported_operation` rather than executing a partial configuration change.

Order/extension adapters must declare custom tables, caches, locks and native writers before claiming transactional safety; the current core-table guard does not cover every such store. External dispatch is a separate phase with durable intent, unique provider reference and read-only reconciliation. Database rollback cannot reverse a transfer. Pending/unknown effects prohibit blind retries even after the 24-hour ordinary replay window; native financial deduplication references survive that window.

Orders remain protected from hard deletion and settled-ledger edits. Generic listing/settings/extension tools cannot fabricate paid/refunded state or grant entitlements directly. Only an explicitly authorized native order/provider workflow may cause native fulfillment effects. See PRD 10 for money precision, refund reservations and explicit modes.

## Privacy, errors, and observability

Return `not_found` uniformly for absent and inaccessible protected object IDs after the caller has passed the tool-level permission gate. Do not leak existence through dependency names, filtered totals, previews, errors, or idempotency replay. Public visibility remains governed by Directorist and installed extensions.

Errors use the [catalog's common contract](07-mcp-tool-catalog.md). Rate/size constraints are server-enforced. Field content remains data, including markup sanitized through native policies; no returned content is executed as code. Safe operation events are delivered through a documented Directorist integration hook. Durable storage, retention, dashboards, and third-party delivery are the site's logging integration responsibility; V1 does not add an audit-log database or UI.

The MCP Connection drawer may render a completed snippet from credentials the administrator enters locally. This does not authorize reading a stored secret or posting it to a Directorist service. Keep those inputs and rendered snippets in short-lived component memory, out of the shared settings store and all request/log/export payloads. Copying a completed snippet is an explicit user action; closing, navigation or permission loss clears the password and completed snippets. See [PRD 01](01-mcp-settings-and-setup-prd.md) for the dedicated-field contract.

## Release dependencies

Authorization must run inside each registered ability as well as the transport. Tests must cover the dedicated server, default adapter execution, direct ability calls, stale caches, multiple users, changed capabilities, revoked credentials, disabled/re-enabled feature, and concurrent write attempts. See the [acceptance matrix](08-acceptance-test-matrix.md).
