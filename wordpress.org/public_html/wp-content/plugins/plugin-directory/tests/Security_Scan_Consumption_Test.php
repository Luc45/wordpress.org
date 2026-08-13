<?php
/**
 * Tests for the exactly-once consumption of security scan callbacks.
 *
 * @package WordPressdotorg\Plugin_Directory\Tests
 */

declare( strict_types = 1 );

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use WordPressdotorg\Plugin_Directory\Jobs\Plugin_Scan_Gandalf;
use WordPressdotorg\Plugin_Directory\Plugin_Directory;

/**
 * Tests that security scan callbacks are consumed exactly once.
 *
 * Extends the plain PHPUnit TestCase: WP_UnitTestCase is not compatible with
 * the PHPUnit 11 runner used by this suite. Isolation comes from giving every
 * test its own plugin post instead of per-test transactions.
 *
 * The group is declared as an attribute as well as `@group`: PHPUnit 11 ignores
 * a class-level `@group` docblock, while older runners ignore the attribute.
 *
 * @group jobs
 */
#[Group( 'jobs' )]
class Security_Scan_Consumption_Test extends TestCase {

	/** The scan ID used for the candidate fixture. */
	private const SCAN_ID = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

	/** The version and release ref used for the candidate fixture. */
	private const VERSION = '1.4.4';

	/**
	 * Counter to give every test plugin a unique slug.
	 *
	 * @var int
	 */
	private static int $plugin_count = 0;

	/**
	 * The plugin post under test.
	 *
	 * @var \WP_Post
	 */
	private \WP_Post $plugin;

	/**
	 * Create a published plugin with a staged security scan candidate.
	 */
	protected function setUp(): void {
		parent::setUp();

		wp_cache_flush();

		$plugin = Plugin_Directory::create_plugin_post(
			array(
				'post_name'   => 'consumption-test-' . ( ++self::$plugin_count ),
				'post_title'  => 'Scan Consumption Test Plugin',
				'post_status' => 'publish',
			)
		);

		$this->assertInstanceOf( \WP_Post::class, $plugin );
		$this->plugin = $plugin;

		$this->add_candidate( self::SCAN_ID );
	}

	/**
	 * Register a staged scan candidate on the plugin fixture.
	 *
	 * @param string $scan_id The scan ID to register.
	 */
	private function add_candidate( string $scan_id ): void {
		update_post_meta(
			$this->plugin->ID,
			Plugin_Scan_Gandalf::CANDIDATE_META_KEY,
			array(
				'version'      => self::VERSION,
				'release_ref'  => self::VERSION,
				'scan_id'      => $scan_id,
				'state'        => 'scanning',
				'requested_at' => time(),
				'artifact'     => array(
					'current_zip_url'    => 'https://downloads.wordpress.org/plugin/consumption-test.gandalf.zip',
					'current_zip_sha256' => str_repeat( 'a', 64 ),
				),
			)
		);
	}

	/**
	 * Hash the exact callback body passed to the receiver.
	 *
	 * @param array $callback The callback data.
	 * @return string The callback body hash.
	 */
	private function callback_hash( array $callback ): string {
		return hash( 'sha256', (string) wp_json_encode( $callback ) );
	}

	/**
	 * Build a finding entry matching the callback contract.
	 *
	 * @param float $risk_score The finding risk score.
	 * @return array The finding.
	 */
	private function finding( float $risk_score ): array {
		return array(
			'id'            => 'finding-' . md5( (string) $risk_score ),
			'ref'           => 'prompt-security.supply_chain.remote_controlled_code',
			'title'         => 'Remote response controls a PHP callable',
			'severity'      => 'error',
			'file_path'     => 'includes/class-admin.php',
			'line'          => 688,
			'code_snippet'  => '$clean = $this->write;',
			'explanation'   => 'The response body reaches a callable.',
			'risk_score'    => $risk_score,
			'investigation' => array(
				'status'  => 'completed',
				'result'  => 'reproduced',
				'summary' => 'The unauthenticated probe reached the sink.',
			),
		);
	}

	/**
	 * Build a completed callback matching the staged scan fixture.
	 *
	 * @param array $overrides Fields to override.
	 * @return array The callback data.
	 */
	private function completed_callback( array $overrides = array() ): array {
		$defaults = array(
			'status'          => 'completed',
			'scan_id'         => self::SCAN_ID,
			'subject_type'    => 'plugin',
			'slug'            => $this->plugin->post_name,
			'version'         => self::VERSION,
			'release_ref'     => self::VERSION,
			'completed_at'    => time(),
			'verdict_hash'    => 'f71c3d944050095a4e2e20f9ee8a7c9a',
			'findings_count'  => 2,
			'findings'        => array( $this->finding( 9.8 ), $this->finding( 5.2 ) ),
			'max_risk_score'  => 9.8,
			'severity_counts' => array( 'error' => 2 ),
			'scanner_version' => '0.3.0',
			'report_url'      => 'https://scanner.example/runs/' . self::SCAN_ID,
		);

		return array_merge( $defaults, $overrides );
	}

	/**
	 * Build a failed callback matching the staged scan fixture.
	 *
	 * @param array $overrides Fields to override.
	 * @return array The callback data.
	 */
	private function failed_callback( array $overrides = array() ): array {
		$defaults = array(
			'status'       => 'failed',
			'scan_id'      => self::SCAN_ID,
			'subject_type' => 'plugin',
			'slug'         => $this->plugin->post_name,
			'version'      => self::VERSION,
			'release_ref'  => self::VERSION,
			'completed_at' => time(),
			'report_url'   => 'https://scanner.example/runs/' . self::SCAN_ID,
			'error'        => array(
				'kind'    => 'timeout',
				'message' => 'Scan exceeded the runtime deadline.',
			),
		);

		return array_merge( $defaults, $overrides );
	}

	/**
	 * An identical retry of a consumed callback is acknowledged without
	 * repeating effects.
	 *
	 * Before consumption records existed, a retry arriving after the candidate
	 * was cleared was rejected as an unknown scan.
	 */
	public function test_identical_replay_is_acknowledged_once(): void {
		$callback = $this->completed_callback();

		$this->assertTrue( Plugin_Scan_Gandalf::handle_callback( $this->plugin, $callback, $this->callback_hash( $callback ) ) );
		$this->assertEmpty( get_post_meta( $this->plugin->ID, Plugin_Scan_Gandalf::CANDIDATE_META_KEY, true ) );

		$consumed = get_post_meta( $this->plugin->ID, Plugin_Scan_Gandalf::CONSUMED_META_KEY, true );
		$this->assertArrayHasKey( self::SCAN_ID, $consumed );

		// Clearing the Slack dedup record exposes whether the retry re-runs notification effects.
		delete_post_meta( $this->plugin->ID, Plugin_Scan_Gandalf::NOTIFIED_META_KEY );

		$this->assertTrue( Plugin_Scan_Gandalf::handle_callback( get_post( $this->plugin->ID ), $callback, $this->callback_hash( $callback ) ) );
		$this->assertEmpty( get_post_meta( $this->plugin->ID, Plugin_Scan_Gandalf::NOTIFIED_META_KEY, true ) );
	}

	/**
	 * A different callback body for a consumed scan is rejected as a conflict.
	 */
	public function test_conflicting_replay_is_rejected(): void {
		$callback = $this->completed_callback();
		$this->assertTrue( Plugin_Scan_Gandalf::handle_callback( $this->plugin, $callback, $this->callback_hash( $callback ) ) );

		$conflicting = $this->completed_callback( array( 'report_url' => 'https://scanner.example/runs/other' ) );
		$result      = Plugin_Scan_Gandalf::handle_callback( get_post( $this->plugin->ID ), $conflicting, $this->callback_hash( $conflicting ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'conflicting_gandalf_callback', $result->get_error_code() );
	}

	/**
	 * A reordered callback is a different exact body and therefore conflicts.
	 */
	public function test_reordered_replay_is_rejected(): void {
		$callback = $this->completed_callback();

		$this->assertTrue( Plugin_Scan_Gandalf::handle_callback( $this->plugin, $callback, $this->callback_hash( $callback ) ) );

		$reordered             = array_reverse( $callback, true );
		$reordered['findings'] = array_map(
			static function ( array $finding ): array {
				$finding['investigation'] = array_reverse( $finding['investigation'], true );
				return array_reverse( $finding, true );
			},
			$reordered['findings']
		);

		$result = Plugin_Scan_Gandalf::handle_callback( get_post( $this->plugin->ID ), $reordered, $this->callback_hash( $reordered ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'conflicting_gandalf_callback', $result->get_error_code() );
	}

	/**
	 * A completed body conflicts with an already-consumed failed body.
	 */
	public function test_completed_verdict_conflicts_with_failed_report(): void {
		$failed = $this->failed_callback();
		$this->assertTrue( Plugin_Scan_Gandalf::handle_callback( $this->plugin, $failed, $this->callback_hash( $failed ) ) );

		$completed = $this->completed_callback();
		$result    = Plugin_Scan_Gandalf::handle_callback( get_post( $this->plugin->ID ), $completed, $this->callback_hash( $completed ) );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'conflicting_gandalf_callback', $result->get_error_code() );

		$consumed = get_post_meta( $this->plugin->ID, Plugin_Scan_Gandalf::CONSUMED_META_KEY, true );
		$this->assertSame( 'failed', $consumed[ self::SCAN_ID ]['outcome'] );
	}

	/**
	 * A failure report records the error and terminally consumes the candidate.
	 */
	public function test_failed_scan_records_error(): void {
		$callback = $this->failed_callback();
		$this->assertTrue( Plugin_Scan_Gandalf::handle_callback( $this->plugin, $callback, $this->callback_hash( $callback ) ) );
		$this->assertSame( 'publish', get_post( $this->plugin->ID )->post_status );

		$last_error = get_post_meta( $this->plugin->ID, Plugin_Scan_Gandalf::LAST_ERROR_META_KEY, true );
		$this->assertSame( 'timeout', $last_error['kind'] );

		$consumed = get_post_meta( $this->plugin->ID, Plugin_Scan_Gandalf::CONSUMED_META_KEY, true );
		$this->assertArrayHasKey( self::SCAN_ID, $consumed );

		$this->assertEmpty( get_post_meta( $this->plugin->ID, Plugin_Scan_Gandalf::CANDIDATE_META_KEY, true ) );
	}

	/**
	 * A callback for a different version than the staged scan is rejected
	 * and not recorded as consumed.
	 */
	public function test_mismatched_version_is_rejected(): void {
		$callback = $this->completed_callback( array( 'version' => '9.9.9' ) );
		$result   = Plugin_Scan_Gandalf::handle_callback( $this->plugin, $callback, $this->callback_hash( $callback ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'invalid_gandalf_scan', $result->get_error_code() );

		$this->assertEmpty( get_post_meta( $this->plugin->ID, Plugin_Scan_Gandalf::CONSUMED_META_KEY, true ) );
	}

	/**
	 * A callback arriving while another decision owns the candidate is rejected.
	 */
	public function test_concurrent_callback_is_rejected_while_deciding(): void {
		$candidate          = get_post_meta( $this->plugin->ID, Plugin_Scan_Gandalf::CANDIDATE_META_KEY, true );
		$candidate['state'] = 'deciding';
		update_post_meta( $this->plugin->ID, Plugin_Scan_Gandalf::CANDIDATE_META_KEY, $candidate );

		$callback = $this->completed_callback();
		$result   = Plugin_Scan_Gandalf::handle_callback( $this->plugin, $callback, $this->callback_hash( $callback ) );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'gandalf_callback_in_progress', $result->get_error_code() );
		$this->assertEmpty( get_post_meta( $this->plugin->ID, Plugin_Scan_Gandalf::CONSUMED_META_KEY, true ) );

		$candidate['state'] = 'scanning';
		update_post_meta( $this->plugin->ID, Plugin_Scan_Gandalf::CANDIDATE_META_KEY, $candidate );
		$this->assertTrue( Plugin_Scan_Gandalf::handle_callback( $this->plugin, $callback, $this->callback_hash( $callback ) ) );
	}
}
