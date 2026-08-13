<?php
/**
 * Tests for signed staged plugin ZIP downloads.
 *
 * @package WordPressdotorg\Plugin_Directory\Tests
 */

use PHPUnit\Framework\TestCase;
use WordPressdotorg\Plugin_Directory\Plugin_Directory;
use WordPressdotorg\Plugin_Directory\Zip\Serve;

/** Exercises the signed, lifecycle-bound staged ZIP capability. */
class Zip_Serve_Staged_Download_Test extends TestCase {

	private const SCAN_ID = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';

	/**
	 * Plugin fixture used by the resolver's cache/database lookup.
	 *
	 * @var \WP_Post
	 */
	private \WP_Post $plugin;

	/** Define the same shared secret used by the Gandalf callback tests. */
	public static function setUpBeforeClass(): void {
		if ( ! defined( 'WP_GANDALF_SCAN_SHARED_SECRET' ) ) {
			define( 'WP_GANDALF_SCAN_SHARED_SECRET', 'test-shared-secret' );
		}
	}

	/** Create one plugin with one current staged candidate. */
	protected function setUp(): void {
		parent::setUp();

		wp_cache_flush();
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

		$plugin = Plugin_Directory::create_plugin_post(
			array(
				'post_name'   => 'staged-download-test',
				'post_title'  => 'Staged Download Test',
				'post_status' => 'publish',
			)
		);

		$this->assertInstanceOf( \WP_Post::class, $plugin );
		$this->plugin = $plugin;

		update_post_meta(
			$this->plugin->ID,
			'_gandalf_release_candidate',
			array(
				'scan_id' => self::SCAN_ID,
				'state'   => 'pending',
			)
		);
	}

	/** Delete the plugin fixture. */
	protected function tearDown(): void {
		wp_delete_post( $this->plugin->ID, true );

		parent::tearDown();
	}

	/** A current signed capability maps to the unlisted file and expires with its candidate. */
	public function test_signed_url_maps_to_the_unlisted_staged_file_without_stats(): void {
		$slug      = $this->plugin->post_name;
		$signature = hash_hmac( 'sha256', $slug . ':' . self::SCAN_ID, WP_GANDALF_SCAN_SHARED_SECRET );
		$url       = Serve::staged_download_url( $slug, self::SCAN_ID );

		$this->assertSame(
			"https://downloads.wordpress.org/plugin/{$slug}.gandalf-" . self::SCAN_ID . "-{$signature}.zip",
			$url
		);

		list( $request, $file ) = ( new Testable_Staged_Zip_Serve() )->resolve( "/plugin/{$slug}.gandalf-" . self::SCAN_ID . "-{$signature}.zip" );

		$this->assertTrue( $request['is_staged'] );
		$this->assertFalse( $request['args']['stats'] );
		$this->assertSame( "{$slug}/{$slug}.gandalf-" . self::SCAN_ID . '.zip', $file );

		update_post_meta(
			$this->plugin->ID,
			'_gandalf_release_candidate',
			array(
				'scan_id' => self::SCAN_ID,
				'state'   => 'deciding',
			)
		);
		$this->expectException( \Exception::class );

		( new Testable_Staged_Zip_Serve() )->resolve( "/plugin/{$slug}.gandalf-" . self::SCAN_ID . "-{$signature}.zip" );
	}

	/**
	 * Reject a staged-looking URL that is not a valid capability.
	 *
	 * @dataProvider invalid_url_provider
	 * @param string $version Staged-looking version path component.
	 */
	public function test_unsigned_or_malformed_staged_urls_are_rejected( $version ): void {
		$this->expectException( \Exception::class );

		( new Testable_Staged_Zip_Serve() )->resolve( "/plugin/{$this->plugin->post_name}.{$version}.zip" );
	}

	/** Invalid staged-looking version path components. */
	public static function invalid_url_provider(): array {
		return array(
			'unsigned'                 => array( 'gandalf-' . self::SCAN_ID ),
			'encoded unsigned'         => array( '%67andalf-' . self::SCAN_ID ),
			'double encoded unsigned'  => array( '%2567andalf-' . self::SCAN_ID ),
			'encoded traversal'        => array( 'other%2F..%2Fstaged-download-test.gandalf-' . self::SCAN_ID ),
			'double encoded traversal' => array( 'other%252F..%252Fstaged-download-test.gandalf-' . self::SCAN_ID ),
			'wrong hmac'               => array( 'gandalf-' . self::SCAN_ID . '-' . str_repeat( '0', 64 ) ),
			'malformed uuid'           => array( 'gandalf-not-a-uuid-' . str_repeat( '0', 64 ) ),
		);
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Tiny test-only resolver seam.
/** Exposes the existing request resolver without serving or recording a download. */
class Testable_Staged_Zip_Serve extends Serve {

	/** Avoid the production constructor, which serves and exits. */
	public function __construct() {}

	/**
	 * Resolve a path through the production parser.
	 *
	 * @param string $path Request path.
	 * @return array{0: array, 1: string} Parsed request and resolved file.
	 */
	public function resolve( $path ) {
		$_SERVER['REQUEST_URI'] = $path;
		$_GET                   = array();

		$request = $this->determine_request();

		return array( $request, $this->get_file( $request ) );
	}
}
