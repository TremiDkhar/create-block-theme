<?php

class CBT_Theme_URLs {
	/**
	 * Regex that captures URL characters until a typical delimiter.
	 *
	 * @var string
	 */
	private const URL_SUFFIX_REGEX = '[^\s"\'<>)]*';

	/**
	 * Replace active-theme URLs/paths with dynamic get_stylesheet_directory_uri() output.
	 *
	 * @param string $content Template or pattern content.
	 * @return string
	 */
	public static function replace_active_theme_paths_with_dynamic_function( $content ) {

		$theme_prefixes = self::get_active_theme_prefixes();

		foreach ( self::get_prefix_variants( $theme_prefixes['absolute_url'] ) as $variant ) {
			$content = self::replace_patterns(
				$content,
				function ( $matches ) use ( $theme_prefixes ) {
					return self::theme_url_to_php( $matches[0], $theme_prefixes );
				},
				'~' . preg_quote( $variant, '~' ) . self::URL_SUFFIX_REGEX . '~'
			);
		}

		// Match only root-relative paths.
		// The leading boundary prevents matching the path part of an absolute URL,
		// because absolute URLs are handled in the previous pass.
		foreach ( self::get_prefix_variants( $theme_prefixes['relative_path'] ) as $variant ) {
			$content = self::replace_patterns(
				$content,
				function ( $matches ) use ( $theme_prefixes ) {
					return $matches[1] . self::theme_url_to_php( $matches[2], $theme_prefixes );
				},
				'~(^|[^[:alnum:]._-])(' . preg_quote( $variant, '~' ) . self::URL_SUFFIX_REGEX . ')~'
			);
		}

		return $content;
	}

	/**
	 * Replace current site absolute URLs in template content with dynamic home_url() output.
	 *
	 * @param string $content Template or pattern content.
	 * @return string
	 */
	public static function replace_site_urls_with_dynamic_function( $content ) {

		$content = self::replace_site_urls_in_esc_url_calls( $content );

		foreach ( self::get_local_url_prefixes() as $local_url_prefix ) {
			foreach ( self::get_prefix_variants( $local_url_prefix ) as $variant ) {
				$content = self::replace_patterns(
					$content,
					function ( $matches ) {
						return self::site_url_to_php( $matches[0] );
					},
					'~' . preg_quote( $variant, '~' ) . self::URL_SUFFIX_REGEX . '~'
				);
			}
		}

		return $content;
	}

	/**
	 * Localize URLs in template or pattern content.
	 *
	 * @param object $template Template-like object with a content property.
	 * @return object
	 */
	public static function make_template_urls_local( $template ) {
		if ( isset( $template->content ) ) {
			$template->content = self::replace_active_theme_paths_with_dynamic_function( $template->content );
			$template->content = self::replace_site_urls_with_dynamic_function( $template->content );
		}

		return $template;
	}

	/**
	 * Get the root-relative path prefix for the active theme directory.
	 *
	 * @return string
	 */
	private static function get_active_theme_path_prefix() {

		$stylesheet = wp_get_theme()->get_stylesheet();
		$content_path = wp_parse_url( content_url(), PHP_URL_PATH );

		return trailingslashit( $content_path . '/themes/' . $stylesheet );
	}

	/**
	 * Get active-theme prefixes used for URL/path matching.
	 *
	 * @return array
	 */
	private static function get_active_theme_prefixes() {
		return array(
			'absolute_url'  => trailingslashit( get_stylesheet_directory_uri() ),
			'relative_path' => self::get_active_theme_path_prefix(),
		);
	}

	/**
	 * Get unique URL prefixes that should be treated as local site URLs.
	 *
	 *
	 * @return array
	 */
	private static function get_local_url_prefixes() {

		return array_unique(
			array(
				home_url(),
				site_url(),
			)
		);

	}

	/**
	 * Parse local URL prefixes into host/scheme/port/path tuples.
	 *
	 * @return array
	 */
	private static function get_local_url_bases() {

		$local_bases = array();
		foreach ( self::get_local_url_prefixes() as $prefix ) {
			$prefix_parts = wp_parse_url( $prefix );
			if ( ! is_array( $prefix_parts ) || empty( $prefix_parts['host'] ) || empty( $prefix_parts['scheme'] ) ) {
				continue;
			}

			$local_bases[] = array(
				'host'   => strtolower( $prefix_parts['host'] ),
				'scheme' => strtolower( $prefix_parts['scheme'] ),
				'port'   => self::get_url_port( $prefix_parts ),
				'path'   => isset( $prefix_parts['path'] ) && is_string( $prefix_parts['path'] ) ? rtrim( $prefix_parts['path'], '/' ) : '',
			);
		}

		return $local_bases;
	}

	/**
	 * Get plain and JSON-escaped variants for a URL prefix.
	 *
	 * @param string $prefix URL prefix.
	 * @return array
	 */
	private static function get_prefix_variants( $prefix ) {

		$variants       = array( $prefix );
		$escaped_prefix = str_replace( '/', '\\/', $prefix );
		if ( $escaped_prefix !== $prefix ) {
			$variants[] = $escaped_prefix;
		}

		return $variants;
	}

	/**
	 * Convert an absolute local site URL into a relative URL suitable for home_url().
	 *
	 * @param string $absolute_url Site absolute URL (escaped or unescaped).
	 * @return string|null
	 */
	private static function get_relative_site_path( $absolute_url ) {
		$absolute_parts = wp_parse_url( self::normalize_slashes( $absolute_url ) );
		if ( ! is_array( $absolute_parts ) || empty( $absolute_parts['host'] ) || empty( $absolute_parts['scheme'] ) ) {
			return null;
		}

		$absolute_host   = strtolower( $absolute_parts['host'] );
		$absolute_scheme = strtolower( $absolute_parts['scheme'] );
		$absolute_port   = self::get_url_port( $absolute_parts );
		$absolute_path   = isset( $absolute_parts['path'] ) && '' !== $absolute_parts['path'] ? $absolute_parts['path'] : '/';

		foreach ( self::get_local_url_bases() as $local_base ) {
			// @why condition
			if (
				$local_base['host'] !== $absolute_host ||
				$local_base['scheme'] !== $absolute_scheme ||
				$local_base['port'] !== $absolute_port
			) {
				continue;
			}

			$prefix_path = $local_base['path'];
			if ( '' === $prefix_path || '/' === $prefix_path ) {
				$relative_url = $absolute_path;
			} else {
				if ( $absolute_path !== $prefix_path && ! str_starts_with( $absolute_path, $prefix_path . '/' ) ) {
					continue;
				}

				$relative_url = substr( $absolute_path, strlen( $prefix_path ) );
			}

			if ( '' === $relative_url ) {
				$relative_url = '/';
			} elseif ( '/' !== substr( $relative_url, 0, 1 ) ) {
				$relative_url = '/' . $relative_url;
			}

			if ( isset( $absolute_parts['query'] ) && '' !== $absolute_parts['query'] ) {
				$relative_url .= '?' . $absolute_parts['query'];
			}

			if ( isset( $absolute_parts['fragment'] ) && '' !== $absolute_parts['fragment'] ) {
				$relative_url .= '#' . $absolute_parts['fragment'];
			}

			return $relative_url;
		}

		return null;
	}

	/**
	 * Return a normalized explicit port for URL-part comparisons.
	 *
	 * This treats implicit defaults as explicit (`http` => 80, `https` => 443),
	 * so equivalent URLs with/without the default port compare equal.
	 *
	 * @param array $url_parts Parsed URL parts.
	 * @return int|null
	 */
	private static function get_url_port( $url_parts ) {
		if ( isset( $url_parts['port'] ) ) {
			return (int) $url_parts['port'];
		}

		$scheme = isset( $url_parts['scheme'] ) && is_string( $url_parts['scheme'] ) ? strtolower( $url_parts['scheme'] ) : '';
		if ( 'http' === $scheme ) {
			return 80;
		}

		if ( 'https' === $scheme ) {
			return 443;
		}

		return null;
	}

	/**
	 * Replace local absolute URLs inside esc_url('...') with esc_url( home_url('...') ).
	 *
	 * @param string $content Template or pattern content.
	 * @return string
	 */
	private static function replace_site_urls_in_esc_url_calls( $content ) {
		foreach ( self::get_local_url_prefixes() as $local_url_prefix ) {
			foreach ( self::get_prefix_variants( $local_url_prefix ) as $variant ) {
				$quoted_variant = preg_quote( $variant, '~' );
				$content        = self::replace_patterns(
					$content,
					array( __CLASS__, 'replace_site_url_in_esc_url_call' ),
					'~esc_url\(\s*\'(' . $quoted_variant . self::URL_SUFFIX_REGEX . ')\'\s*\)~',
					'~esc_url\(\s*"(' . $quoted_variant . self::URL_SUFFIX_REGEX . ')"\s*\)~'
				);
			}
		}

		return $content;
	}

	/**
	 * Replace a matched esc_url() call containing a local absolute URL with
	 * esc_url( home_url() ).
	 *
	 * @param array $matches Regular expression matches from preg_replace_callback().
	 * @return string
	 */
	private static function replace_site_url_in_esc_url_call( $matches ) {

		$home_url_call = self::get_home_url_call( $matches[1] );
		if ( null === $home_url_call ) {
			return $matches[0];
		}

		return "esc_url( {$home_url_call} )";
	}

	/**
	 * Apply a callback replacement for each regex pattern.
	 *
	 *
	 * @param string   $content Content to transform.
	 * @param callable $callback Callback used by preg_replace_callback.
	 * @param string   ...$patterns Regex patterns.
	 * @return string
	 */
	private static function replace_patterns( $content, $callback, ...$patterns ) {
		foreach ( $patterns as $pattern ) {
			$result = preg_replace_callback( $pattern, $callback, $content );
			$content = $result;
		}

		return $content;
	}

	/**
	 * Convert an active-theme URL/path into a get_stylesheet_directory_uri() expression.
	 *
	 * @param string $asset_url Active-theme URL/path (escaped or unescaped).
	 * @param array  $theme_prefixes Active-theme prefixes to compare against.
	 * @return string
	 */
	private static function theme_url_to_php( $asset_url, $theme_prefixes ) {
		$normalized_url = self::normalize_slashes( $asset_url );
		$relative_path  = null;

		foreach ( $theme_prefixes as $theme_prefix ) {
			if ( '' === $theme_prefix ) {
				continue;
			}

			$normalized_prefix = self::normalize_slashes( $theme_prefix );
			if ( str_starts_with( $normalized_url, $normalized_prefix ) ) {
				$relative_path = substr( $normalized_url, strlen( $normalized_prefix ) );
				break;
			}
		}

		$replacement = '<?php echo esc_url( get_stylesheet_directory_uri() ); ?>';
		if ( '' !== $relative_path ) {
			$replacement .= '/' . self::escape_single_quoted_php_string( $relative_path );
		}

		return $replacement;
	}

	/**
	 * Build a dynamic home_url() call from an absolute local URL.
	 *
	 * @param string $absolute_url Site absolute URL (escaped or unescaped).
	 * @return string|null
	 */
	private static function get_home_url_call( $absolute_url ) {
		$relative_url = self::get_relative_site_path( $absolute_url );
		if ( null === $relative_url ) {
			return null;
		}

		$relative_url = self::escape_single_quoted_php_string( $relative_url );

		return "home_url( '{$relative_url}' )";
	}

	/**
	 * Convert an absolute local site URL into a dynamic home_url() expression.
	 *
	 * @param string $absolute_url Site absolute URL (escaped or unescaped).
	 * @return string
	 */
	private static function site_url_to_php( $absolute_url ) {
		$home_url_call = self::get_home_url_call( $absolute_url );

		if ( null === $home_url_call ) {
			return $absolute_url;
		}

		return "<?php echo esc_url( {$home_url_call} ); ?>";
	}

	/**
	 * Normalize escaped forward slashes in URL-like strings.
	 *
	 * @param string $value URL-like string.
	 * @return string
	 */
	private static function normalize_slashes( $value ) {
		return str_replace( '\\/', '/', $value );
	}

	/**
	 * Escape a value for interpolation into a single-quoted PHP string literal.
	 *
	 * @param string $value Raw string value.
	 * @return string
	 */
	private static function escape_single_quoted_php_string( $value ) {
		return str_replace( "'", "\\'", $value );
	}
}
