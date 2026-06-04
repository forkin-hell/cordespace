<?php
/**
 * Module: waivers-checkout-warning
 *
 * Affiche un BANDEAU D'AVERTISSEMENT (mais NE BLOQUE PAS) le checkout
 * WooCommerce quand des waivers requis ne sont pas encore signés. La cliente
 * peut quand même finaliser sa commande — la signature est ensuite demandée
 * APRÈS l'achat via deux autres canaux :
 *   (a) waivers-post-purchase-prompt : banderole CTA sur la page « Merci pour
 *       votre commande »
 *   (b) waivers-email-reminder       : email envoyé 48h avant l'événement si
 *       toujours non signé
 *
 * Historique : ce module BLOQUAIT le checkout (Store API RouteException +
 * woocommerce_after_checkout_validation). Approche abandonnée 2026-06-02 :
 * trop sensible aux maj WC/Amelia/MyCred et nécessitait un auto-create de
 * compte WP au checkout pour les non-connecté·es (risque collatéral). Désormais
 * on fait confiance au paiement WC pour créer le compte
 * (woocommerce_enable_guest_checkout = no), puis on relance par email.
 *
 * Logique du bandeau (inchangée) :
 *   1. À chaque chargement du panier ou du checkout, on scanne les cart items
 *   2. Pour chaque item portant la meta `ameliabooking` (= réservation Amelia
 *      de type event), on extrait l'eventId et on appelle
 *      cordespace_waivers_applicable_defaults_for_amelia_event()
 *   3. Pour chaque waiver applicable, on vérifie via cordespace_waivers_has_signed_current()
 *      que le·la user·euse connecté·e l'a déjà signé en version courante
 *   4. S'il manque une signature → on rend un encadré HTML rouge en haut de la
 *      page cart/checkout (via woocommerce_before_cart + woocommerce_before_checkout_form
 *      ET via filtre the_content pour la compatibilité WC Blocks) avec bouton
 *      "Signer maintenant" (lien vers la page de signature, redirect_to = checkout).
 *
 * Filet de sécurité jour-J : le badge "WAIVER MANQUANT" reste affiché dans la
 * vue prof (waivers-prof-badge) — si la personne arrive sans avoir signé, le
 * prof voit le badge et peut faire signer sur place / sur mobile.
 *
 * Dépend de :
 *   - waivers-defaults     (cordespace_waivers_applicable_defaults_for_amelia_event)
 *   - waivers-store        (cordespace_waivers_has_signed_current)
 *   - waivers-signing-page (cordespace_waivers_get_sign_url)
 *   - WooCommerce + Amelia
 *
 * Voir docs/superpowers/specs/waivers.md §3.1 (flow Alice) et §4.2 (warning, plus gating)
 */

defined( 'ABSPATH' ) || exit;

/**
 * Construit la liste des waivers requis (mais non signés) pour le panier WC actuel.
 *
 * @return array<int, array{waiver: WP_Post, event_names: string[]}>
 *               Indexé par waiver_id. Vide si rien à signer.
 */
function cordespace_waivers_gating_missing_for_current_cart(): array {
	if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
		return [];
	}

	$user_id = get_current_user_id();
	if ( $user_id <= 0 ) {
		// Non connecté·e : on ne peut pas vérifier les signatures. Le hook
		// "must be logged in" plus bas gère ce cas séparément.
		return [];
	}

	// Collecte des events Amelia présents dans le panier
	$amelia_events = []; // [event_id => event_name]
	foreach ( WC()->cart->get_cart() as $cart_item ) {
		if ( empty( $cart_item['ameliabooking'] ) || ! is_array( $cart_item['ameliabooking'] ) ) {
			continue;
		}
		$booking = $cart_item['ameliabooking'];
		// MVP : on ne gate que les events (pas appointments/packages)
		if ( ( $booking['type'] ?? '' ) !== 'event' ) {
			continue;
		}
		$event_id = isset( $booking['eventId'] ) ? (int) $booking['eventId'] : 0;
		if ( $event_id <= 0 ) {
			continue;
		}
		$amelia_events[ $event_id ] = (string) ( $booking['name'] ?? '' );
	}

	if ( empty( $amelia_events ) ) {
		return [];
	}

	// Pour chaque event, quels waivers s'appliquent ? Puis : sont-ils signés ?
	$missing = []; // [waiver_id => ['waiver' => WP_Post, 'event_names' => string[]]]
	foreach ( $amelia_events as $event_id => $event_name ) {
		$applicable = cordespace_waivers_applicable_defaults_for_amelia_event( $event_id );
		foreach ( $applicable as $waiver_id ) {
			$waiver_id = (int) $waiver_id;
			if ( cordespace_waivers_has_signed_current( $user_id, $waiver_id ) ) {
				continue;
			}
			if ( ! isset( $missing[ $waiver_id ] ) ) {
				$waiver = get_post( $waiver_id );
				if ( ! $waiver ) {
					continue;
				}
				$missing[ $waiver_id ] = [
					'waiver'      => $waiver,
					'event_names' => [],
				];
			}
			if ( $event_name !== '' && ! in_array( $event_name, $missing[ $waiver_id ]['event_names'], true ) ) {
				$missing[ $waiver_id ]['event_names'][] = $event_name;
			}
		}
	}

	return $missing;
}

/**
 * Rend un encadré HTML enrichi (avec bouton "Signer maintenant") en haut
 * des pages panier et checkout. Le HTML passe directement (pas via
 * wc_add_notice qui peut être strip par certains thèmes ou par WC Blocks).
 *
 * Le BLOCAGE serveur du checkout est assuré séparément par le hook
 * woocommerce_after_checkout_validation plus bas.
 */
function cordespace_waivers_gating_render_block(): void {
	if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
		return;
	}

	// Cas non connecté·e : message informatif (pas de bouton login/signup)
	// Le compte WP n'existe pas encore et sera créé automatiquement par WC à
	// la finalisation de la commande. On informe juste qu'il faudra signer
	// après-coup dans l'espace personnel.
	if ( ! is_user_logged_in() ) {
		// Bug fix 2026-06-04 : on filtre les events qui ont vraiment un waiver
		// applicable (via tag Amelia). Avant on affichait le bandeau pour TOUT
		// event Amelia → faux positif sur les events « semi-privé » qui ne
		// sont pas taggés comme nécessitant un waiver.
		if ( ! cordespace_waivers_gating_cart_has_events_requiring_waivers() ) {
			return;
		}
		?>
		<div class="cordespace-waivers-gate cordespace-waivers-gate--info" role="status" style="margin:0 0 1.5rem; padding:1.2rem 1.5rem; background:#eef5fd; border:2px solid #2c70b8; border-left:6px solid #2c70b8; border-radius:6px; color:#1c2d3c;">
			<p style="margin:0 0 0.6rem;">
				<strong>ℹ️ <?php esc_html_e( 'Document à signer après ton achat.', 'cordespace-snippets' ); ?></strong>
				<?php esc_html_e( 'Ton panier contient un cours qui nécessite la signature d\'un document.', 'cordespace-snippets' ); ?>
			</p>
			<p style="margin:0;">
				<?php esc_html_e( 'À la finalisation de ta commande, un compte sera créé et un courriel te sera envoyé pour y accéder. Tu pourras alors signer le document dans la rubrique « Mes waivers » du menu « Mon compte » avant ton cours. Tu ne le signes qu\'une seule fois — il restera valide pour tes futurs cours du même type.', 'cordespace-snippets' ); ?>
			</p>
		</div>
		<?php
		return;
	}

	$missing = cordespace_waivers_gating_missing_for_current_cart();
	if ( empty( $missing ) ) {
		return;
	}

	$checkout_url = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url();
	?>
	<div class="cordespace-waivers-gate" role="alert" style="margin:0 0 1.5rem; padding:1.2rem 1.5rem; background:#fdecea; border:2px solid #d63638; border-left:6px solid #d63638; border-radius:6px; color:#3c1c1c;">
		<?php foreach ( $missing as $row ) :
			$waiver       = $row['waiver'];
			$event_names  = $row['event_names'];
			$sign_url     = cordespace_waivers_get_sign_url( (int) $waiver->ID, $checkout_url );
			$waiver_title = get_the_title( $waiver );
			$intro        = count( $event_names ) > 1
				? __( 'Plusieurs cours de ton panier nécessitent ta signature :', 'cordespace-snippets' )
				: __( 'Ce cours nécessite ta signature :', 'cordespace-snippets' );
			?>
			<div style="padding:0.4rem 0;">
				<p style="margin:0 0 0.4rem;">
					<strong>⚠️ <?php echo esc_html( $intro ); ?></strong> <?php echo esc_html( $waiver_title ); ?>.
				</p>
				<p style="margin:0 0 0.8rem; font-size:0.95em;">
					<?php esc_html_e( 'Tu ne le signes qu\'une seule fois. Il sera ensuite consultable dans la rubrique « Mes waivers » du menu « Mon compte » et tu n\'auras plus à le signer pour tes futurs cours du même type.', 'cordespace-snippets' ); ?>
				</p>
				<p style="margin:0;">
					<a href="<?php echo esc_url( $sign_url ); ?>" class="button" style="background:#1a1a2e; color:#fff; padding:0.55rem 1.1rem; text-decoration:none; border-radius:4px; display:inline-block;">
						<?php esc_html_e( 'Signer maintenant', 'cordespace-snippets' ); ?>
					</a>
				</p>
			</div>
		<?php endforeach; ?>
	</div>
	<?php
}
add_action( 'woocommerce_before_checkout_form', 'cordespace_waivers_gating_render_block', 5 );
add_action( 'woocommerce_before_cart',          'cordespace_waivers_gating_render_block', 5 );

/**
 * Filtre the_content pour injecter l'encadré gating sur les pages WC Blocks
 * (cart et checkout en mode Gutenberg). Sur ce thème/setup, les hooks classiques
 * woocommerce_before_cart / woocommerce_before_checkout_form ne firent pas —
 * mais the_content fire pour les 2 modes (Blocks + shortcode legacy).
 *
 * Approche identique au module prof-warning qui a le même problème.
 */
function cordespace_waivers_gating_inject_via_content( $content ) {
	if ( ! function_exists( 'is_cart' ) || ! function_exists( 'is_checkout' ) ) {
		return $content;
	}
	if ( ! is_cart() && ! is_checkout() ) {
		return $content;
	}

	// Capture le rendu de cordespace_waivers_gating_render_block() en string
	ob_start();
	cordespace_waivers_gating_render_block();
	$banner = ob_get_clean();

	if ( $banner === '' ) {
		return $content;
	}
	return $banner . $content;
}
add_filter( 'the_content', 'cordespace_waivers_gating_inject_via_content', 5 );

/**
 * Helper : le panier contient-il au moins un event Amelia ?
 */
function cordespace_waivers_gating_cart_has_amelia_event(): bool {
	if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
		return false;
	}
	foreach ( WC()->cart->get_cart() as $cart_item ) {
		if ( empty( $cart_item['ameliabooking'] ) || ! is_array( $cart_item['ameliabooking'] ) ) {
			continue;
		}
		if ( ( $cart_item['ameliabooking']['type'] ?? '' ) === 'event' ) {
			return true;
		}
	}
	return false;
}

/**
 * Vérifie si le panier contient AU MOINS UN event Amelia qui nécessite la
 * signature d'un waiver (= au moins 1 waiver-default applicable par tag).
 *
 * Utilisé pour le cas non connecté·e : on ne sait pas si l'user a déjà signé,
 * mais on peut au moins ne PAS afficher le bandeau pour les events qui n'ont
 * aucun waiver applicable (ex : events « semi-privé » qui ne sont pas taggés
 * comme nécessitant un waiver « Initiation »).
 */
function cordespace_waivers_gating_cart_has_events_requiring_waivers(): bool {
	if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
		return false;
	}
	if ( ! function_exists( 'cordespace_waivers_applicable_defaults_for_amelia_event' ) ) {
		// Fallback safe : si le module defaults n'est pas chargé, mieux vaut
		// afficher le bandeau (faux positif) que de le rater (faux négatif).
		return cordespace_waivers_gating_cart_has_amelia_event();
	}
	foreach ( WC()->cart->get_cart() as $cart_item ) {
		if ( empty( $cart_item['ameliabooking'] ) || ! is_array( $cart_item['ameliabooking'] ) ) {
			continue;
		}
		$booking = $cart_item['ameliabooking'];
		if ( ( $booking['type'] ?? '' ) !== 'event' ) {
			continue;
		}
		$event_id = isset( $booking['eventId'] ) ? (int) $booking['eventId'] : 0;
		if ( $event_id <= 0 ) {
			continue;
		}
		$applicable = cordespace_waivers_applicable_defaults_for_amelia_event( $event_id );
		if ( ! empty( $applicable ) ) {
			return true;
		}
	}
	return false;
}

// Historique du blocage (retiré 2026-06-02) :
//   - On a eu un hook woocommerce_after_checkout_validation (checkout classique)
//   - Puis un hook woocommerce_store_api_checkout_update_order_from_request
//     (checkout WC Blocks) qui throwait une RouteException pour bloquer l'order
//   - Décision de Tess : ne plus bloquer. La cliente peut finaliser sa commande
//     sans waiver signé. La signature est demandée APRÈS via le module
//     waivers-post-purchase-prompt (page de remerciement) et le module
//     waivers-email-reminder (relance 48h avant l'événement). Filet final :
//     badge "WAIVER MANQUANT" affiché au prof dans waivers-prof-badge.
//   - Avantage : ne dépend plus du comportement précis du Store API entre les
//     maj WC/Blocks/Amelia/MyCred.
