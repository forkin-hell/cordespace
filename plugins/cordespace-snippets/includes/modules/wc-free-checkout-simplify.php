<?php
/**
 * Module: wc-free-checkout-simplify
 *
 * Simplifie le checkout WooCommerce quand le panier est entièrement
 * gratuit (total = 0). Retire les champs de facturation non essentiels
 * (adresse, ville, code postal, téléphone, etc.) et garde seulement le
 * minimum requis pour Amelia (email + nom).
 *
 * Pourquoi ce module existe :
 *   Depuis qu'on force les events gratuits Amelia à passer par WC (via
 *   le setting Amelia « Hide WooCommerce cart when price is 0 » décoché,
 *   pour que event-gating.checkout-blocker puisse fire), WC continue à
 *   demander l'adresse de facturation complète à l'user, même pour un
 *   event gratuit (genre Munch). C'est de la friction inutile : ces
 *   infos ne servent à rien pour un order de 0$.
 *
 * Comportement :
 *   - Si WC()->cart->total > 0 → aucune modification (checkout normal)
 *   - Si WC()->cart->total == 0 :
 *       1. Retire tous les champs shipping (events sont virtuels)
 *       2. Garde uniquement billing_email + billing_first_name +
 *          billing_last_name dans la section facturation
 *       3. Retire le champ « Notes de commande »
 *       4. Désactive le besoin d'adresse shipping côté cart
 *   - Si user connecté : les 3 fields restants sont auto-pré-remplis
 *     par WC depuis le compte WP → checkout en quasi 1 clic
 *
 * Pourquoi le minimum est email + nom :
 *   Amelia a besoin du nom et de l'email pour créer le customer record
 *   dans sa table wphu_amelia_users et envoyer la confirmation de
 *   booking. Les retirer ferait planter le booking à la sauvegarde.
 *   C'est le minimum incompressible.
 *
 * Compatibilité avec le gating :
 *   Avec event-gating.checkout-blocker actif, un user non-validé sera
 *   bloqué par la banderole rouge AVANT ce filtre (le throw RouteException
 *   arrive plus tôt dans la pipeline). Donc seul un user validé arrive
 *   réellement au point où ce module simplifie les fields.
 *
 * Désactivation :
 *   Si tu veux retrouver le checkout standard avec tous les fields :
 *   wp-admin → Cordespace → Paiements et crédits → décoche ce module.
 *
 * Dépend de :
 *   - WooCommerce
 */

defined( 'ABSPATH' ) || exit;

/**
 * Le panier WC actuel est-il entièrement gratuit ?
 *
 * Strict : on vérifie le total final (subtotal + tax - discounts). Si
 * un coupon descend à 0 un panier non-gratuit à la base, le filtre
 * s'applique aussi → c'est volontaire.
 */
function cordespace_wc_free_checkout_is_free_cart(): bool {
	if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
		return false;
	}
	// WC()->cart->total est calculé lors de calculate_totals(). Au moment
	// où ce filtre fire (woocommerce_checkout_fields), il a déjà été
	// calculé par WC. On compare à 0 avec une tolérance float.
	return (float) WC()->cart->get_total( 'edit' ) <= 0.0;
}

/**
 * Filtre les champs du checkout WC. Retire les non-essentiels quand le
 * panier est gratuit. Priorité 100 pour passer après les filtres tiers
 * éventuels (gateway plugins, etc.).
 *
 * @param array<string, array> $fields  Sections : billing, shipping, account, order
 * @return array<string, array>
 */
function cordespace_wc_free_checkout_simplify_fields( $fields ) {
	if ( ! is_array( $fields ) || ! cordespace_wc_free_checkout_is_free_cart() ) {
		return $fields;
	}

	// Whitelist des fields requis pour Amelia (= le minimum incompressible).
	$required_billing = [ 'billing_first_name', 'billing_last_name', 'billing_email' ];

	// Section billing : on garde uniquement la whitelist
	if ( isset( $fields['billing'] ) && is_array( $fields['billing'] ) ) {
		foreach ( array_keys( $fields['billing'] ) as $key ) {
			if ( ! in_array( $key, $required_billing, true ) ) {
				unset( $fields['billing'][ $key ] );
			}
		}
	}

	// Section shipping : retire complètement (events Amelia sont virtuels,
	// pas d'expédition possible)
	if ( isset( $fields['shipping'] ) ) {
		$fields['shipping'] = [];
	}

	// Section order : retire les notes de commande (inutile pour un event)
	if ( isset( $fields['order'] ) && is_array( $fields['order'] ) ) {
		unset( $fields['order']['order_comments'] );
	}

	return $fields;
}
add_filter( 'woocommerce_checkout_fields', 'cordespace_wc_free_checkout_simplify_fields', 100 );

/**
 * Indique à WC que le panier n'a pas besoin de shipping pour les orders
 * gratuits. Couvre le cas où certains products ne sont pas marqués
 * « virtual » en DB (Amelia product WC créé automatiquement).
 *
 * Sans ce filtre, WC peut afficher une section shipping vide mais avec
 * un titre, ce qui crée du bruit visuel.
 */
function cordespace_wc_free_checkout_no_shipping( $needs ) {
	if ( cordespace_wc_free_checkout_is_free_cart() ) {
		return false;
	}
	return $needs;
}
add_filter( 'woocommerce_cart_needs_shipping', 'cordespace_wc_free_checkout_no_shipping' );
add_filter( 'woocommerce_cart_needs_shipping_address', 'cordespace_wc_free_checkout_no_shipping' );
