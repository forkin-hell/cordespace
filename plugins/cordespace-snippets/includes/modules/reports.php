<?php
/**
 * Module : admin.reports
 *
 * Page de rapports vente (wp-admin → Cordespace → Rapports) qui croise
 * les commandes WooCommerce et les bookings Amelia pour donner une vue
 * unifiée des ventes sur une période choisie, avec export CSV.
 *
 * Sections :
 *   - 📚 Boutique  : items WC physiques (cordes, livres, outils, etc.)
 *   - 🎓 Cours     : bookings Amelia type='event' (Pratique supervisée, etc.)
 *   - 🏠 Salles    : bookings Amelia type='appointment' (Partagé matin, Privé soir, etc.)
 *
 * Filtres :
 *   - Période : presets rapides (Ce mois, Mois précédent, Cette année) + custom date range
 *   - Statuts de commande : multi-select (Terminée, En cours, En attente, Remboursée, etc.)
 *
 * Export CSV : un seul fichier multi-sections (1 colonne 'Section' indique boutique/cours/salle).
 *
 * Calcule les VRAIS montants depuis WC (pas les 0$ d'Amelia qui ignorent
 * les paiements Interac). On croise via la meta 'ameliabooking' pour
 * classer chaque line_item dans la bonne section.
 *
 * Dépend de : WooCommerce (HPOS), Amelia.
 */

defined( 'ABSPATH' ) || exit;

const CORDESPACE_REPORTS_MENU_SLUG = 'cordespace-reports';

// ============================================================================
// 1) Sous-menu wp-admin
// ============================================================================
add_action( 'admin_menu', 'cordespace_reports_register_menu', 20 );
function cordespace_reports_register_menu(): void {
	add_submenu_page(
		'cordespace-modules',
		__( 'Cordespace — Rapports', 'cordespace-snippets' ),
		__( 'Rapports', 'cordespace-snippets' ),
		'manage_options',
		CORDESPACE_REPORTS_MENU_SLUG,
		'cordespace_reports_render_page'
	);
}

// ============================================================================
// 2) Helpers presets de période
// ============================================================================
function cordespace_reports_get_preset_range( string $preset ): array {
	$tz = wp_timezone();
	$now = new DateTimeImmutable( 'now', $tz );

	switch ( $preset ) {
		case 'this_month':
			$start = $now->modify( 'first day of this month' )->setTime( 0, 0, 0 );
			$end   = $now->modify( 'last day of this month' )->setTime( 23, 59, 59 );
			break;
		case 'last_month':
			$start = $now->modify( 'first day of last month' )->setTime( 0, 0, 0 );
			$end   = $now->modify( 'last day of last month' )->setTime( 23, 59, 59 );
			break;
		case 'this_year':
			$start = $now->modify( 'first day of January ' . $now->format( 'Y' ) )->setTime( 0, 0, 0 );
			$end   = $now->modify( 'last day of December ' . $now->format( 'Y' ) )->setTime( 23, 59, 59 );
			break;
		case 'last_30_days':
		default:
			$start = $now->modify( '-30 days' )->setTime( 0, 0, 0 );
			$end   = $now->setTime( 23, 59, 59 );
	}

	return [
		'start' => $start->format( 'Y-m-d H:i:s' ),
		'end'   => $end->format( 'Y-m-d H:i:s' ),
	];
}

// ============================================================================
// 3) Récupération des données
// ============================================================================

/**
 * Liste des statuts WC actuellement présents en DB (pour la UI de filtres).
 * On exclut les statuts trash et checkout-draft qui ne sont pas des vraies ventes.
 */
function cordespace_reports_get_available_statuses(): array {
	global $wpdb;
	$rows = $wpdb->get_results(
		"SELECT status, COUNT(*) AS total
		   FROM {$wpdb->prefix}wc_orders
		  WHERE status NOT IN ('trash', 'auto-draft', 'wc-checkout-draft')
		  GROUP BY status
		  ORDER BY total DESC",
		ARRAY_A
	);
	$labels = wc_get_order_statuses(); // ['wc-completed' => 'Terminée', ...]
	$out    = [];
	foreach ( (array) $rows as $r ) {
		$slug         = (string) $r['status'];
		$out[ $slug ] = [
			'count' => (int) $r['total'],
			'label' => $labels[ $slug ] ?? $slug,
		];
	}
	return $out;
}

/**
 * Récupère les line_items des commandes WC + remboursements pour la période
 * et les statuts donnés. Classe chaque item dans une section :
 *   - 'boutique'  : produit WC physique (cordes, livres, etc.)
 *   - 'cours'     : booking Amelia type='event'
 *   - 'salle'     : booking Amelia type='appointment'
 *   - 'refund'    : ligne de remboursement (type WC shop_order_refund)
 *
 * @return array<int, array<string,mixed>>
 */
function cordespace_reports_fetch_items( string $start, string $end, array $statuses ): array {
	global $wpdb;

	if ( empty( $statuses ) ) {
		return [];
	}

	// Échappe les statuts pour SQL IN(...)
	$status_placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

	// On inclut shop_order ET shop_order_refund (les remboursements créent
	// une "commande" séparée liée à la commande originale via parent_order_id).
	// Pour les refunds, on utilise la date du refund et le statut du parent.
	$sql = $wpdb->prepare(
		"SELECT
			o.id AS order_id,
			o.type AS order_type,
			COALESCE(parent.status, o.status) AS status,
			o.date_created_gmt,
			COALESCE(parent.id, o.id) AS reference_order_id,
			COALESCE(parent.payment_method_title, o.payment_method_title) AS payment_method_title,
			COALESCE(parent.payment_method, o.payment_method) AS payment_method,
			COALESCE(parent_oa.first_name, oa.first_name) AS first_name,
			COALESCE(parent_oa.last_name, oa.last_name) AS last_name,
			COALESCE(parent.billing_email, o.billing_email) AS billing_email,
			oi.order_item_id,
			oi.order_item_name,
			MAX(CASE WHEN oim.meta_key = '_product_id'   THEN oim.meta_value END) AS product_id,
			MAX(CASE WHEN oim.meta_key = '_qty'          THEN oim.meta_value END) AS qty,
			MAX(CASE WHEN oim.meta_key = '_line_subtotal' THEN oim.meta_value END) AS line_subtotal,
			MAX(CASE WHEN oim.meta_key = '_line_total'   THEN oim.meta_value END) AS line_total,
			MAX(CASE WHEN oim.meta_key = '_line_tax'     THEN oim.meta_value END) AS line_tax,
			MAX(CASE WHEN oim.meta_key = '_line_tax_data' THEN oim.meta_value END) AS line_tax_data,
			MAX(CASE WHEN oim.meta_key = 'ameliabooking' THEN oim.meta_value END) AS ameliabooking_raw
		   FROM {$wpdb->prefix}wc_orders o
		   LEFT JOIN {$wpdb->prefix}wc_orders parent ON parent.id = o.parent_order_id
		   LEFT JOIN {$wpdb->prefix}wc_order_addresses oa
		          ON oa.order_id = o.id AND oa.address_type = 'billing'
		   LEFT JOIN {$wpdb->prefix}wc_order_addresses parent_oa
		          ON parent_oa.order_id = parent.id AND parent_oa.address_type = 'billing'
		   JOIN {$wpdb->prefix}woocommerce_order_items oi
		          ON oi.order_id = o.id AND oi.order_item_type = 'line_item'
		   JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim
		          ON oim.order_item_id = oi.order_item_id
		  WHERE o.date_created_gmt BETWEEN %s AND %s
		    AND o.type IN ('shop_order', 'shop_order_refund')
		    AND (o.status IN ($status_placeholders) OR (o.type = 'shop_order_refund' AND parent.status IN ($status_placeholders)))
		  GROUP BY o.id, oi.order_item_id
		  ORDER BY o.date_created_gmt ASC, o.id ASC, oi.order_item_id ASC",
		array_merge( [ $start, $end ], $statuses, $statuses )
	);

	$rows = $wpdb->get_results( $sql, ARRAY_A );
	if ( ! is_array( $rows ) ) {
		return [];
	}

	// ÉTAPE 1 : collecte des order_ids parents des remboursements (pour aller
	// lire la meta ameliabooking de la commande originale et classer le
	// remboursement dans la même section que la vente d'origine).
	$parent_lookup_needed = [];
	foreach ( $rows as $r ) {
		if ( ( $r['order_type'] ?? '' ) === 'shop_order_refund' && ! empty( $r['reference_order_id'] ) ) {
			$parent_lookup_needed[] = (int) $r['reference_order_id'];
		}
	}
	$parent_lookup_needed = array_unique( $parent_lookup_needed );

	// ÉTAPE 2 : bulk query des meta ameliabooking des line_items des parents.
	// Map : parent_order_id × product_id → ameliabooking array
	$parent_ameliabooking_map = [];
	if ( ! empty( $parent_lookup_needed ) ) {
		$ph = implode( ',', array_fill( 0, count( $parent_lookup_needed ), '%d' ) );
		$parent_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT poi.order_id AS parent_order_id, poi.order_item_name,
					MAX(CASE WHEN poim.meta_key = '_product_id'   THEN poim.meta_value END) AS product_id,
					MAX(CASE WHEN poim.meta_key = 'ameliabooking' THEN poim.meta_value END) AS ameliabooking_raw
			   FROM {$wpdb->prefix}woocommerce_order_items poi
			   JOIN {$wpdb->prefix}woocommerce_order_itemmeta poim ON poim.order_item_id = poi.order_item_id
			  WHERE poi.order_id IN ($ph)
			    AND poi.order_item_type = 'line_item'
			  GROUP BY poi.order_item_id",
			$parent_lookup_needed
		), ARRAY_A );

		foreach ( (array) $parent_rows as $pr ) {
			$parsed = ! empty( $pr['ameliabooking_raw'] ) ? @unserialize( $pr['ameliabooking_raw'] ) : null;
			if ( ! is_array( $parsed ) ) {
				$parsed = null;
			}
			$key = (int) $pr['parent_order_id'] . '|' . (int) $pr['product_id'];
			$parent_ameliabooking_map[ $key ] = [
				'ameliabooking' => $parsed,
				'name'          => (string) $pr['order_item_name'],
			];
		}
	}

	// ÉTAPE 3 : traitement des items, classification finale
	$items = [];
	foreach ( $rows as $r ) {
		$ameliabooking = ! empty( $r['ameliabooking_raw'] ) ? @unserialize( $r['ameliabooking_raw'] ) : null;
		$ameliabooking = is_array( $ameliabooking ) ? $ameliabooking : null;
		$is_refund     = ( $r['order_type'] ?? '' ) === 'shop_order_refund';

		// Pour les remboursements, on cherche le meta ameliabooking de l'item
		// correspondant dans la commande PARENTE (même product_id).
		if ( $is_refund && $ameliabooking === null ) {
			$parent_key = (int) ( $r['reference_order_id'] ?? 0 ) . '|' . (int) ( $r['product_id'] ?? 0 );
			if ( isset( $parent_ameliabooking_map[ $parent_key ] ) ) {
				$ameliabooking = $parent_ameliabooking_map[ $parent_key ]['ameliabooking'];
			}
		}

		// Classification finale : on regarde le type Amelia (qu'il vienne du
		// line_item lui-même ou du parent dans le cas d'un remboursement)
		$type = $ameliabooking['type'] ?? null;
		if ( $type === 'event' ) {
			$section = 'cours';
		} elseif ( $type === 'appointment' ) {
			$section = 'salle';
		} else {
			$section = 'boutique';
		}

		// Nom détaillé : pour les Amelia, c'est le nom du service/event (pas le produit-coquille)
		$detail_name = $ameliabooking['name'] ?? $r['order_item_name'];

		// Date détail (date du cours/salle si Amelia, sinon vide)
		$detail_date = '';
		if ( $ameliabooking && isset( $ameliabooking['dateTimeValues'][0]['start'] ) ) {
			$detail_date = (string) $ameliabooking['dateTimeValues'][0]['start'];
		}

		// Décomposition TPS/TVQ depuis line_tax_data (sérialisé)
		$tps = 0.0;
		$tvq = 0.0;
		if ( ! empty( $r['line_tax_data'] ) ) {
			$tax_data = @unserialize( $r['line_tax_data'] );
			if ( is_array( $tax_data ) && ! empty( $tax_data['total'] ) ) {
				foreach ( $tax_data['total'] as $rate_id => $amount ) {
					$tax_label = cordespace_reports_get_tax_label( (int) $rate_id );
					if ( stripos( $tax_label, 'tps' ) !== false || stripos( $tax_label, 'gst' ) !== false ) {
						$tps += (float) $amount;
					} elseif ( stripos( $tax_label, 'tvq' ) !== false || stripos( $tax_label, 'qst' ) !== false ) {
						$tvq += (float) $amount;
					} else {
						// Taxe non identifiée → on l'ajoute aux TVQ par défaut (pourra être ajusté)
						$tvq += (float) $amount;
					}
				}
			}
		}

		$client_name = trim( ( $r['first_name'] ?? '' ) . ' ' . ( $r['last_name'] ?? '' ) );
		if ( $client_name === '' ) {
			$client_name = (string) ( $r['billing_email'] ?? '' );
		}

		$items[] = [
			'section'         => $section,
			'order_id'        => (int) $r['order_id'],
			'reference_order_id' => (int) ( $r['reference_order_id'] ?? $r['order_id'] ),
			'is_refund'       => ( $r['order_type'] ?? '' ) === 'shop_order_refund',
			'status'          => (string) $r['status'],
			'date'            => (string) $r['date_created_gmt'],
			'client_name'     => $client_name,
			'client_email'    => (string) ( $r['billing_email'] ?? '' ),
			'item_name'       => (string) $r['order_item_name'],
			'detail_name'     => (string) $detail_name,
			'detail_date'     => $detail_date,
			'qty'             => (float) ( $r['qty'] ?? 1 ),
			'subtotal'        => (float) ( $r['line_subtotal'] ?? 0 ),
			'tps'             => round( $tps, 2 ),
			'tvq'             => round( $tvq, 2 ),
			'total'           => (float) ( $r['line_total'] ?? 0 ) + (float) ( $r['line_tax'] ?? 0 ),
			'product_id'      => (int) ( $r['product_id'] ?? 0 ),
			'payment_method'  => (string) ( $r['payment_method'] ?? '' ),
			'payment_label'   => cordespace_reports_short_payment_label( (string) ( $r['payment_method'] ?? '' ), (string) ( $r['payment_method_title'] ?? '' ) ),
		];
	}

	return $items;
}

/**
 * Catégorise un statut de commande WC pour la ventilation comptable du total :
 *   - 'real'      : compte dans le chiffre d'affaires réel (encaissé / remboursé)
 *   - 'pending'   : pronostic — ventes à confirmer (en attente de validation)
 *   - 'cancelled' : ignoré — jamais encaissé, ni remboursé (0$ d'impact réel)
 */
function cordespace_reports_status_category( string $status ): string {
	if ( in_array( $status, [ 'wc-completed', 'wc-refunded' ], true ) ) {
		return 'real';
	}
	if ( in_array( $status, [ 'wc-cancelled', 'wc-failed' ], true ) ) {
		return 'cancelled';
	}
	// Tout le reste (processing, on-hold, pending, etc.) = pronostic à venir
	return 'pending';
}

/**
 * Convertit la méthode de paiement en label court visuellement clair.
 */
function cordespace_reports_short_payment_label( string $method, string $title ): string {
	$method = strtolower( $method );
	if ( $method === 'mycred' ) {
		return '💳 Crédits';
	}
	if ( $method === 'advanced_emt' || stripos( $title, 'interac' ) !== false || stripos( $title, 'virement' ) !== false ) {
		return '💵 Interac';
	}
	if ( $method === 'cod' ) {
		return '💵 Sur place / Interac';
	}
	if ( $title !== '' ) {
		return $title;
	}
	return $method !== '' ? $method : '—';
}

/**
 * Cache statique des labels de taxe (TPS/TVQ) par rate_id.
 */
function cordespace_reports_get_tax_label( int $rate_id ): string {
	static $cache = null;
	if ( $cache === null ) {
		global $wpdb;
		$rows  = $wpdb->get_results( "SELECT tax_rate_id, tax_rate_name FROM {$wpdb->prefix}woocommerce_tax_rates", ARRAY_A );
		$cache = [];
		foreach ( (array) $rows as $row ) {
			$cache[ (int) $row['tax_rate_id'] ] = (string) $row['tax_rate_name'];
		}
	}
	return $cache[ $rate_id ] ?? '';
}

/**
 * Agrège les items par section et calcule les totaux.
 */
function cordespace_reports_compute_totals( array $items ): array {
	$empty_bucket = [ 'qty' => 0, 'subtotal' => 0, 'tps' => 0, 'tvq' => 0, 'total' => 0 ];
	$mk_section   = function () use ( $empty_bucket ) {
		return [
			'items'     => [],
			'real'      => $empty_bucket,
			'pending'   => $empty_bucket,
			'cancelled' => $empty_bucket,
		];
	};
	$sections = [
		'boutique' => $mk_section(),
		'cours'    => $mk_section(),
		'salle'    => $mk_section(),
	];

	foreach ( $items as $it ) {
		$s   = $it['section'];
		$cat = cordespace_reports_status_category( $it['status'] );
		$sections[ $s ]['items'][] = $it;
		foreach ( [ 'qty', 'subtotal', 'tps', 'tvq', 'total' ] as $k ) {
			$sections[ $s ][ $cat ][ $k ] += $it[ $k ];
		}
	}

	// Total général ventilé par catégorie compta
	$grand = [
		'real'      => $empty_bucket,
		'pending'   => $empty_bucket,
		'cancelled' => $empty_bucket,
	];
	foreach ( $sections as $s ) {
		foreach ( [ 'real', 'pending', 'cancelled' ] as $cat ) {
			foreach ( [ 'qty', 'subtotal', 'tps', 'tvq', 'total' ] as $k ) {
				$grand[ $cat ][ $k ] += $s[ $cat ][ $k ];
			}
		}
	}

	return [ 'sections' => $sections, 'grand' => $grand ];
}

// ============================================================================
// 4) Page admin (UI)
// ============================================================================
function cordespace_reports_render_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Accès refusé.', 'cordespace-snippets' ) );
	}

	// Lecture des filtres depuis GET
	$preset           = isset( $_GET['preset'] )   ? sanitize_key( (string) $_GET['preset'] )   : 'this_month';
	$custom_start     = isset( $_GET['date_start'] ) ? sanitize_text_field( (string) $_GET['date_start'] ) : '';
	$custom_end       = isset( $_GET['date_end'] )   ? sanitize_text_field( (string) $_GET['date_end'] )   : '';

	$available_statuses_for_default = cordespace_reports_get_available_statuses();

	// Statuts par défaut : seulement ceux qui comptent pour la compta réelle
	// (Terminée = paiement validé / Remboursée = transaction de remboursement).
	// Les autres (annulée, en attente, en cours, en attente paiement) restent
	// disponibles dans le filtre mais décochés par défaut — Hanna peut les
	// cocher pour analyser les commandes en attente de validation etc.
	$default_statuses = [];
	foreach ( [ 'wc-completed', 'wc-refunded' ] as $s ) {
		if ( isset( $available_statuses_for_default[ $s ] ) ) {
			$default_statuses[] = $s;
		}
	}

	$has_explicit_filter = isset( $_GET['filtered'] ) && $_GET['filtered'] === '1';
	$selected_statuses   = isset( $_GET['statuses'] ) && is_array( $_GET['statuses'] )
		? array_map( 'sanitize_text_field', wp_unslash( $_GET['statuses'] ) )
		: ( $has_explicit_filter ? [] : $default_statuses );

	// Calcul de la période effective
	if ( $preset === 'custom' && $custom_start !== '' && $custom_end !== '' ) {
		$range = [
			'start' => $custom_start . ' 00:00:00',
			'end'   => $custom_end   . ' 23:59:59',
		];
	} else {
		$range = cordespace_reports_get_preset_range( $preset );
	}

	$available_statuses = $available_statuses_for_default;
	$items              = cordespace_reports_fetch_items( $range['start'], $range['end'], $selected_statuses );
	$totals             = cordespace_reports_compute_totals( $items );

	$export_url = add_query_arg(
		array_merge(
			[ 'action' => 'cordespace_reports_csv', '_wpnonce' => wp_create_nonce( 'cordespace_reports_csv' ) ],
			$_GET // on transmet les mêmes filtres
		),
		admin_url( 'admin-post.php' )
	);
	?>
	<div class="wrap" style="max-width:1400px;">
		<h1>📊 Rapports — Cordespace</h1>
		<p style="color:#666;">Rapport unifié des ventes (boutique + cours + salles) sur une période donnée, calculé depuis les vrais montants WooCommerce.</p>

		<form method="get" action="" style="background:#fff; border:1px solid #e0e0e0; border-radius:6px; padding:1.2rem 1.5rem; margin-top:1.2rem;">
			<input type="hidden" name="page" value="<?php echo esc_attr( CORDESPACE_REPORTS_MENU_SLUG ); ?>">
			<input type="hidden" name="filtered" value="1">

			<div style="display:flex; flex-wrap:wrap; gap:1.5rem; align-items:flex-start;">
				<!-- Période -->
				<div>
					<label style="font-weight:600; display:block; margin-bottom:0.4rem;">Période</label>
					<select name="preset" onchange="document.getElementById('custom_dates').style.display = this.value === 'custom' ? 'block' : 'none';">
						<option value="this_month"   <?php selected( $preset, 'this_month' );   ?>>Ce mois</option>
						<option value="last_month"   <?php selected( $preset, 'last_month' );   ?>>Mois précédent</option>
						<option value="this_year"    <?php selected( $preset, 'this_year' );    ?>>Cette année</option>
						<option value="last_30_days" <?php selected( $preset, 'last_30_days' ); ?>>30 derniers jours</option>
						<option value="custom"       <?php selected( $preset, 'custom' );       ?>>Période personnalisée</option>
					</select>
					<div id="custom_dates" style="margin-top:0.6rem; display:<?php echo $preset === 'custom' ? 'block' : 'none'; ?>;">
						<label style="font-size:0.9em; display:block; margin-bottom:0.2rem;">Du</label>
						<input type="date" name="date_start" value="<?php echo esc_attr( $custom_start ); ?>">
						<label style="font-size:0.9em; display:block; margin:0.4rem 0 0.2rem;">Au</label>
						<input type="date" name="date_end" value="<?php echo esc_attr( $custom_end ); ?>">
					</div>
				</div>

				<!-- Statuts -->
				<div style="flex:1; min-width:280px;">
					<label style="font-weight:600; display:block; margin-bottom:0.4rem;">Statuts de commande</label>
					<div style="display:flex; flex-wrap:wrap; gap:0.6rem 1.4rem;">
						<?php foreach ( $available_statuses as $slug => $info ) :
							$checked = in_array( $slug, $selected_statuses, true );
							?>
							<label style="display:flex; align-items:center; gap:0.3rem; font-size:0.95em;">
								<input type="checkbox" name="statuses[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( $checked ); ?>>
								<?php echo esc_html( $info['label'] ); ?>
								<span style="color:#999;">(<?php echo (int) $info['count']; ?>)</span>
							</label>
						<?php endforeach; ?>
					</div>
				</div>

				<!-- Submit -->
				<div style="align-self:flex-end;">
					<button type="submit" class="button button-primary">Filtrer</button>
				</div>
			</div>
		</form>

		<!-- Résumé période + bouton CSV -->
		<div style="margin-top:1.2rem; padding:1rem 1.4rem; background:#eef5fd; border-left:4px solid #2c70b8; border-radius:5px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem;">
			<div>
				<strong>Période sélectionnée :</strong>
				<?php echo esc_html( mysql2date( 'j F Y', $range['start'] ) ); ?>
				→
				<?php echo esc_html( mysql2date( 'j F Y', $range['end'] ) ); ?>
				·
				<strong><?php echo count( $items ); ?> lignes</strong>
			</div>
			<a href="<?php echo esc_url( $export_url ); ?>" class="button button-secondary">📥 Télécharger CSV</a>
		</div>

		<?php cordespace_reports_render_grand_total( $totals['grand'] ); ?>

		<?php cordespace_reports_render_section( '📚 Boutique', $totals['sections']['boutique'], 'boutique' ); ?>
		<?php cordespace_reports_render_section( '🎓 Cours', $totals['sections']['cours'], 'cours' ); ?>
		<?php cordespace_reports_render_section( '🏠 Salles', $totals['sections']['salle'], 'salle' ); ?>
	</div>
	<?php
}

function cordespace_reports_render_grand_total( array $grand ): void {
	?>
	<div style="margin-top:1.5rem; padding:1.5rem 1.8rem; background:linear-gradient(135deg,#5b2c8f 0%,#1a1a2e 100%); color:#fff; border-radius:8px;">
		<h2 style="margin:0 0 1.2rem; color:#fff; font-size:1.3em;">💰 Total général ventilé</h2>

		<!-- BLOC 1 : RÉEL (compta) -->
		<div style="margin-bottom:1rem;">
			<div style="display:flex; align-items:baseline; gap:0.6rem; margin-bottom:0.3rem;">
				<strong style="font-size:1.05em;">✅ Réel comptable</strong>
				<span style="opacity:0.7; font-size:0.85em;">— Complétées + Remboursées (ce qui a effectivement été encaissé)</span>
			</div>
			<div style="display:flex; flex-wrap:wrap; gap:1.5rem; padding-left:0.5rem;">
				<div><span style="opacity:0.7; font-size:0.8em; display:block;">Sous-total</span><strong style="font-size:1.3em;"><?php echo number_format( $grand['real']['subtotal'], 2, ',', ' ' ); ?> $</strong></div>
				<div><span style="opacity:0.7; font-size:0.8em; display:block;">TPS</span><strong style="font-size:1.3em;"><?php echo number_format( $grand['real']['tps'], 2, ',', ' ' ); ?> $</strong></div>
				<div><span style="opacity:0.7; font-size:0.8em; display:block;">TVQ</span><strong style="font-size:1.3em;"><?php echo number_format( $grand['real']['tvq'], 2, ',', ' ' ); ?> $</strong></div>
				<div style="border-left:1px solid rgba(255,255,255,0.3); padding-left:1.2rem;"><span style="opacity:0.7; font-size:0.8em; display:block;">Total TTC</span><strong style="font-size:1.5em;"><?php echo number_format( $grand['real']['total'], 2, ',', ' ' ); ?> $</strong></div>
			</div>
		</div>

		<hr style="border:none; border-top:1px solid rgba(255,255,255,0.18); margin:1rem 0;">

		<!-- BLOC 2 : PRONOSTIC -->
		<div style="margin-bottom:1rem;">
			<div style="display:flex; align-items:baseline; gap:0.6rem; margin-bottom:0.3rem;">
				<strong style="font-size:1.05em;">🔮 Pronostic à venir</strong>
				<span style="opacity:0.7; font-size:0.85em;">— En cours + En attente + Attente paiement (à confirmer)</span>
			</div>
			<div style="display:flex; flex-wrap:wrap; gap:1.5rem; padding-left:0.5rem;">
				<div><span style="opacity:0.7; font-size:0.8em; display:block;">Sous-total</span><strong style="font-size:1.15em;"><?php echo number_format( $grand['pending']['subtotal'], 2, ',', ' ' ); ?> $</strong></div>
				<div><span style="opacity:0.7; font-size:0.8em; display:block;">TPS</span><strong style="font-size:1.15em;"><?php echo number_format( $grand['pending']['tps'], 2, ',', ' ' ); ?> $</strong></div>
				<div><span style="opacity:0.7; font-size:0.8em; display:block;">TVQ</span><strong style="font-size:1.15em;"><?php echo number_format( $grand['pending']['tvq'], 2, ',', ' ' ); ?> $</strong></div>
				<div style="border-left:1px solid rgba(255,255,255,0.3); padding-left:1.2rem;"><span style="opacity:0.7; font-size:0.8em; display:block;">Total TTC</span><strong style="font-size:1.3em;"><?php echo number_format( $grand['pending']['total'], 2, ',', ' ' ); ?> $</strong></div>
			</div>
		</div>

		<hr style="border:none; border-top:1px solid rgba(255,255,255,0.18); margin:1rem 0;">

		<!-- BLOC 3 : ANNULÉES (info seulement) -->
		<div>
			<div style="display:flex; align-items:baseline; gap:0.6rem; margin-bottom:0.3rem;">
				<strong style="font-size:1em; opacity:0.85;">🚫 Annulées (info seulement)</strong>
				<span style="opacity:0.65; font-size:0.85em;">— Jamais encaissées, exclues des totaux ci-dessus</span>
			</div>
			<div style="padding-left:0.5rem; opacity:0.85;">
				<span style="font-size:0.95em;">Montant total annulé (n'impacte rien) :</span>
				<strong style="font-size:1.1em; margin-left:0.4rem;"><?php echo number_format( $grand['cancelled']['total'], 2, ',', ' ' ); ?> $</strong>
			</div>
		</div>
	</div>
	<?php
}

function cordespace_reports_render_section( string $title, array $section, string $key ): void {
	$real    = $section['real'];
	$pending = $section['pending'];
	$cancel  = $section['cancelled'];
	?>
	<div style="margin-top:1.8rem; padding:1.2rem 1.5rem; background:#fff; border:1px solid #e0e0e0; border-radius:6px;">
		<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:0.8rem; flex-wrap:wrap; gap:0.6rem;">
			<h2 style="margin:0; font-size:1.2em;"><?php echo esc_html( $title ); ?></h2>
			<div style="color:#555; font-size:0.9em; display:flex; gap:1rem; flex-wrap:wrap;">
				<span><?php echo count( $section['items'] ); ?> lignes</span>
				<span style="color:#2a7a2a;">✅ Réel : <strong><?php echo number_format( $real['total'], 2, ',', ' ' ); ?> $</strong></span>
				<?php if ( $pending['total'] != 0 ) : ?>
					<span style="color:#7a5d00;">🔮 Pronostic : <strong><?php echo number_format( $pending['total'], 2, ',', ' ' ); ?> $</strong></span>
				<?php endif; ?>
				<?php if ( $cancel['total'] != 0 ) : ?>
					<span style="color:#999;">🚫 Annulé : <?php echo number_format( $cancel['total'], 2, ',', ' ' ); ?> $</span>
				<?php endif; ?>
			</div>
		</div>

		<?php if ( empty( $section['items'] ) ) : ?>
			<p style="color:#999; font-style:italic; margin:0;">Aucune vente dans cette section pour la période.</p>
		<?php else : ?>
			<?php
			$has_event_date = ( $key === 'cours' || $key === 'salle' );
			$colspan_offset = $has_event_date ? 8 : 7;
			?>
			<table class="widefat striped" style="font-size:0.9em;">
				<thead>
					<tr>
						<th>Date</th>
						<th># Cde</th>
						<th>Statut</th>
						<th>Paiement</th>
						<th>Client</th>
						<th><?php echo $key === 'boutique' ? 'Produit' : ( $key === 'cours' ? 'Cours' : ( $key === 'salle' ? 'Salle' : 'Item remboursé' ) ); ?></th>
						<?php if ( $has_event_date ) : ?>
							<th>Date événement</th>
						<?php endif; ?>
						<th style="text-align:right;">Qté</th>
						<th style="text-align:right;">Sous-tot.</th>
						<th style="text-align:right;">TPS</th>
						<th style="text-align:right;">TVQ</th>
						<th style="text-align:right;">Total</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $section['items'] as $it ) :
						$status_label = wc_get_order_statuses()[ $it['status'] ] ?? $it['status'];
						$ref_id       = $it['reference_order_id'] ?? $it['order_id'];
						?>
						<tr<?php echo $it['is_refund'] ? ' style="background:#fdecea;"' : ''; ?>>
							<td><?php echo esc_html( mysql2date( 'Y-m-d', $it['date'] ) ); ?></td>
							<td>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-orders&action=edit&id=' . $ref_id ) ); ?>">#<?php echo (int) $ref_id; ?></a>
								<?php if ( $it['is_refund'] ) : ?>
									<span style="font-size:0.85em; color:#999;">↩</span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $status_label ); ?></td>
							<td><?php echo esc_html( $it['payment_label'] ?? '—' ); ?></td>
							<td><?php echo esc_html( $it['client_name'] ); ?></td>
							<td><?php echo esc_html( $it['detail_name'] ); ?></td>
							<?php if ( $has_event_date ) : ?>
								<td><?php echo esc_html( $it['detail_date'] !== '' ? mysql2date( 'Y-m-d H\hi', $it['detail_date'] ) : '—' ); ?></td>
							<?php endif; ?>
							<td style="text-align:right;"><?php echo (int) $it['qty']; ?></td>
							<td style="text-align:right;"><?php echo number_format( $it['subtotal'], 2, ',', ' ' ); ?></td>
							<td style="text-align:right;"><?php echo number_format( $it['tps'], 2, ',', ' ' ); ?></td>
							<td style="text-align:right;"><?php echo number_format( $it['tvq'], 2, ',', ' ' ); ?></td>
							<td style="text-align:right; font-weight:600;"><?php echo number_format( $it['total'], 2, ',', ' ' ); ?></td>
						</tr>
					<?php endforeach; ?>
					<tr style="background:#eef9ee; font-weight:700;">
						<td colspan="<?php echo $colspan_offset; ?>" style="text-align:right; color:#2a7a2a;">✅ Sous-total RÉEL (Complétées + Remboursées) :</td>
						<td style="text-align:right; color:#2a7a2a;"><?php echo number_format( $real['subtotal'], 2, ',', ' ' ); ?></td>
						<td style="text-align:right; color:#2a7a2a;"><?php echo number_format( $real['tps'], 2, ',', ' ' ); ?></td>
						<td style="text-align:right; color:#2a7a2a;"><?php echo number_format( $real['tvq'], 2, ',', ' ' ); ?></td>
						<td style="text-align:right; color:#2a7a2a;"><?php echo number_format( $real['total'], 2, ',', ' ' ); ?></td>
					</tr>
					<?php if ( $pending['total'] != 0 ) : ?>
						<tr style="background:#fff8e6; font-weight:600; font-size:0.92em;">
							<td colspan="<?php echo $colspan_offset; ?>" style="text-align:right; color:#7a5d00;">🔮 Sous-total PRONOSTIC (En cours, En attente, Attente paiement) :</td>
							<td style="text-align:right; color:#7a5d00;"><?php echo number_format( $pending['subtotal'], 2, ',', ' ' ); ?></td>
							<td style="text-align:right; color:#7a5d00;"><?php echo number_format( $pending['tps'], 2, ',', ' ' ); ?></td>
							<td style="text-align:right; color:#7a5d00;"><?php echo number_format( $pending['tvq'], 2, ',', ' ' ); ?></td>
							<td style="text-align:right; color:#7a5d00;"><?php echo number_format( $pending['total'], 2, ',', ' ' ); ?></td>
						</tr>
					<?php endif; ?>
					<?php if ( $cancel['total'] != 0 ) : ?>
						<tr style="background:#f7f7f7; font-weight:600; font-size:0.88em; color:#999;">
							<td colspan="<?php echo $colspan_offset; ?>" style="text-align:right;">🚫 Sous-total ANNULÉ (info seulement, non compté) :</td>
							<td style="text-align:right;"><?php echo number_format( $cancel['subtotal'], 2, ',', ' ' ); ?></td>
							<td style="text-align:right;"><?php echo number_format( $cancel['tps'], 2, ',', ' ' ); ?></td>
							<td style="text-align:right;"><?php echo number_format( $cancel['tvq'], 2, ',', ' ' ); ?></td>
							<td style="text-align:right;"><?php echo number_format( $cancel['total'], 2, ',', ' ' ); ?></td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}

// ============================================================================
// 5) Export CSV
// ============================================================================
add_action( 'admin_post_cordespace_reports_csv', 'cordespace_reports_handle_csv_export' );
function cordespace_reports_handle_csv_export(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'forbidden', 403 );
	}
	check_admin_referer( 'cordespace_reports_csv' );

	$preset           = isset( $_GET['preset'] )   ? sanitize_key( (string) $_GET['preset'] )   : 'this_month';
	$custom_start     = isset( $_GET['date_start'] ) ? sanitize_text_field( (string) $_GET['date_start'] ) : '';
	$custom_end       = isset( $_GET['date_end'] )   ? sanitize_text_field( (string) $_GET['date_end'] )   : '';
	$selected_statuses = isset( $_GET['statuses'] ) && is_array( $_GET['statuses'] )
		? array_map( 'sanitize_text_field', wp_unslash( $_GET['statuses'] ) )
		: [ 'wc-completed' ];

	if ( $preset === 'custom' && $custom_start !== '' && $custom_end !== '' ) {
		$range = [ 'start' => $custom_start . ' 00:00:00', 'end' => $custom_end . ' 23:59:59' ];
	} else {
		$range = cordespace_reports_get_preset_range( $preset );
	}

	$items  = cordespace_reports_fetch_items( $range['start'], $range['end'], $selected_statuses );
	$totals = cordespace_reports_compute_totals( $items );

	$filename = sprintf(
		'cordespace-rapport-%s-au-%s.csv',
		substr( $range['start'], 0, 10 ),
		substr( $range['end'], 0, 10 )
	);

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=' . $filename );

	$out = fopen( 'php://output', 'w' );
	// BOM UTF-8 pour Excel
	fwrite( $out, "\xEF\xBB\xBF" );

	// Header (avec colonnes Catégorie compta + Paiement)
	fputcsv( $out, [
		'Section', 'Catégorie compta', 'Date', 'N° Commande', 'Remboursement',
		'Statut', 'Paiement', 'Client', 'Courriel', 'Détail', 'Date événement',
		'Qté', 'Sous-total', 'TPS', 'TVQ', 'Total',
	], ';' );

	$status_labels   = wc_get_order_statuses();
	$section_labels  = [ 'boutique' => 'Boutique', 'cours' => 'Cours', 'salle' => 'Salle' ];
	$category_labels = [
		'real'      => 'Réel',
		'pending'   => 'Pronostic',
		'cancelled' => 'Annulé',
	];

	foreach ( [ 'boutique', 'cours', 'salle' ] as $sec ) {
		foreach ( $totals['sections'][ $sec ]['items'] as $it ) {
			$cat = cordespace_reports_status_category( $it['status'] );
			fputcsv( $out, [
				$section_labels[ $sec ],
				$category_labels[ $cat ],
				mysql2date( 'Y-m-d', $it['date'] ),
				'#' . ( $it['reference_order_id'] ?? $it['order_id'] ),
				$it['is_refund'] ? 'OUI' : '',
				$status_labels[ $it['status'] ] ?? $it['status'],
				$it['payment_label'] ?? '',
				$it['client_name'],
				$it['client_email'],
				$it['detail_name'],
				$it['detail_date'] !== '' ? mysql2date( 'Y-m-d H:i', $it['detail_date'] ) : '',
				(int) $it['qty'],
				number_format( $it['subtotal'], 2, '.', '' ),
				number_format( $it['tps'], 2, '.', '' ),
				number_format( $it['tvq'], 2, '.', '' ),
				number_format( $it['total'], 2, '.', '' ),
			], ';' );
		}
		// Sous-totaux par catégorie pour cette section
		foreach ( [ 'real', 'pending', 'cancelled' ] as $cat ) {
			$b = $totals['sections'][ $sec ][ $cat ];
			if ( $b['total'] == 0 && $b['qty'] == 0 ) continue;
			fputcsv( $out, [
				'Sous-total ' . $section_labels[ $sec ] . ' (' . $category_labels[ $cat ] . ')',
				$category_labels[ $cat ], '', '', '', '', '', '', '', '', '',
				(int) $b['qty'],
				number_format( $b['subtotal'], 2, '.', '' ),
				number_format( $b['tps'], 2, '.', '' ),
				number_format( $b['tvq'], 2, '.', '' ),
				number_format( $b['total'], 2, '.', '' ),
			], ';' );
		}
		fputcsv( $out, [], ';' ); // ligne vide entre sections
	}

	// Totaux généraux ventilés
	foreach ( [ 'real', 'pending', 'cancelled' ] as $cat ) {
		$b = $totals['grand'][ $cat ];
		fputcsv( $out, [
			'TOTAL GÉNÉRAL ' . strtoupper( $category_labels[ $cat ] ),
			$category_labels[ $cat ], '', '', '', '', '', '', '', '', '',
			(int) $b['qty'],
			number_format( $b['subtotal'], 2, '.', '' ),
			number_format( $b['tps'], 2, '.', '' ),
			number_format( $b['tvq'], 2, '.', '' ),
			number_format( $b['total'], 2, '.', '' ),
		], ';' );
	}

	fclose( $out );
	exit;
}
