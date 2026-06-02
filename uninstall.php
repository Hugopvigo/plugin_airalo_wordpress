<?php
/**
 * Uninstall handler for Mi Plugin Airalo.
 * Removes options, transients, post meta and scheduled events.
 *
 * @package Hugo\MiPluginAiralo
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

$options = [
    'mpa_settings',
    'mpa_airalo_token',
    'mpa_airalo_balance',
    'mpa_airalo_devices',
];
foreach ( $options as $opt ) {
    delete_option( $opt );
}

$wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_mpa\\_airalo\\_%' OR option_name LIKE '_transient_timeout_mpa\\_airalo\\_%' ESCAPE '\\\\'"
);

$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s OR meta_key LIKE %s",
        $wpdb->esc_like( '_mpa_airalo_' ) . '%',
        $wpdb->esc_like( '_airalo_' ) . '%'
    )
);

$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
        $wpdb->esc_like( 'mpa_' ) . '%'
    )
)

;
wp_clear_scheduled_hook( 'mpa_daily_sync' );
