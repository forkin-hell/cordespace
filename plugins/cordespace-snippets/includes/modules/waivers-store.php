<?php
/**
 * Module: waivers-store
 *
 * CRUD helpers sur la table wphu_cordespace_waiver_signatures.
 * Append-only : `sign()` insère sans dédoublonner (on accepte les re-signatures
 * volontaires ; les requêtes de lecture filtrent par version pour savoir si la
 * personne a signé la version courante).
 *
 * Dépend de :
 *   - waivers-schema (constante CORDESPACE_WAIVERS_TABLE_SUFFIX, helper cordespace_waivers_table_name)
 *   - waivers-cpt    (helper cordespace_waivers_get_version)
 *
 * Voir docs/superpowers/specs/waivers.md §2.3
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cette personne a-t-elle signé une version précise du waiver ?
 */
function cordespace_waivers_has_signed_version( int $user_id, int $waiver_id, string $version ): bool {
	global $wpdb;
	$table = cordespace_waivers_table_name();
	$count = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND waiver_id = %d AND waiver_version = %s",
		$user_id,
		$waiver_id,
		$version
	) );
	return $count > 0;
}

/**
 * Cette personne a-t-elle signé la version COURANTE du waiver ?
 * (Si la version a été bumpée depuis sa dernière signature → renvoie false.)
 */
function cordespace_waivers_has_signed_current( int $user_id, int $waiver_id ): bool {
	$version = cordespace_waivers_get_version( $waiver_id );
	return cordespace_waivers_has_signed_version( $user_id, $waiver_id, $version );
}

/**
 * Insère une signature. Append-only (pas de dédoublonnage : si la personne
 * re-signe, on crée une nouvelle ligne avec un nouveau timestamp).
 *
 * @param int    $user_id      ID WP du·de la signataire (>0)
 * @param int    $waiver_id    ID du CPT cordespace_waiver (>0)
 * @param string $signed_name  Nom utilisé pour la signature (non vide après trim)
 * @param array  $extra        Champs optionnels :
 *                             - 'ip'                  string (max 45)
 *                             - 'user_agent'          string (max 255)
 *                             - 'email'               string (max 255)
 *                             - 'invited_by_user_id'  int    (0 = NULL)
 *                             - 'invited_by_order_id' int    (0 = NULL)
 *
 * @return int|WP_Error  insert_id ou WP_Error.
 */
function cordespace_waivers_sign( int $user_id, int $waiver_id, string $signed_name, array $extra = [] ) {
	if ( $user_id <= 0 || $waiver_id <= 0 ) {
		return new WP_Error( 'cordespace_waiver_invalid', __( 'Utilisateur·trice ou waiver invalide.', 'cordespace-snippets' ) );
	}
	if ( trim( $signed_name ) === '' ) {
		return new WP_Error( 'cordespace_waiver_no_name', __( 'Le nom signataire est requis.', 'cordespace-snippets' ) );
	}

	$ip                  = isset( $extra['ip'] )                  ? mb_substr( (string) $extra['ip'],         0, 45 )  : null;
	$user_agent          = isset( $extra['user_agent'] )          ? mb_substr( (string) $extra['user_agent'], 0, 255 ) : null;
	$email               = isset( $extra['email'] )               ? mb_substr( (string) $extra['email'],      0, 255 ) : null;
	$invited_by_user_id  = isset( $extra['invited_by_user_id'] )  ? (int) $extra['invited_by_user_id']  : 0;
	$invited_by_order_id = isset( $extra['invited_by_order_id'] ) ? (int) $extra['invited_by_order_id'] : 0;

	$data = [
		'user_id'        => $user_id,
		'waiver_id'      => $waiver_id,
		'waiver_version' => cordespace_waivers_get_version( $waiver_id ),
		'signed_name'    => $signed_name,
		'signed_at'      => current_time( 'mysql', true ),
		'ip'             => $ip,
		'user_agent'     => $user_agent,
		'email'          => $email,
	];
	$format = [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ];

	// On n'envoie invited_by_* que si > 0 — laisse la DB mettre NULL sinon.
	if ( $invited_by_user_id > 0 ) {
		$data['invited_by_user_id'] = $invited_by_user_id;
		$format[] = '%d';
	}
	if ( $invited_by_order_id > 0 ) {
		$data['invited_by_order_id'] = $invited_by_order_id;
		$format[] = '%d';
	}

	global $wpdb;
	$ok = $wpdb->insert( cordespace_waivers_table_name(), $data, $format );

	if ( $ok === false ) {
		return new WP_Error(
			'cordespace_waiver_db_error',
			$wpdb->last_error !== '' ? $wpdb->last_error : 'DB insert failed'
		);
	}
	return (int) $wpdb->insert_id;
}

/**
 * Historique COMPLET des signatures d'une personne (tous waivers confondus).
 * Plus récente d'abord. Limit borné [1, 500].
 *
 * @return array<int, array<string, mixed>>
 */
function cordespace_waivers_history_for_user( int $user_id, int $limit = 50 ): array {
	global $wpdb;
	$table = cordespace_waivers_table_name();
	$rows  = $wpdb->get_results( $wpdb->prepare(
		"SELECT id, waiver_id, waiver_version, signed_name, signed_at, email, invited_by_user_id, invited_by_order_id
		   FROM {$table}
		  WHERE user_id = %d
		  ORDER BY signed_at DESC, id DESC
		  LIMIT %d",
		$user_id,
		max( 1, min( $limit, 500 ) )
	), ARRAY_A );
	return is_array( $rows ) ? $rows : [];
}

/**
 * Historique des signatures d'une personne pour UN waiver précis.
 * Plus récente d'abord. Limit borné [1, 500]. Sert à voir si une personne
 * doit re-signer après un bump de version (compare la version courante à la
 * dernière entrée).
 *
 * @return array<int, array<string, mixed>>
 */
function cordespace_waivers_history_for_user_and_waiver( int $user_id, int $waiver_id, int $limit = 10 ): array {
	global $wpdb;
	$table = cordespace_waivers_table_name();
	$rows  = $wpdb->get_results( $wpdb->prepare(
		"SELECT id, waiver_version, signed_name, signed_at, email, invited_by_user_id, invited_by_order_id
		   FROM {$table}
		  WHERE user_id = %d AND waiver_id = %d
		  ORDER BY signed_at DESC, id DESC
		  LIMIT %d",
		$user_id,
		$waiver_id,
		max( 1, min( $limit, 500 ) )
	), ARRAY_A );
	return is_array( $rows ) ? $rows : [];
}
