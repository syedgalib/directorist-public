<?php
namespace Directorist\MCP;

defined( 'ABSPATH' ) || exit;

/** The public allowlist. Schemas and dispatch use the same inventory. */
class Catalog {
    public static function definitions() {
        return Registry::instance()->definitions();
    }

    public static function core_definitions() {
        $s           = [ 'type' => 'string' ];
        $i           = [ 'type' => 'integer', 'minimum' => 1 ];
        $o           = [ 'type' => 'object', 'additionalProperties' => true ];
        $ids         = [ 'type' => 'array', 'items' => $i, 'maxItems' => 100 ];
        $strings     = [ 'type' => 'array', 'items' => $s, 'maxItems' => 100 ];
        $enum        = static function ( array $values ) {
            return [ 'type' => 'string', 'enum' => $values ];
        };
        $paging      = [ 'page' => $i, 'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ], 'search' => $s ];
        $directory   = [ 'directory_id' => $i ];
        $listing     = [ 'listing_id' => $i ];
        $taxonomy    = [ 'taxonomy' => $enum( [ ATBDP_CATEGORY, ATBDP_LOCATION, ATBDP_TAGS ] ) ];
        $term        = $taxonomy + [ 'term_id' => $i ];
        $area        = [ 'area' => $enum( [ 'general', 'submission_form', 'search_form', 'single_listing', 'listing_cards' ] ) ];
        $term_fields = [ 'name' => $s, 'slug' => $s, 'description' => $s, 'parent' => [ 'type' => 'integer', 'minimum' => 0 ], 'image' => $i, 'icon' => $s, 'directories' => $ids ];
        $mut         = [ 'dry_run' => [ 'type' => 'boolean' ], 'confirm_token' => $s, 'idempotency_key' => $s, 'expected_version' => $s ];
        $rows        = [
            [ 'get-context', 'context', [], [], false, __( 'Start here. Current identity, permissions, tool groups and limits.', 'directorist' ) ],
            [ 'get-reference-data', 'context', [ 'kind' => $enum( [ 'directories', 'assignment_terms', 'media', 'pages', 'order_references' ] ), 'ids' => $ids ] + $directory + $taxonomy + $paging, [ 'kind' ], false, __( 'Eligible directory, term, media or page choices. Never unrestricted configuration.', 'directorist' ) ],
            [ 'discover-settings', 'settings', [ 'group' => $s, 'keys' => $strings ] + $paging, [], false, __( 'Discover supported non-secret settings, defaults and constraints.', 'directorist' ) ],
            [ 'get-settings', 'settings', [ 'group' => $s, 'keys' => $strings ] + $paging, [], false, __( 'Read selected supported settings. Requires configuration access.', 'directorist' ) ],
            [ 'update-settings', 'settings', [ 'changes' => $o ], [ 'changes' ], true, __( 'Preview and update explicit supported settings as one change set.', 'directorist' ) ],
            [ 'reset-settings', 'settings', [ 'keys' => $strings ], [ 'keys' ], true, __( 'Preview and reset explicit supported settings to registered defaults.', 'directorist' ) ],
            [ 'list-directories', 'directories', $paging + [ 'is_default' => [ 'type' => 'boolean' ] ], [], false, __( 'List directory management summaries. Administrator only.', 'directorist' ) ],
            [ 'get-directory', 'directories', $directory + $area, [ 'directory_id' ], false, __( 'Inspect a directory and its configuration/version.', 'directorist' ) ],
            [ 'get-builder-schema', 'directories', $directory + $area, [ 'directory_id', 'area' ], false, __( 'Discover builder widgets, placements, options and constraints before editing.', 'directorist' ) ],
            [ 'create-directory', 'directories', [ 'name' => $s, 'slug' => $s, 'changes' => $o ], [ 'name' ], true, __( 'Preview and create a directory with native defaults. Multi-directory mode required.', 'directorist' ) ],
            [ 'duplicate-directory', 'directories', $directory + [ 'name' => $s, 'slug' => $s ], [ 'directory_id', 'name' ], true, __( 'Duplicate configuration only; no listings or term associations.', 'directorist' ) ],
            [ 'update-directory', 'directories', $directory + [ 'changes' => $o ], [ 'directory_id', 'changes' ], true, __( 'Preview and update supported directory identity/general policies.', 'directorist' ) ],
            [ 'set-default-directory', 'directories', $directory, [ 'directory_id' ], true, __( 'Preview and select the single default directory.', 'directorist' ) ],
            [ 'delete-directory', 'directories', $directory, [ 'directory_id' ], true, __( 'Delete only an empty, non-default directory without dependencies. Preview required.', 'directorist' ) ],
            [ 'update-builder', 'directories', $directory + $area + [ 'operations' => [ 'type' => 'array', 'items' => $o, 'minItems' => 1, 'maxItems' => 50 ] ], [ 'directory_id', 'area', 'operations' ], true, __( 'Preview typed builder operations on stable field/section IDs. Existing listing values are retained.', 'directorist' ) ],
            [ 'list-listings', 'listings', $paging + $directory + [ 'scope' => $enum( [ 'management', 'public' ] ), 'ids' => $ids, 'author' => $i, 'status' => $s, 'categories' => $ids, 'locations' => $ids, 'tags' => $ids, 'featured' => [ 'type' => 'boolean' ], 'created_after' => $s, 'created_before' => $s, 'modified_after' => $s, 'modified_before' => $s, 'orderby' => $enum( [ 'id', 'title', 'date', 'modified' ] ), 'order' => $enum( [ 'asc', 'desc' ] ) ], [], false, __( 'Find authorized listings with explicit filters and permission-filtered totals.', 'directorist' ) ],
            [ 'get-listing', 'listings', $listing + [ 'scope' => $enum( [ 'management', 'public' ] ) ], [ 'listing_id' ], false, __( 'Read authorized listing fields, actual status, allowed actions and version.', 'directorist' ) ],
            [ 'get-listing-schema', 'listings', $directory + $listing, [ 'directory_id' ], false, __( 'Discover directory-specific fields, required consents and submission constraints.', 'directorist' ) ],
            [ 'create-listing', 'listings', $directory + [ 'fields' => $o, 'consents' => $o, 'status' => $s, 'schema_version' => $s ], [ 'directory_id', 'fields' ], true, __( 'Create for the current user through native submission validation. Public publication requires preview.', 'directorist' ) ],
            [ 'update-listing', 'listings', $listing + [ 'changes' => $o, 'consents' => $o ], [ 'listing_id', 'changes' ], true, __( 'Edit explicit supported fields with native edit policy. No owner/entitlement overrides.', 'directorist' ) ],
            [ 'change-listing-status', 'listings', $listing + [ 'status' => $s ], [ 'listing_id', 'status' ], true, __( 'Preview an eligible native publication/privacy transition.', 'directorist' ) ],
            [ 'trash-listing', 'listings', $listing, [ 'listing_id' ], true, __( 'Preview and trash a listing. Permanent deletion is never supported.', 'directorist' ) ],
            [ 'restore-listing', 'listings', $listing, [ 'listing_id' ], true, __( 'Preview and restore an eligible trashed listing under current policies.', 'directorist' ) ],
            [ 'bulk-listings', 'listings', [ 'action' => $enum( [ 'create', 'update', 'change_status', 'assign_terms', 'trash', 'restore' ] ), 'items' => [ 'type' => 'array', 'minItems' => 1, 'maxItems' => 50, 'items' => $o ] ], [ 'action', 'items' ], true, __( 'Preview up to 50 explicit listing actions; each result is independent.', 'directorist' ) ],
            [ 'list-taxonomies', 'taxonomies', [], [], false, __( 'Discover the three listing taxonomies and current management/assignment permissions.', 'directorist' ) ],
            [ 'list-terms', 'taxonomies', $taxonomy + $directory + $paging + [ 'parent' => [ 'type' => 'integer', 'minimum' => 0 ], 'ids' => $ids ], [ 'taxonomy' ], false, __( 'Read taxonomy management data. Owners use assignment reference lookups instead.', 'directorist' ) ],
            [ 'get-term', 'taxonomies', $term, [ 'taxonomy', 'term_id' ], false, __( 'Read a managed term with directory associations and current version.', 'directorist' ) ],
            [ 'create-term', 'taxonomies', $taxonomy + $term_fields, [ 'taxonomy', 'name' ], true, __( 'Create a supported listing term. Assignment permission alone is insufficient.', 'directorist' ) ],
            [ 'update-term', 'taxonomies', $term + [ 'changes' => $o ], [ 'taxonomy', 'term_id', 'changes' ], true, __( 'Update supported term properties; hierarchy, slug and association changes require preview.', 'directorist' ) ],
            [ 'delete-term', 'taxonomies', $term, [ 'taxonomy', 'term_id' ], true, __( 'Preview and delete only an unused childless term. No cascading deletion or reassignment.', 'directorist' ) ],
            [ 'bulk-terms', 'taxonomies', $taxonomy + [ 'action' => $enum( [ 'create', 'update', 'delete' ] ), 'items' => [ 'type' => 'array', 'minItems' => 1, 'maxItems' => 50, 'items' => $o ] ], [ 'taxonomy', 'action', 'items' ], true, __( 'Preview up to 50 explicit term actions in one taxonomy with individual outcomes.', 'directorist' ) ],
        ];
        $definitions = [];
        foreach ( $rows as $row ) {
            list( $name, $group, $properties, $required, $write, $description ) = $row;
            if ( $write ) {
                $properties += $mut;
            }
            $definitions[ $name ] = [
                'group'       => $group,
                'write'       => $write,
                'description' => $description,
                'schema'      => [ 'type' => 'object', 'properties' => $properties ?: (object) [], 'required' => $required, 'additionalProperties' => false ]
            ];
        }
        $definitions = array_merge(
            $definitions, Orders::definitions(), Dashboard::definitions(), Site_Health::definitions(), [
                'list-mcp-extensions' => [
                    'group'       => 'context',
                    'write'       => false,
                    'description' => __( 'Discover permitted active MCP integrations. Private diagnostics require administration access.', 'directorist' ),
                    'schema'      => [ 'type' => 'object', 'properties' => $paging + [ 'group' => $s, 'diagnostics' => [ 'type' => 'boolean' ], 'operation_id' => [ 'type' => 'string', 'maxLength' => 36 ] ], 'required' => [], 'additionalProperties' => false ],
                ],
            ]
        );
        foreach ( $definitions as $name => &$d ) {
            if ( $d['write'] && in_array( $d['group'], [ 'listings', 'taxonomies', 'orders' ], true ) && 0 !== strpos( $name, 'bulk-' ) ) {
                $d['schema']['properties']['extension_fields'] = $o;
            }
        }
        return $definitions;
    }
}
