<?php
/**
 * Tests for publishing an already-approved staged plugin ZIP.
 *
 * @package WordPressdotorg\Plugin_Directory\Tests
 */

use PHPUnit\Framework\TestCase;
use WordPressdotorg\Plugin_Directory\CLI\Import;
use WordPressdotorg\Plugin_Directory\Plugin_Directory;
use WordPressdotorg\Plugin_Directory\Zip\Builder;

/**
 * Tests the single importer-owned public pointer transition.
 */
class Zip_Promotion_Test extends TestCase {

	/**
	 * Plugin fixture.
	 *
	 * @var \WP_Post
	 */
	private $plugin;

	/** Create an old public release and one approved candidate. */
	protected function setUp(): void {
		parent::setUp();

		$this->plugin = Plugin_Directory::create_plugin_post(
			array(
				'post_name'   => 'zip-promotion-test',
				'post_title'  => 'ZIP Promotion Test',
				'post_status' => 'publish',
			)
		);
		$this->assertInstanceOf( \WP_Post::class, $this->plugin );

		update_post_meta( $this->plugin->ID, 'version', '1.0.0' );
		update_post_meta( $this->plugin->ID, 'stable_tag', '1.0.0' );
		Plugin_Directory::add_release(
			$this->plugin,
			array(
				'tag'           => '1.1.0',
				'version'       => '1.1.0',
				'zips_built'    => false,
				'release_delay' => DAY_IN_SECONDS,
			)
		);

		update_post_meta(
			$this->plugin->ID,
			'_gandalf_release_candidate',
			$this->candidate()
		);
	}

	/** Delete the fixture and its update-source row. */
	protected function tearDown(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test cleanup.
		$wpdb->delete( $wpdb->prefix . 'update_source', array( 'plugin_slug' => $this->plugin->post_name ) );
		wp_clear_scheduled_hook( "import_plugin:{$this->plugin->post_name}" );
		wp_clear_scheduled_hook( "scan_plugin:{$this->plugin->post_name}" );
		wp_clear_scheduled_hook( "release_to_update_api:{$this->plugin->post_name}" );
		wp_delete_post( $this->plugin->ID, true );

		parent::tearDown();
	}

	/** Approved promotion advances every public release pointer together. */
	public function test_approved_candidate_becomes_the_public_release(): void {
		$this->assertTrue( Import::publish_gandalf_candidate( $this->plugin, $this->candidate() ) );

		$this->assertSame( '1.1.0', get_post_meta( $this->plugin->ID, 'version', true ) );
		$this->assertSame( '1.1.0', get_post_meta( $this->plugin->ID, 'stable_tag', true ) );
		$this->assertSame( array( '1.0.0', '1.1.0' ), get_post_meta( $this->plugin->ID, 'tagged_versions', true ) );

		$release = Plugin_Directory::get_release( $this->plugin, '1.1.0' );
		$this->assertTrue( $release['zips_built'] );
		$this->assertSame( 123, $release['zips_built_from_revision'] );
		$this->assertSame( 0, $release['release_delay'] );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Assert persisted output.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT version, stable_tag FROM {$wpdb->prefix}update_source WHERE plugin_slug = %s",
				$this->plugin->post_name
			),
			ARRAY_A
		);
		$this->assertSame(
			array(
				'version'    => '1.1.0',
				'stable_tag' => '1.1.0',
			),
			$row
		);
	}

	/** A fresh physical tag never inherits release state from same-Version trunk. */
	public function test_new_tag_does_not_inherit_trunk_release_state(): void {
		Plugin_Directory::add_release(
			$this->plugin,
			array(
				'tag'           => 'trunk@2.0.0',
				'version'       => '2.0.0',
				'discarded'     => true,
			)
		);
		Plugin_Directory::add_release(
			$this->plugin,
			array(
				'tag'     => '2.0.0',
				'version' => '2.0.0',
			)
		);

		$tagged = Plugin_Directory::get_release( $this->plugin, '2.0.0' );
		$this->assertSame( '2.0.0', $tagged['tag'] );
		$this->assertArrayNotHasKey( 'discarded', $tagged );
		$this->assertCount( 3, Plugin_Directory::get_releases( $this->plugin ) );
	}

	/** Numeric-looking refs remain distinct release keys. */
	public function test_exact_release_lookup_does_not_coerce_numeric_tags(): void {
		Plugin_Directory::add_release(
			$this->plugin,
			array(
				'tag'     => '01',
				'version' => '1.0.0',
			)
		);
		Plugin_Directory::add_release(
			$this->plugin,
			array(
				'tag'     => '1',
				'version' => '1.0.0',
			)
		);

		$this->assertSame( '01', Plugin_Directory::get_release_by_tag( $this->plugin, '01' )['tag'] );
		$this->assertSame( '1', Plugin_Directory::get_release_by_tag( $this->plugin, '1' )['tag'] );
	}

	/** A requested legacy ref cannot bypass the candidate guard via normalization. */
	public function test_pending_ref_cannot_enter_the_public_builder(): void {
		$candidate                = $this->candidate();
		$candidate['release_ref'] = '.1';
		update_post_meta( $this->plugin->ID, '_gandalf_release_candidate', $candidate );

		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'must use stage() and promote()' );

		( new Testable_Zip_Promotion_Builder() )->assertPublicBuildAllowed(
			$this->plugin->post_name,
			array( '.1' ),
			'1.0.0'
		);
	}

	/** The approved digest must name the ZIP entry from the same scan bundle. */
	public function test_promotion_manifest_binds_the_approved_zip_digest(): void {
		$scan_id = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
		$files   = array(
			array(
				'staged' => "{$this->plugin->post_name}.gandalf-{$scan_id}.zip",
				'public' => "{$this->plugin->post_name}.1.1.0.zip",
				'sha256' => str_repeat( 'a', 64 ),
			),
		);
		$builder = new Testable_Zip_Promotion_Builder();

		$this->assertSame(
			$files,
			$builder->validatePromotionManifest(
				$this->plugin->post_name,
				$scan_id,
				array(
					'current_zip_sha256' => str_repeat( 'a', 64 ),
					'files'              => $files,
				)
			)
		);

		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'Approved ZIP digest does not match' );
		$builder->validatePromotionManifest(
			$this->plugin->post_name,
			$scan_id,
			array(
				'current_zip_sha256' => str_repeat( 'b', 64 ),
				'files'              => $files,
			)
		);
	}

	/** Candidate state shared by the persisted record and explicit command input. */
	private function candidate(): array {
		return array(
			'scan_id'          => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
			'version'          => '1.1.0',
			'release_ref'      => '1.1.0',
			'state'            => 'approved',
			'tags'             => array(
				'1.0.0' => array(),
				'1.1.0' => array(),
			),
			'last_updated'     => '2026-08-13 12:00:00',
			'changed_svn_tags' => array( '1.1.0' ),
			'svn_revision'     => 123,
			'warnings'         => array(),
			'artifact'         => array( 'source_revision' => 123 ),
		);
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Tiny test-only seam.
/** Exposes pure Builder custody checks without touching SVN. */
class Testable_Zip_Promotion_Builder extends Builder {

	/**
	 * Expose the public-build guard.
	 *
	 * @param string $slug       The plugin slug.
	 * @param array  $versions   Requested refs.
	 * @param string $stable_tag The selected stable ref.
	 */
	public function assertPublicBuildAllowed( $slug, $versions, $stable_tag ): void {
		$this->assert_public_build_allowed( $slug, $versions, $stable_tag );
	}

	/**
	 * Expose manifest validation.
	 *
	 * @param string $slug      The plugin slug.
	 * @param string $scan_id   The candidate scan ID.
	 * @param array  $candidate The staged artifact manifest.
	 * @return array Validated manifest files.
	 */
	public function validatePromotionManifest( $slug, $scan_id, $candidate ): array {
		return $this->validate_promotion_manifest( $slug, $scan_id, $candidate );
	}
}
