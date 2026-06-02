<?php
/**
 * Admin page: Settings + connection check.
 *
 * @package Hugo\MiPluginAiralo
 */

namespace Hugo\MiPluginAiralo\Admin\Pages;

use Hugo\MiPluginAiralo\Api\Client;
use Hugo\MiPluginAiralo\Env\Config;
use Hugo\MiPluginAiralo\Plugin;
use Hugo\MiPluginAiralo\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Settings {

    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger
    ) {
    }

    public function register(): void {
        add_action( 'admin_post_mpa_check_connection', [ $this, 'handle_check' ] );
        add_action( 'admin_post_mpa_regen_webhook_secret', [ $this, 'handle_regen_webhook' ] );
    }

    public function render(): void {
        if ( ! current_user_can( Plugin::instance()->capability() ) ) {
            wp_die( esc_html__( 'Permisos insuficientes.', MPA_TEXTDOMAIN ) );
        }

        $configured = $this->config->is_configured();
        $env        = $this->config->env();

        $check_url = wp_nonce_url(
            add_query_arg( [ 'action' => 'mpa_check_connection' ], admin_url( 'admin-post.php' ) ),
            'mpa_check_connection'
        );

        ?>
        <div class="wrap mpa-wrap">
            <h1><?php esc_html_e( 'Airalo · Ajustes', MPA_TEXTDOMAIN ); ?></h1>

            <div class="mpa-card mpa-card--wide">
                <h2 class="mpa-card__title"><?php esc_html_e( 'Estado de la conexión', MPA_TEXTDOMAIN ); ?></h2>
                <p>
                    <strong><?php esc_html_e( 'Entorno:', MPA_TEXTDOMAIN ); ?></strong>
                    <code><?php echo esc_html( strtoupper( $env ) ); ?></code>
                </p>
                <p>
                    <strong><?php esc_html_e( 'Credenciales .env:', MPA_TEXTDOMAIN ); ?></strong>
                    <?php if ( $configured ) : ?>
                        <span class="mpa-status mpa-status--completed"><?php esc_html_e( 'Configuradas', MPA_TEXTDOMAIN ); ?></span>
                    <?php else : ?>
                        <span class="mpa-status mpa-status--cancelled"><?php esc_html_e( 'Faltan', MPA_TEXTDOMAIN ); ?></span>
                    <?php endif; ?>
                </p>
                <p>
                    <a class="button button-secondary" href="<?php echo esc_url( $check_url ); ?>">
                        <?php esc_html_e( 'Probar conexión', MPA_TEXTDOMAIN ); ?>
                    </a>
                </p>
                <?php $this->render_check_result(); ?>
            </div>

            <div class="mpa-card mpa-card--wide">
                <h2 class="mpa-card__title"><?php esc_html_e( 'Configuración vía .env', MPA_TEXTDOMAIN ); ?></h2>
                <p><?php esc_html_e( 'Edita el archivo .env en la raíz del plugin:', MPA_TEXTDOMAIN ); ?></p>
                <pre><code>AIRALO_CLIENT_ID=tu_client_id
AIRALO_CLIENT_SECRET=tu_client_secret
AIRALO_ENV=production   # o "sandbox"</code></pre>
                <p class="description"><?php esc_html_e( 'Si lo prefieres, también puedes definir estas constantes en wp-config.php.', MPA_TEXTDOMAIN ); ?></p>
            </div>

            <div class="mpa-card mpa-card--wide">
                <h2 class="mpa-card__title"><?php esc_html_e( 'Webhook de Airalo', MPA_TEXTDOMAIN ); ?></h2>
                <?php $this->render_webhook_settings(); ?>
            </div>
        </div>
        <?php
    }

    private function render_webhook_settings(): void {
        $secret       = $this->config->webhook_secret();
        $regen_url    = wp_nonce_url(
            add_query_arg( [ 'action' => 'mpa_regen_webhook_secret' ], admin_url( 'admin-post.php' ) ),
            'mpa_regen_webhook_secret'
        );
        ?>
        <p><?php esc_html_e( 'Airalo no firma webhooks de fábrica, así que el listener requiere un secreto compartido vía la cabecera', MPA_TEXTDOMAIN ); ?>
            <code>X-MPA-Signature: sha256=&lt;hmac&gt;</code>.</p>
        <?php if ( '' === $secret ) : ?>
            <div class="notice notice-warning inline">
                <p><strong><?php esc_html_e( 'Webhook desactivado.', MPA_TEXTDOMAIN ); ?></strong>
                <?php esc_html_e( 'Genera un secreto para empezar a aceptar webhooks firmados.', MPA_TEXTDOMAIN ); ?></p>
            </div>
        <?php else : ?>
            <p><strong><?php esc_html_e( 'Secreto actual:', MPA_TEXTDOMAIN ); ?></strong>
                <code id="mpa-webhook-secret"><?php echo esc_html( $secret ); ?></code>
                <button type="button" class="button button-small" data-copy="#mpa-webhook-secret"><?php esc_html_e( 'Copiar', MPA_TEXTDOMAIN ); ?></button>
            </p>
            <p class="description"><?php esc_html_e( 'Si rotas el secreto, actualiza el proxy o el script de firma antes de regenerar.', MPA_TEXTDOMAIN ); ?></p>
        <?php endif; ?>
        <p>
            <a class="button button-secondary" href="<?php echo esc_url( $regen_url ); ?>">
                <?php echo '' === $secret
                    ? esc_html__( 'Generar secreto', MPA_TEXTDOMAIN )
                    : esc_html__( 'Regenerar secreto', MPA_TEXTDOMAIN ); ?>
            </a>
        </p>
        <p><strong><?php esc_html_e( 'Endpoints:', MPA_TEXTDOMAIN ); ?></strong></p>
        <ul>
            <li><code><?php echo esc_url( rest_url( 'mpa/v1/webhook' ) ); ?></code> (REST API)</li>
            <li><code><?php echo esc_url( add_query_arg( 'mpa_webhook', '1', home_url( '/' ) ) ); ?></code> (fallback)</li>
        </ul>
        <?php
    }

    public function handle_regen_webhook(): void {
        if ( ! current_user_can( Plugin::instance()->capability() ) ) {
            wp_die( esc_html__( 'Permisos insuficientes.', MPA_TEXTDOMAIN ) );
        }
        check_admin_referer( 'mpa_regen_webhook_secret' );
        $this->config->set_webhook_secret( wp_generate_password( 48, true, true ) );
        wp_safe_redirect( add_query_arg( [ 'page' => 'mpa-settings' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    public function handle_check(): void {
        if ( ! current_user_can( Plugin::instance()->capability() ) ) {
            wp_die( esc_html__( 'Permisos insuficientes.', MPA_TEXTDOMAIN ) );
        }
        check_admin_referer( 'mpa_check_connection' );

        $api = Plugin::instance()->api;
        try {
            $balance = $api->get_balance();
            $notice  = [
                'type'    => 'success',
                'message' => __( 'Conexión correcta.', MPA_TEXTDOMAIN ) . ' ' . __( 'Balance:', MPA_TEXTDOMAIN ) . ' $' . number_format_i18n( (float) ( $balance['data']['balance'] ?? 0 ), 2 ),
            ];
        } catch ( \Throwable $e ) {
            $this->logger->error( 'Check connection failed: ' . $e->getMessage() );
            $notice = [
                'type'    => 'error',
                'message' => __( 'Conexión fallida:', MPA_TEXTDOMAIN ) . ' ' . $e->getMessage(),
            ];
        }

        set_transient( 'mpa_last_check_notice', $notice, MINUTE_IN_SECONDS );
        wp_safe_redirect( add_query_arg( [ 'page' => 'mpa-settings' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    private function render_check_result(): void {
        $notice = get_transient( 'mpa_last_check_notice' );
        if ( ! is_array( $notice ) ) {
            return;
        }
        delete_transient( 'mpa_last_check_notice' );
        $type = ( 'error' === ( $notice['type'] ?? '' ) ) ? 'error' : 'success';
        echo '<div class="notice notice-' . esc_attr( $type ) . ' inline"><p>' . esc_html( (string) ( $notice['message'] ?? '' ) ) . '</p></div>';
    }
}
