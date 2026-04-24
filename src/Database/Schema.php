<?php
/**
 * Database schema manager for the Compressly plugin.
 *
 * Responsible for creating and removing the {prefix}_compressly_log table
 * that provides the audit log and powers the dashboard stats.
 *
 * @package GoodAgency\Compressly
 */

declare(strict_types=1);

namespace GoodAgency\Compressly\Database;

use GoodAgency\Compressly\Support\Logger;
use RuntimeException;
use Throwable;

final class Schema {

    public const TABLE_SUFFIX   = 'compressly_log';
    public const DB_VERSION     = '2';
    public const VERSION_OPTION = 'compressly_db_version';

    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    public static function install(): void {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table           = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        // status is varchar (not enum) so adding new statuses (e.g. "partial")
        // is a value-only change with no schema migration needed.
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            attachment_id bigint(20) unsigned NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            original_size int unsigned NOT NULL DEFAULT 0,
            optimized_size int unsigned NOT NULL DEFAULT 0,
            webp_size int unsigned DEFAULT NULL,
            error_message text DEFAULT NULL,
            processed_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            plugin_version varchar(20) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            KEY attachment_id (attachment_id),
            KEY status (status)
        ) {$charset_collate};";

        dbDelta( $sql );

        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $exists !== $table ) {
            throw new RuntimeException( sprintf( 'Failed to create table %s.', $table ) );
        }
    }

    /**
     * Compare the stored schema version against DB_VERSION and run
     * install() (which is idempotent via dbDelta) when they differ.
     * Called from Plugin::boot() so updates that don't fire the
     * activation hook still pick up the new schema.
     */
    public static function ensure_current(): void {
        $stored = (string) get_option( self::VERSION_OPTION, '0' );
        if ( $stored === self::DB_VERSION ) {
            return;
        }

        try {
            self::install();
            update_option( self::VERSION_OPTION, self::DB_VERSION, true );
        } catch ( Throwable $e ) {
            Logger::error( 'Schema migration failed: ' . $e->getMessage() );
        }
    }

    public static function drop(): void {
        global $wpdb;
        $table = self::table_name();
        // Table name is constructed from $wpdb->prefix + a constant suffix, not user input.
        $wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }
}
