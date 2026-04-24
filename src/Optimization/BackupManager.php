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
