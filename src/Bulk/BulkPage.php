<?php
/**
 * Renders the Media → Compressly bulk optimization page.
 *
 * The page is purely a shell of static markup with data-* hooks; all
 * dynamic state is fetched and rendered by assets/js/bulk-processor.js
 * via the BulkProcessor AJAX endpoints. Kill switch is checked on
 * render so the page doesn't pretend bulk is available when the
 * plugin is paused.
 *
 * @package GoodAgency\Compressly
 */

declare(strict_types=1);

namespace GoodAgency\Compressly\Bulk;

use GoodAgency\Compressly\Settings\OptionsManager;
use GoodAgency\Compressly\Settings\SettingsPage;
use GoodAgency\Compressly\Support\Security;

final class BulkPage {

    public const MENU_SLUG        = 'compressly-bulk';
    public const PAGE_HOOK_SUFFIX = 'media_page_compressly-bulk';

    private OptionsManager $options;

    public function __construct( OptionsManager $options ) {
        $this->options = $options;
    }

    public function register(): void {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
    }

    public function add_menu(): void {
        add_media_page(
            __( 'Compressly Bulk Optimization', 'compressly' ),
            __( 'Compressly', 'compressly' ),
            Security::BULK_CAPABILITY,
            self::MENU_SLUG,
            [ $this, 'render' ]
        );
    }

    public function render(): void {
        if ( ! current_user_can( Security::BULK_CAPABILITY ) ) {
            return;
        }

        echo '<div class="wrap compressly-bulk">';
        echo '<h1>' . esc_html__( 'Compressly Bulk Optimization', 'compressly' ) . '</h1>';

        if ( (bool) $this->options->get( 'kill_switch', false ) ) {
            $settings_url = admin_url( 'options-general.php?page=' . SettingsPage::MENU_SLUG );
            echo '<div class="notice notice-warning"><p>';
            printf(
                /* translators: %s: link to the Compressly settings page. */
                esc_html__( 'The kill switch is active. Disable it under %s to use bulk optimization.', 'compressly' ),
                '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings → Compressly', 'compressly' ) . '</a>'
            );
            echo '</p></div></div>';
            return;
        }

        ?>
        <div class="compressly-stats-grid">
            <div class="compressly-stat-card">
                <div class="compressly-stat-value" data-compressly-stat="total">—</div>
                <div class="compressly-stat-label"><?php esc_html_e( 'Total Images', 'compressly' ); ?></div>
            </div>
            <div class="compressly-stat-card">
                <div class="compressly-stat-value" data-compressly-stat="optimized">—</div>
                <div class="compressly-stat-label"><?php esc_html_e( 'Optimized', 'compressly' ); ?></div>
            </div>
            <div class="compressly-stat-card">
                <div class="compressly-stat-value" data-compressly-stat="pending">—</div>
                <div class="compressly-stat-label"><?php esc_html_e( 'Pending', 'compressly' ); ?></div>
            </div>
            <div class="compressly-stat-card">
                <div class="compressly-stat-value" data-compressly-stat="failed">0</div>
                <div class="compressly-stat-label"><?php esc_html_e( 'Failed (this run)', 'compressly' ); ?></div>
            </div>
            <div class="compressly-stat-card">
                <div class="compressly-stat-value" data-compressly-stat="bytes_saved">—</div>
                <div class="compressly-stat-label"><?php esc_html_e( 'Bytes Saved', 'compressly' ); ?></div>
            </div>
        </div>

        <div class="compressly-progress-card">
            <div class="compressly-progress-ring" aria-hidden="true">
                <svg viewBox="0 0 80 80" width="80" height="80">
                    <circle class="compressly-progress-ring-bg" cx="40" cy="40" r="34" />
                    <circle class="compressly-progress-ring-fg" cx="40" cy="40" r="34" data-compressly-ring />
                </svg>
                <div class="compressly-progress-percent" data-compressly-percent>0%</div>
            </div>
            <div class="compressly-progress-info">
                <p class="compressly-progress-status" data-compressly-status><?php esc_html_e( 'Idle', 'compressly' ); ?></p>
                <p class="compressly-progress-detail" data-compressly-detail></p>
            </div>
        </div>

        <div class="compressly-controls">
            <button type="button" class="button button-primary" data-compressly-action="start">
                <?php esc_html_e( 'Start Bulk Optimization', 'compressly' ); ?>
            </button>
            <button type="button" class="button" data-compressly-action="pause" hidden>
                <?php esc_html_e( 'Pause', 'compressly' ); ?>
            </button>
            <button type="button" class="button" data-compressly-action="resume" hidden>
                <?php esc_html_e( 'Resume', 'compressly' ); ?>
            </button>
            <button type="button" class="button button-link-delete" data-compressly-action="cancel" hidden>
                <?php esc_html_e( 'Cancel', 'compressly' ); ?>
            </button>
        </div>

        <details class="compressly-card compressly-errors">
            <summary>
                <strong><?php esc_html_e( 'Recent errors', 'compressly' ); ?></strong>
                (<span data-compressly-error-count>0</span>)
            </summary>
            <ul class="compressly-error-list" data-compressly-error-list></ul>
            <button type="button" class="button" data-compressly-action="retry-failed" hidden>
                <?php esc_html_e( 'Clear failed list and retry', 'compressly' ); ?>
            </button>
        </details>

        <details class="compressly-card compressly-restore">
            <summary>
                <strong><?php esc_html_e( 'Restore originals from backup', 'compressly' ); ?></strong>
            </summary>
            <p class="description"><?php esc_html_e( 'Enter an attachment ID to restore its files from /wp-content/uploads/compressly-backup/. The optimization meta is cleared so the attachment can be re-processed if needed.', 'compressly' ); ?></p>
            <p>
                <label for="compressly-restore-id" class="screen-reader-text"><?php esc_html_e( 'Attachment ID', 'compressly' ); ?></label>
                <input type="number" id="compressly-restore-id" min="1" placeholder="<?php esc_attr_e( 'Attachment ID', 'compressly' ); ?>" data-compressly-restore-id />
                <button type="button" class="button" data-compressly-action="restore">
                    <?php esc_html_e( 'Restore', 'compressly' ); ?>
                </button>
            </p>
            <p class="compressly-restore-output" data-compressly-restore-output aria-live="polite"></p>
        </details>
        <?php

        echo '</div>';
    }
}
