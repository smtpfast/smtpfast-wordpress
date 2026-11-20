<?php
/**
 * PHPUnit bootstrap for the WordPress test suite.
 *
 * @package SMTPfast
 */

$smtpfast_root = dirname( __DIR__ );

if ( false === getenv( 'WP_PHPUNIT__TESTS_CONFIG' ) ) {
	putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );
}
if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $smtpfast_root . '/vendor/yoast/phpunit-polyfills' );
}

$smtpfast_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $smtpfast_tests_dir ) {
	$smtpfast_tests_dir = $smtpfast_root . '/vendor/wp-phpunit/wp-phpunit';
}

require_once $smtpfast_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $smtpfast_root ) {
		require $smtpfast_root . '/smtpfast.php';
	}
);

require $smtpfast_tests_dir . '/includes/bootstrap.php';
require __DIR__ . '/class-smtpfast-test-case.php';
