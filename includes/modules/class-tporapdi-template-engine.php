<?php
/**
 * Unified template rendering seam for Twig-based import templates.
 *
 * @package EnterpriseAPIImporter
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single rendering gate for all Twig templates — live imports and dry-run previews alike.
 *
 * Owns the Twig environment lifecycle, applies per-type output sanitization,
 * and ensures identical security for every rendering context.
 */
class TPORAPDI_Template_Engine {

	const TYPE_TITLE   = 'title';
	const TYPE_BODY    = 'body';
	const TYPE_EXCERPT = 'excerpt';
	const TYPE_SLUG    = 'slug';
	const TYPE_META    = 'meta';

	/**
	 * Singleton Twig environment.
	 *
	 * @var \Twig\Environment|null
	 */
	private static $twig = null;

	/**
	 * Renders a Twig template string against a record and sanitizes the output
	 * according to the field type.
	 *
	 * @param string               $template Twig template source.
	 * @param array<string, mixed> $record   Data record exposed as {{ record }}, {{ item }}, {{ data }}.
	 * @param string               $type     One of TYPE_TITLE, TYPE_BODY, TYPE_EXCERPT, TYPE_SLUG, TYPE_META.
	 *
	 * @return string|WP_Error Sanitized rendered string on success, WP_Error on failure.
	 */
	public function render( string $template, array $record, string $type = self::TYPE_BODY ) {
		if ( '' === $template ) {
			return new WP_Error(
				'tporapdi_missing_template',
				__( 'Template string is empty.', 'tporret-api-data-importer' )
			);
		}

		$twig = $this->environment();

		if ( is_wp_error( $twig ) ) {
			return $twig;
		}

		$loader = $twig->getLoader();

		if ( ! $loader instanceof \Twig\Loader\ArrayLoader ) {
			return new WP_Error(
				'tporapdi_twig_loader_invalid',
				__( 'Twig loader is not configured for string templates.', 'tporret-api-data-importer' )
			);
		}

		$name    = 'eai-' . $type . '-' . md5( $template );
		$context = array(
			'record' => $record,
			'item'   => $record,
			'data'   => $record,
		);

		try {
			$loader->setTemplate( $name, $template );
			$rendered = (string) $twig->render( $name, $context );
		} catch ( \Twig\Error\Error $error ) {
			return new WP_Error(
				'tporapdi_template_syntax_error',
				sprintf(
					/* translators: %s is the Twig exception message. */
					__( 'Twig template syntax error: %s', 'tporret-api-data-importer' ),
					$error->getMessage()
				)
			);
		}

		return $this->sanitize_output( $rendered, $type );
	}

	/**
	 * Returns the Twig environment, initializing it once on first call.
	 *
	 * @return \Twig\Environment|WP_Error
	 */
	public function environment() {
		if ( self::$twig instanceof \Twig\Environment ) {
			return self::$twig;
		}

		if ( ! class_exists( '\\Twig\\Environment' ) || ! class_exists( '\\Twig\\Loader\\ArrayLoader' ) ) {
			return new WP_Error(
				'tporapdi_twig_missing_dependency',
				__( 'Twig is not installed. Run Composer install for twig/twig.', 'tporret-api-data-importer' )
			);
		}

		$strict_variables = (bool) apply_filters( 'tporapdi_twig_strict_variables', false );

		$loader     = new \Twig\Loader\ArrayLoader( array() );
		self::$twig = new \Twig\Environment(
			$loader,
			array(
				'autoescape'       => false,
				'strict_variables' => $strict_variables,
				'cache'            => false,
			)
		);

		self::$twig->addTest(
			new \Twig\TwigTest(
				'numeric',
				static function ( $value ) {
					return is_numeric( $value );
				}
			)
		);

		self::$twig->addFilter(
			new \Twig\TwigFilter(
				'format_us_currency',
				static function ( $value ) {
					if ( ! is_numeric( $value ) ) {
						return (string) $value;
					}

					return '$' . number_format( (float) $value, 2, '.', ',' );
				}
			)
		);

		self::$twig->addFilter(
			new \Twig\TwigFilter(
				'format_date_mdy',
				static function ( $value ) {
					$date_value = trim( (string) $value );
					if ( '' === $date_value ) {
						return '';
					}

					$timestamp = strtotime( $date_value );
					if ( false === $timestamp ) {
						return $date_value;
					}

					return gmdate( 'm/d/Y', (int) $timestamp );
				}
			)
		);

		return self::$twig;
	}

	/**
	 * Applies type-appropriate sanitization to a rendered string.
	 *
	 * @param string $rendered Raw Twig output.
	 * @param string $type     One of TYPE_* constants.
	 *
	 * @return string
	 */
	private function sanitize_output( string $rendered, string $type ): string {
		switch ( $type ) {
			case self::TYPE_TITLE:
				return mb_substr( wp_strip_all_tags( trim( $rendered ) ), 0, 255 );

			case self::TYPE_EXCERPT:
				return mb_substr( wp_strip_all_tags( trim( $rendered ) ), 0, 1000 );

			case self::TYPE_SLUG:
				return sanitize_title( trim( $rendered ) );

			case self::TYPE_META:
				return sanitize_text_field( $rendered );

			case self::TYPE_BODY:
			default:
				return wp_kses_post( $rendered );
		}
	}
}
