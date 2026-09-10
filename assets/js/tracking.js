/**
 * Insightistic - Frontend Engagement Tracking
 * Posts custom events to the Insightistic server-side collector, which then
 * forwards them to the GA4 Measurement Protocol. The Measurement Protocol
 * secret stays on the server and is never exposed to the browser.
 * < 3KB minified (budget enforced by `npm run size`). Loaded only when enabled in Settings.
 *
 * @package Insightistic
 */
( function () {
	'use strict';

	if ( typeof ispTracking === 'undefined' ) return;

	var cfg    = ispTracking;
	var origin = window.location.hostname;

	function pageUrl() {
		return window.location.href;
	}

	/* ------------------------------------------------------------------ */
	/* Server-side collector helper                                        */
	/* ------------------------------------------------------------------ */
	function sendEvent( name, params ) {
		var body = new URLSearchParams( {
			action   : cfg.action,
			nonce    : cfg.nonce,
			event    : name,
			client_id: getClientId(),
			params   : JSON.stringify( params || {} )
		} );

		if ( navigator.sendBeacon ) {
			navigator.sendBeacon( cfg.endpoint, body );
		} else if ( window.fetch ) {
			fetch( cfg.endpoint, { method: 'POST', body: body, keepalive: true } );
		}
	}

	function getClientId() {
		var id = readCookie( '_ga' );
		if ( id ) {
			var parts = id.split( '.' );
			if ( parts.length >= 4 ) return parts[2] + '.' + parts[3];
		}
		id = readCookie( 'isp_cid' );
		if ( ! id ) {
			id = Math.floor( Math.random() * 1e9 ) + '.' + Math.floor( Date.now() / 1000 );
			document.cookie = 'isp_cid=' + id + ';max-age=63072000;path=/;SameSite=Lax';
		}
		return id;
	}

	function readCookie( name ) {
		var match = document.cookie.match( new RegExp( '(^| )' + name + '=([^;]+)' ) );
		return match ? match[2] : '';
	}

	/* ------------------------------------------------------------------ */
	/* Outbound Link Tracking                                              */
	/* ------------------------------------------------------------------ */
	if ( cfg.trackOutbound ) {
		document.addEventListener( 'click', function ( e ) {
			var link = e.target.closest( 'a' );
			if ( ! link ) return;
			var href = link.href || '';
			if ( href && link.hostname && link.hostname !== origin && ! href.startsWith( 'javascript' ) ) {
				sendEvent( 'outbound_link_click', {
					link_url   : href,
					link_domain: link.hostname,
					link_text  : ( link.textContent || '' ).trim().slice( 0, 100 )
				} );
			}
		} );
	}

	/* ------------------------------------------------------------------ */
	/* File Download Tracking                                              */
	/* ------------------------------------------------------------------ */
	if ( cfg.trackDownloads ) {
		var downloadExts = /\.(pdf|zip|docx?|xlsx?|pptx?|mp3|mp4|csv|rar|7z|tar|gz|dmg|exe|pkg)(\?|#|$)/i;
		document.addEventListener( 'click', function ( e ) {
			var link = e.target.closest( 'a' );
			if ( ! link ) return;
			var href = link.href || '';
			if ( downloadExts.test( href ) ) {
				var ext  = href.match( downloadExts );
				sendEvent( 'file_download', {
					file_url  : href,
					file_name : href.split( '/' ).pop().split( '?' )[0],
					file_ext  : ext ? ext[1].toLowerCase() : ''
				} );
			}
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Scroll Depth Tracking                                               */
	/* ------------------------------------------------------------------ */
	if ( cfg.trackScroll ) {
		var scrollFired   = {};
		var scrollMilestones = [ 25, 50, 75, 100 ];

		function onScroll() {
			var scrollTop   = window.pageYOffset || document.documentElement.scrollTop;
			var docHeight   = document.documentElement.scrollHeight - document.documentElement.clientHeight;
			var pct         = docHeight > 0 ? Math.round( ( scrollTop / docHeight ) * 100 ) : 0;

			for ( var i = 0; i < scrollMilestones.length; i++ ) {
				var milestone = scrollMilestones[ i ];
				if ( pct >= milestone && ! scrollFired[ milestone ] ) {
					scrollFired[ milestone ] = true;
					sendEvent( 'scroll_depth', {
						percent_scrolled: milestone,
						page_location   : pageUrl()
					} );
				}
			}
		}

		var scrollTimer;
		window.addEventListener( 'scroll', function () {
			clearTimeout( scrollTimer );
			scrollTimer = setTimeout( onScroll, 200 );
		}, { passive: true } );
	}

	/* ------------------------------------------------------------------ */
	/* Form Submit + Site Search Tracking                                  */
	/* ------------------------------------------------------------------ */
	document.addEventListener( 'submit', function ( e ) {
		var f = e.target;
		if ( ! f || f.tagName !== 'FORM' ) return;
		var q = f.querySelector( 'input[name="s"],input[type="search"]' );
		if ( q && q.value ) {
			sendEvent( 'site_search', {
				search_term  : ( q.value + '' ).slice( 0, 100 ),
				page_location: pageUrl()
			} );
			return;
		}
		sendEvent( 'form_submit', {
			form_id      : f.id || '',
			form_action  : ( f.action || '' ).slice( 0, 150 ),
			page_location: pageUrl()
		} );
	}, true );

	/* ------------------------------------------------------------------ */
	/* Video Play Tracking (first play per video)                          */
	/* ------------------------------------------------------------------ */
	var playedVideos = {};
	document.addEventListener( 'play', function ( e ) {
		var v = e.target;
		if ( ! v || v.tagName !== 'VIDEO' ) return;
		var src = v.currentSrc || v.src || '';
		if ( playedVideos[ src ] ) return;
		playedVideos[ src ] = 1;
		sendEvent( 'video_play', {
			video_url    : src.slice( 0, 150 ),
			page_location: pageUrl()
		} );
	}, true );

	/* ------------------------------------------------------------------ */
	/* Content Copy Tracking (once per page view)                          */
	/* ------------------------------------------------------------------ */
	var copyFired = false;
	document.addEventListener( 'copy', function () {
		if ( copyFired ) return;
		copyFired = true;
		var sel = String( ( window.getSelection && window.getSelection() ) || '' );
		sendEvent( 'content_copy', {
			copy_length  : sel.length,
			page_location: pageUrl()
		} );
	} );

	/* ------------------------------------------------------------------ */
	/* Element Click Tracking (.isp-track)                                */
	/* ------------------------------------------------------------------ */
	if ( cfg.trackEvents && cfg.eventSelectors && cfg.eventSelectors.length ) {
		document.addEventListener( 'click', function ( e ) {
			for ( var i = 0; i < cfg.eventSelectors.length; i++ ) {
				var el = e.target.closest( cfg.eventSelectors[ i ] );
				if ( el ) {
					sendEvent( 'element_click', {
						element_id   : el.id || '',
						element_class: el.className || '',
						element_text : ( el.textContent || '' ).trim().slice( 0, 100 ),
						page_location: pageUrl()
					} );
					break;
				}
			}
		} );
	}

} )();
