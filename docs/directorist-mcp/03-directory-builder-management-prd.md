# PRD 03 — Directory Builder Management

Status: proposed V1. Related: [access and safety](06-access-control-and-write-safety-prd.md), [listing schemas](04-listings-management-prd.md), [catalog](07-mcp-tool-catalog.md).

## Outcome and baseline

An administrator can configure a directory and its forms/layouts through an assistant with the same meaningful controls available in the native builder. Directory types are taxonomy terms with configuration in term metadata. The inspected builder includes General, Add Listing Form, Single Page Layout, All Listing Layout, and Search Form. Its existing REST builder reader does not provide full management; the MCP feature needs deliberate schema and mutation support.

Every management operation here requires `manage_options`. Listing owners receive only the submission schema needed for their allowed listing operations, not full builder configuration.

## Builder coverage

| Area | V1 capabilities |
|---|---|
| Directory lifecycle | List/detail, create from native defaults, duplicate configuration, rename, supported slug changes, change default, delete eligible directory |
| General | Name, slug, icon, existing preview-image reference, submission/edit status policy, supported default-expiration settings |
| Add Listing Form | Add/update/remove supported fields and sections; order/move items; labels, placeholders, help text, required flags, choices, constraints, and supported conditional rules |
| Search Form | Add/update/remove supported filters, arrange basic/advanced sections, configure labels/defaults, bind to valid directory fields |
| Single Page Layout | Configure supported header and content sections/widgets, ordering, visibility, and supported widget options |
| All Listing Layout | Configure supported grid/list card sections/widgets, order, visibility, and supported display options |

Schema discovery reports currently registered widgets, allowed placements, supported options, required/preset items, uniqueness/multiplicity rules, accepted field types, and dependencies. Extension widgets without explicit safe support are read-only and preserved. A native “custom page” layout may select a validated existing page if its schema supports that reference; MCP does not author arbitrary page-builder code, CSS, PHP, or executable templates.

## User stories and acceptance criteria

### BLD-01 — Inspect directories and builder capabilities

As a site administrator, I want directory summaries, configuration, and supported builder schemas so that I can make precise changes without guessing internal metadata.

- **BLD-01-AC1:** Given multiple directories, when listing them, then results include stable ID, name, slug, default status, and listing count, with bounded pagination and deterministic ordering.
- **BLD-01-AC2:** Given a valid directory and builder area, when inspecting, then the response includes the ordered configuration, version/fingerprint, supported widgets/options, and validation constraints for that area.
- **BLD-01-AC3:** Given an unknown directory, unsupported area, or non-administrator, when requesting configuration, then an appropriate not-found, validation, or authorization error appears without returning protected builder data.

### BLD-02 — Create or duplicate a directory

As a site administrator, I want to create a directory from defaults or duplicate an existing configuration so that I can launch a new directory without rebuilding every form.

- **BLD-02-AC1:** Given multi-directory mode is enabled and valid name/slug inputs, when a creation is previewed and confirmed, then a distinct directory is created with native default builder configuration and returned ID.
- **BLD-02-AC2:** Given a source directory, when duplication is confirmed, then configuration is copied with internally valid identifiers and references, but listings, media files, reviews, orders, and taxonomy associations are not duplicated; the new directory is not made default automatically.
- **BLD-02-AC3:** Given single-directory mode, a conflicting slug, or an unsupported extension dependency, when creation/duplication is requested, then it is rejected with corrective guidance; existing settings and directories remain unchanged.

### BLD-03 — Configure general settings and the default

As a site administrator, I want to change a directory's identity and default policies so that new submissions and navigation use the intended configuration.

- **BLD-03-AC1:** Given supported general changes, when previewed, then the response describes name/slug, routing, new/edit status, and expiration effects where relevant, without rewriting existing listings' statuses or expiry dates.
- **BLD-03-AC2:** Given a valid target directory, when a default change is confirmed, then exactly one default is resolved by native Directorist helpers and default-directory lookups return the selected ID.
- **BLD-03-AC3:** Given existing listings and term associations, when a rename or supported slug change succeeds, then IDs, associations, and stored listing values remain intact and native permalink/cache updates run as required.

### BLD-04 — Manage submission fields and sections

As a site administrator, I want to manage supported submission fields and their organization so that listing owners provide the information my directory needs.

- **BLD-04-AC1:** Given supported widget types, when a valid change set adds, edits, moves, reorders, or removes fields/sections, then preview shows the affected stable IDs and execution saves the validated ordered form configuration.
- **BLD-04-AC2:** Given a duplicate storage key, missing mandatory preset, unsupported rule, invalid choice/default, or conditional cycle, when the change set is validated, then none of the change set is saved and errors point to the invalid item.
- **BLD-04-AC3:** Given existing values for a removed field, when removal is confirmed, then values remain in listing storage; existing field storage keys cannot be renamed through MCP, and remaining search/layout references must be resolved explicitly before removal succeeds.
- **BLD-04-AC4:** Given a newly required field or stricter validation, when saved, then future submission/edit validation uses the new schema without silently modifying, unpublishing, or deleting existing records; existing listings missing that field are identified as needing it on their next applicable edit.

### BLD-05 — Manage the search form

As a site administrator, I want to configure directory-specific search controls so that visitors can filter the fields offered by the directory.

- **BLD-05-AC1:** Given searchable fields in the directory schema, when a search-form change is confirmed, then supported controls, sections, labels, defaults, and ordering match the saved configuration on the frontend.
- **BLD-05-AC2:** Given an unsupported filter or reference to a removed/other-directory field, when validating, then the whole change set fails before saving.
- **BLD-05-AC3:** Given an unrelated submission or card configuration, when search changes are saved, then that unrelated configuration and existing listing values are preserved.

### BLD-06 — Manage single-listing layouts

As a site administrator, I want to arrange single-listing header and content widgets so that the detail page presents useful information.

- **BLD-06-AC1:** Given supported widgets and placements, when layout changes are confirmed, then the native single-listing page renders the saved ordering, visibility, and supported options.
- **BLD-06-AC2:** Given a widget placed in a disallowed section or bound to a nonexistent field, when validating, then the configuration is rejected with no partial save.
- **BLD-06-AC3:** Given an existing read-only extension widget, when another supported widget changes, then its unknown configuration is preserved; an attempt to edit the unsupported widget fails explicitly.

### BLD-07 — Manage grid and list cards

As a site administrator, I want to configure grid/list card widgets so that archive listings display consistent information.

- **BLD-07-AC1:** Given supported grid or list configurations, when saved, then the selected card layout renders the intended sections, widgets, ordering, and display options.
- **BLD-07-AC2:** Given a grid-only option supplied to a list template or violation of a widget multiplicity rule, when validating, then no layout is saved.
- **BLD-07-AC3:** Given a change targeting only the grid configuration, when executed, then list-card and single-listing configurations remain unchanged.

### BLD-08 — Delete only an eligible directory

As a site administrator, I want to remove an unused directory after reviewing its dependencies so that cleanup does not orphan content.

- **BLD-08-AC1:** Given an empty, non-default directory with no remaining term associations or registered dependent records, when deletion is previewed and confirmed, then only that directory and its configuration are removed.
- **BLD-08-AC2:** Given a default, last remaining, occupied, or dependent directory, when deletion is requested, then it is blocked and the response identifies authorized dependency counts/types and corrective steps; trash/private/draft listings also count as occupancy.
- **BLD-08-AC3:** Given an eligible preview followed by a new listing or association, when deletion is confirmed, then the changed dependency state blocks deletion and a fresh preview is required.

## Change and integrity contract

Create, duplicate, general updates, default changes, builder changes, and deletion all require preview/confirmation. Each builder call targets one directory and one discovered area, using explicit typed operations on stable IDs. Omitted sections/options are preserved; a partial request must never replace the entire term-meta blob.

Validate the complete resulting area plus references into other areas before persistence. All operations in the change set must pass before saving; protect against concurrent changes across relevant dependencies. Configuration writes must either commit the complete unit or return a defined recoverable failure without presenting partial success as a finished configuration. Reuse native persistence and side effects through a non-terminating service adapter; do not simulate a browser AJAX request.

Removing a field does not purge listing metadata. Deleting a section containing items requires explicit operations to move or remove each child; no implicit cascading deletion. New directory defaults do not backfill old listings. Directory deletion requires dependency checks, not only the public listing count. Unknown extension dependencies fail closed when safe deletion cannot be established.

Single-directory sites can inspect/edit the existing directory, but creating/duplicating extra directories requires multi-directory mode. MCP does not silently turn that setting on. Importing configuration files and automatic repair/migration are outside V1.

Extension widget write support must satisfy the typed adapter, ownership, placement/serialization and lifecycle contract in [PRD 11](11-extension-extensibility-prd.md); a widget-name allowlist alone is insufficient.

## Verification

Verify round trips through native builder UI plus submission, search, single-listing, and grid/list frontend views. Include a directory with existing custom-field values and extension configuration. All [BLD stories map to release scenarios](08-acceptance-test-matrix.md); none is certified by static documentation checks alone.
