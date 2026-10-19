<?php
namespace Directorist\MCP;

use Directorist\Fields\Fields;

defined( 'ABSPATH' ) || exit;

class Listings {
    public static function directory( $id ) {
        $ids = wp_get_object_terms( $id, ATBDP_DIRECTORY_TYPE, [ 'fields' => 'ids' ] );
        return (int) ( is_array( $ids ) && $ids ? $ids[0] : directorist_get_default_directory() );
    }

    private static function definitions( $directory ) {
        Support::directory( $directory );
        $definitions = [];
        $aliases     = [ 'title' => 'title', 'description' => 'description', 'excerpt' => 'excerpt', 'category' => 'categories', 'location' => 'locations', 'tag' => 'tags', 'image_upload' => 'images', 'social_info' => 'social' ];
        foreach ( directorist_get_listing_form_fields( $directory ) as $props ) {
            $field = Fields::create( $props );
            if ( $field->is_admin_only() || ! $field->get_key() ) {
                continue;
            }
            $widget    = $props['widget_name'] ?? '';
            $supported = Fields::exists( Fields::translate_key_to_field( $widget ) ) && 0 === strpos( get_class( $field ), 'Directorist\\Fields\\' );
            $key       = $aliases[ $widget ] ?? $field->get_key();
            $schema    = [ 'type' => 'string' ];
            if ( in_array( $widget, [ 'category', 'location', 'tag', 'image_upload' ], true ) ) {
                $schema = [ 'type' => 'array', 'items' => [ 'type' => 'integer', 'minimum' => 1 ], 'maxItems' => 100 ];
            } elseif ( in_array( $field->type, [ 'checkbox', 'social_info' ], true ) ) {
                $schema = [ 'type' => 'array' ];
            } elseif ( in_array( $widget, [ 'map', 'pricing' ], true ) ) {
                $schema = [ 'type' => 'object' ];
            } elseif ( 'number' === $field->type ) {
                $schema = [ 'type' => 'number' ];
            } elseif ( 'switch' === $field->type ) {
                $schema = [ 'type' => 'boolean' ];
            } elseif ( 'file' === $field->type ) {
                $schema = [ 'type' => 'integer', 'minimum' => 0, 'description' => 'Existing authorized attachment ID; 0 removes only the reference. Reads return the stored file URL.' ];
            } elseif ( 'email' === $field->type ) {
                $schema['format'] = 'email';
            } elseif ( 'url' === $field->type ) {
                $schema['format'] = 'uri';
            }
            if ( in_array( $field->type, [ 'select', 'radio' ], true ) ) {
                $options = $props['options'] ?? [];
                $choices = [];
                foreach ( $options as $option ) {
                    if ( is_array( $option ) && isset( $option['option_value'] ) ) {
                        $choices[] = $option['option_value'];
                    } elseif ( is_array( $option ) && isset( $option['value'] ) ) {
                        $choices[] = $option['value'];
                    }
                }
                if ( $choices ) {
                    $schema['enum'] = $choices;
                }
            }
            $definitions[ $key ] = [
                'key'               => $key,
                'native_key'        => $field->get_key(),
                'widget'            => $widget,
                'label'             => $props['label'] ?? $key,
                'required'          => $field->is_required(),
                'schema'            => $schema,
                'writable'          => $supported,
                'conditional_logic' => $props['conditional_logic'] ?? [],
                'props'             => $props
            ];
        }
        return $definitions;
    }

    public static function schema( array $args ) {
        $directory = Support::directory( $args['directory_id'] )->term_id;
        $type      = get_post_type_object( ATBDP_POST_TYPE );
        if ( isset( $args['listing_id'] ) ) {
            Access::listing( $args['listing_id'], 'edit' );
            Support::check( self::directory( $args['listing_id'] ) === $directory, __( 'The listing belongs to a different directory.', 'directorist' ) );
        } else {
            Support::check( current_user_can( $type->cap->create_posts ), __( 'You cannot create listings.', 'directorist' ), 'forbidden' );
        }
        $fields = self::definitions( $directory );
        foreach ( $fields as &$field ) {
            unset( $field['props'] );
        }
        return [
            'directory_id'      => $directory,
            'fields'            => array_values( $fields ),
            'schema_version'    => Support::hash( $fields ),
            'required_consents' => [ 'privacy_policy' => directorist_should_check_privacy_policy( $directory ), 'terms_conditions' => directorist_should_check_terms_and_condition( $directory ) ],
            'initial_statuses'  => self::initial_statuses( $directory ),
            'limits'            => [ 'bulk' => 50 ],
            'media'             => [ 'existing_attachments_only' => true ],
            'notes'             => __( 'Use assignment reference lookups for taxonomy IDs. Paid entitlements and ownership are read-only.', 'directorist' )
        ];
    }

    public static function version( $post ) {
        return Support::hash(
            [ $post->to_array(), get_post_meta( $post->ID ), wp_get_object_terms( $post->ID, [ ATBDP_DIRECTORY_TYPE, ATBDP_CATEGORY, ATBDP_LOCATION, ATBDP_TAGS ], [ 'fields' => 'ids' ] ),
                get_term_meta( self::directory( $post->ID ), 'submission_form_fields', true )
            ]
        );
    }

    private static function field_values( $post, array $definitions ) {
        $values = [];
        foreach ( $definitions as $key => $d ) {
            switch ( $d['widget'] ) {
                case 'title': $value = $post->post_title;
                break;
                case 'description': $value = $post->post_content;
                break;
                case 'excerpt': $value = $post->post_excerpt;
                break;
                case 'category': case 'location': case 'tag':
                            $map   = [ 'category' => ATBDP_CATEGORY, 'location' => ATBDP_LOCATION, 'tag' => ATBDP_TAGS ];
                            $value = array_map( 'intval', wp_get_object_terms( $post->ID, $map[ $d['widget'] ], [ 'fields' => 'ids' ] ) );
                break;
                case 'image_upload': $value = array_values( array_filter( array_map( 'intval', atbdp_get_listing_attachment_ids( $post->ID ) ) ) );
                break;
                case 'pricing': $value = [ 'price_type' => get_post_meta( $post->ID, '_atbd_listing_pricing', true ), 'price' => (float) get_post_meta( $post->ID, '_price', true ), 'price_range' => get_post_meta( $post->ID, '_price_range', true ) ];
                break;
                case 'map': $value = [ 'hide_map' => (bool) get_post_meta( $post->ID, '_hide_map', true ), 'manual_lat' => get_post_meta( $post->ID, '_manual_lat', true ), 'manual_lng' => get_post_meta( $post->ID, '_manual_lng', true ) ];
                break;
                case 'social_info': $value = get_post_meta( $post->ID, '_social', true );
                    if ( is_string( $value ) ) {
                        $value = json_decode( $value, true ) ?: [];
                    }
                break;
                default: $value = get_post_meta( $post->ID, '_' . $d['native_key'], true );
            }
            $values[ $key ] = $value;
        }
        return $values;
    }

    public static function data( $post, $scope = 'management', $summary = false ) {
        $data = [
            'id'            => (int) $post->ID,
            'title'         => $post->post_title,
            'status'        => $post->post_status,
            'directory_id'  => self::directory( $post->ID ),
            'url'           => get_permalink( $post ),
            'date_created'  => $post->post_date_gmt,
            'date_modified' => $post->post_modified_gmt
        ];
        if ( 'public' !== $scope ) {
            $data['author_id'] = (int) $post->post_author;
            if ( ! $summary ) {
                $data['fields']           = self::field_values( $post, self::definitions( $data['directory_id'] ) );
                $data['version']          = self::version( $post );
                $data['allowed_statuses'] = self::transitions( $post );
                $data['featured']         = (bool) get_post_meta( $post->ID, '_featured', true );
                $data['expiry_date']      = get_post_meta( $post->ID, '_expiry_date', true );
            }
        }
        return $data;
    }

    public static function read( $tool, array $args ) {
        if ( 'get-listing-schema' === $tool ) {
            return self::schema( $args );
        }
        $scope = $args['scope'] ?? 'management';
        if ( 'get-listing' === $tool ) {
            return self::data( Access::listing( $args['listing_id'], 'read', $scope ), $scope );
        }
        list( $page, $size ) = Support::paging( $args );
        $type                = get_post_type_object( ATBDP_POST_TYPE );
        $query               = [
            'post_type'      => ATBDP_POST_TYPE,
            'post_status'    => 'public' === $scope ? 'publish' : [ 'publish', 'pending', 'draft', 'private', 'trash', 'expired', 'rejected' ],
            's'              => $args['search'] ?? '',
            'posts_per_page' => $size,
            'paged'          => $page,
            'orderby'        => [ [ 'id' => 'ID', 'title' => 'title', 'date' => 'date', 'modified' => 'modified' ][ $args['orderby'] ?? 'id' ] => strtoupper( $args['order'] ?? 'asc' ), 'ID' => 'ASC' ]
        ];
        if ( 'management' === $scope ) {
            Support::check( current_user_can( $type->cap->edit_posts ), __( 'Listing management access is required.', 'directorist' ), 'forbidden' );
            if ( ! current_user_can( $type->cap->edit_others_posts ) ) {
                Support::check( ! isset( $args['author'] ) || $args['author'] === get_current_user_id(), __( 'Listing not found.', 'directorist' ), 'not_found' );
                $query['author'] = get_current_user_id();
            } elseif ( isset( $args['author'] ) ) {
                $query['author'] = Support::id( $args['author'] );
            }
            if ( current_user_can( $type->cap->edit_others_posts ) && ! current_user_can( $type->cap->read_private_posts ) ) {
                // A query-level condition keeps protected records out of totals as well as rows.
                $query['post_status'] = [ 'publish', 'pending', 'draft', 'trash', 'expired', 'rejected' ];
            }
        }
        if ( isset( $args['status'] ) ) {
            Support::check( in_array( $args['status'], (array) $query['post_status'], true ) || $args['status'] === $query['post_status'], __( 'This status is unavailable in this read scope.', 'directorist' ) );
            $query['post_status'] = $args['status'];
        }
        if ( isset( $args['ids'] ) ) {
            $query['post__in'] = Support::ids( $args['ids'] );
            if ( ! $query['post__in'] ) {
                return Support::collection( [], 0, $page, $size );
            }
        }
        $tax_query = [];
        foreach ( [ 'directory_id' => ATBDP_DIRECTORY_TYPE, 'categories' => ATBDP_CATEGORY, 'locations' => ATBDP_LOCATION, 'tags' => ATBDP_TAGS ] as $key => $taxonomy ) {
            if ( isset( $args[ $key ] ) ) {
                $tax_query[] = [ 'taxonomy' => $taxonomy, 'terms' => 'directory_id' === $key ? [ Support::directory( $args[ $key ] )->term_id ] : Support::ids( $args[ $key ] ), 'include_children' => false ];
            }
        }
        if ( $tax_query ) {
            $query['tax_query'] = $tax_query;
        }
        if ( isset( $args['featured'] ) ) {
            $query['meta_query'][] = [ 'key' => '_featured', 'value' => $args['featured'] ? '1' : '0' ];
        }
        foreach ( [ 'created' => 'post_date_gmt', 'modified' => 'post_modified_gmt' ] as $prefix => $column ) {
            $date = [ 'column' => $column, 'inclusive' => true ];
            foreach ( [ 'after', 'before' ] as $bound ) {
                if ( isset( $args[ $prefix . '_' . $bound ] ) ) {
                    Support::check( false !== strtotime( $args[ $prefix . '_' . $bound ] ), __( 'Invalid date filter.', 'directorist' ) );
                    $date[ $bound ] = $args[ $prefix . '_' . $bound ];
                }
            }
            if ( count( $date ) > 2 ) {
                $query['date_query'][] = $date;
            }
        }
        if ( 'public' === $scope ) {
            $query['has_password'] = false;
            Support::check( ! get_directorist_option( 'disable_single_listing', false ), __( 'Public listing access is disabled.', 'directorist' ), 'forbidden' );
        }
        $query   = apply_filters( 'directorist_mcp_listing_read_query', $query, $scope, get_current_user_id() );
        $results = new \WP_Query( $query );
        $items   = [];
        foreach ( $results->posts as $post ) {
            Access::listing( (int) $post->ID, 'read', $scope );
            $items[] = self::data( $post, $scope, true );
        }
        return Support::collection( $items, $results->found_posts, $page, $size );
    }

    private static function initial_statuses( $directory ) {
        $statuses = [ 'draft', 'pending' ];
        $type     = get_post_type_object( ATBDP_POST_TYPE );
        if ( 'publish' === directorist_get_listing_create_status( $directory ) && current_user_can( $type->cap->publish_posts ) ) {
            $statuses[] = 'publish';
        }
        return $statuses;
    }

    private static function policy( $post, $directory, $operation, array $data = [] ) {
        $allowed = true;
        if ( function_exists( 'is_fee_manager_active' ) && is_fee_manager_active() ) {
            $allowed = false;
        }
        if ( $post && 'publish' === $operation ) {
            $expiry = get_post_meta( $post->ID, '_expiry_date', true );
            if ( $expiry && ! get_post_meta( $post->ID, '_never_expire', true ) && strtotime( $expiry ) < time() ) {
                $allowed = false;
            }
            if ( get_post_meta( $post->ID, '_featured', true ) && function_exists( 'directorist_is_monetization_enabled' ) && directorist_is_monetization_enabled() ) {
                $allowed = $allowed && in_array( \Directorist\Helper::get_listing_payment_status( $post->ID ), [ 'completed', 'paid' ], true );
            }
        }
        $allowed = apply_filters( 'directorist_mcp_listing_policy', $allowed, $post, $directory, $operation, $data );
        Support::unwrap( $allowed );
        Support::check( true === $allowed, __( 'A listing or entitlement policy blocks this operation. Use the native workflow or a registered policy integration.', 'directorist' ), 'dependency_blocked' );
    }

    private static function transitions( $post ) {
        $type = get_post_type_object( ATBDP_POST_TYPE );
        if ( ! current_user_can( 'edit_post', $post->ID ) || ! current_user_can( $type->cap->publish_posts ) || ! in_array( $post->post_status, [ 'publish', 'private', 'pending', 'draft' ], true ) ) {
            return [];
        }
        $result = [];
        foreach ( [ 'publish', 'private' ] as $status ) {
            try {
                self::policy( $post, self::directory( $post->ID ), $status );
                $result[] = $status;
            } catch ( Failure $error ) {
                /* Not advertised. */
            }
        }
        return $result;
    }

    public static function prepare( $tool, array $args ) {
        $create    = 'create-listing' === $tool;
        $post      = $create ? null : Access::listing( $args['listing_id'], in_array( $tool, [ 'trash-listing', 'restore-listing' ], true ) ? 'delete' : 'edit' );
        $directory = $create ? Support::directory( $args['directory_id'] )->term_id : self::directory( $post->ID );
        $version   = $post ? self::version( $post ) : self::schema( [ 'directory_id' => $directory ] )['schema_version'];
        if ( $post && empty( $args['dry_run'] ) ) {
            Support::version( $args['expected_version'] ?? null, $version );
        }
        if ( isset( $args['schema_version'] ) ) {
            Support::version( $args['schema_version'], self::schema( [ 'directory_id' => $directory ] )['schema_version'] );
        }
        $id = $post ? (int) $post->ID : 0;
        if ( in_array( $tool, [ 'trash-listing', 'restore-listing', 'change-listing-status' ], true ) ) {
            return self::lifecycle( $tool, $args, $post, $version );
        }
        if ( $create ) {
            $type = get_post_type_object( ATBDP_POST_TYPE );
            Support::check( current_user_can( $type->cap->create_posts ) && \ATBDP_Add_Listing::current_user_can_create(), __( 'You cannot submit listings.', 'directorist' ), 'forbidden' );
        } else {
            Support::check( 'trash' !== $post->post_status, __( 'Restore this listing before editing it.', 'directorist' ) );
        }
        $definitions = self::definitions( $directory );
        $changes     = Support::object( $create ? $args['fields'] : $args['changes'], 'fields' );
        Support::allowed( $changes, array_keys( $definitions ) );
        $values = $post ? self::field_values( $post, $definitions ) : [];
        foreach ( $changes as $key => $value ) {
            $definition = $definitions[ $key ];
            Support::check( $definition['writable'], __( 'This field is read-only.', 'directorist' ), 'unsupported_operation' );
            if ( in_array( $key, [ 'categories', 'locations', 'tags' ], true ) && ( is_object( $value ) || ( is_array( $value ) && ( isset( $value['add'] ) || isset( $value['remove'] ) || isset( $value['replace'] ) ) ) ) ) {
                $ops = Support::object( $value );
                Support::allowed( $ops, [ 'add', 'remove', 'replace' ] );
                Support::check( ! isset( $ops['replace'] ) || 1 === count( $ops ), __( 'Do not combine replace with add/remove.', 'directorist' ) );
                $value = isset( $ops['replace'] ) ? Support::ids( $ops['replace'] ) : array_values( array_diff( array_unique( array_merge( $values[ $key ] ?? [], Support::ids( $ops['add'] ?? [] ) ) ), Support::ids( $ops['remove'] ?? [] ) ) );
            }
            $schema = $definition['schema'];
            if ( '' === $value && ! $definition['required'] ) {
                unset( $schema['format'] );
            }
            Support::unwrap( rest_validate_value_from_schema( $value, $schema, $key ) );
            if ( 'file' === ( $definition['props']['type'] ?? '' ) || 'file' === $definition['widget'] ) {
                $value = self::file_reference( $value, $definition['props'] );
            }
            $values[ $key ] = json_decode( wp_json_encode( $value ), true );
        }
        $data = [ 'directory_type' => $directory, 'directory_id' => $directory ];
        if ( $id ) {
            $data['listing_id'] = $id;
        }
        foreach ( $definitions as $key => $definition ) {
            $value = $values[ $key ] ?? null;
            if ( null === $value ) {
                continue;
            }
            $widget = $definition['widget'];
            if ( in_array( $widget, [ 'category', 'location', 'tag' ], true ) ) {
                $taxonomy = [ 'category' => ATBDP_CATEGORY, 'location' => ATBDP_LOCATION, 'tag' => ATBDP_TAGS ][ $widget ];
                if ( isset( $changes[ $key ] ) ) {
                    Access::term_cap( $taxonomy, 'assign' );
                }
                $ids = Support::ids( $value );
                foreach ( $ids as $term_id ) {
                    Taxonomies::term( $taxonomy, $term_id );
                    Support::check( in_array( $directory, Taxonomies::scope( $term_id ), true ), __( 'A selected term belongs to a different directory.', 'directorist' ) );
                }
                $data['tax_input'][ $taxonomy ] = $ids;
            } elseif ( 'image_upload' === $widget ) {
                if ( isset( $changes[ $key ] ) ) {
                    foreach ( Support::ids( $value ) as $attachment ) {
                        Access::attachment( $attachment, true );
                    }
                }
                $data[ $definition['native_key'] ] = $value;
            } elseif ( 'map' === $widget ) {
                $value = Support::object( $value );
                Support::allowed( $value, [ 'hide_map', 'manual_lat', 'manual_lng' ] );
                foreach ( [ 'manual_lat' => 90, 'manual_lng' => 180 ] as $coord => $limit ) {
                    if ( isset( $value[ $coord ] ) && '' !== $value[ $coord ] ) {
                        Support::check( is_numeric( $value[ $coord ] ) && abs( $value[ $coord ] ) <= $limit, __( 'Invalid map coordinate.', 'directorist' ) );
                    }
                }
                $data = array_merge( $data, $value );
            } elseif ( 'pricing' === $widget ) {
                $value = Support::object( $value );
                Support::allowed( $value, [ 'price_type', 'price', 'price_range' ] );
                $data['atbd_listing_pricing'] = $value['price_type'] ?? '';
                $data['price']                = $value['price'] ?? 0;
                $data['price_range']          = $value['price_range'] ?? '';
            } elseif ( 'social_info' === $widget ) {
                $data['social'] = $value;
            } else {
                $data[ $definition['native_key'] ] = $value;
            }
        }
        $consents = Support::object( $args['consents'] ?? [] );
        Support::allowed( $consents, [ 'privacy_policy', 'terms_conditions' ] );
        foreach ( [ 'privacy_policy' => 'privacy_policy', 'terms_conditions' => 't_c_check' ] as $key => $native ) {
            if ( isset( $consents[ $key ] ) ) {
                Support::check( is_bool( $consents[ $key ] ), __( 'Consent values must be explicit booleans.', 'directorist' ) );
                $data[ $native ] = $consents[ $key ];
            } elseif ( $post && get_post_meta( $post->ID, 'privacy_policy' === $key ? '_privacy_policy' : '_t_c_check', true ) ) {
                $data[ $native ] = true;
            }
        }
        self::policy( $post, $directory, $create ? 'create' : 'edit', $data );
        Submission_Adapter::validate_only( $data, $directory );
        $status = $create ? ( $args['status'] ?? directorist_get_listing_create_status( $directory ) ) : directorist_get_listing_edit_status( $directory, $id );
        if ( $create ) {
            if ( ! isset( $args['status'] ) && ! in_array( $status, self::initial_statuses( $directory ), true ) ) {
                $status = 'pending';
            }
            Support::check( in_array( $status, self::initial_statuses( $directory ), true ), __( 'This initial publication status is not allowed.', 'directorist' ), 'forbidden' );
        }
        return [
            'state'   => [ $version, $directory, $status ],
            'confirm' => $create ? 'publish' === $status : $status !== $post->post_status,
            'preview' => [ 'listing_id' => $id ?: null, 'directory_id' => $directory, 'fields' => $changes, 'effective_status' => $status, 'version' => $version ],
            'execute' => static function () use ( $data, $create, $status, $id, $values, $definitions ) {
                $status_filter = static function () use ( $status ) {
                    return $status;
                };
                if ( $create ) {
                    add_filter( 'directorist_listing_create_status', $status_filter, PHP_INT_MAX );
                }
                $old_images = $id ? atbdp_get_listing_attachment_ids( $id ) : [];
                // Native submission historically deletes deselected files; MCP changes references only.
                $protect = static function ( $delete, $attachment ) use ( $old_images ) {
                    return in_array( (int) $attachment->ID, array_map( 'intval', $old_images ), true ) ? false : $delete;
                };
                add_filter( 'pre_delete_attachment', $protect, PHP_INT_MAX, 2 );
                try {
                    $result = Support::unwrap( Submission_Adapter::apply( $data ) );
                } finally {
                    remove_filter( 'pre_delete_attachment', $protect, PHP_INT_MAX );
                    if ( $create ) {
                        remove_filter( 'directorist_listing_create_status', $status_filter, PHP_INT_MAX );
                    }
                }
                $new_id = (int) ( $result['id'] ?? 0 );
                Support::check( $new_id && ATBDP_POST_TYPE === get_post_type( $new_id ), __( 'The listing save could not be verified.', 'directorist' ), 'operation_failed' );
                if ( isset( $definitions['images'], $values['images'] ) ) {
                    $images = $values['images'];
                    if ( $images ) {
                        set_post_thumbnail( $new_id, $images[0] );
                        update_post_meta( $new_id, '_listing_prv_img', $images[0] );
                        update_post_meta( $new_id, '_listing_img', array_slice( $images, 1 ) );
                    } else {
                        delete_post_thumbnail( $new_id );
                        delete_post_meta( $new_id, '_listing_prv_img' );
                        delete_post_meta( $new_id, '_listing_img' );
                    }
                }
                return Support::result( [ 'listing_id' => $new_id, 'status' => get_post_status( $new_id ), 'version' => self::version( get_post( $new_id ) ) ] );
            }
        ];
    }

    /** Existing files remain in the media library; never invoke the temporary-upload mover. */
    private static function file_reference( $id, array $props ) {
        if ( 0 === $id ) {
            return '';
        }
        Access::attachment( $id );
        $field = Fields::create( $props );
        $path  = get_attached_file( $id );
        Support::check( $path && is_file( $path ) && is_readable( $path ), __( 'The attachment file is unavailable.', 'directorist' ) );
        $types  = $field->get_file_types();
        $groups = directorist_get_supported_file_types_groups();
        $types  = in_array( $types, [ '', 'all', 'all_types' ], true ) ? directorist_get_supported_file_types() : ( $groups[ $types ] ?? (array) $types );
        $check  = wp_check_filetype_and_ext( $path, wp_basename( $path ), get_allowed_mime_types() );
        Support::check( ! empty( $check['ext'] ) && in_array( $check['ext'], $types, true ), __( 'The attachment type is not allowed for this field.', 'directorist' ) );
        Support::check( ! $field->get_file_size() || filesize( $path ) <= $field->get_file_size(), __( 'The attachment exceeds this field size limit.', 'directorist' ) );
        $url = wp_get_attachment_url( $id );
        Support::check( (bool) $url, __( 'The attachment URL is unavailable.', 'directorist' ) );
        return esc_url_raw( $url );
    }

    private static function lifecycle( $tool, array $args, $post, $version ) {
        $id = (int) $post->ID;
        if ( 'change-listing-status' === $tool ) {
            $status = $args['status'];
            Support::check( in_array( $status, self::transitions( $post ), true ), __( 'This status transition is not permitted.', 'directorist' ), 'forbidden' );
        } elseif ( 'trash-listing' === $tool ) {
            Support::check( defined( 'EMPTY_TRASH_DAYS' ) && EMPTY_TRASH_DAYS > 0, __( 'Trash is disabled; permanent deletion is not supported.', 'directorist' ), 'unsupported_operation' );
            Support::check( 'trash' !== $post->post_status, __( 'This listing is already in Trash.', 'directorist' ) );
            $status = 'trash';
        } else {
            Access::listing( $id, 'edit' );
            Support::check( 'trash' === $post->post_status, __( 'Only trashed listings can be restored.', 'directorist' ) );
            $status = 'draft';
        }
        if ( 'trash' !== $status ) {
            self::policy( $post, self::directory( $id ), $status );
        }
        return [
            'state'   => $version,
            'confirm' => true,
            'preview' => [ 'listing_id' => $id, 'before' => $post->post_status, 'after' => $status, 'version' => $version ],
            'execute' => static function () use ( $tool, $id, $status ) {
                if ( 'trash-listing' === $tool ) {
                    $result = wp_trash_post( $id );
                } elseif ( 'restore-listing' === $tool ) {
                    $result = wp_untrash_post( $id );
                } else {
                    do_action( 'directorist_before_update_listing_status', $id, $status );
                    $result = wp_update_post( [ 'ID' => $id, 'post_status' => $status ], true );
                }
                Support::unwrap( $result );
                Support::check( (bool) $result, __( 'The listing lifecycle update failed.', 'directorist' ), 'operation_failed' );
                return Support::result( [ 'listing_id' => $id, 'status' => get_post_status( $id ), 'version' => self::version( get_post( $id ) ) ] );
            }
        ];
    }
}
