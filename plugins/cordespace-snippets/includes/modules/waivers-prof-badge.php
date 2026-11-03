<?php
/**
 * Module: waivers-prof-badge
 *
 * Affiche un badge à côté du nom des élèves dans la vue prof (teacher-presence) : rouge « Waiver à signer »
 * (jamais signé), orange « Waiver vX » (une ancienne version seulement : la personne reçoit les rappels pour
 * signer la nouvelle), vert « Waiver signé ». Par le compte WordPress et par le courriel de la réservation
 * (personne sans compte : signature partenaire ou par le lien public / code QR). Permet à la prof·esseure de repérer en un clin d'œil
 * qui doit signer avant le début du cours — typiquement les paiements onSite
 * qui bypass le gating WC checkout.
 *
 * S'accroche au slot `do_action('cordespace_teacher_presence_student_badges', $booking, $event)`.
 *
 * Cache mémoire : les waivers applicables par event_id sont calculés une fois
 * et réutilisés pour tous les élèves du même event (évite N requêtes répétées).
 *
 * Dépend de :
 *   - teacher-presence (slot d'action + champ wp_user_id ajouté à la query)
 *   - waivers-defaults (cordespace_waivers_applicable_defaults_for_amelia_event)
 *   - waivers-store    (cordespace_waivers_signature_state)
 *
 * Voir docs/superpowers/specs/waivers.md §3.9 (vue prof) + §4.6 (filet onSite)
 */

defined( 'ABSPATH' ) || exit;

add_action( 'cordespace_teacher_presence_student_badges', 'cordespace_waivers_prof_badge_render', 10, 2 );

function cordespace_waivers_prof_badge_render( $booking, $event ): void {
	if ( ! is_array( $booking ) || ! is_array( $event ) ) {
		return;
	}

	$wp_user_id = isset( $booking['wp_user_id'] ) ? (int) $booking['wp_user_id'] : 0;
	$event_id   = isset( $event['event_id'] ) ? (int) $event['event_id'] : 0;
	if ( $event_id <= 0 ) {
		return;
	}

	// Cache statique par event_id : on calcule la liste des waivers applicables
	// une seule fois par événement, peu importe le nombre d'élèves.
	static $applicable_cache = [];
	if ( ! isset( $applicable_cache[ $event_id ] ) ) {
		$applicable_cache[ $event_id ] = function_exists( 'cordespace_waivers_applicable_defaults_for_amelia_event' )
			? cordespace_waivers_applicable_defaults_for_amelia_event( $event_id )
			: [];
	}
	$applicable = $applicable_cache[ $event_id ];

	if ( empty( $applicable ) ) {
		// Aucun waiver requis pour cet event → pas de badge à afficher
		return;
	}

	// État par document : version en vigueur signée / ancienne version seulement / rien. Par le compte WordPress
	// ET par le courriel de la réservation (personne sans compte, signature partenaire ou par le lien public / code QR).
	$email    = isset( $booking['email'] ) ? (string) $booking['email'] : '';
	$none     = 0;
	$previous = []; // waiver_id => dernière version signée
	foreach ( $applicable as $waiver_id ) {
		$state = cordespace_waivers_signature_state( $wp_user_id, $email, (int) $waiver_id );
		if ( $state['state'] === 'previous' ) {
			$previous[ (int) $waiver_id ] = (string) $state['version'];
		} elseif ( $state['state'] !== 'current' ) {
			$none++;
		}
	}

	if ( $none === 0 && ! $previous ) {
		// Tout est signé : pastille verte discrète, pour que la prof le voie d'un coup d'œil.
		cordespace_waivers_prof_badge_print( 'Waiver signé', 'Le ou les documents requis sont signés (version en vigueur).', 'ok' );
		return;
	}

	if ( $none > 0 ) {
		// Au moins un document jamais signé : rouge (le manquant l'emporte sur une ancienne version).
		$label = $none > 1 ? sprintf( '%d waivers à signer', $none ) : 'Waiver à signer';
		$title = $wp_user_id > 0
			? 'Cette personne doit signer le ou les documents requis avant le cours : Mon espace → Mes waivers, ou le code QR au comptoir.'
			: 'Cette personne n\'a pas de compte Cordespace : elle peut signer le document requis avec le code QR au comptoir, avec le courriel de sa réservation.';
		cordespace_waivers_prof_badge_print( $label, $title );
		return;
	}

	// Ancienne version signée seulement : orange « Waiver vX » ; la personne reçoit les rappels pour signer la nouvelle.
	$versions = array_values( array_unique( $previous ) );
	$label    = ( count( $previous ) > 1 ? 'Waivers v' : 'Waiver v' ) . implode( ', v', $versions );
	$current  = function_exists( 'cordespace_waivers_get_version' ) ? cordespace_waivers_get_version( (int) array_key_first( $previous ) ) : '';
	$title    = sprintf( 'Ancienne version signée (v%s)', implode( ', v', $versions ) )
		. ( $current !== '' ? sprintf( ' ; version en vigueur : v%s', $current ) : '' )
		. '. La personne reçoit les rappels pour signer la nouvelle version.';
	cordespace_waivers_prof_badge_print( $label, $title, 'old' );
}

/**
 * Le badge lui-même : pastille pâle, sans majuscules. Variantes 'todo' (rouge : à signer), 'old' (orange : ancienne
 * version signée) ou 'ok' (vert : signé).
 * Sur téléphone, il passe sur sa propre ligne sous le nom, pour ne pas se perdre parmi les autres badges.
 */
function cordespace_waivers_prof_badge_print( string $label, string $title, string $variant = 'todo' ): void {
	cordespace_waivers_prof_badge_styles_once();
	if ( $variant === 'ok' ) {
		$colors = 'background:#e8f5e9;color:#1b5e20;border:1px solid #b7dfb9;';
	} elseif ( $variant === 'old' ) {
		$colors = 'background:#fff4e5;color:#8a5a00;border:1px solid #f3d19c;'; // ancienne version signée
	} else {
		$colors = 'background:#fdecea;color:#b32d2e;border:1px solid #f1b3b3;';
	}
	$icon = $variant === 'ok' ? '✓ ' : '✍️ ';
	echo '<span class="cordespace-waiver-badge" title="' . esc_attr( $title ) . '"'
		. ' style="display:inline-block;' . $colors . 'font-size:0.78em;padding:0.15rem 0.6rem;border-radius:999px;margin-left:0.35rem;font-weight:600;letter-spacing:0;vertical-align:middle;">'
		. $icon . esc_html( $label ) . '</span>';
}

/**
 * Feuille de style du badge, imprimée une seule fois par page. Sur téléphone (≤ 600 px), la vue
 * de présence compacte tous les badges du nom ; celui-ci garde une taille lisible et sa propre ligne.
 */
function cordespace_waivers_prof_badge_styles_once(): void {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	echo '<style>'
		. '@media (max-width: 600px){'
		. '.cordespace-today-students-wrapper li > div:first-child > div:first-child > span.cordespace-waiver-badge{'
		. 'display:block !important;width:max-content;max-width:100%;white-space:normal;'
		. 'margin:0.35rem 0 0 !important;font-size:0.74em !important;padding:0.15rem 0.6rem !important;'
		. '}}'
		. '</style>';
}
