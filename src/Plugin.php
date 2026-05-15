<?php
/**
 * Main plugin class.
 *
 * Bootstraps the plugin on `plugins_loaded`. Kept as a singleton so the
 * activation/deactivation hooks and the `plugins_loaded` callback all
 * resolve to the same instance. Owns the top-level dependency wiring
 * for every phase — upload handler and cron (when the kill switch is
 * off), settings page, admin notices, asset enqueueing, bulk page,
 * and the bulk AJAX endpoints.
 *
 * @package GoodAgency\Compressly
 */

declare(strict_types=1);

namespace GoodAgency\Compressly;

use GoodAgency\Compressly\Admin\AssetManager;
use GoodAgency\Compressly\Admin\Notices;
use GoodAgency\Compressly\Bulk\BulkPage;
use GoodAgency\Compressly\Bulk\BulkProcessor;
use GoodAgency\Compressly\Bulk\QueueManager;
use GoodAgency\Compressly\Database\LogRepository;
use GoodAgency\Compressly\Database\Schema;
use GoodAgency\Compressly\Frontend\LazyLoad;
use GoodAgency\Compressly\Frontend\WebPServer;
use GoodAgency\Compressly\Optimization\BackupManager;
use GoodAgency\Compressly\Optimization\FileValidator;
use GoodAgency\Compressly\Optimization\Optimizer;
use GoodAgency\Compressly\Optimization\ShortPixelClient;
use GoodAgency\Compressly\Optimization\UploadHandler;
use GoodAgency\Compressly\Settings\OptionsManager;
use GoodAgency\Compressly\Settings\SettingsPage;
use LogicException;

final class Plugin {

    private static ?self $instance = null;

    private bool $booted = false;

    public static function instance(): self {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function boot(): void {
        if ( $this->booted ) {
            return;
        }
        $this->booted = true;

        load_plugin_textdomain(
            'compressly',
            false,
            dirname( plugin_basename( COMPRESSLY_PLUGIN_FILE ) ) . '/languages'
        );

        Schema::ensure_current();

        $options   = new OptionsManager();
        $log       = new LogRepository();
        $backup    = new BackupManager();
        $optimizer = $this->build_optimizer( $options, $log, $backup );

        if ( ! (bool) $options->get( 'kill_switch', false ) ) {
            ( new UploadHandler( $optimizer ) )->register();
            $optimizer->register_cron();
            $this->register_frontend( $options );
        }

        if ( is_admin() ) {
            ( new SettingsPage( $options ) )->register();
            ( new Notices() )->register();
            ( new AssetManager() )->register();
            ( new BulkPage( $options ) )->register();
            ( new BulkProcessor( new QueueManager(), $optimizer, $backup ) )->register();
        }
    }

    private function build_optimizer( OptionsManager $options, LogRepository $log, BackupManager $backup ): Optimizer {
        $validator = new FileValidator( $options );
        $client    = new ShortPixelClient( $options );
        return new Optimizer( $options, $validator, $backup, $client, $log );
    }

    /**
     * Registers front-end output filters (lazy loading, WebP delivery).
     *
     * Both features are opt-in via settings and hook registration is
     * conditional so a site with both disabled pays zero filter cost
     * on the front-end render path. Admin requests and other non-
     * frontend contexts (REST API, cron, CLI) are skipped because the
     * output filters only matter for HTML the user sees.
     */
    private function register_frontend( OptionsManager $options ): void {
        if ( is_admin() || wp_doing_cron() || wp_doing_ajax() ) {
            return;
        }
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return;
        }
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            return;
        }

        $lazy_enabled = (bool) $options->get( 'lazy_load_enabled', true );
        $webp_enabled = (bool) $options->get( 'webp_enabled', true );

        if ( ! $lazy_enabled && ! $webp_enabled ) {
            return;
        }

        if ( $lazy_enabled ) {
            $skip = (int) $options->get( 'lazy_load_skip_count', 1 );
            ( new LazyLoad( $skip ) )->register();
        }

        if ( $webp_enabled ) {
            ( new WebPServer() )->register();
        }
    }

    private function __construct() {}

    private function __clone() {}

    public function __wakeup(): void {
        throw new LogicException( 'Cannot unserialize singleton Plugin.' );
    }
}
