/**
 * Extroverting — frontend interactivity.
 *
 * Vanilla JS (no build step). Wires: save stars, age gate, login OTP flow,
 * registration, city list view toggle + sort + category chips, month grid,
 * dashboard routes/retailers fetch, settings saves, and quiz shell.
 *
 * REST-first: all new code uses `extroverting/v1` + `extroverting-r2/v1` via
 * X-WP-Nonce (see `EXT.rest` / `EXT.restNonce` localized by the theme).
 * Legacy admin-ajax (`EXT.ajax`) is retained only for backwards compat with
 * existing server handlers — prefer `restAuth()` / `rest()` / `wp.apiFetch`
 * for new integrations so blocks and headless consumers can share the same
 * endpoints.
 */
( function () {
	'use strict';

	var C = window.EXT || {};
	var $ = function ( s, r ) { return ( r || document ).querySelector( s ); };
	var $$ = function ( s, r ) { return Array.prototype.slice.call( ( r || document ).querySelectorAll( s ) ); };

	function toast( msg ) {
		var t = document.createElement( 'div' );
		t.style.cssText = 'position:fixed;left:50%;bottom:26px;transform:translateX(-50%);background:#16241d;color:#fff;padding:13px 20px;border-radius:999px;font-weight:700;font-size:15px;z-index:90;box-shadow:0 12px 40px rgba(20,36,29,.2);max-width:90vw;text-align:center';
		t.textContent = msg;
		document.body.appendChild( t );
		setTimeout( function () { t.style.transition = 'opacity .3s'; t.style.opacity = '0'; }, 2300 );
		setTimeout( function () { t.remove(); }, 2600 );
	}

	function post( action, data ) {
		var fd = new FormData();
		fd.append( 'action', action );
		fd.append( 'nonce', C.nonce || '' );
		Object.keys( data || {} ).forEach( function ( k ) { fd.append( k, data[ k ] ); } );
		return fetch( C.ajax || 'admin-ajax.php', { method: 'POST', credentials: 'same-origin', body: fd } )
			.then( function ( r ) { return r.json(); } );
	}

	function rest( path, opts ) {
		return fetch( ( C.rest || '' ) + path, Object.assign( {
			method: 'GET',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': C.restNonce || '' }
		}, opts ) ).then( function ( r ) { return r.json(); } );
	}

	// REST-first auth: prefer extroverting/v1/auth/*, fall back to admin-ajax on failure.
	function restAuth( endpoint, data ) {
		if ( ! C.rest ) {
			// No REST config — degrade to legacy admin-ajax.
			return post( endpoint.replace( 'auth/', 'ext_' ).replace( '/', '_' ), data );
		}
		return fetch( C.rest + endpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': C.restNonce || '' },
			body: JSON.stringify( data )
		} ).then( function ( r ) { return r.json().then( function ( j ) {
			// Normalize REST success shape to the legacy { success, data: {...} } shape
			// so callers (login/verify/register) need no fork.
			if ( j && j.code ) {
				// WP_Error: { code, message, data: { status } }
				return { success: false, data: { error: j.message || j.code } };
			}
			if ( j && typeof j.ok !== 'undefined' || j.message ) {
				// Already in REST shape { message, channel, redirect, ... }
				// Wrap to legacy envelope for minimal churn.
				return { success: true, data: j };
			}
			return { success: true, data: j };
		} ); } ).catch( function () {
			// Network failure — fallback to admin-ajax for resilience.
			return post( endpoint.replace( 'auth/', 'ext_' ).replace( '/', '_' ), data );
		} );
	}

	function validId( v ) {
		v = ( v || '' ).trim();
		return /.+@.+\..+/.test( v ) || /^\(?\d{3}\)?[\s.-]?\d{3}[\s.-]?\d{4}$/.test( v );
	}
	function validZip( v ) { return /^\d{5}$/.test( ( v || '' ).trim() ); }
	function setErr( name, msg ) {
		var f = $( '[data-f="' + name + '"]' );
		var e = $( '[data-err="' + name + '"]' );
		if ( f ) f.classList.add( 'invalid' );
		if ( e ) { e.textContent = msg; e.classList.add( 'show' ); }
	}
	function clearErrs( root ) {
		$$ ( '.ext-field.invalid', root ).forEach( function ( f ) { f.classList.remove( 'invalid' ); } );
		$$ ( '.ext-err.show', root ).forEach( function ( e ) { e.classList.remove( 'show' ); e.textContent = ''; } );
	}
	function genUser() {
		var c = 'abcdefghjkmnpqrstuvwxyz23456789', s = '';
		for ( var i = 0; i < 5; i++ ) s += c[ Math.floor( Math.random() * c.length ) ];
		return 'ext-' + s;
	}

	/* ---------------- Save stars ---------------- */
	function refreshStar( btn, saved ) {
		btn.classList.toggle( 'saved', saved );
		btn.setAttribute( 'aria-pressed', saved ? 'true' : 'false' );
		var g = btn.querySelector( '.glyph' );
		if ( g ) g.textContent = saved ? '★' : '☆';
		btn.lastChild.textContent = saved ? ' Saved' : ' Save';
	}

	function onSaveClick( e ) {
		var btn = e.target.closest( '[data-save]' );
		if ( ! btn ) return;
		var id = btn.getAttribute( 'data-save' );
		if ( ! C.logged_in ) {
			var back = encodeURIComponent( window.location.pathname );
			window.location = ( window.location.origin ) + '/account/register?back=' + back;
			return;
		}
		rest( 'events/' + id + '/save', { method: 'POST' } ).then( function ( r ) {
			if ( r && typeof r.saved === 'boolean' ) {
				refreshStar( btn, r.saved );
				toast( r.saved ? 'Added to your calendar ✓' : 'Removed from your calendar' );
			}
		} ).catch( function () { toast( 'We couldn\'t reach the service. Try again in a moment.' ); } );
	}
	document.addEventListener( 'click', onSaveClick );

	/* ---------------- Age gate ---------------- */
	$$ ( '[data-age-yes]' ).forEach( function ( b ) {
		b.addEventListener( 'click', function () {
			post( 'ext_age_gate_confirm', { yes: 1 } ).then( function () {
				window.location.reload();
			} );
		} );
	} );
	$$ ( '[data-age-no]' ).forEach( function ( b ) {
		b.addEventListener( 'click', function () {
			post( 'ext_age_gate_confirm', { yes: 0 } ).then( function ( r ) {
				window.location = ( r && r.data && r.data.redirect ) || '/';
			} );
		} );
	} );

	/* ---------------- Login (OTP) ---------------- */
	var loginContact = '';
	var sendCodeBtn = $( '[data-send-code]' );
	if ( sendCodeBtn ) {
		sendCodeBtn.addEventListener( 'click', function () {
			clearErrs();
			var v = $( '#ext-login-id' ).value;
			if ( ! validId( v ) ) { setErr( 'loginid', 'Enter a valid email or mobile number.' ); return; }
			loginContact = v.trim();
			restAuth( 'auth/request-otp', { contact: loginContact } ).then( function ( r ) {
				if ( r.success ) {
					$( '[data-login-step="1"]' ).hidden = true;
					$( '[data-login-step="2"]' ).hidden = false;
					toast( r.data.message );
					var dev = $( '[data-dev-code]' );
					if ( dev ) {
						if ( r.data.dev_code ) {
							dev.textContent = 'Demo code: ' + r.data.dev_code;
							dev.hidden = false;
						} else {
							dev.hidden = true;
						}
					}
					var first = $( '[data-code-row] input' );
					if ( first ) first.focus();
				} else if ( r.data && r.data.error ) {
					setErr( 'loginid', r.data.error );
				}
			} );
		} );
	}
	var resend = $( '[data-resend-code]' );
	if ( resend ) {
		resend.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			restAuth( 'auth/request-otp', { contact: loginContact } ).then( function ( r ) {
				toast( r.success ? r.data.message : ( r.data && r.data.error ) || 'Resend failed' );
			} );
		} );
	}
	// Auto-advance code inputs.
	$$ ( '[data-code-row] input' ).forEach( function ( inp, i, arr ) {
		inp.addEventListener( 'input', function () {
			if ( inp.value && arr[ i + 1 ] ) arr[ i + 1 ].focus();
		} );
	} );
	var doLogin = $( '[data-do-login]' );
	if ( doLogin ) {
		doLogin.addEventListener( 'click', function () {
			var code = $$ ( '[data-code-row] input' ).map( function ( i ) { return i.value; } ).join( '' );
			restAuth( 'auth/verify-otp', { contact: loginContact, code: code } ).then( function ( r ) {
				if ( r.success ) {
					toast( r.data.message );
					window.location = r.data.redirect || '/dashboard';
				} else if ( r.data && r.data.error ) {
					toast( r.data.error );
				}
			} );
		} );
	}

	/* ---------------- Register ---------------- */
	var regForm = $( '[data-reg-form]' );
	var userVal = $( '#ext-user-val' );
	if ( userVal ) userVal.value = genUser();
	var regen = $( '[data-regen-user]' );
	if ( regen ) regen.addEventListener( 'click', function () { userVal.value = genUser(); } );
	$$ ( '[data-show-consent], [data-show-consent2]' ).forEach( function ( b ) {
		b.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			var target = b.hasAttribute( 'data-show-consent2' ) ? $( '[data-full-consent2]' ) : $( '[data-full-consent]' );
			if ( target ) target.hidden = ! target.hidden;
		} );
	} );
	if ( regForm ) {
		regForm.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			clearErrs( regForm );
			var ok = true;
			var id = $( '#ext-reg-id' ).value;
			var hz = $( '#ext-home-zip' ).value;
			var wz = $( '#ext-work-zip' ).value;
			var un = userVal.value.trim();
			if ( ! validId( id ) ) { setErr( 'id', 'Enter a valid email or mobile number.' ); ok = false; }
			if ( ! validZip( hz ) && ! validZip( wz ) ) {
				var ze = $( '[data-err="zips"]' );
				if ( ze ) { ze.textContent = 'Enter your home or work ZIP so we can find nearby events and retailers.'; ze.classList.add( 'show' ); }
				ok = false;
			} else if ( ( hz && ! validZip( hz ) ) || ( wz && ! validZip( wz ) ) ) {
				var ze2 = $( '[data-err="zips"]' );
				if ( ze2 ) { ze2.textContent = 'Enter a 5-digit ZIP code.'; ze2.classList.add( 'show' ); }
				ok = false;
			}
			if ( ! un ) { setErr( 'user', 'Pick a username or generate one.' ); ok = false; }
			if ( ! ok ) return;
			restAuth( 'auth/register', {
				contact: id.trim(), home_zip: hz.trim(), work_zip: wz.trim(),
				username: un, first: $( '#ext-fname' ).value.trim(), last: $( '#ext-lname' ).value.trim(),
				crm: $( '#ext-crm-opt' ).checked ? 1 : 0
			} ).then( function ( r ) {
				if ( r.success ) {
					toast( r.data.message );
					setTimeout( function () { window.location = r.data.redirect || '/dashboard/calendar'; }, 700 );
				} else if ( r.data && r.data.error ) {
					toast( r.data.error );
				}
			} );
		} );
	}

	/* ---------------- City lander: view toggle / sort / chips ---------------- */
	$$ ( '[data-view="list"], [data-view="cal"]' ).forEach( function ( b ) {
		b.addEventListener( 'click', function () {
			$$ ( '[data-view]' ).forEach( function ( x ) { x.setAttribute( 'aria-pressed', 'false' ); } );
			b.setAttribute( 'aria-pressed', 'true' );
			var list = $( '[data-citylist]' );
			var grid = $( '[data-calgrid]' );
			if ( b.getAttribute( 'data-view' ) === 'cal' ) {
				if ( list ) list.style.display = 'none';
				if ( grid ) { grid.hidden = false; buildMonthGrid( grid ); }
			} else {
				if ( list ) list.style.display = '';
				if ( grid ) grid.hidden = true;
			}
		} );
	} );
	var sortSel = $( '[data-sort]' );
	if ( sortSel ) sortSel.addEventListener( 'change', function () {
		var url = new URL( window.location );
		url.searchParams.set( 'sort', sortSel.value );
		window.location = url.toString();
	} );
	$$ ( '[data-catchips] [data-cat]' ).forEach( function ( b ) {
		b.addEventListener( 'click', function () {
			$$ ( '[data-catchips] [data-cat]' ).forEach( function ( x ) { x.setAttribute( 'aria-pressed', 'false' ); } );
			b.setAttribute( 'aria-pressed', 'true' );
			var url = new URL( window.location );
			var c = b.getAttribute( 'data-cat' );
			if ( c ) url.searchParams.set( 'cat', c ); else url.searchParams.delete( 'cat' );
			window.location = url.toString();
		} );
	} );

	function buildMonthGrid( grid ) {
		if ( ! grid ) return;
		grid.classList.add( 'ext-calgrid' );
		// Collect events from the rendered list (already filtered/sorted server-side).
		var rows = $$ ( '[data-citylist] .ext-eventrow' );
		var byDay = {};
		rows.forEach( function ( r ) {
			var num = r.querySelector( '.ext-datecell .num' );
			var title = r.querySelector( '.ext-eventbody h3' );
			if ( num && title ) {
				var d = parseInt( num.textContent, 10 );
				( byDay[ d ] = byDay[ d ] || [] ).push( title.textContent );
			}
		} );
		var today = new Date();
		var first = new Date( today.getFullYear(), today.getMonth(), 1 );
		var startDow = ( first.getDay() + 6 ) % 7; // Monday-start
		var daysIn = new Date( today.getFullYear(), today.getMonth() + 1, 0 ).getDate();
		var DOW = [ 'M', 'T', 'W', 'T', 'F', 'S', 'S' ];
		var html = DOW.map( function ( w ) { return '<div class="ext-cal-dow" role="columnheader">' + w + '</div>'; } ).join( '' );
		for ( var d = 1; d <= daysIn; d++ ) {
			var isToday = d === today.getDate();
			var evs = byDay[ d ] || [];
			var offset = ( d === 1 && startDow > 0 ) ? ' style="grid-column-start:' + ( startDow + 1 ) + '"' : '';
			html += '<div class="ext-cal-cell' + ( isToday ? ' today' : '' ) + '"' + offset + '><div class="cn">' + d + '</div>';
			evs.slice( 0, 3 ).forEach( function ( t ) {
				html += '<span class="chip-ev' + ( isToday ? ' tonight' : '' ) + '">' + t.replace( /</g, '&lt;' ).slice( 0, 24 ) + '</span>';
			} );
			html += '</div>';
		}
		grid.innerHTML = html;
	}

	/* ---------------- Directions (event detail) ---------------- */
	$$ ( '[data-directions]' ).forEach( function ( b ) {
		b.addEventListener( 'click', function () {
			var id = b.getAttribute( 'data-directions' );
			if ( ! C.logged_in ) { toast( 'Sign in to save routes.' ); return; }
			// Directions service is D5-agnostic: stub for now.
			rest( 'account/routes', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': C.restNonce || '' },
				body: JSON.stringify( { origin: 'home', event_id: parseInt( id, 10 ) } )
			} ).then( function ( r ) {
				toast( r && r.ok ? 'Route saved ✓ (directions by [service])' : 'Directions service unavailable.' );
			} ).catch( function () { toast( 'We couldn\'t load directions. Try again in a moment.' ); } );
		} );
	} );

	/* ---------------- Dashboard: routes ---------------- */
	var routesBody = $( '[data-routes-body]' );
	function loadRoutes( origin ) {
		if ( ! routesBody || ! C.logged_in ) return;
		rest( 'account/routes?origin=' + origin ).then( function ( r ) {
			var list = ( r && r.routes ) || [];
			if ( ! list.length ) {
				routesBody.innerHTML = '<div class="ext-empty"><div class="em-ic">🧭</div><h3>No saved routes yet.</h3><p>Open an event and tap "Directions" to save one.</p></div>';
				return;
			}
			routesBody.innerHTML = '<div class="ext-eventlist">' + list.map( function ( rt ) {
				return '<div class="ext-eventrow" style="grid-template-columns:1fr auto">' +
					'<div class="ext-eventbody"><h3 style="cursor:default">' + ( origin === 'home' ? 'Home' : 'Work' ) + ' → ' + rt.to + '</h3>' +
					'<div class="ext-evmeta"><span class="t ext-tnum">' + ( rt.dist || '—' ) + ' · about ' + ( rt.eta || '—' ) + '</span></div></div>' +
					'<div class="ext-eventactions"><button class="ext-btn ext-btn-ghost ext-btn-sm" data-rmroute data-orig="' + origin + '" data-evt="' + rt.event_id + '">Remove</button></div></div>';
			} ).join( '' ) + '</div>';
		} ).catch( function () {
			routesBody.innerHTML = '<div class="ext-empty"><div class="em-ic">⚠️</div><h3>We couldn\'t load routes.</h3><p>Try again in a moment.</p><button class="ext-btn ext-btn-ghost" data-retry>Retry</button></div>';
		} );
	}
	if ( routesBody ) {
		loadRoutes( routesBody.getAttribute( 'data-origin' ) || 'home' );
		$$ ( '[data-route-orig] button' ).forEach( function ( b ) {
			b.addEventListener( 'click', function () {
				$$ ( '[data-route-orig] button' ).forEach( function ( x ) { x.setAttribute( 'aria-pressed', 'false' ); } );
				b.setAttribute( 'aria-pressed', 'true' );
				loadRoutes( b.getAttribute( 'data-orig' ) );
			} );
		} );
		document.addEventListener( 'click', function ( e ) {
			var rm = e.target.closest( '[data-rmroute]' );
			if ( ! rm ) return;
			rest( 'account/routes?origin=' + rm.getAttribute( 'data-orig' ) + '&event_id=' + rm.getAttribute( 'data-evt' ), {
				method: 'DELETE', headers: { 'X-WP-Nonce': C.restNonce || '' }
			} ).then( function () { loadRoutes( rm.getAttribute( 'data-orig' ) ); } );
		} );
	}

	/* ---------------- Dashboard: retailers ---------------- */
	var retailBody = $( '[data-retail-body]' );
	function loadRetailers( ctx ) {
		if ( ! retailBody || ! C.logged_in ) return;
		if ( ctx === 'venue' ) {
			retailBody.innerHTML = '<div class="ext-empty"><div class="em-ic">🏬</div><h3>No retailers found yet.</h3><p>Open an event to see places near its venue.</p></div>';
			return;
		}
		rest( 'retailers?ctx=' + ctx ).then( function ( r ) {
			var list = ( r && r.retailers ) || [];
			if ( ! list.length ) {
				retailBody.innerHTML = '<div class="ext-empty"><div class="em-ic">🏬</div><h3>No retailers found yet.</h3><p>Add a ZIP in Settings to see nearby retailers.</p></div>';
				return;
			}
			retailBody.innerHTML = '<div class="ext-eventlist">' + list.map( function ( rt ) {
				return '<div class="ext-eventrow" style="grid-template-columns:1fr auto"><div class="ext-eventbody"><h3 style="cursor:default">' + rt.name + '</h3>' +
					'<div class="ext-evmeta"><span>' + ( rt.cat || '' ) + '</span></div></div>' +
					'<div class="ext-eventactions"><button class="ext-star' + ( rt.saved ? ' saved' : '' ) + '" data-save-retailer="' + rt.id + '" aria-pressed="' + ( rt.saved ? 'true' : 'false' ) + '"><span class="glyph">' + ( rt.saved ? '★' : '☆' ) + '</span> ' + ( rt.saved ? 'Saved' : 'Save' ) + '</button></div></div>';
			} ).join( '' ) + '</div>';
		} ).catch( function () {
			retailBody.innerHTML = '<div class="ext-empty"><div class="em-ic">⚠️</div><h3>We couldn\'t load retailers.</h3><p>Try again in a moment. ([service])</p><button class="ext-btn ext-btn-ghost" data-retry>Retry</button></div>';
		} );
	}
	if ( retailBody ) {
		loadRetailers( retailBody.getAttribute( 'data-ctx' ) || 'home' );
		$$ ( '[data-retail-ctx] button' ).forEach( function ( b ) {
			b.addEventListener( 'click', function () {
				$$ ( '[data-retail-ctx] button' ).forEach( function ( x ) { x.setAttribute( 'aria-pressed', 'false' ); } );
				b.setAttribute( 'aria-pressed', 'true' );
				loadRetailers( b.getAttribute( 'data-ctx' ) );
			} );
		} );
	}

	/* ---------------- Settings ---------------- */
	function patchAccount( data ) {
		return fetch( ( C.rest || '' ) + 'account', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': C.restNonce || '' },
			body: JSON.stringify( data )
		} ).then( function ( r ) { return r.json(); } );
	}
	var saveProfile = $( '[data-save-profile]' );
	if ( saveProfile ) saveProfile.addEventListener( 'click', function () {
		patchAccount( {
			username: $( '#ext-s-user' ).value,
			first: $( '#ext-s-fname' ).value,
			last: $( '#ext-s-lname' ).value
		} ).then( function ( r ) {
			toast( r && r.ok ? 'Changes saved ✓' : ( r && ( r.message || 'Could not save. Try again.' ) ) );
		} ).catch( function () { toast( 'We couldn\'t save your changes. Try again.' ); } );
	} );
	var saveLoc = $( '[data-save-loc]' );
	if ( saveLoc ) saveLoc.addEventListener( 'click', function () {
		var hz = $( '#ext-s-home-zip' ).value;
		var wz = $( '#ext-s-work-zip' ).value;
		if ( ! /^\d{5}$/.test( hz ) && ! /^\d{5}$/.test( wz ) ) {
			toast( 'Enter your home or work ZIP so we can find nearby events and retailers.' );
			return;
		}
		patchAccount( { home_zip: hz, work_zip: wz } ).then( function ( r ) {
			toast( r && r.ok ? 'Locations saved ✓' : ( r && ( r.message || 'Could not save. Try again.' ) ) );
		} ).catch( function () { toast( 'We couldn\'t save your locations. Try again.' ); } );
	} );
	var crmToggle = $( '#ext-s-crm' );
	if ( crmToggle ) crmToggle.addEventListener( 'change', function () {
		patchAccount( { crm: crmToggle.checked ? 1 : 0 } ).then( function ( r ) {
			toast( crmToggle.checked ? 'Updates on ✓' : 'Updates off' );
		} ).catch( function () { toast( 'We couldn\'t update your preference. Try again.' ); } );
	} );
	var sContact = $( '[data-s-contact]' );
	if ( sContact && C.logged_in ) {
		rest( 'account' ).then( function ( r ) { if ( r && r.contact ) sContact.value = r.contact; } );
	}

	/* ---------------- Quiz shell ---------------- */
	var quizShell = window.location.pathname.match( /\/events\/[a-z0-9-]+\/quiz$/ );
	var resultsShell = window.location.pathname.match( /\/events\/[a-z0-9-]+\/results$/ );
	// The quiz/results shells are rendered server-side by the provider when
	// those sub-routes exist; the JS here only handles client-side question
	// navigation once the provider markup is present.
	$$ ( '[data-quiz-shell] .ext-opt' ).forEach( function ( b ) {
		b.addEventListener( 'click', function () {
			var shell = b.closest( '[data-quiz-shell]' );
			var multi = shell && shell.getAttribute( 'data-multi' ) === '1';
			if ( multi ) {
				b.setAttribute( 'aria-pressed', b.getAttribute( 'aria-pressed' ) === 'true' ? 'false' : 'true' );
			} else {
				$$ ( '.ext-opt', shell ).forEach( function ( x ) { x.setAttribute( 'aria-pressed', 'false' ); } );
				b.setAttribute( 'aria-pressed', 'true' );
			}
			var err = shell && shell.querySelector( '.ext-err' );
			if ( err ) err.style.display = 'none';
		} );
	} );

	/* ---------------- Retry buttons ---------------- */
	document.addEventListener( 'click', function ( e ) {
		if ( e.target.closest( '[data-retry]' ) ) window.location.reload();
	} );
}() );
