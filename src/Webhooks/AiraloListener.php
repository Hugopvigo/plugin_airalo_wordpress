<?php
/**
 * Webhook listener for Airalo notifications.
 *
 * Registers ?mpa_webhook=1 (or REST: /wp-json/mpa/v1/webhook) as a public endpoint
 * that accepts the JSON payload Airalo sends when data is low / expired / etc.
 *
 * Payload (per Airalo docs):
 *   {
 *     "event": "order.status.changed",
 *     "data": { "iccid": "...", "status": "low_data", ... }
 *   }
 *
 * Hardening (vs. v2.0.0):
 *  - HMAC SHA-256 signature validation (X-MPA-Signature: sha256=<hex>), shared
 *    secret configured in Airalo > Ajustes.
 *  - Per-IP rate limit (20 req / 5 min) backed by transients.
 *  - Event whitelist (rejects unknown events without touching the DB).
 *  - Hard cap on payload size (8 KB) to avoid log spam via notes.
 *
 * @package Hugo\MiPluginAiralo
 */

namespace Hugo\MiPluginAiralo\Webhooks;

use Hugo\MiPluginAiralo\Env\Config;
use Hugo\MiPluginAiralo\Support\Logger;
use Hugo\MiPluginAiralo\Integrations\WooCommerce\OrderLinker;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class AiraloListener {

    public const QUERY_VAR        = 'mpa_webhook';
    public const REST_NS          = 'mpa/v1';
    public const REST_ROUTE       = '/webhook';
    public const SIG_HEADER       = 'x-mpa-signature';
    public const MAX_PAYLOAD_SIZE = 8192;
    public const RATE_LIMIT       = 20;
    public const RATE_WINDOW      = 5 * MINUTE_IN_SECONDS;

    private const ALLOWED_EVENTS = [
        'order.status.changed',
        'order.completed',
        'sim.activated',
        'sim.expired',
        'sim.low_data',
        'sim.usage.threshold',
    ];

    public function __construct(
        private readonly Logger $logger,
        private readonly Config $config
    ) {
    }

    public function register(): void {
        add_action( 'init', [ $this, 'maybe_handle_query' ] );
        add_action( 'rest_api_init', [ $this, 'register_rest_route' ] );
    }

    public function register_rest_route(): void {
        register_rest_route( self::REST_NS, self::REST_ROUTE, [
            'methods'             => 'POST',
            'permission_callback' => [ $this, 'rest_permission' ],
            'callback'            => [ $this, 'handle_payload' ],
        ] );
    }

    /**
     * REST permission: deny everything until a webhook secret is configured.
     * This replaces the v2.0.0 `__return_true` that left the endpoint public.
     */
    public function rest_permission( \WP_REST_Request $request ): bool|\WP_Error {
        if ( '' === $this->config->webhook_secret() ) {
            $this->logger->warning( 'Webhook rejected: no secret configured' );
            return new \WP_Error( 'mpa_webhook_unconfigured', 'Webhook no configurado.', [ 'status' => 401 ] );
        }
        if ( ! $this->verify_signature( $request->get_body(), $request->get_header( self::SIG_HEADER ) ) ) {
            $this->logger->warning( 'Webhook rejected: bad signature' );
            return new \WP_Error( 'mpa_webhook_signature', 'Firma inválida.', [ 'status' => 401 ] );
        }
        if ( ! $this->check_rate_limit( $this->client_ip() ) ) {
            return new \WP_Error( 'mpa_webhook_ratelimit', 'Demasiadas peticiones.', [ 'status' => 429 ] );
        }
        return true;
    }

    public function maybe_handle_query(): void {
        if ( empty( $_GET[ self::QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }

        if ( '' === $this->config->webhook_secret() ) {
            status_header( 401 );
            echo 'webhook unconfigured';
            exit;
        }

        $raw = file_get_contents( 'php://input' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
        if ( ! is_string( $raw ) ) {
            status_header( 400 );
            exit;
        }
        if ( ! $this->verify_signature( $raw, isset( $_SERVER['HTTP_X_MPA_SIGNATURE'] ) ? wp_unslash( $_SERVER['HTTP_X_MPA_SIGNATURE'] ) : '' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            status_header( 401 );
            echo 'bad signature';
            exit;
        }
        if ( ! $this->check_rate_limit( $this->client_ip() ) ) {
            status_header( 429 );
            exit;
        }

        $this->handle_request( $raw );
    }

    public function handle_payload( \WP_REST_Request $request ): \WP_REST_Response {
        $payload = $request->get_json_params();
        $this->process( is_array( $payload ) ? $payload : [] );
        return new \WP_REST_Response( [ 'ok' => true ], 200 );
    }

    private function handle_request( string $raw ): void {
        if ( '' === $raw ) {
            status_header( 400 );
            echo 'empty body';
            exit;
        }
        if ( strlen( $raw ) > self::MAX_PAYLOAD_SIZE ) {
            status_header( 413 );
            echo 'payload too large';
            exit;
        }
        $payload = json_decode( $raw, true );
        if ( ! is_array( $payload ) ) {
            status_header( 400 );
            echo 'invalid json';
            exit;
        }
        $this->process( $payload );
        status_header( 200 );
        echo 'ok';
        exit;
    }

    private function process( array $payload ): void {
        $event = isset( $payload['event'] ) ? sanitize_key( (string) $payload['event'] ) : '';
        $data  = isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : [];
        $iccid = isset( $data['iccid'] ) ? sanitize_text_field( (string) $data['iccid'] ) : '';

        $this->logger->info( 'Airalo webhook received', [
            'event'    => $event,
            'iccid'    => $iccid,
            'ip'       => $this->client_ip(),
            'has_data' => ! empty( $data ),
        ] );

        if ( '' === $event || ! in_array( $event, self::ALLOWED_EVENTS, true ) ) {
            $this->logger->warning( 'Webhook rejected: unknown event', [ 'event' => $event ] );
            return;
        }

        if ( '' === $iccid ) {
            return;
        }

        $order = OrderLinker::find_wc_order_by_iccid( $iccid )['order'] ?? null;
        if ( ! $order ) {
            $this->logger->info( 'No WC order matches webhook ICCID', [ 'iccid' => $iccid ] );
            return;
        }

        $note = sprintf(
            'Airalo webhook: %s — %s',
            $event,
            wp_json_encode( $data )
        );
        $order->add_order_note( $note );
        $order->save();

        do_action( 'mpa_webhook_received', $event, $data, $order );
    }

    private function verify_signature( string $body, string $header ): bool {
        $secret = $this->config->webhook_secret();
        if ( '' === $secret ) {
            return false;
        }
        if ( ! is_string( $header ) || '' === $header ) {
            return false;
        }
        $provided = preg_replace( '/^sha256=/i', '', trim( $header ) );
        $expected = hash_hmac( 'sha256', $body, $secret );
        return is_string( $provided ) && hash_equals( $expected, $provided );
    }

    private function check_rate_limit( string $ip ): bool {
        if ( '' === $ip || 'UNKNOWN' === $ip ) {
            return true;
        }
        $key   = 'mpa_wh_rl_' . md5( $ip );
        $count = (int) get_transient( $key );
        if ( $count >= self::RATE_LIMIT ) {
            return false;
        }
        set_transient( $key, $count + 1, self::RATE_WINDOW );
        return true;
    }

    private function client_ip(): string {
        $candidates = [
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        ];
        foreach ( $candidates as $key ) {
            if ( ! empty( $_SERVER[ $key ] ) ) {
                $ip = trim( explode( ',', (string) $_SERVER[ $key ] )[0] );
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }
        return 'UNKNOWN';
    }
}
