<?php
/**
 * Module : mon-espace.teacher-presence
 *
 * Toggle présence prof + shortcode [cordespace_today_students].
 *
 * - Crée la table wp_cordespace_checkins (idempotent via dbDelta)
 * - Endpoint REST POST /wp-json/cordespace/v1/checkin
 * - Shortcode [cordespace_today_students] = liste des élèves inscrit·es
 *   aux cours du prof connecté dans les 24h, avec switch iOS-style pour
 *   marquer présent·e en un clic (pas de QR scan requis)
 * - REST endpoint sécurisé : un·e prof ne peut toggler que ses propres élèves
 *
 * Dépendances : Amelia. Le helper cordespace_user_is_amelia_provider() vit
 * dans includes/core/helpers.php (toujours chargé).
 */

defined( 'ABSPATH' ) || exit;

// ============================================================================
// 1) Table custom pour les check-ins
// ============================================================================
function cordespace_checkin_table() {
	global $wpdb;
	return $wpdb->prefix . 'cordespace_checkins';
}

function cordespace_create_checkin_table() {
	global $wpdb;
	$table = cordespace_checkin_table();
	if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table ) {
		return;
	}
	$charset_collate = $wpdb->get_charset_collate();
	$sql = "CREATE TABLE {$table} (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		amelia_booking_id INT NOT NULL,
		checked_in_at DATETIME NOT NULL,
		checked_in_by_user_id BIGINT UNSIGNED DEFAULT NULL,
		PRIMARY KEY (id),
		UNIQUE KEY uniq_booking (amelia_booking_id),
		KEY idx_when (checked_in_at)
	) {$charset_collate};";
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
}
add_action( 'init', 'cordespace_create_checkin_table' );

// ============================================================================
// 2) Helpers CRUD
// ============================================================================
function cordespace_is_checked_in( int $booking_id ): bool {
	global $wpdb;
	$table = cordespace_checkin_table();
	return (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*) FROM {$table} WHERE amelia_booking_id = %d",
		$booking_id
	) ) > 0;
}

function cordespace_check_in( int $booking_id, int $by_user_id ): void {
	global $wpdb;
	$wpdb->replace(
		cordespace_checkin_table(),
		[
			'amelia_booking_id'     => $booking_id,
			'checked_in_at'         => current_time( 'mysql', true ),
			'checked_in_by_user_id' => $by_user_id,
		],
		[ '%d', '%s', '%d' ]
	);
}

function cordespace_uncheck_in( int $booking_id ): void {
	global $wpdb;
	$wpdb->delete(
		cordespace_checkin_table(),
		[ 'amelia_booking_id' => $booking_id ],
		[ '%d' ]
	);
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
			"SELECT b.id AS booking_id, b.status, b.persons,
					u.firstName, u.lastName, u.email
			   FROM {$wpdb->prefix}amelia_customer_bookings_to_events_periods bep
			   JOIN {$wpdb->prefix}amelia_customer_bookings b ON b.id = bep.customerBookingId
			   JOIN {$wpdb->prefix}amelia_users u ON u.id = b.customerId
			  WHERE bep.eventPeriodId = %d
			    AND b.status IN ('approved', 'pending')
			  ORDER BY u.firstName, u.lastName",
			$event['period_id']
		), ARRAY_A );

		foreach ( $bookings as &$b ) {
			$b['is_checked_in'] = cordespace_is_checked_in( (int) $b['booking_id'] );
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
		],
	] );
} );

function cordespace_rest_toggle_checkin( $request ) {
	$booking_id = (int) $request->get_param( 'booking_id' );
	$present    = (bool) $request->get_param( 'present' );
	$user_id    = get_current_user_id();

	if ( ! cordespace_user_can_check_in( $user_id, $booking_id ) ) {
		return new WP_Error( 'forbidden', 'Tu n\'as pas les droits sur ce booking.', [ 'status' => 403 ] );
	}

	if ( $present ) {
		cordespace_check_in( $booking_id, $user_id );
		return [ 'success' => true, 'state' => 'present' ];
	} else {
		cordespace_uncheck_in( $booking_id );
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
			$when  = mysql2date( 'l j F — H\hi', $event['periodStart'] );
			$end   = mysql2date( 'H\hi', $event['periodEnd'] );
			$total = count( $event['bookings'] );
			$pres  = count( array_filter( $event['bookings'], fn($b) => $b['is_checked_in'] ) );
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
										<?php if ( $is_pending ) : ?>
											<span style="display:inline-block;background:#f5b800;color:#3a2c00;font-size:0.7em;padding:0.15rem 0.5rem;border-radius:4px;margin-left:0.4rem;font-weight:700;letter-spacing:0.3px;">⏳ PAIEMENT À VALIDER</span>
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
	</style>

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
			var current    = btn.dataset.state;
			var newPresent = (current !== 'present');

			// Animation visuelle optimiste (avant la réponse serveur)
			track.style.opacity = '0.6';

			fetch(endpoint, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
				body: JSON.stringify({ booking_id: bookingId, present: newPresent }),
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
	})();
	</script>
	<?php
	return ob_get_clean();
}

