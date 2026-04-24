<?php
/**
 * Adapter around the official shortpixel/shortpixel-php SDK.
 *
 * Centralises API-key setup, compression-level translation, WebP and
 * resize options, temp-directory management for atomic writes, and
 * mapping SDK exceptions to our OptimizationException taxonomy.
 * Retries connection errors with exponential backoff per the spec
 * (2s / 4s / 8s).
 *
 * @package GoodAgency\Compressly
 */

declare(strict_types=1);

namespace GoodAgency\Compressly\Optimization;

use GoodAgency\Compressly\Settings\Defaults;
use GoodAgency\Compressly\Settings\OptionsManager;
use GoodAgency\Compressly\Support\Logger;
use ShortPixel\AccountException;
use ShortPixel\ClientException;
use ShortPixel\ConnectionException;
use ShortPixel\Exception as ShortPixelException;
use ShortPixel\ServerException;
use ShortPixel\ShortPixel as ShortPixelSdk;
use Throwable;

final class ShortPixelClient {

    private const COMPRESSION_MAP = [
        Defaults::COMPRESSION_LOSSLESS => 0,
        Defaults::COMPRESSION_LOSSY    => 1,
        Defaults::COMPRESSION_GLOSSY   => 2,
    ];

    private const WAIT_SECONDS      = 60;
    private const MAX_RETRIES       = 3;
    private const RETRY_BACKOFF_SEC = [ 2, 4, 8 ];
    private const TEMP_DIR_NAME     = 'compressly-tmp';

    private OptionsManager $options;
    private bool $key_initialized = false;

    public function __construct( OptionsManager $options ) {
        $this->options = $options;
    }

    /**
     * Run a single file through ShortPixel. The optimized bytes land in
     * a freshly-created temp directory owned by the caller — it is the
     * caller's responsibility to atomically rename them over the
     * source and then call cleanup_temp().
     *
     * @throws OptimizationException
     */
    public function optimize( string $source_path ): OptimizationOutcome {
        if ( ! file_exists( $source_path ) ) {
            throw OptimizationException::io( 'Source not found: ' . $source_path );
        }

        $this->ensure_key_set();

        $original_size = (int) filesize( $source_path );
        if ( $original_size <= 0 ) {
            throw OptimizationException::io( 'Empty source: ' . $source_path );
        }

        $temp_dir = $this->make_temp_dir( $source_path );
        $level    = $this->compression_level();
        $webp     = (bool) $this->options->get( 'webp_enabled', true );
        $resize   = (bool) $this->options->get( 'resize_enabled', true );
        $max_w    = (int) $this->options->get( 'resize_max_width', 2560 );
        $max_h    = (int) $this->options->get( 'resize_max_height', 2560 );

        Logger::trace( 'client optimize start', [
            'source'  => $source_path,
            'bytes'   => $original_size,
            'temp'    => $temp_dir,
            'level'   => $level,
            'webp'    => $webp,
            'resize'  => $resize,
            'max_w'   => $max_w,
            'max_h'   => $max_h,
        ] );

        $result = $this->call_sdk_with_retry(
            function () use ( $source_path, $temp_dir, $level, $webp, $resize, $max_w, $max_h ) {
                $commander = \ShortPixel\fromFile( $source_path );
                $commander = $commander->optimize( $level );
                if ( $webp ) {
                    $commander = $commander->generateWebP( true );
                }
                if ( $resize && $max_w > 0 && $max_h > 0 ) {
                    $commander = $commander->resize( $max_w, $max_h, false );
                }
                return $commander->wait( self::WAIT_SECONDS )->toFiles( $temp_dir );
            }
        );

        Logger::trace( 'client sdk result', [
            'source'    => $source_path,
            'succeeded' => is_array( $result->succeeded ?? null ) ? count( $result->succeeded ) : null,
            'same'      => is_array( $result->same ?? null ) ? count( $result->same ) : null,
            'failed'    => is_array( $result->failed ?? null ) ? count( $result->failed ) : null,
            'pending'   => is_array( $result->pending ?? null ) ? count( $result->pending ) : null,
            'failed_first_status' => ! empty( $result->failed ) && isset( $result->failed[0]->Status ) ? (array) $result->failed[0]->Status : null,
            'temp_dir_contents'   => $this->scan_temp_dir( $temp_dir ),
        ] );

        return $this->outcome_from_result( $source_path, $original_size, $temp_dir, $webp, $result );
    }

    /**
     * @return array<int, string>
     */
    private function scan_temp_dir( string $temp_dir ): array {
        if ( ! is_dir( $temp_dir ) ) {
            return [];
        }
        $entries = scandir( $temp_dir );
        if ( $entries === false ) {
            return [];
        }
        $list = [];
        foreach ( $entries as $entry ) {
            if ( $entry === '.' || $entry === '..' ) {
                continue;
            }
            $full = $temp_dir . '/' . $entry;
            $list[] = $entry . ' (' . ( is_file( $full ) ? (string) filesize( $full ) : 'dir' ) . ')';
        }
        return $list;
    }

    /**
     * Recursively delete a temp directory created by optimize().
     */
    public function cleanup_temp( string $temp_dir ): void {
        $base = $this->temp_base_dir();
        $normalized_base = rtrim( str_replace( '\\', '/', $base ), '/' ) . '/';
        $normalized_temp = str_replace( '\\', '/', $temp_dir );
        if ( strpos( $normalized_temp, $normalized_base ) !== 0 ) {
            return; // defense-in-depth: never rm -rf outside our temp root.
        }
        $this->rmdir_recursive( $temp_dir );
    }

    private function ensure_key_set(): void {
        if ( $this->key_initialized ) {
            return;
        }
        $key = (string) $this->options->get( 'api_key', '' );
        if ( $key === '' ) {
            throw OptimizationException::apiKeyInvalid( 'Compressly API key is not configured.' );
        }
        ShortPixelSdk::setKey( $key );
        $this->key_initialized = true;
    }

    private function compression_level(): int {
        $configured = (string) $this->options->get( 'compression_level', Defaults::COMPRESSION_LOSSY );
        return self::COMPRESSION_MAP[ $configured ] ?? 1;
    }

    /**
     * @return object
     * @throws OptimizationException
     */
    private function call_sdk_with_retry( callable $fn ) {
        $last_exception = null;

        for ( $attempt = 0; $attempt < self::MAX_RETRIES; $attempt++ ) {
            try {
                return $fn();
            } catch ( AccountException $e ) {
                throw $this->map_account_exception( $e );
            } catch ( ConnectionException $e ) {
                $last_exception = OptimizationException::network( $e->getMessage(), $e );
            } catch ( ServerException $e ) {
                $last_exception = OptimizationException::network( $e->getMessage(), $e );
            } catch ( ClientException $e ) {
                throw OptimizationException::unknown( 'ShortPixel rejected request: ' . $e->getMessage(), $e );
            } catch ( ShortPixelException $e ) {
                throw OptimizationException::unknown( $e->getMessage(), $e );
            } catch ( Throwable $e ) {
                throw OptimizationException::unknown( $e->getMessage(), $e );
            }

            if ( $attempt < self::MAX_RETRIES - 1 ) {
                $delay = self::RETRY_BACKOFF_SEC[ $attempt ] ?? 8;
                sleep( $delay );
            }
        }

        throw $last_exception ?: OptimizationException::network( 'ShortPixel request failed after retries.' );
    }

    private function map_account_exception( AccountException $e ): OptimizationException {
        $code = (int) $e->getCode();
        if ( $code === 401 ) {
            return OptimizationException::apiKeyInvalid( $e->getMessage(), $e );
        }
        if ( $code === 429 ) {
            return OptimizationException::quotaExceeded( $e->getMessage(), $e );
        }
        $message = strtolower( $e->getMessage() );
        if ( strpos( $message, 'quota' ) !== false || strpos( $message, 'credits' ) !== false ) {
            return OptimizationException::quotaExceeded( $e->getMessage(), $e );
        }
        if ( strpos( $message, 'api key' ) !== false || strpos( $message, 'invalid key' ) !== false ) {
            return OptimizationException::apiKeyInvalid( $e->getMessage(), $e );
        }
        return OptimizationException::unknown( $e->getMessage(), $e );
    }

    /**
     * Translate the SDK's toFiles() object into an OptimizationOutcome.
     * Handles both the "optimized successfully" and "API said same"
     * paths; "pending" or "failed" items become exceptions.
     *
     * @param object $result
     * @throws OptimizationException
     */
    private function outcome_from_result(
        string $source_path,
        int $original_size,
        string $temp_dir,
        bool $webp_requested,
        $result
    ): OptimizationOutcome {
        $basename     = basename( $source_path );
        $optimized    = rtrim( $temp_dir, '/' ) . '/' . $basename;
        $webp_target  = rtrim( $temp_dir, '/' ) . '/' . pathinfo( $basename, PATHINFO_FILENAME ) . '.webp';

        if ( ! empty( $result->failed ) ) {
            $item    = $result->failed[0];
            $code    = isset( $item->Status->Code ) ? (string) $item->Status->Code : '';
            $message = isset( $item->Status->Message ) ? (string) $item->Status->Message : 'Optimization failed.';
            Logger::trace( 'outcome_from_result branch=failed', [ 'source' => $source_path, 'code' => $code, 'message' => $message ] );
            throw OptimizationException::unknown( 'ShortPixel reported failure (code=' . $code . '): ' . $message );
        }

        if ( ! empty( $result->pending ) ) {
            Logger::trace( 'outcome_from_result branch=pending', [ 'source' => $source_path ] );
            throw OptimizationException::network( 'Optimization still pending after wait window expired.' );
        }

        $api_same = ! empty( $result->same );

        if ( ! $api_same && empty( $result->succeeded ) ) {
            Logger::trace( 'outcome_from_result branch=empty', [ 'source' => $source_path ] );
            throw OptimizationException::unknown( 'ShortPixel returned no processable items.' );
        }

        clearstatcache();

        if ( $api_same ) {
            Logger::trace( 'outcome_from_result branch=same', [ 'source' => $source_path, 'original_size' => $original_size ] );
            return new OptimizationOutcome(
                $source_path,
                $original_size,
                null,
                $original_size,
                null,
                null,
                true
            );
        }

        if ( ! file_exists( $optimized ) ) {
            Logger::trace( 'outcome_from_result branch=missing_optimized_file', [ 'source' => $source_path, 'expected' => $optimized ] );
            throw OptimizationException::io( 'Optimized file missing from temp dir: ' . $optimized );
        }

        $optimized_size = (int) filesize( $optimized );

        $webp_path = null;
        $webp_size = null;
        if ( $webp_requested && file_exists( $webp_target ) ) {
            $webp_path = $webp_target;
            $webp_size = (int) filesize( $webp_target );
        }

        Logger::trace( 'outcome_from_result branch=succeeded', [
            'source'         => $source_path,
            'optimized_size' => $optimized_size,
            'webp_size'      => $webp_size,
        ] );

        return new OptimizationOutcome(
            $source_path,
            $original_size,
            $optimized,
            $optimized_size,
            $webp_path,
            $webp_size,
            false
        );
    }

    private function make_temp_dir( string $source_path ): string {
        $base = $this->temp_base_dir();
        if ( ! wp_mkdir_p( $base ) ) {
            throw OptimizationException::io( 'Cannot create temp base directory: ' . $base );
        }

        $unique = uniqid( '', true );
        $dir    = $base . '/' . md5( $source_path . '|' . $unique );
        if ( ! wp_mkdir_p( $dir ) ) {
            throw OptimizationException::io( 'Cannot create temp directory: ' . $dir );
        }
        return $dir;
    }

    private function temp_base_dir(): string {
        $upload_dir = wp_get_upload_dir();
        $basedir    = isset( $upload_dir['basedir'] ) ? (string) $upload_dir['basedir'] : '';
        return rtrim( $basedir, '/\\' ) . '/' . self::TEMP_DIR_NAME;
    }

    private function rmdir_recursive( string $dir ): void {
        if ( ! is_dir( $dir ) ) {
            return;
        }
        $entries = scandir( $dir );
        if ( $entries === false ) {
            return;
        }
        foreach ( $entries as $entry ) {
            if ( $entry === '.' || $entry === '..' ) {
                continue;
            }
            $path = $dir . '/' . $entry;
            if ( is_dir( $path ) ) {
                $this->rmdir_recursive( $path );
            } else {
                @unlink( $path );
            }
        }
        @rmdir( $dir );
    }
}
