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
 * Indépendance des waivers :
 *   Ce module ne se coordonne PAS avec le module waivers-checkout-warning.
 *   Si une personne est validée pour le type semi-privé MAIS n'a pas signé
 *   son waiver, le bandeau waiver continuera de s'afficher en plus. Les 2
 *   bandeaux peuvent cohabiter dans le cart/checkout. C'est volontaire :
 *   les 2 mécanismes adressent des préoccupations différentes (autorisation
 *   sociale d'accès à un type d'event vs consentement légal au risque).
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
 * Helper bas niveau : check du status pour une cellule précise (user, type, tag).
 * Pour les types sans tags, passer $tag = '' (= row binaire).
 */
function cordespace_evgating_is_user_approved_for_tag( int $user_id, int $event_type_id, string $tag ): bool {
	if ( $user_id <= 0 || $event_type_id <= 0 ) {
		return false;
	}
	global $wpdb;
	$table  = cordespace_event_gating_table_name();
	$status = $wpdb->get_var( $wpdb->prepare(
		"SELECT status FROM {$table} WHERE event_type_id = %d AND user_id = %d AND tag = %s LIMIT 1",
		$event_type_id,
		$user_id,
		$tag
	) );
	return $status === 'approved';
}

/**
 * Vérifie si un user est approved pour un type, en tenant compte des tags
 * de l'event.
 *
 * Sémantique :
 *   - Si le type a des tags configurés : calcule les tags COMMUNS entre
 *     l'event et le type. Si AU MOINS UN tag commun a statut 'approved'
 *     pour cet user → return true (OR par tag).
 *   - Si le type n'a pas de tags configurés (cas appointments salles) :
 *     check binaire sur la row (type, user, tag = '').
 *
 * @param int      $user_id     ID WP user
 * @param int      $type_id     Post ID du CPT cordespace_evtype
 * @param string[] $event_tags  Tags Amelia de l'event (vide pour appointments)
 */
function cordespace_evgating_is_user_approved_for_type( int $user_id, int $type_id, array $event_tags = [] ): bool {
	if ( $user_id <= 0 || $type_id <= 0 ) {
		return false;
	}

	$type_tags = function_exists( 'cordespace_event_gating_get_tags' )
		? cordespace_event_gating_get_tags( $type_id )
		: [];

	if ( empty( $type_tags ) ) {
		// Type sans tags : check binaire sur la row tag = ''
		return cordespace_evgating_is_user_approved_for_tag( $user_id, $type_id, '' );
	}

	$common = array_values( array_intersect( $event_tags, $type_tags ) );
	if ( empty( $common ) ) {
		// Theoriquement n'arrive pas car applicable_types_for_amelia_event
		// filtre deja sur le matching de tags. Mais defensif.
		return false;
	}

	foreach ( $common as $tag ) {
		if ( cordespace_evgating_is_user_approved_for_tag( $user_id, $type_id, (string) $tag ) ) {
			return true;
		}
	}
	return false;
}

/**
 * @deprecated Préférer is_user_approved_for_type() ou is_user_approved_for_tag().
 *
 * Gardée pour rétro-compat éventuelle. Sans tags, tombe sur le check binaire
 * (tag = '') — ce qui ne couvre PAS les types avec tags configurés.
 */
function cordespace_evgating_is_user_approved( int $user_id, int $event_type_id ): bool {
	return cordespace_evgating_is_user_approved_for_tag( $user_id, $event_type_id, '' );
}

/**
 * Renvoie les items du panier qui sont BLOQUÉS pour l'user actuellement
 * connecté (ou anonyme).
 *
 * Logique OR : un item est bloqué si AUCUN des types applicables à cet item
 * n'a l'user en approved. Autrement dit, il suffit d'être validé·e dans 1 seul
 * type applicable pour avoir accès. C'est ce qui permet à un membre de
 * « Semi-privé (complet) » de réserver des events « performances » sans avoir
 * besoin d'être aussi listé·e dans le type « Semi-privé (performances) » :
 * l'event a 2 types applicables, et il suffit que l'user soit dans 1 des 2.
 *
 * @return array<string, array{name: string, kind: 'event'|'appointment', applicable_types: int[]}>
 *               Indexé par une clé unique de l'item (event_<id> ou appt_<svcid>).
 *               Vide si rien ne bloque.
 */
function cordespace_evgating_blocked_items_for_current_cart(): array {
	if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
		return [];
	}

	$user_id = get_current_user_id();
	$blocked = []; // [item_key => [name, kind, applicable_types]]

	foreach ( WC()->cart->get_cart() as $cart_item ) {
		if ( empty( $cart_item['ameliabooking'] ) || ! is_array( $cart_item['ameliabooking'] ) ) {
			continue;
		}
		$booking = $cart_item['ameliabooking'];
		$btype   = (string) ( $booking['type'] ?? '' );

		$applicable = [];
		$item_name  = '';
		$item_key   = '';
		$kind       = '';
		$item_tags  = []; // tags Amelia de l'event (vide pour appointments)

		if ( $btype === 'event' ) {
			$event_id = isset( $booking['eventId'] ) ? (int) $booking['eventId'] : 0;
			if ( $event_id <= 0 ) {
				continue;
			}
			$applicable = cordespace_event_gating_applicable_types_for_amelia_event( $event_id );
			$item_tags  = function_exists( 'cordespace_event_gating_get_amelia_event_tags' )
				? cordespace_event_gating_get_amelia_event_tags( $event_id )
				: [];
			$item_name  = (string) ( $booking['name'] ?? '' );
			$item_key   = 'event_' . $event_id;
			$kind       = 'event';
		} elseif ( $btype === 'appointment' ) {
			$applicable = cordespace_event_gating_applicable_types_for_amelia_appointment();
			$item_name  = (string) ( $booking['name'] ?? $booking['serviceName'] ?? __( 'Réservation de salle', 'cordespace-snippets' ) );
			$svc_id     = isset( $booking['serviceId'] ) ? (int) $booking['serviceId'] : 0;
			$item_key   = 'appt_' . ( $svc_id > 0 ? $svc_id : md5( $item_name ) );
			$kind       = 'appointment';
			// $item_tags reste [] : les appointments matchent par toggle global,
			// le check is_user_approved_for_type tombera dans la branche
			// "type sans tags" (= check binaire sur tag = '').
		} else {
			continue;
		}

		if ( empty( $applicable ) ) {
			continue; // pas de type applicable → pas de gating sur cet item
		}

		// OR par type : si l'user est validé·e dans AU MOINS UN type applicable, OK.
		// Pour chaque type applicable, on délègue à is_user_approved_for_type qui
		// gère lui-même le OR par tag commun (event ∩ type) en interne.
		$approved_in_any = false;
		if ( $user_id > 0 ) {
			foreach ( $applicable as $type_id ) {
				if ( cordespace_evgating_is_user_approved_for_type( $user_id, (int) $type_id, $item_tags ) ) {
					$approved_in_any = true;
					break;
				}
			}
		}

		if ( $approved_in_any ) {
			continue; // accès accordé pour cet item
		}

		// Bloqué : on enregistre l'item + ses types alternatifs (validation
		// dans n'importe lequel suffirait à débloquer).
		if ( ! isset( $blocked[ $item_key ] ) ) {
			$blocked[ $item_key ] = [
				'name'             => $item_name,
				'kind'             => $kind,
				'applicable_types' => array_values( array_map( 'intval', $applicable ) ),
			];
		}
	}

	return $blocked;
}

/**
 * Alias rétro-compatible : certains modules externes pourraient l'appeler
 * sous l'ancien nom. Renvoie une structure compatible (groupée par type)
 * dérivée du nouveau résultat. Préférer blocked_items_for_current_cart()
 * pour tout nouveau code.
 *
 * @return array<int, array{type: WP_Post, event_names: string[]}>
 */
function cordespace_evgating_blocked_types_for_current_cart(): array {
	$items   = cordespace_evgating_blocked_items_for_current_cart();
	$by_type = [];
	foreach ( $items as $item ) {
		foreach ( $item['applicable_types'] as $type_id ) {
			$type_id = (int) $type_id;
			if ( ! isset( $by_type[ $type_id ] ) ) {
				$type = get_post( $type_id );
				if ( ! $type ) {
					continue;
				}
				$by_type[ $type_id ] = [ 'type' => $type, 'event_names' => [] ];
			}
			if ( $item['name'] !== '' && ! in_array( $item['name'], $by_type[ $type_id ]['event_names'], true ) ) {
				$by_type[ $type_id ]['event_names'][] = $item['name'];
			}
		}
	}
	return $by_type;
}

// ============================================================================
// 2) Rendu visuel du bandeau (cart + checkout, classique et WC Blocks)
// ============================================================================

function cordespace_evgating_render_block_banner(): void {
	$items = cordespace_evgating_blocked_items_for_current_cart();
	if ( empty( $items ) ) {
		return;
	}

	$is_logged_in = is_user_logged_in();
	// Cible : la page "Mon compte" custom (= page qui contient le shortcode
	// [cordespace_mon_espace], résolue dynamiquement par le helper du core).
	// Fallback sur la page WC myaccount si le helper n'est pas dispo ou ne
	// trouve rien.
	$cart_url   = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url();
	$mon_compte = function_exists( 'cordespace_get_mon_espace_url' ) ? cordespace_get_mon_espace_url() : '';
	if ( $mon_compte === '' ) {
		$mon_compte = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url();
	}
	$login_url = add_query_arg( 'redirect_to', urlencode( $cart_url ), $mon_compte );
	?>
	<div class="cordespace-evgating-block" role="alert" style="margin:0 0 1.5rem; padding:1.4rem 1.6rem; background:#fdecea; border:2px solid #d63638; border-left:6px solid #d63638; border-radius:6px; color:#3c1c1c;">
		<h3 style="margin:0 0 0.6rem; color:#3c1c1c; font-size:1.15em; font-weight:700;">
			⛔ <?php echo count( $items ) > 1
				? esc_html__( 'Validation requise pour ces réservations', 'cordespace-snippets' )
				: esc_html__( 'Validation requise pour cette réservation', 'cordespace-snippets' ); ?>
		</h3>

		<?php
		// Pas de texte d'intro hardcode ici : le contenu editable du type
		// (post_content du CPT cordespace_evtype) est affiche plus bas dans
		// chaque section, ce qui permet a Tess de rediger son propre message
		// (incluant le contact gestion@cordespace.com pour les nouvelles
		// personnes sans compte). Retirer le hardcoded evite la redondance.
		?>

		<?php foreach ( $items as $item ) :
			$icon            = $item['kind'] === 'appointment' ? '🏠' : '📅';
			$applicable_ids  = $item['applicable_types'];
			$multi_types     = count( $applicable_ids ) > 1;
			?>
			<div style="margin:0.8rem 0; padding:0.9rem 1.1rem; background:rgba(255,255,255,0.55); border-radius:5px;">
				<p style="margin:0 0 0.6rem; font-weight:700; font-size:1.05em;">
					<?php echo esc_html( $icon ); ?> <?php echo esc_html( $item['name'] ); ?>
				</p>
				<p style="margin:0 0 0.6rem; font-size:0.95em; color:#5c1c1c;">
					<?php if ( $multi_types ) :
						esc_html_e( 'Pour pouvoir réserver, tu dois être validé·e dans AU MOINS UN de ces types :', 'cordespace-snippets' );
					else :
						esc_html_e( 'Pour pouvoir réserver, tu dois être validé·e dans :', 'cordespace-snippets' );
					endif; ?>
				</p>
				<div class="cordespace-evgating-applicable-types">
					<?php foreach ( $applicable_ids as $type_id ) :
						$type = get_post( (int) $type_id );
						if ( ! $type ) {
							continue;
						}
						$info_url = cordespace_event_gating_get_info_url( (int) $type_id );
						// IMPORTANT : ne PAS appeler apply_filters('the_content', ...) ici
						// parce que cette fonction est elle-même appelée DEPUIS le filtre
						// the_content (via inject_banner_via_content). Récursion infinie.
						$type_html = wpautop( wp_kses_post( (string) $type->post_content ) );
						?>
						<div style="margin:0.4rem 0; padding:0.6rem 0.8rem; background:rgba(255,255,255,0.45); border-radius:4px;">
							<p style="margin:0 0 0.3rem; font-weight:600;">
								🔒 <?php echo esc_html( get_the_title( $type ) ); ?>
							</p>
							<?php if ( $type_html !== '' ) : ?>
								<div class="cordespace-evgating-block-text" style="margin:0 0 0.5rem; font-size:0.9em; line-height:1.45;">
									<?php echo wp_kses_post( $type_html ); ?>
								</div>
							<?php endif; ?>
							<?php if ( $info_url !== '' ) : ?>
								<a href="<?php echo esc_url( $info_url ); ?>" class="button" style="background:#1a1a2e; color:#fff; padding:0.45rem 0.95rem; text-decoration:none; border-radius:4px; display:inline-block; font-size:0.9em;">
									ℹ️ <?php esc_html_e( 'En savoir plus', 'cordespace-snippets' ); ?>
								</a>
							<?php endif; ?>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		<?php endforeach; ?>

		<?php if ( ! $is_logged_in ) : ?>
			<p style="margin:1rem 0 0;">
				<a href="<?php echo esc_url( $login_url ); ?>" class="button" style="background:#fff; color:#1a1a2e; padding:0.55rem 1.1rem; text-decoration:none; border-radius:4px; display:inline-block; border:1px solid #1a1a2e;">
					<?php esc_html_e( 'Aller à Mon compte', 'cordespace-snippets' ); ?>
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
	// Garde anti-récursion : si on est déjà dans cette fonction
	// (parce que le_content est appliqué dans le banner par mégarde), on
	// renvoie le content tel quel sans repasser dans la logique.
	static $running = false;
	if ( $running ) {
		return $content;
	}

	if ( ! function_exists( 'is_cart' ) || ! function_exists( 'is_checkout' ) ) {
		return $content;
	}
	if ( ! is_cart() && ! is_checkout() ) {
		return $content;
	}

	$running = true;
	ob_start();
	cordespace_evgating_render_block_banner();
	$banner = ob_get_clean();
	$running = false;

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

	$items = cordespace_evgating_blocked_items_for_current_cart();
	if ( empty( $items ) ) {
		return;
	}

	$messages = [];
	foreach ( $items as $item ) {
		$type_titles = [];
		foreach ( $item['applicable_types'] as $type_id ) {
			$t = get_post( (int) $type_id );
			if ( $t ) {
				$type_titles[] = get_the_title( $t );
			}
		}
		if ( empty( $type_titles ) ) {
			continue;
		}
		if ( count( $type_titles ) > 1 ) {
			$messages[] = sprintf(
				/* translators: 1: item name (event or appointment), 2: comma-separated list of type names */
				__( 'Tu dois être validé·e dans au moins un de ces types pour réserver « %1$s » : %2$s.', 'cordespace-snippets' ),
				$item['name'],
				implode( ', ', $type_titles )
			);
		} else {
			$messages[] = sprintf(
				/* translators: 1: item name (event or appointment), 2: type name */
				__( 'Tu dois être validé·e pour le type « %2$s » pour réserver « %1$s ».', 'cordespace-snippets' ),
				$item['name'],
				$type_titles[0]
			);
		}
	}

	if ( empty( $messages ) ) {
		return;
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
	$items = cordespace_evgating_blocked_items_for_current_cart();
	if ( empty( $items ) ) {
		return;
	}
	if ( ! function_exists( 'wc_add_notice' ) ) {
		return;
	}
	foreach ( $items as $item ) {
		$type_titles = [];
		foreach ( $item['applicable_types'] as $type_id ) {
			$t = get_post( (int) $type_id );
			if ( $t ) {
				$type_titles[] = get_the_title( $t );
			}
		}
		if ( empty( $type_titles ) ) {
			continue;
		}
		if ( count( $type_titles ) > 1 ) {
			$notice = sprintf(
				/* translators: 1: item name, 2: comma-separated type names */
				__( '⛔ Tu dois être validé·e dans au moins un de ces types pour réserver « %1$s » : %2$s.', 'cordespace-snippets' ),
				$item['name'],
				implode( ', ', $type_titles )
			);
		} else {
			$notice = sprintf(
				/* translators: 1: item name, 2: type name */
				__( '⛔ Tu dois être validé·e pour le type « %2$s » pour réserver « %1$s ».', 'cordespace-snippets' ),
				$item['name'],
				$type_titles[0]
			);
		}
		wc_add_notice( $notice, 'error' );
	}
}
add_action( 'woocommerce_after_checkout_validation', 'cordespace_evgating_classic_checkout_block' );
