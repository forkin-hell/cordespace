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

const CORDESPACE_EVENT_GATING_TABLE_SUFFIX           = 'cordespace_event_type_approvals';
const CORDESPACE_EVENT_GATING_PENDING_TABLE_SUFFIX   = 'cordespace_pending_email_approvals';
const CORDESPACE_EVENT_GATING_MAGIC_TABLE_SUFFIX     = 'cordespace_magic_links';
const CORDESPACE_EVENT_GATING_SCHEMA_VERSION         = 4;

/**
 * Renvoie le nom complet (avec préfixe) de la table principale (approbations
 * de members WP : matrice user × tag).
 */
function cordespace_event_gating_table_name(): string {
	global $wpdb;
	return $wpdb->prefix . CORDESPACE_EVENT_GATING_TABLE_SUFFIX;
}

/**
 * Renvoie le nom complet de la table « pending » (approbations en attente
 * d'inscription, stockées par email pour les personnes qui n'ont pas encore
 * de compte WP). À la création du compte (hook user_register), les rows
 * pending correspondant à l'email sont promues dans la table principale et
 * supprimées d'ici.
 */
function cordespace_event_gating_pending_table_name(): string {
	global $wpdb;
	return $wpdb->prefix . CORDESPACE_EVENT_GATING_PENDING_TABLE_SUFFIX;
}

/**
 * Renvoie le nom complet de la table des magic links (= URL avec token qui
 * permettent à une personne de bypasser le gating pour 1 event Amelia spécifique).
 */
function cordespace_event_gating_magic_table_name(): string {
	global $wpdb;
	return $wpdb->prefix . CORDESPACE_EVENT_GATING_MAGIC_TABLE_SUFFIX;
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

	// IMPORTANT : dbDelta ne SUPPRIME pas les anciens index, il en ajoute
	// seulement. Pour passer de UNIQUE KEY uniq_type_user (event_type_id,
	// user_id) à uniq_type_user_tag (event_type_id, user_id, tag), on doit
	// dropper l'ancien index MANUELLEMENT avant dbDelta. Sinon l'ancien
	// index bloque silencieusement les INSERT multi-tags pour un même
	// couple (type, user), et la migration des données échoue.
	$old_index = $wpdb->get_var( $wpdb->prepare(
		"SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
		  WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s
		  LIMIT 1",
		DB_NAME,
		$table,
		'uniq_type_user'
	) );
	if ( $old_index ) {
		$wpdb->query( "ALTER TABLE {$table} DROP INDEX uniq_type_user" );
	}

	dbDelta( $sql );

	// Migration des données v1 → v2 (idempotente)
	cordespace_event_gating_migrate_data_v1_to_v2();

	// Table v3 : pending_email_approvals (validations en attente d'inscription)
	$pending_table = cordespace_event_gating_pending_table_name();
	$pending_sql   = "CREATE TABLE {$pending_table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		email VARCHAR(191) NOT NULL,
		event_type_id BIGINT UNSIGNED NOT NULL,
		tag VARCHAR(191) NOT NULL DEFAULT '',
		status VARCHAR(20) NOT NULL DEFAULT 'approved',
		notes TEXT NULL,
		created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		created_by BIGINT UNSIGNED NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY uniq_email_type_tag (email, event_type_id, tag),
		KEY idx_email (email),
		KEY idx_event_type_id (event_type_id)
	) {$charset};";
	dbDelta( $pending_sql );

	// Table v4 : magic_links (URL/token qui bypassent le gating pour 1 event)
	$magic_table = cordespace_event_gating_magic_table_name();
	$magic_sql   = "CREATE TABLE {$magic_table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		token VARCHAR(64) NOT NULL,
		event_id BIGINT UNSIGNED NOT NULL,
		max_uses INT UNSIGNED NOT NULL DEFAULT 0,
		used_count INT UNSIGNED NOT NULL DEFAULT 0,
		expires_at DATETIME NULL,
		notes TEXT NULL,
		created_by BIGINT UNSIGNED NULL,
		created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
		revoked_at DATETIME NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY uniq_token (token),
		KEY idx_event_id (event_id),
		KEY idx_expires_at (expires_at)
	) {$charset};";
	dbDelta( $magic_sql );

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
