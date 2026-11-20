<?php
/**
 * Local send log and the failure notice state.
 *
 * @package SMTPfast
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps the last 100 sends. Stores no addresses, subjects or bodies: only the time,
 * the SMTPfast email id, the result, the number of recipients and the error text with
 * any email address replaced by [address].
 */
final class SMTPfast_Log {

	const OPTION         = 'smtpfast_log';
	const FAILURE_OPTION = 'smtpfast_last_failure';
	const MAX_ENTRIES    = 100;

	/**
	 * Adds one entry, newest first.
	 *
	 * @param string $status     sent, failed or fallback.
	 * @param string $email_id   SMTPfast email id, or an empty string.
	 * @param int    $recipients Number of To, Cc and Bcc addresses.
	 * @param string $error      Error message, or an empty string.
	 * @return void
	 */
	public static function add( $status, $email_id, $recipients, $error = '' ) {
		$entries = self::all();
		array_unshift(
			$entries,
			array(
				'time'       => time(),
				'id'         => (string) $email_id,
				'status'     => (string) $status,
				'recipients' => (int) $recipients,
				'error'      => self::redact( $error ),
			)
		);
		update_option( self::OPTION, array_slice( $entries, 0, self::MAX_ENTRIES ), false );
	}

	/**
	 * All entries, newest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all() {
		$entries = get_option( self::OPTION, array() );
		return is_array( $entries ) ? array_values( $entries ) : array();
	}

	/**
	 * Removes every entry.
	 *
	 * @return void
	 */
	public static function clear() {
		delete_option( self::OPTION );
	}

	/**
	 * Records a failure for the admin notice.
	 *
	 * @param string $message Error message.
	 * @param bool   $fell_back Whether WordPress then sent the email the default way.
	 * @return void
	 */
	public static function record_failure( $message, $fell_back ) {
		$previous = get_option( self::FAILURE_OPTION, array() );
		$count    = is_array( $previous ) && isset( $previous['count'] ) ? (int) $previous['count'] : 0;
		update_option(
			self::FAILURE_OPTION,
			array(
				'time'      => time(),
				'message'   => self::redact( $message ),
				'count'     => $count + 1,
				'fell_back' => (bool) $fell_back,
			),
			false
		);
	}

	/**
	 * After a fallback, marks the newest entry and the notice as failed because the default mailer failed too.
	 *
	 * @return void
	 */
	public static function mark_fallback_failed() {
		$entries = self::all();
		if ( isset( $entries[0]['status'] ) && 'fallback' === $entries[0]['status'] ) {
			$entries[0]['status'] = 'failed';
			update_option( self::OPTION, $entries, false );
		}
		$failure = self::last_failure();
		if ( null !== $failure ) {
			$failure['fell_back'] = false;
			update_option( self::FAILURE_OPTION, $failure, false );
		}
	}

	/**
	 * Removes email addresses from an error message and shortens it.
	 *
	 * @param string $message Message.
	 * @return string
	 */
	public static function redact( $message ) {
		$message = (string) preg_replace( '/[^\s<>"\'(),;:]+@[^\s<>"\'(),;:]+/', '[address]', (string) $message );
		return substr( $message, 0, 500 );
	}

	/**
	 * The last failure, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function last_failure() {
		$failure = get_option( self::FAILURE_OPTION, null );
		return is_array( $failure ) ? $failure : null;
	}

	/**
	 * Dismisses the failure notice.
	 *
	 * @return void
	 */
	public static function clear_failure() {
		delete_option( self::FAILURE_OPTION );
	}
}
