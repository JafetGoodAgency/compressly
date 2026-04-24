<?php
/**
 * Pre-flight checks for a single file before it is sent to ShortPixel.
 *
 * Applies the skip/fail rules from the spec: supported MIME types, the
 * configurable skip-threshold, the hard 100 MB upper bound, user
 * exclusion patterns, and a guard that the path lives inside the
 * WordPress uploads directory (defense against directory traversal).
 *
 * @package GoodAgency\Compressly
 */

declare(strict_types=1);

namespace GoodAgency\Compressly\Optimization;

use GoodAgency\Compressly\Settings\OptionsManager;

final class FileValidator {

    private const MAX_BYTES = 100 * 1024 * 1024; // 100 MB hard limit per spec.

    private const ALLOWED_MIME = [
        'image/jpeg',
        'image/pjpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    private OptionsManager $options;

    public function __construct( OptionsManager $options ) {
        $this->options = $options;
    }

    public function validate( string $file_path ): ValidationResult {
        if ( $file_path === '' || ! file_exists( $file_path ) ) {
            return ValidationResult::fail( 'File not found: ' . $file_path );
        }

        if ( ! is_readable( $file_path ) ) {
            return ValidationResult::fail( 'File not readable: ' . $file_path );
        }

        if ( ! $this->is_within_uploads( $file_path ) ) {
            return ValidationResult::fail( 'File is outside the uploads directory: ' . $file_path );
        }

        clearstatcache( true, $file_path );
        $size = (int) filesize( $file_path );

        if ( $size <= 0 ) {
            return ValidationResult::fail( 'Empty or unreadable file: ' . $file_path );
        }

        if ( $size > self::MAX_BYTES ) {
            return ValidationResult::skip( sprintf( 'File larger than %d MB hard limit.', self::MAX_BYTES / 1024 / 1024 ) );
        }

        $threshold_kb = (int) $this->options->get( 'skip_threshold_kb', 10 );
        if ( $threshold_kb > 0 && $size < $threshold_kb * 1024 ) {
            return ValidationResult::skip( sprintf( 'Below %d KB skip threshold.', $threshold_kb ) );
        }

        if ( ! $this->is_supported_type( $file_path ) ) {
            return ValidationResult::skip( 'Unsupported MIME type.' );
        }

        if ( $this->is_excluded( $file_path ) ) {
            return ValidationResult::skip( 'Matches exclusion pattern.' );
        }

        return ValidationResult::ok();
    }

    private function is_supported_type( string $file_path ): bool {
        $filetype = wp_check_filetype( $file_path );
        $mime     = (string) ( $filetype['type'] ?? '' );

        if ( $mime === '' || ! in_array( $mime, self::ALLOWED_MIME, true ) ) {
            return false;
        }

        if ( function_exists( 'getimagesize' ) ) {
            $info = @getimagesize( $file_path );
            if ( $info === false ) {
                return false;
            }
        }

        return true;
    }

    private function is_excluded( string $file_path ): bool {
        $raw = (string) $this->options->get( 'exclusion_patterns', '' );
        if ( trim( $raw ) === '' ) {
            return false;
        }

        $normalized_path = str_replace( '\\', '/', $file_path );
        $relative        = $this->relative_to_uploads( $normalized_path );

        $patterns = preg_split( '/\r\n|\r|\n/', $raw );
        if ( ! is_array( $patterns ) ) {
            return false;
        }

        foreach ( $patterns as $pattern ) {
            $pattern = trim( $pattern );
            if ( $pattern === '' ) {
                continue;
            }

            if ( fnmatch( $pattern, $normalized_path ) ) {
                return true;
            }

            if ( $relative !== '' && fnmatch( $pattern, $relative ) ) {
                return true;
            }

            if ( $relative !== '' && fnmatch( $pattern, '/' . ltrim( $relative, '/' ) ) ) {
                return true;
            }
        }

        return false;
    }

    private function is_within_uploads( string $file_path ): bool {
        $upload_dir = wp_get_upload_dir();
        $basedir    = isset( $upload_dir['basedir'] ) ? (string) $upload_dir['basedir'] : '';
        if ( $basedir === '' ) {
            return false;
        }

        $real_base = realpath( $basedir );
        $real_file = realpath( $file_path );
        if ( $real_base === false || $real_file === false ) {
            return false;
        }

        $real_base = rtrim( str_replace( '\\', '/', $real_base ), '/' ) . '/';
        $real_file = str_replace( '\\', '/', $real_file );

        return strpos( $real_file, $real_base ) === 0;
    }

    private function relative_to_uploads( string $normalized_path ): string {
        $upload_dir = wp_get_upload_dir();
        $basedir    = isset( $upload_dir['basedir'] ) ? str_replace( '\\', '/', (string) $upload_dir['basedir'] ) : '';
        if ( $basedir === '' || strpos( $normalized_path, $basedir ) !== 0 ) {
            return '';
        }
        return ltrim( substr( $normalized_path, strlen( $basedir ) ), '/' );
    }
}
