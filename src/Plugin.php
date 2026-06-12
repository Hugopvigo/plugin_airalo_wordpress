<?php
/**
 * Plugin singleton.
 *
 * @package Hugo\MiPluginAiralo
 */

namespace Hugo\MiPluginAiralo;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Plugin {

    private static ?Plugin $instance = null;

    public Api\Client $api;
    public Env\Config $config;
    public Support\Logger $logger;
    public Support\Assets $assets;
    public Admin\Menu $menu;
    public Admin\DashboardWidget $dashboard_widget;
    public Admin\Pages\Dashboard $page_dashboard;
    public Admin\Pages\Orders $page_orders;
    public Admin\Pages\Esims $page_esims;
    public Admin\Pages\EsimDetail $page_esim_detail;
    public Admin\Pages\Settings $page_settings;
    public Admin\Pages\PlaceOrder $page_place_order;
    public Admin\Pages\Reconciliation $page_reconciliation;
    public Admin\Ajax\OrderActions $ajax;
    public Admin\Ajax\Export $ajax_export;
    public Cron\Daily $cron_daily;
    public Webhooks\AiraloListener $webhook;
    public Integrations\WooCommerce\OrderLinker $order_linker;
    public Integrations\WooCommerce\OrderMetaBox $order_meta_box;
    public Front\DispositivosShortcode $front;

    public static function instance(): Plugin {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->config  = new Env\Config();
        $this->logger  = new Support\Logger();
        $this->assets  = new Support\Assets();
        $this->api     = new Api\Client( $this->config, $this->logger );

        $this->menu            = new Admin\Menu();
        $this->dashboard_widget= new Admin\DashboardWidget( $this->api, $this->logger );
        $this->page_dashboard  = new Admin\Pages\Dashboard( $this->api, $this->logger );
        $this->page_orders     = new Admin\Pages\Orders( $this->api, $this->logger );
        $this->page_esims      = new Admin\Pages\Esims( $this->api, $this->logger );
        $this->page_esim_detail= new Admin\Pages\EsimDetail( $this->api, $this->logger );
        $this->page_settings   = new Admin\Pages\Settings( $this->config, $this->logger );
        $this->page_place_order= new Admin\Pages\PlaceOrder( $this->config, $this->logger, $this->api );
        $this->page_reconciliation = new Admin\Pages\Reconciliation( $this->api, $this->logger );
        $this->ajax            = new Admin\Ajax\OrderActions( $this->api, $this->logger );
        $this->ajax_export     = new Admin\Ajax\Export( $this->api, $this->logger );
        $this->cron_daily      = new Cron\Daily( $this->api, $this->logger );
        $this->webhook         = new Webhooks\AiraloListener( $this->logger, $this->config );

        $this->order_linker    = new Integrations\WooCommerce\OrderLinker( $this->api, $this->logger );
        $this->order_meta_box  = new Integrations\WooCommerce\OrderMetaBox( $this->api, $this->logger );

        $this->front           = new Front\DispositivosShortcode( $this->api, $this->logger );
    }

    public function boot(): void {
        load_plugin_textdomain( MPA_TEXTDOMAIN, false, dirname( plugin_basename( MPA_FILE ) ) . '/languages' );

        $this->assets->register();
        $this->menu->register();
        $this->dashboard_widget->register();
        $this->page_dashboard->register();
        $this->page_orders->register();
        $this->page_esims->register();
        $this->page_esim_detail->register();
        $this->page_settings->register();
        $this->page_place_order->register();
        $this->page_reconciliation->register();
        $this->ajax->register();
        $this->ajax_export->register();
        $this->cron_daily->register();
        $this->webhook->register();

        if ( $this->is_woocommerce_active() ) {
            $this->order_linker->register();
            $this->order_meta_box->register();
        }

        $this->front->register();

        add_action( 'mpa_revalidate_orders', function ( array $params ) {
            $this->api->revalidate_orders_cache( $params );
        } );
    }

    public function is_woocommerce_active(): bool {
        return class_exists( '\\WooCommerce' );
    }

    public function capability(): string {
        return $this->is_woocommerce_active() ? 'manage_woocommerce' : 'manage_options';
    }

    public static function on_activate(): void {
        if ( ! wp_next_scheduled( 'mpa_daily_sync' ) ) {
            wp_schedule_event( time() + 60, 'daily', 'mpa_daily_sync' );
        }
        flush_rewrite_rules();
    }

    public static function on_deactivate(): void {
        $timestamp = wp_next_scheduled( 'mpa_daily_sync' );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, 'mpa_daily_sync' );
        }
        flush_rewrite_rules();
    }
}
