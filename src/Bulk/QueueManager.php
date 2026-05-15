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

use GoodAgency\Compressly\Database\LogRepository;
use GoodAgency\Compressly\Database\Schema;
use GoodAgency\Compressly\Support\Logger;

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

        Logger::trace(
            'QueueManager::start computed',
            [
                'pending' => $pending,
                'status'  => $state['status'],
            ]
        );

        $this->persist( $state );

        // Read back to confirm the option actually persisted. If it
        // did not, get_option returns false and we know the write
        // dropped silently somewhere downstream of update_option.
        $verified = get_option( self::STATE_OPTION, '__missing__' );
        Logger::trace(
            'QueueManager::start verified',
            [
                'option_name'    => self::STATE_OPTION,
                'option_present' => $verified !== '__missing__',
                'verified_type'  => gettype( $verified ),
            ]
        );

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

        // Eligibility for the next bulk batch is keyed off the log
        // table, so the in-state failed list is not enough — we also
        // delete the failed log rows so next_batch_ids() will
        // surface those attachments again on retry.
        $this->delete_failed_log_rows();
    }

    private function delete_failed_log_rows(): void {
        global $wpdb;
        $table   = Schema::table_name();
        $version = $this->current_plugin_version();

        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE status = %s AND plugin_version = %s",
                LogRepository::STATUS_FAILED,
                $version
            )
        );

        Logger::trace(
            'QueueManager::delete_failed_log_rows',
            [
                'deleted'    => $deleted,
                'version'    => $version,
                'last_error' => (string) $wpdb->last_error,
            ]
        );
    }

    public function transition_to_complete(): void {
        $state           = $this->get_state();
        $state['status'] = self::STATUS_COMPLETE;
        $this->persist( $state );
    }

    /**
     * Next batch of eligible attachments.
     *
     * Pagination is keyed off the compressly_log table — an attachment
     * is eligible iff it has no log row at the CURRENT plugin
     * version. This is the durable "has been seen by Compressly"
     * registry across runs and across statuses (success, partial,
     * failed, skipped all leave a row), so re-running the bulk loop
     * cannot re-select an attachment we already attempted. The old
     * postmeta-based query would re-select skipped attachments
     * forever because skipped paths never set _compressly_optimized
     * — that's the bug that returned the same 5 IDs every batch.
     *
     * @return int[]
     */
    public function next_batch_ids( int $size ): array {
        $ids = $this->query_eligible_ids( $size );

        Logger::trace(
            'QueueManager::next_batch_ids',
            [
                'requested_size' => $size,
                'returned_ids'   => $ids,
                'returned_count' => count( $ids ),
            ]
        );

        return $ids;
    }

    /**
     * Dashboard stats.
     *
     * "Optimized" and "Failed" are derived from the compressly_log
     * audit table (most recent row per attachment) instead of the
     * queue counters or post_meta. The queue option resets per run,
     * so reading from it produced "Failed (this run)" semantics and
     * could drift past the totals — see the "335 of 159" incident.
     * The log table is the durable source of truth across runs.
     *
     * @return array{total:int, optimized:int, pending:int, failed:int, bytes_saved:int}
     */
    public function get_stats(): array {
        $total    = $this->count_total_images();
        $by_state = $this->count_by_latest_log_status();

        $optimized = (int) ( $by_state['optimized'] ?? 0 );
        $failed    = (int) ( $by_state['failed'] ?? 0 );
        $pending   = max( 0, $total - $optimized - $failed );

        return [
            'total'       => $total,
            'optimized'   => $optimized,
            'pending'     => $pending,
            'failed'      => $failed,
            'bytes_saved' => $this->compute_bytes_saved(),
        ];
    }

    /**
     * Completion is "there are no more eligible attachment IDs to
     * fetch" — NOT a counter threshold. Counters can stall if the
     * optimizer returns noop for an attachment (meta says optimized
     * but log row missing) or if an exception path forgets to bump
     * them; tying completion to eligibility makes the bulk run
     * truly bounded by the durable log table. The previous
     * counter-based check could either declare completion early
     * (counter math drifts past total_at_start) or never (5
     * attachments looping never advanced counters).
     *
     * @param array<string, mixed> $state Unused — retained for
     *                                    backwards-compatible signature.
     */
    public function is_run_complete( array $state = [] ): bool {
        unset( $state );
        return $this->count_eligible_attachments() === 0;
    }

    /**
     * Public so callers (BulkPage future polish, dashboard widgets)
     * can render the "X attachments remaining" count without
     * duplicating the SQL.
     */
    public function count_eligible_attachments(): int {
        global $wpdb;
        $log_table = Schema::table_name();
        $version   = $this->current_plugin_version();

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $sql = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             LEFT JOIN {$log_table} l
                 ON l.attachment_id = p.ID
                AND l.plugin_version = %s
             WHERE p.post_type = 'attachment'
               AND p.post_mime_type LIKE %s
               AND l.id IS NULL",
            $version,
            'image/%'
        );

        return (int) $wpdb->get_var( $sql );
    }

    private function current_plugin_version(): string {
        return defined( 'COMPRESSLY_VERSION' ) ? (string) COMPRESSLY_VERSION : '';
    }

    // ---------- Internals ----------

    private function persist( array $state ): void {
        // autoload=false: this option may grow as failures accumulate;
        // we only want it loaded when the bulk page or its AJAX
        // endpoints actually need it.
        $result = update_option( self::STATE_OPTION, $state, false );
        Logger::trace(
            'QueueManager::persist',
            [
                'option_name'   => self::STATE_OPTION,
                'update_result' => $result,
                'status'        => $state['status'] ?? null,
                'processed'     => $state['processed'] ?? null,
                'failed'        => $state['failed'] ?? null,
                'skipped'       => $state['skipped'] ?? null,
            ]
        );
    }

    private function count_total_images(): int {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = 'attachment'
               AND post_mime_type LIKE 'image/%'"
        );
    }

    /**
     * Bucket every attachment in the log table by its MOST RECENT
     * status, so re-runs that flipped a previously-failed attachment
     * to success move it out of the "failed" bucket. Returns the
     * counts the dashboard cards need in a single query.
     *
     * @return array{optimized:int, failed:int}
     */
    private function count_by_latest_log_status(): array {
        global $wpdb;
        $table = Schema::table_name();

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT
                    SUM( CASE WHEN l.status IN (%s, %s, %s) THEN 1 ELSE 0 END ) AS optimized,
                    SUM( CASE WHEN l.status = %s THEN 1 ELSE 0 END ) AS failed
                 FROM {$table} l
                 INNER JOIN (
                    SELECT attachment_id, MAX(id) AS latest_id
                    FROM {$table}
                    GROUP BY attachment_id
                 ) t ON t.latest_id = l.id",
                LogRepository::STATUS_SUCCESS,
                LogRepository::STATUS_PARTIAL,
                LogRepository::STATUS_SKIPPED,
                LogRepository::STATUS_FAILED
            )
        );

        if ( ! is_object( $row ) ) {
            return [ 'optimized' => 0, 'failed' => 0 ];
        }

        return [
            'optimized' => (int) ( $row->optimized ?? 0 ),
            'failed'    => (int) ( $row->failed ?? 0 ),
        ];
    }

    /**
     * Pending count for total_at_start. Mirrors the eligibility
     * query in next_batch_ids() so the progress bar's denominator
     * matches the work the queue will actually serve.
     */
    private function count_pending(): int {
        return $this->count_eligible_attachments();
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
     * Pull the next chunk of attachments that have NO log row at the
     * current plugin version. Successful, partial, failed, and
     * skipped runs all leave a row, so every status terminates the
     * attachment's eligibility — pagination cannot cursor-stick on
     * the same IDs the way the postmeta-only version did when only
     * success/partial set the optimized flag.
     *
     * Plugin-version match in the JOIN means a plugin upgrade
     * re-opens eligibility for everything previously processed
     * (UPSERT updates plugin_version on the next pass), aligning the
     * bulk pipeline with the Optimizer's per-version idempotency
     * check.
     *
     * @return int[]
     */
    private function query_eligible_ids( int $size ): array {
        global $wpdb;

        $size      = max( 1, min( 50, $size ) );
        $log_table = Schema::table_name();
        $version   = $this->current_plugin_version();

        $sql = $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             LEFT JOIN {$log_table} l
                 ON l.attachment_id = p.ID
                AND l.plugin_version = %s
             WHERE p.post_type = 'attachment'
               AND p.post_mime_type LIKE %s
               AND l.id IS NULL
             ORDER BY p.ID ASC
             LIMIT %d",
            $version,
            'image/%',
            $size
        );

        $ids = $wpdb->get_col( $sql );
        return array_map( 'intval', is_array( $ids ) ? $ids : [] );
    }
}
