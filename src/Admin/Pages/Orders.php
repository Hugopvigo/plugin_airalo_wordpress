<?php
/**
 * Admin page: Orders list.
 *
 * @package Hugo\MiPluginAiralo
 */

namespace Hugo\MiPluginAiralo\Admin\Pages;

use Hugo\MiPluginAiralo\Api\Client;
use Hugo\MiPluginAiralo\Api\Exception;
use Hugo\MiPluginAiralo\Integrations\WooCommerce\OrderLinker;
use Hugo\MiPluginAiralo\Plugin;
use Hugo\MiPluginAiralo\Support\Countries;
use Hugo\MiPluginAiralo\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Orders {

    public function __construct(
        private readonly Client $api,
        private readonly Logger $logger
    ) {
    }

    public function register(): void {}

    public function render(): void {
        if ( ! current_user_can( Plugin::instance()->capability() ) ) {
            wp_die( esc_html__( 'Permisos insuficientes.', MPA_TEXTDOMAIN ) );
        }

        $page     = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $per_page = 20;
        $params   = [
            'include' => 'sims',
            'page'    => $page,
            'limit'   => $per_page,
        ];

        $orders = [];
        $error  = null;

        if ( $this->api->is_configured() ) {
            try {
                $orders = $this->api->get_orders( $params );
            } catch ( Exception $e ) {
                $error = $e->getMessage();
                $this->logger->warning( 'Orders list: ' . $e->getMessage() );
            }
        }

        ?>
        <div class="wrap mpa-wrap">
            <h1><?php esc_html_e( 'Airalo · Órdenes', MPA_TEXTDOMAIN ); ?></h1>

            <?php if ( $error ) : ?>
                <div class="notice notice-error inline"><p><?php echo esc_html( $error ); ?></p></div>
            <?php endif; ?>

            <?php $this->render_table( $orders ); ?>

            <p class="mpa-pagination">
                <?php if ( $page > 1 ) : ?>
                    <a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $page - 1 ) ); ?>"><?php esc_html_e( '« Anterior', MPA_TEXTDOMAIN ); ?></a>
                <?php endif; ?>
                <?php if ( count( $orders ) === $per_page ) : ?>
                    <a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $page + 1 ) ); ?>"><?php esc_html_e( 'Siguiente »', MPA_TEXTDOMAIN ); ?></a>
                <?php endif; ?>
            </p>
        </div>
        <?php
    }

    private function render_table( array $orders ): void {
        if ( empty( $orders ) ) {
            echo '<p>' . esc_html__( 'No hay órdenes para mostrar.', MPA_TEXTDOMAIN ) . '</p>';
            return;
        }
        ?>
            <table class="widefat striped mpa-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Código', MPA_TEXTDOMAIN ); ?></th>
                    <th><?php esc_html_e( 'Usuario', MPA_TEXTDOMAIN ); ?></th>
                    <th><?php esc_html_e( 'Procesado por', MPA_TEXTDOMAIN ); ?></th>
                    <th><?php esc_html_e( 'Fecha', MPA_TEXTDOMAIN ); ?></th>
                    <th><?php esc_html_e( 'Paquete', MPA_TEXTDOMAIN ); ?></th>
                    <th><?php esc_html_e( 'Cantidad', MPA_TEXTDOMAIN ); ?></th>
                    <th><?php esc_html_e( 'Precio', MPA_TEXTDOMAIN ); ?></th>
                    <th><?php esc_html_e( 'Estado', MPA_TEXTDOMAIN ); ?></th>
                    <th><?php esc_html_e( 'Descripción', MPA_TEXTDOMAIN ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $orders as $o ) :
                $package_id = (string) ( $o['package_id'] ?? '' );
                $package    = (string) ( $o['package'] ?? $package_id );
                $country    = $this->api->get_country_for_package( $package_id );
                $status_raw = strtolower( (string) ( $o['status'] ?? 'completed' ) );
                $wc_order_for_proc = $this->resolve_wc_order( $o );
                ?>
                <tr>
                    <td><code><?php echo esc_html( (string) ( $o['code'] ?? '' ) ); ?></code></td>
                    <td><?php echo $this->render_order_user( $o ); ?></td>
                    <td><?php echo $this->render_processed_by( $wc_order_for_proc ); ?></td>
                    <td><?php echo esc_html( (string) ( $o['created_at'] ?? '' ) ); ?></td>
                    <td>
                        <strong><?php echo esc_html( $package ); ?></strong>
                        <?php if ( '' !== $country ) : ?>
                            <span class="mpa-pill mpa-pill--country" title="<?php echo esc_attr( $country ); ?>"><?php echo esc_html( Countries::name( $country ) ); ?></span>
                        <?php endif; ?>
                        <br><code class="mpa-mono"><?php echo esc_html( $package_id ); ?></code>
                    </td>
                    <td><?php echo esc_html( (string) ( $o['quantity'] ?? '' ) ); ?></td>
                    <td>$<?php echo esc_html( number_format_i18n( (float) ( $o['price'] ?? 0 ), 2 ) ); ?></td>
                    <td><span class="mpa-status mpa-status--<?php echo esc_attr( $status_raw ); ?>"><?php echo esc_html( $status_raw ); ?></span></td>
                    <td><?php echo esc_html( (string) ( $o['description'] ?? '' ) ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Resolves the WC customer for an Airalo order.
     *
     * Strategy:
     *  1) Match by `WC#<id>` in description (our canonical link).
     *  2) Match by `sims[].iccid` in the WC order's `_mpa_airalo_orders` meta.
     *  3) Fallback to the order's billing email (used by the official Airalo
     *     WC plugin as a less-reliable secondary key).
     */
    private function resolve_wc_order( array $airalo_order ): ?\WC_Order {
        $desc = (string) ( $airalo_order['description'] ?? '' );
        if ( $desc && preg_match( '/WC#(\d+)/', $desc, $m ) ) {
            $order = wc_get_order( (int) $m[1] );
            if ( $order ) {
                return $order;
            }
        }

        if ( class_exists( '\\WC_Order_Query' ) ) {
            $iccid_needles = [];
            foreach ( (array) ( $airalo_order['sims'] ?? [] ) as $sim ) {
                $iccid_needles[] = (string) ( $sim['iccid'] ?? '' );
            }
            $iccid_needles = array_filter( $iccid_needles );
            if ( ! empty( $iccid_needles ) ) {
                $orders = wc_get_orders( [
                    'limit'   => 50,
                    'orderby' => 'date',
                    'order'   => 'DESC',
                    'status'  => [ 'wc-processing', 'wc-completed' ],
                ] );
                foreach ( (array) $orders as $wc_order ) {
                    $linked = OrderLinker::get_airalo_orders( $wc_order );
                    foreach ( (array) $linked as $linked_airalo ) {
                        foreach ( (array) ( $linked_airalo['sims'] ?? [] ) as $linked_sim ) {
                            if ( in_array( (string) ( $linked_sim['iccid'] ?? '' ), $iccid_needles, true ) ) {
                                return $wc_order;
                            }
                        }
                    }
                }
            }
        }

        return null;
    }

    private function render_order_user( array $airalo_order ): string {
        $wc_order = $this->resolve_wc_order( $airalo_order );
        if ( ! $wc_order ) {
            return '<span style="color:#8c8f94;">—</span>';
        }
        $name  = trim( (string) $wc_order->get_billing_first_name() . ' ' . $wc_order->get_billing_last_name() );
        $email = (string) $wc_order->get_billing_email();
        $edit  = $wc_order->get_edit_order_url();
        if ( '' === $name && '' === $email ) {
            return '<a href="' . esc_url( $edit ) . '">#' . esc_html( (string) $wc_order->get_id() ) . '</a>';
        }
        $label = $name ? $name : $email;
        $line2 = $name ? $email : '';
        return '<a href="' . esc_url( $edit ) . '">' . esc_html( $label ) . '</a>'
            . ( '' !== $line2 ? '<br><small style="color:#50575e;">' . esc_html( $line2 ) . '</small>' : '' );
    }

    /**
     * Renders the "Procesado por" cell for the Orders page.
     *
     * Resolution order:
     *  1) `_mpa_processed_by` meta (the user who synced the order).
     *  2) `post_author` of the WC order (the user who created the order).
     *  3) — if no WC order can be linked.
     */
    private function render_processed_by( ?\WC_Order $wc_order ): string {
        if ( ! $wc_order ) {
            return '<span style="color:#8c8f94;">—</span>';
        }

        $processed = OrderLinker::get_processed_by( $wc_order );
        if ( ! $processed ) {
            $author_id = (int) $wc_order->get_user_id();
            if ( $author_id > 0 ) {
                $user = get_userdata( $author_id );
                if ( $user ) {
                    $processed = [
                        'id'      => (int) $user->ID,
                        'login'   => (string) $user->user_login,
                        'email'   => (string) $user->user_email,
                        'display' => $user->display_name ?: $user->user_login,
                    ];
                }
            }
        }

        if ( ! $processed ) {
            return '<span style="color:#8c8f94;">—</span>';
        }

        $edit = get_edit_user_link( (int) ( $processed['id'] ?? 0 ) );
        $name = (string) ( $processed['display'] ?? $processed['login'] ?? '' );
        $login= (string) ( $processed['login'] ?? '' );
        $when = (string) ( $processed['created_at'] ?? '' );

        $html  = '<strong>' . esc_html( $name ) . '</strong>';
        if ( '' !== $login && $login !== $name ) {
            $html .= '<br><small style="color:#50575e;">' . esc_html( $login ) . '</small>';
        }
        if ( $edit ) {
            $html = '<a href="' . esc_url( $edit ) . '">' . $html . '</a>';
        }
        if ( '' !== $when ) {
            $html .= '<br><small style="color:#8c8f94;">' . esc_html( $when ) . '</small>';
        }
        return $html;
    }
}
