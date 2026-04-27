<?php
/**
 * Backs up original attachment files before they are optimized.
 *
 * Mirrors the uploads-folder structure under
 * `/wp-content/uploads/compressly-backup/` so restore can put every
 * file back where it came from. A hardened .htaccess drops direct
 * browser access to the backup tree. All paths are validated to live
 * inside the uploads directory to prevent directory traversal.
 *
 * @package GoodAgency\Compressly
 */

declare(strict_types=1);

namespace GoodAgency\Compressly\Optimization;

use Throwable;

final class BackupManager {

    private const BACKUP_DIR_NAME = 'compressly-backup';

    public function base_dir(): string {
        $upload_dir = wp_get_upload_dir();
        $basedir    = isset( $upload_dir['basedir'] ) ? (string) $upload_dir['basedir'] : '';
        return rtrim( $basedir, '/\\' ) . '/' . self::BACKUP_DIR_NAME;
    }

    /**
     * Ensure the given source file has a backup. Returns the absolute
     * path of the backup on success; throws OptimizationException on
     * I/O failure. Paths that already have a backup are returned
     * as-is (idempotent).
     *
     * @throws OptimizationException
     */
    public function backup( string $source_path ): string {
        if ( ! is_readable( $source_path ) ) {
            throw OptimizationException::io( 'Source file not readable: ' . $source_path );
        }

        $relative = $this->relative_to_uploads( $source_path );
        if ( $relative === null ) {
            throw OptimizationException::io( 'File is outside the uploads directory: ' . $source_path );
        }

        $this->ensure_base_dir();

        $target = $this->base_dir() . '/' . $relative;
        $target_dir = dirname( $target );

        if ( ! wp_mkdir_p( $target_dir ) ) {
            throw OptimizationException::io( 'Cannot create backup directory: ' . $target_dir );
        }

        if ( file_exists( $target ) ) {
            return $target;
        }

        if ( ! copy( $source_path, $target ) ) {
            throw OptimizationException::io( 'Backup copy failed for ' . $source_path );
        }

        return $target;
    }

    /**
     * Backup path relative to the uploads basedir (suitable for storing
     * in post_meta). Returns empty string if the file is not in uploads.
     */
    public function relative_backup_path( string $source_path ): string {
        $relative = $this->relative_to_uploads( $source_path );
        if ( $relative === null ) {
            return '';
        }
        return self::BACKUP_DIR_NAME . '/' . $relative;
    }

    /**
     * Restore an attachment's original files from the backup tree.
     *
     * Walks every known file path for the attachment (the public
     * "original" returned by get_attached_file, the unscaled raw if
     * different, and every generated thumbnail), and for each one with
     * a matching backup, copies the backup over the live file. The
     * companion .webp file is removed alongside, since it was derived
     * from the optimized bytes and no longer matches the restored
     * source. After at least one successful restore, the attachment's
     * _compressly_* meta is cleared so a future bulk run can re-pick
     * the attachment up.
     *
     * @return array{restored: array<int, string>, skipped: array<int, string>, errors: array<int, array{path:string, message:string}>}
     */
    public function restore( int $attachment_id ): array {
        $restored = [];
        $skipped  = [];
        $errors   = [];

        $source = get_attached_file( $attachment_id );
        if ( ! is_string( $source ) || $source === '' ) {
            return [
                'restored' => [],
                'skipped'  => [],
                'errors'   => [ [ 'path' => '', 'message' => 'No attached file for ' . $attachment_id ] ],
            ];
        }

        $paths = [ $source ];

        if ( function_exists( 'wp_get_original_image_path' ) ) {
            $unscaled = wp_get_original_image_path( $attachment_id );
            if ( is_string( $unscaled ) && $unscaled !== '' && $unscaled !== $source ) {
                $paths[] = $unscaled;
            }
        }

        $metadata = wp_get_attachment_metadata( $attachment_id );
        if ( is_array( $metadata ) && ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
            $dir = dirname( $source );
            foreach ( $metadata['sizes'] as $size_data ) {
                if ( ! empty( $size_data['file'] ) ) {
                    $paths[] = $dir . '/' . (string) $size_data['file'];
                }
            }
        }

        $paths = array_values( array_unique( $paths ) );

        foreach ( $paths as $live_path ) {
            $backup = $this->backup_path_for( $live_path );
            if ( $backup === null ) {
                continue;
            }
            if ( ! file_exists( $backup ) ) {
                $skipped[] = $live_path;
                continue;
            }

            $live_dir = dirname( $live_path );
            if ( ! is_dir( $live_dir ) && ! wp_mkdir_p( $live_dir ) ) {
                $errors[] = [ 'path' => $live_path, 'message' => 'Cannot recreate directory: ' . $live_dir ];
                continue;
            }

            if ( ! @copy( $backup, $live_path ) ) {
                $errors[] = [ 'path' => $live_path, 'message' => 'Copy from backup failed' ];
                continue;
            }

            $restored[] = $live_path;

            $companion = $this->companion_webp_path( $live_path );
            if ( $companion !== null && file_exists( $companion ) ) {
                @unlink( $companion );
            }
        }

        if ( $restored !== [] ) {
            try {
                $this->clear_attachment_meta( $attachment_id );
            } catch ( Throwable $e ) {
                $errors[] = [ 'path' => '', 'message' => 'Meta cleanup failed: ' . $e->getMessage() ];
            }
        }

        return [
            'restored' => $restored,
            'skipped'  => $skipped,
            'errors'   => $errors,
        ];
    }

    /**
     * Compute the backup path for a given live path. Tolerant of
     * missing live files so restore can still proceed when the
     * optimized bytes have been deleted out from under us — only the
     * uploads basedir is realpath'd, the live path itself is checked
     * as a string prefix.
     */
    private function backup_path_for( string $live_path ): ?string {
        $upload_dir = wp_get_upload_dir();
        $basedir    = isset( $upload_dir['basedir'] ) ? (string) $upload_dir['basedir'] : '';
        if ( $basedir === '' ) {
            return null;
        }

        $real_base = realpath( $basedir );
        if ( $real_base === false ) {
            return null;
        }

        $real_base   = rtrim( str_replace( '\\', '/', $real_base ), '/' ) . '/';
        $normalized  = str_replace( '\\', '/', $live_path );

        if ( strpos( $normalized, $real_base ) !== 0 ) {
            return null;
        }

        $relative = ltrim( substr( $normalized, strlen( $real_base ) ), '/' );
        if ( $relative === '' ) {
            return null;
        }

        return $this->base_dir() . '/' . $relative;
    }

    private function companion_webp_path( string $path ): ?string {
        $info = pathinfo( $path );
        if ( empty( $info['filename'] ) || empty( $info['dirname'] ) ) {
            return null;
        }
        if ( strtolower( (string) ( $info['extension'] ?? '' ) ) === 'webp' ) {
            return null;
        }
        return $info['dirname'] . '/' . $info['filename'] . '.webp';
    }

    private function clear_attachment_meta( int $attachment_id ): void {
        $keys = [
            '_compressly_optimized',
            '_compressly_version',
            '_compressly_original_size',
            '_compressly_optimized_size',
            '_compressly_webp_path',
            '_compressly_backup_path',
        ];
        foreach ( $keys as $key ) {
            delete_post_meta( $attachment_id, $key );
        }
    }

    private function ensure_base_dir(): void {
        $base = $this->base_dir();
        if ( ! wp_mkdir_p( $base ) ) {
            throw OptimizationException::io( 'Cannot create backup base directory: ' . $base );
        }

        $htaccess = $base . '/.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            @file_put_contents( $htaccess, "Require all denied\n" );
        }

        $index = $base . '/index.php';
        if ( ! file_exists( $index ) ) {
            @file_put_contents( $index, "<?php\n// Silence is golden.\n" );
        }
    }

    private function relative_to_uploads( string $file_path ): ?string {
        $upload_dir = wp_get_upload_dir();
        $basedir    = isset( $upload_dir['basedir'] ) ? (string) $upload_dir['basedir'] : '';
        if ( $basedir === '' ) {
            return null;
        }

        $real_base = realpath( $basedir );
        $real_file = realpath( $file_path );
        if ( $real_base === false || $real_file === false ) {
            return null;
        }

        $real_base = rtrim( str_replace( '\\', '/', $real_base ), '/' ) . '/';
        $real_file = str_replace( '\\', '/', $real_file );

        if ( strpos( $real_file, $real_base ) !== 0 ) {
            return null;
        }

        return ltrim( substr( $real_file, strlen( $real_base ) ), '/' );
    }
}
