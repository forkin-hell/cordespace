<?php
/**
 * Module amelia.waitlist-affichage — le formulaire public redevient réservable tant qu'une place est libre.
 *
 * Amelia traite sa liste d'attente comme une file : dès qu'une personne est en attente sur un cours,
 * `getEventInfo()` renvoie `full = true` et le formulaire public bascule en mode « Rejoindre la liste
 * d'attente » pour tout le monde, même quand une place vient de se libérer. Côté serveur, une
 * réservation normale reste acceptée (« réserver plusieurs fois » activé).
 *
 * Ce module corrige ce que le formulaire PUBLIC reçoit (filtres `amelia_get_events_filter` et
 * `amelia_get_event_filter`) : si au moins un bassin a une place libre, `full` repasse à false et les
 * compteurs de personnes en attente à 0, pour que le formulaire propose une réservation normale.
 * Dès que le cours est vraiment complet, on ne touche à rien : la liste d'attente réapparaît.
 * Les appels de l'admin et des cabinets (paramètre `source`, referer wp-admin) ne sont jamais modifiés.
 *
 * Dépend des champs qu'Amelia envoie à son formulaire (`full`, `waiting`, `customTickets[].waiting`,
 * `settings.waitingList.peopleWaiting`) : à re-tester après chaque mise à jour d'Amelia
 * (docs/liste-attente-test-deux-minutes.md). En cas de casse : désactiver ce module.
 */
defined( 'ABSPATH' ) || exit;

/**
 * Requête AJAX du formulaire public d'Amelia ? (admin-ajax, action wpamelia_api, pas de `source`
 * cabinet, referer hors wp-admin). Sans referer on ne sait rien : on ne touche à rien.
 */
function cordespace_waitlist_is_public_events_request(): bool {
	if ( ! wp_doing_ajax() ) {
		return false;
	}
	$action = isset( $_REQUEST['action'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['action'] ) ) : '';
	if ( $action !== 'wpamelia_api' || ! empty( $_REQUEST['source'] ) ) {
		return false;
	}
	$referer = wp_get_referer();
	if ( ! $referer ) {
		return false;
	}
	return strpos( (string) $referer, '/wp-admin/' ) === false;
}

/**
 * Ce cours a-t-il quelque chose à corriger ? (liste d'attente active et au moins une personne en attente)
 */
function cordespace_waitlist_needs_override( array $event ): bool {
	if ( empty( $event['isWaitingList'] ) ) {
		return false;
	}
	if ( (int) ( $event['waiting'] ?? 0 ) > 0 ) {
		return true;
	}
	foreach ( (array) ( $event['customTickets'] ?? [] ) as $ticket ) {
		if ( is_array( $ticket ) && (int) ( $ticket['waiting'] ?? 0 ) > 0 ) {
			return true;
		}
	}
	return false;
}

/**
 * Corrige le tableau d'un cours (fonction pure) : inchangé si le cours est fermé ou si aucun bassin
 * n'a de place libre ; sinon `full` false, statut open/upcoming, compteurs d'attente à 0.
 */
function cordespace_waitlist_override_event_array( array $event, array $pools ): array {
	if ( ! empty( $event['closed'] ) ) {
		return $event;
	}
	$free = false;
	foreach ( $pools as $pool ) {
		if ( (int) ( $pool['free'] ?? 0 ) > 0 ) {
			$free = true;
			break;
		}
	}
	if ( ! $free ) {
		return $event;
	}
	$event['full']           = false;
	$event['approvedStatus'] = ! empty( $event['upcoming'] ) ? 'upcoming' : 'open';
	$event['waiting']        = 0;
	if ( ! empty( $event['customTickets'] ) && is_array( $event['customTickets'] ) ) {
		foreach ( $event['customTickets'] as $i => $ticket ) {
			if ( is_array( $ticket ) ) {
				$event['customTickets'][ $i ]['waiting'] = 0;
			}
		}
	}
	if ( isset( $event['settings'] ) && is_string( $event['settings'] ) && $event['settings'] !== '' ) {
		$settings = json_decode( $event['settings'], true );
		if ( is_array( $settings ) && isset( $settings['waitingList'] ) && is_array( $settings['waitingList'] ) ) {
			$settings['waitingList']['peopleWaiting'] = 0;
			$encoded = wp_json_encode( $settings );
			if ( is_string( $encoded ) ) {
				$event['settings'] = $encoded;
			}
		}
	}
	return $event;
}

/** Filtre amelia_get_events_filter (liste des cours du formulaire public). */
function cordespace_waitlist_filter_events( $events ) {
	if ( ! is_array( $events ) || ! function_exists( 'cordespace_waitlist_pools' ) || ! cordespace_waitlist_is_public_events_request() ) {
		return $events;
	}
	foreach ( $events as $i => $event ) {
		if ( ! is_array( $event ) || empty( $event['id'] ) || ! cordespace_waitlist_needs_override( $event ) ) {
			continue;
		}
		$events[ $i ] = cordespace_waitlist_override_event_array( $event, cordespace_waitlist_pools( (int) $event['id'] ) );
	}
	return $events;
}

/** Filtre amelia_get_event_filter (un cours, page de réservation). */
function cordespace_waitlist_filter_event( $event ) {
	if ( ! is_array( $event ) || empty( $event['id'] ) || ! function_exists( 'cordespace_waitlist_pools' )
		|| ! cordespace_waitlist_is_public_events_request() || ! cordespace_waitlist_needs_override( $event ) ) {
		return $event;
	}
	return cordespace_waitlist_override_event_array( $event, cordespace_waitlist_pools( (int) $event['id'] ) );
}

add_filter( 'amelia_get_events_filter', 'cordespace_waitlist_filter_events', 20 );
add_filter( 'amelia_get_event_filter', 'cordespace_waitlist_filter_event', 20 );
