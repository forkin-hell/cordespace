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
// Helper courriel partagé (chargé par le plugin ; ici pour que le module se suffise à lui-même).
require_once dirname( __DIR__ ) . '/core/mail.php';

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

// ─── Retrait d'une inscription en attente ───────────────────────────────────

/**
 * Efface une inscription en attente (statut `waiting` seulement) et ses lignes liées
 * (billets, périodes, paiement à 0), dans une transaction. Faux si la réservation n'existe
 * pas ou n'est pas en attente.
 */
function cordespace_waitlist_delete_waiting_booking( int $booking_id ): bool {
	global $wpdb;
	$p      = $wpdb->prefix;
	$status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$p}amelia_customer_bookings WHERE id = %d", $booking_id ) );
	if ( $status !== 'waiting' ) {
		return false;
	}
	$wpdb->query( 'START TRANSACTION' );
	$ok = true;
	foreach ( [
		"DELETE FROM {$p}amelia_customer_bookings_to_events_tickets WHERE customerBookingId = %d",
		"DELETE FROM {$p}amelia_customer_bookings_to_events_periods WHERE customerBookingId = %d",
		"DELETE FROM {$p}amelia_payments WHERE customerBookingId = %d",
		"DELETE FROM {$p}amelia_customer_bookings WHERE id = %d AND status = 'waiting'",
	] as $sql ) {
		if ( $wpdb->query( $wpdb->prepare( $sql, $booking_id ) ) === false ) {
			$ok = false;
			break;
		}
	}
	$wpdb->query( $ok ? 'COMMIT' : 'ROLLBACK' );
	return $ok;
}

/**
 * Redirection : en production on arrête l'exécution ; dans les tests (CS_TESTING) on continue.
 */
function cordespace_waitlist_redirect( string $url ): void {
	wp_safe_redirect( $url );
	if ( ! defined( 'CS_TESTING' ) ) {
		exit;
	}
}

/**
 * Lien « Je ne veux plus être sur la liste d'attente » : ?cordespace_waitlist_leave={id}&k={signature}.
 * Vérifie la signature contre le jeton Amelia de l'inscription, efface, journalise, redirige.
 */
function cordespace_waitlist_handle_leave(): void {
	if ( ! isset( $_GET['cordespace_waitlist_leave'] ) ) {
		return;
	}
	$booking_id = absint( wp_unslash( $_GET['cordespace_waitlist_leave'] ) );
	$k          = isset( $_GET['k'] ) ? sanitize_text_field( wp_unslash( $_GET['k'] ) ) : '';
	global $wpdb;
	$p   = $wpdb->prefix;
	$row = $booking_id > 0 ? $wpdb->get_row( $wpdb->prepare(
		"SELECT b.token, b.status, u.email,
		        (SELECT ep.eventId FROM {$p}amelia_customer_bookings_to_events_periods bp
		           JOIN {$p}amelia_events_periods ep ON ep.id = bp.eventPeriodId
		          WHERE bp.customerBookingId = b.id LIMIT 1) AS eventId
		 FROM {$p}amelia_customer_bookings b
		 LEFT JOIN {$p}amelia_users u ON u.id = b.customerId
		 WHERE b.id = %d",
		$booking_id
	), ARRAY_A ) : null;
	$valid = $row && $row['status'] === 'waiting' && $k !== ''
		&& hash_equals( cordespace_waitlist_leave_signature( $booking_id, (string) $row['token'] ), $k );
	if ( $valid && cordespace_waitlist_delete_waiting_booking( $booking_id ) ) {
		cordespace_waitlist_log( (int) ( $row['eventId'] ?? 0 ), '', $booking_id, (string) ( $row['email'] ?? '' ), 'leave' );
		cordespace_waitlist_redirect( home_url( '/?cordespace_waitlist_left=ok' ) );
		return;
	}
	cordespace_waitlist_redirect( home_url( '/?cordespace_waitlist_left=invalid' ) );
}

/**
 * Bandeau après un retrait (?cordespace_waitlist_left=ok|invalid), une seule fois par page.
 */
function cordespace_waitlist_render_notice( $content ) {
	static $done = false;
	if ( $done || ! isset( $_GET['cordespace_waitlist_left'] ) || ! is_string( $content ) ) {
		return $content;
	}
	$state = sanitize_text_field( wp_unslash( $_GET['cordespace_waitlist_left'] ) );
	if ( $state === 'ok' ) {
		$html = '<div role="status" style="margin:0 0 1.5rem; padding:1rem 1.2rem; background:#e9f7ec; border:2px solid #2e8b57; border-left:6px solid #2e8b57; border-radius:6px; color:#1c3c2a;">'
			. '<strong>✅ C\'est noté : tu n\'es plus sur la liste d\'attente.</strong></div>';
	} elseif ( $state === 'invalid' ) {
		$html = '<div role="alert" style="margin:0 0 1.5rem; padding:1rem 1.2rem; background:#fdecea; border:2px solid #d63638; border-left:6px solid #d63638; border-radius:6px; color:#3c1c1c;">'
			. '<strong>⚠️ Ce lien n\'est plus valide : l\'inscription en attente n\'existe plus.</strong></div>';
	} else {
		return $content;
	}
	$done = true;
	return $html . $content;
}

// ─── Déclencheurs et planification ──────────────────────────────────────────

const CORDESPACE_WAITLIST_AS_GROUP = 'cordespace-waitlist';
const CORDESPACE_WAITLIST_DELAY    = 600;

/**
 * Cours liés à une réservation (par ses périodes).
 */
function cordespace_waitlist_event_ids_for_booking( int $booking_id ): array {
	global $wpdb;
	$p   = $wpdb->prefix;
	$ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT DISTINCT ep.eventId FROM {$p}amelia_customer_bookings_to_events_periods bp
		 JOIN {$p}amelia_events_periods ep ON ep.id = bp.eventPeriodId
		 WHERE bp.customerBookingId = %d ORDER BY ep.eventId",
		$booking_id
	) );
	return array_values( array_map( 'intval', $ids ?: [] ) );
}

/**
 * Cours liés à une commande WooCommerce (par les paiements Amelia qui la référencent).
 */
function cordespace_waitlist_event_ids_for_order( int $order_id ): array {
	global $wpdb;
	$p   = $wpdb->prefix;
	$ids = $wpdb->get_col( $wpdb->prepare(
		"SELECT DISTINCT ep.eventId FROM {$p}amelia_payments pay
		 JOIN {$p}amelia_customer_bookings_to_events_periods bp ON bp.customerBookingId = pay.customerBookingId
		 JOIN {$p}amelia_events_periods ep ON ep.id = bp.eventPeriodId
		 WHERE pay.wcOrderId = %d ORDER BY ep.eventId",
		$order_id
	) );
	return array_values( array_map( 'intval', $ids ?: [] ) );
}

/**
 * Planifie une vérification du cours dans 10 minutes (une seule en attente par cours).
 * Sans Action Scheduler : vérification immédiate.
 */
function cordespace_waitlist_schedule_check( int $event_id ): void {
	if ( $event_id <= 0 ) {
		return;
	}
	if ( function_exists( 'as_schedule_single_action' ) && function_exists( 'as_has_scheduled_action' ) ) {
		if ( as_has_scheduled_action( 'cordespace_waitlist_check_event', [ $event_id ], CORDESPACE_WAITLIST_AS_GROUP ) ) {
			return;
		}
		as_schedule_single_action( time() + CORDESPACE_WAITLIST_DELAY, 'cordespace_waitlist_check_event', [ $event_id ], CORDESPACE_WAITLIST_AS_GROUP );
		return;
	}
	if ( function_exists( 'cordespace_waitlist_run_check' ) ) {
		cordespace_waitlist_run_check( $event_id );
	}
}

/**
 * Hooks Amelia (réservation mise à jour / supprimée / annulée / enregistrée) :
 * le 2e argument est le tableau de l'événement quand Amelia le fournit (clé `periods`),
 * sinon on retrouve le cours par la réservation.
 */
function cordespace_waitlist_on_amelia_booking_hook( $booking, $second = null ): void {
	$event_ids = [];
	if ( is_array( $second ) && ! empty( $second['id'] ) && array_key_exists( 'periods', $second ) ) {
		$event_ids = [ (int) $second['id'] ];
	} elseif ( is_array( $booking ) && ! empty( $booking['id'] ) ) {
		$event_ids = cordespace_waitlist_event_ids_for_booking( (int) $booking['id'] );
	}
	foreach ( $event_ids as $event_id ) {
		cordespace_waitlist_schedule_check( $event_id );
	}
}

/**
 * Changement de statut d'une commande WooCommerce (annulée, échouée, remboursée, impayée
 * annulée automatiquement…) : Amelia met la réservation à jour sans hook, on vérifie nous-mêmes.
 */
function cordespace_waitlist_on_order_status_changed( $order_id, $from = '', $to = '', $order = null ): void {
	foreach ( cordespace_waitlist_event_ids_for_order( (int) $order_id ) as $event_id ) {
		cordespace_waitlist_schedule_check( $event_id );
	}
}

/**
 * Actions récurrentes : balayage horaire de sécurité, ménage quotidien.
 */
function cordespace_waitlist_register_recurring(): void {
	if ( ! function_exists( 'as_schedule_recurring_action' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
		return;
	}
	if ( ! as_has_scheduled_action( 'cordespace_waitlist_hourly_sweep', [], CORDESPACE_WAITLIST_AS_GROUP ) ) {
		as_schedule_recurring_action( time() + 300, HOUR_IN_SECONDS, 'cordespace_waitlist_hourly_sweep', [], CORDESPACE_WAITLIST_AS_GROUP );
	}
	if ( ! as_has_scheduled_action( 'cordespace_waitlist_daily_cleanup', [], CORDESPACE_WAITLIST_AS_GROUP ) ) {
		as_schedule_recurring_action( time() + 600, DAY_IN_SECONDS, 'cordespace_waitlist_daily_cleanup', [], CORDESPACE_WAITLIST_AS_GROUP );
	}
}

/**
 * Cours approuvés à venir ayant au moins une inscription en attente.
 */
function cordespace_waitlist_events_with_waiting(): array {
	global $wpdb;
	$p   = $wpdb->prefix;
	$ids = $wpdb->get_col(
		"SELECT DISTINCT ep.eventId FROM {$p}amelia_customer_bookings b
		 JOIN {$p}amelia_customer_bookings_to_events_periods bp ON bp.customerBookingId = b.id
		 JOIN {$p}amelia_events_periods ep ON ep.id = bp.eventPeriodId
		 JOIN {$p}amelia_events e ON e.id = ep.eventId
		 WHERE b.status = 'waiting' AND e.status = 'approved' AND ep.periodStart > UTC_TIMESTAMP()
		 ORDER BY ep.eventId"
	);
	return array_values( array_map( 'intval', $ids ?: [] ) );
}

function cordespace_waitlist_hourly_sweep(): void {
	foreach ( cordespace_waitlist_events_with_waiting() as $event_id ) {
		cordespace_waitlist_schedule_check( $event_id );
	}
}

/**
 * Ménage : efface les inscriptions en attente des cours terminés depuis plus de 24 h,
 * puis purge le journal (90 jours). Retourne le nombre d'inscriptions effacées.
 */
function cordespace_waitlist_daily_cleanup(): int {
	global $wpdb;
	$p    = $wpdb->prefix;
	$rows = $wpdb->get_results(
		"SELECT b.id, u.email,
		        (SELECT ep2.eventId FROM {$p}amelia_customer_bookings_to_events_periods bp2
		           JOIN {$p}amelia_events_periods ep2 ON ep2.id = bp2.eventPeriodId
		          WHERE bp2.customerBookingId = b.id LIMIT 1) AS eventId
		 FROM {$p}amelia_customer_bookings b
		 LEFT JOIN {$p}amelia_users u ON u.id = b.customerId
		 WHERE b.status = 'waiting'
		   AND EXISTS (SELECT 1 FROM {$p}amelia_customer_bookings_to_events_periods bp WHERE bp.customerBookingId = b.id)
		   AND NOT EXISTS (SELECT 1 FROM {$p}amelia_customer_bookings_to_events_periods bp
		                     JOIN {$p}amelia_events_periods ep ON ep.id = bp.eventPeriodId
		                    WHERE bp.customerBookingId = b.id AND ep.periodEnd >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY))",
		ARRAY_A
	);
	$n = 0;
	foreach ( $rows ?: [] as $r ) {
		if ( cordespace_waitlist_delete_waiting_booking( (int) $r['id'] ) ) {
			cordespace_waitlist_log( (int) ( $r['eventId'] ?? 0 ), '', (int) $r['id'], (string) ( $r['email'] ?? '' ), 'cleanup' );
			$n++;
		}
	}
	cordespace_waitlist_log_purge( 90 );
	return $n;
}

// ─── La vérification ─────────────────────────────────────────────────────────

/**
 * Efface les inscriptions en attente des personnes qui ont désormais une réservation
 * approved/pending sur ce cours, tous billets confondus. Retourne le nombre effacé.
 */
function cordespace_waitlist_remove_booked( int $event_id, array $bookings ): int {
	$booked = [];
	foreach ( $bookings as $b ) {
		if ( ( $b['status'] === 'approved' || $b['status'] === 'pending' ) && (int) $b['customerId'] > 0 ) {
			$booked[ (int) $b['customerId'] ] = true;
		}
	}
	$seen = [];
	$n    = 0;
	foreach ( $bookings as $b ) {
		$bid = (int) $b['id'];
		if ( $b['status'] !== 'waiting' || ! isset( $booked[ (int) $b['customerId'] ] ) || isset( $seen[ $bid ] ) ) {
			continue;
		}
		$seen[ $bid ] = true; // une inscription peut avoir plusieurs lignes (un par billet)
		if ( cordespace_waitlist_delete_waiting_booking( $bid ) ) {
			cordespace_waitlist_log( $event_id, '', $bid, (string) $b['email'], 'removed' );
			$n++;
		}
	}
	return $n;
}

/**
 * La vérification différée d'un cours : retrait des personnes qui ont réservé, puis, bassin
 * par bassin, annonce aux personnes en attente quand une place vient de se libérer.
 *
 * @return array{skipped:?string,removed:int,announced:int}  skipped : unknown | not_announceable | no_waiting
 */
function cordespace_waitlist_run_check( $event_id ): array {
	$event_id = (int) $event_id;
	$result   = [ 'skipped' => null, 'removed' => 0, 'announced' => 0 ];
	$event    = cordespace_waitlist_event_row( $event_id );
	if ( $event === null ) {
		$result['skipped'] = 'unknown';
		return $result;
	}
	$bookings          = cordespace_waitlist_bookings( $event_id );
	$result['removed'] = cordespace_waitlist_remove_booked( $event_id, $bookings );
	if ( $result['removed'] > 0 ) {
		$bookings = cordespace_waitlist_bookings( $event_id );
	}
	$now = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
	if ( ! cordespace_waitlist_event_is_announceable( $event, $now ) ) {
		$result['skipped'] = 'not_announceable';
		return $result;
	}
	$pools       = cordespace_waitlist_compute_pools( $event, cordespace_waitlist_tickets( $event_id ), $bookings );
	$has_waiting = false;
	$event_url   = null;
	foreach ( $pools as $pool ) {
		$has_waiting = $has_waiting || ! empty( $pool['waiting'] );
		$decision    = cordespace_waitlist_decide( $pool, cordespace_waitlist_state_get( $event_id, $pool['key'] ) );
		$announced   = 0;
		if ( $decision['announce'] ) {
			$event_url = $event_url ?? cordespace_waitlist_event_url( $event );
			foreach ( $pool['waiting'] as $person ) {
				$pid = (int) $person['id'];
				if ( ! is_email( (string) $person['email'] ) || (int) $person['ticketPersons'] > (int) $pool['free'] || cordespace_waitlist_recently_announced( $pid, $pool['key'] ) ) {
					continue;
				}
				$mail = cordespace_waitlist_compose_email( $person, $event, $pool, $event_url, cordespace_waitlist_leave_url( $pid, (string) $person['token'] ) );
				if ( cordespace_mail( (string) $person['email'], $mail['subject'], $mail['body'] ) ) {
					cordespace_waitlist_log( $event_id, $pool['key'], $pid, (string) $person['email'], 'announce' );
					$announced++;
				}
			}
		}
		// L'état est mémorisé même sans personne en attente : il doit refléter « complet / libre »
		// pour que la prochaine libération soit bien vue comme un passage de 0 à 1.
		cordespace_waitlist_state_set( $event_id, $pool['key'], $decision['free_spots'], $announced > 0 );
		$result['announced'] += $announced;
	}
	if ( ! $has_waiting ) {
		$result['skipped'] = 'no_waiting';
	}
	return $result;
}

/**
 * Nombre d'inscriptions en attente d'un cours (vue de présence des profs).
 */
function cordespace_waitlist_count_for_event( int $event_id ): int {
	global $wpdb;
	$p = $wpdb->prefix;
	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(DISTINCT b.id) FROM {$p}amelia_customer_bookings b
		 JOIN {$p}amelia_customer_bookings_to_events_periods bp ON bp.customerBookingId = b.id
		 JOIN {$p}amelia_events_periods ep ON ep.id = bp.eventPeriodId
		 WHERE ep.eventId = %d AND b.status = 'waiting'",
		$event_id
	) );
}

add_action( 'template_redirect', 'cordespace_waitlist_handle_leave', 1 );
add_filter( 'the_content', 'cordespace_waitlist_render_notice' );
add_action( 'amelia_after_event_booking_updated', 'cordespace_waitlist_on_amelia_booking_hook', 10, 2 );
add_action( 'amelia_after_event_booking_deleted', 'cordespace_waitlist_on_amelia_booking_hook', 10, 2 );
add_action( 'amelia_before_booking_canceled', 'cordespace_waitlist_on_amelia_booking_hook', 10, 1 );
add_action( 'amelia_after_event_booking_saved', 'cordespace_waitlist_on_amelia_booking_hook', 10, 2 );
add_action( 'woocommerce_order_status_changed', 'cordespace_waitlist_on_order_status_changed', 20, 4 );
add_action( 'init', 'cordespace_waitlist_register_recurring' );
add_action( 'cordespace_waitlist_check_event', 'cordespace_waitlist_run_check' );
add_action( 'cordespace_waitlist_hourly_sweep', 'cordespace_waitlist_hourly_sweep' );
add_action( 'cordespace_waitlist_daily_cleanup', 'cordespace_waitlist_daily_cleanup' );
