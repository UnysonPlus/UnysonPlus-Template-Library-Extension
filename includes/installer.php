<?php if ( ! defined( 'FW' ) ) { die( 'Forbidden' ); }

/**
 * Template Library installer — remote catalog fetch + on-demand install.
 *
 * Mirrors the icon-pack installer (framework/includes/option-types/icon-v3):
 *   - A remote catalog.json lists installable templates (title, category, kind,
 *     thumbnail, description). Fetched with wp_remote_get + a 12h transient.
 *   - On Install, the template's export envelope JSON is downloaded from the
 *     catalog base_url into wp-content/uploads/unysonplus-templates/<slug>/,
 *     written atomically (temp dir + rename) as template.json + meta.json.
 *   - predefined-templates.php then feeds bundled + installed templates into the
 *     builder's native Templates menu — no network call on a builder load.
 *
 * Installed layout (mirrors a bundled template's shape so the resolver in
 * predefined-templates.php treats them identically):
 *   unysonplus-templates/<slug>/template.json   the export envelope verbatim
 *   unysonplus-templates/<slug>/meta.json        { title, slug, category, kind, thumb, installed }
 */

/* -----------------------------------------------------------------------------
 * Paths
 * -------------------------------------------------------------------------- */

if ( ! function_exists( 'fw_tpl_lib_install_dir' ) ) :
	/** Absolute path of the uploads install dir (no trailing slash). Filterable. */
	function fw_tpl_lib_install_dir() {
		$up = wp_upload_dir();
		return apply_filters( 'fw_tpl_lib_install_dir', trailingslashit( $up['basedir'] ) . 'unysonplus-templates' );
	}
endif;

if ( ! function_exists( 'fw_tpl_lib_bundled_dir' ) ) :
	/** Absolute path of the bundled-templates folder shipped in the extension. */
	function fw_tpl_lib_bundled_dir() {
		$ext = fw_ext( 'template-library' );
		return $ext ? $ext->get_templates_path() : '';
	}
endif;

if ( ! function_exists( 'fw_tpl_lib_bundled_uri' ) ) :
	/** Public URI of the bundled-templates folder (for thumbnails). */
	function fw_tpl_lib_bundled_uri() {
		$ext = fw_ext( 'template-library' );
		return $ext ? $ext->get_templates_uri() : '';
	}
endif;

if ( ! function_exists( 'fw_tpl_lib_allowed_kinds' ) ) :
	/** The template kinds the builder's predefined lists accept. */
	function fw_tpl_lib_allowed_kinds() {
		return array( 'section', 'column', 'full' );
	}
endif;

/* -----------------------------------------------------------------------------
 * Remote catalog
 * -------------------------------------------------------------------------- */

if ( ! function_exists( 'fw_tpl_lib_catalog_url' ) ) :
	/** URL of the catalog.json describing installable templates. Filterable. */
	function fw_tpl_lib_catalog_url() {
		return apply_filters(
			'fw_tpl_lib_catalog_url',
			'https://raw.githubusercontent.com/UnysonPlus/UnysonPlus-Templates/master/catalog.json'
		);
	}
endif;

if ( ! function_exists( 'fw_tpl_lib_catalog' ) ) :
	/**
	 * Fetch + decode the remote catalog, cached in a transient (12h). Returns a
	 * normalized array, or an empty shape on failure (cached briefly so a flaky
	 * network doesn't retry on every page load).
	 *
	 * @param bool $force Bypass the transient (explicit "refresh").
	 * @return array { version:int, base_url:string, templates:{ slug => {...} } }
	 */
	function fw_tpl_lib_catalog( $force = false ) {
		$key = 'fw_tpl_lib_catalog';

		if ( ! $force ) {
			$cached = get_transient( $key );
			if ( is_array( $cached ) ) { return $cached; }
		}

		$empty = array( 'version' => 0, 'base_url' => '', 'templates' => array() );

		$res = wp_remote_get( fw_tpl_lib_catalog_url(), array( 'timeout' => 15 ) );
		if ( is_wp_error( $res ) || (int) wp_remote_retrieve_response_code( $res ) !== 200 ) {
			set_transient( $key, $empty, 5 * MINUTE_IN_SECONDS );
			return $empty;
		}

		$json = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $json ) || empty( $json['templates'] ) || ! is_array( $json['templates'] ) ) {
			set_transient( $key, $empty, 5 * MINUTE_IN_SECONDS );
			return $empty;
		}

		$base = isset( $json['base_url'] ) ? trailingslashit( (string) $json['base_url'] ) : '';

		$catalog = array(
			'version'   => isset( $json['version'] ) ? (int) $json['version'] : 1,
			'base_url'  => $base,
			'templates' => array(),
		);

		foreach ( $json['templates'] as $slug => $tpl ) {
			$slug = sanitize_key( $slug );
			if ( $slug === '' || ! is_array( $tpl ) ) { continue; }

			$kind = isset( $tpl['kind'] ) ? sanitize_key( $tpl['kind'] ) : 'section';
			if ( ! in_array( $kind, fw_tpl_lib_allowed_kinds(), true ) ) { continue; }

			$thumb_rel = isset( $tpl['thumb'] ) ? (string) $tpl['thumb'] : '';
			$thumb_url = ( $thumb_rel !== '' && $base !== '' ) ? esc_url_raw( $base . ltrim( $thumb_rel, '/' ) ) : '';

			$catalog['templates'][ $slug ] = array(
				'slug'        => $slug,
				'title'       => isset( $tpl['title'] ) ? (string) $tpl['title'] : ucwords( str_replace( '-', ' ', $slug ) ),
				'category'    => isset( $tpl['category'] ) ? (string) $tpl['category'] : __( 'Uncategorized', 'fw' ),
				'kind'        => $kind,
				'description' => isset( $tpl['description'] ) ? (string) $tpl['description'] : '',
				'thumb'       => $thumb_url,
			);
		}

		set_transient( $key, $catalog, 12 * HOUR_IN_SECONDS );
		return $catalog;
	}
endif;

/* -----------------------------------------------------------------------------
 * Installed templates (local scan — no network)
 * -------------------------------------------------------------------------- */

if ( ! function_exists( 'fw_tpl_lib_installed_slugs' ) ) :
	/** Slugs of templates installed under uploads (a dir with template.json + meta.json). */
	function fw_tpl_lib_installed_slugs() {
		$root = fw_tpl_lib_install_dir();
		if ( ! is_dir( $root ) ) { return array(); }

		$slugs = array();
		foreach ( (array) glob( trailingslashit( $root ) . '*', GLOB_ONLYDIR ) as $dir ) {
			$slug = basename( $dir );
			if ( is_readable( $dir . '/template.json' ) && is_readable( $dir . '/meta.json' ) ) {
				$slugs[] = $slug;
			}
		}
		sort( $slugs );
		return $slugs;
	}
endif;

if ( ! function_exists( 'fw_tpl_lib_installed_meta' ) ) :
	/** Decoded meta.json for one installed template, or null. */
	function fw_tpl_lib_installed_meta( $slug ) {
		$slug = sanitize_key( $slug );
		$file = trailingslashit( fw_tpl_lib_install_dir() ) . $slug . '/meta.json';
		if ( ! is_readable( $file ) ) { return null; }
		$meta = json_decode( file_get_contents( $file ), true );
		return is_array( $meta ) ? $meta : null;
	}
endif;

/* -----------------------------------------------------------------------------
 * Install / uninstall
 * -------------------------------------------------------------------------- */

if ( ! function_exists( 'fw_tpl_lib_install' ) ) :
	/**
	 * Download one template envelope from the catalog into the uploads install dir.
	 * Atomic: writes into a temp dir, then renames into place.
	 *
	 * @param string $slug
	 * @return true|WP_Error
	 */
	function fw_tpl_lib_install( $slug ) {
		$slug = sanitize_key( $slug );
		if ( $slug === '' ) { return new WP_Error( 'bad_slug', __( 'Invalid template.', 'fw' ) ); }

		$catalog = fw_tpl_lib_catalog();
		if ( empty( $catalog['templates'][ $slug ] ) ) {
			return new WP_Error( 'not_in_catalog', __( 'That template is not in the catalog.', 'fw' ) );
		}
		if ( $catalog['base_url'] === '' ) {
			return new WP_Error( 'no_base_url', __( 'Catalog is missing a base URL.', 'fw' ) );
		}

		$tpl      = $catalog['templates'][ $slug ];
		$env_url  = $catalog['base_url'] . 'templates/' . $slug . '/' . $slug . '.json';
		$envelope = fw_tpl_lib__fetch_json( $env_url );
		if ( is_wp_error( $envelope ) ) { return $envelope; }

		// Validate the export envelope + its inner builder tree before writing.
		$check = fw_tpl_lib__validate_envelope( $envelope, $tpl['kind'] );
		if ( is_wp_error( $check ) ) { return $check; }

		$root = fw_tpl_lib_install_dir();
		if ( ! wp_mkdir_p( $root ) ) {
			return new WP_Error( 'mkdir_failed', __( 'Could not create the templates folder.', 'fw' ) );
		}

		$dest = trailingslashit( $root ) . $slug;
		$tmp  = $dest . '.tmp-' . wp_generate_password( 6, false );
		if ( ! wp_mkdir_p( $tmp ) ) {
			return new WP_Error( 'mkdir_failed', __( 'Could not create a temp folder.', 'fw' ) );
		}

		$meta = array(
			'title'     => $tpl['title'],
			'slug'      => $slug,
			'category'  => $tpl['category'],
			'kind'      => $tpl['kind'],
			'thumb'     => $tpl['thumb'],
			'installed' => gmdate( 'c' ),
		);

		$ok =
			   ( false !== file_put_contents( $tmp . '/template.json', wp_json_encode( $envelope ) ) )
			&& ( false !== file_put_contents( $tmp . '/meta.json',     wp_json_encode( $meta ) ) );

		if ( ! $ok ) {
			fw_tpl_lib__rrmdir( $tmp );
			return new WP_Error( 'write_failed', __( 'Could not write the template files.', 'fw' ) );
		}

		if ( is_dir( $dest ) ) { fw_tpl_lib__rrmdir( $dest ); }
		if ( ! @rename( $tmp, $dest ) ) {
			fw_tpl_lib__rrmdir( $tmp );
			return new WP_Error( 'install_failed', __( 'Could not finalize the install.', 'fw' ) );
		}

		return true;
	}
endif;

if ( ! function_exists( 'fw_tpl_lib_uninstall' ) ) :
	/**
	 * Remove an installed template. Bundled templates have no uploads dir, so this
	 * is a no-op error for them.
	 *
	 * @param string $slug
	 * @return true|WP_Error
	 */
	function fw_tpl_lib_uninstall( $slug ) {
		$slug = sanitize_key( $slug );
		$dir  = trailingslashit( fw_tpl_lib_install_dir() ) . $slug;
		if ( $slug === '' || ! is_dir( $dir ) ) {
			return new WP_Error( 'not_installed', __( 'That template is not installed.', 'fw' ) );
		}
		fw_tpl_lib__rrmdir( $dir );
		return true;
	}
endif;

/* -----------------------------------------------------------------------------
 * Internal helpers
 * -------------------------------------------------------------------------- */

if ( ! function_exists( 'fw_tpl_lib__validate_envelope' ) ) :
	/**
	 * Validate a downloaded/bundled export envelope: it must be an _fw_template_export
	 * for the page-builder of the expected kind, whose inner `json` decodes to a
	 * non-empty builder tree.
	 *
	 * @param array  $envelope Decoded envelope.
	 * @param string $kind     Expected kind (section|column|full).
	 * @return true|WP_Error
	 */
	function fw_tpl_lib__validate_envelope( $envelope, $kind ) {
		if ( ! is_array( $envelope ) || empty( $envelope['_fw_template_export'] ) || ! is_array( $envelope['_fw_template_export'] ) ) {
			return new WP_Error( 'bad_envelope', __( 'The file is not an Unyson+ template export.', 'fw' ) );
		}
		$hdr = $envelope['_fw_template_export'];

		if ( isset( $hdr['builder_type'] ) && $hdr['builder_type'] !== 'page-builder' ) {
			return new WP_Error( 'bad_builder', __( 'Template was exported from a different builder type.', 'fw' ) );
		}
		if ( isset( $hdr['kind'] ) && $hdr['kind'] !== $kind ) {
			return new WP_Error( 'bad_kind', __( 'Template kind does not match the catalog entry.', 'fw' ) );
		}
		if ( ! isset( $envelope['json'] ) || ! is_string( $envelope['json'] ) || trim( $envelope['json'] ) === '' ) {
			return new WP_Error( 'no_body', __( 'Template file is missing its body.', 'fw' ) );
		}
		$decoded = json_decode( $envelope['json'], true );
		if ( ! is_array( $decoded ) || empty( $decoded ) ) {
			return new WP_Error( 'bad_body', __( 'Template body is not valid builder JSON.', 'fw' ) );
		}
		return true;
	}
endif;

if ( ! function_exists( 'fw_tpl_lib__fetch_json' ) ) :
	/** GET a URL and json_decode it. Returns array|WP_Error. */
	function fw_tpl_lib__fetch_json( $url ) {
		$res = wp_remote_get( $url, array( 'timeout' => 30 ) );
		if ( is_wp_error( $res ) ) { return $res; }
		if ( (int) wp_remote_retrieve_response_code( $res ) !== 200 ) {
			return new WP_Error(
				'http_' . wp_remote_retrieve_response_code( $res ),
				sprintf( __( 'Download failed (%s).', 'fw' ), wp_remote_retrieve_response_code( $res ) )
			);
		}
		$json = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $json ) ) { return new WP_Error( 'bad_json', __( 'Downloaded file was not valid JSON.', 'fw' ) ); }
		return $json;
	}
endif;

if ( ! function_exists( 'fw_tpl_lib__rrmdir' ) ) :
	/** Recursively delete a directory (temp + uninstall). */
	function fw_tpl_lib__rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) { return; }
		foreach ( (array) glob( trailingslashit( $dir ) . '*' ) as $item ) {
			is_dir( $item ) ? fw_tpl_lib__rrmdir( $item ) : @unlink( $item );
		}
		@rmdir( $dir );
	}
endif;

/* -----------------------------------------------------------------------------
 * Installer UI data (admin page payload)
 * -------------------------------------------------------------------------- */

if ( ! function_exists( 'fw_tpl_lib_installer_items' ) ) :
	/**
	 * Flat list the admin grid renders, one entry per template:
	 *   { slug, title, category, kind, description, thumb,
	 *     state: 'bundled'|'installed'|'available' }
	 *
	 * Bundled templates (shipped in the extension) are always present and can't be
	 * removed. Installed templates were downloaded and can be removed. Catalog
	 * entries not present locally are available to install.
	 */
	function fw_tpl_lib_installer_items() {
		$items   = array();
		$present = array();

		// Bundled (always available, read-only).
		foreach ( fw_tpl_lib_bundled_catalog() as $slug => $m ) {
			$items[] = array(
				'slug'        => $slug,
				'title'       => $m['title'],
				'category'    => $m['category'],
				'kind'        => $m['kind'],
				'description' => $m['description'],
				'thumb'       => $m['thumb'],
				'state'       => 'bundled',
			);
			$present[ $slug ] = true;
		}

		// Installed (downloaded → removable). Overrides a bundled slug clash.
		foreach ( fw_tpl_lib_installed_slugs() as $slug ) {
			if ( isset( $present[ $slug ] ) ) { continue; }
			$meta = fw_tpl_lib_installed_meta( $slug );
			if ( ! $meta ) { continue; }
			$items[] = array(
				'slug'        => $slug,
				'title'       => isset( $meta['title'] ) ? (string) $meta['title'] : ucwords( str_replace( '-', ' ', $slug ) ),
				'category'    => isset( $meta['category'] ) ? (string) $meta['category'] : __( 'Uncategorized', 'fw' ),
				'kind'        => isset( $meta['kind'] ) ? (string) $meta['kind'] : 'section',
				'description' => '',
				'thumb'       => isset( $meta['thumb'] ) ? (string) $meta['thumb'] : '',
				'state'       => 'installed',
			);
			$present[ $slug ] = true;
		}

		// Available (in the catalog, not present locally).
		$catalog = fw_tpl_lib_catalog();
		foreach ( ( isset( $catalog['templates'] ) ? $catalog['templates'] : array() ) as $slug => $tpl ) {
			if ( isset( $present[ $slug ] ) ) { continue; }
			$items[] = array(
				'slug'        => $slug,
				'title'       => $tpl['title'],
				'category'    => $tpl['category'],
				'kind'        => $tpl['kind'],
				'description' => $tpl['description'],
				'thumb'       => $tpl['thumb'],
				'state'       => 'available',
			);
		}

		usort( $items, function ( $a, $b ) {
			return strcasecmp( $a['title'], $b['title'] );
		} );

		return $items;
	}
endif;

if ( ! function_exists( 'fw_tpl_lib_installer_categories' ) ) :
	/** Distinct category names across the current item list (sorted). */
	function fw_tpl_lib_installer_categories( $items = null ) {
		$items = is_array( $items ) ? $items : fw_tpl_lib_installer_items();
		$cats  = array();
		foreach ( $items as $it ) {
			if ( ! empty( $it['category'] ) ) { $cats[ $it['category'] ] = true; }
		}
		$cats = array_keys( $cats );
		sort( $cats );
		return $cats;
	}
endif;

if ( ! function_exists( 'fw_tpl_lib_installer_payload' ) ) :
	/** Everything the admin grid JS needs, localized as `upwTemplateLibrary`. */
	function fw_tpl_lib_installer_payload() {
		$catalog = fw_tpl_lib_catalog();
		$items   = fw_tpl_lib_installer_items();
		return array(
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( 'fw_tpl_lib_manage' ),
			'templates'  => $items,
			'installed'  => array_values( fw_tpl_lib_installed_slugs() ),
			'categories' => fw_tpl_lib_installer_categories( $items ),
			'catalogOk'  => ! empty( $catalog['templates'] ),
			'i18n'       => array(
				'all'                => __( 'All categories', 'fw' ),
				'install'            => __( 'Install', 'fw' ),
				'installed'          => __( 'Installed', 'fw' ),
				'remove'             => __( 'Remove', 'fw' ),
				'bundled'            => __( 'Bundled', 'fw' ),
				'refresh'            => __( 'Refresh catalog', 'fw' ),
				'installing'         => __( 'Installing…', 'fw' ),
				'removing'           => __( 'Removing…', 'fw' ),
				'inBuilderHint'      => __( 'Installed — open the page builder and find it under Templates.', 'fw' ),
				'section'            => __( 'Section', 'fw' ),
				'column'             => __( 'Column', 'fw' ),
				'full'               => __( 'Full page', 'fw' ),
				'noThumb'            => __( 'No preview', 'fw' ),
				'empty'              => __( 'No templates match your filter.', 'fw' ),
				'catalogUnavailable' => __( 'The template catalog is unreachable right now. Bundled templates still work; try Refresh later.', 'fw' ),
				'confirmRemove'      => __( 'Remove the “%s” template? Pages where you already used it keep it — this only removes it from the Templates menu.', 'fw' ),
				'genericError'       => __( 'Something went wrong. Please try again.', 'fw' ),
			),
		);
	}
endif;

/* -----------------------------------------------------------------------------
 * AJAX (admin only, capability + nonce gated)
 * -------------------------------------------------------------------------- */

add_action( 'wp_ajax_fw_tpl_lib_manage', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'fw' ) ), 403 );
	}
	check_ajax_referer( 'fw_tpl_lib_manage', 'nonce' );

	$action = isset( $_POST['tpl_action'] ) ? sanitize_key( $_POST['tpl_action'] ) : '';
	$slug   = isset( $_POST['slug'] ) ? sanitize_key( $_POST['slug'] ) : '';

	if ( $action === 'install' ) {
		$r = fw_tpl_lib_install( $slug );
	} elseif ( $action === 'uninstall' ) {
		$r = fw_tpl_lib_uninstall( $slug );
	} elseif ( $action === 'refresh' ) {
		fw_tpl_lib_catalog( true );
		$r = true;
	} else {
		$r = new WP_Error( 'bad_action', __( 'Unknown action.', 'fw' ) );
	}

	if ( is_wp_error( $r ) ) {
		wp_send_json_error( array( 'message' => $r->get_error_message() ) );
	}

	$items = fw_tpl_lib_installer_items();
	wp_send_json_success( array(
		'installed'  => array_values( fw_tpl_lib_installed_slugs() ),
		'templates'  => $items,
		'categories' => fw_tpl_lib_installer_categories( $items ),
	) );
} );
