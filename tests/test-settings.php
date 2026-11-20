<?php
/**
 * Tests for settings sanitization and key handling.
 *
 * @package SMTPfast
 */

/**
 * Settings tests.
 */
class Test_SMTPfast_Settings extends SMTPfast_Test_Case {

	/**
	 * Bad input falls back to safe defaults.
	 */
	public function test_sanitize() {
		$clean = SMTPfast_Settings::sanitize(
			array(
				'from_email'   => 'not an email',
				'from_name'    => "Evil\r\nBcc: x@example.net <script>",
				'force_from'   => 'yes',
				'category'     => 'spam',
				'track_opens'  => 'always',
				'track_clicks' => 'off',
				'fallback'     => '',
				'unknown'      => 'dropped',
			)
		);

		$this->assertSame( '', $clean['from_email'] );
		$this->assertStringNotContainsString( "\n", $clean['from_name'] );
		$this->assertStringNotContainsString( '<', $clean['from_name'] );
		$this->assertTrue( $clean['force_from'] );
		$this->assertSame( 'transactional', $clean['category'] );
		$this->assertSame( 'account', $clean['track_opens'] );
		$this->assertSame( 'off', $clean['track_clicks'] );
		$this->assertFalse( $clean['fallback'] );
		$this->assertArrayNotHasKey( 'unknown', $clean );
	}

	/**
	 * Valid input is kept.
	 */
	public function test_sanitize_valid() {
		$clean = SMTPfast_Settings::sanitize(
			array(
				'from_email' => 'site@example.org',
				'from_name'  => 'My Site',
				'category'   => 'marketing',
				'fallback'   => '1',
			)
		);
		$this->assertSame( 'site@example.org', $clean['from_email'] );
		$this->assertSame( 'My Site', $clean['from_name'] );
		$this->assertSame( 'marketing', $clean['category'] );
		$this->assertTrue( $clean['fallback'] );
	}

	/**
	 * Non-array input gives the defaults.
	 */
	public function test_sanitize_non_array() {
		$this->assertSame( SMTPfast_Settings::defaults(), SMTPfast_Settings::sanitize( 'x' ) );
	}

	/**
	 * Key-shaped values only.
	 */
	public function test_sanitize_api_key() {
		$this->assertSame( 'sf_live_abc123XYZ', SMTPfast_Settings::sanitize_api_key( '  sf_live_abc123XYZ ' ) );
		$this->assertSame( '', SMTPfast_Settings::sanitize_api_key( 'short' ) );
		$this->assertSame( '', SMTPfast_Settings::sanitize_api_key( 'sf_live abc"><script>' ) );
	}

	/**
	 * The mask shows only the last four characters.
	 */
	public function test_mask_api_key() {
		$masked = SMTPfast_Settings::mask_api_key( self::TEST_KEY );
		$this->assertStringEndsWith( 'CRET', $masked );
		$this->assertStringNotContainsString( 'sf_test', $masked );
		$this->assertStringNotContainsString( 'SECRET', $masked );
		$this->assertSame( '********', SMTPfast_Settings::mask_api_key( 'abc' ) );
	}

	/**
	 * Verified domain lookups.
	 */
	public function test_verified_sender() {
		$this->assertNull( SMTPfast_Settings::is_verified_sender( 'a@example.org' ) );

		SMTPfast_Settings::store_verified_domains(
			array(
				array(
					'domain' => 'Example.org',
					'status' => 'verified',
				),
				array(
					'domain' => 'other.example',
					'status' => 'failed',
				),
			)
		);
		$this->assertTrue( SMTPfast_Settings::is_verified_sender( 'a@EXAMPLE.org' ) );
		$this->assertFalse( SMTPfast_Settings::is_verified_sender( 'a@other.example' ) );
		$this->assertFalse( SMTPfast_Settings::is_verified_sender( 'a@sub.example.org' ) );
	}

	/**
	 * The log keeps 100 entries.
	 */
	public function test_log_is_capped() {
		for ( $i = 0; $i < 105; $i++ ) {
			SMTPfast_Log::add( 'sent', 'em_' . $i, 1 );
		}
		$entries = SMTPfast_Log::all();
		$this->assertCount( 100, $entries );
		$this->assertSame( 'em_104', $entries[0]['id'] );
	}
}
