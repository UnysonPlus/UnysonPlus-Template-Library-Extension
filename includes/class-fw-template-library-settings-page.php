<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Admin page for the Template Library (Unyson+ → Template Library).
 *
 * A bespoke management dashboard — a searchable, category-filtered card grid with
 * thumbnails and Install / Remove buttons — modeled on the Shortcodes settings
 * page (so, per project convention, NOT wrapped in metabox-holder/postbox chrome).
 * The cards are rendered client-side from a localized payload; the page body just
 * hosts the toolbar + an empty grid container.
 */
class FW_Template_Library_Settings_Page {

	const PARENT     = 'fw-extensions';
	const PAGE_SLUG  = 'fw-template-library';
	const CAPABILITY = 'manage_options';

	/** @var FW_Extension_Template_Library */
	private $extension;

	/** @var string|null */
	private $hook_suffix = null;

	public function __construct( FW_Extension_Template_Library $extension ) {
		$this->extension = $extension;

		// Late priority so the parent (Extensions) menu is already registered.
		add_action( 'admin_menu', array( $this, '_action_admin_menu' ), 100 );
		add_action( 'admin_enqueue_scripts', array( $this, '_action_enqueue' ) );
	}

	public static function get_page_url() {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
	}

	/**
	 * @internal
	 */
	public function _action_admin_menu() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$this->hook_suffix = add_submenu_page(
			self::PARENT,
			__( 'Template Library', 'fw' ),
			__( 'Template Library', 'fw' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * @internal
	 */
	public function _action_enqueue( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		$version = $this->extension->manifest->get( 'version' );

		wp_enqueue_style(
			'fw-ext-template-library',
			$this->extension->get_uri( '/static/css/template-library.css' ),
			array(),
			$version
		);

		wp_enqueue_script(
			'fw-ext-template-library',
			$this->extension->get_uri( '/static/js/template-library.js' ),
			array( 'jquery' ),
			$version,
			true
		);

		wp_localize_script( 'fw-ext-template-library', 'upwTemplateLibrary', fw_tpl_lib_installer_payload() );
	}

	/**
	 * @internal
	 */
	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		?>
		<div class="wrap upw-tpl">
			<h1><?php esc_html_e( 'Template Library', 'fw' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Browse premade page-builder templates and install the ones you want. Installed templates appear in the page builder under Templates — click to drop one onto your page. Downloaded templates live in your uploads folder, so the plugin stays lean.', 'fw' ); ?>
			</p>

			<div class="upw-tpl__toolbar">
				<input type="search" id="upw-tpl-search" class="upw-tpl__search"
					placeholder="<?php esc_attr_e( 'Search templates…', 'fw' ); ?>" />
				<select id="upw-tpl-category" class="upw-tpl__category"></select>
				<button type="button" class="button upw-tpl__refresh" data-act="refresh"><?php esc_html_e( 'Refresh catalog', 'fw' ); ?></button>
			</div>

			<div class="upw-tpl__notice" id="upw-tpl-notice" hidden></div>

			<div class="upw-tpl__grid" id="upw-tpl-grid" aria-live="polite"></div>

			<p class="upw-tpl__empty" id="upw-tpl-empty" hidden><?php esc_html_e( 'No templates match your filter.', 'fw' ); ?></p>
		</div>
		<?php
	}
}
