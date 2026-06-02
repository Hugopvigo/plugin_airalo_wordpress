<?php
/**
 * Admin page: place a manual order (testing / backoffice).
 *
 * Lets admins pick a package and quantity, then submit. We use the SDK to
 * place a real order on the configured environment.
 *
 * @package Hugo\MiPluginAiralo
 */

namespace Hugo\MiPluginAiralo\Admin\Pages;

use Hugo\MiPluginAiralo\Api\Client;
use Hugo\MiPluginAiralo\Api\Exception;
use Hugo\MiPluginAiralo\Env\Config;
use Hugo\MiPluginAiralo\Plugin;
use Hugo\MiPluginAiralo\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class PlaceOrder {

    private const CACHE_KEY_PACKAGES = 'mpa_airalo_packages_simple';

    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly Client $api
    ) {
    }

    public function register(): void {
        add_action( 'admin_post_mpa_place_order', [ $this, 'handle' ] );
    }

    public function render(): void {
        if ( ! current_user_can( Plugin::instance()->capability() ) ) {
            wp_die( esc_html__( 'Permisos insuficientes.', MPA_TEXTDOMAIN ) );
        }
        if ( ! $this->config->is_configured() ) {
            echo '<div class="wrap"><h1>Airalo</h1><div class="notice notice-error"><p>' . esc_html__( 'Configura las credenciales primero.', MPA_TEXTDOMAIN ) . '</p></div></div>';
            return;
        }

        $packages = $this->get_packages();

        ?>
        <div class="wrap mpa-wrap">
            <h1><?php esc_html_e( 'Airalo · Place order', MPA_TEXTDOMAIN ); ?></h1>
            <div class="notice notice-warning inline" style="border-left-color:#d63638;">
                <p><strong><?php esc_html_e( '⚠️ SOLO USO TÉCNICO — NO TOCAR SIN SUPERVISIÓN', MPA_TEXTDOMAIN ); ?></strong></p>
                <p><?php esc_html_e( 'Esta herramienta crea órdenes reales en Airalo que pueden generar cargos. Únicamente personal autorizado.', MPA_TEXTDOMAIN ); ?></p>
            </div>
            <p class="description"><?php esc_html_e( 'Crea una orden manual. Útil para soporte o testing. La orden se ejecuta en el entorno configurado en .env.', MPA_TEXTDOMAIN ); ?></p>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mpa-form">
                <input type="hidden" name="action" value="mpa_place_order" />
                <?php wp_nonce_field( 'mpa_place_order' ); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="package_id"><?php esc_html_e( 'Paquete', MPA_TEXTDOMAIN ); ?></label></th>
                        <td>
                            <select name="package_id" id="package_id" required>
                                <option value=""><?php esc_html_e( '— Selecciona —', MPA_TEXTDOMAIN ); ?></option>
                                <?php foreach ( (array) $packages as $p ) :
                                    $pid   = (string) ( $p['package_id'] ?? '' );
                                    $title = (string) ( $p['title'] ?? $pid );
                                    $price = isset( $p['net_price'] ) ? (float) $p['net_price'] : (float) ( $p['price'] ?? 0 );
                                    ?>
                                    <option value="<?php echo esc_attr( $pid ); ?>">
                                        <?php echo esc_html( sprintf( '%s — %s ($%s)', $pid, $title, number_format_i18n( $price, 2 ) ) ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="quantity"><?php esc_html_e( 'Cantidad', MPA_TEXTDOMAIN ); ?></label></th>
                        <td>
                            <input type="number" min="1" max="50" name="quantity" id="quantity" value="1" required />
                            <p class="description"><?php esc_html_e( 'Máximo 50 por llamada.', MPA_TEXTDOMAIN ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="description"><?php esc_html_e( 'Descripción', MPA_TEXTDOMAIN ); ?></label></th>
                        <td>
                            <input type="text" name="description" id="description" class="regular-text" placeholder="manual-2025-06-02" />
                        </td>
                    </tr>
                </table>

                <?php submit_button( __( 'Crear orden', MPA_TEXTDOMAIN ) ); ?>
            </form>

            <?php $this->render_result(); ?>
        </div>
        <?php
    }

    public function handle(): void {
        if ( ! current_user_can( Plugin::instance()->capability() ) ) {
            wp_die( esc_html__( 'Permisos insuficientes.', MPA_TEXTDOMAIN ) );
        }
        check_admin_referer( 'mpa_place_order' );

        $package_id = isset( $_POST['package_id'] ) ? sanitize_text_field( wp_unslash( $_POST['package_id'] ) ) : '';
        $quantity   = isset( $_POST['quantity'] ) ? max( 1, min( 50, (int) $_POST['quantity'] ) ) : 1;
        $description= isset( $_POST['description'] ) ? sanitize_text_field( wp_unslash( $_POST['description'] ) ) : '';

        if ( '' === $package_id ) {
            $this->store_notice( 'error', __( 'package_id requerido.', MPA_TEXTDOMAIN ) );
            wp_safe_redirect( add_query_arg( [ 'page' => 'mpa-place-order' ], admin_url( 'admin.php' ) ) );
            exit;
        }

        try {
            $response = $this->api->place_order( $package_id, $quantity, $description ?: null );
            $data     = is_array( $response ) ? $response : [];

            $this->store_notice( 'success', sprintf(
                /* translators: %s: order code */
                __( 'Orden creada. Código: %s', MPA_TEXTDOMAIN ),
                (string) ( $data['data']['code'] ?? '?' )
            ) );
            set_transient( 'mpa_last_place_order', $data, MINUTE_IN_SECONDS * 5 );
        } catch ( \Throwable $e ) {
            $this->logger->error( 'Place order failed: ' . $e->getMessage() );
            $this->store_notice( 'error', $e->getMessage() );
        }

        wp_safe_redirect( add_query_arg( [ 'page' => 'mpa-place-order' ], admin_url( 'admin.php' ) ) );
        exit;
    }

    private function store_notice( string $type, string $message ): void {
        set_transient( 'mpa_place_order_notice', [ 'type' => $type, 'message' => $message ], MINUTE_IN_SECONDS );
    }

    private function render_result(): void {
        $notice = get_transient( 'mpa_place_order_notice' );
        $last   = get_transient( 'mpa_last_place_order' );
        if ( is_array( $notice ) ) {
            delete_transient( 'mpa_place_order_notice' );
            $type = ( 'error' === ( $notice['type'] ?? '' ) ) ? 'error' : 'success';
            echo '<div class="notice notice-' . esc_attr( $type ) . ' inline"><p>' . esc_html( (string) ( $notice['message'] ?? '' ) ) . '</p></div>';
        }
        if ( is_array( $last ) ) {
            echo '<h2>' . esc_html__( 'Última orden', MPA_TEXTDOMAIN ) . '</h2>';
            echo '<pre style="max-height:320px;overflow:auto;background:#fff;padding:10px;border:1px solid #dcdcde;">';
            echo esc_html( wp_json_encode( $last, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
            echo '</pre>';
        }
    }

    private function get_packages(): array {
        $cached = get_transient( self::CACHE_KEY_PACKAGES );
        if ( is_array( $cached ) ) {
            return $cached;
        }
        try {
            $data = $this->api->get_sim_packages( true );
            $list = is_array( $data['data'] ?? null ) ? $data['data'] : ( is_array( $data ) ? $data : [] );
            set_transient( self::CACHE_KEY_PACKAGES, $list, HOUR_IN_SECONDS );
            return $list;
        } catch ( \Throwable $e ) {
            $this->logger->warning( 'getPackages for place order: ' . $e->getMessage() );
            return [];
        }
    }
}
