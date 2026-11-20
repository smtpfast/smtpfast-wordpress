<?php
/**
 * Tests for the wp_mail() to API mapping and the failure handling.
 *
 * @package SMTPfast
 */

/**
 * Mailer tests.
 */
class Test_SMTPfast_Mailer extends SMTPfast_Test_Case {

	/**
	 * A plain text email maps to a text body with the WordPress default From.
	 */
	public function test_plain_text_email() {
		$result = wp_mail( 'jane@example.net', 'Hello', 'Plain body' );

		$this->assertTrue( $result );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'https://smtpfa.st/api/v1/emails', $this->requests[0]['url'] );
		$this->assertSame( 'POST', $this->requests[0]['args']['method'] );
		$this->assertSame( 'Bearer ' . self::TEST_KEY, $this->requests[0]['args']['headers']['Authorization'] );
		$this->assertNotEmpty( $this->requests[0]['args']['headers']['Idempotency-Key'] );

		$payload = $this->sent_payload();
		$this->assertSame( array( 'jane@example.net' ), $payload['to'] );
		$this->assertSame( 'Hello', $payload['subject'] );
		$this->assertSame( 'Plain body', $payload['text'] );
		$this->assertArrayNotHasKey( 'html', $payload );
		$this->assertSame( 'WordPress <wordpress@example.org>', $payload['from'] );
		$this->assertSame( 'transactional', $payload['category'] );
		$this->assertArrayNotHasKey( 'track_opens', $payload );
		$this->assertArrayNotHasKey( 'track_clicks', $payload );

		$log = SMTPfast_Log::all();
		$this->assertSame( 'sent', $log[0]['status'] );
		$this->assertSame( 'em_test_1', $log[0]['id'] );
		$this->assertSame( 1, $log[0]['recipients'] );
		$this->assertEmpty( $this->failures );
	}

	/**
	 * The log keeps no addresses, subjects or bodies.
	 */
	public function test_log_stores_no_message_data() {
		wp_mail( 'private@example.net', 'Secret subject', 'Secret body' );
		$stored = maybe_serialize( get_option( SMTPfast_Log::OPTION ) );
		$this->assertStringNotContainsString( 'private@example.net', $stored );
		$this->assertStringNotContainsString( 'Secret', $stored );
	}

	/**
	 * Comma lists, arrays, display names and invalid addresses. Like wp_mail(), a To string
	 * is split on every comma, so a quoted name with a comma loses its first half.
	 */
	public function test_recipient_formats() {
		wp_mail( 'a@example.net, Jane Doe <jane@example.net>,not-an-email, "Smith, John" <john@example.net>', 'S', 'B' );
		$this->assertSame(
			array( 'a@example.net', 'Jane Doe <jane@example.net>', 'John <john@example.net>' ),
			$this->sent_payload()['to']
		);

		wp_mail( array( 'b@example.net', 'B <b2@example.net>' ), 'S', 'B' );
		$this->assertSame( array( 'b@example.net', 'B <b2@example.net>' ), $this->sent_payload( 1 )['to'] );
	}

	/**
	 * From, Cc, Bcc, Reply-To, Content-Type and custom headers in a header string.
	 */
	public function test_headers_string() {
		$headers = "From: Shop Team <shop@example.org>\r\n"
			. "Cc: cc1@example.net, CC Two <cc2@example.net>\r\n"
			. "Bcc: bcc@example.net\r\n"
			. "Reply-To: help@example.net, other@example.net\r\n"
			. "Content-Type: text/html; charset=UTF-8\r\n"
			. "MIME-Version: 1.0\r\n"
			. "X-Order-Id: 1234\r\n"
			. "In-Reply-To: <abc@example.org>\r\n";

		$this->assertTrue( wp_mail( 'to@example.net', 'Order', '<p>Thanks</p>', $headers ) );

		$payload = $this->sent_payload();
		$this->assertSame( 'Shop Team <shop@example.org>', $payload['from'] );
		$this->assertSame( array( 'cc1@example.net', 'CC Two <cc2@example.net>' ), $payload['cc'] );
		$this->assertSame( array( 'bcc@example.net' ), $payload['bcc'] );
		$this->assertSame( array( 'help@example.net', 'other@example.net' ), $payload['reply_to'] );
		$this->assertSame( '<p>Thanks</p>', $payload['html'] );
		$this->assertArrayNotHasKey( 'text', $payload );
		$this->assertSame(
			array(
				'X-Order-Id'  => '1234',
				'In-Reply-To' => '<abc@example.org>',
			),
			$payload['headers']
		);

		$log = SMTPfast_Log::all();
		$this->assertSame( 4, $log[0]['recipients'] );
	}

	/**
	 * Headers given as an array of lines, with one Reply-To.
	 */
	public function test_headers_array() {
		wp_mail(
			'to@example.net',
			'S',
			'B',
			array(
				'From: "Quoted Name" <quoted@example.org>',
				'Reply-To: Help Desk <help@example.net>',
				'X-Broken: line' . "\n" . 'Injected: header',
			)
		);
		$payload = $this->sent_payload();
		$this->assertSame( 'Quoted Name <quoted@example.org>', $payload['from'] );
		$this->assertSame( 'Help Desk <help@example.net>', $payload['reply_to'] );
		$this->assertArrayNotHasKey( 'headers', $payload );
	}

	/**
	 * The wp_mail_content_type, wp_mail_from and wp_mail_from_name filters apply.
	 */
	public function test_core_filters_apply() {
		$html = static function () {
			return 'text/html';
		};
		$from = static function () {
			return 'filtered@example.org';
		};
		$name = static function () {
			return 'Filtered';
		};
		add_filter( 'wp_mail_content_type', $html );
		add_filter( 'wp_mail_from', $from );
		add_filter( 'wp_mail_from_name', $name );

		wp_mail( 'to@example.net', 'S', '<b>Hi</b>' );

		remove_filter( 'wp_mail_content_type', $html );
		remove_filter( 'wp_mail_from', $from );
		remove_filter( 'wp_mail_from_name', $name );

		$payload = $this->sent_payload();
		$this->assertSame( '<b>Hi</b>', $payload['html'] );
		$this->assertSame( 'Filtered <filtered@example.org>', $payload['from'] );
	}

	/**
	 * Attachments are read and base64 encoded, with string keys as file names.
	 */
	public function test_attachments() {
		$pdf = self::temp_file( 'pdf', '%PDF-1.4 test' );
		$txt = self::temp_file( 'tmp', 'hello' );

		wp_mail(
			'to@example.net',
			'Files',
			'See attached',
			'',
			array(
				$pdf,
				'renamed.txt' => $txt,
				'/does/not/exist.pdf',
			)
		);

		$attachments = $this->sent_payload()['attachments'];
		$this->assertCount( 2, $attachments );
		$this->assertSame( wp_basename( $pdf ), $attachments[0]['filename'] );
		$this->assertSame( base64_encode( '%PDF-1.4 test' ), $attachments[0]['content'] ); // phpcs:ignore
		$this->assertSame( 'application/pdf', $attachments[0]['content_type'] );
		$this->assertSame( 'renamed.txt', $attachments[1]['filename'] );
		$this->assertSame( 'text/plain', $attachments[1]['content_type'] );
		$this->assertSame( 'hello', base64_decode( $attachments[1]['content'] ) ); // phpcs:ignore

		unlink( $pdf ); // phpcs:ignore
		unlink( $txt ); // phpcs:ignore
	}

	/**
	 * Attachment types the provider refuses fail up front instead of failing after acceptance.
	 */
	public function test_blocked_attachment_type() {
		$file = wp_tempnam( 'x' );
		file_put_contents( $file, 'x' ); // phpcs:ignore
		$this->assertFalse( wp_mail( 'to@example.net', 'S', 'B', '', array( 'setup.exe' => $file ) ) );
		$this->assertFalse( wp_mail( 'to@example.net', 'S', 'B', '', array( $file ) ) );
		$this->assertCount( 0, $this->requests );
		$this->assertSame( 'smtpfast_blocked_attachment', $this->failures[0]->get_error_data()['smtpfast_error_code'] );

		$this->assertTrue( wp_mail( 'to@example.net', 'S', 'B', '', array( 'report.pdf' => $file ) ) );
		unlink( $file ); // phpcs:ignore
	}

	/**
	 * More than 10 attachments fails before any request, and fires wp_mail_failed.
	 */
	public function test_too_many_attachments_fails() {
		$files = array();
		for ( $i = 0; $i < 11; $i++ ) {
			$files[] = wp_tempnam( 'a' . $i );
			file_put_contents( end( $files ), 'x' ); // phpcs:ignore
		}

		$this->assertFalse( wp_mail( 'to@example.net', 'S', 'B', '', $files ) );
		$this->assertCount( 0, $this->requests );
		$this->assertCount( 1, $this->failures );
		$this->assertSame( 'wp_mail_failed', $this->failures[0]->get_error_code() );
		$this->assertSame( 'smtpfast_too_many_attachments', $this->failures[0]->get_error_data()['smtpfast_error_code'] );

		array_map( 'unlink', $files );
	}

	/**
	 * An API error returns false, fires wp_mail_failed with the API message and is not retried.
	 */
	public function test_api_error_fires_wp_mail_failed() {
		$this->responses = array( self::response( 403, array( 'error' => 'Domain example.org is not verified.' ) ) );

		$this->assertFalse( wp_mail( 'to@example.net', 'S', 'B' ) );
		$this->assertCount( 1, $this->requests );
		$this->assertCount( 1, $this->failures );
		$this->assertSame( 'Domain example.org is not verified.', $this->failures[0]->get_error_message() );
		$data = $this->failures[0]->get_error_data();
		$this->assertSame( 403, $data['smtpfast_http_status'] );
		$this->assertSame( array( 'to@example.net' ), (array) $data['to'] );

		$log = SMTPfast_Log::all();
		$this->assertSame( 'failed', $log[0]['status'] );
		$failure = SMTPfast_Log::last_failure();
		$this->assertSame( 1, $failure['count'] );
		$this->assertFalse( $failure['fell_back'] );

		$this->assertFalse( tests_retrieve_phpmailer_instance()->get_sent() );
	}

	/**
	 * A 5xx is retried once with the same Idempotency-Key.
	 */
	public function test_server_error_is_retried_with_same_key() {
		$this->responses = array(
			self::response( 503, array( 'error' => 'Unavailable' ) ),
			self::response( 200, array( 'id' => 'em_retry' ) ),
		);

		$this->assertTrue( wp_mail( 'to@example.net', 'S', 'B' ) );
		$this->assertCount( 2, $this->requests );
		$this->assertSame(
			$this->requests[0]['args']['headers']['Idempotency-Key'],
			$this->requests[1]['args']['headers']['Idempotency-Key']
		);
		$this->assertSame( 'em_retry', SMTPfast_Log::all()[0]['id'] );
	}

	/**
	 * A network error on both tries returns false.
	 */
	public function test_network_error() {
		$this->responses = array( new WP_Error( 'http_request_failed', 'cURL error 28: timed out' ) );

		$this->assertFalse( wp_mail( 'to@example.net', 'S', 'B' ) );
		$this->assertCount( 2, $this->requests );
		$this->assertStringContainsString( 'timed out', $this->failures[0]->get_error_message() );
	}

	/**
	 * With the fallback on, a 4xx lets WordPress send the email the default way.
	 */
	public function test_fallback_sends_with_phpmailer() {
		$this->set_settings( array( 'fallback' => '1' ) );
		$this->responses = array( self::response( 401, array( 'error' => 'Invalid API key' ) ) );

		$this->assertTrue( wp_mail( 'to@example.net', 'Fallback subject', 'B' ) );
		$this->assertEmpty( $this->failures );
		$this->assertSame( 'Fallback subject', tests_retrieve_phpmailer_instance()->get_sent()->subject );
		$this->assertSame( 'fallback', SMTPfast_Log::all()[0]['status'] );
		$this->assertTrue( SMTPfast_Log::last_failure()['fell_back'] );
	}

	/**
	 * A refused connection is a sure failure, so the fallback runs.
	 */
	public function test_fallback_after_connection_refused() {
		$this->set_settings( array( 'fallback' => '1' ) );
		$this->responses = array( new WP_Error( 'http_request_failed', 'cURL error 7: Failed to connect' ) );

		$this->assertTrue( wp_mail( 'to@example.net', 'S', 'B' ) );
		$this->assertNotFalse( tests_retrieve_phpmailer_instance()->get_sent() );
	}

	/**
	 * After a 5xx or a timeout SMTPfast may have queued the email, so the fallback does not run.
	 */
	public function test_no_fallback_when_delivery_is_unknown() {
		$this->set_settings( array( 'fallback' => '1' ) );
		foreach ( array( self::response( 500, array( 'error' => 'Internal' ) ), new WP_Error( 'http_request_failed', 'cURL error 28: timed out' ), self::response( 200, array() ) ) as $response ) {
			reset_phpmailer_instance();
			$this->responses = array( $response );
			$this->failures  = array();

			$this->assertFalse( wp_mail( 'to@example.net', 'S', 'B' ) );
			$this->assertFalse( tests_retrieve_phpmailer_instance()->get_sent() );
			$this->assertCount( 1, $this->failures );
			$this->assertSame( 'failed', SMTPfast_Log::all()[0]['status'] );
		}
	}

	/**
	 * When the fallback mailer fails too, the log and the notice say so.
	 */
	public function test_fallback_failure_is_recorded() {
		$this->set_settings( array( 'fallback' => '1' ) );
		$this->responses = array( self::response( 401, array( 'error' => 'Invalid API key' ) ) );
		$break           = static function ( $phpmailer ) {
			$phpmailer->clearAllRecipients();
		};
		add_action( 'phpmailer_init', $break );
		$result = wp_mail( 'to@example.net', 'S', 'B' );
		remove_action( 'phpmailer_init', $break );

		$this->assertFalse( $result );
		$this->assertSame( 'failed', SMTPfast_Log::all()[0]['status'] );
		$this->assertFalse( SMTPfast_Log::last_failure()['fell_back'] );
	}

	/**
	 * The failure notice clears when a later email is sent.
	 */
	public function test_success_clears_failure_notice() {
		$this->responses = array(
			self::response( 400, array( 'error' => 'Bad' ) ),
			self::response( 200, array( 'id' => 'em_ok' ) ),
		);
		wp_mail( 'to@example.net', 'S', 'B' );
		$this->assertNotNull( SMTPfast_Log::last_failure() );
		wp_mail( 'to@example.net', 'S', 'B' );
		$this->assertNull( SMTPfast_Log::last_failure() );
	}

	/**
	 * Addresses in error messages are not stored.
	 */
	public function test_errors_are_redacted() {
		$this->responses = array( self::response( 400, array( 'error' => 'Invalid recipient: Jane <jane.doe@example.net>' ) ) );
		wp_mail( 'jane.doe@example.net', 'S', 'B' );
		$this->assertSame( 'Invalid recipient: Jane <[address]>', SMTPfast_Log::all()[0]['error'] );
		$this->assertStringNotContainsString( 'jane.doe', SMTPfast_Log::last_failure()['message'] );
	}

	/**
	 * Without a key the plugin stays out of the way.
	 */
	public function test_no_key_uses_default_mailer() {
		delete_option( SMTPfast_Settings::KEY_OPTION );

		$this->assertTrue( wp_mail( 'to@example.net', 'Core subject', 'B' ) );
		$this->assertCount( 0, $this->requests );
		$this->assertSame( 'Core subject', tests_retrieve_phpmailer_instance()->get_sent()->subject );
	}

	/**
	 * Force From wins over the header and the wp_mail_from filter.
	 */
	public function test_force_from() {
		$this->set_settings(
			array(
				'from_email' => 'site@example.org',
				'from_name'  => 'My Site',
				'force_from' => '1',
			)
		);
		$filter = static function () {
			return 'plugin@example.org';
		};
		add_filter( 'wp_mail_from', $filter );
		wp_mail( 'to@example.net', 'S', 'B', 'From: Other <other@example.org>' );
		remove_filter( 'wp_mail_from', $filter );

		$this->assertSame( 'My Site <site@example.org>', $this->sent_payload()['from'] );
	}

	/**
	 * The configured From fills in when the email has none.
	 */
	public function test_default_from_from_settings() {
		$this->set_settings(
			array(
				'from_email' => 'site@example.org',
				'from_name'  => 'My Site',
			)
		);
		wp_mail( 'to@example.net', 'S', 'B' );
		$this->assertSame( 'My Site <site@example.org>', $this->sent_payload()['from'] );
	}

	/**
	 * A From on an unverified domain is replaced, and the original sender becomes Reply-To.
	 */
	public function test_unverified_from_domain_is_replaced() {
		$this->set_settings( array( 'from_email' => 'site@example.org' ) );
		SMTPfast_Settings::store_verified_domains(
			array(
				array(
					'domain' => 'example.org',
					'status' => 'verified',
				),
				array(
					'domain' => 'pending.example',
					'status' => 'pending',
				),
			)
		);

		wp_mail( 'to@example.net', 'Contact form', 'B', 'From: Visitor <visitor@gmail.com>' );
		$payload = $this->sent_payload();
		$this->assertSame( 'Visitor <site@example.org>', $payload['from'] );
		$this->assertSame( 'visitor@gmail.com', $payload['reply_to'] );

		wp_mail( 'to@example.net', 'Verified', 'B', 'From: Shop <shop@example.org>' );
		$this->assertSame( 'Shop <shop@example.org>', $this->sent_payload( 1 )['from'] );
	}

	/**
	 * Category and tracking from settings, overridden by X-SMTPfast-* headers.
	 */
	public function test_category_and_tracking() {
		$this->set_settings(
			array(
				'track_opens'  => 'off',
				'track_clicks' => 'on',
			)
		);
		wp_mail( 'to@example.net', 'S', 'B' );
		$payload = $this->sent_payload();
		$this->assertFalse( $payload['track_opens'] );
		$this->assertTrue( $payload['track_clicks'] );

		wp_mail( 'to@example.net', 'S', 'B', array( 'X-SMTPfast-Category: marketing', 'X-SMTPfast-Track-Clicks: false' ) );
		$payload = $this->sent_payload( 1 );
		$this->assertSame( 'marketing', $payload['category'] );
		$this->assertFalse( $payload['track_clicks'] );
		$this->assertArrayNotHasKey( 'headers', $payload );
	}

	/**
	 * An email with only Bcc recipients becomes one batch item per address, so no extra
	 * recipient gets it and no Bcc address is shown to the others.
	 */
	public function test_bcc_only_uses_batch() {
		$this->responses = array(
			self::response(
				200,
				array(
					'batch_id' => 'batch_1',
					'data'     => array( array( 'id' => 'em_b1' ), array( 'id' => 'em_b2' ) ),
				)
			),
		);
		$this->assertTrue( wp_mail( '', 'Private', 'B', 'Bcc: one@example.net, two@example.net' ) );

		$this->assertSame( 'https://smtpfa.st/api/v1/emails/batch', $this->requests[0]['url'] );
		$items = $this->sent_payload();
		$this->assertCount( 2, $items );
		$this->assertSame( array( 'one@example.net' ), $items[0]['to'] );
		$this->assertSame( array( 'two@example.net' ), $items[1]['to'] );
		$this->assertArrayNotHasKey( 'bcc', $items[0] );
		$this->assertSame( 'em_b1', SMTPfast_Log::all()[0]['id'] );
		$this->assertSame( 2, SMTPfast_Log::all()[0]['recipients'] );
	}

	/**
	 * An email with only Cc recipients sends them as To, with nobody added.
	 */
	public function test_cc_only() {
		wp_mail( '', 'S', 'B', 'Cc: seen@example.net' );
		$payload = $this->sent_payload();
		$this->assertSame( array( 'seen@example.net' ), $payload['to'] );
		$this->assertArrayNotHasKey( 'cc', $payload );
		$this->assertArrayNotHasKey( 'bcc', $payload );
	}

	/**
	 * Requests never follow redirects.
	 */
	public function test_no_redirects() {
		wp_mail( 'to@example.net', 'S', 'B' );
		$this->assertSame( 0, $this->requests[0]['args']['redirection'] );
	}

	/**
	 * Attachment paths with a URL scheme are refused before any file function sees them.
	 */
	public function test_attachment_stream_wrappers_are_refused() {
		$file = self::temp_file( 'txt', 'ok' );
		wp_mail( 'to@example.net', 'S', 'B', '', array( 'phar://' . $file . '/x', 'ftp://127.0.0.1/x.txt', 'file://' . $file, $file ) );
		$attachments = $this->sent_payload()['attachments'];
		$this->assertCount( 1, $attachments );
		$this->assertSame( 'ok', base64_decode( $attachments[0]['content'] ) ); // phpcs:ignore
		unlink( $file ); // phpcs:ignore
	}

	/**
	 * WordPress 6.9 embeds become inline attachments with their Content-ID.
	 */
	public function test_embeds() {
		$png = self::temp_file( 'png', 'png-bytes' );

		$result = SMTPfast_Mailer::build_payload(
			array(
				'to'          => 'to@example.net',
				'subject'     => 'S',
				'message'     => '<img src="cid:logo">',
				'headers'     => 'Content-Type: text/html',
				'attachments' => array(),
				'embeds'      => array( 'logo' => $png ),
			)
		);
		$attachment = $result['payload']['attachments'][0];
		$this->assertSame( 'logo', $attachment['content_id'] );
		$this->assertSame( 'image/png', $attachment['content_type'] );
		$this->assertSame( 'png-bytes', base64_decode( $attachment['content'] ) ); // phpcs:ignore
		unlink( $png ); // phpcs:ignore
	}

	/**
	 * A multipart/mixed body keeps its nested text and html parts and its file parts.
	 */
	public function test_multipart_mixed_with_file() {
		$body = "--outer\r\nContent-Type: multipart/alternative; boundary=\"inner\"\r\n\r\n"
			. "--inner\r\nContent-Type: text/plain\r\n\r\nPlain\r\n"
			. "--inner\r\nContent-Type: text/html\r\n\r\n<p>Html</p>\r\n"
			. "--inner--\r\n"
			. "--outer\r\nContent-Type: application/pdf; name=\"report.pdf\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"report.pdf\"\r\n\r\n"
			. base64_encode( '%PDF' ) . "\r\n" // phpcs:ignore
			. "--outer--\r\n";
		wp_mail( 'to@example.net', 'S', $body, 'Content-Type: multipart/mixed; charset=UTF-8; boundary="outer"' );

		$payload = $this->sent_payload();
		$this->assertSame( 'Plain', $payload['text'] );
		$this->assertSame( '<p>Html</p>', $payload['html'] );
		$this->assertSame( 'report.pdf', $payload['attachments'][0]['filename'] );
		$this->assertSame( 'application/pdf', $payload['attachments'][0]['content_type'] );
		$this->assertSame( '%PDF', base64_decode( $payload['attachments'][0]['content'] ) ); // phpcs:ignore
	}

	/**
	 * Without a stored domain list, a 403 for an unverified From is retried from the configured From.
	 */
	public function test_unverified_from_retried_without_domain_list() {
		$this->set_settings( array( 'from_email' => 'site@example.org' ) );
		$this->responses = array(
			self::response( 403, array( 'error' => 'Domain gmail.com is not verified.' ) ),
			self::response( 200, array( 'id' => 'em_second' ) ),
		);

		$this->assertTrue( wp_mail( 'to@example.net', 'S', 'B', 'From: Visitor <visitor@gmail.com>' ) );
		$this->assertCount( 2, $this->requests );
		$second = $this->sent_payload( 1 );
		$this->assertSame( 'Visitor <site@example.org>', $second['from'] );
		$this->assertSame( 'visitor@gmail.com', $second['reply_to'] );
		$this->assertNotSame(
			$this->requests[0]['args']['headers']['Idempotency-Key'],
			$this->requests[1]['args']['headers']['Idempotency-Key']
		);
	}

	/**
	 * A 403 for the configured domain itself is not retried.
	 */
	public function test_403_on_configured_domain_is_not_retried() {
		$this->set_settings( array( 'from_email' => 'site@example.org' ) );
		$this->responses = array( self::response( 403, array( 'error' => 'Domain example.org is not verified.' ) ) );
		$this->assertFalse( wp_mail( 'to@example.net', 'S', 'B' ) );
		$this->assertCount( 1, $this->requests );
	}

	/**
	 * No valid recipient fails without a request.
	 */
	public function test_no_recipients() {
		$this->assertFalse( wp_mail( 'nobody', 'S', 'B' ) );
		$this->assertCount( 0, $this->requests );
		$this->assertCount( 1, $this->failures );
	}

	/**
	 * More than 50 To addresses fails.
	 */
	public function test_too_many_to() {
		$to = array();
		for ( $i = 0; $i < 51; $i++ ) {
			$to[] = "user{$i}@example.net";
		}
		$this->assertFalse( wp_mail( $to, 'S', 'B' ) );
		$this->assertCount( 0, $this->requests );
	}

	/**
	 * A multipart/alternative body is split into text and html.
	 */
	public function test_multipart_alternative() {
		$body = "--b1\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nPlain part\r\n"
			. "--b1\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n<p>Html=20part</p>\r\n"
			. "--b1--\r\n";
		wp_mail( 'to@example.net', 'S', $body, 'Content-Type: multipart/alternative; boundary="b1"' );

		$payload = $this->sent_payload();
		$this->assertSame( 'Plain part', $payload['text'] );
		$this->assertSame( '<p>Html part</p>', $payload['html'] );
	}

	/**
	 * Empty subject and body still make a valid request.
	 */
	public function test_empty_subject_and_body() {
		$this->assertTrue( wp_mail( 'to@example.net', '', '' ) );
		$payload = $this->sent_payload();
		$this->assertSame( '(no subject)', $payload['subject'] );
		$this->assertSame( ' ', $payload['text'] );
	}

	/**
	 * A send fires wp_mail_succeeded.
	 */
	public function test_wp_mail_succeeded() {
		$seen = array();
		$cb   = static function ( $data ) use ( &$seen ) {
			$seen[] = $data;
		};
		add_action( 'wp_mail_succeeded', $cb );
		wp_mail( 'to@example.net', 'Done', 'B' );
		remove_action( 'wp_mail_succeeded', $cb );

		$this->assertCount( 1, $seen );
		$this->assertSame( 'Done', $seen[0]['subject'] );
	}

	/**
	 * A 200 without an id is a failure, not a silent success.
	 */
	public function test_response_without_id_fails() {
		$this->responses = array( self::response( 200, array() ) );
		$this->assertFalse( wp_mail( 'to@example.net', 'S', 'B' ) );
		$this->assertCount( 1, $this->failures );
	}

	/**
	 * Core password reset mail goes through the API.
	 */
	public function test_password_reset_email() {
		$user_id = self::factory()->user->create( array( 'user_email' => 'reset@example.net' ) );
		$user    = get_userdata( $user_id );

		$this->assertTrue( retrieve_password( $user->user_login ) );
		$payload = $this->sent_payload();
		$this->assertSame( array( 'reset@example.net' ), $payload['to'] );
		$this->assertStringContainsString( 'Password Reset', $payload['subject'] );
		$this->assertStringContainsString( 'action=rp', $payload['text'] );
	}

	/**
	 * The smtpfast_enabled filter can send one email the default way.
	 */
	public function test_enabled_filter() {
		add_filter( 'smtpfast_enabled', '__return_false' );
		wp_mail( 'to@example.net', 'S', 'B' );
		remove_filter( 'smtpfast_enabled', '__return_false' );
		$this->assertCount( 0, $this->requests );
	}
}
