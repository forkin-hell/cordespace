<?php
/**
 * Module: waivers-schema
 *
 * Crée et maintient la table append-only des signatures de waivers.
 * Self-healing : si la version du schema en option est inférieure à la
 * constante CORDESPACE_WAIVERS_SCHEMA_VERSION, on relance dbDelta()
 * au prochain admin_init. Idempotent.
 *
 * Voir docs/superpowers/specs/waivers.md §2.3
 */

defined( 'ABSPATH' ) || exit;

const CORDESPACE_WAIVERS_TABLE_SUFFIX    = 'cordespace_waiver_signatures';
const CORDESPACE_WAIVERS_SCHEMA_VERSION  = 1;

/**
 * Nom complet (avec préfixe wp_) de la table de signatures.
 */
function cordespace_waivers_table_name(): string {
	global $wpdb;
	return $wpdb->prefix . CORDESPACE_WAIVERS_TABLE_SUFFIX;
}

/**
 * Crée ou met à jour la table via dbDelta(). Idempotent.
 * Met à jour l'option cordespace_waivers_schema_version après succès.
 */
function cordespace_waivers_install_table(): void {
	global $wpdb;
	$table           = cordespace_waivers_table_name();
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		user_id BIGINT UNSIGNED NOT NULL,
		waiver_id BIGINT UNSIGNED NOT NULL,
		waiver_version VARCHAR(32) NOT NULL DEFAULT '1',
		signed_name VARCHAR(255) NOT NULL,
		signed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		ip VARCHAR(45) DEFAULT NULL,
		user_agent VARCHAR(255) DEFAULT NULL,
		email VARCHAR(255) DEFAULT NULL,
		invited_by_user_id BIGINT UNSIGNED DEFAULT NULL,
		invited_by_order_id BIGINT UNSIGNED DEFAULT NULL,
		PRIMARY KEY  (id),
		KEY idx_user (user_id),
		KEY idx_waiver (waiver_id),
		KEY idx_user_waiver_version (user_id, waiver_id, waiver_version),
		KEY idx_invited_by_order (invited_by_order_id)
	) {$charset_collate};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	update_option( 'cordespace_waivers_schema_version', CORDESPACE_WAIVERS_SCHEMA_VERSION );
}

/**
 * Self-heal : rejoue l'install si la version stockée est en retard.
 * Tourne en wp-admin uniquement (suffisant : seul·es les admins déclenchent
 * cette branche, et l'option est mise à jour après succès donc 1 seul run).
 */
add_action( 'admin_init', static function (): void {
	$stored = (int) get_option( 'cordespace_waivers_schema_version', 0 );
	if ( $stored < CORDESPACE_WAIVERS_SCHEMA_VERSION ) {
		cordespace_waivers_install_table();
	}
} );
