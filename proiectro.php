<?php
/**
 * Plugin Name:       Proiect.ro
 * Plugin URI:        https://proiect.ro
 * Description:       Send leads from your WordPress forms to your Proiect.ro workspace — reliably, with a local outbox that retries until every lead is delivered.
 * Version:           0.1.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Proiect.ro
 * Author URI:        https://proiect.ro
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       proiectro
 * Domain Path:       /languages
 *
 * @package Proiectro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PROIECTRO_VERSION', '0.1.0' );
define( 'PROIECTRO_PLUGIN_FILE', __FILE__ );
define( 'PROIECTRO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PROIECTRO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once PROIECTRO_PLUGIN_DIR . 'includes/class-proiectro-settings.php';
require_once PROIECTRO_PLUGIN_DIR . 'includes/class-proiectro-api.php';
require_once PROIECTRO_PLUGIN_DIR . 'includes/class-proiectro-outbox.php';
require_once PROIECTRO_PLUGIN_DIR . 'includes/class-proiectro-leads.php';
require_once PROIECTRO_PLUGIN_DIR . 'includes/class-proiectro-form-bridges.php';
require_once PROIECTRO_PLUGIN_DIR . 'includes/class-proiectro-plugin.php';

// Registered at file load, not from the instance: the activation hook needs the schedule
// before plugins_loaded has run for a freshly activated plugin.
add_filter( 'cron_schedules', array( 'Proiectro_Plugin', 'cron_schedules' ) );

register_activation_hook( __FILE__, array( 'Proiectro_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Proiectro_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'Proiectro_Plugin', 'instance' ) );
