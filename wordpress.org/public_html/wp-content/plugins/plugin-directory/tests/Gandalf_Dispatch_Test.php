<?php
/**
 * Tests for the Gandalf release identity sent after an import.
 *
 * @package WordPressdotorg\Plugin_Directory\Tests
 */

declare( strict_types = 1 );

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use WordPressdotorg\Plugin_Directory\Jobs\Plugin_Scan;
use WordPressdotorg\Plugin_Directory\Jobs\Plugin_Scan_Gandalf;
use WordPressdotorg\Plugin_Directory\Plugin_Directory;

/**
 * Tests Gandalf dispatch identity across the delayed cron boundary.
 *
 * @group jobs
 */
#[Group( 'jobs' )]
class Gandalf_Dispatch_Test extends TestCase {

	/**
	 * The plugin post under test.
	 *
	 * @var \WP_Post
	 */
	private \WP_Post $plugin;

	/**
	 * Define the shared secret used by dispatch.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		if ( ! defined( 'WP_GANDALF_SCAN_SHARED_SECRET' ) ) {
			define( 'WP_GANDALF_SCAN_SHARED_SECRET', 'test-secret' );
		}
	}

	/**
	 * Create an imported candidate and an independently identified served row.
	 */
	protected function setUp(): void {
		parent::setUp();

		wp_cache_flush();

		$plugin = Plugin_Directory::create_plugin_post(
			array(
				'post_name'   => 'gandalf-dispatch-' . wp_generate_password( 8, false ),
				'post_title'  => 'Gandalf Dispatch Test Plugin',
				'post_status' => 'publish',
			)
		);

		$this->assertInstanceOf( \WP_Post::class, $plugin );
		$this->plugin = $plugin;

		update_post_meta( $plugin->ID, 'version', '1.0.2' );
		update_post_meta( $plugin->ID, 'stable_tag', '1.0.2' );
		update_post_meta( $plugin->ID, 'last_version', '1.0.1' );
		update_post_meta( $plugin->ID, 'last_stable_tag', '1.0.1' );
		update_post_meta(
			$plugin->ID,
			'releases',
			array(
				array(
					'date'                     => time(),
					'tag'                      => 'release-1.0.0',
					'version'                  => '1.0.0',
					'zips_built'               => true,
					'zips_built_from_revision' => 0,
					'confirmations'            => array(),
					'confirmed'                => true,
					'confirmations_required'   => 0,
					'committer'                => array(),
					'revision'                 => array(),
					'release_delay'            => DAY_IN_SECONDS,
				),
			)
		);

		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'update_source', array( 'plugin_id' => $plugin->ID ) );
		$wpdb->delete( $wpdb->prefix . 'update_source', array( 'plugin_slug' => $plugin->post_name ) );
		$wpdb->insert(
			$wpdb->prefix . 'update_source',
			array(
				'plugin_id'        => $plugin->ID,
				'plugin_slug'      => $plugin->post_name,
				'available'        => 1,
				'version'          => '1.0.0',
				'stable_tag'       => 'release-1.0.0',
				'plugin_name'      => $plugin->post_title,
				'requires_plugins' => '',
				'last_updated'     => $plugin->post_modified,
			)
		);
	}

	/**
	 * Remove filters and fixture data.
	 */
	protected function tearDown(): void {
		wp_delete_post( $this->plugin->ID, true );

		parent::tearDown();
	}

	/**
	 * A delayed dispatch keeps its import identity and uses the exact served row
	 * rather than mutable post meta or importer history.
	 */
	public function test_delayed_dispatch_uses_frozen_candidate_and_served_baseline(): void {
		$scheduled_context = null;
		$schedule_filter   = static function ( $pre, $event ) use ( &$scheduled_context ) {
			$scheduled_context = $event->args[2];
			return false;
		};
		add_filter(
			'pre_schedule_event',
			$schedule_filter,
			10,
			2
		);

		try {
			update_post_meta( $this->plugin->ID, 'version', '1.0.3' );
			do_action( 'wporg_plugins_imported', $this->plugin, '1.0.2', '1.0.1', array( '1.0.2' ), 12345, array(), '1.0.2' );
		} finally {
			remove_filter( 'pre_schedule_event', $schedule_filter );
		}
		$this->assertIsArray( $scheduled_context );

		// Later publication state must not relabel the queued candidate or baseline.
		update_post_meta( $this->plugin->ID, 'stable_tag', '1.0.3' );
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'update_source',
			array(
				'version'    => '1.0.3',
				'stable_tag' => '1.0.3',
			),
			array( 'plugin_slug' => $this->plugin->post_name )
		);

		$sent_body   = null;
		$http_filter = static function ( $preempt, $args ) use ( &$sent_body ) {
			$sent_body = json_decode( $args['body'], true );

			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'scan_id'     => $sent_body['scan_id'],
						'accepted_at' => time(),
					)
				),
				'response' => array(
					'code'    => 202,
					'message' => 'Accepted',
				),
			);
		};
		add_filter(
			'pre_http_request',
			$http_filter,
			10,
			2
		);

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Intercepts the expected dispatch notice.
		set_error_handler(
			static function ( $error_level ) {
				return E_USER_NOTICE === $error_level;
			}
		);
		try {
			Plugin_Scan::cron_trigger( $this->plugin->post_name, array(), $scheduled_context );
		} finally {
			restore_error_handler();
			remove_filter( 'pre_http_request', $http_filter );
		}

		$this->assertSame(
			array(
				'version'              => '1.0.2',
				'release_ref'          => '1.0.2',
				'previous_version'     => '1.0.0',
				'previous_release_ref' => 'release-1.0.0',
				'previous_zip_url'     => 'https://downloads.wordpress.org/plugin/' . $this->plugin->post_name . '.release-1.0.0.zip',
			),
			array_intersect_key(
				$sent_body,
				array_flip( array( 'version', 'release_ref', 'previous_version', 'previous_release_ref', 'previous_zip_url' ) )
			)
		);
	}

	/**
	 * A served release later found malicious is not reused as a clean baseline.
	 */
	public function test_dispatch_with_blocked_served_release_uses_no_baseline(): void {
		update_post_meta(
			$this->plugin->ID,
			'releases',
			array(
				array(
					'date'                     => time(),
					'tag'                      => 'release-1.0.0',
					'version'                  => '1.0.0',
					'zips_built'               => true,
					'zips_built_from_revision' => 0,
					'confirmations'            => array(),
					'confirmed'                => true,
					'confirmations_required'   => 0,
					'committer'                => array(),
					'revision'                 => array(),
					'release_delay'            => DAY_IN_SECONDS,
					'release_block'            => array( 'scan_id' => wp_generate_uuid4() ),
				),
			)
		);

		$sent_body   = null;
		$http_filter = static function ( $preempt, $args ) use ( &$sent_body ) {
			$sent_body = json_decode( $args['body'], true );

			return new \WP_Error( 'http_request_failed', 'The acknowledgement was lost.' );
		};
		add_filter(
			'pre_http_request',
			$http_filter,
			10,
			2
		);

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Intercepts the expected dispatch notice.
		set_error_handler(
			static function ( $error_level ) {
				return E_USER_NOTICE === $error_level;
			}
		);
		try {
			$this->assertFalse(
				Plugin_Scan_Gandalf::dispatch_from_import_context(
					$this->plugin,
					array(
						'stable_tag'       => '1.0.2',
						'old_stable_tag'   => '1.0.1',
						'changed_svn_tags' => array( '1.0.2' ),
						'version'          => '1.0.2',
						'served_release'   => array(
							'version'    => '1.0.0',
							'stable_tag' => 'release-1.0.0',
						),
					)
				)
			);
		} finally {
			restore_error_handler();
			remove_filter( 'pre_http_request', $http_filter );
		}

		$pending = get_post_meta( $this->plugin->ID, Plugin_Scan_Gandalf::PENDING_META_KEY, true );
		$this->assertArrayHasKey( $sent_body['scan_id'], $pending );
		$this->assertNull( $sent_body['previous_version'] );
		$this->assertNull( $sent_body['previous_release_ref'] );
		$this->assertNull( $sent_body['previous_zip_url'] );
	}

	/**
	 * A reused ref cannot provide distinct previous bytes after its Version changes.
	 */
	public function test_dispatch_with_same_ref_version_change_uses_no_baseline(): void {
		$release            = Plugin_Directory::get_release( $this->plugin, 'release-1.0.0' );
		$release['tag']     = 'shared-tag';
		$release['version'] = '1.0.2';
		update_post_meta( $this->plugin->ID, 'releases', array( $release ) );

		$sent_body   = null;
		$http_filter = static function ( $preempt, $args ) use ( &$sent_body ) {
			$sent_body = json_decode( $args['body'], true );

			return new \WP_Error( 'http_request_failed', 'The acknowledgement was lost.' );
		};
		add_filter( 'pre_http_request', $http_filter, 10, 2 );

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Intercepts the expected dispatch notice.
		set_error_handler(
			static function ( $error_level ) {
				return E_USER_NOTICE === $error_level;
			}
		);
		try {
			$this->assertFalse(
				Plugin_Scan_Gandalf::dispatch_from_import_context(
					$this->plugin,
					array(
						'stable_tag'       => 'shared-tag',
						'old_stable_tag'   => 'shared-tag',
						'changed_svn_tags' => array( 'shared-tag' ),
						'version'          => '1.0.2',
						'served_release'   => array(
							'version'    => '1.0.0',
							'stable_tag' => 'shared-tag',
						),
					)
				)
			);
		} finally {
			restore_error_handler();
			remove_filter( 'pre_http_request', $http_filter );
		}

		$this->assertSame( '1.0.2', $sent_body['version'] );
		$this->assertSame( 'shared-tag', $sent_body['release_ref'] );
		$this->assertNull( $sent_body['previous_version'] );
		$this->assertNull( $sent_body['previous_release_ref'] );
		$this->assertNull( $sent_body['previous_zip_url'] );
	}

	/**
	 * A served identity whose release record now describes different bytes is an
	 * internal error, not permission to treat that artifact as a clean baseline.
	 */
	public function test_mismatched_served_release_record_throws(): void {
		$release            = Plugin_Directory::get_release( $this->plugin, 'release-1.0.0' );
		$release['version'] = '9.9.9';
		update_post_meta( $this->plugin->ID, 'releases', array( $release ) );

		$this->expectException( \UnexpectedValueException::class );
		$this->expectExceptionMessage( 'served Gandalf baseline' );

		Plugin_Scan_Gandalf::dispatch_from_import_context(
			$this->plugin,
			array(
				'stable_tag'       => '1.0.2',
				'old_stable_tag'   => '1.0.1',
				'changed_svn_tags' => array( '1.0.2' ),
				'version'          => '1.0.2',
				'served_release'   => array(
					'version'    => '1.0.0',
					'stable_tag' => 'release-1.0.0',
				),
			)
		);
	}

	/**
	 * Broken cron state throws instead of silently skipping the security scan.
	 */
	public function test_missing_import_version_throws(): void {
		$this->expectException( \UnexpectedValueException::class );
		$this->expectExceptionMessage( 'missing a required field' );

		Plugin_Scan_Gandalf::dispatch_from_import_context(
			$this->plugin,
			array(
				'stable_tag'       => '1.0.2',
				'old_stable_tag'   => '1.0.1',
				'changed_svn_tags' => array( '1.0.2' ),
				'served_release'   => false,
			)
		);
	}

	/**
	 * A 202 response is not an acknowledgement unless it has the exact contract.
	 */
	public function test_incomplete_dispatch_acknowledgement_is_rejected(): void {
		$scan_id     = wp_generate_uuid4();
		$old_scan_id = wp_generate_uuid4();
		update_post_meta(
			$this->plugin->ID,
			Plugin_Scan_Gandalf::PENDING_META_KEY,
			array(
				$old_scan_id => array(
					'version'      => '0.9.0',
					'release_ref'  => '0.9.0',
					'requested_at' => time() - 2 * DAY_IN_SECONDS,
				),
			)
		);

		$http_filter = static function () use ( $scan_id ) {
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( array( 'scan_id' => $scan_id ) ),
				'response' => array(
					'code'    => 202,
					'message' => 'Accepted',
				),
			);
		};
		add_filter( 'pre_http_request', $http_filter );

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Intercepts the expected dispatch notice.
		set_error_handler(
			static function ( $error_level ) {
				return E_USER_NOTICE === $error_level;
			}
		);
		try {
			$result = Plugin_Scan_Gandalf::dispatch(
				$this->plugin,
				array(
					'scan_id'      => $scan_id,
					'version'      => '1.0.2',
					'release_ref'  => '1.0.2',
					'requested_at' => time(),
				)
			);
		} finally {
			restore_error_handler();
			remove_filter( 'pre_http_request', $http_filter );
		}

		$this->assertFalse( $result );
		$pending = get_post_meta( $this->plugin->ID, Plugin_Scan_Gandalf::PENDING_META_KEY, true );
		$this->assertArrayHasKey( $old_scan_id, $pending );
		$this->assertArrayHasKey( $scan_id, $pending );
	}

	/**
	 * A scan is not sent unless its callback identity was persisted first.
	 */
	public function test_dispatch_stops_when_pending_identity_cannot_be_stored(): void {
		$http_calls      = 0;
		$metadata_filter = function ( $check, $object_id, $meta_key ) {
			if ( $this->plugin->ID === $object_id && Plugin_Scan_Gandalf::PENDING_META_KEY === $meta_key ) {
				return false;
			}

			return $check;
		};
		$http_filter = static function () use ( &$http_calls ) {
			++$http_calls;
			return new \WP_Error( 'unexpected_request', 'The request should not have been sent.' );
		};
		add_filter( 'update_post_metadata', $metadata_filter, 10, 3 );
		add_filter( 'pre_http_request', $http_filter );
		try {
			Plugin_Scan_Gandalf::dispatch(
				$this->plugin,
				array(
					'scan_id'      => wp_generate_uuid4(),
					'version'      => '1.0.2',
					'release_ref'  => '1.0.2',
					'requested_at' => time(),
				)
			);
			$this->fail( 'Dispatch continued without a stored pending identity.' );
		} catch ( \UnexpectedValueException $error ) {
			$this->assertStringContainsString( 'could not be stored', $error->getMessage() );
		} finally {
			remove_filter( 'update_post_metadata', $metadata_filter );
			remove_filter( 'pre_http_request', $http_filter );
		}

		$this->assertSame( 0, $http_calls );
	}
}
