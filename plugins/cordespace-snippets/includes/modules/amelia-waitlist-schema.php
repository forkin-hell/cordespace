<?php
/**
 * Module amelia.waitlist-schema — tables du module « liste d'attente ».
 *
 * Deux tables :
 *  - {prefix}cordespace_waitlist_state : dernier état connu des places libres par cours et
 *    par bassin (billet ou cours entier), pour la règle d'annonce « par état »
 *    (on annonce quand un bassin passe de 0 place libre à au moins 1).
 *  - {prefix}cordespace_waitlist_log : journal (annonces envoyées, retraits, ménage),
 *    sert aussi de garde-fou (pas deux annonces à la même inscription en moins d'une heure).
 *
 * Self-heal au admin_init (comme waivers-schema.php) : couvre les mises à jour via WP Pusher.
 * Voir docs/superpowers/specs/2026-10-21-liste-attente-design.md (§6).
 */
defined( 'ABSPATH' ) || exit;

const CORDESPACE_WAITLIST_SCHEMA_VERSION = 1;

function cordespace_waitlist_state_table(): string {
	global $wpdb;
	return $wpdb->prefix . 'cordespace_waitlist_state';
}

function cordespace_waitlist_log_table(): string {
	global $wpdb;
	return $wpdb->prefix . 'cordespace_waitlist_log';
}

function cordespace_waitlist_install_tables(): void {
	global $wpdb;
	$state           = cordespace_waitlist_state_table();
	$log             = cordespace_waitlist_log_table();
	$charset_collate = $wpdb->get_charset_collate();

	$sql_state = "CREATE TABLE {$state} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		event_id BIGINT UNSIGNED NOT NULL,
		pool_key VARCHAR(40) NOT NULL,
		free_spots INT NOT NULL DEFAULT 0,
		checked_at DATETIME NOT NULL,
		announced_at DATETIME NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY uq_event_pool (event_id, pool_key)
	) {$charset_collate};";

	$sql_log = "CREATE TABLE {$log} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		event_id BIGINT UNSIGNED NOT NULL,
		pool_key VARCHAR(40) NOT NULL DEFAULT '',
		booking_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		email VARCHAR(255) NOT NULL DEFAULT '',
		kind VARCHAR(20) NOT NULL,
		created_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		KEY idx_booking_kind (booking_id, kind, created_at),
		KEY idx_event (event_id)
	) {$charset_collate};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql_state );
	dbDelta( $sql_log );
	update_option( 'cordespace_waitlist_schema_version', CORDESPACE_WAITLIST_SCHEMA_VERSION );
}

add_action( 'admin_init', static function (): void {
	$stored = (int) get_option( 'cordespace_waitlist_schema_version', 0 );
	if ( $stored < CORDESPACE_WAITLIST_SCHEMA_VERSION ) {
		cordespace_waitlist_install_tables();
	}
} );
