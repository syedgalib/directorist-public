# PRD 04 — Listings Management

Status: proposed V1. Related: [builder](03-directory-builder-management-prd.md), [taxonomy](05-taxonomy-management-prd.md), [access and safety](06-access-control-and-write-safety-prd.md), [catalog](07-mcp-tool-catalog.md).

## Outcome and baseline

Listing owners and authorized staff can find, create, maintain, moderate, and organize eligible listings through an assistant. Directorist already has listing REST readers, builder-aware creation, status handling, post capabilities, submission workflows, and extension hooks. MCP must use those business rules; it must not expose a raw post/meta editing shortcut.

The listing schema is resolved per directory and user. It includes supported built-in fields, registered custom fields, taxonomy choices, media constraints, required consent fields, and writable administrative properties. Unknown extension data remains intact but is not writable automatically.

## Functional requirements

| Area | V1 detail |
|---|---|
| Search | Text, explicit IDs, directory, author where authorized, native status, category/location/tag IDs, featured filter, created/modified date range; supported ordering only |
| Summary/detail | IDs, title, directory, safe status, permitted author reference, timestamps, URL, readable field values, assigned terms, and permitted media references |
| Create | Explicit directory, title/content and supported field values, required consents, eligible terms/media; return saved ID and effective status |
| Edit | Explicit fields on an existing eligible listing, with validation against the resulting record and current directory schema |
| Status | Only native statuses/transitions advertised for the user, directory, listing state, and installed extension policy |
| Terms/media | Assign/unassign existing eligible terms; attach/remove/reorder existing authorized media without deleting media files |
| Lifecycle | Trash and restore; retain native lifecycle hooks and previous-status behavior subject to current publication rules |
| Bulk | Create, update, change status, assign terms, trash, restore; 1–50 explicit items with individual results |

Lists default to the caller's own records when the caller lacks access to other authors' management data. A separate `public` read scope may return only records and fields that the same visitor identity could view on the site; it must honor login, membership, directory, and extension restrictions. Private/draft/trash management queries require the corresponding access. Counts and pagination totals must reflect only the authorized result set.

Search parameters are declared in the schema. Unknown or unsupported filters fail validation rather than silently returning an unfiltered result. Author filters never widen authorization. A client must discover supported native status values instead of treating expired, featured, payment, and WordPress publication status as interchangeable.

## User stories and acceptance criteria

### LST-01 — Find and inspect authorized listings

As a listing owner or staff member, I want to search and inspect eligible listings so that I can identify the exact record to manage.

- **LST-01-AC1:** Given an owner without others-management permissions, when listing management records with no author filter, then only eligible own records are returned, and totals do not include inaccessible records.
- **LST-01-AC2:** Given permitted filters and pagination, when searching, then every returned row satisfies both the filters and access policy, ordered deterministically with ID as a tie-breaker.
- **LST-01-AC3:** Given another owner's protected listing ID, when requesting detail or setting an author filter, then the caller cannot obtain protected content or infer existence from a different error shape; public reads return only site-visible fields.

### LST-02 — Discover the form and create a listing

As a listing owner, I want to discover the directory's required fields and create a listing so that submission succeeds under the same policies as the native form.

- **LST-02-AC1:** Given an eligible directory, when requesting the listing schema, then the response contains supported fields, stable keys/types, constraints, required consents, term/media constraints, and effective submission/status permissions without exposing full builder configuration.
- **LST-02-AC2:** Given valid supported inputs and explicit required consent values, when creating, then one listing is created for the current user, native validation and submission hooks apply, and the response shows the actual saved status rather than an assumed published state.
- **LST-02-AC3:** Given a missing required field, invalid directory, unavailable plan entitlement, or unsatisfied consent, when creating, then no listing is created and actionable field/policy errors are returned; the tool never fabricates consent or purchases entitlement.
- **LST-02-AC4:** Given a retry after timeout with the same idempotency key and payload, when the first creation committed, then the same saved ID is returned without a duplicate listing or duplicate submission side effects.

### LST-03 — Edit supported listing data

As a listing owner or authorized staff member, I want to update selected fields so that information remains accurate without losing other data.

- **LST-03-AC1:** Given permission to edit the record and a valid change, when saving, then only explicit supported fields change, native edit-status policy and hooks apply, and effective status/normalized values are returned.
- **LST-03-AC2:** Given an invalid changed field or a required field missing from the resulting applicable submission schema, when validating, then no requested field is saved and errors identify the problem.
- **LST-03-AC3:** Given existing custom, removed-builder, or unsupported extension metadata, when editing an unrelated supported field, then that data is retained; arbitrary `meta`, ownership, directory-transfer, and entitlement changes are rejected.
- **LST-03-AC4:** Given a supplied record version that no longer matches, when saving, then a conflict requires refetching; the update must not overwrite a concurrent edit.

### LST-04 — Apply eligible status transitions

As an authorized moderator or owner, I want to change a listing's status where policy permits so that I can manage its lifecycle.

- **LST-04-AC1:** Given a listing, when discovering allowed status actions, then only transitions permitted by effective object capabilities, publication capability, directory submission/edit policy, and extension constraints are advertised.
- **LST-04-AC2:** Given a valid preview and confirmation for a permitted transition, when executed, then the actual native status is returned and normal publication/moderation notifications occur at most once for that logical transition.
- **LST-04-AC3:** Given edit permission without the required publish/moderation capability or an unmet payment/plan requirement, when requesting publication or renewal, then the transition is denied rather than bypassing the native workflow.

### LST-05 — Assign terms and existing media

As a listing owner or staff member, I want to assign eligible categories, locations, tags, and media so that my listing is organized and complete.

- **LST-05-AC1:** Given edit access and the taxonomy's effective `assign_terms` capability, when adding, removing, or explicitly replacing term IDs, then only valid terms compatible with that directory are assigned; creating missing terms requires separate term-management permission.
- **LST-05-AC2:** Given existing attachment IDs meeting field type/count and attachment access rules, when attaching/reordering/removing them from the listing, then the references change without deleting the underlying files.
- **LST-05-AC3:** Given an unauthorized attachment, incompatible term, unknown term ID, or unsupported remote media URL, when validating, then the requested record change fails without partial assignment or media changes.

### LST-06 — Trash and restore

As a listing owner or staff member, I want to trash and restore eligible listings so that I can remove obsolete entries without permanent data loss.

- **LST-06-AC1:** Given effective delete permission and a confirmed trash preview, when executed, then the listing moves to WordPress Trash and listing data/media files are retained; if site configuration would force permanent deletion instead, reject with `unsupported_operation`.
- **LST-06-AC2:** Given a trashed listing and current edit/delete plus any required publish capability, when restore is confirmed, then the native restore workflow runs, subject to current status/extension rules, and the actual restored status is reported.
- **LST-06-AC3:** Given a request for permanent deletion or a cross-owner listing without the required object permission, when called, then no record is deleted or changed, even with a previously issued token.

### LST-07 — Apply bounded bulk changes

As a staff member or listing owner, I want to change an explicit set of listings so that repetitive work is efficient and each outcome is visible.

- **LST-07-AC1:** Given 1–50 explicit items with unique client references, when previewing, then each item is checked independently and the response separates eligible proposed changes from validation/access failures without changing records.
- **LST-07-AC2:** Given confirmation of that exact batch, when executed, then eligible unchanged items execute under fresh permissions, failed/inaccessible/stale items remain unchanged, and every client reference receives a result with saved ID/status or safe error.
- **LST-07-AC3:** Given more than 50 items, duplicate client references, a nested bulk request, or a query meaning “all matches,” when requested, then the whole envelope is rejected before processing any item.
- **LST-07-AC4:** Given a partially completed batch and an execution retry with the same idempotency key, when replayed, then committed items and their side effects are not duplicated; per-item results identify committed, failed, and indeterminate outcomes.

## Write and business-rule contract

- Creation binds authorship to the current user in V1. Changing authors or moving directory types is out of scope; staff can edit existing others' records only when their object permissions allow it.
- Core fields include supported title/content/excerpt, tagline, contact/address/map values, pricing-display fields, social links, existing images/files, and registered custom fields. Actual editable keys come from the discovered schema; raw metadata paths are never accepted.
- Pricing display is different from payment/plan entitlement. Existing featured flags, never-expire, expiry, claimed/verified state, and extension entitlements are read-only through V1 MCP; administrative changes to these properties remain in native Directorist workflows. MCP creation/edit/status actions must still honor those properties and cannot grant, renew, or override an entitlement. Directory-level default-expiration configuration is covered separately by PRD 03 and does not rewrite existing listing expiry.
- Every mutation supports dry run and requires an idempotency key for execution. All explicit status transitions, public initial publication, trash, restore, and bulk executions require confirmation. Ordinary single-record content edits and creation resulting in a non-public draft/pending state may execute without a token after validation.
- Supply the discovered version on existing-record mutations. Recheck current schema, field access, required applicable fields, entitlements, and referenced objects before commit. Empty versus omitted fields have distinct, schema-defined meanings.
- Bulk items do not reference objects being created elsewhere in the same batch; they use existing IDs. Partial success is allowed across records, never within one listing's validated data update. Unrelated successful items are not rolled back because another item fails.

Supported [order workflows](10-order-management-prd.md) may cause native fulfillment/entitlement effects after validated payment/refund actions. This does not permit direct entitlement overrides through listing tools. Extension-owned fields/policies follow [PRD 11](11-extension-extensibility-prd.md).

## Verification

Use owners A/B, an editor, an administrator, and custom roles with individual capabilities removed. Include private, draft, published, trash, expired, featured, and extension-constrained examples where supported. Verify native UI/frontend results, preserved metadata, hook effects, and no duplicate notifications on retry. See the [acceptance matrix](08-acceptance-test-matrix.md).
