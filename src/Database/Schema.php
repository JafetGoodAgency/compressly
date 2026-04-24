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

use RuntimeException;

final class Schema {

    public const TABLE_SUFFIX = 'compressly_log';

    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    public static function install(): void {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table           = self::table_name();
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            attachment_id bigint(20) unsigned NOT NULL,
            status enum('pending','success','failed','skipped') NOT NULL DEFAULT 'pending',
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

    public static function drop(): void {
        global $wpdb;
        $table = self::table_name();
        // Table name is constructed from $wpdb->prefix + a constant suffix, not user input.
        $wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }
}
