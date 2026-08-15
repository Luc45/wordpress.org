<?php
/**
 * Tests for sticky publication ownership and cross-pipeline writer fencing.
 *
 * @package WordPressdotorg_Plugin_Directory
 */

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use WordPressdotorg\Plugin_Directory\Publication\Store;

/**
 * Exercises the additive publication control table without runtime admission.
 */
class Plugin_Publication_Rollout_Test extends TestCase {

	/**
	 * Store under test.
	 *
	 * @var Store
	 */
	private Store $store;

	/**
	 * Install the disposable test table.
	 *
	 * @throws \RuntimeException When the test table cannot be installed.
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		global $wpdb;
		$store = new Store( $wpdb );
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $store->table_name() ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Store returns identifier-prepared test DDL.
		if ( false === $wpdb->query( $store->schema_sql() ) ) {
			throw new \RuntimeException( 'Could not install the publication owner test table.' );
		}
		$store->assert_schema();
	}

	/** Remove the disposable test table. */
	public static function tearDownAfterClass(): void {
		global $wpdb;
		$store = new Store( $wpdb );
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $store->table_name() ) );

		parent::tearDownAfterClass();
	}

	/** Clear control rows between tests. */
	protected function setUp(): void {
		parent::setUp();

		global $wpdb;
		$this->store = new Store( $wpdb );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $this->store->table_name() ) );
	}

	/**
	 * The unique row makes both first-use orders deterministic for the loser.
	 */
	public function test_first_admission_and_first_legacy_claim_obey_the_unique_winner(): void {
		$v2_token = Store::new_token();
		$v2       = $this->store->first_admit_v2( 1001, 'v2-first' );
		$loser    = $this->store->first_legacy_claim( 1001, 'v2-first', $v2_token, 'legacy_zip', 'trunk@1.0' );

		$this->assertSame( Store::OWNER_V2, $v2['pipeline_version'] );
		$this->assertSame( $v2, $loser );
		$this->assertNull( $loser['claim_token'] );

		$legacy_token = Store::new_token();
		$legacy       = $this->store->first_legacy_claim( 1002, 'legacy-first', $legacy_token, 'legacy_zip', 'trunk@1.0' );
		$loser        = $this->store->first_admit_v2( 1002, 'legacy-first' );

		$this->assertSame( Store::OWNER_LEGACY, $legacy['pipeline_version'] );
		$this->assertSame( $legacy_token, $legacy['claim_token'] );
		$this->assertSame( $legacy, $loser );
		$this->assertSame( 2, $this->row_count() );
	}

	/**
	 * Admission and a legacy writer cannot both win one loaded revision.
	 */
	public function test_admission_and_legacy_writer_share_one_revision_fence(): void {
		$admission_row = $this->unclaimed_legacy_owner( 1101, 'admission-wins' );
		$legacy_token  = Store::new_token();

		$this->assertTrue( $this->store->admit_v2( 1101, 'admission-wins', $admission_row['revision'] ) );
		$this->assertFalse(
			$this->store->acquire_claim(
				1101,
				'admission-wins',
				Store::OWNER_LEGACY,
				$admission_row['revision'],
				$legacy_token,
				'legacy_offer',
				'trunk@1.0'
			)
		);
		$this->assertSame( Store::OWNER_V2, $this->store->load( 1101, 'admission-wins' )['pipeline_version'] );

		$claim_row   = $this->unclaimed_legacy_owner( 1102, 'claim-wins' );
		$claim_token = Store::new_token();
		$this->assertTrue(
			$this->store->acquire_claim(
				1102,
				'claim-wins',
				Store::OWNER_LEGACY,
				$claim_row['revision'],
				$claim_token,
				'legacy_offer',
				'trunk@1.0'
			)
		);
		$this->assertFalse( $this->store->admit_v2( 1102, 'claim-wins', $claim_row['revision'] ) );
		$this->assertSame( $claim_token, $this->store->load( 1102, 'claim-wins' )['claim_token'] );
	}

	/**
	 * Replacing a stale reservation fences its former token at final commit.
	 */
	public function test_stale_reserved_claim_is_replaced_and_old_token_is_fenced(): void {
		global $wpdb;

		$old_token = Store::new_token();
		$row       = $this->store->first_legacy_claim( 1201, 'stale-claim', $old_token, 'legacy_zip', 'tag:1.0' );
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET claim_changed_at = UTC_TIMESTAMP() - INTERVAL 2 HOUR WHERE plugin_id = %d',
				$this->store->table_name(),
				1201
			)
		);

		$new_token = Store::new_token();
		$this->assertTrue(
			$this->store->replace_stale_reserved(
				1201,
				'stale-claim',
				Store::OWNER_LEGACY,
				$row['revision'],
				$old_token,
				$new_token,
				'legacy_zip',
				'tag:1.0',
				HOUR_IN_SECONDS
			)
		);

		$this->assertFalse(
			$this->store->begin_commit( 1201, 'stale-claim', Store::OWNER_LEGACY, $row['revision'], $old_token )
		);
		$row = $this->store->load( 1201, 'stale-claim' );
		$this->assertSame( $new_token, $row['claim_token'] );
		$this->assertTrue(
			$this->store->begin_commit( 1201, 'stale-claim', Store::OWNER_LEGACY, $row['revision'], $new_token )
		);
	}

	/**
	 * A committing effect cannot be stolen, superseded, or admitted across.
	 */
	public function test_committing_claim_is_nonstealable_and_v2_remains_sticky(): void {
		global $wpdb;

		$token = Store::new_token();
		$row   = $this->store->first_legacy_claim( 1301, 'committing-claim', $token, 'legacy_zip', 'tag:1.0' );
		$this->assertTrue(
			$this->store->begin_commit( 1301, 'committing-claim', Store::OWNER_LEGACY, $row['revision'], $token )
		);
		$row = $this->store->load( 1301, 'committing-claim' );
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET claim_changed_at = UTC_TIMESTAMP() - INTERVAL 2 HOUR WHERE plugin_id = %d',
				$this->store->table_name(),
				1301
			)
		);

		$this->assertFalse(
			$this->store->replace_stale_reserved(
				1301,
				'committing-claim',
				Store::OWNER_LEGACY,
				$row['revision'],
				$token,
				Store::new_token(),
				'legacy_zip',
				'tag:1.1',
				HOUR_IN_SECONDS
			)
		);
		$this->assertFalse( $this->store->admit_v2( 1301, 'committing-claim', $row['revision'] ) );
		$this->assertTrue(
			$this->store->complete_claim( 1301, 'committing-claim', Store::OWNER_LEGACY, $row['revision'], $token )
		);

		$row = $this->store->load( 1301, 'committing-claim' );
		$this->assertTrue( $this->store->admit_v2( 1301, 'committing-claim', $row['revision'] ) );
		$row = $this->store->load( 1301, 'committing-claim' );
		$this->assertSame( Store::OWNER_V2, $row['pipeline_version'] );
		$this->assertFalse(
			$this->store->acquire_claim(
				1301,
				'committing-claim',
				Store::OWNER_LEGACY,
				$row['revision'],
				Store::new_token(),
				'legacy_zip',
				'tag:2.0'
			)
		);
	}

	/**
	 * Standalone sees only the exact current staged projection until revocation.
	 */
	public function test_standalone_projection_is_exact_and_revocable(): void {
		$plugin_id  = 1401;
		$slug       = 'staged-projection';
		$artifact   = '11111111-1111-4111-8111-111111111111';
		$staged     = "{$slug}/{$slug}.gandalf-{$artifact}.zip";
		$zip_sha256 = hash( 'sha256', 'exact staged bytes' );
		$row        = $this->store->first_admit_v2( $plugin_id, $slug );
		$token      = Store::new_token();

		$this->assertTrue(
			$this->store->acquire_claim( $plugin_id, $slug, Store::OWNER_V2, $row['revision'], $token, 'stage', $artifact )
		);
		$row = $this->store->load( $plugin_id, $slug );
		$this->assertTrue( $this->store->begin_commit( $plugin_id, $slug, Store::OWNER_V2, $row['revision'], $token ) );
		$row = $this->store->load( $plugin_id, $slug );
		$this->assertTrue(
			$this->store->complete_with_staged_projection(
				$plugin_id,
				$slug,
				$row['revision'],
				$token,
				$artifact,
				$staged,
				$zip_sha256
			)
		);

		$this->assertSame(
			array(
				'staged_file'       => $staged,
				'staged_zip_sha256' => $zip_sha256,
			),
			$this->store->get_staged_projection( $slug, $artifact )
		);
		$this->assertFalse(
			$this->store->get_staged_projection( $slug, '22222222-2222-4222-8222-222222222222' )
		);

		$row = $this->store->load( $plugin_id, $slug );
		$this->assertTrue( $this->store->clear_staged_projection( $plugin_id, $slug, $row['revision'], $artifact ) );
		$this->assertFalse( $this->store->get_staged_projection( $slug, $artifact ) );
	}

	/**
	 * A staging claim cannot publish a different artifact's projection.
	 */
	public function test_staged_projection_must_match_the_active_claim(): void {
		$plugin_id = 1403;
		$slug      = 'staged-claim-binding';
		$claimed   = '44444444-4444-4444-8444-444444444444';
		$other     = '55555555-5555-4555-8555-555555555555';
		$row       = $this->store->first_admit_v2( $plugin_id, $slug );
		$token     = Store::new_token();

		$this->assertTrue(
			$this->store->acquire_claim( $plugin_id, $slug, Store::OWNER_V2, $row['revision'], $token, 'stage', $claimed )
		);
		$row = $this->store->load( $plugin_id, $slug );
		$this->assertTrue( $this->store->begin_commit( $plugin_id, $slug, Store::OWNER_V2, $row['revision'], $token ) );
		$row = $this->store->load( $plugin_id, $slug );

		$this->expectException( \UnexpectedValueException::class );
		$this->store->complete_with_staged_projection(
			$plugin_id,
			$slug,
			$row['revision'],
			$token,
			$other,
			"{$slug}/{$slug}.gandalf-{$other}.zip",
			hash( 'sha256', 'different staged bytes' )
		);
	}

	/**
	 * The longest accepted slug produces a projection that round-trips exactly.
	 */
	public function test_maximum_length_staged_projection_is_not_truncated(): void {
		$plugin_id  = 1402;
		$slug       = str_repeat( 'a', 200 );
		$artifact   = '33333333-3333-4333-8333-333333333333';
		$staged     = "{$slug}/{$slug}.gandalf-{$artifact}.zip";
		$zip_sha256 = hash( 'sha256', 'maximum-length staged bytes' );
		$row        = $this->store->first_admit_v2( $plugin_id, $slug );
		$token      = Store::new_token();

		$this->assertSame( 450, strlen( $staged ) );
		$this->assertTrue(
			$this->store->acquire_claim( $plugin_id, $slug, Store::OWNER_V2, $row['revision'], $token, 'stage', $artifact )
		);
		$row = $this->store->load( $plugin_id, $slug );
		$this->assertTrue( $this->store->begin_commit( $plugin_id, $slug, Store::OWNER_V2, $row['revision'], $token ) );
		$row = $this->store->load( $plugin_id, $slug );
		$this->assertTrue(
			$this->store->complete_with_staged_projection(
				$plugin_id,
				$slug,
				$row['revision'],
				$token,
				$artifact,
				$staged,
				$zip_sha256
			)
		);

		$this->assertSame(
			array(
				'staged_file'       => $staged,
				'staged_zip_sha256' => $zip_sha256,
			),
			$this->store->get_staged_projection( $slug, $artifact )
		);
	}

	/**
	 * Schema absence fails closed; no Store method creates it at runtime.
	 */
	public function test_missing_schema_fails_closed(): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'DROP TABLE %i', $this->store->table_name() ) );
		try {
			$this->expectException( \RuntimeException::class );
			$this->store->assert_schema();
		} finally {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Store returns identifier-prepared test DDL.
			$wpdb->query( $this->store->schema_sql() );
			$this->store->assert_schema();
		}
	}

	/**
	 * Rollback accepts only inert legacy rows and rejects ownership/effects.
	 */
	public function test_rollback_guard_rejects_claims_and_sticky_v2(): void {
		$this->assertTrue( $this->store->rollback_is_safe() );

		$token = Store::new_token();
		$row   = $this->store->first_legacy_claim( 1501, 'rollback-guard', $token, 'legacy_zip', 'trunk@1.0' );
		$this->assertFalse( $this->store->rollback_is_safe() );
		$this->assertTrue(
			$this->store->begin_commit( 1501, 'rollback-guard', Store::OWNER_LEGACY, $row['revision'], $token )
		);
		$row = $this->store->load( 1501, 'rollback-guard' );
		$this->assertTrue(
			$this->store->complete_claim( 1501, 'rollback-guard', Store::OWNER_LEGACY, $row['revision'], $token )
		);
		$this->assertTrue( $this->store->rollback_is_safe() );

		$row = $this->store->load( 1501, 'rollback-guard' );
		$this->assertTrue( $this->store->admit_v2( 1501, 'rollback-guard', $row['revision'] ) );
		$this->assertFalse( $this->store->rollback_is_safe() );
	}

	/**
	 * Create an inert legacy owner by completing one exact no-op test claim.
	 *
	 * @param int    $plugin_id   Plugin post ID.
	 * @param string $plugin_slug Plugin slug.
	 * @return array Unclaimed legacy owner row.
	 */
	private function unclaimed_legacy_owner( int $plugin_id, string $plugin_slug ): array {
		$token = Store::new_token();
		$row   = $this->store->first_legacy_claim( $plugin_id, $plugin_slug, $token, 'seed', 'test-seed' );
		$this->assertTrue(
			$this->store->begin_commit( $plugin_id, $plugin_slug, Store::OWNER_LEGACY, $row['revision'], $token )
		);
		$row = $this->store->load( $plugin_id, $plugin_slug );
		$this->assertTrue(
			$this->store->complete_claim( $plugin_id, $plugin_slug, Store::OWNER_LEGACY, $row['revision'], $token )
		);
		return $this->store->load( $plugin_id, $plugin_slug );
	}

	/** Return the number of control rows. */
	private function row_count(): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->store->table_name() )
		);
	}
}
