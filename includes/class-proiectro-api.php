<?php
/**
 * The HTTP client: wp_remote_request() with the workspace's Bearer key. Nothing else — no
 * Composer, no vendored HTTP library, so nothing can collide with another plugin.
 *
 * @package Proiectro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Proiectro_Api {

	const TIMEOUT = 15;

	/** @var Proiectro_Settings */
	private $settings;

	public function __construct( Proiectro_Settings $settings ) {
		$this->settings = $settings;
	}

	public function get( $path, array $query = array() ) {
		return $this->request( 'GET', $path, null, $query );
	}

	public function post( $path, array $body ) {
		return $this->request( 'POST', $path, $body );
	}

	/**
	 * Perform one API call. Returns the decoded JSON body on 2xx, or a WP_Error whose code is
	 * the HTTP status ('http_403') — or 'proiectro_unreachable' when no answer came at all,
	 * which is what the outbox uses to decide "retry later" versus "this payload is wrong".
	 *
	 * @return array|WP_Error
	 */
	public function request( $method, $path, $body = null, array $query = array() ) {
		if ( ! $this->settings->is_configured() ) {
			return new WP_Error( 'proiectro_unconfigured', __( 'Proiect.ro is not configured yet: add the workspace path and API key under Settings > Proiect.ro.', 'proiectro' ) );
		}

		$url = sprintf(
			'%s/api/v1/%s/%s',
			untrailingslashit( $this->settings->get( 'base_url' ) ),
			rawurlencode( $this->settings->get( 'workspace_path' ) ),
			ltrim( $path, '/' )
		);
		if ( $query ) {
			$url = add_query_arg( array_map( 'rawurlencode', $query ), $url );
		}

		$args = array(
			'method'  => $method,
			'timeout' => self::TIMEOUT,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->settings->get( 'api_key' ),
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json',
				'User-Agent'    => 'proiectro-wordpress/' . PROIECTRO_VERSION . '; ' . home_url( '/' ),
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'proiectro_unreachable', $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );
		$data   = json_decode( $raw, true );

		if ( $status >= 200 && $status < 300 ) {
			return is_array( $data ) ? $data : array();
		}

		$message = '';
		if ( is_array( $data ) ) {
			if ( ! empty( $data['message'] ) ) {
				$message = $data['message'];
			} elseif ( ! empty( $data['detail'] ) ) {
				// Validation errors: a list of {loc, msg}.
				$parts = array();
				foreach ( (array) $data['detail'] as $item ) {
					if ( is_array( $item ) && isset( $item['msg'] ) ) {
						$field   = isset( $item['loc'] ) && is_array( $item['loc'] ) ? end( $item['loc'] ) : '';
						$parts[] = trim( $field . ': ' . $item['msg'], ': ' );
					}
				}
				$message = implode( '; ', $parts );
			}
		}
		if ( '' === $message ) {
			$message = 401 === $status || 403 === $status
				? __( 'The API key was rejected. Check the key, its permissions and its expiry date.', 'proiectro' )
				: sprintf( /* translators: %d: HTTP status */ __( 'Proiect.ro answered HTTP %d.', 'proiectro' ), $status );
		}
		return new WP_Error( 'http_' . $status, $message, array( 'status' => $status ) );
	}

	/**
	 * Whether an error is worth retrying: no answer, a 429, or a server-side failure.
	 * A 4xx other than 429 means the payload or the credentials are wrong — retrying will not help.
	 */
	public static function is_retryable( WP_Error $error ) {
		if ( 'proiectro_unreachable' === $error->get_error_code() ) {
			return true;
		}
		$data   = $error->get_error_data();
		$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
		return 429 === $status || $status >= 500;
	}
}
