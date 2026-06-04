<?php
/**
 * Module: event-gating-cpt
 *
 * Custom Post Type `cordespace_event_type` : un « type d'événement gated »
 * que l'admin définit (ex : « Semi-privé », « Privé », « Atelier expert »).
 * Chaque type a :
 *   - un titre (post_title)
 *   - un texte de bandeau (post_content, WYSIWYG) qui s'affichera au panier
 *     si la personne n'est pas approuvée
 *   - une liste d'étiquettes Amelia qui déclenchent l'application du type
 *     (= matching auto avec les events Amelia)
 *   - une URL d'info (où la personne va apprendre comment se faire valider)
 *
 * Sous-menu Cordespace → Events gated (priorité 40, entre Waivers à 30 et
 * Voir sur GitHub à 100). show_in_menu=false sur le CPT + add_submenu_page
 * manuel pour garantir l'ordre (même pattern que waivers-cpt.php).
 */

defined( 'ABSPATH' ) || exit;

// Slug court (max 20 char autorisés par WP pour un CPT). 'cordespace_event_type'
// aurait fait 21 caractères → erreur 'Type de contenu non valide'. On garde
// les constantes meta avec leur nom long, c'est juste le slug du CPT qui
// est compact.
const CORDESPACE_EVENT_TYPE_POST_TYPE              = 'cordespace_evtype';
const CORDESPACE_EVENT_TYPE_META_TAGS              = '_cordespace_event_type_amelia_tags';
const CORDESPACE_EVENT_TYPE_META_INFO_URL          = '_cordespace_event_type_info_url';
const CORDESPACE_EVENT_TYPE_META_APPLIES_APPT      = '_cordespace_event_type_applies_to_appointments';

// ============================================================================
// 1) Enregistrement du CPT
// ============================================================================

function cordespace_event_gating_register_cpt(): void {
	register_post_type(
		CORDESPACE_EVENT_TYPE_POST_TYPE,
		[
			'label'           => __( "Types d'événements à validation", 'cordespace-snippets' ),
			'labels'          => [
				'name'          => __( "Types d'événements à validation", 'cordespace-snippets' ),
				'singular_name' => __( "Type d'événement à validation", 'cordespace-snippets' ),
				'add_new'       => __( 'Ajouter', 'cordespace-snippets' ),
				'add_new_item'  => __( "Nouveau type", 'cordespace-snippets' ),
				'edit_item'     => __( 'Éditer le type', 'cordespace-snippets' ),
				'search_items'  => __( 'Rechercher un type', 'cordespace-snippets' ),
				'not_found'     => __( 'Aucun type configuré.', 'cordespace-snippets' ),
			],
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => false,
			'supports'        => [ 'title', 'editor', 'revisions' ],
			'capability_type' => 'post',
			'map_meta_cap'    => true,
			'rewrite'         => false,
			'has_archive'     => false,
			'menu_icon'       => 'dashicons-lock',
		]
	);
}
add_action( 'init', 'cordespace_event_gating_register_cpt' );

// ============================================================================
// 2) Sous-menu Cordespace + highlight parent_file
// ============================================================================

function cordespace_event_gating_register_admin_submenu(): void {
	add_submenu_page(
		'cordespace-modules',
		__( "Types d'événements à validation", 'cordespace-snippets' ),
		__( 'Événements à validation', 'cordespace-snippets' ),
		'edit_posts',
		'edit.php?post_type=' . CORDESPACE_EVENT_TYPE_POST_TYPE
	);
}
add_action( 'admin_menu', 'cordespace_event_gating_register_admin_submenu', 40 );

/**
 * Quand on est sur l'écran d'édition / liste d'un event type, force WP à
 * highlighter Cordespace → Events gated dans le menu (sinon il perd la
 * trace du parent puisqu'on a coupé show_in_menu).
 */
function cordespace_event_gating_highlight_admin_menu( string $parent_file ): string {
	global $current_screen, $submenu_file;
	if ( $current_screen && $current_screen->post_type === CORDESPACE_EVENT_TYPE_POST_TYPE ) {
		$submenu_file = 'edit.php?post_type=' . CORDESPACE_EVENT_TYPE_POST_TYPE;
		return 'cordespace-modules';
	}
	return $parent_file;
}
add_filter( 'parent_file', 'cordespace_event_gating_highlight_admin_menu' );

// ============================================================================
// 3) Metabox : Étiquettes Amelia
// ============================================================================

function cordespace_event_gating_add_tags_metabox(): void {
	add_meta_box(
		'cordespace-event-type-tags',
		__( "🏷️ Étiquettes Amelia à matcher", 'cordespace-snippets' ),
		'cordespace_event_gating_render_tags_metabox',
		CORDESPACE_EVENT_TYPE_POST_TYPE,
		'normal',
		'high'
	);
}
add_action( 'add_meta_boxes_' . CORDESPACE_EVENT_TYPE_POST_TYPE, 'cordespace_event_gating_add_tags_metabox' );

function cordespace_event_gating_render_tags_metabox( WP_Post $post ): void {
	global $wpdb;

	$all_tags = $wpdb->get_col(
		"SELECT DISTINCT name FROM {$wpdb->prefix}amelia_events_tags ORDER BY name ASC"
	);
	$all_tags = array_filter( (array) $all_tags );

	$selected = get_post_meta( $post->ID, CORDESPACE_EVENT_TYPE_META_TAGS, true );
	if ( ! is_array( $selected ) ) {
		$selected = [];
	}

	wp_nonce_field( 'cordespace_event_gating_meta_save', 'cordespace_event_gating_nonce' );
	?>
	<p style="margin-top:0;">
		<?php esc_html_e( "Coche les étiquettes Amelia pour lesquelles ce type s'applique. Tout événement portant AU MOINS UNE de ces étiquettes nécessitera l'approbation admin avant que la personne puisse le réserver.", 'cordespace-snippets' ); ?>
	</p>

	<?php if ( empty( $all_tags ) ) : ?>
		<p style="padding:1rem 1.2rem; background:#fff8e1; border-left:4px solid #f5b800; color:#7a5d00;">
			⚠️ <?php esc_html_e( "Aucune étiquette Amelia trouvée. Crée d'abord des étiquettes dans wp-admin → Amelia → Events.", 'cordespace-snippets' ); ?>
		</p>
	<?php else : ?>
		<div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:0.5rem 1rem; padding:0.6rem 0;">
			<?php foreach ( $all_tags as $tag ) :
				$checked = in_array( $tag, $selected, true );
				?>
				<label style="display:flex; align-items:center; gap:0.4rem; cursor:pointer;">
					<input type="checkbox" name="cordespace_event_type_tags[]" value="<?php echo esc_attr( $tag ); ?>" <?php checked( $checked ); ?>>
					<span><?php echo esc_html( $tag ); ?></span>
				</label>
			<?php endforeach; ?>
		</div>

		<p style="margin-top:1rem; padding:0.6rem 0.9rem; background:#f0f6fc; border-left:3px solid #2c70b8; font-size:0.92em;">
			<?php
			echo wp_kses_post( sprintf(
				/* translators: %d = number of selected tags */
				_n(
					"%d étiquette sélectionnée → tout event Amelia portant cette étiquette sera gated par ce type.",
					"%d étiquettes sélectionnées → tout event Amelia portant AU MOINS UNE de ces étiquettes sera gated par ce type.",
					count( $selected ),
					'cordespace-snippets'
				),
				count( $selected )
			) );
			?>
		</p>
	<?php endif; ?>
	<?php
}

// ============================================================================
// 4) Metabox : URL d'info
// ============================================================================

function cordespace_event_gating_add_info_url_metabox(): void {
	add_meta_box(
		'cordespace-event-type-info-url',
		__( "🔗 URL d'info pour se faire valider", 'cordespace-snippets' ),
		'cordespace_event_gating_render_info_url_metabox',
		CORDESPACE_EVENT_TYPE_POST_TYPE,
		'normal',
		'default'
	);
}
add_action( 'add_meta_boxes_' . CORDESPACE_EVENT_TYPE_POST_TYPE, 'cordespace_event_gating_add_info_url_metabox' );

function cordespace_event_gating_render_info_url_metabox( WP_Post $post ): void {
	$url = (string) get_post_meta( $post->ID, CORDESPACE_EVENT_TYPE_META_INFO_URL, true );
	?>
	<p style="margin-top:0;">
		<?php esc_html_e( "URL où la personne non-approuvée sera redirigée pour apprendre comment se faire valider. Affichée dans le bandeau de gating sous la forme d'un bouton « En savoir plus ».", 'cordespace-snippets' ); ?>
	</p>
	<p>
		<input type="url"
		       name="cordespace_event_type_info_url"
		       value="<?php echo esc_attr( $url ); ?>"
		       class="widefat"
		       placeholder="https://cordespace.com/semi-prive/">
	</p>
	<p class="description" style="font-size:0.9em;">
		<?php esc_html_e( "Laisser vide pour ne pas afficher de bouton (le bandeau montrera juste le texte).", 'cordespace-snippets' ); ?>
	</p>
	<?php
}

// ============================================================================
// 4c) Metabox : Réservations de salle (toggle global)
// ============================================================================

function cordespace_event_gating_add_appointments_metabox(): void {
	add_meta_box(
		'cordespace-event-type-appointments',
		__( '🏠 Réservations de salle', 'cordespace-snippets' ),
		'cordespace_event_gating_render_appointments_metabox',
		CORDESPACE_EVENT_TYPE_POST_TYPE,
		'normal',
		'default'
	);
}
add_action( 'add_meta_boxes_' . CORDESPACE_EVENT_TYPE_POST_TYPE, 'cordespace_event_gating_add_appointments_metabox' );

function cordespace_event_gating_render_appointments_metabox( WP_Post $post ): void {
	$applies = (string) get_post_meta( $post->ID, CORDESPACE_EVENT_TYPE_META_APPLIES_APPT, true );
	?>
	<p style="margin-top:0;">
		<label style="display:flex; align-items:center; gap:0.5rem; cursor:pointer;">
			<input type="checkbox" name="cordespace_evtype_applies_to_appointments" value="1" <?php checked( $applies, '1' ); ?>>
			<strong><?php esc_html_e( "S'applique à TOUTES les réservations de salle (appointments Amelia)", 'cordespace-snippets' ); ?></strong>
		</label>
	</p>
	<p class="description" style="font-size:0.92em;">
		<?php esc_html_e( "Si coché, ce type bloque la réservation de N'IMPORTE QUELLE salle Amelia tant que la personne n'est pas validée pour ce type (ou un type qui l'inclut). Les étiquettes Amelia ne s'appliquent pas aux appointments — c'est ce toggle qui détermine si le type s'applique aux salles.", 'cordespace-snippets' ); ?>
	</p>
	<?php
}

// ============================================================================
// 5) Sauvegarde des metas (commune à toutes les metaboxes)
// ============================================================================

function cordespace_event_gating_save_meta( int $post_id ): void {
	if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
		return;
	}
	if ( get_post_type( $post_id ) !== CORDESPACE_EVENT_TYPE_POST_TYPE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	if ( ! isset( $_POST['cordespace_event_gating_nonce'] )
	     || ! wp_verify_nonce(
	           sanitize_text_field( wp_unslash( $_POST['cordespace_event_gating_nonce'] ) ),
	           'cordespace_event_gating_meta_save'
	     ) ) {
		return;
	}

	// Tags : array de strings, sanitize chaque entrée
	$tags = isset( $_POST['cordespace_event_type_tags'] ) && is_array( $_POST['cordespace_event_type_tags'] )
		? array_values( array_filter( array_map( 'sanitize_text_field', wp_unslash( $_POST['cordespace_event_type_tags'] ) ) ) )
		: [];
	update_post_meta( $post_id, CORDESPACE_EVENT_TYPE_META_TAGS, $tags );

	// URL : esc_url_raw
	$url = isset( $_POST['cordespace_event_type_info_url'] )
		? esc_url_raw( wp_unslash( $_POST['cordespace_event_type_info_url'] ) )
		: '';
	update_post_meta( $post_id, CORDESPACE_EVENT_TYPE_META_INFO_URL, $url );

	// Applies to appointments : '0' ou '1'
	$applies_appt = isset( $_POST['cordespace_evtype_applies_to_appointments'] ) ? '1' : '0';
	update_post_meta( $post_id, CORDESPACE_EVENT_TYPE_META_APPLIES_APPT, $applies_appt );
}
add_action( 'save_post_' . CORDESPACE_EVENT_TYPE_POST_TYPE, 'cordespace_event_gating_save_meta' );

// ============================================================================
// 6) Helpers publics — utilisés par les futurs modules (members, checkout)
// ============================================================================

/**
 * Renvoie les tags Amelia configurés pour un type donné.
 *
 * @return string[]
 */
function cordespace_event_gating_get_tags( int $event_type_id ): array {
	$tags = get_post_meta( $event_type_id, CORDESPACE_EVENT_TYPE_META_TAGS, true );
	return is_array( $tags ) ? array_values( $tags ) : [];
}

/**
 * Renvoie l'URL d'info pour un type donné.
 */
function cordespace_event_gating_get_info_url( int $event_type_id ): string {
	return (string) get_post_meta( $event_type_id, CORDESPACE_EVENT_TYPE_META_INFO_URL, true );
}

/**
 * Renvoie les types d'events applicables à un event Amelia donné (= types
 * dont les tags configurés matchent au moins UN tag de l'event Amelia).
 *
 * Utilisé par le module de gating au checkout pour décider si une personne
 * a besoin d'être approuvée pour réserver cet event.
 *
 * @return int[] Liste des post IDs de cordespace_event_type applicables.
 */
function cordespace_event_gating_applicable_types_for_amelia_event( int $amelia_event_id ): array {
	if ( $amelia_event_id <= 0 ) {
		return [];
	}

	// 1. Tags de l'event Amelia (via le helper extrait)
	$event_tags = cordespace_event_gating_get_amelia_event_tags( $amelia_event_id );
	if ( empty( $event_tags ) ) {
		return [];
	}

	// 2. Tous les event types publiés
	$type_ids = get_posts( [
		'post_type'      => CORDESPACE_EVENT_TYPE_POST_TYPE,
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	] );

	// 3. Filtre ceux qui ont au moins un tag en commun
	$matching = [];
	foreach ( $type_ids as $type_id ) {
		$type_tags = cordespace_event_gating_get_tags( (int) $type_id );
		if ( ! empty( array_intersect( $event_tags, $type_tags ) ) ) {
			$matching[] = (int) $type_id;
		}
	}
	return $matching;
}

/**
 * Renvoie les tags Amelia configurés pour un event Amelia donné.
 *
 * Helper bas niveau extrait de applicable_types_for_amelia_event(), exposé
 * pour que le module checkout-blocker puisse calculer le check par tag
 * commun (event ∩ type).
 *
 * @return string[]
 */
function cordespace_event_gating_get_amelia_event_tags( int $amelia_event_id ): array {
	if ( $amelia_event_id <= 0 ) {
		return [];
	}
	global $wpdb;
	$tags = $wpdb->get_col( $wpdb->prepare(
		"SELECT DISTINCT name FROM {$wpdb->prefix}amelia_events_tags WHERE eventId = %d",
		$amelia_event_id
	) );
	return array_values( array_map( 'strval', array_filter( (array) ( $tags ?: [] ) ) ) );
}

/**
 * Renvoie les types d'events applicables à un appointment Amelia (= salles).
 * Les appointments n'ont pas d'étiquettes Amelia, donc on prend tous les
 * types qui ont coché « S'applique à TOUTES les réservations de salle ».
 *
 * @return int[] Liste des post IDs de cordespace_event_type applicables.
 */
function cordespace_event_gating_applicable_types_for_amelia_appointment(): array {
	$type_ids = get_posts( [
		'post_type'      => CORDESPACE_EVENT_TYPE_POST_TYPE,
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'meta_query'     => [
			[
				'key'   => CORDESPACE_EVENT_TYPE_META_APPLIES_APPT,
				'value' => '1',
			],
		],
	] );
	return array_map( 'intval', (array) $type_ids );
}

