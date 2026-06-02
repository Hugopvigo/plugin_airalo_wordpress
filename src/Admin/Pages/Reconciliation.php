<?php
/**
 * Admin page: Reembolsos (refund form + WC ↔ Airalo reconciliation).
 *
 * - Top: a refund form where the operator pastes an ICCID, picks a reason
 *   and (optionally) adds notes. The form POSTs to admin-post.php and
 *   the handler calls Airalo `/v2/refund`.
 * - Bottom: the legacy orphan tables (WC sin Airalo / Airalo sin WC) so
 *   the existing reconciliation workflow is preserved.
 *
 * @package Hugo\MiPluginAiralo
 */

namespace Hugo\MiPluginAiralo\Admin\Pages;

use Hugo\MiPluginAiralo\Api\Client;
use Hugo\MiPluginAiralo\Api\Exception;
use Hugo\MiPluginAiralo\Integrations\WooCommerce\OrderLinker;
use Hugo\MiPluginAiralo\Plugin;
use Hugo\MiPluginAiralo\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Reconciliation {

    public const NONCE_ACTION     = 'mpa_request_refund';
    public const RATE_LIMIT_KEY   = 'mpa_refund_rl_';
    public const RATE_LIMIT_MAX   = 1;            // Airalo: 1 refund / 5 min / IP
    public const RATE_LIMIT_WINDOW = 5 * MINUTE_IN_SECONDS;

    public function __construct(
        private readonly Client $api,
        private readonly Logger $logger
    ) {
    }

    public function register(): void {
        add_action( 'admin_post_mpa_request_refund', [ $this, 'handle_refund' ] );
    }

    public function render(): void {
        if ( ! current_user_can( Plugin::instance()->capability() ) ) {
            wp_die( esc_html__( 'Permisos insuficientes.', MPA_TEXTDOMAIN ) );
        }

        $notice = get_transient( 'mpa_refund_notice' );
        if ( is_array( $notice ) ) {
            delete_transient( 'mpa_refund_notice' );
        }

        $orphans_wc  = [];
        $orphans_air = [];
        $error       = null;
        $wc_total    = 0;
        $air_total   = 0;
        $wc_page     = max( 1, (int) ( $_GET['wc_page'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $air_page    = max( 1, (int) ( $_GET['air_page'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $per_page    = 20;

        if ( $this->api->is_configured() ) {
            try {
                $orphans_wc = $this->find_wc_orphans( 50, $wc_page, $per_page, $wc_total );
            } catch ( \Throwable $e ) {
                $error = $e->getMessage();
                $this->logger->warning( 'Reembolsos WC orphans: ' . $e->getMessage() );
            }

            try {
                $orphans_air = $this->find_airalo_orphans( $air_page, $per_page, $air_total );
            } catch ( Exception $e ) {
                $error = $error ? $error . '; ' . $e->getMessage() : $e->getMessage();
            }
        } else {
            $error = __( 'Credenciales Airalo no configuradas.', MPA_TEXTDOMAIN );
        }

        $rate_remaining = $this->get_refund_rate_remaining();

        ?>
        <div class="wrap mpa-wrap">
            <h1><?php esc_html_e( 'Airalo · Reembolsos', MPA_TEXTDOMAIN ); ?></h1>

            <?php if ( is_array( $notice ) ) :
                $type = ( 'error' === ( $notice['type'] ?? '' ) ) ? 'error' : 'success'; ?>
                <div class="notice notice-<?php echo esc_attr( $type ); ?> inline">
                    <p><?php echo esc_html( (string) ( $notice['message'] ?? '' ) ); ?></p>
                    <?php if ( ! empty( $notice['wc_order_id'] ) ) : ?>
                        <p><a href="<?php echo esc_url( get_edit_post_link( (int) $notice['wc_order_id'] ) ); ?>"><?php
                            /* translators: %d: WC order id */
                            echo esc_html( sprintf( __( 'Ver pedido WC#%d', MPA_TEXTDOMAIN ), (int) $notice['wc_order_id'] ) );
                        ?></a></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ( $error ) : ?>
                <div class="notice notice-warning inline"><p><?php echo esc_html( $error ); ?></p></div>
            <?php endif; ?>

            <div class="mpa-card mpa-card--wide">
                <h2 class="mpa-card__title"><?php esc_html_e( 'Pedir refund de una eSIM', MPA_TEXTDOMAIN ); ?></h2>
                <p class="description"><?php esc_html_e( 'Pega el ICCID, elige el motivo y envía. El cargo se devuelve en Airalo y se anota en el pedido WC correspondiente.', MPA_TEXTDOMAIN ); ?></p>

                <div class="notice notice-info inline" style="border-left-color:#dba617;">
                    <p>
                        <strong><?php esc_html_e( 'Limitaciones de la API de Airalo:', MPA_TEXTDOMAIN ); ?></strong>
                        <?php esc_html_e( 'Airalo sólo acepta 1 refund cada 5 minutos por IP. Si mandas varios seguidos, el segundo (y siguientes) fallarán con 429 hasta que pase la ventana.', MPA_TEXTDOMAIN ); ?>
                    </p>
                    <p>
                        <?php
                        if ( $rate_remaining > 0 ) {
                            echo esc_html( sprintf( _n( 'Te queda %d refund disponible en este momento.', 'Te quedan %d refunds disponibles en este momento.', $rate_remaining, MPA_TEXTDOMAIN ), $rate_remaining ) );
                        } else {
                            echo '<strong>' . esc_html__( 'Has alcanzado el límite local. Espera 5 min antes de enviar otro refund.', MPA_TEXTDOMAIN ) . '</strong>';
                        }
                        ?>
                    </p>
                </div>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mpa-form">
                    <input type="hidden" name="action" value="mpa_request_refund" />
                    <?php wp_nonce_field( self::NONCE_ACTION ); ?>

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="mpa_refund_iccid"><?php esc_html_e( 'ICCID', MPA_TEXTDOMAIN ); ?></label></th>
                            <td>
                                <input type="text" name="iccid" id="mpa_refund_iccid" class="regular-text" required pattern="\d{16,22}" placeholder="893000000000034143" />
                                <p class="description"><?php esc_html_e( '19-20 dígitos. Lo encuentras en el detalle de la eSIM o en la lista de eSIMs.', MPA_TEXTDOMAIN ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="mpa_refund_reason"><?php esc_html_e( 'Motivo', MPA_TEXTDOMAIN ); ?></label></th>
                            <td>
                                <select name="reason" id="mpa_refund_reason" required>
                                    <option value="SERVICE_ISSUES"><?php esc_html_e( 'Problemas de servicio', MPA_TEXTDOMAIN ); ?></option>
                                    <option value="INVALID_ACTIVATION"><?php esc_html_e( 'La eSIM no se activó', MPA_TEXTDOMAIN ); ?></option>
                                    <option value="DUPLICATE_ORDER"><?php esc_html_e( 'Pedido duplicado', MPA_TEXTDOMAIN ); ?></option>
                                    <option value="OTHERS"><?php esc_html_e( 'Otros motivos', MPA_TEXTDOMAIN ); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="mpa_refund_notes"><?php esc_html_e( 'Notas', MPA_TEXTDOMAIN ); ?></label></th>
                            <td>
                                <textarea name="notes" id="mpa_refund_notes" rows="3" class="large-text" placeholder="<?php esc_attr_e( 'Detalle opcional que verá el equipo de soporte de Airalo…', MPA_TEXTDOMAIN ); ?>"></textarea>
                            </td>
                        </tr>
                    </table>

                    <?php submit_button( __( 'Solicitar refund', MPA_TEXTDOMAIN ), 'primary', 'mpa_submit_refund', false ); ?>
                </form>
            </div>

            <h2><?php esc_html_e( 'Reconciliación WC ↔ Airalo', MPA_TEXTDOMAIN ); ?></h2>
            <p class="description"><?php esc_html_e( 'Pedidos WooCommerce recientes con producto eSIM que no tienen orden Airalo vinculada, y órdenes Airalo recientes sin pedido WC asociado.', MPA_TEXTDOMAIN ); ?></p>

            <h3><?php esc_html_e( 'WC sin Airalo', MPA_TEXTDOMAIN ); ?> (<?php echo esc_html( (string) $wc_total ); ?>)</h3>
            <?php $this->render_wc_orphans( $orphans_wc, $wc_page, $wc_total, $per_page ); ?>

            <h3><?php esc_html_e( 'Airalo sin WC', MPA_TEXTDOMAIN ); ?> (<?php echo esc_html( (string) $air_total ); ?>)</h3>
            <?php $this->render_airalo_orphans( $orphans_air, $air_page, $air_total, $per_page ); ?>
        </div>
        <?php
    }

    public function handle_refund(): void {
        if ( ! current_user_can( Plugin::instance()->capability() ) ) {
            wp_die( esc_html__( 'Permisos insuficientes.', MPA_TEXTDOMAIN ) );
        }
        check_admin_referer( self::NONCE_ACTION );

        $iccid  = isset( $_POST['iccid'] ) ? sanitize_text_field( wp_unslash( $_POST['iccid'] ) ) : '';
        $reason = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
        $notes  = isset( $_POST['notes'] ) ? sanitize_text_field( wp_unslash( $_POST['notes'] ) ) : '';

        $valid_reasons = [ 'INVALID_ACTIVATION', 'DUPLICATE_ORDER', 'SERVICE_ISSUES', 'OTHERS' ];

        if ( ! preg_match( '/^\d{16,22}$/', $iccid ) ) {
            $this->store_notice( 'error', __( 'ICCID inválido (debe tener 16-22 dígitos).', MPA_TEXTDOMAIN ) );
            $this->redirect_back();
        }
        if ( ! in_array( $reason, $valid_reasons, true ) ) {
            $this->store_notice( 'error', __( 'Motivo de refund inválido.', MPA_TEXTDOMAIN ) );
            $this->redirect_back();
        }
        if ( strlen( $notes ) > 500 ) {
            $notes = substr( $notes, 0, 500 );
        }
        if ( ! $this->api->is_configured() ) {
            $this->store_notice( 'error', __( 'Credenciales Airalo no configuradas.', MPA_TEXTDOMAIN ) );
            $this->redirect_back();
        }

        // Local safety net: Airalo enforces 1 refund / 5 min / IP. We mirror
        // the window so two clicks in a row don't both hit the API and one
        // bounces with 429 (which would still leave the meta note dangling).
        if ( ! $this->consume_refund_rate_token() ) {
            $this->store_notice( 'error', __( 'Acabas de enviar un refund. Espera 5 minutos antes de enviar otro (límite de Airalo: 1/5min/IP).', MPA_TEXTDOMAIN ) );
            $this->redirect_back();
        }

        try {
            $response = $this->api->refund_order( [ $iccid ], $reason, $notes );
        } catch ( Exception $e ) {
            $this->release_refund_rate_token();
            $this->logger->error( 'Refund via form failed: ' . $e->getMessage() );
            $this->store_notice( 'error', __( 'Airalo rechazó la petición: ', MPA_TEXTDOMAIN ) . $e->getMessage() );
            $this->redirect_back();
        }

        // Annotate the WC order (if any) so support has a paper trail.
        $wc_order = OrderLinker::find_wc_order_by_iccid( $iccid )['order'] ?? null;
        if ( $wc_order ) {
            OrderLinker::mark_refunded( $wc_order, $reason, is_array( $response ) ? $response : [] );
        }

        $this->store_notice( 'success', sprintf(
            /* translators: %s: ICCID */
            __( 'Refund solicitado correctamente para %s. Airalo lo procesará en los próximos minutos.', MPA_TEXTDOMAIN ),
            $iccid
        ), $wc_order ? (int) $wc_order->get_id() : 0 );
        $this->redirect_back();
    }

    /**
     * Returns the number of refund calls the current user can still make
     * inside the 5-min Airalo window. 0 means "wait".
     */
    private function get_refund_rate_remaining(): int {
        $user_id = get_current_user_id();
        $key     = self::RATE_LIMIT_KEY . $user_id;
        $count   = (int) get_transient( $key );
        return max( 0, self::RATE_LIMIT_MAX - $count );
    }

    /**
     * Spends a refund slot. Returns true if the request can proceed.
     */
    private function consume_refund_rate_token(): bool {
        $user_id = get_current_user_id();
        $key     = self::RATE_LIMIT_KEY . $user_id;
        $count   = (int) get_transient( $key );
        if ( $count >= self::RATE_LIMIT_MAX ) {
            return false;
        }
        set_transient( $key, $count + 1, self::RATE_LIMIT_WINDOW );
        return true;
    }

    /**
     * Roll-back the local rate-limit if the upstream Airalo call failed
     * (so the operator can retry immediately after fixing the cause).
     */
    private function release_refund_rate_token(): void {
        $user_id = get_current_user_id();
        $key     = self::RATE_LIMIT_KEY . $user_id;
        $count   = (int) get_transient( $key );
        if ( $count > 0 ) {
            set_transient( $key, $count - 1, self::RATE_LIMIT_WINDOW );
        }
    }

    private function redirect_back(): void {
        wp_safe_redirect( add_query_arg( [ 'page' => 'mpa-reconciliation' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    private function store_notice( string $type, string $message, int $wc_order_id = 0 ): void {
        set_transient( 'mpa_refund_notice', [
            'type'        => $type,
            'message'     => $message,
            'wc_order_id' => $wc_order_id,
        ], MINUTE_IN_SECONDS * 2 );
    }

    /**
     * Returns the WC orphan slice for the given page. $total_out is filled
     * with the full count so the paginator can render the right number of
     * pages without doing a second pass.
     *
     * @param int $limit       Max orders to inspect (recent first).
     * @param int $page        1-based page of the orphan list.
     * @param int $per_page    Page size for the orphan list.
     * @param int $total_out   Out-param: total orphans across all scanned orders.
     * @return \WC_Order[]
     */
    private function find_wc_orphans( int $limit = 200, int $page = 1, int $per_page = 20, int &$total_out = 0 ): array {
        $query = new \WC_Order_Query( [
            'limit'   => $limit,
            'orderby' => 'date',
            'order'   => 'DESC',
            'status'  => [ 'wc-processing', 'wc-completed' ],
        ] );
        $orders = $query->get_orders();
        $orphans = [];
        foreach ( (array) $orders as $order ) {
            if ( ! $order instanceof \WC_Order ) {
                continue;
            }
            $items = $order->get_items();
            $has_esim = false;
            foreach ( $items as $item ) {
                $product = $item->get_product();
                if ( $product && stripos( (string) $product->get_name(), 'esim' ) !== false ) {
                    $has_esim = true;
                    break;
                }
                $cats = wp_get_post_terms( $product ? $product->get_id() : 0, 'product_cat', [ 'fields' => 'names' ] );
                foreach ( (array) $cats as $c ) {
                    if ( stripos( (string) $c, 'esim' ) !== false ) {
                        $has_esim = true;
                        break 2;
                    }
                }
            }
            if ( ! $has_esim ) {
                continue;
            }
            if ( ! OrderLinker::get_airalo_order_id( $order ) ) {
                $orphans[] = $order;
            }
        }
        $total_out = count( $orphans );
        $offset    = max( 0, ( $page - 1 ) * $per_page );
        return array_slice( $orphans, $offset, $per_page );
    }

    private function find_airalo_orphans( int $page = 1, int $per_page = 20, int &$total_out = 0 ): array {
        $airalo_orders = $this->api->get_orders_paginated( 20, 50 );
        $orphans = [];
        foreach ( (array) $airalo_orders as $o ) {
            $desc = (string) ( $o['description'] ?? '' );
            if ( '' === $desc ) {
                continue;
            }
            if ( ! preg_match( '/WC#(\d+)/', $desc, $m ) ) {
                $orphans[] = $o;
                continue;
            }
            $order = wc_get_order( (int) $m[1] );
            if ( ! $order ) {
                $orphans[] = $o;
            }
        }
        $total_out = count( $orphans );
        $offset    = max( 0, ( $page - 1 ) * $per_page );
        return array_slice( $orphans, $offset, $per_page );
    }

    private function render_wc_orphans( array $orders, int $page, int $total, int $per_page ): void {
        if ( empty( $orders ) ) {
            echo '<p>' . esc_html__( 'Todo enlazado.', MPA_TEXTDOMAIN ) . '</p>';
            return;
        }
        ?>
        <table class="widefat striped mpa-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Pedido', MPA_TEXTDOMAIN ); ?></th>
                    <th><?php esc_html_e( 'Cliente', MPA_TEXTDOMAIN ); ?></th>
                    <th><?php esc_html_e( 'Total', MPA_TEXTDOMAIN ); ?></th>
                    <th><?php esc_html_e( 'Fecha', MPA_TEXTDOMAIN ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $orders as $o ) : ?>
                <tr>
                    <td><a href="<?php echo esc_url( $o->get_edit_order_url() ); ?>">#<?php echo esc_html( (string) $o->get_id() ); ?></a></td>
                    <td><?php echo esc_html( (string) $o->get_billing_email() ); ?></td>
                    <td><?php echo wp_kses_post( $o->get_formatted_order_total() ); ?></td>
                    <td><?php $dt = $o->get_date_created(); echo esc_html( $dt ? (string) $dt->date_i18n() : '—' ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php $this->render_pagination( $page, $total, $per_page, 'wc_page' ); ?>
        <?php
    }

    private function render_airalo_orphans( array $orders, int $page, int $total, int $per_page ): void {
        if ( empty( $orders ) ) {
            echo '<p>' . esc_html__( 'Todo enlazado.', MPA_TEXTDOMAIN ) . '</p>';
            return;
        }
        ?>
        <table class="widefat striped mpa-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Airalo code', MPA_TEXTDOMAIN ); ?></th>
                    <th><?php esc_html_e( 'Descripción', MPA_TEXTDOMAIN ); ?></th>
                    <th><?php esc_html_e( 'Fecha', MPA_TEXTDOMAIN ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $orders as $o ) : ?>
                <tr>
                    <td><code><?php echo esc_html( (string) ( $o['code'] ?? '' ) ); ?></code></td>
                    <td><?php echo esc_html( (string) ( $o['description'] ?? '' ) ); ?></td>
                    <td><?php echo esc_html( (string) ( $o['created_at'] ?? '' ) ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php $this->render_pagination( $page, $total, $per_page, 'air_page' ); ?>
        <?php
    }

    private function render_pagination( int $page, int $total, int $per_page, string $query_arg ): void {
        $pages = (int) ceil( max( 0, $total ) / max( 1, $per_page ) );
        if ( $pages <= 1 ) {
            return;
        }
        $base = remove_query_arg( $query_arg );
        ?>
        <p class="mpa-pagination">
            <?php for ( $i = 1; $i <= $pages; $i++ ) :
                $url    = add_query_arg( $query_arg, $i, $base );
                $active = ( $i === $page );
                ?>
                <a class="button<?php echo $active ? ' button-primary' : ''; ?>" href="<?php echo esc_url( $url ); ?>" style="margin-right:4px;">
                    <?php echo esc_html( (string) $i ); ?>
                </a>
            <?php endfor; ?>
        </p>
        <?php
    }
}
