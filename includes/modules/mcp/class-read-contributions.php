<?php
namespace Directorist\MCP;

defined( 'ABSPATH' ) || exit;

/** Public read-adapter version 1; callbacks receive scope/actor, never arbitrary source paths. */
class Read_Contributions {
    /** Identity and site scope come from the host, never contributor inputs. */
    public static function validate_inputs( array $schema, $domain, $depth = 0 ) {
        foreach ( (array) ( $schema['properties'] ?? [] ) as $key => $child ) {
            Support::check( ! preg_match( '/^(?:user|author|customer|blog|site|network)(?:_id|_ids)?$/i', $key ) && ! in_array( $key, [ 'legacy_id', 'listing_ids', 'order_ids' ], true ), __( 'Identity and cross-site selectors are not supported by diagnostic tools.', 'directorist' ) );
            Support::check( ! in_array( $key, [ 'listing_id', 'order_id' ], true ) || ( 'dashboard' === $domain && 0 === $depth && 'integer' === $child['type'] ), __( 'Object selectors require a top-level dashboard object permission check.', 'directorist' ) );
            Support::check( 'scope' !== $key || ( 'dashboard' === $domain && 0 === $depth && 'string' === $child['type'] && ! empty( $child['enum'] ) && ! array_diff( $child['enum'], [ 'site', 'own' ] ) ), __( 'Diagnostic scope cannot be overridden.', 'directorist' ) );
            self::validate_inputs( $child, $domain, $depth + 1 );
        }
        if ( isset( $schema['items'] ) ) {
            self::validate_inputs( $schema['items'], $domain, $depth + 1 );
        }
    }

    public static function object_permission( array $args ) {
        $scope = Dashboard::permission( '', $args );
        if ( isset( $args['listing_id'] ) ) {
            Access::listing_domain();
            $post = Access::listing( $args['listing_id'] );
            Support::check( 'site' === $scope || (int) $post->post_author === get_current_user_id(), __( 'Listing not found.', 'directorist' ), 'not_found' );
            Support::check( true === apply_filters( 'directorist_rest_check_permissions', true, 'read', $post->ID, ATBDP_POST_TYPE ), __( 'Listing not found.', 'directorist' ), 'not_found' );
        }
        if ( isset( $args['order_id'] ) ) {
            $order = Orders::get( $args['order_id'] );
            Support::check( 'site' === $scope || (int) $order->user_id === get_current_user_id(), __( 'Order not found.', 'directorist' ), 'not_found' );
        }
    }

    public static function validate_schema( array $schema, $depth = 0 ) {
        Support::check( strlen( wp_json_encode( $schema ) ) <= 65536, __( 'Read contribution schemas exceed the metadata limit.', 'directorist' ) );
        Support::check( $depth <= 3, __( 'Read contributions permit at most three nested levels.', 'directorist' ) );
        Support::allowed( $schema, [ 'type', 'properties', 'required', 'additionalProperties', 'items', 'maxItems', 'minItems', 'minLength', 'maxLength', 'minimum', 'maximum', 'enum', 'pattern' ] );
        Support::check( ! isset( $schema['pattern'] ) || ( is_string( $schema['pattern'] ) && strlen( $schema['pattern'] ) <= 256 && null !== Read_Support::safe_value( $schema['pattern'] ) ), __( 'Unsafe or oversized diagnostic schema pattern.', 'directorist' ) );
        foreach ( $schema['enum'] ?? [] as $value ) {
            Support::check( null !== Read_Support::safe_value( $value ), __( 'Unsafe diagnostic schema value.', 'directorist' ) );
        }
        foreach ( (array) ( $schema['properties'] ?? [] ) as $key => $child ) {
            Support::check( preg_match( '/^[a-zA-Z][a-zA-Z0-9_.-]{0,99}$/', $key ) && ! preg_match( '/private|debug|token|path|socket|username|license|prefix/i', $key ), __( 'Private diagnostic properties are excluded.', 'directorist' ) );
            self::validate_schema( $child, $depth + 1 );
        }
        if ( isset( $schema['items'] ) ) {
            self::validate_schema( $schema['items'], $depth + 1 );
        }
        Support::check( 'string' !== $schema['type'] || ( $schema['maxLength'] ?? 2049 ) <= 2048, __( 'Diagnostic strings must be bounded to 2048 characters.', 'directorist' ) );
    }

    public static function descriptors( $domain, array $args, $kind = null ) {
        return array_filter(
            Registry::instance()->read_adapters( $domain, $args ), static function ( $d ) use ( $kind ) { return null === $kind || $kind === $d['kind'];
            }
        );
    }

    public static function metadata( array $d ) {
        $ready = false;
        try {
            $ready = true === Support::extension_callback( $d['availability'] );
        } catch ( \Throwable $error ) {
            $ready = false;
        }
        return [ 'id' => $d['id'], 'owner' => $d['owner'], 'label' => $d['label'], 'kind' => $d['kind'], 'units' => $d['units'], 'range_semantics' => $d['range_semantics'], 'filters' => $d['filters'], 'contract_version' => $d['contract_version'], 'source_version' => $d['source_version'], 'schema' => $d['output_schema'], 'state' => $ready ? 'available' : 'unavailable' ];
    }

    public static function read( array $d, array $args ) {
        try {
            $current = self::descriptors( $d['domain'], $args );
            Support::check( isset( $current[ $d['id'] ] ), __( 'This contribution is no longer permitted.', 'directorist' ), 'forbidden' );
            foreach ( [ 'directory_id', 'period', 'start_date', 'end_date', 'currency', 'compare_previous', 'series_kind' ] as $filter ) {
                Support::check( ! isset( $args[ $filter ] ) || in_array( $filter, $d['filters'], true ), __( 'This source does not support the selected filter.', 'directorist' ), 'unsupported_operation' );
            }
            Support::check( true === Support::extension_callback( $d['availability'] ), __( 'The optional source is unavailable.', 'directorist' ), 'extension_unavailable' );
            $value = Support::extension_callback( $d['read'], $args, get_current_user_id() );
            $value = Support::extension_callback( $d['project'], $value, $args, get_current_user_id() );
            Support::unwrap( rest_validate_value_from_schema( $value ?: (object) [], $d['output_schema'] ) );
            $value = self::safe_projection( $value, $d['output_schema'], $d );
            Support::check( null !== $value, __( 'Unsafe diagnostic output.', 'directorist' ) );
            Support::unwrap( rest_validate_value_from_schema( $value ?: (object) [], $d['output_schema'] ) );
            return Read_Support::section( $value, $d['id'] . ':' . $d['source_version'] );
        } catch ( \Throwable $error ) {
            return Read_Support::section( null, $d['id'], 'unavailable', 'source_unavailable_or_invalid' );
        }
    }

    private static function safe_projection( $value, array $schema, array $d, $key = '' ) {
        if ( 'object' === $schema['type'] ) {
            $out = [];
            foreach ( (array) $value as $field => $child ) {
                $out[ $field ] = self::safe_projection( $child, $schema['properties'][ $field ], $d, $field );
            }
            return $out;
        }
        if ( 'array' === $schema['type'] ) {
            return array_map(
                static function ( $child ) use ( $schema, $d ) { return self::safe_projection( $child, $schema['items'], $d );
                }, $value
            );
        }
        if ( 'dashboard' === $d['domain'] && 'guidance' === $d['kind'] && 'url' === $key && is_string( $value ) ) {
            $host     = wp_parse_url( $value, PHP_URL_HOST );
            $external = $host !== wp_parse_url( home_url(), PHP_URL_HOST );
            if ( $external && ! in_array( $host, $d['link_hosts'] ?? [], true ) ) {
                return null;
            }
            return Read_Support::safe_url( $value, $external );
        }
        return Read_Support::safe_value( $value );
    }
}
