<?php
namespace Directorist\MCP;

defined( 'ABSPATH' ) || exit;

class Support {
    public static function fail( $code, $message, array $details = [] ) {
        throw new Failure( $code, $message, $details );
    }

    public static function check( $condition, $message, $code = 'validation_failed' ) {
        if ( ! $condition ) {
            self::fail( $code, $message );
        }
    }

    public static function unwrap( $result ) {
        if ( is_wp_error( $result ) ) {
            self::fail( 'validation_failed', $result->get_error_message() );
        }
        return $result;
    }

    public static function extension_callback( $callback, ...$args ) {
        try {
            $result = call_user_func_array( $callback, $args );
            Support::check( ! is_wp_error( $result ), __( 'The extension rejected this operation. Check its supported fields and native workflow.', 'directorist' ), 'extension_failed' );
            return $result;
        } catch ( Failure $error ) {
            throw $error;
        } catch ( \Throwable $error ) {
            Support::fail( 'extension_failed', __( 'The extension could not complete this operation. Rediscover supported tools or contact the site administrator.', 'directorist' ) );
        }
    }

    public static function hash( $value ) {
        return hash( 'sha256', wp_json_encode( self::canonical( $value ) ) );
    }

    public static function canonical( $value ) {
        if ( $value instanceof \Closure ) {
            return 'registered-callback';
        }
        if ( is_object( $value ) ) {
            $value = (array) $value;
        }
        if ( is_array( $value ) ) {
            if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
                ksort( $value );
            }
            foreach ( $value as &$item ) {
                $item = self::canonical( $item );
            }
        }
        return $value;
    }

    public static function object( $value, $label = 'object' ) {
        if ( is_object( $value ) ) {
            $value = (array) $value;
        }
        self::check( is_array( $value ) && ( ! $value || ! isset( $value[0] ) ), sprintf( __( 'Expected %s to be an object.', 'directorist' ), $label ) );
        return $value;
    }

    public static function allowed( array $value, array $keys ) {
        $unknown = array_diff( array_keys( $value ), $keys );
        self::check( ! $unknown, __( 'Unknown or unsupported parameter.', 'directorist' ) . ' ' . implode( ', ', $unknown ) );
    }

    public static function id( $value ) {
        self::check( is_int( $value ) && $value > 0, __( 'A positive integer ID is required.', 'directorist' ) );
        return $value;
    }

    public static function ids( $value ) {
        self::check( is_array( $value ) && count( $value ) <= 100, __( 'Expected at most 100 explicit IDs.', 'directorist' ) );
        return array_values( array_unique( array_map( [ self::class, 'id' ], $value ) ) );
    }

    public static function paging( array $args ) {
        $page = $args['page'] ?? 1;
        $size = $args['per_page'] ?? 20;
        self::check( is_int( $page ) && $page > 0 && is_int( $size ) && $size >= 1 && $size <= 100, __( 'Use a positive page and a page size between 1 and 100.', 'directorist' ) );
        return [ $page, $size ];
    }

    public static function page( array $items, array $args ) {
        list( $page, $size ) = self::paging( $args );
        return self::collection( array_slice( array_values( $items ), ( $page - 1 ) * $size, $size ), count( $items ), $page, $size );
    }

    public static function collection( array $items, $total, $page, $size ) {
        return [
            'items'       => array_values( $items ),
            'page'        => $page,
            'per_page'    => $size,
            'total'       => (int) $total,
            'total_pages' => (int) ceil( $total / $size ),
            'has_more'    => $page * $size < $total
        ];
    }

    public static function version( $expected, $current ) {
        self::check( is_string( $expected ) && hash_equals( $current, $expected ), __( 'The record changed. Read it again and obtain a new preview.', 'directorist' ), 'state_changed' );
    }

    public static function taxonomy( $name ) {
        self::check( in_array( $name, [ ATBDP_CATEGORY, ATBDP_LOCATION, ATBDP_TAGS ], true ), __( 'Unsupported Directorist taxonomy.', 'directorist' ) );
        return $name;
    }

    public static function directory( $id ) {
        $term = get_term( self::id( $id ), ATBDP_DIRECTORY_TYPE );
        self::check( $term && ! is_wp_error( $term ), __( 'Directory not found.', 'directorist' ), 'not_found' );
        return $term;
    }

    public static function clean_text( $value, $html = false ) {
        self::check( is_string( $value ), __( 'A text value is required.', 'directorist' ) );
        return $html ? wp_kses_post( $value ) : sanitize_text_field( $value );
    }

    public static function result( array $data = [] ) {
        return array_merge( [ 'success' => true, 'committed' => true, 'idempotent_replay' => false ], $data );
    }

    public static function event( $tool, $outcome, array $args = [], $code = '' ) {
        $ids = [];
        foreach ( [ 'listing_id', 'directory_id', 'term_id', 'order_id', 'payment_id' ] as $key ) {
            if ( isset( $args[ $key ] ) && is_int( $args[ $key ] ) ) {
                $ids[ $key ] = $args[ $key ];
            }
        }
        do_action(
            'directorist_mcp_operation_event', [
                'timestamp'  => gmdate( 'c' ),
                'site_id'    => get_current_blog_id(),
                'user_id'    => get_current_user_id(),
                'tool'       => $tool,
                'targets'    => $ids,
                'outcome'    => $outcome,
                'error_code' => $code,
                'request_id' => Module::request_id()
            ]
        );
    }
}
