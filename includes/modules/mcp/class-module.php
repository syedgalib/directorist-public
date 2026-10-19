<?php
namespace Directorist\MCP;

defined( 'ABSPATH' ) || exit;

class Module {
    private static $request_id;

    public static function boot() {
        add_filter( 'atbdp_listing_type_settings_field_list', [ self::class, 'fields' ], 100 );
        add_filter( 'atbdp_listing_type_settings_layout', [ self::class, 'layout' ], 100 );
        add_action( 'plugins_loaded', [ Registry::class, 'instance' ], PHP_INT_MAX );
        add_action( 'activated_plugin', [ Registry::class, 'lifecycle' ] );
        add_action( 'deactivated_plugin', [ Registry::class, 'lifecycle' ] );
        add_action( 'upgrader_process_complete', [ Registry::class, 'lifecycle' ] );
        foreach ( [ 'directorist_before_order_create', 'directorist_mcp_before_native_order_write', 'directorist_mcp_before_native_payment_write', 'directorist_mcp_before_native_refund_write' ] as $hook ) {
            add_action( $hook, [ Write_Guard::class, 'native_lock' ], 0 );
        }
        add_action( 'admin_enqueue_scripts', [ self::class, 'assets' ], 30 );
        add_action( 'rest_api_init', [ self::class, 'admin_routes' ] );
        add_action( 'wp_abilities_api_categories_init', [ self::class, 'category' ] );
        add_action( 'wp_abilities_api_init', [ self::class, 'abilities' ] );
        add_action( 'mcp_adapter_init', [ self::class, 'server' ] );
        add_filter( 'mcp_adapter_tools_list', [ self::class, 'filter_tools' ], 10, 2 );
        add_filter( 'pre_update_option_atbdp_option', [ self::class, 'option_change' ], 10, 2 );
        add_action( 'directorist_mcp_cleanup', [ Write_Guard::class, 'cleanup' ] );
        add_action( 'wp_ajax_save_settings_data', [ self::class, 'before_settings_save' ], 0 );
        foreach ( [ 'wp_ajax_cptm_save_post_type_data', 'wp_ajax_save_post_type_data', 'admin_post_delete_listing_type' ] as $hook ) {
            add_action( $hook, [ Write_Guard::class, 'native_lock' ], 0 );
        }
        add_filter(
            'query', static function ( $sql ) {
                if ( preg_match( '/^\s*(?:INSERT(?:\s+IGNORE)?\s+INTO|REPLACE\s+INTO|UPDATE|DELETE\s+FROM)\s+`?([A-Za-z0-9_]+)`?/i', $sql, $match ) && in_array( $match[1], Orders::tables(), true ) ) {
                    Write_Guard::native_lock();
                }
                return $sql;
            }, 0
        );
        // Acquire before native REST handlers read state used by a subsequent write, after REST authorization.
        add_filter(
            'rest_dispatch_request', static function ( $result, $request, $route ) {
                if ( null === $result && ! in_array( $request->get_method(), [ 'GET', 'HEAD', 'OPTIONS' ], true ) && preg_match( '#^/directorist/v[12]/(?:orders?|admin/payments|checkout)(?:/|$)#', $route ) ) {
                    Write_Guard::native_lock();
                }
                return $result;
            }, 0, 3
        );
        add_action( 'directorist_checkout_validation', [ Write_Guard::class, 'native_lock' ], 0 );
        // Coordinate native content writes with MCP dependency checks and commits.
        add_filter(
            'wp_insert_post_data', static function ( $data ) {
                if ( ATBDP_POST_TYPE === ( $data['post_type'] ?? '' ) ) {
                    Write_Guard::native_lock();
                }
                return $data;
            }, 1
        );
        add_action(
            'add_term_relationship', static function ( $object_id, $tt_id, $taxonomy ) {
                if ( in_array( $taxonomy, [ ATBDP_DIRECTORY_TYPE, ATBDP_CATEGORY, ATBDP_LOCATION, ATBDP_TAGS ], true ) ) {
                    Write_Guard::native_lock();
                    global $wpdb;
                    if ( Module::enabled() && ! $wpdb->get_var( $wpdb->prepare( "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d", $tt_id ) ) ) {
                        wp_die( esc_html__( 'The directory taxonomy changed. Refresh the page before assigning this term.', 'directorist' ), '', [ 'response' => 409 ] );
                    }
                }
            }, 0, 3
        );
        foreach ( [ 'add_post_metadata', 'update_post_metadata', 'delete_post_metadata' ] as $hook ) {
            add_filter(
                $hook, static function ( $check, $id ) {
                    if ( ATBDP_POST_TYPE === get_post_type( $id ) ) {
                        Write_Guard::native_lock();
                    }
                    return $check;
                }, 0, 2
            );
        }
        foreach ( [ 'add_term_metadata', 'update_term_metadata', 'delete_term_metadata' ] as $hook ) {
            add_filter(
                $hook, static function ( $check, $id, $key ) {
                    $term = get_term( $id );
                    if ( $term && ! is_wp_error( $term ) && ( ATBDP_DIRECTORY_TYPE === $term->taxonomy || 0 === strpos( $key, '_directory_type' ) ) ) {
                        Write_Guard::native_lock();
                    }
                    return $check;
                }, 0, 3
            );
        }
        foreach ( [ 'edit_terms', 'pre_delete_term' ] as $hook ) {
            add_action(
                $hook, static function ( $id, $taxonomy ) {
                    if ( in_array( $taxonomy, [ ATBDP_DIRECTORY_TYPE, ATBDP_CATEGORY, ATBDP_LOCATION, ATBDP_TAGS ], true ) ) {
                        Write_Guard::native_lock();
                    }
                }, 0, 2
            );
        }
        add_filter(
            'pre_insert_term', static function ( $term, $taxonomy ) {
                if ( in_array( $taxonomy, [ ATBDP_DIRECTORY_TYPE, ATBDP_CATEGORY, ATBDP_LOCATION, ATBDP_TAGS ], true ) ) {
                    Write_Guard::native_lock();
                }
                return $term;
            }, 0, 2
        );
    }

    public static function request_id() {
        if ( ! self::$request_id ) {
            self::$request_id = wp_generate_uuid4();
        }
        return self::$request_id;
    }

    public static function enabled() {
        return true === get_directorist_option( 'mcp_enabled', false ) || '1' === get_directorist_option( 'mcp_enabled', false );
    }

    public static function dependencies() {
        global $wp_version;
        return version_compare( $wp_version, '6.9', '>=' ) && function_exists( 'wp_register_ability' ) && function_exists( 'wp_register_ability_category' ) && class_exists( '\WP\MCP\Core\McpAdapter' ) && defined( '\WP\MCP\Core\McpAdapter::VERSION' ) && version_compare( \WP\MCP\Core\McpAdapter::VERSION, '0.6.1', '>=' ) && method_exists( '\WP\MCP\Core\McpAdapter', 'create_server' );
    }

    public static function fields( $fields ) {
        $fields['mcp_enabled'] = [
            'type'        => 'mcp-configuration',
            'label'       => __( 'MCP for AI Agents', 'directorist' ),
            'description' => __( 'Connect an AI assistant to Directorist using your WordPress account and permissions.', 'directorist' ),
            'value'       => false,
            'instantSave' => true
        ];
        return $fields;
    }

    public static function layout( $layouts ) {
        $layouts['other_features'] = [
            'label'   => __( 'Other Features', 'directorist' ),
            'icon'    => 'las la-puzzle-piece',
            'submenu' => [ 'mcp' => [ 'label' => __( 'MCP', 'directorist' ), 'sections' => [ 'mcp_connection' => [ 'title' => '', 'fields' => [ 'mcp_enabled' ] ] ] ] ]
        ];
        return $layouts;
    }

    public static function assets() {
        if ( ! \ATBDP_Settings_Panel::in_settings_page() || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        // A source-delivered registry extension: no generated legacy bundle needs rewriting.
        wp_enqueue_script( 'directorist-mcp-settings', ATBDP_URL . 'assets/js/directorist-mcp-settings.js', [ 'directorist-settings-manager' ], ATBDP_VERSION, true );
        wp_enqueue_style( 'directorist-mcp-settings', ATBDP_URL . 'assets/css/directorist-mcp-settings.css', [], ATBDP_VERSION );
        wp_localize_script(
            'directorist-mcp-settings', 'directoristMcpSettings', [
                'statusUrl'    => rest_url( 'directorist/v1/mcp/status' ),
                'templatesUrl' => rest_url( 'directorist/v1/mcp/config-snippets' ),
                'nonce'        => wp_create_nonce( 'wp_rest' ),
                'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
                'ajaxNonce'    => wp_create_nonce( directorist_get_nonce_key() ),
                'nonceKey'     => directorist_get_nonce_key(),
            ]
        );
    }

    public static function before_settings_save() {
        if ( ! current_user_can( 'manage_options' ) || ! directorist_verify_nonce() ) {
            return;
            // Existing handler provides its established error response.
        }
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Decode JSON intact; the exact registered key is checked below and the native handler validates the field allowlist.
        $keys = isset( $_POST['field_list'] ) ? json_decode( wp_unslash( $_POST['field_list'] ), true ) : [];
        if ( in_array( 'mcp_enabled', (array) $keys, true ) ) {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Strict boolean validation below rejects any non-boolean JSON value.
            $value = json_decode( wp_unslash( $_POST['mcp_enabled'] ?? 'false' ), true );
            if ( is_array( $value ) && count( $value ) === 1 ) {
                $value = reset( $value );
            }
            if ( ! is_bool( $value ) || ( $value && ! self::dependencies() ) ) {
                wp_send_json( [ 'status' => [ 'success' => false, 'status_log' => [ 'type' => 'error', 'message' => __( 'A compatible WordPress Abilities API and MCP Adapter are required before enabling MCP.', 'directorist' ) ] ] ] );
            }
            $_POST['mcp_enabled'] = $value ? 'true' : 'false';
        }
        Write_Guard::native_lock();
    }

    public static function option_change( $value, $old ) {
        if ( ! is_array( $value ) ) {
            return $value;
        }
        if ( array_key_exists( 'mcp_enabled', $value ) ) {
            $value['mcp_enabled'] = in_array( $value['mcp_enabled'], [ true, 1, '1', 'true' ], true );
            if ( $value['mcp_enabled'] !== ( $old['mcp_enabled'] ?? false ) ) {
                Support::check( ! Write_Guard::is_mutating(), __( 'MCP tools cannot change MCP access policy.', 'directorist' ), 'sensitive_field' );
                update_option( 'directorist_mcp_epoch', wp_generate_uuid4(), false );
                if ( $value['mcp_enabled'] && ! wp_next_scheduled( 'directorist_mcp_cleanup' ) ) {
                    wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'directorist_mcp_cleanup' );
                }
            }
        }
        return $value;
    }

    public static function admin_routes() {
        $permission = static function () {
            return current_user_can( 'manage_options' ) ? true : new \WP_Error( 'directorist_mcp_forbidden', __( 'Configuration access is required.', 'directorist' ), [ 'status' => rest_authorization_required_code() ] );
        };
        register_rest_route( 'directorist/v1', '/mcp/status', [ 'methods' => 'GET', 'permission_callback' => $permission, 'callback' => [ self::class, 'status' ] ] );
        register_rest_route( 'directorist/v1', '/mcp/config-snippets', [ 'methods' => 'GET', 'permission_callback' => $permission, 'callback' => [ self::class, 'templates' ] ] );
        register_rest_route(
            'directorist/v1', '/mcp/guide', [
                'methods'             => 'GET',
                'permission_callback' => '__return_true',
                'callback'            => static function () {
                    return [
                        'title'       => __( 'Connect to Directorist MCP', 'directorist' ),
                        'endpoint'    => rest_url( 'directorist/mcp' ),
                        'steps'       => [
                            __( 'Ask a site administrator to enable Directorist Settings → Other Features → MCP. Administrators can use Configure to open the MCP Connection panel.', 'directorist' ),
                            __( 'Create a named application password for your own WordPress account from your profile. Never use or share an administrator credential.', 'directorist' ),
                            __( 'Use trusted HTTPS and a compatible HTTP MCP client with an Authorization: Basic header encoding username:application-password, or a compatible local bridge.', 'directorist' ),
                            __( 'Initialize the client, discover the tools, then call directorist-get-context to verify your identity and permissions. Preview consequential changes before executing them.', 'directorist' ),
                            __( 'Revoke the named application password in your WordPress profile to disconnect that client. Disabling Directorist MCP stops Directorist tools but does not revoke credentials for other WordPress APIs.', 'directorist' ),
                        ],
                        'limitations' => __( 'OAuth-only clients are not supported. If application passwords are unavailable, ask the administrator to review the site policy.', 'directorist' )
                    ];
                }
            ]
        );
    }

    public static function status() {
        $groups = [];
        $count  = 0;
        foreach ( Catalog::definitions() as $name => $definition ) {
            if ( Tools::available( $name, $definition ) ) {
                ++$count; $groups[] = $definition['group'];
            }
        }
        return [
            'enabled'                         => self::enabled(),
            'ready'                           => self::dependencies(),
            'abilities_available'             => function_exists( 'wp_register_ability' ),
            'adapter_available'               => class_exists( '\WP\MCP\Core\McpAdapter' ),
            'https'                           => is_ssl() || 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME ),
            'application_passwords_available' => function_exists( 'wp_is_application_passwords_available_for_user' ) && wp_is_application_passwords_available_for_user( wp_get_current_user() ),
            'endpoint_url'                    => rest_url( 'directorist/mcp' ),
            'tools_count'                     => $count,
            'groups'                          => array_values(
                array_unique(
                    array_map(
                        static function ( $group ) { return 'directories' === $group ? 'Directory Builder' : ucwords( str_replace( '-', ' ', $group ) );
                        }, $groups
                    )
                )
            ),
            'current_user_login'              => wp_get_current_user()->user_login,
            'profile_url'                     => admin_url( 'profile.php#application-passwords-section' ),
            'adapter_url'                     => 'https://wordpress.org/plugins/mcp-adapter/',
            'guide_url'                       => rest_url( 'directorist/v1/mcp/guide' )
        ];
    }

    public static function templates() {
        if ( ! self::enabled() || ! self::dependencies() ) {
            return new \WP_Error( 'directorist_mcp_unavailable', __( 'Enable MCP and resolve its dependencies before configuring a client.', 'directorist' ), [ 'status' => 409 ] );
        }
        $url    = rest_url( 'directorist/mcp' );
        $header = 'Basic <base64(username:application-password)>';
        $http   = wp_json_encode( [ 'mcpServers' => [ 'directorist' => [ 'url' => $url, 'headers' => [ 'Authorization' => $header ] ] ] ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        $bridge = wp_json_encode( [ 'mcpServers' => [ 'directorist' => [ 'command' => 'npx', 'args' => [ '-y', '@automattic/mcp-wordpress-remote@0.5.1' ], 'env' => [ 'WP_API_URL' => $url, 'WP_API_USERNAME' => '<username>', 'WP_API_PASSWORD' => '<application-password>', 'OAUTH_ENABLED' => 'false' ] ] ] ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
        return [
            'clients' => [
                [ 'id' => 'claude-code', 'label' => 'Claude Code', 'instructions' => __( 'Add this HTTP MCP server in your client terminal. Treat the copied configuration as a secret.', 'directorist' ), 'snippet' => 'claude mcp add --transport http directorist ' . escapeshellarg( $url ) . ' --header "Authorization: ' . $header . '"' ],
                [ 'id' => 'claude-desktop', 'label' => 'Claude Desktop', 'instructions' => __( 'Use a local WordPress MCP bridge with Node.js installed. Add this entry to your client MCP configuration, then restart the client.', 'directorist' ), 'snippet' => $bridge ],
                [ 'id' => 'cursor', 'label' => 'Cursor', 'instructions' => __( 'Add this entry to your MCP server configuration.', 'directorist' ), 'snippet' => $http ],
                [ 'id' => 'codex', 'label' => 'Codex', 'instructions' => __( 'Use this server URL and Authorization header with an HTTP-capable MCP client.', 'directorist' ), 'snippet' => "URL: {$url}\nTransport: Streamable HTTP\nAuthorization: {$header}" ],
                [ 'id' => 'generic', 'label' => __( 'Other', 'directorist' ), 'instructions' => __( 'Your client must support HTTP with a custom Authorization header or a compatible local bridge. OAuth-only connections are not supported.', 'directorist' ), 'snippet' => $http ],
            ]
        ];
    }

    public static function category() {
        if ( self::enabled() && function_exists( 'wp_register_ability_category' ) ) {
            wp_register_ability_category( 'directorist', [ 'label' => __( 'Directorist', 'directorist' ), 'description' => __( 'Permission-aware directory management.', 'directorist' ) ] );
        }
    }

    public static function abilities() {
        if ( ! self::enabled() || ! self::dependencies() ) {
            return;
        }
        foreach ( Catalog::definitions() as $name => $definition ) {
            wp_register_ability(
                'directorist/' . $name, [
                    'label'               => ucwords( str_replace( '-', ' ', $name ) ),
                    'description'         => $definition['description'],
                    'category'            => 'directorist',
                    'input_schema'        => $definition['schema'],
                    'permission_callback' => static function ( $args = [] ) use ( $name ) {
                        try {
                            return Access::permission( $name, (array) $args );
                        } catch ( Failure $error ) {
                            return $error->as_error();
                        } catch ( \Throwable $error ) {
                            return new \WP_Error( 'directorist_mcp_extension_failed', __( 'An integration could not authorize this operation.', 'directorist' ) );
                        }
                    },
                    'execute_callback'    => static function ( $args = [] ) use ( $name ) {
                        return Tools::execute( $name, (array) $args );
                    },
                    'meta'                => [ 'show_in_rest' => true, 'mcp' => [ 'public' => true ], 'annotations' => [ 'readOnlyHint' => ! $definition['write'], 'destructiveHint' => $definition['write'], 'idempotentHint' => ! $definition['write'], 'openWorldHint' => ! empty( $definition['external_effects'] ) || in_array( $name, [ 'create-order-refund', 'get-order-operation' ], true ) ] ],
                ] + ( isset( $definition['output_schema'] ) ? [ 'output_schema' => Registry::ability_output_schema( $definition ) ] : [] )
            );
        }
    }

    public static function server( $adapter ) {
        if ( ! self::enabled() || ! self::dependencies() || ! is_object( $adapter ) || ! method_exists( $adapter, 'create_server' ) ) {
            return;
        }
        $adapter->create_server(
            'directorist', 'directorist', 'mcp', __( 'Directorist MCP Server', 'directorist' ), __( 'Manage Directorist using your WordPress account permissions.', 'directorist' ), ATBDP_VERSION,
            [ '\WP\MCP\Transport\HttpTransport' ], '\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler', '\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler',
            array_map(
                static function ( $name ) {
                    return 'directorist/' . $name;
                }, array_keys( Catalog::definitions() )
            ), [], [], [ Access::class, 'transport' ]
        );
    }

    public static function filter_tools( $tools, $server ) {
        $definitions = Catalog::definitions();
        return array_values(
            array_filter(
                $tools, static function ( $tool ) use ( $definitions ) {
                    $name = method_exists( $tool, 'get_name' ) ? $tool->get_name() : ( method_exists( $tool, 'getName' ) ? $tool->getName() : ( $tool->name ?? '' ) );
                    if ( 0 !== strpos( $name, 'directorist-' ) ) {
                        return true;
                    }
                    $action = substr( $name, strlen( 'directorist-' ) );
                    return Module::enabled() && isset( $definitions[ $action ] ) && Tools::available( $action, $definitions[ $action ] );
                }
            )
        );
    }
}
