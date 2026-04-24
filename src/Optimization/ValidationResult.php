<?php
/**
 * Immutable verdict returned by FileValidator.
 *
 * Three possible outcomes: ok (process the file), skip (don't process
 * but don't treat as a failure), fail (real error, record in log).
 * The reason string is surfaced into the log table and admin notices.
 *
 * @package GoodAgency\Compressly
 */

declare(strict_types=1);

namespace GoodAgency\Compressly\Optimization;

final class ValidationResult {

    public const STATUS_OK   = 'ok';
    public const STATUS_SKIP = 'skip';
    public const STATUS_FAIL = 'fail';

    private string $status;
    private string $reason;

    private function __construct( string $status, string $reason ) {
        $this->status = $status;
        $this->reason = $reason;
    }

    public static function ok(): self {
        return new self( self::STATUS_OK, '' );
    }

    public static function skip( string $reason ): self {
        return new self( self::STATUS_SKIP, $reason );
    }

    public static function fail( string $reason ): self {
        return new self( self::STATUS_FAIL, $reason );
    }

    public function status(): string {
        return $this->status;
    }

    public function reason(): string {
        return $this->reason;
    }

    public function passes(): bool {
        return $this->status === self::STATUS_OK;
    }

    public function is_skip(): bool {
        return $this->status === self::STATUS_SKIP;
    }

    public function is_fail(): bool {
        return $this->status === self::STATUS_FAIL;
    }
}
