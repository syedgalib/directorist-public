# PRD 13 — Site Health Info

Status: initial implementation of T51–T53 is available for review; acceptance criteria remain normative release requirements. See the [implementation guide](../../docs/mcp/dashboard-health.md). Related: [overview](README.md), [shared access rules](06-access-control-and-write-safety-prd.md), [catalog](07-mcp-tool-catalog.md#site-health-info-tools-proposed), [acceptance matrix](08-acceptance-test-matrix.md), [extension framework](11-extension-extensibility-prd.md). See the [implementation review and repairs](../../docs/mcp/dashboard-health-review.md).

## Product outcome

An authorized administrator can ask an assistant “Show the site's environment information” or “Prepare a Directorist support report” and receive a structured, redacted view of WordPress Site Health Info and Directorist compatibility information. Results identify their source, runtime and collection time so support can distinguish an observed fact from a compatibility assessment or unavailable value.

This feature covers the information shown under WordPress Tools → Site Health → Info, adapted for safe MCP access. Running Site Health Status tests, repairing the site, refreshing updates and changing configuration are separate workflows and outside this release. The existing Directorist endpoint, credentials, enablement and registry remain the entry point.

## Existing sources and integration constraints

The inspected [Info screen](https://github.com/WordPress/WordPress/blob/master/wp-admin/site-health-info.php) loads WordPress debug information and calls update-check functions. [WP_Debug_Data](https://github.com/WordPress/WordPress/blob/master/wp-admin/includes/class-wp-debug-data.php) assembles WordPress, server, database, theme/plugin, constants, filesystem and media sections, then applies `debug_information`. Its `value` and optional `debug` representations may differ. A section or field can be marked `private`; the native formatter omits such content from copied output.

Private flags alone are insufficient for remote exposure. Some unflagged values contain absolute paths; filtered contributions may contain arbitrary sensitive text. The inspected collector can make a WordPress.org request, and directory-size collection can recurse over disk. Do not call the entire Info screen, `check_for_updates()`, or the unadapted full collector to answer a narrow read. Some collection methods are private PHP methods; do not use reflection to bypass their visibility. Use supported, bounded local helpers or explicit source adapters and project only approved fields.

The [Site Health screen](https://github.com/WordPress/WordPress/blob/master/wp-admin/site-health.php) requires `view_site_health_checks`. Its effective mapping is defined by WordPress [capability handling](https://github.com/WordPress/WordPress/blob/master/wp-includes/capabilities.php). Directorist adds the `manage_options` floor below. No role or capability is granted to make this feature available.

## Scope and prerequisites

- Enabled/ready Directorist MCP, live authentication, `manage_options` **and** effective `view_site_health_checks`, plus source-specific permissions, are required for all three tools. Role names are examples only. Owners, purchasers and ordinary editors receive no Site Health access through their existing domain admission.
- Scope is the currently addressed WordPress site/blog. No `blog_id`, network enumeration, user override or cross-site collector is accepted. In multisite, shared host/network-wide inventory also requires super-administrator authority; a custom role with the two site capabilities cannot use site access to enumerate network plugins, users or other sites.
- Core information covers safe WordPress configuration, current request runtime, approved server/database capabilities, active theme and plugin inventories, media capabilities, selected boolean constants, filesystem writability, Directorist and MCP dependency/readiness facts, and cached update observations.
- Full arbitrary options/constants, environment variables, configuration files, logs, filesystem browsing, database rows, `phpinfo()`, telemetry, user/customer/listing data, licenses, account details, credentials and private source fields are excluded.
- No status-test runner, loopback/HTTP/DNS probe, update check, cron execution/reschedule, upload-directory creation, recursive size scan, plugin/account refresh, gateway call, repair, install/update/activate action or settings write is triggered by these tools.
- Optional directory/storage sizes may be shown only from already available bounded local snapshots, with their measurement time and explicit unavailable state if absent. Do not schedule or trigger a scan to fill the result.
- No new settings control or UI is required. Tool discovery, context and the existing MCP Configure panel's group summary show Site Health Info only when eligible and implemented.

## Proposed interfaces

| ID | MCP wire name | Inputs | Result |
|---|---|---|---|
| T51 | `directorist-discover-site-health-info` | Optional `section_id`, `page`, `per_page` | Eligible section/field descriptors, types, safe classifications, collection limits, source availability and supported report formats; no raw values |
| T52 | `directorist-get-site-health-info` | Required discovered `section_id`; optional explicit `field_keys` (1–100), `page`, `per_page` | One typed redacted section, authorized field/item pagination, source/version/freshness, safe unavailable reasons |
| T53 | `directorist-get-site-health-report` | Optional explicit `section_ids` (1–10); `format`: json/text, default text | One bounded redacted support report, included sections, coverage/omission summary and collection metadata; returned in MCP response only |

Schemas reject unknown arguments, duplicate selectors, invalid keys and invalid paging. Pagination defaults to 20 and caps at 100. T51 paginates section descriptors, or field descriptors when `section_id` is supplied. T52 applies paging to visible fields for scalar sections and to visible inventory items for collection sections, after optional field selection. Return authorized `total`, `total_pages` and `has_more`, with stable field/item-key order; hidden entries do not contribute to totals. Selectors use stable machine keys, not translated labels.

All three tools are read-only. They require no preview token, idempotency key or operation record, and cannot be invoked as mutation actions through a generic extension writer. Existing dedicated/default/direct ability paths recheck the same permissions and redaction policy.

## Section and field policy

The implementation publishes an explicit versioned field allowlist. The following table is the minimum content contract; optional/unavailable facts are identified rather than guessed.

| Section ID | Included safe facts | Excluded or restricted facts |
|---|---|---|
| `wordpress` | Installed WordPress version, site/user locale, timezone, permalink mode, environment type, HTTPS configuration, multisite flag | Home/site URLs, administrator email, user/site/network counts and registration identities; configured HTTPS is not a successful connectivity test |
| `runtime` | Current request PHP version/SAPI/bitness, WordPress/PHP memory limits, execution/input limits, upload/post size limits, approved extension availability | Arbitrary ini values, process environment, session storage paths, disabled-function dumps and secrets in ini text |
| `server` | Normalized server family/version and OS family/architecture when safely available; approved transport/library capabilities | Hostname, IP, socket, internal domains, kernel/build banners containing identifiers, raw server headers and paths |
| `database` | Database engine/server/client version, driver, approved numeric resource limits | Database username/password/host/name/prefix, connection strings, table names/rows and private fields, including those flagged private by WordPress |
| `themes` | Active/parent theme names, safe logical identifiers and installed versions; explicitly selected inactive inventory | Theme directories, author contact details, authenticated update/package URLs and license metadata |
| `plugins` | Active, inactive and must-use plugin names, safe logical identifiers, versions, activation category and permitted cached update state | Absolute plugin paths, full plugin basenames where they reveal directories, download/package URLs, license/account payloads; multisite shared inventory requires network authority |
| `media` | Approved image-library versions, supported formats, upload limits and boolean processing capabilities | Attachment inventories, image/file paths, delegate executable locations and raw library configuration dumps |
| `constants` | Explicit approved booleans such as debug/display/cache/cron configuration and approved memory limits | Keys/salts, database constants, arbitrary constant lookup, absolute directory constants and debug-log file location; expose log enabled/disabled/unknown only |
| `filesystem` | Boolean writable/not-writable/unknown for logical WordPress/content/uploads/plugins/themes areas | Absolute paths, file names/content, permission-owner identifiers, write-probe files and directory traversal |
| `storage` | Previously measured local size values in bytes and their measurement timestamps where safely available | On-demand recursion, new database-wide scans, path disclosure and interpreting a missing snapshot as zero |
| `directorist` | Installed core version, directory mode, relevant native service/storage adapter availability and compatible registered extension versions | Raw settings, listing/customer/order counts or content, payment/provider configuration, licenses and account connection data |
| `mcp` | Installed adapter/API versions, enablement/readiness, supported transport/authentication mode and safe compatibility reasons | Application passwords, Authorization headers, tokens, private client profiles, stored snippets and arbitrary access-policy values |

Default report sections are `wordpress`, `runtime`, `directorist` and `mcp`. Broader server/database/plugin inventories require explicit section selection and their source permission. T52 plugin inventory defaults to active and must-use records; inactive plugin inventory uses a separately discovered section variant, `plugins-inactive`, with the same protections. Theme inactive inventory similarly uses `themes-inactive`. T51 advertises these variants only when supported. T53 never expands a selection implicitly to installed plugins, inactive themes or network information.

Installed versions are observations, not assertions that a product is secure or fully compatible. Compatibility assessments use installed manifests, supported source adapter versions and the documented MCP requirements; expose `supported`, `unsupported` or `unknown` with the rule/source version. Never infer extension compatibility solely from activation. A cached update indicator carries its source observation time; absence of an update transient means `unknown`, not “up to date.” No fresh remote update request is made.

## Privacy and response behavior

1. Check live feature, identity, capabilities and section permission before collecting source values. If an entire requested section is forbidden, return `forbidden` without names, counts or partial data from it.
2. Honor `private: true` on sections and fields as a mandatory exclusion. This applies to `value`, `debug`, nested values, descriptions, labels, errors and text reports. There is no `include_private`, raw or unredacted mode, even for administrators.
3. Apply the Directorist allowlist and typed projection in addition to native private flags. Unknown fields are excluded by default; a missing private flag never means a field is safe. Source `debug` is not a fallback route around redaction.
4. Sanitize approved strings and nested values before caching or formatting. Prevent path/credential/connection-string/header leakage in unexpected version banners, plugin names, labels and callback errors. If a value cannot be safely normalized, return null with a safe unavailable/redacted state rather than its raw form.
5. Return section/field states `available`, `empty`, `unavailable` or `redacted` as applicable. Private/forbidden field identities are omitted, not enumerated; redacted state is only for a previously public approved descriptor whose value cannot safely be emitted. Omission summaries use safe categories, not private keys or raw values.
6. Treat all source/plugin text as untrusted data. No embedded instruction, URL, markup or shell command can trigger another tool. Response and report text contain no executable HTML, nonce, authentication token or signed URL.

Envelopes include `schema_version`, current-site opaque scope identifier, `generated_at` UTC, `source_versions`, `redaction_policy_version`, `request_id` and per-section `as_of`, `cached`, `age_seconds` and availability. Runtime facts identify `PHP_SAPI` and `observed_runtime` from the request executing the tool; never substitute a CLI PHP version for the web worker's version. A direct CLI invocation is explicitly labeled CLI.

## Report, bounds and freshness

T53 serializes the same safe typed projection used by T52 into structured JSON or plain text with stable section/field keys, readable labels and units. It returns content directly; it does not write a file, create a public URL, email/upload the report, call a support endpoint or copy data to an external service. If the user later requests sharing, that is a separate task and authorization.

An explicitly selected multi-section report is validated for permissions and supported sections before collection. Optional unavailable sources appear as unavailable with safe reasons; a failed required collector returns a safe tool error. No report is described as complete while concealing a failed source or omitted inventory page. A report is a bounded support snapshot, not a signed forensic record or whole-site export.

Limit reports to 10 sections, 1,000 emitted fields/items in aggregate and 256 KiB of UTF-8 serialized content. Approved scalar strings cap at 2,048 characters; registered nested structures cap at depth three and 100 entries per object/array. Do not silently cut off a version/diagnostic value and claim accuracy: oversized or malformed values become unavailable. A report exceeding its aggregate bounds returns `validation_failed` with safe narrowing guidance; it does not return a seemingly complete truncated report. T52 pagination remains available for larger inventories.

Use request-local caching first. Optional persistent caching may retain only redacted projections for at most 60 seconds, scoped to site/blog, actor, authorization fingerprint, locale, source/registry/schema and redaction-policy versions and exact selectors. Runtime/PHP facts are request-local and must not be reused across SAPIs or worker configurations. Recheck permissions and source availability on every cache hit. Invalidate affected entries on plugin/theme/core updates, extension lifecycle changes and relevant configuration changes. Never cache raw debug arrays, credential-bearing payloads or full report contents in logs. Report bodies are not persisted beyond this optional projection cache.

Reads perform no product writes, outbound network requests, status tests or disk scans. Shared read guards must enforce SQL-write and HTTP denial for collectors, including contributed callbacks; no broad GET exception is allowed for Site Health. Cache infrastructure cannot bypass the existing read guard: prefer uncached collection if it cannot safely populate the cache. Native scheduled update checks may change cached observations independently; this tool only reads those observations.

## Extension contributions

PRD 11 governs ownership, admission, versioning, schemas, lifecycle and fault isolation. The additive read-adapter API 1 now implements the versioned `site_health_info` read-only contract without changing existing extension tool/field descriptors.

An owner registers a namespaced section/key, label, source/contract versions, bounded field schemas, explicit safe sensitivity classifications, supported site/network scope, source availability, mandatory permission, read/projection callbacks and cache/invalidation rules. No write callback, arbitrary file/SQL collector or private field exposure is accepted. An extension can add restrictions but cannot bypass `manage_options`, native Site Health access, multisite isolation or core redaction rules.

`debug_information`-only contributions are not automatically exposed. A registered adapter can map approved native contribution fields only through an audited read-only collector; the presence of a WordPress hook does not authorize running every hook to discover information. Core allowlists cannot be replaced by an extension. Deactivation removes eligible schema/data and invalidates cached contributions without deleting the extension's product data. Malformed/private callback output fails safely for that contribution while unrelated sections remain usable.

## User stories and acceptance criteria

Each story requires the shared prerequisites and live source permissions. Criteria specify happy paths and failures in Given/When/Then form.

### SHI-01 — Discover available Site Health information

As an administrator, I want to discover supported sections and fields so that I can request relevant diagnostics without guessing private keys or unsupported inputs.

Behavior: T51 reports the safe typed schema, selectors, bounds, sources and availability. Discovery does not run health tests, fetch remote data or reveal hidden field identities.

- **SHI-01-AC1:** Given an eligible administrator, when requesting discovery, then typed safe section/field descriptors, stable keys, limits and report formats are returned without source values or private keys.
- **SHI-01-AC2:** Given the implemented registered inventory, when inspecting tools, context and the connection group summary, then eligible T51–T53 and contributed sections agree with the live registry.
- **SHI-01-AC3:** Given an unknown selector, duplicate key or page size 101, when requesting information, then explicit validation errors are returned; default 20 and maximum 100 work without silent clamping.
- **SHI-01-AC4:** Given a missing source or unsupported runtime adapter, when discovering information, then a safe unavailable reason is shown for public supported descriptors and no private inventory is disclosed.

### SHI-02 — Inspect the current environment

As an administrator, I want current WordPress and server information so that I can troubleshoot Directorist behavior using the environment that actually serves the request.

Behavior: T52 returns approved local facts, typed limits/units, honest unavailable states and explicit runtime identity. No all-options, ini or raw debug dump is returned.

- **SHI-02-AC1:** Given known WordPress/runtime/database/media fixtures, when reading each approved section, then returned versions, typed booleans and limits match the allowed native sources with source/time metadata.
- **SHI-02-AC2:** Given different CLI and web PHP configurations, when invoking through each entry point, then the result identifies that request's SAPI/runtime and never serves the other runtime's cached values.
- **SHI-02-AC3:** Given absent media capabilities, denied filesystem observation or missing size snapshots, when reading, then values are unavailable/unknown rather than zero, false success or invented measurements.
- **SHI-02-AC4:** Given debug-log configuration containing a file path, when reading constants, then only the allowed enabled/disabled/unknown fact appears and no path or arbitrary constant can be retrieved.

### SHI-03 — Understand installed compatibility information

As an administrator, I want safe installed component and dependency information so that I can identify unsupported combinations without changing the site's software.

Behavior: Project safe installed identities/versions and explicit compatibility rules. Read cached update observations only. Plugin/theme inventories remain bounded and separately selected.

- **SHI-03-AC1:** Given active/inactive/must-use plugins and a child/parent theme, when requesting eligible inventories, then safe identities, versions and categories paginate deterministically with no package URL, absolute path or license payload.
- **SHI-03-AC2:** Given known Directorist/MCP dependency requirements and a component without a compatibility rule, when reading, then supported/unsupported results cite their rule/source version and the undeclared combination is unknown, not inferred compatible.
- **SHI-03-AC3:** Given a stale or absent update transient, when inspecting update information, then the observation age or unknown state is explicit and no network update refresh occurs.
- **SHI-03-AC4:** Given default report selection, when building the report, then inactive software and broader inventories are excluded until explicitly requested; selection never expands silently to network data.

### SHI-04 — Protect sensitive information

As a site administrator, I want diagnostics consistently redacted so that troubleshooting cannot disclose credentials, infrastructure identifiers or private data through an assistant.

Behavior: Native private flags and stricter Directorist field allowlists apply before output, caching and formatting. There is no raw/private opt-in.

- **SHI-04-AC1:** Given private sections/fields with different value/debug content, when reading schema, JSON or text output, then neither representation, private key nor hidden count is exposed.
- **SHI-04-AC2:** Given secrets, paths, database identifiers or signed URLs embedded in an unflagged field, nested value, label or exception, when projecting data, then allowlisting/normalization omits or safely marks the value unavailable without leaking it in output/cache/logs.
- **SHI-04-AC3:** Given include_private, arbitrary constant/option/file or raw-output parameters, when called, then validation rejects them even for a super administrator and no collector is invoked for them.
- **SHI-04-AC4:** Given instruction-like plugin text or executable markup, when returning an allowed field, then it remains inert data and triggers no tool, shell command, fetch or permission change.

### SHI-05 — Prepare a safe support report

As an administrator, I want a portable Site Health Info report so that I can review diagnostic information before sharing it with support.

Behavior: T53 returns the shared safe projection as JSON or plain text, with clear coverage. It performs no transmission or file publication.

- **SHI-05-AC1:** Given the same stable selected sources, when requesting JSON and text reports, then both contain equivalent redacted facts, source/time/runtime metadata and safe coverage summaries.
- **SHI-05-AC2:** Given a forbidden section mixed with permitted ones, when requesting a report, then validation denies the selection before source collection and no partial protected report is returned.
- **SHI-05-AC3:** Given unavailable optional data or aggregate output above 10 sections, 1,000 fields/items or 256 KiB, when building a report, then missing coverage is explicit or the bound error requests narrower selection; no truncated report is labeled complete.
- **SHI-05-AC4:** Given a successful report, when examining filesystem, mail, outbound requests and report storage, then only the MCP response and any permitted redacted projection cache exist; no file, upload, email, support submission or public URL is created.

### SHI-06 — Extend safe diagnostic sections

As an extension developer, I want a versioned diagnostic contribution contract so that support can inspect my extension's approved environment facts through Directorist's existing MCP controls.

Behavior: Explicit read-only registration supplies schemas, source versions, permissions and classifications. Existing WordPress debug hooks alone do not register MCP exposure.

- **SHI-06-AC1:** Given a compatible registered diagnostic fixture, when discovered/read, then only its approved namespaced fields appear for users passing all core and contributed checks.
- **SHI-06-AC2:** Given a collision, unsupported contract, missing classification/permission, private field or write callback, when registering, then the contribution is rejected with safe administrator diagnostics and unrelated sections remain usable.
- **SHI-06-AC3:** Given only a debug_information contribution without MCP registration, when collecting diagnostics, then its keys/values are not automatically exposed and its callback is not run merely to enumerate MCP sections.
- **SHI-06-AC4:** Given deactivation, a contract change, callback failure or attempted hidden SQL/HTTP operation, when reading cached or fresh data, then access/cache invalidation and safe fault isolation apply with no data deletion, external request or product mutation.

### SHI-07 — Enforce capabilities and multisite boundaries

As a site administrator, I want Site Health access checked on every request so that domain access or cached credentials cannot expose administrative infrastructure information.

Behavior: Both required effective capabilities and source/network rules apply before collection and cache hits, including all alternate ability paths. No user is elevated.

- **SHI-07-AC1:** Given an owner, editor, order-only purchaser, anonymous user or custom role missing either required capability, when calling any tool by discovery or direct invocation, then Site Health schema/data/report access is denied without infrastructure details.
- **SHI-07-AC2:** Given a custom role with both effective capabilities, when reading eligible current-site sections, then access follows those capabilities rather than role name and still obeys additional source restrictions.
- **SHI-07-AC3:** Given permission revocation, disabled MCP, missing required dependency or an existing session/warm cache, when invoking through dedicated/default/direct ability paths, then no protected data is returned and ordinary Directorist behavior remains available.
- **SHI-07-AC4:** Given multisite and a site-scoped caller, when requesting cross-site selectors or shared network/host inventories without network authority, then the request is rejected; separate sites/actors never share cached diagnostic data.

### SHI-08 — Keep reads fresh, bounded and nonmutating

As an administrator, I want bounded local diagnostic reads with clear freshness so that asking for support information does not run tests, alter the site or start expensive scans.

Behavior: Reuse safe local source adapters and request-local facts. Optional persistent cache retains redacted projections only and cannot override live permissions or source availability.

- **SHI-08-AC1:** Given instrumented diagnostic sources, when invoking every read/report, then no external HTTP/DNS probe, update/status test, cron/scan, directory creation, settings/operation write or provider action occurs.
- **SHI-08-AC2:** Given cached projections and a configuration/software/permission change, when reading again, then affected entries are invalidated or rejected by current checks; ordinary projection age never exceeds 60 seconds and runtime facts remain request-local.
- **SHI-08-AC3:** Given oversized/malformed source output, when validating, then the offending value/contribution becomes safely unavailable or fails with a bounded error; source/output schemas enforce declared string, nesting, item and response limits.
- **SHI-08-AC4:** Given a documented large-inventory fixture and missing/slow optional collectors, when requesting a narrow section, then collection avoids unrelated sources and satisfies the published query/time budget with honest availability and no full-site disk or database scan.

## Feasibility and compatibility

**Straightforward reuse:** WordPress's native information taxonomy, local version/configuration helpers, current capability evaluation and existing Directorist registry/response/read-guard conventions. Reuse privacy semantics while applying a stricter remote allowlist.

**Risky areas:** Whole-collector calls have broader work/side effects than a narrow MCP request; arbitrary debug filters are trusted installed PHP, not safe remote data by default. Private flags do not classify every path or banner. Native methods may be private or vary across WordPress versions. Add supported source adapters rather than invoking private methods or assuming current field layouts on the minimum supported release.

**Affected systems:** MCP tool inventory/dispatch/abilities, per-tool admission, section collectors/projectors, cache invalidation, safe text/JSON formatting, proposed extension adapter and controlled fixtures. Preserve all native Site Health screens/hooks/capabilities, Directorist settings, MCP Configure UI and normal scheduled update behavior. No role grant, storage migration, telemetry integration or new UI is required.

**Release decisions:** Publish the initial field allowlist/redaction policy, adapter API version, supported minimum/current WordPress source mappings, multisite section restrictions and measured query/time budget. Unsupported facts remain unavailable. Plugin-installed PHP remains trusted code; the registration contract/read guard is not a general PHP sandbox.

## Verification and delivery

Run QA-071–QA-078 and QA-X12 in the [matrix](08-acceptance-test-matrix.md). Include ordinary administrators, custom dual-capability roles, revoked actors, owners/purchasers, multisite site/network actors, CLI/web runtimes, malicious private/debug fixtures, unsupported native layouts, large plugin inventories and a controlled extension. Observe network, filesystem, SQL writes, source invocation counts, cache contents and safe event logs in an isolated test site.

This documentation is source-based; it collects or stores no live Site Health dump, account/credential state or server paths. Focused local and HTTPS tests now cover T51–T53, with evidence in the implementation guide and verification record. Minimum-runtime, multisite and full matrix scenarios remain release criteria until separately executed. The earlier 45-tool results do not verify these additions.
