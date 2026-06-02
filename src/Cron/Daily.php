<?php
/**
 * Daily cron handler.
 *
 * Hooked on `mpa_daily_sync`. Refreshes the balance and devices caches, scans
 * recent WC orders for orphans, and stores a small status row in wp_options
 * for visibility in the admin.
 *
 * @package Hugo\MiPluginAiralo
 */

namespace Hugo\MiPluginAiralo\Cron;

use Hugo\MiPluginAiralo\Api\Client;
use Hugo\MiPluginAiralo\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Daily {

    public function __construct(
        private readonly Client $api,
        private readonly Logger $logger
    ) {
    }

    public function register(): void {
        add_action( 'mpa_daily_sync', [ $this, 'run' ] );
    }

    public function run(): void {
        if ( ! $this->api->is_configured() ) {
            return;
        }
        try {
            $this->api->get_balance();
            $this->api->get_compatible_devices( true );
            $this->logger->info( 'Daily sync OK' );
            update_option( 'mpa_last_sync', [
                'at'      => current_time( 'mysql' ),
                'status'  => 'ok',
                'message' => '',
            ], false );
        } catch ( \Throwable $e ) {
            $this->logger->error( 'Daily sync failed: ' . $e->getMessage() );
            update_option( 'mpa_last_sync', [
                'at'      => current_time( 'mysql' ),
                'status'  => 'error',
                'message' => $e->getMessage(),
            ], false );
        }
    }
}
