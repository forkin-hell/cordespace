<?php
/**
 * Module : mon-espace.shortcode
 *
 * Enveloppe minimaliste du shortcode [cordespace_mon_espace] : aiguillage
 * (non logué·e | client·e | prof) + slots `do_action` que les sous-modules
 * remplissent.
 *
 * Sous-modules associés :
 *   - mon-espace.upcoming-qr     → do_action('cordespace_mon_espace_section_client_qr')
 *   - mon-espace.credit-history  → do_action('cordespace_mon_espace_section_client_credits')
 *   - mon-espace.linked-accounts → fournit [cordespace_switch_button]
 *
 * Helpers utilisés (depuis includes/core/helpers.php) :
 *   - cordespace_user_is_amelia_provider()
 *   - cordespace_get_mon_espace_url()
 *   - cordespace_render_amelia_default_range()
 *   - cordespace_render_amelia_cookie_sync()
 *
 * Dépendances : Amelia (panels), MyCred (solde), User Switching (bascule).
 */

defined( 'ABSPATH' ) || exit;

// Note historique : on a tenté de set le cookie ameliaRangeFuture (côté JS
// puis côté PHP via template_redirect) pour étendre la fenêtre d'events
// affichée par défaut dans le cabinet. Après diagnostic, ce cookie n'est
// lu QUE par le formulaire de booking public (stepForm.js) — les cabinets
// customer et provider ont leurs propres défauts hardcodés dans leur
// Vue.js (1 an pour customer, 7 jours pour provider). Donc on a retiré
// les appels qui essayaient de forcer la range : ça n'avait aucun effet.
// Les profs cliquent sur le date picker du cabinet pour étendre s'iels
// veulent voir plus loin que la semaine en cours.

add_shortcode( 'cordespace_mon_espace', 'cordespace_render_mon_espace_shortcode' );

function cordespace_render_mon_espace_shortcode( $atts ) {
	ob_start();
	cordespace_render_mon_espace_mobile_css();

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
 * CSS responsive global pour la page /mon-espace/. Réduit l'encombrement
 * vertical sur mobile (≤ 600px) : padding des sections, taille des titres,
 * espacement du Bonjour. Les sections internes (today_students, upcoming-qr)
 * ont leurs propres règles mobile dans leurs modules respectifs.
 */
function cordespace_render_mon_espace_mobile_css() {
	?>
	<style>
		/* ===================== Tous viewports ===================== */
		/* Réduit le grand espace entre notre <p> de description et le panel Amelia.
		   Amelia sort un <div id="amelia-v2-booking-X" class="amelia-v2-booking">
		   qui contient un <customer-panel-wrapper> ou <employee-panel-wrapper>
		   (custom element Vue). On neutralise les margin/padding/min-height
		   sur tous ces niveaux. */
		.cordespace-page section > div[id^="amelia"],
		.cordespace-page section > div[class*="amelia"],
		.cordespace-page section .amelia-v2-booking {
			margin-top: 0 !important;
			padding-top: 0 !important;
			min-height: 0 !important;
		}
		.cordespace-page section customer-panel-wrapper,
		.cordespace-page section employee-panel-wrapper {
			display: block;
			margin-top: 0 !important;
			padding-top: 0 !important;
		}
		/* Premier enfant rendu par le Vue app — souvent porteur d'un margin-top. */
		.cordespace-page section .amelia-v2-booking > *:first-child,
		.cordespace-page section customer-panel-wrapper > *:first-child,
		.cordespace-page section employee-panel-wrapper > *:first-child {
			margin-top: 0 !important;
		}
		/* <p> vides éventuels injectés par wpautop. */
		.cordespace-page section > p:empty {
			display: none !important;
			margin: 0 !important;
		}

		/* ===================== Mobile (<= 600px) ===================== */
		@media (max-width: 600px) {
			.cordespace-page section {
				padding: 1.1rem 1.1rem !important;
				margin-bottom: 1.5rem !important;
				border-radius: 8px !important;
			}
			.cordespace-page section > h2 {
				font-size: 1.15em !important;
				margin-bottom: 0.3rem !important;
			}
			.cordespace-page section > p {
				font-size: 0.85em !important;
				margin-bottom: 0.8rem !important;
			}
			/* Bloc Bonjour plus compact */
			.cordespace-page .cordespace-greeting-block {
				padding: 1.3rem 1.2rem !important;
				margin-bottom: 1rem !important;
			}
			.cordespace-page .cordespace-greeting-block h1 {
				font-size: 1.4rem !important;
			}
			.cordespace-page .cordespace-greeting-block p {
				font-size: 0.95em !important;
			}
			/* Navigation par ancres : items plus petits */
			.cordespace-page nav {
				gap: 0.35rem !important;
				padding: 0.5rem !important;
			}
			.cordespace-page nav a {
				padding: 0.4rem 0.7rem !important;
				font-size: 0.85em !important;
			}
		}
	</style>
	<?php
}

/**
 * État non-logué·e : carte d'accueil + message Amelia clair (sans panel)
 */
function cordespace_render_logged_out_view() {
	$login_url    = wp_login_url( cordespace_get_mon_espace_url() );
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

/* ========================================================================
   VUE CLIENT·E
   ======================================================================== */
function cordespace_render_client_view( $user, $has_linked ) {
	$switch_button = $has_linked
		? do_shortcode( '[cordespace_switch_button label="Basculer vers mon compte enseignant·e"]' )
		: '';
	$greet_name  = cordespace_user_greeting_name( $user );
	// Filtre cordespace_greeting_theme_class — wrappe toute la vue dans une
	// classe thème (ex: cordespace-theme-dinosaurs). Les CSS du module
	// greeting-themes ciblent les descendants via .cordespace-theme-X .truc.
	$theme_class = apply_filters( 'cordespace_greeting_theme_class', '', $user );
	?>
	<div class="cordespace-page cordespace-page-client <?php echo esc_attr( $theme_class ); ?>">
	<?php if ( $switch_button ) : ?>
		<div style="background:#fef9e6;border-left:4px solid #f5b800;padding:1rem 1.2rem;margin-bottom:1.5rem;border-radius:6px;">
			<strong style="color:#7a5d00;">👩‍🏫 Tu enseignes aussi chez Cordespace ?</strong>
			<div style="margin-top:0.6rem;"><?php echo $switch_button; ?></div>
		</div>
	<?php endif; ?>

	<?php
	/**
	 * Slot : QR codes des cours à venir (sous-module mon-espace.upcoming-qr).
	 * Si le module est désactivé, rien ne s'affiche.
	 */
	do_action( 'cordespace_mon_espace_section_client_qr', $user );

	/**
	 * Slot : banderoles d'alerte au top (avant le greeting block).
	 * Ex : module waivers-post-purchase-prompt qui rappelle de signer un
	 * document avant le prochain cours.
	 *
	 * Args : $user (WP_User).
	 */
	do_action( 'cordespace_mon_espace_section_client_top_banner', $user );
	?>

	<div class="cordespace-greeting-block" style="background:linear-gradient(135deg,#5b2c8f 0%,#1a1a2e 100%);color:#fff;padding:2rem;border-radius:10px;margin-bottom:1.5rem;">
		<?php if ( function_exists( 'cordespace_get_user_greeting_theme' ) ) {
			$_theme = cordespace_get_user_greeting_theme( (int) $user->ID );
			if ( $_theme && function_exists( 'cordespace_render_greeting_decor' ) ) {
				cordespace_render_greeting_decor( $_theme );
			}
		} ?>
		<h1 style="margin:0 0 0.4rem;color:#fff;font-size:1.8rem;">Bonjour<?php echo $greet_name !== '' ? ' ' . esc_html( $greet_name ) : ''; ?></h1>
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
		<a href="#section-commandes" style="text-decoration:none;padding:0.5rem 1rem;background:#fff;border-radius:5px;color:#333;border:1px solid #e0e0e0;font-size:0.95em;">🛒 Mes commandes</a>
	</nav>

	<section id="section-cours" style="margin-bottom:2.5rem;padding:1.8rem;background:#fff;border:1px solid #e5e5e5;border-radius:10px;">
		<h2 style="margin:0 0 0.4rem;font-size:1.4rem;">📅 Mes prochains cours</h2>
		<p style="color:#666;margin:0 0 1.2rem;font-size:0.95em;">Tes inscriptions aux ateliers et événements Cordespace.</p>
		<?php echo do_shortcode( '[ameliacustomerpanel events=1]' ); ?>
	</section>

	<?php
	/**
	 * Slot : section "Mes waivers" (sous-module waivers.mon-espace).
	 * Si le module est désactivé, on retombe sur un placeholder pour ne pas casser
	 * l'ancre #section-waivers du nav du haut.
	 */
	if ( has_action( 'cordespace_mon_espace_section_client_waivers' ) ) {
		do_action( 'cordespace_mon_espace_section_client_waivers', $user );
	} else {
		?>
		<section id="section-waivers" style="margin-bottom:2.5rem;padding:1.8rem;background:#fff;border:1px solid #e5e5e5;border-radius:10px;">
			<h2 style="margin:0 0 0.4rem;font-size:1.4rem;">📋 Mes waivers</h2>
			<p style="color:#666;margin:0 0 1.2rem;font-size:0.95em;">Les décharges et formulaires que tu as signés.</p>
			<div style="padding:1.2rem;background:#fff8e6;border-left:4px solid #f5b800;border-radius:5px;color:#7a5d00;">
				<strong>🚧 Bientôt disponible</strong><br>
				Le système de waivers sera ajouté dans la prochaine phase.
			</div>
		</section>
		<?php
	}
	?>

	<?php
	/**
	 * Slot : historique des crédits MyCred (sous-module mon-espace.credit-history).
	 */
	do_action( 'cordespace_mon_espace_section_client_credits', $user );

	/**
	 * Slot : tableau des commandes WooCommerce (sous-module mon-espace.orders).
	 */
	do_action( 'cordespace_mon_espace_section_client_orders', $user );
	?>
	</div><?php // /.cordespace-page ?>
	<?php
}

/* ========================================================================
   VUE PROF
   ======================================================================== */
function cordespace_render_prof_view( $user, $has_linked ) {
	$switch_button = $has_linked
		? do_shortcode( '[cordespace_switch_button label="Basculer vers mon compte client·e"]' )
		: '';
	$greet_name  = cordespace_user_greeting_name( $user );
	// Filtre cordespace_greeting_theme_class — wrappe toute la vue dans une
	// classe thème (ex: cordespace-theme-dinosaurs). Les CSS du module
	// greeting-themes ciblent les descendants via .cordespace-theme-X .truc.
	$theme_class = apply_filters( 'cordespace_greeting_theme_class', '', $user );

	// Note : on n'a plus besoin de filtrer la section "Outils enseignant·e"
	// par rôle. Tous les profs (même les wpamelia-provider simples) peuvent
	// accéder à wp-admin → Amelia → Events ; Amelia 9.5 leur applique
	// automatiquement le filtre provider et ils voient seulement leurs
	// propres events. Les admins/managers voient tout.
	?>
	<div class="cordespace-page cordespace-page-prof <?php echo esc_attr( $theme_class ); ?>">
	<?php if ( $switch_button ) : ?>
		<div style="background:#fef9e6;border-left:4px solid #f5b800;padding:1rem 1.2rem;margin-bottom:1.5rem;border-radius:6px;">
			<strong style="color:#7a5d00;">🎒 Tu prends aussi des cours chez Cordespace ?</strong>
			<div style="margin-top:0.6rem;"><?php echo $switch_button; ?></div>
			<p style="margin:0.6rem 0 0;font-size:0.9em;color:#7a5d00;">
				<em>Pour acheter un cours, un produit boutique ou utiliser tes crédits, bascule d'abord sur ton compte client·e.</em>
			</p>
		</div>
	<?php endif; ?>

	<div class="cordespace-greeting-block" style="background:linear-gradient(135deg,#1d4d7e 0%,#1a1a2e 100%);color:#fff;padding:2rem;border-radius:10px;margin-bottom:1.5rem;">
		<?php if ( function_exists( 'cordespace_get_user_greeting_theme' ) ) {
			$_theme = cordespace_get_user_greeting_theme( (int) $user->ID );
			if ( $_theme && function_exists( 'cordespace_render_greeting_decor' ) ) {
				cordespace_render_greeting_decor( $_theme );
			}
		} ?>
		<h1 style="margin:0 0 0.4rem;color:#fff;font-size:1.8rem;">Bonjour<?php echo $greet_name !== '' ? ' ' . esc_html( $greet_name ) : ''; ?></h1>
		<p style="margin:0;opacity:0.9;font-size:1.05em;">Bienvenue dans ton espace enseignant·e. Retrouve ici tes élèves du jour et tes prochains cours.</p>
	</div>

	<nav style="display:flex;flex-wrap:wrap;gap:0.5rem;margin-bottom:2rem;padding:0.8rem;background:#f7f7f7;border-radius:6px;">
		<a href="#section-eleves" style="text-decoration:none;padding:0.5rem 1rem;background:#fff;border-radius:5px;color:#333;border:1px solid #e0e0e0;font-size:0.95em;">👥 Mes élèves</a>
		<a href="#section-cours-prof" style="text-decoration:none;padding:0.5rem 1rem;background:#fff;border-radius:5px;color:#333;border:1px solid #e0e0e0;font-size:0.95em;">📅 Mes cours à enseigner</a>
		<a href="#section-outils" style="text-decoration:none;padding:0.5rem 1rem;background:#fff;border-radius:5px;color:#333;border:1px solid #e0e0e0;font-size:0.95em;">🛠️ Outils</a>
	</nav>

	<section id="section-eleves" style="margin-bottom:2.5rem;padding:1.8rem;background:#fff;border:1px solid #e5e5e5;border-radius:10px;">
		<h2 style="margin:0 0 0.4rem;font-size:1.4rem;">👥 Mes élèves du jour</h2>
		<p style="color:#666;margin:0 0 1.2rem;font-size:0.95em;">Clique sur le bouton à droite de chaque personne pour marquer sa présence (24h de cours autour).</p>
		<?php echo do_shortcode( '[cordespace_today_students]' ); ?>
	</section>

	<section id="section-cours-prof" style="margin-bottom:2.5rem;padding:1.8rem;background:#fff;border:1px solid #e5e5e5;border-radius:10px;">
		<h2 style="margin:0 0 0.4rem;font-size:1.4rem;">📅 Mes prochains cours à enseigner</h2>
		<p style="color:#666;margin:0 0 1.2rem;font-size:0.95em;">Les ateliers et événements où tu es enseignant·e assigné·e (1 an à l'avance).</p>
		<?php echo do_shortcode( '[ameliaemployeepanel events=1]' ); ?>
	</section>

	<section id="section-outils" style="margin-bottom:2.5rem;padding:1.8rem;background:#fff;border:1px solid #e5e5e5;border-radius:10px;">
		<h2 style="margin:0 0 0.4rem;font-size:1.4rem;">🛠️ Outils enseignant·e</h2>
		<p style="color:#666;margin:0 0 1.2rem;font-size:0.95em;">Raccourcis vers la gestion d'Amelia. Selon ton rôle tu verras soit tous les cours (admin / manager), soit seulement les tiens.</p>
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
	</section>
	</div><?php // /.cordespace-page ?>
	<?php
}
