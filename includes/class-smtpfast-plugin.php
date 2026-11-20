<?php
/**
 * Plugin bootstrap.
 *
 * @package SMTPfast
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the mailer and the admin screens into WordPress.
 */
final class SMTPfast_Plugin {

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'pre_wp_mail', array( 'SMTPfast_Mailer', 'pre_wp_mail' ), 10, 2 );
		add_action( 'wp_mail_failed', array( 'SMTPfast_Mailer', 'fallback_failed' ), 1 );
		add_action( 'wp_mail_succeeded', array( 'SMTPfast_Mailer', 'fallback_succeeded' ), 1 );

		if ( is_admin() ) {
			SMTPfast_Admin::init();
		}
	}
}
