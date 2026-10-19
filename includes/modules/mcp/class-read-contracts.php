<?php
namespace Directorist\MCP;

defined( 'ABSPATH' ) || exit;

/** Published closed output contracts, assembled once from the full registration inventory. */
class Read_Contracts {
    private static function object( array $properties, array $required = [] ) {
        return [ 'type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false ];
    }

    private static function array_of( $schema, $max = 100 ) {
        return [ 'type' => 'array', 'items' => $schema, 'maxItems' => $max ];
    }

    private static function nullable( $schema ) {
        return [ 'anyOf' => [ $schema, [ 'type' => 'null' ] ] ];
    }

    private static function literal_shape( $value ) {
        if ( is_array( $value ) || is_object( $value ) ) {
            $value = (array) $value;
            if ( $value && array_keys( $value ) === range( 0, count( $value ) - 1 ) ) {
                return self::array_of( [ 'anyOf' => array_map( [ self::class, 'literal_shape' ], $value ) ], count( $value ) );
            }
            return self::object( array_map( [ self::class, 'literal_shape' ], $value ) );
        }
        return [ 'type' => is_bool( $value ) ? 'boolean' : ( is_int( $value ) ? 'integer' : 'string' ), 'enum' => [ $value ] ];
    }

    private static function collection( $item, $max = 100 ) {
        $integer = [ 'type' => 'integer', 'minimum' => 0 ];
        return [ 'items' => self::array_of( $item, $max ), 'page' => $integer, 'per_page' => $integer, 'total' => $integer, 'total_pages' => $integer, 'has_more' => [ 'type' => 'boolean' ] ];
    }

    public static function schema( $tool, array $adapters ) {
        $s        = [ 'type' => 'string', 'maxLength' => 2048 ];
        $n        = self::nullable( $s );
        $i        = [ 'type' => 'integer', 'minimum' => 0 ];
        $b        = [ 'type' => 'boolean' ];
        $strings  = self::array_of( $s );
        $base     = [ 'schema_version' => $s, 'scope' => $s, 'timezone' => $s, 'generated_at' => $s, 'request_id' => $s ];
        $required = array_keys( $base );
        $range    = self::object( [ 'start_date' => $s, 'end_date' => $s, 'start_utc' => $s, 'end_exclusive_utc' => $s, 'days' => $i, 'partial' => $b ] );
        $state    = [ 'state' => Read_Support::enum_schema( [ 'available', 'empty', 'unavailable', 'partial', 'stale' ] ), 'source' => $s, 'as_of' => $s, 'cached' => $b, 'age_seconds' => $i, 'reason' => $n ];
        if ( in_array( $tool, [ 'discover-site-health-info', 'get-site-health-info', 'get-site-health-report' ], true ) ) {
            $base  += [ 'source_versions' => self::object( [ 'wordpress' => $n, 'directorist' => $s, 'collector' => $s ] ), 'redaction_policy_version' => $s ];
            $values = [ [ 'type' => [ 'string', 'boolean', 'integer', 'number', 'null' ], 'maxLength' => 2048 ], self::array_of( $s ), self::array_of( self::object( [ 'owner' => $s, 'contract_version' => $s, 'compatibility' => $s ] ) ) ];
            foreach ( $adapters['site_health_info'] ?? [] as $d ) {
                foreach ( (array) $d['output_schema']['properties'] as $schema ) {
                    $values[] = $schema;
                }
            }
            $field     = self::object( [ 'key' => $s, 'label' => $s, 'units' => $n, 'type' => $s, 'value' => [ 'anyOf' => $values ], 'state' => Read_Support::enum_schema( [ 'available', 'empty', 'unavailable', 'redacted' ] ) ], [ 'key', 'value', 'state' ] );
            $inventory = self::object( [ 'id' => $s, 'name' => $n, 'version' => $n, 'category' => $s, 'update_state' => $s, 'update_observed_at' => $n, 'update_age_seconds' => self::nullable( $i ), 'compatibility' => $s, 'compatibility_rule' => $s ] );
            $section   = self::object( [ 'id' => $s, 'source_scope' => $s, 'state' => $s, 'reason' => $n, 'as_of' => $s, 'cached' => $b, 'age_seconds' => $i, 'source_version' => $s ] + self::collection( [ 'anyOf' => [ $field, $inventory ] ], 1000 ) );
            if ( 'discover-site-health-info' === $tool ) {
                $schemas = [];
                foreach ( Site_Health::core_fields() as $id => $fields ) {
                    foreach ( $fields as $key => $type ) {
                        $schemas[] = self::literal_shape( Site_Health::field_schema( $id, $key, $type ) );
                    }
                }
                foreach ( $adapters['site_health_info'] ?? [] as $d ) {
                    foreach ( (array) $d['output_schema']['properties'] as $schema ) {
                        $schemas[] = self::literal_shape( $schema );
                    }
                }
                $descriptor = self::object( [ 'id' => $s, 'label' => $s, 'kind' => $s, 'state' => $s, 'source_version' => $s, 'key' => $s, 'type' => $s, 'sensitivity' => $s, 'nullable' => $b, 'schema' => [ 'anyOf' => $schemas ], 'units' => $n, 'reason' => $n, 'source_scope' => $s ] );
                $base      += self::collection( $descriptor ) + [ 'limits' => self::object( array_fill_keys( [ 'page_default', 'page_maximum', 'report_sections', 'report_items', 'report_bytes', 'scalar_characters' ], $i ) ), 'report_formats' => $strings ];
            } elseif ( 'get-site-health-info' === $tool ) {
                $base['section'] = $section;
            } else {
                $base += [ 'sections' => self::array_of( $section, 10 ), 'coverage' => self::object( [ 'included' => $strings, 'complete' => $b, 'omissions' => $strings ] ), 'format' => $s, 'content' => [ 'type' => 'string', 'maxLength' => 262144 ] ];
            }
            return self::object( $base, $required );
        }
        $status              = self::object( array_fill_keys( [ 'publish', 'pending', 'private', 'expired', 'rejected', 'draft', 'trash', 'paid', 'unpaid', 'prepaid', 'failed', 'cancelled', 'refunded', 'created', 'completed' ], $i ) );
        $group               = self::object( [ 'currency' => $s, 'precision' => $i, 'count' => $i, 'amount' => $s ], [ 'currency', 'amount' ] );
        $action              = self::object( [ 'key' => $s, 'label' => $s, 'url' => $s, 'tool' => $n ] );
        $directory_action    = self::object( [ 'directory_id' => $i, 'label' => $s, 'actions' => self::array_of( $action, 10 ) ] );
        $step                = self::object( [ 'key' => $s, 'label' => $s, 'url' => $s, 'complete' => $b ] );
        $point               = self::object( [ 'date' => $s, 'value' => [ 'type' => [ 'integer', 'string', 'null' ], 'maxLength' => 32 ], 'partial' => $b ] );
        $data                = [
            self::object( [ 'range_kind' => $s, 'by_status' => $status, 'expiring_next_7_days' => $i ], [ 'by_status' ] ),
            self::object( [ 'range_kind' => $s, 'published_listing_views' => $i ], [ 'published_listing_views' ] ),
            self::object( [ 'range_kind' => $s, 'meaning' => $s, 'groups' => self::array_of( $group, 20 ), 'by_status' => $status, 'coverage_complete' => $b, 'coverage_reason' => $n, 'excluded_records' => $i ], [ 'groups', 'coverage_complete' ] ),
            self::object( [ 'count' => $i ], [ 'count' ] ),
            self::object( [ 'kind' => $s, 'range_kind' => $s, 'units' => $s, 'basis' => $s, 'points' => self::array_of( [ 'type' => 'integer', 'minimum' => 0 ], 8 ), 'comparison' => [ 'type' => 'null' ] ], [ 'range_kind' ] ),
            self::object( [ 'kind' => $s, 'units' => $s, 'currency' => $n, 'precision' => self::nullable( $i ), 'points' => self::array_of( $point, 90 ), 'coverage_complete' => $b, 'comparison' => self::nullable( self::object( [ 'kind' => $s, 'percent' => [ 'type' => [ 'number', 'null' ] ], 'partial' => $b, 'equal_elapsed_time' => $b, 'range' => $range ] ) ) ], [ 'coverage_complete', 'points' ] ),
            self::object( [ 'visible' => $b, 'dismissed' => $b, 'progress' => $i, 'steps' => self::array_of( $step, 10 ), 'meaning' => $s ], [ 'steps' ] ),
            self::object( self::collection( [ 'anyOf' => [ $action, $directory_action ] ] ) + [ 'global_actions' => self::array_of( $action, 10 ) ], [ 'items' ] ),
            [ 'type' => 'null' ],
        ];
        $contribution_shapes = [];
        foreach ( $adapters['dashboard'] ?? [] as $d ) {
            $data[]                = $d['output_schema'];
            $contribution_shapes[] = self::object( [ 'id' => $s, 'owner' => $s, 'label' => $s, 'kind' => $s, 'units' => $s, 'range_semantics' => $s, 'filters' => $strings, 'contract_version' => $s, 'source_version' => $s, 'schema' => self::literal_shape( $d['output_schema'] ), 'state' => $s ] );
        }
        $section     = self::object( $state + [ 'data' => [ 'anyOf' => $data ] ], [ 'state', 'source', 'data' ] );
        $section_map = array_fill_keys( [ 'listings', 'views', 'orders', 'favorites', 'enquiries', 'setup', 'quick_actions', 'recommendations' ], $section );
        foreach ( $adapters['dashboard'] ?? [] as $key => $d ) {
            $section_map[ $key ] = $section;
        }
        if ( 'get-dashboard-schema' === $tool ) {
            $base['defaults']     = self::object( [ 'scope' => $s, 'period' => $s, 'series_kind' => $s, 'compare_previous' => $b, 'page' => $i, 'per_page' => $i ] );
            $base['availability'] = self::object( array_fill_keys( [ 'listings', 'views', 'orders', 'favorites', 'enquiries', 'setup', 'quick_actions', 'recommendations' ], self::object( [ 'state' => $s, 'reason' => $n ] ) ) );
            $base                += [ 'scopes' => $strings, 'sections' => $strings, 'metrics' => $strings, 'guidance' => $strings, 'activity_types' => $strings, 'limits' => self::object( array_fill_keys( [ 'page_default', 'page_maximum', 'range_days', 'comparison_source_days', 'activity_window', 'currency_groups', 'listing_scan_budget' ], $i ) ), 'definitions' => self::object( array_fill_keys( [ 'published_listings', 'listing_views', 'paid_order_value', 'directory_filter', 'financial_amount_source', 'analytics' ], $s ) ), 'contributions' => self::array_of( $contribution_shapes ? [ 'anyOf' => $contribution_shapes ] : self::object( [] ) ), 'source_versions' => self::object( [ 'directorist' => $s, 'read_adapter' => $i ] ) ];
        } else {
            $filters         = [ 'scope' => $s, 'directory_id' => self::nullable( $i ), 'range' => $range, 'page' => $i, 'per_page' => $i, 'sections' => $strings ];
            $base['filters'] = self::object( $filters );
            if ( 'get-dashboard-series' === $tool ) {
                $base['series'] = $section;
            } elseif ( 'list-dashboard-activity' === $tool ) {
                $event      = self::object( [ 'id' => $s, 'object_id' => $i, 'type' => $s, 'timestamp' => $s, 'upcoming' => $b, 'detail_tool' => $n, 'meaning' => $s ] );
                $collection = self::collection( $event );
                unset( $collection['total'] );
                $base += $collection + [ 'upcoming' => self::object( $collection + [ 'window_total' => $i, 'truncated' => $b, 'continuation_limit' => $i ] ), 'window_total' => $i, 'history_start' => $s, 'truncated' => $b, 'continuation_limit' => $i, 'as_of' => $s, 'ordering' => $s, 'source_coverage' => $s ];
            } else {
                $base['sections'] = self::object( $section_map );
            }
        }
        return self::object( $base, $required );
    }
}
