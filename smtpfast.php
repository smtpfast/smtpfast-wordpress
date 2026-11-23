<?php
/**
 * Plugin Name:       SMTPfast
 * Plugin URI:        https://github.com/smtpfast/smtpfast-wordpress
 * Description:       Send every WordPress email through the SMTPfast email API, with domain status, a test email and a send log.
 * Version:           1.0.1
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            SMTPfast
 * Author URI:        https://smtpfa.st
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       smtpfast
 *
 * @package SMTPfast
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SMTPFAST_VERSION', '1.0.1' );
define( 'SMTPFAST_PLUGIN_FILE', __FILE__ );
define( 'SMTPFAST_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once SMTPFAST_PLUGIN_DIR . 'includes/class-smtpfast-settings.php';
require_once SMTPFAST_PLUGIN_DIR . 'includes/class-smtpfast-api.php';
require_once SMTPFAST_PLUGIN_DIR . 'includes/class-smtpfast-log.php';
require_once SMTPFAST_PLUGIN_DIR . 'includes/class-smtpfast-mailer.php';
require_once SMTPFAST_PLUGIN_DIR . 'includes/class-smtpfast-admin.php';
require_once SMTPFAST_PLUGIN_DIR . 'includes/class-smtpfast-plugin.php';

SMTPfast_Plugin::init();
