/* global insightisticPro, Chart */
'use strict';

( function ( $ ) {

	/* ------------------------------------------------------------------ */
	/* State                                                               */
	/* ------------------------------------------------------------------ */
	var timelineChart = null;
	var sourcesChart  = null;
	var currentData   = null;
	var lastAIHtml    = '';

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
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}

	function truncate( str, len ) {
		return str.length > len ? str.slice( 0, len ) + '' : str;
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
					dashboardError( ( res && res.data ) || insightisticPro.i18n.error );
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
		var model = escHtml( insightisticPro.aiModel || '' );
		var cost = escHtml( insightisticPro.aiCostHint || '' );
		return '<div class="isp-ai-panel"><div class="isp-ai-header">' +
			'<div class="isp-ai-title"><span class="isp-ai-icon"></span>' + escHtml( insightisticPro.i18n.analyzing ) +
			' <span class="isp-ai-badge">' + prov + ( model ? ' &middot; ' + model : '' ) + '</span></div>' +
			( cost ? '<div class="isp-ai-cost-hint" title="' + escHtml( insightisticPro.i18n.aiCostTitle || 'Estimated per-run cost' ) + '">' + cost + '</div>' : '' ) +
			'</div></div>';
	}

	function aiFooterToolbar() {
		return '<div class="isp-ai-toolbar">' +
			'<button type="button" class="isp-link-btn" id="isp-ai-copy">' + escHtml( insightisticPro.i18n.aiCopy || 'Copy insights' ) + '</button>' +
			'<button type="button" class="isp-link-btn" id="isp-ai-rerun">' + escHtml( insightisticPro.i18n.aiRerun || 'Re-run' ) + '</button>' +
			'<button type="button" class="isp-link-btn" id="isp-ai-save">' + escHtml( insightisticPro.i18n.aiSave || 'Save snapshot' ) + '</button>' +
			'<span class="isp-ai-toolbar-status" id="isp-ai-toolbar-status" aria-live="polite"></span>' +
			'</div>';
	}

	function runAI() {
		if ( ! currentData ) return;
		var $btn   = $( '#isp-ai-analyze' );
		var $panel = $( '#isp-ai-insights' );
		var orig   = $btn.html();
		$btn.prop( 'disabled', true ).html( '<span class="isp-inline-spinner"></span> ' + escHtml( insightisticPro.i18n.analyzing ) );
		$panel.html( aiHeaderHtml() ).show();
		$.ajax( {
			url: insightisticPro.ajaxUrl, method: 'POST',
			data: { action: 'insightistic_ai_analyze', data: JSON.stringify( currentData.structured_data ), days: $( '#isp-date-range' ).val() || '28', nonce: insightisticPro.nonce },
			success: function ( res ) {
				if ( res.success ) {
					lastAIHtml = res.data.html;
					$panel.html( res.data.html + aiFooterToolbar() );
					animateGauges( $panel );
				} else {
					renderAIError( $panel, res.data || insightisticPro.i18n.error );
				}
			},
			error: function () { renderAIError( $panel, insightisticPro.i18n.error ); },
			complete: function () { $btn.prop( 'disabled', false ).html( orig ); }
		} );
	}

	function renderAIError( $panel, msg ) {
		var errBox = '<div class="isp-ai-error"><div class="isp-notice isp-notice-error">' + escHtml( msg ) + '</div>' +
			'<button type="button" class="isp-btn isp-btn-secondary" id="isp-ai-retry">' + escHtml( insightisticPro.i18n.retry || 'Retry' ) + '</button></div>';
		if ( lastAIHtml ) {
			// Preserve the previous successful insight  do not blank the
			// panel on failure.
			$panel.html( lastAIHtml + errBox + aiFooterToolbar() );
			animateGauges( $panel );
		} else {
			$panel.html( aiHeaderHtml().replace( insightisticPro.i18n.analyzing, '' ) + errBox );
		}
	}

	function initAIToolbar() {
		// One delegated handler shared between the GA4 AI panel and the
		// Commerce AI panel  the source panel is detected from the click
		// origin so Re-run targets the right loader.
		var selector = '#isp-ai-insights, #isp-woo-ai-insights';
		$( document ).on( 'click', selector + ' #isp-ai-copy', function () {
			var $panel = $( this ).closest( '.isp-ai-container, #isp-ai-insights, #isp-woo-ai-insights' );
			var text = $panel.find( '.isp-ai-summary, .isp-ai-card, .isp-ai-rec' ).map( function () {
				return $( this ).text().trim();
			} ).get().join( '\n\n' );
			if ( ! text ) { return; }
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( text ).then( function () {
					$panel.find( '#isp-ai-toolbar-status' ).text( insightisticPro.i18n.aiCopied || 'Copied' );
					setTimeout( function () { $panel.find( '#isp-ai-toolbar-status' ).text( '' ); }, 1500 );
				} );
			}
		} );
		$( document ).on( 'click', selector + ' #isp-ai-rerun, ' + selector + ' #isp-ai-retry', function () {
			var inCommerce = $( this ).closest( '#isp-woo-ai-insights' ).length > 0;
			if ( inCommerce ) { runWooAI(); } else { runAI(); }
		} );
		$( document ).on( 'click', selector + ' #isp-ai-save', function () {
			var inCommerce = $( this ).closest( '#isp-woo-ai-insights' ).length > 0;
			var $panel = $( this ).closest( '#isp-ai-insights, #isp-woo-ai-insights' );
			var payload = inCommerce ? lastWooAIHtml : lastAIHtml;
			$.ajax( {
				url: insightisticPro.ajaxUrl, method: 'POST',
				data: { action: 'insightistic_save_ai_snapshot', html: payload, nonce: insightisticPro.nonce },
				success: function ( res ) {
					$panel.find( '#isp-ai-toolbar-status' ).text( res.success
						? ( insightisticPro.i18n.aiSaved || 'Saved' )
						: ( res.data || insightisticPro.i18n.error ) );
					setTimeout( function () { $panel.find( '#isp-ai-toolbar-status' ).text( '' ); }, 2000 );
				},
				error: function () { $panel.find( '#isp-ai-toolbar-status' ).text( insightisticPro.i18n.error ); }
			} );
		} );
	}

	/* ================================================================== */
	/* SEARCH CONSOLE TAB                                                  */
	/* ================================================================== */

	function loadGSC( force ) {
		var $btn  = $( '#isp-load-gsc' );
		var days  = $( '#isp-gsc-date-range' ).val() || '28';

		spinnerBtn( $btn, insightisticPro.i18n.loading );
		$( '#isp-gsc-cards,#isp-gsc-tables,#isp-gsc-devices' ).hide();
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
					dataLoaded.gsc = true;
					renderGSC( res.data );
					renderCacheBadge(
						$( '#isp-gsc-cache' ),
						res.data.cached_at,
						function () { loadGSC( true ); }
					);
				} else {
					$( '#isp-gsc-loading' ).empty().append(
						errorBox( ( res && res.data ) || insightisticPro.i18n.error, null, function () { loadGSC( true ); }, 'gsc' )
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
		$( '#isp-psi-results' ).hide();
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
					dataLoaded.psi = true;
					renderPageSpeed( res.data );
					renderCacheBadge(
						$( '#isp-psi-cache' ),
						res.data.cached_at,
						function () { runPageSpeed( true ); }
					);
				} else {
					$( '#isp-psi-loading' ).empty().append(
						errorBox( ( res && res.data ) || insightisticPro.i18n.error, null, function () { runPageSpeed( true ); }, 'pagespeed' )
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
		$( '#isp-psi-loading' ).hide();

		// Render both rings.
		renderScoreRing( 'mobile',  data.mobile  );
		renderScoreRing( 'desktop', data.desktop );

		// CWV grids.
		if ( data.mobile )  { $( '#isp-cwv-mobile' ).html( buildCWVGrid( data.mobile.cwv ) ); }
		if ( data.desktop ) { $( '#isp-cwv-desktop' ).html( buildCWVGrid( data.desktop.cwv ) ); }

		$( '#isp-psi-results' ).show();
	}

	function renderScoreRing( device, data ) {
		if ( ! data ) return;

		var score     = data.score || 0;
		var cls       = score >= 90 ? 'good' : ( score >= 50 ? 'moderate' : 'poor' );
		var label     = score >= 90 ? 'Good' : ( score >= 50 ? 'Needs Improvement' : 'Poor' );
		var ringColor = score >= 90 ? '#10b981' : ( score >= 50 ? '#f59e0b' : '#ef4444' );

		// Score text
		$( '#isp-' + device + '-score' )
			.text( score )
			.closest( '.isp-ring-container' )
			.attr( 'class', 'isp-ring-container isp-score-' + cls );

		// Animate the ring stroke.
		var circumference = 2 * Math.PI * 54; // 339.3
		var offset        = circumference - ( score / 100 ) * circumference;
		var $ring         = $( '#isp-' + device + '-ring' );
		$ring.attr( 'stroke', ringColor );
		$ring.css( 'stroke-dashoffset', circumference );
		setTimeout( function () {
			$ring.css( { 'stroke-dashoffset': offset, 'stroke': ringColor } );
		}, 80 );

		// Label below ring.
		$( '#isp-' + device + '-label' )
			.text( label )
			.attr( 'class', 'isp-speed-score-label isp-lbl-' + cls );
	}

	var cwvLabels = { lcp: 'LCP', inp: 'INP / TBT', cls: 'CLS', fcp: 'FCP', tbt: 'TBT', si: 'Speed Index' };

	function buildCWVGrid( cwv ) {
		if ( ! cwv ) return '<p style="color:#9ca3af;padding:16px;">' + escHtml( insightisticPro.i18n.noData ) + '</p>';
		var keys = [ 'lcp', 'inp', 'cls', 'fcp', 'tbt', 'si' ];
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
						errorBox( ( res && res.data ) || insightisticPro.i18n.error, null, function () { loadWoo( true ); }, 'ga4' )
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

		// Generic "Change key" / "Clear key" handlers for AI provider tiles.
		// Each AI provider key card uses data-provider for routing.
		$( document ).on( 'click', '.isp-change-ai-key', function () {
			var prov = $( this ).data( 'provider' );
			$( this ).closest( '.isp-key-stored' ).hide();
			$( '#ai_key_' + prov ).show().focus();
		} );
		$( document ).on( 'click', '.isp-clear-ai-key', function () {
			var prov = $( this ).data( 'provider' );
			if ( ! window.confirm( insightisticPro.i18n.confirmClearKey || 'Clear this saved key?' ) ) { return; }
			var $card = $( this ).closest( '.isp-settings-card' );
			var $result = $card.find( '.isp-ai-test-result' );
			$result.html( '' );
			$.ajax( {
				url: insightisticPro.ajaxUrl, method: 'POST',
				data: { action: 'insightistic_clear_ai_key', provider: prov, nonce: insightisticPro.nonce },
				success: function ( res ) {
					if ( res.success ) {
						$card.find( '.isp-key-stored' ).remove();
						$( '#ai_key_' + prov ).val( '' ).show().focus();
						$result.html( '<div class="isp-notice isp-notice-success">' + escHtml( res.data || 'Key cleared.' ) + '</div>' );
					} else {
						$result.html( '<div class="isp-notice isp-notice-error">' + escHtml( res.data || insightisticPro.i18n.error ) + '</div>' );
					}
				},
				error: function () { $result.html( '<div class="isp-notice isp-notice-error">' + escHtml( insightisticPro.i18n.error ) + '</div>' ); }
			} );
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

	function initLicensePage() {
		$( '#isp-license-activate' ).on( 'click', function () {
			var $btn = $( this );
			var key  = $.trim( $( '#isp-license-key' ).val() || '' );

			if ( ! key ) {
				licenseMsg( 'error', 'Please paste your license key first.' );
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
						var msg = ( res.data && res.data.message ) ? res.data.message : ( res.data || insightisticPro.i18n.error );
						licenseMsg( 'error', msg );
						if ( res.data && res.data.upgrade_url ) {
							$( '<a>', { href: res.data.upgrade_url, target: '_blank', rel: 'noopener', text: ' Upgrade →' } )
								.appendTo( '#isp-license-msg' );
						}
						$btn.prop( 'disabled', false ).removeClass( 'isp-btn-busy' );
					}
				},
				error: function () {
					licenseMsg( 'error', insightisticPro.i18n.error );
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
			licenseMsg( 'info', 'Checking your license…' );

			$.ajax( {
				url: insightisticPro.ajaxUrl,
				method: 'POST',
				data: { action: 'insightistic_license_refresh', nonce: insightisticPro.nonce },
				success: function ( res ) {
					if ( res.success ) {
						licenseMsg( 'success', res.data.message );
						setTimeout( function () { window.location.reload(); }, 900 );
					} else {
						var msg = ( res.data && res.data.message ) ? res.data.message : ( res.data || insightisticPro.i18n.error );
						licenseMsg( 'error', msg );
						$btn.prop( 'disabled', false ).removeClass( 'isp-btn-busy' );
					}
				},
				error: function () {
					licenseMsg( 'error', insightisticPro.i18n.error );
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
						var msg = ( res.data && res.data.message ) ? res.data.message : ( res.data || insightisticPro.i18n.error );
						licenseMsg( 'error', msg );
					}
				},
				error: function () { licenseMsg( 'error', insightisticPro.i18n.error ); },
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

		return '' +
			'<div class="isp-gauge" style="width:' + size + 'px">' +
				'<svg viewBox="0 0 ' + size + ' ' + size + '" width="' + size + '" height="' + size + '">' +
					'<circle class="isp-gauge-track" cx="' + ( size / 2 ) + '" cy="' + ( size / 2 ) + '" r="' + r + '"/>' +
					'<circle class="isp-gauge-fill" cx="' + ( size / 2 ) + '" cy="' + ( size / 2 ) + '" r="' + r + '"' +
						' stroke="' + col + '" stroke-dasharray="' + c.toFixed( 1 ) + '"' +
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

	function renderStrategy( report ) {
		if ( ! report ) {
			return '<div class="isp-notice isp-notice-error">No data for this device.</div>';
		}

		var html = '';
		var s = report.scores || {};

		// AI Agent Readiness hero.
		var ai = report.ai_readiness || null;
		if ( ai ) {
			var checks = '';
			( ai.checks || [] ).forEach( function ( c ) {
				checks += '<li class="' + ( c.pass ? 'isp-ai-check-pass' : 'isp-ai-check-fail' ) + '">' +
					'<span aria-hidden="true">' + ( c.pass ? '✓' : '✕' ) + '</span> ' + escHtml( c.label ) + '</li>';
			} );
			html += '' +
				'<div class="isp-airead-card isp-animate-in">' +
					'<div class="isp-airead-gauge">' + gaugeHtml( ai.score, 'AI Readiness', 132 ) +
						'<span class="isp-airead-grade isp-airead-grade-' + escHtml( ai.grade ) + '">' + escHtml( ai.grade ) + '</span>' +
					'</div>' +
					'<div class="isp-airead-body">' +
						'<h3>AI Agent Readiness</h3>' +
						'<p>How well this page can be read, understood and cited by AI assistants, crawlers and answer engines — blended from SEO, performance, accessibility and machine-readability checks.</p>' +
						'<ul class="isp-airead-checks">' + checks + '</ul>' +
					'</div>' +
				'</div>';
		}

		// Category gauges.
		html += '<div class="isp-speedtest-gauges isp-animate-in">' +
			gaugeHtml( s.performance, 'Performance' ) +
			gaugeHtml( s.seo, 'SEO' ) +
			gaugeHtml( s.accessibility, 'Accessibility' ) +
			gaugeHtml( s.best_practices, 'Best Practices' ) +
		'</div>';

		// Core Web Vitals & lab metrics.
		var cwv = report.cwv || {};
		var cwvHtml = '';
		$.each( [ 'lcp', 'inp', 'cls', 'fcp', 'tbt', 'si', 'ttfb' ], function ( _, key ) {
			if ( cwv[ key ] ) { cwvHtml += cwvItemHtml( key, cwv[ key ] ); }
		} );
		html += '<div class="isp-detail-card isp-animate-in"><div class="isp-detail-card-header">' +
			'<h3 class="isp-detail-card-title">Core Web Vitals &amp; lab metrics</h3></div>' +
			'<div class="isp-cwv-grid isp-speedtest-cwv">' + cwvHtml + '</div></div>';

		// Opportunities, biggest savings first.
		var opps = report.opportunities || [];
		if ( opps.length ) {
			var maxSave = Math.max.apply( null, opps.map( function ( o ) { return o.savings_ms; } ) );
			var oppHtml = '';
			opps.forEach( function ( o ) {
				var pct = maxSave ? Math.max( 6, Math.round( o.savings_ms / maxSave * 100 ) ) : 0;
				oppHtml += '' +
					'<div class="isp-opp">' +
						'<div class="isp-opp-top"><strong>' + escHtml( o.title ) + '</strong>' +
						'<span class="isp-opp-save">~' + ( o.savings_ms >= 1000 ? ( o.savings_ms / 1000 ).toFixed( 1 ) + ' s' : o.savings_ms + ' ms' ) + '</span></div>' +
						'<div class="isp-opp-bar"><span style="width:' + pct + '%"></span></div>' +
						( o.desc ? '<p class="isp-opp-desc">' + escHtml( o.desc ) + '</p>' : '' ) +
					'</div>';
			} );
			html += '<div class="isp-detail-card isp-animate-in"><div class="isp-detail-card-header">' +
				'<h3 class="isp-detail-card-title">Top opportunities</h3>' +
				'<p class="isp-detail-card-sub">Estimated loading-time savings, biggest first.</p></div>' +
				'<div class="isp-opps">' + oppHtml + '</div></div>';
		}

		// Diagnostics.
		var diags = report.diagnostics || [];
		if ( diags.length ) {
			var diagHtml = '';
			diags.forEach( function ( d ) {
				diagHtml += '<li><strong>' + escHtml( d.title ) + '</strong>' +
					( d.display ? ' <span>' + escHtml( d.display ) + '</span>' : '' ) + '</li>';
			} );
			html += '<div class="isp-detail-card isp-animate-in"><div class="isp-detail-card-header">' +
				'<h3 class="isp-detail-card-title">Diagnostics</h3></div>' +
				'<ul class="isp-diags">' + diagHtml + '</ul></div>';
		}

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
			'<div class="isp-speedtest-strategy" data-strategy-panel="mobile">' + renderStrategy( data.mobile ) + '</div>' +
			'<div class="isp-speedtest-strategy" data-strategy-panel="desktop" style="display:none">' + renderStrategy( data.desktop ) + '</div>'
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
