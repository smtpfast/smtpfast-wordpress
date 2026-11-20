<?php
/**
 * Settings storage and sanitization.
 *
 * @package SMTPfast
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads, sanitizes and stores the plugin settings.
 */
final class SMTPfast_Settings {

	const OPTION         = 'smtpfast_settings';
	const KEY_OPTION     = 'smtpfast_api_key';
	const DOMAINS_OPTION = 'smtpfast_verified_domains';

	/**
	 * Default settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		return array(
			'from_email'   => '',
			'from_name'    => '',
			'force_from'   => false,
			'category'     => 'transactional',
			'track_opens'  => 'account',
			'track_clicks' => 'account',
			'fallback'     => false,
		);
	}

	/**
	 * Returns all settings, or one setting when a name is given.
	 *
	 * @param string|null $name Setting name.
	 * @return mixed
	 */
	public static function get( $name = null ) {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$settings = array_merge( self::defaults(), array_intersect_key( $stored, self::defaults() ) );

		if ( null === $name ) {
			return $settings;
		}
		return isset( $settings[ $name ] ) ? $settings[ $name ] : null;
	}

	/**
	 * Sanitizes the settings form input.
	 *
	 * @param mixed $input Raw input.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$clean = self::defaults();

		$email               = isset( $input['from_email'] ) ? sanitize_email( (string) $input['from_email'] ) : '';
		$clean['from_email'] = is_email( $email ) ? $email : '';

		$name               = isset( $input['from_name'] ) ? sanitize_text_field( (string) $input['from_name'] ) : '';
		$clean['from_name'] = self::clean_display_name( $name );

		$clean['force_from'] = ! empty( $input['force_from'] );
		$clean['fallback']   = ! empty( $input['fallback'] );

		if ( isset( $input['category'] ) && in_array( $input['category'], array( 'transactional', 'marketing' ), true ) ) {
			$clean['category'] = $input['category'];
		}

		foreach ( array( 'track_opens', 'track_clicks' ) as $field ) {
			if ( isset( $input[ $field ] ) && in_array( $input[ $field ], array( 'account', 'on', 'off' ), true ) ) {
				$clean[ $field ] = $input[ $field ];
			}
		}

		return $clean;
	}

	/**
	 * Removes characters that would break a display name in a header.
	 *
	 * @param string $name Display name.
	 * @return string
	 */
	public static function clean_display_name( $name ) {
		$name = preg_replace( '/[\r\n\t"<>\\\\]+/', ' ', (string) $name );
		return trim( preg_replace( '/\s+/', ' ', $name ) );
	}

	/**
	 * Whether the API key comes from the SMTPFAST_API_KEY constant.
	 *
	 * @return bool
	 */
	public static function key_from_constant() {
		return defined( 'SMTPFAST_API_KEY' ) && is_string( SMTPFAST_API_KEY ) && '' !== trim( SMTPFAST_API_KEY );
	}

	/**
	 * The API key in use: the constant first, then the stored option.
	 *
	 * @return string
	 */
	public static function get_api_key() {
		if ( self::key_from_constant() ) {
			return trim( SMTPFAST_API_KEY );
		}
		$key = get_option( self::KEY_OPTION, '' );
		return is_string( $key ) ? $key : '';
	}

	/**
	 * Whether an API key is set.
	 *
	 * @return bool
	 */
	public static function has_api_key() {
		return '' !== self::get_api_key();
	}

	/**
	 * Sanitizes an API key. Returns an empty string when the value is not key-shaped.
	 *
	 * @param mixed $key Raw key.
	 * @return string
	 */
	public static function sanitize_api_key( $key ) {
		$key = trim( sanitize_text_field( (string) $key ) );
		return preg_match( '/^[A-Za-z0-9_.\-]{8,256}$/', $key ) ? $key : '';
	}

	/**
	 * A masked form of the key that is safe to show: only the last four characters.
	 *
	 * @param string $key API key.
	 * @return string
	 */
	public static function mask_api_key( $key ) {
		if ( strlen( $key ) <= 8 ) {
			return str_repeat( '*', 8 );
		}
		return str_repeat( '*', 12 ) . substr( $key, -4 );
	}

	/**
	 * Verified sending domains seen on the last domain check, lower case.
	 *
	 * @return string[]
	 */
	public static function get_verified_domains() {
		$domains = get_option( self::DOMAINS_OPTION, array() );
		return is_array( $domains ) ? array_values( array_filter( $domains, 'is_string' ) ) : array();
	}

	/**
	 * Stores the verified sending domains from an API domain list.
	 *
	 * @param array<int, array<string, mixed>> $domains Domains as GET /v1/domains returns them.
	 * @return void
	 */
	public static function store_verified_domains( array $domains ) {
		$verified = array();
		foreach ( $domains as $domain ) {
			if ( is_array( $domain ) && isset( $domain['domain'], $domain['status'] ) && 'verified' === $domain['status'] ) {
				$verified[] = strtolower( (string) $domain['domain'] );
			}
		}
		update_option( self::DOMAINS_OPTION, $verified, false );
	}

	/**
	 * Whether the address is on a verified sending domain. Unknown when no domain list is stored yet.
	 *
	 * @param string $email Address.
	 * @return bool|null True or false, or null when no domain list is stored.
	 */
	public static function is_verified_sender( $email ) {
		$domains = self::get_verified_domains();
		if ( empty( $domains ) ) {
			return null;
		}
		$at = strrpos( $email, '@' );
		if ( false === $at ) {
			return false;
		}
		return in_array( strtolower( substr( $email, $at + 1 ) ), $domains, true );
	}
}
