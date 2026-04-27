<?php
/**
 * AJAX endpoints that drive the bulk optimization page.
 *
 * Eight endpoints, all admin-only and gated on:
 *   * the compressly_bulk nonce (check_ajax_referer)
 *   * the upload_files capability (per spec)
 *
 * Each endpoint is a thin shell that delegates to QueueManager,
 * Optimizer, or BackupManager and returns JSON with the latest state
 * and library stats so the JS can re-render without an extra round
 * trip.
 *
 * @package GoodAgency\Compressly
 */

declare(strict_types=1);

namespace GoodAgency\Compressly\Bulk;

use GoodAgency\Compressly\Optimization\BackupManager;
use GoodAgency\Compressly\Optimization\Optimizer;
use GoodAgency\Compressly\Support\Logger;
use GoodAgency\Compressly\Support\Security;
use Throwable;

final class BulkProcessor {

    public const NONCE_ACTION = 'compressly_bulk';
    public const BATCH_SIZE   = 5;

    private QueueManager $queue;
    private Optimizer $optimizer;
    private BackupManager $backup;

    public function __construct( QueueManager $queue, Optimizer $optimizer, BackupManager $backup ) {
        $this->queue     = $queue;
        $this->optimizer = $optimizer;
        $this->backup    = $backup;
    }

    public function register(): void {
        add_action( 'wp_ajax_compressly_bulk_stats',         [ $this, 'ajax_stats' ] );
        add_action( 'wp_ajax_compressly_bulk_start',         [ $this, 'ajax_start' ] );
        add_action( 'wp_ajax_compressly_bulk_pause',         [ $this, 'ajax_pause' ] );
        add_action( 'wp_ajax_compressly_bulk_resume',        [ $this, 'ajax_resume' ] );
        add_action( 'wp_ajax_compressly_bulk_cancel',        [ $this, 'ajax_cancel' ] );
        add_action( 'wp_ajax_compressly_bulk_process_batch', [ $this, 'ajax_process_batch' ] );
        add_action( 'wp_ajax_compressly_bulk_retry_failed',  [ $this, 'ajax_retry_failed' ] );
        add_action( 'wp_ajax_compressly_bulk_restore',       [ $this, 'ajax_restore' ] );
    }

    public function ajax_stats(): void {
        $this->guard();
        wp_send_json_success( $this->snapshot() );
    }

    public function ajax_start(): void {
        $this->guard();
        $this->queue->start();
        wp_send_json_success( $this->snapshot() );
    }

    public function ajax_pause(): void {
        $this->guard();
        $this->queue->set_status( QueueManager::STATUS_PAUSED );
        wp_send_json_success( $this->snapshot() );
    }

    public function ajax_resume(): void {
        $this->guard();
        $this->queue->set_status( QueueManager::STATUS_RUNNING );
        wp_send_json_success( $this->snapshot() );
    }

    public function ajax_cancel(): void {
        $this->guard();
        $this->queue->reset();
        wp_send_json_success( $this->snapshot() );
    }

    public function ajax_retry_failed(): void {
        $this->guard();
        $this->queue->clear_failed();
        wp_send_json_success( $this->snapshot() );
    }

    public function ajax_process_batch(): void {
        $this->guard();

        Logger::trace(
            'BulkProcessor::ajax_process_batch enter',
            [
                'user_id'    => get_current_user_id(),
                'batch_size' => self::BATCH_SIZE,
            ]
        );

        $state = $this->queue->get_state();
        $batch = [
            'attempted' => 0,
            'processed' => 0,
            'failed'    => 0,
            'skipped'   => 0,
            'noop'      => 0,
            'ids'       => [],
        ];

        Logger::trace(
            'BulkProcessor::ajax_process_batch state at entry',
            [
                'status'         => $state['status'] ?? null,
                'processed'      => $state['processed'] ?? null,
                'failed'         => $state['failed'] ?? null,
                'skipped'        => $state['skipped'] ?? null,
                'total_at_start' => $state['total_at_start'] ?? null,
            ]
        );

        if ( $state['status'] !== QueueManager::STATUS_RUNNING ) {
            Logger::trace( 'BulkProcessor::ajax_process_batch early return: not running' );
            wp_send_json_success( $this->snapshot( $batch ) );
            return;
        }

        $ids = $this->queue->next_batch_ids( self::BATCH_SIZE );
        Logger::trace(
            'BulkProcessor::ajax_process_batch ids',
            [
                'ids'   => $ids,
                'count' => count( $ids ),
            ]
        );

        if ( $ids === [] ) {
            Logger::trace( 'BulkProcessor::ajax_process_batch empty queue → transition_to_complete' );
            $this->queue->transition_to_complete();
            wp_send_json_success( $this->snapshot( $batch ) );
            return;
        }

        foreach ( $ids as $id ) {
            $batch['ids'][] = $id;
            $batch['attempted']++;

            try {
                $result = $this->optimizer->optimize_attachment( $id );
            } catch ( Throwable $e ) {
                Logger::error( sprintf( 'BulkProcessor unhandled error for %d: %s', $id, $e->getMessage() ) );
                $this->queue->record_failed( $id, $e->getMessage() );
                $batch['failed']++;
                continue;
            }

            $status = (string) ( $result['status'] ?? 'noop' );
            $error  = isset( $result['error'] ) ? (string) $result['error'] : '';

            // Read post_meta after the optimizer returns so we can
            // verify (a) META_OPTIMIZED was actually set when status
            // is success/partial, and (b) META_VERSION matches the
            // current plugin version. Mismatches here mean
            // optimize_attachment claimed success but did not finish
            // its meta updates.
            $meta_optimized = get_post_meta( $id, '_compressly_optimized', true );
            $meta_version   = get_post_meta( $id, '_compressly_version', true );

            Logger::trace(
                'BulkProcessor::ajax_process_batch result',
                [
                    'id'             => $id,
                    'status'         => $status,
                    'error'          => $error,
                    'meta_optimized' => $meta_optimized,
                    'meta_version'   => $meta_version,
                ]
            );

            switch ( $status ) {
                case 'success':
                case 'partial':
                    $this->queue->record_processed( $id );
                    $batch['processed']++;
                    break;

                case 'failed':
                    $this->queue->record_failed( $id, $error !== '' ? $error : 'Unknown error' );
                    $batch['failed']++;
                    break;

                case 'skipped':
                    $this->queue->record_skipped( $id );
                    $batch['skipped']++;
                    break;

                case 'noop':
                default:
                    // Idempotent short-circuit (e.g. already optimized
                    // at current version). Don't move counters; the
                    // next batch query will skip past this ID since
                    // META_OPTIMIZED is set.
                    $batch['noop']++;
                    break;
            }
        }

        Logger::trace(
            'BulkProcessor::ajax_process_batch exit',
            [
                'batch' => $batch,
            ]
        );

        wp_send_json_success( $this->snapshot( $batch ) );
    }

    public function ajax_restore(): void {
        $this->guard();

        $attachment_id = isset( $_POST['attachment_id'] ) ? absint( wp_unslash( $_POST['attachment_id'] ) ) : 0;
        if ( $attachment_id <= 0 ) {
            wp_send_json_error( [ 'message' => __( 'Invalid attachment ID.', 'compressly' ) ], 400 );
        }

        try {
            $result = $this->backup->restore( $attachment_id );
        } catch ( Throwable $e ) {
            Logger::error( sprintf( 'BulkProcessor restore failed for %d: %s', $attachment_id, $e->getMessage() ) );
            wp_send_json_error( [ 'message' => $e->getMessage() ], 500 );
        }

        wp_send_json_success(
            [
                'restore' => [
                    'attachment_id' => $attachment_id,
                    'restored'      => count( $result['restored'] ),
                    'skipped'       => count( $result['skipped'] ),
                    'errors'        => $result['errors'],
                ],
                'state'   => $this->queue->get_state(),
                'stats'   => $this->queue->get_stats(),
            ]
        );
    }

    private function guard(): void {
        check_ajax_referer( self::NONCE_ACTION, '_wpnonce' );
        if ( ! current_user_can( Security::BULK_CAPABILITY ) ) {
            wp_send_json_error( [ 'message' => __( 'You do not have permission to run bulk optimization.', 'compressly' ) ], 403 );
        }
    }

    /**
     * @param array<string, mixed>|null $batch
     * @return array<string, mixed>
     */
    private function snapshot( ?array $batch = null ): array {
        $payload = [
            'state' => $this->queue->get_state(),
            'stats' => $this->queue->get_stats(),
        ];
        if ( $batch !== null ) {
            $payload['batch'] = $batch;
        }
        return $payload;
    }
}
