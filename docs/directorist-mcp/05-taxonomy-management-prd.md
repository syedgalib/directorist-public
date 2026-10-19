# PRD 05 — Taxonomy Management

Status: proposed V1. Related: [listing assignment](04-listings-management-prd.md), [access and safety](06-access-control-and-write-safety-prd.md), [catalog](07-mcp-tool-catalog.md).

## Outcome and baseline

Authorized users can maintain Directorist categories, locations, and tags while listing owners can look up and assign eligible existing terms. Directorist already registers these taxonomies and exposes term controllers. Native capabilities distinguish managing/editing/deleting terms from assigning them to listings. MCP must preserve that distinction.

| Taxonomy | Identifier | Hierarchy | Supported properties |
|---|---|---|---|
| Categories | `at_biz_dir-category` | Parent/child | Name, slug, description, parent, supported icon/image, directory associations |
| Locations | `at_biz_dir-location` | Parent/child | Name, slug, description, parent, supported image, directory associations |
| Tags | `at_biz_dir-tags` | Flat | Name, slug, description, directory associations where the registered native schema supports them |

Directory types are managed under PRD 03 rather than through arbitrary taxonomy editing. MCP cannot manage WordPress post categories/tags or extension taxonomies simply by accepting another taxonomy name.

## User stories and acceptance criteria

### TAX-01 — Discover and inspect eligible terms

As an authorized user, I want to discover taxonomy capabilities and find eligible terms so that I can use exact term IDs and understand their directory context.

- **TAX-01-AC1:** Given an authorized taxonomy manager, when discovering taxonomy schemas and searching terms, then hierarchy support, supported metadata, effective permissions, IDs, names, slugs, parents, relevant counts, and directory associations are returned with bounded pagination.
- **TAX-01-AC2:** Given a listing owner with assignment permission but no term-management permission, when requesting an assignment lookup, then only permitted display fields for terms eligible for the specified directory are returned; management metadata and protected counts are excluded.
- **TAX-01-AC3:** Given an unknown taxonomy, invalid directory, unsupported filter, or unauthorized management context, when requesting terms, then the request fails explicitly rather than falling back to an unrestricted taxonomy query.

### TAX-02 — Create a category, location, or tag

As a taxonomy manager, I want to create terms so that listings can use a consistent vocabulary.

- **TAX-02-AC1:** Given create/edit-term capability and valid inputs, when creating a term, then its normalized name/slug, parent where supported, supported metadata, and directory associations are saved and a stable term ID is returned.
- **TAX-02-AC2:** Given a missing name, conflicting slug in the native uniqueness scope, invalid parent, unsupported property, or unauthorized media reference, when validating, then no term is created and the failed field is identified.
- **TAX-02-AC3:** Given a listing owner with only assignment permission, when attempting creation directly or through a listing's assignment input, then creation is denied; unknown names are never auto-created as a side effect of assignment.

### TAX-03 — Update properties and hierarchy

As a taxonomy manager, I want to edit term properties and parent relationships so that the taxonomy stays organized.

- **TAX-03-AC1:** Given permission and a valid update, when saved, then only explicit supported properties change and unrelated registered metadata/associations remain intact.
- **TAX-03-AC2:** Given a category/location update creating a self-parent or descendant cycle, or any tag parent input, when validating, then no term data changes and a hierarchy/unsupported-field error is returned.
- **TAX-03-AC3:** Given a changed slug or parent, when previewed and confirmed, then the response describes hierarchy/URL effects and native cache/rewrite behavior is honored; a stale term version blocks the mutation.

### TAX-04 — Manage directory associations

As a taxonomy manager, I want explicit control over a term's directory associations so that terms appear only where appropriate.

- **TAX-04-AC1:** Given multi-directory mode and a supported association property, when association changes are previewed, then added/removed directory IDs and affected authorized dependency counts are shown; unrelated associations are preserved unless explicit replacement is requested.
- **TAX-04-AC2:** Given existing listing assignments in a directory being removed, when attempting association removal, then the change is blocked until those dependencies are explicitly resolved outside that operation; no listing is automatically reassigned or silently made inconsistent.
- **TAX-04-AC3:** Given a parent and child term or a nonexistent directory, when an incompatible association is proposed, then the update fails before saving; in single-directory mode associations resolve to the active default and unsupported association edits are rejected.

### TAX-05 — Delete unused terms

As a taxonomy manager, I want to delete unused terms after reviewing their dependencies so that cleanup preserves listing relationships and hierarchy.

- **TAX-05-AC1:** Given delete-term permission and a term with no listing relationships, children, or registered protected dependencies, when deletion is previewed and confirmed, then that term is deleted without changing unrelated terms or media files.
- **TAX-05-AC2:** Given a term used by any listing, including draft/private/trash or a listing in another directory, or a term with children, when deletion is requested, then it is blocked even if public term counts are zero.
- **TAX-05-AC3:** Given a valid preview followed by a new assignment, child, or protected dependency, when executed, then deletion is rejected as stale/blocked; no implicit reassignment, reparenting, merging, or cascading deletion occurs.

### TAX-06 — Apply bounded bulk term changes

As a taxonomy manager, I want to create, update, or delete explicit terms in a bounded batch so that repetitive maintenance remains reviewable.

- **TAX-06-AC1:** Given 1–50 explicit items within one allowed taxonomy, when previewing, then each item's inputs, permissions, hierarchy, associations, and deletion dependencies are checked independently and no term is changed.
- **TAX-06-AC2:** Given the exact confirmed batch, when executing, then each eligible unchanged item is revalidated against state after preceding successful items, with individual results; an item that would now cause a cycle or conflict fails without corrupting earlier successful changes.
- **TAX-06-AC3:** Given a duplicate retry, more than 50 items, cross-taxonomy envelope, or an item referencing another new item by client reference, when submitted, then retry protection prevents duplicates or envelope validation rejects the unsupported batch before processing, as appropriate.

## Directory and hierarchy integrity

Association operations distinguish `add`, `remove`, and explicit `replace`; omission preserves existing associations. A new term must resolve to a valid directory scope. Parent and child must have compatible scope: every directory assigned to the child must also be available to its parent. Editing a parent's scope must preserve that condition for existing descendants. A term with no explicit native association uses the native effective scope; do not invent a second global-term storage convention.

Single-directory mode uses the existing default directory and preserves legacy metadata. Multi-directory lookups honor effective scope, not only the requested directory ID. Creation and updates validate names, native uniqueness, sanitized descriptions, supported icons, and authorized attachment references. Tag inputs cannot include parent properties, images, or icons unless those properties are explicitly supported by their registered schema.

Taxonomy management requires the runtime taxonomy object's capability for the operation and existing Directorist permission filters. Listing assignment additionally requires edit access to the listing. Safe assignment lookup is a separate context: it does not grant access to a management reader that currently requires taxonomy-management permissions.

## Write rules and verification

All mutations support dry run and require an idempotency key on execution. Deletes, existing slug/parent/directory-association changes, and all bulk executions require preview/confirmation. Ordinary single-term creation and name/description/supported-image updates may execute after validation without confirmation. Existing-record updates require the discovered version.

Deletion dependency checks cover relationships across the site and all statuses, not only the current directory or public term count. Dependency errors expose only counts/details the caller can read. Unknown protected dependencies block deletion if the implementation cannot establish safety. Native term hooks and caches must update normally.

Test hierarchical categories and locations, flat tags, shared associations, legacy scope, parent/descendant conflicts, hidden listing dependencies, custom taxonomy capabilities, and mixed bulk results. See the [acceptance matrix](08-acceptance-test-matrix.md).
