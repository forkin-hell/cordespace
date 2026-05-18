<?php
/**
 * Module : payments.prof-warning
 *
 * Bandeau d'avertissement sur les pages panier + checkout WooCommerce, à
 * destination des profs connecté·es : leur rappelle de basculer vers leur
 * compte client·e pour utiliser leurs crédits et retrouver leur historique.
 *
 * Cordespace utilise les blocs WooCommerce (Gutenberg) pour le panier et le
 * checkout. Les hooks classiques woocommerce_before_cart/checkout_form ne
 * firent PAS sur ces pages. On utilise donc le filtre the_content qui
 * fonctionne pour les 2 modes (blocs + shortcode legacy).
 *
 * Dépendances : WooCommerce, Amelia (helper provider).
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'the_content', 'cordespace_prof_purchase_warning_banner', 5 );

function cordespace_prof_purchase_warning_banner( $content ) {
	if ( ! function_exists( 'is_cart' ) ) {
		return $content;
	}
	if ( ! is_cart() && ! is_checkout() ) {
		return $content;
	}
	if ( ! is_user_logged_in() ) {
		return $content;
	}

	$user = wp_get_current_user();
	if ( ! cordespace_user_is_amelia_provider( $user->ID ) ) {
		return $content;
	}
	$linked_id = (int) get_user_meta( $user->ID, '_cordespace_linked_user_id', true );
	if ( $linked_id <= 0 ) {
		return $content;
	}

	$switch_button = do_shortcode( '[cordespace_switch_button label="Basculer vers mon compte client·e maintenant"]' );

	$banner  = '<div style="background:#fef9e6;border-left:4px solid #f5b800;padding:1rem 1.4rem;margin:0 0 1.5rem;border-radius:6px;">';
	$banner .= '<strong style="color:#7a5d00;font-size:1.05em;">💡 Tu es connecté·e comme enseignant·e</strong>';
	$banner .= '<p style="margin:0.5rem 0 0.8rem;color:#7a5d00;font-size:0.95em;">';
	$banner .= 'Pour utiliser tes <strong>crédits Cordespace</strong>, retrouver tes commandes passées en tant que client·e, ou faire un achat sur ton compte personnel, ';
	$banner .= '<strong>bascule d\'abord vers ton compte client·e</strong> avant de payer.';
	$banner .= '</p>';
	$banner .= $switch_button;
	$banner .= '</div>';

	return $banner . $content;
}
