<?php
/**
 * Enqueues admin CSS and JS for the Compressly settings page.
 *
 * Loading is gated on the page hook suffix so frontend pages and
 * unrelated admin screens never pull our assets. The bundle is
 * vanilla JS plus hand-written CSS with no jQuery dependency, in line
 * with the spec's <50 KB combined target.
 *
 * @package GoodAgency\Compressly
 */

declare(strict_types=1);

namespace GoodAgency\Compressly\Admin;

use GoodAgency\Compressly\Bulk\BulkPage;
use GoodAgency\Compressly\Bulk\BulkProcessor;

final class AssetManager {

    public const SETTINGS_HOOK = 'settings_page_compressly';

    private const HANDLE_STYLE          = 'compressly-admin';
    private const HANDLE_SCRIPT_TABS    = 'compressly-admin-tabs';
    private const HANDLE_SCRIPT_BULK    = 'compressly-bulk-processor';

    public function register(): void {
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
    }

    public function enqueue( string $hook_suffix ): void {
        if ( $hook_suffix === self::SETTINGS_HOOK ) {
            $this->enqueue_shared_style();
            $this->enqueue_settings_script();
            return;
        }

        if ( $hook_suffix === BulkPage::PAGE_HOOK_SUFFIX ) {
            $this->enqueue_shared_style();
            $this->enqueue_bulk_script();
            return;
        }
    }

    private function enqueue_shared_style(): void {
        wp_enqueue_style(
            self::HANDLE_STYLE,
            $this->base_url() . 'assets/css/admin.css',
            [],
            $this->version()
        );
    }

    private function enqueue_settings_script(): void {
        wp_enqueue_script(
            self::HANDLE_SCRIPT_TABS,
            $this->base_url() . 'assets/js/admin.js',
            [],
            $this->version(),
            true
        );
    }

    private function enqueue_bulk_script(): void {
        wp_enqueue_script(
            self::HANDLE_SCRIPT_BULK,
            $this->base_url() . 'assets/js/bulk-processor.js',
            [],
            $this->version(),
            true
        );

        wp_localize_script(
            self::HANDLE_SCRIPT_BULK,
            'compresslyBulk',
            [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( BulkProcessor::NONCE_ACTION ),
                'strings' => [
                    'idle'           => __( 'Idle', 'compressly' ),
                    'running'        => __( 'Running…', 'compressly' ),
                    'paused'         => __( 'Paused', 'compressly' ),
                    'complete'       => __( 'Complete', 'compressly' ),
                    'startError'     => __( 'Could not start the bulk run.', 'compressly' ),
                    'batchError'     => __( 'Batch failed; pausing the run.', 'compressly' ),
                    'restorePrompt'  => __( 'Enter an attachment ID first.', 'compressly' ),
                    'restoreSuccess' => __( 'Restored %1$d files (skipped %2$d, errors %3$d).', 'compressly' ),
                    'restoreError'   => __( 'Restore failed: %s', 'compressly' ),
                    'detail'         => __( '%1$s of %2$s', 'compressly' ),
                ],
            ]
        );
    }

    private function base_url(): string {
        return defined( 'COMPRESSLY_PLUGIN_URL' )
            ? (string) COMPRESSLY_PLUGIN_URL
            : plugin_dir_url( dirname( __DIR__, 2 ) . '/compressly.php' );
    }

    private function version(): string {
        return defined( 'COMPRESSLY_VERSION' ) ? (string) COMPRESSLY_VERSION : '1.0.0';
    }
}
