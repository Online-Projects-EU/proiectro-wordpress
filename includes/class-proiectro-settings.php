<?php
/**
 * Settings: connection (App URL, workspace path, API key), lead defaults, and the connection
 * test that also reports whether the key is broader than the plugin needs.
 *
 * @package Proiectro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Proiectro_Settings {

	const OPTION     = 'proiectro_settings';
	const PAGE       = 'proiectro';
	const CAPABILITY = 'manage_options';

	/** @var array|null */
	private $cache = null;

	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_proiectro_test_connection', array( $this, 'handle_test_connection' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
	}

	/**
	 * Every setting with its default. Read through get() so callers never see a missing key.
	 */
	public static function defaults() {
		return array(
			'base_url'       => 'https://proiect.ro',
			'workspace_path' => '',
			'api_key'        => '',
			'lead_list'      => '',
			'lead_source'    => 'web_form',
			'connection_ok'  => false,
			'key_can_read'   => null,
		);
	}

	public function all() {
		if ( null === $this->cache ) {
			$stored      = get_option( self::OPTION, array() );
			$this->cache = wp_parse_args( is_array( $stored ) ? $stored : array(), self::defaults() );
		}
		return $this->cache;
	}

	public function get( $key ) {
		$all = $this->all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public function update( array $values ) {
		$all         = array_merge( $this->all(), $values );
		$this->cache = $all;
		update_option( self::OPTION, $all, false );
	}

	public function is_configured() {
		return '' !== $this->get( 'workspace_path' ) && '' !== $this->get( 'api_key' );
	}

	// ── Admin UI ────────────────────────────────────────────────────────────

	public function add_menu() {
		add_options_page(
			__( 'Proiect.ro', 'proiectro' ),
			__( 'Proiect.ro', 'proiectro' ),
			self::CAPABILITY,
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	public function register_settings() {
		register_setting(
			'proiectro_settings_group',
			self::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);

		add_settings_section( 'proiectro_connection', __( 'Connection', 'proiectro' ), array( $this, 'section_connection' ), self::PAGE );
		add_settings_field( 'base_url', __( 'App URL', 'proiectro' ), array( $this, 'field_base_url' ), self::PAGE, 'proiectro_connection' );
		add_settings_field( 'workspace_path', __( 'Workspace path', 'proiectro' ), array( $this, 'field_workspace_path' ), self::PAGE, 'proiectro_connection' );
		add_settings_field( 'api_key', __( 'API key', 'proiectro' ), array( $this, 'field_api_key' ), self::PAGE, 'proiectro_connection' );

		add_settings_section( 'proiectro_leads', __( 'Leads', 'proiectro' ), array( $this, 'section_leads' ), self::PAGE );
		add_settings_field( 'lead_list', __( 'Lead list', 'proiectro' ), array( $this, 'field_lead_list' ), self::PAGE, 'proiectro_leads' );
		add_settings_field( 'lead_source', __( 'Lead source', 'proiectro' ), array( $this, 'field_lead_source' ), self::PAGE, 'proiectro_leads' );
	}

	public function sanitize( $input ) {
		$current = $this->all();
		$input   = is_array( $input ) ? $input : array();
		$clean   = $current;

		$clean['base_url']       = untrailingslashit( esc_url_raw( isset( $input['base_url'] ) ? $input['base_url'] : $current['base_url'] ) );
		$clean['workspace_path'] = sanitize_key( isset( $input['workspace_path'] ) ? $input['workspace_path'] : $current['workspace_path'] );
		$clean['lead_list']      = preg_replace( '/[^a-f0-9]/', '', isset( $input['lead_list'] ) ? strtolower( $input['lead_list'] ) : $current['lead_list'] );
		$clean['lead_source']    = in_array( $input['lead_source'] ?? '', self::lead_sources(), true ) ? $input['lead_source'] : $current['lead_source'];

		// The key field is rendered empty; a blank submission means "keep the stored key".
		if ( isset( $input['api_key'] ) && '' !== trim( $input['api_key'] ) ) {
			$clean['api_key'] = trim( sanitize_text_field( $input['api_key'] ) );
		}
		if ( $clean['api_key'] !== $current['api_key'] || $clean['base_url'] !== $current['base_url'] || $clean['workspace_path'] !== $current['workspace_path'] ) {
			$clean['connection_ok'] = false;
			$clean['key_can_read']  = null;
		}
		$this->cache = null;
		return $clean;
	}

	public static function lead_sources() {
		return array( 'web_form', 'referral', 'event', 'linkedin', 'cold_email', 'other' );
	}

	public function section_connection() {
		echo '<p>' . esc_html__( 'Create an API key in your workspace under Settings > API Keys. Give it only the "create" permission and an expiry date: that is all this plugin needs, and a website is not the place to keep a key that can read your workspace.', 'proiectro' ) . '</p>';
	}

	public function section_leads() {
		echo '<p>' . esc_html__( 'Defaults applied to every lead this site sends. Form integrations can override them per form.', 'proiectro' ) . '</p>';
	}

	public function field_base_url() {
		printf( '<input type="url" class="regular-text code" name="%s[base_url]" value="%s" />', esc_attr( self::OPTION ), esc_attr( $this->get( 'base_url' ) ) );
		echo '<p class="description">' . esc_html__( 'Change only for a self-hosted instance.', 'proiectro' ) . '</p>';
	}

	public function field_workspace_path() {
		printf( '<input type="text" class="regular-text code" name="%s[workspace_path]" value="%s" placeholder="my-workspace" />', esc_attr( self::OPTION ), esc_attr( $this->get( 'workspace_path' ) ) );
		echo '<p class="description">' . esc_html__( 'The path segment in your workspace URL.', 'proiectro' ) . '</p>';
	}

	public function field_api_key() {
		$has = '' !== $this->get( 'api_key' );
		printf( '<input type="password" class="regular-text code" name="%s[api_key]" value="" autocomplete="new-password" placeholder="%s" />', esc_attr( self::OPTION ), $has ? esc_attr__( '•••••••• (stored — leave blank to keep)', 'proiectro' ) : '' );
	}

	public function field_lead_list() {
		printf( '<input type="text" class="regular-text code" name="%s[lead_list]" value="%s" />', esc_attr( self::OPTION ), esc_attr( $this->get( 'lead_list' ) ) );
		echo '<p class="description">' . esc_html__( 'Optional. The ID of the lead list new leads go to (from the list\'s URL in your workspace). Leave empty for the workspace default.', 'proiectro' ) . '</p>';
	}

	public function field_lead_source() {
		echo '<select name="' . esc_attr( self::OPTION ) . '[lead_source]">';
		foreach ( self::lead_sources() as $source ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $source ), selected( $this->get( 'lead_source' ), $source, false ), esc_html( $source ) );
		}
		echo '</select>';
	}

	public function render_page() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$outbox = Proiectro_Plugin::instance()->outbox;
		$counts = $outbox->counts();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Proiect.ro', 'proiectro' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'proiectro_settings_group' );
				do_settings_sections( self::PAGE );
				submit_button();
				?>
			</form>

			<h2><?php esc_html_e( 'Connection', 'proiectro' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="proiectro_test_connection" />
				<?php wp_nonce_field( 'proiectro_test_connection' ); ?>
				<p>
					<?php submit_button( __( 'Test connection', 'proiectro' ), 'secondary', 'submit', false ); ?>
					<?php if ( $this->get( 'connection_ok' ) ) : ?>
						<span style="margin-left:1em;color:#1a7f37;">&#10003; <?php esc_html_e( 'Connected', 'proiectro' ); ?></span>
					<?php endif; ?>
				</p>
			</form>

			<h2><?php esc_html_e( 'Outbox', 'proiectro' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: 1: pending count, 2: failed count, 3: sent count */
					esc_html__( '%1$d waiting to send, %2$d gave up after repeated failures, %3$d delivered.', 'proiectro' ),
					(int) $counts['pending'],
					(int) $counts['failed'],
					(int) $counts['sent']
				);
				?>
				<a href="<?php echo esc_url( admin_url( 'options-general.php?page=' . Proiectro_Outbox::ADMIN_PAGE ) ); ?>"><?php esc_html_e( 'View the outbox', 'proiectro' ); ?></a>
			</p>
		</div>
		<?php
	}

	/**
	 * "Test connection": GET me proves the credentials; GET lead_lists tells whether the key can
	 * read the workspace — which the plugin never needs, so a 200 there is reported as a warning.
	 */
	public function handle_test_connection() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'proiectro' ) );
		}
		check_admin_referer( 'proiectro_test_connection' );

		$api = Proiectro_Plugin::instance()->api;
		$me  = $api->get( 'me' );
		if ( is_wp_error( $me ) ) {
			$this->update( array( 'connection_ok' => false, 'key_can_read' => null ) );
			set_transient( 'proiectro_notice', array( 'type' => 'error', 'text' => $me->get_error_message() ), 60 );
		} else {
			$lists    = $api->get( 'lead_lists' );
			$can_read = ! is_wp_error( $lists );
			$this->update( array( 'connection_ok' => true, 'key_can_read' => $can_read ) );
			$text = sprintf(
				/* translators: 1: user name, 2: workspace name */
				__( 'Connected as %1$s to workspace %2$s.', 'proiectro' ),
				isset( $me['name'] ) ? $me['name'] : $me['email'],
				isset( $me['tenant_name'] ) ? $me['tenant_name'] : $this->get( 'workspace_path' )
			);
			if ( $can_read ) {
				$text .= ' ' . __( 'This key can also read your workspace. The plugin only needs "create" — consider replacing it with a create-only key.', 'proiectro' );
				set_transient( 'proiectro_notice', array( 'type' => 'warning', 'text' => $text ), 60 );
			} else {
				set_transient( 'proiectro_notice', array( 'type' => 'success', 'text' => $text ), 60 );
			}
		}
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE ) );
		exit;
	}

	public function admin_notices() {
		$notice = get_transient( 'proiectro_notice' );
		if ( ! $notice ) {
			return;
		}
		delete_transient( 'proiectro_notice' );
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $notice['type'] ), esc_html( $notice['text'] ) );
	}
}
