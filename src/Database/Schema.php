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
    public const DB_VERSION     = '3';
    public const VERSION_OPTION = 'compressly_db_version';

    private const INDEX_ATTACHMENT_ID = 'attachment_id';

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
        //
        // attachment_id is UNIQUE so re-runs UPSERT into the same row
        // instead of accumulating duplicates. Before v3 the column
        // had a non-unique KEY and bulk re-runs (especially the
        // pre-fix auto-resume regression) produced N rows per
        // attachment, throwing off the latest-status-per-attachment
        // stats queries. The v2→v3 migration in ensure_current()
        // dedupes then swaps the index.
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
            UNIQUE KEY attachment_id (attachment_id),
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
            // v2→v3 must dedupe and swap the attachment_id index
            // BEFORE dbDelta runs. dbDelta does not reliably convert
            // an existing non-unique KEY into a UNIQUE KEY, and the
            // ALTER would fail mid-flight if duplicates still exist.
            if ( version_compare( $stored, '3', '<' ) && self::table_exists() ) {
                self::dedupe_log_table();
                self::swap_attachment_id_index_to_unique();
            }

            self::install();
            update_option( self::VERSION_OPTION, self::DB_VERSION, true );
        } catch ( Throwable $e ) {
            Logger::error( 'Schema migration failed: ' . $e->getMessage() );
        }
    }

    private static function table_exists(): bool {
        global $wpdb;
        $table = self::table_name();
        $found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        return $found === $table;
    }

    /**
     * Collapse duplicate rows so the v3 UNIQUE constraint can be
     * applied. Keeps the row with the highest processed_at per
     * attachment_id (id as the tie-breaker, since id is monotonic
     * autoincrement). Self-join DELETE is one statement and runs in
     * a fraction of a second even on libraries with 80k+ rows.
     */
    private static function dedupe_log_table(): void {
        global $wpdb;
        $table = self::table_name();

        // Table name is built from $wpdb->prefix + a constant, not
        // user input — safe to interpolate.
        $sql = "DELETE older
                FROM {$table} AS older
                INNER JOIN {$table} AS newer
                    ON older.attachment_id = newer.attachment_id
                   AND (
                        older.processed_at < newer.processed_at
                        OR ( older.processed_at = newer.processed_at AND older.id < newer.id )
                   )";

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $deleted = $wpdb->query( $sql );

        Logger::trace(
            'Schema::dedupe_log_table',
            [
                'table'       => $table,
                'deleted_rows' => $deleted,
                'last_error'  => (string) $wpdb->last_error,
            ]
        );
    }

    /**
     * Replace the legacy non-unique KEY attachment_id with a UNIQUE
     * KEY of the same name. dbDelta refuses to do this swap, so we
     * issue the DDL explicitly. Both operations are conditional:
     * fresh v3 installs already have the unique index from
     * install(), and re-running this method is a no-op.
     */
    private static function swap_attachment_id_index_to_unique(): void {
        global $wpdb;
        $table = self::table_name();

        $existing = $wpdb->get_results(
            $wpdb->prepare(
                "SHOW INDEX FROM {$table} WHERE Key_name = %s",
                self::INDEX_ATTACHMENT_ID
            )
        );

        $has_any_index = is_array( $existing ) && $existing !== [];
        $is_unique     = $has_any_index && (int) ( $existing[0]->Non_unique ?? 1 ) === 0;

        if ( $is_unique ) {
            Logger::trace( 'Schema::swap_attachment_id_index_to_unique already unique, skipping' );
            return;
        }

        if ( $has_any_index ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->query( "ALTER TABLE {$table} DROP INDEX " . self::INDEX_ATTACHMENT_ID );
            Logger::trace( 'Schema::swap_attachment_id_index_to_unique dropped non-unique index', [
                'last_error' => (string) $wpdb->last_error,
            ] );
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY " . self::INDEX_ATTACHMENT_ID . ' (attachment_id)' );
        Logger::trace( 'Schema::swap_attachment_id_index_to_unique added unique index', [
            'last_error' => (string) $wpdb->last_error,
        ] );
    }

    public static function drop(): void {
        global $wpdb;
        $table = self::table_name();
        // Table name is constructed from $wpdb->prefix + a constant suffix, not user input.
        $wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    }
}
