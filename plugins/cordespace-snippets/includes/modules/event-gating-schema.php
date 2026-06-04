<?php
/**
 * Module: event-gating-schema
 *
 * Table custom des approbations admin pour les events Amelia gated
 * (types d'events comme « Semi-privé », « Privé », etc. où la personne
 * doit être validée par l'équipe avant de pouvoir réserver).
 *
 * Modèle v2 (matrice membre × tag) :
 *   Une row par triple (event_type_id, user_id, tag) avec un statut
 *   tri-valent ('pending' / 'approved' / 'rejected').
 *
 *   - Pour un type qui a des tags Amelia configurés (whitelist), on a
 *     une row par tag. Permet de valider/refuser un membre tag par tag.
 *   - Pour un type sans tags (cas « Réservation des salles »), on a une
 *     row unique avec tag = '' (chaîne vide) → comportement binaire
 *     comme en v1.
 *
 * UNIQUE KEY (event_type_id, user_id, tag) garantit qu'on ne peut pas
 * avoir deux states pour la même cellule. UPDATE plutôt que INSERT pour
 * modifier.
 *
 * Self-healing : la version stockée est comparée à la constante
 * CORDESPACE_EVENT_GATING_SCHEMA_VERSION. Si plus basse, on relance
 * dbDelta() + migration des données au prochain admin_init.
 */

defined( 'ABSPATH' ) || exit;

const CORDESPACE_EVENT_GATING_TABLE_SUFFIX    = 'cordespace_event_type_approvals';
const CORDESPACE_EVENT_GATING_SCHEMA_VERSION  = 2;

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
 *
 * Après dbDelta, on déclenche aussi la migration des données v1 → v2
 * (idempotente via option).
 */
function cordespace_event_gating_install_table(): void {
	global $wpdb;
	$table   = cordespace_event_gating_table_name();
	$charset = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		event_type_id BIGINT UNSIGNED NOT NULL,
		user_id BIGINT UNSIGNED NOT NULL,
		tag VARCHAR(191) NOT NULL DEFAULT '',
		status VARCHAR(20) NOT NULL DEFAULT 'pending',
		notes TEXT NULL,
		approved_at DATETIME NULL,
		approved_by BIGINT UNSIGNED NULL,
		created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		UNIQUE KEY uniq_type_user_tag (event_type_id, user_id, tag),
		KEY idx_type_user (event_type_id, user_id),
		KEY idx_user_id (user_id),
		KEY idx_event_type_id (event_type_id),
		KEY idx_status (status)
	) {$charset};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	// Migration des données v1 → v2 (idempotente)
	cordespace_event_gating_migrate_data_v1_to_v2();

	update_option( 'cordespace_event_gating_schema_version', CORDESPACE_EVENT_GATING_SCHEMA_VERSION );
}

/**
 * Migration des données v1 → v2.
 *
 * V1 : 1 row par paire (event_type_id, user_id), colonne tag absente ou ''.
 * V2 : 1 row par triple (event_type_id, user_id, tag).
 *
 * Pour chaque approbation existante (= row avec tag = '') :
 *   - Si le type a des tags configurés [t1, t2, ...] : on duplique la row
 *     en N rows (une par tag), avec le même status/notes/approved_*. Puis
 *     on supprime la row originale (tag = '').
 *   - Si le type n'a pas de tags (cas « Réservation des salles ») : on
 *     garde la row originale telle quelle.
 *
 * Idempotente via l'option WP `cordespace_event_gating_data_v2_migrated`.
 * Une fois flippée à true, la migration ne re-tourne plus, même si elle
 * est appelée plusieurs fois.
 */
function cordespace_event_gating_migrate_data_v1_to_v2(): void {
	if ( get_option( 'cordespace_event_gating_data_v2_migrated', false ) ) {
		return;
	}

	global $wpdb;
	$table = cordespace_event_gating_table_name();

	// On parcourt seulement les rows qui ont encore tag = '' (= pas migrées)
	$rows = $wpdb->get_results(
		"SELECT id, event_type_id, user_id, status, notes, approved_at, approved_by, created_at
		   FROM {$table}
		  WHERE tag = ''",
		ARRAY_A
	);

	foreach ( (array) $rows as $row ) {
		$type_id = (int) $row['event_type_id'];

		// Récupère les tags configurés sur ce type (lecture brute du post_meta
		// pour ne pas dépendre du module cpt à ce stade — la migration peut
		// tourner avant que les autres modules soient chargés).
		$tags = get_post_meta( $type_id, '_cordespace_event_type_amelia_tags', true );
		$tags = is_array( $tags )
			? array_values( array_filter( array_map( 'sanitize_text_field', $tags ) ) )
			: [];

		if ( empty( $tags ) ) {
			// Type sans tags : on laisse la row inchangée avec tag = ''.
			continue;
		}

		// Type avec tags : duplique en N rows (1 par tag), puis supprime l'original.
		foreach ( $tags as $tag ) {
			$wpdb->insert(
				$table,
				[
					'event_type_id' => $type_id,
					'user_id'       => (int) $row['user_id'],
					'tag'           => (string) $tag,
					'status'        => (string) $row['status'],
					'notes'         => (string) ( $row['notes'] ?? '' ),
					'approved_at'   => $row['approved_at'],
					'approved_by'   => $row['approved_by'] !== null ? (int) $row['approved_by'] : null,
					'created_at'    => $row['created_at'],
				],
				[ '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s' ]
			);
		}

		$wpdb->delete( $table, [ 'id' => (int) $row['id'] ], [ '%d' ] );
	}

	update_option( 'cordespace_event_gating_data_v2_migrated', true );
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
