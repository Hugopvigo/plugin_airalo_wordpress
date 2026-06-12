<?php
/**
 * Admin page: Dashboard.
 *
 * @package Hugo\MiPluginAiralo
 */

namespace Hugo\MiPluginAiralo\Admin\Pages;

use Hugo\MiPluginAiralo\Api\Client;
use Hugo\MiPluginAiralo\Api\Exception;
use Hugo\MiPluginAiralo\Plugin;
use Hugo\MiPluginAiralo\Support\Countries;
use Hugo\MiPluginAiralo\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Dashboard {

    public function __construct(
        private readonly Client $api,
        private readonly Logger $logger
    ) {
    }

    public function register(): void {
        // Page is rendered via Menu callback.
    }

    public function render(): void {
        if ( ! current_user_can( Plugin::instance()->capability() ) ) {
            wp_die( esc_html__( 'Permisos insuficientes.', MPA_TEXTDOMAIN ) );
        }

        $configured  = $this->api->is_configured();
        $balance     = null;
        $recent      = [];
        $recent_24h  = 0;
        $error       = null;

        if ( $configured ) {
            try {
                $balance = $this->api->get_balance();
                $recent  = $this->api->get_orders( [ 'limit' => 50 ] );
                $cutoff  = strtotime( '-24 hours' );
                foreach ( $recent as $o ) {
                    $ts = strtotime( (string) ( $o['created_at'] ?? '' ) );
                    if ( $ts && $ts >= $cutoff ) {
                        $recent_24h++;
                    }
                }
            } catch ( Exception $e ) {
                $error = $e->getMessage();
                $this->logger->warning( 'Dashboard render: ' . $e->getMessage() );
            }
        }

        ?>
        <div class="wrap mpa-wrap">
            <h1 class="mpa-wrap__title"><?php esc_html_e( 'Airalo · Dashboard', MPA_TEXTDOMAIN ); ?></h1>

            <?php if ( ! $configured ) : ?>
                <div class="notice notice-error inline">
                    <p><?php esc_html_e( 'Credenciales no configuradas. Añade AIRALO_CLIENT_ID y AIRALO_CLIENT_SECRET al archivo .env del plugin.', MPA_TEXTDOMAIN ); ?></p>
                </div>
            <?php elseif ( $error ) : ?>
                <div class="notice notice-warning inline">
                    <p><?php echo esc_html( $error ); ?></p>
                </div>
            <?php endif; ?>

            <div class="mpa-grid mpa-grid--cards">
                <div class="mpa-card">
                    <h2 class="mpa-card__title"><?php esc_html_e( 'Balance', MPA_TEXTDOMAIN ); ?></h2>
                    <p class="mpa-card__big">$<?php echo esc_html( number_format_i18n( (float) ( $balance['data']['balance'] ?? 0 ), 2 ) ); ?></p>
                    <p class="mpa-card__hint"><?php esc_html_e( 'Cuenta partner Airalo', MPA_TEXTDOMAIN ); ?></p>
                </div>
                <div class="mpa-card">
                    <h2 class="mpa-card__title"><?php esc_html_e( 'Entorno', MPA_TEXTDOMAIN ); ?></h2>
                    <p class="mpa-card__big"><?php echo esc_html( strtoupper( $this->env_label() ) ); ?></p>
                    <p class="mpa-card__hint"><?php esc_html_e( 'AIRALO_ENV', MPA_TEXTDOMAIN ); ?></p>
                </div>
                <div class="mpa-card">
                    <h2 class="mpa-card__title"><?php esc_html_e( 'Últimas 24h', MPA_TEXTDOMAIN ); ?></h2>
                    <p class="mpa-card__big"><?php echo esc_html( (string) $recent_24h ); ?></p>
                    <p class="mpa-card__hint"><?php esc_html_e( 'Órdenes en las últimas 24h', MPA_TEXTDOMAIN ); ?></p>
                </div>
            </div>

            <h2><?php esc_html_e( 'Órdenes recientes', MPA_TEXTDOMAIN ); ?></h2>
            <?php $this->render_orders_table( $recent ); ?>
        </div>
        <?php
    }

    private function env_label(): string {
        return (string) apply_filters( 'mpa_env_label', 'production' );
    }

    private function render_orders_table( array $orders ): void {
        if ( empty( $orders ) ) {
            echo '<p>' . esc_html__( 'No hay órdenes.', MPA_TEXTDOMAIN ) . '</p>';
            return;
        }
        ?>
        <table class="widefat striped mpa-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Código', MPA_TEXTDOMAIN ); ?></th>
                    <th><?php esc_html_e( 'Fecha', MPA_TEXTDOMAIN ); ?></th>
                    <th><?php esc_html_e( 'Paquete', MPA_TEXTDOMAIN ); ?></th>
                    <th><?php esc_html_e( 'Cantidad', MPA_TEXTDOMAIN ); ?></th>
                    <th><?php esc_html_e( 'Precio', MPA_TEXTDOMAIN ); ?></th>
                    <th><?php esc_html_e( 'Estado', MPA_TEXTDOMAIN ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $orders as $o ) :
                $package_id = (string) ( $o['package_id'] ?? '' );
                $package    = (string) ( $o['package'] ?? $package_id );
                $country    = $this->api->get_country_for_package( $package_id );
                ?>
                <tr>
                    <td><code><?php echo esc_html( (string) ( $o['code'] ?? '' ) ); ?></code></td>
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
                    <td><span class="mpa-status mpa-status--<?php echo esc_attr( (string) ( $o['status'] ?? 'completed' ) ); ?>"><?php echo esc_html( (string) ( $o['status'] ?? 'completed' ) ); ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }
}
