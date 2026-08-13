/**
 * In-builder Template Library panel.
 *
 * Replaces the page-builder's plain "Templates" button with a rich panel:
 * thumbnails, search, category + kind filters, one-click Insert (append or
 * replace), inline Install for catalog templates, a thumbnail lightbox, and a
 * "My Templates" view for the user's own saved templates.
 *
 * Insertion reuses the builder's own API (builder.rootItems.add()/reset()).
 * "My Templates" actions reuse the builder's per-kind endpoints
 * (fw_builder_templates_<kind>_{load,delete,export,import}). Install reuses the
 * admin installer action (fw_tpl_lib_manage). Nothing here forks the builder core.
 */
( function ( $ ) {
	'use strict';

	// Build marker — lets the console debugger confirm which build actually loaded
	// (a stale cached file would leave this undefined or at an older value).
	window.__UPW_TPL_PANEL_BUILD__ = '1.0.4';

	var cfg = window.upwTplPanel || null;
	if ( ! cfg ) { return; }

	var state = {
		builder: null,     // the page-builder Backbone object
		data: null,        // { library, userTemplates, categories, catalogOk }
		tab: 'library',    // 'library' | 'mine'
		kind: 'all',       // 'all' | 'section' | 'column' | 'full'
		cat: '',
		term: '',
		replace: false,    // insert-position: append (false) or replace page (true)
		loading: false
	};

	var $overlay = null;   // built lazily on first open

	/* ---------- helpers ---------- */

	function esc( s ) {
		return String( s == null ? '' : s ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	function hex32() {
		var s = '';
		for ( var i = 0; i < 32; i++ ) { s += Math.floor( Math.random() * 16 ).toString( 16 ); }
		return s;
	}

	// Give every element in a template tree a fresh unique_id, so inserting the
	// same template more than once never collides on a page.
	function regenIds( node ) {
		if ( Array.isArray( node ) ) { node.forEach( regenIds ); return; }
		if ( node && typeof node === 'object' ) {
			for ( var k in node ) {
				if ( ! Object.prototype.hasOwnProperty.call( node, k ) ) { continue; }
				if ( k === 'unique_id' && typeof node[ k ] === 'string' && node[ k ] ) {
					node[ k ] = hex32();
				} else {
					regenIds( node[ k ] );
				}
			}
		}
	}

	function kindLabel( kind ) {
		if ( kind === 'full' ) { return cfg.i18n.kindFull; }
		if ( kind === 'column' ) { return cfg.i18n.kindColumn; }
		return cfg.i18n.kindSection;
	}

	function builderType() {
		return ( state.builder && state.builder.get ) ? state.builder.get( 'type' ) : 'page-builder';
	}

	function toast( msg ) {
		if ( window.fw && fw.soleModal ) {
			fw.soleModal.show( 'upw-tplp-msg', '<p style="padding:6px 2px">' + esc( msg ) + '</p>' );
			setTimeout( function () { try { fw.soleModal.hide(); } catch ( e ) {} }, 1800 );
		}
	}

	/* ---------- insertion ---------- */

	function insertTree( jsonStr, kind ) {
		var tree;
		try { tree = JSON.parse( jsonStr ); } catch ( e ) { toast( cfg.i18n.genericError ); return; }
		regenIds( tree );

		var items = ( kind === 'full' ) ? tree : [ tree ];

		if ( state.replace ) {
			if ( ! window.confirm( cfg.i18n.confirmReplace ) ) { return; }
			state.builder.rootItems.reset( items );
		} else {
			state.builder.rootItems.add( items );
		}

		close();
		toast( cfg.i18n.inserted );
	}

	/* ---------- data ---------- */

	function fetchData( cb ) {
		state.loading = true;
		renderBody();
		$.post( cfg.ajaxUrl, {
			action: 'fw_tpl_lib_builder_data',
			nonce: cfg.dataNonce
		} ).done( function ( res ) {
			state.loading = false;
			if ( res && res.success && res.data ) {
				state.data = res.data;
				renderCategories();
			}
			renderBody();
			if ( cb ) { cb(); }
		} ).fail( function () {
			state.loading = false;
			renderBody();
		} );
	}

	/* ---------- rendering ---------- */

	function build() {
		$overlay = $(
			'<div class="upw-tplp-overlay" role="dialog" aria-modal="true">' +
				'<div class="upw-tplp__backdrop"></div>' +
				'<div class="upw-tplp">' +
					'<div class="upw-tplp__head">' +
						'<div class="upw-tplp__tabs">' +
							'<button type="button" class="upw-tplp__tab" data-tab="library">' + esc( cfg.i18n.library ) + '</button>' +
							'<button type="button" class="upw-tplp__tab" data-tab="mine">' + esc( cfg.i18n.myTemplates ) + '</button>' +
						'</div>' +
						'<button type="button" class="upw-tplp__close" title="' + esc( cfg.i18n.close ) + '" aria-label="' + esc( cfg.i18n.close ) + '">&times;</button>' +
					'</div>' +
					'<div class="upw-tplp__toolbar"></div>' +
					'<div class="upw-tplp__body"></div>' +
				'</div>' +
				'<div class="upw-tplp__light" hidden><img alt=""></div>' +
			'</div>'
		);
		$( document.body ).append( $overlay );

		// close: the × button, the dedicated backdrop, or the overlay padding area
		$overlay.on( 'click', '.upw-tplp__close, .upw-tplp__backdrop', function () { close(); } );
		$overlay.on( 'click', function ( e ) { if ( e.target === $overlay[ 0 ] ) { close(); } } );
		$overlay.on( 'click', '.upw-tplp__tab', function () {
			state.tab = $( this ).data( 'tab' );
			renderTabs(); renderToolbar(); renderBody();
		} );
		$overlay.on( 'input', '.upw-tplp__search', function () { state.term = ( $( this ).val() || '' ).toLowerCase().trim(); renderBody(); } );
		$overlay.on( 'change', '.upw-tplp__cat', function () { state.cat = $( this ).val() || ''; renderBody(); } );
		$overlay.on( 'click', '.upw-tplp__kind', function () { state.kind = $( this ).data( 'kind' ); renderToolbar(); renderBody(); } );
		$overlay.on( 'change', '.upw-tplp__replace', function () { state.replace = $( this ).is( ':checked' ); } );

		// card actions
		$overlay.on( 'click', '[data-act="insert"]', function () {
			var $c = $( this ).closest( '[data-json]' );
			insertTree( $c.attr( 'data-json' ), $c.attr( 'data-kind' ) );
		} );
		$overlay.on( 'click', '[data-act="install"]', function () { installTemplate( $( this ) ); } );
		$overlay.on( 'click', '[data-act="mine-insert"]', function () { mineInsert( $( this ).closest( '[data-id]' ) ); } );
		$overlay.on( 'click', '[data-act="mine-delete"]', function () { mineDelete( $( this ).closest( '[data-id]' ) ); } );
		$overlay.on( 'click', '[data-act="mine-export"]', function () { mineExport( $( this ).closest( '[data-id]' ) ); } );
		$overlay.on( 'click', '[data-act="import"]', function () { $overlay.find( '.upw-tplp__import-file' ).trigger( 'click' ); } );
		$overlay.on( 'change', '.upw-tplp__import-file', function () { mineImport( this ); } );

		// lightbox
		$overlay.on( 'click', '.upw-tplp__thumb img', function () {
			$overlay.find( '.upw-tplp__light img' ).attr( 'src', $( this ).attr( 'src' ) );
			$overlay.find( '.upw-tplp__light' ).prop( 'hidden', false );
		} );
		$overlay.on( 'click', '.upw-tplp__light', function () { $( this ).prop( 'hidden', true ); } );

		$( document ).on( 'keydown.upwTplp', function ( e ) {
			if ( e.key === 'Escape' && $overlay && $overlay.is( ':visible' ) ) {
				if ( ! $overlay.find( '.upw-tplp__light' ).prop( 'hidden' ) ) {
					$overlay.find( '.upw-tplp__light' ).prop( 'hidden', true );
				} else { close(); }
			}
		} );
	}

	function renderTabs() {
		$overlay.find( '.upw-tplp__tab' ).each( function () {
			$( this ).toggleClass( 'is-active', $( this ).data( 'tab' ) === state.tab );
		} );
	}

	function renderCategories() {
		var $sel = $overlay.find( '.upw-tplp__cat' );
		if ( ! $sel.length ) { return; }
		var cur = state.cat;
		var html = '<option value="">' + esc( cfg.i18n.allCategories ) + '</option>';
		( ( state.data && state.data.categories ) || [] ).forEach( function ( c ) {
			html += '<option value="' + esc( c ) + '">' + esc( c ) + '</option>';
		} );
		$sel.html( html ).val( cur );
	}

	function renderToolbar() {
		var $tb = $overlay.find( '.upw-tplp__toolbar' );
		if ( state.tab === 'mine' ) {
			$tb.html(
				'<button type="button" class="button button-secondary" data-act="import">' + esc( cfg.i18n.import ) + '</button>' +
				'<input type="file" class="upw-tplp__import-file" accept="application/json,.json" hidden>' +
				'<label class="upw-tplp__replacewrap"><input type="checkbox" class="upw-tplp__replace"' + ( state.replace ? ' checked' : '' ) + '> ' + esc( cfg.i18n.replaceToggle ) + '</label>'
			);
			return;
		}
		var kinds = [
			[ 'all', cfg.i18n.all ], [ 'full', cfg.i18n.fulls ],
			[ 'section', cfg.i18n.sections ], [ 'column', cfg.i18n.columns ]
		];
		var kindBtns = kinds.map( function ( k ) {
			return '<button type="button" class="upw-tplp__kind' + ( state.kind === k[0] ? ' is-active' : '' ) + '" data-kind="' + k[0] + '">' + esc( k[1] ) + '</button>';
		} ).join( '' );

		$tb.html(
			'<input type="search" class="upw-tplp__search" placeholder="' + esc( cfg.i18n.searchPlaceholder ) + '" value="' + esc( state.term ) + '">' +
			'<select class="upw-tplp__cat"></select>' +
			'<div class="upw-tplp__kinds">' + kindBtns + '</div>' +
			'<label class="upw-tplp__replacewrap"><input type="checkbox" class="upw-tplp__replace"' + ( state.replace ? ' checked' : '' ) + '> ' + esc( cfg.i18n.replaceToggle ) + '</label>'
		);
		renderCategories();
	}

	function thumbHtml( t ) {
		if ( t.thumb ) {
			return '<div class="upw-tplp__thumb" title="' + esc( cfg.i18n.enlarge ) + '"><img src="' + esc( t.thumb ) + '" alt="" loading="lazy"></div>';
		}
		return '<div class="upw-tplp__thumb upw-tplp__thumb--empty"><span>' + esc( cfg.i18n.noThumb ) + '</span></div>';
	}

	function libCardHtml( t ) {
		var insertable = ( t.state === 'bundled' || t.state === 'installed' ) && t.json;
		var action;
		if ( insertable ) {
			action = '<button type="button" class="button button-primary button-small" data-act="insert">' + esc( cfg.i18n.insert ) + '</button>';
		} else if ( cfg.canInstall ) {
			action = '<button type="button" class="button button-small" data-act="install" data-slug="' + esc( t.slug ) + '">' + esc( cfg.i18n.install ) + '</button>';
		} else {
			action = '<span class="upw-tplp__note">' + esc( cfg.i18n.needAdmin ) + '</span>';
		}
		var desc = t.description ? '<p class="upw-tplp__desc">' + esc( t.description ) + '</p>' : '';
		return '' +
			'<div class="upw-tplp__card" data-slug="' + esc( t.slug ) + '" data-kind="' + esc( t.kind ) + '"' + ( t.json ? ' data-json="' + esc( t.json ) + '"' : '' ) + '>' +
				thumbHtml( t ) +
				'<div class="upw-tplp__cbody">' +
					'<div class="upw-tplp__crow"><span class="upw-tplp__ctitle">' + esc( t.title ) + '</span><span class="upw-tplp__kindbadge">' + esc( kindLabel( t.kind ) ) + '</span></div>' +
					( t.category ? '<span class="upw-tplp__cat-tag">' + esc( t.category ) + '</span>' : '' ) +
					desc +
					'<div class="upw-tplp__cactions">' + action + '</div>' +
				'</div>' +
			'</div>';
	}

	function mineCardHtml( t ) {
		return '' +
			'<div class="upw-tplp__card upw-tplp__card--mine" data-id="' + esc( t.id ) + '" data-kind="' + esc( t.kind ) + '">' +
				'<div class="upw-tplp__cbody">' +
					'<div class="upw-tplp__crow"><span class="upw-tplp__ctitle">' + esc( t.title ) + '</span><span class="upw-tplp__kindbadge">' + esc( kindLabel( t.kind ) ) + '</span></div>' +
					'<div class="upw-tplp__cactions">' +
						'<button type="button" class="button button-primary button-small" data-act="mine-insert">' + esc( cfg.i18n.insert ) + '</button>' +
						'<button type="button" class="button button-small" data-act="mine-export">' + esc( cfg.i18n.export ) + '</button>' +
						'<button type="button" class="button-link upw-tplp__del" data-act="mine-delete">' + esc( cfg.i18n.delete ) + '</button>' +
					'</div>' +
				'</div>' +
			'</div>';
	}

	function filteredLibrary() {
		return ( ( state.data && state.data.library ) || [] ).filter( function ( t ) {
			if ( state.kind !== 'all' && t.kind !== state.kind ) { return false; }
			if ( state.cat && t.category !== state.cat ) { return false; }
			if ( state.term ) {
				var hay = ( t.title + ' ' + t.category + ' ' + ( t.description || '' ) ).toLowerCase();
				if ( hay.indexOf( state.term ) === -1 ) { return false; }
			}
			return true;
		} );
	}

	function renderBody() {
		if ( ! $overlay ) { return; }
		var $body = $overlay.find( '.upw-tplp__body' );

		if ( state.loading ) {
			$body.html( '<div class="upw-tplp__loading"><span class="spinner is-active"></span></div>' );
			return;
		}
		if ( ! state.data ) { $body.empty(); return; }

		if ( state.tab === 'mine' ) {
			var mine = state.data.userTemplates || [];
			$body.html( mine.length
				? '<div class="upw-tplp__grid">' + mine.map( mineCardHtml ).join( '' ) + '</div>'
				: '<p class="upw-tplp__empty">' + esc( cfg.i18n.myEmpty ) + '</p>'
			);
			return;
		}

		var lib = filteredLibrary();
		var notice = ( ! state.data.catalogOk )
			? '<div class="upw-tplp__notice">' + esc( cfg.i18n.catalogUnavailable ) + '</div>' : '';
		$body.html( notice + ( lib.length
			? '<div class="upw-tplp__grid">' + lib.map( libCardHtml ).join( '' ) + '</div>'
			: '<p class="upw-tplp__empty">' + esc( cfg.i18n.empty ) + '</p>'
		) );
	}

	/* ---------- library install ---------- */

	function installTemplate( $btn ) {
		var slug = $btn.data( 'slug' );
		$btn.prop( 'disabled', true ).text( cfg.i18n.installing );
		$.post( cfg.ajaxUrl, {
			action: 'fw_tpl_lib_manage',
			tpl_action: 'install',
			slug: slug,
			nonce: cfg.manageNonce
		} ).done( function ( res ) {
			if ( res && res.success ) {
				fetchData(); // refresh so the newly installed item gains its json + becomes insertable
			} else {
				$btn.prop( 'disabled', false ).text( cfg.i18n.install );
				toast( ( res && res.data && res.data.message ) || cfg.i18n.genericError );
			}
		} ).fail( function () {
			$btn.prop( 'disabled', false ).text( cfg.i18n.install );
			toast( cfg.i18n.genericError );
		} );
	}

	/* ---------- My Templates (reuse builder's own endpoints) ---------- */

	function mineInsert( $card ) {
		var id = $card.data( 'id' ), kind = $card.data( 'kind' );
		$.post( cfg.ajaxUrl, {
			action: 'fw_builder_templates_' + kind + '_load',
			builder_type: builderType(),
			template_id: id
		} ).done( function ( res ) {
			if ( res && res.success && res.data && res.data.json ) {
				insertTree( res.data.json, kind );
			} else { toast( cfg.i18n.genericError ); }
		} ).fail( function () { toast( cfg.i18n.genericError ); } );
	}

	function mineDelete( $card ) {
		if ( ! window.confirm( cfg.i18n.confirmDelete ) ) { return; }
		var id = $card.data( 'id' ), kind = $card.data( 'kind' );
		$.post( cfg.ajaxUrl, {
			action: 'fw_builder_templates_' + kind + '_delete',
			builder_type: builderType(),
			template_id: id
		} ).done( function ( res ) {
			if ( res && res.success ) {
				state.data.userTemplates = ( state.data.userTemplates || [] ).filter( function ( t ) {
					return ! ( t.id === id && t.kind === kind );
				} );
				renderBody();
			} else { toast( cfg.i18n.genericError ); }
		} ).fail( function () { toast( cfg.i18n.genericError ); } );
	}

	function mineExport( $card ) {
		var id = $card.data( 'id' ), kind = $card.data( 'kind' );
		$.post( cfg.ajaxUrl, {
			action: 'fw_builder_templates_' + kind + '_export',
			builder_type: builderType(),
			template_id: id
		} ).done( function ( res ) {
			if ( res && res.success && res.data && res.data.content ) {
				var blob = new Blob( [ JSON.stringify( res.data.content, null, 2 ) ], { type: 'application/json' } );
				var a = document.createElement( 'a' );
				a.href = URL.createObjectURL( blob );
				a.download = res.data.filename || ( 'template-' + kind + '.json' );
				document.body.appendChild( a ); a.click();
				setTimeout( function () { URL.revokeObjectURL( a.href ); a.remove(); }, 100 );
			} else { toast( cfg.i18n.genericError ); }
		} ).fail( function () { toast( cfg.i18n.genericError ); } );
	}

	function mineImport( input ) {
		var file = input.files && input.files[ 0 ];
		input.value = '';
		if ( ! file ) { return; }
		var reader = new FileReader();
		reader.onload = function () {
			var kind = '';
			try {
				var parsed = JSON.parse( reader.result );
				kind = parsed && parsed._fw_template_export && parsed._fw_template_export.kind;
			} catch ( e ) {}
			if ( kind !== 'section' && kind !== 'column' && kind !== 'full' ) {
				toast( cfg.i18n.importBadFile ); return;
			}
			var fd = new FormData();
			fd.append( 'action', 'fw_builder_templates_' + kind + '_import' );
			fd.append( 'builder_type', builderType() );
			fd.append( 'template_file', file );
			$.ajax( { url: cfg.ajaxUrl, method: 'POST', data: fd, processData: false, contentType: false } )
				.done( function ( res ) {
					if ( res && res.success ) { fetchData(); }
					else { toast( ( res && res.data && res.data.message ) || cfg.i18n.importBadFile ); }
				} )
				.fail( function () { toast( cfg.i18n.genericError ); } );
		};
		reader.readAsText( file );
	}

	/* ---------- open / close ---------- */

	function open() {
		if ( ! $overlay ) { build(); }
		$overlay.css( 'display', 'flex' );
		renderTabs(); renderToolbar(); renderBody();
		fetchData();
	}

	function close() {
		if ( $overlay ) { $overlay.css( 'display', 'none' ); }
	}

	/* ---------- mount into the builder header ---------- */

	$( document.body ).on( 'fw:option-type:builder:init', function ( e, data ) {
		// Only the page-builder gets the panel (other builder types keep native).
		if ( data.builder && data.builder.get && data.builder.get( 'type' ) !== 'page-builder' ) {
			return;
		}
		state.builder = data.builder;

		var $tools = data.$headerTools || $( e.target ).find( '.builder-header-tools' );
		if ( ! $tools || ! $tools.length ) { return; }

		$tools.addClass( 'upw-tpl-active' );

		// Remove the native Templates button ENTIRELY so its tooltip popover can never
		// open alongside our panel (that co-existence caused a stuck, unclickable
		// overlay). We run this now AND after the current event dispatch, because the
		// native handler listens to the same event and may add its button after ours.
		function killNative() {
			$tools.children( '.template-container' ).not( '.upw-tpl-mine' ).each( function () {
				try { $( this ).find( '.template-btn' ).fwTooltip( 'destroy', true ); } catch ( err ) {}
				$( this ).remove();
			} );
		}
		killNative();
		setTimeout( killNative, 0 );
		setTimeout( killNative, 400 );

		// Our own trigger — a distinct class so the native tooltip selector can't bind to it.
		if ( ! $tools.find( '.upw-tpl-mine' ).length ) {
			var $btn = $(
				'<div class="template-container fw-pull-right upw-tpl-mine">' +
					'<a class="upw-tplp-trigger" href="#" onclick="return false;">' + esc( cfg.i18n.title ) + '</a>' +
				'</div>'
			);
			$tools.append( $btn );
			$btn.on( 'click', '.upw-tplp-trigger', open );
		}
	} );

} )( jQuery );
