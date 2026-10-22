<?php
/**
 * Module waivers.partners — partenaires des réservations à plusieurs.
 *
 * Sur toute réservation Amelia à venir pour plus d'une personne, l'acheteur·euse déclare ses
 * partenaires (nom + courriel) dans Mon espace ; sur les cours à waiver, chaque partenaire reçoit
 * un courriel et signe elle-même, sans compte ; la prof voit les noms et qui n'a pas signé ; le
 * rappel 48 h couvre les partenaires.
 *
 * Table {prefix}cordespace_waiver_partners : une ligne par « place partenaire » (réservation × n°).
 * Voir docs/superpowers/specs/2026-10-22-waivers-partenaires-design.md.
 */
defined( 'ABSPATH' ) || exit;

const CORDESPACE_WAIVERS_PARTNERS_SCHEMA_VERSION = 1;

function cordespace_waivers_partners_table_name(): string {
	global $wpdb;
	return $wpdb->prefix . 'cordespace_waiver_partners';
}

function cordespace_waivers_partners_install_table(): void {
	global $wpdb;
	$table           = cordespace_waivers_partners_table_name();
	$charset_collate = $wpdb->get_charset_collate();
	$sql = "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		booking_id BIGINT UNSIGNED NOT NULL,
		slot_no SMALLINT UNSIGNED NOT NULL,
		event_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		host_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
		partner_name VARCHAR(255) NOT NULL DEFAULT '',
		partner_email VARCHAR(255) NOT NULL DEFAULT '',
		token VARCHAR(64) NOT NULL DEFAULT '',
		invited_at DATETIME NULL,
		reminded_at DATETIME NULL,
		declined_at DATETIME NULL,
		created_at DATETIME NOT NULL,
		updated_at DATETIME NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY uq_booking_slot (booking_id, slot_no),
		KEY idx_email (partner_email),
		KEY idx_event (event_id)
	) {$charset_collate};";
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
	update_option( 'cordespace_waivers_partners_schema_version', CORDESPACE_WAIVERS_PARTNERS_SCHEMA_VERSION );
}

add_action( 'admin_init', static function (): void {
	$stored = (int) get_option( 'cordespace_waivers_partners_schema_version', 0 );
	if ( $stored < CORDESPACE_WAIVERS_PARTNERS_SCHEMA_VERSION ) {
		cordespace_waivers_partners_install_table();
	}
} );
