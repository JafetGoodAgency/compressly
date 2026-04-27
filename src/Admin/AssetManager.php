<?php
/**
 * Enqueues admin CSS and JS for Compressly's two admin pages.
 *
 * Hook suffixes are read from the page classes at request time
 * (BulkPage::hook_suffix() / SettingsPage::hook_suffix()) instead of
 * being hardcoded — WordPress derives the suffix from runtime menu
 * state and the value can shift across WP versions and themes, so
 * capturing the return of add_*_page() is the only authoritative
 * source. Asset versioning uses file mtime so any change to admin.css
 * or the JS files busts cached copies on the next page load even
 * when COMPRESSLY_VERSION has not been bumped.
 *
 * @package GoodAgency\Compressly
 */

declare(strict_types=1);

namespace GoodAgency\Compressly\Admin;

use GoodAgency\Compressly\Bulk\BulkPage;
use GoodAgency\Compressly\Bulk\BulkProcessor;
use GoodAgency\Compressly\Settings\SettingsPage;
use GoodAgency\Compressly\Support\Logger;

final class AssetManager {

    private const HANDLE_STYLE       = 'compressly-admin';
    private const HANDLE_SCRIPT_TABS = 'compressly-admin-tabs';
    private const HANDLE_SCRIPT_BULK = 'compressly-bulk-processor';

    public function register(): void {
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
    }

    public function enqueue( string $hook_suffix ): void {
        $settings_hook = SettingsPage::hook_suffix();
        $bulk_hook     = BulkPage::hook_suffix();

        Logger::trace(
            'AssetManager::enqueue',
            [
                'hook_suffix'      => $hook_suffix,
                'settings_hook'    => $settings_hook,
                'bulk_hook'        => $bulk_hook,
            ]
        );

        if ( $settings_hook !== null && $hook_suffix === $settings_hook ) {
            $this->enqueue_shared_style();
            $this->enqueue_settings_script();
            return;
        }

        if ( $bulk_hook !== null && $hook_suffix === $bulk_hook ) {
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
            $this->asset_version( 'assets/css/admin.css' )
        );
    }

    private function enqueue_settings_script(): void {
        wp_enqueue_script(
            self::HANDLE_SCRIPT_TABS,
            $this->base_url() . 'assets/js/admin.js',
            [],
            $this->asset_version( 'assets/js/admin.js' ),
            true
        );
    }

    private function enqueue_bulk_script(): void {
        wp_enqueue_script(
            self::HANDLE_SCRIPT_BULK,
            $this->base_url() . 'assets/js/bulk-processor.js',
            [],
            $this->asset_version( 'assets/js/bulk-processor.js' ),
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

    /**
     * Use the asset file's mtime as its version string so any edit to
     * the CSS or JS bytes is enough to bust browser/CDN caches without
     * having to bump COMPRESSLY_VERSION. Falls back to the plugin
     * version when the file is unreadable for any reason.
     */
    private function asset_version( string $relative_path ): string {
        $absolute = ( defined( 'COMPRESSLY_PLUGIN_DIR' ) ? (string) COMPRESSLY_PLUGIN_DIR : '' ) . $relative_path;
        if ( $absolute !== '' && is_readable( $absolute ) ) {
            $mtime = filemtime( $absolute );
            if ( is_int( $mtime ) && $mtime > 0 ) {
                return (string) $mtime;
            }
        }
        return defined( 'COMPRESSLY_VERSION' ) ? (string) COMPRESSLY_VERSION : '1.0.0';
    }
}
