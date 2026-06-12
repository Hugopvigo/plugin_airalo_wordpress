<?php
/**
 * Plugin Name: Mi Plugin Airalo
 * Plugin URI:  https://www.suop.es/
 * Description: Panel de backoffice para vincular pedidos WooCommerce con Airalo, gestionar refunds, eSIMs/QR, top-ups y consumo de datos.
 * Version:     2.4.3
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author:      SUOP Mobile SL — Hugo Pérez-Vigo (@hugopvigo)
 * Author URI:  https://www.suop.es/
 * Text Domain: mi-plugin-airalo
 * Domain Path: /languages
 * License:     Proprietary
 *
 * @package Hugo\MiPluginAiralo
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'MPA_VERSION', '2.4.3' );
define( 'MPA_FILE', __FILE__ );
define( 'MPA_PATH', plugin_dir_path( __FILE__ ) );
define( 'MPA_URL', plugin_dir_url( __FILE__ ) );
define( 'MPA_SLUG', 'mi-plugin-airalo' );
define( 'MPA_TEXTDOMAIN', 'mi-plugin-airalo' );
define( 'MPA_API_BASE', 'https://partners-api.airalo.com/v2' );
define( 'MPA_CACHE_GROUP', 'mpa_airalo' );
define( 'MPA_AUTHOR_NAME', 'SUOP Mobile SL — Hugo Pérez-Vigo (@hugopvigo)' );
define( 'MPA_AUTHOR_URI', 'https://www.suop.es/' );
define( 'MPA_AUTHOR_TWITTER', 'https://twitter.com/hugopvigo' );

require_once MPA_PATH . 'vendor/autoload.php';

$env_file = MPA_PATH . '.env';
if ( file_exists( $env_file ) && class_exists( '\\Dotenv\\Dotenv' ) ) {
    try {
        $dotenv = \Dotenv\Dotenv::createImmutable( dirname( $env_file ) );
        $dotenv->safeLoad();
    } catch ( \Throwable $e ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[MPA] .env load error: ' . $e->getMessage() );
        }
    }
}

require_once MPA_PATH . 'src/Plugin.php';
require_once MPA_PATH . 'src/Env/Config.php';
require_once MPA_PATH . 'src/Support/Logger.php';
require_once MPA_PATH . 'src/Support/Assets.php';
require_once MPA_PATH . 'src/Api/Exception.php';
require_once MPA_PATH . 'src/Api/Client.php';
require_once MPA_PATH . 'src/Admin/Menu.php';
require_once MPA_PATH . 'src/Admin/DashboardWidget.php';
require_once MPA_PATH . 'src/Admin/Pages/Dashboard.php';
require_once MPA_PATH . 'src/Admin/Pages/Orders.php';
require_once MPA_PATH . 'src/Admin/Pages/Esims.php';
require_once MPA_PATH . 'src/Admin/Pages/EsimDetail.php';
require_once MPA_PATH . 'src/Admin/Pages/Settings.php';
require_once MPA_PATH . 'src/Admin/Pages/PlaceOrder.php';
require_once MPA_PATH . 'src/Admin/Pages/Reconciliation.php';
require_once MPA_PATH . 'src/Admin/Ajax/OrderActions.php';
require_once MPA_PATH . 'src/Admin/Ajax/Export.php';
require_once MPA_PATH . 'src/Cron/Daily.php';
require_once MPA_PATH . 'src/Webhooks/AiraloListener.php';
require_once MPA_PATH . 'src/Integrations/WooCommerce/OrderLinker.php';
require_once MPA_PATH . 'src/Integrations/WooCommerce/OrderMetaBox.php';
require_once MPA_PATH . 'src/Front/DispositivosShortcode.php';

add_action( 'plugins_loaded', static function () {
    \Hugo\MiPluginAiralo\Plugin::instance()->boot();
} );

register_activation_hook( __FILE__, [ \Hugo\MiPluginAiralo\Plugin::class, 'on_activate' ] );
register_deactivation_hook( __FILE__, [ \Hugo\MiPluginAiralo\Plugin::class, 'on_deactivate' ] );
