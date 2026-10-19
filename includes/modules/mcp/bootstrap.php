<?php
/** Directorist's optional MCP integration. */
defined( 'ABSPATH' ) || exit;

spl_autoload_register(
    static function ( $class ) {
        $prefix = 'Directorist\\MCP\\';
        if ( 0 !== strpos( $class, $prefix ) ) {
            return;
        }
        $name = substr( $class, strlen( $prefix ) );
        if ( false !== strpos( $name, '\\' ) ) {
            return;
        }
        $file = __DIR__ . '/class-' . str_replace( '_', '-', strtolower( $name ) ) . '.php';
        if ( is_readable( $file ) ) {
            require_once $file;
        }
    }
);

\Directorist\MCP\Module::boot();
