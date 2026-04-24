<?php
/**
 * Coordinates the end-to-end optimization of a single attachment.
 *
 * Enumerates the original plus every generated thumbnail, runs each
 * through FileValidator, backs up originals when enabled, calls
 * ShortPixelClient, applies the spec's size-sanity checks, atomically
 * renames the optimized bytes over the source, writes post_meta, and
 * records the attachment's outcome in the compressly_log table.
 *
 * @package GoodAgency\Compressly
 */

declare(strict_types=1);

namespace GoodAgency\Compressly\Optimization;

use GoodAgency\Compressly\Database\LogRepository;
use GoodAgency\Compressly\Settings\OptionsManager;
use GoodAgency\Compressly\Support\Logger;
use Throwable;

final class Optimizer {

    public const META_OPTIMIZED      = '_compressly_optimized';
    public const META_VERSION        = '_compressly_version';
    public const META_ORIGINAL_SIZE  = '_compressly_original_size';
    public const META_OPTIMIZED_SIZE = '_compressly_optimized_size';
    public const META_WEBP_PATH      = '_compressly_webp_path';
    public const META_BACKUP_PATH    = '_compressly_backup_path';

    private const MIN_IMPROVEMENT_RATIO = 0.05; // Reject outputs smaller than 5% of original.

    private OptionsManager $options;
    private FileValidator $validator;
    private BackupManager $backup;
    private ShortPixelClient $client;
    private LogRepository $log;

    public function __construct(
        OptionsManager $options,
        FileValidator $validator,
        BackupManager $backup,
        ShortPixelClient $client,
        LogRepository $log
    ) {
        $this->options   = $options;
        $this->validator = $validator;
        $this->backup    = $backup;
        $this->client    = $client;
        $this->log       = $log;
    }

    /**
     * Optimize every file for a given attachment. Idempotent: if the
     * attachment is already flagged as optimized at the current plugin
     * version, the call is a no-op.
     */
    public function optimize_attachment( int $attachment_id ): void {
        Logger::trace( 'optimize_attachment enter', [ 'attachment_id' => $attachment_id ] );

        if ( (bool) $this->options->get( 'kill_switch', false ) ) {
            Logger::trace( 'optimize_attachment abort: kill_switch on', [ 'attachment_id' => $attachment_id ] );
            return;
        }

        if ( $this->is_already_optimized( $attachment_id ) ) {
            Logger::trace( 'optimize_attachment skip: already optimized at current version', [ 'attachment_id' => $attachment_id ] );
            return;
        }

        $source_path = get_attached_file( $attachment_id );
        if ( ! is_string( $source_path ) || $source_path === '' ) {
            Logger::trace( 'optimize_attachment abort: get_attached_file returned empty', [ 'attachment_id' => $attachment_id ] );
            return;
        }

        $paths = $this->collect_paths( $attachment_id, $source_path );
        Logger::trace( 'collect_paths', [ 'attachment_id' => $attachment_id, 'source' => $source_path, 'paths' => $paths ] );
        if ( $paths === [] ) {
            return;
        }

        $totals = [
            'original'      => 0,
            'optimized'     => 0,
            'webp'          => 0,
            'processed'     => 0,
            'skipped'       => 0,
            'failed'        => 0,
            'last_error'    => null,
            'webp_for_root' => null,
        ];

        foreach ( $paths as $role => $path ) {
            try {
                $this->process_single( (string) $path, (string) $role, $totals );
            } catch ( OptimizationException $e ) {
                $totals['failed']++;
                $totals['last_error'] = sprintf( '[%s] %s: %s', $role, $e->kind(), $e->getMessage() );
                $this->handle_pipeline_exception( $e, $attachment_id, (string) $path, (string) $role );

                // Abort on auth/quota failures — every subsequent file
                // will fail the same way and burn retries.
                if ( in_array( $e->kind(), [ OptimizationException::KIND_API_KEY_INVALID, OptimizationException::KIND_QUOTA_EXCEEDED ], true ) ) {
                    break;
                }
            } catch ( Throwable $e ) {
                $totals['failed']++;
                $totals['last_error'] = sprintf( '[%s] %s', $role, $e->getMessage() );
                Logger::error( sprintf( 'Optimizer unexpected error role=%s attachment=%d path=%s: %s', $role, $attachment_id, $path, $e->getMessage() ) );
            }
        }

        Logger::trace( 'optimize_attachment totals', [ 'attachment_id' => $attachment_id, 'totals' => $totals ] );
        $this->finalize( $attachment_id, $source_path, $totals );
    }

    private function is_already_optimized( int $attachment_id ): bool {
        $flag    = get_post_meta( $attachment_id, self::META_OPTIMIZED, true );
        $version = (string) get_post_meta( $attachment_id, self::META_VERSION, true );
        $current = defined( 'COMPRESSLY_VERSION' ) ? (string) COMPRESSLY_VERSION : '';
        return ! empty( $flag ) && $version !== '' && $version === $current;
    }

    /**
     * @return array<string, string> Map of role (reserved keys "original"/"original_unscaled", or thumbnail size name) => absolute path.
     */
    private function collect_paths( int $attachment_id, string $source_path ): array {
        $paths = [];

        // When a large upload triggers WordPress's big_image_size_threshold,
        // get_attached_file() returns the -scaled variant used for delivery
        // and the untouched raw file lives next to it. Optimize the raw
        // original too so it doesn't sit full-size on disk and so our
        // backup tree retains the true pre-compression bytes.
        if ( function_exists( 'wp_get_original_image_path' ) ) {
            $unscaled = wp_get_original_image_path( $attachment_id );
            Logger::trace( 'collect_paths wp_get_original_image_path', [
                'attachment_id' => $attachment_id,
                'unscaled'      => $unscaled,
                'source'        => $source_path,
                'differs'       => ( is_string( $unscaled ) && $unscaled !== $source_path ),
                'exists'        => ( is_string( $unscaled ) && $unscaled !== '' && file_exists( $unscaled ) ),
            ] );
            if ( is_string( $unscaled ) && $unscaled !== '' && $unscaled !== $source_path && file_exists( $unscaled ) ) {
                $paths['original_unscaled'] = $unscaled;
            }
        }

        $paths['original'] = $source_path;

        $metadata = wp_get_attachment_metadata( $attachment_id );
        if ( ! is_array( $metadata ) || empty( $metadata['sizes'] ) || ! is_array( $metadata['sizes'] ) ) {
            return $paths;
        }

        $excluded = (array) $this->options->get( 'excluded_thumbnail_sizes', [] );
        $dir      = dirname( $source_path );

        foreach ( $metadata['sizes'] as $size_name => $size_data ) {
            if ( in_array( (string) $size_name, array_map( 'strval', $excluded ), true ) ) {
                continue;
            }
            if ( empty( $size_data['file'] ) ) {
                continue;
            }
            $paths[ (string) $size_name ] = $dir . '/' . $size_data['file'];
        }

        return $paths;
    }

    /**
     * @param array<string, mixed> $totals
     * @throws OptimizationException
     */
    private function process_single( string $path, string $role, array &$totals ): void {
        Logger::trace( 'process_single enter', [ 'role' => $role, 'path' => $path ] );

        $validation = $this->validator->validate( $path );
        Logger::trace( 'process_single validation', [ 'role' => $role, 'status' => $validation->status(), 'reason' => $validation->reason() ] );

        if ( $validation->is_skip() ) {
            $totals['skipped']++;
            Logger::info( sprintf( 'Skip role=%s %s: %s', $role, $path, $validation->reason() ) );
            return;
        }

        if ( $validation->is_fail() ) {
            throw OptimizationException::io( $validation->reason() );
        }

        $original_size = (int) filesize( $path );
        Logger::trace( 'process_single original_size', [ 'role' => $role, 'bytes' => $original_size ] );

        if ( (bool) $this->options->get( 'backup_originals', true ) ) {
            $this->backup->backup( $path );
            Logger::trace( 'process_single backup complete', [ 'role' => $role, 'path' => $path ] );
        }

        $outcome  = $this->client->optimize( $path );
        Logger::trace( 'process_single sdk outcome', [
            'role'          => $role,
            'api_same'      => $outcome->api_reported_same(),
            'has_optimized' => $outcome->has_optimized_output(),
            'optimized_tmp' => $outcome->optimized_temp_path(),
            'optimized_size'=> $outcome->optimized_size(),
            'has_webp'      => $outcome->has_webp_output(),
            'webp_tmp'      => $outcome->webp_temp_path(),
            'webp_size'     => $outcome->webp_size(),
        ] );
        $temp_dir = $outcome->optimized_temp_path() !== null
            ? dirname( (string) $outcome->optimized_temp_path() )
            : ( $outcome->webp_temp_path() !== null ? dirname( (string) $outcome->webp_temp_path() ) : null );

        try {
            if ( $outcome->api_reported_same() ) {
                Logger::trace( 'process_single api_reported_same branch', [ 'role' => $role, 'bytes' => $original_size ] );
                $totals['original']  += $original_size;
                $totals['optimized'] += $original_size;
                $totals['processed']++;
                return;
            }

            $this->apply_sanity_and_move( $path, $outcome, $role, $totals );
        } finally {
            if ( $temp_dir !== null ) {
                $this->client->cleanup_temp( $temp_dir );
            }
        }
    }

    /**
     * @param array<string, mixed> $totals
     * @throws OptimizationException
     */
    private function apply_sanity_and_move( string $path, OptimizationOutcome $outcome, string $role, array &$totals ): void {
        $original  = $outcome->original_size();
        $optimized = $outcome->optimized_size();

        if ( ! $outcome->has_optimized_output() ) {
            throw OptimizationException::corruptResult( 'Optimized file missing on disk: ' . $path );
        }

        if ( $optimized <= 0 ) {
            throw OptimizationException::corruptResult( 'Optimized file is empty: ' . $path );
        }

        // Size sanity: reject pathologically small output as corrupt.
        if ( $optimized < (int) floor( $original * self::MIN_IMPROVEMENT_RATIO ) ) {
            throw OptimizationException::corruptResult( sprintf(
                'Optimized output (%d B) < 5%% of original (%d B). Rejected.',
                $optimized,
                $original
            ) );
        }

        // Size sanity: if output is larger, keep the original.
        if ( $optimized >= $original ) {
            Logger::trace( 'apply_sanity_and_move kept original (larger optimized)', [ 'role' => $role, 'original' => $original, 'optimized' => $optimized ] );
            $totals['original']  += $original;
            $totals['optimized'] += $original;
            $totals['processed']++;
            return;
        }

        // Atomic replace.
        $temp_optimized = (string) $outcome->optimized_temp_path();
        $rename_ok      = @rename( $temp_optimized, $path );
        Logger::trace( 'apply_sanity_and_move rename', [
            'role'       => $role,
            'from'       => $temp_optimized,
            'to'         => $path,
            'success'    => $rename_ok,
            'from_exists'=> file_exists( $temp_optimized ),
            'to_writable'=> is_writable( dirname( $path ) ),
        ] );
        if ( ! $rename_ok ) {
            throw OptimizationException::io( 'Failed to move optimized file into place: ' . $path );
        }

        $totals['original']  += $original;
        $totals['optimized'] += $optimized;
        $totals['processed']++;

        if ( $outcome->has_webp_output() ) {
            $webp_target     = $this->webp_target_path( $path );
            $webp_rename_ok  = @rename( (string) $outcome->webp_temp_path(), $webp_target );
            Logger::trace( 'apply_sanity_and_move webp rename', [ 'role' => $role, 'to' => $webp_target, 'success' => $webp_rename_ok ] );
            if ( $webp_rename_ok ) {
                $totals['webp'] += (int) $outcome->webp_size();
                if ( $role === 'original' ) {
                    $totals['webp_for_root'] = $this->relative_to_uploads( $webp_target );
                }
            } else {
                Logger::warning( 'Failed to move WebP file into place: ' . $webp_target );
            }
        }
    }

    private function handle_pipeline_exception( OptimizationException $e, int $attachment_id, string $path, string $role ): void {
        Logger::error( sprintf( 'Optimizer[%s] role=%s attachment=%d path=%s: %s', $e->kind(), $role, $attachment_id, $path, $e->getMessage() ) );

        switch ( $e->kind() ) {
            case OptimizationException::KIND_API_KEY_INVALID:
                set_transient( 'compressly_error_api_key_invalid', 1, HOUR_IN_SECONDS );
                break;
            case OptimizationException::KIND_QUOTA_EXCEEDED:
                set_transient( 'compressly_error_quota_exceeded', 1, HOUR_IN_SECONDS );
                break;
        }
    }

    /**
     * @param array{original:int, optimized:int, webp:int, processed:int, skipped:int, failed:int, last_error:?string, webp_for_root:?string} $totals
     */
    private function finalize( int $attachment_id, string $source_path, array $totals ): void {
        $status = $this->derive_status( $totals );

        // SUCCESS and PARTIAL both update attachment-level meta: at least
        // one path was optimized, so the attachment is no longer in its
        // pristine state and Phase 4's bulk processor should skip the
        // already-compressed paths next time.
        if ( in_array( $status, [ LogRepository::STATUS_SUCCESS, LogRepository::STATUS_PARTIAL ], true ) ) {
            update_post_meta( $attachment_id, self::META_OPTIMIZED, 1 );
            update_post_meta( $attachment_id, self::META_VERSION, defined( 'COMPRESSLY_VERSION' ) ? COMPRESSLY_VERSION : '' );
            update_post_meta( $attachment_id, self::META_ORIGINAL_SIZE, (int) $totals['original'] );
            update_post_meta( $attachment_id, self::META_OPTIMIZED_SIZE, (int) $totals['optimized'] );

            if ( ! empty( $totals['webp_for_root'] ) ) {
                update_post_meta( $attachment_id, self::META_WEBP_PATH, (string) $totals['webp_for_root'] );
            }

            $backup_relative = $this->backup->relative_backup_path( $source_path );
            if ( $backup_relative !== '' && (bool) $this->options->get( 'backup_originals', true ) ) {
                update_post_meta( $attachment_id, self::META_BACKUP_PATH, $backup_relative );
            }
        }

        $this->log->record(
            [
                'attachment_id'  => $attachment_id,
                'status'         => $status,
                'original_size'  => (int) $totals['original'],
                'optimized_size' => (int) $totals['optimized'],
                'webp_size'      => $totals['webp'] > 0 ? (int) $totals['webp'] : null,
                'error_message'  => $totals['last_error'] !== null ? (string) $totals['last_error'] : null,
            ]
        );
    }

    /**
     * @param array{processed:int, skipped:int, failed:int} $totals
     */
    private function derive_status( array $totals ): string {
        if ( $totals['failed'] > 0 && $totals['processed'] > 0 ) {
            return LogRepository::STATUS_PARTIAL;
        }
        if ( $totals['failed'] > 0 ) {
            return LogRepository::STATUS_FAILED;
        }
        if ( $totals['processed'] === 0 && $totals['skipped'] > 0 ) {
            return LogRepository::STATUS_SKIPPED;
        }
        return LogRepository::STATUS_SUCCESS;
    }

    private function webp_target_path( string $source_path ): string {
        $dir      = dirname( $source_path );
        $filename = pathinfo( $source_path, PATHINFO_FILENAME );
        return $dir . '/' . $filename . '.webp';
    }

    private function relative_to_uploads( string $absolute_path ): string {
        $upload_dir = wp_get_upload_dir();
        $basedir    = isset( $upload_dir['basedir'] ) ? (string) $upload_dir['basedir'] : '';
        if ( $basedir === '' ) {
            return '';
        }

        $normalized_base = rtrim( str_replace( '\\', '/', $basedir ), '/' ) . '/';
        $normalized_path = str_replace( '\\', '/', $absolute_path );

        if ( strpos( $normalized_path, $normalized_base ) !== 0 ) {
            return '';
        }

        return ltrim( substr( $normalized_path, strlen( $normalized_base ) ), '/' );
    }
}
