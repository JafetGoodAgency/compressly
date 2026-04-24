<?php
/**
 * Typed exception raised by the optimization pipeline.
 *
 * Carries a `kind` constant so callers can branch on failure class
 * (invalid API key, quota exceeded, network, etc.) without parsing
 * exception messages. Lets the orchestrator translate SDK-level errors
 * into user-visible admin notices and log rows.
 *
 * @package GoodAgency\Compressly
 */

declare(strict_types=1);

namespace GoodAgency\Compressly\Optimization;

use RuntimeException;
use Throwable;

final class OptimizationException extends RuntimeException {

    public const KIND_API_KEY_INVALID = 'api_key_invalid';
    public const KIND_QUOTA_EXCEEDED  = 'quota_exceeded';
    public const KIND_NETWORK         = 'network';
    public const KIND_TOO_LARGE       = 'too_large';
    public const KIND_CORRUPT_RESULT  = 'corrupt_result';
    public const KIND_IO              = 'io';
    public const KIND_UNKNOWN         = 'unknown';

    private string $kind;

    public function __construct( string $message, string $kind = self::KIND_UNKNOWN, ?Throwable $previous = null ) {
        parent::__construct( $message, 0, $previous );
        $this->kind = $kind;
    }

    public function kind(): string {
        return $this->kind;
    }

    public function is_retryable(): bool {
        return $this->kind === self::KIND_NETWORK;
    }

    public static function apiKeyInvalid( string $message, ?Throwable $previous = null ): self {
        return new self( $message, self::KIND_API_KEY_INVALID, $previous );
    }

    public static function quotaExceeded( string $message, ?Throwable $previous = null ): self {
        return new self( $message, self::KIND_QUOTA_EXCEEDED, $previous );
    }

    public static function network( string $message, ?Throwable $previous = null ): self {
        return new self( $message, self::KIND_NETWORK, $previous );
    }

    public static function tooLarge( string $message ): self {
        return new self( $message, self::KIND_TOO_LARGE );
    }

    public static function corruptResult( string $message ): self {
        return new self( $message, self::KIND_CORRUPT_RESULT );
    }

    public static function io( string $message, ?Throwable $previous = null ): self {
        return new self( $message, self::KIND_IO, $previous );
    }

    public static function unknown( string $message, ?Throwable $previous = null ): self {
        return new self( $message, self::KIND_UNKNOWN, $previous );
    }
}
