<?php
/**
 * Main plugin class.
 *
 * Bootstraps the plugin on `plugins_loaded`. Kept as a singleton so the
 * activation/deactivation hooks and the `plugins_loaded` callback all
 * resolve to the same instance. Later phases hang their service wiring
 * off of Plugin::boot().
 *
 * @package GoodAgency\Compressly
 */

declare(strict_types=1);

namespace GoodAgency\Compressly;

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

        if ( is_admin() ) {
            ( new SettingsPage( new OptionsManager() ) )->register();
        }
    }

    private function __construct() {}

    private function __clone() {}

    public function __wakeup(): void {
        throw new LogicException( 'Cannot unserialize singleton Plugin.' );
    }
}
