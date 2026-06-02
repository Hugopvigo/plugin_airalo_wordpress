<?php
/**
 * Admin AJAX actions: order sync, refund, usage, top-up.
 *
 * All actions are nonce-protected and capability-checked.
 *
 * @package Hugo\MiPluginAiralo
 */

namespace Hugo\MiPluginAiralo\Admin\Ajax;

use Hugo\MiPluginAiralo\Api\Client;
use Hugo\MiPluginAiralo\Api\Exception;
use Hugo\MiPluginAiralo\Integrations\WooCommerce\OrderLinker;
use Hugo\MiPluginAiralo\Plugin;
use Hugo\MiPluginAiralo\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class OrderActions {

    private const NONCE_ACTION = 'mpa_admin';

    public function __construct(
        private readonly Client $api,
        private readonly Logger $logger
    ) {
    }

    public function register(): void {
        $actions = [
            'mpa_sync_order',
            'mpa_get_usage',
            'mpa_get_qr',
            'mpa_refund_esim',
            'mpa_refund_esim_by_iccid',
            'mpa_topup',
            'mpa_share_link',
            'mpa_share_esim',
            'mpa_assign_esim_user',
        ];
        foreach ( $actions as $action ) {
            add_action( 'wp_ajax_' . $action, [ $this, 'dispatch' ] );
        }
    }

    public function dispatch(): void {
        $action = isset( $_POST['mpa_action'] ) ? sanitize_key( wp_unslash( $_POST['mpa_action'] ) ) : '';
        if ( '' === $action ) {
            $this->fail( __( 'Acción inválida.', MPA_TEXTDOMAIN ) );
        }

        if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
            $this->fail( __( 'Nonce inválido.', MPA_TEXTDOMAIN ), 403 );
        }

        if ( ! current_user_can( Plugin::instance()->capability() ) ) {
            $this->fail( __( 'Permisos insuficientes.', MPA_TEXTDOMAIN ), 403 );
        }

        $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
        $iccid    = isset( $_POST['iccid'] ) ? sanitize_text_field( wp_unslash( $_POST['iccid'] ) ) : '';

        $wc_order = $order_id ? wc_get_order( $order_id ) : null;
        $needs_order = in_array( $action, [ 'mpa_sync_order' ], true );

        if ( $needs_order && ! $wc_order ) {
            $this->fail( __( 'Pedido WooCommerce no encontrado.', MPA_TEXTDOMAIN ) );
        }

        try {
            switch ( $action ) {
                case 'mpa_sync_order':
                    $this->handle_sync( $wc_order );
                    break;
                case 'mpa_get_usage':
                    $this->handle_usage_with_lookup( $wc_order, $iccid );
                    break;
                case 'mpa_get_qr':
                    $this->handle_qr_with_lookup( $wc_order, $iccid );
                    break;
                case 'mpa_refund_esim':
                    $reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
                    $notes  = isset( $_POST['notes'] ) ? sanitize_text_field( wp_unslash( $_POST['notes'] ) ) : '';
                    $this->handle_refund_with_lookup( $wc_order, $iccid, $reason, $notes );
                    break;
                case 'mpa_topup':
                    $pkg = isset( $_POST['package_id'] ) ? sanitize_text_field( wp_unslash( $_POST['package_id'] ) ) : '';
                    if ( $wc_order ) {
                        $this->handle_topup( $wc_order, $iccid, $pkg );
                    } else {
                        $this->handle_topup_by_iccid( $iccid, $pkg );
                    }
                    break;
                case 'mpa_share_link':
                    $this->ok( [
                        'data' => [
                            'iccid' => $iccid,
                        ],
                        'message' => __( 'OK', MPA_TEXTDOMAIN ),
                    ] );
                    break;
                case 'mpa_share_esim':
                    $email  = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
                    $option = isset( $_POST['sharing_option'] ) ? sanitize_key( wp_unslash( $_POST['sharing_option'] ) ) : 'link';
                    $copy   = isset( $_POST['copy_address'] ) ? sanitize_email( wp_unslash( $_POST['copy_address'] ) ) : '';
                    $this->handle_share_esim( $wc_order, $iccid, $email, $option, $copy );
                    break;
                case 'mpa_assign_esim_user':
                    $name  = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
                    $email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
                    $this->handle_assign_esim_user( $wc_order, $iccid, $name, $email );
                    break;
                case 'mpa_refund_esim_by_iccid':
                    $reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
                    $notes  = isset( $_POST['notes'] ) ? sanitize_text_field( wp_unslash( $_POST['notes'] ) ) : '';
                    $this->handle_refund_with_lookup( $wc_order, $iccid, $reason, $notes );
                    break;
                default:
                    $this->fail( __( 'Acción desconocida.', MPA_TEXTDOMAIN ) );
            }
        } catch ( Exception $e ) {
            $this->logger->error( 'AJAX action failed: ' . $e->getMessage(), [ 'action' => $action, 'order' => $order_id ] );
            $this->fail( $e->getMessage() );
        } catch ( \Throwable $e ) {
            $this->logger->error( 'AJAX action error: ' . $e->getMessage() );
            $this->fail( __( 'Error inesperado.', MPA_TEXTDOMAIN ) );
        }
    }

    private function handle_sync( \WC_Order $wc_order ): void {
        OrderLinker::sync_order( $wc_order, $this->api );
        $this->ok( [ 'message' => __( 'Pedido sincronizado.', MPA_TEXTDOMAIN ) ] );
    }

    private function handle_usage_with_lookup( ?\WC_Order $wc_order, string $iccid ): void {
        if ( '' === $iccid ) {
            $this->fail( __( 'ICCID requerido.', MPA_TEXTDOMAIN ) );
        }
        $data = $this->api->get_sim_usage( $iccid );
        if ( $wc_order ) {
            OrderLinker::store_usage( $wc_order, $iccid, $data );
        }
        $this->ok( [
            'data'    => $data,
            'message' => __( 'Uso actualizado.', MPA_TEXTDOMAIN ),
        ] );
    }

    private function handle_qr_with_lookup( ?\WC_Order $wc_order, string $iccid ): void {
        if ( '' === $iccid ) {
            $this->fail( __( 'ICCID requerido.', MPA_TEXTDOMAIN ) );
        }
        $instructions = $this->api->get_sim_instructions( $iccid );
        if ( $wc_order ) {
            OrderLinker::store_instructions( $wc_order, $iccid, $instructions );
        }
        $this->ok( [
            'data'    => $instructions,
            'message' => __( 'Instrucciones obtenidas.', MPA_TEXTDOMAIN ),
        ] );
    }

    private function handle_refund_with_lookup( ?\WC_Order $wc_order, string $iccid, string $reason, string $notes = '' ): void {
        $target = $wc_order ?? $this->find_wc_order_by_iccid( $iccid );
        if ( ! $target ) {
            $this->fail( __( 'No se encontró un pedido WC para esta ICCID.', MPA_TEXTDOMAIN ) );
        }
        $valid_reasons = [ 'INVALID_ACTIVATION', 'DUPLICATE_ORDER', 'SERVICE_ISSUES', 'OTHERS' ];
        if ( ! in_array( $reason, $valid_reasons, true ) ) {
            $this->fail( __( 'Motivo de refund inválido.', MPA_TEXTDOMAIN ) );
        }
        if ( strlen( $notes ) > 500 ) {
            $notes = substr( $notes, 0, 500 );
        }
        $data = $this->api->refund_order( [ $iccid ], $reason, $notes );
        OrderLinker::mark_refunded( $target, $reason, $data );
        $this->ok( [
            'data'    => $data,
            'message' => __( 'Refund solicitado.', MPA_TEXTDOMAIN ),
        ] );
    }

    private function find_wc_order_by_iccid( string $iccid ): ?\WC_Order {
        $found = OrderLinker::find_wc_order_by_iccid( $iccid );
        return $found['order'] ?? null;
    }

    private function handle_topup( \WC_Order $wc_order, string $iccid, string $package_id ): void {
        if ( '' === $iccid || '' === $package_id ) {
            $this->fail( __( 'ICCID y package_id requeridos.', MPA_TEXTDOMAIN ) );
        }
        $desc = sprintf( 'WC#%d topup', $wc_order->get_id() );
        $data = $this->api->topup( $package_id, $iccid, $desc );
        OrderLinker::store_topup( $wc_order, $iccid, $package_id, $data );
        $this->ok( [
            'data'    => $data,
            'message' => __( 'Top-up solicitado.', MPA_TEXTDOMAIN ),
        ] );
    }

    private function handle_topup_by_iccid( string $iccid, string $package_id ): void {
        $order = OrderLinker::find_wc_order_by_iccid( $iccid )['order'] ?? null;
        if ( ! $order ) {
            $this->fail( __( 'No se encontró un pedido WC para esta ICCID.', MPA_TEXTDOMAIN ) );
        }
        $this->handle_topup( $order, $iccid, $package_id );
    }

    private function handle_share_link( \WC_Order $wc_order, string $iccid ): void {
        if ( '' === $iccid ) {
            $this->fail( __( 'ICCID requerido.', MPA_TEXTDOMAIN ) );
        }
        $orders = OrderLinker::get_airalo_orders( $wc_order );
        $iccid_data = null;
        foreach ( (array) $orders as $o ) {
            foreach ( (array) ( $o['sims'] ?? [] ) as $s ) {
                if ( ( $s['iccid'] ?? '' ) === $iccid ) {
                    $iccid_data = $s;
                    break 2;
                }
            }
        }
        $this->ok( [
            'qrcode'      => $iccid_data['qrcode'] ?? '',
            'qrcode_url'  => $iccid_data['qrcode_url'] ?? '',
            'manual_url'  => $iccid_data['direct_apple_installation_url'] ?? '',
            'message'     => __( 'OK', MPA_TEXTDOMAIN ),
        ] );
    }

    private function handle_share_esim( ?\WC_Order $wc_order, string $iccid, string $email, string $option, string $copy ): void {
        if ( '' === $iccid ) {
            $this->fail( __( 'ICCID requerido.', MPA_TEXTDOMAIN ) );
        }
        if ( '' === $email || ! is_email( $email ) ) {
            $this->fail( __( 'Email del cliente inválido.', MPA_TEXTDOMAIN ) );
        }
        $valid_options = [ 'link', 'qrcode' ];
        if ( ! in_array( $option, $valid_options, true ) ) {
            $option = 'link';
        }
        try {
            $data = $this->api->share_esim( $iccid, $email, $option, $copy !== '' ? $copy : null );
            $note = sprintf( 'eSIM %s compartida con %s (%s)', $iccid, $email, $option );
            if ( $wc_order ) {
                $wc_order->add_order_note( $note );
                $wc_order->save();
            }
            $this->ok( [
                'data'    => $data,
                'message' => __( 'eSIM enviada al cliente.', MPA_TEXTDOMAIN ),
            ] );
        } catch ( Exception $e ) {
            $this->fail( $e->getMessage() );
        }
    }

    /**
     * Assigns an eSIM User (Full Name + email) to a SIM via Airalo eSIM Cloud.
     *
     * Airalo's `POST /v2/sims/{iccid}/share` endpoint doubles as the
     * "Assign eSIM User" form in app.partners.airalo.com — passing
     * `name` + `to_email` sets `simable.user.name` / `simable.user.email`
     * in the dashboard.
     */
    private function handle_assign_esim_user( ?\WC_Order $wc_order, string $iccid, string $name, string $email ): void {
        if ( '' === $iccid ) {
            $this->fail( __( 'ICCID requerido.', MPA_TEXTDOMAIN ) );
        }
        if ( '' === $email || ! is_email( $email ) ) {
            $this->fail( __( 'Email del cliente inválido.', MPA_TEXTDOMAIN ) );
        }
        if ( '' === $name ) {
            $name = (string) explode( '@', $email )[0];
        }
        if ( strlen( $name ) > 100 ) {
            $name = substr( $name, 0, 100 );
        }
        try {
            $data = $this->api->assign_esim_user( $iccid, $name, $email, 'link' );
            $note = sprintf( 'eSIM User asignado: %s <%s>', $name, $email );
            if ( $wc_order ) {
                $wc_order->add_order_note( $note );
                OrderLinker::store_airalo_user( $wc_order, $iccid, $name, $email );
                $wc_order->save();
            }
            $this->ok( [
                'data'    => $data,
                'name'    => $name,
                'email'   => $email,
                'message' => __( 'eSIM User asignado.', MPA_TEXTDOMAIN ),
            ] );
        } catch ( Exception $e ) {
            $this->fail( $e->getMessage() );
        }
    }

    private function ok( array $payload ): void {
        wp_send_json_success( $payload );
    }

    private function fail( string $message, int $code = 400 ): void {
        wp_send_json_error( [ 'message' => $message ], $code );
    }
}
