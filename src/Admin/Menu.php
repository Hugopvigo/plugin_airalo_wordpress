<?php
/**
 * Admin menu registration.
 *
 * @package Hugo\MiPluginAiralo
 */

namespace Hugo\MiPluginAiralo\Admin;

use Hugo\MiPluginAiralo\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Menu {

    public const PARENT_SLUG = 'mpa-dashboard';

    public function register(): void {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_head', [ $this, 'hide_hidden_submenus' ] );
    }

    public function hide_hidden_submenus(): void {
        $hidden = [ 'mpa-esim-detail' ];
        foreach ( $hidden as $slug ) {
            remove_submenu_page( self::PARENT_SLUG, $slug );
        }
    }

    public function register_menu(): void {
        $cap = Plugin::instance()->capability();

        add_menu_page(
            __( 'Airalo', MPA_TEXTDOMAIN ),
            __( 'Airalo', MPA_TEXTDOMAIN ),
            $cap,
            self::PARENT_SLUG,
            [ Plugin::instance()->page_dashboard, 'render' ],
            'dashicons-smartphone',
            56
        );

        add_submenu_page(
            self::PARENT_SLUG,
            __( 'Dashboard', MPA_TEXTDOMAIN ),
            __( 'Dashboard', MPA_TEXTDOMAIN ),
            $cap,
            self::PARENT_SLUG,
            [ Plugin::instance()->page_dashboard, 'render' ]
        );

        add_submenu_page(
            self::PARENT_SLUG,
            __( 'Órdenes', MPA_TEXTDOMAIN ),
            __( 'Órdenes', MPA_TEXTDOMAIN ),
            $cap,
            'mpa-orders',
            [ Plugin::instance()->page_orders, 'render' ]
        );

        add_submenu_page(
            self::PARENT_SLUG,
            __( 'eSIMs', MPA_TEXTDOMAIN ),
            __( 'eSIMs', MPA_TEXTDOMAIN ),
            $cap,
            'mpa-esims',
            [ Plugin::instance()->page_esims, 'render' ]
        );

        add_submenu_page(
            self::PARENT_SLUG,
            __( 'Detalle eSIM', MPA_TEXTDOMAIN ),
            __( 'Detalle eSIM', MPA_TEXTDOMAIN ),
            $cap,
            'mpa-esim-detail',
            [ Plugin::instance()->page_esim_detail, 'render' ]
        );

        add_submenu_page(
            self::PARENT_SLUG,
            __( 'Ajustes', MPA_TEXTDOMAIN ),
            __( 'Ajustes', MPA_TEXTDOMAIN ),
            $cap,
            'mpa-settings',
            [ Plugin::instance()->page_settings, 'render' ]
        );

        add_submenu_page(
            self::PARENT_SLUG,
            __( 'Place order', MPA_TEXTDOMAIN ),
            __( 'Place order', MPA_TEXTDOMAIN ),
            $cap,
            'mpa-place-order',
            [ Plugin::instance()->page_place_order, 'render' ]
        );

        add_submenu_page(
            self::PARENT_SLUG,
            __( 'Reembolsos', MPA_TEXTDOMAIN ),
            __( 'Reembolsos', MPA_TEXTDOMAIN ),
            $cap,
            'mpa-reconciliation',
            [ Plugin::instance()->page_reconciliation, 'render' ]
        );
    }
}
