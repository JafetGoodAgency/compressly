<?php
/**
 * Persistence layer for the compressly_log audit table.
 *
 * Provides typed insert and aggregate-query helpers so the rest of the
 * plugin never touches $wpdb directly. Phase 2 only needs insert
 * support; the dashboard widgets in Phase 7 extend this with query
 * methods against the same table.
 *
 * @package GoodAgency\Compressly
 */

declare(strict_types=1);

namespace GoodAgency\Compressly\Database;

use GoodAgency\Compressly\Support\Logger;

final class LogRepository {

    public const STATUS_PENDING = 'pending';
    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED  = 'failed';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_PARTIAL = 'partial';

    /**
     * Upsert one row describing the outcome for an attachment.
     *
     * Schema v3 made attachment_id UNIQUE, so each attachment has at
     * most one log row. A re-run for the same attachment updates the
     * existing row in place (preserving its id) rather than appending
     * a new row. This keeps the latest-status-per-attachment stats
     * queries honest and prevents the runaway-auto-resume regression
     * from re-inflating the table.
     *
     * Implemented as SELECT-then-INSERT/UPDATE so the existing
     * $wpdb->insert/update format-string API handles nullable
     * columns (webp_size, error_message) cleanly. A unique-key race
     * between the SELECT and INSERT is recovered by re-reading and
     * UPDATEing the row that won the race.
     *
     * @param array{
     *     attachment_id: int,
     *     status: string,
     *     original_size?: int,
     *     optimized_size?: int,
     *     webp_size?: int|null,
     *     error_message?: string|null,
     *     plugin_version?: string,
     * } $data
     * @return int Row id, or 0 on failure.
     */
    public function record( array $data ): int {
        global $wpdb;

        $row = [
            'attachment_id'  => (int) ( $data['attachment_id'] ?? 0 ),
            'status'         => self::normalize_status( $data['status'] ?? self::STATUS_PENDING ),
            'original_size'  => (int) ( $data['original_size'] ?? 0 ),
            'optimized_size' => (int) ( $data['optimized_size'] ?? 0 ),
            'webp_size'      => isset( $data['webp_size'] ) ? (int) $data['webp_size'] : null,
            'error_message'  => isset( $data['error_message'] ) ? (string) $data['error_message'] : null,
            'processed_at'   => gmdate( 'Y-m-d H:i:s' ),
            'plugin_version' => (string) ( $data['plugin_version'] ?? ( defined( 'COMPRESSLY_VERSION' ) ? COMPRESSLY_VERSION : '' ) ),
        ];

        if ( $row['attachment_id'] <= 0 ) {
            return 0;
        }

        $formats   = [ '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%s' ];
        $table     = Schema::table_name();
        $existing  = $this->find_existing_id( $table, $row['attachment_id'] );
        $row_id    = 0;
        $operation = '';

        if ( $existing > 0 ) {
            $updated   = $wpdb->update( $table, $row, [ 'id' => $existing ], $formats, [ '%d' ] );
            $row_id    = $updated !== false ? $existing : 0;
            $operation = 'update';
        } else {
            $inserted = $wpdb->insert( $table, $row, $formats );
            if ( $inserted ) {
                $row_id    = (int) $wpdb->insert_id;
                $operation = 'insert';
            } else {
                // Race recovery: another writer slipped in between
                // our SELECT and INSERT and won the unique key. Read
                // it back and update in place so the most recent
                // outcome still gets persisted.
                $retry = $this->find_existing_id( $table, $row['attachment_id'] );
                if ( $retry > 0 ) {
                    $wpdb->update( $table, $row, [ 'id' => $retry ], $formats, [ '%d' ] );
                    $row_id    = $retry;
                    $operation = 'race-update';
                }
            }
        }

        Logger::trace(
            'LogRepository::record',
            [
                'attachment_id' => $row['attachment_id'],
                'status'        => $row['status'],
                'operation'     => $operation,
                'row_id'        => $row_id,
                'last_error'    => $row_id > 0 ? '' : (string) $wpdb->last_error,
                'last_query'    => $row_id > 0 ? '' : (string) $wpdb->last_query,
            ]
        );

        return $row_id;
    }

    private function find_existing_id( string $table, int $attachment_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE attachment_id = %d LIMIT 1",
                $attachment_id
            )
        );
    }

    private static function normalize_status( string $status ): string {
        $allowed = [
            self::STATUS_PENDING,
            self::STATUS_SUCCESS,
            self::STATUS_FAILED,
            self::STATUS_SKIPPED,
            self::STATUS_PARTIAL,
        ];
        return in_array( $status, $allowed, true ) ? $status : self::STATUS_PENDING;
    }
}
