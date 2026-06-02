<?php
/**
 * CSV export of Airalo orders / eSIMs.
 *
 * Streams a CSV to the browser. Uses WP_List_Table-style headers for the
 * download and a custom buffer to avoid memory issues on large datasets.
 *
 * @package Hugo\MiPluginAiralo
 */

namespace Hugo\MiPluginAiralo\Admin\Ajax;

use Hugo\MiPluginAiralo\Api\Client;
use Hugo\MiPluginAiralo\Plugin;
use Hugo\MiPluginAiralo\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Export {

    public function __construct(
        private readonly Client $api,
        private readonly Logger $logger
    ) {
    }

    public function register(): void {
        add_action( 'admin_post_mpa_export_orders', [ $this, 'export_orders' ] );
        add_action( 'admin_post_mpa_export_esims', [ $this, 'export_esims' ] );
    }

    public function export_orders(): void {
        if ( ! current_user_can( Plugin::instance()->capability() ) ) {
            wp_die( esc_html__( 'Permisos insuficientes.', MPA_TEXTDOMAIN ) );
        }
        check_admin_referer( 'mpa_export' );

        $page  = 1;
        $rows  = [];
        $limit = 100;

        try {
            do {
                $batch = $this->api->get_orders( [ 'page' => $page, 'limit' => $limit ] );
                if ( empty( $batch ) ) {
                    break;
                }
                foreach ( (array) $batch as $o ) {
                    $rows[] = [
                        'id'          => $o['id'] ?? '',
                        'code'        => $o['code'] ?? '',
                        'created_at'  => $o['created_at'] ?? '',
                        'package_id'  => $o['package_id'] ?? '',
                        'quantity'    => $o['quantity'] ?? '',
                        'price'       => $o['price'] ?? '',
                        'net_price'   => $o['net_price'] ?? '',
                        'currency'    => $o['currency'] ?? 'USD',
                        'description' => $o['description'] ?? '',
                        'type'        => $o['type'] ?? '',
                    ];
                }
                $page++;
            } while ( count( $batch ) === $limit && $page < 20 );
        } catch ( \Throwable $e ) {
            $this->logger->error( 'Export orders failed: ' . $e->getMessage() );
            wp_die( esc_html( $e->getMessage() ) );
        }

        $this->stream_csv( 'airalo-orders-' . gmdate( 'Ymd-His' ) . '.csv', [
            'id', 'code', 'created_at', 'package_id', 'quantity', 'price', 'net_price', 'currency', 'description', 'type',
        ], $rows );
    }

    public function export_esims(): void {
        if ( ! current_user_can( Plugin::instance()->capability() ) ) {
            wp_die( esc_html__( 'Permisos insuficientes.', MPA_TEXTDOMAIN ) );
        }
        check_admin_referer( 'mpa_export' );

        $rows = [];
        try {
            $orders = $this->api->get_orders( [ 'limit' => 100 ] );
            foreach ( (array) $orders as $o ) {
                $sims = $o['sims'] ?? [];
                if ( ! is_array( $sims ) || empty( $sims ) ) {
                    $rows[] = [
                        'order_code'   => $o['code'] ?? '',
                        'iccid'        => '',
                        'created_at'   => $o['created_at'] ?? '',
                        'package_id'   => $o['package_id'] ?? '',
                        'apn_type'     => '',
                        'is_roaming'   => '',
                    ];
                    continue;
                }
                foreach ( $sims as $s ) {
                    $rows[] = [
                        'order_code' => $o['code'] ?? '',
                        'iccid'      => $s['iccid'] ?? '',
                        'created_at' => $s['created_at'] ?? $o['created_at'] ?? '',
                        'package_id' => $o['package_id'] ?? '',
                        'apn_type'   => $s['apn_type'] ?? '',
                        'is_roaming' => isset( $s['is_roaming'] ) ? ( $s['is_roaming'] ? '1' : '0' ) : '',
                    ];
                }
            }
        } catch ( \Throwable $e ) {
            $this->logger->error( 'Export esims failed: ' . $e->getMessage() );
            wp_die( esc_html( $e->getMessage() ) );
        }

        $this->stream_csv( 'airalo-esims-' . gmdate( 'Ymd-His' ) . '.csv', [
            'order_code', 'iccid', 'created_at', 'package_id', 'apn_type', 'is_roaming',
        ], $rows );
    }

    private function stream_csv( string $filename, array $headers, array $rows ): void {
        nocache_headers();
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        $out = fopen( 'php://output', 'w' );
        if ( false === $out ) {
            wp_die( esc_html__( 'No se pudo abrir el stream.', MPA_TEXTDOMAIN ) );
        }
        fwrite( $out, "\xEF\xBB\xBF" );
        fputcsv( $out, $headers );
        foreach ( $rows as $row ) {
            fputcsv( $out, array_map( static fn( $v ) => (string) $v, $row ) );
        }
        fclose( $out );
        exit;
    }
}
