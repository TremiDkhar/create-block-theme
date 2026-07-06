<?php

class CBT_Theme_URLs {
	/**
	 * Get path prefixes that point to the active theme directory.
	 *
	 * @return array
	 */
	private static function get_active_theme_path_prefixes() {
		$stylesheet = wp_get_theme()->get_stylesheet();

		if ( ! is_string( $stylesheet ) || '' === $stylesheet ) {
			return array();
		}

		$content_path = wp_parse_url( content_url(), PHP_URL_PATH );
		$content_path = is_string( $content_path ) && '' !== $content_path ? rtrim( $content_path, '/' ) : '/wp-content';

		$theme_path = $content_path . '/themes/' . trim( $stylesheet, '/' ) . '/';

		return array_values(
			array_unique(
				array_filter(
					array( $theme_path )
				)
			)
		);
	}

	/**
	 * Get absolute URL prefixes for the active theme directory.
	 *
	 * @return array
	 */
	private static function get_active_theme_absolute_url_prefixes() {
		$absolute_prefixes = array();

		foreach ( self::get_local_url_prefixes() as $local_prefix ) {
			foreach ( self::get_active_theme_path_prefixes() as $theme_path_prefix ) {
				$absolute_prefixes[] = untrailingslashit( $local_prefix ) . $theme_path_prefix;
			}
		}

		return array_values(
			array_unique(
				array_filter( $absolute_prefixes )
			)
		);
	}

	/**
	 * Get unique URL prefixes that should be treated as local site URLs.
	 *
	 * @return array
	 */
	private static function get_local_url_prefixes() {
		return array_values(
			array_unique(
				array_filter(
					array(
						untrailingslashit( home_url() ),
						untrailingslashit( site_url() ),
					)
				)
			)
		);
	}

	/**
	 * Convert an absolute site URL into a dynamic home_url() expression.
	 *
	 * @param string $absolute_url Site absolute URL (escaped or unescaped).
	 * @return string
	 */
	private static function absolute_site_url_to_home_url_php( $absolute_url ) {
		$normalized_url = str_replace( '\\/', '/', $absolute_url );
		$relative_url   = null;

		foreach ( self::get_local_url_prefixes() as $prefix ) {
			if ( str_starts_with( $normalized_url, $prefix ) ) {
				$relative_url = substr( $normalized_url, strlen( $prefix ) );
				break;
			}
		}

		if ( null === $relative_url ) {
			return $absolute_url;
		}

		if ( '' === $relative_url ) {
			$relative_url = '/';
		} elseif ( '/' !== substr( $relative_url, 0, 1 ) ) {
			$relative_url = '/' . $relative_url;
		}

		$relative_url = str_replace( "'", "\\'", $relative_url );
		$replacement  = "<?php echo esc_url( home_url( '{$relative_url}' ) ); ?>";

		if ( false !== strpos( $absolute_url, '\\/' ) ) {
			return str_replace( '/', '\\/', $replacement );
		}

		return $replacement;
	}

	/**
	 * Convert an absolute site URL into a dynamic home_url() function call.
	 *
	 * @param string $absolute_url Site absolute URL (escaped or unescaped).
	 * @return string
	 */
	private static function absolute_site_url_to_home_url_call( $absolute_url ) {
		$normalized_url = str_replace( '\\/', '/', $absolute_url );
		$relative_url   = null;

		foreach ( self::get_local_url_prefixes() as $prefix ) {
			if ( str_starts_with( $normalized_url, $prefix ) ) {
				$relative_url = substr( $normalized_url, strlen( $prefix ) );
				break;
			}
		}

		if ( null === $relative_url ) {
			return $absolute_url;
		}

		if ( '' === $relative_url ) {
			$relative_url = '/';
		} elseif ( '/' !== substr( $relative_url, 0, 1 ) ) {
			$relative_url = '/' . $relative_url;
		}

		$relative_url = str_replace( "'", "\\'", $relative_url );

		return "home_url( '{$relative_url}' )";
	}

	/**
	 * Replace local absolute URLs inside esc_url('...') calls with esc_url( home_url('...') ).
	 *
	 * @param string $content Template or pattern content.
	 * @return string
	 */
	private static function replace_site_urls_inside_esc_url_calls( $content ) {
		if ( empty( $content ) || ! is_string( $content ) ) {
			return $content;
		}

		foreach ( self::get_local_url_prefixes() as $prefix ) {
			$patterns = array(
				"~esc_url\\(\\s*'(" . preg_quote( $prefix, '~' ) . "[^\\s\"'<>)]*)'\\s*\\)~",
				"~esc_url\\(\\s*\"(" . preg_quote( $prefix, '~' ) . "[^\\s\"'<>)]*)\"\\s*\\)~",
				"~esc_url\\(\\s*'(" . preg_quote( str_replace( '/', '\\/', $prefix ), '~' ) . "[^\\s\"'<>)]*)'\\s*\\)~",
				"~esc_url\\(\\s*\"(" . preg_quote( str_replace( '/', '\\/', $prefix ), '~' ) . "[^\\s\"'<>)]*)\"\\s*\\)~",
			);

			foreach ( $patterns as $pattern ) {
				$content = preg_replace_callback(
					$pattern,
					function ( $matches ) {
						$home_url_call = self::absolute_site_url_to_home_url_call( $matches[1] );

						if ( $home_url_call === $matches[1] ) {
							return $matches[0];
						}

						return "esc_url( {$home_url_call} )";
					},
					$content
				);
			}
		}

		return $content;
	}

	/**
	 * Convert an active-theme path URL into a dynamic get_template_directory_uri() expression.
	 *
	 * @param string $asset_url Active-theme URL/path (escaped or unescaped).
	 * @return string
	 */
	private static function active_theme_path_to_template_directory_uri_php( $asset_url ) {
		$normalized_url = str_replace( '\\/', '/', $asset_url );
		$relative_path  = null;

		$prefixes = array_merge( self::get_active_theme_absolute_url_prefixes(), self::get_active_theme_path_prefixes() );

		foreach ( $prefixes as $prefix ) {
			$normalized_prefix = str_replace( '\\/', '/', $prefix );

			if ( str_starts_with( $normalized_url, $normalized_prefix ) ) {
				$relative_path = ltrim( substr( $normalized_url, strlen( $normalized_prefix ) ), '/' );
				break;
			}
		}

		if ( null === $relative_path ) {
			return $asset_url;
		}

		$replacement = "<?php echo esc_url( get_template_directory_uri() ); ?>";

		if ( '' !== $relative_path ) {
			$replacement .= '/' . str_replace( "'", "\\'", $relative_path );
		}

		if ( false !== strpos( $asset_url, '\\/' ) ) {
			return str_replace( '/', '\\/', $replacement );
		}

		return $replacement;
	}

	/**
	 * Replace active-theme URLs/paths with dynamic get_template_directory_uri() output.
	 *
	 * @param string $content Template or pattern content.
	 * @return string
	 */
	public static function replace_active_theme_paths_with_dynamic_function( $content ) {
		if ( empty( $content ) || ! is_string( $content ) ) {
			return $content;
		}

		$prefixes = array_merge( self::get_active_theme_absolute_url_prefixes(), self::get_active_theme_path_prefixes() );

		foreach ( $prefixes as $prefix ) {
			$patterns = array(
				"~" . preg_quote( $prefix, '~' ) . "[^\\s\"'<>)]*~",
				"~" . preg_quote( str_replace( '/', '\\/', $prefix ), '~' ) . "[^\\s\"'<>)]*~",
			);

			foreach ( $patterns as $pattern ) {
				$content = preg_replace_callback(
					$pattern,
					function ( $matches ) {
						return self::active_theme_path_to_template_directory_uri_php( $matches[0] );
					},
					$content
				);
			}
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
		if ( empty( $content ) || ! is_string( $content ) ) {
			return $content;
		}

		$content = self::replace_site_urls_inside_esc_url_calls( $content );

		foreach ( self::get_local_url_prefixes() as $prefix ) {
			$patterns = array(
				"~" . preg_quote( $prefix, '~' ) . "[^\\s\"'<>)]*~",
				"~" . preg_quote( str_replace( '/', '\\/', $prefix ), '~' ) . "[^\\s\"'<>)]*~",
			);

			foreach ( $patterns as $pattern ) {
				$content = preg_replace_callback(
					$pattern,
					function ( $matches ) {
						return self::absolute_site_url_to_home_url_php( $matches[0] );
					},
					$content
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
}
