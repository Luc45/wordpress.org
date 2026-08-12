<?php
/**
 * Gandalf scan callback REST route.
 *
 * @package WordPressdotorg_Plugin_Directory
 */

namespace WordPressdotorg\Plugin_Directory\API\Routes;

use WordPressdotorg\Plugin_Directory\API\Base;
use WordPressdotorg\Plugin_Directory\Jobs\Plugin_Scan_Gandalf;
use WordPressdotorg\Plugin_Directory\Plugin_Directory;
use WP_Error;
use WP_Http;

/**
 * Callback endpoint for security scan results.
 *
 * @package WordPressdotorg_Plugin_Directory
 */
class Gandalf_Scan extends Base {

	/** Maximum serialized size of a completed callback body. */
	const COMPLETED_BODY_MAX_BYTES = 262144;

	/** Maximum number of findings in a completed callback. */
	const FINDINGS_MAX = 50;

	/**
	 * Registers the callback route.
	 *
	 * The URL slug is the only route argument. scan_callback() validates the
	 * complete JSON body after selecting its status-specific contract.
	 */
	public function __construct() {
		register_rest_route(
			'plugins/v1',
			'/plugin/(?P<plugin_slug>[^/]+)/gandalf-scan',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'scan_callback' ],
				'permission_callback' => function ( $request ) {
					return $this->permission_check_api_bearer( $request, 'WP_GANDALF_SCAN_SHARED_SECRET' );
				},
				'args'                => [
					'plugin_slug' => [
						'validate_callback' => [ $this, 'validate_plugin_slug_callback' ],
					],
				],
			]
		);
	}

	/**
	 * Return the exact callback schema selected by its terminal status.
	 *
	 * @param string $status Callback status.
	 * @return array JSON schema for the selected callback.
	 */
	protected function get_callback_schema( $status ) {
		$properties = [
			'status'       => [
				'type' => 'string',
				'enum' => [ $status ],
			],
			'scan_id'      => [
				'type'    => 'string',
				'pattern' => '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$',
			],
			'subject_type' => [
				'type' => 'string',
				'enum' => [ 'plugin' ],
			],
			'slug'         => [
				'type'      => 'string',
				'minLength' => 1,
			],
			'version'      => [
				'type'      => 'string',
				'minLength' => 1,
			],
			'release_ref'  => [
				'type'      => 'string',
				'minLength' => 1,
			],
			'completed_at' => [
				'type'    => 'integer',
				'minimum' => 0,
			],
			'report_url'   => [
				'type'      => 'string',
				'minLength' => 1,
			],
		];

		if ( 'completed' === $status ) {
			$properties['verdict_hash']    = [
				'type'      => 'string',
				'minLength' => 1,
			];
			$properties['findings_count']  = [
				'type'    => 'integer',
				'minimum' => 0,
			];
			$properties['findings']        = [
				'type'     => 'array',
				'maxItems' => self::FINDINGS_MAX,
				'items'    => [
					'type'                 => 'object',
					'required'             => [ 'id', 'ref', 'title', 'severity', 'file_path', 'risk_score', 'investigation' ],
					'additionalProperties' => false,
					'properties'           => [
						'id'            => [
							'type'      => 'string',
							'minLength' => 1,
						],
						'ref'           => [
							'type'      => 'string',
							'minLength' => 1,
						],
						'title'         => [
							'type'      => 'string',
							'minLength' => 1,
						],
						'severity'      => [
							'type' => 'string',
							'enum' => [ 'error', 'warning', 'info' ],
						],
						'file_path'     => [
							'type'      => 'string',
							'minLength' => 1,
						],
						'line'          => [
							'type'    => 'integer',
							'minimum' => 1,
						],
						'code_snippet'  => [
							'type'      => 'string',
							'minLength' => 1,
						],
						'explanation'   => [
							'type'      => 'string',
							'minLength' => 1,
						],
						'risk_score'    => [
							'type'    => 'number',
							'minimum' => 0,
							'maximum' => 10,
						],
						'investigation' => [
							'type'                 => 'object',
							'required'             => [ 'status', 'result', 'summary' ],
							'additionalProperties' => false,
							'properties'           => [
								'status'  => [
									'type' => 'string',
									'enum' => [ 'completed', 'skipped', 'error' ],
								],
								'result'  => [
									'type' => 'string',
									'enum' => [ 'reproduced', 'conditional', 'not_reproduced', 'not_applicable', 'unknown' ],
								],
								'summary' => [
									'type'      => 'string',
									'minLength' => 1,
								],
							],
						],
					],
				],
			];
			$properties['max_risk_score']  = [
				'type'    => 'number',
				'minimum' => 0,
				'maximum' => 10,
			];
			$properties['severity_counts'] = [
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => [
					'error'   => [
						'type'    => 'integer',
						'minimum' => 0,
					],
					'warning' => [
						'type'    => 'integer',
						'minimum' => 0,
					],
					'info'    => [
						'type'    => 'integer',
						'minimum' => 0,
					],
				],
			];
			$properties['scanner_version'] = [
				'type'      => 'string',
				'minLength' => 1,
			];
		} else {
			$properties['error'] = [
				'type'                 => 'object',
				'required'             => [ 'kind', 'message' ],
				'additionalProperties' => false,
				'properties'           => [
					'kind'    => [
						'type'      => 'string',
						'minLength' => 1,
						'pattern'   => '^[a-z0-9_-]+$',
					],
					'message' => [
						'type'      => 'string',
						'minLength' => 1,
					],
				],
			];
		}

		return [
			'type'                 => 'object',
			'required'             => array_keys( $properties ),
			'additionalProperties' => false,
			'properties'           => $properties,
		];
	}

	/**
	 * Validate cross-field invariants not expressible in the REST schema.
	 *
	 * @param array     $data         Parsed callback body.
	 * @param \stdClass $decoded_body Parsed body preserving JSON object/array distinctions.
	 * @param string    $raw_body     Exact received body.
	 * @return true|WP_Error True when valid, otherwise an error.
	 */
	protected function validate_callback_data( $data, $decoded_body, $raw_body ) {
		if ( ! isset( $data['status'] ) || ! is_string( $data['status'] ) ) {
			return $this->invalid_callback_error();
		}

		if ( ! in_array( $data['status'], [ 'completed', 'failed' ], true ) ) {
			return $this->invalid_callback_error();
		}

		$valid = rest_validate_value_from_schema( $decoded_body, $this->get_callback_schema( $data['status'] ), 'callback' );
		if ( is_wp_error( $valid ) ) {
			return $this->invalid_callback_error( $valid->get_error_message() );
		}

		if ( 'failed' === $data['status'] && ! ( $decoded_body->error instanceof \stdClass ) ) {
			return $this->invalid_callback_error();
		}

		if ( 'completed' === $data['status'] ) {
			if ( ! is_array( $decoded_body->findings ) || ! ( $decoded_body->severity_counts instanceof \stdClass ) ) {
				return $this->invalid_callback_error();
			}

			foreach ( $decoded_body->findings as $finding ) {
				if ( ! ( $finding instanceof \stdClass ) || ! ( $finding->investigation instanceof \stdClass ) ) {
					return $this->invalid_callback_error();
				}
			}
		}

		if ( ! is_int( $data['completed_at'] ) ) {
			return $this->invalid_callback_error();
		}

		if ( ! $this->is_report_url( $data['report_url'] ) ) {
			return $this->invalid_callback_error();
		}

		if ( 'failed' === $data['status'] ) {
			return true;
		}

		if ( strlen( $raw_body ) > self::COMPLETED_BODY_MAX_BYTES ) {
			return $this->invalid_callback_error();
		}

		if ( ! is_int( $data['findings_count'] ) ) {
			return $this->invalid_callback_error();
		}

		if ( ! $this->is_risk_score( $data['max_risk_score'] ) ) {
			return $this->invalid_callback_error();
		}

		$expected_severity_counts = [];
		$expected_max_risk_score  = 0;
		$previous_finding_id      = false;

		foreach ( $data['findings'] as $finding ) {
			if ( ! $this->validate_finding( $finding ) ) {
				return $this->invalid_callback_error();
			}

			if ( false !== $previous_finding_id && strcmp( $previous_finding_id, $finding['id'] ) >= 0 ) {
				return $this->invalid_callback_error();
			}
			$previous_finding_id = $finding['id'];

			if ( ! isset( $expected_severity_counts[ $finding['severity'] ] ) ) {
				$expected_severity_counts[ $finding['severity'] ] = 0;
			}
			++$expected_severity_counts[ $finding['severity'] ];
			$expected_max_risk_score = max( $expected_max_risk_score, $finding['risk_score'] );
		}

		foreach ( $data['severity_counts'] as $count ) {
			if ( ! is_int( $count ) ) {
				return $this->invalid_callback_error();
			}
		}

		$actual_severity_counts = $data['severity_counts'];
		ksort( $expected_severity_counts );
		ksort( $actual_severity_counts );

		if ( count( $data['findings'] ) !== $data['findings_count'] ) {
			return $this->invalid_callback_error();
		}

		if ( $actual_severity_counts !== $expected_severity_counts ) {
			return $this->invalid_callback_error();
		}

		if ( (float) $data['max_risk_score'] !== (float) $expected_max_risk_score ) {
			return $this->invalid_callback_error();
		}

		return true;
	}

	/**
	 * Validate one completed-callback finding.
	 *
	 * @param array $finding Finding data already checked against its schema.
	 * @return bool Whether the cross-field and byte invariants hold.
	 */
	protected function validate_finding( $finding ) {
		if ( ! $this->is_risk_score( $finding['risk_score'] ) ) {
			return false;
		}

		if ( ! $this->is_source_relative_path( $finding['file_path'] ) ) {
			return false;
		}

		if ( isset( $finding['line'] ) && ! is_int( $finding['line'] ) ) {
			return false;
		}

		$bounded_strings = [
			[ $finding['id'], 64 ],
			[ $finding['ref'], 256 ],
			[ $finding['title'], 512 ],
			[ $finding['file_path'], 1024 ],
			[ $finding['investigation']['summary'], 1024 ],
		];

		if ( isset( $finding['code_snippet'] ) ) {
			$bounded_strings[] = [ $finding['code_snippet'], 4096 ];
		}

		if ( isset( $finding['explanation'] ) ) {
			$bounded_strings[] = [ $finding['explanation'], 4096 ];
		}

		foreach ( $bounded_strings as $bounded_string ) {
			if ( strlen( $bounded_string[0] ) > $bounded_string[1] ) {
				return false;
			}
		}

		$investigation = $finding['investigation'];
		if ( 'completed' === $investigation['status'] ) {
			return true;
		}

		return 'unknown' === $investigation['result'];
	}

	/**
	 * Whether a callback score is finite, in range, and has at most one decimal.
	 *
	 * @param mixed $score Candidate score.
	 * @return bool Whether the score is valid.
	 */
	protected function is_risk_score( $score ) {
		if ( ! is_int( $score ) && ! is_float( $score ) ) {
			return false;
		}

		if ( ! is_finite( $score ) || $score < 0 || $score > 10 ) {
			return false;
		}

		$scaled_score = (float) $score * 10;
		if ( floor( $scaled_score ) !== $scaled_score ) {
			return false;
		}

		return true;
	}

	/**
	 * Whether a finding path stays relative to its plugin source root.
	 *
	 * @param string $path Finding source path.
	 * @return bool Whether the path is safe.
	 */
	protected function is_source_relative_path( $path ) {
		if ( str_starts_with( $path, '/' ) || str_contains( $path, '\\' ) || str_contains( $path, "\0" ) || preg_match( '/^[A-Za-z]:\//', $path ) ) {
			return false;
		}

		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether a report URL uses HTTPS, or loopback HTTP for local development.
	 *
	 * @param string $url Report URL.
	 * @return bool Whether the URL is valid.
	 */
	protected function is_report_url( $url ) {
		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return false;
		}

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) {
			return false;
		}

		$scheme = strtolower( $parts['scheme'] );
		$host   = strtolower( $parts['host'] );

		if ( 'https' === $scheme ) {
			return true;
		}

		return 'http' === $scheme && in_array( $host, [ 'localhost', '127.0.0.1' ], true );
	}

	/**
	 * Build the one public error shape for an invalid callback body.
	 *
	 * @param string $message Validation failure detail.
	 * @return WP_Error The callback validation error.
	 */
	protected function invalid_callback_error( $message = '' ) {
		if ( '' === $message ) {
			$message = __( 'Invalid Gandalf scan callback.', 'wporg-plugins' );
		}

		return new WP_Error(
			'invalid_gandalf_scan_callback',
			$message,
			[ 'status' => WP_Http::BAD_REQUEST ]
		);
	}

	/**
	 * Receive a security scan callback.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return array|WP_Error Callback response, or an error.
	 */
	public function scan_callback( $request ) {
		// JSON body params outrank URL params in get_param(); the URL segment is the routed identity.
		$plugin = Plugin_Directory::get_plugin_post( $request->get_url_params()['plugin_slug'] );
		if ( ! $plugin ) {
			return new WP_Error(
				'plugin_not_found',
				__( 'Plugin not found.', 'wporg-plugins' ),
				[ 'status' => WP_Http::NOT_FOUND ]
			);
		}

		$raw_body = $request->get_body();

		try {
			$decoded_body = json_decode( $raw_body, false, 512, JSON_THROW_ON_ERROR );
			$data         = json_decode( $raw_body, true, 512, JSON_THROW_ON_ERROR );
		} catch ( \JsonException ) {
			$decoded_body = false;
			$data         = false;
		}

		if ( ! ( $decoded_body instanceof \stdClass ) || ! is_array( $data ) ) {
			$error = $this->invalid_callback_error();

			Plugin_Scan_Gandalf::record_invalid_callback( $plugin, $error );
			return $error;
		}

		$valid = $this->validate_callback_data( $data, $decoded_body, $raw_body );
		if ( is_wp_error( $valid ) ) {
			$scan_id = '';
			if ( isset( $data['scan_id'] ) && is_string( $data['scan_id'] ) ) {
				$scan_id = sanitize_text_field( $data['scan_id'] );
			}

			Plugin_Scan_Gandalf::record_invalid_callback( $plugin, $valid, $scan_id );
			return $valid;
		}

		// The payload must assert the same plugin the callback was routed to.
		if ( $data['slug'] !== $plugin->post_name ) {
			$error = new WP_Error( 'invalid_gandalf_scan', 'Security scan callback slug does not match the plugin.', [ 'status' => WP_Http::BAD_REQUEST ] );
			Plugin_Scan_Gandalf::record_invalid_callback( $plugin, $error, sanitize_text_field( $data['scan_id'] ) );
			return $error;
		}

		$result = Plugin_Scan_Gandalf::handle_callback( $plugin, $data, hash( 'sha256', $raw_body ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return [
			'success' => true,
		];
	}
}
