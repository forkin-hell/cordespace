<?php
/**
 * Module amelia.waitlist-annonces — annonces de places libérées pour la liste d'attente Amelia.
 *
 * Amelia 9.9.1 a une liste d'attente native pour les événements (inscriptions au statut
 * `waiting`) mais ne fait rien quand une place se libère. Ce module :
 *  - lit les cours, billets et réservations Amelia et calcule les places libres par « bassin »
 *    (un billet, ou le cours entier quand il n'a pas de billets / a une capacité partagée) ;
 *  - (suite dans les tâches suivantes : règle d'annonce, courriels, retraits, planification).
 *
 * Toutes les dates Amelia sont en UTC. Voir docs/superpowers/specs/2026-10-21-liste-attente-design.md.
 */
defined( 'ABSPATH' ) || exit;

/**
 * Le cours (événement Amelia) et ce que le module a besoin d'en savoir. null si inconnu.
 *
 * @return array{id:int,parentId:?int,name:string,status:string,bookingCloses:?string,periodStart:string,periodEnd:string,customPricing:bool,maxCapacity:int,maxCustomCapacity:?int,waitingListEnabled:bool,bookMultipleTimes:bool}|null
 */
function cordespace_waitlist_event_row( int $event_id ): ?array {
	global $wpdb;
	if ( $event_id <= 0 ) {
		return null;
	}
	$row = $wpdb->get_row( $wpdb->prepare(
		"SELECT e.id, e.parentId, e.name, e.status, e.bookingCloses, e.customPricing, e.maxCapacity, e.maxCustomCapacity,
		        e.bookMultipleTimes, e.settings,
		        (SELECT MIN(p.periodStart) FROM {$wpdb->prefix}amelia_events_periods p WHERE p.eventId = e.id) AS periodStart,
		        (SELECT MAX(p.periodEnd)   FROM {$wpdb->prefix}amelia_events_periods p WHERE p.eventId = e.id) AS periodEnd
		 FROM {$wpdb->prefix}amelia_events e
		 WHERE e.id = %d",
		$event_id
	), ARRAY_A );
	if ( ! $row ) {
		return null;
	}
	$settings = json_decode( (string) ( $row['settings'] ?? '' ), true );
	return [
		'id'                 => (int) $row['id'],
		'parentId'           => $row['parentId'] !== null && $row['parentId'] !== '' ? (int) $row['parentId'] : null,
		'name'               => (string) $row['name'],
		'status'             => (string) $row['status'],
		'bookingCloses'      => ! empty( $row['bookingCloses'] ) ? (string) $row['bookingCloses'] : null,
		'periodStart'        => (string) $row['periodStart'],
		'periodEnd'          => (string) $row['periodEnd'],
		'customPricing'      => (bool) $row['customPricing'],
		'maxCapacity'        => (int) $row['maxCapacity'],
		'maxCustomCapacity'  => $row['maxCustomCapacity'] !== null && $row['maxCustomCapacity'] !== '' ? (int) $row['maxCustomCapacity'] : null,
		'waitingListEnabled' => ! empty( $settings['waitingList']['enabled'] ),
		'bookMultipleTimes'  => (bool) $row['bookMultipleTimes'],
	];
}

/**
 * Billets actifs du cours : [ id, name, spots, waitingListSpots ].
 */
function cordespace_waitlist_tickets( int $event_id ): array {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT id, name, spots, waitingListSpots FROM {$wpdb->prefix}amelia_events_to_tickets WHERE eventId = %d AND enabled = 1 ORDER BY id",
		$event_id
	), ARRAY_A );
	return array_map( static fn( array $t ): array => [
		'id'               => (int) $t['id'],
		'name'             => (string) $t['name'],
		'spots'            => (int) $t['spots'],
		'waitingListSpots' => (int) $t['waitingListSpots'],
	], $rows ?: [] );
}

/**
 * Réservations du cours (statuts approved, pending, waiting), une ligne par (réservation × billet).
 * Courriel et prénom viennent de amelia_users ; repli prénom = `info` JSON de la réservation.
 */
function cordespace_waitlist_bookings( int $event_id ): array {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT DISTINCT b.id, b.customerId, b.status, b.persons, b.token, b.info,
		        bt.eventTicketId AS ticketId, bt.persons AS ticketPersons,
		        u.email, u.firstName
		 FROM {$wpdb->prefix}amelia_customer_bookings b
		 JOIN {$wpdb->prefix}amelia_customer_bookings_to_events_periods bp ON bp.customerBookingId = b.id
		 JOIN {$wpdb->prefix}amelia_events_periods ep ON ep.id = bp.eventPeriodId
		 LEFT JOIN {$wpdb->prefix}amelia_customer_bookings_to_events_tickets bt ON bt.customerBookingId = b.id
		 LEFT JOIN {$wpdb->prefix}amelia_users u ON u.id = b.customerId
		 WHERE ep.eventId = %d AND b.status IN ('approved', 'pending', 'waiting')
		 ORDER BY b.id, bt.eventTicketId",
		$event_id
	), ARRAY_A );
	$out = [];
	foreach ( $rows ?: [] as $r ) {
		$first = (string) ( $r['firstName'] ?? '' );
		if ( $first === '' && ! empty( $r['info'] ) ) {
			$info  = json_decode( (string) $r['info'], true );
			$first = (string) ( $info['firstName'] ?? '' );
		}
		$persons = (int) $r['persons'];
		$out[]   = [
			'id'            => (int) $r['id'],
			'customerId'    => (int) $r['customerId'],
			'status'        => (string) $r['status'],
			'persons'       => $persons,
			'ticketId'      => $r['ticketId'] !== null ? (int) $r['ticketId'] : null,
			'ticketPersons' => $r['ticketPersons'] !== null ? (int) $r['ticketPersons'] : max( 1, $persons ),
			'email'         => (string) ( $r['email'] ?? '' ),
			'firstName'     => $first,
			'token'         => (string) ( $r['token'] ?? '' ),
		];
	}
	return $out;
}

/**
 * Bassins de places d'un cours (fonction pure).
 *
 * - billets sans capacité partagée : un bassin par billet (`ticket:{id}`) ;
 * - billets avec `maxCustomCapacity` : un bassin `event` sur cette capacité ;
 * - sans billets : un bassin `event` sur `maxCapacity`.
 * Les réservations approved/pending prennent des places ; les waiting sont listées dans `waiting`.
 *
 * @return list<array{key:string,ticketId:?int,label:string,spots:int,taken:int,free:int,waiting:array}>
 */
function cordespace_waitlist_compute_pools( array $event, array $tickets, array $bookings ): array {
	$fill = static function ( array $pool, array $bookings, ?int $ticket_id ): array {
		foreach ( $bookings as $b ) {
			if ( $ticket_id !== null && (int) ( $b['ticketId'] ?? 0 ) !== $ticket_id ) {
				continue;
			}
			if ( $b['status'] === 'waiting' ) {
				$pool['waiting'][] = $b;
			} elseif ( $b['status'] === 'approved' || $b['status'] === 'pending' ) {
				$pool['taken'] += (int) ( $b['ticketPersons'] ?? $b['persons'] ?? 1 );
			}
		}
		$pool['free'] = max( 0, $pool['spots'] - $pool['taken'] );
		return $pool;
	};

	$custom = ! empty( $event['customPricing'] ) && $tickets;
	if ( $custom && empty( $event['maxCustomCapacity'] ) ) {
		$pools = [];
		foreach ( $tickets as $t ) {
			$pools[] = $fill( [
				'key'      => 'ticket:' . (int) $t['id'],
				'ticketId' => (int) $t['id'],
				'label'    => (string) $t['name'],
				'spots'    => (int) $t['spots'],
				'taken'    => 0,
				'free'     => 0,
				'waiting'  => [],
			], $bookings, (int) $t['id'] );
		}
		return $pools;
	}
	$spots = $custom ? (int) $event['maxCustomCapacity'] : (int) ( $event['maxCapacity'] ?? 0 );
	return [ $fill( [ 'key' => 'event', 'ticketId' => null, 'label' => '', 'spots' => $spots, 'taken' => 0, 'free' => 0, 'waiting' => [] ], $bookings, null ) ];
}

/**
 * Bassins réels d'un cours (lecture en base). [] si le cours est inconnu.
 */
function cordespace_waitlist_pools( int $event_id ): array {
	$event = cordespace_waitlist_event_row( $event_id );
	if ( $event === null ) {
		return [];
	}
	return cordespace_waitlist_compute_pools( $event, cordespace_waitlist_tickets( $event_id ), cordespace_waitlist_bookings( $event_id ) );
}
