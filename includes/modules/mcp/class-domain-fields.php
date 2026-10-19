<?php
namespace Directorist\MCP;

defined( 'ABSPATH' ) || exit;

/** Explicit extension_fields projection; adapters never receive arbitrary client-selected meta paths. */
class Domain_Fields {
    private static function context( $domain, array $args ) {
        if ( 'listings' === $domain && isset( $args['listing_id'] ) ) {
            return Access::listing( $args['listing_id'] );
        }
        if ( 'orders' === $domain && ( isset( $args['order_id'] ) || isset( $args['legacy_id'] ) ) ) {
            return Orders::get( $args['order_id'] ?? null, $args['legacy_id'] ?? null );
        }
        if ( 'taxonomies' === $domain && isset( $args['term_id'] ) ) {
            Access::term_cap( $args['taxonomy'], 'read' );
            return get_term( $args['term_id'], $args['taxonomy'] );
        }
        return $args;
    }

    public static function read( $tool, array $args, $domain, array $result ) {
        if ( ! in_array( $tool, [ 'get-listing', 'get-listing-schema', 'get-term', 'list-taxonomies', 'get-order', 'get-order-schema' ], true ) || 'public' === ( $args['scope'] ?? '' ) ) {
            return $result;
        }
        $context = self::context( $domain, $args );
        $schema  = [];
        $values  = [];
        foreach ( Registry::instance()->fields( $domain, $context ) as $key => $d ) {
            $schema[ $key ] = [ 'owner' => $d['owner'], 'key' => $key, 'schema' => $d['schema'], 'default' => $d['default'], 'dependencies' => $d['dependencies'] ?? [], 'contract_version' => $d['contract_version'], 'readable' => true, 'writable' => ! empty( $d['writable'] ) && true === Support::extension_callback( $d['permission'], $context, 'write' ) ];
            if ( in_array( $tool, [ 'get-listing', 'get-term', 'get-order' ], true ) ) {
                $value = Support::unwrap( Support::extension_callback( $d['read'], $context ) );
                Support::unwrap( rest_validate_value_from_schema( $value, $d['schema'] ) );
                $values[ $key ] = $value;
            }
        }
        if ( $schema ) {
            $result['extension_schema'] = $schema;
        }
        if ( $values ) {
            $result['extension_fields'] = $values;
        }
        return $result;
    }

    public static function prepare( $tool, array $args, $domain, callable $native ) {
        $changes = Support::object( $args['extension_fields'] ?? [] );
        unset( $args['extension_fields'] );
        $plan = $native( $args );
        if ( ! $changes ) {
            return $plan;
        }
        Support::check( in_array( $tool, [ 'create-listing', 'update-listing', 'create-term', 'update-term', 'create-order', 'update-order' ], true ) && empty( $plan['orchestrated'] ), __( 'Extension fields cannot be combined with lifecycle or financial actions.', 'directorist' ), 'unsupported_operation' );
        $context = self::context( $domain, $args );
        $fields  = Registry::instance()->fields( $domain, $context );
        Support::allowed( $changes, array_keys( $fields ) );
        $values   = [];
        $before   = [];
        $tables   = $plan['tables'] ?? [];
        $adapters = [];
        foreach ( $changes as $key => $value ) {
            $d = $fields[ $key ];
            Support::check( ! empty( $d['writable'] ) && true === Support::extension_callback( $d['permission'], $context, 'write' ) && empty( $d['external_effects'] ), __( 'This extension field is not writable.', 'directorist' ), 'forbidden' );
            Support::unwrap( rest_validate_value_from_schema( $value, $d['schema'] ) );
            $values[ $key ] = Support::unwrap( Support::extension_callback( $d['validate'], $value, $context ) );
            Support::unwrap( rest_validate_value_from_schema( $values[ $key ], $d['schema'] ) );
            $before[ $key ] = Support::unwrap( Support::extension_callback( $d['read'], $context ) );
            // Only native WordPress metadata/option participants can join a core logical unit.
            Support::check( 'directorist' === $d['storage_adapter'], __( 'Independent extension stores require a separate registered tool.', 'directorist' ), 'unsupported_operation' );
            $tables     = array_merge( $tables, $d['tables'] );
            $adapters[] = $d;
        }
        Write_Guard::check_tables( $tables );
        $execute                             = $plan['execute'];
        $plan['state']                       = [ $plan['state'], $before, Registry::instance()->fingerprint() ];
        $plan['preview']['extension_fields'] = [ 'before' => $before, 'after' => $values ];
        $plan['confirm']                     = true;
        $plan['tables']                      = array_values( array_unique( $tables ) );
        $plan['invalidate']                  = static function () use ( $adapters ) {
            foreach ( $adapters as $d ) {
                Support::extension_callback( $d['invalidate'] );
            }
        };
        $plan['execute']                     = static function () use ( $execute, $values, $fields, $args, $domain ) {
            $result  = $execute();
            $current = $args;
            foreach ( [ 'listing_id', 'term_id', 'order_id' ] as $id ) {
                if ( isset( $result[ $id ] ) ) {
                    $current[ $id ] = $result[ $id ];
                }
            }
            $context = self::context( $domain, $current );
            foreach ( $values as $key => $value ) {
                $d = $fields[ $key ];
                Support::check( Registry::instance()->admitted( $d['owner'] ) && true === Support::extension_callback( $d['permission'], $context, 'write' ), __( 'Extension field access changed.', 'directorist' ), 'forbidden' );
                Support::unwrap( Support::extension_callback( $d['write'], $context, $value ) );
            }
            return $result;
        };
        return $plan;
    }

    public static function settings() {
        $out = [];
        foreach ( Registry::instance()->fields( 'settings' ) as $key => $d ) {
            $d['writable']   = ! empty( $d['writable'] ) && true === Support::extension_callback( $d['permission'], null, 'write' );
            $d['resettable'] = $d['writable'] && ! empty( $d['resettable'] );
            $out[ $key ]     = $d + [ 'write' => static function () { return new \WP_Error( 'read_only', 'Read-only field.' );
            },
                'storage_adapter'             => 'directorist'
            ] + [ 'capability' => 'manage_options', 'resettable' => false ];
        }
        return $out;
    }
}
