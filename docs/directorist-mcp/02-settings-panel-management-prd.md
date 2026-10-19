# PRD 02 — Settings Panel Management

Status: proposed V1. Related: [access and safety](06-access-control-and-write-safety-prd.md), [catalog](07-mcp-tool-catalog.md), [feasibility](09-reference-and-feasibility.md).

## Outcome and baseline

An administrator can ask an assistant to inspect and change supported Directorist settings without guessing option keys or overwriting unrelated configuration. Current settings are assembled in PHP, rendered by the Vue settings manager, and saved into `atbdp_option`. Existing save hooks can affect routing, caches, maps, emails, and extensions. MCP must preserve that behavior while adding typed discovery, validation, and controlled writes.

All operations in this PRD require `manage_options`; extension registrations can require additional capabilities. An extension cannot weaken the base requirement.

## Settings coverage

| Area | Required supported behavior/examples | Boundaries |
|---|---|---|
| Directory and listing display | Pagination, grid/list availability, image presentation, taxonomy display | Preserve valid stored keys and dependent values |
| Submission and lifecycle policy | Guest submission, status rules, expiration/cleanup settings | Preview must describe public-access or automatic-cleanup effects |
| Search | Search-form display and result presentation | Builder configuration remains under PRD 03 |
| Users and accounts | Registration, login, dashboard, author display controls | No direct user creation, role changes, or capability changes |
| Map | Provider selection, zoom, default coordinates, coverage | API keys and other secrets remain wp-admin only |
| Reviews | Enablement, owner/guest review rules, moderation, reply behavior | Review records themselves are outside this feature |
| Notifications | Supported event toggles, templates, display names | No passwords, push-provider credentials, or direct “send now” actions |
| Appearance | Styling, supported badge definitions and presentation | Validate structured values and preserve unknown extension state |
| Monetization | Non-secret display currency, gateway selection and presentation | No credential writes, payment capture, subscription changes, or entitlement grants |
| Site and pages | Valid page assignments, SEO/schema and permalink settings | Validate referenced objects; preview routing effects and invoke native side effects |
| Registered extensions | Explicitly supported fields from active extensions | No automatic exposure from field name or raw option enumeration |

Every registered core field must receive an explicit supported, read-only, or excluded classification during implementation. Do not silently omit difficult fields or describe every UI control as editable. Action buttons that install software, connect external accounts, register webhooks, import whole settings files, or run maintenance are excluded from generic setting writes. Selected-key resets replace whole-blob import/export in V1.

The dedicated MCP configuration field under Other Features → MCP is an administrator connection control, not an ordinary remotely editable setting. Its enable switch, Configure drawer, credential inputs and snippet actions are excluded from these tools. The field's targeted enablement save and general-panel-save exclusion are defined in [PRD 01](01-mcp-settings-and-setup-prd.md).

## User stories and acceptance criteria

### SET-01 — Discover supported settings

As a site administrator, I want a settings schema organized like the panel so that I can identify the correct field and valid values before changing it.

- **SET-01-AC1:** Given registered core fields, when discovering settings, then each exposed field includes its stable key, label, group, data type, default, constraints, dependency rules, source, and readable/writable/resettable classification.
- **SET-01-AC2:** Given a field depending on another setting, when its schema is requested, then the assistant receives the dependency and whether the field is currently active; conditional visibility is not treated as permission enforcement.
- **SET-01-AC3:** Given an unknown or sensitive key, when discovered or requested directly, then its stored value is never returned; unsupported keys produce an actionable error rather than an apparently successful empty update.

### SET-02 — Read effective values

As a site administrator, I want current and default values for selected settings so that I can explain the site's behavior without exporting the entire options store.

- **SET-02-AC1:** Given selected readable keys or a supported group, when reading, then only those authorized settings are returned with effective value, default, and whether a saved override exists.
- **SET-02-AC2:** Given an unset boolean, numeric zero, empty list, or empty string, when reading, then its distinction from “unset” is preserved according to the field schema.
- **SET-02-AC3:** Given a request for settings with secret siblings in the same option object, when the response is serialized, then neither the secret values nor the raw shared option blob appear.

### SET-03 — Preview and update a change set

As a site administrator, I want to preview a targeted settings change so that I understand its effect before it changes the site.

- **SET-03-AC1:** Given a valid change set and `dry_run: true`, when previewed, then the response contains normalized before/after values, affected keys, relevant warnings, and a confirmation token without saving configuration or firing update side effects.
- **SET-03-AC2:** Given a valid confirmed change set, when executed, then only supplied supported keys change, unrelated core/extension values remain intact, and the existing update behavior including `directorist_options_updated` occurs once for the logical settings update.
- **SET-03-AC3:** Given one invalid key, type, referenced page, range, or dependency in the set, when validating, then no setting in that set is saved and field-level errors identify how to correct it.
- **SET-03-AC4:** Given configuration changed after preview, when the same confirmation is executed, then a conflict requires a new preview; an unrelated setting outside the affected dependency set must not be overwritten.

### SET-04 — Reset selected settings

As a site administrator, I want to reset explicit settings to their registered defaults so that I can undo customization without losing unrelated configuration.

- **SET-04-AC1:** Given explicit resettable keys, when previewing reset, then the exact registered defaults and affected behavior are shown; no implicit “reset all” operation is available.
- **SET-04-AC2:** Given a confirmed reset, when executed, then subsequent effective reads match the registered defaults and other settings retain their values.
- **SET-04-AC3:** Given a secret, unsupported field, or a field without a meaningful reset contract, when included, then the entire change set fails validation before saving.

### SET-05 — Manage registered extension settings

As an administrator using an extension, I want its supported settings exposed with the same rules as core so that my assistant can configure it safely.

- **SET-05-AC1:** Given an active extension with a complete explicit registration, when discovering settings, then its fields identify their owning extension, constraints, additional capabilities, and read/write/reset behavior.
- **SET-05-AC2:** Given an unregistered field or an extension that has been deactivated since preview, when reading or writing that field, then MCP rejects the request and preserves stored extension data.
- **SET-05-AC3:** Given a registered extension field, when its validator fails or its save adapter cannot uphold the change-set contract, then no partial successful settings response is returned; unsafe mixed-owner change sets are rejected before any write.

The broader tool/widget/domain registration and lifecycle requirements are specified in [PRD 11](11-extension-extensibility-prd.md). Existing settings registrations must remain compatible; appearing in the admin panel alone never grants MCP exposure.

## Change-set and extension contract

Settings writes operate on explicit keys, never JSON paths into arbitrary options. Normalize and validate types, enums, ranges, references, and conditional dependencies using the resulting combined state. Do not clear inactive dependent values just because the UI hides them. Existing unmodified legacy values must survive targeted changes.

Core-supported settings continue to use `atbdp_option` and native update hooks. An extension registering a field provides: stable extension/field identity, owning storage adapter, schema/defaults, sensitivity, field capability requirements, validator/normalizer, read/update/reset contract, dependency rules, and relevant side effects. Credentials are excluded even if registered as ordinary strings.

V1 permits a settings change set only when all fields share a save adapter that guarantees a single validated persistence unit. Core plus extension fields sharing the core option adapter may be updated together. Fields in independent extension stores use separate calls; cross-store transactions are not promised. Reject mixed adapters before saving. Failures after persistence must distinguish committed state from hook/side-effect errors so callers do not blindly repeat an already committed change.

Unknown extension fields are preserved but never made writable merely because they appear in a filtered UI schema. The core implementation supplies the registration mechanism and an example contract; integrating every commercial extension is not a V1 prerequisite. At least one controlled test extension must exercise the contract in release QA.

## Examples and verification

- “Set listings per page to 24” discovers `all_listing_page_items`, previews the change, and commits only that supported key after confirmation.
- “Use this page for the directory dashboard” validates an eligible page reference and warns about affected navigation before saving.
- “Show my map API key” returns a sensitive-field exclusion, not a redacted raw options dump.

Verify persistence through the normal settings UI and affected frontend views, not only the MCP response. Full scenarios are in the [acceptance matrix](08-acceptance-test-matrix.md).
