<?php
/**
 * AI Analysis class for Insightistic.
 * Sends analysis requests only to the Insightistic Cloud AI service
 * (api.insightistic.com), and only after the site owner opts in.
 *
 * @package Insightistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Insightistic_AI
 */
class Insightistic_AI {

	/**
	 * Per-request skill-profile override.
	 *
	 * When set, system_prompt() uses this instead of reading the stored
	 * option. Lets commerce analysis run with the commerce profile without
	 * mutating (and risking leaving behind) a persisted global option.
	 *
	 * @var string|null
	 */
	private $active_profile = null;

	/** In-flight lock TTL (seconds) to stop double-billing from rapid clicks. */
	const RUN_LOCK_TTL = 60;

	/** Result de-dupe cache TTL (seconds). */
	const RESULT_CACHE_TTL = 60;

	/** The only AI service this plugin talks to. */
	const PROVIDER = 'insightistic_cloud';

	/**
	 * Whether the site owner has explicitly opted in to sending data to
	 * Insightistic Cloud AI. Off by default.
	 *
	 * @return bool
	 */
	public static function has_consent() {
		return (bool) get_option( 'insightistic_ai_consent', 0 );
	}

	/**
	 * AI credit balance from the last license check-in.
	 *
	 * @return array{total:int,monthly:int,granted:int,purchased:int}
	 */
	public static function credits_summary() {
		$state   = class_exists( 'Insightistic_License_Manager' ) ? Insightistic_License_Manager::state() : array();
		$credits = is_array( $state['credits'] ?? null ) ? $state['credits'] : array();
		return array(
			'total'     => absint( $credits['total_usable'] ?? 0 ),
			'monthly'   => absint( $credits['monthly_included']['remaining'] ?? 0 ),
			'granted'   => absint( $credits['monthly_included']['granted'] ?? ( $state['monthly_ai_credits'] ?? 0 ) ),
			'purchased' => absint( $credits['purchased']['remaining'] ?? 0 ),
		);
	}

	/**
	 * Re-read the credit balance from Insightistic after a request.
	 */
	private function refresh_credits() {
		if ( class_exists( 'Insightistic_License_Manager' ) ) {
			( new Insightistic_License_Manager() )->refresh();
		}
	}

	/**
	 * Register AJAX hook.
	 */
	public function init() {
		add_action( 'wp_ajax_insightistic_ai_analyze', array( $this, 'ajax_analyze' ) );
	}

	/**
	 * AJAX handler: run AI analysis.
	 */
	public function ajax_analyze() {
		check_ajax_referer( 'insightistic_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'insightistic' ) );
		}

		if ( ! get_option( 'insightistic_ai_enabled', 0 ) ) {
			wp_send_json_error( __( 'AI analysis is disabled. Enable it in Settings.', 'insightistic' ) );
		}

		if ( ! self::has_consent() ) {
			wp_send_json_error( __( 'Insightistic Cloud AI is off. To use it, open Settings > AI Insights and agree to send analytics summaries to the Insightistic service.', 'insightistic' ) );
		}

		$provider = self::PROVIDER;

		if ( class_exists( 'Insightistic_Feature_Gate' ) && ! Insightistic_Feature_Gate::can( 'ai_insights' ) ) {
			wp_send_json_error(
				array(
					'code' => 'locked',
					'html' => Insightistic_Feature_Gate::locked_card( 'ai_insights', '', __( 'Insightistic Cloud AI is an optional hosted service and needs a free Insightistic account. Your analytics dashboards and email digests work without one.', 'insightistic' ) ),
				)
			);
		}

		// Raw JSON payload built by our own dashboard JS; decoded and re-encoded
		// below, never echoed. json_decode() returns null for malformed input.
		$raw_data = wp_unslash( $_POST['data'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw JSON from this plugin's own admin JS; it is json_decode()d and each field is sanitized/validated before use, never echoed.
		$data     = json_decode( $raw_data, true );
		$days     = isset( $_POST['days'] ) ? absint( wp_unslash( $_POST['days'] ) ) : 28;

		if ( empty( $data ) || ! is_array( $data ) ) {
			wp_send_json_error( __( 'No analytics data to analyse.', 'insightistic' ) );
		}

		// Cost guardrails: serve a recent identical result instead of paying
		// for a duplicate provider call, and block overlapping runs.
		$model       = get_option( 'insightistic_insightistic_cloud_model', 'ollama-balanced' );
		$fingerprint = md5( $provider . '|' . $model . '|' . $days . '|' . wp_json_encode( $data ) );
		$cache_key   = 'insightistic_ai_cache_' . $fingerprint;
		$lock_key    = 'insightistic_ai_lock_' . get_current_user_id();

		$cached = get_transient( $cache_key );
		if ( false !== $cached ) {
			wp_send_json_success(
				array(
					'html'   => $cached,
					'cached' => true,
				)
			);
		}

		if ( get_transient( $lock_key ) ) {
			wp_send_json_error( __( 'An AI analysis is already running. Please wait a few seconds and try again.', 'insightistic' ) );
		}
		// In-flight lock. Released explicitly before every response below
		// because wp_send_json_*() exits and would skip a finally block; if a
		// hard fatal slips through, the lock simply self-expires.
		set_transient( $lock_key, 1, self::RUN_LOCK_TTL );

		$prompt = $this->build_prompt( $data, $days );

		$result = $this->call_insightistic_cloud( $prompt );

		if ( is_wp_error( $result ) ) {
			delete_transient( $lock_key );
			wp_send_json_error( $result->get_error_message() );
		}

		$parsed = $this->parse_ai_json( $result );
		if ( ! is_array( $parsed ) ) {
			delete_transient( $lock_key );
			wp_send_json_error( __( 'AI returned an unreadable response. Please try again.', 'insightistic' ) );
		}

		$html = $this->render_insights( $parsed, $provider );
		set_transient( $cache_key, $html, self::RESULT_CACHE_TTL );

		delete_transient( $lock_key );
		wp_send_json_success( array( 'html' => $html ) );
	}

	/**
	 * Quick connectivity check for a single provider.
	 *
	 * Sends a minimal one-sentence prompt and returns either a success
	 * message or a WP_Error so the Settings UI can surface concrete
	 * "Key invalid", "Model not found", "Network error" responses without
	 * waiting on a full insight build.
	 *
	 * @param string $provider Provider slug.
	 * @return true|WP_Error
	 */
	public function test_provider( $provider = self::PROVIDER ) {
		unset( $provider );
		if ( ! self::has_consent() ) {
			return new WP_Error( 'no_consent', __( 'Give consent in Settings > AI Insights before testing Insightistic Cloud AI.', 'insightistic' ) );
		}
		$prompt = 'Respond with the JSON {"overall_score": 90, "summary": "ok"}.';
		$result = $this->call_insightistic_cloud( $prompt );

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! is_string( $result ) || '' === trim( $result ) ) {
			return new WP_Error( 'empty_response', __( 'Provider returned an empty response.', 'insightistic' ) );
		}
		return true;
	}

	/**
	 * Run an AI analysis specifically over WooCommerce data.
	 *
	 * Reuses the same provider/model configuration as the standard AI
	 * Insights flow, but swaps the system prompt and the user prompt for
	 * commerce-focused expertise (revenue, AOV, refunds, repeat purchase,
	 * product mix, top customers, channel attribution).
	 *
	 * @param array $data Structured commerce payload.
	 * @param int   $days Window size in days.
	 * @return array|WP_Error  Rendered HTML on success.
	 */
	public function analyze_commerce( $data, $days = 28 ) {
		if ( ! get_option( 'insightistic_ai_enabled', 0 ) || ! self::has_consent() ) {
			return new WP_Error( 'no_consent', __( 'Insightistic Cloud AI is off. Enable it and give consent in Settings > AI Insights.', 'insightistic' ) );
		}

		$provider = self::PROVIDER;

		// Use a per-request profile override so commerce analysis gets the
		// commerce-tuned system prompt without persisting any global option
		// (no race condition, no stray write if the request dies mid-call).
		$this->active_profile = 'commerce_expert';

		$prompt = $this->build_commerce_prompt( $data, $days );

		try {
			$result = $this->call_insightistic_cloud( $prompt );
		} finally {
			// Always clear the override, even if a provider call throws.
			$this->active_profile = null;
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$parsed = $this->parse_ai_json( $result );
		if ( ! is_array( $parsed ) ) {
			return new WP_Error( 'unreadable_response', __( 'AI returned an unreadable response. Please try again.', 'insightistic' ) );
		}
		return array( 'html' => $this->render_insights( $parsed, $provider ) );
	}

	/**
	 * Build the commerce-specific user prompt.
	 *
	 * @param array $data Structured commerce payload.
	 * @param int   $days Window size in days.
	 * @return string
	 */
	private function build_commerce_prompt( $data, $days ) {
		$currency = isset( $data['currency'] ) ? (string) $data['currency'] : 'USD';
		$prompt   = "Analyse the following WooCommerce store data for the last {$days} days. Currency: {$currency}.\n\n";
		$prompt  .= "DATA:\n" . wp_json_encode( $data ) . "\n\n";
		$prompt  .= 'Focus on: revenue trend, AOV movement, refund rate, repeat-purchase opportunities, product-mix concentration risk, top-customer retention, and quick-win promotions. '
				. 'If the previous period is small or zero, do not over-claim growth. ';
		$prompt  .= "Return this exact JSON structure:\n";
		$prompt  .= wp_json_encode(
			array(
				'overall_score'   => 'integer 0-100 reflecting overall store health',
				'summary'         => '2-3 sentence executive summary mentioning concrete revenue numbers',
				'key_insights'    => array(
					array(
						'title'       => 'Commerce insight heading (e.g. "AOV expanded 18% week over week")',
						'description' => 'Explanation tied to the actual numbers',
						'impact'      => 'high|medium|low',
					),
				),
				'recommendations' => array(
					array(
						'title'           => 'Action heading (e.g. "Bundle SKU-123 with SKU-456 in checkout upsell")',
						'description'     => 'Implementation detail with measurable goal',
						'priority'        => 'high|medium|low',
						'expected_impact' => 'Expected outcome stated in revenue or AOV terms',
						'effort'          => 'high|medium|low',
					),
				),
				'warnings'        => array(
					array(
						'issue'          => 'Commerce risk (refund spike, single-SKU concentration, abandoned coupons)',
						'severity'       => 'high|medium|low',
						'recommendation' => 'Fix',
					),
				),
			)
		);
		return $prompt;
	}

	/**
	 * Run an AI analysis over Cloudflare Traffic Insights data (edge
	 * requests, cache ratio, country/status/TLS breakdowns, firewall
	 * events). Reuses the same provider/model configuration as the
	 * standard AI Insights flow, gated behind the same account
	 * requirement (`ai_insights`) enforced by the caller.
	 *
	 * @param array $data Structured Cloudflare payload (see
	 *                    Insightistic_Cloudflare::get_dashboard_data()).
	 * @param int   $days Window size in days.
	 * @return array|WP_Error Rendered HTML on success.
	 */
	public function analyze_cloudflare( $data, $days = 28 ) {
		if ( ! get_option( 'insightistic_ai_enabled', 0 ) || ! self::has_consent() ) {
			return new WP_Error( 'no_consent', __( 'Insightistic Cloud AI is off. Enable it and give consent in Settings > AI Insights.', 'insightistic' ) );
		}

		$provider = self::PROVIDER;

		$this->active_profile = 'traffic_expert';

		$prompt = $this->build_cloudflare_prompt( $data, $days );

		try {
			$result = $this->call_insightistic_cloud( $prompt );
		} finally {
			$this->active_profile = null;
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$parsed = $this->parse_ai_json( $result );
		if ( ! is_array( $parsed ) ) {
			return new WP_Error( 'unreadable_response', __( 'AI returned an unreadable response. Please try again.', 'insightistic' ) );
		}
		return array( 'html' => $this->render_insights( $parsed, $provider ) );
	}

	/**
	 * Build the Cloudflare-specific user prompt.
	 *
	 * @param array $data Structured Cloudflare payload.
	 * @param int   $days Window size in days.
	 * @return string
	 */
	private function build_cloudflare_prompt( $data, $days ) {
		$prompt  = "Analyse the following Cloudflare edge traffic data for the last {$days} days and return a JSON object.\n\n";
		$prompt .= "DATA:\n" . wp_json_encode( $data ) . "\n\n";
		$prompt .= 'Focus on: cache hit ratio and what a low ratio costs in origin load, the mix of encrypted vs unencrypted requests, '
				. 'concentration of traffic or threats in specific countries, unusual status-code patterns (e.g. a spike in 5xx or 429), '
				. 'and any firewall events worth the site owner\'s attention. '
				. 'This is edge-level data, so it will not match GA4 exactly since it includes bots, crawlers, and requests that never fired a client-side tag; do not treat that as an error. ';
		$prompt .= "Return this exact JSON structure:\n";
		$prompt .= wp_json_encode(
			array(
				'overall_score'   => 'integer 0-100 reflecting edge traffic health (caching efficiency, security posture, anomaly-free)',
				'summary'         => '2-3 sentence executive summary mentioning concrete request/cache/threat numbers',
				'key_insights'    => array(
					array(
						'title'       => 'Traffic insight heading (e.g. "Cache hit rate dropped to 41% this period")',
						'description' => 'Explanation tied to the actual numbers',
						'impact'      => 'high|medium|low',
					),
				),
				'recommendations' => array(
					array(
						'title'           => 'Action heading (e.g. "Add a cache rule for /wp-content/uploads/*")',
						'description'     => 'Implementation detail with measurable goal',
						'priority'        => 'high|medium|low',
						'expected_impact' => 'Expected outcome in cache/bandwidth/security terms',
						'effort'          => 'high|medium|low',
					),
				),
				'warnings'        => array(
					array(
						'issue'          => 'Traffic risk (threat spike from a country, abnormal status codes, cache regression)',
						'severity'       => 'high|medium|low',
						'recommendation' => 'Fix',
					),
				),
			)
		);
		return $prompt;
	}

	/*
	------------------------------------------------------------------ */
	/*
	Provider API Calls                                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * Call Insightistic Cloud AI through the account's plan-aware provider chain.
	 * No provider key is stored on this site. The free tier uses the configured
	 * WordPressistic gateway and zero-cost OpenRouter fallbacks; paid tiers are
	 * routed server-side according to the account entitlement. The selected
	 * analysis focus is context, not a provider/model override. Usage limits are
	 * enforced server-side and surfaced as a friendly quota_exceeded message.
	 *
	 * @param string $prompt User prompt.
	 * @return string|WP_Error JSON content string.
	 */
	private function call_insightistic_cloud( $prompt ) {
		if ( ! class_exists( 'Insightistic_License_Manager' ) || ! Insightistic_License_Manager::is_connected() ) {
			return new WP_Error(
				'no_license',
				__( 'Connect your free Insightistic account (Insightistic → License) to use Insightistic Cloud AI.', 'insightistic' )
			);
		}

		$model   = get_option( 'insightistic_insightistic_cloud_model', 'ollama-balanced' );
		$profile = null !== $this->active_profile ? $this->active_profile : get_option( 'insightistic_ai_skill_profile', 'basic' );
		// The API accepts basic|seo_expert|commerce_expert; any other focus
		// (e.g. traffic_expert) is already expressed in the system prompt.
		if ( ! in_array( $profile, array( 'basic', 'seo_expert', 'commerce_expert' ), true ) ) {
			$profile = 'basic';
		}
		if ( ! in_array( $model, array( 'ollama-balanced', 'hermes-seo' ), true ) ) {
			$model = 'ollama-balanced';
		}

		$result = Insightistic_Saas_Client::ai_insights(
			array(
				'model'         => $model,
				'skill_profile' => $profile,
				'system_prompt' => $this->system_prompt(),
				'prompt'        => $prompt,
			)
		);

		if ( ! $result['ok'] ) {
			if ( $result['network'] ) {
				return new WP_Error( 'cloud_network', __( 'Could not reach Insightistic Cloud AI. Please try again shortly.', 'insightistic' ) );
			}

			$code = is_array( $result['data'] ) && ! empty( $result['data']['code'] ) ? $result['data']['code'] : '';
			if ( 'quota_exceeded' === $code || 'insufficient_credits' === $code || 402 === $result['status'] || 429 === $result['status'] ) {
				$this->refresh_credits();
				return new WP_Error(
					'cloud_quota',
					__( 'You have used all of your Insightistic AI credits for this period. Your free monthly credits reset automatically at the start of next month. Your analytics dashboards keep working in the meantime.', 'insightistic' )
				);
			}
			if ( 'ai_provider_unavailable' === $code ) {
				return new WP_Error( 'cloud_unavailable', __( 'Insightistic Cloud AI is temporarily unavailable for this site. Please try again later.', 'insightistic' ) );
			}
			if ( 'no_license' === $code || in_array( $result['status'], array( 401, 403 ), true ) ) {
				return new WP_Error( 'cloud_unauthorized', __( 'Your Insightistic account connection could not be verified. Open Insightistic → License and reconnect.', 'insightistic' ) );
			}

			return new WP_Error(
				'cloud_error',
				$result['error'] ? $result['error'] : __( 'Insightistic Cloud AI returned an error. Please try again.', 'insightistic' )
			);
		}

		// Credits were spent; refresh the cached balance.
		$this->refresh_credits();

		$content = is_array( $result['data'] ) ? ( $result['data']['content'] ?? '' ) : '';
		if ( ! $content ) {
			return new WP_Error( 'cloud_empty', __( 'Insightistic Cloud AI returned an empty response.', 'insightistic' ) );
		}

		$content = preg_replace( '/^```json\s*/i', '', $content );
		$content = preg_replace( '/```\s*$/', '', $content );
		return trim( $content );
	}

	/*
	------------------------------------------------------------------ */
	/*
	Prompt Builders                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * System prompt sent to Insightistic Cloud AI.
	 */
	private function system_prompt() {
		$profile = null !== $this->active_profile
			? $this->active_profile
			: get_option( 'insightistic_ai_skill_profile', 'basic' );
		$base    = 'You are an expert digital marketing analyst specialising in Google Analytics, revenue attribution and conversion optimisation. '
			. 'Provide concise, data-driven, actionable insights. '
			. 'Always respond ONLY with a valid JSON object matching the requested schema. Do not include any text outside the JSON.';
		if ( 'seo_expert' === $profile ) {
			$base .= ' You are also an SEO specialist. Prioritize organic traffic growth, CTR improvement, content decay detection, keyword intent alignment, internal linking opportunities, and technical SEO quick wins.';
		}
		if ( 'commerce_expert' === $profile ) {
			$base .= ' You are also a senior ecommerce strategist for WooCommerce stores. Prioritise revenue, average order value, refund rate, repeat purchase rate, product-mix concentration risk, top-customer retention, cart and checkout funnel optimisation, and high-leverage promotion or bundling moves. Always quote the concrete numbers from the dataset.';
		}
		if ( 'traffic_expert' === $profile ) {
			$base .= ' You are also a CDN and web infrastructure analyst specialising in Cloudflare edge traffic. Prioritise cache-hit efficiency and origin-load reduction, encrypted-traffic adoption, bot/threat concentration by country, anomalous HTTP status-code patterns, and concrete firewall/caching-rule recommendations. Remember this data is edge-level, not client-side analytics, so do not flag a mismatch with GA4 as an error.';
		}
		return $base;
	}

	/**
	 * Build the analysis prompt from structured GA4 data.
	 *
	 * @param array $data  Structured data from the GA4 class.
	 * @param int   $days  Number of days analysed.
	 * @return string
	 */
	private function build_prompt( $data, $days ) {
		$prompt  = "Analyse the following Google Analytics 4 data for the last {$days} days and return a JSON object.\n\n";
		$prompt .= "DATA:\n" . wp_json_encode( $data ) . "\n\n";
		$prompt .= "Return this exact JSON structure:\n";
		$prompt .= wp_json_encode(
			array(
				'overall_score'   => 'integer 0-100 reflecting marketing performance',
				'summary'         => '2-3 sentence executive summary',
				'key_insights'    => array(
					array(
						'title'       => 'Insight heading',
						'description' => 'Explanation',
						'impact'      => 'high|medium|low',
					),
				),
				'recommendations' => array(
					array(
						'title'           => 'Action heading',
						'description'     => 'Implementation detail',
						'priority'        => 'high|medium|low',
						'expected_impact' => 'Expected outcome',
						'effort'          => 'high|medium|low',
					),
				),
				'warnings'        => array(
					array(
						'issue'          => 'Problem',
						'severity'       => 'high|medium|low',
						'recommendation' => 'Fix',
					),
				),
			)
		);
		return $prompt;
	}

	/*
	------------------------------------------------------------------ */
	/*
	Response Rendering                                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * Render AI insights as HTML.
	 *
	 * @param array  $data     Parsed AI JSON response.
	 * @param string $provider Provider key.
	 * @return string HTML.
	 */
	private function render_insights( $data, $provider ) {
		unset( $provider );
		$provider_label = 'Insightistic Cloud AI';

		$engine_label = 'hermes-seo' === get_option( 'insightistic_insightistic_cloud_model', 'ollama-balanced' )
			? __( 'SEO-focused analysis', 'insightistic' )
			: __( 'Balanced', 'insightistic' );

		$score       = max( 0, min( 100, intval( $data['overall_score'] ?? 0 ) ) );
		$score_class = $score >= 70 ? 'isp-score-high' : ( $score >= 40 ? 'isp-score-medium' : 'isp-score-low' );
		$score_color = $score >= 70 ? '#34d399' : ( $score >= 40 ? '#fbbf24' : '#f87171' );

		// Reuses the same .isp-gauge/-track/-fill/-value primitives the Speed
		// Test rings already use; admin.js's generic animateGauges() animates
		// any element with those classes, so no new JS is needed  the
		// caller just has to invoke it once after inserting this HTML.
		$gauge_size = 56;
		$gauge_r    = ( $gauge_size / 2 ) - 6;
		$gauge_c    = 2 * M_PI * $gauge_r;
		$gauge_off  = $gauge_c - ( $gauge_c * $score / 100 );

		ob_start();
		?>
		<div class="isp-ai-panel">
			<div class="isp-ai-header">
				<div class="isp-ai-title">
					<span class="isp-ai-icon"></span>
					<?php esc_html_e( 'AI-Powered Insights', 'insightistic' ); ?>
					<span class="isp-ai-badge">
						<?php echo esc_html( $provider_label ); ?>
						<?php echo $engine_label ? ' &middot; ' . esc_html( $engine_label ) : ''; ?>
					</span>
				</div>
				<?php if ( $score ) : ?>
				<div class="isp-score-badge <?php echo esc_attr( $score_class ); ?>">
					<div class="isp-gauge isp-ai-gauge" style="width:<?php echo esc_attr( $gauge_size ); ?>px">
						<svg viewBox="0 0 <?php echo esc_attr( $gauge_size ); ?> <?php echo esc_attr( $gauge_size ); ?>" width="<?php echo esc_attr( $gauge_size ); ?>" height="<?php echo esc_attr( $gauge_size ); ?>">
							<circle class="isp-gauge-track" cx="<?php echo esc_attr( $gauge_size / 2 ); ?>" cy="<?php echo esc_attr( $gauge_size / 2 ); ?>" r="<?php echo esc_attr( $gauge_r ); ?>"/>
							<circle class="isp-gauge-fill" cx="<?php echo esc_attr( $gauge_size / 2 ); ?>" cy="<?php echo esc_attr( $gauge_size / 2 ); ?>" r="<?php echo esc_attr( $gauge_r ); ?>"
								stroke="<?php echo esc_attr( $score_color ); ?>"
								stroke-dasharray="<?php echo esc_attr( round( $gauge_c, 1 ) ); ?>"
								stroke-dashoffset="<?php echo esc_attr( round( $gauge_c, 1 ) ); ?>"
								data-target-offset="<?php echo esc_attr( round( $gauge_off, 1 ) ); ?>"/>
						</svg>
						<div class="isp-gauge-value" data-count-to="<?php echo esc_attr( $score ); ?>" style="color:<?php echo esc_attr( $score_color ); ?>">0</div>
					</div>
					<span class="isp-score-label"><?php esc_html_e( 'Overall score', 'insightistic' ); ?></span>
				</div>
				<?php endif; ?>
			</div>

			<?php if ( ! empty( $data['summary'] ) ) : ?>
			<div class="isp-ai-summary">
				<p><?php echo esc_html( $data['summary'] ); ?></p>
			</div>
			<?php endif; ?>

			<?php if ( ! empty( $data['key_insights'] ) ) : ?>
			<div class="isp-ai-section">
				<h3 class="isp-ai-section-title"> <?php esc_html_e( 'Key Insights', 'insightistic' ); ?></h3>
				<div class="isp-ai-cards">
					<?php foreach ( $data['key_insights'] as $insight ) : ?>
					<div class="isp-ai-card isp-impact-<?php echo esc_attr( $insight['impact'] ?? 'medium' ); ?>">
						<div class="isp-ai-card-impact"><?php echo esc_html( ucfirst( $insight['impact'] ?? 'medium' ) ); ?></div>
						<h4><?php echo esc_html( $insight['title'] ?? '' ); ?></h4>
						<p><?php echo esc_html( $insight['description'] ?? '' ); ?></p>
					</div>
					<?php endforeach; ?>
				</div>
			</div>
			<?php endif; ?>

			<?php if ( ! empty( $data['recommendations'] ) ) : ?>
			<div class="isp-ai-section">
				<h3 class="isp-ai-section-title"> <?php esc_html_e( 'Recommendations', 'insightistic' ); ?></h3>
				<div class="isp-ai-recs">
					<?php foreach ( $data['recommendations'] as $rec ) : ?>
					<div class="isp-ai-rec isp-priority-<?php echo esc_attr( $rec['priority'] ?? 'medium' ); ?>">
						<div class="isp-rec-meta">
							<span class="isp-rec-priority"><?php echo esc_html( ucfirst( $rec['priority'] ?? 'medium' ) ); ?> <?php esc_html_e( 'Priority', 'insightistic' ); ?></span>
							<span class="isp-rec-effort"><?php esc_html_e( 'Effort:', 'insightistic' ); ?> <?php echo esc_html( ucfirst( $rec['effort'] ?? '' ) ); ?></span>
						</div>
						<h4><?php echo esc_html( $rec['title'] ?? '' ); ?></h4>
						<p><?php echo esc_html( $rec['description'] ?? '' ); ?></p>
						<?php if ( ! empty( $rec['expected_impact'] ) ) : ?>
						<p class="isp-rec-impact"> <?php echo esc_html( $rec['expected_impact'] ); ?></p>
						<?php endif; ?>
					</div>
					<?php endforeach; ?>
				</div>
			</div>
			<?php endif; ?>

			<?php if ( ! empty( $data['warnings'] ) ) : ?>
			<div class="isp-ai-section">
				<h3 class="isp-ai-section-title"> <?php esc_html_e( 'Issues to Address', 'insightistic' ); ?></h3>
				<div class="isp-ai-recs">
					<?php foreach ( $data['warnings'] as $w ) : ?>
					<div class="isp-ai-rec isp-warning isp-priority-<?php echo esc_attr( $w['severity'] ?? 'medium' ); ?>">
						<div class="isp-rec-meta">
							<span class="isp-rec-priority"><?php echo esc_html( ucfirst( $w['severity'] ?? 'medium' ) ); ?> <?php esc_html_e( 'Severity', 'insightistic' ); ?></span>
						</div>
						<h4><?php echo esc_html( $w['issue'] ?? '' ); ?></h4>
						<p><?php echo esc_html( $w['recommendation'] ?? '' ); ?></p>
					</div>
					<?php endforeach; ?>
				</div>
			</div>
			<?php endif; ?>

			<div class="isp-ai-footer">
				<?php esc_html_e( 'AI analysis generated by', 'insightistic' ); ?> <?php echo esc_html( $provider_label ); ?> &bull;
				<?php
				$insightistic_credits = self::credits_summary();
				/* translators: %s: number of AI credits remaining */
				echo esc_html( sprintf( __( 'AI credits remaining: %s', 'insightistic' ), number_format_i18n( $insightistic_credits['total'] ) ) );
				?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/*
	------------------------------------------------------------------ */
	/*
	Helpers                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Parse JSON robustly from providers that may wrap or prepend text.
	 *
	 * @param string $raw Raw model output.
	 * @return array|null
	 */
	private function parse_ai_json( $raw ) {
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) ) {
			return $this->normalize_payload( $decoded );
		}
		if ( preg_match( '/\{(?:[^{}]|(?R))*\}/s', $raw, $m ) ) {
			$decoded = json_decode( $m[0], true );
			if ( is_array( $decoded ) ) {
				return $this->normalize_payload( $decoded );
			}
		}
		return null;
	}

	/**
	 * Guarantee required keys to keep UI stable.
	 *
	 * @param array $data AI response payload.
	 * @return array
	 */
	private function normalize_payload( $data ) {
		$defaults = array(
			'overall_score'   => 0,
			'summary'         => '',
			'key_insights'    => array(),
			'recommendations' => array(),
			'warnings'        => array(),
		);
		return wp_parse_args( $data, $defaults );
	}
}

