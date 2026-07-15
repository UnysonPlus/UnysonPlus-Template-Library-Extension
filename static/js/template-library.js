/**
 * Template Library admin grid.
 *
 * Renders the localized `upwTemplateLibrary` payload as a searchable,
 * category-filtered card grid with thumbnails and Install / Remove buttons.
 * Install / Remove / Refresh hit the capability + nonce-gated `fw_tpl_lib_manage`
 * AJAX action and re-render in place. Installed templates then show up in the page
 * builder's Templates menu (wired server-side via the predefined-templates filter).
 */
( function ( $ ) {
	'use strict';

	var cfg = window.upwTemplateLibrary || null;

	function esc( s ) {
		return String( s == null ? '' : s ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	function kindLabel( kind ) {
		if ( kind === 'full' ) { return cfg.i18n.full; }
		if ( kind === 'column' ) { return cfg.i18n.column; }
		return cfg.i18n.section;
	}

	function thumbHtml( t ) {
		if ( t.thumb ) {
			return '<div class="upw-tpl__thumb"><img src="' + esc( t.thumb ) + '" alt="" loading="lazy"></div>';
		}
		return '<div class="upw-tpl__thumb upw-tpl__thumb--empty"><span>' + esc( cfg.i18n.noThumb ) + '</span></div>';
	}

	function actionsHtml( t ) {
		if ( t.state === 'available' ) {
			return '<button type="button" class="button button-primary button-small" data-act="install" data-slug="' + esc( t.slug ) + '">' + esc( cfg.i18n.install ) + '</button>';
		}
		if ( t.state === 'installed' ) {
			return '<span class="upw-tpl__badge upw-tpl__badge--on">' + esc( cfg.i18n.installed ) + '</span>' +
				'<button type="button" class="button button-small upw-tpl__remove" data-act="uninstall" data-slug="' + esc( t.slug ) + '">' + esc( cfg.i18n.remove ) + '</button>';
		}
		// bundled
		return '<span class="upw-tpl__badge">' + esc( cfg.i18n.bundled ) + '</span>';
	}

	function cardHtml( t ) {
		var desc = t.description ? '<p class="upw-tpl__desc">' + esc( t.description ) + '</p>' : '';
		return '' +
			'<div class="upw-tpl__card" data-slug="' + esc( t.slug ) + '"' +
				' data-search="' + esc( ( t.title + ' ' + t.category + ' ' + ( t.description || '' ) ).toLowerCase() ) + '"' +
				' data-category="' + esc( t.category ) + '">' +
				thumbHtml( t ) +
				'<div class="upw-tpl__body">' +
					'<div class="upw-tpl__head">' +
						'<span class="upw-tpl__title">' + esc( t.title ) + '</span>' +
						'<span class="upw-tpl__kind">' + esc( kindLabel( t.kind ) ) + '</span>' +
					'</div>' +
					desc +
					'<div class="upw-tpl__actions">' + actionsHtml( t ) + '</div>' +
				'</div>' +
			'</div>';
	}

	function renderCategories() {
		var $sel = $( '#upw-tpl-category' );
		if ( !$sel.length ) { return; }
		var cur = $sel.val() || '';
		var html = '<option value="">' + esc( cfg.i18n.all ) + '</option>';
		( cfg.categories || [] ).forEach( function ( c ) {
			html += '<option value="' + esc( c ) + '">' + esc( c ) + '</option>';
		} );
		$sel.html( html );
		if ( cur ) { $sel.val( cur ); }
	}

	function renderGrid() {
		var $grid = $( '#upw-tpl-grid' );
		if ( !$grid.length ) { return; }

		if ( !cfg.catalogOk && !( cfg.templates || [] ).some( function ( t ) { return t.state !== 'available'; } ) ) {
			// Nothing local and no catalog: still show bundled if any (handled below);
			// the notice is shown separately.
		}

		$grid.html( ( cfg.templates || [] ).map( cardHtml ).join( '' ) );
		applyFilter();

		var $notice = $( '#upw-tpl-notice' );
		if ( !cfg.catalogOk ) {
			$notice.text( cfg.i18n.catalogUnavailable ).prop( 'hidden', false );
		} else {
			$notice.prop( 'hidden', true );
		}
	}

	function applyFilter() {
		var term = ( $( '#upw-tpl-search' ).val() || '' ).toLowerCase().trim();
		var cat  = $( '#upw-tpl-category' ).val() || '';
		var shown = 0;

		$( '#upw-tpl-grid .upw-tpl__card' ).each( function () {
			var $c = $( this );
			var okTerm = !term || $c.attr( 'data-search' ).indexOf( term ) !== -1;
			var okCat  = !cat || $c.attr( 'data-category' ) === cat;
			var show = okTerm && okCat;
			$c.prop( 'hidden', !show );
			if ( show ) { shown++; }
		} );

		$( '#upw-tpl-empty' ).prop( 'hidden', shown !== 0 );
	}

	function ajax( tplAction, slug ) {
		return $.post( cfg.ajaxUrl, {
			action: 'fw_tpl_lib_manage',
			tpl_action: tplAction,
			slug: slug || '',
			nonce: cfg.nonce
		} );
	}

	function applyState( res ) {
		if ( res && res.success && res.data ) {
			if ( res.data.templates )  { cfg.templates  = res.data.templates; }
			if ( res.data.installed )  { cfg.installed  = res.data.installed; }
			if ( res.data.categories ) { cfg.categories = res.data.categories; }
			return true;
		}
		return false;
	}

	function fail( $card, msg ) {
		$card.removeClass( 'is-busy' );
		$card.find( '.upw-tpl__actions' ).after( '<p class="upw-tpl__error">' + esc( msg ) + '</p>' );
		window.setTimeout( function () {
			$card.find( '.upw-tpl__error' ).fadeOut( 400, function () { $( this ).remove(); } );
		}, 5000 );
	}

	function bind() {
		$( document ).on( 'input', '#upw-tpl-search', applyFilter );
		$( document ).on( 'change', '#upw-tpl-category', applyFilter );

		$( document ).on( 'click', '.upw-tpl [data-act]', function () {
			var $btn = $( this ),
				act  = $btn.data( 'act' ),
				slug = $btn.data( 'slug' ),
				$card = $btn.closest( '.upw-tpl__card' );

			if ( act === 'refresh' ) {
				$btn.prop( 'disabled', true ).text( '…' );
				ajax( 'refresh' ).always( function () { window.location.reload(); } );
				return;
			}

			if ( act === 'uninstall' && !window.confirm( cfg.i18n.confirmRemove.replace( '%s', slug ) ) ) {
				return;
			}

			$card.addClass( 'is-busy' ).find( '.upw-tpl__actions' )
				.html( '<span class="spinner is-active"></span> ' + esc( act === 'install' ? cfg.i18n.installing : cfg.i18n.removing ) );

			ajax( act, slug )
				.done( function ( res ) {
					if ( applyState( res ) ) {
						renderCategories();
						renderGrid();
					} else {
						fail( $card, ( res && res.data && res.data.message ) || cfg.i18n.genericError );
					}
				} )
				.fail( function () { fail( $card, cfg.i18n.genericError ); } );
		} );
	}

	$( function () {
		if ( !cfg ) { return; }
		bind();
		renderCategories();
		renderGrid();
	} );

} )( jQuery );
