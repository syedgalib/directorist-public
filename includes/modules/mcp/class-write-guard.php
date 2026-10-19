<?php
namespace Directorist\MCP;

defined( 'ABSPATH' ) || exit;

/** Shared serialization, preview binding and durable retry records. No new tables. */
class Write_Guard {
    private static $locked = false;

    private static $mutating = false;

    private static $active_key = '';

    public static function is_locked() {
        return (bool) self::$locked;
    }

    public static function is_mutating() {
        return self::$mutating;
    }

    public static function lock() {
        if ( self::$locked ) {
            return;
        }
        global $wpdb;
        $name = 'directorist_mcp_' . md5( DB_NAME . ':' . $wpdb->prefix );
        $got  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $name ) );
        Support::check( '1' === (string) $got, __( 'Another Directorist operation is in progress. Retry shortly.', 'directorist' ), 'operation_in_progress' );
        self::$locked = $name;
    }

    public static function unlock() {
        if ( self::$locked ) {
            global $wpdb;
            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::$locked ) );
            self::$locked = false;
        }
    }

    /** Native Directorist changes participate in the same advisory lock. */
    public static function native_lock() {
        if ( ! self::$locked && Module::enabled() && Module::dependencies() ) {
            try {
                self::lock();
            } catch ( Failure $error ) {
                wp_die( esc_html( $error->getMessage() ), esc_html__( 'Directorist is busy', 'directorist' ), [ 'response' => 409 ] );
            }
            register_shutdown_function( [ self::class, 'unlock' ] );
        }
    }

    public static function fresh() {
        wp_cache_delete( 'atbdp_option', 'options' );
        wp_cache_delete( 'alloptions', 'options' );
        wp_cache_delete( 'directorist_mcp_epoch', 'options' );
    }

    public static function run( $tool, array $args, callable $prepare ) {
        Read_Guard::assert_writable();
        Support::check( ! self::$mutating, __( 'Nested MCP mutations are unsupported. Use one declared native transaction.', 'directorist' ), 'unsupported_operation' );
        self::lock();
        try {
            self::fresh();
            Access::permission( $tool, $args );
            $dry = $args['dry_run'] ?? false;
            Support::check( is_bool( $dry ), __( 'dry_run must be a boolean.', 'directorist' ) );
            $payload = $args;
            unset( $payload['dry_run'], $payload['confirm_token'], $payload['idempotency_key'] );
            $digest               = Support::hash( [ get_current_blog_id(), get_current_user_id(), $tool, $payload ] );
            $confirmation_payload = $payload;
            unset( $confirmation_payload['expected_version'], $confirmation_payload['schema_version'] );
            foreach ( $confirmation_payload['items'] ?? [] as $index => $item ) {
                unset( $item['expected_version'], $item['schema_version'] );
                $confirmation_payload['items'][ $index ] = $item;
            }
            $confirmation_digest = Support::hash( [ get_current_blog_id(), get_current_user_id(), $tool, $confirmation_payload ] );
            $durable             = Order_Operations::retry( $tool, $args );
            if ( null !== $durable ) {
                return $durable;
            }
            $extension_retry = Extension_Operations::retry( $tool, $args );
            if ( null !== $extension_retry ) {
                return $extension_retry;
            }
            $key = '';
            if ( ! $dry ) {
                $input = $args['idempotency_key'] ?? '';
                Support::check( is_string( $input ) && preg_match( '/^[A-Za-z0-9_.:-]{8,128}$/', $input ), __( 'Provide an idempotency_key of 8–128 letters, numbers, dots, colons, hyphens or underscores.', 'directorist' ), 'idempotency_required' );
                $key = '_directorist_mcp_op_' . Support::hash( [ get_current_blog_id(), get_current_user_id(), $tool, $input ] );
                $old = get_option( $key );
                if ( is_array( $old ) && $old['expires'] > time() ) {
                    Support::check( hash_equals( $old['digest'], $digest ), __( 'This idempotency key was used for a different action.', 'directorist' ), 'idempotency_conflict' );
                    Support::check( ( $old['registry'] ?? '' ) === Registry::instance()->fingerprint(), __( 'The extension contract changed. Reconcile before retrying.', 'directorist' ), 'contract_changed' );
                    if ( 'done' === $old['state'] ) {
                        Support::event( $tool, 'replay', $args );
                        return self::replay( $old['result'], $args, $tool );
                    }
                    Support::fail( 'outcome_unknown', __( 'This operation may have committed. Inspect the target before trying a new action.', 'directorist' ), [ 'committed' => 'unknown', 'next_step' => 'Read the target and reconcile using the request ID.', 'request_id' => $old['request_id'] ] );
                }
                if ( $old ) {
                    delete_option( $key );
                }
            }
            $plan   = Read_Guard::run( $prepare );
            $stored = [];
            Support::check( isset( $plan['state'], $plan['preview'], $plan['execute'] ), __( 'The operation is unavailable.', 'directorist' ), 'unsupported_operation' );
            $state = Support::hash( [ $plan['state'], get_option( 'directorist_mcp_epoch', '' ), Registry::instance()->fingerprint() ] );
            if ( $dry ) {
                $token = bin2hex( random_bytes( 24 ) );
                set_transient( 'directorist_mcp_preview_' . hash( 'sha256', $token ), [ 'digest' => $confirmation_digest, 'state' => $state, 'plan_state' => ! empty( $plan['bulk'] ) ? $plan['state'] : null, 'epoch' => get_option( 'directorist_mcp_epoch', '' ), 'user_id' => get_current_user_id(), 'tool' => $tool, 'registry' => Registry::instance()->fingerprint() ], 300 );
                Support::event( $tool, 'preview', $args );
                return [ 'dry_run' => true, 'changes' => $plan['preview'], 'confirm_token' => $token, 'expires_in_seconds' => 300, 'warnings' => $plan['warnings'] ?? [] ];
            }
            if ( ! empty( $plan['confirm'] ) ) {
                $token = $args['confirm_token'] ?? '';
                Support::check( is_string( $token ) && $token, __( 'Preview this action using dry_run:true before executing.', 'directorist' ), 'confirmation_required' );
                $token_key = 'directorist_mcp_preview_' . hash( 'sha256', $token );
                $stored    = get_transient( $token_key );
                Support::check( $stored && (int) $stored['user_id'] === get_current_user_id() && $stored['tool'] === $tool && hash_equals( $stored['digest'], $confirmation_digest ) && $stored['epoch'] === get_option( 'directorist_mcp_epoch', '' ) && ( $stored['registry'] ?? '' ) === Registry::instance()->fingerprint(), __( 'The confirmation is invalid or expired. Preview again.', 'directorist' ), 'confirmation_invalid' );
                // Bulk plans bind each record independently; their executor reports stale items.
                if ( empty( $plan['bulk'] ) ) {
                    Support::check( hash_equals( $stored['state'], $state ), __( 'The state changed since preview. Preview again.', 'directorist' ), 'state_changed' );
                }
                delete_transient( $token_key );
            }
            self::check_tables( $plan['tables'] ?? [] );
            $record = [ 'registry' => Registry::instance()->fingerprint(), 'digest' => $digest, 'state' => 'running', 'expires' => time() + DAY_IN_SECONDS, 'request_id' => Module::request_id() ];
            Support::check( add_option( $key, $record, '', false ), __( 'Could not reserve this operation. No changes were made.', 'directorist' ), 'operation_in_progress' );
            self::$active_key = $key;
            self::$mutating   = true;
            $run              = static function () use ( $plan, $stored ) {
                return call_user_func( $plan['execute'], $stored['plan_state'] ?? null );
            };
            $result           = empty( $plan['bulk'] ) && empty( $plan['orchestrated'] ) ? self::atomic( $run, $plan['tables'] ?? [], $plan['invalidate'] ?? null ) : $run();
            self::$mutating   = false;
            $record['state']  = 'done';
            $record['result'] = $result;
            if ( ! update_option( $key, $record, false ) ) {
                Support::fail( 'outcome_unknown', __( 'The operation finished but its retry record could not be saved. Read the target to reconcile.', 'directorist' ), [ 'committed' => 'unknown' ] );
            }
            Support::event( $tool, true === ( $result['committed'] ?? false ) ? 'completed' : 'no_changes', $args );
            return $result;
        } catch ( \Throwable $error ) {
            if ( self::$mutating ) {
                self::$mutating = false;
                // Keep the running reservation: never blindly repeat a possibly committed action.
                Support::event( $tool, 'indeterminate', $args, 'outcome_unknown' );
                throw new Failure( 'outcome_unknown', __( 'The operation did not finish cleanly and may have committed. Read the target before retrying.', 'directorist' ), [ 'committed' => 'unknown', 'request_id' => Module::request_id() ] );
            }
            Support::event( $tool, 'rejected', $args, $error instanceof Failure ? $error->reason : 'operation_failed' );
            throw $error;
        } finally {
            self::$active_key = '';
            self::unlock();
        }
    }

    public static function checkpoint( array $results ) {
        if ( self::$active_key ) {
            $record             = get_option( self::$active_key );
            $record['progress'] = $results;
            update_option( self::$active_key, $record, false );
        }
    }

    /** Roll back a failed logical unit; preserve already committed bulk siblings. */
    public static function atomic( callable $callback, array $tables = [], $invalidate = null ) {
        Read_Guard::assert_writable();
        self::check_tables( $tables );
        global $wpdb;
        static $checked = false;
        if ( ! $checked ) {
            foreach ( [ $wpdb->posts, $wpdb->postmeta, $wpdb->terms, $wpdb->term_taxonomy, $wpdb->term_relationships, $wpdb->termmeta, $wpdb->options ] as $table ) {
                $engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) );
                Support::check( 'INNODB' === strtoupper( (string) $engine ), __( 'MCP writes require transactional WordPress tables. No data has been changed.', 'directorist' ), 'unsupported_operation' );
            }
            $checked = true;
        }
        $post_ids     = [];
        $term_ids     = [];
        $option_names = [];
        $post_hook    = static function ( $id ) use ( &$post_ids ) {
            $post_ids[ (int) $id ] = true;
        };
        $term_hook    = static function ( $id, $tt_id, $taxonomy ) use ( &$term_ids ) {
            $term_ids[ $taxonomy ][ (int) $id ] = true;
        };
        $option_hook  = static function ( $name ) use ( &$option_names ) {
            $option_names[ $name ] = true;
        };
        add_action( 'clean_post_cache', $post_hook );
        add_action( 'created_term', $term_hook, 1, 3 );
        add_action( 'edited_term', $term_hook, 1, 3 );
        add_action( 'delete_term', $term_hook, 1, 3 );
        add_action( 'updated_option', $option_hook );
        add_action( 'added_option', $option_hook );
        Support::check( false !== $wpdb->query( 'START TRANSACTION' ), __( 'Could not begin a safe write transaction.', 'directorist' ), 'operation_failed' );
        try {
            $result = $callback();
            Support::check( false !== $wpdb->query( 'COMMIT' ), __( 'The database commit could not be confirmed.', 'directorist' ), 'outcome_unknown' );
            return $result;
        } catch ( \Throwable $error ) {
            $wpdb->query( 'ROLLBACK' );
            foreach ( array_keys( $post_ids ) as $id ) {
                clean_post_cache( $id );
                wp_cache_delete( $id, 'post_meta' );
            }
            foreach ( $term_ids as $taxonomy => $ids ) {
                clean_term_cache( array_keys( $ids ), $taxonomy );
                foreach ( array_keys( $ids ) as $id ) {
                    wp_cache_delete( $id, 'term_meta' );
                }
            }
            foreach ( array_keys( $option_names ) as $name ) {
                wp_cache_delete( $name, 'options' );
            }
            wp_cache_delete( 'alloptions', 'options' );
            wp_cache_delete( 'notoptions', 'options' );
            if ( function_exists( 'wp_cache_set_posts_last_changed' ) ) {
                wp_cache_set_posts_last_changed();
            }
            if ( function_exists( 'wp_cache_set_terms_last_changed' ) ) {
                wp_cache_set_terms_last_changed();
            }
            throw $error;
        } finally {
            if ( is_callable( $invalidate ) ) {
                call_user_func( $invalidate );
            }
            remove_action( 'clean_post_cache', $post_hook );
            remove_action( 'created_term', $term_hook, 1 );
            remove_action( 'edited_term', $term_hook, 1 );
            remove_action( 'delete_term', $term_hook, 1 );
            remove_action( 'updated_option', $option_hook );
            remove_action( 'added_option', $option_hook );
        }
    }

    private static function replay( array $result, array $args, $tool ) {
        $definition = Catalog::definitions()[ $tool ];
        if ( isset( $definition['owner'] ) && empty( $definition['bulk_of'] ) ) {
            $result = Read_Guard::run(
                static function () use ( $definition, $result, $args ) {
                    return Support::result( Support::extension_callback( $definition['project'], $result, $args, get_current_user_id() ) );
                }
            );
        } elseif ( isset( $definition['bulk_of'] ) ) {
            $child  = $definition['bulk_of'];
            $inputs = array_column( $args['items'], null, 'client_ref' );
            foreach ( $result['results'] as &$item ) {
                $ref = $item['client_ref'];
                if ( empty( $item['success'] ) ) {
                    continue;
                }
                try {
                    Access::permission( $child, $inputs[ $ref ] );
                    $child_definition = Catalog::definitions()[ $child ];
                    $projected        = Read_Guard::run(
                        static function () use ( $child_definition, $item, $inputs, $ref ) { return Support::extension_callback( $child_definition['project'], $item, $inputs[ $ref ], get_current_user_id() );
                        }
                    );
                    $item             = [ 'client_ref' => $ref ] + Support::result( $projected );
                } catch ( Failure $error ) {
                    $item = [ 'client_ref' => $ref, 'success' => false, 'committed' => false, 'error' => [ 'code' => 'not_found', 'message' => __( 'The item is no longer accessible.', 'directorist' ) ] ];
                }
            }
            unset( $item );
        }
        if ( isset( $result['order_id'] ) ) {
            Orders::get( (int) $result['order_id'] );
        }
        // Re-evaluate current field permissions; never replay an old private value snapshot.
        if ( isset( $result['listing_id'] ) ) {
            Access::listing( (int) $result['listing_id'], in_array( $tool, [ 'trash-listing', 'restore-listing' ], true ) ? 'delete' : 'edit' );
        }
        if ( isset( $result['results'] ) ) {
            $result['results'] = array_map(
                static function ( $item ) {
                    if ( isset( $item['order_id'] ) ) {
                        try {
                            Orders::get( (int) $item['order_id'] );
                        } catch ( Failure $error ) {
                            return [ 'client_ref' => $item['client_ref'], 'success' => false, 'error' => [ 'code' => 'not_found' ] ];
                        }
                    }
                    if ( isset( $item['listing_id'] ) ) {
                        try {
                            Access::listing( (int) $item['listing_id'], 'edit' );
                        } catch ( Failure $error ) {
                            return [ 'client_ref' => $item['client_ref'], 'success' => false, 'error' => [ 'code' => 'not_found', 'message' => __( 'Listing not found.', 'directorist' ) ] ];
                        }
                    }
                    unset( $item['data'], $item['fields'] );
                    return $item;
                }, $result['results']
            );
        }
        unset( $result['data'], $result['fields'], $result['values'] );
        $result['idempotent_replay'] = true;
        return $result;
    }

    public static function check_tables( array $tables ) {
        global $wpdb;
        foreach ( $tables as $table ) {
            Support::check( is_string( $table ) && preg_match( '/^[A-Za-z0-9_]+$/', $table ), __( 'Invalid registered transaction table.', 'directorist' ), 'unsupported_operation' );
            $engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) );
            Support::check( 'INNODB' === strtoupper( (string) $engine ), __( 'A registered store is unavailable or nontransactional.', 'directorist' ), 'unsupported_operation' );
        }
    }

    public static function cleanup( $cursor = 0 ) {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_id, option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_id > %d ORDER BY option_id ASC LIMIT 500", $wpdb->esc_like( '_directorist_mcp_op_' ) . '%', (int) $cursor ) );
        foreach ( $rows as $row ) {
            $name   = $row->option_name;
            $record = get_option( $name );
            if ( is_array( $record ) && ( $record['expires'] ?? 0 ) < time() ) {
                delete_option( $name );
            }
        }
        if ( 500 === count( $rows ) ) {
            wp_schedule_single_event( time() + 60, 'directorist_mcp_cleanup', [ (int) end( $rows )->option_id ] );
        }
    }
}
