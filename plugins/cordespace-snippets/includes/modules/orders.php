<?php
/**
 * Module : mon-espace.orders
 *
 * Section "Mes commandes" dans la vue cliente : 10 dernières commandes
 * WooCommerce de l'utilisateur·trice connecté·e (n°, date, statut,
 * total, lien vers le détail natif WC).
 *
 * Hook : do_action('cordespace_mon_espace_section_client_orders', $user)
 * émis par l'enveloppe mon-espace.shortcode.
 *
 * Si une·e cliente·e a plus de 10 commandes, un lien "Voir toutes mes
 * commandes" pointe vers la page native WC My Account → Commandes
 * (résolue dynamiquement via wc_get_endpoint_url, donc robuste aux
 * changements de slug en français).
 *
 * Dépendances : WooCommerce.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'cordespace_mon_espace_section_client_orders', 'cordespace_render_orders_section' );

function cordespace_render_orders_section( $user ) {
	if ( ! function_exists( 'wc_get_orders' ) ) {
		return;
	}
	if ( ! $user || ! $user->ID ) {
		return;
	}

	$limit  = 10;
	$orders = wc_get_orders( [
		'customer_id' => $user->ID,
		'limit'       => $limit,
		'orderby'     => 'date',
		'order'       => 'DESC',
		'paginate'    => false,
	] );
	?>
	<section id="section-commandes" style="margin-bottom:2.5rem;padding:1.8rem;background:#fff;border:1px solid #e5e5e5;border-radius:10px;">
		<h2 style="margin:0 0 0.4rem;font-size:1.4rem;">🛒 Mes commandes</h2>
		<p style="color:#666;margin:0 0 1.2rem;font-size:0.95em;">Tes achats récents sur la boutique Cordespace.</p>

		<?php if ( empty( $orders ) ) : ?>
			<div style="padding:1rem;background:#f7f7f7;border-radius:6px;color:#666;">
				Aucune commande pour l'instant.
			</div>
		<?php else : ?>
			<div style="overflow-x:auto;">
				<table style="width:100%;border-collapse:collapse;">
					<thead>
						<tr style="text-align:left;border-bottom:2px solid #e5e5e5;">
							<th style="padding:0.6rem 0.5rem;font-size:0.85em;text-transform:uppercase;letter-spacing:0.5px;color:#666;">N°</th>
							<th style="padding:0.6rem 0.5rem;font-size:0.85em;text-transform:uppercase;letter-spacing:0.5px;color:#666;">Date</th>
							<th style="padding:0.6rem 0.5rem;font-size:0.85em;text-transform:uppercase;letter-spacing:0.5px;color:#666;">Statut</th>
							<th style="padding:0.6rem 0.5rem;font-size:0.85em;text-transform:uppercase;letter-spacing:0.5px;color:#666;">Total</th>
							<th style="padding:0.6rem 0.5rem;"></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $orders as $order ) :
							$status_slug  = $order->get_status();
							$status_label = wc_get_order_status_name( $status_slug );
							$pastille     = cordespace_order_status_color( $status_slug );
							$created_ts   = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0;

							// Liste des articles : on récupère le titre du vrai event quand
							// dispo (meta 'ameliabooking' → champ 'name'), sinon on retombe
							// sur le nom du produit WC (typiquement « Billet événement »,
							// pas très parlant). Le titre Amelia est plus utile pour
							// reconnaître la commande d'un coup d'œil.
							$item_names = [];
							foreach ( $order->get_items() as $item ) {
								$booking = $item->get_meta( 'ameliabooking' );
								if ( is_array( $booking ) && ! empty( $booking['name'] ) ) {
									$item_names[] = $booking['name'];
								} else {
									$item_names[] = $item->get_name();
								}
							}
						?>
							<tr style="border-bottom:1px solid #f0f0f0;">
								<td style="padding:0.7rem 0.5rem;">
									<div style="font-weight:600;white-space:nowrap;">#<?php echo esc_html( $order->get_order_number() ); ?></div>
									<?php if ( ! empty( $item_names ) ) : ?>
										<div style="color:#666;font-size:0.85em;font-weight:400;margin-top:0.2rem;line-height:1.3;">
											<?php echo esc_html( implode( ', ', $item_names ) ); ?>
										</div>
									<?php endif; ?>
								</td>
								<td style="padding:0.7rem 0.5rem;color:#666;white-space:nowrap;vertical-align:top;">
									<?php echo $created_ts ? esc_html( wp_date( 'j M Y', $created_ts ) ) : '—'; ?>
								</td>
								<td style="padding:0.7rem 0.5rem;vertical-align:top;">
									<span style="display:inline-block;padding:0.2rem 0.6rem;border-radius:12px;background:<?php echo esc_attr( $pastille['bg'] ); ?>;color:<?php echo esc_attr( $pastille['fg'] ); ?>;font-size:0.85em;font-weight:500;white-space:nowrap;">
										<?php echo esc_html( $status_label ); ?>
									</span>
								</td>
								<td style="padding:0.7rem 0.5rem;font-weight:600;white-space:nowrap;vertical-align:top;">
									<?php echo wp_kses_post( $order->get_formatted_order_total() ); ?>
								</td>
								<td style="padding:0.7rem 0.5rem;text-align:right;white-space:nowrap;vertical-align:top;">
									<a href="<?php echo esc_url( $order->get_view_order_url() ); ?>" style="color:#2c70b8;text-decoration:none;font-size:0.95em;">Voir →</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<?php if ( count( $orders ) >= $limit ) :
				$all_orders_url = wc_get_endpoint_url( 'orders', '', wc_get_page_permalink( 'myaccount' ) );
			?>
				<p style="margin-top:1.2rem;margin-bottom:0;">
					<a href="<?php echo esc_url( $all_orders_url ); ?>" style="color:#2c70b8;text-decoration:none;font-size:0.95em;">
						Voir toutes mes commandes →
					</a>
				</p>
			<?php endif; ?>
		<?php endif; ?>
	</section>
	<?php
}

/**
 * Mapping statut WC → couleurs (fond + texte) pour les pastilles.
 * Tons WC standards : pending/on-hold=jaune, processing=bleu,
 * completed=vert, cancelled=gris, refunded/failed=rouge.
 */
function cordespace_order_status_color( string $status ): array {
	$map = [
		'pending'    => [ 'bg' => '#fff3cd', 'fg' => '#856404' ],
		'on-hold'    => [ 'bg' => '#fff3cd', 'fg' => '#856404' ],
		'processing' => [ 'bg' => '#cce5ff', 'fg' => '#004085' ],
		'completed'  => [ 'bg' => '#d4edda', 'fg' => '#155724' ],
		'cancelled'  => [ 'bg' => '#f0f0f0', 'fg' => '#555555' ],
		'refunded'   => [ 'bg' => '#f8d7da', 'fg' => '#721c24' ],
		'failed'     => [ 'bg' => '#f8d7da', 'fg' => '#721c24' ],
	];
	return $map[ $status ] ?? [ 'bg' => '#f0f0f0', 'fg' => '#555555' ];
}
