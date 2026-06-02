<?php
/**
 * Assets registration (admin CSS/JS).
 *
 * @package Hugo\MiPluginAiralo
 */

namespace Hugo\MiPluginAiralo\Support;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Assets {

    public const HANDLE_ADMIN_CSS = 'mpa-admin-css';
    public const HANDLE_ADMIN_JS  = 'mpa-admin-js';
    public const HANDLE_FRONT_CSS = 'mpa-front-css';
    public const HANDLE_FRONT_JS  = 'mpa-front-js';

    public function register(): void {
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_front' ] );
    }

    public function enqueue_admin( string $hook ): void {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

        $on_plugin_page = $screen && isset( $screen->id ) && (
            false !== strpos( (string) $screen->id, '_page_mpa-' )
            || 'toplevel_page_mpa-dashboard' === $screen->id
            || 'admin_page_mpa-esim-detail' === $screen->id
        );
        $is_shop_order  = $screen && in_array( $screen->id, [
            'shop_order',
            'edit-shop_order',
            'woocommerce_page_wc-orders',
            'wc-orders',
        ], true );
        $is_dashboard   = 'index.php' === $hook;

        if ( ! $on_plugin_page && ! $is_shop_order && ! $is_dashboard ) {
            return;
        }

        wp_enqueue_style(
            self::HANDLE_ADMIN_CSS,
            MPA_URL . 'assets/css/admin.css',
            [],
            MPA_VERSION
        );

        wp_enqueue_script(
            self::HANDLE_ADMIN_JS,
            MPA_URL . 'assets/js/admin.js',
            [ 'jquery' ],
            MPA_VERSION,
            true
        );

        wp_localize_script( self::HANDLE_ADMIN_JS, 'MPA', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'mpa_admin' ),
            'i18n'    => [
                'loading'    => __( 'Cargando...', MPA_TEXTDOMAIN ),
                'error'      => __( 'Ha ocurrido un error.', MPA_TEXTDOMAIN ),
                'confirmRefund' => __( '¿Solicitar refund de esta eSIM? Esta acción no se puede deshacer.', MPA_TEXTDOMAIN ),
                'confirmTopup'  => __( '¿Confirmar el top-up?', MPA_TEXTDOMAIN ),
                'copied'        => __( 'Copiado al portapapeles', MPA_TEXTDOMAIN ),
            ],
        ] );
    }

    public function enqueue_front(): void {
        if ( ! is_singular() ) {
            return;
        }
        $post = get_post();
        if ( ! $post || ! has_shortcode( (string) $post->post_content, 'buscador_dispositivos' ) ) {
            return;
        }
        wp_enqueue_style(
            self::HANDLE_FRONT_CSS,
            MPA_URL . 'assets/css/shortcode.css',
            [],
            MPA_VERSION
        );
        wp_enqueue_script(
            self::HANDLE_FRONT_JS,
            MPA_URL . 'assets/js/shortcode.js',
            [],
            MPA_VERSION,
            true
        );
    }
}
