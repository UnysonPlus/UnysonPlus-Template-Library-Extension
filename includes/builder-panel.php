<?php if ( ! defined( 'FW' ) ) { die( 'Forbidden' ); }

/**
 * In-builder Template Library panel.
 *
 * When this extension is active, it enqueues a script on the post-edit screens
 * that (JS-side) hides the page-builder's plain "Templates" button and mounts a
 * rich panel in its place: thumbnails, search, category + kind filters, one-click
 * Insert (append or replace), inline Install for catalog templates, a thumbnail
 * lightbox, and a "My Templates" view for the user's own saved templates.
 *
 * This file only supplies the data + assets. Insertion reuses the builder's own
 * API (`builder.rootItems.add()/reset()`), and the "My Templates" actions reuse
 * the builder's existing per-kind endpoints (`fw_builder_templates_<kind>_*`), so
 * nothing here forks the builder core.
 */

if ( ! function_exists( 'fw_tpl_lib_user_templates' ) ) :
	/**
	 * The current user's own saved page-builder templates, scanned straight from
	 * the wp_options rows the builder writes (one per saved template, keyed by a
	 * documented prefix per kind). Predefined/library templates are NOT stored as
	 * options, so this returns only user-saved ones — exactly the "My Templates"
	 * set. The heavy `json` body is fetched lazily on insert via the builder's
	 * own load endpoint, so this stays light.
	 *
	 * @return array list of { id, title, kind, created }
	 */
	function fw_tpl_lib_user_templates() {
		global $wpdb;

		$prefixes = array(
			'section' => 'fw:bt:s:page-builder:',
			'column'  => 'fw:bt:c:page-builder:',
			'full'    => 'fw:bt:f:page-builder:',
		);

		$out = array();

		foreach ( $prefixes as $kind => $prefix ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
					$wpdb->esc_like( $prefix ) . '%'
				),
				ARRAY_A
			);

			foreach ( (array) $rows as $r ) {
				$id  = substr( $r['option_name'], strlen( $prefix ) );
				$val = maybe_unserialize( $r['option_value'] );
				$out[] = array(
					'id'      => $id,
					'title'   => ( is_array( $val ) && isset( $val['title'] ) && $val['title'] !== '' ) ? (string) $val['title'] : $id,
					'kind'    => $kind,
					'created' => ( is_array( $val ) && isset( $val['created'] ) ) ? (int) $val['created'] : 0,
				);
			}
		}

		usort( $out, function ( $a, $b ) {
			return $b['created'] <=> $a['created'];
		} );

		return $out;
	}
endif;

if ( ! function_exists( 'fw_tpl_lib_builder_payload' ) ) :
	/**
	 * The full data the in-builder panel renders: library items (with the builder
	 * tree attached for insertable ones), the user's saved templates, categories,
	 * and catalog availability.
	 */
	function fw_tpl_lib_builder_payload() {
		$items = fw_tpl_lib_installer_items();       // metadata: slug,title,category,kind,description,thumb,state
		$trees = fw_tpl_lib_registered_templates();  // slug => { title, kind, json } for bundled + installed

		foreach ( $items as &$it ) {
			// Attach the builder tree only for insertable (bundled/installed) items;
			// "available" items have no tree until installed.
			if ( isset( $trees[ $it['slug'] ] ) ) {
				$it['json'] = $trees[ $it['slug'] ]['json'];
			}
		}
		unset( $it );

		$catalog = fw_tpl_lib_catalog();

		return array(
			'library'       => array_values( $items ),
			'userTemplates' => fw_tpl_lib_user_templates(),
			'categories'    => fw_tpl_lib_installer_categories( $items ),
			'catalogOk'     => ! empty( $catalog['templates'] ),
		);
	}
endif;

/**
 * AJAX: panel data. Gated on edit_posts (anyone who can use the builder) — no
 * server files are written here, so it's the builder capability, not admin.
 */
add_action( 'wp_ajax_fw_tpl_lib_builder_data', function () {
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'fw' ) ), 403 );
	}
	check_ajax_referer( 'fw_tpl_lib_builder', 'nonce' );

	wp_send_json_success( fw_tpl_lib_builder_payload() );
} );

/**
 * Enqueue the panel assets on the post-edit screens (where the page builder
 * lives). The script self-gates on the builder's init event, so loading it on a
 * post-type that doesn't use the builder is harmless.
 */
add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
		return;
	}

	$ext = fw_ext( 'template-library' );
	if ( ! $ext ) {
		return;
	}

	$version = $ext->manifest->get( 'version' );

	wp_enqueue_style(
		'fw-tpl-lib-builder-panel',
		$ext->get_uri( '/static/css/builder-panel.css' ),
		array(),
		$version
	);

	wp_enqueue_script(
		'fw-tpl-lib-builder-panel',
		$ext->get_uri( '/static/js/builder-panel.js' ),
		// 'backbone' dropped: builder-panel.js contains no Backbone call.
		array( 'jquery', 'fw', 'fw-events' ),
		$version,
		true
	);

	wp_localize_script( 'fw-tpl-lib-builder-panel', 'upwTplPanel', array(
		'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
		'dataNonce'  => wp_create_nonce( 'fw_tpl_lib_builder' ),
		'manageNonce'=> wp_create_nonce( 'fw_tpl_lib_manage' ), // reuse admin installer's install action
		'canInstall' => current_user_can( 'manage_options' ),
		'i18n'       => array(
			'title'            => __( 'Template Library', 'fw' ),
			'library'          => __( 'Library', 'fw' ),
			'myTemplates'      => __( 'My Templates', 'fw' ),
			'searchPlaceholder'=> __( 'Search templates…', 'fw' ),
			'allCategories'    => __( 'All categories', 'fw' ),
			'all'              => __( 'All', 'fw' ),
			'sections'         => __( 'Sections', 'fw' ),
			'columns'          => __( 'Columns', 'fw' ),
			'fulls'            => __( 'Full pages', 'fw' ),
			'kindSection'      => __( 'Section', 'fw' ),
			'kindColumn'       => __( 'Column', 'fw' ),
			'kindFull'         => __( 'Full page', 'fw' ),
			'insert'           => __( 'Insert', 'fw' ),
			'install'          => __( 'Install', 'fw' ),
			'installing'       => __( 'Installing…', 'fw' ),
			'bundled'          => __( 'Bundled', 'fw' ),
			'installed'        => __( 'Installed', 'fw' ),
			'replaceToggle'    => __( 'Replace page instead of appending', 'fw' ),
			'confirmReplace'   => __( 'Replace the entire page with this template? Your current content will be removed.', 'fw' ),
			'noThumb'          => __( 'No preview', 'fw' ),
			'empty'            => __( 'No templates match your filter.', 'fw' ),
			'myEmpty'          => __( 'You haven\'t saved any templates yet. Use the save (download) icon on a section to store one here.', 'fw' ),
			'catalogUnavailable' => __( 'The template catalog is unreachable right now. Bundled and installed templates still work.', 'fw' ),
			'needAdmin'        => __( 'Ask an administrator to install this template.', 'fw' ),
			'close'            => __( 'Close', 'fw' ),
			'delete'           => __( 'Delete', 'fw' ),
			'export'           => __( 'Export', 'fw' ),
			'import'           => __( 'Import template…', 'fw' ),
			'confirmDelete'    => __( 'Delete this saved template? This cannot be undone.', 'fw' ),
			'importBadFile'    => __( 'That file is not a valid Unyson+ template export.', 'fw' ),
			'inserted'         => __( 'Template inserted.', 'fw' ),
			'genericError'     => __( 'Something went wrong. Please try again.', 'fw' ),
			'enlarge'          => __( 'Click to enlarge', 'fw' ),
		),
	) );
} );
