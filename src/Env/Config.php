<?php
/**
 * Configuration / env loader.
 *
 * @package Hugo\MiPluginAiralo
 */

namespace Hugo\MiPluginAiralo\Env;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Config {

    public const ENV_PRODUCTION = 'production';
    public const ENV_SANDBOX    = 'sandbox';

    public function client_id(): string {
        $value = $this->get( 'AIRALO_CLIENT_ID' );
        return is_string( $value ) ? trim( $value ) : '';
    }

    public function client_secret(): string {
        $value = $this->get( 'AIRALO_CLIENT_SECRET' );
        return is_string( $value ) ? trim( $value ) : '';
    }

    public function env(): string {
        $value = $this->get( 'AIRALO_ENV' );
        $value = is_string( $value ) ? strtolower( trim( $value ) ) : self::ENV_PRODUCTION;
        return in_array( $value, [ self::ENV_PRODUCTION, self::ENV_SANDBOX ], true ) ? $value : self::ENV_PRODUCTION;
    }

    public function is_configured(): bool {
        return '' !== $this->client_id() && '' !== $this->client_secret();
    }

    public function cache_ttl_balance(): int {
        return (int) apply_filters( 'mpa_cache_ttl_balance', 5 * MINUTE_IN_SECONDS );
    }

    public function cache_ttl_devices(): int {
        return (int) apply_filters( 'mpa_cache_ttl_devices', DAY_IN_SECONDS );
    }

    public function cache_ttl_token(): int {
        return (int) apply_filters( 'mpa_cache_ttl_token', HOUR_IN_SECONDS );
    }

    public function cache_ttl_orders(): int {
        return (int) apply_filters( 'mpa_cache_ttl_orders', 5 * MINUTE_IN_SECONDS );
    }

    public function cache_ttl_orders_stale(): int {
        return (int) apply_filters( 'mpa_cache_ttl_orders_stale', DAY_IN_SECONDS );
    }

    public function request_timeout(): int {
        return (int) apply_filters( 'mpa_request_timeout', 15 );
    }

    /**
     * Shared secret used to verify incoming webhook requests via
     * `X-MPA-Signature: sha256=<hmac>`. If empty, the listener rejects
     * all incoming requests with 401.
     */
    public function webhook_secret(): string {
        $opt = get_option( 'mpa_settings', [] );
        $value = is_array( $opt ) && ! empty( $opt['webhook_secret'] ) ? (string) $opt['webhook_secret'] : '';
        return $value;
    }

    public function set_webhook_secret( string $secret ): void {
        $opt = get_option( 'mpa_settings', [] );
        if ( ! is_array( $opt ) ) {
            $opt = [];
        }
        $opt['webhook_secret'] = $secret;
        update_option( 'mpa_settings', $opt );
    }

    private function get( string $key ): mixed {
        if ( isset( $_ENV[ $key ] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            return $_ENV[ $key ];
        }
        if ( isset( $_SERVER[ $key ] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            return $_SERVER[ $key ];
        }
        $const = getenv( $key );
        if ( false !== $const ) {
            return $const;
        }
        $opt = get_option( 'mpa_settings', [] );
        if ( is_array( $opt ) && isset( $opt[ $key ] ) ) {
            return $opt[ $key ];
        }
        return null;
    }
}
