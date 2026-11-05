<?php
/**
 * Bounded, read-only parser of saved post_content image uses.
 *
 * @package Digicyfr_Image_Alt_Use_Audit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Saved-use scanner. Never renders a block or fetches a URL. */
final class DIAU_Scanner {
	public const MAX_POST_BYTES   = 524288;
	public const MAX_IMAGES       = 300;
	private const MAX_BLOCK_DEPTH = 64;

	/**
	 * Classify the saved alt attribute without judging its purpose.
	 *
	 * @param string|bool|null $alt Tag Processor attribute.
	 * @return string One of missing, empty, whitespace, nonempty, invalid.
	 */
	public static function alt_state( $alt ): string {
		if ( null === $alt ) {
			return 'missing';
		}
		if ( ! is_string( $alt ) ) {
			return 'invalid';
		}
		if ( '' === $alt ) {
			return 'empty';
		}
		$blank = preg_match( '/^[\p{Z}\s]*$/u', $alt );
		if ( false === $blank ) {
			return 'invalid';
		}
		return 1 === $blank ? 'whitespace' : 'nonempty';
	}

	/**
	 * Keep a UTF-8-safe, plain-text preview without silently adding meaning.
	 *
	 * @param mixed $value Raw decoded attribute.
	 * @param int   $limit Character limit.
	 * @return string Bounded preview.
	 */
	private static function preview( $value, int $limit ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = sanitize_text_field( $value );
		return preg_match( '/^.{0,' . $limit . '}/us', $value, $matches ) ? $matches[0] : '';
	}

	/**
	 * Discard query strings, fragments, userinfo, data URLs, and unknown schemes.
	 *
	 * @param mixed $src Saved src attribute.
	 * @return string Bounded host/path display hint, or empty.
	 */
	public static function src_hint( $src ): string {
		if ( ! is_string( $src ) || '' === $src ) {
			return '';
		}
		if ( str_starts_with( $src, '//' ) ) {
			$src = 'https:' . $src;
		}
		$parts = wp_parse_url( $src );
		if ( ! is_array( $parts ) ) {
			return '';
		}
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		if ( '' !== $scheme && ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}
		$host = strtolower( (string) ( $parts['host'] ?? '' ) );
		$path = (string) ( $parts['path'] ?? '' );
		if ( '' === $host && ! str_starts_with( $path, '/' ) ) {
			return '';
		}
		return self::preview( ( '' !== $host ? $host : '' ) . $path, 255 );
	}

	/**
	 * Return only a single existing attachment ID identified without conflict.
	 *
	 * @param array $block Parsed block.
	 * @param mixed $class_attr Saved class attribute.
	 * @return int Attachment hint or zero.
	 */
	private static function attachment_id( array $block, $class_attr ): int {
		$candidates = array();
		if ( 'core/image' === ( $block['blockName'] ?? '' ) ) {
			$id = $block['attrs']['id'] ?? null;
			if ( is_int( $id ) && $id > 0 ) {
				$candidates[] = $id;
			}
		}
		if ( is_string( $class_attr ) && preg_match_all( '/(?:^|\s)wp-image-([1-9][0-9]*)(?=\s|$)/', $class_attr, $matches ) ) {
			foreach ( $matches[1] as $found ) {
				$candidates[] = (int) $found;
			}
		}
		$candidates = array_values( array_unique( $candidates ) );
		return 1 === count( $candidates ) && 'attachment' === get_post_type( $candidates[0] ) ? $candidates[0] : 0;
	}

	/**
	 * Scan one bounded saved post, excluding dynamic/rendered content.
	 *
	 * @param int    $post_id Source post ID.
	 * @param string $content Saved post_content.
	 * @return array Uses, coverage reasons, and optional skip reason.
	 */
	public static function scan_post( int $post_id, string $content ): array {
		if ( strlen( $content ) > self::MAX_POST_BYTES ) {
			return array(
				'uses'     => array(),
				'coverage' => array(),
				'skip'     => 'post_too_large',
			);
		}
		$uses     = array();
		$coverage = array();
		$skip     = '';
		if ( has_post_thumbnail( $post_id ) ) {
			$coverage[] = 'featured_image_outside_scope';
		}
		$walk = static function ( array $blocks, string $parent_path, int $depth ) use ( &$walk, &$uses, &$coverage, &$skip ): void {
			if ( $depth > self::MAX_BLOCK_DEPTH ) {
				$skip = 'blocks_too_deep';
				return;
			}
			foreach ( $blocks as $index => $block ) {
				if ( '' !== $skip || ! is_array( $block ) ) {
					break;
				}
				$path      = '' === $parent_path ? (string) ( $index + 1 ) : $parent_path . '/' . ( $index + 1 );
				$name      = is_string( $block['blockName'] ?? null ) ? $block['blockName'] : '';
				$html      = is_string( $block['innerHTML'] ?? null ) ? $block['innerHTML'] : '';
				$processor = new WP_HTML_Tag_Processor( $html );
				$ordinal   = 0;
				while ( $processor->next_tag( 'img' ) ) {
					++$ordinal;
					if ( count( $uses ) >= self::MAX_IMAGES ) {
						$skip = 'too_many_images';
						return;
					}
					$alt    = $processor->get_attribute( 'alt' );
					$uses[] = array(
						'block_name'    => '' === $name ? 'saved HTML' : $name,
						'block_path'    => $path,
						'block_hash'    => hash( 'sha256', $path ),
						'image_ordinal' => $ordinal,
						'alt_state'     => self::alt_state( $alt ),
						'alt_preview'   => self::preview( $alt, 200 ),
						'src_hint'      => self::src_hint( $processor->get_attribute( 'src' ) ),
						'attachment_id' => self::attachment_id( $block, $processor->get_attribute( 'class' ) ),
					);
				}
				if ( 'core/cover' === $name && 0 === $ordinal ) {
					$attrs     = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
					$has_media = ! empty( $attrs['url'] ) || ! empty( $attrs['id'] ) || ! empty( $attrs['useFeaturedImage'] ) || 'video' === ( $attrs['backgroundType'] ?? '' );
					if ( ! $has_media ) {
						$cover_tags = new WP_HTML_Tag_Processor( $html );
						while ( $cover_tags->next_tag() ) {
							$style = $cover_tags->get_attribute( 'style' );
							if ( 'VIDEO' === $cover_tags->get_tag() || ( is_string( $style ) && false !== stripos( $style, 'background-image' ) ) ) {
								$has_media = true;
								break;
							}
						}
					}
					if ( $has_media ) {
						$coverage[] = 'cover_without_saved_img';
					}
				}
				if ( 'core/block' === $name ) {
					$coverage[] = 'synced_block_reference';
				}
				$walk( is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array(), $path, $depth + 1 );
			}
		};
		$walk( parse_blocks( $content ), '', 1 );
		if ( '' !== $skip ) {
			return array(
				'uses'     => array(),
				'coverage' => array(),
				'skip'     => $skip,
			);
		}
		return array(
			'uses'     => $uses,
			'coverage' => array_values( array_unique( $coverage ) ),
			'skip'     => '',
		);
	}
}
