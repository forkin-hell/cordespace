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
 * @param string $period_mode 'purchase' (filtre sur date_created_gmt) ou 'event'
 *                            (filtre sur la date de l'événement/réservation depuis
 *                            la meta ameliabooking). Boutique est exclue en mode event.
 * @return array<int, array<string,mixed>>
 */
function cordespace_reports_fetch_items( string $start, string $end, array $statuses, string $period_mode = 'purchase' ): array {
	global $wpdb;

	if ( empty( $statuses ) ) {
		return [];
	}

	// Pour le mode 'event', on élargit la fenêtre SQL sur date_created_gmt (l'achat peut
	// avoir lieu jusqu'à ~1 an avant la date de l'événement). On filtre ensuite côté PHP.
	if ( $period_mode === 'event' ) {
		$sql_start = gmdate( 'Y-m-d H:i:s', strtotime( $start ) - 365 * 24 * 3600 );
		$sql_end   = $end;
	} else {
		$sql_start = $start;
		$sql_end   = $end;
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
		array_merge( [ $sql_start, $sql_end ], $statuses, $statuses )
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

	// MODE 'event' : filtrage PHP final sur detail_date dans la plage demandée.
	// Les items sans date d'événement (boutique pure) sont exclus.
	if ( $period_mode === 'event' ) {
		$start_normalized = substr( $start, 0, 10 ); // YYYY-MM-DD
		$end_normalized   = substr( $end, 0, 10 );
		$items = array_values( array_filter( $items, function ( $it ) use ( $start_normalized, $end_normalized ) {
			if ( $it['detail_date'] === '' ) {
				return false; // boutique sans date d'événement → exclue
			}
			$d = substr( $it['detail_date'], 0, 10 );
			return $d >= $start_normalized && $d <= $end_normalized;
		} ) );
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

/**
 * Top N acheteurs sur la période, en agrégeant les items "réel comptable"
 * (wc-completed + wc-refunded) par client (email prioritaire, fallback name).
 * Net spend = somme des totals (les remboursements ont total négatif → soustraction).
 *
 * @return array<int, array{name:string, email:string, spent:float, order_count:int}>
 */
function cordespace_reports_compute_top_buyers( array $items, int $top_n = 5 ): array {
	$by_client = [];
	foreach ( $items as $it ) {
		if ( ! in_array( $it['status'], [ 'wc-completed', 'wc-refunded' ], true ) ) {
			continue;
		}
		$key = $it['client_email'] !== ''
			? mb_strtolower( $it['client_email'] )
			: mb_strtolower( $it['client_name'] );
		if ( $key === '' ) {
			continue;
		}
		if ( ! isset( $by_client[ $key ] ) ) {
			$by_client[ $key ] = [
				'name'       => $it['client_name'] !== '' ? $it['client_name'] : $it['client_email'],
				'email'      => $it['client_email'],
				'spent'      => 0.0,
				'_order_ids' => [],
			];
		}
		$by_client[ $key ]['spent']                                       += $it['total'];
		$by_client[ $key ]['_order_ids'][ $it['reference_order_id'] ] = true;
	}
	foreach ( $by_client as &$c ) {
		$c['order_count'] = count( $c['_order_ids'] );
		unset( $c['_order_ids'] );
	}
	unset( $c );

	uasort( $by_client, fn( $a, $b ) => $b['spent'] <=> $a['spent'] );
	return array_slice( array_values( $by_client ), 0, $top_n );
}

/**
 * Top N produits par occurrences (= dans combien de commandes distinctes
 * il apparaît) sur la période. "Réel comptable" seulement + on exclut les
 * lignes de remboursement (qui ne sont pas des "ventes").
 * Pour les items Amelia (cours/salles), on regroupe par detail_name (= nom
 * du cours/salle), pas par item_name (nom de la coquille WC).
 *
 * @return array<int, array{name:string, section:string, occurrences:int, qty_total:int}>
 */
function cordespace_reports_compute_top_sellers( array $items, int $top_n = 5 ): array {
	$by_product = [];
	foreach ( $items as $it ) {
		if ( ! in_array( $it['status'], [ 'wc-completed', 'wc-refunded' ], true ) ) {
			continue;
		}
		if ( ! empty( $it['is_refund'] ) ) {
			continue;
		}
		$key = $it['detail_name'] !== '' ? $it['detail_name'] : $it['item_name'];
		if ( $key === '' ) {
			continue;
		}
		if ( ! isset( $by_product[ $key ] ) ) {
			$by_product[ $key ] = [
				'name'        => $key,
				'section'     => $it['section'],
				'occurrences' => 0,
				'qty_total'   => 0,
			];
		}
		$by_product[ $key ]['occurrences'] += 1;
		$by_product[ $key ]['qty_total']   += (int) $it['qty'];
	}

	uasort( $by_product, fn( $a, $b ) => $b['occurrences'] <=> $a['occurrences'] );
	return array_slice( array_values( $by_product ), 0, $top_n );
}

// (la fonction render_top_performers a été supprimée — les tops sont maintenant
//  rendus directement dans cordespace_reports_render_grand_total via $opts.)

/**
 * Rendu : graphique barres horizontales du top N produits par quantité réelle.
 * Utilisé dans Sommaire boutique au-dessus du tableau détaillé.
 */
function cordespace_reports_render_qty_bars( array $grouped, int $top_n = 7 ): void {
	$candidates = array_filter( $grouped, fn( $g ) => ( $g['qty_real'] ?? 0 ) > 0 );
	if ( empty( $candidates ) ) {
		return;
	}
	uasort( $candidates, fn( $a, $b ) => $b['qty_real'] <=> $a['qty_real'] );
	$top = array_slice( array_values( $candidates ), 0, $top_n );
	$max = (int) $top[0]['qty_real'];
	if ( $max <= 0 ) {
		return;
	}
	$rank_emoji = [ 1 => '🥇', 2 => '🥈', 3 => '🥉' ];
	?>
	<div style="margin-top:1.5rem; padding:1.5rem 1.8rem; background:linear-gradient(135deg,#5b2c8f 0%,#1a1a2e 100%); color:#fff; border-radius:8px;">
		<h2 style="margin:0 0 1rem; color:#fff; font-size:1.3em;">📊 Top <?php echo count( $top ); ?> par quantité vendue <span style="opacity:0.7; font-size:0.7em; font-weight:normal;">(réel comptable)</span></h2>
		<?php foreach ( $top as $i => $g ) :
			$pct  = $g['qty_real'] / $max * 100;
			$rank = $i + 1;
			?>
			<div style="display:flex; align-items:center; gap:0.8rem; padding:0.4rem 0; border-bottom:1px solid rgba(255,255,255,0.12);">
				<span style="min-width:1.8em; text-align:center; font-size:1.05em;"><?php echo esc_html( $rank_emoji[ $rank ] ?? '#' . $rank ); ?></span>
				<strong style="flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?php echo esc_html( $g['name'] ); ?></strong>
				<div style="width:140px; background:rgba(255,255,255,0.15); border-radius:4px; height:0.55em; overflow:hidden; flex-shrink:0;">
					<div style="background:linear-gradient(90deg, #c490f0 0%, #f5b1b1 100%); height:100%; width:<?php echo number_format( $pct, 1 ); ?>%;"></div>
				</div>
				<strong style="min-width:5.5em; text-align:right; white-space:nowrap;"><?php echo (int) $g['qty_real']; ?> unité<?php echo $g['qty_real'] > 1 ? 's' : ''; ?></strong>
			</div>
		<?php endforeach; ?>
	</div>
	<?php
}

// ============================================================================
// 4) Page admin (UI) — routage par onglets
// ============================================================================

/**
 * Renvoie la liste des onglets disponibles.
 * @return array<string, array{label: string, icon: string}>
 */
function cordespace_reports_get_tabs(): array {
	return [
		'achats'          => [ 'label' => 'Achats',              'icon' => '📦' ],
		'sommaire'        => [ 'label' => 'Sommaire boutique',   'icon' => '📊' ],
		'credits'         => [ 'label' => 'Historique crédits',  'icon' => '💳' ],
		'credits-globaux' => [ 'label' => 'Soldes crédits',      'icon' => '💰' ],
	];
}

/**
 * Construit une URL d'onglet en conservant les filtres URL pertinents (période,
 * statuts, etc.) pour passer d'un onglet à l'autre sans perdre la sélection.
 */
function cordespace_reports_tab_url( string $tab ): string {
	$preserved_keys = [ 'preset', 'date_start', 'date_end', 'period_mode', 'statuses', 'snapshot_date' ];
	$args           = [ 'page' => CORDESPACE_REPORTS_MENU_SLUG, 'tab' => $tab ];
	foreach ( $preserved_keys as $k ) {
		if ( isset( $_GET[ $k ] ) ) {
			$args[ $k ] = $_GET[ $k ];
		}
	}
	return add_query_arg( $args, admin_url( 'admin.php' ) );
}

/**
 * Page principale : nav onglets + dispatch vers la fonction de rendu du tab.
 */
function cordespace_reports_render_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Accès refusé.', 'cordespace-snippets' ) );
	}

	$tabs        = cordespace_reports_get_tabs();
	$current_tab = isset( $_GET['tab'] ) && isset( $tabs[ $_GET['tab'] ] ) ? sanitize_key( (string) $_GET['tab'] ) : 'achats';
	?>
	<div class="wrap cordespace-reports-page" style="max-width:1400px;">
		<style>
			/* 🦖 Petits dinos décoratifs et animation de saut au clic CSV.
			   Scopé à .cordespace-reports-page pour ne pas affecter le reste de l'admin. */
			.cordespace-reports-page .cordespace-dino-hidden {
				display: inline-block;
				opacity: 1;
				font-size: 1.05em;
				transition: transform 0.25s;
				cursor: default;
				vertical-align: middle;
			}
			.cordespace-reports-page .cordespace-dino-hidden:hover {
				transform: scale(1.4) rotate(-8deg);
			}
			@keyframes cordespace-dino-jump {
				0%   { transform: translate(-50%, 0)    scale(1);   opacity: 1; }
				25%  { transform: translate(-50%, -50px) scale(1.2); opacity: 1; }
				50%  { transform: translate(-50%, -65px) scale(1.3); opacity: 1; }
				75%  { transform: translate(-50%, -20px) scale(1);   opacity: 0.9; }
				100% { transform: translate(-50%, 10px) scale(0.8); opacity: 0; }
			}
			.cordespace-dino-jumper {
				position: absolute;
				font-size: 2em;
				pointer-events: none;
				animation: cordespace-dino-jump 0.9s ease-out forwards;
				z-index: 9999;
			}
		</style>
		<script>
		(function () {
			var dinos = ['🦖', '🦕'];
			document.addEventListener('click', function (e) {
				var btn = e.target.closest('a[href*="cordespace_reports_csv"]');
				if ( ! btn ) return;
				var rect = btn.getBoundingClientRect();
				var d = document.createElement('span');
				d.className = 'cordespace-dino-jumper';
				d.textContent = dinos[ Math.floor( Math.random() * dinos.length ) ];
				d.style.left = ( rect.left + window.scrollX + rect.width / 2 ) + 'px';
				d.style.top  = ( rect.top  + window.scrollY - 4 ) + 'px';
				document.body.appendChild( d );
				setTimeout(function () { d.remove(); }, 1000);
			});
		})();
		</script>
		<h1>📊 Rapports — Cordespace <span class="cordespace-dino-hidden" title="rawr">🦕</span></h1>

		<nav class="nav-tab-wrapper" style="margin-top:1rem;">
			<?php foreach ( $tabs as $key => $info ) : ?>
				<a href="<?php echo esc_url( cordespace_reports_tab_url( $key ) ); ?>"
				   class="nav-tab <?php echo $current_tab === $key ? 'nav-tab-active' : ''; ?>">
					<?php echo esc_html( $info['icon'] . ' ' . $info['label'] ); ?>
				</a>
			<?php endforeach; ?>
		</nav>

		<?php
		switch ( $current_tab ) {
			case 'sommaire':
				cordespace_reports_render_tab_sommaire();
				break;
			case 'credits':
				cordespace_reports_render_tab_credits();
				break;
			case 'credits-globaux':
				cordespace_reports_render_tab_credits_globaux();
				break;
			case 'achats':
			default:
				cordespace_reports_render_tab_achats();
				break;
		}
		?>
	</div>
	<?php
}

/**
 * Onglet 📦 ACHATS : rapport de ventes avec ventilation par section
 * (boutique + cours + salles) et catégorie compta (réel / pronostic / annulé).
 */
function cordespace_reports_render_tab_achats(): void {
	// Lecture des filtres depuis GET
	$period_mode      = isset( $_GET['period_mode'] ) && $_GET['period_mode'] === 'event' ? 'event' : 'purchase';
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
	$items              = cordespace_reports_fetch_items( $range['start'], $range['end'], $selected_statuses, $period_mode );
	$totals             = cordespace_reports_compute_totals( $items );

	$export_url = add_query_arg(
		array_merge(
			[ 'action' => 'cordespace_reports_csv', '_wpnonce' => wp_create_nonce( 'cordespace_reports_csv' ) ],
			$_GET // on transmet les mêmes filtres
		),
		admin_url( 'admin-post.php' )
	);
	?>
		<p style="color:#666; margin-top:1rem;">Ventes (boutique + cours + salles) sur une période donnée, calculées depuis les vrais montants WooCommerce, avec ventilation par catégorie comptable.</p>

		<form method="get" action="" style="background:#fff; border:1px solid #e0e0e0; border-radius:6px; padding:1.2rem 1.5rem; margin-top:1.2rem;">
			<input type="hidden" name="page" value="<?php echo esc_attr( CORDESPACE_REPORTS_MENU_SLUG ); ?>">
			<input type="hidden" name="tab" value="achats">
			<input type="hidden" name="filtered" value="1">

			<div style="display:flex; flex-wrap:wrap; gap:1.5rem; align-items:flex-start;">
				<!-- Période basée sur -->
				<div>
					<label style="font-weight:600; display:block; margin-bottom:0.4rem;">Période basée sur</label>
					<select name="period_mode">
						<option value="purchase" <?php selected( $period_mode, 'purchase' ); ?>>📦 Date d'achat</option>
						<option value="event"    <?php selected( $period_mode, 'event' );    ?>>📅 Date d'événement</option>
					</select>
				</div>

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
				<strong><?php echo $period_mode === 'event' ? '📅 Période d\'événement :' : '📦 Période d\'achat :'; ?></strong>
				<?php echo esc_html( mysql2date( 'j F Y', $range['start'] ) ); ?>
				→
				<?php echo esc_html( mysql2date( 'j F Y', $range['end'] ) ); ?>
				·
				<strong><?php echo count( $items ); ?> lignes</strong>
				<span class="cordespace-dino-hidden" title="rawr">🦖</span>
				<?php if ( $period_mode === 'event' ) : ?>
					<br><span style="color:#666; font-size:0.9em;">(boutique exclue — pas de notion de "date d'événement" pour les items physiques)</span>
				<?php endif; ?>
			</div>
			<a href="<?php echo esc_url( $export_url ); ?>" class="button button-secondary">📥 Télécharger CSV</a>
		</div>

		<?php
		// 🏆 Top performers + ventilation comptable, tout dans le même encadré violet.
		// On masque les blocs Pronostic / Annulées si aucun statut correspondant n'est coché.
		$top_buyers  = cordespace_reports_compute_top_buyers( $items, 5 );
		$top_sellers = cordespace_reports_compute_top_sellers( $items, 5 );

		$selected_categories = [];
		foreach ( $selected_statuses as $st ) {
			$selected_categories[ cordespace_reports_status_category( $st ) ] = true;
		}

		cordespace_reports_render_grand_total( $totals['grand'], [
			'top_buyers'     => $top_buyers,
			'top_sellers'    => $top_sellers,
			'show_pending'   => ! empty( $selected_categories['pending'] ),
			'show_cancelled' => ! empty( $selected_categories['cancelled'] ),
		] );
		?>

		<?php cordespace_reports_render_section( '📚 Boutique', $totals['sections']['boutique'], 'boutique' ); ?>
		<?php cordespace_reports_render_section( '🎓 Cours', $totals['sections']['cours'], 'cours' ); ?>
		<?php cordespace_reports_render_section( '🏠 Salles', $totals['sections']['salle'], 'salle' ); ?>
	<?php
}

/**
 * Onglet 📊 SOMMAIRE BOUTIQUE : agrégation par produit boutique pour une
 * période d'achat donnée. Pas de noms de clients, juste un sommaire :
 * "Quels produits ont été vendus en N unités pour M $ en mai ?"
 */
function cordespace_reports_render_tab_sommaire(): void {
	global $wpdb;

	// Filtres : période + statuts (mêmes que Achats, mais pas de period_mode)
	$preset       = isset( $_GET['preset'] )     ? sanitize_key( (string) $_GET['preset'] )       : 'this_month';
	$custom_start = isset( $_GET['date_start'] ) ? sanitize_text_field( (string) $_GET['date_start'] ) : '';
	$custom_end   = isset( $_GET['date_end'] )   ? sanitize_text_field( (string) $_GET['date_end'] )   : '';

	$available_statuses_for_default = cordespace_reports_get_available_statuses();
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

	if ( $preset === 'custom' && $custom_start !== '' && $custom_end !== '' ) {
		$range = [ 'start' => $custom_start . ' 00:00:00', 'end' => $custom_end . ' 23:59:59' ];
	} else {
		$range = cordespace_reports_get_preset_range( $preset );
	}

	$items     = cordespace_reports_fetch_items( $range['start'], $range['end'], $selected_statuses, 'purchase' );
	$boutique  = array_filter( $items, fn( $it ) => $it['section'] === 'boutique' );

	// Agrégation par produit (key = item_name)
	$grouped = [];
	foreach ( $boutique as $it ) {
		$key = $it['item_name'] !== '' ? $it['item_name'] : 'Produit inconnu';
		if ( ! isset( $grouped[ $key ] ) ) {
			$grouped[ $key ] = [
				'name'      => $key,
				'qty_real'  => 0, 'qty_pending' => 0, 'qty_cancelled' => 0,
				'rev_real'  => 0, 'rev_pending' => 0, 'rev_cancelled' => 0,
				'orders'    => [],
			];
		}
		$cat = cordespace_reports_status_category( $it['status'] );
		$grouped[ $key ][ 'qty_' . $cat ] += (int) $it['qty'];
		$grouped[ $key ][ 'rev_' . $cat ] += $it['total'];
		$grouped[ $key ]['orders'][]       = (int) $it['order_id'];
	}

	// Tri par quantité réelle desc
	uasort( $grouped, fn( $a, $b ) => $b['qty_real'] <=> $a['qty_real'] );

	// Totaux ventilés (réel / pronostic / annulé) sur les items boutique pour
	// le bandeau violet, structure identique à celle utilisée par l'onglet Achats.
	$boutique_totals      = cordespace_reports_compute_totals( array_values( $boutique ) );
	$sommaire_categories  = [];
	foreach ( $selected_statuses as $st ) {
		$sommaire_categories[ cordespace_reports_status_category( $st ) ] = true;
	}

	$export_url = add_query_arg(
		array_merge(
			[ 'action' => 'cordespace_reports_csv_sommaire', '_wpnonce' => wp_create_nonce( 'cordespace_reports_csv_sommaire' ) ],
			$_GET
		),
		admin_url( 'admin-post.php' )
	);
	?>
	<p style="color:#666; margin-top:1rem;">Agrégation par produit boutique sur la période d'achat. Pratique pour l'inventaire mensuel : combien d'unités de chaque produit ont été vendues.</p>

	<form method="get" action="" style="background:#fff; border:1px solid #e0e0e0; border-radius:6px; padding:1.2rem 1.5rem; margin-top:1.2rem;">
		<input type="hidden" name="page" value="<?php echo esc_attr( CORDESPACE_REPORTS_MENU_SLUG ); ?>">
		<input type="hidden" name="tab" value="sommaire">
		<input type="hidden" name="filtered" value="1">

		<div style="display:flex; flex-wrap:wrap; gap:1.5rem; align-items:flex-start;">
			<div>
				<label style="font-weight:600; display:block; margin-bottom:0.4rem;">Période d'achat</label>
				<select name="preset" onchange="document.getElementById('custom_dates_sommaire').style.display = this.value === 'custom' ? 'block' : 'none';">
					<option value="this_month"   <?php selected( $preset, 'this_month' );   ?>>Ce mois</option>
					<option value="last_month"   <?php selected( $preset, 'last_month' );   ?>>Mois précédent</option>
					<option value="this_year"    <?php selected( $preset, 'this_year' );    ?>>Cette année</option>
					<option value="last_30_days" <?php selected( $preset, 'last_30_days' ); ?>>30 derniers jours</option>
					<option value="custom"       <?php selected( $preset, 'custom' );       ?>>Période personnalisée</option>
				</select>
				<div id="custom_dates_sommaire" style="margin-top:0.6rem; display:<?php echo $preset === 'custom' ? 'block' : 'none'; ?>;">
					<label style="font-size:0.9em; display:block; margin-bottom:0.2rem;">Du</label>
					<input type="date" name="date_start" value="<?php echo esc_attr( $custom_start ); ?>">
					<label style="font-size:0.9em; display:block; margin:0.4rem 0 0.2rem;">Au</label>
					<input type="date" name="date_end" value="<?php echo esc_attr( $custom_end ); ?>">
				</div>
			</div>

			<div style="flex:1; min-width:280px;">
				<label style="font-weight:600; display:block; margin-bottom:0.4rem;">Statuts de commande</label>
				<div style="display:flex; flex-wrap:wrap; gap:0.6rem 1.4rem;">
					<?php foreach ( $available_statuses_for_default as $slug => $info ) :
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

			<div style="align-self:flex-end;">
				<button type="submit" class="button button-primary">Filtrer</button>
			</div>
		</div>
	</form>

	<div style="margin-top:1.2rem; padding:1rem 1.4rem; background:#eef5fd; border-left:4px solid #2c70b8; border-radius:5px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem;">
		<div>
			<strong>📦 Période d'achat :</strong>
			<?php echo esc_html( mysql2date( 'j F Y', $range['start'] ) ); ?>
			→
			<?php echo esc_html( mysql2date( 'j F Y', $range['end'] ) ); ?>
			·
			<strong><?php echo count( $grouped ); ?> produits distincts</strong>
			<span class="cordespace-dino-hidden" title="rawr">🦕</span>
		</div>
		<a href="<?php echo esc_url( $export_url ); ?>" class="button button-secondary">📥 Télécharger CSV</a>
	</div>

	<?php
	// Bandeau violet "💰 Total général ventilé" (boutique uniquement). Mêmes
	// règles d'affichage que dans Achats : pas de tops ici, blocs pronostic /
	// annulé masqués si statuts correspondants non cochés.
	cordespace_reports_render_grand_total( $boutique_totals['grand'], [
		'show_pending'   => ! empty( $sommaire_categories['pending'] ),
		'show_cancelled' => ! empty( $sommaire_categories['cancelled'] ),
	] );
	?>

	<?php cordespace_reports_render_qty_bars( $grouped, 7 ); ?>

	<div style="margin-top:1.5rem; padding:1.2rem 1.5rem; background:#fff; border:1px solid #e0e0e0; border-radius:6px;">
		<?php if ( empty( $grouped ) ) : ?>
			<p style="color:#999; font-style:italic; margin:0;">Aucun produit boutique vendu sur cette période avec les statuts sélectionnés.</p>
		<?php else :
			$tot_qty_real = $tot_qty_pen = $tot_qty_can = 0;
			$tot_rev_real = $tot_rev_pen = $tot_rev_can = 0;
			foreach ( $grouped as $g ) {
				$tot_qty_real += $g['qty_real'];     $tot_rev_real += $g['rev_real'];
				$tot_qty_pen  += $g['qty_pending']; $tot_rev_pen  += $g['rev_pending'];
				$tot_qty_can  += $g['qty_cancelled']; $tot_rev_can  += $g['rev_cancelled'];
			}
			?>
			<table class="widefat striped" style="font-size:0.92em;">
				<thead>
					<tr>
						<th>Produit</th>
						<th style="text-align:right;">✅ Qté réelle</th>
						<th style="text-align:right;">✅ Revenu réel</th>
						<th style="text-align:right;">🔮 Qté pronostic</th>
						<th style="text-align:right;">🔮 Revenu pronostic</th>
						<th style="text-align:right;">🚫 Qté annulée</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $grouped as $g ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $g['name'] ); ?></strong></td>
							<td style="text-align:right; color:#2a7a2a;"><strong><?php echo (int) $g['qty_real']; ?></strong></td>
							<td style="text-align:right; color:#2a7a2a;"><?php echo number_format( $g['rev_real'], 2, ',', ' ' ); ?> $</td>
							<td style="text-align:right; color:#7a5d00;"><?php echo (int) $g['qty_pending']; ?></td>
							<td style="text-align:right; color:#7a5d00;"><?php echo number_format( $g['rev_pending'], 2, ',', ' ' ); ?> $</td>
							<td style="text-align:right; color:#999;"><?php echo (int) $g['qty_cancelled']; ?></td>
						</tr>
					<?php endforeach; ?>
					<tr style="background:#eef9ee; font-weight:700;">
						<td>TOTAUX</td>
						<td style="text-align:right; color:#2a7a2a;"><?php echo (int) $tot_qty_real; ?></td>
						<td style="text-align:right; color:#2a7a2a;"><?php echo number_format( $tot_rev_real, 2, ',', ' ' ); ?> $</td>
						<td style="text-align:right; color:#7a5d00;"><?php echo (int) $tot_qty_pen; ?></td>
						<td style="text-align:right; color:#7a5d00;"><?php echo number_format( $tot_rev_pen, 2, ',', ' ' ); ?> $</td>
						<td style="text-align:right; color:#999;"><?php echo (int) $tot_qty_can; ?></td>
					</tr>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Liste des types de mouvement MyCred avec labels lisibles.
 *
 * @return array<string, array{label: string, icon: string, category: string}>
 *   category = 'gain' | 'spend' | 'adjust' (pour les sous-totaux)
 */
function cordespace_reports_get_credit_ref_labels(): array {
	return [
		'manual'              => [ 'label' => 'Ajustement manuel',       'icon' => '✏️', 'category' => 'adjust' ],
		'compensation'        => [ 'label' => 'Compensation',            'icon' => '🎁', 'category' => 'gain' ],
		'woocommerce_payment' => [ 'label' => 'Paiement commande',       'icon' => '🛒', 'category' => 'spend' ],
		'woocommerce_refund'  => [ 'label' => 'Remboursement commande',  'icon' => '🔄', 'category' => 'gain' ],
	];
}

/**
 * Récupère les mouvements MyCred sur une période avec filtres.
 *
 * @return array<int, array<string, mixed>>
 */
function cordespace_reports_fetch_credit_log( string $start, string $end, array $refs = [], string $user_search = '' ): array {
	global $wpdb;

	$start_ts = (int) get_gmt_from_date( $start, 'U' );
	$end_ts   = (int) get_gmt_from_date( $end, 'U' );

	$conditions = [ 'l.time BETWEEN %d AND %d' ];
	$params     = [ $start_ts, $end_ts ];

	if ( ! empty( $refs ) ) {
		$placeholders = implode( ',', array_fill( 0, count( $refs ), '%s' ) );
		$conditions[] = "l.ref IN ($placeholders)";
		$params       = array_merge( $params, $refs );
	}

	if ( $user_search !== '' ) {
		$conditions[] = '(u.user_email LIKE %s OR u.display_name LIKE %s OR u.user_login LIKE %s)';
		$like         = '%' . $wpdb->esc_like( $user_search ) . '%';
		$params       = array_merge( $params, [ $like, $like, $like ] );
	}

	$where_sql = implode( ' AND ', $conditions );

	$sql = $wpdb->prepare(
		"SELECT l.id, l.time, l.user_id, l.ref, l.ref_id, l.creds, l.entry,
		        u.user_email, u.display_name
		   FROM {$wpdb->prefix}myCRED_log l
		   LEFT JOIN {$wpdb->users} u ON u.ID = l.user_id
		  WHERE $where_sql
		  ORDER BY l.time DESC",
		$params
	);

	$rows = $wpdb->get_results( $sql, ARRAY_A );
	return is_array( $rows ) ? $rows : [];
}

/**
 * Remplace les placeholders dans l'entry MyCred (%order_id% etc.) par les
 * valeurs réelles pour un affichage propre.
 */
function cordespace_reports_format_credit_entry( string $entry, array $row ): string {
	$entry = str_replace( '%order_id%', '#' . (int) $row['ref_id'], $entry );
	$entry = str_replace( '%singular%', 'crédit', $entry );
	$entry = str_replace( '%plural%',   'crédits', $entry );
	return $entry;
}

/**
 * Onglet 💳 HISTORIQUE CRÉDITS : tous les mouvements MyCred (achats,
 * compensations, ajustements, remboursements) sur une période, avec filtres
 * par type et par user, et export CSV.
 */
function cordespace_reports_render_tab_credits(): void {
	// Filtres
	$preset       = isset( $_GET['preset'] )       ? sanitize_key( (string) $_GET['preset'] )         : 'this_month';
	$custom_start = isset( $_GET['date_start'] )   ? sanitize_text_field( (string) $_GET['date_start'] ) : '';
	$custom_end   = isset( $_GET['date_end'] )     ? sanitize_text_field( (string) $_GET['date_end'] )   : '';
	$user_search  = isset( $_GET['user_search'] )  ? sanitize_text_field( (string) $_GET['user_search'] ) : '';

	$ref_labels = cordespace_reports_get_credit_ref_labels();
	$all_refs   = array_keys( $ref_labels );

	$has_explicit_filter = isset( $_GET['filtered'] ) && $_GET['filtered'] === '1';
	$selected_refs       = isset( $_GET['refs'] ) && is_array( $_GET['refs'] )
		? array_map( 'sanitize_text_field', wp_unslash( $_GET['refs'] ) )
		: ( $has_explicit_filter ? [] : $all_refs );

	if ( $preset === 'custom' && $custom_start !== '' && $custom_end !== '' ) {
		$range = [ 'start' => $custom_start . ' 00:00:00', 'end' => $custom_end . ' 23:59:59' ];
	} else {
		$range = cordespace_reports_get_preset_range( $preset );
	}

	$rows = cordespace_reports_fetch_credit_log( $range['start'], $range['end'], $selected_refs, $user_search );

	// Calcul des sous-totaux par catégorie + total net
	$cats = [ 'gain' => 0, 'spend' => 0, 'adjust_pos' => 0, 'adjust_neg' => 0 ];
	foreach ( $rows as $r ) {
		$ref = $r['ref'];
		$amt = (float) $r['creds'];
		if ( ! isset( $ref_labels[ $ref ] ) ) {
			continue;
		}
		if ( $ref_labels[ $ref ]['category'] === 'gain' ) {
			$cats['gain'] += $amt;
		} elseif ( $ref_labels[ $ref ]['category'] === 'spend' ) {
			$cats['spend'] += $amt;
		} else {
			if ( $amt >= 0 ) $cats['adjust_pos'] += $amt;
			else             $cats['adjust_neg'] += $amt;
		}
	}
	$net = $cats['gain'] + $cats['spend'] + $cats['adjust_pos'] + $cats['adjust_neg'];

	$export_url = add_query_arg(
		array_merge(
			[ 'action' => 'cordespace_reports_csv_credits', '_wpnonce' => wp_create_nonce( 'cordespace_reports_csv_credits' ) ],
			$_GET
		),
		admin_url( 'admin-post.php' )
	);
	?>
	<p style="color:#666; margin-top:1rem;">Tous les mouvements MyCred sur la période : achats payés en crédits, compensations, remboursements, ajustements manuels admin.</p>

	<form method="get" action="" style="background:#fff; border:1px solid #e0e0e0; border-radius:6px; padding:1.2rem 1.5rem; margin-top:1.2rem;">
		<input type="hidden" name="page" value="<?php echo esc_attr( CORDESPACE_REPORTS_MENU_SLUG ); ?>">
		<input type="hidden" name="tab" value="credits">
		<input type="hidden" name="filtered" value="1">

		<div style="display:flex; flex-wrap:wrap; gap:1.5rem; align-items:flex-start;">
			<div>
				<label style="font-weight:600; display:block; margin-bottom:0.4rem;">Période</label>
				<select name="preset" onchange="document.getElementById('custom_dates_credits').style.display = this.value === 'custom' ? 'block' : 'none';">
					<option value="this_month"   <?php selected( $preset, 'this_month' );   ?>>Ce mois</option>
					<option value="last_month"   <?php selected( $preset, 'last_month' );   ?>>Mois précédent</option>
					<option value="this_year"    <?php selected( $preset, 'this_year' );    ?>>Cette année</option>
					<option value="last_30_days" <?php selected( $preset, 'last_30_days' ); ?>>30 derniers jours</option>
					<option value="custom"       <?php selected( $preset, 'custom' );       ?>>Période personnalisée</option>
				</select>
				<div id="custom_dates_credits" style="margin-top:0.6rem; display:<?php echo $preset === 'custom' ? 'block' : 'none'; ?>;">
					<label style="font-size:0.9em; display:block; margin-bottom:0.2rem;">Du</label>
					<input type="date" name="date_start" value="<?php echo esc_attr( $custom_start ); ?>">
					<label style="font-size:0.9em; display:block; margin:0.4rem 0 0.2rem;">Au</label>
					<input type="date" name="date_end" value="<?php echo esc_attr( $custom_end ); ?>">
				</div>
			</div>

			<div>
				<label style="font-weight:600; display:block; margin-bottom:0.4rem;">Types de mouvement</label>
				<div style="display:flex; flex-direction:column; gap:0.3rem;">
					<?php foreach ( $ref_labels as $ref => $info ) :
						$checked = in_array( $ref, $selected_refs, true );
						?>
						<label style="display:flex; align-items:center; gap:0.4rem; font-size:0.95em;">
							<input type="checkbox" name="refs[]" value="<?php echo esc_attr( $ref ); ?>" <?php checked( $checked ); ?>>
							<?php echo esc_html( $info['icon'] . ' ' . $info['label'] ); ?>
						</label>
					<?php endforeach; ?>
				</div>
			</div>

			<div>
				<label style="font-weight:600; display:block; margin-bottom:0.4rem;">Filtre utilisateur·trice</label>
				<input type="text" name="user_search" value="<?php echo esc_attr( $user_search ); ?>" placeholder="nom, courriel ou login" style="width:260px;">
				<p style="margin:0.4rem 0 0; color:#999; font-size:0.85em;">Vide = tous les users</p>
			</div>

			<div style="align-self:flex-end;">
				<button type="submit" class="button button-primary">Filtrer</button>
			</div>
		</div>
	</form>

	<div style="margin-top:1.2rem; padding:1rem 1.4rem; background:#eef5fd; border-left:4px solid #2c70b8; border-radius:5px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem;">
		<div>
			<strong>📅 Période :</strong>
			<?php echo esc_html( mysql2date( 'j F Y', $range['start'] ) ); ?>
			→
			<?php echo esc_html( mysql2date( 'j F Y', $range['end'] ) ); ?>
			·
			<strong><?php echo count( $rows ); ?> mouvements</strong>
			<span class="cordespace-dino-hidden" title="rawr">🦖</span>
		</div>
		<a href="<?php echo esc_url( $export_url ); ?>" class="button button-secondary">📥 Télécharger CSV</a>
	</div>

	<!-- Sous-totaux par catégorie -->
	<div style="margin-top:1.5rem; padding:1.5rem 1.8rem; background:linear-gradient(135deg,#5b2c8f 0%,#1a1a2e 100%); color:#fff; border-radius:8px;">
		<h2 style="margin:0 0 1rem; color:#fff; font-size:1.2em;">💰 Solde net de la période</h2>
		<div style="display:flex; flex-wrap:wrap; gap:1.8rem;">
			<div><span style="opacity:0.7; font-size:0.85em; display:block;">🎁 Gains (compensations + remb.)</span><strong style="font-size:1.4em; color:#7eff7e;">+<?php echo number_format( $cats['gain'], 2, ',', ' ' ); ?></strong></div>
			<div><span style="opacity:0.7; font-size:0.85em; display:block;">🛒 Dépenses (achats)</span><strong style="font-size:1.4em; color:#ffb0b0;"><?php echo number_format( $cats['spend'], 2, ',', ' ' ); ?></strong></div>
			<div><span style="opacity:0.7; font-size:0.85em; display:block;">✏️ Ajustements +</span><strong style="font-size:1.2em; color:#7eff7e;">+<?php echo number_format( $cats['adjust_pos'], 2, ',', ' ' ); ?></strong></div>
			<div><span style="opacity:0.7; font-size:0.85em; display:block;">✏️ Ajustements −</span><strong style="font-size:1.2em; color:#ffb0b0;"><?php echo number_format( $cats['adjust_neg'], 2, ',', ' ' ); ?></strong></div>
			<div style="border-left:1px solid rgba(255,255,255,0.3); padding-left:1.8rem;"><span style="opacity:0.7; font-size:0.85em; display:block;">SOLDE NET</span><strong style="font-size:1.6em;"><?php echo number_format( $net, 2, ',', ' ' ); ?></strong></div>
		</div>
	</div>

	<!-- Tableau -->
	<div style="margin-top:1.5rem; padding:1.2rem 1.5rem; background:#fff; border:1px solid #e0e0e0; border-radius:6px;">
		<?php if ( empty( $rows ) ) : ?>
			<p style="color:#999; font-style:italic; margin:0;">Aucun mouvement sur cette période avec les filtres sélectionnés.</p>
		<?php else : ?>
			<table class="widefat striped" style="font-size:0.92em;">
				<thead>
					<tr>
						<th>Date / Heure</th>
						<th>Utilisateur·trice</th>
						<th>Type</th>
						<th>Description</th>
						<th># Commande</th>
						<th style="text-align:right;">Montant</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $r ) :
						$ref      = (string) $r['ref'];
						$ref_info = $ref_labels[ $ref ] ?? [ 'label' => $ref, 'icon' => '❓' ];
						$amt      = (float) $r['creds'];
						$entry    = cordespace_reports_format_credit_entry( (string) ( $r['entry'] ?? '' ), $r );
						$has_order = in_array( $ref, [ 'woocommerce_payment', 'woocommerce_refund' ], true ) && $r['ref_id'];
						?>
						<tr>
							<td>
								<?php echo esc_html( mysql2date( 'Y-m-d', gmdate( 'Y-m-d H:i:s', (int) $r['time'] ) ) ); ?>
								<br><span style="color:#999; font-size:0.9em;"><?php echo esc_html( mysql2date( 'H\hi', gmdate( 'Y-m-d H:i:s', (int) $r['time'] ) ) ); ?></span>
							</td>
							<td>
								<strong><?php echo esc_html( (string) ( $r['display_name'] ?? '—' ) ); ?></strong>
								<br><span style="color:#666; font-size:0.85em;"><?php echo esc_html( (string) ( $r['user_email'] ?? '' ) ); ?></span>
							</td>
							<td><?php echo esc_html( $ref_info['icon'] . ' ' . $ref_info['label'] ); ?></td>
							<td style="font-size:0.9em; color:#555;"><?php echo esc_html( $entry ); ?></td>
							<td>
								<?php if ( $has_order ) : ?>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-orders&action=edit&id=' . (int) $r['ref_id'] ) ); ?>">#<?php echo (int) $r['ref_id']; ?></a>
								<?php else : ?>
									<span style="color:#999;">—</span>
								<?php endif; ?>
							</td>
							<td style="text-align:right; font-weight:600; color:<?php echo $amt >= 0 ? '#2a7a2a' : '#b91c1c'; ?>;">
								<?php echo $amt >= 0 ? '+' : ''; ?><?php echo number_format( $amt, 2, ',', ' ' ); ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Détecte le rôle Cordespace d'un user :
 *   - 'prof'        : a un rôle wpamelia-provider ou wpamelia-manager
 *   - 'prof_client' : compte client lié à un compte prof (via _cordespace_linked_user_id)
 *   - 'admin'       : a un rôle administrator
 *   - 'client'      : tout autre cas
 *
 * Pour le cas 'prof_client', renvoie aussi le display_name du compte prof lié.
 *
 * @return array{role: string, prof_name: string}
 */
function cordespace_reports_detect_user_role( string $caps_serialized, ?int $linked_user_id ): array {
	$caps_str = $caps_serialized;
	if ( stripos( $caps_str, '"administrator"' ) !== false ) {
		return [ 'role' => 'admin', 'prof_name' => '' ];
	}
	if ( stripos( $caps_str, '"wpamelia-provider"' ) !== false
	     || stripos( $caps_str, '"wpamelia-manager"' ) !== false ) {
		return [ 'role' => 'prof', 'prof_name' => '' ];
	}
	// Compte client lié à un compte prof → "prof_client"
	if ( $linked_user_id && $linked_user_id > 0 ) {
		global $wpdb;
		$linked_caps = $wpdb->get_var( $wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->usermeta} WHERE user_id = %d AND meta_key = %s",
			$linked_user_id,
			$wpdb->prefix . 'capabilities'
		) );
		if ( $linked_caps
		     && ( stripos( (string) $linked_caps, '"wpamelia-provider"' ) !== false
		          || stripos( (string) $linked_caps, '"wpamelia-manager"' ) !== false ) ) {
			$linked_name = $wpdb->get_var( $wpdb->prepare(
				"SELECT display_name FROM {$wpdb->users} WHERE ID = %d",
				$linked_user_id
			) );
			return [ 'role' => 'prof_client', 'prof_name' => (string) ( $linked_name ?: '' ) ];
		}
	}
	return [ 'role' => 'client', 'prof_name' => '' ];
}

/**
 * Renvoie le label visuel d'un rôle.
 */
function cordespace_reports_role_badge( string $role, string $prof_name = '' ): string {
	switch ( $role ) {
		case 'admin':       return '👑 Admin';
		case 'prof':        return '🎓 Prof';
		case 'prof_client': return '🎓 Prof (compte client, lié à : ' . esc_html( $prof_name ) . ')';
		case 'client':      return '🛒 Client';
	}
	return $role;
}

/**
 * Récupère les soldes MyCred à une date donnée (snapshot).
 *
 * Approche : prend le solde actuel et soustrait les mouvements postérieurs
 * à la date du snapshot. Plus fiable que de partir de 0 et additionner (qui
 * raterait les users dont le solde a été set en dehors du log).
 *
 * @return array<int, array<string, mixed>>
 */
function cordespace_reports_fetch_balances_at( string $snapshot_date_end ): array {
	global $wpdb;

	$snapshot_ts = (int) get_gmt_from_date( $snapshot_date_end, 'U' );
	$caps_key    = $wpdb->prefix . 'capabilities';

	$sql = $wpdb->prepare(
		"SELECT
			u.ID AS user_id,
			u.user_email,
			u.display_name,
			u.user_login,
			CAST(COALESCE(um_bal.meta_value, '0') AS DECIMAL(22,2)) AS current_balance,
			COALESCE(SUM(CASE WHEN l.time > %d THEN l.creds ELSE 0 END), 0) AS post_movements,
			MAX(um_caps.meta_value) AS capabilities,
			MAX(CAST(um_link.meta_value AS UNSIGNED)) AS linked_user_id
		   FROM {$wpdb->users} u
		   LEFT JOIN {$wpdb->usermeta} um_bal  ON um_bal.user_id  = u.ID AND um_bal.meta_key  = 'mycred_default'
		   LEFT JOIN {$wpdb->usermeta} um_caps ON um_caps.user_id = u.ID AND um_caps.meta_key = %s
		   LEFT JOIN {$wpdb->usermeta} um_link ON um_link.user_id = u.ID AND um_link.meta_key = '_cordespace_linked_user_id'
		   LEFT JOIN {$wpdb->prefix}myCRED_log l ON l.user_id = u.ID AND l.ctype = 'mycred_default'
		  WHERE (um_bal.meta_value IS NOT NULL AND um_bal.meta_value != '0')
		     OR l.id IS NOT NULL
		  GROUP BY u.ID, u.user_email, u.display_name, u.user_login, um_bal.meta_value
		  ORDER BY u.display_name ASC",
		$snapshot_ts,
		$caps_key
	);

	$rows = $wpdb->get_results( $sql, ARRAY_A );
	if ( ! is_array( $rows ) ) {
		return [];
	}

	$out = [];
	foreach ( $rows as $r ) {
		$balance_at = (float) $r['current_balance'] - (float) $r['post_movements'];
		// On exclut les users avec 0 solde au snapshot (pas pertinent à montrer)
		if ( abs( $balance_at ) < 0.005 ) {
			continue;
		}
		$linked_id   = (int) ( $r['linked_user_id'] ?? 0 );
		$role_info   = cordespace_reports_detect_user_role( (string) ( $r['capabilities'] ?? '' ), $linked_id );
		$out[]       = [
			'user_id'        => (int) $r['user_id'],
			'display_name'   => (string) $r['display_name'],
			'email'          => (string) $r['user_email'],
			'login'          => (string) $r['user_login'],
			'balance'        => $balance_at,
			'current'        => (float) $r['current_balance'],
			'role'           => $role_info['role'],
			'prof_name'      => $role_info['prof_name'],
			'linked_user_id' => $linked_id,
			'is_anomaly'     => false,
		];
	}

	// Détection d'anomalies : un prof ET son·ses compte·s client lié·s ont tous
	// les deux des crédits non-nuls. Les crédits devraient TOUJOURS être sur le
	// compte client, jamais sur le compte prof — donc ce cas trahit un transfert
	// manqué ou un achat fait par erreur côté prof.
	$prof_ids_with_credits = [];
	$linked_prof_ids       = [];
	foreach ( $out as $row ) {
		if ( $row['role'] === 'prof' ) {
			$prof_ids_with_credits[ $row['user_id'] ] = true;
		} elseif ( $row['role'] === 'prof_client' && $row['linked_user_id'] > 0 ) {
			$linked_prof_ids[ $row['linked_user_id'] ] = true;
		}
	}
	foreach ( $out as &$row ) {
		if ( $row['role'] === 'prof' && isset( $linked_prof_ids[ $row['user_id'] ] ) ) {
			$row['is_anomaly'] = true;
		} elseif ( $row['role'] === 'prof_client' && isset( $prof_ids_with_credits[ $row['linked_user_id'] ] ) ) {
			$row['is_anomaly'] = true;
		}
	}
	unset( $row );

	// Tri par solde desc
	usort( $out, fn( $a, $b ) => $b['balance'] <=> $a['balance'] );

	return $out;
}

/**
 * Onglet 💰 SOLDES CRÉDITS : snapshot des soldes MyCred à une date donnée,
 * avec indicateur de rôle (admin / prof / client lié à prof / client) et CSV.
 */
function cordespace_reports_render_tab_credits_globaux(): void {
	$snapshot_date = isset( $_GET['snapshot_date'] ) ? sanitize_text_field( (string) $_GET['snapshot_date'] ) : wp_date( 'Y-m-d' );
	$role_filter   = isset( $_GET['role_filter'] )   ? sanitize_key( (string) $_GET['role_filter'] )         : 'all';
	$user_search   = isset( $_GET['user_search'] )   ? sanitize_text_field( (string) $_GET['user_search'] )  : '';

	$snapshot_end = $snapshot_date . ' 23:59:59';
	$balances     = cordespace_reports_fetch_balances_at( $snapshot_end );

	// Filtre par rôle. 'prof' regroupe les vrais profs ET les profs qui
	// sont sur leur compte client (linked).
	if ( $role_filter === 'prof' ) {
		$balances = array_values( array_filter( $balances, fn( $b ) => in_array( $b['role'], [ 'prof', 'prof_client' ], true ) ) );
	} elseif ( $role_filter !== 'all' ) {
		$balances = array_values( array_filter( $balances, fn( $b ) => $b['role'] === $role_filter ) );
	}

	// Filtre user texte libre
	if ( $user_search !== '' ) {
		$needle   = mb_strtolower( $user_search );
		$balances = array_values( array_filter( $balances, function ( $b ) use ( $needle ) {
			return stripos( $b['display_name'], $needle ) !== false
			    || stripos( $b['email'], $needle ) !== false
			    || stripos( $b['login'], $needle ) !== false;
		} ) );
	}

	// Sous-totaux par catégorie + compteur d'anomalies
	$tots = [ 'admin' => 0, 'prof' => 0, 'prof_client' => 0, 'client' => 0 ];
	$cnts = [ 'admin' => 0, 'prof' => 0, 'prof_client' => 0, 'client' => 0 ];
	$grand          = 0;
	$anomaly_count  = 0;
	$anomaly_pairs  = []; // user_id de prof => true (pour compter par paire, pas par ligne)
	foreach ( $balances as $b ) {
		$tots[ $b['role'] ] += $b['balance'];
		$cnts[ $b['role'] ] += 1;
		$grand              += $b['balance'];
		if ( ! empty( $b['is_anomaly'] ) ) {
			$anomaly_count++;
			$prof_id = $b['role'] === 'prof' ? $b['user_id'] : $b['linked_user_id'];
			$anomaly_pairs[ $prof_id ] = true;
		}
	}
	$anomaly_pair_count = count( $anomaly_pairs );

	$export_url = add_query_arg(
		array_merge(
			[ 'action' => 'cordespace_reports_csv_balances', '_wpnonce' => wp_create_nonce( 'cordespace_reports_csv_balances' ) ],
			$_GET
		),
		admin_url( 'admin-post.php' )
	);
	?>
	<p style="color:#666; margin-top:1rem;">Solde MyCred de chaque utilisateur·trice à une date précise (snapshot). Le calcul se fait à partir du solde actuel moins les mouvements postérieurs à la date — fiable même pour les anciens comptes.</p>

	<form method="get" action="" style="background:#fff; border:1px solid #e0e0e0; border-radius:6px; padding:1.2rem 1.5rem; margin-top:1.2rem;">
		<input type="hidden" name="page" value="<?php echo esc_attr( CORDESPACE_REPORTS_MENU_SLUG ); ?>">
		<input type="hidden" name="tab" value="credits-globaux">

		<div style="display:flex; flex-wrap:wrap; gap:1.5rem; align-items:flex-end;">
			<div>
				<label style="font-weight:600; display:block; margin-bottom:0.4rem;">Date du snapshot</label>
				<input type="date" name="snapshot_date" value="<?php echo esc_attr( $snapshot_date ); ?>">
				<p style="margin:0.3rem 0 0; color:#999; font-size:0.85em;">Solde à la fin de cette journée</p>
			</div>

			<div>
				<label style="font-weight:600; display:block; margin-bottom:0.4rem;">Rôle</label>
				<select name="role_filter">
					<option value="all"    <?php selected( $role_filter, 'all' );    ?>>Tous</option>
					<option value="prof"   <?php selected( $role_filter, 'prof' );   ?>>🎓 Profs (incl. comptes client liés)</option>
					<option value="client" <?php selected( $role_filter, 'client' ); ?>>🛒 Clients</option>
				</select>
			</div>

			<div>
				<label style="font-weight:600; display:block; margin-bottom:0.4rem;">Filtre utilisateur·trice</label>
				<input type="text" name="user_search" value="<?php echo esc_attr( $user_search ); ?>" placeholder="nom, courriel ou login" style="width:240px;">
			</div>

			<div>
				<button type="submit" class="button button-primary">Filtrer</button>
			</div>
		</div>
	</form>

	<div style="margin-top:1.2rem; padding:1rem 1.4rem; background:#eef5fd; border-left:4px solid #2c70b8; border-radius:5px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:1rem;">
		<div>
			<strong>📅 Snapshot au :</strong>
			<?php echo esc_html( mysql2date( 'j F Y', $snapshot_date . ' 00:00:00' ) ); ?>
			·
			<strong><?php echo count( $balances ); ?> utilisateur·trices avec solde non-nul</strong>
			<span class="cordespace-dino-hidden" title="rawr">🦕</span>
		</div>
		<a href="<?php echo esc_url( $export_url ); ?>" class="button button-secondary">📥 Télécharger CSV</a>
	</div>

	<!-- Totaux par catégorie -->
	<div style="margin-top:1.5rem; padding:1.5rem 1.8rem; background:linear-gradient(135deg,#5b2c8f 0%,#1a1a2e 100%); color:#fff; border-radius:8px;">
		<h2 style="margin:0 0 1rem; color:#fff; font-size:1.2em;">💰 Soldes totaux par catégorie</h2>
		<div style="display:flex; flex-wrap:wrap; gap:1.8rem;">
			<div><span style="opacity:0.7; font-size:0.85em; display:block;">🛒 Clients (<?php echo (int) $cnts['client']; ?>)</span><strong style="font-size:1.4em;"><?php echo number_format( $tots['client'], 2, ',', ' ' ); ?></strong></div>
			<div><span style="opacity:0.7; font-size:0.85em; display:block;">🎓 Profs (<?php echo (int) ( $cnts['prof'] + $cnts['prof_client'] ); ?>)</span><strong style="font-size:1.4em;"><?php echo number_format( $tots['prof'] + $tots['prof_client'], 2, ',', ' ' ); ?></strong></div>
			<div><span style="opacity:0.7; font-size:0.85em; display:block;">👑 Admins (<?php echo (int) $cnts['admin']; ?>)</span><strong style="font-size:1.4em;"><?php echo number_format( $tots['admin'], 2, ',', ' ' ); ?></strong></div>
			<div style="border-left:1px solid rgba(255,255,255,0.3); padding-left:1.8rem;"><span style="opacity:0.7; font-size:0.85em; display:block;">TOTAL EN CIRCULATION</span><strong style="font-size:1.7em;"><?php echo number_format( $grand, 2, ',', ' ' ); ?></strong></div>
		</div>
	</div>

	<?php if ( $anomaly_pair_count > 0 ) : ?>
		<div style="margin-top:1.5rem; padding:1rem 1.3rem; background:#fff3cd; border:1px solid #f0ad4e; border-radius:6px; color:#856404;">
			<strong style="font-size:1.05em;">⚠️ <?php echo (int) $anomaly_pair_count; ?> anomalie<?php echo $anomaly_pair_count > 1 ? 's' : ''; ?> détectée<?php echo $anomaly_pair_count > 1 ? 's' : ''; ?> :</strong>
			crédits MyCred présents à la fois sur un compte prof ET son compte client lié.
			<br>
			<span style="font-size:0.92em;">En théorie, tous les crédits devraient être sur le compte client (c'est ce compte qui sert pour les achats). Les lignes concernées sont surlignées en jaune ci-dessous. À consolider manuellement dans <em>wp-admin → Points</em>.</span>
		</div>
	<?php endif; ?>

	<!-- Tableau -->
	<div style="margin-top:1.5rem; padding:1.2rem 1.5rem; background:#fff; border:1px solid #e0e0e0; border-radius:6px;">
		<?php if ( empty( $balances ) ) : ?>
			<p style="color:#999; font-style:italic; margin:0;">Aucun·e utilisateur·trice avec solde non-nul à cette date.</p>
		<?php else : ?>
			<table class="widefat striped" style="font-size:0.92em;">
				<thead>
					<tr>
						<th>Utilisateur·trice</th>
						<th>Rôle</th>
						<th style="text-align:right;">Solde au snapshot</th>
						<th style="text-align:right;">Solde actuel</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $balances as $b ) : ?>
						<tr<?php echo $b['is_anomaly'] ? ' style="background:#fff8e1;"' : ''; ?>>
							<td>
								<?php if ( $b['is_anomaly'] ) : ?>
									<div style="margin-bottom:0.4rem; padding:0.25rem 0.5rem; background:#fff3cd; border-left:3px solid #f0ad4e; color:#856404; font-size:0.82em; line-height:1.3;">⚠️ Crédits sur 2 comptes liés — à consolider</div>
								<?php endif; ?>
								<strong><?php echo esc_html( $b['display_name'] !== '' ? $b['display_name'] : $b['login'] ); ?></strong>
								<br><span style="color:#666; font-size:0.85em;"><?php echo esc_html( $b['email'] ); ?></span>
							</td>
							<td><?php echo wp_kses_post( cordespace_reports_role_badge( $b['role'], $b['prof_name'] ) ); ?></td>
							<td style="text-align:right; font-weight:600;"><?php echo number_format( $b['balance'], 2, ',', ' ' ); ?></td>
							<td style="text-align:right; color:#666; font-size:0.9em;"><?php echo number_format( $b['current'], 2, ',', ' ' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}

function cordespace_reports_render_grand_total( array $grand, array $opts = [] ): void {
	$top_buyers     = $opts['top_buyers']     ?? [];
	$top_sellers    = $opts['top_sellers']    ?? [];
	$show_pending   = $opts['show_pending']   ?? true;
	$show_cancelled = $opts['show_cancelled'] ?? true;
	$section_emoji  = [ 'boutique' => '🛍️', 'cours' => '🎓', 'salle' => '🏠' ];
	$rank_emoji     = [ 1 => '🥇', 2 => '🥈', 3 => '🥉' ];
	?>
	<div style="margin-top:1.5rem; padding:1.5rem 1.8rem; background:linear-gradient(135deg,#5b2c8f 0%,#1a1a2e 100%); color:#fff; border-radius:8px; position:relative;">
		<h2 style="margin:0 0 1.2rem; color:#fff; font-size:1.3em;">💰 Total général ventilé <span class="cordespace-dino-hidden" title="rawr">🦖</span></h2>

		<?php if ( ! empty( $top_buyers ) || ! empty( $top_sellers ) ) : ?>
			<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(310px, 1fr)); gap:1rem; margin-bottom:1.2rem;">
				<?php if ( ! empty( $top_buyers ) ) : ?>
					<div style="padding:1rem 1.2rem; background:rgba(255,255,255,0.08); border-radius:6px;">
						<h3 style="margin:0 0 0.6rem; color:#fff; font-size:1em;">🏆 Top 5 acheteurs <span style="opacity:0.6; font-size:0.78em; font-weight:normal;">(réel comptable)</span></h3>
						<ol style="margin:0; padding:0; list-style:none;">
							<?php foreach ( $top_buyers as $i => $b ) : $rank = $i + 1; ?>
								<li style="display:flex; align-items:center; gap:0.5rem; padding:0.35rem 0; border-bottom:1px solid rgba(255,255,255,0.1);">
									<span style="min-width:1.5em; text-align:center;"><?php echo esc_html( $rank_emoji[ $rank ] ?? '#' . $rank ); ?></span>
									<span style="flex:1; min-width:0;">
										<strong><?php echo esc_html( $b['name'] ); ?></strong>
										<br><span style="opacity:0.65; font-size:0.78em;"><?php echo esc_html( $b['email'] ); ?> · <?php echo (int) $b['order_count']; ?> cmd</span>
									</span>
									<strong style="white-space:nowrap;"><?php echo number_format( $b['spent'], 2, ',', ' ' ); ?> $</strong>
								</li>
							<?php endforeach; ?>
						</ol>
					</div>
				<?php endif; ?>

				<?php if ( ! empty( $top_sellers ) ) : ?>
					<div style="padding:1rem 1.2rem; background:rgba(255,255,255,0.08); border-radius:6px;">
						<h3 style="margin:0 0 0.6rem; color:#fff; font-size:1em;">🔥 Top 5 ventes <span style="opacity:0.6; font-size:0.78em; font-weight:normal;">(par occurrences)</span></h3>
						<ol style="margin:0; padding:0; list-style:none;">
							<?php foreach ( $top_sellers as $i => $s ) : $rank = $i + 1; ?>
								<li style="display:flex; align-items:center; gap:0.5rem; padding:0.35rem 0; border-bottom:1px solid rgba(255,255,255,0.1);">
									<span style="min-width:1.5em; text-align:center;"><?php echo esc_html( $rank_emoji[ $rank ] ?? '#' . $rank ); ?></span>
									<span style="flex:1; min-width:0;">
										<strong><?php echo esc_html( $section_emoji[ $s['section'] ] ?? '' ); ?> <?php echo esc_html( $s['name'] ); ?></strong>
									</span>
									<strong style="white-space:nowrap;"><?php echo (int) $s['occurrences']; ?> vente<?php echo $s['occurrences'] > 1 ? 's' : ''; ?></strong>
								</li>
							<?php endforeach; ?>
						</ol>
					</div>
				<?php endif; ?>
			</div>
			<hr style="border:none; border-top:1px solid rgba(255,255,255,0.18); margin:0 0 1.2rem;">
		<?php endif; ?>

		<!-- BLOC 1 : RÉEL (compta) — toujours affiché -->
		<div<?php echo ( $show_pending || $show_cancelled ) ? ' style="margin-bottom:1rem;"' : ''; ?>>
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

		<?php if ( $show_pending ) : ?>
			<hr style="border:none; border-top:1px solid rgba(255,255,255,0.18); margin:1rem 0;">
			<div<?php echo $show_cancelled ? ' style="margin-bottom:1rem;"' : ''; ?>>
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
		<?php endif; ?>

		<?php if ( $show_cancelled ) : ?>
			<hr style="border:none; border-top:1px solid rgba(255,255,255,0.18); margin:1rem 0;">
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
		<?php endif; ?>
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

	$period_mode      = isset( $_GET['period_mode'] ) && $_GET['period_mode'] === 'event' ? 'event' : 'purchase';
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

	$items  = cordespace_reports_fetch_items( $range['start'], $range['end'], $selected_statuses, $period_mode );
	$totals = cordespace_reports_compute_totals( $items );

	$filename = sprintf(
		'cordespace-rapport-%s-%s-au-%s.csv',
		$period_mode === 'event' ? 'evt' : 'achat',
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

// ============================================================================
// 8) Export CSV — Soldes crédits (snapshot)
// ============================================================================
add_action( 'admin_post_cordespace_reports_csv_balances', 'cordespace_reports_handle_csv_balances' );
function cordespace_reports_handle_csv_balances(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'forbidden', 403 );
	}
	check_admin_referer( 'cordespace_reports_csv_balances' );

	$snapshot_date = isset( $_GET['snapshot_date'] ) ? sanitize_text_field( (string) $_GET['snapshot_date'] ) : wp_date( 'Y-m-d' );
	$role_filter   = isset( $_GET['role_filter'] )   ? sanitize_key( (string) $_GET['role_filter'] )         : 'all';
	$user_search   = isset( $_GET['user_search'] )   ? sanitize_text_field( (string) $_GET['user_search'] )  : '';

	$balances = cordespace_reports_fetch_balances_at( $snapshot_date . ' 23:59:59' );
	// Même logique que l'affichage : 'prof' regroupe les vrais profs ET les
	// profs sur leur compte client lié.
	if ( $role_filter === 'prof' ) {
		$balances = array_values( array_filter( $balances, fn( $b ) => in_array( $b['role'], [ 'prof', 'prof_client' ], true ) ) );
	} elseif ( $role_filter !== 'all' ) {
		$balances = array_values( array_filter( $balances, fn( $b ) => $b['role'] === $role_filter ) );
	}
	if ( $user_search !== '' ) {
		$needle   = mb_strtolower( $user_search );
		$balances = array_values( array_filter( $balances, function ( $b ) use ( $needle ) {
			return stripos( $b['display_name'], $needle ) !== false
			    || stripos( $b['email'], $needle ) !== false
			    || stripos( $b['login'], $needle ) !== false;
		} ) );
	}

	$filename = sprintf( 'cordespace-soldes-credits-snapshot-%s.csv', $snapshot_date );
	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=' . $filename );

	$out = fopen( 'php://output', 'w' );
	fwrite( $out, "\xEF\xBB\xBF" );

	fputcsv( $out, [
		'Utilisateur', 'Courriel', 'Login', 'Rôle', 'Prof lié', 'Anomalie',
		'Solde au snapshot', 'Solde actuel',
	], ';' );

	$role_text_labels = [
		'admin'       => 'Admin',
		'prof'        => 'Prof',
		'prof_client' => 'Prof (compte client)',
		'client'      => 'Client',
	];

	$grand = 0;
	foreach ( $balances as $b ) {
		fputcsv( $out, [
			$b['display_name'],
			$b['email'],
			$b['login'],
			$role_text_labels[ $b['role'] ] ?? $b['role'],
			$b['prof_name'],
			! empty( $b['is_anomaly'] ) ? 'OUI - crédits sur 2 comptes liés' : '',
			number_format( $b['balance'], 2, '.', '' ),
			number_format( $b['current'], 2, '.', '' ),
		], ';' );
		$grand += $b['balance'];
	}

	fputcsv( $out, [
		'TOTAL EN CIRCULATION', '', '', '', '', '',
		number_format( $grand, 2, '.', '' ),
		'',
	], ';' );

	fclose( $out );
	exit;
}

// ============================================================================
// 7) Export CSV — Historique crédits
// ============================================================================
add_action( 'admin_post_cordespace_reports_csv_credits', 'cordespace_reports_handle_csv_credits' );
function cordespace_reports_handle_csv_credits(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'forbidden', 403 );
	}
	check_admin_referer( 'cordespace_reports_csv_credits' );

	$preset       = isset( $_GET['preset'] )       ? sanitize_key( (string) $_GET['preset'] )         : 'this_month';
	$custom_start = isset( $_GET['date_start'] )   ? sanitize_text_field( (string) $_GET['date_start'] ) : '';
	$custom_end   = isset( $_GET['date_end'] )     ? sanitize_text_field( (string) $_GET['date_end'] )   : '';
	$user_search  = isset( $_GET['user_search'] )  ? sanitize_text_field( (string) $_GET['user_search'] ) : '';

	$ref_labels    = cordespace_reports_get_credit_ref_labels();
	$selected_refs = isset( $_GET['refs'] ) && is_array( $_GET['refs'] )
		? array_map( 'sanitize_text_field', wp_unslash( $_GET['refs'] ) )
		: array_keys( $ref_labels );

	if ( $preset === 'custom' && $custom_start !== '' && $custom_end !== '' ) {
		$range = [ 'start' => $custom_start . ' 00:00:00', 'end' => $custom_end . ' 23:59:59' ];
	} else {
		$range = cordespace_reports_get_preset_range( $preset );
	}

	$rows = cordespace_reports_fetch_credit_log( $range['start'], $range['end'], $selected_refs, $user_search );

	$filename = sprintf(
		'cordespace-historique-credits-%s-au-%s.csv',
		substr( $range['start'], 0, 10 ),
		substr( $range['end'], 0, 10 )
	);

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=' . $filename );

	$out = fopen( 'php://output', 'w' );
	fwrite( $out, "\xEF\xBB\xBF" );

	fputcsv( $out, [
		'Date', 'Heure', 'Utilisateur', 'Courriel', 'Type', 'Description',
		'N° Commande', 'Montant',
	], ';' );

	$net = 0;
	foreach ( $rows as $r ) {
		$ref      = (string) $r['ref'];
		$ref_info = $ref_labels[ $ref ] ?? [ 'label' => $ref ];
		$amt      = (float) $r['creds'];
		$net     += $amt;
		$entry    = cordespace_reports_format_credit_entry( (string) ( $r['entry'] ?? '' ), $r );
		$has_order = in_array( $ref, [ 'woocommerce_payment', 'woocommerce_refund' ], true ) && $r['ref_id'];

		fputcsv( $out, [
			mysql2date( 'Y-m-d', gmdate( 'Y-m-d H:i:s', (int) $r['time'] ) ),
			mysql2date( 'H:i', gmdate( 'Y-m-d H:i:s', (int) $r['time'] ) ),
			(string) ( $r['display_name'] ?? '' ),
			(string) ( $r['user_email'] ?? '' ),
			$ref_info['label'],
			$entry,
			$has_order ? '#' . (int) $r['ref_id'] : '',
			number_format( $amt, 2, '.', '' ),
		], ';' );
	}

	fputcsv( $out, [
		'SOLDE NET', '', '', '', '', '', '',
		number_format( $net, 2, '.', '' ),
	], ';' );

	fclose( $out );
	exit;
}

// ============================================================================
// 6) Export CSV — Sommaire boutique
// ============================================================================
add_action( 'admin_post_cordespace_reports_csv_sommaire', 'cordespace_reports_handle_csv_sommaire' );
function cordespace_reports_handle_csv_sommaire(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'forbidden', 403 );
	}
	check_admin_referer( 'cordespace_reports_csv_sommaire' );

	$preset       = isset( $_GET['preset'] )     ? sanitize_key( (string) $_GET['preset'] )       : 'this_month';
	$custom_start = isset( $_GET['date_start'] ) ? sanitize_text_field( (string) $_GET['date_start'] ) : '';
	$custom_end   = isset( $_GET['date_end'] )   ? sanitize_text_field( (string) $_GET['date_end'] )   : '';
	$selected_statuses = isset( $_GET['statuses'] ) && is_array( $_GET['statuses'] )
		? array_map( 'sanitize_text_field', wp_unslash( $_GET['statuses'] ) )
		: [ 'wc-completed', 'wc-refunded' ];

	if ( $preset === 'custom' && $custom_start !== '' && $custom_end !== '' ) {
		$range = [ 'start' => $custom_start . ' 00:00:00', 'end' => $custom_end . ' 23:59:59' ];
	} else {
		$range = cordespace_reports_get_preset_range( $preset );
	}

	$items    = cordespace_reports_fetch_items( $range['start'], $range['end'], $selected_statuses, 'purchase' );
	$boutique = array_filter( $items, fn( $it ) => $it['section'] === 'boutique' );

	$grouped = [];
	foreach ( $boutique as $it ) {
		$key = $it['item_name'] !== '' ? $it['item_name'] : 'Produit inconnu';
		if ( ! isset( $grouped[ $key ] ) ) {
			$grouped[ $key ] = [
				'name'      => $key,
				'qty_real'  => 0, 'qty_pending' => 0, 'qty_cancelled' => 0,
				'rev_real'  => 0, 'rev_pending' => 0, 'rev_cancelled' => 0,
			];
		}
		$cat = cordespace_reports_status_category( $it['status'] );
		$grouped[ $key ][ 'qty_' . $cat ] += (int) $it['qty'];
		$grouped[ $key ][ 'rev_' . $cat ] += $it['total'];
	}
	uasort( $grouped, fn( $a, $b ) => $b['qty_real'] <=> $a['qty_real'] );

	$filename = sprintf(
		'cordespace-sommaire-boutique-%s-au-%s.csv',
		substr( $range['start'], 0, 10 ),
		substr( $range['end'], 0, 10 )
	);

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=' . $filename );

	$out = fopen( 'php://output', 'w' );
	fwrite( $out, "\xEF\xBB\xBF" ); // BOM UTF-8

	fputcsv( $out, [
		'Produit',
		'Qté réelle', 'Revenu réel',
		'Qté pronostic', 'Revenu pronostic',
		'Qté annulée', 'Revenu annulé',
	], ';' );

	$tot_qr = $tot_qp = $tot_qc = 0;
	$tot_rr = $tot_rp = $tot_rc = 0;
	foreach ( $grouped as $g ) {
		fputcsv( $out, [
			$g['name'],
			$g['qty_real'],     number_format( $g['rev_real'], 2, '.', '' ),
			$g['qty_pending'],  number_format( $g['rev_pending'], 2, '.', '' ),
			$g['qty_cancelled'], number_format( $g['rev_cancelled'], 2, '.', '' ),
		], ';' );
		$tot_qr += $g['qty_real'];     $tot_rr += $g['rev_real'];
		$tot_qp += $g['qty_pending'];  $tot_rp += $g['rev_pending'];
		$tot_qc += $g['qty_cancelled']; $tot_rc += $g['rev_cancelled'];
	}
	fputcsv( $out, [
		'TOTAUX',
		$tot_qr, number_format( $tot_rr, 2, '.', '' ),
		$tot_qp, number_format( $tot_rp, 2, '.', '' ),
		$tot_qc, number_format( $tot_rc, 2, '.', '' ),
	], ';' );

	fclose( $out );
	exit;
}
