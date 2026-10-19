<?php
namespace Directorist\MCP;

defined( 'ABSPATH' ) || exit;

use Directorist\DTO\Order\DTO as OrderDTO;
use Directorist\DTO\Payment\DTO as PaymentDTO;
use Directorist\DTO\Refund\DTO as RefundDTO;

/** Current native order storage. Legacy IDs are resolved explicitly, never guessed. */
class Orders {
    public static function definitions() {
        $s       = [ 'type' => 'string', 'maxLength' => 256 ];
        $id      = [ 'type' => 'integer', 'minimum' => 1 ];
        $o       = [ 'type' => 'object', 'additionalProperties' => true ];
        $paging  = [ 'page' => $id, 'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ], 'search' => $s ];
        $order   = [ 'order_id' => $id, 'legacy_id' => $id ];
        $filters = [ 'scope' => [ 'type' => 'string', 'enum' => [ 'own', 'management' ] ], 'status' => $s, 'customer_id' => $id, 'listing_id' => $id, 'item_type' => $s, 'currency' => $s, 'created_after' => $s, 'created_before' => $s, 'order' => [ 'type' => 'string', 'enum' => [ 'asc', 'desc' ] ], 'ids' => [ 'type' => 'array', 'items' => $id, 'maxItems' => 100 ] ];
        $money   = [ 'amount' => [ 'type' => 'string', 'maxLength' => 32 ], 'currency' => [ 'type' => 'string', 'maxLength' => 4 ] ];
        $rows    = [
            [ 'get-order-schema', $order + [ 'item_type' => $s ], [], false, 'Discover caller-specific order fields, exact money rules, native actions and provider capabilities.' ],
            [ 'list-orders', $paging + $filters, [], false, 'Find authorized purchases. Customer scope is always restricted to the purchaser.' ],
            [ 'get-order', $order, [], false, 'Read a permitted native order, version and eligible actions.' ],
            [ 'create-order', [ 'customer_id' => $id, 'item_type' => $s, 'item' => $o, 'schema_version' => $s ], [ 'customer_id', 'item_type', 'item' ], true, 'Preview a native unpaid order from existing references. Never initiates a charge.' ],
            [ 'update-order', $order + [ 'changes' => $o ], [ 'changes' ], true, 'Preview explicit eligible unpaid-order changes with native recalculation.' ],
            [ 'change-order-status', $order + [ 'status' => $s, 'reason' => $s ], [ 'status' ], true, 'Preview an eligible nonfinancial lifecycle transition. Paid/refunded flags are forbidden.' ],
            [ 'cancel-order', $order + [ 'reason' => $s ], [], true, 'Preview native cancellation. Customers may cancel only their own pending purchase.' ],
            [ 'record-order-payment', $order + $money + [ 'method' => $s, 'evidence_reference' => $s ], [ 'amount', 'currency', 'method', 'evidence_reference' ], true, 'Record an eligible already received offline payment with duplicate evidence protection.' ],
            [ 'list-order-payments', $order + $paging + [ 'status' => $s, 'created_after' => $s, 'created_before' => $s ], [], false, 'Read bounded authorized payment history without gateway payloads or secrets.' ],
            [ 'list-order-refunds', $order + $paging + [ 'status' => $s ], [], false, 'Read bounded refund history and settled/reserved/refundable balances.' ],
            [ 'create-order-refund', $order + $money + [ 'payment_id' => $id, 'reason' => $s, 'mode' => [ 'type' => 'string', 'enum' => [ 'record_only', 'gateway' ] ], 'provider' => $s, 'provider_input' => $o, 'evidence_reference' => $s ], [ 'amount', 'currency', 'payment_id', 'reason', 'mode' ], true, 'Preview a partial/full refund with explicit ledger-only or registered gateway mode.' ],
            [ 'bulk-orders', [ 'action' => [ 'type' => 'string', 'enum' => [ 'update', 'cancel' ] ], 'items' => [ 'type' => 'array', 'items' => $o, 'minItems' => 1, 'maxItems' => 50 ] ], [ 'action', 'items' ], true, 'Preview up to 50 explicit unpaid edits or eligible cancellations with independent outcomes.' ],
            [ 'get-order-operation', [ 'operation_id' => $s ], [ 'operation_id' ], false, 'Read a durable authorized order operation without dispatching another financial action.' ],
        ];
        $out     = [];
        foreach ( $rows as $row ) {
            list( $name, $properties, $required, $write, $description ) = $row;
            $out[ $name ]                                               = [ 'group' => 'orders', 'write' => $write, 'description' => __( 'Directorist orders: ', 'directorist' ) . $description, 'schema' => [ 'type' => 'object', 'properties' => $properties + ( $write ? Registry::mutation_controls() : [] ), 'required' => $required, 'additionalProperties' => false ] ];
        }
        return $out;
    }

    public static function tables() {
        global $wpdb;
        return [ $wpdb->prefix . 'directorist_orders', $wpdb->prefix . 'directorist_payments', $wpdb->prefix . 'directorist_refunds' ];
    }

    public static function ready() {
        global $wpdb;
        foreach ( self::tables() as $table ) {
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) !== $table ) {
                return false;
            }
        }
        return function_exists( 'directorist_order_repository' );
    }

    public static function admission() {
        return is_user_logged_in() && current_user_can( 'read' ) && self::ready();
    }

    public static function permission( $tool, array $args ) {
        Support::check( self::ready(), __( 'The native order storage is unavailable. No migration is performed by MCP.', 'directorist' ), 'dependency_unavailable' );
        $self = [ 'get-order-schema', 'list-orders', 'get-order', 'list-order-payments', 'list-order-refunds', 'cancel-order', 'get-order-operation' ];
        if ( ! in_array( $tool, $self, true ) || 'management' === ( $args['scope'] ?? '' ) || ( isset( $args['customer_id'] ) && $args['customer_id'] !== get_current_user_id() ) ) {
            Access::admin();
        } else {
            Support::check( self::admission(), __( 'Native customer access is required.', 'directorist' ), 'forbidden' );
        }
        if ( isset( $args['order_id'] ) || isset( $args['legacy_id'] ) ) {
            self::get( $args['order_id'] ?? null, $args['legacy_id'] ?? null );
        }
        return true;
    }

    public static function get( $id, $legacy = null ) {
        Support::check( self::ready(), __( 'Order storage is unavailable.', 'directorist' ), 'dependency_unavailable' );
        Support::check( ( null !== $id ) xor ( null !== $legacy ), __( 'Specify exactly one order_id or legacy_id.', 'directorist' ) );
        $repository = directorist_order_repository();
        if ( null !== $legacy ) {
            Support::id( $legacy );
            $rows = $repository->get_query_builder()->where( 'legacy_id', $legacy )->limit( 2 )->get();
            Support::check( count( $rows ) === 1, __( 'Order not found.', 'directorist' ), 'not_found' );
            $order = $rows[0];
        } else {
            $order = $repository->get_by_id( Support::id( $id ) );
        }
        Support::check( $order && ( current_user_can( 'manage_options' ) || (int) $order->user_id === get_current_user_id() ), __( 'Order not found.', 'directorist' ), 'not_found' );
        return $order;
    }

    private static function rows( $index, $order_id ) {
        global $wpdb;
        $table = self::tables()[ $index ];
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table is selected from the internal fixed table map.
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d ORDER BY id ASC LIMIT 1001", $order_id ) );
        Support::check( count( $rows ) <= 1000, __( 'Use the native administration tools to reconcile this unusually large ledger.', 'directorist' ), 'unsupported_operation' );
        return $rows;
    }

    public static function version( $order ) {
        return Support::hash( [ $order, self::rows( 1, $order->id ), self::rows( 2, $order->id ), get_directorist_option( 'featured_listing_price' ), get_directorist_option( 'featured_listing_time' ), directorist_currency(), self::balance( $order ), Registry::instance()->fingerprint() ] );
    }

    private static function total( $order ) {
        return Money::stored( directorist_order_total_amount( $order ), $order->currency );
    }

    private static function balance( $order, $except = '' ) {
        $currency = $order->currency;
        $paid     = 0;
        $pending  = 0;
        foreach ( self::rows( 2, $order->id ) as $refund ) {
            $minor = Money::minor( Money::stored( $refund->amount, $currency ), $currency );
            if ( 'paid' === $refund->status ) {
                $paid += $minor;
            } elseif ( in_array( $refund->status, [ 'pending', 'processing' ], true ) ) {
                $pending += $minor;
            }
        }
        $pending += Order_Operations::reserved( $order->id, $currency, $except );
        $total    = Money::minor( self::total( $order ), $currency );
        return [ 'settled' => Money::decimal( $paid, $currency ), 'reserved' => Money::decimal( $pending, $currency ), 'refundable' => Money::decimal( max( 0, $total - $paid - $pending ), $currency ), 'currency' => $currency ];
    }

    private static function editable( $order ) {
        if ( ! in_array( $order->status, [ 'pending', 'unpaid', 'failed' ], true ) ) {
            return false;
        }
        foreach ( self::rows( 1, $order->id ) as $payment ) {
            if ( in_array( $payment->status, [ 'paid', 'refunded' ], true ) ) {
                return false;
            }
        }
        $balance = self::balance( $order );
        return 0 === Money::minor( $balance['settled'], $order->currency ) && 0 === Money::minor( $balance['reserved'], $order->currency );
    }

    private static function actions( $order ) {
        $actions = [];
        if ( ( 'pending' === $order->status && self::editable( $order ) ) || ( current_user_can( 'manage_options' ) && self::editable( $order ) ) ) {
            $actions[] = 'cancel';
        }
        if ( current_user_can( 'manage_options' ) && self::editable( $order ) ) {
            $actions = array_merge( $actions, [ 'pending', 'unpaid', 'failed', 'expired' ] );
            if ( directorist_is_monetization_enabled() ) {
                $actions[] = 'update';
            }
            if ( self::offline_ready() ) {
                $actions[] = 'record_offline_payment';
            }
        }
        if ( current_user_can( 'manage_options' ) && in_array( $order->status, [ 'paid', 'refunded' ], true ) && Money::minor( self::balance( $order )['refundable'], $order->currency ) > 0 ) {
            $actions[] = 'refund';
        }
        return $actions;
    }

    private static function data( $order ) {
        $discount = Money::stored( directorist_compute_fixed_or_percent_amount( $order->coupon_discount_type, $order->coupon_discount, $order->sub_total ), $order->currency );
        $net      = Money::minor( Money::stored( $order->sub_total, $order->currency ), $order->currency ) - Money::minor( $discount, $order->currency );
        $tax      = Money::stored( directorist_compute_fixed_or_percent_amount( $order->tax_type, $order->tax_rate, (float) Money::decimal( max( 0, $net ), $order->currency ) ), $order->currency );
        $data     = [
            'order_id'        => (int) $order->id,
            'storage'         => 'native-tables-v1',
            'status'          => $order->status,
            'item_type'       => $order->ref_type,
            'currency'        => $order->currency,
            'discount'        => $discount,
            'tax'             => $tax,
            'expires_at'      => $order->expires_at,
            'subtotal'        => Money::stored( $order->sub_total, $order->currency ),
            'total'           => self::total( $order ),
            'created_at'      => $order->created_at,
            'updated_at'      => $order->updated_at,
            'allowed_actions' => self::actions( $order ),
            'version'         => self::version( $order ),
        ];
        if ( current_user_can( 'manage_options' ) ) {
            $data['customer_id'] = (int) $order->user_id;
            $data['legacy_id']   = isset( $order->legacy_id ) ? (int) $order->legacy_id : null;
        }
        if ( ! empty( $order->listing_id ) ) {
            try {
                $listing         = Access::listing( (int) $order->listing_id );
                $data['listing'] = [ 'id' => $listing->ID, 'title' => $listing->post_title ];
            } catch ( Failure $error ) {
                $data['listing'] = [ 'restricted' => true ];
            }
        }
        return $data;
    }

    public static function read( $tool, array $args ) {
        self::permission( $tool, $args );
        if ( 'get-order-operation' === $tool ) {
            return Order_Operations::read( $args );
        }
        if ( 'get-order-schema' === $tool ) {
            $order = isset( $args['order_id'] ) || isset( $args['legacy_id'] ) ? self::get( $args['order_id'] ?? null, $args['legacy_id'] ?? null ) : null;
            $staff = current_user_can( 'manage_options' );
            $items = $staff ? array_keys( Registry::instance()->fields( 'order_items' ) ) : [];
            if ( $staff && self::featured_ready() ) {
                array_unshift( $items, 'featured_listing' );
            }
            $providers         = $staff ? Registry::instance()->fields( 'refund_providers', $order ) : [];
            $schema            = [ 'storage' => 'native-tables-v1', 'scope' => $staff ? [ 'management', 'own' ] : [ 'own' ], 'item_types' => $items, 'currency' => directorist_currency(), 'precision' => Money::precision( directorist_currency() ), 'native_write_precision_maximum' => 2, 'editable_fields' => $staff ? [ 'recalculate', 'item' ] : [], 'actions' => $order ? self::actions( $order ) : [], 'offline_methods' => $staff && self::offline_ready() ? [ 'bank_transfer' ] : [], 'refund_modes' => $staff ? ( $providers ? [ 'record_only', 'gateway' ] : [ 'record_only' ] ) : [], 'refund_providers' => array_keys( $providers ), 'limits' => [ 'page' => 100, 'bulk' => 50 ] ];
            $schema['filters'] = [ 'ids', 'status', 'listing_id', 'item_type', 'currency', 'created_after', 'created_before', 'search', 'order' ];
            if ( $staff ) {
                $schema['filters'][] = 'customer_id';
            }
            $schema['item_schemas'] = [];
            if ( in_array( 'featured_listing', $items, true ) ) {
                $schema['item_schemas']['featured_listing'] = [ 'type' => 'object', 'properties' => [ 'listing_id' => [ 'type' => 'integer', 'minimum' => 1 ] ], 'required' => [ 'listing_id' ], 'additionalProperties' => false ];
            }
            foreach ( $staff ? Registry::instance()->fields( 'order_items', $order ) : [] as $key => $d ) {
                $schema['item_schemas'][ $key ] = $d['schema'];
            }
            $schema['schema_version'] = Support::hash( [ $schema, Registry::instance()->fingerprint(), get_directorist_option( 'featured_listing_price' ) ] );
            return $schema;
        }
        if ( 'list-orders' === $tool ) {
            return self::search( $args );
        }
        $order = self::get( $args['order_id'] ?? null, $args['legacy_id'] ?? null );
        if ( 'get-order' === $tool ) {
            return self::data( $order );
        }
        return self::history( $tool, $order, $args );
    }

    private static function search( array $args ) {
        global $wpdb;
        list( $page, $size ) = Support::paging( $args );
        if ( isset( $args['status'] ) ) {
            Support::check( in_array( $args['status'], \Directorist\Enums\Order\Status::all(), true ), __( 'Unknown native order status.', 'directorist' ) );
        }
        $where  = [ '1=1' ];
        $values = [];
        if ( ! current_user_can( 'manage_options' ) || 'own' === ( $args['scope'] ?? '' ) ) {
            $where[]  = 'user_id = %d';
            $values[] = get_current_user_id();
        }
        foreach ( [ 'customer_id' => 'user_id', 'listing_id' => 'listing_id', 'status' => 'status', 'currency' => 'currency', 'item_type' => 'ref_type' ] as $key => $column ) {
            if ( isset( $args[ $key ] ) ) {
                $where[]  = $column . ' = %s';
                $values[] = $args[ $key ];
            }
        }
        foreach ( [ 'created_after' => '>=', 'created_before' => '<=' ] as $key => $operator ) {
            if ( isset( $args[ $key ] ) ) {
                Support::check( (bool) preg_match( '/^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}:\d{2})?$/', $args[ $key ] ), __( 'Use an ISO date or local date/time.', 'directorist' ) );
                $where[]  = 'created_at ' . $operator . ' %s';
                $values[] = $args[ $key ];
            }
        }
        if ( isset( $args['ids'] ) ) {
            $ids = Support::ids( $args['ids'] );
            if ( ! $ids ) {
                return Support::collection( [], 0, $page, $size );
            }
            $where[] = 'id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
            $values  = array_merge( $values, $ids );
        }
        if ( ! empty( $args['search'] ) ) {
            $where[]  = '(CAST(id AS CHAR) LIKE %s OR status LIKE %s)';
            $values[] = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $values[] = end( $values );
        }
        $table = self::tables()[0];
        $sql   = ' WHERE ' . implode( ' AND ', $where );
        // phpcs:disable WordPress.DB.PreparedSQL -- Internal fixed identifiers/operators; all user values are prepared.
        $total     = $wpdb->get_var( $values ? $wpdb->prepare( "SELECT COUNT(*) FROM {$table}" . $sql, $values ) : "SELECT COUNT(*) FROM {$table}" . $sql );
        $direction = 'asc' === ( $args['order'] ?? '' ) ? 'ASC' : 'DESC';
        $rows      = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table}" . $sql . " ORDER BY id {$direction} LIMIT %d OFFSET %d", array_merge( $values, [ $size, ( $page - 1 ) * $size ] ) ) );
        // phpcs:enable WordPress.DB.PreparedSQL
        return Support::collection( array_map( [ self::class, 'data' ], $rows ), $total, $page, $size );
    }

    private static function history( $tool, $order, array $args ) {
        global $wpdb;
        list( $page, $size ) = Support::paging( $args );
        $refund              = 'list-order-refunds' === $tool;
        $table               = self::tables()[ $refund ? 2 : 1 ];
        $where               = ' WHERE order_id = %d';
        $values              = [ (int) $order->id ];
        foreach ( [ 'status' => '=', 'created_after' => '>=', 'created_before' => '<=' ] as $key => $op ) {
            if ( isset( $args[ $key ] ) ) {
                $where   .= ' AND ' . ( 'status' === $key ? 'status' : 'created_at' ) . ' ' . $op . ' %s';
                $values[] = $args[ $key ];
            }
        }
        if ( ! empty( $args['search'] ) ) {
            $where   .= ' AND CAST(id AS CHAR) LIKE %s';
            $values[] = '%' . $wpdb->esc_like( $args['search'] ) . '%';
        }
        // phpcs:disable WordPress.DB.PreparedSQL -- Internal fixed identifiers/operators; user values are prepared.
        $total = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table}" . $where, $values ) );
        $rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table}" . $where . ' ORDER BY id DESC LIMIT %d OFFSET %d', array_merge( $values, [ $size, ( $page - 1 ) * $size ] ) ) );
        // phpcs:enable WordPress.DB.PreparedSQL
        $items = [];
        foreach ( $rows as $row ) {
            $items[] = [ 'id' => (int) $row->id, 'status' => $row->status, 'amount' => Money::stored( $row->amount, $order->currency ), 'currency' => $order->currency, 'created_at' => $row->created_at ?? null ] + ( $refund ? [] : [ 'method' => $row->method ] );
        }
        $result = Support::collection( $items, $total, $page, $size );
        if ( $refund ) {
            $result['balance']         = self::balance( $order );
            $result['settlement_note'] = __( 'A refund record alone does not prove gateway settlement.', 'directorist' );
        }
        return $result;
    }

    public static function references( array $args ) {
        Access::admin();
        list( $page, $size ) = Support::paging( $args );
        $query               = [ 'number' => $size, 'offset' => ( $page - 1 ) * $size, 'orderby' => 'ID', 'order' => 'ASC', 'count_total' => true ];
        if ( ! empty( $args['search'] ) ) {
            $query['search'] = '*' . $args['search'] . '*';
        }
        if ( isset( $args['ids'] ) ) {
            $ids = Support::ids( $args['ids'] );
            if ( ! $ids ) {
                return Support::collection( [], 0, $page, $size );
            }
            $query['include'] = $ids;
        }
        $users = new \WP_User_Query( $query );
        return Support::collection(
            array_map(
                static function ( $user ) { return [ 'customer_id' => $user->ID, 'label' => $user->display_name ];
                }, $users->get_results()
            ), $users->get_total(), $page, $size
        );
    }

    private static function featured_ready() {
        return directorist_is_monetization_enabled() && function_exists( 'directorist_is_featured_listing_enabled' ) && directorist_is_featured_listing_enabled( [ 'type' => 'featured_listing_checkout' ] ) && in_array( 'featured_listing', directorist_get_checkout_types(), true );
    }

    private static function offline_ready() {
        return directorist_is_monetization_enabled() && in_array( 'bank_transfer', (array) get_directorist_option( 'active_gateways', [ 'bank_transfer' ] ), true ) && function_exists( 'directorist_get_payment_processors' ) && isset( directorist_get_payment_processors()['bank_transfer'] );
    }

    private static function quote( $customer, $type, array $item, $order = null ) {
        Support::check( directorist_is_monetization_enabled(), __( 'New purchases are unavailable while monetization is disabled.', 'directorist' ), 'dependency_unavailable' );
        Support::check( (bool) get_userdata( Support::id( $customer ) ), __( 'Customer not found.', 'directorist' ) );
        if ( 'featured_listing' !== $type ) {
            $adapters = Registry::instance()->fields( 'order_items', $order );
            Support::check( isset( $adapters[ $type ] ), __( 'A supported order-item adapter is required.', 'directorist' ), 'unsupported_operation' );
            $d = $adapters[ $type ];
            Support::unwrap( rest_validate_value_from_schema( $item ?: (object) [], $d['schema'] ) );
            $quote = Support::unwrap( call_user_func( $d['validate'], $item, $customer, $order ) );
            Support::check( is_array( $quote ) && isset( $quote['dto'] ) && $quote['dto'] instanceof OrderDTO && isset( $quote['preview'], $quote['state'] ), __( 'Invalid native item quote.', 'directorist' ), 'unsupported_operation' );
            $dto = $quote['dto'];
            Support::check( ! $dto->is_initialized( 'id' ) && $dto->get_user_id() === $customer && in_array( $dto->get_status(), [ 'pending', 'unpaid' ], true ) && ! $dto->is_initialized( 'expires_at' ), __( 'The item quote attempted a restricted identity or settled-state change.', 'directorist' ), 'unsupported_operation' );
            if ( $dto->is_initialized( 'listing_id' ) && $dto->get_listing_id() ) {
                $listing = Access::listing( $dto->get_listing_id(), 'edit' );
                Support::check( (int) $listing->post_author === $customer && ( ! $order || (int) $order->listing_id === $listing->ID ), __( 'The item listing must belong to the purchaser and cannot be transferred.', 'directorist' ) );
            }
            Money::native( Money::stored( directorist_order_payable( $dto ), $dto->get_currency() ), $dto->get_currency() );
            return $quote;
        }
        Support::check( self::featured_ready(), __( 'Featured-listing purchases are unavailable.', 'directorist' ), 'dependency_unavailable' );
        Support::allowed( $item, [ 'listing_id' ] );
        $listing = Access::listing( Support::id( $item['listing_id'] ?? null ), 'edit' );
        Support::check( (int) $listing->post_author === $customer && 'trash' !== $listing->post_status, __( 'The listing must belong to this customer and be eligible for purchase.', 'directorist' ) );
        Support::check( ! directorist_order_repository()->listing_has_active_featured_entitlement( $listing->ID ), __( 'This listing already has an active featured entitlement.', 'directorist' ), 'dependency_blocked' );
        $request = new \WP_REST_Request( 'POST' );
        $request->set_param( 'listing_id', $listing->ID );
        do_action( 'directorist_checkout_validation', $type, $request );
        $amount = Money::stored( get_directorist_option( 'featured_listing_price' ), directorist_currency() );
        Support::check( Money::minor( $amount, directorist_currency() ) > 0, __( 'Use the native free-listing workflow for a zero-priced item.', 'directorist' ), 'unsupported_operation' );
        $dto = ( new OrderDTO() )->set_user_id( $customer )->set_listing_id( $listing->ID )->set_currency( directorist_currency() )->set_status( 'pending' );
        do_action( 'directorist_checkout_create_order', $dto, $type, $request );
        Support::check( ! $dto->is_initialized( 'id' ) && $dto->get_user_id() === $customer && $dto->get_listing_id() === $listing->ID && 'pending' === $dto->get_status(), __( 'The item adapter attempted an unsupported purchase change.', 'directorist' ), 'unsupported_operation' );
        if ( $order ) {
            $dto->set_coupon_code( $order->coupon_code )->set_coupon_discount( $order->coupon_discount )->set_coupon_discount_type( $order->coupon_discount_type )->set_tax_rate( (float) $order->tax_rate )->set_tax_type( (string) $order->tax_type );
        }
        $native_total = Money::stored( directorist_order_payable( $dto ), $dto->get_currency() );
        Money::native( $native_total, $dto->get_currency() );
        return [ 'dto' => $dto, 'preview' => [ 'item_type' => $type, 'customer_id' => $customer, 'listing_id' => $listing->ID, 'amount' => $native_total, 'currency' => $dto->get_currency(), 'status' => 'pending', 'effects' => 'Unpaid order only; no charge or entitlement grant.' ], 'state' => [ $native_total, $listing->to_array(), get_post_meta( $listing->ID ), get_directorist_option( 'featured_listing_time' ) ] ];
    }

    public static function prepare( $tool, array $args ) {
        self::permission( $tool, $args );
        if ( 'create-order' === $tool ) {
            $quote  = self::quote( $args['customer_id'], $args['item_type'], Support::object( $args['item'] ) );
            $schema = self::read( 'get-order-schema', [] )['schema_version'];
            if ( isset( $args['schema_version'] ) ) {
                Support::version( $args['schema_version'], $schema );
            }
            return [ 'confirm' => true,
                'tables'       => self::tables(),
                'state'        => [ $quote['state'], $schema ],
                'preview'      => $quote['preview'],
                'execute'      => static function () use ( $quote ) {
                    $id = directorist_order_repository()->create( $quote['dto'] );
                    Support::check( $id > 0, __( 'The order could not be created.', 'directorist' ), 'operation_failed' );
                    return Support::result( [ 'order_id' => (int) $id, 'data' => self::data( self::get( (int) $id ) ) ] );
                }
            ];
        }
        $order   = self::get( $args['order_id'] ?? null, $args['legacy_id'] ?? null );
        $version = self::version( $order );
        if ( empty( $args['dry_run'] ) ) {
            Support::version( $args['expected_version'] ?? null, $version );
        }
        $preview = [ 'order_id' => (int) $order->id, 'version' => $version, 'before' => self::data( $order ) ];
        $actions = self::actions( $order );
        if ( 'update-order' === $tool ) {
            Support::check( self::editable( $order ), __( 'Only eligible unpaid orders can be edited.', 'directorist' ), 'dependency_blocked' );
            $changes = Support::object( $args['changes'] );
            Support::allowed( $changes, [ 'recalculate', 'item' ] );
            Support::check( ! isset( $changes['recalculate'] ) || true === $changes['recalculate'], __( 'Recalculate must be true.', 'directorist' ) );
            $item = Support::object( $changes['item'] ?? [ 'listing_id' => (int) $order->listing_id ] );
            Support::check( ! isset( $item['listing_id'] ) || $item['listing_id'] === (int) $order->listing_id, __( 'Order listing transfer is not supported.', 'directorist' ) );
            $quote = self::quote( (int) $order->user_id, $order->ref_type, $item, $order );
            $dto   = $quote['dto'];
            $dto->set_id( (int) $order->id )->set_status( $order->status );
            return [ 'confirm' => true,
                'tables'       => self::tables(),
                'state'        => [ $version, $quote['state'] ],
                'preview'      => $preview + [ 'after' => $quote['preview'] ],
                'execute'      => static function () use ( $dto, $order ) {
                    directorist_order_repository()->update( $dto );
                    return Support::result( [ 'order_id' => (int) $order->id, 'data' => self::data( self::get( (int) $order->id ) ) ] );
                }
            ];
        }
        if ( in_array( $tool, [ 'cancel-order', 'change-order-status' ], true ) ) {
            $target = 'cancel-order' === $tool ? 'cancelled' : $args['status'];
            $action = 'cancelled' === $target ? 'cancel' : $target;
            Support::check( in_array( $action, $actions, true ) && in_array( $target, [ 'cancelled', 'pending', 'unpaid', 'failed', 'expired' ], true ), __( 'This native transition is not eligible.', 'directorist' ), 'dependency_blocked' );
            return [ 'confirm' => true,
                'orchestrated' => true,
                'tables'       => self::tables(),
                'state'        => $version,
                'preview'      => $preview + [ 'after_status' => $target, 'effects' => 'Native state/fulfillment hooks; no charge or refund.' ],
                'execute'      => static function () use ( $order, $target, $tool, $args ) {
                    return Order_Operations::execute(
                        $tool, $args, $order, static function () use ( $order, $target ) {
                            directorist_order_repository()->update_status( (int) $order->id, $target );
                            return Support::result( [ 'order_id' => (int) $order->id, 'status' => $target ] );
                        }
                    );
                }
            ];
        }
        return self::financial( $tool, $args, $order, $version, $preview );
    }

    private static function financial( $tool, array $args, $order, $version, array $preview ) {
        Support::check( $args['currency'] === $order->currency, __( 'The currency must match the native order.', 'directorist' ) );
        $minor = Money::minor( $args['amount'], $order->currency );
        Support::check( $minor > 0, __( 'The amount must be positive.', 'directorist' ) );
        $amount   = Money::native( $args['amount'], $order->currency );
        $provider = [];
        Order_Operations::check_evidence( $tool, $args );
        if ( 'record-order-payment' === $tool ) {
            Support::check( in_array( 'record_offline_payment', self::actions( $order ), true ) && self::offline_ready() && 'bank_transfer' === $args['method'], __( 'Only an eligible native offline receipt can be recorded.', 'directorist' ), 'unsupported_operation' );
            Support::check( $minor === Money::minor( self::total( $order ), $order->currency ), __( 'Record the exact native due amount.', 'directorist' ) );
            Support::check( '' !== trim( $args['evidence_reference'] ), __( 'Receipt evidence is required.', 'directorist' ) );
            $native = static function () use ( $order, $amount, $args ) {
                $dto = ( new PaymentDTO() )->set_order_id( (int) $order->id )->set_amount( $amount )->set_currency( $order->currency )->set_status( 'paid' )->set_method( 'bank_transfer' )->set_transaction_id( 'mcp-' . substr( Support::hash( $args['evidence_reference'] ), 0, 32 ) );
                $id  = directorist_payment_repository()->create( $dto );
                Support::check( $id > 0, __( 'Payment recording failed.', 'directorist' ), 'operation_failed' );
                return Support::result( [ 'payment_id' => $id, 'mode' => 'offline_recording' ] );
            };
        } else {
            Support::check( 'create-order-refund' === $tool && in_array( 'refund', self::actions( $order ), true ), __( 'This order is not eligible for a refund.', 'directorist' ), 'dependency_blocked' );
            $payment = directorist_payment_repository()->get_by_id( $args['payment_id'] );
            Support::check( $payment && (int) $payment->order_id === (int) $order->id && 'paid' === $payment->status && $payment->currency === $order->currency, __( 'The refundable payment is invalid.', 'directorist' ) );
            $paid_receipts = array_filter(
                self::rows( 1, $order->id ), static function ( $receipt ) { return 'paid' === $receipt->status;
                }
            );
            Support::check( 1 === count( $paid_receipts ), __( 'Orders with multiple settled receipts require a native allocation adapter.', 'directorist' ), 'unsupported_operation' );
            Support::check( $minor <= Money::minor( self::balance( $order )['refundable'], $order->currency ) && $minor <= Money::minor( Money::stored( $payment->amount, $order->currency ), $order->currency ), __( 'The refund exceeds the available balance.', 'directorist' ), 'dependency_blocked' );
            Support::check( '' !== trim( $args['reason'] ), __( 'A refund reason is required.', 'directorist' ) );
            if ( 'gateway' === $args['mode'] ) {
                $providers = Registry::instance()->fields( 'refund_providers', $order );
                Support::check( isset( $providers[ $args['provider'] ?? '' ] ), __( 'A compatible registered refund provider is required.', 'directorist' ), 'unsupported_operation' );
                $provider = $providers[ $args['provider'] ];
                Support::check( true === call_user_func( $provider['permission'], $order, 'write' ), __( 'This provider action is forbidden.', 'directorist' ), 'forbidden' );
                Support::unwrap( rest_validate_value_from_schema( ( $args['provider_input'] ?? [] ) ?: (object) [], $provider['schema'] ) );
                Support::unwrap( call_user_func( $provider['validate'], $args['provider_input'] ?? [], $order, $payment ) );
            } else {
                Support::check( ! empty( $args['evidence_reference'] ) && empty( $args['provider'] ) && empty( $args['provider_input'] ), __( 'A manual refund requires evidence and cannot dispatch a provider.', 'directorist' ) );
            }
            $native = static function () use ( $order, $amount, $args ) {
                $dto = ( new RefundDTO() )->set_order_id( (int) $order->id )->set_amount( $amount )->set_status( 'paid' )->set_reason( sanitize_text_field( $args['reason'] ) );
                $id  = ( new \Directorist\Repositories\RefundRepository() )->create( $dto );
                Support::check( $id > 0, __( 'Refund recording failed.', 'directorist' ), 'operation_failed' );
                $balance = self::balance( self::get( (int) $order->id ) );
                if ( Money::minor( $balance['settled'], $order->currency ) === Money::minor( self::total( $order ), $order->currency ) && 'refunded' !== self::get( (int) $order->id )->status ) {
                    directorist_order_repository()->update_status( (int) $order->id, 'refunded' );
                }
                return Support::result( [ 'refund_id' => $id, 'mode' => $args['mode'], 'gateway_refund_performed' => 'gateway' === $args['mode'] ] );
            };
        }
        return [ 'confirm' => true,
            'orchestrated' => true,
            'tables'       => self::tables(),
            'state'        => [ $version, $provider['contract_version'] ?? '' ],
            'preview'      => $preview + [ 'amount' => $args['amount'], 'currency' => $order->currency, 'mode' => $args['mode'] ?? 'offline_recording', 'balance' => self::balance( $order ), 'effects' => 'Native ledger, order, entitlement and notification hooks. Ledger-only recording does not return money.' ],
            'execute'      => static function () use ( $tool, $args, $order, $native, $provider ) {
                return Order_Operations::execute( $tool, $args, $order, $native, $provider );
            }
        ];
    }
}
