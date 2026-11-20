<?php
/**
 * SMTPfast API client.
 *
 * @package SMTPfast
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A small client for the documented SMTPfast API endpoints, built on the WordPress HTTP API.
 */
class SMTPfast_API {

	const BASE_URL = 'https://smtpfa.st/api/v1';

	/**
	 * API key.
	 *
	 * @var string
	 */
	private $api_key;

	/**
	 * Constructor.
	 *
	 * @param string $api_key API key.
	 */
	public function __construct( $api_key ) {
		$this->api_key = (string) $api_key;
	}

	/**
	 * POST /v1/emails. Retries once on a network error, 429 or 5xx with the same Idempotency-Key,
	 * so a retry never sends the email twice.
	 *
	 * @param array<string, mixed> $payload         Request body.
	 * @param string               $idempotency_key Idempotency key.
	 * @return array<string, mixed>|WP_Error The response body, with at least an id.
	 */
	public function send_email( array $payload, $idempotency_key ) {
		$result = $this->with_retry( '/emails', $payload, $idempotency_key );
		if ( ! is_wp_error( $result ) && empty( $result['id'] ) ) {
			return new WP_Error( 'smtpfast_bad_response', __( 'The SMTPfast API accepted the request but returned no email id.', 'smtpfast' ) );
		}
		return $result;
	}

	/**
	 * POSTs a send request, and retries once with the same Idempotency-Key after a network error, 429 or 5xx.
	 *
	 * @param string $path            Path under /v1.
	 * @param array  $body            JSON body.
	 * @param string $idempotency_key Idempotency key.
	 * @return array<string, mixed>|WP_Error
	 */
	private function with_retry( $path, array $body, $idempotency_key ) {
		$headers = array( 'Idempotency-Key' => $idempotency_key );
		$result  = $this->request( 'POST', $path, $body, $headers );

		if ( is_wp_error( $result ) && $this->is_retryable( $result ) ) {
			$data  = $result->get_error_data();
			$delay = isset( $data['retry_after'] ) ? (int) $data['retry_after'] : 1;
			/**
			 * Seconds to wait before the one retry of a failed send. At most 5.
			 *
			 * @param int $delay Seconds.
			 */
			$delay = (int) apply_filters( 'smtpfast_retry_delay', min( max( $delay, 0 ), 2 ) );
			if ( $delay > 0 ) {
				sleep( min( $delay, 5 ) );
			}
			$result = $this->request( 'POST', $path, $body, $headers );
		}
		return $result;
	}

	/**
	 * POST /v1/emails/batch: up to 100 emails, accepted whole or not at all.
	 *
	 * @param array<int, array<string, mixed>> $items           Emails.
	 * @param string                           $idempotency_key Idempotency key.
	 * @return array<string, mixed>|WP_Error The response, with the first email id copied to id.
	 */
	public function send_batch( array $items, $idempotency_key ) {
		$result = $this->with_retry( '/emails/batch', $items, $idempotency_key );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( empty( $result['data'][0]['id'] ) ) {
			return new WP_Error( 'smtpfast_bad_response', __( 'The SMTPfast API accepted the request but returned no email id.', 'smtpfast' ) );
		}
		$result['id'] = (string) $result['data'][0]['id'];
		return $result;
	}

	/**
	 * GET /v1/me: the key's scopes and account.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_me() {
		return $this->request( 'GET', '/me' );
	}

	/**
	 * GET /v1/domains: the sending domains with SPF, DKIM and DMARC status.
	 *
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function list_domains() {
		$result = $this->request( 'GET', '/domains' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
	}

	/**
	 * GET /v1/emails: the newest sent emails, without bodies.
	 *
	 * @param int $limit Page size.
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	public function list_emails( $limit = 100 ) {
		$result = $this->request( 'GET', '/emails?limit=' . absint( $limit ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return isset( $result['data'] ) && is_array( $result['data'] ) ? $result['data'] : array();
	}

	/**
	 * Whether a failed request is worth one retry.
	 *
	 * @param WP_Error $error Error.
	 * @return bool
	 */
	private function is_retryable( WP_Error $error ) {
		if ( 'smtpfast_http_error' === $error->get_error_code() ) {
			return true;
		}
		$data   = $error->get_error_data();
		$status = isset( $data['status'] ) ? (int) $data['status'] : 0;
		return 429 === $status || $status >= 500;
	}

	/**
	 * Sends one request and decodes the JSON answer.
	 *
	 * @param string                    $method  HTTP method.
	 * @param string                    $path    Path under /v1.
	 * @param array<string, mixed>|null $body    JSON body.
	 * @param array<string, string>     $headers Extra headers.
	 * @return array<string, mixed>|WP_Error
	 */
	private function request( $method, $path, $body = null, array $headers = array() ) {
		if ( '' === $this->api_key ) {
			return new WP_Error( 'smtpfast_no_key', __( 'No SMTPfast API key is set.', 'smtpfast' ) );
		}

		$args = array(
			'method'      => $method,
			// The bearer token and the email must never follow a redirect to another host.
			'redirection' => 0,
			/**
			 * Timeout in seconds for SMTPfast API requests.
			 *
			 * @param int $timeout Seconds.
			 */
			'timeout'     => (int) apply_filters( 'smtpfast_http_timeout', 15 ),
			'headers'     => array_merge(
				array(
					'Authorization' => 'Bearer ' . $this->api_key,
					'Accept'        => 'application/json',
					'User-Agent'    => 'SMTPfast-WordPress/' . SMTPFAST_VERSION . '; WordPress/' . get_bloginfo( 'version' ),
				),
				$headers
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = wp_json_encode( $body );
			if ( false === $args['body'] ) {
				return new WP_Error( 'smtpfast_encode_error', __( 'The email could not be encoded as JSON. Check that the subject and body are valid UTF-8.', 'smtpfast' ) );
			}
		}

		$response = wp_safe_remote_request( self::BASE_URL . $path, $args );
		if ( is_wp_error( $response ) ) {
			$message = $response->get_error_message();
			return new WP_Error(
				'smtpfast_http_error',
				/* translators: %s: error message from the HTTP library. */
				sprintf( __( 'Could not reach the SMTPfast API: %s', 'smtpfast' ), $message ),
				// cURL 6 and 7: the name did not resolve or the connection was refused, so nothing was sent.
				array( 'not_connected' => (bool) preg_match( '/cURL error (6|7):/', $message ) )
			);
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $status >= 200 && $status < 300 ) {
			return is_array( $decoded ) ? $decoded : array();
		}

		$message = '';
		if ( is_array( $decoded ) && isset( $decoded['error'] ) ) {
			if ( is_string( $decoded['error'] ) ) {
				$message = $decoded['error'];
			} elseif ( is_array( $decoded['error'] ) && isset( $decoded['error']['message'] ) && is_string( $decoded['error']['message'] ) ) {
				$message = $decoded['error']['message'];
			}
		}
		if ( '' === $message ) {
			/* translators: %d: HTTP status code. */
			$message = sprintf( __( 'The SMTPfast API answered with HTTP %d.', 'smtpfast' ), $status );
		}

		return new WP_Error(
			'smtpfast_api_error',
			$message,
			array(
				'status'      => $status,
				'retry_after' => (int) wp_remote_retrieve_header( $response, 'retry-after' ),
			)
		);
	}
}
