<?php
/**
 * Module : mon-espace.credit-history
 *
 * Section historique MyCred dans la vue cliente. Filtre l'historique sur
 * l'utilisateur·trice connecté·e (sans user_id, [mycred_history] retombe
 * sur la vue admin et expose les transactions de tout le monde — fuite
 * de données privées corrigée dans le commit 95a9d18).
 *
 * Hook : do_action('cordespace_mon_espace_section_client_credits', $user)
 * émis par l'enveloppe mon-espace.shortcode.
 *
 * Dépendances : MyCred.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'cordespace_mon_espace_section_client_credits', 'cordespace_render_credit_history_section' );

function cordespace_render_credit_history_section( $user ) {
	?>
	<section id="section-credits" style="margin-bottom:2.5rem;padding:1.8rem;background:#fff;border:1px solid #e5e5e5;border-radius:10px;">
		<h2 style="margin:0 0 0.4rem;font-size:1.4rem;">💰 Historique de mes crédits</h2>
		<p style="color:#666;margin:0 0 1.2rem;font-size:0.95em;">Solde actuel et 10 dernières transactions.</p>
		<div style="padding:1rem 1.4rem;background:#f0f7f0;border-radius:6px;margin-bottom:1.2rem;">
			<span style="color:#3a7a3a;font-weight:600;">Solde actuel :</span> <strong style="font-size:1.2em;"><?php echo do_shortcode( '[mycred_my_balance show_zero=yes]' ); ?></strong>
		</div>
		<?php
		// SÉCURITÉ : on injecte explicitement l'ID de l'utilisateur·trice
		// connecté·e. Sans user_id, [mycred_history] retombe sur la vue
		// admin et affiche l'historique de TOUS les comptes (fuite de
		// données privées). show_user=0 cache la colonne "User" qui
		// n'aurait aucun sens dans une vue perso.
		$cs_uid = (int) ( $user->ID ?? get_current_user_id() );
		if ( $cs_uid > 0 ) {
			echo do_shortcode( sprintf( '[mycred_history number=10 user_id=%d show_user=0]', $cs_uid ) );
		}
		?>
	</section>
	<?php
}
