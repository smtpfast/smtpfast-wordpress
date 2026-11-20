<?php
/**
 * Sends wp_mail() through the SMTPfast API.
 *
 * @package SMTPfast
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns a wp_mail() call into a POST /v1/emails request through the pre_wp_mail filter.
 */
final class SMTPfast_Mailer {

	const MAX_TO               = 50;
	const MAX_REPLY_TO         = 50;
	const MAX_ATTACHMENTS      = 10;
	const MAX_ATTACHMENT_BYTES = 26214400;
	const MAX_HEADERS          = 20;
	const MAX_BATCH            = 100;

	/**
	 * Headers the API sets itself or that only make sense inside a MIME message.
	 *
	 * @var string[]
	 */
	private static $skipped_headers = array(
		'bcc',
		'cc',
		'content-disposition',
		'content-transfer-encoding',
		'content-type',
		'date',
		'dkim-signature',
		'from',
		'message-id',
		'mime-version',
		'received',
		'reply-to',
		'return-path',
		'sender',
		'subject',
		'to',
	);

	/**
	 * Attachment extensions the email provider refuses. SMTPfast would accept the email and
	 * fail it later, so the plugin refuses it up front and wp_mail() returns false.
	 *
	 * @var string[]
	 */
	private static $blocked_extensions = array( 'ade', 'adp', 'app', 'asp', 'bas', 'bat', 'cer', 'chm', 'cmd', 'com', 'cpl', 'crt', 'csh', 'der', 'exe', 'fxp', 'gadget', 'hlp', 'hta', 'inf', 'ins', 'isp', 'its', 'js', 'jse', 'ksh', 'lib', 'lnk', 'mad', 'maf', 'mag', 'mam', 'maq', 'mar', 'mas', 'mat', 'mau', 'mav', 'maw', 'mda', 'mdb', 'mde', 'mdt', 'mdw', 'mdz', 'msc', 'msh', 'msh1', 'msh2', 'mshxml', 'msh1xml', 'msh2xml', 'msi', 'msp', 'mst', 'ops', 'pcd', 'pif', 'plg', 'prf', 'prg', 'reg', 'scf', 'scr', 'sct', 'shb', 'shs', 'sys', 'ps1', 'ps1xml', 'ps2', 'ps2xml', 'psc1', 'psc2', 'tmp', 'url', 'vb', 'vbe', 'vbs', 'vps', 'vsmacros', 'vss', 'vst', 'vsw', 'vxd', 'ws', 'wsc', 'wsf', 'wsh', 'xnk' );

	/**
	 * The result of the last send in this request: the API response or a WP_Error.
	 *
	 * @var array<string, mixed>|WP_Error|null
	 */
	private static $last_result = null;

	/**
	 * Whether WordPress is now sending an email with its default mailer after an SMTPfast failure.
	 *
	 * @var bool
	 */
	private static $fallback_pending = false;

	/**
	 * The pre_wp_mail filter. Returns true or false when SMTPfast handled the email,
	 * or null to let WordPress send it the default way.
	 *
	 * @param null|bool            $result A result from an earlier filter, or null.
	 * @param array<string, mixed> $atts   The wp_mail() arguments.
	 * @return null|bool
	 */
	public static function pre_wp_mail( $result, $atts ) {
		if ( null !== $result ) {
			return $result;
		}
		if ( ! SMTPfast_Settings::has_api_key() ) {
			return null;
		}

		$atts      = is_array( $atts ) ? $atts : array();
		$mail_data = array(
			'to'          => isset( $atts['to'] ) ? $atts['to'] : array(),
			'subject'     => isset( $atts['subject'] ) ? $atts['subject'] : '',
			'message'     => isset( $atts['message'] ) ? $atts['message'] : '',
			'headers'     => isset( $atts['headers'] ) ? $atts['headers'] : array(),
			'attachments' => isset( $atts['attachments'] ) ? $atts['attachments'] : array(),
			'embeds'      => isset( $atts['embeds'] ) ? $atts['embeds'] : array(),
		);

		/**
		 * Whether SMTPfast sends this email. Return false to let WordPress send it the default way.
		 *
		 * @param bool                 $enabled   Whether to send through SMTPfast.
		 * @param array<string, mixed> $mail_data The wp_mail() arguments.
		 */
		if ( ! apply_filters( 'smtpfast_enabled', true, $mail_data ) ) {
			return null;
		}

		self::$fallback_pending = false;

		$built = self::build_payload( $mail_data );
		if ( is_wp_error( $built ) ) {
			return self::fail( $built, $mail_data, 0 );
		}

		$api      = new SMTPfast_API( SMTPfast_Settings::get_api_key() );
		$response = self::send( $api, $built );

		// Without a stored domain list the From domain could not be checked before sending.
		// When SMTPfast refuses it, send once more from the configured From address.
		if ( is_wp_error( $response ) && self::is_unverified_from_error( $response, $built ) ) {
			$built    = self::with_configured_from( $built );
			$response = self::send( $api, $built );
		}

		if ( is_wp_error( $response ) ) {
			return self::fail( $response, $mail_data, $built['recipients'] );
		}

		self::$last_result = $response;
		SMTPfast_Log::add( 'sent', (string) $response['id'], $built['recipients'] );
		SMTPfast_Log::clear_failure();

		/** This action is documented in wp-includes/pluggable.php */
		do_action( 'wp_mail_succeeded', $mail_data ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
		return true;
	}

	/**
	 * Sends one email, or a batch with one email per Bcc address when the email has only Bcc recipients.
	 *
	 * @param SMTPfast_API         $api   API client.
	 * @param array<string, mixed> $built Result of build_payload().
	 * @return array<string, mixed>|WP_Error The response, with the (first) email id in id.
	 */
	private static function send( SMTPfast_API $api, array $built ) {
		if ( empty( $built['bcc_only'] ) ) {
			return $api->send_email( $built['payload'], wp_generate_uuid4() );
		}

		$items = array();
		foreach ( $built['payload']['bcc'] as $address ) {
			$item       = $built['payload'];
			$item['to'] = array( $address );
			unset( $item['bcc'] );
			$items[] = $item;
		}
		return $api->send_batch( $items, wp_generate_uuid4() );
	}

	/**
	 * Whether SMTPfast refused the From domain, and the configured From address could be used instead.
	 *
	 * @param WP_Error             $error Error.
	 * @param array<string, mixed> $built Result of build_payload().
	 * @return bool
	 */
	private static function is_unverified_from_error( WP_Error $error, array $built ) {
		$settings = SMTPfast_Settings::get();
		$data     = $error->get_error_data();
		if ( ! is_array( $data ) || ! isset( $data['status'] ) || 403 !== (int) $data['status'] ) {
			return false;
		}
		if ( $settings['force_from'] || '' === $settings['from_email'] || null !== SMTPfast_Settings::is_verified_sender( $built['from_email'] ) ) {
			return false;
		}
		return self::domain_of( $built['from_email'] ) !== self::domain_of( $settings['from_email'] );
	}

	/**
	 * The built email with the configured From address, and the original sender as Reply-To.
	 *
	 * @param array<string, mixed> $built Result of build_payload().
	 * @return array<string, mixed>
	 */
	private static function with_configured_from( array $built ) {
		$from_email = (string) SMTPfast_Settings::get( 'from_email' );
		if ( ! isset( $built['payload']['reply_to'] ) ) {
			$built['payload']['reply_to'] = $built['from_email'];
		}
		$built['payload']['from'] = self::mailbox( $from_email, $built['from_name'] );
		$built['from_email']      = $from_email;
		return $built;
	}

	/**
	 * The lower case domain of an address.
	 *
	 * @param string $email Address.
	 * @return string
	 */
	private static function domain_of( $email ) {
		$at = strrpos( $email, '@' );
		return false === $at ? '' : strtolower( substr( $email, $at + 1 ) );
	}

	/**
	 * The result of the last send in this request.
	 *
	 * @return array<string, mixed>|WP_Error|null
	 */
	public static function last_result() {
		return self::$last_result;
	}

	/**
	 * Records a failure, then either falls back to the default mailer or fires wp_mail_failed.
	 *
	 * The fallback runs only when SMTPfast surely did not accept the email: nothing was sent,
	 * the connection never opened, or the API refused the request with a 4xx. After a timeout,
	 * a 5xx or an answer without an id the email may already be queued, and a second copy
	 * through the default mailer would be a duplicate.
	 *
	 * @param WP_Error             $error      What went wrong.
	 * @param array<string, mixed> $mail_data  The wp_mail() arguments.
	 * @param int                  $recipients Number of recipients.
	 * @return null|false
	 */
	private static function fail( WP_Error $error, array $mail_data, $recipients ) {
		$fallback          = SMTPfast_Settings::get( 'fallback' ) && self::surely_not_sent( $error );
		$message           = $error->get_error_message();
		self::$last_result = $error;

		SMTPfast_Log::add( $fallback ? 'fallback' : 'failed', '', $recipients, $message );
		SMTPfast_Log::record_failure( $message, $fallback );

		if ( $fallback ) {
			self::$fallback_pending = true;
			return null;
		}

		$data                        = $mail_data;
		$data['smtpfast_error_code'] = $error->get_error_code();
		$error_data                  = $error->get_error_data();
		if ( is_array( $error_data ) && isset( $error_data['status'] ) ) {
			$data['smtpfast_http_status'] = (int) $error_data['status'];
		}

		/** This action is documented in wp-includes/pluggable.php */
		do_action( 'wp_mail_failed', new WP_Error( 'wp_mail_failed', $message, $data ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
		return false;
	}

	/**
	 * Whether the error proves that SMTPfast did not accept the email.
	 *
	 * @param WP_Error $error Error.
	 * @return bool
	 */
	private static function surely_not_sent( WP_Error $error ) {
		$code = $error->get_error_code();
		$data = $error->get_error_data();
		if ( 'smtpfast_api_error' === $code ) {
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
			return $status >= 400 && $status < 500;
		}
		if ( 'smtpfast_http_error' === $code ) {
			return is_array( $data ) && ! empty( $data['not_connected'] );
		}
		return ! in_array( $code, array( 'smtpfast_bad_response' ), true );
	}

	/**
	 * After a fallback, records whether the default mailer then failed too.
	 *
	 * @return void
	 */
	public static function fallback_failed() {
		if ( ! self::$fallback_pending ) {
			return;
		}
		self::$fallback_pending = false;
		SMTPfast_Log::mark_fallback_failed();
	}

	/**
	 * After a fallback, forgets the pending state once the default mailer has sent the email.
	 *
	 * @return void
	 */
	public static function fallback_succeeded() {
		self::$fallback_pending = false;
	}

	/**
	 * Builds the POST /v1/emails body from the wp_mail() arguments, the same way wp_mail() reads them.
	 *
	 * @param array<string, mixed> $mail_data The wp_mail() arguments.
	 * @return array<string, mixed>|WP_Error The payload, the recipient count, whether it is Bcc only, and the final From.
	 */
	public static function build_payload( array $mail_data ) {
		$settings = SMTPfast_Settings::get();
		$parsed   = self::parse_headers( $mail_data['headers'] );

		$to  = self::format_addresses( is_array( $mail_data['to'] ) ? $mail_data['to'] : explode( ',', (string) $mail_data['to'] ) );
		$cc  = self::format_addresses( $parsed['cc'] );
		$bcc = self::format_addresses( $parsed['bcc'] );

		$reply_to = self::format_addresses( $parsed['reply_to'] );

		// From, in the order wp_mail() uses, then the plugin settings.
		$from_email = $parsed['from_email'];
		$from_name  = $parsed['from_name'];
		if ( '' === $from_email ) {
			$from_email = '' !== $settings['from_email'] ? $settings['from_email'] : self::default_from_email();
		}
		if ( '' === $from_name ) {
			$from_name = '' !== $settings['from_name'] ? $settings['from_name'] : 'WordPress';
		}

		/** This filter is documented in wp-includes/pluggable.php */
		$from_email = (string) apply_filters( 'wp_mail_from', $from_email ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
		/** This filter is documented in wp-includes/pluggable.php */
		$from_name = (string) apply_filters( 'wp_mail_from_name', $from_name ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.

		if ( $settings['force_from'] ) {
			if ( '' !== $settings['from_email'] ) {
				$from_email = $settings['from_email'];
			}
			if ( '' !== $settings['from_name'] ) {
				$from_name = $settings['from_name'];
			}
		} elseif ( '' !== $settings['from_email'] && false === SMTPfast_Settings::is_verified_sender( $from_email ) ) {
			// SMTPfast refuses a From on a domain that is not verified. Send from the
			// configured address instead and keep the original sender as Reply-To.
			if ( empty( $reply_to ) && is_email( $from_email ) ) {
				$reply_to = self::format_addresses( array( $from_email ) );
			}
			$from_email = $settings['from_email'];
		}

		if ( ! is_email( $from_email ) ) {
			/* translators: %s: the From address. */
			return new WP_Error( 'smtpfast_invalid_from', sprintf( __( 'The From address "%s" is not a valid email address.', 'smtpfast' ), $from_email ) );
		}

		if ( empty( $to ) && empty( $cc ) && empty( $bcc ) ) {
			return new WP_Error( 'smtpfast_no_recipients', __( 'The email has no valid recipient address.', 'smtpfast' ) );
		}
		// The API needs a To address. Cc addresses are visible to everyone anyway, so they
		// can move to To. An email with only Bcc recipients becomes one email per address.
		$bcc_only = false;
		if ( empty( $to ) && ! empty( $cc ) ) {
			$to = $cc;
			$cc = array();
		} elseif ( empty( $to ) ) {
			$bcc_only = true;
			if ( count( $bcc ) > self::MAX_BATCH ) {
				/* translators: %d: the maximum number of Bcc addresses. */
				return new WP_Error( 'smtpfast_too_many_recipients', sprintf( __( 'An email with only Bcc recipients can have at most %d of them.', 'smtpfast' ), self::MAX_BATCH ) );
			}
		}
		if ( count( $to ) > self::MAX_TO ) {
			/* translators: %d: the maximum number of To addresses. */
			return new WP_Error( 'smtpfast_too_many_recipients', sprintf( __( 'SMTPfast accepts at most %d To addresses per email.', 'smtpfast' ), self::MAX_TO ) );
		}
		$reply_to = array_slice( $reply_to, 0, self::MAX_REPLY_TO );

		/** This filter is documented in wp-includes/pluggable.php */
		$content_type = (string) apply_filters( 'wp_mail_content_type', '' !== $parsed['content_type'] ? $parsed['content_type'] : 'text/plain' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
		/** This filter is documented in wp-includes/pluggable.php */
		$charset = (string) apply_filters( 'wp_mail_charset', '' !== $parsed['charset'] ? $parsed['charset'] : get_bloginfo( 'charset' ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.

		$subject = self::to_utf8( (string) $mail_data['subject'], $charset );
		$message = self::to_utf8( (string) $mail_data['message'], $charset );
		$name    = SMTPfast_Settings::clean_display_name( self::to_utf8( $from_name, $charset ) );

		$payload = array(
			'from'    => self::mailbox( $from_email, $name ),
			'to'      => $bcc_only ? array() : $to,
			'subject' => '' !== trim( $subject ) ? $subject : __( '(no subject)', 'smtpfast' ),
		);
		if ( ! empty( $cc ) ) {
			$payload['cc'] = $cc;
		}
		if ( ! empty( $bcc ) ) {
			$payload['bcc'] = $bcc;
		}
		if ( ! empty( $reply_to ) ) {
			$payload['reply_to'] = 1 === count( $reply_to ) ? $reply_to[0] : $reply_to;
		}

		$body    = self::body( $message, $content_type, $parsed['boundary'] );
		$payload = array_merge( $payload, $body['fields'] );

		// Category and tracking: the settings, then the X-SMTPfast-* headers of this email.
		$payload['category'] = $settings['category'];
		foreach ( array(
			'track_opens'  => 'x-smtpfast-track-opens',
			'track_clicks' => 'x-smtpfast-track-clicks',
		) as $field => $header ) {
			if ( 'on' === $settings[ $field ] ) {
				$payload[ $field ] = true;
			} elseif ( 'off' === $settings[ $field ] ) {
				$payload[ $field ] = false;
			}
			if ( isset( $parsed['smtpfast'][ $header ] ) && in_array( $parsed['smtpfast'][ $header ], array( 'true', 'false' ), true ) ) {
				$payload[ $field ] = 'true' === $parsed['smtpfast'][ $header ];
			}
		}
		if ( isset( $parsed['smtpfast']['x-smtpfast-category'] ) && in_array( $parsed['smtpfast']['x-smtpfast-category'], array( 'transactional', 'marketing' ), true ) ) {
			$payload['category'] = $parsed['smtpfast']['x-smtpfast-category'];
		}

		if ( ! empty( $parsed['custom'] ) ) {
			$payload['headers'] = $parsed['custom'];
		}

		$attachments = self::attachments( $mail_data['attachments'], isset( $mail_data['embeds'] ) ? $mail_data['embeds'] : array(), $body['attachments'] );
		if ( is_wp_error( $attachments ) ) {
			return $attachments;
		}
		if ( ! empty( $attachments ) ) {
			$payload['attachments'] = $attachments;
		}

		/**
		 * The POST /v1/emails body before it is sent. Use it to add tags, for example.
		 *
		 * @param array<string, mixed> $payload   Request body.
		 * @param array<string, mixed> $mail_data The wp_mail() arguments.
		 */
		$payload = apply_filters( 'smtpfast_email_payload', $payload, $mail_data );

		return array(
			'payload'    => $payload,
			'recipients' => count( array_unique( array_merge( $to, $cc, $bcc ) ) ),
			'bcc_only'   => $bcc_only,
			'from_email' => $from_email,
			'from_name'  => $name,
		);
	}

	/**
	 * Parses wp_mail() headers the way wp_mail() does.
	 *
	 * @param string|string[] $headers Headers as a string or a list of lines.
	 * @return array<string, mixed>
	 */
	public static function parse_headers( $headers ) {
		$parsed = array(
			'from_email'   => '',
			'from_name'    => '',
			'content_type' => '',
			'charset'      => '',
			'boundary'     => '',
			'cc'           => array(),
			'bcc'          => array(),
			'reply_to'     => array(),
			'custom'       => array(),
			'smtpfast'     => array(),
		);
		if ( empty( $headers ) ) {
			return $parsed;
		}
		$lines = is_array( $headers ) ? $headers : explode( "\n", str_replace( "\r\n", "\n", (string) $headers ) );

		foreach ( $lines as $line ) {
			if ( ! is_string( $line ) ) {
				continue;
			}
			if ( false === strpos( $line, ':' ) ) {
				if ( false !== stripos( $line, 'boundary=' ) ) {
					$parts              = preg_split( '/boundary=/i', trim( $line ) );
					$parsed['boundary'] = trim( str_replace( array( "'", '"' ), '', $parts[1] ) );
				}
				continue;
			}
			list( $name, $content ) = explode( ':', trim( $line ), 2 );
			$name                   = trim( $name );
			$content                = trim( $content );
			$lower                  = strtolower( $name );

			switch ( $lower ) {
				case 'from':
					$bracket = strpos( $content, '<' );
					if ( false !== $bracket ) {
						if ( $bracket > 0 ) {
							$parsed['from_name'] = trim( str_replace( '"', '', substr( $content, 0, $bracket ) ) );
						}
						$parsed['from_email'] = trim( str_replace( '>', '', substr( $content, $bracket + 1 ) ) );
					} elseif ( '' !== $content ) {
						$parsed['from_email'] = $content;
					}
					break;
				case 'content-type':
					if ( false !== strpos( $content, ';' ) ) {
						list( $type, $params )  = explode( ';', $content, 2 );
						$parsed['content_type'] = strtolower( trim( $type ) );
						if ( preg_match( '/charset\s*=\s*"?([^";\s]+)/i', $params, $matches ) ) {
							$parsed['charset'] = strtolower( $matches[1] );
						}
						if ( preg_match( '/boundary\s*=\s*"?([^";]+)"?/i', $params, $matches ) ) {
							$parsed['boundary'] = trim( $matches[1] );
						}
					} elseif ( '' !== $content ) {
						$parsed['content_type'] = strtolower( $content );
					}
					break;
				case 'cc':
					$parsed['cc'] = array_merge( $parsed['cc'], explode( ',', $content ) );
					break;
				case 'bcc':
					$parsed['bcc'] = array_merge( $parsed['bcc'], explode( ',', $content ) );
					break;
				case 'reply-to':
					$parsed['reply_to'] = array_merge( $parsed['reply_to'], explode( ',', $content ) );
					break;
				case 'x-smtpfast-category':
				case 'x-smtpfast-track-opens':
				case 'x-smtpfast-track-clicks':
					$parsed['smtpfast'][ $lower ] = strtolower( $content );
					break;
				default:
					if ( self::is_allowed_header( $name, $content ) && count( $parsed['custom'] ) < self::MAX_HEADERS ) {
						$parsed['custom'][ $name ] = $content;
					}
			}
		}
		return $parsed;
	}

	/**
	 * Whether a custom header can go to the API as it is.
	 *
	 * @param string $name  Header name.
	 * @param string $value Header value.
	 * @return bool
	 */
	private static function is_allowed_header( $name, $value ) {
		if ( in_array( strtolower( $name ), self::$skipped_headers, true ) ) {
			return false;
		}
		if ( ! preg_match( '/^[!-9;-~]{1,64}$/', $name ) ) {
			return false;
		}
		if ( '' === $value || strlen( $value ) > 2000 || preg_match( '/[\r\n]/', $value ) ) {
			return false;
		}
		foreach ( preg_split( '/\s+/', $value ) as $token ) {
			if ( strlen( $token ) > 900 ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Validates and formats a list of addresses as "Name <email>" or "email".
	 * Invalid addresses are skipped, as wp_mail() does.
	 *
	 * @param array<int|string, mixed> $addresses Raw addresses.
	 * @return string[]
	 */
	public static function format_addresses( array $addresses ) {
		$out = array();
		foreach ( $addresses as $raw ) {
			if ( ! is_string( $raw ) ) {
				continue;
			}
			$raw = trim( $raw );
			if ( '' === $raw ) {
				continue;
			}
			$name  = '';
			$email = $raw;
			if ( preg_match( '/(.*)<(.+)>/', $raw, $matches ) ) {
				$name  = SMTPfast_Settings::clean_display_name( $matches[1] );
				$email = trim( $matches[2] );
			}
			if ( ! is_email( $email ) ) {
				continue;
			}
			$out[] = self::mailbox( $email, $name );
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * One mailbox, with the display name quoted when it has special characters.
	 *
	 * @param string $email Address.
	 * @param string $name  Display name, already cleaned.
	 * @return string
	 */
	private static function mailbox( $email, $name ) {
		if ( '' === $name ) {
			return $email;
		}
		if ( preg_match( '/[()\[\]:;@,.]/', $name ) ) {
			$name = '"' . $name . '"';
		}
		return $name . ' <' . $email . '>';
	}

	/**
	 * The From address wp_mail() uses when nothing else sets one.
	 *
	 * @return string
	 */
	private static function default_from_email() {
		$sitename = wp_parse_url( network_home_url(), PHP_URL_HOST );
		if ( ! is_string( $sitename ) ) {
			return '';
		}
		if ( 'www.' === substr( $sitename, 0, 4 ) ) {
			$sitename = substr( $sitename, 4 );
		}
		return 'wordpress@' . $sitename;
	}

	/**
	 * Converts text to UTF-8 when the email uses another charset.
	 *
	 * @param string $text    Text.
	 * @param string $charset Charset.
	 * @return string
	 */
	private static function to_utf8( $text, $charset ) {
		$charset = strtolower( trim( $charset ) );
		if ( '' === $charset || 'utf-8' === $charset || 'utf8' === $charset || ! function_exists( 'mb_convert_encoding' ) ) {
			return $text;
		}
		try {
			$converted = @mb_convert_encoding( $text, 'UTF-8', $charset ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- An unknown charset must not stop the email.
		} catch ( Throwable $e ) {
			return $text;
		}
		return is_string( $converted ) ? $converted : $text;
	}

	/**
	 * The html and text fields for the message, plus any files inside a multipart body.
	 *
	 * @param string $message      Message body.
	 * @param string $content_type Content type.
	 * @param string $boundary     MIME boundary of a multipart message.
	 * @return array{fields: array<string, string>, attachments: array<int, array<string, mixed>>}
	 */
	private static function body( $message, $content_type, $boundary ) {
		$content_type = strtolower( trim( $content_type ) );

		if ( 0 === strpos( $content_type, 'multipart/' ) && '' !== $boundary ) {
			$out = array(
				'fields'      => array(),
				'attachments' => array(),
			);
			self::split_multipart( str_replace( "\r\n", "\n", $message ), $boundary, $out, 0 );
			if ( ! empty( $out['fields'] ) ) {
				return $out;
			}
		}
		$field = 'text/html' === $content_type ? 'html' : 'text';
		return array(
			'fields'      => array( $field => '' !== $message ? $message : ' ' ),
			'attachments' => array(),
		);
	}

	/**
	 * Walks a multipart body: the first text/plain and text/html parts become the body,
	 * nested multiparts are followed, and every other part becomes an attachment.
	 *
	 * @param string               $message  Multipart body with \n line ends.
	 * @param string               $boundary MIME boundary.
	 * @param array<string, array> $out      Result with fields and attachments, filled in place.
	 * @param int                  $depth    Nesting depth.
	 * @return void
	 */
	private static function split_multipart( $message, $boundary, array &$out, $depth ) {
		if ( $depth > 5 ) {
			return;
		}
		$sections = explode( '--' . $boundary, $message );
		array_shift( $sections );

		foreach ( $sections as $section ) {
			if ( 0 === strpos( $section, '--' ) ) {
				break;
			}
			$section = ltrim( $section, "\n" );
			$split   = strpos( $section, "\n\n" );
			if ( false === $split ) {
				continue;
			}
			$head = preg_replace( '/\n[ \t]+/', ' ', substr( $section, 0, $split ) );
			$body = rtrim( substr( $section, $split + 2 ), "\n" );

			$type        = preg_match( '/^content-type:\s*([^;\s]+)/im', $head, $m ) ? strtolower( $m[1] ) : 'text/plain';
			$encoding    = preg_match( '/^content-transfer-encoding:\s*(\S+)/im', $head, $m ) ? strtolower( $m[1] ) : '';
			$charset     = preg_match( '/charset="?([^";\s]+)/i', $head, $m ) ? $m[1] : '';
			$disposition = preg_match( '/^content-disposition:\s*([^;\s]+)/im', $head, $m ) ? strtolower( $m[1] ) : '';

			if ( 0 === strpos( $type, 'multipart/' ) ) {
				if ( preg_match( '/boundary="?([^";\n]+)"?/i', $head, $m ) ) {
					self::split_multipart( $body, trim( $m[1] ), $out, $depth + 1 );
				}
				continue;
			}

			if ( 'base64' === $encoding ) {
				$decoded = base64_decode( preg_replace( '/\s+/', '', $body ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes a MIME part of the email body.
				$body    = false === $decoded ? $body : $decoded;
			} elseif ( 'quoted-printable' === $encoding ) {
				$body = quoted_printable_decode( $body );
			}

			if ( 'attachment' !== $disposition && 'text/plain' === $type && ! isset( $out['fields']['text'] ) ) {
				$out['fields']['text'] = self::to_utf8( $body, $charset );
				continue;
			}
			if ( 'attachment' !== $disposition && 'text/html' === $type && ! isset( $out['fields']['html'] ) ) {
				$out['fields']['html'] = self::to_utf8( $body, $charset );
				continue;
			}

			$name = '';
			if ( preg_match( '/filename\*?="?([^";\n]+)"?/i', $head, $m ) || preg_match( '/\bname="?([^";\n]+)"?/i', $head, $m ) ) {
				$name = trim( $m[1] );
			}
			$file = array(
				'filename'     => self::clean_filename( '' !== $name ? $name : 'part-' . ( count( $out['attachments'] ) + 1 ) ),
				'content'      => base64_encode( $body ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- The API takes attachments as base64.
				'content_type' => $type,
				'size'         => strlen( $body ),
			);
			if ( preg_match( '/^content-id:\s*<?([^>\s]+)>?/im', $head, $m ) ) {
				$file['content_id'] = $m[1];
			} elseif ( 'inline' === $disposition ) {
				$file['inline'] = true;
			}
			$out['attachments'][] = $file;
		}
	}

	/**
	 * Reads attachments and embedded images and encodes them for the API.
	 * Unreadable files are skipped, as wp_mail() does.
	 *
	 * @param string|array<int|string, string> $attachments File paths, keyed by file name when the key is a string.
	 * @param string|array<int|string, string> $embeds      Image paths keyed by Content-ID (WordPress 6.9+).
	 * @param array<int, array<string, mixed>> $parts       Files already taken from a multipart body.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	private static function attachments( $attachments, $embeds, array $parts ) {
		$files = array();
		foreach ( self::path_list( $attachments ) as $filename => $path ) {
			$file = self::read_file( $path, is_string( $filename ) ? $filename : '' );
			if ( null !== $file ) {
				$files[] = $file;
			}
		}
		foreach ( self::path_list( $embeds ) as $cid => $path ) {
			// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
			/** This filter is documented in wp-includes/pluggable.php */
			$args = apply_filters(
				'wp_mail_embed_args',
				array(
					'path'        => $path,
					'cid'         => (string) $cid,
					'name'        => basename( $path ),
					'encoding'    => 'base64',
					'type'        => '',
					'disposition' => 'inline',
				)
			);
			// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			if ( ! is_array( $args ) || ! isset( $args['path'] ) || ! is_string( $args['path'] ) ) {
				continue;
			}
			$file = self::read_file( $args['path'], isset( $args['name'] ) ? (string) $args['name'] : '' );
			if ( null === $file ) {
				continue;
			}
			if ( ! empty( $args['type'] ) && is_string( $args['type'] ) ) {
				$file['content_type'] = $args['type'];
			}
			$cid = isset( $args['cid'] ) ? (string) $args['cid'] : '';
			if ( preg_match( '/^[A-Za-z0-9._-]+(@[A-Za-z0-9.-]+)?$/', $cid ) ) {
				$file['content_id'] = $cid;
			} else {
				$file['inline'] = true;
			}
			$files[] = $file;
		}
		$files = array_merge( $files, $parts );

		if ( count( $files ) > self::MAX_ATTACHMENTS ) {
			/* translators: %d: the maximum number of attachments. */
			return new WP_Error( 'smtpfast_too_many_attachments', sprintf( __( 'SMTPfast accepts at most %d attachments per email.', 'smtpfast' ), self::MAX_ATTACHMENTS ) );
		}
		foreach ( $files as $file ) {
			$extension = strtolower( (string) pathinfo( $file['filename'], PATHINFO_EXTENSION ) );
			if ( in_array( $extension, self::$blocked_extensions, true ) ) {
				return new WP_Error(
					'smtpfast_blocked_attachment',
					/* translators: %s: file extension. */
					sprintf( __( 'SMTPfast cannot send attachments of type .%s. Rename or zip the file.', 'smtpfast' ), $extension )
				);
			}
		}
		$bytes = 0;
		foreach ( $files as $index => $file ) {
			$bytes += $file['size'];
			unset( $files[ $index ]['size'] );
		}
		if ( $bytes > self::MAX_ATTACHMENT_BYTES ) {
			return new WP_Error( 'smtpfast_attachments_too_large', __( 'The attachments are larger than the 25 MB SMTPfast accepts per email.', 'smtpfast' ) );
		}
		return array_values( $files );
	}

	/**
	 * A list of paths from an array or a newline separated string, as wp_mail() reads them.
	 *
	 * @param mixed $paths Paths.
	 * @return array<int|string, string>
	 */
	private static function path_list( $paths ) {
		if ( empty( $paths ) ) {
			return array();
		}
		if ( ! is_array( $paths ) ) {
			$paths = explode( "\n", str_replace( "\r\n", "\n", (string) $paths ) );
		}
		$out = array();
		foreach ( $paths as $key => $path ) {
			if ( is_string( $path ) && '' !== trim( $path ) ) {
				$out[ $key ] = trim( $path );
			}
		}
		return $out;
	}

	/**
	 * Reads one local file. Paths with a URL scheme (ftp://, phar:// and so on) are refused
	 * before any file function sees them, as PHPMailer does.
	 *
	 * @param string $path Local path.
	 * @param string $name File name to use, or an empty string for the base name.
	 * @return array<string, mixed>|null
	 */
	private static function read_file( $path, $name ) {
		if ( preg_match( '#^[a-z][a-z\d+.-]*:#i', $path ) && ! preg_match( '#^[a-z]:[\\\\/]#i', $path ) ) {
			return null;
		}
		if ( ! @is_file( $path ) || ! is_readable( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir may warn about a path outside it.
			return null;
		}
		$content = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local attachment file, as wp_mail() does.
		if ( false === $content ) {
			return null;
		}
		$name = '' !== trim( $name ) ? trim( $name ) : wp_basename( $path );
		$type = wp_check_filetype( $name );
		return array(
			'filename'     => self::clean_filename( $name ),
			'content'      => base64_encode( $content ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- The API takes attachments as base64.
			'content_type' => ! empty( $type['type'] ) ? $type['type'] : 'application/octet-stream',
			'size'         => strlen( $content ),
		);
	}

	/**
	 * A file name without path separators, quotes or control characters.
	 *
	 * @param string $name File name.
	 * @return string
	 */
	private static function clean_filename( $name ) {
		$name = trim( (string) preg_replace( '/[\x00-\x1f\x7f\/\\\\"]+/', '', $name ) );
		return '' !== $name ? substr( $name, 0, 200 ) : 'attachment';
	}
}
