<?php
namespace Directorist\MCP;

defined( 'ABSPATH' ) || exit;

class Settings {
    public static function registry() {
        $panel   = new \ATBDP_Settings_Panel();
        $core    = [];
        $capture = static function ( $fields ) use ( &$core, $panel ) {
            $core = $panel->register_setting_fields( $fields );
            return $fields;
        };
        add_filter( 'atbdp_listing_type_settings_field_list', $capture, PHP_INT_MIN );
        try {
            $panel->prepare_settings();
        } finally {
            remove_filter( 'atbdp_listing_type_settings_field_list', $capture, PHP_INT_MIN );
        }
        $groups = [];
        foreach ( $panel->layouts as $menu_key => $menu ) {
            self::index_groups( $menu, $menu_key, $groups );
        }
        $registry = [];
        foreach ( $core as $key => $field ) {
            $field   = $panel->fields[ $key ] ?? $field;
            $type    = $field['type'] ?? 'text';
            $secret  = self::sensitive( $key ) || in_array( $type, [ 'button', 'button-example', 'ajax-action', 'import', 'export', 'export-data', 'restore' ], true );
            $schema  = self::field_schema( $field );
            $default = $field['value'] ?? null;
            if ( isset( $schema['type'] ) && 'number' === $schema['type'] && is_numeric( $default ) ) {
                $default = 0 + $default;
            }
            $registry[ $key ] = [
                'key'             => $key,
                'label'           => wp_strip_all_tags( $field['label'] ?? $field['title'] ?? $key ),
                'group'           => $groups[ $key ] ?? 'general',
                'owner'           => 'directorist',
                'default'         => $default,
                'schema'          => $schema,
                'readable'        => ! $secret,
                'writable'        => ! $secret && null !== $schema,
                'resettable'      => ! $secret && null !== $schema,
                'classification'  => $secret ? 'excluded' : ( $schema ? 'supported' : 'read-only' ),
                'dependencies'    => $field['show-if'] ?? [],
                'storage_adapter' => 'directorist',
                'field'           => $field
            ];
        }
        // Extensions opt in explicitly. Merely adding an admin field never exposes it.
        foreach ( (array) array_merge( (array) apply_filters( 'directorist_mcp_settings', [] ), Domain_Fields::settings() ) as $key => $registration ) {
            if ( ! is_string( $key ) || self::sensitive( $key ) || isset( $registry[ $key ] ) || ! is_array( $registration ) ) {
                continue;
            }
            $required = [ 'owner', 'schema', 'default', 'capability', 'read', 'validate', 'write', 'storage_adapter', 'sensitive' ];
            if ( array_diff( $required, array_keys( $registration ) ) || ! empty( $registration['sensitive'] ) || ! is_callable( $registration['read'] ) || ! is_callable( $registration['validate'] ) || ! is_callable( $registration['write'] ) ) {
                continue;
            }
            if ( ! is_string( $registration['owner'] ) || ! is_string( $registration['capability'] ) || ! current_user_can( $registration['capability'] ) ) {
                continue;
            }
            $registry[ $key ] = array_merge(
                $registration, [
                    'key'            => $key,
                    'label'          => $registration['label'] ?? $key,
                    'group'          => $registration['group'] ?? 'extensions',
                    'readable'       => true,
                    'writable'       => $registration['writable'] ?? true,
                    'resettable'     => ! empty( $registration['resettable'] ),
                    'classification' => isset( $registration['writable'] ) && ! $registration['writable'] ? 'read-only' : 'supported',
                    'dependencies'   => $registration['dependencies'] ?? []
                ]
            );
        }
        return $registry;
    }

    private static function index_groups( array $node, $group, array &$groups ) {
        foreach ( $node['fields'] ?? [] as $key ) {
            if ( is_string( $key ) ) {
                $groups[ $key ] = $group;
            }
        }
        foreach ( [ 'sections', 'submenu' ] as $kind ) {
            foreach ( $node[ $kind ] ?? [] as $key => $child ) {
                if ( is_array( $child ) ) {
                    self::index_groups( $child, $group . '/' . $key, $groups );
                }
            }
        }
    }

    private static function sensitive( $key ) {
        return (bool) preg_match( '/password|secret|token|api_key|private_key|public_key|client_id|license|webhook|credential|access_key|auth_key|(?:^|_)(?:pk|sk)(?:_|$)|^mcp_|^directorist_mcp_/', $key );
    }

    public static function field_schema( array $field ) {
        $type = $field['type'] ?? 'text';
        if ( 'toggle' === $type || 'switch' === $type ) {
            return [ 'type' => 'boolean' ];
        }
        if ( in_array( $type, [ 'number', 'range' ], true ) ) {
            $schema = [ 'type' => 'number' ];
            foreach ( [ 'min' => 'minimum', 'max' => 'maximum' ] as $key => $target ) {
                if ( isset( $field[ $key ] ) && is_numeric( $field[ $key ] ) ) {
                    $schema[ $target ] = 0 + $field[ $key ];
                }
            }
            return $schema;
        }
        $choices = [];
        foreach ( (array) ( $field['options'] ?? [] ) as $key => $option ) {
            if ( is_array( $option ) && isset( $option['value'] ) ) {
                $choices[] = $option['value'];
            }
        }
        if ( in_array( $type, [ 'select', 'radio', 'radio-images', 'select2', 'color', 'color-picker', 'color_picker', 'text', 'textarea', 'email', 'url' ], true ) ) {
            $schema = [ 'type' => 'string' ];
            if ( $choices ) {
                $types          = array_values(
                    array_unique(
                        array_map(
                            static function ( $choice ) {
                                return is_int( $choice ) ? 'integer' : ( is_bool( $choice ) ? 'boolean' : 'string' );
                            }, $choices
                        )
                    )
                );
                $schema['type'] = 1 === count( $types ) ? $types[0] : $types;
                $schema['enum'] = $choices;
            }
            if ( 'email' === $type ) {
                $schema['format'] = 'email';
            }
            if ( 'url' === $type ) {
                $schema['format'] = 'uri';
            }
            return $schema;
        }
        if ( in_array( $type, [ 'checkbox', 'checkbox-array', 'multi-select', 'select_multiple' ], true ) && $choices ) {
            return [ 'type' => 'array', 'items' => [ 'enum' => $choices ], 'maxItems' => count( $choices ) ];
        }
        return null;
    }

    private static function value( array $definition ) {
        if ( 'directorist' !== $definition['owner'] ) {
            return call_user_func( $definition['read'] );
        }
        $saved = (array) get_option( 'atbdp_option', [] );
        return array_key_exists( $definition['key'], $saved ) ? $saved[ $definition['key'] ] : $definition['default'];
    }

    public static function read( $tool, array $args ) {
        Access::admin();
        $registry = self::registry();
        $selected = self::select( $registry, $args );
        $items    = [];
        foreach ( $selected as $key => $definition ) {
            if ( 'get-settings' === $tool ) {
                Support::check( $definition['readable'], __( 'Sensitive settings are available only in WordPress administration.', 'directorist' ), 'sensitive_field' );
                $value   = self::value( $definition );
                $items[] = [ 'key' => $key, 'value' => $value, 'default' => $definition['default'], 'has_override' => array_key_exists( $key, (array) get_option( 'atbdp_option', [] ) ), 'version' => Support::hash( [ $key, $value ] ) ];
            } else {
                unset( $definition['field'], $definition['read'], $definition['write'], $definition['validate'], $definition['permission'], $definition['invalidate'], $definition['tables'] );
                if ( ! $definition['readable'] ) {
                    unset( $definition['default'] );
                }
                $definition = array_intersect_key( $definition, array_flip( [ 'key', 'label', 'group', 'owner', 'default', 'schema', 'readable', 'writable', 'resettable', 'classification', 'dependencies', 'storage_adapter', 'capability', 'contract_version' ] ) );
                $items[]    = $definition;
            }
        }
        $result = Support::page( $items, $args );
        $state  = [];
        foreach ( $selected as $key => $definition ) {
            if ( $definition['readable'] ) {
                $state[ $key ] = self::value( $definition );
            }
        }
        $result['version'] = Support::hash( $state );
        return $result;
    }

    private static function select( array $registry, array $args ) {
        if ( isset( $args['keys'] ) ) {
            Support::check( is_array( $args['keys'] ) && count( $args['keys'] ) <= 100, __( 'Specify at most 100 settings keys.', 'directorist' ) );
            foreach ( $args['keys'] as $key ) {
                Support::check( is_string( $key ) && isset( $registry[ $key ] ), __( 'Unknown or unsupported settings key.', 'directorist' ) );
            }
            $registry = array_intersect_key( $registry, array_flip( $args['keys'] ) );
        }
        return array_filter(
            $registry, static function ( $d ) use ( $args ) {
                return ( ! isset( $args['group'] ) || 0 === strpos( $d['group'], $args['group'] ) ) && ( empty( $args['search'] ) || false !== stripos( $d['label'] . ' ' . $d['key'], $args['search'] ) );
            }
        );
    }

    public static function prepare( $tool, array $args ) {
        Access::admin();
        $registry = self::registry();
        $changes  = 'reset-settings' === $tool ? array_fill_keys( $args['keys'], null ) : Support::object( $args['changes'], 'changes' );
        Support::check( count( $changes ) > 0 && count( $changes ) <= 100, __( 'Specify between 1 and 100 explicit settings.', 'directorist' ) );
        $before     = [];
        $normalized = [];
        $adapters   = [];
        foreach ( $changes as $key => $value ) {
            Support::check( isset( $registry[ $key ] ) && $registry[ $key ]['writable'], __( 'Unsupported or sensitive settings key.', 'directorist' ), 'sensitive_field' );
            $d = $registry[ $key ];
            if ( 'reset-settings' === $tool ) {
                Support::check( $d['resettable'], __( 'This setting cannot be reset.', 'directorist' ) );
                $value = $d['default'];
            }
            $normalized[ $key ] = self::validate( $value, $d );
            $before[ $key ]     = self::value( $d );
            $adapters[]         = $d['storage_adapter'];
        }
        Support::check( 1 === count( array_unique( $adapters ) ), __( 'Use separate calls for independent settings stores.', 'directorist' ), 'unsupported_operation' );
        if ( 'directorist' !== reset( $adapters ) ) {
            $writer = $registry[ array_key_first( $normalized ) ]['write'];
            foreach ( array_keys( $normalized ) as $key ) {
                Support::check( $registry[ $key ]['write'] === $writer, __( 'The store must provide one atomic change-set writer.', 'directorist' ), 'unsupported_operation' );
            }
        }
        $dependency_state = [];
        foreach ( $changes as $key => $unused ) {
            $dependency = $registry[ $key ]['dependencies']['where'] ?? '';
            if ( $dependency && isset( $registry[ $dependency ] ) && $registry[ $dependency ]['readable'] ) {
                $dependency_state[ $dependency ] = self::value( $registry[ $dependency ] );
            }
        }
        $version = Support::hash( $before );
        if ( isset( $args['expected_version'] ) ) {
            Support::version( $args['expected_version'], $version );
        }
        // Callables are executable registration details, not serializable schema state.
        $contract = array_intersect_key( $registry, $changes );
        foreach ( $contract as &$definition ) {
            unset( $definition['read'], $definition['write'], $definition['validate'] );
        }
        return [
            'tables'  => array_values(
                array_unique(
                    array_merge(
                        ...array_map(
                            static function ( $key ) use ( $registry ) { return $registry[ $key ]['tables'] ?? [];
                            }, array_keys( $normalized )
                        )
                    )
                )
            ),
            'state'   => [ $before, $dependency_state, $contract ],
            'confirm' => true,
            'preview' => [ 'before' => $before, 'after' => $normalized, 'version' => $version ],
            'execute' => static function () use ( $registry, $normalized, $adapters ) {
                if ( 'directorist' === reset( $adapters ) ) {
                    $panel = new \ATBDP_Settings_Panel();
                    $panel->prepare_settings();
                    foreach ( $normalized as $key => $value ) {
                        if ( ! isset( $panel->fields[ $key ] ) ) {
                            $panel->fields[ $key ] = [ 'value' => $value ];
                        }
                    }
                    // Match the native UI's single-item JSON envelope, preserving numeric-looking strings.
                    $payload = array_map(
                        static function ( $value ) {
                            return wp_json_encode( [ $value ] );
                        }, $normalized
                    );
                    $result  = $panel->update_settings_options( $payload );
                    Support::check( ! empty( $result['status']['success'] ), __( 'The settings update failed.', 'directorist' ), 'operation_failed' );
                    $saved = (array) get_option( 'atbdp_option', [] );
                    foreach ( $normalized as $key => $value ) {
                                Support::check( array_key_exists( $key, $saved ) && $saved[ $key ] === $value, __( 'The settings save could not be verified.', 'directorist' ), 'operation_failed' );
                    }
                } else {
                    // A storage adapter owns the entire unit, never one write callback per key.
                    $callbacks = [];
                    foreach ( array_keys( $normalized ) as $key ) {
                        $callbacks[] = $registry[ $key ]['write'];
                    }
                    foreach ( $callbacks as $callback ) {
                        Support::check( $callback === $callbacks[0], __( 'The store must provide one atomic change-set writer.', 'directorist' ), 'unsupported_operation' );
                    }
                    Support::unwrap( call_user_func( $callbacks[0], $normalized ) );
                }
                return Support::result( [ 'values' => $normalized, 'version' => Support::hash( $normalized ) ] );
            }
        ];
    }

    private static function validate( $value, array $d ) {
        $schema = $d['schema'];
        if ( isset( $schema['enum'] ) && is_scalar( $value ) ) {
            foreach ( $schema['enum'] as $choice ) {
                if ( (string) $choice === (string) $value ) {
                    $value = $choice;
                    break;
                }
            }
        }
        if ( '' === $value && isset( $schema['format'] ) ) {
            unset( $schema['format'] );
        }
        if ( null !== $schema ) {
            Support::unwrap( rest_validate_value_from_schema( $value, $schema, $d['key'] ) );
        }
        if ( 'directorist' !== $d['owner'] ) {
            return Support::unwrap( call_user_func( $d['validate'], $value ) );
        }
        $type = $d['field']['type'] ?? 'text';
        if ( in_array( $type, [ 'number', 'range' ], true ) ) {
            return 0 + $value;
        }
        if ( in_array( $type, [ 'toggle', 'switch' ], true ) ) {
            return in_array( $value, [ true, 1, '1', 'true' ], true );
        }
        if ( is_string( $value ) ) {
            if ( in_array( $type, [ 'color', 'color-picker', 'color_picker' ], true ) ) {
                Support::check( (bool) sanitize_hex_color( $value ), __( 'Use a valid hex color.', 'directorist' ) );
            }
            $value = 'textarea' === $type ? wp_kses_post( $value ) : sanitize_text_field( $value );
        }
        if ( preg_match( '/(^|_)(page|page_id)$/', $d['key'] ) && $value && is_numeric( $value ) ) {
            Support::check( 'page' === get_post_type( (int) $value ) && current_user_can( 'read_post', (int) $value ), __( 'Select an accessible WordPress page.', 'directorist' ) );
        }
        return $value;
    }
}
