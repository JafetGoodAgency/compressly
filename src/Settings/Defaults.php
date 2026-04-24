<?php
/**
 * Default values for all Compressly settings.
 *
 * Centralises the default map so the activator can seed every option on
 * install and future settings-page phases can reference a single source
 * of truth. All options are stored inside one autoloaded wp_option
 * array keyed by self::OPTION_NAME.
 *
 * @package GoodAgency\Compressly
 */

declare(strict_types=1);

namespace GoodAgency\Compressly\Settings;

final class Defaults {

    public const OPTION_NAME = 'compressly_settings';

    public const COMPRESSION_LOSSLESS = 'lossless';
    public const COMPRESSION_LOSSY    = 'lossy';
    public const COMPRESSION_GLOSSY   = 'glossy';

    /**
     * Full default map. Values are deliberately conservative and align
     * with the behaviour described in COMPRESSLY_SPEC.md.
     *
     * @return array<string, mixed>
     */
    public static function all(): array {
        return [
            'api_key'                  => '',
            'compression_level'        => self::COMPRESSION_LOSSY,
            'webp_enabled'             => true,
            'resize_enabled'           => true,
            'resize_max_width'         => 2560,
            'resize_max_height'        => 2560,
            'lazy_load_enabled'        => true,
            'lazy_load_skip_count'     => 1,
            'backup_originals'         => true,
            'skip_threshold_kb'        => 10,
            'exclusion_patterns'       => '',
            'excluded_thumbnail_sizes' => [],
            'kill_switch'              => false,
            'remove_data_on_uninstall' => false,
        ];
    }
}
