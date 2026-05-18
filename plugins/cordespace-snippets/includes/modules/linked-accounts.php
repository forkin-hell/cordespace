<?php
/**
 * Module : mon-espace.linked-accounts
 *
 * Liaison entre 2 comptes WP d'une même personne (typiquement un compte
 * « client·e » Amelia + un compte « prof » Amelia) et bascule en un clic.
 *
 * - Ajoute un champ « Compte lié » dans le profil WP (admin seulement).
 * - Donne la cap `switch_to_user` UNIQUEMENT vers le compte lié déclaré.
 * - Fournit le shortcode [cordespace_switch_button] avec redirect intelligent
 *   vers la page /mon-espace/ résolue dynamiquement (cf. helper).
 * - Vide les cookies Amelia à chaque switch (sinon l'ancien user « colle »).
 *
 * Dépendances : plugin User Switching (john-blackbourn).
 *
 * Voir docs/MON-ESPACE.md pour la procédure de liaison des comptes.
 */

defined( 'ABSPATH' ) || exit;

// 1) Champ admin "Compte lié" sur le profil WP
add_action( 'show_user_profile', 'cordespace_linked_user_field' );
add_action( 'edit_user_profile', 'cordespace_linked_user_field' );

function cordespace_linked_user_field( $user ) {
	if ( ! current_user_can( 'edit_users' ) ) {
		return;
	}
	$linked_id = (int) get_user_meta( $user->ID, '_cordespace_linked_user_id', true );
	$linked    = $linked_id ? get_user_by( 'ID', $linked_id ) : null;
	?>
	<h2>Cordespace — Compte lié</h2>
	<table class="form-table">
		<tr>
			<th><label for="cordespace_linked_user_id">Autre compte WP de cette personne</label></th>
			<td>
				<input type="number" id="cordespace_linked_user_id" name="cordespace_linked_user_id"
					value="<?php echo esc_attr( $linked_id ); ?>" class="regular-text" min="0">
				<?php if ( $linked ) : ?>
					<p style="margin-top:0.5rem;color:#2c70b8;"><strong>Actuellement lié à :</strong>
						<?php echo esc_html( $linked->display_name . ' (' . $linked->user_email . ')' ); ?>
					</p>
				<?php endif; ?>
				<p class="description">
					ID de l'autre compte WordPress de cette personne (ex. son compte prof si celui-ci est client·e, ou inverse).<br>
					Permet à User Switching de basculer entre les deux d'un clic. Mets <code>0</code> pour délier.
				</p>
			</td>
		</tr>
	</table>
	<?php
}

add_action( 'personal_options_update', 'cordespace_save_linked_user' );
add_action( 'edit_user_profile_update', 'cordespace_save_linked_user' );

function cordespace_save_linked_user( $user_id ) {
	if ( ! current_user_can( 'edit_user', $user_id ) ) {
		return;
	}
	if ( ! isset( $_POST['cordespace_linked_user_id'] ) ) {
		return;
	}
	$linked = (int) $_POST['cordespace_linked_user_id'];
	if ( $linked > 0 && get_user_by( 'ID', $linked ) ) {
		update_user_meta( $user_id, '_cordespace_linked_user_id', $linked );
	} else {
		delete_user_meta( $user_id, '_cordespace_linked_user_id' );
	}
}

// 2) Autorise switch_to_user UNIQUEMENT vers le compte lié
add_filter( 'user_has_cap', 'cordespace_grant_switch_to_linked', 10, 4 );

function cordespace_grant_switch_to_linked( $allcaps, $caps, $args, $user ) {
	if ( empty( $args[0] ) || $args[0] !== 'switch_to_user' ) {
		return $allcaps;
	}
	if ( empty( $args[2] ) ) {
		return $allcaps;
	}
	$target_id = (int) $args[2];
	$linked_id = (int) get_user_meta( $user->ID, '_cordespace_linked_user_id', true );
	if ( $linked_id > 0 && $linked_id === $target_id ) {
		$allcaps['switch_to_user'] = true;
	}
	return $allcaps;
}

// 3) Détermine où rediriger après le switch
//    Avec la page unifiée, on redirige toujours vers la page Mon espace
//    (résolue dynamiquement via le helper) qui s'adapte automatiquement
//    au rôle de l'utilisateur·trice après le switch.
function cordespace_get_switch_redirect_target() {
	return cordespace_get_mon_espace_url();
}

// 4) Shortcode pour afficher le bouton de bascule
add_shortcode( 'cordespace_switch_button', 'cordespace_render_switch_button' );

function cordespace_render_switch_button( $atts ) {
	if ( ! is_user_logged_in() ) {
		return '';
	}
	if ( ! class_exists( 'user_switching' ) ) {
		return '<p style="color:#a33;"><em>Le plugin User Switching doit être activé pour cette fonctionnalité.</em></p>';
	}

	$current   = wp_get_current_user();
	$linked_id = (int) get_user_meta( $current->ID, '_cordespace_linked_user_id', true );
	if ( $linked_id <= 0 ) {
		return '';
	}

	$linked_user = get_user_by( 'ID', $linked_id );
	if ( ! $linked_user ) {
		return '';
	}

	$url = user_switching::maybe_switch_url( $linked_user );
	if ( ! $url ) {
		return '';
	}

	// Ajoute le redirect_to vers /mon-espace/
	$redirect = cordespace_get_switch_redirect_target();
	$url      = add_query_arg( 'redirect_to', urlencode( $redirect ), $url );

	$atts = shortcode_atts( [
		'label' => '',
		'style' => 'background:#2c70b8;color:#fff;',
	], $atts );

	$default_label = sprintf(
		'Basculer vers mon autre compte (%s)',
		$linked_user->display_name ?: $linked_user->user_email
	);
	$label = $atts['label'] ?: $default_label;

	return sprintf(
		'<a href="%s" style="display:inline-block;padding:0.7rem 1.4rem;text-decoration:none;border-radius:5px;font-weight:600;%s">🔄 %s</a>',
		esc_url( $url ),
		esc_attr( $atts['style'] ),
		esc_html( $label )
	);
}

// 5) Force le redirect dans la chaîne User Switching aussi (ceinture + bretelles)
add_filter( 'user_switching_redirect_to', 'cordespace_user_switching_redirect_filter', 10, 4 );

function cordespace_user_switching_redirect_filter( $redirect_to, $context = '', $current_user = null, $new_user = null ) {
	if ( ! empty( $_REQUEST['redirect_to'] ) ) {
		$wanted = wp_unslash( (string) $_REQUEST['redirect_to'] );
		$home   = home_url();
		// Accepte les URLs du même domaine ou les chemins relatifs commençant
		// par /mon-espace (substring tolérant : reste valide même si la page
		// est renommée tant qu'elle contient « mon-espace » dans le slug).
		if ( strpos( $wanted, $home ) === 0 || strpos( $wanted, '/mon-espace' ) === 0 ) {
			return $wanted;
		}
	}
	return $redirect_to;
}

// 6) Vide les cookies Amelia (ameliaToken + ameliaUserEmail) à chaque switch.
//    Sans ça, Amelia continue d'authentifier l'ancien user via JWT cookie
//    après un User Switching, et affiche les mauvaises données.
add_action( 'switch_to_user',   'cordespace_clear_amelia_cookies', 5 );
add_action( 'switch_back_user', 'cordespace_clear_amelia_cookies', 5 );
add_action( 'switch_off_user',  'cordespace_clear_amelia_cookies', 5 );

function cordespace_clear_amelia_cookies() {
	if ( headers_sent() ) {
		return;
	}
	$past = time() - 3600;
	setcookie( 'ameliaToken',     '', $past, '/' );
	setcookie( 'ameliaUserEmail', '', $past, '/' );
	unset( $_COOKIE['ameliaToken'], $_COOKIE['ameliaUserEmail'] );
}
