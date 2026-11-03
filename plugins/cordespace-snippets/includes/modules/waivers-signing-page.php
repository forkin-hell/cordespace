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
 * Identité : par défaut, display_name du compte WP connecté (LCJTI : identité authentifiée par le compte).
 * Deux modes sans compte : lien partenaire (module waivers.partners, &partner=&k=) et lien public
 * (&public=<jeton>, généré dans la fiche du waiver et partagé par lien ou code QR) : la personne saisit son nom
 * et son courriel ; la signature est rattachée au compte qui porte ce courriel, s'il existe, sinon elle est
 * reconnue par ce courriel (réservation Amelia, compte créé plus tard).
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

// ─── Lien public (code QR) : signature sans compte, nom + courriel saisis ──────

const CORDESPACE_WAIVER_META_PUBLIC_TOKEN = '_cordespace_waiver_public_token';

/** Jeton du lien public d'un waiver ('' si aucun lien n'est généré ou s'il a été désactivé). */
function cordespace_waivers_public_token( int $waiver_id ): string {
	$t = (string) get_post_meta( $waiver_id, CORDESPACE_WAIVER_META_PUBLIC_TOKEN, true );
	return preg_match( '/^[0-9a-f]{32}$/', $t ) === 1 ? $t : '';
}

/** Génère (ou remplace) le jeton : l'ancien lien et l'ancien code QR ne fonctionnent plus. */
function cordespace_waivers_public_token_generate( int $waiver_id ): string {
	$t = bin2hex( random_bytes( 16 ) );
	update_post_meta( $waiver_id, CORDESPACE_WAIVER_META_PUBLIC_TOKEN, $t );
	return $t;
}

function cordespace_waivers_public_token_disable( int $waiver_id ): void {
	delete_post_meta( $waiver_id, CORDESPACE_WAIVER_META_PUBLIC_TOKEN );
}

/** URL du lien public ('' si aucun lien) : la page de signature habituelle, avec public=<jeton>. */
function cordespace_waivers_get_public_sign_url( int $waiver_id ): string {
	$t = cordespace_waivers_public_token( $waiver_id );
	if ( $t === '' ) {
		return '';
	}
	return add_query_arg( [ 'cordespace_sign_waiver' => '1', 'waiver_id' => $waiver_id, 'public' => $t ], home_url( '/' ) );
}

/** 'none' (pas de paramètre public), 'live' (jeton en vigueur) ou 'dead' (jeton régénéré, désactivé ou inventé). */
function cordespace_waivers_public_link_state( array $query, int $waiver_id ): string {
	if ( ! isset( $query['public'] ) ) {
		return 'none';
	}
	$given = is_string( $query['public'] ) ? $query['public'] : '';
	$token = cordespace_waivers_public_token( $waiver_id );
	return $token !== '' && hash_equals( $token, $given ) ? 'live' : 'dead';
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
	if ( ! $waiver || $waiver->post_type !== CORDESPACE_WAIVER_POST_TYPE ) {
		return;
	}

	// Aperçu admin (&preview=1) : réservé aux personnes qui peuvent éditer le
	// waiver, accepte les brouillons, n'enregistre jamais rien.
	$preview = ! empty( $_GET['preview'] ) && is_user_logged_in() && current_user_can( 'edit_post', $waiver->ID );
	if ( ! $preview && $waiver->post_status !== 'publish' ) {
		return;
	}

	// Mode partenaire (lien signé reçu par courriel, module waivers.partners) : pas de compte requis.
	$partner = function_exists( 'cordespace_waivers_partner_from_request' ) ? cordespace_waivers_partner_from_request( $_GET ) : null;

	// Lien partenaire périmé vers une place qui existe (courriel changé, déclinée, séance passée) : page explicative,
	// connecté·e ou non (l'hôte qui clique l'ancien lien d'une partenaire ne doit pas signer à sa place).
	if ( $partner === null && function_exists( 'cordespace_waivers_partner_dead_link_requested' ) && cordespace_waivers_partner_dead_link_requested( $_GET ) ) {
		cordespace_waivers_render_partner_dead_link();
		cordespace_waivers_signing_exit();
		return;
	}

	// Lien public (code QR) : sans compte, la personne signe avec son nom et son courriel. Lien régénéré ou
	// désactivé : page explicative. Personne connectée sur un lien public valide : page de son compte.
	$public_state = $partner === null ? cordespace_waivers_public_link_state( $_GET, (int) $waiver->ID ) : 'none';
	if ( $public_state === 'dead' ) {
		cordespace_waivers_render_public_dead_link( $waiver );
		cordespace_waivers_signing_exit();
		return;
	}
	$public = $public_state === 'live' && ! is_user_logged_in();

	// Non connecté·e (ni lien partenaire ni lien public valide) → redirige vers login avec retour ici
	if ( ! is_user_logged_in() && $partner === null && ! $public ) {
		wp_safe_redirect( wp_login_url( home_url( add_query_arg( null, null ) ) ) );
		cordespace_waivers_signing_exit();
		return;
	}

	$redirect_to = isset( $_GET['redirect_to'] ) ? esc_url_raw( (string) $_GET['redirect_to'] ) : '';

	if ( ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) === 'POST' ) {
		if ( $preview ) {
			wp_safe_redirect( add_query_arg( null, null ) );
			cordespace_waivers_signing_exit();
			return;
		}
		if ( $partner !== null ) {
			if ( ! empty( $_POST['create_account'] ) ) {
				cordespace_waivers_signing_page_handle_account( $waiver, $partner );
				return;
			}
			cordespace_waivers_signing_page_handle_post_partner( $waiver, $partner );
			return;
		}
		if ( $public ) {
			cordespace_waivers_signing_page_handle_post_public( $waiver );
			return;
		}
		cordespace_waivers_signing_page_handle_post( (int) $waiver->ID, $redirect_to );
		return;
	}

	if ( $partner !== null && ! empty( $_GET['done'] ) ) {
		cordespace_waivers_render_partner_done( $waiver, $partner );
		cordespace_waivers_signing_exit();
		return;
	}
	if ( $public && ! empty( $_GET['done'] ) ) {
		cordespace_waivers_render_public_done( $waiver );
		cordespace_waivers_signing_exit();
		return;
	}

	cordespace_waivers_signing_page_render( $waiver, $redirect_to, $preview, $partner, $public );
	cordespace_waivers_signing_exit();
}

/** Fin de requête : exit en production, retour dans les tests (CS_TESTING). */
function cordespace_waivers_signing_exit(): void {
	if ( ! defined( 'CS_TESTING' ) ) {
		exit;
	}
}

/**
 * POST d'une personne partenaire (sans compte) : nonce, deux cases, nom saisi, signature rattachée
 * à la place, puis redirection vers la page « c'est signé ».
 */
function cordespace_waivers_signing_page_handle_post_partner( WP_Post $waiver, array $partner ): void {
	$waiver_id = (int) $waiver->ID;
	if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( (string) $_POST['_wpnonce'], 'cordespace_sign_waiver_' . $waiver_id ) ) {
		wp_die( esc_html__( 'Sécurité : nonce invalide ou expiré. Recharge la page et réessaie.', 'cordespace-snippets' ) );
	}
	if ( empty( $_POST['confirm_read'] ) || empty( $_POST['confirm_accept'] ) ) {
		wp_die( esc_html__( 'Tu dois cocher les deux cases pour signer le waiver.', 'cordespace-snippets' ) );
	}
	$name = isset( $_POST['signed_name'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['signed_name'] ) ) ) : '';
	if ( $name === '' ) {
		$name = trim( (string) $partner['partner_name'] );
	}
	if ( $name === '' ) {
		wp_die( esc_html__( 'Indique ton nom pour signer.', 'cordespace-snippets' ) );
	}
	$res = cordespace_waivers_sign_guest( (string) $partner['partner_email'], $waiver_id, $name, [
		'ip'                 => isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '',
		'user_agent'         => isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '',
		'invited_by_user_id' => (int) $partner['host_user_id'],
		'partner_id'         => (int) $partner['id'],
	] );
	if ( is_wp_error( $res ) ) {
		wp_die( esc_html( $res->get_error_message() ) );
	}
	// Signé : la relance 48 h (une par place) redevient disponible si une nouvelle version arrive plus tard.
	global $wpdb;
	if ( function_exists( 'cordespace_waivers_partners_table_name' ) ) {
		$wpdb->update( cordespace_waivers_partners_table_name(), [ 'reminded_at' => null ], [ 'id' => (int) $partner['id'] ] );
	}
	wp_safe_redirect( add_query_arg( 'done', '1', cordespace_waivers_partner_sign_url( $partner, $waiver_id ) ) );
	cordespace_waivers_signing_exit();
}

/**
 * POST du lien public (sans compte) : nonce, champ piège, deux cases, nom et courriel saisis. Signature sans
 * compte au courriel (rattachée au compte qui le porte, s'il existe) ; même courriel + même version = pas de
 * doublon. Puis redirection vers la page « c'est signé ».
 */
function cordespace_waivers_signing_page_handle_post_public( WP_Post $waiver ): void {
	$waiver_id = (int) $waiver->ID;
	$done_url  = add_query_arg( 'done', '1', cordespace_waivers_get_public_sign_url( $waiver_id ) );
	if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( (string) $_POST['_wpnonce'], 'cordespace_sign_waiver_' . $waiver_id ) ) {
		wp_die( esc_html__( 'Sécurité : nonce invalide ou expiré. Recharge la page et réessaie.', 'cordespace-snippets' ) );
	}
	// Champ piège (invisible) : un robot le remplit ; on fait comme si c'était signé, sans rien enregistrer.
	if ( ! empty( $_POST['cordespace_site'] ) ) {
		wp_safe_redirect( $done_url );
		cordespace_waivers_signing_exit();
		return;
	}
	if ( empty( $_POST['confirm_read'] ) || empty( $_POST['confirm_accept'] ) ) {
		wp_die( esc_html__( 'Tu dois cocher les deux cases pour signer le waiver.', 'cordespace-snippets' ) );
	}
	$name  = isset( $_POST['signed_name'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['signed_name'] ) ) ) : '';
	$email = isset( $_POST['signer_email'] ) ? cordespace_waivers_normalize_email( sanitize_text_field( wp_unslash( $_POST['signer_email'] ) ) ) : '';
	if ( $name === '' ) {
		wp_die( esc_html__( 'Indique ton nom pour signer.', 'cordespace-snippets' ) );
	}
	if ( ! is_email( $email ) ) {
		wp_die( esc_html__( 'Indique un courriel valide : celui de ton compte Cordespace ou de ta réservation, pour que ta signature soit reconnue.', 'cordespace-snippets' ) );
	}
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
	if ( ! cordespace_waivers_email_has_signed_current( $email, $waiver_id ) ) {
		if ( cordespace_waivers_public_sign_capped( $ip ) ) {
			wp_die( esc_html__( 'Trop de signatures depuis cette connexion pour le moment. Réessaie dans une heure, ou signe depuis ton compte Cordespace (Mon espace → Mes waivers).', 'cordespace-snippets' ) );
		}
		$res = cordespace_waivers_sign_guest( $email, $waiver_id, $name, [
			'ip'         => $ip,
			'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '',
			'source'     => 'public',
		] );
		if ( is_wp_error( $res ) ) {
			wp_die( esc_html( $res->get_error_message() ) );
		}
	}
	wp_safe_redirect( $done_url );
	cordespace_waivers_signing_exit();
}

/**
 * Garde-fou du lien public : au plus 60 signatures enregistrées par heure et par adresse IP. Une classe entière
 * qui scanne le QR sur le Wi-Fi du local passe ; un robot qui invente des courriels, non. Les re-signatures
 * d'un courriel déjà en règle ne comptent pas (elles n'enregistrent rien).
 */
function cordespace_waivers_public_sign_capped( string $ip ): bool {
	if ( $ip === '' ) {
		return false;
	}
	$key = 'cordespace_pubsign_' . md5( $ip );
	$n   = (int) get_transient( $key );
	if ( $n >= 60 ) {
		return true;
	}
	set_transient( $key, $n + 1, 3600 );
	return false;
}

/** Page « c'est signé » du lien public : pas de nom ni de courriel dans l'URL, pas de création de compte. */
function cordespace_waivers_render_public_done( WP_Post $waiver ): void {
	status_header( 200 );
	nocache_headers();
	get_header();
	?>
	<main class="cordespace-sign-waiver" style="max-width:760px; margin:2rem auto 3rem; padding:0 1rem;">
		<section style="padding:1.2rem 1.5rem; background:#eef5ee; border-left:4px solid #3a7a3a; margin:1rem 0; border-radius:0 4px 4px 0;">
			<strong><?php esc_html_e( 'Merci, c\'est signé.', 'cordespace-snippets' ); ?></strong>
			<p style="margin:0.4rem 0 0; color:#555;"><?php echo esc_html( sprintf( __( 'Ta signature du document « %s » est enregistrée au courriel que tu as indiqué. Si un compte Cordespace porte ce courriel, elle y est rattachée ; sinon elle sera reconnue quand tu réserveras avec ce courriel. Tu peux fermer cette page.', 'cordespace-snippets' ), get_the_title( $waiver ) ) ); ?></p>
		</section>
	</main>
	<?php
	get_footer();
}

/** Lien public régénéré, désactivé ou inventé : page explicative, connecté·e ou non. */
function cordespace_waivers_render_public_dead_link( WP_Post $waiver ): void {
	status_header( 200 );
	nocache_headers();
	get_header();
	?>
	<main class="cordespace-sign-waiver" style="max-width:760px; margin:2rem auto 3rem; padding:0 1rem;">
		<section style="padding:1.2rem 1.5rem; background:#fff8e1; border-left:4px solid #d97706; margin:1rem 0; border-radius:0 4px 4px 0;">
			<strong><?php esc_html_e( 'Ce lien n\'est plus valide.', 'cordespace-snippets' ); ?></strong>
			<p style="margin:0.4rem 0 0; color:#555;"><?php echo esc_html( sprintf( __( 'Le lien ou le code QR du document « %s » a été remplacé. Demande le nouveau code QR au comptoir, ou connecte-toi à ton compte Cordespace pour signer depuis Mon espace.', 'cordespace-snippets' ), get_the_title( $waiver ) ) ); ?></p>
		</section>
	</main>
	<?php
	get_footer();
}

/** Bouton « Créer mon compte » de la page « c'est signé ». */
function cordespace_waivers_signing_page_handle_account( WP_Post $waiver, array $partner ): void {
	if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( (string) $_POST['_wpnonce'], 'cordespace_partner_account_' . (int) $partner['id'] ) ) {
		wp_die( esc_html__( 'Sécurité : nonce invalide ou expiré. Recharge la page et réessaie.', 'cordespace-snippets' ) );
	}
	$email = (string) $partner['partner_email'];
	$base  = cordespace_waivers_partner_sign_url( $partner, (int) $waiver->ID );
	// Un compte seulement après la signature, et jamais en double.
	if ( ! cordespace_waivers_email_has_signed_current( $email, (int) $waiver->ID ) ) {
		wp_safe_redirect( add_query_arg( [ 'done' => '1', 'account' => 'error' ], $base ) );
		cordespace_waivers_signing_exit();
		return;
	}
	if ( email_exists( $email ) ) {
		wp_safe_redirect( add_query_arg( [ 'done' => '1', 'account' => 'exists' ], $base ) );
		cordespace_waivers_signing_exit();
		return;
	}
	$last = function_exists( 'cordespace_waivers_partner_last_signature' ) ? cordespace_waivers_partner_last_signature( $email, (int) $waiver->ID ) : null;
	$res  = cordespace_waivers_partner_create_account( $partner, (string) ( $last['signed_name'] ?? '' ) );
	$url  = add_query_arg( [ 'done' => '1', 'account' => is_wp_error( $res ) ? 'error' : 'created' ], $base );
	wp_safe_redirect( $url );
	cordespace_waivers_signing_exit();
}

/** Page « c'est signé » d'une personne partenaire, avec le bouton facultatif « Créer mon compte ». */
function cordespace_waivers_render_partner_done( WP_Post $waiver, array $partner ): void {
	$first    = function_exists( 'cordespace_waivers_first_name' ) ? cordespace_waivers_first_name( (string) $partner['partner_name'], '' ) : '';
	$state    = isset( $_GET['account'] ) ? sanitize_text_field( wp_unslash( $_GET['account'] ) ) : '';
	$has_user = (bool) email_exists( (string) $partner['partner_email'] );
	status_header( 200 );
	nocache_headers();
	get_header();
	?>
	<main class="cordespace-sign-waiver" style="max-width:760px; margin:2rem auto 3rem; padding:0 1rem;">
		<section style="padding:1.2rem 1.5rem; background:#eef5ee; border-left:4px solid #3a7a3a; margin:1rem 0; border-radius:0 4px 4px 0;">
			<strong><?php echo esc_html( $first !== '' ? sprintf( __( 'Merci %s, c\'est signé.', 'cordespace-snippets' ), $first ) : __( 'Merci, c\'est signé.', 'cordespace-snippets' ) ); ?></strong>
			<p style="margin:0.4rem 0 0; color:#555;"><?php echo esc_html( sprintf( __( 'Ta signature du document « %s » est enregistrée. Tu peux fermer cette page.', 'cordespace-snippets' ), get_the_title( $waiver ) ) ); ?></p>
		</section>
		<?php if ( $state === 'created' ) : ?>
			<p style="padding:0.9rem 1.1rem; background:#e8f0fe; border-left:4px solid #1a73e8; border-radius:0 4px 4px 0;"><?php esc_html_e( 'Ton compte est créé : un courriel te permet de choisir ton mot de passe.', 'cordespace-snippets' ); ?></p>
		<?php elseif ( $state === 'error' ) : ?>
			<p style="padding:0.9rem 1.1rem; background:#fdecea; border-left:4px solid #b32d2e; border-radius:0 4px 4px 0;"><?php esc_html_e( 'Le compte n\'a pas pu être créé. Écris-nous et on s\'en occupe.', 'cordespace-snippets' ); ?></p>
		<?php elseif ( $has_user ) : ?>
			<p style="color:#555;"><?php esc_html_e( 'Un compte Cordespace existe déjà avec ton courriel : ta signature y est rattachée.', 'cordespace-snippets' ); ?></p>
		<?php else : ?>
			<form method="post" style="margin-top:1rem; padding:1rem 1.2rem; background:#f4f4f6; border-radius:4px;">
				<?php wp_nonce_field( 'cordespace_partner_account_' . (int) $partner['id'] ); ?>
				<p style="margin:0 0 0.6rem; color:#444;"><?php esc_html_e( 'Facultatif : crée ton compte Cordespace avec ce courriel pour retrouver tes documents signés et réserver toi-même la prochaine fois.', 'cordespace-snippets' ); ?></p>
				<button type="submit" name="create_account" value="1" style="padding:0.6rem 1.2rem; background:#1a1a2e; color:#fff; border:none; border-radius:4px; cursor:pointer; font-weight:600;"><?php esc_html_e( 'Créer mon compte', 'cordespace-snippets' ); ?></button>
			</form>
		<?php endif; ?>
	</main>
	<?php
	get_footer();
}

// ============================================================================
// Phrases des deux cases à cocher — modifiables par waiver (metabox), avec
// les phrases d'origine comme défaut. Deux affirmations distinctes (lecture /
// acceptation) : c'est la forme retenue pour le consentement explicite (LCJTI).
// ============================================================================

const CORDESPACE_WAIVER_META_CONSENT_READ           = '_cordespace_waiver_consent_read';
const CORDESPACE_WAIVER_META_CONSENT_ACCEPT         = '_cordespace_waiver_consent_accept';
const CORDESPACE_WAIVER_META_CONSENT_NOTICE         = '_cordespace_waiver_consent_notice';
const CORDESPACE_WAIVER_META_CONSENT_NOTICE_PARTNER = '_cordespace_waiver_consent_notice_partner';
const CORDESPACE_WAIVER_META_CONSENT_NOTICE_PUBLIC  = '_cordespace_waiver_consent_notice_public';

/**
 * Phrases des cases et mention sous les cases (personne avec compte / partenaire sans compte / lien public).
 * Mots-clés des mentions : {version} (ex. v1.3), {courriel} (partenaire ; lien public : « l'adresse indiquée ci-dessus »).
 *
 * @return array{read:string, accept:string, notice:string, notice_partner:string, notice_public:string}
 */
function cordespace_waivers_default_consent_texts(): array {
	return [
		'read'           => __( "J'ai lu et compris l'intégralité du texte ci-dessus.", 'cordespace-snippets' ),
		'accept'         => __( "J'accepte expressément le contenu de ce document et j'adhère à toutes ses conditions.", 'cordespace-snippets' ),
		'notice'         => __( 'En signant, vous acceptez que ces informations soient enregistrées et associées à votre compte Cordespace : votre identifiant utilisateur, la version de cette décharge ({version}), la date et l\'heure de signature, votre adresse IP, votre navigateur. Vous pouvez à tout moment consulter votre historique de documents signés dans votre espace personnel.', 'cordespace-snippets' ),
		'notice_partner' => __( 'En signant, vous acceptez que ces informations soient enregistrées : votre nom, votre adresse courriel ({courriel}), la version de cette décharge ({version}), la date et l\'heure de signature, votre adresse IP, votre navigateur, et la personne qui vous a invité·e.', 'cordespace-snippets' ),
		'notice_public'  => __( 'En signant, vous acceptez que ces informations soient enregistrées : votre nom, votre adresse courriel, la version de cette décharge ({version}), la date et l\'heure de signature, votre adresse IP, votre navigateur. Si un compte Cordespace porte ce courriel, la signature y est rattachée et vous pouvez la consulter dans votre espace personnel.', 'cordespace-snippets' ),
	];
}

/**
 * Phrases des cases pour un waiver : meta si renseignée, sinon défaut.
 * Filtre cordespace_waivers_consent_texts( array $texts, int $waiver_id ).
 *
 * @return array{read:string, accept:string}
 */
function cordespace_waivers_get_consent_texts( int $waiver_id ): array {
	$texts = cordespace_waivers_default_consent_texts();
	$metas = [
		'read'           => CORDESPACE_WAIVER_META_CONSENT_READ,
		'accept'         => CORDESPACE_WAIVER_META_CONSENT_ACCEPT,
		'notice'         => CORDESPACE_WAIVER_META_CONSENT_NOTICE,
		'notice_partner' => CORDESPACE_WAIVER_META_CONSENT_NOTICE_PARTNER,
		'notice_public'  => CORDESPACE_WAIVER_META_CONSENT_NOTICE_PUBLIC,
	];
	foreach ( $metas as $key => $meta ) {
		$value = trim( (string) get_post_meta( $waiver_id, $meta, true ) );
		if ( $value !== '' ) {
			$texts[ $key ] = $value;
		}
	}
	return (array) apply_filters( 'cordespace_waivers_consent_texts', $texts, $waiver_id );
}

/**
 * Metaboxes sur l'écran d'édition du waiver : « Cases à cocher » (normal) et
 * « Aperçu » (side, bouton vers la page de signature en mode preview).
 */
function cordespace_waivers_signing_page_register_metaboxes(): void {
	add_meta_box(
		'cordespace-waiver-consent',
		__( '☑️ Cases à cocher (signature)', 'cordespace-snippets' ),
		'cordespace_waivers_render_consent_metabox',
		CORDESPACE_WAIVER_POST_TYPE,
		'normal',
		'high'
	);
	add_meta_box(
		'cordespace-waiver-preview',
		__( '👁️ Aperçu', 'cordespace-snippets' ),
		'cordespace_waivers_render_preview_metabox',
		CORDESPACE_WAIVER_POST_TYPE,
		'side',
		'high'
	);
	add_meta_box(
		'cordespace-waiver-public-link',
		__( '🔗 Lien public et code QR', 'cordespace-snippets' ),
		'cordespace_waivers_render_public_link_metabox',
		CORDESPACE_WAIVER_POST_TYPE,
		'side',
		'default'
	);
}
add_action( 'add_meta_boxes_' . CORDESPACE_WAIVER_POST_TYPE, 'cordespace_waivers_signing_page_register_metaboxes' );

function cordespace_waivers_render_consent_metabox( WP_Post $post ): void {
	wp_nonce_field( 'cordespace_waiver_consent_save', 'cordespace_waiver_consent_nonce' );
	$defaults = cordespace_waivers_default_consent_texts();
	$read     = (string) get_post_meta( $post->ID, CORDESPACE_WAIVER_META_CONSENT_READ, true );
	$accept   = (string) get_post_meta( $post->ID, CORDESPACE_WAIVER_META_CONSENT_ACCEPT, true );
	$notice   = (string) get_post_meta( $post->ID, CORDESPACE_WAIVER_META_CONSENT_NOTICE, true );
	$notice_p = (string) get_post_meta( $post->ID, CORDESPACE_WAIVER_META_CONSENT_NOTICE_PARTNER, true );
	$notice_q = (string) get_post_meta( $post->ID, CORDESPACE_WAIVER_META_CONSENT_NOTICE_PUBLIC, true );
	?>
	<p style="margin-top:0; color:#555;">
		<?php esc_html_e( 'Sous le texte du document, la personne doit cocher ces deux cases avant de pouvoir cliquer « Je signe ». Laisse vide pour garder la phrase par défaut.', 'cordespace-snippets' ); ?>
	</p>
	<p>
		<label for="cordespace_waiver_consent_read"><strong><?php esc_html_e( 'Case 1 — lecture', 'cordespace-snippets' ); ?></strong></label><br>
		<input type="text" id="cordespace_waiver_consent_read" name="cordespace_waiver_consent_read" class="widefat" value="<?php echo esc_attr( $read ); ?>" placeholder="<?php echo esc_attr( $defaults['read'] ); ?>">
	</p>
	<p>
		<label for="cordespace_waiver_consent_accept"><strong><?php esc_html_e( 'Case 2 — acceptation', 'cordespace-snippets' ); ?></strong></label><br>
		<input type="text" id="cordespace_waiver_consent_accept" name="cordespace_waiver_consent_accept" class="widefat" value="<?php echo esc_attr( $accept ); ?>" placeholder="<?php echo esc_attr( $defaults['accept'] ); ?>">
	</p>
	<p style="font-size:0.9em; color:#666;">
		<?php esc_html_e( 'Garde deux affirmations distinctes (avoir lu / accepter) : c\'est la forme retenue pour un consentement explicite. Si tu changes le sens d\'une phrase, pense à changer aussi la version du document pour que tout le monde re-signe.', 'cordespace-snippets' ); ?>
	</p>
	<hr>
	<p>
		<label for="cordespace_waiver_consent_notice"><strong><?php esc_html_e( 'Mention sous les cases — personne avec compte', 'cordespace-snippets' ); ?></strong></label><br>
		<textarea id="cordespace_waiver_consent_notice" name="cordespace_waiver_consent_notice" class="widefat" rows="4" placeholder="<?php echo esc_attr( $defaults['notice'] ); ?>"><?php echo esc_textarea( $notice ); ?></textarea>
	</p>
	<p>
		<label for="cordespace_waiver_consent_notice_partner"><strong><?php esc_html_e( 'Mention sous les cases — partenaire sans compte', 'cordespace-snippets' ); ?></strong></label><br>
		<textarea id="cordespace_waiver_consent_notice_partner" name="cordespace_waiver_consent_notice_partner" class="widefat" rows="4" placeholder="<?php echo esc_attr( $defaults['notice_partner'] ); ?>"><?php echo esc_textarea( $notice_p ); ?></textarea>
	</p>
	<p>
		<label for="cordespace_waiver_consent_notice_public"><strong><?php esc_html_e( 'Mention sous les cases — lien public (code QR), sans compte', 'cordespace-snippets' ); ?></strong></label><br>
		<textarea id="cordespace_waiver_consent_notice_public" name="cordespace_waiver_consent_notice_public" class="widefat" rows="4" placeholder="<?php echo esc_attr( $defaults['notice_public'] ); ?>"><?php echo esc_textarea( $notice_q ); ?></textarea>
	</p>
	<p style="font-size:0.9em; color:#666; margin-bottom:0;">
		<?php esc_html_e( 'Ces mentions s\'affichent sous les deux cases, avant le bouton « Je signe ». Laisse vide pour garder le texte par défaut. Mots-clés : {version} = version en vigueur (ex. v1.3), {courriel} = courriel de la personne partenaire (lien public : « l\'adresse indiquée ci-dessus »).', 'cordespace-snippets' ); ?>
	</p>
	<?php
}

function cordespace_waivers_render_preview_metabox( WP_Post $post ): void {
	if ( $post->post_status === 'auto-draft' ) {
		echo '<p style="margin:0; color:#666;">' . esc_html__( 'Enregistre d\'abord le brouillon pour pouvoir le prévisualiser.', 'cordespace-snippets' ) . '</p>';
		return;
	}
	$url = add_query_arg( 'preview', '1', cordespace_waivers_get_sign_url( (int) $post->ID ) );
	?>
	<p style="margin:0 0 0.6rem;">
		<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener" class="button button-secondary" style="width:100%; text-align:center;">
			<?php esc_html_e( '👁️ Prévisualiser la page de signature', 'cordespace-snippets' ); ?>
		</a>
	</p>
	<p style="margin:0; font-size:0.9em; color:#666;">
		<?php esc_html_e( 'Ouvre la page telle que la verra la personne (texte, cases, bouton), sans rien enregistrer. Pense à enregistrer tes modifications avant.', 'cordespace-snippets' ); ?>
	</p>
	<?php
}

function cordespace_waivers_save_consent_meta( int $post_id ): void {
	if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
		return;
	}
	if ( get_post_type( $post_id ) !== CORDESPACE_WAIVER_POST_TYPE || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	if ( ! isset( $_POST['cordespace_waiver_consent_nonce'] )
	     || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cordespace_waiver_consent_nonce'] ) ), 'cordespace_waiver_consent_save' ) ) {
		return;
	}
	$fields = [
		'cordespace_waiver_consent_read'           => CORDESPACE_WAIVER_META_CONSENT_READ,
		'cordespace_waiver_consent_accept'         => CORDESPACE_WAIVER_META_CONSENT_ACCEPT,
		'cordespace_waiver_consent_notice'         => CORDESPACE_WAIVER_META_CONSENT_NOTICE,
		'cordespace_waiver_consent_notice_partner' => CORDESPACE_WAIVER_META_CONSENT_NOTICE_PARTNER,
		'cordespace_waiver_consent_notice_public'  => CORDESPACE_WAIVER_META_CONSENT_NOTICE_PUBLIC,
	];
	foreach ( $fields as $field => $meta ) {
		$raw   = isset( $_POST[ $field ] ) ? wp_unslash( $_POST[ $field ] ) : '';
		$value = str_starts_with( $field, 'cordespace_waiver_consent_notice' ) ? sanitize_textarea_field( $raw ) : sanitize_text_field( $raw );
		if ( $value === '' ) {
			delete_post_meta( $post_id, $meta );
		} else {
			update_post_meta( $post_id, $meta, $value );
		}
	}
}
add_action( 'save_post', 'cordespace_waivers_save_consent_meta' );
add_action( 'wp_loaded', 'cordespace_waivers_signing_page_handle', 20 );

// ============================================================================
// Boîte « Lien public et code QR » (fiche du waiver, colonne latérale) : générer / régénérer / désactiver
// le lien, le copier, afficher et télécharger le code QR (dessiné dans le navigateur, bibliothèque embarquée).
// ============================================================================

function cordespace_waivers_render_public_link_metabox( WP_Post $post ): void {
	if ( $post->post_status === 'auto-draft' ) {
		echo '<p style="margin:0; color:#666;">' . esc_html__( 'Enregistre d\'abord le brouillon pour pouvoir générer le lien.', 'cordespace-snippets' ) . '</p>';
		return;
	}
	wp_nonce_field( 'cordespace_waiver_public_link', 'cordespace_waiver_public_link_nonce' );
	$url = cordespace_waivers_get_public_sign_url( (int) $post->ID );
	if ( $url === '' ) {
		?>
		<p style="margin:0 0 0.8rem; color:#555;"><?php esc_html_e( 'Un lien à partager (affiche, courriel, code QR au comptoir) : la personne signe sans compte, avec son nom et son courriel. Si un compte Cordespace porte ce courriel, la signature y est rattachée ; sinon elle est reconnue par le courriel de sa réservation.', 'cordespace-snippets' ); ?></p>
		<p style="margin:0;"><button type="submit" name="cordespace_waiver_public_link_action" value="generate" class="button button-secondary" style="width:100%; text-align:center;"><?php esc_html_e( '🔗 Générer le lien', 'cordespace-snippets' ); ?></button></p>
		<?php
		return;
	}
	?>
	<p style="margin:0 0 0.5rem;">
		<input type="text" id="cordespace-waiver-public-url" class="widefat" readonly value="<?php echo esc_attr( $url ); ?>" onclick="this.select();" style="font-size:0.85em;">
	</p>
	<p style="margin:0 0 0.8rem;"><button type="button" id="cordespace-waiver-public-copy" class="button" style="width:100%; text-align:center;"><?php esc_html_e( 'Copier le lien', 'cordespace-snippets' ); ?></button></p>
	<div id="cordespace-waiver-qr" data-url="<?php echo esc_attr( $url ); ?>" style="text-align:center; margin:0 0 0.5rem;"></div>
	<p style="margin:0 0 0.8rem; text-align:center;"><a href="#" id="cordespace-waiver-qr-download" class="button button-primary" download="code-qr-waiver-<?php echo (int) $post->ID; ?>.png"><?php esc_html_e( 'Télécharger le code QR (PNG)', 'cordespace-snippets' ); ?></a></p>
	<?php if ( $post->post_status !== 'publish' ) : ?>
		<p style="margin:0 0 0.8rem; color:#8a5a00;"><?php esc_html_e( 'Le lien ne fonctionnera qu\'une fois le waiver publié.', 'cordespace-snippets' ); ?></p>
	<?php endif; ?>
	<p style="margin:0 0 0.8rem; font-size:0.9em; color:#666;"><?php esc_html_e( 'Le code QR mène à ce lien. « Régénérer » rend l\'ancien lien et l\'ancien code QR inutilisables (page « ce lien n\'est plus valide ») ; « Désactiver » aussi, sans nouveau lien. Les signatures déjà faites restent.', 'cordespace-snippets' ); ?></p>
	<p style="margin:0; display:flex; gap:0.4rem;">
		<button type="submit" name="cordespace_waiver_public_link_action" value="regenerate" class="button" style="flex:1;" onclick="return confirm('<?php echo esc_js( __( 'Régénérer le lien ? L\'ancien lien et l\'ancien code QR ne fonctionneront plus.', 'cordespace-snippets' ) ); ?>');"><?php esc_html_e( 'Régénérer', 'cordespace-snippets' ); ?></button>
		<button type="submit" name="cordespace_waiver_public_link_action" value="disable" class="button" style="flex:1;" onclick="return confirm('<?php echo esc_js( __( 'Désactiver le lien public ? Le lien et le code QR ne fonctionneront plus.', 'cordespace-snippets' ) ); ?>');"><?php esc_html_e( 'Désactiver', 'cordespace-snippets' ); ?></button>
	</p>
	<?php
}

/** Les boutons de la boîte envoient le formulaire de la fiche : on agit à l'enregistrement, avec son nonce. */
function cordespace_waivers_save_public_link_meta( int $post_id ): void {
	if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
		return;
	}
	if ( get_post_type( $post_id ) !== CORDESPACE_WAIVER_POST_TYPE || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	if ( ! isset( $_POST['cordespace_waiver_public_link_nonce'] )
	     || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cordespace_waiver_public_link_nonce'] ) ), 'cordespace_waiver_public_link' ) ) {
		return;
	}
	$action = isset( $_POST['cordespace_waiver_public_link_action'] ) ? sanitize_text_field( wp_unslash( $_POST['cordespace_waiver_public_link_action'] ) ) : '';
	if ( $action === 'generate' && cordespace_waivers_public_token( $post_id ) === '' ) {
		cordespace_waivers_public_token_generate( $post_id );
	} elseif ( $action === 'regenerate' ) {
		cordespace_waivers_public_token_generate( $post_id );
	} elseif ( $action === 'disable' ) {
		cordespace_waivers_public_token_disable( $post_id );
	}
}
add_action( 'save_post', 'cordespace_waivers_save_public_link_meta' );

/** Écran d'édition d'un waiver seulement : bibliothèque QR embarquée (MIT) + dessin, copie et téléchargement. */
function cordespace_waivers_public_link_admin_scripts( string $hook ): void {
	if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) || ! defined( 'CORDESPACE_SNIPPETS_URL' ) ) {
		return;
	}
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || (string) ( $screen->post_type ?? '' ) !== CORDESPACE_WAIVER_POST_TYPE ) {
		return;
	}
	wp_enqueue_script( 'cordespace-qrcode-generator', CORDESPACE_SNIPPETS_URL . 'assets/js/qrcode-generator.js', [], '1.4.4', true );
	wp_add_inline_script( 'cordespace-qrcode-generator', cordespace_waivers_public_link_inline_js() );
}
add_action( 'admin_enqueue_scripts', 'cordespace_waivers_public_link_admin_scripts' );

function cordespace_waivers_public_link_inline_js(): string {
	return <<<'JS'
(function () {
	var box = document.getElementById('cordespace-waiver-qr');
	var url = box ? box.getAttribute('data-url') : '';
	function draw(cell, margin) {
		var qr = qrcode(0, 'M');
		qr.addData(url);
		qr.make();
		var n = qr.getModuleCount(), size = (n + margin * 2) * cell;
		var c = document.createElement('canvas');
		c.width = size; c.height = size;
		var ctx = c.getContext('2d');
		ctx.fillStyle = '#ffffff'; ctx.fillRect(0, 0, size, size);
		ctx.fillStyle = '#000000';
		for (var r = 0; r < n; r++) { for (var col = 0; col < n; col++) { if (qr.isDark(r, col)) { ctx.fillRect((col + margin) * cell, (r + margin) * cell, cell, cell); } } }
		return c;
	}
	if (box && url && typeof qrcode === 'function') {
		var preview = draw(5, 2);
		preview.style.maxWidth = '100%'; preview.style.height = 'auto';
		box.appendChild(preview);
		var dl = document.getElementById('cordespace-waiver-qr-download');
		if (dl) { dl.href = draw(24, 4).toDataURL('image/png'); }
	}
	var copy = document.getElementById('cordespace-waiver-public-copy');
	var input = document.getElementById('cordespace-waiver-public-url');
	if (copy && input) {
		copy.addEventListener('click', function () {
			input.select();
			var done = function () { copy.textContent = 'Lien copié ✓'; };
			var fallback = function () { try { document.execCommand('copy'); done(); } catch (e) {} };
			if (navigator.clipboard && navigator.clipboard.writeText) { navigator.clipboard.writeText(input.value).then(done, fallback); } else { fallback(); }
		});
	}
})();
JS;
}

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
	cordespace_waivers_signing_exit();
}

/**
 * Rend la page de signature (form ou "déjà signé").
 */
function cordespace_waivers_signing_page_render( WP_Post $waiver, string $redirect_to, bool $preview = false, ?array $partner = null, bool $public = false ): void {
	$consent      = cordespace_waivers_get_consent_texts( (int) $waiver->ID );
	$user         = wp_get_current_user();
	if ( $partner !== null ) {
		// Mode partenaire : la personne signe en son nom, sans compte, identifiée par son courriel.
		$default_name = (string) $partner['partner_name'];
		$already      = cordespace_waivers_email_has_signed_current( (string) $partner['partner_email'], (int) $waiver->ID );
		$last         = $already && function_exists( 'cordespace_waivers_partner_last_signature' ) ? cordespace_waivers_partner_last_signature( (string) $partner['partner_email'], (int) $waiver->ID ) : null;
		$last_sig     = $last ? [ $last ] : [];
		$host_first   = function_exists( 'cordespace_waivers_partner_host_first_name' ) ? cordespace_waivers_partner_host_first_name( $partner ) : '';
	} elseif ( $public ) {
		// Lien public (code QR) : sans compte, nom et courriel saisis ; on ne sait pas encore qui signe.
		$default_name = '';
		$already      = false;
		$last_sig     = [];
		$host_first   = '';
	} else {
		$default_name = $user->display_name ?: trim( $user->first_name . ' ' . $user->last_name );
		$already      = cordespace_waivers_has_signed_current( (int) $user->ID, (int) $waiver->ID );
		$last_sig     = $already ? cordespace_waivers_history_for_user_and_waiver( (int) $user->ID, (int) $waiver->ID, 1 ) : [];
		if ( $already && ! $last_sig && function_exists( 'cordespace_waivers_partner_last_signature' ) ) {
			// Signature faite sans compte (partenaire), rattachée ensuite au compte par le courriel.
			$row      = cordespace_waivers_partner_last_signature( (string) $user->user_email, (int) $waiver->ID );
			$last_sig = $row ? [ $row ] : [];
		}
		$host_first   = '';
	}
	$back         = $redirect_to !== ''
		? $redirect_to
		: ( $partner === null && ! $public && function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'myaccount' ) : home_url( '/' ) ); // sans compte (partenaire, lien public) : pas vers Mon compte
	$version      = cordespace_waivers_get_version( (int) $waiver->ID );

	status_header( 200 );
	nocache_headers();
	get_header();
	?>
	<main class="cordespace-sign-waiver" style="max-width:760px; margin:2rem auto 3rem; padding:0 1rem;">
		<?php if ( $preview ) : ?>
			<p style="padding:0.8rem 1.1rem; background:#e8f0fe; border-left:4px solid #1a73e8; border-radius:0 4px 4px 0; color:#174ea6; font-weight:600;">
				👁️ <?php esc_html_e( 'Aperçu — c\'est ce que verra la personne. Rien n\'est enregistré depuis cette page.', 'cordespace-snippets' ); ?>
				<?php if ( $waiver->post_status !== 'publish' ) : ?>
					<span style="font-weight:400;">(<?php esc_html_e( 'brouillon non publié', 'cordespace-snippets' ); ?>)</span>
				<?php endif; ?>
			</p>
		<?php endif; ?>
		<h1 style="margin-bottom:0.4rem;"><?php echo esc_html( get_the_title( $waiver ) ); ?></h1>
		<p style="color:#666; margin-top:0;">
			<?php printf( esc_html__( 'Version %s', 'cordespace-snippets' ), esc_html( $version ) ); ?>
			&nbsp;·&nbsp;
			<?php if ( $partner !== null ) :
				printf( esc_html__( 'Tu signes en ton nom, invité·e par %s', 'cordespace-snippets' ), esc_html( $host_first ) );
			elseif ( $public ) :
				esc_html_e( 'Tu signes en ton nom, avec ton courriel', 'cordespace-snippets' );
			else :
				printf( esc_html__( 'Signataire : %s', 'cordespace-snippets' ), esc_html( $default_name ) );
			endif; ?>
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

		<?php if ( $already && ! $preview ) : ?>
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

				<?php if ( $partner !== null || $public ) : ?>
					<p style="margin:0 0 0.8rem;">
						<label for="cordespace-waiver-signed-name"><strong><?php esc_html_e( 'Ton nom complet', 'cordespace-snippets' ); ?></strong></label><br>
						<input type="text" id="cordespace-waiver-signed-name" name="signed_name" value="<?php echo esc_attr( $default_name ); ?>" required autocomplete="name" style="width:100%; max-width:420px; padding:0.6rem 0.8rem; border:1px solid #bbb; border-radius:4px; font-size:1em;">
					</p>
				<?php endif; ?>
				<?php if ( $public ) : ?>
					<p style="margin:0 0 0.8rem;">
						<label for="cordespace-waiver-signer-email"><strong><?php esc_html_e( 'Ton courriel', 'cordespace-snippets' ); ?></strong></label><br>
						<input type="email" id="cordespace-waiver-signer-email" name="signer_email" value="" required autocomplete="email" inputmode="email" style="width:100%; max-width:420px; padding:0.6rem 0.8rem; border:1px solid #bbb; border-radius:4px; font-size:1em;">
						<span style="display:block; color:#666; font-size:0.88em; margin-top:0.3rem;"><?php esc_html_e( 'Celui de ton compte Cordespace ou de ta réservation : c\'est lui qui relie ta signature à ta place dans le cours.', 'cordespace-snippets' ); ?></span>
					</p>
					<p style="position:absolute; left:-9999px; width:1px; height:1px; overflow:hidden;" aria-hidden="true"><label>Site web <input type="text" name="cordespace_site" value="" tabindex="-1" autocomplete="off"></label></p>
				<?php endif; ?>
				<p style="margin-bottom:0.6rem;"><strong><?php esc_html_e( 'Pour signer électroniquement ce document, coche les deux cases ci-dessous :', 'cordespace-snippets' ); ?></strong></p>

				<p style="padding:0.9rem 1.1rem; background:#fff8e1; border-left:4px solid #d97706; margin:0.6rem 0; border-radius:0 4px 4px 0;">
					<label style="cursor:pointer;">
						<input type="checkbox" name="confirm_read" value="1" class="cordespace-waiver-check" required>
						<?php echo esc_html( $consent['read'] ); ?>
					</label>
				</p>

				<p style="padding:0.9rem 1.1rem; background:#fff8e1; border-left:4px solid #d97706; margin:0.6rem 0 1.2rem; border-radius:0 4px 4px 0;">
					<label style="cursor:pointer;">
						<input type="checkbox" name="confirm_accept" value="1" class="cordespace-waiver-check" required>
						<?php echo esc_html( $consent['accept'] ); ?>
					</label>
				</p>

				<?php
				// Mention légale sous les cases (modifiable par waiver, metabox « Cases à cocher »).
				$notice = (string) ( $partner !== null ? $consent['notice_partner'] : ( $public ? $consent['notice_public'] : $consent['notice'] ) );
				$notice = strtr( $notice, [ '{version}' => 'v' . $version, '{courriel}' => $partner !== null ? (string) ( $partner['partner_email'] ?? '' ) : __( 'l\'adresse indiquée ci-dessus', 'cordespace-snippets' ) ] );
				?>
				<p style="margin:0.6rem 0 1.2rem; padding:0.9rem 1.1rem; background:#f4f4f6; border-radius:4px; color:#555; font-size:0.9em; line-height:1.5;">
					<?php echo nl2br( esc_html( $notice ) ); ?>
				</p>

				<p style="margin:1.2rem 0;">
					<button type="submit" id="cordespace-waiver-submit" disabled data-preview="<?php echo $preview ? '1' : '0'; ?>" style="padding:0.7rem 1.6rem; background:#1a1a2e; color:#fff; border:none; border-radius:4px; cursor:pointer; font-size:1em; font-weight:600; opacity:0.4;">
						<?php echo esc_html( $preview ? __( 'Je signe (désactivé en aperçu)', 'cordespace-snippets' ) : __( 'Je signe', 'cordespace-snippets' ) ); ?>
					</button>
				</p>

			</form>

			<script>
			(function () {
				var form   = document.getElementById('cordespace-waiver-sign-form');
				if (!form) return;
				var checks = form.querySelectorAll('.cordespace-waiver-check');
				var btn    = form.querySelector('#cordespace-waiver-submit');
				if (!btn || !checks.length) return;
				if (btn.getAttribute('data-preview') === '1') { return; }
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
