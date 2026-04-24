<?php
/**
 * Plugin deactivation handler.
 *
 * Clears Compressly-owned transients on deactivation. Options and the
 * custom log table are intentionally preserved so reactivation is a
 * true no-op from the user's perspective.
 *
 * @package GoodAgency\Compressly
 */

declare(strict_types=1);

namespace GoodAgency\Compressly;

use GoodAgency\Compressly\Support\Logger;
use Throwable;

final class Deactivator {

    public static function deactivate(): void {
        try {
            self::delete_transients();
        } catch ( Throwable $e ) {
            Logger::error( 'Deactivation transient cleanup failed: ' . $e->getMessage() );
        }

        try {
            self::clear_scheduled_events();
        } catch ( Throwable $e ) {
            Logger::error( 'Deactivation cron cleanup failed: ' . $e->getMessage() );
        }
    }

    private static function clear_scheduled_events(): void {
        wp_clear_scheduled_hook( 'compressly_optimize_deferred_path' );
    }

    private static function delete_transients(): void {
        global $wpdb;

        $prefixes = [
            '_transient_compressly_',
            '_transient_timeout_compressly_',
            '_site_transient_compressly_',
            '_site_transient_timeout_compressly_',
        ];

        foreach ( $prefixes as $prefix ) {
            $like = $wpdb->esc_like( $prefix ) . '%';
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                    $like
                )
            );
        }
    }
}
