<?php
/** Local-only integration suite. No gateway calls, plugin activation, migration or live financial actions. */
use Directorist\MCP\Tools;
use Directorist\MCP\Registry;
use Directorist\MCP\Module;
use Directorist\MCP\Orders;
use Directorist\MCP\Support;
use Directorist\MCP\Money;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() ) {
    throw new RuntimeException( 'Run on a disposable local WordPress site using WP-CLI.' );
}

// Serialize fixture suites before reading the original site state.
global $wpdb;
$fixture_lock = 'directorist_mcp_tests_' . md5( DB_NAME . ':' . $wpdb->prefix );
if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $fixture_lock ) ) ) {
    throw new RuntimeException( 'Another Directorist MCP fixture suite is running. Run these suites sequentially.' );
}
register_shutdown_function(
    static function () use ( $wpdb, $fixture_lock ) {
        $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $fixture_lock ) );
    }
);

require_once dirname( __DIR__ ) . '/fixtures/mcp-extension.php';
$checks                = 0;
$assert                = static function ( $value, $message ) use ( &$checks ) { ++$checks; if ( ! $value ) {
        throw new RuntimeException( $message );
}
};
$ok                    = static function ( $value, $message = 'Operation' ) use ( $assert ) { $assert( ! is_wp_error( $value ), $message . ': ' . ( is_wp_error( $value ) ? $value->get_error_message() . ' ' . wp_json_encode( $value->get_error_data() ) : '' ) ); return $value;
};
$error                 = static function ( $value, $code ) use ( $assert ) { $assert( is_wp_error( $value ) && 'directorist_mcp_' . $code === $value->get_error_code(), 'Expected ' . $code . ': ' . wp_json_encode( $value ) );
};
$write                 = static function ( $tool, array $args, $key = null ) use ( $ok ) {
    $preview = $ok( Tools::execute( $tool, $args + [ 'dry_run' => true ] ), $tool . ' preview' );
    if ( isset( $preview['changes']['version'] ) ) {
        $args['expected_version'] = $preview['changes']['version'];
    }
    $args += [ 'confirm_token' => $preview['confirm_token'], 'idempotency_key' => $key ?: wp_generate_uuid4() ];
    return [ $ok( Tools::execute( $tool, $args ), $tool . ' execute' ), $args ];
};
$original_user         = get_current_user_id();
$saved_options         = get_option( 'atbdp_option' );
$saved_epoch           = get_option( 'directorist_mcp_epoch', null );
$saved_extension_epoch = get_option( 'directorist_mcp_extensions_epoch', null );
$saved_network_epoch   = get_site_option( 'directorist_mcp_extensions_epoch', null );
$saved_cron            = wp_next_scheduled( 'directorist_mcp_cleanup' );
$users                 = [];
$posts                 = [];
$order_ids             = [];
$terms                 = [];
$role                  = 'mcp_order_fixture_' . substr( wp_generate_uuid4(), 0, 8 );
$state                 = (object) [ 'active' => true, 'option' => 'directorist_mcp_contract_fixture_' . $role, 'dispatches' => 0, 'reconciliations' => 0, 'timeout' => false, 'external_dispatches' => 0, 'external_reconciliations' => 0, 'external_timeout' => false, 'settlement' => 'pending' ];
global $wpdb;
$option_names  = static function () use ( $wpdb ) { return $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '%directorist_mcp%' OR option_name LIKE '%mcp_order_fixture%'" );
};
$before_names  = $option_names();
$registration  = static function ( $registry ) use ( $state ) {
    directorist_mcp_register_fixture( $registry, $state );
    $registry->register_extension( [ 'owner' => 'invalid', 'api_version' => 99 ] );
    $registry->register_tool( [ 'owner' => 'fixture', 'action' => 'get-schedule' ] );
};
$suppress_mail = static function () { return true;
};
add_filter( 'pre_wp_mail', $suppress_mail );
add_action( 'directorist_mcp_register', $registration );
$property = new ReflectionProperty( Registry::class, 'instance' );
$property->setAccessible( true );
$previous_registry = $property->getValue();
$property->setValue( null, null );
try {
    $admins = get_users( [ 'role' => 'administrator', 'number' => 1 ] );
    $assert( (bool) $admins, 'An existing local administrator is required.' );
    $admin = $admins[0]->ID;
    wp_set_current_user( $admin );
    $options                           = (array) $saved_options;
    $options['mcp_enabled']            = true;
    $options['featured_listing_price'] = '10.00';
    update_option( 'atbdp_option', $options );
    $assert( Orders::ready() && Module::dependencies(), 'Native stores and MCP dependencies are required.' );
    add_role( $role, 'Disposable own-order customer', [ 'read' => true ] );
    foreach ( [ 'a', 'b', 'staff' ] as $name ) {
        $users[ $name ] = $ok( wp_insert_user( [ 'user_login' => $role . '_' . $name, 'user_pass' => wp_generate_password( 32 ), 'role' => $role ] ), 'Fixture user' );
    }
    $directory = directorist_get_default_directory();
    foreach ( [ 'a', 'b' ] as $name ) {
        $id             = $ok( wp_insert_post( [ 'post_type' => ATBDP_POST_TYPE, 'post_status' => 'pending', 'post_title' => 'MCP order fixture ' . $name, 'post_author' => $users[ $name ] ], true ), 'Fixture listing' );
        $posts[ $name ] = $id;
        wp_set_object_terms( $id, [ $directory ], ATBDP_DIRECTORY_TYPE );
        update_post_meta( $id, '_directory_type', $directory );
    }
    $catalog = \Directorist\MCP\Catalog::definitions();
    $assert( isset( $catalog['fixture-get-schedule'], $catalog['fixture-set-schedule'], $catalog['fixture-bulk-set-schedule'] ), 'Registered tools and explicit bulk must appear in the shared catalog.' );
    $context = $ok( Tools::execute( 'get-context' ) );
    $assert( in_array( 'orders', $context['groups'], true ) && in_array( 'Schedules', $context['groups'], true ), 'Dynamic groups include orders and extensions.' );
    $status = Module::status();
    $assert( $status['tools_count'] === count( $context['tools'] ), 'Drawer and discovery use the same registry.' );
    global $wp_filter;
    foreach ( [ 'wp_abilities_api_categories_init' => 'category', 'wp_abilities_api_init' => 'abilities' ] as $hook => $method ) {
        $original_hook = $wp_filter[ $hook ] ?? null;
        unset( $wp_filter[ $hook ] );
        add_action( $hook, [ Module::class, $method ] );
        try {
            do_action( $hook );
        } finally {
            unset( $wp_filter[ $hook ] ); if ( $original_hook ) {
                $wp_filter[ $hook ] = $original_hook;
            }
        }
    }
    $ability = wp_get_ability( 'directorist/fixture-set-schedule' );
    $assert( (bool) $ability, 'Extension abilities are registered through the same inventory.' );
    $ability_preview = $ok( $ability->execute( [ 'value' => 'initial', 'dry_run' => true ] ), 'Alternate ability preview schema' );
    $assert( ! empty( $ability_preview['confirm_token'] ), 'The ability output schema permits preview envelopes.' );
    $integrations = $ok( Tools::execute( 'list-mcp-extensions', [ 'diagnostics' => true ] ) );
    $assert( 2 === count( $integrations['registration_errors'] ), 'Invalid API and colliding tool are isolated.' );
    $assert( 'fixture' === $integrations['items'][0]['owner'], 'Owner/version diagnostics are available to administrators.' );
    $error( Tools::execute( 'fixture-invalid-reader' ), 'read_only_violation' );
    $assert( false === get_option( $state->option ), 'Hidden reads must not persist data.' );
    $error( Tools::execute( 'fixture-set-schedule', [ 'value' => 'blocked', 'dry_run' => true ] ), 'forbidden' );
    list( $saved, $saved_args ) = $write( 'fixture-set-schedule', [ 'value' => 'Monday' ] );
    $assert( 'Monday' === get_option( $state->option ) && $saved['committed'], 'Extension native mutation commits.' );
    $replay = $ok( Tools::execute( 'fixture-set-schedule', $saved_args ) );
    $assert( $replay['idempotent_replay'], 'Extension mutation replay is safe.' );
    $error( Tools::execute( 'fixture-set-schedule', array_replace( $saved_args, [ 'value' => 'Tuesday' ] ) ), 'idempotency_conflict' );
    $error( Tools::execute( 'fixture-set-schedule', [ 'value' => 'x', 'callback' => 'phpinfo', 'dry_run' => true ] ), 'validation_failed' );
    $preview = $ok( Tools::execute( 'fixture-set-schedule', [ 'value' => 'Wednesday', 'dry_run' => true ] ) );
    update_option( $state->option, 'changed elsewhere', false );
    $error( Tools::execute( 'fixture-set-schedule', [ 'value' => 'Wednesday', 'confirm_token' => $preview['confirm_token'], 'expected_version' => $preview['changes']['version'], 'idempotency_key' => wp_generate_uuid4() ] ), 'state_changed' );
    list( $extension_batch, $extension_batch_args ) = $write( 'fixture-bulk-set-schedule', [ 'items' => [ [ 'client_ref' => 'one', 'value' => 'Thursday', 'expected_version' => Support::hash( 'changed elsewhere' ) ], [ 'client_ref' => 'denied', 'value' => 'blocked', 'expected_version' => Support::hash( 'changed elsewhere' ) ] ] ] );
    $batch_replay                                   = $ok( Tools::execute( 'fixture-bulk-set-schedule', $extension_batch_args ) );
    $assert( $batch_replay['idempotent_replay'] && 2 === count( $batch_replay['results'] ), 'Extension batch replay preserves independent current projections.' );
    $assert( 'Thursday' === get_option( $state->option ), 'Extension bulk preserves per-item policy outcomes.' );
    $read_only = $ok( Tools::execute( 'discover-settings', [ 'keys' => [ 'fixture_view_only' ] ] ) );
    $assert( ! $read_only['items'][0]['writable'], 'A settings adapter can permit reads while independently refusing writes.' );
    $error( Tools::execute( 'update-settings', [ 'changes' => [ 'fixture_view_only' => 'forbidden' ], 'dry_run' => true ] ), 'sensitive_field' );
    $safe_schema = $ok( Tools::execute( 'discover-settings', [ 'keys' => [ 'fixture_summary' ] ] ) );
    $assert( ! array_intersect( [ 'internal_private', 'permission', 'invalidate' ], array_keys( $safe_schema['items'][0] ) ), 'Settings discovery projects only the public descriptor contract.' );
    $write( 'update-settings', [ 'changes' => [ 'fixture_summary' => 'registered settings' ] ] );
    $assert( 'registered settings' === $ok( Tools::execute( 'get-settings', [ 'keys' => [ 'fixture_summary' ] ] ) )['items'][0]['value'], 'Registry settings bridge preserves native option storage.' );
    $term    = $ok( wp_insert_term( $role, ATBDP_CATEGORY ), 'Fixture category' );
    $terms[] = $term['term_id'];
    $write( 'update-term', [ 'taxonomy' => ATBDP_CATEGORY, 'term_id' => $term['term_id'], 'changes' => [ 'description' => 'Fixture' ], 'extension_fields' => [ 'fixture_summary' => 'term value' ] ] );
    $assert( 'term value' === $ok( Tools::execute( 'get-term', [ 'taxonomy' => ATBDP_CATEGORY, 'term_id' => $term['term_id'] ] ) )['extension_fields']['fixture_summary'], 'Taxonomy adapter writes preserve host-domain checks.' );
    // Inject a native widget fixture, then manage it through the ordinary builder adapter.
    \Directorist\Multi_Directory\Multi_Directory_Manager::load_builder_data();
    $manager_fields                                                                                               = \Directorist\Multi_Directory\Multi_Directory_Manager::$fields;
    \Directorist\Multi_Directory\Multi_Directory_Manager::$fields['submission_form_fields']['widgets']['fixture'] = [ 'label' => 'Fixture', 'widgets' => [ 'fixture_widget' => [ 'label' => 'Fixture widget', 'type' => 'text', 'options' => [ 'label' => [ 'type' => 'text', 'value' => 'Fixture widget' ] ] ] ] ];
    $old_form                                                                                                     = get_term_meta( $directory, 'submission_form_fields', true );
    $had_form                                                                                                     = metadata_exists( 'term', $directory, 'submission_form_fields' );
    $builder_schema                                                                                               = $ok( Tools::execute( 'get-builder-schema', [ 'directory_id' => $directory, 'area' => 'submission_form' ] ) );
    $assert( $builder_schema['schemas']['submission_form_fields']['widgets']['fixture_widget']['writable'], 'Registered typed native widget is writable.' );
    $write( 'update-builder', [ 'directory_id' => $directory, 'area' => 'submission_form', 'operations' => [ [ 'action' => 'add', 'entity' => 'field', 'configuration' => 'submission_form_fields', 'id' => 'fixture_stable_field', 'widget' => 'fixture_widget', 'section_id' => $builder_schema['schemas']['submission_form_fields']['sections'][0]['id'], 'changes' => [ 'label' => 'Fixture field' ] ] ] ] );
    $form = get_term_meta( $directory, 'submission_form_fields', true );
    $assert( 'Fixture field' === $form['fields']['fixture_stable_field']['label'], 'Builder adapter preserves native serialization and stable identity.' );
    if ( $had_form ) {
        update_term_meta( $directory, 'submission_form_fields', $old_form );
    } else {
        delete_term_meta( $directory, 'submission_form_fields' );
    }
        \Directorist\Multi_Directory\Multi_Directory_Manager::$fields = $manager_fields;
        $field_preview                                                = $ok( Tools::execute( 'update-listing', [ 'listing_id' => $posts['a'], 'changes' => [], 'extension_fields' => [ 'fixture_summary' => 'safe value' ], 'dry_run' => true ] ) );
        $field_args                                                   = [ 'listing_id' => $posts['a'], 'changes' => [], 'extension_fields' => [ 'fixture_summary' => 'safe value' ], 'expected_version' => $field_preview['changes']['version'], 'confirm_token' => $field_preview['confirm_token'], 'idempotency_key' => wp_generate_uuid4() ];
        $ok( Tools::execute( 'update-listing', $field_args ) );
        $detail = $ok( Tools::execute( 'get-listing', [ 'listing_id' => $posts['a'] ] ) );
        $assert( 'safe value' === $detail['extension_fields']['fixture_summary'], 'Listing adapters have typed read/write projection.' );
        $error( Tools::execute( 'update-listing', [ 'listing_id' => $posts['a'], 'changes' => [], 'extension_fields' => [ 'unregistered' => 'x' ], 'dry_run' => true ] ), 'validation_failed' );

    list( $external, $external_args ) = $write( 'fixture-sync-schedule', [ 'value' => 'External Saturday' ] );
    $assert( $external['completed'] && 1 === $state->external_dispatches && 'External Saturday' === get_option( $state->option . '_external' ), 'Generic extension effects dispatch once and persist through a separate native transaction.' );
    $assert( $ok( Tools::execute( 'fixture-sync-schedule', $external_args ) )['idempotent_replay'] && 1 === $state->external_dispatches, 'Generic external retry returns the durable intent.' );
    $state->external_timeout = true;
    $ext_args                = [ 'value' => 'External Sunday' ];
    $ext_preview             = $ok( Tools::execute( 'fixture-sync-schedule', $ext_args + [ 'dry_run' => true ] ) );
    $ext_args               += [ 'expected_version' => $ext_preview['changes']['version'], 'confirm_token' => $ext_preview['confirm_token'], 'idempotency_key' => wp_generate_uuid4() ];
    $ext_unknown             = Tools::execute( 'fixture-sync-schedule', $ext_args );
    $error( $ext_unknown, 'outcome_unknown' );
    $ext_id   = $ext_unknown->get_error_data()['operation_id'];
    $ext_read = $ok( Tools::execute( 'fixture-sync-schedule-operation', [ 'operation_id' => $ext_id ] ) );
    $assert( 'settled' === $ext_read['external_effect'] && ! $ext_read['completed'] && 2 === $state->external_dispatches && 1 === $state->external_reconciliations && 'External Saturday' === get_option( $state->option . '_external' ), 'Generic operation reads never re-dispatch or rerun the native writer.' );
    $state->active = false;
    $tombstone     = $ok( Tools::execute( 'list-mcp-extensions', [ 'operation_id' => $ext_id ] ) );
    $assert( 'provider_unavailable' === $tombstone['state'] && ! isset( $tombstone['result'] ), 'Core diagnostic lookup retains a safe initiating-user tombstone after deactivation.' );
    $state->active = true;
    update_option( $state->option . '_external', 'External Sunday', false );
    $completed_external = \Directorist\MCP\Extension_Operations::complete( $ext_id, [ 'value' => 'External Sunday' ] );
    $assert( $completed_external['completed'] && 2 === $state->external_dispatches, 'Native result verification completes the existing extension operation without redispatch.' );

        $preview       = $ok( Tools::execute( 'fixture-set-schedule', [ 'value' => 'Friday', 'dry_run' => true ] ) );
        $state->active = false;
        $error( Tools::execute( 'fixture-get-schedule' ), 'extension_unavailable' );
        $assert( ! in_array( 'directorist-fixture-get-schedule', $ok( Tools::execute( 'get-context' ) )['tools'], true ), 'Unavailable tools disappear from discovery.' );
        Registry::lifecycle();
        $state->active = true;
        $error( Tools::execute( 'fixture-set-schedule', [ 'value' => 'Friday', 'expected_version' => $preview['changes']['version'], 'confirm_token' => $preview['confirm_token'], 'idempotency_key' => wp_generate_uuid4() ] ), 'confirmation_invalid' );
        // Create all orders before any purchase fulfills the shared fixture listing.
    foreach ( [ 'first' => 'a', 'second' => 'b', 'gateway' => 'a', 'settled' => 'a', 'cancel' => 'a' ] as $label => $customer ) {
        list( $result )      = $write( 'create-order', [ 'customer_id' => $users[ $customer ], 'item_type' => 'featured_listing', 'item' => [ 'listing_id' => $posts[ $customer ] ] ] );
        $order_ids[ $label ] = $result['order_id'];
        $assert( 'pending' === Orders::get( $result['order_id'] )->status, 'Creation remains unpaid.' );
    }
        list( $extension_order ) = $write( 'create-order', [ 'customer_id' => $users['b'], 'item_type' => 'fixture_item', 'item' => [ 'listing_id' => $posts['b'] ], 'extension_fields' => [ 'fixture_summary' => 'order value' ] ] );
        $order_ids['extension']  = $extension_order['order_id'];
        $assert( 'order value' === $ok( Tools::execute( 'get-order', [ 'order_id' => $extension_order['order_id'] ] ) )['extension_fields']['fixture_summary'], 'Registered item and order field adapters join native persistence.' );
        $write( 'update-order', [ 'order_id' => $order_ids['first'], 'changes' => [ 'recalculate' => true ] ] );
    $wpdb->update( Orders::tables()[0], [ 'legacy_id' => 2147480000 ], [ 'id' => $order_ids['first'] ] );
    $mapped = $ok( Tools::execute( 'get-order', [ 'legacy_id' => 2147480000 ] ) );
    $assert( $mapped['order_id'] === $order_ids['first'], 'Explicit migrated legacy IDs resolve one authoritative order.' );
    $error( Tools::execute( 'get-order', [ 'legacy_id' => 2147480000, 'order_id' => $order_ids['first'] ] ), 'validation_failed' );
        $first = $ok( Tools::execute( 'get-order', [ 'order_id' => $order_ids['first'] ] ) );
        $assert( '10.00' === $first['total'], 'Native totals use decimal-string output.' );
        $error( Tools::execute( 'create-order', [ 'customer_id' => $users['a'], 'item_type' => 'featured_listing', 'item' => [ 'listing_id' => $posts['b'] ], 'dry_run' => true ] ), 'validation_failed' );
        $error( Tools::execute( 'update-order', [ 'order_id' => $order_ids['first'], 'changes' => [ 'customer_id' => $users['b'] ], 'dry_run' => true ] ), 'validation_failed' );
        $error( Tools::execute( 'change-order-status', [ 'order_id' => $order_ids['first'], 'status' => 'paid', 'dry_run' => true ] ), 'dependency_blocked' );
        wp_set_current_user( $users['a'] );
        $own = $ok( Tools::execute( 'list-orders' ) );
        $assert( 4 === $own['total'], 'Customer totals exclude other purchasers before pagination.' );
        $error( Tools::execute( 'get-order', [ 'order_id' => $order_ids['second'] ] ), 'not_found' );
        $error( Tools::execute( 'list-order-refunds', [ 'order_id' => $order_ids['second'] ] ), 'not_found' );
        $error( Tools::execute( 'list-orders', [ 'customer_id' => $users['b'] ] ), 'forbidden' );
        $error( Tools::execute( 'list-listings' ), 'forbidden' );
        $error( Tools::execute( 'get-reference-data', [ 'kind' => 'directories' ] ), 'forbidden' );
        $error( Tools::execute( 'get-settings', [ 'keys' => [ 'featured_listing_price' ] ] ), 'forbidden' );
        $error( Tools::execute( 'fixture-set-schedule', [ 'value' => 'no', 'dry_run' => true ] ), 'forbidden' );
        $error( Tools::execute( 'list-mcp-extensions', [ 'diagnostics' => true ] ), 'forbidden' );
        list( $cancelled ) = $write( 'cancel-order', [ 'order_id' => $order_ids['cancel'] ] );
        $assert( $cancelled['completed'] && 'cancelled' === Orders::get( $order_ids['cancel'] )->status, 'Purchaser can cancel an eligible pending order.' );
        $ok( Tools::execute( 'get-order-operation', [ 'operation_id' => $cancelled['operation_id'] ] ) );
        wp_set_current_user( $users['b'] );
        $error( Tools::execute( 'get-order-operation', [ 'operation_id' => $cancelled['operation_id'] ] ), 'not_found' );
        wp_set_current_user( $admin );
        $error( Tools::execute( 'list-orders', [ 'per_page' => 101 ] ), 'validation_failed' );
        $error( Tools::execute( 'record-order-payment', [ 'order_id' => $order_ids['first'], 'method' => 'bank_transfer', 'amount' => '10.001', 'currency' => 'USD', 'evidence_reference' => $role, 'dry_run' => true ] ), 'validation_failed' );
    foreach ( [ 'first', 'gateway', 'settled' ] as $label ) {
        list( $receipt, $receipt_args ) = $write( 'record-order-payment', [ 'order_id' => $order_ids[ $label ], 'method' => 'bank_transfer', 'amount' => '10.00', 'currency' => directorist_currency(), 'evidence_reference' => $role . '-' . $label ] );
        $assert( $receipt['completed'] && 'paid' === Orders::get( $order_ids[ $label ] )->status, 'Offline receipt uses native settlement/fulfillment.' );
        $assert( '1' === get_post_meta( $posts['a'], '_featured', true ), 'Native fulfillment updates the fixture listing.' );
        $assert( $ok( Tools::execute( 'record-order-payment', $receipt_args ) )['idempotent_replay'], 'Financial retry returns one durable receipt.' );
    }
    $off = $options; $off['enable_monetization'] = false; update_option( 'atbdp_option', $off );
    $assert( $ok( Tools::execute( 'list-orders', [ 'ids' => array_values( $order_ids ) ] ) )['total'] === count( $order_ids ), 'Historical orders remain readable when monetization is off.' );
    $assert( [] === $ok( Tools::execute( 'get-order-schema' ) )['offline_methods'], 'Disabled monetization removes new offline payment actions from discovery.' );
    $error( Tools::execute( 'create-order', [ 'customer_id' => $users['a'], 'item_type' => 'featured_listing', 'item' => [ 'listing_id' => $posts['a'] ], 'dry_run' => true ] ), 'dependency_unavailable' );
    update_option( 'atbdp_option', $options );
        $payments   = $ok( Tools::execute( 'list-order-payments', [ 'order_id' => $order_ids['first'] ] ) );
        $payment_id = $payments['items'][0]['id'];
        $assert( ! isset( $payments['items'][0]['transaction_id'] ), 'History redacts transaction evidence.' );
        $error( Tools::execute( 'create-order-refund', [ 'order_id' => $order_ids['first'], 'payment_id' => $payment_id, 'amount' => '11.00', 'currency' => directorist_currency(), 'reason' => 'test', 'mode' => 'record_only', 'evidence_reference' => $role . '-refund', 'dry_run' => true ] ), 'dependency_blocked' );
        list( $refund, $refund_args ) = $write( 'create-order-refund', [ 'order_id' => $order_ids['first'], 'payment_id' => $payment_id, 'amount' => '4.00', 'currency' => directorist_currency(), 'reason' => 'Fixture manual reimbursement', 'mode' => 'record_only', 'evidence_reference' => $role . '-refund' ] );
        $assert( $refund['completed'] && false === $refund['result']['gateway_refund_performed'] && 0 === $state->dispatches, 'Record-only refund never claims or dispatches a gateway refund.' );
        $ordinary_key    = '_directorist_mcp_op_' . Support::hash( [ get_current_blog_id(), get_current_user_id(), 'create-order-refund', $refund_args['idempotency_key'] ] );
    $ordinary            = get_option( $ordinary_key );
    $ordinary['expires'] = time() - 1; update_option( $ordinary_key, $ordinary, false );
    $durable = $ok( Tools::execute( 'create-order-refund', $refund_args ) );
    $assert( $durable['operation_id'] === $refund['operation_id'] && $durable['idempotent_replay'], 'Financial deduplication survives the ordinary replay window.' );
    $balance = $ok( Tools::execute( 'list-order-refunds', [ 'order_id' => $order_ids['first'] ] ) )['balance'];
        $assert( '4.00' === $balance['settled'] && '6.00' === $balance['refundable'], 'Partial refund balances are exact.' );
        $settled_payment        = $ok( Tools::execute( 'list-order-payments', [ 'order_id' => $order_ids['settled'] ] ) )['items'][0]['id'];
        $state->settlement      = 'settled';
        list( $settled_refund ) = $write( 'create-order-refund', [ 'order_id' => $order_ids['settled'], 'payment_id' => $settled_payment, 'amount' => '10.00', 'currency' => directorist_currency(), 'reason' => 'Fake successful gateway refund', 'mode' => 'gateway', 'provider' => 'fixture_gateway' ] );
        $assert( $settled_refund['completed'] && true === $settled_refund['result']['gateway_refund_performed'] && 'refunded' === Orders::get( $order_ids['settled'] )->status, 'Confirmed fake provider settlement records one full native refund.' );
        $dispatch_baseline = $state->dispatches;
        $gateway_payment   = $ok( Tools::execute( 'list-order-payments', [ 'order_id' => $order_ids['gateway'] ] ) )['items'][0]['id'];
        $state->timeout    = true;
        $unknown_args      = [ 'order_id' => $order_ids['gateway'], 'payment_id' => $gateway_payment, 'amount' => '6.00', 'currency' => directorist_currency(), 'reason' => 'Fake provider timeout', 'mode' => 'gateway', 'provider' => 'fixture_gateway' ];
    $unknown_preview       = $ok( Tools::execute( 'create-order-refund', $unknown_args + [ 'dry_run' => true ] ) );
    $unknown_args         += [ 'expected_version' => $unknown_preview['changes']['version'], 'confirm_token' => $unknown_preview['confirm_token'], 'idempotency_key' => wp_generate_uuid4() ];
    $unknown_result        = Tools::execute( 'create-order-refund', $unknown_args );
    $error( $unknown_result, 'outcome_unknown' );
    $unknown = $unknown_result->get_error_data();
    $assert( 'unknown' === $unknown['state'] && $dispatch_baseline + 1 === $state->dispatches, 'Accepted-then-timeout has an uncertain durable outcome.' );
        $retry_unknown = Tools::execute( 'create-order-refund', $unknown_args );
    $error( $retry_unknown, 'outcome_unknown' );
    $assert( $retry_unknown->get_error_data()['idempotent_replay'] && $dispatch_baseline + 1 === $state->dispatches, 'Timeout retry never submits a second refund.' );
        $balance = $ok( Tools::execute( 'list-order-refunds', [ 'order_id' => $order_ids['gateway'] ] ) )['balance'];
        $assert( '6.00' === $balance['reserved'] && '4.00' === $balance['refundable'], 'Unknown refunds reserve the unsettled balance.' );
        $state->settlement = 'settled';
        $op                = $ok( Tools::execute( 'get-order-operation', [ 'operation_id' => $unknown['operation_id'] ] ) );
        $assert( 'settled' === $op['external_effect'] && false === $op['completed'] && $dispatch_baseline + 1 === $state->dispatches && 1 === $state->reconciliations, 'Read reconciliation reports provider settlement without replaying native writes.' );
        $state->active = false;
        $op            = $ok( Tools::execute( 'get-order-operation', [ 'operation_id' => $unknown['operation_id'] ] ) );
        $assert( $op['provider_unavailable'], 'Deactivated providers retain a safe operation reference.' );
        $state->active = true;
        $native_refund = ( new \Directorist\Repositories\RefundRepository() )->create( ( new \Directorist\DTO\Refund\DTO() )->set_order_id( $order_ids['gateway'] )->set_amount( 6.0 )->set_status( 'paid' )->set_reason( 'Verified fixture native completion' ) );
    $completed_refund  = \Directorist\MCP\Order_Operations::complete_refund( $unknown['operation_id'], $native_refund );
    $assert( $completed_refund['completed'] && $dispatch_baseline + 1 === $state->dispatches, 'Verified native refund proof completes the original financial operation without dispatch.' );
    $assert( $ok( Tools::execute( 'create-order-refund', $unknown_args ) )['completed'], 'Durable reconciled state takes precedence over a stale ordinary retry snapshot.' );
    $balance = $ok( Tools::execute( 'list-order-refunds', [ 'order_id' => $order_ids['gateway'] ] ) )['balance'];
    $assert( '0.00' === $balance['reserved'] && '6.00' === $balance['settled'], 'Native reconciliation releases the original pending reservation.' );
    // A custom role gets staff access from effective capabilities, then loses it immediately on revocation.
        $staff = new WP_User( $users['staff'] );
        $staff->add_cap( 'manage_options' );
        wp_set_current_user( $staff->ID );
        $assert( count( $order_ids ) === $ok( Tools::execute( 'list-orders', [ 'scope' => 'management', 'ids' => array_values( $order_ids ) ] ) )['total'], 'Custom staff role uses native effective capability.' );
        $staff->remove_cap( 'manage_options' );
        wp_set_current_user( 0 ); wp_set_current_user( $staff->ID );
        $error( Tools::execute( 'list-orders', [ 'scope' => 'management' ] ), 'forbidden' );
        wp_set_current_user( $admin );
        $second        = $ok( Tools::execute( 'get-order', [ 'order_id' => $order_ids['second'] ] ) );
        list( $batch ) = $write( 'bulk-orders', [ 'action' => 'cancel', 'items' => [ [ 'client_ref' => 'eligible', 'order_id' => $order_ids['second'], 'expected_version' => $second['version'] ], [ 'client_ref' => 'missing', 'order_id' => 999999999 ] ] ] );
        $assert( 1 === $batch['summary']['succeeded'] && 1 === $batch['summary']['failed'], 'Mixed order batch reports independent outcomes.' );
        $error( Tools::execute( 'bulk-orders', [ 'action' => 'refund', 'items' => [], 'dry_run' => true ] ), 'validation_failed' );
        $error( Tools::execute( 'bulk-orders', [ 'action' => 'cancel', 'items' => array_fill( 0, 51, [ 'client_ref' => 'x' ] ), 'dry_run' => true ] ), 'validation_failed' );
        $options['mcp_enabled'] = false; update_option( 'atbdp_option', $options );
        $error( Tools::execute( 'fixture-get-schedule' ), 'mcp_disabled' );
        $error( Tools::execute( 'list-orders' ), 'mcp_disabled' );
        $assert( is_wp_error( $ability->execute( [ 'value' => 'no', 'dry_run' => true ] ) ), 'Alternate ability execution rejects disabled MCP.' );
        $assert( Money::minor( '1.234', 'KWD' ) === 1234 && Money::minor( '42', 'JPY' ) === 42, 'Exact arithmetic supports currency-specific precision.' );
        WP_CLI::success( 'Directorist MCP orders/extensions: ' . $checks . ' integration assertions passed.' );
} finally {
    wp_set_current_user( $admins[0]->ID ?? $original_user );
    if ( isset( $manager_fields ) ) {
        \Directorist\Multi_Directory\Multi_Directory_Manager::$fields = $manager_fields;
    }
    if ( isset( $old_form ) ) {
        if ( $had_form ) {
            update_term_meta( $directory, 'submission_form_fields', $old_form );
        } else {
            delete_term_meta( $directory, 'submission_form_fields' );
        }
    }
    foreach ( $order_ids as $id ) {
        $wpdb->delete( Orders::tables()[2], [ 'order_id' => $id ] );
        $wpdb->delete( Orders::tables()[1], [ 'order_id' => $id ] );
        $wpdb->delete( Orders::tables()[0], [ 'id' => $id ] );
    }
    foreach ( $posts as $id ) {
        wp_delete_post( $id, true );
    }
    foreach ( $terms as $term ) {
        wp_delete_term( $term, ATBDP_CATEGORY );
    }
    foreach ( $users as $id ) {
        wp_delete_user( $id );
    }
    remove_role( $role );
    remove_action( 'directorist_mcp_register', $registration );
    remove_filter( 'pre_wp_mail', $suppress_mail );
    $property->setValue( null, $previous_registry );
    update_option( 'atbdp_option', $saved_options );
    foreach ( [ 'directorist_mcp_epoch' => $saved_epoch, 'directorist_mcp_extensions_epoch' => $saved_extension_epoch ] as $key => $value ) {
        if ( null === $value ) {
            delete_option( $key );
        } else {
            update_option( $key, $value, false );
        }
    }
    if ( null === $saved_network_epoch ) {
        delete_site_option( 'directorist_mcp_extensions_epoch' );
    } else {
        update_site_option( 'directorist_mcp_extensions_epoch', $saved_network_epoch );
    }
    if ( ! $saved_cron ) {
        wp_clear_scheduled_hook( 'directorist_mcp_cleanup' );
    }
    foreach ( array_diff( $option_names(), $before_names ) as $name ) {
        delete_option( $name );
    }
        wp_set_current_user( $original_user );
        \Directorist\MCP\Write_Guard::unlock();
}
