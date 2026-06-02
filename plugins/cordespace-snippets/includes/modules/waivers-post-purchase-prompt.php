<?php
/**
 * Module: waivers-post-purchase-prompt
 *
 * Affiche une banderole CTA « Signer maintenant » à 3 endroits où la cliente
 * arrive naturellement après un achat ou en revenant dans son espace :
 *
 *   1. Page « Merci pour votre commande » (hook woocommerce_thankyou)
 *      → scan des line items de l'order pour les events Amelia, vérifie les
 *        documents requis non signés. Cas le plus important : ça enchaîne
 *        immédiatement après le paiement.
 *   2. Dashboard WC /mon-compte/ (hook woocommerce_account_dashboard)
 *      → scan des bookings Amelia upcoming du user, vérifie les documents
 *        requis non signés. Filet pour qui ne signe pas tout de suite à
 *        l'achat puis revient plus tard.
 *   3. Page custom /mon-espace/ (hook cordespace_mon_espace_section_client_top_banner)
 *      → même logique que le dashboard WC. C'est la page d'accueil naturelle
 *        des client·es Cordespace, donc on y met aussi le rappel.
 *
 * Architecture pivot 2026-06-02 : on ne BLOQUE plus le checkout. La cliente
 * peut finaliser sa commande sans avoir signé. Ce module est le filet de
 * sécurité #1 : rappel TOUT DE SUITE après l'achat sur la page de
 * remerciement, et à chaque retour dans son compte tant qu'elle n'a pas signé.
 *
 * Filet #2 : email rappel 48h avant l'événement (waivers-email-reminder, à venir).
 * Filet #3 : badge « WAIVER MANQUANT » côté prof jour-J (waivers-prof-badge).
 *
 * Dépend de :
 *   - waivers-defaults     (cordespace_waivers_applicable_defaults_for_amelia_event)
 *   - waivers-store        (cordespace_waivers_has_signed_current)
 *   - waivers-signing-page (cordespace_waivers_get_sign_url)
 *   - WooCommerce + Amelia
 */

defined( 'ABSPATH' ) || exit;

/**
 * Trouve l'URL de la page Cordespace utilisant le shortcode [cordespace_mon_espace].
 * C'est la page « Mon compte » côté Cordespace (différente de la page WC dashboard
 * qu'utilise wc_get_page_permalink('myaccount')).
 *
 * Cache en transient (1 jour) pour éviter une query DB à chaque rendu de banderole.
 * Fallback sur wc_get_page_permalink('myaccount') si aucune page custom trouvée.
 */
function cordespace_waivers_get_mon_espace_url(): string {
	$cached = get_transient( 'cordespace_mon_espace_url' );
	if ( is_string( $cached ) && $cached !== '' ) {
		return $cached;
	}

	global $wpdb;
	$page_id = (int) $wpdb->get_var(
		"SELECT ID FROM {$wpdb->posts}
		  WHERE post_content LIKE '%cordespace_mon_espace%'
		    AND post_status = 'publish'
		    AND post_type   = 'page'
		  ORDER BY ID DESC LIMIT 1"
	);

	if ( $page_id > 0 ) {
		$url = (string) get_permalink( $page_id );
	} elseif ( function_exists( 'wc_get_page_permalink' ) ) {
		$url = (string) wc_get_page_permalink( 'myaccount' );
	} else {
		$url = home_url( '/' );
	}

	set_transient( 'cordespace_mon_espace_url', $url, DAY_IN_SECONDS );
	return $url;
}

/**
 * Renvoie les documents requis NON signés pour une commande WC donnée.
 *
 * @return array<int, array{waiver: WP_Post, event_names: string[]}>
 *               Indexé par waiver_id. Vide si rien à signer.
 */
function cordespace_waivers_post_purchase_missing_for_order( int $order_id ): array {
	if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
		return [];
	}
	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return [];
	}
	$user_id = (int) $order->get_user_id();
	if ( $user_id <= 0 ) {
		// Pas de user lié à la commande (cas guest improbable vu nos settings WC
		// — woocommerce_enable_guest_checkout = no). On skip silencieusement.
		return [];
	}

	// Collecte des events Amelia parmi les line items de la commande
	$amelia_events = []; // [event_id => event_name]
	foreach ( $order->get_items() as $item ) {
		$ameliabooking = $item->get_meta( 'ameliabooking', true );
		if ( ! is_array( $ameliabooking ) ) {
			continue;
		}
		if ( ( $ameliabooking['type'] ?? '' ) !== 'event' ) {
			continue;
		}
		$event_id = isset( $ameliabooking['eventId'] ) ? (int) $ameliabooking['eventId'] : 0;
		if ( $event_id <= 0 ) {
			continue;
		}
		$amelia_events[ $event_id ] = (string) ( $ameliabooking['name'] ?? '' );
	}

	if ( empty( $amelia_events ) ) {
		return [];
	}

	return cordespace_waivers_post_purchase_missing_from_events( $amelia_events, $user_id );
}

/**
 * Renvoie les documents requis NON signés pour les bookings UPCOMING d'un user.
 * Scan direct dans Amelia : tous les events à venir (periodStart > NOW UTC) où
 * le user a un booking approved/pending, dont les documents requis ne sont pas
 * signés.
 *
 * @return array<int, array{waiver: WP_Post, event_names: string[]}>
 */
function cordespace_waivers_post_purchase_missing_for_user_upcoming( int $user_id ): array {
	global $wpdb;
	if ( $user_id <= 0 ) {
		return [];
	}

	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT DISTINCT e.id AS event_id, e.name AS event_name
		   FROM {$wpdb->prefix}amelia_users u
		   JOIN {$wpdb->prefix}amelia_customer_bookings b ON b.customerId = u.id
		   JOIN {$wpdb->prefix}amelia_customer_bookings_to_events_periods bep ON bep.customerBookingId = b.id
		   JOIN {$wpdb->prefix}amelia_events_periods ep ON ep.id = bep.eventPeriodId
		   JOIN {$wpdb->prefix}amelia_events e ON e.id = ep.eventId
		  WHERE u.externalId = %d
		    AND b.status IN ('approved', 'pending')
		    AND ep.periodStart > UTC_TIMESTAMP()",
		$user_id
	), ARRAY_A );

	if ( empty( $rows ) ) {
		return [];
	}

	$amelia_events = [];
	foreach ( $rows as $row ) {
		$amelia_events[ (int) $row['event_id'] ] = (string) $row['event_name'];
	}

	return cordespace_waivers_post_purchase_missing_from_events( $amelia_events, $user_id );
}

/**
 * Helper interne : pour une liste d'events Amelia et un user_id, renvoie la
 * liste des waivers requis NON signés (avec liste des events qui les exigent).
 *
 * @param array<int,string> $amelia_events  [event_id => event_name]
 * @return array<int, array{waiver: WP_Post, event_names: string[]}>
 */
function cordespace_waivers_post_purchase_missing_from_events( array $amelia_events, int $user_id ): array {
	$missing = [];
	foreach ( $amelia_events as $event_id => $event_name ) {
		$applicable = cordespace_waivers_applicable_defaults_for_amelia_event( (int) $event_id );
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
 * Rend la banderole CTA avec la liste des documents à signer.
 * Format identique pour les 3 hooks (thankyou + WC dashboard + cordespace
 * mon-espace), seul l'intro change pour s'adapter au contexte.
 *
 * @param array  $missing  [waiver_id => ['waiver'=>WP_Post, 'event_names'=>string[]]]
 * @param string $context  'thankyou' | 'dashboard' (texte d'intro)
 */
function cordespace_waivers_post_purchase_render_banner( array $missing, string $context = 'dashboard' ): void {
	if ( empty( $missing ) ) {
		return;
	}

	// Redirect_to après signature → ramène vers la page Cordespace « Mon compte »
	// (celle qui utilise [cordespace_mon_espace], pas le dashboard WC standard).
	// Comme ça la cliente voit que la banderole a disparu = confirmation visuelle
	// que sa signature a bien été enregistrée. Helper gère le fallback.
	$account_url = cordespace_waivers_get_mon_espace_url();

	// Textes au SINGULIER : Cordespace n'aura quasi jamais plusieurs documents
	// à signer en même temps. On simplifie pour gagner en lisibilité.
	$intro = $context === 'thankyou'
		? __( 'Merci pour ton achat ! Avant ton prochain cours, prends 2 minutes pour signer le document ci-dessous :', 'cordespace-snippets' )
		: __( 'Tu as encore un document à signer avant ton prochain cours :', 'cordespace-snippets' );
	?>
	<div class="cordespace-waivers-post-purchase" role="alert" style="margin:0 0 1.5rem; padding:1.4rem 1.6rem; background:#fff3cd; border:2px solid #f0ad4e; border-left:6px solid #f0ad4e; border-radius:6px; color:#3c2c00;">
		<h3 style="margin:0 0 0.6rem; font-size:1.15em; font-weight:700; color:#3c2c00;">
			✍️ <?php esc_html_e( 'Document à signer', 'cordespace-snippets' ); ?>
		</h3>
		<p style="margin:0 0 1rem; line-height:1.5;">
			<?php echo esc_html( $intro ); ?>
		</p>
		<?php foreach ( $missing as $row ) :
			$waiver   = $row['waiver'];
			$sign_url = cordespace_waivers_get_sign_url( (int) $waiver->ID, $account_url );
			$events   = $row['event_names'];
			?>
			<div style="margin:0.7rem 0; padding:0.8rem 1rem; background:rgba(255,255,255,0.5); border-radius:5px;">
				<p style="margin:0 0 0.3rem; font-weight:700; font-size:1.02em;">
					📄 <?php echo esc_html( get_the_title( $waiver ) ); ?>
				</p>
				<?php if ( ! empty( $events ) ) : ?>
					<p style="margin:0 0 0.6rem; font-size:0.9em; color:#5c4c00;">
						<?php esc_html_e( 'Requis pour : ', 'cordespace-snippets' ); ?>
						<?php echo esc_html( implode( ', ', $events ) ); ?>
					</p>
				<?php endif; ?>
				<p style="margin:0;">
					<a href="<?php echo esc_url( $sign_url ); ?>" class="button" style="background:#1a1a2e; color:#fff; padding:0.55rem 1.1rem; text-decoration:none; border-radius:4px; display:inline-block;">
						✍️ <?php esc_html_e( 'Signer maintenant', 'cordespace-snippets' ); ?>
					</a>
				</p>
			</div>
		<?php endforeach; ?>
		<p style="margin:1rem 0 0; font-size:0.88em; color:#5c4c00;">
			<?php esc_html_e( 'Tu ne le signes qu\'une seule fois — il restera valide pour tes futurs cours du même type.', 'cordespace-snippets' ); ?>
		</p>
	</div>
	<?php
}

// ============================================================================
// Hooks d'affichage
// ============================================================================

/**
 * Hook 1 : page « Merci pour votre commande » (WC thankyou).
 * Affiche la banderole avec les documents manquants pour CETTE commande.
 */
function cordespace_waivers_post_purchase_render_thankyou( $order_id ): void {
	$order_id = (int) $order_id;
	if ( $order_id <= 0 ) {
		return;
	}
	$missing = cordespace_waivers_post_purchase_missing_for_order( $order_id );
	if ( ! empty( $missing ) ) {
		cordespace_waivers_post_purchase_render_banner( $missing, 'thankyou' );
	}
}
add_action( 'woocommerce_thankyou', 'cordespace_waivers_post_purchase_render_thankyou', 5 );

/**
 * Hook 2 : dashboard /mon-compte/ (WC).
 * Affiche la banderole avec TOUS les documents manquants pour les events
 * upcoming du user (= filet pour qui ne signe pas tout de suite à l'achat).
 */
function cordespace_waivers_post_purchase_render_wc_dashboard(): void {
	$user_id = get_current_user_id();
	if ( $user_id <= 0 ) {
		return;
	}
	$missing = cordespace_waivers_post_purchase_missing_for_user_upcoming( $user_id );
	if ( ! empty( $missing ) ) {
		cordespace_waivers_post_purchase_render_banner( $missing, 'dashboard' );
	}
}
add_action( 'woocommerce_account_dashboard', 'cordespace_waivers_post_purchase_render_wc_dashboard', 5 );

/**
 * Hook 3 : /mon-espace/ Cordespace (custom).
 * Même logique que le dashboard WC. C'est la page d'accueil naturelle des
 * client·es Cordespace, donc on duplique le rappel ici aussi.
 *
 * Le hook cordespace_mon_espace_section_client_top_banner est nouveau —
 * ajouté dans mon-espace.php pour permettre cette injection de banderoles
 * au top, avant le greeting block.
 */
function cordespace_waivers_post_purchase_render_mon_espace( $user ): void {
	$user_id = is_object( $user ) ? (int) $user->ID : (int) $user;
	if ( $user_id <= 0 ) {
		return;
	}
	$missing = cordespace_waivers_post_purchase_missing_for_user_upcoming( $user_id );
	if ( ! empty( $missing ) ) {
		cordespace_waivers_post_purchase_render_banner( $missing, 'dashboard' );
	}
}
add_action( 'cordespace_mon_espace_section_client_top_banner', 'cordespace_waivers_post_purchase_render_mon_espace' );
