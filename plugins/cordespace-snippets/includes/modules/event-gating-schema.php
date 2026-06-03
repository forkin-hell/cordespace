<?php
/**
 * Module: event-gating-schema
 *
 * Table custom des approbations admin pour les events Amelia gated
 * (types d'events comme « Semi-privé », « Privé », etc. où la cliente
 * doit être validée par l'équipe avant de pouvoir réserver).
 *
 * Une row par paire (event_type_id, user_id) avec un statut tri-valent :
 *   - 'pending'  : en attente de décision admin
 *   - 'approved' : la personne peut réserver les events de ce type
 *   - 'rejected' : refusée, ne peut pas réserver
 *
 * UNIQUE KEY (event_type_id, user_id) garantit qu'on ne peut pas avoir
 * deux states pour la même paire. UPDATE plutôt que INSERT pour modifier.
 *
 * Self-healing : la version stockée est comparée à la constante
 * CORDESPACE_EVENT_GATING_SCHEMA_VERSION. Si plus basse, on relance
 * dbDelta() au prochain admin_init.
 */

defined( 'ABSPATH' ) || exit;

const CORDESPACE_EVENT_GATING_TABLE_SUFFIX    = 'cordespace_event_type_approvals';
const CORDESPACE_EVENT_GATING_SCHEMA_VERSION  = 1;

/**
 * Renvoie le nom complet (avec préfixe) de la table.
 */
function cordespace_event_gating_table_name(): string {
	global $wpdb;
	return $wpdb->prefix . CORDESPACE_EVENT_GATING_TABLE_SUFFIX;
}

/**
 * Crée ou met à jour la table via dbDelta(). Idempotent.
 * Note : on utilise VARCHAR(20) pour `status` au lieu d'ENUM parce que
 * dbDelta a des soucis bien connus avec ENUM. Les valeurs valides sont
 * enforced côté PHP par les helpers du module store.
 */
function cordespace_event_gating_install_table(): void {
	global $wpdb;
	$table   = cordespace_event_gating_table_name();
	$charset = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		event_type_id BIGINT UNSIGNED NOT NULL,
		user_id BIGINT UNSIGNED NOT NULL,
		status VARCHAR(20) NOT NULL DEFAULT 'pending',
		notes TEXT NULL,
		approved_at DATETIME NULL,
		approved_by BIGINT UNSIGNED NULL,
		created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		UNIQUE KEY uniq_type_user (event_type_id, user_id),
		KEY idx_user_id (user_id),
		KEY idx_event_type_id (event_type_id),
		KEY idx_status (status)
	) {$charset};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	update_option( 'cordespace_event_gating_schema_version', CORDESPACE_EVENT_GATING_SCHEMA_VERSION );
}

/**
 * Self-heal : rejoue l'install si la version stockée est en retard.
 */
add_action( 'admin_init', static function (): void {
	$stored = (int) get_option( 'cordespace_event_gating_schema_version', 0 );
	if ( $stored < CORDESPACE_EVENT_GATING_SCHEMA_VERSION ) {
		cordespace_event_gating_install_table();
	}
} );
