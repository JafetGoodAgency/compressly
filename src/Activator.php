<?php
/**
 * Plugin activation handler.
 *
 * Runs once when the plugin is activated. Creates the custom log table
 * and seeds default options. Failures are caught and logged so the
 * activation never produces a WSOD.
 *
 * @package GoodAgency\Compressly
 */

declare(strict_types=1);

namespace GoodAgency\Compressly;

use GoodAgency\Compressly\Database\Schema;
use GoodAgency\Compressly\Settings\Defaults;
use GoodAgency\Compressly\Settings\OptionsManager;
use GoodAgency\Compressly\Support\Logger;
use Throwable;

final class Activator {

    public static function activate(): void {
        try {
            Schema::install();
            update_option( Schema::VERSION_OPTION, Schema::DB_VERSION, true );
        } catch ( Throwable $e ) {
            Logger::error( 'Schema installation failed during activation: ' . $e->getMessage() );
        }

        try {
            ( new OptionsManager() )->seed_defaults( Defaults::all() );
        } catch ( Throwable $e ) {
            Logger::error( 'Seeding default options failed during activation: ' . $e->getMessage() );
        }
    }
}
