<?php
/**
 * Immutable DTO returned by ShortPixelClient::optimize().
 *
 * Describes where the optimized bytes landed on disk (temp paths) plus
 * the byte sizes the orchestrator needs for size-sanity checks before
 * it atomically renames the temp output over the source.
 *
 * @package GoodAgency\Compressly
 */

declare(strict_types=1);

namespace GoodAgency\Compressly\Optimization;

final class OptimizationOutcome {

    private string $source_path;
    private int $original_size;
    private ?string $optimized_temp_path;
    private int $optimized_size;
    private ?string $webp_temp_path;
    private ?int $webp_size;
    private bool $api_reported_same;

    public function __construct(
        string $source_path,
        int $original_size,
        ?string $optimized_temp_path,
        int $optimized_size,
        ?string $webp_temp_path,
        ?int $webp_size,
        bool $api_reported_same
    ) {
        $this->source_path         = $source_path;
        $this->original_size       = $original_size;
        $this->optimized_temp_path = $optimized_temp_path;
        $this->optimized_size      = $optimized_size;
        $this->webp_temp_path      = $webp_temp_path;
        $this->webp_size           = $webp_size;
        $this->api_reported_same   = $api_reported_same;
    }

    public function source_path(): string {
        return $this->source_path;
    }

    public function original_size(): int {
        return $this->original_size;
    }

    public function optimized_temp_path(): ?string {
        return $this->optimized_temp_path;
    }

    public function optimized_size(): int {
        return $this->optimized_size;
    }

    public function webp_temp_path(): ?string {
        return $this->webp_temp_path;
    }

    public function webp_size(): ?int {
        return $this->webp_size;
    }

    public function api_reported_same(): bool {
        return $this->api_reported_same;
    }

    public function has_optimized_output(): bool {
        return $this->optimized_temp_path !== null && file_exists( $this->optimized_temp_path );
    }

    public function has_webp_output(): bool {
        return $this->webp_temp_path !== null && file_exists( $this->webp_temp_path );
    }
}
