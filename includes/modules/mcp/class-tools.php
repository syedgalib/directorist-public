<?php
namespace Directorist\MCP;

defined( 'ABSPATH' ) || exit;

class Tools {
    public static function execute( $tool, array $args = [] ) {
        try {
            $definitions = Catalog::definitions();
            Support::check( isset( $definitions[ $tool ] ), __( 'Unsupported Directorist tool.', 'directorist' ), 'unsupported_operation' );
            Support::check( strlen( wp_json_encode( $args ) ) <= 1048576, __( 'The request is too large.', 'directorist' ) );
            $definition = $definitions[ $tool ];
            Support::allowed( $args, array_keys( (array) $definition['schema']['properties'] ) );
            Support::unwrap( rest_validate_value_from_schema( $args ?: (object) [], $definition['schema'] ) );
            Access::permission( $tool, $args );
            if ( ! $definition['write'] ) {
                return Read_Guard::run(
                    static function () use ( $tool, $args, $definition ) {
                        $result = self::read( $tool, $args, $definition['group'] );
                        if ( isset( $definition['output_schema'] ) ) {
                            Support::check( ! is_wp_error( rest_validate_value_from_schema( $result, $definition['output_schema'] ) ), __( 'The read source returned an invalid result.', 'directorist' ), 'source_invalid' );
                        }
                        return $result;
                    }, false, in_array( $definition['group'], [ 'dashboard', 'site-health-info' ], true ) || in_array( $definition['domain'] ?? '', [ 'dashboard', 'site_health_info' ], true )
                );
            }
            $result = Write_Guard::run(
                $tool, $args, static function () use ( $tool, $args ) {
                    return self::prepare( $tool, $args );
                }
            );
            if ( isset( $result['operation_id'] ) && empty( $result['success'] ) ) {
                Support::fail( 'failed' === $result['state'] ? 'provider_failed' : 'outcome_unknown', __( 'Read the original operation to reconcile this outcome. Do not submit a replacement financial action.', 'directorist' ), $result );
            }
            return $result;
        } catch ( Failure $error ) {
            $result             = $error->as_error();
            $data               = $result->get_error_data();
            $data['request_id'] = Module::request_id();
            $result->add_data( $data );
            return $result;
        } catch ( \Throwable $error ) {
            Support::event( $tool, 'failed', $args, 'operation_failed' );
            return new \WP_Error( 'directorist_mcp_operation_failed', __( 'Directorist could not complete this operation.', 'directorist' ), [ 'code' => 'operation_failed', 'committed' => false, 'retryable' => false, 'request_id' => Module::request_id() ] );
        }
    }

    public static function prepare( $tool, array $args ) {
        $definition = Catalog::definitions()[ $tool ];
        if ( in_array( $tool, [ 'bulk-listings', 'bulk-terms', 'bulk-orders' ], true ) ) {
            return self::bulk( $tool, $args );
        }
        if ( isset( $definition['owner'] ) ) {
            return Registry::instance()->prepare( $tool, $args );
        }
        $classes = [ 'orders' => Orders::class, 'settings' => Settings::class, 'directories' => Directories::class, 'listings' => Listings::class, 'taxonomies' => Taxonomies::class ];
        return Domain_Fields::prepare(
            $tool, $args, $definition['group'], static function ( $base ) use ( $classes, $definition, $tool ) { return call_user_func( [ $classes[ $definition['group'] ], 'prepare' ], $tool, $base );
            }
        );
    }

    private static function read( $tool, array $args, $group ) {
        $definition = Catalog::definitions()[ $tool ];
        if ( isset( $definition['owner'] ) ) {
            return Registry::instance()->read( $tool, $args );
        }
        if ( 'list-mcp-extensions' === $tool ) {
            return Registry::instance()->integrations( $args );
        }
        if ( 'get-context' === $tool ) {
            return self::context();
        }
        if ( 'get-reference-data' === $tool ) {
            return self::references( $args );
        }
        if ( 'dashboard' === $group ) {
            return Dashboard::read( $tool, $args );
        }
        if ( 'site-health-info' === $group ) {
            return Site_Health::read( $tool, $args );
        }
        $classes = [ 'orders' => Orders::class, 'settings' => Settings::class, 'directories' => Directories::class, 'listings' => Listings::class, 'taxonomies' => Taxonomies::class ];
        $result  = call_user_func( [ $classes[ $group ], 'read' ], $tool, $args );
        return Domain_Fields::read( $tool, $args, $group, $result );
    }

    public static function available( $name, array $definition ) {
        if ( ! is_user_logged_in() ) {
            return false;
        }
        if ( ! Registry::instance()->available( $name ) ) {
            return false;
        }
        if ( isset( $definition['owner'] ) ) {
            return true;
        }
        if ( 'site-health-info' === $definition['group'] ) {
            return Site_Health::available();
        }
        if ( 'dashboard' === $definition['group'] ) {
            try {
                Dashboard::permission( $name );
                return true;
            } catch ( Failure $error ) {
                return false;
            }
        }
        if ( 'orders' === $definition['group'] ) {
            return Orders::ready() && ( current_user_can( 'manage_options' ) || in_array( $name, [ 'get-order-schema', 'list-orders', 'get-order', 'cancel-order', 'list-order-payments', 'list-order-refunds', 'get-order-operation' ], true ) );
        }
        if ( 'context' === $definition['group'] && in_array( $name, [ 'get-context', 'list-mcp-extensions' ], true ) ) {
            return true;
        }
        if ( 'list-taxonomies' === $name || 'get-reference-data' === $name ) {
            try {
                Access::listing_domain(); return true;
            } catch ( Failure $error ) {
                return false;
            }
        }
        if ( in_array( $definition['group'], [ 'settings', 'directories' ], true ) ) {
            return current_user_can( 'manage_options' );
        }
        if ( 'listings' === $definition['group'] ) {
            $type = get_post_type_object( ATBDP_POST_TYPE );
            return $type && current_user_can( $type->cap->edit_posts );
        }
        if ( 'taxonomies' === $definition['group'] && 'list-taxonomies' !== $name ) {
            foreach ( [ ATBDP_CATEGORY, ATBDP_LOCATION, ATBDP_TAGS ] as $tax ) {
                $object = get_taxonomy( $tax );
                $cap    = false !== strpos( $name, 'delete' ) ? 'delete_terms' : ( in_array( $name, [ 'list-terms', 'get-term' ], true ) ? 'manage_terms' : 'edit_terms' );
                if ( $object && current_user_can( $object->cap->$cap ) ) {
                    return true;
                }
            }
            return false;
        }
        return true;
    }

    private static function context() {
        $tools  = [];
        $groups = [];
        foreach ( Catalog::definitions() as $name => $definition ) {
            if ( self::available( $name, $definition ) ) {
                $tools[]  = 'directorist-' . $name;
                $groups[] = $definition['group'];
            }
        }
        return [
            'user'      => [ 'id' => get_current_user_id(), 'name' => wp_get_current_user()->display_name ],
            'site'      => [ 'name' => get_bloginfo( 'name' ), 'url' => home_url(), 'timezone' => wp_timezone_string(), 'directorist_version' => ATBDP_VERSION ],
            'enabled'   => true,
            'tools'     => $tools,
            'groups'    => array_values( array_unique( $groups ) ),
            'limits'    => [ 'page_default' => 20, 'page_maximum' => 100, 'bulk_maximum' => 50, 'confirmation_seconds' => 300, 'idempotency_seconds' => DAY_IN_SECONDS ],
            'next_step' => __( 'Discover the relevant schema and exact IDs before making changes. Preview consequential actions; execute with an idempotency key.', 'directorist' )
        ];
    }

    private static function references( array $args ) {
        $kind = $args['kind'];
        if ( 'order_references' === $kind ) {
            return Orders::references( $args );
        }
        if ( 'assignment_terms' === $kind ) {
            Support::check( isset( $args['taxonomy'], $args['directory_id'] ), __( 'Specify taxonomy and directory_id for term choices.', 'directorist' ) );
            return Taxonomies::read( 'list-terms', $args, true );
        }
        if ( 'directories' === $kind ) {
            list( $page, $size ) = Support::paging( $args );
            $query               = [ 'taxonomy' => ATBDP_DIRECTORY_TYPE, 'hide_empty' => false, 'orderby' => 'term_id', 'search' => $args['search'] ?? '' ];
            if ( isset( $args['ids'] ) ) {
                $query['include'] = Support::ids( $args['ids'] );
                if ( ! $query['include'] ) {
                    return Support::collection( [], 0, $page, $size );
                }
            }
            $total = Support::unwrap( wp_count_terms( $query ) );
            $terms = Support::unwrap( get_terms( $query + [ 'number' => $size, 'offset' => ( $page - 1 ) * $size ] ) );
            $type  = get_post_type_object( ATBDP_POST_TYPE );
            return Support::collection(
                array_map(
                    static function ( $term ) use ( $type ) {
                        return [ 'id' => $term->term_id, 'name' => $term->name, 'can_submit' => current_user_can( $type->cap->create_posts ) ];
                    }, $terms
                ), $total, $page, $size
            );
        }
        list( $page, $size ) = Support::paging( $args );
        if ( 'pages' === $kind ) {
            Access::admin();
        }
        Support::check( in_array( $kind, [ 'pages', 'media' ], true ), __( 'Unsupported reference kind.', 'directorist' ) );
        if ( 'media' === $kind && ! current_user_can( 'edit_posts' ) ) {
            return Support::collection( [], 0, $page, $size );
        }
        $query = [
            'post_type'      => 'pages' === $kind ? 'page' : 'attachment',
            'post_status'    => 'pages' === $kind ? [ 'publish', 'private', 'draft' ] : 'inherit',
            'posts_per_page' => $size,
            'paged'          => $page,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            's'              => $args['search'] ?? ''
        ];
        if ( 'media' === $kind && ! current_user_can( 'edit_others_posts' ) ) {
            $query['author'] = get_current_user_id();
        }
        if ( isset( $args['ids'] ) ) {
            $query['post__in'] = Support::ids( $args['ids'] );
            if ( ! $query['post__in'] ) {
                return Support::collection( [], 0, $page, $size );
            }
        }
        $posts = new \WP_Query( $query );
        $items = [];
        foreach ( $posts->posts as $post ) {
            if ( current_user_can( 'pages' === $kind ? 'read_post' : 'edit_post', $post->ID ) ) {
                $items[] = [ 'id' => $post->ID, 'title' => $post->post_title ];
            }
        }
        return Support::collection( $items, $posts->found_posts, $page, $size );
    }

    public static function bulk( $tool, array $args, $registered_child = null ) {
        $listing = 'bulk-listings' === $tool;
        $map     = $listing ? [ 'create' => 'create-listing', 'update' => 'update-listing', 'assign_terms' => 'update-listing', 'change_status' => 'change-listing-status', 'trash' => 'trash-listing', 'restore' => 'restore-listing' ] : [ 'create' => 'create-term', 'update' => 'update-term', 'delete' => 'delete-term' ];
        if ( 'bulk-orders' === $tool ) {
            $map = [ 'update' => 'update-order', 'cancel' => 'cancel-order' ];
        }
        $child = $registered_child ?: $map[ $args['action'] ];
        Support::check( count( $args['items'] ) >= 1 && count( $args['items'] ) <= 50, __( 'A batch requires 1–50 explicit items.', 'directorist' ) );
        $refs        = [];
        $previews    = [];
        $states      = [];
        $definitions = Catalog::definitions();
        $items       = [];
        foreach ( $args['items'] as $input ) {
            $item = Support::object( $input );
            $ref  = $item['client_ref'] ?? '';
            Support::check( is_string( $ref ) && preg_match( '/^[A-Za-z0-9_.:-]{1,64}$/', $ref ) && ! isset( $refs[ $ref ] ), __( 'Each item needs a unique client_ref of at most 64 safe characters.', 'directorist' ) );
            $refs[ $ref ] = true;
            unset( $item['client_ref'] );
            Support::check( ! isset( $item['items'] ) && ! isset( $item['action'] ), __( 'Nested batch actions are not supported.', 'directorist' ) );
            if ( 'bulk-terms' === $tool ) {
                Support::check( ! isset( $item['taxonomy'] ), __( 'Specify taxonomy once at batch level.', 'directorist' ) );
                $item['taxonomy'] = $args['taxonomy'];
            }
            $items[ $ref ] = $item;
            try {
                Support::allowed( $item, array_diff( array_keys( $definitions[ $child ]['schema']['properties'] ), [ 'dry_run', 'confirm_token', 'idempotency_key' ] ) );
                if ( 'assign_terms' === ( $args['action'] ?? '' ) ) {
                    Support::allowed( Support::object( $item['changes'] ?? [] ), [ 'categories', 'locations', 'tags' ] );
                }
                Support::unwrap( rest_validate_value_from_schema( $item ?: (object) [], $definitions[ $child ]['schema'] ) );
                Access::permission( $child, $item );
                $plan           = Read_Guard::run(
                    static function () use ( $child, $item ) { return self::prepare( $child, $item + [ 'dry_run' => true ] );
                    }
                );
                $previews[]     = [ 'client_ref' => $ref, 'success' => true, 'preview' => $plan['preview'] ];
                $states[ $ref ] = Support::hash( $plan['state'] );
            } catch ( Failure $error ) {
                $previews[]     = [ 'client_ref' => $ref, 'success' => false, 'error' => [ 'code' => $error->reason, 'message' => $error->getMessage() ] ];
                $states[ $ref ] = null;
            }
        }
        return [
            'bulk'    => true,
            'state'   => $states,
            'confirm' => true,
            'preview' => [ 'items' => $previews ],
            'execute' => static function ( $reviewed_states ) use ( $items, $child, $args ) {
                $results = [];
                $unknown = false;
                foreach ( $items as $ref => $item ) {
                    $item['idempotency_key'] = 'bulk-' . Support::hash( [ $args['idempotency_key'] ?? '', $ref ] );
                    try {
                        Support::check( ! $unknown, __( 'An earlier item has an uncertain outcome. Reconcile before continuing.', 'directorist' ), 'outcome_unknown' );
                        Support::check( isset( $reviewed_states[ $ref ] ), __( 'This item was not eligible in the preview. Correct it and preview again.', 'directorist' ), 'validation_failed' );
                        Access::permission( $child, $item );
                        $plan = Read_Guard::run(
                            static function () use ( $child, $item ) { return self::prepare( $child, $item );
                            }
                        );
                        Support::check( hash_equals( $reviewed_states[ $ref ], Support::hash( $plan['state'] ) ), __( 'This item changed since preview.', 'directorist' ), 'state_changed' );
                        // Persist a before-item marker so a timeout cannot replay a partially committed item.
                        Write_Guard::checkpoint( array_merge( $results, [ [ 'client_ref' => $ref, 'committed' => 'unknown' ] ] ) );
                        try {
                            $result = ! empty( $plan['orchestrated'] ) ? call_user_func( $plan['execute'] ) : Write_Guard::atomic( $plan['execute'], $plan['tables'] ?? [], $plan['invalidate'] ?? null );
                        } catch ( \Throwable $error ) {
                            $unknown = true;
                            throw new Failure( 'outcome_unknown', __( 'This item may have committed. Read it to reconcile.', 'directorist' ), [ 'committed' => 'unknown' ] );
                        }
                        $unknown   = $unknown || 'unknown' === ( $result['committed'] ?? false );
                        $results[] = [ 'client_ref' => $ref ] + $result;
                        Support::event( $child, 'completed', $item );
                    } catch ( Failure $error ) {
                        $results[] = [ 'client_ref' => $ref, 'success' => false, 'committed' => $error->details['committed'] ?? false, 'error' => [ 'code' => $error->reason, 'message' => $error->getMessage() ] ];
                        Support::event( $child, 'rejected', $item, $error->reason );
                    }
                    Write_Guard::checkpoint( $results );
                }
                $succeeded = count(
                    array_filter(
                        $results, static function ( $r ) {
                            return ! empty( $r['success'] );
                        }
                    )
                );
                return Support::result(
                    [
                        'success'   => $succeeded === count( $results ),
                        'committed' => $succeeded > 0 ? true : ( $unknown ? 'unknown' : false ),
                        'results'   => $results,
                        'summary'   => [
                            'succeeded'     => $succeeded,
                            'failed'        => count( $results ) - $succeeded,
                            'indeterminate' => count(
                                array_filter(
                                    $results, static function ( $r ) {
                                        return 'unknown' === ( $r['committed'] ?? false );
                                    }
                                )
                            )
                        ]
                    ]
                );
            }
        ];
    }
}
