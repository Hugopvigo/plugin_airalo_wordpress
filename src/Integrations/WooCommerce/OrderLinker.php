<?php
/**
 * Bridges WC orders with Airalo orders.
 *
 * - Detects meta written by the official Airalo WC plugin
 * - Stores our own canonical meta under _mpa_*
 * - Provides helpers for the meta box and AJAX actions
 *
 * @package Hugo\MiPluginAiralo
 */

namespace Hugo\MiPluginAiralo\Integrations\WooCommerce;

use Hugo\MiPluginAiralo\Api\Client;
use Hugo\MiPluginAiralo\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class OrderLinker {

    public const META_AIRALO_ORDER_ID = '_mpa_airalo_order_id';
    public const META_AIRALO_ORDERS   = '_mpa_airalo_orders';
    public const META_AIRALO_USAGE    = '_mpa_airalo_usage';
    public const META_AIRALO_INSTR    = '_mpa_airalo_instructions';
    public const META_AIRALO_TOPUPS   = '_mpa_airalo_topups';
    public const META_AIRALO_REFUND   = '_mpa_airalo_refund';
    public const META_AIRALO_USERS    = '_mpa_airalo_users';
    public const META_PROCESSED_BY    = '_mpa_processed_by';

    public function __construct(
        private readonly Client $api,
        private readonly Logger $logger
    ) {
    }

    public function register(): void {
        // Pure static helper for now; reserved for future hooks.
    }

    public static function get_airalo_order_id( \WC_Abstract_Order $order ): ?string {
        $value = $order->get_meta( self::META_AIRALO_ORDER_ID, true );
        if ( ! $value ) {
            $legacy = self::detect_legacy_airalo_order_id( $order );
            if ( $legacy ) {
                $order->update_meta_data( self::META_AIRALO_ORDER_ID, $legacy );
                $order->save();
                return (string) $legacy;
            }
        }
        return $value ? (string) $value : null;
    }

    public static function get_airalo_orders( \WC_Abstract_Order $order ): array {
        $value = $order->get_meta( self::META_AIRALO_ORDERS, true );
        if ( is_array( $value ) ) {
            return $value;
        }
        $legacy = $order->get_meta( '_airalo_order_data', true );
        if ( is_array( $legacy ) ) {
            $order->update_meta_data( self::META_AIRALO_ORDERS, $legacy );
            $order->save();
            return $legacy;
        }
        return [];
    }

    public static function get_usage( \WC_Order $order ): array {
        $value = $order->get_meta( self::META_AIRALO_USAGE, true );
        return is_array( $value ) ? $value : [];
    }

    public static function get_instructions( \WC_Order $order ): array {
        $value = $order->get_meta( self::META_AIRALO_INSTR, true );
        return is_array( $value ) ? $value : [];
    }

    public static function get_topups( \WC_Order $order ): array {
        $value = $order->get_meta( self::META_AIRALO_TOPUPS, true );
        return is_array( $value ) ? $value : [];
    }

    public static function get_refund( \WC_Order $order ): array {
        $value = $order->get_meta( self::META_AIRALO_REFUND, true );
        return is_array( $value ) ? $value : [];
    }

    public static function store_usage( \WC_Order $order, string $iccid, array $data ): void {
        $all = $order->get_meta( self::META_AIRALO_USAGE, true );
        if ( ! is_array( $all ) ) {
            $all = [];
        }
        $all[ $iccid ]            = $data;
        $all[ $iccid ]['updated'] = current_time( 'mysql' );
        $order->update_meta_data( self::META_AIRALO_USAGE, $all );
        $order->save();
    }

    public static function store_instructions( \WC_Order $order, string $iccid, array $data ): void {
        $all = $order->get_meta( self::META_AIRALO_INSTR, true );
        if ( ! is_array( $all ) ) {
            $all = [];
        }
        $all[ $iccid ]            = $data;
        $all[ $iccid ]['updated'] = current_time( 'mysql' );
        $order->update_meta_data( self::META_AIRALO_INSTR, $all );
        $order->save();
    }

    public static function store_topup( \WC_Order $order, string $iccid, string $package_id, array $data ): void {
        $all = $order->get_meta( self::META_AIRALO_TOPUPS, true );
        if ( ! is_array( $all ) ) {
            $all = [];
        }
        $all[] = [
            'iccid'      => $iccid,
            'package_id' => $package_id,
            'response'   => $data,
            'created_at' => current_time( 'mysql' ),
        ];
        $order->update_meta_data( self::META_AIRALO_TOPUPS, $all );
        $order->save();
    }

    public static function mark_refunded( \WC_Order $order, string $reason, array $data ): void {
        $order->update_meta_data( self::META_AIRALO_REFUND, [
            'reason'     => $reason,
            'response'   => $data,
            'created_at' => current_time( 'mysql' ),
        ] );
        $order->add_order_note( sprintf( /* translators: %s: reason */ __( 'Refund Airalo solicitado: %s', MPA_TEXTDOMAIN ), $reason ) );
        $order->save();
    }

    /**
     * Persists the Airalo-side eSIM User (name + email) on the WC order meta,
     * so the Orders page and the EsimDetail page can render it without
     * having to re-query Airalo.
     */
    public static function store_airalo_user( \WC_Order $order, string $iccid, string $name, string $email ): void {
        $all = $order->get_meta( self::META_AIRALO_USERS, true );
        if ( ! is_array( $all ) ) {
            $all = [];
        }
        $all[ $iccid ] = [
            'name'       => $name,
            'email'      => $email,
            'created_at' => current_time( 'mysql' ),
        ];
        $order->update_meta_data( self::META_AIRALO_USERS, $all );
        $order->save();
    }

    /**
     * @return array<string,array{name:string,email:string,created_at:string}>
     */
    public static function get_airalo_users( \WC_Order $order ): array {
        $value = $order->get_meta( self::META_AIRALO_USERS, true );
        return is_array( $value ) ? $value : [];
    }

    /**
     * Records the WP user that processed / synced the WC order.
     * Used by the Orders page to show "Procesado por" alongside the customer.
     */
    public static function mark_processed_by( \WC_Abstract_Order $order, ?int $user_id = null ): void {
        $user_id = $user_id ?? ( function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 );
        if ( $user_id <= 0 ) {
            return;
        }
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return;
        }
        $order->update_meta_data( self::META_PROCESSED_BY, [
            'id'         => (int) $user->ID,
            'login'      => (string) $user->user_login,
            'email'      => (string) $user->user_email,
            'display'    => $user->display_name ?: $user->user_login,
            'created_at' => current_time( 'mysql' ),
        ] );
        $order->save();
    }

    /**
     * @return array{id:int,login:string,email:string,display:string,created_at:string}|null
     */
    public static function get_processed_by( \WC_Abstract_Order $order ): ?array {
        $value = $order->get_meta( self::META_PROCESSED_BY, true );
        return is_array( $value ) && ! empty( $value['id'] ) ? $value : null;
    }

    /**
     * Best-effort sync of an order against Airalo.
     *
     * Strategy: look for a description like "WC#123" in recent orders and adopt the matching one.
     * The official Airalo WC plugin already stores the order ID; this is a fallback.
     *
     * Always records the WP user that triggered the sync (defaulting to
     * the current logged-in user) under `_mpa_processed_by` so the
     * Orders page can show "Procesado por" without re-querying.
     */
    public static function sync_order( \WC_Abstract_Order $order, Client $api ): void {
        $existing = self::get_airalo_order_id( $order );
        if ( ! $existing ) {
            $orders = $api->get_orders( [ 'limit' => 100 ] );
            $needle = 'WC#' . $order->get_id();
            foreach ( (array) $orders as $o ) {
                if ( isset( $o['description'] ) && false !== strpos( (string) $o['description'], $needle ) ) {
                    $order->update_meta_data( self::META_AIRALO_ORDER_ID, (string) ( $o['id'] ?? '' ) );
                    $order->update_meta_data( self::META_AIRALO_ORDERS, [ $o ] );
                    break;
                }
            }
        }

        self::mark_processed_by( $order );
    }

    private static function detect_legacy_airalo_order_id( \WC_Order $order ): ?string {
        $candidates = [
            '_airalo_order_id',
            'airalo_order_id',
            '_airalo_order_code',
            'airalo_order_code',
            '_airalo_order',
        ];
        foreach ( $candidates as $key ) {
            $value = $order->get_meta( $key, true );
            if ( $value ) {
                return (string) $value;
            }
        }
        return null;
    }

    /**
     * Shared, cache-backed lookup: ICCID → WC order.
     *
     * Used by EsimDetail (to attach the real order_id to action buttons),
     * OrderActions (top-up / refund when called from contexts that only
     * know the ICCID) and AiraloListener (webhook dispatch).
     *
     * @return array{order:\WC_Order|null, source:string}  source is 'hit' (in-memory index), 'fresh' (scanned WC), or 'miss'.
     */
    public static function find_wc_order_by_iccid( string $iccid ): array {
        if ( '' === $iccid ) {
            return [ 'order' => null, 'source' => 'miss' ];
        }

        $cache_key = 'mpa_iccid_index';
        $index     = wp_cache_get( $cache_key, MPA_CACHE_GROUP );
        if ( ! is_array( $index ) ) {
            $index = [];
        }

        if ( isset( $index[ $iccid ] ) ) {
            $cached = wc_get_order( (int) $index[ $iccid ] );
            if ( $cached ) {
                return [ 'order' => $cached, 'source' => 'hit' ];
            }
            unset( $index[ $iccid ] );
        }

        $orders = wc_get_orders( [
            'limit'   => 50,
            'orderby' => 'date',
            'order'   => 'DESC',
            'status'  => [ 'wc-processing', 'wc-completed' ],
        ] );

        foreach ( (array) $orders as $order ) {
            $linked = self::get_airalo_orders( $order );
            foreach ( (array) $linked as $o ) {
                foreach ( (array) ( $o['sims'] ?? [] ) as $sim ) {
                    if ( ( $sim['iccid'] ?? '' ) === $iccid ) {
                        $index[ $iccid ] = $order->get_id();
                        wp_cache_set( $cache_key, $index, MPA_CACHE_GROUP, MINUTE_IN_SECONDS * 5 );
                        return [ 'order' => $order, 'source' => 'fresh' ];
                    }
                }
            }
        }

        return [ 'order' => null, 'source' => 'miss' ];
    }
}
