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

final class AssetManager {

    public const PAGE_HOOK_SUFFIX = 'settings_page_compressly';

    private const HANDLE_STYLE  = 'compressly-admin';
    private const HANDLE_SCRIPT = 'compressly-admin';

    public function register(): void {
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
    }

    public function enqueue( string $hook_suffix ): void {
        if ( $hook_suffix !== self::PAGE_HOOK_SUFFIX ) {
            return;
        }

        $version = defined( 'COMPRESSLY_VERSION' ) ? (string) COMPRESSLY_VERSION : '1.0.0';
        $base    = defined( 'COMPRESSLY_PLUGIN_URL' ) ? (string) COMPRESSLY_PLUGIN_URL : plugin_dir_url( dirname( __DIR__, 2 ) . '/compressly.php' );

        wp_enqueue_style(
            self::HANDLE_STYLE,
            $base . 'assets/css/admin.css',
            [],
            $version
        );

        wp_enqueue_script(
            self::HANDLE_SCRIPT,
            $base . 'assets/js/admin.js',
            [],
            $version,
            true
        );
    }
}
