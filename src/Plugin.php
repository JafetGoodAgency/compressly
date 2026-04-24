<?php
/**
 * Main plugin class.
 *
 * Bootstraps the plugin on `plugins_loaded`. Kept as a singleton so the
 * activation/deactivation hooks and the `plugins_loaded` callback all
 * resolve to the same instance. Owns the top-level dependency wiring
 * for every phase — upload handler (when the kill switch is off),
 * settings page, and admin notices.
 *
 * @package GoodAgency\Compressly
 */

declare(strict_types=1);

namespace GoodAgency\Compressly;

use GoodAgency\Compressly\Admin\AssetManager;
use GoodAgency\Compressly\Admin\Notices;
use GoodAgency\Compressly\Database\LogRepository;
use GoodAgency\Compressly\Database\Schema;
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

        $options = new OptionsManager();

        if ( ! (bool) $options->get( 'kill_switch', false ) ) {
            $this->register_optimization( $options );
        }

        if ( is_admin() ) {
            ( new SettingsPage( $options ) )->register();
            ( new Notices() )->register();
            ( new AssetManager() )->register();
        }
    }

    private function register_optimization( OptionsManager $options ): void {
        $validator = new FileValidator( $options );
        $backup    = new BackupManager();
        $client    = new ShortPixelClient( $options );
        $log       = new LogRepository();
        $optimizer = new Optimizer( $options, $validator, $backup, $client, $log );
        ( new UploadHandler( $optimizer ) )->register();
        $optimizer->register_cron();
    }

    private function __construct() {}

    private function __clone() {}

    public function __wakeup(): void {
        throw new LogicException( 'Cannot unserialize singleton Plugin.' );
    }
}
