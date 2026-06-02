<?php
/**
 * API exception.
 *
 * @package Hugo\MiPluginAiralo
 */

namespace Hugo\MiPluginAiralo\Api;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Exception extends \RuntimeException {

    public function __construct(
        string $message,
        private readonly int $status_code = 0,
        private readonly array $payload = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct( $message, 0, $previous );
    }

    public function status_code(): int {
        return $this->status_code;
    }

    public function payload(): array {
        return $this->payload;
    }

    public function is_auth_error(): bool {
        return 401 === $this->status_code || 403 === $this->status_code;
    }

    public function is_rate_limited(): bool {
        return 429 === $this->status_code;
    }
}
