<?php
/** Controlled developer-kit integration. Included by local tests; never automatically activated. */
defined( 'ABSPATH' ) || exit;

function directorist_mcp_register_fixture( $registry, $state, $plugin = 'directorist/directorist-base.php' ) {
    global $wpdb;
    $empty  = [ 'type' => 'object', 'properties' => (object) [], 'additionalProperties' => false ];
    $string = [ 'type' => 'string', 'maxLength' => 100 ];
    $output = [ 'type' => 'object', 'properties' => [ 'value' => $string ], 'required' => [ 'value' ], 'additionalProperties' => false ];
    $admin  = static function () { return current_user_can( 'manage_options' );
    };
    $registry->register_extension(
        [
            'owner'            => 'fixture',
            'plugin'           => $plugin,
            'label'            => 'MCP contract fixture',
            'version'          => '1.0.0',
            'contract_version' => '1',
            'api_version'      => 1,
            'capability'       => 'read',
            'availability'     => static function () use ( $state ) { return $state->active;
            },
            'admission'        => static function () { return current_user_can( 'read' );
            },
        ]
    );
    $registry->register_tool(
        [
            'owner'            => 'fixture',
            'action'           => 'get-schedule',
            'domain'           => 'extension',
            'group'            => 'Schedules',
            'description'      => 'Read a safe fixture schedule.',
            'contract_version' => '1',
            'write'            => false,
            'schema'           => $empty,
            'output_schema'    => $output,
            'permission'       => $admin,
            'discover'         => $admin,
            'read'             => static function () use ( $state ) { return [ 'value' => get_option( $state->option, 'initial' ) ];
            },
            'project'          => static function ( $value ) { return [ 'value' => $value['value'] ];
            },
        ]
    );
    $registry->register_tool(
        [
            'owner'            => 'fixture',
            'action'           => 'set-schedule',
            'domain'           => 'settings',
            'group'            => 'Schedules',
            'description'      => 'Preview and edit a fixture schedule.',
            'contract_version' => '1',
            'write'            => true,
            'bulk'             => true,
            'schema'           => $output,
            'output_schema'    => $output,
            'preview_schema'   => [ 'type' => 'object', 'properties' => [ 'before' => $string, 'after' => $string ], 'additionalProperties' => false ],
            'permission'       => $admin,
            'discover'         => $admin,
            'tables'           => [ $wpdb->options ],
            'invalidate'       => static function () use ( $state ) { wp_cache_delete( $state->option, 'options' );
            },
            'project'          => static function ( $value ) { return [ 'value' => $value['value'] ];
            },
            'prepare'          => static function ( $args ) use ( $state ) {
                return [
                    'state'   => get_option( $state->option, 'initial' ),
                    'preview' => [ 'before' => get_option( $state->option, 'initial' ), 'after' => $args['value'] ],
                    'execute' => static function () use ( $state, $args ) {
                        update_option( $state->option, $args['value'], false );
                        return [ 'value' => $args['value'] ];
                    },
                ];
            },
        ]
    );
    $registry->register_tool(
        [
            'owner'            => 'fixture',
            'action'           => 'invalid-reader',
            'domain'           => 'extension',
            'group'            => 'Schedules',
            'description'      => 'Negative fixture: hidden writes must be rejected.',
            'contract_version' => '1',
            'write'            => false,
            'schema'           => $empty,
            'output_schema'    => $output,
            'permission'       => $admin,
            'discover'         => $admin,
            'read'             => static function () use ( $state ) { update_option( $state->option, 'forbidden-write', false ); return [ 'value' => 'bad' ];
            },
            'project'          => static function ( $value ) { return $value;
            },
        ]
    );
    $registry->register_tool(
        [
            'owner'            => 'fixture',
            'action'           => 'sync-schedule',
            'domain'           => 'settings',
            'group'            => 'Schedules',
            'description'      => 'Controlled external-effect fixture; never contacts a network provider.',
            'contract_version' => '1',
            'write'            => true,
            'external_effects' => true,
            'idempotent'       => true,
            'verify_record'    => static function ( $id, $args, $result ) use ( $state ) { return ( $result['value'] ?? '' ) === $args['value'] && get_option( $state->option . '_external' ) === $args['value'];
            },
            'schema'           => $output,
            'output_schema'    => $output,
            'preview_schema'   => [ 'type' => 'object', 'properties' => [ 'after' => $string ], 'additionalProperties' => false ],
            'permission'       => $admin,
            'discover'         => $admin,
            'tables'           => [ $wpdb->options ],
            'invalidate'       => static function () use ( $state ) { wp_cache_delete( $state->option . '_external', 'options' );
            },
            'project'          => static function ( $value ) { return [ 'value' => $value['value'] ];
            },
            'prepare'          => static function ( $args ) use ( $state ) {
                return [ 'state' => get_option( $state->option . '_external', 'initial' ),
                    'preview'    => [ 'after' => $args['value'] ],
                    'execute'    => static function () use ( $args, $state ) { update_option( $state->option . '_external', $args['value'], false ); return [ 'value' => $args['value'] ];
                    },
                ];
            },
            'dispatch'         => static function ( $id ) use ( $state ) { ++$state->external_dispatches; if ( $state->external_timeout ) {
                    throw new RuntimeException( 'Fake timeout' );
            }
             return [ 'state' => 'settled', 'reference' => $id ];
            },
            'reconcile'        => static function ( $id, $reference ) use ( $state ) { ++$state->external_reconciliations; return [ 'state' => 'settled', 'reference' => $reference ];
            },
        ]
    );
    $registry->register_field_adapter(
        [
            'owner'            => 'fixture',
            'domain'           => 'settings',
            'key'              => 'fixture_view_only',
            'contract_version' => '1',
            'sensitive'        => false,
            'default'          => 'visible',
            'schema'           => $string,
            'permission'       => static function ( $context = null, $action = 'read' ) { return 'read' === $action && current_user_can( 'manage_options' );
            },
            'read'             => static function () { return 'visible';
            },
            'validate'         => static function ( $value ) { return $value;
            },
            'writable'         => true,
            'storage_adapter'  => 'directorist',
            'tables'           => [ $wpdb->options ],
            'write'            => static function () { throw new RuntimeException( 'A restricted writer must never execute' );
            },
            'invalidate'       => static function () {
            },
        ]
    );
    directorist_mcp_register_fixture_adapters( $registry, $state );
    $registry->register_policy(
        [
            'owner' => 'fixture',
            'key'   => 'restricted-value',
            'tools' => [ 'fixture-set-schedule' ],
            'check' => static function ( $tool, $args ) { return 'blocked' !== ( $args['value'] ?? '' );
            },
        ]
    );
    foreach ( [ 'settings', 'listings', 'taxonomies', 'orders' ] as $domain ) {
        $permission = static function ( $context = null, $action = 'read' ) { return current_user_can( 'manage_options' );
        };
        $read       = static function ( $context = null ) use ( $state, $domain ) {
            if ( 'settings' === $domain ) {
                return get_directorist_option( 'fixture_summary', 'default' );
            }
            $id = is_object( $context ) ? ( $context->ID ?? $context->term_id ?? $context->id ?? 0 ) : 0;
            return get_option( $state->option . '_' . $domain . '_' . $id, 'default' );
        };
        $registry->register_field_adapter(
            [
                'owner'            => 'fixture',
                'domain'           => $domain,
                'key'              => 'fixture_summary',
                'internal_private' => 'must not enter discovery',
                'contract_version' => '1',
                'sensitive'        => false,
                'writable'         => true,
                'default'          => 'default',
                'schema'           => $string,
                'storage_adapter'  => 'directorist',
                'tables'           => [ $wpdb->options ],
                'permission'       => $permission,
                'read'             => $read,
                'validate'         => static function ( $value ) { return sanitize_text_field( $value );
                },
                'invalidate'       => static function () { wp_cache_delete( 'notoptions', 'options' );
                },
                'write'            => static function ( $context, $value = null ) use ( $state, $domain ) {
                    if ( 'settings' === $domain ) {
                        return true;
                    }
                    // Settings use the native shared-option writer.
                    $id = is_object( $context ) ? ( $context->ID ?? $context->term_id ?? $context->id ?? 0 ) : 0;
                    update_option( $state->option . '_' . $domain . '_' . $id, $value, false );
                    return true;
                },
            ]
        );
    }
    $registry->register_field_adapter(
        [
            'owner'            => 'fixture',
            'domain'           => 'refund_providers',
            'key'              => 'fixture_gateway',
            'contract_version' => '1',
            'sensitive'        => false,
            'writable'         => false,
            'default'          => (object) [],
            'schema'           => $empty,
            'permission'       => $admin,
            'read'             => static function () { return [];
            },
            'validate'         => static function () { return true;
            },
            'idempotent'       => true,
            'dispatch'         => static function ( $id ) use ( $state ) {
                ++$state->dispatches;
                if ( $state->timeout ) {
                    throw new RuntimeException( 'Fake accepted-but-timeout' );
                }
                return [ 'state' => $state->settlement, 'reference' => $id ];
            },
            'reconcile'        => static function ( $id, $reference ) use ( $state ) { ++$state->reconciliations; return [ 'state' => $state->settlement, 'reference' => $reference ];
            },
        ]
    );
}

function directorist_mcp_register_fixture_adapters( $registry, $state ) {
    global $wpdb;
    $admin  = static function () { return current_user_can( 'manage_options' );
    };
    $schema = [ 'type' => 'object', 'properties' => [ 'label' => [ 'type' => 'string', 'maxLength' => 100 ] ], 'additionalProperties' => false ];
    $registry->register_field_adapter(
        [
            'owner'            => 'fixture',
            'domain'           => 'builder',
            'key'              => 'fixture_widget',
            'contract_version' => '1',
            'sensitive'        => false,
            'default'          => (object) [],
            'schema'           => $schema,
            'permission'       => $admin,
            'read'             => static function () { return [];
            },
            'validate'         => static function ( $changes ) { return $changes;
            },
            'writable'         => true,
            'storage_adapter'  => 'directorist',
            'tables'           => [ $wpdb->termmeta ],
            'write'            => static function () { return true;
            },
            'invalidate'       => static function () {
            },
        ]
    );
    $registry->register_field_adapter(
        [
            'owner'            => 'fixture',
            'domain'           => 'order_items',
            'key'              => 'fixture_item',
            'contract_version' => '1',
            'sensitive'        => false,
            'default'          => (object) [],
            'schema'           => [ 'type' => 'object', 'properties' => [ 'listing_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'listing_id' ], 'additionalProperties' => false ],
            'permission'       => $admin,
            'read'             => static function () { return [];
            },
            'validate'         => static function ( $item, $customer ) {
                $dto = ( new \Directorist\DTO\Order\DTO() )->set_user_id( $customer )->set_listing_id( $item['listing_id'] )->set_ref_type( 'fixture_item' )->set_amount( 5.0 )->set_sub_total( 5.0 )->set_currency( directorist_currency() )->set_status( 'pending' );
                return [ 'dto' => $dto, 'preview' => [ 'amount' => '5.00', 'currency' => directorist_currency(), 'status' => 'pending' ], 'state' => [ $customer, $item, '5.00' ] ];
            },
        ]
    );
}
