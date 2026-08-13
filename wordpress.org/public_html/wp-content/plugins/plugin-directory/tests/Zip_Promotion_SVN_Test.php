<?php
/**
 * Production-shaped SVN custody test for staged ZIP promotion.
 *
 * @package WordPressdotorg\Plugin_Directory\Tests
 */

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use WordPressdotorg\Plugin_Directory\Zip\Builder;

/**
 * Proves that promotion copies only the approved staged bytes.
 */
#[Group( 'svn' )]
#[Group( 'external' )]
class Zip_Promotion_SVN_Test extends TestCase {

	/**
	 * Local file:// SVN repository.
	 *
	 * @var string
	 */
	private $repository_url;

	/**
	 * Temporary test root.
	 *
	 * @var string
	 */
	private $tmp_dir;

	/** Create a disposable ZIP-SVN repository. */
	protected function setUp(): void {
		parent::setUp();
		$this->tmp_dir = '';
		if ( ! is_executable( '/usr/bin/svn' ) || ! is_executable( '/usr/bin/svnadmin' ) ) {
			$this->markTestSkipped( 'The production-shaped custody test requires svn and svnadmin.' );
		}
		if ( defined( 'PLUGIN_ZIP_SVN_URL' ) ) {
			$this->markTestSkipped( 'The custody test requires an isolated ZIP-SVN URL.' );
		}

		if ( ! defined( 'PLUGIN_ZIP_SVN_USER' ) ) {
			define( 'PLUGIN_ZIP_SVN_USER', 'test' );
			define( 'PLUGIN_ZIP_SVN_PASS', 'test' );
		}

		$this->tmp_dir = sys_get_temp_dir() . '/zip-promotion-svn-' . wp_generate_uuid4();
		mkdir( $this->tmp_dir, 0700, true );

		$this->assertSame( 0, $this->run_command( array( '/usr/bin/svnadmin', 'create', $this->tmp_dir . '/repo' ) ) );
		$this->repository_url = 'file://' . $this->tmp_dir . '/repo';

		if ( ! defined( 'PLUGIN_ZIP_SVN_URL' ) ) {
			define( 'PLUGIN_ZIP_SVN_URL', $this->repository_url );
		}

		mkdir( $this->tmp_dir . '/seed/custody-test', 0700, true );
		$this->assertSame(
			0,
			$this->run_command(
				array(
					'/usr/bin/svn',
					'import',
					$this->tmp_dir . '/seed',
					$this->repository_url,
					'-m',
					'Initialize ZIP store.',
				)
			)
		);
	}

	/** Remove the local repository. */
	protected function tearDown(): void {
		$this->delete_tree( $this->tmp_dir );

		parent::tearDown();
	}

	/** Exact staged bytes promote; tampered bytes never replace the public ZIP. */
	#[RunInSeparateProcess]
	public function test_promote_copies_only_the_approved_digest(): void {
		$slug    = 'custody-test';
		$scan_id = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';
		$staged  = "{$slug}.gandalf-{$scan_id}.zip";
		$public  = "{$slug}.1.0.0.zip";
		$clean   = 'approved ZIP bytes';
		$digest  = hash( 'sha256', $clean );

		$this->commit_file( $slug, $staged, $clean );
		$manifest = array(
			'release_ref'        => '1.0.0',
			'current_zip_sha256' => $digest,
			'files'              => array(
				array(
					'staged' => $staged,
					'public' => $public,
					'sha256' => $digest,
				),
			),
		);

		$this->assertTrue( ( new Builder() )->promote( $slug, $scan_id, $manifest, 'Promote approved fixture.' ) );
		$this->assertSame( $digest, hash( 'sha256', $this->export_file( $slug, $public ) ) );

		$this->commit_file( $slug, $staged, 'tampered after approval' );
		try {
			( new Builder() )->promote( $slug, $scan_id, $manifest, 'Must reject tampered fixture.' );
			$this->fail( 'Tampered staged bytes should not promote.' );
		} catch ( \Exception $error ) {
			$this->assertStringContainsString( 'does not match its manifest', $error->getMessage() );
		}

		$this->assertSame( $digest, hash( 'sha256', $this->export_file( $slug, $public ) ) );
	}

	/**
	 * Commit one file into the local ZIP store.
	 *
	 * @param string $slug     Plugin slug.
	 * @param string $filename Repository filename.
	 * @param string $contents File contents.
	 */
	private function commit_file( $slug, $filename, $contents ): void {
		$checkout = $this->tmp_dir . '/write-' . wp_generate_uuid4();
		$this->assertSame( 0, $this->run_command( array( '/usr/bin/svn', 'checkout', $this->repository_url, $checkout ) ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Disposable local test fixture.
		file_put_contents( "{$checkout}/{$slug}/{$filename}", $contents );
		$this->assertSame( 0, $this->run_command( array( '/usr/bin/svn', 'add', '--force', "{$checkout}/{$slug}/{$filename}" ) ) );
		$this->assertSame( 0, $this->run_command( array( '/usr/bin/svn', 'commit', $checkout, '-m', "Write {$filename}." ) ) );
	}

	/**
	 * Export one repository file and return its bytes.
	 *
	 * @param string $slug     Plugin slug.
	 * @param string $filename Repository filename.
	 * @return string Exported bytes.
	 */
	private function export_file( $slug, $filename ): string {
		$destination = $this->tmp_dir . '/export-' . wp_generate_uuid4();
		$this->assertSame(
			0,
			$this->run_command( array( '/usr/bin/svn', 'export', "{$this->repository_url}/{$slug}/{$filename}", $destination ) )
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.
		return (string) file_get_contents( $destination );
	}

	/**
	 * Run one argv-safe local command.
	 *
	 * @param array $command Command arguments.
	 * @return int Process exit code.
	 */
	private function run_command( $command ): int {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Production-shaped local SVN test.
		$process = proc_open( $command, array( array( 'pipe', 'r' ), array( 'pipe', 'w' ), array( 'pipe', 'w' ) ), $pipes );
		$this->assertIsResource( $process );
		fclose( $pipes[0] );
		stream_get_contents( $pipes[1] );
		stream_get_contents( $pipes[2] );
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		return proc_close( $process );
	}

	/**
	 * Delete one disposable tree.
	 *
	 * @param string $path Tree path.
	 */
	private function delete_tree( $path ): void {
		if ( ! is_dir( $path ) ) {
			return;
		}

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $entry ) {
			$entry->isDir() ? rmdir( $entry->getPathname() ) : unlink( $entry->getPathname() );
		}
		rmdir( $path );
	}
}
