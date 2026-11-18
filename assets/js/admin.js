/* global insightisticPro, Chart */
'use strict';

( function ( $ ) {

	/* ------------------------------------------------------------------ */
	/* State                                                               */
	/* ------------------------------------------------------------------ */
	var timelineChart = null;
	var sourcesChart  = null;
	var currentData   = null;
	var currentGSCData = null;
	var currentPSIData = null;
	var lastAIHtml    = '';
	var lastAIHtmlByContext = {};

	// Tracks which dashboard panels have been loaded at least once so tab
	// switches can lazy-load instead of forcing the user to click a button.
	var dataLoaded     = { overview: false, gsc: false, psi: false, woo: false, cloudflare: false };
	var cfTimelineChart = null;
	var cfStatusChart   = null;
	var lastCfData      = null;
	var wooTimelineCh  = null;
	var wooStatusCh    = null;
	var currentWooData = null;
	var lastWooAIHtml  = '';

	/* ================================================================== */
	/* UTILITY HELPERS                                                     */
	/* ================================================================== */

	function fmt( n ) {
		return Number( n ).toLocaleString();
	}

	function fmtMoney( n ) {
		return '$' + Number( n ).toLocaleString( undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 } );
	}

	function escHtml( str ) {
		// AJAX error payloads can be objects (for example { code, message }).
		// Always reduce them to a human-readable string before String() so the
		// admin UI never exposes the unhelpful "[object Object]".
		if ( str && 'object' === typeof str ) {
			str = ajaxPayloadMessage( str ) || '';
		}
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	function truncate( str, len ) {
		return str.length > len ? str.slice( 0, len ) + '…' : str;
	}

	function changeClass( val ) {
		if ( val > 0 ) return 'isp-change-up';
		if ( val < 0 ) return 'isp-change-down';
		return 'isp-change-flat';
	}

	function changeArrow( val ) {
		if ( val > 0 ) return ' +' + val + '%';
		if ( val < 0 ) return ' ' + val + '%';
		return '';
	}

	function changeHtml( val ) {
		if ( isNaN( val ) || 0 === val ) {
			return '<span class="isp-change-flat"></span>';
		}
		var sign  = val > 0 ? '+' : '';
		var cls   = val > 0 ? 'isp-change-up' : 'isp-change-down';
		return '<span class="' + cls + '">' + sign + val + '% ' + insightisticPro.i18n.vsLastPeriod + '</span>';
	}

	function spinnerBtn( $btn, text ) {
		$btn.find( '.isp-btn-text' ).text( text );
		$btn.find( '.isp-btn-icon' ).html( '<span class="isp-inline-spinner"></span>' );
		$btn.prop( 'disabled', true );
	}

	function resetBtn( $btn, icon, text ) {
		$btn.find( '.isp-btn-text' ).text( text );
		$btn.find( '.isp-btn-icon' ).text( icon );
		$btn.prop( 'disabled', false );
	}

	function channelIcon( channel ) {
		var c = ( channel || '' ).toLowerCase();
		if ( c.indexOf( 'organic' ) !== -1 ) return '';
		if ( c.indexOf( 'direct' ) !== -1 )  return '';
		if ( c.indexOf( 'social' ) !== -1 )   return '';
		if ( c.indexOf( 'email' ) !== -1 )    return '';
		if ( c.indexOf( 'paid' ) !== -1 || c.indexOf( 'cpc' ) !== -1 ) return '';
		if ( c.indexOf( 'referral' ) !== -1 ) return '';
		if ( c.indexOf( 'affiliate' ) !== -1 )return '';
		return '';
	}

	/**
	 * Build a "just now / 4 min ago / 2 hours ago" string from a UNIX
	 * timestamp returned by the PHP cache layer.
	 */
	function relativeTime( ts ) {
		if ( ! ts ) { return ''; }
		var diff = Math.max( 0, Math.floor( Date.now() / 1000 ) - ts );
		var t = insightisticPro.i18n;
		if ( diff < 30 )    { return t.justNow || 'just now'; }
		if ( diff < 90 )    { return ( t.minuteAgo || '1 min ago' ); }
		if ( diff < 3600 )  { return Math.round( diff / 60 ) + ' ' + ( t.minutesAgo || 'min ago' ); }
		if ( diff < 5400 )  { return ( t.hourAgo || '1 hour ago' ); }
		if ( diff < 86400 ) { return Math.round( diff / 3600 ) + ' ' + ( t.hoursAgo || 'hours ago' ); }
		return Math.round( diff / 86400 ) + ' ' + ( t.daysAgo || 'days ago' );
	}

	/**
	 * Skeleton placeholder for sections that are loading.
	 */
	function skeletonHtml() {
		return '<div class="isp-skeleton-wrap" role="status" aria-live="polite">' +
			'<span class="screen-reader-text">' + escHtml( insightisticPro.i18n.loading ) + '</span>' +
			'<div class="isp-skeleton-block isp-skeleton-row"></div>' +
			'<div class="isp-skeleton-block isp-skeleton-row"></div>' +
			'<div class="isp-skeleton-block isp-skeleton-row isp-skeleton-row-sm"></div>' +
			'</div>';
	}

	/**
	 * Pull a useful message out of a WordPress/Laravel JSON error payload.
	 */
	function ajaxPayloadMessage( payload ) {
		if ( ! payload ) {
			return '';
		}
		if ( 'string' === typeof payload ) {
			return $.trim( payload );
		}
		if ( Array.isArray( payload ) ) {
			for ( var i = 0; i < payload.length; i++ ) {
				var arrayMessage = ajaxPayloadMessage( payload[ i ] );
				if ( arrayMessage ) {
					return arrayMessage;
				}
			}
			return '';
		}
		if ( 'object' !== typeof payload ) {
			return String( payload );
		}
		// Prefer the API's explicit message, but recurse because some gateway
		// responses wrap it under data/error/details as an object.
		var fields = [ 'message', 'error', 'detail', 'details', 'data' ];
		for ( var fieldIndex = 0; fieldIndex < fields.length; fieldIndex++ ) {
			var field = fields[ fieldIndex ];
			if ( Object.prototype.hasOwnProperty.call( payload, field ) ) {
				var fieldMessage = ajaxPayloadMessage( payload[ field ] );
				if ( fieldMessage ) {
					return fieldMessage;
				}
			}
		}
		return '';
	}

	function responseMessage( res, fallback ) {
		return ajaxPayloadMessage( res && res.data ) || ajaxPayloadMessage( res ) || fallback || insightisticPro.i18n.error;
	}

	/**
	 * Extract a concrete diagnostic message from a failed jqXHR so the user
	 * sees what actually went wrong instead of the generic "Something went
	 * wrong" fallback. Handles three common cases:
	 *   1. WordPress nonce rejection (admin-ajax.php body is literal "-1"
	 *      or status 0/403)  asks the user to hard-refresh.
	 *   2. PHP fatal (HTML body instead of JSON)  surfaces HTTP status +
	 *      first 200 chars of the response so the cause is visible.
	 *   3. Empty / network error (status 0)  reports "network error".
	 */
	function diagnoseAjaxError( jqXHR, textStatus ) {
		var i18n = insightisticPro.i18n || {};
		var status = jqXHR ? jqXHR.status : 0;
		var body   = jqXHR ? ( jqXHR.responseText || '' ) : '';
		var trim   = body.replace( /^\s+|\s+$/g, '' );
		var parsed = jqXHR ? ajaxPayloadMessage( jqXHR.responseJSON ) : '';

		if ( ! parsed && trim && ( '{' === trim.charAt( 0 ) || '[' === trim.charAt( 0 ) ) ) {
			try {
				parsed = ajaxPayloadMessage( JSON.parse( trim ) );
			} catch ( e ) {
				parsed = '';
			}
		}
		if ( parsed ) {
			return parsed;
		}

		// WordPress check_ajax_referer() failure returns "-1" with status 200,
		// or 403 when DOING_AJAX nonce check fails harder.
		if ( '-1' === trim || 403 === status || 401 === status ) {
			return i18n.sessionExpired || 'Your session expired. Please hard-refresh the page (Ctrl+Shift+R) and try again.';
		}
		if ( 0 === status ) {
			return ( i18n.error || 'Something went wrong.' ) + ' (network error)';
		}
		// PHP fatal / unexpected HTML body.
		if ( status >= 500 || ( trim.length && '{' !== trim.charAt( 0 ) && '[' !== trim.charAt( 0 ) ) ) {
			var excerpt = trim.replace( /<[^>]+>/g, ' ' ).replace( /\s+/g, ' ' ).slice( 0, 200 );
			var prefix  = ( i18n.serverError || 'Server returned an error' ) + ' (HTTP ' + status + ')';
			return excerpt ? prefix + ': ' + excerpt : prefix;
		}
		return i18n.error || 'Something went wrong.';
	}

	/**
	 * Inline error with a Retry button + a link to Settings.
	 */
	function errorBox( msg, retryLabel, retryCb, settingsHash ) {
		var $box = $( '<div class="isp-initial-state"></div>' );
		var $notice = $( '<div class="isp-notice isp-notice-error" role="alert"></div>' ).text( msg );
		$box.append( $notice );

		var $actions = $( '<div class="isp-error-actions"></div>' );
		if ( retryCb ) {
			var $retry = $( '<button type="button" class="isp-btn isp-btn-primary"></button>' )
				.text( retryLabel || ( insightisticPro.i18n.retry || 'Retry' ) )
				.on( 'click', retryCb );
			$actions.append( $retry );
		}
		var settingsUrl = ( insightisticPro.settingsUrl || '#' ) + ( settingsHash ? '#' + settingsHash : '' );
		var $link = $( '<a class="isp-btn isp-btn-secondary"></a>' )
			.attr( 'href', settingsUrl )
			.text( insightisticPro.i18n.openSettings || 'Open Settings' );
		$actions.append( $link );
		$box.append( $actions );
		return $box;
	}

	/**
	 * Render a "Updated 4 min ago · Force refresh" pill into a toolbar.
	 * @param {jQuery}   $target  Element to receive the pill.
	 * @param {number}   cachedAt UNIX timestamp returned by PHP.
	 * @param {Function} onForce  Click handler for the "Force refresh" link.
	 */
	function renderCacheBadge( $target, cachedAt, onForce ) {
		if ( ! $target.length ) { return; }
		if ( ! cachedAt ) { $target.empty().hide(); return; }
		var rel = relativeTime( cachedAt );
		var label = ( insightisticPro.i18n.updated || 'Updated' ) + ' ' + rel;
		$target.empty()
			.append( $( '<span class="isp-cache-clock" aria-hidden="true"></span>' ) )
			.append( $( '<span class="isp-cache-when"></span>' ).text( label ) )
			.append( $( '<button type="button" class="isp-link-btn isp-cache-force"></button>' )
				.text( insightisticPro.i18n.forceRefresh || 'Force refresh' )
				.on( 'click', onForce ) )
			.show();
	}

	/* ================================================================== */
	/* DASHBOARD TABS                                                      */
	/* ================================================================== */

	function initDashTabs() {
		$( '.isp-dash-tab' ).on( 'click', function () {
			var tab = $( this ).data( 'tab' );
			$( '.isp-dash-tab' ).removeClass( 'isp-dash-tab-active' );
			$( this ).addClass( 'isp-dash-tab-active' );
			$( '.isp-tab-content' ).hide();
			$( '#isp-dash-' + tab ).show();

			// Lazy-load the panel on first switch so users don't have to
			// hunt for the Load Data button.
			if ( 'search-console' === tab && insightisticPro.gscConfigured && ! dataLoaded.gsc ) {
				loadGSC( false );
			} else if ( 'pagespeed' === tab && insightisticPro.psiConfigured && ! dataLoaded.psi ) {
				runPageSpeed( false );
			} else if ( 'commerce' === tab && insightisticPro.wooActive && ! dataLoaded.woo ) {
				loadWoo( false );
			} else if ( 'cloudflare' === tab && insightisticPro.cfAvailable && ! dataLoaded.cloudflare ) {
				loadCloudflare( false );
			}
		} );
	}

	/* ================================================================== */
	/* GA4: OVERVIEW TAB                                                   */
	/* ================================================================== */

	function loadData( auto, force ) {
		var $btn  = $( '#isp-load-data' );
		var days  = $( '#isp-date-range' ).val() || '28';

		spinnerBtn( $btn, insightisticPro.i18n.loading );

		// Always reset chart state so a previous render can't bleed through
		// on retries or forced refreshes.
		destroyCharts();
		if ( ! auto ) {
			clearMetricInsightSlots( '#isp-overview-cards' );
			$( '#isp-overview-cards, #isp-detail-cards, #isp-charts, #isp-content-cards, #isp-table-header, #isp-ai-insights' ).hide();
		}

		$( '#isp-data-container' ).html( skeletonHtml() );

		$.ajax( {
			url    : insightisticPro.ajaxUrl,
			method : 'POST',
			data   : {
				action: 'insightistic_get_data',
				days: days,
				force: force ? 1 : 0,
				nonce: insightisticPro.nonce
			},
			dataType: 'json',
			success: function ( res ) {
				if ( res && res.success ) {
					currentData      = res.data;
					dataLoaded.overview = true;
					renderDashboard( res.data );
					renderCacheBadge(
						$( '#isp-overview-cache' ),
						res.data.cached_at,
						function () { loadData( false, true ); }
					);
					renderTrafficGap();
				} else {
					dashboardError( responseMessage( res ) );
				}
			},
			error: function ( jqXHR, textStatus ) { dashboardError( diagnoseAjaxError( jqXHR, textStatus ) ); },
			complete: function () { resetBtn( $btn, '', insightisticPro.i18n.refreshData ); }
		} );
	}

	function renderDashboard( data ) {
		if ( data.overview ) {
			renderOverviewCards( data.overview );
			$( '#isp-overview-cards' ).show();
		}
		if ( data.countries || data.pages ) {
			renderCountries( data.countries || [] );
			renderPages( data.pages || [] );
			$( '#isp-detail-cards' ).show();
		}
		if ( data.channels || data.top_posts ) {
			renderChannels( data.channels || [] );
			renderTopPosts( data.top_posts || [] );
			$( '#isp-content-cards' ).show();
		}
		if ( data.chartData ) {
			renderCharts( data.chartData );
			$( '#isp-charts' ).show();
		}
		$( '#isp-data-container' ).html( data.html || '<p>' + insightisticPro.i18n.noData + '</p>' );
		$( '#isp-table-header' ).show();
		if ( insightisticPro.aiEnabled ) {
			$( '#isp-ai-analyze' ).show();
		}
	}

	function dashboardError( msg ) {
		// Wipe stale charts/cards so the user is not staring at a half
		// rendered shell with an error banner above it.
		destroyCharts();
		$( '#isp-overview-cards, #isp-detail-cards, #isp-charts, #isp-content-cards, #isp-table-header, #isp-ai-insights' ).hide();
		$( '#isp-overview-cache' ).empty().hide();
		var $box = errorBox(
			msg,
			insightisticPro.i18n.retry,
			function () { loadData( false, true ); },
			'ga4'
		);
		$( '#isp-data-container' ).empty().append( $box );
	}

	/* ---- Overview Cards ---- */
	function blankCard( $card, fallback ) {
		// Keep the card in the grid (no layout shift) but communicate that
		// the metric isn't available for the selected period / property.
		$card.removeClass( 'isp-stat-card-blank' ).addClass( 'isp-stat-card-blank' );
		$card.find( '.isp-stat-value' ).text( '—' );
		$card.find( '.isp-stat-change' ).html(
			'<span class="isp-change-flat">' + escHtml( fallback || insightisticPro.i18n.noMetric || 'No data' ) + '</span>'
		);
		$card.find( '#isp-newreturn-bar' ).empty();
	}

	function renderOverviewCards( ov ) {
		// Reset blank state on every render so a forced refresh shows the
		// new numbers.
		$( '.isp-stat-card' ).removeClass( 'isp-stat-card-blank' );

		// Sessions
		$( '#isp-val-sessions' ).text( fmt( ov.sessions.value ) );
		$( '#isp-chg-sessions' ).html( changeHtml( ov.sessions.change ) );
		// Users
		$( '#isp-val-users' ).text( fmt( ov.unique_users.value ) );
		$( '#isp-chg-users' ).html( changeHtml( ov.unique_users.change ) );
		// Pageviews
		if ( ov.pageviews ) {
			$( '#isp-val-pageviews' ).text( fmt( ov.pageviews.value ) );
			$( '#isp-chg-pageviews' ).html( changeHtml( ov.pageviews.change ) );
		} else {
			blankCard( $( '#isp-card-pageviews' ) );
		}
		// Avg duration
		if ( ov.avg_duration ) {
			$( '#isp-val-duration' ).text( ov.avg_duration.value );
			$( '#isp-chg-duration' ).html( changeHtml( ov.avg_duration.change ) );
		} else {
			blankCard( $( '#isp-card-duration' ) );
		}
		// Bounce rate
		if ( ov.bounce_rate ) {
			$( '#isp-val-bounce' ).text( ov.bounce_rate.value + '%' );
			// Lower is better so we invert the colour mapping.
			var bChg = ov.bounce_rate.change;
			var bHtml = bChg === 0 ? '<span class="isp-change-flat"></span>' :
				( bChg > 0
					? '<span class="isp-change-down">+' + bChg + '% ' + insightisticPro.i18n.vsLastPeriod + '</span>'
					: '<span class="isp-change-up">' + bChg + '% ' + insightisticPro.i18n.vsLastPeriod + '</span>' );
			$( '#isp-chg-bounce' ).html( bHtml );
		} else {
			blankCard( $( '#isp-card-bounce' ) );
		}
		// New vs Return
		if ( ov.new_vs_return ) {
			var nvr = ov.new_vs_return;
			$( '#isp-val-newreturn' ).text( nvr.new_pct + '% New' );
			$( '#isp-newreturn-bar' ).html(
				'<div class="isp-newreturn-bar">' +
				'<div class="isp-newreturn-new" style="width:' + nvr.new_pct + '%" title="New: ' + nvr.new_pct + '%"></div>' +
				'<div class="isp-newreturn-return" style="width:' + nvr.return_pct + '%" title="Returning: ' + nvr.return_pct + '%"></div>' +
				'</div>'
			);
		} else {
			blankCard( $( '#isp-card-newreturn' ) );
		}
		// Revenue
		if ( ov.revenue && ov.revenue.value > 0 ) {
			$( '#isp-val-revenue' ).text( fmtMoney( ov.revenue.value ) );
			$( '#isp-chg-revenue' ).html( changeHtml( ov.revenue.change ) );
		} else {
			blankCard( $( '#isp-card-revenue' ), insightisticPro.i18n.noRevenue || 'No revenue data' );
		}
		// Transactions
		if ( ov.transactions && ov.transactions.value > 0 ) {
			$( '#isp-val-tx' ).text( fmt( ov.transactions.value ) );
			$( '#isp-chg-tx' ).html( changeHtml( ov.transactions.change ) );
		} else {
			blankCard( $( '#isp-card-tx' ), insightisticPro.i18n.noTransactions || 'No transactions' );
		}
	}

	/* ---- Countries ---- */
	function renderCountries( countries ) {
		var $list = $( '#isp-countries-list' ).empty();
		if ( ! countries.length ) {
			$list.html( '<li class="isp-rank-loading">' + insightisticPro.i18n.noData + '</li>' );
			return;
		}
		$.each( countries, function ( i, c ) {
			$list.append(
				'<li>' +
				'<span class="isp-rank-num">' + ( i + 1 ) + '</span>' +
				'<span class="isp-rank-name" title="' + escHtml( c.country ) + '">' + escHtml( c.country ) + '</span>' +
				'<div class="isp-rank-bar-wrap"><div class="isp-rank-bar"><div class="isp-rank-bar-fill" style="width:' + c.share + '%"></div></div></div>' +
				'<span class="isp-rank-share">' + c.share + '%</span>' +
				'<span class="isp-rank-change ' + changeClass( c.change ) + '">' + changeArrow( c.change ) + '</span>' +
				'</li>'
			);
		} );
	}

	/* ---- Top Pages ---- */
	function renderPages( pages ) {
		var $list = $( '#isp-pages-list' ).empty();
		if ( ! pages.length ) {
			$list.html( '<li class="isp-rank-loading">' + insightisticPro.i18n.noData + '</li>' );
			return;
		}
		$.each( pages, function ( i, p ) {
			var label = p.title && p.title !== '(not set)' ? p.title : p.path;
			$list.append(
				'<li>' +
				'<span class="isp-rank-num">' + ( i + 1 ) + '</span>' +
				'<span class="isp-rank-name" title="' + escHtml( label ) + '">' + escHtml( truncate( label, 34 ) ) + '</span>' +
				'<div class="isp-rank-bar-wrap"><div class="isp-rank-bar"><div class="isp-rank-bar-fill" style="width:' + p.share + '%"></div></div></div>' +
				'<span class="isp-rank-share">' + p.share + '%</span>' +
				'<span class="isp-rank-change ' + changeClass( p.change ) + '">' + changeArrow( p.change ) + '</span>' +
				'</li>'
			);
		} );
	}

	/* ---- Traffic Channels ---- */
	function renderChannels( channels ) {
		var $wrap = $( '#isp-channels-table' ).empty();
		if ( ! channels.length ) {
			$wrap.html( '<p style="padding:16px;color:#9ca3af;font-size:13px;">' + insightisticPro.i18n.noData + '</p>' );
			return;
		}
		var html = '';
		$.each( channels, function ( i, ch ) {
			html += '<div class="isp-channel-row">' +
				'<span class="isp-channel-icon">' + channelIcon( ch.channel ) + '</span>' +
				'<span class="isp-channel-name">' + escHtml( ch.channel ) + '</span>' +
				'<div class="isp-channel-bar-wrap"><div class="isp-channel-bar"><div class="isp-channel-bar-fill" style="width:' + ch.share + '%"></div></div></div>' +
				'<span class="isp-channel-pct">' + ch.share + '%</span>' +
				'<span class="isp-channel-sessions">' + fmt( ch.sessions ) + '</span>' +
				'<span class="isp-channel-change ' + changeClass( ch.change ) + '">' + changeArrow( ch.change ) + '</span>' +
				'</div>';
		} );
		$wrap.html( html );
	}

	/* ---- Top Posts ---- */
	function renderTopPosts( posts ) {
		var $list = $( '#isp-posts-list' ).empty();
		if ( ! posts.length ) {
			$list.html( '<li class="isp-rank-loading">' + insightisticPro.i18n.noData + '</li>' );
			return;
		}
		$.each( posts, function ( i, p ) {
			var label = p.title && p.title !== '(not set)' ? p.title : p.path;
			$list.append(
				'<li>' +
				'<span class="isp-rank-num">' + ( i + 1 ) + '</span>' +
				'<span class="isp-rank-name" title="' + escHtml( label ) + '">' + escHtml( truncate( label, 36 ) ) + '</span>' +
				'<span class="isp-rank-share">' + fmt( p.views ) + ' views</span>' +
				'</li>'
			);
		} );
	}

	/* ---- Charts ---- */
	function renderCharts( data ) {
		var tlCtx = document.getElementById( 'isp-chart-timeline' );
		if ( tlCtx ) {
			if ( timelineChart ) { timelineChart.destroy(); }
			var hasRevenue = data.revenue && data.revenue.some( function ( v ) { return v > 0; } );
			var datasets   = [ {
				label: insightisticPro.i18n.sessions, data: data.sessions,
				borderColor: '#3b82f6', backgroundColor: 'rgba(59,130,246,0.08)',
				borderWidth: 2, fill: true, tension: 0.4,
				pointRadius: data.labels.length > 60 ? 0 : 3, pointHoverRadius: 5, yAxisID: 'y'
			} ];
			if ( hasRevenue ) {
				datasets.push( {
					label: insightisticPro.i18n.revenue, data: data.revenue,
					borderColor: '#10b981', backgroundColor: 'rgba(16,185,129,0.08)',
					borderWidth: 2, fill: true, tension: 0.4,
					pointRadius: data.labels.length > 60 ? 0 : 3, pointHoverRadius: 5, yAxisID: 'y1'
				} );
			}
			var scales = {
				x: { grid: { color: 'rgba(0,0,0,0.04)' }, ticks: { font: { size: 11 }, maxTicksLimit: 12 } },
				y: { position: 'left', grid: { color: 'rgba(0,0,0,0.04)' }, ticks: { font: { size: 11 }, callback: function ( v ) { return fmt( v ); } } }
			};
			if ( hasRevenue ) {
				scales.y1 = { position: 'right', grid: { drawOnChartArea: false }, ticks: { font: { size: 11 }, callback: function ( v ) { return '$' + fmt( v ); } } };
			}
			timelineChart = new Chart( tlCtx, {
				type: 'line', data: { labels: data.labels, datasets: datasets },
				options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
					plugins: { legend: { display: hasRevenue, position: 'top', labels: { font: { size: 12 }, usePointStyle: true } }, tooltip: { mode: 'index', intersect: false } },
					scales: scales }
			} );
		}
		renderSourcesDonut();
	}

	function renderSourcesDonut() {
		if ( ! currentData || ! currentData.structured_data ) return;
		var sdCtx = document.getElementById( 'isp-chart-sources' );
		if ( ! sdCtx ) return;
		if ( sourcesChart ) { sourcesChart.destroy(); }
		var channels = currentData.structured_data.channels || [];
		if ( ! channels.length ) return;
		var sorted = channels.slice().sort( function ( a, b ) { return b.visitors - a.visitors; } );
		var top    = sorted.slice( 0, 8 );
		var other  = sorted.slice( 8 ).reduce( function ( acc, c ) { return acc + c.visitors; }, 0 );
		var labels = top.map( function ( c ) { return c.source + ' / ' + c.medium; } );
		var values = top.map( function ( c ) { return c.visitors; } );
		if ( other > 0 ) { labels.push( 'Other' ); values.push( other ); }
		var palette = [ '#3b82f6','#10b981','#f59e0b','#ef4444','#8b5cf6','#06b6d4','#f97316','#84cc16','#6b7280' ];
		sourcesChart = new Chart( sdCtx, {
			type: 'doughnut',
			data: { labels: labels, datasets: [ { data: values, backgroundColor: palette, borderWidth: 2, borderColor: '#fff', hoverBorderWidth: 3 } ] },
			options: { responsive: true, maintainAspectRatio: false, cutout: '62%',
				plugins: { legend: { position: 'bottom', labels: { font: { size: 11 }, usePointStyle: true, padding: 10, boxWidth: 8 } },
					tooltip: { callbacks: { label: function ( ctx ) { var total = ctx.dataset.data.reduce( function ( a, b ) { return a + b; }, 0 ); var pct = total > 0 ? ( ( ctx.parsed / total ) * 100 ).toFixed( 1 ) : 0; return ' ' + fmt( ctx.parsed ) + ' (' + pct + '%)'; } } } } }
		} );
	}

	function destroyCharts() {
		if ( timelineChart ) { timelineChart.destroy(); timelineChart = null; }
		if ( sourcesChart )  { sourcesChart.destroy();  sourcesChart  = null; }
	}

	/* ================================================================== */
	/* AI ANALYSIS                                                         */
	/* ================================================================== */

	function aiHeaderHtml() {
		var prov = escHtml( insightisticPro.aiProviderLabel || 'AI' );
		var cost = escHtml( insightisticPro.aiCostHint || '' );
		return '<div class="isp-ai-panel"><div class="isp-ai-header">' +
			'<div class="isp-ai-title"><span class="isp-ai-icon"></span>' + escHtml( insightisticPro.i18n.analyzing ) +
			' <span class="isp-ai-badge">' + prov + '</span></div>' +
			( cost ? '<div class="isp-ai-cost-hint" title="' + escHtml( insightisticPro.i18n.aiCostTitle || 'Estimated per-run cost' ) + '">' + cost + '</div>' : '' ) +
			'</div></div>';
	}

	function aiFooterToolbar() {
		return '<div class="isp-ai-toolbar">' +
			'<button type="button" class="isp-link-btn" id="isp-ai-download" data-ai-action="download">' + escHtml( insightisticPro.i18n.aiDownload || 'Download Reports' ) + '</button>' +
			'<button type="button" class="isp-link-btn" id="isp-ai-rerun" data-ai-action="rerun">' + escHtml( insightisticPro.i18n.aiRerun || 'Re-run' ) + '</button>' +
			'<button type="button" class="isp-link-btn" id="isp-ai-save" data-ai-action="save">' + escHtml( insightisticPro.i18n.aiSave || 'Save snapshot' ) + '</button>' +
			'<span class="isp-ai-toolbar-status" id="isp-ai-toolbar-status" aria-live="polite"></span>' +
			'</div>';
	}

	/**
	 * Open a print-ready report containing the currently loaded dashboard data
	 * and the rendered AI panel. Browsers can save this print view directly as
	 * a PDF without adding a large third-party PDF library to the plugin.
	 *
	 * @param string $context AI context slug.
	 * @param object $panel   Current AI panel.
	 */
	function downloadAIReport( context, $panel ) {
		var selectors = {
			gsc: [ '#isp-gsc-cards', '#isp-gsc-tables', '#isp-gsc-devices' ],
			pagespeed: [ '#isp-psi-results' ],
			overview: [ '#isp-overview-cards', '#isp-charts', '#isp-detail-cards', '#isp-content-cards', '#isp-data-container' ],
			woo: [ '#isp-woo-cards', '#isp-woo-charts', '#isp-woo-prod-cards', '#isp-woo-cust-cards', '#isp-woo-geo-cards', '#isp-woo-ops-cards' ],
			cloudflare: [ '#isp-cf-cards', '#isp-cf-charts', '#isp-cf-detail-cards', '#isp-cf-security' ]
		}[ context ] || [];
		var sections = [];
		$.each( selectors, function ( _, selector ) {
			var el = document.querySelector( selector );
			if ( el && $( el ).is( ':visible' ) ) {
				sections.push( el.outerHTML );
			}
		} );

		var aiPanel = $panel.find( '.isp-ai-panel' ).first().prop( 'outerHTML' ) || '';
		if ( ! sections.length && ! aiPanel ) {
			return;
		}

		var labels = {
			overview: 'Analytics Overview',
			gsc: 'Google Search Console Report',
			pagespeed: 'PageSpeed Report',
			woo: 'WooCommerce Report',
			cloudflare: 'Traffic Insights Report'
		};
		var title = labels[ context ] || 'Insightistic Report';
		// Open synchronously while the click is still trusted. Passing noopener
		// here makes Chromium return null even though it opens an empty tab,
		// which was the cause of the reported about:blank report screen.
		var reportWindow = window.open( '', '_blank' );
		if ( ! reportWindow ) {
			$panel.find( '#isp-ai-toolbar-status' ).text( insightisticPro.i18n.aiDownloadPopup || 'Please allow pop-ups to open the printable report.' );
			return;
		}
		reportWindow.document.write( '<!doctype html><html><head><meta charset="utf-8"><title>Insightistic</title></head><body style="font-family:system-ui,sans-serif;padding:32px;color:#334155"><p>Preparing your Insightistic report…</p></body></html>' );
		reportWindow.document.close();

		var cssUrl = escHtml( insightisticPro.cssUrl || '' );
		var body = '<main class="isp-report-document">' +
			'<div class="isp-report-cover"><div class="isp-report-brand">Insightistic</div>' +
			'<h1>' + escHtml( title ) + '</h1>' +
			'<p>Generated ' + escHtml( new Date().toLocaleString() ) + '</p></div>' +
			( aiPanel ? '<section class="isp-report-ai"><h2>Insightistic Cloud AI</h2>' + aiPanel + '</section>' : '' ) +
			'<section class="isp-report-data"><h2>Complete Report Data</h2>' + sections.join( '' ) + '</section>' +
			'</main>';
		var reportHtml = '<!doctype html><html><head><meta charset="utf-8"><title>' + escHtml( title ) + '</title>' +
			( cssUrl ? '<link rel="stylesheet" href="' + cssUrl + '">' : '' ) +
			'<style>@page{size:auto;margin:14mm}body{background:#fff!important;color:#111827!important;padding:24px!important}.isp-report-document{max-width:1100px;margin:0 auto}.isp-report-cover{padding:28px 32px;margin-bottom:20px;border-radius:18px;background:linear-gradient(135deg,#0f172a,#155e75);color:#fff}.isp-report-brand{font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;opacity:.8}.isp-report-cover h1{margin:8px 0;font-size:28px;color:#fff}.isp-report-cover p{margin:0;color:rgba(255,255,255,.75)}.isp-report-ai h2,.isp-report-data h2{margin:20px 0 10px;color:#0f172a;font-size:18px}.isp-report-data>*{break-inside:avoid}.isp-report-data .isp-skeleton-wrap,.isp-report-data .isp-ai-toolbar,.isp-report-data button{display:none!important}.isp-report-ai .isp-ai-panel{break-inside:avoid}.isp-report-ai .isp-ai-footer{display:block}.isp-report-data .isp-tab-content,.isp-report-data [style*="display:none"]{display:block!important}.isp-report-data .isp-ai-container{display:none!important}.isp-report-data .isp-chart-card{break-inside:avoid}.isp-report-data canvas{max-width:100%}@media print{body{padding:0!important}.isp-report-cover{print-color-adjust:exact;-webkit-print-color-adjust:exact}.isp-report-ai .isp-ai-panel{print-color-adjust:exact;-webkit-print-color-adjust:exact}}</style></head><body>' + body + '</body></html>';
		var finishReport = function () {
			reportWindow.document.open();
			reportWindow.document.write( reportHtml );
			reportWindow.document.close();
			reportWindow.focus();
			window.setTimeout( function () { reportWindow.print(); }, 400 );
		};

		$.ajax( {
			url: insightisticPro.ajaxUrl,
			method: 'POST',
			data: { action: 'insightistic_charge_ai_report', nonce: insightisticPro.nonce },
			success: function ( res ) {
				if ( res.success ) {
					finishReport();
					return;
				}
				reportWindow.close();
				$panel.find( '#isp-ai-toolbar-status' ).text( responseMessage( res ) || 'Report credits are unavailable.' );
			},
			error: function ( jqXHR, textStatus ) {
				reportWindow.close();
				$panel.find( '#isp-ai-toolbar-status' ).text( diagnoseAjaxError( jqXHR, textStatus ) );
			}
		} );
	}

	function clearMetricInsightSlots( scope ) {
		$( ( scope || '' ) + ' .isp-card-ai-slot' ).empty().hide();
	}

	function renderMetricInsightSlots( $panel, scope ) {
		var groups = {};
		clearMetricInsightSlots( scope );
		$panel.find( '[data-ai-metric]' ).each( function () {
			var metric = $( this ).data( 'ai-metric' ) || 'general';
			groups[ metric ] = groups[ metric ] || [];
			groups[ metric ].push( this );
		} );
		$( scope + ' .isp-card-ai-slot' ).each( function () {
			var metric = $( this ).data( 'metric' );
			var items = groups[ metric ] || groups.general || [];
			if ( ! items.length ) return;
			var $box = $( '<div class="isp-card-ai-box"><span class="isp-card-ai-label">AI improvement</span></div>' );
			$.each( items.slice( 0, 2 ), function ( i, item ) {
				var $item = $( item ).clone().removeAttr( 'data-ai-metric' ).addClass( 'isp-ai-inline-item' );
				$box.append( $item );
			} );
			$( this ).append( $box ).show();
		} );
	}

	function runAIContext( context ) {
		var config = {
			overview:  { data: currentData && ( currentData.structured_data || currentData ), button: '#isp-ai-analyze', panel: '#isp-ai-insights', days: '#isp-date-range', scope: '#isp-overview-cards' },
			gsc:       { data: currentGSCData, button: '#isp-gsc-ai-analyze', panel: '#isp-gsc-ai-insights', days: '#isp-gsc-date-range', scope: '#isp-gsc-cards' },
			pagespeed: { data: currentPSIData, button: '#isp-psi-ai-analyze', panel: '#isp-psi-ai-insights', days: null, scope: '#isp-psi-results' }
		}[ context ];
		if ( ! config || ! config.data ) return;
		var $btn   = $( config.button );
		var $panel = $( config.panel );
		var orig   = $btn.html();
		$btn.prop( 'disabled', true ).html( '<span class="isp-inline-spinner"></span> ' + escHtml( insightisticPro.i18n.analyzing ) );
		$panel.attr( 'data-ai-context', context );
		$panel.html( aiHeaderHtml() ).show();
		$.ajax( {
			url: insightisticPro.ajaxUrl, method: 'POST',
			data: { action: 'insightistic_ai_analyze', data: JSON.stringify( config.data ), days: config.days ? ( $( config.days ).val() || '28' ) : '28', nonce: insightisticPro.nonce },
			success: function ( res ) {
				if ( res.success ) {
					lastAIHtml = res.data.html;
					lastAIHtmlByContext[ context ] = res.data.html;
					$panel.html( res.data.html + aiFooterToolbar() );
					animateGauges( $panel );
					renderMetricInsightSlots( $panel, config.scope );
				} else if ( res.data && res.data.html ) {
					// Feature gates return a rendered lock card rather than a text
					// error. Preserve that UI instead of stringifying the payload.
					$panel.html( res.data.html );
				} else {
					renderAIError( $panel, responseMessage( res ) );
				}
			},
			error: function ( jqXHR, textStatus ) { renderAIError( $panel, diagnoseAjaxError( jqXHR, textStatus ) ); },
			complete: function () { $btn.prop( 'disabled', false ).html( orig ); }
		} );
	}

	function runAI() { runAIContext( 'overview' ); }
	function runGSCAI() { runAIContext( 'gsc' ); }
	function runPageSpeedAI() { runAIContext( 'pagespeed' ); }

	function renderAIError( $panel, msg ) {
		var errBox = '<div class="isp-ai-error"><div class="isp-notice isp-notice-error">' + escHtml( msg ) + '</div>' +
			'<button type="button" class="isp-btn isp-btn-secondary" id="isp-ai-retry">' + escHtml( insightisticPro.i18n.retry || 'Retry' ) + '</button></div>';
		var context = $panel.attr( 'data-ai-context' ) || $panel.data( 'ai-context' ) || 'overview';
		var previous = lastAIHtmlByContext[ context ] || ( 'overview' === context ? lastAIHtml : '' );
		if ( previous ) {
			// Preserve the previous successful insight  do not blank the
			// panel on failure.
			$panel.html( previous + errBox + aiFooterToolbar() );
			animateGauges( $panel );
		} else {
			$panel.html( aiHeaderHtml().replace( insightisticPro.i18n.analyzing, '' ) + errBox );
		}
	}

	function initAIToolbar() {
		// Panels are rendered after dashboard AJAX calls. One namespaced
		// delegation point keeps all toolbar actions live and avoids duplicate
		// handlers if a tab is initialised more than once.
		var buttonSelector = '.isp-ai-container .isp-ai-toolbar [data-ai-action], .isp-ai-container #isp-ai-retry';
		$( document ).off( 'click.insightisticAiToolbar', buttonSelector );
		$( document ).on( 'click.insightisticAiToolbar', buttonSelector, function ( event ) {
			event.preventDefault();
			var $button = $( this );
			var $panel = $button.closest( '.isp-ai-container' );
			var context = $panel.attr( 'data-ai-context' ) || $panel.data( 'ai-context' ) || ( $panel.is( '#isp-gsc-ai-insights' ) ? 'gsc' : ( $panel.is( '#isp-psi-ai-insights' ) ? 'pagespeed' : ( $panel.is( '#isp-woo-ai-insights' ) ? 'woo' : 'overview' ) ) );
			var action = $button.data( 'ai-action' ) || ( $button.is( '#isp-ai-retry' ) ? 'rerun' : '' );

			if ( 'download' === action ) {
				downloadAIReport( context, $panel );
				return;
			}
			if ( 'rerun' === action ) {
				if ( $panel.is( '#isp-woo-ai-insights' ) || 'woo' === context ) { runWooAI(); } else { runAIContext( context ); }
				return;
			}
			if ( 'save' === action ) {
				var inCommerce = $panel.is( '#isp-woo-ai-insights' ) || 'woo' === context;
				var payload = inCommerce ? lastWooAIHtml : ( lastAIHtmlByContext[ context ] || lastAIHtml );
				if ( ! payload ) {
					$panel.find( '#isp-ai-toolbar-status' ).text( 'No insight content to save.' );
					return;
				}
				$.ajax( {
					url: insightisticPro.ajaxUrl, method: 'POST',
					data: { action: 'insightistic_save_ai_snapshot', html: payload, nonce: insightisticPro.nonce },
					success: function ( res ) {
						$panel.find( '#isp-ai-toolbar-status' ).text( res.success ? ( insightisticPro.i18n.aiSaved || 'Saved' ) : ( responseMessage( res ) || insightisticPro.i18n.error ) );
						setTimeout( function () { $panel.find( '#isp-ai-toolbar-status' ).text( '' ); }, 2000 );
					},
					error: function ( jqXHR, textStatus ) { $panel.find( '#isp-ai-toolbar-status' ).text( diagnoseAjaxError( jqXHR, textStatus ) ); }
				} );
			}
		} );
	}

	/* ================================================================== */
	/* SEARCH CONSOLE TAB                                                  */
	/* ================================================================== */

	function loadGSC( force ) {
		var $btn  = $( '#isp-load-gsc' );
		var days  = $( '#isp-gsc-date-range' ).val() || '28';

		spinnerBtn( $btn, insightisticPro.i18n.loading );
		$( '#isp-gsc-cards,#isp-gsc-tables,#isp-gsc-devices,#isp-gsc-ai-insights' ).hide();
		clearMetricInsightSlots( '#isp-gsc-cards' );
		$( '#isp-gsc-loading' ).html( skeletonHtml() ).show();

		$.ajax( {
			url: insightisticPro.ajaxUrl, method: 'POST',
			data: {
				action: 'insightistic_get_gsc_data',
				days: days,
				force: force ? 1 : 0,
				nonce: insightisticPro.nonce
			},
			dataType: 'json',
			success: function ( res ) {
				if ( res && res.success ) {
					currentGSCData = res.data;
					dataLoaded.gsc = true;
					renderGSC( res.data );
					renderCacheBadge(
						$( '#isp-gsc-cache' ),
						res.data.cached_at,
						function () { loadGSC( true ); }
					);
				} else {
					$( '#isp-gsc-loading' ).empty().append(
						errorBox( responseMessage( res ), null, function () { loadGSC( true ); }, 'gsc' )
					);
				}
			},
			error: function ( jqXHR, textStatus ) {
				$( '#isp-gsc-loading' ).empty().append(
					errorBox( diagnoseAjaxError( jqXHR, textStatus ), null, function () { loadGSC( true ); }, 'gsc' )
				);
			},
			complete: function () { resetBtn( $btn, '', insightisticPro.i18n.loadData ); }
		} );
	}

	function renderGSC( data ) {
		currentGSCData = data;
		$( '#isp-gsc-loading' ).hide();

		// Stat cards.
		if ( data.overview ) {
			var ov = data.overview;
			$( '#isp-gsc-clicks' ).text( fmt( ov.clicks.value ) );
			$( '#isp-gsc-clicks-chg' ).html( changeHtml( ov.clicks.change ) );
			$( '#isp-gsc-impr' ).text( fmt( ov.impressions.value ) );
			$( '#isp-gsc-impr-chg' ).html( changeHtml( ov.impressions.change ) );
			$( '#isp-gsc-ctr' ).text( ov.ctr.value + '%' );
			$( '#isp-gsc-ctr-chg' ).html( changeHtml( ov.ctr.change ) );
			$( '#isp-gsc-pos' ).text( '#' + ov.position.value );
			// Position change: improvement is down (lower number).
			var posChg = ov.position.change;
			var posHtml = posChg === 0 ? '<span class="isp-change-flat"></span>' :
				( posChg > 0
					? '<span class="isp-change-up">+' + posChg + '% ' + insightisticPro.i18n.vsLastPeriod + '</span>'
					: '<span class="isp-change-down">' + posChg + '% ' + insightisticPro.i18n.vsLastPeriod + '</span>' );
			$( '#isp-gsc-pos-chg' ).html( posHtml );
			$( '#isp-gsc-cards' ).show();
		}

		// Queries table.
		if ( data.top_queries && data.top_queries.length ) {
			$( '#isp-gsc-queries' ).html( buildGSCTable( data.top_queries, 'query' ) );
		}
		// Pages table.
		if ( data.top_pages && data.top_pages.length ) {
			$( '#isp-gsc-pages' ).html( buildGSCTable( data.top_pages, 'page' ) );
		}
		$( '#isp-gsc-tables' ).show();
		if ( insightisticPro.aiEnabled ) {
			$( '#isp-gsc-ai-analyze' ).show();
		}

		// Device bars.
		if ( data.devices && data.devices.length ) {
			renderDeviceBars( data.devices );
			$( '#isp-gsc-devices' ).show();
		}
	}

	function buildGSCTable( rows, type ) {
		var colLabel = type === 'query' ? 'Query' : 'Page';
		var html = '<table class="isp-gsc-table"><thead><tr>' +
			'<th>' + colLabel + '</th>' +
			'<th>Clicks</th><th>Impr.</th><th>CTR</th><th>Position</th>' +
			'</tr></thead><tbody>';
		$.each( rows, function ( i, row ) {
			var label   = type === 'query' ? row.query : row.page.replace( /^https?:\/\/[^\/]+/, '' );
			var posCls  = row.position <= 3 ? 'isp-pos-top3' : ( row.position <= 10 ? 'isp-pos-top10' : 'isp-pos-low' );
			html += '<tr>' +
				'<td title="' + escHtml( type === 'query' ? row.query : row.page ) + '">' + escHtml( truncate( label, 50 ) ) + '</td>' +
				'<td>' + fmt( row.clicks ) + '</td>' +
				'<td>' + fmt( row.impressions ) + '</td>' +
				'<td>' + row.ctr + '%</td>' +
				'<td><span class="isp-gsc-pos-badge ' + posCls + '">#' + row.position + '</span></td>' +
				'</tr>';
		} );
		html += '</tbody></table>';
		return html;
	}

	function renderDeviceBars( devices ) {
		var icons = { 'MOBILE': '', 'DESKTOP': '', 'TABLET': '' };
		var fillCls = { 'MOBILE': 'isp-device-fill-mobile', 'DESKTOP': 'isp-device-fill-desktop', 'TABLET': 'isp-device-fill-tablet' };
		var html = '';
		$.each( devices, function ( i, d ) {
			var key = ( d.device || '' ).toUpperCase();
			html += '<div class="isp-device-bar-row">' +
				'<span class="isp-device-icon">' + ( icons[ key ] || '' ) + '</span>' +
				'<span class="isp-device-name">' + escHtml( d.device.charAt(0).toUpperCase() + d.device.slice(1).toLowerCase() ) + '</span>' +
				'<div class="isp-device-track"><div class="isp-device-fill ' + ( fillCls[ key ] || '' ) + '" style="width:' + d.share + '%"></div></div>' +
				'<span class="isp-device-pct">' + d.share + '%</span>' +
				'<span class="isp-device-clicks">' + fmt( d.clicks ) + ' clicks</span>' +
				'</div>';
		} );
		$( '#isp-gsc-device-bars' ).html( html );
	}

	/* ================================================================== */
	/* PAGESPEED TAB                                                       */
	/* ================================================================== */

	function runPageSpeed( force ) {
		var $btn  = $( '#isp-run-pagespeed' );
		var url   = $( '#isp-psi-url' ).val() || insightisticPro.defaultUrl;

		spinnerBtn( $btn, insightisticPro.i18n.testing );
		$( '#isp-psi-results,#isp-psi-ai-insights' ).hide();
		$( '#isp-psi-loading' ).html( skeletonHtml() ).show();

		$.ajax( {
			url: insightisticPro.ajaxUrl, method: 'POST',
			data: {
				action: 'insightistic_get_pagespeed',
				page_url: url,
				force: force ? 1 : 0,
				nonce: insightisticPro.nonce
			},
			dataType: 'json',
			success: function ( res ) {
				if ( res && res.success ) {
					currentPSIData = res.data;
					dataLoaded.psi = true;
					renderPageSpeed( res.data );
					renderCacheBadge(
						$( '#isp-psi-cache' ),
						res.data.cached_at,
						function () { runPageSpeed( true ); }
					);
				} else {
					$( '#isp-psi-loading' ).empty().append(
						errorBox( responseMessage( res ), null, function () { runPageSpeed( true ); }, 'pagespeed' )
					);
				}
			},
			error: function ( jqXHR, textStatus ) {
				$( '#isp-psi-loading' ).empty().append(
					errorBox( diagnoseAjaxError( jqXHR, textStatus ), null, function () { runPageSpeed( true ); }, 'pagespeed' )
				);
			},
			complete: function () { resetBtn( $btn, '', insightisticPro.i18n.runTest ); }
		} );
	}

	function renderPageSpeed( data ) {
		currentPSIData = data;
		$( '#isp-psi-loading' ).hide();
		renderPsiSummary( data );
		renderPsiDetails( data );

		// Render both rings.
		renderScoreRing( 'mobile',  data.mobile  );
		renderScoreRing( 'desktop', data.desktop );

		// CWV grids.
		if ( data.mobile )  { $( '#isp-cwv-mobile' ).html( buildCWVGrid( data.mobile.cwv ) ); }
		if ( data.desktop ) { $( '#isp-cwv-desktop' ).html( buildCWVGrid( data.desktop.cwv ) ); }

		$( '#isp-psi-results' ).show();
		if ( insightisticPro.aiEnabled ) {
			$( '#isp-psi-ai-analyze' ).show();
		}
	}

	function renderPsiSummary( data ) {
		var source = data.mobile || data.desktop || {};
		var scores = source.scores || {};
		var values = {
			'performance': scores.performance,
			'accessibility': scores.accessibility,
			'best-practices': scores.best_practices,
			'seo': scores.seo,
			'ai-readiness': source.ai_readiness ? source.ai_readiness.score : null
		};
		$( '#isp-psi-results .isp-psi-summary-card' ).each( function () {
			var key = $( this ).data( 'summary-key' );
			var value = values[ key ];
			$( this ).find( '.isp-psi-summary-value' ).text( value === null || typeof value === 'undefined' ? '—' : value );
			$( this ).toggleClass( 'is-score-good', Number( value ) >= 90 ).toggleClass( 'is-score-moderate', Number( value ) >= 50 && Number( value ) < 90 ).toggleClass( 'is-score-poor', Number( value ) >= 0 && Number( value ) < 50 );
		} );
		var meta = [];
		if ( data.url ) meta.push( escHtml( data.url ) );
		if ( data.cached_at ) meta.push( escHtml( new Date( data.cached_at * 1000 ).toLocaleString() ) );
		$( '#isp-psi-report-meta' ).html( meta.join( ' <span aria-hidden="true">•</span> ' ) );
	}

	function renderPsiDetails( data ) {
		var source = data.mobile || data.desktop || {};
		var opportunities = source.opportunities || [];
		var diagnostics = source.diagnostics || [];
		var list = function ( items, type ) {
			if ( ! items.length ) return '<p class="isp-no-data">' + escHtml( insightisticPro.i18n.noData ) + '</p>';
			var html = '<ul class="isp-psi-detail-list">';
			$.each( items.slice( 0, 6 ), function ( i, item ) {
				html += '<li><strong>' + escHtml( item.title || '' ) + '</strong>' +
					( type === 'opportunity' && item.savings_ms ? '<span>' + escHtml( item.savings_ms + ' ms potential saving' ) + '</span>' : '' ) +
					( type === 'diagnostic' && item.display ? '<span>' + escHtml( item.display ) + '</span>' : '' ) + '</li>';
			} );
			return html + '</ul>';
		};
		$( '#isp-psi-opportunities' ).html( list( opportunities, 'opportunity' ) );
		$( '#isp-psi-diagnostics' ).html( list( diagnostics, 'diagnostic' ) );
	}

	function renderScoreRing( device, data ) {
		if ( ! data ) return;

		var score     = data.score || 0;
		var cls       = score >= 90 ? 'good' : ( score >= 50 ? 'moderate' : 'poor' );
		var label     = score >= 90 ? 'Good' : ( score >= 50 ? 'Needs Improvement' : 'Poor' );

		// Score text (color still reflects the band for instant readability).
		$( '#isp-' + device + '-score' )
			.text( score )
			.closest( '.isp-ring-container' )
			.attr( 'class', 'isp-ring-container isp-score-' + cls );

		// Ring stroke = red→orange→green gradient (filled portion = score).
		var circumference = 2 * Math.PI * 54; // 339.3
		var offset        = circumference - ( score / 100 ) * circumference;
		var $ring         = $( '#isp-' + device + '-ring' );
		$ring.attr( 'stroke', 'url(#ispPsiGrad)' );
		$ring.css( 'stroke-dashoffset', circumference );
		setTimeout( function () {
			$ring.css( 'stroke-dashoffset', offset );
		}, 80 );

		// Label below ring.
		$( '#isp-' + device + '-label' )
			.text( label )
			.attr( 'class', 'isp-speed-score-label isp-lbl-' + cls );
	}

	var cwvLabels = { lcp: 'LCP', inp: 'INP / TBT', cls: 'CLS', fcp: 'FCP', tbt: 'TBT', si: 'Speed Index', ttfb: 'TTFB' };

	function buildCWVGrid( cwv ) {
		if ( ! cwv ) return '<p style="color:#9ca3af;padding:16px;">' + escHtml( insightisticPro.i18n.noData ) + '</p>';
		var keys = [ 'lcp', 'inp', 'cls', 'fcp', 'tbt', 'si', 'ttfb' ];
		var html = '';
		$.each( keys, function ( i, key ) {
			if ( ! cwv[ key ] ) return;
			var m      = cwv[ key ];
			var status = m.status || 'unknown';
			var label  = cwvLabels[ key ] || key.toUpperCase();
			html += '<div class="isp-cwv-item isp-cwv-item-' + escHtml( status ) + '">' +
				'<div class="isp-cwv-metric-name">' + escHtml( label ) + '</div>' +
				'<div class="isp-cwv-metric-val">' + escHtml( m.display ) + '</div>' +
				'<div class="isp-cwv-metric-label">' + escHtml( m.label || '' ) + '</div>' +
				'<span class="isp-cwv-badge isp-cwv-badge-' + escHtml( status ) + '">' + escHtml( status.charAt(0).toUpperCase() + status.slice(1) ) + '</span>' +
				'</div>';
		} );
		return html;
	}

	/* ================================================================== */
	/* COMMERCE (WooCommerce Intelligence)                                  */
	/* ================================================================== */

	function fmtMoneyWoo( amount, symbol ) {
		var sym = symbol || '$';
		return sym + Number( amount || 0 ).toLocaleString( undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 } );
	}

	function changeHtmlInverted( val ) {
		// Lower is better (refund rate). Reuse changeHtml class semantics with inverted colours.
		if ( isNaN( val ) || 0 === val ) { return '<span class="isp-change-flat"></span>'; }
		var sign = val > 0 ? '+' : '';
		var cls  = val > 0 ? 'isp-change-down' : 'isp-change-up';
		return '<span class="' + cls + '">' + sign + val + '% ' + insightisticPro.i18n.vsLastPeriod + '</span>';
	}

	function loadWoo( force ) {
		var $btn = $( '#isp-load-woo' );
		var days = $( '#isp-woo-date-range' ).val() || '28';

		spinnerBtn( $btn, insightisticPro.i18n.loading );
		destroyWooCharts();
		$( '#isp-woo-cards, #isp-woo-charts, #isp-woo-prod-cards, #isp-woo-cust-cards, #isp-woo-geo-cards, #isp-woo-ops-cards, #isp-woo-ai-insights' ).hide();
		$( '#isp-woo-loading' ).html( skeletonHtml() ).show();

		$.ajax( {
			url    : insightisticPro.ajaxUrl,
			method : 'POST',
			dataType: 'json',
			data   : {
				action: 'insightistic_get_woo_data',
				days: days,
				force: force ? 1 : 0,
				nonce: insightisticPro.nonce
			},
			success: function ( res ) {
				if ( res && res.success ) {
					currentWooData = res.data;
					dataLoaded.woo = true;
					renderWoo( res.data );
					renderCacheBadge(
						$( '#isp-woo-cache' ),
						res.data.cached_at,
						function () { loadWoo( true ); }
					);
				} else {
					$( '#isp-woo-loading' ).empty().append(
						errorBox( responseMessage( res ), null, function () { loadWoo( true ); }, 'ga4' )
					);
				}
			},
			error: function ( jqXHR, textStatus ) {
				$( '#isp-woo-loading' ).empty().append(
					errorBox( diagnoseAjaxError( jqXHR, textStatus ), null, function () { loadWoo( true ); }, 'ga4' )
				);
			},
			complete: function () { resetBtn( $btn, '', insightisticPro.i18n.loadCommerce || 'Load Commerce Data' ); }
		} );
	}

	function renderWoo( data ) {
		$( '#isp-woo-loading' ).hide();
		var sym = ( data.period && data.period.symbol ) || '$';

		// KPI cards.
		if ( data.overview ) {
			var ov = data.overview;
			$( '#isp-woo-revenue' ).text( fmtMoneyWoo( ov.revenue.value, sym ) );
			$( '#isp-woo-revenue-chg' ).html( changeHtml( ov.revenue.change ) );
			$( '#isp-woo-net' ).text( fmtMoneyWoo( ov.net_revenue.value, sym ) );
			$( '#isp-woo-net-chg' ).html( changeHtml( ov.net_revenue.change ) );
			$( '#isp-woo-orders' ).text( fmt( ov.orders.value ) );
			$( '#isp-woo-orders-chg' ).html( changeHtml( ov.orders.change ) );
			$( '#isp-woo-aov' ).text( fmtMoneyWoo( ov.aov.value, sym ) );
			$( '#isp-woo-aov-chg' ).html( changeHtml( ov.aov.change ) );
			$( '#isp-woo-refundrate' ).text( ov.refund_rate.value + '%' );
			$( '#isp-woo-refundrate-chg' ).html( changeHtmlInverted( ov.refund_rate.change ) );
			$( '#isp-woo-newcust' ).text( fmt( ov.new_customers.value ) );
			$( '#isp-woo-newcust-chg' ).html( changeHtml( ov.new_customers.change ) );
			$( '#isp-woo-repeat' ).text( ov.repeat_rate.value + '%' );
			$( '#isp-woo-repeat-chg' ).html( changeHtml( ov.repeat_rate.change ) );
			$( '#isp-woo-units' ).text( fmt( ov.units_sold.value ) );
			$( '#isp-woo-units-chg' ).html( changeHtml( ov.units_sold.change ) );
			$( '#isp-woo-cards' ).show();
		}

		// Charts.
		if ( data.timeline ) {
			renderWooCharts( data.timeline, data.order_status, sym );
			$( '#isp-woo-charts' ).show();
		}

		// Products + categories.
		$( '#isp-woo-products' ).html( renderWooProducts( data.top_products || [], sym ) );
		$( '#isp-woo-categories' ).html( renderWooCategories( data.top_categories || [], sym ) );
		$( '#isp-woo-prod-cards' ).show();

		// Customers + recent orders.
		$( '#isp-woo-customers' ).html( renderWooCustomers( data.top_customers || [], sym ) );
		$( '#isp-woo-recent' ).html( renderWooRecent( data.recent_orders || [], sym ) );
		$( '#isp-woo-cust-cards' ).show();

		// Geo + payment methods.
		$( '#isp-woo-geo' ).html( renderWooGeo( data.geography || [], sym ) );
		$( '#isp-woo-payments' ).html( renderWooPayments( data.payment_methods || [], sym ) );
		$( '#isp-woo-geo-cards' ).show();

		// Coupons + refunds + low stock.
		$( '#isp-woo-coupons' ).html( renderWooCoupons( data.coupons || [], sym ) );
		$( '#isp-woo-refunds' ).html( renderWooRefunds( data.refunds || {}, sym ) );
		$( '#isp-woo-lowstock' ).html( renderWooLowStock( data.low_stock || [] ) );
		$( '#isp-woo-ops-cards' ).show();

		if ( insightisticPro.aiEnabled ) {
			$( '#isp-woo-ai-analyze' ).show();
		}
	}

	function destroyWooCharts() {
		if ( wooTimelineCh ) { wooTimelineCh.destroy(); wooTimelineCh = null; }
		if ( wooStatusCh )   { wooStatusCh.destroy();   wooStatusCh   = null; }
	}

	function renderWooCharts( timeline, statuses, sym ) {
		var tlCtx = document.getElementById( 'isp-woo-chart-timeline' );
		if ( tlCtx ) {
			if ( wooTimelineCh ) { wooTimelineCh.destroy(); }
			wooTimelineCh = new Chart( tlCtx, {
				type: 'line',
				data: {
					labels: timeline.labels,
					datasets: [
						{
							label: insightisticPro.i18n.revenue, data: timeline.revenue,
							borderColor: '#10b981', backgroundColor: 'rgba(16,185,129,0.10)',
							borderWidth: 2, fill: true, tension: 0.4, yAxisID: 'y',
							pointRadius: timeline.labels.length > 60 ? 0 : 3
						},
						{
							label: 'Orders', data: timeline.orders,
							borderColor: '#3b82f6', backgroundColor: 'rgba(59,130,246,0.08)',
							borderWidth: 2, fill: true, tension: 0.4, yAxisID: 'y1',
							pointRadius: timeline.labels.length > 60 ? 0 : 3
						}
					]
				},
				options: {
					responsive: true, maintainAspectRatio: false,
					interaction: { mode: 'index', intersect: false },
					plugins: { legend: { display: true, position: 'top', labels: { usePointStyle: true, font: { size: 12 } } } },
					scales: {
						x : { grid: { color: 'rgba(0,0,0,0.04)' }, ticks: { maxTicksLimit: 12, font: { size: 11 } } },
						y : { position: 'left',  ticks: { font: { size: 11 }, callback: function ( v ) { return sym + fmt( v ); } } },
						y1: { position: 'right', grid: { drawOnChartArea: false }, ticks: { font: { size: 11 }, callback: function ( v ) { return fmt( v ); } } }
					}
				}
			} );
		}
		var stCtx = document.getElementById( 'isp-woo-chart-status' );
		if ( stCtx && statuses && statuses.length ) {
			if ( wooStatusCh ) { wooStatusCh.destroy(); }
			var palette = [ '#10b981','#3b82f6','#f59e0b','#ef4444','#8b5cf6','#06b6d4','#f97316','#84cc16','#6b7280' ];
			wooStatusCh = new Chart( stCtx, {
				type: 'doughnut',
				data: {
					labels:   statuses.map( function ( s ) { return s.label; } ),
					datasets: [ { data: statuses.map( function ( s ) { return s.count; } ), backgroundColor: palette, borderWidth: 2, borderColor: '#fff' } ]
				},
				options: { responsive: true, maintainAspectRatio: false, cutout: '62%',
					plugins: { legend: { position: 'bottom', labels: { font: { size: 11 }, usePointStyle: true, padding: 10, boxWidth: 8 } } } }
			} );
		}
	}

	function tbl( head, rows ) {
		if ( ! rows || ! rows.length ) {
			return '<p class="isp-no-data">' + escHtml( insightisticPro.i18n.noData ) + '</p>';
		}
		var html = '<table class="isp-gsc-table"><thead><tr>';
		$.each( head, function ( i, h ) { html += '<th>' + escHtml( h ) + '</th>'; } );
		html += '</tr></thead><tbody>';
		$.each( rows, function ( i, r ) {
			html += '<tr>';
			$.each( r, function ( j, c ) { html += '<td>' + c + '</td>'; } );
			html += '</tr>';
		} );
		return html + '</tbody></table>';
	}

	function renderWooProducts( items, sym ) {
		var rows = items.map( function ( p ) {
			var name = p.edit_url
				? '<a href="' + escHtml( p.edit_url ) + '">' + escHtml( truncate( p.name, 40 ) ) + '</a>'
				: escHtml( truncate( p.name, 40 ) );
			return [ name, escHtml( p.sku || '' ), fmt( p.units ), fmtMoneyWoo( p.revenue, sym ) ];
		} );
		return tbl( [ 'Product', 'SKU', 'Units', 'Revenue' ], rows );
	}

	function renderWooCategories( items, sym ) {
		var rows = items.map( function ( c ) {
			return [ escHtml( c.name ), fmt( c.units ), fmtMoneyWoo( c.revenue, sym ) ];
		} );
		return tbl( [ 'Category', 'Units', 'Revenue' ], rows );
	}

	function renderWooCustomers( items, sym ) {
		var rows = items.map( function ( c ) {
			return [ escHtml( c.name ), escHtml( c.email ), fmt( c.orders ), fmtMoneyWoo( c.revenue, sym ) ];
		} );
		return tbl( [ 'Customer', 'Email', 'Orders', 'Revenue' ], rows );
	}

	function renderWooRecent( items, sym ) {
		var rows = items.map( function ( o ) {
			var num = o.edit_url
				? '<a href="' + escHtml( o.edit_url ) + '">#' + escHtml( String( o.number ) ) + '</a>'
				: '#' + escHtml( String( o.number ) );
			var badge = '<span class="isp-woo-status isp-woo-status-' + escHtml( o.status ) + '">' + escHtml( o.status_label ) + '</span>';
			return [ num, badge, escHtml( o.customer ), o.item_count, escHtml( o.created ), fmtMoneyWoo( o.total, sym ) ];
		} );
		return tbl( [ 'Order', 'Status', 'Customer', 'Items', 'Created', 'Total' ], rows );
	}

	function renderWooGeo( items, sym ) {
		var rows = items.map( function ( g ) {
			return [ escHtml( g.name ) + ' <small style="color:#9ca3af">' + escHtml( g.code ) + '</small>', fmt( g.orders ), fmtMoneyWoo( g.revenue, sym ), g.share + '%' ];
		} );
		return tbl( [ 'Country', 'Orders', 'Revenue', 'Share' ], rows );
	}

	function renderWooPayments( items, sym ) {
		var rows = items.map( function ( m ) {
			return [ escHtml( m.label ), fmt( m.orders ), fmtMoneyWoo( m.revenue, sym ) ];
		} );
		return tbl( [ 'Method', 'Orders', 'Revenue' ], rows );
	}

	function renderWooCoupons( items, sym ) {
		var rows = items.map( function ( c ) {
			return [ '<code>' + escHtml( c.code ) + '</code>', fmt( c.uses ), fmtMoneyWoo( c.discount, sym ) ];
		} );
		return tbl( [ 'Code', 'Uses', 'Discount given' ], rows );
	}

	function renderWooRefunds( data, sym ) {
		var html = '<div class="isp-woo-refund-summary">'
			+ '<div><span class="isp-stat-label">Refunded</span><strong>' + fmtMoneyWoo( data.amount, sym ) + '</strong></div>'
			+ '<div><span class="isp-stat-label">Refunds</span><strong>' + fmt( data.count ) + '</strong></div>'
			+ '</div>';
		var reasons = data.reasons || [];
		if ( reasons.length ) {
			html += tbl( [ 'Reason', 'Count' ], reasons.map( function ( r ) { return [ escHtml( r.reason ), fmt( r.count ) ]; } ) );
		} else {
			html += '<p class="isp-no-data" style="padding:14px;color:#6b7280;font-size:12px;">' + escHtml( insightisticPro.i18n.noData ) + '</p>';
		}
		return html;
	}

	function renderWooLowStock( items ) {
		if ( ! items.length ) {
			return '<p class="isp-no-data" style="padding:24px;color:#6b7280;font-size:13px;">No low-stock items right now.</p>';
		}
		var rows = items.map( function ( p ) {
			var name = p.edit_url
				? '<a href="' + escHtml( p.edit_url ) + '">' + escHtml( truncate( p.name, 36 ) ) + '</a>'
				: escHtml( truncate( p.name, 36 ) );
			return [ name, escHtml( p.sku || '' ), fmt( p.stock ), fmt( p.threshold ) ];
		} );
		return tbl( [ 'Product', 'SKU', 'Stock', 'Threshold' ], rows );
	}

	/* ================================================================== */
	/* 404 & BROKEN LINK MONITOR                                           */
	/* ================================================================== */

	function load404Report() {
		var $out = $( '#isp-404-table' );
		if ( ! $out.length ) { return; }
		$out.html( '<p class="isp-no-data">' + escHtml( insightisticPro.i18n.loading ) + '</p>' );
		$.ajax( {
			url: insightisticPro.ajaxUrl, method: 'POST', dataType: 'json',
			data: { action: 'insightistic_get_404_report', nonce: insightisticPro.nonce },
			success: function ( res ) {
				if ( res && res.success ) {
					render404Report( res.data );
				} else {
					$out.html( '<div class="isp-notice isp-notice-error">' + escHtml( ( res && res.data ) || insightisticPro.i18n.error ) + '</div>' );
				}
			},
			error: function ( jqXHR, textStatus ) {
				$out.html( '<div class="isp-notice isp-notice-error">' + escHtml( diagnoseAjaxError( jqXHR, textStatus ) ) + '</div>' );
			}
		} );
	}

	function render404Report( data ) {
		var $out = $( '#isp-404-table' );
		var rows = ( data && data.rows ) || [];
		if ( ! rows.length ) {
			$out.html( '<p class="isp-no-data">No 404s logged yet.</p>' );
			return;
		}
		var tableRows = rows.map( function ( r ) {
			return [
				'<code>' + escHtml( truncate( r.path, 60 ) ) + '</code>',
				fmt( r.count ),
				r.referrers && r.referrers.length ? escHtml( r.referrers.join( ', ' ) ) : '',
				relativeTime( r.last_seen )
			];
		} );
		$out.html( tbl( [ 'URL', 'Hits', 'Referring domains', 'Last seen' ], tableRows ) );
	}

	function init404Monitor() {
		$( '#isp-404-refresh' ).on( 'click', load404Report );
		$( '#isp-404-clear' ).on( 'click', function () {
			if ( ! window.confirm( insightisticPro.i18n.confirmClear404 || 'Clear the 404 log? This cannot be undone.' ) ) { return; }
			$.post( insightisticPro.ajaxUrl, { action: 'insightistic_clear_404_log', nonce: insightisticPro.nonce } )
				.always( load404Report );
		} );
		load404Report();
	}

	function runWooAI() {
		if ( ! currentWooData ) { return; }
		var $btn   = $( '#isp-woo-ai-analyze' );
		var $panel = $( '#isp-woo-ai-insights' );
		var orig   = $btn.html();
		$btn.prop( 'disabled', true ).html( '<span class="isp-inline-spinner"></span> ' + escHtml( insightisticPro.i18n.analyzing ) );
		$panel.html( aiHeaderHtml() ).show();
		$.ajax( {
			url: insightisticPro.ajaxUrl, method: 'POST', dataType: 'json',
			data: {
				action: 'insightistic_woo_ai_analyze',
				data: JSON.stringify( currentWooData.structured_data ),
				days: $( '#isp-woo-date-range' ).val() || '28',
				nonce: insightisticPro.nonce
			},
			success: function ( res ) {
				if ( res && res.success ) {
					lastWooAIHtml = res.data.html;
					$panel.html( res.data.html + aiFooterToolbar() );
					animateGauges( $panel );
				} else if ( res.data && res.data.html ) {
					$panel.html( res.data.html );
				} else {
					$panel.html( '<div class="isp-notice isp-notice-error" style="margin:16px;">' + escHtml( responseMessage( res ) ) + '</div>' );
				}
			},
			error: function ( jqXHR, textStatus ) {
				$panel.html( '<div class="isp-notice isp-notice-error" style="margin:16px;">' + escHtml( diagnoseAjaxError( jqXHR, textStatus ) ) + '</div>' );
			},
			complete: function () { $btn.prop( 'disabled', false ).html( orig ); }
		} );
	}

	/* ================================================================== */
	/* CLOUDFLARE TRAFFIC INSIGHTS                                         */
	/* ================================================================== */

	function loadCloudflare( force ) {
		var $btn  = $( '#isp-load-cf' );
		var days  = $( '#isp-cf-date-range' ).val() || '28';

		spinnerBtn( $btn, insightisticPro.i18n.loading );
		$( '#isp-cf-cards,#isp-cf-charts,#isp-cf-detail-cards,#isp-cf-not-linked' ).hide();
		$( '#isp-cf-loading' ).html( skeletonHtml() ).show();

		$.ajax( {
			url: insightisticPro.ajaxUrl, method: 'POST',
			data: {
				action: 'insightistic_get_cloudflare_data',
				days: days,
				force: force ? 1 : 0,
				nonce: insightisticPro.nonce
			},
			dataType: 'json',
			success: function ( res ) {
				if ( res && res.success && res.data && false === res.data.available ) {
					// Account connected but no Cloudflare zone linked yet
					// an expected state, not an error.
					dataLoaded.cloudflare = true;
					$( '#isp-cf-loading' ).hide();
					$( '#isp-cf-not-linked' ).show();
				} else if ( res && res.success ) {
					dataLoaded.cloudflare = true;
					$( '#isp-cf-not-linked' ).hide();
					renderCloudflare( res.data );
					renderCacheBadge(
						$( '#isp-cf-cache' ),
						res.data.cached_at,
						function () { loadCloudflare( true ); }
					);
				} else {
					$( '#isp-cf-loading' ).empty().append(
						errorBox( ( res && res.data ) || insightisticPro.i18n.error, null, function () { loadCloudflare( true ); }, 'cloudflare' )
					);
				}
			},
			error: function ( jqXHR, textStatus ) {
				$( '#isp-cf-loading' ).empty().append(
					errorBox( diagnoseAjaxError( jqXHR, textStatus ), null, function () { loadCloudflare( true ); }, 'cloudflare' )
				);
			},
			complete: function () { resetBtn( $btn, '', insightisticPro.i18n.loadData ); }
		} );
	}

	function renderCloudflare( data ) {
		$( '#isp-cf-loading' ).hide();
		var totals = data.totals || {};
		lastCfData = data;

		$( '#isp-cf-requests' ).text( fmt( totals.requests || 0 ) );
		$( '#isp-cf-cache-ratio' ).text( ( data.cache_ratio || 0 ) + '%' );
		$( '#isp-cf-threats' ).text( fmt( totals.threats || 0 ) );
		$( '#isp-cf-encrypted' ).text( ( data.encrypted_ratio || 0 ) + '%' );
		$( '#isp-cf-cards' ).show();

		renderCfCharts( data );
		renderTrafficGap();
		renderCfSecurity( data );
		$( '#isp-cf-ai-analyze' ).show();

		renderCfRankList( $( '#isp-cf-countries-list' ), data.top_countries || {}, function ( name, count ) {
			return escHtml( name ) + ' <span class="isp-rank-share" style="margin-left:6px;color:#9ca3af;">' + fmt( count ) + '</span>';
		} );
		renderCfRankList( $( '#isp-cf-tls-list' ), data.tls_versions || {}, function ( name, count ) {
			return escHtml( name || 'Unknown' ) + ' <span class="isp-rank-share" style="margin-left:6px;color:#9ca3af;">' + fmt( count ) + '</span>';
		} );
		$( '#isp-cf-detail-cards' ).show();
	}

	/**
	 * Render a { label: count } (or { label: {requests: n, ...} }) map as a
	 * ranked <li> list with share bars, reusing the .isp-rank-list markup
	 * already styled for Top Countries/Top Pages.
	 */
	function renderCfRankList( $list, map, labelFn ) {
		$list.empty();
		var keys = Object.keys( map );
		if ( ! keys.length ) {
			$list.html( '<li class="isp-rank-loading">' + escHtml( insightisticPro.i18n.noData ) + '</li>' );
			return;
		}
		var entries = keys.map( function ( k ) {
			var v = map[ k ];
			var count = ( typeof v === 'object' && v !== null ) ? ( v.requests || 0 ) : v;
			return { name: k, count: count };
		} );
		entries.sort( function ( a, b ) { return b.count - a.count; } );
		var total = entries.reduce( function ( acc, e ) { return acc + e.count; }, 0 ) || 1;
		entries.slice( 0, 10 ).forEach( function ( e, i ) {
			var share = Math.round( ( e.count / total ) * 1000 ) / 10;
			$list.append(
				'<li>' +
				'<span class="isp-rank-num">' + ( i + 1 ) + '</span>' +
				'<span class="isp-rank-name" title="' + escHtml( e.name ) + '">' + labelFn( e.name, e.count ) + '</span>' +
				'<div class="isp-rank-bar-wrap"><div class="isp-rank-bar"><div class="isp-rank-bar-fill" style="width:' + share + '%"></div></div></div>' +
				'<span class="isp-rank-share">' + share + '%</span>' +
				'</li>'
			);
		} );
	}

	function renderCfCharts( data ) {
		var timeline = data.timeline || [];
		var tlCtx = document.getElementById( 'isp-cf-chart-timeline' );
		if ( tlCtx ) {
			if ( cfTimelineChart ) { cfTimelineChart.destroy(); }
			cfTimelineChart = new Chart( tlCtx, {
				type: 'line',
				data: {
					labels: timeline.map( function ( r ) { return r.date; } ),
					datasets: [
						{
							label: 'Requests', data: timeline.map( function ( r ) { return r.requests; } ),
							borderColor: '#3b82f6', backgroundColor: 'rgba(59,130,246,0.08)',
							borderWidth: 2, fill: true, tension: 0.4, pointRadius: timeline.length > 30 ? 0 : 3
						},
						{
							label: 'Cached', data: timeline.map( function ( r ) { return r.cached_requests; } ),
							borderColor: '#10b981', backgroundColor: 'rgba(16,185,129,0.06)',
							borderWidth: 2, fill: true, tension: 0.4, pointRadius: timeline.length > 30 ? 0 : 3
						},
						{
							label: 'Threats', data: timeline.map( function ( r ) { return r.threats; } ),
							borderColor: '#f59e0b', backgroundColor: 'rgba(245,158,11,0.06)',
							borderWidth: 2, fill: false, tension: 0.4, pointRadius: timeline.length > 30 ? 0 : 3
						}
					]
				},
				options: {
					responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
					plugins: { legend: { position: 'top', labels: { font: { size: 12 }, usePointStyle: true } }, tooltip: { mode: 'index', intersect: false } },
					scales: {
						x: { grid: { color: 'rgba(0,0,0,0.04)' }, ticks: { font: { size: 11 }, maxTicksLimit: 12 } },
						y: { grid: { color: 'rgba(0,0,0,0.04)' }, ticks: { font: { size: 11 }, callback: function ( v ) { return fmt( v ); } } }
					}
				}
			} );
		}

		var stCtx = document.getElementById( 'isp-cf-chart-status' );
		if ( stCtx ) {
			if ( cfStatusChart ) { cfStatusChart.destroy(); }
			var statusMap = data.status_codes || {};
			var labels = Object.keys( statusMap );
			var values = labels.map( function ( k ) { return statusMap[ k ]; } );
			var palette = [ '#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#06b6d4', '#f97316', '#84cc16', '#6b7280', '#ec4899' ];
			cfStatusChart = new Chart( stCtx, {
				type: 'doughnut',
				data: { labels: labels, datasets: [ { data: values, backgroundColor: palette, borderWidth: 2, borderColor: '#fff' } ] },
				options: { responsive: true, maintainAspectRatio: false, cutout: '62%',
					plugins: { legend: { position: 'bottom', labels: { font: { size: 11 }, usePointStyle: true, padding: 10, boxWidth: 8 } } } }
			} );
		}
	}

	/**
	 * AI narrative over the already-loaded Traffic Insights payload. Gated
	 * server-side by the same free-account requirement as every other AI
	 * Insights flow; a locked response renders the upgrade card the server
	 * sends rather than stringifying it (unlike a plain error message, the
	 * locked payload is `{ code, html }`, not a string).
	 */
	function runCfAI() {
		if ( ! lastCfData ) { return; }
		var $btn   = $( '#isp-cf-ai-analyze' );
		var $panel = $( '#isp-cf-ai-insights' );
		var orig   = $btn.html();
		var days   = $( '#isp-cf-date-range' ).val() || '28';

		$btn.prop( 'disabled', true ).html( '<span class="isp-inline-spinner"></span> ' + escHtml( insightisticPro.i18n.analyzing ) );
		$panel.html( aiHeaderHtml() ).show();

		$.ajax( {
			url: insightisticPro.ajaxUrl, method: 'POST',
			data: { action: 'insightistic_cloudflare_ai_analyze', data: JSON.stringify( lastCfData ), days: days, nonce: insightisticPro.nonce },
			dataType: 'json',
			success: function ( res ) {
				if ( res && res.success ) {
					$panel.html( res.data.html + aiFooterToolbar() );
					animateGauges( $panel );
				} else if ( res && res.data && res.data.html ) {
					$panel.html( res.data.html );
				} else {
					$panel.html( '<div class="isp-notice isp-notice-error" style="margin:16px;">' + escHtml( ( res && res.data ) || insightisticPro.i18n.error ) + '</div>' );
				}
			},
			error: function ( jqXHR, textStatus ) {
				$panel.html( '<div class="isp-notice isp-notice-error" style="margin:16px;">' + escHtml( diagnoseAjaxError( jqXHR, textStatus ) ) + '</div>' );
			},
			complete: function () { $btn.prop( 'disabled', false ).html( orig ); }
		} );
	}

	/**
	 * Security monitor: aggregates the firewall events already fetched in
	 * Phase 1 (WAF challenges/blocks) by action and by attacking country.
	 * Free, read-only  no Bot Management subscription required, since this
	 * only reads events the zone's existing firewall rules already logged.
	 */
	function renderCfSecurity( data ) {
		var $box = $( '#isp-cf-security' );
		if ( ! $box.length ) { return; }

		var events = data.firewall_events || [];
		if ( ! events.length ) {
			$box.html(
				'<div class="isp-detail-card">' +
				'<div class="isp-detail-card-header"><span class="isp-detail-card-title">' + escHtml( 'Security  Firewall Events' ) + '</span></div>' +
				'<p class="isp-no-data">' + escHtml( 'No firewall events in this period.' ) + '</p>' +
				'</div>'
			).show();
			return;
		}

		var byAction  = {};
		var byCountry = {};
		events.forEach( function ( e ) {
			var action = e.action || 'unknown';
			byAction[ action ] = ( byAction[ action ] || 0 ) + e.count;
			if ( e.country ) {
				byCountry[ e.country ] = ( byCountry[ e.country ] || 0 ) + e.count;
			}
		} );

		var actionKeys  = Object.keys( byAction ).sort( function ( a, b ) { return byAction[ b ] - byAction[ a ]; } );
		var countryKeys = Object.keys( byCountry ).sort( function ( a, b ) { return byCountry[ b ] - byCountry[ a ]; } ).slice( 0, 10 );

		var actionHtml = actionKeys.map( function ( a ) {
			return '<li><span class="isp-rank-name">' + escHtml( a ) + '</span><span class="isp-rank-share">' + fmt( byAction[ a ] ) + '</span></li>';
		} ).join( '' ) || '<li class="isp-rank-loading">' + escHtml( insightisticPro.i18n.noData ) + '</li>';

		var countryHtml = countryKeys.map( function ( c, i ) {
			return '<li><span class="isp-rank-num">' + ( i + 1 ) + '</span><span class="isp-rank-name">' + escHtml( c ) + '</span><span class="isp-rank-share">' + fmt( byCountry[ c ] ) + '</span></li>';
		} ).join( '' ) || '<li class="isp-rank-loading">' + escHtml( insightisticPro.i18n.noData ) + '</li>';

		$box.html(
			'<div class="isp-section-header"><h2 class="isp-section-title">' + escHtml( 'Security  Threats Blocked' ) + '</h2></div>' +
			'<div class="isp-detail-cards">' +
			'<div class="isp-detail-card">' +
			'<div class="isp-detail-card-header"><span class="isp-detail-card-title">' + escHtml( 'Firewall Actions' ) + '</span><span class="isp-detail-card-sub">' + escHtml( 'by event count' ) + '</span></div>' +
			'<ul class="isp-rank-list">' + actionHtml + '</ul>' +
			'</div>' +
			'<div class="isp-detail-card">' +
			'<div class="isp-detail-card-header"><span class="isp-detail-card-title">' + escHtml( 'Top Attacking Countries' ) + '</span><span class="isp-detail-card-sub">' + escHtml( 'by firewall events' ) + '</span></div>' +
			'<ul class="isp-rank-list">' + countryHtml + '</ul>' +
			'</div>' +
			'</div>'
		).show();
	}

	/**
	 * "Traffic Gap" callout: compares Cloudflare's edge-detected pageviews
	 * against GA4's client-side-tag pageviews for the same period. The
	 * delta is traffic Cloudflare served that never fired the GA4 tag  bots/
	 * crawlers, ad-blockers, JS-disabled visitors, a blocked script, etc.
	 * Only meaningful when both panels are looking at the same date range,
	 * so a mismatch is surfaced as a prompt rather than a misleading number.
	 */
	function renderTrafficGap() {
		var $box = $( '#isp-cf-gap-callout' );
		if ( ! $box.length || ! lastCfData ) { return; }

		if ( ! currentData || ! currentData.structured_data ) {
			$box.html( '<div class="isp-notice isp-notice-info">' + escHtml( 'Load the Overview tab to compare against GA4 pageviews.' ) + '</div>' ).show();
			return;
		}

		var cfDays  = $( '#isp-cf-date-range' ).val() || '28';
		var ga4Days = $( '#isp-date-range' ).val() || '28';
		if ( cfDays !== ga4Days ) {
			$box.html(
				'<div class="isp-notice isp-notice-info">' +
				escHtml( 'Set the Overview (' + ga4Days + ' days) and Traffic Insights (' + cfDays + ' days) date ranges to match to see the traffic gap.' ) +
				'</div>'
			).show();
			return;
		}

		var ga4Pageviews = parseInt( ( $( '#isp-val-pageviews' ).text() || '0' ).replace( /,/g, '' ), 10 ) || 0;
		var cfPageviews  = ( lastCfData.totals && lastCfData.totals.page_views ) || 0;

		if ( ! cfPageviews ) { $box.hide(); return; }

		var gap    = cfPageviews - ga4Pageviews;
		var gapPct = Math.round( ( gap / cfPageviews ) * 1000 ) / 10;

		var html = '<div class="isp-gap-callout' + ( gap > 0 ? ' isp-gap-positive' : '' ) + '">' +
			'<div class="isp-gap-icon" aria-hidden="true"></div>' +
			'<div class="isp-gap-body">' +
			'<h4>' + ( gap > 0
				? escHtml( Math.abs( gapPct ) + '% of edge traffic never reached GA4' )
				: escHtml( 'GA4 and Cloudflare pageviews are closely aligned' ) ) + '</h4>' +
			'<p>' + escHtml( 'Cloudflare recorded ' ) + '<strong>' + fmt( cfPageviews ) + '</strong>' + escHtml( ' edge pageviews over the last ' + cfDays + ' days; GA4 recorded ' ) + '<strong>' + fmt( ga4Pageviews ) + '</strong>' + escHtml( '.' ) + ' ' +
			escHtml( gap > 0
				? ( 'The ' + fmt( gap ) + '-pageview gap is typically bots/crawlers, ad-blockers, JS-disabled visitors, or a blocked GA4 script  traffic your GA4 dashboard alone would never show you.' )
				: 'No significant gap detected for this period.' ) +
			'</p></div></div>';

		$box.html( html ).show();
	}

	/* ================================================================== */
	/* SETTINGS: TABS                                                      */
	/* ================================================================== */

	function activateSettingsTab( tab ) {
		if ( ! tab || ! $( '#isp-tab-' + tab ).length ) { return false; }
		$( '.isp-tab-btn' ).removeClass( 'isp-tab-active' );
		$( '.isp-tab-btn[data-tab="' + tab + '"]' ).addClass( 'isp-tab-active' );
		$( '.isp-tab-panel' ).hide();
		$( '#isp-tab-' + tab ).show();
		$( '#isp-active-tab' ).val( tab );
		// Mirror the active tab into the URL hash so reloads (Save Settings)
		// land back on the same panel.
		if ( window.history && window.history.replaceState ) {
			window.history.replaceState( null, '', '#' + tab );
		}
		return true;
	}

	function initSettingsTabs() {
		$( '.isp-tab-btn' ).on( 'click', function () {
			activateSettingsTab( $( this ).data( 'tab' ) );
		} );

		// Priority: PHP-rendered hidden field (preserves tab across Save reload)
		// -> URL query (?active_tab=ai) -> URL hash -> default GA4.
		var phpTab = ( $( '#isp-active-tab' ).val() || '' ).trim();
		var qs = new URLSearchParams( window.location.search );
		var qsTab = qs.get( 'active_tab' );
		var hashTab = window.location.hash.replace( '#', '' );

		if ( phpTab && activateSettingsTab( phpTab ) ) { return; }
		if ( qsTab && activateSettingsTab( qsTab ) ) { return; }
		if ( hashTab && activateSettingsTab( hashTab ) ) { return; }
		activateSettingsTab( 'ga4' );
	}

	/* ================================================================== */
	/* SETTINGS: AI PROVIDER TOGGLE                                        */
	/* ================================================================== */

	function initProviderToggle() {
		$( 'input[name="ai_provider"]' ).on( 'change', function () {
			var provider = $( this ).val();
			$( '.isp-provider-card' ).removeClass( 'isp-provider-selected' );
			$( this ).closest( '.isp-provider-card' ).addClass( 'isp-provider-selected' );
			$( '.isp-provider-settings' ).hide();
			if ( provider !== 'none' ) {
				$( '#isp-settings-' + provider ).show();
			}
		} );
	}

	/* ================================================================== */
	/* SETTINGS: JSON IMPORTER                                             */
	/* ================================================================== */

	function initJsonImporter() {
		$( '#isp-toggle-json' ).on( 'click', function () {
			$( '#isp-json-wrap' ).toggle();
		} );
		$( '#isp-extract-json' ).on( 'click', function () {
			var raw     = $( '#isp-json-input' ).val();
			var $notice = $( '#isp-json-notice' );
			try {
				var json = JSON.parse( raw );
				if ( json.client_email ) { $( '#api_email' ).val( json.client_email ); }
				if ( json.private_key ) {
					$( '#api_private_key' ).show().val( json.private_key );
					$( '.isp-key-stored' ).hide();
				}
				$( '#isp-json-wrap' ).hide();
				$( '#isp-json-input' ).val( '' );
				$notice
					.removeClass( 'isp-notice-error' )
					.addClass( 'isp-notice-success' )
					.text( insightisticPro.i18n.jsonOk )
					.show();
			} catch ( e ) {
				$notice
					.removeClass( 'isp-notice-success' )
					.addClass( 'isp-notice-error' )
					.text( insightisticPro.i18n.jsonErr )
					.show();
			}
		} );
		$( '#isp-change-key' ).on( 'click', function () {
			$( '.isp-key-stored' ).hide();
			$( '#api_private_key' ).show().focus();
		} );
		$( '#isp-change-psi-key' ).on( 'click', function () {
			$( this ).closest( '.isp-key-stored' ).hide();
			$( '#pagespeed_api_key' ).show().focus();
		} );
		$( '#isp-change-secret' ).on( 'click', function () {
			$( this ).closest( '.isp-key-stored' ).hide();
			$( '#measurement_secret' ).show().focus();
		} );
		$( '#isp-change-cf-token' ).on( 'click', function () {
			$( this ).closest( '.isp-key-stored' ).hide();
			$( '#cf_api_token' ).show().focus();
		} );
	}

	/* ================================================================== */
	/* SETTINGS: TEST CONNECTIONS                                          */
	/* ================================================================== */

	function initTestConnection() {
		// GA4.
		$( '#isp-test-connection' ).on( 'click', function () {
			var $btn = $( this ), $result = $( '#isp-test-result' ), orig = $btn.html();
			$btn.prop( 'disabled', true ).html( '<span class="isp-inline-spinner"></span> Testing' );
			$result.html( '' );
			$.ajax( {
				url: insightisticPro.ajaxUrl, method: 'POST',
				data: { action: 'insightistic_test_connection', nonce: insightisticPro.nonce },
				success: function ( res ) {
					$result.html( res.success
						? '<div class="isp-notice isp-notice-success">' + escHtml( res.data ) + '</div>'
						: '<div class="isp-notice isp-notice-error">' + escHtml( res.data ) + '</div>' );
				},
				error: function () { $result.html( '<div class="isp-notice isp-notice-error">' + insightisticPro.i18n.error + '</div>' ); },
				complete: function () { $btn.prop( 'disabled', false ).html( orig ); }
			} );
		} );

		// GSC.
		$( '#isp-test-gsc' ).on( 'click', function () {
			var $btn = $( this ), $result = $( '#isp-test-gsc-result' ), orig = $btn.html();
			$btn.prop( 'disabled', true ).html( '<span class="isp-inline-spinner"></span> Testing' );
			$result.html( '' );
			$.ajax( {
				url: insightisticPro.ajaxUrl, method: 'POST',
				data: { action: 'insightistic_test_gsc', nonce: insightisticPro.nonce },
				success: function ( res ) {
					$result.html( res.success
						? '<div class="isp-notice isp-notice-success">' + escHtml( res.data ) + '</div>'
						: '<div class="isp-notice isp-notice-error">' + escHtml( res.data ) + '</div>' );
				},
				error: function () { $result.html( '<div class="isp-notice isp-notice-error">' + insightisticPro.i18n.error + '</div>' ); },
				complete: function () { $btn.prop( 'disabled', false ).html( orig ); }
			} );
		} );

		// Cloudflare.
		$( '#isp-test-cloudflare' ).on( 'click', function () {
			var $btn = $( this ), $result = $( '#isp-test-cloudflare-result' ), orig = $btn.html();
			$btn.prop( 'disabled', true ).html( '<span class="isp-inline-spinner"></span> Testing' );
			$result.html( '' );
			$.ajax( {
				url: insightisticPro.ajaxUrl, method: 'POST',
				data: { action: 'insightistic_test_cloudflare', nonce: insightisticPro.nonce },
				success: function ( res ) {
					$result.html( res.success
						? '<div class="isp-notice isp-notice-success">' + escHtml( res.data ) + '</div>'
						: '<div class="isp-notice isp-notice-error">' + escHtml( res.data ) + '</div>' );
				},
				error: function () { $result.html( '<div class="isp-notice isp-notice-error">' + insightisticPro.i18n.error + '</div>' ); },
				complete: function () { $btn.prop( 'disabled', false ).html( orig ); }
			} );
		} );

		// AI provider test  routed by data-provider so each provider tile gets one button.
		$( document ).on( 'click', '.isp-test-ai-provider', function () {
			var $btn = $( this );
			var prov = $btn.data( 'provider' );
			var $result = $btn.closest( '.isp-settings-card' ).find( '.isp-ai-test-result' );
			var orig = $btn.html();
			$btn.prop( 'disabled', true ).html( '<span class="isp-inline-spinner"></span> ' + escHtml( insightisticPro.i18n.testing || 'Testing' ) );
			$result.html( '' );
			$.ajax( {
				url: insightisticPro.ajaxUrl, method: 'POST',
				data: { action: 'insightistic_test_ai_provider', provider: prov, nonce: insightisticPro.nonce },
				success: function ( res ) {
					$result.html( res.success
						? '<div class="isp-notice isp-notice-success">' + escHtml( res.data ) + '</div>'
						: '<div class="isp-notice isp-notice-error">' + escHtml( res.data ) + '</div>' );
				},
				error: function () { $result.html( '<div class="isp-notice isp-notice-error">' + escHtml( insightisticPro.i18n.error ) + '</div>' ); },
				complete: function () { $btn.prop( 'disabled', false ).html( orig ); }
			} );
		} );
	}

	/* ================================================================== */
	/* CWV Device Tab Toggle                                               */
	/* ================================================================== */

	function initCwvTabs() {
		$( document ).on( 'click', '.isp-cwv-tab', function () {
			var tab = $( this ).data( 'cwv-tab' );
			$( '.isp-cwv-tab' ).removeClass( 'isp-cwv-tab-active' );
			$( this ).addClass( 'isp-cwv-tab-active' );
			$( '#isp-cwv-mobile, #isp-cwv-desktop' ).hide();
			$( '#isp-cwv-' + tab ).show();
		} );
	}

	/* ================================================================== */
	/* ADDONS: FILTER + TOGGLES + EMAIL DIGEST                             */
	/* ================================================================== */

	function initAddonsFilter() {
		var $btns  = $( '.isp-addon-filter-btn' );
		var $cards = $( '.isp-addon-card' );

		$btns.on( 'click', function () {
			var filter = $( this ).data( 'filter' );
			$btns.removeClass( 'isp-addon-filter-active' );
			$( this ).addClass( 'isp-addon-filter-active' );
			$cards.each( function () {
				var show = filter === 'all'
					|| $( this ).data( 'type' )   === filter
					|| $( this ).data( 'status' ) === filter;
				$( this ).toggle( show );
			} );
		} );
	}

	/**
	 * Extract a displayable error out of a wp_send_json_error() payload.
	 * Locked-feature responses carry a fully-rendered `html` card (built by
	 * Insightistic_Feature_Gate::locked_card()) — render that verbatim instead
	 * of falling through to String(resData), which would print "[object Object]".
	 */
	function errorNoticeMarkup( resData, fallback ) {
		if ( resData && typeof resData === 'object' && resData.html ) {
			return { html: resData.html, locked: true };
		}
		var msg = ( resData && typeof resData === 'object' ) ? ( resData.message || fallback ) : ( resData || fallback );
		return { html: '<div class="isp-notice isp-notice-error">' + escHtml( msg ) + '</div>', locked: false };
	}

	/**
	 * Build a per-card error notice for any addon mutation failure.
	 */
	function addonErrorNotice( $card, resData ) {
		$card.find( '.isp-addon-error' ).remove();
		var built = errorNoticeMarkup( resData, insightisticPro.i18n.error );
		var $n = $( '<div class="isp-addon-error"></div>' ).html( built.html );
		$card.find( '.isp-addon-footer' ).after( $n );
		// Locked/account-notice cards stay until the next action; plain error toasts auto-fade.
		if ( ! built.locked ) {
			setTimeout( function () { $n.fadeOut( 400, function () { $( this ).remove(); } ); }, 6000 );
		}
	}

	function initAddonToggles() {
		$( document ).on( 'change', '.isp-addon-toggle-input', function () {
			var $toggle = $( this );
			var $wrap = $toggle.closest( '.isp-addon-toggle' );
			var $card = $toggle.closest( '.isp-addon-card' );
			var slug = $toggle.data( 'addon-toggle' );
			var enabled = $toggle.is( ':checked' ) ? 1 : 0;
			var $label = $wrap.find( '.isp-addon-toggle-label' );

			// Lock the toggle for the duration of the AJAX call so a slow
			// connection cannot double-fire and produce flapping state.
			$toggle.prop( 'disabled', true );
			$wrap.addClass( 'is-loading' );

			$.ajax( {
				url: insightisticPro.ajaxUrl,
				method: 'POST',
				data: {
					action: 'insightistic_toggle_addon',
					slug: slug,
					enabled: enabled,
					nonce: insightisticPro.nonce
				},
				success: function ( res ) {
					if ( ! res.success ) {
						$toggle.prop( 'checked', ! enabled );
						addonErrorNotice( $card, res.data || insightisticPro.i18n.error );
						return;
					}
					$label.text( enabled ? ( insightisticPro.i18n.enabled || 'Enabled' ) : ( insightisticPro.i18n.disabled || 'Disabled' ) );
					if ( slug === 'email_automations' ) {
						$( '#isp-email-automation-config' ).toggleClass( 'is-hidden', ! enabled );
					} else {
						$( '#isp-addon-config-' + slug ).toggleClass( 'is-hidden', ! enabled );
					}
				},
				error: function () {
					$toggle.prop( 'checked', ! enabled );
					addonErrorNotice( $card, insightisticPro.i18n.error );
				},
				complete: function () {
					$toggle.prop( 'disabled', false );
					$wrap.removeClass( 'is-loading' );
				}
			} );
		} );

		// --- Email automation: capture baseline values for dirty-form detection. ---
		var emailBaseline = null;
		function snapshotEmail() {
			emailBaseline = {
				recipients: ( $( '#isp-email-recipients' ).val() || '' ).trim(),
				frequency : $( '#isp-email-frequency' ).val() || 'weekly',
				day       : $( '#isp-email-day' ).val() || 'monday',
				time      : $( '#isp-email-time' ).val() || '09:00'
			};
		}
		function emailIsDirty() {
			if ( ! emailBaseline ) { return false; }
			return ( $( '#isp-email-recipients' ).val() || '' ).trim() !== emailBaseline.recipients
				|| $( '#isp-email-frequency' ).val() !== emailBaseline.frequency
				|| $( '#isp-email-day' ).val() !== emailBaseline.day
				|| $( '#isp-email-time' ).val() !== emailBaseline.time;
		}
		snapshotEmail();

		$( '#isp-save-email-automation' ).on( 'click', function () {
			var $btn = $( this );
			var $notice = $( '#isp-email-automation-notice' );
			var recipients = $( '#isp-email-recipients' ).val() || '';
			var frequency = $( '#isp-email-frequency' ).val() || 'weekly';
			var day = $( '#isp-email-day' ).val() || 'monday';
			var time = $( '#isp-email-time' ).val() || '09:00';
			var orig = $btn.text();

			$btn.prop( 'disabled', true ).text( insightisticPro.i18n.saving || 'Saving...' );
			$notice.html( '' );

			$.ajax( {
				url: insightisticPro.ajaxUrl,
				method: 'POST',
				data: {
					action: 'insightistic_save_email_automation',
					recipients: recipients,
					frequency: frequency,
					day: day,
					time: time,
					nonce: insightisticPro.nonce
				},
				success: function ( res ) {
					if ( res.success ) {
						$notice.html( '<div class="isp-notice isp-notice-success">' + escHtml( res.data.message || ( insightisticPro.i18n.settingsSaved || 'Settings saved.' ) ) + '</div>' );
						snapshotEmail();
					} else {
						$notice.html( errorNoticeMarkup( res.data, insightisticPro.i18n.error ).html );
					}
				},
				error: function () { $notice.html( '<div class="isp-notice isp-notice-error">' + escHtml( insightisticPro.i18n.error ) + '</div>' ); },
				complete: function () { $btn.prop( 'disabled', false ).text( orig ); }
			} );
		} );

		$( '#isp-send-test-email-automation' ).on( 'click', function () {
			var $btn = $( this );
			var $notice = $( '#isp-email-automation-notice' );
			var orig = $btn.text();

			// Dirty-form guard: warn before sending to the SAVED recipient.
			if ( emailIsDirty() ) {
				var saved = ( emailBaseline && emailBaseline.recipients ) || ( insightisticPro.i18n.adminEmail || 'the saved address' );
				var warn = ( insightisticPro.i18n.emailDirty || 'You have unsaved changes. The test will send to:' ) + ' ' + saved + '. ' + ( insightisticPro.i18n.emailSaveFirst || 'Save first to use the new values.' );
				if ( ! window.confirm( warn ) ) { return; }
			}

			$btn.prop( 'disabled', true ).text( insightisticPro.i18n.sending || 'Sending...' );
			$notice.html( '' );

			$.ajax( {
				url: insightisticPro.ajaxUrl, method: 'POST',
				data: { action: 'insightistic_send_test_email_automation', nonce: insightisticPro.nonce },
				success: function ( res ) {
					if ( res.success ) {
						$notice.html( '<div class="isp-notice isp-notice-success">' + escHtml( res.data || ( insightisticPro.i18n.testSent || 'Test digest sent.' ) ) + '</div>' );
					} else {
						$notice.html( errorNoticeMarkup( res.data, insightisticPro.i18n.error ).html );
					}
				},
				error: function () { $notice.html( '<div class="isp-notice isp-notice-error">' + escHtml( insightisticPro.i18n.error ) + '</div>' ); },
				complete: function () { $btn.prop( 'disabled', false ).text( orig ); }
			} );
		} );

		// Preview Digest  loads a rendered HTML preview into a modal so
		// users can verify content before sending.
		$( '#isp-preview-email-automation' ).on( 'click', function () {
			var $btn = $( this );
			var orig = $btn.text();
			$btn.prop( 'disabled', true ).text( insightisticPro.i18n.loading || 'Loading...' );
			$.ajax( {
				url: insightisticPro.ajaxUrl, method: 'POST',
				data: { action: 'insightistic_preview_email_digest', nonce: insightisticPro.nonce },
				success: function ( res ) {
					if ( res.success ) {
						openModal(
							insightisticPro.i18n.emailPreview || 'Email Digest Preview',
							'<iframe class="isp-modal-iframe" srcdoc="' + escHtml( res.data.html ) + '"></iframe>'
						);
					} else {
						openModal(
							insightisticPro.i18n.emailPreview || 'Email Digest Preview',
							'<div class="isp-notice isp-notice-error">' + escHtml( res.data || insightisticPro.i18n.error ) + '</div>'
						);
					}
				},
				error: function () {
					openModal(
						insightisticPro.i18n.emailPreview || 'Email Digest Preview',
						'<div class="isp-notice isp-notice-error">' + escHtml( insightisticPro.i18n.error ) + '</div>'
					);
				},
				complete: function () { $btn.prop( 'disabled', false ).text( orig ); }
			} );
		} );

		$( document ).on( 'click', '.isp-load-addon-report', function () {
			var $btn = $( this );
			var slug = $btn.data( 'addon-report' );
			var $target = $( '#isp-addon-report-' + slug );
			var orig = $btn.text();

			$btn.prop( 'disabled', true ).text( insightisticPro.i18n.loading || 'Loading...' );
			$target.html( '<div class="isp-notice isp-notice-info">' + escHtml( insightisticPro.i18n.loadingReport || 'Loading addon report...' ) + '</div>' );

			$.ajax( {
				url: insightisticPro.ajaxUrl,
				method: 'POST',
				data: {
					action: 'insightistic_get_addon_report',
					slug: slug,
					nonce: insightisticPro.nonce
				},
				success: function ( res ) {
					if ( res.success && res.data && res.data.html ) {
						$target.html( res.data.html );
					} else {
						$target.html( errorNoticeMarkup( res.data, insightisticPro.i18n.error ).html );
					}
				},
				error: function () {
					$target.html( '<div class="isp-notice isp-notice-error">' + escHtml( insightisticPro.i18n.error ) + '</div>' );
				},
				complete: function () {
					$btn.prop( 'disabled', false ).text( orig );
				}
			} );
		} );
	}

	/* ================================================================== */
	/* MODAL                                                               */
	/* ================================================================== */

	function openModal( title, bodyHtml ) {
		closeModal();
		var $overlay = $( '<div class="isp-modal-overlay" role="dialog" aria-modal="true"></div>' );
		var $modal = $( '<div class="isp-modal"></div>' );
		$modal.append( '<div class="isp-modal-header"><h3>' + escHtml( title ) + '</h3><button type="button" class="isp-modal-close" aria-label="Close">&times;</button></div>' );
		$modal.append( '<div class="isp-modal-body">' + bodyHtml + '</div>' );
		$overlay.append( $modal );
		$( 'body' ).append( $overlay );
		$( document ).on( 'keydown.ispmodal', function ( e ) { if ( e.key === 'Escape' ) closeModal(); } );
		$overlay.on( 'click', function ( e ) { if ( e.target === $overlay[ 0 ] ) closeModal(); } );
		$overlay.find( '.isp-modal-close' ).on( 'click', closeModal );
	}

	function closeModal() {
		$( '.isp-modal-overlay' ).remove();
		$( document ).off( 'keydown.ispmodal' );
	}

	/* ================================================================== */
	/* LICENSE PAGE                                                        */
	/* ================================================================== */

	function licenseMsg( type, text ) {
		$( '#isp-license-msg' )
			.attr( 'class', 'isp-license-msg isp-license-msg-' + type )
			.text( text )
			.show();
	}

	function licenseResponseMessage( res ) {
		return ajaxPayloadMessage( res && res.data ) || ajaxPayloadMessage( res ) || insightisticPro.i18n.error;
	}

	function initLicensePage() {
		$( '#isp-license-activate' ).on( 'click', function () {
			var $btn = $( this );
			var key  = $.trim( $( '#isp-license-key' ).val() || '' );

			if ( ! key ) {
				licenseMsg( 'error', 'Please paste your account key first.' );
				return;
			}

			$btn.prop( 'disabled', true ).addClass( 'isp-btn-busy' );
			licenseMsg( 'info', 'Connecting to Insightistic…' );

			$.ajax( {
				url: insightisticPro.ajaxUrl,
				method: 'POST',
				data: { action: 'insightistic_license_activate', license_key: key, nonce: insightisticPro.nonce },
				success: function ( res ) {
					if ( res.success ) {
						licenseMsg( 'success', res.data.message );
						setTimeout( function () { window.location.reload(); }, 900 );
					} else {
						var msg = licenseResponseMessage( res );
						licenseMsg( 'error', msg );
						$btn.prop( 'disabled', false ).removeClass( 'isp-btn-busy' );
					}
				},
				error: function ( jqXHR, textStatus ) {
					licenseMsg( 'error', diagnoseAjaxError( jqXHR, textStatus ) );
					$btn.prop( 'disabled', false ).removeClass( 'isp-btn-busy' );
				}
			} );
		} );

		// Enter key in the input activates too.
		$( '#isp-license-key' ).on( 'keydown', function ( e ) {
			if ( e.key === 'Enter' ) {
				e.preventDefault();
				$( '#isp-license-activate' ).trigger( 'click' );
			}
		} );

		$( '#isp-license-refresh' ).on( 'click', function () {
			var $btn = $( this );
			$btn.prop( 'disabled', true ).addClass( 'isp-btn-busy' );
			licenseMsg( 'info', 'Checking your connection…' );

			$.ajax( {
				url: insightisticPro.ajaxUrl,
				method: 'POST',
				data: { action: 'insightistic_license_refresh', nonce: insightisticPro.nonce },
				success: function ( res ) {
					if ( res.success ) {
						licenseMsg( 'success', res.data.message );
						setTimeout( function () { window.location.reload(); }, 900 );
					} else {
						var msg = licenseResponseMessage( res );
						licenseMsg( 'error', msg );
						$btn.prop( 'disabled', false ).removeClass( 'isp-btn-busy' );
					}
				},
				error: function ( jqXHR, textStatus ) {
					licenseMsg( 'error', diagnoseAjaxError( jqXHR, textStatus ) );
					$btn.prop( 'disabled', false ).removeClass( 'isp-btn-busy' );
				}
			} );
		} );

		$( '#isp-sync-now' ).on( 'click', function () {
			var $btn = $( this );
			$btn.prop( 'disabled', true ).addClass( 'isp-btn-busy' );
			licenseMsg( 'info', 'Starting sync…' );

			$.ajax( {
				url: insightisticPro.ajaxUrl,
				method: 'POST',
				data: { action: 'insightistic_sync_now', nonce: insightisticPro.nonce },
				success: function ( res ) {
					if ( res.success ) {
						licenseMsg( 'success', res.data.message );
					} else {
						var msg = licenseResponseMessage( res );
						licenseMsg( 'error', msg );
					}
				},
				error: function ( jqXHR, textStatus ) { licenseMsg( 'error', diagnoseAjaxError( jqXHR, textStatus ) ); },
				complete: function () { $btn.prop( 'disabled', false ).removeClass( 'isp-btn-busy' ); }
			} );
		} );

		$( '#isp-license-disconnect' ).on( 'click', function () {
			if ( ! window.confirm( 'Disconnect this site from Insightistic? Advanced features will pause; your analytics settings stay untouched.' ) ) {
				return;
			}
			$( this ).prop( 'disabled', true );

			$.ajax( {
				url: insightisticPro.ajaxUrl,
				method: 'POST',
				data: { action: 'insightistic_license_disconnect', nonce: insightisticPro.nonce },
				complete: function () { window.location.reload(); }
			} );
		} );
	}

	/* ================================================================== */
	/* SPEED TEST PAGE                                                     */
	/* ================================================================== */

	function scoreColor( score ) {
		if ( score === null || score === undefined ) { return '#94a3b8'; }
		if ( score >= 90 ) { return '#16a34a'; }
		if ( score >= 50 ) { return '#d97706'; }
		return '#dc2626';
	}

	/**
	 * Animated SVG ring gauge: the ring fills via a stroke-dashoffset CSS
	 * transition, the number counts up with requestAnimationFrame.
	 */
	function gaugeHtml( score, label, size ) {
		size = size || 96;
		var r   = ( size / 2 ) - 7;
		var c   = 2 * Math.PI * r;
		var val = ( score === null || score === undefined ) ? null : Math.max( 0, Math.min( 100, score ) );
		var col = scoreColor( val );
		var off = val === null ? c : c - ( c * val / 100 );
		var gid = 'ispGrad' + ( gaugeHtml._i = ( gaugeHtml._i || 0 ) + 1 );

		return '' +
			'<div class="isp-gauge" style="width:' + size + 'px">' +
				'<svg viewBox="0 0 ' + size + ' ' + size + '" width="' + size + '" height="' + size + '">' +
					'<defs><linearGradient id="' + gid + '" x1="0%" y1="100%" x2="100%" y2="0%">' +
						'<stop offset="0%" stop-color="#dc2626"/><stop offset="50%" stop-color="#f59e0b"/><stop offset="100%" stop-color="#16a34a"/>' +
					'</linearGradient></defs>' +
					'<circle class="isp-gauge-track" cx="' + ( size / 2 ) + '" cy="' + ( size / 2 ) + '" r="' + r + '"/>' +
					'<circle class="isp-gauge-fill" cx="' + ( size / 2 ) + '" cy="' + ( size / 2 ) + '" r="' + r + '"' +
						' stroke="url(#' + gid + ')" stroke-dasharray="' + c.toFixed( 1 ) + '"' +
						' stroke-dashoffset="' + c.toFixed( 1 ) + '" data-target-offset="' + off.toFixed( 1 ) + '"/>' +
				'</svg>' +
				'<div class="isp-gauge-value" data-count-to="' + ( val === null ? '' : val ) + '" style="color:' + col + '">' + ( val === null ? '–' : '0' ) + '</div>' +
				'<div class="isp-gauge-label">' + escHtml( label ) + '</div>' +
			'</div>';
	}

	function cwvItemHtml( key, metric ) {
		var names = { lcp: 'LCP', inp: 'INP', cls: 'CLS', fcp: 'FCP', tbt: 'TBT', si: 'Speed Index', ttfb: 'TTFB' };
		return '' +
			'<div class="isp-cwv-item isp-cwv-' + escHtml( metric.status ) + '">' +
				'<span class="isp-cwv-name" title="' + escHtml( metric.label || '' ) + '">' + escHtml( names[ key ] || key ) + '</span>' +
				'<span class="isp-cwv-val">' + escHtml( metric.display || 'N/A' ) + '</span>' +
			'</div>';
	}

	function renderStrategy( report, deviceLabel ) {
		if ( ! report ) {
			return '<div class="isp-notice isp-notice-error">No data for this device.</div>';
		}

		var html        = '';
		var s           = report.scores || {};
		var ai          = report.ai_readiness || null;
		var device      = deviceLabel || 'Mobile';
		var scoreTone   = function ( value ) {
			if ( value === null || value === undefined ) { return ''; }
			return Number( value ) >= 90 ? ' is-score-good' : ( Number( value ) >= 50 ? ' is-score-moderate' : ' is-score-poor' );
		};
		var summary     = [
			[ 'Performance', s.performance ],
			[ 'Accessibility', s.accessibility ],
			[ 'Best practices', s.best_practices ],
			[ 'SEO', s.seo ],
			[ 'AI readiness', ai ? ai.score : null ]
		];

		html += '<div class="isp-psi-summary-grid isp-animate-in">';
		$.each( summary, function ( _, item ) {
			html += '<div class="isp-psi-summary-card' + scoreTone( item[1] ) + '">' +
				'<strong>' + ( item[1] === null || item[1] === undefined ? '—' : escHtml( item[1] ) ) + '</strong>' +
				'<span>' + escHtml( item[0] ) + '</span></div>';
		} );
		html += '</div>';

		// Match the dashboard PageSpeed visual language: a device score hero
		// below the category summary, followed by a compact legend.
		var performanceScore = ( s.performance === null || s.performance === undefined ) ? null : Number( s.performance );
		var performanceBand   = performanceScore === null || isNaN( performanceScore ) ? 'neutral' : ( performanceScore >= 90 ? 'good' : ( performanceScore >= 50 ? 'moderate' : 'poor' ) );
		var performanceLabel  = 'good' === performanceBand ? 'Good' : ( 'moderate' === performanceBand ? 'Needs Improvement' : ( 'poor' === performanceBand ? 'Poor' : 'Unavailable' ) );
		var performanceValue  = performanceScore === null || isNaN( performanceScore ) ? null : Math.max( 0, Math.min( 100, performanceScore ) );
		var performanceOffset  = performanceValue === null ? 339.3 : 339.3 - ( 339.3 * performanceValue / 100 );
		var performanceGradient = 'ispSpeedTestGrad' + ( renderStrategy._i = ( renderStrategy._i || 0 ) + 1 );
		html += '<div class="isp-speed-scores isp-animate-in">' +
			'<div class="isp-speed-score-card"><div class="isp-speed-label">' + escHtml( device ) + '</div>' +
				'<div class="isp-ring-container isp-score-' + performanceBand + '">' +
					'<svg class="isp-score-ring" viewBox="0 0 120 120" aria-hidden="true">' +
						'<defs><linearGradient id="' + performanceGradient + '" x1="0%" y1="100%" x2="100%" y2="0%"><stop offset="0%" stop-color="#dc2626"/><stop offset="50%" stop-color="#f59e0b"/><stop offset="100%" stop-color="#16a34a"/></linearGradient></defs>' +
						'<circle class="isp-ring-track" cx="60" cy="60" r="54"/>' +
						'<circle class="isp-ring-progress" cx="60" cy="60" r="54" stroke="url(#' + performanceGradient + ')" style="stroke-dashoffset:' + performanceOffset.toFixed( 1 ) + '"/>' +
					'</svg>' +
					'<div class="isp-ring-score">' + ( performanceValue === null ? '—' : escHtml( Math.round( performanceValue ) ) ) + '</div>' +
				'</div>' +
				'<div class="isp-speed-score-label isp-lbl-' + performanceBand + '">' + escHtml( performanceLabel ) + '</div></div>' +
			'<div class="isp-speed-legend">' +
				'<div class="isp-speed-legend-item"><span class="isp-speed-dot isp-speed-good"></span>90–100: Good</div>' +
				'<div class="isp-speed-legend-item"><span class="isp-speed-dot isp-speed-moderate"></span>50–89: Needs Improvement</div>' +
				'<div class="isp-speed-legend-item"><span class="isp-speed-dot isp-speed-poor"></span>0–49: Poor</div>' +
			'</div>' +
			'</div>';

		// Core Web Vitals & lab metrics.
		var cwv = report.cwv || {};
		html += '<div class="isp-cwv-section isp-animate-in"><div class="isp-section-header">' +
			'<h2 class="isp-section-title">Core Web Vitals</h2></div>' +
			'<div class="isp-cwv-grid isp-speedtest-cwv">' + buildCWVGrid( cwv ) + '</div></div>';

		// Opportunities and diagnostics use the same compact report lists as the
		// dashboard PageSpeed tab. Keep each result in one place so the dedicated
		// Speed Test page does not repeat the opportunities section.
		var opps = report.opportunities || [];
		var diags = report.diagnostics || [];
		var compactList = function ( items, type ) {
			if ( ! items.length ) return '<p class="isp-no-data">' + escHtml( insightisticPro.i18n.noData ) + '</p>';
			var listHtml = '<ul class="isp-psi-detail-list">';
			$.each( items.slice( 0, 6 ), function ( _, item ) {
				listHtml += '<li><strong>' + escHtml( item.title || '' ) + '</strong>' +
					( 'opportunity' === type && item.savings_ms ? '<span>' + escHtml( item.savings_ms + ' ms potential saving' ) + '</span>' : '' ) +
					( 'diagnostic' === type && item.display ? '<span>' + escHtml( item.display ) + '</span>' : '' ) + '</li>';
			} );
			return listHtml + '</ul>';
		};
		html += '<div class="isp-psi-details-grid isp-animate-in">' +
			'<div class="isp-psi-detail-card"><h3>Priority opportunities</h3>' + compactList( opps, 'opportunity' ) + '</div>' +
			'<div class="isp-psi-detail-card"><h3>Diagnostics to review</h3>' + compactList( diags, 'diagnostic' ) + '</div>' +
			'</div>';

		return html;
	}

	function animateGauges( $scope ) {
		// Fill the rings.
		$scope.find( '.isp-gauge-fill' ).each( function () {
			var $ring = $( this );
			window.setTimeout( function () {
				$ring.css( 'stroke-dashoffset', $ring.data( 'target-offset' ) );
			}, 80 );
		} );
		// Count the numbers up (ease-in-out).
		$scope.find( '.isp-gauge-value' ).each( function () {
			var $el = $( this );
			var to  = parseInt( $el.data( 'count-to' ), 10 );
			if ( isNaN( to ) ) { return; }
			var start = null;
			function step( ts ) {
				if ( ! start ) { start = ts; }
				var p = Math.min( 1, ( ts - start ) / 900 );
				$el.text( Math.round( to * ( 0.5 - Math.cos( Math.PI * p ) / 2 ) ) );
				if ( p < 1 ) { window.requestAnimationFrame( step ); }
			}
			window.requestAnimationFrame( step );
		} );
	}

	function renderSpeedTest( data ) {
		var $out = $( '#isp-speedtest-results' );
		var tabs = '' +
			'<div class="isp-cwv-tabs isp-speedtest-tabs">' +
				'<button type="button" class="isp-cwv-tab isp-cwv-tab-active" data-strategy="mobile">📱 Mobile</button>' +
				'<button type="button" class="isp-cwv-tab" data-strategy="desktop">🖥 Desktop</button>' +
				'<span class="isp-cache-badge isp-speedtest-when">' + escHtml( data.url ) + '</span>' +
			'</div>';

		$out.html(
			tabs +
			'<div class="isp-speedtest-strategy" data-strategy-panel="mobile">' + renderStrategy( data.mobile, 'Mobile' ) + '</div>' +
			'<div class="isp-speedtest-strategy" data-strategy-panel="desktop" style="display:none">' + renderStrategy( data.desktop, 'Desktop' ) + '</div>'
		).show();
		$( '#isp-speedtest-empty' ).hide();

		animateGauges( $out.find( '[data-strategy-panel="mobile"]' ) );

		$out.find( '.isp-speedtest-tabs .isp-cwv-tab' ).on( 'click', function () {
			var strategy = $( this ).data( 'strategy' );
			$out.find( '.isp-cwv-tab' ).removeClass( 'isp-cwv-tab-active' );
			$( this ).addClass( 'isp-cwv-tab-active' );
			$out.find( '[data-strategy-panel]' ).hide();
			var $panel = $out.find( '[data-strategy-panel="' + strategy + '"]' ).show();
			animateGauges( $panel );
		} );
	}

	function initSpeedTest() {
		function run( force ) {
			var url  = $.trim( $( '#isp-speedtest-url' ).val() || '' ) || insightisticPro.homeUrl;
			var $btn = $( '#isp-speedtest-run' );
			var $st  = $( '#isp-speedtest-status' );

			$btn.prop( 'disabled', true ).addClass( 'isp-btn-busy' );
			$( '#isp-speedtest-force' ).prop( 'disabled', true );
			$st.attr( 'class', 'isp-speedtest-status isp-speedtest-status-info' )
				.html( '<span class="isp-spinner" aria-hidden="true"></span> Running Lighthouse for mobile and desktop — this usually takes 15–30 seconds…' )
				.show();

			$.ajax( {
				url: insightisticPro.ajaxUrl,
				method: 'POST',
				timeout: 180000,
				data: { action: 'insightistic_speed_test', page_url: url, force: force ? 1 : 0, nonce: insightisticPro.nonce },
				success: function ( res ) {
					if ( res.success && res.data ) {
						$st.hide();
						renderSpeedTest( res.data );
					} else {
						$st.attr( 'class', 'isp-speedtest-status isp-speedtest-status-error' )
							.text( ( res.data && res.data.message ) || res.data || insightisticPro.i18n.error );
					}
				},
				error: function () {
					$st.attr( 'class', 'isp-speedtest-status isp-speedtest-status-error' )
						.text( insightisticPro.i18n.error );
				},
				complete: function () {
					$btn.prop( 'disabled', false ).removeClass( 'isp-btn-busy' );
					$( '#isp-speedtest-force' ).prop( 'disabled', false );
				}
			} );
		}

		$( '#isp-speedtest-run' ).on( 'click', function () { run( false ); } );
		$( '#isp-speedtest-force' ).on( 'click', function () { run( true ); } );
		$( '#isp-speedtest-url' ).on( 'keydown', function ( e ) {
			if ( e.key === 'Enter' ) { e.preventDefault(); run( false ); }
		} );
	}

	/* ================================================================== */
	/* INIT                                                                */
	/* ================================================================== */

	$( function () {

		// Dashboard page.
		if ( $( '#isp-load-data' ).length ) {
			initDashTabs();
			initCwvTabs();
			initAIToolbar();

			// Auto-load GA4 data on first paint.
			loadData( true, false );
			init404Monitor();

			$( '#isp-load-data' ).on( 'click', function () { loadData( false, false ); } );
			$( '#isp-ai-analyze' ).on( 'click', runAI );
			$( '#isp-gsc-ai-analyze' ).on( 'click', runGSCAI );
			$( '#isp-psi-ai-analyze' ).on( 'click', runPageSpeedAI );
			$( '#isp-load-gsc' ).on( 'click', function () { loadGSC( false ); } );
			$( '#isp-run-pagespeed' ).on( 'click', function () { runPageSpeed( false ); } );
			$( '#isp-load-woo' ).on( 'click', function () { loadWoo( false ); } );
			$( '#isp-woo-ai-analyze' ).on( 'click', runWooAI );
			$( '#isp-load-cf' ).on( 'click', function () { loadCloudflare( false ); } );
			$( '#isp-cf-ai-analyze' ).on( 'click', runCfAI );

			$( '#isp-psi-url' ).on( 'keypress', function ( e ) {
				if ( e.which === 13 ) { runPageSpeed( false ); }
			} );
		}

		// Settings page.
		if ( $( '.isp-settings-wrap' ).length ) {
			initSettingsTabs();
			initProviderToggle();
			initJsonImporter();
			initTestConnection();
		}

		// Addons page.
		if ( $( '.isp-addons-wrap' ).length ) {
			initAddonsFilter();
			initAddonToggles();
		}

		// License page.
		if ( $( '.isp-license-wrap' ).length ) {
			initLicensePage();
		}

		// Speed Test page.
		if ( $( '.isp-speedtest-wrap' ).length ) {
			initSpeedTest();
		}

	} );

	// Resize charts.
	var resizeTimer;
	$( window ).on( 'resize', function () {
		clearTimeout( resizeTimer );
		resizeTimer = setTimeout( function () {
			if ( timelineChart ) { timelineChart.resize(); }
			if ( sourcesChart )  { sourcesChart.resize(); }
			if ( cfTimelineChart ) { cfTimelineChart.resize(); }
			if ( cfStatusChart )   { cfStatusChart.resize(); }
		}, 200 );
	} );

} )( jQuery );
