<?php
/**
 * Removes the plugin data when the plugin is deleted.
 *
 * @package SMTPfast
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Deletes the options and transients of the current site.
 *
 * @return void
 */
function smtpfast_uninstall_site() {
	foreach ( array( 'smtpfast_settings', 'smtpfast_api_key', 'smtpfast_log', 'smtpfast_last_failure', 'smtpfast_verified_domains' ) as $smtpfast_option ) {
		delete_option( $smtpfast_option );
	}
	delete_transient( 'smtpfast_domains' );
	delete_transient( 'smtpfast_account' );
}

if ( is_multisite() ) {
	$smtpfast_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $smtpfast_site_ids as $smtpfast_site_id ) {
		switch_to_blog( $smtpfast_site_id );
		smtpfast_uninstall_site();
		restore_current_blog();
	}
} else {
	smtpfast_uninstall_site();
}
