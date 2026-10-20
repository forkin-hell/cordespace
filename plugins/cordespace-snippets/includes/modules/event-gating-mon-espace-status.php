<?php
/**
 * Module : event-gating.mon-espace-status
 *
 * Carte « Accès semi-privé » dans le bloc d'accueil de la vue CLIENTE de
 * /mon-espace (slot cordespace_mon_espace_greeting_stats, à côté de « Mes
 * crédits ») : montre pour quels niveaux d'un type d'événement gated la
 * personne est validée, et invite — sans jamais parler de refus — à faire
 * une demande pour le reste.
 *
 * Source : la matrice d'approbations d'event-gating (une ligne par personne
 * × type × étiquette, statut approved / pending / rejected). Seul `approved`
 * compte comme validé ; pending et rejected s'affichent pareil (« pas
 * encore »), décision de Tess (2026-10-20) : une cliente ne doit pas se
 * sentir refusée dans son compte.
 *
 * Trois états par type (un type = une carte) :
 *   - full    : toutes les étiquettes validées → pastilles vertes ;
 *   - partial : certaines → pastilles vertes + pastille « faire une demande » ;
 *   - none    : aucune → carte d'invitation vers la page d'info.
 * Le bouton ⓘ ouvre une bulle (clic ; survol sur desktop) avec une phrase et
 * le lien vers l'URL d'info du type (méta du CPT, modifiable en admin). La
 * bulle s'ouvre dans le flux de la carte (la carte s'agrandit) : le bloc
 * d'accueil masque ce qui dépasse, une bulle flottante serait coupée.
 * La carte prend toute la largeur restante à côté de « Mes crédits ».
 *
 * Étiquette Amelia → libellé client·e : filtre cordespace_evgating_client_tag
 * (défauts pour les trois étiquettes de « Semi-privé » ; sinon l'étiquette,
 * débarrassée du préfixe « Titre du type - »). Textes : filtre
 * cordespace_evgating_status_texts. Seuls les types publiés AVEC étiquettes
 * sont affichés.
 *
 * Dépendances : event-gating.schema (table) + event-gating.cpt (tags, URL) ;
 * sans eux, le module ne rend rien.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'cordespace_mon_espace_greeting_stats', 'cordespace_evgating_render_mon_espace_status', 10, 1 );

/**
 * Libellés client·e d'une étiquette : 'label' (pastille) et 'phrase' (dans
 * une phrase, avec son article).
 *
 * @return array{label:string, phrase:string}
 */
function cordespace_evgating_client_tag( string $tag, string $type_title = '' ): array {
	// 'action' (optionnel) : bouton affiché quand l'étiquette est validée —
	// le lien de réservation est difficile à trouver sur le site (Tess, 2026-10-20).
	$defaults = [
		'Semi-privé'               => [ 'label' => 'Événements',               'phrase' => 'les événements semi-privés' ],
		'Semi-privé - Performance' => [ 'label' => 'Performances',             'phrase' => 'les performances intimes' ],
		'Réservation des salles'   => [
			'label'  => 'Réservation de l\'espace',
			'phrase' => 'la réservation de l\'espace',
			'action' => [ 'label' => '🏠 Réserver l\'espace', 'url' => home_url( '/formulaire-reservation/' ) ],
		],
	];
	if ( isset( $defaults[ $tag ] ) ) {
		$out = $defaults[ $tag ];
	} else {
		$label = $tag;
		if ( $type_title !== '' && stripos( $tag, $type_title . ' - ' ) === 0 ) {
			$label = trim( substr( $tag, strlen( $type_title ) + 3 ) );
		}
		$out = [ 'label' => $label, 'phrase' => mb_strtolower( $label ) ];
	}
	return (array) apply_filters( 'cordespace_evgating_client_tag', $out, $tag, $type_title );
}

/**
 * Modèle pur (testable sans WP) : état + libellés à partir des étiquettes du
 * type et des statuts de la personne.
 *
 * @param string[]                                   $tags         Étiquettes du type, dans l'ordre d'affichage.
 * @param array<string,string>                       $tag_statuses tag => approved|pending|rejected (absent = aucune demande).
 * @param array<string,array{label:string,phrase:string}> $names   tag => libellés client·e.
 * @return array{state:string, approved:array<array{label:string,phrase:string}>, missing:array<array{label:string,phrase:string}>}
 */
function cordespace_evgating_status_model( array $tags, array $tag_statuses, array $names ): array {
	$approved = [];
	$missing  = [];
	foreach ( $tags as $tag ) {
		$tag  = (string) $tag;
		$name = $names[ $tag ] ?? [ 'label' => $tag, 'phrase' => mb_strtolower( $tag ) ];
		if ( ( $tag_statuses[ $tag ] ?? '' ) === 'approved' ) {
			$approved[] = $name;
		} else {
			$missing[] = $name;
		}
	}
	$state = empty( $approved ) ? 'none' : ( empty( $missing ) ? 'full' : 'partial' );
	return [ 'state' => $state, 'approved' => $approved, 'missing' => $missing ];
}

/** « a, b et c » */
function cordespace_evgating_join_fr( array $items ): string {
	$items = array_values( array_map( 'strval', $items ) );
	if ( count( $items ) <= 1 ) {
		return $items[0] ?? '';
	}
	$last = array_pop( $items );
	return implode( ', ', $items ) . ' et ' . $last;
}

/**
 * Textes affichés (filtre cordespace_evgating_status_texts). Les {phrases}
 * sont remplacées à l'affichage.
 */
function cordespace_evgating_status_texts( string $type_title ): array {
	$lc_title = mb_strtolower( mb_substr( $type_title, 0, 1 ) ) . mb_substr( $type_title, 1 );
	$texts    = [
		'card_title'      => 'Accès ' . $lc_title,
		'full_pop'        => 'Tu es validé·e pour {approved}.',
		'full_link'       => 'En savoir plus',
		'partial_pill'    => '{missing_labels} : faire une demande',
		'partial_pop'     => 'Tu es validé·e pour {approved}. Pour {missing}, une demande est nécessaire.',
		'partial_link'    => 'Voir comment faire une demande',
		'none_card'       => 'Envie d\'aller plus loin ? {Missing} sont ouverts aux personnes validées.',
		'none_link'       => 'Voir comment faire une demande',
		'none_pop'        => 'Pour y avoir accès, fais une demande via la page d\'information.',
		'none_pop_link'   => 'Lire les critères et faire une demande',
	];
	if ( $type_title === 'Semi-privé' ) {
		$texts['none_pop'] = 'Les semi-privés demandent une bonne expérience des cordes et de l\'espace. Deux formulaires : simplifié (performances seulement) ou complet (tout). Réponse au moins une fois par mois.';
	}
	return (array) apply_filters( 'cordespace_evgating_status_texts', $texts, $type_title );
}

/**
 * Statuts de la personne pour un type : tag => status (lignes de la matrice).
 *
 * @return array<string,string>
 */
function cordespace_evgating_user_tag_statuses( int $user_id, int $type_id ): array {
	global $wpdb;
	$table = cordespace_event_gating_table_name();
	$rows  = $wpdb->get_results( $wpdb->prepare(
		"SELECT tag, status FROM {$table} WHERE event_type_id = %d AND user_id = %d",
		$type_id,
		$user_id
	), ARRAY_A );
	$out = [];
	foreach ( (array) $rows as $r ) {
		$out[ (string) $r['tag'] ] = (string) $r['status'];
	}
	return $out;
}

function cordespace_evgating_render_mon_espace_status( $user ): void {
	if ( ! $user || empty( $user->ID ) ) {
		return;
	}
	if ( ! function_exists( 'cordespace_event_gating_table_name' ) || ! function_exists( 'cordespace_event_gating_get_tags' ) || ! defined( 'CORDESPACE_EVENT_TYPE_POST_TYPE' ) ) {
		return;
	}
	$type_ids = get_posts( [
		'post_type'      => CORDESPACE_EVENT_TYPE_POST_TYPE,
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'orderby'        => 'title',
		'order'          => 'ASC',
	] );
	$printed = false;
	foreach ( (array) $type_ids as $type_id ) {
		$type_id = (int) $type_id;
		$tags    = cordespace_event_gating_get_tags( $type_id );
		if ( empty( $tags ) ) {
			continue;
		}
		$title = (string) get_the_title( $type_id );
		$names = [];
		foreach ( $tags as $tag ) {
			$names[ (string) $tag ] = cordespace_evgating_client_tag( (string) $tag, $title );
		}
		$model = cordespace_evgating_status_model( $tags, cordespace_evgating_user_tag_statuses( (int) $user->ID, $type_id ), $names );
		$url   = function_exists( 'cordespace_event_gating_get_info_url' ) ? cordespace_event_gating_get_info_url( $type_id ) : '';
		if ( ! $printed ) {
			cordespace_evgating_print_status_assets();
			$printed = true;
		}
		cordespace_evgating_render_status_card( $type_id, $title, $model, $url );
	}
}

function cordespace_evgating_render_status_card( int $type_id, string $title, array $model, string $url ): void {
	$t        = cordespace_evgating_status_texts( $title );
	$pop_id   = 'cordespace-evg-pop-' . $type_id;
	$approved = cordespace_evgating_join_fr( array_column( $model['approved'], 'phrase' ) );
	$missing  = cordespace_evgating_join_fr( array_column( $model['missing'], 'phrase' ) );
	$fill     = static function ( string $s ) use ( $approved, $missing, $model ): string {
		return strtr( $s, [
			'{approved}'       => $approved,
			'{missing}'        => $missing,
			'{Missing}'        => mb_strtoupper( mb_substr( $missing, 0, 1 ) ) . mb_substr( $missing, 1 ),
			'{missing_labels}' => cordespace_evgating_join_fr( array_column( $model['missing'], 'label' ) ),
		] );
	};
	$link = static function ( string $label ) use ( $url ): string {
		return $url !== '' ? ' <a href="' . esc_url( $url ) . '" style="color:#1d4d7e;text-decoration:underline;">' . esc_html( $label ) . '</a>' : '';
	};
	$info_btn = '<button type="button" class="cordespace-evg-info" aria-expanded="false" aria-controls="' . esc_attr( $pop_id ) . '" aria-label="Plus d\'informations" style="display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;padding:0;margin-left:0.35rem;border:0;border-radius:50%;background:transparent;color:#fff;cursor:pointer;vertical-align:middle;opacity:0.9;"><svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true"><circle cx="10" cy="10" r="8.5" fill="none" stroke="currentColor" stroke-width="1.6"/><circle cx="10" cy="6.2" r="1.15" fill="currentColor"/><rect x="9.1" y="8.5" width="1.8" height="6.2" rx="0.9" fill="currentColor"/></svg></button>';
	$pill     = static function ( string $label ): string {
		return '<span style="display:inline-flex;align-items:center;gap:0.3rem;background:#e8f5e9;color:#1b5e20;font-size:0.8em;font-weight:600;padding:0.2rem 0.6rem;border-radius:999px;white-space:nowrap;">✓ ' . esc_html( $label ) . '</span>';
	};
	?>
	<div class="cordespace-evg-card" style="flex:1 1 320px;min-width:0;background:rgba(255,255,255,0.15);padding:0.8rem 1.4rem;border-radius:8px;">
		<?php if ( $model['state'] === 'none' ) : ?>
			<div style="font-size:0.95em;line-height:1.5;">
				✨ <?php echo esc_html( $fill( $t['none_card'] ) ); ?>
				<?php if ( $url !== '' ) : ?><a href="<?php echo esc_url( $url ); ?>" style="color:#fff;text-decoration:underline;font-weight:600;"><?php echo esc_html( $t['none_link'] ); ?></a><?php endif; ?>
				<?php echo $info_btn; ?>
			</div>
			<div id="<?php echo esc_attr( $pop_id ); ?>" class="cordespace-evg-pop" role="tooltip" hidden><?php echo esc_html( $t['none_pop'] ) . $link( $t['none_pop_link'] ); ?></div>
		<?php else : ?>
			<div style="font-size:0.85em;opacity:0.85;text-transform:uppercase;letter-spacing:0.5px;display:flex;align-items:center;"><?php echo esc_html( $t['card_title'] ); ?><?php echo $info_btn; ?></div>
			<div style="display:flex;flex-wrap:wrap;gap:0.4rem;margin-top:0.45rem;align-items:center;">
				<?php foreach ( $model['approved'] as $name ) { echo $pill( $name['label'] ); } ?>
				<?php if ( $model['state'] === 'partial' && $url !== '' ) : ?>
					<a href="<?php echo esc_url( $url ); ?>" style="display:inline-flex;align-items:center;gap:0.3rem;background:rgba(255,255,255,0.18);color:#fff;font-size:0.8em;padding:0.2rem 0.6rem;border-radius:999px;text-decoration:none;">→ <?php echo esc_html( $fill( $t['partial_pill'] ) ); ?></a>
				<?php endif; ?>
			</div>
			<?php
			$actions = array_filter( array_column( $model['approved'], 'action' ), static fn( $a ) => is_array( $a ) && ! empty( $a['url'] ) );
			if ( $actions ) : ?>
				<div style="display:flex;flex-wrap:wrap;gap:0.5rem;margin-top:0.7rem;">
					<?php foreach ( $actions as $a ) : ?>
						<a href="<?php echo esc_url( $a['url'] ); ?>" style="display:inline-flex;align-items:center;gap:0.35rem;background:#fff;color:#5b2c8f;font-weight:700;font-size:0.9em;padding:0.45rem 0.95rem;border-radius:6px;text-decoration:none;"><?php echo esc_html( $a['label'] ); ?> →</a>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<div id="<?php echo esc_attr( $pop_id ); ?>" class="cordespace-evg-pop" role="tooltip" hidden><?php
				echo $model['state'] === 'full'
					? esc_html( $fill( $t['full_pop'] ) ) . $link( $t['full_link'] )
					: esc_html( $fill( $t['partial_pop'] ) ) . $link( $t['partial_link'] );
			?></div>
		<?php endif; ?>
	</div>
	<?php
}

/** CSS + JS de la bulle, imprimés une fois par page. */
function cordespace_evgating_print_status_assets(): void {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	?>
	<style>
		/* La bulle s'ouvre DANS la carte (flux normal) : le bloc d'accueil masque
		   ce qui dépasse (décor des thèmes), une bulle flottante serait coupée. */
		.cordespace-evg-pop { margin-top:0.8rem; max-width:560px; background:#fff; color:#333; border-radius:10px; padding:0.8rem 1rem; box-shadow:0 4px 16px rgba(0,0,0,0.2); font-size:0.9em; line-height:1.5; }
		.cordespace-evg-pop[hidden] { display:none; }
		.cordespace-evg-info:focus-visible { outline:2px solid #fff; outline-offset:2px; }
		@media (hover:hover) { .cordespace-evg-card:hover .cordespace-evg-pop { display:block; } }
	</style>
	<script>
	( function () {
		function closeAll() {
			document.querySelectorAll( '.cordespace-evg-pop' ).forEach( function ( p ) { p.hidden = true; } );
			document.querySelectorAll( '.cordespace-evg-info' ).forEach( function ( b ) { b.setAttribute( 'aria-expanded', 'false' ); } );
		}
		document.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '.cordespace-evg-info' );
			if ( btn ) {
				var pop = document.getElementById( btn.getAttribute( 'aria-controls' ) );
				var open = pop && pop.hidden;
				closeAll();
				if ( pop && open ) { pop.hidden = false; btn.setAttribute( 'aria-expanded', 'true' ); }
				e.stopPropagation();
				return;
			}
			if ( ! e.target.closest( '.cordespace-evg-pop' ) ) { closeAll(); }
		} );
		document.addEventListener( 'keydown', function ( e ) { if ( e.key === 'Escape' ) { closeAll(); } } );
	} )();
	</script>
	<?php
}
