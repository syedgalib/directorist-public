<?php
namespace Directorist\MCP;

defined( 'ABSPATH' ) || exit;

/** Versioned, local-PHP registration. Registration is never authorization. */
class Registry {
    const API_VERSION = 1;

    // Additive read-adapter contract; existing API 1 descriptors retain their meaning.
    const READ_ADAPTER_VERSION = 1;

    private static $instance;

    private $tools = [];

    private $extensions = [];

    private $fields = [];

    private $read_adapters = [];

    private $policies = [];

    private $errors = [];

    private $sealed = false;

    public static function instance() {
        if ( ! self::$instance ) {
            self::$instance        = new self();
            self::$instance->tools = Catalog::core_definitions();
            global $wp_filter;
            $hook    = $wp_filter['directorist_mcp_register'] ?? null;
            $wrapped = [];
            if ( $hook ) {
                foreach ( $hook->callbacks as $priority => $callbacks ) {
                    foreach ( $callbacks as $id => $callback ) {
                        $original                                        = $callback['function'];
                        $wrapper                                         = static function ( ...$args ) use ( $original ) {
                            try {
                                return call_user_func_array( $original, $args );
                            } catch ( \Throwable $error ) {
                                self::$instance->errors[] = [ 'code' => 'registration_failed' ];
                            }
                        };
                        $wrapped[]                                       = [ $priority, $id, $original, $wrapper ];
                        $hook->callbacks[ $priority ][ $id ]['function'] = $wrapper;
                    }
                }
            }
            try {
                do_action( 'directorist_mcp_register', self::$instance );
            } catch ( \Throwable $error ) {
                self::$instance->errors[] = [ 'code' => 'registration_failed' ];
            } finally {
                foreach ( $wrapped as $entry ) {
                    list( $priority, $id, $original, $wrapper ) = $entry;
                    if ( isset( $hook->callbacks[ $priority ][ $id ] ) && $hook->callbacks[ $priority ][ $id ]['function'] === $wrapper ) {
                        $hook->callbacks[ $priority ][ $id ]['function'] = $original;
                    }
                }
            }
            self::$instance->sealed = true;
            foreach ( self::$instance->tools as $name => &$definition ) {
                if ( empty( $definition['owner'] ) && in_array( $definition['group'], [ 'dashboard', 'site-health-info' ], true ) ) {
                    $definition['output_schema'] = Read_Contracts::schema( $name, self::$instance->read_adapters );
                }
            }
        }
        return self::$instance;
    }

    private function register( callable $callback, $owner ) {
        try {
            Support::check( ! $this->sealed, __( 'MCP registration has closed.', 'directorist' ), 'registration_failed' );
            return $callback();
        } catch ( \Throwable $error ) {
            $this->errors[] = [ 'owner' => is_string( $owner ) ? sanitize_key( $owner ) : '', 'code' => $error instanceof Failure ? $error->reason : 'registration_failed' ];
            return new \WP_Error( 'directorist_mcp_registration_failed', __( 'Invalid MCP registration. Check the published contract.', 'directorist' ) );
        }
    }

    public function register_extension( array $d ) {
        return $this->register(
            function () use ( $d ) {
                $owner = $d['owner'] ?? '';
                Support::check( is_string( $owner ) && preg_match( '/^[a-z][a-z0-9-]{1,39}$/', $owner ) && 'directorist' !== $owner && ! isset( $this->extensions[ $owner ] ), __( 'Invalid or duplicate MCP owner.', 'directorist' ) );
                foreach ( [ 'plugin', 'label', 'version', 'contract_version', 'capability' ] as $key ) {
                    Support::check( ! empty( $d[ $key ] ) && is_string( $d[ $key ] ), __( 'The extension descriptor is incomplete.', 'directorist' ) );
                }
                Support::check( false === strpos( $d['plugin'], '..' ) && preg_match( '#^[a-zA-Z0-9_./-]+\.php$#', $d['plugin'] ) && self::API_VERSION === ( $d['api_version'] ?? null ), __( 'Unsupported MCP extension API or plugin identity.', 'directorist' ) );
                Support::check( version_compare( ATBDP_VERSION, $d['requires_core'] ?? ATBDP_VERSION, '>=' ), __( 'The extension requires a newer Directorist version.', 'directorist' ) );
                Support::check( is_callable( $d['availability'] ?? null ) && is_callable( $d['admission'] ?? null ), __( 'Availability and narrow admission callbacks are required.', 'directorist' ) );
                $this->extensions[ $owner ] = $d;
                return true;
            }, $d['owner'] ?? ''
        );
    }

    private function owner( $owner ) {
        Support::check( isset( $this->extensions[ $owner ] ), __( 'Register the MCP owner first.', 'directorist' ) );
    }

    public function register_tool( array $d ) {
        return $this->register(
            function () use ( $d ) {
                $owner = $d['owner'] ?? '';
                $this->owner( $owner );
                $action = $d['action'] ?? '';
                Support::check( is_string( $action ) && preg_match( '/^[a-z][a-z0-9-]{1,49}$/', $action ), __( 'Invalid MCP action name.', 'directorist' ) );
                $name = $owner . '-' . $action;
                Support::check( strlen( 'directorist/' . $name ) <= 64, __( 'The ability name exceeds the supported length.', 'directorist' ) );
                Support::check( ! isset( $this->tools[ $name ] ), __( 'An MCP name is already owned.', 'directorist' ) );
                foreach ( [ 'permission', 'discover', 'project' ] as $callback ) {
                    Support::check( is_callable( $d[ $callback ] ?? null ), __( 'Explicit permissions and output projection are required.', 'directorist' ) );
                }
                Support::check( isset( $d['write'] ) && is_bool( $d['write'] ) && ! empty( $d['description'] ) && ! empty( $d['contract_version'] ), __( 'The tool descriptor is incomplete.', 'directorist' ) );
                self::schema( $d['schema'] ?? [] );
                self::schema( $d['output_schema'] ?? [] );
                Support::check( 'object' === $d['output_schema']['type'] && 'object' === $d['schema']['type'] && false === ( $d['schema']['additionalProperties'] ?? null ), __( 'Tool input must be a closed object.', 'directorist' ) );
                Support::check( in_array( $d['domain'] ?? '', [ 'extension', 'settings', 'directories', 'listings', 'taxonomies', 'orders', 'dashboard', 'site_health_info' ], true ), __( 'An explicit MCP domain is required.', 'directorist' ) );
                Support::check( ! in_array( $d['domain'], [ 'dashboard', 'site_health_info' ], true ) || ! $d['write'], __( 'Diagnostic domains are read-only.', 'directorist' ) );
                if ( in_array( $d['domain'], [ 'dashboard', 'site_health_info' ], true ) ) {
                    Read_Contributions::validate_inputs( $d['schema'], $d['domain'] );
                }
                if ( 'site_health_info' === $d['domain'] ) {
                    Read_Contributions::validate_schema( $d['output_schema'] );
                }
                if ( $d['write'] ) {
                    self::schema( $d['preview_schema'] ?? [] );
                    Support::check( is_callable( $d['prepare'] ?? null ) && is_array( $d['tables'] ?? null ) && is_callable( $d['invalidate'] ?? null ), __( 'Mutation planning, declared stores and cache invalidation are required.', 'directorist' ) );
                    if ( ! empty( $d['external_effects'] ) ) {
                        Support::check( true === ( $d['idempotent'] ?? false ) && is_callable( $d['dispatch'] ?? null ) && is_callable( $d['reconcile'] ?? null ) && is_callable( $d['verify_record'] ?? null ) && empty( $d['bulk'] ), __( 'External tools require durable provider idempotency/reconciliation and a single explicit action.', 'directorist' ), 'unsupported_operation' );
                        Support::check( ! isset( $this->tools[ $name . '-operation' ] ) && strlen( 'directorist/' . $name . '-operation' ) <= 64, __( 'The operation reader name is unavailable.', 'directorist' ) );
                    }
                    Support::check( ! array_intersect_key( (array) $d['schema']['properties'], self::mutation_controls() ), __( 'Core mutation control names are reserved.', 'directorist' ) );
                    $d['schema']['properties']        = (array) $d['schema']['properties'] + self::mutation_controls();
                    $d['output_schema']['properties'] = (array) $d['output_schema']['properties'] + [ 'success' => [ 'type' => 'boolean' ], 'committed' => [ 'type' => [ 'boolean', 'string' ] ], 'idempotent_replay' => [ 'type' => 'boolean' ] ];
                } else {
                    Support::check( is_callable( $d['read'] ?? null ), __( 'A reader is required.', 'directorist' ) );
                }
                $d['group'] = $d['group'] ?? $owner;
                Support::check( is_string( $d['group'] ) && strlen( $d['group'] ) >= 1 && strlen( $d['group'] ) <= 80 && is_string( $d['description'] ) && strlen( $d['description'] ) <= 2000 && is_string( $d['contract_version'] ), __( 'Invalid group, description or contract version.', 'directorist' ) );
                if ( ! empty( $d['bulk'] ) ) {
                    Support::check( $d['write'] && true === $d['bulk'] && ! isset( $this->tools[ $owner . '-bulk-' . $action ] ), __( 'Invalid bulk registration.', 'directorist' ) );
                    $bulk                = $d;
                    $bulk['bulk_of']     = $name;
                    $bulk['description'] = __( 'Confirmed bounded batch: ', 'directorist' ) . $d['description'];
                    $item                = $d['schema'];
                    foreach ( array_keys( self::mutation_controls() ) as $control ) {
                        if ( 'expected_version' !== $control ) {
                            unset( $item['properties'][ $control ] );
                        }
                    }
                    $item['properties']['client_ref'] = [ 'type' => 'string', 'maxLength' => 64, 'minLength' => 1 ];
                    $item['required'][]               = 'client_ref';
                    $bulk['schema']                   = [ 'type' => 'object', 'properties' => [ 'items' => [ 'type' => 'array', 'minItems' => 1, 'maxItems' => 50, 'items' => $item ] ] + self::mutation_controls(), 'required' => [ 'items' ], 'additionalProperties' => false ];
                    unset( $bulk['output_schema'] );
                    $this->tools[ $owner . '-bulk-' . $action ] = $bulk;
                }
                $this->tools[ $name ] = $d;
                if ( ! empty( $d['external_effects'] ) ) {
                    $this->tools[ $name . '-operation' ] = [
                        'owner'            => $owner,
                        'domain'           => 'extension',
                        'group'            => $d['group'],
                        'write'            => false,
                        'contract_version' => $d['contract_version'],
                        'description'      => __( 'Read the original external operation without dispatching or repeating its writer.', 'directorist' ),
                        'schema'           => [ 'type' => 'object', 'properties' => [ 'operation_id' => [ 'type' => 'string', 'maxLength' => 36 ] ], 'required' => [ 'operation_id' ], 'additionalProperties' => false ],
                        'output_schema'    => Extension_Operations::output_schema( $d ),
                        'discover'         => $d['discover'],
                        'permission'       => static function () { return true;
                        },
                        'read'             => static function ( $args ) use ( $name ) { return Extension_Operations::read( $name, $args );
                        },
                        'project'          => static function ( $value ) { return $value;
                        },
                    ];
                }
                return true;
            }, $d['owner'] ?? ''
        );
    }

    public static function mutation_controls() {
        $s = [ 'type' => 'string', 'maxLength' => 256 ];
        return [ 'dry_run' => [ 'type' => 'boolean' ], 'confirm_token' => $s, 'idempotency_key' => $s, 'expected_version' => $s ];
    }

    /** Bounded schemas only; no executable client paths or implicit open objects. */
    public static function schema( array $s, $depth = 0 ) {
        Support::check( $depth <= 10 && isset( $s['type'] ) && in_array( $s['type'], [ 'object', 'array', 'string', 'integer', 'number', 'boolean', 'null' ], true ), __( 'Invalid MCP JSON schema.', 'directorist' ) );
        if ( 'object' === $s['type'] ) {
            Support::check( false === ( $s['additionalProperties'] ?? null ) && isset( $s['properties'] ) && count( (array) $s['properties'] ) <= 100, __( 'Objects must declare bounded properties.', 'directorist' ) );
            foreach ( (array) $s['properties'] as $key => $child ) {
                Support::check( ! preg_match( '/password|secret|credential|api_key/i', $key ), __( 'Secret properties are excluded from MCP schemas.', 'directorist' ) );
                self::schema( $child, $depth + 1 );
            }
            Support::check( ! array_diff( $s['required'] ?? [], array_keys( (array) $s['properties'] ) ), __( 'Invalid required fields.', 'directorist' ) );
        } elseif ( 'array' === $s['type'] ) {
            Support::check( isset( $s['maxItems'], $s['items'] ) && is_int( $s['maxItems'] ) && $s['maxItems'] >= 0 && $s['maxItems'] <= 100, __( 'Arrays must declare a maximum of 100 items.', 'directorist' ) );
            self::schema( $s['items'], $depth + 1 );
        } elseif ( 'string' === $s['type'] ) {
            Support::check( isset( $s['enum'] ) || ( isset( $s['maxLength'] ) && $s['maxLength'] > 0 && $s['maxLength'] <= 65536 ), __( 'Strings must declare a length bound or enum.', 'directorist' ) );
        }
    }

    public function register_field_adapter( array $d ) {
        return $this->register(
            function () use ( $d ) {
                $this->owner( $d['owner'] ?? '' );
                $domain = $d['domain'] ?? '';
                $key    = $d['key'] ?? '';
                Support::check( in_array( $domain, [ 'settings', 'builder', 'listings', 'taxonomies', 'orders', 'order_items', 'refund_providers' ], true ), __( 'Unsupported adapter domain.', 'directorist' ) );
                Support::check( is_string( $key ) && preg_match( '/^[a-z][a-z0-9_-]{1,79}$/', $key ) && ! isset( $this->fields[ $domain ][ $key ] ), __( 'Invalid or duplicate adapter identity.', 'directorist' ) );
                Support::check( false === ( $d['sensitive'] ?? null ) && ! preg_match( '/password|secret|token|credential|api_key|mcp_enabled/i', $key ), __( 'Sensitive MCP fields are excluded.', 'directorist' ) );
                foreach ( [ 'permission', 'read', 'validate' ] as $callback ) {
                    Support::check( is_callable( $d[ $callback ] ?? null ), __( 'Field adapters require explicit permissions, reads and validation.', 'directorist' ) );
                }
                Support::check( ! empty( $d['contract_version'] ) && array_key_exists( 'default', $d ), __( 'Field defaults and versions are required.', 'directorist' ) );
                self::schema( $d['schema'] ?? [] );
                if ( ! empty( $d['writable'] ) ) {
                    Support::check( is_callable( $d['write'] ?? null ) && is_array( $d['tables'] ?? null ) && is_callable( $d['invalidate'] ?? null ) && ! empty( $d['storage_adapter'] ), __( 'Writable adapters need complete persistence contracts.', 'directorist' ) );
                }
                if ( 'refund_providers' === $domain ) {
                    foreach ( [ 'dispatch', 'reconcile' ] as $callback ) {
                        Support::check( is_callable( $d[ $callback ] ?? null ), __( 'Providers require dispatch and reconciliation.', 'directorist' ) );
                    }
                    Support::check( true === ( $d['idempotent'] ?? false ), __( 'Provider duplicate protection is required.', 'directorist' ) );
                }
                $this->fields[ $domain ][ $key ] = $d;
                return true;
            }, $d['owner'] ?? ''
        );
    }

    public function register_policy( array $d ) {
        return $this->register(
            function () use ( $d ) {
                $this->owner( $d['owner'] ?? '' );
                Support::check( is_callable( $d['check'] ?? null ) && ! empty( $d['key'] ) && ! isset( $this->policies[ $d['owner'] . ':' . $d['key'] ] ), __( 'Invalid or duplicate restriction policy.', 'directorist' ) );
                $this->policies[ $d['owner'] . ':' . $d['key'] ] = $d;
                return true;
            }, $d['owner'] ?? ''
        );
    }

    /** Register a bounded, explicitly public, read-only dashboard or diagnostic source. */
    public function register_read_adapter( array $d ) {
        return $this->register(
            function () use ( $d ) {
                $this->owner( $d['owner'] ?? '' );
                $domain = $d['domain'] ?? '';
                Support::check( in_array( $domain, [ 'dashboard', 'site_health_info' ], true ) && self::READ_ADAPTER_VERSION === ( $d['api_version'] ?? null ), __( 'Unsupported read-adapter contract.', 'directorist' ) );
                Support::check( isset( $d['key'] ) && is_string( $d['key'] ) && preg_match( '/^[a-z][a-z0-9-]{1,39}$/', $d['key'] ), __( 'Invalid read-adapter key.', 'directorist' ) );
                $key = $d['owner'] . '-' . $d['key'];
                Support::check( ! isset( $this->read_adapters[ $domain ][ $key ] ), __( 'A read-adapter key is already owned.', 'directorist' ) );
                foreach ( [ 'label', 'contract_version', 'source_version', 'units', 'range_semantics' ] as $field ) {
                    Support::check( ! empty( $d[ $field ] ) && is_string( $d[ $field ] ) && strlen( $d[ $field ] ) <= 256 && null !== Read_Support::safe_value( $d[ $field ] ), __( 'Incomplete or unsafe read-adapter metadata.', 'directorist' ) );
                }
                Support::check( 'public' === ( $d['sensitivity'] ?? null ) && empty( $d['private'] ) && ! isset( $d['write'] ) && 'none' === ( $d['cache'] ?? null ), __( 'Read adapters must be explicitly public and uncached without a writer.', 'directorist' ) );
                Support::check( in_array( $d['kind'] ?? '', 'dashboard' === $domain ? [ 'overview', 'metric', 'guidance' ] : [ 'section' ], true ), __( 'Unsupported contribution kind.', 'directorist' ) );
                Support::check( is_array( $d['link_hosts'] ?? [] ) && count( $d['link_hosts'] ?? [] ) <= 10, __( 'Declare at most ten public guidance hosts.', 'directorist' ) );
                foreach ( $d['link_hosts'] ?? [] as $host ) {
                    Support::check( 'dashboard' === $domain && 'guidance' === $d['kind'] && is_string( $host ) && preg_match( '/^[a-z0-9-]+(?:\.[a-z0-9-]+)+$/', $host ) && ! preg_match( '/(?:\.local|\.test|\.invalid|\.internal|\.localhost|\.[0-9]+)$/', $host ), __( 'Only registered public guidance hosts are permitted.', 'directorist' ) );
                }
                Support::check( ! empty( $d['scopes'] ) && is_array( $d['scopes'] ) && ! array_diff( $d['scopes'], 'dashboard' === $domain ? [ 'site', 'own' ] : [ 'site', 'network' ] ), __( 'Explicit contribution scopes are required.', 'directorist' ) );
                Support::check( isset( $d['filters'] ) && is_array( $d['filters'] ) && ! array_diff( $d['filters'], [ 'directory_id', 'period', 'start_date', 'end_date', 'currency', 'compare_previous', 'series_kind', 'page', 'per_page' ] ), __( 'Declare supported read filters.', 'directorist' ) );
                foreach ( [ 'permission', 'availability', 'read', 'project' ] as $callback ) {
                    Support::check( is_callable( $d[ $callback ] ?? null ), __( 'Read-adapter callbacks are required.', 'directorist' ) );
                }
                self::schema( $d['output_schema'] ?? [] );
                Support::check( 'object' === $d['output_schema']['type'], __( 'Contribution output must be a closed typed object.', 'directorist' ) );
                Read_Contributions::validate_schema( $d['output_schema'] );
                $registered = $this->read_adapters[ $domain ] ?? [];
                Support::check( count( $registered ) < 100 && strlen( wp_json_encode( array_column( $registered, 'output_schema' ) ) ) + strlen( wp_json_encode( $d['output_schema'] ) ) <= 262144, __( 'Read contributions exceed the bounded discovery inventory.', 'directorist' ) );
                $d['id']                                = $key;
                $this->read_adapters[ $domain ][ $key ] = $d;
                return true;
            }, $d['owner'] ?? ''
        );
    }

    public function read_adapters( $domain, array $args ) {
        $out = [];
        foreach ( $this->read_adapters[ $domain ] ?? [] as $key => $d ) {
            try {
                $scope = $args['scope'] ?? 'site';
                if ( $this->admitted( $d['owner'] ) && in_array( $scope, $d['scopes'], true ) && true === Support::extension_callback( $d['permission'], $args, get_current_user_id() ) ) {
                    $out[ $key ] = $d;
                }
            } catch ( \Throwable $error ) {
                continue;
            }
        }
        return $out;
    }

    public function active( $owner ) {
        if ( ! isset( $this->extensions[ $owner ] ) ) {
            return false;
        }
        $d      = $this->extensions[ $owner ];
        $active = in_array( $d['plugin'], (array) get_option( 'active_plugins', [] ), true ) || array_key_exists( $d['plugin'], (array) get_site_option( 'active_sitewide_plugins', [] ) );
        foreach ( $d['requires'] ?? [] as $plugin ) {
            $active = $active && ( in_array( $plugin, (array) get_option( 'active_plugins', [] ), true ) || array_key_exists( $plugin, (array) get_site_option( 'active_sitewide_plugins', [] ) ) );
        }
        try {
            return $active && true === Support::extension_callback( $d['availability'] );
        } catch ( \Throwable $error ) {
            return false;
        }
    }

    public function admitted( $owner ) {
        if ( ! $this->active( $owner ) ) {
            return false;
        }
        $d = $this->extensions[ $owner ];
        try {
            return current_user_can( $d['capability'] ) && true === Support::extension_callback( $d['admission'], get_current_user_id() );
        } catch ( \Throwable $error ) {
            return false;
        }
    }

    public function admission() {
        foreach ( array_keys( $this->extensions ) as $owner ) {
            if ( $this->admitted( $owner ) ) {
                return true;
            }
        }
        return false;
    }

    public function definitions() {
        return $this->tools;
    }

    public function fields( $domain, $context = null ) {
        $out = [];
        foreach ( $this->fields[ $domain ] ?? [] as $key => $d ) {
            try {
                if ( $this->admitted( $d['owner'] ) && true === Support::extension_callback( $d['permission'], $context, 'read' ) ) {
                    $out[ $key ] = $d;
                }
            } catch ( \Throwable $error ) {
                continue;
            }
        }
        return $out;
    }

    public function permission( $name, array $args, $discover = false ) {
        $d = $this->tools[ $name ];
        if ( isset( $d['owner'] ) ) {
            Support::check( $this->admitted( $d['owner'] ), __( 'This extension is unavailable. Rediscover tools.', 'directorist' ), 'extension_unavailable' );
            if ( isset( $d['bulk_of'] ) ) {
                Support::check( true === Support::extension_callback( $d['discover'], [], get_current_user_id() ), __( 'This batch is not permitted.', 'directorist' ), 'forbidden' );
            }
            $domain = isset( $d['bulk_of'] ) ? 'extension' : $d['domain'];
            if ( in_array( $domain, [ 'settings', 'directories' ], true ) ) {
                Access::admin();
            } elseif ( 'listings' === $domain ) {
                Access::listing_domain();
                if ( isset( $args['listing_id'] ) ) {
                    Access::listing( $args['listing_id'], $d['write'] ? 'edit' : 'read' );
                }
            } elseif ( 'taxonomies' === $domain ) {
                Support::check( isset( $args['taxonomy'] ) || $discover, __( 'A taxonomy is required.', 'directorist' ) );
                if ( isset( $args['taxonomy'] ) ) {
                    Access::term_cap( $args['taxonomy'], $d['write'] ? 'edit' : 'read' );
                }
            } elseif ( 'orders' === $domain ) {
                Orders::permission( $d['write'] ? 'extension-order-write' : 'get-order', $args );
            } elseif ( 'dashboard' === $domain ) {
                Dashboard::permission( $name, $args );
                Read_Contributions::object_permission( $args );
            } elseif ( 'site_health_info' === $domain ) {
                Site_Health::permission();
            }
            Support::check( true === Support::extension_callback( $d[ $discover || isset( $d['bulk_of'] ) ? 'discover' : 'permission' ], $args, get_current_user_id() ), __( 'This MCP operation is not permitted.', 'directorist' ), 'forbidden' );
        }
        foreach ( $this->policies as $policy ) {
            if ( empty( $policy['tools'] ) || in_array( $name, $policy['tools'], true ) ) {
                Support::check( $this->active( $policy['owner'] ), __( 'A required policy integration is unavailable.', 'directorist' ), 'extension_unavailable' );
                Support::check( true === Support::extension_callback( $policy['check'], $name, $args, $discover ), __( 'An extension policy restricts this operation.', 'directorist' ), 'forbidden' );
            }
        }
        return true;
    }

    public function available( $name ) {
        try {
            return Read_Guard::run(
                function () use ( $name ) { return $this->permission( $name, [], true );
                }, false, in_array( $this->tools[ $name ]['domain'] ?? '', [ 'dashboard', 'site_health_info' ], true ) || in_array( $this->tools[ $name ]['group'] ?? '', [ 'dashboard', 'site-health-info' ], true )
            );
        } catch ( \Throwable $error ) {
            return false;
        }
    }

    public function fingerprint() {
        $owners = [];
        foreach ( $this->extensions as $owner => $d ) {
            $owners[ $owner ] = [ $d['version'], $d['contract_version'], $this->active( $owner ) ];
        }
        return Support::hash( [ self::API_VERSION, $owners, $this->fields, $this->read_adapters, $this->tools, $this->policies, get_option( 'directorist_mcp_extensions_epoch', '' ), get_site_option( 'directorist_mcp_extensions_epoch', '' ) ] );
    }

    public static function lifecycle() {
        update_option( 'directorist_mcp_extensions_epoch', wp_generate_uuid4(), false );
        update_site_option( 'directorist_mcp_extensions_epoch', wp_generate_uuid4() );
    }

    public function integrations( array $args ) {
        if ( isset( $args['operation_id'] ) ) {
            return Extension_Operations::read_any( $args );
        }
        if ( ! empty( $args['diagnostics'] ) ) {
            Access::admin();
        }
        $items = [];
        foreach ( $this->extensions as $owner => $d ) {
            $tools = [];
            foreach ( $this->tools as $name => $tool ) {
                if ( ( $tool['owner'] ?? '' ) === $owner && $this->available( $name ) ) {
                    $tools[] = 'directorist-' . $name;
                }
            }
            $has_fields = false;
            foreach ( [ 'dashboard', 'site_health_info' ] as $domain ) {
                foreach ( $this->read_adapters( $domain, [ 'scope' => current_user_can( 'manage_options' ) ? 'site' : 'own' ] ) as $adapter ) {
                    if ( 'site_health_info' === $domain && ! Site_Health::available() ) {
                        continue;
                    }
                    $has_fields = $has_fields || $adapter['owner'] === $owner;
                }
            }
            foreach ( $this->fields as $domain => $fields ) {
                foreach ( $this->fields( $domain ) as $field ) {
                    $has_fields = $has_fields || $field['owner'] === $owner;
                }
            }
            if ( ! $tools && ! $has_fields && empty( $args['diagnostics'] ) ) {
                continue;
            }
            if ( ! empty( $args['search'] ) && false === stripos( $d['label'], $args['search'] ) && false === stripos( $owner, $args['search'] ) ) {
                continue;
            }
            if ( isset( $args['group'] ) && ! array_filter(
                $this->tools, static function ( $tool ) use ( $owner, $args ) { return ( $tool['owner'] ?? '' ) === $owner && $tool['group'] === $args['group'];
                }
            ) ) {
                continue;
            }
            $items[] = [ 'owner' => $owner, 'label' => sanitize_text_field( $d['label'] ), 'version' => $d['version'], 'tools' => $tools ] + ( ! empty( $args['diagnostics'] ) ? [ 'available' => $this->active( $owner ), 'reason' => $this->active( $owner ) ? '' : 'dependency_unavailable' ] : [] );
        }
        $result = Support::page( $items, $args );
        if ( ! empty( $args['diagnostics'] ) ) {
            $result['registration_errors'] = $this->errors;
        }
        return $result;
    }

    public function read( $name, array $args ) {
        $d = $this->tools[ $name ];
        if ( 'dashboard' === ( $d['domain'] ?? '' ) ) {
            $args['scope'] = Dashboard::permission( $name, $args );
        }
        $value = Support::unwrap( Support::extension_callback( $d['read'], $args ) );
        $value = Support::unwrap( Support::extension_callback( $d['project'], $value, $args, get_current_user_id() ) );
        if ( 'site_health_info' === ( $d['domain'] ?? '' ) ) {
            $value = Read_Support::safe_value( $value );
        }
        Support::unwrap( rest_validate_value_from_schema( $value, $d['output_schema'] ) );
        return $value;
    }

    public static function ability_output_schema( array $d ) {
        if ( empty( $d['write'] ) ) {
            return $d['output_schema'];
        }
        $preview                          = $d['preview_schema'];
        $preview['properties']['version'] = [ 'type' => 'string', 'maxLength' => 64 ];
        return [ 'type' => 'object',
            'oneOf'     => [ ! empty( $d['external_effects'] ) ? Extension_Operations::output_schema( $d ) : $d['output_schema'], [
                'type'                 => 'object',
                'required'             => [ 'dry_run', 'changes', 'confirm_token', 'expires_in_seconds' ],
                'additionalProperties' => false,
                'properties'           => [
                    'dry_run'            => [ 'type' => 'boolean', 'enum' => [ true ] ],
                    'changes'            => $preview,
                    'confirm_token'      => [ 'type' => 'string', 'maxLength' => 64 ],
                    'expires_in_seconds' => [ 'type' => 'integer', 'enum' => [ 300 ] ],
                    'warnings'           => [ 'type' => 'array', 'maxItems' => 100, 'items' => [ 'type' => 'string', 'maxLength' => 1000 ] ],
                ],
            ]
            ]
        ];
    }

    public function prepare( $name, array $args ) {
        $d = $this->tools[ $name ];
        if ( isset( $d['bulk_of'] ) ) {
            return Tools::bulk( $name, $args, $d['bulk_of'] );
        }
        $plan = Support::unwrap( Support::extension_callback( $d['prepare'], $args ) );
        Support::check( is_array( $plan ) && isset( $plan['state'], $plan['preview'], $plan['execute'] ) && is_callable( $plan['execute'] ) && empty( $plan['bulk'] ), __( 'Invalid extension mutation plan.', 'directorist' ), 'unsupported_operation' );
        Support::unwrap( rest_validate_value_from_schema( $plan['preview'] ?: (object) [], $d['preview_schema'] ) );
        if ( empty( $args['dry_run'] ) ) {
            Support::version( $args['expected_version'] ?? null, Support::hash( $plan['state'] ) );
        }
        $plan['preview']['version'] = Support::hash( $plan['state'] );
        $execute                    = $plan['execute'];
        $plan['confirm']            = true;
        Write_Guard::check_tables( $d['tables'] );
        $plan['tables']     = $d['tables'];
        $plan['invalidate'] = $d['invalidate'];
        $plan['state']      = [ $plan['state'], $this->fingerprint() ];
        $plan['execute']    = function () use ( $d, $execute, $args, $name ) {
            $this->permission( $name, $args );
            $value = Support::unwrap( $execute() );
            $value = Support::unwrap( Support::extension_callback( $d['project'], $value, $args, get_current_user_id() ) );
            Support::unwrap( rest_validate_value_from_schema( $value, $d['output_schema'] ) );
            return Support::result( $value );
        };
        if ( ! empty( $d['external_effects'] ) ) {
            $writer               = $plan['execute'];
            $plan['orchestrated'] = true;
            $plan['execute']      = static function () use ( $name, $args, $d, $writer ) { return Extension_Operations::execute( $name, $args, $d, $writer );
            };
        }
        return $plan;
    }
}
