<?php
/**
 * Fires when the D4H Create Activity plugin is uninstalled.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// This addon stores no credentials or lasting options in Core's place.
// Delete any leftover transients if present.
delete_transient( 'd4h_create_activity_last_batch' );
