<?php
/**
 * Base test case: a saved API key and a mocked HTTP layer.
 *
 * @package SMTPfast
 */

/**
 * Records every HTTP request and answers from a queue of canned responses.
 */
abstract class SMTPfast_Test_Case extends WP_UnitTestCase {

	const TEST_KEY = 'sf_test_0123456789abcdefSECRET';

	/**
	 * Requests seen by pre_http_request.
	 *
	 * @var array<int, array{url: string, args: array}>
	 */
	protected $requests = array();

	/**
	 * Canned responses, used in order. The last one repeats.
	 *
	 * @var array<int, array|WP_Error>
	 */
	protected $responses = array();

	/**
	 * Errors passed to wp_mail_failed.
	 *
	 * @var WP_Error[]
	 */
	protected $failures = array();

	/**
	 * Set up.
	 */
	public function set_up() {
		parent::set_up();
		$this->requests  = array();
		$this->responses = array( self::response( 200, array( 'id' => 'em_test_1' ) ) );
		$this->failures  = array();

		update_option( SMTPfast_Settings::KEY_OPTION, self::TEST_KEY );
		delete_option( SMTPfast_Settings::OPTION );
		delete_option( SMTPfast_Settings::DOMAINS_OPTION );
		SMTPfast_Log::clear();
		SMTPfast_Log::clear_failure();

		add_filter( 'pre_http_request', array( $this, 'mock_http' ), 10, 3 );
		add_filter( 'smtpfast_retry_delay', '__return_zero' );
		add_action( 'wp_mail_failed', array( $this, 'record_failure' ) );

		reset_phpmailer_instance();
	}

	/**
	 * The pre_http_request mock.
	 *
	 * @param false|array|WP_Error $preempt Preempted response.
	 * @param array                $args    Request arguments.
	 * @param string               $url     URL.
	 * @return array|WP_Error
	 */
	public function mock_http( $preempt, $args, $url ) {
		$this->requests[] = array(
			'url'  => $url,
			'args' => $args,
		);
		if ( count( $this->responses ) > 1 ) {
			return array_shift( $this->responses );
		}
		return $this->responses[0];
	}

	/**
	 * Collects wp_mail_failed errors.
	 *
	 * @param WP_Error $error Error.
	 */
	public function record_failure( $error ) {
		$this->failures[] = $error;
	}

	/**
	 * A canned HTTP response.
	 *
	 * @param int   $code    Status code.
	 * @param array $body    JSON body.
	 * @param array $headers Headers.
	 * @return array
	 */
	protected static function response( $code, array $body, array $headers = array() ) {
		return array(
			'headers'  => $headers,
			'body'     => wp_json_encode( $body ),
			'response' => array(
				'code'    => $code,
				'message' => get_status_header_desc( $code ),
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * The JSON body of a recorded request.
	 *
	 * @param int $index Request index.
	 * @return array
	 */
	protected function sent_payload( $index = 0 ) {
		$this->assertArrayHasKey( $index, $this->requests, 'No HTTP request was made.' );
		return json_decode( $this->requests[ $index ]['args']['body'], true );
	}

	/**
	 * Creates a temporary file with the given extension.
	 *
	 * @param string $extension File extension.
	 * @param string $content   Content.
	 * @return string Path.
	 */
	protected static function temp_file( $extension, $content ) {
		$path = wp_tempnam( 'smtpfast' );
		unlink( $path ); // phpcs:ignore
		$path = preg_replace( '/\.tmp$/', '', $path ) . '.' . $extension;
		file_put_contents( $path, $content ); // phpcs:ignore
		return $path;
	}

	/**
	 * Stores settings.
	 *
	 * @param array $settings Settings.
	 */
	protected function set_settings( array $settings ) {
		update_option( SMTPfast_Settings::OPTION, SMTPfast_Settings::sanitize( $settings ) );
	}
}
