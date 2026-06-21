<?php
/**
 * Module : mon-espace.amelia-role-context
 *
 * Objectif : dans le cabinet /mon-espace (panneau employé Amelia), un·e prof
 * voit ses cours comme un PROVIDER (une carte par cours, propre) — tout en
 * restant MANAGER partout ailleurs (wp-admin → Amelia : voit tous les cours).
 *
 *  ── Pourquoi pas l'ancien « strip de rôle en mémoire » ──────────────────
 *  L'ancienne version modifiait $user->roles en mémoire (manager → provider).
 *  Mais Amelia RE-CHERCHE le compte en base par email à chaque requête du
 *  cabinet (UserApplicationService L627 : get_user_by('email', …)) et relit le
 *  rôle depuis la DB. Notre modif en mémoire était donc lue par-dessus → Amelia
 *  voyait « manager » → le token (provider) ne matchait plus → AuthorizationException
 *  → panneau VIDE (le « flash-puis-vide »), de façon intermittente.
 *
 *  ── La nouvelle approche : intercepter la LECTURE en base ───────────────
 *  On filtre `get_user_metadata` sur la clé capabilities, UNIQUEMENT :
 *    - pour le compte WP courant,
 *    - sur une requête du cabinet employé (page/source = 'cabinet-provider'),
 *    - si ce compte a bien une entité Amelia provider (= un·e vrai·e prof).
 *  → On renvoie alors des capabilities « wpamelia-provider ». Comme ce filtre
 *  court-circuite la lecture AVANT le cache, MÊME la re-lecture par email
 *  d'Amelia voit « provider ». Cohérent à chaque requête → plus de désaccord
 *  de token, plus de page vide. Et Amelia, voyant un provider, scope les events
 *  et rend une carte par cours (propre).
 *
 *  Hors cabinet (wp-admin, front public, autres plugins) : le filtre ne touche
 *  rien → le compte reste manager → voit tous les cours, garde ses droits.
 *
 *  Réversible : désactiver ce module rend le filtre dormant (le manager
 *  reverrait alors le cabinet en vue manager, avec les doublons par-réservation).
 *
 *  Référence Amelia (v9.5.1) :
 *    - UserApplicationService L627-660 : re-fetch par email + getUserAmeliaRole
 *    - UserRoles::getUserAmeliaRole : lit $wpUser->roles (admin/manager/provider)
 *    - Command.php L179-181 : 'cabinet-provider' → page='cabinet' + cabinetType='provider'
 */

defined( 'ABSPATH' ) || exit;

// ============================================================================
// 1) Capture du contexte de la requête REST Amelia (page / source)
// ============================================================================

/**
 * Capture les params `page` et `source` de la requête REST avant que la route
 * Amelia ne s'exécute, pour savoir s'il s'agit du cabinet employé. WP_REST_Request
 * expose proprement les params (query string OU corps JSON).
 */
add_filter( 'rest_pre_dispatch', 'cordespace_capture_amelia_request_context', 10, 3 );

function cordespace_capture_amelia_request_context( $result, $server, $request ) {
	if ( $request instanceof WP_REST_Request ) {
		foreach ( [ 'page', 'source' ] as $key ) {
			$val = $request->get_param( $key );
			if ( is_string( $val ) && $val !== '' ) {
				$GLOBALS['cordespace_amelia_req_ctx'][ $key ] = $val;
			}
		}
	}
	return $result; // ne court-circuite jamais le dispatch
}

/**
 * True si la requête courante est celle du CABINET EMPLOYÉ Amelia
 * (page ou source = 'cabinet-provider').
 */
function cordespace_is_amelia_provider_cabinet_request(): bool {
	$ctx = isset( $GLOBALS['cordespace_amelia_req_ctx'] ) ? (array) $GLOBALS['cordespace_amelia_req_ctx'] : [];
	foreach ( [ 'page', 'source' ] as $key ) {
		if ( isset( $ctx[ $key ] ) && $ctx[ $key ] === 'cabinet-provider' ) {
			return true;
		}
		if ( isset( $_GET[ $key ] ) && sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) === 'cabinet-provider' ) {
			return true;
		}
		if ( isset( $_POST[ $key ] ) && sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) === 'cabinet-provider' ) {
			return true;
		}
	}
	return false;
}

// ============================================================================
// 2) Helper : entité provider Amelia du WP user connecté
// ============================================================================

/**
 * ID de l'entité Amelia provider (table wp_amelia_users) liée au WP user
 * connecté via externalId, ou 0. Caché. Ne lit PAS de user meta (pas de
 * récursion avec le filtre des capabilities).
 */
function cordespace_current_user_amelia_provider_id(): int {
	$wp_user_id = get_current_user_id();
	if ( $wp_user_id <= 0 ) {
		return 0;
	}
	static $cache = [];
	if ( isset( $cache[ $wp_user_id ] ) ) {
		return $cache[ $wp_user_id ];
	}
	global $wpdb;
	$id = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM {$wpdb->prefix}amelia_users
			WHERE externalId = %d AND type = 'provider' AND status = 'visible' LIMIT 1",
		$wp_user_id
	) );
	$cache[ $wp_user_id ] = $id;
	return $id;
}

// ============================================================================
// 3) Override des capabilities : provider dans le cabinet, manager ailleurs
// ============================================================================

/**
 * Sur une requête du cabinet employé, force les capabilities du WP user courant
 * à « wpamelia-provider » — au niveau de la LECTURE en base (get_user_metadata),
 * pour que même la re-lecture par email d'Amelia voie provider.
 *
 * Garde anti-récursion ($busy) : la résolution de get_current_user_id() peut
 * elle-même déclencher une lecture des capabilities → on renvoie alors la valeur
 * normale pour ne pas boucler (l'objet user courant se charge en manager, ce
 * qui est sans incidence : Amelia utilise le user re-fetch par email, lui filtré).
 */
add_filter( 'get_user_metadata', 'cordespace_cabinet_force_provider_caps', 10, 4 );

function cordespace_cabinet_force_provider_caps( $value, $object_id, $meta_key, $single ) {
	static $busy = false;

	global $wpdb;
	if ( $meta_key !== $wpdb->prefix . 'capabilities' ) {
		return $value;
	}
	if ( $busy ) {
		return $value;
	}
	if ( ! cordespace_is_amelia_provider_cabinet_request() ) {
		return $value;
	}

	$busy        = true;
	$current_id  = get_current_user_id();
	$is_current  = ( $current_id > 0 && (int) $object_id === $current_id );
	$has_entity  = $is_current ? ( cordespace_current_user_amelia_provider_id() > 0 ) : false;
	$busy        = false;

	if ( ! $has_entity ) {
		return $value;
	}

	// Capabilities = juste le rôle provider. WP en dérive $user->roles =
	// ['wpamelia-provider'] et fusionne les caps du rôle. getUserAmeliaRole
	// renvoie alors 'provider'. Format de retour compatible $single/non-$single :
	// WP fait $check[0] si $single, sinon renvoie $check tel quel.
	return [ [ 'wpamelia-provider' => true ] ];
}
