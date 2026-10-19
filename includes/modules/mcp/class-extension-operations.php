<?php
namespace Directorist\MCP;

defined( 'ABSPATH' ) || exit;

/** Durable dispatch/reconciliation for extension effects outside the database transaction. */
class Extension_Operations {
    private static function key( $id ) {
        Support::check( is_string( $id ) && preg_match( '/^[a-f0-9-]{36}$/', $id ), __( 'Operation not found.', 'directorist' ), 'not_found' );
        return '_directorist_mcp_extension_operation_' . $id;
    }

    private static function save( array $record ) {
        $key = self::key( $record['operation_id'] );
        update_option( $key, $record, false );
        Support::check( get_option( $key ) === $record, __( 'The operation state could not be saved.', 'directorist' ), 'outcome_unknown' );
    }

    private static function payload( array $args ) {
        unset( $args['dry_run'], $args['confirm_token'], $args['idempotency_key'] );
        return $args;
    }

    public static function retry( $tool, array $args ) {
        if ( ! empty( $args['dry_run'] ) || empty( $args['idempotency_key'] ) ) {
            return null;
        }
        $key = '_directorist_mcp_extension_key_' . Support::hash( [ get_current_blog_id(), get_current_user_id(), $tool, $args['idempotency_key'] ] );
        $id  = get_option( $key );
        if ( ! $id ) {
            return null;
        }
        $record = get_option( self::key( $id ) );
        Support::check( $record && hash_equals( $record['digest'], Support::hash( self::payload( $args ) ) ), __( 'The operation key belongs to a different action.', 'directorist' ), 'idempotency_conflict' );
        Access::permission( $tool, $args );
        return self::projection( $record ) + [ 'idempotent_replay' => true ];
    }

    public static function execute( $tool, array $args, array $d, callable $writer ) {
        $old = self::retry( $tool, $args );
        if ( null !== $old ) {
            return $old;
        }
        $id     = wp_generate_uuid4();
        $record = [
            'operation_id'       => $id,
            'tool'               => $tool,
            'owner'              => $d['owner'],
            'actor'              => get_current_user_id(),
            'contract'           => $d['contract_version'],
            'registry'           => Registry::instance()->fingerprint(),
            'args'               => self::payload( $args ),
            'digest'             => Support::hash( self::payload( $args ) ),
            'state'              => 'reserved',
            'external_effect'    => 'none',
            'committed'          => false,
            'provider_reference' => $id,
            'request_id'         => Module::request_id(),
            'created_at'         => gmdate( 'c' ),
        ];
        Support::check( add_option( self::key( $id ), $record, '', false ), __( 'Could not reserve the external operation.', 'directorist' ), 'operation_in_progress' );
        $key = '_directorist_mcp_extension_key_' . Support::hash( [ get_current_blog_id(), get_current_user_id(), $tool, $args['idempotency_key'] ] );
        Support::check( add_option( $key, $id, '', false ), __( 'This external action is already reserved.', 'directorist' ), 'operation_in_progress' );
        try {
            $record['state']           = 'dispatching';
            $record['external_effect'] = 'unknown';
            self::save( $record );
            $outcome = Read_Guard::run(
                static function () use ( $d, $id, $record ) { return Support::extension_callback( $d['dispatch'], $id, $record['args'] );
                }, true
            );
            $record  = self::observe( $record, $outcome );
            self::save( $record );
            if ( 'settled' === $record['state'] ) {
                $record['state'] = 'recording';
                self::save( $record );
                $record['result']    = Write_Guard::atomic( $writer, $d['tables'], $d['invalidate'] );
                $record['committed'] = true;
                $record['state']     = 'settled';
                self::save( $record );
            }
        } catch ( \Throwable $error ) {
            $record['state']     = 'unknown';
            $record['committed'] = 'unknown';
            self::save( $record );
        }
        return self::projection( $record );
    }

    private static function observe( array $record, $result ) {
        Support::check( is_array( $result ) && in_array( $result['state'] ?? '', [ 'pending', 'settled', 'failed', 'unknown' ], true ), __( 'Invalid external outcome.', 'directorist' ), 'outcome_unknown' );
        $record['state']           = $result['state'];
        $record['external_effect'] = $result['state'];
        if ( isset( $result['reference'] ) ) {
            Support::check( is_string( $result['reference'] ) && strlen( $result['reference'] ) <= 256, __( 'Invalid external reference.', 'directorist' ), 'outcome_unknown' );
            $record['provider_reference'] = $result['reference'];
        }
        return $record;
    }

    public static function read_any( array $args ) {
        $record = get_option( self::key( $args['operation_id'] ) );
        Support::check( $record && (int) $record['actor'] === get_current_user_id(), __( 'Operation not found.', 'directorist' ), 'not_found' );
        return self::read( $record['tool'], $args );
    }

    public static function read( $tool, array $args ) {
        $record = get_option( self::key( $args['operation_id'] ) );
        Support::check( $record && $record['tool'] === $tool && (int) $record['actor'] === get_current_user_id(), __( 'Operation not found.', 'directorist' ), 'not_found' );
        $definitions = Catalog::definitions();
        $d           = $definitions[ $tool ] ?? null;
        if ( ! $d || ! Registry::instance()->admitted( $record['owner'] ) ) {
            // Preserve a safe initiating-user tombstone without exposing the old input/result snapshot.
            return [ 'success' => true, 'operation_id' => $record['operation_id'], 'state' => 'provider_unavailable', 'completed' => false, 'committed' => $record['committed'], 'external_effect' => $record['external_effect'], 'request_id' => $record['request_id'], 'next_step' => 'Restore a compatible integration to reconcile. Do not repeat the external action.' ];
        }
        try {
            Access::permission( $tool, $record['args'] );
        } catch ( Failure $error ) {
            Support::fail( 'not_found', __( 'Operation not found.', 'directorist' ) );
        }
        if ( $d['contract_version'] === $record['contract'] && in_array( $record['state'], [ 'pending', 'dispatching', 'unknown' ], true ) ) {
            try {
                $record = self::observe( $record, Support::extension_callback( $d['reconcile'], $record['operation_id'], $record['provider_reference'], $record['args'] ) );
            } catch ( Failure $error ) {
                // Preserve the original uncertain outcome; never dispatch or rerun the writer from a read.
            }
        }
        return self::projection( $record );
    }

    /** Bind an already committed native result after verified provider settlement; never dispatches or reruns a writer. */
    public static function complete( $operation_id, array $native_result ) {
        Access::admin();
        $owns_lock = ! Write_Guard::is_locked();
        Write_Guard::lock();
        try {
            $record = get_option( self::key( $operation_id ) );
            Support::check( $record, __( 'Operation not found.', 'directorist' ), 'not_found' );
            if ( true === $record['committed'] ) {
                Support::check( Support::hash( $record['result'] ) === Support::hash( $native_result ), __( 'The operation already has a different native result.', 'directorist' ), 'idempotency_conflict' ); return self::projection( $record );
            }
            $d = Catalog::definitions()[ $record['tool'] ] ?? null;
            Support::check( $d && Registry::instance()->active( $record['owner'] ) && $d['contract_version'] === $record['contract'] && is_callable( $d['verify_record'] ?? null ), __( 'A compatible native result verifier is required.', 'directorist' ), 'extension_unavailable' );
            Access::permission( $record['tool'], $record['args'] );
            $outcome = Read_Guard::run(
                static function () use ( $d, $record ) { return Support::extension_callback( $d['reconcile'], $record['operation_id'], $record['provider_reference'], $record['args'] );
                }
            );
            Support::check( 'settled' === ( $outcome['state'] ?? '' ), __( 'External settlement is not verified.', 'directorist' ), 'dependency_blocked' );
            $valid = Read_Guard::run(
                static function () use ( $d, $record, $native_result ) { return Support::extension_callback( $d['verify_record'], $record['operation_id'], $record['args'], $native_result );
                }
            );
            Support::check( true === $valid, __( 'The native result cannot be verified.', 'directorist' ), 'dependency_blocked' );
            Support::unwrap( rest_validate_value_from_schema( $native_result, $d['output_schema'] ) );
            $record['result']          = $native_result;
            $record['state']           = 'settled';
            $record['external_effect'] = 'settled';
            $record['committed']       = true;
            self::save( $record );
            return self::projection( $record );
        } finally {
            if ( $owns_lock ) {
                Write_Guard::unlock();
            }
        }
    }

    private static function projection( array $record ) {
        $complete = 'settled' === $record['state'] && true === $record['committed'];
        $result   = [
            'success'         => ! in_array( $record['state'], [ 'unknown', 'failed' ], true ),
            'completed'       => $complete || 'failed' === $record['state'],
            'operation_id'    => $record['operation_id'],
            'state'           => $record['state'],
            'committed'       => $record['committed'],
            'external_effect' => $record['external_effect'],
            'request_id'      => $record['request_id'],
            'next_step'       => $complete ? 'Read the current native object.' : 'Reconcile the original operation. Do not repeat the dispatch or native writer.',
        ];
        if ( isset( $record['result'] ) ) {
            $d = Catalog::definitions()[ $record['tool'] ] ?? null;
            if ( $d && $d['contract_version'] === $record['contract'] ) {
                $result['result'] = Read_Guard::run(
                    static function () use ( $d, $record ) { return Support::extension_callback( $d['project'], $record['result'], $record['args'], get_current_user_id() );
                    }
                );
            }
        }
        return $result;
    }

    public static function output_schema( array $d ) {
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => [ 'operation_id', 'state', 'committed', 'completed', 'external_effect' ],
            'properties'           => [
                'success'           => [ 'type' => 'boolean' ],
                'completed'         => [ 'type' => 'boolean' ],
                'operation_id'      => [ 'type' => 'string', 'maxLength' => 36 ],
                'state'             => [ 'type' => 'string', 'maxLength' => 32 ],
                'committed'         => [ 'type' => [ 'boolean', 'string' ] ],
                'external_effect'   => [ 'type' => 'string', 'maxLength' => 32 ],
                'request_id'        => [ 'type' => 'string', 'maxLength' => 36 ],
                'next_step'         => [ 'type' => 'string', 'maxLength' => 256 ],
                'idempotent_replay' => [ 'type' => 'boolean' ],
                'result'            => $d['output_schema'],
            ],
        ];
    }
}
