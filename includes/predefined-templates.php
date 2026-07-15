<?php if ( ! defined( 'FW' ) ) { die( 'Forbidden' ); }

/**
 * Feed bundled + installed Template Library entries into the page builder's native
 * read-only "predefined templates" lists.
 *
 * The builder already renders predefined templates (no Delete button) and inserts
 * them onto the canvas with its existing JS. We just hook its filters, one per
 * kind, exactly like the theme's Starter Pages do
 * (unysonplus-theme/inc/includes/starter-page-templates.php):
 *
 *     fw_ext_builder:predefined_templates:page-builder:section
 *     fw_ext_builder:predefined_templates:page-builder:column
 *     fw_ext_builder:predefined_templates:page-builder:full
 *
 * Each entry is array( 'title' => …, 'json' => '<stringified builder tree>' ).
 * The `json` we pass is the export envelope's inner `json` string verbatim.
 *
 * A malformed template is skipped so it can never break the builder's list.
 */

if ( ! function_exists( 'fw_tpl_lib_bundled_catalog' ) ) :
	/**
	 * The bundled catalog: reads templates/catalog.json shipped in the extension
	 * (same shape as the remote catalog's `templates` map, minus base_url — the
	 * thumb path is resolved against the bundled folder URI). Cached per request.
	 *
	 * @return array slug => { slug, title, category, kind, description, thumb }
	 */
	function fw_tpl_lib_bundled_catalog() {
		static $cache = null;
		if ( is_array( $cache ) ) { return $cache; }

		$cache = array();
		$dir   = fw_tpl_lib_bundled_dir();
		$file  = $dir ? $dir . '/catalog.json' : '';
		if ( ! $file || ! is_readable( $file ) ) { return $cache; }

		$json = json_decode( (string) file_get_contents( $file ), true );
		if ( ! is_array( $json ) || empty( $json['templates'] ) || ! is_array( $json['templates'] ) ) {
			return $cache;
		}

		$uri = trailingslashit( fw_tpl_lib_bundled_uri() );

		foreach ( $json['templates'] as $slug => $tpl ) {
			$slug = sanitize_key( $slug );
			if ( $slug === '' || ! is_array( $tpl ) ) { continue; }

			$kind = isset( $tpl['kind'] ) ? sanitize_key( $tpl['kind'] ) : 'section';
			if ( ! in_array( $kind, fw_tpl_lib_allowed_kinds(), true ) ) { continue; }

			$thumb_rel = isset( $tpl['thumb'] ) ? (string) $tpl['thumb'] : '';

			$cache[ $slug ] = array(
				'slug'        => $slug,
				'title'       => isset( $tpl['title'] ) ? (string) $tpl['title'] : ucwords( str_replace( '-', ' ', $slug ) ),
				'category'    => isset( $tpl['category'] ) ? (string) $tpl['category'] : __( 'Uncategorized', 'fw' ),
				'kind'        => $kind,
				'description' => isset( $tpl['description'] ) ? (string) $tpl['description'] : '',
				'thumb'       => $thumb_rel !== '' ? esc_url_raw( $uri . ltrim( $thumb_rel, '/' ) ) : '',
			);
		}

		return $cache;
	}
endif;

if ( ! function_exists( 'fw_tpl_lib__read_envelope_json' ) ) :
	/**
	 * Read an export-envelope file and return its inner `json` string if valid for
	 * the given kind, else ''. Used for both bundled and installed templates.
	 */
	function fw_tpl_lib__read_envelope_json( $file, $kind ) {
		if ( ! is_readable( $file ) ) { return ''; }
		$envelope = json_decode( (string) file_get_contents( $file ), true );
		if ( is_wp_error( fw_tpl_lib__validate_envelope( $envelope, $kind ) ) ) { return ''; }
		return trim( (string) $envelope['json'] );
	}
endif;

if ( ! function_exists( 'fw_tpl_lib_registered_templates' ) ) :
	/**
	 * All templates to register, keyed slug => { title, kind, json }. Bundled first,
	 * then installed (installed wins on a slug clash). Cached per request.
	 */
	function fw_tpl_lib_registered_templates() {
		static $cache = null;
		if ( is_array( $cache ) ) { return $cache; }

		$cache = array();

		// Bundled.
		$bdir = fw_tpl_lib_bundled_dir();
		foreach ( fw_tpl_lib_bundled_catalog() as $slug => $m ) {
			$json = fw_tpl_lib__read_envelope_json( $bdir . '/' . $slug . '/' . $slug . '.json', $m['kind'] );
			if ( $json === '' ) { continue; }
			$cache[ $slug ] = array( 'title' => $m['title'], 'kind' => $m['kind'], 'json' => $json );
		}

		// Installed (override).
		$idir = trailingslashit( fw_tpl_lib_install_dir() );
		foreach ( fw_tpl_lib_installed_slugs() as $slug ) {
			$meta = fw_tpl_lib_installed_meta( $slug );
			$kind = ( $meta && isset( $meta['kind'] ) ) ? (string) $meta['kind'] : 'section';
			$json = fw_tpl_lib__read_envelope_json( $idir . $slug . '/template.json', $kind );
			if ( $json === '' ) { continue; }
			$cache[ $slug ] = array(
				'title' => ( $meta && isset( $meta['title'] ) ) ? (string) $meta['title'] : ucwords( str_replace( '-', ' ', $slug ) ),
				'kind'  => $kind,
				'json'  => $json,
			);
		}

		return $cache;
	}
endif;

if ( ! function_exists( 'fw_tpl_lib_feed_predefined' ) ) :
	/**
	 * Filter callback: append this library's templates of $kind to the builder's
	 * predefined list.
	 *
	 * @param array  $templates Existing predefined templates (id => {title, json}).
	 * @param string $kind      section|column|full
	 * @return array
	 */
	function fw_tpl_lib_feed_predefined( $templates, $kind ) {
		if ( ! is_array( $templates ) ) { $templates = array(); }

		foreach ( fw_tpl_lib_registered_templates() as $slug => $t ) {
			if ( $t['kind'] !== $kind ) { continue; }
			$templates[ 'tpllib_' . $slug ] = array(
				'title' => $t['title'],
				'json'  => $t['json'],
			);
		}

		return $templates;
	}
endif;

add_filter( 'fw_ext_builder:predefined_templates:page-builder:section', function ( $t ) {
	return fw_tpl_lib_feed_predefined( $t, 'section' );
} );
add_filter( 'fw_ext_builder:predefined_templates:page-builder:column', function ( $t ) {
	return fw_tpl_lib_feed_predefined( $t, 'column' );
} );
add_filter( 'fw_ext_builder:predefined_templates:page-builder:full', function ( $t ) {
	return fw_tpl_lib_feed_predefined( $t, 'full' );
} );
