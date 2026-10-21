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

// Tables d'état et de journal (ne dépend pas de l'ordre de chargement des modules).
require_once __DIR__ . '/amelia-waitlist-schema.php';

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

// ─── Règles pures ────────────────────────────────────────────────────────────

/**
 * Date Amelia (UTC, « Y-m-d H:i:s ») → DateTimeImmutable UTC ; null si vide/invalide.
 */
function cordespace_waitlist_utc( ?string $value ): ?DateTimeImmutable {
	if ( $value === null || trim( $value ) === '' ) {
		return null;
	}
	try {
		return new DateTimeImmutable( $value, new DateTimeZone( 'UTC' ) );
	} catch ( Exception $e ) {
		return null;
	}
}

/**
 * Un cours peut-il recevoir des annonces ? Approuvé, liste d'attente activée, « réserver
 * plusieurs fois » activé (sinon une personne en attente ne pourrait pas réserver), pas encore
 * commencé, réservations pas encore fermées.
 */
function cordespace_waitlist_event_is_announceable( array $event, DateTimeImmutable $now_utc ): bool {
	if ( ( $event['status'] ?? '' ) !== 'approved' || empty( $event['waitingListEnabled'] ) || empty( $event['bookMultipleTimes'] ) ) {
		return false;
	}
	$start = cordespace_waitlist_utc( $event['periodStart'] ?? null );
	if ( $start === null || $start <= $now_utc ) {
		return false;
	}
	$closes = cordespace_waitlist_utc( $event['bookingCloses'] ?? null );
	return $closes === null || $closes > $now_utc;
}

/**
 * Règle d'annonce « par état » : on annonce quand un bassin passe de 0 place libre
 * (ou jamais vérifié) à au moins 1, et seulement s'il y a des personnes en attente.
 *
 * @return array{announce:bool,free_spots:int}
 */
function cordespace_waitlist_decide( array $pool, ?array $state ): array {
	$free     = max( 0, (int) ( $pool['free'] ?? 0 ) );
	$was_full = $state === null || (int) ( $state['free_spots'] ?? 0 ) === 0;
	$announce = $free >= 1 && $was_full && ! empty( $pool['waiting'] );
	return [ 'announce' => $announce, 'free_spots' => $free ];
}

// ─── État et journal en base ─────────────────────────────────────────────────

/**
 * Dernier état connu d'un bassin : [ free_spots, checked_at, announced_at ] ; null si jamais vérifié.
 */
function cordespace_waitlist_state_get( int $event_id, string $pool_key ): ?array {
	global $wpdb;
	$row = $wpdb->get_row( $wpdb->prepare(
		'SELECT free_spots, checked_at, announced_at FROM ' . cordespace_waitlist_state_table() . ' WHERE event_id = %d AND pool_key = %s',
		$event_id,
		$pool_key
	), ARRAY_A );
	if ( ! $row ) {
		return null;
	}
	return [
		'free_spots'   => (int) $row['free_spots'],
		'checked_at'   => (string) $row['checked_at'],
		'announced_at' => $row['announced_at'] !== null && $row['announced_at'] !== '' ? (string) $row['announced_at'] : null,
	];
}

/**
 * Enregistre l'état d'un bassin (upsert). announced_at n'est mis à jour que si $announced.
 */
function cordespace_waitlist_state_set( int $event_id, string $pool_key, int $free_spots, bool $announced ): void {
	global $wpdb;
	$table = cordespace_waitlist_state_table();
	$now   = gmdate( 'Y-m-d H:i:s' );
	if ( $announced ) {
		$sql = $wpdb->prepare(
			"INSERT INTO {$table} (event_id, pool_key, free_spots, checked_at, announced_at) VALUES (%d, %s, %d, %s, %s)
			 ON DUPLICATE KEY UPDATE free_spots = %d, checked_at = %s, announced_at = %s",
			$event_id, $pool_key, $free_spots, $now, $now, $free_spots, $now, $now
		);
	} else {
		$sql = $wpdb->prepare(
			"INSERT INTO {$table} (event_id, pool_key, free_spots, checked_at, announced_at) VALUES (%d, %s, %d, %s, NULL)
			 ON DUPLICATE KEY UPDATE free_spots = %d, checked_at = %s",
			$event_id, $pool_key, $free_spots, $now, $free_spots, $now
		);
	}
	$wpdb->query( $sql );
}

/**
 * Journalise un événement du module : kind = announce | leave | removed | cleanup.
 */
function cordespace_waitlist_log( int $event_id, string $pool_key, int $booking_id, string $email, string $kind ): void {
	global $wpdb;
	$wpdb->insert( cordespace_waitlist_log_table(), [
		'event_id'   => $event_id,
		'pool_key'   => $pool_key,
		'booking_id' => $booking_id,
		'email'      => $email,
		'kind'       => $kind,
		'created_at' => gmdate( 'Y-m-d H:i:s' ),
	] );
}

/**
 * Garde-fou : cette inscription a-t-elle déjà reçu une annonce pour ce bassin récemment ?
 */
function cordespace_waitlist_recently_announced( int $booking_id, string $pool_key, int $within_seconds = 3600 ): bool {
	global $wpdb;
	$since = gmdate( 'Y-m-d H:i:s', time() - $within_seconds );
	$count = (int) $wpdb->get_var( $wpdb->prepare(
		'SELECT COUNT(*) FROM ' . cordespace_waitlist_log_table() . " WHERE booking_id = %d AND pool_key = %s AND kind = 'announce' AND created_at >= %s",
		$booking_id,
		$pool_key,
		$since
	) );
	return $count > 0;
}

/**
 * Purge le journal au-delà de $days jours. Retourne le nombre de lignes effacées.
 */
function cordespace_waitlist_log_purge( int $days = 90 ): int {
	global $wpdb;
	$before = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
	$n      = $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . cordespace_waitlist_log_table() . ' WHERE created_at < %s', $before ) );
	return $n === false ? 0 : (int) $n;
}

// ─── Liens et courriel d'annonce ─────────────────────────────────────────────

/**
 * Signature du lien de retrait : 32 premiers caractères du HMAC (id|jeton Amelia, sel WP).
 */
function cordespace_waitlist_leave_signature( int $booking_id, string $token ): string {
	return substr( hash_hmac( 'sha256', $booking_id . '|' . $token, wp_salt( 'auth' ) ), 0, 32 );
}

/**
 * Lien « Je ne veux plus être sur la liste d'attente » (sans connexion).
 */
function cordespace_waitlist_leave_url( int $booking_id, string $token ): string {
	return home_url( '/?cordespace_waitlist_leave=' . $booking_id . '&k=' . cordespace_waitlist_leave_signature( $booking_id, $token ) );
}

/**
 * Page de réservation du cours : la page « wpa-events » du cours (ou de son parent pour un
 * cours récurrent), sinon la liste des événements filtrée sur ce cours.
 */
function cordespace_waitlist_event_url( array $event ): string {
	$ids = array_values( array_unique( array_filter( [ (int) ( $event['id'] ?? 0 ), (int) ( $event['parentId'] ?? 0 ) ] ) ) );
	foreach ( $ids as $id ) {
		$posts = get_posts( [
			'post_type'   => 'wpa-events',
			'post_status' => 'publish',
			'meta_key'    => '_amelia_event_id',
			'meta_value'  => (string) $id,
			'numberposts' => 1,
		] );
		if ( ! empty( $posts ) ) {
			$post = $posts[0];
			return (string) get_permalink( is_object( $post ) ? (int) $post->ID : (int) $post );
		}
	}
	return home_url( '/evenements/?ameliaEventId=' . (int) ( $event['id'] ?? 0 ) );
}

/**
 * Textes du courriel d'annonce (filtre cordespace_waitlist_texts).
 */
function cordespace_waitlist_texts(): array {
	$texts = [
		'subject'    => 'Une place s\'est libérée : {cours}, {date}',
		'greeting'   => 'Bonjour {prenom},',
		'intro'      => 'Une place vient de se libérer pour {cours}, {date}.',
		'ticket'     => 'Il s\'agit du billet « {billet} » : {places}.',
		'noticket'   => '{places}.',
		'first_come' => 'Premier·ère arrivé·e, premier·ère servi·e. Si la place est déjà prise, tu restes sur la liste pour la prochaine.',
		'button'     => 'Réserver ma place',
		'leave'      => 'Je ne veux plus être sur la liste d\'attente',
		'signature'  => 'L\'équipe Cordespace',
	];
	return (array) apply_filters( 'cordespace_waitlist_texts', $texts );
}

/**
 * Date Amelia (UTC) → « jeudi 22 octobre 2026 à 18 h 30 » en heure de Montréal.
 */
function cordespace_waitlist_format_date( string $utc ): string {
	$dt = cordespace_waitlist_utc( $utc );
	if ( $dt === null ) {
		return $utc;
	}
	$tz = wp_timezone();
	$dt = $dt->setTimezone( $tz );
	if ( class_exists( 'IntlDateFormatter' ) ) {
		$fmt = new IntlDateFormatter( 'fr_CA', IntlDateFormatter::FULL, IntlDateFormatter::SHORT, $tz->getName(), IntlDateFormatter::GREGORIAN, "EEEE d MMMM yyyy 'à' H 'h' mm" );
		$out = $fmt->format( $dt );
		if ( is_string( $out ) && $out !== '' ) {
			return $out;
		}
	}
	return date_i18n( 'l j F Y \à G \h i', $dt->getTimestamp() );
}

/**
 * Compose le courriel d'annonce : [ subject, body (HTML) ].
 *
 * @param array $person  ligne de réservation (firstName, email…)
 * @param array $event   event_row (name, periodStart…)
 * @param array $pool    bassin (label, free)
 */
function cordespace_waitlist_compose_email( array $person, array $event, array $pool, string $event_url, string $leave_url ): array {
	$t      = cordespace_waitlist_texts();
	$free   = max( 1, (int) ( $pool['free'] ?? 1 ) );
	$places = $free === 1 ? '1 place libre' : $free . ' places libres';
	$vars   = [
		'{prenom}' => trim( (string) ( $person['firstName'] ?? '' ) ),
		'{cours}'  => (string) ( $event['name'] ?? '' ),
		'{date}'   => cordespace_waitlist_format_date( (string) ( $event['periodStart'] ?? '' ) ),
		'{billet}' => (string) ( $pool['label'] ?? '' ),
		'{places}' => $places,
	];
	$fill = static fn( string $s ): string => strtr( $s, $vars );

	$greeting = $vars['{prenom}'] !== '' ? $fill( $t['greeting'] ) : 'Bonjour,';
	$ticket   = $vars['{billet}'] !== '' ? $fill( $t['ticket'] ) : $fill( $t['noticket'] );
	$subject  = $fill( $t['subject'] );
	// Nœuds texte d'un HTML que nous composons : on échappe < > & mais pas les apostrophes
	// (lisibilité du courriel et des tests ; les guillemets n'ont pas à être échappés hors attribut).
	$esc = static fn( string $s ): string => htmlspecialchars( $s, ENT_NOQUOTES, 'UTF-8' );

	$body = '<div style="font-family:Helvetica,Arial,sans-serif;font-size:16px;line-height:1.5;color:#222;max-width:560px;margin:0 auto;padding:24px;">'
		. '<p>' . $esc( $greeting ) . '</p>'
		. '<p>' . $esc( $fill( $t['intro'] ) ) . ' ' . $esc( $ticket ) . '</p>'
		. '<p style="margin:28px 0;"><a href="' . esc_url( $event_url ) . '" style="display:inline-block;background:#5b3ea6;color:#fff;text-decoration:none;font-weight:700;padding:12px 22px;border-radius:6px;">' . $esc( $t['button'] ) . '</a></p>'
		. '<p>' . $esc( $t['first_come'] ) . '</p>'
		. '<p>' . $esc( $t['signature'] ) . '</p>'
		. '<p style="margin-top:32px;font-size:13px;color:#666;"><a href="' . esc_url( $leave_url ) . '" style="color:#666;">' . $esc( $t['leave'] ) . '</a></p>'
		. '</div>';

	return [ 'subject' => $subject, 'body' => $body ];
}
