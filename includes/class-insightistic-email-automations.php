<?php
/**
 * Email automations addon.
 *
 * @package Insightistic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends scheduled analytics digests from the same data payload used by the dashboard.
 */
class Insightistic_Email_Automations {

	const CRON_HOOK = 'insightistic_send_email_automation';

	/**
	 * Register hooks.
	 */
	public function init() {
		add_filter( 'cron_schedules', array( $this, 'add_custom_schedules' ) );
		add_action( self::CRON_HOOK, array( $this, 'send_email_digest' ) );
		$this->maybe_schedule_event();
	}

	/**
	 * Add monthly recurrence support.
	 *
	 * @param array $schedules Cron schedules.
	 * @return array
	 */
	public function add_custom_schedules( $schedules ) {
		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = array(
				'interval' => WEEK_IN_SECONDS,
				'display'  => __( 'Once Weekly', 'insightistic' ),
			);
		}

		if ( ! isset( $schedules['insightistic_monthly'] ) ) {
			$schedules['insightistic_monthly'] = array(
				'interval' => 30 * DAY_IN_SECONDS,
				'display'  => __( 'Once Monthly (Insightistic)', 'insightistic' ),
			);
		}
		return $schedules;
	}

	/**
	 * Schedule, reschedule, or clear the digest cron event.
	 */
	public function maybe_schedule_event() {
		$config  = $this->get_config();
		$enabled = ! empty( $config['enabled'] );
		$next    = wp_next_scheduled( self::CRON_HOOK );

		if ( ! $enabled ) {
			if ( $next ) {
				wp_clear_scheduled_hook( self::CRON_HOOK );
			}
			return;
		}

		$desired_recurrence = $this->get_recurrence( $config['frequency'] );
		$desired_timestamp  = $this->calculate_next_run( $config );
		$current            = wp_get_scheduled_event( self::CRON_HOOK );
		$current_recurrence = $current ? $current->schedule : '';

		if ( ! $next || $current_recurrence !== $desired_recurrence ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
			wp_schedule_event( $desired_timestamp, $desired_recurrence, self::CRON_HOOK );
			return;
		}

		// If the saved time/day changed, rebuild the event instead of waiting for the old slot.
		if ( absint( $next ) > 0 && absint( get_option( 'insightistic_email_next_run', 0 ) ) !== absint( $next ) ) {
			update_option( 'insightistic_email_next_run', absint( $next ) );
		}
	}

	/**
	 * Send the scheduled digest.
	 *
	 * @return bool
	 */
	public function send_email_digest() {
		$config = $this->get_config();
		if ( empty( $config['enabled'] ) ) {
			return false;
		}

		return $this->send_now( false );
	}

	/**
	 * Send a digest immediately.
	 *
	 * @param bool $is_test Whether this is a manual test email.
	 * @return bool|WP_Error
	 */
	/**
	 * Render the digest HTML the same way `send_now()` does, but without
	 * actually calling wp_mail(). Used by the Addons "Preview Digest" button
	 * so users can verify content before sending.
	 *
	 * @return string|WP_Error
	 */
	public function build_preview_html() {
		$config = $this->get_config();
		$days   = $this->get_days_for_frequency( $config['frequency'] );
		$data   = $this->get_analytics_payload( $days );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return $this->build_summary( $data, $days, true );
	}

	public function send_now( $is_test = true ) {
		$config     = $this->get_config();
		$recipients = $this->sanitize_recipients( $config['recipients'] );

		if ( empty( $recipients ) ) {
			return new WP_Error( 'insightistic_email_no_recipients', __( 'Add at least one valid recipient email.', 'insightistic' ) );
		}

		$days      = $this->get_days_for_frequency( $config['frequency'] );
		$analytics = $this->get_analytics_payload( $days );
		if ( is_wp_error( $analytics ) ) {
			return $analytics;
		}

		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$prefix    = $is_test ? __( '[Test]', 'insightistic' ) . ' ' : '';
		$subject   = sprintf(
			/* translators: 1: optional test prefix, 2: site name, 3: days */
			__( '%1$s[%2$s] Insightistic %3$d-Day Growth Digest', 'insightistic' ),
			$prefix,
			$site_name,
			$days
		);

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$sent    = wp_mail( $recipients, $subject, $this->build_summary( $analytics, $days, $is_test ), $headers );

		if ( $sent ) {
			update_option( 'insightistic_email_last_sent', time() );
		}

		return $sent;
	}

	/**
	 * Get complete analytics payload, using cache first and live fetch when needed.
	 *
	 * @param int $days Days to include.
	 * @return array|WP_Error
	 */
	private function get_analytics_payload( $days ) {
		$property_id = get_option( 'insightistic_property_id' );
		if ( ! $property_id ) {
			return new WP_Error( 'insightistic_email_missing_ga4', __( 'Connect GA4 before enabling email digests.', 'insightistic' ) );
		}

		if ( ! class_exists( 'Insightistic_GA' ) ) {
			return new WP_Error( 'insightistic_email_missing_ga_class', __( 'Insightistic GA4 module is not loaded.', 'insightistic' ) );
		}

		$data = ( new Insightistic_GA() )->get_dashboard_data( $days, false );
		if ( is_wp_error( $data ) ) {
			error_log( 'Insightistic email digest failed: ' . $data->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		return $data;
	}

	/**
	 * Build the HTML email body.
	 *
	 * @param array $data Analytics payload.
	 * @param int   $days Days included.
	 * @param bool  $is_test Whether this is a test send.
	 * @return string
	 */
	private function build_summary( $data, $days, $is_test = false ) {
		$site_name     = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$site_url      = home_url( '/' );
		$overview      = is_array( $data ) && ! empty( $data['overview'] ) ? $data['overview'] : array();
		$channels      = is_array( $data ) && ! empty( $data['channels'] ) ? array_slice( $data['channels'], 0, 5 ) : array();
		$pages         = is_array( $data ) && ! empty( $data['pages'] ) ? array_slice( $data['pages'], 0, 5 ) : array();
		$posts         = is_array( $data ) && ! empty( $data['top_posts'] ) ? array_slice( $data['top_posts'], 0, 5 ) : array();
		$opportunities = $this->build_opportunities( $overview, $channels, $pages, $posts );
		$seo_summary   = $this->render_seo_summary( is_array( $data ) ? $data : array() );

		ob_start();
		?>
		<div style="margin:0;padding:0;background:#f3f4f6;color:#111827;font-family:Arial,Helvetica,sans-serif;">
			<div style="max-width:760px;margin:0 auto;padding:28px 16px;">
				<div style="background:#0F2044;color:#ffffff;border-radius:14px 14px 0 0;padding:26px 28px;">
					<p style="margin:0 0 8px;color:#00C857;font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;"><?php esc_html_e( 'Insightistic Analytics Digest', 'insightistic' ); ?></p>
					<h1 style="margin:0;font-size:28px;line-height:1.2;"><?php echo esc_html( $site_name ); ?></h1>
					<p style="margin:10px 0 0;color:#d1d5db;font-size:14px;">
						<?php
						/* translators: %d: number of days in the reporting window. */
						echo esc_html( sprintf( __( 'Last %d days performance snapshot', 'insightistic' ), $days ) );
						?>
						<?php echo $is_test ? ' - ' . esc_html__( 'test email', 'insightistic' ) : ''; ?>
					</p>
				</div>

				<div style="background:#ffffff;border:1px solid #e5e7eb;border-top:0;padding:24px 28px;">
					<?php echo wp_kses_post( $this->render_kpi_grid( $overview ) ); ?>
					<?php if ( '' !== $seo_summary ) : ?>
						<?php echo wp_kses_post( $seo_summary ); ?>
					<?php endif; ?>

					<h2 style="margin:28px 0 12px;font-size:18px;color:#0F2044;"><?php esc_html_e( 'What changed', 'insightistic' ); ?></h2>
					<?php echo wp_kses_post( $this->render_opportunities( $opportunities ) ); ?>

					<?php if ( ! empty( $channels ) ) : ?>
						<h2 style="margin:28px 0 12px;font-size:18px;color:#0F2044;"><?php esc_html_e( 'Top channels', 'insightistic' ); ?></h2>
						<?php echo wp_kses_post( $this->render_simple_table( $channels, array( 'channel', 'sessions', 'users', 'bounce', 'share' ) ) ); ?>
					<?php endif; ?>

					<?php if ( ! empty( $pages ) ) : ?>
						<h2 style="margin:28px 0 12px;font-size:18px;color:#0F2044;"><?php esc_html_e( 'Top pages', 'insightistic' ); ?></h2>
						<?php echo wp_kses_post( $this->render_simple_table( $pages, array( 'title', 'views', 'bounce', 'avg_time', 'share' ) ) ); ?>
					<?php endif; ?>

					<?php if ( ! empty( $posts ) ) : ?>
						<h2 style="margin:28px 0 12px;font-size:18px;color:#0F2044;"><?php esc_html_e( 'Top content', 'insightistic' ); ?></h2>
						<?php echo wp_kses_post( $this->render_simple_table( $posts, array( 'title', 'views', 'avg_time' ) ) ); ?>
					<?php endif; ?>

					<div style="margin-top:28px;padding:18px;border-radius:10px;background:#f9fafb;border:1px solid #e5e7eb;">
						<p style="margin:0 0 12px;font-weight:700;color:#111827;"><?php esc_html_e( 'Recommended next action', 'insightistic' ); ?></p>
						<p style="margin:0;color:#374151;font-size:14px;line-height:1.6;"><?php echo esc_html( $this->primary_recommendation( $overview, $channels, $pages ) ); ?></p>
					</div>

					<p style="margin:26px 0 0;">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=insightistic' ) ); ?>" style="display:inline-block;background:#00C857;color:#07111f;text-decoration:none;font-weight:700;padding:12px 18px;border-radius:8px;"><?php esc_html_e( 'Open Insightistic Dashboard', 'insightistic' ); ?></a>
					</p>
				</div>

				<div style="background:#ffffff;border:1px solid #e5e7eb;border-top:0;border-radius:0 0 14px 14px;padding:18px 28px;color:#6b7280;font-size:12px;">
					<?php echo esc_html( $site_url ); ?> - <?php esc_html_e( 'Sent by Insightistic Email Automations', 'insightistic' ); ?>
				</div>
			</div>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render optional Search Console/PageSpeed/AI data as presentation cards.
	 * Some Brother Tours payloads arrive from the operations bridge rather than
	 * the GA4 dashboard and may contain JSON strings. Decode those values and
	 * expose only named, escaped metrics so raw JSON never reaches an email.
	 *
	 * @param array $data Analytics payload.
	 * @return string
	 */
	private function render_seo_summary( $data ) {
		$gsc = $this->decode_email_value( $data['gsc'] ?? $data['gsc_7d'] ?? $data['search_console'] ?? $data['searchConsole'] ?? array() );
		$psi = $this->decode_email_value( $data['pagespeed'] ?? $data['psi'] ?? $data['page_speed'] ?? $data['pageSpeed'] ?? array() );
		$ai  = $this->decode_email_value( $data['ai'] ?? $data['ai_analysis'] ?? $data['aiSummary'] ?? array() );
		$ai_error = $data['ai_error'] ?? $data['analysis_error'] ?? ( is_array( $ai ) ? ( $ai['error'] ?? '' ) : '' );
		$summary_text = (string) ( $data['summary'] ?? $data['analysis'] ?? '' );
		if ( '' === $ai_error && preg_match( '/AI analysis failed\s*:\s*(.+)$/i', $summary_text, $match ) ) {
			$ai_error = $match[1];
		}
		if ( is_string( $ai ) && preg_match( '/AI analysis failed\s*:\s*(.+)$/i', $ai, $match ) ) {
			$ai_error = $match[1];
			$ai       = array();
		}

		$gsc_rows = array();
		if ( is_array( $gsc ) ) {
			$is_list   = empty( $gsc ) || array_keys( $gsc ) === range( 0, count( $gsc ) - 1 );
			$gsc_rows = isset( $gsc['rows'] ) && is_array( $gsc['rows'] ) ? $gsc['rows'] : ( $is_list ? $gsc : array() );
		}
		$gsc_clicks = 0;
		$gsc_impressions = 0;
		$position_total = 0.0;
		$position_count = 0;
		foreach ( $gsc_rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$gsc_clicks      += (float) ( $row['clicks'] ?? 0 );
			$gsc_impressions += (float) ( $row['impressions'] ?? 0 );
			if ( is_numeric( $row['avg_position'] ?? null ) ) {
				$position_total += (float) $row['avg_position'];
				++$position_count;
			}
		}
		$gsc_ctr = $gsc_impressions > 0 ? ( $gsc_clicks / $gsc_impressions ) * 100 : null;
		$psi = is_array( $psi ) ? $psi : array();
		$has_seo = $gsc_rows || $psi || $ai_error || $ai;
		if ( ! $has_seo ) {
			return '';
		}

		$clicks_label      = $this->email_metric_number( $gsc_clicks );
		$impressions_label = $this->email_metric_number( $gsc_impressions );
		$ctr_label         = null === $gsc_ctr ? '—' : $this->email_metric_number( $gsc_ctr, 2 ) . '%';
		$position_label    = $position_count > 0 ? $this->email_metric_number( $position_total / $position_count, 1 ) : '—';
		$perf_label        = $this->email_metric_text( $psi['perf'] ?? ( $psi['performance'] ?? '—' ) );
		$seo_label         = $this->email_metric_text( $psi['seo'] ?? '—' );
		$lcp_label         = $this->email_metric_text( $psi['lcp'] ?? '—' );
		$cls_label         = $this->email_metric_text( $psi['cls'] ?? '—' );

		ob_start();
		?>
		<div style="margin-top:28px;padding-top:22px;border-top:1px solid #e5e7eb">
			<h2 style="margin:0 0 12px;font-size:18px;color:#0F2044"><?php esc_html_e( 'Search and site health', 'insightistic' ); ?></h2>
			<?php if ( $gsc_rows ) : ?>
			<table role="presentation" style="width:100%;border-collapse:collapse"><tr>
				<?php echo wp_kses_post( $this->email_metric_cell( __( 'Search clicks', 'insightistic' ), $clicks_label, '#0F2044' ) ); ?>
				<?php echo wp_kses_post( $this->email_metric_cell( __( 'Impressions', 'insightistic' ), $impressions_label, '#0F2044' ) ); ?>
				<?php echo wp_kses_post( $this->email_metric_cell( __( 'CTR', 'insightistic' ), $ctr_label, '#047857' ) ); ?>
				<?php echo wp_kses_post( $this->email_metric_cell( __( 'Avg. position', 'insightistic' ), $position_label, '#047857' ) ); ?>
			</tr></table>
			<?php endif; ?>
			<?php if ( $psi ) : ?>
			<h3 style="margin:18px 0 8px;font-size:14px;color:#374151"><?php esc_html_e( 'PageSpeed snapshot', 'insightistic' ); ?></h3>
			<table role="presentation" style="width:100%;border-collapse:collapse"><tr>
				<?php echo wp_kses_post( $this->email_metric_cell( __( 'Performance', 'insightistic' ), $perf_label, '#047857' ) ); ?>
				<?php echo wp_kses_post( $this->email_metric_cell( __( 'SEO', 'insightistic' ), $seo_label, '#047857' ) ); ?>
				<?php echo wp_kses_post( $this->email_metric_cell( __( 'LCP', 'insightistic' ), $lcp_label, '#0F2044' ) ); ?>
				<?php echo wp_kses_post( $this->email_metric_cell( __( 'CLS', 'insightistic' ), $cls_label, '#0F2044' ) ); ?>
			</tr></table>
			<?php endif; ?>
			<?php if ( $ai_error ) : ?>
			<div style="margin-top:18px;padding:14px 16px;border-radius:10px;background:#fffbeb;border:1px solid #fde68a;color:#92400e"><strong style="display:block;margin-bottom:4px">AI analysis temporarily unavailable</strong><span style="font-size:13px;color:#a16207">The measured data above is still complete. Insightistic will retry the AI explanation on the next run.</span></div>
			<?php elseif ( is_array( $ai ) && ! empty( $ai ) ) : ?>
			<div style="margin-top:18px;padding:14px 16px;border-radius:10px;background:#f0fdf4;border:1px solid #bbf7d0;color:#166534"><strong style="display:block;margin-bottom:4px">AI growth note</strong><span style="font-size:13px;color:#166534"><?php echo esc_html( (string) ( $ai['summary'] ?? $ai['insight'] ?? $ai['text'] ?? '' ) ); ?></span></div>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * One email metric card. Returned markup is escaped.
	 *
	 * @param string $label Visible label.
	 * @param string $value Visible value.
	 * @param string $tone  Hex color.
	 * @return string
	 */
	private function email_metric_cell( $label, $value, $tone = '#111827' ) {
		return '<td style="width:25%;padding:5px;vertical-align:top"><div style="border:1px solid #e5e7eb;border-radius:10px;padding:13px;background:#ffffff"><div style="font-size:11px;color:#6b7280;margin-bottom:5px">' . esc_html( $label ) . '</div><div style="font-size:20px;font-weight:800;line-height:1.2;color:' . esc_attr( $tone ) . '">' . esc_html( $value ) . '</div></div></td>';
	}

	/**
	 * Format a numeric email metric, or an em dash when it is not numeric.
	 *
	 * @param mixed $value    Raw value.
	 * @param int   $decimals Decimal places.
	 * @return string
	 */
	private function email_metric_number( $value, $decimals = 0 ) {
		if ( ! is_numeric( $value ) ) {
			return '—';
		}
		return number_format_i18n( (float) $value, $decimals );
	}

	/**
	 * Flatten a PageSpeed field to a single display string.
	 *
	 * @param mixed $value Raw value, including nested score arrays.
	 * @return string
	 */
	private function email_metric_text( $value ) {
		if ( is_array( $value ) ) {
			foreach ( array( 'display', 'displayValue', 'value', 'score' ) as $key ) {
				if ( isset( $value[ $key ] ) && is_scalar( $value[ $key ] ) ) {
					$value = $value[ $key ];
					break;
				}
			}
		}
		if ( ! is_scalar( $value ) ) {
			return '—';
		}
		$text = trim( (string) $value );
		return '' === $text ? '—' : $text;
	}

	/** @return mixed */
	private function decode_email_value( $value ) {
		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			return JSON_ERROR_NONE === json_last_error() ? $decoded : $value;
		}
		return $value;
	}

	/**
	 * Render KPI cards for email clients.
	 *
	 * @param array $overview Overview metrics.
	 * @return string
	 */
	private function render_kpi_grid( $overview ) {
		$cards = array(
			'sessions'     => __( 'Sessions', 'insightistic' ),
			'unique_users' => __( 'Users', 'insightistic' ),
			'pageviews'    => __( 'Pageviews', 'insightistic' ),
			'revenue'      => __( 'Revenue', 'insightistic' ),
			'transactions' => __( 'Transactions', 'insightistic' ),
			'bounce_rate'  => __( 'Bounce Rate', 'insightistic' ),
		);

		ob_start();
		?>
		<table role="presentation" style="width:100%;border-collapse:collapse;">
			<tr>
				<?php
				$i = 0;
				foreach ( $cards as $key => $label ) :
					$value  = $overview[ $key ]['value'] ?? 'N/A';
					$change = $overview[ $key ]['change'] ?? null;
					if ( 'revenue' === $key && is_numeric( $value ) ) {
						$value = '$' . number_format_i18n( (float) $value, 2 );
					} elseif ( in_array( $key, array( 'sessions', 'unique_users', 'pageviews', 'transactions' ), true ) && is_numeric( $value ) ) {
						$value = number_format_i18n( (float) $value );
					} elseif ( 'bounce_rate' === $key && is_numeric( $value ) ) {
						$value = number_format_i18n( (float) $value, 1 ) . '%';
					}
					$change_text  = null === $change ? '' : ( (float) $change >= 0 ? '+' : '' ) . $change . '%';
					$change_color = (float) $change >= 0 ? '#047857' : '#b91c1c';
					?>
					<td style="width:33.333%;padding:6px;vertical-align:top;">
						<div style="border:1px solid #e5e7eb;border-radius:10px;padding:16px;background:#ffffff;">
							<div style="font-size:12px;color:#6b7280;margin-bottom:6px;"><?php echo esc_html( $label ); ?></div>
							<div style="font-size:24px;font-weight:800;color:#111827;line-height:1.2;"><?php echo esc_html( (string) $value ); ?></div>
							<?php if ( '' !== $change_text ) : ?>
								<div style="margin-top:6px;font-size:12px;color:<?php echo esc_attr( $change_color ); ?>;"><?php echo esc_html( $change_text ); ?> <?php esc_html_e( 'vs previous period', 'insightistic' ); ?></div>
							<?php endif; ?>
						</div>
					</td>
					<?php
					++$i;
					if ( 0 === $i % 3 && $i < count( $cards ) ) {
						echo '</tr><tr>';
					}
				endforeach;
				?>
			</tr>
		</table>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Build marketing opportunities from available metrics.
	 *
	 * @param array $overview Overview metrics.
	 * @param array $channels Channels.
	 * @param array $pages Pages.
	 * @param array $posts Posts.
	 * @return array
	 */
	private function build_opportunities( $overview, $channels, $pages, $posts ) {
		$items  = array();
		$bounce = isset( $overview['bounce_rate']['value'] ) ? (float) $overview['bounce_rate']['value'] : 0;
		if ( $bounce >= 60 ) {
			$items[] = __( 'Bounce rate is high. Review top landing pages and strengthen above-the-fold offer clarity.', 'insightistic' );
		}

		$sessions_change = isset( $overview['sessions']['change'] ) ? (float) $overview['sessions']['change'] : 0;
		if ( $sessions_change < 0 ) {
			$items[] = __( 'Sessions declined versus the previous period. Check Search Console queries and refresh declining pages.', 'insightistic' );
		}

		if ( ! empty( $channels[0]['channel'] ) ) {
			$items[] = sprintf(
				/* translators: %s: channel name */
				__( '%s is your leading acquisition channel. Build one focused campaign around that traffic source this week.', 'insightistic' ),
				$channels[0]['channel']
			);
		}

		if ( ! empty( $pages[0]['title'] ) ) {
			$items[] = sprintf(
				/* translators: %s: page title */
				__( 'Your strongest page is "%s". Add a clearer conversion action there before adding more traffic.', 'insightistic' ),
				$pages[0]['title']
			);
		}

		if ( ! empty( $posts[0]['title'] ) ) {
			$items[] = sprintf(
				/* translators: %s: post title */
				__( 'Top content: "%s". Repurpose it into email, social, and internal links.', 'insightistic' ),
				$posts[0]['title']
			);
		}

		if ( empty( $items ) ) {
			$items[] = __( 'Traffic is stable. Focus on conversion actions: calls to action, lead capture, and internal links on your top pages.', 'insightistic' );
		}

		return array_slice( $items, 0, 5 );
	}

	/**
	 * Render opportunities list.
	 *
	 * @param array $items Opportunities.
	 * @return string
	 */
	private function render_opportunities( $items ) {
		ob_start();
		?>
		<ol style="margin:0;padding-left:22px;color:#374151;font-size:14px;line-height:1.7;">
			<?php foreach ( $items as $item ) : ?>
				<li style="margin:0 0 8px;"><?php echo esc_html( $item ); ?></li>
			<?php endforeach; ?>
		</ol>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Render a compact table for email clients.
	 *
	 * @param array $rows Rows.
	 * @param array $keys Keys to show.
	 * @return string
	 */
	private function render_simple_table( $rows, $keys ) {
		ob_start();
		?>
		<table style="width:100%;border-collapse:collapse;font-size:13px;">
			<thead>
				<tr>
					<?php foreach ( $keys as $key ) : ?>
						<th style="text-align:left;padding:10px;border-bottom:1px solid #e5e7eb;color:#6b7280;"><?php echo esc_html( ucwords( str_replace( '_', ' ', $key ) ) ); ?></th>
					<?php endforeach; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<?php foreach ( $keys as $key ) : ?>
							<td style="padding:10px;border-bottom:1px solid #f3f4f6;color:#111827;">
								<?php echo esc_html( $this->format_table_value( $row[ $key ] ?? '' ) ); ?>
							</td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Format table values for email output.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private function format_table_value( $value ) {
		if ( is_float( $value ) || is_int( $value ) ) {
			return (string) number_format_i18n( $value, is_float( $value ) ? 1 : 0 );
		}
		return (string) $value;
	}

	/**
	 * Pick one primary recommendation.
	 *
	 * @param array $overview Overview metrics.
	 * @param array $channels Channels.
	 * @param array $pages Pages.
	 * @return string
	 */
	private function primary_recommendation( $overview, $channels, $pages ) {
		$revenue = isset( $overview['revenue']['value'] ) ? (float) $overview['revenue']['value'] : 0;
		if ( $revenue > 0 && ! empty( $channels[0]['channel'] ) ) {
			return sprintf(
				/* translators: %s: channel name */
				__( 'Protect your highest-value traffic source first: review %s landing paths and remove conversion friction.', 'insightistic' ),
				$channels[0]['channel']
			);
		}

		if ( ! empty( $pages[0]['title'] ) ) {
			return sprintf(
				/* translators: %s: page title */
				__( 'Start with "%s": add one strong offer, one internal link path, and one measurable call to action.', 'insightistic' ),
				$pages[0]['title']
			);
		}

		return __( 'Load fresh analytics in the dashboard, then use this digest to review traffic, content, and conversion trends with your team.', 'insightistic' );
	}

	/**
	 * Validate recipient list.
	 *
	 * @param string $raw Raw recipients.
	 * @return array
	 */
	private function sanitize_recipients( $raw ) {
		$emails = array_filter( array_map( 'trim', explode( ',', (string) $raw ) ) );
		$valid  = array();
		foreach ( $emails as $email ) {
			$email = sanitize_email( $email );
			if ( is_email( $email ) ) {
				$valid[] = $email;
			}
		}
		return array_values( array_unique( $valid ) );
	}

	/**
	 * Get cron recurrence key.
	 *
	 * @param string $frequency Frequency.
	 * @return string
	 */
	private function get_recurrence( $frequency ) {
		if ( 'monthly' === $frequency ) {
			return 'insightistic_monthly';
		}
		return 'daily' === $frequency ? 'daily' : 'weekly';
	}

	/**
	 * Map frequency to reporting window.
	 *
	 * @param string $frequency Frequency.
	 * @return int
	 */
	private function get_days_for_frequency( $frequency ) {
		if ( 'daily' === $frequency ) {
			return 7;
		}
		if ( 'monthly' === $frequency ) {
			return 30;
		}
		return 28;
	}

	/**
	 * Calculate the next run timestamp in the site's timezone.
	 *
	 * @param array $config Config.
	 * @return int
	 */
	private function calculate_next_run( $config ) {
		$frequency = $config['frequency'];
		$time      = preg_match( '/^\d{2}:\d{2}$/', $config['time'] ) ? $config['time'] : '09:00';
		$tz        = wp_timezone();
		$now       = new DateTimeImmutable( 'now', $tz );
		$target    = DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $now->format( 'Y-m-d' ) . ' ' . $time, $tz );

		if ( ! $target ) {
			$target = $now->setTime( 9, 0 );
		}

		if ( 'weekly' === $frequency ) {
			$day    = $this->normalize_day( $config['day'] );
			$target = new DateTimeImmutable( 'next ' . $day . ' ' . $time, $tz );
			if ( strtolower( $now->format( 'l' ) ) === $day && $now < DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $now->format( 'Y-m-d' ) . ' ' . $time, $tz ) ) {
				$target = DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $now->format( 'Y-m-d' ) . ' ' . $time, $tz );
			}
		} elseif ( 'monthly' === $frequency ) {
			$target = DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $now->format( 'Y-m-01' ) . ' ' . $time, $tz );
			if ( $target <= $now ) {
				$target = $target->modify( 'first day of next month' );
			}
		} elseif ( $target <= $now ) {
			$target = $target->modify( '+1 day' );
		}

		$timestamp = $target ? $target->getTimestamp() : time() + HOUR_IN_SECONDS;
		update_option( 'insightistic_email_next_run', $timestamp );
		return $timestamp;
	}

	/**
	 * Normalize weekday names.
	 *
	 * @param string $day Day.
	 * @return string
	 */
	private function normalize_day( $day ) {
		$day     = strtolower( sanitize_key( $day ) );
		$allowed = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );
		return in_array( $day, $allowed, true ) ? $day : 'monday';
	}

	/**
	 * Get saved configuration.
	 *
	 * @return array
	 */
	private function get_config() {
		$defaults = array(
			'enabled'    => 0,
			'recipients' => get_option( 'admin_email' ),
			'frequency'  => 'weekly',
			'day'        => 'monday',
			'time'       => '09:00',
		);
		$saved    = get_option( 'insightistic_email_automations', array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), $defaults );
	}
}
