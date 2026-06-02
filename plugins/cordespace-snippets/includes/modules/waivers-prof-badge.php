<?php
/**
 * Module: waivers-prof-badge
 *
 * Affiche un badge "⚠️ WAIVER MANQUANT" à côté du nom des élèves qui n'ont
 * pas signé tous les waivers requis pour l'événement, dans la vue prof
 * (teacher-presence). Permet à la prof·esseure de repérer en un clin d'œil
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
 *   - waivers-store    (cordespace_waivers_has_signed_current)
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

	// Cas particulier : pas de compte WP (Amelia-only customer, ex: futur·e
	// partenaire Bob qui n'a pas finalisé son inscription)
	if ( $wp_user_id <= 0 ) {
		echo '<span title="Cette personne n\'a pas (encore) de compte WordPress pour signer le document requis"'
			. ' style="display:inline-block;background:#d63638;color:#fff;font-size:0.7em;padding:0.15rem 0.5rem;border-radius:4px;margin-left:0.4rem;font-weight:700;letter-spacing:0.3px;">'
			. '⚠️ COMPTE NON CRÉÉ</span>';
		return;
	}

	// Vérifie quels waivers cette personne n'a PAS signés en version courante
	$missing_count = 0;
	foreach ( $applicable as $waiver_id ) {
		if ( ! cordespace_waivers_has_signed_current( $wp_user_id, (int) $waiver_id ) ) {
			$missing_count++;
		}
	}

	if ( $missing_count === 0 ) {
		return; // tout est signé, pas de badge
	}

	$label = $missing_count > 1
		? sprintf( '⚠️ %d WAIVERS MANQUANTS', $missing_count )
		: '⚠️ WAIVER MANQUANT';

	echo '<span title="Cette personne doit signer le (les) document(s) requis avant le début du cours"'
		. ' style="display:inline-block;background:#d63638;color:#fff;font-size:0.7em;padding:0.15rem 0.5rem;border-radius:4px;margin-left:0.4rem;font-weight:700;letter-spacing:0.3px;">'
		. esc_html( $label ) . '</span>';
}
