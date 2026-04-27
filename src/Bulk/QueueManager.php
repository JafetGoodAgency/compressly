<?php
/**
 * State + DB queries for the Media → Compressly bulk page.
 *
 * Holds the bulk run's status, counters, and recent failures in a
 * single non-autoloaded wp_option so reading the option is on-demand
 * (it can grow as failures accumulate). Also owns the queries that
 * pick the next batch of unoptimized image attachments and the
 * library-wide stats the dashboard renders.
 *
 * The queue does not materialize a fixed list of IDs at start time —
 * it asks the database for the next N unoptimized attachments on
 * every batch. That keeps the option small even on libraries with
 * 80k+ images and means new uploads that happen during a long bulk
 * run are picked up automatically.
 *
 * @package GoodAgency\Compressly
 */

declare(strict_types=1);

namespace GoodAgency\Compressly\Bulk;

use GoodAgency\Compressly\Optimization\Optimizer;

final class QueueManager {

    public const STATE_OPTION = 'compressly_bulk_state';

    public const STATUS_IDLE     = 'idle';
    public const STATUS_RUNNING  = 'running';
    public const STATUS_PAUSED   = 'paused';
    public const STATUS_COMPLETE = 'complete';

    private const FAILED_ITEMS_CAP = 100;

    /**
     * @return array{status:string, started_at:?int, total_at_start:int, processed:int, failed:int, skipped:int, failed_items:array<int, array{id:int, error:string, time:int}>}
     */
    public function get_state(): array {
        $stored = get_option( self::STATE_OPTION, [] );
        if ( ! is_array( $stored ) ) {
            $stored = [];
        }
        return array_merge( self::default_state(), $stored );
    }

    /**
     * @return array{status:string, started_at:null, total_at_start:int, processed:int, failed:int, skipped:int, failed_items:array<int, mixed>}
     */
    private static function default_state(): array {
        return [
            'status'         => self::STATUS_IDLE,
            'started_at'     => null,
            'total_at_start' => 0,
            'processed'      => 0,
            'failed'         => 0,
            'skipped'        => 0,
            'failed_items'   => [],
        ];
    }

    public function set_status( string $status ): void {
        $state           = $this->get_state();
        $state['status'] = $status;
        $this->persist( $state );
    }

    /**
     * Reset counters and start a new run. Returns the fresh state.
     *
     * @return array<string, mixed>
     */
    public function start(): array {
        $pending = $this->count_pending();

        $state                   = self::default_state();
        $state['status']         = $pending > 0 ? self::STATUS_RUNNING : self::STATUS_COMPLETE;
        $state['started_at']     = time();
        $state['total_at_start'] = $pending;
        $this->persist( $state );

        return $state;
    }

    public function reset(): void {
        $this->persist( self::default_state() );
    }

    public function record_processed( int $attachment_id ): void {
        $state = $this->get_state();
        $state['processed']++;
        $state['failed_items'] = array_values(
            array_filter(
                $state['failed_items'],
                static function ( $item ) use ( $attachment_id ) {
                    return ! is_array( $item ) || (int) ( $item['id'] ?? 0 ) !== $attachment_id;
                }
            )
        );
        $this->persist( $state );
    }

    public function record_skipped( int $attachment_id ): void {
        $state = $this->get_state();
        $state['skipped']++;
        $this->persist( $state );
    }

    public function record_failed( int $attachment_id, string $error ): void {
        $state = $this->get_state();
        $state['failed']++;

        $remaining = array_values(
            array_filter(
                $state['failed_items'],
                static function ( $item ) use ( $attachment_id ) {
                    return ! is_array( $item ) || (int) ( $item['id'] ?? 0 ) !== $attachment_id;
                }
            )
        );

        $remaining[] = [
            'id'    => $attachment_id,
            'error' => mb_substr( $error, 0, 500 ),
            'time'  => time(),
        ];

        // Cap so a runaway error storm cannot bloat the option indefinitely.
        if ( count( $remaining ) > self::FAILED_ITEMS_CAP ) {
            $remaining = array_slice( $remaining, -self::FAILED_ITEMS_CAP );
        }

        $state['failed_items'] = $remaining;
        $this->persist( $state );
    }

    public function clear_failed(): void {
        $state                 = $this->get_state();
        $state['failed_items'] = [];
        $state['failed']       = 0;
        $this->persist( $state );
    }

    public function transition_to_complete(): void {
        $state           = $this->get_state();
        $state['status'] = self::STATUS_COMPLETE;
        $this->persist( $state );
    }

    /**
     * @return int[]
     */
    public function next_batch_ids( int $size ): array {
        $state    = $this->get_state();
        $skip_ids = [];
        foreach ( $state['failed_items'] as $item ) {
            if ( is_array( $item ) && isset( $item['id'] ) ) {
                $skip_ids[] = (int) $item['id'];
            }
        }
        return $this->query_pending_ids( $size, $skip_ids );
    }

    /**
     * @return array{total:int, optimized:int, pending:int, bytes_saved:int}
     */
    public function get_stats(): array {
        $total     = $this->count_total_images();
        $optimized = $this->count_optimized();
        $pending   = max( 0, $total - $optimized );

        return [
            'total'       => $total,
            'optimized'   => $optimized,
            'pending'     => $pending,
            'bytes_saved' => $this->compute_bytes_saved(),
        ];
    }

    // ---------- Internals ----------

    private function persist( array $state ): void {
        // autoload=false: this option may grow as failures accumulate;
        // we only want it loaded when the bulk page or its AJAX
        // endpoints actually need it.
        update_option( self::STATE_OPTION, $state, false );
    }

    private function count_total_images(): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = 'attachment'
               AND post_mime_type LIKE 'image/%'"
        );
    }

    private function count_optimized(): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta}
                 WHERE meta_key = %s
                   AND meta_value = %s",
                Optimizer::META_OPTIMIZED,
                '1'
            )
        );
    }

    private function count_pending(): int {
        return max( 0, $this->count_total_images() - $this->count_optimized() );
    }

    private function compute_bytes_saved(): int {
        global $wpdb;
        $row = $wpdb->get_row(
            "SELECT
                COALESCE( SUM( CAST( o.meta_value AS UNSIGNED ) ), 0 ) AS original_total,
                COALESCE( SUM( CAST( p.meta_value AS UNSIGNED ) ), 0 ) AS optimized_total
             FROM {$wpdb->postmeta} o
             INNER JOIN {$wpdb->postmeta} p
                ON p.post_id = o.post_id
               AND p.meta_key = '_compressly_optimized_size'
             WHERE o.meta_key = '_compressly_original_size'"
        );
        if ( ! is_object( $row ) ) {
            return 0;
        }
        $original  = (int) ( $row->original_total ?? 0 );
        $optimized = (int) ( $row->optimized_total ?? 0 );
        return max( 0, $original - $optimized );
    }

    /**
     * @param int[] $skip_ids
     * @return int[]
     */
    private function query_pending_ids( int $size, array $skip_ids ): array {
        global $wpdb;

        $size = max( 1, min( 50, $size ) );

        $skip_ids   = array_values( array_unique( array_map( 'intval', $skip_ids ) ) );
        $skip_clause = '';
        $skip_params = [];
        if ( $skip_ids !== [] ) {
            $placeholders = implode( ',', array_fill( 0, count( $skip_ids ), '%d' ) );
            $skip_clause  = "AND p.ID NOT IN ({$placeholders})";
            $skip_params  = $skip_ids;
        }

        $sql = "SELECT p.ID FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} m
                    ON m.post_id = p.ID
                   AND m.meta_key = %s
                WHERE p.post_type = 'attachment'
                  AND p.post_mime_type LIKE 'image/%%'
                  AND m.meta_id IS NULL
                  {$skip_clause}
                ORDER BY p.ID ASC
                LIMIT %d";

        $params  = array_merge( [ Optimizer::META_OPTIMIZED ], $skip_params, [ $size ] );
        $prepared = $wpdb->prepare( $sql, $params ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

        $ids = $wpdb->get_col( $prepared );
        return array_map( 'intval', is_array( $ids ) ? $ids : [] );
    }
}
