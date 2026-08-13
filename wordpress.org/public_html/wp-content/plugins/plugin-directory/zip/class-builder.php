<?php
namespace WordPressdotorg\Plugin_Directory\Zip;

use WordPressdotorg\Plugin_Directory\Tools\SVN;
use Exception;

/**
 * Generates a ZIP file for a Plugin.
 *
 * @package WordPressdotorg\Plugin_Directory\Zip
 */
class Builder {

	const TMP_DIR     = '/tmp/plugin-zip-builder';
	const SVN_URL     = 'https://plugins.svn.wordpress.org';

	protected $zip_file       = '';
	protected $checksum_file  = '';
	protected $signature_file = '';
	protected $tmp_build_dir  = '';
	protected $tmp_dir        = '';

	protected $slug       = '';
	protected $version    = '';
	protected $versions   = [];
	protected $context    = '';
	protected $stable_tag = '';

	// The SVN url of the plugin version being packaged.
	protected $plugin_version_svn_url = '';

	/**
	 * The revision of the plugin that was just packaged.
	 *
	 * @var int
	 */
	protected $plugins_revision = 0;

	/**
	 * UUIDv4 of the candidate being built outside the public namespace.
	 *
	 * @var string
	 */
	protected $stage_id = '';

	/**
	 * Exact importer export to package instead of exporting mutable SVN again.
	 *
	 * @var string
	 */
	protected $stage_source_dir = '';

	/**
	 * SVN revision of the importer export.
	 *
	 * @var int
	 */
	protected $stage_source_revision = 0;

	/**
	 * Manifest produced while staging one candidate.
	 *
	 * @var array
	 */
	protected $staged_candidate = array();

	/**
	 * Build one candidate without replacing its public ZIP.
	 *
	 * The source directory is the exact export the importer parsed, so release
	 * metadata and staged bytes cannot come from different SVN snapshots.
	 *
	 * @param string $slug            The plugin slug.
	 * @param string $release_ref     The effective stable tag, or trunk.
	 * @param string $scan_id         The UUIDv4 identifying the Gandalf scan.
	 * @param string $source_dir      The importer-owned stable export.
	 * @param int    $source_revision The SVN revision of that export.
	 * @param string $context         Optional SVN commit context.
	 * @return array|false The staged artifact manifest, or false when ZIP SVN is unconfigured.
	 * @throws Exception When the candidate cannot be staged.
	 */
	public function stage( $slug, $release_ref, $scan_id, $source_dir, $source_revision, $context = '' ) {
		if ( ! wp_is_uuid( $scan_id, 4 ) || ! is_dir( $source_dir ) || $source_revision < 1 ) {
			throw new Exception( __METHOD__ . ': Invalid staged candidate source.' );
		}

		$this->stage_id              = $scan_id;
		$this->stage_source_dir      = $source_dir;
		$this->stage_source_revision = (int) $source_revision;

		$built = $this->build( $slug, array( $release_ref ), $context, $release_ref );
		if ( false === $built ) {
			return false;
		}
		if ( ! $this->staged_candidate ) {
			throw new Exception( __METHOD__ . ': Candidate manifest was not created.' );
		}

		return $this->staged_candidate;
	}

	/**
	 * Generate a ZIP for a provided Plugin tags.
	 *
	 * @param string $slug       The plugin slug.
	 * @param array  $versions   The versions of the plugin to build ZIPs for.
	 * @param string $context    The context of this Builder instance (commit #, etc).
	 * @param string $stable_tag The stable tag, used for checksums and the public-build guard.
	 * @return array|false Map of successfully-built version (as requested) => SVN revision it was built from, false in unconfigured environments.
	 */
	public function build( $slug, $versions, $context, $stable_tag ) {
		// Bail when in an unconfigured environment.
		if ( ! defined( 'PLUGIN_ZIP_SVN_URL' ) ) {
			return false;
		}

		$this->slug       = $slug;
		$this->versions   = $versions;
		$this->context    = $context;
		$this->stable_tag = $stable_tag;

		if ( ! $this->stage_id ) {
			$this->assert_public_build_allowed( $slug, $versions, $stable_tag );
		}

		// General TMP directory
		if ( ! is_dir( self::TMP_DIR ) ) {
			mkdir( self::TMP_DIR, 0777, true );
			chmod( self::TMP_DIR, 0777 );
		}

		// Temp Directory for this instance of the Builder class.
		$this->tmp_dir = $this->generate_temporary_directory( self::TMP_DIR, $slug );

		$this->prepare_checkout();

		// Build the requested ZIPs
		$built_versions = [];
		foreach ( $versions as $requested_version ) {
			// Incase .1 was passed, treat it as 0.1
			$version = $requested_version;
			if ( '.' == substr( $version, 0, 1 ) ) {
				$version = "0{$version}";
			}
			$this->version = $version;

			// Reset the per-version output files, so error handling only acts on files from this iteration.
			$this->checksum_file  = '';
			$this->signature_file = '';

			if ( 'trunk' == $version ) {
				$this->zip_file = "{$this->tmp_dir}/{$this->slug}/{$this->slug}.zip";
			} else {
				$this->zip_file = "{$this->tmp_dir}/{$this->slug}/{$this->slug}.{$version}.zip";
			}

			// Pull the ZIP file down we're going to modify, which may not already exist.
			SVN::up( $this->zip_file );
			// This is done within the checksum generation function due to us not knowing the checksum filename until export_plugin().
			// SVN::up( $this->checksum_file );
			try {

				$this->tmp_build_dir = $this->zip_file . '-files';
				mkdir( $this->tmp_build_dir, 0777, true );

				$this->export_plugin();
				$this->fix_directory_dates();

				$this->generate_zip();

				$this->generate_zip_signatures();

				$this->generate_checksums();

				if ( $this->stage_id ) {
					$this->stage_output_files( $requested_version );
				}

				$this->cleanup_plugin_tmp();

			} catch ( Exception $e ) {
				// In event of error, skip this file this time.
				$error = preg_replace( '/[\r\n\t]+/', ' ', $e->getMessage() );

				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Routed to the error log via E_USER_WARNING; raw is fine.
				trigger_error( sprintf( 'ZIP build failed for %s %s: %s', $this->slug, $version, $error ), E_USER_WARNING );

				$this->cleanup_plugin_tmp();

				// Perform an SVN up to revert any changes made.
				SVN::up( $this->zip_file );
				if ( $this->checksum_file ) {
					SVN::up( $this->checksum_file );
				}
				if ( $this->signature_file ) {
					SVN::up( $this->signature_file );
				}
				continue;
			}

			// Add the ZIP file to SVN - This is only really needed for new files which don't exist in SVN.
			SVN::add( $this->zip_file );
			if ( $this->checksum_file ) {
				SVN::add( $this->checksum_file );
			}
			if ( $this->signature_file ) {
				SVN::add( $this->signature_file );
			}

			// Key by the requested version, as release records store the raw tag name.
			$built_versions[ $requested_version ] = $this->plugins_revision;
		}

		// If no versions could be built, an empty commit would incorrectly report success.
		if ( ! $built_versions ) {
			$this->cleanup();
			throw new Exception( __METHOD__ . ': Failed to build any of the requested ZIPs.' );
		}

		$res = SVN::commit(
			$this->tmp_dir,
			$this->context ? $this->context : "Updated ZIPs for {$this->slug}.",
			array(
				'username' => PLUGIN_ZIP_SVN_USER,
				'password' => PLUGIN_ZIP_SVN_PASS,
			)
		);

		if ( ! $this->stage_id ) {
			$this->invalidate_zip_caches( $versions );
		}

		$this->cleanup();

		if ( ! $res['result'] && $res['errors'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI context, callers write the message to STDERR.
			throw new Exception( __METHOD__ . ': Failed to commit the new ZIPs: ' . $res['errors'][0]['error_message'] );
		}

		/*
		 * A failed commit without any SVN errors means there were no modified files,
		 * ie. the ZIPs on disk were already up to date. That's a successful build.
		 */

		return $built_versions;
	}

	/**
	 * Keep selected and pending release refs out of the direct public builder.
	 *
	 * @param string $slug       The plugin slug.
	 * @param array  $versions   Requested refs.
	 * @param string $stable_tag The currently selected stable ref.
	 * @throws Exception When a requested ref must use the promotion gate.
	 */
	protected function assert_public_build_allowed( $slug, $versions, $stable_tag ) {
		$normalize = static function ( $version ) {
			return '.' === substr( $version, 0, 1 ) ? "0{$version}" : $version;
		};
		$versions = array_map( $normalize, $versions );
		$plugin    = \WordPressdotorg\Plugin_Directory\Plugin_Directory::get_plugin_post( $slug );
		$candidate = $plugin ? get_post_meta( $plugin->ID, '_gandalf_release_candidate', true ) : false;
		$protected = array( $stable_tag );
		if ( is_array( $candidate ) && is_string( $candidate['release_ref'] ?? null ) ) {
			$protected[] = $candidate['release_ref'];
		}
		$protected = array_map( $normalize, $protected );
		if ( array_intersect( $protected, $versions ) ) {
			throw new Exception( __METHOD__ . ': A selected release ZIP must use stage() and promote().' );
		}
	}

	/**
	 * Promote the exact staged bundle to its conventional public filenames.
	 *
	 * @param string $slug      The plugin slug.
	 * @param string $scan_id   The UUIDv4 whose exact staged bundle was approved.
	 * @param array  $candidate The manifest returned by stage().
	 * @param string $context   Optional SVN commit context.
	 * @return bool Whether the exact bundle was promoted.
	 * @throws Exception When custody validation or the SVN commit fails.
	 */
	public function promote( $slug, $scan_id, $candidate, $context = '' ) {
		if ( ! defined( 'PLUGIN_ZIP_SVN_URL' ) ) {
			return false;
		}
		$files = $this->validate_promotion_manifest( $slug, $scan_id, $candidate );

		$this->slug    = $slug;
		$this->context = $context;
		if ( ! is_dir( self::TMP_DIR ) ) {
			mkdir( self::TMP_DIR, 0777, true );
			chmod( self::TMP_DIR, 0777 );
		}
		$this->tmp_dir = $this->generate_temporary_directory( self::TMP_DIR, $slug );

		try {
			$this->prepare_checkout();

			foreach ( $files as $file ) {
				$staged_path = "{$this->tmp_dir}/{$slug}/{$file['staged']}";
				$public_path = "{$this->tmp_dir}/{$slug}/{$file['public']}";

				SVN::up( $staged_path );
				if ( ! is_file( $staged_path ) || hash_file( 'sha256', $staged_path ) !== $file['sha256'] ) {
					throw new Exception( __METHOD__ . ": Staged artifact {$file['staged']} does not match its manifest." );
				}

				SVN::up( $public_path );
				if ( ! copy( $staged_path, $public_path ) || hash_file( 'sha256', $public_path ) !== $file['sha256'] ) {
					throw new Exception( __METHOD__ . ": Failed to copy {$file['staged']} to {$file['public']}." );
				}
				SVN::add( $public_path );
			}
			$result = SVN::commit(
				"{$this->tmp_dir}/{$slug}",
				$this->context ? $this->context : "Promoted Gandalf-approved ZIP for {$slug}.",
				array(
					'username' => PLUGIN_ZIP_SVN_USER,
					'password' => PLUGIN_ZIP_SVN_PASS,
				)
			);
			if ( ! $result['result'] && $result['errors'] ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI context, callers write the message to STDERR.
				throw new Exception( __METHOD__ . ': Failed to promote the staged ZIP: ' . $result['errors'][0]['error_message'] );
			}
		} finally {
			$this->cleanup();
		}

		$this->slug = $slug;
		$this->invalidate_zip_caches( array( $candidate['release_ref'] ) );
		return true;
	}

	/**
	 * Validate that a manifest names one scan's exact approved ZIP bundle.
	 *
	 * @param string $slug      The plugin slug.
	 * @param string $scan_id   The UUIDv4 whose bundle was approved.
	 * @param array  $candidate The staged artifact manifest.
	 * @return array Validated file entries.
	 * @throws Exception When the manifest is malformed or names another ZIP.
	 */
	protected function validate_promotion_manifest( $slug, $scan_id, $candidate ) {
		if (
			! preg_match( '/^[a-z0-9][a-z0-9-]*$/', $slug ) ||
			! wp_is_uuid( $scan_id, 4 ) ||
			empty( $candidate['files'] ) ||
			! is_array( $candidate['files'] ) ||
			! preg_match( '/^[a-f0-9]{64}$/', $candidate['current_zip_sha256'] ?? '' )
		) {
			throw new Exception( __METHOD__ . ': Invalid candidate manifest.' );
		}

		$zip_digest = false;
		foreach ( $candidate['files'] as $file ) {
			if (
				! is_array( $file ) ||
				empty( $file['staged'] ) ||
				empty( $file['public'] ) ||
				empty( $file['sha256'] ) ||
				basename( $file['staged'] ) !== $file['staged'] ||
				basename( $file['public'] ) !== $file['public'] ||
				! preg_match( '/^[a-f0-9]{64}$/', $file['sha256'] ) ||
				! str_starts_with( $file['staged'], "{$slug}.gandalf-{$scan_id}." ) ||
				! str_starts_with( $file['public'], "{$slug}." )
			) {
				throw new Exception( __METHOD__ . ': Invalid candidate file entry.' );
			}
			if ( "{$slug}.gandalf-{$scan_id}.zip" === $file['staged'] ) {
				$zip_digest = $file['sha256'];
			}
		}

		if ( false === $zip_digest || ! hash_equals( $candidate['current_zip_sha256'], $zip_digest ) ) {
			throw new Exception( __METHOD__ . ': Approved ZIP digest does not match the promoted bundle.' );
		}

		return $candidate['files'];
	}

	/**
	 * Rename generated output to write-once staged filenames and record hashes.
	 *
	 * @param string $release_ref The requested plugin SVN ref.
	 * @throws Exception When a staged file cannot be created or hashed.
	 */
	protected function stage_output_files( $release_ref ) {
		$stage_prefix = "{$this->tmp_dir}/{$this->slug}/{$this->slug}.gandalf-{$this->stage_id}";
		$outputs      = array( array( $this->zip_file, "{$stage_prefix}.zip" ) );

		if ( $this->checksum_file ) {
			$outputs[] = array( $this->checksum_file, "{$stage_prefix}.checksums.json" );
		}
		if ( $this->signature_file ) {
			$outputs[] = array( $this->signature_file, "{$stage_prefix}.zip.sig" );
		}

		$files = array();
		foreach ( $outputs as list( $public_path, $staged_path ) ) {
			if ( file_exists( $staged_path ) || ! rename( $public_path, $staged_path ) ) {
				throw new Exception( __METHOD__ . ': Failed to create a staged artifact.' );
			}

			$sha256 = hash_file( 'sha256', $staged_path );
			if ( ! $sha256 ) {
				throw new Exception( __METHOD__ . ': Failed to hash a staged artifact.' );
			}

			$files[] = array(
				'staged' => basename( $staged_path ),
				'public' => basename( $public_path ),
				'sha256' => $sha256,
			);

			if ( $public_path === $this->zip_file ) {
				$this->zip_file = $staged_path;
			} elseif ( $public_path === $this->checksum_file ) {
				$this->checksum_file = $staged_path;
			} else {
				$this->signature_file = $staged_path;
			}
		}

		$this->staged_candidate = array(
			'release_ref'        => (string) $release_ref,
			'source_revision'    => $this->stage_source_revision,
			'current_zip_url'    => Serve::staged_download_url( $this->slug, $this->stage_id ),
			'current_zip_sha256' => $files[0]['sha256'],
			'files'              => $files,
		);
	}

	/**
	 * Prepare a sparse checkout of this plugin's ZIP-SVN directory.
	 *
	 * @throws Exception When the checkout cannot be prepared.
	 */
	protected function prepare_checkout() {
		$checkout = SVN::checkout(
			PLUGIN_ZIP_SVN_URL,
			$this->tmp_dir,
			array(
				'depth'    => 'empty',
				'username' => PLUGIN_ZIP_SVN_USER,
				'password' => PLUGIN_ZIP_SVN_PASS,
			)
		);
		if ( ! $checkout['result'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal ZIP-store invariant.
			throw new Exception( __METHOD__ . ': Failed to create checkout of ' . PLUGIN_ZIP_SVN_URL . '.' );
		}

		$plugin_folder = "{$this->tmp_dir}/{$this->slug}/";
		$result        = SVN::up( $plugin_folder, array( 'depth' => 'empty' ) );
		if ( ! is_dir( $plugin_folder ) ) {
			mkdir( $plugin_folder, 0777, true );
			$result = SVN::add( $plugin_folder );
		}
		if ( ! $result['result'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal ZIP-store invariant.
			throw new Exception( __METHOD__ . ": Failed to create {$plugin_folder}." );
		}
	}

	/**
	 * Generates a JSON file containing the checksums of the files within the ZIP.
	 *
	 * In the event that a previous ZIP for this version exists, checksums for all versions of the file will be included.
	 */
	function generate_checksums() {
		// Don't create checksums for trunk.
		if ( ! $this->stable_tag || ( 'trunk' == $this->version && 'trunk' != $this->stable_tag && '' != $this->stable_tag ) ) {
			return;
		}

		// Fetch the plugin headers
		$plugin_data = false;
		foreach ( glob( $this->tmp_build_dir . '/' . $this->slug . '/*.php' ) as $filename ) {
			$plugin_data = get_plugin_data( $filename, false, false );

			if ( $plugin_data['Name'] && '' !== $plugin_data['Version'] ) {
				break;
			}
		}

		if ( ! $plugin_data || '' === $plugin_data['Version'] ) {
			return;
		}

		$plugin_version = $plugin_data['Version'];
		// Catch malformed version strings.
		if ( basename( $plugin_version ) != $plugin_version ) {
			return;
		}

		$this->checksum_file = "{$this->tmp_dir}/{$this->slug}/{$this->slug}.{$plugin_version}.checksums.json";

		// Checkout the Checksum file for this plugin version
		SVN::up( $this->checksum_file );

		// Existing checksums?
		$existing_json_checksum_file = file_exists( $this->checksum_file );

		$skip_bad_files = array();
		$checksums      = array();
		foreach ( array(
			'md5'    => 'md5sum',
			'sha256' => 'sha256sum',
		) as $checksum_type => $checksum_bin ) {
			$checksum_output = array();
			$this->exec( sprintf(
				'cd %s && find . -type f -print0 | sort -z | xargs -0 ' . $checksum_bin . ' 2>&1',
				escapeshellarg( $this->tmp_build_dir . '/' . $this->slug )
			), $checksum_output, $return_value );

			if ( $return_value ) {
				// throw new Exception( __METHOD__ . ': Checksum generation failed, return code: ' . $return_value, 503 );
				// TODO For now, just silently keep going.
				continue;
			}

			foreach ( $checksum_output as $line ) {
				list( $checksum, $filename ) = preg_split( '!\s+!', $line, 2 );

				$filename = trim( preg_replace( '!^./!', '', $filename ) );
				$checksum = trim( $checksum );

				// See https://meta.trac.wordpress.org/ticket/3335 - Filenames like 'Testing Test' truncated to 'Testing'
				if ( preg_match( '!^(\S+)\s+\S!', $filename, $m ) ) {
					$skip_bad_files[ $m[1] ] = true;
				}

				if ( ! isset( $checksums[ $filename ] ) ) {
					$checksums[ $filename ] = array(
						'md5'    => array(),
						'sha256' => array(),
					);
				}

				$checksums[ $filename ][ $checksum_type ] = $checksum;
			}
		}

		$json_checksum_file = (object) array(
			'plugin'  => $this->slug,
			'version' => $plugin_version,
			'source'  => $this->plugin_version_svn_url,
			'zip'     => 'https://downloads.wordpress.org/plugins/' . basename( $this->zip_file ),
			'files'   => $checksums,
		);

		// If the checksum file exists already, merge it into this one.
		if ( $existing_json_checksum_file ) {
			$existing_json_checksum_file = json_decode( file_get_contents( $this->checksum_file ) );

			// Sometimes plugin versions exist in multiple tags/zips, include all the SVN urls & ZIP urls
			foreach ( array( 'source', 'zip' ) as $maybe_different ) {
				if ( ! empty( $existing_json_checksum_file->{$maybe_different} ) &&
					$existing_json_checksum_file->{$maybe_different} != $json_checksum_file->{$maybe_different}
				) {
					$json_checksum_file->{$maybe_different} = array_unique( array_merge(
						(array) $existing_json_checksum_file->{$maybe_different},
						(array) $json_checksum_file->{$maybe_different}
					) );

					// Reduce single arrays back to a string when possible.
					if ( 1 == count( $json_checksum_file->{$maybe_different} ) ) {
						$json_checksum_file->{$maybe_different} = array_shift( $json_checksum_file->{$maybe_different} );
					}
				}
			}

			// Combine Checksums from existing files and the new files
			foreach ( $existing_json_checksum_file->files as $file => $checksums ) {

				if ( ! isset( $json_checksum_file->files[ $file ] ) ) {
					if ( isset( $skip_bad_files[ $file ] ) ) {
						// See https://meta.trac.wordpress.org/ticket/3335
						// This is a partial filename, which shouldn't have been in the checksums.
						continue;
					}

					// Deleted file, use existing checksums.
					$json_checksum_file->files[ $file ] = $checksums;

				} elseif ( $checksums !== $json_checksum_file->files[ $file ] ) {
					// Checksum has changed, include both in the resulting json file.
					foreach ( array( 'md5', 'sha256' ) as $checksum_type ) {
						$json_checksum_file->files[ $file ][ $checksum_type ] = array_unique( array_merge(
							(array) $checksums->{$checksum_type}, // May already be an array
							(array) $json_checksum_file->files[ $file ][ $checksum_type ]
						) );

						// Reduce single arrays back to a string when possible.
						if ( 1 == count( $json_checksum_file->files[ $file ][ $checksum_type ] ) ) {
							$json_checksum_file->files[ $file ][ $checksum_type ] = array_shift( $json_checksum_file->files[ $file ][ $checksum_type ] );
						}
					}
				}
			}
		}

		ksort( $json_checksum_file->files );

		file_put_contents( $this->checksum_file, wp_json_encode( $json_checksum_file ) );
	}

	/**
	 * Generates a temporary unique directory in a given directory
	 *
	 * Performs a similar job to `tempnam()` with an added suffix and doesn't
	 * cut off the $prefix at 60 characters.
	 * As with `tempnam()` the caller is responsible for removing the temorarily file.
	 *
	 * Note: `strlen( $prefix . $suffix )` shouldn't exceed 238 characters.
	 *
	 * @param string $dir The directory to create the file in.
	 * @param string $prefix The file prefix.
	 * @param string $suffix The file suffix, optional.
	 *
	 * @return string Path of unique temporary directory.
	 */
	protected function generate_temporary_directory( $dir, $prefix, $suffix = '' ) {
		$i = 0;
		do {
			$rand     = uniqid();
			$filename = "{$dir}/{$prefix}-{$rand}{$i}{$suffix}";
		} while ( false === ( $fp = @fopen( $filename, 'x' ) ) && $i++ < 50 );

		if ( $i >= 50 ) {
			throw new Exception( __METHOD__ . ': Could not find unique filename.' );
		}

		fclose( $fp );

		// Convert file to directory.
		unlink( $filename );
		if ( ! mkdir( $filename, 0777, true ) ) {
			throw new Exception( __METHOD__ . ': Could not convert temporary filename to directory.' );
		}
		chmod( $filename, 0777 );

		return $filename;
	}

	/**
	 * Creates an Export of the plugin and redies it for ZIP creation by removing invalid data.
	 */
	protected function export_plugin() {
		if ( 'trunk' == $this->version ) {
			$this->plugin_version_svn_url = self::SVN_URL . "/{$this->slug}/trunk/";
		} else {
			$this->plugin_version_svn_url = self::SVN_URL . "/{$this->slug}/tags/{$this->version}/";
		}

		$build_dir = "{$this->tmp_build_dir}/{$this->slug}/";
		if ( $this->stage_source_dir ) {
			mkdir( $build_dir, 0777, true );
			$this->exec(
				sprintf(
					'cp -a %s/. %s',
					escapeshellarg( rtrim( $this->stage_source_dir, '/' ) ),
					escapeshellarg( $build_dir )
				),
				$output,
				$status
			);
			if ( $status ) {
				throw new Exception( __METHOD__ . ': Failed to copy the importer export.' );
			}
			$this->plugins_revision = $this->stage_source_revision;
		} else {
			$this->export_plugin_from_svn( $build_dir );
		}

		// Verify that the specified plugin zip will contain files.
		if ( ! array_diff( scandir( $this->tmp_build_dir ), array( '.', '..' ) ) ) {
			throw new Exception( __METHOD__ . ': No files exist in the plugin directory', 404 );
		}

		// Cleanup any symlinks that shouldn't be there.
		$this->exec(
			sprintf(
				'find %s -type l -print0 | xargs -r0 rm',
				escapeshellarg( $build_dir )
			)
		);

		return true;
	}

	/**
	 * Export the selected ref when building a legacy public ZIP directly.
	 *
	 * @param string $build_dir Destination directory.
	 * @throws Exception When SVN export fails.
	 */
	protected function export_plugin_from_svn( $build_dir ) {

		$svn_params = array();
		// BudyPress is a special sister project, they have svn:externals.
		if ( 'buddypress' != $this->slug ) {
			$svn_params[] = 'ignore-externals';
		}

		/*
		 * Before we check out the plugin, ensure that it has *files* in the folder.
		 *
		 * Some plugins accidentally copy their entire SVN repo into the tagged folder, which
		 * causes a recursive checkout many multiple gigabytes in size, causing issues for WordPress.org.
		 */
		$remote_files = SVN::ls( $this->plugin_version_svn_url, true );
		if (
			$remote_files && 
			! wp_list_filter( $remote_files, [ 'kind' => 'file' ] )
		) {
			throw new Exception( __METHOD__ . ": Could not create SVN export of {$this->plugin_version_svn_url}: Path appears not to have any files." );
		}

		$res = SVN::export( $this->plugin_version_svn_url, $build_dir, $svn_params );
		// Handle tags which we store as 0.blah but are in /tags/.blah
		if ( ! $res['result'] && '0.' == substr( $this->version, 0, 2 ) ) {
			$_version                     = substr( $this->version, 1 );
			$this->plugin_version_svn_url = self::SVN_URL . "/{$this->slug}/tags/{$_version}/";
			$res                          = SVN::export( $this->plugin_version_svn_url, $build_dir, $svn_params );
		}
		if ( ! $res['result'] ) {
			throw new Exception( __METHOD__ . ': ' . ( $res['errors'][0]['error_message'] ?? 'unknown error' ), 404 );
		}

		// Store the SVN revision that's been used for the ZIP in a property for later.
		$this->plugins_revision = $res['revision'];
	}

	/**
	 * Corrects the directory dates to match the latest modified file in the plugin export.
	 *
	 * When svn exports a directory, the file entries will reflect their last modified date
	 * however directories are created with their modified date set to the current date.
	 *
	 * This causes issues for ZIPs being built as we can't guarantee that two zips built
	 * from the same source files at different times will have the same checksums.
	 */
	protected function fix_directory_dates() {
		/*
		 * Find all files, output their modified dates, sort reverse numerically, grab the timestamp from the first entry
		 * Note: `sort | head` will generate STDERR output. This is expected and not a cause of concern. Silence it to remove red herrings.
		 */
		$latest_file_modified_timestamp = $this->exec( sprintf(
			"find %s -type f -printf '%%T@\n' | sort -nr 2>/dev/null | head -c 10",
			escapeshellarg( $this->tmp_build_dir )
		) );
		if ( ! $latest_file_modified_timestamp ) {
			throw new Exception( __METHOD__ . ': Unable to locate the latest modified files timestamp.', 503 );
		}

		$this->exec( sprintf(
			'find %s -type d -exec touch -m -t %s {} \;',
			escapeshellarg( $this->tmp_build_dir ),
			escapeshellarg( date( 'ymdHi.s', $latest_file_modified_timestamp ) )
		) );
	}

	/**
	 * Generates the actual ZIP file we've painstakingly created the files for.
	 */
	protected function generate_zip() {
		// If we're building an existing zip, remove the existing file first.
		if ( file_exists( $this->zip_file ) ) {
			unlink( $this->zip_file );
		}
		$this->exec( sprintf(
			'cd %s && find %s -print0 | sort -z | xargs -0 zip -Xuy %s 2>&1',
			escapeshellarg( $this->tmp_build_dir ),
			escapeshellarg( $this->slug ),
			escapeshellarg( $this->zip_file )
		), $zip_build_output, $return_value );

		if ( $return_value ) {
			throw new Exception( __METHOD__ . ': ZIP generation failed, return code: ' . $return_value, 503 );
		}
	}

	/**
	 * Generate the signature for a ZIP file.
	 */
	protected function generate_zip_signatures() {

		// TODO: Currently disabled, enable when ready.
		return false;

		if ( ! function_exists( 'wporg_sign_file' ) ) {
			return false;
		}

		$signatures = wporg_sign_file( $this->zip_file, 'plugin' );
		if ( $signatures ) {
			$this->signature_file = $this->zip_file . '.sig';

			// Fetch any existing signatures if needed.
			SVN::up( $this->signature_file );

			// If this file was previously signed, keep the previous version.
			// This would only occur if a ZIP file was replaced in the few moments between ZIP download starting, and fetching the signature for verification.
			if ( file_exists( $this->signature_file ) ) {
				$existing_signatures = file( $this->signature_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
				$signatures = array_unique( array_merge( $signatures, $existing_signatures ) );
			}

			file_put_contents( $this->signature_file, implode( "\n", $signatures ) );
		}
	}

	/**
	 * Purge ZIP caches after ZIP building.
	 *
	 * @param array $versions The list of plugin versions of modified zips.
	 * @return bool
	 */
	public function invalidate_zip_caches( $versions ) {
		// TODO: Implement PURGE
		return true;
		if ( ! defined( 'PLUGIN_ZIP_X_ACCEL_REDIRECT_LOCATION' ) ) {
			return true;
		}

		foreach ( $versions as $version ) {
			if ( 'trunk' == $version ) {
				$zip = "{$this->slug}/{$this->slug}.zip";
			} else {
				$zip = "{$this->slug}/{$this->slug}.{$version}.zip";
			}

			foreach ( $plugins_downloads_load_balancer /* TODO */ as $lb ) {
				$url = 'http://' . $lb . PLUGIN_ZIP_X_ACCEL_REDIRECT_LOCATION . $zip;

				wp_remote_request( $url, array(
					'method' => 'PURGE',
				) );
			}
		}
	}

	/**
	 * Cleans up any temporary directories created by the ZIP Builder.
	 */
	protected function cleanup() {
		if ( $this->tmp_dir ) {
			$this->exec( sprintf( 'rm -rf %s', escapeshellarg( $this->tmp_dir ) ) );
		}
	}

	/**
	 * Cleans up any temporary directories created by the ZIP builder for a specific build.
	 */
	protected function cleanup_plugin_tmp() {
		if ( $this->tmp_build_dir ) {
			$this->exec( sprintf( 'rm -rf %s', escapeshellarg( $this->tmp_build_dir ) ) );
		}
	}

	/**
	 * Executes a command with 'proper' locale/language settings
	 * so that utf8 strings are handled correctly.
	 *
	 * WordPress.org uses the en_US.UTF-8 locale.
	 */
	protected function exec( $command, &$output = null, &$return_val = null ) {
		return exec( 'export LC_CTYPE="en_US.UTF-8" LANG="en_US.UTF-8"; ' . $command, $output, $return_val );
	}

}
