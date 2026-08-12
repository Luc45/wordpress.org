<?php
/**
 * Gandalf scan integration for plugin updates.
 *
 * @package WordPressdotorg\Plugin_Directory\Jobs
 */

namespace WordPressdotorg\Plugin_Directory\Jobs;

use WordPressdotorg\Plugin_Directory\Template;
use WordPressdotorg\Plugin_Directory\Tools;
use WP_Error;
use WP_Http;

/**
 * Sends plugin updates to Gandalf for security scans and acts on the results.
 *
 * Completed scans whose maximum risk score reaches the block threshold record
 * a block on the scanned release ref pending review. Unserved refs stay out of
 * the update API; already-served refs cannot be selected again after supersession.
 *
 * @package WordPressdotorg\Plugin_Directory\Jobs
 */
class Plugin_Scan_Gandalf {

	/** Pending scans keyed by scan_id, used to recognize callbacks. */
	const PENDING_META_KEY = '_gandalf_scan_pending';

	/** Verdict hashes already sent to Slack, to avoid duplicate alerts. */
	const NOTIFIED_META_KEY = '_gandalf_scan_notified';

	/** Last dispatch or callback error for quick operator debugging. */
	const LAST_ERROR_META_KEY = '_gandalf_scan_last_error';

	/** Consumed callbacks keyed by scan_id, to acknowledge retries without repeating effects. */
	const CONSUMED_META_KEY = '_gandalf_scan_consumed';

	/** Bounded evidence snapshot of the last completed scan. */
	const LAST_RESULT_META_KEY = '_gandalf_scan_last_result';

	/** Completed scans with a max risk score at or above this have their release blocked pending review. */
	const BLOCK_RISK_SCORE = 8.0;

	/** Gandalf scan endpoint. */
	const ENDPOINT = 'https://gandalf.wordpress.org/scan';

	/**
	 * Dispatch a Gandalf scan from the importer context carried through cron.
	 *
	 * @param \WP_Post $plugin         The plugin post.
	 * @param array    $import_context The importer context.
	 * @return bool Whether the request was accepted.
	 * @throws \UnexpectedValueException When importer state or a served baseline is malformed.
	 */
	public static function dispatch_from_import_context( $plugin, $import_context ) {
		if ( ! defined( 'WP_GANDALF_SCAN_SHARED_SECRET' ) || ! WP_GANDALF_SCAN_SHARED_SECRET ) {
			return false;
		}

		if ( ! is_array( $import_context ) ) {
			throw new \UnexpectedValueException( 'The Gandalf import context is not a map.' );
		}

		foreach ( [ 'stable_tag', 'old_stable_tag', 'changed_svn_tags', 'version', 'served_release' ] as $required_key ) {
			if ( ! array_key_exists( $required_key, $import_context ) ) {
				throw new \UnexpectedValueException( 'The Gandalf import context is missing a required field.' );
			}
		}

		if ( ! is_string( $import_context['stable_tag'] ) || '' === $import_context['stable_tag'] ) {
			throw new \UnexpectedValueException( 'The Gandalf import context has no stable tag.' );
		}

		if ( ! is_string( $import_context['old_stable_tag'] ) || '' === $import_context['old_stable_tag'] ) {
			throw new \UnexpectedValueException( 'The Gandalf import context has no previous stable tag.' );
		}

		if ( ! is_array( $import_context['changed_svn_tags'] ) ) {
			throw new \UnexpectedValueException( 'The Gandalf import context changed-tag list is not an array.' );
		}

		if ( ! is_string( $import_context['version'] ) || '' === $import_context['version'] ) {
			throw new \UnexpectedValueException( 'The Gandalf import context has no plugin version.' );
		}

		$served_release = $import_context['served_release'];
		if ( false !== $served_release ) {
			if (
				! is_array( $served_release ) ||
				! array_key_exists( 'version', $served_release ) ||
				! array_key_exists( 'stable_tag', $served_release ) ||
				! is_string( $served_release['version'] ) ||
				! is_string( $served_release['stable_tag'] ) ||
				'' === $served_release['version'] ||
				'' === $served_release['stable_tag']
			) {
				throw new \UnexpectedValueException( 'The Gandalf import context has an invalid served release.' );
			}
		}

		$stable_tag       = $import_context['stable_tag'];
		$old_stable_tag   = $import_context['old_stable_tag'];
		$changed_svn_tags = $import_context['changed_svn_tags'];
		$release_ref      = $stable_tag;

		foreach ( $changed_svn_tags as $changed_svn_tag ) {
			if ( ! is_string( $changed_svn_tag ) ) {
				throw new \UnexpectedValueException( 'The Gandalf import context contains a non-string changed tag.' );
			}
		}

		// Trunk-only commits should not rescan a tag-based stable ZIP that was not rebuilt.
		if ( $stable_tag === $old_stable_tag && ! in_array( $release_ref, $changed_svn_tags, true ) ) {
			return false;
		}

		// The delayed cron must use the version frozen by the triggering import.
		$version = $import_context['version'];

		$previous_release_ref = null;
		$previous_version     = null;
		$previous_zip_url     = null;

		if (
			false !== $served_release &&
			$served_release['stable_tag'] !== $release_ref &&
			'trunk' !== $served_release['stable_tag']
		) {
			$served_release_meta = API_Update_Updater::get_release_by_identity( $plugin, $served_release['version'], $served_release['stable_tag'] );
			if ( false === $served_release_meta ) {
				throw new \UnexpectedValueException( 'The served Gandalf baseline has no exact release record.' );
			}

			if ( ! API_Update_Updater::is_release_blocked( $served_release_meta ) ) {
				$previous_version     = $served_release['version'];
				$previous_release_ref = $served_release['stable_tag'];
				$previous_zip_url     = Template::download_link( $plugin, $previous_release_ref );
			}
		}

		return self::dispatch(
			$plugin,
			[
				'scan_id'              => wp_generate_uuid4(),
				'subject_type'         => 'plugin',
				'slug'                 => $plugin->post_name,
				'version'              => $version,
				'release_ref'          => $release_ref,
				'current_zip_url'      => Template::download_link( $plugin, $release_ref ),
				'previous_version'     => $previous_version,
				'previous_release_ref' => $previous_release_ref,
				'previous_zip_url'     => $previous_zip_url,
				'callback_url'         => rest_url( 'plugins/v1/plugin/' . $plugin->post_name . '/gandalf-scan' ),
				'requested_at'         => time(),
			]
		);
	}

	/**
	 * POST a queued scan request to Gandalf.
	 *
	 * @param \WP_Post $plugin       The plugin post.
	 * @param array    $request_data The Gandalf scan request data.
	 * @return bool Whether the request was accepted.
	 * @throws \UnexpectedValueException When persisted pending-scan metadata is malformed.
	 */
	public static function dispatch( $plugin, $request_data ) {
		if ( ! defined( 'WP_GANDALF_SCAN_SHARED_SECRET' ) || ! WP_GANDALF_SCAN_SHARED_SECRET ) {
			return false;
		}

		$pending = self::get_meta_map( $plugin, self::PENDING_META_KEY );
		foreach ( $pending as $record ) {
			self::assert_pending_record( $record );
		}

		$pending[ $request_data['scan_id'] ] = [
			'version'      => $request_data['version'],
			'release_ref'  => $request_data['release_ref'],
			'requested_at' => $request_data['requested_at'],
		];
		update_post_meta( $plugin->ID, self::PENDING_META_KEY, $pending );

		$stored_pending = self::get_meta_map( $plugin, self::PENDING_META_KEY );
		if (
			! array_key_exists( $request_data['scan_id'], $stored_pending ) ||
			$stored_pending[ $request_data['scan_id'] ] !== $pending[ $request_data['scan_id'] ]
		) {
			throw new \UnexpectedValueException( 'The Gandalf pending scan identity could not be stored.' );
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
		if ( WP_Http::ACCEPTED !== $response_code ) {
			return self::dispatch_failed( $plugin, $request_data, sprintf( 'Gandalf returned HTTP %d.', $response_code ), 'dispatch_http_error' );
		}

		$response_data = json_decode( wp_remote_retrieve_body( $response ), true );
		if (
			! is_array( $response_data ) ||
			2 !== count( $response_data ) ||
			! array_key_exists( 'scan_id', $response_data ) ||
			! array_key_exists( 'accepted_at', $response_data ) ||
			! is_string( $response_data['scan_id'] ) ||
			$response_data['scan_id'] !== $request_data['scan_id'] ||
			! is_int( $response_data['accepted_at'] ) ||
			$response_data['accepted_at'] < 0
		) {
			return self::dispatch_failed( $plugin, $request_data, 'Gandalf accepted the scan with an invalid response body.', 'dispatch_ack_invalid' );
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Routed to the error log via E_USER_NOTICE; raw is fine.
		trigger_error( sprintf( 'Dispatched Gandalf scan %s for %s.', $request_data['scan_id'], $plugin->post_name ), E_USER_NOTICE );
		return true;
	}

	/**
	 * Handle a completed or failed scan callback.
	 *
	 * @param \WP_Post $plugin The plugin post.
	 * @param array    $data   The security scan callback data, validated by the route.
	 * @param string   $digest SHA-256 of the exact received callback body.
	 * @return true|WP_Error True on success, or an error when the callback is invalid.
	 */
	public static function handle_callback( $plugin, $data, $digest ) {
		$scan_id = $data['scan_id'];

		/*
		 * Serialize processing per plugin, not per scan: callbacks read-modify-write
		 * shared per-plugin meta, and a scanner retry racing a slow first delivery
		 * waits for the consumed record.
		 */
		if ( ! wp_cache_add( 'gandalf-scan-callback-' . $plugin->ID, 1, 'plugin-scans', 5 * MINUTE_IN_SECONDS ) ) {
			return new WP_Error( 'security_scan_locked', 'A security scan callback for this plugin is already being processed.', [ 'status' => WP_Http::CONFLICT ] );
		}

		try {
			return self::consume_callback( $plugin, $data, $digest );
		} finally {
			wp_cache_delete( 'gandalf-scan-callback-' . $plugin->ID, 'plugin-scans' );
		}
	}

	/**
	 * Consume a validated callback exactly once and apply scan policy.
	 *
	 * Runs under the per-plugin lock taken by handle_callback().
	 *
	 * @param \WP_Post $plugin The plugin post.
	 * @param array    $data   The validated security scan callback data.
	 * @param string   $digest SHA-256 of the exact received callback body.
	 * @return true|WP_Error True on success, or an error when the callback is invalid.
	 * @throws \UnexpectedValueException When persisted callback metadata is malformed.
	 */
	protected static function consume_callback( $plugin, $data, $digest ) {
		$scan_id  = $data['scan_id'];
		$consumed = self::get_meta_map( $plugin, self::CONSUMED_META_KEY );

		if ( array_key_exists( $scan_id, $consumed ) ) {
			if (
				! is_array( $consumed[ $scan_id ] ) ||
				! array_key_exists( 'digest', $consumed[ $scan_id ] ) ||
				! is_string( $consumed[ $scan_id ]['digest'] )
			) {
				throw new \UnexpectedValueException( 'A Gandalf consumed-callback record is malformed.' );
			}

			// Identical retry of a consumed callback: acknowledge without repeating effects.
			if ( hash_equals( $consumed[ $scan_id ]['digest'], $digest ) ) {
				return true;
			}

			$error = new WP_Error( 'security_scan_conflict', 'A different security scan callback was already consumed for this scan.', [ 'status' => WP_Http::CONFLICT ] );
			self::record_invalid_callback( $plugin, $error, $scan_id );
			return $error;
		}

		$pending = self::get_meta_map( $plugin, self::PENDING_META_KEY );

		if ( ! array_key_exists( $scan_id, $pending ) ) {
			$error = new WP_Error( 'unknown_gandalf_scan', 'Unknown security scan.', [ 'status' => WP_Http::BAD_REQUEST ] );
			self::record_invalid_callback( $plugin, $error, $scan_id );
			return $error;
		}

		$pending_record = $pending[ $scan_id ];
		self::assert_pending_record( $pending_record );

		if ( $data['version'] !== $pending_record['version'] || $data['release_ref'] !== $pending_record['release_ref'] ) {
			$error = new WP_Error( 'invalid_gandalf_scan', 'Security scan callback does not match the pending scan.', [ 'status' => WP_Http::BAD_REQUEST ] );
			self::record_invalid_callback( $plugin, $error, $scan_id );
			return $error;
		}

		if ( 'completed' === $data['status'] ) {
			$record = [
				'scan_id'         => $scan_id,
				'version'         => $pending_record['version'],
				'release_ref'     => $pending_record['release_ref'],
				'completed_at'    => $data['completed_at'],
				'verdict_hash'    => $data['verdict_hash'],
				'findings_count'  => $data['findings_count'],
				'severity_counts' => $data['severity_counts'],
				'max_risk_score'  => $data['max_risk_score'],
				'report_url'      => $data['report_url'],
				'action'          => 'advisory',

				/*
				 * Only the ten highest-risk findings ever surface (Slack shows five,
				 * the review note ten), and snippets and explanations are only in
				 * the scan report; storing more would bloat a post meta row that is
				 * loaded on every plugin page view.
				 */
				'findings'        => array_map(
					static function ( $finding ) {
						unset( $finding['code_snippet'], $finding['explanation'] );
						return $finding;
					},
					self::top_findings( $data['findings'], 10 )
				),
			];

			if ( $record['max_risk_score'] >= self::BLOCK_RISK_SCORE ) {
				if ( ! self::block_release( $plugin, $record ) ) {
					$error = new WP_Error( 'security_scan_block_failed', 'The scanned release could not be blocked.', [ 'status' => WP_Http::INTERNAL_SERVER_ERROR ] );
					self::record_invalid_callback( $plugin, $error, $scan_id );
					return $error;
				}

				$record['action'] = 'blocked';
				self::record_review_note( $plugin, $record );
			}

			// Persist the bounded evidence snapshot and the action taken on it.
			update_post_meta( $plugin->ID, self::LAST_RESULT_META_KEY, $record );

			if ( $record['findings_count'] > 0 || 'advisory' !== $record['action'] ) {
				self::notify_slack( $plugin, $record );
			}
		} else {
			self::record_last_error( $plugin, $data['error']['kind'], $data['error']['message'], $scan_id );
		}

		/*
		 * Deliberately recorded after the policy effects, failing closed: a crash
		 * mid-processing makes the retry re-apply effects (the block itself is
		 * precondition-guarded) rather than acknowledge a block that never happened.
		 */
		$consumed[ $scan_id ] = [
			'digest' => $digest,
		];
		update_post_meta( $plugin->ID, self::CONSUMED_META_KEY, $consumed );

		unset( $pending[ $scan_id ] );
		update_post_meta( $plugin->ID, self::PENDING_META_KEY, $pending );

		return true;
	}

	/**
	 * Block the scanned release, once the verdict is known to still apply to it.
	 *
	 * The exact scanned ref is blocked even when a newer release superseded it or
	 * it was already served. That cannot un-ship an already-live release, but it
	 * prevents the burned ref from becoming eligible again later.
	 *
	 * @param \WP_Post $plugin The plugin post.
	 * @param array    $record The completed scan record.
	 * @throws \RuntimeException When the audit note cannot be attributed or recorded.
	 * @return bool Whether the release was blocked.
	 */
	protected static function block_release( $plugin, $record ) {
		return API_Update_Updater::block_release(
			$plugin->post_name,
			$record['version'],
			$record['release_ref'],
			[
				'scan_id'    => $record['scan_id'],
				'risk_score' => $record['max_risk_score'],
			]
		);
	}

	/**
	 * Leave an internal note with the scan findings for the plugin review team.
	 *
	 * @param \WP_Post $plugin The plugin post.
	 * @param array    $record The completed scan record.
	 * @throws \UnexpectedValueException When the audit actor does not exist.
	 * @throws \RuntimeException When the audit note cannot be recorded.
	 */
	protected static function record_review_note( $plugin, $record ) {
		$note = sprintf(
			'Release ref %s (%s) is blocked pending review: security scan %s reported a maximum risk score of %s. The block prevents future update API publication but does not un-ship an already-served release.',
			esc_html( $record['release_ref'] ),
			esc_html( $record['version'] ),
			esc_html( $record['scan_id'] ),
			esc_html( $record['max_risk_score'] )
		);

		$note .= '<br><br>Findings:';
		foreach ( self::top_findings( $record['findings'], 10 ) as $finding ) {
			$note .= sprintf(
				'<br>&#8226; <strong>%s</strong> &mdash; %s',
				esc_html( number_format( $finding['risk_score'], 1 ) ),
				esc_html( self::excerpt( $finding['title'], 200 ) )
			);

			$location = esc_html( $finding['file_path'] );
			if ( isset( $finding['line'] ) ) {
				$location .= ':' . $finding['line'];
			}
			$note .= '<br>&nbsp;&nbsp;' . $location;

			$investigation = $finding['investigation'];
			if ( 'completed' === $investigation['status'] && in_array( $investigation['result'], [ 'reproduced', 'conditional' ], true ) ) {
				$note .= sprintf(
					'<br>&nbsp;&nbsp;Investigation (%s): %s',
					esc_html( $investigation['result'] ),
					esc_html( self::excerpt( $investigation['summary'], 200 ) )
				);
			}
		}

		$note .= '<br><br>Report: ' . esc_url( $record['report_url'] );

		$wordpressdotorg = get_user_by( 'slug', 'wordpressdotorg' );
		if ( ! ( $wordpressdotorg instanceof \WP_User ) ) {
			throw new \UnexpectedValueException( 'The WordPress.org audit user does not exist.' );
		}

		// wp_insert_comment() unslashes; slash so backslashes in finding strings survive.
		if ( ! Tools::audit_log( wp_slash( $note ), $plugin, $wordpressdotorg ) ) {
			throw new \RuntimeException( 'The Gandalf release-block audit note could not be recorded.' );
		}
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
		// Keep the pending identity: a transport or acknowledgement failure does not prove Gandalf rejected the request.

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Routed to the error log via E_USER_NOTICE; raw is fine.
		trigger_error( sprintf( 'Failed to dispatch Gandalf scan for %s: %s', $plugin->post_name, $message ), E_USER_NOTICE );
		return false;
	}

	/**
	 * Notify Slack about a Gandalf scan with findings.
	 *
	 * @param \WP_Post $plugin The plugin post.
	 * @param array    $record The completed scan record.
	 * @throws \UnexpectedValueException When persisted notification metadata is malformed.
	 */
	protected static function notify_slack( $plugin, $record ) {
		$already_notified = self::get_meta_map( $plugin, self::NOTIFIED_META_KEY );
		foreach ( $already_notified as $hash => $time ) {
			if ( ! is_int( $time ) ) {
				throw new \UnexpectedValueException( 'A Gandalf notification record is malformed.' );
			}

			if ( $time < time() - MONTH_IN_SECONDS ) {
				unset( $already_notified[ $hash ] );
			}
		}

		// Release blocks always alert; only advisory results deduplicate.
		if ( 'advisory' === $record['action'] && isset( $already_notified[ $record['verdict_hash'] ] ) ) {
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

		$findings_count = $record['findings_count'];
		$top_findings   = self::top_findings( $record['findings'], 5 );
		$findings_text  = "{$findings_count} findings";
		if ( 1 === $findings_count ) {
			$findings_text = '1 finding';
		}

		$meta_links = sprintf(
			'<https://wordpress.org/plugins/wp-admin/post.php?post=%d&action=edit|wp-admin> · <https://wordpress.org/plugins/%s/|Plugin page>',
			$plugin->ID,
			$plugin->post_name
		);
		if ( $record['release_ref'] !== $record['version'] ) {
			// Escaping &, <, and > neutralizes Slack control sequences like <!channel> in untrusted strings.
			$meta_links .= ' · ' . htmlspecialchars( $record['release_ref'], ENT_NOQUOTES );
		}

		$summary_text  = sprintf( '*%s* · %s', $findings_text, $install_text );
		$summary_text .= sprintf( ' · max risk %s', number_format( $record['max_risk_score'], 1 ) );

		$summary = [
			'type' => 'section',
			'text' => [
				'type' => 'mrkdwn',
				'text' => $summary_text,
			],
		];

		$summary['accessory'] = [
			'type'  => 'button',
			'text'  => [
				'type' => 'plain_text',
				'text' => 'View report',
			],
			'url'   => esc_url_raw( $record['report_url'] ),
			'style' => 'primary',
		];

		$blocks = [
			[
				'type' => 'header',
				'text' => [
					'type' => 'plain_text',
					'text' => self::excerpt( trim( $title . ' ' . $record['version'] ), 150 ),
				],
			],
		];

		if ( 'blocked' === $record['action'] ) {
			$blocks[] = [
				'type' => 'section',
				'text' => [
					'type' => 'mrkdwn',
					'text' => ':rotating_light: *Release ref is blocked pending review.*',
				],
			];
		}

		$blocks[] = $summary;
		$blocks[] = [
			'type'     => 'context',
			'elements' => [
				[
					'type' => 'mrkdwn',
					'text' => $meta_links,
				],
			],
		];

		$attachments = [];
		foreach ( $top_findings as $finding ) {
			$risk_score = $finding['risk_score'];

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
								htmlspecialchars( self::excerpt( $finding['title'], 150 ), ENT_NOQUOTES )
							),
						],
					],
				],
			];

			$line = 0;
			if ( isset( $finding['line'] ) ) {
				$line = $finding['line'];
			}

			$attachment['blocks'][] = [
				'type'     => 'context',
				'elements' => [
					[
						'type' => 'mrkdwn',
						'text' => 'File: ' . self::file_link( $plugin, $record['release_ref'], $finding['file_path'], $line ),
					],
				],
			];

			$attachments[] = $attachment;
		}

		if ( 'blocked' === $record['action'] ) {
			$fallback = sprintf(
				'Security scan blocked %s release ref %s (%s) pending review',
				htmlspecialchars( $title, ENT_NOQUOTES ),
				htmlspecialchars( $record['release_ref'], ENT_NOQUOTES ),
				htmlspecialchars( $record['version'], ENT_NOQUOTES )
			);
		} else {
			$fallback = sprintf(
				'Security scan found %s in %s %s',
				$findings_text,
				htmlspecialchars( $title, ENT_NOQUOTES ),
				htmlspecialchars( $record['version'], ENT_NOQUOTES )
			);
		}
		$fallback .= sprintf( ' (max risk %s)', number_format( $record['max_risk_score'], 1 ) );

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
		$file_path  = ltrim( $file_path, '/' );
		$source_ref = 'tags/' . rawurlencode( $release_ref );
		if ( 'trunk' === $release_ref ) {
			$source_ref = 'trunk';
		}

		// URL-encoding the untrusted path segments also keeps | and > from breaking the link syntax.
		$url = sprintf(
			'https://plugins.trac.wordpress.org/browser/%s/%s/%s',
			$plugin->post_name,
			$source_ref,
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
	 * @param array $findings The scan findings.
	 * @param int   $limit    Maximum number of findings to return.
	 * @return array The highest-risk findings.
	 */
	protected static function top_findings( $findings, $limit ) {
		usort(
			$findings,
			static function ( $a, $b ) {
				return $b['risk_score'] <=> $a['risk_score'];
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
		return mb_strimwidth( preg_replace( '/\s+/u', ' ', trim( $text ) ), 0, $length, '…' );
	}

	/**
	 * Read one map-shaped Gandalf post-meta value.
	 *
	 * Missing metadata is the empty initial state. Any other non-array value is
	 * an internal storage invariant violation.
	 *
	 * @param \WP_Post $plugin The plugin post.
	 * @param string   $key    Post-meta key.
	 * @return array The stored map.
	 * @throws \UnexpectedValueException When the stored value is not a map.
	 */
	protected static function get_meta_map( $plugin, $key ) {
		if ( ! metadata_exists( 'post', $plugin->ID, $key ) ) {
			return [];
		}

		$value = get_post_meta( $plugin->ID, $key, true );

		if ( ! is_array( $value ) ) {
			throw new \UnexpectedValueException( 'Gandalf metadata is not a map.' );
		}

		return $value;
	}

	/**
	 * Assert the one persisted shape for a pending Gandalf scan.
	 *
	 * @param mixed $record Pending scan record.
	 * @throws \UnexpectedValueException When the record is malformed.
	 */
	protected static function assert_pending_record( $record ) {
		if ( ! is_array( $record ) ) {
			throw new \UnexpectedValueException( 'A Gandalf pending-scan record is not a map.' );
		}

		foreach ( [ 'version', 'release_ref', 'requested_at' ] as $required_key ) {
			if ( ! array_key_exists( $required_key, $record ) ) {
				throw new \UnexpectedValueException( 'A Gandalf pending-scan record is missing a required field.' );
			}
		}

		if (
			! is_string( $record['version'] ) ||
			'' === $record['version'] ||
			! is_string( $record['release_ref'] ) ||
			'' === $record['release_ref'] ||
			! is_int( $record['requested_at'] ) ||
			$record['requested_at'] < 0
		) {
			throw new \UnexpectedValueException( 'A Gandalf pending-scan record has invalid values.' );
		}
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
