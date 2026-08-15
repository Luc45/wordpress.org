<?php
/**
 * Manually install, verify, or remove the inert publication owner table.
 *
 * Runtime requests never execute this file or perform schema changes.
 * Rollback is only safe before runtime Store consumers exist, or while every
 * publication writer is explicitly quiesced.
 *
 * @package WordPressdotorg_Plugin_Directory
 */

declare( strict_types = 1 );

namespace WordPressdotorg\Plugin_Directory;

use WordPressdotorg\Plugin_Directory\Publication\Store;

if ( 'cli' !== php_sapi_name() ) {
	exit( 1 );
}

$opts = getopt( '', array( 'url:', 'abspath:', 'install', 'verify', 'rollback' ) );
if ( empty( $opts['url'] ) ) {
	$opts['url'] = 'https://wordpress.org/plugins/';
}
if ( empty( $opts['abspath'] ) && false !== strpos( __DIR__, 'wp-content' ) ) {
	$opts['abspath'] = substr( __DIR__, 0, strpos( __DIR__, 'wp-content' ) );
}

$actions = array_values(
	array_filter(
		array( 'install', 'verify', 'rollback' ),
		static fn ( string $action ): bool => isset( $opts[ $action ] )
	)
);
if ( 1 !== count( $actions ) || empty( $opts['abspath'] ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- This is a CLI-only script writing to STDERR.
	fwrite(
		STDERR,
		"Usage: php {$argv[0]} --install|--verify|--rollback [--url https://wordpress.org/plugins/] [--abspath /path/to/wordpress/]\n"
	);
	exit( 1 );
}

// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- WordPress is not loaded yet.
$_SERVER['HTTP_HOST'] = parse_url( $opts['url'], PHP_URL_HOST );
// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- WordPress is not loaded yet.
$_SERVER['REQUEST_URI'] = parse_url( $opts['url'], PHP_URL_PATH );

require rtrim( $opts['abspath'], '/' ) . '/wp-load.php';

if ( ! class_exists( Plugin_Directory::class ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- This is a CLI-only script writing to STDERR.
	fwrite( STDERR, "Error: this site does not have the Plugin Directory plugin enabled.\n" );
	exit( 1 );
}

try {
	global $wpdb;
	$store            = new Store( $wpdb );
	$migration_action = $actions[0];

	if ( 'install' === $migration_action ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Store returns the identifier-prepared canonical DDL.
		if ( false === $wpdb->query( $store->schema_sql() ) ) {
			throw new \RuntimeException( 'Could not install the plugin publication owner table: ' . $wpdb->last_error );
		}
		$store->assert_schema();
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Validated table name written to CLI stdout.
		echo "Installed and verified {$store->table_name()}. V2 admission remains inert.\n";
	} elseif ( 'verify' === $migration_action ) {
		$store->assert_schema();
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Validated table name written to CLI stdout.
		echo "Verified {$store->table_name()}.\n";
	} else {
		$store->assert_schema();
		if ( ! $store->rollback_is_safe() ) {
			throw new \RuntimeException( 'Rollback is unsafe: sticky V2 ownership, a writer claim, or a staged projection remains.' );
		}
		if ( false === $wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $store->table_name() ) ) ) {
			throw new \RuntimeException( 'Could not remove the plugin publication owner table: ' . $wpdb->last_error );
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Validated table name written to CLI stdout.
		echo "Removed inert table {$store->table_name()}.\n";
	}
} catch ( \Throwable $error ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- This is a CLI-only script writing to STDERR.
	fwrite( STDERR, "Error: {$error->getMessage()}\n" );
	exit( 1 );
}
