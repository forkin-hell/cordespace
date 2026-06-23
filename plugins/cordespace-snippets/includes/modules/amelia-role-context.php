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

// ─── SONDE TEMPORAIRE (à retirer) : capture l'employé null de /entities ──────
// Le cabinet provider plante car getEmployees contient un employé null
// (injectProviderOptions lit null.id). Le hook amelia_get_entities_filter
// (GetEntitiesCommandHandler L721) porte la liste FINALE des employés renvoyée
// au cabinet. On la scanne : si un élément est null / sans id, on le logge avec
// tous les ids → on saura QUEL provider arrive null (ou si la liste est saine
// côté serveur, auquel cas le null est créé côté navigateur).
add_filter( 'amelia_get_entities_filter', 'cordespace_probe_entities_employees', 999, 1 );

function cordespace_probe_entities_employees( $resultData ) {
	if ( ! is_array( $resultData ) || ! array_key_exists( 'employees', $resultData ) || ! is_array( $resultData['employees'] ) ) {
		return $resultData;
	}
	$ids      = [];
	$has_null = false;
	foreach ( $resultData['employees'] as $i => $e ) {
		if ( $e === null ) {
			$ids[]    = "[$i]=NULL";
			$has_null = true;
		} elseif ( ! is_array( $e ) ) {
			$ids[]    = "[$i]=" . gettype( $e );
			$has_null = true;
		} elseif ( ! isset( $e['id'] ) || $e['id'] === null ) {
			$ids[]    = "[$i]=NOID";
			$has_null = true;
		} else {
			$ids[] = (string) (int) $e['id'];
		}
	}
	$log = get_option( 'cordespace_probe', [] );
	if ( ! is_array( $log ) ) {
		$log = [];
	}
	$log[] = [
		'uid'     => get_current_user_id(),
		'count'   => count( $resultData['employees'] ),
		'hasNull' => $has_null ? 'OUI' : 'non',
		'ids'     => implode( ',', $ids ),
	];
	if ( count( $log ) > 40 ) {
		$log = array_slice( $log, -40 );
	}
	update_option( 'cordespace_probe', $log, false );
	return $resultData;
}
