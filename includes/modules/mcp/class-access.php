<?php
namespace Directorist\MCP;

defined( 'ABSPATH' ) || exit;

class Access {
    public static function transport( $request = null ) {
        try {
            self::connected();
            return true;
        } catch ( Failure $error ) {
            return $error->as_error();
        }
    }

    public static function connected() {
        Support::check( Module::enabled(), __( 'Directorist MCP is disabled.', 'directorist' ), 'mcp_disabled' );
        Support::check( Module::dependencies(), __( 'A compatible WordPress Abilities API and MCP Adapter are required.', 'directorist' ), 'dependency_unavailable' );
        Support::check( is_user_logged_in(), __( 'Authentication is required.', 'directorist' ), 'unauthenticated' );
        $post = get_post_type_object( ATBDP_POST_TYPE );
        $can  = current_user_can( 'manage_options' ) || ( $post && current_user_can( $post->cap->edit_posts ) );
        foreach ( [ ATBDP_CATEGORY, ATBDP_LOCATION, ATBDP_TAGS ] as $taxonomy ) {
            $tax = get_taxonomy( $taxonomy );
            $can = $can || ( $tax && ( current_user_can( $tax->cap->manage_terms ) || current_user_can( $tax->cap->edit_terms ) || current_user_can( $tax->cap->assign_terms ) ) );
        }
        Support::check( $can || Orders::admission() || Registry::instance()->admission(), __( 'Your account does not have Directorist access.', 'directorist' ), 'forbidden' );
    }

    public static function admin() {
        Support::check( current_user_can( 'manage_options' ), __( 'You cannot manage Directorist configuration.', 'directorist' ), 'forbidden' );
    }

    public static function listing_domain() {
        $type = get_post_type_object( ATBDP_POST_TYPE );
        Support::check( current_user_can( 'manage_options' ) || ( $type && current_user_can( $type->cap->edit_posts ) ), __( 'Listing access is required.', 'directorist' ), 'forbidden' );
    }

    public static function permission( $tool, array $args = [] ) {
        $definitions = Catalog::definitions();
        $d           = $definitions[ $tool ] ?? [];
        if ( empty( $d['write'] ) && ( in_array( $d['group'] ?? '', [ 'dashboard', 'site-health-info' ], true ) || in_array( $d['domain'] ?? '', [ 'dashboard', 'site_health_info' ], true ) ) ) {
            return Read_Guard::run(
                static function () use ( $tool, $args ) { return self::authorize( $tool, $args );
                }, false, true
            );
        }
        return self::authorize( $tool, $args );
    }

    private static function authorize( $tool, array $args ) {
        self::connected();
        $definitions = Catalog::definitions();
        Support::check( isset( $definitions[ $tool ] ), __( 'Unsupported tool.', 'directorist' ), 'unsupported_operation' );
        $definition = $definitions[ $tool ];
        if ( isset( $definition['owner'] ) ) {
            return Registry::instance()->permission( $tool, $args );
        }
        if ( 'dashboard' === $definition['group'] ) {
            Dashboard::permission( $tool, $args );
        } elseif ( 'site-health-info' === $definition['group'] ) {
            Site_Health::permission();
        } elseif ( 'orders' === $definition['group'] ) {
            Orders::permission( $tool, $args );
        } elseif ( 'listings' === $definition['group'] || ( 'get-reference-data' === $tool && 'order_references' !== ( $args['kind'] ?? '' ) ) ) {
            self::listing_domain();
        } elseif ( 'taxonomies' === $definition['group'] && 'list-taxonomies' === $tool ) {
            $can = false;
            foreach ( [ ATBDP_CATEGORY, ATBDP_LOCATION, ATBDP_TAGS ] as $taxonomy ) {
                $tax = get_taxonomy( $taxonomy );
                $can = $can || ( $tax && ( current_user_can( $tax->cap->manage_terms ) || current_user_can( $tax->cap->assign_terms ) ) );
            }
            Support::check( $can, __( 'Taxonomy access is required.', 'directorist' ), 'forbidden' );
        }
        Registry::instance()->permission( $tool, $args );
        if ( preg_match( '/^(discover-settings|get-settings|update-settings|reset-settings|list-directories|get-directory|get-builder-schema|create-directory|duplicate-directory|update-directory|set-default-directory|delete-directory|update-builder)$/', $tool ) ) {
            self::admin();
        }
        if ( 'listings' === $definition['group'] && isset( $args['listing_id'] ) ) {
            $action = in_array( $tool, [ 'get-listing', 'get-listing-schema' ], true ) ? 'read' : 'edit';
            if ( in_array( $tool, [ 'trash-listing', 'restore-listing' ], true ) ) {
                $action = 'delete';
            }
            self::listing( $args['listing_id'], $action, $args['scope'] ?? 'management' );
        }
        if ( in_array( $tool, [ 'list-terms', 'get-term', 'create-term', 'update-term', 'delete-term', 'bulk-terms' ], true ) && isset( $args['taxonomy'] ) ) {
            $operation = 'read';
            if ( in_array( $tool, [ 'create-term', 'update-term' ], true ) ) {
                $operation = 'edit';
            } elseif ( 'delete-term' === $tool ) {
                $operation = 'delete';
            } elseif ( 'bulk-terms' === $tool ) {
                $operation = 'delete' === ( $args['action'] ?? '' ) ? 'delete' : 'edit';
            }
            self::term_cap( $args['taxonomy'], $operation );
        }
        return true;
    }

    public static function listing( $id, $action = 'read', $scope = 'management' ) {
        $post = get_post( Support::id( $id ) );
        $can  = $post && ATBDP_POST_TYPE === $post->post_type && current_user_can( $action . '_post', $id );
        if ( 'public' === $scope && 'read' === $action ) {
            $can = $post && ATBDP_POST_TYPE === $post->post_type && self::public_listing( $post );
        } elseif ( 'read' === $action && $post && (int) $post->post_author !== get_current_user_id() ) {
            $type = get_post_type_object( ATBDP_POST_TYPE );
            $can  = $can && current_user_can( $type->cap->edit_others_posts );
        }
        Support::check( $can, __( 'Listing not found.', 'directorist' ), 'not_found' );
        return $post;
    }

    public static function public_listing( $post ) {
        // Only the deliberately minimal public projection is exposed; never private custom fields.
        $visible = 'publish' === $post->post_status && ! $post->post_password && ! get_directorist_option( 'disable_single_listing', false );
        return (bool) apply_filters( 'directorist_mcp_public_listing_visible', $visible, $post, get_current_user_id() );
    }

    public static function term_cap( $taxonomy, $action ) {
        $taxonomy = Support::taxonomy( $taxonomy );
        $tax      = get_taxonomy( $taxonomy );
        $map      = [ 'read' => 'manage_terms', 'edit' => 'edit_terms', 'delete' => 'delete_terms', 'assign' => 'assign_terms' ];
        Support::check(
            isset( $map[ $action ] ) && $tax && current_user_can(
                $tax->cap->{$map[ $action ]
                }
            ), __( 'You cannot perform this taxonomy operation.', 'directorist' ), 'forbidden'
        );
        $context = [ 'read' => 'read', 'edit' => 'edit', 'delete' => 'delete', 'assign' => 'assign' ][ $action ];
        Support::check( apply_filters( 'directorist_rest_check_permissions', true, $context, 0, $taxonomy ), __( 'Taxonomy access is restricted.', 'directorist' ), 'forbidden' );
    }

    public static function attachment( $id, $image = false ) {
        $file = get_post( Support::id( $id ) );
        Support::check( $file && 'attachment' === $file->post_type && current_user_can( 'edit_post', $id ) && ( ! $image || wp_attachment_is_image( $id ) ), __( 'The attachment is unavailable or not permitted.', 'directorist' ) );
        return $id;
    }
}
