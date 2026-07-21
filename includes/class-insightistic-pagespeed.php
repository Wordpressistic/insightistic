<?php
/**
 * PageSpeed Insights API class.
 *
 * @package Insightistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Insightistic_PageSpeed
 * Handles Google PageSpeed Insights API requests.
 */
class Insightistic_PageSpeed {

	/** PageSpeed API endpoint. */
	const API_ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

	/**
	 * Register AJAX hooks.
	 */
	public function init() {
		add_action( 'wp_ajax_insightistic_get_pagespeed', array( $this, 'ajax_get_pagespeed' ) );
		add_action( 'wp_ajax_insightistic_speed_test', array( $this, 'ajax_speed_test' ) );
	}

	/* ------------------------------------------------------------------ */
	/* AJAX Handler                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Run PageSpeed analysis for a given URL.
	 */
	public function ajax_get_pagespeed() {
		check_ajax_referer( 'insightistic_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'insightistic' ) );
		}

		$enc_key = get_option( 'insightistic_pagespeed_api_key_enc' );
		if ( ! $enc_key ) {
			wp_send_json_error( __( 'PageSpeed API key is not configured. Please visit Settings.', 'insightistic' ) );
		}
		$api_key = Insightistic_Encryption::decrypt( $enc_key );
		if ( ! $api_key ) {
			wp_send_json_error( __( 'Failed to read the PageSpeed API key. Please re-save it in Settings.', 'insightistic' ) );
		}

		$url = esc_url_raw( wp_unslash( $_POST['page_url'] ?? '' ) );
		if ( ! $url ) {
			$url = home_url( '/' );
		}

		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			wp_send_json_error( __( 'Invalid URL provided.', 'insightistic' ) );
		}

		// Cache for 1 hour (bypassed when the user clicks Force refresh).
		$cache_key = 'insightistic_psi_' . md5( $url );
		$force     = ! empty( $_POST['force'] );
		if ( ! $force ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				wp_send_json_success( $cached );
			}
		}

		// Run mobile and desktop in parallel-ish sequence.
		$mobile_raw  = $this->fetch_report( $url, 'mobile', $api_key );
		$desktop_raw = $this->fetch_report( $url, 'desktop', $api_key );

		if ( is_wp_error( $mobile_raw ) && is_wp_error( $desktop_raw ) ) {
			wp_send_json_error( $mobile_raw->get_error_message() );
		}

		$result = array(
			'url'       => $url,
			'mobile'    => ! is_wp_error( $mobile_raw ) ? $this->parse_report( $mobile_raw ) : null,
			'desktop'   => ! is_wp_error( $desktop_raw ) ? $this->parse_report( $desktop_raw ) : null,
			'cached_at' => time(),
		);

		set_transient( $cache_key, $result, HOUR_IN_SECONDS );
		wp_send_json_success( $result );
	}

	/**
	 * Full Speed Test: all four Lighthouse categories, Core Web Vitals,
	 * opportunities, diagnostics and the AI Agent Readiness score — for both
	 * mobile and desktop. Powers the dedicated Speed Test page.
	 */
	public function ajax_speed_test() {
		check_ajax_referer( 'insightistic_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'insightistic' ) );
		}

		$enc_key = get_option( 'insightistic_pagespeed_api_key_enc' );
		if ( ! $enc_key ) {
			wp_send_json_error( __( 'PageSpeed API key is not configured. Please visit Settings.', 'insightistic' ) );
		}
		$api_key = Insightistic_Encryption::decrypt( $enc_key );
		if ( ! $api_key ) {
			wp_send_json_error( __( 'Failed to read the PageSpeed API key. Please re-save it in Settings.', 'insightistic' ) );
		}

		$url = esc_url_raw( wp_unslash( $_POST['page_url'] ?? '' ) );
		if ( ! $url ) {
			$url = home_url( '/' );
		}
		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			wp_send_json_error( __( 'Invalid URL provided.', 'insightistic' ) );
		}

		$cache_key = 'insightistic_speedtest_' . md5( $url );
		$force     = ! empty( $_POST['force'] );
		if ( ! $force ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				wp_send_json_success( $cached );
			}
		}

		$mobile_raw  = $this->fetch_report( $url, 'mobile', $api_key, true );
		$desktop_raw = $this->fetch_report( $url, 'desktop', $api_key, true );

		if ( is_wp_error( $mobile_raw ) && is_wp_error( $desktop_raw ) ) {
			wp_send_json_error( $mobile_raw->get_error_message() );
		}

		$result = array(
			'url'       => $url,
			'mobile'    => ! is_wp_error( $mobile_raw ) ? $this->parse_detailed_report( $mobile_raw ) : null,
			'desktop'   => ! is_wp_error( $desktop_raw ) ? $this->parse_detailed_report( $desktop_raw ) : null,
			'cached_at' => time(),
		);

		set_transient( $cache_key, $result, HOUR_IN_SECONDS );
		wp_send_json_success( $result );
	}

	/**
	 * Build one connector v2 sync/performance/runs payload (mobile strategy —
	 * the schema's site-wide "how healthy is the store" number). Reuses the
	 * exact same fetch/parse path as the Speed Test page, not a re-
	 * implementation, so this can never drift from what the admin sees.
	 * Returns null when no API key is configured, and false (not WP_Error)
	 * on a fetch failure — this runs unattended on a schedule, so a single
	 * bad PSI response should skip the sync quietly, matching this file's
	 * own "one failed report must not abort the whole thing" rule.
	 *
	 * @param string $url URL to test; defaults to the site's home URL.
	 * @return array|null|false
	 */
	public function get_sync_payload( $url = null ) {
		$enc_key = get_option( 'insightistic_pagespeed_api_key_enc' );
		if ( ! $enc_key ) {
			return null;
		}
		$api_key = Insightistic_Encryption::decrypt( $enc_key );
		if ( ! $api_key ) {
			return null;
		}

		$url = $url ?: home_url( '/' );
		$raw = $this->fetch_report( $url, 'mobile', $api_key, true );
		if ( is_wp_error( $raw ) ) {
			return false;
		}

		$report = $this->parse_detailed_report( $raw );
		$status = static function ( $metric ) {
			$map = array( 'good' => 'good', 'moderate' => 'needs_improvement', 'poor' => 'poor' );
			return $map[ $metric['status'] ] ?? null;
		};

		$issues = array();
		foreach ( $report['opportunities'] as $op ) {
			$issues[] = array(
				'audit_key'     => sanitize_key( $op['title'] ),
				'category'      => 'performance',
				'title'         => $op['title'],
				'severity'      => $op['savings_ms'] >= 1000 ? 'high' : ( $op['savings_ms'] >= 300 ? 'medium' : 'low' ),
				'savings_ms'    => $op['savings_ms'],
				'description'   => $op['desc'],
			);
		}
		foreach ( $report['diagnostics'] as $d ) {
			$issues[] = array(
				'audit_key'     => sanitize_key( $d['title'] ),
				'category'      => 'best-practices',
				'title'         => $d['title'],
				'severity'      => 'low',
				'display_value' => $d['display'],
			);
		}

		return array(
			'url'                   => $url,
			'strategy'              => 'mobile',
			'run_at'                => current_time( 'c' ),
			'lighthouse_version'    => $report['lh_version'] ?: null,
			'fetch_time'            => $report['fetched'] ?: null,
			'performance_score'     => $report['scores']['performance'],
			'accessibility_score'   => $report['scores']['accessibility'],
			'best_practices_score'  => $report['scores']['best_practices'],
			'seo_score'             => $report['scores']['seo'],
			'ai_readiness_score'    => $report['ai_readiness']['score'],
			'lcp_ms'                => $report['cwv']['lcp']['value'],
			'lcp_status'            => $status( $report['cwv']['lcp'] ),
			'inp_ms'                => $report['cwv']['inp']['value'],
			'inp_status'            => $status( $report['cwv']['inp'] ),
			'cls'                   => $report['cwv']['cls']['value'],
			'cls_status'            => $status( $report['cwv']['cls'] ),
			'fcp_ms'                => $report['cwv']['fcp']['value'],
			'tbt_ms'                => $report['cwv']['tbt']['value'],
			'speed_index_ms'        => $report['cwv']['si']['value'],
			'ttfb_ms'               => $report['cwv']['ttfb']['value'],
			'field_data_available'  => false,
			'lab_data_available'    => true,
			'status'                => 'complete',
			'issues'                => $issues,
		);
	}

	/* ------------------------------------------------------------------ */
	/* Core Methods                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Fetch a PageSpeed report for a URL and strategy.
	 *
	 * @param string $url      URL to test.
	 * @param string $strategy 'mobile' or 'desktop'.
	 * @param string $api_key  Google Cloud API key.
	 * @return array|WP_Error  Decoded response or WP_Error.
	 */
	private function fetch_report( $url, $strategy, $api_key, $all_categories = false ) {
		$endpoint = add_query_arg(
			array(
				'url'      => rawurlencode( $url ),
				'strategy' => $strategy,
				'key'      => $api_key,
			),
			self::API_ENDPOINT
		);

		// PSI accepts repeated category params; add_query_arg can't repeat a
		// key, so append them manually for the detailed report.
		$categories = $all_categories
			? array( 'performance', 'accessibility', 'best-practices', 'seo' )
			: array( 'performance' );
		foreach ( $categories as $category ) {
			$endpoint .= '&category=' . rawurlencode( $category );
		}

		$response = wp_remote_get(
			$endpoint,
			array( 'timeout' => 60 )
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( isset( $body['error'] ) ) {
			$msg = $body['error']['message'] ?? __( 'PageSpeed API error.', 'insightistic' );
			return new WP_Error( 'psi_error', $msg );
		}

		return $body;
	}

	/**
	 * Parse a raw PageSpeed API response into a structured array.
	 *
	 * @param array $raw Raw API response.
	 * @return array
	 */
	private function parse_report( $raw ) {
		$categories = $raw['lighthouseResult']['categories'] ?? array();
		$audits     = $raw['lighthouseResult']['audits'] ?? array();

		$score = isset( $categories['performance']['score'] )
			? intval( round( $categories['performance']['score'] * 100 ) )
			: 0;

		// Core Web Vitals.
		$cwv = array(
			'lcp' => $this->parse_metric( $audits['largest-contentful-paint'] ?? null, 2500, 4000 ),
			'inp' => $this->parse_metric( $audits['interaction-to-next-paint'] ?? ( $audits['total-blocking-time'] ?? null ), 200, 500 ),
			'cls' => $this->parse_metric( $audits['cumulative-layout-shift'] ?? null, 0.1, 0.25, false ),
			'fcp' => $this->parse_metric( $audits['first-contentful-paint'] ?? null, 1800, 3000 ),
			'tbt' => $this->parse_metric( $audits['total-blocking-time'] ?? null, 200, 600 ),
			'si'  => $this->parse_metric( $audits['speed-index'] ?? null, 3400, 5800 ),
		);

		return array(
			'score' => $score,
			'cwv'   => $cwv,
		);
	}

	/**
	 * Parse a single audit metric.
	 *
	 * @param array|null $audit     Audit data from Lighthouse.
	 * @param float      $good      Threshold for a "good" score.
	 * @param float      $moderate  Threshold for a "moderate" score.
	 * @param bool       $is_ms     Whether the value is in milliseconds.
	 * @return array
	 */
	private function parse_metric( $audit, $good, $moderate, $is_ms = true ) {
		if ( ! $audit ) {
			return array( 'display' => 'N/A', 'value' => null, 'status' => 'unknown', 'label' => '' );
		}

		$raw_value = $audit['numericValue'] ?? null;
		$display   = $audit['displayValue'] ?? 'N/A';
		$label     = $audit['title'] ?? '';

		$status = 'good';
		if ( null !== $raw_value ) {
			if ( $raw_value > $moderate ) {
				$status = 'poor';
			} elseif ( $raw_value > $good ) {
				$status = 'moderate';
			}
		}

		return array(
			'display' => $display,
			'value'   => $raw_value,
			'status'  => $status,
			'label'   => $label,
		);
	}

	/* ------------------------------------------------------------------ */
	/* Detailed Speed Test parsing                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Parse a full 4-category Lighthouse response: category scores, CWV,
	 * opportunities, diagnostics and the AI Agent Readiness breakdown.
	 *
	 * @param array $raw Raw API response.
	 * @return array
	 */
	private function parse_detailed_report( $raw ) {
		$lh         = $raw['lighthouseResult'] ?? array();
		$categories = $lh['categories'] ?? array();
		$audits     = $lh['audits'] ?? array();

		$score = static function ( $key ) use ( $categories ) {
			return isset( $categories[ $key ]['score'] ) && null !== $categories[ $key ]['score']
				? intval( round( $categories[ $key ]['score'] * 100 ) )
				: null;
		};

		$scores = array(
			'performance'    => $score( 'performance' ),
			'accessibility'  => $score( 'accessibility' ),
			'best_practices' => $score( 'best-practices' ),
			'seo'            => $score( 'seo' ),
		);

		// Core Web Vitals (same thresholds as the dashboard widget).
		$cwv = array(
			'lcp' => $this->parse_metric( $audits['largest-contentful-paint'] ?? null, 2500, 4000 ),
			'inp' => $this->parse_metric( $audits['interaction-to-next-paint'] ?? ( $audits['total-blocking-time'] ?? null ), 200, 500 ),
			'cls' => $this->parse_metric( $audits['cumulative-layout-shift'] ?? null, 0.1, 0.25, false ),
			'fcp' => $this->parse_metric( $audits['first-contentful-paint'] ?? null, 1800, 3000 ),
			'tbt' => $this->parse_metric( $audits['total-blocking-time'] ?? null, 200, 600 ),
			'si'  => $this->parse_metric( $audits['speed-index'] ?? null, 3400, 5800 ),
			'ttfb' => $this->parse_metric( $audits['server-response-time'] ?? null, 800, 1800 ),
		);

		// Opportunities: audits with measurable savings, largest first.
		$opportunities = array();
		foreach ( $audits as $audit ) {
			$savings = $audit['details']['overallSavingsMs'] ?? null;
			if ( null === $savings || $savings < 50 ) {
				continue;
			}
			if ( isset( $audit['score'] ) && null !== $audit['score'] && $audit['score'] >= 0.9 ) {
				continue;
			}
			$opportunities[] = array(
				'title'      => sanitize_text_field( $audit['title'] ?? '' ),
				'desc'       => wp_strip_all_tags( $this->strip_md_links( $audit['description'] ?? '' ) ),
				'savings_ms' => (int) round( $savings ),
			);
		}
		usort(
			$opportunities,
			static function ( $a, $b ) {
				return $b['savings_ms'] <=> $a['savings_ms'];
			}
		);
		$opportunities = array_slice( $opportunities, 0, 8 );

		// Diagnostics: failed non-savings audits worth surfacing.
		$diagnostic_ids = array(
			'render-blocking-resources',
			'uses-long-cache-ttl',
			'dom-size',
			'font-display',
			'third-party-summary',
			'largest-contentful-paint-element',
			'layout-shift-elements',
			'unsized-images',
			'redirects',
		);
		$diagnostics    = array();
		foreach ( $diagnostic_ids as $id ) {
			$audit = $audits[ $id ] ?? null;
			if ( ! $audit || ! isset( $audit['score'] ) || null === $audit['score'] || $audit['score'] >= 0.9 ) {
				continue;
			}
			$diagnostics[] = array(
				'title'   => sanitize_text_field( $audit['title'] ?? '' ),
				'display' => sanitize_text_field( $audit['displayValue'] ?? '' ),
			);
		}
		$diagnostics = array_slice( $diagnostics, 0, 8 );

		return array(
			'scores'        => $scores,
			'cwv'           => $cwv,
			'opportunities' => $opportunities,
			'diagnostics'   => $diagnostics,
			'ai_readiness'  => $this->ai_readiness( $scores, $audits ),
			'lh_version'    => sanitize_text_field( $lh['lighthouseVersion'] ?? '' ),
			'fetched'       => sanitize_text_field( $lh['fetchTime'] ?? '' ),
		);
	}

	/**
	 * AI Agent Readiness score.
	 *
	 * AI assistants, crawlers and answer engines reward the same fundamentals
	 * a human visitor does — fast responses, crawlable markup, clear document
	 * semantics — plus a handful of machine-readability signals. We blend the
	 * Lighthouse category scores with those specific audits:
	 *
	 *   40% SEO  ·  25% Performance  ·  20% Accessibility  ·  15% Best Practices
	 *   then each failed machine-readability check subtracts up to 4 points.
	 *
	 * @param array $scores Category scores (0-100 or null).
	 * @param array $audits Lighthouse audits.
	 * @return array{score:int,grade:string,checks:array}
	 */
	private function ai_readiness( $scores, $audits ) {
		$weights = array(
			'seo'            => 0.40,
			'performance'    => 0.25,
			'accessibility'  => 0.20,
			'best_practices' => 0.15,
		);

		$weighted = 0.0;
		$total_w  = 0.0;
		foreach ( $weights as $key => $w ) {
			if ( null !== $scores[ $key ] ) {
				$weighted += $scores[ $key ] * $w;
				$total_w  += $w;
			}
		}
		$base = $total_w > 0 ? $weighted / $total_w : 0;

		// Machine-readability checklist (each pass keeps points on the table).
		$check_defs = array(
			'document-title'    => __( 'Page has a descriptive title', 'insightistic' ),
			'meta-description'  => __( 'Meta description present', 'insightistic' ),
			'http-status-code'  => __( 'Page returns a successful HTTP status', 'insightistic' ),
			'is-crawlable'      => __( 'Page is crawlable (not blocked)', 'insightistic' ),
			'crawlable-anchors' => __( 'Links are crawlable anchors', 'insightistic' ),
			'canonical'         => __( 'Canonical URL declared', 'insightistic' ),
			'robots-txt'        => __( 'robots.txt is valid', 'insightistic' ),
			'hreflang'          => __( 'hreflang is valid', 'insightistic' ),
			'viewport'          => __( 'Mobile viewport configured', 'insightistic' ),
			'image-alt'         => __( 'Images have alt text', 'insightistic' ),
		);

		$checks  = array();
		$penalty = 0;
		foreach ( $check_defs as $id => $label ) {
			$audit = $audits[ $id ] ?? null;
			if ( ! $audit || ! isset( $audit['score'] ) || null === $audit['score'] ) {
				continue; // Not applicable for this page.
			}
			$pass     = $audit['score'] >= 0.9;
			$checks[] = array(
				'id'    => $id,
				'label' => $label,
				'pass'  => $pass,
			);
			if ( ! $pass ) {
				$penalty += 4;
			}
		}

		$final = (int) max( 0, min( 100, round( $base - $penalty ) ) );

		if ( $final >= 90 ) {
			$grade = 'A';
		} elseif ( $final >= 75 ) {
			$grade = 'B';
		} elseif ( $final >= 60 ) {
			$grade = 'C';
		} elseif ( $final >= 40 ) {
			$grade = 'D';
		} else {
			$grade = 'E';
		}

		return array(
			'score'  => $final,
			'grade'  => $grade,
			'checks' => $checks,
		);
	}

	/**
	 * Lighthouse descriptions embed markdown links; keep the text only.
	 *
	 * @param string $text Description text.
	 * @return string
	 */
	private function strip_md_links( $text ) {
		return preg_replace( '/\[([^\]]+)\]\([^)]*\)/', '$1', (string) $text );
	}
}
