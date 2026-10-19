# Directorist MCP

Directorist exposes 53 core tools for settings, directory configuration, listings, listing taxonomies, orders, dashboard data, Site Health Info and integration discovery, plus explicitly registered extension tools. See [dashboard and Site Health](dashboard-health.md) for read contracts, privacy, extension adapters and supported bounds. The feature ships in core and defaults to disabled. Connecting uses the user's existing WordPress capabilities.

## Enable and connect

1. Use WordPress 6.9+ with the [official MCP Adapter](https://wordpress.org/plugins/mcp-adapter/) active. The integration checks for adapter 0.6.1+. A missing dependency leaves normal Directorist operation available.
2. Open **Directorist → Settings → Other Features → MCP**. Enable the feature, then click **Configure**.
3. The **MCP Connection** drawer shows the site-specific endpoint, prerequisites and five client tabs. Enablement saves immediately through the native nonce-protected settings endpoint. General Save changes does not overwrite the independently saved preference.
4. Each connecting user creates a separately named WordPress application password in their own profile. Use trusted HTTPS and that user's account. The drawer fills snippets locally; credentials are never submitted by this field. A copied filled snippet contains a secret.
5. Configure an HTTP client with an Authorization header, or a stdio bridge. The endpoint comes from WordPress rest_url( 'directorist/mcp' ), including any site subdirectory.
6. Initialize MCP, send the initialized notification, discover tools, then call **directorist-get-context**. Confirm the returned user and permitted tool groups.
7. Revoke the client's named application password in WordPress to disconnect it. Disabling MCP rejects subsequent Directorist execution, including alternate ability entry points. It does not revoke the credential's access to other WordPress APIs.

The drawer includes Claude Code, Claude Desktop, Cursor, Codex and Other examples. These examples do not certify every desktop release. The Claude Desktop example pins **@automattic/mcp-wordpress-remote@0.5.1**, requiring Node.js 18+. That bridge was verified with Node.js 22, application passwords and a custom endpoint over verified TLS. Authentication variables follow the [official bridge documentation](https://github.com/Automattic/mcp-wordpress-remote).

OAuth-only connections, automatic charging/capture, subscription lifecycle mutations, arbitrary code/database access, remote uploads and permanent listing/order/ledger deletion remain outside this release. Authorized offline recording and registered refund workflows follow the [order contract](orders.md).

### Troubleshooting

| Symptom | Check |
|---|---|
| Setup required | Abilities API, adapter version/activation and WordPress version. Refresh after resolving dependencies. |
| 404 | Copy the full endpoint; check REST routing and proxy rules. A disabled server may not register its route. |
| Authentication failure | Username, named application password, revocation and WordPress application-password policy. |
| Forbidden tool | Effective capabilities and object ownership. |
| TLS failure | Fix the certificate chain or add the local CA to the client trust store. Keep certificate validation enabled. |
| Anonymous requests | Check that the web server/proxy forwards Authorization to WordPress. |
| Protocol/session error | Use a revision supported by the installed adapter; retain negotiated headers and complete initialization. |
| state_changed | Read current state and preview again. |
| outcome_unknown | Inspect affected objects and reconcile the request ID before submitting a new key. |

A credential-free guide is at **/wp-json/directorist/v1/mcp/guide**. Configuration status and snippet templates require **manage_options**.

## Tool contract

Abilities use **directorist/action**; HTTP names use **directorist-action**. Catalog::definitions() resolves the versioned registry for the runtime inventory and schemas. See [order management](orders.md), [extension API/developer kit](extensions.md) and the [implementation verification record](verification.md).

| Group | Actions |
|---|---|
| Context | get-context, get-reference-data |
| Settings | discover-settings, get-settings, update-settings, reset-settings |
| Directories | list-directories, get-directory, get-builder-schema, create-directory, duplicate-directory, update-directory, set-default-directory, delete-directory, update-builder |
| Listings | list-listings, get-listing, get-listing-schema, create-listing, update-listing, change-listing-status, trash-listing, restore-listing, bulk-listings |
| Taxonomies | list-taxonomies, list-terms, get-term, create-term, update-term, delete-term, bulk-terms |
| Orders | get-order-schema, list-orders, get-order, create-order, update-order, change-order-status, cancel-order, record-order-payment, list-order-payments, list-order-refunds, create-order-refund, bulk-orders, get-order-operation |
| Integrations | list-mcp-extensions; registered owner-action tools and explicitly opted-in bulk/external-operation readers |

Pagination defaults to 20, capped at 100. Bulk accepts 1–50 explicit items, one action, unique client_ref values and one taxonomy for term batches. Nested batches and implicit cross-item references are rejected.

Every mutation requires **idempotency_key** on execution. Use **dry_run: true** to validate and obtain changes, current version and a five-minute **confirm_token**. Existing listing/directory/term mutations require **expected_version**. Settings may supply the version for the same selected keys. Builder operations use discovered configuration keys and stable IDs.

Settings, directory/builder changes, bulk, deletion, trash/restore, publication and consequential term changes require previews. Content edits that change effective status also require confirmation. Tokens bind site, user, operation, payload, state and enablement epoch. Execution rechecks live permissions.

Successful responses include success, committed and idempotent_replay. Bulk includes individual outcomes and totals. Retry the exact successful payload with the same key within 24 hours to recover its result; changing inputs with that key is rejected. Replays recheck access and exclude stale field snapshots.

### Data behavior

- Settings use selected native atbdp_option keys and existing save hooks. Secrets, action controls, MCP policy and arbitrary options are excluded. Unsupported structured settings are read-only.
- Builder discovery returns widgets, options, sections, templates and slot constraints. Fields receive stable storage keys. Cards/headers use populated native widget records and enforce slot capacity. Removal preserves listing values; unknown references and conditional cycles fail validation.
- Listings use native validation, consents, status policy and hooks. Partial edits retain unrelated data. Ownership, featured status, expiration and entitlement overrides are rejected.
- Images accept existing authorized attachment IDs. File fields accept an authorized attachment ID or 0 to remove the reference; reads return the native stored URL. Type/size constraints apply. Native temporary-upload moving/deletion is not invoked for existing file references.
- Categories/locations support hierarchy; tags are flat. Associations and assignments validate directory compatibility. Deletion rejects dependent listings, children and registered protection rules.

## Extension settings

Extensions opt in through **directorist_mcp_settings**. Adding an admin settings field alone does not expose it.

    add_filter( 'directorist_mcp_settings', static function ( $settings ) {
        $settings['my_extension_summary'] = [
            'owner'           => 'my-extension',
            'label'           => 'Summary',
            'schema'          => [ 'type' => 'string', 'maxLength' => 500 ],
            'default'         => '',
            'capability'      => 'manage_options',
            'storage_adapter' => 'my-extension-options',
            'sensitive'       => false,
            'resettable'      => true,
            'read'            => static function () {
                return get_option( 'my_extension_options', [] )['my_extension_summary'] ?? '';
            },
            'validate'        => static function ( $value ) {
                return sanitize_text_field( $value ); // Or WP_Error.
            },
            'write'           => 'my_extension_write_mcp_settings',
        ];
        return $settings;
    } );

    function my_extension_write_mcp_settings( array $changes ) {
        $options = get_option( 'my_extension_options', [] );
        update_option( 'my_extension_options', array_replace( $options, $changes ), false );
        return true; // Or WP_Error, causing rollback.
    }

Use one stable writer callable per storage adapter. It receives the complete validated change set once. Requests cannot mix independent adapters. Validators must be side-effect free; writers must preserve unrelated keys, use transactional WordPress storage, report failures and avoid external irreversible effects. Removing registration immediately removes support and blocks pending writes.

| Hook | Purpose |
|---|---|
| directorist_mcp_listing_policy | Enforce entitlement rules; paid-plan operations default to blocked without explicit integration. Return boolean or WP_Error. |
| directorist_mcp_builder_widget_allowlist | Explicitly admit inspected extension widgets; native schema constraints still apply. |
| directorist_mcp_directory_has_dependencies | Protect directory deletion for extension dependencies. |
| directorist_mcp_term_has_dependencies | Protect term deletion for extension dependencies. |
| directorist_mcp_public_listing_visible | Restrict the minimal public projection. |
| directorist_mcp_listing_read_query | Apply extension visibility restrictions to queries and totals. |
| directorist_mcp_operation_event | Safe IDs, actor, tool, outcome, error code and request ID; excludes credentials, tokens, values and idempotency keys. |

## Storage and compatibility

Writes require transactional InnoDB core tables and MySQL/MariaDB advisory locks. Settings/builder units commit atomically; bulk commits one record at a time. Cooperating native settings/content/taxonomy writes take the same lock. Raw SQL from integrations cannot participate automatically.

Database rollback cannot retract email, network calls, filesystem changes or arbitrary extension-hook side effects. Integrations must validate before writing and provide safe policies. An unclean execution retains an indeterminate retry reservation. Preview tokens expire after five minutes; outcomes expire after 24 hours and are cleaned by directorist_mcp_cleanup.

No roles, capabilities, tables or existing product data are migrated. The native order/payment/refund tables are existing transactional participants, not new MCP tables. Durable financial and extension-operation references are retained separately from the ordinary replay cleanup. The dedicated Vue field uses the settings registry and ships directly as **assets/js/directorist-mcp-settings.js** with scoped CSS. No generated legacy bundle is modified; no build is needed for these assets.

## Verification

On a disposable local WordPress site:

    wp eval-file wp-content/plugins/directorist/tests/wp-cli/directorist-mcp.php

The suite requires the local environment type, suppresses mail, creates disposable fixtures, restores settings/default metadata and removes its fixtures in finally.

The HTTP test creates/revokes its own application password for an existing disposable account beginning mcp_ or directorist_mcp_:

    python3 wp-content/plugins/directorist/tests/mcp-http-smoke.py \
      --user directorist_mcp_test_account \
      --url https://example.test/wp-json/directorist/mcp \
      --bridge

For Local, add --socket /absolute/path/mysql.sock and --ca /absolute/path/ca.crt. TLS verification stays enabled.

Verified on 2026-10-18 with WordPress 7.1.3, Directorist 8.10.5, PHP 8.2 CLI / PHP 8.0 web, adapter 0.6.1 and Node.js 22. The original local suite passed **153 assertions** before the order/extension expansion. Current checks and remaining release gates are recorded in [verification](verification.md). PHP coding standards, PHP/JavaScript syntax and PRD links also passed. Checks cover feature-off/alternate-entry guards, owner isolation, revoked capabilities, settings units, extension absence/deactivation, rollback, native builder output, taxonomy safety, image/file preservation, stale state, retries and mixed bulk outcomes. HTTP and pinned-bridge tests exercise real initialization, discovery, execution and revocation.

Desktop browser QA covers root navigation/deep links, conditional Configure, drawer tabs, local dummy-credential filling, cleanup, Escape and focus restoration. The immediate native disable save was verified to hide Configure while preserving an exact hash of all sibling settings. MCP was left disabled and temporary accounts/credentials were removed. Responsive CSS is included; mobile devices, every named client release, all extension combinations and the full PRD matrix still require release QA. These checks do not claim completion of every PRD acceptance scenario.
