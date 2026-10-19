<?php
namespace Directorist\MCP;

defined( 'ABSPATH' ) || exit;

/** Exact bounded integer arithmetic. Floats are only used at native DTO boundaries. */
class Money {
    public static function precision( $currency ) {
        Support::check( is_string( $currency ) && preg_match( '/^[A-Z]{3,4}$/', $currency ), __( 'Invalid currency.', 'directorist' ) );
        $three     = [ 'BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND' ];
        $zero      = [ 'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' ];
        $precision = in_array( $currency, $three, true ) ? 3 : ( in_array( $currency, $zero, true ) ? 0 : 2 );
        $precision = (int) apply_filters( 'directorist_mcp_currency_precision', $precision, $currency );
        Support::check( $precision >= 0 && $precision <= 4, __( 'Unsupported currency precision.', 'directorist' ), 'unsupported_operation' );
        return $precision;
    }

    public static function minor( $value, $currency ) {
        $precision = self::precision( $currency );
        Support::check( PHP_INT_SIZE >= 8, __( 'Exact MCP monetary arithmetic requires a 64-bit PHP runtime.', 'directorist' ), 'unsupported_operation' );
        Support::check( is_string( $value ) && preg_match( '/^(0|[1-9][0-9]{0,10})(?:\.([0-9]+))?$/', $value, $match ), __( 'Use a nonnegative exact decimal string.', 'directorist' ) );
        $fraction = $match[2] ?? '';
        Support::check( strlen( $fraction ) <= $precision, __( 'The amount exceeds currency precision.', 'directorist' ) );
        return (int) ( $match[1] . str_pad( $fraction, $precision, '0' ) );
    }

    public static function decimal( $minor, $currency ) {
        Support::check( is_int( $minor ) && $minor >= 0, __( 'Invalid monetary balance.', 'directorist' ) );
        $precision = self::precision( $currency );
        $digits    = str_pad( (string) $minor, $precision + 1, '0', STR_PAD_LEFT );
        return $precision ? substr( $digits, 0, -$precision ) . '.' . substr( $digits, -$precision ) : $digits;
    }

    public static function stored( $value, $currency ) {
        // Database DECIMAL values are strings. Native calculation results are normalized once here.
        if ( is_float( $value ) ) {
            Support::check( is_finite( $value ) && $value >= 0, __( 'Invalid native monetary value.', 'directorist' ) );
            $value = number_format( $value, self::precision( $currency ), '.', '' );
        }
        $value = (string) $value;
        if ( false !== strpos( $value, '.' ) ) {
            $value = rtrim( rtrim( $value, '0' ), '.' );
        }
        return self::decimal( self::minor( $value, $currency ), $currency );
    }

    public static function native( $value, $currency ) {
        $minor = self::minor( $value, $currency );
        // Inspected native tables/calculators use DECIMAL(...,2) and round(...,2).
        Support::check( self::precision( $currency ) <= 2 && $minor <= 100000000 * ( 10 ** self::precision( $currency ) ) - 1, __( 'This amount or precision is unsupported by the native ledger.', 'directorist' ), 'unsupported_operation' );
        return (float) self::decimal( $minor, $currency );
    }
}
