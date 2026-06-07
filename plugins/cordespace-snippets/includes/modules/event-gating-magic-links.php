<?php
/**
 * Module: event-gating-magic-links
 *
 * Liens magiques : URLs avec token qui permettent à une personne de bypasser
 * le gating event pour 1 event Amelia spécifique, sans avoir besoin d'être
 * validée dans la matrice.
 *
 * Cas d'usage : Tess invite quelqu'un·e (qui n'a pas de compte / n'est pas
 * dans la liste semi-privé) à un cours spécifique. Elle génère un lien,
 * l'envoie par mail, et la personne peut réserver SEULEMENT cet event sans
 * autre validation.
 *
 * Flow :
 *   1. Tess génère un lien depuis l'admin → URL `?cordespace_magic=TOKEN`
 *   2. Elle envoie le lien à la personne
 *   3. La personne clique → middleware valide le token → stocke un cookie
 *      `cordespace_magic_<event_id>=TOKEN` (24h par défaut)
 *   4. La personne ajoute l'event au panier → le scan cart voit le cookie
 *      et bypass le check gating pour CET event UNIQUEMENT
 *   5. La personne finalise sa commande → on incrémente used_count
 *   6. Si max_uses atteint → le lien est invalidé pour les prochaines fois
 *
 * Sécurité :
 *   - Token = 32 caractères hex random (crypto-grade via random_bytes)
 *   - Cookie HTTPONLY (pas accessible en JS)
 *   - SSL requis en prod (cookie Secure=true)
 *   - Le bypass ne marche QUE pour l'event_id du token (pas pour d'autres
 *     events semi-privés du même type)
 *
 * UI admin : onglet « Magic Links » au-dessus de la liste des types
 * d'événements à validation (via filter views_edit-cordespace_evtype).
 */

defined( 'ABSPATH' ) || exit;

// ============================================================================
// 1) Helpers de génération de token + validation
// ============================================================================

/**
 * Génère un token random de 32 caractères hex (= 128 bits d'entropie).
 */
function cordespace_magic_link_generate_token(): string {
	return bin2hex( random_bytes( 16 ) );
}

/**
 * Crée un nouveau magic link et retourne l'array représentant l'entrée DB.
 *
 * @param int      $event_id    Amelia event ID (table wp_amelia_events)
 * @param int      $max_uses    0 = illimité
 * @param ?string  $expires_at  Format MySQL datetime ou null pour pas d'expiration
 * @param string   $notes       Note admin libre
 * @param int      $by_user_id  ID admin qui crée
 *
 * @return array{token: string, id: int}|null Null si échec (event_id <= 0)
 */
function cordespace_magic_link_create( int $event_id, int $max_uses, ?string $expires_at, string $notes, int $by_user_id ): ?array {
	if ( $event_id <= 0 ) {
		return null;
	}
	global $wpdb;
	$table = cordespace_event_gating_magic_table_name();
	$token = cordespace_magic_link_generate_token();

	$result = $wpdb->insert(
		$table,
		[
			'token'       => $token,
			'event_id'    => $event_id,
			'max_uses'    => max( 0, $max_uses ),
			'used_count'  => 0,
			'expires_at'  => $expires_at,
			'notes'       => $notes,
			'created_by'  => $by_user_id ?: null,
		],
		[ '%s', '%d', '%d', '%d', '%s', '%s', '%d' ]
	);
	if ( ! $result ) {
		return null;
	}
	return [ 'token' => $token, 'id' => (int) $wpdb->insert_id ];
}

/**
 * Renvoie le magic link DB row si le token est valide pour cet event_id,
 * ou null sinon.
 *
 * Conditions de validité :
 *   - Token existe dans la table
 *   - Event_id matche (ou pas filtré si null)
 *   - revoked_at est null
 *   - expires_at est null OU dans le futur
 *   - used_count < max_uses OU max_uses = 0 (illimité)
 *
 * @param string $token
 * @param ?int   $event_id Si fourni, vérifie aussi le matching
 */
function cordespace_magic_link_get_if_valid( string $token, ?int $event_id = null ): ?array {
	if ( strlen( $token ) !== 32 ) {
		return null;
	}
	global $wpdb;
	$table = cordespace_event_gating_magic_table_name();
	$row   = $wpdb->get_row( $wpdb->prepare(
		"SELECT * FROM {$table} WHERE token = %s LIMIT 1",
		$token
	), ARRAY_A );
	if ( ! $row ) {
		return null;
	}
	if ( $row['revoked_at'] !== null ) {
		return null;
	}
	if ( $row['expires_at'] !== null && strtotime( $row['expires_at'] ) < time() ) {
		return null;
	}
	if ( (int) $row['max_uses'] > 0 && (int) $row['used_count'] >= (int) $row['max_uses'] ) {
		return null;
	}
	if ( $event_id !== null && (int) $row['event_id'] !== $event_id ) {
		return null;
	}
	return $row;
}

/**
 * Incrémente used_count du magic link. Appelée à la finalisation d'une commande
 * WC qui a utilisé le token.
 */
function cordespace_magic_link_consume( string $token ): bool {
	global $wpdb;
	$table = cordespace_event_gating_magic_table_name();
	$result = $wpdb->query( $wpdb->prepare(
		"UPDATE {$table} SET used_count = used_count + 1 WHERE token = %s",
		$token
	) );
	return $result !== false;
}

/**
 * Marque un magic link comme révoqué (manuel, depuis l'UI admin).
 */
function cordespace_magic_link_revoke( int $id ): bool {
	if ( $id <= 0 ) {
		return false;
	}
	global $wpdb;
	$table = cordespace_event_gating_magic_table_name();
	$result = $wpdb->update(
		$table,
		[ 'revoked_at' => current_time( 'mysql' ) ],
		[ 'id' => $id ],
		[ '%s' ],
		[ '%d' ]
	);
	return $result !== false;
}

/**
 * Liste tous les magic links (admin UI).
 *
 * @return array<int, array> Rows brutes de la table.
 */
function cordespace_magic_link_list_all(): array {
	global $wpdb;
	$table = cordespace_event_gating_magic_table_name();
	$rows  = $wpdb->get_results(
		"SELECT * FROM {$table} ORDER BY created_at DESC",
		ARRAY_A
	);
	return is_array( $rows ) ? $rows : [];
}

/**
 * Enregistre dans la table des usages : qui a réservé avec ce magic link.
 * UNIQUE (magic_link_id, amelia_booking_id) → idempotent si fire 2x.
 *
 * @param string $token       Token du magic link
 * @param int    $booking_id  ID du booking Amelia
 * @param array  $booking     Array du booking (a customerId, persons, etc.)
 */
function cordespace_magic_link_log_usage( string $token, int $booking_id, array $booking ): void {
	global $wpdb;
	$magic_table  = cordespace_event_gating_magic_table_name();
	$usages_table = cordespace_event_gating_magic_usages_table_name();

	$magic_id = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM {$magic_table} WHERE token = %s LIMIT 1",
		$token
	) );
	if ( $magic_id <= 0 ) {
		return;
	}

	// Récupère les infos client depuis Amelia (table amelia_users)
	$customer_id = (int) ( $booking['customerId'] ?? 0 );
	$customer    = $customer_id > 0
		? $wpdb->get_row( $wpdb->prepare(
			"SELECT email, firstName, lastName FROM {$wpdb->prefix}amelia_users WHERE id = %d LIMIT 1",
			$customer_id
		), ARRAY_A )
		: null;

	$wpdb->insert(
		$usages_table,
		[
			'magic_link_id'        => $magic_id,
			'amelia_booking_id'    => $booking_id,
			'amelia_customer_id'   => $customer_id ?: null,
			'customer_email'       => $customer ? (string) ( $customer['email'] ?? '' ) : '',
			'customer_first_name'  => $customer ? (string) ( $customer['firstName'] ?? '' ) : '',
			'customer_last_name'   => $customer ? (string) ( $customer['lastName'] ?? '' ) : '',
		],
		[ '%d', '%d', '%d', '%s', '%s', '%s' ]
	);
}

/**
 * Renvoie la liste des utilisations (qui a réservé) pour un magic link donné.
 *
 * @return array<int, array> Rows triées par consumed_at DESC
 */
function cordespace_magic_link_get_usages( int $magic_link_id ): array {
	if ( $magic_link_id <= 0 ) {
		return [];
	}
	global $wpdb;
	$table = cordespace_event_gating_magic_usages_table_name();
	$rows  = $wpdb->get_results( $wpdb->prepare(
		"SELECT * FROM {$table} WHERE magic_link_id = %d ORDER BY consumed_at DESC",
		$magic_link_id
	), ARRAY_A );
	return is_array( $rows ) ? $rows : [];
}

// ============================================================================
// 2) Middleware : intercepte ?cordespace_magic=TOKEN dans l'URL
// ============================================================================

/**
 * Si le param `cordespace_magic` est présent et le token valide, set un
 * cookie pour bypasser le gating sur cet event, puis redirige vers la même
 * URL sans le param (URL propre).
 *
 * Le cookie expire dans 24h (ou plus court si le magic link expire avant).
 */
function cordespace_magic_link_handle_url_activation(): void {
	if ( ! isset( $_GET['cordespace_magic'] ) ) {
		return;
	}
	$token = sanitize_text_field( wp_unslash( $_GET['cordespace_magic'] ) );
	if ( $token === '' ) {
		return;
	}
	$row = cordespace_magic_link_get_if_valid( $token, null );
	if ( ! $row ) {
		// Token invalide / expiré : on stocke une notice, mais on continue le
		// page render normal (pas de redirect, pas d'erreur).
		set_transient( 'cordespace_magic_notice_' . wp_get_session_token(), 'invalid', 300 );
		return;
	}

	// Set le cookie pour cet event_id
	$event_id    = (int) $row['event_id'];
	$cookie_name = 'cordespace_magic_event_' . $event_id;
	$expires     = time() + DAY_IN_SECONDS;
	if ( $row['expires_at'] !== null ) {
		$expires = min( $expires, (int) strtotime( $row['expires_at'] ) );
	}
	setcookie(
		$cookie_name,
		$token,
		[
			'expires'  => $expires,
			'path'     => '/',
			'secure'   => is_ssl(),
			'httponly' => true,
			'samesite' => 'Lax',
		]
	);
	$_COOKIE[ $cookie_name ] = $token; // dispo dans la même requête

	// Aussi en WC session pour robustesse à travers le checkout WC Blocks
	// (les cookies peuvent etre absents ou differents au moment du
	// processing de l'order via Store API).
	if ( function_exists( 'WC' ) && WC() && WC()->session ) {
		$tokens                = (array) WC()->session->get( 'cordespace_magic_tokens', [] );
		$tokens[ $event_id ]   = $token;
		WC()->session->set( 'cordespace_magic_tokens', $tokens );
	}

	// Notice de succès pour affichage côté front
	set_transient( 'cordespace_magic_notice_' . wp_get_session_token(), 'activated_' . $event_id, 300 );

	// Redirect vers l'URL sans le param (cleaner URL après activation)
	$clean_url = remove_query_arg( 'cordespace_magic' );
	wp_safe_redirect( $clean_url );
	exit;
}
add_action( 'template_redirect', 'cordespace_magic_link_handle_url_activation', 1 );

/**
 * Affiche une notice en haut des pages cart/checkout si l'utilisateur·trice
 * vient d'activer un magic link valide (ou si invalide).
 */
function cordespace_magic_link_render_notice( $content ) {
	if ( ! function_exists( 'is_cart' ) || ( ! is_cart() && ! is_checkout() ) ) {
		return $content;
	}
	$notice = get_transient( 'cordespace_magic_notice_' . wp_get_session_token() );
	if ( ! $notice ) {
		return $content;
	}
	delete_transient( 'cordespace_magic_notice_' . wp_get_session_token() );

	if ( $notice === 'invalid' ) {
		$html = '<div role="alert" style="margin:0 0 1.5rem; padding:1rem 1.2rem; background:#fdecea; border:2px solid #d63638; border-left:6px solid #d63638; border-radius:6px; color:#3c1c1c;">'
			. '<strong>⚠️ ' . esc_html__( 'Ce lien magique n\'est plus valide.', 'cordespace-snippets' ) . '</strong> '
			. esc_html__( 'Il a peut-être expiré ou été déjà utilisé. Contacte-nous si tu penses qu\'il y a une erreur.', 'cordespace-snippets' )
			. '</div>';
		return $html . $content;
	}

	if ( strpos( $notice, 'activated_' ) === 0 ) {
		$event_id = (int) substr( $notice, strlen( 'activated_' ) );
		global $wpdb;
		$event_name = (string) $wpdb->get_var( $wpdb->prepare(
			"SELECT name FROM {$wpdb->prefix}amelia_events WHERE id = %d LIMIT 1",
			$event_id
		) );
		$html = '<div role="status" style="margin:0 0 1.5rem; padding:1rem 1.2rem; background:#e8f5e9; border:2px solid #2e7d32; border-left:6px solid #2e7d32; border-radius:6px; color:#1b3a1d;">'
			. '<strong>✨ ' . esc_html__( 'Lien magique activé !', 'cordespace-snippets' ) . '</strong> '
			. ( $event_name !== ''
				? sprintf(
					/* translators: %s = event name */
					esc_html__( 'Tu peux maintenant réserver « %s » sans validation préalable.', 'cordespace-snippets' ),
					esc_html( $event_name )
				)
				: esc_html__( 'Tu peux maintenant réserver cet événement sans validation préalable.', 'cordespace-snippets' )
			)
			. '</div>';
		return $html . $content;
	}

	return $content;
}
add_filter( 'the_content', 'cordespace_magic_link_render_notice', 4 );

// ============================================================================
// 3) Bypass logic : utilisé par le checkout-blocker
// ============================================================================

/**
 * Renvoie le token magic actif pour cet event, en cherchant dans 2 sources :
 *   1. Cookie HTTPONLY (set au moment du clic sur le lien)
 *   2. WC session (= survit mieux au flow Store API que les cookies)
 *
 * @return string|null Le token (32 chars) ou null si aucun trouvé.
 */
function cordespace_magic_link_get_token_for_event( int $event_id ): ?string {
	if ( $event_id <= 0 ) {
		return null;
	}
	// Source 1 : cookie
	$cookie_name = 'cordespace_magic_event_' . $event_id;
	if ( ! empty( $_COOKIE[ $cookie_name ] ) ) {
		$token = (string) $_COOKIE[ $cookie_name ];
		if ( cordespace_magic_link_get_if_valid( $token, $event_id ) !== null ) {
			return $token;
		}
	}
	// Source 2 : WC session (Store API safe)
	if ( function_exists( 'WC' ) && WC() && WC()->session ) {
		$tokens = (array) WC()->session->get( 'cordespace_magic_tokens', [] );
		if ( ! empty( $tokens[ $event_id ] ) ) {
			$token = (string) $tokens[ $event_id ];
			if ( cordespace_magic_link_get_if_valid( $token, $event_id ) !== null ) {
				return $token;
			}
		}
	}
	return null;
}

/**
 * Vérifie si l'utilisateur·trice a un magic link valide pour l'event donné.
 * Appelé par le scan cart pour bypasser le gating.
 */
function cordespace_magic_link_user_has_valid_pass_for_event( int $event_id ): bool {
	return cordespace_magic_link_get_token_for_event( $event_id ) !== null;
}

// ============================================================================
// 4) Consume on order completion : incrémente used_count
// ============================================================================

/**
 * Hook PRINCIPAL : amelia_after_event_booking_saved.
 *
 * Ce hook fire QUEL QUE SOIT le canal de booking :
 *   - WooCommerce checkout classique
 *   - WooCommerce Blocks checkout (Store API)
 *   - Paiement sur place / cash on delivery
 *   - Amelia direct (sans WC, ex: events gratuits)
 *
 * → C'est LE bon point d'ancrage pour consume le magic link.
 *
 * @param array $booking Array du booking Amelia (id, customerId, persons, etc.)
 * @param array $event   Array de l'event Amelia (id, name, etc.)
 */
function cordespace_magic_link_consume_on_amelia_booking( $booking, $event ): void {
	if ( ! is_array( $booking ) || ! is_array( $event ) ) {
		return;
	}
	$booking_id = (int) ( $booking['id'] ?? 0 );
	$event_id   = (int) ( $event['id'] ?? 0 );
	if ( $booking_id <= 0 || $event_id <= 0 ) {
		return;
	}

	// Idempotence : si on a déjà consume pour ce booking_id précis, on skip
	// (utile si le hook fire plusieurs fois pour le même booking, ex : update).
	$idempotence_key = 'cordespace_magic_consumed_booking_' . $booking_id;
	if ( get_transient( $idempotence_key ) ) {
		return;
	}

	$token = cordespace_magic_link_get_token_for_event( $event_id );
	if ( $token === null ) {
		return;
	}

	cordespace_magic_link_consume( $token );
	set_transient( $idempotence_key, $token, 7 * DAY_IN_SECONDS );

	// Log de l'utilisation : qui a réservé avec ce lien (audit + UI admin)
	cordespace_magic_link_log_usage( $token, $booking_id, $booking );

	// Retire ce token de la WC session si présent (= ne peut plus etre
	// reutilise pour un autre booking dans la meme session). Sauf si le
	// magic link a max_uses > 1 et used_count < max_uses : dans ce cas
	// on garde le token actif pour que la personne puisse refaire un
	// achat dans la meme session.
	$row = cordespace_magic_link_get_if_valid( $token, $event_id );
	if ( $row === null && function_exists( 'WC' ) && WC() && WC()->session ) {
		// Le link est maintenant épuisé/expiré : on nettoie la session
		$tokens = (array) WC()->session->get( 'cordespace_magic_tokens', [] );
		unset( $tokens[ $event_id ] );
		WC()->session->set( 'cordespace_magic_tokens', $tokens );
	}
}
add_action( 'amelia_after_event_booking_saved', 'cordespace_magic_link_consume_on_amelia_booking', 10, 2 );

// ============================================================================
// 5) UI admin : onglet "Magic Links" sous "Événements à validation"
// ============================================================================

/**
 * Slug de la sous-page admin "Magic Links".
 */
const CORDESPACE_MAGIC_LINKS_PAGE_SLUG = 'cordespace-magic-links';

/**
 * Enregistre la sous-page admin (hidden dans le menu — accessible via
 * l'onglet en haut de la liste des types).
 */
function cordespace_magic_link_register_admin_page(): void {
	add_submenu_page(
		null, // hidden (pas affiché dans le menu)
		__( 'Magic Links', 'cordespace-snippets' ),
		__( 'Magic Links', 'cordespace-snippets' ),
		'edit_posts',
		CORDESPACE_MAGIC_LINKS_PAGE_SLUG,
		'cordespace_magic_link_render_admin_page'
	);
}
add_action( 'admin_menu', 'cordespace_magic_link_register_admin_page', 50 );

/**
 * Helper partagé : rend la barre d'onglets nav-tab native WP (comme Rapports)
 * permettant de switcher entre la liste des bassins et la page liens de validation.
 *
 * @param string $current 'types' (= bassins) ou 'magic' (= liens individuels)
 */
function cordespace_event_gating_render_main_nav_tabs( string $current ): void {
	$types_url = admin_url( 'edit.php?post_type=cordespace_evtype' );
	$magic_url = admin_url( 'admin.php?page=' . CORDESPACE_MAGIC_LINKS_PAGE_SLUG );
	?>
	<nav class="nav-tab-wrapper" style="margin-top:1rem;">
		<a href="<?php echo esc_url( $types_url ); ?>"
		   class="nav-tab <?php echo $current === 'types' ? 'nav-tab-active' : ''; ?>">
			🏷️ <?php esc_html_e( "Par tag", 'cordespace-snippets' ); ?>
		</a>
		<a href="<?php echo esc_url( $magic_url ); ?>"
		   class="nav-tab <?php echo $current === 'magic' ? 'nav-tab-active' : ''; ?>">
			🔗 <?php esc_html_e( 'Lien de validation', 'cordespace-snippets' ); ?>
		</a>
	</nav>

	<p style="margin:1rem 0 0.6rem; padding:0.8rem 1rem; background:#f0f6fc; border-left:3px solid #2c70b8; font-size:0.95em; color:#1d4d7e;">
		<strong>📋 <?php esc_html_e( "Deux façons de valider l'accès :", 'cordespace-snippets' ); ?></strong>
		<br>
		🏷️ <strong><?php esc_html_e( 'Par tag', 'cordespace-snippets' ); ?></strong> — <?php esc_html_e( "définis des bassins de membres regroupés par étiquette Amelia (ex : toutes les personnes validées « Semi-privé »).", 'cordespace-snippets' ); ?>
		<br>
		🔗 <strong><?php esc_html_e( 'Lien de validation', 'cordespace-snippets' ); ?></strong> — <?php esc_html_e( "génère un lien unique à envoyer à quelqu'un pour bypasser le gating sur 1 event Amelia spécifique (utile pour invité·es ponctuel·les).", 'cordespace-snippets' ); ?>
	</p>

	<p style="margin:0 0 1.5rem; padding:0.8rem 1rem; background:#fef5f3; border-left:3px solid #d63638; font-size:0.95em; color:#5c1c1c; line-height:1.5;">
		ℹ️ <strong><?php esc_html_e( 'À savoir', 'cordespace-snippets' ); ?> :</strong>
		<?php
		echo wp_kses(
			__( "ce système de validation s'applique uniquement aux événements <strong>payants</strong> (qui passent par le panier WooCommerce). Les événements <strong>gratuits</strong> sont réservés directement via Amelia, sans validation possible. Pour un event gratuit semi-privé, partage le lien de la page directement aux personnes concernées, sans le mettre dans les menus publics.", 'cordespace-snippets' ),
			[ 'strong' => [] ]
		);
		?>
	</p>
	<?php
}

/**
 * Affiche les onglets en haut de la liste des types CPT (post_type =
 * cordespace_evtype). Hook all_admin_notices (et non admin_notices) :
 * fire APRÈS toutes les notices admin natives (warnings WP, thème,
 * autres plugins).
 *
 * Réordonnancement DOM (le JS qui suit) :
 *   Sur les pages CPT list, WP rend dans cet ordre :
 *     1. <h1>Title</h1> + <hr class="wp-header-end">
 *     2. do_action('admin_notices')      ← warnings WP (thème, MAJ…)
 *     3. do_action('all_admin_notices')  ← notre helper d'onglets
 *     4. List table
 *   Du coup, on obtient visuellement : H1 → warning → onglets → liste,
 *   ce qui place le warning ENTRE le H1 et nos onglets — incohérent.
 *
 *   Tess a proposé la fix : déplacer le H1 AU-DESSUS des onglets via JS.
 *   Résultat final : warning → H1 → onglets + bandeaux → liste, où le
 *   warning reste tout en haut (comme sur la page Rapports) et notre
 *   pile reste un bloc cohérent juste avant la liste.
 *
 *   Le JS s'exécute au DOMContentLoaded pour s'assurer que le H1 (rendu
 *   plus tard dans le markup) est déjà dans le DOM au moment du move.
 */
function cordespace_event_gating_render_nav_on_cpt_list(): void {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || $screen->id !== 'edit-cordespace_evtype' ) {
		return;
	}
	cordespace_event_gating_render_main_nav_tabs( 'types' );
	?>
	<script>
	document.addEventListener('DOMContentLoaded', function () {
		var wrap = document.querySelector('.wrap');
		if (!wrap) return;
		var nav = wrap.querySelector(':scope > .nav-tab-wrapper');
		var h1  = wrap.querySelector(':scope > h1.wp-heading-inline');
		var hr  = wrap.querySelector(':scope > hr.wp-header-end');
		if (nav && h1) {
			wrap.insertBefore(h1, nav);
			if (hr) wrap.insertBefore(hr, nav);
		}
	});
	</script>
	<?php
}
add_action( 'all_admin_notices', 'cordespace_event_gating_render_nav_on_cpt_list' );

/**
 * Render de la page admin Magic Links.
 */
function cordespace_magic_link_render_admin_page(): void {
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_die( esc_html__( 'Permission insuffisante.', 'cordespace-snippets' ) );
	}

	// Traitement des actions POST (create / revoke)
	$message = '';
	if ( ! empty( $_POST['cordespace_magic_action'] ) && check_admin_referer( 'cordespace_magic_admin' ) ) {
		$action = sanitize_key( $_POST['cordespace_magic_action'] );
		if ( $action === 'create' ) {
			$event_id    = (int) ( $_POST['event_id'] ?? 0 );
			$max_uses    = (int) ( $_POST['max_uses'] ?? 0 );
			$expires_in  = sanitize_text_field( $_POST['expires_in'] ?? '' );
			$notes       = sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) );
			$expires_at  = null;
			if ( $expires_in === '24h' ) {
				$expires_at = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
			} elseif ( $expires_in === '7d' ) {
				$expires_at = gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS );
			} elseif ( $expires_in === '30d' ) {
				$expires_at = gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS );
			} elseif ( $expires_in === 'event_start' ) {
				// Cherche la date de début de l'event Amelia : la 1ère periodStart
				global $wpdb;
				$start = $wpdb->get_var( $wpdb->prepare(
					"SELECT MIN(periodStart) FROM {$wpdb->prefix}amelia_events_periods WHERE eventId = %d",
					$event_id
				) );
				if ( $start ) {
					$expires_at = (string) $start;
				}
			}
			$result = cordespace_magic_link_create( $event_id, $max_uses, $expires_at, $notes, get_current_user_id() );
			if ( $result ) {
				$message = '<div class="notice notice-success"><p>✓ Magic link créé. Token : <code>' . esc_html( $result['token'] ) . '</code></p></div>';
			} else {
				$message = '<div class="notice notice-error"><p>✗ Erreur lors de la création. Vérifie que l\'event_id est valide.</p></div>';
			}
		} elseif ( $action === 'revoke' ) {
			$id = (int) ( $_POST['id'] ?? 0 );
			if ( cordespace_magic_link_revoke( $id ) ) {
				$message = '<div class="notice notice-success"><p>✓ Lien révoqué.</p></div>';
			} else {
				$message = '<div class="notice notice-error"><p>✗ Erreur lors de la révocation.</p></div>';
			}
		}
	}

	$links = cordespace_magic_link_list_all();

	// Liste des events Amelia avec leurs tags (pour le sélecteur + filtre)
	global $wpdb;
	$events = $wpdb->get_results(
		"SELECT e.id, e.name,
		        MIN(ep.periodStart) AS first_date,
		        (SELECT GROUP_CONCAT(et.name SEPARATOR '|')
		         FROM {$wpdb->prefix}amelia_events_tags et
		         WHERE et.eventId = e.id) AS tags
		   FROM {$wpdb->prefix}amelia_events e
		   LEFT JOIN {$wpdb->prefix}amelia_events_periods ep ON ep.eventId = e.id
		  WHERE e.status = 'approved'
		  GROUP BY e.id
		  ORDER BY first_date ASC, e.name ASC
		  LIMIT 500",
		ARRAY_A
	);
	$all_tags_for_filter = (array) $wpdb->get_col(
		"SELECT DISTINCT name FROM {$wpdb->prefix}amelia_events_tags ORDER BY name ASC"
	);

	// Enqueue selectWoo (bundlé par WC) pour la recherche autocomplete
	if ( wp_script_is( 'selectWoo', 'registered' ) ) {
		wp_enqueue_script( 'selectWoo' );
		wp_enqueue_style( 'select2' );
	} elseif ( wp_script_is( 'select2', 'registered' ) ) {
		wp_enqueue_script( 'select2' );
		wp_enqueue_style( 'select2' );
	}

	?>
	<div class="wrap">
		<h1>🔗 <?php esc_html_e( 'Liens de validation', 'cordespace-snippets' ); ?></h1>
		<?php cordespace_event_gating_render_main_nav_tabs( 'magic' ); ?>
		<?php echo $message; // déjà sanitizé ?>

		<p style="background:#fff8e1; border-left:3px solid #fbc02d; padding:0.6rem 0.9rem; font-size:0.95em;">
			ℹ️ <?php esc_html_e( "Génère un lien magique pour permettre à quelqu'un de réserver un event Amelia spécifique sans passer par le gating (utile pour invité·es ponctuel·les). Le lien fonctionne UNIQUEMENT pour cet event précis.", 'cordespace-snippets' ); ?>
		</p>

		<h2><?php esc_html_e( 'Créer un nouveau lien', 'cordespace-snippets' ); ?></h2>
		<form method="post" style="background:#f7f7f9; padding:1rem 1.2rem; border-radius:6px; margin-bottom:2rem;">
			<?php wp_nonce_field( 'cordespace_magic_admin' ); ?>
			<input type="hidden" name="cordespace_magic_action" value="create">

			<table class="form-table">
				<tr>
					<th><label><?php esc_html_e( 'Filtres', 'cordespace-snippets' ); ?></label></th>
					<td>
						<div style="display:flex; flex-wrap:wrap; gap:0.6rem 1rem; align-items:center;">
							<select id="cordespace-magic-tag-filter" style="min-width:220px;">
								<option value=""><?php esc_html_e( '🏷️ Tous les tags', 'cordespace-snippets' ); ?></option>
								<?php foreach ( $all_tags_for_filter as $tag ) : ?>
									<option value="<?php echo esc_attr( $tag ); ?>"><?php echo esc_html( $tag ); ?></option>
								<?php endforeach; ?>
							</select>
							<select id="cordespace-magic-date-filter" style="min-width:220px;">
								<option value="future"><?php esc_html_e( "📅 À venir uniquement", 'cordespace-snippets' ); ?></option>
								<option value="month"><?php esc_html_e( '📅 30 prochains jours', 'cordespace-snippets' ); ?></option>
								<option value="week"><?php esc_html_e( '📅 7 prochains jours', 'cordespace-snippets' ); ?></option>
								<option value="all"><?php esc_html_e( '📅 Tous (incluant passés)', 'cordespace-snippets' ); ?></option>
							</select>
							<span class="description" style="font-size:0.85em; color:#666;">
								<span id="cordespace-magic-count"><?php echo (int) count( $events ); ?></span> <?php esc_html_e( 'events affichés', 'cordespace-snippets' ); ?>
							</span>
						</div>
					</td>
				</tr>
				<tr>
					<th><label for="event_id"><?php esc_html_e( 'Event Amelia', 'cordespace-snippets' ); ?></label></th>
					<td>
						<select name="event_id" id="event_id" required style="min-width:400px;">
							<option value=""><?php esc_html_e( '— Tape pour chercher ou parcours… —', 'cordespace-snippets' ); ?></option>
							<?php
							$now_ts = time();
							foreach ( $events as $e ) :
								$date_str  = $e['first_date'] ? ' (' . mysql2date( 'd M Y', $e['first_date'] ) . ')' : '';
								$date_iso  = (string) ( $e['first_date'] ?? '' );
								$is_future = $date_iso === '' || strtotime( $date_iso ) >= $now_ts;
								$tags_str  = (string) ( $e['tags'] ?? '' );
								?>
								<option value="<?php echo (int) $e['id']; ?>"
								        data-tags="<?php echo esc_attr( $tags_str ); ?>"
								        data-date="<?php echo esc_attr( $date_iso ); ?>"
								        data-future="<?php echo $is_future ? '1' : '0'; ?>">
									<?php echo esc_html( $e['name'] . $date_str ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="max_uses"><?php esc_html_e( "Nombre d'utilisations", 'cordespace-snippets' ); ?></label></th>
					<td>
						<input type="number" name="max_uses" id="max_uses" value="1" min="0" style="width:80px;">
						<span class="description"><?php esc_html_e( '(0 = illimité)', 'cordespace-snippets' ); ?></span>
					</td>
				</tr>
				<tr>
					<th><label for="expires_in"><?php esc_html_e( 'Expiration', 'cordespace-snippets' ); ?></label></th>
					<td>
						<select name="expires_in" id="expires_in" style="min-width:300px;">
							<option value="event_start" selected>📅 <?php esc_html_e( "Au début de l'événement (recommandé)", 'cordespace-snippets' ); ?></option>
							<option value="24h">⏱️ <?php esc_html_e( 'Dans 24 heures', 'cordespace-snippets' ); ?></option>
							<option value="7d">⏱️ <?php esc_html_e( 'Dans 7 jours', 'cordespace-snippets' ); ?></option>
							<option value="30d">⏱️ <?php esc_html_e( 'Dans 30 jours', 'cordespace-snippets' ); ?></option>
							<option value="never">♾️ <?php esc_html_e( 'Jamais', 'cordespace-snippets' ); ?></option>
						</select>
						<p class="description" id="cordespace-magic-expires-preview" style="margin-top:0.4rem; color:#555;">
							<?php esc_html_e( "Sélectionne d'abord un event pour voir la date.", 'cordespace-snippets' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th><label for="notes"><?php esc_html_e( 'Note', 'cordespace-snippets' ); ?></label></th>
					<td>
						<textarea name="notes" id="notes" rows="2" style="width:400px;" placeholder="<?php esc_attr_e( 'Ex : pour Marie Tremblay, invitée Insta', 'cordespace-snippets' ); ?>"></textarea>
					</td>
				</tr>
			</table>

			<p>
				<button type="submit" class="button button-primary">
					<?php esc_html_e( '+ Créer le lien', 'cordespace-snippets' ); ?>
				</button>
			</p>
		</form>

		<h2><?php esc_html_e( 'Liens existants', 'cordespace-snippets' ); ?></h2>
		<?php if ( empty( $links ) ) : ?>
			<p style="color:#666; font-style:italic;"><?php esc_html_e( 'Aucun magic link créé pour l\'instant.', 'cordespace-snippets' ); ?></p>
		<?php else : ?>
			<table class="widefat fixed">
				<thead>
					<tr>
						<th style="width:25%;"><?php esc_html_e( 'Event', 'cordespace-snippets' ); ?></th>
						<th><?php esc_html_e( 'Lien', 'cordespace-snippets' ); ?></th>
						<th><?php esc_html_e( 'Utilisations', 'cordespace-snippets' ); ?></th>
						<th><?php esc_html_e( 'Expiration', 'cordespace-snippets' ); ?></th>
						<th><?php esc_html_e( 'État', 'cordespace-snippets' ); ?></th>
						<th><?php esc_html_e( 'Note', 'cordespace-snippets' ); ?></th>
						<th><?php esc_html_e( 'Action', 'cordespace-snippets' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $links as $link ) :
						$event_name = (string) $wpdb->get_var( $wpdb->prepare(
							"SELECT name FROM {$wpdb->prefix}amelia_events WHERE id = %d LIMIT 1",
							(int) $link['event_id']
						) );
						$magic_url   = add_query_arg( 'cordespace_magic', $link['token'], home_url( '/' ) );
						$is_revoked  = $link['revoked_at'] !== null;
						$is_expired  = $link['expires_at'] !== null && strtotime( $link['expires_at'] ) < time();
						$is_used_up  = (int) $link['max_uses'] > 0 && (int) $link['used_count'] >= (int) $link['max_uses'];
						$state       = $is_revoked ? '🚫 Révoqué' : ( $is_expired ? '⏱️ Expiré' : ( $is_used_up ? '✅ Utilisé' : '🟢 Actif' ) );
						$state_color = ( $is_revoked || $is_expired || $is_used_up ) ? '#999' : '#1a5c1a';
						$usages      = cordespace_magic_link_get_usages( (int) $link['id'] );
						?>
						<tr>
							<td><?php echo esc_html( $event_name ?: 'Event #' . $link['event_id'] ); ?></td>
							<td>
								<code class="cordespace-magic-url" style="display:block; word-break:break-all; font-size:0.85em; background:#f4f4f4; padding:0.3rem; border-radius:3px;"><?php echo esc_html( $magic_url ); ?></code>
								<button type="button" class="button button-small cordespace-magic-copy-btn" data-url="<?php echo esc_attr( $magic_url ); ?>" style="margin-top:0.3rem;">📋 <?php esc_html_e( 'Copier', 'cordespace-snippets' ); ?></button>
							</td>
							<td>
								<?php echo (int) $link['used_count']; ?> / <?php echo (int) $link['max_uses'] > 0 ? (int) $link['max_uses'] : '∞'; ?>
								<?php if ( ! empty( $usages ) ) : ?>
									<details style="margin-top:0.4rem; font-size:0.85em;">
										<summary style="cursor:pointer; color:#2c70b8;">
											👤 <?php printf( esc_html__( '%d personne(s)', 'cordespace-snippets' ), count( $usages ) ); ?>
										</summary>
										<ul style="margin:0.4rem 0 0 0.5rem; padding-left:0.8rem; color:#555;">
											<?php foreach ( $usages as $u ) :
												$name  = trim( (string) ( $u['customer_first_name'] ?? '' ) . ' ' . (string) ( $u['customer_last_name'] ?? '' ) );
												$email = (string) ( $u['customer_email'] ?? '' );
												?>
												<li style="margin-bottom:0.2rem;">
													<?php if ( $name !== '' ) : ?>
														<strong><?php echo esc_html( $name ); ?></strong><br>
													<?php endif; ?>
													<?php if ( $email !== '' ) : ?>
														<a href="mailto:<?php echo esc_attr( $email ); ?>" style="color:#666;"><?php echo esc_html( $email ); ?></a><br>
													<?php endif; ?>
													<small style="color:#999;">
														<?php echo esc_html( mysql2date( 'd M Y H:i', (string) $u['consumed_at'] ) ); ?>
													</small>
												</li>
											<?php endforeach; ?>
										</ul>
									</details>
								<?php endif; ?>
							</td>
							<td><?php echo $link['expires_at'] ? esc_html( mysql2date( 'd M Y H:i', $link['expires_at'] ) ) : '—'; ?></td>
							<td style="color:<?php echo esc_attr( $state_color ); ?>;"><?php echo esc_html( $state ); ?></td>
							<td style="font-size:0.85em; color:#555;"><?php echo esc_html( (string) ( $link['notes'] ?? '' ) ); ?></td>
							<td>
								<?php if ( ! $is_revoked ) : ?>
									<form method="post" style="display:inline;" onsubmit="return confirm('Révoquer ce lien ?');">
										<?php wp_nonce_field( 'cordespace_magic_admin' ); ?>
										<input type="hidden" name="cordespace_magic_action" value="revoke">
										<input type="hidden" name="id" value="<?php echo (int) $link['id']; ?>">
										<button type="submit" class="button button-small">🚫 <?php esc_html_e( 'Révoquer', 'cordespace-snippets' ); ?></button>
									</form>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>

	<script>
	(function ($) {
		'use strict';
		var $sel       = $('#event_id');
		var $tagFilter = $('#cordespace-magic-tag-filter');
		var $dateFilter = $('#cordespace-magic-date-filter');
		var $count     = $('#cordespace-magic-count');
		if (!$sel.length) return;

		// Snapshot des options originales (avant filtrage)
		var allOptions = $sel.find('option').map(function () {
			var $o = $(this);
			var dateIso = $o.attr('data-date') || '';
			var dateTs  = dateIso ? Date.parse(dateIso) / 1000 : 0;
			return {
				value:   $o.val(),
				text:    $o.text(),
				tags:    ($o.attr('data-tags') || '').split('|').filter(Boolean),
				future:  $o.attr('data-future') === '1',
				dateTs:  dateTs,
			};
		}).get();

		function rebuild() {
			var tag        = $tagFilter.val() || '';
			var dateMode   = $dateFilter.val() || 'future';
			var nowTs      = Date.now() / 1000;
			var horizonTs  = nowTs;
			if (dateMode === 'week')  horizonTs = nowTs + 7 * 86400;
			if (dateMode === 'month') horizonTs = nowTs + 30 * 86400;

			$sel.empty();
			$sel.append('<option value=""><?php echo esc_js( __( '— Tape pour chercher ou parcours… —', 'cordespace-snippets' ) ); ?></option>');

			var shown = 0;
			allOptions.forEach(function (opt) {
				if (!opt.value) return;
				var matchTag = !tag || opt.tags.indexOf(tag) >= 0;
				var matchDate = true;
				if (dateMode === 'future') {
					matchDate = opt.future;
				} else if (dateMode === 'week' || dateMode === 'month') {
					matchDate = opt.dateTs >= nowTs && opt.dateTs <= horizonTs;
				} // 'all' = pas de filtre date
				if (matchTag && matchDate) {
					var $o = $('<option>').val(opt.value).text(opt.text);
					$sel.append($o);
					shown++;
				}
			});
			$count.text(shown);

			// Re-init selectWoo si dispo
			if ($.fn.selectWoo) {
				try { $sel.selectWoo('destroy'); } catch (e) {}
				$sel.selectWoo({
					placeholder: '<?php echo esc_js( __( '— Tape pour chercher ou parcours… —', 'cordespace-snippets' ) ); ?>',
					allowClear: true,
					width: '400px',
				});
			} else if ($.fn.select2) {
				try { $sel.select2('destroy'); } catch (e) {}
				$sel.select2({ placeholder: '— Choisir un event —', allowClear: true, width: '400px' });
			}
		}

		$tagFilter.add($dateFilter).on('change', rebuild);

		// === Aperçu de la date d'expiration ===
		// Quand le user choisit un event + un mode d'expiration, on affiche
		// la date résultante dans cordespace-magic-expires-preview.
		function formatLocalDate(iso) {
			if (!iso) return '';
			var d = new Date(iso.replace(' ', 'T') + 'Z');
			if (isNaN(d.getTime())) return iso;
			var months = ['janvier','février','mars','avril','mai','juin','juillet','août','septembre','octobre','novembre','décembre'];
			var day = d.getDate(), month = months[d.getMonth()], year = d.getFullYear();
			var h = String(d.getHours()).padStart(2, '0'), m = String(d.getMinutes()).padStart(2, '0');
			return day + ' ' + month + ' ' + year + ' à ' + h + 'h' + m;
		}
		function updateExpiresPreview() {
			var $preview = $('#cordespace-magic-expires-preview');
			var mode = $('#expires_in').val();
			var eventId = $sel.val();
			if (!eventId) {
				$preview.text("Sélectionne d'abord un event pour voir la date.");
				return;
			}
			var opt = allOptions.find(function (o) { return String(o.value) === String(eventId); });
			if (!opt) { $preview.text(''); return; }

			var now = new Date();
			var dateStr = '';
			if (mode === 'never') {
				dateStr = "Le lien n'expirera jamais.";
			} else if (mode === 'event_start') {
				if (opt.dateTs > 0) {
					var iso = new Date(opt.dateTs * 1000).toISOString().slice(0, 19).replace('T', ' ');
					dateStr = "Le lien expirera au début de l'event : " + formatLocalDate(iso);
				} else {
					dateStr = "⚠️ Cet event n'a pas de date — l'expiration sera désactivée (= jamais).";
				}
			} else {
				var hours = mode === '24h' ? 24 : (mode === '7d' ? 24*7 : 24*30);
				var futureDate = new Date(now.getTime() + hours * 3600 * 1000);
				dateStr = "Le lien expirera le " + formatLocalDate(futureDate.toISOString().slice(0, 19).replace('T', ' '));
			}
			$preview.text(dateStr);
		}
		$('#expires_in, #event_id').on('change', updateExpiresPreview);
		setTimeout(updateExpiresPreview, 100); // après rebuild initial

		// === Bouton "Copier" sur les magic links existants ===
		// Fallback robuste : navigator.clipboard ne marche QU'EN HTTPS ou
		// localhost. En HTTP (genre prod-fresh.local), on tombe sur l'API
		// legacy execCommand('copy') via un textarea temporaire.
		function copyToClipboard(text) {
			// Tentative moderne (HTTPS / localhost)
			if (navigator.clipboard && window.isSecureContext) {
				return navigator.clipboard.writeText(text);
			}
			// Fallback legacy
			return new Promise(function (resolve, reject) {
				var ta = document.createElement('textarea');
				ta.value = text;
				ta.style.position = 'fixed';
				ta.style.opacity = '0';
				document.body.appendChild(ta);
				ta.focus();
				ta.select();
				try {
					if (document.execCommand('copy')) {
						resolve();
					} else {
						reject(new Error('execCommand returned false'));
					}
				} catch (e) {
					reject(e);
				} finally {
					document.body.removeChild(ta);
				}
			});
		}

		$(document).on('click', '.cordespace-magic-copy-btn', function () {
			var $btn = $(this);
			var url  = $btn.data('url') || '';
			if (!url) return;
			var originalLabel = $btn.html();
			copyToClipboard(url).then(function () {
				$btn.html('✅ Copié').css('background', '#e8f5e9');
				setTimeout(function () {
					$btn.html(originalLabel).css('background', '');
				}, 1500);
			}).catch(function () {
				// Echec total des 2 méthodes : on prompt pour permettre une
				// copie manuelle (au moins, le texte est sélectionné).
				window.prompt('Copie manuelle (Ctrl+C / Cmd+C) :', url);
			});
		});

		// selectWoo aussi sur le filtre tag (plus joli)
		if ($.fn.selectWoo) {
			$tagFilter.selectWoo({ width: '220px' });
			$dateFilter.selectWoo({ minimumResultsForSearch: Infinity, width: '220px' });
		}

		// Init au load
		rebuild();
	})(jQuery);
	</script>
	<?php
}
