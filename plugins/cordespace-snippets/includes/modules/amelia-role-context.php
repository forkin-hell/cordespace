<?php
/**
 * Module : mon-espace.amelia-role-context
 *
 * Sur le FRONTEND uniquement (cabinet /mon-espace), découple le rôle WP
 * "vu par Amelia" du rôle WP réel.
 *
 *  ── FRONTEND (cabinet /mon-espace) ─────────────────────────────────────
 *  Les profs avec rôle `wpamelia-manager` (cas Bunny, Ember, Ayik…)
 *  déclenchent une exception dans LoginCabinetCommandHandler : leur type
 *  DB Amelia est `provider` mais Amelia détecte `manager` à cause du rôle
 *  WP, donc le check `$user->getType() === $cabinetType` échoue → formulaire
 *  de login Amelia à chaque visite (au lieu de l'auto-login WP).
 *
 *  Solution : sur le frontend, on retire `wpamelia-manager` (et
 *  `administrator` au cas où) de l'objet $user EN MÉMOIRE (DB inchangée).
 *  Amelia détecte alors `provider`, match avec le type DB → auto-login OK
 *  et events scopés au provider (le prof voit ses propres cours).
 *
 *  ── wp-admin : AUCUNE modification (anciennement « behavior #2 ») ───────
 *  On ne touche PLUS au rôle dans le wp-admin. La fonction qui déclassait
 *  administrator → manager sur les pages ?page=wpamelia-* (pour forcer la
 *  vue « tous les events ») a été RETIRÉE, ainsi que le filtre user_has_cap
 *  associé. Conséquences voulues :
 *    - les admins voient la vue PAR DÉFAUT d'Amelia (plus de rôle forcé) ;
 *    - les admins gardent toutes leurs caps → plus de bug de sauvegarde
 *      des pages Settings/Customize d'Amelia (qui exigent administrator).
 *
 *  ── Pourquoi en mémoire seulement ──────────────────────────────────────
 *  Les modifs sur $user->roles n'affectent que l'objet user de la requête
 *  courante. Aucune écriture en DB. Réversible en désactivant ce module
 *  depuis wp-admin → Cordespace → Modules.
 *
 *  ── Pourquoi `init` priorité 1 ─────────────────────────────────────────
 *  S'exécute APRÈS plugins_loaded (nos add_action sont en place) ET AVANT
 *  qu'Amelia lise $user->roles dans ses handlers AJAX/page render.
 *
 *  Référence du code Amelia :
 *  - src/Infrastructure/WP/UserRoles/UserRoles.php : getUserAmeliaRole()
 *  - src/Application/Commands/User/LoginCabinetCommandHandler.php L72-74
 *  - src/Application/Services/User/UserApplicationService.php L715-720
 */

defined( 'ABSPATH' ) || exit;

// ─── Frontend : strip wpamelia-manager ET administrator pour auto-login cabinet ───
// Sans ça, un user admin (Tess/Ayik) visitant /mon-espace doit se relogger sur
// le cabinet parce qu'Amelia détecte 'admin' mais le cabinet attend 'provider'.
add_action( 'init', 'cordespace_strip_admin_roles_on_frontend', 1 );

function cordespace_strip_admin_roles_on_frontend() {
	if ( ! cordespace_is_frontend_request() ) {
		return;
	}
	$user = wp_get_current_user();
	if ( ! $user || ! $user->ID ) {
		return;
	}

	$had_admin   = in_array( 'administrator', (array) $user->roles, true );
	$had_manager = in_array( 'wpamelia-manager', (array) $user->roles, true );
	if ( ! $had_admin && ! $had_manager ) {
		return;
	}

	$user->roles = array_values( array_diff(
		(array) $user->roles,
		[ 'administrator', 'wpamelia-manager' ]
	) );

	// S'assure que wpamelia-provider est présent (sinon Amelia tombe sur
	// 'customer' par défaut → cabinet provider refuse le match auto-login).
	if ( ! in_array( 'wpamelia-provider', $user->roles, true ) ) {
		$user->roles[] = 'wpamelia-provider';
	}
}

/**
 * True si la requête courante est "côté frontend" : page publique OU appel
 * AJAX déclenché depuis le frontend (admin-ajax.php avec referer hors wp-admin).
 *
 * Sert à distinguer une vraie page wp-admin (où on garde les rôles admin
 * intacts) d'une page publique (où on les retire pour que le cabinet Amelia
 * fasse l'auto-login WP_USER au lieu de demander email + password).
 *
 * Exclusions importantes (renvoient false même si is_admin()=false) :
 * - wp-login.php : endpoint d'auth WP, utilisé par User Switching pour
 *   l'action=switch_to_user. Stripper administrator ici casse le check
 *   edit_user → switch refusé (« Could not switch users »).
 */
function cordespace_is_frontend_request(): bool {
	$script = $_SERVER['SCRIPT_NAME'] ?? '';
	// wp-login.php : endpoint d'auth WP (login, password reset, User Switching).
	// On le traite comme wp-admin pour préserver les caps administrator.
	if ( $script === '/wp-login.php' || substr( $script, -13 ) === '/wp-login.php' ) {
		return false;
	}

	if ( ! is_admin() ) {
		return true;
	}
	// AJAX : on regarde le referer pour savoir d'où vient la requête.
	if ( wp_doing_ajax() ) {
		$referer = wp_get_referer();
		// Pas de referer = on n'est sûr de rien, on suppose wp-admin (plus sécurisant).
		if ( ! $referer ) {
			return false;
		}
		return strpos( $referer, '/wp-admin/' ) === false;
	}
	return false; // vraie page wp-admin
}

// ─── FIX cabinet provider : réinjecte tous les providers dans /entities ──────
// Cause du crash (diagnostiquée par sonde) : pour le cabinet d'un provider,
// Amelia (GetEntitiesCommandHandler::removeAllExceptUser) réduit la liste des
// employés au seul provider courant. Mais les events qu'il CO-ANIME référencent
// d'AUTRES providers (ex : event = [177,158,139]). Le panneau Vue tente de
// résoudre ces co-profs dans la liste réduite, ne les trouve pas → insère un
// `null` → injectProviderOptions lit `null.id` → crash (intermittent, timing).
//
// Parade serveur (sans patcher Amelia) : sur la réponse /entities du frontend,
// on AJOUTE tous les providers visibles manquants, en stubs minimalistes (id +
// nom, listes/relations vidées, mot de passe vidé). Le Vue résout alors tous les
// co-profs → plus de null → plus de crash. wp-admin n'est jamais touché.
add_filter( 'amelia_get_entities_filter', 'cordespace_fix_entities_employees', 1000, 1 );

function cordespace_fix_entities_employees( $resultData ) {
	// Jamais en vraie page wp-admin : on ne modifie que le frontend (cabinet).
	if ( is_admin() && ! wp_doing_ajax() ) {
		return $resultData;
	}
	if ( ! is_array( $resultData ) || empty( $resultData['employees'] ) || ! is_array( $resultData['employees'] ) ) {
		return $resultData;
	}

	// Gabarit = 1re fiche employé existante (structure Amelia complète à copier).
	$template = null;
	$present  = [];
	foreach ( $resultData['employees'] as $e ) {
		if ( is_array( $e ) && isset( $e['id'] ) ) {
			$present[ (int) $e['id'] ] = true;
			if ( $template === null ) {
				$template = $e;
			}
		}
	}
	if ( $template === null ) {
		return $resultData;
	}

	// Champs « liste »/relations à vider dans les stubs (pour ne pas propager les
	// services/horaires du gabarit aux autres profs).
	$blank_keys = [
		'serviceList', 'locationList', 'locationsList', 'weekDayList', 'weekDayOutput',
		'specialDayList', 'dayOffList', 'timeOutList', 'appointmentList', 'eventList',
		'periodList', 'daysOff', 'specialDays', 'weekDays',
	];

	global $wpdb;
	$all = $wpdb->get_results(
		"SELECT id, firstName, lastName, email FROM {$wpdb->prefix}amelia_users WHERE type = 'provider' AND status = 'visible'",
		ARRAY_A
	);
	foreach ( (array) $all as $p ) {
		$id = (int) $p['id'];
		if ( isset( $present[ $id ] ) ) {
			continue; // déjà dans la réponse
		}
		$stub              = $template;
		$stub['id']        = $id;
		$stub['firstName'] = (string) $p['firstName'];
		$stub['lastName']  = (string) ( $p['lastName'] ?? '' );
		$stub['email']     = (string) $p['email'];
		if ( array_key_exists( 'password', $stub ) ) {
			$stub['password'] = '';
		}
		foreach ( $blank_keys as $k ) {
			if ( array_key_exists( $k, $stub ) ) {
				$stub[ $k ] = [];
			}
		}
		$resultData['employees'][] = $stub;
		$present[ $id ]            = true;
	}

	return $resultData;
}
