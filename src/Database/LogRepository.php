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
     * Insert one row describing the outcome for an attachment.
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
     * @return int Insert ID, or 0 on failure.
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

        $formats = [ '%d', '%s', '%d', '%d', '%d', '%s', '%s', '%s' ];

        $inserted = $wpdb->insert( Schema::table_name(), $row, $formats );

        Logger::trace(
            'LogRepository::record',
            [
                'attachment_id' => $row['attachment_id'],
                'status'        => $row['status'],
                'inserted'      => $inserted,
                'insert_id'     => $inserted ? (int) $wpdb->insert_id : 0,
                'last_error'    => $inserted ? '' : (string) $wpdb->last_error,
                'last_query'    => $inserted ? '' : (string) $wpdb->last_query,
            ]
        );

        return $inserted ? (int) $wpdb->insert_id : 0;
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
