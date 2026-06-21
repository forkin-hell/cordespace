<?php
/**
 * Module : mon-espace.amelia-role-context
 *
 * Découple le rôle WP "vu par Amelia" du rôle WP réel sur le FRONTEND, pour
 * permettre l'auto-login au cabinet /mon-espace des profs en rôle
 * `wpamelia-manager`.
 *
 *  ── FRONTEND (cabinet /mon-espace) ─────────────────────────────────────
 *  Les profs avec rôle `wpamelia-manager` (cas Bunny, Ember, Ayik…)
 *  déclenchent une exception dans LoginCabinetCommandHandler : leur type DB
 *  Amelia est `provider` mais Amelia détecte `manager` à cause du rôle WP,
 *  donc le check `$user->getType() === $cabinetType` échoue → formulaire de
 *  login Amelia affiché à chaque visite (au lieu de l'auto-login WP).
 *
 *  Solution : sur le frontend, on retire `wpamelia-manager` (et
 *  `administrator` au cas où) de l'objet $user en mémoire (DB inchangée).
 *  Amelia détecte alors `provider`, match avec le type DB, auto-login OK.
 *
 *  ── HISTORIQUE : le volet « demote admin → manager » a été RETIRÉ ──────
 *  Une 2e fonction (sur les pages wp-admin ?page=wpamelia-*) déclassait
 *  administrator → manager pour qu'un admin qui enseignait (= admin + entité
 *  Amelia provider) voie tous les events au lieu des siens. Retiré le
 *  2026-06-17 : on a nettoyé les comptes pour qu'un admin ne soit JAMAIS
 *  aussi une entité provider. Un admin pur voit donc tous les events
 *  nativement (rôle Amelia = admin), sans hack. Bénéfice : plus de
 *  manipulation de la cap delete_users (qui cassait la sauvegarde des pages
 *  de config Amelia : Settings, Customize, etc.).
 *
 *  ── Pourquoi en mémoire seulement ──────────────────────────────────────
 *  Les modifs sur $user->roles n'affectent que l'objet user de la requête
 *  courante. Aucune écriture en DB. Réversible en désactivant ce module
 *  depuis wp-admin → Cordespace → Modules.
 *
 *  ── Si Amelia met à jour `getUserAmeliaRole` ──────────────────────────
 *  Ce module devient silencieusement inopérant : les profs manager
 *  reverront le formulaire de login Amelia (comme avant). Aucun risque de
 *  casse cachée ou de fuite. À adapter si ça arrive.
 *
 *  ── Pourquoi `init` et pas `set_current_user` ─────────────────────────
 *  `set_current_user` fire au moment où WP résout l'utilisateur·trice
 *  courant, AVANT que notre loader (plugins_loaded p5) ait inclus ce
 *  fichier. Conséquence : nos add_action ne seraient pas encore enregistrés.
 *  Le hook `init` priorité 1 s'exécute APRÈS plugins_loaded ET AVANT
 *  qu'Amelia lise $user->roles dans ses handlers. C'est le bon point.
 *
 *  Référence du code Amelia :
 *  - src/Infrastructure/WP/UserRoles/UserRoles.php : getUserAmeliaRole()
 *  - src/Application/Commands/User/LoginCabinetCommandHandler.php L72-74
 */

defined( 'ABSPATH' ) || exit;

// ─── Frontend : strip wpamelia-manager ET administrator pour auto-login cabinet ───
// Sans ça, un prof manager (Bunny, Ember, Ayik…) visitant /mon-espace doit se
// relogger sur le cabinet parce qu'Amelia détecte 'manager' alors que le
// cabinet attend 'provider'.
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
 * Sert à distinguer une vraie page wp-admin (où on garde les rôles intacts)
 * d'une page publique (où on les retire pour que le cabinet Amelia fasse
 * l'auto-login WP_USER au lieu de demander email + password).
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
