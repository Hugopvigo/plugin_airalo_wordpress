<?php
/**
 * WC order meta box: shows Airalo order, ICCIDs, QR, usage and quick actions.
 *
 * @package Hugo\MiPluginAiralo
 */

namespace Hugo\MiPluginAiralo\Integrations\WooCommerce;

use Hugo\MiPluginAiralo\Api\Client;
use Hugo\MiPluginAiralo\Plugin;
use Hugo\MiPluginAiralo\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class OrderMetaBox {

    public function __construct(
        private readonly Client $api,
        private readonly Logger $logger
    ) {
    }

    public function register(): void {
        add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
    }

    public function add_meta_box(): void {
        $screen = function_exists( 'wc_get_order_types' ) ? wc_get_order_types( [ 'view' ] ) : [ 'shop_order' ];
        add_meta_box(
            'mpa_airalo_order',
            __( 'Airalo', MPA_TEXTDOMAIN ),
            [ $this, 'render' ],
            $screen,
            'side',
            'high'
        );
    }

    public function render( \WP_Post $post ): void {
        $order = wc_get_order( $post->ID );
        if ( ! $order ) {
            echo '<p>' . esc_html__( 'Pedido no disponible.', MPA_TEXTDOMAIN ) . '</p>';
            return;
        }

        $airalo_id    = OrderLinker::get_airalo_order_id( $order );
        $airalo_data  = OrderLinker::get_airalo_orders( $order );
        $usage        = OrderLinker::get_usage( $order );
        $instructions = OrderLinker::get_instructions( $order );
        $refund       = OrderLinker::get_refund( $order );
        $topups       = OrderLinker::get_topups( $order );

        $sims = [];
        foreach ( (array) $airalo_data as $o ) {
            foreach ( (array) ( $o['sims'] ?? [] ) as $s ) {
                if ( ! empty( $s['iccid'] ) ) {
                    $sims[] = $s;
                }
            }
        }

        if ( $airalo_id && $this->api->is_configured() && ! empty( $sims ) ) {
            $this->maybe_auto_sync_usage( $order, $sims );
            $usage = OrderLinker::get_usage( $order );
        }

        ?>
        <div class="mpa-metabox" data-order="<?php echo esc_attr( (string) $order->get_id() ); ?>">

            <p>
                <strong><?php esc_html_e( 'Airalo Order ID:', MPA_TEXTDOMAIN ); ?></strong>
                <?php if ( $airalo_id ) : ?>
                    <code><?php echo esc_html( $airalo_id ); ?></code>
                <?php else : ?>
                    <em><?php esc_html_e( 'No vinculado', MPA_TEXTDOMAIN ); ?></em>
                <?php endif; ?>
            </p>

            <p>
                <button type="button" class="button button-secondary mpa-action" data-action="mpa_sync_order">
                    <?php esc_html_e( 'Sincronizar desde Airalo', MPA_TEXTDOMAIN ); ?>
                </button>
            </p>

            <?php if ( $refund ) : ?>
                <div class="mpa-alert mpa-alert--warning">
                    <strong><?php esc_html_e( 'Refund solicitado', MPA_TEXTDOMAIN ); ?></strong>
                    <small><?php echo esc_html( (string) ( $refund['created_at'] ?? '' ) ); ?></small>
                </div>
            <?php endif; ?>

            <?php if ( empty( $sims ) ) : ?>
                <p class="description"><?php esc_html_e( 'No hay eSIMs vinculadas a este pedido.', MPA_TEXTDOMAIN ); ?></p>
            <?php else : ?>
                <ul class="mpa-sim-list">
                    <?php foreach ( $sims as $sim ) :
                        $iccid       = (string) ( $sim['iccid'] ?? '' );
                        $iccid_usage = $usage[ $iccid ] ?? null;

                        $remaining = isset( $iccid_usage['data']['remaining'] ) ? (float) $iccid_usage['data']['remaining'] : null;
                        $total     = isset( $iccid_usage['data']['total'] ) ? (float) $iccid_usage['data']['total'] : null;
                        $used_pct  = ( null !== $remaining && null !== $total && $total > 0 )
                            ? max( 0, min( 100, ( 1 - $remaining / $total ) * 100 ) )
                            : null;
                        $usage_class = '';
                        $usage_pill  = '';
                        if ( null !== $used_pct ) {
                            if ( $used_pct >= 90 ) {
                                $usage_class = 'mpa-usage--critical';
                                $usage_pill  = 'critical';
                            } elseif ( $used_pct >= 70 ) {
                                $usage_class = 'mpa-usage--warning';
                                $usage_pill  = 'warning';
                            } else {
                                $usage_class = '';
                                $usage_pill  = 'ok';
                            }
                        }

                        $detail_url = add_query_arg(
                            [ 'page' => 'mpa-esim-detail', 'iccid' => $iccid ],
                            admin_url( 'admin.php' )
                        );
                        $updated_at = isset( $iccid_usage['updated'] ) ? (string) $iccid_usage['updated'] : '';
                        ?>
                        <li class="mpa-sim" data-iccid="<?php echo esc_attr( $iccid ); ?>">
                            <div class="mpa-sim__head">
                                <code class="mpa-sim__iccid"><?php echo esc_html( $iccid ); ?></code>
                                <?php if ( null !== $used_pct ) : ?>
                                    <span class="mpa-sim__usage-pill mpa-sim__usage-pill--<?php echo esc_attr( $usage_pill ); ?>">
                                        <?php echo esc_html( number_format_i18n( $used_pct, 0 ) ); ?>%
                                    </span>
                                <?php endif; ?>
                                <a class="mpa-chip" href="<?php echo esc_url( $detail_url ); ?>"><?php esc_html_e( 'Detalle', MPA_TEXTDOMAIN ); ?> →</a>
                            </div>

                            <?php if ( $iccid_usage ) : ?>
                                <div class="mpa-usage <?php echo esc_attr( $usage_class ); ?>">
                                    <div class="mpa-usage__bar">
                                        <span style="width: <?php echo esc_attr( (string) ( $used_pct ?? 0 ) ); ?>%"></span>
                                    </div>
                                    <small>
                                        <?php
                                        if ( null !== $remaining && null !== $total ) {
                                            echo esc_html( sprintf( '%s / %s', size_format( (float) ( $total - $remaining ) ), size_format( $total ) ) );
                                        } else {
                                            echo esc_html__( 'Sin datos', MPA_TEXTDOMAIN );
                                        }
                                        ?>
                                    </small>
                                    <?php if ( '' !== $updated_at ) : ?>
                                        <small class="mpa-usage__last-update">
                                            <?php
                                            /* translators: %s: timestamp */
                                            echo esc_html( sprintf( __( 'Actualizado %s', MPA_TEXTDOMAIN ), $updated_at ) );
                                            ?>
                                        </small>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>

                            <div class="mpa-sim__actions">
                                <button type="button" class="button button-small mpa-action" data-action="mpa_get_usage">
                                    <?php esc_html_e( 'Uso', MPA_TEXTDOMAIN ); ?>
                                </button>
                                <button type="button" class="button button-small mpa-action" data-action="mpa_get_qr">
                                    <?php esc_html_e( 'QR / instrucciones', MPA_TEXTDOMAIN ); ?>
                                </button>
                                <button type="button" class="button button-small mpa-action" data-action="mpa_topup">
                                    <?php esc_html_e( 'Top-up', MPA_TEXTDOMAIN ); ?>
                                </button>
                                <button type="button" class="button button-small mpa-action mpa-action--danger mpa-refund-toggle">
                                    <?php esc_html_e( 'Refund', MPA_TEXTDOMAIN ); ?>
                                </button>
                            </div>
                            <div class="mpa-refund-form" style="display:none;margin-top:6px;">
                                <select class="mpa-refund-reason" style="width:100%;margin-bottom:4px;">
                                    <option value="SERVICE_ISSUES"><?php esc_html_e( 'Problemas de servicio', MPA_TEXTDOMAIN ); ?></option>
                                    <option value="INVALID_ACTIVATION"><?php esc_html_e( 'La eSIM no se activó', MPA_TEXTDOMAIN ); ?></option>
                                    <option value="DUPLICATE_ORDER"><?php esc_html_e( 'Pedido duplicado', MPA_TEXTDOMAIN ); ?></option>
                                    <option value="OTHERS"><?php esc_html_e( 'Otros motivos', MPA_TEXTDOMAIN ); ?></option>
                                </select>
                                <textarea class="mpa-refund-notes" style="width:100%;min-height:40px;font-size:12px;" placeholder="<?php esc_attr_e( 'Notas opcionales…', MPA_TEXTDOMAIN ); ?>"></textarea>
                                <button type="button" class="button button-small mpa-action mpa-action--danger" data-action="mpa_refund_esim">
                                    <?php esc_html_e( 'Confirmar refund', MPA_TEXTDOMAIN ); ?>
                                </button>
                            </div>

                            <?php if ( ! empty( $instructions[ $iccid ] ) ) : ?>
                                <details class="mpa-sim__details">
                                    <summary><?php esc_html_e( 'Instrucciones de instalación', MPA_TEXTDOMAIN ); ?></summary>
                                    <pre><?php echo esc_html( wp_json_encode( $instructions[ $iccid ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ); ?></pre>
                                </details>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if ( ! empty( $topups ) ) : ?>
                <h4><?php esc_html_e( 'Top-ups', MPA_TEXTDOMAIN ); ?></h4>
                <ul class="mpa-topups">
                    <?php foreach ( (array) $topups as $t ) : ?>
                        <li>
                            <code><?php echo esc_html( (string) ( $t['iccid'] ?? '' ) ); ?></code>
                            <span><?php echo esc_html( (string) ( $t['package_id'] ?? '' ) ); ?></span>
                            <small><?php echo esc_html( (string) ( $t['created_at'] ?? '' ) ); ?></small>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <p>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=mpa-orders' ) ); ?>"><?php esc_html_e( 'Ver todas las órdenes Airalo', MPA_TEXTDOMAIN ); ?> →</a>
            </p>
        </div>
        <?php
    }

    /**
     * Refreshes usage for each ICCID if data is older than the threshold.
     * Bounded to a short window so the metabox render stays fast.
     */
    private function maybe_auto_sync_usage( \WC_Order $order, array $sims ): void {
        $threshold = (int) apply_filters( 'mpa_usage_autosync_threshold', 30 * MINUTE_IN_SECONDS );
        $stored    = OrderLinker::get_usage( $order );
        $now       = time();

        foreach ( $sims as $sim ) {
            $iccid = (string) ( $sim['iccid'] ?? '' );
            if ( '' === $iccid ) {
                continue;
            }
            $last = isset( $stored[ $iccid ]['updated'] ) ? strtotime( (string) $stored[ $iccid ]['updated'] ) : 0;
            if ( $last && ( $now - $last ) < $threshold ) {
                continue;
            }
            try {
                $data = $this->api->get_sim_usage( $iccid );
                OrderLinker::store_usage( $order, $iccid, is_array( $data ) ? $data : [] );
            } catch ( \Throwable $e ) {
                $this->logger->warning( 'Auto-sync usage failed for ' . $iccid . ': ' . $e->getMessage() );
            }
        }
    }
}
