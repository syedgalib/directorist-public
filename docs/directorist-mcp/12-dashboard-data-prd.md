# PRD 12 — Dashboard Data Access

Status: initial implementation is available for review; acceptance criteria remain normative release requirements. See the [implementation guide](../../docs/mcp/dashboard-health.md). Related: [overview](README.md), [shared access rules](06-access-control-and-write-safety-prd.md), [tools T46–T50](07-mcp-tool-catalog.md#dashboard-data-tools-proposed), [acceptance matrix](08-acceptance-test-matrix.md), [orders](10-order-management-prd.md), [extension framework](11-extension-extensibility-prd.md). See the [implementation review and repairs](../../docs/mcp/dashboard-health-review.md).

## Product outcome

An authorized user can ask an assistant “How is my directory doing?”, “Which listings need attention?”, or “What setup tasks remain?” and receive structured Directorist dashboard data with its scope, source, time range and freshness. Administrators receive the permitted site overview. Listing owners receive their own eligible listing data and purchase summary, without site totals or another owner's information.

The primary surface is the Directorist admin Dashboard at `edit.php?post_type=at_biz_dir&page=atbdp-extension`. Its metrics, charts, setup checklist, quick actions and recent activity are in scope. The frontend listing-owner dashboard is a separate, explicitly scoped source. This feature exposes data through the existing MCP endpoint; it adds neither a dashboard redesign nor dashboard write actions.

## Existing behavior and gaps

The inspected [dashboard activity service](../../includes/classes/class-extension-activity.php) supplies published/pending listing counts, published-listing view totals, listings expiring within seven days, paid-order amount/count, chart series, setup steps and recent activity. Its financial aggregates combine modern and unmigrated legacy records. Charts use daily data for published listings and orders; views use optional analytics history or a current per-listing distribution fallback.

The [admin page controller](../../includes/classes/class-extension.php) composes welcome data, directory-specific quick actions, setup visibility, activity and recommendations. Its activity AJAX handler requires `manage_options` and a nonce. The [frontend model](../../includes/model/ListingDashboard.php) queries the current author's listings, supports favorites and optional enquiry integration, and conditionally renders order history. [Owner order history](../../assets/src/js/react/frontend/listing-owner-dashboard/app.tsx) uses native customer order routes.

These services are evidence of native data, not existing MCP interfaces or permission-safe reusable payloads. Several methods return presentation data, impose narrower paging, use global counts, combine currencies without a currency dimension, or depend on optional integrations. The MCP adapter must introduce an explicit safe projection and bounded query contract. It must not call the complete admin page renderer, return its raw data array, scrape HTML, or execute AJAX action handlers.

## Prerequisites, scope and exclusions

- Use enabled, ready MCP, the existing WordPress identity and registry, and the shared per-execution guards. No new credential store, endpoint or role grant.
- `scope` is `site` or `own`. Default to `site` for users with `manage_options`, otherwise `own`; always echo the effective scope. An administrator can explicitly choose `own`.
- Site dashboard tools require `manage_options` plus each source's object/field policy. A delegated editor without that capability receives only eligible own data. Role names never authorize access.
- Own listing data requires native Directorist dashboard/listing eligibility and object visibility. Own orders require the purchaser rules in PRD 10. A customer eligible only for orders may receive an empty own order summary, without admission to listing, settings, taxonomy or site data.
- Explicit `site` requests without permission return `forbidden`; never silently downgrade. No client-supplied `user_id`, author override or cross-owner dashboard in this release.
- Read-only overview, charts, activity, setup, recommendations and safe navigation descriptors are in scope. Existing listing/order tools remain the only route for their respective mutations and detailed histories.
- Exclude account connection/refresh, passwords, licenses, purchase entitlements, download URLs, install/update/activation, checklist dismissal, profile/preference edits, favorites changes, enquiry bodies, personal visitor records, arbitrary SQL/meta access, forecasts, accounting reports and raw analytics exports.
- Public documentation and safe product recommendations can be returned as navigation descriptors. No marketing network request, account refresh or product action is triggered by a read. Cached remote material is optional; its absence cannot block core metrics.

## Data and interface contract

### Proposed read tools

All tools have closed, bounded input/output schemas and read-only annotations. Unknown parameters fail validation. Tool discovery, direct abilities, default-adapter exposure and cached-client calls apply identical live permission checks.

| ID | Wire name | Inputs | Main result |
|---|---|---|---|
| T46 | `directorist-get-dashboard-schema` | Optional `scope` | Available sections, metric definitions, capabilities, filters, defaults, bounds, source versions and unavailable reasons safe for this caller |
| T47 | `directorist-get-dashboard-overview` | `scope`; optional explicit `sections` (maximum 8), `directory_id`, period inputs | Listing stock/attention counts, views, financial summary and owner summary as authorized, with per-section state; no embedded unbounded records |
| T48 | `directorist-get-dashboard-series` | `scope`, one discovered `metric`, optional `directory_id`, period inputs, optional `compare_previous` default false, optional `currency` for financial metrics, optional `series_kind` auto/daily, default auto | Typed, dated daily points or explicitly identified distribution; source, coverage, comparison and units |
| T49 | `directorist-list-dashboard-activity` | `scope`, optional discovered `type`, `directory_id`, period inputs, `page`, `per_page` | Permission-filtered activity, pagination and bounded-history metadata; safe links to supported detail tools |
| T50 | `directorist-get-dashboard-guidance` | `scope`, optional explicit `sections`: setup, quick_actions, recommendations; optional `directory_id`; `page`, `per_page` | Typed setup progress, safe next actions and registered recommendations, individually paginated by requested section |

T50 setup and recommendations are site-only in core. Own scope can return eligible listing/order navigation and registered own guidance. An explicit inaccessible section is rejected; default section selection includes only authorized sections. T46/T01 and the connection tool-group summary must reflect the registered Dashboard group and actual eligibility. Registration inventory remains independent of the current user; visibility is filtered at request time.

### Response envelope and availability

Every result includes `schema_version`, `scope`, effective filters, site timezone, `generated_at` in UTC and `request_id`. Each data section carries `state` (`available`, `empty`, `unavailable`, `partial` or `stale`), safe `source`, `as_of`, `cached`, `age_seconds`, and its own effective range where applicable. Unavailable data has a safe reason and a null value, never a fabricated zero. Zero is valid only for an available, successfully queried empty result.

Default requests omit unauthorized sections entirely, including their names, counts and source identities where sensitive. Explicit inaccessible section/metric requests return `forbidden` before querying its source. A missing optional source yields a section-level unavailable result; a required source failure yields a safe tool error. `partial` identifies missing supported sub-sources and must not present a partial aggregate as complete. Raw exception, query, provider or credential values never appear.

### Metric definitions and time handling

| Metric or section | Required definition |
|---|---|
| Published/pending listings | Current authorized listing stock by native post status, all-time as of response; selected period does not change stock counts |
| Other own listing statuses | Discovered native statuses, including expired/rejected/private where permitted; drafts/trash only when explicitly supported and visible. Do not invent a mutually exclusive “all” total from overlapping attributes |
| Expiring listings | Published, eligible listings with a valid finite expiry in `[as_of, as_of + 7 calendar days)` in site timezone; exclude never-expire and already expired records |
| Listing views | Current cumulative views of authorized published listings; daily series requires a compatible registered analytics source. Cumulative views are not unique visitors or period traffic |
| Published-listing series | Currently published eligible listings grouped by their native post date, matching the inspected source; label as such, not historical publication-transition events |
| Paid-order amount/count | Eligible native paid orders created in the selected period, counted once through authoritative current/legacy mapping. This is order value, not verified cash receipts, profit or net revenue |
| Payment/refund summary | If supported by a registered native source, settled receipts/refunds and pending reservations remain separate. No inference from a status flag or subtraction into an undocumented “net” metric |
| Own purchase summary | Purchaser-scoped order counts/statuses and amounts; purchase amounts are customer spending, never listing-owner earnings |
| Favorites/enquiries | Own eligible favorite-listing count and optional registered enquiry KPI only. No hidden-listing existence leak, message content, contact addresses or fabricated enquiry counts |
| Setup/guidance | Current native completion predicates, visibility/dismissal state, safe action labels and eligible destinations. Completion is not proof that payment credentials are valid or a gateway is operational |

Period inputs are one of `period: last_7_days | last_30_days | last_90_days` (default `last_30_days`) or explicit `start_date` and `end_date` in `YYYY-MM-DD`. Presets include today and the preceding N−1 local dates. Custom endpoints are inclusive local dates normalized to `[start 00:00, day after end 00:00)` using the site's timezone, including daylight-saving transitions. Reject conflicting selectors, impossible/reversed/future dates and ranges exceeding 90 calendar days. Partial current-day buckets are marked. Current stock/lifetime metrics explicitly report `range_kind: current` or `lifetime`.

Daily series return at most 90 points per currency; previous-period comparison uses the immediately preceding equal number of local dates, capped at a total 180-day source scan. If the current range includes a partial day, comparison is marked partial and not presented as an equal elapsed-time comparison. Fill known missing buckets with zero only when source coverage is confirmed; pre-retention or unavailable buckets stay null. Return percent change only with a nonzero comparable baseline; zero-to-positive returns `comparison_kind: new` and null percentage, zero-to-zero returns `flat` and null percentage.

Financial values are exact decimal strings with currency and precision, reusing PRD 10 money rules. Group totals by recorded currency; never label a combined amount with the site's current currency. Cap currency groups at 20; an exceeded bound returns `unsupported_operation`, without a silently truncated total. A financial series requires a currency if more than one eligible currency exists. Missing/ambiguous legacy currency or mapping is reported as incomplete coverage with affected record count visible only to authorized staff. Migrated records are not counted twice. No migration, guessed currency or fabricated settlement timestamp occurs.

### Bounds, activity and links

- Collections default to 20 items, maximum 100; invalid bounds are rejected. T50 applies the same page to each requested collection section and returns separate authorized totals/pages. Overview contains aggregates and bounded descriptors only; activity and per-record details use dedicated tools.
- Activity is a bounded recent feed, not a permanent audit log. Core supports listing, review, payment and site-only user activity as permitted; expiry reminders are explicitly upcoming items, separate from past events. Core history covers at most 90 days and the 1,000 most recent eligible items per selected feed; return `history_start`, `window_total`, `truncated`, `has_more` and safe continuation limits. These totals describe that authorized bounded window, not all historic events. This is an explicit exception to the catalog's whole-collection totals contract.
- Apply scope, source permissions, directory and date predicates before aggregate/count/limit/pagination. Never fetch a global page and filter it afterward. Do not expose forbidden counts through empty pages or `has_more`.
- Stable activity identity is source namespace plus source record/event identity; deduplicate overlapping legacy/current representations. Sort past events by timestamp descending and stable identity as tie-breaker; sort upcoming reminders separately ascending. Echo the ordering and query `as_of`; native mutable data can change between pages. No historical snapshot guarantee is implied.
- Exclude user-registration activity from own scope. Own listing activity requires listing ownership plus current visibility; own order/payment activity requires purchaser ownership independently. Reviews and enquiries require their own read policy, not just listing authorship.
- Directory filtering must follow an authoritative eligible listing relationship. It does not infer ownership of an order or allocate a multi-directory order without a native allocation rule. T46 marks unsupported metric/filter combinations; T47–T50 reject explicit incompatible filters rather than silently returning global data.
- Action descriptors contain stable key, label, eligible internal URL and optional discovered tool reference. They contain no nonce, authentication query string, signed download link, executable HTML or auto-execution instruction. External recommendation links must be registered public HTTPS destinations; returning a link never follows it or performs the action.

### Caching, extensions and read safety

Core dashboard cache entries live at most 60 seconds. Keys include site/blog, actor, effective scope, authorization fingerprint, normalized filters, source/schema/registry versions and timezone. Recheck live capabilities and object visibility before serving cached output; permission loss takes effect immediately. Invalidate affected entries after native listing, review, order/payment/refund, relevant settings and extension lifecycle changes. Optional source results may be marked stale for at most five minutes during a transient failure, only after current authorization; stale data is excluded from a fresh complete total. No cross-user, cross-blog or cross-currency cache reuse.

Read-only execution must not increment views, mark enquiries read, dismiss setup, update preferences, refresh accounts/licenses, run migrations, create operations or invoke writes/providers. Read cache population is the only permitted infrastructure write; it must obey the existing read guard and cause no product mutation. If the guard cannot allow safe cache population, use uncached reads rather than weaken it.

Extensions participate through PRD 11's versioned registry. The additive read-adapter API 1 now implements the `dashboard` contribution contract without changing existing extension tool/field descriptors. A contribution declares owner, contract/API version, namespaced key, label, metric/section kind, bounded input/output schemas, units, range semantics, supported filters, sensitivity, scope/admission/permission checks, source availability, read/projection callbacks and cache/invalidation policy. No write callback is allowed. Reject collisions, incomplete definitions and unsupported versions. A permissive callback cannot override site `manage_options`, ownership or field restrictions. Contributors needing an independent workflow can register a read tool under the existing framework. Publish the compatible API version before implementation; do not silently change accepted API 1 descriptors.

## User stories and acceptance criteria

Each story requires the shared prerequisites above. Happy-path and failure outcomes are expressed as measurable Given/When/Then criteria.

### DASH-01 — Discover available dashboard data

As an authorized user, I want to discover my dashboard sections and definitions so that I can ask supported questions with the right scope and filters.

Behavior: T46 advertises only eligible core/extension sections, types, defaults, bounds and honest source availability. Discovery does not call write handlers or reveal a hidden integration inventory.

- **DASH-01-AC1:** Given an administrator and an eligible owner, when each requests the schema, then the administrator receives eligible site/own scopes and the owner receives only own scope with typed metrics and limits.
- **DASH-01-AC2:** Given enabled/ready MCP, when inspecting discovery, context and the connection group summary, then the Dashboard group, permitted T46–T50 tools and registered contributions agree with the live registry.
- **DASH-01-AC3:** Given an unknown scope/metric/filter or unavailable optional source, when requesting schema/data, then invalid input fails explicitly and missing source availability is reported without exposing secret configuration.

### DASH-02 — Read the site overview

As an administrator, I want the Directorist dashboard overview so that I can assess listing health and supported business activity without opening each native screen.

Behavior: T47 reuses native definitions through safe projections, labels stock versus period metrics and supports only declared directory filters. It returns aggregates without raw records or account payloads.

- **DASH-02-AC1:** Given known listing/status/expiry fixtures, when reading the site overview, then published, pending and next-seven-day expiry counts match authorized native source queries, excluding never-expire and already expired listings from upcoming counts.
- **DASH-02-AC2:** Given an empty site and an unavailable source, when reading, then the successful empty metric is zero with `empty` state and the unavailable metric is null with its reason; neither is substituted for the other.
- **DASH-02-AC3:** Given a directory filter, when reading supported metrics, then each reflects that directory; an unsupported relationship/filter combination is rejected and never returns unfiltered global totals.
- **DASH-02-AC4:** Given an editor without `manage_options` or capability revoked after discovery, when requesting site scope through any entry point, then access fails before site data or cached counts are returned.

### DASH-03 — Read my dashboard safely

As a listing owner or purchaser, I want my dashboard summary so that I can monitor my listings and purchases without accessing other users' data.

Behavior: Own listing and purchase sections use separate native permissions. Site totals, configuration, other users' activity and private record fields are excluded.

- **DASH-03-AC1:** Given owners A/B with private and published listings, when A reads own overview or activity, then only A's eligible listing data contributes to values, pagination and references.
- **DASH-03-AC2:** Given a purchaser with no listing capability and an empty history, when requesting eligible own dashboard data, then the order summary is empty and no listing/site/configuration access is granted; linked-listing ownership alone cannot expose someone else's order.
- **DASH-03-AC3:** Given explicit site scope, an author override or another user's identifier, when an owner calls a dashboard tool, then forbidden scope or unsupported input is rejected without cross-owner disclosure.
- **DASH-03-AC4:** Given eligible favorites and an absent enquiry integration, when reading own overview, then favorites exclude currently inaccessible listings and enquiry data is unavailable rather than invented or populated from message bodies.

### DASH-04 — Understand trends and coverage

As an authorized user, I want dated chart values and honest comparisons so that I can distinguish measured trends from current totals and missing history.

Behavior: T48 returns typed units, explicit local-date windows, source coverage and comparison semantics. Distribution fallbacks cannot masquerade as daily traffic.

- **DASH-04-AC1:** Given a 30-day request across a timezone transition, when reading a supported daily series, then exactly 30 local-date buckets and correct exclusive UTC boundaries are returned, with current-day partial coverage identified.
- **DASH-04-AC2:** Given absent analytics history but available cumulative views, when requesting view data, then the result identifies lifetime/distribution data and has no fabricated dates or historical comparison; an explicitly daily-only request returns unavailable data.
- **DASH-04-AC3:** Given zero baseline, missing retained days or a current published listing later unpublished, when computing charts, then zero-baseline percentages are null, unknown buckets remain null, and published-series membership follows the declared current-status/post-date definition.
- **DASH-04-AC4:** Given conflicting selectors, future/reversed dates or 91-day range, when requesting a period, then validation fails; a valid 90-day comparison remains within 180 source days and 90 points per returned series.

### DASH-05 — Read financial summaries accurately

As an authorized administrator or purchaser, I want financial dashboard values labeled by currency and meaning so that I do not mistake order value for received money or earnings.

Behavior: Use native authoritative mappings and exact decimal output. Administration and purchaser scopes remain separate. Refunds, pending outcomes and optional coverage are never hidden in an undocumented total.

- **DASH-05-AC1:** Given paid orders in multiple currencies and one migrated legacy pair, when reading financial metrics, then amounts are exact currency-separated strings, the migrated purchase counts once, and no converted or mixed-currency grand total appears.
- **DASH-05-AC2:** Given paid, cancelled, refunded and pending-provider records, when reading, then the declared paid-order metric uses its explicit status/date definition and separate supported payment/refund values never claim unsettled effects are settled receipts.
- **DASH-05-AC3:** Given an ambiguous legacy currency, unsupported mapping or more than 20 currencies, when aggregating, then incomplete coverage or the explicit bound error is returned without guessing, truncating a total or migrating data.
- **DASH-05-AC4:** Given monetization disabled with readable historical storage, when requesting authorized historical financial data, then history remains readable and own amounts are labeled purchase spending; no charge, provider call or paid entitlement change occurs.

### DASH-06 — Inspect recent activity

As an authorized user, I want a filterable recent activity feed so that I can identify relevant changes and follow up through permitted detail tools.

Behavior: T49 uses stable identities, bounded authorized windows, typed past/upcoming items and minimal safe object projections. It is not a security audit log.

- **DASH-06-AC1:** Given mixed-source activity with equal timestamps and a legacy/current duplicate, when paginating an unchanged dataset, then ordering is deterministic, the duplicate appears once and page metadata describes only authorized bounded-window records.
- **DASH-06-AC2:** Given hidden orders, reviews without native read authority and site-only user registrations, when an owner reads activity, then those items and their counts/links are absent before pagination.
- **DASH-06-AC3:** Given page sizes 20/100/101 and a feed exceeding 1,000 items, when requesting activity, then valid sizes work, 101 fails, and the bounded result reports truncation and continuation limits without claiming complete historical totals.
- **DASH-06-AC4:** Given expiry reminders and past events, when reading, then upcoming reminders remain separately typed/sorted and no item claims a reminder is a completed mutation or exposes nonce-bearing/action URLs.

### DASH-07 — Get setup guidance and safe next actions

As an administrator, I want current setup progress and eligible quick actions so that I can complete directory setup through the supported workflows.

Behavior: T50 supplies native completion predicates and safe navigation/tool descriptors. Checklist visibility/dismissal is separate from actual completion. Own guidance is limited to permitted listing/order actions.

- **DASH-07-AC1:** Given completed/incomplete setup steps and a dismissed checklist, when reading guidance, then completion and progress match native predicates and dismissal only changes visibility; the read does not dismiss, complete or save anything.
- **DASH-07-AC2:** Given single-/multi-directory mode and limited permissions, when reading quick actions, then destinations target the proper directory/native screen or discovered tool and inaccessible actions/URLs are excluded.
- **DASH-07-AC3:** Given cached public recommendations or an unavailable recommendation source, when reading guidance, then safe bounded recommendations or an unavailable section is returned without an account refresh, download/install action or marketing fetch.
- **DASH-07-AC4:** Given setup/payment configuration, when reading status, then only safe readiness labels appear; credentials, license/account data, signed URLs and an unsupported claim of operational gateway readiness are absent.

### DASH-08 — Extend dashboard data through registration

As an extension developer, I want a versioned dashboard contribution contract so that my supported metrics and guidance appear under Directorist's existing permissions and safety rules.

Behavior: The registry validates identity, schemas, scopes, source versions and read-only contracts. Optional failures are isolated; deactivation removes live access without deleting stored extension data.

- **DASH-08-AC1:** Given a compatible read-only fixture contribution, when registered, then its typed namespaced schema/data appears only for eligible users through discovery, T46 and its declared dashboard section.
- **DASH-08-AC2:** Given a collision, unsupported API version, missing permission/unit/range declaration or write callback, when registering, then the invalid contribution is rejected with safe administrator diagnostics and other tools remain available.
- **DASH-08-AC3:** Given deactivation, permission loss or source contract change after discovery/cache population, when reading by any entry point, then the former contribution/data is inaccessible, caches cannot revive it and stored product data remains intact.
- **DASH-08-AC4:** Given a contribution throwing an exception, returning secret/unknown fields or attempting a hidden write, when reading, then safe section failure/redaction enforcement occurs and no product mutation or private payload reaches the client/log.

### DASH-09 — Receive fresh, bounded and nonmutating reads

As an authorized user, I want clear data freshness and reliable read behavior so that I can trust summaries without changing the site or overloading it.

Behavior: Reuse safe bounded native queries, authorization-aware caches and optional-source fault isolation. No full dataset export or unbounded record hydration is allowed to answer a dashboard request.

- **DASH-09-AC1:** Given owner A/B, different blogs, scopes and currencies, when filling/reading caches, then no result crosses those boundaries; revoked capability/object visibility is enforced immediately despite a warm cache.
- **DASH-09-AC2:** Given a native listing/payment update or extension deactivation, when fetching again, then affected cache data is invalidated; otherwise core data is at most 60 seconds old and optional stale data is explicitly marked and no older than five minutes.
- **DASH-09-AC3:** Given disabled/unready MCP, anonymous/revoked credentials or an active cached session, when executing through dedicated/default/direct entry points, then no dashboard data is returned and ordinary Directorist operation remains available.
- **DASH-09-AC4:** Given large source fixtures and instrumentation, when invoking every dashboard read, then bounded indexed queries/aggregations return within the published release query/time budget without hydrating all records, and product tables, view counters, read flags, credentials and provider-dispatch logs remain unchanged.

## Feasibility and compatibility report

**Summary:** Adapt existing dashboard sources behind five proposed read tools and a safe domain projection. Preserve admin page slug, native AJAX contracts/nonces, existing Other Features MCP UI, settings keys and storage. No build or UI redesign is part of this PRD.

**Dynamic state:** Source architecture was inspected for this document. No live product/account state was requested or stored. All counts, amounts, identities and statuses above are definitions or synthetic scenarios, not site snapshots. No connected-account or provider action was executed.

**Straightforward reuse:** Native listing/status/expiry sources, activity types, setup predicates, directory navigation and the existing MCP registry/authentication/response conventions. Read tools require no mutation previews, confirmations or replay operations.

**Risky integration points:** Existing global aggregates cannot be reused for own scope. Native activity paging silently clamps and lacks full totals/stable tie-breakers. Existing financial sums use floats and a site-currency label; MCP needs exact currency grouping and unambiguous legacy mappings. Chart fallbacks are distributions, not daily observations. Rendering the whole admin page would pull in licensing/account/product side effects and private data. Native filters are trusted PHP but their outputs still require MCP schema/permission/redaction checks.

**Affected systems:** Dashboard activity/metric resolvers; listing/review/order source adapters; cache keys and invalidation hooks; MCP catalog, registry, admission, abilities, dispatcher, context/group summaries; proposed dashboard contribution contract and fixture tests. Refactor shared services only where necessary, preserving native page outputs and compatibility hooks. Do not make optional analytics, account connection or monetization a prerequisite for core listing reads.

**Engineering decisions before release:** Publish the compatible dashboard adapter API version, supported source/storage versions and timestamp meanings, directory allocation support, exact metric definitions and query/time budget measured on a documented fixture size. If a source cannot satisfy these requirements, mark that metric/filter unavailable; do not quietly weaken the contract. Multi-request activity is a live feed rather than a historical snapshot.

## Release verification

Run QA-062–QA-070 and QA-X11 in the [matrix](08-acceptance-test-matrix.md), alongside shared access/privacy/alternate-entry tests. Use administrators, custom roles, revoked users, owners A/B, an order-only purchaser, separate blogs, multiple currencies, legacy/current duplicates, missing analytics and a controlled extension. Compare authorized values with native source queries and UI meanings, accounting for documented period/precision improvements rather than asserting byte-identical legacy payloads.

Publish tested source versions, defaults, unavailable combinations, cache ages, large-dataset fixture/query budget and evidence for each acceptance criterion. Focused local and HTTPS verification now covers T46–T50; see the implementation guide and verification record. Full release-matrix scenarios remain Not run unless separately evidenced. The earlier 45-tool results do not verify these additions.
