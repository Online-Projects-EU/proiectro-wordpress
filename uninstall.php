<?php
/**
 * Remove everything the plugin stored: settings, the outbox table, and its cron.
 *
 * @package Proiectro
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'proiectro_outbox' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
delete_option( 'proiectro_settings' );
delete_option( 'proiectro_db_version' );
delete_transient( 'proiectro_notice' );
wp_clear_scheduled_hook( 'proiectro_flush_outbox' );
