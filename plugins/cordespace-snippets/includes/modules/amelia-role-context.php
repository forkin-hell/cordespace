<?php
/**
 * Module : mon-espace.amelia-role-context
 *
 * Objectif : dans le cabinet /mon-espace (panneau employé Amelia), un·e prof
 * ne voit QUE ses propres cours — tout en gardant sa vue « tous les cours »
 * dans wp-admin → Amelia (utile pour les managers).
 *
 *  ── Pourquoi cette approche (et pas l'ancien « strip de rôle ») ──────────
 *  L'ancienne version transformait le rôle WP en mémoire (manager → provider)
 *  pour qu'Amelia scope les events au provider. Mais Amelia ré-évalue le rôle
 *  à CHAQUE requête du panneau (via le token JWT), et le strip ne s'appliquait
 *  pas de façon consistante :
 *    - au login : le strip est appliqué → token = provider → on voit ses cours
 *    - au reload : le strip ne s'applique pas pareil → le serveur re-voit
 *      « manager » → le token (provider) ne matche plus → AuthorizationException
 *      (GetEventsCommandHandler L85) → panneau VIDE. = le fameux « flash-puis-vide ».
 *
 *  ── La nouvelle approche, robuste ───────────────────────────────────────
 *  On NE touche PLUS au rôle. Le manager reste manager → Amelia l'authentifie
 *  sans mismatch de token, et renvoie TOUS les events au cabinet. On filtre
 *  ensuite la liste via le hook natif `amelia_get_events_filter` pour ne garder
 *  que les events où l'entité provider du prof est assignée.
 *
 *  On filtre UNIQUEMENT la requête du cabinet employé (page/source =
 *  'cabinet-provider'). Jamais wp-admin (le manager y voit tout), jamais les
 *  formulaires de réservation publics (les client·es y voient tout).
 *
 *  Avantages vs l'ancien hack :
 *    - Stable au reload (plus de mismatch de token, plus de page vide)
 *    - Garde 100% du panneau Amelia (recherche, filtres de dates, scanner QR…)
 *    - Marche pour managers ET providers (on se base sur l'entité, pas le rôle)
 *    - Réversible : désactiver ce module rend le filtre dormant (le manager
 *      reverrait alors tous les cours dans son cabinet)
 *
 *  Référence du code Amelia (v9.5.1) :
 *    - GetEventsCommandHandler L100-101 : scope au provider si type === PROVIDER
 *    - GetEventsCommandHandler L410     : apply_filters('amelia_get_events_filter')
 *    - GetEventsCommandHandler L366-375 : chaque event a $event['providers'][n]['id']
 *    - Command.php L179-181 : 'cabinet-provider' → page='cabinet' + cabinetType='provider'
 */

defined( 'ABSPATH' ) || exit;

// ============================================================================
// 1) Capture du contexte de la requête REST Amelia (page / source)
// ============================================================================

/**
 * Capture les paramètres `page` et `source` de la requête REST courante avant
 * que la route Amelia ne s'exécute. On les stocke pour que le filtre des events
 * (plus bas) sache s'il s'agit du cabinet employé.
 *
 * On passe par rest_pre_dispatch (et non $_GET brut) parce que WP_REST_Request
 * expose proprement les params, qu'ils soient en query string OU dans le corps
 * JSON de la requête.
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
 * (page ou source = 'cabinet-provider'). Vérifie le contexte capturé via
 * rest_pre_dispatch, puis $_GET / $_POST en filet de sécurité.
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
// 2) Filtre des events : dans le cabinet employé, ne garder que les siens
// ============================================================================

add_filter( 'amelia_get_events_filter', 'cordespace_cabinet_scope_events_to_own', 10, 1 );

function cordespace_cabinet_scope_events_to_own( $eventsArray ) {
	if ( ! is_array( $eventsArray ) || empty( $eventsArray ) ) {
		return $eventsArray;
	}

	// Uniquement la requête du cabinet employé (pas wp-admin, pas réservation).
	if ( ! cordespace_is_amelia_provider_cabinet_request() ) {
		return $eventsArray;
	}

	// L'entité provider Amelia du prof connecté.
	$entity_id = cordespace_current_user_amelia_provider_id();
	if ( $entity_id <= 0 ) {
		// Pas d'entité provider (ex : admin pur) → on ne filtre pas (sécurité :
		// mieux vaut tout montrer que de vider par erreur).
		return $eventsArray;
	}

	// En vue MANAGER, le panneau employé Amelia rend une carte par RÉSERVATION
	// (et par provider) — d'où un même cours affiché N fois (N = nb de bookings).
	// En vue provider, Amelia scope les données et le cours n'apparaît qu'une
	// fois. Comme on garde le rôle manager (pour « voir tout » en wp-admin), on
	// reproduit l'effet « une carte par cours » côté données, pour la requête
	// cabinet uniquement :
	//   1. ne garder que les events du prof (son entité provider est assignée)
	//   2. réduire la liste 'providers' au seul prof
	//   3. vider les tableaux 'bookings' (event + périodes) qui pilotent le
	//      rendu par-réservation. Les compteurs scalaires (spotsSold, places,
	//      bookingsApproved…) restent intacts → le badge « X/Y » s'affiche
	//      toujours, et le détail/scanner QR d'un cours re-fetch ses propres
	//      données via une autre requête (non filtrée ici).
	$filtered = [];
	foreach ( $eventsArray as $event ) {
		if ( empty( $event['providers'] ) || ! is_array( $event['providers'] ) ) {
			continue;
		}
		$own = null;
		foreach ( $event['providers'] as $p ) {
			if ( (int) ( $p['id'] ?? 0 ) === $entity_id ) {
				$own = $p;
				break;
			}
		}
		if ( $own === null ) {
			continue; // pas un cours de ce prof
		}
		$event['providers'] = [ $own ];
		$event['bookings']  = [];
		if ( ! empty( $event['periods'] ) && is_array( $event['periods'] ) ) {
			foreach ( $event['periods'] as $k => $per ) {
				if ( is_array( $per ) ) {
					$event['periods'][ $k ]['bookings'] = [];
				}
			}
		}
		$filtered[] = $event;
	}

	return array_values( $filtered );
}

// ============================================================================
// 3) Helper : entité provider Amelia du WP user connecté
// ============================================================================

/**
 * Renvoie l'ID de l'entité Amelia provider (table wp_amelia_users) liée au WP
 * user connecté via externalId, ou 0 s'il n'en a pas. Caché par requête.
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
