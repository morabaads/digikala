/* DK Bridge admin — vanilla JS, no build step. */
( function () {
	'use strict';
	if ( ! window.DKB ) {
		return;
	}
	var cfg = window.DKB;
	var t = cfg.i18n || {};

	function api( path, opts ) {
		opts = opts || {};
		var init = {
			method: opts.method || 'GET',
			headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' },
			credentials: 'same-origin'
		};
		if ( opts.body ) {
			init.body = JSON.stringify( opts.body );
		}
		var url = cfg.root + path;
		if ( opts.query ) {
			var qs = Object.keys( opts.query ).map( function ( k ) {
				return encodeURIComponent( k ) + '=' + encodeURIComponent( opts.query[ k ] );
			} ).join( '&' );
			url += ( url.indexOf( '?' ) > -1 ? '&' : '?' ) + qs;
		}
		return fetch( url, init ).then( function ( r ) {
			return r.json().catch( function () {
				return {};
			} ).then( function ( data ) {
				if ( ! r.ok ) {
					var err = new Error( ( data && data.message ) || ( t.error + ' ' + r.status ) );
					err.data = data;
					throw err;
				}
				return data;
			} );
		} );
	}

	function esc( s ) {
		return String( s === null || s === undefined ? '' : s ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	function setResult( el, msg, ok ) {
		if ( ! el ) {
			return;
		}
		el.className = el.className.replace( / ?dkb-(good|bad)/g, '' ) + ( ok === undefined ? '' : ok ? ' dkb-good' : ' dkb-bad' );
		el.textContent = msg;
	}

	function debounce( fn, ms ) {
		var timer;
		return function () {
			var args = arguments, self = this;
			clearTimeout( timer );
			timer = setTimeout( function () {
				fn.apply( self, args );
			}, ms );
		};
	}

	function ring( score ) {
		if ( score === null || score === undefined ) {
			return '<span class="dkb-ring dkb-ring-none">–</span>';
		}
		var cls = score >= 85 ? 'good' : score >= 60 ? 'ok' : 'bad';
		return '<span class="dkb-ring dkb-ring-normal dkb-ring-' + cls + '" style="--p:' + score + '"><span>' + score + '</span></span>';
	}

	var SEV = { error: 'خطا', warning: 'هشدار', info: 'اطلاع' };

	function issuesHtml( issues ) {
		if ( ! issues || ! issues.length ) {
			return '<p class="dkb-ok">ایرادی پیدا نشد.</p>';
		}
		return '<ul class="dkb-issues">' + issues.map( function ( i ) {
			return '<li><span class="dkb-sev dkb-sev-' + esc( i.severity ) + '">' + esc( SEV[ i.severity ] || i.severity ) + '</span> ' + esc( i.message ) +
				( i.fixable ? ' <span class="dkb-fixable">قابل اصلاح خودکار</span>' : '' ) + '</li>';
		} ).join( '' ) + '</ul>';
	}

	function sectionsHtml( score ) {
		if ( ! score || ! score.sections ) {
			return '';
		}
		return '<div class="dkb-sections">' + Object.keys( score.sections ).map( function ( k ) {
			var s = score.sections[ k ];
			var pct = s.max ? Math.round( 100 * s.current / s.max ) : 0;
			return '<div class="dkb-section"><span>' + esc( s.label ) + '</span><span class="dkb-bar"><i style="width:' + pct + '%"></i></span><b>' + s.current + '/' + s.max + '</b></div>';
		} ).join( '' ) + '</div>';
	}

	function evaluationHtml( res ) {
		var html = '<div class="dkb-eval"><div class="dkb-eval-head">' + ring( res.score.percent ) +
			'<div><strong>' + esc( res.data.title_fa ) + '</strong>' +
			( res.data.title_en ? '<div dir="ltr" class="dkb-muted">' + esc( res.data.title_en ) + '</div>' : '' ) +
			'<div class="dkb-muted">برند: ' + esc( res.data.brand.fa || res.data.brand.source || '—' ) + ' · مدل: ' + esc( res.data.model || '—' ) +
			' · گروه: ' + esc( res.data.category.title || '—' ) + ' · قیمت دیجی‌کالا: ' + Number( res.data.price || 0 ).toLocaleString( 'fa-IR' ) + ' ریال · موجودی: ' + esc( res.data.stock ) + '</div></div></div>';
		if ( res.changes && res.changes.length ) {
			html += '<div class="dkb-changes"><strong>اصلاحات انجام‌شده:</strong><ul>' + res.changes.map( function ( c ) {
				return '<li>' + esc( c ) + '</li>';
			} ).join( '' ) + '</ul></div>';
		}
		html += sectionsHtml( res.score ) + issuesHtml( res.issues ) + '</div>';
		return html;
	}

	// ------------------------------------------------------------------ Modal.
	var modal = document.getElementById( 'dkb-modal' );
	function openModal( html ) {
		if ( ! modal ) {
			modal = document.createElement( 'div' );
			modal.id = 'dkb-modal';
			modal.className = 'dkb-modal';
			modal.innerHTML = '<div class="dkb-modal-box"><button type="button" class="dkb-modal-close">×</button><div class="dkb-modal-body"></div></div>';
			document.body.appendChild( modal );
		}
		modal.querySelector( '.dkb-modal-body' ).innerHTML = html;
		modal.hidden = false;
	}
	document.addEventListener( 'click', function ( e ) {
		if ( modal && ( e.target === modal || e.target.classList.contains( 'dkb-modal-close' ) ) ) {
			modal.hidden = true;
		}
	} );
	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key && modal ) {
			modal.hidden = true;
		}
	} );

	function previewHtml( p ) {
		return '<h2>پیش‌نمایش درخواست‌ها</h2>' + sectionsHtml( p.score ) +
			[ [ 'اطلاعات پایه (detail/validation)', p.details ], [ 'ویژگی‌ها (attributes)', p.attributes ], [ 'عنوان و توضیحات (auto-title/save)', p.title ],
				[ 'تصاویر', p.images ], [ 'تنوع‌ها (variants/creation)', p.variants ], [ 'محاسبهٔ قیمت', p.price_info ] ].map( function ( s ) {
				return '<h3>' + esc( s[ 0 ] ) + '</h3><pre dir="ltr" class="dkb-json">' + esc( JSON.stringify( s[ 1 ], null, 2 ) ) + '</pre>';
			} ).join( '' );
	}

	// ------------------------------------------------------- Generic actions.
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '[data-dkb-action]' );
		if ( ! btn ) {
			return;
		}
		var action = btn.getAttribute( 'data-dkb-action' );
		var out = document.querySelector( '[data-dkb-result="' + action + '"]' );
		btn.disabled = true;
		setResult( out, t.loading );
		var req;
		if ( 'connection-test' === action ) {
			req = api( 'connection/test', { method: 'POST' } ).then( function ( r ) {
				var msg = 'اتصال برقرار است' + ( r.profile && r.profile.name ? ' — ' + r.profile.name : '' );
				if ( r.product_scope === false ) {
					msg += ' (هشدار: مجوز محصول ندارد: ' + ( r.scope_error || '' ) + ')';
				}
				setResult( out, msg, r.product_scope !== false );
			} );
		} else if ( 'sync' === action ) {
			req = api( 'sync/' + btn.getAttribute( 'data-what' ), { method: 'POST' } ).then( function ( r ) {
				setResult( out, 'انجام شد (' + r.count + ')', true );
			} );
		} else if ( 'cache-flush' === action ) {
			req = api( 'cache/flush', { method: 'POST' } ).then( function () {
				setResult( out, t.saved, true );
			} );
		} else if ( 'webhook-subscribe' === action ) {
			req = api( 'webhook/subscribe', { method: 'POST' } ).then( function ( r ) {
				var active = r.events && r.events.active_event_types ? r.events.active_event_types.join( '، ' ) : '';
				setResult( out, 'ثبت شد. رویدادهای فعال: ' + active, true );
			} );
		}
		if ( req ) {
			req.catch( function ( err ) {
				setResult( out, err.message, false );
			} ).then( function () {
				btn.disabled = false;
			} );
		}
	} );

	// ---------------------------------------------------------- Products table.
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '[data-dkb-row]' );
		if ( ! btn ) {
			return;
		}
		var row = btn.closest( '[data-id]' );
		var id = row.getAttribute( 'data-id' );
		var what = btn.getAttribute( 'data-dkb-row' );
		btn.disabled = true;
		var done = function () {
			btn.disabled = false;
		};
		if ( 'preview' === what ) {
			api( 'products/' + id + '/preview' ).then( function ( p ) {
				openModal( previewHtml( p ) );
			} ).catch( function ( err ) {
				openModal( '<p class="dkb-bad">' + esc( err.message ) + '</p>' );
			} ).then( done );
			return;
		}
		if ( 'push' === what ) {
			api( 'products/' + id + '/push', { method: 'POST' } ).then( function () {
				var tr = btn.closest( 'tr' );
				var cell = tr && tr.querySelector( '.column-state' );
				if ( cell ) {
					cell.innerHTML = '<span class="dkb-badge dkb-state-queued">' + esc( cfg.states.queued ) + '</span>';
				}
			} ).catch( function ( err ) {
				alert( err.message );
			} ).then( done );
			return;
		}
		api( 'products/' + id + '/' + what, { method: 'POST' } ).then( function ( res ) {
			var tr = btn.closest( 'tr' );
			if ( tr ) {
				var sc = tr.querySelector( '.column-score' );
				var is = tr.querySelector( '.column-issues' );
				if ( sc ) {
					sc.innerHTML = ring( res.score.percent );
				}
				if ( is ) {
					is.innerHTML = issuesHtml( res.issues.filter( function ( i ) {
						return 'info' !== i.severity;
					} ).slice( 0, 3 ) );
				}
			}
			openModal( evaluationHtml( res ) );
		} ).catch( function ( err ) {
			openModal( '<p class="dkb-bad">' + esc( err.message ) + '</p>' );
		} ).then( done );
	} );

	// Bulk actions via REST.
	var form = document.getElementById( 'dkb-products-form' );
	if ( form ) {
		form.addEventListener( 'submit', function ( e ) {
			var sel = form.querySelector( 'select[name="action"]' );
			var sel2 = form.querySelector( 'select[name="action2"]' );
			var action = sel && sel.value !== '-1' ? sel.value : sel2 && sel2.value !== '-1' ? sel2.value : '';
			if ( ! action ) {
				return;
			}
			e.preventDefault();
			var ids = Array.prototype.map.call( form.querySelectorAll( 'input[name="ids[]"]:checked' ), function ( i ) {
				return i.value;
			} );
			if ( ! ids.length ) {
				return;
			}
			if ( 'reset' === action && ! window.confirm( t.confirm ) ) {
				return;
			}
			var button = form.querySelector( '#doaction' );
			if ( button ) {
				button.disabled = true;
				button.value = t.loading;
			}
			api( 'bulk', { method: 'POST', body: { action: action, ids: ids } } ).then( function () {
				window.location.reload();
			} ).catch( function ( err ) {
				alert( err.message );
				if ( button ) {
					button.disabled = false;
				}
			} );
		} );
	}

	// ----------------------------------------------------------------- Meta box.
	var box = document.querySelector( '.dkb-box' );
	if ( box ) {
		var id = box.getAttribute( 'data-id' );
		var out = box.querySelector( '.dkb-box-result' );
		box.addEventListener( 'click', function ( e ) {
			var b = e.target.closest( '[data-dkb-box],[data-dkb-match]' );
			if ( ! b ) {
				return;
			}
			e.preventDefault();
			b.disabled = true;
			out.innerHTML = '<p>' + esc( t.loading ) + '</p>';
			var match = b.getAttribute( 'data-dkb-match' );
			var what = b.getAttribute( 'data-dkb-box' );
			var req;
			if ( match ) {
				req = api( 'products/' + id + '/match', { method: 'POST', body: { decision: match } } ).then( function () {
					out.innerHTML = '<p class="dkb-good">' + esc( t.queued ) + '</p>';
				} );
			} else if ( 'preview' === what ) {
				req = api( 'products/' + id + '/preview' ).then( function ( p ) {
					out.innerHTML = '';
					openModal( previewHtml( p ) );
				} );
			} else if ( 'push-now' === what || 'status' === what ) {
				req = ( 'status' === what ? api( 'products/' + id + '/status', { query: { refresh: 1 } } ) : api( 'products/' + id + '/push-now', { method: 'POST' } ) ).then( function ( s ) {
					var html = '<p><span class="dkb-badge dkb-state-' + esc( s.state ) + '">' + esc( s.state_label || s.state ) + '</span>' +
						( s.dk_product_id ? ' <a target="_blank" rel="noopener" href="https://www.digikala.com/product/dkp-' + esc( s.dk_product_id ) + '/">DKP-' + esc( s.dk_product_id ) + '</a>' : '' ) + '</p>';
					if ( s.last_error ) {
						html += '<p class="dkb-bad">' + esc( s.last_error ) + '</p>';
					}
					if ( s.dry_run ) {
						html += '<p>اجرای آزمایشی — اولین درخواست تغییردهنده که ارسال نشد:</p><pre dir="ltr" class="dkb-json">' + esc( s.dry_run.path + '\n' + JSON.stringify( s.dry_run.body, null, 2 ) ) + '</pre>';
					}
					if ( 'match_review' === s.state ) {
						html += '<p>کالای مشابه پیدا شد؛ صفحه را دوباره بارگذاری کنید تا گزینه‌ها را ببینید.</p>';
					}
					out.innerHTML = html;
				} );
			} else {
				req = api( 'products/' + id + '/' + what, { method: 'POST' } ).then( function ( res ) {
					out.innerHTML = evaluationHtml( res );
					var head = box.querySelector( '.dkb-box-head .dkb-ring' );
					if ( head ) {
						head.outerHTML = ring( res.score.percent ).replace( 'dkb-ring-normal', 'dkb-ring-large' );
					}
					var rep = box.querySelector( '.dkb-report' );
					if ( rep ) {
						rep.innerHTML = '';
					}
					if ( 'autofix' === what ) {
						out.insertAdjacentHTML( 'afterbegin', '<p class="dkb-good">محتوای اصلاح‌شده در «محتوای اختصاصی دیجی‌کالا» ذخیره شد. برای دیدن مقادیر، صفحه را دوباره بارگذاری کنید.</p>' );
					}
				} );
			}
			req.catch( function ( err ) {
				out.innerHTML = '<p class="dkb-bad">' + esc( err.message ) + '</p>';
			} ).then( function () {
				b.disabled = false;
			} );
		} );
	}

	// ------------------------------------------------------- Category mapping.
	var catTable = document.querySelector( '.dkb-catmap' );
	if ( catTable ) {
		var renderList = function ( list, items, row ) {
			list.innerHTML = '';
			if ( ! items.length ) {
				list.innerHTML = '<li class="dkb-muted">' + esc( t.noResults ) + '</li>';
			}
			items.forEach( function ( c ) {
				var li = document.createElement( 'li' );
				li.innerHTML = esc( c.text ) + ' <code>#' + esc( c.id ) + '</code>' + ( c.leaf === false ? ' <em class="dkb-bad">(برگ نیست)</em>' : '' ) + ( c.keyword ? ' <span class="dkb-muted">«' + esc( c.keyword ) + '»</span>' : '' );
				if ( c.leaf !== false ) {
					li.tabIndex = 0;
					li.addEventListener( 'click', function () {
						saveMap( row, c );
					} );
					li.addEventListener( 'keydown', function ( ev ) {
						if ( 'Enter' === ev.key ) {
							saveMap( row, c );
						}
					} );
				} else {
					li.className = 'dkb-disabled';
				}
				list.appendChild( li );
			} );
			list.hidden = false;
		};
		var saveMap = function ( row, c ) {
			var result = row.querySelector( '.dkb-result' );
			setResult( result, t.saving );
			row.querySelector( '.dkb-ac-list' ).hidden = true;
			api( 'category-map', { method: 'POST', body: { term_id: row.getAttribute( 'data-term' ), dk_category_id: c.id, title: c.text } } ).then( function ( r ) {
				row.querySelector( '.dkb-cat-current' ).innerHTML = '<span class="dkb-chip">' + esc( r.title || c.text ) + ' <code>#' + esc( c.id ) + '</code></span>';
				if ( r.valid === false ) {
					setResult( result, 'ذخیره شد، اما فروش در این گروه محدود است: ' + r.errors.join( ' ' ), false );
				} else {
					setResult( result, t.saved, true );
				}
			} ).catch( function ( err ) {
				setResult( result, err.message, false );
			} );
		};
		catTable.addEventListener( 'input', debounce( function ( e ) {
			if ( ! e.target.classList.contains( 'dkb-cat-search' ) ) {
				return;
			}
			var row = e.target.closest( 'tr' );
			var list = row.querySelector( '.dkb-ac-list' );
			var q = e.target.value.trim();
			if ( q.length < 2 ) {
				list.hidden = true;
				return;
			}
			api( 'categories/search', { query: { q: q } } ).then( function ( items ) {
				renderList( list, items || [], row );
			} ).catch( function ( err ) {
				list.innerHTML = '<li class="dkb-bad">' + esc( err.message ) + '</li>';
				list.hidden = false;
			} );
		}, 350 ) );
		catTable.addEventListener( 'click', function ( e ) {
			var row = e.target.closest( 'tr' );
			if ( ! row ) {
				return;
			}
			if ( e.target.classList.contains( 'dkb-cat-suggest' ) ) {
				var list = row.querySelector( '.dkb-ac-list' );
				setResult( row.querySelector( '.dkb-result' ), t.loading );
				api( 'categories/suggest', { query: { term: row.getAttribute( 'data-term' ) } } ).then( function ( r ) {
					setResult( row.querySelector( '.dkb-result' ), r.keyword ? 'بر اساس «' + r.keyword + '»' : '' );
					renderList( list, ( r && r.items ) || [], row );
				} ).catch( function ( err ) {
					setResult( row.querySelector( '.dkb-result' ), err.message, false );
				} );
			}
			if ( e.target.classList.contains( 'dkb-cat-delete' ) && window.confirm( t.confirm ) ) {
				api( 'category-map/' + row.getAttribute( 'data-term' ), { method: 'DELETE' } ).then( function () {
					row.querySelector( '.dkb-cat-current' ).innerHTML = '<span class="dkb-muted">نگاشت نشده</span>';
					e.target.remove();
				} );
			}
		} );
	}

	// ------------------------------------------------------ Attribute mapping.
	var attrSelect = document.getElementById( 'dkb-attr-category' );
	if ( attrSelect ) {
		var tableEl = document.getElementById( 'dkb-attr-table' );
		var status = document.getElementById( 'dkb-attr-status' );
		var saveBtn = document.getElementById( 'dkb-attr-save' );
		var options = function ( a ) {
			if ( ! a.values || 'object' !== typeof a.values ) {
				return [];
			}
			return Object.keys( a.values ).map( function ( k ) {
				var v = a.values[ k ];
				return { id: v && 'object' === typeof v ? k : v, text: v && 'object' === typeof v ? v.text : v };
			} );
		};
		attrSelect.addEventListener( 'change', function () {
			var cat = attrSelect.value;
			tableEl.innerHTML = '';
			saveBtn.hidden = true;
			if ( ! cat ) {
				return;
			}
			setResult( status, t.loading );
			api( 'attributes/' + cat ).then( function ( r ) {
				setResult( status, '' );
				var woo = '<option value="">—</option>' + r.woo.map( function ( w ) {
					return '<option value="' + esc( w.key ) + '">' + esc( w.label ) + ' (' + esc( w.key ) + ')</option>';
				} ).join( '' );
				var rows = r.attributes.map( function ( a ) {
					var m = r.mapping[ a.id ] || {};
					var opts = options( a );
					var optHtml = opts.length ? '<details><summary>' + opts.length + ' گزینه</summary><div class="dkb-opts">' + opts.slice( 0, 200 ).map( function ( o ) {
						return '<code>' + esc( o.id ) + '</code> ' + esc( o.text );
					} ).join( '<br>' ) + '</div></details>' : '';
					return '<tr data-attr="' + esc( a.id ) + '"><td><strong>' + esc( a.title ) + '</strong>' + ( a.required ? ' <span class="dkb-bad">*</span>' : '' ) +
						'<div class="dkb-muted">' + esc( a.group_title || '' ) + ' · ' + esc( a.type || '' ) + ( a.unit ? ' · ' + esc( a.unit ) : '' ) + '</div>' + ( a.hint ? '<div class="dkb-hint">' + esc( a.hint ) + '</div>' : '' ) + '</td>' +
						'<td><select class="dkb-src-type"><option value="none">خودکار</option><option value="attribute">ویژگی ووکامرس</option><option value="meta">متای محصول</option><option value="static">مقدار ثابت</option></select></td>' +
						'<td><select class="dkb-src-attr" hidden>' + woo + '</select><input type="text" class="dkb-src-key regular-text" hidden placeholder="meta key / مقدار"></td><td>' + optHtml + '</td></tr>';
				} ).join( '' );
				tableEl.innerHTML = '<table class="widefat striped dkb-attrmap"><thead><tr><th>ویژگی دیجی‌کالا</th><th>منبع</th><th>کلید</th><th>گزینه‌های مجاز</th></tr></thead><tbody>' + rows + '</tbody></table>';
				Array.prototype.forEach.call( tableEl.querySelectorAll( 'tr[data-attr]' ), function ( tr ) {
					var m = r.mapping[ tr.getAttribute( 'data-attr' ) ];
					var type = tr.querySelector( '.dkb-src-type' );
					var sync = function () {
						tr.querySelector( '.dkb-src-attr' ).hidden = type.value !== 'attribute';
						tr.querySelector( '.dkb-src-key' ).hidden = type.value !== 'meta' && type.value !== 'static';
					};
					if ( m ) {
						type.value = m.source_type;
						if ( 'attribute' === m.source_type ) {
							tr.querySelector( '.dkb-src-attr' ).value = m.source_key;
						} else {
							tr.querySelector( '.dkb-src-key' ).value = m.source_key;
						}
					}
					type.addEventListener( 'change', sync );
					sync();
				} );
				saveBtn.hidden = false;
			} ).catch( function ( err ) {
				setResult( status, err.message, false );
			} );
		} );
		saveBtn.addEventListener( 'click', function () {
			var items = Array.prototype.map.call( tableEl.querySelectorAll( 'tr[data-attr]' ), function ( tr ) {
				var type = tr.querySelector( '.dkb-src-type' ).value;
				return {
					attribute_id: tr.getAttribute( 'data-attr' ),
					source_type: type,
					source_key: 'attribute' === type ? tr.querySelector( '.dkb-src-attr' ).value : tr.querySelector( '.dkb-src-key' ).value
				};
			} );
			saveBtn.disabled = true;
			setResult( status, t.saving );
			api( 'attributes/' + attrSelect.value, { method: 'POST', body: { items: items } } ).then( function () {
				setResult( status, t.saved, true );
			} ).catch( function ( err ) {
				setResult( status, err.message, false );
			} ).then( function () {
				saveBtn.disabled = false;
			} );
		} );
	}

	// Copy webhook URL.
	document.addEventListener( 'click', function ( e ) {
		if ( e.target.classList && e.target.classList.contains( 'dkb-copy' ) && navigator.clipboard ) {
			navigator.clipboard.writeText( e.target.textContent );
			e.target.classList.add( 'dkb-copied' );
		}
	} );

	// ------------------------------------------------- Module screens (generic).
	function money( rial ) {
		if ( rial === null || rial === undefined || rial === '' ) {
			return '—';
		}
		return Math.round( Number( rial ) / 10 ).toLocaleString( 'fa-IR' ) + ' تومان';
	}

	function setPath( obj, path, value ) {
		var parts = path.split( '.' );
		var o = obj;
		for ( var i = 0; i < parts.length - 1; i++ ) {
			o[ parts[ i ] ] = o[ parts[ i ] ] || {};
			o = o[ parts[ i ] ];
		}
		o[ parts[ parts.length - 1 ] ] = value;
	}

	function collectFields( container, body ) {
		if ( ! container ) {
			return body;
		}
		Array.prototype.forEach.call( container.querySelectorAll( '[data-field]' ), function ( input ) {
			var key = input.getAttribute( 'data-field' );
			var val = input.value;
			if ( '' === val ) {
				return;
			}
			if ( 'ids' === key ) {
				val = val.split( /[\s,،]+/ ).filter( Boolean );
			}
			setPath( body, key, val );
		} );
		return body;
	}

	var modalRenderers = {
		json: function ( r ) {
			return '<pre dir="ltr" class="dkb-json">' + esc( JSON.stringify( r, null, 2 ) ) + '</pre>';
		},
		score: function ( r ) {
			var rows = Object.keys( r.sections || {} ).map( function ( k ) {
				var s = r.sections[ k ];
				var weak = s.current < s.max;
				return '<tr><td>' + esc( k ) + '</td><td class="' + ( weak ? 'dkb-bad' : 'dkb-good' ) + '">' + esc( s.current ) + ' / ' + esc( s.max ) + '</td></tr>';
			} ).join( '' );
			return '<h2>امتیاز محتوای دیجی‌کالا: ' + ring( r.percent ) + '</h2><table class="widefat striped">' + rows + '</table>';
		},
		decision: function ( d ) {
			return '<h2>پیشنهاد قیمت</h2><table class="widefat striped"><tbody>' +
				'<tr><td>راهبرد</td><td>' + esc( d.strategy ) + '</td></tr>' +
				'<tr><td>قیمت فعلی</td><td>' + money( d.current ) + '</td></tr>' +
				'<tr><td>برندهٔ بای‌باکس</td><td>' + ( d.is_winner ? 'شما' : money( d.buy_box ) ) + '</td></tr>' +
				'<tr><td>کف سود / سقف</td><td>' + money( d.floor ) + ' / ' + money( d.ceiling ) + '</td></tr>' +
				'<tr><td><strong>قیمت پیشنهادی</strong></td><td><strong>' + money( d.price ) + '</strong> (' + ( 'update' === d.action ? 'تغییر' : 'بدون تغییر' ) + ')</td></tr>' +
				'<tr><td>دلیل</td><td>' + esc( d.reason ) + '</td></tr></tbody></table>';
		},
		decisions: function ( r ) {
			var rows = ( r.decisions || [] ).map( function ( d ) {
				return '<tr><td>DKPC-' + esc( d.variant_id ) + '</td><td>' + money( d.current ) + '</td><td>' + money( d.buy_box ) + '</td><td><strong>' + money( d.price ) + '</strong></td><td>' + ( 'update' === d.action ? '✓' : '—' ) + '</td><td>' + esc( d.reason ) + '</td></tr>';
			} ).join( '' );
			return '<h2>بررسی ' + esc( r.checked ) + ' تنوع، ' + esc( r.updated ) + ' تغییر اعمال شد</h2><table class="widefat striped"><thead><tr><th>تنوع</th><th>فعلی</th><th>بای‌باکس</th><th>جدید</th><th>تغییر</th><th>دلیل</th></tr></thead><tbody>' + ( rows || '<tr><td colspan="6">موردی نیست (قیمت‌گذاری هوشمند باید فعال و راهبرد غیر از «ثابت» باشد).</td></tr>' ) + '</tbody></table>';
		},
		history: function ( rows ) {
			return '<h2>تاریخچهٔ قیمت</h2><table class="widefat striped"><thead><tr><th>زمان</th><th>قبلی</th><th>جدید</th><th>بای‌باکس</th><th>دلیل</th></tr></thead><tbody>' + ( rows || [] ).map( function ( h ) {
				return '<tr><td>' + esc( h.created_at ) + '</td><td>' + money( h.old_price ) + '</td><td>' + money( h.new_price ) + '</td><td>' + money( h.buy_box_price ) + '</td><td>' + esc( h.reason ) + '</td></tr>';
			} ).join( '' ) + '</tbody></table>';
		}
	};

	document.addEventListener( 'click', function ( e ) {
		var btn = e.target.closest( '[data-dkb-call]' );
		if ( ! btn ) {
			return;
		}
		e.preventDefault();
		if ( btn.getAttribute( 'data-confirm' ) && ! window.confirm( t.confirm ) ) {
			return;
		}
		var method = btn.getAttribute( 'data-method' ) || 'POST';
		var body = {};
		try {
			body = JSON.parse( btn.getAttribute( 'data-body' ) || '{}' );
		} catch ( err ) {
			body = {};
		}
		var sel = btn.getAttribute( 'data-ids' );
		if ( sel ) {
			body.ids = Array.prototype.map.call( document.querySelectorAll( sel + ':checked' ), function ( c ) {
				return c.value;
			} );
			if ( ! body.ids.length ) {
				alert( 'ابتدا موردی را انتخاب کنید.' );
				return;
			}
		}
		var fields = btn.getAttribute( 'data-fields' );
		if ( 'row' === fields ) {
			collectFields( btn.closest( '.dkb-row-fields' ) || btn.closest( 'tr' ), body );
		} else if ( fields ) {
			collectFields( btn.closest( '.dkb-bulkbar' ) || btn.parentNode, body );
		}
		var out = ( btn.parentNode && btn.parentNode.querySelector( '.dkb-result' ) ) || null;
		setResult( out, t.loading );
		btn.disabled = true;
		var opts = { method: method };
		if ( 'GET' !== method ) {
			opts.body = body;
		}
		api( btn.getAttribute( 'data-dkb-call' ), opts ).then( function ( r ) {
			var modalType = btn.getAttribute( 'data-modal' );
			if ( modalType && modalRenderers[ modalType ] ) {
				setResult( out, '' );
				openModal( modalRenderers[ modalType ]( r ) );
			} else {
				var msg = t.saved;
				if ( r && 'object' === typeof r ) {
					if ( r.synced !== undefined ) {
						msg = 'همگام شد: ' + r.synced + ' تنوع، ' + r.linked + ' اتصال جدید';
					} else if ( r.marked !== undefined ) {
						msg = r.marked + ' تنوع برای ارسال موجودی علامت خورد';
					} else if ( r.joined !== undefined ) {
						msg = r.joined + ' تنوع به ' + r.promotions + ' پروموشن پیوست';
					} else if ( r.urgent !== undefined ) {
						msg = r.urgent + ' مورد فوری';
					} else if ( r.errors && r.errors.length ) {
						msg = r.ok + ' موفق؛ خطا: ' + r.errors.join( '، ' );
					}
				}
				setResult( out, msg, true );
			}
			if ( btn.getAttribute( 'data-reload' ) ) {
				setTimeout( function () {
					window.location.reload();
				}, 700 );
			}
		} ).catch( function ( err ) {
			setResult( out, err.message, false );
			if ( ! out ) {
				alert( err.message );
			}
		} ).then( function () {
			btn.disabled = false;
		} );
	} );

	document.addEventListener( 'change', function ( e ) {
		if ( e.target.classList && e.target.classList.contains( 'dkb-sel-all' ) ) {
			var table = e.target.closest( 'table' );
			Array.prototype.forEach.call( table.querySelectorAll( '.dkb-sel' ), function ( c ) {
				c.checked = e.target.checked;
			} );
		}
	} );

	// Consignment package from the replenishment plan.
	var pkgBtn = document.getElementById( 'dkb-pkg-create' );
	if ( pkgBtn ) {
		pkgBtn.addEventListener( 'click', function () {
			var items = Array.prototype.map.call( document.querySelectorAll( '.dkb-pkg-qty' ), function ( i ) {
				return { variant_id: i.getAttribute( 'data-variant' ), count: parseInt( i.value, 10 ) || 0 };
			} ).filter( function ( r ) {
				return r.count > 0;
			} );
			var out = document.getElementById( 'dkb-pkg-result' );
			if ( ! items.length ) {
				setResult( out, 'تعدادی برای ارسال وارد نشده است.', false );
				return;
			}
			var total = items.reduce( function ( a, r ) {
				return a + r.count;
			}, 0 );
			if ( ! window.confirm( 'ساخت محموله با ' + items.length + ' تنوع و ' + total + ' عدد کالا؟ هر کالای ثبت‌شده که به انبار نرسد جریمهٔ مغایرت دارد.' ) ) {
				return;
			}
			pkgBtn.disabled = true;
			setResult( out, t.loading );
			api( 'm/logistics/package', { method: 'POST', body: { type: pkgBtn.getAttribute( 'data-type' ), items: items, date: document.getElementById( 'dkb-pkg-date' ).value, delivery_type: document.getElementById( 'dkb-pkg-delivery' ).value } } ).then( function () {
				setResult( out, 'محموله ساخته شد؛ لیبل‌ها را از تب «محموله‌ها» چاپ کنید.', true );
			} ).catch( function ( err ) {
				setResult( out, err.message, false );
			} ).then( function () {
				pkgBtn.disabled = false;
			} );
		} );
	}

	// Promotion plan.
	document.addEventListener( 'click', function ( e ) {
		var b = e.target.closest( '.dkb-promo-plan' );
		if ( ! b ) {
			return;
		}
		var id = b.getAttribute( 'data-id' );
		var load = function ( discount ) {
			openModal( '<p>' + esc( t.loading ) + '</p>' );
			api( 'm/promotions/' + id + '/plan', { query: { discount: discount || 0 } } ).then( function ( plan ) {
				var rows = plan.rows.map( function ( r, i ) {
					return '<tr class="' + ( r.join ? '' : 'dkb-muted' ) + '"><td><input type="checkbox" class="dkb-plan-sel" data-i="' + i + '" ' + ( r.join ? 'checked' : 'disabled' ) + '></td><td>' + esc( r.title ) + ( r.hot ? ' <span class="dkb-badge dkb-state-match_review">پرتقاضا</span>' : '' ) + '<div class="dkb-muted">DKPC-' + esc( r.variant_id ) + ' · فروش هفته ' + esc( r.sales ) + '</div></td><td>' + money( r.selling_price ) + '</td><td><input type="number" class="dkb-plan-price" data-i="' + i + '" value="' + esc( r.promotion_price ) + '" step="100" style="width:120px"></td><td>' + esc( r.discount ) + '٪</td><td>' + money( r.floor ) + '</td><td><input type="number" class="small-text dkb-plan-limit" data-i="' + i + '" value="' + esc( r.price_limit || 1 ) + '" min="1"></td><td>' + esc( r.reason ) + '</td></tr>';
				} ).join( '' );
				openModal( '<h2>' + esc( plan.promotion.title || '' ) + '</h2><p>حداقل تخفیف: ' + esc( plan.min ) + '٪ · <label>تخفیف دلخواه <input type="number" id="dkb-plan-discount" value="' + esc( discount || plan.min ) + '" class="small-text">٪</label> <button type="button" class="button" id="dkb-plan-reload">محاسبهٔ دوباره</button></p>' +
					'<table class="widefat striped"><thead><tr><th></th><th>تنوع</th><th>قیمت فعلی</th><th>قیمت پروموشن (ریال)</th><th>تخفیف</th><th>کف سود</th><th>تعداد</th><th>توضیح</th></tr></thead><tbody>' + ( rows || '<tr><td colspan="8">تنوع واجد شرایطی نیست.</td></tr>' ) + '</tbody></table>' +
					'<p><button type="button" class="button button-primary" id="dkb-plan-join">پیوستن انتخاب‌شده‌ها</button> <span class="dkb-result" id="dkb-plan-result"></span></p>' );
				document.getElementById( 'dkb-plan-reload' ).onclick = function () {
					load( document.getElementById( 'dkb-plan-discount' ).value );
				};
				document.getElementById( 'dkb-plan-join' ).onclick = function () {
					var chosen = Array.prototype.filter.call( document.querySelectorAll( '.dkb-plan-sel' ), function ( c ) {
						return c.checked;
					} ).map( function ( c ) {
						var i = c.getAttribute( 'data-i' );
						var r = plan.rows[ i ];
						return { variant_id: r.variant_id, promotion_price: document.querySelector( '.dkb-plan-price[data-i="' + i + '"]' ).value, price_limit: document.querySelector( '.dkb-plan-limit[data-i="' + i + '"]' ).value, order_limit: r.order_limit || 1 };
					} );
					var out = document.getElementById( 'dkb-plan-result' );
					if ( ! chosen.length ) {
						setResult( out, 'موردی انتخاب نشده.', false );
						return;
					}
					setResult( out, t.loading );
					api( 'm/promotions/' + id + '/join', { method: 'POST', body: { rows: chosen } } ).then( function ( r ) {
						setResult( out, r.ok + ' تنوع ثبت شد' + ( r.errors && r.errors.length ? '؛ خطا: ' + r.errors.join( '، ' ) : '' ), ! ( r.errors && r.errors.length ) );
					} ).catch( function ( err ) {
						setResult( out, err.message, false );
					} );
				};
			} ).catch( function ( err ) {
				openModal( '<p class="dkb-bad">' + esc( err.message ) + '</p>' );
			} );
		};
		load( 0 );
	} );


	// Copy the public key for the seller panel.
	var copyKey = document.getElementById( 'dkb-copy-key' );
	if ( copyKey ) {
		copyKey.addEventListener( 'click', function () {
			var ta = document.getElementById( 'dkb-public-key' );
			var done = function () {
				setResult( document.getElementById( 'dkb-copy-result' ), 'کپی شد؛ در پنل فروشندگان جای‌گذاری کنید.', true );
			};
			if ( navigator.clipboard ) {
				navigator.clipboard.writeText( ta.value ).then( done );
			} else {
				ta.select();
				document.execCommand( 'copy' );
				done();
			}
		} );
	}

}() );
