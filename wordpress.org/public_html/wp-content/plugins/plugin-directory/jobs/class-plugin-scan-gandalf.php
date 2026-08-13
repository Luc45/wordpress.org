<?php
/**
 * Gandalf publication gate for staged plugin updates.
 *
 * @package WordPressdotorg\Plugin_Directory\Jobs
 */

namespace WordPressdotorg\Plugin_Directory\Jobs;

use WordPressdotorg\Plugin_Directory\CLI\Import;
use WordPressdotorg\Plugin_Directory\Plugin_Directory;
use WordPressdotorg\Plugin_Directory\Zip\Builder;
use WP_Error;
use WP_Http;

/**
 * Scans a staged stable ZIP and publishes only the exact approved bytes.
 *
 * @package WordPressdotorg\Plugin_Directory\Jobs
 */
class Plugin_Scan_Gandalf {

	/** The one staged release candidate that may currently be promoted. */
	const CANDIDATE_META_KEY = '_gandalf_release_candidate';

	/** Callback body hashes keyed by scan ID, used for exact replay handling. */
	const CONSUMED_META_KEY = '_gandalf_scan_consumed';

	/** Verdict hashes already sent to Slack, to avoid duplicate alerts. */
	const NOTIFIED_META_KEY = '_gandalf_scan_notified';

	/** Last dispatch or callback error for quick operator debugging. */
	const LAST_ERROR_META_KEY = '_gandalf_scan_last_error';

	/** Gandalf scan endpoint. */
	const ENDPOINT = 'https://gandalf.wordpress.org/scan';

	/** A score at or above this value holds the candidate. */
	const HOLD_THRESHOLD = 8;

	/**
	 * Dispatch the plugin's authoritative staged release candidate.
	 *
	 * @param \WP_Post $plugin  The plugin post.
	 * @param string   $scan_id The candidate UUIDv4 selected by the cron job.
	 * @return bool Whether the request was accepted.
	 * @throws \UnexpectedValueException When the persisted candidate is malformed.
	 */
	public static function dispatch_candidate( $plugin, $scan_id ) {
		if ( ! defined( 'WP_GANDALF_SCAN_SHARED_SECRET' ) || ! WP_GANDALF_SCAN_SHARED_SECRET ) {
			self::record_last_error( $plugin, 'dispatch_not_configured', 'Gandalf shared secret is not configured.', $scan_id );
			return false;
		}

		$candidate = get_post_meta( $plugin->ID, self::CANDIDATE_META_KEY, true );
		if ( ! is_array( $candidate ) || ( $candidate['scan_id'] ?? '' ) !== $scan_id ) {
			return true; // The queued candidate was superseded; there is nothing to retry.
		}
		if ( 'approved' === ( $candidate['state'] ?? '' ) ) {
			return true;
		}
		if ( ! in_array( $candidate['state'] ?? '', [ 'pending', 'scanning' ], true ) ) {
			throw new \UnexpectedValueException( 'Gandalf release candidate has an invalid dispatch state.' );
		}

		$artifact = $candidate['artifact'] ?? null;
		if (
			! is_array( $artifact ) ||
			! wp_is_uuid( $scan_id, 4 ) ||
			! is_string( $candidate['version'] ?? null ) ||
			'' === $candidate['version'] ||
			! is_string( $candidate['release_ref'] ?? null ) ||
			'' === $candidate['release_ref'] ||
			! is_string( $artifact['current_zip_url'] ?? null ) ||
			'' === $artifact['current_zip_url'] ||
			! preg_match( '/^[a-f0-9]{64}$/', $artifact['current_zip_sha256'] ?? '' ) ||
			! is_int( $candidate['requested_at'] ?? null ) ||
			$candidate['requested_at'] < 1
		) {
			throw new \UnexpectedValueException( 'Gandalf release candidate is missing required artifact identity.' );
		}

		$previous = [
			$candidate['public_version'] ?? null,
			$candidate['public_release_ref'] ?? null,
			$candidate['public_zip_url'] ?? null,
		];
		$has_previous = array_map(
			static function ( $value ) {
				if ( null === $value ) {
					return false;
				}
				if ( ! is_string( $value ) || '' === $value ) {
					throw new \UnexpectedValueException( 'Gandalf release candidate has invalid public baseline identity.' );
				}
				return true;
			},
			$previous
		);
		if ( count( array_filter( $has_previous ) ) && count( array_filter( $has_previous ) ) !== 3 ) {
			throw new \UnexpectedValueException( 'Gandalf release candidate has partial public baseline identity.' );
		}

		return self::dispatch(
			$plugin,
			[
				'scan_id'              => $scan_id,
				'subject_type'         => 'plugin',
				'slug'                 => $plugin->post_name,
				'version'              => $candidate['version'],
				'release_ref'          => $candidate['release_ref'],
				'current_zip_url'      => $artifact['current_zip_url'],
				'expected_current_zip_sha256' => $artifact['current_zip_sha256'],
				'previous_version'     => $previous[0],
				'previous_release_ref' => $previous[1],
				'previous_zip_url'     => $previous[2],
				'callback_url'         => rest_url( 'plugins/v1/plugin/' . $plugin->post_name . '/gandalf-scan' ),
				'requested_at'         => (int) $candidate['requested_at'],
			]
		);
	}

	/**
	 * POST a queued scan request to Gandalf.
	 *
	 * @param \WP_Post $plugin       The plugin post.
	 * @param array    $request_data The Gandalf scan request data.
	 * @return bool Whether the request was accepted.
	 */
	public static function dispatch( $plugin, $request_data ) {
		if ( ! defined( 'WP_GANDALF_SCAN_SHARED_SECRET' ) || ! WP_GANDALF_SCAN_SHARED_SECRET ) {
			return false;
		}

		$candidate = get_post_meta( $plugin->ID, self::CANDIDATE_META_KEY, true );
		if (
			! is_array( $candidate ) ||
			( $candidate['scan_id'] ?? '' ) !== $request_data['scan_id'] ||
			! in_array( $candidate['state'] ?? '', [ 'pending', 'scanning' ], true )
		) {
			return false;
		}

		$response = wp_safe_remote_post(
			self::ENDPOINT,
			[
				'timeout'    => 15,
				'user-agent' => 'WordPress.org Plugin Directory Gandalf Scan',
				'headers'    => [
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . WP_GANDALF_SCAN_SHARED_SECRET,
					'Content-Type'  => 'application/json',
				],
				'body'       => wp_json_encode( $request_data ),
			]
		);

		if ( is_wp_error( $response ) ) {
			return self::dispatch_failed( $plugin, $request_data, $response->get_error_message(), 'dispatch_wp_error' );
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( $response_code < 200 || $response_code >= 300 ) {
			return self::dispatch_failed( $plugin, $request_data, sprintf( 'Gandalf returned HTTP %d.', $response_code ), 'dispatch_http_error' );
		}

		$response_data = json_decode( wp_remote_retrieve_body( $response ), true );
		$response_keys = is_array( $response_data ) ? array_keys( $response_data ) : [];
		sort( $response_keys );
		if (
			! is_array( $response_data ) ||
			[ 'accepted_at', 'scan_id' ] !== $response_keys ||
			( $response_data['scan_id'] ?? '' ) !== $request_data['scan_id'] ||
			! is_int( $response_data['accepted_at'] ) ||
			$response_data['accepted_at'] < 0
		) {
			return self::dispatch_failed( $plugin, $request_data, 'Gandalf accepted the scan with an invalid response body.', 'dispatch_ack_invalid' );
		}

		if ( 'pending' === $candidate['state'] ) {
			$scanning          = $candidate;
			$scanning['state'] = 'scanning';
			if (
				! update_post_meta( $plugin->ID, self::CANDIDATE_META_KEY, wp_slash( $scanning ), $candidate ) &&
				get_post_meta( $plugin->ID, self::CANDIDATE_META_KEY, true ) !== $scanning
			) {
				return false;
			}
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Routed to the error log via E_USER_NOTICE; raw is fine.
		trigger_error( sprintf( 'Dispatched Gandalf scan %s for %s.', $request_data['scan_id'], $plugin->post_name ), E_USER_NOTICE );
		return true;
	}

	/**
	 * Handle a completed or failed scan callback.
	 *
	 * @param \WP_Post $plugin The plugin post.
	 * @param array    $data      The Gandalf callback data.
	 * @param string   $body_hash SHA-256 of the exact callback request body.
	 * @return true|WP_Error True on success, or an error when the scan is unknown.
	 * @throws \Throwable When promotion queue rollback fails.
	 */
	public static function handle_callback( $plugin, $data, $body_hash ) {
		$scan_id  = $data['scan_id'];
		$consumed = get_post_meta( $plugin->ID, self::CONSUMED_META_KEY, true ) ?: [];

		if ( isset( $consumed[ $scan_id ] ) ) {
			if ( hash_equals( $consumed[ $scan_id ]['body_hash'] ?? '', $body_hash ) ) {
				return true;
			}

			return new WP_Error( 'conflicting_gandalf_callback', 'A different callback was already consumed for this scan_id.', [ 'status' => WP_Http::CONFLICT ] );
		}

		$candidate = get_post_meta( $plugin->ID, self::CANDIDATE_META_KEY, true );

		if ( ! is_array( $candidate ) || ( $candidate['scan_id'] ?? '' ) !== $scan_id ) {
			$error = new WP_Error( 'unknown_gandalf_scan', 'Unknown Gandalf scan_id.', [ 'status' => WP_Http::BAD_REQUEST ] );
			self::record_invalid_callback( $plugin, $error, $scan_id );
			return $error;
		}

		if ( $data['version'] !== $candidate['version'] || $data['release_ref'] !== $candidate['release_ref'] ) {
			$error = new WP_Error( 'invalid_gandalf_scan', 'Gandalf callback does not match the pending scan.', [ 'status' => WP_Http::BAD_REQUEST ] );
			self::record_invalid_callback( $plugin, $error, $scan_id );
			return $error;
		}
		if ( ! in_array( $candidate['state'] ?? '', [ 'pending', 'scanning' ], true ) ) {
			return new WP_Error( 'gandalf_callback_in_progress', 'This Gandalf callback is already being decided.', [ 'status' => WP_Http::CONFLICT ] );
		}

		$retryable         = $candidate;
		$deciding          = $candidate;
		$deciding['state'] = 'deciding';
		if ( ! update_post_meta( $plugin->ID, self::CANDIDATE_META_KEY, wp_slash( $deciding ), $candidate ) ) {
			return new WP_Error( 'gandalf_callback_in_progress', 'Another worker is deciding this Gandalf callback.', [ 'status' => WP_Http::CONFLICT ] );
		}
		$candidate = $deciding;

		if ( 'completed' === $data['status'] ) {
			if ( $data['findings_count'] > 0 ) {
				self::notify_slack(
					$plugin,
					[
						'version'         => $candidate['version'],
						'release_ref'     => $candidate['release_ref'],
						'findings_count'  => $data['findings_count'],
						'severity_counts' => $data['severity_counts'],
						'verdict_hash'    => $data['verdict_hash'],
						'report_url'      => $data['report_url'],
						'findings'        => is_array( $data['findings'] ?? null ) ? array_filter( $data['findings'], 'is_array' ) : [],
						'max_risk_score'  => $data['max_risk_score'] ?? null,
					]
				);
			}

			if ( $data['max_risk_score'] < self::HOLD_THRESHOLD ) {
				$approved          = $candidate;
				$approved['state'] = 'approved';
				if ( ! update_post_meta( $plugin->ID, self::CANDIDATE_META_KEY, wp_slash( $approved ), $candidate ) ) {
					return new WP_Error( 'gandalf_candidate_superseded', 'The release candidate changed while its callback was being decided.', [ 'status' => WP_Http::CONFLICT ] );
				}
				try {
					Plugin_Import::queue( $plugin->post_name, [ 'gandalf_promotion' => $scan_id ] );
				} catch ( \Throwable $error ) {
					if (
						! update_post_meta( $plugin->ID, self::CANDIDATE_META_KEY, wp_slash( $retryable ), $approved ) &&
						get_post_meta( $plugin->ID, self::CANDIDATE_META_KEY, true ) !== $retryable
					) {
						throw $error;
					}
					return new WP_Error( 'gandalf_promotion_queue_failed', $error->getMessage(), [ 'status' => WP_Http::INTERNAL_SERVER_ERROR ] );
				}
				$outcome = 'approved';
			} else {
				$outcome = 'held';
				delete_post_meta( $plugin->ID, self::CANDIDATE_META_KEY, wp_slash( $candidate ) );
			}
		} else {
			self::record_last_error( $plugin, $data['error']['kind'], $data['error']['message'], $scan_id );
			$outcome = 'failed';
			delete_post_meta( $plugin->ID, self::CANDIDATE_META_KEY, wp_slash( $candidate ) );
		}

		$consumed[ $scan_id ] = [
			'body_hash'   => $body_hash,
			'outcome'     => $outcome,
			'consumed_at' => time(),
		];
		update_post_meta( $plugin->ID, self::CONSUMED_META_KEY, array_slice( $consumed, -100, null, true ) );

		return true;
	}

	/**
	 * Promote a previously approved candidate outside the callback request.
	 *
	 * @param string $plugin_slug The plugin slug.
	 * @param string $scan_id     The approved candidate UUIDv4.
	 * @return true Promotion completed, became inapplicable, or was requeued.
	 * @throws \RuntimeException When retry scheduling fails.
	 */
	public static function cron_promote( $plugin_slug, $scan_id ) {
		$plugin = Plugin_Directory::get_plugin_post( $plugin_slug );
		if ( ! $plugin ) {
			return true;
		}

		$candidate = get_post_meta( $plugin->ID, self::CANDIDATE_META_KEY, true );
		if ( ! is_array( $candidate ) || ( $candidate['scan_id'] ?? '' ) !== $scan_id || 'approved' !== ( $candidate['state'] ?? '' ) ) {
			return true;
		}

		try {
			self::assert_release_may_promote( $plugin, $candidate );

			if ( ! ( new Builder() )->promote( $plugin_slug, $scan_id, $candidate['artifact'], "Gandalf scan {$scan_id}" ) ) {
				throw new \RuntimeException( 'Plugin ZIP storage is not configured.' );
			}

			$current = get_post_meta( $plugin->ID, self::CANDIDATE_META_KEY, true );
			if ( ! is_array( $current ) || ( $current['scan_id'] ?? '' ) !== $scan_id || 'approved' !== ( $current['state'] ?? '' ) ) {
				return true;
			}

			Import::publish_gandalf_candidate( $plugin, $current );
			delete_post_meta( $plugin->ID, self::CANDIDATE_META_KEY, wp_slash( $current ) );
			$remaining = get_post_meta( $plugin->ID, self::CANDIDATE_META_KEY, true );
			if ( is_array( $remaining ) && ( $remaining['scan_id'] ?? '' ) === $scan_id ) {
				throw new \RuntimeException( 'The promoted Gandalf candidate could not be cleared.' );
			}
			return true;
		} catch ( \Throwable $error ) {
			self::record_last_error( $plugin, 'promotion_failed', $error->getMessage(), $scan_id );
			Plugin_Import::queue( $plugin_slug, [ 'gandalf_promotion' => $scan_id ] );
			return true;
		}
	}

	/**
	 * Assert that the approved release still exists.
	 *
	 * @param \WP_Post $plugin    The plugin post.
	 * @param array    $candidate The approved release candidate.
	 * @throws \RuntimeException When the exact release is missing.
	 */
	protected static function assert_release_may_promote( $plugin, $candidate ) {
		$release_key = 'trunk' === $candidate['release_ref']
			? 'trunk@' . $candidate['version']
			: $candidate['release_ref'];

		$release = Plugin_Directory::get_release_by_tag( $plugin, $release_key );
		if ( $release ) {
			return;
		}

		throw new \RuntimeException( 'The approved release is missing.' );
	}

	/**
	 * Record a valid-secret callback that failed validation.
	 *
	 * @param \WP_Post $plugin  The plugin post.
	 * @param WP_Error $error   The validation error.
	 * @param string   $scan_id Optional scan ID.
	 */
	public static function record_invalid_callback( $plugin, $error, $scan_id = '' ) {
		self::record_last_error( $plugin, $error->get_error_code(), $error->get_error_message(), $scan_id );
	}

	/**
	 * Record and report a failed dispatch.
	 *
	 * @param \WP_Post $plugin       The plugin post.
	 * @param array    $request_data The Gandalf scan request data.
	 * @param string   $message      The failure message.
	 * @param string   $kind         The failure kind.
	 * @return false Always false.
	 */
	protected static function dispatch_failed( $plugin, $request_data, $message, $kind ) {
		$scan_id = sanitize_text_field( $request_data['scan_id'] );

		self::record_last_error( $plugin, $kind, $message, $scan_id );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Routed to the error log via E_USER_NOTICE; raw is fine.
		trigger_error( sprintf( 'Failed to dispatch Gandalf scan for %s: %s', $plugin->post_name, $message ), E_USER_NOTICE );
		return false;
	}

	/**
	 * Notify Slack about a Gandalf scan with findings.
	 *
	 * @param \WP_Post $plugin The plugin post.
	 * @param array    $record The completed scan summary.
	 */
	protected static function notify_slack( $plugin, $record ) {
		if ( empty( $record['verdict_hash'] ) ) {
			return;
		}

		$already_notified = get_post_meta( $plugin->ID, self::NOTIFIED_META_KEY, true ) ?: [];
		foreach ( $already_notified as $hash => $time ) {
			if ( $time < time() - MONTH_IN_SECONDS ) {
				unset( $already_notified[ $hash ] );
			}
		}

		if ( isset( $already_notified[ $record['verdict_hash'] ] ) ) {
			update_post_meta( $plugin->ID, self::NOTIFIED_META_KEY, $already_notified );
			return;
		}

		$already_notified[ $record['verdict_hash'] ] = time();
		update_post_meta( $plugin->ID, self::NOTIFIED_META_KEY, $already_notified );

		if ( ! defined( 'PLUGIN_REVIEW_ALERT_SLACK_CHANNEL' ) || ! function_exists( 'slack_dm' ) ) {
			return;
		}

		$active_installs = (int) get_post_meta( $plugin->ID, 'active_installs', true );
		$install_text    = sprintf( '%s+ active installs', number_format_i18n( $active_installs ) );
		if ( $active_installs >= 10000 ) {
			$install_text = "*{$install_text}*";
		}

		// Post titles are stored entity-encoded; the header block is plain text, so only decode.
		$title = html_entity_decode( $plugin->post_title, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( 'closed' === $plugin->post_status ) {
			$title .= ' (closed)';
		}

		$findings_count = (int) $record['findings_count'];
		$top_findings   = self::top_findings( $record['findings'], 5 );

		$meta_links = sprintf(
			'<https://wordpress.org/plugins/wp-admin/post.php?post=%d&action=edit|wp-admin> · <https://wordpress.org/plugins/%s/|Plugin page>',
			$plugin->ID,
			$plugin->post_name
		);
		if ( $record['release_ref'] !== $record['version'] ) {
			// Escaping &, <, and > neutralizes Slack control sequences like <!channel> in untrusted strings.
			$meta_links .= ' · ' . htmlspecialchars( $record['release_ref'], ENT_NOQUOTES );
		}

		$summary = [
			'type' => 'section',
			'text' => [
				'type' => 'mrkdwn',
				'text' => sprintf( '%s · %s', 1 === $findings_count ? '*1 finding*' : "*{$findings_count} findings*", $install_text ),
			],
		];

		$report_url = esc_url_raw( $record['report_url'] ?? '' );
		if ( $report_url ) {
			$summary['accessory'] = [
				'type'  => 'button',
				'text'  => [
					'type' => 'plain_text',
					'text' => 'View report',
				],
				'url'   => $report_url,
				'style' => 'primary',
			];
		}

		$blocks = [
			[
				'type' => 'header',
				'text' => [
					'type' => 'plain_text',
					'text' => self::excerpt( trim( $title . ' ' . $record['version'] ), 150 ),
				],
			],
			$summary,
			[
				'type'     => 'context',
				'elements' => [
					[
						'type' => 'mrkdwn',
						'text' => $meta_links,
					],
				],
			],
		];

		$attachments = [];
		foreach ( $top_findings as $finding ) {
			$risk_score = (float) ( $finding['risk_score'] ?? 0 );

			$attachment = [
				'color'  => self::risk_color( $risk_score ),
				'blocks' => [
					[
						'type' => 'section',
						'text' => [
							'type' => 'mrkdwn',
							'text' => sprintf(
								'*%s* — %s',
								number_format( $risk_score, 1 ),
								htmlspecialchars( self::excerpt( $finding['title'] ?? '', 150 ), ENT_NOQUOTES )
							),
						],
					],
				],
			];

			if ( ! empty( $finding['file_path'] ) ) {
				$attachment['blocks'][] = [
					'type'     => 'context',
					'elements' => [
						[
							'type' => 'mrkdwn',
							'text' => 'File: ' . self::file_link( $plugin, $record['release_ref'], $finding['file_path'], (int) ( $finding['line'] ?? 0 ) ),
						],
					],
				];
			}

			$attachments[] = $attachment;
		}

		$fallback = sprintf(
			'Security scan found %s in %s %s',
			1 === $findings_count ? '1 finding' : "{$findings_count} findings",
			htmlspecialchars( $title, ENT_NOQUOTES ),
			htmlspecialchars( $record['version'], ENT_NOQUOTES )
		);
		if ( isset( $record['max_risk_score'] ) ) {
			$fallback .= sprintf( ' (max risk %s)', number_format( (float) $record['max_risk_score'], 1 ) );
		}

		slack_dm(
			[
				'text'        => $fallback,
				'username'    => 'Gandalf',
				'blocks'      => $blocks,
				'attachments' => $attachments,
			],
			PLUGIN_REVIEW_ALERT_SLACK_CHANNEL,
			true
		);
	}

	/**
	 * Return the attachment bar color for a risk score.
	 *
	 * @param float $risk_score The finding's risk score, 0-10.
	 * @return string A hex color.
	 */
	protected static function risk_color( $risk_score ) {
		if ( $risk_score >= 9 ) {
			return '#D0342C';
		}

		if ( $risk_score >= 6 ) {
			return '#E8912D';
		}

		if ( $risk_score >= 4 ) {
			return '#ECB22E';
		}

		return '#808080';
	}

	/**
	 * Return a Slack link to the finding's file in the plugins Trac browser.
	 *
	 * @param \WP_Post $plugin      The plugin post.
	 * @param string   $release_ref The scanned release ref.
	 * @param string   $file_path   The file path, relative to the plugin root.
	 * @param int      $line        The line number, or 0 for none.
	 * @return string The Slack-formatted link.
	 */
	protected static function file_link( $plugin, $release_ref, $file_path, $line ) {
		$file_path = ltrim( (string) $file_path, '/' );

		// URL-encoding the untrusted path segments also keeps | and > from breaking the link syntax.
		$url = sprintf(
			'https://plugins.trac.wordpress.org/browser/%s/%s/%s',
			$plugin->post_name,
			'trunk' === $release_ref ? 'trunk' : 'tags/' . rawurlencode( $release_ref ),
			implode( '/', array_map( 'rawurlencode', explode( '/', $file_path ) ) )
		);

		$label = $file_path;
		if ( $line ) {
			$url   .= '#L' . $line;
			$label .= ':' . $line;
		}

		return sprintf( '<%s|%s>', $url, htmlspecialchars( $label, ENT_NOQUOTES ) );
	}

	/**
	 * Return the highest-risk findings first, bounded for display.
	 *
	 * The callback orders findings by ID, not by severity.
	 *
	 * @param array $findings The scan findings.
	 * @param int   $limit    Maximum number of findings to return.
	 * @return array The highest-risk findings.
	 */
	protected static function top_findings( $findings, $limit ) {
		usort(
			$findings,
			static function ( $a, $b ) {
				return ( $b['risk_score'] ?? 0 ) <=> ( $a['risk_score'] ?? 0 );
			}
		);

		return array_slice( $findings, 0, $limit );
	}

	/**
	 * Collapse untrusted text onto a single bounded line.
	 *
	 * @param string $text   The text to excerpt.
	 * @param int    $length Maximum length in characters.
	 * @return string The excerpted text.
	 */
	protected static function excerpt( $text, $length ) {
		return mb_strimwidth( preg_replace( '/\s+/u', ' ', trim( (string) $text ) ), 0, $length, '…' );
	}

	/**
	 * Store the last Gandalf integration error on the plugin.
	 *
	 * @param \WP_Post $plugin  The plugin post.
	 * @param string   $kind    The error kind.
	 * @param string   $message The error message.
	 * @param string   $scan_id Optional scan ID.
	 */
	protected static function record_last_error( $plugin, $kind, $message, $scan_id = '' ) {
		update_post_meta(
			$plugin->ID,
			self::LAST_ERROR_META_KEY,
			[
				'kind'        => sanitize_key( $kind ),
				'message'     => sanitize_text_field( $message ),
				'scan_id'     => sanitize_text_field( $scan_id ),
				'recorded_at' => time(),
			]
		);
	}
}
