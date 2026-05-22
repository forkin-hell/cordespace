<?php
/**
 * Module : login-redirect
 *
 * Redirige les user·trice·s non-admin vers /mon-espace/ après connexion
 * (au lieu du wp-admin par défaut). Concerne aussi l'activation de compte
 * (lien dans l'email de bienvenue) et la réinitialisation de mot de passe.
 *
 * Préserve un éventuel `redirect_to` explicite dans l'URL (par ex. après
 * inscription depuis le bandeau gating waiver qui envoie redirect_to=/commander/).
 *
 * Comportement :
 *   - Admin / shop_manager → wp-admin (inchangé)
 *   - Tout le monde d'autre → /mon-espace/ (configuré via mon-espace-shortcode)
 *
 * Dépend de : helpers du module mon-espace.shortcode (cordespace_get_mon_espace_url).
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'login_redirect', 'cordespace_login_redirect_non_admin', 20, 3 );

function cordespace_login_redirect_non_admin( $redirect_to, $requested_redirect_to, $user ) {
	// Si pas de user valide (ex: erreur login), laisse WP gérer
	if ( ! is_object( $user ) || is_wp_error( $user ) ) {
		return $redirect_to;
	}

	// Admin / managers gardent leur redirection par défaut (wp-admin)
	if ( user_can( $user, 'manage_options' ) || user_can( $user, 'manage_woocommerce' ) ) {
		return $redirect_to;
	}

	// Si l'user a explicitement demandé une URL (ex: redirect_to=/commander/ après
	// inscription depuis le bandeau gating), on respecte SAUF si c'est wp-admin
	if ( ! empty( $requested_redirect_to )
	     && strpos( $requested_redirect_to, '/wp-admin' ) === false
	     && strpos( $requested_redirect_to, 'wp-login.php' ) === false ) {
		return $requested_redirect_to;
	}

	// Sinon, on envoie vers /mon-espace/
	if ( function_exists( 'cordespace_get_mon_espace_url' ) ) {
		return cordespace_get_mon_espace_url();
	}

	return home_url();
}
