<?php
/**
 * WP Dashboard widget: account balance + last orders.
 *
 * @package Hugo\MiPluginAiralo
 */

namespace Hugo\MiPluginAiralo\Admin;

use Hugo\MiPluginAiralo\Api\Client;
use Hugo\MiPluginAiralo\Api\Exception;
use Hugo\MiPluginAiralo\Plugin;
use Hugo\MiPluginAiralo\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class DashboardWidget {

    public function __construct(
        private readonly Client $api,
        private readonly Logger $logger
    ) {
    }

    public function register(): void {
        add_action( 'wp_dashboard_setup', [ $this, 'add_widget' ] );
    }

    public function add_widget(): void {
        if ( ! current_user_can( Plugin::instance()->capability() ) ) {
            return;
        }
        wp_add_dashboard_widget(
            'mpa_dashboard_widget',
            __( 'Airalo', MPA_TEXTDOMAIN ),
            [ $this, 'render' ]
        );
    }

    public function render(): void {
        if ( ! $this->api->is_configured() ) {
            echo '<p>' . esc_html__( 'Configura las credenciales en .env para conectar con Airalo.', MPA_TEXTDOMAIN ) . '</p>';
            return;
        }

        try {
            $balance = $this->api->get_balance();
            $orders  = $this->api->get_orders( [ 'limit' => 5 ] );
        } catch ( Exception $e ) {
            $this->logger->warning( 'Dashboard widget API error: ' . $e->getMessage() );
            echo '<p>' . esc_html__( 'No se pudo conectar con Airalo.', MPA_TEXTDOMAIN ) . '</p>';
            return;
        }

        echo '<div class="mpa-widget">';
        echo '<h3>' . esc_html__( 'Balance (USD)', MPA_TEXTDOMAIN ) . '</h3>';
        echo '<p class="mpa-widget__balance">$' . esc_html( number_format_i18n( (float) ( $balance['data']['balance'] ?? 0 ), 2 ) ) . '</p>';

        if ( ! empty( $orders ) ) {
            echo '<h3>' . esc_html__( 'Últimas órdenes', MPA_TEXTDOMAIN ) . '</h3>';
            echo '<ul class="mpa-widget__orders">';
            foreach ( (array) $orders as $order ) {
                $code   = isset( $order['code'] ) ? (string) $order['code'] : '';
                $date   = isset( $order['created_at'] ) ? (string) $order['created_at'] : '';
                $status = isset( $order['status'] ) ? (string) $order['status'] : 'completed';
                echo '<li><code>' . esc_html( $code ) . '</code> <span class="mpa-status mpa-status--' . esc_attr( $status ) . '">' . esc_html( $status ) . '</span> <small>' . esc_html( $date ) . '</small></li>';
            }
            echo '</ul>';
        }

        echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=mpa-orders' ) ) . '">' . esc_html__( 'Ver todas', MPA_TEXTDOMAIN ) . '</a></p>';

        $author = sprintf(
            '<small class="mpa-widget__author">%s <a href="%s" target="_blank" rel="noopener">@hugopvigo</a></small>',
            esc_html__( 'Plugin by', MPA_TEXTDOMAIN ),
            esc_url( MPA_AUTHOR_TWITTER )
        );
        echo '<p>' . wp_kses_post( $author ) . '</p>';

        echo '</div>';
    }
}
