<?php
namespace WordPressdotorg\Plugin_Directory\Jobs;

use WordPressdotorg\Plugin_Directory\Plugin_Directory;
use WordPressdotorg\Plugin_Directory\Standalone\Plugins_Info_API;
use WordPressdotorg\Plugin_Directory\Template;
use WordPressdotorg\Plugin_Directory\Tools;

/**
 * Handles interfacing with the api.WordPress.org/plugin/update-check/ API.
 *
 * @package WordPressdotorg\Plugin_Directory\Jobs
 */
class API_Update_Updater {

	/**
	 * The cron job to ensure all plugins in the `update_source` table are up-to-date.
	 * This cron is a backup in the event that the import doesn't trigger it correctly.
	 */
	public static function cron_trigger() {
		global $wpdb;

		// Note: `left( pm.meta_value, 128 )` is due to the short `version` field length and some plugins with absurdly long version strings.
		$out_of_date_plugins = $wpdb->get_col(
			"SELECT p.post_name
			FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->prefix}update_source u ON p.ID = u.plugin_id
				LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'version'
				LEFT JOIN {$wpdb->postmeta} pm_stable ON p.ID = pm_stable.post_id AND pm_stable.meta_key = 'stable_tag'
				LEFT JOIN {$wpdb->postmeta} pm_closed ON p.ID = pm_closed.post_id AND pm_closed.meta_key = 'plugin_closed_date'
			WHERE
				p.post_type = 'plugin'
				AND (
					p.post_status IN( 'publish', 'disabled', 'closed' ) OR
					u.plugin_id IS NOT NULL
				)
				AND (
					u.plugin_id IS NULL OR
					u.last_updated != p.post_modified OR
					( u.version != pm.meta_value AND u.version != left( pm.meta_value, 128 ) ) OR
					( u.stable_tag != pm_stable.meta_value AND u.stable_tag != left( pm_stable.meta_value, 128 ) ) OR
					( u.available = 1 AND p.post_status NOT IN( 'publish', 'disabled' ) ) OR
					( u.available = 0 AND p.post_status IN( 'publish', 'disabled' ) ) OR
					(
						pm_closed.meta_value IS NOT NULL AND (
							u.meta NOT LIKE '%closed_at%' OR
							(
								u.meta NOT LIKE '%closed_reason%' AND
								DATE_ADD( pm_closed.meta_value, INTERVAL 60 DAY ) <= NOW()
							)
						)
					)
				)"
		);

		if ( ! $out_of_date_plugins ) {
			return;
		}

		foreach ( $out_of_date_plugins as $plugin_slug ) {
			if ( ! self::update_single_plugin( $plugin_slug ) ) {
				// If the update failed, but yet we know the DB data differs, clear cached data and try again.
				$post = Plugin_Directory::get_plugin_post( $plugin_slug );
				clean_post_cache( $post->ID );
				self::update_single_plugin( $plugin_slug );
			}
		}
	}

	/**
	 * Updates a single plugins `update_source` data.
	 *
	 * @param string $plugin_slug The plugin slug.
	 * @return bool
	 */
	public static function update_single_plugin( $plugin_slug ) {
		global $wpdb;
		$post = Plugin_Directory::get_plugin_post( $plugin_slug );

		if ( ! $post || ! in_array( $post->post_status, array( 'publish', 'disabled', 'closed' ) ) ) {
			$wpdb->delete( $wpdb->prefix . 'update_source', compact( 'plugin_slug' ) );
			wp_clear_scheduled_hook( "release_to_update_api:{$plugin_slug}" );
			return true;
		}

		/*
		 * Plugin meta selects the current candidate; `update_source` records the
		 * update API identity. Keep both halves of each identity together.
		 */
		$version          = get_post_meta( $post->ID, 'version', true );
		$stable_tag       = get_post_meta( $post->ID, 'stable_tag', true );
		$requires_plugins = get_post_meta( $post->ID, 'requires_plugins', true );
		$release          = self::get_release_by_identity( $post, $version, $stable_tag );
		if ( false === $release ) {
			return false;
		}

		$release_time     = self::compute_release_time( $post, $release );
		$existing_row     = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT version, stable_tag, meta FROM {$wpdb->prefix}update_source WHERE plugin_slug = %s",
				$post->post_name
			)
		);
		$existing_version = '';
		$existing_tag     = '';
		if ( null !== $existing_row ) {
			$existing_version = $existing_row->version;
			$existing_tag     = $existing_row->stable_tag;
		}

		$release_delay = self::get_release_delay( $release );

		/*
		 * A stable-tag change is a new release even when Version is reused, so both
		 * fields participate in cooldown and blocking. Both columns are varchar(128);
		 * mirror cron_trigger()'s truncation allowance.
		 */
		$is_new_release =
			substr( $version, 0, 128 ) !== $existing_version ||
			substr( $stable_tag, 0, 128 ) !== $existing_tag;

		/*
		 * Keep a burned candidate out of `update_source`, retaining any previous release
		 * identity. Clear the now-pointless delayed event; the persistent
		 * block keeps the ref out after cooldown. Availability still follows plugin
		 * status without changing that identity.
		 */
		if ( self::is_release_blocked( $release ) && $is_new_release ) {
			wp_clear_scheduled_hook( "release_to_update_api:{$post->post_name}" );

			if ( null !== $existing_row ) {
				self::update_row_availability( $post, $existing_row->meta );
			}

			return true;
		}

		/*
		 * Defer the write for new releases still inside the cooldown window. While
		 * deferred, any existing `update_source` row retains the previously recorded
		 * release. Reviewers force-release by setting `release_delay = 0` on the release
		 * meta.
		 *
		 * The event is scheduled for $cooldown_until. Whenever WP-Cron runs it, this
		 * time gate is false, so no explicit bypass is needed.
		 *
		 * Only the release change waits for the cooldown: a status change made
		 * mid-cooldown (a closure, a reopen) reaches the existing row right away,
		 * while retaining the previous release's data. Until the cooldown
		 * expires, cron_trigger() keeps re-selecting the plugin and this write
		 * repeats as a no-op.
		 */
		if ( 0 < $release_delay && $is_new_release ) {
			$cooldown_until = $release_time + $release_delay;
			if ( $cooldown_until > time() ) {
				self::queue_release_to_update_api( $post->post_name, $cooldown_until );

				if ( null !== $existing_row ) {
					self::update_row_availability( $post, $existing_row->meta );
				}

				return true;
			}
		}

		// On the first write after cooldown, record when this release reached `update_source`.
		if ( 0 < $release_delay && $is_new_release ) {
			$release_time = time();
		}

		$meta = array(
			'release_time'    => $release_time,
			'last_version'    => $post->last_version ?? '',
			'last_stable_tag' => $post->last_stable_tag ?? '',
		);

		$meta = array_merge( $meta, self::get_close_meta( $post ) );

		// Add phased rollout strategy data if needed.
		if ( $release && ! empty( $release['rollout_strategy'] ) ) {
			$meta['rollout'] = array(
				'strategy' => $release['rollout_strategy'],
			);
		}

		// The deferred event (if any) has either fired or been pre-empted by a force-release
		// or status change. Clear any leftover schedule so the cron table doesn't grow.
		wp_clear_scheduled_hook( "release_to_update_api:{$post->post_name}" );

		$data = array(
			'plugin_id'        => $post->ID,
			'plugin_slug'      => $post->post_name,
			'available'        => (int) self::is_available( $post ),
			'version'          => $version,
			'stable_tag'       => $stable_tag,
			'plugin_name'      => strip_tags( get_post_meta( $post->ID, 'header_name', true ) ),
			'plugin_name_san'  => sanitize_title_with_dashes( strip_tags( get_post_meta( $post->ID, 'header_name', true ) ) ),
			'plugin_author'    => strip_tags( get_post_meta( $post->ID, 'header_author', true ) ),
			'tested'           => get_post_meta( $post->ID, 'tested', true ),
			'requires'         => get_post_meta( $post->ID, 'requires', true ),
			'requires_php'     => get_post_meta( $post->ID, 'requires_php', true ),
			'requires_plugins' => $requires_plugins ? serialize( $requires_plugins ) : '',
			'upgrade_notice'   => get_post_meta( $post->ID, 'upgrade_notice', true )[ $version ] ?? '',
			'assets'           => serialize( self::get_plugin_assets( $post ) ),
			'meta'             => $meta ? serialize( $meta ) : '',
			'last_updated'     => $post->post_modified,
		);

		if (
			! $wpdb->update( $wpdb->prefix . 'update_source', $data, array( 'plugin_slug' => $post->post_name ) ) &&
			! $wpdb->get_var( $wpdb->prepare( "SELECT `plugin_slug` FROM `{$wpdb->prefix}update_source` WHERE `plugin_slug` = %s", $post->post_name ) )
		) {
			if ( ! $wpdb->insert( $wpdb->prefix . 'update_source', $data ) ) {
				return false;
			}
		}

		self::clear_plugin_caches( $plugin_slug );

		// Sync the latest version to Stats.
		if ( function_exists( '\WordPressdotorg\Stats\sync_latest_version' ) ) {
			\WordPressdotorg\Stats\sync_latest_version(
				'plugin',
				array(
					$plugin_slug => $version,
				)
			);
		}

		return true;
	}

	/**
	 * Read the release identity recorded in `update_source`.
	 *
	 * Plugin meta may already describe a candidate held by a block or cooldown;
	 * `update_source` remains on the prior release. Read both columns together so
	 * delayed scans receive one authoritative identity for baseline selection.
	 *
	 * @param string $plugin_slug The plugin slug.
	 * @return array|false The recorded Version and stable tag, or false when absent.
	 */
	public static function get_served_release_identity( $plugin_slug ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT version, stable_tag FROM {$wpdb->prefix}update_source WHERE plugin_slug = %s",
				$plugin_slug
			),
			ARRAY_A
		);

		if ( null === $row ) {
			return false;
		}

		return array(
			'version'    => $row['version'],
			'stable_tag' => $row['stable_tag'],
		);
	}

	/**
	 * Whether a release ref is burned by a block.
	 *
	 * Blocks are recorded on the release meta as `release_block`, and cleared by
	 * Plugin_Directory::add_release() with `unblock => true`. A newly selected
	 * burned ref is held out of `update_source`; one already recorded there is not
	 * removed, but the block prevents that ref from being served after supersession.
	 *
	 * @param array|bool $release The release row from Plugin_Directory::get_release(), or false.
	 * @return bool True when the release ref is burned.
	 */
	public static function is_release_blocked( $release ) {
		return is_array( $release ) && ! empty( $release['release_block'] );
	}

	/**
	 * Resolve the exact Version/ref pair used for publication or baseline selection.
	 *
	 * The ref resolver below deliberately ignores a tagged release's mutable Version
	 * header when burning it. This wrapper adds the Version equality required when
	 * the pair itself identifies a publication candidate or historical baseline.
	 *
	 * @param \WP_Post $post        The plugin post.
	 * @param string   $version     The Version half of the identity.
	 * @param string   $release_ref The stable tag, or `trunk`.
	 * @return array|false The release row, or false when it doesn't exist.
	 * @throws \UnexpectedValueException When the stored release identity is malformed.
	 */
	public static function get_release_by_identity( $post, $version, $release_ref ) {
		$release = self::get_release_by_ref( $post, $release_ref, $version );
		if ( false === $release ) {
			return false;
		}
		if ( ! array_key_exists( 'version', $release ) || ! is_string( $release['version'] ) ) {
			throw new \UnexpectedValueException( 'A plugin release has no valid Version identity.' );
		}
		if ( $release['version'] !== $version ) {
			return false;
		}

		return $release;
	}

	/**
	 * Resolve the release record that owns an exact stored ref.
	 *
	 * Tagged releases are identified by tag, not by their mutable Version header,
	 * so changing that header cannot escape a block attached to the tag. Trunk is
	 * reusable and is therefore stored as `trunk@{$version}`. `get_release()` may
	 * fall back from a missing tag to same-Version trunk; exact tag equality below
	 * rejects that fallback so the wrong ref is never blocked or force-released.
	 *
	 * @param \WP_Post $post        The plugin post.
	 * @param string   $release_ref The stable tag, or `trunk`.
	 * @param string   $version     The plugin Version header for a trunk release.
	 * @return array|false The release row, or false when it doesn't exist.
	 * @throws \UnexpectedValueException When the stored release identity is malformed.
	 */
	protected static function get_release_by_ref( $post, $release_ref, $version ) {
		$release_tag = $release_ref;
		if ( 'trunk' === $release_ref ) {
			$release_tag = "trunk@{$version}";
		}

		$release = Plugin_Directory::get_release( $post, $release_tag );

		if ( false === $release ) {
			return false;
		}
		if ( ! array_key_exists( 'tag', $release ) || ! is_string( $release['tag'] ) ) {
			throw new \UnexpectedValueException( 'A plugin release has no valid ref identity.' );
		}

		// get_release() may fall back from a missing tag to a same-version trunk release.
		if ( $release['tag'] !== $release_tag ) {
			return false;
		}

		return $release;
	}

	/**
	 * Burn the exact scanned release ref until it is explicitly force-released.
	 *
	 * A callback may arrive after a later candidate is imported, so the scanned
	 * identity is supplied instead of reconstructed from current plugin meta. A
	 * later ref remains eligible. A ref already recorded in `update_source` is not
	 * removed, but burning it prevents that ref from being served after supersession.
	 *
	 * Tagged refs remain burned even if their Version header changes. Trunk is
	 * version-qualified because the same `trunk` ref is reused for later releases.
	 * Capability checks and audit logging are the caller's.
	 *
	 * @param string $plugin_slug The plugin slug.
	 * @param string $version     The scanned plugin Version header.
	 * @param string $release_ref The scanned stable tag, or `trunk`.
	 * @param array  $block       The block to record; 'blocked_at' is added here.
	 * @return bool Whether the block exists and any required update API sync succeeded.
	 */
	public static function block_release( $plugin_slug, $version, $release_ref, array $block ) {
		$post = Plugin_Directory::get_plugin_post( $plugin_slug );
		if ( ! $post ) {
			return false;
		}

		$release = self::get_release_by_ref( $post, $release_ref, $version );
		if ( false === $release ) {
			return false;
		}

		/*
		 * Preserve the first block's evidence. An already-blocked current ref still
		 * reaches synchronization below in case an earlier attempt recorded the block
		 * but failed before reconciling `update_source`.
		 */
		if ( ! self::is_release_blocked( $release ) ) {
			$block['blocked_at'] = time();

			$recorded = Plugin_Directory::add_release(
				$post,
				array(
					'tag'           => $release['tag'],
					'release_block' => $block,
				)
			);
			if ( ! $recorded ) {
				return false;
			}
		}

		$current_version = get_post_meta( $post->ID, 'version', true );
		$current_ref     = get_post_meta( $post->ID, 'stable_tag', true );
		$current_tag     = 'trunk' === $current_ref ? "trunk@{$current_version}" : $current_ref;

		if ( $current_tag === $release['tag'] ) {
			// Only a current ref can alter `update_source`; superseded blocks prevent later reuse.
			return self::update_single_plugin( $plugin_slug );
		}

		return true;
	}

	/**
	 * Sync the status-dependent `update_source` fields for a plugin whose
	 * release change is deferred by a release cooldown.
	 *
	 * The row retains the previous release's data; only its availability
	 * and closure meta follow the plugin's current status. `version` and
	 * `last_updated` are deliberately left untouched: the stale freshness
	 * marker keeps the plugin matching cron_trigger()'s out-of-date query, so
	 * the backup recovery path survives even when the version clauses are
	 * blinded by their 128-character truncation allowance.
	 *
	 * @param \WP_Post    $post     The plugin post.
	 * @param string|null $row_meta The row's current `meta` column value.
	 * @return bool Whether the row changed.
	 */
	protected static function update_row_availability( $post, $row_meta ) {
		global $wpdb;

		$meta = maybe_unserialize( $row_meta );
		$meta = is_array( $meta ) ? $meta : array();
		unset( $meta['closed_at'], $meta['closed_reason'] );
		$meta = array_merge( $meta, self::get_close_meta( $post ) );

		$updated = $wpdb->update(
			$wpdb->prefix . 'update_source',
			array(
				'available' => (int) self::is_available( $post ),
				// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Matches the update_source meta format.
				'meta'      => $meta ? serialize( $meta ) : '',
			),
			array( 'plugin_slug' => $post->post_name )
		);

		if ( $updated ) {
			self::clear_plugin_caches( $post->post_name );
		}

		return (bool) $updated;
	}

	/**
	 * Whether a plugin's `update_source` row should be marked available.
	 *
	 * @param \WP_Post $post The plugin post.
	 * @return bool
	 */
	protected static function is_available( $post ) {
		return in_array( $post->post_status, array( 'publish', 'disabled' ), true );
	}

	/**
	 * Return the closure fields for a plugin's `update_source` meta.
	 *
	 * @param \WP_Post $post The plugin post.
	 * @return array Empty for plugins that are not disabled or closed.
	 */
	protected static function get_close_meta( $post ) {
		$meta = array();

		if ( in_array( $post->post_status, array( 'disabled', 'closed' ), true ) ) {
			$closed_data = Template::get_close_data( $post );
			if ( $closed_data ) {
				// Close date is sometimes unknown, only include the Day of closure.
				$meta['closed_at'] = $closed_data['date'] ? gmdate( 'Y-m-d', strtotime( $closed_data['date'] ) ) : false;
				if ( $closed_data['public'] ) {
					$meta['closed_reason'] = $closed_data['reason'] ? $closed_data['reason'] : 'unknown';
				}
			}
		}

		return $meta;
	}

	/**
	 * Clear the update-check and plugin information caches for a plugin.
	 *
	 * @param string $plugin_slug The plugin slug.
	 */
	protected static function clear_plugin_caches( $plugin_slug ) {
		// ~34char prefix, Memcache limit of 255char per key.
		$plugin_details_cache_key = 'plugin_details:' . ( strlen( $plugin_slug ) > 200 ? 'md5:' . md5( $plugin_slug ) : $plugin_slug );
		wp_cache_delete( $plugin_details_cache_key, 'update-check-3' );

		// Clear plugin info caches also.
		Plugins_Info_API::flush_plugin_information_cache( $plugin_slug );
	}

	/**
	 * Determine the timestamp for the selected release.
	 *
	 * Uses the stable release activation time, or the plugin modification time
	 * when that is absent. A later required confirmation moves the timestamp
	 * forward because the release is not ready before both events have happened.
	 *
	 * @param \WP_Post   $post    The plugin post.
	 * @param array|bool $release The release row from Plugin_Directory::get_release(), or false.
	 * @return int Unix timestamp.
	 * @throws \UnexpectedValueException When activation or confirmation state is malformed.
	 */
	public static function compute_release_time( $post, $release ) {
		$version_date = $post->version_date;
		if ( '' === $version_date ) {
			$version_date = $post->post_modified;
		} elseif ( ! is_string( $version_date ) ) {
			throw new \UnexpectedValueException( 'A plugin release has an invalid version date.' );
		}

		$release_time = strtotime( $version_date );
		if ( false === $release_time ) {
			throw new \UnexpectedValueException( 'A plugin release has no valid activation time.' );
		}

		if ( false === $release ) {
			return $release_time;
		}

		if (
			! is_array( $release ) ||
			! array_key_exists( 'confirmations_required', $release ) ||
			! is_int( $release['confirmations_required'] ) ||
			$release['confirmations_required'] < 0 ||
			! array_key_exists( 'confirmations', $release ) ||
			! is_array( $release['confirmations'] )
		) {
			throw new \UnexpectedValueException( 'A plugin release has invalid confirmation state.' );
		}

		if ( 0 === $release['confirmations_required'] || [] === $release['confirmations'] ) {
			return $release_time;
		}

		foreach ( $release['confirmations'] as $confirmation_time ) {
			if ( ! is_int( $confirmation_time ) || $confirmation_time < 0 ) {
				throw new \UnexpectedValueException( 'A plugin release has an invalid confirmation time.' );
			}
		}

		return max( $release_time, max( $release['confirmations'] ) );
	}

	/**
	 * Return a release's required cooldown delay.
	 *
	 * @param array $release The release row.
	 * @return int Cooldown delay in seconds.
	 * @throws \UnexpectedValueException When release cooldown state is missing or malformed.
	 */
	protected static function get_release_delay( $release ) {
		if (
			! is_array( $release ) ||
			! array_key_exists( 'release_delay', $release ) ||
			! is_int( $release['release_delay'] ) ||
			$release['release_delay'] < 0
		) {
			throw new \UnexpectedValueException( 'A plugin release has no valid release delay.' );
		}

		return $release['release_delay'];
	}

	/**
	 * Schedule a deferred release-to-update-api cron event for a plugin, replacing
	 * any prior event so only the current candidate is reconsidered at its deadline.
	 *
	 * @param string $plugin_slug    The plugin slug.
	 * @param int    $cooldown_until Unix timestamp when the deferred event should fire.
	 */
	public static function queue_release_to_update_api( $plugin_slug, $cooldown_until ) {
		wp_clear_scheduled_hook( "release_to_update_api:{$plugin_slug}" );
		wp_schedule_single_event( $cooldown_until, "release_to_update_api:{$plugin_slug}" );
	}

	/**
	 * Cron handler for `release_to_update_api:{slug}`. Re-evaluates current plugin
	 * state when the cooldown is due. The slug is recovered from the dynamic hook
	 * name so stale candidate arguments do not flow through cron.
	 */
	public static function cron_trigger_release() {
		list( , $plugin_slug ) = explode( ':', current_filter(), 2 );
		self::update_single_plugin( $plugin_slug );
	}

	/**
	 * Reviewer force-release: lift the block and cooldown on the plugin's current
	 * exact release identity, then reconcile it with `update_source`. This does not
	 * target a superseded historical ref. Logs the action with the supplied reason.
	 *
	 * Capability checks must be performed by the caller.
	 *
	 * @param string   $plugin_slug The plugin slug.
	 * @param string   $reason      Free-text reason recorded in the audit log.
	 * @param \WP_User $user        The acting user. Defaults to the current user.
	 * @return bool True on success.
	 */
	public static function force_release( $plugin_slug, $reason, $user = null ) {
		if ( ! $user ) {
			$user = wp_get_current_user();
		}

		$post = Plugin_Directory::get_plugin_post( $plugin_slug );
		if ( ! $post ) {
			return false;
		}

		$version    = get_post_meta( $post->ID, 'version', true );
		$stable_tag = get_post_meta( $post->ID, 'stable_tag', true );
		$release    = self::get_release_by_identity( $post, $version, $stable_tag );

		if ( false === $release ) {
			return false;
		}

		// Log only what is actually lifted: a deleted block's only trace, and the cooldown only while it still runs.
		$lifted = array();

		if ( self::is_release_blocked( $release ) ) {
			$lifted[] = 'lifting the release block';
		}

		$release_delay = self::get_release_delay( $release );
		if ( 0 < $release_delay && self::compute_release_time( $post, $release ) + $release_delay > time() ) {
			$lifted[] = sprintf( 'bypassing the %d-hour release cooldown', $release_delay / HOUR_IN_SECONDS );
		}

		Tools::audit_log(
			sprintf(
				'Force-released version %s%s. Reason: %s',
				$version,
				$lifted ? ', ' . implode( ' and ', $lifted ) : '',
				$reason
			),
			$post
		);

		Plugin_Directory::add_release(
			$post,
			array(
				'tag'           => $release['tag'],
				'release_delay' => 0,
				'unblock'       => true,
			)
		);

		return self::update_single_plugin( $plugin_slug );
	}

	static function get_plugin_assets( $post ) {
		$icons = $banners = $banners_rtl = array();

		$raw_icons   = Template::get_plugin_icon( $post, 'raw' );
		$raw_banners = Template::get_plugin_banner( $post, 'raw_with_rtl' );

		// Banners
		if ( !empty( $raw_banners['banner_2x'] ) ) {
			$banners['2x'] = $raw_banners['banner_2x'];
		}
		if ( !empty( $raw_banners['banner'] ) ) {
			$banners['1x'] = $raw_banners['banner'];
		}

		// RTL Banners (get_plugin_banner 'raw_with_rtl' returns these)
		if ( !empty( $raw_banners['banner_2x_rtl'] ) ) {
			$banners_rtl['2x'] = $raw_banners['banner_2x_rtl'];
		}
		if ( !empty( $raw_banners['banner_rtl'] ) ) {
			$banners_rtl['1x'] = $raw_banners['banner_rtl'];
		}

		// Icons.
		if ( !empty( $raw_icons['icon_2x'] ) ) {
			$icons['2x'] = $raw_icons['icon_2x'];
		}
		if ( !empty( $raw_icons['icon'] ) ) {
			$icons['1x'] = $raw_icons['icon'];
		}
		if ( !empty( $raw_icons['svg'] ) ) {
			$icons['svg'] = $raw_icons['svg'];
		}
		if ( !empty( $raw_icons['generated'] ) ) {
			// Geopattern SVG will be in 'icon':
			$icons['default'] = $raw_icons['icon'];

			// Don't set the `1x` field when it's a geopattern icon
			unset( $icons['1x'] );
		}

		return (object) compact( 'icons', 'banners', 'banners_rtl' );
	}

}

/*
CREATE TABLE `{$prefix}_update_source` (
  `plugin_id` bigint(20) unsigned NOT NULL,
  `plugin_slug` varchar(255) NOT NULL DEFAULT '',
  `available` tinyint(4) NOT NULL,
  `version` varchar(128) NOT NULL DEFAULT '0.0',
  `stable_tag` varchar(128) NOT NULL DEFAULT 'trunk',
  `plugin_name` varchar(255) NOT NULL DEFAULT '',
  `plugin_name_san` varchar(255) NOT NULL DEFAULT '',
  `plugin_author` varchar(255) NOT NULL DEFAULT '',
  `tested` varchar(128) NOT NULL DEFAULT '',
  `requires` varchar(128) NOT NULL DEFAULT '',
  `requires_php` varchar(128) NOT NULL DEFAULT '',
  `requires_plugins` text NOT NULL DEFAULT '',
  `upgrade_notice` text,
  `assets` text DEFAULT NULL,
  `meta` text DEFAULT NULL,
  `last_updated` datetime NOT NULL,
  PRIMARY KEY (`plugin_id`),
  UNIQUE KEY `plugin_slug` (`plugin_slug`),
  KEY `plugin_name` (`plugin_name`),
  KEY `plugin_name_san` (`plugin_name_san`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1;
*/
