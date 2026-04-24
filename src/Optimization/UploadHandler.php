<?php
/**
 * Hooks optimization into the attachment-upload lifecycle.
 *
 * Attaches to wp_generate_attachment_metadata at priority 100 so
 * WordPress has already generated every thumbnail by the time we run.
 * The filter callback never mutates the metadata — it passes the input
 * array through untouched and delegates the heavy lifting to
 * Optimizer::optimize_attachment(). All exceptions are caught here so
 * a failed optimization never breaks the surrounding upload pipeline.
 *
 * @package GoodAgency\Compressly
 */

declare(strict_types=1);

namespace GoodAgency\Compressly\Optimization;

use GoodAgency\Compressly\Support\Logger;
use Throwable;

final class UploadHandler {

    private const HOOK_PRIORITY = 100;

    private Optimizer $optimizer;

    public function __construct( Optimizer $optimizer ) {
        $this->optimizer = $optimizer;
    }

    public function register(): void {
        add_filter( 'wp_generate_attachment_metadata', [ $this, 'handle' ], self::HOOK_PRIORITY, 2 );
    }

    /**
     * @param mixed $metadata
     * @param mixed $attachment_id
     * @return mixed
     */
    public function handle( $metadata, $attachment_id ) {
        try {
            $id = is_numeric( $attachment_id ) ? (int) $attachment_id : 0;
            if ( $id > 0 ) {
                $this->optimizer->optimize_attachment( $id );
            }
        } catch ( Throwable $e ) {
            Logger::error( 'UploadHandler caught unhandled error: ' . $e->getMessage() );
        }
        return $metadata;
    }
}
