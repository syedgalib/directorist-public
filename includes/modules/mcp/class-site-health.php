<?php
namespace Directorist\MCP;

defined( 'ABSPATH' ) || exit;

/** Safe local equivalents of selected Info fields. Never invokes WP_Debug_Data or health tests. */
class Site_Health {
    const POLICY_VERSION = '1.1';

    public static function definitions() {
        return [
            'discover-site-health-info' => Read_Support::definition( 'site-health-info', 'Discover approved Site Health Info sections and fields without collecting their values.', [ 'section_id' => Read_Support::string_schema() ] + Read_Support::paging_schema() ),
            'get-site-health-info'      => Read_Support::definition( 'site-health-info', 'Read one approved local diagnostic section with strict privacy and current runtime identity.', [ 'section_id' => Read_Support::string_schema(), 'field_keys' => Read_Support::list_schema( 100 ) ] + Read_Support::paging_schema(), [ 'section_id' ] ),
            'get-site-health-report'    => Read_Support::definition( 'site-health-info', 'Return a redacted text or JSON support report without saving or transmitting it.', [ 'section_ids' => Read_Support::list_schema( 10 ), 'format' => Read_Support::enum_schema( [ 'json', 'text' ] ) ] ),
        ];
    }

    public static function available() {
        return is_user_logged_in() && current_user_can( 'manage_options' ) && current_user_can( 'view_site_health_checks' );
    }

    public static function permission() {
        Support::check( self::available(), __( 'Directorist administration and native Site Health access are required.', 'directorist' ), 'forbidden' );
    }

    public static function core_fields() {
        return [
            'wordpress'        => [ 'version' => 'string', 'site_locale' => 'string', 'user_locale' => 'string', 'timezone' => 'string', 'permalink_mode' => 'string', 'environment_type' => 'string', 'https_configured' => 'boolean', 'multisite' => 'boolean' ],
            'runtime'          => [ 'php_version' => 'string', 'sapi' => 'string', 'observed_runtime' => 'string', 'bitness' => 'integer', 'php_memory_limit' => 'string', 'wp_memory_limit' => 'string', 'wp_max_memory_limit' => 'string', 'execution_limit_seconds' => 'integer', 'input_limit_seconds' => 'integer', 'max_input_variables' => 'integer', 'upload_limit' => 'string', 'post_limit' => 'string' ],
            'server'           => [ 'os_family' => 'string', 'architecture' => 'string', 'web_server_family' => 'string', 'curl_version' => 'string', 'openssl_version' => 'string' ],
            'database'         => [ 'engine_version' => 'string', 'driver' => 'string', 'client_version' => 'string', 'max_packet_bytes' => 'integer', 'max_connections' => 'integer' ],
            'themes'           => [ 'id' => 'string', 'name' => 'string', 'version' => 'string', 'category' => 'string', 'update_state' => 'string', 'update_observed_at' => 'string', 'update_age_seconds' => 'integer', 'compatibility' => 'string', 'compatibility_rule' => 'string' ],
            'themes-inactive'  => [ 'id' => 'string', 'name' => 'string', 'version' => 'string', 'category' => 'string', 'update_state' => 'string', 'update_observed_at' => 'string', 'update_age_seconds' => 'integer', 'compatibility' => 'string', 'compatibility_rule' => 'string' ],
            'plugins'          => [ 'id' => 'string', 'name' => 'string', 'version' => 'string', 'category' => 'string', 'update_state' => 'string', 'update_observed_at' => 'string', 'update_age_seconds' => 'integer', 'compatibility' => 'string', 'compatibility_rule' => 'string' ],
            'plugins-inactive' => [ 'id' => 'string', 'name' => 'string', 'version' => 'string', 'category' => 'string', 'update_state' => 'string', 'update_observed_at' => 'string', 'update_age_seconds' => 'integer', 'compatibility' => 'string', 'compatibility_rule' => 'string' ],
            'media'            => [ 'gd_available' => 'boolean', 'gd_version' => 'string', 'imagick_available' => 'boolean', 'imagick_version' => 'string', 'supported_formats' => 'array', 'upload_limit_bytes' => 'integer' ],
            'constants'        => [ 'debug_enabled' => 'boolean', 'debug_display_enabled' => 'boolean', 'debug_log_enabled' => 'boolean', 'cache_enabled' => 'boolean', 'cron_disabled' => 'boolean', 'memory_limit' => 'string' ],
            'filesystem'       => [ 'wordpress_writable' => 'boolean', 'content_writable' => 'boolean', 'uploads_writable' => 'boolean', 'plugins_writable' => 'boolean', 'themes_writable' => 'boolean' ],
            'storage'          => [],
            'directorist'      => [ 'version' => 'string', 'directory_mode' => 'string', 'order_storage_available' => 'boolean', 'registered_integrations' => 'array' ],
            'mcp'              => [ 'enabled' => 'boolean', 'ready' => 'boolean', 'abilities_available' => 'boolean', 'adapter_version' => 'string', 'read_adapter_version' => 'integer', 'transport' => 'string', 'authentication' => 'string', 'auth_available' => 'boolean', 'compatibility' => 'string', 'compatibility_rule' => 'string' ],
        ];
    }

    public static function field_schema( $id, $key, $type ) {
        if ( 'array' === $type ) {
            $item = 'registered_integrations' === $key ? [ 'type' => 'object', 'properties' => [ 'owner' => Read_Support::string_schema(), 'contract_version' => Read_Support::string_schema(), 'compatibility' => Read_Support::string_schema() ], 'additionalProperties' => false ] : Read_Support::string_schema();
            return [ 'type' => 'array', 'items' => $item, 'maxItems' => 100 ];
        }
        return 'string' === $type ? [ 'type' => 'string', 'maxLength' => 2048 ] : [ 'type' => $type ];
    }

    private static function descriptors() {
        $sections = self::core_fields();
        if ( is_multisite() && ! is_super_admin() ) {
            foreach ( [ 'runtime', 'server', 'database', 'themes-inactive', 'plugins', 'plugins-inactive', 'media', 'constants', 'filesystem', 'storage' ] as $id ) {
                unset( $sections[ $id ] );
            }
        }
        $out = [];
        foreach ( $sections as $id => $fields ) {
            $out[ $id ] = [ 'id' => $id, 'label' => ucwords( str_replace( '-', ' ', $id ) ), 'kind' => in_array( $id, [ 'themes', 'themes-inactive', 'plugins', 'plugins-inactive' ], true ) ? 'collection' : 'fields', 'fields' => $fields, 'state' => 'storage' === $id ? 'unavailable' : 'available', 'reason' => 'storage' === $id ? 'no_supported_preexisting_size_snapshot' : null, 'source_scope' => 'site', 'source_version' => 'local-safe-collector-2' ];
        }
        $adapters = Read_Contributions::descriptors( 'site_health_info', [ 'scope' => 'site' ] );
        if ( is_multisite() && is_super_admin() ) {
            $adapters += Read_Contributions::descriptors( 'site_health_info', [ 'scope' => 'network' ] );
        }
        foreach ( $adapters as $key => $d ) {
            if ( in_array( 'network', $d['scopes'], true ) && is_multisite() && ! is_super_admin() ) {
                continue;
            }
            $fields = [];
            foreach ( (array) $d['output_schema']['properties'] as $field => $schema ) {
                $fields[ $field ] = $schema['type'];
            }
            $availability = Read_Contributions::metadata( $d )['state'];
            $out[ $key ]  = [ 'id' => $key, 'label' => $d['label'], 'kind' => 'fields', 'fields' => $fields, 'state' => $availability, 'reason' => 'unavailable' === $availability ? 'source_unavailable' : null, 'source_scope' => in_array( 'site', $d['scopes'], true ) ? 'site' : 'network', 'source_version' => $d['source_version'], 'adapter' => $d ];
        }
        ksort( $out );
        return $out;
    }

    public static function read( $tool, array $args ) {
        self::permission();
        $sections = self::descriptors();
        $base     = Read_Support::envelope( hash_hmac( 'sha256', (string) get_current_blog_id(), wp_salt( 'nonce' ) ) );
        $base    += [ 'source_versions' => [ 'wordpress' => Read_Support::safe_value( get_bloginfo( 'version' ) ), 'directorist' => ATBDP_VERSION, 'collector' => 'local-safe-2' ], 'redaction_policy_version' => self::POLICY_VERSION ];
        if ( isset( $args['section_id'] ) ) {
            Support::check( isset( $sections[ $args['section_id'] ] ), __( 'This section is unsupported or forbidden.', 'directorist' ), 'forbidden' );
        }
        if ( 'discover-site-health-info' === $tool ) {
            $items = [];
            if ( isset( $args['section_id'] ) ) {
                $d = $sections[ $args['section_id'] ];
                ksort( $d['fields'] );
                foreach ( $d['fields'] as $key => $type ) {
                    $schema  = $d['adapter']['output_schema']['properties'][ $key ] ?? self::field_schema( $d['id'], $key, $type );
                    $items[] = [ 'key' => $key, 'label' => ucwords( str_replace( '_', ' ', $key ) ), 'type' => $type, 'schema' => $schema, 'units' => self::units( $key ), 'sensitivity' => 'public', 'nullable' => true ];
                }
            } else {
                foreach ( $sections as $d ) {
                    unset( $d['fields'], $d['adapter'] );
                    $items[] = $d;
                }
            }
            return $base + Support::page( $items, $args ) + [ 'limits' => [ 'page_default' => 20, 'page_maximum' => 100, 'report_sections' => 10, 'report_items' => 1000, 'report_bytes' => 262144, 'scalar_characters' => 2048 ], 'report_formats' => [ 'json', 'text' ] ];
        }
        if ( 'get-site-health-info' === $tool ) {
            $d = $sections[ $args['section_id'] ];
            // Validate explicit selection before any collector runs.
            $keys = isset( $args['field_keys'] ) ? Read_Support::selected( $args, 'field_keys', [], array_keys( $d['fields'] ), 100 ) : array_keys( $d['fields'] );
            return Read_Support::bounded( $base + [ 'section' => self::section( $d, $args, $keys ) ] );
        }
        $selected = Read_Support::selected( $args, 'section_ids', array_values( array_intersect( [ 'wordpress', 'runtime', 'directorist', 'mcp' ], array_keys( $sections ) ) ), array_keys( $sections ), 10 );
        $report   = [];
        $count    = 0;
        foreach ( $selected as $key ) {
            $d       = $sections[ $key ];
            $section = self::section( $d, [ 'page' => 1, 'per_page' => 100 ], array_keys( $d['fields'] ), true );
            $count  += $section['total'];
            Support::check( $count <= 1000, __( 'Report exceeds 1000 fields/items. Select fewer sections.', 'directorist' ) );
            $report[] = $section;
        }
        $payload = $base + [ 'sections' => $report,
            'coverage'                  => [ 'included' => $selected,
                'complete'                              => ! array_filter(
                    $report, static function ( $section ) {
                        if ( 'unavailable' === $section['state'] ) {
                            return true;
                        }
                        foreach ( $section['items'] as $item ) {
                            if ( isset( $item['state'] ) ? ! in_array( $item['state'], [ 'available', 'empty' ], true ) : in_array( null, $item, true ) ) {
                                return true;
                            }
                        }
                        return false;
                    }
                ),
                'omissions'                             => [ 'private_and_unapproved_fields_are_excluded' ]
            ]
        ];
        Read_Support::bounded( $payload, 262144 );
        if ( 'json' === ( $args['format'] ?? 'text' ) ) {
            return $payload;
        }
        $lines = [ 'Directorist Site Health Info', 'Generated: ' . $base['generated_at'], 'Redaction policy: ' . self::POLICY_VERSION, 'Sources: ' . wp_json_encode( $base['source_versions'] ), 'Scope: ' . $base['scope'], 'Coverage complete: ' . ( $payload['coverage']['complete'] ? 'yes' : 'no' ) ];
        foreach ( $report as $section ) {
            $lines[] = "\n[" . $section['id'] . '] state=' . $section['state'] . ' as_of=' . $section['as_of'];
            foreach ( $section['items'] as $item ) {
                $lines[] = wp_json_encode( $item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
            }
            if ( $section['reason'] ) {
                $lines[] = 'Reason: ' . $section['reason'];
            }
        }
        return Read_Support::bounded( $base + [ 'format' => 'text', 'content' => implode( "\n", $lines ), 'coverage' => $payload['coverage'] ], 262144 );
    }

    private static function section( array $d, array $args, array $keys, $report = false ) {
        $id     = $d['id'];
        $state  = 'available';
        $reason = null;
        if ( isset( $d['adapter'] ) ) {
            $source = Read_Contributions::read( $d['adapter'], [ 'scope' => $d['source_scope'] ] );
            $values = $source['data'] ?? [];
            $state  = $source['state'];
            $reason = $source['reason'];
        } else {
            try {
                $values = self::collect( $id );
            } catch ( \Throwable $error ) {
                if ( in_array( $id, [ 'wordpress', 'runtime' ], true ) ) {
                    throw new Failure( 'dependency_unavailable', __( 'A required diagnostic collector is unavailable.', 'directorist' ) );
                }
                $values = [];
                $state  = 'unavailable';
                $reason = 'source_unavailable';
            }
        }
        $items = [];
        if ( 'storage' === $id ) {
            $state  = 'unavailable';
            $reason = 'no_supported_preexisting_size_snapshot';
        }
        if ( 'collection' === $d['kind'] ) {
            // Sort the complete safe row before optional field projection removes its identity.
            usort(
                $values, static function ( $a, $b ) { return strcmp( $a['id'] ?? '', $b['id'] ?? '' );
                }
            );
            foreach ( $values as $value ) {
                $items[] = array_intersect_key( $value, array_flip( $keys ) );
            }
            if ( ! $items && 'available' === $state ) {
                $state = 'empty';
            }
        } else {
            sort( $keys );
            foreach ( $keys as $key ) {
                $raw   = $values[ $key ] ?? null;
                $value = Read_Support::safe_value( $raw );
                if ( null !== $value && is_wp_error( rest_validate_value_from_schema( $value, $d['adapter']['output_schema']['properties'][ $key ] ?? self::field_schema( $id, $key, $d['fields'][ $key ] ) ) ) ) {
                    $value = null;
                }
                $items[] = [ 'key' => $key, 'label' => ucwords( str_replace( '_', ' ', $key ) ), 'units' => self::units( $key ), 'type' => $d['fields'][ $key ], 'value' => $value, 'state' => null === $value ? ( null === $raw ? 'unavailable' : 'redacted' ) : ( [] === $value || '' === $value ? 'empty' : 'available' ) ];
            }
        }
        if ( $report ) {
            Support::check( count( $items ) <= 1000, __( 'This inventory is too large for a support report. Read it in pages.', 'directorist' ) );
            $collection = Support::collection( $items, count( $items ), 1, max( 1, count( $items ) ) );
        } else {
            $collection = Support::page( $items, $args );
        }
        return [ 'id' => $id, 'source_scope' => $d['source_scope'], 'state' => $state, 'reason' => $reason, 'as_of' => gmdate( 'c' ), 'cached' => false, 'age_seconds' => 0, 'source_version' => $d['source_version'] ] + $collection;
    }

    private static function units( $key ) {
        return false !== strpos( $key, '_seconds' ) ? 'seconds' : ( false !== strpos( $key, '_bytes' ) ? 'bytes' : null );
    }

    private static function collect( $id ) {
        global $wpdb;
        if ( 'wordpress' === $id ) {
            return [ 'version' => get_bloginfo( 'version' ), 'site_locale' => get_locale(), 'user_locale' => get_user_locale(), 'timezone' => wp_timezone_string(), 'permalink_mode' => get_option( 'permalink_structure' ) ? 'pretty' : 'plain', 'environment_type' => wp_get_environment_type(), 'https_configured' => 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME ), 'multisite' => is_multisite() ];
        }
        if ( 'runtime' === $id ) {
            return [ 'php_version' => PHP_VERSION, 'sapi' => PHP_SAPI, 'observed_runtime' => PHP_SAPI === 'cli' ? 'cli' : 'request_worker', 'bitness' => PHP_INT_SIZE * 8, 'php_memory_limit' => ini_get( 'memory_limit' ), 'wp_memory_limit' => WP_MEMORY_LIMIT, 'wp_max_memory_limit' => WP_MAX_MEMORY_LIMIT, 'execution_limit_seconds' => (int) ini_get( 'max_execution_time' ), 'input_limit_seconds' => (int) ini_get( 'max_input_time' ), 'max_input_variables' => (int) ini_get( 'max_input_vars' ), 'upload_limit' => ini_get( 'upload_max_filesize' ), 'post_limit' => ini_get( 'post_max_size' ) ];
        }
        if ( 'server' === $id ) {
            $family       = function_exists( 'php_uname' ) ? php_uname( 's' ) : '';
            $architecture = function_exists( 'php_uname' ) ? php_uname( 'm' ) : '';
            $software     = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';
            preg_match( '/^(Apache|nginx|LiteSpeed|Microsoft-IIS)(?:\/([0-9.]+))?/i', $software, $match );
            return [ 'os_family' => in_array( $family, [ 'Linux', 'Darwin', 'Windows NT', 'FreeBSD', 'OpenBSD' ], true ) ? $family : null, 'architecture' => in_array( $architecture, [ 'x86_64', 'arm64', 'aarch64', 'i386', 'i686' ], true ) ? $architecture : null, 'web_server_family' => isset( $match[1] ) ? $match[1] . ( isset( $match[2] ) ? ' ' . $match[2] : '' ) : null, 'curl_version' => function_exists( 'curl_version' ) ? curl_version()['version'] : null, 'openssl_version' => defined( 'OPENSSL_VERSION_TEXT' ) ? OPENSSL_VERSION_TEXT : null ];
        }
        if ( 'database' === $id ) {
            $limits  = Read_Support::query( "SHOW VARIABLES WHERE Variable_name IN ('max_allowed_packet','max_connections')" );
            $limits  = array_column( $limits, 'Value', 'Variable_name' );
            $version = Read_Support::query( 'SELECT VERSION() version' );
            return [ 'engine_version' => $version[0]['version'], 'driver' => function_exists( 'mysqli_get_client_info' ) ? 'mysqli' : null, 'client_version' => function_exists( 'mysqli_get_client_info' ) ? mysqli_get_client_info() : null, 'max_packet_bytes' => isset( $limits['max_allowed_packet'] ) ? (int) $limits['max_allowed_packet'] : null, 'max_connections' => isset( $limits['max_connections'] ) ? (int) $limits['max_connections'] : null ];
        }
        if ( in_array( $id, [ 'plugins', 'plugins-inactive', 'themes', 'themes-inactive' ], true ) ) {
            return self::inventory( $id );
        }
        if ( 'media' === $id ) {
            $gd         = function_exists( 'gd_info' ) ? gd_info() : [];
            $imagick    = class_exists( '\Imagick' ) ? \Imagick::getVersion() : [];
            $formats    = [];
            $gd_formats = self::gd_formats( $gd );
            foreach ( [ 'JPEG', 'PNG', 'GIF', 'WEBP', 'AVIF' ] as $format ) {
                if ( in_array( strtolower( $format ), $gd_formats, true ) || ( class_exists( '\Imagick' ) && \Imagick::queryFormats( $format ) ) ) {
                    $formats[] = strtolower( $format );
                }
            }
            return [ 'gd_available' => function_exists( 'gd_info' ), 'gd_version' => $gd['GD Version'] ?? null, 'imagick_available' => class_exists( '\Imagick' ), 'imagick_version' => $imagick['versionString'] ?? null, 'supported_formats' => $formats, 'upload_limit_bytes' => (int) wp_max_upload_size() ];
        }
        if ( 'constants' === $id ) {
            return [ 'debug_enabled' => (bool) WP_DEBUG, 'debug_display_enabled' => (bool) WP_DEBUG_DISPLAY, 'debug_log_enabled' => (bool) WP_DEBUG_LOG, 'cache_enabled' => defined( 'WP_CACHE' ) ? (bool) WP_CACHE : false, 'cron_disabled' => defined( 'DISABLE_WP_CRON' ) ? (bool) DISABLE_WP_CRON : false, 'memory_limit' => WP_MEMORY_LIMIT ];
        }
        if ( 'filesystem' === $id ) {
            $uploads = wp_get_upload_dir();
            return [ 'wordpress_writable' => self::writable( ABSPATH ), 'content_writable' => self::writable( WP_CONTENT_DIR ), 'uploads_writable' => ! empty( $uploads['basedir'] ) ? self::writable( $uploads['basedir'] ) : null, 'plugins_writable' => self::writable( WP_PLUGIN_DIR ), 'themes_writable' => self::writable( get_theme_root() ) ];
        }
        if ( 'directorist' === $id ) {
            $integrations = [];
            foreach ( [ 'dashboard', 'site_health_info' ] as $domain ) {
                foreach ( Registry::instance()->read_adapters( $domain, [ 'scope' => 'site' ] ) as $d ) {
                    $integrations[] = [ 'owner' => $d['owner'], 'contract_version' => $d['contract_version'], 'compatibility' => 'registered_contract_only' ];
                }
            }
            Support::check( count( $integrations ) <= 100, __( 'Too many registered diagnostic contributions for this section.', 'directorist' ), 'unsupported_operation' );
            return [ 'version' => ATBDP_VERSION, 'directory_mode' => directorist_is_multi_directory_enabled() ? 'multiple' : 'single', 'order_storage_available' => Orders::ready(), 'registered_integrations' => $integrations ];
        }
        if ( 'mcp' === $id ) {
            return [ 'enabled' => Module::enabled(), 'ready' => Module::dependencies(), 'abilities_available' => function_exists( 'wp_register_ability' ), 'adapter_version' => defined( '\WP\MCP\Core\McpAdapter::VERSION' ) ? \WP\MCP\Core\McpAdapter::VERSION : null, 'read_adapter_version' => Registry::READ_ADAPTER_VERSION, 'transport' => 'http', 'authentication' => 'WordPress application authentication over HTTPS', 'auth_available' => function_exists( 'wp_is_application_passwords_available_for_user' ) && wp_is_application_passwords_available_for_user( wp_get_current_user() ), 'compatibility' => Module::dependencies() ? 'supported' : 'unsupported', 'compatibility_rule' => 'WordPress 6.9 or newer; MCP Adapter 0.6.1 or newer; Abilities API present' ];
        }
        return [];
    }

    private static function gd_formats( array $gd ) {
        $formats = [];
        foreach ( [ 'jpeg' => 'JPEG Support', 'png' => 'PNG Support', 'gif' => 'GIF Read Support', 'webp' => 'WebP Support', 'avif' => 'AVIF Support' ] as $format => $key ) {
            if ( ! empty( $gd[ $key ] ) || ( 'gif' === $format && ! empty( $gd['GIF Create Support'] ) ) ) {
                $formats[] = $format;
            }
        }
        return $formats;
    }

    private static function writable( $path ) {
        // Restricted/missing paths are unknown; never emit PHP warnings containing a host path.
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Read-only observation may be denied by open_basedir.
        if ( ! @is_dir( $path ) ) {
            return null;
        }
        // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Do not expose a restricted source path in warnings.
        return @is_writable( $path );
    }

    private static function compatibility( $wordpress, $php ) {
        $requirements = [ [ $wordpress, get_bloginfo( 'version' ) ], [ $php, PHP_VERSION ] ];
        $declared     = false;
        foreach ( $requirements as $requirement ) {
            if ( '' === $requirement[0] ) {
                continue;
            }
            if ( ! is_string( $requirement[0] ) || ! preg_match( '/^[0-9]+(?:\.[0-9]+){0,3}$/', $requirement[0] ) ) {
                return [ 'compatibility' => 'unknown', 'compatibility_rule' => 'unrecognized_manifest_minimums_v1' ];
            }
            $declared = true;
            if ( version_compare( $requirement[1], $requirement[0], '<' ) ) {
                return [ 'compatibility' => 'unsupported', 'compatibility_rule' => 'installed_manifest_wp_php_minimums_v1' ];
            }
        }
        return [ 'compatibility' => $declared ? 'supported' : 'unknown', 'compatibility_rule' => $declared ? 'installed_manifest_wp_php_minimums_only_v1' : 'no_declared_compatibility_rule_v1' ];
    }

    private static function plugin_inventory( array $sources, array $active, $updates, $observed, $inactive ) {
        $items = [];
        foreach ( $sources as $category => $plugins ) {
            foreach ( $plugins as $key => $data ) {
                $is_mu   = 'must_use' === $category;
                $enabled = $is_mu || in_array( $key, $active, true );
                if ( $inactive === $enabled ) {
                    continue;
                }
                $items[] = [ 'id' => substr( hash( 'sha256', ( $is_mu ? 'must_use:' : '' ) . $key ), 0, 24 ), 'name' => Read_Support::safe_value( $data['Name'] ?? '' ), 'version' => Read_Support::safe_value( $data['Version'] ?? '' ), 'category' => $is_mu ? 'must_use' : ( $enabled ? 'active' : 'inactive' ), 'update_state' => ! $is_mu && $observed ? ( isset( $updates->response[ $key ] ) ? 'update_available' : ( isset( $updates->no_update[ $key ] ) ? 'observed_no_update' : 'unknown' ) ) : 'unknown', 'update_observed_at' => $is_mu ? null : $observed, 'update_age_seconds' => $is_mu || ! $observed ? null : max( 0, time() - strtotime( $observed ) ) ] + self::compatibility( $data['RequiresWP'] ?? '', $data['RequiresPHP'] ?? '' );
            }
        }
        return $items;
    }

    private static function inventory( $section ) {
        if ( ! function_exists( 'get_plugins' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugin   = 0 === strpos( $section, 'plugins' );
        $inactive = false !== strpos( $section, 'inactive' );
        $updates  = get_site_transient( $plugin ? 'update_plugins' : 'update_themes' );
        $observed = is_object( $updates ) && isset( $updates->last_checked ) && is_numeric( $updates->last_checked ) && $updates->last_checked > 0 && $updates->last_checked <= time() ? gmdate( 'c', (int) $updates->last_checked ) : null;
        $items    = [];
        if ( $plugin ) {
            $active  = array_merge( (array) get_option( 'active_plugins', [] ), array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) );
            $sources = [ 'plugin' => get_plugins(), 'must_use' => get_mu_plugins() ];
            return self::plugin_inventory( $sources, $active, $updates, $observed, $inactive );
        } else {
            $theme  = wp_get_theme();
            $active = [ $theme->get_stylesheet(), $theme->get_template() ];
            foreach ( wp_get_themes() as $key => $data ) {
                $enabled = in_array( $key, $active, true );
                if ( $inactive === $enabled ) {
                    continue;
                }
                $items[] = [ 'id' => substr( hash( 'sha256', $key ), 0, 24 ), 'name' => Read_Support::safe_value( $data->get( 'Name' ) ), 'version' => Read_Support::safe_value( $data->get( 'Version' ) ), 'category' => $key === $theme->get_stylesheet() ? 'active' : ( $enabled ? 'parent' : 'inactive' ), 'update_state' => $observed ? ( isset( $updates->response[ $key ] ) ? 'update_available' : ( isset( $updates->no_update[ $key ] ) ? 'observed_no_update' : 'unknown' ) ) : 'unknown', 'update_observed_at' => $observed, 'update_age_seconds' => $observed ? max( 0, time() - strtotime( $observed ) ) : null ] + self::compatibility( $data->get( 'RequiresWP' ), $data->get( 'RequiresPHP' ) );
            }
        }
        return $items;
    }
}
