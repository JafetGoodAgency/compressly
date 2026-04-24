<?php
/**
 * Central logging helper.
 *
 * Phase 1 ships a minimal implementation that writes structured lines
 * to error_log() so activation-time failures stay out of the user's
 * face (no WSOD) but still surface in the PHP/WP debug log. Later
 * phases expand this to also record entries in the compressly_log
 * custom table.
 *
 * @package GoodAgency\Compressly
 */

declare(strict_types=1);

namespace GoodAgency\Compressly\Support;

final class Logger {

    /**
     * @param array<string, mixed> $context
     */
    public static function error( string $message, array $context = [] ): void {
        self::write( 'ERROR', $message, $context );
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function warning( string $message, array $context = [] ): void {
        self::write( 'WARNING', $message, $context );
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function info( string $message, array $context = [] ): void {
        self::write( 'INFO', $message, $context );
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function write( string $level, string $message, array $context ): void {
        $line = sprintf( '[Compressly][%s] %s', $level, $message );
        if ( $context !== [] ) {
            $encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $context ) : json_encode( $context );
            if ( is_string( $encoded ) && $encoded !== '' ) {
                $line .= ' ' . $encoded;
            }
        }
        error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
    }
}
