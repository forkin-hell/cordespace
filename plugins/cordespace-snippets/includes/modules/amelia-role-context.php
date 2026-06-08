<?php
/**
 * Module : mon-espace.amelia-role-context
 *
 * Découple le rôle WP "vu par Amelia" du rôle WP réel, selon le contexte :
 *
 *  ── FRONTEND (cabinet /mon-espace) ─────────────────────────────────────
 *  Les profs avec rôle `wpamelia-manager` (cas Bunny, Ember…) déclenchent
 *  une exception dans LoginCabinetCommandHandler : leur type DB Amelia
 *  est `provider` mais Amelia détecte `manager` à cause du rôle WP, donc
 *  le check `$user->getType() === $cabinetType` échoue → formulaire de
 *  login Amelia affiché à chaque visite (au lieu de l'auto-login WP).
 *
 *  Solution : sur le frontend, on retire `wpamelia-manager` de l'objet
 *  $user en mémoire (DB inchangée). Amelia détecte alors `provider`,
 *  match avec type DB, auto-login OK.
 *
 *  ── WP-ADMIN AMELIA (pages ?page=wpamelia-*) ───────────────────────────
 *  Les administrateurs voient seulement leurs propres events si leur
 *  entité Amelia est de type `provider` (cas Tess, qui enseigne). La
 *  vue "manager" (86 events) demande que `getUserAmeliaRole` retourne
 *  `manager`, donc il faut que `administrator` ne soit pas détecté.
 *
 *  Solution : sur ces pages, on retire `administrator` du tableau roles
 *  ET on retire la cap `delete_users` (sinon is_super_admin() retourne
 *  true et Amelia détecte `admin` quand même). En ajoutant
 *  `wpamelia-manager` à la place, Amelia détecte `manager` → vue 86.
 *
 *  ── Pourquoi en mémoire seulement ──────────────────────────────────────
 *  Les modifs sur $user->roles et le filtre user_has_cap n'affectent que
 *  l'objet user de la requête courante. Aucune écriture en DB. Réversible
 *  en désactivant ce module depuis wp-admin → Cordespace → Modules.
 *
 *  ── Si Amelia met à jour `getUserAmeliaRole` ──────────────────────────
 *  Ce module devient silencieusement inopérant : Bunny/Ember reverront
 *  le formulaire de login Amelia (comme avant), Tess reverra 9 events.
 *  Aucun risque de casse cachée ou de fuite. À adapter si ça arrive.
 *
 *  ── Pourquoi `init` et pas `set_current_user` ─────────────────────────
 *  `set_current_user` action fire au moment où WP résout l'utilisateur·trice
 *  courant, ce qui arrive AVANT que notre loader (plugins_loaded p5) ait
 *  inclus ce fichier (un autre plugin appelle vraisemblablement
 *  wp_get_current_user() pendant le chargement). Conséquence : nos
 *  add_action ne sont pas encore enregistrés quand l'action se déclenche,
 *  donc nos hooks ne s'exécutent jamais (silencieusement). Vérifié via
 *  un mu-plugin de diagnostic.
 *
 *  Le hook `init` priorité 1 s'exécute APRÈS plugins_loaded (nos add_action
 *  sont en place) ET AVANT que Amelia lise $user->roles dans ses handlers
 *  AJAX/page render. C'est le bon point d'accroche.
 *
 *  Référence du code Amelia :
 *  - src/Infrastructure/WP/UserRoles/UserRoles.php : getUserAmeliaRole()
 *  - src/Application/Commands/User/LoginCabinetCommandHandler.php L72-74
 *  - src/Application/Commands/Booking/Event/GetEventsCommandHandler.php L96
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

// ─── wp-admin Amelia : déclasse administrator → manager pour la vue events ──
add_action( 'init', 'cordespace_demote_admin_on_amelia_pages', 1 );

function cordespace_demote_admin_on_amelia_pages() {
	if ( ! cordespace_is_amelia_admin_context() ) {
		return;
	}
	$user = wp_get_current_user();
	if ( ! $user || ! $user->ID ) {
		return;
	}
	if ( ! in_array( 'administrator', (array) $user->roles, true ) ) {
		return;
	}

	$user->roles = array_values( array_diff( (array) $user->roles, [ 'administrator' ] ) );

	if ( ! in_array( 'wpamelia-manager', $user->roles, true ) ) {
		$user->roles[] = 'wpamelia-manager';
	}
}

// Filtre user_has_cap : neutralise is_super_admin() partout sauf vrai wp-admin
// non-Amelia, pour qu'Amelia ne détecte plus 'admin' via la cap delete_users.
// Couvre à la fois le contexte wp-admin Amelia (vue events 86) ET le frontend
// (cabinet auto-login pour admin avec entité provider).
add_filter( 'user_has_cap', 'cordespace_drop_delete_users_outside_real_admin', 99, 4 );

function cordespace_drop_delete_users_outside_real_admin( $allcaps, $caps, $args, $user ) {
	$amelia_admin = cordespace_is_amelia_admin_context();
	$frontend     = cordespace_is_frontend_request();
	if ( ! $amelia_admin && ! $frontend ) {
		return $allcaps;
	}
	// Seulement pour les users qui sont (ou étaient) administrators.
	$is_admin_user = ! empty( $allcaps['administrator'] )
		|| ( is_object( $user ) && in_array( 'administrator', (array) $user->roles, true ) );
	if ( ! $is_admin_user ) {
		return $allcaps;
	}
	unset( $allcaps['delete_users'] );
	return $allcaps;
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

/**
 * True si la requête courante appartient à un contexte wp-admin Amelia.
 *
 * Couvre :
 * - les pages wp-admin dont le ?page= commence par "wpamelia"
 *   (wpamelia-events, wpamelia-calendar, wpamelia-services…)
 * - les requêtes AJAX vers /wp-admin/admin-ajax.php déclenchées depuis
 *   ces pages (HTTP_REFERER contient "page=wpamelia"). Sans ce 2e cas,
 *   les events affichés dans la table sont filtrés par provider parce
 *   que le hook ne fire pas sur les XHR qui peuplent la liste.
 */
function cordespace_is_amelia_admin_context(): bool {
	if ( ! is_admin() ) {
		return false;
	}

	// Pages d'administration Amelia (config) qu'on EXCLUT du contexte
	// "demote admin → manager". Amelia v3 vérifie explicitement
	// in_array('administrator', $user->roles, true) dans SettingsStorage
	// avant d'autoriser la save des settings (cf. SettingsStorage.php:146
	// et :585 du plugin Amelia). Si on retire le rôle administrator sur
	// ces pages, le bouton Save échoue silencieusement (réf. ticket bug
	// signalé par Tess : impossible de changer les Payment methods).
	//
	// La protection « voir les events des autres profs » qu'apporte ce
	// module n'a aucun intérêt sur les écrans de config purs — donc on
	// renvoie false ici et l'utilisateur·trice garde ses pleines caps.
	$admin_pages_to_skip = [
		'wpamelia-settings',      // Settings (général, payments, etc.)
		'wpamelia-notifications', // Notifications config
	];

	// Cas 1 : page wp-admin Amelia (chargement direct).
	if ( ! empty( $_GET['page'] ) ) {
		$page = (string) $_GET['page'];
		if ( in_array( $page, $admin_pages_to_skip, true ) ) {
			return false;
		}
		if ( strpos( $page, 'wpamelia' ) === 0 ) {
			return true;
		}
	}

	// Cas 2 : AJAX déclenché depuis une page wp-admin Amelia.
	if ( wp_doing_ajax() ) {
		$referer = wp_get_referer();
		if ( $referer && strpos( $referer, 'page=wpamelia' ) !== false ) {
			// Même exclusion côté AJAX : la save des settings passe par
			// admin-ajax.php ou REST avec un referer page=wpamelia-settings.
			foreach ( $admin_pages_to_skip as $page_slug ) {
				if ( strpos( $referer, 'page=' . $page_slug ) !== false ) {
					return false;
				}
			}
			return true;
		}
	}

	return false;
}
