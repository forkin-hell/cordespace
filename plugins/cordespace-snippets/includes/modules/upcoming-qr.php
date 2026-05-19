<?php
/**
 * Module : mon-espace.upcoming-qr
 *
 * Affiche un QR code par cours réservé dans les 24h (vue cliente). Identique
 * au QR que le scanner Amelia attend ; les bookings « pending » affichent un
 * badge « ⏳ paiement à valider » mais restent scannables.
 *
 * Hook : do_action('cordespace_mon_espace_section_client_qr', $user) émis
 * par l'enveloppe mon-espace.shortcode.
 *
 * Dépendances : Amelia.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'cordespace_mon_espace_section_client_qr', 'cordespace_render_upcoming_classes_qr' );

/**
 * Récupère les bookings approved/pending du user (Customer Amelia) dans
 * les 24h à venir et affiche un QR code pour chacun, identique à celui
 * que le scanner Amelia attend. Badge visuel "à valider" pour les pending.
 *
 * Le hook passe l'objet $user — on echo directement (pas de return) car
 * c'est un do_action, pas un do_shortcode.
 */
function cordespace_render_upcoming_classes_qr( $user ) {
	global $wpdb;

	// Trouve l'Amelia customer record du user
	$amelia_customer_id = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM {$wpdb->prefix}amelia_users
		  WHERE externalId = %d AND type = 'customer' AND status = 'visible'
		  LIMIT 1",
		$user->ID
	) );

	if ( $amelia_customer_id <= 0 ) {
		return;
	}

	$now    = current_time( 'mysql', true );                            // UTC
	$in_24h = gmdate( 'Y-m-d H:i:s', time() + 24 * HOUR_IN_SECONDS );   // UTC

	$bookings = $wpdb->get_results( $wpdb->prepare(
		"SELECT b.id, b.status, b.qrCodes, e.name AS event_name, ep.periodStart, ep.periodEnd
		   FROM {$wpdb->prefix}amelia_customer_bookings b
		   JOIN {$wpdb->prefix}amelia_customer_bookings_to_events_periods bep ON bep.customerBookingId = b.id
		   JOIN {$wpdb->prefix}amelia_events_periods ep ON ep.id = bep.eventPeriodId
		   JOIN {$wpdb->prefix}amelia_events e ON e.id = ep.eventId
		  WHERE b.customerId = %d
		    AND b.status IN ('approved', 'pending')
		    AND ep.periodStart <= %s
		    AND ep.periodEnd   >= %s
		  ORDER BY ep.periodStart ASC",
		$amelia_customer_id,
		$in_24h,
		$now
	), ARRAY_A );

	if ( empty( $bookings ) ) {
		return;
	}

	?>
	<style>
		/* ===================== Mobile (<= 600px) — vue cliente QR ===================== */
		@media (max-width: 600px) {
			/* Wrapper plus compact */
			.cordespace-upcoming-qr {
				padding: 1rem 1.1rem !important;
			}
			.cordespace-upcoming-qr h2 {
				font-size: 1.15em !important;
			}
			.cordespace-upcoming-qr > p {
				font-size: 0.85em !important;
				margin-bottom: 0.8rem !important;
			}
			/* Card QR plus compacte */
			.cordespace-qr-card {
				padding: 0.8rem 0.9rem !important;
				gap: 0.8rem !important;
				flex-wrap: nowrap !important;
				align-items: flex-start !important;
			}
			.cordespace-qr-card > div:first-child {
				min-width: 0 !important;
				flex: 1 1 auto !important;
			}
			/* Nom de l'event tronqué si trop long */
			.cordespace-qr-card .cordespace-qr-event-name {
				font-size: 1em !important;
			}
			.cordespace-qr-card .cordespace-qr-when {
				font-size: 0.85em !important;
			}
			/* QR plus petit pour économiser la largeur */
			.cordespace-qr-card img {
				width: 95px !important;
				height: 95px !important;
			}
			.cordespace-qr-card .cordespace-qr-manual-code {
				font-size: 0.78em !important;
				padding: 0.2rem 0.5rem !important;
			}
		}
	</style>
	<div class="cordespace-upcoming-qr" style="background:linear-gradient(135deg,#5b2c8f 0%,#3a1a5e 100%);color:#fff;padding:1.5rem 1.8rem;border-radius:10px;margin-bottom:1.5rem;border:2px solid #f5b800;">
		<h2 style="margin:0 0 0.4rem;color:#fff;font-size:1.4rem;">🎫 <?php echo count( $bookings ) > 1 ? 'Tes prochains cours (24h)' : 'Ton prochain cours'; ?></h2>
		<p style="margin:0 0 1.2rem;opacity:0.92;font-size:0.95em;">Présente ces QR à ton arrivée pour le check-in.</p>

		<?php foreach ( $bookings as $booking ) :
			$is_pending  = ( $booking['status'] === 'pending' );
			$qr_data     = ! empty( $booking['qrCodes'] ) ? json_decode( $booking['qrCodes'], true ) : null;
			$qr_string   = ( is_array( $qr_data ) && ! empty( $qr_data[0]['qrCodeData'] ) ) ? $qr_data[0]['qrCodeData'] : '';
			// Code manuel = ce que la personne peut taper à l'oral si le scan QR ne marche pas
			$manual_code = ( is_array( $qr_data ) && ! empty( $qr_data[0]['ticketManualCode'] ) ) ? $qr_data[0]['ticketManualCode'] : '';
			$qr_url      = $qr_string
				? 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&margin=8&data=' . urlencode( $qr_string )
				: '';
			// Amelia stocke periodStart en UTC. On convertit vers la timezone WP
			// (Montréal) avant de formater pour afficher l'heure locale réelle.
			$when        = mysql2date( 'l j F — H\hi', get_date_from_gmt( $booking['periodStart'] ) );
		?>
			<div class="cordespace-qr-card" style="background:rgba(255,255,255,0.12);padding:1rem 1.2rem;border-radius:8px;margin-bottom:0.7rem;display:flex;gap:1.2rem;align-items:center;flex-wrap:wrap;<?php echo $is_pending ? 'outline:2px solid #f5b800;outline-offset:-2px;' : ''; ?>">
				<div style="flex:1;min-width:200px;">
					<div class="cordespace-qr-event-name" style="font-size:1.1em;font-weight:600;margin-bottom:0.3rem;line-height:1.4;">
						<?php echo esc_html( $booking['event_name'] ); ?>
						<?php if ( $is_pending ) : ?>
							<span style="display:inline-block;background:#f5b800;color:#3a2c00;font-size:0.7em;padding:0.2rem 0.55rem;border-radius:4px;margin-left:0.4rem;font-weight:700;vertical-align:middle;letter-spacing:0.3px;">⏳ PAIEMENT À VALIDER</span>
						<?php endif; ?>
					</div>
					<div class="cordespace-qr-when" style="opacity:0.9;font-size:0.95em;"><?php echo esc_html( $when ); ?></div>

					<?php if ( $is_pending ) : ?>
						<div style="margin-top:0.6rem;font-size:0.85em;background:rgba(245,184,0,0.18);padding:0.5rem 0.7rem;border-radius:4px;line-height:1.4;">
							💡 <strong>Si tu as déjà payé par Interac</strong>, ton check-in se fera quand même.<br>
							Sinon, prépare ton paiement à l'arrivée.
						</div>
					<?php endif; ?>
				</div>

				<?php if ( $qr_url ) : ?>
					<div style="display:flex;flex-direction:column;align-items:center;gap:0.4rem;<?php echo $is_pending ? 'opacity:0.85;' : ''; ?>">
						<div style="background:white;padding:0.5rem;border-radius:6px;">
							<img src="<?php echo esc_url( $qr_url ); ?>" alt="QR code" width="130" height="130" style="display:block;">
						</div>
						<?php if ( $manual_code !== '' ) : ?>
							<div class="cordespace-qr-manual-code" style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;background:rgba(0,0,0,0.25);padding:0.3rem 0.7rem;border-radius:4px;font-size:0.9em;letter-spacing:0.06em;font-weight:600;" title="Code à taper si le scan QR ne marche pas">
								<?php echo esc_html( $manual_code ); ?>
							</div>
						<?php endif; ?>
					</div>
				<?php else : ?>
					<div style="background:rgba(255,255,255,0.1);padding:0.6rem 0.8rem;border-radius:4px;font-size:0.85em;">QR non dispo,<br>contacte l'équipe.</div>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
	</div>
	<?php
}
