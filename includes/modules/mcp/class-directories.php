<?php
namespace Directorist\MCP;

use Directorist\Multi_Directory\Multi_Directory_Manager as Manager;

defined( 'ABSPATH' ) || exit;

class Directories {
    const AREAS = [
        'general'         => [ 'icon', 'preview_image', 'default_expiration', 'new_listing_status', 'edit_listing_status' ],
        'submission_form' => [ 'submission_form_fields' ],
        'search_form'     => [ 'search_form_fields' ],
        'single_listing'  => [ 'single_listing_header', 'single_listings_contents', 'enable_single_listing_page', 'single_listing_page', 'single_listing_page_title' ],
        'listing_cards'   => [ 'listings_card_grid_view', 'listings_card_list_view' ],
    ];

    public static function definitions() {
        Manager::load_builder_data();
        return Manager::$fields;
    }

    public static function configuration( $id ) {
        $values = [];
        foreach ( self::definitions() as $key => $definition ) {
            if ( 'import_export' === $key ) {
                continue;
            }
            $values[ $key ] = metadata_exists( 'term', $id, $key ) ? get_term_meta( $id, $key, true ) : self::default_value( $definition );
        }
        return $values;
    }

    private static function default_value( array $definition ) {
        if ( ! empty( $definition['value'] ) || ( array_key_exists( 'value', $definition ) && 'card-builder' !== ( $definition['type'] ?? '' ) ) ) {
            $value = $definition['value'];
            if ( is_array( $value ) && isset( $value['fields'] ) ) {
                foreach ( $value['fields'] as $id => &$field ) {
                    $field['widget_key'] = $field['widget_key'] ?? $id;
                }
            }
            return $value;
        }
        if ( ! empty( $definition['card_templates'] ) ) {
            $templates = $definition['card_templates'];
            $key       = key( $templates );
            return [ 'active_template' => $key, 'template_data' => [ $key => self::layout_default( $templates[ $key ]['layout'] ?? [], self::widgets( $definition ), false ) ] ];
        }
        if ( isset( $definition['layout'] ) ) {
            return self::layout_default( $definition['layout'], self::widgets( $definition ), true );
        }
        return null;
    }

    /** Materialize the same widget records as the native card/header editor. */
    private static function layout_default( array $layout, array $widgets, $header ) {
        if ( isset( $layout['acceptedWidgets'] ) ) {
            $selected = [];
            foreach ( $layout['selectedWidgetList'] ?? $layout['selectedWidgets'] ?? [] as $name ) {
                if ( isset( $widgets[ $name ] ) ) {
                    $selected[] = self::new_widget( $name, $name, $widgets[ $name ] );
                }
            }
            if ( ! $header ) {
                return $selected;
            }
            $layout['selectedWidgets']    = $selected;
            $layout['selectedWidgetList'] = array_column( $selected, 'widget_key' );
            return $layout;
        }
        foreach ( $layout as &$child ) {
            if ( is_array( $child ) ) {
                $child = self::layout_default( $child, $widgets, $header );
            }
        }
        return $layout;
    }

    public static function version( $id ) {
        $term = Support::directory( $id );
        return Support::hash( [ $term->to_array(), get_term_meta( $id ), directorist_get_default_directory(), self::dependencies( $id ) ] );
    }

    public static function dependencies( $id ) {
        global $wpdb;
        $term          = Support::directory( $id );
        $relationships = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d", $term->term_taxonomy_id ) );
        $meta          = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_directory_type' AND meta_value = %s", (string) $id ) );
        $associations  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE meta_key = '_directory_type' AND (meta_value LIKE %s OR meta_value LIKE %s)", '%' . $wpdb->esc_like( 'i:' . $id . ';' ) . '%', '%' . $wpdb->esc_like( '"' . $id . '"' ) . '%' ) );
        return [
            'listing_relationships' => $relationships,
            'listing_metadata'      => $meta,
            'term_associations'     => $associations,
            'protected'             => (bool) apply_filters( 'directorist_mcp_directory_has_dependencies', false, $id )
        ];
    }

    public static function summary( $term ) {
        return [
            'id'            => (int) $term->term_id,
            'name'          => $term->name,
            'slug'          => $term->slug,
            'is_default'    => (int) directorist_get_default_directory() === (int) $term->term_id,
            'listing_count' => (int) $term->count
        ];
    }

    public static function read( $tool, array $args ) {
        Access::admin();
        if ( 'list-directories' === $tool ) {
            list( $page, $size ) = Support::paging( $args );
            $query               = [ 'taxonomy' => ATBDP_DIRECTORY_TYPE, 'hide_empty' => false, 'search' => $args['search'] ?? '', 'orderby' => 'term_id', 'order' => 'ASC' ];
            if ( isset( $args['is_default'] ) ) {
                $query[ $args['is_default'] ? 'include' : 'exclude' ] = [ directorist_get_default_directory() ];
            }
            $total = Support::unwrap( wp_count_terms( $query ) );
            $terms = Support::unwrap( get_terms( $query + [ 'number' => $size, 'offset' => ( $page - 1 ) * $size ] ) );
            return Support::collection( array_map( [ self::class, 'summary' ], $terms ), $total, $page, $size );
        }
        $id     = Support::directory( $args['directory_id'] )->term_id;
        $config = self::configuration( $id );
        $area   = $args['area'] ?? null;
        if ( $area ) {
            Support::check( isset( self::AREAS[ $area ] ), __( 'Unsupported builder area.', 'directorist' ) );
            $config = array_intersect_key( $config, array_flip( self::AREAS[ $area ] ) );
        }
        $result = self::summary( get_term( $id, ATBDP_DIRECTORY_TYPE ) ) + [ 'configuration' => $config, 'version' => self::version( $id ) ];
        if ( 'get-builder-schema' === $tool ) {
            $result['schemas'] = [];
            foreach ( $config as $key => $value ) {
                $definition                = self::definitions()[ $key ];
                $result['schemas'][ $key ] = [
                    'options'   => Settings::field_schema( $definition ),
                    'widgets'   => self::widgets( $definition ),
                    'sections'  => self::sections( (array) $value ),
                    'slots'     => self::slots( (array) $value, $definition ),
                    'templates' => array_keys( $definition['card_templates'] ?? [] )
                ];
            }
            $result['operation_contract'] = [
                'configuration' => 'Native configuration key in the selected area.',
                'entity'        => [ 'field', 'section', 'widget', 'options', 'template' ],
                'action'        => [ 'add', 'update', 'move', 'reorder', 'remove' ],
                'id'            => 'Stable field/widget/section ID from the configuration.',
                'slot'          => 'Discovered slot path; never a term-meta path.'
            ];
        }
        return $result;
    }

    private static function sections( array $config ) {
        $sections = [];
        foreach ( $config['groups'] ?? [] as $index => $group ) {
            $sections[] = [ 'id' => self::section_id( $group, $index ), 'label' => $group['label'] ?? '', 'fields' => $group['fields'] ?? [] ];
        }
        return $sections;
    }

    private static function section_id( array $group, $index ) {
        return (string) ( $group['id'] ?? $group['section_id'] ?? 'section-' . $index );
    }

    private static function slots( array $config, array $definition ) {
        $slots    = [];
        $header   = empty( $definition['card_templates'] );
        $template = $config['active_template'] ?? '';
        $layout   = $header ? ( $definition['layout'] ?? [] ) : ( $definition['card_templates'][ $template ]['layout'] ?? [] );
        $walk     = static function ( $node, $path ) use ( &$walk, &$slots, $config, $header ) {
            if ( isset( $node['acceptedWidgets'] ) ) {
                if ( $header ) {
                    $path[] = 'selectedWidgets';
                }
                $value = $config;
                foreach ( $path as $segment ) {
                    $value = $value[ $segment ] ?? [];
                }
                $slots[] = [ 'path' => $path, 'widgets' => array_column( $value, 'widget_key' ), 'accepted_widgets' => $node['acceptedWidgets'], 'max_widgets' => (int) ( $node['maxWidget'] ?? 0 ) ];
                return;
            }
            foreach ( $node as $key => $value ) {
                if ( is_array( $value ) ) {
                    $walk( $value, array_merge( $path, [ $key ] ) );
                }
            }
        };
        $walk( $layout, $header ? [] : [ 'template_data', $template ] );
        return $slots;
    }

    private static function placement( array $slot, array $constraint, array $widget ) {
        Support::check( ! $constraint['accepted_widgets'] || in_array( $widget['widget_name'], $constraint['accepted_widgets'], true ), __( 'This widget is not allowed in the selected slot.', 'directorist' ) );
        Support::check( ! $constraint['max_widgets'] || count( $slot ) < $constraint['max_widgets'], __( 'This widget slot is full.', 'directorist' ) );
        Support::check( ! in_array( $widget['widget_key'], array_column( $slot, 'widget_key' ), true ), __( 'Duplicate widget in this slot.', 'directorist' ) );
    }

    private static function widgets( array $definition ) {
        $widgets = [];
        $walk    = static function ( $node, $group = '' ) use ( &$walk, &$widgets ) {
            if ( ! is_array( $node ) ) {
                return;
            }
            if ( isset( $node['widgets'] ) && is_array( $node['widgets'] ) ) {
                foreach ( $node['widgets'] as $name => $widget ) {
                    if ( ! is_array( $widget ) ) {
                        continue;
                    }
                    if ( isset( $widget['widgets'] ) ) {
                        $walk( $widget, $name );
                    } else {
                        $widgets[ $name ] = $widget + [ 'widget_group' => $group, 'canTrash' => true ];
                    }
                }
            }
            foreach ( $node['card_templates'] ?? [] as $template ) {
                $walk( $template, $group );
            }
        };
        $walk( $definition );
        $adapters = Registry::instance()->fields( 'builder', $definition );
        foreach ( $widgets as $name => &$widget ) {
            $widget['writable'] = in_array( $name, self::core_widgets(), true );
            if ( isset( $adapters[ $name ] ) && ! $widget['writable'] ) {
                $d                              = $adapters[ $name ];
                $widget['writable']             = ! empty( $d['writable'] ) && true === call_user_func( $d['permission'], $definition, 'write' );
                $widget['mcp_owner']            = $d['owner'];
                $widget['mcp_schema']           = $d['schema'];
                $widget['mcp_contract_version'] = $d['contract_version'];
            }
        }
        return $widgets;
    }

    private static function core_widgets() {
        return apply_filters( 'directorist_mcp_builder_widget_allowlist', [ 'title', 'description', 'excerpt', 'tagline', 'address', 'map', 'phone', 'phone2', 'fax', 'email', 'website', 'social_info', 'video', 'pricing', 'category', 'location', 'tag', 'image_upload', 'hide_contact_owner', 'text', 'textarea', 'number', 'url', 'date', 'time', 'color', 'select', 'checkbox', 'radio', 'file', 'switch', 'back', 'slider', 'bookmark', 'share', 'report', 'price', 'badges', 'ratings_count', 'button', 'listing_title', 'rating', 'featured_badge', 'new_badge', 'popular_badge', 'favorite_badge', 'listings_location', 'posted_date', 'view_count', 'user_avatar', 'search', 'search_button', 'reset_button', 'submit_button', 'listing_content', 'contact_info', 'contact_owner', 'related_listings', 'review', 'author_info' ] );
    }

    public static function prepare( $tool, array $args ) {
        Access::admin();
        $create    = 'create-directory' === $tool;
        $duplicate = 'duplicate-directory' === $tool;
        $term      = $create ? null : Support::directory( $args['directory_id'] );
        $id        = $term ? (int) $term->term_id : 0;
        $version   = $id ? self::version( $id ) : Support::hash( array_keys( self::definitions() ) );
        if ( $id && empty( $args['dry_run'] ) ) {
            Support::version( $args['expected_version'] ?? null, $version );
        }
        if ( $create || $duplicate ) {
            Support::check( directorist_is_multi_directory_enabled(), __( 'Enable multi-directory mode before creating another directory.', 'directorist' ), 'unsupported_operation' );
            $name = Support::clean_text( $args['name'] );
            Support::check( '' !== trim( $name ) && ! term_exists( $name, ATBDP_DIRECTORY_TYPE ), __( 'Use a unique, nonempty directory name.', 'directorist' ) );
            $slug = sanitize_title( $args['slug'] ?? $name );
            Support::check( $slug && ! get_term_by( 'slug', $slug, ATBDP_DIRECTORY_TYPE ), __( 'Use a unique directory slug.', 'directorist' ) );
            $values = $duplicate ? self::configuration( $id ) : array_map( [ self::class, 'default_value' ], self::definitions() );
            unset( $values['import_export'] );
            $changes = $create ? self::general( Support::object( $args['changes'] ?? [] ) ) : [];
            $values  = array_replace( $values, $changes );
            return [
                'state'   => [ $version, $values ],
                'confirm' => true,
                'preview' => [ 'name' => $name, 'slug' => $slug, 'configuration_only' => true, 'version' => $version ],
                'execute' => static function () use ( $name, $slug, $values ) {
                    $result = Manager::add_directory( [ 'directory_name' => $name, 'fields_value' => $values ] );
                    Support::check( ! empty( $result['status']['success'] ), __( 'Directory creation failed.', 'directorist' ), 'operation_failed' );
                    $new_id = (int) $result['term_id'];
                    Support::unwrap( wp_update_term( $new_id, ATBDP_DIRECTORY_TYPE, [ 'slug' => $slug ] ) );
                    return Support::result( [ 'directory_id' => $new_id, 'version' => self::version( $new_id ) ] );
                }
            ];
        }
        if ( 'delete-directory' === $tool ) {
            $deps = self::dependencies( $id );
            Support::check( $id !== (int) directorist_get_default_directory() && (int) wp_count_terms( [ 'taxonomy' => ATBDP_DIRECTORY_TYPE, 'hide_empty' => false ] ) > 1 && ! array_filter( $deps ), __( 'Only an empty, non-default directory without dependencies can be deleted.', 'directorist' ), 'dependency_blocked' );
            return [
                'state'   => $version,
                'confirm' => true,
                'preview' => [ 'directory_id' => $id, 'delete' => true, 'version' => $version ],
                'execute' => static function () use ( $id ) {
                    Support::unwrap( wp_delete_term( $id, ATBDP_DIRECTORY_TYPE ) );
                    return Support::result( [ 'directory_id' => $id, 'deleted' => true ] );
                }
            ];
        }
        if ( 'set-default-directory' === $tool ) {
            return [
                'state'   => $version,
                'confirm' => true,
                'preview' => [ 'old_default' => directorist_get_default_directory(), 'new_default' => $id, 'version' => $version ],
                'execute' => static function () use ( $id ) {
                    $directories = get_terms( [ 'taxonomy' => ATBDP_DIRECTORY_TYPE, 'hide_empty' => false, 'fields' => 'ids' ] );
                    foreach ( $directories as $directory ) {
                        update_term_meta( $directory, '_default', (int) $directory === $id );
                    }
                    update_directorist_option( 'atbdp_default_derectory', $id );
                    do_action( 'directorist_options_updated' );
                    return Support::result( [ 'directory_id' => $id, 'version' => self::version( $id ) ] );
                }
            ];
        }
        $values = self::configuration( $id );
        $name   = $term->name;
        $slug   = $term->slug;
        if ( 'update-directory' === $tool ) {
            $changes = Support::object( $args['changes'] );
            if ( isset( $changes['name'] ) ) {
                $name = Support::clean_text( $changes['name'] );
                unset( $changes['name'] );
            }
            if ( isset( $changes['slug'] ) ) {
                $slug = sanitize_title( $changes['slug'] );
                unset( $changes['slug'] );
            }
            Support::check( $name && $slug, __( 'Name and slug cannot be empty.', 'directorist' ) );
            $existing = get_term_by( 'slug', $slug, ATBDP_DIRECTORY_TYPE );
            Support::check( ! $existing || $existing->term_id === $id, __( 'That directory slug is already in use.', 'directorist' ) );
            $changes = self::general( $changes );
        } else {
            $area = $args['area'];
            Support::check( isset( self::AREAS[ $area ] ) && 'general' !== $area, __( 'Use update-directory for general settings.', 'directorist' ) );
            $result = $values;
            foreach ( $args['operations'] as $operation ) {
                self::operation( $result, $area, Support::object( $operation ) );
            }
            self::validate_configuration( $values, $result );
            $changes = [];
            foreach ( self::AREAS[ $area ] as $key ) {
                if ( $result[ $key ] !== $values[ $key ] ) {
                    $changes[ $key ] = $result[ $key ];
                }
            }
        }
        return [
            'state'   => $version,
            'confirm' => true,
            'preview' => [ 'directory_id' => $id, 'name' => $name, 'slug' => $slug, 'changes' => $changes, 'version' => $version ],
            'execute' => static function () use ( $id, $name, $slug, $changes ) {
                do_action( 'directorist_before_directory_type_updated' );
                $result = Manager::update_directory( [ 'term_id' => $id, 'directory_name' => $name, 'fields_value' => $changes ] );
                Support::check( ! empty( $result['status']['success'] ), __( 'The builder update failed.', 'directorist' ), 'operation_failed' );
                Support::unwrap( wp_update_term( $id, ATBDP_DIRECTORY_TYPE, [ 'slug' => $slug ] ) );
                return Support::result( [ 'directory_id' => $id, 'version' => self::version( $id ) ] );
            }
        ];
    }

    private static function general( array $changes ) {
        Support::allowed( $changes, self::AREAS['general'] );
        foreach ( $changes as $key => &$value ) {
            if ( 'preview_image' === $key ) {
                if ( $value ) {
                    Access::attachment( $value, true );
                }
                continue;
            }
            if ( 'default_expiration' === $key ) {
                Support::check( is_int( $value ) && $value >= 0 && $value <= 36500, __( 'Expiration must be 0–36500 days.', 'directorist' ) );
                continue;
            }
            if ( in_array( $key, [ 'new_listing_status', 'edit_listing_status' ], true ) ) {
                $options = self::definitions()[ $key ]['options'] ?? [];
                $allowed = array_column( $options, 'value' );
                Support::check( in_array( $value, $allowed ?: [ 'publish', 'pending' ], true ), __( 'Unsupported listing status policy.', 'directorist' ) );
            } else {
                $value = Support::clean_text( $value );
            }
        }
        return $changes;
    }

    private static function operation( array &$values, $area, array $op ) {
        Support::allowed( $op, [ 'action', 'entity', 'configuration', 'id', 'widget', 'section_id', 'slot', 'target_slot', 'position', 'changes', 'order', 'template' ] );
        $key = $op['configuration'] ?? '';
        Support::check( in_array( $key, self::AREAS[ $area ], true ), __( 'Select a discovered configuration key in this builder area.', 'directorist' ) );
        $action = $op['action'] ?? '';
        Support::check( in_array( $action, [ 'add', 'update', 'move', 'reorder', 'remove' ], true ), __( 'Unsupported builder action.', 'directorist' ) );
        $definition = self::definitions()[ $key ];
        if ( 'options' === ( $op['entity'] ?? '' ) ) {
            $schema = Settings::field_schema( $definition );
            Support::check( null !== $schema && 'update' === $action, __( 'This builder option is not writable.', 'directorist' ), 'unsupported_operation' );
            $value = $op['changes']['value'] ?? null;
            Support::unwrap( rest_validate_value_from_schema( $value, $schema ) );
            if ( 'single_listing_page' === $key && $value ) {
                Support::check( 'page' === get_post_type( (int) $value ), __( 'Select an existing page.', 'directorist' ) );
            }
            $values[ $key ] = is_string( $value ) ? Support::clean_text( $value ) : $value;
            return;
        }
        $config  = (array) $values[ $key ];
        $widgets = self::widgets( $definition );
        if ( isset( $config['groups'], $config['fields'] ) ) {
            self::form_operation( $config, $op, $widgets );
        } else {
            self::layout_operation( $config, $op, $widgets, $definition );
        }
        $values[ $key ] = $config;
    }

    private static function form_operation( array &$config, array $op, array $widgets ) {
        foreach ( $config['groups'] as $index => &$group ) {
            $group['id'] = self::section_id( $group, $index );
        }
        unset( $group );
        $action = $op['action'];
        $id     = $op['id'] ?? '';
        if ( 'section' === ( $op['entity'] ?? '' ) ) {
            if ( 'add' === $action ) {
                $changes = Support::object( $op['changes'] ?? [] );
                Support::allowed( $changes, [ 'label', 'icon', 'section_id' ] );
                foreach ( $changes as &$value ) {
                    $value = Support::clean_text( $value );
                }
                unset( $value );
                Support::check( ! $id || ( is_string( $id ) && preg_match( '/^[A-Za-z0-9_-]{1,100}$/', $id ) ), __( 'Use a stable section ID with letters, numbers, hyphens or underscores.', 'directorist' ) );
                Support::check( ! $id || ! in_array( $id, array_column( $config['groups'], 'id' ), true ), __( 'Section ID is already in use.', 'directorist' ) );
                $config['groups'][] = [ 'id' => $id ?: wp_generate_uuid4(), 'type' => 'general_group', 'fields' => [] ] + $changes;
                return;
            }
            $index = array_search( $id, array_column( $config['groups'], 'id' ), true );
            Support::check( false !== $index, __( 'Builder section not found.', 'directorist' ) );
            if ( 'remove' === $action ) {
                Support::check( empty( $config['groups'][ $index ]['fields'] ) && empty( $config['groups'][ $index ]['lock'] ), __( 'Move/remove section items first; locked sections cannot be removed.', 'directorist' ), 'dependency_blocked' );
                array_splice( $config['groups'], $index, 1 );
            } elseif ( 'update' === $action ) {
                $changes = Support::object( $op['changes'] );
                Support::allowed( $changes, [ 'label', 'icon' ] );
                foreach ( $changes as &$v ) {
                    $v = Support::clean_text( $v );
                }
                $config['groups'][ $index ] = array_replace( $config['groups'][ $index ], $changes );
            } elseif ( 'reorder' === $action ) {
                self::reorder_objects( $config['groups'], $op['order'], 'id' );
            } else {
                Support::fail( 'unsupported_operation', __( 'Use section reorder to move sections.', 'directorist' ) );
            }
            return;
        }
        Support::check( 'field' === ( $op['entity'] ?? '' ), __( 'This configuration contains fields and sections.', 'directorist' ) );
        if ( 'add' === $action ) {
            $name = $op['widget'] ?? '';
            Support::check( isset( $widgets[ $name ] ) && $widgets[ $name ]['writable'], __( 'This widget is not supported for MCP editing.', 'directorist' ), 'unsupported_operation' );
            $id = $id ?: $name . '-' . substr( wp_generate_uuid4(), 0, 8 );
            Support::check( is_string( $id ) && preg_match( '/^[A-Za-z0-9_-]{1,100}$/', $id ), __( 'Use a stable field ID with letters, numbers, hyphens or underscores.', 'directorist' ) );
            Support::check( ! isset( $config['fields'][ $id ] ), __( 'Field ID is already in use.', 'directorist' ) );
            $field = self::new_widget( $name, $id, $widgets[ $name ] );
            if ( 'custom' === ( $field['widget_group'] ?? '' ) ) {
                $field['field_key'] = 'custom-' . sanitize_key( $id );
            }
            $config['fields'][ $id ] = self::update_widget( $field, $op['changes'] ?? [], $widgets[ $name ], true );
        } else {
            Support::check( isset( $config['fields'][ $id ] ), __( 'Builder field not found.', 'directorist' ) );
            $field = $config['fields'][ $id ];
            $name  = $field['widget_name'] ?? '';
            Support::check( isset( $widgets[ $name ] ) && $widgets[ $name ]['writable'], __( 'This widget is read-only.', 'directorist' ), 'unsupported_operation' );
            if ( 'update' === $action ) {
                $config['fields'][ $id ] = self::update_widget( $field, $op['changes'] ?? [], $widgets[ $name ] );
                return;
            }
            if ( 'remove' === $action ) {
                Support::check( ! empty( $widgets[ $name ]['canTrash'] ) && 'title' !== $name, __( 'A mandatory preset cannot be removed.', 'directorist' ) );
                unset( $config['fields'][ $id ] );
            }
        }
        if ( 'reorder' === $action ) {
            foreach ( $config['groups'] as &$group ) {
                if ( $group['id'] === ( $op['section_id'] ?? '' ) ) {
                    self::reorder_ids( $group['fields'], $op['order'] );
                    return;
                }
            }
            Support::fail( 'validation_failed', __( 'Select a section to reorder.', 'directorist' ) );
        }
        if ( in_array( $action, [ 'add', 'move', 'remove' ], true ) ) {
            foreach ( $config['groups'] as &$group ) {
                $group['fields'] = array_values( array_diff( $group['fields'], [ $id ] ) );
            }
            unset( $group );
            if ( 'remove' !== $action ) {
                $target = array_search( $op['section_id'] ?? '', array_column( $config['groups'], 'id' ), true );
                Support::check( false !== $target, __( 'Select a target section.', 'directorist' ) );
                $position = $op['position'] ?? count( $config['groups'][ $target ]['fields'] );
                Support::check( is_int( $position ) && $position >= 0, __( 'Invalid insertion position.', 'directorist' ) );
                array_splice( $config['groups'][ $target ]['fields'], $position, 0, [ $id ] );
            }
        }
    }

    private static function new_widget( $name, $id, array $definition ) {
        $widget = [ 'widget_name' => $name, 'widget_key' => $id, 'widget_group' => $definition['widget_group'] ?? '', 'type' => $definition['type'] ?? 'text', 'label' => $definition['label'] ?? $name ];
        if ( isset( $definition['hook'] ) ) {
            $widget['hook'] = $definition['hook'];
        }
        $options = $definition['options']['fields'] ?? $definition['options'] ?? [];
        foreach ( $options as $key => $option ) {
            if ( is_array( $option ) && array_key_exists( 'value', $option ) ) {
                $widget[ $key ] = $option['value'];
            }
        }
        return $widget;
    }

    private static function update_widget( array $widget, $changes, array $definition, $new = false ) {
        $changes = Support::object( $changes );
        if ( isset( $definition['mcp_owner'] ) ) {
            $adapters = Registry::instance()->fields( 'builder', $definition );
            $d        = $adapters[ $widget['widget_name'] ] ?? null;
            Support::check( $d && true === call_user_func( $d['permission'], $definition, 'write' ), __( 'This widget integration is unavailable.', 'directorist' ), 'extension_unavailable' );
            Support::unwrap( rest_validate_value_from_schema( $changes ?: (object) [], $d['schema'] ) );
            $changes = Support::object( Support::unwrap( call_user_func( $d['validate'], $changes, $widget ) ) );
            Support::unwrap( rest_validate_value_from_schema( $changes ?: (object) [], $d['schema'] ) );
        }
        $options = $definition['options']['fields'] ?? $definition['options'] ?? [];
        Support::allowed( $changes, array_merge( array_keys( $options ), [ 'label', 'original_widget_key' ] ) );
        foreach ( $changes as $key => $value ) {
            Support::check( $new || ! in_array( $key, [ 'field_key', 'type' ], true ) || $value === ( $widget[ $key ] ?? null ), __( 'Existing field storage keys and types cannot be changed.', 'directorist' ) );
            $schema = isset( $options[ $key ] ) ? Settings::field_schema( $options[ $key ] ) : [ 'type' => 'string' ];
            if ( 'conditional_logic' === $key ) {
                $schema = self::conditional_schema();
            }
            if ( 'options' === $key && 'multi-fields' === ( $options[ $key ]['type'] ?? '' ) ) {
                $schema = [ 'type' => 'array', 'maxItems' => 200, 'items' => [ 'type' => 'object', 'required' => [ 'option_value', 'option_label' ], 'additionalProperties' => false, 'properties' => [ 'option_value' => [ 'type' => 'string', 'minLength' => 1 ], 'option_label' => [ 'type' => 'string' ] ] ] ];
                Support::unwrap( rest_validate_value_from_schema( $value, $schema, $key ) );
                Support::check( count( array_unique( array_column( $value, 'option_value' ) ) ) === count( $value ), __( 'Choice values must be unique.', 'directorist' ) );
            }
            if ( null !== $schema ) {
                Support::unwrap( rest_validate_value_from_schema( $value, $schema, $key ) );
            } elseif ( ! is_array( $value ) && ! is_object( $value ) ) {
                Support::fail( 'validation_failed', __( 'Unsupported widget option type.', 'directorist' ) );
            }
            $widget[ $key ] = map_deep(
                json_decode( wp_json_encode( $value ), true ), static function ( $v ) {
                    return is_string( $v ) ? sanitize_text_field( $v ) : $v;
                }
            );
        }
        return $widget;
    }

    private static function conditional_schema() {
        $condition = [
            'type'                 => 'object',
            'required'             => [ 'field', 'operator' ],
            'additionalProperties' => false,
            'properties'           => [
                'field'    => [ 'type' => 'string', 'minLength' => 1 ],
                'operator' => [ 'type' => 'string' ],
                'value'    => [ 'type' => [ 'string', 'number', 'boolean', 'array', 'null' ] ],
            ],
        ];
        $group     = [
            'type'                 => 'object',
            'required'             => [ 'conditions' ],
            'additionalProperties' => false,
            'properties'           => [
                'operator'   => [ 'type' => 'string', 'enum' => [ 'AND', 'OR' ] ],
                'conditions' => [ 'type' => 'array', 'maxItems' => 50, 'items' => $condition ],
            ],
        ];
        return [
            'type'                 => 'object',
            'additionalProperties' => false,
            'required'             => [ 'enabled', 'action', 'groups' ],
            'properties'           => [
                'enabled'        => [ 'type' => 'boolean' ],
                'action'         => [ 'type' => 'string', 'enum' => [ 'show', 'hide' ] ],
                'globalOperator' => [ 'type' => 'string', 'enum' => [ 'AND', 'OR' ] ],
                'groups'         => [ 'type' => 'array', 'maxItems' => 50, 'items' => $group ],
            ],
        ];
    }

    private static function layout_operation( array &$config, array $op, array $widgets, array $definition ) {
        if ( 'template' === ( $op['entity'] ?? '' ) ) {
            $template = $op['template'] ?? '';
            Support::check( isset( $definition['card_templates'][ $template ] ), __( 'Unknown card template.', 'directorist' ) );
            Support::check( 'update' === $op['action'], __( 'Use update to select a card template.', 'directorist' ) );
            $config['template_data'][ $template ] = $config['template_data'][ $template ] ?? self::layout_default( $definition['card_templates'][ $template ]['layout'], $widgets, false );
            $config['active_template']            = $template;
            return;
        }
        Support::check( 'widget' === ( $op['entity'] ?? '' ), __( 'This configuration contains layout widgets.', 'directorist' ) );
        $path        = $op['slot'] ?? [];
        $constraints = self::slots( $config, $definition );
        $valid       = array_column( $constraints, 'path' );
        Support::check( in_array( $path, $valid, true ), __( 'Use a discovered widget slot.', 'directorist' ) );
        $slot =& $config;
        foreach ( $path as $segment ) {
            $slot =& $slot[ $segment ];
        }
        $action = $op['action'];
        $id     = $op['id'] ?? '';
        if ( isset( $op['position'] ) ) {
            Support::check( is_int( $op['position'] ) && $op['position'] >= 0, __( 'Invalid widget position.', 'directorist' ) );
        }
        if ( 'reorder' === $action ) {
            self::reorder_objects( $slot, $op['order'], 'widget_key' );
        } elseif ( 'add' === $action ) {
            $name = $op['widget'] ?? '';
            Support::check( isset( $widgets[ $name ] ) && $widgets[ $name ]['writable'], __( 'Unsupported layout widget.', 'directorist' ) );
            $widget = self::update_widget( self::new_widget( $name, $id ?: $name, $widgets[ $name ] ), $op['changes'] ?? [], $widgets[ $name ], true );
            self::placement( $slot, $constraints[ array_search( $path, $valid, true ) ], $widget );
            array_splice( $slot, $op['position'] ?? count( $slot ), 0, [ $widget ] );
        } else {
            $index = array_search( $id, array_column( $slot, 'widget_key' ), true );
            Support::check( false !== $index, __( 'Layout widget not found in this slot.', 'directorist' ) );
            $widget = $slot[ $index ];
            $name   = $widget['widget_name'];
            Support::check( isset( $widgets[ $name ] ) && $widgets[ $name ]['writable'], __( 'This widget is read-only.', 'directorist' ) );
            if ( 'update' === $action ) {
                $slot[ $index ] = self::update_widget( $widget, $op['changes'] ?? [], $widgets[ $name ] );
            } elseif ( 'remove' === $action || 'move' === $action ) {
                Support::check( ! isset( $widget['can_move'] ) || false !== $widget['can_move'], __( 'This widget cannot be moved or removed.', 'directorist' ) );
                array_splice( $slot, $index, 1 );
                if ( 'move' === $action ) {
                    $target = $op['target_slot'] ?? null;
                    Support::check( in_array( $target, $valid, true ), __( 'Unknown target slot.', 'directorist' ) );
                    $target_slot =& $config;
                    foreach ( $target as $segment ) {
                        $target_slot =& $target_slot[ $segment ];
                    }
                    self::placement( $target_slot, $constraints[ array_search( $target, $valid, true ) ], $widget );
                    array_splice( $target_slot, $op['position'] ?? count( $target_slot ), 0, [ $widget ] );
                }
            }
        }
        // Header UI maintains both arrays; keep its native round-trip representation consistent.
        $sync = static function ( &$node ) use ( &$sync ) {
            if ( ! is_array( $node ) ) {
                return;
            }
            if ( isset( $node['selectedWidgets'] ) ) {
                $node['selectedWidgetList'] = array_column( $node['selectedWidgets'], 'widget_key' );
            }
            foreach ( $node as &$child ) {
                if ( is_array( $child ) ) {
                    $sync( $child );
                }
            }
        };
        if ( ! isset( $config['active_template'] ) ) {
            $sync( $config );
        }
    }

    private static function reorder_ids( array &$items, $order ) {
        Support::check( is_array( $order ) && count( $order ) === count( $items ) && count( array_unique( $order ) ) === count( $order ) && ! array_diff( $order, $items ), __( 'Reorder must include every sibling ID exactly once.', 'directorist' ) );
        $items = array_values( $order );
    }

    private static function reorder_objects( array &$items, $order, $key ) {
        $ids = array_column( $items, $key );
        self::reorder_ids( $ids, $order );
        $map   = array_column( $items, null, $key );
        $items = array_map(
            static function ( $id ) use ( $map ) {
                return $map[ $id ];
            }, $ids
        );
    }

    private static function validate_configuration( array $before, array $after ) {
        $form   = $after['submission_form_fields'];
        $fields = $form['fields'] ?? [];
        $keys   = array_column( $fields, 'field_key' );
        Support::check( count( $keys ) === count( array_unique( $keys ) ), __( 'Field storage keys must be unique.', 'directorist' ) );
        foreach ( $fields as $field ) {
            if ( 'title' === ( $field['widget_name'] ?? '' ) ) {
                Support::check( ! empty( $field['required'] ), __( 'The listing title must remain required.', 'directorist' ) );
            }
        }
        $references = [];
        foreach ( $form['groups'] ?? [] as $group ) {
            $references = array_merge( $references, $group['fields'] ?? [] );
        }
        Support::check( ! array_diff( array_keys( $fields ), $references ) && ! array_diff( $references, array_keys( $fields ) ) && count( $references ) === count( array_unique( $references ) ), __( 'Every submission field must belong to exactly one section.', 'directorist' ) );
        $graph = [];
        foreach ( $fields as $id => $field ) {
            $rules = $field['conditional_logic']['groups'] ?? [];
            foreach ( $rules as $group ) {
                foreach ( $group['conditions'] ?? [] as $condition ) {
                    $ref      = $condition['field'] ?? '';
                    $target   = array_search( $ref, array_column( $fields, 'field_key' ), true );
                    $field_id = isset( $fields[ $ref ] ) ? $ref : ( false === $target ? null : array_keys( $fields )[ $target ] );
                    Support::check( null !== $field_id, __( 'Conditional rules must reference an existing field.', 'directorist' ) );
                    Support::check( in_array( $condition['operator'] ?? '', [ 'is', '==', '=', 'is not', '!=', 'not', 'contains', 'does not contain', 'greater than', '>', 'less than', '<', 'greater than or equal', '>=', 'less than or equal', '<=', 'starts with', 'ends with', 'empty', 'not empty', 'is empty', 'is not empty' ], true ), __( 'Unsupported conditional operator.', 'directorist' ) );
                    $graph[ $id ][] = $field_id;
                }
            }
        }
        $visit = static function ( $id, $path ) use ( &$visit, $graph ) {
            Support::check( ! in_array( $id, $path, true ), __( 'Conditional rules contain a cycle.', 'directorist' ) );
            foreach ( $graph[ $id ] ?? [] as $child ) {
                $visit( $child, array_merge( $path, [ $id ] ) );
            }
        };
        foreach ( array_keys( $graph ) as $id ) {
            $visit( $id, [] );
        }
        $before_fields = $before['submission_form_fields']['fields'] ?? [];
        $removed       = array_diff( array_merge( array_keys( $before_fields ), array_column( $before_fields, 'field_key' ) ), array_merge( array_keys( $fields ), $keys ) );
        $legacy        = [];
        $collect       = static function ( $node ) use ( &$collect, &$legacy ) {
            if ( ! is_array( $node ) ) {
                return;
            }
            if ( isset( $node['original_widget_key'] ) ) {
                $legacy[] = $node['original_widget_key'];
            }
            foreach ( $node as $child ) {
                $collect( $child );
            }
        };
        $collect( $before );
        $available = array_merge( array_keys( $fields ), $keys, $legacy );
        $walk      = static function ( $node ) use ( &$walk, $removed, $available ) {
            if ( ! is_array( $node ) ) {
                return;
            }
            if ( isset( $node['original_widget_key'] ) ) {
                Support::check( ! in_array( $node['original_widget_key'], $removed, true ), __( 'A search or layout widget still references a removed field.', 'directorist' ), 'dependency_blocked' );
            }
            if ( isset( $node['original_widget_key'] ) ) {
                Support::check( in_array( $node['original_widget_key'], $available, true ), __( 'Widget references an unknown submission field.', 'directorist' ) );
            }
            foreach ( $node as $child ) {
                $walk( $child );
            }
        };
        foreach ( array_diff_key( $after, [ 'submission_form_fields' => true ] ) as $value ) {
            $walk( $value );
        }
    }
}
