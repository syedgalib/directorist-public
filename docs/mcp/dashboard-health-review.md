# Dashboard and Site Health PRD review

Reviewed 19 October 2026 against root PRDs 12 and 13 and their shared access, extension and tool contracts. The core inventory remains 53 tools. This is a correctness review with focused regression evidence; the full release matrix is not certified by this review.

## Repaired gaps

| PRD requirements | Finding and repair | Regression evidence |
|---|---|---|
| DASH-01 | Schema discovery omitted machine-readable effective defaults and core optional-source availability. Both are now returned under closed output contracts. | Default period/scope and unavailable recommendations assertions. |
| DASH-03, DASH-08; SHI-07 | Independent dashboard tools could accept another owner's IDs or identity/site overrides under permissive contributor callbacks. Diagnostic schemas now reserve identity/site selectors, reject nested/plural record selectors, and enforce native top-level listing/order ownership. Explicit own scope also restricts administrators. | Invalid registration cases, owner A/B denial before reader execution, normalized scope and explicit administrator-own denial. |
| DASH-06, DASH-09 | Favorite counts and review activity did not apply the same restrictive native listing read filter as listing aggregates. They now do so before counts and pagination. | Hidden public favorite and restricted-review fixtures. |
| DASH-04, DASH-06 | A native `prepaid` order status was missing from the output schema. Past-feed windowing could crowd out all upcoming reminders; numeric SQL tie ordering differed from in-memory lexical ordering; future same-day timestamps could appear as past events. The status contract is complete, reminders have an independent bounded collection, and ordering/time bounds agree. | Prepaid overview, busy feed with expiry reminder, future timestamp and numeric tie fixtures. |
| DASH-07 | The canonical setup service discarded completion data after the setup window expired. A companion details reader retains steps/progress while preserving the original method signature, early exit and UI payload. Guidance/activity omit references to undiscoverable tools. | Expired-window source fixtures, native/default versus details comparison, and policy-hidden detail-tool assertions. |
| DASH-08, DASH-09; SHI-06, SHI-08 | Permission/discovery callbacks could run before strict read protection, including direct ability authorization. SQL-write/HTTP guards now cover these callbacks. Contribution schemas/patterns and discovery inventories have explicit bounds. | Permission callbacks attempting writes/HTTP, direct ability attempt, existing source fault/lifecycle tests and closed output validation. |
| SHI-01, SHI-05 | Discovery exposed only shallow field types, field ordering differed, and text reports contained literal newline markers. Discovery now publishes bounded nested schemas, stable keys, labels and units; text uses real line breaks. | Typed array schema/order assertions, units and JSON/text report checks. |
| SHI-01, SHI-03 | Removing `id` from selected inventory fields changed pagination order. Same-basename regular/MU plugins could collide. Theme no-update observations were lost, and compatibility/observation-age facts were incomplete. Sort before projection; retain source-specific plugin identities; emit honest cached state/age and versioned, manifest-minimum-only assessments. | Selected-field page parity, empty inventory, filename collision, unsupported/unknown manifests and stale theme/MU update fixtures. |
| SHI-02, SHI-04, SHI-08 | Redaction missed short root paths, UNC shares, IPv6 and private-domain banners. Limits counted bytes instead of Unicode characters. Missing/restricted filesystem areas could be misreported or log paths. GD GIF/WebP source keys were incorrect. | Synthetic unsafe banners, Unicode bounds, missing filesystem area and GD key fixtures. |
| SHI-06, SHI-07 | Network-only health adapters could register but were never selected for an eligible network administrator. They now use explicit source scope and remain excluded from ordinary site callers. | Source-path and schema review; actual multisite execution remains a release gate. |

## Interface and compatibility notes

- T49 returns past `items` and independently paginated `upcoming.items`; each window has its own totals/truncation/continuation metadata. The same requested page/size applies to both.
- T51 includes complete field schemas and source scope. T52/T53 preserve stable row membership with any allowed field selection. Successful empty data is distinguished from an unavailable source.
- Site Health collector version is `local-safe-collector-2`, redaction policy `1.1`. Inventory compatibility reports only installed WordPress/PHP manifest minimums, with an explicit rule version; it does not certify product interoperability or security.
- The original native `get_dashboard_setup($metrics)` signature and behavior are retained. MCP uses `get_dashboard_setup_details($metrics)`; unsupported native sources are unavailable.
- Internal navigation requires the configured origin. Encoded credential selectors/fragments, HTTPS downgrades and alternate ports are rejected. Registered public guidance URLs remain inert data.
- Read-adapter API major 1 remains additive. Each domain permits at most 100 contributions and 256 KiB of aggregate schema metadata; an individual schema caps at 64 KiB. Existing tool/field domains retain their contracts.

## Verification and remaining release work

The regression suite is [directorist-mcp-dashboard-health.php](../../tests/wp-cli/directorist-mcp-dashboard-health.php). Reproduction cases use synthetic source fixtures, disposable records and custom roles; they do not install/activate software, refresh updates/accounts or execute provider actions. Native setup is tested with a synthetic directory query, without depending on or modifying existing directory creation dates. Suites share a test lock and restore their captured state in `finally`.

Final assertion counts, coding standards, syntax and HTTPS/bridge outcomes are recorded in [verification](verification.md). The [implementation guide](dashboard-health.md) documents the current contract and limits.

Actual multisite and network-source execution, minimum/current runtime combinations, real analytics/enquiry/recommendation extensions, restricted host configurations and maximum-size listing scans still require staging verification. Optional sources without compatible registrations remain unavailable; unresolved legacy currency/mapping remains incomplete. These are explicit release constraints, not completed acceptance scenarios.
