<?php
/**
 * Module: waivers-signing-page
 *
 * Page de signature d'un waiver. URL :
 *   /?cordespace_sign_waiver=1&waiver_id=X[&redirect_to=Y]
 *
 * - Si GET : affiche le texte du waiver + un formulaire (2 cases + bouton "Je signe").
 * - Si POST : vérifie nonce + les 2 cases cochées, insère la signature, redirige.
 *
 * Identité : pas de saisie manuelle du nom. On utilise display_name du compte WP
 * connecté (LCJTI : identité authentifiée par le compte, pas par un champ libre).
 *
 * Pas une vraie page WP (pas de slug fixe à gérer) — une URL paramétrée suffit
 * et permet de transmettre redirect_to.
 *
 * Dépend de :
 *   - waivers-cpt   (CORDESPACE_WAIVER_POST_TYPE, cordespace_waivers_get_version)
 *   - waivers-store (cordespace_waivers_sign, cordespace_waivers_has_signed_current,
 *                    cordespace_waivers_history_for_user_and_waiver)
 *
 * Voir docs/superpowers/specs/waivers.md §3.1 et §8 (LCJTI)
 */

defined( 'ABSPATH' ) || exit;

/**
 * Construit l'URL de signature pour un waiver, avec redirect_to optionnel.
 * Public helper utilisé par les autres modules (gating checkout, My Account, etc.).
 */
function cordespace_waivers_get_sign_url( int $waiver_id, string $redirect_to = '' ): string {
	$args = [
		'cordespace_sign_waiver' => '1',
		'waiver_id'              => $waiver_id,
	];
	if ( $redirect_to !== '' ) {
		$args['redirect_to'] = $redirect_to;
	}
	return add_query_arg( $args, home_url( '/' ) );
}

/**
 * Hook principal : si l'URL contient cordespace_sign_waiver=1, on prend la main.
 */
function cordespace_waivers_signing_page_handle(): void {
	if ( empty( $_GET['cordespace_sign_waiver'] ) ) {
		return;
	}
	$waiver_id = isset( $_GET['waiver_id'] ) ? (int) $_GET['waiver_id'] : 0;
	if ( $waiver_id <= 0 ) {
		return;
	}
	$waiver = get_post( $waiver_id );
	if ( ! $waiver
	     || $waiver->post_type !== CORDESPACE_WAIVER_POST_TYPE
	     || $waiver->post_status !== 'publish' ) {
		return;
	}

	// Non connecté·e → redirige vers login avec retour ici
	if ( ! is_user_logged_in() ) {
		wp_safe_redirect( wp_login_url( home_url( add_query_arg( null, null ) ) ) );
		exit;
	}

	$redirect_to = isset( $_GET['redirect_to'] ) ? esc_url_raw( (string) $_GET['redirect_to'] ) : '';

	if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
		cordespace_waivers_signing_page_handle_post( (int) $waiver->ID, $redirect_to );
		return;
	}

	cordespace_waivers_signing_page_render( $waiver, $redirect_to );
	exit;
}
add_action( 'wp_loaded', 'cordespace_waivers_signing_page_handle', 20 );

/**
 * Traite le POST : valide, insère, redirige.
 */
function cordespace_waivers_signing_page_handle_post( int $waiver_id, string $redirect_to ): void {
	if ( ! isset( $_POST['_wpnonce'] )
	     || ! wp_verify_nonce( (string) $_POST['_wpnonce'], 'cordespace_sign_waiver_' . $waiver_id ) ) {
		wp_die( esc_html__( 'Sécurité : nonce invalide ou expiré. Recharge la page et réessaie.', 'cordespace-snippets' ) );
	}

	// Les deux cases obligatoires (LCJTI : consentement explicite séparé lecture/acceptation)
	$confirm_read   = ! empty( $_POST['confirm_read'] );
	$confirm_accept = ! empty( $_POST['confirm_accept'] );
	if ( ! $confirm_read || ! $confirm_accept ) {
		wp_die( esc_html__( 'Tu dois cocher les deux cases pour signer le waiver.', 'cordespace-snippets' ) );
	}

	$user = wp_get_current_user();
	$name = trim( (string) $user->display_name );
	if ( $name === '' ) {
		$name = trim( $user->first_name . ' ' . $user->last_name );
	}
	if ( $name === '' ) {
		$name = $user->user_login; // ultime fallback
	}

	$ip = isset( $_SERVER['REMOTE_ADDR'] )     ? (string) $_SERVER['REMOTE_ADDR']     : '';
	$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';

	$res = cordespace_waivers_sign(
		(int) $user->ID,
		$waiver_id,
		$name,
		[
			'ip'         => $ip,
			'user_agent' => $ua,
			'email'      => (string) $user->user_email,
		]
	);
	if ( is_wp_error( $res ) ) {
		wp_die( esc_html( $res->get_error_message() ) );
	}

	$target = $redirect_to !== ''
		? $redirect_to
		: ( function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'myaccount' ) : home_url() );
	wp_safe_redirect( $target );
	exit;
}

/**
 * Rend la page de signature (form ou "déjà signé").
 */
function cordespace_waivers_signing_page_render( WP_Post $waiver, string $redirect_to ): void {
	$user         = wp_get_current_user();
	$default_name = $user->display_name ?: trim( $user->first_name . ' ' . $user->last_name );
	$already      = cordespace_waivers_has_signed_current( (int) $user->ID, (int) $waiver->ID );
	$last_sig     = $already ? cordespace_waivers_history_for_user_and_waiver( (int) $user->ID, (int) $waiver->ID, 1 ) : [];
	$back         = $redirect_to !== ''
		? $redirect_to
		: ( function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'myaccount' ) : home_url() );
	$version      = cordespace_waivers_get_version( (int) $waiver->ID );

	status_header( 200 );
	nocache_headers();
	get_header();
	?>
	<main class="cordespace-sign-waiver" style="max-width:760px; margin:2rem auto 3rem; padding:0 1rem;">
		<h1 style="margin-bottom:0.4rem;"><?php echo esc_html( get_the_title( $waiver ) ); ?></h1>
		<p style="color:#666; margin-top:0;">
			<?php printf( esc_html__( 'Version %s', 'cordespace-snippets' ), esc_html( $version ) ); ?>
			&nbsp;·&nbsp;
			<?php printf(
				esc_html__( 'Signataire : %s', 'cordespace-snippets' ),
				esc_html( $default_name )
			); ?>
		</p>

		<?php if ( $already && ! empty( $last_sig ) ) :
			$sig = $last_sig[0];
			?>
			<section style="padding:1rem 1.5rem; background:#eef5ee; border-left:4px solid #3a7a3a; margin:1rem 0; border-radius:0 4px 4px 0;">
				<strong><?php esc_html_e( '✓ Tu as déjà signé ce waiver', 'cordespace-snippets' ); ?></strong>
				<p style="margin:0.3rem 0 0; color:#555;">
					<?php printf(
						esc_html__( 'Signé le %1$s sous le nom « %2$s ».', 'cordespace-snippets' ),
						esc_html( mysql2date( 'j F Y — H\hi', (string) $sig['signed_at'] ) ),
						esc_html( (string) $sig['signed_name'] )
					); ?>
				</p>
			</section>
		<?php endif; ?>

		<article style="padding:1.5rem 2rem; background:#fafafa; border:1px solid #ddd; margin:1rem 0; border-radius:4px;">
			<?php echo apply_filters( 'the_content', (string) $waiver->post_content ); // phpcs:ignore ?>
		</article>

		<?php if ( $already ) : ?>
			<p style="margin-top:1.5rem;">
				<a href="<?php echo esc_url( $back ); ?>" style="display:inline-block; padding:0.6rem 1.2rem; background:#eee; color:#333; text-decoration:none; border-radius:4px;">
					<?php esc_html_e( '← Retour', 'cordespace-snippets' ); ?>
				</a>
			</p>
		<?php else : ?>
			<form method="post" id="cordespace-waiver-sign-form" style="margin-top:1.5rem;">
				<?php wp_nonce_field( 'cordespace_sign_waiver_' . $waiver->ID ); ?>
				<?php if ( $redirect_to !== '' ) : ?>
					<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>">
				<?php endif; ?>

				<p style="margin-bottom:0.6rem;"><strong><?php esc_html_e( 'Pour signer électroniquement ce document, coche les deux cases ci-dessous :', 'cordespace-snippets' ); ?></strong></p>

				<p style="padding:0.9rem 1.1rem; background:#fff8e1; border-left:4px solid #d97706; margin:0.6rem 0; border-radius:0 4px 4px 0;">
					<label style="cursor:pointer;">
						<input type="checkbox" name="confirm_read" value="1" class="cordespace-waiver-check" required>
						<?php esc_html_e( "J'ai lu et compris l'intégralité du texte ci-dessus.", 'cordespace-snippets' ); ?>
					</label>
				</p>

				<p style="padding:0.9rem 1.1rem; background:#fff8e1; border-left:4px solid #d97706; margin:0.6rem 0 1.2rem; border-radius:0 4px 4px 0;">
					<label style="cursor:pointer;">
						<input type="checkbox" name="confirm_accept" value="1" class="cordespace-waiver-check" required>
						<?php esc_html_e( "J'accepte expressément le contenu de ce document et j'adhère à toutes ses conditions.", 'cordespace-snippets' ); ?>
					</label>
				</p>

				<p style="margin:1.2rem 0;">
					<button type="submit" id="cordespace-waiver-submit" disabled style="padding:0.7rem 1.6rem; background:#1a1a2e; color:#fff; border:none; border-radius:4px; cursor:pointer; font-size:1em; font-weight:600; opacity:0.4;">
						<?php esc_html_e( 'Je signe', 'cordespace-snippets' ); ?>
					</button>
				</p>

				<p style="margin-top:1.2rem; padding:1rem 1.2rem; background:#f4f4f6; border-radius:4px; color:#555; font-size:0.92em; line-height:1.5;">
					<?php
					printf(
						esc_html__( 'En signant, ces informations sont enregistrées et associées à ton compte : ton identifiant Cordespace, la version de ce document (v%s), la date et l\'heure de signature. Des métadonnées techniques sont également conservées dans nos journaux internes pour garantir la validité légale de ta signature. Tu peux consulter l\'historique de tes signatures dans la rubrique « Mes waivers » du menu « Mon compte » à tout moment.', 'cordespace-snippets' ),
						esc_html( $version )
					);
					?>
				</p>
			</form>

			<script>
			(function () {
				var form   = document.getElementById('cordespace-waiver-sign-form');
				if (!form) return;
				var checks = form.querySelectorAll('.cordespace-waiver-check');
				var btn    = form.querySelector('#cordespace-waiver-submit');
				if (!btn || !checks.length) return;
				function refresh() {
					var allChecked = true;
					checks.forEach(function (c) { if (!c.checked) allChecked = false; });
					btn.disabled = !allChecked;
					btn.style.opacity = allChecked ? '1' : '0.4';
					btn.style.cursor  = allChecked ? 'pointer' : 'not-allowed';
				}
				checks.forEach(function (c) { c.addEventListener('change', refresh); });
				refresh();
			})();
			</script>
		<?php endif; ?>
	</main>
	<?php
	get_footer();
}
