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
 * This route owns the raw HTTP trust boundary: it selects the terminal-status
 * schema, validates the exact callback shape and cross-field evidence, and
 * passes validated data plus exact-body identity to the scan job.
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
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'scan_callback' ),
				'permission_callback' => function ( $request ) {
					return $this->permission_check_api_bearer( $request, 'WP_GANDALF_SCAN_SHARED_SECRET' );
				},
				'args'                => array(
					'plugin_slug' => array(
						'validate_callback' => array( $this, 'validate_plugin_slug_callback' ),
					),
				),
			)
		);
	}

	/**
	 * Return the exact callback schema selected by its terminal status.
	 *
	 * Completed and failed callbacks have disjoint required fields. Selecting one
	 * closed schema here avoids downstream defaults for status-specific evidence.
	 *
	 * @param string $status Callback status.
	 * @return array JSON schema for the selected callback.
	 */
	protected function get_callback_schema( $status ) {
		$properties = array(
			'status'       => array(
				'type' => 'string',
				'enum' => array( $status ),
			),
			'scan_id'      => array(
				'type'    => 'string',
				'pattern' => '^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$',
			),
			'subject_type' => array(
				'type' => 'string',
				'enum' => array( 'plugin' ),
			),
			'slug'         => array(
				'type'      => 'string',
				'minLength' => 1,
			),
			'version'      => array(
				'type'      => 'string',
				'minLength' => 1,
			),
			'release_ref'  => array(
				'type'      => 'string',
				'minLength' => 1,
			),
			'completed_at' => array(
				'type'    => 'integer',
				'minimum' => 0,
			),
			'report_url'   => array(
				'type'      => 'string',
				'minLength' => 1,
			),
		);

		if ( 'completed' === $status ) {
			$properties['verdict_hash']    = array(
				'type'      => 'string',
				'minLength' => 1,
			);
			$properties['findings_count']  = array(
				'type'    => 'integer',
				'minimum' => 0,
			);
			$properties['findings']        = array(
				'type'     => 'array',
				'maxItems' => self::FINDINGS_MAX,
				'items'    => array(
					'type'                 => 'object',
					'required'             => array( 'id', 'ref', 'title', 'severity', 'file_path', 'risk_score', 'investigation' ),
					'additionalProperties' => false,
					'properties'           => array(
						'id'            => array(
							'type'      => 'string',
							'minLength' => 1,
						),
						'ref'           => array(
							'type'      => 'string',
							'minLength' => 1,
						),
						'title'         => array(
							'type'      => 'string',
							'minLength' => 1,
						),
						'severity'      => array(
							'type' => 'string',
							'enum' => array( 'error', 'warning', 'info' ),
						),
						'file_path'     => array(
							'type'      => 'string',
							'minLength' => 1,
						),
						'line'          => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'code_snippet'  => array(
							'type'      => 'string',
							'minLength' => 1,
						),
						'explanation'   => array(
							'type'      => 'string',
							'minLength' => 1,
						),
						'risk_score'    => array(
							'type'    => 'number',
							'minimum' => 0,
							'maximum' => 10,
						),
						'investigation' => array(
							'type'                 => 'object',
							'required'             => array( 'status', 'result', 'summary' ),
							'additionalProperties' => false,
							'properties'           => array(
								'status'  => array(
									'type' => 'string',
									'enum' => array( 'completed', 'skipped', 'error' ),
								),
								'result'  => array(
									'type' => 'string',
									'enum' => array( 'reproduced', 'conditional', 'not_reproduced', 'not_applicable', 'unknown' ),
								),
								'summary' => array(
									'type'      => 'string',
									'minLength' => 1,
								),
							),
						),
					),
				),
			);
			$properties['max_risk_score']  = array(
				'type'    => 'number',
				'minimum' => 0,
				'maximum' => 10,
			);
			$properties['severity_counts'] = array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => array(
					'error'   => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
					'warning' => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
					'info'    => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
				),
			);
			$properties['scanner_version'] = array(
				'type'      => 'string',
				'minLength' => 1,
			);
		} else {
			$properties['error'] = array(
				'type'                 => 'object',
				'required'             => array( 'kind', 'message' ),
				'additionalProperties' => false,
				'properties'           => array(
					'kind'    => array(
						'type'      => 'string',
						'minLength' => 1,
						'pattern'   => '^[a-z0-9_-]+$',
					),
					'message' => array(
						'type'      => 'string',
						'minLength' => 1,
					),
				),
			);
		}

		return array(
			'type'                 => 'object',
			'required'             => array_keys( $properties ),
			'additionalProperties' => false,
			'properties'           => $properties,
		);
	}

	/**
	 * Validate cross-field invariants not expressible in the REST schema.
	 *
	 * The schema owns exact object fields and scalar ranges. This pass owns facts
	 * derived across fields: JSON object/array distinctions, aggregate counts,
	 * score consistency, investigation state, paths, and byte limits.
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

		if ( ! in_array( $data['status'], array( 'completed', 'failed' ), true ) ) {
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

		$expected_severity_counts = array();
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

		$bounded_strings = array(
			array( $finding['id'], 64 ),
			array( $finding['ref'], 256 ),
			array( $finding['title'], 512 ),
			array( $finding['file_path'], 1024 ),
			array( $finding['investigation']['summary'], 1024 ),
		);

		if ( isset( $finding['code_snippet'] ) ) {
			$bounded_strings[] = array( $finding['code_snippet'], 4096 );
		}

		if ( isset( $finding['explanation'] ) ) {
			$bounded_strings[] = array( $finding['explanation'], 4096 );
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

		return 'http' === $scheme && in_array( $host, array( 'localhost', '127.0.0.1' ), true );
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
			array( 'status' => WP_Http::BAD_REQUEST )
		);
	}

	/**
	 * Receive a security scan callback.
	 *
	 * The URL slug owns routing; the body slug is an assertion. Preserve the raw
	 * body because replay identity is its SHA-256, not `verdict_hash` or re-encoded
	 * JSON.
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
				array( 'status' => WP_Http::NOT_FOUND )
			);
		}

		$raw_body = $request->get_body();

		// Decode once for JSON object/array identity and once for validated PHP access.
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
			$error = new WP_Error( 'invalid_gandalf_scan', 'Security scan callback slug does not match the plugin.', array( 'status' => WP_Http::BAD_REQUEST ) );
			Plugin_Scan_Gandalf::record_invalid_callback( $plugin, $error, sanitize_text_field( $data['scan_id'] ) );
			return $error;
		}

		$result = Plugin_Scan_Gandalf::handle_callback( $plugin, $data, hash( 'sha256', $raw_body ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'success' => true,
		);
	}
}
