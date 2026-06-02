<?php
/**
 * Module: waivers-mon-espace
 *
 * Section "Mes waivers" intégrée dans la vue cliente du shortcode
 * [cordespace_mon_espace]. Liste chaque waiver publié avec son statut
 * (Signée / À signer), version, date de signature, et un bouton d'action
 * (Relire ou Signer) qui mène à la page de signature.
 *
 * S'accroche au slot do_action('cordespace_mon_espace_section_client_waivers').
 *
 * Confidentialité : on n'affiche jamais l'IP ni le user_agent (audit interne
 * uniquement, voir spec §8 LCJTI).
 *
 * Dépend de :
 *   - mon-espace.shortcode (slot d'action)
 *   - waivers-cpt          (CORDESPACE_WAIVER_POST_TYPE, cordespace_waivers_get_version)
 *   - waivers-store        (cordespace_waivers_has_signed_current, cordespace_waivers_history_for_user_and_waiver)
 *   - waivers-signing-page (cordespace_waivers_get_sign_url)
 *
 * Voir docs/superpowers/specs/waivers.md §3.8 (onglet "Mes waivers")
 */

defined( 'ABSPATH' ) || exit;

add_action( 'cordespace_mon_espace_section_client_waivers', 'cordespace_waivers_render_mon_espace_section' );

function cordespace_waivers_render_mon_espace_section( $user ): void {
	if ( ! $user || ! isset( $user->ID ) || (int) $user->ID <= 0 ) {
		return;
	}
	$user_id = (int) $user->ID;

	$waivers = get_posts( [
		'post_type'      => CORDESPACE_WAIVER_POST_TYPE,
		'posts_per_page' => 50,
		'post_status'    => 'publish',
		'orderby'        => 'title',
		'order'          => 'ASC',
		'no_found_rows'  => true,
	] );

	$mon_espace_url = function_exists( 'cordespace_get_mon_espace_url' )
		? cordespace_get_mon_espace_url()
		: home_url();
	?>
	<section id="section-waivers" style="margin-bottom:2.5rem;padding:1.8rem;background:#fff;border:1px solid #e5e5e5;border-radius:10px;">
		<h2 style="margin:0 0 0.4rem;font-size:1.4rem;">📋 Mes waivers</h2>
		<p style="color:#666;margin:0 0 1.2rem;font-size:0.95em;">
			Les documents à signer (ou déjà signés) pour participer aux activités Cordespace. Tu peux relire à tout moment ceux que tu as signés.
		</p>

		<?php if ( empty( $waivers ) ) : ?>
			<p style="padding:1rem 1.2rem; background:#f7f7f7; border-radius:5px; color:#666; font-style:italic; margin:0;">
				Aucun document actif pour le moment.
			</p>
		<?php else : ?>
			<div style="overflow-x:auto;">
				<table style="width:100%; border-collapse:collapse; min-width:560px;">
					<thead>
						<tr style="text-align:left; border-bottom:2px solid #e5e5e5; font-size:0.9em; color:#666; text-transform:uppercase; letter-spacing:0.3px;">
							<th style="padding:10px 8px;">Document</th>
							<th style="padding:10px 8px;">Statut</th>
							<th style="padding:10px 8px;">Signée le</th>
							<th style="padding:10px 8px;"></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $waivers as $waiver ) :
							$wid      = (int) $waiver->ID;
							$version  = cordespace_waivers_get_version( $wid );
							$signed   = cordespace_waivers_has_signed_current( $user_id, $wid );
							$history  = $signed ? cordespace_waivers_history_for_user_and_waiver( $user_id, $wid, 1 ) : [];
							$last     = ! empty( $history ) ? $history[0] : null;
							$view_url = cordespace_waivers_get_sign_url( $wid, $mon_espace_url );
							?>
							<tr style="border-bottom:1px solid #f0f0f0;">
								<td style="padding:12px 8px;">
									<strong><?php echo esc_html( (string) $waiver->post_title ); ?></strong><br>
									<span style="color:#999; font-size:0.85em;">v<?php echo esc_html( (string) $version ); ?></span>
								</td>
								<?php if ( $signed ) : ?>
									<td style="padding:12px 8px; color:#2a7a2a; font-weight:600;">✓ Signée</td>
									<td style="padding:12px 8px; color:#555;">
										<?php echo esc_html( $last ? mysql2date( 'j F Y', (string) $last['signed_at'] ) : '—' ); ?>
									</td>
									<td style="padding:12px 8px;">
										<a href="<?php echo esc_url( $view_url ); ?>" style="color:#5b2c8f; text-decoration:underline; font-size:0.95em;">
											Relire
										</a>
									</td>
								<?php else : ?>
									<td style="padding:12px 8px; color:#b91c1c; font-weight:600;">⚠ À signer</td>
									<td style="padding:12px 8px; color:#999;">—</td>
									<td style="padding:12px 8px;">
										<a href="<?php echo esc_url( $view_url ); ?>" style="display:inline-block; padding:0.4rem 1rem; background:#1a1a2e; color:#fff; text-decoration:none; border-radius:4px; font-size:0.9em; font-weight:600;">
											Signer
										</a>
									</td>
								<?php endif; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</section>
	<?php
}
