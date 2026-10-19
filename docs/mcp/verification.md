# Directorist MCP implementation verification

Latest scope: the initial runtime now contains 53 core tools and additive read-adapter API 1. The original order/extension evidence below is retained as a dated baseline. Dashboard/Site Health evidence follows in the 19 October update.

Implementation reviewed: 18 October 2026. Core tool inventory: 45 definitions plus validated registered extension tools. Registry API major: 1. MCP remains opt-in and was restored to its original disabled state after local verification.

## Evidence

| Check | Scope/result |
|---|---|
| Original core integration suite | tests/wp-cli/directorist-mcp.php, updated for the 45-tool inventory and narrow purchaser admission |
| Order/extension integration suite | tests/wp-cli/directorist-mcp-orders-extensions.php; disposable customers/custom staff, native tables, typed field/widget/item fixture and fake providers |
| HTTPS transport | Initialization, negotiated session, 45-tool discovery, context execution, secret-setting denial and application-password revocation |
| Local stdio bridge | Pinned @automattic/mcp-wordpress-remote 0.5.1 over verified TLS; initialization, discovery and authenticated context |
| Static checks | Focused PHP syntax/coding standards and Markdown links/catalog traceability; no generated asset build |

Final local assertion counts and static-check outcomes are recorded below. Stateful fixture suites now share a database advisory test lock and must run sequentially before taking configuration snapshots.

The suites cover unpaid creation/recalculation, explicit legacy lookup, purchaser isolation/cancellation, native offline fulfillment, partial/manual refunds, synchronous fake settlement, accepted-then-timeout reservations, financial replay after cache expiry, native completion proof, mixed batches, custom-role revocation, safe discovery/diagnostics, invalid API/colliding registrations, hidden-write rejection, field/widget/item adapters, stale previews, additional policies, extension lifecycle invalidation and alternate ability execution. Generic external tools also have fake dispatch/reconciliation and verified native result completion.

The HTTPS account and application password are disposable and removed/revoked in cleanup. CLI fixtures suppress mail and restore settings, metadata, roles, users, orders, native ledger entries and operational records in finally. No real gateway charge/refund, commercial extension activation, role migration or plugin build was performed.

## Compatibility and remaining release gates

The inspected environment uses Directorist 8.10.5, WordPress 7.1.3, adapter 0.6.1, PHP 8.2 CLI / PHP 8.0 web and Node.js 22. This records tested versions, not an unconditional guarantee for every later release.

- Current custom-table storage and explicit previously migrated legacy mappings are supported. Unmigrated/ambiguous legacy records require native administration; MCP performs no migration.
- Monetary strings/minor-unit comparisons are exact. Native DTOs/tables/calculators still constrain native writes to at most two decimal places and DECIMAL(10,2); unsupported precision/range is refused. Core refunds require a single settled receipt because native refunds lack payment allocation metadata.
- Real gateways need an explicitly registered native adapter and their own sandbox/provider certification. Registered callback contracts are tested with fake providers; installing an extension alone does not certify its policy, credentials or financial behavior.
- Asynchronous provider settlement is separate from native ledger completion. Read tools never dispatch or rerun native writers. A trusted administrator integration can bind verified already-committed native results using the documented local PHP completion APIs.
- Native REST handlers, checkout and native order-table writes coordinate through the MCP advisory lock. Additional stores/writers must declare tables/cache behavior and join the lock before reading state used for a later write. Full multi-process webhook/third-party-store races still require staging verification.
- Supported WordPress/adapter/runtime minima, every named desktop client, mobile browser behavior, large ledgers, multi-currency native/provider combinations, external hook/email failures and the entire PRD acceptance matrix remain release criteria. Focused checks are not completion of every QA row.

## Final run

- Original core suite: **160 assertions passed**.
- Order/extension suite: **188 assertions passed**; combined **348 assertions**.
- HTTPS and pinned stdio bridge: initialization, 45-tool discovery, authenticated execution, protected-setting denial and revocation passed with TLS verification enabled.
- Focused coding standards and PHP syntax passed; formatting patches were checked for identical non-whitespace PHP tokens. No generated asset build was run.
- All 12 PRD files retain 61 unique stories, 208 acceptance criteria and 45 core tool definitions with exact criterion-to-story-row coverage. Local documentation/source links resolve.
- Final HTTP cleanup verified disabled MCP and an unchanged hash of every sibling setting. Fixture users/orders/options and temporary HTTP credentials were removed; no MCP cleanup job was left scheduled.

One intermediate overlap between fixture runs left the enablement flag on. It was detected and corrected, and the final stateful runs were sequential. A shared test-suite advisory lock now prevents this overlap. The final sibling-settings check passed.

## Dashboard and Site Health update — 19 October 2026

The [implementation guide](dashboard-health.md) records eight implemented read tools, their native sources, exact scope/period meanings, supported bounds and additive read-adapter API 1. Native pages, settings keys/save flow, scheduled tests/updates, credentials and provider workflows were preserved.

Focused checks passed on the local Directorist/WordPress installation:

- Core suite updated for 53 tools: **166 assertions**.
- Existing order/extension regression suite: **188 assertions**.
- New dashboard/Site Health fixture suite: **140 assertions**, including typed schemas, actor/capability boundaries, own listing/purchaser separation, exact currencies, ambiguous legacy mapping, DST dates, database-session TIMESTAMP normalization, safe view distributions, malformed counters, safe public guidance hosts, redaction, text/JSON parity, report bounds, hidden SQL/HTTP denial, extension faults/deactivation, current runtime facts and alternate abilities. Combined: **494 assertions**.
- The 1,005-order source fixture returned a 100-item page and an explicitly truncated 1,000-item window using **4 queries in approximately 0.006 seconds**, within the fixture's 50-query/5-second local budget. This does not establish the performance of maximum-size listing scopes or arbitrary extension collectors.
- Real verified HTTPS: initialization/session negotiation, 53-tool discovery, all eight reads, protected-setting denial and revocation of a live session passed. Pinned stdio bridge 0.5.1 initialization/discovery/context also passed over verified TLS.
- PHP syntax passed for **34 files** covering the MCP module, three native repositories, three CLI suites and the controlled extension fixture. Focused PHPCS passed. Formatting was performed on temporary copies and verified for unchanged non-whitespace PHP tokens before applying whitespace edits; no generated asset build ran.
- Static PRD validation passed for 14 files, 78 unique stories, 275 acceptance criteria, 53 catalog entries and 90 QA scenarios; exact criterion-to-story-row coverage and local links/anchors passed. These are documentation checks, separate from runtime scenario acceptance.

Fixture suites run sequentially under the shared test lock, suppress mail and restore their original enablement/settings, timezone, users, posts, orders and roles in finally. HTTPS cleanup removed the temporary administrator and all its application passwords and verified an unchanged hash of unrelated settings. Each suite restored the enablement state captured before its run. Final inspection found MCP enabled with its existing cleanup schedule; the product default remains disabled.

No live financial action, account/license refresh, Site Health Status test, external collector request, recursive size scan, automatic migration, role grant or persistent diagnostic/report cache was required. Optional analytics/enquiry/recommendation sources require explicit compatible registration; unmapped legacy financial currency and unsupported storage snapshots remain honestly unavailable/incomplete. Native object-permission scans cap at 20,000 listing/review candidates and reject broader scopes rather than returning partial totals.

Actual multisite installations and network policies, minimum/current WordPress/adapter/runtime combinations, real extension products, restricted hosts, maximum listing scopes and every individual PRD release scenario remain staging/release gates. Focused passing assertions do not certify the entire matrix.

## PRD review and repairs — 19 October 2026

The [review report](dashboard-health-review.md) maps repaired gaps to PRD requirements and reproduction cases. The implementation guide now describes separate past/upcoming activity windows, typed discovery/defaults, stable field-selected inventory paging, manifest-only compatibility rules, update ages, redaction policy 1.1 and contribution bounds.

Final sequential verification after the repairs:

- Original core suite: **166 assertions passed**.
- Order/extension regression suite: **188 assertions passed**.
- Dashboard/Site Health suite: **198 assertions passed**, up from the initial 140; combined **552 assertions**. New cases cover native prepaid status, independent expiry reminders under a full past window, future timestamp exclusion, numeric tie order, native restrictive listing policies, expired setup-window completion, standalone object/identity checks, guarded authorization/direct abilities, typed field schema/units/order, field-selected inventory page parity, regular/MU filename collisions, cached theme/update age, limited manifest compatibility, empty state, Unicode/redaction and GD GIF/WebP support.
- The 1,005-order performance fixture used **7 queries in approximately 0.007 seconds**, below the existing 50-query/5-second local limit. Optional detail-tool visibility is evaluated once per source, rather than once per record.
- Verified HTTPS initialization/session, **53-tool** discovery, all eight read tools, protected-setting denial and live-session credential revocation passed. Pinned stdio bridge **0.5.1** initialization/discovery/context passed over verified TLS.
- **35 PHP files** passed syntax checks. MCP code and all three fixture suites plus the extension fixture passed focused PHPCS with zero errors/warnings. The native activity service retains 18 pre-existing alignment warnings, reduced from its 21-warning baseline; no new native-source warnings were introduced. Its original public setup method signature, early-exit work and default payload remain intact.
- Whitespace formatting was applied only after temporary-copy token comparison. No asset build ran. **286 local documentation links/anchors** resolved, and root PRDs retained their Directorist-only wording. PRD acceptance criteria were not relaxed or marked as universally verified.

The fixtures and HTTPS wrapper restored their captured settings/enablement, removed disposable data/credentials, and retained the existing schedule state. Multisite/network execution and other release gates listed above remain outstanding validation, rather than claimed passing scenarios.
