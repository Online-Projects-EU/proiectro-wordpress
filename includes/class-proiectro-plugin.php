<?php
/**
 * Plugin bootstrap: wires the settings screen, the outbox and its cron, and the lead intake.
 *
 * @package Proiectro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Proiectro_Plugin {

	const CRON_HOOK = 'proiectro_flush_outbox';

	/** @var Proiectro_Plugin|null */
	private static $instance = null;

	/** @var Proiectro_Settings */
	public $settings;

	/** @var Proiectro_Api */
	public $api;

	/** @var Proiectro_Outbox */
	public $outbox;

	/** @var Proiectro_Leads */
	public $leads;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->settings = new Proiectro_Settings();
		$this->api      = new Proiectro_Api( $this->settings );
		$this->outbox   = new Proiectro_Outbox( $this->api );
		$this->leads    = new Proiectro_Leads( $this->outbox, $this->settings );

		load_plugin_textdomain( 'proiectro', false, dirname( plugin_basename( PROIECTRO_PLUGIN_FILE ) ) . '/languages' );

		$this->settings->register();
		$this->outbox->register();
		$this->leads->register();

		add_action( self::CRON_HOOK, array( $this->outbox, 'flush' ) );
		add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );
	}

	/**
	 * Activation: create the outbox table and schedule the flush.
	 */
	public static function activate() {
		Proiectro_Outbox::install_table();
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, 'proiectro_five_minutes', self::CRON_HOOK );
		}
		update_option( 'proiectro_db_version', PROIECTRO_VERSION );
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * A five-minute schedule for the outbox. Immediate delivery is attempted on submit; the
	 * cron only exists to retry what failed and to drain a backlog after an outage.
	 */
	public static function cron_schedules( $schedules ) {
		if ( ! isset( $schedules['proiectro_five_minutes'] ) ) {
			$schedules['proiectro_five_minutes'] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every five minutes (Proiect.ro outbox)', 'proiectro' ),
			);
		}
		return $schedules;
	}

	/**
	 * Keep the table current after an update installed without re-activation.
	 */
	public function maybe_upgrade() {
		if ( get_option( 'proiectro_db_version' ) !== PROIECTRO_VERSION || ! wp_next_scheduled( self::CRON_HOOK ) ) {
			self::activate();
		}
	}
}
