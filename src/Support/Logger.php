<?php
/**
 * Logger.
 *
 * @package Hugo\MiPluginAiralo
 */

namespace Hugo\MiPluginAiralo\Support;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Logger {

    private const KEY_BLACKLIST = [
        'AIRALO_CLIENT_SECRET',
        'client_secret',
        'authorization',
        'password',
        'token',
    ];

    public function info( string $message, array $context = [] ): void {
        $this->log( 'INFO', $message, $context );
    }

    public function warning( string $message, array $context = [] ): void {
        $this->log( 'WARNING', $message, $context );
    }

    public function error( string $message, array $context = [] ): void {
        $this->log( 'ERROR', $message, $context );
    }

    private function log( string $level, string $message, array $context ): void {
        $sanitized = $this->sanitize_context( $context );
        $line      = sprintf( '[MPA][%s] %s %s', $level, $message, $sanitized ? wp_json_encode( $sanitized ) : '' );
        error_log( $line );
    }

    private function sanitize_context( array $context ): array {
        array_walk_recursive( $context, function ( &$value, $key ) {
            $lk = is_string( $key ) ? strtolower( $key ) : '';
            foreach ( self::KEY_BLACKLIST as $needle ) {
                if ( false !== strpos( $lk, strtolower( $needle ) ) ) {
                    $value = '***';
                    return;
                }
            }
            if ( is_string( $value ) && strlen( $value ) > 500 ) {
                $value = substr( $value, 0, 500 ) . '...';
            }
        } );
        return $context;
    }
}
