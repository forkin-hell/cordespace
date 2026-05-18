<?php
/**
 * Shortcode [cordespace_mon_espace] — page unifiée /mon-espace/ (ex-WPCode 1232).
 *
 * - Non logué·e : carte d'accueil + boutons login / register
 * - Client·e Amelia : panel client + crédits MyCred + QR codes des cours à venir
 * - Prof Amelia : panel employé + toggle de présence pour les élèves du jour
 * - Compte dual (prof + client·e lié) : bandeau de bascule en haut
 *
 * Helpers :
 *   - cordespace_user_is_amelia_provider()
 *   - cordespace_render_upcoming_classes_qr()
 *
 * Dépendances : Amelia, MyCred, MyCred Amelia, User Switching.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cordespace — Shortcode [cordespace_mon_espace] (v4)
 *
 * - État non logué·e : carte d'accueil + bouton, message Amelia clair
 * - État logué·e : vue prof ou cliente selon le rôle
 * - JS : sync cookie Amelia + force la plage de dates à 1 an
 *
 * Self-contained, ne dépend d'aucun autre snippet.
 */

add_shortcode( 'cordespace_mon_espace', 'cordespace_render_mon_espace_shortcode' );

function cordespace_render_mon_espace_shortcode( $atts ) {
	ob_start();
	cordespace_render_amelia_default_range();

	if ( ! is_user_logged_in() ) {
		echo cordespace_render_logged_out_view();
		return ob_get_clean();
	}

	$user       = wp_get_current_user();
	$linked_id  = (int) get_user_meta( $user->ID, '_cordespace_linked_user_id', true );
	$has_linked = $linked_id > 0;
	$is_prof    = cordespace_user_is_amelia_provider( $user->ID );

	cordespace_render_amelia_cookie_sync( $user );

	if ( $is_prof ) {
		cordespace_render_prof_view( $user, $has_linked );
	} else {
		cordespace_render_client_view( $user, $has_linked );
	}
	return ob_get_clean();
}

/**
 * Helper : un user WP donné est-il un·e provider dans la table Amelia ?
 */
if ( ! function_exists( 'cordespace_user_is_amelia_provider' ) ) {
	function cordespace_user_is_amelia_provider( int $wp_user_id ): bool {
		if ( $wp_user_id <= 0 ) return false;
		static $cache = [];
		if ( isset( $cache[ $wp_user_id ] ) ) return $cache[ $wp_user_id ];
		global $wpdb;
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}amelia_users
  				WHERE externalId = %d AND type IN ('provider', 'manager') AND status = 'visible'",
			$wp_user_id
		) );
		$cache[ $wp_user_id ] = $count > 0;
		return $cache[ $wp_user_id ];
	}
}

/**
 * Force la plage de dates par défaut des panels Amelia à 1 an dans le futur
 * (au lieu de 14 jours par défaut pour le panel employé·e).
 * Ne shrink jamais si l'utilisateur·trice a déjà étendu manuellement.
 */
function cordespace_render_amelia_default_range() {
	?>
	<script>
	(function () {
		function setMinCookie(name, minVal) {
			var m = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]+)'));
			var current = m ? parseInt(m[1], 10) : 0;
			if (isNaN(current) || current < minVal) {
				document.cookie = name + '=' + minVal + '; path=/; max-age=31536000';
			}
		}
		setMinCookie('ameliaRangeFuture', 365);
		// On ne touche pas ameliaRangePast pour respecter la préférence user
	})();
	</script>
	<?php
}

/**
 * État non-logué·e : carte d'accueil + message Amelia clair (sans panel)
 */
function cordespace_render_logged_out_view() {
	$login_url    = wp_login_url( site_url( '/mon-espace/' ) );
	$register_url = wp_registration_url();
	$can_register = get_option( 'users_can_register' );

	ob_start();
	?>
	<div style="background:linear-gradient(135deg,#5b2c8f 0%,#1a1a2e 100%);color:#fff;padding:2.5rem 2rem;border-radius:10px;margin-bottom:1.5rem;text-align:center;">
		<h1 style="margin:0 0 0.6rem;color:#fff;font-size:1.8rem;">Bienvenue 👋</h1>
		<p style="margin:0 0 1.5rem;opacity:0.92;font-size:1.05em;">
			Connecte-toi pour retrouver tes cours, tes crédits et tes réservations.
		</p>
		<a href="<?php echo esc_url( $login_url ); ?>" style="display:inline-block;padding:0.8rem 2rem;background:#fff;color:#5b2c8f;text-decoration:none;border-radius:6px;font-weight:700;font-size:1.05em;">
			🔐 Se connecter
		</a>
		<?php if ( $can_register ) : ?>
			<p style="margin:1.2rem 0 0;font-size:0.95em;opacity:0.85;">
				Pas encore de compte ?
				<a href="<?php echo esc_url( $register_url ); ?>" style="color:#fff;text-decoration:underline;">Créer mon compte</a>
			</p>
		<?php endif; ?>
	</div>

	<div style="padding:1.4rem 1.6rem;background:#eef5fd;border-left:4px solid #2c70b8;border-radius:6px;color:#1d4d7e;font-size:0.97em;margin-bottom:3rem;">
		<strong>💡 Utilise tes identifiants Amelia</strong> via le bouton « Se connecter » ci-dessus.<br>
		Que tu sois enseignant·e ou client·e, ta vue s'adaptera automatiquement après la connexion.
	</div>
	<?php
	return ob_get_clean();
}

/**
 * JS auto-clear : détecte désynchro cookie Amelia ↔ session WP, nettoie
 */
function cordespace_render_amelia_cookie_sync( $user ) {
	$expected_email = strtolower( (string) $user->user_email );
	?>
	<script>
	(function () {
		var expectedEmail = <?php echo wp_json_encode( $expected_email ); ?>;
		var m = document.cookie.match(/(?:^|; )ameliaUserEmail=([^;]+)/);
		var cookieEmail = m ? decodeURIComponent(m[1]).toLowerCase() : null;
		if (cookieEmail && cookieEmail !== expectedEmail) {
			document.cookie = 'ameliaToken=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
			document.cookie = 'ameliaUserEmail=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
			if (!sessionStorage.getItem('cordespace_amelia_sync_done')) {
				sessionStorage.setItem('cordespace_amelia_sync_done', '1');
				window.location.reload();
			}
		} else {
			sessionStorage.removeItem('cordespace_amelia_sync_done');
		}
	})();
	</script>
	<?php
}

/* ========================================================================
   VUE CLIENT·E
   ======================================================================== */
function cordespace_render_client_view( $user, $has_linked ) {
	$switch_button = $has_linked
		? do_shortcode( '[cordespace_switch_button label="Basculer vers mon compte enseignant·e"]' )
		: '';
	
	$qr_section = cordespace_render_upcoming_classes_qr( $user );
	?>
	<?php if ( $switch_button ) : ?>
		<div style="background:#fef9e6;border-left:4px solid #f5b800;padding:1rem 1.2rem;margin-bottom:1.5rem;border-radius:6px;">
			<strong style="color:#7a5d00;">👩‍🏫 Tu enseignes aussi chez Cordespace ?</strong>
			<div style="margin-top:0.6rem;"><?php echo $switch_button; ?></div>
		</div>
	<?php endif; ?>

	<?php echo $qr_section; ?>

	<div style="background:linear-gradient(135deg,#5b2c8f 0%,#1a1a2e 100%);color:#fff;padding:2rem;border-radius:10px;margin-bottom:1.5rem;">
		<h1 style="margin:0 0 0.4rem;color:#fff;font-size:1.8rem;">Bonjour 👋</h1>
		<p style="margin:0;opacity:0.9;font-size:1.05em;">Bienvenue dans ton espace Cordespace.</p>
		<div style="margin-top:1.5rem;">
			<div style="display:inline-block;background:rgba(255,255,255,0.15);padding:0.8rem 1.4rem;border-radius:8px;">
				<div style="font-size:0.85em;opacity:0.85;text-transform:uppercase;letter-spacing:0.5px;">Mes crédits</div>
				<div style="font-size:1.6rem;font-weight:700;margin-top:0.2rem;"><?php echo do_shortcode( '[mycred_my_balance show_zero=yes]' ); ?></div>
			</div>
		</div>
	</div>

	<nav style="display:flex;flex-wrap:wrap;gap:0.5rem;margin-bottom:2rem;padding:0.8rem;background:#f7f7f7;border-radius:6px;">
		<a href="#section-cours" style="text-decoration:none;padding:0.5rem 1rem;background:#fff;border-radius:5px;color:#333;border:1px solid #e0e0e0;font-size:0.95em;">📅 Mes cours</a>
		<a href="#section-waivers" style="text-decoration:none;padding:0.5rem 1rem;background:#fff;border-radius:5px;color:#333;border:1px solid #e0e0e0;font-size:0.95em;">📋 Mes waivers</a>
		<a href="#section-credits" style="text-decoration:none;padding:0.5rem 1rem;background:#fff;border-radius:5px;color:#333;border:1px solid #e0e0e0;font-size:0.95em;">💰 Historique crédits</a>
		<a href="/mon-compte/orders/" style="text-decoration:none;padding:0.5rem 1rem;background:#fff;border-radius:5px;color:#333;border:1px solid #e0e0e0;font-size:0.95em;">🛒 Mes commandes</a>
	</nav>

	<section id="section-cours" style="margin-bottom:2.5rem;padding:1.8rem;background:#fff;border:1px solid #e5e5e5;border-radius:10px;">
		<h2 style="margin:0 0 0.4rem;font-size:1.4rem;">📅 Mes prochains cours</h2>
		<p style="color:#666;margin:0 0 1.2rem;font-size:0.95em;">Tes inscriptions aux ateliers et événements Cordespace.</p>
		<?php echo do_shortcode( '[ameliacustomerpanel events=1]' ); ?>
	</section>

	<section id="section-waivers" style="margin-bottom:2.5rem;padding:1.8rem;background:#fff;border:1px solid #e5e5e5;border-radius:10px;">
		<h2 style="margin:0 0 0.4rem;font-size:1.4rem;">📋 Mes waivers</h2>
		<p style="color:#666;margin:0 0 1.2rem;font-size:0.95em;">Les décharges et formulaires que tu as signés.</p>
		<div style="padding:1.2rem;background:#fff8e6;border-left:4px solid #f5b800;border-radius:5px;color:#7a5d00;">
			<strong>🚧 Bientôt disponible</strong><br>
			Le système de waivers sera ajouté dans la prochaine phase.
		</div>
	</section>

	<section id="section-credits" style="margin-bottom:2.5rem;padding:1.8rem;background:#fff;border:1px solid #e5e5e5;border-radius:10px;">
		<h2 style="margin:0 0 0.4rem;font-size:1.4rem;">💰 Historique de mes crédits</h2>
		<p style="color:#666;margin:0 0 1.2rem;font-size:0.95em;">Solde actuel et 10 dernières transactions.</p>
		<div style="padding:1rem 1.4rem;background:#f0f7f0;border-radius:6px;margin-bottom:1.2rem;">
			<span style="color:#3a7a3a;font-weight:600;">Solde actuel :</span> <strong style="font-size:1.2em;"><?php echo do_shortcode( '[mycred_my_balance show_zero=yes]' ); ?></strong>
		</div>
		<?php echo do_shortcode( '[mycred_history number=10]' ); ?>
	</section>
	<?php
}

/**
 * Récupère les bookings approved/pending du user (Customer Amelia) dans
 * les 24h à venir et affiche un QR code pour chacun, identique à celui
 * que le scanner Amelia attend. Badge visuel "à valider" pour les pending.
 */
function cordespace_render_upcoming_classes_qr( $user ) {
	global $wpdb;

	// Trouve l'Amelia customer record du user
	$amelia_customer_id = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM {$wpdb->prefix}amelia_users
		  WHERE externalId = %d AND type = 'customer' AND status = 'visible'
		  LIMIT 1",
		$user->ID
	) );

	if ( $amelia_customer_id <= 0 ) {
		return '';
	}

	$now    = current_time( 'mysql', true );                            // UTC
	$in_24h = gmdate( 'Y-m-d H:i:s', time() + 24 * HOUR_IN_SECONDS );   // UTC

	$bookings = $wpdb->get_results( $wpdb->prepare(
		"SELECT b.id, b.status, b.qrCodes, e.name AS event_name, ep.periodStart, ep.periodEnd
		   FROM {$wpdb->prefix}amelia_customer_bookings b
		   JOIN {$wpdb->prefix}amelia_customer_bookings_to_events_periods bep ON bep.customerBookingId = b.id
		   JOIN {$wpdb->prefix}amelia_events_periods ep ON ep.id = bep.eventPeriodId
		   JOIN {$wpdb->prefix}amelia_events e ON e.id = ep.eventId
		  WHERE b.customerId = %d
		    AND b.status IN ('approved', 'pending')
		    AND ep.periodStart <= %s
		    AND ep.periodEnd   >= %s
		  ORDER BY ep.periodStart ASC",
		$amelia_customer_id,
		$in_24h,
		$now
	), ARRAY_A );

	if ( empty( $bookings ) ) {
		return '';
	}

	ob_start();
	?>
	<div style="background:linear-gradient(135deg,#5b2c8f 0%,#3a1a5e 100%);color:#fff;padding:1.5rem 1.8rem;border-radius:10px;margin-bottom:1.5rem;border:2px solid #f5b800;">
		<h2 style="margin:0 0 0.4rem;color:#fff;font-size:1.4rem;">🎫 <?php echo count( $bookings ) > 1 ? 'Tes prochains cours (24h)' : 'Ton prochain cours'; ?></h2>
		<p style="margin:0 0 1.2rem;opacity:0.92;font-size:0.95em;">Présente ces QR à ton arrivée pour le check-in.</p>

		<?php foreach ( $bookings as $booking ) :
			$is_pending = ( $booking['status'] === 'pending' );
			$qr_data    = ! empty( $booking['qrCodes'] ) ? json_decode( $booking['qrCodes'], true ) : null;
			$qr_string  = ( is_array( $qr_data ) && ! empty( $qr_data[0]['qrCodeData'] ) ) ? $qr_data[0]['qrCodeData'] : '';
			$qr_url     = $qr_string
				? 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&margin=8&data=' . urlencode( $qr_string )
				: '';
			$when       = mysql2date( 'l j F — H\hi', $booking['periodStart'] );
		?>
			<div style="background:rgba(255,255,255,0.12);padding:1rem 1.2rem;border-radius:8px;margin-bottom:0.7rem;display:flex;gap:1.2rem;align-items:center;flex-wrap:wrap;<?php echo $is_pending ? 'outline:2px solid #f5b800;outline-offset:-2px;' : ''; ?>">
				<div style="flex:1;min-width:200px;">
					<div style="font-size:1.1em;font-weight:600;margin-bottom:0.3rem;line-height:1.4;">
						<?php echo esc_html( $booking['event_name'] ); ?>
						<?php if ( $is_pending ) : ?>
							<span style="display:inline-block;background:#f5b800;color:#3a2c00;font-size:0.7em;padding:0.2rem 0.55rem;border-radius:4px;margin-left:0.4rem;font-weight:700;vertical-align:middle;letter-spacing:0.3px;">⏳ PAIEMENT À VALIDER</span>
						<?php endif; ?>
					</div>
					<div style="opacity:0.9;font-size:0.95em;">📅 <?php echo esc_html( $when ); ?></div>

					<?php if ( $is_pending ) : ?>
						<div style="margin-top:0.6rem;font-size:0.85em;background:rgba(245,184,0,0.18);padding:0.5rem 0.7rem;border-radius:4px;line-height:1.4;">
							💡 <strong>Si tu as déjà payé par Interac</strong>, ton check-in se fera quand même.<br>
							Sinon, prépare ton paiement à l'arrivée.
						</div>
					<?php endif; ?>
				</div>

				<?php if ( $qr_url ) : ?>
					<div style="background:white;padding:0.5rem;border-radius:6px;<?php echo $is_pending ? 'opacity:0.85;' : ''; ?>">
						<img src="<?php echo esc_url( $qr_url ); ?>" alt="QR code" width="130" height="130" style="display:block;">
					</div>
				<?php else : ?>
					<div style="background:rgba(255,255,255,0.1);padding:0.6rem 0.8rem;border-radius:4px;font-size:0.85em;">QR non dispo,<br>contacte l'équipe.</div>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
	</div>
	<?php
	return ob_get_clean();
}
/* ========================================================================
   VUE PROF
   ======================================================================== */
function cordespace_render_prof_view( $user, $has_linked ) {
	$switch_button = $has_linked
		? do_shortcode( '[cordespace_switch_button label="Basculer vers mon compte client·e"]' )
		: '';

	// Détection des rôles autorisés à gérer les events Amelia en admin
	$roles = isset( $user->roles ) ? (array) $user->roles : [];
	$can_manage_events = ! empty( array_intersect( $roles, [
		'administrator',
		'wpamelia-admin',
		'wpamelia-manager',
	] ) );
	?>
	<?php if ( $switch_button ) : ?>
		<div style="background:#fef9e6;border-left:4px solid #f5b800;padding:1rem 1.2rem;margin-bottom:1.5rem;border-radius:6px;">
			<strong style="color:#7a5d00;">🎒 Tu prends aussi des cours chez Cordespace ?</strong>
			<div style="margin-top:0.6rem;"><?php echo $switch_button; ?></div>
			<p style="margin:0.6rem 0 0;font-size:0.9em;color:#7a5d00;">
				<em>Pour acheter un cours, un produit boutique ou utiliser tes crédits, bascule d'abord sur ton compte client·e.</em>
			</p>
		</div>
	<?php endif; ?>

	<div style="background:linear-gradient(135deg,#1d4d7e 0%,#1a1a2e 100%);color:#fff;padding:2rem;border-radius:10px;margin-bottom:1.5rem;">
		<h1 style="margin:0 0 0.4rem;color:#fff;font-size:1.8rem;">Bonjour 👋</h1>
		<p style="margin:0;opacity:0.9;font-size:1.05em;">Bienvenue dans ton espace enseignant·e. Retrouve ici tes prochains cours à donner.</p>
	</div>

	<nav style="display:flex;flex-wrap:wrap;gap:0.5rem;margin-bottom:2rem;padding:0.8rem;background:#f7f7f7;border-radius:6px;">
		<a href="#section-cours-prof" style="text-decoration:none;padding:0.5rem 1rem;background:#fff;border-radius:5px;color:#333;border:1px solid #e0e0e0;font-size:0.95em;">📅 Mes cours à enseigner</a>
		<a href="#section-eleves" style="text-decoration:none;padding:0.5rem 1rem;background:#fff;border-radius:5px;color:#333;border:1px solid #e0e0e0;font-size:0.95em;">👥 Mes élèves</a>
		<a href="#section-outils" style="text-decoration:none;padding:0.5rem 1rem;background:#fff;border-radius:5px;color:#333;border:1px solid #e0e0e0;font-size:0.95em;">🛠️ Outils</a>
	</nav>

	<section id="section-cours-prof" style="margin-bottom:2.5rem;padding:1.8rem;background:#fff;border:1px solid #e5e5e5;border-radius:10px;">
		<h2 style="margin:0 0 0.4rem;font-size:1.4rem;">📅 Mes prochains cours à enseigner</h2>
		<p style="color:#666;margin:0 0 1.2rem;font-size:0.95em;">Les ateliers et événements où tu es enseignant·e assigné·e (1 an à l'avance).</p>
		<?php echo do_shortcode( '[ameliaemployeepanel events=1]' ); ?>
	</section>

	<section id="section-eleves" style="margin-bottom:2.5rem;padding:1.8rem;background:#fff;border:1px solid #e5e5e5;border-radius:10px;">
    <h2 style="margin:0 0 0.4rem;font-size:1.4rem;">👥 Mes élèves du jour</h2>
    <p style="color:#666;margin:0 0 1.2rem;font-size:0.95em;">Clique sur le bouton à droite de chaque personne pour marquer sa présence (24h de cours autour).</p>
    <?php echo do_shortcode( '[cordespace_today_students]' ); ?>
</section>

	<section id="section-outils" style="margin-bottom:2.5rem;padding:1.8rem;background:#fff;border:1px solid #e5e5e5;border-radius:10px;">
		<h2 style="margin:0 0 0.4rem;font-size:1.4rem;">🛠️ Outils enseignant·e</h2>
		<p style="color:#666;margin:0 0 1.2rem;font-size:0.95em;">Raccourcis vers la gestion d'Amelia pour les enseignant·es autorisé·es.</p>
		<?php if ( $can_manage_events ) : ?>
			<div style="display:flex;flex-wrap:wrap;gap:0.6rem;">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpamelia-events#/' ) ); ?>"
				   style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.7rem 1.2rem;background:#1d4d7e;color:#fff;text-decoration:none;border-radius:6px;font-weight:600;">
					📋 Gérer les événements Amelia
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpamelia-calendar#/' ) ); ?>"
				   style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.7rem 1.2rem;background:#fff;color:#1d4d7e;text-decoration:none;border-radius:6px;font-weight:600;border:1px solid #1d4d7e;">
					🗓️ Voir le calendrier Amelia
				</a>
			</div>
		<?php else : ?>
			<div style="padding:1rem 1.2rem;background:#f7f7f7;border-radius:6px;color:#555;font-size:0.95em;">
				🔒 Tu n'as pas les droits pour éditer les événements directement.<br>
				Pour ajouter / modifier / annuler un cours, <strong>contacte un membre de l'équipe administrative</strong> (gestion@cordespace.com).
			</div>
		<?php endif; ?>
	</section>
	<?php
}
// ============================================================================
// Avertissement aux profs sur les pages WooCommerce (panier + checkout)
// ============================================================================
// Cordespace utilise les blocs WooCommerce (Gutenberg) pour le panier et le
// checkout. Les hooks classiques woocommerce_before_cart/checkout_form ne
// firent PAS sur ces pages. On utilise donc le filtre the_content qui
// fonctionne pour les 2 modes (blocs + shortcode legacy).
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
