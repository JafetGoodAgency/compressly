<?php
/**
 * Thin wrapper around the single autoloaded wp_option that stores every
 * Compressly setting.
 *
 * Keeps get/set logic in one place so callers never touch wp_options
 * directly and every read is guaranteed to fall back to the documented
 * defaults.
 *
 * @package GoodAgency\Compressly
 */

declare(strict_types=1);

namespace GoodAgency\Compressly\Settings;

final class OptionsManager {

    /**
     * @return array<string, mixed>
     */
    public function all(): array {
        $stored = get_option( Defaults::OPTION_NAME, [] );
        if ( ! is_array( $stored ) ) {
            $stored = [];
        }
        return array_merge( Defaults::all(), $stored );
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public function get( string $key, $default = null ) {
        $all = $this->all();
        return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
    }

    /**
     * @param mixed $value
     */
    public function set( string $key, $value ): bool {
        $all         = $this->all();
        $all[ $key ] = $value;
        return update_option( Defaults::OPTION_NAME, $all );
    }

    /**
     * Merge the given defaults with whatever is already stored. Existing
     * values always win so re-activating the plugin never resets a
     * user's configuration.
     *
     * @param array<string, mixed> $defaults
     */
    public function seed_defaults( array $defaults ): bool {
        $existing = get_option( Defaults::OPTION_NAME, null );

        if ( ! is_array( $existing ) ) {
            return (bool) update_option( Defaults::OPTION_NAME, $defaults, true );
        }

        $merged = array_merge( $defaults, $existing );
        if ( $merged === $existing ) {
            return true;
        }

        return (bool) update_option( Defaults::OPTION_NAME, $merged, true );
    }
}
