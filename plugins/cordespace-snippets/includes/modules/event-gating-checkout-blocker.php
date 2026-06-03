<?php
/**
 * Module: event-gating-checkout-blocker
 *
 * Phase 3 du gating events : BLOQUE le checkout WooCommerce quand le panier
 * contient un événement Amelia de type gated (semi-privé, privé, etc.) et
 * que la personne connectée n'est pas approuvée pour ce type.
 *
 * Logique :
 *   1. Scan des cart items pour trouver les events Amelia
 *   2. Pour chaque event, trouve les types applicables (matching par tag
 *      via cordespace_event_gating_applicable_types_for_amelia_event)
 *   3. Pour chaque type, vérifie si l'user connecté est approved
 *   4. Si NON approved (ou non connecté) → bandeau rouge bloquant + Store
 *      API throw RouteException
 *
 * Affichage du bandeau :
 *   - Cart classique : woocommerce_before_cart
 *   - Checkout classique : woocommerce_before_checkout_form
 *   - WC Blocks (cart/checkout en Gutenberg) : filtre the_content
 *     (= même approche que prof-warning et waivers-checkout-warning)
 *
 * Blocage serveur :
 *   - Hook woocommerce_store_api_checkout_update_order_from_request
 *     qui throw une RouteException → bloque l'order côté Store API
 *
 * Pas de bypass admin : si Tess teste avec son compte admin sans être
 * validée pour le type, elle sera aussi bloquée. C'est volontaire — pas
 * de mécanisme caché qui pourrait être abusé.
 *
 * Dépend de :
 *   - event-gating-cpt (helpers applicable_types_for_amelia_event, get_info_url)
 *   - event-gating-schema (table approvals)
 *   - WooCommerce + Amelia
 */

defined( 'ABSPATH' ) || exit;

// ============================================================================
// 1) Helpers — quels types bloquent ce panier pour cet user ?
// ============================================================================

/**
 * Vérifie si un user est approved pour un type d'événement donné.
 */
function cordespace_evgating_is_user_approved( int $user_id, int $event_type_id ): bool {
	if ( $user_id <= 0 || $event_type_id <= 0 ) {
		return false;
	}
	global $wpdb;
	$table  = cordespace_event_gating_table_name();
	$status = $wpdb->get_var( $wpdb->prepare(
		"SELECT status FROM {$table} WHERE event_type_id = %d AND user_id = %d LIMIT 1",
		$event_type_id,
		$user_id
	) );
	return $status === 'approved';
}

/**
 * Renvoie les types d'événements qui bloquent le panier actuel, pour le user
 * actuellement connecté (ou anonyme).
 *
 * @return array<int, array{type: WP_Post, event_names: string[]}>
 *               Indexé par event_type_id. Vide si rien ne bloque.
 */
function cordespace_evgating_blocked_types_for_current_cart(): array {
	if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
		return [];
	}

	$user_id = get_current_user_id();

	// Collecte des events Amelia présents dans le panier
	$amelia_events = []; // [event_id => event_name]
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
		$amelia_events[ $event_id ] = (string) ( $booking['name'] ?? '' );
	}

	if ( empty( $amelia_events ) ) {
		return [];
	}

	// Pour chaque event, quels types s'appliquent ? Pour chaque type, l'user
	// est-iel approved ?
	$blocked = []; // [type_id => ['type' => WP_Post, 'event_names' => string[]]]
	foreach ( $amelia_events as $event_id => $event_name ) {
		$applicable = cordespace_event_gating_applicable_types_for_amelia_event( $event_id );
		foreach ( $applicable as $type_id ) {
			$type_id = (int) $type_id;
			// User connecté ET approved pour ce type → on skip (pas bloqué)
			if ( $user_id > 0 && cordespace_evgating_is_user_approved( $user_id, $type_id ) ) {
				continue;
			}
			// Sinon bloqué : on collecte
			if ( ! isset( $blocked[ $type_id ] ) ) {
				$type = get_post( $type_id );
				if ( ! $type ) {
					continue;
				}
				$blocked[ $type_id ] = [
					'type'        => $type,
					'event_names' => [],
				];
			}
			if ( $event_name !== '' && ! in_array( $event_name, $blocked[ $type_id ]['event_names'], true ) ) {
				$blocked[ $type_id ]['event_names'][] = $event_name;
			}
		}
	}
	return $blocked;
}

// ============================================================================
// 2) Rendu visuel du bandeau (cart + checkout, classique et WC Blocks)
// ============================================================================

function cordespace_evgating_render_block_banner(): void {
	$blocked = cordespace_evgating_blocked_types_for_current_cart();
	if ( empty( $blocked ) ) {
		return;
	}

	$is_logged_in = is_user_logged_in();
	$myaccount    = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url();
	$cart_url     = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url();
	$login_url    = add_query_arg( 'redirect_to', urlencode( $cart_url ), $myaccount );
	?>
	<div class="cordespace-evgating-block" role="alert" style="margin:0 0 1.5rem; padding:1.4rem 1.6rem; background:#fdecea; border:2px solid #d63638; border-left:6px solid #d63638; border-radius:6px; color:#3c1c1c;">
		<h3 style="margin:0 0 0.6rem; color:#3c1c1c; font-size:1.15em; font-weight:700;">
			⛔ <?php echo count( $blocked ) > 1
				? esc_html__( 'Validation requise pour ces événements', 'cordespace-snippets' )
				: esc_html__( 'Validation requise pour cet événement', 'cordespace-snippets' ); ?>
		</h3>

		<?php if ( ! $is_logged_in ) : ?>
			<p style="margin:0 0 1rem;">
				<?php esc_html_e( 'Ton panier contient un ou plusieurs événements qui nécessitent une validation préalable par l\'équipe Cordespace. Tu dois te connecter avec un compte validé pour pouvoir les réserver.', 'cordespace-snippets' ); ?>
			</p>
		<?php endif; ?>

		<?php foreach ( $blocked as $row ) :
			$type        = $row['type'];
			$events      = $row['event_names'];
			$info_url    = cordespace_event_gating_get_info_url( (int) $type->ID );
			$banner_html = apply_filters( 'the_content', (string) $type->post_content );
			?>
			<div style="margin:0.8rem 0; padding:0.9rem 1.1rem; background:rgba(255,255,255,0.55); border-radius:5px;">
				<p style="margin:0 0 0.4rem; font-weight:700; font-size:1.05em;">
					🔒 <?php echo esc_html( get_the_title( $type ) ); ?>
				</p>
				<?php if ( ! empty( $events ) ) : ?>
					<p style="margin:0 0 0.5rem; font-size:0.9em; color:#5c1c1c;">
						<?php esc_html_e( 'Concerné·e dans ton panier :', 'cordespace-snippets' ); ?>
						<em><?php echo esc_html( implode( ', ', $events ) ); ?></em>
					</p>
				<?php endif; ?>
				<?php if ( $banner_html !== '' ) : ?>
					<div class="cordespace-evgating-block-text" style="margin:0 0 0.6rem; font-size:0.95em; line-height:1.5;">
						<?php echo wp_kses_post( $banner_html ); ?>
					</div>
				<?php endif; ?>
				<?php if ( $info_url !== '' ) : ?>
					<p style="margin:0.5rem 0 0;">
						<a href="<?php echo esc_url( $info_url ); ?>" class="button" style="background:#1a1a2e; color:#fff; padding:0.55rem 1.1rem; text-decoration:none; border-radius:4px; display:inline-block;">
							ℹ️ <?php esc_html_e( 'En savoir plus', 'cordespace-snippets' ); ?>
						</a>
					</p>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>

		<?php if ( ! $is_logged_in ) : ?>
			<p style="margin:1rem 0 0;">
				<a href="<?php echo esc_url( $login_url ); ?>" class="button" style="background:#fff; color:#1a1a2e; padding:0.55rem 1.1rem; text-decoration:none; border-radius:4px; display:inline-block; border:1px solid #1a1a2e;">
					<?php esc_html_e( 'Me connecter', 'cordespace-snippets' ); ?>
				</a>
			</p>
		<?php endif; ?>
	</div>
	<?php
}
add_action( 'woocommerce_before_cart',          'cordespace_evgating_render_block_banner', 5 );
add_action( 'woocommerce_before_checkout_form', 'cordespace_evgating_render_block_banner', 5 );

/**
 * Filtre the_content pour injecter le bandeau sur les pages WC Blocks
 * (cart et checkout en mode Gutenberg). Les hooks classiques ne firent
 * pas dans ce contexte — même approche que prof-warning et waivers.
 */
function cordespace_evgating_inject_banner_via_content( $content ) {
	if ( ! function_exists( 'is_cart' ) || ! function_exists( 'is_checkout' ) ) {
		return $content;
	}
	if ( ! is_cart() && ! is_checkout() ) {
		return $content;
	}
	ob_start();
	cordespace_evgating_render_block_banner();
	$banner = ob_get_clean();
	if ( $banner === '' ) {
		return $content;
	}
	return $banner . $content;
}
add_filter( 'the_content', 'cordespace_evgating_inject_banner_via_content', 5 );

// ============================================================================
// 3) Blocage serveur via Store API (WC Blocks checkout)
// ============================================================================

/**
 * Bloque l'order côté serveur si le panier contient un type gated pour lequel
 * l'user n'est pas validé·e. Throw une RouteException qui sera affichée
 * comme erreur en haut du checkout WC Blocks.
 *
 * Le hook ne fire que sur le checkout WC Blocks (l'endpoint REST Store API).
 * Pour le checkout classique, le blocage est assuré par
 * woocommerce_after_checkout_validation plus bas (filet de sécurité).
 */
function cordespace_evgating_store_api_block( $order, $request ): void {
	$exception_class = '\\Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException';
	if ( ! class_exists( $exception_class ) ) {
		$exception_class = '\\Automattic\\WooCommerce\\Blocks\\StoreApi\\Exceptions\\RouteException';
		if ( ! class_exists( $exception_class ) ) {
			return; // Pas de classe d'exception → aucun moyen de bloquer
		}
	}

	$blocked = cordespace_evgating_blocked_types_for_current_cart();
	if ( empty( $blocked ) ) {
		return;
	}

	$messages = [];
	foreach ( $blocked as $row ) {
		$type     = $row['type'];
		$type_lbl = get_the_title( $type );
		$info_url = cordespace_event_gating_get_info_url( (int) $type->ID );
		$msg      = sprintf(
			/* translators: %s = type label */
			__( 'Tu dois être validé·e pour réserver « %s ».', 'cordespace-snippets' ),
			$type_lbl
		);
		if ( $info_url !== '' ) {
			$msg .= ' ' . sprintf(
				/* translators: %s = info URL */
				__( 'Pour en savoir plus : %s', 'cordespace-snippets' ),
				$info_url
			);
		}
		$messages[] = $msg;
	}

	throw new $exception_class(
		'cordespace_evgating_not_approved',
		implode( ' --- ', $messages ),
		400
	);
}
add_action( 'woocommerce_store_api_checkout_update_order_from_request', 'cordespace_evgating_store_api_block', 10, 2 );

/**
 * Filet de sécurité pour le checkout classique (non-Blocks).
 * Ajoute une erreur de validation qui empêche la création de l'order.
 */
function cordespace_evgating_classic_checkout_block(): void {
	$blocked = cordespace_evgating_blocked_types_for_current_cart();
	if ( empty( $blocked ) ) {
		return;
	}
	if ( ! function_exists( 'wc_add_notice' ) ) {
		return;
	}
	foreach ( $blocked as $row ) {
		$type     = $row['type'];
		$type_lbl = get_the_title( $type );
		$info_url = cordespace_event_gating_get_info_url( (int) $type->ID );
		$notice   = sprintf(
			/* translators: %s = type label */
			__( '⛔ Tu dois être validé·e pour réserver « %s ».', 'cordespace-snippets' ),
			$type_lbl
		);
		if ( $info_url !== '' ) {
			$notice .= sprintf(
				/* translators: %s = info URL */
				__( ' Plus d\'infos : %s', 'cordespace-snippets' ),
				esc_url( $info_url )
			);
		}
		wc_add_notice( $notice, 'error' );
	}
}
add_action( 'woocommerce_after_checkout_validation', 'cordespace_evgating_classic_checkout_block' );
