<?php
/**
 * Wires the Plugin Update Checker library to this repo's GitHub
 * releases so the fleet sees Compressly updates in the standard
 * WordPress admin update flow.
 *
 * Release-asset mode: the GitHub Actions workflow attaches
 * compressly.zip (with vendor/ bundled) to every tagged release,
 * and PUC pulls that asset rather than the raw source archive
 * so /vendor isn't missing on the client site after update.
 *
 * Centralised so future channels (beta, hotfix) and the
 * COMPRESSLY_GITHUB_TOKEN constant for a private mirror only need
 * to be touched in one place.
 *
 * @package GoodAgency\Compressly
 */

declare(strict_types=1);

namespace GoodAgency\Compressly\Updater;

use GoodAgency\Compressly\Settings\OptionsManager;
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

final class GitHubUpdater {

    public const REPO_URL = 'https://github.com/JafetGoodAgency/compressly/';

    private const SLUG = 'compressly';

    private OptionsManager $options;
    private string $plugin_file;

    public function __construct( OptionsManager $options, string $plugin_file ) {
        $this->options     = $options;
        $this->plugin_file = $plugin_file;
    }

    /**
     * Builds the update checker for this site. No-ops if the kill switch
     * for updates is off or if PUC isn't autoloadable for any reason.
     */
    public function register(): void {
        if ( ! (bool) $this->options->get( 'update_check_enabled', true ) ) {
            return;
        }

        if ( ! class_exists( PucFactory::class ) ) {
            return;
        }

        $repo_url = defined( 'COMPRESSLY_UPDATE_REPO_URL' )
            ? (string) constant( 'COMPRESSLY_UPDATE_REPO_URL' )
            : self::REPO_URL;

        $checker = PucFactory::buildUpdateChecker(
            $repo_url,
            $this->plugin_file,
            self::SLUG
        );

        $branch = $this->resolve_branch();
        $checker->setBranch( $branch );

        // compressly.zip from the GitHub Action ships with vendor/ —
        // that's what we want WP to install, not the raw source tarball.
        $vcs = $checker->getVcsApi();
        if ( is_object( $vcs ) && method_exists( $vcs, 'enableReleaseAssets' ) ) {
            $vcs->enableReleaseAssets( '/^compressly.*\.zip$/i' );
        }

        if ( defined( 'COMPRESSLY_GITHUB_TOKEN' ) ) {
            $token = (string) constant( 'COMPRESSLY_GITHUB_TOKEN' );
            if ( $token !== '' && is_object( $vcs ) && method_exists( $vcs, 'setAuthentication' ) ) {
                $vcs->setAuthentication( $token );
            }
        }
    }

    /**
     * Override priority: wp-config constant beats setting beats default.
     *
     * The constant exists for the staged-rollout pattern in the spec
     * (deploy to 2-3 test sites on `beta`, promote to `stable`). The
     * setting exists so a per-site override can be flipped without
     * SSH access.
     */
    private function resolve_branch(): string {
        if ( defined( 'COMPRESSLY_UPDATE_CHANNEL' ) ) {
            $channel = sanitize_branch_name( (string) constant( 'COMPRESSLY_UPDATE_CHANNEL' ) );
            if ( $channel !== '' ) {
                return $channel === 'stable' ? 'main' : $channel;
            }
        }

        $configured = sanitize_branch_name( (string) $this->options->get( 'update_branch', 'main' ) );
        return $configured !== '' ? $configured : 'main';
    }
}

if ( ! function_exists( __NAMESPACE__ . '\\sanitize_branch_name' ) ) {
    /**
     * Whitelist a git branch name: letters, digits, dots, slashes,
     * hyphens, underscores. Anything else collapses to empty so the
     * caller falls back to a default.
     */
    function sanitize_branch_name( string $candidate ): string {
        $candidate = trim( $candidate );
        if ( $candidate === '' ) {
            return '';
        }
        if ( preg_match( '#^[A-Za-z0-9._/-]{1,100}$#', $candidate ) !== 1 ) {
            return '';
        }
        return $candidate;
    }
}
