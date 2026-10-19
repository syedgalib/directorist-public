<?php
namespace Directorist\MCP;

defined( 'ABSPATH' ) || exit;

/** Enforce read/preview contracts on cooperating registered paths and SQL/HTTP writes. */
class Read_Guard {
    private static $depth = 0;

    public static function assert_writable() {
        Support::check( 0 === self::$depth, __( 'A read or preview cannot mutate product data.', 'directorist' ), 'read_only_violation' );
    }

    public static function run( callable $callback, $allow_external_writes = false, $deny_http = false ) {
        ++self::$depth;
        $query = static function ( $sql ) {
            if ( ! preg_match( '/^\s*(SELECT|SHOW|DESCRIBE|EXPLAIN)\b/i', $sql ) ) {
                Support::fail( 'read_only_violation', __( 'A read or preview attempted a database write.', 'directorist' ) );
            }
            return $sql;
        };
        $http  = static function ( $preempt, $args ) use ( $deny_http ) {
            Support::check( ! $deny_http && in_array( strtoupper( $args['method'] ?? 'GET' ), [ 'GET', 'HEAD' ], true ), __( 'This read does not permit the requested external operation.', 'directorist' ), 'read_only_violation' );
            return $preempt;
        };
        add_filter( 'query', $query, PHP_INT_MAX );
        if ( ! $allow_external_writes ) {
            add_filter( 'pre_http_request', $http, PHP_INT_MAX, 2 );
        }
        try {
            return $callback();
        } finally {
            --self::$depth;
            remove_filter( 'query', $query, PHP_INT_MAX );
            remove_filter( 'pre_http_request', $http, PHP_INT_MAX );
        }
    }
}
