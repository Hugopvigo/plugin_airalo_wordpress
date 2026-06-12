<?php
/**
 * Airalo API client.
 *
 * Direct REST wrapper for the Airalo Partner API v2.
 * Does NOT depend on airalo/sdk to avoid namespace conflicts with the
 * official Airalo WordPress plugin when both are active on the same site.
 *
 * @package Hugo\MiPluginAiralo
 */

namespace Hugo\MiPluginAiralo\Api;

use Hugo\MiPluginAiralo\Env\Config;
use Hugo\MiPluginAiralo\Support\Logger;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Client {

    private const CACHE_KEY_BALANCE      = 'mpa_airalo_balance';
    private const CACHE_KEY_DEVICES      = 'mpa_airalo_devices';
    private const CACHE_KEY_COUNTRY_MAP  = 'mpa_airalo_country_map';
    private const CACHE_KEY_SIM_BY_ICCID = 'mpa_airalo_sim_';
    private const CACHE_KEY_ORDERS_FRESH = 'mpa_orders_';
    private const CACHE_KEY_ORDERS_STALE = 'mpa_orders_stale_';
    private const CACHE_KEY_PACKAGES     = 'mpa_airalo_packages';

    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger
    ) {
    }

    public function is_configured(): bool {
        return $this->config->is_configured();
    }

    // -------------------------------------------------------------------------
    // Balance
    // -------------------------------------------------------------------------

    public function get_balance(): array {
        $cached = get_transient( self::CACHE_KEY_BALANCE );
        if ( is_array( $cached ) ) {
            return $cached;
        }
        $data = $this->fetch_balance_via_rest();
        set_transient( self::CACHE_KEY_BALANCE, $data, $this->config->cache_ttl_balance() );
        return $data;
    }

    // -------------------------------------------------------------------------
    // Compatible devices
    // -------------------------------------------------------------------------

    public function get_compatible_devices( bool $force_refresh = false ): array {
        if ( ! $force_refresh ) {
            $cached = get_transient( self::CACHE_KEY_DEVICES );
            if ( is_array( $cached ) ) {
                return $cached;
            }
        }
        $data = $this->request( 'GET', '/compatible-devices' );
        $list = $data['data'] ?? [];
        set_transient( self::CACHE_KEY_DEVICES, $list, $this->config->cache_ttl_devices() );
        return $list;
    }

    // -------------------------------------------------------------------------
    // Packages
    // -------------------------------------------------------------------------

    /**
     * Returns all available packages, paginating through the API.
     * Result format: ['data' => [...packages...]]
     */
    public function get_sim_packages( bool $force_refresh = false ): array {
        if ( ! $force_refresh ) {
            $cached = get_transient( self::CACHE_KEY_PACKAGES );
            if ( is_array( $cached ) ) {
                return $cached;
            }
        }
        $all   = [];
        $page  = 1;
        $limit = 100;
        do {
            $resp = $this->request( 'GET', '/packages', [ 'page' => $page, 'limit' => $limit ] );
            $rows = $resp['data'] ?? [];
            if ( ! is_array( $rows ) || empty( $rows ) ) {
                break;
            }
            $all      = array_merge( $all, $rows );
            $last     = (int) ( $resp['meta']['last_page'] ?? $page );
            $page++;
        } while ( $page <= $last );

        $result = [ 'data' => $all ];
        set_transient( self::CACHE_KEY_PACKAGES, $result, DAY_IN_SECONDS );
        return $result;
    }

    // -------------------------------------------------------------------------
    // Orders
    // -------------------------------------------------------------------------

    public function get_orders( array $params = [], bool $bypass_cache = false ): array {
        if ( $bypass_cache ) {
            return $this->fetch_orders_via_rest( $params );
        }
        return $this->get_orders_cached( $params );
    }

    /**
     * Stale-while-revalidate cache for get_orders().
     */
    private function get_orders_cached( array $params ): array {
        $params_key = md5( wp_json_encode( $params ) );
        $fresh_key  = self::CACHE_KEY_ORDERS_FRESH . $params_key;
        $stale_key  = self::CACHE_KEY_ORDERS_STALE . $params_key;

        $fresh = get_transient( $fresh_key );
        if ( is_array( $fresh ) ) {
            return $fresh;
        }

        $stale = get_transient( $stale_key );
        if ( is_array( $stale ) ) {
            $this->schedule_orders_revalidation( $params );
            return $stale;
        }

        $data = $this->fetch_orders_via_rest( $params );
        set_transient( $fresh_key, $data, $this->config->cache_ttl_orders() );
        set_transient( $stale_key, $data, $this->config->cache_ttl_orders_stale() );
        return $data;
    }

    private function schedule_orders_revalidation( array $params ): void {
        $hook = 'mpa_revalidate_orders';
        if ( wp_next_scheduled( $hook, [ $params ] ) ) {
            return;
        }
        wp_schedule_single_event( time() + 30, $hook, [ $params ] );
    }

    public function revalidate_orders_cache( array $params ): void {
        $params_key = md5( wp_json_encode( $params ) );
        $fresh_key  = self::CACHE_KEY_ORDERS_FRESH . $params_key;
        $stale_key  = self::CACHE_KEY_ORDERS_STALE . $params_key;
        try {
            $data = $this->fetch_orders_via_rest( $params );
            set_transient( $fresh_key, $data, $this->config->cache_ttl_orders() );
            set_transient( $stale_key, $data, $this->config->cache_ttl_orders_stale() );
        } catch ( \Throwable $e ) {
            $this->logger->warning( 'Orders revalidation failed: ' . $e->getMessage() );
        }
    }

    /**
     * Fetches ALL orders by paginating, with dedup. Cached for cache_ttl_orders().
     */
    public function get_orders_paginated( int $max_pages = 40, int $per_page = 50 ): array {
        $cache_key = 'mpa_orders_paginated_' . md5( "{$max_pages}_{$per_page}" );
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $collected  = [];
        $seen_codes = [];

        for ( $page = 1; $page <= $max_pages; $page++ ) {
            $batch = $this->fetch_orders_via_rest( [
                'include' => 'sims',
                'page'    => $page,
                'limit'   => $per_page,
            ] );
            if ( ! is_array( $batch ) || empty( $batch ) ) {
                break;
            }

            $new_in_batch = 0;
            foreach ( (array) $batch as $o ) {
                $code = (string) ( $o['code'] ?? '' );
                if ( '' !== $code && isset( $seen_codes[ $code ] ) ) {
                    continue;
                }
                if ( '' !== $code ) {
                    $seen_codes[ $code ] = true;
                }
                $collected[] = $o;
                $new_in_batch++;
            }

            if ( 0 === $new_in_batch || count( $batch ) < $per_page ) {
                break;
            }
        }

        set_transient( $cache_key, $collected, $this->config->cache_ttl_orders() );
        return $collected;
    }

    public function place_order( string $package_id, int $quantity, ?string $description = null ): array {
        $body = [ 'package_id' => $package_id, 'quantity' => $quantity ];
        if ( null !== $description && '' !== $description ) {
            $body['description'] = $description;
        }
        return $this->request( 'POST', '/orders', $body );
    }

    // -------------------------------------------------------------------------
    // SIMs
    // -------------------------------------------------------------------------

    public function get_sim_usage( string $iccid ): array {
        return $this->request( 'GET', '/sims/' . rawurlencode( $iccid ) . '/usage' );
    }

    public function get_sim_usage_bulk( array $iccids ): array {
        if ( empty( $iccids ) ) {
            return [];
        }
        $result = [];
        foreach ( $iccids as $iccid ) {
            try {
                $result[ $iccid ] = $this->get_sim_usage( $iccid );
            } catch ( \Throwable $e ) {
                $this->logger->warning( 'Bulk usage failed for ' . $iccid . ': ' . $e->getMessage() );
                $result[ $iccid ] = [];
            }
        }
        return $result;
    }

    public function get_sim_instructions( string $iccid, string $language = 'en' ): array {
        return $this->request( 'GET', '/sims/' . rawurlencode( $iccid ) . '/instructions', [ 'language' => $language ] );
    }

    public function get_sim_topup_packages( string $iccid ): array {
        $resp = $this->request( 'GET', '/sims/' . rawurlencode( $iccid ) . '/topups' );
        return $resp['data'] ?? $resp;
    }

    public function get_sim_package_history( string $iccid ): array {
        $resp = $this->request( 'GET', '/sims/' . rawurlencode( $iccid ) . '/packages' );
        return $resp['data'] ?? $resp;
    }

    /**
     * Fetches a single SIM detail by ICCID. Cached 1h.
     */
    public function get_sim_by_iccid( string $iccid ): ?array {
        $iccid  = trim( $iccid );
        $key    = self::CACHE_KEY_SIM_BY_ICCID . md5( $iccid );
        $cached = get_transient( $key );
        if ( is_array( $cached ) ) {
            return $cached;
        }
        try {
            $body = $this->request( 'GET', '/sims/' . rawurlencode( $iccid ) );
            $data = $body['data'] ?? null;
            if ( is_array( $data ) ) {
                set_transient( $key, $data, HOUR_IN_SECONDS );
                return $data;
            }
        } catch ( \Throwable $e ) {
            $this->logger->warning( 'get_sim_by_iccid failed: ' . $e->getMessage() );
        }
        return null;
    }

    /**
     * Searches SIMs by ICCID using filter[iccid] param.
     */
    public function search_sims_by_iccid( string $iccid ): array {
        $iccid = trim( $iccid );
        if ( '' === $iccid ) {
            return [];
        }
        $resp = $this->get_sims_list( [ 'filter[iccid]' => $iccid ], '', 1, 5 );
        $rows = $resp['data'] ?? [];
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Hits GET /v2/sims with given filters and include set.
     */
    public function get_sims_list( array $filters = [], string $include = '', int $page = 1, int $limit = 50 ): array {
        $query = array_merge( [ 'page' => $page, 'limit' => $limit ], $filters );
        if ( '' !== $include ) {
            $query['include'] = $include;
        }
        try {
            return $this->request( 'GET', '/sims', $query );
        } catch ( Exception $e ) {
            $this->logger->warning( 'get_sims_list failed: ' . $e->getMessage() );
            return [];
        }
    }

    /**
     * Paginates GET /v2/sims until all sims matching filters are collected. Cached 5 min.
     */
    public function get_sims_paginated( array $filters = [], int $max_pages = 30, int $per_page = 100 ): array {
        $cache_key = 'mpa_sims_list_' . md5( wp_json_encode( $filters ) );
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $collected = [];
        for ( $page = 1; $page <= $max_pages; $page++ ) {
            $resp = $this->get_sims_list( $filters, '', $page, $per_page );
            $rows = $resp['data'] ?? [];
            if ( ! is_array( $rows ) || empty( $rows ) ) {
                break;
            }
            $collected = array_merge( $collected, $rows );
            $last_page = (int) ( $resp['meta']['last_page'] ?? $page );
            if ( $page >= $last_page || count( $rows ) < $per_page ) {
                break;
            }
        }

        set_transient( $cache_key, $collected, 5 * MINUTE_IN_SECONDS );
        return $collected;
    }

    /**
     * Builds ICCID-to-order-data map. Cached 5 min.
     */
    public function build_iccid_order_map(): array {
        $cache_key = 'mpa_iccid_order_map';
        $cached    = get_transient( $cache_key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $orders = $this->get_orders_paginated();
        $map    = [];

        foreach ( $orders as $o ) {
            $order_data = [
                'package_id'  => (string) ( $o['package_id'] ?? '' ),
                'package'     => (string) ( $o['package'] ?? '' ),
                'description' => (string) ( $o['description'] ?? '' ),
                'code'        => (string) ( $o['code'] ?? '' ),
                'created_at'  => (string) ( $o['created_at'] ?? '' ),
            ];
            foreach ( (array) ( $o['sims'] ?? [] ) as $s ) {
                $iccid = (string) ( $s['iccid'] ?? '' );
                if ( '' !== $iccid ) {
                    $map[ $iccid ] = $order_data;
                }
            }
        }

        set_transient( $cache_key, $map, 5 * MINUTE_IN_SECONDS );
        return $map;
    }

    // -------------------------------------------------------------------------
    // eSIM share / assign
    // -------------------------------------------------------------------------

    public function assign_esim_user( string $iccid, string $name, string $email, string $sharing_option = 'link' ): array {
        $valid_options = [ 'link', 'qrcode' ];
        if ( ! in_array( $sharing_option, $valid_options, true ) ) {
            $sharing_option = 'link';
        }
        $body = [ 'to_email' => $email, 'sharing_option' => [ $sharing_option ] ];
        if ( '' !== $name ) {
            $body['name'] = $name;
        }
        $result = $this->request( 'POST', '/sims/' . rawurlencode( $iccid ) . '/share', $body, true );
        delete_transient( self::CACHE_KEY_SIM_BY_ICCID . md5( $iccid ) );
        return $result;
    }

    public function share_esim( string $iccid, string $email, string $sharing_option = 'link', ?string $copy_address = null ): array {
        $body = [ 'to_email' => $email, 'sharing_option' => [ $sharing_option ] ];
        if ( null !== $copy_address && '' !== $copy_address ) {
            $body['copy_address'] = $copy_address;
        }
        return $this->request( 'POST', '/sims/' . rawurlencode( $iccid ) . '/share', $body, true );
    }

    // -------------------------------------------------------------------------
    // Topups
    // -------------------------------------------------------------------------

    public function topup( string $package_id, string $iccid, ?string $description = null ): array {
        $body = [ 'package_id' => $package_id, 'iccid' => $iccid ];
        if ( null !== $description && '' !== $description ) {
            $body['description'] = $description;
        }
        return $this->request( 'POST', '/topups', $body, true );
    }

    // -------------------------------------------------------------------------
    // Refunds
    // -------------------------------------------------------------------------

    public function refund_order( array $iccids, string $reason, string $notes = '' ): array {
        return $this->refund_via_rest( $iccids, $reason, $notes );
    }

    // -------------------------------------------------------------------------
    // Package country map
    // -------------------------------------------------------------------------

    public function get_package_country_map( bool $force_refresh = false ): array {
        if ( ! $force_refresh ) {
            $cached = get_transient( self::CACHE_KEY_COUNTRY_MAP );
            if ( is_array( $cached ) ) {
                return $cached;
            }
        }

        $map = [];
        try {
            $result = $this->get_sim_packages( $force_refresh );
            $rows   = $result['data'] ?? ( is_array( $result ) ? $result : [] );

            foreach ( (array) $rows as $pkg ) {
                if ( ! is_array( $pkg ) ) {
                    continue;
                }
                $pid = (string) ( $pkg['package_id'] ?? $pkg['slug'] ?? '' );
                if ( '' === $pid ) {
                    continue;
                }
                $countries = $pkg['countries'] ?? null;
                if ( is_array( $countries ) && ! empty( $countries ) ) {
                    $map[ $pid ] = strtoupper( (string) reset( $countries ) );
                    continue;
                }
                if ( ! empty( $pkg['country'] ) ) {
                    $map[ $pid ] = strtoupper( (string) $pkg['country'] );
                }
            }
        } catch ( \Throwable $e ) {
            $this->logger->warning( 'get_package_country_map failed: ' . $e->getMessage() );
        }

        set_transient( self::CACHE_KEY_COUNTRY_MAP, $map, DAY_IN_SECONDS );
        return $map;
    }

    public function get_country_for_package( string $package_id ): string {
        if ( '' === $package_id ) {
            return '';
        }
        $map = $this->get_package_country_map();
        return strtoupper( (string) ( $map[ $package_id ] ?? '' ) );
    }

    // -------------------------------------------------------------------------
    // Core HTTP helpers
    // -------------------------------------------------------------------------

    /**
     * Makes an authenticated request to the Airalo API.
     *
     * @param string              $method       GET or POST
     * @param string              $path         e.g. '/orders'
     * @param array<string,mixed> $params       Query params (GET) or body (POST)
     * @param bool                $json_body    Encode POST body as JSON (default false = form)
     * @return array<string,mixed>
     * @throws Exception
     */
    private function request( string $method, string $path, array $params = [], bool $json_body = false ): array {
        if ( ! $this->is_configured() ) {
            throw new Exception( 'Credenciales Airalo no configuradas', 0 );
        }

        $token = $this->get_token();
        $url   = MPA_API_BASE . $path;
        $args  = [
            'headers' => [
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ],
            'timeout' => $this->config->request_timeout(),
        ];

        if ( 'GET' === strtoupper( $method ) ) {
            if ( ! empty( $params ) ) {
                $args['body'] = $params;
            }
            $response = wp_remote_get( $url, $args );
        } else {
            if ( $json_body ) {
                $args['headers']['Content-Type'] = 'application/json';
                $args['body']                    = wp_json_encode( $params );
            } else {
                $args['headers']['Content-Type'] = 'application/json';
                $args['body']                    = wp_json_encode( $params );
            }
            $response = wp_remote_post( $url, $args );
        }

        if ( is_wp_error( $response ) ) {
            throw new Exception( $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true ) ?: [];

        if ( $code >= 400 ) {
            throw new Exception( $body['meta']['message'] ?? "API error on {$method} {$path}", $code, $body );
        }

        return $body;
    }

    private function fetch_orders_via_rest( array $params ): array {
        $token    = $this->get_token();
        $response = wp_remote_get( MPA_API_BASE . '/orders', [
            'headers' => [
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ],
            'body'    => $params,
            'timeout' => $this->config->request_timeout(),
        ] );
        return $this->parse_list( $response, 'data' );
    }

    private function refund_via_rest( array $iccids, string $reason, string $notes = '' ): array {
        $token = $this->get_token();
        $body  = [ 'iccids' => $iccids, 'reason' => $reason ];
        if ( '' !== $notes ) {
            $body['notes'] = $notes;
        }
        $response = wp_remote_post( MPA_API_BASE . '/refund', [
            'headers' => [
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'text/plain',
            ],
            'body'    => wp_json_encode( $body ),
            'timeout' => $this->config->request_timeout(),
        ] );
        if ( is_wp_error( $response ) ) {
            throw new Exception( $response->get_error_message() );
        }
        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true ) ?: [];
        if ( $code >= 400 ) {
            throw new Exception( $data['meta']['message'] ?? 'Refund failed', $code, $data );
        }
        return $data;
    }

    private function fetch_balance_via_rest(): array {
        $token    = $this->get_token();
        $response = wp_remote_get( MPA_API_BASE . '/balance', [
            'headers' => [
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ],
            'timeout' => $this->config->request_timeout(),
        ] );
        return $this->parse_single( $response, 'data' );
    }

    private function get_token(): string {
        $cached = get_transient( 'mpa_airalo_token' );
        if ( is_string( $cached ) && '' !== $cached ) {
            return $cached;
        }
        $response = wp_remote_post( MPA_API_BASE . '/token', [
            'headers' => [ 'Accept' => 'application/json' ],
            'body'    => [
                'client_id'     => $this->config->client_id(),
                'client_secret' => $this->config->client_secret(),
                'grant_type'    => 'client_credentials',
            ],
            'timeout' => $this->config->request_timeout(),
        ] );
        if ( is_wp_error( $response ) ) {
            throw new Exception( $response->get_error_message() );
        }
        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true ) ?: [];
        if ( $code >= 400 || empty( $body['data']['access_token'] ) ) {
            throw new Exception( $body['meta']['message'] ?? 'No se pudo obtener el token', $code, $body );
        }
        $expires = isset( $body['data']['expires_in'] ) ? max( (int) $body['data']['expires_in'] - 3600, 0 ) : $this->config->cache_ttl_token();
        set_transient( 'mpa_airalo_token', (string) $body['data']['access_token'], $expires );
        return (string) $body['data']['access_token'];
    }

    private function parse_list( $response, string $list_key ): array {
        if ( is_wp_error( $response ) ) {
            throw new Exception( $response->get_error_message() );
        }
        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true ) ?: [];
        if ( $code >= 400 ) {
            throw new Exception( $body['meta']['message'] ?? 'API error', $code, $body );
        }
        return $body[ $list_key ] ?? [];
    }

    private function parse_single( $response, string $key ): array {
        if ( is_wp_error( $response ) ) {
            throw new Exception( $response->get_error_message() );
        }
        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true ) ?: [];
        if ( $code >= 400 ) {
            throw new Exception( $body['meta']['message'] ?? 'API error', $code, $body );
        }
        return $body;
    }
}
