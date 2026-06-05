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
 * Vérifie si l'utilisateur·trice a un cookie magic link valide pour
 * l'event_id donné. Appelé par le scan cart pour bypasser le gating.
 *
 * @return bool true si bypass autorisé, false sinon
 */
function cordespace_magic_link_user_has_valid_pass_for_event( int $event_id ): bool {
	if ( $event_id <= 0 ) {
		return false;
	}
	$cookie_name = 'cordespace_magic_event_' . $event_id;
	if ( empty( $_COOKIE[ $cookie_name ] ) ) {
		return false;
	}
	$token = (string) $_COOKIE[ $cookie_name ];
	$row   = cordespace_magic_link_get_if_valid( $token, $event_id );
	return $row !== null;
}

/**
 * Renvoie le token utilisé pour bypasser l'event (pour pouvoir l'enregistrer
 * dans l'order meta). null si pas de pass valide.
 */
function cordespace_magic_link_get_token_for_event( int $event_id ): ?string {
	if ( ! cordespace_magic_link_user_has_valid_pass_for_event( $event_id ) ) {
		return null;
	}
	return (string) ( $_COOKIE[ 'cordespace_magic_event_' . $event_id ] ?? '' );
}

// ============================================================================
// 4) Consume on order completion : incrémente used_count
// ============================================================================

/**
 * À la finalisation d'une commande WC, on regarde les cart items qui avaient
 * un magic pass actif et on incrémente used_count.
 *
 * Hook : woocommerce_checkout_order_processed (juste après la création de
 * l'order, avant le payment processing).
 */
function cordespace_magic_link_consume_on_order_processed( int $order_id, array $posted_data, $order ): void {
	if ( ! $order ) {
		return;
	}
	$consumed_tokens = [];
	foreach ( $order->get_items() as $item ) {
		$amelia = $item->get_meta( 'ameliabooking', true );
		if ( ! is_array( $amelia ) ) {
			continue;
		}
		if ( ( $amelia['type'] ?? '' ) !== 'event' ) {
			continue;
		}
		$event_id = (int) ( $amelia['eventId'] ?? 0 );
		if ( $event_id <= 0 ) {
			continue;
		}
		$token = cordespace_magic_link_get_token_for_event( $event_id );
		if ( $token === null || in_array( $token, $consumed_tokens, true ) ) {
			continue;
		}
		cordespace_magic_link_consume( $token );
		$consumed_tokens[] = $token;
		// Log dans l'order meta pour audit
		$order->update_meta_data( '_cordespace_magic_consumed_' . $event_id, $token );
	}
	if ( ! empty( $consumed_tokens ) ) {
		$order->save();
	}
}
add_action( 'woocommerce_checkout_order_processed', 'cordespace_magic_link_consume_on_order_processed', 10, 3 );

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
 * Ajoute un onglet "Magic Links" en haut de la liste des types
 * (à côté de "Tous" / "Publié" / etc., via filter views_edit-{cpt}).
 */
function cordespace_magic_link_add_tab_to_type_list( $views ) {
	$url   = admin_url( 'admin.php?page=' . CORDESPACE_MAGIC_LINKS_PAGE_SLUG );
	$label = __( 'Magic Links', 'cordespace-snippets' );
	$views['cordespace_magic_links'] = '<a href="' . esc_url( $url ) . '">🔗 ' . esc_html( $label ) . '</a>';
	return $views;
}
add_filter( 'views_edit-cordespace_evtype', 'cordespace_magic_link_add_tab_to_type_list' );

/**
 * Et inversement : un lien retour vers la liste des types depuis la page
 * Magic Links (via un sub-nav rendu en haut de la page).
 */
function cordespace_magic_link_render_subnav( string $current ): void {
	$types_url = admin_url( 'edit.php?post_type=cordespace_evtype' );
	$magic_url = admin_url( 'admin.php?page=' . CORDESPACE_MAGIC_LINKS_PAGE_SLUG );
	?>
	<ul class="subsubsub" style="float:none; margin-bottom:1rem;">
		<li>
			<a href="<?php echo esc_url( $types_url ); ?>" class="<?php echo $current === 'types' ? 'current' : ''; ?>">
				🔒 <?php esc_html_e( "Types d'événements à validation", 'cordespace-snippets' ); ?>
			</a> |
		</li>
		<li>
			<a href="<?php echo esc_url( $magic_url ); ?>" class="<?php echo $current === 'magic' ? 'current' : ''; ?>">
				🔗 <?php esc_html_e( 'Magic Links', 'cordespace-snippets' ); ?>
			</a>
		</li>
	</ul>
	<div style="clear:both;"></div>
	<?php
}

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
			$expires_in  = sanitize_text_field( $_POST['expires_in'] ?? '' ); // '', '24h', '7d', '30d', 'never'
			$notes       = sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) );
			$expires_at  = null;
			if ( $expires_in === '24h' ) {
				$expires_at = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS );
			} elseif ( $expires_in === '7d' ) {
				$expires_at = gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS );
			} elseif ( $expires_in === '30d' ) {
				$expires_at = gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS );
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

	// Liste des events Amelia futurs (pour le sélecteur)
	global $wpdb;
	$events = $wpdb->get_results(
		"SELECT e.id, e.name, MIN(ep.periodStart) AS first_date
		 FROM {$wpdb->prefix}amelia_events e
		 LEFT JOIN {$wpdb->prefix}amelia_events_periods ep ON ep.eventId = e.id
		 WHERE e.status = 'approved'
		 GROUP BY e.id
		 HAVING first_date IS NULL OR first_date >= UTC_TIMESTAMP() - INTERVAL 30 DAY
		 ORDER BY first_date ASC, e.name ASC
		 LIMIT 200",
		ARRAY_A
	);

	?>
	<div class="wrap">
		<h1>🔗 <?php esc_html_e( 'Magic Links', 'cordespace-snippets' ); ?></h1>
		<?php cordespace_magic_link_render_subnav( 'magic' ); ?>
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
					<th><label for="event_id"><?php esc_html_e( 'Event Amelia', 'cordespace-snippets' ); ?></label></th>
					<td>
						<select name="event_id" id="event_id" required>
							<option value=""><?php esc_html_e( '— Choisir un event —', 'cordespace-snippets' ); ?></option>
							<?php foreach ( $events as $e ) :
								$date_str = $e['first_date'] ? ' (' . mysql2date( 'd M Y', $e['first_date'] ) . ')' : '';
								?>
								<option value="<?php echo (int) $e['id']; ?>">
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
						<select name="expires_in" id="expires_in">
							<option value="never"><?php esc_html_e( 'Jamais', 'cordespace-snippets' ); ?></option>
							<option value="24h" selected><?php esc_html_e( 'Dans 24 heures', 'cordespace-snippets' ); ?></option>
							<option value="7d"><?php esc_html_e( 'Dans 7 jours', 'cordespace-snippets' ); ?></option>
							<option value="30d"><?php esc_html_e( 'Dans 30 jours', 'cordespace-snippets' ); ?></option>
						</select>
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
						<th style="width:30%;"><?php esc_html_e( 'Event', 'cordespace-snippets' ); ?></th>
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
						?>
						<tr>
							<td><?php echo esc_html( $event_name ?: 'Event #' . $link['event_id'] ); ?></td>
							<td>
								<code style="display:block; word-break:break-all; font-size:0.85em; background:#f4f4f4; padding:0.3rem; border-radius:3px;"><?php echo esc_html( $magic_url ); ?></code>
								<button type="button" class="button button-small" onclick="navigator.clipboard.writeText('<?php echo esc_attr( $magic_url ); ?>'); this.textContent = '✓ Copié';" style="margin-top:0.3rem;">📋 <?php esc_html_e( 'Copier', 'cordespace-snippets' ); ?></button>
							</td>
							<td><?php echo (int) $link['used_count']; ?> / <?php echo (int) $link['max_uses'] > 0 ? (int) $link['max_uses'] : '∞'; ?></td>
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
	<?php
}
