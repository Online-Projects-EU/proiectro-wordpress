<?php
/**
 * Lead intake: turns whatever a form produced into an `add_lead` payload and hands it to the
 * outbox. Two entry points today — a PHP action for developers and form-plugin bridges, and
 * a shortcode form for sites without a form plugin.
 *
 *   do_action( 'proiectro_capture_lead', array( 'name' => 'Jane', 'email' => 'jane@example.com' ), 'contact-form' );
 *
 * @package Proiectro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Proiectro_Leads {

	/** Fields add_lead accepts, and the aliases forms commonly use for them. */
	const FIELDS = array(
		'name'         => array( 'name', 'full_name', 'your-name', 'fullname' ),
		'email'        => array( 'email', 'your-email', 'e-mail', 'mail' ),
		'phone'        => array( 'phone', 'your-phone', 'tel', 'telephone', 'mobile' ),
		'company_name' => array( 'company_name', 'company', 'organization', 'organisation', 'firma' ),
		'job_title'    => array( 'job_title', 'title', 'position', 'role' ),
		'notes'        => array( 'notes', 'message', 'your-message', 'comments', 'details' ),
		'company_url'  => array( 'company_url', 'website', 'url' ),
		'country'      => array( 'country' ),
	);

	/** @var Proiectro_Outbox */
	private $outbox;

	/** @var Proiectro_Settings */
	private $settings;

	public function __construct( Proiectro_Outbox $outbox, Proiectro_Settings $settings ) {
		$this->outbox   = $outbox;
		$this->settings = $settings;
	}

	public function register() {
		add_action( 'proiectro_capture_lead', array( $this, 'capture' ), 10, 2 );
		add_shortcode( 'proiectro_lead_form', array( $this, 'shortcode' ) );
		add_action( 'init', array( $this, 'handle_shortcode_post' ) );
	}

	/**
	 * Normalise a form's fields into an add_lead payload and enqueue it.
	 *
	 * @param array  $fields Raw field name => value pairs from any form.
	 * @param string $source Where it came from (form plugin / form id), kept on the outbox row.
	 * @return int|WP_Error Outbox row id, or an error when nothing usable was submitted.
	 */
	public function capture( $fields, $source = '' ) {
		$fields  = is_array( $fields ) ? $fields : array();
		$payload = $this->to_payload( $fields );
		if ( '' === $payload['name'] ) {
			return new WP_Error( 'proiectro_no_name', __( 'A lead needs at least a name.', 'proiectro' ) );
		}
		/**
		 * Last chance to adjust what is sent (e.g. set lead_list per form, add a note).
		 */
		$payload = apply_filters( 'proiectro_lead_payload', $payload, $fields, $source );
		return $this->outbox->enqueue( 'lead', $payload, $source );
	}

	/**
	 * Map arbitrary field names onto add_lead's fields; unknown fields are folded into notes so
	 * nothing the visitor typed is lost.
	 */
	public function to_payload( array $fields ) {
		$lower = array();
		foreach ( $fields as $key => $value ) {
			if ( is_array( $value ) ) {
				$value = implode( ', ', array_map( 'strval', $value ) );
			}
			$lower[ strtolower( trim( (string) $key ) ) ] = trim( wp_strip_all_tags( (string) $value ) );
		}

		$payload = array();
		$used    = array();
		foreach ( self::FIELDS as $target => $aliases ) {
			foreach ( $aliases as $alias ) {
				if ( isset( $lower[ $alias ] ) && '' !== $lower[ $alias ] ) {
					$payload[ $target ] = $lower[ $alias ];
					$used[ $alias ]     = true;
					break;
				}
			}
		}
		if ( ! isset( $payload['name'] ) ) {
			$first = isset( $lower['first_name'] ) ? $lower['first_name'] : ( isset( $lower['first-name'] ) ? $lower['first-name'] : '' );
			$last  = isset( $lower['last_name'] ) ? $lower['last_name'] : ( isset( $lower['last-name'] ) ? $lower['last-name'] : '' );
			$payload['name'] = trim( $first . ' ' . $last );
			$used['first_name'] = $used['first-name'] = $used['last_name'] = $used['last-name'] = true;
		}

		$extra = array();
		foreach ( $lower as $key => $value ) {
			if ( isset( $used[ $key ] ) || '' === $value || in_array( $key, array( '_wpnonce', '_wp_http_referer', 'action', 'submit' ), true ) || 0 === strpos( $key, '_' ) ) {
				continue;
			}
			$extra[] = $key . ': ' . $value;
		}
		if ( $extra ) {
			$payload['notes'] = trim( ( isset( $payload['notes'] ) ? $payload['notes'] . "\n\n" : '' ) . implode( "\n", $extra ) );
		}

		$payload['name']   = isset( $payload['name'] ) ? substr( $payload['name'], 0, 255 ) : '';
		$payload['source'] = $this->settings->get( 'lead_source' );
		$list = $this->settings->get( 'lead_list' );
		if ( '' !== $list ) {
			$payload['lead_list'] = $list;
		}
		if ( isset( $payload['email'] ) && ! is_email( $payload['email'] ) ) {
			$payload['notes'] = trim( ( isset( $payload['notes'] ) ? $payload['notes'] . "\n" : '' ) . 'email: ' . $payload['email'] );
			unset( $payload['email'] );
		}
		return $payload;
	}

	// ── Shortcode form ──────────────────────────────────────────────────────

	public function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'button'  => __( 'Send', 'proiectro' ),
				'company' => 'yes',
				'phone'   => 'yes',
				'message' => 'yes',
			),
			$atts,
			'proiectro_lead_form'
		);
		$sent = isset( $_GET['proiectro'] ) && 'sent' === $_GET['proiectro']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		ob_start();
		if ( $sent ) {
			echo '<p class="proiectro-lead-form__thanks">' . esc_html__( 'Thank you — we will be in touch.', 'proiectro' ) . '</p>';
		}
		?>
		<form class="proiectro-lead-form" method="post" action="">
			<?php wp_nonce_field( 'proiectro_lead_form', 'proiectro_nonce' ); ?>
			<input type="hidden" name="proiectro_lead_form" value="1" />
			<p style="position:absolute;left:-9999px;" aria-hidden="true"><label>Website <input type="text" name="proiectro_hp" tabindex="-1" autocomplete="off" /></label></p>
			<p><label><?php esc_html_e( 'Name', 'proiectro' ); ?><br /><input type="text" name="proiectro[name]" required /></label></p>
			<p><label><?php esc_html_e( 'Email', 'proiectro' ); ?><br /><input type="email" name="proiectro[email]" required /></label></p>
			<?php if ( 'yes' === $atts['company'] ) : ?><p><label><?php esc_html_e( 'Company', 'proiectro' ); ?><br /><input type="text" name="proiectro[company_name]" /></label></p><?php endif; ?>
			<?php if ( 'yes' === $atts['phone'] ) : ?><p><label><?php esc_html_e( 'Phone', 'proiectro' ); ?><br /><input type="tel" name="proiectro[phone]" /></label></p><?php endif; ?>
			<?php if ( 'yes' === $atts['message'] ) : ?><p><label><?php esc_html_e( 'Message', 'proiectro' ); ?><br /><textarea name="proiectro[notes]" rows="4"></textarea></label></p><?php endif; ?>
			<p><button type="submit"><?php echo esc_html( $atts['button'] ); ?></button></p>
		</form>
		<?php
		return ob_get_clean();
	}

	public function handle_shortcode_post() {
		if ( empty( $_POST['proiectro_lead_form'] ) ) {
			return;
		}
		if ( ! isset( $_POST['proiectro_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['proiectro_nonce'] ) ), 'proiectro_lead_form' ) ) {
			return;
		}
		if ( ! empty( $_POST['proiectro_hp'] ) ) {
			return; // honeypot
		}
		// Fields are namespaced (proiectro[name]) so they cannot collide with WordPress query
		// vars — a bare `name` parameter is a post slug lookup and turns the request into a 404.
		$posted = isset( $_POST['proiectro'] ) && is_array( $_POST['proiectro'] ) ? wp_unslash( $_POST['proiectro'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$fields = array();
		foreach ( array( 'name', 'email', 'company_name', 'phone', 'notes' ) as $key ) {
			if ( isset( $posted[ $key ] ) ) {
				$fields[ $key ] = sanitize_textarea_field( $posted[ $key ] );
			}
		}
		$this->capture( $fields, 'shortcode' );
		$back = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : home_url( '/' );
		wp_safe_redirect( add_query_arg( 'proiectro', 'sent', remove_query_arg( 'proiectro', $back ) ) );
		exit;
	}
}
