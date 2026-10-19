<?php
namespace Directorist\MCP;

defined( 'ABSPATH' ) || exit;

/** Bounded chunked native reads. Permissions precede aggregates, counts and feed slicing. */
class Dashboard_Data {
    const LISTING_BUDGET = 20000;

    public static function listings( array $args, array $range, $activity = false ) {
        global $wpdb;
        Support::check( Dashboard::listing_access(), __( 'Listing access is required.', 'directorist' ), 'forbidden' );
        $where  = $wpdb->prepare( 'p.post_type = %s', ATBDP_POST_TYPE );
        $where .= " AND p.post_status IN ('publish','pending','private','expired','rejected','draft','trash')";
        if ( 'own' === $args['scope'] ) {
            $where .= $wpdb->prepare( ' AND p.post_author = %d', get_current_user_id() );
        }
        if ( isset( $args['directory_id'] ) ) {
            $where .= $wpdb->prepare( " AND EXISTS (SELECT 1 FROM {$wpdb->term_relationships} r JOIN {$wpdb->term_taxonomy} t ON t.term_taxonomy_id=r.term_taxonomy_id WHERE r.object_id=p.ID AND t.taxonomy=%s AND t.term_id=%d)", ATBDP_DIRECTORY_TYPE, $args['directory_id'] );
        }
        $out          = [ 'counts' => array_fill_keys( [ 'publish', 'pending', 'private', 'expired', 'rejected', 'draft', 'trash' ], 0 ), 'views' => 0, 'views_complete' => true, 'expiring' => 0, 'daily' => [], 'distribution' => [], 'activity' => [], 'upcoming' => [] ];
        $cursor       = 0;
        $scanned      = 0;
        $listing_tool = $activity ? Read_Support::tool_reference( 'directorist-get-listing' ) : null;
        $now          = current_datetime();
        $end          = $now->modify( '+7 days' );
        do {
            $rows     = Read_Support::query( "SELECT p.* FROM {$wpdb->posts} p WHERE {$where} AND p.ID > " . (int) $cursor . ' ORDER BY p.ID ASC LIMIT 128' );
            $scanned += count( $rows );
            Support::check( $scanned <= self::LISTING_BUDGET, __( 'This listing scope exceeds the native object-permission scan budget. Select a directory or use a narrower source.', 'directorist' ), 'unsupported_operation' );
            if ( ! $rows ) {
                break;
            }
            $ids       = array_map( 'intval', array_column( $rows, 'ID' ) );
            $keys      = [ '_expiry_date', '_never_expire', directorist_get_listing_views_count_meta_key() ];
            $meta_rows = Read_Support::query( $wpdb->prepare( "SELECT post_id,meta_key,meta_value FROM {$wpdb->postmeta} WHERE post_id IN (" . implode( ',', $ids ) . ') AND meta_key IN (%s,%s,%s) ORDER BY meta_id ASC', ...$keys ) );
            $meta      = [];
            foreach ( $meta_rows as $item ) {
                if ( ! isset( $meta[ $item['post_id'] ][ $item['meta_key'] ] ) ) {
                    $meta[ $item['post_id'] ][ $item['meta_key'] ] = $item['meta_value'];
                }
            }
            foreach ( $rows as $row ) {
                $id     = (int) $row['ID'];
                $cursor = $id;
                $cached = wp_cache_get( $id, 'posts' );
                if ( ! $cached ) {
                    wp_cache_add( $id, new \WP_Post( (object) $row ), 'posts' );
                }
                try {
                    Access::listing( $id );
                    Support::check( true === apply_filters( 'directorist_rest_check_permissions', true, 'read', $id, ATBDP_POST_TYPE ), __( 'This listing is restricted.', 'directorist' ), 'forbidden' );
                    $status = $row['post_status'];
                    ++$out['counts'][ $status ];
                    if ( 'publish' === $status ) {
                        $views = $meta[ $id ][ $keys[2] ] ?? '0';
                        if ( ! ctype_digit( (string) $views ) || strlen( (string) $views ) >= 15 ) {
                            $out['views_complete'] = false;
                        }
                        $views                 = ctype_digit( (string) $views ) && strlen( (string) $views ) < 15 ? (int) $views : 0;
                        $out['views']         += $views;
                        $out['distribution'][] = $views;
                        $date                  = substr( $row['post_date'], 0, 10 );
                        if ( $date >= $range['start_date'] && $date <= $range['end_date'] ) {
                            $out['daily'][ $date ] = ( $out['daily'][ $date ] ?? 0 ) + 1;
                        }
                        $expiry  = $meta[ $id ]['_expiry_date'] ?? '';
                        $expires = self::expiry( $expiry );
                        if ( empty( $meta[ $id ]['_never_expire'] ) && $expires && $expires >= $now && $expires < $end ) {
                            ++$out['expiring'];
                            if ( $activity ) {
                                $out['upcoming'][] = self::event( 'expiry', $id, 'listing', $expires->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ), true, $listing_tool );
                            }
                        }
                    }
                    if ( $activity ) {
                        $timestamp = $row['post_modified_gmt'] !== '0000-00-00 00:00:00' ? $row['post_modified_gmt'] : $row['post_date_gmt'];
                        if ( $timestamp >= $range['start_utc'] && $timestamp < $range['end_exclusive_utc'] ) {
                            $out['activity'][] = self::event( 'listing', $id, 'listing', $timestamp, false, $listing_tool );
                        }
                    }
                } catch ( Failure $error ) {
                    if ( ! in_array( $error->reason, [ 'not_found', 'forbidden' ], true ) ) {
                        throw $error;
                    }
                } finally {
                    if ( ! $cached ) {
                        wp_cache_delete( $id, 'posts' );
                    }
                }
            }
            self::trim( $out['activity'] );
            self::trim( $out['upcoming'] );
        } while ( count( $rows ) === 128 );
        // Equal-count buckets of listings ranked by lifetime views, never time buckets.
        $distribution = $out['distribution'];
        rsort( $distribution, SORT_NUMERIC );
        $out['distribution'] = [];
        if ( $distribution ) {
            foreach ( array_chunk( $distribution, (int) ceil( count( $distribution ) / 8 ) ) as $bucket ) {
                $out['distribution'][] = array_sum( $bucket );
            }
        }
        return $out;
    }

    private static function expiry( $value ) {
        if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}(?: \d{2}:\d{2}:\d{2})?$/', $value ) ) {
            return null;
        }
        $format = strlen( $value ) === 10 ? '!Y-m-d' : '!Y-m-d H:i:s';
        $date   = \DateTimeImmutable::createFromFormat( $format, $value, wp_timezone() );
        return $date && $date->format( substr( $format, 1 ) ) === $value ? $date : null;
    }

    public static function financial( array $args, array $range, $series = false ) {
        global $wpdb;
        Support::check( Orders::admission(), __( 'Order access is unavailable.', 'directorist' ), 'forbidden' );
        Support::check( ! isset( $args['directory_id'] ), __( 'Financial sources have no supported directory allocation.', 'directorist' ), 'unsupported_operation' );
        $table = Orders::tables()[0];
        $where = $wpdb->prepare( 'o.created_at >= FROM_UNIXTIME(%d) AND o.created_at < FROM_UNIXTIME(%d)', strtotime( $range['start_utc'] . ' UTC' ), strtotime( $range['end_exclusive_utc'] . ' UTC' ) );
        if ( 'own' === $args['scope'] ) {
            $where .= $wpdb->prepare( ' AND o.user_id=%d', get_current_user_id() );
        }
        $unambiguous = " AND (o.legacy_id IS NULL OR o.legacy_id=0 OR NOT EXISTS (SELECT 1 FROM {$table} other_order WHERE other_order.legacy_id=o.legacy_id AND other_order.id<>o.id))";
        $ambiguous   = Read_Support::query( "SELECT COUNT(*) count FROM {$table} o WHERE {$where} AND o.legacy_id>0 AND EXISTS (SELECT 1 FROM {$table} other_order WHERE other_order.legacy_id=o.legacy_id AND other_order.id<>o.id)" );
        $where      .= $unambiguous;
        $rows        = [];
        // Explicit UTC bounds per local day also work when MySQL timezone tables are absent.
        if ( $series ) {
            $case = 'CASE';
            $date = new \DateTimeImmutable( $range['start_date'], wp_timezone() );
            for ( $day = 0; $day < $range['days']; ++$day ) {
                $next  = $date->modify( '+1 day' );
                $case .= $wpdb->prepare( ' WHEN o.created_at >= FROM_UNIXTIME(%d) AND o.created_at < FROM_UNIXTIME(%d) THEN %s', $date->getTimestamp(), $next->getTimestamp(), $date->format( 'Y-m-d' ) );
                $date  = $next;
            }
            $case .= ' END';
            $rows  = Read_Support::query( "SELECT o.currency,o.status,{$case} local_date,SUM(o.amount) amount,COUNT(*) count FROM {$table} o WHERE {$where} GROUP BY o.currency,o.status,local_date ORDER BY o.currency,o.status,local_date" );
        } else {
            $rows = Read_Support::query( "SELECT o.currency,o.status,SUM(o.amount) amount,COUNT(*) count FROM {$table} o WHERE {$where} GROUP BY o.currency,o.status ORDER BY o.currency,o.status" );
        }
        $groups     = [];
        $daily      = [];
        $statuses   = [];
        $incomplete = (int) $ambiguous[0]['count'];
        foreach ( $rows as $row ) {
            $status = $row['status'];
            Support::check( preg_match( '/^[a-z_]{1,32}$/', $status ), __( 'Unsupported stored order status.', 'directorist' ), 'unsupported_operation' );
            $statuses[ $status ] = ( $statuses[ $status ] ?? 0 ) + (int) $row['count'];
            if ( 'paid' !== $status ) {
                continue;
            }
            $currency = $row['currency'];
            try {
                $minor = Money::minor( Money::stored( $row['amount'], $currency ), $currency );
            } catch ( Failure $error ) {
                $incomplete += (int) $row['count'];
                continue;
            }
            if ( ! isset( $groups[ $currency ] ) ) {
                $groups[ $currency ] = [ 'currency' => $currency, 'precision' => Money::precision( $currency ), 'count' => 0, 'minor' => 0 ];
            }
            Support::check( count( $groups ) <= 20, __( 'Too many currencies in the requested range.', 'directorist' ), 'unsupported_operation' );
            $groups[ $currency ]['count'] += (int) $row['count'];
            $groups[ $currency ]['minor'] += $minor;
            Support::check( is_int( $groups[ $currency ]['minor'] ), __( 'Financial aggregate exceeds exact arithmetic bounds.', 'directorist' ), 'unsupported_operation' );
            if ( $series ) {
                $daily[ $currency ][ $row['local_date'] ] = $minor;
            }
        }
        $legacy_where = $wpdb->prepare( 'p.post_type=%s AND p.post_status=%s AND p.post_date_gmt >= %s AND p.post_date_gmt < %s', ATBDP_ORDER_POST_TYPE, 'publish', $range['start_utc'], $range['end_exclusive_utc'] );
        if ( 'own' === $args['scope'] ) {
            $legacy_where .= $wpdb->prepare( ' AND p.post_author=%d', get_current_user_id() );
        }
        $legacy      = Read_Support::query( "SELECT COUNT(*) count FROM {$wpdb->posts} p WHERE {$legacy_where} AND EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id=p.ID AND m.meta_key='_payment_status' AND m.meta_value='completed') AND NOT EXISTS (SELECT 1 FROM {$table} o WHERE o.legacy_id=p.ID)" );
        $incomplete += (int) $legacy[0]['count'];
        foreach ( $groups as &$group ) {
            $group['amount'] = Money::decimal( $group['minor'], $group['currency'] );
            unset( $group['minor'] );
        }
        $result = [ 'range_kind' => 'period', 'meaning' => 'own' === $args['scope'] ? 'purchase_spending_order_value' : 'paid_order_value_not_cash_receipts', 'groups' => array_values( $groups ), 'by_status' => $statuses, 'coverage_complete' => 0 === $incomplete, 'coverage_reason' => $incomplete ? 'unmapped_legacy_or_unsupported_money' : null ];
        if ( current_user_can( 'manage_options' ) ) {
            $result['excluded_records'] = $incomplete;
        }
        if ( $series ) {
            $result['daily'] = $daily;
        }
        return $result;
    }

    public static function favorites() {
        $ids = directorist_get_user_favorites( get_current_user_id() );
        Support::check( is_array( $ids ) && count( $ids ) <= self::LISTING_BUDGET, __( 'Favorites exceed the supported scope budget.', 'directorist' ), 'unsupported_operation' );
        $count = 0;
        foreach ( array_unique( array_map( 'absint', $ids ) ) as $id ) {
            $post = get_post( $id );
            if ( $post && ATBDP_POST_TYPE === $post->post_type && Access::public_listing( $post ) && current_user_can( 'read_post', $id ) && true === apply_filters( 'directorist_rest_check_permissions', true, 'read', $id, ATBDP_POST_TYPE ) ) {
                ++$count;
            }
        }
        return $count;
    }

    public static function activity_types( $scope ) {
        $types = Dashboard::listing_access() ? [ 'listing' ] : [];
        if ( Orders::admission() ) {
            $types[] = 'payment';
        }
        if ( 'site' === $scope && current_user_can( 'moderate_comments' ) && Dashboard::listing_access() ) {
            $types[] = 'review';
        }
        if ( 'site' === $scope && current_user_can( 'list_users' ) && ! is_multisite() ) {
            $types[] = 'user';
        }
        return $types;
    }

    public static function event( $source, $id, $type, $date, $upcoming = false, $tool = null ) {
        return [ 'id' => $source . ':' . $id, 'object_id' => (int) $id, 'type' => $type, 'timestamp' => str_replace( ' ', 'T', $date ) . 'Z', 'upcoming' => $upcoming, 'detail_tool' => $tool ];
    }

    private static function trim( array &$items ) {
        usort(
            $items, static function ( $a, $b ) { return $a['upcoming'] === $b['upcoming'] ? ( ( $a['upcoming'] ? strcmp( $a['timestamp'], $b['timestamp'] ) : strcmp( $b['timestamp'], $a['timestamp'] ) ) ?: ( strcmp( strstr( $a['id'], ':', true ), strstr( $b['id'], ':', true ) ) ?: $a['object_id'] <=> $b['object_id'] ) ) : ( $a['upcoming'] ? 1 : -1 );
            }
        );
        $items = array_slice( $items, 0, 1001 );
    }

    public static function activity( array $args, array $range ) {
        global $wpdb;
        $types    = self::activity_types( $args['scope'] );
        $selected = 'all' === ( $args['type'] ?? 'all' ) ? $types : [ $args['type'] ];
        Support::check( ! array_diff( $selected, $types ), __( 'This activity type is not permitted.', 'directorist' ), 'forbidden' );
        Support::check( ! isset( $args['directory_id'] ) || $selected === [ 'listing' ], __( 'Select listing activity for directory filtering.', 'directorist' ), 'unsupported_operation' );
        $items    = [];
        $upcoming = [];
        $as_of    = gmdate( 'c' );
        // Native sources have second precision. Include this second but never future events.
        $range['end_exclusive_utc'] = min( $range['end_exclusive_utc'], gmdate( 'Y-m-d H:i:s', strtotime( $as_of ) + 1 ) );
        if ( in_array( 'listing', $selected, true ) ) {
            $data     = self::listings( $args, $range, true );
            $items    = $data['activity'];
            $upcoming = $data['upcoming'];
        }
        if ( in_array( 'payment', $selected, true ) ) {
            $order_tool = Read_Support::tool_reference( 'directorist-get-order' );
            $table      = Orders::tables()[0];
            $where      = $wpdb->prepare( "o.status='paid' AND o.created_at >= FROM_UNIXTIME(%d) AND o.created_at < FROM_UNIXTIME(%d)", strtotime( $range['start_utc'] . ' UTC' ), strtotime( $range['end_exclusive_utc'] . ' UTC' ) );
            if ( 'own' === $args['scope'] ) {
                $where .= $wpdb->prepare( ' AND o.user_id=%d', get_current_user_id() );
            }
            $where .= " AND (o.legacy_id IS NULL OR o.legacy_id=0 OR NOT EXISTS (SELECT 1 FROM {$table} other_order WHERE other_order.legacy_id=o.legacy_id AND other_order.id<>o.id))";
            foreach ( Read_Support::query( "SELECT o.id,UNIX_TIMESTAMP(o.created_at) created_epoch FROM {$table} o WHERE {$where} ORDER BY o.created_at DESC,o.id ASC LIMIT 1001" ) as $row ) {
                $event            = self::event( 'order-paid', $row['id'], 'payment', gmdate( 'Y-m-d H:i:s', (int) $row['created_epoch'] ), false, $order_tool );
                $event['meaning'] = 'paid_order_created_not_settlement_event';
                $items[]          = $event;
            }
        }
        if ( in_array( 'review', $selected, true ) ) {
            $cursor_date = $range['end_exclusive_utc'];
            $cursor_id   = 0;
            $accepted    = 0;
            $scanned     = 0;
            do {
                $rows = Read_Support::query( $wpdb->prepare( "SELECT c.comment_ID,c.comment_post_ID,c.comment_date_gmt FROM {$wpdb->comments} c JOIN {$wpdb->posts} p ON p.ID=c.comment_post_ID WHERE p.post_type=%s AND c.comment_type='review' AND c.comment_parent=0 AND c.comment_approved IN ('0','1') AND c.comment_date_gmt >= %s AND (c.comment_date_gmt < %s OR (c.comment_date_gmt=%s AND c.comment_ID>%d)) ORDER BY c.comment_date_gmt DESC,c.comment_ID ASC LIMIT 128", ATBDP_POST_TYPE, $range['start_utc'], $cursor_date, $cursor_date, $cursor_id ) );
                foreach ( $rows as $row ) {
                    $cursor_date = $row['comment_date_gmt'];
                    $cursor_id   = (int) $row['comment_ID'];
                    ++$scanned;
                    Support::check( $scanned <= self::LISTING_BUDGET, __( 'Review source exceeds its permission budget.', 'directorist' ), 'unsupported_operation' );
                    try {
                        Access::listing( (int) $row['comment_post_ID'] );
                        if ( current_user_can( 'edit_comment', $cursor_id ) && true === apply_filters( 'directorist_rest_check_permissions', true, 'read', (int) $row['comment_post_ID'], ATBDP_POST_TYPE ) ) {
                            $items[] = self::event( 'review', $cursor_id, 'review', $cursor_date );
                            ++$accepted;
                        }
                    } catch ( Failure $error ) {
                        continue;
                    }
                    if ( $accepted >= 1001 ) {
                        break;
                    }
                }
            } while ( count( $rows ) === 128 && $accepted < 1001 );
        }
        if ( in_array( 'user', $selected, true ) ) {
            foreach ( Read_Support::query( $wpdb->prepare( "SELECT ID,user_registered FROM {$wpdb->users} WHERE user_registered >= %s AND user_registered < %s ORDER BY user_registered DESC,ID ASC LIMIT 1001", $range['start_utc'], $range['end_exclusive_utc'] ) ) as $row ) {
                $items[] = self::event( 'user', $row['ID'], 'user', $row['user_registered'] );
            }
        }
        self::trim( $upcoming );
        $upcoming_truncated            = count( $upcoming ) > 1000;
        $upcoming_page                 = Support::page( array_slice( $upcoming, 0, 1000 ), $args );
        $upcoming_page['window_total'] = $upcoming_page['total'];
        unset( $upcoming_page['total'] );
        $upcoming_page += [ 'truncated' => $upcoming_truncated, 'continuation_limit' => 1000 ];
        self::trim( $items );
        $truncated              = count( $items ) > 1000;
        $items                  = array_slice( $items, 0, 1000 );
        $result                 = Support::page( $items, $args );
        $result['window_total'] = $result['total'];
        unset( $result['total'] );
        return $result + [ 'upcoming' => $upcoming_page, 'history_start' => $range['start_utc'], 'truncated' => $truncated, 'continuation_limit' => 1000, 'as_of' => $as_of, 'ordering' => 'past_timestamp_desc; upcoming_timestamp_asc; ties_source_namespace_then_numeric_object_id', 'source_coverage' => 'native_current_records_not_a_permanent_audit_log' ];
    }
}
