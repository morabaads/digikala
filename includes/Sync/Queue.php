<?php
/**
 * Background job queue (Action Scheduler with WP-Cron fallback).
 *
 * @package DKBridge
 */

namespace DKBridge\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Queue facade.
 */
class Queue {

	const GROUP = 'dk-bridge';

	/**
	 * Recurring jobs: hook => interval seconds.
	 */
	const RECURRING = array(
		'dkb_flush_stock'     => 300,
		'dkb_flush_prices'    => 900,
		'dkb_poll_moderation' => 1800,
		'dkb_pull_orders'     => 300,
		'dkb_check_batches'   => 600,
		'dkb_reconcile'       => DAY_IN_SECONDS,
		'dkb_cleanup'         => DAY_IN_SECONDS,
	);

	/**
	 * Whether Action Scheduler is available.
	 *
	 * @return bool
	 */
	public static function has_action_scheduler() {
		return function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_schedule_recurring_action' );
	}

	/**
	 * Enqueue a single job.
	 *
	 * @param string $hook  Hook.
	 * @param array  $args  Args.
	 * @param int    $delay Seconds.
	 */
	public static function push( $hook, array $args = array(), $delay = 0 ) {
		if ( self::has_action_scheduler() ) {
			if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( $hook, $args, self::GROUP ) ) {
				return;
			}
			if ( $delay > 0 ) {
				as_schedule_single_action( time() + $delay, $hook, $args, self::GROUP );
			} else {
				as_enqueue_async_action( $hook, $args, self::GROUP );
			}
			return;
		}
		if ( ! wp_next_scheduled( $hook, $args ) ) {
			wp_schedule_single_event( time() + max( 1, $delay ), $hook, $args );
		}
	}

	/**
	 * Register custom cron interval (fallback mode).
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public static function cron_schedules( $schedules ) {
		foreach ( array( 300, 600, 900, 1800 ) as $seconds ) {
			$schedules[ 'dkb_' . $seconds ] = array(
				'interval' => $seconds,
				/* translators: %d: minutes */
				'display'  => sprintf( __( 'هر %d دقیقه (DK Bridge)', 'dk-bridge' ), $seconds / 60 ),
			);
		}
		return $schedules;
	}

	/**
	 * Ensure recurring jobs are scheduled.
	 */
	public static function ensure_recurring() {
		if ( get_transient( 'dkb_recurring_checked' ) ) {
			return;
		}
		set_transient( 'dkb_recurring_checked', 1, HOUR_IN_SECONDS );
		foreach ( self::RECURRING as $hook => $interval ) {
			if ( self::has_action_scheduler() ) {
				if ( ! as_has_scheduled_action( $hook, array(), self::GROUP ) ) {
					as_schedule_recurring_action( time() + 60, $interval, $hook, array(), self::GROUP );
				}
			} elseif ( ! wp_next_scheduled( $hook ) ) {
				$recurrence = DAY_IN_SECONDS === $interval ? 'daily' : 'dkb_' . $interval;
				wp_schedule_event( time() + 60, $recurrence, $hook );
			}
		}
	}

	/**
	 * Remove all scheduled jobs.
	 */
	public static function unschedule_all() {
		$hooks = array_merge( array_keys( self::RECURRING ), array( 'dkb_publish_item', 'dkb_create_variants' ) );
		foreach ( $hooks as $hook ) {
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( $hook, array(), self::GROUP );
			}
			wp_clear_scheduled_hook( $hook );
		}
		delete_transient( 'dkb_recurring_checked' );
	}

	/**
	 * Simple mutex using an option row (atomic add_option).
	 *
	 * @param string $name Lock name.
	 * @param int    $ttl  Seconds.
	 * @return bool Acquired.
	 */
	public static function lock( $name, $ttl = 300 ) {
		$key = 'dkb_lock_' . $name;
		if ( add_option( $key, time() + $ttl, '', 'no' ) ) {
			return true;
		}
		$until = (int) get_option( $key );
		if ( $until < time() ) {
			update_option( $key, time() + $ttl, false );
			return true;
		}
		return false;
	}

	/**
	 * Release a lock.
	 *
	 * @param string $name Lock name.
	 */
	public static function unlock( $name ) {
		delete_option( 'dkb_lock_' . $name );
	}
}
