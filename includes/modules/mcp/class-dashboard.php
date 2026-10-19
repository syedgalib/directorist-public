<?php
namespace Directorist\MCP;

defined( 'ABSPATH' ) || exit;

/** Scope-aware read interfaces; never renders the dashboard or invokes its action handlers. */
class Dashboard {
    public static function definitions() {
        $scope     = [ 'scope' => Read_Support::enum_schema( [ 'site', 'own' ] ) ];
        $period    = [ 'period' => Read_Support::enum_schema( [ 'last_7_days', 'last_30_days', 'last_90_days' ] ), 'start_date' => Read_Support::string_schema( 10 ), 'end_date' => Read_Support::string_schema( 10 ) ];
        $directory = [ 'directory_id' => [ 'type' => 'integer', 'minimum' => 1 ] ];
        $filters   = $scope + $directory + $period;
        return [
            'get-dashboard-schema'    => Read_Support::definition( 'dashboard', 'Discover authorized dashboard metrics, sources, sections, scopes and constraints.', $scope ),
            'get-dashboard-overview'  => Read_Support::definition( 'dashboard', 'Read authorized listing health and purchase summaries with source and range semantics.', $filters + [ 'sections' => Read_Support::list_schema( 8 ) ] ),
            'get-dashboard-series'    => Read_Support::definition( 'dashboard', 'Read measured daily trends or an explicitly identified current distribution.', $filters + [ 'metric' => Read_Support::string_schema(), 'currency' => [ 'type' => 'string', 'pattern' => '^[A-Z]{3,4}$', 'maxLength' => 4 ], 'compare_previous' => [ 'type' => 'boolean' ], 'series_kind' => Read_Support::enum_schema( [ 'auto', 'daily' ] ) ], [ 'metric' ] ),
            'list-dashboard-activity' => Read_Support::definition( 'dashboard', 'Read a bounded permission-filtered recent feed, with separate upcoming reminders.', $filters + Read_Support::paging_schema() + [ 'type' => Read_Support::enum_schema( [ 'all', 'listing', 'review', 'payment', 'user' ] ) ] ),
            'get-dashboard-guidance'  => Read_Support::definition( 'dashboard', 'Read native setup progress and safe next actions without executing them.', $scope + $directory + Read_Support::paging_schema() + [ 'sections' => Read_Support::list_schema( 8 ) ] ),
        ];
    }

    public static function listing_access() {
        $type = get_post_type_object( ATBDP_POST_TYPE );
        return $type && ( current_user_can( 'manage_options' ) || current_user_can( $type->cap->edit_posts ) );
    }

    public static function permission( $tool, array $args = [] ) {
        $scope = $args['scope'] ?? ( current_user_can( 'manage_options' ) ? 'site' : 'own' );
        Support::check( in_array( $scope, [ 'site', 'own' ], true ), __( 'Unsupported dashboard scope.', 'directorist' ) );
        if ( 'site' === $scope ) {
            Access::admin();
        } else {
            Support::check( self::listing_access() || Orders::admission(), __( 'Own dashboard access is unavailable.', 'directorist' ), 'forbidden' );
        }
        return $scope;
    }

    public static function range( array $args ) {
        $today  = current_datetime()->setTime( 0, 0 );
        $custom = isset( $args['start_date'] ) || isset( $args['end_date'] );
        Support::check( ! $custom || ( isset( $args['start_date'], $args['end_date'] ) && ! isset( $args['period'] ) ), __( 'Use one preset or both explicit dates.', 'directorist' ) );
        $parse = static function ( $value ) {
            $date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() );
            Support::check( $date && $date->format( 'Y-m-d' ) === $value, __( 'Use a valid local YYYY-MM-DD date.', 'directorist' ) );
            return $date;
        };
        $end   = $custom ? $parse( $args['end_date'] ) : $today;
        $start = $custom ? $parse( $args['start_date'] ) : $end->modify( '-' . ( [ 'last_7_days' => 6, 'last_30_days' => 29, 'last_90_days' => 89 ][ $args['period'] ?? 'last_30_days' ] ) . ' days' );
        Support::check( $start <= $end && $end <= $today && $start->diff( $end )->days < 90, __( 'Use an ordered range of at most 90 local dates, ending no later than today.', 'directorist' ) );
        return [ 'start_date' => $start->format( 'Y-m-d' ), 'end_date' => $end->format( 'Y-m-d' ), 'start_utc' => $start->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ), 'end_exclusive_utc' => $end->modify( '+1 day' )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ), 'days' => $start->diff( $end )->days + 1, 'partial' => $end == $today ];
    }

    public static function sections( $scope ) {
        $out = self::listing_access() ? [ 'listings', 'views' ] : [];
        if ( Orders::admission() ) {
            $out[] = 'orders';
        }
        if ( 'own' === $scope && self::listing_access() ) {
            $out = array_merge( $out, [ 'favorites', 'enquiries' ] );
        }
        return $out;
    }

    public static function read( $tool, array $args ) {
        $scope         = self::permission( $tool, $args );
        $args['scope'] = $scope;
        $result        = Read_Support::envelope( $scope );
        if ( isset( $args['directory_id'] ) ) {
            Support::directory( $args['directory_id'] );
        }
        $contributions = Read_Contributions::descriptors( 'dashboard', $args );
        if ( 'get-dashboard-schema' === $tool ) {
            $result += [ 'scopes' => current_user_can( 'manage_options' ) ? [ 'site', 'own' ] : [ 'own' ], 'sections' => self::sections( $scope ), 'metrics' => self::listing_access() ? [ 'published_listings', 'listing_views' ] : [], 'guidance' => 'site' === $scope ? [ 'setup', 'quick_actions', 'recommendations' ] : [ 'quick_actions' ], 'activity_types' => Dashboard_Data::activity_types( $scope ), 'limits' => [ 'page_default' => 20, 'page_maximum' => 100, 'range_days' => 90, 'comparison_source_days' => 180, 'activity_window' => 1000, 'currency_groups' => 20, 'listing_scan_budget' => Dashboard_Data::LISTING_BUDGET ], 'definitions' => [ 'published_listings' => 'Current eligible stock; series groups currently published listings by native post date.', 'listing_views' => 'Lifetime cumulative published-listing views; a distribution is not daily traffic or unique visitors.', 'paid_order_value' => 'Native paid order value by creation date and recorded currency; not cash receipts or profit.', 'directory_filter' => 'Supported for listing data and listing guidance. Financial, favorites, setup, review and user sources reject directory allocation.', 'financial_amount_source' => 'Native stored order amount; current records use created_at UTC. Unmapped legacy purchases have incomplete currency coverage.', 'analytics' => 'Daily history requires an explicitly registered compatible metric with coverage; a table alone does not prove retention.' ], 'contributions' => array_values( array_map( [ Read_Contributions::class, 'metadata' ], $contributions ) ), 'source_versions' => [ 'directorist' => ATBDP_VERSION, 'read_adapter' => Registry::READ_ADAPTER_VERSION ] ];
            if ( Orders::admission() ) {
                $result['metrics'][] = 'paid_order_value';
            } else {
                unset( $result['definitions']['paid_order_value'], $result['definitions']['financial_amount_source'] );
            }
            if ( ! self::listing_access() ) {
                unset( $result['definitions']['published_listings'], $result['definitions']['listing_views'], $result['definitions']['directory_filter'], $result['definitions']['analytics'] );
            }
            $result['defaults']     = [ 'scope' => $scope, 'period' => 'last_30_days', 'series_kind' => 'auto', 'compare_previous' => false, 'page' => 1, 'per_page' => 20 ];
            $result['availability'] = [];
            foreach ( array_merge( self::sections( $scope ), $result['guidance'] ) as $section ) {
                $missing                            = in_array( $section, [ 'enquiries', 'recommendations' ], true ) || ( 'setup' === $section && ! method_exists( '\\ATBDP_Extension_Activity', 'get_dashboard_setup_details' ) );
                $result['availability'][ $section ] = [ 'state' => $missing ? 'unavailable' : 'available', 'reason' => $missing ? 'no_compatible_registered_source' : null ];
            }
            return Read_Support::bounded( $result );
        }
        if ( 'get-dashboard-guidance' === $tool ) {
            return Read_Support::bounded( $result + [ 'filters' => $args, 'sections' => Dashboard_Guidance::read( $args, $contributions ) ] );
        }
        $range             = self::range( $args );
        $result['filters'] = [ 'scope' => $scope, 'directory_id' => $args['directory_id'] ?? null, 'range' => $range ];
        if ( 'list-dashboard-activity' === $tool ) {
            return Read_Support::bounded( $result + Dashboard_Data::activity( $args, $range ) );
        }
        if ( 'get-dashboard-series' === $tool ) {
            if ( isset( $contributions[ $args['metric'] ] ) && 'metric' === $contributions[ $args['metric'] ]['kind'] ) {
                return $result + [ 'series' => Read_Contributions::read( $contributions[ $args['metric'] ], $args ) ];
            }
            $allowed = self::listing_access() ? [ 'published_listings', 'listing_views' ] : [];
            if ( Orders::admission() ) {
                $allowed[] = 'paid_order_value';
            }
            Support::check( in_array( $args['metric'], $allowed, true ), __( 'This metric is unavailable or forbidden.', 'directorist' ), 'forbidden' );
            return $result + [ 'series' => self::series( $args, $range ) ];
        }
        $extensions = array_filter(
            $contributions, static function ( $d ) { return 'overview' === $d['kind'];
            }
        );
        $selected   = Read_Support::selected( $args, 'sections', self::sections( $scope ), array_merge( self::sections( $scope ), array_keys( $extensions ) ), 8 );
        if ( isset( $args['directory_id'] ) ) {
            Support::check( ! array_intersect( $selected, [ 'orders', 'favorites', 'enquiries' ] ), __( 'Select listing sections for directory filtering; these sources have no native directory allocation.', 'directorist' ), 'unsupported_operation' );
        }
        $data     = [];
        $listings = array_intersect( $selected, [ 'listings', 'views' ] ) ? Dashboard_Data::listings( $args, $range ) : null;
        foreach ( $selected as $section ) {
            if ( isset( $extensions[ $section ] ) ) {
                $data[ $section ] = Read_Contributions::read( $extensions[ $section ], $args );
            } elseif ( 'listings' === $section ) {
                $data[ $section ] = Read_Support::section( [ 'range_kind' => 'current', 'by_status' => $listings['counts'], 'expiring_next_7_days' => $listings['expiring'] ], 'native_listings', array_sum( $listings['counts'] ) ? 'available' : 'empty' );
            } elseif ( 'views' === $section ) {
                $data[ $section ] = $listings['views_complete'] ? Read_Support::section( [ 'range_kind' => 'lifetime', 'published_listing_views' => $listings['views'] ], 'listing_meta', $listings['counts']['publish'] ? 'available' : 'empty' ) : Read_Support::section( null, 'listing_meta', 'unavailable', 'invalid_stored_view_count' );
            } elseif ( 'orders' === $section ) {
                $financial        = Dashboard_Data::financial( $args, $range );
                $data[ $section ] = Read_Support::section( $financial, 'native_orders', $financial['coverage_complete'] ? ( $financial['groups'] ? 'available' : 'empty' ) : 'partial' );
            } elseif ( 'favorites' === $section ) {
                $favorites        = Dashboard_Data::favorites();
                $data[ $section ] = Read_Support::section( [ 'count' => $favorites ], 'native_favorites', $favorites ? 'available' : 'empty' );
            } else {
                $data[ $section ] = Read_Support::section( null, 'registered_enquiry_kpi', 'unavailable', 'no_compatible_registered_source' );
            }
        }
        return Read_Support::bounded( $result + [ 'sections' => $data ] );
    }

    private static function series( array $args, array $range ) {
        $metric   = $args['metric'];
        $previous = ! empty( $args['compare_previous'] );
        $scan     = $range;
        if ( $previous ) {
            $scan = self::range( [ 'start_date' => ( new \DateTimeImmutable( $range['start_date'], wp_timezone() ) )->modify( '-' . $range['days'] . ' days' )->format( 'Y-m-d' ), 'end_date' => ( new \DateTimeImmutable( $range['start_date'], wp_timezone() ) )->modify( '-1 day' )->format( 'Y-m-d' ) ] );
        }
        if ( 'listing_views' === $metric ) {
            if ( 'daily' === ( $args['series_kind'] ?? '' ) ) {
                return Read_Support::section( null, 'registered_analytics', 'unavailable', 'daily_coverage_requires_registered_source' );
            }
            $data = Dashboard_Data::listings( $args, $range );
            if ( ! $data['views_complete'] ) {
                return Read_Support::section( null, 'listing_meta', 'unavailable', 'invalid_stored_view_count' );
            }
            return Read_Support::section( [ 'kind' => 'distribution', 'range_kind' => 'lifetime', 'units' => 'views', 'basis' => 'equal_listing_count_buckets_ranked_by_lifetime_views', 'points' => $data['distribution'], 'comparison' => null ], 'listing_meta' );
        }
        if ( 'paid_order_value' === $metric ) {
            $current    = Dashboard_Data::financial( $args, $range, true );
            $before     = $previous ? Dashboard_Data::financial( $args, $scan, true ) : null;
            $currencies = array_unique( array_merge( array_column( $current['groups'], 'currency' ), $before ? array_column( $before['groups'], 'currency' ) : [] ) );
            Support::check( count( $currencies ) <= 20, __( 'Too many currencies for this range.', 'directorist' ), 'unsupported_operation' );
            Support::check( isset( $args['currency'] ) || count( $currencies ) <= 1, __( 'Select one recorded currency for financial series.', 'directorist' ) );
            $currency = $args['currency'] ?? ( reset( $currencies ) ?: null );
            if ( ! $currency ) {
                return Read_Support::section( null, 'native_orders', $current['coverage_complete'] ? 'empty' : 'partial', 'no_unambiguous_currency' );
            }
            $map      = $current['daily'][ $currency ] ?? [];
            $old      = $before['daily'][ $currency ] ?? [];
            $complete = $current['coverage_complete'] && ( ! $before || $before['coverage_complete'] );
        } else {
            $map      = Dashboard_Data::listings( $args, $range )['daily'];
            $old      = $previous ? Dashboard_Data::listings( $args, $scan )['daily'] : [];
            $currency = null;
            $complete = true;
        }
        $points = [];
        $sum    = 0;
        $date   = new \DateTimeImmutable( $range['start_date'], wp_timezone() );
        for ( $i = 0; $i < $range['days']; ++$i ) {
            $key   = $date->format( 'Y-m-d' );
            $value = $map[ $key ] ?? 0;
            $sum  += $value;
            Support::check( is_int( $sum ), __( 'The monetary aggregate exceeds exact arithmetic bounds.', 'directorist' ), 'unsupported_operation' );
            $points[] = [ 'date' => $key, 'value' => $complete ? ( $currency ? Money::decimal( $value, $currency ) : $value ) : null, 'partial' => $range['partial'] && $key === $range['end_date'] ];
            $date     = $date->modify( '+1 day' );
        }
        $baseline   = array_sum( $old );
        $comparison = $previous && $complete ? [ 'kind' => 0 === $baseline ? ( $sum ? 'new' : 'flat' ) : 'percentage', 'percent' => $baseline ? round( ( $sum - $baseline ) / $baseline * 100, 2 ) : null, 'partial' => $range['partial'], 'equal_elapsed_time' => ! $range['partial'], 'range' => $scan ] : null;
        return Read_Support::section( [ 'kind' => 'daily', 'units' => $currency ? 'order_value' : 'listings', 'currency' => $currency, 'precision' => $currency ? Money::precision( $currency ) : null, 'points' => $points, 'coverage_complete' => $complete, 'comparison' => $comparison ], $currency ? 'native_orders' : 'posts_daily', $complete ? 'available' : 'partial' );
    }
}
