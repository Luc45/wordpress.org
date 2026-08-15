<?php
/**
 * Atomic ownership and writer fencing for plugin publication.
 *
 * @package WordPressdotorg_Plugin_Directory
 */

declare( strict_types = 1 );

namespace WordPressdotorg\Plugin_Directory\Publication;

/**
 * Owns the one-row-per-plugin publication control plane.
 *
 * This class contains storage mechanics only. It does not decide rollout,
 * admission eligibility, publication policy, retries, or recovery outcomes.
 * Schema installation is deliberately absent; the manual migration command
 * installs and verifies the additive table before any runtime owner uses it.
 *
 * A reserved claim may be replaced after it is stale because no external
 * effect has started. A committing claim is never replaceable: recovery must
 * verify or finish that exact effect before releasing its token.
 */
class Store {

	/** Legacy publication owns the plugin. */
	public const OWNER_LEGACY = 1;

	/** The vertical publication pipeline owns the plugin. */
	public const OWNER_V2 = 2;

	/** The writer has not started its external effect. */
	public const CLAIM_RESERVED = 'reserved';

	/** The writer may have started its external effect. */
	public const CLAIM_COMMITTING = 'committing';

	/** Custom table suffix below the current WordPress table prefix. */
	private const TABLE_SUFFIX = 'plugin_publication_owner';

	/**
	 * Database connection.
	 *
	 * @var \wpdb
	 */
	private \wpdb $db;

	/**
	 * Fully prefixed custom table name.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Constructor.
	 *
	 * @param \wpdb $db WordPress database connection.
	 * @throws \UnexpectedValueException When the prefixed table name is invalid.
	 */
	public function __construct( \wpdb $db ) {
		$this->db    = $db;
		$this->table = $db->prefix . self::TABLE_SUFFIX;

		if ( ! preg_match( '/^[A-Za-z0-9_]+$/D', $this->table ) ) {
			throw new \UnexpectedValueException( 'The plugin publication table name is invalid.' );
		}
	}

	/**
	 * Return the fully prefixed table name.
	 *
	 * @return string Table name.
	 */
	public function table_name(): string {
		return $this->table;
	}

	/**
	 * Return the additive schema used only by the migration command and tests.
	 *
	 * @return string CREATE TABLE statement.
	 */
	public function schema_sql(): string {
		return $this->db->prepare(
			"CREATE TABLE IF NOT EXISTS %i (
				plugin_id bigint(20) unsigned NOT NULL,
				plugin_slug varchar(200) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
				pipeline_version tinyint(3) unsigned NOT NULL,
				revision bigint(20) unsigned NOT NULL,
				claim_token binary(16) DEFAULT NULL,
				claim_phase enum('reserved','committing') DEFAULT NULL,
				claim_kind varchar(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
				claim_key varchar(191) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
				claim_changed_at datetime DEFAULT NULL,
				staged_artifact_id char(36) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
				staged_file varbinary(512) DEFAULT NULL,
				staged_zip_sha256 binary(32) DEFAULT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY (plugin_id),
				UNIQUE KEY plugin_slug (plugin_slug),
				KEY stale_claim (claim_phase, claim_changed_at)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
			$this->table
		);
	}

	/**
	 * Fail unless the manually installed table has the exact required shape.
	 *
	 * @throws \RuntimeException When the schema is absent or has an unexpected shape.
	 */
	public function assert_schema(): void {
		$engine = $this->db->get_var(
			$this->db->prepare(
				'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
				$this->table
			)
		);

		if ( 'InnoDB' !== $engine ) {
			throw new \RuntimeException( 'The plugin publication owner table is missing or is not InnoDB.' );
		}

		$columns = $this->db->get_results(
			$this->db->prepare(
				'SELECT COLUMN_NAME AS name, DATA_TYPE AS data_type, COLUMN_TYPE AS column_type,
					IS_NULLABLE AS is_nullable, CHARACTER_MAXIMUM_LENGTH AS maximum_length,
					COLLATION_NAME AS collation_name
				FROM information_schema.COLUMNS
				WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s
				ORDER BY ORDINAL_POSITION',
				$this->table
			),
			ARRAY_A
		);

		if ( ! is_array( $columns ) ) {
			$this->throw_query_error( 'read publication schema columns' );
		}

		$actual = array();
		foreach ( $columns as $column ) {
			$actual[ $column['name'] ] = array(
				'type'        => strtolower( $column['data_type'] ),
				'column_type' => strtolower( $column['column_type'] ),
				'nullable'    => 'YES' === $column['is_nullable'],
				'length'      => null === $column['maximum_length'] ? null : (int) $column['maximum_length'],
				'collation'   => null === $column['collation_name'] ? null : strtolower( $column['collation_name'] ),
			);
		}

		$expected = $this->expected_columns();
		if ( array_keys( $expected ) !== array_keys( $actual ) ) {
			throw new \RuntimeException( 'The plugin publication owner table has unexpected columns.' );
		}

		foreach ( $expected as $name => $definition ) {
			$column = $actual[ $name ];
			foreach ( $definition as $field => $value ) {
				if ( 'unsigned' === $field ) {
					if ( str_contains( $column['column_type'], 'unsigned' ) !== $value ) {
						// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal invariant exception, not HTML output.
						throw new \RuntimeException( "The plugin publication owner column {$name} has an invalid unsigned attribute." );
					}
					continue;
				}

				if ( $value !== $column[ $field ] ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal invariant exception, not HTML output.
					throw new \RuntimeException( "The plugin publication owner column {$name} has an invalid {$field}." );
				}
			}
		}

		$indexes = $this->db->get_results(
			$this->db->prepare( 'SHOW INDEX FROM %i', $this->table ),
			ARRAY_A
		);
		if ( ! is_array( $indexes ) ) {
			$this->throw_query_error( 'read publication schema indexes' );
		}

		$actual_indexes = array();
		foreach ( $indexes as $index ) {
			$name = $index['Key_name'];
			if ( ! isset( $actual_indexes[ $name ] ) ) {
				$actual_indexes[ $name ] = array(
					'unique'  => 0 === (int) $index['Non_unique'],
					'columns' => array(),
				);
			}
			$actual_indexes[ $name ]['columns'][ (int) $index['Seq_in_index'] ] = $index['Column_name'];
		}
		foreach ( $actual_indexes as &$index ) {
			ksort( $index['columns'] );
			$index['columns'] = array_values( $index['columns'] );
		}
		unset( $index );
		ksort( $actual_indexes );

		$expected_indexes = array(
			'PRIMARY'     => array(
				'unique'  => true,
				'columns' => array( 'plugin_id' ),
			),
			'plugin_slug' => array(
				'unique'  => true,
				'columns' => array( 'plugin_slug' ),
			),
			'stale_claim' => array(
				'unique'  => false,
				'columns' => array( 'claim_phase', 'claim_changed_at' ),
			),
		);
		ksort( $expected_indexes );

		if ( $expected_indexes !== $actual_indexes ) {
			throw new \RuntimeException( 'The plugin publication owner table has unexpected indexes.' );
		}
	}

	/**
	 * Load and validate the canonical owner row for one exact plugin identity.
	 *
	 * @param int    $plugin_id   Plugin post ID.
	 * @param string $plugin_slug Plugin slug.
	 * @return array|null Validated row, or null when it does not exist.
	 * @throws \UnexpectedValueException When the stored identity or row is inconsistent.
	 */
	public function load( int $plugin_id, string $plugin_slug ): ?array {
		$this->assert_identity( $plugin_id, $plugin_slug );

		$rows = $this->db->get_results(
			$this->db->prepare(
				'SELECT plugin_id, plugin_slug, pipeline_version, revision,
					LOWER(HEX(claim_token)) AS claim_token, claim_phase, claim_kind, claim_key,
					claim_changed_at, staged_artifact_id, staged_file,
					LOWER(HEX(staged_zip_sha256)) AS staged_zip_sha256, updated_at
				FROM %i WHERE plugin_id = %d OR plugin_slug = %s',
				$this->table,
				$plugin_id,
				$plugin_slug
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			$this->throw_query_error( 'load plugin publication owner' );
		}
		if ( ! $rows ) {
			return null;
		}
		if ( 1 !== count( $rows ) ) {
			throw new \UnexpectedValueException( 'The plugin publication identity resolves to multiple rows.' );
		}

		$row = $rows[0];
		if ( $plugin_id !== (int) $row['plugin_id'] || $plugin_slug !== $row['plugin_slug'] ) {
			throw new \UnexpectedValueException( 'The plugin publication ID and slug disagree.' );
		}

		return $this->validate_row( $row );
	}

	/**
	 * Generate a random 128-bit writer token.
	 *
	 * @return string Lowercase hexadecimal token.
	 */
	public static function new_token(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Compete for first ownership as V2, then return the canonical winner.
	 *
	 * @param int    $plugin_id   Plugin post ID.
	 * @param string $plugin_slug Plugin slug.
	 * @return array Canonical owner row.
	 * @throws \RuntimeException When the winning row cannot be loaded after the insert.
	 */
	public function first_admit_v2( int $plugin_id, string $plugin_slug ): array {
		$this->assert_identity( $plugin_id, $plugin_slug );

		$this->execute(
			$this->db->prepare(
				'INSERT INTO %i (plugin_id, plugin_slug, pipeline_version, revision, updated_at)
				VALUES (%d, %s, 2, 1, UTC_TIMESTAMP())
				ON DUPLICATE KEY UPDATE plugin_id = plugin_id',
				$this->table,
				$plugin_id,
				$plugin_slug
			),
			'persist first V2 publication owner'
		);

		$row = $this->load( $plugin_id, $plugin_slug );
		if ( null === $row ) {
			throw new \RuntimeException( 'The first V2 publication owner was not persisted.' );
		}
		return $row;
	}

	/**
	 * Compete for first use as a claimed legacy writer.
	 *
	 * @param int    $plugin_id   Plugin post ID.
	 * @param string $plugin_slug Plugin slug.
	 * @param string $token       Exact 128-bit hexadecimal claim token.
	 * @param string $kind        Concrete effect kind.
	 * @param string $key         Exact effect identity.
	 * @return array Canonical owner row; compare its token to determine the winner.
	 * @throws \RuntimeException When the winning row cannot be loaded after the insert.
	 */
	public function first_legacy_claim( int $plugin_id, string $plugin_slug, string $token, string $kind, string $key ): array {
		$this->assert_identity( $plugin_id, $plugin_slug );
		$this->assert_token( $token );
		$this->assert_effect( $kind, $key );

		$this->execute(
			$this->db->prepare(
				"INSERT INTO %i
					(plugin_id, plugin_slug, pipeline_version, revision, claim_token, claim_phase,
					claim_kind, claim_key, claim_changed_at, updated_at)
				VALUES (%d, %s, 1, 1, UNHEX(%s), 'reserved', %s, %s, UTC_TIMESTAMP(), UTC_TIMESTAMP())
				ON DUPLICATE KEY UPDATE plugin_id = plugin_id",
				$this->table,
				$plugin_id,
				$plugin_slug,
				$token,
				$kind,
				$key
			),
			'persist first legacy publication claim'
		);

		$row = $this->load( $plugin_id, $plugin_slug );
		if ( null === $row ) {
			throw new \RuntimeException( 'The first legacy publication owner was not persisted.' );
		}
		return $row;
	}

	/**
	 * Atomically make an unclaimed legacy owner sticky V2.
	 *
	 * The caller owns the external quiescence check. A zero result means reload
	 * and obey the winner; it is never permission to execute legacy behavior.
	 *
	 * @param int    $plugin_id        Plugin post ID.
	 * @param string $plugin_slug      Plugin slug.
	 * @param int    $expected_revision Previously loaded revision.
	 * @return bool Whether this CAS won.
	 */
	public function admit_v2( int $plugin_id, string $plugin_slug, int $expected_revision ): bool {
		$this->assert_identity( $plugin_id, $plugin_slug );
		$this->assert_revision( $expected_revision );

		return $this->changed(
			$this->db->prepare(
				'UPDATE %i SET pipeline_version = 2, revision = revision + 1, updated_at = UTC_TIMESTAMP()
				WHERE plugin_id = %d AND plugin_slug = %s AND pipeline_version = 1
					AND revision = %d AND claim_token IS NULL',
				$this->table,
				$plugin_id,
				$plugin_slug,
				$expected_revision
			),
			'admit V2 publication owner'
		);
	}

	/**
	 * Reserve one effect under the exact owner revision.
	 *
	 * @param int    $plugin_id        Plugin post ID.
	 * @param string $plugin_slug      Plugin slug.
	 * @param int    $pipeline_version Required owner.
	 * @param int    $expected_revision Previously loaded revision.
	 * @param string $token             Exact 128-bit hexadecimal claim token.
	 * @param string $kind              Concrete effect kind.
	 * @param string $key               Exact effect identity.
	 * @return bool Whether this CAS won.
	 */
	public function acquire_claim( int $plugin_id, string $plugin_slug, int $pipeline_version, int $expected_revision, string $token, string $kind, string $key ): bool {
		$this->assert_identity( $plugin_id, $plugin_slug );
		$this->assert_owner( $pipeline_version );
		$this->assert_revision( $expected_revision );
		$this->assert_token( $token );
		$this->assert_effect( $kind, $key );

		return $this->changed(
			$this->db->prepare(
				"UPDATE %i SET revision = revision + 1, claim_token = UNHEX(%s),
					claim_phase = 'reserved', claim_kind = %s, claim_key = %s,
					claim_changed_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
				WHERE plugin_id = %d AND plugin_slug = %s AND pipeline_version = %d
					AND revision = %d AND claim_token IS NULL",
				$this->table,
				$token,
				$kind,
				$key,
				$plugin_id,
				$plugin_slug,
				$pipeline_version,
				$expected_revision
			),
			'acquire plugin publication claim'
		);
	}

	/**
	 * Replace only an exact stale reservation and advance its fencing revision.
	 *
	 * @param int    $plugin_id        Plugin post ID.
	 * @param string $plugin_slug      Plugin slug.
	 * @param int    $pipeline_version Required owner.
	 * @param int    $expected_revision Previously loaded revision.
	 * @param string $expected_token    Previously loaded token.
	 * @param string $new_token         Replacement token.
	 * @param string $kind              Replacement effect kind.
	 * @param string $key               Replacement effect identity.
	 * @param int    $stale_seconds     Positive DB-time staleness threshold.
	 * @return bool Whether this CAS won.
	 * @throws \InvalidArgumentException When the token or staleness threshold cannot replace a claim.
	 */
	public function replace_stale_reserved( int $plugin_id, string $plugin_slug, int $pipeline_version, int $expected_revision, string $expected_token, string $new_token, string $kind, string $key, int $stale_seconds ): bool {
		$this->assert_identity( $plugin_id, $plugin_slug );
		$this->assert_owner( $pipeline_version );
		$this->assert_revision( $expected_revision );
		$this->assert_token( $expected_token );
		$this->assert_token( $new_token );
		$this->assert_effect( $kind, $key );
		if ( $expected_token === $new_token || $stale_seconds < 1 ) {
			throw new \InvalidArgumentException( 'A stale claim requires a new token and positive threshold.' );
		}

		return $this->changed(
			$this->db->prepare(
				"UPDATE %i SET revision = revision + 1, claim_token = UNHEX(%s),
					claim_phase = 'reserved', claim_kind = %s, claim_key = %s,
					claim_changed_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
				WHERE plugin_id = %d AND plugin_slug = %s AND pipeline_version = %d
					AND revision = %d AND claim_token = UNHEX(%s) AND claim_phase = 'reserved'
					AND claim_changed_at < UTC_TIMESTAMP() - INTERVAL %d SECOND",
				$this->table,
				$new_token,
				$kind,
				$key,
				$plugin_id,
				$plugin_slug,
				$pipeline_version,
				$expected_revision,
				$expected_token,
				$stale_seconds
			),
			'replace stale plugin publication claim'
		);
	}

	/**
	 * Cross the final token and revision fence immediately before an effect.
	 *
	 * @param int    $plugin_id        Plugin post ID.
	 * @param string $plugin_slug      Plugin slug.
	 * @param int    $pipeline_version Required owner.
	 * @param int    $expected_revision Previously loaded revision.
	 * @param string $token             Exact claim token.
	 * @return bool Whether this CAS won.
	 */
	public function begin_commit( int $plugin_id, string $plugin_slug, int $pipeline_version, int $expected_revision, string $token ): bool {
		$this->assert_identity( $plugin_id, $plugin_slug );
		$this->assert_owner( $pipeline_version );
		$this->assert_revision( $expected_revision );
		$this->assert_token( $token );

		return $this->changed(
			$this->db->prepare(
				"UPDATE %i SET revision = revision + 1, claim_phase = 'committing',
					claim_changed_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
				WHERE plugin_id = %d AND plugin_slug = %s AND pipeline_version = %d
					AND revision = %d AND claim_token = UNHEX(%s) AND claim_phase = 'reserved'",
				$this->table,
				$plugin_id,
				$plugin_slug,
				$pipeline_version,
				$expected_revision,
				$token
			),
			'begin plugin publication commit'
		);
	}

	/**
	 * Complete and release the exact committing claim.
	 *
	 * @param int    $plugin_id        Plugin post ID.
	 * @param string $plugin_slug      Plugin slug.
	 * @param int    $pipeline_version Required owner.
	 * @param int    $expected_revision Previously loaded revision.
	 * @param string $token             Exact claim token.
	 * @return bool Whether this CAS won.
	 */
	public function complete_claim( int $plugin_id, string $plugin_slug, int $pipeline_version, int $expected_revision, string $token ): bool {
		return $this->complete( $plugin_id, $plugin_slug, $pipeline_version, $expected_revision, $token );
	}

	/**
	 * Complete a claim while publishing the standalone staged projection.
	 *
	 * @param int    $plugin_id        Plugin post ID.
	 * @param string $plugin_slug      Plugin slug.
	 * @param int    $expected_revision Previously loaded revision.
	 * @param string $token             Exact claim token.
	 * @param string $artifact_id       Exact staged artifact UUIDv4.
	 * @param string $staged_file       Exact backing file path.
	 * @param string $zip_sha256        Exact staged ZIP SHA-256.
	 * @return bool Whether this CAS won.
	 * @throws \UnexpectedValueException When the projection does not match the active staging claim.
	 */
	public function complete_with_staged_projection( int $plugin_id, string $plugin_slug, int $expected_revision, string $token, string $artifact_id, string $staged_file, string $zip_sha256 ): bool {
		$this->assert_projection( $plugin_slug, $artifact_id, $staged_file, $zip_sha256 );
		$this->assert_revision( $expected_revision );
		$this->assert_token( $token );
		$claim = $this->load( $plugin_id, $plugin_slug );
		if ( null === $claim || $expected_revision !== $claim['revision'] || $token !== $claim['claim_token'] ) {
			return false;
		}
		if (
			self::OWNER_V2 !== $claim['pipeline_version']
			|| self::CLAIM_COMMITTING !== $claim['claim_phase']
			|| 'stage' !== $claim['claim_kind']
			|| $artifact_id !== $claim['claim_key']
		) {
			throw new \UnexpectedValueException( 'The staged projection does not match the active publication claim.' );
		}

		return $this->complete(
			$plugin_id,
			$plugin_slug,
			self::OWNER_V2,
			$expected_revision,
			$token,
			array(
				'artifact_id' => $artifact_id,
				'file'        => $staged_file,
				'sha256'      => $zip_sha256,
			)
		);
	}

	/**
	 * Revoke the exact staged projection while no external writer is active.
	 *
	 * @param int    $plugin_id        Plugin post ID.
	 * @param string $plugin_slug      Plugin slug.
	 * @param int    $expected_revision Previously loaded revision.
	 * @param string $artifact_id       Exact projected artifact UUIDv4.
	 * @return bool Whether this CAS won.
	 */
	public function clear_staged_projection( int $plugin_id, string $plugin_slug, int $expected_revision, string $artifact_id ): bool {
		$this->assert_identity( $plugin_id, $plugin_slug );
		$this->assert_revision( $expected_revision );
		$this->assert_artifact_id( $artifact_id );

		return $this->changed(
			$this->db->prepare(
				'UPDATE %i SET revision = revision + 1, staged_artifact_id = NULL,
					staged_file = NULL, staged_zip_sha256 = NULL, updated_at = UTC_TIMESTAMP()
				WHERE plugin_id = %d AND plugin_slug = %s AND pipeline_version = 2
					AND revision = %d AND claim_token IS NULL AND staged_artifact_id = %s',
				$this->table,
				$plugin_id,
				$plugin_slug,
				$expected_revision,
				$artifact_id
			),
			'clear staged plugin publication projection'
		);
	}

	/**
	 * Read the only state exposed to the standalone staged-download adapter.
	 *
	 * @param string $plugin_slug Plugin slug.
	 * @param string $artifact_id Exact staged artifact UUIDv4.
	 * @return array|false Exact file and SHA-256, or false when unauthorized.
	 */
	public function get_staged_projection( string $plugin_slug, string $artifact_id ) {
		$this->assert_slug( $plugin_slug );
		$this->assert_artifact_id( $artifact_id );

		$row = $this->db->get_row(
			$this->db->prepare(
				'SELECT staged_file, LOWER(HEX(staged_zip_sha256)) AS staged_zip_sha256
				FROM %i WHERE plugin_slug = %s AND pipeline_version = 2
					AND staged_artifact_id = %s AND staged_file IS NOT NULL
					AND staged_zip_sha256 IS NOT NULL LIMIT 1',
				$this->table,
				$plugin_slug,
				$artifact_id
			),
			ARRAY_A
		);

		if ( null === $row ) {
			if ( $this->db->last_error ) {
				$this->throw_query_error( 'read staged plugin publication projection' );
			}
			return false;
		}

		$this->assert_projection( $plugin_slug, $artifact_id, $row['staged_file'], $row['staged_zip_sha256'] );
		return $row;
	}

	/**
	 * Whether an old binary may safely ignore and drop this additive table.
	 *
	 * Unclaimed legacy owner rows are inert. Sticky V2 ownership, any claim, or
	 * any staged projection makes old-binary rollback unsafe.
	 * The caller must separately ensure that publication writers are quiesced.
	 *
	 * @return bool Whether the table is safe to drop.
	 */
	public function rollback_is_safe(): bool {
		$count = $this->db->get_var(
			$this->db->prepare(
				'SELECT COUNT(*) FROM %i WHERE pipeline_version <> 1
					OR claim_token IS NOT NULL OR claim_phase IS NOT NULL
					OR claim_kind IS NOT NULL OR claim_key IS NOT NULL OR claim_changed_at IS NOT NULL
					OR staged_artifact_id IS NOT NULL OR staged_file IS NOT NULL OR staged_zip_sha256 IS NOT NULL',
				$this->table
			)
		);

		if ( null === $count ) {
			$this->throw_query_error( 'check plugin publication rollback' );
		}
		return 0 === (int) $count;
	}

	/**
	 * Complete a claim, optionally replacing the staged projection atomically.
	 *
	 * @param int        $plugin_id        Plugin post ID.
	 * @param string     $plugin_slug      Plugin slug.
	 * @param int        $pipeline_version Required owner.
	 * @param int        $expected_revision Previously loaded revision.
	 * @param string     $token             Exact claim token.
	 * @param array|null $projection        Optional staged projection.
	 * @return bool Whether this CAS won.
	 */
	private function complete( int $plugin_id, string $plugin_slug, int $pipeline_version, int $expected_revision, string $token, ?array $projection = null ): bool {
		$this->assert_identity( $plugin_id, $plugin_slug );
		$this->assert_owner( $pipeline_version );
		$this->assert_revision( $expected_revision );
		$this->assert_token( $token );

		$projection_sql = '';
		$values         = array( $this->table );
		if ( null !== $projection ) {
			$projection_sql = 'staged_artifact_id = %s, staged_file = %s, staged_zip_sha256 = UNHEX(%s),';
			$values[]       = $projection['artifact_id'];
			$values[]       = $projection['file'];
			$values[]       = $projection['sha256'];
		}
		$values[] = $plugin_id;
		$values[] = $plugin_slug;
		$values[] = $pipeline_version;
		$values[] = $expected_revision;
		$values[] = $token;

		$query = "UPDATE %i SET {$projection_sql}
			revision = revision + 1, claim_token = NULL, claim_phase = NULL,
			claim_kind = NULL, claim_key = NULL, claim_changed_at = NULL,
			updated_at = UTC_TIMESTAMP()
			WHERE plugin_id = %d AND plugin_slug = %s AND pipeline_version = %d
				AND revision = %d AND claim_token = UNHEX(%s) AND claim_phase = 'committing'";

		return $this->changed(
			$this->db->prepare( $query, ...$values ),
			'complete plugin publication claim'
		);
	}

	/**
	 * Execute a write that may legitimately lose a CAS.
	 *
	 * @param string $query   Prepared SQL.
	 * @param string $purpose Human-readable operation.
	 * @return bool Whether exactly one row changed.
	 * @throws \UnexpectedValueException When the write changes more than one row.
	 */
	private function changed( string $query, string $purpose ): bool {
		$result = $this->execute( $query, $purpose );
		if ( 0 === $result ) {
			return false;
		}
		if ( 1 !== $result ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal invariant exception, not HTML output.
			throw new \UnexpectedValueException( "The plugin publication {$purpose} changed an unexpected number of rows." );
		}
		return true;
	}

	/**
	 * Execute prepared SQL and throw on a database failure.
	 *
	 * @param string $query   Prepared SQL.
	 * @param string $purpose Human-readable operation.
	 * @return int Affected rows.
	 */
	private function execute( string $query, string $purpose ): int {
		$result = $this->db->query( $query );
		if ( false === $result ) {
			$this->throw_query_error( $purpose );
		}
		return (int) $result;
	}

	/**
	 * Throw the current database error as an internal invariant failure.
	 *
	 * @param string $purpose Human-readable operation.
	 * @throws \RuntimeException Always, with the database failure context.
	 */
	private function throw_query_error( string $purpose ): void {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal invariant exception, not HTML output.
		throw new \RuntimeException( "Failed to {$purpose}: {$this->db->last_error}" );
	}

	/**
	 * Validate one loaded row and normalize database scalar types.
	 *
	 * @param array $row Raw ARRAY_A row.
	 * @return array Validated row.
	 * @throws \UnexpectedValueException When the stored row is internally inconsistent.
	 */
	private function validate_row( array $row ): array {
		$row['plugin_id']        = (int) $row['plugin_id'];
		$row['pipeline_version'] = (int) $row['pipeline_version'];
		$row['revision']         = (int) $row['revision'];
		$this->assert_owner( $row['pipeline_version'] );
		$this->assert_revision( $row['revision'] );

		$claim        = array( $row['claim_token'], $row['claim_phase'], $row['claim_kind'], $row['claim_key'], $row['claim_changed_at'] );
		$claim_fields = count( array_filter( $claim, static fn ( $value ) => null !== $value ) );
		if ( 0 !== $claim_fields && count( $claim ) !== $claim_fields ) {
			throw new \UnexpectedValueException( 'The plugin publication claim is partial.' );
		}
		if ( $claim_fields ) {
			$this->assert_token( $row['claim_token'] );
			if ( ! in_array( $row['claim_phase'], array( self::CLAIM_RESERVED, self::CLAIM_COMMITTING ), true ) ) {
				throw new \UnexpectedValueException( 'The plugin publication claim phase is invalid.' );
			}
			$this->assert_effect( $row['claim_kind'], $row['claim_key'] );
		}

		$projection        = array( $row['staged_artifact_id'], $row['staged_file'], $row['staged_zip_sha256'] );
		$projection_fields = count( array_filter( $projection, static fn ( $value ) => null !== $value ) );
		if ( 0 !== $projection_fields && count( $projection ) !== $projection_fields ) {
			throw new \UnexpectedValueException( 'The staged plugin publication projection is partial.' );
		}
		if ( $projection_fields ) {
			if ( self::OWNER_V2 !== $row['pipeline_version'] ) {
				throw new \UnexpectedValueException( 'A legacy owner has a staged V2 projection.' );
			}
			$this->assert_projection( $row['plugin_slug'], $row['staged_artifact_id'], $row['staged_file'], $row['staged_zip_sha256'] );
		}

		return $row;
	}

	/**
	 * Validate one plugin database identity.
	 *
	 * @param int    $plugin_id   Plugin post ID.
	 * @param string $plugin_slug Plugin slug.
	 * @throws \InvalidArgumentException When the identity is invalid.
	 */
	private function assert_identity( int $plugin_id, string $plugin_slug ): void {
		if ( $plugin_id < 1 ) {
			throw new \InvalidArgumentException( 'The plugin publication identity is invalid.' );
		}
		$this->assert_slug( $plugin_slug );
	}

	/**
	 * Validate one plugin slug.
	 *
	 * @param string $plugin_slug Plugin slug.
	 * @throws \InvalidArgumentException When the slug is invalid.
	 */
	private function assert_slug( string $plugin_slug ): void {
		if ( ! preg_match( '/^[a-z0-9][a-z0-9-]{0,199}$/D', $plugin_slug ) ) {
			throw new \InvalidArgumentException( 'The plugin publication slug is invalid.' );
		}
	}

	/**
	 * Validate a pipeline owner value.
	 *
	 * @param int $pipeline_version Pipeline owner value.
	 * @throws \UnexpectedValueException When the owner is invalid.
	 */
	private function assert_owner( int $pipeline_version ): void {
		if ( ! in_array( $pipeline_version, array( self::OWNER_LEGACY, self::OWNER_V2 ), true ) ) {
			throw new \UnexpectedValueException( 'The plugin publication owner is invalid.' );
		}
	}

	/**
	 * Validate a positive fencing revision.
	 *
	 * @param int $revision Fencing revision.
	 * @throws \InvalidArgumentException When the revision is invalid.
	 */
	private function assert_revision( int $revision ): void {
		if ( $revision < 1 ) {
			throw new \InvalidArgumentException( 'The plugin publication revision is invalid.' );
		}
	}

	/**
	 * Validate an exact 128-bit claim token.
	 *
	 * @param string $token Claim token.
	 * @throws \InvalidArgumentException When the token is invalid.
	 */
	private function assert_token( string $token ): void {
		if ( ! preg_match( '/^[a-f0-9]{32}$/D', $token ) ) {
			throw new \InvalidArgumentException( 'The plugin publication claim token is invalid.' );
		}
	}

	/**
	 * Validate concrete effect identity fields.
	 *
	 * @param string $kind Effect kind.
	 * @param string $key  Effect identity.
	 * @throws \InvalidArgumentException When either effect field is invalid.
	 */
	private function assert_effect( string $kind, string $key ): void {
		if (
			! preg_match( '/^[a-z][a-z0-9_-]{0,31}$/D', $kind )
			|| '' === $key
			|| strlen( $key ) > 191
			|| ! preg_match( '/^[\x20-\x7e]+$/D', $key )
		) {
			throw new \InvalidArgumentException( 'The plugin publication effect identity is invalid.' );
		}
	}

	/**
	 * Validate an exact artifact UUIDv4.
	 *
	 * @param string $artifact_id Artifact UUIDv4.
	 * @throws \InvalidArgumentException When the artifact ID is invalid.
	 */
	private function assert_artifact_id( string $artifact_id ): void {
		if ( ! preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $artifact_id ) ) {
			throw new \InvalidArgumentException( 'The staged plugin publication artifact ID is invalid.' );
		}
	}

	/**
	 * Validate the dependency-free standalone staged projection.
	 *
	 * @param string $plugin_slug Plugin slug.
	 * @param string $artifact_id Artifact UUIDv4.
	 * @param string $staged_file Exact backing file path.
	 * @param string $zip_sha256  Exact staged ZIP SHA-256.
	 * @throws \InvalidArgumentException When the projection is invalid.
	 */
	private function assert_projection( string $plugin_slug, string $artifact_id, string $staged_file, string $zip_sha256 ): void {
		$this->assert_artifact_id( $artifact_id );
		$expected_file = "{$plugin_slug}/{$plugin_slug}.gandalf-{$artifact_id}.zip";
		if ( $expected_file !== $staged_file || ! preg_match( '/^[a-f0-9]{64}$/D', $zip_sha256 ) ) {
			throw new \InvalidArgumentException( 'The staged plugin publication projection is invalid.' );
		}
	}

	/**
	 * Expected database-independent column descriptors.
	 *
	 * @return array Required column descriptors in ordinal order.
	 */
	private function expected_columns(): array {
		return array(
			'plugin_id'          => array(
				'type'     => 'bigint',
				'nullable' => false,
				'unsigned' => true,
			),
			'plugin_slug'        => array(
				'type'      => 'varchar',
				'nullable'  => false,
				'length'    => 200,
				'collation' => 'ascii_bin',
			),
			'pipeline_version'   => array(
				'type'     => 'tinyint',
				'nullable' => false,
				'unsigned' => true,
			),
			'revision'           => array(
				'type'     => 'bigint',
				'nullable' => false,
				'unsigned' => true,
			),
			'claim_token'        => array(
				'type'      => 'binary',
				'nullable'  => true,
				'length'    => 16,
				'collation' => null,
			),
			'claim_phase'        => array(
				'type'        => 'enum',
				'nullable'    => true,
				'column_type' => "enum('reserved','committing')",
			),
			'claim_kind'         => array(
				'type'      => 'varchar',
				'nullable'  => true,
				'length'    => 32,
				'collation' => 'ascii_bin',
			),
			'claim_key'          => array(
				'type'      => 'varchar',
				'nullable'  => true,
				'length'    => 191,
				'collation' => 'ascii_bin',
			),
			'claim_changed_at'   => array(
				'type'      => 'datetime',
				'nullable'  => true,
				'collation' => null,
			),
			'staged_artifact_id' => array(
				'type'      => 'char',
				'nullable'  => true,
				'length'    => 36,
				'collation' => 'ascii_bin',
			),
			'staged_file'        => array(
				'type'      => 'varbinary',
				'nullable'  => true,
				'length'    => 512,
				'collation' => null,
			),
			'staged_zip_sha256'  => array(
				'type'      => 'binary',
				'nullable'  => true,
				'length'    => 32,
				'collation' => null,
			),
			'updated_at'         => array(
				'type'      => 'datetime',
				'nullable'  => false,
				'collation' => null,
			),
		);
	}
}
