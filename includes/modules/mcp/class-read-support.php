<?php
namespace Directorist\MCP;

defined( 'ABSPATH' ) || exit;

/** Shared bounded read contracts. No persisted cache or diagnostic payloads. */
class Read_Support {
    public static function string_schema( $max = 128 ) {
        return [ 'type' => 'string', 'maxLength' => $max, 'minLength' => 1 ];
    }

    public static function enum_schema( array $values ) {
        return [ 'type' => 'string', 'enum' => $values ];
    }

    public static function list_schema( $max, $item = null ) {
        return [ 'type' => 'array', 'minItems' => 1, 'maxItems' => $max, 'uniqueItems' => true, 'items' => $item ?: self::string_schema() ];
    }

    public static function paging_schema() {
        return [ 'page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100000 ], 'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ] ];
    }

    public static function definition( $group, $description, array $properties, array $required = [] ) {
        return [ 'group' => $group, 'write' => false, 'description' => $description, 'schema' => [ 'type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false ] ];
    }

    public static function envelope( $scope ) {
        return [ 'schema_version' => '1.0', 'scope' => $scope, 'timezone' => wp_timezone_string(), 'generated_at' => gmdate( 'c' ), 'request_id' => Module::request_id() ];
    }

    public static function section( $data, $source, $state = null, $reason = null ) {
        return [ 'state' => $state ?: ( null === $data ? 'unavailable' : 'available' ), 'source' => $source, 'as_of' => gmdate( 'c' ), 'cached' => false, 'age_seconds' => 0, 'data' => $data, 'reason' => $reason ];
    }

    public static function selected( array $args, $key, array $defaults, array $allowed, $maximum ) {
        $selected = $args[ $key ] ?? $defaults;
        Support::check( is_array( $selected ) && count( $selected ) > 0 && count( $selected ) <= $maximum && count( array_unique( $selected ) ) === count( $selected ), __( 'Select a bounded set of distinct supported keys.', 'directorist' ) );
        Support::check( ! array_diff( $selected, $allowed ), __( 'A requested section or field is unavailable or forbidden.', 'directorist' ), 'forbidden' );
        return $selected;
    }

    public static function query( $sql ) {
        global $wpdb;
        $previous = $wpdb->suppress_errors( true );
        try {
            $result = $wpdb->get_results( $sql, ARRAY_A );
            $valid  = ! $wpdb->last_error && is_array( $result );
        } finally {
            $wpdb->suppress_errors( $previous );
        }
        Support::check( $valid, __( 'The requested data source is unavailable.', 'directorist' ), 'dependency_unavailable' );
        return $result;
    }

    public static function table_exists( $table ) {
        global $wpdb;
        return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
    }

    public static function tool_reference( $tool ) {
        if ( ! $tool ) {
            return null;
        }
        $name        = substr( $tool, strlen( 'directorist-' ) );
        $definitions = Catalog::definitions();
        return isset( $definitions[ $name ] ) && Tools::available( $name, $definitions[ $name ] ) ? $tool : null;
    }

    public static function safe_url( $url, $external = false ) {
        if ( ! is_string( $url ) || strlen( $url ) > 2048 ) {
            return null;
        }
        $parts = wp_parse_url( $url );
        $query = rawurldecode( rawurldecode( ( $parts['query'] ?? '' ) . '&' . ( $parts['fragment'] ?? '' ) ) );
        if ( ! $parts || ! in_array( $parts['scheme'] ?? '', [ 'http', 'https' ], true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || preg_match( '/nonce|token|password|secret|signature|auth|license|download|package/i', $query ) ) {
            return null;
        }
        if ( $external ? 'https' !== $parts['scheme'] : strtolower( $parts['host'] ?? '' ) !== strtolower( wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
            return null;
        }
        if ( ! $external ) {
            $home      = wp_parse_url( home_url() );
            $port      = $parts['port'] ?? ( 'https' === $parts['scheme'] ? 443 : 80 );
            $home_port = $home['port'] ?? ( 'https' === $home['scheme'] ? 443 : 80 );
            if ( $parts['scheme'] !== $home['scheme'] || $port !== $home_port ) {
                return null;
            }
        }
        return esc_url_raw( $url );
    }

    /** Never echo source strings containing infrastructure identifiers or credential patterns. */
    public static function safe_value( $value, $depth = 0 ) {
        if ( $depth > 3 ) {
            return null;
        }
        if ( is_null( $value ) || is_bool( $value ) || is_int( $value ) ) {
            return $value;
        }
        if ( is_float( $value ) ) {
            return is_finite( $value ) ? $value : null;
        }
        if ( is_array( $value ) ) {
            if ( count( $value ) > 100 || ! empty( $value['private'] ) ) {
                return null;
            }
            $out = [];
            foreach ( $value as $key => $child ) {
                if ( is_string( $key ) && ( ! preg_match( '/^[a-zA-Z0-9_. -]{1,100}$/', $key ) || preg_match( '/private|debug|password|secret|credential|token|api.key|license|database.host|database.name|username|path|socket|prefix/i', $key ) ) ) {
                    continue;
                }
                $out[ $key ] = self::safe_value( $child, $depth + 1 );
            }
            return $out;
        }
        if ( ! is_string( $value ) || strlen( $value ) > 8192 || 1 !== preg_match( '//u', $value ) || preg_match_all( '/./us', $value ) > 2048 || preg_match( '#(?:https?://|[A-Z]:[\\\\/]|/(?:[a-zA-Z0-9_.-]+/)+|[a-z0-9_.+-]+@[a-z0-9.-]+\.[a-z]{2,}|\b(?:[0-9]{1,3}\.){3}[0-9]{1,3}\b|password|secret|credential|bearer|authorization|api[_ -]?key|license|signature|[?&](?:token|auth)=)#i', $value ) ) {
            return null;
        }
        // Reject short root paths, UNC shares, IPv6 literals and private-domain banners too.
        if ( preg_match( '#(?:^|[\s=("])/(?:[a-zA-Z0-9_.~-]+)(?:/|\b)|\\\\\\\\[a-z0-9_.-]+[\\\\/]|\b[a-z0-9.-]+\.(?:local|internal|lan|localhost|test|invalid)\b#i', $value ) ) {
            return null;
        }
        foreach ( preg_split( '/[\s\[\](),;=]+/', $value ) as $part ) {
            if ( filter_var( $part, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
                return null;
            }
        }
        return sanitize_text_field( $value );
    }

    public static function bounded( $result, $bytes = 1048576 ) {
        Support::check( strlen( wp_json_encode( $result ) ) <= $bytes, __( 'The response exceeds its bound. Select fewer sections or a smaller page.', 'directorist' ) );
        return $result;
    }
}
