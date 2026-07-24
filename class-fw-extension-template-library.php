<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Template Library extension.
 *
 * Ships a browsable catalog of premade page-builder templates (sections, columns,
 * whole pages). The client-side plumbing is tiny because we reuse two things the
 * page builder already has:
 *
 *   1. Remote fetch/install — mirrors the icon-pack installer: a GitHub
 *      `catalog.json` is fetched (12h transient), and each template's export
 *      envelope is downloaded on demand into
 *      wp-content/uploads/unysonplus-templates/<slug>/ so the plugin stays lean.
 *
 *   2. Insertion — installed (and bundled) templates are fed into the builder's
 *      native read-only "predefined templates" lists via the public filter
 *      `fw_ext_builder:predefined_templates:page-builder:<kind>`. Clicking one in
 *      Templates → Sections/Columns/Full drops it onto the canvas with the
 *      builder's existing JS — no custom insertion code.
 *
 * A handful of templates ship bundled (under this extension's templates/ folder)
 * so the library is useful even before the catalog is reachable; the resolver
 * treats bundled and downloaded templates identically (downloaded wins on a slug
 * clash).
 */
class FW_Extension_Template_Library extends FW_Extension {

	/** @var FW_Template_Library_Settings_Page|null */
	private $settings_page = null;

	/**
	 * @internal
	 */
	public function _init() {
		// Procedural helpers: catalog fetch, install/uninstall, AJAX, payload.
		require_once dirname( __FILE__ ) . '/includes/installer.php';

		// Feed bundled + installed templates into the builder's predefined lists.
		require_once dirname( __FILE__ ) . '/includes/predefined-templates.php';

		// The rich in-builder panel that replaces the plain Templates inserter. Its
		// hooks (admin_enqueue_scripts, wp_ajax_*) self-gate, so it's required
		// unconditionally like the installer/predefined-templates includes.
		require_once dirname( __FILE__ ) . '/includes/builder-panel.php';

		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/includes/class-fw-template-library-settings-page.php';
			$this->settings_page = new FW_Template_Library_Settings_Page( $this );
		}
	}

	/**
	 * Absolute filesystem path to the bundled-templates folder (no trailing slash).
	 */
	public function get_templates_path() {
		return $this->get_path( '/templates' );
	}

	/**
	 * Public URI of the bundled-templates folder (for thumbnails).
	 */
	public function get_templates_uri() {
		return $this->get_uri( '/templates' );
	}
}
