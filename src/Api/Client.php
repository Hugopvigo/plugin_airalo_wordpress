<?php
/**
 * Airalo API client.
 *
 * Thin wrapper over the official airalo/sdk that:
 *  - lazy-initialises the SDK with the configured credentials;
 *  - exposes typed helpers for the endpoints we use in the backoffice;
 *  - normalises exceptions;
 *  - caches read-only responses (devices, balance) in transients.
 *
 * @package Hugo\MiPluginAiralo
 */

namespace Hugo\MiPluginAiralo\Api;

use Airalo\Airalo;
use Airalo\AiraloStatic;
use Airalo\Exceptions\AiraloException;
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

    private ?Airalo $sdk = null;
    private bool $sdk_initialised = false;

    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger
    ) {
    }

    public function is_configured(): bool {
        return $this->config->is_configured();
    }

    public function get_balance(): array {
        $cached = get_transient( self::CACHE_KEY_BALANCE );
        if ( is_array( $cached ) ) {
            return $cached;
        }
        $data = $this->fetch_balance_via_rest();
        set_transient( self::CACHE_KEY_BALANCE, $data, $this->config->cache_ttl_balance() );
        return $data;
    }

    public function get_compatible_devices( bool $force_refresh = false ): array {
        if ( ! $force_refresh ) {
            $cached = get_transient( self::CACHE_KEY_DEVICES );
            if ( is_array( $cached ) ) {
                return $cached;
            }
        }
        $data = $this->call( fn() => $this->fetch_devices_via_rest() );
        set_transient( self::CACHE_KEY_DEVICES, $data, $this->config->cache_ttl_devices() );
        return $data;
    }

    public function get_orders( array $params = [] ): array {
        return $this->call( fn() => $this->fetch_orders_via_rest( $params ) );
    }

    /**
     * Fetches ALL orders by paginating through the Airalo /v2/orders endpoint.
     *
     * The SDK / REST endpoint caps `limit` to 50/page, so for the eSIMs index
     * (which has to include every ICCID ever sold to make search work) we loop
     * until an empty page is returned or a hard cap is hit.
     *
     * @return array<int, array<string,mixed>>
     */
    public function get_orders_paginated( int $max_pages = 40, int $per_page = 50 ): array {
        $collected = [];
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

        return $collected;
    }

    /**
     * Builds an ICCID-to-order-data map from the /v2/orders endpoint.
     *
     * Used to enrich the eSIMs list (which no longer returns order-level
     * fields via `include`) with package, description, and order code.
     *
     * @return array<string,array{package_id:string,package:string,description:string,code:string,created_at:string}>
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
            $iccids = [];
            foreach ( (array) ( $o['sims'] ?? [] ) as $s ) {
                $iccid = (string) ( $s['iccid'] ?? '' );
                if ( '' !== $iccid ) {
                    $iccids[] = $iccid;
                }
            }
            if ( empty( $iccids ) ) {
                continue;
            }
            foreach ( $iccids as $iccid ) {
                $map[ $iccid ] = $order_data;
            }
        }

        set_transient( $cache_key, $map, 5 * MINUTE_IN_SECONDS );
        return $map;
    }

    public function get_sim_usage( string $iccid ): array {
        return $this->call( fn() => $this->sdk()->simUsage( $iccid ) );
    }

    public function get_sim_usage_bulk( array $iccids ): array {
        if ( empty( $iccids ) ) {
            return [];
        }
        return $this->call( fn() => $this->sdk()->simUsageBulk( $iccids ) );
    }

    public function get_sim_instructions( string $iccid, string $language = 'en' ): array {
        return $this->call( fn() => $this->sdk()->getSimInstructions( $iccid, $language ) );
    }

    /**
     * Fetches a single SIM detail by ICCID via the REST endpoint.
     * Result is cached for 1h to keep detail-page navigation snappy.
     *
     * @return array<string,mixed>|null
     */
    public function get_sim_by_iccid( string $iccid ): ?array {
        $iccid  = trim( $iccid );
        $key    = self::CACHE_KEY_SIM_BY_ICCID . md5( $iccid );
        $cached = get_transient( $key );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        try {
            $token    = $this->get_token();
            $response = wp_remote_get( MPA_API_BASE . '/sims/' . rawurlencode( $iccid ), [
                'headers' => [
                    'Accept'        => 'application/json',
                    'Authorization' => 'Bearer ' . $token,
                ],
                'timeout' => $this->config->request_timeout(),
            ] );
            if ( is_wp_error( $response ) ) {
                return null;
            }
            $code = wp_remote_retrieve_response_code( $response );
            if ( $code >= 400 ) {
                return null;
            }
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            $data = is_array( $body ) ? ( $body['data'] ?? null ) : null;
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
     * Direct ICCID lookup using the `filter[iccid]` query parameter.
     *
     * Why we need this on top of the single-sim endpoint: the
     * `GET /v2/sims/{iccid}` endpoint can be slow / 404 for recycled
     * eSIMs on some accounts, whereas `GET /v2/sims?filter[iccid]=…`
     * always returns the row (with `recycled:true` when applicable).
     *
     * @return array<int,array<string,mixed>>  Empty array if no match.
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
     * Hits `GET /v2/sims` (the eSIM inventory endpoint) with the given
     * filters and optional include set. Returns the flat list of sims +
     * the pagination meta.
     *
     * `include=order,order.status,order.user,share` is what makes the
     * "eSIM User" name/email and the order status show up in the response.
     *
     * @param array<string,mixed> $filters  e.g. ['filter[iccid]' => '8944…']
     * @return array{data?: array<int,array<string,mixed>>, meta?: array<string,mixed>}
     */
    public function get_sims_list( array $filters = [], string $include = '', int $page = 1, int $limit = 50 ): array {
        $query = array_merge( [
            'page'    => $page,
            'limit'   => $limit,
        ], $filters );
        if ( '' !== $include ) {
            $query['include'] = $include;
        }

        try {
            $token    = $this->get_token();
            $response = wp_remote_get( MPA_API_BASE . '/sims', [
                'headers' => [
                    'Accept'        => 'application/json',
                    'Authorization' => 'Bearer ' . $token,
                ],
                'body'    => $query,
                'timeout' => $this->config->request_timeout(),
            ] );
            if ( is_wp_error( $response ) ) {
                throw new Exception( $response->get_error_message() );
            }
            $code = wp_remote_retrieve_response_code( $response );
            $body = json_decode( wp_remote_retrieve_body( $response ), true ) ?: [];
            if ( $code >= 400 ) {
                throw new Exception( $body['meta']['message'] ?? 'GET /v2/sims failed', $code, $body );
            }
            return $body;
        } catch ( Exception $e ) {
            $this->logger->warning( 'get_sims_list failed: ' . $e->getMessage() );
            return [];
        }
    }

    /**
     * Paginates `GET /v2/sims` until it has collected every sim matching the
     * filters, or the hard cap is reached. Used for the eSIMs index + ICCID
     * search. Cached 5 min under a per-filter key.
     *
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
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
     * Assigns an "eSIM User" (Full Name + email) to a SIM via Airalo eSIM Cloud.
     *
     * Internally wraps `POST /v2/sims/{iccid}/share` (the eSIM Cloud
     * assign flow). The customer receives the eSIM via link or QR, and
     * Airalo records the user.name / user.email in the dashboard.
     *
     * If `$name` is empty we still call the endpoint (email-only is valid
     * per the dashboard form), but Airalo will only show the email.
     *
     * @return array<string,mixed>
     */
    public function assign_esim_user( string $iccid, string $name, string $email, string $sharing_option = 'link' ): array {
        $valid_options = [ 'link', 'qrcode' ];
        if ( ! in_array( $sharing_option, $valid_options, true ) ) {
            $sharing_option = 'link';
        }

        $body = [
            'to_email'       => $email,
            'sharing_option' => [ $sharing_option ],
        ];
        if ( '' !== $name ) {
            // eSIM Cloud / Airalo reads `name` as the full name shown as "eSIM User".
            $body['name'] = $name;
        }

        $token    = $this->get_token();
        $response = wp_remote_post( MPA_API_BASE . '/sims/' . rawurlencode( $iccid ) . '/share', [
            'headers' => [
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
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
            throw new Exception( $data['meta']['message'] ?? 'Assign eSIM user failed', $code, $data );
        }

        // Bust the per-iccid cache so the detail page sees the new user on reload.
        delete_transient( self::CACHE_KEY_SIM_BY_ICCID . md5( $iccid ) );

        return $data;
    }

    public function get_sim_topup_packages( string $iccid ): array {
        return $this->call( fn() => $this->sdk()->getSimTopups( $iccid ) );
    }

    public function get_sim_package_history( string $iccid ): array {
        return $this->call( fn() => $this->sdk()->getSimPackageHistory( $iccid ) );
    }

    public function refund_order( array $iccids, string $reason, string $notes = '' ): array {
        return $this->refund_via_rest( $iccids, $reason, $notes );
    }

    /**
     * Shares an eSIM with a customer via Airalo eSIM Cloud.
     *
     * Wraps `POST /v2/sims/{iccid}/share` which sends the eSIM to the given
     * email using the configured sharing option (link, qrcode, etc.).
     *
     * @return array<string,mixed>
     */
    public function share_esim( string $iccid, string $email, string $sharing_option = 'link', ?string $copy_address = null ): array {
        $body = [
            'to_email'        => $email,
            'sharing_option'  => [ $sharing_option ],
        ];
        if ( null !== $copy_address && '' !== $copy_address ) {
            $body['copy_address'] = $copy_address;
        }

        $token    = $this->get_token();
        $response = wp_remote_post( MPA_API_BASE . '/sims/' . rawurlencode( $iccid ) . '/share', [
            'headers' => [
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
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
            throw new Exception( $data['meta']['message'] ?? 'Share eSIM failed', $code, $data );
        }
        return $data;
    }

    /**
     * Returns a `package_id => country_code` map built from the package catalog.
     *
     * Airalo's `/v2/orders` response does NOT include the country for each
     * order; the only way to display "Spain - 5 GB - 30 Days" is to look up
     * the country in the package catalog. The map is cached for 24h.
     *
     * @return array<string,string>  e.g. ['meraki-mobile-7days-1gb' => 'ES', ...]
     */
    public function get_package_country_map( bool $force_refresh = false ): array {
        if ( ! $force_refresh ) {
            $cached = get_transient( self::CACHE_KEY_COUNTRY_MAP );
            if ( is_array( $cached ) ) {
                return $cached;
            }
        }

        $map = [];
        try {
            $packages = $this->sdk()->getAllPackages( true );
            if ( $packages && method_exists( $packages, 'toArray' ) ) {
                $arr = $packages->toArray();
            } elseif ( is_object( $packages ) ) {
                $arr = json_decode( (string) $packages, true ) ?: [];
            } else {
                $arr = is_array( $packages ) ? $packages : [];
            }

            $rows = $arr['data'] ?? $arr;
            if ( ! is_array( $rows ) ) {
                $rows = [];
            }

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
                    $code = strtoupper( (string) reset( $countries ) );
                    $map[ $pid ] = $code;
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

    /**
     * Convenience: country code (e.g. "ES") for a given Airalo package_id.
     * Returns empty string if unknown.
     */
    public function get_country_for_package( string $package_id ): string {
        if ( '' === $package_id ) {
            return '';
        }
        $map = $this->get_package_country_map();
        return strtoupper( (string) ( $map[ $package_id ] ?? '' ) );
    }

    public function topup( string $package_id, string $iccid, ?string $description = null ): array {
        return $this->call( fn() => $this->sdk()->topup( $package_id, $iccid, $description ) );
    }

    public function get_sim_packages( bool $force_refresh = false ): array {
        return $this->call( fn() => $this->sdk()->getSimPackages( $force_refresh ) );
    }

    public function place_order( string $package_id, int $quantity, ?string $description = null ): array {
        return $this->call( fn() => $this->sdk()->order( $package_id, $quantity, $description ) );
    }

    private function sdk(): Airalo {
        if ( null === $this->sdk ) {
            $this->sdk = new Airalo( [
                'client_id'     => $this->config->client_id(),
                'client_secret' => $this->config->client_secret(),
                'env'           => $this->config->env(),
            ] );
            $this->sdk_initialised = true;
        }
        return $this->sdk;
    }

    /**
     * @template T
     * @param callable():T $fn
     * @return T
     */
    private function call( callable $fn ) {
        if ( ! $this->is_configured() ) {
            throw new Exception( 'Credenciales Airalo no configuradas', 0 );
        }
        try {
            $result = $fn();
            if ( is_object( $result ) && method_exists( $result, 'toArray' ) ) {
                return $result->toArray();
            }
            if ( is_object( $result ) ) {
                return json_decode( (string) $result, true ) ?? [];
            }
            return is_array( $result ) ? $result : [];
        } catch ( AiraloException $e ) {
            $this->logger->error( 'Airalo SDK error: ' . $e->getMessage(), [ 'trace' => $e->getTraceAsString() ] );
            throw new Exception( $e->getMessage(), 0, [], $e );
        } catch ( \Throwable $e ) {
            $this->logger->error( 'Airalo client error: ' . $e->getMessage() );
            throw new Exception( $e->getMessage(), 0, [], $e );
        }
    }

    private function fetch_devices_via_rest(): array {
        $token = $this->get_token();
        $response = wp_remote_get( MPA_API_BASE . '/compatible-devices', [
            'headers' => [
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ],
            'timeout' => $this->config->request_timeout(),
        ] );
        return $this->parse_list( $response, 'data' );
    }

    private function fetch_orders_via_rest( array $params ): array {
        $token = $this->get_token();
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
        $body = [
            'iccids' => $iccids,
            'reason' => $reason,
        ];
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
        $body  = json_decode( wp_remote_retrieve_body( $response ), true ) ?: [];
        if ( $code >= 400 ) {
            throw new Exception( $body['meta']['message'] ?? 'Refund failed', $code, $body );
        }
        return $body;
    }

    private function fetch_balance_via_rest(): array {
        $token = $this->get_token();
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
