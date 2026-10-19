# Dashboard data and Site Health Info

Implemented in Directorist core on 19 October 2026 under PRDs 12 and 13. Eight read tools expand the core catalog to **53 tools**, plus explicitly registered extension tools. The feature retains its disabled default, existing authentication, dedicated endpoint and Configure panel. Settings keys, save behavior, assets, role grants and native screen behavior are preserved. The canonical setup service has a companion setup-details read used by MCP; its default UI contract is unchanged. See the [PRD review and repairs](dashboard-health-review.md).

## Dashboard interfaces

| Tool | Use |
|---|---|
| `directorist-get-dashboard-schema` | Discover caller-specific scopes, sections, metric meanings, filters, contributions and limits |
| `directorist-get-dashboard-overview` | Read selected listing health, cumulative views, purchase values, favorites and optional-source states |
| `directorist-get-dashboard-series` | Read currently published listings by post date, exact paid-order value by local creation date, or the explicitly labeled current view distribution |
| `directorist-list-dashboard-activity` | Read authorized current-record activity and upcoming expiry reminders within a bounded recent window |
| `directorist-get-dashboard-guidance` | Read native setup predicates, eligible directory/own navigation and registered optional guidance |

`scope` defaults to `site` for effective `manage_options`, otherwise `own`. Explicit unauthorized site scope is rejected. Listing data requires native listing access and object read permission, including the native restrictive permission filter. Purchases use purchaser identity independently of listing ownership. An order-only customer receives only own purchase data, including an empty history. No author override exists.

Dates use the site timezone. Presets cover 7/30/90 local dates; custom inclusive endpoints cover at most 90 days. Previous comparison scans at most another equally sized window. Current stock and lifetime views are labeled separately from period activity. Missing daily analytics remains unavailable; the current view distribution uses equal-count groups of listings ranked by lifetime views, with no invented daily dates or traffic comparison. Extensions can register their own measured metric under its namespaced identifier.

Financial summaries use the native stored `amount`, explicit status and recorded currency; they are paid-order value rather than verified cash receipts, profit or owner earnings. Exact decimal totals are separated into at most 20 currencies. SQL uses `FROM_UNIXTIME` boundaries and `UNIX_TIMESTAMP` projection for native TIMESTAMP columns, preserving correct instants even with a non-UTC database session. Daily CASE ranges are computed from site-local calendar boundaries, including DST; MySQL timezone tables are not required. Ambiguous legacy mappings are excluded with incomplete coverage. Unmigrated legacy orders lack an authoritative recorded currency adapter and are not guessed or silently included. Native migrations and provider calls never run.

Directory filtering is supported for listing sections/series/activity and site quick actions. Select those sections/types explicitly; financial, favorites, setup, review and user sources reject directory allocation. This avoids quietly substituting global totals or allocating a multi-directory purchase without a native rule.

Listings are read in 128-record chunks with current object permissions before aggregation. The native permission-scan budget is **20,000 candidate listings/reviews per request**; exceeding it returns `unsupported_operation`, requiring a narrower directory/source. It never returns a seemingly complete partial count. Activity returns separate `items` (past) and `upcoming.items` (expiry reminders), each with a maximum 1,000-item authorized window, pages of at most 100 and its own `window_total`, truncation and continuation limit. Past timestamps stop at the response observation time. Equal timestamps sort by source namespace and numeric object ID, matching native query cursors. A full past window cannot suppress upcoming reminders. Its order-paid entry is labeled a current paid purchase by creation date, not a reconstructed settlement event. Reviews require native moderation and comment access; user activity requires site scope and user-list authority and is excluded on multisite. This is a current-record feed, not a permanent audit log.

Native setup uses the public local setup resolver only, without full metric/page/account rendering. Completion steps/progress remain available after the native setup window expires; expiry and dismissal affect visibility independently. Dismissal is read without changing it. Action tool references are included only when discoverable for the caller. Configuration predicates do not certify an operational gateway. Quick actions are descriptors and safe internal URLs, never executions. Recommendations and enquiry KPIs remain explicitly unavailable without a compatible registered source; no account refresh, marketing fetch or message-body export occurs.

## Site Health Info interfaces

| Tool | Use |
|---|---|
| `directorist-discover-site-health-info` | Discover approved section/field descriptors without source values |
| `directorist-get-site-health-info` | Read one selected approved section with field/item pagination |
| `directorist-get-site-health-report` | Return the same redacted information as JSON or plain text directly in the MCP result |

All three require both effective `manage_options` and `view_site_health_checks`. These checks apply on every call, including direct abilities. Scope is the addressed site only; no cross-site selector exists. On multisite, shared runtime, server, database, broader plugin/theme, filesystem and storage inventories require super-administrator authority. Default reports intersect the safe default sections with the caller's permitted current-site sections.

Collectors use bounded local helpers instead of the WordPress Info page, full `WP_Debug_Data` collector, arbitrary `debug_information` callbacks or status-test routes. Approved information includes local versions, locale/timezone, PHP SAPI/limits, normalized server family, safe database versions/resource limits, plugin/theme versions, approved media capabilities, selected boolean constants, non-probing filesystem writability, Directorist/native-storage availability and MCP dependencies. Field discovery returns complete bounded schemas, readable labels and resource units in stable key order. Selecting fields preserves inventory order even when `id` is omitted. Regular and must-use plugins have separate identities even when filenames match. Manifest compatibility assessments are explicitly limited to installed WordPress/PHP minimum requirements; undeclared or malformed rules are unknown, and activation is never proof of compatibility. Cached update observations carry their original observation time and age; missing observations are unknown rather than “up to date.” No update refresh is initiated.

Absolute paths, database connection identities/prefix, site/account URLs, passwords, keys, licenses, environment/config dumps, private/debug properties, signed package links and source exception payloads are excluded. Unknown fields are not automatically imported. Registration rejects private diagnostic properties; projected strings and nested values have additional privacy validation. Unsupported/malformed facts become unavailable or redacted. Runtime facts identify the process actually executing the request, so CLI facts do not masquerade as web-worker facts.

Reports default to WordPress/runtime/Directorist/MCP information and accept at most 10 explicit sections, 1,000 fields/items and 256 KiB of serialized content. Larger selections fail with narrowing guidance rather than a truncated “complete” report. Inactive inventories require explicit selection. Successful empty inventories/facts use `empty` and do not imply missing coverage. Coverage is incomplete when an included source/fact is unavailable or redacted. Redaction policy 1.1 also handles short root paths, UNC shares, IPv6 and private-domain banners; scalar limits count Unicode characters. Restricted/missing filesystem areas return unknown without path-bearing warnings. Text/JSON share one safe projection. Reports create no file, public URL, support upload or email. Storage sizes remain unavailable without a supported existing snapshot; no scan is initiated.

## Read adapter API 1

The existing extension API major remains 1. The additive contract is `Registry::READ_ADAPTER_VERSION = 1`, registered through `register_read_adapter()` inside `directorist_mcp_register`, after `register_extension()`. Existing tool/field descriptors retain their meaning.

```php
$registry->register_read_adapter( [
    'owner'            => 'my-extension', // Previously registered active owner.
    'key'              => 'environment', // Exposed as my-extension-environment.
    'domain'           => 'site_health_info', // Or dashboard.
    'api_version'      => 1,
    'label'            => 'Extension environment',
    'contract_version' => '1',
    'source_version'   => '1',
    'units'            => 'capability',
    'range_semantics'  => 'current',
    'sensitivity'      => 'public',
    'cache'            => 'none',
    'kind'             => 'section', // Dashboard: overview, metric or guidance.
    'scopes'           => [ 'site' ], // Dashboard also permits own; health network requires network authority.
    'filters'          => [], // Supported host filters only; undeclared explicit filters are rejected.
    'output_schema'    => [
        'type' => 'object',
        'properties' => [ 'supported' => [ 'type' => 'boolean' ] ],
        'required' => [ 'supported' ],
        'additionalProperties' => false,
    ],
    'permission'       => static function ( $args, $user_id ) {
        return current_user_can( 'manage_options' );
    },
    'availability'     => static function () { return true; },
    'read'             => static function ( $args, $user_id ) {
        return [ 'supported' => true ];
    },
    'project'          => static function ( $value, $args, $user_id ) {
        return [ 'supported' => $value['supported'] ];
    },
] );
```

The descriptor requires owner/key/domain/API, safe metadata, units/range semantics, explicit public sensitivity, scopes/filters, closed bounded output schema and four callbacks. Host tools provide fixed typed input contracts; adapters declare which host filters they support. Strings cap at 2,048 Unicode characters, structures at depth three and 100 entries, and schema metadata cannot smuggle private fields or unsafe patterns. A contribution schema is at most 64 KiB; each domain accepts at most 100 adapters and 256 KiB of schema metadata. Discovery responses remain bounded. Guidance adapters may declare up to ten public `link_hosts`; only a declared public HTTPS destination or the current internal origin is allowed in typed `url` fields. No URL is fetched. Diagnostic URLs remain excluded. No writer is accepted. Owners, schemas and collisions validate before caller filtering. New API/schema versions require new registration; no client registration endpoint exists.

Owner admission and source permissions are re-evaluated on each read. Dashboard contributions cannot broaden site/own access; diagnostic contributions cannot bypass either capability, network authority or redaction. Source failure, unsafe output or a hidden SQL/HTTP attempt yields an isolated unavailable contribution. No stored extension data is removed on deactivation. Standalone tools may declare the additive `dashboard` or `site_health_info` domain and receive the same native floor; these domains are read-only. Their input schemas reject user/author/customer/site/network overrides, plural record selectors and nested record IDs. Top-level dashboard `listing_id`/`order_id` selectors invoke native object checks; explicit own scope also constrains administrators to their own records. Readers receive the effective dashboard scope. Network-only health adapters are eligible only for a multisite super administrator and receive network source scope without permitting a client-selected site.

Both core read groups and standalone tools in these domains deny all WordPress HTTP requests through the read guard, in addition to SQL-write protection. These protections cover permission/discovery callbacks and direct ability authorization before source execution. Installed PHP remains trusted code, not a sandbox. All implemented reads collect fresh data without persistent cache or replay/operation records, so native changes and capability/extension removal take effect immediately. No stale response or cross-actor/runtime cache is served.

## Verification

The local CLI suite `tests/wp-cli/directorist-mcp-dashboard-health.php` covers scoped counts, expiry/favorites, exact currencies, duplicate mappings, local-date/DST boundaries, current-vs-daily views, native runtime collectors, redacted text/JSON, report bounds, registration faults/lifecycle, hidden SQL/HTTP attempts, revoked capabilities, 1,000-item feed truncation and direct abilities. It suppresses mail, serializes fixture suites and restores settings/timezone/users/posts/orders/roles in `finally`.

Real HTTPS tests call all eight tools, verify 53-tool discovery and credential revocation, and use the pinned stdio bridge 0.5.1 over trusted TLS. No live financial/provider operation, account refresh, update/status test, build or diagnostic dump is needed. Detailed run evidence is in [verification](verification.md).

Remaining release verification includes minimum/current WordPress and adapter combinations, actual multisite installations/network policies, real registered analytics/enquiry/recommendation products, very large listing scopes up to the advertised scan budget, restricted host configurations and the complete PRD matrix. Focused local results are not certification of these environments.
