<?php
/**
 * The outbox: every submission is stored locally first and only then sent. Delivery is
 * attempted immediately; whatever fails is retried by WP-Cron with exponential backoff until
 * it succeeds or the payload is proven wrong. A dropped lead is the one failure a site owner
 * never forgives, and WordPress hosting is not reliable enough to send-and-forget.
 *
 * Table: {prefix}proiectro_outbox
 *   status  pending -> sent | failed      (failed = a 4xx we will not retry, or attempts exhausted)
 *   kind    which API operation the payload targets ('lead' -> add_lead)
 *
 * @package Proiectro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Proiectro_Outbox {

	const ADMIN_PAGE   = 'proiectro-outbox';
	const MAX_ATTEMPTS = 12;                 // ~ 5 min, 10, 20 … capped at 6 h: about two days
	const BATCH        = 25;

	/** @var Proiectro_Api */
	private $api;

	public function __construct( Proiectro_Api $api ) {
		$this->api = $api;
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'proiectro_outbox';
	}

	public static function install_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				kind VARCHAR(32) NOT NULL,
				source VARCHAR(64) NOT NULL DEFAULT '',
				payload LONGTEXT NOT NULL,
				status VARCHAR(16) NOT NULL DEFAULT 'pending',
				attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
				next_attempt_at DATETIME NULL,
				last_error TEXT NULL,
				response_id VARCHAR(64) NULL,
				created_at DATETIME NOT NULL,
				sent_at DATETIME NULL,
				PRIMARY KEY  (id),
				KEY status_next (status, next_attempt_at),
				KEY created_at (created_at)
			) {$charset};"
		);
	}

	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_proiectro_outbox_action', array( $this, 'handle_admin_action' ) );
	}

	// ── Writing ─────────────────────────────────────────────────────────────

	/**
	 * Store a payload and try to send it right away. Returns the outbox row id; the caller
	 * (a form hook) never needs to know whether delivery already happened.
	 */
	public function enqueue( $kind, array $payload, $source = '' ) {
		global $wpdb;
		$wpdb->insert(
			self::table(),
			array(
				'kind'            => $kind,
				'source'          => substr( (string) $source, 0, 64 ),
				'payload'         => wp_json_encode( $payload ),
				'status'          => 'pending',
				'attempts'        => 0,
				'next_attempt_at' => current_time( 'mysql', true ),
				'created_at'      => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		$id = (int) $wpdb->insert_id;

		/**
		 * Immediate delivery. Filter to false on sites that prefer to send only from cron
		 * (keeps form submissions fast when the API is slow).
		 */
		if ( apply_filters( 'proiectro_outbox_send_immediately', true, $kind, $payload ) ) {
			$this->deliver( $id );
		}
		return $id;
	}

	// ── Sending ─────────────────────────────────────────────────────────────

	/**
	 * Cron entry point: send everything that is due, oldest first.
	 */
	public function flush() {
		global $wpdb;
		$table = self::table();
		$ids   = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE status = 'pending' AND (next_attempt_at IS NULL OR next_attempt_at <= %s) ORDER BY id ASC LIMIT %d",
				current_time( 'mysql', true ),
				self::BATCH
			)
		);
		foreach ( $ids as $id ) {
			$this->deliver( (int) $id );
		}
	}

	/**
	 * One delivery attempt for one row. Success marks it sent; a retryable failure schedules the
	 * next attempt with backoff; a non-retryable failure (bad payload, rejected key) marks it
	 * failed so a human can look at it and requeue after fixing the cause.
	 */
	public function deliver( $id ) {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		if ( ! $row || 'pending' !== $row['status'] ) {
			return false;
		}
		// Claim the row so a concurrent cron run does not send it twice.
		$claimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET attempts = attempts + 1, next_attempt_at = %s WHERE id = %d AND attempts = %d",
				gmdate( 'Y-m-d H:i:s', time() + 5 * MINUTE_IN_SECONDS ),
				$id,
				(int) $row['attempts']
			)
		);
		if ( 1 !== $claimed ) {
			return false;
		}
		$attempt = (int) $row['attempts'] + 1;
		$payload = json_decode( $row['payload'], true );
		$result  = $this->send( $row['kind'], is_array( $payload ) ? $payload : array() );

		if ( ! is_wp_error( $result ) ) {
			$wpdb->update(
				$table,
				array(
					'status'      => 'sent',
					'sent_at'     => current_time( 'mysql', true ),
					'last_error'  => null,
					'response_id' => isset( $result['response_id'] ) ? substr( (string) $result['response_id'], 0, 64 ) : null,
				),
				array( 'id' => $id ),
				array( '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
			do_action( 'proiectro_outbox_sent', $id, $row['kind'], $payload, $result );
			return true;
		}

		$retry = Proiectro_Api::is_retryable( $result ) && $attempt < self::MAX_ATTEMPTS;
		$wpdb->update(
			$table,
			array(
				'status'          => $retry ? 'pending' : 'failed',
				'next_attempt_at' => $retry ? gmdate( 'Y-m-d H:i:s', time() + self::backoff( $attempt ) ) : null,
				'last_error'      => substr( $result->get_error_message(), 0, 2000 ),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
		do_action( 'proiectro_outbox_failed', $id, $row['kind'], $payload, $result, $retry );
		return false;
	}

	/**
	 * 5 min, 10, 20, 40 … capped at 6 hours.
	 */
	public static function backoff( $attempt ) {
		return min( 6 * HOUR_IN_SECONDS, 5 * MINUTE_IN_SECONDS * ( 2 ** max( 0, $attempt - 1 ) ) );
	}

	/**
	 * Map a kind to its API call. Adding an integration means adding a case here.
	 *
	 * @return array|WP_Error
	 */
	private function send( $kind, array $payload ) {
		switch ( $kind ) {
			case 'lead':
				return $this->api->post( 'add_lead', $payload );
			default:
				return new WP_Error( 'http_400', sprintf( 'Unknown outbox kind "%s".', $kind ), array( 'status' => 400 ) );
		}
	}

	// ── Reading ─────────────────────────────────────────────────────────────

	public function counts() {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", ARRAY_A );
		$out   = array( 'pending' => 0, 'sent' => 0, 'failed' => 0 );
		foreach ( (array) $rows as $r ) {
			$out[ $r['status'] ] = (int) $r['n'];
		}
		return $out;
	}

	public function requeue( $id ) {
		global $wpdb;
		return (bool) $wpdb->update(
			self::table(),
			array( 'status' => 'pending', 'attempts' => 0, 'next_attempt_at' => current_time( 'mysql', true ), 'last_error' => null ),
			array( 'id' => (int) $id ),
			array( '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);
	}

	public function delete( $id ) {
		global $wpdb;
		return (bool) $wpdb->delete( self::table(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	// ── Admin screen ────────────────────────────────────────────────────────

	public function add_menu() {
		add_submenu_page(
			null, // no menu entry of its own; reached from the settings page
			__( 'Proiect.ro outbox', 'proiectro' ),
			__( 'Proiect.ro outbox', 'proiectro' ),
			Proiectro_Settings::CAPABILITY,
			self::ADMIN_PAGE,
			array( $this, 'render_page' )
		);
	}

	public function handle_admin_action() {
		if ( ! current_user_can( Proiectro_Settings::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'proiectro' ) );
		}
		check_admin_referer( 'proiectro_outbox_action' );
		$id  = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$do  = isset( $_POST['do'] ) ? sanitize_key( $_POST['do'] ) : '';
		if ( 'flush' === $do ) {
			$this->flush();
		} elseif ( $id && 'retry' === $do ) {
			$this->requeue( $id );
			$this->deliver( $id );
		} elseif ( $id && 'delete' === $do ) {
			$this->delete( $id );
		}
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::ADMIN_PAGE ) );
		exit;
	}

	public function render_page() {
		if ( ! current_user_can( Proiectro_Settings::CAPABILITY ) ) {
			return;
		}
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 200", ARRAY_A );
		$action_url = admin_url( 'admin-post.php' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Proiect.ro outbox', 'proiectro' ); ?></h1>
			<p><a href="<?php echo esc_url( admin_url( 'options-general.php?page=' . Proiectro_Settings::PAGE ) ); ?>">&larr; <?php esc_html_e( 'Settings', 'proiectro' ); ?></a></p>
			<form method="post" action="<?php echo esc_url( $action_url ); ?>" style="margin-bottom:1em;">
				<input type="hidden" name="action" value="proiectro_outbox_action" /><input type="hidden" name="do" value="flush" />
				<?php wp_nonce_field( 'proiectro_outbox_action' ); ?>
				<?php submit_button( __( 'Send everything that is due now', 'proiectro' ), 'secondary', 'submit', false ); ?>
			</form>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'ID', 'proiectro' ); ?></th>
					<th><?php esc_html_e( 'Created', 'proiectro' ); ?></th>
					<th><?php esc_html_e( 'Kind', 'proiectro' ); ?></th>
					<th><?php esc_html_e( 'Source', 'proiectro' ); ?></th>
					<th><?php esc_html_e( 'Status', 'proiectro' ); ?></th>
					<th><?php esc_html_e( 'Attempts', 'proiectro' ); ?></th>
					<th><?php esc_html_e( 'Last error', 'proiectro' ); ?></th>
					<th><?php esc_html_e( 'Payload', 'proiectro' ); ?></th>
					<th></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="9"><?php esc_html_e( 'Nothing here yet.', 'proiectro' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( (array) $rows as $r ) : ?>
					<tr>
						<td><?php echo (int) $r['id']; ?></td>
						<td><?php echo esc_html( get_date_from_gmt( $r['created_at'], 'Y-m-d H:i' ) ); ?></td>
						<td><?php echo esc_html( $r['kind'] ); ?></td>
						<td><?php echo esc_html( $r['source'] ); ?></td>
						<td><?php echo esc_html( $r['status'] ); ?></td>
						<td><?php echo (int) $r['attempts']; ?></td>
						<td><?php echo esc_html( (string) $r['last_error'] ); ?></td>
						<td><code style="font-size:11px;"><?php echo esc_html( wp_trim_words( (string) $r['payload'], 20, '…' ) ); ?></code></td>
						<td>
							<?php if ( 'sent' !== $r['status'] ) : ?>
							<form method="post" action="<?php echo esc_url( $action_url ); ?>" style="display:inline">
								<input type="hidden" name="action" value="proiectro_outbox_action" /><input type="hidden" name="do" value="retry" /><input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>" />
								<?php wp_nonce_field( 'proiectro_outbox_action' ); ?>
								<button class="button-link"><?php esc_html_e( 'Retry', 'proiectro' ); ?></button>
							</form>
							<?php endif; ?>
							<form method="post" action="<?php echo esc_url( $action_url ); ?>" style="display:inline">
								<input type="hidden" name="action" value="proiectro_outbox_action" /><input type="hidden" name="do" value="delete" /><input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>" />
								<?php wp_nonce_field( 'proiectro_outbox_action' ); ?>
								<button class="button-link-delete"><?php esc_html_e( 'Delete', 'proiectro' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
