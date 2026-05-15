<?php
/**
 * Adds loading="lazy" to <img> tags in front-end output.
 *
 * Hooks the_content, post_thumbnail_html, and wp_get_attachment_image_attributes
 * so the same skip-the-first-N policy applies whether images come from post
 * content, the featured image, or a direct wp_get_attachment_image call. Images
 * that already declare a loading attribute, and images inside an existing
 * <picture> block (handled by WebPServer or hand-authored), are left untouched.
 *
 * @package GoodAgency\Compressly
 */

declare(strict_types=1);

namespace GoodAgency\Compressly\Frontend;

final class LazyLoad {

    private int $skip_count;

    /**
     * Request-scoped counter incremented for every <img> we consider. Lets
     * the "skip the first N" rule apply across multiple filter invocations
     * (the_content + post_thumbnail_html + attachment attributes) within a
     * single page render.
     */
    private int $img_index = 0;

    public function __construct( int $skip_count ) {
        $this->skip_count = max( 0, $skip_count );
    }

    public function register(): void {
        add_filter( 'the_content', [ $this, 'filter_content' ], 9 );
        add_filter( 'post_thumbnail_html', [ $this, 'filter_thumbnail_html' ], 9 );
        add_filter( 'wp_get_attachment_image_attributes', [ $this, 'filter_attachment_attributes' ], 10 );
    }

    public function filter_content( $content ) {
        if ( ! is_string( $content ) || $content === '' ) {
            return $content;
        }
        if ( stripos( $content, '<img' ) === false ) {
            return $content;
        }

        [ $masked, $placeholders ] = $this->mask_picture_blocks( $content );

        $processed = preg_replace_callback(
            '#<img\b[^>]*>#i',
            function ( array $m ): string {
                return $this->maybe_add_lazy( (string) $m[0] );
            },
            $masked
        );

        return is_string( $processed ) ? strtr( $processed, $placeholders ) : $content;
    }

    public function filter_thumbnail_html( $html ) {
        if ( ! is_string( $html ) || $html === '' ) {
            return $html;
        }
        if ( stripos( $html, '<img' ) === false ) {
            return $html;
        }

        $processed = preg_replace_callback(
            '#<img\b[^>]*>#i',
            function ( array $m ): string {
                return $this->maybe_add_lazy( (string) $m[0] );
            },
            $html
        );

        return is_string( $processed ) ? $processed : $html;
    }

    /**
     * @param mixed $attrs
     * @return mixed
     */
    public function filter_attachment_attributes( $attrs ) {
        if ( ! is_array( $attrs ) ) {
            return $attrs;
        }
        if ( isset( $attrs['loading'] ) && (string) $attrs['loading'] !== '' ) {
            return $attrs;
        }

        $this->img_index++;
        if ( $this->img_index <= $this->skip_count ) {
            return $attrs;
        }

        $attrs['loading'] = 'lazy';
        return $attrs;
    }

    /**
     * @return array{0:string,1:array<string,string>}
     */
    private function mask_picture_blocks( string $content ): array {
        if ( stripos( $content, '<picture' ) === false ) {
            return [ $content, [] ];
        }

        $placeholders = [];
        $index        = 0;
        $masked       = preg_replace_callback(
            '#<picture\b[^>]*>.*?</picture>#is',
            static function ( array $m ) use ( &$placeholders, &$index ): string {
                $token                  = "\0CPL_PIC_{$index}\0";
                $placeholders[ $token ] = (string) $m[0];
                $index++;
                return $token;
            },
            $content
        );

        if ( ! is_string( $masked ) ) {
            return [ $content, [] ];
        }

        return [ $masked, $placeholders ];
    }

    private function maybe_add_lazy( string $img_tag ): string {
        if ( preg_match( '/\sloading\s*=/i', $img_tag ) === 1 ) {
            return $img_tag;
        }

        $this->img_index++;
        if ( $this->img_index <= $this->skip_count ) {
            return $img_tag;
        }

        $replaced = preg_replace( '#(\s*/?>)$#', ' loading="lazy"$1', $img_tag, 1 );
        return is_string( $replaced ) ? $replaced : $img_tag;
    }
}
