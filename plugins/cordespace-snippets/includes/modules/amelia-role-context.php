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
 *  Référence du code Amelia :
 *  - src/Infrastructure/WP/UserRoles/UserRoles.php : getUserAmeliaRole()
 *  - src/Application/Commands/User/LoginCabinetCommandHandler.php L72-74
 *  - src/Application/Commands/Booking/Event/GetEventsCommandHandler.php L96
 */

defined( 'ABSPATH' ) || exit;

// ─── Frontend : strip wpamelia-manager pour permettre l'auto-login cabinet ───
add_action( 'set_current_user', 'cordespace_strip_manager_role_on_frontend', 99 );

function cordespace_strip_manager_role_on_frontend() {
	if ( is_admin() ) {
		return;
	}
	$user = wp_get_current_user();
	if ( ! $user || ! $user->ID ) {
		return;
	}
	if ( ! in_array( 'wpamelia-manager', (array) $user->roles, true ) ) {
		return;
	}

	$user->roles = array_values( array_diff( (array) $user->roles, [ 'wpamelia-manager' ] ) );

	// S'assure que wpamelia-provider est présent (sinon Amelia tombe sur null
	// → 'customer' par défaut → cabinet provider refuse le match).
	if ( ! in_array( 'wpamelia-provider', $user->roles, true ) ) {
		$user->roles[] = 'wpamelia-provider';
	}
}

// ─── wp-admin Amelia : déclasse administrator → manager pour la vue events ──
add_action( 'set_current_user', 'cordespace_demote_admin_on_amelia_pages', 99 );

function cordespace_demote_admin_on_amelia_pages() {
	if ( ! cordespace_is_amelia_admin_page() ) {
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

// Le filtre user_has_cap pour neutraliser is_super_admin() sur les pages Amelia.
// Sans ça, getUserAmeliaRole() détecte 'admin' via is_super_admin() même si
// 'administrator' a été retiré du tableau roles.
add_filter( 'user_has_cap', 'cordespace_drop_delete_users_on_amelia_pages', 99, 4 );

function cordespace_drop_delete_users_on_amelia_pages( $allcaps, $caps, $args, $user ) {
	if ( ! cordespace_is_amelia_admin_page() ) {
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
 * True si on est sur une page wp-admin dont le ?page= commence par "wpamelia".
 * Couvre wpamelia-events, wpamelia-calendar, wpamelia-services, etc.
 */
function cordespace_is_amelia_admin_page(): bool {
	if ( ! is_admin() ) {
		return false;
	}
	if ( empty( $_GET['page'] ) ) {
		return false;
	}
	return strpos( (string) $_GET['page'], 'wpamelia' ) === 0;
}
