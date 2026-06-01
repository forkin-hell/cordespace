<?php
/**
 * Module : mon-espace.teacher-presence
 *
 * Toggle présence prof + shortcode [cordespace_today_students].
 *
 * - Source de vérité du check-in : le JSON `qrCodes` du booking Amelia, qui
 *   stocke `qrCodes[].dates[YYYY-MM-DD] = true` pour chaque scan. Notre
 *   toggle écrit exactement la même chose que ferait le scanner QR natif
 *   d'Amelia, donc les deux flux sont parfaitement synchronisés.
 * - Endpoint REST POST /wp-json/cordespace/v1/checkin
 * - Shortcode [cordespace_today_students] = liste des élèves inscrit·es
 *   aux cours du prof connecté dans les 24h, avec switch iOS-style pour
 *   marquer présent·e en un clic (pas de QR scan requis)
 * - REST endpoint sécurisé : un·e prof ne peut toggler que ses propres élèves
 *
 * Dépendances : Amelia. Le helper cordespace_user_is_amelia_provider() vit
 * dans includes/core/helpers.php (toujours chargé).
 *
 * Historique : avant on stockait dans une table custom wp_cordespace_checkins.
 * On a basculé sur le JSON Amelia pour avoir UNE source de vérité partagée
 * avec le scanner natif. La table reste en place (no-op) pour ne pas casser
 * d'éventuelles installations historiques — on peut la dropper plus tard.
 */

defined( 'ABSPATH' ) || exit;

// ============================================================================
// 1) Helpers de lecture/écriture du JSON qrCodes Amelia
// ============================================================================

/**
 * Renvoie le JSON qrCodes décodé d'un booking Amelia (array ou []).
 */
function cordespace_get_booking_qrcodes( int $booking_id ): array {
	global $wpdb;
	$json = $wpdb->get_var( $wpdb->prepare(
		"SELECT qrCodes FROM {$wpdb->prefix}amelia_customer_bookings WHERE id = %d",
		$booking_id
	) );
	if ( ! $json ) {
		return [];
	}
	$data = json_decode( $json, true );
	return is_array( $data ) ? $data : [];
}

/**
 * Sauve le JSON qrCodes modifié dans le booking Amelia.
 */
function cordespace_save_booking_qrcodes( int $booking_id, array $qrCodes ): bool {
	global $wpdb;
	$encoded = wp_json_encode( $qrCodes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	$result  = $wpdb->update(
		$wpdb->prefix . 'amelia_customer_bookings',
		[ 'qrCodes' => $encoded ],
		[ 'id' => $booking_id ],
		[ '%s' ],
		[ '%d' ]
	);
	return $result !== false;
}

/**
 * Convertit un periodStart Amelia (en UTC dans la DB) en date locale Y-m-d
 * dans le fuseau WordPress. C'est la date qu'on inscrira comme clé dans
 * qrCodes[].dates[$date] = true (cohérent avec ce que ferait le scanner Amelia).
 */
function cordespace_event_scan_date( string $period_start_utc ): string {
	$ts = strtotime( $period_start_utc . ' UTC' );
	if ( ! $ts ) {
		return wp_date( 'Y-m-d' );
	}
	return wp_date( 'Y-m-d', $ts );
}

// ============================================================================
// 2) Helpers CRUD check-in (lit/écrit dans qrCodes JSON Amelia)
// ============================================================================

/**
 * Le booking est-il marqué comme présent pour la date donnée ?
 *
 * @param int    $booking_id ID du booking Amelia.
 * @param string $scan_date  Date Y-m-d. Si vide, utilise la date locale du jour.
 */
function cordespace_is_checked_in( int $booking_id, string $scan_date = '' ): bool {
	if ( $scan_date === '' ) {
		$scan_date = wp_date( 'Y-m-d' );
	}
	$qrCodes = cordespace_get_booking_qrcodes( $booking_id );
	foreach ( $qrCodes as $qr ) {
		if ( isset( $qr['dates'][ $scan_date ] ) && $qr['dates'][ $scan_date ] === true ) {
			return true;
		}
	}
	return false;
}

/**
 * Marque le booking présent pour la date donnée. Modifie tous les tickets du
 * booking (cohérent avec le comportement de scan « booking » d'Amelia).
 *
 * @param int    $booking_id  ID du booking Amelia.
 * @param int    $by_user_id  ID du WP user qui fait l'action (réservé pour usage futur).
 * @param string $scan_date   Date Y-m-d. Si vide, date locale du jour.
 */
function cordespace_check_in( int $booking_id, int $by_user_id, string $scan_date = '' ): bool {
	if ( $scan_date === '' ) {
		$scan_date = wp_date( 'Y-m-d' );
	}
	$qrCodes = cordespace_get_booking_qrcodes( $booking_id );
	if ( empty( $qrCodes ) ) {
		return false;
	}
	foreach ( $qrCodes as &$qr ) {
		if ( ! isset( $qr['dates'] ) || ! is_array( $qr['dates'] ) ) {
			$qr['dates'] = [];
		}
		$qr['dates'][ $scan_date ] = true;
	}
	unset( $qr );
	return cordespace_save_booking_qrcodes( $booking_id, $qrCodes );
}

/**
 * Marque le booking comme NON présent pour la date donnée. Met à false plutôt
 * que de supprimer la clé : Amelia traite `=== true` comme « scanné », donc
 * `false` = « non scanné ». Permet aussi de garder une trace audit visuelle
 * dans le JSON (« cette date a été touchée à un moment »).
 */
function cordespace_uncheck_in( int $booking_id, string $scan_date = '' ): bool {
	if ( $scan_date === '' ) {
		$scan_date = wp_date( 'Y-m-d' );
	}
	$qrCodes = cordespace_get_booking_qrcodes( $booking_id );
	if ( empty( $qrCodes ) ) {
		return false;
	}
	foreach ( $qrCodes as &$qr ) {
		if ( isset( $qr['dates'][ $scan_date ] ) ) {
			$qr['dates'][ $scan_date ] = false;
		}
	}
	unset( $qr );
	return cordespace_save_booking_qrcodes( $booking_id, $qrCodes );
}

// ============================================================================
// 3) Events du prof dans les 24h, avec ses élèves
// ============================================================================
function cordespace_get_prof_today_events( int $wp_user_id ): array {
	global $wpdb;

	$amelia_provider_id = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM {$wpdb->prefix}amelia_users
		  WHERE externalId = %d
		    AND type IN ('provider', 'manager')
		    AND status = 'visible'
		  LIMIT 1",
		$wp_user_id
	) );
	if ( $amelia_provider_id <= 0 ) {
		return [];
	}

	$now    = current_time( 'mysql', true );
	$in_24h = gmdate( 'Y-m-d H:i:s', time() + 24 * HOUR_IN_SECONDS );

	$events = $wpdb->get_results( $wpdb->prepare(
		"SELECT DISTINCT e.id AS event_id, e.name, ep.id AS period_id,
				ep.periodStart, ep.periodEnd
		   FROM {$wpdb->prefix}amelia_events e
		   JOIN {$wpdb->prefix}amelia_events_to_providers etp ON etp.eventId = e.id
		   JOIN {$wpdb->prefix}amelia_events_periods ep ON ep.eventId = e.id
		  WHERE etp.userId = %d
		    AND ep.periodStart <= %s
		    AND ep.periodEnd   >= %s
		  ORDER BY ep.periodStart ASC",
		$amelia_provider_id,
		$in_24h,
		$now
	), ARRAY_A );

	if ( empty( $events ) ) {
		return [];
	}

	foreach ( $events as &$event ) {
		$bookings = $wpdb->get_results( $wpdb->prepare(
			"SELECT b.id AS booking_id, b.status,
					u.firstName, u.lastName, u.email,
					COALESCE((SELECT SUM(bt.persons)
					            FROM {$wpdb->prefix}amelia_customer_bookings_to_events_tickets bt
					           WHERE bt.customerBookingId = b.id), 1) AS persons,
					(SELECT p.wcOrderId
					   FROM {$wpdb->prefix}amelia_payments p
					  WHERE p.customerBookingId = b.id
					    AND p.wcOrderId IS NOT NULL
					  ORDER BY p.id DESC
					  LIMIT 1) AS wc_order_id,
					COALESCE((SELECT p.amount
					   FROM {$wpdb->prefix}amelia_payments p
					  WHERE p.customerBookingId = b.id
					  ORDER BY p.id DESC
					  LIMIT 1), 0) AS booking_amount,
					COALESCE((SELECT SUM(bt.persons * bt.price)
					   FROM {$wpdb->prefix}amelia_customer_bookings_to_events_tickets bt
					  WHERE bt.customerBookingId = b.id), 0) AS ticket_face_value
			   FROM {$wpdb->prefix}amelia_customer_bookings_to_events_periods bep
			   JOIN {$wpdb->prefix}amelia_customer_bookings b ON b.id = bep.customerBookingId
			   JOIN {$wpdb->prefix}amelia_users u ON u.id = b.customerId
			  WHERE bep.eventPeriodId = %d
			    AND b.status IN ('approved', 'pending')
			  ORDER BY u.firstName, u.lastName",
			$event['period_id']
		), ARRAY_A );

		// Date locale du périodStart : c'est cette date qu'on inscrit dans
		// qrCodes[].dates pour rester cohérent avec ce que ferait le scanner
		// Amelia natif.
		$scan_date = cordespace_event_scan_date( $event['periodStart'] );
		$event['scan_date'] = $scan_date;

		foreach ( $bookings as &$b ) {
			$b['is_checked_in'] = cordespace_is_checked_in( (int) $b['booking_id'], $scan_date );
			$b['scan_date']     = $scan_date;
		}
		unset( $b );

		$event['bookings'] = $bookings;
	}
	unset( $event );

	return $events;
}

// ============================================================================
// 4) Sécurité
// ============================================================================
function cordespace_user_can_check_in( int $wp_user_id, int $booking_id ): bool {
	if ( $wp_user_id <= 0 || $booking_id <= 0 ) return false;
	global $wpdb;

	$provider_id = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM {$wpdb->prefix}amelia_users
		  WHERE externalId = %d AND type IN ('provider', 'manager') AND status = 'visible'
		  LIMIT 1",
		$wp_user_id
	) );
	if ( $provider_id <= 0 ) return false;

	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*)
		   FROM {$wpdb->prefix}amelia_customer_bookings b
		   JOIN {$wpdb->prefix}amelia_customer_bookings_to_events_periods bep ON bep.customerBookingId = b.id
		   JOIN {$wpdb->prefix}amelia_events_periods ep ON ep.id = bep.eventPeriodId
		   JOIN {$wpdb->prefix}amelia_events_to_providers etp ON etp.eventId = ep.eventId
		  WHERE b.id = %d AND etp.userId = %d",
		$booking_id, $provider_id
	) ) > 0;
}

// ============================================================================
// 5) REST endpoint
// ============================================================================
add_action( 'rest_api_init', function () {
	register_rest_route( 'cordespace/v1', '/checkin', [
		'methods'             => 'POST',
		'callback'            => 'cordespace_rest_toggle_checkin',
		'permission_callback' => function () { return is_user_logged_in(); },
		'args'                => [
			'booking_id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ],
			'present'    => [ 'required' => true, 'type' => 'boolean' ],
			'scan_date'  => [
				'required'          => false,
				'type'               => 'string',
				'sanitize_callback'  => function ( $v ) {
					$v = is_string( $v ) ? trim( $v ) : '';
					// Format Y-m-d strict, sinon on retombe sur "" (=> default côté handler)
					return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : '';
				},
			],
		],
	] );

	// Endpoint léger pour le polling temps réel — retourne juste l'état actuel
	// des bookings du prof connecté, format compact { booking_id: bool, ... }.
	// Utilisé par le JS pour rafraîchir les toggles toutes les 5s sans reload.
	register_rest_route( 'cordespace/v1', '/checkin-state', [
		'methods'             => 'GET',
		'callback'            => 'cordespace_rest_checkin_state',
		'permission_callback' => function () { return is_user_logged_in(); },
	] );
} );

/**
 * GET /wp-json/cordespace/v1/checkin-state
 * → { "117": { "present": true, "status": "approved" }, ... }
 *
 * Renvoie l'état actuel des bookings visibles dans la vue du prof connecté :
 *   - present  : true/false (toggle de check-in)
 *   - status   : 'pending' / 'approved' / autre (statut Amelia → reflète le
 *                paiement WC : tant qu'un order WC est pending, le booking
 *                Amelia est pending ; quand l'order passe à completed/processing,
 *                l'integration Amelia upgrade le booking à approved)
 *
 * Utilisé par le polling JS pour rafraîchir les toggles ET les badges
 * 'PAIEMENT À VALIDER' sans reload de page.
 */
function cordespace_rest_checkin_state( $request ) {
	$user_id = get_current_user_id();
	if ( $user_id <= 0 ) {
		return [];
	}
	$events = cordespace_get_prof_today_events( $user_id );
	$state  = [];
	foreach ( $events as $event ) {
		foreach ( ( $event['bookings'] ?? [] ) as $b ) {
			$state[ (string) $b['booking_id'] ] = [
				'present' => (bool) $b['is_checked_in'],
				'status'  => (string) ( $b['status'] ?? '' ),
			];
		}
	}
	return $state;
}

function cordespace_rest_toggle_checkin( $request ) {
	$booking_id = (int) $request->get_param( 'booking_id' );
	$present    = (bool) $request->get_param( 'present' );
	$scan_date  = (string) $request->get_param( 'scan_date' );
	$user_id    = get_current_user_id();

	if ( ! cordespace_user_can_check_in( $user_id, $booking_id ) ) {
		return new WP_Error( 'forbidden', 'Tu n\'as pas les droits sur ce booking.', [ 'status' => 403 ] );
	}

	if ( $present ) {
		$ok = cordespace_check_in( $booking_id, $user_id, $scan_date );
		if ( ! $ok ) {
			return new WP_Error( 'qr_missing', 'Ce booking n\'a pas de QR codes Amelia générés.', [ 'status' => 422 ] );
		}
		return [ 'success' => true, 'state' => 'present' ];
	} else {
		$ok = cordespace_uncheck_in( $booking_id, $scan_date );
		if ( ! $ok ) {
			return new WP_Error( 'qr_missing', 'Ce booking n\'a pas de QR codes Amelia générés.', [ 'status' => 422 ] );
		}
		return [ 'success' => true, 'state' => 'not_present' ];
	}
}

// ============================================================================
// 6) Shortcode [cordespace_today_students] — UI prof avec switch iOS
// ============================================================================
add_shortcode( 'cordespace_today_students', 'cordespace_render_today_students' );

function cordespace_render_today_students( $atts ) {
	if ( ! is_user_logged_in() ) return '';

	$user   = wp_get_current_user();
	$events = cordespace_get_prof_today_events( $user->ID );

	if ( empty( $events ) ) {
		return '<div style="padding:1.2rem;background:#f7f7f7;border-radius:6px;color:#555;font-size:0.95em;">📭 Aucun cours à enseigner dans les prochaines 24h.</div>';
	}

	ob_start();
	?>
	<div class="cordespace-today-students-wrapper">
		<?php foreach ( $events as $event ) :
			// Amelia stocke periodStart/End en UTC. On convertit en timezone WP
			// (Montréal) avant de formater pour afficher les heures locales.
			$when  = mysql2date( 'l j F — H\hi', get_date_from_gmt( $event['periodStart'] ) );
			$end   = mysql2date( 'H\hi',          get_date_from_gmt( $event['periodEnd']   ) );
			// On compte les PERSONNES (somme des billets), pas les réservations,
			// pour qu'un booking de groupe (Marie ×3) compte pour 3 et pas 1.
			$total = (int) array_sum( array_map( fn($b) => (int) ( $b['persons'] ?? 1 ), $event['bookings'] ) );
			$pres  = (int) array_sum( array_map(
				fn($b) => ! empty( $b['is_checked_in'] ) ? (int) ( $b['persons'] ?? 1 ) : 0,
				$event['bookings']
			) );
		?>
			<details class="cordespace-event-block" open style="margin-bottom:1.8rem;padding:1.2rem 1.4rem;background:#fafafa;border-radius:8px;border:1px solid #e0e0e0;">
				<summary style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:0.6rem;margin-bottom:0.8rem;padding-bottom:0.8rem;border-bottom:1px solid #e5e5e5;cursor:pointer;list-style:none;">
					<div style="display:flex;align-items:center;gap:0.6rem;flex:1;min-width:200px;">
						<span class="cordespace-disclosure" aria-hidden="true" style="font-size:0.85em;color:#999;display:inline-block;width:1em;text-align:center;transition:transform 0.18s ease;">▼</span>
						<div>
							<div style="font-size:1.1em;font-weight:700;color:#1d4d7e;"><?php echo esc_html( $event['name'] ); ?></div>
							<div style="font-size:0.9em;color:#666;margin-top:0.2rem;">📅 <?php echo esc_html( $when ); ?> → <?php echo esc_html( $end ); ?></div>
						</div>
					</div>
					<div class="cordespace-counter" style="background:#5b2c8f;color:#fff;padding:0.4rem 0.9rem;border-radius:99px;font-size:0.9em;font-weight:600;white-space:nowrap;">
						<?php echo (int) $pres; ?> / <?php echo (int) $total; ?> présent·e·s
					</div>
				</summary>

				<?php if ( empty( $event['bookings'] ) ) : ?>
					<p style="color:#999;font-style:italic;margin:0.6rem 0 0;">Aucune inscription pour ce cours.</p>
				<?php else : ?>
					<ul style="list-style:none;margin:0;padding:0;">
						<?php foreach ( $event['bookings'] as $b ) :
							$is_present   = (bool) $b['is_checked_in'];
							$is_pending   = ( $b['status'] === 'pending' );
							$display_name = trim( ( $b['firstName'] ?? '' ) . ' ' . ( $b['lastName'] ?? '' ) ) ?: $b['email'];
						?>
							<li style="padding:0.7rem 0;border-bottom:1px solid #f0f0f0;display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
								<div style="flex:1;min-width:200px;">
									<div style="font-weight:600;color:#333;line-height:1.4;">
										<?php echo esc_html( $display_name ); ?>
										<?php $persons = (int) ( $b['persons'] ?? 1 ); if ( $persons > 1 ) : ?>
											<span class="cordespace-persons-badge" style="display:inline-block;background:#e9d8f5;color:#5b2c8f;font-size:0.75em;padding:0.15rem 0.45rem;border-radius:4px;margin-left:0.35rem;font-weight:700;letter-spacing:0.2px;" title="Nombre de billets pour cette réservation">🎫 ×<?php echo $persons; ?></span>
										<?php endif; ?>
										<?php if ( $is_pending ) : ?>
											<span class="cordespace-pending-badge" style="display:inline-block;background:#f5b800;color:#3a2c00;font-size:0.7em;padding:0.15rem 0.5rem;border-radius:4px;margin-left:0.4rem;font-weight:700;letter-spacing:0.3px;">⏳ PAIEMENT À VALIDER<?php
											$wc_order_id = (int) ( $b['wc_order_id'] ?? 0 );
											if ( $wc_order_id > 0 ) {
												echo ' #' . $wc_order_id;
											}
											?></span>
										<?php endif; ?>
										<?php
										// Priorité d'affichage :
										//   1. payment.amount > 0  → personne A PAYÉ via WC (Interac/CB/crédits) → badge vert
										//   2. tickets price × persons > 0 → réservation Amelia onSite, NON encaissée → badge orange "💵 SUR PLACE"
										//   3. les 2 à zéro → vraie invitation / billet gratuit → aucun badge
										$paid_amount = (float) ( $b['booking_amount']     ?? 0 );
										$face_value  = (float) ( $b['ticket_face_value']  ?? 0 );
										if ( $paid_amount > 0 ) : ?>
											<span class="cordespace-amount-badge" style="display:inline-block;background:#e8f5e9;color:#1b5e20;font-size:0.75em;padding:0.15rem 0.45rem;border-radius:4px;margin-left:0.35rem;font-weight:600;letter-spacing:0.2px;" title="Montant payé via WooCommerce (incl. taxes)">💰 <?php echo number_format( $paid_amount, 2, ',', ' ' ); ?> $</span>
										<?php elseif ( $face_value > 0 ) : ?>
											<span class="cordespace-amount-onsite-badge" style="display:inline-block;background:#fff3cd;color:#856404;font-size:0.75em;padding:0.15rem 0.45rem;border-radius:4px;margin-left:0.35rem;font-weight:700;letter-spacing:0.2px;" title="Réservation Amelia avec paiement « sur place » (gateway onSite) — pas de transaction WooCommerce. À encaisser pendant le cours.">💵 <?php echo number_format( $face_value, 2, ',', ' ' ); ?> $ SUR PLACE</span>
										<?php endif; ?>
									</div>
									<div style="font-size:0.85em;color:#888;margin-top:0.1rem;"><?php echo esc_html( $b['email'] ); ?></div>
								</div>

								<label class="cordespace-checkin-switch" style="display:inline-flex;align-items:center;gap:0.7rem;cursor:pointer;user-select:none;">
									<span class="cordespace-switch-label" style="font-size:0.92em;font-weight:600;min-width:90px;text-align:right;color:<?php echo $is_present ? '#5b2c8f' : '#888'; ?>;">
										<?php echo $is_present ? '✅ Présent·e' : 'À venir'; ?>
									</span>
									<input type="checkbox"
										   class="cordespace-checkin-btn"
										   data-booking-id="<?php echo (int) $b['booking_id']; ?>"
										   data-scan-date="<?php echo esc_attr( $b['scan_date'] ); ?>"
										   data-state="<?php echo $is_present ? 'present' : 'not_present'; ?>"
										   <?php checked( $is_present ); ?>
										   style="position:absolute;opacity:0;pointer-events:none;">
									<span class="cordespace-switch-track" style="display:inline-block;width:52px;height:30px;background:<?php echo $is_present ? '#5b2c8f' : '#d0d0d0'; ?>;border-radius:15px;position:relative;transition:background 0.25s ease;flex-shrink:0;">
										<span class="cordespace-switch-thumb" style="display:block;width:24px;height:24px;background:#fff;border-radius:50%;position:absolute;top:3px;left:<?php echo $is_present ? '25px' : '3px'; ?>;transition:left 0.25s cubic-bezier(0.4, 0, 0.2, 1);box-shadow:0 1px 3px rgba(0,0,0,0.2);"></span>
									</span>
								</label>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</details>
		<?php endforeach; ?>
	</div>

	<style>
		/* Cache le marqueur natif de <details> dans tous les navigateurs */
		.cordespace-today-students-wrapper details.cordespace-event-block > summary::-webkit-details-marker { display:none; }
		.cordespace-today-students-wrapper details.cordespace-event-block > summary { list-style:none; }
		/* Chevron qui pivote : pointe vers le bas quand ouvert, vers la droite quand fermé */
		.cordespace-today-students-wrapper details.cordespace-event-block:not([open]) .cordespace-disclosure { transform: rotate(-90deg); }
		.cordespace-today-students-wrapper details.cordespace-event-block[open] .cordespace-disclosure { transform: rotate(0deg); }
		/* Réduit l'espacement vertical quand un cours est replié (pas de border-bottom sur summary) */
		.cordespace-today-students-wrapper details.cordespace-event-block:not([open]) > summary { margin-bottom:0; padding-bottom:0; border-bottom:none; }

		/* ===================== Mobile (<= 600px) ===================== */
		@media (max-width: 600px) {
			/* Bloc cours plus compact */
			.cordespace-today-students-wrapper details.cordespace-event-block {
				padding: 0.8rem 1rem !important;
				margin-bottom: 1rem !important;
			}
			/* Compteur "X/Y présent·e·s" un peu plus petit */
			.cordespace-today-students-wrapper .cordespace-counter {
				font-size: 0.8em !important;
				padding: 0.3rem 0.7rem !important;
			}
			/* Ligne élève compacte : nom à gauche + toggle à droite, JAMAIS de wrap */
			.cordespace-today-students-wrapper li {
				padding: 0.55rem 0 !important;
				gap: 0.7rem !important;
				flex-wrap: nowrap !important;
			}
			.cordespace-today-students-wrapper li > div:first-child {
				min-width: 0 !important;
				flex: 1 1 auto !important;
				overflow: hidden;
			}
			/* Nom : autorise le wrap pour que le badge "PAIEMENT À VALIDER"
			   passe à la ligne sous le nom plutôt que d'être tronqué en "…". */
			.cordespace-today-students-wrapper li > div:first-child > div:first-child {
				font-size: 0.9em !important;
			}
			/* Badge compact en mobile pour qu'il tienne souvent sur la même ligne que le nom */
			.cordespace-today-students-wrapper li > div:first-child > div:first-child > span {
				font-size: 0.62em !important;
				padding: 0.1rem 0.4rem !important;
				margin-left: 0.3rem !important;
				white-space: nowrap;
			}
			/* Email : tronqué (1 ligne max), souvent long */
			.cordespace-today-students-wrapper li > div:first-child > div:last-child {
				font-size: 0.78em !important;
				white-space: nowrap;
				overflow: hidden;
				text-overflow: ellipsis;
			}
			/* Cache le label texte "À venir / Présent·e" — le toggle parle de lui-même */
			.cordespace-today-students-wrapper .cordespace-switch-label {
				display: none !important;
			}
		}
	</style>

	<!-- canvas-confetti pour l'animation quand le dernier élève d'un cours
	     est marqué présent. Lib légère (4 KB) chargée depuis jsdelivr CDN.
	     Si la lib échoue à charger (CDN down, hors-ligne), le toggle continue
	     à fonctionner — juste pas de confetti. -->
	<script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.9.3/dist/confetti.browser.min.js" defer></script>

	<script>
	(function () {
		var nonce    = <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>;
		var endpoint = <?php echo wp_json_encode( rest_url( 'cordespace/v1/checkin' ) ); ?>;

		document.addEventListener('click', function (e) {
			var label = e.target.closest('.cordespace-checkin-switch');
			if (!label) return;
			e.preventDefault(); // empêche le toggle natif, on gère manuellement

			var btn        = label.querySelector('.cordespace-checkin-btn');
			var labelText  = label.querySelector('.cordespace-switch-label');
			var track      = label.querySelector('.cordespace-switch-track');
			var thumb      = track.querySelector('.cordespace-switch-thumb');
			var bookingId  = parseInt(btn.dataset.bookingId, 10);
			var scanDate   = btn.dataset.scanDate || '';
			var current    = btn.dataset.state;
			var newPresent = (current !== 'present');

			// Animation visuelle optimiste (avant la réponse serveur)
			track.style.opacity = '0.6';

			fetch(endpoint, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
				body: JSON.stringify({ booking_id: bookingId, present: newPresent, scan_date: scanDate }),
			})
			.then(function (r) { return r.json(); })
			.then(function (data) {
				if (data && data.success) {
					if (newPresent) {
						btn.dataset.state = 'present';
						btn.checked = true;
						labelText.textContent = '✅ Présent·e';
						labelText.style.color = '#5b2c8f';
						track.style.background = '#5b2c8f';
						thumb.style.left = '25px';
					} else {
						btn.dataset.state = 'not_present';
						btn.checked = false;
						labelText.textContent = 'À venir';
						labelText.style.color = '#888';
						track.style.background = '#d0d0d0';
						thumb.style.left = '3px';
					}
					updateCounters();
					// Si on vient de marquer la dernière personne du cours présente → confettis !
					if (newPresent) {
						maybeCelebrateEventCompletion(btn);
					}
				} else {
					alert('Erreur : ' + (data && data.message ? data.message : 'inconnue'));
				}
			})
			.catch(function (err) { alert('Erreur réseau : ' + err.message); })
			.finally(function () { track.style.opacity = '1'; });
		});

		function updateCounters() {
			document.querySelectorAll('.cordespace-event-block').forEach(function (blk) {
				var all = blk.querySelectorAll('.cordespace-checkin-btn').length;
				var pres = blk.querySelectorAll('.cordespace-checkin-btn[data-state="present"]').length;
				var c = blk.querySelector('.cordespace-counter');
				if (c) c.textContent = pres + ' / ' + all + ' présent·e·s';
			});
		}

		// ============================================================
		// Confettis quand un cours est complet (tous les élèves présents)
		// ============================================================
		function maybeCelebrateEventCompletion(triggerBtn) {
			var eventBlock = triggerBtn.closest('.cordespace-event-block');
			if (!eventBlock) return;
			var all = eventBlock.querySelectorAll('.cordespace-checkin-btn').length;
			var pres = eventBlock.querySelectorAll('.cordespace-checkin-btn[data-state="present"]').length;
			if (all === 0 || pres < all) return;
			// Cours complet ! On dégaine les confettis.
			fireConfettiBurst(eventBlock);
		}

		function fireConfettiBurst(eventBlock) {
			if (typeof confetti !== 'function') return; // lib pas (encore) chargée → silencieux
			// Origine : bas-centre du viewport, toujours visible peu importe où la prof est scrollée.
			// y=0.95 = juste au-dessus du bord bas → les particules explosent vers le haut.
			var origin = { x: 0.5, y: 0.95 };
			var colors = ['#5b2c8f', '#f5b800', '#1d4d7e', '#ffffff', '#c084ed'];
			// Premier burst : large et puissant, explose vers le haut
			confetti({
				particleCount: 90,
				spread: 80,
				origin: origin,
				colors: colors,
				ticks: 220,
				gravity: 1.0,
				scalar: 1.05,
				startVelocity: 55,
			});
			// Deuxième burst 220ms plus tard pour un effet "double pop"
			setTimeout(function () {
				if (typeof confetti !== 'function') return;
				confetti({
					particleCount: 50,
					spread: 120,
					origin: origin,
					colors: colors,
					startVelocity: 45,
					ticks: 240,
					scalar: 0.85,
				});
			}, 220);
		}

		// ============================================================
		// Polling temps réel — rafraîchit l'état des toggles toutes les 5s
		// ============================================================
		var stateEndpoint = <?php echo wp_json_encode( rest_url( 'cordespace/v1/checkin-state' ) ); ?>;
		var POLL_INTERVAL_MS = 5000;
		var pollTimer = null;
		var pollFailures = 0;
		var MAX_FAILURES = 5;

		function applyState(state) {
			if (!state || typeof state !== 'object') return;
			document.querySelectorAll('.cordespace-checkin-btn').forEach(function (btn) {
				var bookingId = String(btn.dataset.bookingId);
				if (!(bookingId in state)) return;

				var entry = state[bookingId];
				// Compat ancien format { id: bool } au cas où le serveur n'a pas
				// encore été redéployé : on lit aussi le boolean direct.
				var serverPresent, serverStatus;
				if (typeof entry === 'object' && entry !== null) {
					serverPresent = !!entry.present;
					serverStatus  = String(entry.status || '');
				} else {
					serverPresent = !!entry;
					serverStatus  = null;
				}

				// Ne pas écraser un toggle en mid-transition (track.opacity < 1)
				var label = btn.closest('.cordespace-checkin-switch');
				if (!label) return;
				var track = label.querySelector('.cordespace-switch-track');
				if (track && track.style.opacity && parseFloat(track.style.opacity) < 1) return;

				// === SYNC TOGGLE PRÉSENCE ===
				var domPresent = (btn.dataset.state === 'present');
				if (serverPresent !== domPresent) {
					var labelText = label.querySelector('.cordespace-switch-label');
					var thumb = track.querySelector('.cordespace-switch-thumb');
					if (serverPresent) {
						btn.dataset.state = 'present';
						btn.checked = true;
						labelText.textContent = '✅ Présent·e';
						labelText.style.color = '#5b2c8f';
						track.style.background = '#5b2c8f';
						thumb.style.left = '25px';
					} else {
						btn.dataset.state = 'not_present';
						btn.checked = false;
						labelText.textContent = 'À venir';
						labelText.style.color = '#888';
						track.style.background = '#d0d0d0';
						thumb.style.left = '3px';
					}
				}

				// === SYNC BADGE PENDING (PAIEMENT À VALIDER) ===
				if (serverStatus !== null) {
					// On localise la ligne <li> de cette personne pour gérer son badge
					var li = btn.closest('li');
					if (li) {
						var existingBadge = li.querySelector('.cordespace-pending-badge');
						var shouldShowPending = (serverStatus === 'pending');

						if (shouldShowPending && !existingBadge) {
							// Badge à AJOUTER : le statut est passé à pending (rare,
							// mais possible si un staff l'a remis manuellement)
							var nameDiv = li.querySelector('div > div:first-child');
							if (nameDiv) {
								var newBadge = document.createElement('span');
								newBadge.className = 'cordespace-pending-badge';
								newBadge.setAttribute('style', 'display:inline-block;background:#f5b800;color:#3a2c00;font-size:0.7em;padding:0.15rem 0.5rem;border-radius:4px;margin-left:0.4rem;font-weight:700;letter-spacing:0.3px;');
								newBadge.textContent = '⏳ PAIEMENT À VALIDER';
								nameDiv.appendChild(document.createTextNode(' '));
								nameDiv.appendChild(newBadge);
							}
						} else if (!shouldShowPending && existingBadge) {
							// Badge à RETIRER : le paiement vient d'être validé
							// (status passé de 'pending' à 'approved')
							existingBadge.remove();
						}
					}
				}
			});
			updateCounters();
		}

		function poll() {
			if (document.hidden) return; // onglet pas visible, on saute ce cycle
			fetch(stateEndpoint, {
				method: 'GET',
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': nonce },
			})
			.then(function (r) {
				if (!r.ok) throw new Error('HTTP ' + r.status);
				return r.json();
			})
			.then(function (state) {
				pollFailures = 0;
				applyState(state);
			})
			.catch(function () {
				pollFailures++;
				if (pollFailures >= MAX_FAILURES) {
					// Arrêter le polling après 5 échecs consécutifs (évite de
					// spammer un serveur en panne). Reload de page = repart à zéro.
					clearInterval(pollTimer);
					pollTimer = null;
				}
			});
		}

		// Démarre le polling. Pause auto via Page Visibility API : si l'onglet
		// passe en background, la fonction poll() ne fait rien. À l'inverse,
		// dès que la page redevient visible on poll tout de suite (rattrape).
		pollTimer = setInterval(poll, POLL_INTERVAL_MS);
		document.addEventListener('visibilitychange', function () {
			if (!document.hidden && pollTimer) poll();
		});
	})();
	</script>
	<?php
	return ob_get_clean();
}

