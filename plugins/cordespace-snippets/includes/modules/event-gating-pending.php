<?php
/**
 * Module: event-gating-pending
 *
 * Helpers pour la table `wp_cordespace_pending_email_approvals` : validations
 * en attente d'inscription. Quand un CSV est importé, les emails dont le
 * compte WP n'existe pas encore sont stockés ici. À la création du compte
 * (hook user_register), ces rows sont automatiquement promues vers la table
 * principale wp_cordespace_event_type_approvals (= matrice membre × tag).
 *
 * Flow :
 *   Import CSV → email a un user WP ?
 *     OUI → cordespace_evgating_set_tag_status() direct (matrice)
 *     NON → cordespace_evgating_pending_add() ici
 *
 *   Création du compte (user_register) → resolve_for_user_register() :
 *     - cherche pending WHERE email = nouveau user email
 *     - pour chaque row : set_tag_status() puis DELETE pending
 *     - log dans user meta _cordespace_evgating_auto_validated_at
 *
 * Dépend de :
 *   - event-gating-schema (table + helper pending_table_name)
 *   - event-gating-members (helpers de la matrice + valid_statuses)
 */

defined( 'ABSPATH' ) || exit;

// ============================================================================
// 1) Helper : normalisation de l'email (lowercase + trim, retourne '' si invalide)
// ============================================================================

function cordespace_evgating_normalize_email( string $email ): string {
	$email = trim( strtolower( $email ) );
	if ( ! is_email( $email ) ) {
		return '';
	}
	return $email;
}

// ============================================================================
// 2) CRUD helpers sur la table pending
// ============================================================================

/**
 * Insère ou met à jour une row pending pour un email donné sur (type, tag).
 *
 * Idempotent : si la row existe déjà, met à jour le statut (= comportement
 * "dernier import gagne"). Si pas existe : insère.
 *
 * @param string $email      Sera normalisé en lowercase + trim. Si invalide, return 0.
 * @param int    $type_id
 * @param string $tag        '' pour types sans tags
 * @param string $status     'pending' | 'approved' | 'rejected'
 * @param string $notes
 * @param int    $by_user_id ID de l'admin qui fait l'import
 * @return int Nombre de rows affectées (1 = OK, 0 = invalide ou erreur DB)
 */
function cordespace_evgating_pending_add(
	string $email,
	int $type_id,
	string $tag,
	string $status,
	string $notes,
	int $by_user_id,
	array $visited = []
): int {
	$email = cordespace_evgating_normalize_email( $email );
	if ( $email === '' || $type_id <= 0 ) {
		return 0;
	}
	if ( ! in_array( $status, cordespace_evgating_valid_statuses(), true ) ) {
		$status = CORDESPACE_EVTYPE_STATUS_APPROVED;
	}

	global $wpdb;
	$table = cordespace_event_gating_pending_table_name();

	// UPSERT manuel : check + INSERT ou UPDATE.
	$existing_id = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM {$table} WHERE email = %s AND event_type_id = %d AND tag = %s LIMIT 1",
		$email, $type_id, $tag
	) );

	if ( $existing_id > 0 ) {
		$result = $wpdb->update(
			$table,
			[
				'status'     => $status,
				'notes'      => $notes,
				'created_by' => $by_user_id ?: null,
			],
			[ 'id' => $existing_id ],
			[ '%s', '%s', '%d' ],
			[ '%d' ]
		);
		$ok = ( $result !== false );
	} else {
		$result = $wpdb->insert(
			$table,
			[
				'email'         => $email,
				'event_type_id' => $type_id,
				'tag'           => $tag,
				'status'        => $status,
				'notes'         => $notes,
				'created_by'    => $by_user_id ?: null,
			],
			[ '%s', '%d', '%s', '%s', '%s', '%d' ]
		);
		$ok = (bool) $result;
	}

	// Cascade des implications (uniquement sur 'approved'), même logique que
	// cordespace_evgating_set_tag_status pour les WP users. Protection cycle
	// via $visited.
	if ( $ok && $status === CORDESPACE_EVTYPE_STATUS_APPROVED && function_exists( 'cordespace_event_gating_get_tag_implications' ) ) {
		$visited[] = $tag;
		$implications = cordespace_event_gating_get_tag_implications( $type_id );
		$implied      = isset( $implications[ $tag ] ) && is_array( $implications[ $tag ] )
			? $implications[ $tag ]
			: [];
		foreach ( $implied as $child_tag ) {
			$child_tag = (string) $child_tag;
			if ( $child_tag === '' || in_array( $child_tag, $visited, true ) ) {
				continue;
			}
			cordespace_evgating_pending_add( $email, $type_id, $child_tag, CORDESPACE_EVTYPE_STATUS_APPROVED, $notes, $by_user_id, $visited );
		}
	}

	return $ok ? 1 : 0;
}

/**
 * Renvoie toutes les pending rows pour un type, groupées par email.
 *
 * @return array<int, array{email: string, statuses: array<string, string>, notes: string, created_at: string}>
 *         Indexé numériquement. statuses = [tag => status] pour le member.
 */
function cordespace_evgating_pending_get_for_type( int $type_id ): array {
	if ( $type_id <= 0 ) {
		return [];
	}
	global $wpdb;
	$table = cordespace_event_gating_pending_table_name();
	$rows  = $wpdb->get_results( $wpdb->prepare(
		"SELECT email, tag, status, notes, created_at
		   FROM {$table}
		  WHERE event_type_id = %d
		  ORDER BY email ASC, tag ASC",
		$type_id
	), ARRAY_A );

	$by_email = [];
	foreach ( (array) $rows as $row ) {
		$email = (string) $row['email'];
		if ( ! isset( $by_email[ $email ] ) ) {
			$by_email[ $email ] = [
				'email'      => $email,
				'statuses'   => [],
				'notes'      => (string) ( $row['notes'] ?? '' ),
				'created_at' => (string) $row['created_at'],
			];
		}
		$by_email[ $email ]['statuses'][ (string) $row['tag'] ] = (string) $row['status'];
		// Note partagée : on garde la plus longue (toutes devraient être identiques)
		$row_notes = (string) ( $row['notes'] ?? '' );
		if ( $row_notes !== '' && strlen( $row_notes ) > strlen( (string) $by_email[ $email ]['notes'] ) ) {
			$by_email[ $email ]['notes'] = $row_notes;
		}
	}
	return array_values( $by_email );
}

/**
 * Compte les emails uniques en attente pour un type.
 */
function cordespace_evgating_pending_count_for_type( int $type_id ): int {
	if ( $type_id <= 0 ) {
		return 0;
	}
	global $wpdb;
	$table = cordespace_event_gating_pending_table_name();
	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(DISTINCT email) FROM {$table} WHERE event_type_id = %d",
		$type_id
	) );
}

/**
 * Supprime TOUTES les rows pending d'un email pour un type (= retire la
 * personne en attente de ce type). Utilisé quand l'admin retire un pending
 * via l'UI.
 */
function cordespace_evgating_pending_remove( string $email, int $type_id ): int {
	$email = cordespace_evgating_normalize_email( $email );
	if ( $email === '' || $type_id <= 0 ) {
		return 0;
	}
	global $wpdb;
	$table  = cordespace_event_gating_pending_table_name();
	$result = $wpdb->delete(
		$table,
		[ 'email' => $email, 'event_type_id' => $type_id ],
		[ '%s', '%d' ]
	);
	return $result === false ? 0 : (int) $result;
}

// ============================================================================
// 3) Hook user_register : résolution automatique des pending
// ============================================================================

/**
 * Quand un nouveau user WP est créé (inscription, achat WC, etc.), on cherche
 * dans la table pending toutes les rows qui correspondent à son email. Pour
 * chacune, on promeut la validation dans la matrice principale (via
 * set_tag_status) et on supprime la row pending.
 *
 * Logge la résolution dans le user meta `_cordespace_evgating_auto_validated_at`
 * (timestamp + nombre de rows promues) pour pouvoir retracer.
 */
function cordespace_evgating_resolve_pending_for_user_register( int $user_id ): void {
	$user = get_user_by( 'id', $user_id );
	if ( ! $user ) {
		return;
	}
	$email = cordespace_evgating_normalize_email( $user->user_email );
	if ( $email === '' ) {
		return;
	}

	global $wpdb;
	$table = cordespace_event_gating_pending_table_name();
	$rows  = $wpdb->get_results( $wpdb->prepare(
		"SELECT id, event_type_id, tag, status, notes, created_by
		   FROM {$table}
		  WHERE email = %s",
		$email
	), ARRAY_A );

	if ( empty( $rows ) ) {
		return;
	}

	$promoted = 0;
	foreach ( (array) $rows as $row ) {
		$type_id = (int) $row['event_type_id'];
		$tag     = (string) $row['tag'];
		$status  = (string) $row['status'];
		$by_user = $row['created_by'] !== null ? (int) $row['created_by'] : 0;

		// Si helper de matrice dispo, on promeut. Sinon on garde la pending (paix
		// défensive).
		if ( function_exists( 'cordespace_evgating_set_tag_status' ) ) {
			$ok = cordespace_evgating_set_tag_status( $type_id, $user_id, $tag, $status, $by_user );
			if ( $ok ) {
				// Synchronise aussi la note (toutes les rows du membre)
				if ( function_exists( 'cordespace_evgating_set_member_note' ) && ! empty( $row['notes'] ) ) {
					cordespace_evgating_set_member_note( $type_id, $user_id, (string) $row['notes'] );
				}
				$wpdb->delete( $table, [ 'id' => (int) $row['id'] ], [ '%d' ] );
				$promoted++;
			}
		}
	}

	if ( $promoted > 0 ) {
		update_user_meta( $user_id, '_cordespace_evgating_auto_validated_at', [
			'timestamp' => current_time( 'mysql', true ),
			'promoted'  => $promoted,
		] );
	}
}
add_action( 'user_register', 'cordespace_evgating_resolve_pending_for_user_register', 20, 1 );
