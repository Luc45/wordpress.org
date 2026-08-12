<?php
/**
 * Tests for update_source writes while a version is held by a cooldown or block.
 *
 * @package WordPressdotorg\Plugin_Directory\Tests
 */

declare( strict_types = 1 );

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use WordPressdotorg\Plugin_Directory\CLI\Import;
use WordPressdotorg\Plugin_Directory\Jobs\API_Update_Updater;
use WordPressdotorg\Plugin_Directory\Plugin_Directory;

/**
 * Tests that a hold — a release cooldown or a release block — defers only the
 * version bump, while status changes reach the `update_source` row immediately.
 * A cooldown expires on its own; a block lasts until the release is force-released.
 *
 * @group jobs
 */
#[Group( 'jobs' )]
class Update_Source_Hold_Test extends TestCase {

	/** The version served by the update_source row fixture. */
	private const SERVED_VERSION = '1.0.0';

	/** The newer version held by the cooldown or block. */
	private const STAGED_VERSION = '1.4.4';

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
	 * Create a published plugin with a staged release in cooldown.
	 */
	protected function setUp(): void {
		parent::setUp();

		wp_cache_flush();

		// Tools::audit_log() reads it unguarded.
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

		$plugin = Plugin_Directory::create_plugin_post(
			array(
				'post_name'   => 'update-source-test-' . ( ++self::$plugin_count ),
				'post_title'  => 'Update Source Test Plugin',
				'post_status' => 'publish',
			)
		);

		$this->assertInstanceOf( \WP_Post::class, $plugin );
		$this->plugin = $plugin;

		/*
		 * The stub update_source table survives across runs — the WP test
		 * installer only drops core tables — so clear leftovers that would
		 * collide with this run's plugin ID or read as a served version.
		 */
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'update_source', array( 'plugin_id' => $this->plugin->ID ) );
		$wpdb->delete( $wpdb->prefix . 'update_source', array( 'plugin_slug' => $this->plugin->post_name ) );

		update_post_meta( $this->plugin->ID, 'version', self::STAGED_VERSION );
		update_post_meta( $this->plugin->ID, 'stable_tag', self::STAGED_VERSION );
		update_post_meta(
			$this->plugin->ID,
			'releases',
			array(
				array(
					'date'                     => time(),
					'tag'                      => self::STAGED_VERSION,
					'version'                  => self::STAGED_VERSION,
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
	}

	/**
	 * Insert an update_source row serving a version.
	 *
	 * @param string      $version    The version the row serves.
	 * @param string|null $stable_tag The stable tag the row serves. Defaults to the version.
	 */
	private function insert_served_row( string $version = self::SERVED_VERSION, ?string $stable_tag = null ): void {
		global $wpdb;
		if ( null === $stable_tag ) {
			$stable_tag = $version;
		}

		$wpdb->insert(
			$wpdb->prefix . 'update_source',
			array(
				'plugin_id'        => $this->plugin->ID,
				'plugin_slug'      => $this->plugin->post_name,
				'available'        => 1,
				'version'          => $version,
				'stable_tag'       => $stable_tag,
				'plugin_name'      => $this->plugin->post_title,
				'requires_plugins' => '',
				'last_updated'     => $this->plugin->post_modified,
			)
		);
	}

	/**
	 * Fetch the plugin's update_source row.
	 *
	 * @return object|null The row, or null when none exists.
	 */
	private function get_row(): ?object {
		global $wpdb;

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT available, version, stable_tag, meta FROM {$wpdb->prefix}update_source WHERE plugin_slug = %s",
				$this->plugin->post_name
			)
		);
	}

	/**
	 * Assert the exact release identity offered by the update API.
	 *
	 * @param string $version    Served Version header.
	 * @param string $stable_tag Served stable ref.
	 */
	private function assert_served_release( string $version, string $stable_tag ): void {
		$this->assertSame(
			array(
				'version'    => $version,
				'stable_tag' => $stable_tag,
			),
			API_Update_Updater::get_served_release_identity( $this->plugin->post_name )
		);
	}

	/**
	 * Set the plugin's status, mirroring the closure meta the admin UI writes.
	 *
	 * @param string $status The new post status.
	 */
	private function set_status( string $status ): void {
		wp_update_post(
			array(
				'ID'          => $this->plugin->ID,
				'post_status' => $status,
			)
		);

		if ( in_array( $status, array( 'closed', 'disabled' ), true ) ) {
			update_post_meta( $this->plugin->ID, '_close_reason', 'security-issue' );
			update_post_meta( $this->plugin->ID, 'plugin_closed_date', current_time( 'mysql' ) );
		} else {
			delete_post_meta( $this->plugin->ID, '_close_reason' );
			delete_post_meta( $this->plugin->ID, 'plugin_closed_date' );
		}
	}

	/**
	 * Block the staged release of the plugin fixture.
	 *
	 * @return bool Whether the release was blocked.
	 */
	private function block(): bool {
		return API_Update_Updater::block_release(
			$this->plugin->post_name,
			self::STAGED_VERSION,
			self::STAGED_VERSION,
			array( 'reason' => 'High-risk release.' )
		);
	}

	/**
	 * Fetch the release record for the staged version.
	 *
	 * @return array|false The release, or false when none exists.
	 */
	private function get_release(): array|false {
		return Plugin_Directory::get_release( get_post( $this->plugin->ID ), self::STAGED_VERSION );
	}

	/**
	 * Fetch the plugin's audit log entries as a single string.
	 *
	 * @return string The concatenated internal-note comments.
	 */
	private function get_audit_log(): string {
		$notes = get_comments(
			array(
				'post_id' => $this->plugin->ID,
				'type'    => 'internal-note',
			)
		);

		return implode( ' ', wp_list_pluck( $notes, 'comment_content' ) );
	}

	/**
	 * A new version inside its cooldown stays deferred; the row keeps serving
	 * the previous version.
	 */
	public function test_version_bump_is_deferred_during_cooldown(): void {
		$this->insert_served_row();

		$this->assertTrue( API_Update_Updater::update_single_plugin( $this->plugin->post_name ) );

		$row = $this->get_row();
		$this->assertSame( '1', $row->available );
		$this->assertSame( self::SERVED_VERSION, $row->version );
		$this->assertNotFalse( wp_next_scheduled( "release_to_update_api:{$this->plugin->post_name}" ) );
	}

	/**
	 * Closing a plugin mid-cooldown withdraws its row immediately, still on
	 * the served version.
	 */
	public function test_closure_during_cooldown_reaches_row_immediately(): void {
		$this->insert_served_row();
		$this->set_status( 'closed' );

		$this->assertTrue( API_Update_Updater::update_single_plugin( $this->plugin->post_name ) );

		$row = $this->get_row();
		$this->assertSame( '0', $row->available );
		$this->assertStringContainsString( 'closed_at', (string) $row->meta );
		$this->assertSame( self::SERVED_VERSION, $row->version );
	}

	/**
	 * Disabling a plugin mid-cooldown records its closure meta while the row
	 * stays available.
	 */
	public function test_disable_during_cooldown_records_closure_meta(): void {
		$this->insert_served_row();
		$this->set_status( 'disabled' );

		$this->assertTrue( API_Update_Updater::update_single_plugin( $this->plugin->post_name ) );

		$row = $this->get_row();
		$this->assertSame( '1', $row->available );
		$this->assertStringContainsString( 'closed_at', (string) $row->meta );
		$this->assertSame( self::SERVED_VERSION, $row->version );
	}

	/**
	 * Reopening a closed plugin mid-cooldown restores its row immediately;
	 * only the version bump keeps waiting for the cooldown.
	 */
	public function test_reopen_during_cooldown_restores_row(): void {
		$this->insert_served_row();

		$this->set_status( 'closed' );
		$this->assertTrue( API_Update_Updater::update_single_plugin( $this->plugin->post_name ) );

		$this->set_status( 'publish' );
		$this->assertTrue( API_Update_Updater::update_single_plugin( $this->plugin->post_name ) );

		$row = $this->get_row();
		$this->assertSame( '1', $row->available );
		$this->assertStringNotContainsString( 'closed_at', (string) $row->meta );
		$this->assertSame( self::SERVED_VERSION, $row->version );
	}

	/**
	 * A first-ever release in cooldown has no row to sync; none is created
	 * until the cooldown expires.
	 */
	public function test_first_release_in_cooldown_creates_no_row(): void {
		$this->set_status( 'closed' );

		$this->assertTrue( API_Update_Updater::update_single_plugin( $this->plugin->post_name ) );

		$this->assertNull( $this->get_row() );
	}

	/**
	 * Blocking an unserved release records the hold and keeps the row on the
	 * served version, with the deferred serve cancelled.
	 */
	public function test_block_holds_unserved_version(): void {
		$this->insert_served_row();

		// Schedule the deferred serve that the block is expected to cancel.
		$this->assertTrue( API_Update_Updater::update_single_plugin( $this->plugin->post_name ) );
		$this->assertNotFalse( wp_next_scheduled( "release_to_update_api:{$this->plugin->post_name}" ) );

		$this->assertTrue( $this->block() );
		$this->assertTrue( API_Update_Updater::is_release_blocked( $this->get_release() ) );

		$block = $this->get_release()['release_block'];
		$this->assertSame( 'High-risk release.', $block['reason'] );
		$this->assertNotEmpty( $block['blocked_at'] );

		$this->assert_served_release( self::SERVED_VERSION, self::SERVED_VERSION );
		$this->assertFalse( wp_next_scheduled( "release_to_update_api:{$this->plugin->post_name}" ) );
	}

	/**
	 * A release is selected and held by its stable tag even when that tag differs
	 * from the plugin Version header.
	 */
	public function test_block_targets_exact_stable_tag(): void {
		$release        = $this->get_release();
		$release['tag'] = 'release-1.4.4';

		update_post_meta( $this->plugin->ID, 'stable_tag', $release['tag'] );
		update_post_meta( $this->plugin->ID, 'releases', array( $release ) );
		$this->insert_served_row();

		$this->assertTrue(
			API_Update_Updater::block_release(
				$this->plugin->post_name,
				self::STAGED_VERSION,
				$release['tag'],
				array( 'reason' => 'High-risk release.' )
			)
		);

		$blocked = Plugin_Directory::get_release( $this->plugin, $release['tag'] );
		$this->assertTrue( API_Update_Updater::is_release_blocked( $blocked ) );
		$this->assertSame( self::SERVED_VERSION, $this->get_row()->version );
	}

	/**
	 * A tag stays burned if its Version header changes before the callback arrives.
	 */
	public function test_block_burns_ref_after_header_version_changes(): void {
		$release            = $this->get_release();
		$release['version'] = '9.9.9';

		update_post_meta( $this->plugin->ID, 'version', '9.9.9' );
		update_post_meta( $this->plugin->ID, 'releases', array( $release ) );

		$this->assertTrue( $this->block() );
		$this->assertTrue( API_Update_Updater::is_release_blocked( $this->get_release() ) );
	}

	/**
	 * A missing tagged ref must not fall through to a same-version trunk release.
	 */
	public function test_block_does_not_fall_back_to_trunk_release(): void {
		$release        = $this->get_release();
		$release['tag'] = 'trunk@' . self::STAGED_VERSION;

		update_post_meta( $this->plugin->ID, 'releases', array( $release ) );

		$this->assertFalse(
			API_Update_Updater::block_release(
				$this->plugin->post_name,
				self::STAGED_VERSION,
				self::STAGED_VERSION,
				array( 'reason' => 'High-risk release.' )
			)
		);
		$this->assertFalse(
			API_Update_Updater::is_release_blocked(
				Plugin_Directory::get_release( $this->plugin, 'trunk@' . self::STAGED_VERSION )
			)
		);
	}

	/**
	 * A new stable tag uses the importer-owned release activation time even when
	 * its Version header is unchanged.
	 */
	public function test_same_version_new_tag_observes_cooldown(): void {
		$old_version_date = gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS );
		$old_release      = $this->get_release();

		$old_release['tag'] = 'old-tag';
		update_post_meta( $this->plugin->ID, 'stable_tag', 'old-tag' );
		update_post_meta( $this->plugin->ID, 'version_date', $old_version_date );
		update_post_meta( $this->plugin->ID, 'releases', array( $old_release ) );
		$this->insert_served_row( self::STAGED_VERSION, 'old-tag' );

		$readme = (object) array(
			'warnings'          => array(),
			'name'              => 'Update Source Test Plugin',
			'short_description' => 'Test plugin.',
			'sections'          => array(),
			'tags'              => array( 'adopt-me' ),
			'contributors'      => array(),
			'requires'          => '',
			'requires_php'      => '',
			'tested'            => '',
			'donate_link'       => '',
			'license'           => '',
			'license_uri'       => '',
			'upgrade_notice'    => array(),
			'screenshots'       => array(),
		);

		$headers = (object) array(
			'Version'         => self::STAGED_VERSION,
			'Name'            => 'Update Source Test Plugin',
			'Description'     => 'Test plugin.',
			'UpdateURI'       => '',
			'RequiresPlugins' => '',
			'RequiresWP'      => '',
			'RequiresPHP'     => '',
			'TestedUpTo'      => '',
		);

		$data = array(
			'readme'            => $readme,
			'assets'            => array(
				'screenshot' => array(),
				'icon'       => array(),
				'banner'     => array(),
			),
			'plugin_headers'    => $headers,
			'stable_tag'        => 'new-tag',
			'last_committer'    => 'tester',
			'last_revision'     => 123,
			'tagged_versions'   => array( 'new-tag' => array() ),
			'last_modified'     => current_time( 'mysql' ),
			'blocks'            => array(),
			'block_files'       => array(),
			'dashboard_widgets' => array( 'Test Widget' ),
		);

		$importer = $this->getMockBuilder( Import::class )
			->onlyMethods( array( 'export_and_parse_plugin', 'rebuild_affected_zips' ) )
			->getMock();
		$importer->method( 'export_and_parse_plugin' )->willReturn( $data );
		$importer->method( 'rebuild_affected_zips' )->willReturn( true );

		$delay_filter = static function () {
			return DAY_IN_SECONDS;
		};
		add_filter( 'wporg_plugins_release_cooldown_delay', $delay_filter );
		try {
			$this->assertTrue( $importer->import_from_svn( $this->plugin->post_name, array( 'new-tag' ), array(), 123 ) );
		} finally {
			remove_filter( 'wporg_plugins_release_cooldown_delay', $delay_filter );
		}

		$this->assertSame( 'old-tag', $this->get_row()->stable_tag );
		$this->assertNotSame( $old_version_date, get_post_meta( $this->plugin->ID, 'version_date', true ) );
		$this->assertNotFalse( wp_next_scheduled( "release_to_update_api:{$this->plugin->post_name}" ) );
	}

	/**
	 * A required confirmation remains the cooldown anchor for a same-version tag.
	 */
	public function test_same_version_new_tag_uses_confirmation_time(): void {
		$release                           = $this->get_release();
		$release['tag']                    = 'release-1.4.4';
		$release['date']                   = time() - WEEK_IN_SECONDS;
		$release['confirmations_required'] = 1;
		$release['confirmations']          = array( 'reviewer' => time() );

		update_post_meta( $this->plugin->ID, 'stable_tag', $release['tag'] );
		update_post_meta( $this->plugin->ID, 'version_date', gmdate( 'Y-m-d H:i:s', time() - WEEK_IN_SECONDS ) );
		update_post_meta( $this->plugin->ID, 'releases', array( $release ) );
		$this->insert_served_row( self::STAGED_VERSION );

		$this->assertTrue( API_Update_Updater::update_single_plugin( $this->plugin->post_name ) );

		$row = $this->get_row();
		$this->assertSame( self::STAGED_VERSION, $row->version );
		$this->assertSame( self::STAGED_VERSION, $row->stable_tag );
		$this->assertNotFalse( wp_next_scheduled( "release_to_update_api:{$this->plugin->post_name}" ) );
	}

	/**
	 * The block, not the cooldown clock, holds the version: with the cooldown
	 * cleared, a direct write attempt still changes nothing.
	 */
	public function test_block_outlasts_cooldown(): void {
		$this->insert_served_row();
		$this->assertTrue( $this->block() );

		// Clear the cooldown so only the block can be holding the version.
		Plugin_Directory::add_release(
			$this->plugin,
			array(
				'tag'           => self::STAGED_VERSION,
				'release_delay' => 0,
			)
		);

		API_Update_Updater::update_single_plugin( $this->plugin->post_name );

		$this->assert_served_release( self::SERVED_VERSION, self::SERVED_VERSION );
	}

	/**
	 * A first-ever release can be blocked before any row exists; none is
	 * created while the hold is in effect.
	 */
	public function test_block_holds_first_release(): void {
		$this->assertTrue( $this->block() );

		// Clear the cooldown so only the block can be holding the version.
		Plugin_Directory::add_release(
			$this->plugin,
			array(
				'tag'           => self::STAGED_VERSION,
				'release_delay' => 0,
			)
		);

		API_Update_Updater::update_single_plugin( $this->plugin->post_name );

		$this->assertFalse( API_Update_Updater::get_served_release_identity( $this->plugin->post_name ) );
	}

	/**
	 * A version that is already being served is burned for future publication,
	 * but the existing update row cannot be un-shipped.
	 */
	public function test_served_version_is_burned_without_unshipping(): void {
		$this->insert_served_row( self::STAGED_VERSION );

		$this->assertTrue( $this->block() );
		$this->assertTrue( API_Update_Updater::is_release_blocked( $this->get_release() ) );
		$this->assert_served_release( self::STAGED_VERSION, self::STAGED_VERSION );
	}

	/**
	 * A served release with a version longer than the row's varchar(128) column
	 * is still burned under its exact tag without un-shipping the truncated row.
	 */
	public function test_served_truncated_version_is_burned_without_unshipping(): void {
		$long_version = str_repeat( '1.0.', 50 ) . '0';

		$release            = $this->get_release();
		$release['tag']     = $long_version;
		$release['version'] = $long_version;

		update_post_meta( $this->plugin->ID, 'version', $long_version );
		update_post_meta( $this->plugin->ID, 'stable_tag', $long_version );
		update_post_meta( $this->plugin->ID, 'releases', array( $release ) );
		$this->insert_served_row( substr( $long_version, 0, 128 ) );

		$this->assertTrue(
			API_Update_Updater::block_release(
				$this->plugin->post_name,
				$long_version,
				$long_version,
				array( 'reason' => 'High-risk release.' )
			)
		);
		$this->assertTrue( API_Update_Updater::is_release_blocked( Plugin_Directory::get_release( $this->plugin, $long_version ) ) );
		$truncated_identity = substr( $long_version, 0, 128 );
		$this->assert_served_release( $truncated_identity, $truncated_identity );
	}

	/**
	 * A version longer than the row's varchar(128) `version` column is stored
	 * truncated; the truncated match must not read as a new version, which would
	 * defer the already-served release to a cooldown over and over.
	 */
	public function test_served_truncated_version_is_not_deferred(): void {
		$long_version = str_repeat( '1.0.', 50 ) . '0';

		$release            = $this->get_release();
		$release['tag']     = $long_version;
		$release['version'] = $long_version;

		update_post_meta( $this->plugin->ID, 'version', $long_version );
		update_post_meta( $this->plugin->ID, 'stable_tag', $long_version );
		update_post_meta( $this->plugin->ID, 'releases', array( $release ) );
		$this->insert_served_row( substr( $long_version, 0, 128 ) );

		$this->assertTrue( API_Update_Updater::update_single_plugin( $this->plugin->post_name ) );

		$this->assertFalse( wp_next_scheduled( "release_to_update_api:{$this->plugin->post_name}" ) );
		$truncated_identity = substr( $long_version, 0, 128 );
		$this->assert_served_release( $truncated_identity, $truncated_identity );
	}

	/**
	 * A release without a record cannot be blocked.
	 *
	 * An empty (rather than deleted) `releases` meta keeps get_releases() from
	 * prefilling via a live SVN lookup.
	 */
	public function test_unknown_release_is_not_blockable(): void {
		update_post_meta( $this->plugin->ID, 'releases', array() );

		$this->assertFalse( $this->block() );
	}

	/**
	 * A missing exact release record cannot be interpreted as zero cooldown.
	 */
	public function test_unknown_release_is_not_served(): void {
		update_post_meta( $this->plugin->ID, 'releases', array() );
		$this->insert_served_row();

		$this->assertFalse( API_Update_Updater::update_single_plugin( $this->plugin->post_name ) );
		$this->assertSame( self::SERVED_VERSION, $this->get_row()->version );
	}

	/**
	 * Legacy release rows need an explicit migration, not an implicit zero delay.
	 */
	public function test_release_without_delay_is_not_served(): void {
		$release = $this->get_release();
		unset( $release['release_delay'] );

		update_post_meta( $this->plugin->ID, 'releases', array( $release ) );
		$this->insert_served_row();

		try {
			API_Update_Updater::update_single_plugin( $this->plugin->post_name );
			$this->fail( 'A release without explicit cooldown state was served.' );
		} catch ( \UnexpectedValueException $error ) {
			$this->assertStringContainsString( 'release delay', $error->getMessage() );
		}

		$this->assertSame( self::SERVED_VERSION, $this->get_row()->version );
	}

	/**
	 * A second block on an already-held release is a no-op success — the version
	 * is held, which is what the caller asked for — preserving the first block.
	 */
	public function test_existing_block_is_not_replaced(): void {
		$this->insert_served_row();
		$this->assertTrue( $this->block() );

		$second = API_Update_Updater::block_release(
			$this->plugin->post_name,
			self::STAGED_VERSION,
			self::STAGED_VERSION,
			array( 'reason' => 'Another reason.' )
		);

		$this->assertTrue( $second );
		$this->assertSame( 'High-risk release.', $this->get_release()['release_block']['reason'] );
	}

	/**
	 * A status change while a release is held still reaches the row.
	 */
	public function test_status_change_reaches_row_while_blocked(): void {
		$this->insert_served_row();
		$this->assertTrue( $this->block() );

		$this->set_status( 'closed' );

		$this->assertTrue( API_Update_Updater::update_single_plugin( $this->plugin->post_name ) );

		$row = $this->get_row();
		$this->assertSame( '0', $row->available );
		$this->assertStringContainsString( 'closed_at', (string) $row->meta );
		$this->assertSame( self::SERVED_VERSION, $row->version );
	}

	/**
	 * A force-release clears the block, serves the version, and logs that a
	 * block was lifted — the only trace left once the block record is deleted.
	 * With the cooldown already cleared, the log claims no cooldown bypass.
	 */
	public function test_force_release_clears_block(): void {
		$this->insert_served_row();
		$this->assertTrue( $this->block() );

		// Clear the cooldown so the block is the only thing being lifted.
		Plugin_Directory::add_release(
			$this->plugin,
			array(
				'tag'           => self::STAGED_VERSION,
				'release_delay' => 0,
			)
		);

		$this->assertTrue( API_Update_Updater::force_release( $this->plugin->post_name, 'Reviewed; false positive.' ) );

		$this->assertFalse( API_Update_Updater::is_release_blocked( $this->get_release() ) );
		$this->assert_served_release( self::STAGED_VERSION, self::STAGED_VERSION );

		$audit_log = $this->get_audit_log();
		$this->assertStringContainsString( 'lifting the release block', $audit_log );
		$this->assertStringNotContainsString( 'release cooldown', $audit_log );
	}

	/**
	 * Force-releasing a version that is both blocked and still in cooldown lifts
	 * both holds in one go: the version is served and the log records both.
	 */
	public function test_force_release_clears_block_and_cooldown(): void {
		$this->insert_served_row();
		$this->assertTrue( $this->block() );

		$this->assertTrue( API_Update_Updater::force_release( $this->plugin->post_name, 'Reviewed; false positive.' ) );

		$this->assertFalse( API_Update_Updater::is_release_blocked( $this->get_release() ) );
		$this->assert_served_release( self::STAGED_VERSION, self::STAGED_VERSION );

		$this->assertStringContainsString(
			'lifting the release block and bypassing the 24-hour release cooldown',
			$this->get_audit_log()
		);
	}
}
