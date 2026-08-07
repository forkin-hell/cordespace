<?php
/**
 * Module : mon-espace.abonnement-salles
 *
 * Section « 🏠 Mes réservations » de la vue CLIENTE de /mon-espace : intègre
 * le cabinet Amelia des salles dans un accordéon + iframe. Le cabinet vient de
 * la page /mon-abonnement/ qui porte [ameliacustomerpanel appointments=1] :
 *  - onglet Appointments : les créneaux réservés (annuler / reporter) ;
 *  - onglet Packages : l'abonnement, sa validité, et « Book Now » pour
 *    réserver via l'abonnement. Packages n'apparaît que si la personne
 *    possède un package (comportement natif Amelia).
 *
 * Visibilité de la section : abonnement actif OU au moins une réservation à
 * venir. Sinon, aucun rendu.
 *
 * Pourquoi une iframe : Amelia refuse 2 instances de [ameliacustomerpanel]
 * par page (app single-instance) et mon-espace porte déjà le panneau events.
 * L'iframe même-domaine partage la session (auto-login OK) et n'est chargée
 * qu'à la première ouverture de l'accordéon (lazy). Le paramètre
 * ?cordespace_iframe=1 active le mode « sans chrome » (en-tête / menu /
 * footer masqués) pour ne montrer que le panneau.
 *
 * Historique : la v1 (2026-08-07) affichait une carte-liste des packages
 * (« Actif jusqu'au X ») + un lien pleine page — retirés à la demande de
 * Tess : le cabinet intégré montre déjà tout. L'ancienne section custom
 * « Mes réservations de salles » de mon-espace.php a été retirée à la même
 * occasion (le cabinet la remplace).
 */

defined( 'ABSPATH' ) || exit;

add_action( 'cordespace_mon_espace_section_client_abonnement', 'cordespace_render_client_abonnement_salles', 10, 1 );
add_action( 'wp_head', 'cordespace_abonnement_iframe_chrome_css' );

/**
 * True si la personne a au moins un package Amelia actif (abonnement).
 * status approved + end >= maintenant (Amelia stocke en UTC). Cache statique
 * par requête : appelé par le nav de mon-espace ET par le rendu de section.
 */
function cordespace_user_has_active_amelia_package( $user ): bool {
	static $cache = [];
	if ( ! $user || empty( $user->ID ) ) {
		return false;
	}
	if ( isset( $cache[ $user->ID ] ) ) {
		return $cache[ $user->ID ];
	}
	global $wpdb;
	$n = (int) $wpdb->get_var( $wpdb->prepare(
		"SELECT COUNT(*)
		   FROM {$wpdb->prefix}amelia_packages_to_customers pc
		   JOIN {$wpdb->prefix}amelia_users u ON u.id = pc.customerId
		  WHERE u.externalId = %d AND u.type = 'customer' AND u.status = 'visible'
		    AND pc.status = 'approved' AND pc.end >= UTC_TIMESTAMP()",
		$user->ID
	) );

	$cache[ $user->ID ] = ( $n > 0 );
	return $cache[ $user->ID ];
}

function cordespace_render_client_abonnement_salles( $user ): void {
	if ( ! $user || empty( $user->ID ) ) {
		return;
	}

	$has_package  = cordespace_user_has_active_amelia_package( $user );
	$has_upcoming = function_exists( 'cordespace_user_has_upcoming_appointments' )
		? cordespace_user_has_upcoming_appointments( $user )
		: false;
	if ( ! $has_package && ! $has_upcoming ) {
		return;
	}

	// Page compagne [ameliacustomerpanel appointments=1] (voir en-tête).
	$manage_url = apply_filters(
		'cordespace_abonnement_salles_manage_url',
		home_url( '/mon-abonnement/' )
	);
	$iframe_url = add_query_arg( 'cordespace_iframe', '1', $manage_url );

	$subtitle = $has_package
		? 'Tes créneaux de salle — et ton abonnement, dans l\'onglet Packages.'
		: 'Tes créneaux de location de salle à venir.';
	?>
	<section id="section-salles" style="margin-bottom:2.5rem;padding:1.4rem 1.8rem;background:#fafaf8;border:1px solid #e5e5e5;border-radius:10px;">
		<h2 style="margin:0 0 0.4rem;font-size:1.25rem;">🏠 Mes réservations</h2>
		<p style="color:#666;margin:0 0 1rem;font-size:0.92em;"><?php echo esc_html( $subtitle ); ?></p>

		<details id="cordespace-abo-details">
			<summary style="display:inline-block;padding:0.55rem 1.1rem;background:#4a3b8c;color:#fff;border-radius:6px;cursor:pointer;font-size:0.95em;list-style:none;user-select:none;">
				🗓️ Voir / gérer mes réservations <span style="font-size:0.8em;">▾</span>
			</summary>
			<div style="margin-top:0.8rem;">
				<iframe id="cordespace-abo-iframe" data-src="<?php echo esc_url( $iframe_url ); ?>" title="Mes réservations" style="width:100%;height:75vh;min-height:560px;border:1px solid #e0e0e0;border-radius:8px;background:#fff;display:block;"></iframe>
			</div>
		</details>
		<script>
		( function () {
			var d = document.getElementById( 'cordespace-abo-details' );
			if ( ! d ) { return; }
			d.addEventListener( 'toggle', function () {
				var f = document.getElementById( 'cordespace-abo-iframe' );
				if ( d.open && f && ! f.src ) { f.src = f.getAttribute( 'data-src' ); }
			} );
		} )();
		</script>
	</section>
	<?php
}

/**
 * Mode « sans chrome » pour l'iframe : quand la page est chargée avec
 * ?cordespace_iframe=1 (depuis l'accordéon de mon-espace), on masque
 * l'en-tête, le menu, le footer et l'admin bar pour ne laisser que le
 * panneau Amelia. Sélecteurs génériques + spécifiques au thème Inspiro.
 */
function cordespace_abonnement_iframe_chrome_css(): void {
	if ( empty( $_GET['cordespace_iframe'] ) ) {
		return;
	}
	echo '<style id="cordespace-iframe-chrome">
		#wpadminbar, .site-header, #masthead, #site-header, header.header,
		.zoom-top-bar, .navbar, .site-footer, #colophon, footer.footer,
		.entry-header, .page-header, .breadcrumbs { display: none !important; }
		html { margin-top: 0 !important; }
		body { padding-top: 0 !important; }
	</style>';
}
