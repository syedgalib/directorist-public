<?php
namespace Directorist\MCP;

defined( 'ABSPATH' ) || exit;

/** Non-autoloaded durable financial intents; deliberately not expired by the replay cleanup. */
class Order_Operations {
    private static function key( $id ) {
        Support::check( is_string( $id ) && preg_match( '/^[a-f0-9-]{36}$/', $id ), __( 'Operation not found.', 'directorist' ), 'not_found' );
        return '_directorist_mcp_order_operation_' . $id;
    }

    private static function save( array $record ) {
        $key = self::key( $record['operation_id'] );
        update_option( $key, $record, false );
        Support::check( get_option( $key ) === $record, __( 'The operation state could not be saved.', 'directorist' ), 'outcome_unknown' );
    }

    public static function retry( $tool, array $args ) {
        if ( empty( $args['idempotency_key'] ) || ! empty( $args['dry_run'] ) ) {
            return null;
        }
        $key = '_directorist_mcp_financial_key_' . Support::hash( [ get_current_blog_id(), get_current_user_id(), $tool, $args['idempotency_key'] ] );
        $id  = get_option( $key );
        if ( ! $id ) {
            return null;
        }
        $record  = get_option( self::key( $id ) );
        $payload = $args;
        unset( $payload['confirm_token'], $payload['idempotency_key'], $payload['dry_run'] );
        Support::check( $record && hash_equals( $record['digest'], Support::hash( $payload ) ), __( 'This key belongs to a different financial operation.', 'directorist' ), 'idempotency_conflict' );
        Orders::get( (int) $record['order_id'] );
        return self::projection( $record ) + [ 'idempotent_replay' => true ];
    }

    public static function check_evidence( $tool, array $args ) {
        if ( isset( $args['evidence_reference'] ) ) {
            $key = '_directorist_mcp_receipt_' . Support::hash( [ $tool, $args['method'] ?? $args['mode'] ?? '', $args['evidence_reference'] ] );
            Support::check( ! get_option( $key ), __( 'This evidence was already recorded. Reconcile the original receipt.', 'directorist' ), 'duplicate_receipt' );
        }
    }

    public static function reserved( $order_id, $currency, $except = '' ) {
        global $wpdb;
        $prefix = '_directorist_mcp_financial_order_' . (int) $order_id . '_';
        $rows   = $wpdb->get_col( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 1001", $wpdb->esc_like( $prefix ) . '%' ) );
        Support::check( count( $rows ) <= 1000, __( 'The order needs native financial reconciliation before more refunds.', 'directorist' ), 'dependency_blocked' );
        $reserved = 0;
        foreach ( $rows as $id ) {
            if ( $id === $except ) {
                continue;
            }
            $record = get_option( self::key( $id ) );
            if ( $record && 'create-order-refund' === $record['tool'] && ! in_array( $record['state'], [ 'settled', 'failed' ], true ) ) {
                Support::check( $record['currency'] === $currency, __( 'The refund ledger has inconsistent currencies.', 'directorist' ), 'dependency_blocked' );
                $reserved += Money::minor( $record['amount'], $currency );
            }
        }
        return $reserved;
    }

    public static function execute( $tool, array $args, $order, callable $native, array $provider = [] ) {
        $replay = self::retry( $tool, $args );
        if ( null !== $replay ) {
            return $replay;
        }
        $id      = wp_generate_uuid4();
        $payload = $args;
        unset( $payload['confirm_token'], $payload['idempotency_key'], $payload['dry_run'] );
        $record = [
            'operation_id'       => $id,
            'order_id'           => (int) $order->id,
            'actor'              => get_current_user_id(),
            'tool'               => $tool,
            'digest'             => Support::hash( $payload ),
            'state'              => 'reserved',
            'committed'          => false,
            'external_effect'    => 'none',
            'amount'             => $args['amount'] ?? '0',
            'currency'           => $order->currency,
            'provider'           => $args['provider'] ?? '',
            'provider_reference' => $id,
            'provider_contract'  => $provider['contract_version'] ?? '',
            'request_id'         => Module::request_id(),
            'created_at'         => gmdate( 'c' ),
        ];
        $key    = '_directorist_mcp_financial_key_' . Support::hash( [ get_current_blog_id(), get_current_user_id(), $tool, $args['idempotency_key'] ] );
        Support::check( add_option( self::key( $id ), $record, '', false ), __( 'Could not reserve the financial intent.', 'directorist' ), 'operation_in_progress' );
        Support::check( add_option( $key, $id, '', false ), __( 'The financial key is already reserved.', 'directorist' ), 'operation_in_progress' );
        Support::check( add_option( '_directorist_mcp_financial_order_' . $order->id . '_' . $id, $id, '', false ), __( 'Could not reserve the order balance.', 'directorist' ), 'operation_in_progress' );
        if ( isset( $args['evidence_reference'] ) ) {
            $receipt = '_directorist_mcp_receipt_' . Support::hash( [ $tool, $args['method'] ?? $args['mode'] ?? '', $args['evidence_reference'] ] );
            if ( ! add_option( $receipt, $id, '', false ) ) {
                $record['state'] = 'failed';
                self::save( $record );
                Support::fail( 'duplicate_receipt', __( 'This evidence has already been recorded. Reconcile the original operation.', 'directorist' ) );
            }
        }
        try {
            if ( $provider ) {
                // Commit the dispatch marker before the network call. Never hold a DB transaction here.
                $record['state']           = 'dispatching';
                $record['external_effect'] = 'unknown';
                self::save( $record );
                $outcome = Read_Guard::run(
                    static function () use ( $provider, $id, $order, $args ) { return Support::extension_callback( $provider['dispatch'], $id, $order, $args );
                    }, true
                );
                $record  = self::provider_result( $record, $outcome );
                self::save( $record );
                if ( 'settled' !== $record['state'] ) {
                    return self::projection( $record );
                }
                // Still reserve the amount until the native ledger has committed.
                $record['state'] = 'recording';
            }
            $record['state'] = 'recording';
            self::save( $record );
            $result              = Write_Guard::atomic( $native, Orders::tables() );
            $record['result']    = $result;
            $record['committed'] = true;
            $record['state']     = 'settled';
            self::save( $record );
            return self::projection( $record );
        } catch ( \Throwable $error ) {
            $record['state']     = 'unknown';
            $record['committed'] = 'unknown';
            self::save( $record );
            // Never dispatch or repeat native hooks after an uncertain write.
            return self::projection( $record );
        }
    }

    private static function provider_result( array $record, $outcome ) {
        Support::check( is_array( $outcome ) && in_array( $outcome['state'] ?? '', [ 'pending', 'settled', 'failed', 'unknown' ], true ), __( 'Invalid provider outcome.', 'directorist' ), 'outcome_unknown' );
        $record['state']           = $outcome['state'];
        $record['external_effect'] = $outcome['state'];
        if ( isset( $outcome['reference'] ) ) {
            Support::check( is_string( $outcome['reference'] ) && strlen( $outcome['reference'] ) <= 256, __( 'Invalid provider reference.', 'directorist' ), 'outcome_unknown' );
            $record['provider_reference'] = $outcome['reference'];
        }
        return $record;
    }

    public static function read( array $args ) {
        $record = get_option( self::key( $args['operation_id'] ) );
        Support::check( $record && ( current_user_can( 'manage_options' ) || ( (int) $record['actor'] === get_current_user_id() && 'cancel-order' === $record['tool'] ) ), __( 'Operation not found.', 'directorist' ), 'not_found' );
        $order = Orders::get( (int) $record['order_id'] );
        if ( $record['provider'] && in_array( $record['state'], [ 'pending', 'unknown', 'dispatching' ], true ) ) {
            $providers = Registry::instance()->fields( 'refund_providers', $order );
            if ( ! isset( $providers[ $record['provider'] ] ) || $providers[ $record['provider'] ]['contract_version'] !== $record['provider_contract'] ) {
                return self::projection( $record ) + [ 'provider_unavailable' => true ];
            }
            try {
                $provider = $providers[ $record['provider'] ];
                $observed = self::provider_result( $record, call_user_func( $provider['reconcile'], $record['operation_id'], $record['provider_reference'], $order ) );
                // Read-based reconciliation may report settlement; it does not write a refund or replay fulfillment.
                $result              = self::projection( $observed );
                $result['next_step'] = 'settled' === $observed['external_effect'] && true !== $record['committed'] ? 'Reconcile the native ledger through the provider integration; do not submit another refund.' : $result['next_step'];
                return $result;
            } catch ( \Throwable $error ) {
                return self::projection( $record );
            }
        }
        return self::projection( $record );
    }

    /** Trusted native/admin reconciliation after the provider integration has recorded a settled refund. */
    public static function complete_refund( $operation_id, $refund_id ) {
        Access::admin();
        $owns_lock = ! Write_Guard::is_locked();
        Write_Guard::lock();
        try {
            $record = get_option( self::key( $operation_id ) );
            Support::check( $record && 'create-order-refund' === $record['tool'] && ! empty( $record['provider'] ), __( 'Refund operation not found.', 'directorist' ), 'not_found' );
            if ( true === $record['committed'] ) {
                Support::check( (int) ( $record['result']['refund_id'] ?? 0 ) === $refund_id, __( 'The operation is already linked to a different native refund.', 'directorist' ), 'idempotency_conflict' ); return self::projection( $record );
            }
            $order     = Orders::get( (int) $record['order_id'] );
            $providers = Registry::instance()->fields( 'refund_providers', $order );
            Support::check( isset( $providers[ $record['provider'] ] ) && $providers[ $record['provider'] ]['contract_version'] === $record['provider_contract'], __( 'A compatible provider is required to reconcile.', 'directorist' ), 'extension_unavailable' );
            $provider = $providers[ $record['provider'] ];
            $outcome  = Read_Guard::run(
                static function () use ( $provider, $record, $order ) { return Support::extension_callback( $provider['reconcile'], $record['operation_id'], $record['provider_reference'], $order );
                }
            );
            Support::check( 'settled' === ( $outcome['state'] ?? '' ), __( 'Provider settlement has not been verified.', 'directorist' ), 'dependency_blocked' );
            $refund = ( new \Directorist\Repositories\RefundRepository() )->get_by_id( Support::id( $refund_id ) );
            Support::check( $refund && (int) $refund->order_id === (int) $order->id && 'paid' === $refund->status && Money::minor( Money::stored( $refund->amount, $order->currency ), $order->currency ) === Money::minor( $record['amount'], $order->currency ), __( 'The native settled refund does not match the operation.', 'directorist' ), 'dependency_blocked' );
            $proof    = '_directorist_mcp_refund_proof_' . $refund_id;
            $existing = get_option( $proof );
            Support::check( ! $existing || $existing === $operation_id, __( 'This refund is already linked to another operation.', 'directorist' ), 'idempotency_conflict' );
            if ( ! $existing ) {
                Support::check( add_option( $proof, $operation_id, '', false ), __( 'Could not reserve the native proof.', 'directorist' ), 'operation_in_progress' );
            }
            $record['state']           = 'settled';
            $record['external_effect'] = 'settled';
            $record['committed']       = true;
            $record['result']          = Support::result( [ 'refund_id' => $refund_id, 'mode' => 'gateway', 'gateway_refund_performed' => true ] );
            self::save( $record );
            return self::projection( $record );
        } finally {
            if ( $owns_lock ) {
                Write_Guard::unlock();
            }
        }
    }

    private static function projection( array $record ) {
        $settled = 'settled' === $record['state'] && true === $record['committed'];
        return [
            'success'         => ! in_array( $record['state'], [ 'unknown', 'failed' ], true ),
            'completed'       => $settled || 'failed' === $record['state'],
            'committed'       => $record['committed'],
            'operation_id'    => $record['operation_id'],
            'order_id'        => (int) $record['order_id'],
            'state'           => $record['state'],
            'external_effect' => $record['external_effect'],
            'request_id'      => $record['request_id'],
            'result'          => $record['result'] ?? null,
            'next_step'       => $settled ? 'Read the current order.' : 'Read this operation to reconcile. Do not dispatch a replacement financial action.',
        ];
    }
}
