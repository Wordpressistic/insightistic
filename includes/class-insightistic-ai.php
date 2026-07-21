<?php
/**
 * AI Analysis class for Insightistic.
 * Supports OpenAI, Google Gemini, OpenRouter, Groq, and Anthropic Claude.
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

		$provider = get_option( 'insightistic_ai_provider', 'none' );
		if ( 'none' === $provider ) {
			wp_send_json_error( __( 'No AI provider selected. Please configure one in Settings.', 'insightistic' ) );
		}

		if ( class_exists( 'Insightistic_Feature_Gate' ) && ! Insightistic_Feature_Gate::can( 'ai_insights' ) ) {
			wp_send_json_error(
				array(
					'code' => 'locked',
					'html' => Insightistic_Feature_Gate::locked_card( 'ai_insights', '', __( 'Create a free account to unlock AI Insights.', 'insightistic' ) ),
				)
			);
		}

		// Raw JSON payload built by our own dashboard JS; decoded and re-encoded
		// below, never echoed. json_decode() returns null for malformed input.
		$raw_data = wp_unslash( $_POST['data'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$data     = json_decode( $raw_data, true );
		$days     = intval( $_POST['days'] ?? 28 );

		if ( empty( $data ) || ! is_array( $data ) ) {
			wp_send_json_error( __( 'No analytics data to analyse.', 'insightistic' ) );
		}

		// Cost guardrails: serve a recent identical result instead of paying
		// for a duplicate provider call, and block overlapping runs.
		$model       = get_option( 'insightistic_' . $provider . '_model', '' );
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

		switch ( $provider ) {
			case 'openai':
				$result = $this->call_openai( $prompt );
				break;
			case 'gemini':
				$result = $this->call_gemini( $prompt );
				break;
			case 'openrouter':
				$result = $this->call_openrouter( $prompt );
				break;
			case 'claude':
				$result = $this->call_claude( $prompt );
				break;
			case 'groq':
				$result = $this->call_groq( $prompt );
				break;
			case 'insightistic_cloud':
				$result = $this->call_insightistic_cloud( $prompt );
				break;
			default:
				delete_transient( $lock_key );
				wp_send_json_error( __( 'Unknown AI provider.', 'insightistic' ) );
				return;
		}

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
	public function test_provider( $provider ) {
		$prompt = 'Respond with the JSON {"overall_score": 90, "summary": "ok"}.';
		switch ( $provider ) {
			case 'openai':
				$result = $this->call_openai( $prompt );
				break;
			case 'gemini':
				$result = $this->call_gemini( $prompt );
				break;
			case 'openrouter':
				$result = $this->call_openrouter( $prompt );
				break;
			case 'groq':
				$result = $this->call_groq( $prompt );
				break;
			case 'claude':
				$result = $this->call_claude( $prompt );
				break;
			case 'insightistic_cloud':
				$result = $this->call_insightistic_cloud( $prompt );
				break;
			default:
				return new WP_Error( 'unknown_provider', __( 'Unknown provider.', 'insightistic' ) );
		}

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
		$provider = get_option( 'insightistic_ai_provider', 'none' );
		if ( 'none' === $provider ) {
			return new WP_Error( 'no_provider', __( 'No AI provider selected.', 'insightistic' ) );
		}

		// Use a per-request profile override so commerce analysis gets the
		// commerce-tuned system prompt without persisting any global option
		// (no race condition, no stray write if the request dies mid-call).
		$this->active_profile = 'commerce_expert';

		$prompt = $this->build_commerce_prompt( $data, $days );

		try {
			switch ( $provider ) {
				case 'openai':
					$result = $this->call_openai( $prompt );
					break;
				case 'gemini':
					$result = $this->call_gemini( $prompt );
					break;
				case 'openrouter':
					$result = $this->call_openrouter( $prompt );
					break;
				case 'claude':
					$result = $this->call_claude( $prompt );
					break;
				case 'groq':
					$result = $this->call_groq( $prompt );
					break;
				case 'insightistic_cloud':
					$result = $this->call_insightistic_cloud( $prompt );
					break;
				default:
					return new WP_Error( 'unknown_provider', __( 'Unknown AI provider.', 'insightistic' ) );
			}
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
		$provider = get_option( 'insightistic_ai_provider', 'none' );
		if ( 'none' === $provider ) {
			return new WP_Error( 'no_provider', __( 'No AI provider selected.', 'insightistic' ) );
		}

		$this->active_profile = 'traffic_expert';

		$prompt = $this->build_cloudflare_prompt( $data, $days );

		try {
			switch ( $provider ) {
				case 'openai':
					$result = $this->call_openai( $prompt );
					break;
				case 'gemini':
					$result = $this->call_gemini( $prompt );
					break;
				case 'openrouter':
					$result = $this->call_openrouter( $prompt );
					break;
				case 'claude':
					$result = $this->call_claude( $prompt );
					break;
				case 'groq':
					$result = $this->call_groq( $prompt );
					break;
				case 'insightistic_cloud':
					$result = $this->call_insightistic_cloud( $prompt );
					break;
				default:
					return new WP_Error( 'unknown_provider', __( 'Unknown AI provider.', 'insightistic' ) );
			}
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

	/* ------------------------------------------------------------------ */
	/* Provider API Calls                                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * Call OpenAI Chat Completions API.
	 *
	 * @param string $prompt User prompt.
	 * @return string|WP_Error JSON content string.
	 */
	private function call_openai( $prompt ) {
		$key = $this->get_key( 'insightistic_openai_key' );
		if ( is_wp_error( $key ) ) {
			return $key;
		}

		$model = get_option( 'insightistic_openai_model', 'gpt-4o-mini' );

		$response = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
			array(
				'timeout' => 45,
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'           => $model,
						'messages'        => array(
							array( 'role' => 'system', 'content' => $this->system_prompt() ),
							array( 'role' => 'user', 'content' => $prompt ),
						),
						'temperature'     => 0.5,
						'max_tokens'      => 2000,
						'response_format' => array( 'type' => 'json_object' ),
					)
				),
			)
		);

		return $this->extract_openai_content( $response );
	}

	/**
	 * Call Google Gemini API.
	 *
	 * @param string $prompt User prompt.
	 * @return string|WP_Error JSON content string.
	 */
	private function call_gemini( $prompt ) {
		$key = $this->get_key( 'insightistic_gemini_key' );
		if ( is_wp_error( $key ) ) {
			return $key;
		}

		$model    = get_option( 'insightistic_gemini_model', 'gemini-1.5-flash' );
		$endpoint = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . rawurlencode( $key );

		$full_prompt = $this->system_prompt() . "\n\n" . $prompt;

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 45,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'contents'         => array(
							array( 'parts' => array( array( 'text' => $full_prompt ) ) ),
						),
						'generationConfig' => array(
							'temperature'     => 0.5,
							'maxOutputTokens' => 2000,
							'responseMimeType' => 'application/json',
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( isset( $body['error'] ) ) {
			return new WP_Error( 'gemini_error', $body['error']['message'] );
		}

		$text = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';
		if ( ! $text ) {
			return new WP_Error( 'gemini_empty', __( 'Gemini returned an empty response.', 'insightistic' ) );
		}

		// Strip possible markdown fences.
		$text = preg_replace( '/^```json\s*/i', '', $text );
		$text = preg_replace( '/```\s*$/', '', $text );
		return trim( $text );
	}

	/**
	 * Call OpenRouter API (OpenAI-compatible).
	 *
	 * @param string $prompt User prompt.
	 * @return string|WP_Error JSON content string.
	 */
	private function call_openrouter( $prompt ) {
		$key = $this->get_key( 'insightistic_openrouter_key' );
		if ( is_wp_error( $key ) ) {
			return $key;
		}

		$model = get_option( 'insightistic_openrouter_model', 'meta-llama/llama-3.3-70b-instruct:free' );

		// NOTE: response_format is intentionally omitted here.
		// Most free OpenRouter models do not support JSON mode (response_format).
		// JSON output is enforced via the system prompt and user prompt instead.
		$response = wp_remote_post(
			'https://openrouter.ai/api/v1/chat/completions',
			array(
				'timeout' => 45,
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
					'HTTP-Referer'  => home_url(),
					'X-Title'       => 'Insightistic Analytics',
				),
				'body'    => wp_json_encode(
					array(
						'model'       => $model,
						'messages'    => array(
							array( 'role' => 'system', 'content' => $this->system_prompt() ),
							array( 'role' => 'user', 'content' => $prompt ),
						),
						'temperature' => 0.3,
						'max_tokens'  => 2000,
					)
				),
			)
		);

		$result = $this->extract_openai_content( $response );
		if ( is_wp_error( $result ) ) {
			return $this->translate_openrouter_error( $result, $model );
		}
		return $result;
	}

	/**
	 * Translate a generic OpenRouter API error into an actionable message
	 * that names the model slug and points the operator at the current
	 * free-model catalogue. Without this, the dashboard only shows the
	 * raw "Model not found" / "404" string which doesn't tell the user
	 * what to fix.
	 *
	 * @param WP_Error $err   Original error.
	 * @param string   $model Configured model slug.
	 * @return WP_Error
	 */
	private function translate_openrouter_error( $err, $model ) {
		$msg     = $err->get_error_message();
		$low     = strtolower( $msg );
		$is_free = ( false !== strpos( $model, ':free' ) );

		// Detect the OpenRouter "data policy" gate. Free models on OpenRouter
		// require the account to explicitly opt in to "providers may train on
		// my prompts" at openrouter.ai/settings/privacy. Without that opt-in,
		// every :free model returns "no allowed providers" / "no endpoints
		// found" / "data policy". This is the #1 cause of "I tried every free
		// model and they all error" reports.
		$is_data_policy = false !== strpos( $low, 'data policy' )
			|| false !== strpos( $low, 'no allowed providers' )
			|| false !== strpos( $low, 'no endpoints found' )
			|| false !== strpos( $low, 'provider preferences' )
			|| false !== strpos( $low, 'privacy settings' );

		if ( $is_data_policy && $is_free ) {
			$hint = sprintf(
				/* translators: 1: model slug, 2: OpenRouter privacy URL */
				__( 'OpenRouter is blocking this :free model because your account has not opted in to free-tier data sharing. Open %2$s, enable "Free model providers may train on my prompts" (or set the privacy level to allow free providers), save, then click Test This Provider again. (Failing model: %1$s)', 'insightistic' ),
				$model,
				'https://openrouter.ai/settings/privacy'
			);
			return new WP_Error( 'openrouter_privacy_gate', $hint );
		}

		// Wrong-type model (re-ranker, embedding, audio, vision-only).
		$is_wrong_type = false !== strpos( $model, '-rerank' )
			|| false !== strpos( $model, '-embed' )
			|| false !== strpos( $model, '-tts' )
			|| false !== strpos( $model, '-stt' );

		if ( $is_wrong_type ) {
			$hint = sprintf(
				/* translators: %s: model slug */
				__( 'The model "%s" is not a chat-completion model (re-rankers, embeddings, and audio models cannot answer prompts). Pick an instruct/chat model instead, such as meta-llama/llama-3.3-70b-instruct:free.', 'insightistic' ),
				$model
			);
			return new WP_Error( 'openrouter_wrong_model_type', $hint );
		}

		// Model genuinely not found / deprecated / 404.
		$is_model_404 = false !== strpos( $low, 'not found' )
			|| false !== strpos( $low, 'deprecated' )
			|| false !== strpos( $low, 'no longer available' )
			|| ( false !== strpos( $low, '404' ) && false !== strpos( $low, 'model' ) );

		if ( $is_model_404 ) {
			$hint = sprintf(
				/* translators: 1: model slug, 2: openrouter.ai URL */
				__( 'OpenRouter does not have an active endpoint for the model "%1$s" right now. Open %2$s, pick any current free model, and paste its slug into the "custom model" field below the dropdown.', 'insightistic' ),
				$model,
				'https://openrouter.ai/models?max_price=0'
			);
			return new WP_Error( 'openrouter_model_unavailable', $hint );
		}

		// Auth / 401.
		if ( false !== strpos( $low, '401' ) || ( false !== strpos( $low, 'invalid' ) && false !== strpos( $low, 'key' ) ) || false !== strpos( $low, 'unauthorized' ) ) {
			return new WP_Error(
				'openrouter_auth',
				__( 'OpenRouter rejected the API key. Verify the key at https://openrouter.ai/keys and re-paste it in Settings > AI Insights.', 'insightistic' )
			);
		}

		// Rate limit / 429.
		if ( false !== strpos( $low, '429' ) || false !== strpos( $low, 'rate limit' ) || false !== strpos( $low, 'rate-limited' ) ) {
			return new WP_Error(
				'openrouter_rate_limit',
				__( 'OpenRouter rate-limited this free model. Wait a minute and try again, or switch to another free model in the dropdown.', 'insightistic' )
			);
		}

		// Insufficient credits.
		if ( false !== strpos( $low, 'insufficient' ) || false !== strpos( $low, 'credit' ) ) {
			return new WP_Error(
				'openrouter_credits',
				__( 'OpenRouter reports insufficient credits for this model. Switch to a model with a ":free" suffix or top up at https://openrouter.ai/credits.', 'insightistic' )
			);
		}

		// Default: surface the original message with the model name appended for context.
		return new WP_Error( 'openrouter_error', sprintf( '%s (model: %s)', $msg, $model ) );
	}

	/**
	 * Call Groq API (OpenAI-compatible).
	 *
	 * @param string $prompt User prompt.
	 * @return string|WP_Error JSON content string.
	 */
	private function call_groq( $prompt ) {
		$key = $this->get_key( 'insightistic_groq_key' );
		if ( is_wp_error( $key ) ) {
			return $key;
		}

		$model    = get_option( 'insightistic_groq_model', 'llama-3.1-8b-instant' );
		$response = wp_remote_post(
			'https://api.groq.com/openai/v1/chat/completions',
			array(
				'timeout' => 45,
				'headers' => array(
					'Authorization' => 'Bearer ' . $key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'       => $model,
						'messages'    => array(
							array( 'role' => 'system', 'content' => $this->system_prompt() ),
							array( 'role' => 'user', 'content' => $prompt ),
						),
						'temperature' => 0.3,
						'max_tokens'  => 2000,
					)
				),
			)
		);
		return $this->extract_openai_content( $response );
	}

	/**
	 * Call Insightistic Cloud AI  the self-hosted analysis backend proxied
	 * through the Insightistic SaaS (no key stored on this site; a connected
	 * free account is the only credential). Two engines, selected by the
	 * `insightistic_insightistic_cloud_model` setting:
	 *   ollama-balanced  general-purpose analysis on our self-hosted Ollama models.
	 *   hermes-seo       the Hermes SEO skill agent, tuned for organic-growth analysis.
	 * Both are free with usage limits; the limit is enforced server-side and
	 * surfaced here as a friendly "quota_exceeded" message rather than a raw error.
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
			if ( 'quota_exceeded' === $code ) {
				return new WP_Error(
					'cloud_quota',
					__( 'You have used this period\'s free Insightistic Cloud AI quota. It resets automatically  or switch to a bring-your-own-key provider (OpenAI, Gemini, OpenRouter, Groq, Claude) in Settings meanwhile.', 'insightistic' )
				);
			}
			if ( 'no_license' === $code || in_array( $result['status'], array( 401, 403 ), true ) ) {
				return new WP_Error( 'cloud_unauthorized', __( 'Your Insightistic account connection could not be verified. Open Insightistic → License and reconnect.', 'insightistic' ) );
			}

			return new WP_Error(
				'cloud_error',
				$result['error'] ? $result['error'] : __( 'Insightistic Cloud AI returned an error. Please try again.', 'insightistic' )
			);
		}

		$content = is_array( $result['data'] ) ? ( $result['data']['content'] ?? '' ) : '';
		if ( ! $content ) {
			return new WP_Error( 'cloud_empty', __( 'Insightistic Cloud AI returned an empty response.', 'insightistic' ) );
		}

		$content = preg_replace( '/^```json\s*/i', '', $content );
		$content = preg_replace( '/```\s*$/', '', $content );
		return trim( $content );
	}

	/**
	 * Call Anthropic Claude Messages API.
	 *
	 * @param string $prompt User prompt.
	 * @return string|WP_Error JSON content string.
	 */
	private function call_claude( $prompt ) {
		$key = $this->get_key( 'insightistic_claude_key' );
		if ( is_wp_error( $key ) ) {
			return $key;
		}

		$model = get_option( 'insightistic_claude_model', 'claude-haiku-4-5-20251001' );

		$full_prompt = $prompt . "\n\nIMPORTANT: Respond ONLY with valid JSON. Do not include any text outside the JSON object.";

		$response = wp_remote_post(
			'https://api.anthropic.com/v1/messages',
			array(
				'timeout' => 45,
				'headers' => array(
					'x-api-key'         => $key,
					'anthropic-version' => '2023-06-01',
					'Content-Type'      => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'      => $model,
						'system'     => $this->system_prompt(),
						'messages'   => array(
							array( 'role' => 'user', 'content' => $full_prompt ),
						),
						'max_tokens' => 2000,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( isset( $body['error'] ) ) {
			return new WP_Error( 'claude_error', $body['error']['message'] );
		}

		$text = $body['content'][0]['text'] ?? '';
		if ( ! $text ) {
			return new WP_Error( 'claude_empty', __( 'Claude returned an empty response.', 'insightistic' ) );
		}

		$text = preg_replace( '/^```json\s*/i', '', $text );
		$text = preg_replace( '/```\s*$/', '', $text );
		return trim( $text );
	}

	/* ------------------------------------------------------------------ */
	/* Prompt Builders                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * System prompt sent to all AI providers.
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

	/* ------------------------------------------------------------------ */
	/* Response Rendering                                                   */
	/* ------------------------------------------------------------------ */

	/**
	 * Render AI insights as HTML.
	 *
	 * @param array  $data     Parsed AI JSON response.
	 * @param string $provider Provider key.
	 * @return string HTML.
	 */
	private function render_insights( $data, $provider ) {
		$provider_labels = array(
			'openai'             => 'OpenAI',
			'gemini'             => 'Google Gemini',
			'openrouter'         => 'OpenRouter',
			'claude'             => 'Anthropic Claude',
			'groq'               => 'Groq',
			'insightistic_cloud' => 'Insightistic Cloud AI',
		);
		$provider_label  = $provider_labels[ $provider ] ?? ucfirst( $provider );

		$engine_label = '';
		if ( 'insightistic_cloud' === $provider ) {
			$engine_label = 'hermes-seo' === get_option( 'insightistic_insightistic_cloud_model', 'ollama-balanced' )
				? __( 'SEO Specialist (Hermes)', 'insightistic' )
				: __( 'Balanced (Ollama)', 'insightistic' );
		}

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
				<?php esc_html_e( 'AI analysis generated by', 'insightistic' ); ?> <?php echo esc_html( $provider_label ); ?> &bull; <a href="https://wordpressistic.com" target="_blank" rel="noopener noreferrer">Insightistic</a>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Retrieve and decrypt a stored API key.
	 *
	 * @param string $option_name WP option name.
	 * @return string|WP_Error
	 */
	private function get_key( $option_name ) {
		$enc = get_option( $option_name );
		if ( ! $enc ) {
			return new WP_Error( 'no_key', __( 'API key not configured. Please add it in Settings  AI Insights.', 'insightistic' ) );
		}
		$key = Insightistic_Encryption::decrypt( $enc );
		if ( ! $key ) {
			return new WP_Error( 'bad_key', __( 'Failed to read the API key. Please re-save it in Settings.', 'insightistic' ) );
		}
		return $key;
	}

	/**
	 * Extract text content from an OpenAI-compatible API response.
	 *
	 * @param array|WP_Error $response wp_remote_post result.
	 * @return string|WP_Error
	 */
	private function extract_openai_content( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			$body      = json_decode( wp_remote_retrieve_body( $response ), true );
			$error_msg = $body['error']['message'] ?? "API error: HTTP {$status}";
			return new WP_Error( 'api_error', $error_msg );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( isset( $body['error'] ) ) {
			return new WP_Error( 'api_error', $body['error']['message'] );
		}

		$text = $body['choices'][0]['message']['content'] ?? '';
		if ( is_array( $text ) ) {
			// Some providers return structured content arrays.
			$text = wp_json_encode( $text );
		}
		if ( ! $text ) {
			return new WP_Error( 'empty_response', __( 'The AI returned an empty response. Please try again.', 'insightistic' ) );
		}

		$text = preg_replace( '/^```json\s*/i', '', $text );
		$text = preg_replace( '/```\s*$/', '', $text );
		return trim( $text );
	}

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



