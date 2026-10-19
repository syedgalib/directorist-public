<?php
/**
* Disposable local integration tests. Run with wp eval-file on a test site only.
* Restores settings and deletes only fixtures/operational records created here.
*/
use Directorist\MCP\Tools;
use Directorist\MCP\Catalog;
use Directorist\MCP\Module;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() ) {
    throw new RuntimeException( 'These tests require WP-CLI on a local test site.' );
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

$checks        = 0;
$assert        = static function ( $condition, $message ) use ( &$checks ) {
    ++$checks;
    if ( ! $condition ) {
        throw new RuntimeException( $message );
    }
};
$ok            = static function ( $result, $label ) use ( $assert ) {
    $assert( ! is_wp_error( $result ), $label . ': ' . ( is_wp_error( $result ) ? $result->get_error_code() . ' ' . $result->get_error_message() . ' ' . wp_json_encode( $result->get_error_data() ) : '' ) );
    return $result;
};
$error         = static function ( $result, $code ) use ( $assert ) {
    $assert( is_wp_error( $result ) && 'directorist_mcp_' . $code === $result->get_error_code(), 'Expected ' . $code . ', received ' . wp_json_encode( $result ) );
};
$write         = static function ( $tool, $args ) use ( $ok ) {
    $preview = $ok( Tools::execute( $tool, $args + [ 'dry_run' => true ] ), $tool . ' preview' );
    if ( isset( $preview['changes']['version'] ) ) {
        $args['expected_version'] = $preview['changes']['version'];
    }
    return $ok( Tools::execute( $tool, $args + [ 'confirm_token' => $preview['confirm_token'], 'idempotency_key' => wp_generate_uuid4() ] ), $tool . ' execute' );
};
$saved_options = get_option( 'atbdp_option' );
$saved_epoch   = get_option( 'directorist_mcp_epoch', null );
$saved_cron    = wp_next_scheduled( 'directorist_mcp_cleanup' );
$saved_default = directorist_get_default_directory();
$default_meta  = [];
foreach ( get_terms( [ 'taxonomy' => ATBDP_DIRECTORY_TYPE, 'hide_empty' => false, 'fields' => 'ids' ] ) as $existing_id ) {
    $default_meta[ $existing_id ] = get_term_meta( $existing_id, '_default', false );
}
$original_user = get_current_user_id();
$users         = [];
$posts         = [];
$terms         = [];
$directories   = [];
$files         = [];
$suffix        = substr( wp_generate_uuid4(), 0, 8 );
global $wpdb;
$operational_names = static function () use ( $wpdb ) {
    return $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '%directorist_mcp_op_%' OR option_name LIKE '%directorist_mcp_preview_%'" );
};
$before_names      = $operational_names();
$suppress_mail     = static function () {
    return true;
};
add_filter( 'pre_wp_mail', $suppress_mail );

try {
    $admins = get_users( [ 'role' => 'administrator', 'number' => 1 ] );
    $assert( (bool) $admins, 'An existing test-site administrator is required.' );
    $admin = $admins[0]->ID;
    wp_set_current_user( $admin );
    $options                = (array) $saved_options;
    $options['mcp_enabled'] = false;
    update_option( 'atbdp_option', $options );
    foreach ( Catalog::definitions() as $name => $definition ) {
        if ( ! $definition['schema']['required'] ) {
            $error( Tools::execute( $name, [] ), 'mcp_disabled' );
        }
    }
    $options['mcp_enabled']            = true;
    $options['enable_multi_directory'] = true;
    update_option( 'atbdp_option', $options );
    $assert( Module::enabled() && Module::dependencies(), 'MCP dependencies must be ready.' );
    $context = $ok( Tools::execute( 'get-context' ), 'context' );
    $assert( 53 === count( $context['tools'] ), 'Administrator should discover 53 core tools.' );
    // A normally disabled site may already have initialized its ability registry.
    // Replay only this module's registration hooks in this disposable CLI process.
    if ( ! wp_get_ability( 'directorist/get-context' ) ) {
        global $wp_filter;
        foreach ( [ 'wp_abilities_api_categories_init' => 'category', 'wp_abilities_api_init' => 'abilities' ] as $hook => $method ) {
            $original_hook = $wp_filter[ $hook ] ?? null;
            unset( $wp_filter[ $hook ] );
            add_action( $hook, [ Module::class, $method ] );
            try {
                do_action( $hook );
            } finally {
                unset( $wp_filter[ $hook ] );
                if ( $original_hook ) {
                    $wp_filter[ $hook ] = $original_hook;
                }
            }
        }
    }
    $ability = wp_get_ability( 'directorist/get-context' );
    $assert( $ability && ! is_wp_error( $ability->execute( [] ) ), 'The alternate Abilities API path should use the same handler.' );
    $error( Tools::execute( 'get-context', [ 'unexpected' => true ] ), 'validation_failed' );
    $error( Tools::execute( 'list-listings', [ 'per_page' => 101 ] ), 'validation_failed' );
    $error( Tools::execute( 'bulk-listings', [ 'action' => 'create', 'items' => array_fill( 0, 51, [ 'client_ref' => 'too-many' ] ), 'dry_run' => true ] ), 'validation_failed' );
    $settings = $ok( Tools::execute( 'discover-settings', [ 'keys' => [ 'all_listing_page_items', 'map_api_key' ] ] ), 'settings discovery' );
    $assert( 2 === count( $settings['items'] ), 'Selected settings should be returned.' );
    $error( Tools::execute( 'get-settings', [ 'keys' => [ 'map_api_key' ] ] ), 'sensitive_field' );
    $setting_preview = $ok( Tools::execute( 'update-settings', [ 'changes' => [ 'all_listing_page_items' => 24 ], 'dry_run' => true ] ), 'settings preview' );
    $assert( get_directorist_option( 'all_listing_page_items' ) !== 24, 'Preview must not save.' );
    $result = $write( 'update-settings', [ 'changes' => [ 'all_listing_page_items' => 24 ] ] );
    $assert( 24 === get_directorist_option( 'all_listing_page_items' ), 'Targeted settings update should persist.' );
    $assert( get_directorist_option( 'map_api_key' ) === ( $saved_options['map_api_key'] ?? false ), 'Secret sibling settings must remain unchanged.' );
    $error( Tools::execute( 'update-settings', [ 'changes' => [ 'mcp_enabled' => false ], 'dry_run' => true ] ), 'sensitive_field' );
    $write( 'reset-settings', [ 'keys' => [ 'all_listing_page_items' ] ] );
    $before_invalid = get_option( 'atbdp_option' );
    $error( Tools::execute( 'update-settings', [ 'changes' => [ 'all_listing_page_items' => 17, 'mcp_enabled' => true ], 'dry_run' => true ] ), 'sensitive_field' );
    $assert( $before_invalid === get_option( 'atbdp_option' ), 'An invalid settings unit must save no keys.' );
    $extension_key    = 'directorist_mcp_fixture_store_' . $suffix;
    $extension_writer = static function ( $values ) use ( $extension_key ) {
        update_option( $extension_key, $values, false );
        return true;
    };
    $extension_filter = static function ( $registry ) use ( $extension_key, $extension_writer ) {
        $registry['fixture_setting'] = [
            'owner'           => 'mcp-fixture',
            'schema'          => [ 'type' => 'string', 'maxLength' => 30 ],
            'default'         => 'default',
            'capability'      => 'manage_options',
            'read'            => static function () use ( $extension_key ) {
                return get_option( $extension_key, [] )['fixture_setting'] ?? 'default';
            },
            'validate'        => static function ( $v ) {
                return sanitize_text_field( $v );
            },
            'write'           => $extension_writer,
            'storage_adapter' => 'fixture',
            'sensitive'       => false,
            'resettable'      => true
        ];
        return $registry;
    };
    add_filter( 'directorist_mcp_settings', $extension_filter );
    $write( 'update-settings', [ 'changes' => [ 'fixture_setting' => 'registered' ] ] );
    $assert( 'registered' === get_option( $extension_key )['fixture_setting'], 'Registered extension writer should receive the complete unit.' );
    $extension_preview = $ok( Tools::execute( 'update-settings', [ 'changes' => [ 'fixture_setting' => 'inactive' ], 'dry_run' => true ] ), 'extension preview' );
    remove_filter( 'directorist_mcp_settings', $extension_filter );
    $error( Tools::execute( 'update-settings', [ 'changes' => [ 'fixture_setting' => 'inactive' ], 'confirm_token' => $extension_preview['confirm_token'], 'idempotency_key' => wp_generate_uuid4() ] ), 'sensitive_field' );
    $assert( 'registered' === get_option( $extension_key )['fixture_setting'], 'Deactivating an extension must invalidate pending writes.' );
    try {
        \Directorist\MCP\Write_Guard::atomic(
            static function () use ( $extension_key ) {
                update_option( $extension_key, [ 'fixture_setting' => 'rolled back' ], false );
                throw new RuntimeException( 'Fixture rollback' );
            }
        );
    } catch ( RuntimeException $expected ) {
    }
    $assert( 'registered' === get_option( $extension_key )['fixture_setting'], 'Rollback must restore database values and option caches.' );

    $new           = $write( 'create-directory', [ 'name' => 'MCP fixture ' . $suffix, 'slug' => 'mcp-fixture-' . $suffix ] );
    $dir           = $new['directory_id'];
    $directories[] = $dir;
    $directory     = $ok( Tools::execute( 'get-directory', [ 'directory_id' => $dir ] ), 'directory detail' );
    $assert( ! $directory['is_default'], 'Creation must not switch the default directory.' );
    $write( 'set-default-directory', [ 'directory_id' => $dir ] );
    $assert( $dir === (int) directorist_get_default_directory(), 'Selecting a default must update native readers.' );
    $error( Tools::execute( 'delete-directory', [ 'directory_id' => $dir, 'dry_run' => true ] ), 'dependency_blocked' );
    $write( 'set-default-directory', [ 'directory_id' => (int) $saved_default ] );
    $builder = $ok( Tools::execute( 'get-builder-schema', [ 'directory_id' => $dir, 'area' => 'submission_form' ] ), 'builder schema' );
    $assert( isset( $builder['schemas']['submission_form_fields']['widgets']['title'] ), 'Title widget schema should be discoverable.' );
    $section = $builder['schemas']['submission_form_fields']['sections'][0]['id'];
    $write(
        'update-builder', [
            'directory_id' => $dir,
            'area'         => 'submission_form',
            'operations'   => [
                [ 'configuration' => 'submission_form_fields', 'entity' => 'field', 'action' => 'add', 'id' => 'mcp_extra', 'widget' => 'text', 'section_id' => $section, 'changes' => [ 'label' => 'MCP extra', 'required' => false ] ],
                [ 'configuration' => 'submission_form_fields', 'entity' => 'field', 'action' => 'add', 'id' => 'category', 'widget' => 'category', 'section_id' => $section, 'changes' => [ 'required' => false ] ],
                [ 'configuration' => 'submission_form_fields', 'entity' => 'field', 'action' => 'add', 'id' => 'image_upload', 'widget' => 'image_upload', 'section_id' => $section, 'changes' => [ 'required' => false ] ],
                [ 'configuration' => 'submission_form_fields', 'entity' => 'field', 'action' => 'add', 'id' => 'mcp_file', 'widget' => 'file', 'section_id' => $section, 'changes' => [ 'required' => false, 'file_type' => 'document' ] ],
            ]
        ]
    );
    $assert( isset( get_term_meta( $dir, 'submission_form_fields', true )['fields']['mcp_extra'] ), 'Builder field should persist.' );
    $error( Tools::execute( 'update-builder', [ 'directory_id' => $dir, 'area' => 'submission_form', 'operations' => [ [ 'configuration' => 'submission_form_fields', 'entity' => 'field', 'action' => 'update', 'id' => 'title', 'changes' => [ 'required' => false ] ] ], 'dry_run' => true ] ), 'validation_failed' );
    $error( Tools::execute( 'update-builder', [ 'directory_id' => $dir, 'area' => 'submission_form', 'operations' => [ [ 'configuration' => 'submission_form_fields', 'entity' => 'field', 'action' => 'update', 'id' => 'mcp_extra', 'changes' => [ 'conditional_logic' => [ 'enabled' => true, 'action' => 'show', 'groups' => [ [ 'conditions' => [ [ 'field' => 'mcp_extra', 'operator' => 'is', 'value' => 'cycle' ] ] ] ] ] ] ] ], 'dry_run' => true ] ), 'validation_failed' );
    $cards = $ok( Tools::execute( 'get-builder-schema', [ 'directory_id' => $dir, 'area' => 'listing_cards' ] ), 'card schema' );
    $grid  = $cards['configuration']['listings_card_grid_view'];
    $assert( isset( $grid['template_data'][ $grid['active_template'] ]['body']['top'][0]['widget_key'] ), 'New directories need populated native card records.' );
    $card_slot = [ 'template_data', $grid['active_template'], 'body', 'top' ];
    $write( 'update-builder', [ 'directory_id' => $dir, 'area' => 'listing_cards', 'operations' => [ [ 'configuration' => 'listings_card_grid_view', 'entity' => 'widget', 'action' => 'reorder', 'slot' => $card_slot, 'order' => [ 'rating', 'listing_title' ] ] ] ] );
    $archive = directorist_listing_archive_fields( $dir );
    $assert( 'rating' === $archive['card_fields']['template_data'][ $grid['active_template'] ]['body']['top'][0]['widget_name'], 'Frontend readers must receive reordered cards in native format.' );
    $error( Tools::execute( 'update-builder', [ 'directory_id' => $dir, 'area' => 'listing_cards', 'operations' => [ [ 'configuration' => 'listings_card_grid_view', 'entity' => 'widget', 'action' => 'add', 'widget' => 'email', 'slot' => $card_slot ] ], 'dry_run' => true ] ), 'validation_failed' );
    $error( Tools::execute( 'update-builder', [ 'directory_id' => $dir, 'area' => 'listing_cards', 'operations' => [ [ 'configuration' => 'listings_card_grid_view', 'entity' => 'widget', 'action' => 'add', 'widget' => 'user_avatar', 'id' => 'extra-avatar', 'slot' => [ 'template_data', $grid['active_template'], 'thumbnail', 'avatar' ] ] ], 'dry_run' => true ] ), 'validation_failed' );
    $write( 'update-builder', [ 'directory_id' => $dir, 'area' => 'listing_cards', 'operations' => [ [ 'configuration' => 'listings_card_grid_view', 'entity' => 'template', 'action' => 'update', 'template' => 'grid_view_without_thumbnail' ] ] ] );
    $assert( 'grid_view_without_thumbnail' === get_term_meta( $dir, 'listings_card_grid_view', true )['active_template'], 'Template switch should persist.' );
    $write( 'update-builder', [ 'directory_id' => $dir, 'area' => 'single_listing', 'operations' => [ [ 'configuration' => 'single_listing_header', 'entity' => 'widget', 'action' => 'update', 'id' => 'back', 'slot' => [ 0, 'placeholders', 0, 'selectedWidgets' ], 'changes' => [ 'label' => 'Back to directory' ] ] ] ] );
    $header = get_term_meta( $dir, 'single_listing_header', true );
    $assert( 'Back to directory' === $header[0]['placeholders'][0]['selectedWidgets'][0]['label'] && [ 'back' ] === $header[0]['placeholders'][0]['selectedWidgetList'], 'Header must retain synchronized native widget lists.' );
    $search         = $ok( Tools::execute( 'get-builder-schema', [ 'directory_id' => $dir, 'area' => 'search_form' ] ), 'search schema' );
    $search_section = $search['schemas']['search_form_fields']['sections'][0]['id'];
    $search_op      = [ 'configuration' => 'search_form_fields', 'entity' => 'field', 'action' => 'add', 'id' => 'extra-search', 'widget' => 'text', 'section_id' => $search_section, 'changes' => [ 'original_widget_key' => 'not-a-field' ] ];
    $error( Tools::execute( 'update-builder', [ 'directory_id' => $dir, 'area' => 'search_form', 'operations' => [ $search_op ], 'dry_run' => true ] ), 'validation_failed' );
    $search_op['changes']['original_widget_key'] = 'mcp_extra';
    $write( 'update-builder', [ 'directory_id' => $dir, 'area' => 'search_form', 'operations' => [ $search_op ] ] );
    $error( Tools::execute( 'update-builder', [ 'directory_id' => $dir, 'area' => 'submission_form', 'operations' => [ [ 'configuration' => 'submission_form_fields', 'entity' => 'field', 'action' => 'remove', 'id' => 'mcp_extra' ] ], 'dry_run' => true ] ), 'dependency_blocked' );
    $error( Tools::execute( 'update-builder', [ 'directory_id' => $dir, 'area' => 'submission_form', 'operations' => [ [ 'configuration' => 'submission_form_fields', 'entity' => 'field', 'action' => 'remove', 'id' => 'title' ] ], 'dry_run' => true ] ), 'validation_failed' );
    $write( 'update-directory', [ 'directory_id' => $dir, 'changes' => [ 'name' => 'MCP renamed ' . $suffix ] ] );
    $copy          = $write( 'duplicate-directory', [ 'directory_id' => $dir, 'name' => 'MCP duplicate ' . $suffix ] );
    $directories[] = $copy['directory_id'];
    $write( 'delete-directory', [ 'directory_id' => $copy['directory_id'] ] );

    foreach ( [ 'a', 'b' ] as $letter ) {
        $id      = $ok( wp_insert_user( [ 'user_login' => 'mcp_fixture_' . $letter . '_' . $suffix, 'user_pass' => wp_generate_password( 32 ), 'role' => 'subscriber' ] ), 'create fixture owner' );
        $users[] = $id;
    }
    $owner_a = $users[0];
    $owner_b = $users[1];
    ( new WP_User( $owner_a ) )->add_cap( 'edit_posts' );
    ( new WP_User( $owner_a ) )->add_cap( 'upload_files' );
    $cat      = $write( 'create-term', [ 'taxonomy' => ATBDP_CATEGORY, 'name' => 'MCP category ' . $suffix, 'directories' => [ $dir ] ] );
    $cat_id   = $cat['term_id'];
    $terms[]  = [ $cat_id, ATBDP_CATEGORY ];
    $child    = $write( 'create-term', [ 'taxonomy' => ATBDP_CATEGORY, 'name' => 'MCP child ' . $suffix, 'parent' => $cat_id, 'directories' => [ $dir ] ] );
    $child_id = $child['term_id'];
    $terms[]  = [ $child_id, ATBDP_CATEGORY ];
    $error( Tools::execute( 'update-term', [ 'taxonomy' => ATBDP_CATEGORY, 'term_id' => $cat_id, 'changes' => [ 'parent' => $child_id ], 'dry_run' => true ] ), 'validation_failed' );
    $error( Tools::execute( 'delete-term', [ 'taxonomy' => ATBDP_CATEGORY, 'term_id' => $cat_id, 'dry_run' => true ] ), 'dependency_blocked' );
    $write( 'delete-term', [ 'taxonomy' => ATBDP_CATEGORY, 'term_id' => $child_id ] );
    $batch_terms = $write( 'bulk-terms', [ 'taxonomy' => ATBDP_TAGS, 'action' => 'create', 'items' => [ [ 'client_ref' => 'one', 'name' => 'MCP tag one ' . $suffix, 'directories' => [ $dir ] ], [ 'client_ref' => 'two', 'name' => 'MCP tag two ' . $suffix, 'directories' => [ $dir ] ], [ 'client_ref' => 'invalid', 'name' => '', 'directories' => [ $dir ] ] ] ] );
    foreach ( $batch_terms['results'] as $item ) {
        if ( isset( $item['term_id'] ) ) {
            $terms[] = [ $item['term_id'], ATBDP_TAGS ];
        }
    }
    $assert( 2 === $batch_terms['summary']['succeeded'] && 1 === $batch_terms['summary']['failed'], 'Taxonomy bulk outcomes must be independent.' );

    wp_set_current_user( $owner_a );
    $error( Tools::execute( 'get-settings', [ 'keys' => [ 'all_listing_page_items' ] ] ), 'forbidden' );
    $error( Tools::execute( 'get-directory', [ 'directory_id' => $dir ] ), 'forbidden' );
    $error( Tools::execute( 'create-term', [ 'taxonomy' => ATBDP_CATEGORY, 'name' => 'unauthorized', 'dry_run' => true ] ), 'forbidden' );
    $lookup = $ok( Tools::execute( 'get-reference-data', [ 'kind' => 'assignment_terms', 'taxonomy' => ATBDP_CATEGORY, 'directory_id' => $dir ] ), 'owner term lookup' );
    $assert( ! isset( $lookup['items'][0]['version'] ), 'Assignment lookups must exclude management metadata.' );
    $schema = $ok( Tools::execute( 'get-listing-schema', [ 'directory_id' => $dir ] ), 'listing schema' );
    $assert( isset( $schema['schema_version'] ), 'Listing schema needs a version.' );
    $create_args = [ 'directory_id' => $dir, 'fields' => [ 'title' => 'MCP listing ' . $suffix ], 'status' => 'draft', 'consents' => [ 'terms_conditions' => true, 'privacy_policy' => true ] ];
    $listing     = $write( 'create-listing', $create_args );
    $listing_id  = $listing['listing_id'];
    $posts[]     = $listing_id;
    $assert( (int) get_post( $listing_id )->post_author === $owner_a && 'draft' === get_post_status( $listing_id ), 'Native creation must preserve owner and effective status.' );
    update_post_meta( $listing_id, '_mcp_legacy_retained', 'retained' );
    $write( 'update-listing', [ 'listing_id' => $listing_id, 'changes' => [ 'title' => 'MCP updated ' . $suffix, 'categories' => [ $cat_id ] ] ] );
    $assert( 'retained' === get_post_meta( $listing_id, '_mcp_legacy_retained', true ), 'Partial edit must retain unknown metadata.' );
    $assert( has_term( $cat_id, ATBDP_CATEGORY, $listing_id ), 'Authorized assignment should persist.' );
    $assert( 'MCP updated ' . $suffix === get_post( $listing_id )->post_title, 'Native content edits must retain the submitted title.' );
    $image_a = $ok( wp_insert_attachment( [ 'post_title' => 'MCP image fixture A', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit', 'post_author' => $owner_a ], false, $listing_id, true ), 'image fixture A' );
    $posts[] = $image_a;
    $image_b = $ok( wp_insert_attachment( [ 'post_title' => 'MCP image fixture B', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit', 'post_author' => $owner_a ], false, $listing_id, true ), 'image fixture B' );
    $posts[] = $image_b;
    update_post_meta( $image_a, '_wp_attached_file', 'mcp-fixture-' . $suffix . '-a.jpg' );
    update_post_meta( $image_b, '_wp_attached_file', 'mcp-fixture-' . $suffix . '-b.jpg' );
    $assert( 'MCP updated ' . $suffix === get_post( $listing_id )->post_title, 'Attachment creation must not change the listing title.' );
    $write( 'update-listing', [ 'listing_id' => $listing_id, 'changes' => [ 'images' => [ $image_a, $image_b ] ] ] );
    $assert( in_array( $image_b, array_map( 'intval', atbdp_get_listing_attachment_ids( $listing_id ) ), true ), 'Existing gallery attachments should be referenced.' );
    $write( 'update-listing', [ 'listing_id' => $listing_id, 'changes' => [ 'images' => [ $image_b ] ] ] );
    $assert( 'attachment' === get_post_type( $image_a ) && ! in_array( $image_a, array_map( 'intval', atbdp_get_listing_attachment_ids( $listing_id ) ), true ), 'Removing a reference must preserve the media object.' );
    $uploads   = wp_upload_dir();
    $file_path = $uploads['path'] . '/mcp-fixture-' . $suffix . '.pdf';
    file_put_contents( $file_path, "%PDF-1.4\n% Directorist MCP disposable file fixture.\n%%EOF\n" );
    $files[]    = $file_path;
    $attachment = $ok( wp_insert_attachment( [ 'post_title' => 'MCP document fixture', 'post_mime_type' => 'application/pdf', 'post_status' => 'inherit', 'post_author' => $owner_a ], $file_path, $listing_id, true ), 'file fixture' );
    $posts[]    = $attachment;
    $write( 'update-listing', [ 'listing_id' => $listing_id, 'changes' => [ 'custom-mcp_file' => $attachment ] ] );
    $assert( wp_get_attachment_url( $attachment ) === get_post_meta( $listing_id, '_custom-mcp_file', true ), 'File references must store the native attachment URL.' );
    $write( 'update-listing', [ 'listing_id' => $listing_id, 'changes' => [ 'custom-mcp_file' => 0 ] ] );
    $assert( 'attachment' === get_post_type( $attachment ) && is_file( $file_path ), 'Removing a file reference must retain the media file.' );
    $error( Tools::execute( 'update-listing', [ 'listing_id' => $listing_id, 'changes' => [ 'custom-mcp_file' => 'https://example.invalid/file.txt' ], 'dry_run' => true ] ), 'validation_failed' );
    $error( Tools::execute( 'update-listing', [ 'listing_id' => $listing_id, 'changes' => [ '_featured' => 1 ], 'dry_run' => true ] ), 'validation_failed' );
    $detail = $ok( Tools::execute( 'get-listing', [ 'listing_id' => $listing_id ] ), 'listing detail' );
    $stale  = $ok( Tools::execute( 'update-listing', [ 'listing_id' => $listing_id, 'changes' => [ 'title' => 'stale' ], 'expected_version' => $detail['version'], 'dry_run' => true ] ), 'stale preview' );
    wp_update_post( [ 'ID' => $listing_id, 'post_title' => 'external change' ] );
    $error( Tools::execute( 'update-listing', [ 'listing_id' => $listing_id, 'changes' => [ 'title' => 'stale' ], 'expected_version' => $detail['version'], 'confirm_token' => $stale['confirm_token'], 'idempotency_key' => wp_generate_uuid4() ] ), 'state_changed' );
    $fresh      = $ok( Tools::execute( 'get-listing', [ 'listing_id' => $listing_id ] ), 'fresh listing' );
    $retry_args = [ 'listing_id' => $listing_id, 'changes' => [ 'title' => 'idempotent title' ], 'expected_version' => $fresh['version'], 'idempotency_key' => wp_generate_uuid4() ];
    $first      = $ok( Tools::execute( 'update-listing', $retry_args ), 'first idempotent update' );
    $retry      = $ok( Tools::execute( 'update-listing', $retry_args ), 'idempotent replay' );
    $assert( ! empty( $retry['idempotent_replay'] ) && $retry['listing_id'] === $first['listing_id'], 'Retry must return the original saved identity.' );
    $error( Tools::execute( 'update-listing', array_replace( $retry_args, [ 'changes' => [ 'title' => 'different payload' ] ] ) ), 'idempotency_conflict' );
    wp_set_current_user( $owner_b );
    $error( Tools::execute( 'get-listing', [ 'listing_id' => $listing_id ] ), 'not_found' );
    $error( Tools::execute( 'update-listing', [ 'listing_id' => $listing_id, 'changes' => [ 'title' => 'steal' ], 'dry_run' => true ] ), 'not_found' );
    $own = $ok( Tools::execute( 'list-listings' ), 'owner query' );
    $assert( 0 === $own['total'], 'Owner B must not see owner A in management totals.' );
    wp_set_current_user( $owner_a );
    $fresh = $ok( Tools::execute( 'get-listing', [ 'listing_id' => $listing_id ] ), 'bulk input version' );
    $bulk  = [ 'action' => 'update', 'items' => [ [ 'client_ref' => 'allowed', 'listing_id' => $listing_id, 'expected_version' => $fresh['version'], 'changes' => [ 'title' => 'bulk title' ] ], [ 'client_ref' => 'missing', 'listing_id' => 999999999, 'changes' => [ 'title' => 'no' ] ] ] ];
    $batch = $write( 'bulk-listings', $bulk );
    $assert( 1 === $batch['summary']['succeeded'] && 1 === $batch['summary']['failed'], 'Mixed bulk results must remain independent.' );
    $write( 'trash-listing', [ 'listing_id' => $listing_id ] );
    $assert( 'trash' === get_post_status( $listing_id ), 'Trash should retain the post.' );
    $write( 'restore-listing', [ 'listing_id' => $listing_id ] );
    $assert( 'trash' !== get_post_status( $listing_id ) && 'retained' === get_post_meta( $listing_id, '_mcp_legacy_retained', true ), 'Restore should retain stored data.' );
    $owner        = wp_get_current_user();
    $listing_type = get_post_type_object( ATBDP_POST_TYPE );
    $owner->add_cap( $listing_type->cap->edit_posts, false );
    $error( Tools::execute( 'update-listing', [ 'listing_id' => $listing_id, 'changes' => [ 'title' => 'revoked' ], 'dry_run' => true ] ), 'forbidden' );
    $owner->remove_cap( $listing_type->cap->edit_posts );
    wp_set_current_user( 0 );
    $error( Tools::execute( 'get-context' ), 'unauthenticated' );
    wp_set_current_user( $admin );
    $error( Tools::execute( 'delete-term', [ 'taxonomy' => ATBDP_CATEGORY, 'term_id' => $cat_id, 'dry_run' => true ] ), 'dependency_blocked' );
    $error( Tools::execute( 'delete-directory', [ 'directory_id' => $dir, 'dry_run' => true ] ), 'dependency_blocked' );
    $epoch_preview          = $ok( Tools::execute( 'update-settings', [ 'changes' => [ 'all_listing_page_items' => 30 ], 'dry_run' => true ] ), 'disable preview' );
    $current                = get_option( 'atbdp_option' );
    $current['mcp_enabled'] = false;
    update_option( 'atbdp_option', $current );
    $error( Tools::execute( 'get-context' ), 'mcp_disabled' );
    $assert( is_wp_error( $ability->execute( [] ) ), 'A previously registered ability must reject execution after disabling MCP.' );
    $current['mcp_enabled'] = true;
    update_option( 'atbdp_option', $current );
    $error( Tools::execute( 'update-settings', [ 'changes' => [ 'all_listing_page_items' => 30 ], 'confirm_token' => $epoch_preview['confirm_token'], 'idempotency_key' => wp_generate_uuid4() ] ), 'confirmation_invalid' );
    WP_CLI::success( 'Directorist MCP: ' . $checks . ' integration assertions passed.' );
} finally {
    wp_set_current_user( $admin ?? $original_user );
    foreach ( $posts as $id ) {
        wp_delete_post( $id, true );
    }
    foreach ( $files as $file_path ) {
        if ( is_file( $file_path ) ) {
            unlink( $file_path );
        }
    }
    foreach ( array_reverse( $terms ) as $entry ) {
        if ( term_exists( $entry[0], $entry[1] ) ) {
            wp_delete_term( $entry[0], $entry[1] );
        }
    }
    foreach ( array_reverse( $directories ) as $id ) {
        if ( term_exists( $id, ATBDP_DIRECTORY_TYPE ) ) {
            wp_delete_term( $id, ATBDP_DIRECTORY_TYPE );
        }
    }
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach ( $users as $id ) {
        wp_delete_user( $id );
    }
    update_option( 'atbdp_option', $saved_options );
    foreach ( $default_meta as $existing_id => $values ) {
        delete_term_meta( $existing_id, '_default' );
        foreach ( $values as $value ) {
            add_term_meta( $existing_id, '_default', $value );
        }
    }
    if ( isset( $extension_filter ) ) {
        remove_filter( 'directorist_mcp_settings', $extension_filter );
    }
    if ( isset( $extension_key ) ) {
        delete_option( $extension_key );
    }
    if ( null === $saved_epoch ) {
        delete_option( 'directorist_mcp_epoch' );
    } else {
        update_option( 'directorist_mcp_epoch', $saved_epoch, false );
    }
    if ( ! $saved_cron ) {
        wp_clear_scheduled_hook( 'directorist_mcp_cleanup' );
    }
    foreach ( array_diff( $operational_names(), $before_names ) as $name ) {
        delete_option( $name );
    }
    remove_filter( 'pre_wp_mail', $suppress_mail );
    wp_set_current_user( $original_user );
    \Directorist\MCP\Write_Guard::unlock();
}
