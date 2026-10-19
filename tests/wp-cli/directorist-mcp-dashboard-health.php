<?php
/** Local-only read-contract fixtures. No live provider action, update check or account refresh. */
use Directorist\MCP\Catalog;
use Directorist\MCP\Dashboard;
use Directorist\MCP\Module;
use Directorist\MCP\Orders;
use Directorist\MCP\Read_Support;
use Directorist\MCP\Site_Health;
use Directorist\MCP\Registry;
use Directorist\MCP\Tools;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || 'local' !== wp_get_environment_type() ) {
    throw new RuntimeException( 'Run only on a disposable local WordPress site.' );
}
global $wpdb;
$lock = 'directorist_mcp_tests_' . md5( DB_NAME . ':' . $wpdb->prefix );
if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ) ) {
    throw new RuntimeException( 'Run fixture suites sequentially.' );
}
$checks         = 0;
$assert         = static function ( $test, $message ) use ( &$checks ) { ++$checks; if ( ! $test ) {
        throw new RuntimeException( $message );
}
};
$ok             = static function ( $result ) use ( $assert ) { $assert( ! is_wp_error( $result ), is_wp_error( $result ) ? $result->get_error_code() . ': ' . $result->get_error_message() : 'Successful result' ); return $result;
};
$error          = static function ( $result, $code ) use ( $assert ) { $assert( is_wp_error( $result ) && 'directorist_mcp_' . $code === $result->get_error_code(), 'Expected ' . $code . ': ' . wp_json_encode( $result ) );
};
$saved_user     = get_current_user_id();
$saved_options  = get_option( 'atbdp_option' );
$saved_epoch    = get_option( 'directorist_mcp_epoch', null );
$saved_cron     = wp_next_scheduled( 'directorist_mcp_cleanup' );
$saved_timezone = get_option( 'timezone_string' );
$saved_updates  = get_site_transient( 'update_plugins' );
$users          = [];
$posts          = [];
$order_ids      = [];
$roles          = [];
$prefix         = 'mcp_read_fixture_' . substr( wp_generate_uuid4(), 0, 8 );
$state          = (object) [ 'active' => true, 'mode' => 'safe', 'reads' => 0, 'debug_reads' => 0, 'http' => 0, 'writes' => 0, 'rejects' => [], 'tool_rejects' => [], 'tool_reads' => 0, 'permission_mode' => 'safe', 'hide_listing_tool' => false ];
$suppress_mail  = static function () { return true;
};
add_filter( 'pre_wp_mail', $suppress_mail );
$register     = static function ( $registry ) use ( $state ) {
    $registry->register_extension(
        [ 'owner'              => 'read-fixture',
            'plugin'           => 'directorist/directorist-base.php',
            'label'            => 'Read contract fixture',
            'version'          => '1',
            'contract_version' => '1',
            'api_version'      => 1,
            'capability'       => 'read',
            'availability'     => static function () use ( $state ) { return $state->active;
            },
            'admission'        => static function () { return current_user_can( 'read' );
            }
        ]
    );
    $registry->register_policy(
        [ 'owner'   => 'read-fixture',
            'key'   => 'hide-listing',
            'tools' => [ 'get-listing' ],
            'check' => static function () use ( $state ) { return ! $state->hide_listing_tool;
            }
        ]
    );
    $schema = [ 'type' => 'object', 'properties' => [ 'count' => [ 'type' => 'integer' ], 'summary' => [ 'type' => 'string', 'maxLength' => 100 ] ], 'required' => [ 'count', 'summary' ], 'additionalProperties' => false ];
    foreach ( [ 'dashboard', 'site_health_info' ] as $domain ) {
        $d                = [ 'owner' => 'read-fixture',
            'key'                     => 'safe',
            'domain'                  => $domain,
            'api_version'             => 1,
            'label'                   => 'Fixture diagnostics',
            'contract_version'        => '1',
            'source_version'          => '1',
            'units'                   => 'count',
            'range_semantics'         => 'current',
            'sensitivity'             => 'public',
            'cache'                   => 'none',
            'kind'                    => 'dashboard' === $domain ? 'overview' : 'section',
            'scopes'                  => 'dashboard' === $domain ? [ 'site', 'own' ] : [ 'site' ],
            'filters'                 => [],
            'permission'              => static function () { return current_user_can( 'read' );
            },
            'availability'            => static function () use ( $state ) { return $state->active;
            },
            'output_schema'           => $schema,
            'project'                 => static function ( $value ) { return $value;
            },
            'read'                    => static function () use ( $state ) {
                ++$state->reads;
                if ( 'http' === $state->mode ) {
                    wp_remote_get( 'https://example.invalid' );
                }
                if ( 'write' === $state->mode ) {
                    update_option( 'directorist_mcp_read_violation_fixture', 'no', false );
                }
                if ( 'throw' === $state->mode ) {
                    throw new RuntimeException( 'private fixture credential /sensitive/path' );
                }
                return [ 'count' => 3, 'summary' => 'secret' === $state->mode ? 'password=private-fixture-value' : 'Fixture safe information' ];
            }
        ];
        $state->rejects[] = $registry->register_read_adapter( $d );
        $state->rejects[] = $registry->register_read_adapter( $d );
        $state->rejects[] = $registry->register_read_adapter( array_merge( $d, [ 'key' => 'invalid', 'write' => '__return_true' ] ) );
        $state->rejects[] = $registry->register_read_adapter( array_merge( $d, [ 'key' => 'future', 'api_version' => 99 ] ) );
    }
    $properties = array_fill_keys(
        array_map(
            static function ( $i ) { return 'field_' . $i;
            }, range( 1, 100 )
        ), [ 'type' => 'string', 'maxLength' => 2048 ]
    );
    foreach ( [ 'large-one', 'large-two' ] as $key ) {
        $registry->register_read_adapter(
            array_merge(
                $d, [ 'key'         => $key,
                    'output_schema' => [ 'type' => 'object', 'properties' => $properties, 'required' => array_keys( $properties ), 'additionalProperties' => false ],
                    'read'          => static function () use ( $properties ) { return array_fill_keys( array_keys( $properties ), str_repeat( 'x', 1500 ) );
                    }
                ]
            )
        );
    }
    $registry->register_read_adapter(
        array_merge(
            $d, [ 'domain'      => 'dashboard',
                'kind'          => 'guidance',
                'key'           => 'links',
                'link_hosts'    => [ 'directorist.com' ],
                'output_schema' => [ 'type' => 'object', 'properties' => [ 'url' => [ 'type' => 'string', 'maxLength' => 2048 ] ], 'required' => [ 'url' ], 'additionalProperties' => false ],
                'read'          => static function () use ( $state ) { return [ 'url' => 'https://' . ( 'bad-link' === $state->mode ? 'example.com' : 'directorist.com' ) . '/documentation/' ];
                }
            ]
        )
    );
    $tool = [ 'owner'      => 'read-fixture',
        'action'           => 'inspect',
        'domain'           => 'dashboard',
        'group'            => 'dashboard',
        'description'      => 'Independent read fixture',
        'contract_version' => '1',
        'write'            => false,
        'schema'           => [ 'type' => 'object', 'properties' => [ 'scope' => [ 'type' => 'string', 'enum' => [ 'site', 'own' ] ], 'listing_id' => [ 'type' => 'integer', 'minimum' => 1 ], 'order_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'additionalProperties' => false ],
        'output_schema'    => [ 'type' => 'object', 'properties' => [ 'scope' => [ 'type' => 'string', 'maxLength' => 10 ] ], 'required' => [ 'scope' ], 'additionalProperties' => false ],
        'discover'         => '__return_true',
        'permission'       => static function () use ( $state ) {
            if ( 'write' === $state->permission_mode ) {
                update_option( 'directorist_mcp_read_violation_fixture', 'no', false );
            }
            if ( 'http' === $state->permission_mode ) {
                wp_remote_get( 'https://example.invalid' );
            }
            return true;
        },
        'read'             => static function ( $args ) use ( $state ) { ++$state->tool_reads; return [ 'scope' => $args['scope'] ];
        },
        'project'          => static function ( $value ) { return $value;
        },
    ];
    $registry->register_tool( $tool );
    foreach ( [ 'user_id', 'author_id', 'customer_id', 'blog_id', 'site_id', 'listing_ids' ] as $key ) {
        $invalid                                 = $tool;
        $invalid['action']                       = 'bad-' . str_replace( '_', '-', $key );
        $invalid['schema']['properties'][ $key ] = [ 'type' => 'integer' ];
        $state->tool_rejects[]                   = $registry->register_tool( $invalid );
    }
    $tool['action']                          = 'bad-nested';
    $tool['schema']['properties']['filters'] = [ 'type' => 'object', 'properties' => [ 'order_id' => [ 'type' => 'integer' ] ], 'additionalProperties' => false ];
    $state->tool_rejects[]                   = $registry->register_tool( $tool );
    $state->rejects[]                        = $registry->register_read_adapter( array_merge( $d, [ 'key' => 'private-fields', 'output_schema' => [ 'type' => 'object', 'properties' => [ 'private' => [ 'type' => 'string', 'maxLength' => 20 ] ], 'additionalProperties' => false ] ] ) );
};
$debug_filter = static function ( $data ) use ( $state ) { ++$state->debug_reads; return $data;
};
add_filter( 'debug_information', $debug_filter );
add_action( 'directorist_mcp_register', $register );
$property = new ReflectionProperty( Registry::class, 'instance' );
$property->setAccessible( true );
$old_registry = $property->getValue();
$property->setValue( null, null );
try {
    $admin = get_users( [ 'role' => 'administrator', 'number' => 1 ] )[0]->ID;
    wp_set_current_user( $admin );
    update_option( 'atbdp_option', array_merge( (array) $saved_options, [ 'mcp_enabled' => true ] ) );
    update_option( 'timezone_string', 'America/New_York' );
    foreach ( [ 'owner', 'customer', 'staff' ] as $role ) {
        $key  = $prefix . '_' . $role;
        $caps = [ 'read' => true ];
        if ( 'owner' === $role ) {
            $type                                   = get_post_type_object( ATBDP_POST_TYPE );
            $caps[ $type->cap->edit_posts ]         = true;
            $caps[ $type->cap->read_private_posts ] = true;
        }
        add_role( $key, $key, $caps );
        $roles[]        = $key;
        $users[ $role ] = $ok( wp_insert_user( [ 'user_login' => $key, 'user_pass' => wp_generate_password( 32 ), 'role' => $key ] ) );
    }
    foreach ( [ 'owner', 'customer' ] as $who ) {
        foreach ( [ 'publish', 'private', 'pending' ] as $status ) {
            $id                            = $ok( wp_insert_post( [ 'post_type' => ATBDP_POST_TYPE, 'post_author' => $users[ $who ], 'post_title' => $prefix, 'post_status' => $status, 'post_date' => current_time( 'mysql' ), 'post_date_gmt' => current_time( 'mysql', true ) ], true ) );
            $posts[ $who . '-' . $status ] = $id;
            update_post_meta( $id, directorist_get_listing_views_count_meta_key(), '17' );
            if ( 'publish' === $status ) {
                update_post_meta( $id, '_expiry_date', current_datetime()->modify( '+2 days' )->format( 'Y-m-d H:i:s' ) );
            }
        }
    }
    $create_order = static function ( $who, $currency, $amount ) use ( $wpdb, &$order_ids, $users ) {
        if ( ! $wpdb->insert( Orders::tables()[0], [ 'user_id' => $users[ $who ], 'currency' => $currency, 'amount' => $amount, 'sub_total' => $amount, 'status' => 'paid', 'created_at' => $wpdb->get_var( 'SELECT CURRENT_TIMESTAMP' ), 'updated_at' => $wpdb->get_var( 'SELECT CURRENT_TIMESTAMP' ) ] ) ) {
            throw new RuntimeException( 'Fixture order insert failed.' );
        }
        $order_ids[] = (int) $wpdb->insert_id;
        return (int) $wpdb->insert_id;
    };
    $first        = $create_order( 'owner', 'USD', '10.10' );
    $create_order( 'owner', 'EUR', '2.05' );
    $create_order( 'customer', 'USD', '99.99' );
    $catalog = Catalog::definitions();
    $assert(
        53 === count(
            array_filter(
                $catalog, static function ( $d ) { return empty( $d['owner'] );
                }
            )
        ), 'Exactly 53 core tools, plus the independently registered fixture.'
    );
    $assert(
        2 === count(
            array_filter(
                $state->rejects, static function ( $r ) { return true === $r;
                }
            )
        ), 'Only the two valid adapters register.'
    );
    foreach ( array_merge( array_keys( Dashboard::definitions() ), array_keys( \Directorist\MCP\Site_Health::definitions() ) ) as $tool ) {
        $assert( isset( $catalog[ $tool ]['output_schema'] ) && ! $catalog[ $tool ]['write'] && false === $catalog[ $tool ]['schema']['additionalProperties'], 'Typed closed read contract: ' . $tool );
    }
    $context       = $ok( Tools::execute( 'get-context' ) );
    $schema_result = $ok( Tools::execute( 'get-dashboard-schema' ) );
    $assert( 'last_30_days' === $schema_result['defaults']['period'] && 'unavailable' === $schema_result['availability']['recommendations']['state'], 'Discovery publishes effective defaults and safe optional-source availability.' );
    $assert( in_array( 'dashboard', $context['groups'], true ) && in_array( 'site-health-info', $context['groups'], true ), 'Groups derive from registry.' );
    $assert( Module::status()['tools_count'] === count( $context['tools'] ), 'Drawer count matches permitted inventory.' );
    foreach ( [ 'get-dashboard-overview', 'get-dashboard-guidance', 'discover-site-health-info', 'get-site-health-report' ] as $tool ) {
        $ok( Tools::execute( $tool ) );
    }
    foreach ( [ 'runtime', 'server', 'database', 'media', 'constants', 'filesystem', 'plugins', 'plugins-inactive', 'themes', 'themes-inactive', 'wordpress', 'directorist', 'mcp', 'storage' ] as $id ) {
        $result = $ok( Tools::execute( 'get-site-health-info', [ 'section_id' => $id ] ) );
        $assert( ! str_contains( wp_json_encode( $result ), ABSPATH ), 'No absolute installation path in ' . $id );
    }
    $assert( count( $state->tool_rejects ) === count( array_filter( $state->tool_rejects, 'is_wp_error' ) ), 'Standalone diagnostic tools reject identity, cross-site and nested object selectors.' );
    $discovery = $ok( Tools::execute( 'discover-site-health-info', [ 'section_id' => 'media' ] ) );
    $fields    = array_column( $discovery['items'], null, 'key' );
    $assert( 'string' === $fields['supported_formats']['schema']['items']['type'] && 100 === $fields['supported_formats']['schema']['maxItems'], 'Discovery publishes nested field constraints rather than just array type.' );
    $discovery_keys = array_column( $discovery['items'], 'key' );
    $sorted_keys    = $discovery_keys;
    sort( $sorted_keys );
    $assert( $sorted_keys === $discovery_keys, 'Field discovery uses stable machine-key order.' );
    // Source-only fixtures avoid plugin installation, activation and update refresh.
    $plugin_cache = wp_cache_get( 'plugins', 'plugins', false, $plugin_cache_found );
    try {
        $plugins     = $plugin_cache ?: [];
        $plugins[''] = [
            'z-read-fixture.php' => [ 'Name' => 'Zulu fixture', 'Version' => '1' ],
            'a-read-fixture.php' => [ 'Name' => 'Alpha fixture', 'Version' => '1' ],
            'm-read-fixture.php' => [ 'Name' => 'Middle fixture', 'Version' => '1' ],
        ];
        wp_cache_set( 'plugins', $plugins, 'plugins' );
        $full  = $ok( Tools::execute( 'get-site-health-info', [ 'section_id' => 'plugins-inactive', 'per_page' => 1, 'page' => 2 ] ) );
        $names = $ok( Tools::execute( 'get-site-health-info', [ 'section_id' => 'plugins-inactive', 'field_keys' => [ 'name' ], 'per_page' => 1, 'page' => 2 ] ) );
        $assert( array_column( $full['section']['items'], 'name' ) === array_column( $names['section']['items'], 'name' ), 'Removing id from selected fields never changes inventory page membership.' );
        wp_cache_set( 'plugins', [ '' => [] ], 'plugins' );
        $empty = $ok( Tools::execute( 'get-site-health-info', [ 'section_id' => 'plugins-inactive' ] ) );
        $assert( 'empty' === $empty['section']['state'] && 0 === $empty['section']['total'], 'A successful empty inventory is distinct from unavailable.' );
    } finally {
        if ( $plugin_cache_found ) {
            wp_cache_set( 'plugins', $plugin_cache, 'plugins' );
        } else {
            wp_cache_delete( 'plugins', 'plugins' );
        }
    }
    $inventory = new ReflectionMethod( Site_Health::class, 'plugin_inventory' );
    $inventory->setAccessible( true );
    $observed = gmdate( 'c', time() - 120 );
    $rows     = $inventory->invoke(
        null, [
            'plugin'   => [ 'same.php' => [ 'Name' => 'Regular fixture', 'Version' => '1', 'RequiresWP' => '999.0' ] ],
            'must_use' => [ 'same.php' => [ 'Name' => 'MU fixture', 'Version' => '2' ] ],
        ], [ 'same.php' ], (object) [ 'response' => [ 'same.php' => (object) [] ] ], $observed, false
    );
    $assert( 2 === count( $rows ) && 2 === count( array_unique( array_column( $rows, 'id' ) ) ), 'Same-basename regular and MU plugins retain independent stable identities.' );
    $assert( 'unsupported' === $rows[0]['compatibility'] && 'unknown' === $rows[1]['compatibility'], 'Manifest minimum failures are unsupported and undeclared combinations remain unknown.' );
    $assert( 'update_available' === $rows[0]['update_state'] && 'unknown' === $rows[1]['update_state'] && $rows[0]['update_age_seconds'] >= 120, 'MU plugins cannot inherit regular plugin update observations; stale age remains visible.' );
    $theme_updates = static function () { return (object) [ 'last_checked' => time() - 60, 'no_update' => [ get_stylesheet() => [] ] ];
    };
    add_filter( 'pre_site_transient_update_themes', $theme_updates );
    try {
        $themes       = $ok( Tools::execute( 'get-site-health-info', [ 'section_id' => 'themes', 'per_page' => 100 ] ) );
        $active_theme = array_values(
            array_filter(
                $themes['section']['items'], static function ( $row ) { return 'active' === $row['category'];
                }
            )
        )[0];
        $assert( 'observed_no_update' === $active_theme['update_state'] && $active_theme['update_age_seconds'] >= 60, 'Theme no-update observations retain their state and original age.' );
    } finally {
        remove_filter( 'pre_site_transient_update_themes', $theme_updates );
    }
    foreach ( [ '/tmp', 'Library /etc/config', 'C:\\private\\data', '\\\\host\\share', 'host db.internal', '[2001:db8::1]', '::1' ] as $unsafe ) {
        $assert( null === Read_Support::safe_value( $unsafe ), 'Infrastructure banners are redacted: ' . hash( 'sha256', $unsafe ) );
    }
    $unicode = str_repeat( 'অ', 2000 );
    $assert( $unicode === Read_Support::safe_value( $unicode ) && null === Read_Support::safe_value( $unicode . str_repeat( 'অ', 49 ) ), 'Scalar limits count Unicode characters and reject oversize data without truncation.' );
    $assert( null === Read_Support::safe_url( home_url( '/?%2574oken=abc' ) ) && null === Read_Support::safe_url( str_replace( 'https:', 'http:', home_url( '/' ) ) ), 'Navigation rejects encoded credential selectors and HTTPS downgrades.' );
    $gd_formats = new ReflectionMethod( Site_Health::class, 'gd_formats' );
    $gd_formats->setAccessible( true );
    $assert( [ 'gif', 'webp' ] === $gd_formats->invoke( null, [ 'GIF Read Support' => true, 'WebP Support' => true ] ), 'Native GD GIF/WebP keys are mapped correctly without relying on Imagick.' );
    $writable = new ReflectionMethod( Site_Health::class, 'writable' );
    $writable->setAccessible( true );
    $assert( null === $writable->invoke( null, ABSPATH . 'nonexistent-' . $prefix ), 'Unavailable filesystem areas stay unknown rather than false success.' );
    foreach ( [ 'write', 'http' ] as $mode ) {
        $state->permission_mode = $mode;
        $before_reads           = $state->tool_reads;
        $error( Tools::execute( 'read-fixture-inspect' ), 'read_only_violation' );
        $assert( $state->tool_reads === $before_reads && false === get_option( 'directorist_mcp_read_violation_fixture', false ), 'Permission callbacks are guarded before the source executes.' );
    }
    $state->permission_mode = 'safe';
    $old_date               = static function ( $value, $id, $key ) { return '_created_date' === $key ? time() - 40 * DAY_IN_SECONDS : $value;
    };
    $setup_terms            = static function ( $terms, $query ) {
        if ( in_array( ATBDP_DIRECTORY_TYPE, (array) $query->query_vars['taxonomy'], true ) ) {
            return [ new WP_Term( (object) [ 'term_id' => 2147480000, 'taxonomy' => ATBDP_DIRECTORY_TYPE, 'name' => 'Fixture directory', 'slug' => 'fixture-directory' ] ) ];
        }
        return $terms;
    };
    add_filter( 'terms_pre_query', $setup_terms, 10, 2 );
    add_filter( 'get_term_metadata', $old_date, 10, 3 );
    try {
        $native   = ( new ATBDP_Extension_Activity() )->get_dashboard_setup( [ 'published_listings' => 1 ] );
        $complete = ( new ATBDP_Extension_Activity() )->get_dashboard_setup_details( [ 'published_listings' => 1 ] );
        $assert( false === $native['is_visible'] && [] === $native['steps'] && false === $complete['is_visible'] && 4 === count( $complete['steps'] ) && $complete['progress'] >= 25, 'Expired setup window preserves the native UI contract and opt-in completion data.' );
        $setup = $ok( Tools::execute( 'get-dashboard-guidance', [ 'sections' => [ 'setup' ] ] ) );
        $assert( false === $setup['sections']['setup']['data']['visible'] && 4 === count( $setup['sections']['setup']['data']['steps'] ), 'MCP setup retains completion when the native setup window is hidden.' );
    } finally {
        remove_filter( 'get_term_metadata', $old_date, 10 );
        remove_filter( 'terms_pre_query', $setup_terms, 10 );
    }
    $runtime = $ok( Tools::execute( 'get-site-health-info', [ 'section_id' => 'runtime', 'field_keys' => [ 'php_version', 'sapi' ] ] ) );
    $assert( PHP_VERSION === array_column( $runtime['section']['items'], 'value', 'key' )['php_version'], 'Current runtime facts are observed locally.' );
    $error( Tools::execute( 'get-site-health-info', [ 'section_id' => 'runtime', 'include_private' => true ] ), 'validation_failed' );
    $error( Tools::execute( 'get-site-health-info', [ 'section_id' => 'runtime', 'field_keys' => [ 'php_version', 'php_version' ] ] ), 'validation_failed' );
    $error( Tools::execute( 'get-site-health-info', [ 'section_id' => 'constants', 'field_keys' => [ 'ABSPATH' ] ] ), 'forbidden' );
    $reads = $state->reads;
    $error( Tools::execute( 'get-site-health-report', [ 'section_ids' => [ 'read-fixture-safe', 'private-section' ] ] ), 'forbidden' );
    $assert( $reads === $state->reads, 'Whole selection authorized before a collector runs.' );
    $basic_report = $ok( Tools::execute( 'get-site-health-report', [ 'format' => 'json', 'section_ids' => [ 'runtime' ] ] ) );
    $assert( 'seconds' === array_column( $basic_report['sections'][0]['items'], 'units', 'key' )['execution_limit_seconds'], 'Reports publish explicit units for resource limits.' );
    $json = $ok( Tools::execute( 'get-site-health-report', [ 'format' => 'json', 'section_ids' => [ 'read-fixture-safe' ] ] ) );
    $text = $ok( Tools::execute( 'get-site-health-report', [ 'format' => 'text', 'section_ids' => [ 'read-fixture-safe' ] ] ) );
    $assert( str_contains( wp_json_encode( $json ), 'Fixture safe information' ) && str_contains( $text['content'], 'Fixture safe information' ), 'JSON and text use the same safe values.' );
    $assert( str_contains( $text['content'], "\n[read-fixture-safe]" ) && ! str_contains( $text['content'], '\\n[' ), 'Text reports contain real section line breaks.' );
    $links = $ok( Tools::execute( 'get-dashboard-guidance', [ 'sections' => [ 'read-fixture-links' ] ] ) );
    $assert( 'https://directorist.com/documentation/' === $links['sections']['read-fixture-links']['data']['url'], 'Registered public guidance link is returned as data without fetching it.' );
    $state->mode = 'bad-link';
    $links       = $ok( Tools::execute( 'get-dashboard-guidance', [ 'sections' => [ 'read-fixture-links' ] ] ) );
    $assert( 'unavailable' === $links['sections']['read-fixture-links']['state'], 'Unregistered guidance destination is rejected.' );
    $state->mode = 'safe';
    $error( Tools::execute( 'get-site-health-report', [ 'section_ids' => [ 'read-fixture-large-one', 'read-fixture-large-two' ] ] ), 'validation_failed' );
    $large_page = $ok( Tools::execute( 'get-site-health-info', [ 'section_id' => 'read-fixture-large-one', 'per_page' => 10 ] ) );
    $assert( 10 === count( $large_page['section']['items'] ) && 100 === $large_page['section']['total'], 'Large source remains readable in bounded pages.' );
    foreach ( [ 'secret', 'throw', 'write', 'http' ] as $mode ) {
        $state->mode = $mode;
        $result      = $ok( Tools::execute( 'get-site-health-info', [ 'section_id' => 'read-fixture-safe' ] ) );
        $assert( 'unavailable' === $result['section']['state'] && ! str_contains( wp_json_encode( $result ), 'private-fixture-value' ), 'Fault or unsafe output isolated: ' . $mode );
    }
    $state->mode = 'safe';
    $assert( false === get_option( 'directorist_mcp_read_violation_fixture', false ), 'Hidden contribution write never persisted.' );
    $assert( 0 === $state->debug_reads, 'Native debug hook never runs implicitly.' );
    foreach ( [ true, false ] as $active ) {
        $state->active = $active;
        $result        = Tools::execute( 'get-site-health-info', [ 'section_id' => 'read-fixture-safe' ] );
        if ( $active ) {
            $ok( $result );
        } else {
            $error( $result, 'forbidden' );
        }
    }
    $state->active = true;
    $error( Tools::execute( 'get-dashboard-overview', [ 'directory_id' => directorist_get_default_directory(), 'sections' => [ 'orders' ] ] ), 'unsupported_operation' );
    $error( Tools::execute( 'get-dashboard-series', [ 'metric' => 'published_listings', 'start_date' => '2026-02-30', 'end_date' => '2026-03-02' ] ), 'validation_failed' );
    $error( Tools::execute( 'get-dashboard-series', [ 'metric' => 'published_listings', 'period' => 'last_7_days', 'start_date' => '2026-01-01', 'end_date' => '2026-01-02' ] ), 'validation_failed' );
    $error( Tools::execute( 'get-dashboard-series', [ 'metric' => 'published_listings', 'start_date' => '2026-01-01', 'end_date' => '2026-04-01' ] ), 'validation_failed' );
    $series = $ok( Tools::execute( 'get-dashboard-series', [ 'metric' => 'published_listings', 'start_date' => '2026-03-07', 'end_date' => '2026-03-09', 'compare_previous' => true ] ) );
    $assert( 3 === count( $series['series']['data']['points'] ) && '2026-03-10 04:00:00' === $series['filters']['range']['end_exclusive_utc'], 'DST dates use local calendar boundaries.' );
    $views = $ok( Tools::execute( 'get-dashboard-series', [ 'metric' => 'listing_views' ] ) );
    $assert( 'distribution' === $views['series']['data']['kind'] && null === $views['series']['data']['comparison'], 'No fabricated historical view trend.' );
    $daily_views = $ok( Tools::execute( 'get-dashboard-series', [ 'metric' => 'listing_views', 'series_kind' => 'daily' ] ) );
    $assert( 'unavailable' === $daily_views['series']['state'], 'Absent daily analytics is explicit.' );
    wp_set_current_user( $users['owner'] );
    $own = $ok( Tools::execute( 'get-dashboard-overview' ) );
    $assert( 'own' === $own['scope'] && 1 === $own['sections']['listings']['data']['by_status']['publish'] && 1 === $own['sections']['listings']['data']['expiring_next_7_days'], 'Own authorized stock/expiry excludes the other owner.' );
    $groups = array_column( $own['sections']['orders']['data']['groups'], 'amount', 'currency' );
    $assert( '10.10' === $groups['USD'] && '2.05' === $groups['EUR'], 'Exact currencies remain separate and exclude other purchaser.' );
    $error( Tools::execute( 'get-dashboard-series', [ 'metric' => 'paid_order_value' ] ), 'validation_failed' );
    $money_series = $ok( Tools::execute( 'get-dashboard-series', [ 'metric' => 'paid_order_value', 'currency' => 'USD', 'compare_previous' => true ] ) );
    $assert( '10.10' === end( $money_series['series']['data']['points'] )['value'], 'Local-day financial series uses exact amount even without DB timezone tables.' );
    $paid_feed = $ok( Tools::execute( 'list-dashboard-activity', [ 'type' => 'payment' ] ) );
    $assert( abs( strtotime( $paid_feed['items'][0]['timestamp'] ) - time() ) < 30, 'TIMESTAMP activity is normalized from database session timezone to UTC.' );
    $prepaid = $create_order( 'owner', 'USD', '3.00' );
    $wpdb->update( Orders::tables()[0], [ 'status' => 'prepaid' ], [ 'id' => $prepaid ] );
    $statuses = $ok( Tools::execute( 'get-dashboard-overview', [ 'sections' => [ 'orders' ] ] ) );
    $assert( 1 === $statuses['sections']['orders']['data']['by_status']['prepaid'], 'Every native order status validates in dashboard overview output.' );
    $other_order  = $create_order( 'customer', 'USD', '4.00' );
    $before_reads = $state->tool_reads;
    $error( Tools::execute( 'read-fixture-inspect', [ 'listing_id' => $posts['customer-publish'] ] ), 'not_found' );
    $error( Tools::execute( 'read-fixture-inspect', [ 'order_id' => $other_order ] ), 'not_found' );
    $assert( $before_reads === $state->tool_reads, 'A permissive extension cannot execute against another owner through dashboard selectors.' );
    $own_tool = $ok( Tools::execute( 'read-fixture-inspect', [ 'listing_id' => $posts['owner-publish'], 'order_id' => $first ] ) );
    $assert( 'own' === $own_tool['scope'], 'Standalone dashboard readers receive the effective authorized scope.' );
    $wpdb->update( Orders::tables()[0], [ 'legacy_id' => 2147480001 ], [ 'id' => $first ] );
    $duplicate = $create_order( 'owner', 'USD', '10.10' );
    $wpdb->update( Orders::tables()[0], [ 'legacy_id' => 2147480001 ], [ 'id' => $duplicate ] );
    $ambiguous = $ok( Tools::execute( 'get-dashboard-overview', [ 'sections' => [ 'orders' ] ] ) );
    $assert( 'partial' === $ambiguous['sections']['orders']['state'] && ! isset( array_column( $ambiguous['sections']['orders']['data']['groups'], 'amount', 'currency' )['USD'] ), 'Ambiguous legacy mappings never double count financial value.' );
    $wpdb->update( Orders::tables()[0], [ 'legacy_id' => null ], [ 'id' => $first ] );
    $wpdb->delete( Orders::tables()[0], [ 'id' => $duplicate ] );
    $error( Tools::execute( 'get-dashboard-overview', [ 'scope' => 'site' ] ), 'forbidden' );
    $error( Tools::execute( 'get-dashboard-overview', [ 'user_id' => $users['customer'] ] ), 'validation_failed' );
    $error( Tools::execute( 'get-site-health-report' ), 'forbidden' );
    $error( Tools::execute( 'list-dashboard-activity', [ 'type' => 'user' ] ), 'forbidden' );
    $feed = $ok( Tools::execute( 'list-dashboard-activity', [ 'type' => 'listing', 'per_page' => 100 ] ) );
    $assert(
        ! array_intersect(
            array_values(
                array_filter(
                    $posts, static function ( $id, $key ) { return str_starts_with( $key, 'customer-' );
                    }, ARRAY_FILTER_USE_BOTH
                )
            ), array_column( $feed['items'], 'object_id' )
        ), 'Activity excludes all other-owner IDs before pagination.'
    );
    $assert(
        in_array( $posts['owner-publish'], array_column( $feed['upcoming']['items'], 'object_id' ), true ) && ! array_filter(
            $feed['items'], static function ( $item ) { return $item['upcoming'];
            }
        ), 'Upcoming expiry reminders have a separate bounded collection from past activity.'
    );
    $state->hide_listing_tool = true;
    $hidden_refs              = $ok( Tools::execute( 'list-dashboard-activity', [ 'type' => 'listing' ] ) );
    $assert(
        ! array_filter(
            array_merge( $hidden_refs['items'], $hidden_refs['upcoming']['items'] ), static function ( $item ) { return null !== $item['detail_tool'];
            }
        ), 'Activity never advertises a policy-hidden detail tool.'
    );
    $state->hide_listing_tool = false;
    update_post_meta( $posts['owner-publish'], '_never_expire', '1' );
    $fresh = $ok( Tools::execute( 'get-dashboard-overview', [ 'sections' => [ 'listings' ] ] ) );
    $assert( 0 === $fresh['sections']['listings']['data']['expiring_next_7_days'], 'Fresh reads reflect native changes immediately.' );
    update_user_meta( $users['owner'], 'atbdp_favourites', [ $posts['customer-private'], $posts['customer-publish'] ] );
    $favorites = $ok( Tools::execute( 'get-dashboard-overview', [ 'sections' => [ 'favorites' ] ] ) );
    $assert( 1 === $favorites['sections']['favorites']['data']['count'], 'Private favorite existence does not contribute to count.' );
    $deny_listing = static function ( $allowed, $context, $id, $type ) use ( $posts ) { return in_array( (int) $id, [ $posts['customer-publish'], $posts['owner-publish'] ], true ) && ATBDP_POST_TYPE === $type ? false : $allowed;
    };
    add_filter( 'directorist_rest_check_permissions', $deny_listing, 10, 4 );
    try {
        $restricted_favorites = $ok( Tools::execute( 'get-dashboard-overview', [ 'sections' => [ 'favorites' ] ] ) );
        $assert( 'empty' === $restricted_favorites['sections']['favorites']['state'] && 0 === $restricted_favorites['sections']['favorites']['data']['count'], 'Native restrictive listing policy also applies before favorite counts.' );
    } finally {
        remove_filter( 'directorist_rest_check_permissions', $deny_listing, 10 );
    }
    update_post_meta( $posts['owner-publish'], directorist_get_listing_views_count_meta_key(), 'invalid-counter' );
    $invalid_views = $ok( Tools::execute( 'get-dashboard-overview', [ 'sections' => [ 'views' ] ] ) );
    $assert( 'unavailable' === $invalid_views['sections']['views']['state'] && null === $invalid_views['sections']['views']['data'], 'Invalid stored counters are not fabricated zeros.' );
    update_post_meta( $posts['owner-publish'], directorist_get_listing_views_count_meta_key(), '17' );
    wp_set_current_user( $users['customer'] );
    $customer = $ok( Tools::execute( 'get-dashboard-overview' ) );
    $assert( ! isset( $customer['sections']['listings'] ) && isset( $customer['sections']['orders'] ), 'Order-only admission does not expose own listings merely because posts exist.' );
    $customer_schema = $ok( Tools::execute( 'get-dashboard-schema' ) );
    $assert( ! isset( $customer_schema['definitions']['listing_views'] ) && [ 'paid_order_value' ] === $customer_schema['metrics'], 'Order-only schema advertises only eligible metrics and definitions.' );
    wp_set_current_user( $users['staff'] );
    $staff = wp_get_current_user();
    $staff->add_cap( 'manage_options' );
    $error( Tools::execute( 'get-site-health-report' ), 'forbidden' );
    $staff->add_cap( 'view_site_health_checks' );
    $ok( Tools::execute( 'get-site-health-report' ) );
    $staff->remove_cap( 'view_site_health_checks' );
    $error( Tools::execute( 'get-site-health-report' ), 'forbidden' );
    wp_set_current_user( $admin );
    $error( Tools::execute( 'read-fixture-inspect', [ 'scope' => 'own', 'listing_id' => $posts['owner-publish'] ] ), 'not_found' );
    $review = wp_insert_comment( [ 'comment_post_ID' => $posts['owner-publish'], 'comment_content' => 'Fixture review', 'comment_type' => 'review', 'comment_approved' => '1', 'comment_date' => current_time( 'mysql' ), 'comment_date_gmt' => current_time( 'mysql', true ) ] );
    add_filter( 'directorist_rest_check_permissions', $deny_listing, 10, 4 );
    try {
        $reviews = $ok( Tools::execute( 'list-dashboard-activity', [ 'type' => 'review' ] ) );
        $assert( ! in_array( (int) $review, array_column( $reviews['items'], 'object_id' ), true ), 'Review activity cannot bypass the native listing read policy.' );
    } finally {
        remove_filter( 'directorist_rest_check_permissions', $deny_listing, 10 );
    }
    $http_observer = static function ( $pre ) use ( $state ) { ++$state->http; return $pre;
    };
    add_filter( 'pre_http_request', $http_observer );
    $before_options = SupportSnapshot();
    foreach ( array_keys( Dashboard::definitions() ) as $tool ) {
        $args = 'get-dashboard-series' === $tool ? [ 'metric' => 'published_listings' ] : [];
        $ok( Tools::execute( $tool, $args ) );
    }
    $assert( $before_options === SupportSnapshot(), 'Ordinary reads do not modify options/product state.' );
    $ok( Tools::execute( 'get-site-health-report' ) );
    $assert( 0 === $state->http, 'Ordinary diagnostic reads never attempt HTTP probes or refreshes.' );
    remove_filter( 'pre_http_request', $http_observer );
    wp_set_current_user( $users['owner'] );
    for ( $j = 0; $j < 1002; ++$j ) {
        $create_order( 'owner', 'USD', '0.01' );
    }
    $query_start  = $wpdb->num_queries;
    $time_start   = microtime( true );
    $bounded_feed = $ok( Tools::execute( 'list-dashboard-activity', [ 'type' => 'payment', 'per_page' => 100 ] ) );
    $feed_queries = $wpdb->num_queries - $query_start;
    $feed_seconds = microtime( true ) - $time_start;
    $assert( $feed_queries <= 50 && $feed_seconds < 5, '1005-record native order fixture meets the 50-query/5-second local budget.' );
    $assert( true === $bounded_feed['truncated'] && 1000 === $bounded_feed['window_total'] && 100 === count( $bounded_feed['items'] ), 'Large feed exposes honest 1000-record window and bounded page.' );
    $error( Tools::execute( 'list-dashboard-activity', [ 'per_page' => 101 ] ), 'validation_failed' );
    wp_set_current_user( $admin );
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
    $reminder                = $ok( wp_insert_post( [ 'post_type' => ATBDP_POST_TYPE, 'post_author' => $admin, 'post_title' => $prefix, 'post_status' => 'publish' ], true ) );
    $posts['admin-reminder'] = $reminder;
    update_post_meta( $reminder, '_expiry_date', current_datetime()->modify( '+1 day' )->format( 'Y-m-d H:i:s' ) );
    $busy = $ok( Tools::execute( 'list-dashboard-activity', [ 'per_page' => 100 ] ) );
    $assert( $busy['truncated'] && in_array( $reminder, array_column( $busy['upcoming']['items'], 'object_id' ), true ), 'A full past-event window cannot suppress upcoming expiry reminders.' );
    $future = $create_order( 'owner', 'USD', '1.00' );
    $wpdb->query( $wpdb->prepare( 'UPDATE ' . Orders::tables()[0] . ' SET created_at=FROM_UNIXTIME(%d) WHERE id=%d', time() + 3600, $future ) );
    $past = $ok( Tools::execute( 'list-dashboard-activity', [ 'type' => 'payment', 'per_page' => 100 ] ) );
    $assert( ! in_array( $future, array_column( $past['items'], 'object_id' ), true ), 'Past activity excludes future native timestamps even inside the selected local date.' );
    $trim = new ReflectionMethod( \Directorist\MCP\Dashboard_Data::class, 'trim' );
    $trim->setAccessible( true );
    $tied = [ \Directorist\MCP\Dashboard_Data::event( 'order-paid', 10, 'payment', '2026-01-01 00:00:00' ), \Directorist\MCP\Dashboard_Data::event( 'order-paid', 2, 'payment', '2026-01-01 00:00:00' ) ];
    $trim->invokeArgs( null, [ &$tied ] );
    $assert( [ 2, 10 ] === array_column( $tied, 'object_id' ), 'Tie sorting agrees with native numeric cursor order before applying the window limit.' );
    $ability = wp_get_ability( 'directorist/get-site-health-report' );
    $assert( (bool) $ability, 'Typed Site Health ability registers through the shared inventory.' );
    $ok( $ability->execute( [ 'format' => 'json' ] ) );
    $guarded_ability        = wp_get_ability( 'directorist/read-fixture-inspect' );
    $state->permission_mode = 'write';
    $assert( is_wp_error( $guarded_ability->execute( [] ) ) && false === get_option( 'directorist_mcp_read_violation_fixture', false ), 'Direct ability authorization also guards permission callbacks.' );
    $state->permission_mode = 'safe';
    update_option( 'atbdp_option', array_merge( (array) $saved_options, [ 'mcp_enabled' => false ] ) );
    $error( Tools::execute( 'get-dashboard-overview' ), 'mcp_disabled' );
    $error( Tools::execute( 'get-site-health-report' ), 'mcp_disabled' );
    $assert( is_wp_error( $ability->execute( [ 'format' => 'json' ] ) ), 'Alternate direct ability respects live disabled state.' );
    WP_CLI::log( 'Bounded 1005-order fixture: ' . $feed_queries . ' queries, ' . round( $feed_seconds, 3 ) . ' seconds.' );
    WP_CLI::success( 'Directorist MCP dashboard/health: ' . $checks . ' assertions passed.' );
} finally {
    wp_set_current_user( $admin ?? $saved_user );
    foreach ( $order_ids as $id ) {
        $wpdb->delete( Orders::tables()[0], [ 'id' => $id ] );
    }
    foreach ( $posts as $id ) {
        wp_delete_post( $id, true );
    }
    foreach ( $users as $id ) {
        wp_delete_user( $id );
    }
    foreach ( $roles as $role ) {
        remove_role( $role );
    }
    update_option( 'timezone_string', $saved_timezone );
    update_option( 'atbdp_option', $saved_options );
    if ( null === $saved_epoch ) {
        delete_option( 'directorist_mcp_epoch' );
    } else {
        update_option( 'directorist_mcp_epoch', $saved_epoch, false );
    }
    if ( ! $saved_cron ) {
        wp_clear_scheduled_hook( 'directorist_mcp_cleanup' );
    }
    delete_option( 'directorist_mcp_read_violation_fixture' );
    remove_action( 'directorist_mcp_register', $register );
    remove_filter( 'debug_information', $debug_filter );
    remove_filter( 'pre_wp_mail', $suppress_mail );
    if ( isset( $http_observer ) ) {
        remove_filter( 'pre_http_request', $http_observer );
    }
    $property->setValue( null, $old_registry );
    wp_set_current_user( $saved_user );
    \Directorist\MCP\Write_Guard::unlock();
    $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
}

function SupportSnapshot() {
    global $wpdb;
    return hash( 'sha256', wp_json_encode( $wpdb->get_results( "SELECT option_name,option_value FROM {$wpdb->options} ORDER BY option_name", ARRAY_A ) ) );
}
