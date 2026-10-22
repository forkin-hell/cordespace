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
	if ( cordespace_waivers_has_signed_version( $user_id, $waiver_id, $version ) ) {
		return true;
	}
	// Signature faite comme partenaire, sans compte, avec le courriel de ce compte (créé après coup).
	$user = $user_id > 0 ? get_userdata( $user_id ) : false;
	if ( $user && ! empty( $user->user_email ) ) {
		return cordespace_waivers_email_has_signed_version_guest( (string) $user->user_email, $waiver_id, $version );
	}
	return false;
}

/**
 * Courriel normalisé : minuscules, sans espaces.
 */
function cordespace_waivers_normalize_email( string $email ): string {
	return strtolower( trim( $email ) );
}

/**
 * Une signature SANS compte (user_id = 0) porte-t-elle ce courriel pour ce waiver et cette version ?
 */
function cordespace_waivers_email_has_signed_version_guest( string $email, int $waiver_id, string $version ): bool {
	global $wpdb;
	$email = cordespace_waivers_normalize_email( $email );
	if ( $email === '' ) {
		return false;
	}
	$table = cordespace_waivers_table_name();
	$count = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$table} WHERE user_id = 0 AND LOWER(email) = %s AND waiver_id = %d AND waiver_version = %s",
		$email,
		$waiver_id,
		$version
	) );
	return $count > 0;
}

/**
 * Ce courriel a-t-il signé ce waiver dans cette version ? Signature sans compte à ce courriel,
 * OU signature d'un compte WordPress dont l'adresse est ce courriel.
 */
function cordespace_waivers_email_has_signed_version( string $email, int $waiver_id, string $version ): bool {
	$email = cordespace_waivers_normalize_email( $email );
	if ( $email === '' ) {
		return false;
	}
	if ( cordespace_waivers_email_has_signed_version_guest( $email, $waiver_id, $version ) ) {
		return true;
	}
	$user = get_user_by( 'email', $email );
	return $user && cordespace_waivers_has_signed_version( (int) $user->ID, $waiver_id, $version );
}

function cordespace_waivers_email_has_signed_current( string $email, int $waiver_id ): bool {
	return cordespace_waivers_email_has_signed_version( $email, $waiver_id, cordespace_waivers_get_version( $waiver_id ) );
}

/**
 * Signature d'une personne SANS compte (partenaire invitée). Si un compte WordPress existe avec
 * ce courriel, la signature lui est rattachée (user_id du compte).
 *
 * @param array $extra  'ip', 'user_agent', 'invited_by_user_id', 'partner_id'
 * @return int|WP_Error  id de la ligne
 */
function cordespace_waivers_sign_guest( string $email, int $waiver_id, string $signed_name, array $extra = [] ) {
	global $wpdb;
	$email = cordespace_waivers_normalize_email( $email );
	if ( $waiver_id <= 0 || ! is_email( $email ) ) {
		return new WP_Error( 'cordespace_waiver_invalid', __( 'Courriel ou waiver invalide.', 'cordespace-snippets' ) );
	}
	if ( trim( $signed_name ) === '' ) {
		return new WP_Error( 'cordespace_waiver_no_name', __( 'Le nom signataire est requis.', 'cordespace-snippets' ) );
	}
	$user    = get_user_by( 'email', $email );
	$user_id = $user ? (int) $user->ID : 0;
	$data    = [
		'user_id'        => $user_id,
		'waiver_id'      => $waiver_id,
		'waiver_version' => cordespace_waivers_get_version( $waiver_id ),
		'signed_name'    => trim( $signed_name ),
		'signed_at'      => current_time( 'mysql', true ),
		'ip'             => isset( $extra['ip'] ) ? mb_substr( (string) $extra['ip'], 0, 45 ) : null,
		'user_agent'     => isset( $extra['user_agent'] ) ? mb_substr( (string) $extra['user_agent'], 0, 255 ) : null,
		'email'          => $email,
	];
	$format = [ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ];
	if ( (int) ( $extra['invited_by_user_id'] ?? 0 ) > 0 ) {
		$data['invited_by_user_id'] = (int) $extra['invited_by_user_id'];
		$format[]                   = '%d';
	}
	if ( (int) ( $extra['partner_id'] ?? 0 ) > 0 ) {
		$data['partner_id'] = (int) $extra['partner_id'];
		$format[]           = '%d';
	}
	$ok = $wpdb->insert( cordespace_waivers_table_name(), $data, $format );
	if ( $ok === false ) {
		return new WP_Error( 'cordespace_waiver_db_error', $wpdb->last_error !== '' ? $wpdb->last_error : 'DB insert failed' );
	}
	return (int) $wpdb->insert_id;
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

/**
 * Toutes les signatures d'un waiver (toutes versions), les plus récentes en premier.
 * Pour la boîte « Signatures » de l'écran d'édition.
 *
 * @return list<array{id:int,user_id:int,waiver_version:string,signed_name:string,email:string,signed_at:string}>
 */
function cordespace_waivers_signatures_for_waiver( int $waiver_id, int $limit = 500 ): array {
	global $wpdb;
	$table = cordespace_waivers_table_name();
	$rows  = $wpdb->get_results( $wpdb->prepare(
		"SELECT id, user_id, waiver_version, signed_name, email, signed_at, invited_by_user_id, partner_id
		   FROM {$table}
		  WHERE waiver_id = %d
		  ORDER BY signed_at DESC, id DESC
		  LIMIT %d",
		$waiver_id,
		max( 1, min( $limit, 5000 ) )
	), ARRAY_A );
	$out = [];
	foreach ( is_array( $rows ) ? $rows : [] as $r ) {
		$out[] = [
			'id'             => (int) $r['id'],
			'user_id'        => (int) $r['user_id'],
			'waiver_version' => (string) $r['waiver_version'],
			'signed_name'    => (string) $r['signed_name'],
			'email'          => (string) ( $r['email'] ?? '' ),
			'signed_at'      => (string) $r['signed_at'],
			'invited_by_user_id' => (int) ( $r['invited_by_user_id'] ?? 0 ),
			'partner_id'     => isset( $r['partner_id'] ) && $r['partner_id'] !== null ? (int) $r['partner_id'] : null,
		];
	}
	return $out;
}

/**
 * Nombre de signatures d'un waiver, toutes versions ou pour une version donnée.
 */
function cordespace_waivers_signatures_count( int $waiver_id, ?string $version = null ): int {
	global $wpdb;
	$table = cordespace_waivers_table_name();
	if ( $version === null ) {
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE waiver_id = %d", $waiver_id ) );
	}
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE waiver_id = %d AND waiver_version = %s", $waiver_id, $version ) );
}
