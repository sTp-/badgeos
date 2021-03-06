<?php
/**
 * Activity Triggers, used for triggering achievement earning
 *
 * @package BadgeOS
 * @subpackage Achievements
 * @author Credly, LLC
 * @license http://www.gnu.org/licenses/agpl.txt GNU AGPL v3.0
 * @link https://credly.com
 */

/**
 * Helper function for returning our available actvity triggers
 *
 * @since  1.0.0
 * @return array An array of all our activity triggers stored as 'value' => 'Display Name'
 */
function badgeos_get_activity_triggers() {
	global $badgeos;

	$badgeos->activity_triggers = apply_filters( 'badgeos_activity_triggers',
		array(
			// WordPress-specific
			'wp_login'             => __( 'Log in to Website', 'badgeos' ),
			'comment_post'         => __( 'Comment on a post', 'badgeos' ),
			'badgeos_new_post'     => __( 'Publish a new post', 'badgeos' ),
			'badgeos_new_page'     => __( 'Publish a new page', 'badgeos' ),

			// BadgeOS-specific
			'specific-achievement' => __( 'Specific Achievement of Type', 'badgeos' ),
			'any-achievement'      => __( 'Any Achievement of Type', 'badgeos' ),
			'all-achievements'     => __( 'All Achievements of Type', 'badgeos' ),
		)
	);

	return $badgeos->activity_triggers;
}

/**
 * Load up our activity triggers so we can add actions to them
 *
 * @since 1.0.0
 */
function badgeos_load_activity_triggers() {

	// Grab our activity triggers
	$activity_triggers = badgeos_get_activity_triggers();

	// Loop through each achievement type and add triggers for unlocking them
	foreach ( badgeos_get_achievement_types_slugs() as $achievement_type ) {

		// Grab the post type object, and bail if it's not actually an object
		$post_type_object = get_post_type_object( $achievement_type );
		if ( ! is_object( $post_type_object ) )
			continue;

		// Add trigger for unlocking ANY and ALL posts for each achievement type
		$activity_triggers['badgeos_unlock_'.$achievement_type] = sprintf( __( 'Unlocked a %s', 'badgeos' ), $post_type_object->labels->singular_name );
		$activity_triggers['badgeos_unlock_all_'.$achievement_type] = sprintf( __( 'Unlocked all %s', 'badgeos' ), $post_type_object->labels->name );

	}

	// Loop through each trigger and add our trigger event to the hook
	foreach ( $activity_triggers as $trigger => $label )
		add_action( $trigger, 'badgeos_trigger_event', 10, 10 );

}
add_action( 'init', 'badgeos_load_activity_triggers' );

/**
 * Handle each of our activity triggers
 *
 * @since 1.0.0
 * @param mixed $args Args that are passed through from the hook (only relevant for the wp_login hook presently)
 */
function badgeos_trigger_event( $args ) {

	// Setup all our globals
	global $user_ID, $blog_id, $wpdb;

	// Grab our current trigger
	$this_trigger = current_filter();

	// Special case: when logging in (which is an activity trigger event),
	// global $user_ID is not yet available so it must be gotten from the
	// user login name that IS passed to this function.
	if ( 'wp_login' == $this_trigger ) {
		$user_data = get_user_by( 'login', $args );
		$user_id = $user_data->ID;
	} else {
		$user_data = get_user_by( 'id', $user_ID );
		$user_id = $user_ID;
	}

	// If the user doesn't satisfy the trigger requirements, bail here
	if ( ! apply_filters( 'badgeos_user_deserves_trigger', true, $user_id, $this_trigger ) )
		return $args;

	// Update hook count for this user
	$new_count = badgeos_update_user_trigger_count( $user_id, $this_trigger, $blog_id );

	// Mark the count in the log entry
	badgeos_post_log_entry( null, $user_id, null, sprintf( __( '%1$s triggered %2$s (%3$dx)', 'badgeos' ), $user_data->user_login, $this_trigger, $new_count ) );

	// Now determine if any badges are earned based on this trigger event
	$triggered_achievements = $wpdb->get_results( $wpdb->prepare(
		"
		SELECT post_id
		FROM   $wpdb->postmeta
		WHERE  meta_key = '_badgeos_trigger_type'
		       AND meta_value = %s
		",
		$this_trigger
	) );
	foreach ( $triggered_achievements as $achievement ) {
		badgeos_maybe_award_achievement_to_user( $achievement->post_id, $user_id );
	}

}

/**
 * Wrapper function for returning a user's array of sprung triggers
 *
 * @since  1.0.0
 * @param  integer $user_id The given user's ID
 * @param  integer $site_id The desired Site ID to check
 * @return array            An array of the triggers a user has triggered
 */
function badgeos_get_user_triggers( $user_id = 0, $site_id = 0 ) {

	// Grab all of the user's triggers
	$user_triggers = ( $array_exists = get_user_meta( $user_id, '_badgeos_triggered_triggers', true ) ) ? $array_exists : array( $site_id => array() );

	// Return only the triggers that are relevant to the provided $site_id
	if ( $site_id )
		return $user_triggers[$site_id];

	// Otherwise, return the full array of all triggers across all sites
	else
		return $user_triggers;
}

/**
 * Get the count for the number of times a user has triggered a particular trigger
 *
 * @since  1.0.0
 * @param  integer $user_id The given user's ID
 * @param  string  $trigger The given trigger we're checking
 * @return integer          The total number of times a user has triggered the trigger
 */
function badgeos_get_user_trigger_count( $user_id, $trigger, $site_id = 1 ) {

	// Grab the user's logged triggers
	$user_triggers = badgeos_get_user_triggers( $user_id, $site_id );

	// If we have any triggers, return the current count for the given trigger
	if ( ! empty( $user_triggers ) && isset( $user_triggers[$trigger] ) )
		return absint( $user_triggers[$trigger] );

	// Otherwise, they've never hit the trigger
	else
		return 0;

}

/**
 * Update the user's trigger count for a given trigger by 1
 *
 * @since  1.0.0
 * @param  integer $user_id The given user's ID
 * @param  string  $trigger The trigger we're updating
 * @param  integer $site_id The desired Site ID to update
 * @return integer          The updated trigger count
 */
function badgeos_update_user_trigger_count( $user_id, $trigger, $site_id = 1 ) {

	// Grab the current count and increase it by 1
	$trigger_count = absint( badgeos_get_user_trigger_count( $user_id, $trigger, $site_id ) );
	$trigger_count++;

	// Update the triggers arary with the new count
	$user_triggers = badgeos_get_user_triggers( $user_id );
	$user_triggers[$site_id][$trigger] = $trigger_count;
	update_user_meta( $user_id, '_badgeos_triggered_triggers', $user_triggers );

	// Send back our trigger count for other purposes
	return $trigger_count;

}

/**
 * Reset a user's trigger count for a given trigger to 0 or reset ALL triggers
 *
 * @since  1.0.0
 * @param  integer $user_id The given user's ID
 * @param  string  $trigger The trigger we're updating (or "all" to dump all triggers)
 * @param  integer $site_id The desired Site ID to update (or "all" to dump across all sites)
 * @return integer          The updated trigger count
 */
function badgeos_reset_user_trigger_count( $user_id, $trigger, $site_id = 1 ) {

	// Grab the user's current triggers
	$user_triggers = badgeos_get_user_triggers( $user_id );

	// If we're deleteing all triggers...
	if ( 'all' == $trigger ) {
		// For all sites
		if ( 'all' == $site_id )
			$user_triggers = array();
		// For a specific site
		else
			$user_triggers[$site_id] = array();
	// Otherwise, reset the specific trigger back to zero
	} else {
		$user_triggers[$site_id][$trigger] = 0;
	}

	// Finally, update our user meta
	update_user_meta( $user_id, '_badgeos_triggered_triggers', $user_triggers );

}

/**
 * Listener function for post/page publishing
 *
 * This triggers a separate hook, badgeos_new_{$post_type},
 * only if the published content is brand new
 *
 * @since 1.1.0
 * @param integer $post_id The post ID
 */
function badgeos_publish_listener( $post_id ) {

	// Bail if we're not intentionally saving a post
	if (
		defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE // If we're autosaving,
		|| wp_is_post_revision( $post_id )            // or this is a revision
	)
		return;

	// Bail if we have more than the single, ititial revision
	$revisions = wp_get_post_revisions( $post_id );
	if ( count( $revisions ) > 1 )
		return;

	// Trigger a badgeos_new_{$post_type} action
	do_action( 'badgeos_new_' . get_post_type( $post_id ), $post_id );
}
add_action( 'publish_post', 'badgeos_publish_listener', 0 );
add_action( 'publish_page', 'badgeos_publish_listener', 0 );
