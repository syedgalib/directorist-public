<?php
namespace Directorist\MCP;

defined( 'ABSPATH' ) || exit;

class Taxonomies {
    public static function scope( $id ) {
        if ( ! directorist_is_multi_directory_enabled() ) {
            return [ (int) directorist_get_default_directory() ];
        }
        $value = get_term_meta( $id, '_directory_type', true );
        // Follow Directorist's legacy effective scope for terms without associations.
        return is_array( $value ) && $value ? array_values( array_map( 'intval', $value ) ) : [ (int) directorist_get_default_directory() ];
    }

    public static function term( $taxonomy, $id ) {
        $term = get_term( Support::id( $id ), Support::taxonomy( $taxonomy ) );
        Support::check( $term && ! is_wp_error( $term ), __( 'Term not found.', 'directorist' ), 'not_found' );
        return $term;
    }

    public static function version( $term ) {
        return Support::hash( [ $term->to_array(), get_term_meta( $term->term_id ), self::dependencies( $term ) ] );
    }

    public static function dependencies( $term ) {
        global $wpdb;
        return [
            'relationships' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d", $term->term_taxonomy_id ) ),
            'children'      => get_terms( [ 'taxonomy' => $term->taxonomy, 'parent' => $term->term_id, 'hide_empty' => false, 'fields' => 'ids' ] ),
            'protected'     => (bool) apply_filters( 'directorist_mcp_term_has_dependencies', false, $term )
        ];
    }

    public static function data( $term, $assignment = false ) {
        $data = [ 'id' => (int) $term->term_id, 'name' => $term->name, 'slug' => $term->slug, 'parent' => (int) $term->parent ];
        if ( ! $assignment ) {
            $data += [
                'taxonomy'    => $term->taxonomy,
                'description' => $term->description,
                'count'       => (int) $term->count,
                'directories' => self::scope( $term->term_id ),
                'version'     => self::version( $term )
            ];
            if ( ATBDP_CATEGORY === $term->taxonomy ) {
                $data['icon'] = get_term_meta( $term->term_id, 'category_icon', true );
            }
            if ( ATBDP_TAGS !== $term->taxonomy ) {
                $data['image'] = (int) get_term_meta( $term->term_id, 'image', true );
            }
        }
        return $data;
    }

    public static function schemas() {
        $items = [];
        foreach ( [ ATBDP_CATEGORY, ATBDP_LOCATION, ATBDP_TAGS ] as $name ) {
            $tax     = get_taxonomy( $name );
            $items[] = [
                'taxonomy'     => $name,
                'label'        => $tax->label,
                'hierarchical' => (bool) $tax->hierarchical,
                'properties'   => ATBDP_TAGS === $name ? [ 'name', 'slug', 'description', 'directories' ] : [ 'name', 'slug', 'description', 'parent', 'directories', 'image' ],
                'permissions'  => [ 'manage' => current_user_can( $tax->cap->manage_terms ), 'edit' => current_user_can( $tax->cap->edit_terms ), 'delete' => current_user_can( $tax->cap->delete_terms ), 'assign' => current_user_can( $tax->cap->assign_terms ) ]
            ];
        }
        return [ 'items' => $items ];
    }

    public static function read( $tool, array $args, $assignment = false ) {
        if ( 'list-taxonomies' === $tool ) {
            return self::schemas();
        }
        $taxonomy = Support::taxonomy( $args['taxonomy'] );
        Access::term_cap( $taxonomy, $assignment ? 'assign' : 'read' );
        if ( 'get-term' === $tool ) {
            return self::data( self::term( $taxonomy, $args['term_id'] ) );
        }
        list( $page, $size ) = Support::paging( $args );
        $query               = [ 'taxonomy' => $taxonomy, 'hide_empty' => false, 'orderby' => 'term_id', 'order' => 'ASC', 'search' => $args['search'] ?? '' ];
        if ( isset( $args['ids'] ) ) {
            $query['include'] = Support::ids( $args['ids'] );
            if ( ! $query['include'] ) {
                return Support::collection( [], 0, $page, $size );
            }
        }
        if ( isset( $args['parent'] ) ) {
            $query['parent'] = $args['parent'];
        }
        if ( $assignment || isset( $args['directory_id'] ) ) {
            $directory = $args['directory_id'] ?? directorist_get_default_directory();
            Support::directory( $directory );
            if ( directorist_is_multi_directory_enabled() ) {
                $query['meta_query'] = [ 'relation' => 'OR', [ 'key' => '_directory_type', 'value' => 'i:' . $directory . ';', 'compare' => 'LIKE' ], [ 'key' => '_directory_type', 'value' => '"' . $directory . '"', 'compare' => 'LIKE' ] ];
                if ( (int) $directory === (int) directorist_get_default_directory() ) {
                    $query['meta_query'][] = [ 'key' => '_directory_type', 'compare' => 'NOT EXISTS' ];
                }
            }
        }
        $total = Support::unwrap( wp_count_terms( $query ) );
        $terms = Support::unwrap( get_terms( $query + [ 'number' => $size, 'offset' => ( $page - 1 ) * $size ] ) );
        return Support::collection(
            array_map(
                static function ( $term ) use ( $assignment ) {
                    return self::data( $term, $assignment );
                }, $terms
            ), $total, $page, $size
        );
    }

    public static function prepare( $tool, array $args ) {
        $taxonomy = Support::taxonomy( $args['taxonomy'] );
        $create   = 'create-term' === $tool;
        $delete   = 'delete-term' === $tool;
        Access::term_cap( $taxonomy, $delete ? 'delete' : 'edit' );
        $term    = $create ? null : self::term( $taxonomy, $args['term_id'] );
        $version = $term ? self::version( $term ) : Support::hash( [ $taxonomy, directorist_get_default_directory() ] );
        if ( ! $create && empty( $args['dry_run'] ) ) {
            Support::version( $args['expected_version'] ?? null, $version );
        }
        if ( $delete ) {
            $deps = self::dependencies( $term );
            Support::check( ! $deps['relationships'] && ! $deps['children'] && ! $deps['protected'], __( 'This term still has listing, child or protected dependencies. Resolve them before deleting it.', 'directorist' ), 'dependency_blocked' );
            return [
                'state'   => $version,
                'confirm' => true,
                'preview' => [ 'term_id' => $term->term_id, 'delete' => true, 'version' => $version ],
                'execute' => static function () use ( $term ) {
                    Support::unwrap( wp_delete_term( $term->term_id, $term->taxonomy ) );
                    Support::check( ! term_exists( $term->term_id, $term->taxonomy ), __( 'The deletion could not be verified.', 'directorist' ), 'operation_failed' );
                    return Support::result( [ 'term_id' => $term->term_id, 'deleted' => true ] );
                }
            ];
        }
        if ( $create ) {
            $changes = $args;
            foreach ( [ 'taxonomy', 'dry_run', 'confirm_token', 'idempotency_key', 'expected_version' ] as $key ) {
                unset( $changes[ $key ] );
            }
        } else {
            $changes = Support::object( $args['changes'], 'changes' );
        }
        $allowed = [ 'name', 'slug', 'description', 'directories' ];
        if ( ATBDP_TAGS !== $taxonomy ) {
            $allowed = array_merge( $allowed, [ 'parent', 'image' ] );
        }
        if ( ATBDP_CATEGORY === $taxonomy ) {
            $allowed[] = 'icon';
        }
        Support::allowed( $changes, $allowed );
        Support::check( (bool) $changes, __( 'No term changes were supplied.', 'directorist' ) );
        foreach ( [ 'name', 'slug', 'description', 'icon' ] as $key ) {
            if ( isset( $changes[ $key ] ) ) {
                $changes[ $key ] = Support::clean_text( $changes[ $key ], 'description' === $key );
            }
        }
        if ( $create || isset( $changes['name'] ) ) {
            Support::check( '' !== trim( $changes['name'] ?? '' ), __( 'A term name is required.', 'directorist' ) );
        }
        if ( isset( $changes['slug'] ) ) {
            $changes['slug'] = sanitize_title( $changes['slug'] );
            $existing        = get_term_by( 'slug', $changes['slug'], $taxonomy );
            Support::check( $changes['slug'] && ( ! $existing || ( $term && $existing->term_id === $term->term_id ) ), __( 'That term slug is already in use or invalid.', 'directorist' ) );
        }
        if ( isset( $changes['image'] ) && 0 !== $changes['image'] ) {
            Access::attachment( $changes['image'], true );
        }
        $directories = $term ? self::scope( $term->term_id ) : [ (int) directorist_get_default_directory() ];
        if ( isset( $changes['directories'] ) ) {
            Support::check( directorist_is_multi_directory_enabled() || $create, __( 'Directory associations cannot be edited in single-directory mode.', 'directorist' ), 'unsupported_operation' );
            if ( is_object( $changes['directories'] ) || ( is_array( $changes['directories'] ) && ( isset( $changes['directories']['add'] ) || isset( $changes['directories']['remove'] ) || isset( $changes['directories']['replace'] ) ) ) ) {
                $operation = Support::object( $changes['directories'] );
                Support::allowed( $operation, [ 'add', 'remove', 'replace' ] );
                Support::check( ! isset( $operation['replace'] ) || 1 === count( $operation ), __( 'Do not mix replace with add/remove.', 'directorist' ) );
                $directories = isset( $operation['replace'] ) ? Support::ids( $operation['replace'] ) : array_values( array_diff( array_unique( array_merge( $directories, Support::ids( $operation['add'] ?? [] ) ) ), Support::ids( $operation['remove'] ?? [] ) ) );
            } else {
                $directories = Support::ids( $changes['directories'] );
            }
            Support::check( (bool) $directories, __( 'A term must retain an eligible directory.', 'directorist' ) );
            foreach ( $directories as $directory ) {
                Support::directory( $directory );
            }
            $changes['directories'] = $directories;
            if ( $term && array_diff( self::scope( $term->term_id ), $directories ) ) {
                $object_ids = get_objects_in_term( $term->term_id, $taxonomy );
                foreach ( Support::unwrap( $object_ids ) as $object_id ) {
                    $assigned = wp_get_object_terms( $object_id, ATBDP_DIRECTORY_TYPE, [ 'fields' => 'ids' ] );
                    Support::check( ! array_diff( Support::unwrap( $assigned ), $directories ), __( 'Listings still use this term in a directory being removed.', 'directorist' ), 'dependency_blocked' );
                }
            }
        }
        $parent = $changes['parent'] ?? ( $term ? (int) $term->parent : 0 );
        Support::check( is_int( $parent ) && $parent >= 0, __( 'Parent must be a nonnegative integer.', 'directorist' ) );
        if ( $create ) {
            Support::check( ! term_exists( $changes['name'], $taxonomy, $parent ), __( 'This term name already exists in that scope.', 'directorist' ) );
        }
        if ( $parent ) {
            self::term( $taxonomy, $parent );
            Support::check( ! $term || ( $parent !== $term->term_id && ! in_array( $term->term_id, get_ancestors( $parent, $taxonomy, 'taxonomy' ), true ) ), __( 'This parent would create a hierarchy cycle.', 'directorist' ) );
            Support::check( ! array_diff( $directories, self::scope( $parent ) ), __( 'The parent must support every child directory.', 'directorist' ) );
        }
        if ( $term ) {
            foreach ( Support::unwrap( get_terms( [ 'taxonomy' => $taxonomy, 'parent' => $term->term_id, 'hide_empty' => false, 'fields' => 'ids' ] ) ) as $child ) {
                Support::check( ! array_diff( self::scope( $child ), $directories ), __( 'This change would invalidate a child directory association.', 'directorist' ) );
            }
        }
        $confirm = ! $create && ( isset( $changes['slug'] ) || isset( $changes['parent'] ) || isset( $changes['directories'] ) );
        return [
            'state'   => [ $version, $directories, $parent ],
            'confirm' => $confirm,
            'preview' => [ 'term_id' => $term ? $term->term_id : null, 'changes' => $changes, 'version' => $version ],
            'execute' => static function () use ( $create, $term, $taxonomy, $changes ) {
                $data   = array_intersect_key( $changes, array_flip( [ 'name', 'slug', 'description', 'parent' ] ) );
                $result = $create ? wp_insert_term( $data['name'], $taxonomy, $data ) : wp_update_term( $term->term_id, $taxonomy, $data );
                $result = Support::unwrap( $result );
                $id     = (int) $result['term_id'];
                if ( isset( $changes['directories'] ) || $create ) {
                    directorist_update_term_directory( $id, $changes['directories'] ?? [ (int) directorist_get_default_directory() ] );
                }
                if ( isset( $changes['image'] ) ) {
                    update_term_meta( $id, 'image', $changes['image'] );
                }
                if ( isset( $changes['icon'] ) ) {
                    update_term_meta( $id, 'category_icon', $changes['icon'] );
                }
                clean_term_cache( $id, $taxonomy );
                return Support::result( [ 'term_id' => $id, 'data' => self::data( get_term( $id, $taxonomy ) ) ] );
            }
        ];
    }
}
