<?php
/**
 * Tests for the settings page and its actions.
 *
 * @package SMTPfast
 */

/**
 * Raised instead of a redirect so a handler can be tested without exit.
 */
class SMTPfast_Test_Redirect extends Exception {}

/**
 * Admin tests.
 */
class Test_SMTPfast_Admin extends SMTPfast_Test_Case {

	/**
	 * Administrator id.
	 *
	 * @var int
	 */
	protected $admin_id;

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		$this->admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		add_filter( 'wp_redirect', array( $this, 'stop_redirect' ) );
		delete_transient( SMTPfast_Admin::ACCOUNT_CACHE );
		delete_transient( SMTPfast_Admin::DOMAINS_CACHE );
	}

	/**
	 * Tear down.
	 */
	public function tear_down() {
		$_POST    = array();
		$_REQUEST = array();
		parent::tear_down();
	}

	/**
	 * Turns a redirect into an exception.
	 *
	 * @param string $location Location.
	 * @throws SMTPfast_Test_Redirect Always.
	 */
	public function stop_redirect( $location ) {
		throw new SMTPfast_Test_Redirect( $location );
	}

	/**
	 * Posts to an admin-post handler.
	 *
	 * @param string $action Action without the smtpfast_ prefix.
	 * @param array  $fields Fields.
	 * @param bool   $nonce  Whether to add a valid nonce.
	 * @return string Redirect location.
	 */
	protected function post( $action, array $fields, $nonce = true ) {
		$_POST = $fields;
		if ( $nonce ) {
			$_POST['_wpnonce'] = wp_create_nonce( 'smtpfast_' . $action );
		}
		$_REQUEST = $_POST;
		try {
			call_user_func( array( 'SMTPfast_Admin', 'handle_' . $action ) );
		} catch ( SMTPfast_Test_Redirect $redirect ) {
			return $redirect->getMessage();
		}
		$this->fail( 'The handler did not redirect.' );
	}

	/**
	 * Saving settings stores sanitized values and a new key, and checks the key.
	 */
	public function test_save_settings() {
		wp_set_current_user( $this->admin_id );
		delete_option( SMTPfast_Settings::KEY_OPTION );
		update_option( SMTPfast_Settings::DOMAINS_OPTION, array( 'old-account.example' ) );
		$this->responses = array(
			self::response(
				200,
				array(
					'scopes'  => array( 'email:send', 'domain:read' ),
					'account' => array( 'tier' => 'growth' ),
				)
			),
			self::response(
				200,
				array(
					'data' => array(
						array(
							'domain' => 'example.org',
							'status' => 'verified',
						),
					),
				)
			),
		);

		$location = $this->post(
			'save_settings',
			array(
				'smtpfast'         => array(
					'from_email' => 'site@example.org',
					'from_name'  => 'My <b>Site</b>',
					'category'   => 'marketing',
				),
				'smtpfast_api_key' => 'sf_new_key_1234567890',
			)
		);

		$this->assertStringContainsString( 'page=smtpfast', $location );
		$this->assertSame( 'sf_new_key_1234567890', get_option( SMTPfast_Settings::KEY_OPTION ) );
		$this->assertSame( 'site@example.org', SMTPfast_Settings::get( 'from_email' ) );
		$this->assertSame( 'My Site', SMTPfast_Settings::get( 'from_name' ) );
		$this->assertSame( 'marketing', SMTPfast_Settings::get( 'category' ) );
		$this->assertSame( array( 'example.org' ), SMTPfast_Settings::get_verified_domains() );

		// A new key whose domains cannot be read must not keep the old account's list.
		update_option( SMTPfast_Settings::DOMAINS_OPTION, array( 'old-account.example' ) );
		delete_transient( SMTPfast_Admin::ACCOUNT_CACHE );
		delete_transient( SMTPfast_Admin::DOMAINS_CACHE );
		$this->responses = array( self::response( 403, array( 'error' => 'Missing scope domain:read' ) ) );
		$this->post(
			'save_settings',
			array(
				'smtpfast'         => array(),
				'smtpfast_api_key' => 'sf_other_key_1234567890',
			)
		);
		$this->assertSame( array(), SMTPfast_Settings::get_verified_domains() );
		$this->assertSame( 'https://smtpfa.st/api/v1/me', $this->requests[0]['url'] );
	}

	/**
	 * An empty key field keeps the saved key.
	 */
	public function test_empty_key_field_keeps_key() {
		wp_set_current_user( $this->admin_id );
		$this->post(
			'save_settings',
			array(
				'smtpfast'         => array(),
				'smtpfast_api_key' => '',
			)
		);
		$this->assertSame( self::TEST_KEY, get_option( SMTPfast_Settings::KEY_OPTION ) );
		$this->assertCount( 0, $this->requests );
	}

	/**
	 * A user without manage_options cannot save.
	 */
	public function test_save_requires_capability() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->expectException( WPDieException::class );
		$this->post( 'save_settings', array( 'smtpfast_api_key' => 'sf_attacker_key_123456' ) );
	}

	/**
	 * A request without a valid nonce is refused.
	 */
	public function test_save_requires_nonce() {
		wp_set_current_user( $this->admin_id );
		$this->expectException( WPDieException::class );
		$this->post( 'save_settings', array( 'smtpfast_api_key' => 'sf_attacker_key_123456' ), false );
	}

	/**
	 * Every action checks its nonce.
	 */
	public function test_every_action_requires_nonce() {
		wp_set_current_user( $this->admin_id );
		foreach ( array( 'delete_key', 'send_test', 'refresh_domains', 'clear_log', 'dismiss_failure' ) as $action ) {
			try {
				$this->post( $action, array(), false );
				$this->fail( "$action ran without a nonce." );
			} catch ( WPDieException $e ) {
				$this->assertNotEmpty( $e->getMessage() );
			}
		}
		$this->assertSame( self::TEST_KEY, get_option( SMTPfast_Settings::KEY_OPTION ) );
	}

	/**
	 * The page never prints the saved key.
	 */
	public function test_page_does_not_print_key() {
		wp_set_current_user( $this->admin_id );
		$this->responses = array(
			self::response(
				200,
				array(
					'scopes'  => array( 'email:send' ),
					'account' => array( 'tier' => 'free' ),
				)
			),
		);
		ob_start();
		SMTPfast_Admin::render_page();
		$html = ob_get_clean();

		$this->assertStringNotContainsString( self::TEST_KEY, $html );
		$this->assertStringNotContainsString( 'sf_test_0123', $html );
		$this->assertStringContainsString( 'CRET', $html );
		$this->assertStringContainsString( 'Connected', $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
	}

	/**
	 * Removing the key.
	 */
	public function test_delete_key() {
		wp_set_current_user( $this->admin_id );
		$this->post( 'delete_key', array() );
		$this->assertFalse( get_option( SMTPfast_Settings::KEY_OPTION ) );
	}

	/**
	 * The test email goes through wp_mail() and reports the email id.
	 */
	public function test_send_test_email() {
		wp_set_current_user( $this->admin_id );
		$this->responses = array( self::response( 200, array( 'id' => 'em_test_button' ) ) );

		$this->post( 'send_test', array( 'smtpfast_test_to' => 'success@simulator.amazonses.com' ) );

		$payload = $this->sent_payload();
		$this->assertSame( array( 'success@simulator.amazonses.com' ), $payload['to'] );
		$this->assertArrayHasKey( 'html', $payload );
		$notice = get_transient( SMTPfast_Admin::NOTICE_TRANSIENT . $this->admin_id );
		$this->assertSame( 'success', $notice['type'] );
		$this->assertStringContainsString( 'em_test_button', $notice['message'] );
	}

	/**
	 * The failure notice shows until dismissed.
	 */
	public function test_failure_notice() {
		wp_set_current_user( $this->admin_id );
		SMTPfast_Log::record_failure( 'Domain not verified <script>', false );

		ob_start();
		SMTPfast_Admin::failure_notice();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'could not send an email', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
		$this->assertStringNotContainsString( '<script>', $html );

		try {
			$this->post( 'dismiss_failure', array() );
		} catch ( Exception $e ) {
			unset( $e );
		}
		$this->assertNull( SMTPfast_Log::last_failure() );
	}

	/**
	 * Subscribers do not see the failure notice.
	 */
	public function test_failure_notice_hidden_from_subscribers() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		SMTPfast_Log::record_failure( 'x', false );
		ob_start();
		SMTPfast_Admin::failure_notice();
		$this->assertSame( '', ob_get_clean() );
	}

	/**
	 * Uninstall removes the options.
	 */
	public function test_uninstall() {
		SMTPfast_Log::add( 'sent', 'em_1', 1 );
		update_option( SMTPfast_Settings::OPTION, SMTPfast_Settings::defaults() );
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'smtpfast/smtpfast.php' );
		}
		require dirname( __DIR__ ) . '/uninstall.php';

		foreach ( array( 'smtpfast_settings', 'smtpfast_api_key', 'smtpfast_log', 'smtpfast_last_failure', 'smtpfast_verified_domains' ) as $option ) {
			$this->assertFalse( get_option( $option ), $option );
		}
	}
}
