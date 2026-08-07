<?php
/**
 * Module : mon-espace.abonnement-salles
 *
 * Affiche dans la vue CLIENTE de /mon-espace une carte « Mon abonnement »
 * listant les packages Amelia actifs de la personne (ex : « Abonnement
 * réservations partagées illimités »), avec leur date de fin de validité.
 *
 * Règles :
 *  - S'affiche dès qu'un package est actif (status approved + end >= maintenant),
 *    MÊME sans réservation de salle à venir (choix design option A) : c'est
 *    justement quand on n'a rien réservé que savoir « j'ai un abonnement actif
 *    jusqu'au X » est le plus utile. Dans ce cas, un bouton « Réserver une
 *    salle » pointe vers la page de booking.
 *  - Disparaît tout seul à l'expiration : le filtre se fait à la lecture
 *    (end >= UTC_TIMESTAMP()), aucun cron.
 *  - Aucun customer Amelia / aucun package actif → aucun rendu.
 *
 * Données : wphu_amelia_packages_to_customers JOIN wphu_amelia_packages.
 * Amelia stocke les datetime en UTC → comparaison en UTC_TIMESTAMP() et
 * affichage converti au fuseau du site (wp_date).
 *
 * S'accroche au slot `cordespace_mon_espace_section_client_abonnement`
 * (déclaré dans mon-espace.php, AVANT la section salles, hors condition
 * has_upcoming_appts).
 */

defined( 'ABSPATH' ) || exit;

add_action( 'cordespace_mon_espace_section_client_abonnement', 'cordespace_render_client_abonnement_salles', 10, 1 );

function cordespace_render_client_abonnement_salles( $user ): void {
	if ( ! $user || empty( $user->ID ) ) {
		return;
	}

	global $wpdb;

	// WP user → customer Amelia (même pattern que upcoming-qr.php).
	$amelia_customer_id = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT id FROM {$wpdb->prefix}amelia_users
		  WHERE externalId = %d AND type = 'customer' AND status = 'visible'
		  LIMIT 1",
		$user->ID
	) );
	if ( $amelia_customer_id <= 0 ) {
		return;
	}

	// Packages actifs. MAX(end) par package : en cas de renouvellement/doublon,
	// on affiche la fin de validité la plus lointaine.
	$packages = $wpdb->get_results( $wpdb->prepare(
		"SELECT p.name, MAX(pc.end) AS end_utc
		   FROM {$wpdb->prefix}amelia_packages_to_customers pc
		   JOIN {$wpdb->prefix}amelia_packages p ON p.id = pc.packageId
		  WHERE pc.customerId = %d
		    AND pc.status = 'approved'
		    AND pc.end >= UTC_TIMESTAMP()
		  GROUP BY p.id, p.name
		  ORDER BY end_utc DESC",
		$amelia_customer_id
	) );
	if ( empty( $packages ) ) {
		return;
	}

	// Bouton « Réserver » seulement si aucune réservation de salle à venir
	// (sinon la section salles suit immédiatement, le bouton serait redondant).
	$has_upcoming = function_exists( 'cordespace_user_has_upcoming_appointments' )
		? cordespace_user_has_upcoming_appointments( $user )
		: false;

	$booking_url = apply_filters(
		'cordespace_abonnement_salles_booking_url',
		home_url( '/formulaire-reservation/' )
	);
	?>
	<section id="section-abonnement" style="margin-bottom:2.5rem;padding:1.4rem 1.8rem;background:#fafaf8;border:1px solid #e5e5e5;border-radius:10px;">
		<h2 style="margin:0 0 0.4rem;font-size:1.25rem;">🎟️ Mon abonnement</h2>

		<div style="display:flex; flex-direction:column; gap:0.6rem;">
			<?php foreach ( $packages as $pkg ) :
				// end stocké en UTC → timestamp UTC → wp_date affiche au fuseau du site.
				$ts = strtotime( $pkg->end_utc . ' +00:00' );
				?>
				<div style="display:flex;flex-wrap:wrap;align-items:baseline;gap:0.35rem 0.8rem;padding:0.7rem 1rem;background:#fff;border:1px solid #e8e8e8;border-radius:8px;">
					<strong style="font-size:1.02em;"><?php echo esc_html( $pkg->name ); ?></strong>
					<span style="color:#2e7d32;font-size:0.92em;white-space:nowrap;">
						✓ Actif jusqu'au <?php echo esc_html( wp_date( get_option( 'date_format' ), $ts ) ); ?>
					</span>
				</div>
			<?php endforeach; ?>
		</div>

		<?php if ( ! $has_upcoming ) : ?>
			<p style="margin:1rem 0 0;">
				<a href="<?php echo esc_url( $booking_url ); ?>" style="display:inline-block;padding:0.55rem 1.1rem;background:#4a3b8c;color:#fff;border-radius:6px;text-decoration:none;font-size:0.95em;">
					🏠 Réserver une salle
				</a>
			</p>
		<?php endif; ?>
	</section>
	<?php
}
