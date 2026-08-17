<?php
/**
 * Bridges to the form plugins people actually use. Each one listens to that plugin's own
 * "submission complete" hook and hands the fields to Proiectro_Leads::capture(); nothing is
 * bundled or required — a bridge stays inert unless its plugin is active.
 *
 *   Contact Form 7 ..... wpcf7_submit (mail_sent or mail_failed — a broken mailer must not drop a lead)
 *   WPForms ............ wpforms_process_complete
 *   Gravity Forms ...... gform_after_submission
 *
 * Which forms feed Proiect.ro is decided in Settings > Proiect.ro > Forms: by default none, so
 * installing the plugin never silently turns every form on the site into a lead.
 *
 * @package Proiectro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Proiectro_Form_Bridges {

	const OPTION = 'proiectro_forms';

	/** @var Proiectro_Leads */
	private $leads;

	public function __construct( Proiectro_Leads $leads ) {
		$this->leads = $leads;
	}

	public function register() {
		add_action( 'wpcf7_submit', array( $this, 'cf7' ), 10, 2 );
		add_action( 'wpforms_process_complete', array( $this, 'wpforms' ), 10, 4 );
		add_action( 'gform_after_submission', array( $this, 'gravity' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	// ── Which forms are enabled ─────────────────────────────────────────────

	/**
	 * Enabled forms, keyed "cf7:123" / "wpforms:45" / "gravity:7".
	 */
	public function enabled() {
		$stored = get_option( self::OPTION, array() );
		return is_array( $stored ) ? $stored : array();
	}

	public function is_enabled( $provider, $form_id ) {
		$enabled = $this->enabled();
		return ! empty( $enabled[ $provider . ':' . $form_id ] );
	}

	// ── The bridges ─────────────────────────────────────────────────────────

	/**
	 * Contact Form 7. Fires once the submission was processed, with the form object and the
	 * result; the posted values come from the current submission. CF7's defaults are
	 * `your-name`, `your-email`, `your-subject`, `your-message` — all mapped by the alias table.
	 * Spam and validation failures are not leads; a failed notification email still is.
	 */
	public function cf7( $contact_form, $result = array() ) {
		if ( ! is_object( $contact_form ) || ! method_exists( $contact_form, 'id' ) ) {
			return;
		}
		$status = is_array( $result ) && isset( $result['status'] ) ? $result['status'] : '';
		if ( ! in_array( $status, array( 'mail_sent', 'mail_failed' ), true ) ) {
			return;
		}
		if ( ! $this->is_enabled( 'cf7', $contact_form->id() ) ) {
			return;
		}
		if ( ! class_exists( 'WPCF7_Submission' ) ) {
			return;
		}
		$submission = WPCF7_Submission::get_instance();
		if ( ! $submission ) {
			return;
		}
		$fields = $submission->get_posted_data();
		if ( ! is_array( $fields ) ) {
			return;
		}
		$fields['your-subject'] = isset( $fields['your-subject'] ) ? $fields['your-subject'] : '';
		$this->leads->capture( $fields, 'cf7:' . $contact_form->id() . ' ' . $contact_form->title() );
	}

	/**
	 * WPForms. `$fields` is keyed by field id with name / value / type; the field name is what
	 * the site owner labelled it ("Name", "Email", "Company") — lowercased it hits the aliases.
	 * A "name" field with first/last sub-fields arrives already joined in `value`.
	 */
	public function wpforms( $fields, $entry, $form_data, $entry_id ) {
		$form_id = isset( $form_data['id'] ) ? (int) $form_data['id'] : 0;
		if ( ! $form_id || ! $this->is_enabled( 'wpforms', $form_id ) ) {
			return;
		}
		$flat = array();
		foreach ( (array) $fields as $field ) {
			if ( ! is_array( $field ) || ! isset( $field['name'] ) ) {
				continue;
			}
			$key   = strtolower( trim( (string) $field['name'] ) );
			$value = isset( $field['value'] ) ? $field['value'] : '';
			// Typed fields are more reliable than labels: an "email" field is the email whatever
			// it was called.
			$type = isset( $field['type'] ) ? $field['type'] : '';
			if ( 'email' === $type ) {
				$key = 'email';
			} elseif ( 'phone' === $type ) {
				$key = 'phone';
			} elseif ( 'name' === $type ) {
				$key = 'name';
			}
			$flat[ $key ] = $value;
		}
		$title = isset( $form_data['settings']['form_title'] ) ? $form_data['settings']['form_title'] : '';
		$this->leads->capture( $flat, 'wpforms:' . $form_id . ' ' . $title );
	}

	/**
	 * Gravity Forms. `$entry` is keyed by field id (and "id.sub" for multi-input fields such as
	 * name and address); labels and types come from `$form['fields']`.
	 */
	public function gravity( $entry, $form ) {
		$form_id = isset( $form['id'] ) ? (int) $form['id'] : 0;
		if ( ! $form_id || ! $this->is_enabled( 'gravity', $form_id ) ) {
			return;
		}
		$flat = array();
		foreach ( (array) ( isset( $form['fields'] ) ? $form['fields'] : array() ) as $field ) {
			$id    = is_object( $field ) ? $field->id : ( isset( $field['id'] ) ? $field['id'] : null );
			$type  = is_object( $field ) ? $field->type : ( isset( $field['type'] ) ? $field['type'] : '' );
			$label = is_object( $field ) ? $field->label : ( isset( $field['label'] ) ? $field['label'] : '' );
			if ( null === $id ) {
				continue;
			}
			$inputs = is_object( $field ) ? $field->inputs : ( isset( $field['inputs'] ) ? $field['inputs'] : null );
			if ( is_array( $inputs ) && $inputs ) {
				$parts = array();
				foreach ( $inputs as $input ) {
					$iid = isset( $input['id'] ) ? (string) $input['id'] : '';
					if ( '' !== $iid && isset( $entry[ $iid ] ) && '' !== $entry[ $iid ] ) {
						$parts[] = $entry[ $iid ];
					}
				}
				$value = implode( ' ', $parts );
			} else {
				$value = isset( $entry[ (string) $id ] ) ? $entry[ (string) $id ] : '';
			}
			$key = strtolower( trim( (string) $label ) );
			if ( 'email' === $type ) {
				$key = 'email';
			} elseif ( 'phone' === $type ) {
				$key = 'phone';
			} elseif ( 'name' === $type ) {
				$key = 'name';
			} elseif ( 'website' === $type ) {
				$key = 'website';
			}
			if ( '' !== $key ) {
				$flat[ $key ] = $value;
			}
		}
		$title = isset( $form['title'] ) ? $form['title'] : '';
		$this->leads->capture( $flat, 'gravity:' . $form_id . ' ' . $title );
	}

	// ── Settings: pick the forms ────────────────────────────────────────────

	public function register_settings() {
		register_setting(
			'proiectro_settings_group',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => array(),
			)
		);
		add_settings_section( 'proiectro_forms', __( 'Forms', 'proiectro' ), array( $this, 'section' ), Proiectro_Settings::PAGE );
		add_settings_field( 'forms', __( 'Send these forms to Proiect.ro', 'proiectro' ), array( $this, 'field' ), Proiectro_Settings::PAGE, 'proiectro_forms' );
	}

	public function sanitize( $input ) {
		$clean = array();
		foreach ( (array) $input as $key => $on ) {
			if ( preg_match( '/^(cf7|wpforms|gravity):\d+$/', (string) $key ) && $on ) {
				$clean[ $key ] = 1;
			}
		}
		return $clean;
	}

	public function section() {
		echo '<p>' . esc_html__( 'Tick the forms whose submissions should become leads. Forms from Contact Form 7, WPForms and Gravity Forms are listed when those plugins are active.', 'proiectro' ) . '</p>';
	}

	public function field() {
		$forms   = $this->available_forms();
		$enabled = $this->enabled();
		if ( ! $forms ) {
			echo '<p class="description">' . esc_html__( 'No supported form plugin is active, or it has no forms yet. The shortcode [proiectro_lead_form] works without one.', 'proiectro' ) . '</p>';
			return;
		}
		foreach ( $forms as $key => $label ) {
			printf(
				'<label style="display:block;margin-bottom:.35em;"><input type="checkbox" name="%s[%s]" value="1"%s /> %s</label>',
				esc_attr( self::OPTION ),
				esc_attr( $key ),
				checked( ! empty( $enabled[ $key ] ), true, false ),
				esc_html( $label )
			);
		}
	}

	/**
	 * Every form of every active supported plugin, keyed like enabled().
	 */
	public function available_forms() {
		$forms = array();
		if ( defined( 'WPCF7_VERSION' ) && class_exists( 'WPCF7_ContactForm' ) ) {
			foreach ( WPCF7_ContactForm::find( array( 'posts_per_page' => 200 ) ) as $cf ) {
				$forms[ 'cf7:' . $cf->id() ] = 'Contact Form 7 — ' . $cf->title();
			}
		}
		if ( function_exists( 'wpforms' ) && is_object( wpforms()->form ) ) {
			$list = wpforms()->form->get( '', array( 'orderby' => 'title', 'order' => 'ASC' ) );
			foreach ( (array) $list as $post ) {
				if ( is_object( $post ) && isset( $post->ID ) ) {
					$forms[ 'wpforms:' . $post->ID ] = 'WPForms — ' . $post->post_title;
				}
			}
		}
		if ( class_exists( 'GFAPI' ) ) {
			foreach ( (array) GFAPI::get_forms() as $gf ) {
				if ( isset( $gf['id'] ) ) {
					$forms[ 'gravity:' . $gf['id'] ] = 'Gravity Forms — ' . $gf['title'];
				}
			}
		}
		return $forms;
	}
}
